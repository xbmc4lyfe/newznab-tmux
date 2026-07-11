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

    private const BASE_TTL_SECONDS = 600;

    private const MAX_TTL_JITTER_SECONDS = 60;

    private const LOCK_TTL_SECONDS = 10;

    private const WAIT_ATTEMPTS = 30;

    private const WAIT_MICROSECONDS = 100_000;

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
            $this->logCacheEvent('hit', $apiVersion, $scope);

            return $cached;
        }

        $lockKey = $cacheKey.':lock';
        if (Cache::add($lockKey, true, self::LOCK_TTL_SECONDS)) {
            try {
                [$hit, $cached] = $this->getCachedValue($cacheKey);
                if ($hit) {
                    $this->logCacheEvent('hit_after_lock', $apiVersion, $scope);

                    return $cached;
                }

                $this->logCacheEvent('miss_lock_owner', $apiVersion, $scope);
                $rows = $callback();
                $this->putCachedValue($cacheKey, $rows);

                return $rows;
            } finally {
                Cache::forget($lockKey);
            }
        }

        $this->logCacheEvent('lock_wait', $apiVersion, $scope);
        for ($attempt = 0; $attempt < self::WAIT_ATTEMPTS; $attempt++) {
            usleep(self::WAIT_MICROSECONDS);

            [$hit, $cached] = $this->getCachedValue($cacheKey);
            if ($hit) {
                $this->logCacheEvent('hit_after_wait', $apiVersion, $scope);

                return $cached;
            }
        }

        $this->logCacheEvent('miss_after_wait', $apiVersion, $scope);
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
            'parameters' => $parameters,
        ]));
    }

    private function ttlSeconds(): int
    {
        return self::BASE_TTL_SECONDS + mt_rand(0, self::MAX_TTL_JITTER_SECONDS);
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
        Cache::put(
            $cacheKey,
            $value === null ? self::NULL_SENTINEL : $value,
            $this->ttlSeconds()
        );
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
}
