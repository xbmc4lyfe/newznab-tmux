<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Runners;

use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Runners\BaseRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class BaseRunnerTest extends TestCase
{
    #[Test]
    public function execute_command_returns_output_on_success(): void
    {
        $runner = new BaseRunnerTestDouble;

        $this->assertSame('hello', trim($runner->runCommand('echo hello')));
    }

    #[Test]
    public function execute_command_throws_runtime_exception_with_clear_message_on_timeout(): void
    {
        config(['nntmux.concurrency_timeout' => 1]);

        $runner = new BaseRunnerTestDouble;

        try {
            $runner->runCommand('sleep 5');
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            // Laravel's Concurrency ProcessDriver cannot reconstruct
            // ProcessTimedOutException, so executeCommand() must surface a
            // RuntimeException carrying the original timeout message instead.
            $this->assertStringContainsString('exceeded the timeout', $e->getMessage());
        }
    }

    #[Test]
    public function asynchronous_pool_enforces_timeout_and_continues_other_jobs(): void
    {
        $runner = new BaseRunnerTestDouble;
        try {
            $runner->pool([
                'hung' => [PHP_BINARY, '-r', 'sleep(3);'],
                'ok' => [PHP_BINARY, '-r', 'echo "done";'],
            ], 2, 1);
            $this->fail('Expected a failed batch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('hung timeout', $exception->getMessage());
            $this->assertSame('timeout', $runner->outcomes()['hung']['status']);
            $this->assertSame(['status' => 'success', 'exit_code' => 0], $runner->outcomes()['ok']);
        }
    }

    #[Test]
    public function a_failed_worker_logs_the_tail_of_its_output(): void
    {
        $log = Log::spy();
        $runner = new BaseRunnerTestDouble;
        try {
            $runner->pool(['failed' => [PHP_BINARY, '-r', 'fwrite(STDERR, "Cannot connect to server: too many connections"); exit(1);'], 'ok' => [PHP_BINARY, '-r', 'echo "fine";']], 2, 3);
            $this->fail('Expected a failed batch.');
        } catch (RuntimeException) {
            $log->shouldHaveReceived('error')->once()->with(
                'Multiprocessing worker did not succeed',
                \Mockery::on(static fn (array $context): bool => $context['worker'] === 'failed'
                    && str_contains($context['output_tail'] ?? '', 'too many connections')),
            );
        }
    }

    #[Test]
    public function a_failed_workers_stderr_survives_verbose_stdout(): void
    {
        $log = Log::spy();
        $runner = new BaseRunnerTestDouble;
        try {
            $runner->pool(['failed' => [PHP_BINARY, '-r', 'fwrite(STDERR, "Cannot connect: too many connections"); echo str_repeat("progress line\n", 200); exit(1);']], 1, 3);
            $this->fail('Expected a failed batch.');
        } catch (RuntimeException) {
            $log->shouldHaveReceived('error')->once()->with(
                'Multiprocessing worker did not succeed',
                \Mockery::on(static fn (array $context): bool => str_contains($context['output_tail'] ?? '', 'stderr: Cannot connect: too many connections')
                    && str_contains($context['output_tail'], 'stdout: ')),
            );
        }
    }

    #[Test]
    public function asynchronous_pool_reports_nonzero_exit_codes(): void
    {
        $runner = new BaseRunnerTestDouble;
        try {
            $runner->pool(['failed' => [PHP_BINARY, '-r', 'exit(7);']], 1, 3);
            $this->fail('Expected a failed batch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed failure (exit 7)', $exception->getMessage());
            $this->assertSame(['status' => 'failure', 'exit_code' => 7], $runner->outcomes()['failed']);
        }
    }

    #[Test]
    public function streaming_output_is_emitted_exactly_once(): void
    {
        $runner = new BaseRunnerTestDouble;
        ob_start();
        try {
            $runner->stream([[PHP_BINARY, '-r', 'echo "unique stdout"; fwrite(STDERR, "unique stderr");']], 1);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame(1, substr_count($output, 'unique stdout'));
        $this->assertSame(1, substr_count($output, 'unique stderr'));
    }

    #[Test]
    public function cancellation_stops_owned_workers_and_does_not_launch_queued_jobs(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }
        $runner = new BaseRunnerTestDouble;
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-cancel-');
        $this->assertNotFalse($marker);
        $started = microtime(true);
        try {
            $runner->pool([
                'signal' => [PHP_BINARY, '-r', 'usleep(100000); posix_kill(posix_getppid(), SIGTERM); sleep(5);'],
                'owned' => [PHP_BINARY, '-r', 'sleep(5); file_put_contents($argv[1], "orphan");', $marker],
                'queued' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "queued");', $marker],
            ], 2, 10);
            $this->fail('Expected cancellation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cancellation', $exception->getMessage());
            $this->assertSame('', file_get_contents($marker));
            $this->assertSame('cancellation', $runner->outcomes()['queued']['status']);
            $this->assertLessThan(4, microtime(true) - $started);
        } finally {
            unlink($marker);
        }
    }

    #[Test]
    public function cooperative_drain_finishes_active_workers_and_leaves_queued_work_unstarted(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }
        $runner = new BaseRunnerTestDouble;
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-drain-');
        $this->assertNotFalse($marker);
        try {
            $results = $runner->pool([
                'active' => [PHP_BINARY, '-r', 'usleep(100000); posix_kill(posix_getppid(), SIGUSR1); usleep(200000); echo "completed"; file_put_contents($argv[1], "active");', $marker],
                'queued' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "queued");', $marker],
            ], 1, 5);
            $this->assertSame(['active' => 'completed'], $results);
            $this->assertSame('active', file_get_contents($marker));
            $this->assertSame('drained', $runner->outcomes()['queued']['status']);
            $this->assertSame(['active'], $runner->started);
            $this->assertSame(['active' => 'completed'], $runner->completed);
            $this->assertTrue($runner->draining());
        } finally {
            unlink($marker);
        }
    }

    #[Test]
    #[DataProvider('drainSources')]
    public function cooperative_drain_allows_a_resident_worker_to_finish_past_its_ordinary_timeout(string $source): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        $runner = new BaseRunnerTestDouble;
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-drain-deadline-');
        $this->assertNotFalse($marker);
        $observed = false;
        if ($source !== 'signal') {
            DB::listen(function (QueryExecuted $query) use ($source, $marker, &$observed): void {
                if ($observed || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'processing_runtime_states')
                    || file_get_contents($marker) !== 'drain') {
                    return;
                }
                $observed = true;
                if ($source === 'database-failure') {
                    throw new RuntimeException('Runtime drain lookup unavailable at the deadline.');
                }
                app(ProcessingRuntimeStateRepository::class)->requestStop();
            });
        }
        $failure = null;
        try {
            try {
                $runner->pool([
                    'active' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "resident"); usleep(750000); file_put_contents($argv[1], "drain"); if ($argv[2] === "signal") { posix_kill(posix_getppid(), SIGUSR1); } usleep(600000); file_put_contents($argv[1], "committed"); echo "completed";', $marker, $source],
                    'queued' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "queued");', $marker],
                ], 1, 1);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }

            $this->assertSame('committed', file_get_contents($marker));
            $this->assertSame(['active' => 'completed'], $runner->completed);
            $this->assertSame(['status' => 'success', 'exit_code' => 0], $runner->outcomes()['active']);
            $this->assertSame('drained', $runner->outcomes()['queued']['status']);
            $this->assertSame(['active'], $runner->started);
            if ($source === 'database-failure') {
                $this->assertNotNull($failure);
                $this->assertStringContainsString('Runtime drain lookup unavailable at the deadline', $failure->getMessage());
            } else {
                $this->assertNull($failure);
            }
            if ($source !== 'signal') {
                $this->assertTrue($observed);
            }
        } finally {
            unlink($marker);
        }
    }

    /** @return array<string, array{string}> */
    public static function drainSources(): array
    {
        return ['signal' => ['signal'], 'database stop' => ['database-stop'], 'database uncertainty' => ['database-failure']];
    }

    #[Test]
    public function uncertain_drain_state_stops_admission_but_finishes_an_already_resident_worker(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        $runner = new BaseRunnerTestDouble;
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-drain-db-error-');
        $this->assertNotFalse($marker);
        $residentObserved = false;
        DB::listen(function (QueryExecuted $query) use ($runner, $marker, &$residentObserved): void {
            if ($residentObserved || $runner->started !== ['active'] || ! str_starts_with($query->sql, 'select')
                || ! str_contains($query->sql, 'processing_runtime_states')) {
                return;
            }
            $deadline = microtime(true) + 2;
            do {
                if (file_get_contents($marker) === 'resident') {
                    $residentObserved = true;
                    throw new RuntimeException('Runtime drain lookup unavailable during resident work.');
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
        });
        $failure = null;
        try {
            try {
                $runner->pool([
                    'active' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "resident"); usleep(500000); file_put_contents($argv[1], "committed"); echo "completed";', $marker],
                    'queued' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "queued");', $marker],
                ], 1, 5);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }

            $this->assertTrue($residentObserved, 'The lookup must fail while a real admitted child is resident.');
            $this->assertSame('committed', file_get_contents($marker));
            $this->assertSame(['active' => 'completed'], $runner->completed);
            $this->assertSame(['active'], $runner->started);
            $this->assertSame(['status' => 'success', 'exit_code' => 0], $runner->outcomes()['active']);
            $this->assertSame('drained', $runner->outcomes()['queued']['status']);
            $this->assertNotNull($failure, 'Drain-state uncertainty must be surfaced after admitted work finishes.');
            $this->assertStringContainsString('Runtime drain lookup unavailable', $failure->getMessage());
        } finally {
            unlink($marker);
        }
    }

    #[Test]
    #[DataProvider('completionHooks')]
    public function uncertain_drain_and_completion_errors_remain_visible_after_all_resident_workers_finish(string $hook, int $slowExit): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'nntmux.concurrency_timeout' => 5]);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        $runner = new class($hook) extends BaseRunnerTestDouble
        {
            public function __construct(private string $hook) {}

            protected function workerCompleted(string|int $key, string $output): void
            {
                parent::workerCompleted($key, $output);
                if ($this->hook === 'workerCompleted') {
                    DB::table('processing_runtime_states')->value('stop_requested');
                }
            }
        };
        $fast = tempnam(sys_get_temp_dir(), 'nntmux-drain-fast-');
        $slow = tempnam(sys_get_temp_dir(), 'nntmux-drain-slow-');
        $release = tempnam(sys_get_temp_dir(), 'nntmux-drain-release-');
        $this->assertNotFalse($fast);
        $this->assertNotFalse($slow);
        $this->assertNotFalse($release);
        $outage = false;
        DB::listen(function (QueryExecuted $query) use ($runner, $fast, $slow, $release, &$outage): void {
            if ($outage || $runner->started !== ['fast', 'slow'] || ! str_starts_with($query->sql, 'select')
                || ! str_contains($query->sql, 'processing_runtime_states')) {
                return;
            }
            $deadline = microtime(true) + 2;
            do {
                if (file_get_contents($fast) === 'resident' && file_get_contents($slow) === 'resident') {
                    $outage = true;
                    DB::statement('DROP TABLE processing_runtime_states');
                    file_put_contents($release, 'released');
                    try {
                        DB::table('processing_runtime_states')->value('stop_requested');
                    } catch (\Throwable $exception) {
                        throw new RuntimeException('Runtime lookup failed with resident children.', 0, $exception);
                    }
                }
                usleep(1000);
            } while (! $outage && microtime(true) < $deadline);
        });
        $worker = static fn (string $marker, int $delay, int $exit): array => [PHP_BINARY, '-r',
            'file_put_contents($argv[1], "resident"); $deadline = microtime(true) + 3; while (file_get_contents($argv[2]) !== "released") { if (microtime(true) >= $deadline) { exit(3); } usleep(1000); } usleep((int) $argv[3]); file_put_contents($argv[1], "committed"); echo "completed"; exit((int) $argv[4]);',
            $marker, $release, (string) $delay, (string) $exit];
        $failure = null;
        try {
            try {
                $runner->poolWithCompletion([
                    'fast' => $worker($fast, 100000, 0),
                    'slow' => $worker($slow, 600000, $slowExit),
                    'queued' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "queued");', $release],
                ], 2, static function (string|int $key, string $output) use ($hook): void {
                    if ($hook === 'onComplete') {
                        DB::table('processing_runtime_states')->value('stop_requested');
                    }
                });
            } catch (\Throwable $exception) {
                $failure = $exception;
            }

            $this->assertTrue($outage, 'The database must become unavailable while two admitted children are resident.');
            $this->assertSame('committed', file_get_contents($fast));
            $this->assertSame('committed', file_get_contents($slow));
            $this->assertSame('released', file_get_contents($release));
            $this->assertSame(['fast', 'slow'], $runner->started);
            $this->assertSame(['status' => $slowExit === 0 ? 'success' : 'failure', 'exit_code' => $slowExit], $runner->outcomes()['slow']);
            $this->assertSame('drained', $runner->outcomes()['queued']['status']);
            $this->assertNotNull($failure, 'The database and completion failure must remain visible after both workers finish.');
            $this->assertStringContainsString('Runtime lookup failed with resident children.', $failure->getMessage());
            $this->assertNotNull($failure->getPrevious(), 'The deferred exception must remain available as a cause.');
            if ($slowExit !== 0) {
                $this->assertStringContainsString('slow failure (exit 7)', $failure->getMessage());
            }
            if ($hook !== 'none') {
                $this->assertStringContainsString('Worker completion failed', $failure->getMessage());
                $this->assertStringContainsString('processing_runtime_states', $failure->getMessage());
            }
        } finally {
            unlink($fast);
            unlink($slow);
            unlink($release);
        }
    }

    /** @return array<string, array{string, int}> */
    public static function completionHooks(): array
    {
        return [
            'completion callback' => ['onComplete', 0],
            'worker hook' => ['workerCompleted', 0],
            'failed child and drain lookup' => ['none', 7],
            'failed child and completion callback' => ['onComplete', 7],
            'failed child and worker hook' => ['workerCompleted', 7],
        ];
    }

    #[Test]
    public function a_paused_pool_launches_nothing_and_reports_every_task_as_drained(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
        $runner = new BaseRunnerTestDouble;
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-paused-');
        $this->assertNotFalse($marker);
        try {
            $this->assertSame([], $runner->pool(['queued' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "started");', $marker]], 1, 5));
            $this->assertSame('', file_get_contents($marker));
            $this->assertSame([], $runner->started);
            $this->assertSame('drained', $runner->outcomes()['queued']['status']);
            $this->assertTrue($runner->draining());
        } finally {
            unlink($marker);
        }
    }

    #[Test]
    public function drain_signal_during_runtime_poll_cannot_be_overwritten_by_a_false_database_flag(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        $sent = false;
        DB::listen(function (QueryExecuted $query) use (&$sent): void {
            if (! $sent && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'processing_runtime_states')) {
                $sent = true;
                posix_kill(getmypid(), SIGUSR1);
            }
        });
        $runner = new BaseRunnerTestDouble;
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-drain-race-');
        $this->assertNotFalse($marker);
        try {
            $results = $runner->pool([
                'queued' => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "started");', $marker],
            ], 1, 5);

            $this->assertTrue($sent);
            $this->assertSame([], $results);
            $this->assertSame('', file_get_contents($marker));
            $this->assertSame([], $runner->started);
            $this->assertSame('drained', $runner->outcomes()['queued']['status']);
            $this->assertTrue($runner->draining());
        } finally {
            unlink($marker);
        }
    }

    #[Test]
    public function shared_group_workers_are_serialized_while_other_groups_fill_free_slots(): void
    {
        $runner = new class extends BaseRunnerTestDouble
        {
            protected function workerConcurrencyKey(string|int $key): string
            {
                return explode(':', (string) $key)[0];
            }
        };
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-groups-');
        $this->assertNotFalse($marker);
        $barrier = tempnam(sys_get_temp_dir(), 'nntmux-group-barrier-');
        $this->assertNotFalse($barrier);
        $worker = <<<'PHP'
            file_put_contents($argv[1], "start:".$argv[2]."\n", FILE_APPEND | LOCK_EX);
            if ($argv[2] === 'a:1') {
                $deadline = microtime(true) + 3;
                while (file_get_contents($argv[3]) !== 'c-started') {
                    if (microtime(true) >= $deadline) {
                        exit(4);
                    }
                    usleep(1000);
                }
            } elseif ($argv[2] === 'c:1') {
                file_put_contents($argv[3], 'c-started');
            }
            file_put_contents($argv[1], "finish:".$argv[2]."\n", FILE_APPEND | LOCK_EX);
            PHP;
        $command = static fn (string $name): array => [PHP_BINARY, '-r', $worker, $marker, $name, $barrier];
        try {
            $runner->pool(['a:1' => $command('a:1'), 'a:2' => $command('a:2'), 'b:1' => $command('b:1'), 'c:1' => $command('c:1')], 2, 5);
            $events = file_get_contents($marker);
            $this->assertLessThan(strpos($events, 'start:a:2'), strpos($events, 'finish:a:1'));
            $this->assertLessThan(strpos($events, 'finish:a:1'), strpos($events, 'start:c:1'));
            $this->assertSame(['a:1', 'b:1', 'c:1', 'a:2'], $runner->started);
        } finally {
            unlink($marker);
            unlink($barrier);
        }
    }

    #[Test]
    public function legacy_worker_commands_preserve_arguments_without_shell_interpolation(): void
    {
        $group = 'alt.test; literal "quote" $(literal)';
        $this->assertSame([PHP_BINARY, base_path('artisan'), 'backfill:group', $group, '1'],
            (new BaseRunnerTestDouble)->buildDnrCommandPublic('backfill  '.$group.'  1'));
    }

    #[Test]
    public function concurrency_timeout_prefers_concurrency_timeout_config(): void
    {
        config(['nntmux.concurrency_timeout' => 60]);
        config(['nntmux.multiprocessing_max_child_time' => 42]);

        $this->assertSame(60, (new BaseRunnerTestDouble)->timeout());
    }

    #[Test]
    public function concurrency_timeout_falls_back_to_multiprocessing_max_child_time(): void
    {
        config(['nntmux.concurrency_timeout' => null]);
        config(['nntmux.multiprocessing_max_child_time' => 42]);

        $this->assertSame(42, (new BaseRunnerTestDouble)->timeout());
    }
}

