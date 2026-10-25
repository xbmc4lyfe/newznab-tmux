<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * What the release's PAR2 index told us about its obfuscated files.
 */
final readonly class MediaSourcePlan
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_msp_real_size_of(const char *packed, size_t len, int64_t source_index, int64_t *out_size);
        C;

    /**
     * @param  array<int, MappedNzbFile>  $mappedFiles  keyed by NZB source index
     * @param  int|null  $articlePartSize  decoded bytes per article, when an article header showed it
     */
    public function __construct(
        public array $mappedFiles = [],
        public ?int $articlePartSize = null,
    ) {}

    // PHP remains the production default until benchmarks qualify Rust.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }

    public static function usesRustFfi(): bool
    {
        return self::rustEnabled();
    }

    /**
     * Cached `additional` domain handle; `$reset` replaces it with `$override` (null re-resolves).
     */
    public static function additionalFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    public function realSizeOf(int $sourceIndex): ?int
    {
        return self::rustEnabled() ? $this->realSizeOfNative($sourceIndex) : $this->realSizeOfPhp($sourceIndex);
    }

    public function realSizeOfPhp(int $sourceIndex): ?int
    {
        return $this->mappedFiles[$sourceIndex]->file->size ?? null;
    }

    public function realSizeOfNative(int $sourceIndex): ?int
    {
        $ffi = self::additionalFfi();
        if ($ffi === null) {
            return $this->realSizeOfPhp($sourceIndex);
        }

        $pairs = [];
        foreach ($this->mappedFiles as $index => $mapped) {
            if (! is_int($index) || ! $mapped instanceof MappedNzbFile) {
                return $this->realSizeOfPhp($sourceIndex);
            }
            $pairs[] = $index;
            $pairs[] = $mapped->file->size;
        }
        if ($pairs === []) {
            return null;
        }

        static $out = null;
        static $owner = null;
        try {
            if ($owner !== $ffi || $out === null) {
                $out = $ffi->new('int64_t[1]');
                $owner = $ffi;
            }
            // Little-endian [index][size] pairs.
            $packed = pack('P*', ...$pairs);
            // @phpstan-ignore method.notFound
            $found = $ffi->nntmux_msp_real_size_of($packed, strlen($packed), $sourceIndex, $out);
        } catch (Throwable) {
            return $this->realSizeOfPhp($sourceIndex);
        }

        return match ($found) {
            1 => (int) $out[0],
            0 => null,
            default => $this->realSizeOfPhp($sourceIndex),
        };
    }

    // Rust is the default: the paired W48.6 benchmark cleared 1.5x on all four metrics (7.70x/4.95x common, 8.34x/4.98x uncommon mean/p99).
    public const bool W48_SLICE_PLAN_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        int32_t nntmux_w48_media_slice_plan(const char *ints, size_t ints_len, size_t count, int64_t budget, int64_t probe, int64_t *out, size_t out_count);
        C;

    public const int W48_SLICE_HEADER = 12;

    public const int W48_SLICE_WIDTH = 5;

    private const int W48_VALUE_CAP = 1_099_511_627_775;

    private const int W48_TOTAL_CAP = 4_611_686_018_427_387_904;

    private const int W48_DEFAULT_PART = 716_800;

    private const int W48_PART_CAP = 1 << 30;

    private const int W48_PROBE_CAP = 1 << 34;

    private const int W48_MAX_ITEMS = 1_048_576;

    /** Strategy base values: complete, head, mid, tail. */
    private const array W48_SLICE_BASE = [3_000_000, 2_000_000, 1_500_000, 1_000_000];

    public static function useW48SlicePlanRustFfi(?bool $enabled): void
    {
        self::w48Toggle(true, $enabled);
    }

    public static function usesW48SlicePlanRustFfi(): bool
    {
        return self::w48Toggle() ?? self::W48_SLICE_PLAN_RUST_FFI_DEFAULT;
    }

    public static function resetW48SlicePlanRustFfi(): void
    {
        self::w48Toggle(true);
        self::w48Binding(0, true);
    }

    /**
     * Plan which slice of each partially downloaded media file to probe under one byte budget.
     * Strategies: 0 complete file (known size), 1 head (probe bytes rounded up to an article),
     * 2 mid and 3 tail (known sizes above the probe, article-aligned), 4 none. A window costs
     * the bytes it needs beyond the downloaded head; its value is `base + min(bytes >> 20,
     * 500000) - min(cost >> 14, 1400000)`. Files are planned by descending best value (then
     * index) and each takes its highest-value window (lowest strategy on ties) that fits the
     * remaining budget. Sizes are clamped to [0, 2^40); unknown real sizes are 0 or less, a
     * missing article size is 700 KiB, and the probe is clamped to [1, 2^34].
     *
     * Layout: `[0]` count, `[1..5]` chosen complete/head/mid/tail/none, `[6]` cost, `[7]` budget
     * left, `[8]` planned bytes, `[9]` unknown sizes, `[10]` FNV-1a digest, `[11]` files whose
     * best window did not fit; then per file `strategy | articles << 8`, start, end, cost and
     * value; then the planning order. Missing values are 0.
     *
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $availableBytes
     * @param  array<array-key, int>  $partSizes
     * @return list<int>
     */
    public static function planSliceBatch(array $realSizes, array $availableBytes, array $partSizes, int $byteBudget, int $probeBytes): array
    {
        return (self::usesW48SlicePlanRustFfi() ? self::planSliceBatchNative($realSizes, $availableBytes, $partSizes, $byteBudget, $probeBytes) : null)
            ?? self::planSliceBatchPhp($realSizes, $availableBytes, $partSizes, $byteBudget, $probeBytes);
    }

    /**
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $availableBytes
     * @param  array<array-key, int>  $partSizes
     * @return list<int>
     */
    public static function planSliceBatchPhp(array $realSizes, array $availableBytes, array $partSizes, int $byteBudget, int $probeBytes): array
    {
        $realSizes = array_values($realSizes);
        $availableBytes = array_values($availableBytes);
        $partSizes = array_values($partSizes);
        $count = \count($realSizes);
        $probe = min(max($probeBytes, 1), self::W48_PROBE_CAP);
        $out = array_fill(0, self::W48_SLICE_HEADER + self::W48_SLICE_WIDTH * $count, 0);
        $out[0] = $count;
        $candidates = $best = $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $real = min(max($realSizes[$i], 0), self::W48_VALUE_CAP);
            $part = ($partSizes[$i] ?? 0) > 0 ? min($partSizes[$i], self::W48_PART_CAP) : self::W48_DEFAULT_PART;
            $available = min(max($availableBytes[$i] ?? 0, 0), self::W48_VALUE_CAP);
            $out[9] += (int) ($real === 0);
            $up = ($probe + $part - 1) - ($probe + $part - 1) % $part;
            $headEnd = $real > 0 ? min($up, $real) : $up;
            $options = [null, self::w48Window(1, 0, $headEnd, max(0, $headEnd - $available)), null, null];
            if ($real > 0) {
                $options[0] = self::w48Window(0, 0, $real, max(0, $real - $available));
                if ($real > $probe) {
                    $center = intdiv($real, 2) - intdiv($probe, 2);
                    $start = $center - $center % $part;
                    $end = min($start + $up, $real);
                    $options[2] = self::w48Window(2, $start, $end, $end - $start - max(0, min($available, $end) - $start));
                    $tail = $real - $probe;
                    $start = $tail - $tail % $part;
                    $options[3] = self::w48Window(3, $start, $real, $real - $start - max(0, min($available, $real) - $start));
                }
            }
            $value = null;
            foreach ($options as $option) {
                if ($option !== null && ($value === null || $option[3] > $value)) {
                    $value = $option[3];
                }
            }
            $best[] = $value ?? 0;
            $candidates[] = $options;
            $parts[] = $part;
        }
        $order = self::w48Rank($best);
        $remaining = min(max($byteBudget, 0), self::W48_TOTAL_CAP);
        $spent = $planned = 0;
        $digest = 2_166_136_261;
        foreach ($order as $index) {
            $chosen = null;
            $strategy = 4;
            foreach ($candidates[$index] as $option => $window) {
                if ($window !== null && $window[2] <= $remaining && ($chosen === null || $window[3] > $chosen[3])) {
                    $chosen = $window;
                    $strategy = $option;
                }
            }
            $row = self::W48_SLICE_HEADER + self::W48_SLICE_WIDTH * $index;
            $segments = 0;
            if ($chosen === null) {
                $out[11]++;
            } else {
                [$start, $end, $cost, $value] = $chosen;
                $remaining -= $cost;
                $spent = min($spent + $cost, self::W48_TOTAL_CAP);
                $planned = min($planned + $end - $start, self::W48_TOTAL_CAP);
                $part = $parts[$index];
                $segments = intdiv($end + $part - 1, $part) - intdiv($start, $part);
                $out[11] += (int) ($value !== $best[$index]);
                $out[$row + 1] = $start;
                $out[$row + 2] = $end;
                $out[$row + 3] = $cost;
                $out[$row + 4] = $value;
            }
            $out[1 + $strategy]++;
            $out[$row] = $strategy | $segments << 8;
            $digest = (($digest ^ ($strategy | min($segments, 4095) << 4)) * 16_777_619) & 0xFFFFFFFF;
        }
        $out[6] = $spent;
        $out[7] = $remaining;
        $out[8] = $planned;
        $out[10] = $digest;

        return [...$out, ...$order];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $availableBytes
     * @param  array<array-key, int>  $partSizes
     * @return list<int>|null
     */
    public static function planSliceBatchNative(array $realSizes, array $availableBytes, array $partSizes, int $byteBudget, int $probeBytes): ?array
    {
        $count = \count($realSizes);
        $fields = self::W48_SLICE_HEADER + (self::W48_SLICE_WIDTH + 1) * $count;
        $binding = $count <= self::W48_MAX_ITEMS ? self::w48Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $ints = pack('q*', ...array_values($realSizes), ...self::w48Fit($availableBytes, $count), ...self::w48Fit($partSizes, $count));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w48_media_slice_plan($ints, \strlen($ints), $count, $byteBudget, $probeBytes, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} start, end, cost and value */
    private static function w48Window(int $strategy, int $start, int $end, int $cost): array
    {
        return [$start, $end, $cost, self::W48_SLICE_BASE[$strategy] + min(($end - $start) >> 20, 500_000) - min($cost >> 14, 1_400_000)];
    }

    /**
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w48Fit(array $values, int $count): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, 0);
    }

    /**
     * Indices by descending score, then ascending index.
     *
     * @param  list<int>  $scores
     * @return list<int>
     */
    private static function w48Rank(array $scores): array
    {
        if ($scores === []) {
            return [];
        }
        $indexes = array_keys($scores);
        array_multisort($scores, SORT_DESC, SORT_NUMERIC, $indexes, SORT_ASC, SORT_NUMERIC);

        return $indexes;
    }

    private static function w48Toggle(bool $set = false, ?bool $enabled = null): ?bool
    {
        /** @var bool|null $state */
        static $state = null;
        if ($set) {
            $state = $enabled;
        }

        return $state;
    }

    /**
     * Cached `additional_w48` binding and an output buffer of at least `$fields` values; `$reset` drops both.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w48Binding(int $fields, bool $reset = false): ?array
    {
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($reset) {
            $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w48', self::W48_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }
}
