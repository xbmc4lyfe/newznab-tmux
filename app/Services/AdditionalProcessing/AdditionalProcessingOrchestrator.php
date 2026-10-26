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
use FFI\CData;
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

    // Rust is the default only while the paired W51.6 benchmark clears 1.5x on all four metrics.
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        int32_t nntmux_w516_release_run_batch(const char *guids, size_t guids_len, const char *guid_twin, size_t guid_twin_len, const char *ints, size_t ints_len, size_t count, size_t token_len, int64_t slow_ns, int64_t *out, size_t out_count);
        C;

    public const int W51_RUN_HEADER = 28;

    private const int W51_TOTAL_CAP = 1 << 62;

    private const array W51_HEX_BUCKETS = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
        '8' => 8, '9' => 9, 'a' => 10, 'b' => 11, 'c' => 12, 'd' => 13, 'e' => 14, 'f' => 15,
    ];

    private const int W51_MAX_ITEMS = 1_048_576;

    private static ?bool $w51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51Capacity = 0;

    /**
     * Account a batch of processed releases as {@see self::processReleases()} sees them.
     *
     * `$ids` sets the row count; missing GUIDs are '' and missing clocks 0. Start and end
     * `hrtime()` nanoseconds are clamped to 0..2^62 and elapsed time to at least 0. Layout:
     * `[0]` count, `[1]` `batchDispositionPhp(true, count)`, `[2]` claims to clear
     * (`shouldClearClaimPhp()`), `[3]` repeated ids, `[4]` distinct ids, `[5]` GUIDs that are
     * not 40 hex digits, `[6]` releases taking at least `$slowNs` (when positive), `[7]` total
     * elapsed (saturating at 2^62), `[8]` longest elapsed, `[9]` its first index (-1 when
     * empty), `[10..26]` per GUID bucket (0-f, then other), `[27]` FNV-1a digest; then per release
     * `clear | repeated << 1 | well_formed << 2 | slow << 3 | bucket << 4 | elapsed_us << 9`.
     *
     * @param  array<array-key, int>  $ids
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, int>  $startedNs
     * @param  array<array-key, int>  $endedNs
     * @return list<int>
     */
    public static function summarizeReleaseRunBatch(array $ids, array $guids, array $startedNs, array $endedNs, string $claimToken, int $slowNs): array
    {
        return (self::usesW51RustFfi() ? self::summarizeReleaseRunBatchNative($ids, $guids, $startedNs, $endedNs, $claimToken, $slowNs) : null)
            ?? self::summarizeReleaseRunBatchPhp($ids, $guids, $startedNs, $endedNs, $claimToken, $slowNs);
    }

    /**
     * @param  array<array-key, int>  $ids
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, int>  $startedNs
     * @param  array<array-key, int>  $endedNs
     * @return list<int>
     */
    public static function summarizeReleaseRunBatchPhp(array $ids, array $guids, array $startedNs, array $endedNs, string $claimToken, int $slowNs): array
    {
        $ids = array_values($ids);
        $guids = array_values($guids);
        $startedNs = array_values($startedNs);
        $endedNs = array_values($endedNs);
        $count = \count($ids);
        $out = array_fill(0, self::W51_RUN_HEADER, 0);
        $out[0] = $count;
        $out[1] = self::batchDispositionPhp(true, $count);
        $out[9] = -1;
        $seen = $rows = [];
        $digest = 2_166_136_261;
        foreach ($ids as $index => $id) {
            $guid = $guids[$index] ?? '';
            $elapsed = max(0, min(max($endedNs[$index] ?? 0, 0), self::W51_TOTAL_CAP) - min(max($startedNs[$index] ?? 0, 0), self::W51_TOTAL_CAP));
            $clear = self::shouldClearClaimPhp($claimToken, $id);
            $repeated = isset($seen[$id]);
            $seen[$id] = true;
            $wellFormed = \strlen($guid) === 40 && ctype_xdigit($guid);
            $slow = $slowNs > 0 && $elapsed >= $slowNs;
            $bucket = self::W51_HEX_BUCKETS[strtolower(substr($guid, 0, 1))] ?? 16;
            $out[2] += (int) $clear;
            $out[3] += (int) $repeated;
            $out[5] += (int) ! $wellFormed;
            $out[6] += (int) $slow;
            $out[7] = $elapsed > self::W51_TOTAL_CAP - $out[7] ? self::W51_TOTAL_CAP : $out[7] + $elapsed;
            if ($index === 0 || $elapsed > $out[8]) {
                $out[8] = $elapsed;
                $out[9] = $index;
            }
            $out[10 + $bucket]++;
            $row = (int) $clear | (int) $repeated << 1 | (int) $wellFormed << 2 | (int) $slow << 3 | $bucket << 4 | intdiv($elapsed, 1000) << 9;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[4] = $count - $out[3];
        $out[27] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust accounting, or null when PHP must compute it (unavailable library, mismatched
     * columns or rejected input).
     *
     * @param  array<array-key, int>  $ids
     * @param  array<array-key, string>  $guids
     * @param  array<array-key, int>  $startedNs
     * @param  array<array-key, int>  $endedNs
     * @return list<int>|null
     */
    public static function summarizeReleaseRunBatchNative(array $ids, array $guids, array $startedNs, array $endedNs, string $claimToken, int $slowNs): ?array
    {
        $count = \count($ids);
        $fields = self::W51_RUN_HEADER + $count;
        $binding = $count <= self::W51_MAX_ITEMS && \count($guids) === $count && \count($startedNs) === $count && \count($endedNs) === $count
            ? self::w51Binding($fields)
            : null;
        if ($binding === null) {
            return null;
        }
        [$ffi, $out] = $binding;
        [$joined, $twin] = self::w51Strings($guids);
        try {
            $packed = pack('q*', ...$ids, ...$startedNs, ...$endedNs);
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w516_release_run_batch($joined, \strlen($joined), $twin, \strlen($twin), $packed, \strlen($packed), $count, \strlen($claimToken), $slowNs, $out, $fields);

            return self::w51Decode(\is_int($status) ? $status : -1, $out, $fields);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run `$callback` with the Wave 51.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w51RustFfi;
        self::$w51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w51RustFfi = $previous;
        }
    }

    public static function usesW51RustFfi(): bool
    {
        return self::$w51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * The cached Wave 51.6 binding and an output buffer of at least `$fields` values, or null.
     *
     * @return array{0: FFI, 1: CData}|null
     */
    private static function w51Binding(int $fields): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('additional_w51', self::W51_FFI_CDEF) ?? false;
            if (self::$w51Ffi === false) {
                return null;
            }
            $out = self::$w51Out;
            if ($out === null || self::$w51Capacity < $fields) {
                self::$w51Capacity = max(256, $fields);
                $out = self::$w51Out = self::$w51Ffi->new('int64_t['.self::$w51Capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return [self::$w51Ffi, $out];
    }

    /**
     * Decode `$fields` output values after a successful Wave 51.6 call.
     *
     * @return list<int>|null
     */
    private static function w51Decode(int $status, CData $out, int $fields): ?array
    {
        if ($status !== 1) {
            return null;
        }
        $values = unpack('P*', FFI::string($out, $fields * 8));

        return $values === false ? null : array_values($values);
    }

    /**
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  array<array-key, string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w51Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }

    // ========================================================================
    // WAVE 64.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.66x, p99 5.79x; uncommon mean 7.39x, p99 5.67x); see RustAdditionalQueryDiagnosticsOrchestratorPlannerExtractionW64ParityTest. */
    public const bool W64_RUST_FFI_DEFAULT = true;

    public const string W64_ORCHESTRATOR_FFI_CDEF = <<<'C'
        ssize_t nntmux_w641_orchestrator_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w64OrchestratorPlanBatch(). */
    public const int W64_ORCHESTRATOR_TRAILER = 28;

    public static function usesW64RustFfi(): bool
    {
        $override = self::w64RustFfiState();

        return \is_bool($override) ? $override : self::W64_RUST_FFI_DEFAULT;
    }

    /** Forces the AdditionalProcessingOrchestrator Wave 64 Rust path on until reset. */
    public static function enableW64RustFfi(): void
    {
        self::w64RustFfiState('set', true);
    }

    /** Forces the AdditionalProcessingOrchestrator Wave 64 PHP path until reset. */
    public static function disableW64RustFfi(): void
    {
        self::w64RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW64RustFfi(): void
    {
        self::w64RustFfiState('reset');
    }

    /**
     * Runs $callback with the AdditionalProcessingOrchestrator Wave 64 Rust path forced on or off, then restores the previous setting.
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
     * Wave 64.1 dispatch plans, one per worker cycle `limit|threads|setup|total|claim_token|lock_age|cooldown|release_id|elapsed_ms`
     * (trimmed fields). The limit and release ID are optionally signed (empty 0); threads (empty 1), total, lock age
     * (empty: no lock), cooldown and elapsed are 1-15 digits; setup is `1`, `true` or `yes`, or empty, `0`, `false` or
     * `no`. The effective limit follows effectiveQueryLimitPhp() and the disposition batchDispositionPhp(). Lock state: 0
     * none, 1 held (under 1800 s), 2 stale. Action: 0 empty row, 1 malformed, 2 setup failed, 3 locked, 4 cooling down
     * (lock younger than the cooldown), 5 empty batch, 6 dispatch, 7 dispatch after stale-lock recovery. A dispatch claims
     * min(limit, total) releases over min(threads, batch) workers. Flags: 1 defaulted limit, 2 limit over 500, 4 over 32
     * threads, 8 more than 500 releases in flight, 16 shouldClearClaimPhp(), 32 alphanumeric token of 16+ bytes, 64 slow
     * (60 s or more), 128 non-ASCII or control byte in the token. Per item `action | disposition << 3 | lockState << 5 |
     * flags << 7 | min(limit, 4095) << 15 | min(threads, 255) << 27 | min(batch, 4095) << 35 | min(workers, 255) << 47 |
     * min(perWorker, 255) << 55`; then 8 action, 3 disposition, 3 lock state and 8 flag counts, the claimed release and
     * worker sums, the largest in-flight count, the elapsed sum, the oldest lock and the distinct tokens.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64OrchestratorPlanBatch(array $items): array
    {
        return self::usesW64RustFfi() ? self::w64OrchestratorPlanBatchNative($items) : self::w64OrchestratorPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64OrchestratorPlanBatchNative(array $items): array
    {
        return self::w64Invoke($items, \count($items) + self::W64_ORCHESTRATOR_TRAILER) ?? self::w64OrchestratorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w64OrchestratorPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W64_ORCHESTRATOR_TRAILER, 0);
        $tokens = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawLimit, $rawThreads, $rawSetup, $rawTotal, $rawToken, $rawLock, $rawCooldown, $rawRelease, $rawElapsed] = explode('|', $item, 9)
                + ['', '', '', '', '', '', '', '', ''];
            $limitRaw = trim($rawLimit);
            $configured = $limitRaw === '' ? 0 : self::w64Sint($limitRaw);
            $threads = self::w64Uint($rawThreads, 1);
            $setup = match (strtolower(trim($rawSetup))) {
                '1', 'true', 'yes' => true,
                '', '0', 'false', 'no' => false,
                default => null,
            };
            $total = self::w64Uint($rawTotal, 0);
            $token = trim($rawToken);
            $tokenLength = \strlen($token);
            $lock = self::w64Uint($rawLock, -2);
            $cooldown = self::w64Uint($rawCooldown, 0);
            $releaseRaw = trim($rawRelease);
            $release = $releaseRaw === '' ? 0 : self::w64Sint($releaseRaw);
            $elapsed = self::w64Uint($rawElapsed, 0);
            $malformed = $configured === null || $threads < 0 || $setup === null || $total < 0 || $lock === -1 || $cooldown < 0 || $release === null
                || $elapsed < 0;
            $configured ??= 0;
            $effective = $configured > 0 ? $configured : self::DEFAULT_QUERY_LIMIT;
            $workerThreads = max(1, $threads);
            $disposition = $setup !== true ? 0 : ($total > 0 ? 2 : 1);
            $lockState = $lock < 0 ? 0 : ($lock < 1800 ? 1 : 2);
            $action = match (true) {
                trim($item) === '' => 0,
                $malformed => 1,
                $disposition === 0 => 2,
                $lockState === 1 => 3,
                $cooldown > 0 && $lock >= 0 && $lock < $cooldown => 4,
                $disposition === 1 => 5,
                $lockState === 2 => 7,
                default => 6,
            };
            $dispatch = $action === 6 || $action === 7;
            $batch = $dispatch ? min($effective, $total) : 0;
            $workers = $dispatch ? min($workerThreads, $batch) : 0;
            $perWorker = $workers > 0 ? intdiv($batch + $workers - 1, $workers) : 0;
            $inFlight = min($effective, 1 << 20) * min($workerThreads, 1 << 20);
            $flags = ($configured <= 0 ? 1 : 0) | ($configured > 500 ? 2 : 0) | ($workerThreads > 32 ? 4 : 0) | ($inFlight > 500 ? 8 : 0)
                | ($token !== '' && ($release ?? 0) > 0 ? 16 : 0) | ($tokenLength >= 16 && strspn($token, self::W64_ALNUM) === $tokenLength ? 32 : 0)
                | ($elapsed >= 60000 ? 64 : 0)
                | (preg_match('/[\x80-\xff]/', $token) === 1 || strcspn($token, self::W64_CONTROL_BYTES) !== $tokenLength ? 128 : 0);
            if ($token !== '') {
                $tokens[$token] = true;
            }
            $tail[$action]++;
            $tail[8 + $disposition]++;
            $tail[11 + $lockState]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[14 + $b] += $flags >> $b & 1;
            }
            $tail[22] += min($batch, 1 << 40);
            $tail[23] += min($workers, 1 << 40);
            $tail[24] = max($tail[24], $inFlight);
            $tail[25] += max(0, min($elapsed, 1 << 40));
            $tail[26] = max($tail[26], $lock);
            $out[] = $action | $disposition << 3 | $lockState << 5 | $flags << 7 | min($effective, 4095) << 15 | min($workerThreads, 255) << 27
                | min($batch, 4095) << 35 | min($workers, 255) << 47 | min($perWorker, 255) << 55;
        }
        $tail[27] = \count($tokens);
        array_push($out, ...$tail);

        return $out;
    }

    private const string W64_ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const string W64_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private const string W64_DIGITS = '0123456789';

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w64Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W64_DIGITS) === $length ? (int) $value : -1;
    }

    /** An optional `-` and 1-15 digits of an already trimmed field, else null. */
    private static function w64Sint(string $value): ?int
    {
        $negative = str_starts_with($value, '-');
        $digits = $negative ? substr($value, 1) : $value;
        $length = \strlen($digits);
        if ($length === 0 || $length > 15 || strspn($digits, self::W64_DIGITS) !== $length) {
            return null;
        }

        return $negative ? -(int) $digits : (int) $digits;
    }

    /**
     * Wave 64 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w64RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_orchestrator_w641', self::W64_ORCHESTRATOR_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w64RustFfiState('ffi');
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
                $handle = self::w64RustFfiState('ffi');
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
     * Calls the Wave 64.1 AdditionalProcessingOrchestrator export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w64Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w64RustFfiState('ffi');
            $out = self::w64RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w64RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W64_ORCHESTRATOR_FFI_CDEF.)
            $written = $ffi->nntmux_w641_orchestrator_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
