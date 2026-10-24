<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Configuration\ConfigurationProvider;
use App\Services\Runners\BackfillRunner;
use App\Services\Runners\BinariesRunner;
use App\Services\Runners\PostProcessRunner;
use App\Services\Runners\ReleasesRunner;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Service for multiprocessing various tasks.
 *
 * This service orchestrates parallel processing of usenet indexing tasks
 * by delegating to specialized runner classes.
 */
class ForkingService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_fork_max_retries(int64_t max_nfo_retries, int64_t *out);
        int32_t nntmux_fork_work_type(const char *ptr, size_t len);
        ssize_t nntmux_fork_timing_header(const char *task, size_t task_len, int64_t elapsed, const char *date, size_t date_len, char *out, size_t cap);
        C;

    /**
     * Work types accepted by {@see processWorkType()}, in its match order.
     *
     * @var list<string>
     */
    public const array WORK_TYPES = [
        'backfill',
        'binaries',
        'fixRelNames_standard',
        'fixRelNames_predbft',
        'releases',
        'postProcess_add',
        'postProcess_ani',
        'postProcess_ama',
        'postProcess_boo',
        'postProcess_con',
        'postProcess_gam',
        'postProcess_mov',
        'postProcess_mus',
        'postProcess_nfo',
        'postProcess_tv',
        'safe_backfill',
        'safe_binaries',
        'update_per_group',
    ];

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected BackfillRunner $backfillRunner;

    protected BinariesRunner $binariesRunner;

    protected ReleasesRunner $releasesRunner;

    protected PostProcessRunner $postProcessRunner;

    protected int $maxRetries;

    public function __construct()
    {
        $this->maxRetries = self::maxRetriesFor((int) app(ConfigurationProvider::class)->postProcessing()->maxNfoRetries);

        // Initialize runners
        $this->backfillRunner = new BackfillRunner;
        $this->binariesRunner = new BinariesRunner;
        $this->releasesRunner = new ReleasesRunner;
        $this->postProcessRunner = new PostProcessRunner;
    }

    /**
     * Process backfill for all groups with backfill enabled.
     *
     * @param  array<string, mixed>  $options
     */
    public function backfill(array $options = []): void
    {
        $this->runWithTiming('backfill', fn () => $this->backfillRunner->backfill($options));
    }

    /**
     * Process safe backfill (ordered by oldest first).
     */
    public function safeBackfill(): void
    {
        $this->runWithTiming('safe_backfill', fn () => $this->backfillRunner->safeBackfill());
    }

    /**
     * Download binaries (new headers) for all active groups.
     */
    public function binaries(int $maxPerGroup = 0): void
    {
        $this->runWithTiming('binaries', fn () => $this->binariesRunner->binaries($maxPerGroup));
    }

    /**
     * Process safe binaries (ordered by most recent activity).
     */
    public function safeBinaries(): void
    {
        $this->runWithTiming('safe_binaries', fn () => $this->binariesRunner->safeBinaries());
    }

    /**
     * Process releases for all groups.
     */
    public function releases(): void
    {
        $this->runWithTiming('releases', function () {
            $this->releasesRunner->releases();
            $this->processReleasesEndWork();
        });
    }

    /**
     * Update binaries and releases per group.
     */
    public function updatePerGroup(): void
    {
        $this->runWithTiming('update_per_group', function () {
            $this->releasesRunner->updatePerGroup();
            $this->processReleasesEndWork();
        });
    }

    /**
     * Fix release names using specified mode.
     *
     * @param  string  $mode  'standard' or 'predbft'
     */
    public function fixRelNames(string $mode): void
    {
        $this->runWithTiming("fixRelNames_{$mode}", fn () => $this->releasesRunner->fixRelNames(
            $mode,
            (int) app(ConfigurationProvider::class)->postProcessing()->fixNamesPerRun,
            (int) app(ConfigurationProvider::class)->postProcessing()->fixNameThreads
        ));
    }

    /**
     * Process additional post-processing (preview images, samples, etc).
     */
    public function processAdditional(): void
    {
        $this->runWithTiming('postProcess_add', fn () => $this->postProcessRunner->processAdditional());
    }

    /**
     * Process NFO files.
     */
    public function processNfo(): void
    {
        $this->runWithTiming('postProcess_nfo', fn () => $this->postProcessRunner->processNfo());
    }

    /**
     * Process movie metadata.
     */
    public function processMovies(bool $renamedOnly = false): void
    {
        $this->runWithTiming('postProcess_mov', fn () => $this->postProcessRunner->processMovies($renamedOnly));
    }

    /**
     * Process TV metadata.
     */
    public function processTv(bool $renamedOnly = false): void
    {
        $this->runWithTiming('postProcess_tv', function () use ($renamedOnly) {
            if ($this->postProcessRunner->hasTvWork($renamedOnly)) {
                $this->postProcessRunner->processTv($renamedOnly);
            } else {
                $this->log('No TV work to do.');
            }
        });
    }

    /**
     * Process anime metadata.
     */
    public function processAnime(): void
    {
        $this->runWithTiming('postProcess_ani', fn () => $this->postProcessRunner->processAnime());
    }

    /**
     * Process book metadata.
     */
    public function processBooks(): void
    {
        $this->runWithTiming('postProcess_boo', fn () => $this->postProcessRunner->processBooks());
    }

    /**
     * Process music metadata.
     */
    public function processMusic(): void
    {
        $this->runWithTiming('postProcess_mus', fn () => $this->postProcessRunner->processMusic());
    }

    /**
     * Process console game metadata.
     */
    public function processConsoles(): void
    {
        $this->runWithTiming('postProcess_con', fn () => $this->postProcessRunner->processConsoles());
    }

    /**
     * Process PC game metadata.
     */
    public function processGames(): void
    {
        $this->runWithTiming('postProcess_gam', fn () => $this->postProcessRunner->processGames());
    }

    /**
     * Process books, music, console, and games metadata in parallel.
     */
    public function processAmazon(): void
    {
        $this->runWithTiming('postProcess_ama', fn () => $this->postProcessRunner->processAmazon());
    }

    /**
     * Generic work type processor for backwards compatibility.
     *
     * @deprecated Use specific methods instead (e.g., backfill(), binaries(), etc.)
     *
     * @param  array<string, mixed>  $options
     */
    public function processWorkType(string $type, array $options = []): void
    {
        if (self::workTypeIndex($type) === null) {
            Log::warning("Unknown work type: {$type}");

            return;
        }

        match ($type) {
            'backfill' => $this->backfill($options),
            'binaries' => $this->binaries((int) ($options[0] ?? 0)),
            'fixRelNames_standard' => $this->fixRelNames('standard'),
            'fixRelNames_predbft' => $this->fixRelNames('predbft'),
            'releases' => $this->releases(),
            'postProcess_add' => $this->processAdditional(),
            'postProcess_ani' => $this->processAnime(),
            'postProcess_ama' => $this->processAmazon(),
            'postProcess_boo' => $this->processBooks(),
            'postProcess_con' => $this->processConsoles(),
            'postProcess_gam' => $this->processGames(),
            'postProcess_mov' => $this->processMovies($options[0] ?? false),
            'postProcess_mus' => $this->processMusic(),
            'postProcess_nfo' => $this->processNfo(),
            'postProcess_tv' => $this->processTv($options[0] ?? false),
            'safe_backfill' => $this->safeBackfill(),
            'safe_binaries' => $this->safeBinaries(),
            'update_per_group' => $this->updatePerGroup(),
            default => Log::warning("Unknown work type: {$type}"),
        };
    }

    /**
     * Run a task with timing output.
     */
    protected function runWithTiming(string $taskName, callable $task): void
    {
        $startTime = now()->timestamp;

        try {
            $task();
        } catch (Throwable $e) {
            Log::error("Task {$taskName} failed: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }

        if (config('nntmux.echocli')) {
            cli()->header(self::timingHeader($taskName, now()->timestamp - $startTime, now()->toRfc2822String()));
        }
    }

    /**
     * Run release maintenance after the per-group workers finish.
     */
    protected function processReleasesEndWork(): void
    {
        $this->executeCommand([PHP_BINARY, base_path('artisan'), 'releases:process', '--maintenance-only']);
    }

    /**
     * Execute a shell command.
     *
     * @param  string|list<string>  $command
     */
    protected function executeCommand(string|array $command): string
    {
        $process = is_array($command) ? new Process($command, base_path()) : Process::fromShellCommandline($command, base_path());
        $process->setTimeout((int) config('nntmux.multiprocessing_max_child_time', 1800));
        $process->run(function ($type, $buffer) {
            if ($type === Process::ERR) {
                echo $buffer;
            }
        });

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Worker failed with exit code '.$process->getExitCode().'.');
        }

        return $process->getOutput();
    }

    /**
     * Log a message to console.
     */
    protected function log(string $message): void
    {
        if (config('nntmux.echocli')) {
            echo $message.PHP_EOL;
        }
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    /**
     * NFO retry marker for a `maxNfoRetries` setting: `-(retries + 1)` when non-negative,
     * otherwise {@see NfoService::NFO_UNPROC}, never below -8.
     */
    public static function maxRetriesFor(int $maxNfoRetries): int
    {
        return self::usesRustFfi() ? self::maxRetriesForNative($maxNfoRetries) : self::maxRetriesForPhp($maxNfoRetries);
    }

    public static function maxRetriesForPhp(int $maxNfoRetries): int
    {
        $maxRetries = $maxNfoRetries >= 0 ? -($maxNfoRetries + 1) : NfoService::NFO_UNPROC;

        // `-(PHP_INT_MAX + 1)` is a float; it still clamps to -8.
        return (int) max($maxRetries, -8);
    }

    public static function maxRetriesForNative(int $maxNfoRetries): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::maxRetriesForPhp($maxNfoRetries);
        }
        try {
            $out = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_fork_max_retries($maxNfoRetries, $out);
            if ($code !== 0) {
                return self::maxRetriesForPhp($maxNfoRetries);
            }
            $maxRetries = $out[0];
        } catch (Throwable) {
            return self::maxRetriesForPhp($maxNfoRetries);
        }

        return \is_int($maxRetries) ? $maxRetries : self::maxRetriesForPhp($maxNfoRetries);
    }

    /**
     * Position of `$type` in {@see WORK_TYPES} (exact match), or null for an unknown type.
     */
    public static function workTypeIndex(string $type): ?int
    {
        return self::usesRustFfi() ? self::workTypeIndexNative($type) : self::workTypeIndexPhp($type);
    }

    public static function workTypeIndexPhp(string $type): ?int
    {
        $index = array_search($type, self::WORK_TYPES, true);

        return $index === false ? null : $index;
    }

    public static function workTypeIndexNative(string $type): ?int
    {
        if (($ffi = self::ffi()) === null) {
            return self::workTypeIndexPhp($type);
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_fork_work_type($type, \strlen($type));
        } catch (Throwable) {
            return self::workTypeIndexPhp($type);
        }
        if ($index === -2) {
            return null;
        }

        return \is_int($index) && isset(self::WORK_TYPES[$index]) ? $index : self::workTypeIndexPhp($type);
    }

    /**
     * Console line reporting how long a multi-processing task took.
     */
    public static function timingHeader(string $taskName, int $elapsedSeconds, string $finishedAt): string
    {
        return self::usesRustFfi()
            ? self::timingHeaderNative($taskName, $elapsedSeconds, $finishedAt)
            : self::timingHeaderPhp($taskName, $elapsedSeconds, $finishedAt);
    }

    public static function timingHeaderPhp(string $taskName, int $elapsedSeconds, string $finishedAt): string
    {
        return "Multi-processing for {$taskName} finished in ".$elapsedSeconds.
            ' seconds at '.$finishedAt.'.'.PHP_EOL;
    }

    public static function timingHeaderNative(string $taskName, int $elapsedSeconds, string $finishedAt): string
    {
        if (($ffi = self::ffi()) === null || PHP_EOL !== "\n") {
            return self::timingHeaderPhp($taskName, $elapsedSeconds, $finishedAt);
        }
        $needed = \strlen($taskName) + \strlen($finishedAt) + 80;
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, $needed, $capacity);
        if ($buffer === null) {
            return self::timingHeaderPhp($taskName, $elapsedSeconds, $finishedAt);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fork_timing_header($taskName, \strlen($taskName), $elapsedSeconds, $finishedAt, \strlen($finishedAt), $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::timingHeaderPhp($taskName, $elapsedSeconds, $finishedAt);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::timingHeaderPhp($taskName, $elapsedSeconds, $finishedAt);
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
