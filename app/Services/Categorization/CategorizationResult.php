<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Value object representing the result of a categorization attempt.
 */
class CategorizationResult
{
    /**
     * Wave 41.3 production default is PHP: the paired benchmark in
     * tests/Unit/RustCategorizationPipelineResultMusicPcTvW41ParityTest.php measures only
     * 1.17-1.20x mean / 1.04-1.20x p99 on the common batch (1.54-1.61x mean / 1.44-1.70x p99
     * uncommon), below the 1.5x adoption threshold, so Rust stays behind useW413RustFfi(true).
     * Named W413 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W413_RUST_FFI_DEFAULT = false;

    public const string W413_FFI_CDEF = <<<'C'
        ssize_t nntmux_w413_matched_by_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Exactly 10 0x1F-separated decimals of 1-19 digits. */
    private const string W413_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){9}\d{1,19}\z/';

    private static ?bool $w413RustFfiOverride = null;

    private static FFI|false|null $w413RustFfiHandle = null;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @param  int  $categoryId  The determined category ID
     * @param  float  $confidence  Confidence level (0.0 to 1.0)
     * @param  string  $matchedBy  Description of what matched
     * @param  array<string, mixed>  $debug  Additional debug information
     */
    public function __construct(
        public readonly int $categoryId = Category::OTHER_MISC,
        public readonly float $confidence = 0.0,
        public readonly string $matchedBy = 'none',
        public readonly array $debug = []
    ) {}

    /**
     * Check if this result represents a successful categorization.
     *
     * Returns true for any non-zero confidence result that was explicitly
     * matched, including intentional OTHER_MISC assignments (e.g. archive,
     * dataset, obfuscated_pattern). Only the default no-match sentinel
     * (confidence 0, matchedBy 'no_match') is treated as unsuccessful.
     */
    public function isSuccessful(): bool
    {
        return self::$useRustFfi
            ? $this->isSuccessfulNative()
            : $this->isSuccessfulPhp();
    }

    public function isSuccessfulPhp(): bool
    {
        if ($this->confidence <= 0) {
            return false;
        }

        // The default no-match sentinel is not successful
        if ($this->categoryId === Category::OTHER_MISC && $this->matchedBy === 'no_match') {
            return false;
        }

        return true;
    }

    public function isSuccessfulNative(): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->isSuccessfulPhp();
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorization_result_is_successful(
            $this->categoryId,
            $this->confidence,
            $this->matchedBy,
            \strlen($this->matchedBy),
        );
        if (! \is_int($res) || $res < 0) {
            return $this->isSuccessfulPhp();
        }

        return $res === 1;
    }

    /**
     * Check if this result should take precedence over another.
     */
    public function shouldOverride(CategorizationResult $other): bool
    {
        return self::$useRustFfi
            ? $this->shouldOverrideNative($other)
            : $this->shouldOverridePhp($other);
    }

    public function shouldOverridePhp(CategorizationResult $other): bool
    {
        if ($this->categoryId !== Category::OTHER_MISC && $this->categoryId !== Category::OTHER_HASHED &&
            $other->isProtectedMiscResultPhp() && $this->confidence < 0.6) {
            return false;
        }

        // Higher confidence always wins
        if ($this->confidence > $other->confidence) {
            return true;
        }

        // If same confidence, prefer non-misc categories
        if ($this->confidence === $other->confidence) {
            return $this->categoryId !== Category::OTHER_MISC && $other->categoryId === Category::OTHER_MISC;
        }

        return false;
    }

    public function shouldOverrideNative(CategorizationResult $other): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->shouldOverridePhp($other);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorization_result_should_override(
            $this->categoryId,
            $this->confidence,
            $this->matchedBy,
            \strlen($this->matchedBy),
            $other->categoryId,
            $other->confidence,
            $other->matchedBy,
            \strlen($other->matchedBy),
        );
        if (! \is_int($res) || $res < 0) {
            return $this->shouldOverridePhp($other);
        }

        return $res === 1;
    }

    /**
     * Determine whether this result should resist weak downstream overrides.
     */
    public function isProtectedMiscResult(): bool
    {
        return self::$useRustFfi
            ? $this->isProtectedMiscResultNative()
            : $this->isProtectedMiscResultPhp();
    }

    public function isProtectedMiscResultPhp(): bool
    {
        if ($this->categoryId === Category::OTHER_HASHED) {
            return true;
        }

        if ($this->categoryId !== Category::OTHER_MISC) {
            return false;
        }

        return str_starts_with($this->matchedBy, 'hash_')
            || str_starts_with($this->matchedBy, 'obfuscated_')
            || str_starts_with($this->matchedBy, 'gibberish_')
            || $this->matchedBy === 'group_only_low_signal';
    }

    public function isProtectedMiscResultNative(): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->isProtectedMiscResultPhp();
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorization_result_is_protected_misc(
            $this->categoryId,
            $this->matchedBy,
            \strlen($this->matchedBy),
        );
        if (! \is_int($res) || $res < 0) {
            return $this->isProtectedMiscResultPhp();
        }

        return $res === 1;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', ReleaseImageService::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Create a failed/empty result.
     */
    public static function noMatch(): self
    {
        return new self(Category::OTHER_MISC, 0.0, 'no_match');
    }

    /**
     * Create a result with debug info merged.
     *
     * @param  array<string, mixed>  $additionalDebug
     */
    public function withDebug(array $additionalDebug): self
    {
        return new self(
            $this->categoryId,
            $this->confidence,
            $this->matchedBy,
            array_merge($this->debug, $additionalDebug)
        );
    }

    public static function useW413RustFfi(bool $enable = true): void
    {
        self::$w413RustFfiOverride = $enable;
    }

    public static function usesW413RustFfi(): bool
    {
        return self::$w413RustFfiOverride ?? self::W413_RUST_FFI_DEFAULT;
    }

    public static function resetW413RustFfi(): void
    {
        self::$w413RustFfiOverride = null;
        self::$w413RustFfiHandle = null;
    }

    /**
     * Summary of a batch of `matchedBy` labels, compared byte for byte: "" is empty, "no_match" and
     * "none" are the sentinels, "hash_", "obfuscated_" and "gibberish_" prefixes and
     * "group_only_low_signal" are the protected Misc labels, and anything else is other. Distinct
     * counts unique labels; non-strings count as "".
     *
     * @param  array<array-key, mixed>  $matchedBy
     * @return array{total: int, no_match: int, none: int, hash: int, obfuscated: int, gibberish: int, group_only_low_signal: int, other: int, empty: int, distinct: int}
     */
    public static function summarizeMatchedByBatch(array $matchedBy): array
    {
        return (self::usesW413RustFfi() ? self::summarizeMatchedByBatchNative($matchedBy) : null)
            ?? self::summarizeMatchedByBatchPhp($matchedBy);
    }

    /**
     * @param  array<array-key, mixed>  $matchedBy
     * @return array{total: int, no_match: int, none: int, hash: int, obfuscated: int, gibberish: int, group_only_low_signal: int, other: int, empty: int, distinct: int}
     */
    public static function summarizeMatchedByBatchPhp(array $matchedBy): array
    {
        $counts = [0, 0, 0, 0, 0, 0, 0, 0];
        $seen = [];
        foreach ($matchedBy as $item) {
            $label = \is_string($item) ? $item : '';
            $seen[$label] = true;
            if ($label === '') {
                $counts[7]++;
            } elseif ($label === 'no_match') {
                $counts[0]++;
            } elseif ($label === 'none') {
                $counts[1]++;
            } elseif ($label === 'group_only_low_signal') {
                $counts[5]++;
            } elseif (str_starts_with($label, 'hash_')) {
                $counts[2]++;
            } elseif (str_starts_with($label, 'obfuscated_')) {
                $counts[3]++;
            } elseif (str_starts_with($label, 'gibberish_')) {
                $counts[4]++;
            } else {
                $counts[6]++;
            }
        }

        return [
            'total' => \count($matchedBy),
            'no_match' => $counts[0],
            'none' => $counts[1],
            'hash' => $counts[2],
            'obfuscated' => $counts[3],
            'gibberish' => $counts[4],
            'group_only_low_signal' => $counts[5],
            'other' => $counts[6],
            'empty' => $counts[7],
            'distinct' => \count($seen),
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $matchedBy
     * @return array{total: int, no_match: int, none: int, hash: int, obfuscated: int, gibberish: int, group_only_low_signal: int, other: int, empty: int, distinct: int}|null
     */
    public static function summarizeMatchedByBatchNative(array $matchedBy): ?array
    {
        $lengths = [];
        foreach ($matchedBy as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $matchedBy);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW413Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'no_match' => (int) $fields[1],
            'none' => (int) $fields[2],
            'hash' => (int) $fields[3],
            'obfuscated' => (int) $fields[4],
            'gibberish' => (int) $fields[5],
            'group_only_low_signal' => (int) $fields[6],
            'other' => (int) $fields[7],
            'empty' => (int) $fields[8],
            'distinct' => (int) $fields[9],
        ];
    }

    /**
     * Calls this class's Wave 41.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W413_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW413Native(string $blob, string $lens): ?array
    {
        $ffi = self::w413RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w413_matched_by_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w413_matched_by_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W413_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w413RustFfi(): ?FFI
    {
        if (self::$w413RustFfiHandle === null) {
            self::$w413RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w413', self::W413_FFI_CDEF) ?? false;
        }

        return self::$w413RustFfiHandle === false ? null : self::$w413RustFfiHandle;
    }
}
