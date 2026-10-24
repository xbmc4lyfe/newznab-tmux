<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's cache "failover" driver still tries Redis on every read/write, so when Redis is down
 * each Cache call pays the full connection timeout (very slow + spinners on admin/API-heavy pages).
 *
 * This middleware runs first (global stack): quick TCP reachability check to the configured Redis
 * host/port, then switches default cache + session to file for this request only. terminate()
 * restores config for Octane/long-lived workers.
 */
class DegradeWhenRedisUnreachable
{
    /**
     * Rust candidates for the probe decision and Redis target normalization. They stay
     * opt-in: none beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustRedisDegradeQuerySessionJsonMiddlewareParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_redis_failover_contains_redis(int32_t is_array, const char *driver, size_t driver_len, int32_t driver_is_string, const char *names, size_t names_len, const size_t *lens, size_t count);
        int32_t nntmux_redis_should_probe(const char *cache_default, size_t cache_default_len, int32_t failover_contains_redis, const char *session_driver, size_t session_driver_len, int32_t session_is_string);
        intptr_t nntmux_redis_stream_remote(const char *scheme, size_t scheme_len, const char *host, size_t host_len, int64_t port, char *out, size_t cap);
        intptr_t nntmux_redis_tcp_target(int32_t use_url, const char *host, size_t host_len, int32_t has_host, int64_t port, int32_t has_port, const char *scheme, size_t scheme_len, int32_t has_scheme, char *out, size_t cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('nntmux.redis_fast_degrade', true)) {
            return $next($request);
        }

        if ($this->runningUnitTests()) {
            return $next($request);
        }

        if (! $this->shouldProbeRedis()) {
            return $next($request);
        }

        $target = $this->redisTcpTarget();
        if ($target === null) {
            return $next($request);
        }

        if ($this->tcpReachable($target['remote'], (float) config('nntmux.redis_tcp_check_seconds', 0.2))) {
            return $next($request);
        }

        $request->attributes->set('redis_degrade_original_config', [
            'cache.default' => config('cache.default'),
            'session.driver' => config('session.driver'),
        ]);

        $this->degradeAwayFromRedis();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $snapshot = $request->attributes->get('redis_degrade_original_config');
        if (! is_array($snapshot)) {
            return;
        }

        foreach ($snapshot as $key => $value) {
            Config::set($key, $value);
        }
    }

    private function runningUnitTests(): bool
    {
        return app()->environment('testing');
    }

    private function shouldProbeRedis(): bool
    {
        $cacheDefault = (string) config('cache.default', '');

        return self::shouldProbeRedisFor($cacheDefault, $this->failoverChainContainsRedis($cacheDefault), config('session.driver'));
    }

    public static function shouldProbeRedisFor(string $cacheDefault, bool $failoverContainsRedis, mixed $sessionDriver): bool
    {
        return self::$useRustFfi
            ? self::shouldProbeRedisForNative($cacheDefault, $failoverContainsRedis, $sessionDriver)
            : self::shouldProbeRedisForPhp($cacheDefault, $failoverContainsRedis, $sessionDriver);
    }

    public static function shouldProbeRedisForPhp(string $cacheDefault, bool $failoverContainsRedis, mixed $sessionDriver): bool
    {
        if ($cacheDefault === 'redis' || $cacheDefault === 'failover_redis_file' || $failoverContainsRedis) {
            return true;
        }

        return $sessionDriver === 'redis';
    }

    public static function shouldProbeRedisForNative(string $cacheDefault, bool $failoverContainsRedis, mixed $sessionDriver): bool
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::shouldProbeRedisForPhp($cacheDefault, $failoverContainsRedis, $sessionDriver);
        }

        $session = \is_string($sessionDriver) ? $sessionDriver : '';
        // @phpstan-ignore method.notFound
        $result = $ffi->nntmux_redis_should_probe($cacheDefault, \strlen($cacheDefault), $failoverContainsRedis ? 1 : 0, $session, \strlen($session), \is_string($sessionDriver) ? 1 : 0);

        return \is_int($result) && $result >= 0 ? $result === 1 : self::shouldProbeRedisForPhp($cacheDefault, $failoverContainsRedis, $sessionDriver);
    }

    private function failoverChainContainsRedis(string $storeName): bool
    {
        return self::failoverChainContainsRedisFor(config('cache.stores.'.$storeName));
    }

    public static function failoverChainContainsRedisFor(mixed $store): bool
    {
        return self::$useRustFfi
            ? self::failoverChainContainsRedisForNative($store)
            : self::failoverChainContainsRedisForPhp($store);
    }

    public static function failoverChainContainsRedisForPhp(mixed $store): bool
    {
        if (! is_array($store) || ($store['driver'] ?? '') !== 'failover') {
            return false;
        }

        foreach ($store['stores'] ?? [] as $sub) {
            if (strtolower((string) $sub) === 'redis') {
                return true;
            }
        }

        return false;
    }

    /**
     * Sub-store names are cast to strings here; non-scalar entries or a non-array
     * `stores` value keep PHP's own conversion semantics.
     */
    public static function failoverChainContainsRedisForNative(mixed $store): bool
    {
        $ffi = self::searchFfi();
        $stores = is_array($store) ? ($store['stores'] ?? []) : [];
        if ($ffi === null || ! is_array($stores)) {
            return self::failoverChainContainsRedisForPhp($store);
        }

        $names = [];
        foreach ($stores as $sub) {
            if ($sub !== null && ! \is_scalar($sub)) {
                return self::failoverChainContainsRedisForPhp($store);
            }
            $names[] = (string) $sub;
        }

        $count = \count($names);
        $lens = null;
        if ($count > 0) {
            $lens = $ffi->new('size_t['.$count.']');
            foreach ($names as $i => $name) {
                $lens[$i] = \strlen($name);
            }
        }

        $driver = is_array($store) ? ($store['driver'] ?? null) : null;
        $driverString = \is_string($driver) ? $driver : '';
        $packed = implode('', $names);
        // @phpstan-ignore method.notFound
        $result = $ffi->nntmux_redis_failover_contains_redis(
            is_array($store) ? 1 : 0,
            $driverString,
            \strlen($driverString),
            \is_string($driver) ? 1 : 0,
            $packed,
            \strlen($packed),
            $lens,
            $count,
        );

        return \is_int($result) && $result >= 0 ? $result === 1 : self::failoverChainContainsRedisForPhp($store);
    }

