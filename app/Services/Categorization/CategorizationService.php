<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Support\RustPipelineLibrary;
use Blacklight\Categorize;
use FFI;
use FFI\CData;

/**
 * Categorization service using Laravel Pipeline.
 *
 * This class is a drop-in replacement for the legacy Blacklight\Categorize
 * with additional features like confidence scoring and debug information.
 */
class CategorizationService
{
    /**
     * Wave 40.3 production default is PHP: the paired benchmark in
     * tests/Unit/RustCategorizationServiceGroupNameFixerW40ParityTest.php measures only
     * 1.21-1.24x mean / 1.03-1.22x p99 on the common batch, below the 1.5x adoption threshold, so Rust
     * stays behind useW403RustFfi(true). Named W403 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W403_RUST_FFI_DEFAULT = false;

    public const string W403_FFI_CDEF = <<<'C'
        ssize_t nntmux_w403_category_distribution(
            const char *values_ptr,
            size_t values_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Category IDs from 0 through this value fall in a major root bucket; others are invalid. */
    public const int W403_MAX_CATEGORY_ID = 8999;

    private static ?bool $w403RustFfiOverride = null;

    private static FFI|false|null $w403RustFfiHandle = null;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_categorize_passable_should_stop(uint32_t locked_to_misc, double confidence);
        int32_t nntmux_categorize_passable_should_update_best(int64_t cand_cat_id, double cand_conf, const char *cand_matched_ptr, size_t cand_matched_len, int64_t best_cat_id, double best_conf, const char *best_matched_ptr, size_t best_matched_len);
        ssize_t nntmux_categorize_passable_pack_summary(uint32_t debug, const char *matched_by_ptr, size_t matched_by_len, const char *release_name_ptr, size_t release_name_len, const char *group_name_ptr, size_t group_name_len, char *out_buf, size_t out_cap);
        int32_t nntmux_categorize_misc_pipe_should_lock(int64_t category_id, const char *matched_by_ptr, size_t matched_by_len);
        int32_t nntmux_categorize_safety_net_should_downgrade(uint32_t locked_to_misc, uint32_t low_signal, const char *matched_by_ptr, size_t matched_by_len);
        ssize_t nntmux_categorize_service_compare_summary(int64_t pipeline_cat_id, uint32_t has_pipeline_title, const char *pipeline_title_ptr, size_t pipeline_title_len, int64_t legacy_cat_id, uint32_t has_legacy_title, const char *legacy_title_ptr, size_t legacy_title_len, uint32_t legacy_state, char *out_buf, size_t out_cap);
        int32_t nntmux_category_is_parent_id(int64_t cid);
        int32_t nntmux_category_is_parent_str(const char *input_ptr, size_t input_len);
        ssize_t nntmux_category_others_group(char *out_buf, size_t out_cap);
        int32_t nntmux_category_value(const char *name_ptr, size_t name_len);
        ssize_t nntmux_usenet_group_normalize_valid(const char *name_ptr, size_t name_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_usenet_group_format_status_message(int64_t id, int64_t status, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected CategorizationPipeline $pipeline;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function categorizeFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(?CategorizationPipeline $pipeline = null)
    {
        $this->pipeline = $pipeline ?? CategorizationPipeline::createDefault();
    }

    /**
     * Determine category for a release.
     *
     * @param  int|string  $groupId  The usenet group ID
     * @param  string  $releaseName  The name of the release
     * @param  string|null  $poster  The poster name
     * @param  bool  $debug  Whether to include debug information
     * @return array<string, mixed> The categorization result with category ID and optional debug info
     */
    public function determineCategory(
        int|string $groupId,
        string $releaseName = '',
        ?string $poster = '',
        bool $debug = false
    ): array {
        return $this->pipeline->categorize($groupId, $releaseName, $poster, $debug);
    }

    /**
     * Batch categorize multiple releases.
     *
     * @param  array<string, mixed>  $releases  Array of ['group_id' => x, 'name' => y, 'poster' => z]
     * @return array<string, mixed> Array of categorization results
     */
    public function batchCategorize(array $releases): array
    {
        $results = [];

        foreach ($releases as $release) {
            $groupId = $release['group_id'] ?? $release['groupId'] ?? 0;
            $name = $release['name'] ?? $release['releaseName'] ?? '';
            $poster = $release['poster'] ?? '';

            $results[] = [
                'release' => $release,
                'result' => $this->determineCategory($groupId, $name, $poster),
            ];
        }

        return $results;
    }

    /**
     * Get the underlying pipeline.
     *
     * @return list<array<string, mixed>>
     */
    public function getPipeline(): CategorizationPipeline
    {
        return $this->pipeline;
    }

    /**
     * Add a custom categorizer pipe to the pipeline.
     */
    public function addCategorizer(AbstractCategorizationPipe $pipe): self
    {
        $this->pipeline->addCategorizer($pipe);

        return $this;
    }

    /**
     * Compare pipeline categorization against the legacy Blacklight\Categorize class.
     *
     * Returns an array with 'pipeline', 'legacy', and 'match' keys so callers
     * can verify the new pipeline produces the same results as the old categorizer.
     *
     * @param  int|string  $groupId  The usenet group ID
     * @param  string  $releaseName  The name of the release
     * @return array{pipeline: array{category_id: int, category_name: string}, legacy: array{category_id: int, category_name: string}, match: bool}
     */
    public function compare(int|string $groupId, string $releaseName): array
    {
        return self::$useRustFfi
            ? $this->compareNative($groupId, $releaseName)
            : $this->comparePhp($groupId, $releaseName);
    }

    /**
     * @return array{pipeline: array{category_id: int, category_name: string}, legacy: array{category_id: int, category_name: string}, match: bool}
     */
    public function comparePhp(int|string $groupId, string $releaseName): array
    {
        [$pipelineCategoryId, $pipelineCategoryTitle, $legacyCategoryId, $legacyCategoryTitle, $legacyState] = $this->resolveComparisonInputs($groupId, $releaseName);

        return $this->buildComparisonResultPhp(
            $pipelineCategoryId,
            $pipelineCategoryTitle,
            $legacyCategoryId,
            $legacyCategoryTitle,
            $legacyState
        );
    }

    /**
     * @return array{pipeline: array{category_id: int, category_name: string}, legacy: array{category_id: int, category_name: string}, match: bool}
     */
    public function compareNative(int|string $groupId, string $releaseName): array
    {
        [$pipelineCategoryId, $pipelineCategoryTitle, $legacyCategoryId, $legacyCategoryTitle, $legacyState] = $this->resolveComparisonInputs($groupId, $releaseName);

        return $this->buildComparisonResultNative(
            $pipelineCategoryId,
            $pipelineCategoryTitle,
            $legacyCategoryId,
            $legacyCategoryTitle,
            $legacyState
        );
    }

    /**
     * Build the comparison diff array between pipeline and legacy categorization results.
     *
     * @return array{pipeline: array{category_id: int, category_name: string}, legacy: array{category_id: int, category_name: string}, match: bool}
     */
    public function buildComparisonResult(
        int $pipelineCategoryId,
        ?string $pipelineCategoryTitle,
        int $legacyCategoryId,
        ?string $legacyCategoryTitle,
        int $legacyState = 0
    ): array {
        return self::$useRustFfi
            ? $this->buildComparisonResultNative(
                $pipelineCategoryId,
                $pipelineCategoryTitle,
                $legacyCategoryId,
                $legacyCategoryTitle,
                $legacyState
            )
            : $this->buildComparisonResultPhp(
                $pipelineCategoryId,
                $pipelineCategoryTitle,
                $legacyCategoryId,
                $legacyCategoryTitle,
                $legacyState
            );
    }

    /**
     * @return array{pipeline: array{category_id: int, category_name: string}, legacy: array{category_id: int, category_name: string}, match: bool}
     */
    public function buildComparisonResultPhp(
        int $pipelineCategoryId,
        ?string $pipelineCategoryTitle,
        int $legacyCategoryId,
        ?string $legacyCategoryTitle,
        int $legacyState = 0
    ): array {
        $pipelineCategoryName = $pipelineCategoryTitle !== null ? $pipelineCategoryTitle : 'Unknown';
        $legacyCategoryName = match ($legacyState) {
            1 => 'Legacy error',
            2 => 'Legacy unavailable',
            default => $legacyCategoryTitle !== null ? $legacyCategoryTitle : 'Unknown',
        };

        return [
            'pipeline' => [
                'category_id' => $pipelineCategoryId,
                'category_name' => $pipelineCategoryName,
            ],
            'legacy' => [
                'category_id' => $legacyCategoryId,
                'category_name' => $legacyCategoryName,
            ],
            'match' => $pipelineCategoryId === $legacyCategoryId,
        ];
    }

    /**
     * @return array{pipeline: array{category_id: int, category_name: string}, legacy: array{category_id: int, category_name: string}, match: bool}
     */
    public function buildComparisonResultNative(
        int $pipelineCategoryId,
        ?string $pipelineCategoryTitle,
        int $legacyCategoryId,
        ?string $legacyCategoryTitle,
        int $legacyState = 0
    ): array {
        if (! \in_array($legacyState, [0, 1, 2], true)) {
            $legacyState = 0;
        }
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->buildComparisonResultPhp(
                $pipelineCategoryId,
                $pipelineCategoryTitle,
                $legacyCategoryId,
                $legacyCategoryTitle,
                $legacyState
            );
        }

        $pStr = $pipelineCategoryTitle ?? '';
        $lStr = $legacyCategoryTitle ?? '';
        $pLen = \strlen($pStr);
        $lLen = \strlen($lStr);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $pLen + $lLen + 64), $cap);
        if ($scratch === null) {
            return $this->buildComparisonResultPhp(
                $pipelineCategoryId,
                $pipelineCategoryTitle,
                $legacyCategoryId,
                $legacyCategoryTitle,
                $legacyState
            );
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_categorize_service_compare_summary(
            $pipelineCategoryId,
            $pipelineCategoryTitle !== null ? 1 : 0,
            $pStr,
            $pLen,
            $legacyCategoryId,
            $legacyCategoryTitle !== null ? 1 : 0,
            $lStr,
            $lLen,
            $legacyState,
            $scratch,
            $cap
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->buildComparisonResultPhp(
                    $pipelineCategoryId,
                    $pipelineCategoryTitle,
                    $legacyCategoryId,
                    $legacyCategoryTitle,
                    $legacyState
                );
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_categorize_service_compare_summary(
                $pipelineCategoryId,
                $pipelineCategoryTitle !== null ? 1 : 0,
                $pStr,
                $pLen,
                $legacyCategoryId,
                $legacyCategoryTitle !== null ? 1 : 0,
                $lStr,
                $lLen,
                $legacyState,
                $scratch,
                $cap
            );
        }
        if (! \is_int($written) || $written < 2) {
            return $this->buildComparisonResultPhp(
                $pipelineCategoryId,
                $pipelineCategoryTitle,
                $legacyCategoryId,
                $legacyCategoryTitle,
                $legacyState
            );
        }

        $raw = FFI::string($scratch, $written);
        $sep = strpos($raw, "\0", 1);
        if ($sep === false) {
            return $this->buildComparisonResultPhp(
                $pipelineCategoryId,
                $pipelineCategoryTitle,
                $legacyCategoryId,
                $legacyCategoryTitle,
                $legacyState
            );
        }

        return [
            'pipeline' => [
                'category_id' => $pipelineCategoryId,
                'category_name' => substr($raw, 1, $sep - 1),
            ],
            'legacy' => [
                'category_id' => $legacyCategoryId,
                'category_name' => substr($raw, $sep + 1),
            ],
            'match' => $raw[0] === '1',
        ];
    }

