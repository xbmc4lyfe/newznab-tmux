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
}
