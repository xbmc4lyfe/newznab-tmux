<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
