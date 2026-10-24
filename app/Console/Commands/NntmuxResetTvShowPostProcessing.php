<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Models\Video;
use App\Services\TvProcessing\TvProcessingCandidateQuery;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class NntmuxResetTvShowPostProcessing extends Command
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_reset_tv_show_format_video_not_found(int64_t video_id, char *out_buf, size_t out_cap);
    ssize_t nntmux_reset_tv_show_format_dry_run(
        int64_t total,
        const char *title_ptr,
        size_t title_len,
        int64_t video_id,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_reset_tv_show_format_resetting(
        const char *title_ptr,
        size_t title_len,
        int64_t video_id,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_reset_tv_show_format_reset_complete(int64_t updated, char *out_buf, size_t out_cap);
    CDEF;

    /**
     * Rust stays opt-in: the FFI round trip is slower than these PHP helpers (< 1.5x); see
     * tests/Unit/RustPostProcessTvAniDbSteamCommandsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * videos_id: ID from videos table (type = 0 TV) whose releases should be reset.
     * --dry-run: Show how many releases would be reset without making changes.
     */
    protected $signature = 'nntmux:reset-tv-show {videos_id : ID of the TV show (videos.id)} {--dry-run : Show counts only, do not modify releases}';

    /**
     * The console command description.
     */
    protected $description = 'Reset postprocessing for all TV releases belonging to a specific videos_id so they can be reprocessed again.';

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = self::RUST_FFI_DEFAULT;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    public static function formatVideoNotFoundMessage(int $videoId): string
    {
        return self::$useRustFfi ? self::formatVideoNotFoundMessageNative($videoId) : self::formatVideoNotFoundMessagePhp($videoId);
    }

    public static function formatVideoNotFoundMessagePhp(int $videoId): string
    {
        return "videos_id {$videoId} not found or is not a TV show (type=0)";
    }

    public static function formatVideoNotFoundMessageNative(int $videoId): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatVideoNotFoundMessagePhp($videoId);
        }

        try {
            return self::callBufferFfi($ffi, 128, 'nntmux_reset_tv_show_format_video_not_found', $videoId)
                ?? self::formatVideoNotFoundMessagePhp($videoId);
        } catch (Throwable) {
            return self::formatVideoNotFoundMessagePhp($videoId);
        }
    }

    public static function formatDryRunSummary(int $total, string $title, int $videoId): string
    {
        return self::$useRustFfi ? self::formatDryRunSummaryNative($total, $title, $videoId) : self::formatDryRunSummaryPhp($total, $title, $videoId);
    }

    public static function formatDryRunSummaryPhp(int $total, string $title, int $videoId): string
    {
        return "[Dry Run] Would reset {$total} release(s) for TV show: {$title} (videos_id={$videoId})";
    }

    public static function formatDryRunSummaryNative(int $total, string $title, int $videoId): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatDryRunSummaryPhp($total, $title, $videoId);
        }

        try {
            return self::callBufferFfi($ffi, strlen($title) + 128, 'nntmux_reset_tv_show_format_dry_run', $total, $title, strlen($title), $videoId)
                ?? self::formatDryRunSummaryPhp($total, $title, $videoId);
        } catch (Throwable) {
            return self::formatDryRunSummaryPhp($total, $title, $videoId);
        }
    }

    public static function formatResettingMessage(string $title, int $videoId): string
    {
        return self::$useRustFfi ? self::formatResettingMessageNative($title, $videoId) : self::formatResettingMessagePhp($title, $videoId);
    }

    public static function formatResettingMessagePhp(string $title, int $videoId): string
    {
        return "Resetting postprocessing for TV show: {$title} (videos_id={$videoId})";
    }

    public static function formatResettingMessageNative(string $title, int $videoId): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatResettingMessagePhp($title, $videoId);
        }

        try {
            return self::callBufferFfi($ffi, strlen($title) + 128, 'nntmux_reset_tv_show_format_resetting', $title, strlen($title), $videoId)
                ?? self::formatResettingMessagePhp($title, $videoId);
        } catch (Throwable) {
            return self::formatResettingMessagePhp($title, $videoId);
        }
    }

    public static function formatResetCompleteMessage(int $updated): string
    {
        return self::$useRustFfi ? self::formatResetCompleteMessageNative($updated) : self::formatResetCompleteMessagePhp($updated);
    }

    public static function formatResetCompleteMessagePhp(int $updated): string
    {
        return number_format($updated).' release(s) reset. They will be considered for TV postprocessing again.';
    }

    public static function formatResetCompleteMessageNative(int $updated): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatResetCompleteMessagePhp($updated);
        }

        try {
            return self::callBufferFfi($ffi, 128, 'nntmux_reset_tv_show_format_reset_complete', $updated)
                ?? self::formatResetCompleteMessagePhp($updated);
        } catch (Throwable) {
            return self::formatResetCompleteMessagePhp($updated);
        }
    }

    public function handle(): int
    {
        $videosId = (int) $this->argument('videos_id');
        $dryRun = (bool) $this->option('dry-run');

        // Validate target show exists and is TV type (0)
        $video = Video::query()->where('id', $videosId)->where('type', 0)->first(['id', 'title']);
        if ($video === null) {
            $this->error(self::formatVideoNotFoundMessage($videosId));

            return self::FAILURE;
        }

        // Gather releases to reset
        $baseQuery = Release::query()
            ->select(['id'])
            ->where('videos_id', $videosId)
            ->whereBetween('categories_id', [Category::TV_ROOT, Category::TV_OTHER]);

        $total = (clone $baseQuery)->count();
        if ($total === 0) {
            $this->info('No releases found for this videos_id in TV categories');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line(self::formatDryRunSummary($total, (string) $video->title, $videosId));

            return self::SUCCESS;
        }

        $this->info(self::formatResettingMessage((string) $video->title, $videosId));

        // Progress bar
        $bar = $this->output->createProgressBar($total);
        $bar->setOverwrite(true);
        $bar->start();

        // Chunk through releases to avoid loading all models simultaneously
        $baseQuery->chunkById(1000, function ($chunk) use ($bar) {
            foreach ($chunk as $release) {
                // Reset association so TV pipeline picks them up again
                Release::query()->where('id', $release->id)->update(TvProcessingCandidateQuery::resetColumns());
                Search::updateRelease((int) $release->id);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info(self::formatResetCompleteMessage($total));
        $this->line('You can now run: php artisan postprocess:tv-pipeline <guid-bucket> (or your existing TV processing routines)');

        return self::SUCCESS;
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
