<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Models\Release;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\DTO\AdditionalBatchResult;
use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\TempWorkspaceService;
use App\Support\RustPipelineLibrary;
use Exception;
use FFI;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Main orchestrator for additional release post-processing.
 * Coordinates release selection and delegates actual per-release work to ReleaseProcessor.
 */
class AdditionalProcessingOrchestrator
{
    public const int MAX_COMPRESSED_FILES_TO_CHECK = 10;

    public const string FFI_CDEF = <<<'C'
        double nntmux_aporch_elapsed_seconds(int64_t start_ns, int64_t end_ns, int32_t *status);
        int64_t nntmux_aporch_query_limit(int64_t limit);
        int32_t nntmux_aporch_batch_disposition(uint8_t setup_ok, int64_t total_releases);
        int32_t nntmux_aporch_should_clear_claim(size_t token_len, int64_t release_id);
        C;

    public const int BATCH_SETUP_FAILED = 0;

    public const int BATCH_EMPTY = 1;

    public const int BATCH_PROCESS = 2;

    public const int DEFAULT_QUERY_LIMIT = 25;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @var Collection<int, mixed>
     */
    private Collection $releases;

    private int $totalReleases = 0;

    private string $mainTmpPath = '';

    private string $claimToken = '';

    private string $lastSetupError = '';

    public function __construct(
        private readonly ProcessingConfiguration $config,
        private readonly ReleaseProcessor $processor,
        private readonly TempWorkspaceService $tempWorkspace,
        private readonly ConsoleOutputService $output
    ) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * Start the additional processing.
     *
     * @param  list<int>  $excludedReleaseIds
     *
     * @throws Exception
     */
    public function start(
        string $groupID = '',
        string $guidChar = '',
        string $workerToken = '',
        array $excludedReleaseIds = []
    ): AdditionalBatchResult {
        $this->finish();
        if (! $this->setupTempPath($guidChar, $groupID, $workerToken)) {
            return AdditionalBatchResult::setupFailed($this->lastSetupError);
        }

        $this->fetchReleases($groupID, $guidChar, $excludedReleaseIds);

        if (self::batchDisposition(true, $this->totalReleases) === self::BATCH_PROCESS) {
            $this->output->echoDescription($this->totalReleases);

            return $this->processReleases($guidChar);
        }

        return AdditionalBatchResult::empty();
    }

    /**
     * Process a single release by GUID.
     */
    public function processSingleGuid(string $guid): ReleaseProcessingResult
    {
        $releaseId = 0;

        try {
            $this->finish();
            $release = Release::where('guid', $guid)->first();
            if ($release === null) {
                $this->output->warning('Release not found for GUID: '.$guid);

                return new ReleaseProcessingResult(0, $guid, ProcessingOutcome::NotFound, reason: 'Release not found.');
            }
            if (Release::hasLateArticleFlag() && $release->getAttribute('late_article_candidate_id') !== null) {
                $this->output->warning('Release is a partial still awaiting late articles: '.$guid);

                return new ReleaseProcessingResult((int) $release->id, $guid, ProcessingOutcome::AwaitingLateArticles,
                    reason: 'Release is a partial still awaiting late articles.');
            }

            $releaseId = (int) $release->id;

            $this->releases = collect([$release]);
            $this->totalReleases = 1;
            $guidChar = $release->leftguid ?? substr($release->guid, 0, 1);
            $groupID = '';
            if (! $this->setupTempPath($guidChar, $groupID)) {
                return new ReleaseProcessingResult(
                    $releaseId,
                    $guid,
                    ProcessingOutcome::TemporaryWorkspaceUnavailable,
                    reason: $this->lastSetupError,
                );
            }

            $result = $this->processReleases($guidChar)->firstResult();

            return $result ?? new ReleaseProcessingResult(
                $releaseId,
                $guid,
                ProcessingOutcome::Failed,
                reason: 'No processing result was produced.',
            );
        } catch (Throwable $e) {
            if ($this->config->debugMode) {
                Log::error('processSingleGuid failed: '.$e->getMessage());
            }

            return new ReleaseProcessingResult(
                $releaseId,
                $guid,
                ProcessingOutcome::Failed,
                reason: $e->getMessage(),
            );
        }
    }

