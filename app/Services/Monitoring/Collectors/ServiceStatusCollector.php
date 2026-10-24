<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Enums\ServiceStatusEnum;
use App\Models\ServiceStatus;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Mirrors the /status page. Reads the stored results of nntmux:check-service-health
 * rather than probing live, so a slow NNTP probe can never stall the exporter.
 */
class ServiceStatusCollector implements MetricsCollector
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_service_status_collector_evaluate(const char *status_ptr, size_t status_len, uint8_t has_response_time_ms, int64_t response_time_ms, int32_t *out_up, int32_t *out_severity, int32_t *out_rt_mode, int64_t *out_rt_int_seconds, double *out_rt_float_seconds);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $int32Slots = null;

    private static ?CData $int64Slots = null;

    private static ?CData $doubleSlots = null;

    private static ?FFI $slotOwner = null;

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
        $services = ServiceStatus::query()->where('is_enabled', true)->orderBy('sort_order')->get();

        yield from $this->buildMetricFamilies($services);
    }

    /**
     * @return array{up: int, severity: int, response_time_seconds: int|float|null}
     */
    public static function evaluateServiceMetrics(
        ServiceStatusEnum|string $status,
        ?int $responseTimeMs,
    ): array {
        return self::$useRustFfi
            ? self::evaluateServiceMetricsNative($status, $responseTimeMs)
            : self::evaluateServiceMetricsPhp($status, $responseTimeMs);
    }

    /**
     * @return array{up: int, severity: int, response_time_seconds: int|float|null}
     */
    public static function evaluateServiceMetricsPhp(
        ServiceStatusEnum|string $status,
        ?int $responseTimeMs,
    ): array {
        $enum = $status instanceof ServiceStatusEnum ? $status : ServiceStatusEnum::from($status);
        $severity = $enum->severityPhp();

        return [
            'up' => $severity <= ServiceStatusEnum::Degraded->severityPhp() ? 1 : 0,
            'severity' => $severity,
            'response_time_seconds' => $responseTimeMs !== null ? $responseTimeMs / 1000 : null,
        ];
    }

    /**
     * @return array{up: int, severity: int, response_time_seconds: int|float|null}
     */
    public static function evaluateServiceMetricsNative(
        ServiceStatusEnum|string $status,
        ?int $responseTimeMs,
    ): array {
        $ffi = self::categorizeFfi();
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$int32Slots === null
            || self::$int64Slots === null
            || self::$doubleSlots === null
        ) {
            return self::evaluateServiceMetricsPhp($status, $responseTimeMs);
        }

        $statusVal = $status instanceof ServiceStatusEnum ? $status->value : $status;

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_service_status_collector_evaluate(
                $statusVal,
                \strlen($statusVal),
                $responseTimeMs !== null ? 1 : 0,
                $responseTimeMs ?? 0,
                FFI::addr(self::$int32Slots[0]),
                FFI::addr(self::$int32Slots[1]),
                FFI::addr(self::$int32Slots[2]),
                FFI::addr(self::$int64Slots[0]),
                FFI::addr(self::$doubleSlots[0]),
            );
            if ($rc === 1) {
                /** @var int $up */
                $up = self::$int32Slots[0];
                /** @var int $severity */
                $severity = self::$int32Slots[1];
                /** @var int $rtMode */
                $rtMode = self::$int32Slots[2];
                /** @var int $rtInt */
                $rtInt = self::$int64Slots[0];
                /** @var float $rtFloat */
                $rtFloat = self::$doubleSlots[0];

                return [
                    'up' => $up,
                    'severity' => $severity,
                    'response_time_seconds' => match ($rtMode) {
                        1 => $rtInt,
                        0 => $rtFloat,
                        default => null,
                    },
                ];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::evaluateServiceMetricsPhp($status, $responseTimeMs);
    }

    /**
     * @param  iterable<object>  $services
     * @return list<MetricFamily>
     */
    public function buildMetricFamilies(iterable $services): array
    {
        return self::$useRustFfi
            ? $this->buildMetricFamiliesNative($services)
            : $this->buildMetricFamiliesPhp($services);
    }

    /**
     * @param  iterable<object>  $services
     * @return list<MetricFamily>
     */
    public function buildMetricFamiliesPhp(iterable $services): array
    {
        return $this->assembleMetricFamilies($services, false);
    }

    /**
     * @param  iterable<object>  $services
     * @return list<MetricFamily>
     */
    public function buildMetricFamiliesNative(iterable $services): array
    {
        return $this->assembleMetricFamilies($services, true);
    }

    /**
     * @param  iterable<object>  $services
     * @return list<MetricFamily>
     */
    private function assembleMetricFamilies(iterable $services, bool $native): array
    {
        $up = MetricFamily::gauge('nntmux_service_up', 'Whether a monitored service is operational or degraded (1) or down (0).');
        $severity = MetricFamily::gauge('nntmux_service_severity', 'Service status severity: 0 operational, 1 degraded, 2 maintenance, 3 partial outage, 4 major outage.');
        $responseTime = MetricFamily::gauge('nntmux_service_response_time_seconds', 'Response time of the last service health check.');

        foreach ($services as $service) {
            /** @var string $slug */
            $slug = $service->slug;
            /** @var ServiceStatusEnum|string $status */
            $status = $service->status;
            /** @var int|null $responseTimeMs */
            $responseTimeMs = $service->response_time_ms;

            $labels = ['service' => $slug];
            $evaluated = $native
                ? self::evaluateServiceMetricsNative($status, $responseTimeMs)
                : self::evaluateServiceMetricsPhp($status, $responseTimeMs);

            $up->add($evaluated['up'], $labels);
            $severity->add($evaluated['severity'], $labels);

            if ($evaluated['response_time_seconds'] !== null) {
                $responseTime->add($evaluated['response_time_seconds'], $labels);
            }
        }

        return [$up, $severity, $responseTime];
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
                self::$int32Slots = $ffi->new('int32_t[3]');
                self::$int64Slots = $ffi->new('int64_t[1]');
                self::$doubleSlots = $ffi->new('double[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
