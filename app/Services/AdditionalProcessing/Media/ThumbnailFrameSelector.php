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

    // ========================================================================
    // WAVE 65.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.48x, p99 6.96x; uncommon mean 8.02x, p99 6.15x); see RustAdditionalMediaStageToolPar2MapperThumbExtractionW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_THUMBNAIL_SELECTOR_FFI_CDEF = <<<'C'
        ssize_t nntmux_w651_thumbnail_selector_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w65ThumbnailSelectorPlanBatch(). */
    public const int W65_THUMBNAIL_SELECTOR_TRAILER = 26;

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /** Forces the ThumbnailFrameSelector Wave 65 Rust path on until reset. */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /** Forces the ThumbnailFrameSelector Wave 65 PHP path until reset. */
    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    /**
     * Runs $callback with the ThumbnailFrameSelector Wave 65 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.1 thumbnail selection plans, one per candidate frame `timestampMs|durationMs|brightness|contrast|sharpness|width|height`
     * (trimmed fields; numbers are 1-15 digits, empty 0). Gate: 0 empty row, 1 malformed (bad number, brightness or contrast
     * over 255, sharpness over 1000), 2 past the duration, 3 under 64 pixels wide or high, 4 dark (brightness under 16), 5
     * bright (over 240), 6 flat (contrast under 12), 7 within one second of an accepted frame, 8 accepted. Score (0 for gates
     * 0-1): `sharpness + 2 * contrast + 2 * (128 - |brightness - 128|)` plus 200 inside 20-80% of the duration (100 inside
     * 10-90%) plus `min(width * height / 10000, 300)` with dimensions capped at 65535. The winner is the first accepted frame
     * with the highest score. Flags: 1 first 5%, 2 last 5%, 4 unknown duration, 8 portrait, 16 non-ASCII, 32 control byte, 64
     * sharpness 500 or more, 128 extra fields. Per item `gate | flags << 4 | score << 12 | positionPercent << 24 | brightness
     * << 31 | contrast << 39 | bitLength(timestamp) << 47 | bitLength(width) << 53`; then 9 gate and 8 flag counts, 4 accepted
     * score buckets (600 wide), the winner's 1-based index, score and timestamp, the accepted score sum and the latest timestamp.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ThumbnailSelectorPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65ThumbnailSelectorPlanBatchNative($items) : self::w65ThumbnailSelectorPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ThumbnailSelectorPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_THUMBNAIL_SELECTOR_TRAILER) ?? self::w65ThumbnailSelectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ThumbnailSelectorPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W65_THUMBNAIL_SELECTOR_TRAILER, 0);
        $accepted = [];
        $index = 0;
        $bestIndex = 0;
        $bestScore = -1;
        $bestTimestamp = 0;
        foreach ($items as $item) {
            $item = (string) $item;
            $index++;
            [$rawTimestamp, $rawDuration, $rawBrightness, $rawContrast, $rawSharpness, $rawWidth, $rawHeight] = explode('|', $item, 7)
                + ['', '', '', '', '', '', ''];
            $timestamp = self::w65Uint($rawTimestamp, 0);
            $duration = self::w65Uint($rawDuration, 0);
            $brightness = self::w65Uint($rawBrightness, 0);
            $contrast = self::w65Uint($rawContrast, 0);
            $sharpness = self::w65Uint($rawSharpness, 0);
            $width = self::w65Uint($rawWidth, 0);
            $height = self::w65Uint($rawHeight, 0);
            $malformed = $timestamp < 0 || $duration < 0 || $brightness < 0 || $brightness > 255 || $contrast < 0 || $contrast > 255
                || $sharpness < 0 || $sharpness > 1000 || $width < 0 || $height < 0;
            $timestamp = max(0, $timestamp);
            $duration = max(0, $duration);
            $brightness = max(0, min($brightness, 255));
            $contrast = max(0, min($contrast, 255));
            $sharpness = max(0, min($sharpness, 1000));
            $width = max(0, $width);
            $height = max(0, $height);
            $position = $duration > 0 ? min(intdiv($timestamp * 100, $duration), 100) : 0;
            $bucket = intdiv($timestamp, 1000);
            $near = false;
            for ($b = $bucket - 1; $b <= $bucket + 1; $b++) {
                if (isset($accepted[$b]) && abs($timestamp - $accepted[$b]) < 1000) {
                    $near = true;
                    break;
                }
            }
            $gate = match (true) {
                trim($item) === '' => 0,
                $malformed => 1,
                $duration > 0 && $timestamp > $duration => 2,
                $width < 64 || $height < 64 => 3,
                $brightness < 16 => 4,
                $brightness > 240 => 5,
                $contrast < 12 => 6,
                $near => 7,
                default => 8,
            };
            $bonus = $duration > 0 && $position >= 20 && $position <= 80 ? 200 : ($duration > 0 && $position >= 10 && $position <= 90 ? 100 : 0);
            $score = $gate <= 1 ? 0 : $sharpness + $contrast * 2 + (128 - abs($brightness - 128)) * 2 + $bonus
                + min(intdiv(min($width, 65535) * min($height, 65535), 10_000), 300);
            if ($gate === 8) {
                $accepted[$bucket] = $timestamp;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                    $bestTimestamp = $timestamp;
                }
            }
            $flags = ($duration > 0 && $position < 5 ? 1 : 0) | ($duration > 0 && $position > 95 ? 2 : 0) | ($duration === 0 ? 4 : 0)
                | ($height > $width ? 8 : 0) | (preg_match('/[\x80-\xff]/', $item) === 1 ? 16 : 0)
                | (strcspn($item, self::W65_CONTROL_BYTES) !== \strlen($item) ? 32 : 0) | ($sharpness >= 500 ? 64 : 0)
                | (str_contains($rawHeight, '|') ? 128 : 0);
            $tail[$gate]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[9 + $b] += $flags >> $b & 1;
            }
            if ($gate === 8) {
                $tail[17 + min(intdiv($score, 600), 3)]++;
                $tail[24] += $score;
            }
            $tail[25] = max($tail[25], $timestamp);
            $out[] = $gate | $flags << 4 | $score << 12 | $position << 24 | $brightness << 31 | $contrast << 39 | self::w65BitLength($timestamp) << 47
                | self::w65BitLength($width) << 53;
        }
        $tail[21] = $bestIndex;
        $tail[22] = max(0, $bestScore);
        $tail[23] = $bestTimestamp;
        array_push($out, ...$tail);

        return $out;
    }

    private const string W65_DIGITS = '0123456789';

    private const string W65_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w65Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W65_DIGITS) === $length ? (int) $value : -1;
    }

    private static function w65BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Wave 65 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('thumbnail_selector_w651', self::W65_THUMBNAIL_SELECTOR_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w65RustFfiState('ffi');
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
                $handle = self::w65RustFfiState('ffi');
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
     * Calls the Wave 65.1 ThumbnailFrameSelector export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w65Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w65RustFfiState('ffi');
            $out = self::w65RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w65RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W65_THUMBNAIL_SELECTOR_FFI_CDEF.)
            $written = $ffi->nntmux_w651_thumbnail_selector_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
