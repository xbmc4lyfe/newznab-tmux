<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Services\TvProcessing\TvProcessingPipeline;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReprocessTvRelease extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_reprocess_tv_release_truncate_search_name(
        const char *search_name_ptr,
        size_t search_name_len,
        int64_t max_chars,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_reprocess_tv_release_final_state_retry_at(
        uint32_t has_retry_at,
        const char *retry_at_ptr,
        size_t retry_at_len,
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
    protected $signature = 'tv:reprocess
                            {guid : The release GUID to reprocess}
                            {--reset : Reset the release video/episode IDs before reprocessing}
                            {--debug : Show detailed debug information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprocess a specific TV release by GUID to rematch it against TV databases';

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
        $guid = $this->argument('guid');
        $reset = $this->option('reset');
        $debug = $this->option('debug');

        // Find the release by GUID
        $release = Release::query()->where('guid', $guid)->first();

        if ($release === null) {
            $this->error("Release with GUID '{$guid}' not found.");

            return self::FAILURE;
        }

        $this->info('Found release:');
        $this->table(
            ['ID', 'GUID', 'Search Name', 'Videos ID', 'Episode ID', 'Category'],
            [[
                $release->id,
                $release->guid,
                self::truncateSearchName((string) $release->searchname),
                $release->videos_id,
                $release->tv_episodes_id,
                $release->categories_id,
            ]]
        );

        if ($reset) {
            $this->warn('Resetting videos_id, tv_episodes_id and the lookup retry state...');
            $release->update(TvProcessingCandidateQuery::resetColumns());
            $release->refresh();
        }

        $this->info('Processing release through TV pipeline...');
        $this->newLine();

        try {
            $result = app(TvProcessingPipeline::class)->processRelease($release, (bool) $debug);

            $this->newLine();

            if ($result['matched']) {
                cli()->primary('Successfully matched!');
                $this->info('Provider: '.($result['provider'] ?? 'Unknown'));
                $this->info('Video ID: '.($result['video_id'] ?? 'N/A'));
                $this->info('Episode ID: '.($result['episode_id'] ?? 'N/A'));
            } else {
                cli()->warning('No episode match.');
                $this->info('Status: '.($result['status'] ?? 'Unknown'));
                if (! empty($result['video_id'])) {
                    $this->info('Video ID: '.$result['video_id']);
                }
                if (! empty($result['provider'])) {
                    $this->info('Provider: '.$result['provider']);
                }
            }

            if ($debug && isset($result['debug'])) {
                $this->newLine();
                $this->info('Debug Information:');
                $this->line(json_encode($result['debug'], JSON_PRETTY_PRINT));
            }

            // Show final state of the release
            $release->refresh();
            $this->newLine();
            $this->info('Final release state:');
            $this->table(
                ['Videos ID', 'Episode ID', 'Lookup attempts', 'Retry at'],
                [self::projectFinalStateRow($release->videos_id, $release->tv_episodes_id, $release->tv_lookup_attempts, $release->tv_lookup_retry_at)]
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error('Error processing release: '.$e->getMessage());
            if ($debug) {
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * `$maxChars` UTF-8 characters, with `...` appended when the name is longer than `$maxChars` bytes.
     */
    public static function truncateSearchName(string $searchName, int $maxChars = 60): string
    {
        if (self::$useRustFfi) {
            return self::truncateSearchNameNative($searchName, $maxChars);
        }

        return self::truncateSearchNamePhp($searchName, $maxChars);
    }

    public static function truncateSearchNamePhp(string $searchName, int $maxChars = 60): string
    {
        return mb_substr($searchName, 0, $maxChars).(strlen($searchName) > $maxChars ? '...' : '');
    }

    public static function truncateSearchNameNative(string $searchName, int $maxChars = 60): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::truncateSearchNamePhp($searchName, $maxChars);
        }

        try {
            return self::callBufferFfi(
                $ffi,
                strlen($searchName) + 4,
                'nntmux_reprocess_tv_release_truncate_search_name',
                $searchName,
                strlen($searchName),
                $maxChars,
            ) ?? self::truncateSearchNamePhp($searchName, $maxChars);
        } catch (Throwable) {
            return self::truncateSearchNamePhp($searchName, $maxChars);
        }
    }

    /**
     * The "Final release state" table row; a missing retry time renders as `-`.
     *
     * @return list<mixed>
     */
    public static function projectFinalStateRow(mixed $videosId, mixed $tvEpisodesId, mixed $attempts, mixed $retryAt): array
    {
        if (self::$useRustFfi) {
            return self::projectFinalStateRowNative($videosId, $tvEpisodesId, $attempts, $retryAt);
        }

        return self::projectFinalStateRowPhp($videosId, $tvEpisodesId, $attempts, $retryAt);
    }

    /**
     * @return list<mixed>
     */
    public static function projectFinalStateRowPhp(mixed $videosId, mixed $tvEpisodesId, mixed $attempts, mixed $retryAt): array
    {
        return [$videosId, $tvEpisodesId, $attempts, $retryAt ?? '-'];
    }

    /**
     * @return list<mixed>
     */
    public static function projectFinalStateRowNative(mixed $videosId, mixed $tvEpisodesId, mixed $attempts, mixed $retryAt): array
    {
        // Non-string values (e.g. a cast date) keep their own table rendering.
        $ffi = $retryAt === null || is_string($retryAt) ? self::ffi() : null;
        if ($ffi === null) {
            return self::projectFinalStateRowPhp($videosId, $tvEpisodesId, $attempts, $retryAt);
        }

        $retryStr = $retryAt ?? '';

        try {
            $out = self::callBufferFfi(
                $ffi,
                strlen($retryStr) + 1,
                'nntmux_reprocess_tv_release_final_state_retry_at',
                $retryAt !== null ? 1 : 0,
                $retryStr,
                strlen($retryStr),
            );

            return $out === null
                ? self::projectFinalStateRowPhp($videosId, $tvEpisodesId, $attempts, $retryAt)
                : [$videosId, $tvEpisodesId, $attempts, $out];
        } catch (Throwable) {
            return self::projectFinalStateRowPhp($videosId, $tvEpisodesId, $attempts, $retryAt);
        }
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
