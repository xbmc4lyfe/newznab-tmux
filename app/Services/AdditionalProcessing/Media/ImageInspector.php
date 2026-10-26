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

    /** C signature of the Wave 64.5 {@see self::w64ImageInspectorPlanBatch()} helper. */
    private const string W64_IMAGE_INSPECTOR_PLAN_FFI_CDEF = 'int64_t nntmux_w645_image_inspector_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 64.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 3.05/2.80/1.97/1.95x); see tests/Unit/RustAdditionalFfmpegFrameScorerRankerInspectorFetcherW64ParityTest.php.
     */
    public const bool W64_RUST_FFI_DEFAULT = true;

    /**
     * Wave 64.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w64_5', self::W64_IMAGE_INSPECTOR_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W64_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 64.5 plan batch to Rust until disabled or reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Route the Wave 64.5 plan batch to PHP until enabled or reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restore W64_RUST_FFI_DEFAULT routing and drop the cached Wave 64.5 handle and buffer. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('set', null);
        self::w64RustFfiState('reset', null);
    }

    public static function usesW64RustFfi(): bool
    {
        return self::w64RustFfiState() === true;
    }

    /**
     * Runs $callback with w64ImageInspectorPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w64RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 64.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w64Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w64RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w645_image_inspector_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w64Number(string $field, int $default, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /**
     * Batch inspection plan over `file_size\thead_hex\ttail_hex`, the hex-encoded first and last bytes of a file.
     * Nine words per record: `[type, width, height, complete, edges_ok, pixels, usable, reason, flags]`. The type
     * is IMAGETYPE_JPEG, PNG, GIF or WEBP by magic bytes (else 0). GIF dimensions are the logical screen size;
     * the others come from headerProbeBatch() when it reports the same type (else 0). Completeness mirrors
     * isCompleteTailPhp() for files of 16 bytes or more: WebP compares the RIFF size in the head, JPEG and PNG look
     * for their end marker in the right-trimmed last 4096 tail bytes, and a GIF must end with its `;` trailer.
     * Edges are ok at 150 pixels or more; pixels is the product with each side capped at 2^31. Reason, checked in
     * order: 1 unknown type, 6 file under 16 bytes, 2 GIF (not publishable), 3 no dimensions, 4 too small, 5
     * incomplete, else 0 usable. The size is trimmed 1-15 ASCII digits (else 0); invalid hex (odd length or other
     * bytes) is read as empty. Flags: 1 invalid number, 2 not exactly three fields, 4 invalid head hex, 8 invalid
     * tail hex, 16 uppercase hex, 32 more than MAX_MEASURED_PIXELS.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ImageInspectorPlanBatch(array $records): array
    {
        return self::usesW64RustFfi() ? self::w64ImageInspectorPlanBatchNative($records) : self::w64ImageInspectorPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ImageInspectorPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w64Words($count, implode("\0", $records), 9 * $count) ?? self::w64ImageInspectorPlanBatchPhp($records);
    }

    /** Bytes of an even-length ASCII hex field (empty stays empty); invalid hex sets $invalid and reads as empty. */
    private static function w64Unhex(string $field, int $invalid, int &$flags): string
    {
        if ($field === '') {
            return '';
        }
        if (\strlen($field) % 2 !== 0 || ! ctype_xdigit($field)) {
            $flags |= $invalid;

            return '';
        }
        if (strpbrk($field, 'ABCDEF') !== false) {
            $flags |= 16;
        }

        return (string) hex2bin($field);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64ImageInspectorPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 3 ? 0 : 2;
            $size = self::w64Number($fields[0], 0, $flags);
            $head = self::w64Unhex($fields[1] ?? '', 4, $flags);
            $tail = self::w64Unhex($fields[2] ?? '', 8, $flags);
            $type = match (true) {
                str_starts_with($head, "\xFF\xD8\xFF") => IMAGETYPE_JPEG,
                str_starts_with($head, "\x89PNG\r\n\x1a\n") => IMAGETYPE_PNG,
                str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a') => IMAGETYPE_GIF,
                \strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => IMAGETYPE_WEBP,
                default => 0,
            };
            $width = $height = 0;
            if ($type === IMAGETYPE_GIF) {
                if (\strlen($head) >= 10) {
                    /** @var array{1: int, 2: int} $screen */
                    $screen = unpack('v2', $head, 6);
                    [$width, $height] = [$screen[1], $screen[2]];
                }
            } elseif ($type !== 0) {
                [$probed, $probedWidth, $probedHeight] = self::w52ProbeHeader($head);
                if ($probed === $type) {
                    [$width, $height] = [$probedWidth, $probedHeight];
                }
            }
            if ($type === 0 || $size < 16) {
                $complete = false;
            } elseif ($type === IMAGETYPE_WEBP) {
                /** @var array{1: int} $declared */
                $declared = \strlen($head) >= 8 ? unpack('V', $head, 4) : [1 => 0];
                $complete = \strlen($head) >= 8 && $size >= $declared[1] + 8;
            } else {
                $slice = rtrim(\strlen($tail) > 4096 ? substr($tail, -4096) : $tail, "\0\r\n ");
                $complete = match ($type) {
                    IMAGETYPE_PNG => str_contains(substr($slice, -64), 'IEND'),
                    IMAGETYPE_JPEG => str_contains(substr($slice, -1024), "\xFF\xD9"),
                    default => str_ends_with($slice, ';'),
                };
            }
            $edges = $width >= self::MIN_EDGE && $height >= self::MIN_EDGE;
            $pixels = min($width, 1 << 31) * min($height, 1 << 31);
            if ($pixels > self::MAX_MEASURED_PIXELS) {
                $flags |= 32;
            }
            $reason = match (true) {
                $type === 0 => 1,
                $size < 16 => 6,
                $type === IMAGETYPE_GIF => 2,
                $width === 0 || $height === 0 => 3,
                ! $edges => 4,
                ! $complete => 5,
                default => 0,
            };
            array_push($out, $type, $width, $height, (int) $complete, (int) $edges, $pixels, (int) ($reason === 0), $reason, $flags);
        }

        return $out;
    }
}
