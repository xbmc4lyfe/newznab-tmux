<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grabs keyframes with one ffmpeg call. Only keyframes are decoded, so even a
 * frame far into a file is reached quickly, and frames are selected by
 * timestamp rather than seeking, which a partial download cannot support.
 */
class FfmpegFrameGrabber implements VideoFrameGrabber
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

    public const string FFI_CDEF = <<<'C'
        double nntmux_w436_ffmpeg_clock_seconds(const char *ptr, size_t len);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    public function __construct(
        private readonly MediaToolFactory $tools,
        private readonly bool $debugMode = false,
    ) {}

    public function grab(string $videoPath, ?VideoContainer $container, float $from, float $spacing, int $count, string $outputDirectory): array
    {
        $pattern = rtrim($outputDirectory, '/').'/frame_'.bin2hex(random_bytes(4)).'_';

        try {
            $this->tools->ffmpeg()->getFFMpegDriver()->command([
                '-y',
                '-v', 'error',
                '-skip_frame', 'nokey',
                ...$this->inputFormat($container),
                '-i', $videoPath,
                '-an', '-sn', '-dn',
                // Selected frames are renumbered one second apart and written at one per second,
                // so the image muxer never repeats a frame to fill a gap, on any ffmpeg version.
                '-vf', sprintf(
                    "select='gte(t,%.3F)*(isnan(prev_selected_t)+gte(t-prev_selected_t,%.3F))',setpts=N/TB,scale='min(1280,iw)':-2",
                    max(0.0, $from),
                    max(0.0, $spacing),
                ),
                '-r', '1',
                '-frames:v', (string) max(1, $count),
                '-q:v', '3',
                $pattern.'%02d.jpg',
            ]);
        } catch (Throwable $e) {
            // A truncated file often fails after the frames we need were written.
            if ($this->debugMode) {
                Log::debug('Frame grab ended with an error: '.$e->getMessage());
            }
        }

        $frames = glob($pattern.'*.jpg') ?: [];
        sort($frames);

        return array_values(array_filter($frames, static fn (string $frame): bool => (int) @filesize($frame) > 0));
    }

    public function probe(string $videoPath, ?VideoContainer $container): array
    {
        try {
            $output = $this->tools->ffprobe()->getFFProbeDriver()->command([
                '-v', 'error',
                ...$this->inputFormat($container),
                '-show_format',
                '-show_streams',
                '-print_format', 'json',
                $videoPath,
            ]);
        } catch (Throwable $e) {
            if ($this->debugMode) {
                Log::debug('Probe failed: '.$e->getMessage());
            }

            return ['duration' => null, 'bitRate' => null];
        }

        $info = json_decode((string) $output, true);
        if (! is_array($info)) {
            return ['duration' => null, 'bitRate' => null];
        }

        $video = null;
        foreach ($info['streams'] ?? [] as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;
                break;
            }
        }

        $tags = array_change_key_case(is_array($video['tags'] ?? null) ? $video['tags'] : [], CASE_UPPER);
        $duration = self::positiveFloat($info['format']['duration'] ?? null)
            ?? self::positiveFloat($video['duration'] ?? null)
            ?? self::clockSeconds($tags['DURATION'] ?? null);
        $bitRate = self::positiveFloat($tags['BPS'] ?? null) ?? self::positiveFloat($video['bit_rate'] ?? null);

        return ['duration' => $duration, 'bitRate' => $bitRate === null ? null : (int) $bitRate];
    }

    /**
     * @return list<string>
     */
    private function inputFormat(?VideoContainer $container): array
    {
        return $container === null ? [] : ['-f', $container->value];
    }

    private static function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * Seconds in a Matroska "HH:MM:SS.nnnnnnnnn" duration tag.
     */
    public static function clockSeconds(mixed $value): ?float
    {
        return self::usesRustFfi() ? self::clockSecondsNative($value) : self::clockSecondsPhp($value);
    }

    public static function clockSecondsPhp(mixed $value): ?float
    {
        if (! is_string($value) || preg_match('/^(\d+):(\d{2}):(\d{2}(?:\.\d+)?)$/', $value, $match) !== 1) {
            return null;
        }

        return self::positiveFloat((int) $match[1] * 3600 + (int) $match[2] * 60 + (float) $match[3]);
    }

    public static function clockSecondsNative(mixed $value, bool &$supported = false, ?string $libraryPath = null): ?float
    {
        $supported = false;
        if (! is_string($value)) {
            $supported = true;

            return null;
        }

        $ffi = $libraryPath === null
            ? self::ffi()
            : RustPipelineLibrary::resolveDomain('ffmpeg_frame_grabber', self::FFI_CDEF, $libraryPath);
        if ($ffi === null) {
            return self::clockSecondsPhp($value);
        }

        try {
            // @phpstan-ignore method.notFound
            $seconds = $ffi->nntmux_w436_ffmpeg_clock_seconds($value, strlen($value));
        } catch (Throwable) {
            return self::clockSecondsPhp($value);
        }

        if (! is_float($seconds) && ! is_int($seconds)) {
            return self::clockSecondsPhp($value);
        }

        $seconds = (float) $seconds;
        if ($seconds <= -1.5) {
            return self::clockSecondsPhp($value);
        }

        $supported = true;

        return $seconds > 0.0 ? $seconds : null;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('ffmpeg_frame_grabber', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    // Rust is the default only while the paired W49.6 benchmark clears 1.5x on all four metrics.
    private const bool W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        int32_t nntmux_w49_ffmpeg_clock_batch(const char *values, size_t values_len, const char *value_twin, size_t value_twin_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W49_CLOCK_HEADER = 8;

    private const int W49_MAX_ITEMS = 1_048_576;

    private const int W49_TOTAL_CAP = 1 << 62;

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
     * Parse a batch of Matroska `HH:MM:SS[.fraction]` duration tags to integer nanoseconds,
     * keeping nine fraction digits. A value is 0 when it is not a string, does not match,
     * is not positive or has more than 2,000,000 hours.
     *
     * Layout: `[0]` count, `[1]` valid, `[2]` invalid, `[3]` total (capped at 2^62), `[4]`
     * longest and `[5]` its first index or -1, `[6]` shortest valid (0 without one), `[7]`
     * FNV-1a digest of whole seconds; then the nanoseconds per value.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<int>
     */
    public static function parseClockBatch(array $values): array
    {
        return (self::usesW49RustFfi() ? self::parseClockBatchNative($values) : null) ?? self::parseClockBatchPhp($values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<int>
     */
    public static function parseClockBatchPhp(array $values): array
    {
        $values = array_values($values);
        $out = array_fill(0, self::W49_CLOCK_HEADER, 0);
        $out[0] = \count($values);
        $out[5] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($values as $index => $value) {
            $nanos = 0;
            if (\is_string($value) && preg_match('/^(\d+):(\d{2}):(\d{2})(?:\.(\d+))?$/', $value, $match) === 1) {
                $hours = ltrim($match[1], '0');
                if (\strlen($hours) <= 7 && ($hours = (int) $hours) <= 2_000_000) {
                    $nanos = $hours * 3_600_000_000_000 + (int) $match[2] * 60_000_000_000 + (int) $match[3] * 1_000_000_000
                        + (int) substr(str_pad($match[4] ?? '', 9, '0'), 0, 9);
                }
            }
            if ($nanos > 0) {
                $out[1]++;
                $out[3] = $nanos > self::W49_TOTAL_CAP - $out[3] ? self::W49_TOTAL_CAP : $out[3] + $nanos;
                if ($out[6] === 0 || $nanos < $out[6]) {
                    $out[6] = $nanos;
                }
            } else {
                $out[2]++;
            }
            if ($nanos > $out[4]) {
                $out[4] = $nanos;
                $out[5] = $index;
            }
            $digest = (($digest ^ (intdiv($nanos, 1_000_000_000) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $nanos;
        }
        $out[7] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust clocks, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, mixed>  $values
     * @return list<int>|null
     */
    public static function parseClockBatchNative(array $values): ?array
    {
        $strings = [];
        foreach ($values as $value) {
            $strings[] = \is_string($value) ? $value : '';
        }
        $count = \count($strings);
        $fields = self::W49_CLOCK_HEADER + $count;
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = $count <= self::W49_MAX_ITEMS ? self::w49RustFfiState('buffer', $fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w49Strings($strings);
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w49_ffmpeg_clock_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $fields);
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