    /**
     * Set up the main temp path.
     */
    private function setupTempPath(string $guidChar, string $groupID, string $workerToken = ''): bool
    {
        $this->lastSetupError = '';

        try {
            $this->mainTmpPath = $this->tempWorkspace->ensureMainTempPath(
                $this->config->tmpUnrarPath,
                $guidChar,
                $groupID,
                $workerToken !== '' ? $workerToken : bin2hex(random_bytes(16)),
            );
            $this->tempWorkspace->clearDirectory($this->mainTmpPath, true);
        } catch (Throwable $e) {
            $this->lastSetupError = $e->getMessage();
            $this->output->warning('Additional post-processing skipped: '.$e->getMessage());
            Log::error('Additional post-processing temp path is unavailable', [
                'tmp_unrar_path' => $this->config->tmpUnrarPath,
                'guid_char' => $guidChar,
                'group_id' => $groupID,
                'exception' => $e,
            ]);
            $this->mainTmpPath = '';

            return false;
        }

        return true;
    }

    /**
     * Fetch releases for processing.
     *
     * The selection predicates (passwordstatus, haspreview, nzbstatus,
     * disablepreview, size bounds) are owned by AdditionalCandidateQuery so
     * they stay consistent with the bucket-fanout SQL in
     * PostProcessRunner::processAdditional(). Do NOT inline new predicates
     * here; add them to AdditionalCandidateQuery instead.
     */
    /**
     * @param  list<int>  $excludedReleaseIds
     */
    private function fetchReleases(int|string $groupID, string $guidChar, array $excludedReleaseIds = []): void
    {
        $this->claimToken = bin2hex(random_bytes(16));
        $this->releases = AdditionalCandidateQuery::claimBatch(
            $guidChar,
            self::effectiveQueryLimit($this->config->queryLimit),
            $this->claimToken,
            $groupID,
            $this->config->minSizeBytes,
            $this->config->maxSizeBytes,
            [
                'id',
                'guid',
                'name',
                'size',
                'groups_id',
                'nfostatus',
                'fromname',
                'completion',
                'categories_id',
                'searchname',
                'predb_id',
                'pp_timeout_count',
                AdditionalCandidateQuery::CLAIM_TOKEN_COLUMN,
            ],
            $excludedReleaseIds,
        );
        $this->totalReleases = $this->releases->count();
    }

