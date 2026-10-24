<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;

/**
 * Base class for categorization pipe handlers.
 *
 * Each pipe is responsible for categorizing a specific type of release.
 */
abstract class AbstractCategorizationPipe
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_categorize_pipe_should_skip(uint32_t locked_to_misc, double confidence, uint32_t pipe_skip);
        ssize_t nntmux_categorize_pipe_format_result_meta(uint32_t is_no_match, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len, int64_t *out_category_id, double *out_confidence, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected int $priority = 50;

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

    /**
     * Handle the categorization request.
     */
    public function handle(CategorizationPassable $passable, Closure $next): CategorizationPassable
    {
        // If we already have a high-confidence match, skip processing
        if ($passable->shouldStopProcessing()) {
            return $next($passable);
        }

        // Skip if this categorizer shouldn't process this release
        if ($this->shouldSkip($passable->context)) {
            return $next($passable);
        }

        // Attempt categorization
        $result = $this->categorize($passable->context);

        // Update the best result
        $passable->updateBestResult($result, $this->getName());

        return $next($passable);
    }

    /**
     * Get the priority of this categorizer (lower = higher priority).
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Get the name of this categorizer for debugging/logging.
     */
    abstract public function getName(): string;

    /**
     * Attempt to categorize the given release.
     */
    abstract protected function categorize(ReleaseContext $context): CategorizationResult;

    /**
     * Evaluate whether a pipe should be bypassed given passable state and context skip flag.
     */
    public function evaluateSkipGuard(bool $lockedToMisc, float $confidence, bool $pipeSkip = false): bool
    {
        return self::$useRustFfi
            ? $this->evaluateSkipGuardNative($lockedToMisc, $confidence, $pipeSkip)
            : $this->evaluateSkipGuardPhp($lockedToMisc, $confidence, $pipeSkip);
    }

    public function evaluateSkipGuardPhp(bool $lockedToMisc, float $confidence, bool $pipeSkip = false): bool
    {
        return $lockedToMisc || $confidence >= 0.95 || $pipeSkip;
    }

    public function evaluateSkipGuardNative(bool $lockedToMisc, float $confidence, bool $pipeSkip = false): bool
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->evaluateSkipGuardPhp($lockedToMisc, $confidence, $pipeSkip);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorize_pipe_should_skip(
            $lockedToMisc ? 1 : 0,
            $confidence,
            $pipeSkip ? 1 : 0
        );
        if (! \is_int($res) || $res < 0) {
            return $this->evaluateSkipGuardPhp($lockedToMisc, $confidence, $pipeSkip);
        }

        return $res === 1;
    }

    /**
     * Check if this categorizer should be skipped for the given context.
     */
    protected function shouldSkip(ReleaseContext $context): bool
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
        return $this->evaluateSkipGuardNative(false, 0.0, false);
    }

    /**
     * Create a successful categorization result.
     *
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
        $ffi = self::categorizeFfi();
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
        $written = $ffi->nntmux_categorize_pipe_format_result_meta(
            0,
            $categoryId,
            $confidence,
            $matchedBy,
            $len,
            $outCat,
            $outConf,
            $scratch,
            $cap
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->matchedPhp($categoryId, $confidence, $matchedBy, $debug);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_categorize_pipe_format_result_meta(
                0,
                $categoryId,
                $confidence,
                $matchedBy,
                $len,
                $outCat,
                $outConf,
                $scratch,
                $cap
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

    /**
     * Create a no-match result.
     */
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
        $ffi = self::categorizeFfi();
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
        $written = $ffi->nntmux_categorize_pipe_format_result_meta(
            1,
            0,
            0.0,
            '',
            0,
            $outCat,
            $outConf,
            $scratch,
            $cap
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
