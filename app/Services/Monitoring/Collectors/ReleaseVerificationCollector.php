<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ReleaseVerificationCollector implements MetricsCollector
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_release_verification_collector_is_timeout_code(int64_t driver_error_code);
        int32_t nntmux_release_verification_collector_evaluate_snapshot(uint8_t has_measured_at, int64_t measured_at, int64_t now_ts, uint8_t status_is_unknown, uint8_t has_numeric_scratch, double scratch_bytes, uint8_t has_numeric_verified, double verified_bps, int32_t *out_scratch_known, double *out_scratch_bytes, int64_t *out_scratch_age_seconds, int32_t *out_verified_is_float, double *out_verified_bps);
        int32_t nntmux_release_verification_collector_backlog_age(uint8_t has_oldest, int64_t oldest_ts, int64_t now_ts, int64_t *out_age_seconds);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $int32Slots = null;

    private static ?CData $int64Slots = null;

    private static ?CData $doubleSlots = null;

    private static ?FFI $slotOwner = null;

    public function __construct(private readonly BoundedMetricQuery $queries) {}

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::setUseRustFfi(null);
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use ?? false;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function collect(): iterable
    {
        if (! Schema::hasTable('release_verification_candidates')) {
            return;
        }
        $family = MetricFamily::gauge('nntmux_release_verification_candidates', 'Candidates by verification outcome.');
        $counts = $this->measure(DB::table('release_verification_candidates')->selectRaw('status, COUNT(*) AS total')->groupBy('status'));
        foreach ($counts ?? [] as $row) {
            $family->add((int) $row->total, ['status' => $row->status]);
        }
        yield MetricFamily::gauge('nntmux_release_verification_candidates_measurement_available', 'Whether verification outcome counts were measured within their query deadline.')->add($counts === null ? 0 : 1);
        if ($counts !== null) {
            yield $family;
        }
        yield MetricFamily::gauge('nntmux_release_verification_pending_bytes', 'Compressed pending manifest bytes.')
            ->add((int) DB::table('release_verification_state')->where('id', 1)->value('pending_bytes'));
        $snapshotPath = (string) config('release_verification.storage_path').'/status.json';
        [$snapshot, $measuredAt] = $this->snapshot($snapshotPath);
        $snapshotMetrics = self::evaluateSnapshotMetrics($snapshot, $measuredAt, now()->timestamp);
        yield MetricFamily::gauge('nntmux_release_verification_scratch_bytes_measurement_available', 'Whether a cached scratch measurement is available; not a live disk-usage reading.')
            ->add($snapshotMetrics['scratch_known'] ? 1 : 0);
        if ($snapshotMetrics['scratch_known']) {
            yield MetricFamily::gauge('nntmux_release_verification_scratch_bytes', 'Last cached scratch measurement; consult its measurement age.')
                ->add($snapshotMetrics['scratch_bytes']);
            yield MetricFamily::gauge('nntmux_release_verification_scratch_bytes_measurement_age_seconds', 'Age of the cached scratch measurement.')
                ->add($snapshotMetrics['scratch_age_seconds']);
        }
        yield MetricFamily::gauge('nntmux_release_verification_verified_bytes_per_second', 'Latest bounded verifier measurement.')
            ->add($snapshotMetrics['verified_bytes_per_second']);
        $rows = $this->measure(DB::table('release_verification_candidates')->whereNull('release_id')
            ->whereIn('status', ['ready', 'waiting_upload', 'pending', 'unknown', 'header_rejected', 'verifying', 'dirty', 'unrepairable'])
            ->selectRaw('MIN(last_unique_at) AS oldest'));
        if ($rows === null) {
            // Omit an unavailable age rather than publishing a fake zero or blocking every other collector.
            yield MetricFamily::gauge('nntmux_release_verification_backlog_age_measurement_available', 'Whether the oldest unverified candidate age was measured within its query deadline.')->add(0);

            return;
        }
        $oldest = $rows[0]->oldest ?? null;
        yield MetricFamily::gauge('nntmux_release_verification_backlog_age_measurement_available', 'Whether the oldest unverified candidate age was measured within its query deadline.')->add(1);
        yield MetricFamily::gauge('nntmux_release_verification_backlog_age_seconds', 'Age of the oldest unverified candidate.')
            ->add(self::computeBacklogAgeSeconds($oldest !== null ? (string) $oldest : null, now()->timestamp));
    }

    public static function isQueryTimeoutErrorCode(int $driverErrorCode): bool
    {
        return self::$useRustFfi
            ? self::isQueryTimeoutErrorCodeNative($driverErrorCode)
            : self::isQueryTimeoutErrorCodePhp($driverErrorCode);
    }

    public static function isQueryTimeoutErrorCodePhp(int $driverErrorCode): bool
    {
        return \in_array($driverErrorCode, [1969, 3024], true);
    }

    public static function isQueryTimeoutErrorCodeNative(int $driverErrorCode): bool
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::isQueryTimeoutErrorCodePhp($driverErrorCode);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_release_verification_collector_is_timeout_code($driverErrorCode);
            if ($rc === 0 || $rc === 1) {
                return $rc === 1;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::isQueryTimeoutErrorCodePhp($driverErrorCode);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     scratch_known: bool,
     *     scratch_bytes: float,
     *     scratch_age_seconds: int,
     *     verified_bytes_per_second: float|int
     * }
     */
    public static function evaluateSnapshotMetrics(array $snapshot, ?int $measuredAt, int $nowTimestamp): array
    {
        return self::$useRustFfi
            ? self::evaluateSnapshotMetricsNative($snapshot, $measuredAt, $nowTimestamp)
            : self::evaluateSnapshotMetricsPhp($snapshot, $measuredAt, $nowTimestamp);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     scratch_known: bool,
     *     scratch_bytes: float,
     *     scratch_age_seconds: int,
     *     verified_bytes_per_second: float|int
     * }
     */
    public static function evaluateSnapshotMetricsPhp(array $snapshot, ?int $measuredAt, int $nowTimestamp): array
    {
        $scratchBytes = $snapshot['scratch_bytes'] ?? null;
        $scratchKnown = $measuredAt !== null
            && ($snapshot['scratch_bytes_status'] ?? null) !== 'unknown'
            && is_numeric($scratchBytes)
            && (float) $scratchBytes >= 0;
        $verifiedRate = $snapshot['verified_bytes_per_second'] ?? null;

        return [
            'scratch_known' => $scratchKnown,
            'scratch_bytes' => $scratchKnown ? (float) $scratchBytes : 0.0,
            'scratch_age_seconds' => $scratchKnown ? max(0, $nowTimestamp - $measuredAt) : 0,
            'verified_bytes_per_second' => is_numeric($verifiedRate) ? (float) $verifiedRate : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *     scratch_known: bool,
     *     scratch_bytes: float,
     *     scratch_age_seconds: int,
     *     verified_bytes_per_second: float|int
     * }
     */
    public static function evaluateSnapshotMetricsNative(array $snapshot, ?int $measuredAt, int $nowTimestamp): array
    {
        $ffi = self::categorizeFfi();
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$int32Slots === null
            || self::$int64Slots === null
            || self::$doubleSlots === null
        ) {
            return self::evaluateSnapshotMetricsPhp($snapshot, $measuredAt, $nowTimestamp);
        }

        $scratchRaw = $snapshot['scratch_bytes'] ?? null;
        $hasNumericScratch = is_numeric($scratchRaw);
        $verifiedRaw = $snapshot['verified_bytes_per_second'] ?? null;
        $hasNumericVerified = is_numeric($verifiedRaw);

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_release_verification_collector_evaluate_snapshot(
                $measuredAt !== null ? 1 : 0,
                $measuredAt ?? 0,
                $nowTimestamp,
                ($snapshot['scratch_bytes_status'] ?? null) === 'unknown' ? 1 : 0,
                $hasNumericScratch ? 1 : 0,
                $hasNumericScratch ? (float) $scratchRaw : 0.0,
                $hasNumericVerified ? 1 : 0,
                $hasNumericVerified ? (float) $verifiedRaw : 0.0,
                FFI::addr(self::$int32Slots[0]),
                FFI::addr(self::$doubleSlots[0]),
                FFI::addr(self::$int64Slots[0]),
                FFI::addr(self::$int32Slots[1]),
                FFI::addr(self::$doubleSlots[1]),
            );
            if ($rc === 1) {
                /** @var int $scratchKnown */
                $scratchKnown = self::$int32Slots[0];
                /** @var float $scratchBytes */
                $scratchBytes = self::$doubleSlots[0];
                /** @var int $scratchAge */
                $scratchAge = self::$int64Slots[0];
                /** @var int $verifiedIsFloat */
                $verifiedIsFloat = self::$int32Slots[1];
                /** @var float $verifiedBps */
                $verifiedBps = self::$doubleSlots[1];

                return [
                    'scratch_known' => $scratchKnown === 1,
                    'scratch_bytes' => $scratchBytes,
                    'scratch_age_seconds' => $scratchAge,
                    'verified_bytes_per_second' => $verifiedIsFloat === 1 ? $verifiedBps : 0,
                ];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::evaluateSnapshotMetricsPhp($snapshot, $measuredAt, $nowTimestamp);
    }

    public static function computeBacklogAgeSeconds(?string $oldest, int $nowTimestamp): int
    {
        return self::$useRustFfi
            ? self::computeBacklogAgeSecondsNative($oldest, $nowTimestamp)
            : self::computeBacklogAgeSecondsPhp($oldest, $nowTimestamp);
    }

    public static function computeBacklogAgeSecondsPhp(?string $oldest, int $nowTimestamp): int
    {
        return $oldest === null ? 0 : max(0, $nowTimestamp - strtotime($oldest));
    }

    public static function computeBacklogAgeSecondsNative(?string $oldest, int $nowTimestamp): int
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null || ! self::ensureSlots($ffi) || self::$int64Slots === null) {
            return self::computeBacklogAgeSecondsPhp($oldest, $nowTimestamp);
        }

        $parsedOldest = 0;
        if ($oldest !== null) {
            $ts = strtotime($oldest);
            if ($ts === false) {
                return self::computeBacklogAgeSecondsPhp($oldest, $nowTimestamp);
            }
            $parsedOldest = $ts;
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_release_verification_collector_backlog_age(
                $oldest === null ? 0 : 1,
                $parsedOldest,
                $nowTimestamp,
                FFI::addr(self::$int64Slots[0]),
            );
            if ($rc === 1) {
                /** @var int $age */
                $age = self::$int64Slots[0];

                return $age;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::computeBacklogAgeSecondsPhp($oldest, $nowTimestamp);
    }

    /** @return list<object>|null Null means the database cancelled an over-budget measurement. */
    private function measure(Builder $query): ?array
    {
        try {
            return $this->queries->get($query);
        } catch (QueryException $exception) {
            if (! self::isQueryTimeoutErrorCode((int) ($exception->errorInfo[1] ?? 0))) {
                throw $exception;
            }

            return null;
        }
    }

    /** @return array{array<string, mixed>, int|null} */
    private function snapshot(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [[], null];
        }
        try {
            $body = stream_get_contents($handle, 16385);
            $stat = fstat($handle);
            if ($body === false || strlen($body) > 16384 || $stat === false) {
                return [[], null];
            }
            $snapshot = json_decode($body, true);

            return [is_array($snapshot) ? $snapshot : [], (int) $stat['mtime']];
        } finally {
            fclose($handle);
        }
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (
            self::$slotOwner !== $ffi
            || self::$int32Slots === null
            || self::$int64Slots === null
            || self::$doubleSlots === null
        ) {
            try {
                self::$int32Slots = $ffi->new('int32_t[2]');
                self::$int64Slots = $ffi->new('int64_t[1]');
                self::$doubleSlots = $ffi->new('double[2]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
