<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TvLookupStatus;
use App\Models\Release;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Services\TvProcessing\TvProcessingPipeline;
use App\Services\TvProcessing\TvProcessingResult;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReprocessUnmatchedTvReleases extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_reprocess_unmatched_tv_target_statuses(
        uint32_t include_parse_failed,
        int64_t *out_values,
        size_t values_cap
    );
    int32_t nntmux_reprocess_unmatched_tv_clamp_process_count(
        int64_t total_count,
        int64_t limit,
        int64_t *out_count
    );
    ssize_t nntmux_reprocess_unmatched_tv_format_match_rate(
        int64_t matched,
        int64_t processed,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMovieAnimeTvReprocessCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tv:reprocess-unmatched
                            {--limit=0 : Limit the number of releases to process (0 = no limit)}
                            {--dry-run : Show how many releases would be processed without making changes}
                            {--parse-failed : Also retry releases whose names could not be parsed}
                            {--debug : Show detailed debug information for each release}
                            {--sleep=0 : Sleep time in milliseconds between processing each release}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprocess TV releases that are not matched to any show (pending or show not found)';

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
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

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');
        $sleep = (int) $this->option('sleep');

        $statuses = self::buildTargetStatuses((bool) $this->option('parse-failed'));

        // Build query for unmatched TV releases (newest to oldest by adddate, then postdate)
        $query = Release::query()
            ->select(['id', 'guid', 'searchname', 'videos_id', 'tv_episodes_id', 'categories_id', 'adddate', 'postdate'])
            ->whereRaw(TvProcessingCandidateQuery::categoryCondition())
            // A partial awaiting late articles is renamed when they arrive; look it up once it settles.
            ->whereRaw(TvProcessingCandidateQuery::settledCondition())
            ->where('videos_id', 0)
            ->whereIn('tv_episodes_id', $statuses)
            ->orderBy('adddate', 'desc')
            ->orderBy('postdate', 'desc');

        $totalCount = (clone $query)->count();

        if ($totalCount === 0) {
            $this->info('No unmatched TV releases found.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalCount} unmatched TV release(s).");

        $processCount = self::clampProcessCount($totalCount, $limit);
        if ($limit > 0) {
            $query->limit($limit);
            $this->info("Processing limited to {$processCount} release(s).");
        }

        if ($dryRun) {
            $this->line("[Dry Run] Would process {$processCount} unmatched TV release(s).");

            // Show sample of releases that would be processed
            $sample = (clone $query)->limit(10)->get();
            if ($sample->isNotEmpty()) {
                $this->newLine();
                $this->info('Sample of releases to be processed:');
                $rows = $sample->map(fn ($release) => [
                    $release->id,
                    $release->guid,
                    ReprocessTvRelease::truncateSearchName((string) $release->searchname),
                    $release->categories_id,
                ])->toArray();
                $this->table(['ID', 'GUID', 'Search Name', 'Category'], $rows);
            }

            return self::SUCCESS;
        }

        $this->info("Starting to process {$processCount} unmatched TV release(s)...");
        $this->newLine();

        $bar = $this->output->createProgressBar($processCount);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | Matched: %matched% | Failed: %failed% | Elapsed: %elapsed:6s%');
        $bar->setMessage('0', 'matched');
        $bar->setMessage('0', 'failed');
        $bar->start();

        $matched = 0;
        $failed = 0;
        $processed = 0;

        try {
            $pipeline = app(TvProcessingPipeline::class)->setEchoOutput(false);
            $runState = $pipeline->newRunState();

            // Use cursor to iterate without chunking issues when ordering by non-id columns
            $cursor = $query->cursor();

            foreach ($cursor as $release) {
                if ($limit > 0 && $processed >= $limit) {
                    break;
                }

                try {
                    $result = $pipeline->processRelease($release, $debug, $runState);

                    if (\in_array($result['status'], [TvProcessingResult::STATUS_MATCHED, TvProcessingResult::STATUS_SEASON_PACK, TvProcessingResult::STATUS_EPISODE_MISSING], true)) {
                        $matched++;
                        if ($debug) {
                            $this->newLine();
                            cli()->primary("Matched: {$release->searchname}");
                            $this->info('  Provider: '.($result['provider'] ?? 'Unknown'));
                            $this->info('  Video ID: '.($result['video_id'] ?? 'N/A'));
                            $this->info('  Episode ID: '.($result['episode_id'] ?? 'N/A'));
                        }
                    } else {
                        $failed++;
                        if ($debug) {
                            $this->newLine();
                            cli()->warning("No match: {$release->searchname}");
                        }
                    }
                } catch (Throwable $e) {
                    $failed++;
                    Log::error("Error processing release {$release->guid}: ".$e->getMessage());
                    if ($debug) {
                        $this->newLine();
                        $this->error("Error processing {$release->searchname}: ".$e->getMessage());
                    }
                }

                $processed++;
                $bar->setMessage((string) $matched, 'matched');
                $bar->setMessage((string) $failed, 'failed');
                $bar->advance();

                if ($sleep > 0) {
                    usleep($sleep * 1000);
                }
            }

        } catch (Throwable $e) {
            $bar->finish();
            $this->newLine();
            $this->error('Fatal error during processing: '.$e->getMessage());
            Log::error('Fatal error in tv:reprocess-unmatched: '.$e->getMessage()."\n".$e->getTraceAsString());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Processing complete!');
        $this->table(
            ['Total Processed', 'Matched', 'Not Matched', 'Match Rate'],
            [[
                number_format($processed),
                number_format($matched),
                number_format($failed),
                self::formatMatchRate($matched, $processed),
            ]]
        );

        return self::SUCCESS;
    }

    /**
     * The `tv_episodes_id` lookup states reprocessed by this command.
     *
     * @return list<int>
     */
    public static function buildTargetStatuses(bool $includeParseFailed): array
    {
        if (self::$useRustFfi) {
            return self::buildTargetStatusesNative($includeParseFailed);
        }

        return self::buildTargetStatusesPhp($includeParseFailed);
    }

    /**
     * @return list<int>
     */
    public static function buildTargetStatusesPhp(bool $includeParseFailed): array
    {
        $statuses = [TvLookupStatus::Pending->value, TvLookupStatus::ShowNotFound->value];
        if ($includeParseFailed) {
            $statuses[] = TvLookupStatus::ParseFailed->value;
        }

        return $statuses;
    }

    /**
     * @return list<int>
     */
    public static function buildTargetStatusesNative(bool $includeParseFailed): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildTargetStatusesPhp($includeParseFailed);
        }

        try {
            $values = $ffi->new('int64_t[3]');
            $count = (int) self::callFfi(
                $ffi,
                'nntmux_reprocess_unmatched_tv_target_statuses',
                $includeParseFailed ? 1 : 0,
                $values,
                3,
            );
            if ($count < 0) {
                return self::buildTargetStatusesPhp($includeParseFailed);
            }

            $statuses = [];
            for ($i = 0; $i < $count; $i++) {
                $statuses[] = (int) $values[$i];
            }

            return $statuses;
        } catch (Throwable) {
            return self::buildTargetStatusesPhp($includeParseFailed);
        }
    }

    public static function clampProcessCount(int $totalCount, int $limit): int
    {
        if (self::$useRustFfi) {
            return self::clampProcessCountNative($totalCount, $limit);
        }

        return self::clampProcessCountPhp($totalCount, $limit);
    }

    public static function clampProcessCountPhp(int $totalCount, int $limit): int
    {
        return $limit > 0 ? min($limit, $totalCount) : $totalCount;
    }

    public static function clampProcessCountNative(int $totalCount, int $limit): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::clampProcessCountPhp($totalCount, $limit);
        }

        try {
            $out = $ffi->new('int64_t[1]');
            $rc = (int) self::callFfi($ffi, 'nntmux_reprocess_unmatched_tv_clamp_process_count', $totalCount, $limit, $out);

            return $rc === 0 ? (int) $out[0] : self::clampProcessCountPhp($totalCount, $limit);
        } catch (Throwable) {
            return self::clampProcessCountPhp($totalCount, $limit);
        }
    }

    public static function formatMatchRate(int $matched, int $processed): string
    {
        if (self::$useRustFfi) {
            return self::formatMatchRateNative($matched, $processed);
        }

        return self::formatMatchRatePhp($matched, $processed);
    }

    public static function formatMatchRatePhp(int $matched, int $processed): string
    {
        return $processed > 0 ? round(($matched / $processed) * 100, 2).'%' : '0%';
    }

    public static function formatMatchRateNative(int $matched, int $processed): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatMatchRatePhp($matched, $processed);
        }

        try {
            return self::callBufferFfi(
                $ffi,
                64,
                'nntmux_reprocess_unmatched_tv_format_match_rate',
                $matched,
                $processed,
            ) ?? self::formatMatchRatePhp($matched, $processed);
        } catch (Throwable) {
            return self::formatMatchRatePhp($matched, $processed);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    /**
     * Runs a buffer-writing export, appending the scratch buffer and its capacity; `null` when it declines.
     */
    private static function callBufferFfi(FFI $ffi, int $minBytes, string $symbol, mixed ...$args): ?string
    {
        $cap = 0;
        $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, $minBytes), $cap);
        if ($buf === null) {
            return null;
        }

        $rc = (int) $ffi->$symbol(...[...$args, $buf, $cap]);
        if ($rc < 0) {
            return null;
        }

        return $rc > 0 ? FFI::string($buf, $rc) : '';
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::FFI_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
