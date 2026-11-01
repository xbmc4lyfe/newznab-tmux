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

    // Rust is the default only while the paired W52.6 benchmark clears 1.5x on all four metrics.
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        int32_t nntmux_w526_thumbnail_window_batch(const char *ints, size_t ints_len, const char *durations, size_t durations_len, size_t count, int64_t attempts, int64_t *out, size_t out_count);
        C;

    public const int W52_WINDOW_HEADER = 6;

    /** `windowBatch()` position codes; any other code means no hints. */
    public const int W52_POSITION_HEAD = 1;

    public const int W52_POSITION_MID = 2;

    public const int W52_POSITION_COMPLETE = 3;

    private const int W52_VALUE_CAP = (1 << 40) - 1;

    private const float W52_MAX_DURATION = 1e9;

    private const int W52_MAX_ATTEMPTS = 1_000_000;

    private const int W52_MAX_ITEMS = 1_048_576;

    private static ?bool $w52RustFfi = null;

    private static FFI|false|null $w52Ffi = null;

    private static ?CData $w52Out = null;

    private static int $w52Capacity = 0;

    /**
     * `windowPhp()` over already probed sources, in milliseconds.
     *
     * Per source: a position code (`W52_POSITION_*`), the probed duration (outside 0..1e9 or NaN
     * when unknown), the probed bit rate, the hinted available bytes, the hinted real size and
     * the file size; byte counts and bit rates are clamped to 0..2^40-1, where 0 is an unknown
     * bit rate or real size. Missing values are unknown or 0. `$attempts` is clamped to
     * 1..1,000,000. Layout: `[0]` count, `[1]` mid sources, `[2]` windows starting after 0 (so
     * retried from 0), `[3]` other windows starting and spaced at 0, `[4]` latest start, `[5]`
     * FNV-1a digest; then per source `start << 2 | path` (0 mid, 1 duration and available
     * fraction, 2 bit rate, 3 duration alone) and the spacing, each `(int) round($seconds * 1000)`
     * of the rounded window.
     *
     * @param  array<array-key, int>  $positions
     * @param  array<array-key, float>  $durations
     * @param  array<array-key, int>  $bitRates
     * @param  array<array-key, int>  $hintBytes
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $fileSizes
     * @return list<int>
     */
    public static function windowBatch(array $positions, array $durations, array $bitRates, array $hintBytes, array $realSizes, array $fileSizes, int $attempts): array
    {
        return (self::usesW52RustFfi() ? self::windowBatchNative($positions, $durations, $bitRates, $hintBytes, $realSizes, $fileSizes, $attempts) : null)
            ?? self::windowBatchPhp($positions, $durations, $bitRates, $hintBytes, $realSizes, $fileSizes, $attempts);
    }

    /**
     * @param  array<array-key, int>  $positions
     * @param  array<array-key, float>  $durations
     * @param  array<array-key, int>  $bitRates
     * @param  array<array-key, int>  $hintBytes
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $fileSizes
     * @return list<int>
     */
    public static function windowBatchPhp(array $positions, array $durations, array $bitRates, array $hintBytes, array $realSizes, array $fileSizes, int $attempts): array
    {
        $positions = array_values($positions);
        $durations = array_values($durations);
        $bitRates = array_values($bitRates);
        $hintBytes = array_values($hintBytes);
        $realSizes = array_values($realSizes);
        $fileSizes = array_values($fileSizes);
        $attempts = min(max($attempts, 1), self::W52_MAX_ATTEMPTS);
        $out = [\count($positions), 0, 0, 0, 0, 0];
        $rows = [];
        foreach ($positions as $index => $position) {
            if ($position === self::W52_POSITION_MID) {
                $out[1]++;
                $rows[] = 0;
                $rows[] = 0;

                continue;
            }
            $bitRate = min(max($bitRates[$index] ?? 0, 0), self::W52_VALUE_CAP);
            $hinted = min(max($hintBytes[$index] ?? 0, 0), self::W52_VALUE_CAP);
            $realSize = min(max($realSizes[$index] ?? 0, 0), self::W52_VALUE_CAP);
            $fileSize = min(max($fileSizes[$index] ?? 0, 0), self::W52_VALUE_CAP);
            $duration = (float) ($durations[$index] ?? -1.0);
            $known = $duration >= 0.0 && $duration <= self::W52_MAX_DURATION;
            $fraction = match (true) {
                $position === self::W52_POSITION_COMPLETE => 1.0,
                $position === self::W52_POSITION_HEAD && $realSize > 0 && $hinted > 0 => min(1.0, $hinted / $realSize),
                default => null,
            };
            $available = $hinted > 0 ? $hinted : $fileSize;
            if ($known && $fraction !== null) {
                $playable = $duration * $fraction * ($fraction < 1.0 ? self::PLAYABLE_MARGIN : 1.0);
                $path = 1;
            } elseif ($position >= self::W52_POSITION_HEAD && $position <= self::W52_POSITION_COMPLETE && $bitRate > 0 && $available > 0) {
                $playable = $available * 8 / $bitRate * self::PLAYABLE_MARGIN;
                $path = 2;
            } else {
                $playable = $known ? $duration : 0.0;
                $path = 3;
            }
            $from = round($playable * self::START_FRACTION, 3);
            $start = (int) round($from * 1000.0);
            $spacing = (int) round(round(($playable - $from) / $attempts, 3) * 1000.0);
            if ($start > 0) {
                $out[2]++;
            } elseif ($spacing === 0) {
                $out[3]++;
            }
            $out[4] = max($out[4], $start);
            $rows[] = $start << 2 | $path;
            $rows[] = $spacing;
        }
        $digest = 2_166_136_261;
        foreach ($rows as $row) {
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $out[5] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust windows, or null when PHP must compute them (unavailable library or invalid input).
     *
     * @param  array<array-key, int>  $positions
     * @param  array<array-key, float>  $durations
     * @param  array<array-key, int>  $bitRates
     * @param  array<array-key, int>  $hintBytes
     * @param  array<array-key, int>  $realSizes
     * @param  array<array-key, int>  $fileSizes
     * @return list<int>|null
     */
    public static function windowBatchNative(array $positions, array $durations, array $bitRates, array $hintBytes, array $realSizes, array $fileSizes, int $attempts): ?array
    {
        $count = \count($positions);
        $fields = self::W52_WINDOW_HEADER + 2 * $count;
        $binding = $count <= self::W52_MAX_ITEMS ? self::w52Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        $ints = pack(
            'q*',
            ...array_values($positions),
            ...self::w52Column($bitRates, $count, 0),
            ...self::w52Column($hintBytes, $count, 0),
            ...self::w52Column($realSizes, $count, 0),
            ...self::w52Column($fileSizes, $count, 0),
        );
        $packed = pack('e*', ...self::w52Column($durations, $count, -1.0));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w526_thumbnail_window_batch($ints, \strlen($ints), $packed, \strlen($packed), $count, $attempts, $out, $fields);
            if ($status !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $fields * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run `$callback` with the Wave 52.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w52RustFfi;
        self::$w52RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w52RustFfi = $previous;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        return self::$w52RustFfi ?? self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * `$values` as a list of exactly `$count` values, padded with `$missing`.
     *
     * @template T of int|float
     *
     * @param  array<array-key, T>  $values
     * @param  T  $missing
     * @return list<T>
     */
    private static function w52Column(array $values, int $count, int|float $missing): array
    {
        return \count($values) === $count ? array_values($values) : array_pad(\array_slice(array_values($values), 0, $count), $count, $missing);
    }

    /**
     * The cached Wave 52.6 binding and an output buffer of at least `$fields` values, or null.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w52Binding(int $fields): ?array
    {
        try {
            self::$w52Ffi ??= RustPipelineLibrary::resolveDomain('additional_w52', self::W52_FFI_CDEF) ?? false;
            if (self::$w52Ffi === false) {
                return null;
            }
            if (self::$w52Out === null || self::$w52Capacity < $fields) {
                self::$w52Capacity = max(256, $fields);
                self::$w52Out = self::$w52Ffi->new('int64_t['.self::$w52Capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return [self::$w52Ffi, self::$w52Out];
    }
}
