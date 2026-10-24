<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use App\Services\Categorization\Pipes\AbstractCategorizationPipe;
use App\Support\RustPipelineLibrary;
use Blacklight\Categorize;
use FFI;

/**
 * Categorization service using Laravel Pipeline.
 *
 * This class is a drop-in replacement for the legacy Blacklight\Categorize
 * with additional features like confidence scoring and debug information.
 */
class CategorizationService
{
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
}
