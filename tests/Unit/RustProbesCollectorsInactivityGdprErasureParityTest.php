<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ServiceStatusEnum;
use App\Services\Gdpr\GdprErasureService;
use App\Services\Monitoring\Collectors\ReleaseVerificationCollector;
use App\Services\Monitoring\Collectors\ServiceStatusCollector;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\StatusProbes\DatabaseProbe;
use App\Services\StatusProbes\ProbeResult;
use App\Services\UserInactivityEvaluator;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use ValueError;

/**
 * Parity, C-ABI safety contract, fallback, and paired benchmark verification for Wave 22 Slice 22.6:
 * DatabaseProbe, ReleaseVerificationCollector, ServiceStatusCollector, UserInactivityEvaluator,
 * and GdprErasureService (`rust/pipeline-ffi/src/categorize.rs`).
 *
 * Benchmarks run with NNTMUX_PROBES_COLLECTORS_INACTIVITY_GDPR_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_PROBES_COLLECTORS_INACTIVITY_GDPR_BENCH=1 php artisan test --compact tests/Unit/RustProbesCollectorsInactivityGdprErasureParityTest.php --filter=test_paired_randomized_benchmarks
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * | Method                                                 | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |--------------------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | DatabaseProbe::evaluateConnectedResult                 | 0.34x / 0.37x       | 0.35x / 0.37x         | No      | PHP (false)  |
 * | DatabaseProbe::formatUnreachableResult                 | 0.54x / 0.52x       | 1.68x / 1.28x         | No      | PHP (false)  |
 * | ReleaseVerificationCollector::isQueryTimeoutErrorCode  | 0.27x / 0.28x       | 0.29x / 0.26x         | No      | PHP (false)  |
 * | ReleaseVerificationCollector::evaluateSnapshotMetrics  | 0.19x / 0.16x       | 0.17x / 0.21x         | No      | PHP (false)  |
 * | ReleaseVerificationCollector::computeBacklogAgeSeconds | 0.56x / 0.47x       | 0.14x / 0.13x         | No      | PHP (false)  |
 * | ServiceStatusCollector::evaluateServiceMetrics         | 0.21x / 0.21x       | 0.23x / 0.15x         | No      | PHP (false)  |
 * | UserInactivityEvaluator::isActivityStale               | 0.21x / 0.22x       | 0.19x / 0.15x         | No      | PHP (false)  |
 * | UserInactivityEvaluator::isDownloadActivityStale       | 0.18x / 0.19x       | 0.18x / 0.18x         | No      | PHP (false)  |
 * | UserInactivityEvaluator::shouldPurge                   | 0.42x / 0.40x       | 0.34x / 0.32x         | No      | PHP (false)  |
 * | GdprErasureService::anonymousIdentity                  | 0.31x / 0.24x       | 0.35x / 0.37x         | No      | PHP (false)  |
 * | GdprErasureService::resolveErasureEventName            | 0.14x / 0.15x       | 0.14x / 0.16x         | No      | PHP (false)  |
 * | GdprErasureService::formatAuditSubjectMetadataJson     | 0.35x / 0.39x       | 0.35x / 0.28x         | No      | PHP (false)  |
 * | GdprErasureService::sanitizeMetadata                   | 0.15x / 0.11x       | 0.43x / 0.40x         | No      | PHP (false)  |
 *
 * Single-call PHP FFI marshaling overhead exceeds the sub-microsecond native PHP execution cost
 * across these pure-CPU helpers. Per `.ai/rules/rust-performance.md`, all five classes retain PHP
 * (`$useRustFfi = false`) as the production default.
 */
class RustProbesCollectorsInactivityGdprErasureParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        DatabaseProbe::class,
        ReleaseVerificationCollector::class,
        ServiceStatusCollector::class,
        UserInactivityEvaluator::class,
        GdprErasureService::class,
    ];

    private const string FULL_CDEF = DatabaseProbe::FFI_CDEF."\n"
        .ReleaseVerificationCollector::FFI_CDEF."\n"
        .ServiceStatusCollector::FFI_CDEF."\n"
        .UserInactivityEvaluator::FFI_CDEF."\n"
        .GdprErasureService::FFI_CDEF;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('categorize', self::FULL_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::enableRustFfi();
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. DatabaseProbe
    // ---------------------------------------------------------------------

    public function test_database_probe_connected_and_unreachable_parity(): void
    {
        $connectedCases = [
            [1_000_000_000, 1_000_000_000],
            [1_000_000_000, 1_000_999_999],
            [1_000_000_000, 1_001_000_000],
            [1_000_000_000, 1_014_750_000],
            [2_500_000_000, 2_498_500_000],
            [0, 2_000_000_000_000_000],
        ];

        foreach ($connectedCases as $idx => [$startNs, $endNs]) {
            $expected = DatabaseProbe::evaluateConnectedResultPhp($startNs, $endNs);
            $actual = DatabaseProbe::evaluateConnectedResultNative($startNs, $endNs);
            self::assertProbeResultSame($expected, $actual, "db connected case {$idx}");

            foreach ([false, true] as $toggle) {
                DatabaseProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, DatabaseProbe::evaluateConnectedResult($startNs, $endNs));
            }
        }

        $errorCases = [
            '',
            'SQLSTATE[HY000] [2002] Connection refused',
            str_repeat('a', 119),
            str_repeat('b', 120),
            str_repeat('c', 121),
            str_repeat('d', 117).'   extra text after 120 boundary',
            str_repeat('e', 115)."\t\n\r\0\x0B trailing whitespace before cut",
            str_repeat('Database connection timeout ', 10),
            'UTF-8 error detail: '.str_repeat('é', 130),
        ];

        foreach ($errorCases as $idx => $msg) {
            $expected = DatabaseProbe::formatUnreachableResultPhp($msg);
            $actual = DatabaseProbe::formatUnreachableResultNative($msg);
            self::assertProbeResultSame($expected, $actual, "db unreachable case {$idx}");

            foreach ([false, true] as $toggle) {
                DatabaseProbe::setUseRustFfi($toggle);
                self::assertProbeResultSame($expected, DatabaseProbe::formatUnreachableResult($msg));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. ReleaseVerificationCollector
    // ---------------------------------------------------------------------

    public function test_release_verification_collector_timeout_snapshot_and_backlog_age_parity(): void
    {
        foreach ([0, 1045, 1205, 1968, 1969, 1970, 3023, 3024, 3025, -1969] as $code) {
            $expected = ReleaseVerificationCollector::isQueryTimeoutErrorCodePhp($code);
            $actual = ReleaseVerificationCollector::isQueryTimeoutErrorCodeNative($code);
            $this->assertSame($expected, $actual, "timeout code {$code}");

            foreach ([false, true] as $toggle) {
                ReleaseVerificationCollector::setUseRustFfi($toggle);
                $this->assertSame($expected, ReleaseVerificationCollector::isQueryTimeoutErrorCode($code));
            }
        }

        $snapshotCases = [
            [[], null, 1_700_000_600],
            [['scratch_bytes' => 1234, 'verified_bytes_per_second' => 5678], 1_700_000_000, 1_700_000_600],
            [['scratch_bytes' => '1234.5', 'scratch_bytes_status' => 'ok', 'verified_bytes_per_second' => '5678.25'], 1_700_000_000, 1_700_000_600],
            [['scratch_bytes' => 1234, 'scratch_bytes_status' => 'unknown', 'verified_bytes_per_second' => 5678], 1_700_000_000, 1_700_000_600],
            [['scratch_bytes' => null, 'scratch_bytes_status' => 'unknown', 'verified_bytes_per_second' => 0], 1_700_000_000, 1_700_000_600],
            [['scratch_bytes' => -1, 'scratch_bytes_status' => 'ok', 'verified_bytes_per_second' => null], 1_700_000_000, 1_700_000_600],
            [['scratch_bytes' => 0, 'scratch_bytes_status' => 'ok', 'verified_bytes_per_second' => 'not-numeric'], 1_700_000_900, 1_700_000_600],
            [['scratch_bytes' => 512, 'verified_bytes_per_second' => 1024], null, 1_700_000_600],
            [['scratch_bytes' => INF, 'verified_bytes_per_second' => 100], 1_700_000_000, 1_700_000_600],
        ];

        foreach ($snapshotCases as $idx => [$snapshot, $measuredAt, $nowTs]) {
            $expected = ReleaseVerificationCollector::evaluateSnapshotMetricsPhp($snapshot, $measuredAt, $nowTs);
            $actual = ReleaseVerificationCollector::evaluateSnapshotMetricsNative($snapshot, $measuredAt, $nowTs);
            $this->assertSame($expected, $actual, "snapshot case {$idx}");

            foreach ([false, true] as $toggle) {
                ReleaseVerificationCollector::setUseRustFfi($toggle);
                $this->assertSame(
                    $expected,
                    ReleaseVerificationCollector::evaluateSnapshotMetrics($snapshot, $measuredAt, $nowTs)
                );
            }
        }

        $backlogCases = [
            [null, 1_700_007_200],
            ['2023-11-14 22:13:20 UTC', 1_700_007_200],
            ['2023-11-15 00:13:20 UTC', 1_700_007_200],
            ['2023-11-15 02:13:20 UTC', 1_700_007_200],
        ];

        foreach ($backlogCases as $idx => [$oldest, $nowTs]) {
            $expected = ReleaseVerificationCollector::computeBacklogAgeSecondsPhp($oldest, $nowTs);
            $actual = ReleaseVerificationCollector::computeBacklogAgeSecondsNative($oldest, $nowTs);
            $this->assertSame($expected, $actual, "backlog age case {$idx}");

            foreach ([false, true] as $toggle) {
                ReleaseVerificationCollector::setUseRustFfi($toggle);
                $this->assertSame(
                    $expected,
                    ReleaseVerificationCollector::computeBacklogAgeSeconds($oldest, $nowTs)
                );
            }
        }
    }

    // ---------------------------------------------------------------------
    // 3. ServiceStatusCollector
    // ---------------------------------------------------------------------

    public function test_service_status_collector_evaluation_and_metric_families_parity(): void
    {
        $serviceCases = [
            [ServiceStatusEnum::Operational, 15],
            [ServiceStatusEnum::Operational, 2000],
            [ServiceStatusEnum::Operational, 0],
            [ServiceStatusEnum::Operational, null],
            [ServiceStatusEnum::Degraded, 250],
            [ServiceStatusEnum::Maintenance, 1000],
            [ServiceStatusEnum::PartialOutage, 450],
            [ServiceStatusEnum::MajorOutage, null],
            ['operational', 120],
            ['degraded', 1000],
            ['maintenance', null],
            ['partial_outage', 3500],
            ['major_outage', 50],
            ['operational', PHP_INT_MIN],
        ];

        foreach ($serviceCases as $idx => [$status, $responseTimeMs]) {
            $expected = ServiceStatusCollector::evaluateServiceMetricsPhp($status, $responseTimeMs);
            $actual = ServiceStatusCollector::evaluateServiceMetricsNative($status, $responseTimeMs);
            $this->assertSame($expected, $actual, "service metrics case {$idx}");

            foreach ([false, true] as $toggle) {
                ServiceStatusCollector::setUseRustFfi($toggle);
                $this->assertSame(
                    $expected,
                    ServiceStatusCollector::evaluateServiceMetrics($status, $responseTimeMs)
                );
            }
        }

        $phpThrew = false;
        try {
            ServiceStatusCollector::evaluateServiceMetricsPhp('not_a_valid_status', 10);
        } catch (ValueError) {
            $phpThrew = true;
        }
        $this->assertTrue($phpThrew);

        $nativeThrew = false;
        try {
            ServiceStatusCollector::evaluateServiceMetricsNative('not_a_valid_status', 10);
        } catch (ValueError) {
            $nativeThrew = true;
        }
        $this->assertTrue($nativeThrew);

        $rows = [
            (object) ['slug' => 'database', 'status' => ServiceStatusEnum::Operational, 'response_time_ms' => 4],
            (object) ['slug' => 'redis', 'status' => ServiceStatusEnum::Degraded, 'response_time_ms' => 1000],
            (object) ['slug' => 'nntp', 'status' => ServiceStatusEnum::MajorOutage, 'response_time_ms' => null],
            (object) ['slug' => 'search', 'status' => ServiceStatusEnum::PartialOutage, 'response_time_ms' => 275],
            (object) ['slug' => 'disk', 'status' => ServiceStatusEnum::Maintenance, 'response_time_ms' => 0],
        ];

        $collector = new ServiceStatusCollector;
        $phpFamilies = $collector->buildMetricFamiliesPhp($rows);
        $rustFamilies = $collector->buildMetricFamiliesNative($rows);
        self::assertMetricFamiliesSame($phpFamilies, $rustFamilies, 'service status families');

        foreach ([false, true] as $toggle) {
            ServiceStatusCollector::setUseRustFfi($toggle);
            self::assertMetricFamiliesSame($phpFamilies, $collector->buildMetricFamilies($rows), "toggle {$toggle}");
        }
    }

    // ---------------------------------------------------------------------
    // 4. UserInactivityEvaluator
    // ---------------------------------------------------------------------

    public function test_user_inactivity_evaluator_parity(): void
    {
        $evaluator = new UserInactivityEvaluator;
        $now = Carbon::parse('2026-03-11 12:00:00.500000 UTC');
        $threshold = $now->copy()->subDays(180);
        $stale = $threshold->copy()->subMicrosecond();
        $exact = $threshold->copy();
        $recent = $threshold->copy()->addMicrosecond();
        $nySameAsThreshold = $threshold->copy()->setTimezone('America/New_York');
        $pre1970Threshold = Carbon::parse('1969-12-31 23:59:59.200000 UTC');
        $pre1970Stale = Carbon::parse('1969-12-31 23:59:59.100000 UTC');

        $activityCases = [
            [null, null, $threshold],
            [null, $stale, $threshold],
            [null, $exact, $threshold],
            [null, $recent, $threshold],
            [$stale, $recent, $threshold],
            [$recent, $stale, $threshold],
            [$nySameAsThreshold, $stale, $threshold],
            [$pre1970Stale, null, $pre1970Threshold],
        ];

        foreach ($activityCases as $idx => [$activityAt, $fallbackAt, $thresh]) {
            $expected = $evaluator->isActivityStalePhp($activityAt, $fallbackAt, $thresh);
            $actual = $evaluator->isActivityStaleNative($activityAt, $fallbackAt, $thresh);
            $this->assertSame($expected, $actual, "activity stale case {$idx}");

            foreach ([false, true] as $toggle) {
                UserInactivityEvaluator::setUseRustFfi($toggle);
                $this->assertSame($expected, $evaluator->isActivityStale($activityAt, $fallbackAt, $thresh));
            }
        }

        $purgeCases = [
            [$stale, $stale, null, null, null, 0, $threshold],
            [$stale, $recent, $stale, $stale, $recent, 12, $threshold],
            [$stale, $recent, $stale, $stale, null, 15, $threshold],
            [$stale, $stale, $stale, $stale, null, 15, $threshold],
            [$stale, null, $stale, $stale, null, 5, $threshold],
            [$stale, $stale, $recent, $stale, $stale, 0, $threshold],
            [$stale, $stale, $stale, $recent, $stale, 0, $threshold],
            [$stale, $stale, $exact, $stale, $stale, 0, $threshold],
            [null, null, null, null, null, 0, $threshold],
            [$pre1970Stale, $pre1970Stale, null, null, null, 0, $pre1970Threshold],
        ];

        foreach ($purgeCases as $idx => [$createdAt, $updatedAt, $loginAt, $apiAt, $downloadAt, $grabs, $thresh]) {
            $expected = $evaluator->shouldPurgePhp($createdAt, $updatedAt, $loginAt, $apiAt, $downloadAt, $grabs, $thresh);
            $actual = $evaluator->shouldPurgeNative($createdAt, $updatedAt, $loginAt, $apiAt, $downloadAt, $grabs, $thresh);
            $this->assertSame($expected, $actual, "shouldPurge case {$idx}");

            $expectedDl = $evaluator->isDownloadActivityStalePhp($createdAt, $updatedAt, $downloadAt, $grabs, $thresh);
            $actualDl = $evaluator->isDownloadActivityStaleNative($createdAt, $updatedAt, $downloadAt, $grabs, $thresh);
            $this->assertSame($expectedDl, $actualDl, "downloadStale case {$idx}");

            foreach ([false, true] as $toggle) {
                UserInactivityEvaluator::setUseRustFfi($toggle);
                $this->assertSame(
                    $expected,
                    $evaluator->shouldPurge($createdAt, $updatedAt, $loginAt, $apiAt, $downloadAt, $grabs, $thresh)
                );
                $this->assertSame(
                    $expectedDl,
                    $evaluator->isDownloadActivityStale($createdAt, $updatedAt, $downloadAt, $grabs, $thresh)
                );
            }
        }
    }

    // ---------------------------------------------------------------------
    // 5. GdprErasureService
    // ---------------------------------------------------------------------

    public function test_gdpr_erasure_service_helpers_parity(): void
    {
        foreach ([0, 1, 42, 999_999, -7, PHP_INT_MAX] as $userId) {
            $expected = GdprErasureService::anonymousIdentityPhp($userId);
            $actual = GdprErasureService::anonymousIdentityNative($userId);
            $this->assertSame($expected, $actual, "anonymousIdentity {$userId}");

            foreach ([false, true] as $toggle) {
                GdprErasureService::setUseRustFfi($toggle);
                $this->assertSame($expected, GdprErasureService::anonymousIdentity($userId));
            }
        }

        foreach ([false, true] as $forceDelete) {
            $expected = GdprErasureService::resolveErasureEventNamePhp($forceDelete);
            $actual = GdprErasureService::resolveErasureEventNameNative($forceDelete);
            $this->assertSame($expected, $actual);

            foreach ([false, true] as $toggle) {
                GdprErasureService::setUseRustFfi($toggle);
                $this->assertSame($expected, GdprErasureService::resolveErasureEventName($forceDelete));
            }
        }

        $usernameCases = [
            'deleted_user_42',
            '',
            'user/with/slashes"and\\backslash',
            "control\x00\x01\x08\x09\x0A\x0B\x0C\x0D\x1F\x7Fchars",
            'non_ascii_user_é',
            str_repeat('long_username_', 20),
        ];

        foreach ($usernameCases as $idx => $username) {
            $expected = GdprErasureService::formatAuditSubjectMetadataJsonPhp($username);
            $actual = GdprErasureService::formatAuditSubjectMetadataJsonNative($username);
            $this->assertSame($expected, $actual, "audit subject json case {$idx}");

            foreach ([false, true] as $toggle) {
                GdprErasureService::setUseRustFfi($toggle);
                $this->assertSame($expected, GdprErasureService::formatAuditSubjectMetadataJson($username));
            }
        }

        $metadataCases = [
            null,
            '',
            'invalid-json',
            '123',
            '{"email":"user@example.test","ip_address":"127.0.0.1","host":"localhost","role":"vip","count":3}',
            '{"gdpr_minimized":false,"email":"a@b.c","browser":"Firefox"}',
            ['email' => 'a@b.c', 'ip_address' => '10.0.0.1', 'host' => 'h', 'retained' => ['nested' => true]],
            ['gdpr_minimized' => false, 'first' => 1, 'email' => 'drop', 'second' => 2],
            [0 => 'zero', 'email' => 'drop', 2 => 'two'],
            ["bad\0key" => 'val', 'email' => 'drop'],
            42,
        ];

        foreach ($metadataCases as $idx => $meta) {
            $expected = GdprErasureService::sanitizeMetadataPhp($meta);
            $actual = GdprErasureService::sanitizeMetadataNative($meta);
            $this->assertSame($expected, $actual, "sanitizeMetadata case {$idx}");
            $this->assertSame(array_keys($expected), array_keys($actual), "sanitizeMetadata key order {$idx}");

            foreach ([false, true] as $toggle) {
                GdprErasureService::setUseRustFfi($toggle);
                $this->assertSame($expected, GdprErasureService::sanitizeMetadata($meta));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 6. C-ABI Safety & Fallback Contracts
    // ---------------------------------------------------------------------

    public function test_c_abi_safety_contract_for_all_13_entrypoints(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FULL_CDEF);
        $this->assertNotNull($ffi);

        $buf = $ffi->new('char[256]');
        $small = $ffi->new('char[4]');
        $u8Buf = $ffi->new('uint8_t[16]');
        $u8Small = $ffi->new('uint8_t[2]');
        $int32Slot = $ffi->new('int32_t[1]');
        $int64Slot = $ffi->new('int64_t[1]');
        $doubleSlot = $ffi->new('double[1]');

        $raw = $ffi->new('uint8_t[64]');
        $unalignedInt32 = $ffi->cast('int32_t*', FFI::addr($raw[1]));
        $unalignedInt64 = $ffi->cast('int64_t*', FFI::addr($raw[1]));
        $unalignedDouble = $ffi->cast('double*', FFI::addr($raw[1]));

        // 1. nntmux_database_probe_evaluate_connected
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_database_probe_evaluate_connected', 0, 1000, $unalignedInt64, $buf, 256));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_database_probe_evaluate_connected', 0, 1000, $int64Slot, null, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_database_probe_evaluate_connected', 0, 1000, $int64Slot, $small, 4));

        // 2. nntmux_database_probe_format_unreachable
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_database_probe_format_unreachable', null, 4, $buf, 256));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_database_probe_format_unreachable', 'é', 2, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_database_probe_format_unreachable', 'timeout', 7, $small, 4));

        // 3. nntmux_release_verification_collector_is_timeout_code
        $this->assertSame(1, self::ffiCall($ffi, 'nntmux_release_verification_collector_is_timeout_code', 1969));
        $this->assertSame(0, self::ffiCall($ffi, 'nntmux_release_verification_collector_is_timeout_code', 100));

        // 4. nntmux_release_verification_collector_evaluate_snapshot
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_release_verification_collector_evaluate_snapshot', 2, 0, 0, 0, 0, 0.0, 0, 0.0, $int32Slot, $doubleSlot, $int64Slot, $int32Slot, $doubleSlot));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_release_verification_collector_evaluate_snapshot', 1, 0, 0, 0, 1, 1.0, 1, 1.0, $unalignedInt32, $doubleSlot, $int64Slot, $int32Slot, $doubleSlot));

        // 5. nntmux_release_verification_collector_backlog_age
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_release_verification_collector_backlog_age', 2, 0, 0, $int64Slot));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_release_verification_collector_backlog_age', 1, 0, 0, $unalignedInt64));

        // 6. nntmux_service_status_collector_evaluate
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_service_status_collector_evaluate', null, 5, 0, 0, $int32Slot, $int32Slot, $int32Slot, $int64Slot, $doubleSlot));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_service_status_collector_evaluate', 'invalid', 7, 0, 0, $int32Slot, $int32Slot, $int32Slot, $int64Slot, $doubleSlot));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_service_status_collector_evaluate', 'operational', 11, 1, 100, $int32Slot, $int32Slot, $int32Slot, $int64Slot, $unalignedDouble));

        // 7-9. UserInactivityEvaluator symbols
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_inactivity_is_activity_stale', 2, 0, 0, 0, 0));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_inactivity_is_download_stale', 0, 0, 2, 0, 0, 0, 0, 0));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_user_inactivity_should_purge', 0, 0, 0, 0, 0, 0, 2, 0, 0, 0, 0, 0));

        // 10-13. GdprErasureService symbols
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_gdpr_erasure_anonymous_identity_nul', 1, null, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_gdpr_erasure_anonymous_identity_nul', 1, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_gdpr_erasure_event_name', 2, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_gdpr_erasure_event_name', 1, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_gdpr_erasure_audit_subject_json', null, 5, $buf, 256));
        $this->assertSame(-2, self::ffiCall($ffi, 'nntmux_gdpr_erasure_audit_subject_json', 'é', 2, $buf, 256));
        $this->assertLessThan(-4, self::ffiCall($ffi, 'nntmux_gdpr_erasure_audit_subject_json', 'deleted_user_1', 14, $small, 4));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_gdpr_erasure_filter_metadata_keys_nul', null, 3, 1, $u8Buf, 16, $int32Slot));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_gdpr_erasure_filter_metadata_keys_nul', 'a', 1, 1, $u8Buf, 16, $unalignedInt32));
        $this->assertLessThan(-1, self::ffiCall($ffi, 'nntmux_gdpr_erasure_filter_metadata_keys_nul', "a\0b\0c\0d\0e", 9, 5, $u8Small, 2, $int32Slot));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertTrue(DatabaseProbe::evaluateConnectedResultNative(1_000_000_000, 1_003_000_000)->ok);
        $this->assertFalse(DatabaseProbe::formatUnreachableResultNative('Connection refused')->ok);

        $this->assertTrue(ReleaseVerificationCollector::isQueryTimeoutErrorCodeNative(1969));
        $this->assertTrue(ReleaseVerificationCollector::evaluateSnapshotMetricsNative(['scratch_bytes' => 100], 1000, 1060)['scratch_known']);
        $this->assertSame(60, ReleaseVerificationCollector::computeBacklogAgeSecondsNative('2023-11-14 22:13:20 UTC', 1_700_000_060));

        $this->assertSame(1, ServiceStatusCollector::evaluateServiceMetricsNative(ServiceStatusEnum::Operational, 25)['up']);

        $evaluator = new UserInactivityEvaluator;
        $threshold = Carbon::parse('2026-03-11 12:00:00 UTC');
        $stale = $threshold->copy()->subDays(10);
        $this->assertTrue($evaluator->shouldPurgeNative($stale, $stale, null, null, null, 0, $threshold));
        $this->assertTrue($evaluator->isActivityStaleNative(null, $stale, $threshold));
        $this->assertTrue($evaluator->isDownloadActivityStaleNative($stale, $stale, null, 0, $threshold));

        $this->assertSame('deleted_user_9', GdprErasureService::anonymousIdentityNative(9)['username']);
        $this->assertSame('erasure_force_delete', GdprErasureService::resolveErasureEventNameNative(true));
        $this->assertSame('{"subject":"deleted_user_9"}', GdprErasureService::formatAuditSubjectMetadataJsonNative('deleted_user_9'));
        $this->assertSame(['role' => 'user', 'gdpr_minimized' => true], GdprErasureService::sanitizeMetadataNative(['email' => 'x', 'role' => 'user']));
    }

    // ---------------------------------------------------------------------
    // 7. Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_PROBES_COLLECTORS_INACTIVITY_GDPR_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_PROBES_COLLECTORS_INACTIVITY_GDPR_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $longErr = str_repeat('SQLSTATE[HY000] [2002] Connection timed out while reading greeting packet ', 3);
        $evaluator = new UserInactivityEvaluator;
        $threshold = Carbon::parse('2026-03-11 12:00:00 UTC');
        $stale = $threshold->copy()->subDays(181);
        $recent = $threshold->copy()->addHours(6);

        $workloads = [
            'DatabaseProbe::evaluateConnectedResult' => [
                DatabaseProbe::class,
                static fn () => DatabaseProbe::evaluateConnectedResult(1_000_000_000, 1_002_500_000)->responseTimeMs,
                static fn () => DatabaseProbe::evaluateConnectedResult(1_000_000_000, 1_245_800_000)->responseTimeMs,
            ],
            'DatabaseProbe::formatUnreachableResult' => [
                DatabaseProbe::class,
                static fn () => DatabaseProbe::formatUnreachableResult('SQLSTATE[HY000] [2002] Connection refused')->reason,
                static fn () => DatabaseProbe::formatUnreachableResult($longErr)->reason,
            ],
            'ReleaseVerificationCollector::isQueryTimeoutErrorCode' => [
                ReleaseVerificationCollector::class,
                static fn () => ReleaseVerificationCollector::isQueryTimeoutErrorCode(1969),
                static fn () => ReleaseVerificationCollector::isQueryTimeoutErrorCode(1045),
            ],
            'ReleaseVerificationCollector::evaluateSnapshotMetrics' => [
                ReleaseVerificationCollector::class,
                static fn () => ReleaseVerificationCollector::evaluateSnapshotMetrics(['scratch_bytes' => 123456, 'scratch_bytes_status' => 'ok', 'verified_bytes_per_second' => 98765], 1_700_000_000, 1_700_000_120),
                static fn () => ReleaseVerificationCollector::evaluateSnapshotMetrics(['scratch_bytes' => null, 'scratch_bytes_status' => 'unknown', 'verified_bytes_per_second' => 0], 1_700_000_000, 1_700_000_900),
            ],
            'ReleaseVerificationCollector::computeBacklogAgeSeconds' => [
                ReleaseVerificationCollector::class,
                static fn () => ReleaseVerificationCollector::computeBacklogAgeSeconds('2023-11-14 22:13:20 UTC', 1_700_007_200),
                static fn () => ReleaseVerificationCollector::computeBacklogAgeSeconds(null, 1_700_007_200),
            ],
            'ServiceStatusCollector::evaluateServiceMetrics' => [
                ServiceStatusCollector::class,
                static fn () => ServiceStatusCollector::evaluateServiceMetrics(ServiceStatusEnum::Operational, 15),
                static fn () => ServiceStatusCollector::evaluateServiceMetrics(ServiceStatusEnum::MajorOutage, null),
            ],
            'UserInactivityEvaluator::isActivityStale' => [
                UserInactivityEvaluator::class,
                static fn () => $evaluator->isActivityStale(null, $stale, $threshold),
                static fn () => $evaluator->isActivityStale($recent, $stale, $threshold),
            ],
            'UserInactivityEvaluator::isDownloadActivityStale' => [
                UserInactivityEvaluator::class,
                static fn () => $evaluator->isDownloadActivityStale($stale, $stale, null, 0, $threshold),
                static fn () => $evaluator->isDownloadActivityStale($stale, $recent, null, 15, $threshold),
            ],
            'UserInactivityEvaluator::shouldPurge' => [
                UserInactivityEvaluator::class,
                static fn () => $evaluator->shouldPurge($stale, $stale, null, null, null, 0, $threshold),
                static fn () => $evaluator->shouldPurge($stale, $recent, $stale, $stale, null, 15, $threshold),
            ],
            'GdprErasureService::anonymousIdentity' => [
                GdprErasureService::class,
                static fn () => GdprErasureService::anonymousIdentity(42),
                static fn () => GdprErasureService::anonymousIdentity(9_876_543),
            ],
            'GdprErasureService::resolveErasureEventName' => [
                GdprErasureService::class,
                static fn () => GdprErasureService::resolveErasureEventName(false),
                static fn () => GdprErasureService::resolveErasureEventName(true),
            ],
            'GdprErasureService::formatAuditSubjectMetadataJson' => [
                GdprErasureService::class,
                static fn () => GdprErasureService::formatAuditSubjectMetadataJson('deleted_user_42'),
                static fn () => GdprErasureService::formatAuditSubjectMetadataJson('deleted_user_9876543_with_"quote"'),
            ],
            'GdprErasureService::sanitizeMetadata' => [
                GdprErasureService::class,
                static fn () => GdprErasureService::sanitizeMetadata(['email' => 'u@example.test', 'ip_address' => '127.0.0.1', 'role' => 'user']),
                static fn () => GdprErasureService::sanitizeMetadata('{"email":"u@example.test","ip_address":"127.0.0.1","host":"localhost","browser":"Firefox","action":"login"}'),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::enableRustFfi();
                    $rustOut = self::invoke($fn);
                    $class::disableRustFfi();
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2206 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::resetRustFfi();
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-54s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    /**
     * @param  list<MetricFamily>  $expected
     * @param  list<MetricFamily>  $actual
     */
    private static function assertMetricFamiliesSame(array $expected, array $actual, string $message = ''): void
    {
        self::assertCount(\count($expected), $actual, $message.' (count)');
        foreach ($expected as $idx => $family) {
            self::assertSame($family->name, $actual[$idx]->name, "{$message} [{$idx}] name");
            self::assertSame($family->help, $actual[$idx]->help, "{$message} [{$idx}] help");
            self::assertSame($family->type, $actual[$idx]->type, "{$message} [{$idx}] type");
            self::assertSame($family->samples(), $actual[$idx]->samples(), "{$message} [{$idx}] samples");
        }
    }

    private static function assertProbeResultSame(ProbeResult $expected, ProbeResult $actual, string $message = ''): void
    {
        self::assertSame($expected->ok, $actual->ok, $message.' (ok)');
        self::assertSame($expected->responseTimeMs, $actual->responseTimeMs, $message.' (responseTimeMs)');
        self::assertSame($expected->impact, $actual->impact, $message.' (impact)');
        self::assertSame($expected->reason, $actual->reason, $message.' (reason)');
        self::assertSame($expected->metadata, $actual->metadata, $message.' (metadata)');
    }

    private static function ffiCall(FFI $ffi, string $symbol, CData|int|float|string|null ...$args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $toggle) {
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [true => [], false => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[$toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[true];
        $php = $times[false];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
