<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Contracts\CategorizerInterface;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;

abstract class AbstractCategorizer implements CategorizerInterface
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_abstract_categorizer_priority_and_skip(int64_t priority, const char *release_name, size_t release_name_len, int64_t *out_priority);
        ssize_t nntmux_abstract_categorizer_format_result_meta(uint32_t is_no_match, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len, int64_t *out_category_id, double *out_confidence, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected int $priority = 50;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    public function getPriority(): int
    {
        return self::$useRustFfi
            ? $this->getPriorityNative()
            : $this->getPriorityPhp();
    }

    public function getPriorityPhp(): int
    {
        return $this->priority;
    }

    public function getPriorityNative(): int
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->getPriorityPhp();
        }

        $outPriority = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_abstract_categorizer_priority_and_skip(
            $this->priority,
            '',
            0,
            $outPriority,
        );
        if (! \is_int($res) || $res < 0) {
            return $this->getPriorityPhp();
        }

        /** @var int $resolved */
        $resolved = $outPriority[0];

        return $resolved;
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        return self::$useRustFfi
            ? $this->shouldSkipNative($context)
            : $this->shouldSkipPhp($context);
    }

    public function shouldSkipPhp(ReleaseContext $context): bool
    {
        return false;
    }

    public function shouldSkipNative(ReleaseContext $context): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->shouldSkipPhp($context);
        }

        $outPriority = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_abstract_categorizer_priority_and_skip(
            $this->priority,
            $context->releaseName,
            \strlen($context->releaseName),
            $outPriority,
        );
        if (! \is_int($res) || $res < 0) {
            return $this->shouldSkipPhp($context);
        }

        return $res === 1;
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    protected function matched(int $categoryId, float $confidence, string $matchedBy, array $debug = []): CategorizationResult
    {
        return self::$useRustFfi
            ? $this->matchedNative($categoryId, $confidence, $matchedBy, $debug)
            : $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public function matchedPhp(int $categoryId, float $confidence, string $matchedBy, array $debug = []): CategorizationResult
    {
        return new CategorizationResult($categoryId, $confidence, $matchedBy, $debug);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public function matchedNative(int $categoryId, float $confidence, string $matchedBy, array $debug = []): CategorizationResult
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
        }

        $len = \strlen($matchedBy);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(64, $len + 16), $cap);
        if ($scratch === null) {
            return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
        }
        $outCat = $ffi->new('int64_t[1]');
        $outConf = $ffi->new('double[1]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_abstract_categorizer_format_result_meta(
            0,
            $categoryId,
            $confidence,
            $matchedBy,
            $len,
            $outCat,
            $outConf,
            $scratch,
            $cap,
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_abstract_categorizer_format_result_meta(
                0,
                $categoryId,
                $confidence,
                $matchedBy,
                $len,
                $outCat,
                $outConf,
                $scratch,
                $cap,
            );
        }
        if (! \is_int($written) || $written < 0) {
            return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
        }

        /** @var int $resolvedCat */
        $resolvedCat = $outCat[0];
        /** @var float $resolvedConf */
        $resolvedConf = $outConf[0];
        $resolvedMatchedBy = $written === 0 ? '' : FFI::string($scratch, $written);

        return new CategorizationResult($resolvedCat, $resolvedConf, $resolvedMatchedBy, $debug);
    }

    protected function noMatch(): CategorizationResult
    {
        return self::$useRustFfi
            ? $this->noMatchNative()
            : $this->noMatchPhp();
    }

    public function noMatchPhp(): CategorizationResult
    {
        return CategorizationResult::noMatch();
    }

    public function noMatchNative(): CategorizationResult
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->noMatchPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return $this->noMatchPhp();
        }
        $outCat = $ffi->new('int64_t[1]');
        $outConf = $ffi->new('double[1]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_abstract_categorizer_format_result_meta(
            1,
            0,
            0.0,
            '',
            0,
            $outCat,
            $outConf,
            $scratch,
            $cap,
        );
        if (! \is_int($written) || $written <= 0) {
            return $this->noMatchPhp();
        }

        /** @var int $resolvedCat */
        $resolvedCat = $outCat[0];
        /** @var float $resolvedConf */
        $resolvedConf = $outConf[0];

        return new CategorizationResult($resolvedCat, $resolvedConf, FFI::string($scratch, $written));
    }
}
