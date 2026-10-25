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
     * Count image candidates, unencrypted candidates and stored candidates without extracting archives.
     * Integer sums saturate at PHP_INT_MAX; malformed rows are counted without coercion.
     *
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, images: int, usable_images: int, stored_images: int, total_bytes: int, invalid: int}
     */
    public static function summarizeArchiveFallbackBatch(array $files): array
    {
        return self::usesW426RustFfi()
            ? self::summarizeArchiveFallbackBatchNative($files)
            : self::summarizeArchiveFallbackBatchPhp($files);
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, images: int, usable_images: int, stored_images: int, total_bytes: int, invalid: int}
     */
    public static function summarizeArchiveFallbackBatchPhp(array $files): array
    {
        $result = ['total' => \count($files), 'images' => 0, 'usable_images' => 0, 'stored_images' => 0, 'total_bytes' => 0, 'invalid' => 0];
        foreach ($files as $row) {
            if (! self::w426ValidRow($row)) {
                $result['invalid']++;

                continue;
            }
            if (self::isArchiveImageNamePhp($row['name'])) {
                $result['images']++;
                if ($row['pass'] === 0) {
                    $result['usable_images']++;
                    $result['stored_images'] += (int) $row['stored'];
                    $result['total_bytes'] = self::w426Add($result['total_bytes'], $row['size']);
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, images: int, usable_images: int, stored_images: int, total_bytes: int, invalid: int}
     */
    public static function summarizeArchiveFallbackBatchNative(array $files): array
    {
        $total = \count($files);
        if (PHP_INT_SIZE !== 8 || $total > 100_000) {
            return self::summarizeArchiveFallbackBatchPhp($files);
        }
        $frame = pack('qV', 0, $total);
        foreach ($files as $row) {
            if (! self::w426ValidRow($row)) {
                return self::summarizeArchiveFallbackBatchPhp($files);
            }
            if (\strlen($row['name']) > 16_777_216 - 14 - \strlen($frame)) {
                return self::summarizeArchiveFallbackBatchPhp($files);
            }
            $frame .= pack('qCCV', $row['size'], $row['pass'], (int) $row['stored'], \strlen($row['name'])).$row['name'];
        }
        $slots = self::w426Slots(2, $frame, 6);
        if ($slots === null || $slots[0] !== $total || $slots[1] > $total || $slots[2] > $slots[1] || $slots[3] > $slots[2] || $slots[5] !== 0) {
            return self::summarizeArchiveFallbackBatchPhp($files);
        }

        return ['total' => $slots[0], 'images' => $slots[1], 'usable_images' => $slots[2], 'stored_images' => $slots[3], 'total_bytes' => $slots[4], 'invalid' => $slots[5]];
    }

    /** @phpstan-assert-if-true array{name: string, size: int, pass: int, stored: bool} $row */
    private static function w426ValidRow(mixed $row): bool
    {
        return \is_array($row) && \is_string($row['name'] ?? null) && \is_int($row['size'] ?? null) && $row['size'] >= 0 && \is_int($row['pass'] ?? null) && \in_array($row['pass'], [0, 1], true) && \is_bool($row['stored'] ?? null);
    }
}
