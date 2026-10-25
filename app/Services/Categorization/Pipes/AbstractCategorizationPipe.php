<?php

declare(strict_types=1);

namespace App\Services\Categorization\Pipes;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Throwable;

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

    /**
     * Wave 46.3 production default stays PHP: the paired benchmark in
     * tests/Unit/RustAbstractCategorizerPipeSafetyNetDisplayNamesW46ParityTest.php measures
     * 0.66-0.71x mean / 0.47-0.76x p99 on the common 32-confidence batch (fixed FFI marshaling
     * exceeds a few float comparisons) and 1.47-1.63x mean / 1.42-1.60x p99 uncommon, below the
     * 1.5x gate. useW463RustFfi(true) keeps the Rust helper available for parity and benchmarks.
     * Named W463 so it stays distinct from the class's existing useRustFfi paths.
     */
    public const bool W463_RUST_FFI_DEFAULT = false;

    public const string W463_FFI_CDEF = <<<'C'
        ssize_t nntmux_w463_pipe_confidence_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** evaluateSkipGuardPhp() stop threshold, then the high and medium confidence bands. */
    public const float W463_STOP_CONFIDENCE = 0.95;

    public const float W463_HIGH_CONFIDENCE = 0.8;

    public const float W463_MEDIUM_CONFIDENCE = 0.5;

    /** Exactly 8 0x1F-separated decimals of 1-19 digits. */
    private const string W463_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){7}\d{1,19}\z/';

    private static ?bool $w463RustFfiOverride = null;

    private static FFI|false|null $w463RustFfiHandle = null;

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

    /**
     * Enables (true) or disables (false) the Wave 46.3 Rust helper; null restores
     * W463_RUST_FFI_DEFAULT.
     */
    public static function useW463RustFfi(?bool $enable = true): void
    {
        self::$w463RustFfiOverride = $enable;
    }

    public static function usesW463RustFfi(): bool
    {
        return self::$w463RustFfiOverride ?? self::W463_RUST_FFI_DEFAULT;
    }

    public static function resetW463RustFfi(): void
    {
        self::$w463RustFfiOverride = null;
        self::$w463RustFfiHandle = null;
    }

    /**
     * Confidence band summary for a batch of best-result confidences: NaN, then stop (at least
     * W463_STOP_CONFIDENCE, so evaluateSkipGuard() bypasses later pipes), high (at least
     * W463_HIGH_CONFIDENCE), medium (at least W463_MEDIUM_CONFIDENCE), low (above zero) and none.
     * Values other than ints and floats count as invalid.
     *
     * @param  array<array-key, mixed>  $confidences
     * @return array{total: int, stop: int, high: int, medium: int, low: int, none: int, nan: int, invalid: int}
     */
    public static function summarizeSkipGuardConfidenceBatch(array $confidences): array
    {
        return (self::usesW463RustFfi() ? self::summarizeSkipGuardConfidenceBatchNative($confidences) : null)
            ?? self::summarizeSkipGuardConfidenceBatchPhp($confidences);
    }

    /**
     * @param  array<array-key, mixed>  $confidences
     * @return array{total: int, stop: int, high: int, medium: int, low: int, none: int, nan: int, invalid: int}
     */
    public static function summarizeSkipGuardConfidenceBatchPhp(array $confidences): array
    {
        $stop = $high = $medium = $low = $none = $nan = $invalid = 0;
        foreach ($confidences as $confidence) {
            if (! \is_float($confidence) && ! \is_int($confidence)) {
                $invalid++;

                continue;
            }
            $confidence = (float) $confidence;
            if (is_nan($confidence)) {
                $nan++;
            } elseif ($confidence >= self::W463_STOP_CONFIDENCE) {
                $stop++;
            } elseif ($confidence >= self::W463_HIGH_CONFIDENCE) {
                $high++;
            } elseif ($confidence >= self::W463_MEDIUM_CONFIDENCE) {
                $medium++;
            } elseif ($confidence > 0.0) {
                $low++;
            } else {
                $none++;
            }
        }

        return [
            'total' => \count($confidences),
            'stop' => $stop,
            'high' => $high,
            'medium' => $medium,
            'low' => $low,
            'none' => $none,
            'nan' => $nan,
            'invalid' => $invalid,
        ];
    }

    /**
     * Packs the confidences as little-endian doubles in one item. Declines (null) for values other
     * than ints and floats and when the library is unavailable, so PHP counts them.
     *
     * @param  array<array-key, mixed>  $confidences
     * @return array{total: int, stop: int, high: int, medium: int, low: int, none: int, nan: int, invalid: int}|null
     */
    public static function summarizeSkipGuardConfidenceBatchNative(array $confidences): ?array
    {
        foreach ($confidences as $confidence) {
            if (! \is_float($confidence) && ! \is_int($confidence)) {
                return null;
            }
        }
        $packed = pack('e*', ...array_values($confidences));
        $fields = self::callW463Native($packed, pack('V', \strlen($packed)));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'stop' => (int) $fields[1],
            'high' => (int) $fields[2],
            'medium' => (int) $fields[3],
            'low' => (int) $fields[4],
            'none' => (int) $fields[5],
            'nan' => (int) $fields[6],
            'invalid' => (int) $fields[7],
        ];
    }

    /**
     * Calls the Wave 46.3 export with the shared scratch buffer, retrying once at the size it asks
     * for. Returns its fields only when they match W463_FIELDS_PATTERN.
     *
     * @return list<string>|null
     */
    private static function callW463Native(string $blob, string $lens): ?array
    {
        $ffi = self::w463RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 192, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w463_pipe_confidence_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w463_pipe_confidence_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W463_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w463RustFfi(): ?FFI
    {
        if (self::$w463RustFfiHandle === null) {
            self::$w463RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w463', self::W463_FFI_CDEF) ?? false;
        }

        return self::$w463RustFfiHandle === false ? null : self::$w463RustFfiHandle;
    }
}