    /**
     * Process all fetched releases.
     *
     * Each release is processed inside its own try/catch so that a single
     * poison release cannot stall an entire GUID-character bucket. Without
     * this, an exception from ReleaseProcessor::process() would abort the
     * foreach for the whole worker, the same release would be re-selected on
     * every subsequent cycle, and the "needs additional pp" backlog would
     * grow indefinitely without any visible failure.
     *
     * @throws Exception
     */
    private function processReleases(string $guidChar = ''): AdditionalBatchResult
    {
        $startedAt = hrtime(true);
        $claimedIds = $this->releases
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $results = [];

        foreach ($this->releases as $release) {
            $releaseId = (int) ($release->id ?? 0);
            $releaseGuid = (string) ($release->guid ?? '');
            $releaseStartedAt = hrtime(true);

            try {
                $results[] = $this->processor->process(new ReleaseProcessingContext($release), $this->mainTmpPath);
            } catch (Throwable $e) {
                $results[] = new ReleaseProcessingResult(
                    releaseId: $releaseId,
                    guid: $releaseGuid,
                    outcome: ProcessingOutcome::Failed,
                    reason: $e->getMessage(),
                    elapsedSeconds: $this->elapsedSecondsSince($releaseStartedAt),
                );
                Log::error('Additional postprocessing failed for release '.$releaseId.': '.$e->getMessage(), [
                    'release_id' => $releaseId,
                    'guid' => $releaseGuid,
                    'guid_char' => $guidChar,
                    'exception' => $e,
                ]);
                // Don't rethrow: keep draining the bucket. The release will be
                // re-selected on the next cycle, and the pp_timeout_count /
                // maxpptimeoutcount machinery will eventually drop it.
            } finally {
                if (self::shouldClearClaim($this->claimToken, $releaseId)) {
                    AdditionalCandidateQuery::clearClaim($releaseId, $this->claimToken);
                }
            }
        }

        $batchResult = new AdditionalBatchResult(
            claimedIds: $claimedIds,
            results: $results,
            elapsedSeconds: $this->elapsedSecondsSince($startedAt),
            peakMemoryBytes: memory_get_peak_usage(true),
        );
        $downloadMetrics = $batchResult->downloadMetrics();
        $persistenceMetrics = $batchResult->persistenceMetrics();

        Log::info('Additional postprocessing run finished', [
            'pipeline' => 'v2',
            'guid_char' => $guidChar,
            'picked' => $this->totalReleases,
            'processed' => $batchResult->successfulCount(),
            'failed' => $batchResult->unsuccessfulCount(),
            'outcomes' => $batchResult->outcomeCounts(),
            'artifacts_created' => $batchResult->artifactsCreatedCount(),
            'artifact_yield_percent' => round($batchResult->artifactYieldPercent(), 2),
            'release_files_added' => $batchResult->releaseFilesAdded(),
            'download_requests' => $downloadMetrics->logicalRequests,
            'nntp_requests' => $downloadMetrics->networkRequests,
            'download_cache_hits' => $downloadMetrics->cacheHits,
            'nntp_bytes' => $downloadMetrics->bytesDownloaded,
            'reused_bytes' => $downloadMetrics->bytesReused,
            'preview_bytes' => $downloadMetrics->mediaBytesDownloaded,
            'database_statements' => $persistenceMetrics->databaseStatements,
            'database_milliseconds' => round($persistenceMetrics->databaseMilliseconds, 3),
            'search_sync_requests' => $persistenceMetrics->searchSyncRequests,
            'search_sync_executions' => $persistenceMetrics->searchSyncExecutions,
            'duplicate_message_ids' => $batchResult->duplicateMessageIdCount(),
            'unsupported_reasons' => $batchResult->unsupportedReasonCounts(),
            'elapsed_seconds' => round($batchResult->elapsedSeconds, 6),
            'releases_per_second' => round($batchResult->releasesPerSecond(), 4),
            'average_release_seconds' => round($batchResult->averageReleaseSeconds(), 6),
            'stage_seconds' => $this->roundedStageDurations($batchResult->stageDurationTotals()),
            'peak_memory_bytes' => $batchResult->peakMemoryBytes,
        ]);

        $this->output->endOutput();

        return $batchResult;
    }

    private function elapsedSecondsSince(int $startedAtNanoseconds): float
    {
        return self::elapsedSecondsBetween($startedAtNanoseconds, hrtime(true));
    }

    public static function elapsedSecondsBetween(int $startedAtNanoseconds, int $endedAtNanoseconds): float
    {
        return (self::$useRustFfi ? self::elapsedSecondsBetweenNative($startedAtNanoseconds, $endedAtNanoseconds) : null)
            ?? self::elapsedSecondsBetweenPhp($startedAtNanoseconds, $endedAtNanoseconds);
    }

    public static function elapsedSecondsBetweenPhp(int $startedAtNanoseconds, int $endedAtNanoseconds): float
    {
        return ($endedAtNanoseconds - $startedAtNanoseconds) / 1_000_000_000;
    }

