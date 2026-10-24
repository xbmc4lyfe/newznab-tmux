<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Enums\ServiceStatusEnum;
use App\Models\Category;
use App\Models\ServiceStatus;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Services\Categorization\Pipes\CategorizationPassable;
use App\Services\Categorization\ReleaseContext;
use App\Services\Monitoring\Collectors\TmuxSnapshotCollector;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\SiteStatusService;
use App\Services\Tmux\CollectionDowntimeCompensator;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;

/**
 * Wave 17 Worker 6 parity and paired benchmark suite for `AbstractCategorizationPipe`,
 * `Prometheus\MetricFamily`, `TmuxSnapshotCollector`, `CollectionDowntimeCompensator`,
 * and `SiteStatusService` (`rust/pipeline-ffi/src/categorize.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   NNTMUX_CATEGORIZE_METRICS_STATUS_BENCH=1 php artisan test --compact tests/Unit/RustCategorizeMetricsStatusParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon); nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x speedup on all 4 metrics
 * (common mean, common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Paired speedup results (`PHP latency / Rust FFI latency`, mean/p99 across 3 reps):
 * - `pipeSkipGuard`:            common 0.29x/0.25x, 0.31x/0.34x, 0.31x/0.25x | uncommon 0.30x/0.25x, 0.30x/0.34x, 0.30x/0.25x
 * - `pipeMatched`:              common 0.25x/0.29x, 0.25x/0.23x, 0.25x/0.29x | uncommon 0.23x/0.19x, 0.24x/0.29x, 0.24x/0.27x
 * - `metricValidate`:           common 0.41x/0.50x, 0.45x/0.40x, 0.43x/0.50x | uncommon 0.40x/0.40x, 0.49x/0.37x, 0.40x/0.40x
 * - `metricNormalizeSample`:    common 0.21x/0.20x, 0.20x/0.23x, 0.21x/0.27x | uncommon 0.27x/0.26x, 0.27x/0.26x, 0.28x/0.32x
 * - `collectorExtractSeries`:   common 0.44x/0.25x, 0.41x/0.30x, 0.42x/0.42x | uncommon 0.37x/0.32x, 0.37x/0.32x, 0.37x/0.34x
 * - `downtimeAt`:               common 6.22x/7.28x, 6.17x/6.00x, 6.38x/7.70x | uncommon 2.54x/3.00x, 2.60x/2.58x, 2.62x/4.13x
 * - `downtimeTimestamp`:        common 4.74x/4.73x, 4.58x/4.26x, 4.77x/6.46x | uncommon 0.56x/0.38x, 0.56x/0.52x, 0.55x/0.42x
 * - `downtimeLargestOffset`:    common 0.83x/0.87x, 0.81x/0.76x, 0.84x/0.82x | uncommon 0.88x/0.88x, 0.86x/0.85x, 0.86x/0.83x
 * - `downtimeCrossesOffset`:    common 0.84x/0.80x, 0.83x/0.83x, 0.81x/0.85x | uncommon 0.83x/0.83x, 0.83x/0.85x, 0.80x/0.83x
 * - `downtimeTimestampNotAfter`:common 1.83x/1.82x, 1.80x/1.80x, 1.79x/1.83x | uncommon 0.74x/0.55x, 0.74x/0.62x, 0.74x/0.70x
 * - `siteStatusMergeIntervals`: common 0.64x/0.67x, 0.64x/0.60x, 0.66x/0.67x | uncommon 3.65x/5.33x, 3.69x/3.66x, 3.71x/3.68x
 * - `siteStatusAggregateWorst`: common 0.86x/1.00x, 0.89x/1.00x, 0.86x/1.00x | uncommon 1.42x/1.41x, 1.35x/1.40x, 1.35x/1.38x
 * - `siteStatusMapImpact`:      common 0.40x/0.43x, 0.39x/0.67x, 0.41x/0.67x | uncommon 0.43x/0.51x, 0.42x/0.67x, 0.42x/0.51x
 *
 * Per `.ai/rules/rust-performance.md`, `$useRustFfi = false` is retained as the
 * production default across all five classes while keeping `*Native` and `*Php`
 * methods tested for 100% parity.
 */
final class RustCategorizeMetricsStatusParityTest extends TestCase
{
    private const string FULL_CDEF = AbstractCategorizationPipe::FFI_CDEF."\n"
        .MetricFamily::FFI_CDEF."\n"
        .TmuxSnapshotCollector::FFI_CDEF."\n"
        .CollectionDowntimeCompensator::FFI_CDEF."\n"
        .SiteStatusService::FFI_CDEF;

