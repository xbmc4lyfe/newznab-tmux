<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Chooses the preview frame of a video, which is often only the start of the file.
 *
 * Openings tend to be black or show logos, so frames are taken a quarter of the
 * way into the part that was downloaded, estimated from the container's
 * duration and the file's real size or the stream's bit rate.
 *
 * Wave 45 helpers remain opt-in: caller-level mean and p99 benchmarks do not
 * meet the 1.5x gate on both workloads. See the Wave 45 parity test.
 */
class ThumbnailFrameSelector
{
    public const bool W45_RUST_FFI_DEFAULT = false;

    /** Share of the downloaded playing time skipped before taking frames. */
    private const float START_FRACTION = 0.25;

    /** Margin for bytes that hold headers or decode to less than the average. */
    private const float PLAYABLE_MARGIN = 0.85;

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_w45_thumbnail_looks_like_text(const char *head_ptr, size_t head_len);
        int32_t nntmux_w45_thumbnail_window(uint32_t position_is_mid, double duration, uint32_t has_duration, int64_t bit_rate, uint32_t has_bit_rate, int64_t available_bytes, int64_t total_bytes, uint32_t has_hints, int32_t attempts, double *out_from, double *out_spacing);
        C;

    private static bool $useRustFfi = self::W45_RUST_FFI_DEFAULT;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $windowOut = null;

    private static ?CData $fromOut = null;

    private static ?CData $spacingOut = null;

