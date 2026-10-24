<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * What the release's PAR2 index told us about its obfuscated files.
 */
final readonly class MediaSourcePlan
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_msp_real_size_of(const char *packed, size_t len, int64_t source_index, int64_t *out_size);
        C;

    /**
     * @param  array<int, MappedNzbFile>  $mappedFiles  keyed by NZB source index
     * @param  int|null  $articlePartSize  decoded bytes per article, when an article header showed it
     */
    public function __construct(
        public array $mappedFiles = [],
        public ?int $articlePartSize = null,
    ) {}

    // PHP remains the production default until benchmarks qualify Rust.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }

    public static function usesRustFfi(): bool
    {
        return self::rustEnabled();
    }

    /**
     * Cached `additional` domain handle; `$reset` replaces it with `$override` (null re-resolves).
     */
    public static function additionalFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    public function realSizeOf(int $sourceIndex): ?int
    {
        return self::rustEnabled() ? $this->realSizeOfNative($sourceIndex) : $this->realSizeOfPhp($sourceIndex);
    }

    public function realSizeOfPhp(int $sourceIndex): ?int
    {
        return $this->mappedFiles[$sourceIndex]->file->size ?? null;
    }

    public function realSizeOfNative(int $sourceIndex): ?int
    {
        $ffi = self::additionalFfi();
        if ($ffi === null) {
            return $this->realSizeOfPhp($sourceIndex);
        }

        $pairs = [];
        foreach ($this->mappedFiles as $index => $mapped) {
            if (! is_int($index) || ! $mapped instanceof MappedNzbFile) {
                return $this->realSizeOfPhp($sourceIndex);
            }
            $pairs[] = $index;
            $pairs[] = $mapped->file->size;
        }
        if ($pairs === []) {
            return null;
        }

        static $out = null;
        static $owner = null;
        try {
            if ($owner !== $ffi || $out === null) {
                $out = $ffi->new('int64_t[1]');
                $owner = $ffi;
            }
            // Little-endian [index][size] pairs.
            $packed = pack('P*', ...$pairs);
            // @phpstan-ignore method.notFound
            $found = $ffi->nntmux_msp_real_size_of($packed, strlen($packed), $sourceIndex, $out);
        } catch (Throwable) {
            return $this->realSizeOfPhp($sourceIndex);
        }

        return match ($found) {
            1 => (int) $out[0],
            0 => null,
            default => $this->realSizeOfPhp($sourceIndex),
        };
    }
}
