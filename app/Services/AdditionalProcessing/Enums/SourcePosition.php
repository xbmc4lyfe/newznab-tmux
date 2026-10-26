<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Which part of a media file a downloaded copy holds.
 */
enum SourcePosition: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_aenum_resolve(int32_t kind, const char *ptr, size_t len);
        ssize_t nntmux_aenum_label(int32_t kind, int64_t index, char *out, size_t cap);
        int32_t nntmux_aenum_rank(int32_t kind, int64_t index);
        C;

    /** `nntmux_aenum_*` selector for this enum. */
    private const int FFI_KIND = 2;

    case Complete = 'complete';
    case Head = 'head';
    case Mid = 'mid';

    // PHP remains the production default until benchmarks qualify Rust.
    public static function enableRustFfi(): void
    {
        self::toggleState(true, true);
    }

    public static function disableRustFfi(): void
    {
        self::toggleState(false, true);
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::toggleState(null, true);
        self::ffi(true);
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::Complete => 'Complete file',
            self::Head => 'File head',
            self::Mid => 'Mid-file',
        };
    }

    public function labelNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->labelPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return $this->labelPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_aenum_label(self::FFI_KIND, $this->ordinal(), $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->labelPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->labelPhp();
        }
    }

    /**
     * Coverage of the media file: a complete copy outranks its head, which outranks a mid-file slice.
     */
    public function rank(): int
    {
        return self::usesRustFfi() ? $this->rankNative() : $this->rankPhp();
    }

    public function rankPhp(): int
    {
        return match ($this) {
            self::Complete => 2,
            self::Head => 1,
            self::Mid => 0,
        };
    }

    public function rankNative(): int
    {
        if (($ffi = self::ffi()) === null) {
            return $this->rankPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $rank = $ffi->nntmux_aenum_rank(self::FFI_KIND, $this->ordinal());
        } catch (Throwable) {
            return $this->rankPhp();
        }

        return \is_int($rank) && $rank >= 0 ? $rank : $this->rankPhp();
    }

    /**
     * Resolve a trimmed, case-insensitive backing value that may use `_` for `-`.
     */
    public static function tryFromNormalized(string $value): ?self
    {
        return self::usesRustFfi() ? self::tryFromNormalizedNative($value) : self::tryFromNormalizedPhp($value);
    }

    public static function tryFromNormalizedPhp(string $value): ?self
    {
        return self::tryFrom(str_replace('_', '-', strtolower(trim($value))));
    }

    public static function tryFromNormalizedNative(string $value): ?self
    {
        if (($ffi = self::ffi()) === null) {
            return self::tryFromNormalizedPhp($value);
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_aenum_resolve(self::FFI_KIND, $value, \strlen($value));
        } catch (Throwable) {
            return self::tryFromNormalizedPhp($value);
        }
        if ($index === -2) {
            return null;
        }

        return \is_int($index) && $index >= 0
            ? (self::cases()[$index] ?? self::tryFromNormalizedPhp($value))
            : self::tryFromNormalizedPhp($value);
    }

    /**
     * Declaration index of this case, as the Rust tables order it.
     */
    private function ordinal(): int
    {
        return match ($this) {
            self::Complete => 0,
            self::Head => 1,
            self::Mid => 2,
        };
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Enums cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default only while the paired W50.6 benchmark clears 1.5x on all four metrics.
    private const bool W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        int32_t nntmux_w506_source_position_batch(const char *values, size_t values_len, const char *value_twin, size_t value_twin_len, const char *sizes, size_t sizes_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W50_POSITION_HEADER = 13;

    private const int W50_MAX_ITEMS = 1_048_576;

    private const int W50_VALUE_CAP = (1 << 40) - 1;

    private const int W50_TOTAL_CAP = 1 << 62;

    public static function usesW50RustFfi(): bool
    {
        return self::w50RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 50.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('swap', $previous);
        }
    }

    /**
     * Resolve and rank a batch of downloaded copies as `tryFromNormalizedPhp()` and `rankPhp()`
     * do (case 3 is an unresolved value), with their sizes (missing sizes are 0; sizes are
     * clamped to 0..2^40-1).
     *
     * Layout: `[0]` count, `[1..4]` per case, `[5..7]` bytes per case, `[8]` index, `[9]` rank
     * and `[10]` size of the best copy (highest rank, then largest size, first on ties; -1, -1, 0
     * when none resolves), `[11]` 1 when a complete copy exists, `[12]` FNV-1a digest; then per
     * value `case | (rank + 1) << 4 | size << 8`.
     *
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function rankPositionBatch(array $values, array $sizes): array
    {
        return (self::usesW50RustFfi() ? self::rankPositionBatchNative($values, $sizes) : null) ?? self::rankPositionBatchPhp($values, $sizes);
    }

    /**
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function rankPositionBatchPhp(array $values, array $sizes): array
    {
        $values = array_values($values);
        $sizes = array_values($sizes);
        $out = array_fill(0, self::W50_POSITION_HEADER, 0);
        $out[0] = \count($values);
        $out[8] = $out[9] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($values as $index => $value) {
            $size = min(max($sizes[$index] ?? 0, 0), self::W50_VALUE_CAP);
            $position = self::tryFromNormalizedPhp($value);
            [$case, $rank] = $position === null ? [3, -1] : [$position->ordinal(), $position->rankPhp()];
            $out[1 + $case]++;
            if ($rank >= 0) {
                $out[5 + $case] = min($out[5 + $case] + $size, self::W50_TOTAL_CAP);
                if ($rank > $out[9] || ($rank === $out[9] && $size > $out[10])) {
                    $out[8] = $index;
                    $out[9] = $rank;
                    $out[10] = $size;
                }
            }
            $digest = (($digest ^ ($case | ($rank + 1) << 4)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $case | ($rank + 1) << 4 | $size << 8;
        }
        $out[11] = $out[1] > 0 ? 1 : 0;
        $out[12] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust ranking, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $sizes
     * @return list<int>|null
     */
    public static function rankPositionBatchNative(array $values, array $sizes): ?array
    {
        $values = array_values($values);
        $count = \count($values);
        $fields = self::W50_POSITION_HEADER + $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W50_MAX_ITEMS ? self::w50RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w50Strings($values);
        $sizes = array_values($sizes);
        $packed = pack('q*', ...(\count($sizes) === $count ? $sizes : array_pad(\array_slice($sizes, 0, $count), $count, 0)));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w506_source_position_batch($joined, \strlen($joined), $twin, \strlen($twin), $packed, \strlen($packed), $count, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $unpacked = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $unpacked === false ? null : array_values($unpacked);
    }

    /**
     * Wave 50.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W50_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w50', self::W50_FFI_CDEF) ?? false;
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
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w50Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    // Rust is the default only while the paired W64.6 benchmark clears 1.5x on all four metrics.
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_POSITION_FFI_CDEF = <<<'C'
        int32_t nntmux_w646_source_position_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t window, int64_t *out, size_t out_cap);
        C;

    public const int W64_POSITION_HEADER = 18;

    private const int W64_MAX_ITEMS = 1_048_576;

    /** Window for a window at or below 0 (1 MiB), the window cap (2^30) and the size/offset clamp (2^40 - 1). */
    private const int W64_POSITION_WINDOW = 1_048_576;

    private const int W64_POSITION_WINDOW_CAP = 1_073_741_824;

    private const int W64_POSITION_SIZE_CAP = 1_099_511_627_775;

    /** Exact token => `case | source << 2`: backing values (0), case names (1) and labels (2). */
    private const array W64_POSITION_TOKENS = [
        'complete' => 0, 'head' => 1, 'mid' => 2,
        'Complete' => 4, 'Head' => 5, 'Mid' => 6,
        'Complete file' => 8, 'File head' => 9, 'Mid-file' => 10,
    ];

    /** Alias of the trimmed, lowercased token with `_` turned into `-` => case. */
    private const array W64_POSITION_ALIASES = [
        'complete' => 0, 'complete-file' => 0, 'full' => 0, 'whole' => 0, 'entire' => 0,
        'head' => 1, 'file-head' => 1, 'start' => 1, 'begin' => 1, 'beginning' => 1, 'first' => 1,
        'mid' => 2, 'mid-file' => 2, 'middle' => 2, 'center' => 2, 'centre' => 2, 'tail' => 2, 'end' => 2,
    ];

    /**
     * Plan the byte window read for each media source position. Positions resolve by backing value (source
     * 0), case name (1), label (2), an alias of `str_replace('_', '-', strtolower(trim($p)))` (3; `middle` and
     * `tail` are mid-file slices), else unknown case 3 (4). Sizes and offsets clamp to 0..2^40-1; the window is
     * 1 MiB at or below 0, else capped at 2^30. A complete copy reads the whole file, a head the first window,
     * and a mid-file slice the window at the offset, moved back (clamped) so it ends inside the file. Coverage
     * is the read share of the file in ppm.
     *
     * Layout: `[0]` count, `[1..5]` per case, `[5..10]` per source, `[10]` clamped, `[11]` empty reads of known
     * positions, `[12]` total read bytes, `[13]` total size, `[14]` best rank (-1), `[15]` first unknown and
     * `[16]` first clamped index (-1), `[17]` FNV-1a digest; then per position `case | source << 2 | (rank + 1)
     * << 5 | clamped << 7 | empty << 8 | coverage << 9 | min(read, 2^30) << 29 | min(token bytes, 7) << 60`.
     *
     * @param  array<array-key, string>  $positions
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $offsets
     * @return list<int>
     */
    public static function w64SourcePositionPlanBatch(array $positions, array $sizes, array $offsets, int $window = 0): array
    {
        return (self::usesW64RustFfi() ? self::w64SourcePositionPlanBatchNative($positions, $sizes, $offsets, $window) : null)
            ?? self::w64SourcePositionPlanBatchPhp($positions, $sizes, $offsets, $window);
    }

    /**
     * @param  array<array-key, string>  $positions
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $offsets
     * @return list<int>
     */
    public static function w64SourcePositionPlanBatchPhp(array $positions, array $sizes, array $offsets, int $window = 0): array
    {
        $positions = array_values($positions);
        $count = \count($positions);
        $sizes = self::w64Column($sizes, $count);
        $offsets = self::w64Column($offsets, $count);
        $window = $window <= 0 ? self::W64_POSITION_WINDOW : min($window, self::W64_POSITION_WINDOW_CAP);
        $out = array_fill(0, self::W64_POSITION_HEADER, 0);
        $out[0] = $count;
        $out[14] = $out[15] = $out[16] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($positions as $at => $position) {
            $position = (string) $position;
            $code = self::W64_POSITION_TOKENS[$position] ?? null;
            if ($code === null) {
                $case = self::W64_POSITION_ALIASES[str_replace('_', '-', strtolower(trim($position)))] ?? 3;
                $source = $case === 3 ? 4 : 3;
            } else {
                $case = $code & 3;
                $source = $code >> 2;
            }
            $size = max(0, min((int) $sizes[$at], self::W64_POSITION_SIZE_CAP));
            $offset = max(0, min((int) $offsets[$at], self::W64_POSITION_SIZE_CAP));
            $clamped = 0;
            if ($case === 0) {
                [$read, $rank] = [$size, 2];
            } elseif ($case === 1) {
                [$read, $rank] = [min($window, $size), 1];
            } elseif ($case === 2) {
                $start = min($offset, max($size - $window, 0));
                [$read, $rank, $clamped] = [min($window, $size - $start), 0, $start !== $offset ? 1 : 0];
            } else {
                [$read, $rank] = [0, -1];
            }
            $empty = $case < 3 && $read === 0 ? 1 : 0;
            $coverage = $size > 0 ? intdiv($read * 1_000_000, $size) : 0;
            $out[1 + $case]++;
            $out[5 + $source]++;
            $out[10] += $clamped;
            $out[11] += $empty;
            $out[12] += $read;
            $out[13] += $size;
            $out[14] = max($out[14], $rank);
            if ($case === 3 && $out[15] < 0) {
                $out[15] = $at;
            }
            if ($clamped === 1 && $out[16] < 0) {
                $out[16] = $at;
            }
            $row = $case | $source << 2 | ($rank + 1) << 5 | $clamped << 7 | $empty << 8 | $coverage << 9
                | min($read, self::W64_POSITION_WINDOW_CAP) << 29 | min(\strlen($position), 7) << 60;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[17] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $positions
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $offsets
     * @return list<int>|null
     */
    public static function w64SourcePositionPlanBatchNative(array $positions, array $sizes, array $offsets, int $window = 0): ?array
    {
        $count = \count($positions);
        if ($count > self::W64_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w64Strings(array_values($positions));
        $ints = pack('q*', ...self::w64Column($sizes, $count), ...self::w64Column($offsets, $count));
        $words = self::W64_POSITION_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w64Call($words, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w646_source_position_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $window, $out, $words));
    }

    /**
     * Route the Wave 64.6 batch plan through Rust for the rest of the process.
     */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /**
     * Route the Wave 64.6 batch plan through PHP for the rest of the process.
     */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /**
     * Restore the default Wave 64.6 routing and drop the cached binding and scratch buffer.
     */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $forced = self::w64RustFfiState();

        return \is_bool($forced) ? $forced : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Run `$callback` with the Wave 64.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 64.6 routing and binding in function statics (enums cannot declare properties). No `$op` returns
     * the forced routing (null for the default); `'set'` replaces it with `$val` and returns the previous
     * value; `'reset'` clears the routing, binding and buffer; `'binding'` returns the cached binding and an
     * output buffer of at least `$val` values, or null.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'reset') {
            $enabled = $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w646', self::W64_POSITION_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 64.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w64Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w64RustFfiState('binding', $fields);
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
     * Values NUL-joined and, when one holds a NUL, the same values joined with 0x01 so Rust finds the
     * separators where the two differ.
     *
     * @param  list<int|string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w64Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or padded with `$pad` to `$count`.
     *
     * @template T of int|string
     *
     * @param  array<array-key, T>  $values
     * @param  T  $pad
     * @return list<T>
     */
    private static function w64Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
