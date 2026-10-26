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

    public const string W50_FFI_CDEF = 'int64_t nntmux_w505_redis_probe_outcome(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * Disabled: the common-path gains stay below 1.5x because the per-record PHP work is cheaper than packing
     * and decoding the batch (see RustProbesRedisSearchRegistryCursorFailureW50ParityTest::QUALIFICATION).
     */
    private const W50_RUST_FFI_DEFAULT = false;

    private static ?bool $w50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$w50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 50.5 batch helper routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w50RustFfi;
        self::$w50RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w50RustFfi = $previous;
        }
    }

    /** PHP `hash('xxh64')` of $value read as a signed big-endian word, as the Rust helper reports it. */
    private static function w50Digest(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $value, true));

        return $unpacked[1];
    }

    /**
     * Batch outcome of probe() for many pings. Four words per probe, aligned with $pongs: `[code (0 connected,
     * 1 unexpected response, 2 unreachable), responseTimeMs, reason length, xxh64(reason)]`, following
     * evaluatePingResponsePhp() and, for a probe whose ping threw ($errors holds its message),
     * formatUnreachableResultPhp().
     *
     * @param  list<string|bool|null>  $pongs
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  array<int, string>  $errors  probe index => exception message
     * @return list<int>
     */
    public static function probeOutcomeBatch(array $pongs, array $startNs, array $endNs, array $errors = []): array
    {
        return self::usesW50RustFfi()
            ? self::probeOutcomeBatchNative($pongs, $startNs, $endNs, $errors)
            : self::probeOutcomeBatchPhp($pongs, $startNs, $endNs, $errors);
    }

    /**
     * @param  list<string|bool|null>  $pongs
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  array<int, string>  $errors
     * @return list<int>
     */
    public static function probeOutcomeBatchPhp(array $pongs, array $startNs, array $endNs, array $errors = []): array
    {
        static $connected = null;
        static $unexpected = null;
        $connected ??= self::w50Digest('Connected');
        $unexpected ??= self::w50Digest('Redis ping returned unexpected response');
        $out = [];
        foreach ($pongs as $i => $pong) {
            if (isset($errors[$i])) {
                $reason = 'Redis unreachable: '.Str::limit($errors[$i], 120);
                array_push($out, 2, 0, \strlen($reason), self::w50Digest($reason));

                continue;
            }
            $elapsed = (int) (($endNs[$i] - $startNs[$i]) / 1_000_000);
            if ($pong === true || $pong === '+PONG' || strtoupper((string) $pong) === 'PONG') {
                array_push($out, 0, $elapsed, 9, $connected);
            } else {
                array_push($out, 1, $elapsed, 39, $unexpected);
            }
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the columns differ in length or a value holds
     * a NUL byte, and computes non-ASCII errors and overflowing timings with PHP.
     *
     * @param  list<string|bool|null>  $pongs
     * @param  list<int>  $startNs
     * @param  list<int>  $endNs
     * @param  array<int, string>  $errors
     * @return list<int>
     */
    public static function probeOutcomeBatchNative(array $pongs, array $startNs, array $endNs, array $errors = []): array
    {
        $count = \count($pongs);
        if ($count === 0 || \count($startNs) !== $count || \count($endNs) !== $count || ! array_is_list($pongs)) {
            return self::probeOutcomeBatchPhp($pongs, $startNs, $endNs, $errors);
        }
        // NUL-delimited pongs then errors; true pongs travel as indices because implode() renders them "1".
        $trues = array_keys($pongs, true, true);
        $data = $errors === [] ? implode("\0", $pongs) : implode("\0", $pongs)."\0".implode("\0", $errors);
        $words = self::w50Words($count, $data, pack('P*', \count($errors), \count($trues), ...$trues, ...$startNs, ...$endNs, ...array_keys($errors)), 4 * $count, $patches);
        if ($words === null) {
            return self::probeOutcomeBatchPhp($pongs, $startNs, $endNs, $errors);
        }
        if ($patches > 0) {
            foreach (array_keys($words, -1, true) as $key) {
                if ($key % 4 === 0) {
                    $i = intdiv($key, 4);
                    [$words[$key], $words[$key + 1], $words[$key + 2], $words[$key + 3]] = self::probeOutcomeBatchPhp([$pongs[$i]], [$startNs[$i]], [$endNs[$i]], isset($errors[$i]) ? [$errors[$i]] : []);
                }
            }
        }

        return $words;
    }

    /**
     * Exactly $words output words from the Wave 50.5 Rust helper, or null to fall back to PHP. The helper
     * leads with the number of rows PHP must compute, returned in $patches.
     *
     * @return list<int>|null
     */
    private static function w50Words(int $count, string $data, string $ints, int $words, ?int &$patches = null): ?array
    {
        if (self::$w50Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w50Ffi = RustPipelineLibrary::resolveDomain('search_w50', self::W50_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w50Out = null;
            self::$w50OutCap = 0;
        }
        $ffi = self::$w50Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w50Out === null || $words >= self::$w50OutCap) {
                $cap = max(1024, $words + 1);
                self::$w50Out = $ffi->new('int64_t['.$cap.']');
                self::$w50OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w505_redis_probe_outcome($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w50Out, self::$w50OutCap);
            if ($written !== $words + 1) {
                return null;
            }
            $patches = self::$w50Out[0];
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w50Out, $written * 8), 8);

            return \is_array($values) ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Enabled: the paired benchmark in the W56 parity test measures 4.365x common mean, 3.336x
     * common p99, 2.838x uncommon mean and 3.355x uncommon p99 (per-metric minimum of three runs),
     * above the 1.5x rule.
     */
    private const bool W56_RUST_FFI_DEFAULT = true;

    private const string W56_FFI_CDEF = 'ssize_t nntmux_w561_redis_info_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    /** Integer `INFO` fields, in output order. */
    public const array W56_INFO_INT_KEYS = ['used_memory', 'maxmemory', 'connected_clients', 'instantaneous_ops_per_sec', 'keyspace_hits', 'keyspace_misses'];

    /** Words per `INFO` payload in infoWords(). */
    public const int W56_INFO_WORDS = 9;

    /** Memory use (permille of maxmemory) at which a payload is degraded. */
    public const int W56_INFO_DEGRADED_PERMILLE = 900;

    public static function usesW56RustFfi(): bool
    {
        $enabled = self::w56RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W56_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 56 batch routed to Rust or PHP, then restores the routing. */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /**
     * Parses Redis `INFO` payloads (`/\r?\n/` lines, `#` section headers skipped, `key:value`
     * fields, the last duplicate winning). Nine words per payload: the W56_INFO_INT_KEYS values
     * (-1 when missing or not 1-15 digits), `role | link << 2 | health << 4` (role: missing 0,
     * master 1, slave 2, other 3; master_link_status: missing 0, up 1, down 2, other 3; health:
     * 0 healthy, 1 degraded for a replica whose link is not up or memory use at
     * W56_INFO_DEGRADED_PERMILLE, 2 unhealthy without used_memory or connected_clients), the
     * keyspace hit rate and the used/max memory ratio in permille (-1 when undefined).
     *
     * @param  array<array-key, string>  $payloads
     * @return list<int>
     */
    public static function infoWords(array $payloads): array
    {
        return self::usesW56RustFfi() ? self::infoWordsNative($payloads) : self::infoWordsPhp($payloads);
    }

    /**
     * @param  array<array-key, string>  $payloads
     * @return list<int>
     */
    public static function infoWordsPhp(array $payloads): array
    {
        $words = [];
        foreach ($payloads as $payload) {
            $fields = [];
            foreach (preg_split('/\r?\n/', $payload) ?: [] as $line) {
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $fields[substr($line, 0, $colon)] = substr($line, $colon + 1);
                }
            }
            $ints = [];
            foreach (self::W56_INFO_INT_KEYS as $key) {
                $value = $fields[$key] ?? null;
                $ints[] = $value !== null && preg_match('/\A\d{1,15}\z/', $value) === 1 ? (int) $value : -1;
            }
            [$used, $max, $clients, , $hits, $misses] = $ints;
            $role = match ($fields['role'] ?? null) {
                null => 0,
                'master' => 1,
                'slave' => 2,
                default => 3,
            };
            $link = match ($fields['master_link_status'] ?? null) {
                null => 0,
                'up' => 1,
                'down' => 2,
                default => 3,
            };
            $hitRate = $hits >= 0 && $misses >= 0 && $hits + $misses > 0 ? intdiv($hits * 1000, $hits + $misses) : -1;
            $memory = $used >= 0 && $max > 0 ? intdiv($used * 1000, $max) : -1;
            $health = match (true) {
                $used < 0 || $clients < 0 => 2,
                ($role === 2 && $link !== 1) || $memory >= self::W56_INFO_DEGRADED_PERMILLE => 1,
                default => 0,
            };
            array_push($words, ...$ints);
            array_push($words, $role | $link << 2 | $health << 4, $hitRate, $memory);
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a row
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $payloads
     * @return list<int>
     */
    public static function infoWordsNative(array $payloads): array
    {
        $count = \count($payloads);
        $words = 9 * $count;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w56RustFfiState('out', $words);
        if ($buffer === null) {
            return self::infoWordsPhp($payloads);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $payloads);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w561_redis_info_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($codes)) {
            return self::infoWordsPhp($payloads);
        }

        /** @var list<int> */
        return array_values($codes);
    }

    /**
     * Wave 56 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('redis_probe_w56', self::W56_FFI_CDEF) ?? false;
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
