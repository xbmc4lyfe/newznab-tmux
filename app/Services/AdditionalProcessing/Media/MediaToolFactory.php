<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Throwable;

/**
 * Builds the ffmpeg and ffprobe clients from the configured binaries.
 *
 * Both clients share one probe whose cache is bounded, because a worker lives
 * through thousands of releases and php-ffmpeg's default cache never forgets.
 */
class MediaToolFactory
{
    public const bool W45_RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_mtf_driver_mask(int32_t ffmpeg_is_string, size_t ffmpeg_len, int32_t ffprobe_is_string, size_t ffprobe_len);
        int32_t nntmux_w45_tool_driver_mask(int32_t ffmpeg_is_string, size_t ffmpeg_len, int32_t ffprobe_is_string, size_t ffprobe_len);
        C;

    // PHP remains the production default until benchmarks qualify Rust.
    public static bool $useRustFfi = self::W45_RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    private const int PROBE_CACHE_ITEMS = 64;

    private ?FFProbe $ffprobe = null;

    private ?FFMpeg $ffmpeg = null;

    public static function useW45RustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? self::W45_RUST_FFI_DEFAULT;
    }

    public static function usesW45RustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public function __construct(private readonly ProcessingConfiguration $config) {}

    public function ffprobe(): FFProbe
    {
        return $this->ffprobe ??= FFProbe::create(
            $this->driverConfiguration(),
            null,
            new ArrayAdapter(0, false, 0, self::PROBE_CACHE_ITEMS),
        );
    }

    public function ffmpeg(): FFMpeg
    {
        return $this->ffmpeg ??= FFMpeg::create($this->driverConfiguration(), null, $this->ffprobe());
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function additionalFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @return array{timeout: int, 'ffmpeg.binaries'?: string, 'ffprobe.binaries'?: string}
     */
    public function driverConfiguration(): array
    {
        return self::driverConfigurationFor(
            $this->config->mediaFfmpegTimeout,
            $this->config->ffmpegPath,
            $this->config->ffprobePath,
        );
    }

    /**
     * @return array{timeout: int, 'ffmpeg.binaries'?: string, 'ffprobe.binaries'?: string}
     */
    public static function driverConfigurationFor(int $timeout, string|false $ffmpegPath, string|false $ffprobePath): array
    {
        return self::$useRustFfi
            ? self::driverConfigurationNative($timeout, $ffmpegPath, $ffprobePath)
            : self::driverConfigurationPhp($timeout, $ffmpegPath, $ffprobePath);
    }

    /**
     * @return array{timeout: int, 'ffmpeg.binaries'?: string, 'ffprobe.binaries'?: string}
     */
    public static function driverConfigurationPhp(int $timeout, string|false $ffmpegPath, string|false $ffprobePath): array
    {
        $configuration = ['timeout' => $timeout];

        if (is_string($ffmpegPath) && $ffmpegPath !== '') {
            $configuration['ffmpeg.binaries'] = $ffmpegPath;
        }
        if (is_string($ffprobePath) && $ffprobePath !== '') {
            $configuration['ffprobe.binaries'] = $ffprobePath;
        }

        return $configuration;
    }

    /**
     * @return array{timeout: int, 'ffmpeg.binaries'?: string, 'ffprobe.binaries'?: string}
     */
    public static function driverConfigurationNative(int $timeout, string|false $ffmpegPath, string|false $ffprobePath): array
    {
        $ffi = self::additionalFfi();
        if ($ffi === null) {
            return self::driverConfigurationPhp($timeout, $ffmpegPath, $ffprobePath);
        }

        try {
            // @phpstan-ignore method.notFound
            $mask = $ffi->nntmux_w45_tool_driver_mask(
                is_string($ffmpegPath) ? 1 : 0,
                is_string($ffmpegPath) ? strlen($ffmpegPath) : 0,
                is_string($ffprobePath) ? 1 : 0,
                is_string($ffprobePath) ? strlen($ffprobePath) : 0,
            );
        } catch (Throwable) {
            return self::driverConfigurationPhp($timeout, $ffmpegPath, $ffprobePath);
        }
        if (! is_int($mask) || $mask < 0 || $mask > 3) {
            return self::driverConfigurationPhp($timeout, $ffmpegPath, $ffprobePath);
        }

        $configuration = ['timeout' => $timeout];
        if (($mask & 1) !== 0 && is_string($ffmpegPath)) {
            $configuration['ffmpeg.binaries'] = $ffmpegPath;
        }
        if (($mask & 2) !== 0 && is_string($ffprobePath)) {
            $configuration['ffprobe.binaries'] = $ffprobePath;
        }

        return $configuration;
    }

    // Rust is the default only while the paired W52.6 benchmark clears 1.5x on all four metrics.
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        int32_t nntmux_w526_tool_driver_batch(const char *ffmpeg, size_t ffmpeg_len, const char *ffmpeg_twin, size_t ffmpeg_twin_len, const char *ffprobe, size_t ffprobe_len, const char *ffprobe_twin, size_t ffprobe_twin_len, const char *timeouts, size_t timeouts_len, size_t count, int64_t *out, size_t out_count);
        C;

    public const int W52_DRIVER_HEADER = 5;

    private const int W52_MAX_ITEMS = 1_048_576;

    private static ?bool $w52RustFfi = null;

    private static FFI|false|null $w52Ffi = null;

    private static ?CData $w52Out = null;

    private static int $w52Capacity = 0;

    /**
     * `driverConfigurationPhp()` over (timeout, ffmpeg, ffprobe) rows, deduplicated.
     *
     * A missing path counts as `false`. Layout: `[0]` count, `[1]` distinct configurations,
     * `[2]` rows setting `ffmpeg.binaries`, `[3]` rows setting `ffprobe.binaries`, `[4]` FNV-1a
     * digest; then per row `mask | leader << 2`, where `mask` bit 0 is `ffmpeg.binaries`, bit 1
     * `ffprobe.binaries` and `leader` the first row with the same configuration.
     *
     * @param  array<array-key, int>  $timeouts
     * @param  array<array-key, string|false>  $ffmpegPaths
     * @param  array<array-key, string|false>  $ffprobePaths
     * @return list<int>
     */
    public static function driverConfigurationBatch(array $timeouts, array $ffmpegPaths, array $ffprobePaths): array
    {
        return (self::usesW52RustFfi() ? self::driverConfigurationBatchNative($timeouts, $ffmpegPaths, $ffprobePaths) : null)
            ?? self::driverConfigurationBatchPhp($timeouts, $ffmpegPaths, $ffprobePaths);
    }

    /**
     * @param  array<array-key, int>  $timeouts
     * @param  array<array-key, string|false>  $ffmpegPaths
     * @param  array<array-key, string|false>  $ffprobePaths
     * @return list<int>
     */
    public static function driverConfigurationBatchPhp(array $timeouts, array $ffmpegPaths, array $ffprobePaths): array
    {
        $timeouts = array_values($timeouts);
        $ffmpegPaths = array_values($ffmpegPaths);
        $ffprobePaths = array_values($ffprobePaths);
        $out = [\count($timeouts), 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $leaders = $rows = [];
        foreach ($timeouts as $index => $timeout) {
            $binary = $ffmpegPaths[$index] ?? false;
            $probe = $ffprobePaths[$index] ?? false;
            // A binary is configured exactly when its path is a non-empty string.
            $binary = \is_string($binary) ? $binary : '';
            $probe = \is_string($probe) ? $probe : '';
            $mask = ($binary !== '' ? 1 : 0) | ($probe !== '' ? 2 : 0);
            $out[2] += $mask & 1;
            $out[3] += $mask >> 1;
            $leader = $leaders[$timeout.':'.\strlen($binary).':'.$binary.$probe] ??= $index;
            if ($leader === $index) {
                $out[1]++;
            }
            $row = $mask | $leader << 2;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[4] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust configurations, or null when PHP must compute them (unavailable library or invalid input).
     *
     * @param  array<array-key, int>  $timeouts
     * @param  array<array-key, string|false>  $ffmpegPaths
     * @param  array<array-key, string|false>  $ffprobePaths
     * @return list<int>|null
     */
    public static function driverConfigurationBatchNative(array $timeouts, array $ffmpegPaths, array $ffprobePaths): ?array
    {
        $count = \count($timeouts);
        $fields = self::W52_DRIVER_HEADER + $count;
        $binding = $count <= self::W52_MAX_ITEMS ? self::w52Binding($fields) : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        if (\count($ffmpegPaths) !== $count) {
            $ffmpegPaths = array_pad(\array_slice(array_values($ffmpegPaths), 0, $count), $count, false);
        }
        if (\count($ffprobePaths) !== $count) {
            $ffprobePaths = array_pad(\array_slice(array_values($ffprobePaths), 0, $count), $count, false);
        }
        // implode() turns false into "", which configures no binary either.
        $binaries = implode("\0", $ffmpegPaths);
        $binaryTwin = $count > 0 && substr_count($binaries, "\0") !== $count - 1 ? implode("\x01", $ffmpegPaths) : '';
        $probes = implode("\0", $ffprobePaths);
        $probeTwin = $count > 0 && substr_count($probes, "\0") !== $count - 1 ? implode("\x01", $ffprobePaths) : '';
        $packed = pack('q*', ...array_values($timeouts));
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w526_tool_driver_batch(
                $binaries, \strlen($binaries), $binaryTwin, \strlen($binaryTwin), $probes, \strlen($probes), $probeTwin, \strlen($probeTwin),
                $packed, \strlen($packed), $count, $out, $fields,
            );
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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.20x, p99 4.70x; uncommon mean 5.96x, p99 4.32x); see RustAdditionalMediaStageToolPar2MapperThumbExtractionW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_MEDIA_TOOL_FACTORY_FFI_CDEF = <<<'C'
        ssize_t nntmux_w651_media_tool_factory_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w65MediaToolFactoryPlanBatch(). */
    public const int W65_MEDIA_TOOL_FACTORY_TRAILER = 35;

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /** Forces the MediaToolFactory Wave 65 Rust path on until reset. */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /** Forces the MediaToolFactory Wave 65 PHP path until reset. */
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
     * Runs $callback with the MediaToolFactory Wave 65 Rust path forced on or off, then restores the previous setting.
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
     * Wave 65.1 media tool plans, one per configured binary `tool|path|timeout|threads|args|version` (trimmed fields; numbers
     * are 1-15 digits, empty 0). Tool (lowercased): 0 empty, 1 ffmpeg, 2 ffprobe, 3 mediainfo, 4 unrar, 5 7z, 6 par2, 7 other.
     * Path state: 0 empty, 1 absolute, 2 bare name, 3 relative path. Placeholders: 1 `{input}`, 2 `{output}`, 4 `{timeout}`;
     * ffmpeg, unrar and 7z need input and output, the others input. The version major is the leading 1-4 digits after an
     * optional `n`/`v`; minimum majors are ffmpeg/ffprobe 4, mediainfo 20, unrar 5, 7z 16, par2 0. The effective timeout is
     * 60 seconds when unset, else at most 3600. Decision: 0 empty row, 1 malformed (bad number or unknown tool), 2 missing
     * binary, 3 unsafe path (`..` segment, shell metacharacter or control byte), 4 missing placeholder, 5 outdated, 6
     * unverified version, 7 ready. Flags: 1 timeout clamped, 2 timeout defaulted, 4 space in path, 8 non-ASCII, 16 path
     * repeated in the batch, 32 unbalanced braces, 64 unparsable version, 128 relative path. Per item `decision | tool << 3 |
     * pathState << 6 | placeholders << 8 | flags << 11 | min(timeout, 4095) << 19 | min(threads, 255) << 31 | min(major, 1023)
     * << 39 | min(pathLength, 1023) << 49`; then 8 decision, 8 tool, 4 path state, 3 placeholder and 8 flag counts, the
     * timeout sum and maximum, the distinct paths and the `1 << tool` mask of ready tools.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaToolFactoryPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65MediaToolFactoryPlanBatchNative($items) : self::w65MediaToolFactoryPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaToolFactoryPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_MEDIA_TOOL_FACTORY_TRAILER) ?? self::w65MediaToolFactoryPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaToolFactoryPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W65_MEDIA_TOOL_FACTORY_TRAILER, 0);
        $paths = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawTool, $rawPath, $rawTimeout, $rawThreads, $rawArgs, $rawVersion] = explode('|', $item, 6) + ['', '', '', '', '', ''];
            $toolName = strtolower(trim($rawTool));
            $tool = $toolName === '' ? 0 : (self::W65_TOOLS[$toolName] ?? 7);
            $path = trim($rawPath);
            $pathLength = \strlen($path);
            $timeout = self::w65Uint($rawTimeout, 0);
            $threads = self::w65Uint($rawThreads, 0);
            $args = trim($rawArgs);
            $version = strtolower(trim($rawVersion));
            $pathState = $path === '' ? 0 : ($path[0] === '/' ? 1 : (str_contains($path, '/') ? 3 : 2));
            $unsafe = \in_array('..', explode('/', $path), true) || strpbrk($path, ';&$`<>') !== false
                || strcspn($path, self::W65_CONTROL_BYTES) !== $pathLength;
            $placeholders = (str_contains($args, '{input}') ? 1 : 0) | (str_contains($args, '{output}') ? 2 : 0) | (str_contains($args, '{timeout}') ? 4 : 0);
            $digits = $version !== '' && ($version[0] === 'n' || $version[0] === 'v') ? substr($version, 1) : $version;
            $run = strspn($digits, self::W65_DIGITS);
            $major = $run === 0 || $run > 4 ? null : (int) substr($digits, 0, $run);
            $known = $tool >= 1 && $tool <= 6;
            $required = $known ? self::W65_TOOL_PLACEHOLDERS[$tool - 1] : 0;
            $minimum = $known ? self::W65_TOOL_MIN_MAJOR[$tool - 1] : 0;
            $effective = $timeout <= 0 ? 60 : min($timeout, 3600);
            $decision = match (true) {
                trim($item) === '' => 0,
                $timeout < 0 || $threads < 0 || ! $known => 1,
                $path === '' => 2,
                $unsafe => 3,
                ($placeholders & $required) !== $required => 4,
                $major !== null && $major < $minimum => 5,
                $major === null => 6,
                default => 7,
            };
            $flags = ($timeout > 3600 ? 1 : 0) | ($timeout === 0 ? 2 : 0) | (str_contains($path, ' ') ? 4 : 0)
                | (preg_match('/[\x80-\xff]/', $item) === 1 ? 8 : 0) | (substr_count($args, '{') !== substr_count($args, '}') ? 32 : 0)
                | ($version !== '' && $major === null ? 64 : 0) | ($pathState >= 2 ? 128 : 0);
            if ($path !== '') {
                if (isset($paths[$path])) {
                    $flags |= 16;
                } else {
                    $paths[$path] = true;
                }
            }
            $tail[$decision]++;
            $tail[8 + $tool]++;
            $tail[16 + $pathState]++;
            for ($b = 0; $b < 3; $b++) {
                $tail[20 + $b] += $placeholders >> $b & 1;
            }
            for ($b = 0; $b < 8; $b++) {
                $tail[23 + $b] += $flags >> $b & 1;
            }
            $tail[31] += $effective;
            $tail[32] = max($tail[32], $effective);
            if ($decision === 7) {
                $tail[34] |= 1 << $tool;
            }
            $out[] = $decision | $tool << 3 | $pathState << 6 | $placeholders << 8 | $flags << 11 | min($effective, 4095) << 19
                | max(0, min($threads, 255)) << 31 | min($major ?? 0, 1023) << 39 | min($pathLength, 1023) << 49;
        }
        $tail[33] = \count($paths);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, int> */
    private const array W65_TOOLS = ['ffmpeg' => 1, 'ffprobe' => 2, 'mediainfo' => 3, 'unrar' => 4, '7z' => 5, 'par2' => 6];

    /** @var list<int> */
    private const array W65_TOOL_PLACEHOLDERS = [3, 1, 1, 3, 3, 1];

    /** @var list<int> */
    private const array W65_TOOL_MIN_MAJOR = [4, 4, 20, 5, 16, 0];

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
            $ffi ??= RustPipelineLibrary::resolveDomain('media_tool_factory_w651', self::W65_MEDIA_TOOL_FACTORY_FFI_CDEF) ?? false;

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
     * Calls the Wave 65.1 MediaToolFactory export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W65_MEDIA_TOOL_FACTORY_FFI_CDEF.)
            $written = $ffi->nntmux_w651_media_tool_factory_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
