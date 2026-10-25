<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Video container formats, named by their ffmpeg demuxer.
 *
 * ffmpeg is told the demuxer instead of probing for one, so a downloaded file
 * can never be read as a playlist that pulls in other files.
 */
enum VideoContainer: string
{
    case Matroska = 'matroska';
    case Avi = 'avi';
    case MpegProgram = 'mpeg';
    case MpegTransport = 'mpegts';
    case Mp4 = 'mov';
    case Asf = 'asf';
    case Ogg = 'ogg';

    /**
     * Recognise a container from the first bytes of a file.
     */
    public static function detect(string $head, ?string $libraryPath = null): ?self
    {
        if (strlen($head) < 12) {
            return null;
        }

        $rust = RustPipelineLibrary::videoContainerDetect($head, $libraryPath);
        if ($rust !== null) {
            return match ($rust) {
                1 => self::Matroska,
                2 => self::Avi,
                3 => self::MpegProgram,
                4 => self::MpegTransport,
                5 => self::Mp4,
                6 => self::Asf,
                7 => self::Ogg,
                default => null,
            };
        }

        return self::detectPhp($head);
    }

    public static function detectPhp(string $head): ?self
    {
        if (strlen($head) < 12) {
            return null;
        }

        return match (true) {
            str_starts_with($head, "\x1A\x45\xDF\xA3") => self::Matroska,
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'AVI ' => self::Avi,
            str_starts_with($head, "\x00\x00\x01\xBA"), str_starts_with($head, "\x00\x00\x01\xB3") => self::MpegProgram,
            substr($head, 4, 4) === 'ftyp', substr($head, 4, 4) === 'moov' => self::Mp4,
            str_starts_with($head, "\x30\x26\xB2\x75\x8E\x66\xCF\x11") => self::Asf,
            str_starts_with($head, 'OggS') => self::Ogg,
            self::transportStreamOffsetPhp($head) !== null => self::MpegTransport,
            default => null,
        };
    }

    /**
     * Guess a container from a file name's extension.
     */
    public static function fromFileName(string $name): ?self
    {
        if (preg_match('/\.([a-z0-9]{2,4})"?(?:\s|$)/i', trim($name), $match) !== 1) {
            return null;
        }

        return match (strtolower($match[1])) {
            'mkv', 'mk3d', 'webm' => self::Matroska,
            'avi', 'divx', 'xvid' => self::Avi,
            'mpg', 'mpeg', 'vob', 'm2v', 'mpv' => self::MpegProgram,
            'ts', 'm2ts', 'mts' => self::MpegTransport,
            'mp4', 'm4v', 'mov', 'qt' => self::Mp4,
            'wmv', 'asf' => self::Asf,
            'ogv', 'ogm' => self::Ogg,
            default => null,
        };
    }

    /**
     * Offset of the first transport stream packet, checking several packets of 188 or 192 bytes.
     */
    public static function transportStreamOffset(string $data, ?string $libraryPath = null): ?int
    {
        $supported = false;
        $rust = RustPipelineLibrary::videoTransportStreamOffset($data, $libraryPath, $supported);
        if ($supported) {
            return $rust;
        }

        return self::transportStreamOffsetPhp($data);
    }

    public static function transportStreamOffsetPhp(string $data): ?int
    {
        foreach ([188, 192] as $stride) {
            for ($offset = 0, $limit = min(strlen($data) - $stride * 4, $stride); $offset < $limit; $offset++) {
                if ($data[$offset] === "\x47" && $data[$offset + $stride] === "\x47"
                    && $data[$offset + $stride * 2] === "\x47" && $data[$offset + $stride * 3] === "\x47"
                ) {
                    return $offset;
                }
            }
        }

        return null;
    }

    public function extension(): string
    {
        return match ($this) {
            self::Matroska => 'mkv',
            self::Avi => 'avi',
            self::MpegProgram => 'mpg',
            self::MpegTransport => 'ts',
            self::Mp4 => 'mp4',
            self::Asf => 'wmv',
            self::Ogg => 'ogv',
        };
    }

    /**
     * Whether a chunk from the middle of a file can be decoded, given at most the file's opening headers.
     * MP4 keeps its index in one box, often at the end; ASF and Ogg need more than a prefix.
     */
    public function canResumeMidStream(): bool
    {
        return match ($this) {
            self::Matroska, self::Avi, self::MpegProgram, self::MpegTransport => true,
            default => false,
        };
    }

