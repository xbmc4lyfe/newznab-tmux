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

    // Rust is the default only while the paired W54.6 benchmark clears 1.5x on all four metrics.
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        int32_t nntmux_w546_hint_normalize_batch(const char *positions, size_t positions_len, const char *position_lens, size_t position_lens_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W54_HINT_HEADER = 15;

    private const int W54_MAX_ITEMS = 1_048_576;

    private const int W54_VALUE_CAP = 1_099_511_627_775;

    private const int W54_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** `SourcePosition` backing value => code; anything else is code 3 and treated as a head. */
    private const array W54_POSITION_CODES = ['complete' => 0, 'head' => 1, 'mid' => 2];

    /**
     * `headPhp()` normalization and `availableFractionPhp()` over many hints, as integer parts per million.
     *
     * Per hint a position value, a real size (null, 0 or less is unknown; capped at 2^40-1) and available
     * bytes (clamped to [0, 2^40)); missing values are unknown and 0. A head whose real size is covered
     * becomes complete; a mid hint drops its real size as `mid()` does. Layout: `[0]` count, `[1..4]`
     * inputs per code, `[5..7]` normalized complete/head/mid, `[8]` promoted heads, `[9]` partial hints
     * without a real size, `[10]` available and `[11]` missing bytes (capped at 2^62), `[12]` mean fraction
     * ppm of hints with a fraction, `[13]` first lowest fraction (-1 when none), `[14]` FNV-1a digest; then
     * per hint `normalized | code << 2 | (ppm + 1) << 4 | flags << 24 | missing_kib << 28`, where `ppm + 1`
     * is 0 for a null fraction, flag bit 0 is promoted, bit 1 unknown real size, bit 2 nothing available,
     * bit 3 more available than real, and the missing KiB round up.
     *
     * @param  array<array-key, string>  $positions  SourcePosition values
     * @param  array<array-key, int|null>  $realSizes
     * @param  array<array-key, int>  $availableBytes
     * @return list<int>
     */
    public static function normalizeHintBatch(array $positions, array $realSizes, array $availableBytes): array
    {
        return (self::usesW54RustFfi() ? self::normalizeHintBatchNative($positions, $realSizes, $availableBytes) : null)
            ?? self::normalizeHintBatchPhp($positions, $realSizes, $availableBytes);
    }

    /**
     * @param  array<array-key, string>  $positions
     * @param  array<array-key, int|null>  $realSizes
     * @param  array<array-key, int>  $availableBytes
     * @return list<int>
     */
    public static function normalizeHintBatchPhp(array $positions, array $realSizes, array $availableBytes): array
    {
        $positions = array_values($positions);
        $realSizes = array_values($realSizes);
        $availableBytes = array_values($availableBytes);
        $count = \count($positions);
        $out = array_fill(0, self::W54_HINT_HEADER, 0);
        $out[0] = $count;
        $lowestAt = -1;
        $lowest = 0;
        $ppmSum = $ppmCount = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $code = self::W54_POSITION_CODES[$positions[$i]] ?? 3;
            $real = min($realSizes[$i] ?? -1, self::W54_VALUE_CAP);
            $available = min(max($availableBytes[$i] ?? 0, 0), self::W54_VALUE_CAP);
            $known = $real > 0;
            $promoted = $code === 1 && $known && $available >= $real;
            $normalized = $code === 0 || $promoted ? 0 : ($code === 2 ? 2 : 1);
            $sized = $known && $normalized !== 2;
            $ppm = $normalized === 0 ? 1_000_000 : ($sized && $available > 0 ? min(intdiv($available * 1_000_000, $real), 1_000_000) : null);
            $missing = $normalized !== 0 && $sized ? max($real - $available, 0) : 0;
            $unsized = $normalized !== 0 && ! $sized;
            $flags = ($promoted ? 1 : 0) | ($unsized ? 2 : 0) | ($available === 0 ? 4 : 0) | ($known && $available > $real ? 8 : 0);
            $out[1 + $code]++;
            $out[5 + $normalized]++;
            if ($promoted) {
                $out[8]++;
            }
            if ($unsized) {
                $out[9]++;
            }
            $out[10] = min($out[10] + $available, self::W54_TOTAL_CAP);
            $out[11] = min($out[11] + $missing, self::W54_TOTAL_CAP);
            if ($ppm !== null) {
                $ppmSum += $ppm;
                $ppmCount++;
                if ($lowestAt < 0 || $ppm < $lowest) {
                    $lowestAt = $i;
                    $lowest = $ppm;
                }
            }
            $row = $normalized | $code << 2 | ($ppm === null ? 0 : $ppm + 1) << 4 | $flags << 24 | intdiv($missing + 1023, 1024) << 28;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($ppmCount > 0) {
            $out[12] = intdiv($ppmSum, $ppmCount);
        }
        $out[13] = $lowestAt;
        $out[14] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust normalization, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $positions
     * @param  array<array-key, int|null>  $realSizes
     * @param  array<array-key, int>  $availableBytes
     * @return list<int>|null
     */
    public static function normalizeHintBatchNative(array $positions, array $realSizes, array $availableBytes): ?array
    {
        $positions = array_values($positions);
        $count = \count($positions);
        if ($count > self::W54_MAX_ITEMS) {
            return null;
        }
        if (\in_array(null, $realSizes, true)) {
            $realSizes = array_map(static fn (mixed $size): mixed => $size ?? -1, $realSizes);
        }
        /** @var array<array-key, int> $realSizes */
        [$joined, $lens] = self::w54Strings($positions);
        $ints = pack('q*', ...self::w54Fit($realSizes, $count, -1), ...self::w54Fit($availableBytes, $count));
        $fields = self::W54_HINT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w54Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w546_hint_normalize_batch($joined, \strlen($joined), $lens, \strlen($lens), $ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 54.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $forced = self::w54RustFfiState();

        return \is_bool($forced) ? $forced : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 54.6 routing and binding, kept in function statics because readonly classes cannot declare
     * static properties. No `$op` returns the forced routing (null for the default); `'set'` replaces it
     * with `$val` and returns the previous value; `'binding'` returns the cached `additional_w54` binding
     * and an output buffer of at least `$val` values, or null.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w54', self::W54_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 54.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w54Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w54RustFfiState('binding', $fields);
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

    /**
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with `$default`.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w54Fit(array $values, int $count, int $default = 0): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, $default);
    }

    /**
     * NUL-joined items, or their concatenation with `pack('V*')` lengths when an item holds a NUL.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w54Strings(array $items): array
    {
        $joined = implode("\0", $items);
        if ($items === [] || substr_count($joined, "\0") === \count($items) - 1) {
            return [$joined, ''];
        }

        return [implode('', $items), pack('V*', ...array_map(strlen(...), $items))];
    }

    // ========================================================================
    // WAVE 64.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.79x, p99 2.91x; uncommon mean 5.92x, p99 2.88x); see RustAdditionalDownloadMetricsArchiveImageMappedNzbSourceHintsW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_MEDIA_SOURCE_HINTS_FFI_CDEF = <<<'C'
        ssize_t nntmux_w643_media_source_hints_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 64.3 batch. */
    public const int W64_MEDIA_SOURCE_HINTS_TRAILER = 16;

    /** Cap for the Wave 64.3 running totals. */
    private const int W64_TOTAL_CAP = 4_611_686_018_427_387_904;

    /** Lower-cased extension => container. */
    private const array W64_CONTAINERS = ['mkv' => 1, 'mp4' => 2, 'm4v' => 2, 'avi' => 3, 'ts' => 4, 'm2ts' => 4, 'wmv' => 5, 'mov' => 6, 'mpg' => 7, 'mpeg' => 7];

    /** Lower-cased name token => codec. */
    private const array W64_CODECS = ['x264' => 1, 'h264' => 1, 'avc' => 1, 'x265' => 2, 'h265' => 2, 'hevc' => 2, 'xvid' => 3, 'divx' => 3, 'av1' => 4, 'vp9' => 5, 'mpeg2' => 6];

    /** Lower-cased SourcePosition value => code (empty 0, anything else 3). */
    private const array W64_POSITIONS = ['complete' => 0, 'head' => 1, 'mid' => 2];

    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores W64_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $override = self::w64RustFfiState();

        return \is_bool($override) ? $override : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the MediaSourceHints Wave 64 Rust path forced on or off, then restores the previous setting.
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
     * Wave 64.3 media source container, codec, sample/main role and byte-slice hint plans. Each item is `name|position|
     * realSize|availableBytes|offset`. The container of the trimmed name's extension is 1 mkv, 2 mp4/m4v, 3 avi, 4 ts/m2ts,
     * 5 wmv, 6 mov, 7 mpg/mpeg (any case), else 0; the codec is that of the first lower-cased `[a-z0-9]+` token naming one
     * (1 x264/h264/avc, 2 x265/h265/hevc, 3 xvid/divx, 4 av1, 5 vp9, 6 mpeg2), else 0. The role is 2 with a `sample` token,
     * 1 with a container, else 0. The trimmed position is 0 empty or `complete`, 1 `head`, 2 `mid` (any case), else 3.
     * Sizes and the offset (up to 12 digits) are 0 empty, 1 trimmed digits, 2 other (invalid values count as 0). The
     * fraction is 1000 for complete sources, else available bytes per mille of the real size (capped at 1000, 0 without
     * one); the slice is 0 without a real size, 1 when offset + available bytes fit it, else 2. The probe is 0 skip (no
     * role, an unknown position or an invalid number), 1 now (complete, a fraction of 50+ or 1 MiB available), else 2 more
     * bytes. Per item `container | codec << 3 | role << 6 | position << 8 | realState << 10 | availableState << 12 |
     * offsetState << 14 | slice << 16 | probe << 18 | fraction << 20 | min(available >> 10, 2^20 - 1) << 30 | min(tokens,
     * 255) << 50`. Then the eight container counts, the three role counts, the three probe counts, the available byte
     * total (capped at 2^62) and the 1-based index of the first strictly highest fraction among main sources to probe now.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64MediaSourceHintsPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64MediaSourceHintsPlanBatchNative($items) : self::w64MediaSourceHintsPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64MediaSourceHintsPlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_MEDIA_SOURCE_HINTS_TRAILER) ?? self::w64MediaSourceHintsPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64MediaSourceHintsPlanBatchPhp(array $items): array
    {
        $out = [];
        $containers = [0, 0, 0, 0, 0, 0, 0, 0];
        $roles = [0, 0, 0];
        $probes = [0, 0, 0];
        $availableSum = $best = $index = 0;
        $bestFraction = -1;
        foreach ($items as $item) {
            $index++;
            [$rawName, $rawPosition, $rawReal, $rawAvailable, $rawOffset] = explode('|', (string) $item, 5) + ['', '', '', '', ''];
            $lower = strtolower(trim($rawName));
            $dot = strrpos($lower, '.');
            $container = $dot === false ? 0 : (self::W64_CONTAINERS[substr($lower, $dot + 1)] ?? 0);
            $tokens = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $codec = 0;
            $sample = false;
            foreach ($tokens as $token) {
                if ($codec === 0) {
                    $codec = self::W64_CODECS[$token] ?? 0;
                }
                $sample = $sample || $token === 'sample';
            }
            $role = $sample ? 2 : ($container !== 0 ? 1 : 0);
            $positionText = strtolower(trim($rawPosition));
            $position = $positionText === '' ? 0 : (self::W64_POSITIONS[$positionText] ?? 3);
            [$realState, $real] = self::w64Count($rawReal, 12);
            [$availableState, $available] = self::w64Count($rawAvailable, 12);
            [$offsetState, $offset] = self::w64Count($rawOffset, 12);
            $fraction = $position === 0 ? 1000 : ($real > 0 ? min(intdiv($available * 1000, $real), 1000) : 0);
            $slice = $real === 0 ? 0 : ($offset + $available <= $real ? 1 : 2);
            if ($role === 0 || $position === 3 || $realState === 2 || $availableState === 2 || $offsetState === 2) {
                $probe = 0;
            } else {
                $probe = $position === 0 || $fraction >= 50 || $available >= 1_048_576 ? 1 : 2;
            }
            $containers[$container]++;
            $roles[$role]++;
            $probes[$probe]++;
            $availableSum = min($availableSum + $available, self::W64_TOTAL_CAP);
            if ($role === 1 && $probe === 1 && $fraction > $bestFraction) {
                $best = $index;
                $bestFraction = $fraction;
            }
            $out[] = $container | $codec << 3 | $role << 6 | $position << 8 | $realState << 10 | $availableState << 12 | $offsetState << 14
                | $slice << 16 | $probe << 18 | $fraction << 20 | min($available >> 10, 1_048_575) << 30 | min(\count($tokens), 255) << 50;
        }
        array_push($out, ...$containers, ...$roles, ...$probes);
        array_push($out, $availableSum, $best);

        return $out;
    }

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w64Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $value = self::w64Digits($text, 1, $max);

        return $value === null ? [2, 0] : [1, $value];
    }

    /** $min to $max ASCII digits as their value, else null. */
    private static function w64Digits(string $text, int $min, int $max): ?int
    {
        $length = \strlen($text);

        return $length >= $min && $length <= $max && strspn($text, '0123456789') === $length ? (int) $text : null;
    }

    /**
     * Wave 64 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w643', self::W64_MEDIA_SOURCE_HINTS_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w64RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 64.3 MediaSourceHints export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w64Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w64RustFfiState('ffi');
            $out = self::w64RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w64RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W64_MEDIA_SOURCE_HINTS_FFI_CDEF.)
            $written = $ffi->nntmux_w643_media_source_hints_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
