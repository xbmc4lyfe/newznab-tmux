<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\LogFileRepository;
use App\Services\LogViewer\LogReader;
use App\Services\LogViewer\LogSearcher;
use App\Services\LogViewer\SearchQuery;
use App\Services\Mcp\ProcessRunner;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`, run with
 * `RUN_RUST_FFI_BENCHMARKS=1`): per helper, 400 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, three back-to-back calls per side, every call timed individually
 * (1,200 observations per side, so p99 is a per-call tail), release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustLogViewerRepoReaderSearcherQueryMcpProcessRunnerW67ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        LogFileRepository::class => 'w67LogFileRepositoryPlanBatch',
        LogReader::class => 'w67LogReaderPlanBatch',
        LogSearcher::class => 'w67LogSearcherPlanBatch',
        SearchQuery::class => 'w67SearchQueryPlanBatch',
        ProcessRunner::class => 'w67ProcessRunnerPlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        LogFileRepository::class => ['W67_LOG_FILE_REPOSITORY_PLAN_FFI_CDEF', 'nntmux_w676_log_file_repository_plan'],
        LogReader::class => ['W67_LOG_READER_PLAN_FFI_CDEF', 'nntmux_w676_log_reader_plan'],
        LogSearcher::class => ['W67_LOG_SEARCHER_PLAN_FFI_CDEF', 'nntmux_w676_log_searcher_plan'],
        SearchQuery::class => ['W67_SEARCH_QUERY_PLAN_FFI_CDEF', 'nntmux_w676_search_query_plan'],
        ProcessRunner::class => ['W67_PROCESS_RUNNER_PLAN_FFI_CDEF', 'nntmux_w676_process_runner_plan'],
    ];

    /** Output words per record (every Wave 67.6 helper writes eight). */
    private const WIDTH = 8;

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        LogFileRepository::class => true,
        LogReader::class => true,
        LogSearcher::class => true,
        SearchQuery::class => true,
        ProcessRunner::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `RUN_RUST_FFI_BENCHMARKS=1` runs (100 samples per workload, seed 6762, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        LogFileRepository::class => [5.43, 4.25, 4.29, 3.94],
        LogReader::class => [4.83, 4.31, 4.37, 3.74],
        LogSearcher::class => [4.65, 4.34, 1.86, 1.93],
        SearchQuery::class => [8.01, 7.51, 8.29, 7.58],
        ProcessRunner::class => [5.10, 4.83, 4.43, 4.04],
    ];

    /**
     * Properties before Wave 67.6; the wave keeps its state in function-local statics.
     *
     * @var array<class-string, list<string>>
     */
    private const PROPERTIES = [
        LogFileRepository::class => ['useW48LogFileRepositoryRustFfi', 'directory', 'files', 'parser'],
        LogReader::class => ['useW48LogReaderRustFfi', 'w48Ffi', 'w48Out', 'w48OutCap', 'useRustFfi', 'ffi', 'ffiResolved', 'parser', 'chunkSize', 'maxEntryBytes', 'scanBudget'],
        LogSearcher::class => ['useRustFfi', 'useW47LogSearcherRustFfi', 'cachedFfi', 'nativeState', 'grep', 'reader'],
        SearchQuery::class => ['term', 'regex', 'caseSensitive', 'levels', 'channels', 'from', 'to'],
        ProcessRunner::class => ['useRustFfi', 'redactor'],
    ];

    /** Back-to-back calls per side of a sample; each call is recorded so p99 is an individual-call latency. */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 400;

    private const BYTES = ['a', 'Z', 'x', 'g', 'z', 'E', 'r', 'l', 'o', '0', '1', '2', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', ':', '|', '/', '\\', '"', '[', ']', ';', '$', "\xc3\xa9", "\xc3", "\xff", "\xa0", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x80\xa8", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", "\n", '/', '\\', '..', '.', '-', ' ', '"', ':', ',', '0', '1', '9', '1000', '3600', '3601', '1023', '67108865', '999999999999999', '1000000000000000', '2026-10-26', '2026-13-01', '.log', '.gz', '.1', 'level:', 'from:', 'to:', 'is:regex', 'OR', '[x] a.ERROR:', 'php', 'artisan', 'vendor/bin/', ';', '$', "\x0c", "\x01", "\xc3\xa9", "\xff"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            $class::resetW67RustFfi();
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
        foreach (['enableW67RustFfi', 'disableW67RustFfi', 'resetW67RustFfi', 'usesW67RustFfi', 'withW67RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w67RustFfiState')->isPrivate(), "{$class} keeps its W67 state private");
        $this->assertTrue((new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->isPrivate(), "{$class} keeps its W67 declaration private");
        $constant = new ReflectionClassConstant($class, 'W67_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), "{$class} routes by its default (PHP when it did not qualify)");

        $class::enableW67RustFfi();
        $this->assertTrue($class::usesW67RustFfi());
        $class::disableW67RustFfi();
        $this->assertFalse($class::usesW67RustFfi());
        $class::resetW67RustFfi();
        $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), "{$class} reset restores the default");

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW67RustFfi($enabled, static fn () => [$class::usesW67RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), "{$class} must restore after the callback");
        }
        $class::disableW67RustFfi();
        $this->assertTrue($class::withW67RustFfi(true, static fn () => $class::usesW67RustFfi()));
        $this->assertFalse($class::usesW67RustFfi(), "{$class} restores an explicit override, not the default");
        $class::resetW67RustFfi();
        $nested = $class::withW67RustFfi(false, static fn () => [$class::withW67RustFfi(true, static fn () => $class::usesW67RustFfi()), $class::usesW67RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW67RustFfi(! $class::usesW67RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), "{$class} must restore after an exception");

        $class::withW67RustFfi(! $class::usesW67RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW67RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w67_state_adds_no_properties(): void
    {
        foreach (self::PROPERTIES as $class => $expected) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w67', $property->getName(), $class);
            }
            $this->assertSame($expected, array_map(
                static fn (ReflectionProperty $property): string => $property->getName(),
                $reflection->getProperties(),
            ), "{$class} gains no properties");
        }
        $this->assertTrue((new ReflectionClass(SearchQuery::class))->isReadOnly(), 'SearchQuery stays a readonly DTO');
        $this->assertTrue((new ReflectionClass(SearchQuery::class))->isFinal(), 'SearchQuery stays final');
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            LogFileRepository::usesW48LogFileRepositoryRustFfi(),
            LogFileRepository::usesW55RustFfi(),
            LogReader::usesRustFfi(),
            LogReader::usesW48LogReaderRustFfi(),
            LogReader::usesW55RustFfi(),
            LogSearcher::usesW47LogSearcherRustFfi(),
            LogSearcher::usesW55RustFfi(),
            SearchQuery::usesW445RustFfi(),
            SearchQuery::usesW55RustFfi(),
            ProcessRunner::usesRustFfi(),
            ProcessRunner::usesW54RustFfi(),
        ];
        $before = $snapshot();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW67RustFfi($enabled, $snapshot), $class);
            }
            $class::enableW67RustFfi();
            $class::resetW67RustFfi();
            $this->assertSame($before, $snapshot(), $class);
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            LogFileRepository::class => ['__construct', 'useW48LogFileRepositoryRustFfi', 'usesW48LogFileRepositoryRustFfi', 'resetW48LogFileRepositoryRustFfi', 'inventoryPathBatch', 'inventoryPathBatchPhp', 'inventoryPathBatchNative', 'all', 'find', 'truncate', 'delete', 'looksStructuredNative', 'looksStructuredPhp', 'normalizeNative', 'normalizePhp', 'usesW55RustFfi', 'withW55RustFfi', 'relativePathPlanBatch', 'relativePathPlanBatchPhp', 'relativePathPlanBatchNative'],
            LogReader::class => ['__construct', 'latest', 'entryAt', 'entryFromHandle', 'entriesForward', 'open', 'splitBackwardChunk', 'splitBackwardChunkNative', 'capNative', 'setUseRustFfi', 'usesRustFfi', 'useW48LogReaderRustFfi', 'usesW48LogReaderRustFfi', 'resetW48LogReaderRustFfi', 'assembleRecordBatch', 'assembleRecordBatchPhp', 'assembleRecordBatchNative', 'evaluateW48Fields', 'evaluateW48Native', 'w48Rows', 'w48Strings', 'usesW55RustFfi', 'withW55RustFfi', 'linePlanBatch', 'linePlanBatchPhp', 'linePlanBatchNative'],
            LogSearcher::class => ['__construct', 'setUseRustFfi', 'useW47LogSearcherRustFfi', 'usesW47LogSearcherRustFfi', 'resetW47LogSearcherRustFfi', 'filterLineWindowBatch', 'filterLineWindowBatchPhp', 'filterLineWindowBatchNative', 'engine', 'search', 'usesW55RustFfi', 'withW55RustFfi', 'grepLinePlanBatch', 'grepLinePlanBatchPhp', 'grepLinePlanBatchNative'],
            SearchQuery::class => ['__construct', 'isValidRegex', 'hasTerm', 'pcre', 'grepArguments', 'hasTimeRange', 'acceptsEntry', 'needsPcreGrep', 'matches', 'segments', 'plainRangesNative', 'useW445RustFfi', 'usesW445RustFfi', 'resetW445RustFfi', 'summarizeLineMatchBatch', 'summarizeLineMatchBatchPhp', 'summarizeLineMatchBatchNative', 'usesW55RustFfi', 'withW55RustFfi', 'levelFilterPlanBatch', 'levelFilterPlanBatchPhp', 'levelFilterPlanBatchNative'],
            ProcessRunner::class => ['__construct', 'setUseRustFfi', 'usesRustFfi', 'run', 'transformNative', 'limitOutput', 'limitOutputPhp', 'limitOutputNative', 'buildProcessResult', 'buildProcessResultPhp', 'buildProcessResultNative', 'usesW54RustFfi', 'withW54RustFfi', 'planOutputLimitBatch', 'planOutputLimitBatchPhp', 'planOutputLimitBatchNative'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        foreach ([
            [LogFileRepository::class, 'W48_LOG_FILE_REPOSITORY_RUST_FFI_DEFAULT', false],
            [LogFileRepository::class, 'W55_RUST_FFI_DEFAULT', true],
            [LogReader::class, 'W48_LOG_READER_RUST_FFI_DEFAULT', false],
            [LogReader::class, 'W55_RUST_FFI_DEFAULT', true],
            [LogSearcher::class, 'W47_LOG_SEARCHER_RUST_FFI_DEFAULT', false],
            [LogSearcher::class, 'W55_RUST_FFI_DEFAULT', true],
            [SearchQuery::class, 'W445_RUST_FFI_DEFAULT', false],
            [SearchQuery::class, 'W55_RUST_FFI_DEFAULT', true],
            [ProcessRunner::class, 'W54_RUST_FFI_DEFAULT', true],
        ] as [$class, $constant, $value]) {
            $this->assertSame($value, (new ReflectionClassConstant($class, $constant))->getValue(), "{$class}::{$constant}");
        }

        $earlier = static fn (): array => [
            LogFileRepository::relativePathPlanBatchPhp(['logs/laravel.log', '../x.gz', '']),
            LogReader::linePlanBatchPhp(['[2026-01-01 00:00:00] local.ERROR: boom', '']),
            LogSearcher::grepLinePlanBatchPhp(['12:345:line', 'x']),
            SearchQuery::levelFilterPlanBatchPhp(['error,warning', '']),
            ProcessRunner::planOutputLimitBatchPhp(['abc', ''], [2, 10]),
            (new SearchQuery('boom', levels: ['error']))->matches('[t] local.ERROR: boom'),
        ];
        $expected = $earlier();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW67RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_log_file_repository_plan_examples(): void
    {
        $this->requireFfi(LogFileRepository::class);
        $this->assertBoth(LogFileRepository::class, [
            " /logs\\laravel-2026-10-26.log.2.gz \t2048",
            '',
            "../.env\tx",
            "worker.LOG.17\t1048576",
            "C:/logs/app.json\t67108864",
            "a//b/./2026-13-01.txt\t0",
            "nested/deeper/app-2026-02-31.log\t5",
            "line\nfeed.log\t1",
        ], [
            32, 4 | 64 | 128, 1, 20261026, 2, 1, 1, 0,
            0, 8, 0, 0, 0, 0, 0, 2,
            7, 1 | 16, 1, 0, 0, 0, 0, 1,
            13, 256, 0, 0, 17, 1, 2, 0,
            16, 4, 2, 0, 0, 3, 3, 0,
            21, 2, 4, 0, 0, 2, 0, 0,
            32, 128 | 256, 2, 20260231, 0, 1, 1, 0,
            13, 32, 0, 0, 0, 1, 1, 0,
        ]);
        // Length, depth and the `..` / empty-segment / hidden bits agree with the Wave 55.5 path plan.
        $paths = ['logs/laravel.log', '\\a\\..\\b.gz', 'x//.y', ' /deep/er/path/.z.log ', 'a/./b'];
        $earlier = LogFileRepository::relativePathPlanBatchPhp($paths);
        foreach ($paths as $i => $path) {
            $plan = LogFileRepository::w67LogFileRepositoryPlanBatchPhp(["{$path}\t1"]);
            $w55 = \array_slice($earlier, 4 * $i, 4);
            $this->assertSame([$w55[0], $w55[1], $w55[3] & 3, ($w55[3] & 4) !== 0], [$plan[0], $plan[2], $plan[1] & 3, ($plan[1] & 16) !== 0], $path);
        }
    }

    public function test_log_reader_plan_examples(): void
    {
        $this->requireFfi(LogReader::class);
        $this->assertBoth(LogReader::class, [
            "1000\t\t100\t500\tpartial\nline\n",
            "50\t80\t\t\tab",
            '',
            "65536\t\t\t\t".str_repeat("x\n", 8),
            "100\t40\t7\t\tno newline",
            "10\t\t0\t3\t\n\n",
            "x\t\t\t\tchunk\twith\ttabs",
        ], [
            1000, 500, 5, 900, 100, 908, 2, 32,
            50, 0, 1, 0, 50, 0, 0, 4 | 8 | 16,
            0, 0, 0, 0, 0, 0, 0, 2 | 16,
            65536, 0, 1, 0, 65536, 0, 8, 0,
            40, 0, 6, 33, 7, -1, 0, 8 | 16 | 64,
            10, 7, 3, 9, 1, 10, 2, 32 | 64,
            0, 0, 0, 0, 0, 0, 0, 1 | 8 | 16 | 64,
        ]);
    }

    public function test_log_searcher_plan_examples(): void
    {
        $this->requireFfi(LogSearcher::class);
        $this->assertBoth(LogSearcher::class, [
            "boom\t0\t16\t3\t[t] local.ERROR: BOOM boom",
            "boom\t1\t8\t0\t[t] local.ERROR: BOOM boom",
            "\t0\t0\t0\t[2026-01-01 00:00:00] a.b-c_d.Warning: x",
            "\t\t1\t\tno header",
            '',
            "a\t1\t\t1000\t".str_repeat('a', 250),
            "x\t0\t255\tz\t[x]local.error:",
        ], [
            26, 16, 17, 2, 14, 24, 1 | 2 | 4 | 16, 0,
            26, 16, 22, 1, 22, 26, 1 | 16, 0,
            40, 8, -1, 0, -1, -1, 1 | 2 | 4 | 16, 0,
            9, 0, -1, 0, -1, -1, 0, 0,
            0, 0, -1, 0, -1, -1, 2, 2,
            250, 0, 0, 200, 0, 250, 1 | 2 | 4 | 8, 0,
            15, 0, 1, 1, 1, 2, 1, 1,
        ]);
        // Term hits agree with SearchQuery::matches(); level-only hits agree with its header PCRE.
        foreach (['[t] local.ERROR: boom', '[t]  ch.WARNING: Boom', 'plain boom', '[t] .ERROR: x', '[t] a.ERROR :x'] as $line) {
            foreach ([['boom', true], ['BOOM', false], ['', false]] as [$term, $caseSensitive]) {
                $plan = LogSearcher::w67LogSearcherPlanBatchPhp(["{$term}\t".($caseSensitive ? 1 : 0)."\t16\t0\t{$line}"]);
                $query = new SearchQuery($term, caseSensitive: $caseSensitive, levels: ['error']);
                $expected = $query->matches($line);
                $this->assertSame($expected, $term === '' ? ($plan[6] & 4) !== 0 : $plan[2] >= 0, "{$term} / {$line}");
            }
        }
    }

    public function test_search_query_plan_examples(): void
    {
        $this->requireFfi(SearchQuery::class);
        $this->assertBoth(SearchQuery::class, [
            'timeout level:error,Warning from:2026-01-02 to:2026-01-01 -foo "a b" OR is:regex',
            '',
            "  \t\n ",
            'x "unterminated phrase',
            'LEVEL:debug,,nope FROM:2026-13-01 to:2026-1-01 IS:Case is:other - "" AND NOT or',
            str_repeat('abcdefghij ', 100),
        ], [
            8, 3, 24, 20260102, 20260101, 1 | 8 | 16 | 512, 193, 32,
            0, 0, 0, 0, 0, 256, 100, 0,
            0, 0, 0, 0, 0, 256, 100, 0,
            2, 2, 0, 0, 0, 64 | 512, 40, 4,
            10, 2, 1, 0, 0, 2 | 4 | 32 | 64, 23, 8 | 16,
            100, 100, 0, 0, 0, 128, 2000, 0,
        ]);
    }

    public function test_process_runner_plan_examples(): void
    {
        $this->requireFfi(ProcessRunner::class);
        $this->assertBoth(ProcessRunner::class, [
            "0\t\t\tphp\tartisan\ttest",
            "9000\t1\t/a/../b\tvendor/bin/pint\t\$x",
            '',
            "60\t32768\tstorage/logs\t/usr/bin/git\tstatus",
            "120\t65536\t\tbash\t-c\t\techo",
            "x\t\tl\x01\tvendor/bin/../phpstan\tanalyse\na",
            "3600\t1024\t/\tcomposer",
        ], [
            3, 14, 1, 1, 32768, 0, 4 | 32 | 128, 0,
            2, 17, 7, 3600, 1024, 1 | 2, 8 | 32 | 64, 0,
            0, 0, 0, 60, 32768, 0, 1, 2,
            2, 18, 3, 60, 32768, 8, 2, 0,
            4, 10, 0, 120, 65536, 0, 1 | 16, 0,
            2, 30, 8, 60, 32768, 8 | 4, 2 | 8, 1,
            1, 8, 2, 3600, 1024, 1, 128, 0,
        ]);
    }

    /**
     * Numeric limits: 15 digits parse, 16 digits are invalid; clamps and caps hold exactly at their edges.
     */
    public function test_boundary_records(): void
    {
        $this->requireFfi(LogReader::class);
        $max = '999999999999999';
        $this->assertBoth(LogFileRepository::class, [
            "a.log\t{$max}",
            "a.log\t1{$max}",
            "a.log.123456789\t1048575",
            "a.log.1234567890\t67108863",
            str_repeat('d/', 3000)."x.log\t",
            "9999-12-31.log.gz.gz\t\t",
            "z:\t1",
            "\xc3\xa9:/x.log\t1",
        ], null);
        $this->assertBoth(LogReader::class, [
            "{$max}\t{$max}\t{$max}\t{$max}\t".str_repeat("\n", 100),
            "{$max}\t1{$max}\t1\t1\tx",
            "0\t0\t0\t0\t",
            "1\t\t1\t0\t\n",
            "100\t100\t100\t100\t".str_repeat('y', 5000),
        ], null);
        $this->assertBoth(LogSearcher::class, [
            "a\t0\t{$max}\t{$max}\t".str_repeat('A', 201),
            "aa\t1\t256\t1001\t".str_repeat('a', 401),
            "[\t0\t0\t0\t[",
            "\t0\t128\t0\t[".str_repeat(']', 3)." \x0b\x0c\r\n.x.emergency:",
            "\t0\t0\t0\t[] x.EMERGENCYX:",
        ], null);
        $this->assertBoth(SearchQuery::class, [
            str_repeat('x', 991),
            str_repeat('x', 990),
            'is:regex '.str_repeat('ab ', 14),
            'from:0000-01-01 to:9999-12-31 from:2026-12-32',
            'level:'.str_repeat('error,', 500),
            '"a"b"c"',
        ], null);
        $this->assertBoth(ProcessRunner::class, [
            "1\t1024\t\tphp",
            "3601\t1023\t\tphp",
            "{$max}\t67108864\t\tnode",
            "1{$max}\t67108865\t\tnpm",
            "60\t32768\t..\tphpunit\t".str_repeat('-v ', 2000),
            "\t\t\tvendor/bin/\t\t",
        ], null);
    }

    /**
     * Non-ASCII bytes (valid and invalid UTF-8) never count as ASCII letters, digits, separators or whitespace.
     */
    public function test_unicode_records(): void
    {
        $this->requireFfi(LogFileRepository::class);
        $records = [
            "Caf\xc3\xa9/\xc3\x89.log\t1\t2\t3\t[\xc3\xa9] a.error: \xc3\xa9",
            "\xff\xfe\t\t",
            "\xef\xbc\xb3/\xc2\xa0x.LOG\t\xd9\xa1\t\t\t",
            "\xe2\x80\xa8level:ERROR\xc2\xa0\t\xc3\xa9\tphp\tartisan\xe2\x80\xa8",
            "\xc2\xa0\"\xc3\xa9\" -\xc3\xa9 from:\xd9\xa1\xd9\xa1\xd9\xa1\xd9\xa1-01-01",
            "\xc3\xa9\t0\t0\t2\t[x] \xc3\xa9.info: \xc3\x89\xc3\xa9\xc3\xa9",
        ];
        foreach (array_keys(self::METHODS) as $class) {
            $this->assertBoth($class, $records, null);
        }
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
        $this->assertCount(self::WIDTH * \count($records), $php);
        $this->assertSame($php, $class::withW67RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW67RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        // One empty record still yields a full row.
        $this->assertSame($class::{$method.'Php'}(['']), $class::{$method.'Native'}(['']));
        $this->assertCount(self::WIDTH, $class::{$method}(['']));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WIDTH * \count($records), $class::{$method.'Native'}($records));
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
            $records[] = self::firstRecords($class)[$i % 3].($i % 7 === 0 ? (string) $i : '');
        }
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $handle = self::state($class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(3000 * self::WIDTH, $handle[2]);
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
        $this->assertSame($class::{$method.'Php'}($records), $class::withW67RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        $records = ["\0", "\t\0\t", self::firstRecords($class)[0]."\0"];
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
        $parts = self::firstRecords($class);
        $data = implode("\0", $parts);
        $count = \count($parts);
        $needed = 3 * self::WIDTH;
        $out = $ffi->new('int64_t[64]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call($count, 0, $data, '', $out, 64), $function);
        $this->assertSame(-$needed, $call($count, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call($count, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call($count, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call($count, 1, $data, '', $out, 64), "{$function} flags");
        $this->assertSame(-1, $call($count, -1, $data, '', $out, 64), "{$function} flags");
        $this->assertSame(-1, $call($count + 1, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call($count - 1, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call($count, 0, $data, pack('P', 1), $out, 64), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    /**
     * Over 400 rounds of 1-16 records each (thousands of structured, fragment-spliced and raw-byte inputs
     * per helper), every class's Rust and PHP plans agree bit for bit; every 50th round also carries NUL
     * bytes, which must decline to PHP.
     *
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $this->requireFfi($class);
        mt_srand(6761 + crc32($class) % 1000);
        $checked = 0;
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $records[] = match (mt_rand(0, 5)) {
                    0 => self::randomBytes(24, $withNul),
                    1 => self::spliced(self::structuredRecord($class), $withNul),
                    default => self::structuredRecord($class),
                };
            }
            if ($withNul) {
                $records[0] .= "\0";
            }
            $this->assertBoth($class, $records, null);
            $checked += \count($records);
        }
        $this->assertGreaterThanOrEqual(300, $checked);
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 67.6 paired benchmark.');
        }
        $this->requireFfi(LogReader::class);
        mt_srand(6762);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $repo = $reader = $searcher = $query = $runner = [];
            for ($i = 0; $i < $count; $i++) {
                $hash = md5((string) $i);
                $edge = static fn (string $long, string $broken): string => match ($i % 5) {
                    0 => $long,
                    1 => '',
                    2 => $broken,
                    3 => str_repeat("{$hash}/", 4)."{$i}\t{$i}\t999999999999999\t1000000000000000\t{$hash}",
                    default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                };
                $day = str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT);
                $repo[] = $odd
                    ? $edge(' /'.str_repeat('nested\\deeper/./', 6)."laravel-2026-10-{$day}.LOG.123456789.gz \t 999999999999999 ", "\xff\xfe/../\x01\xc3\xa9.log\t-1\textra")
                    : ['', 'workers/', 'horizon/', 'archive/2026/'][$i % 4].['laravel', 'worker', 'scheduler', 'nntmux'][$i % 4]."-2026-10-{$day}.log".['', '.1', '.2.gz', ''][$i % 4]."\t".($i * 7919 % 9_000_000);
                $reader[] = $odd
                    ? $edge("999999999999999\t\t1\t999999999999999\t".str_repeat("[2026-10-26 12:00:00] local.ERROR: boom {$hash}\n", 6), "\xff\xfe\t-1\t\xc3\xa9\t\t\xc3\xa9\x01no newline here")
                    : ($i * 104729 % 50_000_000)."\t".($i % 3 === 0 ? '' : (string) ($i * 7919 % 40_000_000))."\t65536\t33554432\t".'partial tail '.$i."\n[2026-10-26 12:00:00] local.INFO: processed {$i} releases in group alt.binaries.test\n[2026-10-26 12:00:01] local.DEBUG: ok\n";
                $searcher[] = $odd
                    ? $edge("a\t0\t0\t1000\t".str_repeat('aA', 160), "\xff\t-1\t\xc3\xa9\t\t\xc3\xa9[".str_repeat(']', 30))
                    : ['boom', 'Releases', '', 'timeout'][$i % 4]."\t".($i % 2)."\t".[0, 16, 24, 2][$i % 4]."\t40\t[2026-10-26 12:00:".str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT).'] production.'.['ERROR', 'INFO', 'WARNING', 'DEBUG'][$i % 4].": processed {$i} releases, boom timeout in group alt.binaries.test {$hash}";
                $query[] = $odd
                    ? $edge(str_repeat('level:error,warning,notice,bogus from:2026-01-02 to:2025-12-31 is:regex -noise "unterminated ', 3), "\xff\xfe \"\xc3\xa9 \x01 is:\xc3\xa9 level:\xc3\xa9 from:2026-02-30x")
                    : ['boom', 'timeout level:error', '"failed job" from:2026-10-01 to:2026-10-26', 'releases -debug OR backfill', 'level:warning,error is:case group'][$i % 5]." {$i}";
                $runner[] = $odd
                    ? $edge("999999999999999\t1\t/tmp/../etc\tvendor/bin/../phpstan\tanalyse\t--memory-limit=2G\t\$HOME\t;\t".str_repeat('-v ', 20), "x\t-1\t\x01\t\xc3\xa9/bin\t\t\xff")
                    : ['120', '600', '', '30'][$i % 4]."\t".['32768', '', '65536', '1048576'][$i % 4]."\t".['', 'storage/logs', '', '/var/www/html'][$i % 4]."\t".['php', 'vendor/bin/pint', 'git', 'composer'][$i % 4]."\t".['artisan', '--dirty', 'status', 'validate'][$i % 4]."\t".['test', '--test', '--short', '--strict'][$i % 4]."\t--filter=Case{$i}";
            }

            $workloads[LogFileRepository::class][$name] = static fn (bool $rust) => $rust ? LogFileRepository::w67LogFileRepositoryPlanBatchNative($repo) : LogFileRepository::w67LogFileRepositoryPlanBatchPhp($repo);
            $workloads[LogReader::class][$name] = static fn (bool $rust) => $rust ? LogReader::w67LogReaderPlanBatchNative($reader) : LogReader::w67LogReaderPlanBatchPhp($reader);
            $workloads[LogSearcher::class][$name] = static fn (bool $rust) => $rust ? LogSearcher::w67LogSearcherPlanBatchNative($searcher) : LogSearcher::w67LogSearcherPlanBatchPhp($searcher);
            $workloads[SearchQuery::class][$name] = static fn (bool $rust) => $rust ? SearchQuery::w67SearchQueryPlanBatchNative($query) : SearchQuery::w67SearchQueryPlanBatchPhp($query);
            $workloads[ProcessRunner::class][$name] = static fn (bool $rust) => $rust ? ProcessRunner::w67ProcessRunnerPlanBatchNative($runner) : ProcessRunner::w67ProcessRunnerPlanBatchPhp($runner);
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
                for ($s = 0; $s < self::BENCH_SAMPLES; $s++) {
                    foreach (mt_rand(0, 1) === 1 ? [true, false] : [false, true] as $rust) {
                        for ($r = 0; $r < self::REPEATS; $r++) {
                            $t0 = hrtime(true);
                            $workload($rust);
                            $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / 1000;
                        }
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
                "\n[Wave 67.6 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW67RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW67RustFfi(), $class);
            if ($class::usesW67RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW67RustFfi(), "{$class} retains PHP by default");
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
        $this->assertCount(self::WIDTH * \count($records), $php, $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW67RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW67RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
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
            LogFileRepository::class => ["logs/laravel-2026-10-26.log.1\t2048", "../x.gz\tx\t1", ''],
            LogReader::class => ["1000\t\t100\t500\tpartial\nline\n", "50\tx", ''],
            LogSearcher::class => ["boom\t0\t16\t3\t[t] local.ERROR: BOOM boom", "x\tx", ''],
            SearchQuery::class => ['boom level:error from:2026-01-02 -debug "a b" OR is:regex', 'x "y', ''],
            ProcessRunner::class => ["120\t32768\t\tphp\tartisan\ttest", "x\t1\t/..\tbash", ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * One record shaped like the class's input, built from plausible and adversarial pieces.
     *
     * @param  class-string  $class
     */
    private static function structuredRecord(string $class): string
    {
        $pick = static fn (array $items): string => (string) $items[mt_rand(0, \count($items) - 1)];
        $join = static function (string $separator, int $max, callable $part): string {
            $parts = [];
            for ($i = 0, $n = mt_rand(0, $max); $i < $n; $i++) {
                $parts[] = $part();
            }

            return implode($separator, $parts);
        };
        $number = static fn (): string => $pick(['', '', ' ', '0', '1', '2', '3', '7', '9', ' 5', '5 ', '00', '007', '-1', '+1', '1.5', 'x', '10', '40', '100', '500', '1000', '1023', '1024', '3600', '3601', '65536', '1048575', '1048576', '33554432', '67108863', '67108864', '67108865', (string) mt_rand(0, 100000), '999999999999999', '1000000000000000', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $size): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $size + 1) : $size);
        $text = static fn (array $words, string $separators): string => $join($pick(str_split($separators)), 8, static fn (): string => mt_rand(0, 9) === 0 ? self::randomBytes(4) : $pick($words));

        return match ($class) {
            LogFileRepository::class => implode("\t", $cut([
                $pick(['', ' ', '/', '\\', '//', 'C:', 'c:\\', "\xc3\xa9:", '. ']).$text(['logs', 'laravel', 'laravel-2026-10-26.log', 'worker.log.1', 'a.LOG.GZ', 'x.log.12.gz', '.hidden', '..', '.', '', 'x.json', 'y.txt.3.gz', '2026-13-40.log', '12345-01-02.log', '2026-02-31', 'run.1234567890', 'a.log.', "caf\xc3\xa9.log", 'GZ', '.gz'], '/\\/'),
                $number(),
                'extra',
            ], 2)),
            LogReader::class => implode("\t", $cut([
                $number(),
                $number(),
                $number(),
                $number(),
                $text(["\n", 'line', "\r\n", "\t", 'partial', '[2026-01-01 00:00:00] local.ERROR: x', "\n\n", ''], " \n\t"),
            ], 5)),
            LogSearcher::class => implode("\t", $cut([
                $pick(['', '', 'boom', 'BOOM', 'a', 'aa', 'error', 'ERROR:', "\xc3\xa9", ' ', '[', 'x.error', self::randomBytes(3)]),
                $pick(['', '0', '1', '2', 'x']),
                $pick(['', '0', '1', '8', '16', '24', '255', '256', '-1']),
                $number(),
                $pick(['', '[t] local.ERROR: ', '[2026-01-01] a.b-c.warning: ', "[x]\t\x0b ch.Info: ", '[x] .error: ', '[x]local.debug: ', '[x] a.debugx: ', '[] x.EMERGENCY:', '[x] a.alert :', '[[x]] a.critical:', '']).$text(['boom', 'BOOM', 'Boom', 'a', 'aa', 'aaa', 'error', 'x', "\xc3\xa9", '[', ']', 'timeout'], ' .:'),
            ], 5)),
            SearchQuery::class => $text(['boom', 'x', 'OR', 'AND', 'NOT', 'or', '-', '-noise', '"', '"a b"', '""', 'level:error', 'LEVEL:Warning,info', 'level:', 'level:,bogus', 'from:2026-01-02', 'to:2026-01-01', 'FROM:2026-13-01', 'to:2026-1-1', 'from:', 'is:regex', 'IS:CASE', 'is:x', str_repeat('q', mt_rand(1, 400)), "\xc3\xa9"], " \t\n\x0b\x0c\r"),
            ProcessRunner::class => implode("\t", $cut([
                $number(),
                $number(),
                $pick(['', '', '/', '/var/www', 'storage/logs', '..', 'a/../b', "l\x01", "\x7f", 'a/..b', '../']),
                $pick(['php', 'composer', 'git', 'npm', 'node', 'phpunit', 'pint', 'phpstan', 'bash', '', 'PHP', 'vendor/bin/pint', 'vendor/bin/../pint', '/usr/bin/php', 'vendor/bin/', 'vendor/binphp', './php']),
                $pick(['artisan', 'test', '', '--filter=X', '$HOME', 'a;b', 'x|y', '`id`', "a\nb", '<in', 'out>', 'a&b', "\xc3\xa9"]),
                $pick(['', 'status', '-v', 'x']),
            ], 6)),
            default => throw new RuntimeException($class),
        };
    }

    /** Splices random fragments and bytes into $record at random offsets. */
    private static function spliced(string $record, bool $withNul): string
    {
        for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
            $insert = mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(4, $withNul);
            $at = mt_rand(0, \strlen($record));
            $record = substr($record, 0, $at).$insert.substr($record, $at);
        }

        return $record;
    }

    /**
     * The C entry point of $class, called by name through the FFI handle.
     *
     * @param  class-string  $class
     */
    private static function abiFunction(string $class): string
    {
        return self::FUNCTIONS[$class][1];
    }

    /**
     * Invokes the class's private `w67RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w67RustFfiState'))->invoke(null, $op, $val);
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
     * The class's resolved FFI handle; skips when the extension or library is unavailable.
     *
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI extension not loaded.');
        }
        $cdef = (new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->getValue();
        $this->assertIsString($cdef);
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('w67_6', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library with the Wave 67.6 helpers is unavailable.');
        }

        return $ffi;
    }
}
