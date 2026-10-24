<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\CategorizationService;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Passable object that travels through the categorization pipeline.
 */
class CategorizationPassable
{
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
}
