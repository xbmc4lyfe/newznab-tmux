<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

enum DownloadKind: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_dk_is_preview_source(const char *value, size_t len);
        C;

    case Sample = 'sample';
    case MediaInfo = 'media-info';
    case Audio = 'audio';
    case Jpg = 'jpg';
    case Compressed = 'compressed';
    case Par2Index = 'par2-index';
    case MediaSource = 'media-source';

    // PHP remains the production default until benchmarks qualify Rust.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Enums cannot declare properties.
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

    /**
     * Downloads made only to find a preview, counted against the media byte budget.
     */
    public function isPreviewSource(): bool
    {
        return self::rustEnabled() ? $this->isPreviewSourceNative() : $this->isPreviewSourcePhp();
    }

    public function isPreviewSourcePhp(): bool
    {
        return $this === self::Par2Index || $this === self::MediaSource || $this === self::Jpg;
    }

    public function isPreviewSourceNative(): bool
    {
        $ffi = self::additionalFfi();
        if ($ffi === null) {
            return $this->isPreviewSourcePhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $preview = $ffi->nntmux_dk_is_preview_source($this->value, strlen($this->value));
        } catch (Throwable) {
            return $this->isPreviewSourcePhp();
        }

        return $preview === 0 || $preview === 1 ? $preview === 1 : $this->isPreviewSourcePhp();
    }
}
