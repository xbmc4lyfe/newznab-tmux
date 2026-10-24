<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Categorizers\MovieCategorizer;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Pipe for Movie content categorization.
 */
class MoviePipe extends AbstractCategorizationPipe
{
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

    private const int PIPE_SPEC_KIND = 2;

    private const string PIPE_NAME = 'Movie';

    private const int PIPE_PRIORITY = 25;

    // Public because the parent declares it public; a private redeclaration is a fatal error.
    public static bool $useRustFfi = false;

    private static FFI|false|null $pipeSpecFfi = null;

    protected int $priority = self::PIPE_PRIORITY;

    private MovieCategorizer $categorizer;

    public function __construct()
    {
        $this->categorizer = new MovieCategorizer;
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
        if ($categoryId >= Category::MOVIE_ROOT && $categoryId <= Category::MOVIE_ROOT + 999) {
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
}
