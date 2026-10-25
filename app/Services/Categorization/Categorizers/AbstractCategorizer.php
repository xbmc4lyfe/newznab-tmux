<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\Contracts\CategorizerInterface;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

abstract class AbstractCategorizer implements CategorizerInterface
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_abstract_categorizer_priority_and_skip(int64_t priority, const char *release_name, size_t release_name_len, int64_t *out_priority);
        ssize_t nntmux_abstract_categorizer_format_result_meta(uint32_t is_no_match, int64_t category_id, double confidence, const char *matched_by_ptr, size_t matched_by_len, int64_t *out_category_id, double *out_confidence, char *out_buf, size_t out_cap);
        C;

    /**
     * Wave 46.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustAbstractCategorizerPipeSafetyNetDisplayNamesW46ParityTest.php measures
     * 1.97-2.07x mean / 1.81-1.97x p99 on the common 32-name batch and 3.84-4.12x mean /
     * 3.06-3.58x p99 on the uncommon 2000-name batch. PHP stays the fallback when the library is
     * unavailable or a name is not a string.
     * Named W463 so it stays distinct from the class's existing useRustFfi paths.
     */
    public const bool W463_RUST_FFI_DEFAULT = true;

    public const string W463_FFI_CDEF = <<<'C'
        ssize_t nntmux_w463_categorizer_release_name_shape_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Release group suffix: a hyphen and 2-15 trailing ASCII alphanumerics. */
    public const string W463_GROUP_SUFFIX_PATTERN = '/-[A-Za-z0-9]{2,15}\z/';

    /** A 19xx/20xx year not embedded in a longer digit run. */
    public const string W463_YEAR_PATTERN = '/(?<![0-9])(?:19|20)[0-9]{2}(?![0-9])/';

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
     * Release name shape summary for categorizer inputs: empty names (only trim() characters), names
     * holding a dot, space, underscore or opening bracket/parenthesis, names ending in a
     * W463_GROUP_SUFFIX_PATTERN release group and names holding a W463_YEAR_PATTERN year. Matching
     * is bytewise; non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, dotted: int, spaced: int, underscored: int, bracketed: int, group_suffix: int, year: int}
     */
    public static function summarizeReleaseNameShapeBatch(array $names): array
    {
        return (self::usesW463RustFfi() ? self::summarizeReleaseNameShapeBatchNative($names) : null)
            ?? self::summarizeReleaseNameShapeBatchPhp($names);
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, dotted: int, spaced: int, underscored: int, bracketed: int, group_suffix: int, year: int}
     */
    public static function summarizeReleaseNameShapeBatchPhp(array $names): array
    {
        $empty = $dotted = $spaced = $underscored = $bracketed = $groupSuffix = $year = 0;
        foreach ($names as $name) {
            $name = \is_string($name) ? $name : '';
            $empty += (int) (trim($name) === '');
            $dotted += (int) str_contains($name, '.');
            $spaced += (int) str_contains($name, ' ');
            $underscored += (int) str_contains($name, '_');
            $bracketed += (int) (strpbrk($name, '[(') !== false);
            $groupSuffix += (int) (preg_match(self::W463_GROUP_SUFFIX_PATTERN, $name) === 1);
            $year += (int) (preg_match(self::W463_YEAR_PATTERN, $name) === 1);
        }

        return [
            'total' => \count($names),
            'empty' => $empty,
            'dotted' => $dotted,
            'spaced' => $spaced,
            'underscored' => $underscored,
            'bracketed' => $bracketed,
            'group_suffix' => $groupSuffix,
            'year' => $year,
        ];
    }

    /**
     * Declines (null) for non-string names and when the library is unavailable, so PHP counts them.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, dotted: int, spaced: int, underscored: int, bracketed: int, group_suffix: int, year: int}|null
     */
    public static function summarizeReleaseNameShapeBatchNative(array $names): ?array
    {
        $lengths = [];
        foreach ($names as $name) {
            if (! \is_string($name)) {
                return null;
            }
            $lengths[] = \strlen($name);
        }
        $fields = self::callW463Native(implode('', $names), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'empty' => (int) $fields[1],
            'dotted' => (int) $fields[2],
            'spaced' => (int) $fields[3],
            'underscored' => (int) $fields[4],
            'bracketed' => (int) $fields[5],
            'group_suffix' => (int) $fields[6],
            'year' => (int) $fields[7],
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
            $written = $ffi->nntmux_w463_categorizer_release_name_shape_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w463_categorizer_release_name_shape_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
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
