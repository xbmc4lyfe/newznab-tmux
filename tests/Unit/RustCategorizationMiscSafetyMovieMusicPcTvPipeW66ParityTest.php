<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\Pipes\MiscSafetyNetPipe;
use App\Services\Categorization\Pipes\MoviePipe;
use App\Services\Categorization\Pipes\MusicPipe;
use App\Services\Categorization\Pipes\PcPipe;
use App\Services\Categorization\Pipes\TvPipe;
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
class RustCategorizationMiscSafetyMovieMusicPcTvPipeW66ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        MiscSafetyNetPipe::class => 'w66MiscSafetyNetPipePlanBatch',
        MoviePipe::class => 'w66MoviePipePlanBatch',
        MusicPipe::class => 'w66MusicPipePlanBatch',
        PcPipe::class => 'w66PcPipePlanBatch',
        TvPipe::class => 'w66TvPipePlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        MiscSafetyNetPipe::class => ['W66_MISC_SAFETY_NET_PIPE_PLAN_FFI_CDEF', 'nntmux_w666_misc_safety_net_pipe_plan'],
        MoviePipe::class => ['W66_MOVIE_PIPE_PLAN_FFI_CDEF', 'nntmux_w666_movie_pipe_plan'],
        MusicPipe::class => ['W66_MUSIC_PIPE_PLAN_FFI_CDEF', 'nntmux_w666_music_pipe_plan'],
        PcPipe::class => ['W66_PC_PIPE_PLAN_FFI_CDEF', 'nntmux_w666_pc_pipe_plan'],
        TvPipe::class => ['W66_TV_PIPE_PLAN_FFI_CDEF', 'nntmux_w666_tv_pipe_plan'],
    ];

    /** Output words per record (every Wave 66.6 helper writes eight). */
    private const WIDTH = 8;

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        MiscSafetyNetPipe::class => true,
        MoviePipe::class => true,
        MusicPipe::class => true,
        PcPipe::class => true,
        TvPipe::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `RUN_RUST_FFI_BENCHMARKS=1` runs (100 samples per workload, seed 6662, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        MiscSafetyNetPipe::class => [5.61, 5.26, 5.31, 3.93],
        MoviePipe::class => [7.37, 6.01, 5.98, 3.75],
        MusicPipe::class => [6.91, 5.03, 5.96, 5.51],
        PcPipe::class => [7.21, 4.77, 5.93, 3.86],
        TvPipe::class => [7.44, 4.96, 5.61, 4.40],
    ];

    /**
     * Properties before Wave 66.6; the wave keeps its state in function-local statics.
     *
     * @var array<class-string, list<string>>
     */
    private const PROPERTIES = [
        MiscSafetyNetPipe::class => ['predbTransactionRustFfiOverride', 'predbTransactionRustFfiHandle', 'w463RustFfiOverride', 'w463RustFfiHandle', 'useRustFfi', 'priority'],
        MoviePipe::class => ['w403RustFfiOverride', 'w403RustFfiHandle', 'useRustFfi', 'pipeSpecFfi', 'priority', 'categorizer'],
        MusicPipe::class => ['w413RustFfiOverride', 'w413RustFfiHandle', 'useRustFfi', 'pipeSpecFfi', 'priority', 'categorizer'],
        PcPipe::class => ['w413RustFfiOverride', 'w413RustFfiHandle', 'useRustFfi', 'pipeFfi', 'priority', 'categorizer'],
        TvPipe::class => ['w413RustFfiOverride', 'w413RustFfiHandle', 'useRustFfi', 'pipeSpecFfi', 'priority', 'categorizer'],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 100;

    private const BYTES = ['a', 'Z', 'x', 'S', 'e', 'E', 'v', 'V', 'k', '0', '1', '2', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', ':', '|', '/', "\xc3\xa9", "\xc3", "\xff", "\xa0", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x80\xa8", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", '.', '..', '-', ' ', '0', '1', '9', '950', '949', '400', '399', '10', '20', '2999', '3999', '4010', '5999', '999999999999999', '1000000000000000', '1080p', '2160P', 'x265', 'S01E02', 's1', 'S1234', 'FLAC', '320kbps', 'Win64', 'v12', 'PS4', 'BluRay', 'hash_', 'group_name_', 'no_match', '2019', "\x0c", "\x01", "\xc3\xa9", "\xff"];

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
            MiscSafetyNetPipe::$useRustFfi,
            MiscSafetyNetPipe::usesPredbTransactionRustFfi(),
            MiscSafetyNetPipe::usesW463RustFfi(),
            MiscSafetyNetPipe::usesW53RustFfi(),
            MoviePipe::usesRustFfi(),
            MoviePipe::usesW403RustFfi(),
            MoviePipe::usesW53RustFfi(),
            MusicPipe::usesRustFfi(),
            MusicPipe::usesW413RustFfi(),
            MusicPipe::usesW54RustFfi(),
            PcPipe::usesRustFfi(),
            PcPipe::usesW413RustFfi(),
            PcPipe::usesW54RustFfi(),
            TvPipe::usesRustFfi(),
            TvPipe::usesW413RustFfi(),
            TvPipe::usesW53RustFfi(),
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
            MiscSafetyNetPipe::class => ['setUseRustFfi', 'categorizeFfi', 'getName', 'handle', 'shouldDowngradeToMisc', 'shouldDowngradeToMiscPhp', 'shouldDowngradeToMiscNative', 'usePredbTransactionRustFfi', 'usesPredbTransactionRustFfi', 'resetPredbTransactionRustFfi', 'planPredbTransactionRetry', 'classifyPredbTransactionFailure', 'useW463RustFfi', 'usesW463RustFfi', 'resetW463RustFfi', 'summarizeDowngradeBatch', 'summarizeDowngradeBatchPhp', 'summarizeDowngradeBatchNative', 'usesW53RustFfi', 'withW53RustFfi', 'planSafetyNetBatch', 'planSafetyNetBatchPhp', 'planSafetyNetBatchNative'],
            MoviePipe::class => ['__construct', 'usesRustFfi', 'setUseRustFfi', 'setPipeSpecFfiForTesting', 'getName', 'pipeName', 'pipeNamePhp', 'pipeNameNative', 'pipePriority', 'pipePriorityPhp', 'pipePriorityNative', 'evaluateResult', 'evaluateResultPhp', 'evaluateResultNative', 'useW403RustFfi', 'usesW403RustFfi', 'resetW403RustFfi', 'summarizeCandidateScoreWindow', 'summarizeCandidateScoreWindowPhp', 'summarizeCandidateScoreWindowNative', 'usesW53RustFfi', 'withW53RustFfi', 'planMovieResultBatch', 'planMovieResultBatchPhp', 'planMovieResultBatchNative'],
            MusicPipe::class => ['__construct', 'usesRustFfi', 'setUseRustFfi', 'setPipeSpecFfiForTesting', 'getName', 'pipeName', 'pipeNamePhp', 'pipeNameNative', 'pipePriority', 'pipePriorityPhp', 'pipePriorityNative', 'evaluateResult', 'evaluateResultPhp', 'evaluateResultNative', 'useW413RustFfi', 'usesW413RustFfi', 'resetW413RustFfi', 'summarizeAudioFormatBatch', 'summarizeAudioFormatBatchPhp', 'summarizeAudioFormatBatchNative', 'usesW54RustFfi', 'withW54RustFfi', 'w54AudioTokenFlagBatch', 'w54AudioTokenFlagBatchNative', 'w54AudioTokenFlagBatchPhp'],
            PcPipe::class => ['__construct', 'usesRustFfi', 'setUseRustFfi', 'setPipeFfiForTesting', 'getName', 'pipeName', 'pipeNamePhp', 'pipeNameNative', 'pipePriority', 'pipePriorityPhp', 'pipePriorityNative', 'decisionState', 'decisionStatePhp', 'decisionStateNative', 'evaluateResult', 'evaluateResultPhp', 'evaluateResultNative', 'useW413RustFfi', 'usesW413RustFfi', 'resetW413RustFfi', 'summarizePcPlatformBatch', 'summarizePcPlatformBatchPhp', 'summarizePcPlatformBatchNative', 'usesW54RustFfi', 'withW54RustFfi', 'w54PcPlatformFlagBatch', 'w54PcPlatformFlagBatchNative', 'w54PcPlatformFlagBatchPhp'],
            TvPipe::class => ['__construct', 'usesRustFfi', 'setUseRustFfi', 'setPipeSpecFfiForTesting', 'getName', 'pipeName', 'pipeNamePhp', 'pipeNameNative', 'pipePriority', 'pipePriorityPhp', 'pipePriorityNative', 'evaluateResult', 'evaluateResultPhp', 'evaluateResultNative', 'useW413RustFfi', 'usesW413RustFfi', 'resetW413RustFfi', 'summarizeEpisodeTokenBatch', 'summarizeEpisodeTokenBatchPhp', 'summarizeEpisodeTokenBatchNative', 'usesW53RustFfi', 'withW53RustFfi', 'w53EpisodeTokenBatch', 'w53EpisodeTokenBatchNative', 'w53EpisodeTokenBatchPhp'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        foreach ([
            [MiscSafetyNetPipe::class, 'PREDB_TRANSACTION_RUST_FFI_DEFAULT', false],
            [MiscSafetyNetPipe::class, 'W463_RUST_FFI_DEFAULT', false],
            [MiscSafetyNetPipe::class, 'W53_RUST_FFI_DEFAULT', true],
            [MoviePipe::class, 'W403_RUST_FFI_DEFAULT', false],
            [MoviePipe::class, 'W53_RUST_FFI_DEFAULT', true],
            [MusicPipe::class, 'W413_RUST_FFI_DEFAULT', true],
            [MusicPipe::class, 'W54_RUST_FFI_DEFAULT', true],
            [PcPipe::class, 'W413_RUST_FFI_DEFAULT', true],
            [PcPipe::class, 'W54_RUST_FFI_DEFAULT', true],
            [TvPipe::class, 'W413_RUST_FFI_DEFAULT', false],
            [TvPipe::class, 'W53_RUST_FFI_DEFAULT', true],
        ] as [$class, $constant, $value]) {
            $this->assertSame($value, (new ReflectionClassConstant($class, $constant))->getValue(), "{$class}::{$constant}");
        }

        $earlier = static fn (): array => [
            MiscSafetyNetPipe::planSafetyNetBatchPhp([Category::TV_OTHER], ['group_name_tv'], [2]),
            MiscSafetyNetPipe::summarizeDowngradeBatchPhp(['group_name_x', 'tv'], [true, true]),
            MoviePipe::evaluateResultPhp(Category::MOVIE_HD, 0.96, 'hd_x'),
            MoviePipe::planMovieResultBatchPhp([Category::MOVIE_HD], [0.7], ['hd_x']),
            MusicPipe::w54AudioTokenFlagBatchPhp(['Artist-Album-2019-FLAC', '']),
            PcPipe::w54PcPlatformFlagBatchPhp(['App.v1.Win64', '']),
            TvPipe::w53EpisodeTokenBatchPhp(['Show.S01E02.720p', 'News.2020.01.02']),
        ];
        $expected = $earlier();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW66RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_misc_safety_net_pipe_plan_examples(): void
    {
        $this->requireFfi(MiscSafetyNetPipe::class);
        $this->assertBoth(MiscSafetyNetPipe::class, [
            "abc\t5000\t700\tgroup_name_tv\t2",
            "0123456789abcdef0123\t0\t0\t\t0",
            '',
            "abc\t5000\t700\tgroup_name_tv\t3",
            "Some.Show.S01E02.1080p\t5000\t300\ttv_x\t0",
            "Some.Random.Name.2019\t5000\t399\ttv_x\t0",
            "Some.Random.Name\t20\t100\tx\t0",
            "Name\t0\t900\tlabel\tx",
        ], [
            3, 1, 8, 0, 11, 1, 10, 0,
            20, 1, 9, 0, 0, 2, 20, 0,
            0, 0, 16, 0, 0, 3, 10, 2,
            3, 1, 8, 0, 15, 0, 5000, 0,
            22, 4, 0, 2, 65, 0, 5000, 0,
            21, 4, 2, 0, 65, 4, 10, 0,
            16, 3, 0, 0, 81, 0, 20, 0,
            4, 1, 8, 0, 1, 3, 10, 1,
        ]);
        // Decision 1 agrees with shouldDowngradeToMiscPhp() on a live passable.
        $pipe = new MiscSafetyNetPipe;
        foreach ([[false, true, 'group_name_tv'], [true, true, 'group_name_tv'], [false, false, 'group_name_tv'], [false, true, 'tv_x']] as [$locked, $low, $label]) {
            $passable = new CategorizationPassable(new ReleaseContext('Some.Release', 0, 'alt.binaries.test'));
            $passable->bestResult = new CategorizationResult(Category::TV_OTHER, 0.7, $label);
            $passable->miscAnalysis = ['lowSignal' => $low];
            if ($locked) {
                $passable->lockToMisc();
            }
            $state = ($passable->lockedToMisc ? 1 : 0) | ($low ? 2 : 0);
            $plan = MiscSafetyNetPipe::w66MiscSafetyNetPipePlanBatchPhp(["Some.Release\t".Category::TV_OTHER."\t700\t{$label}\t{$state}"]);
            $this->assertSame($pipe->shouldDowngradeToMiscPhp($passable), $plan[5] === 1, $label);
        }
    }

    public function test_movie_pipe_plan_examples(): void
    {
        $this->requireFfi(MoviePipe::class);
        $this->assertBoth(MoviePipe::class, [
            "Some.Movie.2019.1080p.BluRay.x264-GRP\t0\t0\tno_match\t10",
            "Show.S01E02.720p\t0\t0\tno_match\t10",
            '',
            "Film.2160p.UHD.HEVC.German\t2045\t960\tuhd_x\t20",
            "Film.3D.HSBS.1080p\t2050\t700\t3d\t2999",
            "Film.2001.DVDR\tx\t\t\t5000",
        ], [
            7, 3074, 2040, 0, 2, 2040, 2019, 0,
            3, 514, 2040, 0, 4, 10, 0, 0,
            0, 0, 0, 0, 0, 10, 0, 2,
            5, 268, 2010, 7, 3, 20, 0, 0,
            4, 18, 2050, 11, 1, 2050, 0, 0,
            3, 1088, 2070, 0, 0, 5000, 2001, 1,
        ]);
        $this->assertResultMask(MoviePipe::class, [Category::MOVIE_HD, Category::MOVIE_OTHER, Category::TV_HD, Category::OTHER_MISC]);
    }

    public function test_music_pipe_plan_examples(): void
    {
        $this->requireFfi(MusicPipe::class);
        $this->assertBoth(MusicPipe::class, [
            "Artist-Album-2019-FLAC-24BIT\t0\t0\tno_match\t10",
            "Podcast.Episode.320kbps.MP3\t3010\t960\tmusic_mp3\t10",
            "Show.S01E02.1080p.x264\t0\t0\tno_match\t10",
            "Concert.1080p.x264\t0\t0\tno_match\t3999",
            "Author.Book.Unabridged.M4B\t7020\t800\tebook_x\t0",
        ], [
            5, 513, 3040, 0, 2, 3040, 2019, 0,
            4, 38, 3050, 7, 1, 3010, 0, 0,
            4, 384, 0, 0, 4, 10, 0, 0,
            3, 272, 3020, 0, 2, 3020, 0, 0,
            4, 8, 3030, 1, 2, 3030, 0, 0,
        ]);
        $this->assertResultMask(MusicPipe::class, [Category::MUSIC_MP3, Category::MUSIC_OTHER, Category::MOVIE_HD, Category::OTHER_MISC]);
        // Lossless and lossy bits agree with the Wave 54.3 audio token flags.
        $names = ['Artist-Album-2019-FLAC', 'A.MP3.320', 'Mix.DSD.OGG', 'Nothing.Here'];
        $flags = MusicPipe::w54AudioTokenFlagBatchPhp($names);
        foreach ($names as $i => $name) {
            $plan = MusicPipe::w66MusicPipePlanBatchPhp(["{$name}\t\t\t\t"]);
            $this->assertSame(($flags[$i] >> 1) & 3, $plan[1] & 3, $name);
        }
    }

    public function test_pc_pipe_plan_examples(): void
    {
        $this->requireFfi(PcPipe::class);
        $this->assertBoth(PcPipe::class, [
            "Adobe.Photoshop.v24.1.Win64.Cracked\t0\t0\tno_match\t10",
            "Game.PS4-DUPLEX\t0\t0\tno_match\t10",
            "App.v1.2.Android.APK\t4070\t900\tpc_android\t20",
            "Tool.MacOS.DMG.Linux.DEB\t0\t0\tno_match\t4010",
            "Movie.2019.1080p.x264\t2040\t900\thd\t10",
        ], [
            6, 385, 4010, 0, 2, 4010, 1, 0,
            3, 576, 4050, 0, 4, 10, 0, 0,
            5, 272, 4070, 11, 3, 20, 1, 0,
            5, 6, 4030, 0, 2, 4030, 2, 0,
            4, 1024, 0, 1, 4, 10, 0, 0,
        ]);
        $this->assertResultMask(PcPipe::class, [Category::PC_0DAY, Category::PC_PHONE_ANDROID, Category::GAME_OTHER, Category::OTHER_MISC]);
        // Windows, macOS and Linux bits agree with the Wave 54.3 platform flags.
        $names = ['App.Win64', 'Tool.MacOS.DMG', 'Kit.Ubuntu.DEB.X86', 'None'];
        $flags = PcPipe::w54PcPlatformFlagBatchPhp($names);
        foreach ($names as $i => $name) {
            $plan = PcPipe::w66PcPipePlanBatchPhp(["{$name}\t\t\t\t"]);
            $this->assertSame(($flags[$i] >> 1) & 7, $plan[1] & 7, $name);
        }
    }

    public function test_tv_pipe_plan_examples(): void
    {
        $this->requireFfi(TvPipe::class);
        $this->assertBoth(TvPipe::class, [
            "Show.S03E07.1080p.WEB-DL\t5040\t960\ttv_hd\t10",
            "Show.S02.Complete.720p\t0\t0\tno_match\t10",
            "NFL.2020.Week1.HDTV\t0\t0\tno_match\t0",
            "Movie.2019.BluRay.REMUX\t0\t0\tno_match\t10",
            "Just.Some.Words\t0\t0\tno_match\t10",
            "Anime.S01E02.S02E03\t0\t0\tno_match\t20",
        ], [
            5, 8713, 5010, 7, 1, 5040, (3 << 16) | 7, 0,
            4, 8202, 5040, 0, 2, 5040, 2 << 16, 0,
            4, 132, 5060, 0, 2, 5060, 0, 0,
            4, 4096, 0, 0, 4, 10, 0, 0,
            3, 0, 0, 0, 0, 10, 0, 0,
            3, 8257, 5070, 0, 3, 20, (1 << 16) | 2, 0,
        ]);
        $this->assertResultMask(TvPipe::class, [Category::TV_HD, Category::TV_OTHER, Category::MOVIE_HD, Category::OTHER_MISC]);
        // The first episode token agrees with the Wave 53.3 episode token batch.
        foreach (['Show.S01E02.720p', 'Show.s10e123.x264'] as $name) {
            $word = TvPipe::w53EpisodeTokenBatchPhp([$name])[0];
            $plan = TvPipe::w66TvPipePlanBatchPhp(["{$name}\t\t\t\t"]);
            $this->assertSame([1, ($word >> 2) & 0x3FFF, $word >> 16], [$plan[1] & 1, $plan[6] >> 16, $plan[6] & 0xFFFF], $name);
        }
    }

    /**
     * Numeric limits: 15 digits parse, 16 digits are invalid; thresholds hold exactly at 949/950 and 399/400.
     */
    public function test_boundary_records(): void
    {
        $this->requireFfi(MoviePipe::class);
        $max = '999999999999999';
        $this->assertBoth(MiscSafetyNetPipe::class, [
            str_repeat('a', 15)."\t20\t950\t\t0",
            str_repeat('F', 16)."\t\t\t\t",
            "x\t5000\t399\tx\t{$max}",
            "x\t5000\t400\tx\t1{$max}",
            str_repeat('ab', 5000)."\t{$max}\t1{$max}\t\t",
            "S999E9999.S1000.S1E10000\t10\t1\tgroup_name_\t2",
        ], null);
        foreach ([MoviePipe::class, MusicPipe::class, PcPipe::class, TvPipe::class] as $class) {
            $this->assertBoth($class, [
                "Title.1899.1900.2099.2100\t{$max}\t{$max}\t\t{$max}",
                "Title\t1{$max}\t950\t\t1{$max}",
                "Title\t2999\t949\tx\t3999",
                "S999E9999.S1000.S1E10000.s01e01.S0E0\t5999\t950\t\t4010",
                str_repeat('1080p.', 2000)."\t\t\t\t",
                'V'.str_repeat('9', 40).'.'.str_repeat('X', 17).".99kbit.1000K\t4999\t1\t\t2000",
                "\t\t\t\t\t",
            ], null);
        }
    }

    /**
     * Non-ASCII bytes (valid and invalid UTF-8) never count as ASCII letters, digits, separators or whitespace.
     */
    public function test_unicode_records(): void
    {
        $this->requireFfi(MoviePipe::class);
        $records = [
            "Caf\xc3\xa9.\xc3\x891080p.1080P\xc2\xa0x265\t2040\t\xd9\xa1\t\xc3\xa9\t",
            "\xff\xfe\t\t\t\t",
            "\xef\xbc\xb3\xef\xbc\x90\xef\xbc\x91.S01E02\xc3\xa9.\xc5\xbf01\t\t\t\t",
            "\xe2\x80\xa8FLAC\xed\xa0\x80MP3.Win64\xc2\xa0\t\t\tgroup_name_\xc3\xa9\t2",
            "\xc2\xa0abcdef0123456789\t\t\t\t",
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
        mt_srand(6661 + crc32($class) % 1000);
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
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 66.6 paired benchmark.');
        }
        $this->requireFfi(MoviePipe::class);
        mt_srand(6662);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $safety = $movie = $music = $pc = $tv = [];
            for ($i = 0; $i < $count; $i++) {
                $hash = md5((string) $i);
                $edge = static fn (string $long, string $broken): string => match ($i % 5) {
                    0 => $long,
                    1 => '',
                    2 => $broken,
                    3 => str_repeat("{$hash}.", 4)."{$i}\t{$i}\t999999999999999\thash_{$hash}\t20\textra",
                    default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                };
                $safety[] = $odd
                    ? $edge(' '.str_repeat('Some.Show.S01E02.1080p.x264.', 3)."2019 \t 5000 \t 399 \tgroup_name_tv\t 2 ", "\xff\xfe".str_repeat('xkcdqzrt.', 5)."\xc3\xa9\t-1\t\xc3\xa9\thash_x\t1\textra")
                    : ($i % 3 === 0 ? $hash : 'Some.Release.Name.'.$i.'.'.['1080p.x264', 'FLAC', 'Win64', 'random'][$i % 4])."\t".[5000, 10, 2000, 0][$i % 4]."\t".($i * 59 % 1000)."\t".['group_name_tv', 'no_match', 'movie_hd', 'hash_md5'][$i % 4]."\t".($i % 4);
                $movie[] = $odd
                    ? $edge(' Film.'.str_repeat('German.3D.HSBS.', 4)."2160p.UHD.HEVC.BluRay.REMUX.2019\t 2045 \t999999999999999\tuhd_x\t2999", "\xff\xfeFilm\x01S01E02\xc3\xa9.1080p\t-1\t\xc3\xa9\t\t20\textra")
                    : 'Some.Movie.Title.'.(1990 + $i % 30).'.'.['1080p.BluRay.x264', '2160p.WEB-DL.HEVC', '720p.WEBRip', 'DVDRip.XviD', 'BDMV.BD50'][$i % 5]."-GROUP{$i}\t".[2040, 2045, 2080, 0, 10][$i % 5]."\t".($i * 37 % 1000)."\t".['hd_x', 'uhd_x', 'webdl', 'no_match', 'no_match'][$i % 5]."\t".[10, 0, 2999, 5000, 10][$i % 5];
                $music[] = $odd
                    ? $edge(' Artist-'.str_repeat('Discography.VA.OST.', 4)."Unabridged.M4B.FLAC.24BIT.320kbps.2019\t 3040 \t999999999999999\tmusic_x\t3999", "\xff\xfeShow\x01S01E02\xc3\xa9.1080p.x264\t-1\t\xc3\xa9\t\t20\textra")
                    : 'Artist.Name-Album.Title-'.(1990 + $i % 30).'-'.['FLAC', 'MP3.320kbps', 'WEB.V0', 'CD.FLAC.24BIT', 'Podcast.MP3'][$i % 5]."-GROUP{$i}\t".[3040, 3010, 3010, 0, 10][$i % 5]."\t".($i * 41 % 1000)."\t".['music_flac', 'music_mp3', 'music_x', 'no_match', 'no_match'][$i % 5]."\t".[10, 0, 3999, 5000, 10][$i % 5];
                $pc[] = $odd
                    ? $edge(' App.'.str_repeat('Win64.MacOS.Linux.Android.', 4)."v12.3.Build.4567.Cracked.ISO\t 4010 \t999999999999999\tpc_x\t4010", "\xff\xfeGame\x01PS4\xc3\xa9.1080p.v1\t-1\t\xc3\xa9\t\t20\textra")
                    : 'Software.Suite.'.$i.'.'.['v12.3.Win64', 'MacOS.DMG', 'Android.APK', 'GOG.Game', 'Linux.DEB.x86'][$i % 5]."-GROUP\t".[4010, 4030, 4070, 0, 10][$i % 5]."\t".($i * 43 % 1000)."\t".['pc_0day', 'pc_mac', 'pc_android', 'no_match', 'no_match'][$i % 5]."\t".[10, 0, 4010, 5000, 10][$i % 5];
                $tv[] = $odd
                    ? $edge(' Show.'.str_repeat('NFL.Anime.Docu.German.', 4)."S01E02.S03.2160p.WEB-DL.x265\t 5045 \t999999999999999\ttv_x\t5999", "\xff\xfeMovie\x01BluRay\xc3\xa9.REMUX\t-1\t\xc3\xa9\t\t20\textra")
                    : 'Some.Show.Name.S'.str_pad((string) ($i % 20), 2, '0', STR_PAD_LEFT).'E'.str_pad((string) ($i % 30), 2, '0', STR_PAD_LEFT).'.'.['1080p.WEB-DL.x264', '720p.HDTV', '2160p.WEBRip.x265', 'XviD', 'Complete'][$i % 5]."-GROUP\t".[5040, 5010, 5045, 0, 10][$i % 5]."\t".($i * 47 % 1000)."\t".['tv_hd', 'tv_webdl', 'tv_uhd', 'no_match', 'no_match'][$i % 5]."\t".[10, 0, 5999, 2000, 10][$i % 5];
            }

            $workloads[MiscSafetyNetPipe::class][$name] = static fn (bool $rust) => $rust ? MiscSafetyNetPipe::w66MiscSafetyNetPipePlanBatchNative($safety) : MiscSafetyNetPipe::w66MiscSafetyNetPipePlanBatchPhp($safety);
            $workloads[MoviePipe::class][$name] = static fn (bool $rust) => $rust ? MoviePipe::w66MoviePipePlanBatchNative($movie) : MoviePipe::w66MoviePipePlanBatchPhp($movie);
            $workloads[MusicPipe::class][$name] = static fn (bool $rust) => $rust ? MusicPipe::w66MusicPipePlanBatchNative($music) : MusicPipe::w66MusicPipePlanBatchPhp($music);
            $workloads[PcPipe::class][$name] = static fn (bool $rust) => $rust ? PcPipe::w66PcPipePlanBatchNative($pc) : PcPipe::w66PcPipePlanBatchPhp($pc);
            $workloads[TvPipe::class][$name] = static fn (bool $rust) => $rust ? TvPipe::w66TvPipePlanBatchNative($tv) : TvPipe::w66TvPipePlanBatchPhp($tv);
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
                "\n[Wave 66.6 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
        $this->assertCount(self::WIDTH * \count($records), $php, $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW66RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW66RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /**
     * Mask bits 1-4 agree with evaluateResultPhp() at permille confidences.
     *
     * @param  class-string  $class
     * @param  list<int>  $categories
     */
    private function assertResultMask(string $class, array $categories): void
    {
        $method = self::METHODS[$class].'Php';
        foreach ($categories as $category) {
            foreach ([0, 1, 949, 950, 1000] as $permille) {
                foreach (['no_match', 'x'] as $label) {
                    $plan = $class::{$method}(["\t{$category}\t{$permille}\t{$label}\t10"]);
                    $this->assertSame($class::evaluateResultPhp($category, $permille / 1000, $label), $plan[3] & 7, "{$class} {$category}/{$permille}/{$label}");
                }
            }
        }
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
            MiscSafetyNetPipe::class => ["abc\t5000\t700\tgroup_name_tv\t2", "x.rar\tA\t1", ''],
            MoviePipe::class => ["Some.Movie.2019.1080p.BluRay.x264-GRP\t0\t0\tno_match\t10", "Show.S01E02\tx\t1", ''],
            MusicPipe::class => ["Artist-Album-2019-FLAC\t3040\t900\tmusic_flac\t10", "Pod.320kbps\tx\t1", ''],
            PcPipe::class => ["App.v1.2.Win64.Cracked\t4010\t900\tpc_0day\t10", "Game.PS4\tx\t1", ''],
            TvPipe::class => ["Show.S03E07.1080p.WEB-DL\t5040\t960\ttv_hd\t10", "NFL.HDTV\tx\t1", ''],
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
        $number = static fn (): string => $pick(['', '', ' ', '0', '1', '2', '3', '5', '9', ' 5', '5 ', '00', '007', '-1', '+1', '1.5', 'x', '10', '20', '100', '399', '400', '401', '949', '950', '951', '999', '1000', '2000', '2040', '2999', '3000', '3040', '3999', '4000', '4010', '4999', '5000', '5040', '5999', '6000', '7020', (string) mt_rand(0, 10000), '999999999999999', '1000000000000000', self::randomBytes(3)]);
        $cut = static fn (array $fields): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, 6) : 5);
        $label = static fn (): string => $pick(['', 'no_match', 'hash_md5', 'hash_', 'obfuscated_x', 'gibberish_y', 'group_only_low_signal', 'group_name_tv', 'group_name_', 'tv_hd', 'movie_x', 'music_x', 'pc_x', ' no_match', "x\xc3\xa9", self::randomBytes(4)]);
        $words = ['480p', '576P', '720p', '1080p', '1080i', '2160p', '4320p', '4K', 'UHD', 'x264', 'X265', 'h265', 'HEVC', 'H264', 'XviD', 'DivX', 'DVDRip', '3D', 'SBS', 'HSBS', 'HOU', 'HalfSBS', 'BDMV', 'BD25', 'BD50', 'BD66', 'BD100', 'DVDR', 'DVD5', 'DVD9', 'WEB', 'WEBDL', 'WEBRip', 'German', 'FRENCH', 'spanish', 'Italian', 'Dutch', 'Swedish', 'Nordic', 'Danish', 'Finnish', 'Norwegian', 'Polish', 'Russian', 'Hindi', 'Turkish', 'Portuguese', 'BluRay', 'BDRip', 'BRRip', 'REMUX', 'HDRip', 'CAM', 'HDCAM', 'TELESYNC', 'DVDSCR', 'Screener',
            'FLAC', 'ALAC', 'WAV', 'APE', 'WV', 'AIFF', 'Lossless', 'DSD', 'DSF', 'MP3', 'AAC', 'OGG', 'Opus', 'M4A', 'WMA', 'Vorbis', 'CBR', 'VBR', 'V0', 'V2', '320kbps', '96K', '128KBIT', '1234K', '9K', 'AudioBook', 'AudioBooks', 'M4B', 'Unabridged', 'Abridged', 'Narrated', 'MVID', 'MusicVideo', 'Concert', 'VideoClip', 'Podcast', 'Podcasts', 'Radio', 'Discography', 'VA', 'OST', 'Soundtrack', 'Compilation', 'Anthology',
            'Win', 'Windows', 'Win32', 'Win64', 'WinALL', 'x64', 'X86', 'Mac', 'MacOS', 'MacOSX', 'OSX', 'DMG', 'Linux', 'Ubuntu', 'DEB', 'RPM', 'iOS', 'IPA', 'iPhone', 'iPad', 'Android', 'APK', 'ISO', 'IMG', 'BIN', 'CUE', 'GOG', 'Steam', 'CODEX', 'PLAZA', 'SKIDROW', 'Reloaded', 'FLT', 'DARKSiDERS', 'EMPRESS', 'TENOKE', 'RUNE', 'Game', 'Crack', 'Cracked', 'Keygen', 'Patch', 'Serial', 'Portable', 'Setup', 'Installer', 'MSI', 'EXE', 'Multilingual', 'Retail', 'Build', 'v1', 'V12', 'v', 'vX1', 'PS4', 'PS5', 'XBOX', 'XBOX360', 'X360', 'NSW', 'Switch', 'Wii', 'WiiU', '3DS', 'NDS', 'EPUB', 'MOBI', 'PDF', 'CBZ',
            'SDTV', 'HDTV', 'PDTV', 'DSR', 'TVRip', 'Anime', 'FanSub', 'HorribleSubs', 'SubsPlease', 'Erai-raws', 'EraiRaws', 'NFL', 'NBA', 'NHL', 'MLB', 'UFC', 'WWE', 'F1', 'Formula1', 'EPL', 'UEFA', 'Sport', 'Sports', 'Boxing', 'MotoGP', 'Docu', 'Documentary', 'Documentaries', 'NatGeo', 'Discovery',
            'S01E02', 's1', 'S123', 'S1E1234', 'S1234', 'S01E', 'S1E12345', 'S0E0', 'Season', 'Title', 'Name', '2019', '1899', '1900', '2099', '2100', '0001', 'GROUP', 'x', "\xc3\xa9", "\xff", str_repeat('Q', mt_rand(1, 20))];
        $subject = static fn (): string => $pick(['', ' ', md5((string) mt_rand()), strtoupper(sha1((string) mt_rand())), substr(md5((string) mt_rand()), 0, mt_rand(10, 20)), ' '.md5((string) mt_rand()).' ', ''])
            .$join($pick(['.', '-', '_', ' ', '..', '.', '.']), 8, static fn (): string => mt_rand(0, 9) === 0 ? self::randomBytes(4) : $pick($words));

        return implode("\t", $cut([$subject(), $number(), $number(), $label(), $number(), 'extra']));
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
        $ffi = RustPipelineLibrary::resolveDomain('w66_6', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library with the Wave 66.6 helpers is unavailable.');
        }

        return $ffi;
    }
}
