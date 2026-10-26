<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Network bytes one release may still spend looking for preview sources.
 */
final class MediaByteBudget
{
    public const bool W45_RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_budget_eval(uint32_t op, int64_t limit, int64_t spent, int64_t bytes, int64_t *out);
        double nntmux_budget_utilization(int64_t limit, int64_t spent, int32_t *status);
        int32_t nntmux_w45_budget_eval(uint32_t op, int64_t limit, int64_t spent, int64_t bytes, int64_t *out);
        double nntmux_w45_budget_utilization(int64_t limit, int64_t spent, int32_t *status);
        C;

    private const int OP_ALLOWS = 0;

    private const int OP_SPEND = 1;

    private const int OP_REMAINING = 2;

    private const int OP_EXHAUSTED = 3;

    private const int OP_CLAMP = 4;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = self::W45_RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    private int $spent = 0;

    public static function useW45RustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? self::W45_RUST_FFI_DEFAULT;
    }

    public static function usesW45RustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function __construct(public readonly int $limit) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function allows(int $bytes): bool
    {
        return (self::$useRustFfi ? $this->allowsNative($bytes) : null) ?? $this->allowsPhp($bytes);
    }

    public function allowsPhp(int $bytes): bool
    {
        return $this->spent + max(0, $bytes) <= $this->limit;
    }

    public function allowsNative(int $bytes): ?bool
    {
        $value = $this->evalNative(self::OP_ALLOWS, $bytes);

        return $value === null ? null : $value === 1;
    }

    public function spend(int $bytes): void
    {
        $this->spent = (self::$useRustFfi ? $this->spentAfterNative($bytes) : null) ?? $this->spentAfterPhp($bytes);
    }

    public function spentAfterPhp(int $bytes): int
    {
        $spent = $this->spent;
        $spent += max(0, $bytes);

        return $spent;
    }

    public function spentAfterNative(int $bytes): ?int
    {
        return $this->evalNative(self::OP_SPEND, $bytes);
    }

    public function remaining(): int
    {
        return (self::$useRustFfi ? $this->remainingNative() : null) ?? $this->remainingPhp();
    }

    public function remainingPhp(): int
    {
        return max(0, $this->limit - $this->spent);
    }

    public function remainingNative(): ?int
    {
        return $this->evalNative(self::OP_REMAINING, 0);
    }

    public function exhausted(): bool
    {
        return (self::$useRustFfi ? $this->exhaustedNative() : null) ?? $this->exhaustedPhp();
    }

    public function exhaustedPhp(): bool
    {
        return $this->spent >= $this->limit;
    }

    public function exhaustedNative(): ?bool
    {
        $value = $this->evalNative(self::OP_EXHAUSTED, 0);

        return $value === null ? null : $value === 1;
    }

    /**
     * Largest request that stays within the remaining budget.
     */
    public function clampRequestBytes(int $requested): int
    {
        return (self::$useRustFfi ? $this->clampRequestBytesNative($requested) : null) ?? $this->clampRequestBytesPhp($requested);
    }

    public function clampRequestBytesPhp(int $requested): int
    {
        return min($requested, $this->remainingPhp());
    }

    public function clampRequestBytesNative(int $requested): ?int
    {
        return $this->evalNative(self::OP_CLAMP, $requested);
    }

    /**
     * Share of the limit already spent, capped at 1.0; a non-positive limit counts as used up.
     */
    public function utilization(): float
    {
        return (self::$useRustFfi ? $this->utilizationNative() : null) ?? $this->utilizationPhp();
    }

    public function utilizationPhp(): float
    {
        if ($this->limit <= 0) {
            return 1.0;
        }
        $ratio = $this->spent / $this->limit;

        return $ratio >= 1 ? 1.0 : (float) $ratio;
    }

    public function utilizationNative(): ?float
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $status = $ffi->new('int32_t');
            // @phpstan-ignore method.notFound
            $value = $ffi->nntmux_w45_budget_utilization($this->limit, $this->spent, FFI::addr($status));
            // @phpstan-ignore property.notFound
            $code = (int) $status->cdata;
        } catch (Throwable) {
            return null;
        }

        return $code === 1 ? (float) $value : null;
    }

    /**
     * Null when PHP must evaluate the operation (Rust unavailable or integer overflow).
     */
    private function evalNative(int $op, int $bytes): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $out = $ffi->new('int64_t');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_w45_budget_eval($op, $this->limit, $this->spent, $bytes, FFI::addr($out));
            // @phpstan-ignore property.notFound
            $value = (int) $out->cdata;
        } catch (Throwable) {
            return null;
        }

        return $code === 1 ? $value : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    // Rust is the default only while the paired W53.6 benchmark clears 1.5x on all four metrics.
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        int32_t nntmux_w536_budget_spend_batch(const char *requests, size_t requests_len, size_t count, int64_t limit, int64_t spent, int64_t *out, size_t out_count);
        C;

    public const int W53_SPEND_HEADER = 10;

    private const int W53_VALUE_CAP = 1_099_511_627_775;

    private const int W53_TOTAL_CAP = 4_611_686_018_427_387_904;

    private const int W53_MAX_ITEMS = 1_048_576;

    /**
     * `clampRequestBytesPhp()` then `spend()` over a request sequence against one budget.
     *
     * The limit and starting spend are clamped to [0, 2^62], requests to [0, 2^40). Layout: `[0]`
     * count, `[1]` limit, `[2]` starting spend, `[3]` final spend, `[4]` bytes granted, `[5]` requests
     * `allowsPhp()` accepts whole, `[6]` requests granted short, `[7]` first request after which the
     * budget is exhausted (-1 for none), `[8]` final `utilizationPhp()` in ppm (truncated), `[9]`
     * FNV-1a digest; then per request `allowed | short << 1 | exhausted << 2 | granted << 3`.
     *
     * @param  array<array-key, int>  $requests
     * @return list<int>
     */
    public static function spendPlanBatch(int $limit, int $spent, array $requests): array
    {
        return (self::usesW53RustFfi() ? self::spendPlanBatchNative($limit, $spent, $requests) : null)
            ?? self::spendPlanBatchPhp($limit, $spent, $requests);
    }

    /**
     * @param  array<array-key, int>  $requests
     * @return list<int>
     */
    public static function spendPlanBatchPhp(int $limit, int $spent, array $requests): array
    {
        $cap = self::W53_VALUE_CAP;
        $limit = min(max($limit, 0), self::W53_TOTAL_CAP);
        $spent = min(max($spent, 0), self::W53_TOTAL_CAP);
        $out = [\count($requests), $limit, $spent, 0, 0, 0, 0, -1, 0, 0];
        $digest = 2_166_136_261;
        $rows = [];
        $index = 0;
        foreach ($requests as $request) {
            $request = min(max($request, 0), $cap);
            $allowed = $spent + $request <= $limit;
            $remaining = $limit - $spent;
            $granted = $remaining > 0 ? min($request, $remaining) : 0;
            $spent += $granted;
            $out[4] += $granted;
            $row = $granted << 3;
            if ($allowed) {
                $out[5]++;
                $row |= 1;
            }
            if ($granted < $request) {
                $out[6]++;
                $row |= 2;
            }
            if ($spent >= $limit) {
                $row |= 4;
                if ($out[7] < 0) {
                    $out[7] = $index;
                }
            }
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $index++;
        }
        $out[3] = $spent;
        $out[8] = $spent >= $limit ? 1_000_000 : (int) ((float) $spent / (float) $limit * 1_000_000.0);
        $out[9] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plans, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $requests
     * @return list<int>|null
     */
    public static function spendPlanBatchNative(int $limit, int $spent, array $requests): ?array
    {
        $count = \count($requests);
        if ($count > self::W53_MAX_ITEMS) {
            return null;
        }
        $packed = pack('q*', ...array_is_list($requests) ? $requests : array_values($requests));
        $fields = self::W53_SPEND_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w53Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w536_budget_spend_batch($packed, \strlen($packed), $count, $limit, $spent, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 53.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    public static function usesW53RustFfi(): bool
    {
        $forced = self::w53RustFfiState();

        return \is_bool($forced) ? $forced : self::W53_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 53.6 routing and binding, kept in function statics. No `$op` returns the forced routing (null
     * for the default); `'set'` replaces it with `$val` and returns the previous value; `'binding'`
     * returns the cached `additional_w53` binding and an output buffer of at least `$val` values, or null.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w53', self::W53_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 53.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w53Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w53RustFfiState('binding', $fields);
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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 9.10x, p99 6.38x; uncommon mean 7.31x, p99 4.96x); see RustAdditional7zParserBudgetPersistenceMetricsContextW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_MEDIA_BYTE_BUDGET_FFI_CDEF = <<<'C'
        ssize_t nntmux_w653_media_byte_budget_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 65.3 batch. */
    public const int W65_MEDIA_BYTE_BUDGET_TRAILER = 14;

    /** Cap for the Wave 65.3 running totals. */
    private const int W65_TOTAL_CAP = 4_611_686_018_427_387_904;

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores W65_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the MediaByteBudget Wave 65 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.3 media byte budget gate plan with allows(), spend(), remaining(), exhausted() and clampRequestBytes()'s
     * rules. Each item is `limit|spent|request|mode`; limit, spent and request are 0 empty, 1 up to 15 trimmed ASCII
     * digits, 2 other (invalid values count as 0). The trimmed mode is 0 empty, 1 `reserve`, 2 `consume`, 3 `probe`, 4
     * `release` (any case) or 5 other. Reserve (and empty) grants the whole request when spent + request <= limit,
     * consume grants the request clamped to the remaining bytes, probe grants nothing and release returns the request
     * (spent floored at 0). The outcome is 0 granted, 1 partially granted, 2 denied, 3 no-op (probe, release or a zero
     * request) or 4 invalid (any state 2 or mode 5, which changes nothing). Utilization is the per-mille spent after /
     * limit (capped at 1000; 1000 for a zero limit). Per item `limitState | spentState << 2 | requestState << 4 | mode <<
     * 6 | outcome << 9 | allows << 12 | exhaustedBefore << 13 | exhaustedAfter << 14 | utilization << 15 | grantKiB <<
     * 25` (granted bytes >> 10, capped at 2^37 - 1). Then the five outcome counts, the six mode counts, the granted and
     * remaining-after totals (capped at 2^62) and the count exhausted afterwards.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaByteBudgetPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65MediaByteBudgetPlanBatchNative($items) : self::w65MediaByteBudgetPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaByteBudgetPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_MEDIA_BYTE_BUDGET_TRAILER) ?? self::w65MediaByteBudgetPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaByteBudgetPlanBatchPhp(array $items): array
    {
        $out = [];
        $outcomes = [0, 0, 0, 0, 0];
        $modes = [0, 0, 0, 0, 0, 0];
        $grantSum = $remainingSum = $exhaustedCount = 0;
        foreach ($items as $item) {
            [$rawLimit, $rawSpent, $rawRequest, $rawMode] = explode('|', (string) $item, 4) + ['', '', '', ''];
            [$limitState, $limit] = self::w65Count($rawLimit, 15);
            [$spentState, $spent] = self::w65Count($rawSpent, 15);
            [$requestState, $request] = self::w65Count($rawRequest, 15);
            $modeText = strtolower(trim($rawMode));
            $mode = $modeText === '' ? 0 : (self::W65_BUDGET_MODES[$modeText] ?? 5);
            $allows = $spent + $request <= $limit;
            $exhaustedBefore = $spent >= $limit;
            if ($limitState === 2 || $spentState === 2 || $requestState === 2 || $mode === 5) {
                [$grant, $spentAfter, $outcome] = [0, $spent, 4];
            } elseif ($mode <= 1) {
                [$grant, $spentAfter, $outcome] = $request === 0 ? [0, $spent, 3] : ($allows ? [$request, $spent + $request, 0] : [0, $spent, 2]);
            } elseif ($mode === 2) {
                $granted = min($request, max(0, $limit - $spent));
                if ($request === 0) {
                    [$grant, $spentAfter, $outcome] = [0, $spent, 3];
                } elseif ($granted > 0) {
                    [$grant, $spentAfter, $outcome] = [$granted, $spent + $granted, $granted === $request ? 0 : 1];
                } else {
                    [$grant, $spentAfter, $outcome] = [0, $spent, 2];
                }
            } elseif ($mode === 3) {
                [$grant, $spentAfter, $outcome] = [0, $spent, 3];
            } else {
                [$grant, $spentAfter, $outcome] = [0, max(0, $spent - $request), 3];
            }
            $exhaustedAfter = $spentAfter >= $limit;
            $utilization = $limit > 0 ? min(intdiv($spentAfter * 1000, $limit), 1000) : 1000;
            $outcomes[$outcome]++;
            $modes[$mode]++;
            $grantSum = min($grantSum + $grant, self::W65_TOTAL_CAP);
            $remainingSum = min($remainingSum + max(0, $limit - $spentAfter), self::W65_TOTAL_CAP);
            $exhaustedCount += $exhaustedAfter ? 1 : 0;
            $out[] = $limitState | $spentState << 2 | $requestState << 4 | $mode << 6 | $outcome << 9 | ($allows ? 1 : 0) << 12
                | ($exhaustedBefore ? 1 : 0) << 13 | ($exhaustedAfter ? 1 : 0) << 14 | $utilization << 15 | min($grant >> 10, 137_438_953_471) << 25;
        }
        array_push($out, ...$outcomes, ...$modes);
        array_push($out, $grantSum, $remainingSum, $exhaustedCount);

        return $out;
    }

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w65Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /** Lower-cased budget mode => code (empty 0, anything else 5). */
    private const array W65_BUDGET_MODES = ['reserve' => 1, 'consume' => 2, 'probe' => 3, 'release' => 4];

    /**
     * Wave 65 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w653', self::W65_MEDIA_BYTE_BUDGET_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 65.3 MediaByteBudget export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w65Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w65RustFfiState('ffi');
            $out = self::w65RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w65RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W65_MEDIA_BYTE_BUDGET_FFI_CDEF.)
            $written = $ffi->nntmux_w653_media_byte_budget_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
