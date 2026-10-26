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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.36x, p99 5.59x; uncommon mean 6.08x, p99 4.63x); see RustMonitoringHorizonVerificationStatusTmuxGrafanaEmbedW68ParityTest. */
    public const bool W68_RUST_FFI_DEFAULT = true;

    public const string W68_SERVICE_STATUS_COLLECTOR_FFI_CDEF = <<<'C'
        ssize_t nntmux_w683_service_status_collector_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 68.3 batch. */
    public const int W68_SERVICE_STATUS_COLLECTOR_TRAILER = 29;

    public static function enableW68RustFfi(): void
    {
        self::w68RustFfiState('set', true);
    }

    public static function disableW68RustFfi(): void
    {
        self::w68RustFfiState('set', false);
    }

    /** Restores W68_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW68RustFfi(): void
    {
        self::w68RustFfiState('reset');
    }

    public static function usesW68RustFfi(): bool
    {
        $override = self::w68RustFfiState();

        return \is_bool($override) ? $override : self::W68_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ServiceStatusCollector Wave 68 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW68RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w68RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w68RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 68.3 service status plan. Each item is `service|status|latencyMs|readiness|liveness|checkedAgeSeconds`.
     * Status is matched caselessly after trimming (0 empty, 1-5 operational, degraded, partial_outage, major_outage,
     * maintenance, 6 other). Latency (9 digits) and age (10) are 0 empty, 1 digits or 2 other; the latency level is
     * 0 unmeasured, 1 under 250 ms, 2 under 1000, 3 under 5000 or 4 slower. Readiness and liveness are 0 empty, 1 up
     * (1, true, yes, up, ok, pass), 2 down (0, false, no, down, fail) or 3 other. Checks older than
     * W68_SERVICE_STALE_SECONDS are stale. Severity starts from the status (degraded 1, partial 2, major 3), rises to
     * 1 for level 3 latency, 2 for level 4 latency or failed readiness and 3 for failed liveness. Up means operational
     * or degraded with neither probe down. Decision 6 malformed, 0 skipped (no service or status), 1 maintenance, 2
     * stale, 3 critical, 4 degraded or 5 healthy; decisions 3 and 4 raise the alert flag. Per item `status |
     * latencyState << 3 | latencyLevel << 5 | readiness << 8 | liveness << 10 | ageState << 12 | stale << 14 | up << 15
     * | severity << 16 | alert << 18 | decision << 19 | latency (cap 2^24 - 1) << 22 | serviceLength (cap 255) << 46`.
     * Then the seven decision, seven status, five latency-level and four severity counts, the alerts, the up
     * services, the latency sum and maximum (measured, non-malformed), the 1-based first critical item and the
     * distinct lower-cased services.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68ServiceStatusCollectorPlanBatch(array $items): array
    {
        return self::usesW68RustFfi() ? self::w68ServiceStatusCollectorPlanBatchNative($items) : self::w68ServiceStatusCollectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68ServiceStatusCollectorPlanBatchNative(array $items): array
    {
        return self::w68Invoke($items, \count($items) + self::W68_SERVICE_STATUS_COLLECTOR_TRAILER) ?? self::w68ServiceStatusCollectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68ServiceStatusCollectorPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0, 0, 0];
        $statuses = [0, 0, 0, 0, 0, 0, 0];
        $levels = [0, 0, 0, 0, 0];
        $severities = [0, 0, 0, 0];
        $alerts = $upCount = $latencySum = $maxLatency = $firstCritical = 0;
        $services = [];
        $index = 0;
        foreach ($items as $item) {
            [$rawService, $rawStatus, $rawLatency, $rawReady, $rawLive, $rawAge] = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            $service = trim($rawService);
            $status = self::w68Keyword($rawStatus, self::W68_SERVICE_STATUSES);
            [$latencyState, $latency] = self::w68Count($rawLatency, 9);
            $level = $latencyState !== 1 ? 0 : ($latency < 250 ? 1 : ($latency < 1000 ? 2 : ($latency < 5000 ? 3 : 4)));
            $ready = self::W68_SERVICE_PROBE_STATES[self::w68Keyword($rawReady, self::W68_SERVICE_PROBE_WORDS)];
            $live = self::W68_SERVICE_PROBE_STATES[self::w68Keyword($rawLive, self::W68_SERVICE_PROBE_WORDS)];
            [$ageState, $age] = self::w68Count($rawAge, 10);
            $stale = $ageState === 1 && $age > self::W68_SERVICE_STALE_SECONDS;
            $severity = match ($status) {
                2 => 1,
                3 => 2,
                4 => 3,
                default => 0,
            };
            if ($level === 3) {
                $severity = max($severity, 1);
            }
            if ($level === 4 || $ready === 2) {
                $severity = max($severity, 2);
            }
            if ($live === 2) {
                $severity = 3;
            }
            $up = ($status === 1 || $status === 2) && $live !== 2 && $ready !== 2;
            if ($status === 6 || $latencyState === 2 || $ageState === 2 || $ready === 3 || $live === 3) {
                $decision = 6;
            } elseif ($service === '' || $status === 0) {
                $decision = 0;
            } elseif ($status === 5) {
                $decision = 1;
            } elseif ($stale) {
                $decision = 2;
            } elseif ($severity === 3) {
                $decision = 3;
            } elseif ($severity >= 1) {
                $decision = 4;
            } else {
                $decision = 5;
            }
            $alert = $decision === 3 || $decision === 4;
            $decisions[$decision]++;
            $statuses[$status]++;
            $levels[$level]++;
            $severities[$severity]++;
            $alerts += $alert ? 1 : 0;
            $upCount += $up ? 1 : 0;
            if ($latencyState === 1 && $decision !== 6) {
                $latencySum = min($latencySum + $latency, self::W68_TOTAL_CAP);
                $maxLatency = max($maxLatency, $latency);
            }
            if ($decision === 3 && $firstCritical === 0) {
                $firstCritical = $index + 1;
            }
            if ($service !== '') {
                $services[strtolower($service)] = true;
            }
            $out[] = $status | $latencyState << 3 | $level << 5 | $ready << 8 | $live << 10 | $ageState << 12 | ($stale ? 1 : 0) << 14 | ($up ? 1 : 0) << 15
                | $severity << 16 | ($alert ? 1 : 0) << 18 | $decision << 19 | min($latency, 16_777_215) << 22 | min(\strlen($service), 255) << 46;
            $index++;
        }
        array_push($out, ...$decisions);
        array_push($out, ...$statuses);
        array_push($out, ...$levels);
        array_push($out, ...$severities);
        array_push($out, $alerts, $upCount, $latencySum, $maxLatency, $firstCritical, \count($services));

        return $out;
    }

    /** Wave 68.3 service statuses, in plan order (ServiceStatusEnum backing values). */
    private const array W68_SERVICE_STATUSES = ['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance'];

    /** Wave 68.3 probe words: the first six read as up, the rest as down. */
    private const array W68_SERVICE_PROBE_WORDS = ['1', 'true', 'yes', 'up', 'ok', 'pass', '0', 'false', 'no', 'down', 'fail'];

    /** Probe state by W68_SERVICE_PROBE_WORDS keyword: 0 empty, 1 up, 2 down, 3 other. */
    private const array W68_SERVICE_PROBE_STATES = [0, 1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 3];

    /** Check age after which a Wave 68.3 probe result counts as stale. */
    public const int W68_SERVICE_STALE_SECONDS = 300;

    /** Cap for Wave 68.3 running totals. */
    private const int W68_TOTAL_CAP = 1 << 62;

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w68Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * The trimmed field, lower-cased and matched against $words: 0 empty, 1-based index, or the word count + 1.
     *
     * @param  list<string>  $words
     */
    private static function w68Keyword(string $text, array $words): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $position = array_search(strtolower($text), $words, true);

        return $position === false ? \count($words) + 1 : $position + 1;
    }

    /**
     * Wave 68 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w68RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('monitoring_w683', self::W68_SERVICE_STATUS_COLLECTOR_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w68RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w68RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 68.3 ServiceStatusCollector export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w68Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w68RustFfiState('ffi');
            $out = self::w68RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w68RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W68_SERVICE_STATUS_COLLECTOR_FFI_CDEF.)
            $written = $ffi->nntmux_w683_service_status_collector_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
