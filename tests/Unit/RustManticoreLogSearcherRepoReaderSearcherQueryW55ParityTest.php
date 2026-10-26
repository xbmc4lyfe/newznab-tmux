<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\Index\ManticoreLogSearcher;
use App\Services\LogViewer\LogEntry;
use App\Services\LogViewer\LogEntryParser;
use App\Services\LogViewer\LogFileRepository;
use App\Services\LogViewer\LogReader;
use App\Services\LogViewer\LogSearcher;
use App\Services\LogViewer\SearchQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W55_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, each side the fastest of three back-to-back calls, release dylib. Common workloads hold 64
 * records and uncommon ones 192 records with edge cases. Timings include PHP-side packing, the FFI call
 * and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each run asserts that the Rust defaults still clear 1.5x.
 */
class RustManticoreLogSearcherRepoReaderSearcherQueryW55ParityTest extends TestCase
{
    /**
     * Batch method per class (four words per record); each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        ManticoreLogSearcher::class => 'hitTextPlanBatch',
        LogFileRepository::class => 'relativePathPlanBatch',
        LogReader::class => 'linePlanBatch',
        LogSearcher::class => 'grepLinePlanBatch',
        SearchQuery::class => 'levelFilterPlanBatch',
    ];

    /**
     * C entry point per class.
     *
     * @var array<class-string, string>
     */
    private const FUNCTIONS = [
        ManticoreLogSearcher::class => 'nntmux_w555_manticore_hit_text_plan',
        LogFileRepository::class => 'nntmux_w555_log_file_repository_path_plan',
        LogReader::class => 'nntmux_w555_log_reader_line_plan',
        LogSearcher::class => 'nntmux_w555_log_searcher_grep_line_plan',
        SearchQuery::class => 'nntmux_w555_search_query_level_filter_plan',
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        ManticoreLogSearcher::class => true,
        LogFileRepository::class => true,
        LogReader::class => true,
        LogSearcher::class => true,
        SearchQuery::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W55_BENCH=1` runs (1000 samples per workload, seed 5552, release Rust, PHP 8.5.12 CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        ManticoreLogSearcher::class => [3.37, 3.45, 2.99, 2.68],
        LogFileRepository::class => [3.31, 3.68, 5.50, 5.09],
        LogReader::class => [3.18, 3.36, 4.25, 4.13],
        LogSearcher::class => [2.49, 2.29, 1.87, 2.02],
        SearchQuery::class => [3.15, 3.39, 4.63, 4.45],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const WORDS = 4;

    private const BYTES = ['a', 'Z', 'E', '0', '1', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', ':', '/', '\\', '[', ']', '{', '}', '#', 'T', "\xc3\xa9", "\xf0\x9f\x8e\xac", "\xc3", "\xed\xa0\x80", "\xff", "\0"];

    /** Fragments the randomized rounds glue together so headers, frames, paths, grep fields and levels actually occur. */
    private const FRAGMENTS = ['[2024-01-02 03:04:05', '[1970-01-01T00:00:00.5Z', '[2024-01-02 03:04:05.123+02:00', '[2024-01-02 03:04:05-0130', '[2024-13-40 25:61:61', '] ', ']', ' ', 'production', '.ERROR', '.Info', '.a-b', ': ', "\n#0 ", '#12 ', '[stacktrace]', "\n", "\r", '{"id":1}', '..', '/', '\\', '.log', '.gz', '.hidden', 'error', ' Warning ', 'EMERGENCY', ',', 'bogus', '12:', '3456:', ':', '1234567890123456789'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::state($class, 'reset', null);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => $method) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method): void
    {
        $reflection = new ReflectionClass($class);
        foreach (['usesW55RustFfi', 'withW55RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w55RustFfiState')->isPrivate(), "{$class} keeps its W55 state private");
        $constant = new ReflectionClassConstant($class, 'W55_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW55RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW55RustFfi($enabled, static fn () => [$class::usesW55RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW55RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW55RustFfi(false, static fn () => [$class::withW55RustFfi(true, static fn () => $class::usesW55RustFfi()), $class::usesW55RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW55RustFfi(! $class::usesW55RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW55RustFfi(), "{$class} must restore after an exception");

        $class::withW55RustFfi(! $class::usesW55RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW55RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_search_query_stays_a_readonly_class_without_static_properties(): void
    {
        $reflection = new ReflectionClass(SearchQuery::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
        $this->assertSame([], $reflection->getStaticProperties());
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            ManticoreLogSearcher::usesW47ManticoreLogSearcherRustFfi(),
            LogFileRepository::usesW48LogFileRepositoryRustFfi(),
            LogReader::usesRustFfi(),
            LogReader::usesW48LogReaderRustFfi(),
            LogSearcher::usesW47LogSearcherRustFfi(),
            SearchQuery::usesW445RustFfi(),
        ];
        $before = $snapshot();
        $this->assertSame([false, false, true, false, false, false], $before);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW55RustFfi($enabled, $snapshot), $class);
            }
        }
        $this->assertSame($before, $snapshot());
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $php = $class::{$method.'Php'}($records);
        $this->assertCount(self::WORDS * \count($records), $php);
        $this->assertSame($php, $class::withW55RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW55RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method): void
    {
        $this->assertSame([], $class::{$method.'Php'}([]));
        $this->assertSame([], $class::{$method.'Native'}([]));
        $this->assertSame([], $class::{$method}([]));
        // One empty record still yields four words.
        $this->assertSame($class::{$method.'Php'}(['']), $class::{$method.'Native'}(['']));
        $this->assertCount(self::WORDS, $class::{$method}(['']));
    }

    public function test_hit_text_plan_examples(): void
    {
        $this->requireFfi(ManticoreLogSearcher::class);
        $this->assertBoth(ManticoreLogSearcher::class, [
            "[2024-01-02 03:04:05] production.ERROR: boom {\"a\":1} \r\n[stacktrace]\n#0 /app/x.php(1): y\n#12 {main}\n#x no",
            '',
            'plain',
            "caf\u{e9} []\t\n\n",
            "#1 \n#\n# 2 \n#3x",
            "\n",
            "x]\n\n#1 y",
        ], [
            5, 54, 2, 14,
            0, 0, 0, 0,
            1, 5, 0, 0,
            3, 9, 0, 3,
            4, 3, 1, 0,
            2, 0, 0, 0,
            3, 2, 1, 2,
        ]);
    }

    public function test_relative_path_plan_examples(): void
    {
        $this->requireFfi(LogFileRepository::class);
        $this->assertBoth(LogFileRepository::class, [
            ' laravel-2024-01-02.log ',
            '\\archive\\old.log.gz',
            '//a/../b/.hidden',
            'a//b/./c.log',
            " \t/ ",
            '',
            'dir/',
            '..',
            "\x0bnested/worker.log\n",
        ], [
            22, 0, 0, 8,
            18, 1, 8, 16,
            14, 3, 7, 5,
            12, 4, 7, 10,
            0, 0, 0, 32,
            0, 0, 0, 32,
            4, 1, 4, 2,
            2, 0, 0, 5,
            17, 1, 7, 8,
        ]);
        // The normalized length matches the repository's own normalization.
        $repository = new LogFileRepository(new LogEntryParser, sys_get_temp_dir());
        foreach ([' a\\b/c.log ', '//x', "\t..\\y\n", ''] as $path) {
            $this->assertSame(\strlen($repository->normalizePhp($path)), LogFileRepository::relativePathPlanBatchNative([$path])[0], var_export($path, true));
        }
    }

    public function test_line_plan_examples(): void
    {
        $this->requireFfi(LogReader::class);
        $this->assertBoth(LogReader::class, [
            "[2024-01-02 03:04:05] production.ERROR: boom\r",
            '[2024-01-02T03:04:05.123456+02:00]  a.b-c.INFO:x',
            '[2024-01-02 03:04:05Z] app.Info: lower',
            '[2024-01-02 03:04:05+0200] .ERROR: no channel',
            '[stacktrace]',
            '#0 /app/x.php(12): y',
            "  \t\r\r",
            "\tcaf\u{e9}",
            "bad \xff\x01",
            '[2024-01-02 03:04:05.] a.B:',
            "[2024-01-02 03:04:05]\x0ba.B:",
            "x\x7f\r\ry",
            "\xed\xa0\x80",
        ], [
            1, 44, 0, 1,
            1, 48, 0, 0,
            4, 38, 0, 0,
            4, 45, 0, 0,
            3, 12, 0, 0,
            2, 20, 0, 0,
            0, 3, 3, 1,
            4, 6, 1, 2,
            4, 6, 0, 14,
            4, 27, 0, 0,
            1, 26, 0, 8,
            4, 5, 0, 8,
            4, 3, 0, 6,
        ]);
        // Kind 1 is exactly LogEntry's header pattern.
        foreach (['[2024-01-02 03:04:05] a.B:', '[2024-01-02 03:04:05+02:0] a.B:', '[2024-01-02 03:04:05] a.B-C:', '[2024-01-02 03:04:05] a.:'] as $line) {
            $this->assertSame(preg_match(LogEntry::W48_HEADER_PATTERN, $line) === 1, LogReader::linePlanBatchNative([$line])[0] === 1, $line);
        }
    }

    public function test_grep_line_plan_examples(): void
    {
        $this->requireFfi(LogSearcher::class);
        $this->assertBoth(LogSearcher::class, [
            '12:3456:[2024-01-02] x: y',
            '12:3456',
            'no colon',
            '1x:2:z',
            '::',
            '1234567890123456789:1:z',
            '999999999999999999:0:',
            ' 1:2:x',
            "1:2:\xff:3",
        ], [
            1, 12, 3456, 8,
            0, 0, 0, -1,
            0, 0, 0, -1,
            0, 0, 0, 5,
            0, 0, 0, 2,
            0, 0, 0, 22,
            1, 999999999999999999, 0, 21,
            0, 0, 0, 5,
            1, 1, 2, 4,
        ]);
    }

    public function test_level_filter_plan_examples(): void
    {
        $this->requireFfi(SearchQuery::class);
        $this->assertBoth(SearchQuery::class, [
            'error, Warning ,info',
            '',
            ',, ,',
            'ERROR,error,bogus,emergency',
            implode(',', LogEntryParser::LEVELS),
            "\tcritical\n,0,16",
            "d\u{e9}bug,ALERT",
        ], [
            26, 3, 0, 0,
            0, 0, 0, 0,
            0, 0, 0, 0,
            144, 2, 1, 1,
            255, 8, 0, 0,
            32, 1, 2, 0,
            64, 1, 1, 0,
        ]);
        // Bit n marks LogEntryParser::LEVELS[n].
        foreach (LogEntryParser::LEVELS as $index => $level) {
            $this->assertSame([1 << $index, 1, 0, 0], SearchQuery::levelFilterPlanBatchNative([strtoupper($level)]), $level);
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WORDS * \count($records), $class::{$method.'Native'}($records));
        $handle = self::state($class, 'buffer', 1);
        $this->assertIsArray($handle, "{$class} must resolve the Rust helper");
        $this->assertInstanceOf(FFI::class, $handle[0]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_large_batches_grow_the_output_buffer(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = self::firstRecords($class)[$i % 3].$i;
        }
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $handle = self::state($class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(12000, $handle[2]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method): void
    {
        self::state($class, 'reset', false);
        $records = self::firstRecords($class);
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $this->assertSame($class::{$method.'Php'}($records), $class::withW55RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertNull(self::state($class, 'buffer', 1), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_nul_bytes_decline_to_php(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $records[1] .= "\0x";
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $ffi = $this->requireFfi($class);
        $function = self::abiFunction($class);
        $data = implode("\0", self::firstRecords($class));
        $needed = 3 * self::WORDS;
        $out = $ffi->new('int64_t[16]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call(3, 0, $data, '', $out, 16), $function);
        $this->assertSame(-$needed, $call(3, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call(3, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call(3, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call(3, 1, $data, '', $out, 16), "{$function} flags");
        $this->assertSame(-1, $call(4, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(2, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(3, 0, $data, pack('P', 1), $out, 16), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi(ManticoreLogSearcher::class);
        mt_srand(5551);
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $record = '';
                for ($p = 0, $parts = mt_rand(0, 8); $p < $parts; $p++) {
                    $record .= mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(6, $withNul);
                }
                $records[] = $record;
            }
            foreach (array_keys(self::METHODS) as $class) {
                $this->assertBoth($class, $records, null);
            }
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi(ManticoreLogSearcher::class);
        mt_srand(5552);
        $strict = getenv('NNTMUX_W55_BENCH') === '1';
        $samples = $strict ? 1000 : 300;
        $workloads = [];
        $floors = [];
        $levels = ['ERROR', 'INFO', 'WARNING', 'DEBUG', 'CRITICAL', 'NOTICE'];

        foreach (['common' => 64, 'uncommon' => 192] as $name => $count) {
            $odd = $name === 'uncommon';
            $texts = $paths = $lines = $grep = $filters = [];
            for ($i = 0; $i < $count; $i++) {
                $texts[] = $odd
                    ? match ($i % 5) {
                        0 => "[2024-05-06 07:08:09] production.ERROR: boom {\"id\":{$i}} \n[stacktrace]\n".implode("\n", array_map(static fn (int $k): string => "#{$k} /app/vendor/laravel/framework/src/Illuminate/Foundation/x.php({$k}): call()", range(0, 20))),
                        1 => '',
                        2 => "caf\u{e9} \r\n#0 x",
                        3 => str_repeat('long line text ', 40),
                        default => "x]\n\n#1 y",
                    }
                : '[2024-05-06 07:08:'.\sprintf('%02d', $i % 60).'] production.'.$levels[$i % 6].': Processing release '.$i.' failed '.($i % 3 === 0 ? '{"release_id":'.$i.'}' : '');

                $paths[] = $odd
                    ? match ($i % 5) {
                        0 => ' \\archive\\laravel-2023-01-01.log.gz ',
                        1 => '',
                        2 => '../etc/passwd',
                        3 => 'a//b/./.hidden',
                        default => str_repeat('deep/', 10).'w'.$i.'.txt',
                    }
                : ['laravel', 'horizon', 'worker'][$i % 3].'-2024-05-'.\sprintf('%02d', 1 + $i % 20).'.log';

                $lines[] = $odd
                    ? match ($i % 6) {
                        0 => '[2024-05-06T07:08:09.123456+02:00] worker.INFO: job '.$i."\r",
                        1 => '#'.$i.' /app/vendor/x.php(12): y()',
                        2 => '[stacktrace]',
                        3 => '   ',
                        4 => "bad \xff\x01 bytes caf\u{e9}",
                        default => str_repeat('continuation ', 20),
                    }
                : ($i % 4 === 0 ? '#'.$i.' /app/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(123): process()' : '[2024-05-06 07:08:09] production.'.$levels[$i % 6].': Processing release '.$i);

                $grep[] = $odd
                    ? match ($i % 5) {
                        0 => 'no colon here',
                        1 => '12:x:y',
                        2 => '1234567890123456789:1:z',
                        3 => '',
                        default => (100000 + $i).':'.(987654321 + $i).':'.str_repeat('payload ', 30),
                    }
                : ($i + 1).':'.(1000 * $i).':[2024-05-06 07:08:09] production.ERROR: Processing release '.$i.' failed';

                $filters[] = $odd
                    ? match ($i % 5) {
                        0 => implode(',', LogEntryParser::LEVELS),
                        1 => '',
                        2 => ' ERROR , error,bogus,, ',
                        3 => 'Warning,'.str_repeat('x,', 10),
                        default => 'emergency',
                    }
                : ['error', 'error,warning', 'info,debug', 'critical,alert,emergency'][$i % 4];
            }

            $workloads[ManticoreLogSearcher::class][$name] = static fn (bool $rust) => $rust ? ManticoreLogSearcher::hitTextPlanBatchNative($texts) : ManticoreLogSearcher::hitTextPlanBatchPhp($texts);
            $workloads[LogFileRepository::class][$name] = static fn (bool $rust) => $rust ? LogFileRepository::relativePathPlanBatchNative($paths) : LogFileRepository::relativePathPlanBatchPhp($paths);
            $workloads[LogReader::class][$name] = static fn (bool $rust) => $rust ? LogReader::linePlanBatchNative($lines) : LogReader::linePlanBatchPhp($lines);
            $workloads[LogSearcher::class][$name] = static fn (bool $rust) => $rust ? LogSearcher::grepLinePlanBatchNative($grep) : LogSearcher::grepLinePlanBatchPhp($grep);
            $workloads[SearchQuery::class][$name] = static fn (bool $rust) => $rust ? SearchQuery::levelFilterPlanBatchNative($filters) : SearchQuery::levelFilterPlanBatchPhp($filters);
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
                        $best = PHP_INT_MAX;
                        for ($r = 0; $r < self::REPEATS; $r++) {
                            $t0 = hrtime(true);
                            $workload($rust);
                            $best = min($best, hrtime(true) - $t0);
                        }
                        $times[$rust ? 'rust' : 'php'][] = $best / 1000;
                    }
                }

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, \sprintf(
                "\n[Wave 55.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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

            $floors[$class] = min($results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']);
        }
        fwrite(STDERR, "\n");

        // Every helper is reported before the gate is asserted; Rust defaults must still clear 1.5x.
        foreach ($floors as $class => $floor) {
            $this->assertSame(self::DEFAULTS[$class], $class::usesW55RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW55RustFfi(), $class);
            if ($class::usesW55RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<string>  $records
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $records, ?array $expected): void
    {
        $method = self::METHODS[$class];
        $php = $class::{$method.'Php'}($records);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class} PHP");
        }
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native");
        $this->assertSame($php, $class::withW55RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW55RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /**
     * Three representative records per class.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private static function firstRecords(string $class): array
    {
        return match ($class) {
            ManticoreLogSearcher::class => ["[2024-01-02 03:04:05] production.ERROR: boom\n[stacktrace]\n#0 x", 'plain', ''],
            LogFileRepository::class => ['laravel-2024-01-02.log', '\\archive\\old.log.gz', ''],
            LogReader::class => ['[2024-01-02 03:04:05] production.ERROR: boom', '#0 trace', ''],
            LogSearcher::class => ['12:3456:[2024-01-02] x', 'no colon', ''],
            SearchQuery::class => ['error,warning', 'Info, bogus', ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * The C entry point of $class, called by name through the FFI handle.
     *
     * @param  class-string  $class
     */
    private static function abiFunction(string $class): string
    {
        return self::FUNCTIONS[$class];
    }

    /**
     * Invokes the class's private `w55RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w55RustFfiState'))->invoke(null, $op, $val);
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $value = '';
        for ($i = 0, $length = mt_rand(0, $maxLength); $i < $length; $i++) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $value .= $byte === "\0" && ! $withNul ? ' ' : $byte;
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

    /**
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        /** @var string $cdef */
        $cdef = (new ReflectionClassConstant($class, 'W55_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('w55_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust W55.5 FFI library is not built or available.');
        }

        return $ffi;
    }
}
