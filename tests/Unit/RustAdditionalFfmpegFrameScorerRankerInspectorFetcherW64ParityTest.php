<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Media\FfmpegFrameGrabber;
use App\Services\AdditionalProcessing\Media\FrameQualityScorer;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;
use App\Services\AdditionalProcessing\Media\ImageInspector;
use App\Services\AdditionalProcessing\Media\MediaArticleFetcher;
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
class RustAdditionalFfmpegFrameScorerRankerInspectorFetcherW64ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        FfmpegFrameGrabber::class => 'w64FfmpegGrabberPlanBatch',
        FrameQualityScorer::class => 'w64FrameQualityPlanBatch',
        ImageCandidateRanker::class => 'w64ImageRankerPlanBatch',
        ImageInspector::class => 'w64ImageInspectorPlanBatch',
        MediaArticleFetcher::class => 'w64ArticleFetcherPlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        FfmpegFrameGrabber::class => ['W64_FFMPEG_GRABBER_PLAN_FFI_CDEF', 'nntmux_w645_ffmpeg_grabber_plan'],
        FrameQualityScorer::class => ['W64_FRAME_QUALITY_PLAN_FFI_CDEF', 'nntmux_w645_frame_quality_plan'],
        ImageCandidateRanker::class => ['W64_IMAGE_RANKER_PLAN_FFI_CDEF', 'nntmux_w645_image_ranker_plan'],
        ImageInspector::class => ['W64_IMAGE_INSPECTOR_PLAN_FFI_CDEF', 'nntmux_w645_image_inspector_plan'],
        MediaArticleFetcher::class => ['W64_ARTICLE_FETCHER_PLAN_FFI_CDEF', 'nntmux_w645_article_fetcher_plan'],
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        FfmpegFrameGrabber::class => 10,
        FrameQualityScorer::class => 10,
        ImageCandidateRanker::class => 10,
        ImageInspector::class => 9,
        MediaArticleFetcher::class => 10,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        FfmpegFrameGrabber::class => true,
        FrameQualityScorer::class => true,
        ImageCandidateRanker::class => true,
        ImageInspector::class => true,
        MediaArticleFetcher::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `RUN_RUST_FFI_BENCHMARKS=1` runs (100 samples per workload, seed 6452, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        FfmpegFrameGrabber::class => [6.75, 6.69, 6.60, 5.54],
        FrameQualityScorer::class => [3.40, 3.47, 6.10, 6.02],
        ImageCandidateRanker::class => [3.20, 3.32, 2.62, 2.64],
        ImageInspector::class => [3.05, 2.80, 1.97, 1.95],
        MediaArticleFetcher::class => [6.92, 6.02, 13.04, 10.91],
    ];

    /**
     * Properties before Wave 64.5; the wave keeps its state in function-local statics.
     *
     * @var array<class-string, list<string>>
     */
    private const PROPERTIES = [
        FfmpegFrameGrabber::class => ['w43RustFfi', 'ffi', 'ffiResolved', 'tools', 'debugMode'],
        FrameQualityScorer::class => ['w43RustFfi', 'ffi', 'ffiResolved', 'statsOut', 'meanOut', 'stddevOut', 'bmpStream', 'minStddev', 'minLuma', 'maxLuma', 'w51RustFfi', 'w51Ffi', 'w51Out', 'w51Capacity'],
        ImageCandidateRanker::class => ['w43RustFfi', 'ffi', 'ffiResolved', 'w51RustFfi', 'w51Ffi', 'w51Out', 'w51Capacity'],
        ImageInspector::class => ['w44RustFfi', 'ffi', 'ffiResolved', 'scorer', 'minStddev'],
        MediaArticleFetcher::class => ['useRustFfi', 'ffi', 'downloadService', 'w52RustFfi', 'w52Ffi', 'w52Out', 'w52Capacity'],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 100;

    private const BYTES = ['a', 'Z', 'x', 'F', 'f', '0', '1', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '+', '_', '.', ',', ':', ';', '/', '\\', '|', '"', "'", "\xc3\xa9", "\xc3", "\xff", "\xa0", "\x85", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", '|', '||', ':', '::', ' ', '.', '..', '/', '\\', '"', '""', '-', '_', '0', '1', '9', '00', 'ff', 'FF', 'ffd9', 'FFD9', '49454e44', '3b', 'g', '150', '1280', '999999999999999', '1000000000000000', 'ok', 'retry', 'alt', 'missing', 'mov', 'matroska', 'screens', 'cover', 'back', 'cd1', 'contact', 'sheets', "\x0c", "\x01", "\xc3\xa9"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            $class::resetW64RustFfi();
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
        foreach (['enableW64RustFfi', 'disableW64RustFfi', 'resetW64RustFfi', 'usesW64RustFfi', 'withW64RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w64RustFfiState')->isPrivate(), "{$class} keeps its W64 state private");
        $this->assertTrue((new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->isPrivate(), "{$class} keeps its W64 declaration private");
        $constant = new ReflectionClassConstant($class, 'W64_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), "{$class} routes by its default (PHP when it did not qualify)");

        $class::enableW64RustFfi();
        $this->assertTrue($class::usesW64RustFfi());
        $class::disableW64RustFfi();
        $this->assertFalse($class::usesW64RustFfi());
        $class::resetW64RustFfi();
        $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), "{$class} reset restores the default");

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW64RustFfi($enabled, static fn () => [$class::usesW64RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), "{$class} must restore after the callback");
        }
        $class::disableW64RustFfi();
        $this->assertTrue($class::withW64RustFfi(true, static fn () => $class::usesW64RustFfi()));
        $this->assertFalse($class::usesW64RustFfi(), "{$class} restores an explicit override, not the default");
        $class::resetW64RustFfi();
        $nested = $class::withW64RustFfi(false, static fn () => [$class::withW64RustFfi(true, static fn () => $class::usesW64RustFfi()), $class::usesW64RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW64RustFfi(! $class::usesW64RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), "{$class} must restore after an exception");

        $class::withW64RustFfi(! $class::usesW64RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW64RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w64_state_adds_no_properties(): void
    {
        foreach (self::PROPERTIES as $class => $expected) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w64', $property->getName(), $class);
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
            FfmpegFrameGrabber::usesRustFfi(),
            FfmpegFrameGrabber::usesW49RustFfi(),
            FfmpegFrameGrabber::usesW56RustFfi(),
            FrameQualityScorer::usesRustFfi(),
            FrameQualityScorer::usesW51RustFfi(),
            ImageCandidateRanker::usesRustFfi(),
            ImageCandidateRanker::usesW51RustFfi(),
            ImageInspector::usesRustFfi(),
            ImageInspector::usesW52RustFfi(),
            MediaArticleFetcher::usesRustFfi(),
            MediaArticleFetcher::usesW45RustFfi(),
            MediaArticleFetcher::usesW52RustFfi(),
        ];
        $before = $snapshot();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW64RustFfi($enabled, $snapshot), $class);
            }
            $class::enableW64RustFfi();
            $class::resetW64RustFfi();
            $this->assertSame($before, $snapshot(), $class);
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            FfmpegFrameGrabber::class => ['__construct', 'useRustFfi', 'usesRustFfi', 'grab', 'probe', 'clockSeconds', 'clockSecondsPhp', 'clockSecondsNative', 'usesW49RustFfi', 'withW49RustFfi', 'parseClockBatch', 'parseClockBatchPhp', 'parseClockBatchNative', 'classifyFrameBatch', 'classifyFrameBatchPhp', 'classifyFrameBatchNative', 'withW56RustFfi', 'usesW56RustFfi'],
            FrameQualityScorer::class => ['__construct', 'useRustFfi', 'usesRustFfi', 'best', 'bestNative', 'measure', 'measurePhp', 'measureNative', 'measureSample', 'measureSampleNative', 'measureSamplePhp', 'measureRgb', 'measureRgbNative', 'measureRgbPhp', 'scoreFrameBatch', 'scoreFrameBatchPhp', 'scoreFrameBatchNative', 'withW51RustFfi', 'usesW51RustFfi'],
            ImageCandidateRanker::class => ['useRustFfi', 'usesRustFfi', 'rank', 'rankNative', 'rankPhp', 'sort', 'sortPhp', 'fileName', 'rankCandidateBatch', 'rankCandidateBatchPhp', 'rankCandidateBatchNative', 'withW51RustFfi', 'usesW51RustFfi'],
            ImageInspector::class => ['__construct', 'useRustFfi', 'usesRustFfi', 'isUsable', 'isUsableNative', 'isComplete', 'isCompleteTail', 'isCompletePhp', 'isCompleteNative', 'isCompleteTailNative', 'isCompleteTailPhp', 'usesW52RustFfi', 'withW52RustFfi', 'headerProbeBatch', 'headerProbeBatchPhp', 'headerProbeBatchNative'],
            MediaArticleFetcher::class => ['__construct', 'useW45RustFfi', 'usesW45RustFfi', 'setUseRustFfi', 'usesRustFfi', 'append', 'isSafeWitnessName', 'isSafeWitnessNamePhp', 'isSafeWitnessNameNative', 'chunkLength', 'chunkLengthPhp', 'chunkLengthNative', 'segmentStart', 'segmentStartPhp', 'segmentStartNative', 'planAppendBatch', 'planAppendBatchPhp', 'planAppendBatchNative', 'withW52RustFfi', 'usesW52RustFfi'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        foreach ([
            [FfmpegFrameGrabber::class, 'W43_RUST_FFI_DEFAULT'],
            [FrameQualityScorer::class, 'W43_RUST_FFI_DEFAULT'],
            [ImageCandidateRanker::class, 'W43_RUST_FFI_DEFAULT'],
            [ImageInspector::class, 'W44_RUST_FFI_DEFAULT'],
            [MediaArticleFetcher::class, 'W45_RUST_FFI_DEFAULT'],
        ] as [$class, $constant]) {
            $this->assertFalse((new ReflectionClassConstant($class, $constant))->getValue(), "{$class}::{$constant}");
        }

        $earlier = static fn (): array => [
            FfmpegFrameGrabber::clockSecondsPhp('01:02:03.5'),
            FfmpegFrameGrabber::parseClockBatchPhp(['00:00:01', 'x']),
            FfmpegFrameGrabber::classifyFrameBatchPhp(['/t/frame_0a0b0c0d_01.jpg'], [10], 0, 1000),
            FrameQualityScorer::measureRgbPhp("\x10\x20\x30\xf0\xe0\xd0"),
            FrameQualityScorer::scoreFrameBatchPhp(["\x10\x20\x30\xf0\xe0\xd0", ''], 5.0, 20.0, 230.0),
            ImageCandidateRanker::rankPhp('CD1.screens.jpg'),
            ImageCandidateRanker::rankCandidateBatchPhp(['cover.jpg', 'back.jpg'], [10, 20]),
            ImageInspector::isCompleteTailPhp(IMAGETYPE_JPEG, 100, "abc\xFF\xD9"),
            ImageInspector::headerProbeBatchPhp(["\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR\0\0\x01\0\0\0\x02\0"]),
            MediaArticleFetcher::chunkLengthPhp(10, 4),
            MediaArticleFetcher::segmentStartPhp(5, 2, 0),
            MediaArticleFetcher::planAppendBatchPhp([10, 10], [0, 10], ['a.mkv', 'a.mkv'], 0, 0, 15),
        ];
        $expected = $earlier();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW64RustFfi($enabled, $earlier), $class);
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
        $this->assertSame($php, $class::withW64RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW64RustFfi(true, static fn () => $class::{$method}($records)), $class);
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

    public function test_ffmpeg_grabber_plan_examples(): void
    {
        $this->requireFfi(FfmpegFrameGrabber::class);
        $this->assertBoth(FfmpegFrameGrabber::class, [
            "5400000\t1920\t1080\t6\t60000\t300000\tmatroska",
            '',
            "01:30:00.5\t1280\t721\t4\t\t\tmov",
            "100000\t640\t480\t0\t99000\t50\tmp4\textra",
            "x\t\t7\t65\t5\t2\t MOV ",
        ], [
            5400000, 60000, 300000, 6, 1560000, 1280, 720, 1, 21, 0,
            0, 0, 0, 1, 0, 0, 0, 0, 19, 34,
            5400500, 0, 1350125, 4, 4050375, 1280, 720, 5, 21, 64,
            100000, 90000, 50, 1, 90000, 640, 480, -1, 19, 30,
            0, 5, 2, 64, 131, 0, 0, -1, 19, 45,
        ]);
    }

    public function test_frame_quality_plan_examples(): void
    {
        $this->requireFfi(FrameQualityScorer::class);
        $this->assertBoth(FrameQualityScorer::class, [
            "2\t2\t1040c0f0",
            '',
            "4\t4\t".str_repeat('00', 4).'40806020'.'60208040'.str_repeat('00', 4),
            "2\t2\t000000",
            "1\t2\t8080",
            "2\t1\t0G00",
            "3\t1\t".bin2hex("\x00\x00\x00"),
            "2\t2\tFFFFF0F0",
        ], [
            0, 128000, 91000, 224, 4, 0, 0, 5, (5 << 32) | 91000, 0,
            1, 0, 0, 0, 0, 0, 0, 0, 0, 2,
            0, 40000, 47312, 128, 5, 2, 0, 4, (4 << 32) | 47312, 0,
            3, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 128000, 0, 0, 1, 0, 0, 1, 1 << 32, 0,
            2, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 1, 1, 3, 1, 1 << 32, 0,
            0, 247500, 7500, 15, 2, 0, 0, 1, 1 << 32 | 7500, 4,
        ]);
    }

    public function test_image_ranker_plan_examples(): void
    {
        $this->requireFfi(ImageCandidateRanker::class);
        $this->assertBoth(ImageCandidateRanker::class, [
            "Movie.2024.screens.jpg\t1920\t1080\t512000\t",
            '',
            "[1/5] - \"CD1.Cover.JPEG\" yEnc\t100\t900\t0\t",
            "C:\\rel\\contact-sheets.png\t70000\t\t2048\tcover",
            "dir/sheets.webp/\t640\t480\tx\tbogus\textra",
        ], [
            0, 0, 1, 1920, 1080, 3, 512000, 246, (3 << 50) | (3 << 48) | (1920 * 1080 << 16) | 500, 0,
            2, 0, 0, 0, 0, 0, 0, 0, 1 << 50, 18,
            3, 0, 2, 100, 900, 1, 0, 0, (1 << 48) | (90000 << 16), 32,
            1, 1, 3, 65535, 0, 0, 2048, 0, (2 << 50) | 2, 4,
            2, 0, 5, 640, 480, 3, 0, 0, (1 << 50) | (3 << 48) | (307200 << 16), 11,
        ]);
    }

    public function test_image_inspector_plan_examples(): void
    {
        $this->requireFfi(ImageInspector::class);
        $png = bin2hex("\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR".pack('N2', 800, 600)."\x08\x02\0\0\0");
        $jpeg = bin2hex("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xC0\x00\x11\x08".pack('n2', 120, 160)."\x03");
        $gif = bin2hex('GIF89a'.pack('v2', 320, 240));
        $webp = bin2hex('RIFF'.pack('V', 5000).'WEBPVP8X'.pack('V', 10)."\0\0\0\0".substr(pack('V', 399), 0, 3).substr(pack('V', 299), 0, 3));
        $this->assertBoth(ImageInspector::class, [
            "4096\t{$png}\t".bin2hex("data\0\0\0\0IEND\xae\x42\x60\x82\r\n"),
            '',
            "2048\t{$jpeg}\t".bin2hex("....\xFF\xD9"),
            "999\t{$gif}\t3b0a",
            "5008\t".strtoupper($webp)."\t",
            "5007\t{$webp}\t",
            "10\t{$png}\tzz",
            "9000\t".substr($png, 0, 32)."\t49454e44",
        ], [
            3, 800, 600, 1, 1, 480000, 1, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 1, 2,
            2, 160, 120, 1, 0, 19200, 0, 4, 0,
            1, 320, 240, 1, 1, 76800, 0, 2, 0,
            18, 400, 300, 1, 1, 120000, 1, 0, 16,
            18, 400, 300, 0, 1, 120000, 0, 5, 0,
            3, 800, 600, 0, 1, 480000, 0, 6, 8,
            3, 0, 0, 1, 0, 0, 0, 3, 0,
        ]);
    }

    public function test_article_fetcher_plan_examples(): void
    {
        $this->requireFfi(MediaArticleFetcher::class);
        $this->assertBoth(MediaArticleFetcher::class, [
            "0\t2500\t0\t0\t1000|1000:retry|1000:alt|1000",
            '',
            "1\t10000\t2500\t1000\t700|800:ok|900|1000",
            "0\t5000\t\t\t500|x:missing|500",
            "7\t100\t\t\t1|2",
            "0\t\t\t\t1|2|3",
            "0\t9\t\t\t5:bogus\textra",
        ], [
            4, 0, 3, 2500, 3000, 6, 2, 1, 1, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 2,
            4, 1, 3, 1700, 1700, 2, 0, 0, 2, 0,
            3, 0, 1, 500, 500, 4, 1, 0, 3, 1,
            2, 7, 7, 0, 0, 0, 0, 0, 0, 4,
            3, 3, 3, 0, 0, 0, 0, 0, 0, 0,
            1, 0, 0, 0, 0, 3, 1, 0, 3, 10,
        ]);
    }

    /**
     * Numeric limits: 15 digits parse, 16 digits are invalid, and pixel and dimension caps hold exactly.
     */
    public function test_boundary_records(): void
    {
        $this->requireFfi(FfmpegFrameGrabber::class);
        $max = '999999999999999';
        $this->assertBoth(FfmpegFrameGrabber::class, [
            "{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\togg",
            "1{$max}\t1281\t2\t64\t\t\t",
            "9999999:59:59.9999\t1\t1\t2\t\t\tasf",
        ], null);
        $this->assertBoth(FrameQualityScorer::class, [
            "1048576\t1\t",
            "1024\t1025\tab",
            "1048577\t1\tab",
            "1{$max}\t1\tab",
        ], [
            2, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            1, 0, 0, 0, 0, 0, 0, 0, 0, 8,
            1, 0, 0, 0, 0, 0, 0, 0, 0, 8,
            1, 0, 0, 0, 0, 0, 0, 0, 0, 1,
        ]);
        $this->assertBoth(ImageCandidateRanker::class, [
            "a.jpg\t65535\t65535\t{$max}\t",
            "a.jpg\t65536\t{$max}\t1{$max}\t",
        ], null);
        $this->assertBoth(ImageInspector::class, [
            "{$max}\t".bin2hex("\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR\xff\xff\xff\xff\xff\xff\xff\xff")."\t",
            "16\t".bin2hex('RIFF'.pack('V', 8).'WEBP')."\t",
        ], [
            3, 4294967295, 4294967295, 0, 1, 1 << 62, 0, 5, 32,
            18, 0, 0, 1, 0, 0, 0, 3, 0,
        ]);
        $this->assertBoth(MediaArticleFetcher::class, [
            "0\t{$max}\t\t\t".implode('|', array_fill(0, 6000, $max)),
            "0\t{$max}\t{$max}\t{$max}\t1|1000000000000000",
        ], [
            6000, 0, 1, (int) $max, (int) $max, 1, 0, 0, 1, 0,
            2, 0, 1, 1, 1, 1, 0, 0, 2, 1,
        ]);
    }

    /**
     * Non-ASCII bytes (valid and invalid UTF-8) never count as ASCII letters, digits, separators or whitespace.
     */
    public function test_unicode_records(): void
    {
        $this->requireFfi(ImageCandidateRanker::class);
        $this->assertBoth(ImageCandidateRanker::class, [
            "\xc3\xa9cran.screens.jpg\t\t\t\t",
            "caf\xc3\xa9cover.jpg\t\t\t\t",
            "\xffback\xff.png\t\t\t\tCONTENT",
            "\"\xc3\xa9t\xc3\xa9/Poster.JPG\" yEnc\t300\t300\t\xc3\xa9\t\xc2\xa0",
        ], null);
        $this->assertBoth(FfmpegFrameGrabber::class, ["\xc3\xa9\t\xc2\xa0\t1\t1\t1\t1\tmatroska\xc2\xa0"], null);
        $this->assertBoth(MediaArticleFetcher::class, ["0\t10\t\t\t1:\xc3\xa9|\xd9\xa1"], null);
        $this->assertBoth(FrameQualityScorer::class, ["1\t1\t\xc3\xa9"], null);
        $this->assertBoth(ImageInspector::class, ["100\t\xc3\xa9\t\xff"], null);
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
        $this->assertSame($class::{$method.'Php'}($records), $class::withW64RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        mt_srand(6451 + crc32($class) % 1000);
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
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 64.5 paired benchmark.');
        }
        $this->requireFfi(FfmpegFrameGrabber::class);
        mt_srand(6452);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $ffmpeg = $frames = $ranker = $inspector = $fetcher = [];
            for ($i = 0; $i < $count; $i++) {
                $file = substr(md5((string) $i), 0, 8);

                $ffmpeg[] = $odd
                    ? match ($i % 5) {
                        0 => ' '.str_pad((string) ($i % 100), 2, '0', STR_PAD_LEFT).':'.str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT).':59.'.str_repeat('9', 12)." \t 3840 \t 2160 \t999\t999999999999999\t1\t MATROSKA ",
                        1 => '',
                        2 => "\xc3\xa9{$i}\t-1\t\xff\t\t\t\tbogus\textra",
                        3 => "999999999999999\t1281\t1\t64\t0\t0\tmpegts",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : (600_000 + $i * 9_000)."\t".[1920, 1280, 3840, 720][$i % 4]."\t".[1080, 720, 1600, 576][$i % 4]."\t".(4 + $i % 4)."\t".(30_000 + $i * 10)."\t".(60_000 + $i % 7 * 1000)."\t".['matroska', 'mov', 'avi', 'mpegts'][$i % 4];

                $frames[] = $odd
                    ? match ($i % 5) {
                        0 => "24\t24\t".strtoupper(self::lumaHex(24, 24, $i, 3)),
                        1 => '',
                        2 => "16\t16\t".self::lumaHex(16, 16, $i, 0).'g0',
                        3 => "32\t24\t".self::lumaHex(32, 24, $i, 4),
                        default => "40\t30\t".self::lumaHex(40, 30, $i, 5),
                    }
                : "32\t24\t".self::lumaHex(32, 24, $i, $i % 3);

                $ranker[] = $odd
                    ? match ($i % 5) {
                        0 => "[{$i}/40] - \"Some.Very.Long.Release.Name.2024.1080p.BluRay.x264-GROUP.CD1.Contact Sheets.PNG\" yEnc (1/3)\t 999999 \t 1 \t999999999999999\t\xc2\xa0",
                        1 => '',
                        2 => "C:\\Users\\x\\\xc3\xa9t\xc3\xa9\\Cover.Back.Spine.jpg\\\\\t-1\t\xff\t\t COVER ",
                        3 => 'dir/sub/dir/'.str_repeat('poster-', 12)."{$file}.webp\t65536\t65536\t".($i * 1000)."\tbogus\textra",
                        default => "\"\"\"{$file}\"\"\t{$i}\t{$i}\t{$i}\t",
                    }
                : "Movie.{$file}.2024.".['screens', 'cover', 'proof', 'nfo', 'thumbs', 'front'][$i % 6].'.'.['jpg', 'png', 'jpeg', 'webp'][$i % 4]."\t".(800 + $i % 9 * 160)."\t".(600 + $i % 5 * 90)."\t".(40_000 + $i * 731)."\t";

                $inspector[] = $odd
                    ? match ($i % 5) {
                        0 => self::imageRecord($i, true),
                        1 => '',
                        2 => "x{$i}\t\xc3\xa9\t".str_repeat('f', 4097),
                        3 => "999999999999999\t".strtoupper(bin2hex('GIF89a'.pack('v2', $i, $i + 7)))."\t".str_repeat('00', 2048),
                        default => "{$i}\t{$i}\t{$i}",
                    }
                : self::imageRecord($i, false);

                $fetcher[] = $odd
                    ? match ($i % 5) {
                        0 => " 0 \t 999999999999999 \t 999999999999999 \t 750000 \t".implode('|', array_map(static fn (int $n): string => (string) (700_000 + $n).['', ':ok', ':retry', ':alt'][$n % 4], range(0, 199))),
                        1 => '',
                        2 => "x\t\xc3\xa9\t-1\t\t|||:missing|\xff:alt|9999999999999999:retry\textra",
                        3 => "500\t1000\t\t\t".implode('|', array_fill(0, 40, '1:bogus')),
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ($i % 3)."\t".(2_000_000 + $i * 1000)."\t".(30_000_000 + $i * 5000)."\t750000\t".implode('|', array_map(static fn (int $n): string => (string) (740_000 + ($n * 37 + $i) % 9000).($n % 11 === 7 ? ':retry' : ($n % 29 === 13 ? ':alt' : '')), range(0, 15 + $i % 10)));
            }

            $workloads[FfmpegFrameGrabber::class][$name] = static fn (bool $rust) => $rust ? FfmpegFrameGrabber::w64FfmpegGrabberPlanBatchNative($ffmpeg) : FfmpegFrameGrabber::w64FfmpegGrabberPlanBatchPhp($ffmpeg);
            $workloads[FrameQualityScorer::class][$name] = static fn (bool $rust) => $rust ? FrameQualityScorer::w64FrameQualityPlanBatchNative($frames) : FrameQualityScorer::w64FrameQualityPlanBatchPhp($frames);
            $workloads[ImageCandidateRanker::class][$name] = static fn (bool $rust) => $rust ? ImageCandidateRanker::w64ImageRankerPlanBatchNative($ranker) : ImageCandidateRanker::w64ImageRankerPlanBatchPhp($ranker);
            $workloads[ImageInspector::class][$name] = static fn (bool $rust) => $rust ? ImageInspector::w64ImageInspectorPlanBatchNative($inspector) : ImageInspector::w64ImageInspectorPlanBatchPhp($inspector);
            $workloads[MediaArticleFetcher::class][$name] = static fn (bool $rust) => $rust ? MediaArticleFetcher::w64ArticleFetcherPlanBatchNative($fetcher) : MediaArticleFetcher::w64ArticleFetcherPlanBatchPhp($fetcher);
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
                "\n[Wave 64.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW64RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW64RustFfi(), $class);
            if ($class::usesW64RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW64RustFfi(), "{$class} retains PHP by default");
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
        $this->assertSame($php, $class::withW64RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW64RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /**
     * Hex luma plane of a $width x $height frame: 0 noise, 1 gradient, 2 flat, 3 letterbox, 4 pillarbox,
     * 5 dark with a lit centre.
     */
    private static function lumaHex(int $width, int $height, int $seed, int $pattern): string
    {
        $pixels = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $noise = ($x * 73 + $y * 151 + $seed * 31) % 256;
                $luma = match ($pattern) {
                    0 => $noise,
                    1 => intdiv(($x + $y) * 255, $width + $height),
                    2 => 90 + $seed % 40,
                    3 => $y < intdiv($height, 6) || $y >= $height - intdiv($height, 6) ? $noise % 17 : $noise,
                    4 => $x < intdiv($width, 5) || $x >= $width - intdiv($width, 5) ? 0 : $noise,
                    default => abs($x - intdiv($width, 2)) < 4 && abs($y - intdiv($height, 2)) < 4 ? 200 + $noise % 50 : $noise % 12,
                };
                $pixels .= \chr($luma);
            }
        }

        return bin2hex($pixels);
    }

    /** One inspector record: a PNG, JPEG, GIF or WebP head with a complete or truncated tail. */
    private static function imageRecord(int $i, bool $odd): string
    {
        $width = 120 + ($i * 37) % 2000;
        $height = 90 + ($i * 53) % 1500;
        $head = match ($i % 4) {
            0 => "\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR".pack('N2', $width, $height)."\x08\x02\0\0\0",
            1 => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB\x00\x05\x00\x00\x00\xFF\xC0\x00\x11\x08".pack('n2', $height, $width)."\x03",
            2 => 'GIF89a'.pack('v2', $width, $height),
            default => 'RIFF'.pack('V', 4000 + $i).'WEBPVP8 '.pack('V', 100)."\0\0\0\x9d\x01\x2a".pack('v2', $width, $height),
        };
        $body = str_repeat(substr(md5((string) $i, true), 0, 16), $odd ? 255 : 24);
        $tail = $body.match ($i % 4) {
            0 => $i % 5 === 0 ? 'IEN' : "IEND\xae\x42\x60\x82",
            1 => $i % 5 === 0 ? "\xFF" : "\xFF\xD9\0\0\r\n",
            2 => ';',
            default => '',
        };
        $hex = bin2hex($tail);

        return (5000 + $i)."\t".bin2hex($head)."\t".($odd ? strtoupper($hex) : $hex);
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
            FfmpegFrameGrabber::class => ["5400000\t1920\t1080\t6\t60000\t300000\tmatroska", "x\t5\t5\t9\t3", ''],
            FrameQualityScorer::class => ["2\t2\t1040c0f0", "2\t2\t0g", ''],
            ImageCandidateRanker::class => ["Movie.screens.jpg\t1920\t1080\t512000\t", "\"cover.png\"\tx\t1", ''],
            ImageInspector::class => ["4096\t".bin2hex("\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR".pack('N2', 800, 600))."\t".bin2hex('IEND'), "x\tzz\t", ''],
            MediaArticleFetcher::class => ["0\t2500\t0\t0\t1000|1000:retry|1000:alt", "x\t5\t\t\t1:missing", ''],
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
        $number = static fn (): string => $pick(['', ' ', '0', '1', '2', '3', '4', '5', '9', '10', ' 5', '5 ', '00', '007', '-1', '+1', '1.5', 'x', '63', '64', '65', '149', '150', '640', '1279', '1280', '1281', '1920', '65535', '65536', '1048576', '1048577', (string) mt_rand(0, 1_000_000_000), (string) mt_rand(0, 100_000), '999999999999999', '1000000000000000', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);
        $hex = static function (string $bytes) use ($pick): string {
            $hex = bin2hex($bytes);

            return match (mt_rand(0, 9)) {
                0 => strtoupper($hex),
                1 => substr($hex, 0, -1),
                2 => $hex.$pick(['g', 'G', ' ', '0', "\xc3\xa9"]),
                default => $hex,
            };
        };

        return match ($class) {
            FfmpegFrameGrabber::class => implode("\t", $cut([
                $pick(['', ' ', '0', '5400000', (string) mt_rand(1, 10_000_000), '01:30:00', '1:30:00.5', '0:00:10.123456', '12345678:00:00', '1234567:00:00', '1:2:3', '00:00:00', ' 01:00:00 ', '9999999:59:59.999', '1:30', '1:30:00.', '1:30:00.x', '1:99:99', 'x', '999999999999999', '1000000000000000', self::randomBytes(6)]),
                $number(), $number(), $number(), $number(), $number(),
                $pick(['', ' ', 'matroska', 'MATROSKA', 'mov', ' mov ', 'mp4', 'avi', 'mpeg', 'mpegts', 'asf', 'ogg', 'bogus', self::randomBytes(4)]),
                'extra',
            ], 7)),
            FrameQualityScorer::class => (static function () use ($cut, $hex, $number, $pick): string {
                [$width, $height] = mt_rand(0, 4) === 0 ? [$number(), $number()] : [(string) mt_rand(1, 12), (string) mt_rand(1, 12)];
                $count = max(0, min((int) $width * (int) $height, 4096));
                $pixels = '';
                $pattern = mt_rand(0, 3);
                for ($at = 0; $at < $count; $at++) {
                    $pixels .= \chr(match ($pattern) {
                        0 => mt_rand(0, 255),
                        1 => mt_rand(0, 20),
                        2 => mt_rand(0, 1) === 0 ? 0 : mt_rand(0, 255),
                        default => (int) $pick([0, 16, 17, 128, 255]),
                    });
                }

                return implode("\t", $cut([$width, $height, mt_rand(0, 9) === 0 ? self::randomBytes(8) : $hex($pixels), 'extra'], 3));
            })(),
            ImageCandidateRanker::class => implode("\t", $cut([
                ($pick(['', '', 'dir/', 'C:\\x\\', '/a/b/', '"', '[1/2] "', 'x"" "'])).$join($pick(['.', ' ', '_', '-', '', '1', '/', '\\', "\xc3\xa9"]), 4, static fn (): string => $pick(['screens', 'Screen', 'screenshots', 'caps', 'contact', 'Contact', 'sheet', 'sheets', 'contactsheets', 'thumbs', 'preview', 'samples', 'cover', 'Front', 'poster', 'folder', 'box', 'keyart', 'back', 'rear', 'cd', 'CD1', 'dvd2', 'disc', 'label', 'inlay', 'proof', 'logo', 'banner', 'sig', 'signature', 'spine', 'covers', 'xcover', 'movie', '2024', "\xc3\xa9cran", self::randomBytes(4)])).$pick(['', '.jpg', '.JPEG', '.png', '.gif', '.webp', '.bmp', '.nfo', '.', '/', '\\\\', '" yEnc', '"', ' ']),
                $number(), $number(), $number(),
                $pick(['', '', ' ', 'content', 'COVER', ' other ', 'least', 'Least', 'bogus', self::randomBytes(3)]),
                'extra',
            ], 5)),
            ImageInspector::class => implode("\t", $cut([
                $number(),
                $hex(match (mt_rand(0, 8)) {
                    0 => "\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR".pack('N2', mt_rand(0, 3000), mt_rand(0, 3000)).substr("\x08\x02\0\0\0", 0, mt_rand(0, 5)),
                    1 => substr("\x89PNG\r\n\x1a\n\0\0\0\x0dIHDR\0\0\x01", 0, mt_rand(0, 19)),
                    2 => "\xFF\xD8\xFF".$pick(["\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00", "\xFF\x01", "\xD0", '', "\xFE\x00\x01"])."\xFF".$pick(["\xC0", "\xC2", "\xC4", "\xC8", "\xDA", "\xD9", "\xCF"])."\x00\x11\x08".pack('n2', mt_rand(0, 3000), mt_rand(0, 3000)).substr("\x03", 0, mt_rand(0, 1)),
                    3 => $pick(['GIF89a', 'GIF87a', 'GIF88a']).substr(pack('v2', mt_rand(0, 3000), mt_rand(0, 3000)), 0, mt_rand(0, 4)),
                    4 => 'RIFF'.pack('V', mt_rand(0, 9000)).'WEBP'.$pick(['VP8 ', 'VP8L', 'VP8X', 'ALPH']).pack('V', 10).$pick(["\0\0\0\x9d\x01\x2a", "\x2f", "\0\0\0\0", '']).pack('v2', mt_rand(0, 65535), mt_rand(0, 65535)).pack('V', mt_rand(0, 2_000_000_000)),
                    5 => substr('RIFF'.pack('V', 100).'WEBP', 0, mt_rand(0, 12)),
                    default => self::randomBytes(16),
                }),
                $hex(self::randomBytes(mt_rand(0, 1) === 0 ? 8 : 40).$pick(['', 'IEND', "IEND\xae\x42\x60\x82", "\xFF\xD9", "\xFF\xD9\0\0", "\xFF\xD9\r\n ", ';', ";\n", 'IEN', "\xFF", 'IEND'.str_repeat('x', 61), "\xFF\xD9".str_repeat("\0", 5000), "\xFF\xD9".str_repeat('y', 1023), "\xFF\xD9".str_repeat('y', 1022)])),
                'extra',
            ], 3)),
            MediaArticleFetcher::class => implode("\t", $cut([
                $number(), $number(), $number(), $number(),
                $join('|', 12, static fn (): string => $pick(['', '0', '1', '700000', (string) mt_rand(1, 2_000_000), ' 5', '1.5', 'x', '999999999999999', '1000000000000000', self::randomBytes(3)]).$pick(['', '', '', ':', ':ok', ':retry', ':alt', ':missing', ':OK', ':bogus', ':ok:retry', '::'])),
                'extra',
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
     * Invokes the class's private `w64RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w64RustFfiState'))->invoke(null, $op, $val);
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
     * Resolves the class's Wave 64.5 FFI handle or skips the test without the library.
     *
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI extension not loaded.');
        }
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $cdef = (new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->getValue();
        $ffi = \is_string($cdef) ? RustPipelineLibrary::resolveDomain('w64_5', $cdef, \is_string($path) && $path !== '' ? $path : null) : null;
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library not built.');
        }

        return $ffi;
    }
}
