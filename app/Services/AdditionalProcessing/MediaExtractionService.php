<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Enums\ImageAssetProfile;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Services\AdditionalProcessing\Media\FfmpegFrameGrabber;
use App\Services\AdditionalProcessing\Media\FrameQualityScorer;
use App\Services\AdditionalProcessing\Media\ImageInspector;
use App\Services\AdditionalProcessing\Media\MediaToolFactory;
use App\Services\AdditionalProcessing\Media\ThumbnailFrameSelector;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\ReleaseExtraService;
use App\Services\ReleaseImageService;
use App\Support\Par2FileHashEvidence;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Format\Video\Ogg;
use FFMpeg\Media\Video;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mhor\MediaInfo\MediaInfo;

/**
 * Service for processing media files (video, audio, images).
 * Handles sample generation, thumbnails, media info extraction, and audio processing.
 */
class MediaExtractionService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_rfm_audio_tag_span(const char *ptr, size_t len, size_t *out);
        ssize_t nntmux_rfm_audio_release_title(const char *performer_ptr, size_t performer_len, const char *album_ptr, size_t album_len, const char *date_ptr, size_t date_len, int32_t has_date, const char *ext_ptr, size_t ext_len, char *out_ptr, size_t out_cap);
        C;

    /** Wave 40.6 paired benchmarks decide this default; see RustArchiveExtractionMediaUsenetDownloadReleaseProcW40ParityTest. */
    public const bool W406_RUST_FFI_DEFAULT = false;

    /** Resolution tiers indexed by the Wave 40.6 export's tier code. */
    private const array W406_RESOLUTION_TIERS = ['sd', 'hd_720', 'fhd_1080', 'uhd_4k', 'invalid'];

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    private static ?bool $w406UseRustFfi = null;

    private static ?CData $audioTagSpan = null;

    private readonly MediaToolFactory $tools;

    private readonly ThumbnailFrameSelector $frameSelector;

    private readonly ImageInspector $imageInspector;

    private ?MediaInfo $mediaInfo = null;

    private readonly ReleaseSearchSyncCoordinator $searchSyncCoordinator;

    private ?ReleaseUpdateService $releaseUpdater;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly ReleaseImageService $releaseImage,
        private readonly ReleaseExtraService $releaseExtra,
        private readonly CategorizationService $categorize,
        ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
        ?MediaToolFactory $tools = null,
        ?ThumbnailFrameSelector $frameSelector = null,

        ?ReleaseUpdateService $releaseUpdater = null,
    ) {
        $this->searchSyncCoordinator = $searchSyncCoordinator
            ?? new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
            );

        $this->releaseUpdater = $releaseUpdater;
        $this->tools = $tools ?? new MediaToolFactory($config);
        $scorer = new FrameQualityScorer($config->thumbnailMinStddev, $config->thumbnailMinLuma, $config->thumbnailMaxLuma);
        $this->frameSelector = $frameSelector ?? new ThumbnailFrameSelector(
            $config,
            new FfmpegFrameGrabber($this->tools, $config->debugMode),
            $scorer,
        );
        $this->imageInspector = new ImageInspector($scorer, $config->thumbnailMinStddev);
    }

    /**
     * Read a file's duration in seconds from its container, or from its first video stream.
     */
    public function getMediaDuration(string $videoLocation): ?float
    {
        try {
            if (! $this->ffprobe()->isValid($videoLocation)) {
                return null;
            }

            $duration = $this->ffprobe()->format($videoLocation)->get('duration');
            if (! is_numeric($duration) || (float) $duration <= 0) {
                $duration = $this->ffprobe()->streams($videoLocation)->videos()->first()?->get('duration');
            }
        } catch (\Throwable $e) {
            if ($this->config->debugMode) {
                Log::debug($e->getMessage());
            }

            return null;
        }

        return is_numeric($duration) && (float) $duration > 0 ? (float) $duration : null;
    }

    /**
     * Extract a preview image from a video file, skipping blank frames.
     */
    public function getSample(string $fileLocation, string $tmpPath, string $guid, ?MediaSourceHints $hints = null): bool
    {
        if (! $this->config->processThumbnails || ! File::isFile($fileLocation)) {
            return false;
        }

        $workDirectory = $tmpPath.'frames_'.bin2hex(random_bytes(6)).'/';

        try {
            File::ensureDirectoryExists($workDirectory);
            $frame = $this->frameSelector->select($fileLocation, $workDirectory, $hints);
            if ($frame === null) {
                return false;
            }

            return $this->releaseImage->saveLocalImage(
                $guid.'_thumb',
                $frame,
                $this->releaseImage->imgSavePath,
                ImageAssetProfile::Preview,
            )->success;
        } catch (\Throwable $e) {
            if ($this->config->debugMode) {
                Log::error($e->getTraceAsString());
            }

            return false;
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    /**
     * Create a video sample clip.
     *
     * A complete file is clipped from 30% in; a partial download from its start,
     * because only the start is known to be there.
     */
    public function getVideo(string $fileLocation, string $tmpPath, string $guid, ?MediaSourceHints $hints = null): bool
    {
        if (! $this->config->processVideo || ! File::isFile($fileLocation) || $hints?->position === SourcePosition::Mid) {
            return false;
        }

        $fileName = $tmpPath.'zzzz'.$guid.'.ogv';

        try {
            if ($this->ffprobe()->isValid($fileLocation)) {
                $start = 0.0;
                if ($hints?->position === SourcePosition::Complete) {
                    $duration = $this->getMediaDuration($fileLocation) ?? 0.0;
                    $start = max(0.0, min($duration * 0.3, $duration - $this->config->ffmpegDuration));
                }

                /** @var Video $video */
                $video = $this->ffmpeg()->open($fileLocation);
                $clip = $video->clip(
                    TimeCode::fromSeconds($start),
                    TimeCode::fromSeconds($this->config->ffmpegDuration)
                );
                $format = new Ogg;
                $format->setAudioCodec('libvorbis');
                $clip->filters()->resize(new Dimension(320, -1), ResizeFilter::RESIZEMODE_SCALE_HEIGHT);
                $clip->save($format, $fileName);
            }
        } catch (\Throwable $e) {
            if ($this->config->debugMode) {
                Log::error($e->getTraceAsString());
            }
        }

        if (! File::isFile($fileName)) {
            return false;
        }

        $newFile = $this->releaseImage->vidSavePath.$guid.'.ogv';

        if (! @File::move($fileName, $newFile)) {
            $copied = @File::copy($fileName, $newFile);
            File::delete($fileName);
            if (! $copied) {
                return false;
            }
        }

        @chmod($newFile, 0764);
        Release::query()->where('guid', $guid)->update(['videostatus' => 1]);

        return true;
    }

    /**
     * Extract media info from a video file.
     */
    public function getMediaInfo(string $fileLocation, int $releaseId): bool
    {
        if (! $this->config->processMediaInfo || ! File::isFile($fileLocation)) {
            return false;
        }

        try {
            $xmlArray = $this->mediaInfo()->getInfo($fileLocation, true);
            \App\Models\MediaInfo::addData($releaseId, $xmlArray);
            $this->releaseExtra->addFromXml($releaseId, $xmlArray);

            return true;
        } catch (\Throwable $e) {
            Log::debug($e->getMessage());

            return false;
        }
    }

    /**
     * Process a JPG sample image.
     */
    public function getJPGSample(string $fileLocation, string $guid): bool
    {
        $saved = $this->releaseImage->saveLocalImage(
            $guid.'_thumb',
            $fileLocation,
            $this->releaseImage->jpgSavePath,
            ImageAssetProfile::Sample,
        );

        if ($saved->success) {
            Release::query()->where('guid', $guid)->update(['jpgstatus' => 1]);

            return true;
        }

        return false;
    }

    /**
     * Process audio file for media info and sample.
     *
     * @return array{audioInfo: bool, audioSample: bool}
     */
    public function getAudioInfo(
        string $fileLocation,
        string $fileExtension,
        ReleaseProcessingContext $context,
        string $tmpPath
    ): array {
        $result = ['audioInfo' => false, 'audioSample' => false];

        if (! $this->config->processAudioSample) {
            $result['audioSample'] = true;
        }
        if (! $this->config->processAudioInfo) {
            $result['audioInfo'] = true;
        }

        $snapshot = $context->fullPar2EvidenceSnapshot();
        $rQuery = DB::table('releases')->where('id', $snapshot['id'])->first();
        if (! Par2FileHashEvidence::snapshotMatches($rQuery, $snapshot)
            || ! $this->ownsAudioClaim($context, $snapshot)) {
            $context->fullPar2EvidenceDeferred = true;

            return $result;
        }
        if ((int) ($rQuery->proc_pp ?? 0) !== 0) {
            return $result;
        }
        $snapshot['additional_pp_claimed_at'] = $rQuery->additional_pp_claimed_at ?? null;

        $musicParent = (string) Category::MUSIC_ROOT;
        if ($rQuery === null || ! preg_match(
            sprintf(
                '/%d\d{3}|%d|%d|%d/',
                $musicParent[0],
                Category::OTHER_MISC,
                Category::MOVIE_OTHER,
                Category::TV_OTHER
            ),
            (string) $rQuery->categories_id
        )) {
            return $result;
        }

        if (! File::isFile($fileLocation)) {
            return $result;
        }

        // Get media info
        if (! $result['audioInfo']) {
            $aborted = new \RuntimeException('Audio metadata ownership changed.');
            $audioAdmissionGeneration = $context->audioMetadataAdmissionGeneration;
            try {
                $xmlArray = $this->mediaInfo()->getInfo($fileLocation, false);
                $this->releaseUpdater ??= new ReleaseUpdateService($this->categorize, searchSyncCoordinator: $this->searchSyncCoordinator);
                foreach ($xmlArray->getAudios() as $track) {
                    $album = $this->audioTagText($track->get('album'));
                    $performer = $this->audioTagText($track->get('performer'));
                    if ($album === null || $performer === null) {
                        continue;
                    }
                    $renamed = DB::transaction(function () use ($context, $snapshot, $xmlArray, $track, $album, $performer, $fileExtension, $aborted, $audioAdmissionGeneration): bool {
                        $current = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()->first();
                        if (! Par2FileHashEvidence::snapshotMatches($current, $snapshot)
                            || ! $this->ownsAudioClaim($context, $snapshot)
                            || (int) ($current->proc_pp ?? 0) !== 0) {
                            throw $aborted;
                        }
                        DB::afterCommit(static function () use ($context, $audioAdmissionGeneration): void {
                            if ($context->audioMetadataAdmissionGeneration === $audioAdmissionGeneration) {
                                $context->audioMetadataCommitted = true;
                            }
                        });
                        $expected = [...$snapshot, 'predb_id' => (int) $current->predb_id, 'proc_pp' => 0];
                        $renamed = false;
                        if ((int) $current->predb_id === 0 && $this->config->renameMusicMediaInfo) {
                            $ext = strtoupper($fileExtension);
                            $title = self::audioReleaseTitle($performer, $album, $this->audioTagText($track->get('recorded_date')), $fileExtension);
                            if ($title !== null) {
                                $category = match ($ext) {
                                    'MP3' => Category::MUSIC_MP3,
                                    'FLAC' => Category::MUSIC_LOSSLESS,
                                    default => $this->categorize->determineCategory((int) $current->groups_id, $title, $current->fromname),
                                };
                                $categoryId = is_array($category) ? (int) $category['categories_id'] : $category;
                                if (! $this->releaseUpdater->updateMediaSnapshot(
                                    (object) [...$snapshot, 'releases_id' => $snapshot['id']], $title, $categoryId,
                                    fn (): bool => $this->ownsAudioClaim($context, $snapshot),
                                )) {
                                    throw $aborted;
                                }
                                $expected['searchname'] = $title;
                                $expected['proc_pp'] = 1;
                                $renamed = true;
                            }

                        }
                        $this->releaseExtra->addFromXml($snapshot['id'], $xmlArray);
                        if (! Par2FileHashEvidence::snapshotMatches(DB::table('releases')->where('id', $snapshot['id'])->first(), $expected)
                            || ! $this->ownsAudioClaim($context, $snapshot)) {
                            throw $aborted;
                        }

                        return $renamed;
                    });
                    if ($renamed && ! $context->advanceFullPar2EvidenceIdentity($this->releaseUpdater->lastCommittedIdentity())) {
                        $context->fullPar2EvidenceDeferred = true;

                        return $result;
                    }
                    $result['audioInfo'] = true;
                    $context->foundAudioInfo = true;
                    break;
                }
            } catch (\Throwable $e) {
                if ($e === $aborted || ! Par2FileHashEvidence::snapshotMatches(DB::table('releases')->where('id', $snapshot['id'])->first(), $snapshot)
                    || ! $this->ownsAudioClaim($context, $snapshot)) {
                    $context->fullPar2EvidenceDeferred = true;
                }
                Log::debug($e->getMessage());
                if ($context->fullPar2EvidenceDeferred) {
                    return $result;
                }
            }
        }

        // Create audio sample
        if (! $result['audioSample']) {
            $audioFileName = $context->release->guid.'.ogg';

            try {
                if ($this->ffprobe()->isValid($fileLocation)) {
                    $this->saveAudioClip($fileLocation, $tmpPath.$audioFileName);
                }
            } catch (\Throwable $e) {
                if ($this->config->debugMode) {
                    Log::error($e->getTraceAsString());
                }
            }

            if (File::isFile($tmpPath.$audioFileName)) {
                $renamed = File::move($tmpPath.$audioFileName, $this->config->audioSavePath.$audioFileName);
                if (! $renamed) {
                    $copied = File::copy($tmpPath.$audioFileName, $this->config->audioSavePath.$audioFileName);
                    File::delete($tmpPath.$audioFileName);
                    if (! $copied) {
                        return $result;
                    }
                }

                @chmod($this->config->audioSavePath.$audioFileName, 0764);
                $result['audioSample'] = true;
                $context->foundAudioSample = true;
            }
        }

        return $result;
    }

    /**
     * Retains the native PHP path in production (~0.50x-0.61x mean in paired benchmarks);
     * see audioTagTextNative().
     */
    private function audioTagText(mixed $value): ?string
    {
        return self::audioTagTextPhp($value);
    }

    public static function audioTagTextPhp(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! $value instanceof \Stringable) {
            return null;
        }
        $text = trim((string) $value);

        return $text !== '' && mb_check_encoding($text, 'UTF-8') && ! preg_match('/[\x00-\x1f\x7f]/', $text) ? $text : null;
    }

    public static function audioTagTextNative(mixed $value, bool &$supported = false, ?string $libraryPath = null): ?string
    {
        $supported = false;
        if (! is_string($value)) {
            return self::audioTagTextPhp($value);
        }
        $ffi = self::rustFfi($libraryPath);
        if ($ffi === null) {
            return self::audioTagTextPhp($value);
        }

        try {
            $span = self::$audioTagSpan ??= $ffi->new('size_t[2]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_rfm_audio_tag_span($value, strlen($value), $span);
        } catch (\Throwable) {
            return self::audioTagTextPhp($value);
        }

        if (! is_int($status) || $status < 0) {
            return self::audioTagTextPhp($value);
        }
        $supported = true;

        return $status === 1 ? substr($value, (int) $span[0], (int) $span[1]) : null;
    }

    /**
     * Build the MediaInfo music title, or null when it is not a valid searchname.
     *
     * Retains the native PHP path in production (~0.73x-0.76x mean in paired benchmarks);
     * see audioReleaseTitleNative().
     */
    public static function audioReleaseTitle(string $performer, string $album, ?string $date, string $fileExtension): ?string
    {
        return self::audioReleaseTitlePhp($performer, $album, $date, $fileExtension);
    }

    public static function audioReleaseTitlePhp(string $performer, string $album, ?string $date, string $fileExtension): ?string
    {
        $title = $performer.' - '.$album;
        if ($date !== null && preg_match('/(?:19|20)\d\d/', $date, $year)) {
            $title .= ' ('.$year[0].')';
        }
        $title .= ' '.strtoupper($fileExtension);

        return mb_check_encoding($title, 'UTF-8') && mb_strlen($title, 'UTF-8') <= 255
            && preg_match('/\p{C}/u', $title) === 0 ? $title : null;
    }

    public static function audioReleaseTitleNative(
        string $performer,
        string $album,
        ?string $date,
        string $fileExtension,
        bool &$supported = false,
        ?string $libraryPath = null,
    ): ?string {
        $supported = false;
        $ffi = self::rustFfi($libraryPath);
        if ($ffi === null) {
            return self::audioReleaseTitlePhp($performer, $album, $date, $fileExtension);
        }

        $dateText = $date ?? '';
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return self::audioReleaseTitlePhp($performer, $album, $date, $fileExtension);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_rfm_audio_release_title(
                $performer,
                strlen($performer),
                $album,
                strlen($album),
                $dateText,
                strlen($dateText),
                $date === null ? 0 : 1,
                $fileExtension,
                strlen($fileExtension),
                $scratch,
                $cap,
            );
        } catch (\Throwable) {
            return self::audioReleaseTitlePhp($performer, $album, $date, $fileExtension);
        }

        if ($written === -3) {
            $supported = true;

            return null;
        }
        if (! is_int($written) || $written < 0) {
            return self::audioReleaseTitlePhp($performer, $album, $date, $fileExtension);
        }
        $supported = true;

        return FFI::string($scratch, $written);
    }

    /**
     * Toggle the Rust helpers; disabled, every *Native method falls back to PHP.
     */
    public static function useRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function useW406RustFfi(?bool $enabled): void
    {
        self::$w406UseRustFfi = $enabled;
    }

    public static function usesW406RustFfi(): bool
    {
        return self::$w406UseRustFfi ?? self::W406_RUST_FFI_DEFAULT;
    }

    public static function resetW406RustFfi(): void
    {
        self::$w406UseRustFfi = null;
    }

    /**
     * Classify probed media metrics. Non-positive dimensions are invalid; otherwise the tier is uhd_4k from 3840
     * wide or 2160 high, fhd_1080 from 1920 or 1080, hd_720 from 1280 or 720, else sd. A duration is valid within
     * 1 ms..24 h, six or more channels are surround, and a sample needs a valid duration of at least 10 s, valid
     * dimensions and a positive video bitrate.
     *
     * @return array{resolution_tier: string, valid_duration: bool, surround_audio: bool, sample_eligible: bool}
     */
    public static function summarizeMediaProbeMetrics(int $durationMs, int $width, int $height, int $videoBitrateKbps, int $audioChannels): array
    {
        return (self::usesW406RustFfi() ? self::summarizeMediaProbeMetricsNative($durationMs, $width, $height, $videoBitrateKbps, $audioChannels) : null)
            ?? self::summarizeMediaProbeMetricsPhp($durationMs, $width, $height, $videoBitrateKbps, $audioChannels);
    }

    /** @return array{resolution_tier: string, valid_duration: bool, surround_audio: bool, sample_eligible: bool} */
    public static function summarizeMediaProbeMetricsPhp(int $durationMs, int $width, int $height, int $videoBitrateKbps, int $audioChannels): array
    {
        $tier = match (true) {
            $width <= 0 || $height <= 0 => 'invalid',
            $width >= 3840 || $height >= 2160 => 'uhd_4k',
            $width >= 1920 || $height >= 1080 => 'fhd_1080',
            $width >= 1280 || $height >= 720 => 'hd_720',
            default => 'sd',
        };
        $validDuration = $durationMs > 0 && $durationMs <= 86_400_000;

        return [
            'resolution_tier' => $tier,
            'valid_duration' => $validDuration,
            'surround_audio' => $audioChannels >= 6,
            'sample_eligible' => $validDuration && $durationMs >= 10_000 && $tier !== 'invalid' && $videoBitrateKbps > 0,
        ];
    }

    /** @return array{resolution_tier: string, valid_duration: bool, surround_audio: bool, sample_eligible: bool}|null */
    public static function summarizeMediaProbeMetricsNative(int $durationMs, int $width, int $height, int $videoBitrateKbps, int $audioChannels): ?array
    {
        $slots = ArchiveExtractionService::w406Slots(2, pack('q5', $durationMs, $width, $height, $videoBitrateKbps, $audioChannels), 4);
        if ($slots === null || ! isset(self::W406_RESOLUTION_TIERS[$slots[0]]) || ($slots[1] & ~1) !== 0 || ($slots[2] & ~1) !== 0 || ($slots[3] & ~1) !== 0) {
            return null;
        }

        return [
            'resolution_tier' => self::W406_RESOLUTION_TIERS[$slots[0]],
            'valid_duration' => $slots[1] === 1,
            'surround_audio' => $slots[2] === 1,
            'sample_eligible' => $slots[3] === 1,
        ];
    }

    private static function rustFfi(?string $libraryPath = null): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('media_extraction', self::FFI_CDEF, $libraryPath);
        }
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('media_extraction', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     *
     * @phpstan-impure
     */
    private function ownsAudioClaim(ReleaseProcessingContext $context, array $snapshot): bool
    {
        if ((int) $context->release->id !== $snapshot['id'] || (string) $context->release->guid !== $snapshot['guid']) {
            return false;
        }
        $current = DB::table('releases')->where('id', $snapshot['id'])->first();
        if ($current === null || $current->guid !== $snapshot['guid']
            || ($current->additional_pp_claim_token ?? null) !== $snapshot['additional_pp_claim_token']) {
            return false;
        }
        $claimedAt = $current->additional_pp_claimed_at ?? null;
        if (array_key_exists('additional_pp_claimed_at', $snapshot) && $claimedAt !== $snapshot['additional_pp_claimed_at']) {
            return false;
        }
        if ($snapshot['additional_pp_claim_token'] === null) {
            return $claimedAt === null;
        }
        if (! is_string($claimedAt)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $claimedAt);

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $claimedAt
            && $claimedAt >= AdditionalCandidateQuery::claimStaleBefore()->format('Y-m-d H:i:s');
    }

    /**
     * Encode 30 seconds of a track as Ogg Vorbis, skipping the intro of anything over a minute.
     */
    public function saveAudioClip(string $fileLocation, string $outputFile): bool
    {
        $duration = $this->getMediaDuration($fileLocation) ?? 0.0;

        $this->ffmpeg()->getFFMpegDriver()->command([
            '-y',
            '-ss', $duration > 60 ? '30' : '0',
            '-t', '30',
            '-i', $fileLocation,
            '-vn',
            '-c:a', 'libvorbis',
            '-q:a', '4',
            $outputFile,
        ]);

        return File::isFile($outputFile) && File::size($outputFile) > 0;
    }

    /**
     * Process a video file for sample, video clip, and media info.
     *
     * @return array<string, mixed>
     */
    public function processVideoFile(
        string $fileLocation,
        ReleaseProcessingContext $context,
        string $tmpPath
    ): array {
        $result = [
            'sample' => false,
            'video' => false,
            'mediaInfo' => false,
        ];

        if (! $context->foundSample) {
            $result['sample'] = $this->getSample($fileLocation, $tmpPath, $context->release->guid);
            if ($result['sample']) {
                $context->foundSample = true;
            }
        }

        // Only get video if sampleMessageIDs count is less than 2
        if (! $context->foundVideo && count($context->sampleMessageIDs) < 2) {
            $result['video'] = $this->getVideo($fileLocation, $tmpPath, $context->release->guid);
            if ($result['video']) {
                $context->foundVideo = true;
            }
        }

        if (! $context->foundMediaInfo) {
            $result['mediaInfo'] = $this->getMediaInfo($fileLocation, $context->release->id);
            if ($result['mediaInfo']) {
                $context->foundMediaInfo = true;
            }
        }

        return $result;
    }

    /**
     * Check if data appears to be a JPEG image.
     */
    public function isJpegData(string $filePath): bool
    {
        if (! File::isFile($filePath)) {
            return false;
        }

        return exif_imagetype($filePath) === IMAGETYPE_JPEG;
    }

    /**
     * Whether an image is complete, big enough to show something, and not blank.
     */
    public function isUsableImage(string $filePath): bool
    {
        return File::isFile($filePath) && $this->imageInspector->isUsable($filePath);
    }

    /**
     * Check if file is a valid image (JPEG or PNG).
     */
    public function isValidImage(string $filePath): bool
    {
        if (! File::isFile($filePath)) {
            return false;
        }

        $type = @exif_imagetype($filePath);

        return in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }

    private function ffmpeg(): FFMpeg
    {
        return $this->tools->ffmpeg();
    }

    private function ffprobe(): FFProbe
    {
        return $this->tools->ffprobe();
    }

    private function mediaInfo(): MediaInfo
    {
        if ($this->mediaInfo === null) {
            $this->mediaInfo = new MediaInfo;
            $this->mediaInfo->setConfig('use_oldxml_mediainfo_output_format', true);
            if ($this->config->mediaInfoPath) {
                $this->mediaInfo->setConfig('command', $this->config->mediaInfoPath);
            }
        }

        return $this->mediaInfo;
    }
}
