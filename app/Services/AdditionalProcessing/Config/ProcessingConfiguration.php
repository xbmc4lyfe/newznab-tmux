<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Config;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use ReflectionProperty;
use Throwable;

/**
 * Configuration DTO for additional post-processing.
 * Centralizes all settings loading from database and config files.
 */
final readonly class ProcessingConfiguration
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_pcfg_kill_string(const char *path, size_t path_len, int32_t path_present, int64_t timeout_seconds, char *out, size_t cap);
        ssize_t nntmux_pcfg_ffprobe_sibling(const char *path, size_t path_len, char *out, size_t cap);
        int32_t nntmux_pcfg_normalize_limits(const int64_t *raw, size_t count, int64_t *out, size_t out_count);
        C;

    /** Number of integer limits handled by {@see normalizeLimits()}. */
    public const int LIMIT_COUNT = 14;

    // PHP remains the production default until benchmarks qualify Rust.
    public bool $useRustFfi;

    public bool $echoCLI;

    public bool|string $innerFileBlacklist;

    public int $maxNestedLevels;

    public bool $extractUsingRarInfo;

    public bool $fetchLastFiles;

    public string|false $unrarPath;

    public string|false $unzipPath;

    public string|false $sevenZipPath;

    public string|false $timeoutPath;

    public int $timeoutSeconds;

    public int $queryLimit;

    public int $segmentsToDownload;

    public int $maximumRarSegments;

    public int $maximumRarPasswordChecks;

    public int $archiveProbeFiles;

    public int $seasonPackArchiveSets;

    public int $seasonPackMaxReleaseFiles;

    public int $maxSizeBytes;

    public int $minSizeBytes;

    public bool $alternateNNTP;

    public int $ffmpegDuration;

    public bool $addPAR2Files;

    public bool $processVideo;

    public bool $processThumbnails;

    public bool $processAudioSample;

    public bool $processJPGSample;

    public bool $processMediaInfo;

    public bool $processAudioInfo;

    public bool $processPasswords;

    public string $audioSavePath;

    public string $tmpUnrarPath;

    public bool $debugMode;

    public bool $searchEnabled;

    public string $searchDriver;

    public bool $renameMusicMediaInfo;

    public bool $renamePar2;

    public string|false $ffmpegPath;

    public string|false $ffprobePath;

    public string|false $mediaInfoPath;

    // Preview source settings
    public bool $par2MediaMapping;

    public int $par2IndexMaxSegments;

    public int $par2VerifyFiles;

    public bool $mediaArchiveSlicing;

    public bool $mediaMainVideoFallback;

    public bool $mediaMidSources;

    public int $mediaMaxBytes;

    public int $mediaSampleMaxBytes;

    public int $mediaMidBytes;

    public int $mediaMainVideoMinBytes;

    public int $mediaImageMaxBytes;

    public int $mediaStageMaxSeconds;

    public int $mediaFfmpegTimeout;

    public int $thumbnailFrameAttempts;

    public float $thumbnailMinStddev;

    public float $thumbnailMinLuma;

    public float $thumbnailMaxLuma;

    // Timeout settings
    public int $releaseProcessingTimeout;

    public int $maxPpTimeoutCount;

    // Regex patterns
    public string $audioFileRegex;

    public string $ignoreBookRegex;

    public string $supportFileRegex;

    public string $videoFileRegex;

    public function __construct(bool $useRustFfi = false)
    {
        $this->useRustFfi = $useRustFfi;
        $this->echoCLI = (bool) config('nntmux.echocli');
        $innerFileBlacklistValue = app(ConfigurationProvider::class)->postProcessing()->innerFileBlacklist;
        $this->innerFileBlacklist = ($innerFileBlacklistValue === '' || $innerFileBlacklistValue === null)
            ? false
            : $innerFileBlacklistValue;
        $this->maxNestedLevels = (int) app(ConfigurationProvider::class)->postProcessing()->maxNestedLevels ?: 3;
        $this->extractUsingRarInfo = (int) app(ConfigurationProvider::class)->postProcessing()->extractUsingRarInfo !== 0;
        $this->fetchLastFiles = (bool) config('nntmux_settings.fetch_last_file');
        $this->unrarPath = config('nntmux_settings.unrar_path') ?: false;
        $this->unzipPath = config('nntmux_settings.unzip_path') ?: false;
        $this->sevenZipPath = config('nntmux_settings.sevenzip_path') ?: false;
        $this->timeoutPath = config('nntmux_settings.timeout_path') ?: false;
        $this->timeoutSeconds = (int) app(ConfigurationProvider::class)->postProcessing()->timeoutSeconds;
        $this->queryLimit = (int) (app(ConfigurationProvider::class)->postProcessing()->maxAdditionalProcessed ?: 25);
        $this->segmentsToDownload = (int) (app(ConfigurationProvider::class)->postProcessing()->segmentsToDownload ?: 2);
        $this->maximumRarSegments = (int) (app(ConfigurationProvider::class)->postProcessing()->maxPartsProcessed ?: 3);
        // Delegate to AdditionalCandidateQuery so size-filter semantics
        // (explicit '0' means disabled, empty/null means default) are owned
        // in one place and shared between the bucket query and the per-worker
        // fetch.
        $this->maxSizeBytes = AdditionalCandidateQuery::maxSizeBytes();
        $this->minSizeBytes = AdditionalCandidateQuery::minSizeBytes();
        $this->alternateNNTP = (bool) config('nntmux_nntp.alternate_article_fallback');
        $this->ffmpegDuration = (int) (app(ConfigurationProvider::class)->postProcessing()->ffmpegDuration ?: 5);
        $this->addPAR2Files = (bool) config('nntmux_settings.add_par2');
        $this->ffmpegPath = config('nntmux_settings.ffmpeg_path') ?: false;
        $this->ffprobePath = self::resolveFfprobePath(config('nntmux_settings.ffprobe_path') ?: false, $this->ffmpegPath, $useRustFfi);
        $this->mediaInfoPath = config('nntmux_settings.mediainfo_path') ?: false;
        $this->par2MediaMapping = (bool) config('nntmux.par2_media_mapping', true);
        $this->mediaArchiveSlicing = (bool) config('nntmux.media_archive_slicing', true);
        $this->mediaMainVideoFallback = (bool) config('nntmux.media_main_video_fallback', true);
        $this->mediaMidSources = (bool) config('nntmux.media_mid_sources', false);
        [
            $this->archiveProbeFiles,
            $this->seasonPackArchiveSets,
            $this->seasonPackMaxReleaseFiles,
            $this->par2IndexMaxSegments,
            $this->par2VerifyFiles,
            $this->mediaMaxBytes,
            $this->mediaSampleMaxBytes,
            $this->mediaMidBytes,
            $this->mediaMainVideoMinBytes,
            $this->mediaImageMaxBytes,
            $this->mediaStageMaxSeconds,
            $this->mediaFfmpegTimeout,
            $this->thumbnailFrameAttempts,
            $this->maximumRarPasswordChecks,
        ] = self::normalizeLimits([
            (int) config('nntmux.archive_probe_files', 3),
            (int) config('nntmux.season_pack_archive_sets', 3),
            (int) config('nntmux.season_pack_max_release_files', 30),
            (int) config('nntmux.par2_index_max_segments', 2),
            (int) config('nntmux.par2_verify_files', 2),
            (int) config('nntmux.media_max_bytes', 16777216),
            (int) config('nntmux.media_sample_max_bytes', 8388608),
            (int) config('nntmux.media_mid_bytes', 3145728),
            (int) config('nntmux.media_main_video_min_bytes', 104857600),
            (int) config('nntmux.media_image_max_bytes', 8388608),
            (int) config('nntmux.media_stage_max_seconds', 30),
            (int) config('nntmux.media_ffmpeg_timeout', 30),
            (int) config('nntmux.thumbnail_frame_attempts', 4),
            (int) (app(ConfigurationProvider::class)->postProcessing()->passwordCheckAttempts ?: 1),
        ], $useRustFfi);
        $this->thumbnailMinStddev = (float) config('nntmux.thumbnail_min_stddev', 12);
        $this->thumbnailMinLuma = (float) config('nntmux.thumbnail_min_luma', 24);
        $this->thumbnailMaxLuma = (float) config('nntmux.thumbnail_max_luma', 232);
        if (! $this->ffmpegPath) {
            $this->processAudioSample = false;
            $this->processThumbnails = false;
            $this->processVideo = false;
        } else {
            $this->processAudioSample = (int) app(ConfigurationProvider::class)->postProcessing()->saveAudioPreview !== 0;
            $this->processThumbnails = (int) app(ConfigurationProvider::class)->postProcessing()->processThumbnails !== 0;
            $this->processVideo = (int) app(ConfigurationProvider::class)->postProcessing()->processVideos !== 0;
        }
        $this->processJPGSample = (int) app(ConfigurationProvider::class)->postProcessing()->processJpg !== 0;
        $this->processMediaInfo = (bool) $this->mediaInfoPath;
        $this->processAudioInfo = $this->processMediaInfo;
        $this->processPasswords = config('nntmux_settings.check_passworded_rars') === true
            && ! empty(config('nntmux_settings.unrar_path'));
        $this->audioSavePath = config('nntmux_settings.covers_path').'/audiosample/';
        $this->tmpUnrarPath = config('nntmux.tmp_unrar_path');
        $this->debugMode = (bool) config('app.debug');
        $this->searchDriver = config('search.default', 'manticore');
        $this->searchEnabled = in_array($this->searchDriver, ['manticore', 'elasticsearch']);
        $this->renameMusicMediaInfo = (bool) config('nntmux.rename_music_mediainfo');
        $this->renamePar2 = (bool) config('nntmux.rename_par2');
        $this->releaseProcessingTimeout = (int) (app(ConfigurationProvider::class)->postProcessing()->releaseTimeoutSeconds ?: 120);
        $this->maxPpTimeoutCount = (int) (app(ConfigurationProvider::class)->postProcessing()->maxTimeoutCount ?: 3);
        // Regex patterns
        $this->audioFileRegex = '\\.(AAC|AIFF|APE|AC3|ASF|DTS|FLAC|MKA|MKS|MP2|MP3|RA|OGG|OGM|W64|WAV|WMA)';
        $this->ignoreBookRegex = '/\\b(epub|lit|mobi|pdf|sipdf|html)\\b.*\\.rar(?!.{20,})/i';
        $this->supportFileRegex = '\\.(?:vol\\d{1,3}\\+\\d{1,3}|par2|srs|sfv|nzb)';
        $this->videoFileRegex = '\\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)';
    }

    /**
     * Use the configured ffprobe, or the one installed next to ffmpeg.
     */
    public static function resolveFfprobePath(string|false $ffprobePath, string|false $ffmpegPath, bool $useRustFfi = false): string|false
    {
        if ($ffprobePath !== false && is_executable($ffprobePath)) {
            return $ffprobePath;
        }

        if ($ffmpegPath !== false) {
            $sibling = self::ffprobeSiblingCandidate($ffmpegPath, $useRustFfi);
            if (is_executable($sibling)) {
                return $sibling;
            }
        }

        return $ffprobePath;
    }

    /**
     * The ffprobe path installed next to an ffmpeg binary.
     */
    public static function ffprobeSiblingCandidate(string $ffmpegPath, bool $useRustFfi = false): string
    {
        return $useRustFfi
            ? self::ffprobeSiblingCandidateNative($ffmpegPath)
            : self::ffprobeSiblingCandidatePhp($ffmpegPath);
    }

    public static function ffprobeSiblingCandidatePhp(string $ffmpegPath): string
    {
        return dirname($ffmpegPath).DIRECTORY_SEPARATOR.'ffprobe';
    }

    public static function ffprobeSiblingCandidateNative(string $ffmpegPath): string
    {
        $ffi = self::ffi();
        if ($ffi === null || DIRECTORY_SEPARATOR !== '/') {
            return self::ffprobeSiblingCandidatePhp($ffmpegPath);
        }

        $length = strlen($ffmpegPath);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 16, $cap);
        if ($scratch === null) {
            return self::ffprobeSiblingCandidatePhp($ffmpegPath);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_pcfg_ffprobe_sibling($ffmpegPath, $length, $scratch, $cap);

        return is_int($written) && $written > 0
            ? FFI::string($scratch, $written)
            : self::ffprobeSiblingCandidatePhp($ffmpegPath);
    }

    /**
     * Clamp the integer limits, in this order: archiveProbeFiles, seasonPackArchiveSets,
     * seasonPackMaxReleaseFiles, par2IndexMaxSegments, par2VerifyFiles, mediaMaxBytes,
     * mediaSampleMaxBytes, mediaMidBytes, mediaMainVideoMinBytes, mediaImageMaxBytes,
     * mediaStageMaxSeconds, mediaFfmpegTimeout, thumbnailFrameAttempts and
     * maximumRarPasswordChecks.
     *
     * @param  list<int>  $raw
     * @return list<int>
     */
    public static function normalizeLimits(array $raw, bool $useRustFfi = false): array
    {
        return $useRustFfi ? self::normalizeLimitsNative($raw) : self::normalizeLimitsPhp($raw);
    }

    /**
     * @param  list<int>  $raw
     * @return list<int>
     */
    public static function normalizeLimitsPhp(array $raw): array
    {
        if (count($raw) !== self::LIMIT_COUNT) {
            throw new \InvalidArgumentException('Expected '.self::LIMIT_COUNT.' configuration limits.');
        }

        return [
            max(0, $raw[0]),
            max(1, min(50, $raw[1])),
            max(11, min(200, $raw[2])),
            max(1, $raw[3]),
            max(0, $raw[4]),
            max(0, $raw[5]),
            max(1048576, $raw[6]),
            max(262144, $raw[7]),
            max(0, $raw[8]),
            max(1048576, $raw[9]),
            max(0, $raw[10]),
            max(1, $raw[11]),
            min(10, max(1, $raw[12])),
            max($raw[13], 1),
        ];
    }

    /**
     * @param  list<int>  $raw
     * @return list<int>
     */
    public static function normalizeLimitsNative(array $raw): array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($raw) !== self::LIMIT_COUNT) {
            return self::normalizeLimitsPhp($raw);
        }

        try {
            $buffer = $ffi->new('int64_t['.self::LIMIT_COUNT.']');
            $packed = pack('q*', ...$raw);
            FFI::memcpy($buffer, $packed, strlen($packed));
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_pcfg_normalize_limits($buffer, self::LIMIT_COUNT, $buffer, self::LIMIT_COUNT);
            if ($written !== self::LIMIT_COUNT) {
                return self::normalizeLimitsPhp($raw);
            }
            $values = unpack('q*', FFI::string($buffer, strlen($packed)));
        } catch (Throwable) {
            return self::normalizeLimitsPhp($raw);
        }

        return is_array($values) ? array_values($values) : self::normalizeLimitsPhp($raw);
    }

    /**
     * Build the kill string for timeout command wrapper.
     */
    public function getKillString(): string
    {
        return self::killString($this->timeoutPath, $this->timeoutSeconds, $this->rustFfiEnabled());
    }

    public static function killString(string|false $timeoutPath, int $timeoutSeconds, bool $useRustFfi = false): string
    {
        return $useRustFfi
            ? self::killStringNative($timeoutPath, $timeoutSeconds)
            : self::killStringPhp($timeoutPath, $timeoutSeconds);
    }

    public static function killStringPhp(string|false $timeoutPath, int $timeoutSeconds): string
    {
        if ($timeoutPath && $timeoutSeconds > 0) {
            return '"'.$timeoutPath.'" --foreground --signal=KILL '.$timeoutSeconds.' "';
        }

        return '"';
    }

    public static function killStringNative(string|false $timeoutPath, int $timeoutSeconds): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::killStringPhp($timeoutPath, $timeoutSeconds);
        }

        $path = $timeoutPath === false ? '' : $timeoutPath;
        $length = strlen($path);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $length + 64, $cap);
        if ($scratch === null) {
            return self::killStringPhp($timeoutPath, $timeoutSeconds);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_pcfg_kill_string($path, $length, $timeoutPath === false ? 0 : 1, $timeoutSeconds, $scratch, $cap);

        return is_int($written) && $written > 0
            ? FFI::string($scratch, $written)
            : self::killStringPhp($timeoutPath, $timeoutSeconds);
    }

    private function rustFfiEnabled(): bool
    {
        // Configurations built without the constructor (tests) leave the toggle uninitialized.
        return (new ReflectionProperty(self::class, 'useRustFfi'))->isInitialized($this) && $this->useRustFfi;
    }

    public static function ffi(): ?FFI
    {
        static $ffi = null;
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