    private function degradeAwayFromRedis(): void
    {
        $cacheDefault = (string) config('cache.default', '');
        if ($cacheDefault === 'redis'
            || $cacheDefault === 'failover_redis_file'
            || $this->failoverChainContainsRedis($cacheDefault)) {
            Config::set('cache.default', 'file');
        }

        if (config('session.driver') === 'redis') {
            Config::set('session.driver', 'file');
        }
    }

    /**
     * @return array{remote: string}|null PHP stream remote URL (tcp:// or ssl://)
     */
    private function redisTcpTarget(): ?array
    {
        $remote = self::redisTcpTargetFor(config('database.redis.default'));

        return $remote === null ? null : ['remote' => $remote];
    }

    /**
     * Stream remote for the default Redis connection config, or null when it has no TCP target.
     */
    public static function redisTcpTargetFor(mixed $cfg): ?string
    {
        return self::$useRustFfi ? self::redisTcpTargetForNative($cfg) : self::redisTcpTargetForPhp($cfg);
    }

    public static function redisTcpTargetForPhp(mixed $cfg): ?string
    {
        if (! is_array($cfg)) {
            return null;
        }

        if (! empty($cfg['url'])) {
            $parts = parse_url((string) $cfg['url']);
            if ($parts === false) {
                return null;
            }
            $host = $parts['host'] ?? null;
            if ($host === null || $host === '') {
                return null;
            }
            $port = (int) ($parts['port'] ?? 6379);
            $scheme = strtolower((string) ($parts['scheme'] ?? 'tcp'));

            return self::streamRemotePhp($scheme, $host, $port);
        }

        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        if ($host === '' || str_starts_with($host, '/')) {
            return null;
        }

        $port = (int) ($cfg['port'] ?? 6379);

        return self::streamRemotePhp('tcp', $host, $port);
    }

    /**
     * `parse_url()` and the `host`/`port` casts stay in PHP; Rust applies the defaults,
     * host checks, scheme lowercasing and remote formatting.
     */
    public static function redisTcpTargetForNative(mixed $cfg): ?string
    {
        $ffi = self::searchFfi();
        if ($ffi === null || ! is_array($cfg)) {
            return self::redisTcpTargetForPhp($cfg);
        }

        if (! empty($cfg['url'])) {
            if (! \is_scalar($cfg['url'])) {
                return self::redisTcpTargetForPhp($cfg);
            }
            $parts = parse_url((string) $cfg['url']);
            if ($parts === false) {
                return null;
            }
            $useUrl = 1;
            $host = $parts['host'] ?? null;
            $port = $parts['port'] ?? null;
            $scheme = $parts['scheme'] ?? null;
        } else {
            $useUrl = 0;
            $host = $cfg['host'] ?? null;
            $port = $cfg['port'] ?? null;
            $scheme = null;
            if (($host !== null && ! \is_scalar($host)) || ($port !== null && ! \is_scalar($port))) {
                return self::redisTcpTargetForPhp($cfg);
            }
        }

        $hostString = $host === null ? '' : (string) $host;
        $schemeString = $scheme ?? '';
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($hostString) + 48, $cap);
        if ($scratch === null) {
            return self::redisTcpTargetForPhp($cfg);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_redis_tcp_target(
            $useUrl,
            $hostString,
            \strlen($hostString),
            $host === null ? 0 : 1,
            $port === null ? 0 : (int) $port,
            $port === null ? 0 : 1,
            $schemeString,
            \strlen($schemeString),
            $scheme === null ? 0 : 1,
            $scratch,
            $cap,
        );
        if (! \is_int($written) || $written < 0) {
            return self::redisTcpTargetForPhp($cfg);
        }

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    public static function streamRemote(string $scheme, string $host, int $port): string
    {
        return self::$useRustFfi ? self::streamRemoteNative($scheme, $host, $port) : self::streamRemotePhp($scheme, $host, $port);
    }

    public static function streamRemotePhp(string $scheme, string $host, int $port): string
    {
        if ($scheme === 'tls' || $scheme === 'ssl' || $scheme === 'rediss') {
            return 'ssl://'.$host.':'.$port;
        }

        $wrapped = str_contains($host, ':') && ! str_starts_with($host, '[')
            ? '['.$host.']'
            : $host;

        return 'tcp://'.$wrapped.':'.$port;
    }

    public static function streamRemoteNative(string $scheme, string $host, int $port): string
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, \strlen($host) + 32, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::streamRemotePhp($scheme, $host, $port);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_redis_stream_remote($scheme, \strlen($scheme), $host, \strlen($host), $port, $scratch, $cap);

        return \is_int($written) && $written > 0 ? FFI::string($scratch, $written) : self::streamRemotePhp($scheme, $host, $port);
    }

    private function tcpReachable(string $remote, float $timeoutSeconds): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );

        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }

        return false;
    }
}
