<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Counts search-engine query failures within the current request or job.
 *
 * Drivers swallow query errors and return empty results so pages keep
 * rendering; this lets callers that cache results (the API row cache) tell a
 * genuine empty result from a failed query. Bound as a scoped singleton.
 */
final class SearchFailureTracker
{
    /**
     * Rust candidates for the counter helpers. They stay opt-in: none beat PHP by
     * 1.5x in the paired benchmark (tests/Unit/RustSearchApiThrottleNoCacheFiltersParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_search_failure_next(int64_t count, int64_t *out);
        int32_t nntmux_search_failure_since(int64_t count, int64_t baseline);
        int32_t nntmux_search_failure_reached(int64_t count, int64_t threshold);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private int $failures = 0;

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

    public function record(): void
    {
        self::$useRustFfi ? $this->recordNative() : $this->recordPhp();
    }

    public function recordPhp(): void
    {
        $this->failures++;
    }

    public function recordNative(): void
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            $this->recordPhp();

            return;
        }

        $next = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_search_failure_next($this->failures, $next);
        if ($status !== 0) {
            $this->recordPhp();

            return;
        }

        $this->failures = (int) $next[0];
    }

    public function count(): int
    {
        return $this->failures;
    }

    /**
     * Whether a failure was recorded after `$baseline` was taken from count().
     */
    public function failedSince(int $baseline): bool
    {
        return self::$useRustFfi ? $this->failedSinceNative($baseline) : $this->failedSincePhp($baseline);
    }

    public function failedSincePhp(int $baseline): bool
    {
        return $this->failures !== $baseline;
    }

    public function failedSinceNative(int $baseline): bool
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return $this->failedSincePhp($baseline);
        }

        // @phpstan-ignore method.notFound
        $result = $ffi->nntmux_search_failure_since($this->failures, $baseline);

        return \is_int($result) && $result >= 0 ? $result === 1 : $this->failedSincePhp($baseline);
    }

    /**
     * Whether at least `$threshold` failures were recorded; a non-positive threshold never trips.
     */
    public function reachedThreshold(int $threshold): bool
    {
        return self::$useRustFfi ? $this->reachedThresholdNative($threshold) : $this->reachedThresholdPhp($threshold);
    }

    public function reachedThresholdPhp(int $threshold): bool
    {
        return $threshold > 0 && $this->failures >= $threshold;
    }

    public function reachedThresholdNative(int $threshold): bool
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return $this->reachedThresholdPhp($threshold);
        }

        // @phpstan-ignore method.notFound
        $result = $ffi->nntmux_search_failure_reached($this->failures, $threshold);

        return \is_int($result) && $result >= 0 ? $result === 1 : $this->reachedThresholdPhp($threshold);
    }
}
