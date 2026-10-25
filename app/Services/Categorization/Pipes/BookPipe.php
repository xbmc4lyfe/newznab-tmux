<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\BookCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Pipe for Book content categorization.
 */
class BookPipe extends AbstractCategorizationPipe
{
    /**
     * Wave 40.3 production default is PHP: the paired benchmark in
     * tests/Unit/RustCategorizationServiceGroupNameFixerW40ParityTest.php measures only
     * 0.98-1.00x mean / 0.91-1.01x p99 on the common batch, below the 1.5x adoption threshold, so Rust
     * stays behind useW403RustFfi(true). Named W403 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W403_RUST_FFI_DEFAULT = false;

    public const string W403_FFI_CDEF = <<<'C'
        ssize_t nntmux_w403_name_fix_batch_progress(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            int64_t max_fixes,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const int DEFAULT_MAX_FIXES = 500;

    public const string OUTCOME_NFO = 'nfo';

    public const string OUTCOME_FILES = 'files';

    public const string OUTCOME_PAR2 = 'par2';

    public const string OUTCOME_PREDB = 'predb';

    public const string OUTCOME_UNFIXABLE = 'unfixable';

    /** @var array<string, int<0, 4>> */
    private const array OUTCOME_INDEX = [
        self::OUTCOME_NFO => 0,
        self::OUTCOME_FILES => 1,
        self::OUTCOME_PAR2 => 2,
        self::OUTCOME_PREDB => 3,
        self::OUTCOME_UNFIXABLE => 4,
    ];

    private static ?bool $w403RustFfiOverride = null;

    private static FFI|false|null $w403RustFfiHandle = null;

    public const string PIPE_SPEC_FFI_CDEF = <<<'C'
        ssize_t nntmux_categorize_pipes_spec_name(uint8_t kind, char *out_buf, size_t out_cap);
        int64_t nntmux_categorize_pipes_spec_priority(uint8_t kind);
        int32_t nntmux_categorize_pipes_spec_evaluate_result(uint8_t kind, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len);
        C;

    /** The result passes `CategorizationResult::isSuccessful()`. */
    public const int RESULT_SUCCESSFUL = 1;

    /** The category id lies in this pipe's category family. */
    public const int RESULT_IN_DOMAIN = 2;

    /** The confidence reaches the `CategorizationPassable` stop threshold. */
    public const int RESULT_TERMINAL = 4;

    private const int PIPE_SPEC_KIND = 0;

    private const string PIPE_NAME = 'Book';

    private const int PIPE_PRIORITY = 45;

    // Public because the parent declares it public; a private redeclaration is a fatal error.
    public static bool $useRustFfi = false;

    private static FFI|false|null $pipeSpecFfi = null;

    protected int $priority = self::PIPE_PRIORITY;

    private BookCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new BookCategorizer;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setPipeSpecFfiForTesting(FFI|false|null $ffi): void
    {
        self::$pipeSpecFfi = $ffi;
    }

    public function getName(): string
    {
        return self::pipeName();
    }

    public static function pipeName(): string
    {
        return self::$useRustFfi ? self::pipeNameNative() : self::pipeNamePhp();
    }

    public static function pipeNamePhp(): string
    {
        return self::PIPE_NAME;
    }

    public static function pipeNameNative(): string
    {
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::pipeNamePhp();
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 16, $cap);
            if ($scratch === null) {
                return self::pipeNamePhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_categorize_pipes_spec_name(self::PIPE_SPEC_KIND, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
                return self::pipeNamePhp();
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::pipeNamePhp();
        }
    }

    public static function pipePriority(): int
    {
        return self::$useRustFfi ? self::pipePriorityNative() : self::pipePriorityPhp();
    }

    public static function pipePriorityPhp(): int
    {
        return self::PIPE_PRIORITY;
    }

    public static function pipePriorityNative(): int
    {
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::pipePriorityPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $priority = $ffi->nntmux_categorize_pipes_spec_priority(self::PIPE_SPEC_KIND);
        } catch (Throwable) {
            return self::pipePriorityPhp();
        }

