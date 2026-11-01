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
use FFI\CData;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

    // ---------------------------------------------------------------------
    // Wave 50.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.82x, p99 2.83x; uncommon mean 2.46x, p99 2.06x); see RustBookCandidateFanartForkingGenreInvitationW50ParityTest. */
    private const bool W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w504_fork_timing_parse_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Work type index reported by parseTimingHeaderBatch() for a line that is not a timing header. */
    public const int W50_NOT_A_HEADER = -2;

    private const string W50_PREFIX = 'Multi-processing for ';

    private static ?bool $useW50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$useW50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W50 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW50RustFfi;
        self::$useW50RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW50RustFfi = $previous;
        }
    }

    /**
     * Parse timingHeader() console lines, four ints each: [work type index, elapsed seconds,
     * task length, date offset << 32 | date length]. One trailing "\n" is ignored. A header
     * is "Multi-processing for {task} finished in {1-18 digits} seconds at {date}." with the
     * task ending at the first " finished in "; the index is the task's exact position in
     * WORK_TYPES or -1. Other lines yield [W50_NOT_A_HEADER, 0, 0, 0].
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function parseTimingHeaderBatch(array $lines): array
    {
        return self::usesW50RustFfi()
            ? self::parseTimingHeaderBatchNative($lines)
            : self::parseTimingHeaderBatchPhp($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function parseTimingHeaderBatchNative(array $lines): array
    {
        self::w50AssertList($lines);
        if ($lines === []) {
            return [];
        }

        return self::w50Call(implode("\0", $lines), '', \count($lines))
            ?? self::parseTimingHeaderBatchPhp($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function parseTimingHeaderBatchPhp(array $lines): array
    {
        self::w50AssertList($lines);
        static $indexes = null;
        $indexes ??= array_flip(self::WORK_TYPES);
        $out = [];
        foreach ($lines as $line) {
            if (str_ends_with($line, "\n")) {
                $line = substr($line, 0, -1);
            }
            $finished = str_starts_with($line, self::W50_PREFIX) ? strpos($line, ' finished in ', 21) : false;
            if ($finished !== false) {
                $digits = strspn($line, '0123456789', $finished + 13);
                $dateStart = $finished + 25 + $digits;
                $length = \strlen($line);
                if ($digits >= 1 && $digits <= 18 && substr_compare($line, ' seconds at ', $finished + 13 + $digits, 12) === 0
                    && $length > $dateStart && $line[$length - 1] === '.') {
                    $task = substr($line, 21, $finished - 21);
                    array_push($out, $indexes[$task] ?? -1, (int) substr($line, $finished + 13, $digits), $finished - 21, ($dateStart << 32) | ($length - 1 - $dateStart));

                    continue;
                }
            }
            array_push($out, self::W50_NOT_A_HEADER, 0, 0, 0);
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined lines, four int64 words back per line.
     * Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w50Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w50Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w50', self::W50_FFI_CDEF) ?? false;
            $ffi = self::$w50Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 4;
            $out = self::$w50Out;
            if ($out === null || self::$w50OutCap < $need) {
                self::$w50OutCap = max(1024, $need);
                $out = self::$w50Out = $ffi->new('int64_t['.self::$w50OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
            $written = $ffi->nntmux_w504_fork_timing_parse_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w50OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $lines
     */
    private static function w50AssertList(array $lines): void
    {
        if (! array_is_list($lines)) {
            throw new InvalidArgumentException('W50 batch columns must be lists.');
        }
    }
}
