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
}
