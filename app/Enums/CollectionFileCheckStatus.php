<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Collection filecheck status values used during release processing.
 *
 * These statuses track the progress of collections through the release
 * creation pipeline, from initial discovery to NZB file creation.
 */
enum CollectionFileCheckStatus: int
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_collection_file_check_description(int64_t status, char *out, size_t cap);
        int32_t nntmux_collection_file_check_classify(int64_t status);
        ssize_t nntmux_collection_file_check_list_values(int32_t kind, int64_t *out_values, size_t cap);
        C;

    /** Default status for newly discovered collections */
    case Default = 0;

    /** Collection has all expected binary files */
    case CompleteCollection = 1;

    /** All parts of binaries are complete */
    case CompleteParts = 2;

    /** Collection size has been calculated */
    case Sized = 3;

    /** Collection has been converted to a release */
    case Inserted = 4;

    /** Collection marked for deletion */
    case Delete = 5;

    /** Temporary complete status during processing */
    case TempComplete = 15;

    /** Collection has zero-numbered parts (special handling) */
    case ZeroPart = 16;

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

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
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
            $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /**
     * Get the human-readable description for this status.
     */
    public function description(): string
    {
        return self::usesRustFfi()
            ? $this->descriptionNative()
            : $this->descriptionPhp();
    }

    public function descriptionNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->descriptionPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return $this->descriptionPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collection_file_check_description($this->value, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->descriptionPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_collection_file_check_description($this->value, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->descriptionPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->descriptionPhp();
        }
    }

    public function descriptionPhp(): string
    {
        return match ($this) {
            self::Default => 'Newly discovered',
            self::CompleteCollection => 'Complete collection',
            self::CompleteParts => 'Complete parts',
            self::Sized => 'Size calculated',
            self::Inserted => 'Inserted as release',
            self::Delete => 'Marked for deletion',
            self::TempComplete => 'Temporarily complete',
            self::ZeroPart => 'Zero-part collection',
        };
    }

    /**
     * Check if this status indicates processing is complete.
     */
    public function isProcessingComplete(): bool
    {
        return self::usesRustFfi()
            ? $this->isProcessingCompleteNative()
            : $this->isProcessingCompletePhp();
    }

    public function isProcessingCompleteNative(): bool
    {
        if (($ffi = self::ffi()) === null) {
            return $this->isProcessingCompletePhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_collection_file_check_classify($this->value);
            if (\is_int($mask) && $mask >= 0) {
                return ($mask & 1) !== 0;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->isProcessingCompletePhp();
    }

    public function isProcessingCompletePhp(): bool
    {
        return match ($this) {
            self::Inserted, self::Delete => true,
            default => false,
        };
    }

    /**
     * Get statuses that indicate a collection is ready for release creation.
     *
     * @return array<self>
     */
    public static function readyForRelease(): array
    {
        return self::usesRustFfi()
            ? self::readyForReleaseNative()
            : self::readyForReleasePhp();
    }

    /**
     * @return array<self>
     */
    public static function readyForReleaseNative(): array
    {
        return self::nativeStatusList(0, 1) ?? self::readyForReleasePhp();
    }

    /**
     * @return array<self>
     */
    public static function readyForReleasePhp(): array
    {
        return [self::Sized];
    }

    /**
     * Get statuses that indicate a collection is still being processed.
     *
     * @return array<self>
     */
    public static function inProgress(): array
    {
        return self::usesRustFfi()
            ? self::inProgressNative()
            : self::inProgressPhp();
    }

    /**
     * @return array<self>
     */
    public static function inProgressNative(): array
    {
        return self::nativeStatusList(1, 5) ?? self::inProgressPhp();
    }

    /**
     * @return array<self>
     */
    public static function inProgressPhp(): array
    {
        return [
            self::Default,
            self::CompleteCollection,
            self::CompleteParts,
            self::TempComplete,
            self::ZeroPart,
        ];
    }

    /**
     * @return array<self>|null
     */
    private static function nativeStatusList(int $kind, int $expectedCount): ?array
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            $outValues = $ffi->new("int64_t[{$expectedCount}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collection_file_check_list_values($kind, $outValues, $expectedCount);
            if (! \is_int($written) || $written !== $expectedCount) {
                return null;
            }
            $result = [];
            for ($i = 0; $i < $expectedCount; $i++) {
                $case = self::tryFrom((int) $outValues[$i]);
                if ($case === null) {
                    return null;
                }
                $result[] = $case;
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }
}
