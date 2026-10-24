<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\TmuxDrain;
use App\Console\Commands\TmuxStop;
use App\Enums\ConfigurationDomain;
use App\Models\Category;
use App\Services\Runners\BaseRunner;
use App\Services\Runners\PostProcessRunner;
use App\Services\Runners\ReleasesRunner;
use App\Services\Tmux\TmuxTaskRunner;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

/**
 * Reproduce: TMUX_RUNNERS_BENCHMARK=1 php artisan test --compact
 * tests/Unit/RustTmuxRunnersAndDrainParityTest.php
 *
 * Paired, randomized single-call timings include PHP marshalling, FFI, scratch reads,
 * allocation and result decoding. Two repetitions of 5,000 pairs per regime, 250
 * warmup pairs; nearest-rank p99. SQL construction is measured separately from DB
 * execution; stop-result classification excludes the identical process I/O. Neither
 * reduced measurement qualifies those full operations for production adoption.
 * None of the 19 candidates qualified across all four metrics; PHP is retained.
 * Large output tails and session listings alone can favor Rust, but their common
 * paths fail the gate. This is helper evidence, not production throughput evidence.
 * Set TMUX_RUNNERS_BENCHMARK_OUTPUT to preserve raw pairs plus metadata as JSON.
 */
final class RustTmuxRunnersAndDrainParityTest extends TestCase
{
    private Randomizer $random;

    private BaseRunner $base;