    /**
     * @return array{0: int, 1: ?string, 2: int, 3: ?string, 4: int}
     */
    protected function resolveComparisonInputs(int|string $groupId, string $releaseName): array
    {
        $pipelineResult = $this->determineCategory($groupId, $releaseName);
        /** @var int $pipelineCategoryId */
        $pipelineCategoryId = (int) $pipelineResult['categories_id'];
        $pipelineCategoryTitle = $this->findCategoryTitle($pipelineCategoryId);

        $legacyCategoryId = Category::OTHER_MISC;
        $legacyCategoryTitle = null;
        $legacyState = 0;

        if (class_exists(Categorize::class)) {
            try {
                $legacy = new Categorize;
                /** @var int $legacyCategoryId */
                $legacyCategoryId = (int) $legacy->determineCategory($groupId, $releaseName);
                $legacyCategoryTitle = $this->findCategoryTitle($legacyCategoryId);
            } catch (\Throwable) {
                $legacyState = 1;
            }
        } else {
            $legacyState = 2;
        }

        return [$pipelineCategoryId, $pipelineCategoryTitle, $legacyCategoryId, $legacyCategoryTitle, $legacyState];
    }

    protected function findCategoryTitle(int $categoryId): ?string
    {
        try {
            $category = Category::find($categoryId);

            return $category ? $category->title : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get statistics about categorizer usage.
     *
     * @return array<string, mixed>
     */
    public function getCategorizerStats(): array
    {
        $categorizers = $this->pipeline->getCategorizers();

        return $categorizers->map(function ($categorizer) {
            return [
                'name' => $categorizer->getName(),
                'priority' => $categorizer->getPriority(),
                'class' => get_class($categorizer),
            ];
        })->toArray();
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
     * Category assignment counts per major root: Console (1000s), Movies (2000s), Audio (3000s),
     * PC (4000s), TV (5000s), XXX (6000s), Books (7000s) and Other (0-999 and 8000s). Non-integer,
     * negative and above W403_MAX_CATEGORY_ID IDs are invalid.
     *
     * @param  array<array-key, mixed>  $categoryIds
     * @return array{total: int, valid: int, console: int, movies: int, audio: int, pc: int, tv: int, xxx: int, books: int, other: int, invalid: int}
     */
    public static function summarizeCategoryDistribution(array $categoryIds): array
    {
        return (self::usesW403RustFfi() ? self::summarizeCategoryDistributionNative($categoryIds) : null)
            ?? self::summarizeCategoryDistributionPhp($categoryIds);
    }

    /**
     * @param  array<array-key, mixed>  $categoryIds
     * @return array{total: int, valid: int, console: int, movies: int, audio: int, pc: int, tv: int, xxx: int, books: int, other: int, invalid: int}
     */
    public static function summarizeCategoryDistributionPhp(array $categoryIds): array
    {
        // Console, Movies, Audio, PC, TV, XXX, Books, Other.
        $buckets = [0, 0, 0, 0, 0, 0, 0, 0];
        $invalid = 0;
        foreach ($categoryIds as $id) {
            if (! \is_int($id) || $id < 0 || $id > self::W403_MAX_CATEGORY_ID) {
                $invalid++;

                continue;
            }
            $root = intdiv($id, 1000);
            $buckets[$root === 0 || $root === 8 ? 7 : $root - 1]++;
        }
        $total = \count($categoryIds);

        return [
            'total' => $total,
            'valid' => $total - $invalid,
            'console' => $buckets[0],
            'movies' => $buckets[1],
            'audio' => $buckets[2],
            'pc' => $buckets[3],
            'tv' => $buckets[4],
            'xxx' => $buckets[5],
            'books' => $buckets[6],
            'other' => $buckets[7],
            'invalid' => $invalid,
        ];
    }

    /**
     * Declines (null) for non-integer IDs so PHP counts them.
     *
     * @param  array<array-key, mixed>  $categoryIds
     * @return array{total: int, valid: int, console: int, movies: int, audio: int, pc: int, tv: int, xxx: int, books: int, other: int, invalid: int}|null
     */
    public static function summarizeCategoryDistributionNative(array $categoryIds): ?array
    {
        foreach ($categoryIds as $item) {
            if (! \is_int($item)) {
                return null;
            }
        }
        $packed = pack('P*', ...array_values($categoryIds));
        $fields = self::callW403Native('nntmux_w403_category_distribution', [$packed, \strlen($packed)], 128, 11);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'valid' => (int) $fields[1],
            'console' => (int) $fields[2],
            'movies' => (int) $fields[3],
            'audio' => (int) $fields[4],
            'pc' => (int) $fields[5],
            'tv' => (int) $fields[6],
            'xxx' => (int) $fields[7],
            'books' => (int) $fields[8],
            'other' => (int) $fields[9],
            'invalid' => (int) $fields[10],
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
        } catch (\Throwable) {
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

    /**
     * Enabled: the paired benchmark in the W53 parity test measures 2.75x common mean, 3.08x common
     * p99, 2.14x uncommon mean and 2.33x uncommon p99 (per-metric minimum of three runs), above the
     * 1.5x rule.
     */
    private const bool W53_RUST_FFI_DEFAULT = true;

    private const string W53_FFI_CDEF = 'ssize_t nntmux_w531_category_comparison_matrix_batch(const char *pairs, size_t pairs_len, size_t count, int64_t *out, size_t out_cap);';

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
     * Compares pipeline and legacy category IDs pairwise, like {@see buildComparisonResultPhp()}
     * does one pair: exact matches, mismatches, mismatches within one root (the thousands), pairs
     * with an ID outside 0-W403_MAX_CATEGORY_ID, and the pipeline-root by legacy-root counts of the
     * valid pairs, row-major (`root_matrix[pipelineRoot * 9 + legacyRoot]`).
     *
     * @param  array<array-key, int>  $pipelineIds
     * @param  array<array-key, int>  $legacyIds
     * @return array{matches: int, mismatches: int, same_root_mismatches: int, invalid_pairs: int, root_matrix: list<int>}
     *
     * @throws \InvalidArgumentException when the columns differ in length
     */
    public static function compareCategoryBatch(array $pipelineIds, array $legacyIds): array
    {
        return self::usesW53RustFfi() ? self::compareCategoryBatchNative($pipelineIds, $legacyIds) : self::compareCategoryBatchPhp($pipelineIds, $legacyIds);
    }

    /**
     * @param  array<array-key, int>  $pipelineIds
     * @param  array<array-key, int>  $legacyIds
     * @return array{matches: int, mismatches: int, same_root_mismatches: int, invalid_pairs: int, root_matrix: list<int>}
     */
    public static function compareCategoryBatchPhp(array $pipelineIds, array $legacyIds): array
    {
        $count = self::w53Count($pipelineIds, $legacyIds);
        $legacyIds = array_values($legacyIds);
        $matrix = array_fill(0, 81, 0);
        $matches = $sameRoot = $invalid = 0;
        foreach (array_values($pipelineIds) as $i => $pipeline) {
            $legacy = $legacyIds[$i];
            if ($pipeline === $legacy) {
                $matches++;
            }
            if ($pipeline < 0 || $pipeline > self::W403_MAX_CATEGORY_ID || $legacy < 0 || $legacy > self::W403_MAX_CATEGORY_ID) {
                $invalid++;

                continue;
            }
            $pipelineRoot = intdiv($pipeline, 1000);
            $legacyRoot = intdiv($legacy, 1000);
            if ($pipeline !== $legacy && $pipelineRoot === $legacyRoot) {
                $sameRoot++;
            }
            $matrix[$pipelineRoot * 9 + $legacyRoot]++;
        }

        return ['matches' => $matches, 'mismatches' => $count - $matches, 'same_root_mismatches' => $sameRoot, 'invalid_pairs' => $invalid, 'root_matrix' => $matrix];
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch.
     *
     * @param  array<array-key, int>  $pipelineIds
     * @param  array<array-key, int>  $legacyIds
     * @return array{matches: int, mismatches: int, same_root_mismatches: int, invalid_pairs: int, root_matrix: list<int>}
     */
    public static function compareCategoryBatchNative(array $pipelineIds, array $legacyIds): array
    {
        $count = self::w53Count($pipelineIds, $legacyIds);
        $buffer = $count === 0 ? null : self::w53Buffer(85);
        if ($buffer === null) {
            return self::compareCategoryBatchPhp($pipelineIds, $legacyIds);
        }
        [$ffi, $out, $cap] = $buffer;
        // One table: the pipeline IDs, then the legacy IDs.
        $pairs = pack('P*', ...array_values($pipelineIds), ...array_values($legacyIds));
        try {
            // @phpstan-ignore method.notFound
            $words = self::w53Decode($out, $ffi->nntmux_w531_category_comparison_matrix_batch($pairs, \strlen($pairs), $count, $out, $cap), 85);
        } catch (\Throwable) {
            $words = null;
        }
        if ($words === null || \count($words) !== 85) {
            return self::compareCategoryBatchPhp($pipelineIds, $legacyIds);
        }

        return ['matches' => $words[0], 'mismatches' => $words[1], 'same_root_mismatches' => $words[2], 'invalid_pairs' => $words[3], 'root_matrix' => \array_slice($words, 4)];
    }

    /**
     * @param  array<mixed>  ...$columns
     */
    private static function w53Count(array ...$columns): int
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count) {
                throw new \InvalidArgumentException('Category comparison columns must have the same length.');
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

    /** Enabled: the minimum of four 1000-round paired runs reached 1.75x common mean, 1.72x common p99, 1.75x uncommon mean and 1.85x uncommon p99; see RustBooksProcessorCategorizationPipelineResultServicePipeW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    private const string W66_FFI_CDEF = 'ssize_t nntmux_w664_categorization_service_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w66CategorizationServicePlanBatch() record. */
    public const int W66_ROW_WORDS = 4;

    /** int64 summary words after the w66CategorizationServicePlanBatch() records (none for an empty batch). */
    public const int W66_TRAILER_WORDS = 13;

    /** Lower-cased group name substrings and their category roots; the first match routes the group. */
    private const array W66_GROUP_ROUTES = [
        ['erotica', 6000], ['xxx', 6000], ['teevee', 5000], ['hdtv', 5000], ['moovee', 2000], ['movies', 2000], ['dvd', 2000], ['x264', 2000],
        ['sounds', 3000], ['mp3', 3000], ['music', 3000], ['audiobook', 3000], ['e-book', 7000], ['ebook', 7000], ['comics', 7000],
        ['games', 1000], ['console', 1000], ['nintendo', 1000], ['xbox', 1000], ['apps', 4000], ['mac', 4000],
    ];

    /**
     * Routing rows for [release name, group name, cached category, pipeline category, legacy
     * category] items, as batchCategorize() and compare() resolve one release each.
     *
     * Names and groups are trimmed and lower-cased; the pair is the in-batch cache key. A cached
     * category from 0 to 8999 is a cache hit; a repeated key reuses its first record's category.
     * The group route is the first W66_GROUP_ROUTES substring of the group (1-based, 0 none).
     * Routes and final categories: 1 cache hit (the cached category), 2 batch hit (the first
     * record's), 3 empty name (OTHER_MISC), 4 32 or 40 hex digit name (OTHER_HASHED), 5 pipeline
     * (a valid category other than OTHER_MISC), 6 group route (its root + 999), 7 legacy (a valid
     * category), 8 fallback (OTHER_MISC). Flags: 1 pipeline and legacy (both non-negative) match,
     * 2 they differ, 4 hex name, 8 cached category above 8999, 16 empty group, 32 empty name.
     *
     * Rows [route | cache (0 miss, 1 hit, 2 batch hit) << 4 | group route << 6 | flags << 11 |
     * first record + 1 for batch hits << 17 | name length (clamped to 2^16 - 1) << 38, final
     * category, fnv1a32(name), fnv1a32(group) (both lower-cased, 0 when empty)], then the trailer
     * [records, the eight route counts, matches, mismatches, distinct keys, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function w66CategorizationServicePlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66CategorizationServicePlanBatchNative($items) : self::w66CategorizationServicePlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function w66CategorizationServicePlanBatchPhp(array $items): array
    {
        self::w66Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $seen = [];
        $finals = [];
        foreach ($items as $i => [$name, $group, $cached, $pipeline, $legacy]) {
            $name = strtolower(trim($name));
            $group = strtolower(trim($group));
            $routeGroup = $root = 0;
            foreach (self::W66_GROUP_ROUTES as $k => [$needle, $parent]) {
                if (str_contains($group, $needle)) {
                    [$routeGroup, $root] = [$k + 1, $parent];

                    break;
                }
            }
            $hashed = (\strlen($name) === 32 || \strlen($name) === 40) && ctype_xdigit($name);
            $first = $seen[\strlen($name).':'.$name.$group] ??= $i;
            $dup = $first === $i ? -1 : $first;
            [$route, $cache, $category] = match (true) {
                $cached >= 0 && $cached <= self::W403_MAX_CATEGORY_ID => [1, 1, $cached],
                $dup >= 0 => [2, 2, $finals[$first]],
                $name === '' => [3, 0, Category::OTHER_MISC],
                $hashed => [4, 0, Category::OTHER_HASHED],
                $pipeline >= 0 && $pipeline <= self::W403_MAX_CATEGORY_ID && $pipeline !== Category::OTHER_MISC => [5, 0, $pipeline],
                $root > 0 => [6, 0, $root + 999],
                $legacy >= 0 && $legacy <= self::W403_MAX_CATEGORY_ID => [7, 0, $legacy],
                default => [8, 0, Category::OTHER_MISC],
            };
            $finals[] = $category;
            $both = $pipeline >= 0 && $legacy >= 0;
            $flags = ($both && $pipeline === $legacy ? 1 : 0) | ($both && $pipeline !== $legacy ? 2 : 0) | ($hashed ? 4 : 0) | ($cached > self::W403_MAX_CATEGORY_ID ? 8 : 0)
                | ($group === '' ? 16 : 0) | ($name === '' ? 32 : 0);
            $word = $route | $cache << 4 | $routeGroup << 6 | $flags << 11 | ($dup + 1) << 17 | min(\strlen($name), 0xFFFF) << 38;
            $nameHash = self::w66Fnv($name);
            array_push($out, $word, $category, $nameHash, self::w66Fnv($group));
            $t[$route]++;
            $t[9] += $flags & 1;
            $t[10] += ($flags >> 1) & 1;
            $value = $word ^ $category << 24 ^ $nameHash;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[11] = \count($seen);
        $t[12] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function w66CategorizationServicePlanBatchNative(array $items): array
    {
        self::w66Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w66Join([array_column($items, 0), array_column($items, 1)]);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4));

        return self::w66Call($blob, $twin, $ints, $count, $count * self::W66_ROW_WORDS + self::W66_TRAILER_WORDS)
            ?? self::w66CategorizationServicePlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w66Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W66 categorization service plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 5 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2])
                || ! \is_int($item[3]) || ! \is_int($item[4])) {
                throw new \InvalidArgumentException('W66 categorization service plan batch items must be [string release name, string group name, int cached category, int pipeline category, int legacy category] lists.');
            }
        }
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
            $written = $ffi->nntmux_w664_categorization_service_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (\Throwable) {
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
