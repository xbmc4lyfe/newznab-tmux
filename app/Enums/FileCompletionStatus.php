<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Binary file completion status during collection processing.
 */
enum FileCompletionStatus: int
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_fcs_classify(int64_t value);
        ssize_t nntmux_fcs_description(int64_t value, char *out, size_t cap);
        C;

    /** File/binary is still incomplete (missing parts) */
    case Incomplete = 0;

    /** File/binary has all parts */
    case Complete = 1;

    // PHP remains the production default until benchmarks qualify Rust.
    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    /**
     * Get human-readable description.
     */
    public function description(): string
    {
        return self::usesRustFfi() ? $this->descriptionNative() : $this->descriptionPhp();
    }

    public function descriptionPhp(): string
    {
        return match ($this) {
            self::Incomplete => 'Incomplete',
            self::Complete => 'Complete',
        };
    }

    public function descriptionNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->descriptionPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 16, $capacity);
        if ($buffer === null) {
            return $this->descriptionPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fcs_description($this->value, $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->descriptionPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->descriptionPhp();
        }
    }

    /**
     * Check if file is complete.
     */
    public function isComplete(): bool
    {
        return self::usesRustFfi() ? $this->isCompleteNative() : $this->isCompletePhp();
    }

    public function isCompletePhp(): bool
    {
        return $this === self::Complete;
    }

    public function isCompleteNative(): bool
    {
        $status = self::classifyNative($this->value);

        return $status === null ? $this->isCompletePhp() : $status === self::Complete;
    }

    /**
     * Classify a stored completion flag; null when it names no status.
     */
    public static function resolve(int $value): ?self
    {
        return self::usesRustFfi() ? self::resolveNative($value) : self::resolvePhp($value);
    }

    public static function resolvePhp(int $value): ?self
    {
        return self::tryFrom($value);
    }

    public static function resolveNative(int $value): ?self
    {
        return self::classifyNative($value) ?? self::resolvePhp($value);
    }

    /**
     * Status from `nntmux_fcs_classify`, or null for an unknown value or unavailable library.
     */
    private static function classifyNative(int $value): ?self
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_fcs_classify($value);
        } catch (Throwable) {
            return null;
        }

        return \is_int($code) && $code >= 0 ? self::tryFrom($code) : null;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Enums cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
