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

    /**
     * Enabled: the paired benchmark in the W57 parity test measures 8.649x common mean, 8.797x
     * common p99, 7.915x uncommon mean and 5.502x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = 'ssize_t nntmux_w571_verification_latency_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W57_LATENCY_WORDS = 8;

    /** Upper bounds (milliseconds) of the first seven histogram buckets; the eighth is +Inf. */
    public const array W57_LATENCY_BUCKETS = [100, 250, 500, 1000, 2500, 5000, 10000];

    public const int W57_HEALTH_UNKNOWN = 0;

    public const int W57_HEALTH_HEALTHY = 1;

    public const int W57_HEALTH_DEGRADED = 2;

    public const int W57_HEALTH_CRITICAL = 3;

    public const int W57_LATENCY_INVALID = 4;

    public const int W57_LATENCY_DEGRADED_P95 = 1000;

    public const int W57_LATENCY_CRITICAL_P99 = 5000;

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 57 batch routed to Rust or PHP, then restores the routing. */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /**
     * Eight words per `,`-separated window of millisecond latency samples (each trimmed, blanks
     * skipped, anything but 1-9 digits counted invalid): the health rollup (W57_HEALTH_UNKNOWN
     * without samples, CRITICAL from a W57_LATENCY_CRITICAL_P99 p99, DEGRADED from a
     * W57_LATENCY_DEGRADED_P95 p95 or any invalid sample, else HEALTHY) plus W57_LATENCY_INVALID
     * and the invalid count (at most 255) `<< 8`; then the sample count, sum, nearest-rank
     * p50/p95/p99 and max (-1 without samples) and the W57_LATENCY_BUCKETS histogram as eight
     * 8-bit saturating counts packed from the low byte.
     *
     * @param  array<array-key, string>  $windows
     * @return list<int>
     */
    public static function latencyBatch(array $windows): array
    {
        return self::usesW57RustFfi() ? self::latencyBatchNative($windows) : self::latencyBatchPhp($windows);
    }

    /**
     * @param  array<array-key, string>  $windows
     * @return list<int>
     */
    public static function latencyBatchPhp(array $windows): array
    {
        $words = [];
        foreach ($windows as $window) {
            $samples = [];
            $invalid = 0;
            foreach (explode(',', $window) as $raw) {
                $sample = trim($raw);
                if ($sample === '') {
                    continue;
                }
                if (\strlen($sample) <= 9 && ctype_digit($sample)) {
                    $samples[] = (int) $sample;
                } else {
                    $invalid++;
                }
            }
            sort($samples);
            $count = \count($samples);
            $sum = 0;
            $buckets = [0, 0, 0, 0, 0, 0, 0, 0];
            foreach ($samples as $sample) {
                $sum += $sample;
                $bucket = 7;
                foreach (self::W57_LATENCY_BUCKETS as $i => $limit) {
                    if ($sample <= $limit) {
                        $bucket = $i;
                        break;
                    }
                }
                $buckets[$bucket]++;
            }
            $packed = 0;
            foreach ($buckets as $i => $bucketCount) {
                $packed |= min(255, $bucketCount) << (8 * $i);
            }
            [$p50, $p95, $p99, $max] = $count === 0 ? [-1, -1, -1, -1] : [
                $samples[intdiv(50 * $count + 99, 100) - 1],
                $samples[intdiv(95 * $count + 99, 100) - 1],
                $samples[intdiv(99 * $count + 99, 100) - 1],
                $samples[$count - 1],
            ];
            $health = match (true) {
                $count === 0 => self::W57_HEALTH_UNKNOWN,
                $p99 >= self::W57_LATENCY_CRITICAL_P99 => self::W57_HEALTH_CRITICAL,
                $p95 >= self::W57_LATENCY_DEGRADED_P95 || $invalid > 0 => self::W57_HEALTH_DEGRADED,
                default => self::W57_HEALTH_HEALTHY,
            };
            array_push(
                $words,
                $health | ($invalid > 0 ? self::W57_LATENCY_INVALID : 0) | min(255, $invalid) << 8,
                $count,
                $sum,
                $p50,
                $p95,
                $p99,
                $max,
                $packed,
            );
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a window
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $windows
     * @return list<int>
     */
    public static function latencyBatchNative(array $windows): array
    {
        $count = \count($windows);
        $words = $count * self::W57_LATENCY_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w57RustFfiState('out', $words);
        if ($buffer === null) {
            return self::latencyBatchPhp($windows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $windows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w571_verification_latency_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($codes)) {
            return self::latencyBatchPhp($windows);
        }

        /** @var list<int> */
        return array_values($codes);
    }

    /**
     * Wave 57 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('release_verification_collector_w57', self::W57_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
