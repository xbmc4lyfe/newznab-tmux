<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

/**
 * Makes a chunk from the middle of a video decodable by giving it the file's headers.
 *
 * Openings are often black or show logos; a frame from the middle shows the content.
 * Only the containers whose stream can be picked up again at a known marker are supported.
 *
 * splice() defaults to PHP: the joined copy dominates its cost and Zend's strpos/PCRE scans are
 * already native, so the Rust offset scanner (spliceNative()) measured below the 1.5x adoption
 * threshold (see tests/Unit/RustMediaSlicerSplicerParityTest.php).
 */
final class ContainerSplicer
{
    // Production adoption requires >= 1.5x mean and p99 on both W43 workloads.
    public const bool W43_RUST_FFI_DEFAULT = false;

    private static ?bool $w43RustFfi = null;

    public static function useRustFfi(?bool $enabled = true): void
    {
        self::$w43RustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$w43RustFfi ?? self::W43_RUST_FFI_DEFAULT;
    }

    private const string MATROSKA_CLUSTER = "\x1F\x43\xB6\x75";

    private const string MPEG_PACK_START = "\x00\x00\x01\xBA";

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_w436_container_splice(uint8_t container_code, const char *head, size_t head_len, const char *tail, size_t tail_len, size_t *out_head_end, size_t *out_tail_start);
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
        return self::usesRustFfi() ? $this->spliceNative($head, $mid, $container) : $this->splicePhp($head, $mid, $container);
    }

    public function splicePhp(string $head, string $mid, VideoContainer $container): ?string
    {
        return match ($container) {
            VideoContainer::Matroska => $this->joinAt($head, self::MATROSKA_CLUSTER, $mid, self::MATROSKA_CLUSTER),
            VideoContainer::MpegTransport => ($offset = VideoContainer::transportStreamOffsetPhp($mid)) === null ? null : substr($mid, $offset),
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
            return $this->splicePhp($head, $mid, $container);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w436_container_splice($code, $head, strlen($head), $mid, strlen($mid), self::$headEndOut, self::$midStartOut);
        } catch (Throwable) {
            return $this->splicePhp($head, $mid, $container);
        }
        if (! is_int($status) || $status < 0) {
            return $this->splicePhp($head, $mid, $container);
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

    // PHP stays the default: the paired W50.6 benchmark p99 fell below 1.5x in repeated runs.
    private const bool W50_RUST_FFI_DEFAULT = false;

    public const string W50_FFI_CDEF = <<<'C'
        int32_t nntmux_w506_container_splice_batch(const char *heads, size_t heads_len, const char *head_lens, size_t head_lens_len, const char *mids, size_t mids_len, const char *mid_lens, size_t mid_lens_len, const char *mid_sizes, size_t mid_sizes_len, const char *codes, size_t codes_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W50_SPLICE_HEADER = 8;

    public const int W50_SPLICE_ROW = 3;

    private const int W50_MAX_ITEMS = 1_048_576;

    private const int W50_TOTAL_CAP = 1 << 62;

    /** Bytes of a transport stream mid the resume scan can read (five 192-byte packets). */
    private const int W50_TRANSPORT_WINDOW = 960;

    /** Container backing value => code (case index + 1); 0 is none. */
    private const array W50_CONTAINER_CODES = ['matroska' => 1, 'avi' => 2, 'mpeg' => 3, 'mpegts' => 4, 'mov' => 5, 'asf' => 6, 'ogg' => 7];

    public static function usesW50RustFfi(): bool
    {
        return self::w50RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 50.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('swap', $previous);
        }
    }

    /**
     * Plan a batch of splices as `splicePhp()` does, as offsets: a spliced copy is
     * `substr($head, 0, head end).substr($mid, mid start)`. Containers, heads and mids are
     * matched by position (a missing head or mid is empty).
     *
     * Layout: `[0]` count, `[1]` spliced, `[2]` supported without a resume point, `[3]`
     * unsupported, `[4]` joined bytes, `[5]` largest joined length and `[6]` its first index (-1
     * when none), `[7]` FNV-1a digest; then three values per pair: `status | code << 4` (1
     * spliced, 0 no resume point, 2 unsupported; code is the case index + 1, 0 for none), the
     * head end and the mid start (both 0 unless spliced).
     *
     * @param  array<array-key, string>  $heads
     * @param  array<array-key, string>  $mids
     * @param  array<array-key, VideoContainer|null>  $containers
     * @return list<int>
     */
    public static function planSpliceBatch(array $heads, array $mids, array $containers): array
    {
        return (self::usesW50RustFfi() ? self::planSpliceBatchNative($heads, $mids, $containers) : null) ?? self::planSpliceBatchPhp($heads, $mids, $containers);
    }

    /**
     * @param  array<array-key, string>  $heads
     * @param  array<array-key, string>  $mids
     * @param  array<array-key, VideoContainer|null>  $containers
     * @return list<int>
     */
    public static function planSpliceBatchPhp(array $heads, array $mids, array $containers): array
    {
        $heads = array_values($heads);
        $mids = array_values($mids);
        $out = array_fill(0, self::W50_SPLICE_HEADER, 0);
        $out[0] = \count($containers);
        $out[6] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach (array_values($containers) as $index => $container) {
            $head = $heads[$index] ?? '';
            $mid = $mids[$index] ?? '';
            $code = $container === null ? 0 : self::W50_CONTAINER_CODES[$container->value];
            $offsets = match ($container) {
                VideoContainer::Matroska => ($headEnd = strpos($head, self::MATROSKA_CLUSTER)) === false || ($midStart = strpos($mid, self::MATROSKA_CLUSTER)) === false
                    ? null : [$headEnd, $midStart],
                VideoContainer::MpegTransport => ($midStart = VideoContainer::transportStreamOffsetPhp($mid)) === null ? null : [0, $midStart],
                VideoContainer::MpegProgram => ($midStart = strpos($mid, self::MPEG_PACK_START)) === false ? null : [0, $midStart],
                VideoContainer::Avi => ($movi = strpos($head, 'movi')) === false || $movi < 8 || substr($head, $movi - 8, 4) !== 'LIST'
                    || preg_match('/\d\d(?:dc|db|wb)/', $mid, $chunk, PREG_OFFSET_CAPTURE) !== 1
                    ? null : [$movi + 4, $chunk[0][1]],
                default => false,
            };
            if ($offsets === false) {
                $out[3]++;
                $row = [2 | $code << 4, 0, 0];
            } elseif ($offsets === null) {
                $out[2]++;
                $row = [$code << 4, 0, 0];
            } else {
                $out[1]++;
                $joined = $offsets[0] + \strlen($mid) - $offsets[1];
                $out[4] = $joined > self::W50_TOTAL_CAP - $out[4] ? self::W50_TOTAL_CAP : $out[4] + $joined;
                if ($joined > $out[5] || $out[6] < 0) {
                    $out[5] = $joined;
                    $out[6] = $index;
                }
                $row = [1 | $code << 4, $offsets[0], $offsets[1]];
            }
            $digest = (($digest ^ $row[0]) * 16_777_619) & 0xFFFFFFFF;
            array_push($rows, ...$row);
        }
        $out[7] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plan, or null when PHP must compute it (unavailable library or rejected input).
     * Only the bytes a container's resume point needs cross: heads for Matroska and AVI, mids
     * for the four spliceable containers, cut to the scan window for MPEG transport streams,
     * with their full lengths.
     *
     * @param  array<array-key, string>  $heads
     * @param  array<array-key, string>  $mids
     * @param  array<array-key, VideoContainer|null>  $containers
     * @return list<int>|null
     */
    public static function planSpliceBatchNative(array $heads, array $mids, array $containers): ?array
    {
        $count = \count($containers);
        $fields = self::W50_SPLICE_HEADER + self::W50_SPLICE_ROW * $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W50_MAX_ITEMS ? self::w50RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $heads = array_values($heads);
        $mids = array_values($mids);
        $codes = '';
        $headParts = $midParts = $headLens = $midLens = $midSizes = [];
        foreach (array_values($containers) as $index => $container) {
            $code = $container === null ? 0 : self::W50_CONTAINER_CODES[$container->value];
            $codes .= \chr($code);
            $head = $code === 1 || $code === 2 ? $heads[$index] ?? '' : '';
            $mid = $code >= 1 && $code <= 4 ? $mids[$index] ?? '' : '';
            $midSizes[] = \strlen($mid);
            if ($code === 4 && \strlen($mid) > self::W50_TRANSPORT_WINDOW) {
                $mid = substr($mid, 0, self::W50_TRANSPORT_WINDOW);
            }
            $headParts[] = $head;
            $midParts[] = $mid;
            $headLens[] = \strlen($head);
            $midLens[] = \strlen($mid);
        }
        if ($count > 0 && max(max($headLens), max($midLens)) > 0xFFFFFFFF) {
            return null;
        }
        $headJoined = implode('', $headParts);
        $midJoined = implode('', $midParts);
        $headLenPack = pack('V*', ...$headLens);
        $midLenPack = pack('V*', ...$midLens);
        $midSizePack = pack('q*', ...$midSizes);
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w506_container_splice_batch(
                $headJoined, \strlen($headJoined), $headLenPack, \strlen($headLenPack),
                $midJoined, \strlen($midJoined), $midLenPack, \strlen($midLenPack), $midSizePack, \strlen($midSizePack),
                $codes, \strlen($codes), $count, $out, $fields,
            );
            if ($status !== 1) {
                return null;
            }
            $unpacked = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $unpacked === false ? null : array_values($unpacked);
    }

    /**
     * Wave 50.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W50_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w50', self::W50_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    // Rust is the default only while the paired W55.6 benchmark clears 1.5x on all four metrics.
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        int32_t nntmux_w556_resume_probe_batch(const char *mids, size_t mids_len, const char *mid_lens, size_t mid_lens_len, size_t count, int64_t window, int64_t *out, size_t out_cap);
        C;

    public const int W55_PROBE_HEADER = 8;

    public const int W55_DEFAULT_WINDOW = 65_536;

    private const int W55_MAX_ITEMS = 1_048_576;

    private const int W55_WINDOW_CAP = 1_048_575;

    /**
     * Find every resume point splicePhp() looks for in the first `$window` bytes of each mid chunk (0 or
     * less uses 65,536; capped at 2^20-1), so a chunk of unknown container can be matched to the ones it
     * can resume as: the Matroska cluster, the MPEG pack start, the transport stream offset
     * (VideoContainer::transportStreamOffsetPhp()) and the AVI stream chunk.
     *
     * Layout: `[0]` count, `[1]` window, `[2..5]` chunks with a cluster / pack start / transport offset /
     * AVI chunk, `[6]` chunks with none, `[7]` FNV-1a digest; then two words per chunk: `cluster + 1 |
     * (pack + 1) << 21 | (transport + 1) << 42` (0 when absent) and `avi + 1 | stride << 21 | found << 23
     * | scanned << 27` (stride 1 for 188-byte and 2 for 192-byte packets; found bits in header order;
     * scanned bytes).
     *
     * @param  array<array-key, string>  $mids
     * @return list<int>
     */
    public static function probeResumeBatch(array $mids, int $window = self::W55_DEFAULT_WINDOW): array
    {
        return (self::usesW55RustFfi() ? self::probeResumeBatchNative($mids, $window) : null) ?? self::probeResumeBatchPhp($mids, $window);
    }

    /**
     * @param  array<array-key, string>  $mids
     * @return list<int>
     */
    public static function probeResumeBatchPhp(array $mids, int $window = self::W55_DEFAULT_WINDOW): array
    {
        $window = $window <= 0 ? self::W55_DEFAULT_WINDOW : min($window, self::W55_WINDOW_CAP);
        $out = array_fill(0, self::W55_PROBE_HEADER, 0);
        $out[0] = \count($mids);
        $out[1] = $window;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($mids as $mid) {
            $hay = \strlen($mid) > $window ? substr($mid, 0, $window) : $mid;
            $length = \strlen($hay);
            $cluster = strpos($hay, self::MATROSKA_CLUSTER);
            $cluster = $cluster === false ? 0 : $cluster + 1;
            $pack = strpos($hay, self::MPEG_PACK_START);
            $pack = $pack === false ? 0 : $pack + 1;
            $transport = $stride = 0;
            foreach ([1 => 188, 2 => 192] as $code => $step) {
                for ($offset = 0, $limit = min($length - $step * 4, $step); $offset < $limit; $offset++) {
                    if ($hay[$offset] === "\x47" && $hay[$offset + $step] === "\x47"
                        && $hay[$offset + $step * 2] === "\x47" && $hay[$offset + $step * 3] === "\x47"
                    ) {
                        $transport = $offset + 1;
                        $stride = $code;
                        break 2;
                    }
                }
            }
            $avi = preg_match('/\d\d(?:dc|db|wb)/', $hay, $chunk, PREG_OFFSET_CAPTURE) === 1 ? $chunk[0][1] + 1 : 0;
            $found = ($cluster > 0 ? 1 : 0) | ($pack > 0 ? 2 : 0) | ($transport > 0 ? 4 : 0) | ($avi > 0 ? 8 : 0);
            $out[2] += $found & 1;
            $out[3] += ($found >> 1) & 1;
            $out[4] += ($found >> 2) & 1;
            $out[5] += $found >> 3;
            $out[6] += $found === 0 ? 1 : 0;
            $first = $cluster | $pack << 21 | $transport << 42;
            $second = $avi | $stride << 21 | $found << 23 | $length << 27;
            $digest = (($digest ^ (($first ^ ($first >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $digest = (($digest ^ (($second ^ ($second >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $first;
            $rows[] = $second;
        }
        $out[7] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust probe, or null when PHP must compute it (unavailable library or rejected input). Only each
     * chunk's scan window crosses.
     *
     * @param  array<array-key, string>  $mids
     * @return list<int>|null
     */
    public static function probeResumeBatchNative(array $mids, int $window = self::W55_DEFAULT_WINDOW): ?array
    {
        $count = \count($mids);
        if ($count > self::W55_MAX_ITEMS) {
            return null;
        }
        $window = $window <= 0 ? self::W55_DEFAULT_WINDOW : min($window, self::W55_WINDOW_CAP);
        $mids = array_values($mids);
        $lengths = array_map('strlen', $mids);
        if ($count > 0 && max($lengths) > $window) {
            $mids = array_map(static fn (string $mid): string => \strlen($mid) > $window ? substr($mid, 0, $window) : $mid, $mids);
            $lengths = array_map('strlen', $mids);
        }
        $joined = implode('', $mids);
        $lengthPack = pack('V*', ...$lengths);
        $fields = self::W55_PROBE_HEADER + 2 * $count;

        // @phpstan-ignore method.notFound
        return self::w55Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w556_resume_probe_batch($joined, \strlen($joined), $lengthPack, \strlen($lengthPack), $count, $window, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 55.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $forced = self::w55RustFfiState();

        return \is_bool($forced) ? $forced : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 55.6 routing and binding, kept in function statics so earlier waves' static state stays
     * untouched. No `$op` returns the forced routing (null for the default); `'set'` replaces it with
     * `$val` and returns the previous value; `'binding'` returns the cached `additional_w55` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w55', self::W55_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 55.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w55Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w55RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    // ========================================================================
    // WAVE 64.4 BATCH HELPER
    // ========================================================================

    /** PHP: three 1000-round paired runs stayed at 0.89-0.90x mean and 0.86-1.10x p99. Zend's strpos already scans natively, and joining the binary heads and tails (with the 0x01 twin) costs more than the PHP scan; see RustAdditionalSourcePlanPar2EntryPersistenceSlicerContainerW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = false;

    private const string W64_FFI_CDEF = 'ssize_t nntmux_w644_container_splicer_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w64ContainerSplicerPlanBatch() record. */
    public const int W64_ROW_WORDS = 5;

    /** int64 summary words after the w64ContainerSplicerPlanBatch() records (none for an empty batch). */
    public const int W64_TRAILER_WORDS = 9;

    private const int W64_VALUE_CAP = (1 << 40) - 1;

    /**
     * Head/tail splice plan rows for [VideoContainer value, head bytes, tail bytes, tail file
     * offset] items.
     *
     * Codes: 1 matroska (head and tail at their first Cluster ID), 2 avi (head through `movi`
     * after `LIST`, tail at the first `\d\d(?:dc|db|wb)` chunk), 3 mpeg (tail at the first pack
     * start), 4 mpegts (tail at transportStreamOffsetPhp()'s packet; flags 1 a 188-byte or 2 a
     * 192-byte stride, 4 the absolute offset is stride-aligned), 5 mov (head through a complete
     * top-level `moov` box, tail at the first `moof`/`mdat`/`free`/`skip`/`moov` header of at
     * least 8 bytes; flags 1 `ftyp` first, 2 `moov`, 4 `mdat`, 8 `moov` before `mdat`, 16 a bad
     * box size), 0/6/7 unsupported. Status: 1 spliced, 0 no resume point, 2 unsupported. Tail
     * offsets clamp to [0, 2^40).
     *
     * Rows [status | code << 4 | flags << 8 | top-level boxes << 16 (clamped to 2^16 - 1) | head
     * length << 32 (clamped to 2^24 - 1), head end, tail start (both -1 when not found), joined
     * length (0 unless spliced), absolute tail start (-1 when not found)], then the trailer
     * [records, spliced, no resume point, unsupported, joined bytes (saturating at 2^62), largest
     * joined length and its first index (-1 when none), fast-start MP4 heads, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $items
     * @return list<int>
     */
    public static function w64ContainerSplicerPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64ContainerSplicerPlanBatchNative($items) : self::w64ContainerSplicerPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $items
     * @return list<int>
     */
    public static function w64ContainerSplicerPlanBatchPhp(array $items): array
    {
        self::w64Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, -1, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as $i => [$container, $head, $tail, $offset]) {
            $code = self::W50_CONTAINER_CODES[$container] ?? 0;
            $offset = max(0, min($offset, self::W64_VALUE_CAP));
            $flags = $boxes = 0;
            $headEnd = $tailStart = -1;
            if ($code === 1) {
                $found = strpos($head, self::MATROSKA_CLUSTER);
                $headEnd = $found === false ? -1 : $found;
                $found = strpos($tail, self::MATROSKA_CLUSTER);
                $tailStart = $found === false ? -1 : $found;
            } elseif ($code === 2) {
                $movi = strpos($head, 'movi');
                if ($movi !== false && $movi >= 8 && substr($head, $movi - 8, 4) === 'LIST') {
                    $headEnd = $movi + 4;
                }
                $tailStart = preg_match('/\d\d(?:dc|db|wb)/', $tail, $chunk, PREG_OFFSET_CAPTURE) === 1 ? $chunk[0][1] : -1;
            } elseif ($code === 3) {
                $headEnd = 0;
                $found = strpos($tail, self::MPEG_PACK_START);
                $tailStart = $found === false ? -1 : $found;
            } elseif ($code === 4) {
                $headEnd = 0;
                [$found, $stride] = self::w64TransportOffset($tail);
                if ($found >= 0) {
                    $tailStart = $found;
                    $flags = ($stride === 188 ? 1 : 2) | (($offset + $found) % $stride === 0 ? 4 : 0);
                }
            } elseif ($code === 5) {
                [$flags, $boxes, $headEnd] = self::w64Mp4Head($head);
                $tailStart = self::w64Mp4Tail($tail);
            }
            $status = $code < 1 || $code > 5 ? 2 : ($headEnd >= 0 && $tailStart >= 0 ? 1 : 0);
            $joined = $status === 1 ? $headEnd + \strlen($tail) - $tailStart : 0;
            $word = $status | $code << 4 | $flags << 8 | min($boxes, 0xFFFF) << 16 | min(\strlen($head), 0xFFFFFF) << 32;
            array_push($out, $word, $headEnd, $tailStart, $joined, $tailStart >= 0 ? $offset + $tailStart : -1);
            if ($status === 1) {
                $t[1]++;
                $t[4] = min($t[4] + $joined, 1 << 62);
                if ($t[6] < 0 || $joined > $t[5]) {
                    $t[5] = $joined;
                    $t[6] = $i;
                }
            } elseif ($status === 0) {
                $t[2]++;
            } else {
                $t[3]++;
            }
            if ($code === 5 && ($flags & 8) !== 0) {
                $t[7]++;
            }
            $value = $word ^ $headEnd << 8 ^ $tailStart << 24;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[8] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $items
     * @return list<int>
     */
    public static function w64ContainerSplicerPlanBatchNative(array $items): array
    {
        self::w64Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w64Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)]);
        $ints = pack('P*', ...array_column($items, 3));

        return self::w64Call($blob, $twin, $ints, $count, $count * self::W64_ROW_WORDS + self::W64_TRAILER_WORDS)
            ?? self::w64ContainerSplicerPlanBatchPhp($items);
    }

    /**
     * transportStreamOffsetPhp() with the stride that matched: [offset, stride] or [-1, 0].
     *
     * @return array{0: int, 1: int}
     */
    private static function w64TransportOffset(string $data): array
    {
        $length = \strlen($data);
        foreach ([188, 192] as $stride) {
            for ($offset = 0, $limit = min($length - $stride * 4, $stride); $offset < $limit; $offset++) {
                if ($data[$offset] === "\x47" && $data[$offset + $stride] === "\x47"
                    && $data[$offset + $stride * 2] === "\x47" && $data[$offset + $stride * 3] === "\x47"
                ) {
                    return [$offset, $stride];
                }
            }
        }

        return [-1, 0];
    }

    /**
     * [flags, top-level boxes, end of a complete moov box or -1] of an ISO media head.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function w64Mp4Head(string $head): array
    {
        $length = \strlen($head);
        $pos = $flags = $boxes = 0;
        $moov = $moovEnd = $mdat = -1;
        while ($pos + 8 <= $length) {
            /** @var array{1: int} $word */
            $word = unpack('N', $head, $pos);
            $size = $word[1];
            $type = substr($head, $pos + 4, 4);
            $header = 8;
            if ($size === 1) {
                if ($pos + 16 > $length) {
                    break;
                }
                /** @var array{1: int} $large */
                $large = unpack('J', $head, $pos + 8);
                $size = $large[1];
                $header = 16;
                if ($size > 1 << 62) {
                    $flags |= 16;
                    break;
                }
            } elseif ($size === 0) {
                $size = $length - $pos;
            }
            if ($size < $header) {
                $flags |= 16;
                break;
            }
            $boxes++;
            if ($pos === 0 && $type === 'ftyp') {
                $flags |= 1;
            }
            if ($type === 'moov' && $moov < 0) {
                $moov = $pos;
                $moovEnd = $pos + $size;
                $flags |= 2;
            }
            if ($type === 'mdat' && $mdat < 0) {
                $mdat = $pos;
                $flags |= 4;
            }
            $pos += $size;
        }
        if ($moov >= 0 && ($mdat < 0 || $moov < $mdat)) {
            $flags |= 8;
        }

        return [$flags, $boxes, $moov >= 0 && $moovEnd <= $length ? $moovEnd : -1];
    }

    /** First offset of a moof/mdat/free/skip/moov box header of at least 8 bytes, or -1. */
    private static function w64Mp4Tail(string $tail): int
    {
        if (\strlen($tail) < 8) {
            return -1;
        }
        $best = -1;
        foreach (['moof', 'mdat', 'free', 'skip', 'moov'] as $type) {
            $at = 4;
            while (($at = strpos($tail, $type, $at)) !== false && ($best < 0 || $at - 4 < $best)) {
                /** @var array{1: int} $size */
                $size = unpack('N', $tail, $at - 4);
                if ($size[1] >= 8) {
                    $best = $at - 4;
                    break;
                }
                $at++;
            }
        }

        return $best;
    }

    /** @param array<array-key, mixed> $items */
    private static function w64Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W64 container splicer plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 4 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_int($item[3])) {
                throw new InvalidArgumentException('W64 container splicer plan batch items must be [string container, string head, string tail, int tail offset] lists.');
            }
        }
    }

    public static function usesW64RustFfi(): bool
    {
        $enabled = self::w64RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W64_RUST_FFI_DEFAULT;
    }

    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('enabled', true);
    }

    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('enabled', false);
    }

    /** Drop the W64 override, binding, output buffer and declined-call count; routing returns to W64_RUST_FFI_DEFAULT. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Run $callback with the W64 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW64RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w64RustFfiState('enabled');
        self::w64RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w64RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w64Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w64Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w64RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w64', self::W64_FFI_CDEF) ?? false;
            self::w64RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w64RustFfiState('out', $out);
                self::w64RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W64_FFI_CDEF.)
            $written = $ffi->nntmux_w644_container_splicer_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w64RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W64 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
