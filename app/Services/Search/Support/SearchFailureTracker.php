<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Services\Search\SearchService;
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

    /**
     * Rust stays opt-in for the Wave 40.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustManticoreElasticSearchServiceCircuitW40ParityTest.php.
     */
    public const bool W405_RUST_FFI_DEFAULT = false;

    private static ?bool $useW405RustFfi = null;

    public static function useW405RustFfi(?bool $use = true): void
    {
        self::$useW405RustFfi = $use;
    }

    public static function usesW405RustFfi(): bool
    {
        return self::$useW405RustFfi ?? self::W405_RUST_FFI_DEFAULT;
    }

    public static function resetW405RustFfi(): void
    {
        self::$useW405RustFfi = null;
    }

    /**
     * Evaluates the circuit state window. Failures and cooldown clamp at 0 and the threshold at 1.
     * Below the threshold the circuit is `closed`; otherwise it stays `open` until the cooldown
     * since `$openedAtUnix` has elapsed (a future open time counts as no elapsed time, and elapsed
     * time saturates at PHP_INT_MAX) and is then `half_open`, allowing one probe. The next failure
     * trips the circuit when a closed circuit is one failure short of the threshold, or when it is
     * half-open.
     *
     * @return array{state: 'closed'|'open'|'half_open', allow_probe: bool, remaining_cooldown_seconds: int, trip_on_next_failure: bool}
     */
    public static function evaluateCircuitStateWindow(int $failureCount, int $failureThreshold, int $openedAtUnix, int $nowUnix, int $cooldownSeconds = 60): array
    {
        return self::usesW405RustFfi()
            ? self::evaluateCircuitStateWindowNative($failureCount, $failureThreshold, $openedAtUnix, $nowUnix, $cooldownSeconds)
            : self::evaluateCircuitStateWindowPhp($failureCount, $failureThreshold, $openedAtUnix, $nowUnix, $cooldownSeconds);
    }

    /**
     * @return array{state: 'closed'|'open'|'half_open', allow_probe: bool, remaining_cooldown_seconds: int, trip_on_next_failure: bool}
     */
    private static function evaluateCircuitStateWindowNative(int $failureCount, int $failureThreshold, int $openedAtUnix, int $nowUnix, int $cooldownSeconds): array
    {
        $fields = SearchService::evaluateW405Native(4, [
            (string) $failureCount,
            (string) $failureThreshold,
            (string) $openedAtUnix,
            (string) $nowUnix,
            (string) $cooldownSeconds,
        ]);
        if ($fields === null || \count($fields) !== 4) {
            return self::evaluateCircuitStateWindowPhp($failureCount, $failureThreshold, $openedAtUnix, $nowUnix, $cooldownSeconds);
        }
        $state = match ($fields[0]) {
            'closed' => 'closed',
            'open' => 'open',
            'half_open' => 'half_open',
            default => null,
        };
        if ($state === null) {
            return self::evaluateCircuitStateWindowPhp($failureCount, $failureThreshold, $openedAtUnix, $nowUnix, $cooldownSeconds);
        }

        return [
            'state' => $state,
            'allow_probe' => $fields[1] === '1',
            'remaining_cooldown_seconds' => (int) $fields[2],
            'trip_on_next_failure' => $fields[3] === '1',
        ];
    }

    /**
     * @return array{state: 'closed'|'open'|'half_open', allow_probe: bool, remaining_cooldown_seconds: int, trip_on_next_failure: bool}
     */
    private static function evaluateCircuitStateWindowPhp(int $failureCount, int $failureThreshold, int $openedAtUnix, int $nowUnix, int $cooldownSeconds): array
    {
        $failures = max(0, $failureCount);
        $threshold = max(1, $failureThreshold);
        if ($failures < $threshold) {
            return [
                'state' => 'closed',
                'allow_probe' => false,
                'remaining_cooldown_seconds' => 0,
                'trip_on_next_failure' => $failures >= $threshold - 1,
            ];
        }

        $elapsed = match (true) {
            $openedAtUnix > $nowUnix => 0,
            $openedAtUnix < 0 && $nowUnix > PHP_INT_MAX + $openedAtUnix => PHP_INT_MAX,
            default => $nowUnix - $openedAtUnix,
        };
        $remaining = max(0, max(0, $cooldownSeconds) - $elapsed);
        $halfOpen = $remaining === 0;

        return [
            'state' => $halfOpen ? 'half_open' : 'open',
            'allow_probe' => $halfOpen,
            'remaining_cooldown_seconds' => $remaining,
            'trip_on_next_failure' => $halfOpen,
        ];
    }
}