        return \is_int($priority) && $priority >= 0 ? $priority : self::pipePriorityPhp();
    }

    /**
     * Evaluate a categorization result against this pipe as a `RESULT_*` bit mask.
     */
    public static function evaluateResult(int $categoryId, float $confidence, string $matchedBy): int
    {
        return self::$useRustFfi
            ? self::evaluateResultNative($categoryId, $confidence, $matchedBy)
            : self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
    }

    public static function evaluateResultPhp(int $categoryId, float $confidence, string $matchedBy): int
    {
        $mask = 0;
        if (! ($confidence <= 0) && ! ($categoryId === Category::OTHER_MISC && $matchedBy === 'no_match')) {
            $mask |= self::RESULT_SUCCESSFUL;
        }
        if ($categoryId >= Category::BOOKS_ROOT && $categoryId <= Category::BOOKS_ROOT + 999) {
            $mask |= self::RESULT_IN_DOMAIN;
        }
        if ($confidence >= 0.95) {
            $mask |= self::RESULT_TERMINAL;
        }

        return $mask;
    }

    public static function evaluateResultNative(int $categoryId, float $confidence, string $matchedBy): int
    {
        $ffi = self::pipeSpecFfi();
        if ($ffi === null) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_categorize_pipes_spec_evaluate_result(
                self::PIPE_SPEC_KIND,
                $categoryId,
                $confidence,
                $matchedBy,
                \strlen($matchedBy)
            );
        } catch (Throwable) {
            return self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
        }

        return \is_int($mask) && $mask >= 0 ? $mask : self::evaluateResultPhp($categoryId, $confidence, $matchedBy);
    }

    protected function shouldSkip(ReleaseContext $context): bool
    {
        return $this->categorizer->shouldSkip($context);
    }

    protected function categorize(ReleaseContext $context): CategorizationResult
    {
        return $this->categorizer->categorize($context);
    }

    private static function pipeSpecFfi(): ?FFI
    {
        if (self::$pipeSpecFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            try {
                self::$pipeSpecFfi = RustPipelineLibrary::resolveDomain(
                    'categorize',
                    self::PIPE_SPEC_FFI_CDEF,
                    \is_string($path) && $path !== '' ? $path : null
                ) ?? false;
            } catch (Throwable) {
                self::$pipeSpecFfi = false;
            }
        }

        return self::$pipeSpecFfi === false ? null : self::$pipeSpecFfi;
    }

    public static function useW403RustFfi(?bool $enabled): void
    {
        self::$w403RustFfiOverride = $enabled;
    }

    public static function usesW403RustFfi(): bool
    {
        return self::$w403RustFfiOverride ?? self::W403_RUST_FFI_DEFAULT;
    }

    public static function resetW403RustFfi(): void
    {
        self::$w403RustFfiOverride = null;
        self::$w403RustFfiHandle = null;
    }

    /**
     * Progress summary for a batch of NameFixingService pass outcomes. Each outcome is trimmed (PHP's default
     * set) and lowercased, then matched against the OUTCOME_* labels; anything else, including
     * non-strings, is unknown. The fix limit is max(1, $maxFixes); it is reached once the nfo, files,
     * par2 and predb fixes together reach it.
     *
     * @param  array<array-key, mixed>  $outcomes
     * @return array{inspected: int, fixed_nfo: int, fixed_files: int, fixed_par2: int, fixed_predb: int, fixed_total: int, unfixable: int, unknown: int, limit_reached: bool, remaining_budget: int, max_fixes: int}
     */
    public static function summarizeNameFixBatchProgress(array $outcomes, int $maxFixes = self::DEFAULT_MAX_FIXES): array
    {
        return (self::usesW403RustFfi() ? self::summarizeNameFixBatchProgressNative($outcomes, $maxFixes) : null)
            ?? self::summarizeNameFixBatchProgressPhp($outcomes, $maxFixes);
    }

    /**
     * @param  array<array-key, mixed>  $outcomes
     * @return array{inspected: int, fixed_nfo: int, fixed_files: int, fixed_par2: int, fixed_predb: int, fixed_total: int, unfixable: int, unknown: int, limit_reached: bool, remaining_budget: int, max_fixes: int}
     */
    public static function summarizeNameFixBatchProgressPhp(array $outcomes, int $maxFixes = self::DEFAULT_MAX_FIXES): array
    {
        $counts = [0, 0, 0, 0, 0];
        $unknown = 0;
        foreach ($outcomes as $outcome) {
            $index = self::OUTCOME_INDEX[strtolower(trim(\is_string($outcome) ? $outcome : ''))] ?? null;
            if ($index === null) {
                $unknown++;
            } else {
                $counts[$index]++;
            }
        }
        $max = max(1, $maxFixes);
        $fixed = $counts[0] + $counts[1] + $counts[2] + $counts[3];

        return [
            'inspected' => \count($outcomes),
            'fixed_nfo' => $counts[0],
            'fixed_files' => $counts[1],
            'fixed_par2' => $counts[2],
            'fixed_predb' => $counts[3],
            'fixed_total' => $fixed,
            'unfixable' => $counts[4],
            'unknown' => $unknown,
            'limit_reached' => $fixed >= $max,
            'remaining_budget' => max(0, $max - $fixed),
            'max_fixes' => $max,
        ];
    }

    /**
     * Declines (null) for non-string outcomes so PHP counts them.
     *
     * @param  array<array-key, mixed>  $outcomes
     * @return array{inspected: int, fixed_nfo: int, fixed_files: int, fixed_par2: int, fixed_predb: int, fixed_total: int, unfixable: int, unknown: int, limit_reached: bool, remaining_budget: int, max_fixes: int}|null
     */
    public static function summarizeNameFixBatchProgressNative(array $outcomes, int $maxFixes = self::DEFAULT_MAX_FIXES): ?array
    {
        $lengths = [];
        foreach ($outcomes as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $outcomes);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW403Native('nntmux_w403_name_fix_batch_progress', [$blob, \strlen($blob), $lens, \strlen($lens), $maxFixes], 128, 11);
        if ($fields === null || ($fields[8] !== '0' && $fields[8] !== '1')) {
            return null;
        }

        return [
            'inspected' => (int) $fields[0],
            'fixed_nfo' => (int) $fields[1],
            'fixed_files' => (int) $fields[2],
            'fixed_par2' => (int) $fields[3],
            'fixed_predb' => (int) $fields[4],
            'fixed_total' => (int) $fields[5],
            'unfixable' => (int) $fields[6],
            'unknown' => (int) $fields[7],
            'limit_reached' => $fields[8] === '1',
            'remaining_budget' => (int) $fields[9],
            'max_fixes' => (int) $fields[10],
        ];
    }

    /**
     * Calls a Wave 40.3 export with the shared scratch buffer, retrying once at the size it asks for.
     * Returns its fields only when there are exactly $fieldCount, all of them decimal.
     *
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callW403Native(string $symbol, array $args, int $minBytes, int $fieldCount): ?array
    {
        $ffi = self::w403RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $fields = explode("\x1F", FFI::string($buffer, $written));
            if (\count($fields) !== $fieldCount) {
                return null;
            }
            foreach ($fields as $field) {
                if (\strlen($field) > 19 || ! ctype_digit($field)) {
                    return null;
                }
            }

            return $fields;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w403RustFfi(): ?FFI
    {
        if (self::$w403RustFfiHandle === null) {
            self::$w403RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w403', self::W403_FFI_CDEF) ?? false;
        }

        return self::$w403RustFfiHandle === false ? null : self::$w403RustFfiHandle;
    }

    /** Wave 53.2 paired benchmarks decide this default; see RustCategorizationPipesAbstractBookPassableMiscMovieW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    private const string W53_FFI_CDEF = <<<'C'
        intptr_t nntmux_w532_book_result_plan_batch(const char *labels, size_t labels_len, const char *categories, size_t categories_len, const char *confidences, size_t confidences_len, size_t count, int64_t *out, size_t out_cap);
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
     * Runs $callback with planBookResultBatch() routed to Rust ($enabled) or PHP, then restores the
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

    /** Wave 53.2 sub-category histogram slots (0 out of domain, 7 other in-domain ids). */
    private const array W53_SUBCATEGORIES = [
        Category::BOOKS_MAGAZINES => 1,
        Category::BOOKS_EBOOK => 2,
        Category::BOOKS_COMICS => 3,
        Category::BOOKS_TECHNICAL => 4,
        Category::BOOKS_FOREIGN => 5,
        Category::BOOKS_UNKNOWN => 6,
    ];

    /** Wave 53.2 matched-by families in match order: prefix => [index, category the family assigns]. */
    private const array W53_FAMILIES = [
        'comic_' => [1, Category::BOOKS_COMICS],
        'manga' => [2, Category::BOOKS_COMICS],
        'technical_' => [3, Category::BOOKS_TECHNICAL],
        'magazine_' => [4, Category::BOOKS_MAGAZINES],
        'ebook_' => [5, Category::BOOKS_EBOOK],
    ];

    /**
     * Batch result plan for Book results, as evaluateResultPhp() grades them. One word per
     * result: `mask | sub << 8 | family << 16 | crc32(matchedBy) << 32`. Mask: RESULT_SUCCESSFUL,
     * RESULT_IN_DOMAIN and RESULT_TERMINAL, plus 8 when the matched-by family's category equals
     * the result's. `sub` is the W53_SUBCATEGORIES slot (7 other in-domain ids, 0 out of
     * domain); `family` the first W53_FAMILIES prefix of matchedBy (0 none). Successful,
     * in-domain, terminal and agreeing counts, the first terminal row (-1 none), the 8-slot
     * sub-category histogram and the XXH64 digest of all earlier words follow.
     *
     * @param  list<int>  $categoryIds
     * @param  list<float>  $confidences
     * @param  list<string>  $matchedBy
     * @return list<int>
     */
    public static function planBookResultBatch(array $categoryIds, array $confidences, array $matchedBy): array
    {
        return self::usesW53RustFfi()
            ? self::planBookResultBatchNative($categoryIds, $confidences, $matchedBy)
            : self::planBookResultBatchPhp($categoryIds, $confidences, $matchedBy);
    }

    /**
     * @param  list<int>  $categoryIds
     * @param  list<float>  $confidences
     * @param  list<string>  $matchedBy
     * @return list<int>
     */
    public static function planBookResultBatchPhp(array $categoryIds, array $confidences, array $matchedBy): array
    {
        [$categoryIds, $confidences] = [array_values($categoryIds), array_values($confidences)];
        $out = [];
        $ok = $domain = $terminal = $agree = 0;
        $firstTerminal = -1;
        $histogram = [0, 0, 0, 0, 0, 0, 0, 0];
        foreach (array_values($matchedBy) as $i => $label) {
            $category = $categoryIds[$i] ?? 0;
            $confidence = (float) ($confidences[$i] ?? 0.0);
            $success = ! ($confidence <= 0) && ! ($category === Category::OTHER_MISC && $label === 'no_match');
            $inDomain = $category >= Category::BOOKS_ROOT && $category <= Category::BOOKS_ROOT + 999;
            $stop = $confidence >= 0.95;
            $sub = self::W53_SUBCATEGORIES[$category] ?? ($inDomain ? 7 : 0);
            $family = 0;
            $matches = false;
            foreach (self::W53_FAMILIES as $prefix => [$index, $familyCategory]) {
                if (str_starts_with($label, $prefix)) {
                    $family = $index;
                    $matches = $familyCategory === $category;
                    break;
                }
            }
            $ok += (int) $success;
            $domain += (int) $inDomain;
            $terminal += (int) $stop;
            $agree += (int) $matches;
            if ($stop && $firstTerminal < 0) {
                $firstTerminal = $i;
            }
            $histogram[$sub]++;
            $out[] = ($success ? self::RESULT_SUCCESSFUL : 0) | ($inDomain ? self::RESULT_IN_DOMAIN : 0) | ($stop ? self::RESULT_TERMINAL : 0) | ($matches ? 8 : 0)
                | $sub << 8 | $family << 16 | crc32($label) << 32;
        }
        array_push($out, $ok, $domain, $terminal, $agree, $firstTerminal, ...$histogram);
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
     * @return list<int>
     */
    public static function planBookResultBatchNative(array $categoryIds, array $confidences, array $matchedBy): array
    {
        $count = \count($matchedBy);
        $words = $count + 14;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w53RustFfiState('out', $words);
        if ($state === null || \count($categoryIds) !== $count || \count($confidences) !== $count
            || ! array_is_list($matchedBy) || ! array_is_list($categoryIds) || ! array_is_list($confidences)) {
            return self::planBookResultBatchPhp($categoryIds, $confidences, $matchedBy);
        }
        $labels = implode("\0", $matchedBy);
        $categories = pack('P*', ...$categoryIds);
        $packed = pack('e*', ...$confidences);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w532_book_result_plan_batch($labels, \strlen($labels), $categories, \strlen($categories), $packed, \strlen($packed), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planBookResultBatchPhp($categoryIds, $confidences, $matchedBy);
    }
}