    protected function tearDown(): void
    {
        AbstractCategorizationPipe::setUseRustFfi(false);
        MetricFamily::setUseRustFfi(false);
        TmuxSnapshotCollector::setUseRustFfi(false);
        CollectionDowntimeCompensator::setUseRustFfi(false);
        SiteStatusService::setUseRustFfi(false);
        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function bare(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Expected libnntmux_pipeline categorize domain FFI to be available.');

        return $ffi;
    }

    private static function dummyPipe(): AbstractCategorizationPipe
    {
        return new class extends AbstractCategorizationPipe
        {
            public bool $forceSkip = false;

            public ?CategorizationResult $nextResult = null;

            public function getName(): string
            {
                return 'DummyPipe';
            }

            protected function shouldSkip(ReleaseContext $context): bool
            {
                if ($this->forceSkip) {
                    return true;
                }

                return parent::shouldSkip($context);
            }

            protected function categorize(ReleaseContext $context): CategorizationResult
            {
                return $this->nextResult ?? $this->matched(Category::TV_HD, 0.85, 'dummy_tv_hd', ['ctx' => $context->releaseName]);
            }
        };
    }

    public function test_abstract_categorization_pipe_helpers_and_handle_match_php(): void
    {
        self::ffi();
        $pipe = self::dummyPipe();
        $ctx = new ReleaseContext('Show.Name.S01E01.1080p.WEB-DL-GRP', 1, 'alt.binaries.teevee');

        $confidences = [-0.1, 0.0, 0.5, 0.94, 0.9499999, 0.95, 0.9500001, 1.0, NAN];
        foreach ([false, true] as $locked) {
            foreach ($confidences as $conf) {
                foreach ([false, true] as $pipeSkip) {
                    $expected = $pipe->evaluateSkipGuardPhp($locked, $conf, $pipeSkip);
                    self::assertSame(
                        $expected,
                        $pipe->evaluateSkipGuardNative($locked, $conf, $pipeSkip),
                        'locked='.(int) $locked.' conf='.var_export($conf, true).' skip='.(int) $pipeSkip
                    );
                    foreach ([false, true] as $toggle) {
                        AbstractCategorizationPipe::setUseRustFfi($toggle);
                        self::assertSame($expected, $pipe->evaluateSkipGuard($locked, $conf, $pipeSkip));
                    }
                }
            }
        }

        self::assertFalse($pipe->shouldSkipPhp($ctx));
        self::assertFalse($pipe->shouldSkipNative($ctx));

        $matchedCases = [
            [Category::TV_HD, 0.85, 'tv_hd_pattern', ['rule' => 'hdtv']],
            [Category::MOVIE_UHD, 0.95, 'movie_uhd_2160p', []],
            [Category::OTHER_MISC, 0.40, '', ['empty' => true]],
            [Category::GAME_PS4, 0.75, "matched\0with\0nul", ['nul' => 1]],
            [Category::BOOKS_EBOOK, 0.90, str_repeat('long_matched_by_', 24), ['long' => true]],
        ];

        foreach ($matchedCases as [$catId, $conf, $matchedBy, $debug]) {
            $expected = $pipe->matchedPhp($catId, $conf, $matchedBy, $debug);
            $actual = $pipe->matchedNative($catId, $conf, $matchedBy, $debug);
            self::assertSame($expected->categoryId, $actual->categoryId);
            self::assertSame($expected->confidence, $actual->confidence);
            self::assertSame($expected->matchedBy, $actual->matchedBy);
            self::assertSame($expected->debug, $actual->debug);
        }

        $noMatchPhp = $pipe->noMatchPhp();
        $noMatchNative = $pipe->noMatchNative();
        self::assertSame($noMatchPhp->categoryId, $noMatchNative->categoryId);
        self::assertSame($noMatchPhp->confidence, $noMatchNative->confidence);
        self::assertSame($noMatchPhp->matchedBy, $noMatchNative->matchedBy);
        self::assertSame($noMatchPhp->debug, $noMatchNative->debug);

        foreach ([false, true] as $toggle) {
            AbstractCategorizationPipe::setUseRustFfi($toggle);
            $passable = new CategorizationPassable($ctx, true);
            $out = $pipe->handle($passable, static fn (CategorizationPassable $p): CategorizationPassable => $p);
            self::assertSame(Category::TV_HD, $out->bestResult->categoryId);
            self::assertSame('dummy_tv_hd', $out->bestResult->matchedBy);
        }
    }

    public function test_prometheus_metric_family_validation_and_normalization_match_php(): void
    {
        self::ffi();

        $validDefinitions = [
            ['nntmux_up', MetricFamily::GAUGE],
            ['nntmux:processing:backlog_total', MetricFamily::COUNTER],
            ['_leading_underscore:metric_1', MetricFamily::GAUGE],
            [':leading_colon_99', MetricFamily::COUNTER],
            ["metric_with_trailing_newline\n", MetricFamily::GAUGE],
        ];

        foreach ($validDefinitions as [$name, $type]) {
            MetricFamily::validateDefinitionPhp($name, $type);
            MetricFamily::validateDefinitionNative($name, $type);

            foreach ([false, true] as $toggle) {
                MetricFamily::setUseRustFfi($toggle);
                MetricFamily::validateDefinition($name, $type);
                $g = MetricFamily::gauge($name, 'Help text');
                self::assertSame(MetricFamily::GAUGE, $g->type);
                $c = MetricFamily::counter($name, 'Help text');
                self::assertSame(MetricFamily::COUNTER, $c->type);
            }
        }

        $invalidDefinitions = [
            ['', MetricFamily::GAUGE],
            ['9starts_with_digit', MetricFamily::GAUGE],
            ['nntmux-bad-hyphen', MetricFamily::GAUGE],
            ["metric_two_newlines\n\n", MetricFamily::GAUGE],
            ['nntmux_valid_name', 'histogram'],
            ['nntmux_valid_name', 'summary'],
            ['nntmux_valid_name', ''],
        ];

        foreach ($invalidDefinitions as [$name, $type]) {
            $phpMsg = null;
            try {
                MetricFamily::validateDefinitionPhp($name, $type);
            } catch (InvalidArgumentException $e) {
                $phpMsg = $e->getMessage();
            }

            $nativeMsg = null;
            try {
                MetricFamily::validateDefinitionNative($name, $type);
            } catch (InvalidArgumentException $e) {
                $nativeMsg = $e->getMessage();
            }

            self::assertNotNull($phpMsg, "Expected exception for {$name} / {$type}");
            self::assertSame($phpMsg, $nativeMsg);
        }

        $sampleCases = [
            [1, []],
            [42, ['type' => 'nfo', 'server' => 'primary']],
            [0.25, ['_private_label' => 123, 'float_label' => 1.5, 'empty_val' => '']],
            [2.0, ['with_newline_val' => "line1\nline2", 'with_nul_val' => "a\0b"]],
            [100, ['long_val' => str_repeat('segment_value_', 32)]],
        ];

        foreach ($sampleCases as [$val, $labels]) {
            $expected = MetricFamily::normalizeSamplePhp($val, $labels);
            $actual = MetricFamily::normalizeSampleNative($val, $labels);
            self::assertSame($expected, $actual);

            foreach ([false, true] as $toggle) {
                MetricFamily::setUseRustFfi($toggle);
                self::assertSame($expected, MetricFamily::normalizeSample($val, $labels));
                $fam = MetricFamily::gauge('nntmux_test', 'Help')->add($val, $labels);
                self::assertSame([$expected], $fam->samples());
            }
        }

        $invalidLabelSets = [
            ['bad-label' => 'x'],
            ['valid_first' => 'ok', '9bad_second' => 'y'],
            ['valid_first' => 'ok', 'bad:colon' => 'z'],
            ['' => 'empty'],
        ];

        foreach ($invalidLabelSets as $badLabels) {
            $phpErr = null;
            try {
                MetricFamily::normalizeSamplePhp(1, $badLabels);
            } catch (InvalidArgumentException $e) {
                $phpErr = $e->getMessage();
            }

            $nativeErr = null;
            try {
                MetricFamily::normalizeSampleNative(1, $badLabels);
            } catch (InvalidArgumentException $e) {
                $nativeErr = $e->getMessage();
            }

            self::assertNotNull($phpErr);
            self::assertSame($phpErr, $nativeErr);
        }
    }

    public function test_tmux_snapshot_collector_series_and_families_match_php(): void
    {
        self::ffi();
        $collector = self::bare(TmuxSnapshotCollector::class);

        $ageCases = [
            [1_700_000_000, 1_700_000_000],
            [1_700_000_000, 1_700_000_045],
            [1_700_000_100, 1_700_000_000],
            [0, 1_700_000_000],
        ];

        foreach ($ageCases as [$collectedAt, $now]) {
            $expectedAge = $collector->computeSnapshotAgePhp($collectedAt, $now);
            self::assertSame($expectedAge, $collector->computeSnapshotAgeNative($collectedAt, $now));
            foreach ([false, true] as $toggle) {
                TmuxSnapshotCollector::setUseRustFfi($toggle);
                self::assertSame($expectedAge, $collector->computeSnapshotAge($collectedAt, $now));
            }
        }

        $countCases = [
            [],
            [
                'processnfo' => 12,
                'processtv' => 8,
                'processmovies' => 3,
                'process' => 1,
                'collections_table' => 500,
                'binaries_table' => 2500,
                'parts_table' => 15000,
                'missed_parts_table' => 40,
                'tv' => 100,
                'movies' => 200,
                'audio' => 50,
                'books' => 30,
                'console' => 10,
                'pc' => 25,
                'xxx' => 5,
                'misc' => 80,
                'total_work' => 24,
                'work' => 10,
                'work_available' => 7,
                'releases' => 500,
                'predb' => 10000,
                'predb_matched' => 350,
                'nfo' => 420,
                'renamed' => 310,
                'active_groups' => 45,
                'all_groups' => 120,
                'unrelated_key' => 999,
            ],
            [
                'processbooks' => 4.5,
                'parts_table' => 99,
                'misc' => 12,
                'releases' => 12,
            ],
        ];

        foreach ($countCases as $idx => $counts) {
            $expectedSeries = $collector->extractCountSeriesPhp($counts);
            self::assertSame($expectedSeries, $collector->extractCountSeriesNative($counts), "series case {$idx}");
            foreach ([false, true] as $toggle) {
                TmuxSnapshotCollector::setUseRustFfi($toggle);
                self::assertSame($expectedSeries, $collector->extractCountSeries($counts));
            }
        }

        $snapshots = [
            null,
            [
                'collected_at' => 1_700_000_000,
                'is_running' => 1,
                'counts' => $countCases[1],
                'connections' => [
                    'primary' => ['active' => 4, 'total' => 20],
                    'backup' => ['active' => 1, 'total' => 10],
                ],
                'query_timers' => [
                    'proc1_time' => 0.25,
                    'split_time' => 1.5,
                ],
            ],
        ];

        $serializeFamilies = static function (array $families): array {
            $out = [];
            foreach ($families as $f) {
                $out[] = [
                    'name' => $f->name,
                    'help' => $f->help,
                    'type' => $f->type,
                    'samples' => $f->samples(),
                ];
            }

            return $out;
        };

        foreach ($snapshots as $snap) {
            $expectedFams = $serializeFamilies($collector->buildMetricFamiliesPhp($snap, 1_700_000_030));
            $actualFams = $serializeFamilies($collector->buildMetricFamiliesNative($snap, 1_700_000_030));
            self::assertSame($expectedFams, $actualFams);

            foreach ([false, true] as $toggle) {
                TmuxSnapshotCollector::setUseRustFfi($toggle);
                self::assertSame($expectedFams, $serializeFamilies($collector->buildMetricFamilies($snap, 1_700_000_030)));
            }
        }
    }

    public function test_collection_downtime_compensator_helpers_match_php(): void
    {
        self::ffi();
        $markerDir = sys_get_temp_dir().'/nntmux-rust-parity-marker-'.uniqid('', true);
        @mkdir($markerDir, 0775, true);
        $markerPath = $markerDir.'/marker';
        $compensator = new CollectionDowntimeCompensator($markerPath);

        $previousTz = date_default_timezone_get();
        try {
            $timezones = ['UTC', 'America/New_York', 'Europe/Berlin', 'Antarctica/Casey'];
            $timestamps = [
                0,
                -86400,
                1_709_164_800, // 2024-02-29 00:00:00 UTC (leap year)
                1_772_952_600, // 2026-03-08 06:50:00 UTC (NY spring forward)
                1_772_953_800, // 2026-03-08 07:10:00 UTC
                1_791_720_000, // 2026-10-11 12:00:00 UTC
                1_793_512_200, // 2026-11-01 05:50:00 UTC (NY fall back)
                1_793_513_400, // 2026-11-01 06:10:00 UTC
                1_647_088_800, // 2022-03-12 12:40:00 UTC (Casey 3h fall back)
            ];

            foreach ($timezones as $tz) {
                date_default_timezone_set($tz);

                foreach ($timestamps as $ts) {
                    $expectedAt = $compensator->atPhp($ts);
                    self::assertSame($expectedAt, $compensator->atNative($ts), "at tz={$tz} ts={$ts}");

                    $expectedBack = $compensator->timestampPhp($expectedAt);
                    self::assertSame($expectedBack, $compensator->timestampNative($expectedAt), "timestamp tz={$tz} dt={$expectedAt}");

                    $withSubsec = $expectedAt.'.123456';
                    self::assertSame(
                        $compensator->timestampPhp($withSubsec),
                        $compensator->timestampNative($withSubsec),
                        "timestamp subsec tz={$tz}"
                    );

                    foreach ([false, true] as $toggle) {
                        CollectionDowntimeCompensator::setUseRustFfi($toggle);
                        self::assertSame($expectedAt, $compensator->at($ts));
                        self::assertSame($expectedBack, $compensator->timestamp($expectedAt));
                    }
                }

                $spans = [
                    [1_791_710_000, 1_791_716_400, 1_791_720_000],
                    [1_772_945_400, 1_772_952_600, 1_772_953_800],
                    [1_793_505_000, 1_793_512_200, 1_793_513_400],
                    [1_647_081_600, 1_647_088_800, 1_647_090_600],
                ];

                foreach ($spans as [$w, $l, $n]) {
                    $expectedCross = $compensator->crossesOffsetChangePhp($w, $l, $n);
                    $expectedFold = $compensator->largestOffsetChangePhp($w, $l, $n);

                    self::assertSame($expectedCross, $compensator->crossesOffsetChangeNative($w, $l, $n), "crosses tz={$tz}");
                    self::assertSame($expectedFold, $compensator->largestOffsetChangeNative($w, $l, $n), "fold tz={$tz}");

                    foreach ([false, true] as $toggle) {
                        CollectionDowntimeCompensator::setUseRustFfi($toggle);
                        self::assertSame($expectedCross, $compensator->crossesOffsetChange($w, $l, $n));
                        self::assertSame($expectedFold, $compensator->largestOffsetChange($w, $l, $n));
                    }
                }
            }

            // Ambiguous repeated fall-back wall-clock timestamps in America/New_York and Antarctica/Casey
            date_default_timezone_set('America/New_York');
            $nyCases = [
                ['2026-11-01 01:45:00', 1_793_513_400], // before 01:10 EST restart -> 01:45 EDT
                ['2026-11-01 01:45:00', 1_793_520_600], // before 03:10 EST restart -> 01:45 EST
                ['2026-11-01 01:10:00', 1_793_519_400], // before 02:50 EST shutdown -> 01:10 EST
                ['2026-10-11 10:30:00', 1_791_720_000],
            ];
            foreach ($nyCases as [$dt, $notAfter]) {
                $expected = $compensator->timestampNotAfterPhp($dt, $notAfter);
                self::assertSame($expected, $compensator->timestampNotAfterNative($dt, $notAfter), "NY dt={$dt} notAfter={$notAfter}");
                foreach ([false, true] as $toggle) {
                    CollectionDowntimeCompensator::setUseRustFfi($toggle);
                    self::assertSame($expected, $compensator->timestampNotAfter($dt, $notAfter));
                }
            }

            date_default_timezone_set('Antarctica/Casey');
            $caseyCases = [
                ['2022-03-12 23:10:00', 1_647_090_600],
                ['2022-03-12 23:10:00', 1_647_080_000],
            ];
            foreach ($caseyCases as [$dt, $notAfter]) {
                $expected = $compensator->timestampNotAfterPhp($dt, $notAfter);
                self::assertSame($expected, $compensator->timestampNotAfterNative($dt, $notAfter), "Casey dt={$dt}");
            }

            // prepareMarker Php / Native parity
            $tmpPhp = $compensator->prepareMarkerPhp("1791720000\n");
            self::assertFileExists($tmpPhp);
            self::assertSame("1791720000\n", file_get_contents($tmpPhp));
            @unlink($tmpPhp);

            $tmpNative = $compensator->prepareMarkerNative("1791720000\n");
            self::assertFileExists($tmpNative);
            self::assertSame("1791720000\n", file_get_contents($tmpNative));
            @unlink($tmpNative);
        } finally {
            date_default_timezone_set($previousTz);
            @rmdir($markerDir);
        }
    }

    public function test_site_status_service_helpers_match_php(): void
    {
        self::ffi();
        $service = self::bare(SiteStatusService::class);

        $intervalSets = [
            [],
            [[100, 200]],
            [[100, 200], [300, 400]],
            [[300, 400], [100, 200]],
            [[100, 200], [150, 300]],
            [[100, 200], [200, 300]],
            [[100, 500], [150, 250], [300, 400]],
            [[100, 200], [100, 350], [100, 150]],
            [[-500, -200], [-300, -100], [0, 50]],
        ];

        $random = new Randomizer(new Mt19937(17006));
        for ($i = 0; $i < 64; $i++) {
            $count = $random->getInt(1, 24);
            $set = [];
            for ($j = 0; $j < $count; $j++) {
                $start = $random->getInt(0, 10_000);
                $end = $start + $random->getInt(0, 2_000);
                $set[] = [$start, $end];
            }
            $intervalSets[] = $set;
        }

        foreach ($intervalSets as $idx => $intervals) {
            $expected = $service->mergeIntervalsPhp($intervals);
            self::assertSame($expected, $service->mergeIntervalsNative($intervals), "mergeIntervals case {$idx}");
            foreach ([false, true] as $toggle) {
                SiteStatusService::setUseRustFfi($toggle);
                self::assertSame($expected, $service->mergeIntervals($intervals));
            }
        }

        $statusLists = [
            [],
            [ServiceStatusEnum::Operational],
            [ServiceStatusEnum::Operational, ServiceStatusEnum::Degraded],
            [ServiceStatusEnum::Degraded, ServiceStatusEnum::Maintenance, ServiceStatusEnum::Operational],
            [ServiceStatusEnum::Operational, ServiceStatusEnum::PartialOutage, ServiceStatusEnum::Maintenance],
            [ServiceStatusEnum::Degraded, ServiceStatusEnum::MajorOutage, ServiceStatusEnum::PartialOutage],
            [ServiceStatusEnum::MajorOutage, ServiceStatusEnum::Operational],
        ];

        foreach ($statusLists as $idx => $statuses) {
            $models = new EloquentCollection(array_map(static function (ServiceStatusEnum $s): ServiceStatus {
                $m = new ServiceStatus;
                $m->status = $s;

                return $m;
            }, $statuses));

            $expected = $service->aggregateWorstStatusPhp($models);
            self::assertSame($expected, $service->aggregateWorstStatusNative($models), "aggregateWorstStatus case {$idx}");
            self::assertSame($expected, $service->aggregateWorstStatusNative($statuses));
            foreach ([false, true] as $toggle) {
                SiteStatusService::setUseRustFfi($toggle);
                self::assertSame($expected, $service->aggregateWorstStatus($models));
            }
        }

        foreach (IncidentImpactEnum::cases() as $impact) {
            $expected = $service->mapIncidentImpactToServiceHealthPhp($impact);
            self::assertSame($expected, $service->mapIncidentImpactToServiceHealthNative($impact));
            foreach ([false, true] as $toggle) {
                SiteStatusService::setUseRustFfi($toggle);
                self::assertSame($expected, $service->mapIncidentImpactToServiceHealth($impact));
            }
        }
    }

    public function test_direct_c_abi_null_overflow_and_small_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $small = $ffi->new('char[4]');
        $outCat = $ffi->new('int64_t');
        $outConf = $ffi->new('double');
        $outIdx = $ffi->new('size_t');

        // AbstractCategorizationPipe C-ABI contracts
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_pipe_should_skip(2, 0.5, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_pipe_should_skip(0, 0.5, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_categorize_pipe_format_result_meta(0, 10, 0.5, null, 4, FFI::addr($outCat), FFI::addr($outConf), $small, 4));
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_categorize_pipe_format_result_meta(1, 0, 0.0, '', 0, FFI::addr($outCat), FFI::addr($outConf), $small, 4));

        // MetricFamily C-ABI contracts
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_prometheus_validate_metric_definition(null, 4, 'gauge', 5));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_prometheus_normalize_labels_nul(null, 4, 1, FFI::addr($outIdx), $small, 4));
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_prometheus_normalize_labels_nul("server\0primary", 14, 1, FFI::addr($outIdx), $small, 4));

        // TmuxSnapshotCollector C-ABI contracts
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_collector_snapshot_age(PHP_INT_MAX, PHP_INT_MIN));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_tmux_collector_classify_count_keys_nul('processnfo', 10, 1, null, null, null, $small, 4));

        // CollectionDowntimeCompensator C-ABI contracts
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_downtime_format_at(PHP_INT_MAX, 100, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_downtime_format_at(1_700_000_000, 0, $small, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_downtime_parse_civil_datetime('2026-10-11 12:00:00', 19, null));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_downtime_parse_civil_datetime('2026-02-30 12:00:00', 19, FFI::addr($outCat)));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_downtime_largest_offset_change(null, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_downtime_crosses_offset_change(-2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_downtime_select_timestamp_not_after(100, 200, null, 2, FFI::addr($outCat)));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_downtime_verify_marker_dir(null, 4, '/tmp', 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_downtime_verify_marker_write(10, 10, 2, 1));

        // SiteStatusService C-ABI contracts
        $inPairs = $ffi->new('int64_t[4]');
        $inPairs[0] = 100;
        $inPairs[1] = 200;
        $inPairs[2] = 300;
        $inPairs[3] = 400;
        $smallPairs = $ffi->new('int64_t[2]');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_status_merge_intervals(null, 2, $smallPairs, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_site_status_merge_intervals($inPairs, 2, $smallPairs, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_status_aggregate_worst_nul(null, 5, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_status_aggregate_worst_nul('invalid_status', 14, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_site_status_map_incident_impact('unknown', 7));
    }

    public function test_native_methods_gracefully_fallback_to_php_when_ffi_unavailable(): void
    {
        $classes = [
            AbstractCategorizationPipe::class,
            MetricFamily::class,
            TmuxSnapshotCollector::class,
            CollectionDowntimeCompensator::class,
            SiteStatusService::class,
        ];

        $saved = [];
        foreach ($classes as $class) {
            $prop = new ReflectionProperty($class, 'ffi');
            $saved[$class] = $prop->getValue();
            $prop->setValue(null, false);
        }

        try {
            $pipe = self::dummyPipe();
            self::assertTrue($pipe->evaluateSkipGuardNative(false, 0.96, false));
            self::assertSame(Category::TV_HD, $pipe->matchedNative(Category::TV_HD, 0.9, 'tv')->categoryId);
            self::assertSame('no_match', $pipe->noMatchNative()->matchedBy);

            $fam = MetricFamily::gaugeNative('nntmux_fallback_test', 'Help');
            self::assertSame(MetricFamily::GAUGE, $fam->type);
            self::assertSame(
                ['labels' => ['k' => '1'], 'value' => 5],
                MetricFamily::normalizeSampleNative(5, ['k' => 1])
            );

            $collector = self::bare(TmuxSnapshotCollector::class);
            self::assertSame(20, $collector->computeSnapshotAgeNative(100, 120));
            $series = $collector->extractCountSeriesNative(['processnfo' => 7, 'releases' => 99]);
            self::assertSame(['nfo' => 7], $series['backlog']);
            self::assertSame(99, $series['scalars']['releases']);

            $compensator = new CollectionDowntimeCompensator(sys_get_temp_dir().'/nntmux-fallback-marker');
            $at = $compensator->atNative(1_791_720_000);
            self::assertSame(1_791_720_000, $compensator->timestampNative($at));

            $statusService = self::bare(SiteStatusService::class);
            self::assertSame([[10, 30]], $statusService->mergeIntervalsNative([[20, 30], [10, 25]]));
            self::assertSame(
                ServiceStatusEnum::PartialOutage,
                $statusService->aggregateWorstStatusNative([ServiceStatusEnum::Degraded, ServiceStatusEnum::PartialOutage])
            );
            self::assertSame(
                ServiceStatusEnum::MajorOutage,
                $statusService->mapIncidentImpactToServiceHealthNative(IncidentImpactEnum::Critical)
            );
        } finally {
            foreach ($classes as $class) {
                $prop = new ReflectionProperty($class, 'ffi');
                $prop->setValue(null, $saved[$class]);
            }
        }
    }

    /**
     * Run with:
     * NNTMUX_CATEGORIZE_METRICS_STATUS_BENCH=1 php artisan test --compact tests/Unit/RustCategorizeMetricsStatusParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_CATEGORIZE_METRICS_STATUS_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_CATEGORIZE_METRICS_STATUS_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $pipe = self::dummyPipe();
        $collector = self::bare(TmuxSnapshotCollector::class);
        $compensator = new CollectionDowntimeCompensator(sys_get_temp_dir().'/nntmux-bench-marker');
        $statusService = self::bare(SiteStatusService::class);

        $countsCommon = [
            'processnfo' => 12,
            'processtv' => 4,
            'collections_table' => 400,
            'binaries_table' => 1800,
            'parts_table' => 12000,
            'tv' => 80,
            'movies' => 120,
            'releases' => 2500,
            'total_work' => 16,
            'active_groups' => 30,
            'all_groups' => 95,
        ];
        $countsUncommon = $countsCommon;
        for ($i = 0; $i < 16; $i++) {
            $countsUncommon["processstage_{$i}"] = $i * 7;
        }
        $countsUncommon['missed_parts_table'] = 90;
        $countsUncommon['audio'] = 40;
        $countsUncommon['books'] = 22;
        $countsUncommon['console'] = 9;
        $countsUncommon['pc'] = 18;
        $countsUncommon['xxx'] = 3;
        $countsUncommon['misc'] = 55;

        $intervalsCommon = [[1_000, 1_200], [1_150, 1_400], [2_000, 2_300], [1_350, 1_500]];
        $intervalsUncommon = [];
        for ($i = 0; $i < 32; $i++) {
            $start = (($i * 37) % 20) * 300;
            $intervalsUncommon[] = [$start, $start + 450];
        }

        $statusesCommon = [
            ServiceStatusEnum::Operational,
            ServiceStatusEnum::Operational,
            ServiceStatusEnum::Degraded,
            ServiceStatusEnum::Operational,
        ];
        $statusesUncommon = array_merge(
            array_fill(0, 12, ServiceStatusEnum::Operational),
            [ServiceStatusEnum::Degraded, ServiceStatusEnum::Maintenance, ServiceStatusEnum::PartialOutage, ServiceStatusEnum::MajorOutage]
        );

        $workloads = [
            'pipeSkipGuard/common' => [
                static fn () => $pipe->evaluateSkipGuardPhp(false, 0.85, false),
                static fn () => $pipe->evaluateSkipGuardNative(false, 0.85, false),
            ],
            'pipeSkipGuard/uncommon' => [
                static fn () => $pipe->evaluateSkipGuardPhp(false, 0.96, true),
                static fn () => $pipe->evaluateSkipGuardNative(false, 0.96, true),
            ],
            'pipeMatched/common' => [
                static fn () => $pipe->matchedPhp(Category::TV_HD, 0.85, 'tv_hd_pattern')->matchedBy,
                static fn () => $pipe->matchedNative(Category::TV_HD, 0.85, 'tv_hd_pattern')->matchedBy,
            ],
            'pipeMatched/uncommon' => [
                static fn () => $pipe->matchedPhp(Category::MOVIE_UHD, 0.95, 'movie_uhd_2160p_bluray_remux', ['rule' => 'uhd'])->matchedBy,
                static fn () => $pipe->matchedNative(Category::MOVIE_UHD, 0.95, 'movie_uhd_2160p_bluray_remux', ['rule' => 'uhd'])->matchedBy,
            ],
            'metricValidate/common' => [
                static function (): string {
                    MetricFamily::validateDefinitionPhp('nntmux_processing_backlog', MetricFamily::GAUGE);

                    return MetricFamily::GAUGE;
                },
                static function (): string {
                    MetricFamily::validateDefinitionNative('nntmux_processing_backlog', MetricFamily::GAUGE);

                    return MetricFamily::GAUGE;
                },
            ],
            'metricValidate/uncommon' => [
                static function (): string {
                    MetricFamily::validateDefinitionPhp('nntmux:subsystem:detailed_metric_counter_total', MetricFamily::COUNTER);

                    return MetricFamily::COUNTER;
                },
                static function (): string {
                    MetricFamily::validateDefinitionNative('nntmux:subsystem:detailed_metric_counter_total', MetricFamily::COUNTER);

                    return MetricFamily::COUNTER;
                },
            ],
            'metricNormalizeSample/common' => [
                static fn () => MetricFamily::normalizeSamplePhp(42, ['type' => 'nfo']),
                static fn () => MetricFamily::normalizeSampleNative(42, ['type' => 'nfo']),
            ],
            'metricNormalizeSample/uncommon' => [
                static fn () => MetricFamily::normalizeSamplePhp(12.5, ['server' => 'primary', 'state' => 'active', 'pool' => 2, 'region' => 'eu_west']),
                static fn () => MetricFamily::normalizeSampleNative(12.5, ['server' => 'primary', 'state' => 'active', 'pool' => 2, 'region' => 'eu_west']),
            ],
            'collectorExtractSeries/common' => [
                static fn () => $collector->extractCountSeriesPhp($countsCommon),
                static fn () => $collector->extractCountSeriesNative($countsCommon),
            ],
            'collectorExtractSeries/uncommon' => [
                static fn () => $collector->extractCountSeriesPhp($countsUncommon),
                static fn () => $collector->extractCountSeriesNative($countsUncommon),
            ],
            'downtimeAt/common' => [
                static fn () => $compensator->atPhp(1_791_720_000),
                static fn () => $compensator->atNative(1_791_720_000),
            ],
            'downtimeAt/uncommon' => [
                static function () use ($compensator): string {
                    $prev = date_default_timezone_get();
                    date_default_timezone_set('America/New_York');
                    try {
                        return $compensator->atPhp(1_793_512_200);
                    } finally {
                        date_default_timezone_set($prev);
                    }
                },
                static function () use ($compensator): string {
                    $prev = date_default_timezone_get();
                    date_default_timezone_set('America/New_York');
                    try {
                        return $compensator->atNative(1_793_512_200);
                    } finally {
                        date_default_timezone_set($prev);
                    }
                },
            ],
            'downtimeTimestamp/common' => [
                static fn () => $compensator->timestampPhp('2026-10-11 12:00:00'),
                static fn () => $compensator->timestampNative('2026-10-11 12:00:00'),
            ],
            'downtimeTimestamp/uncommon' => [
                static function () use ($compensator): int {
                    $prev = date_default_timezone_get();
                    date_default_timezone_set('America/New_York');
                    try {
                        return $compensator->timestampPhp('2026-11-01 01:45:00');
                    } finally {
                        date_default_timezone_set($prev);
                    }
                },
                static function () use ($compensator): int {
                    $prev = date_default_timezone_get();
                    date_default_timezone_set('America/New_York');
                    try {
                        return $compensator->timestampNative('2026-11-01 01:45:00');
                    } finally {
                        date_default_timezone_set($prev);
                    }
                },
            ],
            'downtimeLargestOffset/common' => [
                static fn () => $compensator->largestOffsetChangePhp(1_791_710_000, 1_791_716_400, 1_791_720_000),
                static fn () => $compensator->largestOffsetChangeNative(1_791_710_000, 1_791_716_400, 1_791_720_000),
            ],
            'downtimeLargestOffset/uncommon' => [
                static function () use ($compensator): int {
                    $prev = date_default_timezone_get();
                    date_default_timezone_set('America/New_York');
                    try {
                        return $compensator->largestOffsetChangePhp(1_793_505_000, 1_793_512_200, 1_793_513_400);
                    } finally {
                        date_default_timezone_set($prev);
                    }
                },
                static function () use ($compensator): int {
                    $prev = date_default_timezone_get();
                    date_default_timezone_set('America/New_York');
                    try {
                        return $compensator->largestOffsetChangeNative(1_793_505_000, 1_793_512_200, 1_793_513_400);
                    } finally {
                        date_default_timezone_set($prev);
                    }
                },
            ],
            'downtimeCrossesOffset/common' => [
                static fn () => $compensator->crossesOffsetChangePhp(1_791_710_000, 1_791_716_400, 1_791_720_000),
                static fn () => $compensator->crossesOffsetChangeNative(1_791_710_000, 1_791_716_400, 1_791_720_000),
            ],
            'downtimeCrossesOffset/uncommon' => [
                static fn () => $compensator->crossesOffsetChangePhp(1_793_505_000, 1_793_512_200, 1_793_513_400),
                static fn () => $compensator->crossesOffsetChangeNative(1_793_505_000, 1_793_512_200, 1_793_513_400),
            ],
            'downtimeTimestampNotAfter/common' => [
                static fn () => $compensator->timestampNotAfterPhp('2026-10-11 10:30:00', 1_791_720_000),
                static fn () => $compensator->timestampNotAfterNative('2026-10-11 10:30:00', 1_791_720_000),
            ],
            'downtimeTimestampNotAfter/uncommon' => [
                static function () use ($compensator): int {
                    $prev = date_default_timezone_get();
                    date_default_timezone_set('America/New_York');
                    try {
                        return $compensator->timestampNotAfterPhp('2026-11-01 01:45:00', 1_793_513_400);
                    } finally {
                        date_default_timezone_set($prev);
                    }
                },
                static function () use ($compensator): int {
                    $prev = date_default_timezone_get();
                    date_default_timezone_set('America/New_York');
                    try {
                        return $compensator->timestampNotAfterNative('2026-11-01 01:45:00', 1_793_513_400);
                    } finally {
                        date_default_timezone_set($prev);
                    }
                },
            ],
            'siteStatusMergeIntervals/common' => [
                static fn () => $statusService->mergeIntervalsPhp($intervalsCommon),
                static fn () => $statusService->mergeIntervalsNative($intervalsCommon),
            ],
            'siteStatusMergeIntervals/uncommon' => [
                static fn () => $statusService->mergeIntervalsPhp($intervalsUncommon),
                static fn () => $statusService->mergeIntervalsNative($intervalsUncommon),
            ],
            'siteStatusAggregateWorst/common' => [
                static fn () => $statusService->aggregateWorstStatusPhp($statusesCommon),
                static fn () => $statusService->aggregateWorstStatusNative($statusesCommon),
            ],
            'siteStatusAggregateWorst/uncommon' => [
                static fn () => $statusService->aggregateWorstStatusPhp($statusesUncommon),
                static fn () => $statusService->aggregateWorstStatusNative($statusesUncommon),
            ],
            'siteStatusMapImpact/common' => [
                static fn () => $statusService->mapIncidentImpactToServiceHealthPhp(IncidentImpactEnum::Major),
                static fn () => $statusService->mapIncidentImpactToServiceHealthNative(IncidentImpactEnum::Major),
            ],
            'siteStatusMapImpact/uncommon' => [
                static fn () => $statusService->mapIncidentImpactToServiceHealthPhp(IncidentImpactEnum::Critical),
                static fn () => $statusService->mapIncidentImpactToServiceHealthNative(IncidentImpactEnum::Critical),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertSame($phpCall(), $rustCall(), $name);
            for ($w = 0; $w < 512; $w++) {
                $phpCall();
                $rustCall();
            }

            $reps = [];
            for ($rep = 0; $rep < 3; $rep++) {
                $samples = [[], []];
                for ($pair = 0; $pair < 4096; $pair++) {
                    $first = $random->getInt(0, 1);
                    foreach ([$first, 1 - $first] as $side) {
                        $fn = $side === 0 ? $phpCall : $rustCall;
                        $t0 = hrtime(true);
                        $fn();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
                $phpMean = array_sum($samples[0]) / count($samples[0]);
                $rustMean = array_sum($samples[1]) / count($samples[1]);
                $phpP99 = $percentile99($samples[0]);
                $rustP99 = $percentile99($samples[1]);
                $reps[] = sprintf('rep%d=%.2fx/%.2fx', $rep + 1, $phpMean / $rustMean, $phpP99 / $rustP99);
            }
            fwrite(STDOUT, sprintf("%-34s %s\n", $name, implode('  ', $reps)));
        }
    }
}
