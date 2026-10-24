<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Makes a chunk from the middle of a video decodable by giving it the file's headers.
 *
 * Openings are often black or show logos; a frame from the middle shows the content.
 * Only the containers whose stream can be picked up again at a known marker are supported.
 *
 * splice() stays on PHP: the joined copy dominates its cost and Zend's strpos/PCRE scans are
 * already native, so the Rust offset scanner (spliceNative()) measured below the 1.5x adoption
 * threshold (see tests/Unit/RustMediaSlicerSplicerParityTest.php).
 */
final class ContainerSplicer
{
    private const string MATROSKA_CLUSTER = "\x1F\x43\xB6\x75";

    private const string MPEG_PACK_START = "\x00\x00\x01\xBA";

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_container_splice(uint8_t container_code, const char *head, size_t head_len, const char *tail, size_t tail_len, size_t *out_head_end, size_t *out_tail_start);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $offsets = null;

    private static ?CData $headEndOut = null;

    private static ?CData $midStartOut = null;

    /**
     * @param  string  $head  the start of the file
     * @param  string  $mid  bytes from later in the same file
     */
    public function splice(string $head, string $mid, VideoContainer $container): ?string
    {
        return match ($container) {
            VideoContainer::Matroska => $this->joinAt($head, self::MATROSKA_CLUSTER, $mid, self::MATROSKA_CLUSTER),
            VideoContainer::MpegTransport => ($offset = VideoContainer::transportStreamOffset($mid)) === null ? null : substr($mid, $offset),
            VideoContainer::MpegProgram => ($offset = strpos($mid, self::MPEG_PACK_START)) === false ? null : substr($mid, $offset),
            VideoContainer::Avi => $this->spliceAvi($head, $mid),
            default => null,
        };
    }

    /**
     * splice() with the marker scan done by the Rust pipeline library; falls back to splice()
     * (and leaves $supported false) when the library is unavailable or rejects the call.
     */
    public function spliceNative(string $head, string $mid, VideoContainer $container, bool &$supported = false): ?string
    {
        $supported = false;
        $code = match ($container) {
            VideoContainer::Matroska => 1,
            VideoContainer::Avi => 2,
            VideoContainer::MpegProgram => 3,
            VideoContainer::MpegTransport => 4,
            default => 0,
        };
        $ffi = self::ffi();
        if ($ffi === null || self::$headEndOut === null || self::$midStartOut === null || self::$offsets === null) {
            return $this->splice($head, $mid, $container);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_container_splice($code, $head, strlen($head), $mid, strlen($mid), self::$headEndOut, self::$midStartOut);
        } catch (Throwable) {
            return $this->splice($head, $mid, $container);
        }
        if (! is_int($status) || $status < 0) {
            return $this->splice($head, $mid, $container);
        }
        $supported = true;
        if ($status !== 1) {
            return null;
        }

        return $this->join($head, (int) self::$offsets[0], $mid, (int) self::$offsets[1]);
    }

    /**
     * Keep $head up to its first $headMarker, then continue at $mid's first $midMarker.
     */
    private function joinAt(string $head, string $headMarker, string $mid, string $midMarker): ?string
    {
        $headEnd = strpos($head, $headMarker);
        $midStart = strpos($mid, $midMarker);
        if ($headEnd === false || $midStart === false) {
            return null;
        }

        return $this->join($head, $headEnd, $mid, $midStart);
    }

    /**
     * Headers up to the start of the 'movi' list, then the first stream chunk of $mid.
     */
    private function spliceAvi(string $head, string $mid): ?string
    {
        $movi = strpos($head, 'movi');
        if ($movi === false || $movi < 8 || substr($head, $movi - 8, 4) !== 'LIST'
            || preg_match('/\d\d(?:dc|db|wb)/', $mid, $chunk, PREG_OFFSET_CAPTURE) !== 1
        ) {
            return null;
        }

        return $this->join($head, $movi + 4, $mid, $chunk[0][1]);
    }

    /**
     * substr($head, 0, $headEnd).substr($mid, $midStart), copying the multi-megabyte tail once.
     */
    private function join(string $head, int $headEnd, string $mid, int $midStart): string
    {
        return substr_replace($mid, substr($head, 0, $headEnd), 0, $midStart);
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            $ffi = RustPipelineLibrary::resolveDomain('container_splicer', self::FFI_CDEF);
            if ($ffi !== null) {
                try {
                    $offsets = $ffi->new('size_t[2]');
                    self::$headEndOut = FFI::addr($offsets[0]);
                    self::$midStartOut = FFI::addr($offsets[1]);
                    self::$offsets = $offsets;
                    self::$ffi = $ffi;
                } catch (Throwable) {
                    self::$ffi = null;
                }
            }
        }

        return self::$ffi;
    }
}
