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
}
