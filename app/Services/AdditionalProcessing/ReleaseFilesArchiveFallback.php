<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Models\ReleaseFile;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Shared fallback logic for extracting known files back out of downloaded archives.
 */
class ReleaseFilesArchiveFallback
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_rfaf_is_archive_image_name(const char *ptr, size_t len);
        ssize_t nntmux_rfaf_extracted_file_suffix(const char *ptr, size_t len, char *out, size_t out_cap);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    public function __construct(
        private readonly ArchiveExtractionService $archiveService,
        private readonly MediaExtractionService $mediaService,
        private readonly UsenetDownloadService $downloadService,
        private readonly ReleaseFileManager $releaseManager,
        private readonly ConsoleOutputService $output
    ) {}

    /**
     * Try JPG/PNG/WebP files listed inside an already-downloaded archive summary.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    public function processJpgFromArchiveFileList(
        string $compressedData,
        array $files,
        ReleaseProcessingContext $context
    ): void {
        $imageFiles = array_values(array_filter(
            $files,
            static fn (mixed $file): bool => is_array($file)
                && empty($file['pass'])
                && self::isArchiveImageName((string) ($file['name'] ?? ''))
        ));

        if ($imageFiles === []) {
            return;
        }

        // Images that can be copied straight out of the downloaded data go first, then the most telling.
        $complete = ArchiveExtractionService::completeStoredEntries($imageFiles);
        usort($imageFiles, static fn (array $a, array $b): int => [
            ! isset($complete[(string) ($a['name'] ?? '')]),
            ImageCandidateRanker::rank((string) ($a['name'] ?? '')),
            -(int) ($a['size'] ?? 0),
        ] <=> [
            ! isset($complete[(string) ($b['name'] ?? '')]),
            ImageCandidateRanker::rank((string) ($b['name'] ?? '')),
            -(int) ($b['size'] ?? 0),
        ]);

        $this->processExtractedCandidatesFromData(
            $compressedData,
            array_slice($imageFiles, 0, 3),
            $context,
            'extracted_',
            function (string $tempPath, ReleaseProcessingContext $context): bool {
                if (! $this->mediaService->isUsableImage($tempPath)) {
                    return false;
                }

                if (! $this->mediaService->getJPGSample($tempPath, $context->release->guid)) {
                    return false;
                }

                $context->markFound('jpgSample');
                $this->output->echoJpgSaved();

                return true;
            }
        );
    }

    /**
     * Re-download archives and extract stored JPG/PNG/WebP candidates.
     */
    public function processJpgFromReleaseFiles(ReleaseProcessingContext $context): void
    {
        $imageFiles = ReleaseFile::query()
            ->where('releases_id', $context->release->id)
            ->where(function ($query) {
                $query->where('name', 'like', '%.jpg')
                    ->orWhere('name', 'like', '%.jpeg')
                    ->orWhere('name', 'like', '%.png')
                    ->orWhere('name', 'like', '%.webp');
            })
            ->orderByDesc('size')
            ->limit(10)
            ->get();

        if ($imageFiles->isEmpty()) {
            return;
        }

        $imageFiles = new EloquentCollection(array_slice(ImageCandidateRanker::sort(
            $imageFiles->all(),
            static fn (ReleaseFile $file): string => (string) $file->name,
            static fn (ReleaseFile $file): int => (int) $file->size,
        ), 0, 3));

        $this->processStoredReleaseFiles(
            $context,
            $imageFiles,
            'release_file_',
            static fn (ReleaseProcessingContext $context): bool => $context->foundJPGSample,
            function (string $tempPath, ReleaseProcessingContext $context): bool {
                if (! $this->mediaService->isUsableImage($tempPath)) {
                    return false;
                }

                if (! $this->mediaService->getJPGSample($tempPath, $context->release->guid)) {
                    return false;
                }

                $context->markFound('jpgSample');
                $this->output->echoJpgSaved();

                return true;
            }
        );
    }

    /**
     * Re-download archives and extract stored NFO-like candidates.
     */
    public function processNfoFromReleaseFiles(ReleaseProcessingContext $context): void
    {
        $nfoFiles = ReleaseFile::query()
            ->where('releases_id', $context->release->id)
            ->nfoFiles()
            ->limit(3)
            ->get();

        if ($nfoFiles->isEmpty()) {
            return;
        }

        $nntp = $this->downloadService->getNNTP();

        $this->processStoredReleaseFiles(
            $context,
            $nfoFiles,
            'release_file_',
            static fn (ReleaseProcessingContext $context): bool => ! $context->releaseHasNoNFO,
            function (string $tempPath, ReleaseProcessingContext $context) use ($nntp): bool {
                if (! $this->releaseManager->processNfoFile($tempPath, $context, $nntp)) {
                    return false;
                }

                $this->output->echoNfoFound();

                return true;
            }
        );
    }

    /**
     * Extract NFO candidates from archives already downloaded for this release.
     */
    public function processNfoFromDownloadedArchives(ReleaseProcessingContext $context): void
    {
        if ($context->downloadedArchives === [] || ! $context->releaseHasNoNFO) {
            return;
        }

        $nntp = $this->downloadService->getNNTP();

        foreach ($context->downloadedArchives as $archive) {
            $nfoFiles = array_values(array_filter(
                $archive->files,
                fn (array $file): bool => $this->archiveService->isNfoFile((string) ($file['name'] ?? '')),
            ));

            $this->processExtractedCandidatesFromData(
                $archive->data,
                array_slice($nfoFiles, 0, 3),
                $context,
                'downloaded_nfo_',
                function (string $tempPath, ReleaseProcessingContext $context) use ($nntp): bool {
                    if (! $this->releaseManager->processNfoFile($tempPath, $context, $nntp)) {
                        return false;
                    }

                    $this->output->echoNfoFound();

                    return true;
                },
            );

            if (! $context->releaseHasNoNFO) {
                return;
            }
        }
    }

    /**
     * @param  EloquentCollection<int, ReleaseFile>  $candidates
     * @param  callable(ReleaseProcessingContext): bool  $shouldStop
     * @param  callable(string, ReleaseProcessingContext): bool  $handler
     */
    private function processStoredReleaseFiles(
        ReleaseProcessingContext $context,
        EloquentCollection $candidates,
        string $tempPrefix,
        callable $shouldStop,
        callable $handler
    ): void {
        $archiveCandidates = $this->archiveCandidates($context);
        if ($candidates->isEmpty() || $archiveCandidates === []) {
            return;
        }

        foreach ($archiveCandidates as $archiveCandidate) {
            if ($shouldStop($context)) {
                break;
            }

            $result = $this->downloadService->download(
                DownloadKind::Compressed,
                $archiveCandidate->messageIds,
                $context->releaseGroupName,
                $context->release->id,
                $archiveCandidate->title,
            );

            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;
                $this->output->echoGroupUnavailable();

                return;
            }

            if (! $result['success'] || empty($result['data'])) {
                continue;
            }

            $filenames = $candidates
                ->pluck('name')
                ->filter(static fn (mixed $filename): bool => is_string($filename) && $filename !== '')
                ->values()
                ->all();
            $extractedFiles = $this->extractCandidateFiles(
                $result['data'],
                $filenames,
                $context->tmpPath,
            );

            foreach ($candidates as $candidate) {
                $fileData = $extractedFiles[$candidate->name] ?? null;

                if ($fileData === null || $fileData === '') {
                    continue;
                }

                if ($this->withExtractedTempFile($candidate->name, $fileData, $context, $tempPrefix, $handler)) {
                    return;
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @param  callable(string, ReleaseProcessingContext): bool  $handler
     */
    private function processExtractedCandidatesFromData(
        string $compressedData,
        array $candidates,
        ReleaseProcessingContext $context,
        string $tempPrefix,
        callable $handler
    ): void {
        $filenames = array_values(array_filter(array_map(
            static fn (array $candidate): string => (string) ($candidate['name'] ?? ''),
            $candidates,
        )));
        $extractedFiles = $this->extractCandidateFiles(
            $compressedData,
            $filenames,
            $context->tmpPath,
        );

        foreach ($candidates as $candidate) {
            $filename = (string) ($candidate['name'] ?? '');
            if ($filename === '') {
                continue;
            }

            $fileData = $extractedFiles[$filename] ?? null;
            if ($fileData === null || $fileData === '') {
                continue;
            }

            if ($this->withExtractedTempFile($filename, $fileData, $context, $tempPrefix, $handler)) {
                return;
            }
        }
    }

    /**
     * @return list<ArchiveCandidate>
     */
    private function archiveCandidates(ReleaseProcessingContext $context): array
    {
        if ($context->workPlan === null) {
            return [];
        }

        return array_values(array_filter(
            $context->workPlan->archiveCandidates,
            static fn (ArchiveCandidate $candidate): bool => $candidate->likelyFirstVolume,
        ));
    }

    /**
     * @param  list<string>  $filenames
     * @return array<string, string>
     */
    private function extractCandidateFiles(string $archiveData, array $filenames, string $tmpPath): array
    {
        return $this->archiveService->extractSpecificFiles($archiveData, $filenames, $tmpPath);
    }

    /**
     * @param  callable(string, ReleaseProcessingContext): bool  $handler
     */
    private function withExtractedTempFile(
        string $filename,
        string $fileData,
        ReleaseProcessingContext $context,
        string $tempPrefix,
        callable $handler
    ): bool {
        $tempPath = $context->tmpPath.$tempPrefix.uniqid('', true).self::extractedFileSuffix($filename);
        File::put($tempPath, $fileData);

        try {
            return $handler($tempPath, $context);
        } finally {
            File::delete($tempPath);
        }
    }

    /**
     * Whether an archive entry name is a JPG/PNG/WebP image.
     *
     * Retains the native PHP path: the FFI crossing exceeds one anchored PCRE match
     * (0.36x mean / 0.40x p99 common, 0.52x / 1.00x uncommon).
     */
    public static function isArchiveImageName(string $name): bool
    {
        return self::isArchiveImageNamePhp($name);
    }

    public static function isArchiveImageNamePhp(string $name): bool
    {
        return preg_match('/\.(jpe?g|png|webp)$/i', $name) === 1;
    }

    public static function isArchiveImageNameNative(string $name, bool &$supported = false): bool
    {
        $supported = false;
        $ffi = self::rustFfi();
        if ($ffi === null || ! self::environmentSupported()) {
            return self::isArchiveImageNamePhp($name);
        }
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_rfaf_is_archive_image_name($name, strlen($name));
        } catch (Throwable) {
            return self::isArchiveImageNamePhp($name);
        }
        if ($status !== 0 && $status !== 1) {
            return self::isArchiveImageNamePhp($name);
        }
        $supported = true;

        return $status === 1;
    }

    /**
     * Lower-case extension suffix (with its dot) for a temporary copy of an extracted file.
     *
     * Retains the native PHP path: 0.36x mean / 0.38x p99 common, 0.39x / 0.56x uncommon.
     */
    public static function extractedFileSuffix(string $filename): string
    {
        return self::extractedFileSuffixPhp($filename);
    }

    public static function extractedFileSuffixPhp(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $extension === '' ? '' : '.'.$extension;
    }

    public static function extractedFileSuffixNative(string $filename, bool &$supported = false): string
    {
        $supported = false;
        $ffi = self::rustFfi();
        if ($ffi === null || ! self::environmentSupported()) {
            return self::extractedFileSuffixPhp($filename);
        }
        try {
            $len = strlen($filename);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $len + 2, $capacity);
            if ($buffer === null) {
                return self::extractedFileSuffixPhp($filename);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_rfaf_extracted_file_suffix($filename, $len, $buffer, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return self::extractedFileSuffixPhp($filename);
            }
            $suffix = $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::extractedFileSuffixPhp($filename);
        }
        $supported = true;

        return $suffix;
    }

    /**
     * Toggle the Rust helpers; disabled, every *Native method falls back to PHP.
     */
    public static function useRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /** basename() and PCRE case folding follow LC_CTYPE outside the ASCII-compatible C locales. */
    private static function environmentSupported(): bool
    {
        return in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true);
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
}
