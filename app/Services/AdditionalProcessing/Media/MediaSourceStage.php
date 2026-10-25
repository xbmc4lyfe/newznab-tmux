<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Last attempt at a preview for a release the regular downloads gave none.
 *
 * Sources, best first: a sample stored in a downloaded archive volume, the start
 * of a full-length video posted as it is, the start of a video stored in an
 * archive and, when enabled, a chunk from the middle of either. Every download
 * counts against the release's media byte budget and the stage's deadline.
 */
class MediaSourceStage
{
    public const bool W45_RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_media_middle_volume_index(const char *titles, size_t len, size_t count);
        int32_t nntmux_w45_source_has_work(int32_t enabled, int64_t seconds, int32_t found, int32_t password, int32_t unavailable, int32_t archived, int32_t main);
        C;

    private const float POSTED_SIZE_TO_REAL_SIZE = 0.97;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = self::W45_RUST_FFI_DEFAULT;

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
        private readonly MediaExtractionService $mediaService,
        private readonly MediaArticleFetcher $fetcher,
        private readonly ArchivedMediaSlicer $slicer,
        private readonly ConsoleOutputService $output,
        private readonly ContainerSplicer $splicer = new ContainerSplicer,
    ) {}

    public function hasWork(ReleaseProcessingContext $context): bool
    {
        if (! self::$useRustFfi) {
            return $this->hasWorkPhp($context);
        }

        return $this->hasWorkNative($context) ?? $this->hasWorkPhp($context);
    }

    public function hasWorkPhp(ReleaseProcessingContext $context): bool
    {
        return $this->config->processThumbnails
            && $this->config->mediaStageMaxSeconds > 0
            && ! $context->foundSample
            && ! $context->releaseHasPassword
            && ! $context->groupUnavailable
            && ($context->archivedMedia !== [] || $this->mainVideoIndexes($context) !== []);
    }

    public function hasWorkNative(ReleaseProcessingContext $context): ?bool
    {
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_w45_source_has_work(
                (int) $this->config->processThumbnails,
                $this->config->mediaStageMaxSeconds,
                (int) $context->foundSample,
                (int) $context->releaseHasPassword,
                (int) $context->groupUnavailable,
                (int) ($context->archivedMedia !== []),
                (int) ($this->mainVideoIndexes($context) !== []),
            );
        } catch (Throwable) {
            return null;
        }

        return match ($result) {
            0 => false,
            1 => true,
            default => null,
        };
    }

    public function run(ReleaseProcessingContext $context): void
    {
        $deadline = $this->deadline($context);
        $budget = $context->mediaBudget ??= new MediaByteBudget($this->config->mediaMaxBytes);
        $workDirectory = $context->tmpPath.'media_'.bin2hex(random_bytes(4)).'/';
        File::ensureDirectoryExists($workDirectory);

        try {
            $midSources = [];
            foreach ($this->headSources($context) as $number => $source) {
                if ($context->foundSample || $context->groupUnavailable || $this->expired($deadline)) {
                    break;
                }

                $head = $source instanceof ArchivedMediaEntry
                    ? $this->archivedHead($context, $source, $workDirectory.'head_'.$number, $budget, $deadline)
                    : $this->videoFileHead($context, $source, $workDirectory.'head_'.$number, $budget, $deadline);
                if ($head === null || $this->expired($deadline)) {
                    continue;
                }

                [$path, $hints, $container] = $head;
                $this->output->echoMediaSource($source instanceof ArchivedMediaEntry ? 'archive' : 'video');
                $this->processHead($context, $path, $hints, $source instanceof ArchivedMediaEntry && $source->isSample);

                if (! $context->foundSample && $this->config->mediaMidSources && $container?->canResumeMidStream()) {
                    $midSources[] = [$source, $path, $container];
                }
            }

            foreach ($midSources as $number => [$source, $headPath, $container]) {
                if ($context->foundSample || $context->groupUnavailable || $this->expired($deadline)) {
                    break;
                }

                $midPath = $this->midChunk($context, $source, $headPath, $container, $workDirectory.'mid_'.$number, $budget, $deadline);
                if ($midPath === null || $this->expired($deadline)) {
                    continue;
                }

                $this->output->echoMediaSource('mid');
                if ($this->mediaService->getSample($midPath, $context->tmpPath, $context->release->guid, MediaSourceHints::mid())) {
                    $context->markFound('sample');
                    $this->output->echoSampleCreated();
                }
            }
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    /**
     * @return list<ArchivedMediaEntry|int> archived entries or NZB source indexes, best first
     */
    private function headSources(ReleaseProcessingContext $context): array
    {
        $samples = array_filter($context->archivedMedia, static fn (ArchivedMediaEntry $entry): bool => $entry->isSample);
        $mains = array_filter($context->archivedMedia, static fn (ArchivedMediaEntry $entry): bool => ! $entry->isSample);

        return [...array_values($samples), ...$this->mainVideoIndexes($context), ...array_values($mains)];
    }

    /**
     * @return list<int>
     */
    private function mainVideoIndexes(ReleaseProcessingContext $context): array
    {
        return $this->config->mediaMainVideoFallback ? ($context->workPlan->mainVideoSourceIndexes ?? []) : [];
    }

    private function processHead(ReleaseProcessingContext $context, string $path, MediaSourceHints $hints, bool $isSample): void
    {
        if (! $context->foundSample && $this->mediaService->getSample($path, $context->tmpPath, $context->release->guid, $hints)) {
            $context->markFound('sample');
            $this->output->echoSampleCreated();
        }

        if (! $context->foundMediaInfo && $this->mediaService->getMediaInfo($path, (int) $context->release->id)) {
            $context->markFound('mediaInfo');
            $this->output->echoMediaInfoAdded();
        }

        // Only a sample makes a fair clip; a feature's first seconds are usually logos.
        if ($isSample && ! $context->foundVideo && $this->mediaService->getVideo($path, $context->tmpPath, $context->release->guid, $hints)) {
            $context->markFound('video');
            $this->output->echoVideoCreated();
        }
    }

    /**
     * Write the archived video's start, continued from later segments of its volume when that helps.
     *
     * @return array{0: string, 1: MediaSourceHints, 2: VideoContainer|null}|null
     */
    private function archivedHead(
        ReleaseProcessingContext $context,
        ArchivedMediaEntry $entry,
        string $basePath,
        MediaByteBudget $budget,
        float $deadline,
    ): ?array {
        $path = $basePath.'.'.($entry->container?->extension() ?? 'bin');
        if (File::put($path, $entry->headBytes) === false) {
            return null;
        }

        $continuation = $entry->continuationBytes($this->config->mediaSampleMaxBytes);
        if ($continuation > 0) {
            $this->fetcher->append(
                $context,
                $this->segmentsOf($context, $entry->sourceIndex),
                $entry->volumeSegmentsDownloaded,
                $entry->volumeBytesDownloaded,
                $continuation,
                $path,
                $budget,
                $deadline,
                $this->articleEstimate($context, $entry->sourceIndex),
            );
            clearstatcache(true, $path);
        }

        return [$path, MediaSourceHints::head($entry->realSize, (int) File::size($path)), $entry->container];
    }

    /**
     * Download the start of a video file posted outside any archive.
     *
     * @return array{0: string, 1: MediaSourceHints, 2: VideoContainer|null}|null
     */
    private function videoFileHead(
        ReleaseProcessingContext $context,
        int $sourceIndex,
        string $basePath,
        MediaByteBudget $budget,
        float $deadline,
    ): ?array {
        $name = $context->mediaPlan?->mappedFiles[$sourceIndex]->file->name
            ?? (string) ($context->nzbContents[$sourceIndex]['title'] ?? '');
        $path = $basePath.'.'.(VideoContainer::fromFileName($name)?->extension() ?? 'bin');

        $written = $this->fetcher->append(
            $context,
            $this->segmentsOf($context, $sourceIndex),
            0,
            0,
            $this->config->mediaSampleMaxBytes,
            $path,
            $budget,
            $deadline,
            $this->articleEstimate($context, $sourceIndex),
        );
        if ($written === 0) {
            File::delete($path);

            return null;
        }

        $realSize = $context->mediaPlan?->realSizeOf($sourceIndex)
            ?? (int) round((int) ($context->nzbContents[$sourceIndex]['size'] ?? 0) * self::POSTED_SIZE_TO_REAL_SIZE);
        $container = VideoContainer::detect((string) file_get_contents($path, false, null, 0, 4096))
            ?? VideoContainer::fromFileName($name);

        return [$path, MediaSourceHints::head($realSize, $written), $container];
    }

    /**
     * Fetch bytes from the middle of the video and give them the head's container headers.
     */
    private function midChunk(
        ReleaseProcessingContext $context,
        ArchivedMediaEntry|int $source,
        string $headPath,
        VideoContainer $container,
        string $basePath,
        MediaByteBudget $budget,
        float $deadline,
    ): ?string {
        $rawPath = $basePath.'.raw';
        $mid = null;

        try {
            if ($source instanceof ArchivedMediaEntry) {
                $volume = $this->middleVolume($context, $source->sourceIndex);
                if ($volume === null) {
                    return null;
                }

                $written = $this->fetcher->append(
                    $context, $this->segmentsOf($context, $volume->sourceIndex), 0, 0, $this->config->mediaMidBytes,
                    $rawPath, $budget, $deadline, $this->articleEstimate($context, $volume->sourceIndex),
                );
                $mid = $written > 0 ? $this->slicer->chunkFromVolume((string) file_get_contents($rawPath), $source->name) : null;
            } else {
                $segments = $this->segmentsOf($context, $source);
                if (count($segments) < 4) {
                    return null;
                }

                $written = $this->fetcher->append(
                    $context, $segments, intdiv(count($segments), 2), null, $this->config->mediaMidBytes,
                    $rawPath, $budget, $deadline, $this->articleEstimate($context, $source),
                );
                $mid = $written > 0 ? (string) file_get_contents($rawPath) : null;
            }
        } finally {
            File::delete($rawPath);
        }

        $spliced = $mid === null ? null : $this->splicer->splice((string) file_get_contents($headPath), $mid, $container);
        if ($spliced === null) {
            return null;
        }

        $path = $basePath.'.'.$container->extension();

        return File::put($path, $spliced) === false ? null : $path;
    }

    /**
     * Another volume of the same archive set, from the middle of the set when names give the order.
     */
    private function middleVolume(ReleaseProcessingContext $context, int $headSourceIndex): ?ArchiveCandidate
    {
        $volumes = array_values(array_filter(
            $context->workPlan->archiveCandidates ?? [],
            static fn (ArchiveCandidate $candidate): bool => $candidate->sourceIndex !== $headSourceIndex,
        ));
        if ($volumes === []) {
            return null;
        }

        $index = self::middleVolumeIndex(array_map(static fn (ArchiveCandidate $candidate): string => $candidate->title, $volumes));

        return $index === null ? null : $volumes[$index];
    }

    /**
     * Position in $titles of the middle title after a stable strnatcasecmp() sort.
     *
     * @param  list<string>  $titles
     */
    public static function middleVolumeIndex(array $titles): ?int
    {
        // Wave 45 caller benchmarks retain PHP by default; larger sets alone
        // cannot qualify the common-path mean and p99 adoption gate.
        return self::$useRustFfi ? self::middleVolumeIndexNative($titles) : self::middleVolumeIndexPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     */
    public static function middleVolumeIndexPhp(array $titles): ?int
    {
        if ($titles === []) {
            return null;
        }
        $order = array_keys($titles);
        usort($order, static fn (int $a, int $b): int => strnatcasecmp($titles[$a], $titles[$b]));

        return $order[intdiv(count($order), 2)];
    }

    /**
     * @param  list<string>  $titles
     */
    public static function middleVolumeIndexNative(array $titles, bool &$supported = false): ?int
    {
        $supported = false;
        $count = count($titles);
        if ($count === 0) {
            return null;
        }
        // strnatcasecmp() follows LC_CTYPE: the C locale is byte-exact, C.UTF-8 only for ASCII.
        $locale = setlocale(LC_CTYPE, '0');
        $asciiOnly = $locale === 'C.UTF-8' || $locale === 'C.utf8';
        $ffi = self::rustFfi();
        if ($ffi === null || (! $asciiOnly && $locale !== 'C' && $locale !== 'POSIX')) {
            return self::middleVolumeIndexPhp($titles);
        }
        $joined = implode("\0", $titles);
        if (substr_count($joined, "\0") !== $count - 1) {
            return self::middleVolumeIndexPhp($titles);
        }
        if ($asciiOnly && preg_match('/[\x80-\xff]/', $joined) === 1) {
            return self::middleVolumeIndexPhp($titles);
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_media_middle_volume_index($joined, strlen($joined), $count);
        } catch (Throwable) {
            return self::middleVolumeIndexPhp($titles);
        }
        if (! is_int($index) || $index < 0 || $index >= $count) {
            return self::middleVolumeIndexPhp($titles);
        }
        $supported = true;

        return $index;
    }

    /**
     * Toggle the Rust helpers; disabled, every *Native method falls back to PHP.
     */
    public static function useRustFfi(?bool $use): void
    {
        self::useW45RustFfi($use);
    }

    private static function rustFfi(): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    /**
     * @return list<string>
     */
    private function segmentsOf(ReleaseProcessingContext $context, int $sourceIndex): array
    {
        $segments = $context->nzbContents[$sourceIndex]['segments'] ?? [];

        return is_array($segments) ? array_values(array_map(strval(...), $segments)) : [];
    }

    private function articleEstimate(ReleaseProcessingContext $context, int $sourceIndex): int
    {
        $segments = max(1, count($this->segmentsOf($context, $sourceIndex)));

        return (int) ceil((int) ($context->nzbContents[$sourceIndex]['size'] ?? 0) / $segments);
    }

    /**
     * Stop early enough that one more ffmpeg call cannot push the release past its own timeout.
     */
    private function deadline(ReleaseProcessingContext $context): float
    {
        $deadline = hrtime(true) / 1e9 + $this->config->mediaStageMaxSeconds;
        if ($this->config->releaseProcessingTimeout > 0) {
            $deadline = min(
                $deadline,
                $context->startTime / 1e9 + $this->config->releaseProcessingTimeout - ($this->config->mediaFfmpegTimeout + 5),
            );
        }

        return $deadline;
    }

    /**
     * @phpstan-impure
     */
    private function expired(float $deadline): bool
    {
        return hrtime(true) / 1e9 >= $deadline;
    }
}
