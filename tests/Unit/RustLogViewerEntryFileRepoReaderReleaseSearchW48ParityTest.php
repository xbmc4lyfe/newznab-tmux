<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\LogEntry;
use App\Services\LogViewer\LogFile;
use App\Services\LogViewer\LogFileRepository;
use App\Services\LogViewer\LogReader;
use App\Services\Releases\ReleaseSearchService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W48_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, release dylib. Common workloads hold 64 items and uncommon ones 128 items with edge cases.
 * Timings include PHP-side framing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every common and uncommon mean and p99 ratio reaches 1.5x. The
 * default run only checks that Rust-default helpers are not slower than PHP, so slower CI hosts do not
 * flake; `NNTMUX_W48_BENCH=1` enforces the full 1.5x gate for every Rust default.
 */
class RustLogViewerEntryFileRepoReaderReleaseSearchW48ParityTest extends TestCase
{
    /**
     * Toggle infix and default constant per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const CLASSES = [
        LogEntry::class => ['LogEntry', 'W48_LOG_ENTRY_RUST_FFI_DEFAULT'],
        LogFile::class => ['LogFile', 'W48_LOG_FILE_RUST_FFI_DEFAULT'],
        LogFileRepository::class => ['LogFileRepository', 'W48_LOG_FILE_REPOSITORY_RUST_FFI_DEFAULT'],
        LogReader::class => ['LogReader', 'W48_LOG_READER_RUST_FFI_DEFAULT'],
        ReleaseSearchService::class => ['ReleaseSearchService', 'W48_RELEASE_SEARCH_SERVICE_RUST_FFI_DEFAULT'],
    ];

    /**
     * Benchmark-qualified defaults. Six strict 1000-sample runs (Apple Silicon, PHP 8.5 CLI, release
     * dylib) gave min common mean/p99 and uncommon mean/p99 speedups of: LogEntry 2.53/1.70/2.45/1.95,
     * LogFile 3.92/2.69/4.43/3.10, LogFileRepository 1.73/1.42/1.77/1.53, LogReader 1.87/1.44/1.95/1.46
     * and ReleaseSearchService 2.39/1.96/2.82/2.38 (LogReader and ReleaseSearchService from the five runs
     * that reached them). LogFileRepository and LogReader clear the gate on means but not reliably on p99,
     * so they stay PHP.
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        LogEntry::class => true,
        LogFile::class => true,
        LogFileRepository::class => false,
        LogReader::class => false,
        ReleaseSearchService::class => true,
    ];

    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        LogEntry::class => 'classifyEntryBatch',
        LogFile::class => 'rotationMetadataBatch',
        LogFileRepository::class => 'inventoryPathBatch',
        LogReader::class => 'assembleRecordBatch',
        ReleaseSearchService::class => 'normalizeSearchRequestBatch',
    ];

    private const RANDOM_ROUNDS = 600;

    private const BYTES = ['a', 'b', 'A', 'B', 'e', 'E', 'x', '0', '9', ' ', "\t", "\x0b", "\x0c", '.', ':', '-', '_', '[', ']', '{', '}', '#', '!', '/', '\\', '*', '?', "\r", "\n", "\0", "\xc3\xa9", "\xff"];

    private const LINE_PIECES = [
        '[2026-10-25 12:00:00] local.ERROR: boom',
        '[2026-10-25T12:00:00.123456+00:00] production.app.WARNING: slow {"user":1}',
        '[2026-10-25 12:00:00+0000] a.EMERGENCY: x',
        '[2026-10-25 12:00:00Z] a-b_c.9.CUSTOM: tail',
        '[2026-10-25 12:00:00] .ERROR: no channel',
        '[2026-10-25 12:00:00] local.error: lower level',
        "[2026-10-25 12:00:00]\t\x0b local.INFO: odd spaces",
        '[2026-10-25 12:00:00] local.INFO.: dot',
        '[2026-10-25 12:00:00.] local.INFO: bare fraction',
        '[2026-10-25 12:00:00+00:0] local.INFO: short zone',
        '[2026-1-25 12:00:00] local.INFO: short date',
        '[2026-10-25 12:00:00]local.INFO: no space',
        'local.ERROR: RuntimeException: boom {"exception":"[object] (RuntimeException(code: 0): boom)"}',
        '[stacktrace]',
        'Stack trace:',
        '#0 /app/vendor/x.php(12): y()',
        '#',
        '#a not a frame',
        '  at App\\Foo->bar()',
        '"} ',
        '',
        "\r",
        "bin\0ary\xff",
    ];

    private const NAME_PIECES = ['laravel', 'worker', 'LARAVEL', 'a.log', '1', '', 'x y', "n\0", 'é', '.log', '.LOG', '-2026-10-25', '-2024-02-29', '-2026-02-29', '-2026-13-01', '-0000-01-01', '-2026-1-01', '.1', '.123456789', '.1234567890', '.', '.gz', '.GZ', '.Zst', '.bz2', '.xz', '.zip', '.tar'];

    private const PATH_PIECES = ['a', 'b.log', 'laravel.log', '..', '.', '', 'x y', "n\0", 'C:', 'é', '*', 'worker-1.log'];

    private const PATTERNS = ['', '*', '*.log', '**', '**/*.log', '?.log', 'a/*', '**.log', '*/**/?', 'a\\b', '[x]', '***', 'b.log', '*a*b*', "n\0", '(.+)'];

