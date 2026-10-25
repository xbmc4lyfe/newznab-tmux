<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Passable object that travels through the categorization pipeline.
 */
class CategorizationPassable
{
    /**
     * Wave 45.3 production default stays PHP: the paired benchmark in
     * tests/Unit/RustPredbFeedsG4uGabberKnabenNima4kPassableW45ParityTest.php measures 1.53-1.64x
     * mean but 1.21-1.52x p99 on the common 32-label batch (fixed FFI marshaling dominates the
     * cheap prefix checks), below the 1.5x gate, although uncommon batches reach 1.9-2.9x.
     * useW453RustFfi(true) keeps the Rust helper available for parity and benchmarks.
     * Named W453 so it stays distinct from the class's existing native paths.
     */
    public const bool W453_RUST_FFI_DEFAULT = false;

    public const string W453_FFI_CDEF = <<<'C'
        ssize_t nntmux_w453_categorization_passable_matched_by_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** CategorizationResult::noMatch() label. */
    public const string W453_NO_MATCH_LABEL = 'no_match';

    /**
     * Matched-by label prefixes, in match order.
     *
     * @var array<string, string>
     */
    public const array W453_LABEL_PREFIXES = [
        'group_name' => 'group_name_',
        'hash' => 'hash',
        'gibberish' => 'gibberish_',
    ];

    /** Substring marking a fallback categorizer label. */
    public const string W453_FALLBACK_MARKER = 'fallback';

