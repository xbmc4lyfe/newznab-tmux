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

    // ---- Wave 67.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 12.751x/10.878x, uncommon 10.965x/8.701x); see RustRuntimeStateConsolesEpisodeHydrationFanartForkingW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    private const string W67_FORKING_SERVICE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w672_forking_service_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W67_FORKING_SERVICE_TAIL = 14;

    /**
     * Wave 67 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w67', self::W67_FORKING_SERVICE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w67RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w67ForkingServicePlanBatch() to Rust until reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Routes w67ForkingServicePlanBatch() to PHP until reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores W67_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    public static function usesW67RustFfi(): bool
    {
        $enabled = self::w67RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W67_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w67ForkingServicePlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67ForkingServicePlanBatch(array $rows): array
    {
        return self::usesW67RustFfi() ? self::w67ForkingServicePlanBatchNative($rows) : self::w67ForkingServicePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67ForkingServicePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W67_FORKING_SERVICE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w67RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w67ForkingServicePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W67_FORKING_SERVICE_FFI_CDEF.)
            $written = $ffi->nntmux_w672_forking_service_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w67ForkingServicePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w67Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w67Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Workers when a row sets no maximum. */
    private const int W67_FORK_DEFAULT_WORKERS = 16;

    /** Cap of a chunk duration estimate in ms. */
    private const int W67_FORK_EST_CAP_MS = 1 << 40;

    /** Ceil-divided chunk duration in ms, capped at W67_FORK_EST_CAP_MS. */
    private static function w67ForkEstimate(int $items, int $workers, int $perItemMs): int
    {
        return min(intdiv($items + $workers - 1, $workers) * $perItemMs, self::W67_FORK_EST_CAP_MS);
    }

    /**
     * Wave 67.2 worker pool plan. Per `work_type\titems\trequested_workers\tmax_workers\t
     * timeout_s\tper_item_ms` row `action | type << 3 | min(workers, 1023) << 8 | clamped << 18 |
     * serial << 19 | min(base_chunk, 2^20 - 1) << 20 | min(extra, 1023) << 40 | over_budget << 50 |
     * min(ceil(estimate_ms / 1000), 4095) << 51`. The trimmed type is its WORK_TYPES position + 1
     * (exact match, 0 unknown); items, requested and maximum workers, timeout and per-item time
     * are w67Int() fields of 9, 4, 4, 6 and 7 digits. An unknown type or an unparsable number is
     * malformed (0). `releases` and `update_per_group` are serial (one worker); otherwise the cap
     * is the maximum or W67_FORK_DEFAULT_WORKERS. No items is idle (1). Otherwise the requested
     * workers (the cap when 0) are clamped to 1..cap and to the item count; the slowest chunk
     * takes `ceil(items / workers) * per_item_ms` (w67ForkEstimate()). When that exceeds the
     * timeout budget, a parallel type scales up (3) to `ceil(items * per_item_ms / budget)` workers
     * within the cap and items; a plan still over budget is flagged (4), otherwise planned (2).
     * Items split into `items / workers` per worker with the remainder over the first workers;
     * clamped marks a requested count that changed. Then five action counts, total workers,
     * total items, the most workers, clamped and serial rows, total and longest estimates in ms,
     * distinct types (well-formed rows) and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w67ForkingServicePlanBatchPhp(array $rows): array
    {
        $out = [];
        $actions = array_fill(0, 5, 0);
        $types = [];
        $totalWorkers = $totalItems = $maxWorkers = $clampedRows = $serialRows = $totalEst = $maxEst = 0;
        foreach ($rows as $row) {
            [$kind, $items, $requested, $max, $timeout, $per] = explode("\t", (string) $row, 6) + ['', '', '', '', '', ''];
            $position = array_search(trim($kind), self::WORK_TYPES, true);
            $ty = \is_int($position) ? $position + 1 : 0;
            $items = self::w67Int($items, 9);
            $requested = self::w67Int($requested, 4);
            $max = self::w67Int($max, 4);
            $timeout = self::w67Int($timeout, 6);
            $per = self::w67Int($per, 7);
            $malformed = $ty === 0 || $items === false || $requested === false || $max === false || $timeout === false || $per === false;
            $n = \is_int($items) ? $items : 0;
            $req = \is_int($requested) ? $requested : 0;
            $mx = \is_int($max) ? $max : 0;
            $t = \is_int($timeout) ? $timeout : 0;
            $p = \is_int($per) ? $per : 0;
            $serial = $ty === 5 || $ty === 18;
            $workers = $base = $extra = $est = 0;
            $clamped = $over = false;
            if ($malformed) {
                $action = 0;
            } else {
                $cap = $serial ? 1 : ($mx > 0 ? $mx : self::W67_FORK_DEFAULT_WORKERS);
                $want = $req > 0 ? $req : $cap;
                if ($n === 0) {
                    $action = 1;
                } else {
                    $workers = min(max($want, 1), $cap, $n);
                    $est = self::w67ForkEstimate($n, $workers, $p);
                    $budget = $t * 1000;
                    $over = $t > 0 && $est > $budget;
                    $scaled = false;
                    if ($over && ! $serial) {
                        $needed = min(intdiv($n * $p + $budget - 1, $budget), $cap, $n);
                        if ($needed > $workers) {
                            [$workers, $scaled] = [$needed, true];
                            $est = self::w67ForkEstimate($n, $workers, $p);
                            $over = $est > $budget;
                        }
                    }
                    $base = intdiv($n, $workers);
                    $extra = $n % $workers;
                    $action = $over ? 4 : ($scaled ? 3 : 2);
                }
                $clamped = $req > 0 && $workers !== $req;
                $totalWorkers += $workers;
                $totalItems += $n;
                $maxWorkers = max($maxWorkers, $workers);
                $clampedRows += (int) $clamped;
                $serialRows += (int) $serial;
                $totalEst += $est;
                $maxEst = max($maxEst, $est);
                $types[$ty] = true;
            }
            $actions[$action]++;
            $out[] = $action | $ty << 3 | min($workers, 1023) << 8 | (int) $clamped << 18 | (int) $serial << 19 | min($base, 0xFFFFF) << 20
                | min($extra, 1023) << 40 | (int) $over << 50 | min(intdiv($est + 999, 1000), 0xFFF) << 51;
        }
        array_push($out, ...$actions);
        array_push($out, $totalWorkers, $totalItems, $maxWorkers, $clampedRows, $serialRows, $totalEst, $maxEst, \count($types));
        $out[] = self::w67Digest($out);

        return $out;
    }
}