class BaseRunnerTestDouble extends BaseRunner
{
    /** @var list<string|int> */
    public array $started = [];

    /** @var array<string|int, string> */
    public array $completed = [];

    protected function workerStarting(string|int $key): void
    {
        $this->started[] = $key;
    }

    protected function workerCompleted(string|int $key, string $output): void
    {
        $this->completed[$key] = $output;
    }

    public function draining(): bool
    {
        return $this->hasDrainRequest();
    }

    public function runCommand(string $command): string
    {
        return $this->executeCommand($command);
    }

    /** @param array<string|int, string|list<string>> $commands
     * @return array<string|int, string>
     */
    public function pool(array $commands, int $limit, int $timeout): array
    {
        return $this->runParallelCommands($commands, $limit, $timeout);
    }

    /**
     * @param  array<string|int, string|list<string>>  $commands
     * @param  callable(string|int, string): void  $onComplete
     */
    public function poolWithCompletion(array $commands, int $limit, callable $onComplete): void
    {
        $this->runParallelCommandsWithCompletion($commands, $limit, $onComplete);
    }

    /** @return array<string|int, array{status: string, exit_code: ?int}> */
    public function outcomes(): array
    {
        return $this->workerOutcomes;
    }

    /** @param array<string|int, string|list<string>> $commands */
    public function stream(array $commands, int $limit): void
    {
        $this->runStreamingCommands($commands, $limit, 'test');
    }

    public function timeout(): int
    {
        return $this->concurrencyTimeout();
    }
}
