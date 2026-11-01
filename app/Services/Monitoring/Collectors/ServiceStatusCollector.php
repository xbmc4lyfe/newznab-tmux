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

    // ---- Wave 57.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 4.92x/5.21x, uncommon 4.24x/3.92x); see RustMcpPathGuardRepoContextRedactorStatusTmuxCollectorW57ParityTest. */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = <<<'C'
        intptr_t nntmux_w57_service_status_plan_batch(const char *statuses, size_t statuses_len, const char *ints, size_t ints_len, const char *nulls, size_t nulls_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 57 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w57', self::W57_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w57RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planStatusBatch() routed to Rust ($enabled) or PHP, then restores
     * the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w57Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w57Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** ServiceStatusEnum values => case position plus one. */
    private const array W57_STATUS_CODES = ['operational' => 1, 'degraded' => 2, 'partial_outage' => 3, 'major_outage' => 4, 'maintenance' => 5];

    /**
     * Batch rollup over stored service statuses and response times (ms, null when unmeasured),
     * evaluated as evaluateServiceMetricsPhp() does. Per service: `code | up << 3 | timed << 4
     * | (severity + 1) << 8 | clamp(ms, 0, 2^40 - 1) << 16`, code being the ServiceStatusEnum
     * case position plus one (0 and severity -1 for an unknown status). The six code counts,
     * up, down, incidents (severity >= 3), timed services, the saturating sum of non-negative
     * response times, the largest response time (PHP_INT_MIN when none), the worst severity
     * (-1 when none) and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $statuses
     * @param  list<int|null>  $responseTimesMs
     * @return list<int>
     */
    public static function planStatusBatch(array $statuses, array $responseTimesMs): array
    {
        return self::usesW57RustFfi() ? self::planStatusBatchNative($statuses, $responseTimesMs) : self::planStatusBatchPhp($statuses, $responseTimesMs);
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<int|null>  $responseTimesMs
     * @return list<int>
     */
    public static function planStatusBatchPhp(array $statuses, array $responseTimesMs): array
    {
        $responseTimesMs = array_values($responseTimesMs);
        $out = [];
        $codes = array_fill(0, 6, 0);
        $up = $down = $incidents = $timed = $sum = 0;
        $max = PHP_INT_MIN;
        $worst = -1;
        foreach (array_values($statuses) as $i => $status) {
            $enum = ServiceStatusEnum::tryFrom((string) $status);
            $code = $enum === null ? 0 : self::W57_STATUS_CODES[$enum->value];
            $severity = $enum?->severityPhp() ?? -1;
            $isUp = $enum !== null && $severity <= ServiceStatusEnum::Degraded->severityPhp();
            $codes[$code]++;
            $up += $isUp ? 1 : 0;
            $down += $enum !== null && ! $isUp ? 1 : 0;
            $incidents += $severity >= 3 ? 1 : 0;
            $worst = max($worst, $severity);
            $ms = $responseTimesMs[$i] ?? null;
            $packed = 0;
            if (\is_int($ms)) {
                $timed++;
                $positive = max(0, $ms);
                $sum = $sum > PHP_INT_MAX - $positive ? PHP_INT_MAX : $sum + $positive;
                $max = max($max, $ms);
                $packed = min($positive, 0xFFFFFFFFFF);
            }
            $out[] = $code | ($isUp ? 8 : 0) | (\is_int($ms) ? 16 : 0) | ($severity + 1) << 8 | $packed << 16;
        }
        array_push($out, ...$codes);
        array_push($out, $up, $down, $incidents, $timed, $sum, $max, $worst);
        $out[] = self::w57Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a status holds a NUL byte, a
     * response time is neither an integer nor null or the columns are not lists of one length.
     *
     * @param  list<string>  $statuses
     * @param  list<int|null>  $responseTimesMs
     * @return list<int>
     */
    public static function planStatusBatchNative(array $statuses, array $responseTimesMs): array
    {
        $count = \count($statuses);
        $words = $count + 14;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 || \count($responseTimesMs) !== $count ? null : self::w57RustFfiState('out', $words);
        if ($state === null || ! array_is_list($statuses) || ! array_is_list($responseTimesMs)) {
            return self::planStatusBatchPhp($statuses, $responseTimesMs);
        }
        foreach ($responseTimesMs as $ms) {
            if ($ms !== null && ! \is_int($ms)) {
                return self::planStatusBatchPhp($statuses, $responseTimesMs);
            }
        }
        $blob = implode("\0", $statuses);
        // pack() writes null as 0; the null indices travel separately.
        $ints = pack('P*', ...$responseTimesMs);
        $nulls = pack('P*', ...array_keys($responseTimesMs, null, true));
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W57_FFI_CDEF.)
            $written = $ffi->nntmux_w57_service_status_plan_batch($blob, \strlen($blob), $ints, \strlen($ints), $nulls, \strlen($nulls), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w57Words($written, $out, $words) ?? self::planStatusBatchPhp($statuses, $responseTimesMs);
    }
}
