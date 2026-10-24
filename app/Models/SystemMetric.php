<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class SystemMetric extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    private const int SECONDS_PER_HOUR = 3600;

    private const int SECONDS_PER_DAY = 86400;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_system_metric_period_cutoff_ts(
            int64_t now_ts,
            int64_t hours,
            int64_t *out_ts
        );
        int32_t nntmux_system_metric_cleanup_cutoff_ts(
            int64_t now_ts,
            int64_t days,
            int64_t *out_ts
        );
        ssize_t nntmux_system_metric_normalize_type(
            const char *type_ptr,
            size_t type_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustRolePaymentGenreMetricModelParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $fillable = [
        'metric_type',
        'value',
        'recorded_at',
    ];

    protected $casts = [
        'value' => 'float',
        'recorded_at' => 'datetime',
    ];

    public static function setUseRustFfi(bool $useRustFfi = true): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Scope to get CPU metrics
     */
    public function scopeCpu(mixed $query): mixed
    {
        return $query->where('metric_type', 'cpu');
    }

    /**
     * Scope to get RAM metrics
     */
    public function scopeRam(mixed $query): mixed
    {
        return $query->where('metric_type', 'ram');
    }

    /**
     * Scope to get metrics for a specific time period
     */
    public function scopeForPeriod(mixed $query, int $hours): mixed
    {
        return $query->where('recorded_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope to get metrics for a specific date range
     */
    public function scopeBetweenDates(mixed $query, mixed $startDate, mixed $endDate): mixed
    {
        return $query->whereBetween('recorded_at', [$startDate, $endDate]);
    }

    /**
     * Clean up old metrics (older than specified days)
     */
    public static function cleanupOldMetrics(int $days = 60): int
    {
        return static::where('recorded_at', '<', now()->subDays($days))->delete();
    }

    public static function computePeriodCutoffTimestamp(int $nowTs, int $hours): int
    {
        return self::$useRustFfi
            ? self::computePeriodCutoffTimestampNative($nowTs, $hours)
            : self::computePeriodCutoffTimestampPhp($nowTs, $hours);
    }

    public static function computePeriodCutoffTimestampNative(int $nowTs, int $hours): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::computePeriodCutoffTimestampPhp($nowTs, $hours);
        }

        try {
            $outSlot = $ffi->new('int64_t[1]');
            $rc = self::callFfi($ffi, 'nntmux_system_metric_period_cutoff_ts', $nowTs, $hours, $outSlot);
            if ($rc === 1) {
                return (int) $outSlot[0];
            }
        } catch (Throwable) {
        }

        return self::computePeriodCutoffTimestampPhp($nowTs, $hours);
    }

    public static function computePeriodCutoffTimestampPhp(int $nowTs, int $hours): int
    {
        return $nowTs - ($hours * self::SECONDS_PER_HOUR);
    }

    public static function computeCleanupCutoffTimestamp(int $nowTs, int $days = 60): int
    {
        return self::$useRustFfi
            ? self::computeCleanupCutoffTimestampNative($nowTs, $days)
            : self::computeCleanupCutoffTimestampPhp($nowTs, $days);
    }

    public static function computeCleanupCutoffTimestampNative(int $nowTs, int $days = 60): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::computeCleanupCutoffTimestampPhp($nowTs, $days);
        }

        try {
            $outSlot = $ffi->new('int64_t[1]');
            $rc = self::callFfi($ffi, 'nntmux_system_metric_cleanup_cutoff_ts', $nowTs, $days, $outSlot);
            if ($rc === 1) {
                return (int) $outSlot[0];
            }
        } catch (Throwable) {
        }

        return self::computeCleanupCutoffTimestampPhp($nowTs, $days);
    }

    public static function computeCleanupCutoffTimestampPhp(int $nowTs, int $days = 60): int
    {
        return $nowTs - ($days * self::SECONDS_PER_DAY);
    }

    /**
     * @return array{metric_type: string, value: float, recorded_at: mixed}
     */
    public static function normalizeMetricAttributes(string $metricType, float $value, mixed $recordedAt): array
    {
        return self::$useRustFfi
            ? self::normalizeMetricAttributesNative($metricType, $value, $recordedAt)
            : self::normalizeMetricAttributesPhp($metricType, $value, $recordedAt);
    }

    /**
     * @return array{metric_type: string, value: float, recorded_at: mixed}
     */
    public static function normalizeMetricAttributesNative(string $metricType, float $value, mixed $recordedAt): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeMetricAttributesPhp($metricType, $value, $recordedAt);
        }

        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(64, strlen($metricType) + 16), $capacity);
            if ($outBuf === null) {
                return self::normalizeMetricAttributesPhp($metricType, $value, $recordedAt);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_system_metric_normalize_type',
                $metricType,
                strlen($metricType),
                $outBuf,
                $capacity
            );

            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                return [
                    'metric_type' => $written === 0 ? '' : FFI::string($outBuf, $written),
                    'value' => $value,
                    'recorded_at' => $recordedAt,
                ];
            }
        } catch (Throwable) {
        }

        return self::normalizeMetricAttributesPhp($metricType, $value, $recordedAt);
    }

    /**
     * @return array{metric_type: string, value: float, recorded_at: mixed}
     */
    public static function normalizeMetricAttributesPhp(string $metricType, float $value, mixed $recordedAt): array
    {
        return [
            'metric_type' => strtolower(trim($metricType)),
            'value' => $value,
            'recorded_at' => $recordedAt,
        ];
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
