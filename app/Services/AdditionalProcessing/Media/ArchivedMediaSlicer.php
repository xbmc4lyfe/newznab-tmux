<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\ArchiveInfo;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Finds videos stored uncompressed in archive volumes, as scene releases store them,
 * and cuts their bytes straight out of the downloaded data. No unrar is needed, and
 * the file listing says exactly where the video starts and how much the volume holds.
 */
class ArchivedMediaSlicer
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_archived_media_range(const char *range_ptr, size_t range_len, const char *name_ptr, size_t name_len, int64_t file_size, int64_t next_offset, uint32_t has_next_offset, int64_t data_len, int64_t max_sample_bytes, int64_t min_main_video_bytes, int64_t *out_bounds);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $boundsBuf = null;

    private static ?CData $boundsPtr = null;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly ?string $libraryPath = null,
    ) {}

    /**
     * Keep the start of each stored video in a volume's listing for the media-source stage.
     *
     * @param  array<int|string, mixed>  $files  ArchiveInfo::getArchiveFileList() of $data
     */
    public function register(ReleaseProcessingContext $context, string $data, array $files, ?ArchiveCandidate $source): void
    {
        if (! $this->config->mediaArchiveSlicing || ! $this->config->processThumbnails || $source === null) {
            return;
        }

        foreach ($this->storedVideos($files) as $file) {
            $entry = $this->entryFor($data, $file, $source->sourceIndex, count($source->messageIds));
            if ($entry !== null) {
                $context->rememberArchivedMedia($entry);
            }
        }
    }

    /**
     * Read the chunk of a stored video that a later volume holds, from that volume's first bytes.
     */
    public function chunkFromVolume(string $volumeData, string $entryName): ?string
    {
        $archive = new ArchiveInfo;
        if (! $archive->setData($volumeData, true) || $archive->error) {
            return null;
        }

        foreach ($this->storedVideos($archive->getArchiveFileList()) as $file) {
            if ($file['name'] !== $entryName && basename(str_replace('\\', '/', $file['name'])) !== basename(str_replace('\\', '/', $entryName))) {
                continue;
            }

            [$start, $end] = $this->range($file);
            if ($start < strlen($volumeData)) {
                return substr($volumeData, $start, min($end, strlen($volumeData) - 1) - $start + 1);
            }
        }

        return null;
    }

    /**
     * @param  array{name: string, size: int, range: string, next_offset: int|null}  $file
     */
    public function entryFor(string $data, array $file, int $sourceIndex, int $segmentsDownloaded): ?ArchivedMediaEntry
    {
        return $this->entryForPhp($data, $file, $sourceIndex, $segmentsDownloaded);
    }

    /**
     * @param  array{name: string, size: int, range: string, next_offset: int|null}  $file
     */
    public function entryForPhp(string $data, array $file, int $sourceIndex, int $segmentsDownloaded): ?ArchivedMediaEntry
    {
        [$start] = $this->rangePhp($file);
        if ($start >= strlen($data)) {
            return null;
        }

        $size = $file['size'];
        $inVolumeEnd = $file['next_offset'] !== null && $file['next_offset'] > $start
            ? $file['next_offset'] - 1
            : $start + $size - 1;
        $available = min($inVolumeEnd, strlen($data) - 1) - $start + 1;
        $head = substr($data, $start, min($available, $this->config->mediaSampleMaxBytes));
        $name = $file['name'];

        return new ArchivedMediaEntry(
            name: $name,
            realSize: $size,
            sourceIndex: $sourceIndex,
            headBytes: $head,
            rangeStart: $start,
            inVolumeEnd: $inVolumeEnd,
            volumeBytesDownloaded: strlen($data),
            volumeSegmentsDownloaded: $segmentsDownloaded,
            isSample: preg_match('/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i', $name) === 1
                || $size < $this->config->mediaMainVideoMinBytes,
            container: VideoContainer::detect($head) ?? VideoContainer::fromFileName($name),
        );
    }

    /**
     * @param  array{name: string, size: int, range: string, next_offset: int|null}  $file
     */
    public function entryForNative(string $data, array $file, int $sourceIndex, int $segmentsDownloaded, bool &$supported = false): ?ArchivedMediaEntry
    {
        $supported = false;
        [$ffi, $boundsBuf, $boundsPtr] = $this->resolveFfi();
        if ($ffi === null || $boundsBuf === null || $boundsPtr === null) {
            return $this->entryForPhp($data, $file, $sourceIndex, $segmentsDownloaded);
        }

        $range = (string) $file['range'];
        $name = (string) $file['name'];
        $size = (int) $file['size'];
        $nextOffset = $file['next_offset'];
        $dataLen = strlen($data);

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_archived_media_range(
                $range,
                strlen($range),
                $name,
                strlen($name),
                $size,
                $nextOffset ?? 0,
                $nextOffset !== null ? 1 : 0,
                $dataLen,
                $this->config->mediaSampleMaxBytes,
                $this->config->mediaMainVideoMinBytes,
                $boundsPtr,
            );
        } catch (Throwable) {
            return $this->entryForPhp($data, $file, $sourceIndex, $segmentsDownloaded);
        }

        if (! is_int($status) || $status < 0) {
            return $this->entryForPhp($data, $file, $sourceIndex, $segmentsDownloaded);
        }

        $supported = true;
        $start = (int) $boundsBuf[0];
        if ($start >= $dataLen) {
            return null;
        }

        $inVolumeEnd = (int) $boundsBuf[2];
        $headLen = (int) $boundsBuf[4];
        $isSample = ((int) $boundsBuf[5]) === 1;
        $head = substr($data, $start, $headLen);

        return new ArchivedMediaEntry(
            name: $name,
            realSize: $size,
            sourceIndex: $sourceIndex,
            headBytes: $head,
            rangeStart: $start,
            inVolumeEnd: $inVolumeEnd,
            volumeBytesDownloaded: $dataLen,
            volumeSegmentsDownloaded: $segmentsDownloaded,
            isSample: $isSample,
            container: VideoContainer::detect($head) ?? VideoContainer::fromFileName($name),
        );
    }

    /**
     * Unencrypted, uncompressed video entries of the archive itself (not of nested archives).
     *
     * @param  array<int|string, mixed>  $files
     * @return list<array{name: string, size: int, range: string, next_offset: int|null}>
     */
    public function storedVideos(array $files): array
    {
        return $this->storedVideosPhp($files);
    }

    /**
     * @param  array<int|string, mixed>  $files
     * @return list<array{name: string, size: int, range: string, next_offset: int|null}>
     */
    public function storedVideosPhp(array $files): array
    {
        $videos = [];
        foreach ($files as $file) {
            if (is_array($file)
                && is_string($file['name'] ?? null)
                && isset($file['size'], $file['range'])
                && ($file['source'] ?? 'main') === 'main'
                && empty($file['pass'])
                && empty($file['is_dir'])
                && ($file['compressed'] ?? null) === 0
                && (int) $file['size'] > 0
                && VideoContainer::fromFileName($file['name']) !== null
            ) {
                $videos[] = [
                    'name' => $file['name'],
                    'size' => (int) $file['size'],
                    'range' => (string) $file['range'],
                    'next_offset' => isset($file['next_offset']) ? (int) $file['next_offset'] : null,
                ];
            }
        }

        return $videos;
    }

    /**
     * @param  array<int|string, mixed>  $files
     * @return list<array{name: string, size: int, range: string, next_offset: int|null}>
     */
    public function storedVideosNative(array $files, bool &$supported = false): array
    {
        $supported = false;
        [$ffi, $boundsBuf, $boundsPtr] = $this->resolveFfi();
        if ($ffi === null || $boundsBuf === null || $boundsPtr === null) {
            return $this->storedVideosPhp($files);
        }

        $videos = $this->storedVideosPhp($files);
        if ($videos === []) {
            $supported = true;

            return [];
        }

        foreach ($videos as $video) {
            $rangeSupported = false;
            $this->rangeNative($video, $rangeSupported);
            if (! $rangeSupported) {
                return $videos;
            }
        }

        $supported = true;

        return $videos;
    }

    /**
     * @param  array{range: string}  $file
     * @return array{0: int, 1: int}
     */
    public function range(array $file): array
    {
        return $this->rangePhp($file);
    }

    /**
     * @param  array{range: string}  $file
     * @return array{0: int, 1: int}
     */
    public function rangePhp(array $file): array
    {
        $bounds = explode('-', $file['range'], 2);

        return [(int) $bounds[0], (int) ($bounds[1] ?? -1)];
    }

    /**
     * @param  array{range: string}  $file
     * @return array{0: int, 1: int}
     */
    public function rangeNative(array $file, bool &$supported = false): array
    {
        $supported = false;
        [$ffi, $boundsBuf, $boundsPtr] = $this->resolveFfi();
        if ($ffi === null || $boundsBuf === null || $boundsPtr === null) {
            return $this->rangePhp($file);
        }

        $range = (string) $file['range'];
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_archived_media_range(
                $range,
                strlen($range),
                '',
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                $boundsPtr,
            );
        } catch (Throwable) {
            return $this->rangePhp($file);
        }

        if (! is_int($status) || $status < 0) {
            return $this->rangePhp($file);
        }

        $supported = true;

        return [(int) $boundsBuf[0], (int) $boundsBuf[1]];
    }

    /**
     * @return array{0: FFI|null, 1: CData|null, 2: CData|null}
     */
    private function resolveFfi(): array
    {
        if ($this->libraryPath !== null) {
            $ffi = RustPipelineLibrary::resolveDomain('archived_media_slicer', self::FFI_CDEF, $this->libraryPath);
            if ($ffi === null) {
                return [null, null, null];
            }
            try {
                $buf = $ffi->new('int64_t[6]');

                return [$ffi, $buf, FFI::addr($buf[0])];
            } catch (Throwable) {
                return [null, null, null];
            }
        }

        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            $ffi = RustPipelineLibrary::resolveDomain('archived_media_slicer', self::FFI_CDEF);
            if ($ffi !== null) {
                try {
                    $buf = $ffi->new('int64_t[6]');
                    self::$boundsBuf = $buf;
                    self::$boundsPtr = FFI::addr($buf[0]);
                    self::$ffi = $ffi;
                } catch (Throwable) {
                    self::$ffi = null;
                }
            }
        }

        return [self::$ffi, self::$boundsBuf, self::$boundsPtr];
    }
}