    public static function useW45RustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? self::W45_RUST_FFI_DEFAULT;
    }

    public static function usesW45RustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly VideoFrameGrabber $grabber,
        private readonly FrameQualityScorer $scorer,
    ) {}

    /**
     * Return the best frame's image path, inside $workDirectory, or null if every frame was blank.
     */
    public function select(string $videoPath, string $workDirectory, ?MediaSourceHints $hints = null): ?string
    {
        $head = (string) @file_get_contents($videoPath, false, null, 0, 4096);
        $container = VideoContainer::detect($head);
        if ($container === null && self::looksLikeText($head)) {
            // Never let ffmpeg read a playlist or concat script out of downloaded data.
            return null;
        }

        [$from, $spacing] = $this->window($videoPath, $container, $hints);
        $windows = $from > 0.0 ? [[$from, $spacing], [0.0, 0.0]] : [[$from, $spacing]];
        foreach ($windows as [$start, $gap]) {
            $best = $this->scorer->best(
                $this->grabber->grab($videoPath, $container, $start, $gap, $this->config->thumbnailFrameAttempts, $workDirectory),
            );
            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    public function selectNative(string $videoPath, string $workDirectory, ?MediaSourceHints $hints = null, bool &$supported = false): ?string
    {
        $supported = false;
        $head = (string) @file_get_contents($videoPath, false, null, 0, 4096);
        $container = VideoContainer::detect($head);
        if ($container === null) {
            $textSupported = false;
            if (self::looksLikeTextNative($head, $textSupported)) {
                $supported = $textSupported;

                return null;
            }
        }

        $windowSupported = false;
        [$from, $spacing] = $this->windowNative($videoPath, $container, $hints, $windowSupported);
        $windows = $from > 0.0 ? [[$from, $spacing], [0.0, 0.0]] : [[$from, $spacing]];
        $supported = $windowSupported;
        foreach ($windows as [$start, $gap]) {
            $scorerSupported = false;
            $best = $this->scorer->bestNative(
                $this->grabber->grab($videoPath, $container, $start, $gap, $this->config->thumbnailFrameAttempts, $workDirectory),
                $scorerSupported,
            );
            $supported = $supported && $scorerSupported;
            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /**
     * When to take the first frame, and how far apart the candidates should be so they
     * cover the rest of the downloaded playing time.
     *
     * @return array{0: float, 1: float} seconds
     */
    public function window(string $videoPath, ?VideoContainer $container, ?MediaSourceHints $hints): array
    {
        return self::$useRustFfi
            ? $this->windowNative($videoPath, $container, $hints)
            : $this->windowPhp($videoPath, $container, $hints);
    }

    /**
     * @return array{0: float, 1: float} seconds
     */
    public function windowPhp(string $videoPath, ?VideoContainer $container, ?MediaSourceHints $hints): array
    {
        if ($hints?->position === SourcePosition::Mid) {
            return [0.0, 0.0];
        }

        $playable = $this->playableSeconds($videoPath, $container, $hints);
        $from = round($playable * self::START_FRACTION, 3);

        return [$from, round(($playable - $from) / $this->config->thumbnailFrameAttempts, 3)];
    }

    /**
     * @return array{0: float, 1: float} seconds
     */
    public function windowNative(string $videoPath, ?VideoContainer $container, ?MediaSourceHints $hints, bool &$supported = false): array
    {
        $supported = false;
        $ffi = self::ffi();
        if ($ffi === null || self::$fromOut === null || self::$spacingOut === null || self::$windowOut === null || $this->config->thumbnailFrameAttempts <= 0 || $this->config->thumbnailFrameAttempts > 2147483647) {
            return $this->windowPhp($videoPath, $container, $hints);
        }

        if ($hints?->position === SourcePosition::Mid) {
            try {
                // @phpstan-ignore method.notFound
                $status = $ffi->nntmux_w45_thumbnail_window(1, 0.0, 0, 0, 0, 0, 0, 1, $this->config->thumbnailFrameAttempts, self::$fromOut, self::$spacingOut);
            } catch (Throwable) {
                return $this->windowPhp($videoPath, $container, $hints);
            }
            if (! is_int($status) || $status !== 1) {
                return $this->windowPhp($videoPath, $container, $hints);
            }
            $supported = true;

            return [(float) self::$windowOut[0], (float) self::$windowOut[1]];
        }

        if ($hints !== null && $hints->position !== SourcePosition::Complete && $hints->availableBytes > 0 && $hints->realSize !== null && $hints->realSize <= 0) {
            return $this->windowPhp($videoPath, $container, $hints);
        }

        ['duration' => $duration, 'bitRate' => $bitRate] = $this->grabber->probe($videoPath, $container);
        $available = $hints !== null && $hints->availableBytes > 0 ? $hints->availableBytes : (int) @filesize($videoPath);
        $hasHints = match (true) {
            $hints === null => 0,
            $hints->position === SourcePosition::Complete => 2,
            default => 1,
        };
        $totalBytes = $hints !== null && $hints->availableBytes > 0 && $hints->realSize !== null && $hints->realSize > 0
            ? $hints->realSize
            : 0;

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w45_thumbnail_window(
                0,
                $duration ?? 0.0,
                $duration !== null ? 1 : 0,
                $bitRate ?? 0,
                $bitRate !== null ? 1 : 0,
                $available,
                $totalBytes,
                $hasHints,
                $this->config->thumbnailFrameAttempts,
                self::$fromOut,
                self::$spacingOut,
            );
        } catch (Throwable) {
            return $this->windowPhp($videoPath, $container, $hints);
        }
        if (! is_int($status) || $status !== 1) {
            return $this->windowPhp($videoPath, $container, $hints);
        }
        $supported = true;

        return [(float) self::$windowOut[0], (float) self::$windowOut[1]];
    }

    public static function looksLikeText(string $head): bool
    {
        return self::$useRustFfi ? self::looksLikeTextNative($head) : self::looksLikeTextPhp($head);
    }

    public static function looksLikeTextNative(string $head, bool &$supported = false): bool
    {
        $supported = false;
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::looksLikeTextPhp($head);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w45_thumbnail_looks_like_text($head, strlen($head));
        } catch (Throwable) {
            return self::looksLikeTextPhp($head);
        }
        if (! is_int($status) || $status < 0) {
            return self::looksLikeTextPhp($head);
        }
        $supported = true;

        return $status === 1;
    }

    public static function looksLikeTextPhp(string $head): bool
    {
        $start = ltrim($head, "\xEF\xBB\xBF \t\r\n");

        return strncasecmp($start, '#EXTM3U', 7) === 0 || strncasecmp($start, 'ffconcat', 8) === 0;
    }

    private function playableSeconds(string $videoPath, ?VideoContainer $container, ?MediaSourceHints $hints): float
    {
        ['duration' => $duration, 'bitRate' => $bitRate] = $this->grabber->probe($videoPath, $container);
        $available = $hints !== null && $hints->availableBytes > 0 ? $hints->availableBytes : (int) @filesize($videoPath);
        $fraction = $hints?->availableFraction();

        return match (true) {
            $duration !== null && $fraction !== null => $duration * $fraction * ($fraction < 1.0 ? self::PLAYABLE_MARGIN : 1.0),
            $hints !== null && $bitRate !== null && $available > 0 => $available * 8 / $bitRate * self::PLAYABLE_MARGIN,
            // Unknown size: assume the whole file is here; the retry from 0 covers a partial one.
            default => $duration ?? 0.0,
        };
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
            if ($ffi !== null) {
                try {
                    $window = $ffi->new('double[2]');
                    self::$fromOut = FFI::addr($window[0]);
                    self::$spacingOut = FFI::addr($window[1]);
                    self::$windowOut = $window;
                    self::$ffi = $ffi;
                } catch (Throwable) {
                    self::$ffi = null;
                }
            }
        }

        return self::$ffi;
    }
}
