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
use InvalidArgumentException;
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

    // ---------------------------------------------------------------------
    // Wave 52.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.53x, p99 1.91x; uncommon mean 2.17x, p99 1.80x); see RustWorkPlannerArchiveConfigConsoleMediaExtractW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        ssize_t nntmux_w524_audio_tag_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** audioTagBatch() flags; valid is exclusive. */
    public const int W52_TAG_VALID = 1;

    public const int W52_TAG_EMPTY = 2;

    public const int W52_TAG_INVALID_UTF8 = 4;

    public const int W52_TAG_CONTROL = 8;

    /**
     * Validate MediaInfo tag strings, four ints each: [flags, trimmed offset << 32 | length,
     * UTF-8 characters in the trimmed text or -1 when it is invalid, first `(?:19|20)\d\d`
     * year in the value (as audioReleaseTitlePhp() reads a date) or -1]. Flags: 1 valid
     * (audioTagTextPhp() returns the trimmed text), otherwise 2 empty after trim(), 4 invalid
     * UTF-8 and 8 control byte (0x00-0x1f, 0x7f) in the trimmed text.
     *
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function audioTagBatch(array $values): array
    {
        return self::usesW52RustFfi()
            ? self::audioTagBatchNative($values)
            : self::audioTagBatchPhp($values);
    }

    /**
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function audioTagBatchNative(array $values): array
    {
        self::w52AssertList($values);
        if ($values === []) {
            return [];
        }

        [$text, $twin] = self::w52Text($values);

        return self::w52Call($text, $twin, \count($values))
            ?? self::audioTagBatchPhp($values);
    }

    /**
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function audioTagBatchPhp(array $values): array
    {
        self::w52AssertList($values);
        $out = [];
        foreach ($values as $value) {
            $text = trim($value);
            $utf8 = mb_check_encoding($text, 'UTF-8');
            $flags = ($text === '' ? self::W52_TAG_EMPTY : 0)
                | ($utf8 ? 0 : self::W52_TAG_INVALID_UTF8)
                | (preg_match('/[\x00-\x1f\x7f]/', $text) === 1 ? self::W52_TAG_CONTROL : 0);
            $out[] = $flags === 0 ? self::W52_TAG_VALID : $flags;
            $out[] = (strspn($value, " \t\n\r\0\x0B") << 32) | \strlen($text);
            $out[] = $utf8 ? mb_strlen($text, 'UTF-8') : -1;
            $out[] = preg_match('/(?:19|20)\d\d/', $value, $year) === 1 ? (int) $year[0] : -1;
        }

        return $out;
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
     * One FFI crossing for the batch: encoded text and packed int64 columns in, 4 int64
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
            $need = $count * 4;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w52RustFfiState('out', $out);
                self::w52RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w524_audio_tag_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Text column encoding: NUL-joined values and, when a value holds a NUL byte, the same
     * values joined with 0x01 so Rust finds the separators where the two buffers differ.
     *
     * @param  list<string>  $values
     * @return array{0: string, 1: string}
     */
    private static function w52Text(array $values): array
    {
        $joined = implode("\0", $values);

        return [$joined, substr_count($joined, "\0") === \count($values) - 1 ? '' : implode("\x01", $values)];
    }

    /** @param array<array-key, mixed> $values */
    private static function w52AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('W52 batch input must be a list.');
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

    // ========================================================================
    // WAVE 65.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 10.97x, p99 8.63x; uncommon mean 10.25x, p99 7.97x); see RustAdditionalMediaStageToolPar2MapperThumbExtractionW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_MEDIA_EXTRACTION_FFI_CDEF = <<<'C'
        ssize_t nntmux_w651_media_extraction_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w65MediaExtractionPlanBatch(). */
    public const int W65_MEDIA_EXTRACTION_TRAILER = 53;

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /** Forces the MediaExtractionService Wave 65 Rust path on until reset. */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /** Forces the MediaExtractionService Wave 65 PHP path until reset. */
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
     * Runs $callback with the MediaExtractionService Wave 65 Rust path forced on or off, then restores the previous setting.
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
     * Wave 65.1 media extraction plans, one per release `category|kinds|durationMs|width|height|codec|done|bytes` (trimmed
     * fields; numbers are 1-15 digits, empty 0). Kinds letters (case-insensitive): v video, a audio, s sample, j jpg, m
     * mediainfo. Stages: 1 video clip (movie, TV and XXX groups), 2 audio (audio group or no video), 4 mediainfo, 8 sample
     * thumbnail, 16 jpg, 32 video thumbnail (video without jpg); pending is requested minus the 0-63 `done` mask. Codec: 0
     * empty, 1-14 h264, hevc, h265, av1, mpeg2, mpeg4, vc1, vp9, aac, mp3, flac, ac3, dts, opus, 15 other. Outcome: 0 empty
     * row, 1 malformed (bad number, unknown kind letter, done over 63), 2 nothing requested, 3 already complete, 4 probe
     * required (pending video work without a duration), 5 shorter than one second, 6 over 50 GiB, 7 run. Tier: 0 unknown, 1
     * SD, 2 720p, 3 1080p, 4 2160p. First stage (in order video, sample, jpg, thumbnail, audio, mediainfo), 1-based or 0.
     * Flags: 1 unknown codec, 2 audio codec on video, 4 portrait, 8 non-ASCII, 16 control byte, 32 three hours or longer, 64
     * dimension under 64, 128 no category. Per item `outcome | requested << 3 | pending << 9 | min(category / 1000, 15) << 15 |
     * codec << 19 | tier << 23 | first << 26 | flags << 29 | bitLength(duration) << 37 | bitLength(bytes) << 43 |
     * popcount(pending) << 49 | done << 52`; then 8 outcome, 6 requested, 6 pending, 5 tier, 8 flag and 16 codec counts, the
     * duration and byte sums of runnable releases, the longest duration and the distinct codec names.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaExtractionPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65MediaExtractionPlanBatchNative($items) : self::w65MediaExtractionPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaExtractionPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_MEDIA_EXTRACTION_TRAILER) ?? self::w65MediaExtractionPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65MediaExtractionPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W65_MEDIA_EXTRACTION_TRAILER, 0);
        $codecs = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawCategory, $rawKinds, $rawDuration, $rawWidth, $rawHeight, $rawCodec, $rawDone, $rawBytes] = explode('|', $item, 8)
                + ['', '', '', '', '', '', '', ''];
            $category = self::w65Uint($rawCategory, 0);
            $kinds = 0;
            $badKind = false;
            $kindText = strtolower(trim($rawKinds));
            for ($i = 0, $n = \strlen($kindText); $i < $n; $i++) {
                $bit = self::W65_MEDIA_KINDS[$kindText[$i]] ?? 0;
                if ($bit === 0) {
                    $badKind = true;
                } else {
                    $kinds |= $bit;
                }
            }
            $duration = self::w65Uint($rawDuration, 0);
            $width = self::w65Uint($rawWidth, 0);
            $height = self::w65Uint($rawHeight, 0);
            $codecName = strtolower(trim($rawCodec));
            $codec = $codecName === '' ? 0 : (self::W65_CODECS[$codecName] ?? 15);
            $done = self::w65Uint($rawDone, 0);
            $bytes = self::w65Uint($rawBytes, 0);
            $malformed = $category < 0 || $badKind || $duration < 0 || $width < 0 || $height < 0 || $done < 0 || $done > 63 || $bytes < 0;
            $group = $category > 0 ? intdiv($category, 1000) : 0;
            $video = ($kinds & 1) !== 0;
            $requested = 0;
            if ($video) {
                $requested |= ($group === 2 || $group === 5 || $group === 6 ? 1 : 0) | 4 | (($kinds & 8) === 0 ? 32 : 0);
            }
            if (($kinds & 2) !== 0) {
                $requested |= ($group === 3 || ! $video ? 2 : 0) | 4;
            }
            $requested |= (($kinds & 4) !== 0 ? 8 : 0) | (($kinds & 8) !== 0 ? 16 : 0) | (($kinds & 16) !== 0 ? 4 : 0);
            $doneMask = $done >= 0 && $done <= 63 ? $done : 0;
            $pending = $requested & ~$doneMask;
            $outcome = match (true) {
                trim($item) === '' => 0,
                $malformed => 1,
                $requested === 0 => 2,
                $pending === 0 => 3,
                ($pending & (1 | 32)) !== 0 && $duration === 0 => 4,
                $duration > 0 && $duration < 1000 && ($pending & (1 | 2 | 32)) !== 0 => 5,
                $bytes > 50 << 30 => 6,
                default => 7,
            };
            $width = max(0, $width);
            $height = max(0, $height);
            $tier = match (true) {
                $width === 0 || $height === 0 => 0,
                $height < 720 => 1,
                $height < 1080 => 2,
                $height < 2160 => 3,
                default => 4,
            };
            $first = 0;
            foreach ([1, 8, 16, 32, 2, 4] as $slot => $stage) {
                if (($pending & $stage) !== 0) {
                    $first = $slot + 1;
                    break;
                }
            }
            $flags = ($codec === 15 ? 1 : 0) | ($video && $codec >= 9 && $codec <= 14 ? 2 : 0) | ($height > $width ? 4 : 0)
                | (preg_match('/[\x80-\xff]/', $item) === 1 ? 8 : 0) | (strcspn($item, self::W65_CONTROL_BYTES) !== \strlen($item) ? 16 : 0)
                | ($duration >= 10_800_000 ? 32 : 0) | (($width > 0 && $width < 64) || ($height > 0 && $height < 64) ? 64 : 0) | ($category === 0 ? 128 : 0);
            $tail[$outcome]++;
            for ($b = 0; $b < 6; $b++) {
                $tail[8 + $b] += $requested >> $b & 1;
                $tail[14 + $b] += $pending >> $b & 1;
            }
            $tail[20 + $tier]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[25 + $b] += $flags >> $b & 1;
            }
            $tail[33 + $codec]++;
            if ($outcome === 7) {
                $tail[49] += max(0, min($duration, 1 << 40));
                $tail[50] += max(0, min($bytes, 1 << 40));
            }
            $tail[51] = max($tail[51], $duration);
            if ($codecName !== '') {
                $codecs[$codecName] = true;
            }
            $out[] = $outcome | $requested << 3 | $pending << 9 | max(0, min($group, 15)) << 15 | $codec << 19 | $tier << 23 | $first << 26
                | $flags << 29 | self::w65BitLength($duration) << 37 | self::w65BitLength($bytes) << 43
                | substr_count(decbin($pending), '1') << 49 | $doneMask << 52;
        }
        $tail[52] = \count($codecs);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, int> */
    private const array W65_MEDIA_KINDS = ['v' => 1, 'a' => 2, 's' => 4, 'j' => 8, 'm' => 16];

    /** @var array<string, int> */
    private const array W65_CODECS = ['h264' => 1, 'hevc' => 2, 'h265' => 3, 'av1' => 4, 'mpeg2' => 5, 'mpeg4' => 6, 'vc1' => 7, 'vp9' => 8, 'aac' => 9,
        'mp3' => 10, 'flac' => 11, 'ac3' => 12, 'dts' => 13, 'opus' => 14];

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
            $ffi ??= RustPipelineLibrary::resolveDomain('media_extraction_w651', self::W65_MEDIA_EXTRACTION_FFI_CDEF) ?? false;

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
     * Calls the Wave 65.1 MediaExtractionService export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W65_MEDIA_EXTRACTION_FFI_CDEF.)
            $written = $ffi->nntmux_w651_media_extraction_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }
}
