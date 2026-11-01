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
 * Wave 44 keeps file I/O and image decoding in PHP and optionally inspects the
 * header/tail bytes through Rust. Its default is qualified against complete PHP
 * caller timings in RustAdditionalDtoArchivePar2ImageInspectorSourceMapperW44ParityTest.
 */
final class ImageInspector
{
    public const bool W44_RUST_FFI_DEFAULT = false;

    private static ?bool $w44RustFfi = null;

    public static function useRustFfi(?bool $enabled): void
    {
        self::$w44RustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$w44RustFfi ?? self::W44_RUST_FFI_DEFAULT;
    }

    /** Smallest width and height accepted; smaller images are icons and banners. */
    private const int MIN_EDGE = 150;

    /** Larger images are not decoded for the blank check; GD would need too much memory. */
    private const int MAX_MEASURED_PIXELS = 12_000_000;

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_w44_image_is_complete_tail(uint32_t type_code, uint64_t file_size, const char *head_or_tail_ptr, size_t head_or_tail_len);
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
        if (! self::usesRustFfi() || ! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return $this->isCompletePhp($path, $type);
        }

        $size = (int) @filesize($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            if ($size < 16) {
                return false;
            }
            if ($type !== IMAGETYPE_WEBP) {
                fseek($handle, max(0, $size - 4096));
            }

            return self::isCompleteTail($type, $size, (string) fread($handle, $type === IMAGETYPE_WEBP ? 8 : 4096));
        } finally {
            fclose($handle);
        }
    }

    public static function isCompleteTail(int $type, int $fileSize, string $headOrTail): bool
    {
        return self::usesRustFfi()
            ? self::isCompleteTailNative($type, $fileSize, $headOrTail)
            : self::isCompleteTailPhp($type, $fileSize, $headOrTail);
    }

    public function isCompletePhp(string $path, int $type): bool
    {
        $size = (int) @filesize($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            if ($size < 16) {
                return false;
            }
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
            $status = $ffi->nntmux_w44_image_is_complete_tail($type, $fileSize, $headOrTail, strlen($headOrTail));
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
            self::$ffi = RustPipelineLibrary::resolveDomain('w44_image_inspector', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    public const string W52_FFI_CDEF = 'int64_t nntmux_w525_image_header_probe(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52.5 parity test's QUALIFICATION). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    /**
     * Wave 52.5 routing and FFI cache, kept in function-local statics so readonly classes can use them too.
     * Without $op it reports the routing; 'set' swaps the override for $val and returns the previous one;
     * 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w52_5', self::W52_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W52_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        return self::w52RustFfiState() === true;
    }

    /**
     * Runs $callback with headerProbeBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 52.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w52Words(int $count, string $data, string $ints, int $words): ?array
    {
        try {
            $handle = self::w52RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w525_image_header_probe($count, 0, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch header probe over each file's first bytes. Three words per header: `[IMAGETYPE_JPEG/PNG/WEBP or
     * 0, width, height]`; width and height are 0 when the header is too short to hold them. PNG reads IHDR,
     * WebP its VP8, VP8L or VP8X chunk and JPEG the first SOF marker before the scan data.
     *
     * @param  list<string>  $heads
     * @return list<int>
     */
    public static function headerProbeBatch(array $heads): array
    {
        return self::usesW52RustFfi() ? self::headerProbeBatchNative($heads) : self::headerProbeBatchPhp($heads);
    }

    /**
     * @param  list<string>  $heads
     * @return list<int>
     */
    public static function headerProbeBatchPhp(array $heads): array
    {
        $out = [];
        foreach ($heads as $head) {
            array_push($out, ...self::w52ProbeHeader($head));
        }

        return $out;
    }

    /**
     * Rust batch over concatenated headers and their lengths; falls back to PHP without the library.
     *
     * @param  list<string>  $heads
     * @return list<int>
     */
    public static function headerProbeBatchNative(array $heads): array
    {
        $count = \count($heads);
        if ($count === 0) {
            return [];
        }

        return self::w52Words($count, implode('', $heads), pack('P*', ...array_map(strlen(...), $heads)), 3 * $count)
            ?? self::headerProbeBatchPhp($heads);
    }

    /** @return array{int, int, int} */
    private static function w52ProbeHeader(string $head): array
    {
        $length = \strlen($head);
        if ($length >= 24 && str_starts_with($head, "\x89PNG\r\n\x1a\n") && substr($head, 12, 4) === 'IHDR') {
            /** @var array{1: int, 2: int} $size */
            $size = unpack('N2', $head, 16);

            return [IMAGETYPE_PNG, $size[1], $size[2]];
        }
        if ($length >= 30 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            $chunk = substr($head, 12, 4);
            if ($chunk === 'VP8 ' && substr($head, 23, 3) === "\x9d\x01\x2a") {
                /** @var array{1: int, 2: int} $size */
                $size = unpack('v2', $head, 26);

                return [IMAGETYPE_WEBP, $size[1] & 0x3FFF, $size[2] & 0x3FFF];
            }
            if ($chunk === 'VP8L' && $head[20] === "\x2f") {
                /** @var array{1: int} $bits */
                $bits = unpack('V', $head, 21);

                return [IMAGETYPE_WEBP, ($bits[1] & 0x3FFF) + 1, ($bits[1] >> 14 & 0x3FFF) + 1];
            }
            if ($chunk === 'VP8X') {
                /** @var array{1: int, 2: int} $size */
                $size = unpack('V2', substr($head, 24, 3)."\0".substr($head, 27, 3)."\0");

                return [IMAGETYPE_WEBP, $size[1] + 1, $size[2] + 1];
            }

            return [IMAGETYPE_WEBP, 0, 0];
        }
        if ($length >= 3 && str_starts_with($head, "\xFF\xD8\xFF")) {
            $offset = 2;
            while ($offset + 4 <= $length && $head[$offset] === "\xFF") {
                $marker = \ord($head[$offset + 1]);
                if ($marker === 0xFF) {
                    $offset++;

                    continue;
                }
                if ($marker === 0xD8 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                    $offset += 2;

                    continue;
                }
                if ($marker === 0xD9 || $marker === 0xDA) {
                    break;
                }
                /** @var array{1: int} $segment */
                $segment = unpack('n', $head, $offset + 2);
                if ($segment[1] < 2) {
                    break;
                }
                if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                    if ($offset + 9 > $length) {
                        break;
                    }
                    /** @var array{height: int, width: int} $size */
                    $size = unpack('nheight/nwidth', $head, $offset + 5);

                    return [IMAGETYPE_JPEG, $size['width'], $size['height']];
                }
                $offset += 2 + $segment[1];
            }

            return [IMAGETYPE_JPEG, 0, 0];
        }

        return [0, 0, 0];
    }
}
