<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Facades\Search;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApiReleaseRowCache
{
    private const CACHE_MISS = '__nntmux_api_release_row_cache_miss__';

    private const NULL_SENTINEL = ['__nntmux_api_release_row_cache_null' => true];

    /**
     * Cache release rows only. User-specific response fields should be built after this returns.
     *
     * @param  array<string, mixed>  $parameters
     * @param  callable(): mixed  $callback
     */
    public function remember(string $apiVersion, string $scope, array $parameters, callable $callback): mixed
    {
        $cacheKey = $this->cacheKey($apiVersion, $scope, $parameters);

        [$hit, $cached] = $this->getCachedValue($cacheKey);
        if ($hit) {
            $this->markRequest('hit');
            $this->logCacheEvent('hit', $apiVersion, $scope);

            return $cached;
        }

        $lock = Cache::lock($cacheKey.':lock', max(1, (int) config('nntmux.api.release_cache_lock_ttl', 15)));
        if ($lock->get()) {
            try {
                [$hit, $cached] = $this->getCachedValue($cacheKey);
                if ($hit) {
                    $this->markRequest('hit_after_lock');
                    $this->logCacheEvent('hit_after_lock', $apiVersion, $scope);

                    return $cached;
                }

                $this->logCacheEvent('miss_lock_owner', $apiVersion, $scope);
                $this->markRequest('miss');
                $rows = $callback();
                $this->putCachedValue($cacheKey, $rows);

                return $rows;
            } finally {
                $lock->release();
            }
        }

        [$staleHit, $stale] = $this->getCachedValue($cacheKey.':stale');
        if ($staleHit) {
            $this->markRequest('stale');
            $this->logCacheEvent('stale_while_refresh', $apiVersion, $scope);

            return $stale;
        }

        $this->logCacheEvent('miss_lock_contended', $apiVersion, $scope);
        $this->markRequest('miss_contended');
        $rows = $callback();
        $this->putCachedValue($cacheKey, $rows);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function cacheKey(string $apiVersion, string $scope, array $parameters): string
    {
        return 'api_release_rows:'.$apiVersion.':'.$scope.':'.md5(serialize([
            'driver' => Search::getCurrentDriver(),
            'release_version' => Cache::get('releases:cache_version', 1),
            'parameters' => $this->normalize($parameters),
        ]));
    }

    private function ttlSeconds(): int
    {
        $ttl = max(1, (int) config('nntmux.api.release_cache_ttl', 600));
        $jitter = max(0, (int) config('nntmux.api.release_cache_jitter', 60));

        return $ttl + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function getCachedValue(string $cacheKey): array
    {
        $cached = Cache::get($cacheKey, self::CACHE_MISS);
        if ($cached === self::CACHE_MISS) {
            return [false, null];
        }

        if ($cached === self::NULL_SENTINEL) {
            return [true, null];
        }

        return [true, $cached];
    }

    private function putCachedValue(string $cacheKey, mixed $value): void
    {
        $cached = $value === null ? self::NULL_SENTINEL : $value;
        $staleTtl = max($this->ttlSeconds(), (int) config('nntmux.api.release_cache_stale_ttl', 900));
        Cache::put(
            $cacheKey,
            $cached,
            $this->ttlSeconds()
        );
        Cache::put($cacheKey.':stale', $cached, $staleTtl);
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function normalize(array $parameters): array
    {
        ksort($parameters);
        foreach ($parameters as &$value) {
            if (is_array($value)) {
                $value = array_is_list($value) ? array_values(array_unique($value, SORT_REGULAR)) : $this->normalize($value);
                if (array_is_list($value)) {
                    sort($value);
                }
            } elseif (is_string($value)) {
                $value = trim($value);
            }
        }

        return $parameters;
    }

    private function logCacheEvent(string $event, string $apiVersion, string $scope): void
    {
        if (! config('app.debug')) {
            return;
        }

        Log::debug('API release row cache '.$event, [
            'version' => $apiVersion,
            'scope' => $scope,
        ]);
    }

    private function markRequest(string $status): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        request()->attributes->set('nntmux.api_release_cache', $status);
    }
}
