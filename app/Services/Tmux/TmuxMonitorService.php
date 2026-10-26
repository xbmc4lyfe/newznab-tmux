<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Enums\TmuxMode;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Service for monitoring tmux operations and collecting statistics
 */
class TmuxMonitorService
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_tmux_monitor_increment_run_count(int64_t iterations);
        ssize_t nntmux_tmux_monitor_update_diffs_nul(const int64_t *now_vals, const int64_t *start_vals, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_monitor_calc_processing_stats(int64_t work, int64_t process_sum, int64_t tv, int64_t movies, int64_t audio, int64_t books, int64_t console, int64_t pc, int64_t xxx, int64_t misc, int64_t nfo, int64_t processnfo, int64_t predb_matched, int64_t predb, int64_t renamed, int64_t processrenames, int64_t *total_work_out, char *out_buf, size_t out_cap);
        int32_t nntmux_tmux_monitor_should_run_pane(const char *pane, size_t pane_len, int64_t binaries_run, int64_t backfill, int64_t releases_run, int64_t fix_names, const char *fix_crap_opt, size_t fix_crap_opt_len, const char *fix_crap, size_t fix_crap_len, int64_t post, int64_t post_non, int64_t post_amazon, int64_t processtvrage, int64_t processanime, int64_t processmovies, int64_t processbooks, int64_t processmusic, int64_t processgames, int64_t run_ircscraper, int64_t work_available, int64_t processnfo, int64_t processrenames, int64_t count_processtv, int64_t count_processanime, int64_t count_processmovies, int64_t count_processbooks, int64_t count_processmusic, int64_t count_processconsole, int64_t count_processgames, uint8_t kill_pp, uint8_t kill_coll);
        C;

    private static bool $useRustFfi = false;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static ?CData $totalWorkOut = null;

    private static ?CData $nowDiffBuf = null;

    private static ?CData $startDiffBuf = null;

    private static int $diffBufCap = 0;

    private const int PROC1_CACHE_TTL_SECONDS = 60;

    protected Tmux $tmux;

    /**
     * @var array<string, mixed>
     */
    protected array $runVar = [];

    protected int $iterations = 1;

    protected bool $shouldContinue = true;

    protected float $lastOperationalRefreshAt = 0.0;

    protected float $lastSlowRefreshAt = 0.0;

    /**
     * Whether counts.now.collections_table holds a row estimate rather than an exact count.
     */
    protected bool $collectionsCountIsEstimate = false;

    public function __construct(?ProcessingRuntimeStateRepository $runtimeState = null)
    {
        $this->tmux = new Tmux;
        ($runtimeState ?? app(ProcessingRuntimeStateRepository::class))->updateMonitorPaths([
            'monitor_path' => config('nntmux_settings.path_to_nzbs'),
            'monitor_path_a' => config('nntmux_settings.covers_path'),
            'monitor_path_b' => config('nntmux.tmp_unrar_path'),
        ]);
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('irc', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * Initialize monitor with default values
     *
     * @return array<string, mixed>
     */
    public function initializeMonitor(): array
    {
        $this->runVar['paths']['misc'] = base_path().'/misc/';
        $this->runVar['paths']['cli'] = base_path().'/cli/';

        $this->runVar['constants'] = $this->tmux->getConstantSettings();
        $this->runVar['settings'] = $this->tmux->getMonitorSettings();
        $this->runVar['connections'] = $this->tmux->getConnectionsInfo($this->runVar['constants']);

        // Initialize timers
        $this->runVar['timers'] = $this->initializeTimers();

        // Initialize counts
        $this->runVar['counts'] = [
            'iterations' => 1,
            'now' => $this->defaultCountValues(),
            'start' => $this->defaultCountValues(),
            'diff' => $this->defaultDiffValues(),
            'percent' => $this->defaultPercentValues(),
        ];

        // Parse fix_crap setting into an array
        $fixCrapSetting = $this->runVar['settings']['fix_crap'] ?? '';
        $fixCrapTypes = ! empty($fixCrapSetting)
            ? (is_array($fixCrapSetting) ? $fixCrapSetting : explode(',', $fixCrapSetting))
            : [];
        $fixCrapTypes = array_filter($fixCrapTypes);

        $this->runVar['modsettings'] = [
            'fix_crap' => $fixCrapTypes,
            'fc' => [
                'num' => 0,
                'max' => count($fixCrapTypes),
                'time' => 'full',
                'firstrun' => true,
            ],
        ];

        return $this->runVar;
    }

    /**
     * Initialize all timers
     *
     * @return array<string, mixed>
     */
    protected function initializeTimers(): array
    {
        $now = time();

        return [
            'timer1' => $now,
            'timer2' => $now,
            'timer3' => $now,
            'timer4' => $now,
            'timer5' => $now,
            'query' => [
                'tmux_time' => 0,
                'split_time' => 0,
                'init_time' => 0,
                'proc1_time' => 0,
                'proc2_time' => 0,
                'proc3_time' => 0,
                'split1_time' => 0,
                'init1_time' => 0,
                'proc11_time' => 0,
                'proc21_time' => 0,
                'proc31_time' => 0,
                'tpg_time' => 0,
                'tpg1_time' => 0,
            ],
            'newOld' => [
                'newestrelname' => '',
                'oldestcollection' => 0,
                'newestpre' => 0,
                'newestrelease' => 0,
            ],
        ];
    }

    /**
     * Collect current statistics
     *
     * @return array<string, mixed>
     */
    public function collectStatistics(): array
    {
        $timer = microtime(true);
        $this->runVar['settings'] = $this->tmux->getMonitorSettings();
        $this->runVar['timers']['query']['tmux_time'] = microtime(true) - $timer;
        $now = microtime(true);
        $monitorDelay = max(1, (int) ($this->runVar['settings']['monitor'] ?? 60));
        $slowRefreshDelay = max($monitorDelay, (int) config('tmux.monitor.refresh_interval', 60));
        $statisticsChanged = false;

        if ($this->refreshIsDue($this->lastOperationalRefreshAt, $monitorDelay, $now)) {
            $this->refreshOperationalStatistics();
            $this->lastOperationalRefreshAt = $now;
            $this->runVar['timers']['timer2'] = (int) $now;
            $statisticsChanged = true;
        }

        if ($this->refreshIsDue($this->lastSlowRefreshAt, $slowRefreshDelay, $now)) {
            $this->refreshSlowStatistics();
            $this->lastSlowRefreshAt = $now;
            $statisticsChanged = true;
        }

        if ($this->refreshCollectionCountForBackfillControls()) {
            $statisticsChanged = true;
        }

        if ($statisticsChanged) {
            $this->calculateStatistics();
        }

        $this->updateConnectionCounts();
        $this->setKillswitches();

        return $this->runVar;
    }

    protected function refreshOperationalStatistics(): void
    {
        $this->getProcessCounts();
    }

    protected function refreshSlowStatistics(): void
    {
        $this->getCategoryCounts();
        $this->getTableCounts();
    }

    protected function refreshIsDue(float $lastRefreshAt, int $interval, float $now): bool
    {
        return $lastRefreshAt === 0.0 || ($now - $lastRefreshAt) >= $interval;
    }

    /**
     * Get counts by category
     */
    protected function getCategoryCounts(): void
    {
        $timer = microtime(true);
        $bindings = [];
        $aggregates = [];

        foreach ($this->categoryRanges() as $name => [$minimum, $maximum]) {
            $aggregates[] = "SUM(CASE WHEN categories_id BETWEEN ? AND ? THEN 1 ELSE 0 END) AS {$name}";
            $bindings[] = $minimum;
            $bindings[] = $maximum;
        }

        try {
            $counts = Release::query()->selectRaw(implode(', ', $aggregates), $bindings)->first();

            if ($counts !== null) {
                foreach (array_keys($this->categoryRanges()) as $name) {
                    $this->runVar['counts']['now'][$name] = (int) $counts->getAttribute($name);
                }
            }
        } catch (\Exception $e) {
            logger()->error('Error collecting category counts: '.$e->getMessage());
        }

        $this->runVar['timers']['query']['init_time'] = microtime(true) - $timer;
    }

    /**
     * @return array<string, array{int, int}>
     */
    protected function categoryRanges(): array
    {
        return [
            'tv' => [Category::TV_ROOT, Category::TV_OTHER],
            'movies' => [Category::MOVIE_ROOT, Category::MOVIE_OTHER],
            'audio' => [Category::MUSIC_ROOT, Category::MUSIC_OTHER],
            'books' => [Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN],
            'console' => [Category::GAME_ROOT, Category::GAME_OTHER],
            'pc' => [Category::PC_ROOT, Category::PC_PHONE_ANDROID],
            'xxx' => [Category::XXX_ROOT, Category::XXX_OTHER],
            'misc' => [Category::OTHER_ROOT, Category::OTHER_HASHED],
        ];
    }

    /**
     * Get process-related counts
     */
    protected function getProcessCounts(): void
    {
        $timer = microtime(true);
        $this->runVar['counts']['now']['work'] = $this->runVar['counts']['now']['work'] ?? 0;
        $this->runVar['counts']['now']['work_available'] = $this->runVar['counts']['now']['work_available'] ?? 0;

        try {
            $dbName = (string) config('nntmux.db_name');

            $proc1Query = $this->tmux->proc_query(1, $dbName, '');
            $proc1Result = $this->getCachedProc1Result($proc1Query);

            if ($proc1Result) {
                foreach ((array) $proc1Result as $key => $value) {
                    $this->runVar['counts']['now'][$key] = $value;
                }
            }

            $this->runVar['timers']['query']['proc1_time'] = microtime(true) - $timer;

            // Process 2
            $timer2 = microtime(true);
            $maxSize = $this->runVar['settings']['maxsize_pp'] ?? '';
            $minSize = $this->runVar['settings']['minsize_pp'] ?? '';

            $proc2Query = $this->tmux->proc_query(2, $dbName, (string) $maxSize, (string) $minSize);
            $proc2Result = DB::selectOne($proc2Query);

            if ($proc2Result) {
                foreach ((array) $proc2Result as $key => $value) {
                    $this->runVar['counts']['now'][$key] = $value;
                }
            }

            // `work` remains the visible additional-processing backlog, including
            // rows claimed by active workers. `work_available` is the scheduler
            // gate, excluding fresh claims so tmux does not respawn duplicate
            // additional workers while a claimed batch is still running.
            $sequential = (int) ($this->runVar['constants']['sequential'] ?? 0) === 2;
            $additionalEnabled = $sequential || in_array((int) ($this->runVar['settings']['post'] ?? 0), [1, 3], true);
            $additionalBacklog = $additionalEnabled
                ? AdditionalCandidateQuery::backlogCounts(sequential: $sequential)
                : ['total' => 0, 'available' => 0];
            $this->runVar['counts']['now']['work'] = $additionalBacklog['total'];
            $this->runVar['counts']['now']['work_available'] = $additionalBacklog['available'];

            $this->runVar['timers']['query']['proc2_time'] = microtime(true) - $timer2;

        } catch (\Exception $e) {
            logger()->error('Error collecting process counts: '.$e->getMessage());
        }
    }

    protected function getCachedProc1Result(string $query): ?object
    {
        return Cache::remember(
            'tmux:proc1:'.md5($query),
            self::PROC1_CACHE_TTL_SECONDS,
            static fn (): ?object => DB::selectOne($query),
        );
    }

    /**
     * Get table row counts
     */
    protected function getTableCounts(): void
    {
        $timer = microtime(true);

        try {
            // The collections kill switch and progressive backfill decide on this count,
            // so keep it exact when either is on; otherwise it is display-only and can be
            // over 10M rows, so use the row estimate (see Tmux::tableRowCount()).
            $estimate = ! $this->collectionCountDrivesBackfill();
            $this->runVar['counts']['now']['collections_table'] = $estimate
                ? $this->tmux->tableRowCount('collections')
                : Collection::query()->count();
            $this->collectionsCountIsEstimate = $estimate;
            $this->runVar['counts']['now']['releases'] = Release::query()->count();

            foreach ($this->aggregateTableRowCounts($this->tmux->cbpmTableQuery()) as $key => $count) {
                $this->runVar['counts']['now'][$key] = $count;
            }

            $this->runVar['timers']['query']['tpg_time'] = microtime(true) - $timer;

            foreach ([4, 6] as $queryNumber) {
                $result = DB::selectOne($this->tmux->proc_query($queryNumber, (string) config('nntmux.db_name'), ''));
                if ($result === null) {
                    continue;
                }

                $target = $queryNumber === 4 ? 'counts' : 'timers';
                $section = $queryNumber === 4 ? 'now' : 'newOld';
                foreach ((array) $result as $key => $value) {
                    $this->runVar[$target][$section][$key] = $value;
                }
            }
        } catch (\Exception $e) {
            logger()->error('Error collecting table counts: '.$e->getMessage());
        }
    }

    /**
     * Replace an estimated collections count with an exact one as soon as a backfill
     * control that decides on it is turned on, instead of at the next slow refresh.
     */
    protected function refreshCollectionCountForBackfillControls(): bool
    {
        if (! $this->collectionsCountIsEstimate || ! $this->collectionCountDrivesBackfill()) {
            return false;
        }

        try {
            $this->runVar['counts']['now']['collections_table'] = Collection::query()->count();
        } catch (\Exception $e) {
            // Keep the flag set so the next loop retries.
            logger()->error('Error counting collections for backfill controls: '.$e->getMessage());

            return false;
        }
        $this->collectionsCountIsEstimate = false;

        return true;
    }

    /**
     * Whether backfill runs (the tmux mode schedules it and TmuxTaskRunner::runBackfill()
     * launches backfill modes 1 and 4) and decides on the collections count through the
     * kill switch or progressive delay.
     */
    private function collectionCountDrivesBackfill(): bool
    {
        // Same mode resolution as TmuxMonitor::runPaneTasks().
        $mode = TmuxMode::tryFrom((int) ($this->runVar['constants']['sequential'] ?? 0)) ?? TmuxMode::Full;
        if (! isset($mode->tasks()['backfill'])
            || ! \in_array((int) ($this->runVar['settings']['backfill'] ?? 0), [1, 4], true)) {
            return false;
        }

        return (int) ($this->runVar['settings']['collections_kill'] ?? 0) > 0
            || (int) ($this->runVar['settings']['progressive'] ?? 0) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $tables
     * @return array{binaries_table: int, parts_table: int, missed_parts_table: int}
     */
    protected function aggregateTableRowCounts(array $tables): array
    {
        $counts = [
            'binaries_table' => 0,
            'parts_table' => 0,
            'missed_parts_table' => 0,
        ];

        foreach ($tables as $table) {
            if (! is_object($table)) {
                continue;
            }

            $tableName = (string) ($table->name ?? '');
            $count = (int) ($table->row_count ?? 0);

            if (str_contains($tableName, 'binaries')) {
                $counts['binaries_table'] += $count;
            } elseif (str_contains($tableName, 'missed_parts')) {
                $counts['missed_parts_table'] += $count;
            } elseif (str_contains($tableName, 'parts')) {
                $counts['parts_table'] += $count;
            }
        }

        return $counts;
    }

    /**
     * Calculate statistics (diffs, percentages, totals)
     */
    protected function calculateStatistics(): void
    {
        $this->runVar['counts']['now'] = array_replace($this->defaultCountValues(), $this->runVar['counts']['now'] ?? []);
        $this->runVar['counts']['start'] = array_replace($this->defaultCountValues(), $this->runVar['counts']['start'] ?? []);
        $this->runVar['counts']['diff'] = array_replace($this->defaultDiffValues(), $this->runVar['counts']['diff'] ?? []);
        $this->runVar['counts']['percent'] = array_replace($this->defaultPercentValues(), $this->runVar['counts']['percent'] ?? []);

        $stats = $this->calculateProcessingStats(
            $this->runVar['counts']['now'],
            $this->runVar['counts']['percent'],
        );
        $this->runVar['counts']['now'] = $stats['now'];
        $this->runVar['counts']['percent'] = $stats['percent'];

        // Set initial start values on first iteration
        if ($this->iterations === 1) {
            $this->runVar['counts']['start'] = $this->runVar['counts']['now'];
        }

        // Calculate diffs
        $this->runVar['counts']['diff'] = array_replace(
            $this->runVar['counts']['diff'],
            $this->updateDiffs($this->runVar['counts']['now'], $this->runVar['counts']['start']),
        );
    }

    /**
     * Compute formatted diffs (`number_format(now - start)`) for all keys in `$now`.
     *
     * @param  array<string, int|float|string>  $now
     * @param  array<string, int|float|string>  $start
     * @return array<string, string>
     */
    public function updateDiffs(array $now, array $start): array
    {
        if (self::$useRustFfi) {
            $native = $this->updateDiffsNative($now, $start);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->updateDiffsPhp($now, $start);
    }

    /**
     * @param  array<string, int|float|string>  $now
     * @param  array<string, int|float|string>  $start
     * @return array<string, string>|null
     */
    public function updateDiffsNative(array $now, array $start): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        $count = count($now);
        if ($count === 0) {
            return [];
        }

        try {
            if (self::$nowDiffBuf === null || self::$startDiffBuf === null || self::$diffBufCap < $count) {
                $cap = max(64, $count);
                self::$nowDiffBuf = $ffi->new("int64_t[{$cap}]");
                self::$startDiffBuf = $ffi->new("int64_t[{$cap}]");
                self::$diffBufCap = $cap;
            }

            $keys = [];
            $idx = 0;
            foreach ($now as $key => $value) {
                $keys[] = (string) $key;
                self::$nowDiffBuf[$idx] = (int) $value;
                self::$startDiffBuf[$idx] = (int) ($start[$key] ?? 0);
                $idx++;
            }

            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $count * 16), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_tmux_monitor_update_diffs_nul(
                self::$nowDiffBuf,
                self::$startDiffBuf,
                $count,
                $scratch,
                $cap,
            );
            if ($rc <= -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $rc = (int) $ffi->nntmux_tmux_monitor_update_diffs_nul(
                    self::$nowDiffBuf,
                    self::$startDiffBuf,
                    $count,
                    $scratch,
                    $cap,
                );
            }
            if ($rc < 0) {
                return null;
            }

            $parts = explode("\0", FFI::string($scratch, $rc));
            if (count($parts) !== $count) {
                return null;
            }

            return array_combine($keys, $parts);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, int|float|string>  $now
     * @param  array<string, int|float|string>  $start
     * @return array<string, string>
     */
    public function updateDiffsPhp(array $now, array $start): array
    {
        $diffs = [];
        foreach ($now as $key => $value) {
            $startValue = (int) ($start[$key] ?? 0);
            $diffs[(string) $key] = number_format((int) $value - $startValue);
        }

        return $diffs;
    }

    /**
     * Compute `total_work` and category/PP percentages for `counts.now` and `counts.percent`.
     *
     * @param  array<string, int|float|string>  $now
     * @param  array<string, int|string>  $percentDefaults
     * @return array{now: array<string, int|float|string>, percent: array<string, int|string>}
     */
    public function calculateProcessingStats(array $now, array $percentDefaults = []): array
    {
        if (self::$useRustFfi) {
            $native = $this->calculateProcessingStatsNative($now, $percentDefaults);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->calculateProcessingStatsPhp($now, $percentDefaults);
    }

    /**
     * @param  array<string, int|float|string>  $now
     * @param  array<string, int|string>  $percentDefaults
     * @return array{now: array<string, int|float|string>, percent: array<string, int|string>}|null
     */
    public function calculateProcessingStatsNative(array $now, array $percentDefaults = []): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        $processSum = 0;
        foreach ($now as $key => $value) {
            if (str_starts_with((string) $key, 'process')) {
                $processSum += (int) $value;
            }
        }

        try {
            if (self::$totalWorkOut === null) {
                self::$totalWorkOut = $ffi->new('int64_t');
            }

            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_tmux_monitor_calc_processing_stats(
                (int) ($now['work'] ?? 0),
                $processSum,
                (int) ($now['tv'] ?? 0),
                (int) ($now['movies'] ?? 0),
                (int) ($now['audio'] ?? 0),
                (int) ($now['books'] ?? 0),
                (int) ($now['console'] ?? 0),
                (int) ($now['pc'] ?? 0),
                (int) ($now['xxx'] ?? 0),
                (int) ($now['misc'] ?? 0),
                (int) ($now['nfo'] ?? 0),
                (int) ($now['processnfo'] ?? 0),
                (int) ($now['predb_matched'] ?? 0),
                (int) ($now['predb'] ?? 1),
                (int) ($now['renamed'] ?? 0),
                (int) ($now['processrenames'] ?? 0),
                FFI::addr(self::$totalWorkOut),
                $scratch,
                $cap,
            );
            if ($rc <= -10) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $rc = (int) $ffi->nntmux_tmux_monitor_calc_processing_stats(
                    (int) ($now['work'] ?? 0),
                    $processSum,
                    (int) ($now['tv'] ?? 0),
                    (int) ($now['movies'] ?? 0),
                    (int) ($now['audio'] ?? 0),
                    (int) ($now['books'] ?? 0),
                    (int) ($now['console'] ?? 0),
                    (int) ($now['pc'] ?? 0),
                    (int) ($now['xxx'] ?? 0),
                    (int) ($now['misc'] ?? 0),
                    (int) ($now['nfo'] ?? 0),
                    (int) ($now['processnfo'] ?? 0),
                    (int) ($now['predb_matched'] ?? 0),
                    (int) ($now['predb'] ?? 1),
                    (int) ($now['renamed'] ?? 0),
                    (int) ($now['processrenames'] ?? 0),
                    FFI::addr(self::$totalWorkOut),
                    $scratch,
                    $cap,
                );
            }
            if ($rc < 10) {
                return null;
            }

            $tokens = explode("\0", FFI::string($scratch, $rc));
            if (count($tokens) !== 11) {
                return null;
            }

            // @phpstan-ignore property.notFound
            $now['total_work'] = (int) self::$totalWorkOut->cdata;
            $percent = array_replace($this->defaultPercentValues(), $percentDefaults);
            $keys = ['tv', 'movies', 'audio', 'books', 'console', 'pc', 'xxx', 'misc', 'nfo', 'predb_matched', 'renamed'];
            foreach ($keys as $idx => $key) {
                $percent[$key] = $tokens[$idx] === '' ? 0 : $tokens[$idx];
            }

            return [
                'now' => $now,
                'percent' => $percent,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, int|float|string>  $now
     * @param  array<string, int|string>  $percentDefaults
     * @return array{now: array<string, int|float|string>, percent: array<string, int|string>}
     */
    public function calculateProcessingStatsPhp(array $now, array $percentDefaults = []): array
    {
        $now['total_work'] = (int) ($now['work'] ?? 0);

        foreach ($now as $key => $value) {
            if (str_starts_with((string) $key, 'process')) {
                $now['total_work'] += (int) $value;
            }
        }

        $percent = array_replace($this->defaultPercentValues(), $percentDefaults);
        $categoryKeys = ['tv', 'movies', 'audio', 'books', 'console', 'pc', 'xxx', 'misc'];

        $totalCategorized = 0;
        foreach ($categoryKeys as $key) {
            $totalCategorized += (int) ($now[$key] ?? 0);
        }

        foreach ($categoryKeys as $key) {
            $value = (int) ($now[$key] ?? 0);
            $percent[$key] = $totalCategorized > 0
                ? sprintf('%02d', (int) floor(($value / $totalCategorized) * 100))
                : 0;
        }

        // NFO: nfo / (nfo + processnfo) * 100
        $nfoMatched = (int) ($now['nfo'] ?? 0);
        $nfoUnmatched = (int) ($now['processnfo'] ?? 0);
        $nfoTotal = $nfoMatched + $nfoUnmatched;
        $percent['nfo'] = $nfoTotal > 0
            ? sprintf('%02d', floor(($nfoMatched / $nfoTotal) * 100))
            : 0;

        // PreDB: predb_matched / predb * 100
        $predbMatched = (int) ($now['predb_matched'] ?? 0);
        $predbTotal = (int) ($now['predb'] ?? 1);
        $percent['predb_matched'] = $predbTotal > 0
            ? sprintf('%02d', floor(($predbMatched / $predbTotal) * 100))
            : 0;

        // Renames: renamed / (renamed + processrenames) * 100
        $renamed = (int) ($now['renamed'] ?? 0);
        $processrenames = (int) ($now['processrenames'] ?? 0);
        $renameTotal = $renamed + $processrenames;
        $percent['renamed'] = $renameTotal > 0
            ? sprintf('%02d', floor(($renamed / $renameTotal) * 100))
            : 0;

        return [
            'now' => $now,
            'percent' => $percent,
        ];
    }

    /**
     * Evaluate whether a pane task should run based on monitor settings, work counts, and killswitches.
     *
     * @param  array<string, mixed>|null  $settings
     * @param  array<string, mixed>|null  $countsNow
     * @param  array<string, mixed>|null  $killswitch
     */
    public function shouldRunPane(
        string $pane,
        ?array $settings = null,
        ?array $countsNow = null,
        ?array $killswitch = null,
    ): bool {
        $resolvedSettings = $settings ?? ($this->runVar['settings'] ?? []);
        $resolvedCounts = $countsNow ?? ($this->runVar['counts']['now'] ?? []);
        $resolvedKillswitch = $killswitch ?? ($this->runVar['killswitch'] ?? []);

        if (self::$useRustFfi) {
            $native = $this->shouldRunPaneNative($pane, $resolvedSettings, $resolvedCounts, $resolvedKillswitch);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->shouldRunPanePhp($pane, $resolvedSettings, $resolvedCounts, $resolvedKillswitch);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $countsNow
     * @param  array<string, mixed>  $killswitch
     */
    public function shouldRunPaneNative(
        string $pane,
        array $settings = [],
        array $countsNow = [],
        array $killswitch = [],
    ): ?bool {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        $fixCrapOpt = (string) ($settings['fix_crap_opt'] ?? '');
        $fixCrapRaw = $settings['fix_crap'] ?? '';
        $fixCrap = is_array($fixCrapRaw) ? implode(',', array_map('strval', $fixCrapRaw)) : (string) $fixCrapRaw;

        try {
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_tmux_monitor_should_run_pane(
                $pane,
                strlen($pane),
                (int) ($settings['binaries_run'] ?? 0),
                (int) ($settings['backfill'] ?? 0),
                (int) ($settings['releases_run'] ?? 0),
                (int) ($settings['fix_names'] ?? 0),
                $fixCrapOpt,
                strlen($fixCrapOpt),
                $fixCrap,
                strlen($fixCrap),
                (int) ($settings['post'] ?? 0),
                (int) ($settings['post_non'] ?? 0),
                (int) ($settings['post_amazon'] ?? 0),
                (int) ($settings['processtvrage'] ?? 0),
                (int) ($settings['processanime'] ?? 0),
                (int) ($settings['processmovies'] ?? 0),
                (int) ($settings['processbooks'] ?? 0),
                (int) ($settings['processmusic'] ?? 0),
                (int) ($settings['processgames'] ?? 0),
                (int) ($settings['run_ircscraper'] ?? 0),
                (int) ($countsNow['work_available'] ?? 0),
                (int) ($countsNow['processnfo'] ?? 0),
                (int) ($countsNow['processrenames'] ?? 0),
                (int) ($countsNow['processtv'] ?? 0),
                (int) ($countsNow['processanime'] ?? 0),
                (int) ($countsNow['processmovies'] ?? 0),
                (int) ($countsNow['processbooks'] ?? 0),
                (int) ($countsNow['processmusic'] ?? 0),
                (int) ($countsNow['processconsole'] ?? 0),
                (int) ($countsNow['processgames'] ?? 0),
                ! empty($killswitch['pp']) ? 1 : 0,
                ! empty($killswitch['coll']) ? 1 : 0,
            );
            if ($rc < 0) {
                return null;
            }

            return $rc === 1;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $countsNow
     * @param  array<string, mixed>  $killswitch
     */
    public function shouldRunPanePhp(
        string $pane,
        array $settings = [],
        array $countsNow = [],
        array $killswitch = [],
    ): bool {
        $killPp = ! empty($killswitch['pp']);
        $killColl = ! empty($killswitch['coll']);

        return match ($pane) {
            'binaries' => (int) ($settings['binaries_run'] ?? 0) === 1 && ! $killPp,
            'backfill' => in_array((int) ($settings['backfill'] ?? 0), [1, 4], true) && ! $killColl && ! $killPp,
            'releases' => (int) ($settings['releases_run'] ?? 0) !== 0,
            'sequential' => true,
            'fixnames' => (int) ($settings['fix_names'] ?? 0) === 1 && (int) ($countsNow['processrenames'] ?? 0) > 0,
            'removecrap' => match ((string) ($settings['fix_crap_opt'] ?? '')) {
                'All' => true,
                'Custom' => array_filter(
                    array_map('trim', explode(',', is_array($settings['fix_crap'] ?? null) ? implode(',', $settings['fix_crap']) : (string) ($settings['fix_crap'] ?? ''))),
                    static fn (string $v): bool => $v !== '' && $v !== '0',
                ) !== [],
                default => false,
            },
            'ppadditional' => match ((int) ($settings['post'] ?? 0)) {
                1 => (int) ($countsNow['work_available'] ?? 0) > 0,
                2 => (int) ($countsNow['processnfo'] ?? 0) > 0,
                3 => (int) ($countsNow['work_available'] ?? 0) > 0 || (int) ($countsNow['processnfo'] ?? 0) > 0,
                default => false,
            },
            'tv', 'nonamazon' => (int) ($settings['post_non'] ?? 0) > 0
                && (((int) ($settings['processtvrage'] ?? 0) > 0 && (int) ($countsNow['processtv'] ?? 0) > 0)
                    || ((int) ($settings['processanime'] ?? 0) > 0 && (int) ($countsNow['processanime'] ?? 0) > 0)),
            'movies' => (int) ($settings['post_non'] ?? 0) > 0
                && (int) ($settings['processmovies'] ?? 0) > 0
                && (int) ($countsNow['processmovies'] ?? 0) > 0,
            'amazon' => (int) ($settings['post_amazon'] ?? 0) > 0
                && (((int) ($settings['processbooks'] ?? 0) > 0 && (int) ($countsNow['processbooks'] ?? 0) > 0)
                    || ((int) ($settings['processmusic'] ?? 0) > 0 && (int) ($countsNow['processmusic'] ?? 0) > 0)
                    || ((int) ($settings['processgames'] ?? 0) > 0 && ((int) ($countsNow['processconsole'] ?? 0) > 0 || (int) ($countsNow['processgames'] ?? 0) > 0))),
            'scraper' => (int) ($settings['run_ircscraper'] ?? 0) === 1,
            default => false,
        };
    }

    /**
     * @return array<string, int>
     */
    protected function defaultCountValues(): array
    {
        return [
            'active_groups' => 0,
            'all_groups' => 0,
            'audio' => 0,
            'backfill_groups_date' => 0,
            'backfill_groups_days' => 0,
            'binaries_table' => 0,
            'books' => 0,
            'collections_table' => 0,
            'console' => 0,
            'distinct_predb_matched' => 0,
            'misc' => 0,
            'missed_parts_table' => 0,
            'movies' => 0,
            'nfo' => 0,
            'parts_table' => 0,
            'pc' => 0,
            'predb' => 0,
            'predb_matched' => 0,
            'processanime' => 0,
            'processbooks' => 0,
            'processconsole' => 0,
            'processgames' => 0,
            'processmovies' => 0,
            'processmusic' => 0,
            'processnfo' => 0,
            'processrenames' => 0,
            'processtv' => 0,
            'releases' => 0,
            'renamed' => 0,
            'total_work' => 0,
            'tv' => 0,
            'work' => 0,
            'work_available' => 0,
            'xxx' => 0,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function defaultDiffValues(): array
    {
        return array_fill_keys(array_keys($this->defaultCountValues()), '0');
    }

    /**
     * @return array<string, int|string>
     */
    protected function defaultPercentValues(): array
    {
        return [
            'audio' => 0,
            'books' => 0,
            'console' => 0,
            'misc' => 0,
            'movies' => 0,
            'nfo' => 0,
            'pc' => 0,
            'predb_matched' => 0,
            'renamed' => 0,
            'tv' => 0,
            'xxx' => 0,
        ];
    }

    /**
     * Update connection counts
     */
    protected function updateConnectionCounts(): void
    {
        $socketSnapshot = $this->tmux->getSocketSnapshot();
        $this->runVar['conncounts'] = $this->tmux->getUSPConnections(
            'primary',
            $this->runVar['connections'],
            $socketSnapshot,
        );

        if ((int) ($this->runVar['constants']['alternate_nntp'] ?? 0) === 1) {
            $alternateConns = $this->tmux->getUSPConnections(
                'alternate',
                $this->runVar['connections'],
                $socketSnapshot,
            );
            $this->runVar['conncounts'] = array_merge($this->runVar['conncounts'], $alternateConns);
        }
    }

    /**
     * Set killswitches based on limits
     */
    protected function setKillswitches(): void
    {
        $ppKillLimit = (int) ($this->runVar['settings']['postprocess_kill'] ?? 0);
        $collKillLimit = (int) ($this->runVar['settings']['collections_kill'] ?? 0);

        $totalWork = (int) ($this->runVar['counts']['now']['total_work'] ?? 0);
        $collections = (int) ($this->runVar['counts']['now']['collections_table'] ?? 0);

        $this->runVar['killswitch']['pp'] = ($ppKillLimit > 0 && $ppKillLimit < $totalWork);
        // Fail closed: while backfill decides on the collections count but only an
        // estimate is available (the exact count failed), hold backfill.
        $this->runVar['killswitch']['coll'] = ($collKillLimit > 0 && $collKillLimit < $collections)
            || ($this->collectionsCountIsEstimate && $this->collectionCountDrivesBackfill());
    }

    /**
     * Update the monitor display
     */
    public function updateDisplay(): void
    {
        // This would output to terminal - implementation depends on TmuxOutput
        // For now, we'll just log basic info
        if ($this->iterations % 10 === 0) {
            logger()->info('Tmux Monitor', [
                'iteration' => $this->iterations,
                'total_work' => $this->runVar['counts']['now']['total_work'] ?? 0,
                'collections' => $this->runVar['counts']['now']['collections_table'] ?? 0,
            ]);
        }
    }

    /**
     * Increment iteration counter
     */
    public function incrementIteration(): void
    {
        $this->iterations = $this->incrementRunCount($this->iterations);
        $this->runVar['counts']['iterations'] = $this->iterations;
    }

    /**
     * Increment a monitor run iteration count (`$iterations + 1`).
     */
    public function incrementRunCount(int $iterations): int
    {
        if (self::$useRustFfi) {
            $native = $this->incrementRunCountNative($iterations);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->incrementRunCountPhp($iterations);
    }

    public function incrementRunCountNative(int $iterations): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_tmux_monitor_increment_run_count($iterations);
            if ($rc < 0) {
                return null;
            }

            return $rc;
        } catch (Throwable) {
            return null;
        }
    }

    public function incrementRunCountPhp(int $iterations): int
    {
        return $iterations + 1;
    }

    /**
     * Check if monitoring should continue
     */
    public function shouldContinue(): bool
    {
        $exitFlag = (int) app(ProcessingRuntimeStateRepository::class)->stopRequested();

        if ($exitFlag === 0) {
            return true;
        }

        $this->shouldContinue = false;

        return false;
    }

    /**
     * Get current run variables
     *
     * @return array<string, mixed>
     */
    public function getRunVar(): array
    {
        return $this->runVar;
    }

    // ========================================================================
    // WAVE 58.4 BATCH HELPER
    // ========================================================================

    /** Disabled: common p99 fell to 1.48x in the minimum of three paired runs (common mean 1.64x; uncommon mean 1.59x, p99 1.59x), since packing six int columns costs about as much as the PHP arithmetic; see RustTmuxLayoutMonitorOutputPaneSessionManagerW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = false;

    public const string W58_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_monitor_cycle_row_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per monitorCycleRowBatch() record. */
    public const int W58_ROW_WORDS = 9;

    /** int64 summary words after the monitorCycleRowBatch() records (none for an empty batch). */
    public const int W58_TRAILER_WORDS = 4;

    /**
     * Monitor cycle rows. Each item is [now count, start count, elapsed seconds, backlog,
     * previous backlog, iterations] and yields [delta (saturating now - start), rate per minute
     * (floor(delta * 60 / elapsed), 0 without elapsed time), backlog delta (saturating), ETA
     * seconds (ceil(backlog * 60 / rate), -1 without a positive rate and backlog), percent done
     * (floor(max(0, delta) * 100 / (max(0, delta) + max(0, backlog)))), trend (-1/0/1 backlog
     * delta sign), flags (1 stalled, 2 backlog growing, 4 draining, 8 clock skew, 16 idle, 32
     * first cycle), health penalty score (0-100) and the next iteration (saturating)], followed
     * by the trailer [saturating delta sum, stalled cycles, max ETA, max score]. Float results
     * are clamped to +/-4e18 before the integer cast.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function monitorCycleRowBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::monitorCycleRowBatchNative($items) : self::monitorCycleRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function monitorCycleRowBatchPhp(array $items): array
    {
        self::w58Items($items);
        $out = [];
        $summary = [0, 0, -1, 0];
        foreach ($items as [$now, $start, $elapsed, $backlog, $previous, $iterations]) {
            $delta = self::w58Sub($now, $start);
            $rate = $elapsed > 0 ? self::w58Floor((float) $delta * 60.0 / (float) $elapsed) : 0;
            $backlogDelta = self::w58Sub($backlog, $previous);
            $eta = $rate > 0 && $backlog > 0 ? self::w58Ceil((float) $backlog * 60.0 / (float) $rate) : -1;
            $completed = max(0, $delta);
            $denominator = self::w58Add($completed, max(0, $backlog));
            $percent = $denominator > 0 ? self::w58Floor((float) $completed * 100.0 / (float) $denominator) : 0;
            $stalled = $iterations > 0 && $delta <= 0 && $backlog > 0;
            $growing = $backlogDelta > 0;
            $skew = $elapsed < 0;
            $score = ($stalled ? 50 : 0) + ($growing ? 20 : 0) + ($eta > 3600 ? 20 : ($eta > 600 ? 10 : 0)) + ($skew ? 10 : 0);
            array_push(
                $out,
                $delta,
                $rate,
                $backlogDelta,
                $eta,
                $percent,
                $backlogDelta <=> 0,
                ($stalled ? 1 : 0) | ($growing ? 2 : 0) | ($backlogDelta < 0 ? 4 : 0) | ($skew ? 8 : 0)
                    | ($backlog <= 0 && $delta <= 0 ? 16 : 0) | ($iterations <= 0 ? 32 : 0),
                $score,
                $iterations === PHP_INT_MAX ? PHP_INT_MAX : $iterations + 1,
            );
            $summary[0] = self::w58Add($summary[0], $delta);
            $summary[1] += $stalled ? 1 : 0;
            $summary[2] = max($summary[2], $eta);
            $summary[3] = max($summary[3], $score);
        }

        return $items === [] ? [] : [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function monitorCycleRowBatchNative(array $items): array
    {
        self::w58Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        $columns = [[], [], [], [], [], []];
        foreach ($items as $item) {
            foreach ($item as $k => $value) {
                $columns[$k][] = $value;
            }
        }

        return self::w58Call('', pack('P*', ...array_merge(...$columns)), $count, $count * self::W58_ROW_WORDS + self::W58_TRAILER_WORDS)
            ?? self::monitorCycleRowBatchPhp($items);
    }

    /** Saturating $a - $b. */
    private static function w58Sub(int $a, int $b): int
    {
        if ($b < 0 ? $a > PHP_INT_MAX + $b : $a < PHP_INT_MIN + $b) {
            return $b < 0 ? PHP_INT_MAX : PHP_INT_MIN;
        }

        return $a - $b;
    }

    /** Saturating $a + $b. */
    private static function w58Add(int $a, int $b): int
    {
        if ($b > 0 ? $a > PHP_INT_MAX - $b : $a < PHP_INT_MIN - $b) {
            return $b > 0 ? PHP_INT_MAX : PHP_INT_MIN;
        }

        return $a + $b;
    }

    private static function w58Floor(float $value): int
    {
        return (int) max(-4.0e18, min(4.0e18, floor($value)));
    }

    private static function w58Ceil(float $value): int
    {
        return (int) max(-4.0e18, min(4.0e18, ceil($value)));
    }

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W58 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('enabled');
        self::w58RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $items */
    private static function w58Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W58 monitor batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || ! array_is_list($item) || \count($item) !== 6 || \count(array_filter($item, is_int(...))) !== 6) {
                throw new \InvalidArgumentException('W58 monitor batch items must be lists of six ints.');
            }
        }
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w58Call(string $blob, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w58RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w58', self::W58_FFI_CDEF) ?? false;
            self::w58RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w58RustFfiState('out', $out);
                self::w58RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_monitor_cycle_row_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w58RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W58 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