    private const TERM_PIECES = ['The', 'movie', 'MOVIE', '!x264', '!the', '!!', '!', 'a', 'ab', '.', '-', '_', ' ', '  ', "\t", "\x0b", "\x0c", "\n", '1080p', 'é', "\0", 'S01E02', '2026'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class => [$infix]) {
            $class::{'resetW48'.$infix.'RustFfi'}();
        }
        (new ReflectionClass(LogReader::class))->setStaticPropertyValue('w48Ffi', null);
        (new ReflectionClass(ReleaseSearchService::class))->setStaticPropertyValue('w48Ffi', null);

        parent::tearDown();
    }

    public function test_methods_defaults_and_toggles(): void
    {
        foreach (self::CLASSES as $class => [$infix, $constant]) {
            $method = self::METHODS[$class];
            foreach (["useW48{$infix}RustFfi", "usesW48{$infix}RustFfi", "resetW48{$infix}RustFfi", $method, $method.'Php', $method.'Native'] as $name) {
                $this->assertTrue((new ReflectionClass($class))->hasMethod($name), "{$class}::{$name}");
            }
            $default = (new ReflectionClassConstant($class, $constant))->getValue();
            $this->assertSame(self::DEFAULTS[$class], $default, "{$class} default must match the benchmark evidence");
            $this->assertSame($default, $class::{"usesW48{$infix}RustFfi"}(), "{$class} must start at its default");
            $class::{"useW48{$infix}RustFfi"}(false);
            $this->assertFalse($class::{"usesW48{$infix}RustFfi"}());
            $class::{"useW48{$infix}RustFfi"}();
            $this->assertTrue($class::{"usesW48{$infix}RustFfi"}());
            $class::{"useW48{$infix}RustFfi"}(null);
            $this->assertSame($default, $class::{"usesW48{$infix}RustFfi"}(), "{$class}: null restores the default");
            $class::{"useW48{$infix}RustFfi"}(! $default);
            $class::{"resetW48{$infix}RustFfi"}();
            $this->assertSame($default, $class::{"usesW48{$infix}RustFfi"}(), "{$class}: reset restores the default");
        }

        // Toggles are independent of each other and of the earlier useRustFfi switches.
        foreach (self::CLASSES as $class => [$infix]) {
            $class::{"useW48{$infix}RustFfi"}(! self::DEFAULTS[$class]);
            foreach (self::CLASSES as $other => [$otherInfix]) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::{"usesW48{$otherInfix}RustFfi"}(), "{$class} leaked into {$other}");
                }
            }
            $class::{"resetW48{$infix}RustFfi"}();
        }
        $legacy = LogReader::usesRustFfi();
        LogReader::useW48LogReaderRustFfi(! $legacy);
        $this->assertSame($legacy, LogReader::usesRustFfi());
        $legacy = ReleaseSearchService::usesRustFfi();
        ReleaseSearchService::useW48ReleaseSearchServiceRustFfi(! $legacy);
        $this->assertSame($legacy, ReleaseSearchService::usesRustFfi());
    }

    public function test_entry_points_route_by_toggle(): void
    {
        $this->requireFfi();
        $plans = [
            LogEntry::class => static fn () => LogEntry::classifyEntryBatch(["[2026-10-25 12:00:00] a.INFO: x\n#0 y"]),
            LogFile::class => static fn () => LogFile::rotationMetadataBatch([['laravel-2026-10-25.log', 10, 5], ['laravel.log.1', 5, 9]]),
            LogFileRepository::class => static fn () => LogFileRepository::inventoryPathBatch(['a.log', 'b/c.log'], '*.log'),
            LogReader::class => static fn () => LogReader::assembleRecordBatch(['[2026-10-25 12:00:00] a.INFO: x', '#0 y', ''], 0, true, 100, 0, 0, 10),
            ReleaseSearchService::class => static fn () => ReleaseSearchService::normalizeSearchRequestBatch([['the.movie !cam', 2040, 1, 3, 0, 30]]),
        ];
        foreach ($plans as $class => $plan) {
            $infix = self::CLASSES[$class][0];
            $class::{"useW48{$infix}RustFfi"}(false);
            $php = $plan();
            $class::{"useW48{$infix}RustFfi"}(true);
            $this->assertSame($php, $plan(), $class);
        }
    }

    public function test_classify_entry_examples(): void
    {
        $entry = "[2026-10-25 12:00:00] app.ERROR: boom {\"exception\":\"x\"} \r\n[stacktrace]\n#0 /a.php(1)\n#1 {main}\n#x";
        $this->assertBoth(LogEntry::class, [[$entry, '', "plain Exception\n#1\n\n#9 Stack trace:"]], [
            [1, 5, self::fnv32('app'), 38, 2, 5, 9, self::fnv64('app.ERROR: boom {"exception":"x"}')],
            [0, 0, 0, -1, 0, 0, 0, self::fnv64('')],
            [0, 0, 0, -1, 2, 4, 6, self::fnv64('plain Exception')],
        ]);
        $this->assertBoth(LogEntry::class, [['[2026-10-25T12:00:00.5+01:00]  prod.app.CUSTOM: x']], [[1, 0, self::fnv32('prod.app'), -1, 0, 1, 0, self::fnv64('prod.app.CUSTOM: x')]]);
        $this->assertBoth(LogEntry::class, [[]], []);
        foreach (self::LINE_PIECES as $line) {
            $this->assertSame(
                preg_match(LogEntry::W48_HEADER_PATTERN, $line) === 1,
                LogEntry::classifyEntryBatchNative([$line])[0][0] === 1,
                bin2hex($line),
            );
        }
    }

    public function test_rotation_metadata_examples(): void
    {
        $files = [['laravel-2026-02-29.log', 10, 100], ['laravel.log.2.GZ', 2048, 300], ['laravel-2024-02-29.log', 5 << 30, 300], ['worker.txt', -1, 200]];
        $this->assertBoth(LogFile::class, [$files], [
            [0, 0, 0, 1, 0, self::fnv32('laravel'), 3, 3],
            [0, 2, 1, 1, 1, self::fnv32('laravel'), 3, 1],
            [20240229, 0, 0, 1, 3, self::fnv32('laravel'), 3, 0],
            [0, 0, 0, 0, -1, self::fnv32('worker.txt'), 1, 2],
        ]);
        $this->assertBoth(LogFile::class, [[['a.1234567890', 1 << 40, PHP_INT_MIN], ['1', PHP_INT_MAX, PHP_INT_MIN], ['01', 0, PHP_INT_MAX]]], [
            [0, 0, 0, 0, 4, self::fnv32('a.1234567890'), 1, 2],
            [0, 0, 0, 0, 4, self::fnv32('1'), 1, 1],
            [0, 0, 0, 0, 0, self::fnv32('01'), 1, 0],
        ]);
        $this->assertBoth(LogFile::class, [[]], []);
    }

    public function test_inventory_path_examples(): void
    {
        $paths = [' /a.log ', 'a.log', 'b\\..\\c.log', 'x/y.log', '', 'C:a.log'];
        $result = LogFileRepository::inventoryPathBatchPhp($paths, '*.log');
        $this->assertSame([[5, 4, 0, 1, -1, 0], [5, 0, 0, 1, 0, 0], [10, 1, 2, 0, -1, 0], [7, 0, 1, 0, -1, 0], [0, 8, 0, 0, -1, 0], [7, 4, 0, 1, -1, 0]], array_map(static fn (array $row): array => \array_slice($row, 0, 6), $result['paths']));
        $this->assertSame([0, 4, 1, 0, 0], $result['summary']);
        $this->assertSame($result, LogFileRepository::inventoryPathBatchNative($paths, '*.log'));

        $this->assertBoth(LogFileRepository::class, [['x/y/z.log', 'z.log', 'x/yz.log', "a\0b", 'a//b', './a'], '**/?.log'], [
            'paths' => [
                [9, 0, 2, 1, -1, 1, self::fnv64('x/y/z.log')],
                [5, 0, 0, 0, -1, 0, self::fnv64('z.log')],
                [8, 0, 1, 0, -1, 0, self::fnv64('x/yz.log')],
                [3, 2, 0, 0, -1, 0, self::fnv64("a\0b")],
                [4, 16, 2, 0, -1, 0, self::fnv64('a//b')],
                [3, 16, 1, 0, -1, 0, self::fnv64('./a')],
            ],
            'summary' => [1, 3, 0, self::fnv64('x/y/z.log'), 2],
        ]);
        $this->assertBoth(LogFileRepository::class, [[], ''], ['paths' => [], 'summary' => [0, 0, 0, 0, 0]]);
    }

    public function test_assemble_record_examples(): void
    {
        $lines = ['orphan', '[2026-10-25 12:00:00] a.ERROR: x', '#0 frame', "[2026-10-25 12:00:01] a.INFO: y\r", '[2026-10-25 12:00:02] a.ERROR: z', ''];
        $this->assertBoth(LogReader::class, [$lines, 100, true, 1000, 5, 0, 1], [
            'records' => [
                [182, 214, 4, 1, 32, 0, 5, 0, 1],
                [149, 181, 3, 1, 31, 0, 2, -1, 0],
                [107, 148, 1, 2, 41, 0, 5, 1, 0],
                [100, 106, -1, 1, 6, 0, 0, -1, 0],
            ],
            'summary' => [4, 2, 1, 182, 0],
        ]);
        $this->assertBoth(LogReader::class, [['[2026-10-25 12:00:00] a.B: x', 'abcdef', 'gh'], 0, true, 4, 0, 0, 9], [
            'records' => [[0, 38, 0, 2, 9, 1, 0, 0, 1]],
            'summary' => [1, 1, 1, -1, 1],
        ]);
        $this->assertBoth(LogReader::class, [['a', 'b'], PHP_INT_MIN, false, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX], [
            'records' => [[2, 3, -1, 1, 1, 0, 0, 0, 0], [0, 1, -1, 1, 1, 0, 0, 1, 0]],
            'summary' => [2, 2, 0, -1, 0],
        ]);
        $this->assertBoth(LogReader::class, [[], 0, true, 10, 0, 0, 10], ['records' => [], 'summary' => [0, 0, 0, -1, 0]]);
        $this->assertBoth(LogReader::class, [[''], 0, true, 10, 0, 0, 10], ['records' => [], 'summary' => [0, 0, 0, -1, 0]]);
    }

    public function test_search_request_examples(): void
    {
        $requests = [["  The.Movie-2026_!X264 !the !! a movie\x0c", 5030, 9, 3, 400000, 30], ["\0 .", 0, 2, 1, 7, 5], ['ab', PHP_INT_MIN, PHP_INT_MAX, 0, PHP_INT_MIN, PHP_INT_MAX]];
        $this->assertBoth(ReleaseSearchService::class, [$requests], [
            [36, 3, 2, 2, 524288000, 16777216000, 30, 36500, 5030, 5000, self::fnv64('2026 movie the|the x264|5030|524288000|16777216000|30|36500')],
            [0, 0, 0, 0, 104857600, 209715200, 5, 7, -1, -1, self::fnv64('||-1|104857600|209715200|5|7')],
            [2, 1, 0, 0, 0, 0, -1, 36500, -1, -1, self::fnv64('ab||-1|0|0|-1|36500')],
        ]);
        $this->assertBoth(ReleaseSearchService::class, [[]], []);
        // The term normalization matches the MySQL fallback phrase.
        $service = new ReleaseSearchService;
        foreach (["  The.Movie-2026_!X264 \x0b\x0c a\n", "\0 .", 'A__B--C..D'] as $term) {
            $this->assertSame(\strlen($service->normalizeMysqlFallbackPhrasePhp($term)), ReleaseSearchService::normalizeSearchRequestBatchNative([[$term, 0, 0, 0, 0, 0]])[0][0]);
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame([0, 0, 0, -1, 0, 1, 0, self::fnv64('x')], LogReader::evaluateW48Fields(1, pack('P', 1).LogReader::w48Strings(['x']), 8));
        $this->assertSame([0, 0, 0, 0, 0, self::fnv32('x'), 1, 0], LogReader::evaluateW48Fields(2, pack('P*', 1, 0, 0).LogReader::w48Strings(['x']), 8));
        $this->assertSame([1, 0, 0, 1, -1, 1, self::fnv64('x'), 1, 0, 0, self::fnv64('x'), 0], LogReader::evaluateW48Fields(3, pack('P', 1).LogReader::w48Strings(['', 'x']), 12));
        $this->assertSame([0, 1, -1, 1, 1, 0, 0, 0, 1, 1, 1, 1, -1, 0], LogReader::evaluateW48Fields(4, pack('P*', 1, 0, 1, 10, 0, 0, 5).LogReader::w48Strings(['x']), 14));
        $this->assertSame([0, 0, 0, 0, 0, 0, -1, -1, -1, -1, self::fnv64('||-1|0|0|-1|-1')], LogReader::evaluateW48Fields(5, pack('P*', 1, 0, 0, 0, -1, -1).LogReader::w48Strings(['']), 11));
        $this->assertSame([], LogReader::evaluateW48Fields(1, pack('P', 0), 0));

        $this->assertNull(LogReader::evaluateW48Fields(0, '', 0));
        $this->assertNull(LogReader::evaluateW48Fields(6, pack('P', 0), 0));
        $this->assertNull(LogReader::evaluateW48Fields(1, pack('P', 0), -1));
        $this->assertNull(LogReader::evaluateW48Fields(1, pack('P', 1).LogReader::w48Strings(['x']).'y', 8));
        $this->assertNull(LogReader::evaluateW48Fields(2, pack('P', 2).LogReader::w48Strings(['a']), 16));
        $this->assertNull(LogReader::evaluateW48Fields(4, pack('P*', 0, 0, 2, 1, 0, 0, 0), 5));
        $this->assertNull(ReleaseSearchService::evaluateW48Native(5, pack('P', 1), 88));
        $this->assertSame(88, \strlen((string) ReleaseSearchService::evaluateW48Native(5, pack('P*', 1, 0, 0, 0, 0, 0).LogReader::w48Strings(['']), 88)));

        // The output buffer grows for large batches.
        $lines = array_map(static fn (int $i): string => $i % 3 === 0 ? '[2026-10-25 12:00:00] a.ERROR: '.$i : '#'.$i, range(0, 4999));
        $this->assertSame(LogReader::assembleRecordBatchPhp($lines, 0, true, 64, 0, 10, 50), LogReader::assembleRecordBatchNative($lines, 0, true, 64, 0, 10, 50));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(LogReader::class))->setStaticPropertyValue('w48Ffi', false);
        (new ReflectionClass(ReleaseSearchService::class))->setStaticPropertyValue('w48Ffi', false);
        $this->assertNull(LogReader::evaluateW48Fields(1, pack('P', 0), 0));
        $this->assertNull(ReleaseSearchService::evaluateW48Native(5, pack('P', 0), 0));

        $this->assertSame(LogEntry::classifyEntryBatchPhp(["#1\n#2"]), LogEntry::classifyEntryBatchNative(["#1\n#2"]));
        $this->assertSame(LogFile::rotationMetadataBatchPhp([['a.log.1', 1, 1]]), LogFile::rotationMetadataBatchNative([['a.log.1', 1, 1]]));
        $this->assertSame(LogFileRepository::inventoryPathBatchPhp(['a', '../b'], '*'), LogFileRepository::inventoryPathBatchNative(['a', '../b'], '*'));
        $this->assertSame(LogReader::assembleRecordBatchPhp(['x', '#1'], 0, true, 9, 0, 0, 1), LogReader::assembleRecordBatchNative(['x', '#1'], 0, true, 9, 0, 0, 1));
        $this->assertSame(ReleaseSearchService::normalizeSearchRequestBatchPhp([['a.b', 1, 1, 1, 1, 1]]), ReleaseSearchService::normalizeSearchRequestBatchNative([['a.b', 1, 1, 1, 1, 1]]));
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();
        $small = $ffi->new('char[8]');
        $big = $ffi->new('char[128]');
        $call = static function (int $mode, string $input, mixed $out, int $cap) use ($ffi): int {
            // @phpstan-ignore method.notFound
            return $ffi->nntmux_w48_logviewer_entry_file_repo_reader_release_search($mode, $input, \strlen($input), $out, $cap);
        };

        $packet = pack('P', 1).LogReader::w48Strings(['x']);
        $this->assertSame(64, $call(1, $packet, $big, 128));
        $this->assertSame(-64, $call(1, $packet, $small, 8));
        $this->assertSame("\0", FFI::string($small, 1));
        $this->assertSame(-64, $call(1, $packet, null, 0));
        $this->assertSame(-1, $call(1, $packet, null, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $packet, $big, 128));
        }
        foreach ([1, 2, 3, 4, 5] as $mode) {
            $this->assertSame(-1, $call($mode, '', $big, 128), "mode {$mode} empty input");
            $this->assertSame(-1, $call($mode, pack('P', -1), $big, 128), "mode {$mode} negative count");
        }
        $this->assertSame(-1, $call(1, $packet.'y', $big, 128));
        $this->assertSame(-1, $call(3, pack('P', 1).LogReader::w48Strings(['*']), $big, 128));
        $this->assertSame(-1, $call(4, pack('P*', 0, 0, 2, 1, 0, 0, 0), $big, 128));
        $this->assertSame(-1, $call(5, pack('P*', 1, 0, 0, 0, 0), $big, 128));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();
        mt_srand(4858);
        $pick = static fn (array $items): mixed => $items[mt_rand(0, \count($items) - 1)];
        $int = static fn (): int => match (mt_rand(0, 6)) {
            0 => $pick([PHP_INT_MAX, PHP_INT_MIN, 0, -1, 1, 1 << 40, 1 << 48, 1023, 1024, 1048576, 1 << 30, 1 << 40]),
            1 => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            2 => mt_rand(-50, 50),
            default => mt_rand(0, 5000),
        };
        $joined = static function (array $pieces, int $max, string $glue = '') use ($pick): string {
            $out = [];
            for ($i = mt_rand(0, $max); $i > 0; $i--) {
                $out[] = mt_rand(0, 4) === 0 ? self::randomBytes(6, true) : $pick($pieces);
            }

            return implode($glue, $out);
        };

        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $texts = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $texts[] = self::mutate($joined(self::LINE_PIECES, 6, $pick(["\n", "\r\n", "\n\n"])), $pick);
            }
            $this->assertSame(LogEntry::classifyEntryBatchPhp($texts), LogEntry::classifyEntryBatchNative($texts), bin2hex(serialize($texts)));

            $files = [];
            for ($i = mt_rand(0, 10); $i > 0; $i--) {
                $files[] = [$joined(self::NAME_PIECES, 5), mt_rand(0, 2) === 0 ? $int() : mt_rand(-10, 1 << 31), mt_rand(0, 2) === 0 ? $int() : mt_rand(0, 3)];
            }
            $this->assertSame(LogFile::rotationMetadataBatchPhp($files), LogFile::rotationMetadataBatchNative($files), bin2hex(serialize($files)));

            $paths = [];
            for ($i = mt_rand(0, 10); $i > 0; $i--) {
                $paths[] = $pick(['', ' ', "\t", "\0", '/', '\\']).$joined(self::PATH_PIECES, 4, $pick(['/', '\\', '/'])).$pick(['', ' ', "\n", "\x0b"]);
            }
            $pattern = mt_rand(0, 3) === 0 ? self::randomBytes(5, true) : $pick(self::PATTERNS);
            $this->assertSame(LogFileRepository::inventoryPathBatchPhp($paths, $pattern), LogFileRepository::inventoryPathBatchNative($paths, $pattern), bin2hex(serialize([$paths, $pattern])));

            $lines = [];
            for ($i = mt_rand(0, 14); $i > 0; $i--) {
                $lines[] = mt_rand(0, 3) === 0 ? self::randomBytes(40, true) : self::mutate($pick(self::LINE_PIECES), $pick);
            }
            $arguments = [
                $lines,
                mt_rand(0, 3) === 0 ? $int() : mt_rand(0, 1000),
                mt_rand(0, 3) !== 0,
                mt_rand(0, 3) === 0 ? $int() : mt_rand(1, 80),
                mt_rand(0, 4) === 0 ? $int() : mt_rand(-1, 9),
                mt_rand(0, 4) === 0 ? $int() : mt_rand(0, 4),
                mt_rand(0, 4) === 0 ? $int() : mt_rand(0, 6),
            ];
            $this->assertSame(LogReader::assembleRecordBatchPhp(...$arguments), LogReader::assembleRecordBatchNative(...$arguments), bin2hex(serialize($arguments)));

            $requests = [];
            for ($i = mt_rand(0, 8); $i > 0; $i--) {
                $requests[] = [
                    $joined(self::TERM_PIECES, 10, $pick(['', ' ', '.'])),
                    mt_rand(0, 3) === 0 ? $int() : mt_rand(-1, 8999),
                    mt_rand(0, 3) === 0 ? $int() : mt_rand(-1, 12),
                    mt_rand(0, 3) === 0 ? $int() : mt_rand(-1, 12),
                    mt_rand(0, 3) === 0 ? $int() : mt_rand(-2, 40000),
                    mt_rand(0, 3) === 0 ? $int() : mt_rand(-2, 40000),
                ];
            }
            $this->assertSame(ReleaseSearchService::normalizeSearchRequestBatchPhp($requests), ReleaseSearchService::normalizeSearchRequestBatchNative($requests), bin2hex(serialize($requests)));
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi();
        mt_srand(4851);
        $strict = getenv('NNTMUX_W48_BENCH') === '1';
        $samples = $strict ? 1000 : 300;
        $workloads = [];

        foreach (['common' => 64, 'uncommon' => 128] as $name => $count) {
            $odd = $name === 'uncommon';
            $texts = [];
            $files = [];
            $paths = [];
            $lines = [];
            $requests = [];
            for ($i = 0; $i < $count; $i++) {
                $text = '[2026-10-25 12:00:'.sprintf('%02d', $i % 60).'] production.'.($i % 3 === 0 ? 'ERROR' : 'INFO').': Request '.$i.' handled in '.mt_rand(1, 999).'ms {"user":'.$i.'}';
                if ($odd && $i % 2 === 0) {
                    $text .= " {\"exception\":\"[object] (RuntimeException(code: 0): boom)\"}\n[stacktrace]";
                    for ($frame = 0, $frames = mt_rand(5, 40); $frame < $frames; $frame++) {
                        $text .= "\n#".$frame.' /app/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php('.mt_rand(10, 999).'): Illuminate\\Pipeline\\Pipeline->handle()';
                    }
                    $text .= "\n\"}";
                } elseif ($odd && $i % 5 === 0) {
                    $text = 'plain worker output '.str_repeat('Exception Stack trace: ', mt_rand(1, 6));
                }
                $texts[] = $text;

                $files[] = $odd
                    ? [match ($i % 5) {
                        0 => 'laravel.log.'.($i % 9).'.gz',
                        1 => 'worker-'.$i.'-2026-13-'.sprintf('%02d', $i % 40).'.LOG.ZST',
                        2 => "odd name \xff ".$i,
                        3 => 'horizon-2024-02-29.log.'.$i,
                        default => 'laravel-2026-10-'.sprintf('%02d', 1 + $i % 28).'.log',
                    }, $i % 7 === 0 ? -1 : mt_rand(0, 1 << 41), $i % 3 === 0 ? 1761393600 : mt_rand(0, 1761393600)]
                    : ['laravel-2026-10-'.sprintf('%02d', 1 + $i % 28).'.log', mt_rand(1000, 90000000), 1761393600 - $i * 86400];

                $paths[] = $odd
                    ? match ($i % 6) {
                        0 => '../etc/passwd'.$i,
                        1 => ' \\nested\\dir\\worker-'.$i.'.log ',
                        2 => 'archive/'.($i % 4).'/laravel.log',
                        3 => "bad\0name-".$i.'.log',
                        4 => 'deep/a/b/c/d/e/f/g-'.$i.'.log',
                        default => 'worker-'.($i % 10).'.log',
                    }
                : ($i % 4 === 0 ? 'laravel-'.$i.'.log' : 'workers/worker-'.$i.'.log');

                $lines[] = match (true) {
                    $odd && $i % 3 === 0 => '#'.$i.' /app/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(183): Illuminate\\Foundation\\Http\\Middleware\\TrimStrings->handle()',
                    $odd && $i % 10 === 1 => str_repeat("binary \xff\xfe noise ", mt_rand(20, 80))."\r",
                    $i % 6 === 0 => '[2026-10-25 12:00:00] production.ERROR: Connection refused by usenet.example.test:563',
                    default => '[2026-10-25 12:00:00] production.INFO: Processed release '.$i.' in group alt.binaries.example',
                };

                $requests[] = $odd
                    ? [str_repeat('Some.Show-S01E0'.($i % 9).'_1080p !CAM !! a ', mt_rand(1, 4)), $i % 4 === 0 ? -1 : 5000 + $i, $i % 13, 12 - $i % 13, $i % 5 === 0 ? -1 : mt_rand(0, 40000), mt_rand(-1, 40000)]
                    : ['The.Movie.'.(2000 + $i % 26).'.1080p.BluRay', 2040, 1 + $i % 4, 6 + $i % 5, 0, 30];
            }

            $workloads[LogEntry::class][$name] = static fn (bool $rust) => $rust ? LogEntry::classifyEntryBatchNative($texts) : LogEntry::classifyEntryBatchPhp($texts);
            $workloads[LogFile::class][$name] = static fn (bool $rust) => $rust ? LogFile::rotationMetadataBatchNative($files) : LogFile::rotationMetadataBatchPhp($files);
            $pattern = $odd ? '**/*.log' : '*.log';
            $workloads[LogFileRepository::class][$name] = static fn (bool $rust) => $rust ? LogFileRepository::inventoryPathBatchNative($paths, $pattern) : LogFileRepository::inventoryPathBatchPhp($paths, $pattern);
            $workloads[LogReader::class][$name] = static fn (bool $rust) => $rust
                ? LogReader::assembleRecordBatchNative($lines, 1 << 20, true, $odd ? 96 : 32768, $odd ? 5 : 0, $odd ? 3 : 0, 25)
                : LogReader::assembleRecordBatchPhp($lines, 1 << 20, true, $odd ? 96 : 32768, $odd ? 5 : 0, $odd ? 3 : 0, 25);
            $workloads[ReleaseSearchService::class][$name] = static fn (bool $rust) => $rust ? ReleaseSearchService::normalizeSearchRequestBatchNative($requests) : ReleaseSearchService::normalizeSearchRequestBatchPhp($requests);
        }

        foreach ($workloads as $class => $byName) {
            $results = [];
            foreach ($byName as $name => $workload) {
                $this->assertSame($workload(false), $workload(true), "{$class} {$name} benchmark workload must agree");
                for ($w = 0; $w < 20; $w++) {
                    $workload(false);
                    $workload(true);
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach (mt_rand(0, 1) === 1 ? [true, false] : [false, true] as $rust) {
                        $t0 = hrtime(true);
                        $workload($rust);
                        $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / 1000;
                    }
                }

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, sprintf(
                "\n[Wave 48.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            if (! self::DEFAULTS[$class]) {
                continue;
            }
            $ratios = [$results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']];
            if ($strict) {
                $this->assertGreaterThanOrEqual(1.5, min($ratios), "{$class} defaults to Rust without clearing every 1.5x mean/p99 gate");
            } else {
                $this->assertGreaterThanOrEqual(1.0, min($results['common']['mean'], $results['uncommon']['mean']), "{$class} defaults to Rust but Rust is slower than PHP");
            }
        }
        fwrite(STDERR, "\n");
    }

    /**
     * Asserts that the PHP and native variants of the class's batch method both return $expected.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $arguments
     */
    private function assertBoth(string $class, array $arguments, mixed $expected): void
    {
        $method = self::METHODS[$class];
        $this->assertSame($expected, $class::{$method.'Php'}(...$arguments), "{$class}::{$method}Php");
        $this->assertSame($expected, $class::{$method.'Native'}(...$arguments), "{$class}::{$method}Native");
    }

    private static function fnv64(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('fnv1a64', $value, true));

        return $unpacked[1];
    }

    private static function fnv32(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', hash('fnv1a32', $value, true));

        return $unpacked[1];
    }

    private static function randomBytes(int $maxLength, bool $withNul = false, int $minLength = 0): string
    {
        $out = '';
        for ($i = mt_rand($minLength, $maxLength); $i > 0; $i--) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $out .= $byte === "\0" && ! $withNul ? 'n' : $byte;
        }

        return $out;
    }

    private static function mutate(string $value, callable $pick): string
    {
        if ($value !== '' && mt_rand(0, 4) === 0) {
            $value[mt_rand(0, \strlen($value) - 1)] = $pick(self::BYTES)[0];
        }

        return $value;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search_w48', LogReader::W48_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
