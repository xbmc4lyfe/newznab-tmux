<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Decides whether a downloaded image is worth publishing as a release's sample picture:
 * a complete JPEG, PNG or WebP, large enough to show something, and not blank.
 *
 * isUsable() and isComplete() stay on native PHP: kernel open/fstat/read syscalls dominate
 * file-completeness checks and Zend's rtrim/str_contains is faster than FFI string marshaling
 * on 4 KiB tails, so the Rust candidates (isUsableNative(), isCompleteNative(),
 * isCompleteTailNative()) measure below the 1.5x adoption threshold
 * (see tests/Unit/RustImageInspectorFrameScorerParityTest.php).
 */
final class ImageInspector
{
    /** Smallest width and height accepted; smaller images are icons and banners. */
    private const int MIN_EDGE = 150;

    /** Larger images are not decoded for the blank check; GD would need too much memory. */
    private const int MAX_MEASURED_PIXELS = 12_000_000;

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_image_is_complete_tail(uint32_t type_code, uint64_t file_size, const char *head_or_tail_ptr, size_t head_or_tail_len);
        int32_t nntmux_image_is_complete_file(const char *path_ptr, size_t path_len, uint32_t type_code);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    public function __construct(private readonly FrameQualityScorer $scorer, private readonly float $minStddev) {}

    public function isUsable(string $path): bool
    {
        $info = @getimagesize($path);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] < self::MIN_EDGE || $info[1] < self::MIN_EDGE
            || ! $this->isComplete($path, $info[2])
        ) {
            return false;
        }

        if ($info[0] * $info[1] > self::MAX_MEASURED_PIXELS) {
            return true;
        }

        $measure = $this->scorer->measure($path);

        return $measure !== null && $measure['stddev'] >= $this->minStddev;
    }

    public function isUsableNative(string $path, bool &$supported = false): bool
    {
        $supported = false;
        $info = @getimagesize($path);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] < self::MIN_EDGE || $info[1] < self::MIN_EDGE
        ) {
            $supported = self::ffi() !== null;

            return false;
        }

        $completeSupported = false;
        if (! $this->isCompleteNative($path, $info[2], $completeSupported)) {
            $supported = $completeSupported;

            return false;
        }

        if ($info[0] * $info[1] > self::MAX_MEASURED_PIXELS) {
            $supported = $completeSupported;

            return true;
        }

        $measureSupported = false;
        $measure = $this->scorer->measureNative($path, $measureSupported);
        $supported = $completeSupported && $measureSupported;

        return $measure !== null && $measure['stddev'] >= $this->minStddev;
    }

    /**
     * A download cut short decodes to an image whose lower part is grey; its end marker is missing.
     */
    public function isComplete(string $path, int $type): bool
    {
        return $this->isCompletePhp($path, $type);
    }

    public function isCompletePhp(string $path, int $type): bool
    {
        $size = (int) @filesize($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false || $size < 16) {
            return false;
        }

        try {
            if ($type === IMAGETYPE_WEBP) {
                $header = (string) fread($handle, 8);
                $declared = unpack('V', substr($header, 4, 4));

                return $declared !== false && $size >= $declared[1] + 8;
            }

            fseek($handle, max(0, $size - 4096));
            $tail = rtrim((string) fread($handle, 4096), "\0\r\n ");

            return $type === IMAGETYPE_PNG
                ? str_contains(substr($tail, -64), 'IEND')
                : str_contains(substr($tail, -1024), "\xFF\xD9");
        } finally {
            fclose($handle);
        }
    }

    public function isCompleteNative(string $path, int $type, bool &$supported = false): bool
    {
        $supported = false;
        if ($path === '' || str_contains($path, "\0") || ! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return $this->isCompletePhp($path, $type);
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return $this->isCompletePhp($path, $type);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_image_is_complete_file($path, strlen($path), $type);
        } catch (Throwable) {
            return $this->isCompletePhp($path, $type);
        }
        if (! is_int($status) || $status < 0) {
            return $this->isCompletePhp($path, $type);
        }
        $supported = true;

        return $status === 1;
    }

    public static function isCompleteTailNative(int $type, int $fileSize, string $headOrTail, bool &$supported = false): bool
    {
        $supported = false;
        if ($fileSize < 0 || ! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return self::isCompleteTailPhp($type, $fileSize, $headOrTail);
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isCompleteTailPhp($type, $fileSize, $headOrTail);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_image_is_complete_tail($type, $fileSize, $headOrTail, strlen($headOrTail));
        } catch (Throwable) {
            return self::isCompleteTailPhp($type, $fileSize, $headOrTail);
        }
        if (! is_int($status) || $status < 0) {
            return self::isCompleteTailPhp($type, $fileSize, $headOrTail);
        }
        $supported = true;

        return $status === 1;
    }

    public static function isCompleteTailPhp(int $type, int $fileSize, string $headOrTail): bool
    {
        if ($fileSize < 16 || ! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return false;
        }
        if ($type === IMAGETYPE_WEBP) {
            if (strlen($headOrTail) < 8) {
                return false;
            }
            $declared = unpack('V', substr($headOrTail, 4, 4));

            return $declared !== false && $fileSize >= $declared[1] + 8;
        }

        $slice = strlen($headOrTail) > 4096 ? substr($headOrTail, -4096) : $headOrTail;
        $tail = rtrim($slice, "\0\r\n ");

        return $type === IMAGETYPE_PNG
            ? str_contains(substr($tail, -64), 'IEND')
            : str_contains(substr($tail, -1024), "\xFF\xD9");
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('image_inspector', self::FFI_CDEF);
        }

        return self::$ffi;
    }
}
