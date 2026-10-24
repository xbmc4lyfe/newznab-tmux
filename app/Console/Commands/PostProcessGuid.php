<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AdditionalProcessing\AdditionalProcessingOrchestrator;
use App\Services\AdditionalProcessing\DTO\AdditionalBatchResult;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\NfoService;
use App\Services\NNTP\NNTPService;
use App\Services\PostProcessService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'postprocess:guid')]
class PostProcessGuid extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_postprocess_guid_is_valid_hex_char(const char *value_ptr, size_t value_len);
int32_t nntmux_postprocess_guid_is_valid_type(const char *value_ptr, size_t value_len);
int64_t nntmux_postprocess_guid_effective_batch_timeout(int64_t child_timeout);
ssize_t nntmux_postprocess_guid_normalize_release_ids(
    const int64_t *ids_ptr,
    size_t ids_count,
    int64_t *out_ids,
    size_t out_cap
);
CDEF;

    private const HEX_CHARS = ['a', 'b', 'c', 'd', 'e', 'f', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    private const POSTPROCESS_TYPES = ['additional', 'nfo', 'movie', 'tv', 'anime', 'books', 'music', 'console', 'games'];

    private const INVALID_TYPE_MESSAGE = 'Invalid type. Must be: additional, nfo, movie, tv, anime, books, music, console, or games.';

    /**
     * Rust FFI stays opt-in: these argument checks are too small for FFI to beat PHP by 1.5x.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postprocess:guid
                            {type : Type: additional, nfo, movie, tv, anime, books, music, console, or games}
                            {guid : First character of release guid (a-f, 0-9)}
                            {renamed? : For movie/tv: process renamed only (optional)}
                            {--worker : Drain multiple additional-processing batches}
                            {--max-batches=4 : Maximum claim batches for worker mode}
                            {--profile : Emit one machine-readable performance record per additional batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Post process releases by GUID character';

    private ?AdditionalProcessingOrchestrator $additionalProcessor = null;

    public function __construct(
        private readonly PostProcessService $postProcessService
    ) {
        parent::__construct();
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    private function additionalProcessor(): AdditionalProcessingOrchestrator
    {
        return $this->additionalProcessor ??= app(AdditionalProcessingOrchestrator::class);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->argument('type');
        $guid = $this->argument('guid');
        $renamed = $this->argument('renamed') ?? '';

        if (! self::isValidHexChar($guid)) {
            $this->error('GUID character must be a-f or 0-9.');

            return self::FAILURE;
        }

        try {
            if (! self::isValidPostProcessType($type)) {
                throw new \InvalidArgumentException(self::INVALID_TYPE_MESSAGE);
            }

            match ($type) {
                'additional' => $this->processAdditional($guid, (bool) $this->option('worker')),
                'nfo' => $this->processNfo($guid),
                'movie' => $this->postProcessService->processMovies('', $guid, $renamed),
                'tv' => $this->postProcessService->processTv('', $guid, $renamed),
                'anime' => $this->postProcessService->processAnime('', $guid),
                'books' => $this->postProcessService->processBooks('', $guid),
                'music' => $this->postProcessService->processMusic('', $guid),
                'console' => $this->postProcessService->processConsoles('', $guid),
                'games' => $this->postProcessService->processGames('', $guid),
                default => throw new \InvalidArgumentException(self::INVALID_TYPE_MESSAGE),
            };

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Process additional data for releases.
     */
    private function processAdditional(string $guid, bool $workerMode): void
    {
        $maxBatches = $workerMode ? max(1, (int) $this->option('max-batches')) : 1;
        $workerToken = bin2hex(random_bytes(16));
        $childTimeout = (int) config('nntmux.multiprocessing_max_child_time', 1800);
        $deadline = microtime(true) + self::computeEffectiveBatchTimeout($childTimeout);
        $excludedReleaseIds = [];

        try {
            for ($batch = 0; $batch < $maxBatches && microtime(true) < $deadline; $batch++) {
                $result = $this->additionalProcessor()->start('', $guid, $workerToken, $excludedReleaseIds);
                if ((bool) $this->option('profile')) {
                    $this->writeAdditionalProfile($guid, $batch + 1, $result);
                }
                if ($result->claimedCount() === 0) {
                    break;
                }
                $excludedReleaseIds = self::normalizeWorkerReleaseIds([
                    ...$excludedReleaseIds,
                    ...$result->claimedIds,
                ]);
            }
        } finally {
            $this->additionalProcessor()->finish();
        }
    }

    private function writeAdditionalProfile(string $guid, int $batch, AdditionalBatchResult $result): void
    {
        $downloadMetrics = $result->downloadMetrics();
        $persistenceMetrics = $result->persistenceMetrics();

        $this->line(json_encode([
            'event' => 'additional-postprocessing-profile',
            'pipeline' => 'v2',
            'guid_char' => $guid,
            'batch' => $batch,
            'claimed' => $result->claimedCount(),
            'attempted' => $result->attemptedCount(),
            'successful' => $result->successfulCount(),
            'failed' => $result->unsuccessfulCount(),
            'outcomes' => $result->outcomeCounts(),
            'artifacts_created' => $result->artifactsCreatedCount(),
            'artifact_yield_percent' => round($result->artifactYieldPercent(), 2),
            'release_files_added' => $result->releaseFilesAdded(),
            'nntp_requests' => $downloadMetrics->networkRequests,
            'nntp_bytes' => $downloadMetrics->bytesDownloaded,
            'database_statements' => $persistenceMetrics->databaseStatements,
            'database_milliseconds' => round($persistenceMetrics->databaseMilliseconds, 3),
            'search_sync_requests' => $persistenceMetrics->searchSyncRequests,
            'search_sync_executions' => $persistenceMetrics->searchSyncExecutions,
            'duplicate_message_ids' => $result->duplicateMessageIdCount(),
            'elapsed_seconds' => round($result->elapsedSeconds, 6),
            'releases_per_hour' => round($result->releasesPerSecond() * 3600, 2),
            'average_release_seconds' => round($result->averageReleaseSeconds(), 6),
            'stage_seconds' => $result->stageDurationTotals(),
            'peak_memory_bytes' => $result->peakMemoryBytes,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Process NFO files for releases.
     */
    private function processNfo(string $guid): void
    {
        $nntp = $this->getNntp();
        (new NfoService)->processNfoFiles(
            $nntp,
            '',
            $guid,
            (bool) app(ConfigurationProvider::class)->metadata()->movieLookup->value,
            (bool) app(ConfigurationProvider::class)->metadata()->tvLookup->value
        );
    }

    /**
     * Check if the character contains a-f or 0-9.
     */
    public static function isValidHexChar(string $str): bool
    {
        if (self::$useRustFfi) {
            return self::isValidHexCharNative($str);
        }

        return self::isValidHexCharPhp($str);
    }

    public static function isValidHexCharPhp(string $str): bool
    {
        return \in_array($str, self::HEX_CHARS, true);
    }

    public static function isValidHexCharNative(string $str): bool
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::isValidHexCharPhp($str);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_postprocess_guid_is_valid_hex_char($str, strlen($str));

            return match ($result) {
                1 => true,
                0 => false,
                default => self::isValidHexCharPhp($str),
            };
        } catch (Throwable) {
            return self::isValidHexCharPhp($str);
        }
    }

    public static function isValidPostProcessType(string $type): bool
    {
        if (self::$useRustFfi) {
            return self::isValidPostProcessTypeNative($type);
        }

        return self::isValidPostProcessTypePhp($type);
    }

    public static function isValidPostProcessTypePhp(string $type): bool
    {
        return \in_array($type, self::POSTPROCESS_TYPES, true);
    }

    public static function isValidPostProcessTypeNative(string $type): bool
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::isValidPostProcessTypePhp($type);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_postprocess_guid_is_valid_type($type, strlen($type));

            return match ($result) {
                1 => true,
                0 => false,
                default => self::isValidPostProcessTypePhp($type),
            };
        } catch (Throwable) {
            return self::isValidPostProcessTypePhp($type);
        }
    }

    /**
     * Worker-mode batch window: the child timeout minus a 30-second safety margin, at least 1 second.
     */
    public static function computeEffectiveBatchTimeout(int $childTimeout): int
    {
        if (self::$useRustFfi) {
            return self::computeEffectiveBatchTimeoutNative($childTimeout);
        }

        return self::computeEffectiveBatchTimeoutPhp($childTimeout);
    }

    public static function computeEffectiveBatchTimeoutPhp(int $childTimeout): int
    {
        return (int) max(1, $childTimeout - 30);
    }

    public static function computeEffectiveBatchTimeoutNative(int $childTimeout): int
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::computeEffectiveBatchTimeoutPhp($childTimeout);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_postprocess_guid_effective_batch_timeout($childTimeout);

            return is_int($result) ? $result : self::computeEffectiveBatchTimeoutPhp($childTimeout);
        } catch (Throwable) {
            return self::computeEffectiveBatchTimeoutPhp($childTimeout);
        }
    }

    /**
     * Positive, de-duplicated release IDs in first-seen order.
     *
     * @param  array<int|string, mixed>  $releaseIds
     * @return list<int>
     */
    public static function normalizeWorkerReleaseIds(array $releaseIds): array
    {
        if (self::$useRustFfi) {
            return self::normalizeWorkerReleaseIdsNative($releaseIds);
        }

        return self::normalizeWorkerReleaseIdsPhp($releaseIds);
    }

    /**
     * @param  array<int|string, mixed>  $releaseIds
     * @return list<int>
     */
    public static function normalizeWorkerReleaseIdsPhp(array $releaseIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $releaseIds),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * @param  array<int|string, mixed>  $releaseIds
     * @return list<int>
     */
    public static function normalizeWorkerReleaseIdsNative(array $releaseIds): array
    {
        $ids = array_values($releaseIds);
        foreach ($ids as $id) {
            if (! is_int($id)) {
                return self::normalizeWorkerReleaseIdsPhp($releaseIds);
            }
        }
        $count = count($ids);
        if ($count === 0) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::normalizeWorkerReleaseIdsPhp($releaseIds);
        }

        try {
            $input = $ffi->new("int64_t[{$count}]");
            foreach ($ids as $index => $id) {
                $input[$index] = $id;
            }
            $output = $ffi->new("int64_t[{$count}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_postprocess_guid_normalize_release_ids($input, $count, $output, $count);
            if (! is_int($written) || $written < 0 || $written > $count) {
                return self::normalizeWorkerReleaseIdsPhp($releaseIds);
            }

            $normalized = [];
            for ($i = 0; $i < $written; $i++) {
                $id = $output[$i];
                if (! is_int($id)) {
                    return self::normalizeWorkerReleaseIdsPhp($releaseIds);
                }
                $normalized[] = $id;
            }

            return $normalized;
        } catch (Throwable) {
            return self::normalizeWorkerReleaseIdsPhp($releaseIds);
        }
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    /**
     * Get NNTP connection.
     */
    private function getNntp(): NNTPService
    {
        $nntp = new NNTPService;

        $connectResult = config('nntmux_nntp.use_alternate_nntp_server') === true
            ? $nntp->doConnect(false, true)
            : $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \RuntimeException($errorMessage);
        }

        return $nntp;
    }
}
