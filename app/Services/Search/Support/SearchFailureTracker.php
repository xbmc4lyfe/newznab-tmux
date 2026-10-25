<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Services\Search\SearchService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

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

    public const string W50_FFI_CDEF = 'int64_t nntmux_w505_search_failure_window(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

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

    /**
     * Evaluates many tracker snapshots against one threshold, clock and cooldown. Two words per snapshot,
     * aligned with $counts: `[failedSince($baseline) | reachedThreshold($threshold) << 1 | state (0 closed,
     * 1 open, 2 half_open) << 2 | allow_probe << 4 | trip_on_next_failure << 5, remaining_cooldown_seconds]`,
     * with the rules of failedSincePhp(), reachedThresholdPhp() and evaluateCircuitStateWindow().
     *
     * @param  list<int>  $counts
     * @param  list<int>  $baselines
     * @param  list<int>  $openedAtUnix
     * @return list<int>
     */
    public static function failureWindowBatch(array $counts, array $baselines, array $openedAtUnix, int $threshold, int $nowUnix, int $cooldownSeconds = 60): array
    {
        return self::usesW50RustFfi()
            ? self::failureWindowBatchNative($counts, $baselines, $openedAtUnix, $threshold, $nowUnix, $cooldownSeconds)
            : self::failureWindowBatchPhp($counts, $baselines, $openedAtUnix, $threshold, $nowUnix, $cooldownSeconds);
    }

    /**
     * @param  list<int>  $counts
     * @param  list<int>  $baselines
     * @param  list<int>  $openedAtUnix
     * @return list<int>
     */
    public static function failureWindowBatchPhp(array $counts, array $baselines, array $openedAtUnix, int $threshold, int $nowUnix, int $cooldownSeconds = 60): array
    {
        $clamped = max(1, $threshold);
        $cooldown = max(0, $cooldownSeconds);
        $out = [];
        foreach ($counts as $i => $count) {
            $flags = (int) ($count !== $baselines[$i]) | (int) ($threshold > 0 && $count >= $threshold) << 1;
            $failures = max(0, $count);
            if ($failures < $clamped) {
                array_push($out, $flags | (int) ($failures >= $clamped - 1) << 5, 0);

                continue;
            }
            $openedAt = $openedAtUnix[$i];
            $elapsed = match (true) {
                $openedAt > $nowUnix => 0,
                $openedAt < 0 && $nowUnix > PHP_INT_MAX + $openedAt => PHP_INT_MAX,
                default => $nowUnix - $openedAt,
            };
            $remaining = max(0, $cooldown - $elapsed);
            array_push($out, $flags | ($remaining === 0 ? 0b111000 : 0b100), $remaining);
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or the columns differ in length.
     *
     * @param  list<int>  $counts
     * @param  list<int>  $baselines
     * @param  list<int>  $openedAtUnix
     * @return list<int>
     */
    public static function failureWindowBatchNative(array $counts, array $baselines, array $openedAtUnix, int $threshold, int $nowUnix, int $cooldownSeconds = 60): array
    {
        $count = \count($counts);
        if ($count === 0 || \count($baselines) !== $count || \count($openedAtUnix) !== $count) {
            return self::failureWindowBatchPhp($counts, $baselines, $openedAtUnix, $threshold, $nowUnix, $cooldownSeconds);
        }

        return self::w50Words($count, '', pack('P*', $threshold, $nowUnix, $cooldownSeconds, ...$counts, ...$baselines, ...$openedAtUnix), 2 * $count)
            ?? self::failureWindowBatchPhp($counts, $baselines, $openedAtUnix, $threshold, $nowUnix, $cooldownSeconds);
    }

    /**
     * Exactly $words output words from the Wave 50.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w50Words(int $count, string $data, string $ints, int $words): ?array
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
            if (self::$w50Out === null || $words > self::$w50OutCap) {
                $cap = max(1024, $words);
                self::$w50Out = $ffi->new('int64_t['.$cap.']');
                self::$w50OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w505_search_failure_window($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w50Out, self::$w50OutCap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w50Out, $words * 8));

            return \is_array($values) ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
