<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AdditionalProcessing\DTO\DownloadedArchive;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\Media\MediaArticleFetcher;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ProcessingMetrics;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;
use TypeError;

/**
 * Wave 16 Worker 3 parity and paired randomized benchmark suite for
 * AdditionalProcessingOrchestrator, MediaByteBudget, ProcessingMetrics, MediaArticleFetcher
 * and DownloadedArchive.
 *
 * Paired randomized benchmark evidence (10,000 paired observations across 2 repetitions per
 * workload regime, 200 warmup pairs, Apple M5, PHP 8.5.12 CLI without opcache, rustc 1.99.1
 * release + LTO, base 7a1911099; `NNTMUX_ADDITIONAL_BUDGET_METRICS_BENCH=1`, optional `_REPS`
 * and `_PAIRS`). Speedup is PHP / Rust latency including FFI marshaling:
 *
 * | Method | Common Mean | Common p99 | Uncommon Mean | Uncommon p99 |
 * | --- | --- | --- | --- | --- |
 * | `AdditionalProcessingOrchestrator::elapsedSecondsBetween` | 0.181x (31 / 171 ns) | 0.201x | 0.183x (32 / 178 ns) | 0.201x |
 * | `AdditionalProcessingOrchestrator::effectiveQueryLimit` | 0.314x (27 / 85 ns) | 0.336x | 0.296x (27 / 93 ns) | 0.336x |
 * | `AdditionalProcessingOrchestrator::batchDisposition` | 0.317x (29 / 93 ns) | 0.336x | 0.294x (29 / 100 ns) | 0.336x |
 * | `AdditionalProcessingOrchestrator::shouldClearClaim` | 0.326x (30 / 92 ns) | 0.336x | 0.297x (28 / 94 ns) | 0.336x |
 * | `MediaByteBudget::allows` | 0.187x (41 / 219 ns) | 0.332x | 0.187x (38 / 205 ns) | 0.332x |
 * | `MediaByteBudget::spend` | 0.187x (38 / 202 ns) | 0.332x | 0.180x (41 / 228 ns) | 0.183x |
 * | `MediaByteBudget::remaining` | 0.186x (38 / 206 ns) | 0.332x | 0.201x (42 / 210 ns) | 0.288x |
 * | `MediaByteBudget::exhausted` | 0.160x (34 / 214 ns) | 0.144x | 0.157x (34 / 214 ns) | 0.144x |
 * | `MediaByteBudget::clampRequestBytes` | 0.260x (53 / 204 ns) | 0.336x | 0.261x (56 / 213 ns) | 0.288x |
 * | `MediaByteBudget::utilization` | 0.239x (43 / 181 ns) | 0.224x | 0.171x (31 / 184 ns) | 0.168x |
 * | `ProcessingMetrics::secondsFromNanoseconds` | 0.403x (189 / 468 ns) | 0.428x | 0.483x (316 / 655 ns) | 0.450x |
 * | `ProcessingMetrics::rate` | 0.329x (30 / 93 ns) | 0.336x | 0.327x (30 / 93 ns) | 0.336x |
 * | `ProcessingMetrics::formatStageSummary` | 0.734x (649 / 885 ns) | 0.741x | 1.630x (3221 / 1976 ns) | 1.637x |
 * | `MediaArticleFetcher::isSafeWitnessName` | 0.971x (130 / 134 ns) | 1.000x | 0.706x (2564 / 3634 ns) | 0.755x |
 * | `MediaArticleFetcher::chunkLength` | 0.350x (33 / 94 ns) | 0.336x | 0.374x (35 / 93 ns) | 0.336x |
 * | `DownloadedArchive::fileSummary` | 0.316x (127 / 403 ns) | 0.309x | 0.526x (7249 / 13776 ns) | 0.547x |
 *
 * Production defaults: `$useRustFfi = false` for every class. No method clears 1.5x on all
 * four metrics; `formatStageSummary` wins only on the 13-stage extreme-value summary while the
 * common summary loses to `sprintf`, and the scalar helpers are dominated by FFI call overhead.
 */
final class RustAdditionalBudgetMetricsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array STATIC_TOGGLE_CLASSES = [
        AdditionalProcessingOrchestrator::class,
        MediaByteBudget::class,
        ProcessingMetrics::class,
        MediaArticleFetcher::class,
    ];

    private const string FULL_CDEF = AdditionalProcessingOrchestrator::FFI_CDEF
        .MediaByteBudget::FFI_CDEF
        .ProcessingMetrics::FFI_CDEF
        .MediaArticleFetcher::FFI_CDEF
        .DownloadedArchive::FFI_CDEF;

    private FFI $ffi;

    private Randomizer $random;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Rust pipeline FFI library must be built with Wave 16 budget/metrics exports.');
        $this->ffi = $ffi;
        $this->random = new Randomizer(new Mt19937(20261024));
    }

    protected function tearDown(): void
    {
        foreach (self::STATIC_TOGGLE_CLASSES as $class) {
            $class::setUseRustFfi(false);
        }
        parent::tearDown();
    }

    public function test_default_use_rust_ffi_flags_match_benchmark_threshold(): void
    {
        foreach (self::STATIC_TOGGLE_CLASSES as $class) {
            $default = (new ReflectionClass($class))->getDefaultProperties()['useRustFfi'] ?? null;
            self::assertFalse($default, "{$class} should default to useRustFfi = false");
            self::assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
        }
        self::assertFalse((new DownloadedArchive('a.rar', 'x', []))->useRustFfi);
    }

    public function test_c_abi_null_pointer_and_short_buffer_contracts(): void
    {
        $ffi = $this->ffi;
        $tiny = $ffi->new('char[2]');
        $out = $ffi->new('int64_t[4]');
        $one = $ffi->new('int64_t');
        $status = $ffi->new('int32_t');

        // @phpstan-ignore method.notFound
        self::assertNan($ffi->nntmux_aporch_elapsed_seconds(0, 1, null));
        // @phpstan-ignore method.notFound
        $ffi->nntmux_aporch_elapsed_seconds(PHP_INT_MIN, PHP_INT_MAX, FFI::addr($status));
        // @phpstan-ignore property.notFound
        self::assertSame(-1, $status->cdata);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_budget_eval(0, 10, 0, 1, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_budget_eval(99, 10, 0, 1, FFI::addr($one)));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_budget_eval(1, 10, PHP_INT_MAX, 1, FFI::addr($one)));
        // @phpstan-ignore method.notFound
        self::assertNan($ffi->nntmux_budget_utilization(10, 1, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_pmetrics_ns_to_seconds(null, 8, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_pmetrics_ns_to_seconds('abc', 3, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-8, $ffi->nntmux_pmetrics_ns_to_seconds(pack('q', 1), 8, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_pmetrics_ns_to_seconds(pack('q', 1), 8, null, 8));
        $entry = pack('V', 1).'a'.pack('e', 1.5);
        // @phpstan-ignore method.notFound
        self::assertSame(-11, $ffi->nntmux_pmetrics_format_summary($entry, \strlen($entry), $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_pmetrics_format_summary($entry, \strlen($entry) - 1, $tiny, 2));
        $infinite = pack('V', 1).'a'.pack('e', INF);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_pmetrics_format_summary($infinite, \strlen($infinite), $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_fetcher_safe_witness_name(null, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_fetcher_chunk_length(-1, 5));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_dlarchive_summary("\0", 1, pack('q', 1), 8, null, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_dlarchive_summary("\0", 1, pack('q', 1), 8, $out, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_dlarchive_summary("\0", 1, pack('q', 1), 7, $out, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_dlarchive_summary("\x04", 1, pack('q', 1), 8, $out, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_dlarchive_summary(null, 1, pack('q', 1), 8, $out, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_dlarchive_summary("\0\0", 2, pack('q*', PHP_INT_MAX, 1), 16, $out, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(1, $ffi->nntmux_dlarchive_summary('', 0, '', 0, $out, 4));
    }

    public function test_orchestrator_helper_parity(): void
    {
        $pairs = [[0, 0], [0, 1], [1_000_000_000, 3_000_000_000], [5, 1_500_000_005], [10, 3], [PHP_INT_MIN, -1], [0, PHP_INT_MAX], [-PHP_INT_MAX, 0]];
        for ($i = 0; $i < 300; $i++) {
            $start = $this->random->getInt(0, PHP_INT_MAX >> 1);
            $pairs[] = [$start, $start + $this->random->getInt(0, 10 ** 12)];
        }
        foreach ($pairs as [$start, $end]) {
            $expected = AdditionalProcessingOrchestrator::elapsedSecondsBetweenPhp($start, $end);
            self::assertSame($expected, AdditionalProcessingOrchestrator::elapsedSecondsBetweenNative($start, $end), "elapsed {$start}..{$end}");
        }
        self::assertNull(AdditionalProcessingOrchestrator::elapsedSecondsBetweenNative(PHP_INT_MIN, PHP_INT_MAX));

        foreach ([PHP_INT_MIN, -1, 0, 1, 25, 26, 500, PHP_INT_MAX] as $limit) {
            self::assertSame(AdditionalProcessingOrchestrator::effectiveQueryLimitPhp($limit), AdditionalProcessingOrchestrator::effectiveQueryLimitNative($limit));
            foreach ([true, false] as $setup) {
                self::assertSame(
                    AdditionalProcessingOrchestrator::batchDispositionPhp($setup, $limit),
                    AdditionalProcessingOrchestrator::batchDispositionNative($setup, $limit),
                );
            }
            foreach (['', 'a', bin2hex(random_bytes(16))] as $token) {
                self::assertSame(
                    AdditionalProcessingOrchestrator::shouldClearClaimPhp($token, $limit),
                    AdditionalProcessingOrchestrator::shouldClearClaimNative($token, $limit),
                );
            }
        }
        self::assertSame(25, AdditionalProcessingOrchestrator::effectiveQueryLimitPhp(0));
        self::assertSame(AdditionalProcessingOrchestrator::BATCH_SETUP_FAILED, AdditionalProcessingOrchestrator::batchDispositionPhp(false, 5));
        self::assertSame(AdditionalProcessingOrchestrator::BATCH_EMPTY, AdditionalProcessingOrchestrator::batchDispositionPhp(true, 0));
        self::assertSame(AdditionalProcessingOrchestrator::BATCH_PROCESS, AdditionalProcessingOrchestrator::batchDispositionPhp(true, 1));
    }

    public function test_media_byte_budget_parity(): void
    {
        $limits = [PHP_INT_MIN, -10, 0, 1, 1_000, 50_000_000, PHP_INT_MAX];
        $bytes = [PHP_INT_MIN, -5, 0, 1, 999, 1_000, 1_001, 49_999_999, PHP_INT_MAX];
        foreach ($limits as $limit) {
            foreach ([0, 1, 1_000, 40_000_000, PHP_INT_MAX - 1, PHP_INT_MAX] as $spent) {
                $budget = $this->budget($limit, $spent);
                $this->assertBudgetParity($budget, $bytes);
            }
        }
        for ($i = 0; $i < 300; $i++) {
            $limit = $this->random->getInt(-1_000, 100_000_000);
            $budget = $this->budget($limit, $this->random->getInt(0, 120_000_000));
            $this->assertBudgetParity($budget, [$this->random->getInt(-1_000, 20_000_000), $this->random->getInt(PHP_INT_MIN, PHP_INT_MAX)]);
        }

        $budget = $this->budget(PHP_INT_MAX, PHP_INT_MAX);
        self::assertNull($budget->allowsNative(1));
        self::assertNull($budget->spentAfterNative(1));
        self::assertNull($this->budget(PHP_INT_MAX, -1)->remainingNative());
        self::assertSame(0, $this->budget(PHP_INT_MIN, PHP_INT_MAX)->remainingNative());
    }

    public function test_media_byte_budget_public_methods_match_with_rust_enabled(): void
    {
        foreach ([false, true] as $enabled) {
            MediaByteBudget::setUseRustFfi($enabled);
            $budget = new MediaByteBudget(1_000);
            self::assertTrue($budget->allows(1_000));
            self::assertFalse($budget->allows(1_001));
            self::assertSame(0.0, $budget->utilization());
            $budget->spend(-50);
            self::assertSame(0, $budget->spent());
            $budget->spend(400);
            self::assertSame(600, $budget->remaining());
            self::assertSame(600, $budget->clampRequestBytes(2_000));
            self::assertSame(0.4, $budget->utilization());
            self::assertFalse($budget->exhausted());
            $budget->spend(700);
            self::assertTrue($budget->exhausted());
            self::assertSame(0, $budget->remaining());
            self::assertSame(1.0, $budget->utilization());
            self::assertSame(1.0, (new MediaByteBudget(0))->utilization());

            $overflow = $this->budget(PHP_INT_MAX, PHP_INT_MAX);
            self::assertSame($overflow->allowsPhp(1), $overflow->allows(1));
            try {
                $overflow->spend(1);
                self::fail('Overflowing spend must keep the PHP TypeError');
            } catch (TypeError) {
                self::assertSame(PHP_INT_MAX, $overflow->spent());
            }
        }
    }

    public function test_processing_metrics_parity(): void
    {
        $cases = [
            [],
            ['a' => 0],
            ['download' => 1_500_000_000, 'probe' => 3_000_000_000, 'neg' => -7],
            ['max' => PHP_INT_MAX, 'min' => PHP_INT_MIN, 'one' => 1],
        ];
        for ($i = 0; $i < 100; $i++) {
            $case = [];
            foreach (ProcessingStage::cases() as $stage) {
                if ($this->random->getInt(0, 2) > 0) {
                    $case[$stage->value] = $this->random->getInt(0, 10 ** $this->random->getInt(1, 13));
                }
            }
            $cases[] = $case;
        }
        foreach ($cases as $case) {
            $seconds = ProcessingMetrics::secondsFromNanosecondsPhp($case);
            self::assertSame($seconds, ProcessingMetrics::secondsFromNanosecondsNative($case));
            self::assertSame(ProcessingMetrics::formatStageSummaryPhp($seconds), ProcessingMetrics::formatStageSummaryNative($seconds));
        }
        self::assertNull(ProcessingMetrics::secondsFromNanosecondsNative(['x' => 1.5]));

        $durations = [
            'zero' => 0.0, 'negzero' => -0.0, 'neg' => -1.5e-7, 'tie' => 0.0625, 'half' => 0.0000025, 'tiny' => 5e-324,
            'big' => 1e300, 'negbig' => -1.7976931348623157e308, 'round' => 123456789.0000005, 'int' => 3, '7' => 2.5,
        ];
        self::assertSame(ProcessingMetrics::formatStageSummaryPhp($durations), ProcessingMetrics::formatStageSummaryNative($durations));
        for ($i = 0; $i < 500; $i++) {
            $value = $this->random->getFloat(-1e6, 1e6) / (10 ** $this->random->getInt(0, 12));
            self::assertSame(ProcessingMetrics::formatStageSummaryPhp(['s' => $value]), ProcessingMetrics::formatStageSummaryNative(['s' => $value]), (string) $value);
        }
        self::assertNull(ProcessingMetrics::formatStageSummaryNative(['inf' => INF]));
        self::assertNull(ProcessingMetrics::formatStageSummaryNative(['nan' => NAN]));
        self::assertSame('', ProcessingMetrics::formatStageSummaryNative([]));

        foreach ([[0, 0.0], [10, 4.0], [7, -1.0], [PHP_INT_MAX, 1e-9], [PHP_INT_MIN, 3.3], [5, NAN], [5, INF]] as [$count, $seconds]) {
            self::assertSame(ProcessingMetrics::ratePhp($count, $seconds), ProcessingMetrics::rateNative($count, $seconds));
        }
    }

    public function test_processing_metrics_instance_results_match_with_rust_enabled(): void
    {
        foreach ([false, true] as $enabled) {
            ProcessingMetrics::setUseRustFfi($enabled);
            $metrics = new ProcessingMetrics;
            self::assertSame(42, $metrics->measure(ProcessingStage::NzbParsing, static fn (): int => 42));
            $durations = $metrics->stageDurations();
            self::assertSame([ProcessingStage::NzbParsing->value], array_keys($durations));
            self::assertIsFloat($durations[ProcessingStage::NzbParsing->value]);
            self::assertMatchesRegularExpression('/^nzb-parsing=\d+\.\d{6}s$/', $metrics->summaryLine());
            self::assertGreaterThanOrEqual(0.0, $metrics->releasesPerSecond(3));
            self::assertSame('a=1.000000s b=0.500000s', ProcessingMetrics::formatStageSummary(['a' => 1.0, 'b' => 0.5]));
            self::assertSame(['x' => 2.0], ProcessingMetrics::secondsFromNanoseconds(['x' => 2_000_000_000]));
        }
    }

    public function test_media_article_fetcher_parity(): void
    {
        $names = [
            '', 'Movie.2024.mkv', 'Fïlm.mp4', '.hidden.mkv', '-dash.mkv', 'a/b.mkv', 'dir/', '/', 'a\\b.mkv',
            "a\x01.mkv", "a\x7f.mkv", "a\x1f", "\xff.mkv", "\xc0\xaf.mkv", "\xed\xa0\x80.mkv", "tab\t.mkv",
            str_repeat('a', 4096), str_repeat('a', 4097), 'é'.str_repeat('a', 4094), '..', '.', 'x y.avi', "a\0b",
        ];
        for ($i = 0; $i < 300; $i++) {
            $names[] = $this->random->getBytesFromString("ab.-/\\\x00\x1f\x7f\xc3\xa9\xff ", $this->random->getInt(1, 24));
        }
        foreach ($names as $name) {
            self::assertSame(MediaArticleFetcher::isSafeWitnessNamePhp($name), MediaArticleFetcher::isSafeWitnessNameNative($name), bin2hex($name));
        }

        $lengths = [PHP_INT_MIN, -11, -10, -3, -1, 0, 1, 9, 10, 11, PHP_INT_MAX];
        foreach ([0, 1, 10, 750_000] as $dataLength) {
            $data = str_repeat('x', $dataLength);
            foreach ($lengths as $length) {
                $expected = MediaArticleFetcher::chunkLengthPhp($dataLength, $length);
                self::assertSame(\strlen(substr($data, 0, $length)), $expected, "substr {$dataLength}/{$length}");
                self::assertSame($expected, MediaArticleFetcher::chunkLengthNative($dataLength, $length));
            }
        }
        self::assertNull(MediaArticleFetcher::chunkLengthNative(-1, 3));
        self::assertSame(0, MediaArticleFetcher::chunkLengthPhp(-1, 3));
    }

    public function test_downloaded_archive_parity(): void
    {
        $fileSets = [
            [],
            [['name' => 'a.nfo', 'size' => 10], ['name' => 'dir', 'is_dir' => true, 'size' => 99], ['name' => 'b.mkv', 'size' => '5', 'pass' => 1]],
            [['name' => 'x'], ['name' => 'y', 'size' => -4, 'pass' => 0, 'is_dir' => 0], ['size' => 7.9, 'pass' => '0'], ['size' => null, 'pass' => 'yes']],
            [['size' => PHP_INT_MAX], ['size' => PHP_INT_MIN]],
        ];
        for ($i = 0; $i < 100; $i++) {
            $files = [];
            for ($j = 0, $n = $this->random->getInt(0, 30); $j < $n; $j++) {
                $files[] = [
                    'name' => "f{$j}.rar",
                    'size' => $this->random->getInt(0, 10 ** 10),
                    'is_dir' => $this->random->getInt(0, 9) === 0,
                    'pass' => $this->random->getInt(0, 19) === 0,
                ];
            }
            $fileSets[] = $files;
        }
        foreach ($fileSets as $files) {
            $archive = new DownloadedArchive('a.rar', 'data', $files);
            self::assertSame($archive->fileSummaryPhp(), $archive->fileSummaryNative(), json_encode($files, JSON_THROW_ON_ERROR));
        }

        $overflow = new DownloadedArchive('a.rar', 'data', [['size' => PHP_INT_MAX], ['size' => 1]]);
        self::assertNull($overflow->fileSummaryNative());
        self::assertIsFloat($overflow->fileSummaryPhp()['bytes']);
        $enabled = new DownloadedArchive('a.rar', 'data', [['size' => PHP_INT_MAX], ['size' => 1]], useRustFfi: true);
        self::assertSame($overflow->fileSummaryPhp(), $enabled->fileSummary());
        self::assertSame(4, $enabled->byteSize());
        self::assertSame(['files' => 1, 'directories' => 0, 'bytes' => 3, 'passworded' => true], (new DownloadedArchive('t', '', [['size' => 3, 'pass' => true]], true))->fileSummary());
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        foreach (self::STATIC_TOGGLE_CLASSES as $class) {
            $property = new ReflectionProperty($class, 'ffi');
            $saved = $property->getValue();
            $property->setValue(null, false);
            try {
                $class::setUseRustFfi(true);
                match ($class) {
                    AdditionalProcessingOrchestrator::class => $this->assertOrchestratorFallback(),
                    MediaByteBudget::class => $this->assertBudgetFallback(),
                    ProcessingMetrics::class => $this->assertMetricsFallback(),
                    MediaArticleFetcher::class => $this->assertFetcherFallback(),
                };
            } finally {
                $property->setValue(null, $saved);
                $class::setUseRustFfi(false);
            }
        }
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_ADDITIONAL_BUDGET_METRICS_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_ADDITIONAL_BUDGET_METRICS_BENCH=1 to run paired randomized benchmarks.');
        }

        $repetitions = max(2, (int) (getenv('NNTMUX_ADDITIONAL_BUDGET_METRICS_BENCH_REPS') ?: 2));
        $pairsPerRep = max(2000, (int) (getenv('NNTMUX_ADDITIONAL_BUDGET_METRICS_BENCH_PAIRS') ?: 5000));
        $summary = [];

        foreach ($this->buildBenchmarkWorkloads() as $methodName => $regimes) {
            foreach (['common', 'uncommon'] as $regime) {
                [$phpCall, $rustCall] = $regimes[$regime];
                self::assertEquals($phpCall(), $rustCall(), "Benchmark pre-check failed for {$methodName} ({$regime})");

                for ($w = 0; $w < 200; $w++) {
                    $phpCall();
                    $rustCall();
                }

                $phpAll = [];
                $rustAll = [];
                $repStats = [];
                for ($rep = 0; $rep < $repetitions; $rep++) {
                    $phpRep = [];
                    $rustRep = [];
                    for ($i = 0; $i < $pairsPerRep; $i++) {
                        if ($this->random->getInt(0, 1) === 1) {
                            $t0 = hrtime(true);
                            $rustCall();
                            $rustNs = hrtime(true) - $t0;
                            $t1 = hrtime(true);
                            $phpCall();
                            $phpNs = hrtime(true) - $t1;
                        } else {
                            $t0 = hrtime(true);
                            $phpCall();
                            $phpNs = hrtime(true) - $t0;
                            $t1 = hrtime(true);
                            $rustCall();
                            $rustNs = hrtime(true) - $t1;
                        }
                        $phpRep[] = $phpNs;
                        $rustRep[] = $rustNs;
                    }
                    array_push($phpAll, ...$phpRep);
                    array_push($rustAll, ...$rustRep);
                    sort($phpRep);
                    sort($rustRep);
                    $rank = (int) ceil($pairsPerRep * 0.99) - 1;
                    $repStats[] = [
                        'mean_speedup' => round((array_sum($phpRep) / $pairsPerRep) / max(1.0, array_sum($rustRep) / $pairsPerRep), 3),
                        'p99_speedup' => round($phpRep[$rank] / max(1, $rustRep[$rank]), 3),
                    ];
                }

                sort($phpAll);
                sort($rustAll);
                $total = \count($phpAll);
                $p99 = (int) ceil($total * 0.99) - 1;
                $phpMean = array_sum($phpAll) / $total;
                $rustMean = array_sum($rustAll) / $total;
                $summary[$methodName][$regime] = [
                    'samples' => $total,
                    'php_mean_ns' => round($phpMean, 1),
                    'rust_mean_ns' => round($rustMean, 1),
                    'php_p99_ns' => $phpAll[$p99],
                    'rust_p99_ns' => $rustAll[$p99],
                    'mean_speedup' => round($phpMean / max(1.0, $rustMean), 3),
                    'p99_speedup' => round($phpAll[$p99] / max(1, $rustAll[$p99]), 3),
                    'reps' => $repStats,
                ];
            }
        }

        fwrite(STDERR, "\nWave 16 Additional Budget/Metrics Benchmark Summary:\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        self::assertNotEmpty($summary);
    }

    /**
     * @return array<string, array{common: array{Closure, Closure}, uncommon: array{Closure, Closure}}>
     */
    private function buildBenchmarkWorkloads(): array
    {
        $commonBudget = $this->budget(50_000_000, 12_345_678);
        $uncommonBudget = $this->budget(PHP_INT_MIN, PHP_INT_MAX - 1);
        $commonStages = [];
        foreach (\array_slice(ProcessingStage::cases(), 0, 6) as $index => $stage) {
            $commonStages[$stage->value] = 1_234_567 * ($index + 3);
        }
        $uncommonStages = [];
        foreach (ProcessingStage::cases() as $index => $stage) {
            $uncommonStages[$stage->value] = PHP_INT_MAX - $index * 999_999_937;
        }
        $commonSeconds = ProcessingMetrics::secondsFromNanosecondsPhp($commonStages);
        $uncommonSeconds = ProcessingMetrics::secondsFromNanosecondsPhp($uncommonStages);
        $commonName = 'Some.Movie.2024.1080p.WEB-DL.mkv';
        $uncommonName = str_repeat('Ä', 2040).'.mkv';
        $commonFiles = [
            ['name' => 'movie.nfo', 'size' => 4_096, 'pass' => false, 'is_dir' => false],
            ['name' => 'movie.mkv', 'size' => 734_003_200, 'pass' => false, 'is_dir' => false],
            ['name' => 'Sample', 'size' => 0, 'pass' => false, 'is_dir' => true],
        ];
        $uncommonFiles = [];
        for ($i = 0; $i < 400; $i++) {
            $uncommonFiles[] = ['name' => "d/f{$i}.bin", 'size' => 1_000_003 * $i, 'pass' => $i === 399, 'is_dir' => $i % 50 === 0];
        }
        $commonArchive = new DownloadedArchive('a.rar', str_repeat('x', 64), $commonFiles);
        $uncommonArchive = new DownloadedArchive('a.rar', str_repeat('x', 64), $uncommonFiles);

        return [
            'AdditionalProcessingOrchestrator::elapsedSecondsBetween' => [
                'common' => [static fn (): float => AdditionalProcessingOrchestrator::elapsedSecondsBetweenPhp(1_000_000, 2_501_234_567), static fn (): ?float => AdditionalProcessingOrchestrator::elapsedSecondsBetweenNative(1_000_000, 2_501_234_567)],
                'uncommon' => [static fn (): float => AdditionalProcessingOrchestrator::elapsedSecondsBetweenPhp(-PHP_INT_MAX, 0), static fn (): ?float => AdditionalProcessingOrchestrator::elapsedSecondsBetweenNative(-PHP_INT_MAX, 0)],
            ],
            'AdditionalProcessingOrchestrator::effectiveQueryLimit' => [
                'common' => [static fn (): int => AdditionalProcessingOrchestrator::effectiveQueryLimitPhp(25), static fn (): ?int => AdditionalProcessingOrchestrator::effectiveQueryLimitNative(25)],
                'uncommon' => [static fn (): int => AdditionalProcessingOrchestrator::effectiveQueryLimitPhp(PHP_INT_MIN), static fn (): ?int => AdditionalProcessingOrchestrator::effectiveQueryLimitNative(PHP_INT_MIN)],
            ],
            'AdditionalProcessingOrchestrator::batchDisposition' => [
                'common' => [static fn (): int => AdditionalProcessingOrchestrator::batchDispositionPhp(true, 25), static fn (): ?int => AdditionalProcessingOrchestrator::batchDispositionNative(true, 25)],
                'uncommon' => [static fn (): int => AdditionalProcessingOrchestrator::batchDispositionPhp(false, 0), static fn (): ?int => AdditionalProcessingOrchestrator::batchDispositionNative(false, 0)],
            ],
            'AdditionalProcessingOrchestrator::shouldClearClaim' => [
                'common' => [static fn (): bool => AdditionalProcessingOrchestrator::shouldClearClaimPhp('0123456789abcdef0123456789abcdef', 123_456), static fn (): ?bool => AdditionalProcessingOrchestrator::shouldClearClaimNative('0123456789abcdef0123456789abcdef', 123_456)],
                'uncommon' => [static fn (): bool => AdditionalProcessingOrchestrator::shouldClearClaimPhp('', 0), static fn (): ?bool => AdditionalProcessingOrchestrator::shouldClearClaimNative('', 0)],
            ],
            'MediaByteBudget::allows' => [
                'common' => [static fn (): bool => $commonBudget->allowsPhp(750_000), static fn (): ?bool => $commonBudget->allowsNative(750_000)],
                'uncommon' => [static fn (): bool => $uncommonBudget->allowsPhp(PHP_INT_MIN), static fn (): ?bool => $uncommonBudget->allowsNative(PHP_INT_MIN)],
            ],
            'MediaByteBudget::spend' => [
                'common' => [static fn (): int => $commonBudget->spentAfterPhp(750_000), static fn (): ?int => $commonBudget->spentAfterNative(750_000)],
                'uncommon' => [static fn (): int => $uncommonBudget->spentAfterPhp(1), static fn (): ?int => $uncommonBudget->spentAfterNative(1)],
            ],
            'MediaByteBudget::remaining' => [
                'common' => [static fn (): int => $commonBudget->remainingPhp(), static fn (): ?int => $commonBudget->remainingNative()],
                'uncommon' => [static fn (): int => $uncommonBudget->remainingPhp(), static fn (): ?int => $uncommonBudget->remainingNative()],
            ],
            'MediaByteBudget::exhausted' => [
                'common' => [static fn (): bool => $commonBudget->exhaustedPhp(), static fn (): ?bool => $commonBudget->exhaustedNative()],
                'uncommon' => [static fn (): bool => $uncommonBudget->exhaustedPhp(), static fn (): ?bool => $uncommonBudget->exhaustedNative()],
            ],
            'MediaByteBudget::clampRequestBytes' => [
                'common' => [static fn (): int => $commonBudget->clampRequestBytesPhp(16_777_216), static fn (): ?int => $commonBudget->clampRequestBytesNative(16_777_216)],
                'uncommon' => [static fn (): int => $uncommonBudget->clampRequestBytesPhp(PHP_INT_MIN), static fn (): ?int => $uncommonBudget->clampRequestBytesNative(PHP_INT_MIN)],
            ],
            'MediaByteBudget::utilization' => [
                'common' => [static fn (): float => $commonBudget->utilizationPhp(), static fn (): ?float => $commonBudget->utilizationNative()],
                'uncommon' => [static fn (): float => $uncommonBudget->utilizationPhp(), static fn (): ?float => $uncommonBudget->utilizationNative()],
            ],
            'ProcessingMetrics::secondsFromNanoseconds' => [
                'common' => [static fn (): array => ProcessingMetrics::secondsFromNanosecondsPhp($commonStages), static fn (): ?array => ProcessingMetrics::secondsFromNanosecondsNative($commonStages)],
                'uncommon' => [static fn (): array => ProcessingMetrics::secondsFromNanosecondsPhp($uncommonStages), static fn (): ?array => ProcessingMetrics::secondsFromNanosecondsNative($uncommonStages)],
            ],
            'ProcessingMetrics::rate' => [
                'common' => [static fn (): float => ProcessingMetrics::ratePhp(25, 12.5), static fn (): ?float => ProcessingMetrics::rateNative(25, 12.5)],
                'uncommon' => [static fn (): float => ProcessingMetrics::ratePhp(PHP_INT_MAX, 1e-300), static fn (): ?float => ProcessingMetrics::rateNative(PHP_INT_MAX, 1e-300)],
            ],
            'ProcessingMetrics::formatStageSummary' => [
                'common' => [static fn (): string => ProcessingMetrics::formatStageSummaryPhp($commonSeconds), static fn (): ?string => ProcessingMetrics::formatStageSummaryNative($commonSeconds)],
                'uncommon' => [static fn (): string => ProcessingMetrics::formatStageSummaryPhp($uncommonSeconds), static fn (): ?string => ProcessingMetrics::formatStageSummaryNative($uncommonSeconds)],
            ],
            'MediaArticleFetcher::isSafeWitnessName' => [
                'common' => [static fn (): bool => MediaArticleFetcher::isSafeWitnessNamePhp($commonName), static fn (): ?bool => MediaArticleFetcher::isSafeWitnessNameNative($commonName)],
                'uncommon' => [static fn (): bool => MediaArticleFetcher::isSafeWitnessNamePhp($uncommonName), static fn (): ?bool => MediaArticleFetcher::isSafeWitnessNameNative($uncommonName)],
            ],
            'MediaArticleFetcher::chunkLength' => [
                'common' => [static fn (): int => MediaArticleFetcher::chunkLengthPhp(750_000, 16_000_000), static fn (): ?int => MediaArticleFetcher::chunkLengthNative(750_000, 16_000_000)],
                'uncommon' => [static fn (): int => MediaArticleFetcher::chunkLengthPhp(750_000, PHP_INT_MIN), static fn (): ?int => MediaArticleFetcher::chunkLengthNative(750_000, PHP_INT_MIN)],
            ],
            'DownloadedArchive::fileSummary' => [
                'common' => [static fn (): array => $commonArchive->fileSummaryPhp(), static fn (): ?array => $commonArchive->fileSummaryNative()],
                'uncommon' => [static fn (): array => $uncommonArchive->fileSummaryPhp(), static fn (): ?array => $uncommonArchive->fileSummaryNative()],
            ],
        ];
    }

    /**
     * @param  list<int>  $bytes
     */
    private function assertBudgetParity(MediaByteBudget $budget, array $bytes): void
    {
        $label = "limit {$budget->limit} spent {$budget->spent()}";
        $remaining = $this->phpOrNull(static fn (): int => $budget->remainingPhp());
        self::assertSame($remaining, $budget->remainingNative(), $label);
        self::assertSame($budget->exhaustedPhp(), $budget->exhaustedNative(), $label);
        self::assertSame($budget->utilizationPhp(), $budget->utilizationNative(), $label);
        foreach ($bytes as $request) {
            $allowsNative = $budget->allowsNative($request);
            if ($allowsNative !== null) {
                self::assertSame($budget->allowsPhp($request), $allowsNative, "{$label} allows {$request}");
            }
            self::assertSame($this->phpOrNull(static fn (): int => $budget->spentAfterPhp($request)), $budget->spentAfterNative($request), "{$label} spend {$request}");
            self::assertSame($this->phpOrNull(static fn (): int => $budget->clampRequestBytesPhp($request)), $budget->clampRequestBytesNative($request), "{$label} clamp {$request}");
        }
    }

    /**
     * Result of a PHP path that throws TypeError on integer overflow, or null when it does.
     *
     * @param  Closure(): int  $call
     */
    private function phpOrNull(Closure $call): ?int
    {
        try {
            return $call();
        } catch (TypeError) {
            return null;
        }
    }

    private function budget(int $limit, int $spent): MediaByteBudget
    {
        $budget = new MediaByteBudget($limit);
        (new ReflectionProperty(MediaByteBudget::class, 'spent'))->setValue($budget, $spent);

        return $budget;
    }

    private function assertOrchestratorFallback(): void
    {
        self::assertNull(AdditionalProcessingOrchestrator::elapsedSecondsBetweenNative(0, 1));
        self::assertSame(1.5, AdditionalProcessingOrchestrator::elapsedSecondsBetween(0, 1_500_000_000));
        self::assertSame(25, AdditionalProcessingOrchestrator::effectiveQueryLimit(-1));
        self::assertSame(AdditionalProcessingOrchestrator::BATCH_EMPTY, AdditionalProcessingOrchestrator::batchDisposition(true, 0));
        self::assertTrue(AdditionalProcessingOrchestrator::shouldClearClaim('t', 1));
    }

    private function assertBudgetFallback(): void
    {
        $budget = $this->budget(10, 4);
        self::assertNull($budget->allowsNative(1));
        self::assertTrue($budget->allows(6));
        self::assertSame(6, $budget->remaining());
        self::assertSame(3, $budget->clampRequestBytes(3));
        self::assertSame(0.4, $budget->utilization());
        $budget->spend(6);
        self::assertTrue($budget->exhausted());
    }

    private function assertMetricsFallback(): void
    {
        self::assertNull(ProcessingMetrics::secondsFromNanosecondsNative(['a' => 1]));
        self::assertSame(['a' => 1.0], ProcessingMetrics::secondsFromNanoseconds(['a' => 1_000_000_000]));
        self::assertSame(2.5, ProcessingMetrics::rate(10, 4.0));
        self::assertSame('a=1.000000s', ProcessingMetrics::formatStageSummary(['a' => 1.0]));
    }

    private function assertFetcherFallback(): void
    {
        self::assertNull(MediaArticleFetcher::isSafeWitnessNameNative('a.mkv'));
        self::assertTrue(MediaArticleFetcher::isSafeWitnessName('a.mkv'));
        self::assertFalse(MediaArticleFetcher::isSafeWitnessName('.a.mkv'));
        self::assertSame(4, MediaArticleFetcher::chunkLength(10, 4));
    }
}
