<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * How an NZB file was matched to a PAR2 file entry.
 */
enum MappingConfidence: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_aenum_resolve(int32_t kind, const char *ptr, size_t len);
        ssize_t nntmux_aenum_label(int32_t kind, int64_t index, char *out, size_t cap);
        int32_t nntmux_aenum_rank(int32_t kind, int64_t index);
        C;

    /** `nntmux_aenum_*` selector for this enum. */
    private const int FFI_KIND = 0;

    /** Its first 16 KiB, or its yEnc name and size, matched the PAR2 entry. */
    case Verified = 'verified';

    /** It was the only file whose size fits the PAR2 entry, and the other way round. */
    case UniqueSize = 'unique-size';

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
            self::Verified => 'Verified',
            self::UniqueSize => 'Unique size',
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
     * Match strength: a verified match outranks a unique-size match.
     */
    public function rank(): int
    {
        return self::usesRustFfi() ? $this->rankNative() : $this->rankPhp();
    }

    public function rankPhp(): int
    {
        return match ($this) {
            self::Verified => 2,
            self::UniqueSize => 1,
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
            self::Verified => 0,
            self::UniqueSize => 1,
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

    // Rust is the default only while the paired W49.6 benchmark clears 1.5x on all four metrics.
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        int32_t nntmux_w49_mapping_confidence_rank(const char *values, size_t values_len, const char *value_twin, size_t value_twin_len, const char *sizes, size_t sizes_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W49_MAPPING_HEADER = 12;

    private const int W49_MAX_ITEMS = 1_048_576;

    private const int W49_VALUE_CAP = (1 << 40) - 1;

    private const int W49_TOTAL_CAP = 1 << 62;

    public static function usesW49RustFfi(): bool
    {
        return self::w49RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 49.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w49RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w49RustFfiState('swap', $previous);
        }
    }

    /**
     * Resolve and rank a batch of mapping confidences as `tryFromNormalizedPhp()` does
     * (case 0 verified rank 2, case 1 unique size rank 1, case 2 unknown rank 0), with the
     * mapped file sizes (missing sizes are 0; sizes are clamped to 0..2^40-1).
     *
     * Layout: `[0]` count, `[1..3]` per case, `[4..6]` bytes per case, `[7]` first weakest
     * index and `[8]` its rank (-1 for both when empty), `[9]` all verified, `[10]` confidence
     * ppm (`(2 * verified + unique) / (2 * count)`), `[11]` FNV-1a digest; then per value
     * `case | rank << 4 | size << 8`.
     *
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function rankMappingBatch(array $values, array $sizes): array
    {
        return (self::usesW49RustFfi() ? self::rankMappingBatchNative($values, $sizes) : null) ?? self::rankMappingBatchPhp($values, $sizes);
    }

    /**
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function rankMappingBatchPhp(array $values, array $sizes): array
    {
        $values = array_values($values);
        $sizes = array_values($sizes);
        $count = \count($values);
        $cap = self::W49_VALUE_CAP;
        $out = array_fill(0, self::W49_MAPPING_HEADER, 0);
        $out[0] = $count;
        $out[7] = $out[8] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($values as $index => $value) {
            $case = match (str_replace('_', '-', strtolower(trim($value)))) {
                'verified' => 0,
                'unique-size' => 1,
                default => 2,
            };
            $rank = 2 - $case;
            $size = min(max($sizes[$index] ?? 0, 0), $cap);
            $out[1 + $case]++;
            $out[4 + $case] = min($out[4 + $case] + $size, self::W49_TOTAL_CAP);
            if ($out[8] < 0 || $rank < $out[8]) {
                $out[8] = $rank;
                $out[7] = $index;
            }
            $digest = (($digest ^ $case) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $case | $rank << 4 | $size << 8;
        }
        if ($count > 0) {
            $out[9] = $out[1] === $count ? 1 : 0;
            $out[10] = intdiv(($out[1] * 2 + $out[2]) * 1_000_000, 2 * $count);
        }
        $out[11] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust ranking, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $sizes
     * @return list<int>|null
     */
    public static function rankMappingBatchNative(array $values, array $sizes): ?array
    {
        $values = array_values($values);
        $count = \count($values);
        $fields = self::W49_MAPPING_HEADER + $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W49_MAX_ITEMS ? self::w49RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w49Strings($values);
        $packed = pack('q*', ...self::w49Column($sizes, $count));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w49_mapping_confidence_rank($joined, \strlen($joined), $twin, \strlen($twin), $packed, \strlen($packed), $count, $out, $fields);
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
     * Wave 49.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w49RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W49_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w49', self::W49_FFI_CDEF) ?? false;
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
    private static function w49Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Values by position, cut or zero-padded to `$count`.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w49Column(array $values, int $count): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, 0);
    }

    // Rust is the default only while the paired W60.6 benchmark clears 1.5x on all four metrics.
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_MAPPING_FFI_CDEF = <<<'C'
        int32_t nntmux_w606_mapping_confidence_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W60_MAPPING_HEADER = 20;

    private const int W60_MAX_ITEMS = 1_048_576;

    private const int W60_VALUE_CAP = (1 << 40) - 1;

    private const int W60_TOTAL_CAP = 1 << 62;

    /** Backing value => case index. */
    private const array W60_VALUES = ['verified' => 0, 'unique-size' => 1];

    /** Case name => case index. */
    private const array W60_NAMES = ['Verified' => 0, 'UniqueSize' => 1];

    /**
     * Weigh mapping evidence. Values resolve by backing value (source 0), case name (1), the backing value
     * of `str_replace('_', '-', strtolower(trim($value)))` (2), else unknown case 2 (3); rank is 2 verified,
     * 1 unique size, 0 unknown. PAR2, NFO and header evidence counts clamp to 0..255 and sizes to
     * 0..2^40-1. The score is `min(1000, rank * 100 + par2 * 40 + nfo * 15 + header * 25)`; tier 2 from 300,
     * 1 from 150. A unique-size match with PAR2 evidence is promotable; a verified one without PAR2 or
     * header evidence conflicts.
     *
     * Layout: `[0]` count, `[1..3]` per case, `[4..7]` per source, `[8..10]` per tier, `[11]` promotable,
     * `[12]` conflicts, `[13]` score sum, `[14]` max score and `[15]` its first index (-1), `[16]` verified
     * and `[17]` unique-size bytes (capped at 2^62), `[18]` mean score, `[19]` FNV-1a digest; then per mapping
     * `case | source << 2 | tier << 4 | promotable << 6 | conflict << 7 | rank << 8 | score << 10 | par2 << 20
     * | nfo << 28 | header << 36 | min(value bytes, 255) << 44`.
     *
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $par2Matches
     * @param  array<array-key, int>  $nfoMatches
     * @param  array<array-key, int>  $headerMatches
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function planMappingConfidenceBatch(array $values, array $par2Matches, array $nfoMatches, array $headerMatches, array $sizes): array
    {
        return (self::usesW60RustFfi() ? self::planMappingConfidenceBatchNative($values, $par2Matches, $nfoMatches, $headerMatches, $sizes) : null)
            ?? self::planMappingConfidenceBatchPhp($values, $par2Matches, $nfoMatches, $headerMatches, $sizes);
    }

    /**
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $par2Matches
     * @param  array<array-key, int>  $nfoMatches
     * @param  array<array-key, int>  $headerMatches
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function planMappingConfidenceBatchPhp(array $values, array $par2Matches, array $nfoMatches, array $headerMatches, array $sizes): array
    {
        $values = array_values($values);
        $par2Matches = array_values($par2Matches);
        $nfoMatches = array_values($nfoMatches);
        $headerMatches = array_values($headerMatches);
        $sizes = array_values($sizes);
        $count = \count($values);
        $out = array_fill(0, self::W60_MAPPING_HEADER, 0);
        $out[0] = $count;
        $out[15] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($values as $at => $value) {
            $value = (string) $value;
            $source = 0;
            $case = self::W60_VALUES[$value] ?? null;
            if ($case === null) {
                $source = 1;
                $case = self::W60_NAMES[$value] ?? null;
                if ($case === null) {
                    $source = 2;
                    $case = self::W60_VALUES[str_replace('_', '-', strtolower(trim($value)))] ?? null;
                    if ($case === null) {
                        $source = 3;
                        $case = 2;
                    }
                }
            }
            $rank = 2 - $case;
            $par2 = min(max((int) ($par2Matches[$at] ?? 0), 0), 255);
            $nfo = min(max((int) ($nfoMatches[$at] ?? 0), 0), 255);
            $header = min(max((int) ($headerMatches[$at] ?? 0), 0), 255);
            $size = min(max((int) ($sizes[$at] ?? 0), 0), self::W60_VALUE_CAP);
            $score = min(1_000, $rank * 100 + $par2 * 40 + $nfo * 15 + $header * 25);
            $tier = $score >= 300 ? 2 : ($score >= 150 ? 1 : 0);
            $promotable = $case === 1 && $par2 > 0 ? 1 : 0;
            $conflict = $case === 0 && $par2 === 0 && $header === 0 ? 1 : 0;
            $out[1 + $case]++;
            $out[4 + $source]++;
            $out[8 + $tier]++;
            $out[11] += $promotable;
            $out[12] += $conflict;
            $out[13] += $score;
            if ($out[15] < 0 || $score > $out[14]) {
                $out[14] = $score;
                $out[15] = $at;
            }
            if ($case < 2) {
                $out[16 + $case] = min($out[16 + $case] + $size, self::W60_TOTAL_CAP);
            }
            $row = $case | $source << 2 | $tier << 4 | $promotable << 6 | $conflict << 7 | $rank << 8 | $score << 10 | $par2 << 20
                | $nfo << 28 | $header << 36 | min(\strlen($value), 255) << 44;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($count > 0) {
            $out[18] = intdiv($out[13], $count);
        }
        $out[19] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $par2Matches
     * @param  array<array-key, int>  $nfoMatches
     * @param  array<array-key, int>  $headerMatches
     * @param  array<array-key, int>  $sizes
     * @return list<int>|null
     */
    public static function planMappingConfidenceBatchNative(array $values, array $par2Matches, array $nfoMatches, array $headerMatches, array $sizes): ?array
    {
        $count = \count($values);
        if ($count > self::W60_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w60Strings(array_values($values));
        $ints = pack('q*', ...self::w60Column($par2Matches, $count), ...self::w60Column($nfoMatches, $count),
            ...self::w60Column($headerMatches, $count), ...self::w60Column($sizes, $count));
        $fields = self::W60_MAPPING_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w60Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w606_mapping_confidence_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 60.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $forced = self::w60RustFfiState();

        return \is_bool($forced) ? $forced : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 60.6 routing and binding in function statics (enums and readonly classes cannot declare properties).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val` and returns
     * the previous value; `'binding'` returns the cached binding and an output buffer of at least `$val`
     * values, or null.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w606', self::W60_MAPPING_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 60.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w60Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w60RustFfiState('binding', $fields);
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
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w60Strings(array $items): array
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
    private static function w60Column(array $values, int $count, int|string $pad = 0): array
    {
        $values = array_values($values);

        return \count($values) === $count ? $values : array_pad(\array_slice($values, 0, $count), $count, $pad);
    }
}
