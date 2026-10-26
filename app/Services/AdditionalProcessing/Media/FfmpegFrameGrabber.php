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

    // Rust is the default only while the paired W56.6 benchmark clears 1.5x on all four metrics.
    private const bool W56_RUST_FFI_DEFAULT = true;

    public const string W56_FFI_CDEF = <<<'C'
        int32_t nntmux_w566_frame_output_batch(const char *paths, size_t paths_len, const char *path_twin, size_t path_twin_len, const char *sizes, size_t sizes_len, size_t count, int64_t from_ms, int64_t spacing_ms, int64_t *out, size_t out_cap);
        C;

    public const int W56_FRAME_HEADER = 13;

    private const int W56_MAX_ITEMS = 1_048_576;

    private const int W56_SEEK_CAP = 2_147_483_647;

    private const int W56_EXPECTED_CAP = 1_073_741_823;

    private const int W56_VALUE_CAP = 1_099_511_627_775;

    private const int W56_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * Validate the frames grab() wrote, as paths and byte sizes, against the seek start and spacing
     * in milliseconds (each clamped to 0..2^31-1). Basenames must be `frame_<8 hex>_<2-9 digits>.jpg`;
     * the first matching name fixes the token. Status 0 accepted, 1 bad name, 2 empty (size 0 or
     * less), 3 foreign token, 4 not after the last accepted frame; missing sizes are 0.
     *
     * Layout: `[0]` count, `[1..5]` per status, `[6]` accepted bytes (sizes clamped to 2^40-1, total
     * capped at 2^62), `[7]` first and `[8]` last accepted frame number (-1 when none), `[9]` frames
     * missing between accepted ones, `[10]` seek start, `[11]` spacing, `[12]` FNV-1a digest; then
     * per frame `status | number << 3 | expected << 33` (expected milliseconds `start + (number - 1)
     * * spacing`, `start` for frame 0 or a bad name, capped at 2^30-1).
     *
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function classifyFrameBatch(array $paths, array $sizes, int $fromMs, int $spacingMs): array
    {
        return (self::usesW56RustFfi() ? self::classifyFrameBatchNative($paths, $sizes, $fromMs, $spacingMs) : null)
            ?? self::classifyFrameBatchPhp($paths, $sizes, $fromMs, $spacingMs);
    }

    /**
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @return list<int>
     */
    public static function classifyFrameBatchPhp(array $paths, array $sizes, int $fromMs, int $spacingMs): array
    {
        $sizes = array_values($sizes);
        $from = min(max($fromMs, 0), self::W56_SEEK_CAP);
        $spacing = min(max($spacingMs, 0), self::W56_SEEK_CAP);
        $out = array_fill(0, self::W56_FRAME_HEADER, 0);
        $out[0] = \count($paths);
        $out[7] = $out[8] = -1;
        $out[10] = $from;
        $out[11] = $spacing;
        $first = null;
        $digest = 2_166_136_261;
        $rows = [];
        $index = 0;
        foreach ($paths as $path) {
            $slash = strrpos($path, '/');
            $base = $slash === false ? $path : substr($path, $slash + 1);
            $size = $sizes[$index] ?? 0;
            $number = 0;
            if (preg_match('/^frame_([0-9a-f]{8})_(\d{2,9})\.jpg$/D', $base, $match) !== 1) {
                $status = 1;
            } else {
                $number = (int) $match[2];
                $first ??= $match[1];
                $status = match (true) {
                    $match[1] !== $first => 3,
                    $size <= 0 => 2,
                    $out[8] >= 0 && $number <= $out[8] => 4,
                    default => 0,
                };
            }
            if ($status === 0) {
                $out[6] = min($out[6] + min($size, self::W56_VALUE_CAP), self::W56_TOTAL_CAP);
                if ($out[8] >= 0) {
                    $out[9] += $number - $out[8] - 1;
                } else {
                    $out[7] = $number;
                }
                $out[8] = $number;
            }
            $out[1 + $status]++;
            $expected = min($from + max($number - 1, 0) * $spacing, self::W56_EXPECTED_CAP);
            $row = $status | $number << 3 | $expected << 33;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
            $index++;
        }
        $out[12] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust validation, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     * @return list<int>|null
     */
    public static function classifyFrameBatchNative(array $paths, array $sizes, int $fromMs, int $spacingMs): ?array
    {
        $count = \count($paths);
        if ($count > self::W56_MAX_ITEMS) {
            return null;
        }
        [$joined, $twin] = self::w56Strings(array_values($paths));
        $ints = pack('q*', ...self::w56Fit($sizes, $count, 0));
        $fields = self::W56_FRAME_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w56Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w566_frame_output_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $fromMs, $spacingMs, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 56.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    public static function usesW56RustFfi(): bool
    {
        $forced = self::w56RustFfiState();

        return \is_bool($forced) ? $forced : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 56.6 routing and binding, kept in function statics so earlier waves' static state stays
     * untouched. No `$op` returns the forced routing (null for the default); `'set'` replaces it with
     * `$val` and returns the previous value; `'binding'` returns the cached `additional_w56` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w56', self::W56_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 56.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w56Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w56RustFfiState('binding', $fields);
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
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w56Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    /**
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with `$pad`.
     *
     * @template TValue
     *
     * @param  array<array-key, TValue>  $values
     * @param  TValue  $pad
     * @return list<TValue>
     */
    private static function w56Fit(array $values, int $count, mixed $pad): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, $pad);
    }

    /** C signature of the Wave 64.5 {@see self::w64FfmpegGrabberPlanBatch()} helper. */
    private const string W64_FFMPEG_GRABBER_PLAN_FFI_CDEF = 'int64_t nntmux_w645_ffmpeg_grabber_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 64.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 6.75/6.69/6.60/5.54x); see tests/Unit/RustAdditionalFfmpegFrameScorerRankerInspectorFetcherW64ParityTest.php.
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
                    $ffi = RustPipelineLibrary::resolveDomain('w64_5', self::W64_FFMPEG_GRABBER_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
     * Runs $callback with w64FfmpegGrabberPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
            $written = $ffi->nntmux_w645_ffmpeg_grabber_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch grab() plan over `duration\twidth\theight\tcount\tfrom_ms\tspacing_ms\tcontainer`. Ten words per record:
     * `[duration_ms, from_ms, spacing_ms, frames, last_ms, scaled_width, scaled_height, container, options, flags]`.
     * The duration is a trimmed Matroska `H:MM:SS[.fraction]` tag (1-7 hour digits, fraction truncated to
     * milliseconds) or 1-15 digit milliseconds; other numbers are trimmed 1-15 ASCII digits (empty takes the
     * default: count 1, the rest 0). Frames are the count clamped to 1..64. With a known duration the seek start
     * is capped at 90% of it and, for several frames, the spacing at the remaining time per gap (an unset spacing
     * spreads the frames evenly). The scaled size follows `scale='min(1280,iw)':-2` (even height); the container
     * is its 1-based `VideoContainer` case for an exact demuxer name, 0 empty or -1 unknown, and options counts
     * grab()'s ffmpeg arguments. Flags: 1 invalid number, 2 not exactly seven fields, 4 count clamped, 8 unknown
     * container, 16 seek start capped, 32 missing resolution, 64 duration read from a clock tag.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64FfmpegGrabberPlanBatch(array $records): array
    {
        return self::usesW64RustFfi() ? self::w64FfmpegGrabberPlanBatchNative($records) : self::w64FfmpegGrabberPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64FfmpegGrabberPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w64Words($count, implode("\0", $records), 10 * $count) ?? self::w64FfmpegGrabberPlanBatchPhp($records);
    }

    private const array W64_CONTAINERS = ['matroska', 'avi', 'mpeg', 'mpegts', 'mov', 'asf', 'ogg'];

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w64FfmpegGrabberPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 7 ? 0 : 2;
            $text = trim($fields[0]);
            if ($text === '') {
                $duration = 0;
            } elseif (preg_match('/^(\d{1,7}):(\d{2}):(\d{2})(?:\.(\d+))?$/D', $text, $match) === 1) {
                $flags |= 64;
                $duration = (int) $match[1] * 3_600_000 + (int) $match[2] * 60_000 + (int) $match[3] * 1000
                    + (int) substr(str_pad($match[4] ?? '', 3, '0'), 0, 3);
            } elseif (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
                $duration = (int) $text;
            } else {
                $flags |= 1;
                $duration = 0;
            }
            $width = self::w64Number($fields[1] ?? '', 0, $flags);
            $height = self::w64Number($fields[2] ?? '', 0, $flags);
            $requested = self::w64Number($fields[3] ?? '', 1, $flags);
            $frames = min(max($requested, 1), 64);
            if ($frames !== $requested) {
                $flags |= 4;
            }
            $from = self::w64Number($fields[4] ?? '', 0, $flags);
            $spacing = self::w64Number($fields[5] ?? '', 0, $flags);
            if ($duration > 0) {
                $limit = intdiv($duration * 9, 10);
                if ($from > $limit) {
                    $from = $limit;
                    $flags |= 16;
                }
                if ($frames > 1) {
                    $available = $duration - $from;
                    $spacing = $spacing > 0 ? min($spacing, intdiv($available, $frames - 1)) : intdiv($available, $frames);
                }
            }
            if ($width === 0 || $height === 0) {
                $flags |= 32;
                $scaledWidth = $scaledHeight = 0;
            } else {
                $scaled = $width > 1280 ? intdiv($height * 1280, $width) : $height;
                $scaledWidth = min($width, 1280);
                $scaledHeight = $scaled - $scaled % 2;
            }
            $name = trim($fields[6] ?? '');
            $container = 0;
            if ($name !== '') {
                $at = array_search($name, self::W64_CONTAINERS, true);
                if ($at === false) {
                    $flags |= 8;
                    $container = -1;
                } else {
                    $container = $at + 1;
                }
            }
            array_push($out, $duration, $from, $spacing, $frames, $from + ($frames - 1) * $spacing, $scaledWidth, $scaledHeight, $container, $container > 0 ? 21 : 19, $flags);
        }

        return $out;
    }
}
