<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /**
     * Enabled: the paired benchmark in the W53 parity test measures 1.97x common mean, 2.25x common
     * p99, 2.29x uncommon mean and 2.11x uncommon p99 (per-metric minimum of three runs), above the
     * 1.5x rule.
     */
    private const bool W53_RUST_FFI_DEFAULT = true;

    private const string W53_FFI_CDEF = 'ssize_t nntmux_w531_categorization_result_fold_batch(const char *category_ids, size_t category_ids_len, const char *confidences, size_t confidences_len, const char *matched_by, size_t matched_by_len, size_t count, int64_t misc_id, int64_t hashed_id, int64_t *out, size_t out_cap);';

    public static function usesW53RustFfi(): bool
    {
        $enabled = self::w53RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W53_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 53 batch routed to Rust or PHP, then restores the routing. */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('set', $previous);
        }
    }

    /**
     * Folds a batch of results given as columns: how many {@see isSuccessfulPhp()}, how many
     * {@see isProtectedMiscResultPhp()}, and the index of the best one when each result replaces
     * the best so far only if it {@see shouldOverridePhp()} it (-1 for an empty batch).
     *
     * @param  array<array-key, int>  $categoryIds
     * @param  array<array-key, float>  $confidences
     * @param  array<array-key, string>  $matchedBy
     * @return array{successful: int, protected: int, best_index: int}
     *
     * @throws \InvalidArgumentException when the columns differ in length
     */
    public static function foldResults(array $categoryIds, array $confidences, array $matchedBy): array
    {
        return self::usesW53RustFfi() ? self::foldResultsNative($categoryIds, $confidences, $matchedBy) : self::foldResultsPhp($categoryIds, $confidences, $matchedBy);
    }

    /**
     * @param  array<array-key, int>  $categoryIds
     * @param  array<array-key, float>  $confidences
     * @param  array<array-key, string>  $matchedBy
     * @return array{successful: int, protected: int, best_index: int}
     */
    public static function foldResultsPhp(array $categoryIds, array $confidences, array $matchedBy): array
    {
        self::w53Count($categoryIds, $confidences, $matchedBy);
        $confidences = array_values($confidences);
        $matchedBy = array_values($matchedBy);
        $successful = $protected = $bestId = 0;
        $best = -1;
        $bestConfidence = 0.0;
        $bestProtected = false;
        foreach (array_values($categoryIds) as $i => $id) {
            $confidence = (float) $confidences[$i];
            $matched = $matchedBy[$i];
            $isProtected = $id === Category::OTHER_HASHED || ($id === Category::OTHER_MISC
                && (str_starts_with($matched, 'hash_') || str_starts_with($matched, 'obfuscated_') || str_starts_with($matched, 'gibberish_') || $matched === 'group_only_low_signal'));
            if (! ($confidence <= 0) && ! ($id === Category::OTHER_MISC && $matched === 'no_match')) {
                $successful++;
            }
            if ($isProtected) {
                $protected++;
            }
            if ($best < 0) {
                $wins = true;
            } elseif ($id !== Category::OTHER_MISC && $id !== Category::OTHER_HASHED && $bestProtected && $confidence < 0.6) {
                $wins = false;
            } elseif ($confidence > $bestConfidence) {
                $wins = true;
            } elseif ($confidence === $bestConfidence) {
                $wins = $id !== Category::OTHER_MISC && $bestId === Category::OTHER_MISC;
            } else {
                $wins = false;
            }
            if ($wins) {
                $best = $i;
                $bestId = $id;
                $bestConfidence = $confidence;
                $bestProtected = $isProtected;
            }
        }

        return ['successful' => $successful, 'protected' => $protected, 'best_index' => $best];
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a
     * `matchedBy` holding a NUL byte).
     *
     * @param  array<array-key, int>  $categoryIds
     * @param  array<array-key, float>  $confidences
     * @param  array<array-key, string>  $matchedBy
     * @return array{successful: int, protected: int, best_index: int}
     */
    public static function foldResultsNative(array $categoryIds, array $confidences, array $matchedBy): array
    {
        $count = self::w53Count($categoryIds, $confidences, $matchedBy);
        $buffer = $count === 0 ? null : self::w53Buffer(3);
        if ($buffer === null) {
            return self::foldResultsPhp($categoryIds, $confidences, $matchedBy);
        }
        [$ffi, $out, $cap] = $buffer;
        $ids = pack('P*', ...array_values($categoryIds));
        $packed = pack('e*', ...array_values($confidences));
        $joined = implode("\0", $matchedBy);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w53Decode($out, $ffi->nntmux_w531_categorization_result_fold_batch($ids, \strlen($ids), $packed, \strlen($packed), $joined, \strlen($joined), $count, Category::OTHER_MISC, Category::OTHER_HASHED, $out, $cap), 3);
        } catch (Throwable) {
            $words = null;
        }
        if ($words === null || \count($words) !== 3) {
            return self::foldResultsPhp($categoryIds, $confidences, $matchedBy);
        }

        return ['successful' => $words[0], 'protected' => $words[1], 'best_index' => $words[2]];
    }

    /**
     * @param  array<mixed>  ...$columns
     */
    private static function w53Count(array ...$columns): int
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count) {
                throw new \InvalidArgumentException('Categorization result columns must have the same length.');
            }
        }

        return $count;
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: CData, 2: int}|null
     */
    private static function w53Buffer(int $words): ?array
    {
        $buffer = self::w53RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w53Decode(CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 53 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('obfuscation_blacklist_cat_w53', self::W53_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    // ========================================================================
    // WAVE 66.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four 1000-round paired runs reached 3.19x common mean, 2.67x common p99, 2.77x uncommon mean and 2.43x uncommon p99; see RustBooksProcessorCategorizationPipelineResultServicePipeW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    private const string W66_FFI_CDEF = 'ssize_t nntmux_w664_categorization_result_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w66CategorizationResultPlanBatch() record. */
    public const int W66_ROW_WORDS = 4;

    /** int64 summary words after the w66CategorizationResultPlanBatch() records (none for an empty batch). */
    public const int W66_TRAILER_WORDS = 11;

    /**
     * Result evaluation rows for [matchedBy, other matchedBy, category ID, other category ID,
     * confidence, other confidence] items: this result against the other (the current best).
     *
     * Successful and protected follow isSuccessfulPhp() and isProtectedMiscResultPhp(); the
     * override gate follows shouldOverridePhp(): 1 blocked (a non-Misc result below 0.6 against a
     * protected one), 2 higher confidence, 3 tie won by a non-Misc result over Misc, 4 tie lost, 5
     * lower or NaN confidence (2 and 3 override). A category from 0 to 8999 is valid and its
     * parent is its thousand, else -1. Rule tags: 0 empty, 1 no_match, 2 none, 3 hash_, 4
     * obfuscated_, 5 gibberish_, 6 group_only_low_signal, 7 other. Bands: 0 none, 1 low (above
     * zero), 2 medium (0.5), 3 high (0.8), 4 stop (0.95), 5 NaN. Basis points are
     * `(int) (clamp(confidence, -1, 2) * 10000)`, -1 for NaN.
     *
     * Rows [successful | protected << 1 | other protected << 2 | overrides << 3 | valid category
     * << 4 | tag << 8 | other tag << 12 | band << 16 | other band << 20 | gate << 24 | matchedBy
     * length (clamped to 2^16 - 1) << 28, parent ID, confidence basis points,
     * fnv1a32(strtolower(matchedBy before its first `_`)) (0 when empty)], then the trailer
     * [records, successful, protected, overrides, invalid categories, the five gate counts,
     * FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: float, 5: float}>  $items
     * @return list<int>
     */
    public static function w66CategorizationResultPlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66CategorizationResultPlanBatchNative($items) : self::w66CategorizationResultPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: float, 5: float}>  $items
     * @return list<int>
     */
    public static function w66CategorizationResultPlanBatchPhp(array $items): array
    {
        self::w66Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$by, $otherBy, $category, $otherCategory, $confidence, $otherConfidence]) {
            $ok = ! ($confidence <= 0) && ! ($category === Category::OTHER_MISC && $by === 'no_match');
            $shield = self::w66Protected($category, $by);
            $otherShield = self::w66Protected($otherCategory, $otherBy);
            $gate = match (true) {
                $category !== Category::OTHER_MISC && $category !== Category::OTHER_HASHED && $otherShield && $confidence < 0.6 => 1,
                $confidence > $otherConfidence => 2,
                $confidence === $otherConfidence => $category !== Category::OTHER_MISC && $otherCategory === Category::OTHER_MISC ? 3 : 4,
                default => 5,
            };
            $wins = $gate === 2 || $gate === 3;
            $valid = $category >= 0 && $category <= 8999;
            $parent = $valid ? intdiv($category, 1000) * 1000 : -1;
            $underscore = strpos($by, '_');
            $tagHash = self::w66Fnv(strtolower($underscore === false ? $by : substr($by, 0, $underscore)));
            $bp = is_nan($confidence) ? -1 : (int) (max(-1.0, min(2.0, $confidence)) * 10_000.0);
            $word = ($ok ? 1 : 0) | ($shield ? 2 : 0) | ($otherShield ? 4 : 0) | ($wins ? 8 : 0) | ($valid ? 16 : 0) | self::w66Tag($by) << 8 | self::w66Tag($otherBy) << 12
                | self::w66Band($confidence) << 16 | self::w66Band($otherConfidence) << 20 | $gate << 24 | min(\strlen($by), 0xFFFF) << 28;
            array_push($out, $word, $parent, $bp, $tagHash);
            $t[1] += $ok ? 1 : 0;
            $t[2] += $shield ? 1 : 0;
            $t[3] += $wins ? 1 : 0;
            $t[4] += $valid ? 0 : 1;
            $t[4 + $gate]++;
            $value = $word ^ $tagHash << 20 ^ $bp;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[10] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: float, 5: float}>  $items
     * @return list<int>
     */
    public static function w66CategorizationResultPlanBatchNative(array $items): array
    {
        self::w66Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w66Join([array_column($items, 0), array_column($items, 1)]);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3)).pack('e*', ...array_column($items, 4), ...array_column($items, 5));

        return self::w66Call($blob, $twin, $ints, $count, $count * self::W66_ROW_WORDS + self::W66_TRAILER_WORDS)
            ?? self::w66CategorizationResultPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w66Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W66 categorization result plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 6 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2])
                || ! \is_int($item[3]) || ! \is_float($item[4]) || ! \is_float($item[5])) {
                throw new \InvalidArgumentException('W66 categorization result plan batch items must be [string matchedBy, string other matchedBy, int category, int other category, float confidence, float other confidence] lists.');
            }
        }
    }

    /** isProtectedMiscResultPhp() for a category and matchedBy. */
    private static function w66Protected(int $category, string $by): bool
    {
        return $category === Category::OTHER_HASHED || ($category === Category::OTHER_MISC && (str_starts_with($by, 'hash_') || str_starts_with($by, 'obfuscated_')
            || str_starts_with($by, 'gibberish_') || $by === 'group_only_low_signal'));
    }

    /** 0 empty, 1 no_match, 2 none, 3 hash_, 4 obfuscated_, 5 gibberish_, 6 group_only_low_signal, 7 other. */
    private static function w66Tag(string $by): int
    {
        return match (true) {
            $by === '' => 0,
            $by === 'no_match' => 1,
            $by === 'none' => 2,
            $by === 'group_only_low_signal' => 6,
            str_starts_with($by, 'hash_') => 3,
            str_starts_with($by, 'obfuscated_') => 4,
            str_starts_with($by, 'gibberish_') => 5,
            default => 7,
        };
    }

    /** 5 NaN, 4 stop (0.95), 3 high (0.8), 2 medium (0.5), 1 low (above zero), 0 none. */
    private static function w66Band(float $confidence): int
    {
        return match (true) {
            is_nan($confidence) => 5,
            $confidence >= 0.95 => 4,
            $confidence >= 0.8 => 3,
            $confidence >= 0.5 => 2,
            $confidence > 0.0 => 1,
            default => 0,
        };
    }

    public static function usesW66RustFfi(): bool
    {
        $enabled = self::w66RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W66_RUST_FFI_DEFAULT;
    }

    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('enabled', true);
    }

    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('enabled', false);
    }

    /** Drop the W66 override, binding, output buffer and declined-call count; routing returns to W66_RUST_FFI_DEFAULT. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    /**
     * Run $callback with the W66 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('enabled');
        self::w66RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w66RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w66Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w66Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w66RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w66', self::W66_FFI_CDEF) ?? false;
            self::w66RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w66RustFfiState('out', $out);
                self::w66RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W66_FFI_CDEF.)
            $written = $ffi->nntmux_w664_categorization_result_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w66RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W66 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w66Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
