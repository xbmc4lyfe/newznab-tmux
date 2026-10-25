<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\Index\LogIndex;
use App\Services\LogViewer\Index\LogIndexer;
use App\Services\LogViewer\Index\LogSearchCoordinator;
use App\Services\LogViewer\Index\ManticoreLogSearcher;
use App\Services\LogViewer\LogSearcher;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W47_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, release dylib. Common workloads hold 64 items and uncommon ones 128 items with edge cases.
 * Timings include PHP-side framing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every common and uncommon mean and p99 ratio reaches 1.5x. The
 * default run only checks that Rust-default helpers are not slower than PHP, so slower CI hosts do not
 * flake; `NNTMUX_W47_BENCH=1` enforces the full 1.5x gate for every Rust default.
 */
class RustLogViewerIndexIndexerCoordinatorManticoreSearcherW47ParityTest extends TestCase
{
    /**
     * Toggle infix and default constant per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const CLASSES = [
        LogIndex::class => ['LogIndex', 'W47_LOG_INDEX_RUST_FFI_DEFAULT'],
        LogIndexer::class => ['LogIndexer', 'W47_LOG_INDEXER_RUST_FFI_DEFAULT'],
        LogSearchCoordinator::class => ['LogSearchCoordinator', 'W47_LOG_SEARCH_COORDINATOR_RUST_FFI_DEFAULT'],
        ManticoreLogSearcher::class => ['ManticoreLogSearcher', 'W47_MANTICORE_LOG_SEARCHER_RUST_FFI_DEFAULT'],
        LogSearcher::class => ['LogSearcher', 'W47_LOG_SEARCHER_RUST_FFI_DEFAULT'],
    ];

    /**
     * Benchmark-qualified defaults. Three strict 1000-sample runs (Apple Silicon, PHP 8.5 CLI, release
     * dylib, loaded host) gave min common mean/p99 and uncommon mean/p99 speedups of: LogIndex
     * 1.48/1.05/1.70/1.66, LogIndexer 2.55/2.15/5.00/4.59, LogSearchCoordinator 0.83/0.80/0.93/0.99,
     * ManticoreLogSearcher 1.40/1.10/1.37/1.30 and LogSearcher 0.97/0.90/2.22/1.83. Packing the batch and
     * unpacking i64 rows costs about as much as PHP's own row building, so only the line-scanning
     * LogIndexer clears every gate.
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        LogIndex::class => false,
        LogIndexer::class => true,
        LogSearchCoordinator::class => false,
        ManticoreLogSearcher::class => false,
        LogSearcher::class => false,
    ];

    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        LogIndex::class => 'indexEntryRangeBatch',
        LogIndexer::class => 'scanChunkBatch',
        LogSearchCoordinator::class => 'routeFileBatch',
        ManticoreLogSearcher::class => 'scoreHitBatch',
        LogSearcher::class => 'filterLineWindowBatch',
    ];

    private const RANDOM_ROUNDS = 600;

    private const BYTES = ['a', 'b', 'A', 'B', 'e', 'E', 'r', 'R', 'x', '0', '9', ' ', "\t", '.', ':', '-', '_', '[', ']', '#', "\r", "\0", "\xc3\xa9", "\xff", "\xe2\x82\xac"];

    private const LINE_PIECES = [
        '[2026-10-25 12:00:00] local.ERROR: boom',
        '[2026-10-25T12:00:00.123456+00:00] production.app.WARNING: slow',
        '[2026-10-25 12:00:00] .ERROR: no channel',
        '[2026-10-25 12:00:00] local.error: lower level',
        '[2026-10-25 12:00:00]  local.INFO: two spaces',
        '[2026-10-25 12:00:00 extra] a-b_c.9.DEBUG: tail',
        '[2026-1-25 12:00:00] local.INFO: short date',
        '[stacktrace]',
        '#0 /app/vendor/x.php(12): y()',
        '#',
        '#a not a frame',
        'Stack trace:',
        '  at App\\Foo->bar()',
        "\tthrown in",
        '',
        "\r",
        'plain text Error err ERR',
        "bin\0ary\xff",
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class => [$infix]) {
            $class::{'resetW47'.$infix.'RustFfi'}();
        }
        (new ReflectionClass(LogIndex::class))->setStaticPropertyValue('w47Ffi', null);

        parent::tearDown();
    }

    public function test_methods_defaults_and_toggles(): void
    {
        foreach (self::CLASSES as $class => [$infix, $constant]) {
            $reflection = new ReflectionClass($class);
            $method = self::METHODS[$class];
            foreach (["useW47{$infix}RustFfi", "usesW47{$infix}RustFfi", "resetW47{$infix}RustFfi", $method, $method.'Php', $method.'Native'] as $name) {
                $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            }
            $default = (new ReflectionClassConstant($class, $constant))->getValue();
            $this->assertSame(self::DEFAULTS[$class], $default, "{$class} default must match the benchmark evidence");
            $this->assertSame($default, $class::{"usesW47{$infix}RustFfi"}(), "{$class} must start at its default");
            $class::{"useW47{$infix}RustFfi"}(false);
            $this->assertFalse($class::{"usesW47{$infix}RustFfi"}());
            $class::{"useW47{$infix}RustFfi"}();
            $this->assertTrue($class::{"usesW47{$infix}RustFfi"}());
            $class::{"useW47{$infix}RustFfi"}(null);
            $this->assertSame($default, $class::{"usesW47{$infix}RustFfi"}(), "{$class}: null restores the default");
            $class::{"useW47{$infix}RustFfi"}(! $default);
            $class::{"resetW47{$infix}RustFfi"}();
            $this->assertSame($default, $class::{"usesW47{$infix}RustFfi"}(), "{$class}: reset restores the default");
        }

        // Toggles are independent of each other and of the earlier useRustFfi switches.
        foreach (self::CLASSES as $class => [$infix]) {
            $class::{"useW47{$infix}RustFfi"}(! self::DEFAULTS[$class]);
            foreach (self::CLASSES as $other => [$otherInfix]) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::{"usesW47{$otherInfix}RustFfi"}(), "{$class} leaked into {$other}");
                }
            }
            $class::{"resetW47{$infix}RustFfi"}();
        }
        $legacy = LogIndex::usesRustFfi();
        LogIndex::useW47LogIndexRustFfi(! $legacy);
        $this->assertSame($legacy, LogIndex::usesRustFfi());
        $legacy = LogIndexer::usesRustFfi();
        LogIndexer::useW47LogIndexerRustFfi(! $legacy);
        $this->assertSame($legacy, LogIndexer::usesRustFfi());
    }

    public function test_entry_points_route_by_toggle(): void
    {
        $this->requireFfi();
        $entries = [[1, 0, 10, 5], [1, 10, 20, 9]];
        $chunks = ["[2026-10-25 12:00:00] a.INFO: x\n#0 y\n"];
        $files = [['a.log', 10, 10, 1]];
        $hits = [[0, 5, 1, 'error', 'app', 'Err', '']];
        $lines = ['err', 'x'];
        $plans = [
            LogIndex::class => static fn () => LogIndex::indexEntryRangeBatch($entries, [10]),
            LogIndexer::class => static fn () => LogIndexer::scanChunkBatch($chunks),
            LogSearchCoordinator::class => static fn () => LogSearchCoordinator::routeFileBatch($files, true, 0, null),
            ManticoreLogSearcher::class => static fn () => ManticoreLogSearcher::scoreHitBatch($hits, 'err', false, [], []),
            LogSearcher::class => static fn () => LogSearcher::filterLineWindowBatch($lines, 'err', false, 0),
        ];
        foreach ($plans as $class => $plan) {
            $infix = self::CLASSES[$class][0];
            $class::{"useW47{$infix}RustFfi"}(false);
            $php = $plan();
            $class::{"useW47{$infix}RustFfi"}(true);
            $this->assertSame($php, $plan(), $class);
        }
    }

    public function test_index_entry_range_examples(): void
    {
        $entries = [[1, 0, 10, 100], [1, 10, 25, 300], [-1, 20, 5, 0]];
        $expected = [
            'entries' => [[1 << 40, 31], [(1 << 40) | 10, 31], [-(1 << 40) | 20, 1]],
            'ranges' => [[2, 2, 300], [0, 0, 0], [3, 2, 300], [3, 2, 300]],
        ];
        $this->assertBoth(LogIndex::class, [$entries, [15, 0, 25, PHP_INT_MAX]], $expected);
        $this->assertBoth(LogIndex::class, [[], []], ['entries' => [], 'ranges' => []]);
        $this->assertBoth(LogIndex::class, [[], [PHP_INT_MIN, 0]], ['entries' => [], 'ranges' => [[0, 0, 0], [0, 0, 0]]]);
        // Overflowing file ids wrap like PHP's shift; out-of-range offsets are flagged invalid.
        $edge = [[PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX], [1 << 23, (1 << 40) - 1, 1 << 40, 1], [0, 1 << 40, 1 << 40, -5]];
        $this->assertSame(LogIndex::indexEntryRangeBatchPhp($edge, [PHP_INT_MIN, -1, 1 << 40, PHP_INT_MAX]), LogIndex::indexEntryRangeBatchNative($edge, [PHP_INT_MIN, -1, 1 << 40, PHP_INT_MAX]));
        // A malformed tuple falls back to PHP.
        $this->assertSame(['entries' => [[0, 15]], 'ranges' => []], LogIndex::indexEntryRangeBatchNative([[0, 0, 0, 0, 9]], []));
    }

    public function test_chunk_scan_examples(): void
    {
        $chunk = "[2026-10-25 12:00:00] local.ERROR: boom\r\n[stacktrace]\n#0 /app/x.php(1)\n  at y\n[2026-10-25T12:00:01.5+00:00] prod.app.INFO: ok\npartial";
        $odd = "[2026-10-25 12:00:00] .ERROR: x\n[2026-10-25 12:00:00] a.error: x\n[2026-10-25 12:00:00]  a.B: x\n";
        $this->assertBoth(LogIndexer::class, [[$chunk, '', $odd, "\n", 'no newline']], [
            [5, 2, 3, 0, 78, 126, 78, 1],
            [0, 0, 0, -1, -1, 0, 0, 0],
            [3, 0, 0, -1, -1, 95, 0, 0],
            [1, 0, 0, -1, -1, 1, 0, 0],
            [0, 0, 0, -1, -1, 0, 0, 0],
        ]);
        $this->assertBoth(LogIndexer::class, [[]], []);
        $binary = ["[2026-10-25 12:00:00\0] a.B: \xff\n#9\0\n\0\n[2026-10-25 12:00:00]] a.B:\n"];
        $this->assertSame(LogIndexer::scanChunkBatchPhp($binary), LogIndexer::scanChunkBatchNative($binary));
    }

    public function test_route_file_examples(): void
    {
        $files = [['a.log', 100, 95, 1], ['b.log', 100, 80, 1], ['a.log', 100, 95, 0], ['c.log', 100, -1, 1]];
        $this->assertBoth(LogSearchCoordinator::class, [$files, true, 10, 50], [
            [1, 2, 95, 50, 0],
            [0, 1, 80, -1, 1],
            [0, 0, 95, -1, 0],
            [0, 0, 0, -1, 3],
        ]);
        $this->assertBoth(LogSearchCoordinator::class, [$files, false, 10, null], [
            [0, 2, 95, -1, 0],
            [0, 1, 80, -1, 1],
            [0, 0, 95, -1, 0],
            [0, 0, 0, -1, 3],
        ]);
        $this->assertBoth(LogSearchCoordinator::class, [[], true, 0, null], []);
        // Numeric-looking and binary paths stay distinct; extreme sizes never overflow the lag check.
        $edge = [['1', PHP_INT_MIN, 0, 1], ['01', PHP_INT_MAX, 0, PHP_INT_MAX], ['1', 5, 9, 1], ["a\0b", 0, 0, 1], ['', 7, PHP_INT_MAX, 1], ["a\0b", 3, 3, -1]];
        foreach ([[PHP_INT_MIN, null], [PHP_INT_MAX, PHP_INT_MIN], [0, PHP_INT_MAX], [-1, 2]] as [$lag, $before]) {
            $this->assertSame(LogSearchCoordinator::routeFileBatchPhp($edge, true, $lag, $before), LogSearchCoordinator::routeFileBatchNative($edge, true, $lag, $before));
        }
    }

    public function test_score_hit_examples(): void
    {
        $hits = [[10, 15, 3, 'error', 'app', 'ERR err', 'x'], [20, 30, -4, 'info', 'app', "err\nerr", '']];
        $this->assertBoth(ManticoreLogSearcher::class, [$hits, 'Err', false, ['error'], []], [[10, 15, 3, 1, 1, 8], [20, 30, 0, 0, 1, 8]]);
        $this->assertBoth(ManticoreLogSearcher::class, [$hits, 'Err', true, [], ['app']], [[10, 15, 3, 1, 0, 0], [20, 30, 0, 1, 0, 0]]);
        $this->assertBoth(ManticoreLogSearcher::class, [$hits, '', false, [], ['web']], [[10, 15, 3, 0, 1, 0], [20, 30, 0, 0, 1, 0]]);
        $this->assertBoth(ManticoreLogSearcher::class, [[[1, 2, 3, '', '', 'a', 'b']], "a\nb", true, [''], ['']], [[1, 2, 3, 1, 1, 0]]);
        $this->assertBoth(ManticoreLogSearcher::class, [[], 'x', false, [], []], []);
    }

    public function test_line_window_examples(): void
    {
        $lines = ['xx', 'AB ab', 'xx', 'xx', 'xx', 'zab'];
        $this->assertBoth(LogSearcher::class, [$lines, 'ab', false, 1], [
            [0, 0, -1, -1, 0], [1, 2, 0, 5, 0], [0, 0, -1, -1, 0], [0, 0, -1, -1, -1], [0, 0, -1, -1, 1], [1, 1, 1, 3, 1],
        ]);
        $this->assertBoth(LogSearcher::class, [$lines, 'ab', true, 0], [
            [0, 0, -1, -1, -1], [1, 1, 3, 5, 0], [0, 0, -1, -1, -1], [0, 0, -1, -1, -1], [0, 0, -1, -1, -1], [1, 1, 1, 3, 1],
        ]);
        $this->assertBoth(LogSearcher::class, [['a', ''], '', true, -5], [[1, 0, -1, -1, 0], [1, 0, -1, -1, 0]]);
        $this->assertBoth(LogSearcher::class, [[str_repeat('a', 250)], 'a', true, PHP_INT_MAX], [[1, 200, 0, 200, 0]]);
        $this->assertBoth(LogSearcher::class, [[], 'a', true, 3], []);
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame([1 << 40, 31, 1, 1, 5], LogIndex::evaluateW47Fields(1, pack('P*', 1, 1, 1, 0, 0, 5, 1), 5));
        $this->assertSame([0, 0, 0, -1, -1, 0, 0, 0], LogIndex::evaluateW47Fields(2, pack('P', 1).LogIndex::w47Joined(['']), 8));
        $this->assertSame([0, 0, 0, -1, 0], LogIndex::evaluateW47Fields(3, pack('P*', 1, 1, 0, 0, 0, 1, -1, 0).LogIndex::w47Joined(['x']), 5));
        $this->assertSame([1, 2, 3, 1, 1, 0], LogIndex::evaluateW47Fields(4, pack('P*', 1, 1, 0, 0, 1, 2, 3).LogIndex::w47Joined(['', '', '', 'a', '']), 6));
        $this->assertSame([1, 1, 0, 1, 0], LogIndex::evaluateW47Fields(5, pack('P*', 1, 1, 0).LogIndex::w47Joined(['a', 'a']), 5));
        $this->assertSame([], LogIndex::evaluateW47Fields(1, pack('P*', 0, 0), 0));

        $this->assertNull(LogIndex::evaluateW47Fields(0, '', 0));
        $this->assertNull(LogIndex::evaluateW47Fields(6, pack('P*', 0, 0), 0));
        $this->assertNull(LogIndex::evaluateW47Fields(1, pack('P*', 0, 1, 1), 2));
        $this->assertNull(LogIndex::evaluateW47Fields(1, pack('P*', 0, 0), -1));
        $this->assertNull(LogIndex::evaluateW47Fields(2, pack('P', 1).LogIndex::w47Joined(['a', 'b']), 8));
        $this->assertNull(LogIndex::evaluateW47Fields(3, pack('P*', 1, 2, 0, 0, 0, 1, 1, 1).LogIndex::w47Joined(['x']), 5));
        $this->assertNull(LogIndex::evaluateW47Fields(5, pack('P*', 1, 1, 0).LogIndex::w47Joined(['a']), 5));

        // The output buffer grows for large batches.
        $lines = array_map(static fn (int $i): string => 'line '.$i.' error', range(0, 4999));
        $this->assertSame(LogSearcher::filterLineWindowBatchPhp($lines, 'ERROR', false, 2), LogSearcher::filterLineWindowBatchNative($lines, 'ERROR', false, 2));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(LogIndex::class))->setStaticPropertyValue('w47Ffi', false);
        $this->assertNull(LogIndex::evaluateW47Fields(1, pack('P*', 0, 0), 0));

        $this->assertSame(LogIndex::indexEntryRangeBatchPhp([[1, 2, 3, 4]], [2]), LogIndex::indexEntryRangeBatchNative([[1, 2, 3, 4]], [2]));
        $this->assertSame(LogIndexer::scanChunkBatchPhp(["#1\n"]), LogIndexer::scanChunkBatchNative(["#1\n"]));
        $this->assertSame(LogSearchCoordinator::routeFileBatchPhp([['a', 1, 1, 1]], true, 0, 0), LogSearchCoordinator::routeFileBatchNative([['a', 1, 1, 1]], true, 0, 0));
        $hits = [[1, 2, 3, 'info', 'app', 'a', 'b']];
        $this->assertSame(ManticoreLogSearcher::scoreHitBatchPhp($hits, 'a', false, [], []), ManticoreLogSearcher::scoreHitBatchNative($hits, 'a', false, [], []));
        $this->assertSame(LogSearcher::filterLineWindowBatchPhp(['a'], 'a', false, 1), LogSearcher::filterLineWindowBatchNative(['a'], 'a', false, 1));
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();
        $small = $ffi->new('char[8]');
        $big = $ffi->new('char[64]');
        $call = static function (int $mode, string $input, mixed $out, int $cap) use ($ffi): int {
            // @phpstan-ignore method.notFound
            return $ffi->nntmux_w47_logviewer_index_indexer_coordinator_manticore_searcher($mode, $input, \strlen($input), $out, $cap);
        };

        $packet = pack('P*', 0, 1, 9);
        $this->assertSame(24, $call(1, $packet, $big, 64));
        $this->assertSame(-24, $call(1, $packet, $small, 8));
        $this->assertSame("\0", FFI::string($small, 1));
        $this->assertSame(-24, $call(1, $packet, null, 0));
        $this->assertSame(-1, $call(1, $packet, null, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $packet, $big, 64));
        }
        foreach ([1, 2, 3, 4, 5] as $mode) {
            $this->assertSame(-1, $call($mode, '', $big, 64), "mode {$mode} empty input");
            $this->assertSame(-1, $call($mode, pack('P', -1), $big, 64), "mode {$mode} negative count");
        }
        $this->assertSame(-1, $call(2, pack('P', 2).LogIndex::w47Joined(['a']), $big, 64));
        $this->assertSame(-1, $call(4, pack('P*', 0, 2, 0, 0).LogIndex::w47Joined(['']), $big, 64));
        $this->assertSame(-1, $call(5, pack('P*', 0, 1, 0).LogIndex::w47Joined(['', '']), $big, 64));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();
        mt_srand(4757);
        $pick = static fn (array $items): mixed => $items[mt_rand(0, \count($items) - 1)];
        $int = static fn (): int => match (mt_rand(0, 6)) {
            0 => $pick([PHP_INT_MAX, PHP_INT_MIN, 0, -1, 1, 1 << 40, (1 << 40) - 1, 1 << 23, (1 << 23) - 1]),
            1 => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            2 => mt_rand(-50, 50),
            default => mt_rand(0, 5000),
        };

        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $entries = [];
            $offset = mt_rand(0, 100);
            for ($i = mt_rand(0, 12); $i > 0; $i--) {
                $entries[] = mt_rand(0, 4) === 0
                    ? [$int(), $int(), $int(), $int()]
                    : [mt_rand(0, 40), $offset, $offset += mt_rand(0, 300), mt_rand(-2, 1) < 0 ? 0 : mt_rand(1, 2000000000)];
                if (mt_rand(0, 6) === 0) {
                    $offset -= mt_rand(0, 400);
                }
            }
            $cursors = [];
            for ($i = mt_rand(0, 8); $i > 0; $i--) {
                $cursors[] = mt_rand(0, 3) === 0 ? $int() : mt_rand(-10, max(-10, $offset + 10));
            }
            $this->assertSame(LogIndex::indexEntryRangeBatchPhp($entries, $cursors), LogIndex::indexEntryRangeBatchNative($entries, $cursors), json_encode([$entries, $cursors]) ?: '');

            $chunks = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $chunks[] = self::randomChunk($pick);
            }
            $this->assertSame(LogIndexer::scanChunkBatchPhp($chunks), LogIndexer::scanChunkBatchNative($chunks), bin2hex(serialize($chunks)));

            $files = [];
            $paths = ['a.log', 'b.log', 'laravel.log', '1', '01', '', "x\0y", 'é.log'];
            for ($i = mt_rand(0, 10); $i > 0; $i--) {
                $size = mt_rand(0, 3) === 0 ? $int() : mt_rand(0, 5000);
                $files[] = [$pick($paths), $size, mt_rand(0, 4) === 0 ? $int() : mt_rand(-1, 5000), mt_rand(0, 4) === 0 ? $int() : mt_rand(0, 1)];
            }
            $usable = mt_rand(0, 3) !== 0;
            $lag = mt_rand(0, 5) === 0 ? $int() : mt_rand(0, 2000);
            $before = mt_rand(0, 2) === 0 ? null : $int();
            $this->assertSame(
                LogSearchCoordinator::routeFileBatchPhp($files, $usable, $lag, $before),
                LogSearchCoordinator::routeFileBatchNative($files, $usable, $lag, $before),
                bin2hex(serialize([$files, $usable, $lag, $before])),
            );

            $term = mt_rand(0, 5) === 0 ? '' : self::randomBytes(3, true, 1);
            $caseSensitive = mt_rand(0, 1) === 1;
            $levelNames = ['error', 'info', 'debug', 'warning', '', 'ERROR'];
            $channelNames = ['app', 'local', 'web', '', "a\0"];
            $levels = mt_rand(0, 2) === 0 ? [] : array_slice($levelNames, 0, mt_rand(1, 3));
            $channels = mt_rand(0, 2) === 0 ? [] : array_slice($channelNames, mt_rand(0, 2), mt_rand(1, 2));
            $hits = [];
            for ($i = mt_rand(0, 8); $i > 0; $i--) {
                $hits[] = [$int(), $int(), $int(), $pick($levelNames), $pick($channelNames), self::randomBytes(24, true), mt_rand(0, 2) === 0 ? '' : self::randomBytes(60, true)];
            }
            $this->assertSame(
                ManticoreLogSearcher::scoreHitBatchPhp($hits, $term, $caseSensitive, $levels, $channels),
                ManticoreLogSearcher::scoreHitBatchNative($hits, $term, $caseSensitive, $levels, $channels),
                bin2hex(serialize([$hits, $term, $caseSensitive, $levels, $channels])),
            );

            $lines = [];
            for ($i = mt_rand(0, 14); $i > 0; $i--) {
                $lines[] = mt_rand(0, 3) === 0 ? $pick(self::LINE_PIECES) : self::randomBytes(40, true);
            }
            $context = match (mt_rand(0, 4)) {
                0 => $int(),
                1 => -mt_rand(0, 3),
                default => mt_rand(0, 3),
            };
            $this->assertSame(
                LogSearcher::filterLineWindowBatchPhp($lines, $term, $caseSensitive, $context),
                LogSearcher::filterLineWindowBatchNative($lines, $term, $caseSensitive, $context),
                bin2hex(serialize([$lines, $term, $caseSensitive, $context])),
            );
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi();
        mt_srand(4751);
        $strict = getenv('NNTMUX_W47_BENCH') === '1';
        $samples = $strict ? 1000 : 300;
        $workloads = [];

        foreach (['common' => 64, 'uncommon' => 128] as $name => $count) {
            $odd = $name === 'uncommon';
            $entries = [];
            $cursors = [];
            $chunks = [];
            $files = [];
            $hits = [];
            $lines = [];
            $offset = 0;
            for ($i = 0; $i < $count; $i++) {
                $length = $odd && $i % 9 === 0 ? mt_rand(2000, 9000) : mt_rand(80, 400);
                $entries[] = $odd && $i % 7 === 0
                    ? [mt_rand(-5, 1 << 24), $offset - mt_rand(0, 500), $offset - 1, 0]
                    : [17, $offset, $offset + $length, 1761393600 + $i];
                $offset += $length;
                $cursors[] = mt_rand(-100, $offset + 100);

                $chunk = '';
                for ($entry = 0, $entryCount = $odd ? mt_rand(1, 12) : mt_rand(2, 5); $entry < $entryCount; $entry++) {
                    $chunk .= '[2026-10-25 12:'.sprintf('%02d:%02d', $entry % 60, $i % 60).'] production.'.($entry % 3 === 0 ? 'ERROR' : 'INFO').': Request '.$i.' handled in '.mt_rand(1, 999)."ms\n";
                    if ($odd && $entry % 2 === 0) {
                        $chunk .= "[stacktrace]\n";
                        for ($frame = 0; $frame < mt_rand(3, 25); $frame++) {
                            $chunk .= '#'.$frame.' /app/vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php('.mt_rand(10, 999)."): handle()\r\n";
                        }
                        $chunk .= "\"}\n  at App\\Http\\Controllers\\Foo->bar()\n";
                    }
                }
                $chunks[] = $odd && $i % 5 === 0 ? $chunk.'[2026-10-25 12:00:00] partial.ERR' : $chunk;

                $size = mt_rand(1000, 90000000);
                $files[] = $odd
                    ? ['storage/logs/'.($i % 11 === 0 ? 'laravel.log' : 'worker-'.$i.'.log'), $size, match ($i % 4) {
                        0 => -1,
                        1 => $size + 5,
                        2 => $size - mt_rand(0, 4000000),
                        default => $size,
                    }, $i % 6 === 0 ? 0 : 1761393600]
                    : ['storage/logs/laravel-2026-10-'.sprintf('%02d', 1 + $i % 28).'-'.$i.'.log', $size, $size - mt_rand(0, 2000), 1761393600];

                $first = '[2026-10-25 12:00:00] production.ERROR: Connection error '.$i.' while talking to NNTP host';
                $body = $odd && $i % 3 === 0
                    ? str_repeat('#'.$i.' /app/Services/NNTP/Client.php(88): Error handling error path ERROR', mt_rand(5, 40))
                    : ($i % 2 === 0 ? '' : '{"exception":"[object] (RuntimeException(code: 0): error)"}');
                $hits[] = [$offset, $offset + $length, $i + 1, $i % 4 === 0 ? 'info' : 'error', $i % 5 === 0 ? 'web' : 'app', $first, $body];

                $lines[] = match (true) {
                    $odd && $i % 10 === 0 => str_repeat("Error \xff\xfe error ", mt_rand(20, 120)),
                    $odd && $i % 4 === 0 => '#'.$i.' /app/vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(183): Illuminate\\Foundation\\Http\\Middleware\\TrimStrings->handle()',
                    $i % 6 === 0 => '[2026-10-25 12:00:00] production.ERROR: Connection refused by usenet.example.test:563',
                    default => '[2026-10-25 12:00:00] production.INFO: Processed release '.$i.' in group alt.binaries.example',
                };
            }

            $workloads[LogIndex::class][$name] = static fn (bool $rust) => $rust ? LogIndex::indexEntryRangeBatchNative($entries, $cursors) : LogIndex::indexEntryRangeBatchPhp($entries, $cursors);
            $workloads[LogIndexer::class][$name] = static fn (bool $rust) => $rust ? LogIndexer::scanChunkBatchNative($chunks) : LogIndexer::scanChunkBatchPhp($chunks);
            $workloads[LogSearchCoordinator::class][$name] = static fn (bool $rust) => $rust
                ? LogSearchCoordinator::routeFileBatchNative($files, true, 1048576, $odd ? $offset : null)
                : LogSearchCoordinator::routeFileBatchPhp($files, true, 1048576, $odd ? $offset : null);
            $levels = $odd ? ['error', 'critical', 'alert'] : ['error'];
            $workloads[ManticoreLogSearcher::class][$name] = static fn (bool $rust) => $rust
                ? ManticoreLogSearcher::scoreHitBatchNative($hits, $odd ? 'ERROR' : 'connection', $odd, $levels, $odd ? ['app'] : [])
                : ManticoreLogSearcher::scoreHitBatchPhp($hits, $odd ? 'ERROR' : 'connection', $odd, $levels, $odd ? ['app'] : []);
            $workloads[LogSearcher::class][$name] = static fn (bool $rust) => $rust
                ? LogSearcher::filterLineWindowBatchNative($lines, $odd ? 'error' : 'Connection', ! $odd, $odd ? 3 : 1)
                : LogSearcher::filterLineWindowBatchPhp($lines, $odd ? 'error' : 'Connection', ! $odd, $odd ? 3 : 1);
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
                "\n[Wave 47.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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

    private static function randomBytes(int $maxLength, bool $withNul = false, int $minLength = 0): string
    {
        $out = '';
        for ($i = mt_rand($minLength, $maxLength); $i > 0; $i--) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $out .= $byte === "\0" && ! $withNul ? 'n' : $byte;
        }

        return $out;
    }

    private static function randomChunk(callable $pick): string
    {
        $chunk = '';
        for ($i = mt_rand(0, 8); $i > 0; $i--) {
            $piece = mt_rand(0, 2) === 0 ? self::randomBytes(30, true) : $pick(self::LINE_PIECES);
            if (mt_rand(0, 4) === 0 && $piece !== '') {
                $position = mt_rand(0, \strlen($piece) - 1);
                $piece[$position] = $pick(self::BYTES)[0];
            }
            $chunk .= $piece.(mt_rand(0, 5) === 0 ? "\r\n" : "\n");
        }

        return mt_rand(0, 2) === 0 ? $chunk.self::randomBytes(10) : $chunk;
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
        $ffi = RustPipelineLibrary::resolveDomain('search_w47', LogIndex::W47_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
