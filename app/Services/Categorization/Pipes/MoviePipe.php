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
    /**
     * Wave 40.3 production default is PHP: the paired benchmark in
     * tests/Unit/RustCategorizationServiceGroupNameFixerW40ParityTest.php measures only
     * 0.42-0.43x mean / 0.37-0.50x p99 on the common batch, below the 1.5x adoption threshold, so Rust
     * stays behind useW403RustFfi(true). Named W403 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W403_RUST_FFI_DEFAULT = false;

    public const string W403_FFI_CDEF = <<<'C'
        ssize_t nntmux_w403_candidate_score_window(
            const char *values_ptr,
            size_t values_len,
            int64_t threshold_permille,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const int W403_MAX_PERMILLE = 1000;

    /** A winner above the threshold is unambiguous alone or this far ahead of the runner-up. */
    public const int W403_MIN_WINNER_MARGIN_PERMILLE = 50;

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
     * Summary of a window of candidate similarity scores in permille. Integer scores and the
     * threshold are clamped to 0..W403_MAX_PERMILLE; non-integer scores are invalid and skipped. The
     * best candidate is an unambiguous winner when it reaches the threshold and is either the only
     * candidate or at least W403_MIN_WINNER_MARGIN_PERMILLE ahead of the runner-up.
     *
     * @param  array<array-key, mixed>  $scoresPermille
     * @return array{candidates: int, invalid: int, above_threshold: int, best: int, runner_up: int, margin: int, unambiguous: bool, threshold: int}
     */
    public static function summarizeCandidateScoreWindow(array $scoresPermille, int $thresholdPermille = 850): array
    {
        return (self::usesW403RustFfi() ? self::summarizeCandidateScoreWindowNative($scoresPermille, $thresholdPermille) : null)
            ?? self::summarizeCandidateScoreWindowPhp($scoresPermille, $thresholdPermille);
    }

    /**
     * @param  array<array-key, mixed>  $scoresPermille
     * @return array{candidates: int, invalid: int, above_threshold: int, best: int, runner_up: int, margin: int, unambiguous: bool, threshold: int}
     */
    public static function summarizeCandidateScoreWindowPhp(array $scoresPermille, int $thresholdPermille = 850): array
    {
        $threshold = max(0, min(self::W403_MAX_PERMILLE, $thresholdPermille));
        $invalid = 0;
        $above = 0;
        $best = 0;
        $runnerUp = 0;
        foreach ($scoresPermille as $raw) {
            if (! \is_int($raw)) {
                $invalid++;

                continue;
            }
            $score = max(0, min(self::W403_MAX_PERMILLE, $raw));
            if ($score >= $threshold) {
                $above++;
            }
            if ($score > $best) {
                $runnerUp = $best;
                $best = $score;
            } elseif ($score > $runnerUp) {
                $runnerUp = $score;
            }
        }
        $candidates = \count($scoresPermille) - $invalid;
        $margin = $best - $runnerUp;

        return [
            'candidates' => $candidates,
            'invalid' => $invalid,
            'above_threshold' => $above,
            'best' => $best,
            'runner_up' => $runnerUp,
            'margin' => $margin,
            'unambiguous' => $above >= 1 && ($candidates === 1 || $margin >= self::W403_MIN_WINNER_MARGIN_PERMILLE),
            'threshold' => $threshold,
        ];
    }

    /**
     * Declines (null) for non-integer scores so PHP counts them as invalid.
     *
     * @param  array<array-key, mixed>  $scoresPermille
     * @return array{candidates: int, invalid: int, above_threshold: int, best: int, runner_up: int, margin: int, unambiguous: bool, threshold: int}|null
     */
    public static function summarizeCandidateScoreWindowNative(array $scoresPermille, int $thresholdPermille = 850): ?array
    {
        foreach ($scoresPermille as $item) {
            if (! \is_int($item)) {
                return null;
            }
        }
        $packed = pack('P*', ...array_values($scoresPermille));
        $fields = self::callW403Native('nntmux_w403_candidate_score_window', [$packed, \strlen($packed), $thresholdPermille], 96, 7);
        if ($fields === null || ($fields[5] !== '0' && $fields[5] !== '1')) {
            return null;
        }

        return [
            'candidates' => (int) $fields[0],
            'invalid' => 0,
            'above_threshold' => (int) $fields[1],
            'best' => (int) $fields[2],
            'runner_up' => (int) $fields[3],
            'margin' => (int) $fields[4],
            'unambiguous' => $fields[5] === '1',
            'threshold' => (int) $fields[6],
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
}
