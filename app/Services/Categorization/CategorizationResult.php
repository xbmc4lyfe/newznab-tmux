<?php

declare(strict_types=1);

namespace App\Services\Categorization;

use App\Models\Category;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Value object representing the result of a categorization attempt.
 */
class CategorizationResult
{
    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @param  int  $categoryId  The determined category ID
     * @param  float  $confidence  Confidence level (0.0 to 1.0)
     * @param  string  $matchedBy  Description of what matched
     * @param  array<string, mixed>  $debug  Additional debug information
     */
    public function __construct(
        public readonly int $categoryId = Category::OTHER_MISC,
        public readonly float $confidence = 0.0,
        public readonly string $matchedBy = 'none',
        public readonly array $debug = []
    ) {}

    /**
     * Check if this result represents a successful categorization.
     *
     * Returns true for any non-zero confidence result that was explicitly
     * matched, including intentional OTHER_MISC assignments (e.g. archive,
     * dataset, obfuscated_pattern). Only the default no-match sentinel
     * (confidence 0, matchedBy 'no_match') is treated as unsuccessful.
     */
    public function isSuccessful(): bool
    {
        return self::$useRustFfi
            ? $this->isSuccessfulNative()
            : $this->isSuccessfulPhp();
    }

    public function isSuccessfulPhp(): bool
    {
        if ($this->confidence <= 0) {
            return false;
        }

        // The default no-match sentinel is not successful
        if ($this->categoryId === Category::OTHER_MISC && $this->matchedBy === 'no_match') {
            return false;
        }

        return true;
    }

    public function isSuccessfulNative(): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->isSuccessfulPhp();
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorization_result_is_successful(
            $this->categoryId,
            $this->confidence,
            $this->matchedBy,
            \strlen($this->matchedBy),
        );
        if (! \is_int($res) || $res < 0) {
            return $this->isSuccessfulPhp();
        }

        return $res === 1;
    }

    /**
     * Check if this result should take precedence over another.
     */
    public function shouldOverride(CategorizationResult $other): bool
    {
        return self::$useRustFfi
            ? $this->shouldOverrideNative($other)
            : $this->shouldOverridePhp($other);
    }

    public function shouldOverridePhp(CategorizationResult $other): bool
    {
        if ($this->categoryId !== Category::OTHER_MISC && $this->categoryId !== Category::OTHER_HASHED &&
            $other->isProtectedMiscResultPhp() && $this->confidence < 0.6) {
            return false;
        }

        // Higher confidence always wins
        if ($this->confidence > $other->confidence) {
            return true;
        }

        // If same confidence, prefer non-misc categories
        if ($this->confidence === $other->confidence) {
            return $this->categoryId !== Category::OTHER_MISC && $other->categoryId === Category::OTHER_MISC;
        }

        return false;
    }

    public function shouldOverrideNative(CategorizationResult $other): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->shouldOverridePhp($other);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorization_result_should_override(
            $this->categoryId,
            $this->confidence,
            $this->matchedBy,
            \strlen($this->matchedBy),
            $other->categoryId,
            $other->confidence,
            $other->matchedBy,
            \strlen($other->matchedBy),
        );
        if (! \is_int($res) || $res < 0) {
            return $this->shouldOverridePhp($other);
        }

        return $res === 1;
    }

    /**
     * Determine whether this result should resist weak downstream overrides.
     */
    public function isProtectedMiscResult(): bool
    {
        return self::$useRustFfi
            ? $this->isProtectedMiscResultNative()
            : $this->isProtectedMiscResultPhp();
    }

    public function isProtectedMiscResultPhp(): bool
    {
        if ($this->categoryId === Category::OTHER_HASHED) {
            return true;
        }

        if ($this->categoryId !== Category::OTHER_MISC) {
            return false;
        }

        return str_starts_with($this->matchedBy, 'hash_')
            || str_starts_with($this->matchedBy, 'obfuscated_')
            || str_starts_with($this->matchedBy, 'gibberish_')
            || $this->matchedBy === 'group_only_low_signal';
    }

    public function isProtectedMiscResultNative(): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->isProtectedMiscResultPhp();
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_categorization_result_is_protected_misc(
            $this->categoryId,
            $this->matchedBy,
            \strlen($this->matchedBy),
        );
        if (! \is_int($res) || $res < 0) {
            return $this->isProtectedMiscResultPhp();
        }

        return $res === 1;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', ReleaseImageService::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Create a failed/empty result.
     */
    public static function noMatch(): self
    {
        return new self(Category::OTHER_MISC, 0.0, 'no_match');
    }

    /**
     * Create a result with debug info merged.
     *
     * @param  array<string, mixed>  $additionalDebug
     */
    public function withDebug(array $additionalDebug): self
    {
        return new self(
            $this->categoryId,
            $this->confidence,
            $this->matchedBy,
            array_merge($this->debug, $additionalDebug)
        );
    }
}
