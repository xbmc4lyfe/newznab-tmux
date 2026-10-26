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

    /** C signature of the Wave 66.5 {@see self::w66CategorizationPassablePlanBatch()} helper. */
    private const string W66_CATEGORIZATION_PASSABLE_PLAN_FFI_CDEF = 'int64_t nntmux_w665_categorization_passable_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 66.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 14.87/12.44/13.38/10.91x); see tests/Unit/RustCategorizationBookPassableConsoleGroupNameMiscPipeW66ParityTest.php.
     */
    public const bool W66_RUST_FFI_DEFAULT = true;

    /**
     * Wave 66.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w66_5', self::W66_CATEGORIZATION_PASSABLE_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W66_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 66.5 plan batch to Rust until disabled or reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Route the Wave 66.5 plan batch to PHP until enabled or reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restore W66_RUST_FFI_DEFAULT routing and drop the cached Wave 66.5 handle and buffer. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('set', null);
        self::w66RustFfiState('reset', null);
    }

    public static function usesW66RustFfi(): bool
    {
        return self::w66RustFfiState() === true;
    }

    /**
     * Runs $callback with w66CategorizationPassablePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 66.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w66Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w66RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w665_categorization_passable_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w66Number(string $field, int $default, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /** `CategorizationResult::isProtectedMiscResultPhp()` for a category id and matched-by label. */
    private static function w66ProtectedMisc(int $category, string $label): bool
    {
        return $category === Category::OTHER_HASHED || ($category === Category::OTHER_MISC && (str_starts_with($label, 'hash_')
            || str_starts_with($label, 'obfuscated_') || str_starts_with($label, 'gibberish_') || $label === 'group_only_low_signal'));
    }

    /**
     * `CategorizationResult::shouldOverridePhp()` on permille confidences: 2 blocked by the protected-misc guard,
     * 1 replaces the best result, 0 keeps it.
     */
    private static function w66OverrideDecision(int $category, int $confidence, int $bestCategory, int $bestConfidence, string $bestLabel): int
    {
        if ($category !== Category::OTHER_MISC && $category !== Category::OTHER_HASHED && $confidence < 600
            && self::w66ProtectedMisc($bestCategory, $bestLabel)) {
            return 2;
        }

        return $confidence > $bestConfidence || ($confidence === $bestConfidence && $category !== Category::OTHER_MISC && $bestCategory === Category::OTHER_MISC) ? 1 : 0;
    }

    /**
     * Batch passable replay over `locked\tbestCategory\tbestConfidence\tbestMatchedBy\tcandidates`, confidences in
     * permille; candidates are `|`-joined `category:confidence:matchedBy` (the label keeps later colons, empty items are
     * ignored, items without two colons set error 4). Like updateBestResultPhp() gated by shouldStopProcessingPhp(): a
     * candidate is skipped while the passable is locked (non-zero) or its best confidence reaches 950; otherwise it is
     * considered and, when successful, blocked by shouldOverridePhp()'s protected-misc guard or replaces the best result
     * when it overrides it. Nine words per record: `[bestCategory, bestConfidence, considered, replaced, blocked,
     * skipped, firstSkipped, crc32(bestMatchedBy), errors]` with the final best result (default OTHER_MISC, 0, '') and
     * the first skipped candidate's index (-1 none). Numbers are trimmed 1-15 ASCII digits; errors: 1 invalid number,
     * 2 not exactly five fields, 4 malformed candidate, 8 locked, 16 final protected misc result, 32 final result stops
     * processing.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66CategorizationPassablePlanBatch(array $records): array
    {
        return self::usesW66RustFfi() ? self::w66CategorizationPassablePlanBatchNative($records) : self::w66CategorizationPassablePlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66CategorizationPassablePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w66Words($count, implode("\0", $records), 9 * $count) ?? self::w66CategorizationPassablePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w66CategorizationPassablePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $errors = \count($fields) === 5 ? 0 : 2;
            $locked = self::w66Number($fields[0], 0, $errors) !== 0;
            $bestCategory = self::w66Number($fields[1] ?? '', Category::OTHER_MISC, $errors);
            $bestConfidence = self::w66Number($fields[2] ?? '', 0, $errors);
            $bestLabel = $fields[3] ?? '';
            $candidates = $fields[4] ?? '';
            $considered = $replaced = $blocked = $skipped = $index = 0;
            $firstSkipped = -1;
            foreach ($candidates === '' ? [] : explode('|', $candidates) as $item) {
                if ($item === '') {
                    continue;
                }
                $parts = explode(':', $item, 3);
                if (\count($parts) < 3) {
                    $errors |= 4;

                    continue;
                }
                $category = self::w66Number($parts[0], 0, $errors);
                $confidence = self::w66Number($parts[1], 0, $errors);
                $label = $parts[2];
                if ($locked || $bestConfidence >= 950) {
                    $skipped++;
                    if ($firstSkipped < 0) {
                        $firstSkipped = $index;
                    }
                } else {
                    $considered++;
                    if ($confidence > 0 && ! ($category === Category::OTHER_MISC && $label === 'no_match')) {
                        $decision = self::w66OverrideDecision($category, $confidence, $bestCategory, $bestConfidence, $bestLabel);
                        if ($decision === 2) {
                            $blocked++;
                        } elseif ($decision === 1) {
                            $replaced++;
                            [$bestCategory, $bestConfidence, $bestLabel] = [$category, $confidence, $label];
                        }
                    }
                }
                $index++;
            }
            $errors |= ($locked ? 8 : 0) | (self::w66ProtectedMisc($bestCategory, $bestLabel) ? 16 : 0) | ($locked || $bestConfidence >= 950 ? 32 : 0);
            array_push($out, $bestCategory, $bestConfidence, $considered, $replaced, $blocked, $skipped, $firstSkipped, crc32($bestLabel), $errors);
        }

        return $out;
    }
}