    // Rust is the default only while the paired W49.6 benchmark clears 1.5x on all four metrics.
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        int32_t nntmux_w49_video_container_classify(const char *names, size_t names_len, const char *name_twin, size_t name_twin_len, const char *head_records, size_t head_records_len, const char *head_lens, size_t head_lens_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W49_CONTAINER_HEADER = 14;

    private const int W49_MAX_ITEMS = 1_048_576;

    /** `detectPhp()` reads no byte past this offset, so heads cross as fixed records of this size. */
    private const int W49_HEAD_WINDOW = 960;

    /** Demuxer => container code (case index + 1); 0 is none. */
    private const array W49_CODES = [
        'matroska' => 1, 'avi' => 2, 'mpeg' => 3, 'mpegts' => 4, 'mov' => 5, 'asf' => 6, 'ogg' => 7,
    ];

    public static function usesW49RustFfi(): bool
    {
        return self::w49RustFfiState() === true;
    }

    /**
     * Run `$callback` with the Wave 49.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w49RustFfiState('swap', $enabled);
        try {
            return $callback();
        } finally {
            self::w49RustFfiState('swap', $previous);
        }
    }

    /**
     * Classify a batch of files from their names and first bytes: the header container
     * (`detectPhp()`), the name container (`fromFileName()`) and the effective one (header
     * first). Missing names or heads are empty. Flags: 1 header detected, 2 name only,
     * 4 header and name disagree, 8 effective container can resume mid-stream.
     *
     * Layout: `[0]` count, `[1]` unknown, `[2..8]` per effective container, `[9]` header
     * detected, `[10]` name only, `[11]` mismatches, `[12]` resumable, `[13]` FNV-1a digest;
     * then per file `header | name << 4 | effective << 8 | flags << 12`.
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @return list<int>
     */
    public static function classifyContainerBatch(array $names, array $heads): array
    {
        return (self::usesW49RustFfi() ? self::classifyContainerBatchNative($names, $heads) : null) ?? self::classifyContainerBatchPhp($names, $heads);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @return list<int>
     */
    public static function classifyContainerBatchPhp(array $names, array $heads): array
    {
        $names = array_values($names);
        $heads = array_values($heads);
        $out = array_fill(0, self::W49_CONTAINER_HEADER, 0);
        $out[0] = \count($names);
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($names as $index => $name) {
            $detected = self::detectPhp($heads[$index] ?? '');
            $named = self::fromFileName($name);
            $effective = $detected ?? $named;
            $header = $detected === null ? 0 : self::W49_CODES[$detected->value];
            $fromName = $named === null ? 0 : self::W49_CODES[$named->value];
            $code = $effective === null ? 0 : self::W49_CODES[$effective->value];
            $flags = ($header !== 0 ? 1 : 0)
                | ($header === 0 && $fromName !== 0 ? 2 : 0)
                | ($header !== 0 && $fromName !== 0 && $header !== $fromName ? 4 : 0)
                | ($effective?->canResumeMidStream() ? 8 : 0);
            $out[1 + $code]++;
            $out[9] += $flags & 1;
            $out[10] += $flags >> 1 & 1;
            $out[11] += $flags >> 2 & 1;
            $out[12] += $flags >> 3 & 1;
            $value = $header | $fromName << 4 | $code << 8;
            $digest = (($digest ^ $value) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $value | $flags << 12;
        }
        $out[13] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust classification, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @return list<int>|null
     */
    public static function classifyContainerBatchNative(array $names, array $heads): ?array
    {
        $names = array_values($names);
        $count = \count($names);
        $fields = self::W49_CONTAINER_HEADER + $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W49_MAX_ITEMS ? self::w49RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joinedNames, $nameTwin] = self::w49Strings($names);
        $heads = array_values($heads);
        if (\count($heads) !== $count) {
            $heads = array_pad(\array_slice($heads, 0, $count), $count, '');
        }
        $records = $count === 0 ? '' : pack(str_repeat('a'.self::W49_HEAD_WINDOW, $count), ...$heads);
        $headLens = $count === 0 ? '' : pack('V*', ...array_map(strlen(...), $heads));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w49_video_container_classify($joinedNames, \strlen($joinedNames), $nameTwin, \strlen($nameTwin),
                $records, \strlen($records), $headLens, \strlen($headLens), $count, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Wave 49.6 routing: null reads the toggle, `swap` replaces it (null restores the default)
     * and returns the previous value, `buffer` returns the cached binding and an output buffer
     * of at least `$val` values. Enums and readonly classes cannot declare static properties,
     * so the state lives in function-local statics.
     */
    private static function w49RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === null) {
            return $enabled ?? self::W49_RUST_FFI_DEFAULT;
        }
        if ($op === 'swap') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w49', self::W49_FFI_CDEF) ?? false;
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
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w49Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }
}
