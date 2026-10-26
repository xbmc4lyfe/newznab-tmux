<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Facades\Search;
use App\Models\Category;
use App\Models\MediaInfo as MediaInfoModel;
use App\Models\ParHash;
use App\Models\Predb;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NameFixing\SeasonPackDetector;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Par2FileList;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\Par2FileHashEvidence;
use App\Support\PredbSearchDocument;
use App\Support\ReleaseArchiveNamingEvidence;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use FFI\CData;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing release-related database operations.
 * Handles file info, release updates, deletions, and search index updates.
 */
class ReleaseFileManager
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_rfm_is_nfo_filename(const char *ptr, size_t len);
        ssize_t nntmux_rfm_extract_release_name(const char *ptr, size_t len, char *out_ptr, size_t out_cap);
        int32_t nntmux_rfm_normalize_file_size(const char *ptr, size_t len, int64_t *out);
        C;

    private const int DEFAULT_RELEASE_FILE_LIMIT = 11;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    private static ?CData $fileSizeOut = null;

    private readonly ReleaseUpdateService $releaseUpdateService;

    private readonly FileNameCleaner $fileNameCleaner;

    private readonly ReleaseSearchSyncCoordinator $searchSyncCoordinator;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly ReleaseImageService $releaseImage,
        private readonly NfoService $nfo,
        private readonly NzbService $nzb,
        private readonly NameFixingService $nameFixingService,
        ?ReleaseUpdateService $releaseUpdateService = null,
        ?FileNameCleaner $fileNameCleaner = null,
        ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
    ) {
        $this->searchSyncCoordinator = $searchSyncCoordinator
            ?? new ReleaseSearchSyncCoordinator(
                new PersistenceMetricsCollector,
            );
        $this->releaseUpdateService = $releaseUpdateService
            ?? new ReleaseUpdateService(searchSyncCoordinator: $this->searchSyncCoordinator);
        $this->fileNameCleaner = $fileNameCleaner ?? new FileNameCleaner;
    }

    /**
     * Add file information to the database.
     *
     * @param  array<string, mixed>  $file
     *
     * @throws \Exception
     */
    public function addFileInfo(
        array $file,
        ReleaseProcessingContext $context,
        string $supportFileRegex
    ): bool {
        if (isset($file['error'])) {
            if ($this->config->debugMode) {
                Log::debug("Error: {$file['error']} (in: {$file['source']})");
            }

            return false;
        }

        if (! isset($file['name'])) {
            return false;
        }

        // Check for password
        if (! empty($file['pass'])) {
            $context->releaseHasPassword = true;
            $context->passwordStatus = ReleaseBrowseService::PASSWD_RAR;

            return false;
        }

        // Check inner file blacklist
        if ($this->config->innerFileBlacklist !== false
            && preg_match($this->config->innerFileBlacklist, $file['name'])
        ) {
            $context->releaseHasPassword = true;
            $context->passwordStatus = ReleaseBrowseService::PASSWD_RAR;

            return false;
        }

        // Skip support files
        if (preg_match(
            '/(?:'.$supportFileRegex.'|part\d+|[rz]\d{1,3}|zipr\d{2,3}|\d{2,3}|zipx?|zip|rar)(\s*\.rar)?$/i',
            $file['name']
        )) {
            return false;
        }

        $size = $this->normalizeFileSize($file['size'] ?? 0);
        if ($size === null) {
            Log::warning('Skipping release file with invalid size metadata.', [
                'release_id' => $context->release->id,
                'name' => $file['name'],
                'size_type' => get_debug_type($file['size'] ?? 0),
            ]);

            return false;
        }

        // Increment total file info count
        $context->totalFileInfo++;

        $context->rememberNamingWitness((string) $file['name']);
        // Don't add too many files
        if ($context->addedFileInfo >= $this->releaseFileLimit($context)) {
            $context->archiveNameEvidenceIncomplete = true;

            return false;
        }

        $queued = $this->queueReleaseFile(
            $context,
            $context->release->id,
            $file['name'],
            $size,
            $file['date'] ?? now(),
            $file['pass'] ?? 0,
            '',
            $file['crc32'] ?? ''
        );

        if ($queued) {
            // Check for codec spam
            if (preg_match('#(?:^|[/\\\\])Codec[/\\\\]Setup\.exe$#i', $file['name'])) {
                if ($this->config->debugMode) {
                    Log::debug('Codec spam found, setting release to potentially passworded.');
                }
                $context->releaseHasPassword = true;
                $context->passwordStatus = ReleaseBrowseService::PASSWD_RAR;
            } elseif ($file['name'] !== '' && ! str_starts_with($file['name'], '.') && ! $context->defersArchiveNameChecks()) {
                // Run PreDB filename check
                $this->applyProducerName($context, $this->nameFixingService, function (Release $entry) use ($file, $context): void {
                    $entry->setAttribute('filename', $file['name']);
                    $this->nameFixingService->matchPreDbFileNames($entry, [$file['name']], true, true, true);
                    $context->rememberArchiveFilenameNamingAttempt((string) $file['name'], $this->nameFixingService->wasEpisodeNamingDeferredByArchiveProof());
                });
            }

            return true;
        }

        return false;
    }

    /**
     * Update search indexes after adding file info.
     */
    public function updateSearchIndex(int $releaseId): void
    {
        $this->searchSyncCoordinator->request($releaseId);
    }

    /** @param callable(Release): void $rename */
    private function applyProducerName(ReleaseProcessingContext $context, ReleaseUpdateService|NameFixingService $service, callable $rename): bool
    {
        if ($context->fullPar2EvidenceDeferred) {
            return false;
        }
        if ($context->producerNamingClaim !== null) {
            $context->producerNamingAttempted = true;
            if (! $this->ownsFullPar2Evidence($context)) {
                $this->deferProducerNaming($context);

                return false;
            }
        }
        $entry = clone $context->release;
        $entry->releases_id = $entry->id;
        if ($context->producerNamingClaim !== null) {
            $entry->setAttribute('observed_additional_pp_claim', $context->producerNamingClaim);
        }
        $service->reset();
        $rename($entry);
        if ($service->lastCommittedIdentity() !== null) {
            return $this->acceptProducerName($context, $service);
        }
        if (DB::getFacadeApplication() !== null && DB::transactionLevel() > 0) {
            $generation = $context->producerNamingGeneration;
            DB::afterCommit(function () use ($context, $service, $generation): void {
                if ($context->producerNamingGeneration === $generation) {
                    $this->acceptProducerName($context, $service);
                }
            });

            return false;
        }

        return $this->acceptProducerName($context, $service);
    }

    private function acceptProducerName(ReleaseProcessingContext $context, ReleaseUpdateService|NameFixingService $service): bool
    {
        $proof = $service->lastCommittedIdentity();
        if ($proof !== null && ! $context->advanceFullPar2EvidenceIdentity($proof)) {
            $this->deferProducerNaming($context);

            return false;
        }
        if ($context->producerNamingClaim !== null && ! $this->ownsFullPar2Evidence($context)) {
            $this->deferProducerNaming($context);

            return false;
        }

        return $proof !== null;
    }

    private function deferProducerNaming(ReleaseProcessingContext $context): void
    {
        $context->fullPar2EvidenceDeferred = true;
        $this->searchSyncCoordinator->discard($context->par2EvidenceSnapshot['id']);
    }

    /**
     * Rename NZBSPLIT-wrapped releases directly from parsed NZB titles.
     *
     * @param  list<array<string, mixed>>  $nzbContents
     */
    public function processReleaseNameFromNzbContents(array $nzbContents, ReleaseProcessingContext $context): bool
    {
        if (! $this->releaseHasNzbSplitWrapper($context->release)) {
            return false;
        }

        foreach ($nzbContents as $nzbFile) {
            $title = (string) ($nzbFile['title'] ?? '');

            if ($title === '') {
                continue;
            }

            $candidate = $this->fileNameCleaner->extractNzbSplitName($title);

            if ($candidate === null) {
                continue;
            }

            $candidate = $this->fileNameCleaner->normalizeCandidateTitle($candidate);

            $isPlausible = $this->fileNameCleaner->isPlausibleReleaseTitle($candidate);
            if (! $isPlausible) {
                continue;
            }

            return $this->applyProducerName($context, $this->releaseUpdateService, function (Release $entry) use ($candidate): void {
                $this->releaseUpdateService->updateRelease(
                    $entry,
                    $candidate,
                    'NZBSPLIT wrapper',
                    true,
                    'Filenames, ',
                    true,
                    true
                );
            });
        }

        return false;
    }

    /**
     * Finalize release processing with status updates.
     */
    public function finalizeRelease(ReleaseProcessingContext $context, bool $processPasswords): void
    {
        if ($context->fullPar2EvidenceDeferred || $context->nzbSourceDigest === null) {
            $context->fullPar2EvidenceDeferred = true;
            $this->searchSyncCoordinator->discard($context->par2EvidenceSnapshot['id']);

            return;
        }
        $updateRows = ['haspreview' => 0];

        // Check for existing samples
        if ($this->releaseImage->imageExists($this->releaseImage->imgSavePath, $context->release->guid.'_thumb')) {
            $updateRows = ['haspreview' => 1];
        }

        if (File::isFile($this->releaseImage->vidSavePath.$context->release->guid.'.ogv')) {
            $updateRows['videostatus'] = 1;
        }

        if ($this->releaseImage->imageExists($this->releaseImage->jpgSavePath, $context->release->guid.'_thumb')) {
            $updateRows['jpgstatus'] = 1;
        }

        $passwordStatus = $context->passwordStatus;

        // Set to no password if processing is off
        if (! $processPasswords) {
            $context->releaseHasPassword = false;
        }

        $updateRows = array_merge($updateRows, AdditionalCandidateQuery::claimResetValues());

        $pendingReleaseFiles = array_values($context->pendingReleaseFiles);
        $pendingParHashes = array_values($context->pendingParHashes);
        $pendingFullParHashes = array_values($context->pendingFullParHashes);
        $terminalNamingSnapshot = null;
        $terminalNamingFiles = [];
        $sourceDigest = $context->nzbSourceDigest;

        try {
            $insertedReleaseFiles = DB::transaction(function () use (
                $context,
                $pendingReleaseFiles,
                $pendingParHashes,
                $pendingFullParHashes,
                $updateRows,
                $processPasswords,
                $passwordStatus,
                &$terminalNamingSnapshot,
                &$terminalNamingFiles,
            ): ?int {
                $terminalNamingSnapshot = null;
                $terminalNamingFiles = [];
                if (! $this->ownsFullPar2Evidence($context)) {
                    return null;
                }
                $insertedReleaseFiles = null;
                $persist = function () use ($context, $pendingReleaseFiles, $pendingParHashes, $pendingFullParHashes,
                    $updateRows, $processPasswords, $passwordStatus, &$insertedReleaseFiles): void {
                    foreach ($pendingFullParHashes as $evidence) {
                        if (! Par2FileHashEvidence::remember(
                            $context->fullPar2EvidenceSnapshot()['id'], $evidence['full_md5'], $evidence['hash16k'], $evidence['size'],
                            $context->fullPar2EvidenceSnapshot(), fn (): bool => $this->ownsFullPar2Evidence($context),
                        )) {
                            throw new \RuntimeException('Additional-processing evidence admission failed.');
                        }
                    }
                    if (! $this->ownsFullPar2Evidence($context)) {
                        throw new \RuntimeException('Additional-processing evidence ownership changed inside finalization.');
                    }
                    $inserted = $pendingReleaseFiles === []
                        ? 0
                        : ReleaseFile::query()->insertOrIgnore($pendingReleaseFiles);

                    if ($inserted > 0) {
                        $current = DB::table('releases')->where('id', $context->release->id)->lockForUpdate()->first();
                        if ($current !== null && (int) $current->isrenamed === 0 && (int) $current->predb_id === 0) {
                            $updateRows['proc_files'] = NameFixingService::PROC_FILES_NONE;
                        }
                    }

                    if ($pendingParHashes !== []) {
                        ParHash::query()->insertOrIgnore($pendingParHashes);
                    }

                    $releaseFilesCount = ReleaseFile::whereReleasesId($context->release->id)->count('releases_id') ?? 0;

                    if (! $context->releaseHasPassword && $context->nzbHasCompressedFile && ! $context->compressedFilesAreMedia()
                        && $releaseFilesCount === 0
                    ) {
                        // No file list could be read, often because volumes were still missing.
                        // Queue the release once more after a delay; a second failure stays unknown.
                        // Only retry when inspection ran and a later attempt could still read the list.
                        $retried = $updateRows['haspreview'] === 0
                            && $context->archiveInspectionAttempted
                            && (! $context->archiveListingUndecodable || $context->archiveRetryable)
                            && $this->commitFullPar2FinalizationUpdate(
                                $context,
                                Release::query()->where('id', $context->release->id)->whereNull(AdditionalCandidateQuery::ARCHIVE_RETRY_COLUMN),
                                [...$updateRows, 'haspreview' => -1, AdditionalCandidateQuery::ARCHIVE_RETRY_COLUMN => now()->addSeconds(AdditionalCandidateQuery::archiveRetryDelaySeconds())],
                                true,
                                true,
                            ) > 0;
                        if (! $retried) {
                            $this->commitFullPar2FinalizationUpdate($context, Release::query()->where('id', $context->release->id), $updateRows, true);
                        }
                    } else {
                        // A cut-off listing cannot rule out an encrypted entry beyond the cut.
                        if (! $processPasswords) {
                            $updateRows['passwordstatus'] = ReleaseBrowseService::PASSWD_NONE;
                        } elseif ($context->releaseHasPassword || ! $context->archiveListingIncomplete) {
                            $updateRows['passwordstatus'] = $passwordStatus;
                        }
                        $updateRows['rarinnerfilecount'] = $releaseFilesCount;
                        $this->commitFullPar2FinalizationUpdate($context, Release::query()->where('id', $context->release->id), $updateRows, true);
                    }

                    $insertedReleaseFiles = $inserted;
                };
                $names = array_keys($context->archiveNamingWitnesses);
                $authoritative = self::archiveNamingIsAuthoritative($context);
                $scope = ($context->seasonPack || SeasonPackDetector::isMultiEpisodeSet($names))
                    ? 'multi_episode'
                    : ($authoritative ? 'complete_single' : 'incomplete');
                if (! app(ReleaseArchiveNamingEvidence::class)->recordLocked($context->release, $context->nzbSourceDigest,
                    $scope, $names, $authoritative, $persist, $context->namingWitnessesOmitted)) {
                    return null;
                }
                if ($authoritative
                    && ! $context->namingWitnessesOmitted && ! $context->seasonPack
                    && ! SeasonPackDetector::hasDistinctEpisodeIdentities($names)) {
                    $candidates = array_values(array_filter($names, static fn (string $name): bool => ($context->archiveFilenameNamingAttempts[$name] ?? false)
                        || (! array_key_exists($name, $context->archiveFilenameNamingAttempts) && SeasonPackDetector::isEpisodeName($name))));
                    if ($candidates !== []) {
                        $current = DB::table('releases')->where('id', $context->release->id)->lockForUpdate()->first();
                        if ($current !== null && (int) $current->predb_id === 0 && (int) $current->isrenamed === 0) {
                            $terminalNamingSnapshot = (array) $current;
                            $terminalNamingFiles = $candidates;
                        }
                    }
                }

                return $insertedReleaseFiles;
            }, 3);
        } catch (\Throwable $exception) {
            $this->searchSyncCoordinator->discard($context->par2EvidenceSnapshot['id']);
            throw $exception;
        }

        $context->pendingReleaseFiles = [];
        $context->pendingParHashes = [];
        $context->pendingFullParHashes = [];
        if ($insertedReleaseFiles === null) {
            $context->fullPar2EvidenceDeferred = true;
            $this->searchSyncCoordinator->discard($context->par2EvidenceSnapshot['id']);

            return;
        }
        $context->releaseFilesChanged = $context->releaseFilesChanged || $insertedReleaseFiles > 0;

        $this->searchSyncCoordinator->request((int) $context->release->id);
        if ($terminalNamingSnapshot !== null) {
            $this->matchFinalizedFileNames($terminalNamingSnapshot, $terminalNamingFiles, $sourceDigest);
        }
    }

    /**
     * Whether a fully read archive inventory proves the release's naming scope. A partial publication's NZB
     * lacks files that were never seen, so it proves nothing about the whole upload: one episode of a
     * season pack is not a single-episode release.
     */
    public static function archiveNamingIsAuthoritative(ReleaseProcessingContext $context): bool
    {
        return $context->archiveNamingInspectionComplete && ! $context->archiveNameEvidenceIncomplete
            && ! (Release::hasPartialArticleCounts()
                && DB::table('releases')->where('id', $context->release->id)->whereNotNull('articles_expected')->exists());
    }

    /**
     * A new ordinary consumer observes the committed terminal state. It never
     * reuses the producer claim that finalization just released. Freeze values
     * before leaving the transaction so a later worker cannot refresh this
     * attempt into ownership of its successor's identity or source generation.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $names
     */
    private function matchFinalizedFileNames(array $snapshot, array $names, string $sourceDigest): void
    {
        $consume = function () use ($snapshot, $names, $sourceDigest): void {
            try {
                $entry = new Release;
                $entry->setRawAttributes($snapshot, true);
                $entry->setAttribute('releases_id', $snapshot['id']);
                $entry->setAttribute('observed_isrenamed', (int) $snapshot['isrenamed']);
                $entry->setAttribute('observed_additional_pp_claim', [
                    'token' => $snapshot[AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN],
                    'claimed_at' => $snapshot[AdditionalCandidateQuery::CLAIMED_AT_COLUMN],
                ]);
                $entry->setAttribute('observed_archive_source_digest', $sourceDigest);
                foreach (array_slice($names, 0, self::DEFAULT_RELEASE_FILE_LIMIT) as $name) {
                    if ($name === '' || str_starts_with($name, '.')
                        || preg_match('/'.$this->config->supportFileRegex.'$/i', $name) === 1) {
                        continue;
                    }
                    $entry->setAttribute('filename', $name);
                    $this->nameFixingService->reset();
                    if ($this->nameFixingService->matchPreDbFiles($entry, true, true, true) > 0) {
                        return;
                    }
                }
            } catch (\Throwable $exception) {
                // Metadata is already committed. Neither matching nor logging
                // failure may masquerade as a finalization rollback.
                $this->reportFinalizedNamingFailure((int) $snapshot['id'], $exception);
            }
        };
        try {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($consume);
            } else {
                $consume();
            }
        } catch (\Throwable $exception) {
            $this->reportFinalizedNamingFailure((int) $snapshot['id'], $exception);
        }
    }

    private function reportFinalizedNamingFailure(int $releaseId, \Throwable $exception): void
    {
        try {
            Log::warning('Post-finalization filename matching failed.', [
                'release_id' => $releaseId, 'exception_class' => $exception::class,
            ]);
        } catch (\Throwable) {
            // Logging must not interrupt an outer transaction's callbacks.
        }
    }

    /**
     * Handle a release that has timed out during post-processing.
     *
     * Increments the timeout counter on the release. If the counter reaches
     * the configured maximum, the release is deleted entirely. Otherwise,
     * the release is marked as processed (haspreview=0, passwordstatus=0)
     * to remove it from the re-selection query.
     *
     * @return bool True if the release was deleted, false if it was skipped
     */
    public function handleReleaseTimeout(Release $release, int $maxTimeoutCount): bool
    {
        $currentCount = (int) ($release->pp_timeout_count ?? 0);
        $newCount = $currentCount + 1;

        if ($newCount >= $maxTimeoutCount) {
            Log::warning('Release '.$release->id.' deleted after '.$newCount.' post-processing timeout(s)');
            $this->deleteRelease($release);

            return true;
        }

        // Increment the timeout counter and mark as processed to skip re-selection
        Release::query()->where('id', $release->id)->update(array_merge([
            'pp_timeout_count' => $newCount,
            'haspreview' => 0,
            'passwordstatus' => ReleaseBrowseService::PASSWD_NONE,
        ], AdditionalCandidateQuery::claimResetValues()));

        $this->searchSyncCoordinator->request((int) $release->id);

        Log::warning('Release '.$release->id.' skipped after post-processing timeout ('.$newCount.'/'.$maxTimeoutCount.')');

        return false;
    }

    /**
     * Delete a broken release completely.
     */
    public function deleteRelease(Release $release): void
    {
        try {
            if (empty($release->id)) {
                return;
            }

            $id = (int) $release->id;
            $guid = $release->guid ?? '';
            $this->searchSyncCoordinator->discard($id);

            // Delete NZB file
            try {
                $this->nzb->deleteNzb($guid);
            } catch (\Throwable) {
                // Ignore
            }

            // Delete preview assets
            try {
                $files = [$this->releaseImage->vidSavePath.$guid.'.ogv'];
                foreach (['webp', 'jpg', 'jpeg'] as $extension) {
                    $files[] = $this->releaseImage->imgSavePath.$guid.'_thumb.'.$extension;
                    $files[] = $this->releaseImage->jpgSavePath.$guid.'_thumb.'.$extension;
                }
                foreach ($files as $file) {
                    if (File::exists($file)) {
                        File::delete($file);
                    }
                }
            } catch (\Throwable) {
                // Ignore
            }

            // Delete related database rows
            try {
                ReleaseFile::where('releases_id', $id)->delete();
            } catch (\Throwable) {
            }

            try {
                MediaInfoModel::where('releases_id', $id)->delete();
            } catch (\Throwable) {
            }

            // Delete from search index
            try {
                Search::deleteRelease($id);
            } catch (\Throwable) {
                // Ignore
            }

            // Delete release row
            try {
                Release::where('id', $id)->delete();
            } catch (\Throwable) {
            }
        } catch (\Throwable) {
            // Last resort: swallow any exception
        }
    }

    /**
     * Process PAR2 file for file info and release name matching.
     */
    public function processPar2File(
        string $fileLocation,
        ReleaseProcessingContext $context,
        Par2Info $par2Info
    ): bool {
        $this->nameFixingService->reset();
        $par2Info->open($fileLocation);

        if ($par2Info->error) {
            return false;
        }

        $releaseInfo = Release::query()
            ->where('id', $context->release->id)
            ->select(['postdate', 'proc_pp'])
            ->first();

        if ($releaseInfo === null) {
            return false;
        }

        $postDate = Carbon::createFromFormat('Y-m-d H:i:s', $releaseInfo->postdate)->getTimestamp();

        // Only get new name if category is OTHER
        $foundName = true;
        if ((int) $releaseInfo->proc_pp === 0 && $this->config->renamePar2
            && in_array((int) $context->release->categories_id, Category::OTHERS_GROUP, false)
        ) {
            $foundName = false;
        }

        $this->queueFullPar2Evidence($fileLocation, $context, $par2Info);
        $filesAdded = 0;
        $fileLimit = $this->releaseFileLimit($context);

        foreach ((new Par2FileList)->getFiles($par2Info) as $file) {
            if (! isset($file['name'])) {
                continue;
            }

            if ($foundName && $filesAdded >= $fileLimit) {
                break;
            }

            // Add to release files
            if ($this->config->addPAR2Files) {
                if ($filesAdded < $fileLimit) {
                    if ($this->queueReleaseFile(
                        $context,
                        $context->release->id,
                        $file['name'],
                        $file['size'] ?? 0,
                        $postDate,
                        0,
                        $file['hash_16K'] ?? ''
                    )) {
                        $filesAdded++;
                    }
                }
            } else {
                $filesAdded++;
            }

            // Try to get a new name
            if (! $foundName) {
                $context->release->textstring = $file['name'];
                $matched = false;
                $committed = $this->applyProducerName($context, $this->nameFixingService, function (Release $entry) use ($file, &$matched): void {
                    $entry->textstring = $file['name'];
                    $matched = $this->nameFixingService->checkName($entry, $this->config->echoCLI, 'PAR2, ', true, true);
                });
                // Retain a pending transaction's chosen name without advancing the context before commit.
                if ($committed || ($matched && DB::transactionLevel() > 0)) {
                    $foundName = true;
                }
            }
        }

        $context->foundPAR2Info = true;

        return true;
    }

    /**
     * @param  Builder<Release>  $query
     * @param  array<string, mixed>  $values
     */
    private function commitFullPar2FinalizationUpdate(ReleaseProcessingContext $context, Builder $query, array $values, bool $guarded, bool $allowNoMatch = false): int
    {
        if (! $guarded) {
            return $query->update($values);
        }
        if (! $this->ownsFullPar2Evidence($context)) {
            throw new \RuntimeException('Additional-processing evidence ownership changed before final status update.');
        }
        $snapshot = $context->fullPar2EvidenceSnapshot();
        $locked = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()->first();
        if ($locked === null) {
            throw new \RuntimeException('Additional-processing release disappeared before final status update.');
        }
        foreach ($snapshot as $column => $value) {
            if ($column === AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN && ! property_exists($locked, $column)) {
                continue;
            }
            if ($value === null) {
                $query->whereNull($column);
            } elseif ($column === 'id') {
                $query->where($column, $value);
            } else {
                $query->whereRaw('HEX('.$column.') = ?', [strtoupper(bin2hex($value))]);
            }
        }
        if (property_exists($locked, AdditionalCandidateQuery::CLAIMED_AT_COLUMN)) {
            $stamp = $locked->{AdditionalCandidateQuery::CLAIMED_AT_COLUMN};
            if ($stamp === null) {
                $query->whereNull(AdditionalCandidateQuery::CLAIMED_AT_COLUMN);
            } else {
                $query->where(AdditionalCandidateQuery::CLAIMED_AT_COLUMN, $stamp);
            }
            if ($snapshot[AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN] === null || $snapshot[AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN] === '') {
                $query->whereNull(AdditionalCandidateQuery::CLAIMED_AT_COLUMN);
            } else {
                $query->where(AdditionalCandidateQuery::CLAIMED_AT_COLUMN, '>=', AdditionalCandidateQuery::claimStaleBefore()->toDateTimeString());
            }
        }
        if ($allowNoMatch && ! (clone $query)->exists()) {
            if (! $this->ownsFullPar2Evidence($context)) {
                throw new \RuntimeException('Additional-processing evidence ownership changed during final status admission.');
            }

            return 0;
        }
        $updated = $query->update($values);
        $current = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()->first();
        foreach ([...$snapshot, ...$values] as $column => $value) {
            $actual = $current->{$column} ?? null;
            if (is_int($value)) {
                $actual = (int) $actual;
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            if ($current === null || $actual !== $value) {
                throw new \RuntimeException('Additional-processing evidence ownership or terminal state changed during final status update.');
            }
        }

        if ((int) $context->release->id !== $snapshot['id'] || (string) $context->release->guid !== $snapshot['guid']) {
            throw new \RuntimeException('Additional-processing caller identity changed during final status update.');
        }

        return $updated;
    }

    /** @phpstan-impure */
    private function ownsFullPar2Evidence(ReleaseProcessingContext $context): bool
    {
        $snapshot = $context->fullPar2EvidenceSnapshot();
        if ((int) $context->release->id !== $snapshot['id'] || (string) $context->release->guid !== $snapshot['guid']) {
            return false;
        }
        $current = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()->first();
        if ($current === null) {
            return false;
        }
        foreach ($snapshot as $column => $value) {
            $actual = $current->{$column} ?? null;
            if ($column === 'id') {
                $actual = (int) $actual;
            }
            if ($actual !== $value) {
                return false;
            }
        }
        $token = $snapshot['additional_pp_claim_token'];
        $claimedAt = $current->additional_pp_claimed_at ?? null;
        if ($context->producerNamingAttempted && $context->producerNamingClaim !== null
            && $claimedAt !== $context->producerNamingClaim['claimed_at']) {
            return false;
        }
        if ($token === null || $token === '') {
            return $claimedAt === null;
        }

        if (! is_string($claimedAt)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $claimedAt);

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $claimedAt
            && $claimedAt >= AdditionalCandidateQuery::claimStaleBefore()->toDateTimeString();
    }

    private function queueFullPar2Evidence(string $fileLocation, ReleaseProcessingContext $context, Par2Info $par2Info): void
    {
        $packets = $par2Info->getPackets(true);
        if (! is_array($packets)) {
            return;
        }
        $stream = is_file($fileLocation) ? fopen($fileLocation, 'rb') : false;
        try {
            foreach ($packets as $packet) {
                if (($packet['head_type'] ?? null) !== Par2Info::PACKET_FILEDESC) {
                    continue;
                }
                $full = $packet['file_hash'] ?? '';
                $prefix = $packet['file_hash_16K'] ?? '';
                if (! is_string($full) || ! is_string($prefix) || strlen($full) !== 32 || strlen($prefix) !== 32
                    || ! ctype_xdigit($full) || ! ctype_xdigit($prefix)) {
                    continue;
                }
                $size = Par2FileHashEvidence::sizeDecimal($packet['file_length'] ?? null);
                $offset = $packet['offset'] ?? null;
                $length = $packet['head_length'] ?? null;
                if (is_resource($stream) && is_int($offset) && $offset >= 0 && is_int($length) && $length >= 120
                    && fseek($stream, $offset) === 0) {
                    $fixed = fread($stream, 120);
                    if (is_string($fixed) && strlen($fixed) === 120 && substr($fixed, 0, 8) === "PAR2\0PKT") {
                        $checksum = hash_init('md5');
                        hash_update($checksum, substr($fixed, 32));
                        $remaining = $length - 120;
                        while ($remaining > 0) {
                            $chunk = fread($stream, min(16384, $remaining));
                            if (! is_string($chunk) || $chunk === '') {
                                break;
                            }
                            hash_update($checksum, $chunk);
                            $remaining -= strlen($chunk);
                        }
                        if ($remaining === 0 && hash_final($checksum) === ($packet['head_hash'] ?? null)) {
                            $size = Par2FileHashEvidence::littleEndianSize(substr($fixed, 112, 8));
                        }
                    }
                }
                $full = strtolower($full);
                $prefix = strtolower($prefix);
                $context->pendingFullParHashes[$full.':'.$prefix.':'.($size ?? '')] = [
                    'full_md5' => $full, 'hash16k' => $prefix, 'size' => $size,
                ];
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Process NFO file with enhanced detection capabilities.
     *
     * Supports multiple NFO naming conventions:
     * - Standard: .nfo, .diz, .info
     * - Alternative: file_id.diz, readme.txt, info.txt
     * - Scene-style: 00-groupname.nfo, groupname-releasename.nfo
     */
    public function processNfoFile(
        string $fileLocation,
        ReleaseProcessingContext $context,
        NNTPService $nntp
    ): bool {
        try {
            $data = File::get($fileLocation);

            // Try to detect and convert encoding
            $data = $this->normalizeNfoEncoding($data);

            if ($this->nfo->isNFO($data, $context->release->guid)
                && $this->nfo->addAlternateNfo($data, $context->release, $nntp)
            ) {
                $context->releaseHasNoNFO = false;

                return true;
            }
        } catch (FileNotFoundException $e) {
            Log::warning("Could not read potential NFO file: {$fileLocation} - {$e->getMessage()}");
        }

        return false;
    }

    /**
     * Check if a filename looks like an NFO file.
     *
     * Retains the native PHP path in production: paired single-call benchmarks measured
     * 1.25x-1.61x mean and 1.40x-2.00x p99, inconsistently below the 1.5x threshold on the
     * uncommon mean and common p99; see isNfoFilenameNative().
     *
     * @param  string  $filename  The filename to check.
     * @return bool True if the filename matches NFO patterns.
     */
    public function isNfoFilename(string $filename): bool
    {
        return self::isNfoFilenamePhp($filename);
    }

    public static function isNfoFilenamePhp(string $filename): bool
    {
        // Standard NFO extensions
        if (preg_match('/\.(?:nfo|diz|info?)$/i', $filename)) {
            return true;
        }

        // Alternative NFO filenames
        $nfoPatterns = [
            '/^(?:file[_-]?id|readme|release|info(?:rmation)?|about|notes?)\.(?:txt|diz)$/i',
            '/^00-[a-z0-9_-]+\.nfo$/i',           // Scene: 00-group.nfo
            '/^0+-[a-z0-9_-]+\.nfo$/i',           // Scene variations
            '/^[a-z0-9_-]+-[a-z0-9_.-]+\.nfo$/i', // Scene: group-release.nfo
            '/info\.txt$/i',                      // info.txt (common alternative)
        ];

        $basename = basename($filename);
        foreach ($nfoPatterns as $pattern) {
            if (preg_match($pattern, $basename)) {
                return true;
            }
        }

        return false;
    }

    public static function isNfoFilenameNative(string $filename, bool &$supported = false, ?string $libraryPath = null): bool
    {
        $supported = false;
        $ffi = self::$useRustFfi && $libraryPath === null && self::$ffiResolved ? self::$ffi : self::rustFfi($libraryPath);
        if ($ffi === null) {
            return self::isNfoFilenamePhp($filename);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_rfm_is_nfo_filename($filename, strlen($filename));
        } catch (\Throwable) {
            return self::isNfoFilenamePhp($filename);
        }

        if (! is_int($status) || $status < 0) {
            return self::isNfoFilenamePhp($filename);
        }
        $supported = true;

        return $status === 1;
    }

    /**
     * Toggle the Rust helpers; disabled, every *Native method falls back to PHP.
     */
    public static function useRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalize NFO encoding to UTF-8.
     *
     * NFO files often use CP437 (DOS) encoding for ASCII art.
     * This method attempts to detect and convert various encodings.
     *
     * @param  string  $data  Raw NFO data.
     * @return string UTF-8 encoded NFO data.
     */
    protected function normalizeNfoEncoding(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $native = RustPipelineLibrary::normalizeNfoEncoding($data);
        if ($native !== null) {
            return $native;
        }

        return $this->normalizeNfoEncodingPhp($data);
    }

    protected function normalizeNfoEncodingPhp(string $data): string
    {
        // Check for UTF-8 BOM and remove it
        if (str_starts_with($data, "\xEF\xBB\xBF")) {
            $data = substr($data, 3);
        }

        // Check for UTF-16 BOM
        if (str_starts_with($data, "\xFF\xFE")) {
            // UTF-16 LE
            $data = mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($data, "\xFE\xFF")) {
            // UTF-16 BE
            $data = mb_convert_encoding(substr($data, 2), 'UTF-8', 'UTF-16BE');
        }

        // If already valid UTF-8, return as-is
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }

        // Try CP437 (DOS encoding - common for scene NFOs with ASCII art)
        // Use the cp437toUTF helper function
        if (function_exists('cp437toUTF')) {
            return (string) iconv('CP437', 'UTF-8//IGNORE//TRANSLIT', $data);
        }

        // Fallback: try ISO-8859-1 (Latin-1)
        $converted = @mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        if ($converted !== false) {
            return $converted;
        }

        // Last resort: force UTF-8 with error handling
        return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
    }

    /**
     * Match listed file names to PreDB, as addFileInfo() does per file, for names that
     * were held back while the release might still turn out to be a season pack.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    public function matchFileNamesToPreDb(array $files, ReleaseProcessingContext $context): void
    {
        if ($context->defersArchiveNameChecks()) {
            return;
        }

        foreach (array_slice($files, 0, self::DEFAULT_RELEASE_FILE_LIMIT) as $file) {
            $name = (string) ($file['name'] ?? '');
            if ($name === '' || str_starts_with($name, '.')
                || preg_match('/'.$this->config->supportFileRegex.'$/i', $name) === 1
            ) {
                continue;
            }

            $matched = 0;
            $accepted = $this->applyProducerName($context, $this->nameFixingService, function (Release $entry) use ($name, $context, &$matched): void {
                $entry->setAttribute('filename', $name);
                $matched = $this->nameFixingService->matchPreDbFiles($entry, true, true, true);
                $context->rememberArchiveFilenameNamingAttempt($name, $this->nameFixingService->wasEpisodeNamingDeferredByArchiveProof());
            });
            if ($accepted || $matched > 0 || $context->fullPar2EvidenceDeferred) {
                return;
            }
        }
    }

    /**
     * Handle release name extraction from RAR file content.
     *
     * @param  array<string, mixed>  $dataSummary
     */
    public function processReleaseNameFromRar(
        array $dataSummary,
        ReleaseProcessingContext $context
    ): void {
        // One inner file names one episode, not the whole season pack
        if ($context->defersArchiveNameChecks()) {
            return;
        }

        $fileData = $dataSummary['file_list'] ?? [];
        if (empty($fileData)) {
            return;
        }

        $rarFileName = array_column($fileData, 'name');
        if (empty($rarFileName[0])) {
            return;
        }

        $extractedName = $this->extractReleaseNameFromFile($rarFileName[0]);

        if ($extractedName !== null) {
            $preCheck = Predb::whereTitle($extractedName)->first();
            if ($preCheck !== null && PredbSearchDocument::exactValue($preCheck->title) !== PredbSearchDocument::exactValue($extractedName)) {
                $preCheck = null;
            }
            $preId = $preCheck?->id ?? 0;
            $candidate = $preCheck?->title ?? $this->normalizeCandidateTitle($extractedName);

            if ($this->isPlausibleReleaseTitle($candidate)) {
                $this->applyProducerName($context, $this->releaseUpdateService, function (Release $entry) use ($candidate, $preId): void {
                    $this->releaseUpdateService->updateRelease(
                        $entry,
                        $candidate,
                        'RarInfo FileName Match',
                        true,
                        'Filenames, ',
                        true,
                        true,
                        $preId
                    );
                });
            } elseif ($this->config->debugMode) {
                Log::debug('RarInfo: Ignored low-quality candidate "'.$candidate.'" from inner file name.');
            }
        } elseif (! empty($dataSummary['archives'][$rarFileName[0]]['file_list'])) {
            // Try nested archive
            $archiveData = $dataSummary['archives'][$rarFileName[0]]['file_list'];
            $archiveFileName = array_column($archiveData, 'name');
            $extractedName = $this->extractReleaseNameFromFile($archiveFileName[0] ?? '');

            if ($extractedName !== null) {
                $preCheck = Predb::whereTitle($extractedName)->first();
                if ($preCheck !== null && PredbSearchDocument::exactValue($preCheck->title) !== PredbSearchDocument::exactValue($extractedName)) {
                    $preCheck = null;
                }
                $preId = $preCheck?->id ?? 0;
                $candidate = $preCheck?->title ?? $this->normalizeCandidateTitle($extractedName);

                if ($this->isPlausibleReleaseTitle($candidate)) {
                    $this->applyProducerName($context, $this->releaseUpdateService, function (Release $entry) use ($candidate, $preId): void {
                        $this->releaseUpdateService->updateRelease(
                            $entry,
                            $candidate,
                            'RarInfo FileName Match',
                            true,
                            'Filenames, ',
                            true,
                            true,
                            $preId
                        );
                    });
                }
            }
        }
    }

    /**
     * Extract the release name from a filename.
     *
     * Retains the native PHP path in production (~0.90x common, ~1.20x uncommon mean in
     * paired benchmarks; FFI string marshaling dominates); see extractReleaseNameFromFileNative().
     */
    private function extractReleaseNameFromFile(string $filename): ?string
    {
        return $this->extractReleaseNameFromFilePhp($filename);
    }

    public function extractReleaseNameFromFilePhp(string $filename): ?string
    {
        $basename = basename($filename);

        $unwrapped = $this->fileNameCleaner->extractNzbSplitName($basename);
        if ($unwrapped !== null) {
            return $unwrapped;
        }

        $cleaned = preg_replace(
            '/\.(mkv|avi|mp4|m4v|mpg|mpeg|wmv|flv|mov|ts|vob|iso|divx|par2?|nfo|sfv|nzb|rar|zip|r\d{2,3}|pkg|exe|msi)$/i',
            '',
            $basename
        );

        if (preg_match('/^(.+[-.][A-Za-z0-9_]{2,})$/i', $cleaned, $match)) {
            return ucwords($match[1], '.-_ ');
        }

        if (preg_match(ReleaseUpdateService::PREDB_REGEX, $cleaned, $hit)) {
            return ucwords($hit[0], '.');
        }

        return null;
    }

    public function extractReleaseNameFromFileNative(string $filename, bool &$supported = false, ?string $libraryPath = null): ?string
    {
        $supported = false;
        // The fused export embeds the default cleaner's NZBSPLIT unwrapping.
        $ffi = $this->fileNameCleaner::class === FileNameCleaner::class ? self::rustFfi($libraryPath) : null;
        if ($ffi === null) {
            return $this->extractReleaseNameFromFilePhp($filename);
        }

        $len = strlen($filename);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $len + 16, $cap);
        if ($scratch === null) {
            return $this->extractReleaseNameFromFilePhp($filename);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_rfm_extract_release_name($filename, $len, $scratch, $cap);
        } catch (\Throwable) {
            return $this->extractReleaseNameFromFilePhp($filename);
        }

        if ($written === -3) {
            $supported = true;

            return null;
        }
        if (! is_int($written) || $written < 0) {
            return $this->extractReleaseNameFromFilePhp($filename);
        }
        $supported = true;

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Normalize a candidate title.
     */
    private function normalizeCandidateTitle(string $title): string
    {
        return $this->fileNameCleaner->normalizeCandidateTitle($title);
    }

    private function queueReleaseFile(
        ReleaseProcessingContext $context,
        int|string $releaseId,
        string $name,
        int|string $size,
        mixed $createdTime,
        mixed $hasPassword,
        string $hash = '',
        string $crc = ''
    ): bool {
        if ($name === '' || (int) $releaseId !== (int) $context->release->id) {
            return false;
        }

        $context->rememberNamingWitness($name);
        $this->loadExistingReleaseFileNames($context);
        if ($this->config->addPAR2Files && strlen($hash) === 32 && ctype_xdigit($hash)) {
            $normalizedHash = strtolower($hash);
            $context->pendingParHashes[$normalizedHash] = [
                'releases_id' => (int) $context->release->id,
                'hash' => $normalizedHash,
            ];
        }

        if (isset($context->existingReleaseFileNames[$name]) || isset($context->pendingReleaseFiles[$name])) {
            return false;
        }

        $context->pendingReleaseFiles[$name] = [
            'releases_id' => (int) $releaseId,
            'name' => $name,
            'size' => (int) $size,
            'created_at' => $this->normalizeCreatedTime($createdTime),
            'updated_at' => now()->timestamp,
            'passworded' => (int) $hasPassword,
            'crc32' => $crc,
        ];
        $context->existingReleaseFileNames[$name] = true;
        $context->addedFileInfo++;

        return true;
    }

    private function loadExistingReleaseFileNames(ReleaseProcessingContext $context): void
    {
        if ($context->existingReleaseFileNames !== null) {
            return;
        }

        $existingNames = ReleaseFile::query()
            ->where('releases_id', $context->release->id)
            ->pluck('name')
            ->all();

        $context->existingReleaseFileNames = [];
        foreach ($existingNames as $name) {
            $context->existingReleaseFileNames[(string) $name] = true;
        }
    }

    private function normalizeCreatedTime(mixed $createdTime): mixed
    {
        if (! is_int($createdTime)) {
            return $createdTime;
        }

        if ($createdTime === 0) {
            return now()->format('Y-m-d H:i:s');
        }

        return Carbon::createFromTimestamp($createdTime, date_default_timezone_get())->format('Y-m-d H:i:s');
    }

    /**
     * Retains the native PHP path in production (~0.90x mean in paired benchmarks); see
     * normalizeFileSizeNative().
     */
    private function normalizeFileSize(mixed $size): ?int
    {
        return self::normalizeFileSizePhp($size);
    }

    public static function normalizeFileSizePhp(mixed $size): ?int
    {
        if (is_int($size)) {
            return $size >= 0 ? $size : null;
        }

        if (is_float($size)) {
            return is_finite($size) && $size >= 0 && $size < PHP_INT_MAX
                ? (int) $size
                : null;
        }

        if (! is_string($size) || preg_match('/^\d+$/D', $size) !== 1) {
            return null;
        }

        $normalizedSize = ltrim($size, '0');
        if ($normalizedSize === '') {
            return 0;
        }

        $maximumSize = (string) PHP_INT_MAX;
        if (strlen($normalizedSize) > strlen($maximumSize)
            || (strlen($normalizedSize) === strlen($maximumSize) && strcmp($normalizedSize, $maximumSize) > 0)
        ) {
            return null;
        }

        return (int) $normalizedSize;
    }

    public static function normalizeFileSizeNative(mixed $size, bool &$supported = false, ?string $libraryPath = null): ?int
    {
        $supported = false;
        if (! is_string($size)) {
            return self::normalizeFileSizePhp($size);
        }
        $ffi = self::rustFfi($libraryPath);
        if ($ffi === null) {
            return self::normalizeFileSizePhp($size);
        }

        try {
            $out = self::$fileSizeOut ??= $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_rfm_normalize_file_size($size, strlen($size), $out);
        } catch (\Throwable) {
            return self::normalizeFileSizePhp($size);
        }

        if (! is_int($status) || $status < 0) {
            return self::normalizeFileSizePhp($size);
        }
        $supported = true;

        return $status === 1 ? (int) $out[0] : null;
    }

    private static function rustFfi(?string $libraryPath = null): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('release_file_manager', self::FFI_CDEF, $libraryPath);
        }
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('release_file_manager', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    private function releaseHasNzbSplitWrapper(Release $release): bool
    {
        $name = (string) ($release->name ?? '');
        $searchName = (string) ($release->searchname ?? '');

        return str_contains(strtoupper($name), 'NZBSPLIT')
            || str_contains(strtoupper($searchName), 'NZBSPLIT');
    }

    /**
     * Check if a title is plausible for release naming.
     */
    /**
     * Inner files stored per release; a season pack lists more so every episode shows.
     */
    private function releaseFileLimit(ReleaseProcessingContext $context): int
    {
        return $context->seasonPack ? $this->config->seasonPackMaxReleaseFiles : self::DEFAULT_RELEASE_FILE_LIMIT;
    }

    private function isPlausibleReleaseTitle(string $title): bool
    {
        return $this->fileNameCleaner->isPlausibleReleaseTitle($title);
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
        } catch (\Throwable) {
            return null;
        }
    }

    private static function w426Add(int $a, int $b): int
    {
        return $a > PHP_INT_MAX - $b ? PHP_INT_MAX : $a + $b;
    }

    /**
     * Report file counts and byte totals without database writes. Empty names and negative sizes are invalid.
     * Integer sums saturate at PHP_INT_MAX; malformed rows are counted without coercion.
     *
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, files: int, total_bytes: int, empty_files: int, name_bytes: int, exceeds_limit: bool, invalid: int}
     */
    public static function summarizeFilePersistenceBatch(array $files, int $limit = 11): array
    {
        return self::usesW426RustFfi()
            ? self::summarizeFilePersistenceBatchNative($files, $limit)
            : self::summarizeFilePersistenceBatchPhp($files, $limit);
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, files: int, total_bytes: int, empty_files: int, name_bytes: int, exceeds_limit: bool, invalid: int}
     */
    public static function summarizeFilePersistenceBatchPhp(array $files, int $limit = 11): array
    {
        $result = ['total' => \count($files), 'files' => 0, 'total_bytes' => 0, 'empty_files' => 0, 'name_bytes' => 0, 'exceeds_limit' => false, 'invalid' => 0];
        foreach ($files as $row) {
            if (! self::w426ValidRow($row)) {
                $result['invalid']++;

                continue;
            }
            $result['files']++;
            $result['total_bytes'] = self::w426Add($result['total_bytes'], $row['size']);
            $result['empty_files'] += (int) ($row['size'] === 0);
            $result['name_bytes'] = self::w426Add($result['name_bytes'], \strlen($row['name']));
        }
        $result['exceeds_limit'] = $result['files'] > max(0, $limit);

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, files: int, total_bytes: int, empty_files: int, name_bytes: int, exceeds_limit: bool, invalid: int}
     */
    public static function summarizeFilePersistenceBatchNative(array $files, int $limit = 11): array
    {
        $total = \count($files);
        if (PHP_INT_SIZE !== 8 || $total > 100_000) {
            return self::summarizeFilePersistenceBatchPhp($files, $limit);
        }
        $frame = pack('qV', $limit, $total);
        foreach ($files as $row) {
            if (! self::w426ValidRow($row)) {
                return self::summarizeFilePersistenceBatchPhp($files, $limit);
            }
            if (\strlen($row['name']) > 16_777_216 - 12 - \strlen($frame)) {
                return self::summarizeFilePersistenceBatchPhp($files, $limit);
            }
            $frame .= pack('qV', $row['size'], \strlen($row['name'])).$row['name'];
        }
        $slots = self::w426Slots(1, $frame, 7);
        if ($slots === null || $slots[0] !== $total || $slots[1] !== $total || $slots[3] > $total || $slots[5] !== (int) ($total > max(0, $limit)) || $slots[6] !== 0) {
            return self::summarizeFilePersistenceBatchPhp($files, $limit);
        }

        return ['total' => $slots[0], 'files' => $slots[1], 'total_bytes' => $slots[2], 'empty_files' => $slots[3], 'name_bytes' => $slots[4], 'exceeds_limit' => $slots[5] === 1, 'invalid' => $slots[6]];
    }

    /** @phpstan-assert-if-true array{name: string, size: int} $row */
    private static function w426ValidRow(mixed $row): bool
    {
        return \is_array($row) && \is_string($row['name'] ?? null) && $row['name'] !== '' && \is_int($row['size'] ?? null) && $row['size'] >= 0;
    }

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 5.57-5.71x, p99 4.13-5.05x; uncommon mean 5.50-5.64x, p99 4.21-5.33x); see RustReleaseFileArchiveProcessorSyncUsenetW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        ssize_t nntmux_w523_release_file_name_size_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW52RustFfi = null;

    private static FFI|false|null $w52Ffi = null;

    private static ?CData $w52Out = null;

    private static int $w52OutCap = 0;

    public static function usesW52RustFfi(): bool
    {
        return self::$useW52RustFfi ?? self::W52_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 52 Rust path forced on or off, then restores the previous setting. */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW52RustFfi;
        self::$useW52RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW52RustFfi = $previous;
        }
    }

    /**
     * Classifies inner archive file rows: one `[flags, size]` pair per row, where flags are 1 for
     * an isNfoFilenamePhp() name and 2 for a valid size, and size is normalizeFileSizePhp() of the
     * size string (-1 when invalid). Then the NFO and valid-size counts. A missing size is ''.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $sizes
     * @return list<int>
     */
    public static function w52FileNameSizeBatch(array $names, array $sizes): array
    {
        return self::usesW52RustFfi() ? self::w52FileNameSizeBatchNative($names, $sizes) : self::w52FileNameSizeBatchPhp($names, $sizes);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $sizes
     * @return list<int>
     */
    public static function w52FileNameSizeBatchNative(array $names, array $sizes): array
    {
        $count = \count($names);
        if (\count($sizes) !== $count) {
            return self::w52FileNameSizeBatchPhp($names, $sizes);
        }
        $blob = $count === 0 ? '' : implode("\0", $names)."\0".implode("\0", $sizes);

        return self::w52Invoke($blob, '', $count, 2 * $count + 2)
            ?? self::w52FileNameSizeBatchPhp($names, $sizes);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $sizes
     * @return list<int>
     */
    public static function w52FileNameSizeBatchPhp(array $names, array $sizes): array
    {
        $sizes = array_values($sizes);
        $out = [];
        $nfo = 0;
        $valid = 0;
        foreach (array_values($names) as $i => $name) {
            $isNfo = self::isNfoFilenamePhp((string) $name);
            $size = self::normalizeFileSizePhp((string) ($sizes[$i] ?? ''));
            $nfo += (int) $isNfo;
            $valid += (int) ($size !== null);
            $out[] = (int) $isNfo | ($size !== null ? 2 : 0);
            $out[] = $size ?? -1;
        }
        $out[] = $nfo;
        $out[] = $valid;

        return $out;
    }

    /**
     * Calls the Wave 52 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing, the call declines (for example a string holding a NUL byte) or it writes
     * more than $cap words, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w52Invoke(string $blob, string $ints, int $count, int $cap): ?array
    {
        self::$w52Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w52', self::W52_FFI_CDEF) ?? false;
        $ffi = self::$w52Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w52Out === null || self::$w52OutCap < $cap) {
                self::$w52OutCap = max(1024, $cap * 2);
                self::$w52Out = $ffi->new('int64_t['.self::$w52OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w523_release_file_name_size_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w52Out, self::$w52OutCap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w52Out, $written * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }

    // ---- Wave 65.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 9.80x/8.74x, uncommon 10.28x/7.59x); see RustAdditionalNzbParserFileManagerArchiveFallbackProcessorSyncW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_RELEASE_FILE_MANAGER_FFI_CDEF = <<<'C'
        intptr_t nntmux_w652_release_file_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W65_RELEASE_FILE_MANAGER_TAIL = 26;

    /**
     * Wave 65 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w65', self::W65_RELEASE_FILE_MANAGER_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w65RustFfiState('ffi');
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

    /** Routes w65ReleaseFileManagerPlanBatch() to Rust until reset. */
    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    /** Routes w65ReleaseFileManagerPlanBatch() to PHP until reset. */
    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores W65_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $enabled = self::w65RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w65ReleaseFileManagerPlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
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
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w65ReleaseFileManagerPlanBatch(array $rows): array
    {
        return self::usesW65RustFfi() ? self::w65ReleaseFileManagerPlanBatchNative($rows) : self::w65ReleaseFileManagerPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w65ReleaseFileManagerPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W65_RELEASE_FILE_MANAGER_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w65RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w65ReleaseFileManagerPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W65_RELEASE_FILE_MANAGER_FFI_CDEF.)
            $written = $ffi->nntmux_w652_release_file_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w65ReleaseFileManagerPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w65Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w65Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** 0 empty, 1 passworded (yes, 1, true), 2 clear (no, 0, false), 3 other; trimmed, case-insensitive. */
    private static function w65Password(string $value): int
    {
        $value = strtolower(trim($value));

        return match ($value) {
            '' => 0,
            'yes', '1', 'true' => 1,
            'no', '0', 'false' => 2,
            default => 3,
        };
    }

    /** Inserts one release may take per batch (DEFAULT_RELEASE_FILE_LIMIT). */
    private const int W65_FILE_LIMIT = 11;

    /**
     * 0 empty, 1 valid (an optional `0x`/`0X` and 1 to 8 hex digits) with its value, 2 invalid.
     *
     * @return array{int, int}
     */
    private static function w65Crc(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [0, 0];
        }
        $hex = \strlen($value) >= 2 && $value[0] === '0' && ($value[1] === 'x' || $value[1] === 'X') ? substr($value, 2) : $value;
        $length = \strlen($hex);

        return $length >= 1 && $length <= 8 && strspn($hex, '0123456789abcdefABCDEF') === $length ? [1, (int) hexdec($hex)] : [2, 0];
    }

    /**
     * 0 empty, 1 Unix seconds (1 to 10 digits), 2 `YYYY-MM-DD[ T]HH:MM:SS` (years 1970-2099, days
     * 1-31, read as UTC), 3 invalid; with the epoch seconds.
     *
     * @return array{int, int}
     */
    private static function w65Timestamp(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [0, 0];
        }
        $length = \strlen($value);
        if ($length <= 10 && strspn($value, '0123456789') === $length) {
            return [1, (int) $value];
        }
        if ($length !== 19 || $value[4] !== '-' || $value[7] !== '-' || ($value[10] !== ' ' && $value[10] !== 'T') || $value[13] !== ':' || $value[16] !== ':') {
            return [3, 0];
        }
        $parts = [];
        foreach ([[0, 4], [5, 2], [8, 2], [11, 2], [14, 2], [17, 2]] as [$at, $size]) {
            $part = substr($value, $at, $size);
            if (strspn($part, '0123456789') !== $size) {
                return [3, 0];
            }
            $parts[] = (int) $part;
        }
        [$year, $month, $day, $hour, $minute, $second] = $parts;
        if ($year < 1970 || $year > 2099 || $month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 59) {
            return [3, 0];
        }
        // Days from the civil date (proleptic Gregorian, March-based years).
        $y = $month <= 2 ? $year - 1 : $year;
        $era = intdiv($y, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($month > 2 ? $month - 3 : $month + 9) + 2, 5) + $day - 1;
        $days = $era * 146_097 + $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy - 719_468;

        return [2, $days * 86_400 + $hour * 3_600 + $minute * 60 + $second];
    }

    /**
     * Wave 65.2 release file plan. Per `release_id\tname\tsize\tcrc32\ttimestamp\tpassworded\texisting_size`
     * row `action | crc_state << 3 | timestamp_state << 5 | password << 7 | size_missing << 9 | crc <<
     * 10 | min(strlen(name), 4095) << 42 | min(insert_ordinal, 511) << 54`. Release id, size and
     * existing size are w65Int() fields of 12 digits; the name is trimmed. Actions: 4 reject (no
     * positive release id, an empty name or an unparsable size), 3 skip a duplicate (release id and
     * lowercased name seen earlier), then 0 insert without an existing size (5 once the release
     * passes W65_FILE_LIMIT inserts; the ordinal counts them), 2 skip an unchanged size, 1 update.
     * See w65Crc(), w65Timestamp() and w65Password(). Then six action, three CRC state, four
     * timestamp state and four password counts, distinct valid releases, inserted bytes, updated
     * bytes, total update size change, the earliest and latest valid timestamp (0 without one),
     * name bytes, rows without a size and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w65ReleaseFileManagerPlanBatchPhp(array $rows): array
    {
        $out = $releases = $inserts = $seen = [];
        $actions = array_fill(0, 6, 0);
        $crcs = array_fill(0, 3, 0);
        $stamps = $passwords = array_fill(0, 4, 0);
        $insertBytes = $updateBytes = $deltaBytes = $nameBytes = $minTs = $maxTs = $missingRows = 0;
        foreach ($rows as $row) {
            [$rid, $name, $size, $crc, $ts, $pw, $existing] = explode("\t", (string) $row, 7) + ['', '', '', '', '', '', ''];
            $rid = self::w65Int($rid, 12);
            $size = self::w65Int($size, 12);
            $existing = self::w65Int($existing, 12);
            $name = trim($name);
            [$crcState, $crcValue] = self::w65Crc($crc);
            [$tsState, $tsValue] = self::w65Timestamp($ts);
            $password = self::w65Password($pw);
            $id = \is_int($rid) && $rid > 0 ? $rid : 0;
            $malformed = $id === 0 || $name === '' || $size === false || $existing === false;
            $missing = $size === null;
            $bytes = \is_int($size) ? $size : 0;
            $ordinal = 0;
            if ($malformed) {
                $action = 4;
            } else {
                $key = $id."\t".strtolower($name);
                if (isset($seen[$key])) {
                    $action = 3;
                } else {
                    $seen[$key] = true;
                    if ($existing === null) {
                        $ordinal = $inserts[$id] = ($inserts[$id] ?? 0) + 1;
                        $action = $ordinal > self::W65_FILE_LIMIT ? 5 : 0;
                    } elseif ($existing === $bytes) {
                        $action = 2;
                    } else {
                        $deltaBytes += abs($bytes - $existing);
                        $action = 1;
                    }
                }
                $releases[$id] = true;
            }
            if ($action === 0) {
                $insertBytes += $bytes;
            } elseif ($action === 1) {
                $updateBytes += $bytes;
            }
            if ($tsState === 1 || $tsState === 2) {
                if ($stamps[1] + $stamps[2] === 0) {
                    $minTs = $maxTs = $tsValue;
                } else {
                    $minTs = min($minTs, $tsValue);
                    $maxTs = max($maxTs, $tsValue);
                }
            }
            $actions[$action]++;
            $crcs[$crcState]++;
            $stamps[$tsState]++;
            $passwords[$password]++;
            $nameBytes += \strlen($name);
            $missingRows += (int) $missing;
            $out[] = $action | $crcState << 3 | $tsState << 5 | $password << 7 | (int) $missing << 9 | $crcValue << 10 | min(\strlen($name), 4095) << 42 | min($ordinal, 511) << 54;
        }
        array_push($out, ...$actions, ...$crcs, ...$stamps, ...$passwords);
        array_push($out, \count($releases), $insertBytes, $updateBytes, $deltaBytes, $minTs, $maxTs, $nameBytes, $missingRows);
        $out[] = self::w65Digest($out);

        return $out;
    }
}
