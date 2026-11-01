<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use Blacklight\Categorize;

/**
 * Categorization service using Laravel Pipeline.
 *
 * This class is a drop-in replacement for the legacy Blacklight\Categorize
 * with additional features like confidence scoring and debug information.
 */
class CategorizationService
{
    protected CategorizationPipeline $pipeline;

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
        // Run the new pipeline categorization
        $pipelineResult = $this->determineCategory($groupId, $releaseName);
        $pipelineCategoryId = $pipelineResult['categories_id'];
        $pipelineCategory = Category::find($pipelineCategoryId);
        $pipelineCategoryName = $pipelineCategory ? $pipelineCategory->title : 'Unknown';

        // Attempt legacy categorization if the class still exists
        $legacyCategoryId = Category::OTHER_MISC;
        $legacyCategoryName = 'Unknown';

        if (class_exists(Categorize::class)) {
            try {
                $legacy = new Categorize;
                /** @var int $legacyCategoryId */
                $legacyCategoryId = (int) $legacy->determineCategory($groupId, $releaseName);
                $legacyCategory = Category::find($legacyCategoryId);
                $legacyCategoryName = $legacyCategory ? $legacyCategory->title : 'Unknown';
            } catch (\Throwable) {
                $legacyCategoryName = 'Legacy error';
            }
        } else {
            $legacyCategoryName = 'Legacy unavailable';
        }

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
