<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Models\UsenetGroup;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\Media\ArchivedMediaSlicer;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;
use App\Services\AdditionalProcessing\Media\MediaArticleFetcher;
use App\Services\AdditionalProcessing\Media\MediaSourceResolver;
use App\Services\AdditionalProcessing\Media\MediaSourceStage;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ProcessingMetrics;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\NameFixing\SeasonPackDetector;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\ArchiveInfo;
use dariusiii\rarinfo\SzipInfo;
use FFI;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Stateless release processor for additional post-processing.
 */
class ReleaseProcessor
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_release_parse_release_files_nul(const char *raw_ptr, size_t raw_len, char *out_ptr, size_t out_cap);
        ssize_t nntmux_release_normalize_subject(const char *ptr, size_t len, char *out_ptr, size_t out_cap);
        int32_t nntmux_release_contains_video_pattern(const char *subject_ptr, size_t subject_len, const char *video_regex_ptr, size_t video_regex_len);
        int32_t nntmux_release_has_usable_sample_filename(const char *ptr, size_t len);
        int32_t nntmux_release_is_auxiliary_nzb_subject(const char *subject_ptr, size_t subject_len, const char *support_regex_ptr, size_t support_regex_len);
        int32_t nntmux_release_subject_matches_release(const char *subject_ptr, size_t subject_len, const char *release_ptr, size_t release_len);
        C;

    private const string DEFAULT_AUDIO_FILE_REGEX = '\.(AAC|AIFF|APE|AC3|ASF|DTS|FLAC|MKA|MKS|MP2|MP3|RA|OGG|OGM|W64|WAV|WMA)';

    private const string DEFAULT_IGNORE_BOOK_REGEX = '/\b(epub|lit|mobi|pdf|sipdf|html)\b.*\.rar(?!.{20,})/i';

    private const string DEFAULT_SUPPORT_FILE_REGEX = '\.(?:vol\d{1,3}\+\d{1,3}|par2|srs|sfv|nzb)';

    private const string DEFAULT_VIDEO_FILE_REGEX = '\.(AVI|F4V|IFO|M1V|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|OGV|QT|RM|RMVB|TS|VOB|WMV)';

    private const string ARCHIVE_PATTERN = '/(\.(part\d+|[rz]\d+|rar|7z|0+|0*10?|zipr\d{2,3}|zipx?)("|\s*\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\.[1-9]\d{1,2}".*\(\d+\/\d{2,}\)$)/i';

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    /** Wave 40.6 paired benchmarks decide this default; see RustArchiveExtractionMediaUsenetDownloadReleaseProcW40ParityTest. */
    public const bool W406_RUST_FFI_DEFAULT = false;

    private static ?bool $w406UseRustFfi = null;

    /**
     * @var array<int, string>
     */
    private array $groupNameCache = [];

    private readonly MediaSourceResolver $mediaSourceResolver;

    private readonly ArchivedMediaSlicer $archivedMediaSlicer;

    private readonly MediaSourceStage $mediaSourceStage;

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly NzbContentParser $nzbParser,
        private readonly AdditionalWorkPlanner $workPlanner,
        private readonly ArchiveExtractionService $archiveService,
        private readonly MediaExtractionService $mediaService,
        private readonly UsenetDownloadService $downloadService,
        private readonly ReleaseFileManager $releaseManager,
        private readonly ReleaseFilesArchiveFallback $archiveFallback,
        private readonly TempWorkspaceService $tempWorkspace,
        private readonly ConsoleOutputService $output,
        private readonly ?ReleaseSearchSyncCoordinator $searchSyncCoordinator = null,
        private readonly ?PersistenceMetricsCollector $persistenceMetricsCollector = null,
        ?MediaSourceResolver $mediaSourceResolver = null,
        ?ArchivedMediaSlicer $archivedMediaSlicer = null,
        ?MediaSourceStage $mediaSourceStage = null,
    ) {
        $this->mediaSourceResolver = $mediaSourceResolver ?? new MediaSourceResolver($config, $downloadService);
        $this->archivedMediaSlicer = $archivedMediaSlicer ?? new ArchivedMediaSlicer($config);
        $this->mediaSourceStage = $mediaSourceStage ?? new MediaSourceStage(
            $config,
            $mediaService,
            new MediaArticleFetcher($downloadService),
            $this->archivedMediaSlicer,
            $output,
        );
    }

    public function process(ReleaseProcessingContext $context, string $mainTmpPath): ReleaseProcessingResult
    {
        $metrics = new ProcessingMetrics;
        $releaseId = (int) $context->release->id;
        $persistenceMetricsCollector = $this->persistenceMetricsCollector ?? new PersistenceMetricsCollector;
        $searchSyncCoordinator = $this->searchSyncCoordinator
            ?? new ReleaseSearchSyncCoordinator(
                $persistenceMetricsCollector,
            );

        $persistenceMetricsCollector->beginReleaseScope($releaseId);
        $searchSyncCoordinator->beginReleaseScope($releaseId);
        $this->downloadService->beginReleaseScope();

        try {
            $result = $this->processRelease($context, $mainTmpPath, $metrics);
        } finally {
            $context->releaseDownloadedArchives();
            try {
                $searchSyncCoordinator->finishReleaseScope();
            } finally {
                $persistenceMetrics = $persistenceMetricsCollector->finishReleaseScope();
                $downloadMetrics = $this->downloadService->finishReleaseScope();
            }
        }

        return $result->withPerformance(
            $metrics->elapsedSeconds(),
            $metrics->stageDurations(),
            $downloadMetrics,
            $persistenceMetrics,
        );
    }

    private function processRelease(
        ReleaseProcessingContext $context,
        string $mainTmpPath,
        ProcessingMetrics $metrics,
    ): ReleaseProcessingResult {
        $release = $context->release;

        $this->output->echoReleaseStart($release->id, $release->size);
        $this->output->setProcessTitle((int) $release->id);

        try {
            $context->tmpPath = $metrics->measure(
                ProcessingStage::WorkspacePreparation,
                fn (): string => $this->tempWorkspace->createReleaseTempFolder($mainTmpPath, $release->guid),
            );
        } catch (Throwable $e) {
            $this->output->warning('Unable to prepare release temp directory: '.$e->getMessage());

            return $this->result(
                $context,
                ProcessingOutcome::TemporaryWorkspaceUnavailable,
                reason: $e->getMessage(),
            );
        }

        try {
            $releaseNameChanged = false;
            $releaseNeededNfo = false;
            $nzbResult = $metrics->measure(
                ProcessingStage::NzbParsing,
                fn (): array => $this->nzbParser->parseNzb($release->guid),
            );
            if ($nzbResult['error'] !== null) {
                $this->output->warning($nzbResult['error']);
                if (($nzbResult['sourceChanged'] ?? false) !== true) {
                    $this->releaseManager->deleteRelease($release);
                }

                return $this->result(
                    $context,
                    ($nzbResult['sourceChanged'] ?? false) ? ProcessingOutcome::Failed : ProcessingOutcome::DeletedBrokenNzb,
                    reason: $nzbResult['error'],
                );
            }

            $context->nzbSourceDigest = $nzbResult['sourceDigest'] ?? null;
            $context->directVideoFiles = $nzbResult['directVideoFiles'] ?? null;
            $context->nzbSourceFiles = $nzbResult['sourceFiles'] ?? null;
            $context->nzbContents = array_values($nzbResult['contents']);
            if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                return $timeoutResult;
            }

            $releaseNameChanged = $metrics->measure(
                ProcessingStage::ReleaseInitialization,
                function () use ($context): bool {
                    $this->initializeContext($context);

                    return $this->releaseManager->processReleaseNameFromNzbContents($context->nzbContents, $context);
                },
            );
            $releaseNeededNfo = $context->releaseHasNoNFO;
            $bookFlood = $metrics->measure(
                ProcessingStage::MessageIdSelection,
                fn (): bool => $this->prepareMessageIds($context),
            );
            if ($context->workPlan?->par2IndexCandidates !== [] && $this->shouldProcessDownloads()) {
                $metrics->measure(
                    ProcessingStage::MediaSourcePlanning,
                    fn () => $this->applyMediaSourcePlan($context),
                );
                if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                    return $timeoutResult;
                }
            }

            $probe = $this->downloadProbe($context);
            if ($probe !== null && $context->workPlan?->probeIsOnlyFile === true && str_starts_with($probe[1], "PAR2\0PKT")) {
                $this->output->warning('Release '.$release->id.' only contains PAR2 data, deleting.');
                $this->releaseManager->deleteRelease($release);

                return $this->result(
                    $context,
                    ProcessingOutcome::DeletedPar2Only,
                    reason: 'The release only contains PAR2 recovery data.',
                );
            }

            // The probed file may be an archive behind an obfuscated name.
            $probedArchive = $probe !== null && ArchiveExtractionService::hasArchiveSignature($probe[1]) ? $probe : null;
            if ($probedArchive !== null) {
                $context->nzbHasCompressedFile = true;
                $context->unplannedArchiveSeen = true;
            }

            if ($this->shouldProcessDownloads()) {
                $metrics->measure(
                    ProcessingStage::DirectDownloads,
                    fn () => $this->processMessageIdDownloads($context),
                );
                if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                    return $timeoutResult;
                }

                if (! $bookFlood && $context->nzbHasCompressedFile) {
                    $context->archiveInspectionAttempted = true;
                    $triedCompressedMids = [];
                    $readArchiveSets = [];
                    $metrics->measure(
                        ProcessingStage::ArchiveDownloads,
                        function () use ($context, &$triedCompressedMids, &$readArchiveSets, $probedArchive): void {
                            if ($probedArchive !== null) {
                                $this->processProbedArchive($context, $probedArchive[0], $probedArchive[1]);
                            }
                            $this->processNzbCompressedFiles($context, false, $triedCompressedMids, $readArchiveSets);
                        },
                    );
                    if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                        return $timeoutResult;
                    }

                    if ($this->config->fetchLastFiles) {
                        $metrics->measure(
                            ProcessingStage::ArchiveDownloads,
                            function () use ($context, &$triedCompressedMids, &$readArchiveSets): void {
                                $this->processNzbCompressedFiles($context, true, $triedCompressedMids, $readArchiveSets);
                            },
                        );
                        if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                            return $timeoutResult;
                        }
                    }

                    if (! $context->releaseHasPassword) {
                        $metrics->measure(
                            ProcessingStage::ExtractedFiles,
                            fn () => $this->processExtractedFiles($context),
                        );
                        if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                            return $timeoutResult;
                        }
                    }

                    $context->archiveNamingInspectionComplete = $context->workPlan !== null
                        && $context->workPlan->archiveCandidates !== []
                        && $context->workPlan->unsupportedReasons === []
                        && $context->workPlan->probeCandidates === []
                        && ! $context->unplannedArchiveSeen && ! $context->archiveRetryable
                        && ! $context->archiveListingIncomplete && ! $context->archiveNameEvidenceIncomplete
                        && ! $context->archiveListingUndecodable && ! $context->releaseHasPassword;
                    foreach ($context->workPlan->archiveCandidates as $candidate) {
                        if (($readArchiveSets[$context->workPlan->setKeyOf($candidate)] ?? false) !== true) {
                            $context->archiveNamingInspectionComplete = false;
                        }
                    }
                    $this->runDeferredArchiveNameChecks($context, $readArchiveSets);
                }

                if ($this->mediaSourceStage->hasWork($context)) {
                    $metrics->measure(
                        ProcessingStage::MediaSources,
                        fn () => $this->mediaSourceStage->run($context),
                    );
                    if (($timeoutResult = $this->processingTimeoutResult($context, $metrics)) !== null) {
                        return $timeoutResult;
                    }
                }

                $metrics->measure(ProcessingStage::ArchiveFallbacks, function () use ($context): void {
                    if (! $context->foundJPGSample && $this->config->processJPGSample && ! ($context->mediaBudget?->exhausted() ?? false)) {
                        $this->archiveFallback->processJpgFromReleaseFiles($context);
                    }

                    if ($context->releaseHasNoNFO) {
                        $this->archiveFallback->processNfoFromDownloadedArchives($context);
                    }

                    if ($context->releaseHasNoNFO) {
                        $this->archiveFallback->processNfoFromReleaseFiles($context);
                    }
                });
            }

            $this->retainDirectVideoEvidence($context);
            $context->archiveNamingInspectionComplete = $context->archiveNamingInspectionComplete
                && ! $context->archiveNameEvidenceIncomplete && $this->hasCompleteSourceCoverage($context);
            $metrics->measure(
                ProcessingStage::Finalization,
                fn () => $this->releaseManager->finalizeRelease($context, $this->config->processPasswords),
            );

            if ($context->fullPar2EvidenceDeferred) {
                return $this->result($context, ProcessingOutcome::Failed, reason: 'Release identity or additional-processing claim changed before metadata commit.');
            }

            $artifactsCreated = $releaseNameChanged || $this->createdArtifacts($context, $releaseNeededNfo);
            $outcome = match (true) {
                $context->releaseHasPassword => ProcessingOutcome::Passworded,
                $context->groupUnavailable => ProcessingOutcome::GroupUnavailable,
                ! $artifactsCreated => ProcessingOutcome::NoUsefulArtifacts,
                default => ProcessingOutcome::Completed,
            };

            return $this->result(
                $context,
                $outcome,
                $artifactsCreated,
                reason: match ($outcome) {
                    ProcessingOutcome::Passworded => 'Password protection was detected.',
                    ProcessingOutcome::GroupUnavailable => 'The release group was unavailable during processing.',
                    ProcessingOutcome::NoUsefulArtifacts => 'Processing completed without creating useful artifacts.',
                    default => '',
                },
            );
        } finally {
            if ($context->tmpPath !== '') {
                $metrics->measure(
                    ProcessingStage::WorkspaceCleanup,
                    fn () => $this->tempWorkspace->clearDirectory($context->tmpPath, false),
                );
            }
        }
    }

    /**
     * Sample the first segment of files the planner couldn't classify by name, stopping at the
     * first archive. Obfuscated uploads can post each PAR2 volume as its own release; the first
     * bytes of a lone file give them away, so its sample is returned whatever it holds.
     *
     * @return array{0: ArchiveCandidate, 1: string}|null
     */
    private function downloadProbe(ReleaseProcessingContext $context): ?array
    {
        // A multi-file probe only feeds archive inspection, which needs downloads enabled.
        if ($context->workPlan?->probeIsOnlyFile !== true && ! $this->shouldProcessDownloads()) {
            return null;
        }

        foreach ($context->workPlan->probeCandidates ?? [] as $candidate) {
            if ($context->groupUnavailable) {
                return null;
            }

            $result = $this->downloadService->download(
                DownloadKind::Compressed,
                [$candidate->messageIds[0]],
                $context->releaseGroupName,
                $context->release->id,
            );

            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;
            }

            if (! $result['success'] || ! is_string($result['data'])) {
                continue;
            }

            if ($context->workPlan->probeIsOnlyFile || ArchiveExtractionService::hasArchiveSignature($result['data'])) {
                return [$candidate, $result['data']];
            }
        }

        return null;
    }

    private function processProbedArchive(ReleaseProcessingContext $context, ArchiveCandidate $candidate, string $data): void
    {
        if ($context->groupUnavailable) {
            $context->archiveRetryable = true;

            return;
        }

        if (! str_starts_with($data, SzipInfo::MARKER_SIGNATURE)) {
            // Batch downloads can return a partial body as success when a later article is missing.
            foreach (array_slice($candidate->messageIds, 1) as $messageId) {
                $result = $this->downloadService->download(
                    DownloadKind::Compressed,
                    [$messageId],
                    $context->releaseGroupName,
                    $context->release->id,
                );
                if ($result['groupUnavailable']) {
                    $context->groupUnavailable = true;
                }
                if (! $result['success'] || ! is_string($result['data'])) {
                    $context->archiveRetryable = true;

                    return;
                }

                $data .= $result['data'];
            }
        }

        $this->output->echoCompressedDownload();
        $this->processArchiveData(
            $context,
            $data,
            $candidate->tailMessageIds,
            false,
            $candidate->title,
            $candidate,
        );
    }

    private function retainDirectVideoEvidence(ReleaseProcessingContext $context): void
    {
        $matched = [];
        foreach ($context->nzbSourceFiles ?? [] as $file) {
            $witness = $context->directVideoWitnesses[hash('sha256', $file['firstMessageId'])] ?? null;
            if ($witness === null || $witness['digest'] !== $context->nzbSourceDigest || $witness['name'] !== $file['name']) {
                continue;
            }
            $matched[] = $witness;
            $context->rememberNamingWitness($witness['name']);
            if (SeasonPackDetector::isUnclassifiedEpisodeName($witness['name'])) {
                $context->archiveNameEvidenceIncomplete = true;
            }
        }
        $context->seasonPack = $context->seasonPack || SeasonPackDetector::isMultiEpisodeSet(array_keys($context->archiveNamingWitnesses));
        foreach ($matched as $witness) {
            $this->releaseManager->addFileInfo(['name' => $witness['name'], 'size' => $witness['size']], $context, $this->config->supportFileRegex);
        }
        if ($context->directVideoFiles !== null && ! $context->nzbHasCompressedFile && ! $context->unplannedArchiveSeen) {
            $context->archiveNamingInspectionComplete = count($matched) === count($context->directVideoFiles)
                && ! $context->archiveNameEvidenceIncomplete && ! $context->groupUnavailable && ! $context->releaseHasPassword;
        }
    }

    private function hasCompleteSourceCoverage(ReleaseProcessingContext $context): bool
    {
        if ($context->nzbSourceFiles === null || $context->nzbSourceDigest === null) {
            return false;
        }
        foreach ($context->nzbSourceFiles as $file) {
            $key = hash('sha256', $file['firstMessageId']);
            if (($context->archiveSourceWitnesses[$key] ?? null) === $context->nzbSourceDigest) {
                continue;
            }
            $direct = $context->directVideoWitnesses[$key] ?? null;
            if ($direct === null || $direct['digest'] !== $context->nzbSourceDigest || $direct['name'] !== $file['name']) {
                return false;
            }
        }

        return true;
    }

    private function shouldProcessDownloads(): bool
    {
        return $this->config->processPasswords
            || $this->config->processThumbnails
            || $this->config->processMediaInfo
            || $this->config->processAudioInfo
            || $this->config->processVideo
            || $this->config->processJPGSample;
    }

    private function prepareMessageIds(ReleaseProcessingContext $context): bool
    {
        $workPlan = $this->workPlanner->plan(
            $context->nzbContents,
            $context->releaseGroupName,
        );
        $this->useWorkPlan($context, $workPlan);
        $this->refreshSeasonPackState($context, $context->nzbContents);

        return $workPlan->bookFlood;
    }

    private function useWorkPlan(ReleaseProcessingContext $context, AdditionalWorkPlan $workPlan): void
    {
        $context->workPlan = $workPlan;
        $context->nzbHasCompressedFile = $workPlan->hasCompressedFile();
        $context->sampleMessageIDs = $workPlan->sampleMessageIds;
        $context->jpgMessageIDs = $workPlan->jpgMessageIds;
        $context->mediaInfoMessageIDs = $workPlan->mediaInfoMessageId;
        $context->audioInfoMessageIDs = $workPlan->audioInfoMessageId;
        $context->audioInfoExtension = $workPlan->audioInfoExtension;
    }

    /**
     * Plan again under the real names the PAR2 index gave obfuscated files,
     * filling in the samples, images and archives the posted names hid.
     */
    private function applyMediaSourcePlan(ReleaseProcessingContext $context): void
    {
        if ($context->workPlan === null) {
            return;
        }

        $plan = $this->mediaSourceResolver->resolve($context);
        if ($plan === null) {
            return;
        }

        $context->mediaPlan = $plan;
        $this->output->echoPar2Mapped(count($plan->mappedFiles));

        $renamed = $context->nzbContents;
        foreach ($plan->mappedFiles as $sourceIndex => $mapped) {
            $renamed[$sourceIndex]['title'] = '"'.$mapped->file->name.'" yEnc (1/'.count($renamed[$sourceIndex]['segments']).')';
        }

        $this->useWorkPlan(
            $context,
            $context->workPlan->withMappedNames($this->workPlanner->plan($renamed, $context->releaseGroupName)),
        );
        $this->refreshSeasonPackState($context, $renamed);
    }

    /**
     * Mark the release as a season pack when its name or its NZB file names say so.
     *
     * @param  array<int|string, mixed>  $nzbContents
     */
    private function refreshSeasonPackState(ReleaseProcessingContext $context, array $nzbContents): void
    {
        if ($context->seasonPack) {
            return;
        }

        if (SeasonPackDetector::isSeasonPack((string) $context->release->searchname)) {
            $context->seasonPack = true;

            return;
        }

        $names = [];
        foreach ($nzbContents as $file) {
            $title = \is_array($file) ? (string) ($file['title'] ?? '') : '';
            $names[] = preg_match('/"([^"\r\n]+)"/', $title, $quoted) === 1 ? $quoted[1] : $title;
        }
        $context->seasonPack = SeasonPackDetector::isMultiEpisodeSet($names);
    }

    /**
     * Record what an archive listed and whether that makes the release a season pack.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    private function noteArchiveListing(ReleaseProcessingContext $context, array $files): void
    {
        $context->rememberArchiveFileNames($files);
        foreach ($context->archiveFileNames as $name) {
            if (SeasonPackDetector::isUnclassifiedEpisodeName($name)) {
                $context->archiveNameEvidenceIncomplete = true;
            }
        }
        if ($context->seasonPack) {
            return;
        }

        if (SeasonPackDetector::isMultiEpisodeSet($context->archiveFileNames)) {
            $context->seasonPack = true;
            $context->seasonPackProbable = false;

            return;
        }

        if (! $context->seasonPackProbable && ($context->workPlan?->archiveSetCount() ?? 0) >= 2) {
            foreach ($context->archiveFileNames as $name) {
                if (SeasonPackDetector::isEpisodeName(basename(str_replace('\\', '/', $name)))) {
                    $context->seasonPackProbable = true;

                    return;
                }
            }
        }
    }

    /**
     * Once every archive set was read: a confirmed season pack keeps its name, and when no
     * second episode showed up the per-file renames held back during the walk run as usual.
     *
     * @param  array<string, bool>  $readArchiveSets
     */
    private function runDeferredArchiveNameChecks(ReleaseProcessingContext $context, array $readArchiveSets): void
    {
        if ($context->releaseHasPassword || $context->seasonPack || ! $context->seasonPackProbable
            || $context->archiveListingIncomplete || $context->archiveNameEvidenceIncomplete || $context->workPlan === null
        ) {
            return;
        }

        foreach ($context->workPlan->archiveCandidates as $candidate) {
            if (($readArchiveSets[$context->workPlan->setKeyOf($candidate)] ?? false) !== true) {
                return;
            }
        }

        $context->seasonPackProbable = false;
        if ($context->firstArchiveFiles !== null) {
            $this->releaseManager->matchFileNamesToPreDb($context->firstArchiveFiles, $context);
        }
        if ($context->reverseArchiveSummary !== null) {
            $this->releaseManager->processReleaseNameFromRar($context->reverseArchiveSummary, $context);
        }
    }

    private function initializeContext(ReleaseProcessingContext $context): void
    {
        $context->initializeFromConfig(
            $this->config->processVideo,
            $this->config->processMediaInfo,
            $this->config->processAudioInfo,
            $this->config->processAudioSample,
            $this->config->processJPGSample,
            $this->config->processThumbnails
        );

        $context->passwordStatus = ReleaseBrowseService::PASSWD_NONE;
        $context->releaseHasPassword = false;
        try {
            $groupId = (int) $context->release->groups_id;
            if (! array_key_exists($groupId, $this->groupNameCache)) {
                $this->groupNameCache[$groupId] = UsenetGroup::getNameByID($groupId);
            }
            $context->releaseGroupName = $this->groupNameCache[$groupId];
        } catch (Throwable) {
            $context->releaseGroupName = '';
        }
        $context->releaseHasNoNFO = (int) $context->release->nfostatus !== 1;
        $context->resetMessageIDs();
        $context->resetCounters();
    }

    /**
     * @phpstan-impure
     */
    private function processingTimeoutResult(
        ReleaseProcessingContext $context,
        ProcessingMetrics $metrics,
    ): ?ReleaseProcessingResult {
        if (! $context->isTimedOut($this->config->releaseProcessingTimeout)) {
            return null;
        }

        $deleted = $metrics->measure(
            ProcessingStage::TimeoutHandling,
            fn (): bool => $this->releaseManager->handleReleaseTimeout(
                $context->release,
                $this->config->maxPpTimeoutCount,
            ),
        );

        if ($deleted) {
            $this->output->echoReleaseTimeoutDeleted(
                $context->release->id,
                (int) ($context->release->pp_timeout_count ?? 0) + 1
            );
        } else {
            $this->output->echoReleaseTimeout($context->release->id, $context->getElapsedSeconds());
        }

        if ($context->tmpPath !== '') {
            $this->tempWorkspace->clearDirectory($context->tmpPath, false);
        }

        return $this->result(
            $context,
            $deleted ? ProcessingOutcome::DeletedAfterTimeout : ProcessingOutcome::TimedOut,
            reason: $deleted
                ? 'The release was deleted after reaching the post-processing timeout limit.'
                : 'The release exceeded the post-processing timeout.',
        );
    }

    private function createdArtifacts(ReleaseProcessingContext $context, bool $releaseNeededNfo): bool
    {
        return $context->releaseFilesChanged
            || $context->foundPAR2Info
            || ($releaseNeededNfo && ! $context->releaseHasNoNFO)
            || ($this->config->processVideo && $context->foundVideo)
            || ($this->config->processThumbnails && $context->foundSample)
            || ($this->config->processJPGSample && $context->foundJPGSample)
            || ($this->config->processMediaInfo && $context->foundMediaInfo)
            || ($this->config->processAudioInfo && $context->foundAudioInfo)
            || ($this->config->processAudioSample && $context->foundAudioSample);
    }

    private function result(
        ReleaseProcessingContext $context,
        ProcessingOutcome $outcome,
        bool $artifactsCreated = false,
        string $reason = '',
    ): ReleaseProcessingResult {
        return new ReleaseProcessingResult(
            releaseId: (int) $context->release->id,
            guid: (string) $context->release->guid,
            outcome: $outcome,
            artifactsCreated: $artifactsCreated,
            releaseFilesAdded: $context->addedFileInfo,
            reason: $reason,
            duplicateMessageIdCount: $context->duplicateMessageIdCount(),
            unsupportedReasons: $context->unsupportedReasons(),
        );
    }

    private function processMessageIdDownloads(ReleaseProcessingContext $context): void
    {
        if ((! $context->foundSample || ! $context->foundVideo) && $context->sampleMessageIDs !== []) {
            $result = $this->downloadService->download(
                DownloadKind::Sample,
                $context->sampleMessageIDs,
                $context->releaseGroupName,
                $context->release->id
            );

            if ($result['success'] && is_string($result['data']) && $this->downloadService->meetsMinimumSize($result['data'])) {
                $this->output->echoSampleDownload();
                $fileLocation = $context->tmpPath.'sample_'.random_int(0, 99999).'.avi';
                File::put($fileLocation, $result['data']);

                if (! $context->foundSample && $this->mediaService->getSample($fileLocation, $context->tmpPath, $context->release->guid)) {
                    $context->markFound('sample');
                    $this->output->echoSampleCreated();
                }
                if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid)) {
                    $context->markFound('video');
                    $this->output->echoVideoCreated();
                }

                // The media-info article is the sample's first one; it is already here.
                if ($context->mediaInfoMessageIDs !== '' && $context->mediaInfoMessageIDs === ($context->sampleMessageIDs[0] ?? null)) {
                    if (! $context->foundMediaInfo && $this->mediaService->getMediaInfo($fileLocation, $context->release->id)) {
                        $context->markFound('mediaInfo');
                        $this->output->echoMediaInfoAdded();
                    }
                    $context->mediaInfoMessageIDs = '';
                }
            } elseif (! $result['success']) {
                $this->output->echoSampleFailure();
            }
        }

        if ((! $context->foundMediaInfo || ! $context->foundSample || ! $context->foundVideo)
            && ! empty($context->mediaInfoMessageIDs)
        ) {
            $result = $this->downloadService->download(
                DownloadKind::MediaInfo,
                $context->mediaInfoMessageIDs,
                $context->releaseGroupName,
                $context->release->id
            );

            if ($result['success'] && is_string($result['data']) && $this->downloadService->meetsMinimumSize($result['data'])) {
                $this->output->echoMediaInfoDownload();
                $fileLocation = $context->tmpPath.'media.avi';
                File::put($fileLocation, $result['data']);

                if (! $context->foundMediaInfo && $this->mediaService->getMediaInfo($fileLocation, $context->release->id)) {
                    $context->markFound('mediaInfo');
                    $this->output->echoMediaInfoAdded();
                }
                if (! $context->foundSample && $this->mediaService->getSample($fileLocation, $context->tmpPath, $context->release->guid)) {
                    $context->markFound('sample');
                    $this->output->echoSampleCreated();
                }
                if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid)) {
                    $context->markFound('video');
                    $this->output->echoVideoCreated();
                }
            } elseif (! $result['success']) {
                $this->output->echoMediaInfoFailure();
            }
        }

        if ((! $context->foundAudioInfo || ! $context->foundAudioSample)
            && ! empty($context->audioInfoMessageIDs)
        ) {
            $result = $this->downloadService->download(
                DownloadKind::Audio,
                $context->audioInfoMessageIDs,
                $context->releaseGroupName,
                $context->release->id
            );

            if ($result['success'] && is_string($result['data'])) {
                $this->output->echoAudioDownload();
                $fileLocation = $context->tmpPath.'audio.'.$context->audioInfoExtension;
                File::put($fileLocation, $result['data']);

                $audioResult = $this->mediaService->getAudioInfo(
                    $fileLocation,
                    $context->audioInfoExtension,
                    $context,
                    $context->tmpPath
                );

                if ($audioResult['audioInfo']) {
                    $this->output->echoAudioInfoAdded();
                }
                if ($audioResult['audioSample']) {
                    $this->output->echoAudioSampleCreated();
                }
            } elseif (! $result['success']) {
                $this->output->echoAudioFailure();
            }
        }

        if (! $context->foundJPGSample && $context->jpgMessageIDs !== []) {
            $this->processImageDownloads($context);
        }
    }

    /**
     * Download the release's best posted images whole, one at a time, until one is usable.
     */
    private function processImageDownloads(ReleaseProcessingContext $context): void
    {

        $budget = $context->mediaBudget ??= new MediaByteBudget($this->config->mediaMaxBytes);
        $candidates = $context->workPlan->imageCandidates ?? [];
        $messageIdLists = $candidates !== []
            ? array_map(static fn ($candidate): array => $candidate->messageIds, $candidates)
            : [$context->jpgMessageIDs];

        foreach ($messageIdLists as $messageIds) {

            if ($context->foundJPGSample || $context->groupUnavailable || $budget->exhausted()) {
                return;
            }

            $result = $this->downloadService->downloadBounded(
                DownloadKind::Jpg,
                $messageIds,
                min($this->config->mediaImageMaxBytes, $budget->remaining()),
                $context->releaseGroupName,
                $context->release->id
            );
            $budget->spend($result['bytesDownloaded']);
            if ($result['groupUnavailable']) {
                $context->groupUnavailable = true;
            }
            if (! $result['success'] || ! is_string($result['data'])) {
                $this->output->echoJpgFailure();

                continue;
            }

            $this->output->echoJpgDownload();
            $fileLocation = $context->tmpPath.'samplepicture.img';
            File::put($fileLocation, $result['data']);

            if ($this->mediaService->isUsableImage($fileLocation)
                && $this->mediaService->getJPGSample($fileLocation, $context->release->guid)
            ) {
                $context->markFound('jpgSample');
                $this->output->echoJpgSaved();
            }

            File::delete($fileLocation);
        }
    }

    /**
     * @param  list<string>  $triedCompressedMids
     * @param  array<string, bool>  $readArchiveSets
     */
    private function processNzbCompressedFiles(
        ReleaseProcessingContext $context,
        bool $reverse,
        array &$triedCompressedMids,
        array &$readArchiveSets
    ): void {
        if ($context->groupUnavailable) {
            return;
        }

        $archiveCandidates = match (true) {
            $context->workPlan === null => [],
            $reverse => $context->workPlan->orderedArchiveCandidates(true),
            default => $context->workPlan->prioritizedArchiveCandidates(),
        };
        if (! $reverse) {
            $triedCompressedMids = [];
        }

        $failed = $downloaded = 0;
        /** @var array<string, true> $listedSets */
        $listedSets = [];
        /** @var array<string, int> $setFailures */
        $setFailures = [];
        $setKey = '';

        foreach ($archiveCandidates as $archiveCandidate) {
            if ($context->releaseHasPassword) {
                break;
            }

            // A season pack packed per episode is read set by set: the first volume of each set
            // lists its episode, and a set that fails to download only skips that set.
            $walking = ! $reverse && $context->workPlan !== null && $context->defersArchiveNameChecks();
            if (! $walking) {
                if ($downloaded >= $this->config->maximumRarSegments
                    || $failed >= $this->config->maximumRarPasswordChecks
                ) {
                    break;
                }
            } else {
                $setKey = $context->workPlan->setKeyOf($archiveCandidate);
                if (count($listedSets) >= $this->config->seasonPackArchiveSets
                    || $downloaded >= max($this->config->maximumRarSegments, $this->config->seasonPackArchiveSets)
                    || $failed >= $this->config->seasonPackArchiveSets * $this->config->maximumRarPasswordChecks
                    || $context->isTimedOut($this->config->releaseProcessingTimeout)
                ) {
                    break;
                }
                if (isset($listedSets[$setKey]) || ($setFailures[$setKey] ?? 0) >= $this->config->maximumRarPasswordChecks) {
                    continue;
                }
            }

            if ($reverse && array_intersect($archiveCandidate->messageIds, $triedCompressedMids) !== []) {
                continue;
            }

            // Further volumes of a file already found to be plain media hold no file list.
            if (isset($context->mediaVolumeSets[$archiveCandidate->volumeSet()])) {
                continue;
            }

            if (! $reverse) {
                $triedCompressedMids = [...$triedCompressedMids, ...$archiveCandidate->messageIds];
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
                $context->archiveRetryable = true;
                $this->output->echoGroupUnavailable();
                break;
            }

            if ($result['success'] && is_string($result['data'])) {
                $this->output->echoCompressedDownload();
                $downloaded++;

                $filesBefore = $context->totalFileInfo;
                $processed = $this->processArchiveData(
                    $context,
                    $result['data'],
                    $archiveCandidate->tailMessageIds,
                    $reverse,
                    $archiveCandidate->title,
                    $archiveCandidate,
                    $readArchiveSets,
                );

                // The listing may have just shown this is a season pack: keep reading the other sets
                if (! $reverse && $context->workPlan !== null && $context->defersArchiveNameChecks()) {
                    if ($context->totalFileInfo > $filesBefore) {
                        $listedSets[$context->workPlan->setKeyOf($archiveCandidate)] = true;
                    }

                    continue;
                }
                // Plain media says nothing about the release's other archives; keep checking them.
                if ($processed && ! isset($context->mediaVolumeSets[$archiveCandidate->volumeSet()])) {
                    break;
                }
            } else {
                $failed++;
                $context->archiveRetryable = true;
                if ($walking) {
                    $setFailures[$setKey] = ($setFailures[$setKey] ?? 0) + 1;
                }
                $this->output->echoCompressedFailure($failed);
            }
        }
    }

    /**
     * @param  list<string>  $tailMessageIds
     * @param  array<string, bool>  $readArchiveSets
     */
    private function processArchiveData(
        ReleaseProcessingContext $context,
        string $data,
        array $tailMessageIds,
        bool $reverse,
        string $archiveTitle,
        ?ArchiveCandidate $source = null,
        array &$readArchiveSets = [],
    ): bool {
        // Without a tail, a 7z whose end header is already in $data is still listed by 7-Zip.
        $tail = $this->downloadSevenZipTail($context, $data, $tailMessageIds);
        $listing = str_starts_with($data, SzipInfo::MARKER_SIGNATURE)
            ? $this->archiveService->listEncodedSevenZip($data, $tail, $context, $context->tmpPath)
            : null;
        $archiveData = $data;
        if ($listing === null) {
            $archiveData = $tail === null ? $data : ($this->archiveService->withSevenZipEndHeader($data, $tail) ?? $data);
            $listing = $this->archiveService->processCompressedData(
                $archiveData,
                $context,
                $context->tmpPath,
            );
        }

        $partialListing = $context->archiveListingIncomplete || $this->hasPartialArchiveListing($listing, $archiveData);
        if ($source !== null && $context->workPlan !== null) {
            $key = $context->workPlan->setKeyOf($source);
            if ($partialListing) {
                $readArchiveSets[$key] = false;
            } elseif (! array_key_exists($key, $readArchiveSets) && ! $context->archiveNameEvidenceIncomplete
                && ($listing['success'] || isset($listing['standaloneVideoType']))
            ) {
                $readArchiveSets[$key] = true;
            }
            $partialListing = $partialListing || ($readArchiveSets[$key] ?? true) === false;
        }

        if ($source !== null && $context->nzbSourceDigest !== null && ! $partialListing
            && $listing['success'] && ! empty($listing['files']) && isset($source->messageIds[0])) {
            $context->archiveSourceWitnesses[hash('sha256', $source->messageIds[0])] = $context->nzbSourceDigest;
        }

        return $this->handleArchiveResult($listing, $archiveData, $context, $reverse, $archiveTitle, $source, $partialListing);
    }

    /** @param array<string, mixed> $listing */
    private function hasPartialArchiveListing(array $listing, string $data): bool
    {
        if (! in_array($listing['dataSummary']['main_type'] ?? null, [ArchiveInfo::TYPE_RAR, ArchiveInfo::TYPE_SZIP], true)) {
            // File offsets alone do not attest the terminal directory of other archive types.
            return true;
        }
        if (($listing['dataSummary']['main_type'] ?? null) === ArchiveInfo::TYPE_RAR
            && ($listing['rarListingComplete'] ?? false) !== true
        ) {
            return true;
        }
        // A successful volume listing need not include the other episodes in its set.
        if (! empty($listing['dataSummary']['is_volume'])) {
            return true;
        }
        foreach ($listing['files'] ?? [] as $file) {
            if (! empty($file['split']) || ! empty($file['split_before']) || ! empty($file['split_after'])) {
                return true;
            }
            // Decoded 7z lists have an explicit completeness result instead of byte offsets.
            if (! empty($listing['listingOnly']) && ($listing['dataSummary']['main_type'] ?? null) === ArchiveInfo::TYPE_SZIP) {
                continue;
            }
            // Nested offsets use another buffer, and missing bounds cannot prove completeness.
            if (($file['source'] ?? null) !== ArchiveInfo::MAIN_SOURCE || ! is_int($file['next_offset'] ?? null)
                || $file['next_offset'] < 0 || $file['next_offset'] > strlen($data)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tailMessageIds
     */
    private function downloadSevenZipTail(ReleaseProcessingContext $context, string $data, array $tailMessageIds): ?string
    {
        if ($tailMessageIds === [] || ! $this->archiveService->needsSevenZipEndHeader($data)) {
            return null;
        }

        $result = $this->downloadService->download(
            DownloadKind::Compressed,
            $tailMessageIds,
            $context->releaseGroupName,
            $context->release->id,
        );
        if ($result['groupUnavailable']) {
            $context->groupUnavailable = true;
        }

        return $result['success'] && is_string($result['data']) ? $result['data'] : null;
    }

    private function processCompressedData(
        string $compressedData,
        ReleaseProcessingContext $context,
        bool $reverse,
        string $archiveTitle = '',
        ?ArchiveCandidate $source = null,
    ): bool {
        return $this->handleArchiveResult(
            $this->archiveService->processCompressedData($compressedData, $context, $context->tmpPath),
            $compressedData,
            $context,
            $reverse,
            $archiveTitle,
            $source,
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function handleArchiveResult(
        array $result,
        string $compressedData,
        ReleaseProcessingContext $context,
        bool $reverse,
        string $archiveTitle,
        ?ArchiveCandidate $source,
        bool $partialListing = false,
    ): bool {
        if ($result['hasPassword']) {
            $context->releaseHasPassword = true;
            $context->passwordStatus = $result['passwordStatus'];

            return true;
        }

        if (isset($result['standaloneVideoType'])) {
            if ($source !== null) {
                $context->mediaVolumeSets[$source->volumeSet()] = true;
            }
            $this->output->echoInlineVideo();
            $fileLocation = $context->tmpPath.'inline_video_'.uniqid('', true).'.'.$result['standaloneVideoType'];
            File::put($fileLocation, $result['standaloneVideoData']);

            if (! $context->foundMediaInfo && $this->mediaService->getMediaInfo($fileLocation, $context->release->id)) {
                $context->markFound('mediaInfo');
                $this->output->echoMediaInfoAdded();
            }
            if (! $context->foundSample && $this->mediaService->getSample($fileLocation, $context->tmpPath, $context->release->guid)) {
                $context->markFound('sample');
                $this->output->echoSampleCreated();
            }
            if (! $context->foundVideo && $this->mediaService->getVideo($fileLocation, $context->tmpPath, $context->release->guid)) {
                $context->markFound('video');
                $this->output->echoVideoCreated();
            }

            return $context->foundMediaInfo || $context->foundSample || $context->foundVideo;
        }

        if (! $result['success']) {
            return false;
        }

        if (! empty($result['archiveMarker'])) {
            $this->output->echoArchiveMarker($result['archiveMarker']);
        }

        $files = is_array($result['files'] ?? null) ? $result['files'] : [];
        if ($partialListing || $context->archiveListingIncomplete || $this->hasPartialArchiveListing($result, $compressedData)) {
            foreach ($files as $file) {
                if (SeasonPackDetector::isEpisodeName(basename(str_replace('\\', '/', (string) ($file['name'] ?? ''))))) {
                    $context->archiveNameEvidenceIncomplete = true;

                    break;
                }
            }
        }
        $this->noteArchiveListing($context, $files);
        if (! $reverse && $files !== [] && $context->defersArchiveNameChecks()) {
            $context->firstArchiveFiles ??= $files;
        }

        if ($reverse && ! empty($result['dataSummary'])) {
            if ($context->defersArchiveNameChecks()) {
                $context->reverseArchiveSummary ??= $result['dataSummary'];
            } else {
                $this->releaseManager->processReleaseNameFromRar($result['dataSummary'], $context);
            }
        }

        foreach ($result['files'] as $file) {
            if ($context->releaseHasPassword) {
                break;
            }

            if ($this->releaseManager->addFileInfo($file, $context, $this->config->supportFileRegex)) {
                $this->output->echoFileInfoAdded();
            }
        }

        if (! empty($result['listingOnly'])) {
            return $context->totalFileInfo > 0;
        }

        if (! $context->releaseHasPassword && is_array($result['files'])) {
            $this->archivedMediaSlicer->register($context, $compressedData, $result['files'], $source);
        }

        if ($context->releaseHasNoNFO
            && is_array($result['files'])
            && $this->containsNfoCandidate($result['files'])
        ) {
            $context->rememberDownloadedArchive($archiveTitle, $compressedData, $result['files']);
        }

        if (! $context->foundJPGSample && $this->config->processJPGSample) {
            $this->archiveFallback->processJpgFromArchiveFileList($compressedData, $result['files'], $context);
        }

        return $context->totalFileInfo > 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    private function containsNfoCandidate(array $files): bool
    {
        foreach ($files as $file) {
            if ($this->archiveService->isNfoFile((string) ($file['name'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function processExtractedFiles(ReleaseProcessingContext $context): void
    {
        $nestedLevels = 0;

        while ($nestedLevels < $this->config->maxNestedLevels) {
            if ($context->compressedFilesChecked >= AdditionalProcessingOrchestrator::MAX_COMPRESSED_FILES_TO_CHECK) {
                break;
            }

            $foundCompressed = false;
            $pattern = '/.*\.([rz]\d{2,}|rar|zipx?|0{0,2}1)($|[^a-z0-9])/i';

            try {
                $files = $this->tempWorkspace->listFiles($context->tmpPath, $pattern);
            } catch (Throwable) {
                break;
            }

            foreach ($files as $file) {
                $filePath = is_array($file) ? $file[0] : $file->getPathname();
                if (! File::isFile($filePath)) {
                    continue;
                }

                $rarData = @File::get($filePath);
                if (! empty($rarData)) {
                    $this->processCompressedData($rarData, $context, false, basename($filePath));
                    $foundCompressed = true;
                }
                File::delete($filePath);
            }

            if (! $foundCompressed) {
                break;
            }
            $nestedLevels++;
        }

        try {
            $files = $this->tempWorkspace->listFiles($context->tmpPath);
        } catch (Throwable) {
            return;
        }

        $images = [];
        foreach ($files as $file) {
            $filePath = is_object($file) ? $file->getPathname() : $file;

            if (preg_match('/[\/\\\\]\.{1,2}$/', $filePath) === 1 || ! File::isFile($filePath)) {
                continue;
            }

            if (! $context->foundPAR2Info && preg_match('/\.par2$/i', $filePath) === 1) {
                $this->releaseManager->processPar2File(
                    $filePath,
                    $context,
                    $this->archiveService->getPar2Info()
                );

                continue;
            }

            if ($context->releaseHasNoNFO) {
                if (preg_match('/(\.(nfo|inf|ofn|diz)|info\.txt)$/i', $filePath) === 1) {
                    if ($this->releaseManager->processNfoFile($filePath, $context, $this->downloadService->getNNTP())) {
                        $this->output->echoNfoFound();
                    }

                    continue;
                }

                if ($this->releaseManager->isNfoFilename($filePath)) {
                    if ($this->releaseManager->processNfoFile($filePath, $context, $this->downloadService->getNNTP())) {
                        $this->output->echoNfoFound();
                    }

                    continue;
                }
            }

            if ((! $context->foundAudioInfo || ! $context->foundAudioSample)
                && preg_match('/(.*)'.$this->config->audioFileRegex.'$/i', $filePath, $fileType) === 1
            ) {
                $audioPath = $context->tmpPath.'audiofile.'.$fileType[2];
                File::move($filePath, $audioPath);
                $audioResult = $this->mediaService->getAudioInfo(
                    $audioPath,
                    $fileType[2],
                    $context,
                    $context->tmpPath
                );
                if ($audioResult['audioInfo']) {
                    $this->output->echoAudioInfoAdded();
                }
                if ($audioResult['audioSample']) {
                    $this->output->echoAudioSampleCreated();
                }
                File::delete($audioPath);

                continue;
            }

            if (! $context->foundJPGSample && preg_match('/\.(jpe?g|png|webp)$/i', $filePath) === 1) {
                $images[] = $filePath;

                continue;
            }

            if ((! $context->foundSample || ! $context->foundVideo || ! $context->foundMediaInfo)
                && preg_match('/(.*)'.$this->config->videoFileRegex.'$/i', $filePath) === 1
            ) {
                $this->mediaService->processVideoFile($filePath, $context, $context->tmpPath);

                continue;
            }

            $output = fileInfo($filePath);
            if ($output === '' || $output === null) {
                continue;
            }

            if (! $context->foundJPGSample && preg_match('/^JPE?G|^PNG|^WebP/i', $output) === 1) {
                $images[] = $filePath;
            } elseif ((! $context->foundMediaInfo || ! $context->foundSample || ! $context->foundVideo)
                && preg_match('/Matroska data|MPEG v4|MPEG sequence, v2|\WAVI\W/i', $output) === 1
            ) {
                $this->mediaService->processVideoFile($filePath, $context, $context->tmpPath);
            } elseif ((! $context->foundAudioSample || ! $context->foundAudioInfo)
                && preg_match('/^FLAC|layer III|Vorbis audio/i', $output, $audioType) === 1
            ) {
                $extension = match ($audioType[0]) {
                    'FLAC' => 'FLAC',
                    'layer III' => 'MP3',
                    'Vorbis audio' => 'OGG',
                    default => 'audio',
                };
                $audioPath = $context->tmpPath.'audiofile.'.$extension;
                File::move($filePath, $audioPath);
                $audioResult = $this->mediaService->getAudioInfo($audioPath, $extension, $context, $context->tmpPath);
                if ($audioResult['audioInfo']) {
                    $this->output->echoAudioInfoAdded();
                }
                if ($audioResult['audioSample']) {
                    $this->output->echoAudioSampleCreated();
                }
                File::delete($audioPath);
            } elseif (! $context->foundPAR2Info && stripos($output, 'Parity') === 0) {
                $this->releaseManager->processPar2File(
                    $filePath,
                    $context,
                    $this->archiveService->getPar2Info()
                );
            }
        }

        $this->useBestExtractedImage($context, $images);
    }

    /**
     * Save the best usable image among those unpacked from the release's archives.
     *
     * @param  list<string>  $images
     */
    private function useBestExtractedImage(ReleaseProcessingContext $context, array $images): void
    {
        $ranked = ImageCandidateRanker::sort(
            $images,
            static fn (string $path): string => $path,
            static fn (string $path): int => (int) @filesize($path),
        );

        foreach ($ranked as $path) {
            if (! $context->foundJPGSample
                && $this->mediaService->isUsableImage($path)
                && $this->mediaService->getJPGSample($path, $context->release->guid)
            ) {
                $context->markFound('jpgSample');
                $this->output->echoJpgSaved();
            }
            File::delete($path);
        }
    }

    /**
     * Parse a `|`-separated list of `filename*size` pairs into structured entries.
     *
     * Retains the native PHP path in production (~1.34x–1.41x mean speedup in paired
     * benchmarks, below the 1.5x adoption threshold due to per-entry array allocation
     * in PHP); see parseReleaseFilesNative() for the Rust FFI implementation.
     *
     * @return list<array{name: string, size: int}>
     */
    public static function parseReleaseFiles(string $raw): array
    {
        return self::parseReleaseFilesPhp($raw);
    }

    /**
     * @return list<array{name: string, size: int}>
     */
    public static function parseReleaseFilesPhp(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }

        $files = [];
        foreach (explode('|', $trimmed) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || preg_match('/^(.+)\*(\d+)$/sD', $entry, $match) !== 1) {
                continue;
            }
            $name = trim($match[1]);
            if ($name === '') {
                continue;
            }
            $size = filter_var($match[2], FILTER_VALIDATE_INT);
            if ($size === false || $size < 0) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'size' => $size,
            ];
        }

        return $files;
    }

    /**
     * @return list<array{name: string, size: int}>
     */
    public static function parseReleaseFilesNative(string $raw, bool &$supported = false, ?string $libraryPath = null): array
    {
        $supported = false;
        if (str_contains($raw, "\0")) {
            return self::parseReleaseFilesPhp($raw);
        }

        $ffi = $libraryPath === null
            ? self::ffi()
            : RustPipelineLibrary::resolveDomain('release_processor', self::FFI_CDEF, $libraryPath);
        if ($ffi === null) {
            return self::parseReleaseFilesPhp($raw);
        }

        $rawLen = strlen($raw);
        if ($rawLen === 0) {
            $supported = true;

            return [];
        }

        $needed = $rawLen + 32;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
        if ($scratch === null) {
            return self::parseReleaseFilesPhp($raw);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_parse_release_files_nul($raw, $rawLen, $scratch, $cap);
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::parseReleaseFilesPhp($raw);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_release_parse_release_files_nul($raw, $rawLen, $scratch, $cap);
            }
        } catch (Throwable) {
            return self::parseReleaseFilesPhp($raw);
        }

        if (! is_int($written) || $written < 0) {
            return self::parseReleaseFilesPhp($raw);
        }

        $supported = true;
        if ($written === 0) {
            return [];
        }

        $parts = explode("\0", FFI::string($scratch, $written));
        $files = [];
        for ($i = 0, $n = count($parts); $i + 1 < $n; $i += 2) {
            $files[] = [
                'name' => $parts[$i],
                'size' => (int) $parts[$i + 1],
            ];
        }

        return $files;
    }

    /**
     * Extract message ID candidates and work plan from parsed NZB contents.
     *
     * @param  array<int|string, mixed>  $nzbContents
     */
    public function extractMessageIdCandidates(array $nzbContents, string $groupName = ''): AdditionalWorkPlan
    {
        return $this->workPlanner->plan($nzbContents, $groupName);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     */
    public function extractMessageIdCandidatesPhp(array $nzbContents, string $groupName = ''): AdditionalWorkPlan
    {
        return $this->workPlanner->planPhp($nzbContents, $groupName);
    }

    /**
     * @param  array<int|string, mixed>  $nzbContents
     */
    public function extractMessageIdCandidatesNative(array $nzbContents, string $groupName = '', bool &$supported = false): AdditionalWorkPlan
    {
        $canUseRust = $this->config->audioFileRegex === self::DEFAULT_AUDIO_FILE_REGEX
            && $this->config->ignoreBookRegex === self::DEFAULT_IGNORE_BOOK_REGEX
            && $this->config->supportFileRegex === self::DEFAULT_SUPPORT_FILE_REGEX
            && $this->config->videoFileRegex === self::DEFAULT_VIDEO_FILE_REGEX
            && ! str_contains($groupName, "\0")
            && RustPipelineLibrary::resolveDomain('release_processor', self::FFI_CDEF) !== null;
        if ($canUseRust) {
            foreach ($nzbContents as $file) {
                if (is_array($file) && str_contains((string) ($file['title'] ?? ''), "\0")) {
                    $canUseRust = false;
                    break;
                }
            }
        }
        $supported = $canUseRust;

        return $this->workPlanner->plan($nzbContents, $groupName);
    }

    /**
     * Normalize an NZB subject line into its canonical lower-cased file name.
     *
     * Retains the native PHP path in production (~0.65x–0.75x in single-string paired
     * benchmarks due to FFI string return marshaling; batched classification uses
     * nntmux_additional_classify_titles_nul); see normalizeSubjectNative().
     */
    public static function normalizeSubject(string $subject): string
    {
        return self::normalizeSubjectPhp($subject);
    }

    public static function normalizeSubjectPhp(string $subject): string
    {
        return ArchiveCandidate::fileNamePhp($subject);
    }

    public static function normalizeSubjectNative(string $subject, bool &$supported = false, ?string $libraryPath = null): string
    {
        $supported = false;
        if (str_contains($subject, "\0")) {
            return self::normalizeSubjectPhp($subject);
        }

        $ffi = $libraryPath === null
            ? self::ffi()
            : RustPipelineLibrary::resolveDomain('release_processor', self::FFI_CDEF, $libraryPath);
        if ($ffi === null) {
            return self::normalizeSubjectPhp($subject);
        }

        $len = strlen($subject);
        $needed = max(64, $len + 16);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
        if ($scratch === null) {
            return self::normalizeSubjectPhp($subject);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_normalize_subject($subject, $len, $scratch, $cap);
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::normalizeSubjectPhp($subject);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_release_normalize_subject($subject, $len, $scratch, $cap);
            }
        } catch (Throwable) {
            return self::normalizeSubjectPhp($subject);
        }

        if (! is_int($written) || $written < 0) {
            return self::normalizeSubjectPhp($subject);
        }

        $supported = true;

        return $written === 0 ? '' : FFI::string($scratch, $written);
    }

    /**
     * Check whether an NZB subject contains a video file extension pattern.
     *
     * Retains the native PHP path in production (~0.81x single-string FFI vs compiled
     * PCRE cache); see containsVideoPatternNative() for the Rust FFI helper.
     */
    public static function containsVideoPattern(string $subject, ?string $videoFileRegex = null): bool
    {
        return self::containsVideoPatternPhp($subject, $videoFileRegex);
    }

    public static function containsVideoPatternPhp(string $subject, ?string $videoFileRegex = null): bool
    {
        $regex = $videoFileRegex ?? self::DEFAULT_VIDEO_FILE_REGEX;

        return preg_match('/'.$regex.'(?:[. ")\]]|$)/i', $subject) === 1;
    }

    public static function containsVideoPatternNative(
        string $subject,
        ?string $videoFileRegex = null,
        bool &$supported = false,
        ?string $libraryPath = null,
    ): bool {
        $supported = false;
        $regex = $videoFileRegex ?? self::DEFAULT_VIDEO_FILE_REGEX;
        $ffi = $libraryPath === null
            ? self::ffi()
            : RustPipelineLibrary::resolveDomain('release_processor', self::FFI_CDEF, $libraryPath);
        if ($ffi === null) {
            return self::containsVideoPatternPhp($subject, $regex);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_release_contains_video_pattern(
                $subject,
                strlen($subject),
                $regex,
                strlen($regex),
            );
        } catch (Throwable) {
            return self::containsVideoPatternPhp($subject, $regex);
        }

        if (! is_int($status) || $status < 0) {
            return self::containsVideoPatternPhp($subject, $regex);
        }

        $supported = true;

        return $status === 1;
    }

    /**
     * Check whether an NZB subject represents a usable video sample candidate.
     *
     * Retains the native PHP path in production (~0.40x single-string FFI vs stripos
     * short-circuit); see hasUsableSampleFilenameNative() for the Rust FFI helper.
     */
    public static function hasUsableSampleFilename(string $subject): bool
    {
        return self::hasUsableSampleFilenamePhp($subject);
    }

    public static function hasUsableSampleFilenamePhp(string $subject): bool
    {
        return stripos($subject, 'sample') !== false
            && preg_match('/\.(?:jpe?g|png|webp)(?:[. ")\]]|$)/i', $subject) !== 1
            && preg_match(self::ARCHIVE_PATTERN, $subject) !== 1;
    }

    public static function hasUsableSampleFilenameNative(string $subject, bool &$supported = false, ?string $libraryPath = null): bool
    {
        $supported = false;
        $ffi = $libraryPath === null
            ? self::ffi()
            : RustPipelineLibrary::resolveDomain('release_processor', self::FFI_CDEF, $libraryPath);
        if ($ffi === null) {
            return self::hasUsableSampleFilenamePhp($subject);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_release_has_usable_sample_filename($subject, strlen($subject));
        } catch (Throwable) {
            return self::hasUsableSampleFilenamePhp($subject);
        }

        if (! is_int($status) || $status < 0) {
            return self::hasUsableSampleFilenamePhp($subject);
        }

        $supported = true;

        return $status === 1;
    }

    /**
     * Check whether an NZB subject is an auxiliary support/NFO file.
     *
     * Retains the native PHP path in production (~0.75x single-string FFI vs PCRE);
     * see isAuxiliaryNzbSubjectNative() for the Rust FFI helper.
     */
    public static function isAuxiliaryNzbSubject(string $subject, ?string $supportFileRegex = null): bool
    {
        return self::isAuxiliaryNzbSubjectPhp($subject, $supportFileRegex);
    }

    public static function isAuxiliaryNzbSubjectPhp(string $subject, ?string $supportFileRegex = null): bool
    {
        $regex = $supportFileRegex ?? self::DEFAULT_SUPPORT_FILE_REGEX;

        return preg_match(
            '/(?:'.$regex.'|nfo\b|inf\b|ofn\b)($|[ ")]|-])(?!.{20,})/i',
            $subject,
        ) === 1;
    }

    public static function isAuxiliaryNzbSubjectNative(
        string $subject,
        ?string $supportFileRegex = null,
        bool &$supported = false,
        ?string $libraryPath = null,
    ): bool {
        $supported = false;
        $regex = $supportFileRegex ?? self::DEFAULT_SUPPORT_FILE_REGEX;
        $ffi = $libraryPath === null
            ? self::ffi()
            : RustPipelineLibrary::resolveDomain('release_processor', self::FFI_CDEF, $libraryPath);
        if ($ffi === null) {
            return self::isAuxiliaryNzbSubjectPhp($subject, $regex);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_release_is_auxiliary_nzb_subject(
                $subject,
                strlen($subject),
                $regex,
                strlen($regex),
            );
        } catch (Throwable) {
            return self::isAuxiliaryNzbSubjectPhp($subject, $regex);
        }

        if (! is_int($status) || $status < 0) {
            return self::isAuxiliaryNzbSubjectPhp($subject, $regex);
        }

        $supported = true;

        return $status === 1;
    }

    /**
     * Check whether a normalized NZB subject matches a release name.
     */
    public static function subjectMatchesRelease(string $subject, string $releaseName): bool
    {
        return self::subjectMatchesReleaseNative($subject, $releaseName);
    }

    public static function subjectMatchesReleasePhp(string $subject, string $releaseName): bool
    {
        $normSubject = trim((string) preg_replace('/[^a-z0-9]+/', '.', self::normalizeSubjectPhp($subject)), '.');
        $normRelease = trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower($releaseName)), '.');
        if ($normSubject === '' || $normRelease === '') {
            return false;
        }

        return str_contains($normSubject, $normRelease) || str_contains($normRelease, $normSubject);
    }

    public static function subjectMatchesReleaseNative(
        string $subject,
        string $releaseName,
        bool &$supported = false,
        ?string $libraryPath = null,
    ): bool {
        $supported = false;
        $ffi = $libraryPath === null
            ? self::ffi()
            : RustPipelineLibrary::resolveDomain('release_processor', self::FFI_CDEF, $libraryPath);
        if ($ffi === null) {
            return self::subjectMatchesReleasePhp($subject, $releaseName);
        }

        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_release_subject_matches_release(
                $subject,
                strlen($subject),
                $releaseName,
                strlen($releaseName),
            );
        } catch (Throwable) {
            return self::subjectMatchesReleasePhp($subject, $releaseName);
        }

        if (! is_int($status) || $status < 0) {
            return self::subjectMatchesReleasePhp($subject, $releaseName);
        }

        $supported = true;

        return $status === 1;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('release_processor', self::FFI_CDEF);
        }

        return self::$ffi;
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
     * Summarize a post-processing batch. Each outcome is an array whose string 'status' processed/ok counts as
     * processed and failed/error as failed (compared exactly); 'passworded' and 'media_extracted' count only when
     * exactly true. Elapsed time sums max(0, an integer 'elapsed_ms'), saturating at PHP_INT_MAX, and exhausts
     * the budget once it reaches max(1, $budgetMs). Non-array outcomes only count toward the total.
     *
     * @param  array<array-key, mixed>  $releaseOutcomes
     * @return array{total: int, processed: int, password_detected: int, media_extracted: int, failed: int, total_elapsed_ms: int, budget_exhausted: bool}
     */
    public static function summarizePostProcessBatchTelemetry(array $releaseOutcomes, int $budgetMs = 60000): array
    {
        return (self::usesW406RustFfi() ? self::summarizePostProcessBatchTelemetryNative($releaseOutcomes, $budgetMs) : null)
            ?? self::summarizePostProcessBatchTelemetryPhp($releaseOutcomes, $budgetMs);
    }

    /**
     * @param  array<array-key, mixed>  $releaseOutcomes
     * @return array{total: int, processed: int, password_detected: int, media_extracted: int, failed: int, total_elapsed_ms: int, budget_exhausted: bool}
     */
    public static function summarizePostProcessBatchTelemetryPhp(array $releaseOutcomes, int $budgetMs = 60000): array
    {
        $processed = 0;
        $passworded = 0;
        $media = 0;
        $failed = 0;
        $elapsed = 0;
        foreach ($releaseOutcomes as $outcome) {
            [$flags, $status, $spent] = self::w406Outcome($outcome);
            if ($status === 'processed' || $status === 'ok') {
                $processed++;
            } elseif ($status === 'failed' || $status === 'error') {
                $failed++;
            }
            $passworded += $flags & 1;
            $media += $flags >> 1;
            if ($spent > 0) {
                $elapsed = $elapsed > PHP_INT_MAX - $spent ? PHP_INT_MAX : $elapsed + $spent;
            }
        }

        return [
            'total' => \count($releaseOutcomes),
            'processed' => $processed,
            'password_detected' => $passworded,
            'media_extracted' => $media,
            'failed' => $failed,
            'total_elapsed_ms' => $elapsed,
            'budget_exhausted' => $elapsed >= max(1, $budgetMs),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $releaseOutcomes
     * @return array{total: int, processed: int, password_detected: int, media_extracted: int, failed: int, total_elapsed_ms: int, budget_exhausted: bool}|null
     */
    public static function summarizePostProcessBatchTelemetryNative(array $releaseOutcomes, int $budgetMs = 60000): ?array
    {
        $total = \count($releaseOutcomes);
        $frame = pack('qV', $budgetMs, $total);
        foreach ($releaseOutcomes as $outcome) {
            [$flags, $status, $spent] = self::w406Outcome($outcome);
            $frame .= \chr($flags).ArchiveExtractionService::w406OptionalString($status).pack('q', $spent);
        }
        $slots = ArchiveExtractionService::w406Slots(4, $frame, 7);
        if ($slots === null || $slots[0] !== $total || $slots[1] < 0 || $slots[4] < 0 || $total < $slots[1] + $slots[4]
            || $slots[2] < 0 || $slots[2] > $total || $slots[3] < 0 || $slots[3] > $total || $slots[5] < 0 || ($slots[6] & ~1) !== 0) {
            return null;
        }

        return [
            'total' => $total,
            'processed' => $slots[1],
            'password_detected' => $slots[2],
            'media_extracted' => $slots[3],
            'failed' => $slots[4],
            'total_elapsed_ms' => $slots[5],
            'budget_exhausted' => $slots[6] === 1,
        ];
    }

    /** @return array{0: int, 1: string|null, 2: int} Passworded (bit 0) and media (bit 1) flags, string status and elapsed ms. */
    private static function w406Outcome(mixed $outcome): array
    {
        if (! \is_array($outcome)) {
            return [0, null, 0];
        }
        $status = $outcome['status'] ?? null;
        $elapsed = $outcome['elapsed_ms'] ?? null;

        return [
            (($outcome['passworded'] ?? null) === true ? 1 : 0) | (($outcome['media_extracted'] ?? null) === true ? 2 : 0),
            \is_string($status) ? $status : null,
            \is_int($elapsed) ? $elapsed : 0,
        ];
    }
}
