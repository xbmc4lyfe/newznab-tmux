<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustArchiveExtractionMediaUsenetDownloadReleaseProcW40ParityTest extends TestCase
{
    /** Helper name => owning class. Each class owns its separate Wave 40.6 toggle. */
    private const HELPERS = [
        'summarizeExtractedEntriesSafety' => ArchiveExtractionService::class,
        'summarizeMediaProbeMetrics' => MediaExtractionService::class,
        'summarizeSegmentDownloadWindow' => UsenetDownloadService::class,
        'summarizePostProcessBatchTelemetry' => ReleaseProcessor::class,
        'summarizeWorkQueuePriorityBatch' => AdditionalWorkPlanner::class,
    ];

    private const RANDOM_CASES = 1_500;

    private const EDGE_INTS = [PHP_INT_MIN, PHP_INT_MIN + 1, -1_000, -1, 0, 1, 2, 5, 6, 24, 25, 720, 1080, 1280, 1920, 2160, 3840,
        9_999, 10_000, 86_400_000, 86_400_001, PHP_INT_MAX - 1, PHP_INT_MAX];

    private const PATHS = ['movie/movie.mkv', '../etc/passwd', '/abs/run.SH', 'setup.exe', '', "a\0b", '\\root', 'a\\..\\b', 'a..b/c', '..',
        'dir.exe/readme', '.JS', 'x.', 'Sample/clip.BaT', 'tools\\install.Ps1', 'a/b/c.tar.gz', 'é.exe', 'fake.exé', 'x/..y', null, 7, 1.5, true, ['x']];

    private const SEGMENT_STATUSES = ['ok', 'downloaded', 'missing', 'not_found', 'timeout', 'error', 'retry', 'OK', ' ok', '', 'ok ', "missing\0", null, 430, true];

    private const BATCH_STATUSES = ['processed', 'ok', 'failed', 'error', 'skipped', 'PROCESSED', '', null, 1, false];

    private const FLAG_VALUES = [true, false, 1, 0, 'true', null];

    /** @var array<string, Closure> */
    private array $closures = [];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as $class) {
            $class::resetW406RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_follow_the_benchmark_and_toggle_independently(): void
    {
        foreach (self::HELPERS as $class) {
            // None of the five helpers clears 1.5x on every required workload (see the benchmark test).
            $this->assertFalse($this->defaultFor($class), $class);
            $this->assertFalse($class::usesW406RustFfi(), $class);
        }
        foreach (self::HELPERS as $class) {
            $class::useW406RustFfi(true);
            $this->assertTrue($class::usesW406RustFfi(), $class);
            foreach (self::HELPERS as $other) {
                if ($other !== $class) {
                    $this->assertFalse($other::usesW406RustFfi(), $other.' must not follow '.$class);
                }
            }
            $class::useW406RustFfi(false);
            $this->assertFalse($class::usesW406RustFfi(), $class);
            $class::useW406RustFfi(true);
            $class::resetW406RustFfi();
            $this->assertFalse($class::usesW406RustFfi(), $class);
        }
        $this->assertNull((new ReflectionProperty(ArchiveExtractionService::class, 'w406Ffi'))->getValue());

        // The Wave 40.6 toggle is separate from UsenetDownloadService's earlier Rust helper toggle.
        $earlier = new ReflectionProperty(UsenetDownloadService::class, 'useRustFfi');
        $this->assertTrue($earlier->getValue());
        UsenetDownloadService::useW406RustFfi(false);
        $this->assertTrue($earlier->getValue());
        UsenetDownloadService::useRustFfi(false);
        UsenetDownloadService::useW406RustFfi(true);
        $this->assertTrue(UsenetDownloadService::usesW406RustFfi());
        UsenetDownloadService::useRustFfi(true);
        UsenetDownloadService::resetW406RustFfi();
        $this->assertTrue($earlier->getValue());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(['total_entries' => 5, 'safe_relative' => 2, 'traversal_rejected' => 3, 'executable' => 2, 'exceeds_limit' => true],
            ArchiveExtractionService::summarizeExtractedEntriesSafety(['movie/movie.mkv', '../etc/passwd', '/abs/run.SH', 'setup.exe', null], 3));
        $this->assertSame(['total_entries' => 8, 'safe_relative' => 4, 'traversal_rejected' => 4, 'executable' => 1, 'exceeds_limit' => true],
            ArchiveExtractionService::summarizeExtractedEntriesSafety(['', "a\0b", '\\root', 'a\\..\\b', 'a..b/c', 'dir.exe/readme', '.JS', 'x.'], PHP_INT_MIN));
        $this->assertSame(['total_entries' => 0, 'safe_relative' => 0, 'traversal_rejected' => 0, 'executable' => 0, 'exceeds_limit' => false],
            ArchiveExtractionService::summarizeExtractedEntriesSafety([]));
        $this->assertFalse(ArchiveExtractionService::summarizeExtractedEntriesSafety(array_fill(0, 500, 'a.txt'))['exceeds_limit']);
        $this->assertTrue(ArchiveExtractionService::summarizeExtractedEntriesSafety(array_fill(0, 501, 'a.txt'))['exceeds_limit']);
        // Only ASCII case folds, so a non-ASCII extension never matches.
        $this->assertSame(2, ArchiveExtractionService::summarizeExtractedEntriesSafety(['é.exe', 'fake.exé', 'Sample/clip.BaT'])['executable']);

        $this->assertSame(['resolution_tier' => 'fhd_1080', 'valid_duration' => true, 'surround_audio' => true, 'sample_eligible' => true],
            MediaExtractionService::summarizeMediaProbeMetrics(5_400_000, 1920, 1080, 8_000, 6));
        $tiers = [];
        foreach ([[3840, 2160], [1280, 1], [640, 480], [640, 0], [1, 720], [0, 2160], [1919, 1079], [1, 2160]] as [$width, $height]) {
            $tiers[] = MediaExtractionService::summarizeMediaProbeMetrics(60_000, $width, $height, 1, 2)['resolution_tier'];
        }
        $this->assertSame(['uhd_4k', 'hd_720', 'sd', 'invalid', 'hd_720', 'invalid', 'hd_720', 'uhd_4k'], $tiers);
        $this->assertSame(['resolution_tier' => 'sd', 'valid_duration' => true, 'surround_audio' => false, 'sample_eligible' => false],
            MediaExtractionService::summarizeMediaProbeMetrics(9_999, 640, 480, 1, 0));
        $this->assertFalse(MediaExtractionService::summarizeMediaProbeMetrics(86_400_001, 640, 480, 1, 8)['valid_duration']);
        $this->assertFalse(MediaExtractionService::summarizeMediaProbeMetrics(10_000, 1, 720, 0, 6)['sample_eligible']);

        $this->assertSame(['total_segments' => 6, 'downloaded' => 2, 'missing_articles' => 2, 'transient_errors' => 1, 'downloaded_bytes' => 700_000, 'abort_threshold_exceeded' => true],
            UsenetDownloadService::summarizeSegmentDownloadWindow([['status' => 'ok', 'bytes' => 700_000], ['status' => 'missing'], ['status' => 'timeout', 'bytes' => 5],
                ['status' => 'downloaded', 'bytes' => -4], ['status' => 'not_found'], 'junk'], 1));
        $this->assertSame(['total_segments' => 3, 'downloaded' => 2, 'missing_articles' => 0, 'transient_errors' => 0, 'downloaded_bytes' => PHP_INT_MAX, 'abort_threshold_exceeded' => false],
            UsenetDownloadService::summarizeSegmentDownloadWindow([['status' => 'ok', 'bytes' => PHP_INT_MAX], ['status' => 'ok', 'bytes' => 1], ['status' => 'OK', 'bytes' => 1]], PHP_INT_MIN));
        $this->assertSame(0, UsenetDownloadService::summarizeSegmentDownloadWindow([['status' => 'ok', 'bytes' => '9']])['downloaded_bytes']);
        $this->assertFalse(UsenetDownloadService::summarizeSegmentDownloadWindow(array_fill(0, 2, ['status' => 'missing']))['abort_threshold_exceeded']);
        $this->assertTrue(UsenetDownloadService::summarizeSegmentDownloadWindow(array_fill(0, 3, ['status' => 'missing']))['abort_threshold_exceeded']);

        $this->assertSame(['total' => 4, 'processed' => 2, 'password_detected' => 2, 'media_extracted' => 2, 'failed' => 1, 'total_elapsed_ms' => 100, 'budget_exhausted' => true],
            ReleaseProcessor::summarizePostProcessBatchTelemetry([['status' => 'processed', 'passworded' => true, 'elapsed_ms' => 40],
                ['status' => 'ok', 'media_extracted' => true, 'elapsed_ms' => 30], ['status' => 'failed', 'elapsed_ms' => -5],
                ['passworded' => true, 'media_extracted' => true, 'elapsed_ms' => 30]], 100));
        $this->assertSame(['total' => 0, 'processed' => 0, 'password_detected' => 0, 'media_extracted' => 0, 'failed' => 0, 'total_elapsed_ms' => 0, 'budget_exhausted' => false],
            ReleaseProcessor::summarizePostProcessBatchTelemetry([], PHP_INT_MIN));
        $this->assertSame(['total' => 3, 'processed' => 0, 'password_detected' => 0, 'media_extracted' => 0, 'failed' => 1, 'total_elapsed_ms' => PHP_INT_MAX, 'budget_exhausted' => true],
            ReleaseProcessor::summarizePostProcessBatchTelemetry([['status' => 'x', 'elapsed_ms' => PHP_INT_MAX, 'passworded' => 1], ['status' => 'error', 'elapsed_ms' => 1], 5], PHP_INT_MAX));
        $this->assertFalse(ReleaseProcessor::summarizePostProcessBatchTelemetry([['elapsed_ms' => 59_999]])['budget_exhausted']);

        $this->assertSame(['total_candidates' => 6, 'high_priority_fresh' => 1, 'retry_candidates' => 1, 'skipped_passworded' => 1, 'selected_batch' => 2],
            AdditionalWorkPlanner::summarizeWorkQueuePriorityBatch([['age_hours' => 3], ['attempts' => 2, 'age_hours' => 3], ['passworded' => true], 'x',
                ['age_hours' => 48], ['attempts' => -1, 'age_hours' => -1]], 2));
        $this->assertSame(['total_candidates' => 3, 'high_priority_fresh' => 2, 'retry_candidates' => 0, 'skipped_passworded' => 0, 'selected_batch' => 0],
            AdditionalWorkPlanner::summarizeWorkQueuePriorityBatch([['age_hours' => 0], ['age_hours' => 24], ['attempts' => PHP_INT_MIN, 'age_hours' => 25]], PHP_INT_MIN));
        $this->assertSame(['total_candidates' => 2, 'high_priority_fresh' => 0, 'retry_candidates' => 0, 'skipped_passworded' => 0, 'selected_batch' => 2],
            AdditionalWorkPlanner::summarizeWorkQueuePriorityBatch([['age_hours' => '3', 'attempts' => '2', 'passworded' => 1], []], PHP_INT_MAX));
        $this->assertSame(100, AdditionalWorkPlanner::summarizeWorkQueuePriorityBatch(array_fill(0, 150, []))['selected_batch']);
    }

    public function test_edge_case_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $ints = self::EDGE_INTS;
        $count = count($ints);
        foreach ($ints as $i => $a) {
            foreach ($ints as $j => $b) {
                $c = $ints[($i + $j) % $count];
                $d = $ints[($i * 3 + $j) % $count];
                $e = $ints[($i + 2 * $j) % $count];
                $this->assertHelperParity('summarizeMediaProbeMetrics', [$a, $b, $c, $d, $e]);
                $this->assertHelperParity('summarizeSegmentDownloadWindow', [[['status' => 'ok', 'bytes' => $a], ['status' => 'downloaded', 'bytes' => $b], ['status' => 'missing']], $c]);
                $this->assertHelperParity('summarizePostProcessBatchTelemetry', [[['status' => 'ok', 'elapsed_ms' => $a], ['elapsed_ms' => $b, 'passworded' => true]], $c]);
                $this->assertHelperParity('summarizeWorkQueuePriorityBatch', [[['attempts' => $a, 'age_hours' => $b], ['age_hours' => $c], 'x'], $d]);
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 2, 3, 24, 500, PHP_INT_MAX] as $limit) {
            foreach ([[], self::PATHS, array_fill(0, 501, '../x.exe'), ['k' => 'a.sh', 9 => null]] as $paths) {
                $this->assertHelperParity('summarizeExtractedEntriesSafety', [$paths, $limit]);
            }
            $segments = array_map(static fn (mixed $status): array => ['status' => $status, 'bytes' => 100], self::SEGMENT_STATUSES);
            $this->assertHelperParity('summarizeSegmentDownloadWindow', [[...$segments, 'x', null, [], ['bytes' => 5]], $limit]);
            $outcomes = [];
            foreach (self::BATCH_STATUSES as $status) {
                foreach (self::FLAG_VALUES as $flag) {
                    $outcomes[] = ['status' => $status, 'passworded' => $flag, 'media_extracted' => $flag, 'elapsed_ms' => 7];
                }
            }
            $this->assertHelperParity('summarizePostProcessBatchTelemetry', [[...$outcomes, 'x', null, []], $limit]);
            $candidates = array_map(static fn (mixed $flag): array => ['passworded' => $flag, 'attempts' => 0, 'age_hours' => 1], self::FLAG_VALUES);
            $this->assertHelperParity('summarizeWorkQueuePriorityBatch', [[...$candidates, 'x', null, [], ['attempts' => 1.5, 'age_hours' => '2']], $limit]);
        }
    }

    public function test_randomized_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4061));
        $int = fn (): int => match ($random->getInt(0, 3)) {
            0 => self::EDGE_INTS[$random->getInt(0, count(self::EDGE_INTS) - 1)],
            1 => $random->getInt(-10, 4_000),
            2 => $random->getInt(0, 1 << 40),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $pick = fn (array $values): mixed => $values[$random->getInt(0, count($values) - 1)];
        $maybe = fn (mixed $value): mixed => $random->getInt(0, 9) === 0 ? [null, '12', 3.0, false, []][$random->getInt(0, 4)] : $value;
        for ($i = 0; $i < self::RANDOM_CASES; $i++) {
            $this->assertHelperParity('summarizeMediaProbeMetrics', [$int(), $int(), $int(), $int(), $random->getInt(0, 2) === 0 ? $int() : $random->getInt(0, 8)]);

            $paths = [];
            for ($n = $random->getInt(0, 40); $n > 0; $n--) {
                $paths[] = match ($random->getInt(0, 4)) {
                    0 => $random->getBytes($random->getInt(1, 12)),
                    1 => implode($pick(['/', '\\']), [$pick(['a', '..', '', '.', 'x.exe']), $pick(['b.SH', '..', 'c', 'd.Js'])]),
                    default => $pick(self::PATHS),
                };
            }
            $this->assertHelperParity('summarizeExtractedEntriesSafety', [$paths, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 50)]);

            $segments = [];
            for ($n = $random->getInt(0, 60); $n > 0; $n--) {
                $segments[] = $random->getInt(0, 19) === 0 ? $pick([null, 'ok', 5]) : ['status' => $maybe($pick(self::SEGMENT_STATUSES)), 'bytes' => $maybe($random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 800_000))];
            }
            $this->assertHelperParity('summarizeSegmentDownloadWindow', [$segments, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 5)]);

            $outcomes = [];
            for ($n = $random->getInt(0, 40); $n > 0; $n--) {
                $outcomes[] = $random->getInt(0, 19) === 0 ? $pick([null, 'processed', 5]) : ['status' => $maybe($pick(self::BATCH_STATUSES)), 'passworded' => $pick(self::FLAG_VALUES),
                    'media_extracted' => $pick(self::FLAG_VALUES), 'elapsed_ms' => $maybe($random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 5_000))];
            }
            $this->assertHelperParity('summarizePostProcessBatchTelemetry', [$outcomes, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 120_000)]);

            $candidates = [];
            for ($n = $random->getInt(0, 60); $n > 0; $n--) {
                $candidates[] = $random->getInt(0, 19) === 0 ? $pick([null, 'x', 5]) : ['passworded' => $pick(self::FLAG_VALUES),
                    'attempts' => $maybe($random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 3)), 'age_hours' => $maybe($random->getInt(0, 3) === 0 ? $int() : $random->getInt(-2, 72))];
            }
            $this->assertHelperParity('summarizeWorkQueuePriorityBatch', [$candidates, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 64)]);
        }
    }

    public function test_direct_ffi_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[64]');
        FFI::memset($out, ord('Z'), 64);
        $input = pack('q5', 5_400_000, 1920, 1080, 8_000, 6);

        $this->assertSame(-32, $this->callFfi($ffi, 2, $input, 40, $out, 31));
        $this->assertSame(-32, $this->callFfi($ffi, 2, $input, 40, $out, 0));
        foreach ([0, 6, 255] as $operation) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $input, 40, $out, 64), "op {$operation}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, 2, null, 40, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, $input, 40, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, $input, 39, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, $input.'x', 41, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, $input, 16_777_217, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, $input, 40, $out, 16_777_217));
        $this->assertSame(-1, $this->callFfi($ffi, 1, pack('qV', 0, 0xFFFFFFFF), 12, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 1, pack('qVV', 0, 1, 100), 16, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 4, pack('qVCVq', 0, 1, 4, 0xFFFFFFFF, 0), 25, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 5, pack('qVCqq', 0, 1, 2, 0, 0), 29, $out, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));

        $this->assertSame(32, $this->callFfi($ffi, 2, $input, 40, $out, 64));
        $this->assertSame(pack('q4', 2, 1, 1, 1).str_repeat('Z', 32), FFI::string($out, 64));

        // A short buffer reports the exact size; retrying with that capacity succeeds without overrunning it.
        $window = pack('qV', 0, 2).pack('V', 2).'ok'.pack('q', 9).pack('V', 7).'missing'.pack('q', 0);
        FFI::memset($out, ord('Z'), 64);
        $required = $this->callFfi($ffi, 3, $window, strlen($window), $out, 8);
        $this->assertSame(-48, $required);
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));
        $this->assertSame(48, $this->callFfi($ffi, 3, $window, strlen($window), $out, -$required));
        $this->assertSame(pack('q6', 2, 1, 1, 0, 9, 1).str_repeat('Z', 16), FFI::string($out, 64));

        foreach (self::HELPERS as $class) {
            $class::useW406RustFfi(true);
        }
        ArchiveExtractionService::summarizeExtractedEntriesSafety(['a.txt']);
        $this->assertInstanceOf(FFI::class, (new ReflectionProperty(ArchiveExtractionService::class, 'w406Ffi'))->getValue());
        $this->assertNotNull(ArchiveExtractionService::summarizeExtractedEntriesSafetyNative(['a.txt', null]));
        $this->assertNotNull(MediaExtractionService::summarizeMediaProbeMetricsNative(1, 1, 1, 1, 1));
        $this->assertNotNull(UsenetDownloadService::summarizeSegmentDownloadWindowNative([['status' => 'ok']]));
        $this->assertNotNull(ReleaseProcessor::summarizePostProcessBatchTelemetryNative([['status' => 'ok']]));
        $this->assertNotNull(AdditionalWorkPlanner::summarizeWorkQueuePriorityBatchNative([[]]));
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $cases = [
            'summarizeExtractedEntriesSafety' => [['a/b.exe', '../c', null], 2],
            'summarizeMediaProbeMetrics' => [60_000, 1280, 720, 4_000, 2],
            'summarizeSegmentDownloadWindow' => [[['status' => 'ok', 'bytes' => 10], ['status' => 'missing']], 0],
            'summarizePostProcessBatchTelemetry' => [[['status' => 'processed', 'elapsed_ms' => 5, 'passworded' => true]], 5],
            'summarizeWorkQueuePriorityBatch' => [[['age_hours' => 1], ['attempts' => 1], ['passworded' => true]], 1],
        ];
        foreach (self::HELPERS as $class) {
            $class::useW406RustFfi(true);
        }
        (new ReflectionProperty(ArchiveExtractionService::class, 'w406Ffi'))->setValue(null, false);
        foreach ($cases as $helper => $args) {
            $class = self::HELPERS[$helper];
            $this->assertNull($this->helper($class, $helper.'Native')(...$args), $helper);
            $this->assertSame($this->helper($class, $helper.'Php')(...$args), $class::$helper(...$args), $helper);
        }
        $this->assertNull(ArchiveExtractionService::w406Slots(2, str_repeat("\0", 16_777_217), 4));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // frame packing, the FFI call and decoding (release dylib, 5 warmups, 201 samples x 20 iterations).
        // Common = a 40-member archive listing, one media probe, a 64-segment window, a 25-release batch and a
        // 100-candidate queue; uncommon = a 5,000-member listing with traversal, executable and non-string
        // members, an overflow-range probe, a 5,000-segment window, a 2,000-release batch and a 10,000-candidate
        // queue, each with unknown statuses and non-array or non-integer values.
        // Speedup = PHP latency / Rust latency per workload pass, common mean/p99 then uncommon mean/p99
        // (PHP 8.5.12 NTS, Apple M5, release dylib, 3 rounds):
        // - Round 1: archive 1.44x/1.31x, 1.58x/1.58x; media 0.29x/0.21x, 0.25x/0.09x; segments 0.44x/0.49x, 0.56x/0.63x;
        //   batch 0.45x/0.50x, 0.61x/0.62x; queue 0.67x/0.72x, 0.72x/0.72x
        // - Round 2: archive 1.48x/1.45x, 1.56x/1.54x; media 0.28x/0.29x, 0.25x/0.29x; segments 0.43x/0.43x, 0.54x/0.54x;
        //   batch 0.45x/0.46x, 0.60x/0.48x; queue 0.67x/0.66x, 0.71x/0.70x
        // - Round 3: archive 1.42x/1.31x, 1.53x/1.38x; media 0.29x/0.50x, 0.28x/0.48x; segments 0.42x/0.48x, 0.52x/0.57x;
        //   batch 0.44x/0.37x, 0.61x/0.58x; queue 0.67x/0.73x, 0.71x/0.71x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustArchiveExtractionMediaUsenetDownloadReleaseProcW40ParityTest.php --filter benchmark
        // The other helpers must inspect every PHP value just to build the frame, so the FFI round trip only adds
        // cost; the archive audit gains on long listings but its common path stays below 1.5x. No helper clears the
        // 1.5x bar on every workload in .ai/rules/rust-performance.md, so every W406_RUST_FFI_DEFAULT stays false.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(4062));
        $workloads = ['common' => $this->commonWorkload($random), 'uncommon' => $this->uncommonWorkload($random)];
        $warmup = 5;
        $samples = 201;
        $iterations = 20;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => $class) {
            foreach ($workloads as $name => $workload) {
                $args = $workload[$helper];
                $this->assertHelperParity($helper, $args);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $class::useW406RustFfi($useRust);
                        $sink += count($class::$helper(...$args));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach ($random->getInt(0, 1) === 1 ? [true, false] : [false, true] as $useRust) {
                        $class::useW406RustFfi($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$helper(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::useW406RustFfi(null);
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustArchiveExtractionMediaUsenetDownloadReleaseProcW40 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => $class) {
            $speedup = $speedups[$helper];
            if (min($speedup['common']['mean'], $speedup['common']['p99'], $speedup['uncommon']['mean'], $speedup['uncommon']['p99']) < 1.5) {
                $this->assertFalse($this->defaultFor($class), $class.' must keep PHP below 1.5x: '.$summary);
            }
            foreach ($speedup as $name => $values) {
                $this->assertGreaterThan(0.0, $values['mean'], $helper.' '.$name);
                $this->assertGreaterThan(0.0, $values['p99'], $helper.' '.$name);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertHelperParity(string $helper, array $args): void
    {
        $class = self::HELPERS[$helper];
        $expected = $this->helper($class, $helper.'Php')(...$args);
        $context = $helper.' '.substr(bin2hex(serialize($args)), 0, 400);
        $this->assertSame($expected, $this->helper($class, $helper.'Native')(...$args), $context);
        foreach ([true, false] as $useRust) {
            $class::useW406RustFfi($useRust);
            $this->assertSame($expected, $class::$helper(...$args), $context);
        }
        $class::useW406RustFfi(null);
    }

    private function defaultFor(string $class): bool
    {
        return (bool) constant($class.'::W406_RUST_FFI_DEFAULT');
    }

    private function helper(string $class, string $method): Closure
    {
        return $this->closures[$class.'::'.$method] ??= (new ReflectionMethod($class, $method))->getClosure(null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function commonWorkload(Randomizer $random): array
    {
        $paths = [];
        for ($i = 0; $i < 40; $i++) {
            $paths[] = 'Release.Name.1080p/'.['release.mkv', 'release.nfo', 'Sample/sample.mkv', 'proof.jpg', 'release.r'.sprintf('%02d', $i)][$random->getInt(0, 4)];
        }
        $segments = [];
        for ($i = 0; $i < 64; $i++) {
            $segments[] = $random->getInt(0, 31) === 0 ? ['status' => 'missing', 'bytes' => 0] : ['status' => 'ok', 'bytes' => $random->getInt(700_000, 760_000)];
        }
        $outcomes = [];
        for ($i = 0; $i < 25; $i++) {
            $outcomes[] = ['status' => $random->getInt(0, 9) === 0 ? 'failed' : 'processed', 'passworded' => $random->getInt(0, 9) === 0,
                'media_extracted' => $random->getInt(0, 1) === 1, 'elapsed_ms' => $random->getInt(50, 4_000)];
        }
        $candidates = [];
        for ($i = 0; $i < 100; $i++) {
            $candidates[] = ['passworded' => $random->getInt(0, 19) === 0, 'attempts' => $random->getInt(0, 3) === 0 ? $random->getInt(1, 3) : 0, 'age_hours' => $random->getInt(0, 72)];
        }

        return [
            'summarizeExtractedEntriesSafety' => [$paths, 500],
            'summarizeMediaProbeMetrics' => [5_400_000, 1920, 1080, 8_000, 6],
            'summarizeSegmentDownloadWindow' => [$segments, 2],
            'summarizePostProcessBatchTelemetry' => [$outcomes, 60_000],
            'summarizeWorkQueuePriorityBatch' => [$candidates, 100],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function uncommonWorkload(Randomizer $random): array
    {
        $paths = [];
        for ($i = 0; $i < 5_000; $i++) {
            $paths[] = $i % 7 === 0 ? $random->getBytes($random->getInt(1, 40)) : self::PATHS[$random->getInt(0, count(self::PATHS) - 1)];
        }
        $segments = [];
        for ($i = 0; $i < 5_000; $i++) {
            $segments[] = $i % 50 === 0 ? 'junk' : ['status' => self::SEGMENT_STATUSES[$random->getInt(0, count(self::SEGMENT_STATUSES) - 1)],
                'bytes' => $i % 9 === 0 ? (string) $i : $random->getInt(-5, 800_000)];
        }
        $outcomes = [];
        for ($i = 0; $i < 2_000; $i++) {
            $outcomes[] = $i % 40 === 0 ? null : ['status' => self::BATCH_STATUSES[$random->getInt(0, count(self::BATCH_STATUSES) - 1)],
                'passworded' => self::FLAG_VALUES[$random->getInt(0, count(self::FLAG_VALUES) - 1)], 'media_extracted' => self::FLAG_VALUES[$random->getInt(0, count(self::FLAG_VALUES) - 1)],
                'elapsed_ms' => $i % 11 === 0 ? PHP_INT_MAX : $random->getInt(-10, 90_000)];
        }
        $candidates = [];
        for ($i = 0; $i < 10_000; $i++) {
            $candidates[] = $i % 60 === 0 ? 'x' : ['passworded' => self::FLAG_VALUES[$random->getInt(0, count(self::FLAG_VALUES) - 1)],
                'attempts' => $i % 13 === 0 ? '2' : $random->getInt(-1, 4), 'age_hours' => $i % 17 === 0 ? null : $random->getInt(-5, 500)];
        }

        return [
            'summarizeExtractedEntriesSafety' => [$paths, 1_000],
            'summarizeMediaProbeMetrics' => [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN],
            'summarizeSegmentDownloadWindow' => [$segments, PHP_INT_MAX],
            'summarizePostProcessBatchTelemetry' => [$outcomes, PHP_INT_MIN],
            'summarizeWorkQueuePriorityBatch' => [$candidates, 2_500],
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, mixed ...$args): mixed
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w406_archive_media_download_processor_planner(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional_w406', ArchiveExtractionService::W406_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 40.6 additional-processing export is not available.');
        }

        return $ffi;
    }
}
