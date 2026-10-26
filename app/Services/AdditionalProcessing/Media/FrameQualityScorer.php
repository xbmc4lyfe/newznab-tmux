<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use GdImage;
use Throwable;

/**
 * Rates candidate preview frames by brightness and detail.
 *
 * A blank frame (black, white or one flat colour) is never chosen. Among the
 * rest, frames of normal brightness come first, then the most detailed one.
 *
 * The Wave 43 toggle covers full-file scoring, including decoding and scaling.
 * Previously qualified measureSample()/measureRgb() kernels retain their native
 * default and explicit Php/Native helpers; their earlier adoption is unchanged.
 */
final class FrameQualityScorer
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

    private const int SAMPLE_WIDTH = 64;

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_w436_frame_luma_stats_rgb(const char *rgb_bytes_ptr, size_t pixel_count, double *out_mean, double *out_stddev);
        int32_t nntmux_w436_frame_luma_stats_bmp(const char *bmp_ptr, size_t bmp_len, double *out_mean, double *out_stddev);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $statsOut = null;

    private static ?CData $meanOut = null;

    private static ?CData $stddevOut = null;

    /** @var resource|false|null */
    private static $bmpStream = null;

    public function __construct(
        private readonly float $minStddev,
        private readonly float $minLuma,
        private readonly float $maxLuma,
    ) {}

    /**
     * @param  list<string>  $imagePaths
     */
    public function best(array $imagePaths): ?string
    {
        $best = null;
        $bestRank = null;
        foreach ($imagePaths as $path) {
            $measure = $this->measure($path);
            if ($measure === null || $measure['stddev'] < $this->minStddev) {
                continue;
            }

            $rank = [
                $measure['mean'] >= $this->minLuma && $measure['mean'] <= $this->maxLuma,
                $measure['stddev'],
            ];
            if ($bestRank === null || $rank > $bestRank) {
                $best = $path;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $imagePaths
     */
    public function bestNative(array $imagePaths, bool &$supported = false): ?string
    {
        $supported = self::ffi() !== null;
        $best = null;
        $bestRank = null;
        foreach ($imagePaths as $path) {
            $frameSupported = false;
            $measure = $this->measureNative($path, $frameSupported);
            $supported = $supported && $frameSupported;
            if ($measure === null || $measure['stddev'] < $this->minStddev) {
                continue;
            }

            $rank = [
                $measure['mean'] >= $this->minLuma && $measure['mean'] <= $this->maxLuma,
                $measure['stddev'],
            ];
            if ($bestRank === null || $rank > $bestRank) {
                $best = $path;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * Mean luma and its standard deviation (0–255), measured on a downscaled copy.
     *
     * @return array{mean: float, stddev: float}|null
     */
    public function measure(string $imagePath): ?array
    {
        return self::usesRustFfi() ? $this->measureNative($imagePath) : $this->measurePhp($imagePath);
    }

    /**
     * @return array{mean: float, stddev: float}|null
     */
    public function measurePhp(string $imagePath): ?array
    {
        $sample = $this->loadSample($imagePath);
        if ($sample === null) {
            return null;
        }

        return self::measureSamplePhp($sample);
    }

    /**
     * @return array{mean: float, stddev: float}|null
     */
    public function measureNative(string $imagePath, bool &$supported = false): ?array
    {
        $supported = false;
        $sample = $this->loadSample($imagePath);
        if ($sample === null) {
            $supported = self::ffi() !== null;

            return null;
        }

        return self::measureSampleNative($sample, $supported);
    }

    /**
     * Score an already-downscaled GdImage sample via the Rust BMP luma kernel (with PHP fallback).
     *
     * @return array{mean: float, stddev: float}
     */
    public static function measureSample(GdImage $sample, bool &$supported = false): array
    {
        return self::measureSampleNative($sample, $supported);
    }

    /**
     * @return array{mean: float, stddev: float}
     */
    public static function measureSampleNative(GdImage $sample, bool &$supported = false): array
    {
        $supported = false;
        $ffi = self::ffi();
        $stream = self::bmpStream();
        if ($ffi === null || $stream === null || self::$meanOut === null || self::$stddevOut === null || self::$statsOut === null || ! imageistruecolor($sample)) {
            return self::measureSamplePhp($sample);
        }

        rewind($stream);
        ftruncate($stream, 0);
        if (! @imagebmp($sample, $stream, false)) {
            return self::measureSamplePhp($sample);
        }
        rewind($stream);
        $bmp = stream_get_contents($stream);
        if (! is_string($bmp) || strlen($bmp) < 54) {
            return self::measureSamplePhp($sample);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w436_frame_luma_stats_bmp($bmp, strlen($bmp), self::$meanOut, self::$stddevOut);
        } catch (Throwable) {
            return self::measureSamplePhp($sample);
        }
        if (! is_int($status) || $status !== 1) {
            return self::measureSamplePhp($sample);
        }
        $supported = true;

        return [
            'mean' => (float) self::$statsOut[0],
            'stddev' => (float) self::$statsOut[1],
        ];
    }

    /**
     * @return array{mean: float, stddev: float}
     */
    public static function measureSamplePhp(GdImage $sample): array
    {
        $width = imagesx($sample);
        $height = imagesy($sample);
        $sum = $sumSquares = 0.0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $luma = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                $sum += $luma;
                $sumSquares += $luma * $luma;
            }
        }

        $pixels = max(1, $width * $height);
        $mean = $sum / $pixels;

        return ['mean' => $mean, 'stddev' => sqrt(max(0.0, $sumSquares / $pixels - $mean * $mean))];
    }

    /**
     * Score contiguous RGB triplets (`3 * pixel_count` bytes) via the Rust luma kernel (with PHP fallback).
     *
     * @return array{mean: float, stddev: float}|null
     */
    public static function measureRgb(string $rgbBytes, bool &$supported = false): ?array
    {
        return self::measureRgbNative($rgbBytes, $supported);
    }

    /**
     * @return array{mean: float, stddev: float}|null
     */
    public static function measureRgbNative(string $rgbBytes, bool &$supported = false): ?array
    {
        $supported = false;
        $len = strlen($rgbBytes);
        if ($len === 0 || $len % 3 !== 0) {
            $supported = self::ffi() !== null;

            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null || self::$meanOut === null || self::$stddevOut === null || self::$statsOut === null) {
            return self::measureRgbPhp($rgbBytes);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w436_frame_luma_stats_rgb($rgbBytes, intdiv($len, 3), self::$meanOut, self::$stddevOut);
        } catch (Throwable) {
            return self::measureRgbPhp($rgbBytes);
        }
        if (! is_int($status) || $status !== 1) {
            return self::measureRgbPhp($rgbBytes);
        }
        $supported = true;

        return [
            'mean' => (float) self::$statsOut[0],
            'stddev' => (float) self::$statsOut[1],
        ];
    }

    /**
     * @return array{mean: float, stddev: float}|null
     */
    public static function measureRgbPhp(string $rgbBytes): ?array
    {
        $len = strlen($rgbBytes);
        if ($len === 0 || $len % 3 !== 0) {
            return null;
        }

        $sum = $sumSquares = 0.0;
        for ($i = 0; $i < $len; $i += 3) {
            $luma = 0.299 * ord($rgbBytes[$i]) + 0.587 * ord($rgbBytes[$i + 1]) + 0.114 * ord($rgbBytes[$i + 2]);
            $sum += $luma;
            $sumSquares += $luma * $luma;
        }

        $pixels = max(1, intdiv($len, 3));
        $mean = $sum / $pixels;

        return ['mean' => $mean, 'stddev' => sqrt(max(0.0, $sumSquares / $pixels - $mean * $mean))];
    }

    private function loadSample(string $imagePath): ?GdImage
    {
        $contents = @file_get_contents($imagePath);
        $image = $contents === false || $contents === '' ? false : @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        $sample = imagesx($image) > self::SAMPLE_WIDTH ? imagescale($image, self::SAMPLE_WIDTH) : $image;

        return $sample === false ? null : $sample;
    }

    /**
     * @return resource|null
     */
    private static function bmpStream()
    {
        if (self::$bmpStream === null) {
            self::$bmpStream = @fopen('php://memory', 'r+b');
        }

        return is_resource(self::$bmpStream) ? self::$bmpStream : null;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            $ffi = RustPipelineLibrary::resolveDomain('frame_quality_scorer', self::FFI_CDEF);
            if ($ffi !== null) {
                try {
                    $stats = $ffi->new('double[2]');
                    self::$meanOut = FFI::addr($stats[0]);
                    self::$stddevOut = FFI::addr($stats[1]);
                    self::$statsOut = $stats;
                    self::$ffi = $ffi;
                } catch (Throwable) {
                    self::$ffi = null;
                }
            }
        }

        return self::$ffi;
    }

    // Rust is the default only while the paired W51.6 benchmark clears 1.5x on all four metrics.
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        int32_t nntmux_w516_frame_luma_batch(const char *samples, size_t samples_len, const char *sample_lens, size_t sample_lens_len, size_t count, double min_stddev, double min_luma, double max_luma, int64_t *out, size_t out_count);
        C;

    public const int W51_FRAME_HEADER = 9;

    private const int W51_MAX_ITEMS = 1_048_576;

    private static ?bool $w51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51Capacity = 0;

    /**
     * Score a batch of RGB samples as `measureRgbPhp()` does and choose the frame `best()` would.
     *
     * Layout: `[0]` count, `[1]` valid samples, `[2]` blank ones (stddev below `$minStddev`),
     * `[3]` non-blank ones of normal brightness, `[4]` best index (-1 when none), `[5]` and
     * `[6]` its floor(mean * 1000) and floor(stddev * 1000), `[7]` invalid samples (empty or not
     * whole RGB triplets), `[8]` FNV-1a digest; then per sample `valid | blank << 1 |
     * normal << 2 | mean_milli << 4 | stddev_milli << 24` (0 when invalid).
     *
     * @param  array<array-key, string>  $samples
     * @return list<int>
     */
    public static function scoreFrameBatch(array $samples, float $minStddev, float $minLuma, float $maxLuma): array
    {
        return (self::usesW51RustFfi() ? self::scoreFrameBatchNative($samples, $minStddev, $minLuma, $maxLuma) : null)
            ?? self::scoreFrameBatchPhp($samples, $minStddev, $minLuma, $maxLuma);
    }

    /**
     * @param  array<array-key, string>  $samples
     * @return list<int>
     */
    public static function scoreFrameBatchPhp(array $samples, float $minStddev, float $minLuma, float $maxLuma): array
    {
        $samples = array_values($samples);
        $out = [\count($samples), 0, 0, 0, -1, 0, 0, 0, 0];
        $bestRank = null;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($samples as $index => $sample) {
            $measure = self::measureRgbPhp($sample);
            $row = 0;
            if ($measure === null) {
                $out[7]++;
            } else {
                $blank = $measure['stddev'] < $minStddev;
                $normal = $measure['mean'] >= $minLuma && $measure['mean'] <= $maxLuma;
                $meanMilli = (int) floor($measure['mean'] * 1000.0);
                $stddevMilli = (int) floor($measure['stddev'] * 1000.0);
                $out[1]++;
                if ($blank) {
                    $out[2]++;
                } else {
                    $out[3] += (int) $normal;
                    $rank = [$normal, $measure['stddev']];
                    if ($bestRank === null || $rank > $bestRank) {
                        $bestRank = $rank;
                        $out[4] = $index;
                        $out[5] = $meanMilli;
                        $out[6] = $stddevMilli;
                    }
                }
                $row = 1 | (int) $blank << 1 | (int) $normal << 2 | $meanMilli << 4 | $stddevMilli << 24;
            }
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[8] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust scoring, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $samples
     * @return list<int>|null
     */
    public static function scoreFrameBatchNative(array $samples, float $minStddev, float $minLuma, float $maxLuma): ?array
    {
        $samples = array_values($samples);
        $count = \count($samples);
        $fields = self::W51_FRAME_HEADER + $count;
        $binding = $count <= self::W51_MAX_ITEMS ? self::w51Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $joined = implode('', $samples);
        if (\strlen($joined) > 0xFFFFFFFF) {
            return null;
        }
        $lens = $count === 0 ? '' : pack('V*', ...array_map(strlen(...), $samples));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w516_frame_luma_batch($joined, \strlen($joined), $lens, \strlen($lens), $count, $minStddev, $minLuma, $maxLuma, $out, $fields);

            return self::w51Decode(\is_int($status) ? $status : -1, $out, $fields);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run `$callback` with the Wave 51.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w51RustFfi;
        self::$w51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w51RustFfi = $previous;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        return self::$w51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * The cached Wave 51.6 binding and an output buffer of at least `$fields` values, or null.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w51Binding(int $fields): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('additional_w51', self::W51_FFI_CDEF) ?? false;
            if (self::$w51Ffi === false) {
                return null;
            }
            $out = self::$w51Out;
            if ($out === null || self::$w51Capacity < $fields) {
                self::$w51Capacity = max(256, $fields);
                $out = self::$w51Out = self::$w51Ffi->new('int64_t['.self::$w51Capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return [self::$w51Ffi, $out];
    }

    /**
     * Decode `$fields` output values after a successful Wave 51.6 call.
     *
     * @return list<int>|null
     */
    private static function w51Decode(int $status, CData $out, int $fields): ?array
    {
        if ($status !== 1) {
            return null;
        }
        $values = unpack('P*', FFI::string($out, $fields * 8));

        return $values === false ? null : array_values($values);
    }

    /** C signature of the Wave 64.5 {@see self::w64FrameQualityPlanBatch()} helper. */
    private const string W64_FRAME_QUALITY_PLAN_FFI_CDEF = 'int64_t nntmux_w645_frame_quality_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 64.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 3.40/3.47/6.10/6.02x); see tests/Unit/RustAdditionalFfmpegFrameScorerRankerInspectorFetcherW64ParityTest.php.
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
                    $ffi = RustPipelineLibrary::resolveDomain('w64_5', self::W64_FRAME_QUALITY_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
     * Runs $callback with w64FrameQualityPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
            $written = $ffi->nntmux_w645_frame_quality_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch frame quality plan over `width\theight\tluma_hex`, the hex-encoded 8-bit luma plane in row order. Ten
     * words per record: `[status, mean_milli, stddev_milli, contrast, levels, border_rows, border_columns, class,
     * rank, flags]`. Status: 0 measured, 1 width or height below 1 or over 2^20 pixels, 2 luma not even-length
     * ASCII hex, 3 luma length other than width * height; unmeasured records are otherwise 0. The mean and
     * population standard deviation are floored thousandths (the deviation from the exact integer square root);
     * contrast is max - min luma, levels the distinct luma values, and border rows/columns the fully dark (luma
     * <= 16) rows from the top and bottom and columns from the left and right (all of them for a dark frame).
     * Class, checked in order: 0 unmeasured, 1 blank (deviation below 10 or contrast below 24), 2 too dark (mean
     * below 40), 3 too bright (mean above 220), 4 letterboxed (a quarter or more border rows or columns), else 5;
     * rank is `class << 32 | stddev_milli`, higher being better. Numbers are trimmed 1-15 ASCII digits (else 0).
     * Flags: 1 invalid number, 2 not exactly three fields, 4 uppercase hex, 8 over the pixel cap.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64FrameQualityPlanBatch(array $records): array
    {
        return self::usesW64RustFfi() ? self::w64FrameQualityPlanBatchNative($records) : self::w64FrameQualityPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64FrameQualityPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w64Words($count, implode("\0", $records), 10 * $count) ?? self::w64FrameQualityPlanBatchPhp($records);
    }

    private const int W64_PIXEL_CAP = 1 << 20;

    /** Luma bytes 0-16, which count as black border. */
    private const string W64_DARK = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64FrameQualityPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 3 ? 0 : 2;
            $width = self::w64Number($fields[0], 0, $flags);
            $height = self::w64Number($fields[1] ?? '', 0, $flags);
            $hex = $fields[2] ?? '';
            $validHex = $hex !== '' && \strlen($hex) % 2 === 0 && ctype_xdigit($hex);
            if ($validHex && strpbrk($hex, 'ABCDEF') !== false) {
                $flags |= 4;
            }
            if ($width < 1 || $height < 1) {
                $status = 1;
            } elseif ($width > self::W64_PIXEL_CAP || $height > self::W64_PIXEL_CAP || $width * $height > self::W64_PIXEL_CAP) {
                $flags |= 8;
                $status = 1;
            } elseif (! $validHex) {
                $status = 2;
            } elseif (\strlen($hex) !== 2 * $width * $height) {
                $status = 3;
            } else {
                $status = 0;
            }
            if ($status !== 0) {
                array_push($out, $status, 0, 0, 0, 0, 0, 0, 0, 0, $flags);

                continue;
            }
            $pixels = (string) hex2bin($hex);
            $count = $width * $height;
            $sum = $squares = $high = 0;
            $low = 255;
            $histogram = count_chars($pixels, 1);
            foreach ($histogram as $luma => $seen) {
                $sum += $luma * $seen;
                $squares += $luma * $luma * $seen;
                $low = min($low, $luma);
                $high = max($high, $luma);
            }
            $variance = $count * $squares - $sum * $sum;
            $root = (int) sqrt((float) $variance);
            while ($root * $root > $variance) {
                $root--;
            }
            while (($root + 1) * ($root + 1) <= $variance) {
                $root++;
            }
            $mean = intdiv($sum * 1000, $count);
            $stddev = intdiv($root * 1000, $count);
            $contrast = $high - $low;
            $top = 0;
            while ($top < $height && strspn($pixels, self::W64_DARK, $top * $width, $width) === $width) {
                $top++;
            }
            if ($top === $height) {
                $rows = $height;
                $columns = $width;
            } else {
                $bottom = 0;
                while (strspn($pixels, self::W64_DARK, ($height - 1 - $bottom) * $width, $width) === $width) {
                    $bottom++;
                }
                $darkColumn = static function (int $x) use ($pixels, $width, $height): bool {
                    for ($at = $x, $end = $width * $height; $at < $end; $at += $width) {
                        if (\ord($pixels[$at]) > 16) {
                            return false;
                        }
                    }

                    return true;
                };
                $left = $right = 0;
                while ($darkColumn($left)) {
                    $left++;
                }
                while ($darkColumn($width - 1 - $right)) {
                    $right++;
                }
                $rows = $top + $bottom;
                $columns = $left + $right;
            }
            $class = match (true) {
                $stddev < 10_000 || $contrast < 24 => 1,
                $mean < 40_000 => 2,
                $mean > 220_000 => 3,
                ($rows > 0 && $rows * 4 >= $height) || ($columns > 0 && $columns * 4 >= $width) => 4,
                default => 5,
            };
            array_push($out, 0, $mean, $stddev, $contrast, \count($histogram), $rows, $columns, $class, $class << 32 | $stddev, $flags);
        }

        return $out;
    }
}
