<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Runners;

use App\Services\Runners\BaseRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        Log::spy();
        $runner = new BaseRunnerTestDouble;
        try {
            $runner->pool(['failed' => [PHP_BINARY, '-r', 'fwrite(STDERR, "Cannot connect to server: too many connections"); exit(1);'], 'ok' => [PHP_BINARY, '-r', 'echo "fine";']], 2, 3);
            $this->fail('Expected a failed batch.');
        } catch (RuntimeException) {
            Log::shouldHaveReceived('error')->once()->with(
                'Multiprocessing worker did not succeed',
                \Mockery::on(static fn (array $context): bool => $context['worker'] === 'failed'
                    && str_contains($context['output_tail'] ?? '', 'too many connections')),
            );
        }
    }

    #[Test]
    public function a_failed_workers_stderr_survives_verbose_stdout(): void
    {
        Log::spy();
        $runner = new BaseRunnerTestDouble;
        try {
            $runner->pool(['failed' => [PHP_BINARY, '-r', 'fwrite(STDERR, "Cannot connect: too many connections"); echo str_repeat("progress line\n", 200); exit(1);']], 1, 3);
            $this->fail('Expected a failed batch.');
        } catch (RuntimeException) {
            Log::shouldHaveReceived('error')->once()->with(
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
            protected function workerConcurrencyKey(string|int $key): string|int|null
            {
                return explode(':', (string) $key)[0];
            }
        };
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-groups-');
        $this->assertNotFalse($marker);
        $command = static fn (string $name, int $delay): array => [PHP_BINARY, '-r', 'file_put_contents($argv[1], "start:".$argv[2]."\n", FILE_APPEND); usleep((int) $argv[3]); file_put_contents($argv[1], "finish:".$argv[2]."\n", FILE_APPEND);', $marker, $name, (string) $delay];
        try {
            $runner->pool(['a:1' => $command('a:1', 400000), 'a:2' => $command('a:2', 50000), 'b:1' => $command('b:1', 100000), 'c:1' => $command('c:1', 100000)], 2, 5);
            $events = file_get_contents($marker);
            $this->assertLessThan(strpos($events, 'start:a:2'), strpos($events, 'finish:a:1'));
            $this->assertLessThan(strpos($events, 'finish:a:1'), strpos($events, 'start:c:1'));
            $this->assertSame(['a:1', 'b:1', 'c:1', 'a:2'], $runner->started);
        } finally {
            unlink($marker);
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
