<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * What is known about a partial media file handed to ffmpeg.
 */
final readonly class MediaSourceHints
{
    public const string FFI_CDEF = <<<'C'
        double nntmux_media_available_fraction(uint32_t position, uint8_t has_real, int64_t real, int64_t available, int32_t *status);
        int32_t nntmux_media_head_hint(uint8_t has_real, int64_t real, int64_t available, int64_t *real_out);
        C;

    private const array POSITION_CODES = ['complete' => 0, 'head' => 1, 'mid' => 2];

    /**
     * @param  int|null  $realSize  size of the whole file, when the release told us
     * @param  int  $availableBytes  bytes of it that were downloaded
     */
    public function __construct(
        public SourcePosition $position,
        public ?int $realSize = null,
        public int $availableBytes = 0,
        // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
        public bool $useRustFfi = false,
    ) {}

    public static function complete(): self
    {
        return new self(SourcePosition::Complete);
    }

    public static function head(?int $realSize, int $availableBytes, bool $useRustFfi = false): self
    {
        return ($useRustFfi ? self::headNative($realSize, $availableBytes) : null) ?? self::headPhp($realSize, $availableBytes);
    }

    public static function headPhp(?int $realSize, int $availableBytes): self
    {
        if ($realSize !== null && $realSize > 0 && $availableBytes >= $realSize) {
            return new self(SourcePosition::Complete, $realSize, $availableBytes);
        }

        return new self(SourcePosition::Head, $realSize !== null && $realSize > 0 ? $realSize : null, $availableBytes);
    }

    public static function headNative(?int $realSize, int $availableBytes): ?self
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $real = $ffi->new('int64_t');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_media_head_hint($realSize !== null ? 1 : 0, $realSize ?? 0, $availableBytes, FFI::addr($real));
            // @phpstan-ignore property.notFound
            $normalized = (int) $real->cdata;
        } catch (Throwable) {
            return null;
        }

        return match ($code) {
            0 => new self(SourcePosition::Complete, $normalized, $availableBytes),
            1 => new self(SourcePosition::Head, $normalized, $availableBytes),
            2 => new self(SourcePosition::Head, null, $availableBytes),
            default => null,
        };
    }

    public static function mid(int $availableBytes = 0): self
    {
        return new self(SourcePosition::Mid, null, $availableBytes);
    }

    /**
     * Fraction of the file that was downloaded, when the real size is known.
     */
    public function availableFraction(): ?float
    {
        if (! $this->useRustFfi) {
            return $this->availableFractionPhp();
        }
        $native = $this->availableFractionNative();

        return $native === false ? $this->availableFractionPhp() : $native;
    }

    public function availableFractionPhp(): ?float
    {
        if ($this->position === SourcePosition::Complete) {
            return 1.0;
        }

        if ($this->realSize === null || $this->availableBytes <= 0) {
            return null;
        }

        return min(1.0, $this->availableBytes / $this->realSize);
    }

    /**
     * @return float|false|null False when PHP must evaluate the fraction (Rust unavailable or a zero size).
     */
    public function availableFractionNative(): float|false|null
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return false;
        }
        try {
            $status = $ffi->new('int32_t');
            // @phpstan-ignore method.notFound
            $value = $ffi->nntmux_media_available_fraction(self::POSITION_CODES[$this->position->value], $this->realSize !== null ? 1 : 0, $this->realSize ?? 0, $this->availableBytes, FFI::addr($status));
            // @phpstan-ignore property.notFound
            $code = (int) $status->cdata;
        } catch (Throwable) {
            return false;
        }

        return match ($code) {
            0 => null,
            1 => (float) $value,
            default => false,
        };
    }

    private static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    // Rust is the default: the paired W48.6 benchmark cleared 1.5x on all four metrics (11.53x/6.99x common, 12.52x/9.95x uncommon mean/p99).
    public const bool W48_HINT_EXTRACT_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        int32_t nntmux_w48_media_hint_extract(const char *names, size_t names_len, const char *name_lens, size_t name_lens_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W48_HINT_HEADER = 12;

    public const int W48_HINT_WIDTH = 4;

    private const int W48_MAX_ITEMS = 1_048_576;

    private const array W48_CODEC_WEIGHT = [0, 10, 300, 500, 400, 200, 50];

    private const array W48_CODECS = [
        'x265' => 3, 'h265' => 3, 'hevc' => 3, 'x264' => 2, 'h264' => 2, 'avc' => 2,
        'av1' => 4, 'vp9' => 5, 'xvid' => 1, 'divx' => 1, 'mpeg2' => 6,
    ];

    private const array W48_AUDIO = [
        'truehd' => 9, 'flac' => 8, 'dtsx' => 7, 'dts' => 6, 'ddp' => 5, 'eac' => 5,
        'dd' => 4, 'ac' => 4, 'opus' => 3, 'aac' => 2,
    ];

    private const array W48_SOURCES = [
        'remux' => 6, 'bluray' => 5, 'bdrip' => 5, 'brrip' => 5, 'webdl' => 4,
        'webrip' => 3, 'web' => 3, 'hdtv' => 2, 'dvdrip' => 1,
    ];

    private const array W48_CONTAINERS = [
        'mkv' => 1, 'mp4' => 2, 'avi' => 3, 'ts' => 4, 'm2ts' => 5, 'wmv' => 6,
        'mov' => 7, 'webm' => 8, 'mpg' => 9, 'mpeg' => 9, 'm4v' => 10,
    ];

    public static function useW48HintExtractRustFfi(?bool $enabled): void
    {
        self::w48Toggle(true, $enabled);
    }

    public static function usesW48HintExtractRustFfi(): bool
    {
        return self::w48Toggle() ?? self::W48_HINT_EXTRACT_RUST_FFI_DEFAULT;
    }

    public static function resetW48HintExtractRustFfi(): void
    {
        self::w48Toggle(true);
        self::w48Binding(0, true);
    }

    /**
     * Extract and score media hints from a batch of release or file names. Names are lowercased
     * and split on bytes other than `a-z0-9`. Codecs: 1 XviD/DivX, 2 AVC, 3 HEVC, 4 AV1, 5 VP9,
     * 6 MPEG-2 (first wins). Audio (best wins): 1 MP3, 2 AAC, 3 Opus, 4 AC-3, 5 E-AC-3, 6 DTS,
     * 7 DTS-HD/DTS:X, 8 FLAC, 9 TrueHD. Sources (best wins): 1 DVD, 2 HDTV, 3 WEB/WEBRip,
     * 4 WEB-DL, 5 Blu-ray, 6 remux. Containers come from the extension (1 mkv .. 10 m4v). Flags:
     * 1 HDR, 2 Dolby Vision, 4 Atmos, 8 sample, 16 proper/repack. Heights are the largest
     * `NNNp`/`NNNNi` (240-4320) or 2160 for `4k`/`uhd`; channels come from `N.1`/`N.0`.
     *
     * Layout: `[0]` count, `[1]` no resolution, `[2]` below 720, `[3]` 720-1079, `[4]`
     * 1080-2159, `[5]` 2160 and up, `[6]` HEVC/AV1, `[7]` HDR or Dolby Vision, `[8]` samples,
     * `[9]` best index or -1, `[10]` tokens, `[11]` FNV-1a digest; then per name `codec |
     * container << 8 | audio << 16 | source << 24 | flags << 32`, height, channels and score;
     * then the indices best first (ties keep input order).
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function extractHintBatch(array $names): array
    {
        return (self::usesW48HintExtractRustFfi() ? self::extractHintBatchNative($names) : null) ?? self::extractHintBatchPhp($names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function extractHintBatchPhp(array $names): array
    {
        $names = array_values($names);
        $count = \count($names);
        $out = array_fill(0, self::W48_HINT_HEADER, 0);
        $out[0] = $count;
        $out[9] = -1;
        $scores = [];
        $digest = 2_166_136_261;
        foreach ($names as $name) {
            $lower = strtolower($name);
            $codec = $audio = $source = $flags = $height = $channels = 0;
            $previous = '';
            foreach (preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $out[10]++;
                $base = rtrim($token, '0..9');
                if ($codec === 0) {
                    $codec = self::W48_CODECS[$token] ?? ($previous === 'h' ? ($token === '265' ? 3 : ($token === '264' ? 2 : 0)) : 0);
                }
                $length = \strlen($token);
                if ($token === '4k' || $token === 'uhd') {
                    $height = max($height, 2160);
                } elseif ($length >= 4 && $length <= 5 && ($token[$length - 1] === 'p' || $token[$length - 1] === 'i') && ctype_digit($digits = substr($token, 0, -1))) {
                    $value = (int) $digits;
                    if ($value >= 240 && $value <= 4320) {
                        $height = max($height, $value);
                    }
                }
                $sound = $token === 'mp3' ? 1 : ($base === 'hd' ? (rtrim($previous, '0..9') === 'dts' ? 7 : 0) : (self::W48_AUDIO[$base] ?? 0));
                $audio = max($audio, $sound);
                if ($base === 'atmos') {
                    $flags |= 4;
                }
                if ($base === 'hdr' || $token === 'hdr10plus') {
                    $flags |= 1;
                }
                if ($token === 'dv' || $token === 'dovi') {
                    $flags |= 2;
                }
                if ($token === 'sample') {
                    $flags |= 8;
                }
                if ($token === 'proper' || $token === 'repack') {
                    $flags |= 16;
                }
                $source = max($source, $token === 'dl' ? ($previous === 'web' ? 4 : 0) : (self::W48_SOURCES[$token] ?? 0));
                if (($token === '0' || $token === '1') && $previous !== '' && ($last = $previous[\strlen($previous) - 1]) !== '0' && ctype_digit($last)) {
                    $channels = max($channels, (int) $last + ($token === '1' ? 1 : 0));
                }
                $previous = $token;
            }
            $dot = strrpos($lower, '.');
            $container = $dot === false ? 0 : (self::W48_CONTAINERS[substr($lower, $dot + 1)] ?? 0);
            $score = $height * 10 + self::W48_CODEC_WEIGHT[$codec] + $audio * 40 + $source * 100
                + ($flags & 1 ? 200 : 0) + ($flags & 2 ? 100 : 0) + ($flags & 4 ? 80 : 0) + ($flags & 16 ? 30 : 0)
                + ($container === 0 ? 0 : ($container === 1 ? 50 : ($container === 2 ? 40 : 10)))
                + $channels * 5 - ($flags & 8 ? 5000 : 0);
            $out[$height === 0 ? 1 : ($height < 720 ? 2 : ($height < 1080 ? 3 : ($height < 2160 ? 4 : 5)))]++;
            $out[6] += (int) ($codec === 3 || $codec === 4);
            $out[7] += (int) (($flags & 3) !== 0);
            $out[8] += (int) (($flags & 8) !== 0);
            $digest = (($digest ^ ($codec | $container << 4 | $audio << 8 | $source << 12)) * 16_777_619) & 0xFFFFFFFF;
            $out[] = $codec | $container << 8 | $audio << 16 | $source << 24 | $flags << 32;
            $out[] = $height;
            $out[] = $channels;
            $out[] = $score;
            $scores[] = $score;
        }
        $order = self::w48Rank($scores);
        if ($order !== []) {
            $out[9] = $order[0];
        }
        $out[11] = $digest;

        return [...$out, ...$order];
    }

    /**
     * Rust hints, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @return list<int>|null
     */
    public static function extractHintBatchNative(array $names): ?array
    {
        $names = array_values($names);
        $count = \count($names);
        $fields = self::W48_HINT_HEADER + (self::W48_HINT_WIDTH + 1) * $count;
        $binding = $count <= self::W48_MAX_ITEMS ? self::w48Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $joined = implode("\0", $names);
        $lens = '';
        if ($count > 0 && substr_count($joined, "\0") !== $count - 1) {
            $joined = implode('', $names);
            $lens = pack('V*', ...array_map(strlen(...), $names));
        }
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w48_media_hint_extract($joined, \strlen($joined), $lens, \strlen($lens), $count, $out, $fields);
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
     * Indices by descending score, then ascending index.
     *
     * @param  list<int>  $scores
     * @return list<int>
     */
    private static function w48Rank(array $scores): array
    {
        if ($scores === []) {
            return [];
        }
        $indexes = array_keys($scores);
        array_multisort($scores, SORT_DESC, SORT_NUMERIC, $indexes, SORT_ASC, SORT_NUMERIC);

        return $indexes;
    }

    private static function w48Toggle(bool $set = false, ?bool $enabled = null): ?bool
    {
        /** @var bool|null $state */
        static $state = null;
        if ($set) {
            $state = $enabled;
        }

        return $state;
    }

    /**
     * Cached `additional_w48` binding and an output buffer of at least `$fields` values; `$reset` drops both.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w48Binding(int $fields, bool $reset = false): ?array
    {
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($reset) {
            $ffi = $buffer = null;
            $capacity = 0;

            return null;
        }
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w48', self::W48_FFI_CDEF) ?? false;
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
}
