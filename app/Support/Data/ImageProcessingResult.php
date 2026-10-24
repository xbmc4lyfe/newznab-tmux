<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final readonly class ImageProcessingResult
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_ipr_build(int32_t kind, int64_t width, int64_t height, int64_t *out, size_t out_count);
        C;

    /** Field presence bits returned by `nntmux_ipr_build`. */
    private const int FIELD_SUCCESS = 1;

    private const int FIELD_PATH = 2;

    private const int FIELD_WIDTH = 4;

    private const int FIELD_HEIGHT = 8;

    private const int FIELD_MIME = 16;

    private const int FIELD_REASON = 32;

    private function __construct(
        public bool $success,
        public ?string $path,
        public ?int $width,
        public ?int $height,
        public ?string $mimeType,
        public ?string $failureReason,
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

    public static function success(string $path, int $width, int $height, string $mimeType): self
    {
        return self::rustEnabled()
            ? self::successNative($path, $width, $height, $mimeType)
            : self::successPhp($path, $width, $height, $mimeType);
    }

    public static function successPhp(string $path, int $width, int $height, string $mimeType): self
    {
        return new self(true, $path, $width, $height, $mimeType, null);
    }

    public static function successNative(string $path, int $width, int $height, string $mimeType): self
    {
        return self::buildNative(1, $path, $width, $height, $mimeType, null)
            ?? self::successPhp($path, $width, $height, $mimeType);
    }

    public static function failure(string $reason): self
    {
        return self::rustEnabled() ? self::failureNative($reason) : self::failurePhp($reason);
    }

    public static function failurePhp(string $reason): self
    {
        return new self(false, null, null, null, null, $reason);
    }

    public static function failureNative(string $reason): self
    {
        return self::buildNative(0, null, 0, 0, null, $reason) ?? self::failurePhp($reason);
    }

    /**
     * Result payload in constructor order.
     *
     * @return array{success: bool, path: ?string, width: ?int, height: ?int, mimeType: ?string, failureReason: ?string}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'path' => $this->path,
            'width' => $this->width,
            'height' => $this->height,
            'mimeType' => $this->mimeType,
            'failureReason' => $this->failureReason,
        ];
    }

    private static function buildNative(int $kind, ?string $path, int $width, int $height, ?string $mimeType, ?string $reason): ?self
    {
        $ffi = self::additionalFfi();
        if ($ffi === null) {
            return null;
        }

        static $out = null;
        static $owner = null;
        try {
            if ($owner !== $ffi || $out === null) {
                $out = $ffi->new('int64_t[2]');
                $owner = $ffi;
            }
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_ipr_build($kind, $width, $height, $out, 2);
        } catch (Throwable) {
            return null;
        }
        if (! is_int($mask) || $mask < 0) {
            return null;
        }

        return new self(
            ($mask & self::FIELD_SUCCESS) !== 0,
            ($mask & self::FIELD_PATH) !== 0 ? $path : null,
            ($mask & self::FIELD_WIDTH) !== 0 ? (int) $out[0] : null,
            ($mask & self::FIELD_HEIGHT) !== 0 ? (int) $out[1] : null,
            ($mask & self::FIELD_MIME) !== 0 ? $mimeType : null,
            ($mask & self::FIELD_REASON) !== 0 ? $reason : null,
        );
    }
}
