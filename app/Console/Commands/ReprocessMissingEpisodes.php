<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Services\TvProcessing\TvProcessingPipeline;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retry the episode lookup for TV releases whose show is known but whose episode
 * wasn't available yet (tv_episodes_id = -11). Scheduled every 30 minutes; each
 * release backs off 1h, 6h, 24h, 72h, then 7 days before giving up.
 */
class ReprocessMissingEpisodes extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_reprocess_missing_episodes_process_count(
        int64_t limit,
        int64_t total_count,
        int64_t *out_count
    );
    ssize_t nntmux_reprocess_missing_episodes_truncate_search_name(
        const char *search_name_ptr,
        size_t search_name_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_reprocess_missing_episodes_format_summary(
        int64_t processed,
        int64_t matched,
        int64_t failed,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminAnidbBookMediaCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tv:reprocess-missing-episodes
                            {--limit=0 : Limit the number of releases to process (0 = no limit)}
                            {--video-id= : Process only releases for a specific video ID}
                            {--all : Ignore the retry schedule and attempt cap}

                            {--scheduled : Respect the configured TV lookup mode}
                            {--dry-run : Show how many releases would be processed without making changes}
                            {--debug : Show detailed debug information for each release}
                            {--sleep=0 : Sleep time in milliseconds between processing each release}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry the episode lookup for TV releases whose show matched but whose episode was missing';

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
        $lookupMode = $this->option('scheduled')
            ? (int) app(ConfigurationProvider::class)->metadata()->tvLookup->value : 1;
        if ($lookupMode <= 0) {
            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $videoId = $this->option('video-id');
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');
        $sleep = (int) $this->option('sleep');

        // Newest first, so episodes that just aired aren't queued behind an old backlog.
        // The run state still downloads each show's episode list only once.
        $query = Release::query()
            ->select(['id', 'guid', 'searchname', 'videos_id', 'tv_episodes_id', 'categories_id', 'groups_id', 'postdate'])
            ->whereRaw(TvProcessingCandidateQuery::episodeRetryCondition((bool) $this->option('all')))
            ->orderByDesc('postdate')
            ->orderByDesc('id');

        if ($lookupMode === 2) {
            $query->where('isrenamed', 1);
        }

        if ($videoId !== null) {
            $query->where('videos_id', (int) $videoId);
            $this->info("Filtering by video ID: {$videoId}");
        }

        $totalCount = (clone $query)->count();

        if ($totalCount === 0) {
            $this->info('No TV releases with missing episodes are due for a retry.');

            return self::SUCCESS;
        }

        $processCount = self::resolveProcessCount($limit, $totalCount);
        if ($limit > 0) {
            $query->limit($limit);
        }
        $this->info("Found {$totalCount} TV release(s) with missing episodes due for a retry; processing {$processCount}.");

        if ($dryRun) {
            $sample = (clone $query)->limit(15)->get();
            $this->table(
                ['ID', 'Video ID', 'Search Name'],
                $sample->map(fn ($release) => self::formatDryRunSampleRow(
                    $release->id,
                    $release->videos_id,
                    (string) $release->searchname,
                ))->toArray()
            );

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($processCount);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | Matched: %matched% | Missing: %failed% | Elapsed: %elapsed:6s%');
        $bar->setMessage('0', 'matched');
        $bar->setMessage('0', 'failed');
        $bar->start();

        $matched = 0;
        $failed = 0;
        $processed = 0;

        $pipeline = app(TvProcessingPipeline::class)->setEchoOutput(false);
        $runState = $pipeline->newRunState();

        foreach ($query->cursor() as $release) {
            try {
                $result = $pipeline->processRelease($release, $debug, $runState);

                if ($result['matched']) {
                    $matched++;
                    if ($debug) {
                        $this->newLine();
                        cli()->primary("Episode matched: {$release->searchname} (".($result['provider'] ?? 'unknown').')');
                    }
                } else {
                    $failed++;
                    if ($debug) {
                        $this->newLine();
                        cli()->warning("Episode not found: {$release->searchname} ({$result['status']})");
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error("Error processing release {$release->guid}: ".$e->getMessage());
            }

            $processed++;
            $bar->setMessage((string) $matched, 'matched');
            $bar->setMessage((string) $failed, 'failed');
            $bar->advance();

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Total Processed', 'Episodes Matched', 'Still Missing', 'Match Rate'],
            [self::formatSummaryRow($processed, $matched, $failed)]
        );

        return self::SUCCESS;
    }

    public static function resolveProcessCount(int $limit, int $totalCount): int
    {
        if (self::$useRustFfi) {
            return self::resolveProcessCountNative($limit, $totalCount);
        }

        return self::resolveProcessCountPhp($limit, $totalCount);
    }

    public static function resolveProcessCountPhp(int $limit, int $totalCount): int
    {
        return $limit > 0 ? min($limit, $totalCount) : $totalCount;
    }

    public static function resolveProcessCountNative(int $limit, int $totalCount): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolveProcessCountPhp($limit, $totalCount);
        }

        try {
            $out = $ffi->new('int64_t[1]');
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_reprocess_missing_episodes_process_count',
                $limit,
                $totalCount,
                $out
            );
            if ($rc !== 0) {
                return self::resolveProcessCountPhp($limit, $totalCount);
            }

            return (int) $out[0];
        } catch (Throwable) {
            return self::resolveProcessCountPhp($limit, $totalCount);
        }
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: string}
     */
    public static function formatDryRunSampleRow(
        mixed $id,
        mixed $videoId,
        string $searchName,
    ): array {
        if (self::$useRustFfi) {
            return self::formatDryRunSampleRowNative($id, $videoId, $searchName);
        }

        return self::formatDryRunSampleRowPhp($id, $videoId, $searchName);
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: string}
     */
    public static function formatDryRunSampleRowPhp(
        mixed $id,
        mixed $videoId,
        string $searchName,
    ): array {
        return [
            $id,
            $videoId,
            mb_substr($searchName, 0, 55).(mb_strlen($searchName) > 55 ? '...' : ''),
        ];
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: string}
     */
    public static function formatDryRunSampleRowNative(
        mixed $id,
        mixed $videoId,
        string $searchName,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatDryRunSampleRowPhp($id, $videoId, $searchName);
        }

        try {
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($searchName) + 16), $cap);
            if ($buf === null) {
                return self::formatDryRunSampleRowPhp($id, $videoId, $searchName);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_reprocess_missing_episodes_truncate_search_name',
                $searchName,
                strlen($searchName),
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::formatDryRunSampleRowPhp($id, $videoId, $searchName);
            }

            return [
                $id,
                $videoId,
                $rc > 0 ? FFI::string($buf, $rc) : '',
            ];
        } catch (Throwable) {
            return self::formatDryRunSampleRowPhp($id, $videoId, $searchName);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public static function formatSummaryRow(int $processed, int $matched, int $failed): array
    {
        if (self::$useRustFfi) {
            return self::formatSummaryRowNative($processed, $matched, $failed);
        }

        return self::formatSummaryRowPhp($processed, $matched, $failed);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public static function formatSummaryRowPhp(int $processed, int $matched, int $failed): array
    {
        return [
            number_format($processed),
            number_format($matched),
            number_format($failed),
            $processed > 0 ? round(($matched / $processed) * 100, 2).'%' : '0%',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public static function formatSummaryRowNative(int $processed, int $matched, int $failed): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatSummaryRowPhp($processed, $matched, $failed);
        }

        if (abs($processed) > 9_000_000_000_000_000
            || abs($matched) > 9_000_000_000_000_000
            || abs($failed) > 9_000_000_000_000_000) {
            return self::formatSummaryRowPhp($processed, $matched, $failed);
        }

        try {
            $lens = $ffi->new('size_t[4]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
            if ($buf === null) {
                return self::formatSummaryRowPhp($processed, $matched, $failed);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_reprocess_missing_episodes_format_summary',
                $processed,
                $matched,
                $failed,
                $lens,
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::formatSummaryRowPhp($processed, $matched, $failed);
            }

            $raw = $rc > 0 ? FFI::string($buf, $rc) : '';
            $l0 = (int) $lens[0];
            $l1 = (int) $lens[1];
            $l2 = (int) $lens[2];
            $l3 = (int) $lens[3];

            return [
                $l0 > 0 ? substr($raw, 0, $l0) : '',
                $l1 > 0 ? substr($raw, $l0, $l1) : '',
                $l2 > 0 ? substr($raw, $l0 + $l1, $l2) : '',
                $l3 > 0 ? substr($raw, $l0 + $l1 + $l2, $l3) : '',
            ];
        } catch (Throwable) {
            return self::formatSummaryRowPhp($processed, $matched, $failed);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
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
