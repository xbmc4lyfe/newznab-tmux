<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Config;

use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // Wave 42.6 is independent of every earlier FFI toggle.
    public const bool W426_RUST_FFI_DEFAULT = false;

    private const string W426_FFI_CDEF = 'int32_t nntmux_w426_additional_file_manager_fallback_sync_sevenzip(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_cap);';

    /** @return array{enabled: ?bool, ffi: FFI|false|null} */
    private static function &w426State(): array
    {
        // A static local also supports readonly configuration objects.
        static $state = ['enabled' => null, 'ffi' => null];

        return $state;
    }

    public static function useW426RustFfi(bool $enable = true): void
    {
        $state = &self::w426State();
        $state['enabled'] = $enable;
    }

    public static function usesW426RustFfi(): bool
    {
        return self::w426State()['enabled'] ?? self::W426_RUST_FFI_DEFAULT;
    }

    public static function resetW426RustFfi(): void
    {
        $state = &self::w426State();
        $state = ['enabled' => null, 'ffi' => null];
    }

    /** @return list<int>|null */
    private static function w426Slots(int $operation, string $input, int $count): ?array
    {
        if (PHP_INT_SIZE !== 8 || \strlen($input) > 16_777_216) {
            return null;
        }
        try {
            $state = &self::w426State();
            $state['ffi'] ??= RustPipelineLibrary::resolveDomain('additional', self::W426_FFI_CDEF) ?? false;
            if ($state['ffi'] === false) {
                return null;
            }
            $bytes = $count * 8;
            $buffer = $state['ffi']->new('char['.$bytes.']');
            // @phpstan-ignore method.notFound
            $written = $state['ffi']->nntmux_w426_additional_file_manager_fallback_sync_sevenzip($operation, $input, \strlen($input), $buffer, $bytes);
            if ($written !== $bytes) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $bytes));
            if ($values === false || \count($values) !== $count) {
                return null;
            }
            $values = array_values($values);
            foreach ($values as $index => $value) {
                if (! \is_int($value) || ($value < 0 && ! ($operation === 3 && $index === 7))) {
                    return null;
                }
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w426Add(int $a, int $b): int
    {
        return $a > PHP_INT_MAX - $b ? PHP_INT_MAX : $a + $b;
    }

    /**
     * Summarize independent configuration snapshots; negative capacities clamp to zero and arithmetic saturates.
     * Integer sums saturate at PHP_INT_MAX; malformed rows are counted without coercion.
     *
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, disabled: int, max_in_flight: int, max_timeout_seconds: int, total_max_bytes: int, invalid: int}
     */
    public static function summarizeLimitConfigurationBatch(array $files): array
    {
        return self::usesW426RustFfi()
            ? self::summarizeLimitConfigurationBatchNative($files)
            : self::summarizeLimitConfigurationBatchPhp($files);
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, disabled: int, max_in_flight: int, max_timeout_seconds: int, total_max_bytes: int, invalid: int}
     */
    public static function summarizeLimitConfigurationBatchPhp(array $files): array
    {
        $result = ['total' => \count($files), 'disabled' => 0, 'max_in_flight' => 0, 'max_timeout_seconds' => 0, 'total_max_bytes' => 0, 'invalid' => 0];
        foreach ($files as $row) {
            if (! self::w426ValidRow($row)) {
                $result['invalid']++;

                continue;
            }
            $batch = max(0, $row['batch_size']);
            $threads = max(0, $row['threads']);
            $capacity = $threads > 0 && $batch > intdiv(PHP_INT_MAX, $threads) ? PHP_INT_MAX : $batch * $threads;
            $result['disabled'] += (int) ($batch === 0 || $threads === 0);
            $result['max_in_flight'] = max($result['max_in_flight'], $capacity);
            $result['max_timeout_seconds'] = max($result['max_timeout_seconds'], $row['timeout_seconds'], 0);
            $result['total_max_bytes'] = self::w426Add($result['total_max_bytes'], max(0, $row['max_bytes']));
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, disabled: int, max_in_flight: int, max_timeout_seconds: int, total_max_bytes: int, invalid: int}
     */
    public static function summarizeLimitConfigurationBatchNative(array $files): array
    {
        $total = \count($files);
        if (PHP_INT_SIZE !== 8 || $total > 100_000) {
            return self::summarizeLimitConfigurationBatchPhp($files);
        }
        $frame = pack('qV', 0, $total);
        foreach ($files as $row) {
            if (! self::w426ValidRow($row)) {
                return self::summarizeLimitConfigurationBatchPhp($files);
            }
            $frame .= pack('qqqq', $row['batch_size'], $row['threads'], $row['timeout_seconds'], $row['max_bytes']);
        }
        $slots = self::w426Slots(5, $frame, 6);
        if ($slots === null || $slots[0] !== $total || $slots[1] > $total || $slots[5] !== 0) {
            return self::summarizeLimitConfigurationBatchPhp($files);
        }

        return ['total' => $slots[0], 'disabled' => $slots[1], 'max_in_flight' => $slots[2], 'max_timeout_seconds' => $slots[3], 'total_max_bytes' => $slots[4], 'invalid' => $slots[5]];
    }

    /** @phpstan-assert-if-true array{batch_size: int, threads: int, timeout_seconds: int, max_bytes: int} $row */
    private static function w426ValidRow(mixed $row): bool
    {
        return \is_array($row) && \is_int($row['batch_size'] ?? null) && \is_int($row['threads'] ?? null) && \is_int($row['timeout_seconds'] ?? null) && \is_int($row['max_bytes'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Wave 52.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.85x, p99 2.66x; uncommon mean 3.50x, p99 3.83x); see RustWorkPlannerArchiveConfigConsoleMediaExtractW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        ssize_t nntmux_w524_limit_snapshot_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Normalize many limit snapshots at once. `$raw` holds LIMIT_COUNT integers per snapshot in
     * normalizeLimits() order; each snapshot yields its LIMIT_COUNT normalized limits followed by
     * a mask with bit k set when limit k was clamped.
     *
     * @param  list<int>  $raw
     * @return list<int>
     */
    public static function normalizeLimitsBatch(array $raw): array
    {
        return self::usesW52RustFfi()
            ? self::normalizeLimitsBatchNative($raw)
            : self::normalizeLimitsBatchPhp($raw);
    }

    /**
     * @param  list<int>  $raw
     * @return list<int>
     */
    public static function normalizeLimitsBatchNative(array $raw): array
    {
        self::w52AssertSnapshots($raw);
        if ($raw === []) {
            return [];
        }

        return self::w52Call('', pack('P*', ...$raw), intdiv(\count($raw), self::LIMIT_COUNT))
            ?? self::normalizeLimitsBatchPhp($raw);
    }

    /**
     * @param  list<int>  $raw
     * @return list<int>
     */
    public static function normalizeLimitsBatchPhp(array $raw): array
    {
        self::w52AssertSnapshots($raw);
        $out = [];
        foreach (array_chunk($raw, self::LIMIT_COUNT) as $snapshot) {
            $mask = 0;
            foreach (self::normalizeLimitsPhp($snapshot) as $index => $limit) {
                $out[] = $limit;
                if ($limit !== $snapshot[$index]) {
                    $mask |= 1 << $index;
                }
            }
            $out[] = $mask;
        }

        return $out;
    }

    /** @param array<array-key, mixed> $raw */
    private static function w52AssertSnapshots(array $raw): void
    {
        self::w52AssertList($raw);
        if (\count($raw) % self::LIMIT_COUNT !== 0) {
            throw new \InvalidArgumentException('Expected a multiple of '.self::LIMIT_COUNT.' configuration limits.');
        }
    }

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W52 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('enabled');
        self::w52RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: encoded text and packed int64 columns in, 15 int64
     * words back per record. Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w52Call(string $text, string $ints, int $count): ?array
    {
        try {
            $state = self::w52RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w52', self::W52_FFI_CDEF) ?? false;
                self::w52RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * 15;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w52RustFfiState('out', $out);
                self::w52RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w524_limit_snapshot_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function w52AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W52 batch input must be a list.');
        }
    }

    /**
     * W52 routing override and cached binding. Readonly classes cannot declare static
     * properties, so they live in a function static: no arguments returns the state, one
     * argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }

    // ---- Wave 64.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 12.60x/10.16x, uncommon 12.77x/11.13x); see RustAdditionalConfigConsoleBatchResultWorkPlanArchiveCandidateW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    private const string W64_PROCESSING_CONFIGURATION_FFI_CDEF = <<<'C'
        intptr_t nntmux_w642_processing_config_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W64_PROCESSING_CONFIGURATION_TAIL = 44;

    /**
     * Wave 64 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w64', self::W64_PROCESSING_CONFIGURATION_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w64RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w64ProcessingConfigPlanBatch() to Rust until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Routes w64ProcessingConfigPlanBatch() to PHP until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores W64_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    public static function usesW64RustFfi(): bool
    {
        $enabled = self::w64RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W64_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w64ProcessingConfigPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
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
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64ProcessingConfigPlanBatch(array $rows): array
    {
        return self::usesW64RustFfi() ? self::w64ProcessingConfigPlanBatchNative($rows) : self::w64ProcessingConfigPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64ProcessingConfigPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W64_PROCESSING_CONFIGURATION_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w64RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w64ProcessingConfigPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W64_PROCESSING_CONFIGURATION_FFI_CDEF.)
            $written = $ffi->nntmux_w642_processing_config_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w64ProcessingConfigPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w64Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w64Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Defaults, lower and upper bounds of the six limits w64ProcessingConfigPlanBatch() reads. */
    private const array W64_CONFIG_DEFAULTS = [25, 2, 3, 1, 0, 120];

    private const array W64_CONFIG_MIN = [1, 1, 1, 1, 0, 1];

    private const array W64_CONFIG_MAX = [1000, 50, 100, 10, 86_400, 86_400];

    /** Feature flag => bit; the first three need a usable ffmpeg path. */
    private const array W64_CONFIG_FEATURES = ['video' => 0, 'thumbnails' => 1, 'audio' => 2, 'jpg' => 3, 'mediainfo' => 4, 'passwords' => 5, 'par2' => 6, 'debug' => 7];

    /** Bytes that make a tool path unusable in a shell-quoted kill string. */
    private const string W64_PATH_INVALID = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f\x22";

    /** strlen() of killStringPhp()'s fixed parts around the path and seconds. */
    private const int W64_KILL_FIXED = 32;

    /** Tool path kind: 0 empty, 1 absolute, 2 relative, 3 invalid (control byte, DEL or `"`). */
    private static function w64PathKind(string $path): int
    {
        if ($path === '') {
            return 0;
        }
        if (strcspn($path, self::W64_PATH_INVALID) !== \strlen($path)) {
            return 3;
        }

        return $path[0] === '/' ? 1 : 2;
    }

    /**
     * Wave 64.2 configuration plan. Per `query_limit\tsegments\tmax_rar_segments\tpassword_checks\t
     * timeout_seconds\trelease_timeout\ttimeout_path\tffmpeg_path\tfeatures` row `query | segments
     * << 11 | max_rar << 17 | password_checks << 24 | defaulted << 28 | clamped << 34 | invalid <<
     * 40 | timeout_path_kind << 46 | ffmpeg_path_kind << 48 | effective_features << 50 |
     * kill_active << 58 | min(unknown_features, 15) << 59`. Limits are w64Int() fields (9 digits):
     * an unparsable one sets its invalid bit, an empty one (or 0, except timeout_seconds) its
     * defaulted bit, either takes W64_CONFIG_DEFAULTS; the value is then clamped to
     * W64_CONFIG_MIN/MAX, setting its clamped bit when that changes it. Paths are trimmed (see
     * w64PathKind()). Features are comma-separated, trimmed, case-insensitive W64_CONFIG_FEATURES
     * names; video, thumbnails and audio need an absolute or relative ffmpeg path. The kill string
     * is active for a usable timeout path and positive seconds; its length is
     * strlen(killStringPhp()) for the trimmed path, else 1. Then six defaulted, six clamped and
     * six invalid counts per limit, four timeout and four ffmpeg path kind counts, eight effective
     * feature counts, the rows that lost features to a missing ffmpeg, active kill strings, total
     * kill string bytes, total query limit, the most segments, total timeout seconds, the longest
     * release timeout, unknown features, distinct usable ffmpeg paths (by crc32) and the XXH64
     * digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w64ProcessingConfigPlanBatchPhp(array $rows): array
    {
        $out = $ffmpegPaths = [];
        $defaultedCounts = $clampedCounts = $invalidCounts = array_fill(0, 6, 0);
        $timeoutKinds = $ffmpegKinds = array_fill(0, 4, 0);
        $features = array_fill(0, 8, 0);
        $disabled = $killRows = $killBytes = $queryTotal = $maxSegments = $timeoutTotal = $maxRelease = $unknownTotal = 0;
        foreach ($rows as $row) {
            $fields = explode("\t", (string) $row, 9) + array_fill(0, 9, '');
            $values = [];
            $defaulted = $clamped = $invalid = 0;
            for ($k = 0; $k < 6; $k++) {
                $parsed = self::w64Int($fields[$k], 9);
                if ($parsed === false) {
                    $invalid |= 1 << $k;
                    $value = self::W64_CONFIG_DEFAULTS[$k];
                } elseif ($parsed === null || ($parsed === 0 && $k !== 4)) {
                    $defaulted |= 1 << $k;
                    $value = self::W64_CONFIG_DEFAULTS[$k];
                } else {
                    $value = $parsed;
                }
                $limit = min(self::W64_CONFIG_MAX[$k], max(self::W64_CONFIG_MIN[$k], $value));
                if ($limit !== $value) {
                    $clamped |= 1 << $k;
                }
                $values[$k] = $limit;
                $defaultedCounts[$k] += $defaulted >> $k & 1;
                $clampedCounts[$k] += $clamped >> $k & 1;
                $invalidCounts[$k] += $invalid >> $k & 1;
            }
            $timeoutPath = trim($fields[6]);
            $ffmpegPath = trim($fields[7]);
            $timeoutKind = self::w64PathKind($timeoutPath);
            $ffmpegKind = self::w64PathKind($ffmpegPath);
            $requested = $unknown = 0;
            foreach (explode(',', $fields[8]) as $token) {
                $token = strtolower(trim($token));
                if ($token === '') {
                    continue;
                }
                $bit = self::W64_CONFIG_FEATURES[$token] ?? null;
                if ($bit === null) {
                    $unknown++;
                } else {
                    $requested |= 1 << $bit;
                }
            }
            $ffmpeg = $ffmpegKind === 1 || $ffmpegKind === 2;
            $effective = $ffmpeg ? $requested : $requested & ~7;
            $active = ($timeoutKind === 1 || $timeoutKind === 2) && $values[4] > 0;
            $killLength = $active ? self::W64_KILL_FIXED + \strlen($timeoutPath) + \strlen((string) $values[4]) : 1;
            $timeoutKinds[$timeoutKind]++;
            $ffmpegKinds[$ffmpegKind]++;
            for ($k = 0; $k < 8; $k++) {
                $features[$k] += $effective >> $k & 1;
            }
            if (($requested & 7) !== 0 && ! $ffmpeg) {
                $disabled++;
            }
            $killRows += (int) $active;
            $killBytes += $killLength;
            $queryTotal += $values[0];
            $maxSegments = max($maxSegments, $values[1]);
            $timeoutTotal += $values[4];
            $maxRelease = max($maxRelease, $values[5]);
            $unknownTotal += $unknown;
            if ($ffmpeg) {
                $ffmpegPaths[crc32($ffmpegPath)] = true;
            }
            $out[] = $values[0] | $values[1] << 11 | $values[2] << 17 | $values[3] << 24 | $defaulted << 28 | $clamped << 34 | $invalid << 40
                | $timeoutKind << 46 | $ffmpegKind << 48 | $effective << 50 | (int) $active << 58 | min($unknown, 15) << 59;
        }
        array_push($out, ...$defaultedCounts, ...$clampedCounts, ...$invalidCounts, ...$timeoutKinds, ...$ffmpegKinds, ...$features);
        array_push($out, $disabled, $killRows, $killBytes, $queryTotal, $maxSegments, $timeoutTotal, $maxRelease, $unknownTotal, \count($ffmpegPaths));
        $out[] = self::w64Digest($out);

        return $out;
    }
}
