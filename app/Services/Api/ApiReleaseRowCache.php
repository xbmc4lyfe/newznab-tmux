<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Exceptions\SearchUnavailableException;
use App\Facades\Search;
use App\Services\ReleaseImageService;
use App\Services\Search\Support\SearchFailureTracker;
use App\Support\RustPipelineLibrary;
use Countable;
use FFI;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ApiReleaseRowCache
{
    private const CACHE_MISS = '__nntmux_api_release_row_cache_miss__';

    private const NULL_SENTINEL = ['__nntmux_api_release_row_cache_null' => true];

    /**
     * Rust stays opt-in for the Wave 45.5 string leaf summary: collecting the leaves in PHP is most of
     * the work (min 1.22/1.16/1.46/1.28x); see
     * tests/Unit/RustApiCapabilitiesCanonicalizerQueryCacheUsageW45ParityTest.php.
     */
    public const bool W455_RUST_FFI_DEFAULT = false;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?bool $useW455RustFfi = null;

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

                return $this->load($cacheKey, $apiVersion, $callback);
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

        return $this->load($cacheKey, $apiVersion, $callback);
    }

    /**
     * Run the search and cache its rows, unless a search-engine query failed
     * while it ran: then the rows are returned uncached, and an empty result
     * becomes the last known rows (bounded by the stale TTL) or a retryable 503.
     *
     * @param  callable(): mixed  $callback
     *
     * @throws SearchUnavailableException
     */
    private function load(string $cacheKey, string $apiVersion, callable $callback): mixed
    {
        $failures = app(SearchFailureTracker::class);
        $failuresBefore = $failures->count();

        $rows = $callback();

        if ($failures->count() === $failuresBefore) {
            $this->putCachedValue($cacheKey, $rows);

            return $rows;
        }

        if (! $this->isEmptyResult($rows)) {
            return $rows;
        }

        [$staleHit, $stale] = $this->getCachedValue($cacheKey.':stale');
        if ($staleHit) {
            $this->markRequest('stale_after_failure');

            return $stale;
        }

        throw new SearchUnavailableException($apiVersion);
    }

    private function isEmptyResult(mixed $rows): bool
    {
        return $rows === null
            || $rows === []
            || ($rows instanceof Countable && count($rows) === 0);
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

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function normalize(array $parameters): array
    {
        return self::$useRustFfi
            ? $this->normalizeNative($parameters)
            : $this->normalizePhp($parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function normalizePhp(array $parameters): array
    {
        ksort($parameters);
        foreach ($parameters as &$value) {
            if (is_array($value)) {
                $value = array_is_list($value) ? array_values(array_unique($value, SORT_REGULAR)) : $this->normalizePhp($value);
                if (array_is_list($value)) {
                    sort($value);
                }
            } elseif (is_string($value)) {
                $value = trim($value);
            }
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function normalizeNative(array $parameters): array
    {
        if ($parameters === []) {
            return [];
        }

        $encoded = json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! \is_string($encoded)) {
            return $this->normalizePhp($parameters);
        }

        $normalizedJson = $this->normalizeJsonNative($encoded);
        if ($normalizedJson === null) {
            return $this->normalizePhp($parameters);
        }

        $decoded = json_decode($normalizedJson, true);

        return \is_array($decoded) ? $decoded : $this->normalizePhp($parameters);
    }

    public function normalizeJsonNative(string $json): ?string
    {
        if ($json === '') {
            return null;
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return null;
        }

        $len = \strlen($json);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 2 + 32), $cap);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_release_cache_normalize_json($json, $len, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return null;
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_api_release_cache_normalize_json($json, $len, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
                return null;
            }
        }

        return $written <= 0 ? null : FFI::string($scratch, $written);
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    /** Pass null to restore {@see W455_RUST_FFI_DEFAULT}. */
    public static function useW455RustFfi(?bool $enable = true): void
    {
        self::$useW455RustFfi = $enable;
    }

    public static function usesW455RustFfi(): bool
    {
        return self::$useW455RustFfi ?? self::W455_RUST_FFI_DEFAULT;
    }

    public static function resetW455RustFfi(): void
    {
        self::$useW455RustFfi = null;
    }

    /**
     * Summarizes the top-level values {@see normalizePhp()} sees in each parameter set: `keys`, string
     * values (`trimmed` when trim() changes them, `blank` after trimming, `string_bytes`,
     * `max_string_bytes` and `distinct_strings` after trimming), `nested` arrays it recurses into or sorts
     * and `other` values it keeps as-is, plus `max_keys`. Non-array sets are invalid.
     *
     * @param  array<array-key, mixed>  $parameterSets
     * @return array{sets: int, invalid: int, keys: int, strings: int, trimmed: int, blank: int, string_bytes: int, max_string_bytes: int, nested: int, other: int, max_keys: int, distinct_strings: int}
     */
    public function summarizeStringLeafBatch(array $parameterSets): array
    {
        return self::usesW455RustFfi() ? $this->summarizeStringLeafBatchNative($parameterSets) : $this->summarizeStringLeafBatchPhp($parameterSets);
    }

    /**
     * @param  array<array-key, mixed>  $parameterSets
     * @return array{sets: int, invalid: int, keys: int, strings: int, trimmed: int, blank: int, string_bytes: int, max_string_bytes: int, nested: int, other: int, max_keys: int, distinct_strings: int}
     */
    public function summarizeStringLeafBatchPhp(array $parameterSets): array
    {
        $summary = ['sets' => \count($parameterSets), 'invalid' => 0, 'keys' => 0, 'strings' => 0, 'trimmed' => 0, 'blank' => 0, 'string_bytes' => 0, 'max_string_bytes' => 0, 'nested' => 0, 'other' => 0, 'max_keys' => 0, 'distinct_strings' => 0];
        $distinct = [];
        foreach ($parameterSets as $parameters) {
            if (! \is_array($parameters)) {
                $summary['invalid']++;

                continue;
            }
            $summary['keys'] += \count($parameters);
            $summary['max_keys'] = max($summary['max_keys'], \count($parameters));
            foreach ($parameters as $value) {
                if (\is_string($value)) {
                    $trimmed = trim($value);
                    $length = \strlen($trimmed);
                    $summary['strings']++;
                    $summary['trimmed'] += (int) ($length !== \strlen($value));
                    $summary['blank'] += (int) ($trimmed === '');
                    $summary['string_bytes'] += $length;
                    $summary['max_string_bytes'] = max($summary['max_string_bytes'], $length);
                    $distinct[$trimmed] = true;
                } elseif (\is_array($value)) {
                    $summary['nested']++;
                } else {
                    $summary['other']++;
                }
            }
        }
        $summary['distinct_strings'] = \count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $parameterSets
     * @return array{sets: int, invalid: int, keys: int, strings: int, trimmed: int, blank: int, string_bytes: int, max_string_bytes: int, nested: int, other: int, max_keys: int, distinct_strings: int}
     */
    public function summarizeStringLeafBatchNative(array $parameterSets): array
    {
        $summary = ['sets' => \count($parameterSets), 'invalid' => 0, 'keys' => 0, 'strings' => 0, 'trimmed' => 0, 'blank' => 0, 'string_bytes' => 0, 'max_string_bytes' => 0, 'nested' => 0, 'other' => 0, 'max_keys' => 0, 'distinct_strings' => 0];
        $strings = [];
        foreach ($parameterSets as $parameters) {
            if (! \is_array($parameters)) {
                $summary['invalid']++;

                continue;
            }
            $summary['keys'] += \count($parameters);
            $summary['max_keys'] = max($summary['max_keys'], \count($parameters));
            foreach ($parameters as $value) {
                if (\is_string($value)) {
                    $strings[] = $value;
                } elseif (\is_array($value)) {
                    $summary['nested']++;
                } else {
                    $summary['other']++;
                }
            }
        }
        $fields = ApiInputCanonicalizer::evaluateW455Native(4, $strings, 6);
        if ($fields === null) {
            return $this->summarizeStringLeafBatchPhp($parameterSets);
        }
        [$summary['strings'], $summary['trimmed'], $summary['blank'], $summary['string_bytes'], $summary['max_string_bytes'], $summary['distinct_strings']] = $fields;

        return $summary;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', ReleaseImageService::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
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