    /** Exactly 8 0x1F-separated decimals of 1-19 digits. */
    private const string W453_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){7}\d{1,19}\z/';

    private static ?bool $w453RustFfiOverride = null;

    private static FFI|false|null $w453RustFfiHandle = null;

    public static bool $useRustFfi = false;

    public ReleaseContext $context;

    public CategorizationResult $bestResult;

    public bool $debug;

    /**
     * When true, the release has been identified as hashed/gibberish
     * and must remain in a misc category. No downstream pipe may override.
     */
    public bool $lockedToMisc = false;

    /**
     * @var array<string, mixed>
     */
    public array $allResults = [];

    /**
     * Misc signal analysis captured by MiscPipe for downstream safety-net decisions.
     *
     * @var array<string, mixed>
     */
    public array $miscAnalysis = [];

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function categorizeFfi(): ?FFI
    {
        return CategorizationService::categorizeFfi();
    }

    public function __construct(ReleaseContext $context, bool $debug = false)
    {
        $this->context = $context;
        $this->debug = $debug;
        $this->bestResult = CategorizationResult::noMatch();
    }

    /**
     * Lock this release to misc categories.
     *
     * Once locked, shouldStopProcessing() returns true and no downstream
     * pipe can assign a non-misc category.
     */
    public function lockToMisc(): void
    {
        $this->lockedToMisc = true;
    }

    /**
     * Check if we should stop processing (high confidence match found or locked to misc).
     */
    public function shouldStopProcessing(): bool
    {
        return self::$useRustFfi
            ? $this->shouldStopProcessingNative()
            : $this->shouldStopProcessingPhp();
    }

    public function shouldStopProcessingPhp(): bool
    {
        return $this->lockedToMisc || $this->bestResult->confidence >= 0.95;
    }

    public function shouldStopProcessingNative(): bool
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->shouldStopProcessingPhp();
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorize_passable_should_stop(
            $this->lockedToMisc ? 1 : 0,
            $this->bestResult->confidence
        );
        if (! \is_int($res) || $res < 0) {
            return $this->shouldStopProcessingPhp();
        }

        return $res === 1;
    }

    /**
     * Update the best result if the new result is better.
     */
    public function updateBestResult(CategorizationResult $result, string $categorizerName): void
    {
        if (self::$useRustFfi) {
            $this->updateBestResultNative($result, $categorizerName);

            return;
        }

        $this->updateBestResultPhp($result, $categorizerName);
    }

    public function updateBestResultPhp(CategorizationResult $result, string $categorizerName): void
    {
        if ($this->debug) {
            $this->allResults[$categorizerName] = [
                'category_id' => $result->categoryId,
                'confidence' => $result->confidence,
                'matched_by' => $result->matchedBy,
            ];
        }

        if ($result->isSuccessful() && $result->shouldOverride($this->bestResult)) {
            $this->bestResult = $result;
        }
    }

    public function updateBestResultNative(CategorizationResult $result, string $categorizerName): void
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            $this->updateBestResultPhp($result, $categorizerName);

            return;
        }

        if ($this->debug) {
            $this->allResults[$categorizerName] = [
                'category_id' => $result->categoryId,
                'confidence' => $result->confidence,
                'matched_by' => $result->matchedBy,
            ];
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorize_passable_should_update_best(
            $result->categoryId,
            $result->confidence,
            $result->matchedBy,
            \strlen($result->matchedBy),
            $this->bestResult->categoryId,
            $this->bestResult->confidence,
            $this->bestResult->matchedBy,
            \strlen($this->bestResult->matchedBy)
        );
        if (! \is_int($res) || $res < 0) {
            if ($result->isSuccessfulPhp() && $result->shouldOverridePhp($this->bestResult)) {
                $this->bestResult = $result;
            }

            return;
        }

        if ($res === 1) {
            $this->bestResult = $result;
        }
    }

    /**
     * Build the final result array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return self::$useRustFfi
            ? $this->toArrayNative()
            : $this->toArrayPhp();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayPhp(): array
    {
        $returnValue = ['categories_id' => $this->bestResult->categoryId];

        if ($this->debug) {
            $returnValue['debug'] = [
                'final_category' => $this->bestResult->categoryId,
                'final_confidence' => $this->bestResult->confidence,
                'matched_by' => $this->bestResult->matchedBy,
                'locked_to_misc' => $this->lockedToMisc,
                'release_name' => $this->context->releaseName,
                'group_name' => $this->context->groupName,
                'misc_analysis' => $this->miscAnalysis,
                'all_results' => $this->allResults,
                'categorizer_details' => $this->bestResult->debug,
            ];
        }

        return $returnValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayNative(): array
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->toArrayPhp();
        }

        $matchedBy = $this->bestResult->matchedBy;
        $releaseName = $this->context->releaseName;
        $groupName = $this->context->groupName;
        $mLen = \strlen($matchedBy);
        $rLen = \strlen($releaseName);
        $gLen = \strlen($groupName);

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $mLen + $rLen + $gLen + 16), $cap);
        if ($scratch === null) {
            return $this->toArrayPhp();
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_categorize_passable_pack_summary(
            $this->debug ? 1 : 0,
            $matchedBy,
            $mLen,
            $releaseName,
            $rLen,
            $groupName,
            $gLen,
            $scratch,
            $cap
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->toArrayPhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_categorize_passable_pack_summary(
                $this->debug ? 1 : 0,
                $matchedBy,
                $mLen,
                $releaseName,
                $rLen,
                $groupName,
                $gLen,
                $scratch,
                $cap
            );
        }
        if (! \is_int($written) || $written < 0) {
            return $this->toArrayPhp();
        }
        if ($written === 0) {
            return ['categories_id' => $this->bestResult->categoryId];
        }

        $parts = explode("\0", FFI::string($scratch, $written), 3);
        if (\count($parts) !== 3) {
            return $this->toArrayPhp();
        }

        return [
            'categories_id' => $this->bestResult->categoryId,
            'debug' => [
                'final_category' => $this->bestResult->categoryId,
                'final_confidence' => $this->bestResult->confidence,
                'matched_by' => $parts[0],
                'locked_to_misc' => $this->lockedToMisc,
                'release_name' => $parts[1],
                'group_name' => $parts[2],
                'misc_analysis' => $this->miscAnalysis,
                'all_results' => $this->allResults,
                'categorizer_details' => $this->bestResult->debug,
            ],
        ];
    }

    /**
     * Enables (true) or disables (false) the Wave 45.3 Rust helper; null restores
     * W453_RUST_FFI_DEFAULT.
     */
    public static function useW453RustFfi(?bool $enable = true): void
    {
        self::$w453RustFfiOverride = $enable;
    }

    public static function usesW453RustFfi(): bool
    {
        return self::$w453RustFfiOverride ?? self::W453_RUST_FFI_DEFAULT;
    }

    public static function resetW453RustFfi(): void
    {
        self::$w453RustFfiOverride = null;
        self::$w453RustFfiHandle = null;
    }

    /**
     * Matched-by label summary for a batch of best results: an empty label is empty, the exact
     * W453_NO_MATCH_LABEL is no_match, others add to their first W453_LABEL_PREFIXES class, else
     * to other. Labels holding W453_FALLBACK_MARKER also count as fallback. Non-strings count as
     * empty.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, empty: int, no_match: int, group_name: int, hash: int, gibberish: int, other: int, fallback: int}
     */
    public static function summarizeMatchedByBatch(array $items): array
    {
        return (self::usesW453RustFfi() ? self::summarizeMatchedByBatchNative($items) : null)
            ?? self::summarizeMatchedByBatchPhp($items);
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, empty: int, no_match: int, group_name: int, hash: int, gibberish: int, other: int, fallback: int}
     */
    public static function summarizeMatchedByBatchPhp(array $items): array
    {
        $counts = ['empty' => 0, 'no_match' => 0, 'group_name' => 0, 'hash' => 0, 'gibberish' => 0, 'other' => 0];
        $fallback = 0;
        foreach ($items as $item) {
            $label = \is_string($item) ? $item : '';
            $fallback += (int) str_contains($label, self::W453_FALLBACK_MARKER);
            if ($label === '') {
                $counts['empty']++;

                continue;
            }
            if ($label === self::W453_NO_MATCH_LABEL) {
                $counts['no_match']++;

                continue;
            }
            foreach (self::W453_LABEL_PREFIXES as $class => $prefix) {
                if (str_starts_with($label, $prefix)) {
                    $counts[$class]++;

                    continue 2;
                }
            }
            $counts['other']++;
        }

        return [
            'total' => \count($items),
            'empty' => $counts['empty'],
            'no_match' => $counts['no_match'],
            'group_name' => $counts['group_name'],
            'hash' => $counts['hash'],
            'gibberish' => $counts['gibberish'],
            'other' => $counts['other'],
            'fallback' => $fallback,
        ];
    }

    /**
     * Declines (null) for non-string items, and whenever Rust leaves a character class to PCRE2,
     * so PHP counts them.
     *
     * @param  array<array-key, mixed>  $items
     * @return array{total: int, empty: int, no_match: int, group_name: int, hash: int, gibberish: int, other: int, fallback: int}|null
     */
    public static function summarizeMatchedByBatchNative(array $items): ?array
    {
        $lengths = [];
        foreach ($items as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $fields = self::callW453Native(implode('', $items), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'empty' => (int) $fields[1],
            'no_match' => (int) $fields[2],
            'group_name' => (int) $fields[3],
            'hash' => (int) $fields[4],
            'gibberish' => (int) $fields[5],
            'other' => (int) $fields[6],
            'fallback' => (int) $fields[7],
        ];
    }

    /**
     * Calls this class's Wave 45.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W453_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW453Native(string $blob, string $lens): ?array
    {
        $ffi = self::w453RustFfi();
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
            $written = $ffi->nntmux_w453_categorization_passable_matched_by_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w453_categorization_passable_matched_by_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W453_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w453RustFfi(): ?FFI
    {
        if (self::$w453RustFfiHandle === null) {
            self::$w453RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w453', self::W453_FFI_CDEF) ?? false;
        }

        return self::$w453RustFfiHandle === false ? null : self::$w453RustFfiHandle;
    }

    /** Wave 53.2 paired benchmarks decide this default; see RustCategorizationPipesAbstractBookPassableMiscMovieW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    private const string W53_FFI_CDEF = <<<'C'
        intptr_t nntmux_w532_passable_best_result_plan_batch(const char *labels, size_t labels_len, const char *ints, size_t ints_len, const char *confidences, size_t confidences_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 53 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w53', self::W53_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w53RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW53RustFfi(): bool
    {
        $enabled = self::w53RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W53_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planBestResultBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
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

    /** Wave 53.2 best-row field value while a passable still holds CategorizationResult::noMatch(). */
    public const int W53_NO_BEST = 0xFFFFFF;

    /**
     * Batch replay of updateBestResult() as AbstractCategorizationPipe::handle() drives it. A new
     * passable (best = noMatch()) starts whenever the group id differs from the previous row's;
     * state bit 1 locks it to misc before the row; a row whose passable already stops (locked, or
     * best confidence at least 0.95) is skipped. One word per row: `flags | best << 8 |
     * crc32(matchedBy) << 32`, `best` being the row of the passable's current best result or
     * W53_NO_BEST. Flags: 1 successful (rows not skipped), 2 replaced the best result, 4 stops
     * after the row, 8 blocked by shouldOverride()'s protected misc guard, 16 protected misc
     * result, 32 skipped, 64 first row of a passable. Passables, replacements, guard blocks,
     * skips, passables that end stopped and the XXH64 digest of all earlier words follow.
     *
     * @param  list<int>  $categoryIds
     * @param  list<float>  $confidences
     * @param  list<string>  $matchedBy
     * @param  list<int>  $groups
     * @param  list<int>  $states
     * @return list<int>
     */
    public static function planBestResultBatch(array $categoryIds, array $confidences, array $matchedBy, array $groups, array $states): array
    {
        return self::usesW53RustFfi()
            ? self::planBestResultBatchNative($categoryIds, $confidences, $matchedBy, $groups, $states)
            : self::planBestResultBatchPhp($categoryIds, $confidences, $matchedBy, $groups, $states);
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<float>  $confidences
     * @param  list<string>  $matchedBy
     * @param  list<int>  $groups
     * @param  list<int>  $states
     * @return list<int>
     */
    public static function planBestResultBatchPhp(array $categoryIds, array $confidences, array $matchedBy, array $groups, array $states): array
    {
        [$categoryIds, $confidences, $groups, $states] = [array_values($categoryIds), array_values($confidences), array_values($groups), array_values($states)];
        $out = [];
        $passables = $replaced = $blocked = $skipped = $stopped = 0;
        $previous = null;
        $bestCategory = Category::OTHER_MISC;
        $bestConfidence = 0.0;
        $bestLabel = 'no_match';
        $bestRow = self::W53_NO_BEST;
        $locked = $stops = false;
        foreach (array_values($matchedBy) as $i => $label) {
            $category = $categoryIds[$i] ?? 0;
            $confidence = (float) ($confidences[$i] ?? 0.0);
            $group = $groups[$i] ?? 0;
            $flags = ($category === Category::OTHER_HASHED || ($category === Category::OTHER_MISC && (str_starts_with($label, 'hash_') || str_starts_with($label, 'obfuscated_')
                || str_starts_with($label, 'gibberish_') || $label === 'group_only_low_signal'))) ? 16 : 0;
            if ($group !== $previous) {
                $stopped += (int) ($previous !== null && $stops);
                $previous = $group;
                [$bestCategory, $bestConfidence, $bestLabel, $bestRow] = [Category::OTHER_MISC, 0.0, 'no_match', self::W53_NO_BEST];
                $locked = false;
                $passables++;
                $flags |= 64;
            }
            $locked = $locked || (($states[$i] ?? 0) & 1) !== 0;
            if ($locked || $bestConfidence >= 0.95) {
                $flags |= 32;
                $skipped++;
            } elseif (! ($confidence <= 0) && ! ($category === Category::OTHER_MISC && $label === 'no_match')) {
                $flags |= 1;
                if ($category !== Category::OTHER_MISC && $category !== Category::OTHER_HASHED && $confidence < 0.6
                    && ($bestCategory === Category::OTHER_HASHED || ($bestCategory === Category::OTHER_MISC && (str_starts_with($bestLabel, 'hash_') || str_starts_with($bestLabel, 'obfuscated_')
                || str_starts_with($bestLabel, 'gibberish_') || $bestLabel === 'group_only_low_signal')))) {
                    $flags |= 8;
                    $blocked++;
                } elseif ($confidence > $bestConfidence
                    || ($confidence === $bestConfidence && $category !== Category::OTHER_MISC && $bestCategory === Category::OTHER_MISC)) {
                    [$bestCategory, $bestConfidence, $bestLabel, $bestRow] = [$category, $confidence, $label, $i];
                    $flags |= 2;
                    $replaced++;
                }
            }
            $stops = $locked || $bestConfidence >= 0.95;
            $out[] = $flags | ($stops ? 4 : 0) | $bestRow << 8 | crc32($label) << 32;
        }
        $stopped += (int) ($previous !== null && $stops);
        array_push($out, $passables, $replaced, $blocked, $skipped, $stopped);
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * are not lists, or a label holds a NUL byte.
     *
     * @param  list<int>  $categoryIds
     * @param  list<float>  $confidences
     * @param  list<string>  $matchedBy
     * @param  list<int>  $groups
     * @param  list<int>  $states
     * @return list<int>
     */
    public static function planBestResultBatchNative(array $categoryIds, array $confidences, array $matchedBy, array $groups, array $states): array
    {
        $count = \count($matchedBy);
        $words = $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w53RustFfiState('out', $words);
        if ($state === null || \count($categoryIds) !== $count || \count($confidences) !== $count || \count($groups) !== $count || \count($states) !== $count
            || ! array_is_list($matchedBy) || ! array_is_list($categoryIds) || ! array_is_list($confidences) || ! array_is_list($groups) || ! array_is_list($states)) {
            return self::planBestResultBatchPhp($categoryIds, $confidences, $matchedBy, $groups, $states);
        }
        $labels = implode("\0", $matchedBy);
        $ints = pack('P*', ...$categoryIds, ...$groups, ...$states);
        $packed = pack('e*', ...$confidences);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w532_passable_best_result_plan_batch($labels, \strlen($labels), $ints, \strlen($ints), $packed, \strlen($packed), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planBestResultBatchPhp($categoryIds, $confidences, $matchedBy, $groups, $states);
    }
}