    public static function elapsedSecondsBetweenNative(int $startedAtNanoseconds, int $endedAtNanoseconds): ?float
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $status = $ffi->new('int32_t');
            // @phpstan-ignore method.notFound
            $value = $ffi->nntmux_aporch_elapsed_seconds($startedAtNanoseconds, $endedAtNanoseconds, FFI::addr($status));
            // @phpstan-ignore property.notFound
            $code = (int) $status->cdata;
        } catch (Throwable) {
            return null;
        }

        return $code === 1 ? (float) $value : null;
    }

    /**
     * Batch size to claim; a non-positive configured limit falls back to the default.
     */
    public static function effectiveQueryLimit(int $configured): int
    {
        return (self::$useRustFfi ? self::effectiveQueryLimitNative($configured) : null) ?? self::effectiveQueryLimitPhp($configured);
    }

    public static function effectiveQueryLimitPhp(int $configured): int
    {
        return $configured > 0 ? $configured : self::DEFAULT_QUERY_LIMIT;
    }

    public static function effectiveQueryLimitNative(int $configured): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            return (int) $ffi->nntmux_aporch_query_limit($configured);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One of the BATCH_* outcomes for a batch whose workspace setup succeeded or failed.
     */
    public static function batchDisposition(bool $setupSucceeded, int $totalReleases): int
    {
        return (self::$useRustFfi ? self::batchDispositionNative($setupSucceeded, $totalReleases) : null)
            ?? self::batchDispositionPhp($setupSucceeded, $totalReleases);
    }

    public static function batchDispositionPhp(bool $setupSucceeded, int $totalReleases): int
    {
        if (! $setupSucceeded) {
            return self::BATCH_SETUP_FAILED;
        }

        return $totalReleases > 0 ? self::BATCH_PROCESS : self::BATCH_EMPTY;
    }

    public static function batchDispositionNative(bool $setupSucceeded, int $totalReleases): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = (int) $ffi->nntmux_aporch_batch_disposition($setupSucceeded ? 1 : 0, $totalReleases);
        } catch (Throwable) {
            return null;
        }

        return \in_array($code, [self::BATCH_SETUP_FAILED, self::BATCH_EMPTY, self::BATCH_PROCESS], true) ? $code : null;
    }

    /**
     * Whether a processed candidate still holds a claim this batch must release.
     */
    public static function shouldClearClaim(string $claimToken, int $releaseId): bool
    {
        return (self::$useRustFfi ? self::shouldClearClaimNative($claimToken, $releaseId) : null) ?? self::shouldClearClaimPhp($claimToken, $releaseId);
    }

    public static function shouldClearClaimPhp(string $claimToken, int $releaseId): bool
    {
        return $claimToken !== '' && $releaseId > 0;
    }

    public static function shouldClearClaimNative(string $claimToken, int $releaseId): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            return (int) $ffi->nntmux_aporch_should_clear_claim(\strlen($claimToken), $releaseId) === 1;
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  array<string, float>  $durations
     * @return array<string, float>
     */
    private function roundedStageDurations(array $durations): array
    {
        return array_map(
            static fn (float $duration): float => round($duration, 6),
            $durations,
        );
    }

    public function finish(): void
    {
        if ($this->mainTmpPath !== '') {
            $this->tempWorkspace->clearDirectory($this->mainTmpPath, false);
            $this->mainTmpPath = '';
        }

        $this->releases = collect();
        $this->totalReleases = 0;
        $this->claimToken = '';
        $this->lastSetupError = '';
    }

    public const bool W416_RUST_FFI_DEFAULT = false;

    private const string W416_FFI_CDEF = 'int32_t nntmux_w416_additional_candidate_diag_orch_console_nzb(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    private static ?bool $w416UseRustFfi = null;

    private static FFI|false|null $w416Ffi = null;

    public static function useW416RustFfi(bool $enable = true): void
    {
        self::$w416UseRustFfi = $enable;
    }

    public static function usesW416RustFfi(): bool
    {
        return self::$w416UseRustFfi ?? self::W416_RUST_FFI_DEFAULT;
    }

    public static function resetW416RustFfi(): void
    {
        self::$w416UseRustFfi = null;
        self::$w416Ffi = null;
    }

    /** @return list<int>|null */
    private static function w416Slots(int $operation, string $input, int $count): ?array
    {
        if (\strlen($input) > 16_777_216) {
            return null;
        }
        try {
            self::$w416Ffi ??= RustPipelineLibrary::resolveDomain('additional', self::W416_FFI_CDEF) ?? false;
            if (self::$w416Ffi === false) {
                return null;
            }
            $bytes = $count * 8;
            $buffer = self::$w416Ffi->new('char['.$bytes.']');
            // @phpstan-ignore method.notFound
            $written = self::$w416Ffi->nntmux_w416_additional_candidate_diag_orch_console_nzb($operation, $input, \strlen($input), $buffer, $bytes);
            if ($written !== $bytes) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $bytes));
            if ($values === false || \count($values) !== $count) {
                return null;
            }
            foreach ($values as $value) {
                if (! \is_int($value) || $value < 0) {
                    return null;
                }
            }

            return array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    private static function w416Add(int $left, int $right): int
    {
        return $left > PHP_INT_MAX - $right ? PHP_INT_MAX : $left + $right;
    }

    /**
     * @param  array<array-key, mixed>  $batches
     * @return array{total: int, setup_failed: int, empty: int, processing: int, total_elapsed_ms: int, budget_exhausted: bool, invalid: int}
     */
    public static function summarizeOrchestrationBatch(array $batches, int $budgetMs = 60000): array
    {
        return self::usesW416RustFfi()
            ? self::summarizeOrchestrationBatchNative($batches, $budgetMs)
            : self::summarizeOrchestrationBatchPhp($batches, $budgetMs);
    }

    /**
     * @param  array<array-key, mixed>  $batches
     * @return array{total: int, setup_failed: int, empty: int, processing: int, total_elapsed_ms: int, budget_exhausted: bool, invalid: int}
     */
    public static function summarizeOrchestrationBatchPhp(array $batches, int $budgetMs = 60000): array
    {
        $result = ['total' => \count($batches), 'setup_failed' => 0, 'empty' => 0, 'processing' => 0, 'total_elapsed_ms' => 0, 'budget_exhausted' => false, 'invalid' => 0];
        foreach ($batches as $row) {
            if (! \is_array($row) || ! \is_int($row['release_count'] ?? null) || ! \is_int($row['elapsed_ms'] ?? null) || ! \is_bool($row['setup_ok'] ?? null)) {
                $result['invalid']++;

                continue;
            }
            $result['total_elapsed_ms'] = self::w416Add($result['total_elapsed_ms'], max(0, $row['elapsed_ms']));
            if (! $row['setup_ok']) {
                $result['setup_failed']++;
            } elseif ($row['release_count'] <= 0) {
                $result['empty']++;
            } else {
                $result['processing']++;
            }
        }
        $result['budget_exhausted'] = $result['total'] > 0 && $result['total_elapsed_ms'] >= max(0, $budgetMs);

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $batches
     * @return array{total: int, setup_failed: int, empty: int, processing: int, total_elapsed_ms: int, budget_exhausted: bool, invalid: int}
     */
    public static function summarizeOrchestrationBatchNative(array $batches, int $budgetMs = 60000): array
    {
        $total = \count($batches);
        if ($total > 100_000) {
            return self::summarizeOrchestrationBatchPhp($batches, $budgetMs);
        }
        $frame = pack('qqV', $budgetMs, 0, $total);
        foreach ($batches as $row) {
            if (! \is_array($row) || ! \is_int($row['release_count'] ?? null) || ! \is_int($row['elapsed_ms'] ?? null) || ! \is_bool($row['setup_ok'] ?? null)) {
                return self::summarizeOrchestrationBatchPhp($batches, $budgetMs);
            }
            $frame .= pack('qqq', $row['setup_ok'] ? 1 : 0, $row['release_count'], $row['elapsed_ms']);
        }
        $slots = self::w416Slots(3, $frame, 7);
        if ($slots === null || $slots[0] !== $total || $slots[6] !== 0 || $slots[5] > 1 || $slots[5] !== (int) ($total > 0 && $slots[4] >= max(0, $budgetMs)) || $slots[1] > $total || $slots[2] > $total || $slots[3] > $total || $total !== $slots[1] + $slots[2] + $slots[3]) {
            return self::summarizeOrchestrationBatchPhp($batches, $budgetMs);
        }

        return [
            'total' => $slots[0],
            'setup_failed' => $slots[1],
            'empty' => $slots[2],
            'processing' => $slots[3],
            'total_elapsed_ms' => $slots[4],
            'budget_exhausted' => $slots[5] === 1,
            'invalid' => $slots[6],
        ];
    }
}
