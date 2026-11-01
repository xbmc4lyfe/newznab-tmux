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

    // Rust is the default only while the paired W54.6 benchmark clears 1.5x on all four metrics.
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        int32_t nntmux_w546_article_coverage_batch(const char *ints, size_t ints_len, size_t count, int64_t part_size, int64_t *out, size_t out_cap);
        C;

    public const int W54_COVERAGE_HEADER = 14;

    private const int W54_MAX_ITEMS = 1_048_576;

    private const int W54_VALUE_CAP = 1_099_511_627_775;

    private const int W54_TOTAL_CAP = 4_611_686_018_427_387_904;

    private const int W54_COUNT_CAP = 1_048_575;

    private const int W54_ARTICLE_CAP = 16_777_215;

    private const int W54_DEFAULT_PART = 716_800;

    private const int W54_PART_CAP = 1 << 30;

    /**
     * Article coverage of mapped files from their source indexes, real sizes and posted article counts.
     *
     * Real sizes clamp to [0, 2^40) and article counts to [0, 2^20); missing values are 0. The part size
     * is decoded bytes per article (null, 0 or less uses 716,800; capped at 2^30). The first file of a
     * source index counts; later ones are flagged duplicates and left out of the totals. Layout: `[0]`
     * count, `[1]` part size, `[2]` distinct source indexes, `[3]` duplicates, `[4]` real bytes, `[5]`
     * expected, `[6]` posted and `[7]` missing articles (capped at 2^62), `[8]` complete, `[9]` short and
     * `[10]` unknown-size files, `[11]` first worst coverage (-1 when none), `[12]` mean coverage ppm of
     * sized files, `[13]` FNV-1a digest; then two words per file: `expected | missing << 24 | flags << 48`
     * (both capped at 2^24-1; flag bit 0 duplicate, bit 1 negative source index, bit 2 more posted than
     * expected, bit 3 unknown size, bit 4 complete) and `coverage_ppm | tail << 20` (bytes in the last
     * article).
     *
     * @param  array<array-key, int>  $sourceIndexes
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $articleCounts
     * @return list<int>
     */
    public static function articleCoverageBatch(array $sourceIndexes, array $realSizes, array $articleCounts, ?int $articlePartSize = null): array
    {
        return (self::usesW54RustFfi() ? self::articleCoverageBatchNative($sourceIndexes, $realSizes, $articleCounts, $articlePartSize) : null)
            ?? self::articleCoverageBatchPhp($sourceIndexes, $realSizes, $articleCounts, $articlePartSize);
    }

    /**
     * @param  array<array-key, int>  $sourceIndexes
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $articleCounts
     * @return list<int>
     */
    public static function articleCoverageBatchPhp(array $sourceIndexes, array $realSizes, array $articleCounts, ?int $articlePartSize = null): array
    {
        $sourceIndexes = array_values($sourceIndexes);
        $realSizes = array_values($realSizes);
        $articleCounts = array_values($articleCounts);
        $count = \count($sourceIndexes);
        $part = $articlePartSize === null || $articlePartSize <= 0 ? self::W54_DEFAULT_PART : min($articlePartSize, self::W54_PART_CAP);
        $totalCap = self::W54_TOTAL_CAP;
        $articleCap = self::W54_ARTICLE_CAP;
        $out = array_fill(0, self::W54_COVERAGE_HEADER, 0);
        $out[0] = $count;
        $out[1] = $part;
        $seen = [];
        $worstAt = -1;
        $worst = 0;
        $ppmSum = $ppmCount = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $source = $sourceIndexes[$i];
            $real = min(max($realSizes[$i] ?? 0, 0), self::W54_VALUE_CAP);
            $posted = min(max($articleCounts[$i] ?? 0, 0), self::W54_COUNT_CAP);
            $duplicate = isset($seen[$source]);
            $seen[$source] = true;
            $expected = $real > 0 ? intdiv($real + $part - 1, $part) : 0;
            $missing = max($expected - $posted, 0);
            $ppm = $expected > 0 ? min(intdiv(min($posted, $expected) * 1_000_000, $expected), 1_000_000) : ($posted > 0 ? 1_000_000 : 0);
            $tail = $expected > 0 ? $real - ($expected - 1) * $part : 0;
            $complete = $expected > 0 && $missing === 0;
            $flags = ($duplicate ? 1 : 0) | ($source < 0 ? 2 : 0) | ($posted > $expected ? 4 : 0) | ($real === 0 ? 8 : 0) | ($complete ? 16 : 0);
            if ($duplicate) {
                $out[3]++;
            } else {
                $out[2]++;
                $out[4] = min($out[4] + $real, $totalCap);
                $out[5] = min($out[5] + $expected, $totalCap);
                $out[6] = min($out[6] + $posted, $totalCap);
                $out[7] = min($out[7] + $missing, $totalCap);
                if ($complete) {
                    $out[8]++;
                }
                if ($expected > 0 && $missing > 0) {
                    $out[9]++;
                }
                if ($real === 0) {
                    $out[10]++;
                }
                if ($expected > 0) {
                    $ppmSum += $ppm;
                    $ppmCount++;
                    if ($worstAt < 0 || $ppm < $worst) {
                        $worstAt = $i;
                        $worst = $ppm;
                    }
                }
            }
            $first = min($expected, $articleCap) | min($missing, $articleCap) << 24 | $flags << 48;
            $second = $ppm | $tail << 20;
            $digest = (($digest ^ (($first ^ ($first >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $digest = (($digest ^ (($second ^ ($second >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $first;
            $rows[] = $second;
        }
        $out[11] = $worstAt;
        if ($ppmCount > 0) {
            $out[12] = intdiv($ppmSum, $ppmCount);
        }
        $out[13] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust coverage, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $sourceIndexes
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $articleCounts
     * @return list<int>|null
     */
    public static function articleCoverageBatchNative(array $sourceIndexes, array $realSizes, array $articleCounts, ?int $articlePartSize = null): ?array
    {
        $count = \count($sourceIndexes);
        if ($count > self::W54_MAX_ITEMS) {
            return null;
        }
        $ints = pack('q*', ...self::w54Fit($sourceIndexes, $count), ...self::w54Fit($realSizes, $count), ...self::w54Fit($articleCounts, $count));
        $fields = self::W54_COVERAGE_HEADER + 2 * $count;
        $part = $articlePartSize ?? 0;

        // @phpstan-ignore method.notFound
        return self::w54Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w546_article_coverage_batch($ints, \strlen($ints), $count, $part, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 54.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $forced = self::w54RustFfiState();

        return \is_bool($forced) ? $forced : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 54.6 routing and binding, kept in function statics because readonly classes cannot declare
     * static properties. No `$op` returns the forced routing (null for the default); `'set'` replaces it
     * with `$val` and returns the previous value; `'binding'` returns the cached `additional_w54` binding
     * and an output buffer of at least `$val` values, or null.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w54', self::W54_FFI_CDEF) ?? false;
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

    /**
     * The batch's `$fields` output words from one Wave 54.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w54Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w54RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with `$default`.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w54Fit(array $values, int $count, int $default = 0): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, $default);
    }
}
