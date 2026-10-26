<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\BookPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\ConsolePipe;
use App\Services\Categorization\Pipes\GroupNamePipe;
use App\Services\Categorization\Pipes\MiscPipe;
use App\Services\Categorization\ReleaseContext;
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
class RustCategorizationBookPassableConsoleGroupNameMiscPipeW66ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        BookPipe::class => 'w66BookPipePlanBatch',
        CategorizationPassable::class => 'w66CategorizationPassablePlanBatch',
        ConsolePipe::class => 'w66ConsolePipePlanBatch',
        GroupNamePipe::class => 'w66GroupNamePipePlanBatch',
        MiscPipe::class => 'w66MiscPipePlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        BookPipe::class => ['W66_BOOK_PIPE_PLAN_FFI_CDEF', 'nntmux_w665_book_pipe_plan'],
        CategorizationPassable::class => ['W66_CATEGORIZATION_PASSABLE_PLAN_FFI_CDEF', 'nntmux_w665_categorization_passable_plan'],
        ConsolePipe::class => ['W66_CONSOLE_PIPE_PLAN_FFI_CDEF', 'nntmux_w665_console_pipe_plan'],
        GroupNamePipe::class => ['W66_GROUP_NAME_PIPE_PLAN_FFI_CDEF', 'nntmux_w665_group_name_pipe_plan'],
        MiscPipe::class => ['W66_MISC_PIPE_PLAN_FFI_CDEF', 'nntmux_w665_misc_pipe_plan'],
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        BookPipe::class => 8,
        CategorizationPassable::class => 9,
        ConsolePipe::class => 8,
        GroupNamePipe::class => 7,
        MiscPipe::class => 8,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        BookPipe::class => true,
        CategorizationPassable::class => true,
        ConsolePipe::class => true,
        GroupNamePipe::class => true,
        MiscPipe::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of four
     * `RUN_RUST_FFI_BENCHMARKS=1` runs (100 samples per workload, seed 6652, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        BookPipe::class => [6.71, 5.92, 5.74, 5.05],
        CategorizationPassable::class => [14.87, 12.44, 13.38, 10.91],
        ConsolePipe::class => [6.20, 6.30, 6.38, 5.73],
        GroupNamePipe::class => [6.48, 6.54, 3.67, 3.57],
        MiscPipe::class => [5.63, 5.82, 4.06, 4.25],
    ];

    /**
     * Properties before Wave 66.5; the wave keeps its state in function-local statics.
     *
     * @var array<class-string, list<string>>
     */
    private const PROPERTIES = [
        BookPipe::class => ['w403RustFfiOverride', 'w403RustFfiHandle', 'useRustFfi', 'pipeSpecFfi', 'priority', 'categorizer'],
        CategorizationPassable::class => ['w453RustFfiOverride', 'w453RustFfiHandle', 'useRustFfi', 'context', 'bestResult', 'debug', 'lockedToMisc', 'allResults', 'miscAnalysis'],
        ConsolePipe::class => ['w403RustFfiOverride', 'w403RustFfiHandle', 'useRustFfi', 'pipeSpecFfi', 'priority', 'categorizer'],
        GroupNamePipe::class => ['w403RustFfiOverride', 'w403RustFfiHandle', 'useRustFfi', 'pipeFfi', 'priority', 'categorizer'],
        MiscPipe::class => ['useRustFfi', 'w393RustFfiOverride', 'w393RustFfiHandle', 'priority', 'categorizer'],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 100;

    private const BYTES = ['a', 'Z', 'x', 'S', 'e', 'E', '0', '1', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', ':', '|', '/', "\xc3\xa9", "\xc3", "\xff", "\xa0", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x80\xa8", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", '|', '||', ':', '::', '.', '..', ' ', '0', '1', '9', '950', '949', '600', '599', '10', '20', '999999999999999', '1000000000000000', 'alt.binaries.', 'a.b.', 'EPUB', 'm4b', 'PS4', 'snes', 'S01E02', 'rar', 'hash_', 'gibberish_', 'no_match', 'ebook_', "\x0c", "\x01", "\xc3\xa9", "\xff"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            $class::resetW66RustFfi();
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
        foreach (['enableW66RustFfi', 'disableW66RustFfi', 'resetW66RustFfi', 'usesW66RustFfi', 'withW66RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w66RustFfiState')->isPrivate(), "{$class} keeps its W66 state private");
        $this->assertTrue((new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->isPrivate(), "{$class} keeps its W66 declaration private");
        $constant = new ReflectionClassConstant($class, 'W66_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), "{$class} routes by its default (PHP when it did not qualify)");

        $class::enableW66RustFfi();
        $this->assertTrue($class::usesW66RustFfi());
        $class::disableW66RustFfi();
        $this->assertFalse($class::usesW66RustFfi());
        $class::resetW66RustFfi();
        $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), "{$class} reset restores the default");

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW66RustFfi($enabled, static fn () => [$class::usesW66RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), "{$class} must restore after the callback");
        }
        $class::disableW66RustFfi();
        $this->assertTrue($class::withW66RustFfi(true, static fn () => $class::usesW66RustFfi()));
        $this->assertFalse($class::usesW66RustFfi(), "{$class} restores an explicit override, not the default");
        $class::resetW66RustFfi();
        $nested = $class::withW66RustFfi(false, static fn () => [$class::withW66RustFfi(true, static fn () => $class::usesW66RustFfi()), $class::usesW66RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW66RustFfi(! $class::usesW66RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), "{$class} must restore after an exception");

        $class::withW66RustFfi(! $class::usesW66RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW66RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w66_state_adds_no_properties(): void
    {
        foreach (self::PROPERTIES as $class => $expected) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w66', $property->getName(), $class);
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
            BookPipe::usesRustFfi(),
            BookPipe::usesW403RustFfi(),
            BookPipe::usesW53RustFfi(),
            CategorizationPassable::$useRustFfi,
            CategorizationPassable::usesW453RustFfi(),
            CategorizationPassable::usesW53RustFfi(),
            ConsolePipe::usesRustFfi(),
            ConsolePipe::usesW403RustFfi(),
            ConsolePipe::usesW54RustFfi(),
            GroupNamePipe::usesRustFfi(),
            GroupNamePipe::usesW403RustFfi(),
            GroupNamePipe::usesW54RustFfi(),
            MiscPipe::$useRustFfi,
            MiscPipe::usesW393RustFfi(),
            MiscPipe::usesW54RustFfi(),
        ];
        $before = $snapshot();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW66RustFfi($enabled, $snapshot), $class);
            }
            $class::enableW66RustFfi();
            $class::resetW66RustFfi();
            $this->assertSame($before, $snapshot(), $class);
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            BookPipe::class => ['__construct', 'usesRustFfi', 'setUseRustFfi', 'setPipeSpecFfiForTesting', 'getName', 'pipeName', 'pipeNamePhp', 'pipeNameNative', 'pipePriority', 'pipePriorityPhp', 'pipePriorityNative', 'evaluateResult', 'evaluateResultPhp', 'evaluateResultNative', 'useW403RustFfi', 'usesW403RustFfi', 'resetW403RustFfi', 'summarizeNameFixBatchProgress', 'summarizeNameFixBatchProgressPhp', 'summarizeNameFixBatchProgressNative', 'usesW53RustFfi', 'withW53RustFfi', 'planBookResultBatch', 'planBookResultBatchPhp', 'planBookResultBatchNative'],
            CategorizationPassable::class => ['setUseRustFfi', 'categorizeFfi', '__construct', 'lockToMisc', 'shouldStopProcessing', 'shouldStopProcessingPhp', 'shouldStopProcessingNative', 'updateBestResult', 'updateBestResultPhp', 'updateBestResultNative', 'toArray', 'toArrayPhp', 'toArrayNative', 'useW453RustFfi', 'usesW453RustFfi', 'resetW453RustFfi', 'summarizeMatchedByBatch', 'summarizeMatchedByBatchPhp', 'summarizeMatchedByBatchNative', 'usesW53RustFfi', 'withW53RustFfi', 'planBestResultBatch', 'planBestResultBatchPhp', 'planBestResultBatchNative'],
            ConsolePipe::class => ['__construct', 'usesRustFfi', 'setUseRustFfi', 'setPipeSpecFfiForTesting', 'getName', 'pipeName', 'pipeNamePhp', 'pipeNameNative', 'pipePriority', 'pipePriorityPhp', 'pipePriorityNative', 'evaluateResult', 'evaluateResultPhp', 'evaluateResultNative', 'useW403RustFfi', 'usesW403RustFfi', 'resetW403RustFfi', 'summarizeCleanedFilenameBatch', 'summarizeCleanedFilenameBatchPhp', 'summarizeCleanedFilenameBatchNative', 'usesW54RustFfi', 'withW54RustFfi', 'w54ConsolePlatformFlagBatch', 'w54ConsolePlatformFlagBatchNative', 'w54ConsolePlatformFlagBatchPhp'],
            GroupNamePipe::class => ['__construct', 'usesRustFfi', 'setUseRustFfi', 'setPipeFfiForTesting', 'getName', 'pipeName', 'pipeNamePhp', 'pipeNameNative', 'pipePriority', 'pipePriorityPhp', 'pipePriorityNative', 'evaluateResult', 'evaluateResultPhp', 'evaluateResultNative', 'resultSummary', 'resultSummaryPhp', 'resultSummaryNative', 'useW403RustFfi', 'usesW403RustFfi', 'resetW403RustFfi', 'summarizeGroupNameRouting', 'summarizeGroupNameRoutingPhp', 'summarizeGroupNameRoutingNative', 'usesW54RustFfi', 'withW54RustFfi', 'w54GroupNameRouteFlagBatch', 'w54GroupNameRouteFlagBatchNative', 'w54GroupNameRouteFlagBatchPhp'],
            MiscPipe::class => ['setUseRustFfi', 'categorizeFfi', '__construct', 'getName', 'handle', 'shouldLock', 'shouldLockPhp', 'shouldLockNative', 'useW393RustFfi', 'usesW393RustFfi', 'resetW393RustFfi', 'summarizeMiscPipeDecisionMetrics', 'summarizeMiscPipeDecisionMetricsPhp', 'summarizeMiscPipeDecisionMetricsNative', 'usesW54RustFfi', 'withW54RustFfi', 'w54MiscSubjectShapeBatch', 'w54MiscSubjectShapeBatchNative', 'w54MiscSubjectShapeBatchPhp'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        foreach ([
            [BookPipe::class, 'W403_RUST_FFI_DEFAULT', false],
            [BookPipe::class, 'W53_RUST_FFI_DEFAULT', true],
            [CategorizationPassable::class, 'W453_RUST_FFI_DEFAULT', false],
            [CategorizationPassable::class, 'W53_RUST_FFI_DEFAULT', true],
            [ConsolePipe::class, 'W403_RUST_FFI_DEFAULT', true],
            [ConsolePipe::class, 'W54_RUST_FFI_DEFAULT', true],
            [GroupNamePipe::class, 'W403_RUST_FFI_DEFAULT', true],
            [GroupNamePipe::class, 'W54_RUST_FFI_DEFAULT', true],
            [MiscPipe::class, 'W393_RUST_FFI_DEFAULT', true],
            [MiscPipe::class, 'W54_RUST_FFI_DEFAULT', true],
        ] as [$class, $constant, $value]) {
            $this->assertSame($value, (new ReflectionClassConstant($class, $constant))->getValue(), "{$class}::{$constant}");
        }

        $earlier = static fn (): array => [
            BookPipe::evaluateResultPhp(Category::BOOKS_EBOOK, 0.96, 'ebook_x'),
            BookPipe::planBookResultBatchPhp([Category::BOOKS_COMICS], [0.7], ['comic_x']),
            CategorizationPassable::planBestResultBatchPhp([Category::TV_OTHER], [0.7], ['tv'], [1], [0]),
            ConsolePipe::w54ConsolePlatformFlagBatchPhp(['Game.PS4-X', '']),
            GroupNamePipe::evaluateResultPhp(Category::TV_OTHER, 0.6, 'group_name_tv'),
            GroupNamePipe::w54GroupNameRouteFlagBatchPhp(['alt.binaries.tv']),
            (new MiscPipe)->shouldLockPhp(new CategorizationResult(Category::OTHER_MISC, 0.9, 'hash_md5')),
            MiscPipe::w54MiscSubjectShapeBatchPhp(['abc.rar', '']),
        ];
        $expected = $earlier();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW66RustFfi($enabled, $earlier), $class);
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
        $this->assertSame($php, $class::withW66RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW66RustFfi(true, static fn () => $class::{$method}($records)), $class);
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

    public function test_book_pipe_plan_examples(): void
    {
        $this->requireFfi(BookPipe::class);
        $this->assertBoth(BookPipe::class, [
            "Some.Author-Title.2019.RETAIL.EPUB\t7020\t900\tebook_format\t10",
            '',
            "Stephen.King-It.Unabridged.M4B\t0\t0\tno_match\t7999",
            "Batman.001.2016.cbr\t10\t0\tno_match\t20",
            "PCGamer.Magazine.Issue.12\t3030\t960\taudiobook_x\t5000",
            "x\tA\t1",
        ], [
            6, 33, 7020, 3, 1, 7020, 5, 0,
            0, 0, 0, 0, 0, 10, 0, 2,
            5, 2, 3030, 0, 2, 3030, 0, 0,
            4, 40, 7030, 0, 3, 20, 0, 0,
            4, 4, 7010, 7, 1, 3030, 6, 0,
            1, 0, 0, 1, 0, 10, 0, 3,
        ]);
        // The success and terminal bits agree with evaluateResultPhp() at permille confidences.
        foreach ([[Category::BOOKS_EBOOK, 950, 'x'], [Category::OTHER_MISC, 500, 'no_match'], [Category::BOOKS_UNKNOWN, 0, 'y'], [Category::MOVIE_OTHER, 949, 'z']] as [$category, $permille, $label]) {
            $plan = BookPipe::w66BookPipePlanBatchPhp(["\t{$category}\t{$permille}\t{$label}\t10"]);
            $mask = BookPipe::evaluateResultPhp($category, $permille / 1000, $label);
            $this->assertSame($mask & (BookPipe::RESULT_SUCCESSFUL | BookPipe::RESULT_TERMINAL), $plan[3] & 5, "{$category}/{$permille}");
        }
    }

    public function test_categorization_passable_plan_examples(): void
    {
        $this->requireFfi(CategorizationPassable::class);
        $this->assertBoth(CategorizationPassable::class, [
            "0\t10\t0\tno_match\t2000:700:movie_x|5000:800:tv_y|7020:800:ebook_z",
            '',
            "0\t20\t900\thash_md5\t2000:500:weak|2000:960:strong|5000:990:after",
            "1\t10\t0\tno_match\t2000:700:a|bad|:|x:1:z",
            "0\t10\t500\tlbl\t10:500:other|7020:500:tie|3000:960:big:colon|2000:999:late",
        ], [
            5000, 800, 3, 2, 0, 0, -1, crc32('tv_y'), 0,
            10, 0, 0, 0, 0, 0, -1, crc32(''), 2,
            2000, 960, 2, 1, 1, 1, 2, crc32('strong'), 32,
            10, 0, 0, 0, 0, 2, 0, crc32('no_match'), 45,
            3000, 960, 3, 2, 0, 1, 3, crc32('big:colon'), 32,
        ]);
        // The replay agrees with updateBestResultPhp() and shouldStopProcessingPhp() on a live passable.
        $passable = new CategorizationPassable(new ReleaseContext('Some.Release', 0, 'alt.binaries.test'));
        $passable->bestResult = new CategorizationResult(Category::OTHER_HASHED, 0.9, 'hash_md5');
        foreach ([[2000, 0.5, 'weak'], [2000, 0.96, 'strong'], [5000, 0.99, 'after']] as [$category, $confidence, $label]) {
            if (! $passable->shouldStopProcessingPhp()) {
                $passable->updateBestResultPhp(new CategorizationResult($category, $confidence, $label), 'test');
            }
        }
        $plan = CategorizationPassable::w66CategorizationPassablePlanBatchPhp(["0\t20\t900\thash_md5\t2000:500:weak|2000:960:strong|5000:990:after"]);
        $this->assertSame([$passable->bestResult->categoryId, (int) round($passable->bestResult->confidence * 1000)], [$plan[0], $plan[1]]);
    }

    public function test_console_pipe_plan_examples(): void
    {
        $this->requireFfi(ConsolePipe::class);
        $this->assertBoth(ConsolePipe::class, [
            "Game.Title.PS4-DUPLEX\t0\t0\tno_match\t10",
            '',
            "Retro.SNES.USA.PAL\t1999\t960\tconsole_retro\t1999",
            "Show.S01E02.Xbox\t5000\t700\ttv\t10",
            "Game.Update.v1.WIN64.GOG\t4000\t700\tpc\t10",
            "Game.XBOX360.DLC\t10\t0\tno_match\t20",
        ], [
            4, 2, 1180, 0, 2, 1180, 0, 0,
            0, 0, 0, 0, 0, 10, 0, 2,
            4, 12, 1999, 7, 1, 1999, 2, 0,
            3, 18, 1040, 1, 2, 1040, 0, 0,
            5, 96, 0, 1, 4, 10, 0, 0,
            3, 66, 1050, 0, 3, 20, 0, 0,
        ]);
        // Token flags and the platform agree with the Wave 54.3 platform flags for the same names.
        $names = ['Game.Title.PS4-DUPLEX', 'Retro.SNES.USA.PAL', 'Show.S01E02.Xbox', 'Game.Win64.GOG'];
        $flags = ConsolePipe::w54ConsolePlatformFlagBatchPhp($names);
        foreach ($names as $i => $name) {
            $plan = ConsolePipe::w66ConsolePipePlanBatchPhp(["{$name}\t\t\t\t"]);
            $this->assertSame([$flags[$i] & 0x3E, $flags[$i] >> 16], [$plan[1], $plan[2]], $name);
        }
    }

    public function test_group_name_pipe_plan_examples(): void
    {
        $this->requireFfi(GroupNamePipe::class);
        $this->assertBoth(GroupNamePipe::class, [
            "alt.binaries.multimedia.anime.highspeed\t10\t0\tno_match",
            '',
            " A.B.HDTV.X264 \t10\t0\tno_match",
            "alt.binaries.moovee\t20\t900\thash_x",
            "alt.binaries.e-book.flood\t10\t500\thash_y",
            "alt.binaries.mp3\t3010\t960\tmusic_x",
            "alt.binaries.boneless\t2000\t700\tmovie",
            "comp.lang.php\tx\t0\t\textra",
        ], [
            1, 5070, 800, 1, 5070, 5, 0,
            0, 0, 0, 0, 10, 0, 10,
            2, 5999, 600, 1, 5999, 4, 16,
            0, 0, 0, 0, 20, 3, 0,
            8, 7020, 500, 2, 10, 4, 0,
            5, 3999, 600, 4, 3010, 3, 0,
            0, 0, 0, 0, 2000, 3, 0,
            0, 0, 0, 0, 10, 3, 7,
        ]);
    }

    public function test_misc_pipe_plan_examples(): void
    {
        $this->requireFfi(MiscPipe::class);
        $this->assertBoth(MiscPipe::class, [
            " 0123456789abcdef0123 \t20\t900\thash_md5\t10",
            '',
            "xkcdqzrt.part01.rar\t10\t500\tgibberish_x\t5000",
            "Some.Release.Name.ZIP\t10\t0\tno_match\t0",
            "averyveryverylongtokenvalue123\t10\t300\tother\t2000",
            "0123456789ABCDEF\t5000\t400\tx\t0",
        ], [
            20, 1, 1, 20, 3, 1, 20, 0,
            0, 0, 16, 0, 0, 0, 10, 2,
            19, 3, 10, 8, 3, 1, 10, 0,
            21, 4, 2, 7, 0, 0, 0, 0,
            30, 1, 4, 30, 1, 3, 10, 0,
            16, 1, 1, 16, 1, 2, 20, 0,
        ]);
        // The lock bit agrees with shouldLockPhp().
        $pipe = new MiscPipe;
        foreach ([[Category::OTHER_HASHED, 'x'], [Category::OTHER_MISC, 'obfuscated_y'], [Category::OTHER_MISC, 'misc'], [Category::TV_OTHER, 'gibberish_z']] as [$category, $label]) {
            $plan = MiscPipe::w66MiscPipePlanBatchPhp(["s\t{$category}\t500\t{$label}\t10"]);
            $this->assertSame($pipe->shouldLockPhp(new CategorizationResult($category, 0.5, $label)), ($plan[4] & 2) !== 0, $label);
        }
    }

    /**
     * Numeric limits: 15 digits parse, 16 digits are invalid; thresholds hold exactly at 949/950 and 599/600.
     */
    public function test_boundary_records(): void
    {
        $this->requireFfi(BookPipe::class);
        $max = '999999999999999';
        $this->assertBoth(BookPipe::class, [
            "Title.1899.1900.2099.2100\t{$max}\t{$max}\tebook_\t{$max}",
            "Title\t1{$max}\t950\t\t1{$max}",
            "Title\t7000\t949\tx\t7999",
            str_repeat('Epub.', 2000)."\t7999\t1\t\t10",
        ], null);
        $this->assertBoth(CategorizationPassable::class, [
            "0\t10\t949\tx\t2000:950:a|3000:999:b",
            "0\t20\t0\thash_\t2000:599:a|2000:600:b",
            "{$max}\t{$max}\t{$max}\t\t1:1:1",
            "0\t10\t0\tno_match\t".implode('|', array_map(static fn (int $i): string => (1000 + $i).':'.$i.':l'.$i, range(0, 999))),
            "0\t10\t0\tgroup_only_low_signal\t5000:599:x|10:599:y",
        ], null);
        $this->assertBoth(ConsolePipe::class, [
            "S999E9999.S1000.S1E10000.s01e01\t1999\t950\t\t1999",
            "REGIONFREE.MEGADRIVE.REGIONFREEX\t1{$max}\t\t\t",
            str_repeat('PS4.', 2000)."\t\t\t\t",
        ], null);
        $this->assertBoth(GroupNamePipe::class, [
            "alt.binaries.\t10\t0\t",
            "xalt.binaries.tv\t10\t950\t",
            "alt.binaries.tv\t10\t600\t",
            "alt.binaries.tv\t20\t599\thash_x",
            'alt.binaries.'.str_repeat('x', 5000).'comic'."\t{$max}\t{$max}\t",
            "...\t\t\t",
        ], null);
        $this->assertBoth(MiscPipe::class, [
            str_repeat('a', 15)."\t20\t950\t\t0",
            str_repeat('F', 16)."\t\t\t\t",
            str_repeat('Z', 16).".bcdfgh.bcdfg\t10\t1\tno_match\t20",
            str_repeat('ab', 5000)."\t{$max}\t1{$max}\t\t",
        ], null);
    }

    /**
     * Non-ASCII bytes (valid and invalid UTF-8) never count as ASCII letters, digits, separators or whitespace.
     */
    public function test_unicode_records(): void
    {
        $this->requireFfi(BookPipe::class);
        $this->assertBoth(BookPipe::class, ["Caf\xc3\xa9.\xc3\x89PUB.EPUB\xc2\xa0MOBI\t7020\t\xd9\xa1\t\xc3\xa9book_\t", "\xff\xfe\t\t\t\t"], null);
        $this->assertBoth(CategorizationPassable::class, ["\xc2\xa01\t10\t0\t\xc3\xa9\t2000:700:\xc3\xa9|\xd9\xa1:1:x"], null);
        $this->assertBoth(ConsolePipe::class, ["\xef\xbc\xb0\xef\xbc\xb3\xef\xbc\x94.PS4\xc3\xa9.\xc5\xbf01\t\t\t\t", "Game.\xed\xa0\x80PS3\t\t\t\t"], null);
        $this->assertBoth(GroupNamePipe::class, ["ALT.BINARIES.\xc3\x84NIME.anime\t\t\t", "alt\xc2\xa0binaries.tv\t\t\t", "\xe2\x80\xa8alt.binaries.tv\t\t\t"], null);
        $this->assertBoth(MiscPipe::class, ["\xc2\xa0abcdef0123456789\t\t\t\t", "\xf0\x9f\x98\x80.rar\t20\t1\thash_\xc3\xa9\t"], null);
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
        $this->assertSame($class::{$method.'Php'}($records), $class::withW66RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        mt_srand(6651 + crc32($class) % 1000);
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
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 66.5 paired benchmark.');
        }
        $this->requireFfi(BookPipe::class);
        mt_srand(6652);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $book = $passable = $console = $group = $misc = [];
            for ($i = 0; $i < $count; $i++) {
                $hash = md5((string) $i);
                $book[] = $odd
                    ? match ($i % 5) {
                        0 => " Author.\xc3\x89dition.".str_repeat('Vol.', 6)."Unabridged.M4B.MP3.2021.Retail.epub\t 3030 \t999999999999999\t audiobook_x\t7999",
                        1 => '',
                        2 => "\xff\xfeBroken\x01Title.cbz\t-1\t\xc3\xa9\tcomic_\t20\textra",
                        3 => str_repeat('Magazine.Issue.', 8)."{$i}\t7010\t950\tmagazine_x\t0",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['Author.Name-Book.Title.', 'Comic.Series.', 'Weekly.Magazine.', 'Packt.Learning.Rust.', 'Some.Novel.'][$i % 5].(2000 + $i % 25).'.'.['EPUB', 'CBR', 'PDF', 'MOBI', 'AZW3'][$i % 5]."-GROUP{$i}\t".[7020, 7030, 7010, 7040, 10][$i % 5]."\t".($i * 37 % 1000)."\t".['ebook_format', 'comic_cbr', 'magazine_issue', 'technical_book', 'no_match'][$i % 5]."\t".[10, 7999, 0, 5000, 10][$i % 5];

                $candidates = implode('|', array_map(static fn (int $n): string => [2000, 5000, 7020, 10, 3010, 1999][$n % 6].':'.(($n * 131 + $i) % 1000).':'.['movie_x', 'tv_y', 'ebook_z', 'no_match', 'music_a', 'console_b'][$n % 6], range(0, 5 + $i % 5)));
                $passable[] = $odd
                    ? match ($i % 5) {
                        0 => " 0 \t 20 \t900\thash_{$hash}\t".str_repeat('2000:599:weak|', 6).'||bad|:|x:y:z|2000:960:strong:with:colons',
                        1 => '',
                        2 => "1\t\xc3\xa9\t\xff\tgroup_only_low_signal\t{$candidates}\textra",
                        3 => "0\t10\t0\tno_match\t".implode('|', array_map(static fn (int $n): string => "10:{$n}:no_match", range(1, 30))),
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : "0\t10\t0\tno_match\t{$candidates}";

                $console[] = $odd
                    ? match ($i % 5) {
                        0 => " Retro.Collection.SNES.N64.GBA.GBC.NES.USA.EUR.JPN.REGIONFREE.S01E02\t 1999 \t999999999999999\tconsole_x\t1999",
                        1 => '',
                        2 => "\xff\xfeGame\x01PS4\xc3\xa9.Update.DLC.PATCH\t-1\t\xc3\xa9\t\t20\textra",
                        3 => str_repeat('Game.WIN64.GOG.STEAM.', 4)."{$i}\t4000\t700\tpc\t10",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : 'Game.Title.'.$i.'.'.['PS4', 'XBOX360', 'NSW', 'WiiU', '3DS'][$i % 5].'.'.['USA', 'EUR', 'PAL', 'MULTi5', 'JPN'][$i % 4]."-GROUP\t".[1180, 1050, 1999, 0, 10][$i % 5]."\t".($i * 41 % 1000)."\t".['console_ps4', 'console_x360', 'no_match', 'console_wiiu', 'console_3ds'][$i % 5]."\t".[10, 0, 1999, 5000, 10][$i % 5];

                $group[] = $odd
                    ? match ($i % 5) {
                        0 => ' ALT.BINARIES.'.str_repeat('Multimedia.', 6)."E-Book.Comics \t 20 \t 900 \thash_{$hash}",
                        1 => '',
                        2 => "\xff\xfeA.B.\xc3\xa9.Sounds.MP3\t-1\t\xc3\xa9\t\textra",
                        3 => 'comp.lang.'.str_repeat('x', 40).".{$i}\t10\t0\tno_match",
                        default => "{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : 'alt.binaries.'.['multimedia.anime.highspeed', 'hdtv.x264', 'moovee', 'erotica', 'sounds.lossless', 'games.nintendo', 'warez', 'e-book.flood', 'boneless', 'misc'][$i % 10]."\t".[10, 5000, 2000, 10, 3010][$i % 5]."\t".($i * 53 % 1000)."\t".['no_match', 'tv', 'movie', 'hash_x', 'music'][$i % 5];

                $misc[] = $odd
                    ? match ($i % 5) {
                        0 => ' '.str_repeat($hash, 3)." \t 20 \t999999999999999\thash_md5\t10",
                        1 => '',
                        2 => "\xff\xfe".str_repeat('xkcdqzrt.', 5)."part01.rar\xc3\xa9\t-1\t\xc3\xa9\tgibberish_x\t\textra",
                        3 => str_repeat('averyveryverylongtokenvalue.', 4)."{$i}.7z\t10\t300\tother\t2000",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ($i % 3 === 0 ? $hash : 'Some.Release.Name.'.$i.'.part'.($i % 9).'.'.['rar', 'zip', 'par2', 'nfo'][$i % 4])."\t".[20, 10, 5000, 10][$i % 4]."\t".($i * 59 % 1000)."\t".['hash_md5', 'obfuscated_x', 'tv', 'no_match'][$i % 4]."\t".[10, 0, 5000, 10][$i % 4];
            }

            $workloads[BookPipe::class][$name] = static fn (bool $rust) => $rust ? BookPipe::w66BookPipePlanBatchNative($book) : BookPipe::w66BookPipePlanBatchPhp($book);
            $workloads[CategorizationPassable::class][$name] = static fn (bool $rust) => $rust ? CategorizationPassable::w66CategorizationPassablePlanBatchNative($passable) : CategorizationPassable::w66CategorizationPassablePlanBatchPhp($passable);
            $workloads[ConsolePipe::class][$name] = static fn (bool $rust) => $rust ? ConsolePipe::w66ConsolePipePlanBatchNative($console) : ConsolePipe::w66ConsolePipePlanBatchPhp($console);
            $workloads[GroupNamePipe::class][$name] = static fn (bool $rust) => $rust ? GroupNamePipe::w66GroupNamePipePlanBatchNative($group) : GroupNamePipe::w66GroupNamePipePlanBatchPhp($group);
            $workloads[MiscPipe::class][$name] = static fn (bool $rust) => $rust ? MiscPipe::w66MiscPipePlanBatchNative($misc) : MiscPipe::w66MiscPipePlanBatchPhp($misc);
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
                "\n[Wave 66.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW66RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW66RustFfi(), $class);
            if ($class::usesW66RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW66RustFfi(), "{$class} retains PHP by default");
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
        $this->assertSame($php, $class::withW66RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW66RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
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
            BookPipe::class => ["Author-Title.2019.EPUB\t7020\t900\tebook_format\t10", "x\tA\t1", ''],
            CategorizationPassable::class => ["0\t10\t0\tno_match\t2000:700:movie_x|5000:800:tv_y", "1\tx\t\t\tbad|1:2:3", ''],
            ConsolePipe::class => ["Game.Title.PS4-DUPLEX\t0\t0\tno_match\t10", "Show.S01E02\tx\t1", ''],
            GroupNamePipe::class => ["alt.binaries.multimedia.anime\t10\t0\tno_match", "a.b.tv\tx", ''],
            MiscPipe::class => ["0123456789abcdef0123\t20\t900\thash_md5\t10", "x.rar\tA\t1", ''],
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
        $number = static fn (): string => $pick(['', '', ' ', '0', '1', '5', '9', ' 5', '5 ', '00', '007', '-1', '+1', '1.5', 'x', '10', '20', '499', '500', '599', '600', '601', '949', '950', '951', '999', '1000', '1010', '1180', '1999', '2000', '3010', '3030', '5000', '7000', '7010', '7020', '7999', '8000', (string) mt_rand(0, 10000), '999999999999999', '1000000000000000', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);
        $label = static fn (): string => $pick(['', 'no_match', 'hash_md5', 'hash_', 'obfuscated_x', 'gibberish_y', 'group_only_low_signal', 'group_name_tv', 'comic_x', 'manga', 'mangaka', 'technical_', 'magazine_x', 'ebook_format', 'audiobook_x', 'console_x', 'tv', ' no_match', "x\xc3\xa9", 'a:b', self::randomBytes(4)]);
        $name = static fn (array $words): string => $join($pick(['.', '-', '_', ' ', '..', '.', '.']), 8, static fn (): string => $pick([...$words, 'Title', 'Name', '2019', '1899', '2100', '0001', 'S01E02', 's1', 'S1234', 'GROUP', 'x', "\xc3\xa9", "\xff", self::randomBytes(4), str_repeat('Q', mt_rand(1, 20)), dechex(mt_rand())]));

        return match ($class) {
            BookPipe::class => implode("\t", $cut([
                $name(['EPUB', 'epub', 'Mobi', 'AZW3', 'PDF', 'DjVu', 'FB2', 'LIT', 'EBOOK', 'eBooks', 'AudioBook', 'M4B', 'UNABRIDGED', 'Abridged', 'Narrated', 'Magazine', 'MAG', 'Issue', 'Weekly', 'Monthly', 'CBR', 'cbz', 'Comic', 'COMICS', 'Manga', 'Graphic', 'OReilly', 'Packt', 'Apress', 'Wiley', 'Manning', 'NoStarch', 'Retail']),
                $number(), $number(), $label(), $number(), 'extra',
            ], 5)),
            CategorizationPassable::class => implode("\t", $cut([
                $pick(['', '0', '1', ' 1 ', '00', '2', 'x', '999999999999999']),
                $number(), $number(), $label(),
                $join($pick(['|', '|', '||']), 10, static fn (): string => match (mt_rand(0, 6)) {
                    0 => $pick(['', ':', '::', 'x', '1:2', self::randomBytes(4)]),
                    default => $number().':'.$number().':'.$label().$pick(['', '', ':x', '::']),
                }),
                'extra',
            ], 5)),
            ConsolePipe::class => implode("\t", $cut([
                $name(['PS4', 'ps3', 'PSVita', 'VITA', 'PSV', 'PSP', 'XboxOne', 'XONE', 'XBONE', 'X360', 'XBOX360', 'Xbox', 'WiiU', 'Wii', '3DS', 'NDS', 'Switch', 'NSW', 'PS5', 'SNES', 'NES', 'N64', 'GBA', 'GBC', 'NGC', 'GameCube', 'PSX', 'PS1', 'PS2', 'Genesis', 'MegaDrive', 'Dreamcast', 'Saturn', 'USA', 'EUR', 'PAL', 'NTSC', 'JPN', 'JAP', 'Asia', 'KOR', 'RegionFree', 'GOG', 'Steam', 'WIN', 'Win32', 'Win64', 'Windows', 'x64', 'X86', 'PC', 'DLC', 'Update', 'Patch', 'S01', 'S001E0001', 'S1E12345', 'Season']),
                $number(), $number(), $label(), $number(), 'extra',
            ], 5)),
            GroupNamePipe::class => implode("\t", $cut([
                $pick(['', ' ', 'alt.binaries.', 'ALT.Binaries.', 'a.b.', 'A.B.', 'x.alt.binaries.', 'comp.', 'alt.binaries', '']).$join($pick(['.', '.', '-', '..']), 5, static fn (): string => $pick(['anime', 'ANIME', 'hdtv', 'tv', 'tvseries', 'teevee', 'movies', 'moovee', 'dvd', 'bluray', 'x264', 'X264', 'erotica', 'xxx', 'sounds', 'sound', 'mp3', 'music', 'lossless', 'games', 'console', 'psx', 'nintendo', 'warez', '0day', 'apps', 'application', 'software', 'ebook', 'e-book', 'e_book', 'comics', 'multimedia', 'boneless', 'misc', 'alt.binaries.', "\xc3\xa9", self::randomBytes(3)])).$pick(['', '', ' ', "\n"]),
                $number(), $number(), $label(), 'extra',
            ], 4)),
            MiscPipe::class => implode("\t", $cut([
                $pick(['', ' ', md5((string) mt_rand()), strtoupper(sha1((string) mt_rand())), substr(md5((string) mt_rand()), 0, mt_rand(10, 20)), ' '.md5((string) mt_rand()).' ', '']).$name(['rar', 'RAR', 'zip', '7z', 'par2', 'PAR', 'sfv', 'nzb', 'part01', 'xkcdqzrt', 'bcdfgh', 'bcdfg', 'rhythm', 'RHYTHMS', 'averyveryverylongtokenvalue', str_repeat('ab', mt_rand(5, 12)), md5((string) mt_rand())]),
                $number(), $number(), $label(), $number(), 'extra',
            ], 5)),
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
     * Invokes the class's private `w66RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w66RustFfiState'))->invoke(null, $op, $val);
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
        $ffi = RustPipelineLibrary::resolveDomain('w66_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library with the Wave 66.5 helpers is unavailable.');
        }

        return $ffi;
    }
}
