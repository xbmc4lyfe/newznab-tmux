<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\RedisMonitor;
use App\Services\Tmux\TmuxMonitorService;
use App\Services\Tmux\TmuxOutput;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Paired randomized single-call benchmark evidence (40 paired runs x 128 iterations, hrtime(true)):
 *
 * | Method / Workload                                       | Rust mean (p99)       | PHP mean (p99)        | Speedup (mean / p99) | Production Path |
 * | ------------------------------------------------------- | --------------------- | --------------------- | -------------------- | --------------- |
 * | TmuxOutput::extractServerInfoMetrics (common)           | 488.4 ns (704.1 ns)   | 1298.8 ns (1474.0 ns) | 2.66x / 2.09x        | Rust FFI        |
 * | TmuxOutput::extractServerInfoMetrics (uncommon)         | 543.1 ns (690.1 ns)   | 1692.2 ns (1717.4 ns) | 3.12x / 2.49x        | Rust FFI        |
 * | TmuxOutput::percent (common)                            | 108.2 ns (262.7 ns)   | 34.9 ns (45.2 ns)     | 0.32x / 0.17x        | PHP (<1.5x)     |
 * | TmuxOutput::decodeCountRow (common)                     | 401.8 ns (756.5 ns)   | 205.5 ns (339.5 ns)   | 0.51x / 0.45x        | PHP (<1.5x)     |
 * | TmuxPaneManager::getPaneNames (common)                  | 430.2 ns (804.0 ns)   | 64.4 ns (132.8 ns)    | 0.15x / 0.17x        | PHP (<1.5x)     |
 * | TmuxPaneManager::buildRespawnCommand (common)           | 1130.7 ns (1841.1 ns) | 456.8 ns (901.0 ns)   | 0.40x / 0.49x        | PHP (<1.5x)     |
 * | TmuxPaneManager::buildLogTeeSuffix (common)             | 372.5 ns (739.3 ns)   | 69.5 ns (150.4 ns)    | 0.19x / 0.20x        | PHP (<1.5x)     |
 * | TmuxPaneManager::getSequentialCommand (common)          | 354.1 ns (677.4 ns)   | 251.4 ns (459.3 ns)   | 0.71x / 0.68x        | PHP (<1.5x)     |
 * | TmuxMonitorService::incrementRunCount (common)          | 95.7 ns (201.2 ns)    | 22.6 ns (25.1 ns)     | 0.24x / 0.12x        | PHP (<1.5x)     |
 * | TmuxMonitorService::updateDiffs (34 keys)               | 2856.1 ns (4009.1 ns) | 2585.7 ns (3877.3 ns) | 0.91x / 0.97x        | PHP (<1.5x)     |
 * | TmuxMonitorService::calculateProcessingStats (common)   | 1956.6 ns (3179.0 ns) | 2006.0 ns (3557.9 ns) | 1.03x / 1.12x        | PHP (<1.5x)     |
 * | TmuxMonitorService::shouldRunPane (removecrap)          | 851.3 ns (1665.7 ns)  | 446.7 ns (758.5 ns)   | 0.52x / 0.46x        | PHP (<1.5x)     |
 * | TmuxSessionManager::buildSelectTiledCommand (common)    | 600.2 ns (1051.8 ns)  | 106.0 ns (224.9 ns)   | 0.18x / 0.21x        | PHP (<1.5x)     |
 * | TmuxSessionManager::buildKillWindowCommand (common)     | 503.5 ns (769.5 ns)   | 93.9 ns (163.7 ns)    | 0.19x / 0.21x        | PHP (<1.5x)     |
 * | TmuxSessionManager::getSplitSetup (common)              | 1587.9 ns (2298.8 ns) | 36.0 ns (74.9 ns)     | 0.02x / 0.03x        | PHP (<1.5x)     |
 * | RedisMonitor::formatBytes (common)                      | 185.4 ns (201.5 ns)   | 107.1 ns (136.4 ns)   | 0.58x / 0.68x        | PHP (<1.5x)     |
 * | RedisMonitor::formatDuration (common)                   | 221.5 ns (370.1 ns)   | 159.5 ns (354.2 ns)   | 0.72x / 0.96x        | PHP (<1.5x)     |
 * | RedisMonitor::calculateHitRate (common)                 | 137.0 ns (263.0 ns)   | 66.9 ns (191.4 ns)    | 0.49x / 0.73x        | PHP (<1.5x)     |
 */
final class RustTmuxPaneMonitorParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_tmux_output_extract_server_info(const char *info, size_t info_len, char *out_buf, size_t out_cap);
        int64_t nntmux_tmux_output_percent(int64_t part, int64_t total);
        ssize_t nntmux_tmux_output_decode_count_row(int64_t in_process, const char *diff, size_t diff_len, int64_t in_database, int64_t percent, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_pane_get_names(int32_t sequential, uint8_t include_irc, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_pane_build_respawn_argv_nul(const char *base_args, size_t base_args_len, size_t base_count, uint8_t kill_flag, const char *target, size_t target_len, const char *directory, size_t directory_len, const char *env_pairs, size_t env_pairs_len, size_t env_pair_count, const char *worker_args, size_t worker_args_len, size_t worker_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_pane_build_log_tee_suffix(const char *path, size_t path_len, const char *pane, size_t pane_len, const char *date, size_t date_len, uint8_t write_logs, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_pane_get_sequential_command(const char *php_bin, size_t php_bin_len, const char *artisan, size_t artisan_len, int32_t niceness, const char *subcommand, size_t subcommand_len, const char *log_suffix, size_t log_suffix_len, char *out_buf, size_t out_cap);
        int64_t nntmux_tmux_monitor_increment_run_count(int64_t iterations);
        ssize_t nntmux_tmux_monitor_update_diffs_nul(const int64_t *now_vals, const int64_t *start_vals, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_monitor_calc_processing_stats(int64_t work, int64_t process_sum, int64_t tv, int64_t movies, int64_t audio, int64_t books, int64_t console, int64_t pc, int64_t xxx, int64_t misc, int64_t nfo, int64_t processnfo, int64_t predb_matched, int64_t predb, int64_t renamed, int64_t processrenames, int64_t *total_work_out, char *out_buf, size_t out_cap);
        int32_t nntmux_tmux_monitor_should_run_pane(const char *pane, size_t pane_len, int64_t binaries_run, int64_t backfill, int64_t releases_run, int64_t fix_names, const char *fix_crap_opt, size_t fix_crap_opt_len, const char *fix_crap, size_t fix_crap_len, int64_t post, int64_t post_non, int64_t post_amazon, int64_t processtvrage, int64_t processanime, int64_t processmovies, int64_t processbooks, int64_t processmusic, int64_t processgames, int64_t run_ircscraper, int64_t work_available, int64_t processnfo, int64_t processrenames, int64_t count_processtv, int64_t count_processanime, int64_t count_processmovies, int64_t count_processbooks, int64_t count_processmusic, int64_t count_processconsole, int64_t count_processgames, uint8_t kill_pp, uint8_t kill_coll);
        ssize_t nntmux_tmux_session_build_window_cmd_nul(const char *base_args, size_t base_args_len, size_t base_count, uint8_t cmd_kind, const char *session_target, size_t session_target_len, const char *window, size_t window_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_session_get_split_setup(int32_t sequential_mode, char *out_buf, size_t out_cap);
        ssize_t nntmux_redis_format_bytes(int64_t bytes, char *out_buf, size_t out_cap);
        ssize_t nntmux_redis_format_duration(int64_t seconds, char *out_buf, size_t out_cap);
        double nntmux_redis_hit_rate(int64_t hits, int64_t misses);
        C;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();
        TmuxOutput::setUseRustFfi(true);
        TmuxPaneManager::setUseRustFfi(false);
        TmuxMonitorService::setUseRustFfi(false);
        TmuxSessionManager::setUseRustFfi(false);
        RedisMonitor::setUseRustFfi(false);

        $ffi = RustPipelineLibrary::resolveDomain('irc', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }
        $this->ffi = $ffi;
    }

    protected function tearDown(): void
    {
        TmuxOutput::setUseRustFfi(true);
        TmuxPaneManager::setUseRustFfi(false);
        TmuxMonitorService::setUseRustFfi(false);
        TmuxSessionManager::setUseRustFfi(false);
        RedisMonitor::setUseRustFfi(false);
        parent::tearDown();
    }

    public function test_c_abi_resize_and_error_contracts(): void
    {
        $tiny = $this->ffi->new('char[2]');
        $serverInfo = 'Threads: 4  Questions: 90  Slow queries: 1  Opens: 12  Open tables: 8  Queries per second avg: 3.5';

        // @phpstan-ignore method.notFound
        self::assertSame(-strlen("4\x0012\x008\x001\x003"), $this->ffi->nntmux_tmux_output_extract_server_info($serverInfo, strlen($serverInfo), $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_output_extract_server_info("Threads: 1\n", 11, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_output_extract_server_info("Threads: 1\0", 11, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_tmux_output_percent(50, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(25, $this->ffi->nntmux_tmux_output_percent(1, 4));

        // @phpstan-ignore method.notFound
        self::assertSame(-strlen("1,234(+10)\x005,678(42%)"), $this->ffi->nntmux_tmux_output_decode_count_row(1234, '+10', 3, 5678, 42, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_output_decode_count_row(1234, "+1\0", 3, 5678, 42, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-strlen("monitor\nsequential\nfix_names\npost_metadata"), $this->ffi->nntmux_tmux_pane_get_names(2, 0, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_pane_get_names(0, 2, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_pane_build_respawn_argv_nul("tmux\0respawn-pane", 17, 1, 1, '%1', 2, '/app', 4, '', 0, 0, 'sh', 2, 1, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(0, $this->ffi->nntmux_tmux_pane_build_log_tee_suffix('/tmp', 4, 'tv', 2, '2026_10_24', 10, 0, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-strlen('2>&1 | tee -a /tmp/tv-2026_10_24.log'), $this->ffi->nntmux_tmux_pane_build_log_tee_suffix('/tmp', 4, 'tv', 2, '2026_10_24', 10, 1, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_pane_get_sequential_command("/usr/bin/php\x80", 13, '/app/artisan', 12, 2, 'binaries', 8, '', 0, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_monitor_increment_run_count(-1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_monitor_increment_run_count(PHP_INT_MAX));

        $totalWorkOut = $this->ffi->new('int64_t');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_monitor_calc_processing_stats(-1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, FFI::addr($totalWorkOut), $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_monitor_should_run_pane('binaries', 8, 1, 0, 0, 0, '', 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 2, 0));

        // @phpstan-ignore method.notFound
        self::assertSame(-strlen("tmux\0kill-window\0-t\0\$1:2"), $this->ffi->nntmux_tmux_session_build_window_cmd_nul('tmux', 4, 1, 1, '$1', 2, '2', 1, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_session_build_window_cmd_nul('tmux', 4, 2, 1, '$1', 2, '2', 1, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $this->ffi->nntmux_tmux_session_build_window_cmd_nul(null, 0, 1, 1, '$1', 2, '2', 1, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-strlen("0\th\tmonitor\t67\tsequential\n1\th\tfix_names\t50\tpost_metadata"), $this->ffi->nntmux_tmux_session_get_split_setup(2, $tiny, 2));

        // @phpstan-ignore method.notFound
        self::assertSame(-strlen('2d 6h 2m 3s'), $this->ffi->nntmux_redis_format_duration(194523, $tiny, 2));
    }

    public function test_tmux_output_parity(): void
    {
        $reflection = new ReflectionClass(TmuxOutput::class);
        /** @var TmuxOutput $output */
        $output = $reflection->newInstanceWithoutConstructor();

        $infoCases = [
            'Threads: 18  Questions: 948271  Slow queries: 4  Opens: 1842  Flush tables: 3  Open tables: 1024  Queries per second avg: 312.481',
            'Uptime: 8640000  Threads: 128  Questions: 9876543210  Slow queries: 4210  Opens: 1948271  Flush tables: 19  Open tables: 8192  Queries per second avg: 1143.118',
            'Threads: 1  Slow queries: 0  Opens: 5  Open tables: 2  Queries per second avg: 0',
            'No matching counters here',
        ];
        foreach ($infoCases as $info) {
            $expected = $output->extractServerInfoMetricsPhp($info);
            self::assertSame($expected, TmuxOutput::extractServerInfoMetricsNative($info));
            foreach ([true, false] as $useRust) {
                TmuxOutput::setUseRustFfi($useRust);
                self::assertSame($expected, $output->extractServerInfoMetrics($info));
            }
        }

        $percentMethod = new ReflectionMethod(TmuxOutput::class, 'percent');
        foreach ([[0, 0], [0, 100], [42, 200], [199, 200], [250, 100], [-5, 100], [10, -3]] as [$part, $total]) {
            $expected = $output->percentPhp($part, $total);
            self::assertSame($expected, TmuxOutput::percentNative($part, $total));
            self::assertSame($expected, $percentMethod->invoke($output, $part, $total));
        }

        foreach ([
            [0, '0', 0, 0],
            [12345, '+1,200', 987654, 42],
            [-45, '-12', 1000000, '99'],
        ] as [$inProc, $diff, $inDb, $pct]) {
            $expected = $output->decodeCountRowPhp($inProc, $diff, $inDb, $pct);
            self::assertSame($expected, TmuxOutput::decodeCountRowNative($inProc, $diff, $inDb, $pct));
            self::assertSame($expected, $output->decodeCountRow($inProc, $diff, $inDb, $pct));
        }
    }

    public function test_tmux_pane_manager_parity(): void
    {
        $reflection = new ReflectionClass(TmuxPaneManager::class);
        /** @var TmuxPaneManager $panes */
        $panes = $reflection->newInstanceWithoutConstructor();

        foreach ([0, 1, 2, 99] as $seq) {
            foreach ([true, false] as $irc) {
                $expected = $panes->getPaneNamesPhp($seq, $irc);
                self::assertSame($expected, TmuxPaneManager::getPaneNamesNative($seq, $irc));
                foreach ([true, false] as $useRust) {
                    TmuxPaneManager::setUseRustFfi($useRust);
                    self::assertSame($expected, $panes->getPaneNames($seq, $irc));
                }
            }
        }

        foreach ([true, false] as $writeLogs) {
            $expected = $panes->buildLogTeeSuffixPhp('/var/log/nntmux', 'binaries', '2026_10_24', $writeLogs);
            $supported = false;
            self::assertSame($expected, TmuxPaneManager::buildLogTeeSuffixNative('/var/log/nntmux', 'binaries', '2026_10_24', $writeLogs, $supported));
            self::assertTrue($supported);
            TmuxPaneManager::setUseRustFfi(true);
            self::assertSame($expected, $panes->buildLogTeeSuffix('/var/log/nntmux', 'binaries', '2026_10_24', $writeLogs));
        }

        foreach ([
            ['/usr/bin/php8.5', '/var/www/nntmux/artisan', 2, 'multiprocessing:update-per-group', ''],
            ["/usr/local/bin/php's", '/var/www/nntmux/artisan', 10, 'binaries:run', '2>&1 | tee -a /tmp/bin.log'],
        ] as [$phpBin, $artisan, $nice, $sub, $log]) {
            $expected = $panes->getSequentialCommandPhp($phpBin, $artisan, $nice, $sub, $log);
            self::assertSame($expected, TmuxPaneManager::getSequentialCommandNative($phpBin, $artisan, $nice, $sub, $log));
            TmuxPaneManager::setUseRustFfi(true);
            self::assertSame($expected, $panes->getSequentialCommand($phpBin, $artisan, $nice, $sub, $log));
        }

        $baseArgs = ['/usr/bin/tmux', '-f', '/etc/tmux.conf', 'respawn-pane'];
        $workerArgs = ['/bin/sh', '-c', 'php artisan tmux:run;', 'trailing;'];
        $envPairs = ['NNTMUX_SESSION' => 'nntmux', 'APP_ENV' => 'testing'];
        foreach ([true, false] as $kill) {
            foreach ([$envPairs, []] as $env) {
                $expected = $panes->buildRespawnCommandPhp($baseArgs, $kill, '%12', '/var/www/nntmux', $env, $workerArgs);
                self::assertSame($expected, TmuxPaneManager::buildRespawnCommandNative($baseArgs, $kill, '%12', '/var/www/nntmux', $env, $workerArgs));
            }
        }
    }

    public function test_tmux_monitor_service_parity(): void
    {
        $reflection = new ReflectionClass(TmuxMonitorService::class);
        /** @var TmuxMonitorService $monitor */
        $monitor = $reflection->newInstanceWithoutConstructor();

        foreach ([0, 1, 42, 100000] as $iter) {
            $expected = $monitor->incrementRunCountPhp($iter);
            self::assertSame($expected, $monitor->incrementRunCountNative($iter));
            TmuxMonitorService::setUseRustFfi(true);
            self::assertSame($expected, $monitor->incrementRunCount($iter));
        }

        $now = [
            'work' => 500,
            'work_available' => 410,
            'processanime' => 12,
            'processbooks' => 45,
            'processconsole' => 8,
            'processgames' => 19,
            'processmovies' => 34,
            'processmusic' => 67,
            'processnfo' => 230,
            'processrenames' => 540,
            'processtv' => 88,
            'tv' => 18400,
            'movies' => 9800,
            'audio' => 1200,
            'books' => 890,
            'console' => 340,
            'pc' => 1500,
            'xxx' => 5000,
            'misc' => 4100,
            'nfo' => 18500,
            'predb' => 250000,
            'predb_matched' => 64000,
            'renamed' => 29400,
        ];
        $start = array_map(static fn (int $v): int => (int) ($v * 0.75), $now);

        $expectedDiffs = $monitor->updateDiffsPhp($now, $start);
        self::assertSame($expectedDiffs, $monitor->updateDiffsNative($now, $start));
        TmuxMonitorService::setUseRustFfi(true);
        self::assertSame($expectedDiffs, $monitor->updateDiffs($now, $start));

        foreach ([$now, ['work' => 0, 'predb' => 0]] as $nowCase) {
            $expectedStats = $monitor->calculateProcessingStatsPhp($nowCase);
            self::assertSame($expectedStats, $monitor->calculateProcessingStatsNative($nowCase));
            TmuxMonitorService::setUseRustFfi(true);
            self::assertSame($expectedStats, $monitor->calculateProcessingStats($nowCase));
        }

        // Verify calculateStatistics end-to-end under both FFI and PHP
        foreach ([false, true] as $useRust) {
            TmuxMonitorService::setUseRustFfi($useRust);
            $runVarProp = new ReflectionProperty(TmuxMonitorService::class, 'runVar');
            $iterProp = new ReflectionProperty(TmuxMonitorService::class, 'iterations');
            $calcMethod = new ReflectionMethod(TmuxMonitorService::class, 'calculateStatistics');

            $runVarProp->setValue($monitor, [
                'counts' => [
                    'now' => $now,
                    'start' => $start,
                    'diff' => [],
                    'percent' => [],
                ],
            ]);
            $iterProp->setValue($monitor, 2);
            $calcMethod->invoke($monitor);
            $results[$useRust ? 'rust' : 'php'] = $runVarProp->getValue($monitor);
        }
        self::assertSame($results['php'], $results['rust']);

        $settings = [
            'binaries_run' => 1,
            'backfill' => 4,
            'releases_run' => 1,
            'fix_names' => 1,
            'fix_crap_opt' => 'Custom',
            'fix_crap' => '0, codec, sample',
            'post' => 3,
            'post_non' => 1,
            'post_amazon' => 1,
            'processtvrage' => 1,
            'processanime' => 1,
            'processmovies' => 1,
            'processbooks' => 1,
            'processmusic' => 1,
            'processgames' => 1,
            'run_ircscraper' => 1,
        ];
        $panes = ['binaries', 'backfill', 'releases', 'sequential', 'fixnames', 'removecrap', 'ppadditional', 'tv', 'nonamazon', 'movies', 'amazon', 'scraper', 'unknown'];
        foreach ($panes as $paneName) {
            foreach ([['pp' => false, 'coll' => false], ['pp' => true, 'coll' => false], ['pp' => false, 'coll' => true]] as $kill) {
                $expected = $monitor->shouldRunPanePhp($paneName, $settings, $now, $kill);
                self::assertSame($expected, $monitor->shouldRunPaneNative($paneName, $settings, $now, $kill));
                TmuxMonitorService::setUseRustFfi(true);
                self::assertSame($expected, $monitor->shouldRunPane($paneName, $settings, $now, $kill));
            }
        }
    }

    public function test_tmux_session_manager_parity(): void
    {
        $reflection = new ReflectionClass(TmuxSessionManager::class);
        /** @var TmuxSessionManager $session */
        $session = $reflection->newInstanceWithoutConstructor();

        $baseArgs = ['/usr/bin/tmux', '-f', '/etc/tmux.conf'];
        foreach ([0, 1, 2, '3'] as $window) {
            $expectedTiled = $session->buildSelectTiledCommandPhp($window, '$1', $baseArgs);
            self::assertSame($expectedTiled, $session->buildSelectTiledCommandNative($window, '$1', $baseArgs));
            TmuxSessionManager::setUseRustFfi(true);
            self::assertSame($expectedTiled, $session->buildSelectTiledCommand($window, '$1', $baseArgs));

            $expectedKill = $session->buildKillWindowCommandPhp($window, '$1', $baseArgs);
            self::assertSame($expectedKill, $session->buildKillWindowCommandNative($window, '$1', $baseArgs));
            self::assertSame($expectedKill, $session->buildKillWindowCommand($window, '$1', $baseArgs));
        }

        foreach ([0, 1, 2, 99] as $mode) {
            $expectedSplit = $session->getSplitSetupPhp($mode);
            self::assertSame($expectedSplit, $session->getSplitSetupNative($mode));
            TmuxSessionManager::setUseRustFfi(true);
            self::assertSame($expectedSplit, $session->getSplitSetup($mode));
        }
    }

    public function test_redis_monitor_command_parity(): void
    {
        $redis = new RedisMonitor;

        foreach ([0, 512, 1024, 1536, 1048576, 157286400, 1073741824, 5368709120] as $bytes) {
            $expected = $redis->formatBytesPhp($bytes);
            self::assertSame($expected, $redis->formatBytesNative($bytes));
            RedisMonitor::setUseRustFfi(true);
            self::assertSame($expected, $redis->formatBytes($bytes));
        }

        foreach ([0, 45, 60, 125, 3600, 7265, 86400, 194523, -95] as $seconds) {
            $expected = $redis->formatDurationPhp($seconds);
            self::assertSame($expected, $redis->formatDurationNative($seconds));
            RedisMonitor::setUseRustFfi(true);
            self::assertSame($expected, $redis->formatDuration($seconds));
        }

        foreach ([[0, 0], [100, 0], [0, 100], [948271, 51829], [1, 2]] as [$hits, $misses]) {
            $expected = $redis->calculateHitRatePhp($hits, $misses);
            self::assertSame($expected, $redis->calculateHitRateNative($hits, $misses));
            RedisMonitor::setUseRustFfi(true);
            self::assertSame($expected, $redis->calculateHitRate($hits, $misses));
        }
    }
}
