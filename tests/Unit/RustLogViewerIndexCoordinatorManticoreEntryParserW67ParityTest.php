<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\Index\LogIndex;
use App\Services\LogViewer\Index\LogIndexQuery;
use App\Services\LogViewer\Index\LogSearchCoordinator;
use App\Services\LogViewer\Index\ManticoreLogSearcher;
use App\Services\LogViewer\LogEntry;
use App\Services\LogViewer\LogEntryParser;
use App\Services\LogViewer\SearchQuery;
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
 * `RUN_RUST_FFI_BENCHMARKS=1`): per helper, 100 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustLogViewerIndexCoordinatorManticoreEntryParserW67ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        LogIndex::class => 'w67LogIndexPlanBatch',
        LogSearchCoordinator::class => 'w67LogSearchCoordinatorPlanBatch',
        ManticoreLogSearcher::class => 'w67ManticoreLogSearcherPlanBatch',
        LogEntry::class => 'w67LogEntryPlanBatch',
        LogEntryParser::class => 'w67LogEntryParserPlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        LogIndex::class => ['W67_LOG_INDEX_PLAN_FFI_CDEF', 'nntmux_w675_log_index_plan'],
        LogSearchCoordinator::class => ['W67_LOG_SEARCH_COORDINATOR_PLAN_FFI_CDEF', 'nntmux_w675_log_search_coordinator_plan'],
        ManticoreLogSearcher::class => ['W67_MANTICORE_LOG_SEARCHER_PLAN_FFI_CDEF', 'nntmux_w675_manticore_log_searcher_plan'],
        LogEntry::class => ['W67_LOG_ENTRY_PLAN_FFI_CDEF', 'nntmux_w675_log_entry_plan'],
        LogEntryParser::class => ['W67_LOG_ENTRY_PARSER_PLAN_FFI_CDEF', 'nntmux_w675_log_entry_parser_plan'],
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        LogIndex::class => 8,
        LogSearchCoordinator::class => 8,
        ManticoreLogSearcher::class => 8,
        LogEntry::class => 8,
        LogEntryParser::class => 9,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        LogIndex::class => true,
        LogSearchCoordinator::class => true,
        ManticoreLogSearcher::class => true,
        LogEntry::class => true,
        LogEntryParser::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of four
     * `RUN_RUST_FFI_BENCHMARKS=1` runs (100 samples per workload, seed 6752, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        LogIndex::class => [7.35, 5.97, 6.81, 5.68],
        LogSearchCoordinator::class => [6.85, 5.98, 7.33, 5.35],
        ManticoreLogSearcher::class => [5.85, 4.41, 5.36, 3.64],
        LogEntry::class => [8.48, 5.25, 3.86, 3.20],
        LogEntryParser::class => [5.99, 4.53, 5.97, 4.10],
    ];

    /**
     * Properties before Wave 67.5; the wave keeps its state in function-local statics.
     *
     * @var array<class-string, list<string>>
     */
    private const PROPERTIES = [
        LogIndex::class => ['useRustFfi', 'ffi', 'ffiResolved', 'useW47LogIndexRustFfi', 'w47Ffi', 'w47Out', 'w47OutCap', 'client', 'table', 'enabled'],
        LogSearchCoordinator::class => ['useW47LogSearchCoordinatorRustFfi', 'grep', 'manticore', 'indexer', 'index'],
        ManticoreLogSearcher::class => ['useW47ManticoreLogSearcherRustFfi', 'index', 'reader', 'parser'],
        LogEntry::class => ['offset', 'end', 'lineNumber', 'timestamp', 'channel', 'level', 'message', 'body', 'truncated'],
        LogEntryParser::class => ['ffi', 'useW445RustFfi', 'buf', 'cap', 'metaBuf', 'metaCap', 'useNative'],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 100;

    private const BYTES = ['a', 'Z', 'x', 'E', '0', '1', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', ':', '|', '/', '[', ']', '{', '}', '#', '+', "\xc3\xa9", "\xc3", "\xff", "\xa0", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x80\xa8", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", "\n", "\r\n", ',', ',,', ' ', ' {', '}', '0', '1', '9', '50', '500', '501', '8388608', '1099511627776', '999999999999999', '1000000000000000', '[2026-10-06 18:11:29] local.ERROR: ', '[2026-10-06T18:11:29.5Z] a.b.INFO:', '2026-10-06 18:11:29', '+05:30', 'Z', '.123', '#0 ', '[stacktrace]', 'Stack trace:', 'error', 'ERROR', 'local', 'boom', "\x0c", "\x01", "\xc3\xa9", "\xff"];

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
        $this->assertTrue((new ReflectionClass(LogEntry::class))->isReadOnly(), 'LogEntry stays a readonly DTO');
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
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            LogIndex::usesRustFfi(),
            LogIndex::usesW47LogIndexRustFfi(),
            LogIndex::usesW54RustFfi(),
            LogSearchCoordinator::usesW47LogSearchCoordinatorRustFfi(),
            LogSearchCoordinator::usesW54RustFfi(),
            ManticoreLogSearcher::usesW47ManticoreLogSearcherRustFfi(),
            ManticoreLogSearcher::usesW55RustFfi(),
            LogEntry::usesW48LogEntryRustFfi(),
            LogEntryParser::usesW445RustFfi(),
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
            LogIndex::class => ['__construct', 'definition', 'documentId', 'documentIdNative', 'isSafeTableName', 'isSafeTableNameNative', 'setUseRustFfi', 'usesRustFfi', 'useW47LogIndexRustFfi', 'usesW47LogIndexRustFfi', 'resetW47LogIndexRustFfi', 'indexEntryRangeBatch', 'indexEntryRangeBatchPhp', 'indexEntryRangeBatchNative', 'evaluateW47Fields', 'evaluateW47Native', 'w47Rows', 'w47Joined', 'isEnabled', 'isAvailable', 'ensureTable', 'drop', 'count', 'replace', 'purgeFile', 'search', 'facet', 'usesW54RustFfi', 'withW54RustFfi', 'headerPlanBatch', 'headerPlanBatchPhp', 'headerPlanBatchNative'],
            LogSearchCoordinator::class => ['__construct', 'engine', 'search', 'combinedEngine', 'combinedEnginePhp', 'combinedEngineNative', 'useW47LogSearchCoordinatorRustFfi', 'usesW47LogSearchCoordinatorRustFfi', 'resetW47LogSearchCoordinatorRustFfi', 'routeFileBatch', 'routeFileBatchPhp', 'routeFileBatchNative', 'facets', 'fileStatus', 'usesW54RustFfi', 'withW54RustFfi', 'pathPlanBatch', 'pathPlanBatchPhp', 'pathPlanBatchNative'],
            ManticoreLogSearcher::class => ['__construct', 'searchFile', 'facets', 'hitPosition', 'hitPositionPhp', 'hitPositionNative', 'useW47ManticoreLogSearcherRustFfi', 'usesW47ManticoreLogSearcherRustFfi', 'resetW47ManticoreLogSearcherRustFfi', 'scoreHitBatch', 'scoreHitBatchPhp', 'scoreHitBatchNative', 'usesW55RustFfi', 'withW55RustFfi', 'hitTextPlanBatch', 'hitTextPlanBatchPhp', 'hitTextPlanBatchNative'],
            LogEntry::class => ['__construct', 'loggedAt', 'loggedAtPhp', 'loggedAtNative', 'useW48LogEntryRustFfi', 'usesW48LogEntryRustFfi', 'resetW48LogEntryRustFfi', 'classifyEntryBatch', 'classifyEntryBatchPhp', 'classifyEntryBatchNative', 'w48Hash64', 'w48Hash32', 'toArray'],
            LogEntryParser::class => ['__construct', 'isHeader', 'isHeaderPhp', 'parseHeader', 'parseHeaderPhp', 'plainTimestamp', 'plainTimestampPhp', 'useW445RustFfi', 'usesW445RustFfi', 'resetW445RustFfi', 'summarizeLogLineBatch', 'summarizeLogLineBatchPhp', 'summarizeLogLineBatchNative', 'parseEntriesBlock', 'parseEntriesBlockPhp', 'make', 'makePhp'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        foreach ([
            [LogIndex::class, 'OFFSET_BITS', 40],
            [LogIndex::class, 'W47_LOG_INDEX_RUST_FFI_DEFAULT', false],
            [LogIndex::class, 'W54_RUST_FFI_DEFAULT', true],
            [LogSearchCoordinator::class, 'W47_LOG_SEARCH_COORDINATOR_RUST_FFI_DEFAULT', false],
            [LogSearchCoordinator::class, 'W54_RUST_FFI_DEFAULT', true],
            [ManticoreLogSearcher::class, 'W47_MANTICORE_LOG_SEARCHER_RUST_FFI_DEFAULT', false],
            [ManticoreLogSearcher::class, 'W55_RUST_FFI_DEFAULT', true],
            [LogEntry::class, 'W48_LOG_ENTRY_RUST_FFI_DEFAULT', true],
            [LogEntryParser::class, 'W445_RUST_FFI_DEFAULT', true],
            [LogEntryParser::class, 'LEVELS', ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']],
        ] as [$class, $constant, $value]) {
            $this->assertSame($value, (new ReflectionClassConstant($class, $constant))->getValue(), "{$class}::{$constant}");
        }

        $earlier = static fn (): array => [
            LogIndex::documentId(3, 1234),
            LogIndex::headerPlanBatchPhp(['[2026-10-06 18:11:29] local.ERROR: boom', 'x']),
            LogSearchCoordinator::pathPlanBatchPhp(['/var/log/laravel-2026-10-06.log', 'x']),
            LogSearchCoordinator::routeFileBatchPhp([['a.log', 100, 90, 1], ['a.log', 5, -1, 0]], true, 20, null),
            ManticoreLogSearcher::hitTextPlanBatchPhp(["boom {\"a\":1}\n#0 x", '']),
            ManticoreLogSearcher::scoreHitBatchPhp([[1, 2, 3, 'error', 'local', 'boom', 'Boom']], 'boom', false, ['error'], []),
            LogEntry::classifyEntryBatchPhp(["[2026-10-06 18:11:29] local.ERROR: boom {\"a\":1}\n#0 x"]),
            (new LogEntryParser(false))->parseHeaderPhp('[2026-10-06 18:11:29] local.ERROR: boom'),
            \count((new LogEntryParser(false))->parseEntriesBlockPhp("[2026-10-06 18:11:29] local.ERROR: a\n#0 x\n[2026-10-06 18:11:30] local.INFO: b")),
        ];
        $expected = $earlier();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW67RustFfi($enabled, $earlier), $class);
            }
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
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php);
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
        $this->assertCount(self::WIDTHS[$class], $class::{$method}(['']));
    }

    public function test_log_index_plan_examples(): void
    {
        $this->requireFfi(LogIndex::class);
        $this->assertBoth(LogIndex::class, [
            "7\t20000000\t42\t42\t1000\t1000\t8388608",
            '',
            "3\t500\t9\t0\t0\t0\t",
            "3\t100\t9\t8\t100\t50\t10",
            "3\t40\t9\t9\t100\t50\t10",
            "8388608\t1099511627776\t1\t1\t0\t0\t1",
            "x\t10\t1\t1\t5\t5\t0\textra",
        ], [
            1, 1000, 19999000, 3, 3221784, 7696581395432, 7696601394432, 0,
            3, 0, 0, 0, 0, 0, 0, 2,
            3, 0, 500, 1, 500, 3298534883328, 3298534883828, 0,
            2, 0, 100, 10, 10, 3298534883328, 3298534883428, 4,
            2, 0, 40, 4, 10, 3298534883328, 3298534883368, 8,
            1, 0, 1099511627776, 1099511627776, 1, -1, -1, 48,
            1, 5, 5, 5, 1, 5, 10, 3,
        ]);
        // Document ids are documentId() of the resume offset and the file size.
        foreach ([[1, 4096, 0], [42, 77, 70], [8388607, 1099511627775, 5]] as [$fileId, $size, $offset]) {
            $plan = LogIndex::w67LogIndexPlanBatchPhp(["{$fileId}\t{$size}\t1\t1\t{$offset}\t{$offset}\t"]);
            $this->assertSame([LogIndex::documentId($fileId, $offset), LogIndex::documentId($fileId, $size)], [$plan[5], $plan[6]], "{$fileId}/{$size}");
        }
    }

    public function test_log_search_coordinator_plan_examples(): void
    {
        $this->requireFfi(LogSearchCoordinator::class);
        $this->assertBoth(LogSearchCoordinator::class, [
            "Jobs\\ReindexRel\t0\terror,warning\tlocal,horizon\t1\t1\t\t\t\t",
            '',
            "a.*b\t1\t\t\t1\t1\t100\t20\t\t",
            "!!!\t0\t\t\t1\t1\t\t0\t\t",
            "boom\t0\terror, nope\tbad chan,local,local\t1\t0\t5\t501\t10\t5",
            "x\tA\t\t\t1\t1\t\t\t1\t9\textra",
        ], [
            1, 2, 24, 2, 50, -1, -1, 0,
            2, 0, 0, 0, 50, -1, -1, 2,
            3, 2, 0, 0, 20, 100, -1, 128,
            2, 0, 0, 0, 1, -1, -1, 80,
            0, 1, 16, 2, 500, 5, 0, 60,
            2, 1, 0, 0, 50, -1, 9, 67,
        ]);
        // Plain ASCII terms route to Manticore exactly when LogIndexQuery has a match expression.
        foreach (['Jobs\\ReindexRel', 'ab', 'abc', 'a b', 'x yz w', '--', 'foo-ba', '_', 'ReindexRelease'] as $term) {
            $plan = LogSearchCoordinator::w67LogSearchCoordinatorPlanBatchPhp(["{$term}\t0\t\t\t1\t1\t\t\t\t"]);
            $this->assertSame(LogIndexQuery::supports(new SearchQuery($term)), $plan[0] === 1, $term);
        }
        // Level bits follow LogEntryParser::LEVELS.
        foreach (LogEntryParser::LEVELS as $index => $level) {
            $this->assertSame(1 << $index, LogSearchCoordinator::w67LogSearchCoordinatorPlanBatchPhp(["\t\t {$level} "])[2], $level);
        }
    }

    public function test_manticore_log_searcher_plan_examples(): void
    {
        $this->requireFfi(ManticoreLogSearcher::class);
        $this->assertBoth(ManticoreLogSearcher::class, [
            "Jobs\\ReindexRel\t0\terror\tlocal\t\t\t\t500\t1700000000\terror\tlocal\tApp\\Jobs\\ReindexRelease failed; jobs\\reindexrel",
            '',
            "boom\t1\t\t\t100\t200\t400\t300\t150\tinfo\tlocal\tBoom boom BOOM\tboom",
            "ab\t0\terror,\t\t100\t\t\t10\t\terror\tx\tab",
            "!!\t0\t\t\t\t\t\t5\t\t\t\tx",
            "a b c\t0\t\t\t\t\t10\t20\t\t\t\tA B C",
        ], [
            2, crc32('"*jobs reindexrel*"'), 1, 1, 2, 500, 1, 0,
            0, 0, 1, 1, 0, 0, 1, 2,
            1, crc32('*boom*'), 1, 1, 2, 300, 1, 0,
            0, 0, 0, 1, 1, -1, 1, 4,
            0, 0, 1, 0, 0, -1, 1, 4,
            1, crc32('b'), 1, 1, 1, -1, 0, 0,
        ]);
        // ASCII terms agree with LogIndexQuery::matchExpression() and SearchQuery::matches().
        foreach (['Jobs\\ReindexRel', 'ab', 'abc def', 'a b c', 'x.yz', 'FooBar_9', '--x--', 'ReindexRelease'] as $term) {
            foreach ([false, true] as $caseSensitive) {
                $text = 'App\\Jobs\\ReindexRelease failed: foobar_9 x.YZ abc def ReindexRelease';
                $plan = ManticoreLogSearcher::w67ManticoreLogSearcherPlanBatchPhp([$term."\t".($caseSensitive ? '1' : '0')."\t\t\t\t\t\t0\t\t\t\t".$text]);
                $expression = LogIndexQuery::matchExpression($term);
                $this->assertSame($expression === null ? 0 : crc32($expression), $plan[1], $term);
                $this->assertSame((new SearchQuery($term, caseSensitive: $caseSensitive))->matches($text), $plan[3] === 1, $term);
            }
        }
    }

    public function test_log_entry_plan_examples(): void
    {
        $this->requireFfi(LogEntry::class);
        $this->assertBoth(LogEntry::class, [
            "2026-10-06T18:11:29+02:00\terror\tlocal\tboom {\"a\":1}\t#0 x\n#1 y",
            '',
            "2026-10-06 18:11:29.123Z\tWARNING\tqueue\tslow  \tStack trace:\n#0 a\tb",
            "2026-02-31 00:00:00\tnope\t\tcaf\xc3\xa9 {x}",
            "1969-12-31 23:59:59\t\t\t\t",
            "2026-10-06 18:11:29+24:00\tinfo",
        ], [
            5, 1791303089, 12, 7, 2, 2, LogEntry::w48Hash64('error|local|boom'), 0,
            0, -1, 0, 0, 0, 0, LogEntry::w48Hash64('||'), 1,
            4, 1791310289, 6, 0, 1, 2, LogEntry::w48Hash64('WARNING|queue|slow'), 8,
            0, 1772496000, 9, 3, 0, 0, LogEntry::w48Hash64("nope||caf\xc3\xa9"), 19,
            0, -1, 0, 0, 0, 0, LogEntry::w48Hash64('||'), 4,
            2, -1, 0, 0, 0, 0, LogEntry::w48Hash64('info||'), 5,
        ]);
        // Zone-qualified epochs agree with strtotime() and severities with W48_SEVERITY.
        foreach (['2026-10-06T18:11:29+02:00', '2026-10-06 18:11:29Z', '1970-01-01 00:00:00Z', '2000-02-29T23:59:59.999-0530', '2038-01-19 03:14:08+00:00', '9999-12-31 23:59:59Z'] as $timestamp) {
            $this->assertSame(strtotime($timestamp), LogEntry::w67LogEntryPlanBatchPhp(["{$timestamp}\t\t\t\t"])[1], $timestamp);
        }
        foreach (LogEntry::W48_SEVERITY as $level => $rank) {
            $this->assertSame($rank, LogEntry::w67LogEntryPlanBatchPhp(["\t".strtolower($level)."\t\t\t"])[0], $level);
        }
    }

    public function test_log_entry_parser_plan_examples(): void
    {
        $this->requireFfi(LogEntryParser::class);
        $this->assertBoth(LogEntryParser::class, [
            "junk\n[2026-10-06 18:11:29] local.ERROR: boom {\"a\":1}\n#0 x\n[stacktrace]\n[2026-10-06T18:11:29.1Z] a.b.NOPE: y\n",
            '',
            "[2026-10-06 18:11:29] production.INFO: ok\r\n[2026-10-06 18:11:30] production.DEBUG: caf\xc3\xa9\r\n",
            "\n\n",
            "[2026-10-06 18:11:29]local.ERROR: x\n[2026-10-06 18:11:29] .ERROR: x\n[2026-10-06 18:11:29] a.Error: x\n[2026-10-06 18:11:29+0530] a-b_c.EMERGENCY:\n  {\"ctx\":[]}",
        ], [
            5, 3, 2, 2, 3, 1, 7, 272, 28,
            0, 0, 0, 0, 0, 0, 0, 0, 0,
            2, 2, 2, 0, 1, 0, 0, 3, 3,
            2, 1, 0, 0, 2, 0, 0, 0, 4,
            5, 2, 1, 1, 3, 0, 0, 128, 20,
        ]);
        // Entry and header counts agree with parseEntriesBlockPhp() on random structured blocks.
        mt_srand(6750);
        $parser = new LogEntryParser(false);
        for ($i = 0; $i < 200; $i++) {
            $block = self::structuredRecord(LogEntryParser::class);
            $plan = LogEntryParser::w67LogEntryParserPlanBatchPhp([$block]);
            $entries = $parser->parseEntriesBlockPhp($block, true, 0, PHP_INT_MAX);
            $this->assertSame([\count($entries), \count(array_filter($entries, static fn (LogEntry $entry): bool => $entry->channel !== null))], [$plan[1], $plan[2]], var_export($block, true));
        }
    }

    /**
     * Numeric limits: 15 digits parse, 16 digits are invalid; offsets and ids hold exactly at their bit limits.
     */
    public function test_boundary_records(): void
    {
        $this->requireFfi(LogIndex::class);
        $max = '999999999999999';
        $this->assertBoth(LogIndex::class, [
            "8388607\t1099511627775\t1\t1\t0\t1099511627775\t1",
            "8388608\t1099511627776\t1\t1\t0\t1099511627776\t",
            "1{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}",
            "1\t{$max}\t2\t1\t0\t0\t1",
            "1\t8388609\t1\t1\t1\t1\t8388608",
            "1\t0\t0\t0\t0\t0\t0",
        ], null);
        $this->assertBoth(LogSearchCoordinator::class, [
            "x\t{$max}\t\t\t1\t1\t{$max}\t1\t0\t0",
            "abc\t0\t".str_repeat('error,', 500)."\t".implode(',', range(1, 500))."\t1\t1\t\t500\t{$max}\t{$max}",
            "abc\t0\t\t\t1\t1\t\t1{$max}\t1\t0",
            str_repeat('ab ', 3000)."\t\t\t\t1\t1",
        ], null);
        $this->assertBoth(ManticoreLogSearcher::class, [
            "a\t0\t\t\t\t\t\t0\t\t\t\t".str_repeat('a', 5000),
            "ab\t1\t\t\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t\t\t".str_repeat('ab', 300),
            "abc\t0\t\t\t0\t0\t1\t0\t0\t\t\tABC",
            str_repeat('Word.', 1000)."\t0\t".str_repeat(',', 100)."\t\t\t\t\t\t\t\t\tword.",
        ], null);
        $this->assertBoth(LogEntry::class, [
            "1970-01-01 00:00:00\tdebug\t\t\t",
            "1970-01-01T00:59:59+01:00\tEMERGENCY\t\t\t",
            '9999-12-31 23:59:59.'.str_repeat('9', 100)."-23:59\t\t\t\t",
            "2026-10-06 18:11:29+0060\t\t\t\t",
            "\t\t\t".str_repeat('x {', 1000).'}'."\t".str_repeat("#1\n", 1000),
        ], null);
        $this->assertBoth(LogEntryParser::class, [
            str_repeat("[2026-10-06 18:11:29] local.ERROR: x {\"a\":1}\n", 500),
            str_repeat("#0 frame\n", 1000),
            '[2026-10-06 18:11:29] '.str_repeat('a.', 2000).'ERROR: x',
            "[2026-10-06 18:11:29.\n[2026-10-06 18:11:29+05:3] a.ERROR:\n[2026-10-06 18:11:29+05:30] a.ERROR:",
        ], null);
    }

    /**
     * Non-ASCII bytes (valid and invalid UTF-8) count as word bytes for terms and never as digits, spaces or levels.
     */
    public function test_unicode_records(): void
    {
        $this->requireFfi(LogIndex::class);
        $this->assertBoth(LogIndex::class, ["\xd9\xa1\t\xef\xbc\x91\t1\t1\t0\t0\t", "\xff\t\t\t\t\t\t"], null);
        $this->assertBoth(LogSearchCoordinator::class, ["caf\xc3\xa9\t0\t\xc3\xa9rror\tl\xc3\xb6cal,local\t1\t1", "\xc3\xa9\t0\t\t\t1\t1", "\xff\xfe!\t\t\t\t1\t1"], null);
        $this->assertBoth(ManticoreLogSearcher::class, ["CAF\xc3\x89\t0\t\t\t\t\t\t1\t\t\t\tcaf\xc3\xa9 CAF\xc3\x89 Caf\xc3\x89", "\xc3\xa9\xc3\xa9\xc3\xa9\t1\t\xc3\xa9\t\t\t\t\t2\t\t\xc3\xa9\t\t\xc3\xa9\xc3\xa9\xc3\xa9"], null);
        $this->assertBoth(LogEntry::class, ["2026-10-06\xc2\xa018:11:29\t\xc3\x89RROR\tl\xc3\xb6cal\tm\xc3\xa9ssage {\xc3\xa9}\t#\xd9\xa1"], null);
        $this->assertBoth(LogEntryParser::class, ["[2026-10-06 18:11:29]\xc2\xa0local.ERROR: x\n[2026-10-06 18:11:29] l\xc3\xb6cal.ERROR: x\n[2026-10-06 18:11:29] local.\xc3\x89RROR: x\n[\xd9\xa1026-10-06 18:11:29] a.ERROR: x"], null);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $class::{$method.'Native'}($records));
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
        $this->assertSame(3000 * self::WIDTHS[$class], $handle[2]);
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
        $needed = 3 * self::WIDTHS[$class];
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
        mt_srand(6751 + crc32($class) % 1000);
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
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 67.5 paired benchmark.');
        }
        $this->requireFfi(LogIndex::class);
        mt_srand(6752);
        $workloads = [];
        $floors = [];
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $index = $coordinator = $manticore = $entry = $parser = [];
            for ($i = 0; $i < $count; $i++) {
                $size = 1000 + $i * 7919;
                $index[] = $odd
                    ? match ($i % 5) {
                        0 => " {$i} \t 999999999999999 \t{$i}\t".($i + 1)."\t1099511627776\t1099511627776\t1",
                        1 => '',
                        2 => "\xff{$i}\t-1\t\xc3\xa9\t\t\t\t\textra",
                        3 => "8388608\t{$size}\t9\t9\t".($size + 1)."\t".($size + 1)."\t0",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ($i + 1)."\t{$size}\t".(100 + $i % 3)."\t".[0, 100 + $i % 3, 99][$i % 3]."\t".($size - $i)."\t".($size - 3 * $i)."\t".[8388608, 65536, 4096, ''][$i % 4];

                $coordinator[] = $odd
                    ? match ($i % 5) {
                        0 => ' '.str_repeat('Jobs\\Reindex ', 6)."\t 0 \t".implode(',', $levels).", nope, \t".str_repeat('local,horizon,bad chan,', 4)."\t1\t1\t999999999999999\t501\t20\t10",
                        1 => '',
                        2 => "\xff\xfe(?i)boom\xc3\xa9\t1\t\xc3\xa9rror\t\t1\t0\t-1\t\t\t\textra",
                        3 => "ab\t0\t\t\t1\t1\t\t0\t1\t2",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['Jobs\\ReindexRel', 'boom', 'Connection refused', '', 'timeout after'][$i % 5]."\t".($i % 7 === 0 ? '1' : '0')."\t".$levels[$i % 8].','.$levels[($i + 3) % 8]."\t".['local', 'horizon,local', '', 'queue'][$i % 4]."\t1\t".($i % 3 === 0 ? '0' : '1')."\t".($i * 977)."\t".[50, 100, 25][$i % 3]."\t".($i % 2 === 0 ? '' : '1700000000')."\t".($i % 2 === 0 ? '' : '1800000000');

                $text = "[2026-10-06 18:11:29] local.ERROR: Job App\\Jobs\\ReindexRelease {$i} failed boom {\"id\":{$i}}\n#0 /app/vendor/x.php(12): boom\n#1 {main}";
                $manticore[] = $odd
                    ? match ($i % 5) {
                        0 => ' '.str_repeat('ab ', 8)."\t1\t".str_repeat(',', 10)."error\t".str_repeat('local,', 10)."\t\t\t999999999999999\t{$i}\t\terror\tlocal\t".str_repeat("ab\tAB ", 60),
                        1 => '',
                        2 => "\xff\xfeBOOM\xc3\xa9\t\xc3\xa9\t\t\t-1\t\t\t\t\t\t\t".str_repeat("boom\xc3\xa9 ", 30),
                        3 => "a\t0\t\t\t\t\t\t{$i}\t\t\t\t".str_repeat('a', 400),
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['boom', 'ReindexRelease', 'Jobs\\Reindex', '', 'main'][$i % 5]."\t".($i % 3 === 0 ? '1' : '0')."\t".$levels[$i % 8].',error'."\t".['local', '', 'horizon,local'][$i % 3]."\t".($i % 2 === 0 ? '' : '1700000000')."\t\t".(100000 - $i)."\t".($i * 311)."\t".(1700000000 + $i)."\t".['error', 'info', 'warning'][$i % 3]."\tlocal\t".$text;

                $entry[] = $odd
                    ? match ($i % 5) {
                        0 => '2026-02-31T25:61:61.'.str_repeat('9', 20)."+24:00\tEMERGENCY\t\xc3\xa9\t".str_repeat('x {', 20)."}\t".str_repeat("#1\n[stacktrace]\n", 10),
                        1 => '',
                        2 => "\xff\xfe2026-10-06 18:11:29\t\xc3\x89RROR\t\t\t\t\textra",
                        3 => "1969-12-31 23:59:59Z\tnope\tlocal\t".str_repeat('m', 300)." {\"ok\":true}  \tStack trace:\n".str_repeat("#0 /app/x.php(1)\n", 15),
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : '2026-10-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT).' '.str_pad((string) ($i % 24), 2, '0', STR_PAD_LEFT).':11:29'.['', '.123456', '+02:00', 'Z'][$i % 4]."\t".$levels[$i % 8]."\t".['local', 'horizon', 'queue'][$i % 3]."\tJob {$i} failed: connection refused {\"id\":{$i},\"attempt\":".($i % 5).'}'."\t".($i % 3 === 0 ? "#0 /app/x.php(12): run()\n#1 {main}" : '');

                $parser[] = $odd
                    ? match ($i % 5) {
                        0 => str_repeat("junk without a header \xc3\xa9\r\n", 6)."[2026-10-06T18:11:29.123456+05:30] a.b.c.NOTALEVEL: x {\"a\":[1,2]}\n[stacktrace]\n",
                        1 => '',
                        2 => "\xff\xfe[2026-10-06 18:11:29]local.ERROR: x\n[2026-10-06 18:11:29] .ERROR: y\n[2026-10-06 18:11:29.] a.ERROR: z\n",
                        3 => str_repeat("[2026-10-06 18:11:29] local.DEBUG: tick {$i} {\"n\":{$i}}\n", 12),
                        default => str_repeat("#{$i} frame\n", 10),
                    }
                : '[2026-10-06 18:11:29] local.'.strtoupper($levels[$i % 8]).": Job {$i} failed {\"id\":{$i}}\n".($i % 2 === 0 ? "[stacktrace]\n#0 /app/x.php(12): run()\n#1 {main}\n\"}\n" : '')."[2026-10-06 18:11:30] horizon.INFO: Processed {$i}\n";
            }

            $workloads[LogIndex::class][$name] = static fn (bool $rust) => $rust ? LogIndex::w67LogIndexPlanBatchNative($index) : LogIndex::w67LogIndexPlanBatchPhp($index);
            $workloads[LogSearchCoordinator::class][$name] = static fn (bool $rust) => $rust ? LogSearchCoordinator::w67LogSearchCoordinatorPlanBatchNative($coordinator) : LogSearchCoordinator::w67LogSearchCoordinatorPlanBatchPhp($coordinator);
            $workloads[ManticoreLogSearcher::class][$name] = static fn (bool $rust) => $rust ? ManticoreLogSearcher::w67ManticoreLogSearcherPlanBatchNative($manticore) : ManticoreLogSearcher::w67ManticoreLogSearcherPlanBatchPhp($manticore);
            $workloads[LogEntry::class][$name] = static fn (bool $rust) => $rust ? LogEntry::w67LogEntryPlanBatchNative($entry) : LogEntry::w67LogEntryPlanBatchPhp($entry);
            $workloads[LogEntryParser::class][$name] = static fn (bool $rust) => $rust ? LogEntryParser::w67LogEntryParserPlanBatchNative($parser) : LogEntryParser::w67LogEntryParserPlanBatchPhp($parser);
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
                "\n[Wave 67.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php, $class);
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
            LogIndex::class => ["7\t20000000\t42\t42\t1000\t1000\t8388608", "x\t10\t1", ''],
            LogSearchCoordinator::class => ["boom\t0\terror\tlocal\t1\t1\t\t50\t\t", "a.*b\t1\tnope", ''],
            ManticoreLogSearcher::class => ["boom\t0\terror\tlocal\t\t\t\t10\t\terror\tlocal\tBoom boom", "ab\tx\t1", ''],
            LogEntry::class => ["2026-10-06 18:11:29\terror\tlocal\tboom {\"a\":1}\t#0 x", "x\tnope", ''],
            LogEntryParser::class => ["[2026-10-06 18:11:29] local.ERROR: boom {\"a\":1}\n#0 x", "junk\n\n", ''],
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
        $number = static fn (): string => $pick(['', '', ' ', '0', '1', '2', '9', ' 5', '5 ', '00', '007', '-1', '+1', '1.5', 'x', '10', '49', '50', '51', '499', '500', '501', '4096', '8388607', '8388608', '8388609', '1099511627775', '1099511627776', '1700000000', '1800000000', (string) mt_rand(0, 100000), '999999999999999', '1000000000000000', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);
        $level = static fn (): string => $pick(['', 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'ERROR', 'Info', 'nope', ' error ', "\xc3\xa9rror", self::randomBytes(3)]);
        $levels = static fn (): string => $join($pick([',', ',', ', ', ',,']), 4, $level);
        $channel = static fn (): string => $pick(['', 'local', 'horizon', 'queue', 'a.b', 'a-b_c', 'bad chan', "l\xc3\xb6cal", '.', self::randomBytes(3)]);
        $channels = static fn (): string => $join($pick([',', ',', ', ']), 4, $channel);
        $term = static fn (): string => $pick(['', 'boom', 'Boom', 'ab', 'abc', 'a b', 'Jobs\\ReindexRel', 'x.yz', '--', '_', "caf\xc3\xa9", 'BOOM boom', 'main}', '#0', self::randomBytes(5)]);
        $timestamp = static fn (): string => $pick(['2026-10-06 18:11:29', '2026-10-06T18:11:29', '1970-01-01 00:00:00', '1969-12-31 23:59:59', '2026-02-31 00:00:00', '2026-13-01 00:00:00', '2026-10-06 24:00:00', '2026-10-06 18:60:00', '2026-10-06 18:11:60', '0000-00-00 00:00:00', '9999-12-31 23:59:59', '2026-10-06x18:11:29', '2026-10-6 18:11:29']).$pick(['', '', '.5', '.123456', '.', 'Z', '+02:00', '-0530', '+05:3', '+24:00', '+00:60', ' ', "\n", 'ZZ']);
        $header = static fn (): string => '['.$timestamp().']'.$pick([' ', ' ', '  ', "\t", '', "\x0b", "\xc2\xa0"]).$pick(['local', 'production', 'a.b', 'a-b_c', '', '.', "l\xc3\xb6cal", 'x y']).$pick(['.', '.', '..', '']).$pick(['ERROR', 'INFO', 'DEBUG', 'WARNING', 'EMERGENCY', 'NOPE', 'Error', '', 'E1']).$pick([':', ':', ': ', '']);
        $message = static fn (): string => $pick(['', 'boom', 'Job failed', "caf\xc3\xa9", 'x {', '}', ' {"a":1}', 'boom {"id":1}', 'boom {"id":1}  ', "slow\r", '{"a":1}', 'a {b} {c}', self::randomBytes(6)]).$pick(['', '', ' {"ctx":[]}', ' []', '}']);
        $line = static fn (): string => match (mt_rand(0, 6)) {
            0, 1, 2 => $header().$message(),
            3 => $pick(['#0 /app/x.php(12): run()', '#1 {main}', '#x', '#', '[stacktrace]', '[stacktrace] ', 'Stack trace:', '"}', '']),
            default => $pick(['', ' ', "\r", 'junk', "caf\xc3\xa9", self::randomBytes(8)]),
        };

        return match ($class) {
            LogIndex::class => implode("\t", $cut([$number(), $number(), $number(), $number(), $number(), $number(), $number(), 'extra'], 7)),
            LogSearchCoordinator::class => implode("\t", $cut([$term(), $pick(['', '0', '1', ' 1', 'x']), $levels(), $channels(), $pick(['', '0', '1']), $pick(['', '0', '1']), $number(), $number(), $number(), $number(), 'extra'], 10)),
            ManticoreLogSearcher::class => implode("\t", $cut([$term(), $pick(['', '0', '1', 'x']), $levels(), $channels(), $number(), $number(), $number(), $number(), $number(), $level(), $channel(), $join($pick([' ', "\n", "\t", '']), 6, static fn (): string => $pick(['boom', 'BOOM', 'Boom', 'ab', 'abc', 'a b', 'Jobs\\ReindexRelease', 'x.YZ', '_', "caf\xc3\xa9", self::randomBytes(4)]))], 12)),
            LogEntry::class => implode("\t", $cut([$pick([$timestamp(), $timestamp(), '', self::randomBytes(4)]), $level(), $channel(), $message(), $join("\n", 5, $line)], 5)),
            LogEntryParser::class => $join($pick(["\n", "\n", "\r\n"]), 8, $line).$pick(['', "\n", "\r\n"]),
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
        $ffi = RustPipelineLibrary::resolveDomain('w67_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library with the Wave 67.5 helpers is unavailable.');
        }

        return $ffi;
    }
}