    private string $nativeLibraryPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::installDefaults();
        $this->random = new Randomizer(new Mt19937(20261024));
        $this->base = new class extends BaseRunner {};
    }

    protected function tearDown(): void
    {
        TmuxTaskRunner::setUseRustFfi(false);
        BaseRunner::setUseRustFfi(null);
        TmuxDrain::setUseRustFfi(null);
        TmuxStop::setUseRustFfi(null);
        parent::tearDown();
    }

    private function requireNative(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('irc', BaseRunner::FFI_CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Build the release pipeline FFI library with Wave 15 runner exports.');
        }

        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        foreach ([base_path('storage/app/nntmux-native/libnntmux_pipeline.'.$suffix), base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix), '/usr/local/lib/nntmux/libnntmux_pipeline.'.$suffix] as $candidate) {
            if (RustPipelineLibrary::resolveDomain('irc', BaseRunner::FFI_CDEF, $candidate) !== null) {
                $this->nativeLibraryPath = (string) realpath($candidate);
                break;
            }
        }
        self::assertNotSame('', $this->nativeLibraryPath);

        return $ffi;
    }

    public function test_production_defaults_retain_php(): void
    {
        self::assertFalse(TmuxTaskRunner::usesRustFfi());
        self::assertFalse(BaseRunner::usesRustFfi());
        self::assertFalse(TmuxDrain::usesRustFfi());
        self::assertFalse(TmuxStop::usesRustFfi());
    }

    public function test_build_commands_and_fix_names_are_byte_exact(): void
    {
        $this->requireNative();
        config(['tmux.socket_name' => "socket 'quoted'", 'tmux.fix_names.category' => "other ' quoted"]);
        $runner = new TmuxTaskRunner("session with 'quotes'");
        $commands = ['', 'false', 'echo "$HOME"; exit 7', "printf '日本語\n'", "binary\0payload", str_repeat('echo x; ', 4000)];
        for ($i = 0; $i < 80; $i++) {
            $commands[] = str_repeat('word ', $this->random->getInt(0, 150)).(string) $i;
        }
        foreach ($commands as $i => $command) {
            $options = $i % 2 === 0 ? [] : ['sleep' => $i - 20];
            $php = $runner->buildCommandPhp($command, $options);
            self::assertSame($php, $runner->buildCommandNative($command, $options));
            foreach ([false, true] as $use) {
                TmuxTaskRunner::setUseRustFfi($use);
                self::assertSame($php, $runner->buildCommand($command, $options));
            }
            self::assertStringContainsString('nntmux_status=$?;', $php);
            self::assertStringEndsWith('exit "$nntmux_status"', $php);
            self::assertSame(2, substr_count($php, 'nntmux_draining=$('));
        }
        foreach ([[], ['false'], ['false', 'true'], $commands] as $batch) {
            self::assertSame($runner->batchCommandPhp($batch), $runner->batchCommandNative($batch));
        }
        foreach (['nntmux_status', 'nntmux_batch_status', ''] as $status) {
            self::assertSame($runner->drainGuardPhp($status), $runner->drainGuardNative($status));
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 60, PHP_INT_MAX] as $seconds) {
            self::assertSame($runner->buildSleepCommandPhp($seconds), $runner->buildSleepCommandNative($seconds));
        }
        foreach ([false, true] as $scheduled) {
            foreach ([false, true] as $fullConfig) {
                foreach ([null, 0, -2, 1, 1500] as $limit) {
                    config(['tmux.fix_names.par2_scheduled' => $scheduled, 'tmux.fix_names.full_backlog' => $fullConfig, 'tmux.fix_names.batch_limit' => $limit]);
                    for ($flags = 0; $flags < 8; $flags++) {
                        $args = [(bool) ($flags & 1), (bool) ($flags & 2), (bool) ($flags & 4)];
                        self::assertSame($runner->fixNamesCommandsPhp(...$args), $runner->fixNamesCommandsNative(...$args));
                    }
                }
            }
        }
    }

    public function test_log_file_preserves_disabled_and_directory_creation_behavior(): void
    {
        $this->requireNative();
        $runner = new TmuxTaskRunner('test');
        ConfigurationTestBuilder::update(ConfigurationDomain::Tmux, ['write_logs' => false]);
        self::assertSame('/dev/null', $runner->getLogFileNative('pane'));
        self::assertSame('/dev/null', $runner->getLogFilePhp('pane'));
        $directory = sys_get_temp_dir().'/nntmux-wave15-'.bin2hex(random_bytes(8));
        config(['tmux.paths.logs' => $directory]);
        $this->travelTo(now()->setDate(2026, 10, 24)->startOfDay());
        try {
            ConfigurationTestBuilder::update(ConfigurationDomain::Tmux, ['write_logs' => true]);
            self::assertSame($directory.'/日本語-2026_10_24.log', $runner->getLogFileNative('日本語'));
            self::assertDirectoryExists($directory);
            self::assertSame($runner->getLogFilePhp('pane'), $runner->getLogFileNative('pane'));
        } finally {
            $this->travelBack();
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_base_runner_argument_parity_and_failure_contract(): void
    {
        $this->requireNative();
        $commands = ['backfill', 'backfill_all_quantity', 'backfill_all_quick', 'get_range', 'part_repair', 'releases', 'update_group_headers', 'update_per_group', 'pp_additional', 'pp_nfo', 'pp_movie', 'pp_tv'];
        foreach ($commands as $command) {
            foreach (['', '  ', '  alt.test  42', '  日本語  1  2  3  4', '    empty    fields', '  '.str_repeat('a', 20000)] as $suffix) {
                $args = "\t".$command.$suffix."\r\n";
                self::assertSame($this->base->buildDnrCommandPhp($args), $this->base->buildDnrCommandNative($args));
                foreach ([false, true] as $use) {
                    BaseRunner::setUseRustFfi($use);
                    self::assertSame($this->base->buildDnrCommandPhp($args), $this->base->buildDnrCommandPublic($args));
                }
            }
        }
        foreach (['', 'unknown', 'backfill single-space'] as $args) {
            foreach ([false, true] as $use) {
                BaseRunner::setUseRustFfi($use);
                try {
                    $this->base->buildDnrCommandPublic($args);
                    self::fail('Unknown commands must throw.');
                } catch (RuntimeException $exception) {
                    self::assertSame('Unrecognized multiprocessing command.', $exception->getMessage());
                }
            }
        }
        foreach ([null, 0, -1, 90, '123', PHP_INT_MAX] as $configured) {
            config(['nntmux.concurrency_timeout' => $configured, 'nntmux.multiprocessing_max_child_time' => 1801]);
            self::assertSame($this->base->concurrencyTimeoutPhp(), $this->base->concurrencyTimeoutNative());
        }
        foreach (['', 0, 'group', PHP_INT_MIN, PHP_INT_MAX] as $key) {
            self::assertSame($this->base->workerConcurrencyKeyPhp($key), $this->base->workerConcurrencyKeyNative($key));
        }
    }

    private function outputProcess(string $stdout, string $stderr, bool $successful = false): Process
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn($successful);
        $process->method('getOutput')->willReturn($stdout);
        $process->method('getErrorOutput')->willReturn($stderr);

        return $process;
    }

    public function test_output_tail_parity_for_ansi_multibyte_and_empty_outputs(): void
    {
        $this->requireNative();
        foreach (['', " \t\r\n\0", "\e[31merror\e[0m\n", "\e[1;2m色\e[0m", str_repeat('日本語🐈', 500), "invalid \e[xm and \e[31 incomplete"] as $stdout) {
            foreach (['', "\e[mwarning\e[0m", str_repeat("ø\n", 350)] as $stderr) {
                foreach ([false, true] as $success) {
                    $process = $this->outputProcess($stdout, $stderr, $success);
                    self::assertSame($this->base->outputTailPhp($process), $this->base->outputTailNative($process));
                }
            }
        }
    }

    public function test_bucket_sql_and_tv_policy_parity(): void
    {
        $this->requireNative();
        $runner = new PostProcessRunner;
        foreach (['leftguid', 'releases.leftguid', '', "日本語\0column", str_repeat('c', 32000)] as $column) {
            self::assertSame($runner->guidBucketExpressionPhp($column), $runner->guidBucketExpressionNative($column));
        }
        foreach ([0, 1, 2] as $mode) {
            ConfigurationTestBuilder::update(ConfigurationDomain::Metadata, ['book_lookup' => $mode, 'tv_lookup' => $mode, 'game_lookup' => $mode]);
            foreach (['Books', 'Music', 'Console', 'Games'] as $kind) {
                $php = 'get'.$kind.'BucketsSqlPhp';
                $native = 'get'.$kind.'BucketsSqlNative';
                self::assertSame($runner->$php(), $runner->$native());
            }
            foreach ([false, true] as $renamedOnly) {
                self::assertSame($runner->tvWorkConditionPhp($renamedOnly), $runner->tvWorkConditionNative($renamedOnly));
            }
        }
        // Both database driver spellings are tested directly at the ABI, without a server.
        self::assertSame('LEFT(x, 1)', BaseRunner::runnerTransformNative(6, ['mysql', 'x']));
        self::assertSame('substr(x, 1, 1)', BaseRunner::runnerTransformNative(6, ['sqlite', 'x']));
    }

    public function test_bucket_callers_preserve_database_results_and_late_article_exclusion(): void
    {
        $this->requireNative();
        DB::statement('CREATE TABLE releases (leftguid TEXT, categories_id INTEGER, isrenamed INTEGER DEFAULT 1,
            musicinfo_id INTEGER, consoleinfo_id INTEGER, gamesinfo_id INTEGER DEFAULT 0,
            bookinfo_id INTEGER, book_lookup_attempts INTEGER DEFAULT 0, book_lookup_retry_at TEXT,
            book_name_normalized_at TEXT, searchname TEXT DEFAULT "title", name TEXT DEFAULT "title",
            late_article_candidate_id INTEGER)');
        foreach ([Category::BOOKS_ROOT, Category::MUSIC_MP3, Category::GAME_ROOT, Category::PC_GAMES] as $i => $category) {
            DB::table('releases')->insert(['leftguid' => $i.'good', 'categories_id' => $category]);
            DB::table('releases')->insert(['leftguid' => 'xlate', 'categories_id' => $category, 'late_article_candidate_id' => 1]);
        }
        ConfigurationTestBuilder::update(ConfigurationDomain::Metadata, ['book_lookup' => 2, 'game_lookup' => 2]);
        $runner = new PostProcessRunner;
        foreach (['Books', 'Music', 'Console', 'Games'] as $kind) {
            $php = 'get'.$kind.'BucketsPhp';
            $native = 'get'.$kind.'BucketsNative';
            $expected = $runner->$php();
            self::assertCount(1, $expected);
            self::assertEquals($expected, $runner->$native());
            foreach ([false, true] as $use) {
                BaseRunner::setUseRustFfi($use);
                self::assertEquals($expected, (new ReflectionMethod($runner, 'get'.$kind.'Buckets'))->invoke($runner));
            }
        }
    }

    public function test_native_declines_and_dispatchers_fall_back_to_php(): void
    {
        $this->requireNative();
        $process = $this->outputProcess("invalid \xff UTF-8", '');
        self::assertNull($this->base->outputTailNative($process));
        BaseRunner::setUseRustFfi(true);
        self::assertSame($this->base->outputTailPhp($process), (new ReflectionMethod($this->base, 'outputTail'))->invoke($this->base, $process));
        $ffi = new ReflectionProperty(BaseRunner::class, 'ffi');
        $resolved = new ReflectionProperty(BaseRunner::class, 'ffiResolved');
        $original = $ffi->getValue();
        $wasResolved = $resolved->getValue();
        $ffi->setValue(null, null);
        $resolved->setValue(null, true);
        try {
            $tmux = new TmuxTaskRunner('fallback');
            TmuxTaskRunner::setUseRustFfi(true);
            self::assertSame($tmux->buildCommandPhp('false'), $tmux->buildCommand('false'));
            self::assertSame($this->base->buildDnrCommandPhp('releases  1'), $this->base->buildDnrCommandPublic('releases  1'));
            self::assertSame(15, (new ReflectionMethod(TmuxDrain::class, 'monitorRecoveryTimeout'))->invoke(new TmuxDrain));
        } finally {
            $ffi->setValue(null, $original);
            $resolved->setValue(null, $wasResolved);
        }
    }

    public function test_release_followup_and_monitor_recovery_parity(): void
    {
        $this->requireNative();
        $runner = new ReleasesRunner;
        foreach ([0, 1, 60, PHP_INT_MAX] as $interval) {
            (new ReflectionProperty($runner, 'rollingInterval'))->setValue($runner, $interval);
            foreach ([1, '', 'alt.test', '日本語  two'] as $key) {
                foreach ([0.0, 0.5, 1782345678.125] as $start) {
                    self::assertSame($runner->workerFollowUpPhp($key, $start), $runner->workerFollowUpNative($key, $start));
                }
            }
        }
        $drain = new TmuxDrain;
        self::assertSame(15, $drain->monitorRecoveryTimeoutPhp());
        self::assertSame(15, $drain->monitorRecoveryTimeoutNative());
    }

    public function test_stop_classifier_fails_closed_and_original_handler_preserves_errors(): void
    {
        $this->requireNative();
        $cases = [
            [true, "other\nnntmux\n", '', 0, 1],
            [true, "other\n", '', 0, 0],
            [true, "nntmux-other\n", '', 0, 0],
            [false, '', 'no server running on /tmp/socket', 1, 0],
            [false, '', 'error connecting to /tmp/socket (No such file or directory)', 1, 0],
            [false, '', 'error connecting to /tmp/socket (Permission denied)', 1, 2],
            [false, '', 'no server running on /tmp/socket', 2, 2],
            [false, '', '', null, 2],
        ];
        foreach ($cases as [$success, $output, $error, $code, $expected]) {
            self::assertSame($expected, TmuxStop::sessionAbsencePhp('nntmux', $success, $output, $error, $code));
            self::assertSame($expected, TmuxStop::sessionAbsenceNative('nntmux', $success, $output, $error, $code));
            foreach ([false, true] as $use) {
                TmuxStop::setUseRustFfi($use);
                ProcessFacade::fake(['*' => ProcessFacade::result(output: $output, errorOutput: $error, exitCode: $code ?? 2)]);
                try {
                    (new ReflectionMethod(TmuxStop::class, 'confirmSessionAbsent'))->invoke(new TmuxStop, 'nntmux');
                    self::assertSame(0, $expected);
                } catch (RuntimeException $exception) {
                    self::assertSame($expected === 1
                        ? 'The tmux session still exists; keeping the stop request.'
                        : 'Unable to confirm the tmux session is absent; keeping the stop request.', $exception->getMessage());
                }
            }
        }
    }

    /** @return array<string, array{Closure, Closure}> */
    private function benchmarkWorkloads(bool $uncommon): array
    {
        BaseRunner::setUseRustFfi(false);
        ConfigurationTestBuilder::update(ConfigurationDomain::Tmux, ['write_logs' => true]);
        ConfigurationTestBuilder::update(ConfigurationDomain::Metadata, [
            'book_lookup' => $uncommon ? 2 : 1, 'tv_lookup' => $uncommon ? 2 : 1, 'game_lookup' => $uncommon ? 2 : 1,
        ]);
        config(['tmux.paths.logs' => sys_get_temp_dir(), 'nntmux.concurrency_timeout' => $uncommon ? null : 1800]);
        $tmux = new TmuxTaskRunner($uncommon ? "日本語 session 'quoted'" : 'nntmux');
        $post = new PostProcessRunner;
        $releases = new ReleasesRunner;
        (new ReflectionProperty($releases, 'rollingInterval'))->setValue($releases, $uncommon ? 3600 : 60);
        $drain = new TmuxDrain;
        $command = $uncommon ? str_repeat('echo "日本語"; ', 2048) : 'php artisan update:postprocess';
        $batch = $uncommon ? array_fill(0, 40, $command) : ['true', 'false'];
        $seconds = $uncommon ? PHP_INT_MAX : 60;
        $dnr = $uncommon ? 'releases  '.str_repeat('  日本語', 100) : 'backfill  alt.test  10';
        $stdout = $uncommon ? str_repeat("\e[31m日本語🐈\e[0m\n", 2500) : 'Processed 20 releases.';
        $stderr = $uncommon ? str_repeat("error\n", 1000) : 'Warning.';
        $output = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, stream_get_contents(STDIN)); fwrite(STDERR, base64_decode($argv[1])); exit(1);', base64_encode($stderr)]);
        $output->setInput($stdout);
        $output->run();
        self::assertSame(1, $output->getExitCode());
        $column = $uncommon ? str_repeat('long_identifier_', 64) : 'leftguid';
        $key = $uncommon ? '日本語  group' : 1;
        $sessions = $uncommon ? implode("\n", array_map(static fn (int $i): string => 'session-'.$i, range(1, 1000))) : "nntmux\nother\n";
        $workloads = [
            'buildCommand' => [fn () => $tmux->buildCommandPhp($command, ['sleep' => $seconds]), fn () => $tmux->buildCommandNative($command, ['sleep' => $seconds])],
            'batchCommand' => [fn () => $tmux->batchCommandPhp($batch), fn () => $tmux->batchCommandNative($batch)],
            'drainGuard' => [fn () => $tmux->drainGuardPhp('nntmux_status'), fn () => $tmux->drainGuardNative('nntmux_status')],
            'buildSleepCommand' => [fn () => $tmux->buildSleepCommandPhp($seconds), fn () => $tmux->buildSleepCommandNative($seconds)],
            'getLogFile' => [fn () => $tmux->getLogFilePhp('pane'), fn () => $tmux->getLogFileNative('pane')],
            'fixNamesCommands' => [fn () => $tmux->fixNamesCommandsPhp(true, $uncommon, $uncommon), fn () => $tmux->fixNamesCommandsNative(true, $uncommon, $uncommon)],
            'workerConcurrencyKey' => [fn () => $this->base->workerConcurrencyKeyPhp($key), fn () => $this->base->workerConcurrencyKeyNative($key)],
            'concurrencyTimeout' => [fn () => $this->base->concurrencyTimeoutPhp(), fn () => $this->base->concurrencyTimeoutNative()],
            'buildDnrCommand' => [fn () => $this->base->buildDnrCommandPhp($dnr), fn () => $this->base->buildDnrCommandNative($dnr)],
            'outputTail' => [fn () => $this->base->outputTailPhp($output), fn () => $this->base->outputTailNative($output)],
            'guidBucketExpression' => [fn () => $post->guidBucketExpressionPhp($column), fn () => $post->guidBucketExpressionNative($column)],
            'tvWorkCondition' => [fn () => $post->tvWorkConditionPhp($uncommon), fn () => $post->tvWorkConditionNative($uncommon)],
            'workerFollowUp' => [fn () => $releases->workerFollowUpPhp($key, 1234567.25), fn () => $releases->workerFollowUpNative($key, 1234567.25)],
            'monitorRecoveryTimeout' => [fn () => $drain->monitorRecoveryTimeoutPhp(), fn () => $drain->monitorRecoveryTimeoutNative()],
            'confirmSessionAbsent.classifier' => [fn () => TmuxStop::sessionAbsencePhp('nntmux', true, $sessions, '', 0), fn () => TmuxStop::sessionAbsenceNative('nntmux', true, $sessions, '', 0)],
        ];
        foreach (['Books', 'Music', 'Console', 'Games'] as $kind) {
            $php = 'get'.$kind.'BucketsSqlPhp';
            $native = 'get'.$kind.'BucketsSqlNative';
            $workloads['get'.$kind.'Buckets.sql'] = [fn () => $post->$php(), fn () => $post->$native()];
        }

        return $workloads;
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('TMUX_RUNNERS_BENCHMARK') !== '1') {
            self::markTestSkipped('Set TMUX_RUNNERS_BENCHMARK=1 for paired randomized benchmarks.');
        }
        $this->requireNative();
        $pairs = max(5000, (int) (getenv('TMUX_RUNNERS_BENCHMARK_PAIRS') ?: 5000));
        $repetitions = max(2, (int) (getenv('TMUX_RUNNERS_BENCHMARK_REPS') ?: 2));
        $raw = $summary = [];
        foreach (['common', 'uncommon'] as $regime) {
            foreach ($this->benchmarkWorkloads($regime === 'uncommon') as $method => [$php, $rust]) {
                self::assertSame($php(), $rust(), "Preflight parity {$method}/{$regime}");
                for ($warmup = 0; $warmup < 250; $warmup++) {
                    $php();
                    $rust();
                }
                $phpAll = $rustAll = [];
                $reps = [];
                for ($rep = 0; $rep < $repetitions; $rep++) {
                    $phpSamples = $rustSamples = $order = [];
                    for ($i = 0; $i < $pairs; $i++) {
                        $rustFirst = $this->random->getInt(0, 1) === 1;
                        $order[] = $rustFirst;
                        if ($rustFirst) {
                            $start = hrtime(true);
                            $actual = $rust();
                            $rustSamples[] = hrtime(true) - $start;
                            $start = hrtime(true);
                            $expected = $php();
                            $phpSamples[] = hrtime(true) - $start;
                        } else {
                            $start = hrtime(true);
                            $expected = $php();
                            $phpSamples[] = hrtime(true) - $start;
                            $start = hrtime(true);
                            $actual = $rust();
                            $rustSamples[] = hrtime(true) - $start;
                        }
                        self::assertSame($expected, $actual, "Measured parity {$method}/{$regime}/{$rep}/{$i}");
                    }
                    $raw[$method][$regime][$rep] = ['php_ns' => $phpSamples, 'rust_ns' => $rustSamples, 'rust_first' => $order];
                    array_push($phpAll, ...$phpSamples);
                    array_push($rustAll, ...$rustSamples);
                    $reps[] = self::latencySummary($phpSamples, $rustSamples);
                }
                $summary[$method][$regime] = [...self::latencySummary($phpAll, $rustAll), 'repetitions' => $reps];
            }
        }
        foreach ($summary as &$method) {
            $method['qualified'] = min($method['common']['mean_speedup'], $method['common']['p99_speedup'], $method['uncommon']['mean_speedup'], $method['uncommon']['p99_speedup']) >= 1.5;
        }
        unset($method);
        $metadata = [
            'php' => PHP_VERSION, 'os' => php_uname(), 'cpu' => PHP_OS_FAMILY === 'Darwin' ? trim((new Process(['sysctl', '-n', 'machdep.cpu.brand_string']))->mustRun()->getOutput()) : php_uname('m'),
            'rustc' => trim((new Process(['rustc', '-Vv']))->mustRun()->getOutput()),
            'cargo' => trim((new Process(['cargo', '-V']))->mustRun()->getOutput()),
            'base_head' => trim((new Process(['git', 'rev-parse', 'HEAD']))->mustRun()->getOutput()),
            'source_sha256' => [], 'composer_lock_sha256' => hash_file('sha256', base_path('composer.lock')),
            'vendor_sha256' => hash_file('sha256', base_path('vendor/composer/installed.php')),
            'seed' => 20261024, 'warmup_pairs' => 250, 'pairs_per_repetition' => $pairs,
            'repetitions' => $repetitions, 'profile' => 'release opt-level=3 lto=true codegen-units=1',
            'php_ini' => ['opcache.enable_cli' => ini_get('opcache.enable_cli'), 'opcache.jit' => ini_get('opcache.jit'), 'pcre.jit' => ini_get('pcre.jit')],
            'limits' => 'host process; no experiment-specific CPU or memory cap',
            'failures' => [], 'uncertainty' => 'Per-repetition estimates retained; p99 is nearest rank, not a confidence bound.',
            'scope' => 'Local helper timings; SQL and session classifier exclude database/process execution; no full-operation adoption from these measurements.',
        ];
        foreach (['rust/pipeline-ffi/src/irc.rs', 'app/Services/Tmux/TmuxTaskRunner.php', 'app/Services/Runners/BaseRunner.php', 'app/Services/Runners/PostProcessRunner.php', 'app/Services/Runners/ReleasesRunner.php', 'app/Console/Commands/TmuxDrain.php', 'app/Console/Commands/TmuxStop.php', 'tests/Unit/RustTmuxRunnersAndDrainParityTest.php'] as $path) {
            $metadata['source_sha256'][$path] = hash_file('sha256', base_path($path));
        }
        $metadata['library_path'] = $this->nativeLibraryPath;
        $metadata['library_sha256'] = hash_file('sha256', $this->nativeLibraryPath);
        $output = getenv('TMUX_RUNNERS_BENCHMARK_OUTPUT');
        if (is_string($output) && $output !== '') {
            file_put_contents($output, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw' => $raw], JSON_THROW_ON_ERROR));
        }
        fwrite(STDERR, "\nWave15 Slice2 benchmark: ".json_encode(['metadata' => $metadata, 'summary' => $summary], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    }

    /**
     * @param  list<int>  $php
     * @param  list<int>  $rust
     * @return array{samples:int, php_mean_ns:float, rust_mean_ns:float, php_p99_ns:int, rust_p99_ns:int, mean_speedup:float, p99_speedup:float}
     */
    private static function latencySummary(array $php, array $rust): array
    {
        sort($php);
        sort($rust);
        $count = count($php);
        $rank = (int) ceil($count * 0.99) - 1;
        $phpMean = array_sum($php) / $count;
        $rustMean = array_sum($rust) / $count;

        return ['samples' => $count, 'php_mean_ns' => $phpMean, 'rust_mean_ns' => $rustMean,
            'php_p99_ns' => $php[$rank], 'rust_p99_ns' => $rust[$rank],
            'mean_speedup' => $phpMean / max(1, $rustMean), 'p99_speedup' => $php[$rank] / max(1, $rust[$rank])];
    }
}
