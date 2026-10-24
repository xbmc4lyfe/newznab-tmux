<?php

declare(strict_types=1);

namespace App\Services\StatusProbes;

use App\Enums\IncidentImpactEnum;
use App\Services\StatusProbes\Contracts\ServiceProbeInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

class RedisProbe implements ServiceProbeInterface
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_redis_probe_uses_redis(const char *cache_default_ptr, size_t cache_default_len, const char *session_driver_ptr, size_t session_driver_len, const char *queue_default_ptr, size_t queue_default_len, const char *pulse_ingest_ptr, size_t pulse_ingest_len, const char *cache_store_driver_ptr, size_t cache_store_driver_len, const char *failover_stores_nul_ptr, size_t failover_stores_nul_len, size_t failover_stores_count);
        ssize_t nntmux_redis_probe_evaluate_ping(uint8_t is_bool_true, const char *pong_ptr, size_t pong_len, int64_t start_ns, int64_t end_ns, int64_t *out_elapsed_ms, int32_t *out_verdict, char *out_buf, size_t out_cap);
        ssize_t nntmux_status_probe_format_failure(uint8_t probe_kind, const char *error_ptr, size_t error_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $elapsedSlot = null;

    private static ?CData $verdictSlot = null;

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

    public static function mediaFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function identifier(): string
    {
        return 'redis';
    }

    public function probe(): ProbeResult
    {
        if (! config('status-probes.redis.probe', true)) {
            return new ProbeResult(
                ok: true,
                responseTimeMs: 0,
                impact: null,
                reason: 'Redis probe disabled (STATUS_PROBE_REDIS=false)',
            );
        }

        if (config('status-probes.redis.only_when_used', true) && ! $this->applicationUsesRedis()) {
            return new ProbeResult(
                ok: true,
                responseTimeMs: 0,
                impact: null,
                reason: 'Skipping: cache/session/queue do not use Redis',
            );
        }

        try {
            $start = hrtime(true);
            $pong = Redis::connection()->ping();
            $end = hrtime(true);

            return self::evaluatePingResponse($pong, $start, $end);
        } catch (Throwable $e) {
            return self::formatUnreachableResult($e->getMessage());
        }
    }

    /**
     * @param  list<string>  $failoverStores
     */
    public static function evaluateDriverUsage(
        string $cacheDefault,
        string $sessionDriver,
        string $queueDefault,
        string $pulseIngest,
        string $cacheStoreDriver = '',
        array $failoverStores = [],
    ): bool {
        return self::$useRustFfi
            ? self::evaluateDriverUsageNative($cacheDefault, $sessionDriver, $queueDefault, $pulseIngest, $cacheStoreDriver, $failoverStores)
            : self::evaluateDriverUsagePhp($cacheDefault, $sessionDriver, $queueDefault, $pulseIngest, $cacheStoreDriver, $failoverStores);
    }

    /**
     * @param  list<string>  $failoverStores
     */
    public static function evaluateDriverUsagePhp(
        string $cacheDefault,
        string $sessionDriver,
        string $queueDefault,
        string $pulseIngest,
        string $cacheStoreDriver = '',
        array $failoverStores = [],
    ): bool {
        if (
            strtolower($cacheDefault) === 'redis'
            || strtolower($sessionDriver) === 'redis'
            || strtolower($queueDefault) === 'redis'
        ) {
            return true;
        }

        if ($cacheStoreDriver === 'failover') {
            foreach ($failoverStores as $subStore) {
                if (strtolower((string) $subStore) === 'redis') {
                    return true;
                }
            }
        }

        return strtolower($pulseIngest) === 'redis';
    }

    /**
     * @param  list<string>  $failoverStores
     */
    public static function evaluateDriverUsageNative(
        string $cacheDefault,
        string $sessionDriver,
        string $queueDefault,
        string $pulseIngest,
        string $cacheStoreDriver = '',
        array $failoverStores = [],
    ): bool {
        $ffi = self::mediaFfi();
        if (
            $ffi === null
            || str_contains($cacheDefault, "\0")
            || str_contains($sessionDriver, "\0")
            || str_contains($queueDefault, "\0")
            || str_contains($pulseIngest, "\0")
            || str_contains($cacheStoreDriver, "\0")
        ) {
            return self::evaluateDriverUsagePhp($cacheDefault, $sessionDriver, $queueDefault, $pulseIngest, $cacheStoreDriver, $failoverStores);
        }

        $normalizedStores = [];
        foreach ($failoverStores as $subStore) {
            $subStr = (string) $subStore;
            if (str_contains($subStr, "\0")) {
                return self::evaluateDriverUsagePhp($cacheDefault, $sessionDriver, $queueDefault, $pulseIngest, $cacheStoreDriver, $failoverStores);
            }
            $normalizedStores[] = $subStr;
        }
        $packedStores = implode("\0", $normalizedStores);

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_redis_probe_uses_redis(
                $cacheDefault,
                \strlen($cacheDefault),
                $sessionDriver,
                \strlen($sessionDriver),
                $queueDefault,
                \strlen($queueDefault),
                $pulseIngest,
                \strlen($pulseIngest),
                $cacheStoreDriver,
                \strlen($cacheStoreDriver),
                $packedStores,
                \strlen($packedStores),
                \count($normalizedStores),
            );
            if ($rc === 0 || $rc === 1) {
                return $rc === 1;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::evaluateDriverUsagePhp($cacheDefault, $sessionDriver, $queueDefault, $pulseIngest, $cacheStoreDriver, $failoverStores);
    }

    public static function evaluatePingResponse(mixed $pong, int $startNs, int $endNs): ProbeResult
    {
        return self::$useRustFfi
            ? self::evaluatePingResponseNative($pong, $startNs, $endNs)
            : self::evaluatePingResponsePhp($pong, $startNs, $endNs);
    }

    public static function evaluatePingResponsePhp(mixed $pong, int $startNs, int $endNs): ProbeResult
    {
        $elapsed = (int) (($endNs - $startNs) / 1_000_000);
        $isOk = $pong === true || $pong === '+PONG' || strtoupper((string) $pong) === 'PONG';

        if (! $isOk) {
            return new ProbeResult(
                ok: false,
                responseTimeMs: $elapsed,
                impact: IncidentImpactEnum::Major,
                reason: 'Redis ping returned unexpected response',
                metadata: ['response' => $pong],
            );
        }

        return new ProbeResult(
            ok: true,
            responseTimeMs: $elapsed,
            impact: null,
            reason: 'Connected',
        );
    }

    public static function evaluatePingResponseNative(mixed $pong, int $startNs, int $endNs): ProbeResult
    {
        $ffi = self::mediaFfi();
        if (
            $ffi === null
            || ! self::ensureSlots($ffi)
            || self::$elapsedSlot === null
            || self::$verdictSlot === null
            || (! \is_scalar($pong) && $pong !== null && ! (\is_object($pong) && method_exists($pong, '__toString')))
        ) {
            return self::evaluatePingResponsePhp($pong, $startNs, $endNs);
        }

        $isBoolTrue = $pong === true ? 1 : 0;
        $pongStr = $pong === true ? '' : (string) $pong;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
        if ($scratch === null) {
            return self::evaluatePingResponsePhp($pong, $startNs, $endNs);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_redis_probe_evaluate_ping(
                $isBoolTrue,
                $pongStr,
                \strlen($pongStr),
                $startNs,
                $endNs,
                self::$elapsedSlot,
                self::$verdictSlot,
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::evaluatePingResponsePhp($pong, $startNs, $endNs);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_redis_probe_evaluate_ping(
                    $isBoolTrue,
                    $pongStr,
                    \strlen($pongStr),
                    $startNs,
                    $endNs,
                    self::$elapsedSlot,
                    self::$verdictSlot,
                    $scratch,
                    $cap,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::evaluatePingResponsePhp($pong, $startNs, $endNs);
            }

            /** @var int $elapsed */
            $elapsed = self::$elapsedSlot[0];
            /** @var int $verdict */
            $verdict = self::$verdictSlot[0];
            $reason = FFI::string($scratch, $written);

            return match ($verdict) {
                0 => new ProbeResult(
                    ok: true,
                    responseTimeMs: $elapsed,
                    impact: null,
                    reason: $reason,
                ),
                1 => new ProbeResult(
                    ok: false,
                    responseTimeMs: $elapsed,
                    impact: IncidentImpactEnum::Major,
                    reason: $reason,
                    metadata: ['response' => $pong],
                ),
                default => self::evaluatePingResponsePhp($pong, $startNs, $endNs),
            };
        } catch (Throwable) {
            return self::evaluatePingResponsePhp($pong, $startNs, $endNs);
        }
    }

    public static function formatUnreachableResult(string $errorMessage): ProbeResult
    {
        return self::$useRustFfi
            ? self::formatUnreachableResultNative($errorMessage)
            : self::formatUnreachableResultPhp($errorMessage);
    }

    public static function formatUnreachableResultPhp(string $errorMessage): ProbeResult
    {
        return new ProbeResult(
            ok: false,
            responseTimeMs: 0,
            impact: IncidentImpactEnum::Critical,
            reason: 'Redis unreachable: '.Str::limit($errorMessage, 120),
        );
    }

    public static function formatUnreachableResultNative(string $errorMessage): ProbeResult
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return self::formatUnreachableResultPhp($errorMessage);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return self::formatUnreachableResultPhp($errorMessage);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_status_probe_format_failure(
                0,
                $errorMessage,
                \strlen($errorMessage),
                $scratch,
                $cap,
            );
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatUnreachableResultPhp($errorMessage);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_status_probe_format_failure(
                    0,
                    $errorMessage,
                    \strlen($errorMessage),
                    $scratch,
                    $cap,
                );
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return new ProbeResult(
                    ok: false,
                    responseTimeMs: 0,
                    impact: IncidentImpactEnum::Critical,
                    reason: FFI::string($scratch, $written),
                );
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::formatUnreachableResultPhp($errorMessage);
    }

    private function applicationUsesRedis(): bool
    {
        $name = (string) config('cache.default', '');
        $store = config('cache.stores.'.$name);
        $cacheStoreDriver = \is_array($store) ? (string) ($store['driver'] ?? '') : '';
        $failoverStores = [];
        if (\is_array($store) && \is_array($store['stores'] ?? null)) {
            foreach ($store['stores'] as $subStore) {
                $failoverStores[] = (string) $subStore;
            }
        }

        return self::evaluateDriverUsage(
            (string) config('cache.default', ''),
            (string) config('session.driver', ''),
            (string) config('queue.default', ''),
            (string) config('pulse.ingest.driver', ''),
            $cacheStoreDriver,
            $failoverStores,
        );
    }

    private static function ensureSlots(FFI $ffi): bool
    {
        if (self::$slotOwner !== $ffi || self::$elapsedSlot === null || self::$verdictSlot === null) {
            try {
                self::$elapsedSlot = $ffi->new('int64_t[1]');
                self::$verdictSlot = $ffi->new('int32_t[1]');
                self::$slotOwner = $ffi;
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
