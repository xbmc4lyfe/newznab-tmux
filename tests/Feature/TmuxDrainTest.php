<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\TmuxDrain;
use App\Enums\TmuxPaneRole;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class TmuxDrainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => true, 'stop_requested' => false]);
    }

    public function test_drain_waits_for_owned_workers_and_preserves_the_paused_session(): void
    {
        $lists = 0;
        Process::fake(function (PendingProcess $process) use (&$lists) {
            if (in_array('#{session_id}', $process->command, true)) {
                return Process::result('$1');
            }
            if (in_array('list-panes', $process->command, true)) {
                $dead = ++$lists > 1 ? '1' : '0';

                return Process::result("%2\t".TmuxPaneRole::Binaries->value."\t{$dead}\t0\t123\t0\t@1\t\t999\n%1\tmonitor\t{$dead}\t0\t456\t0\t@1\t\t999\n");
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=2')->assertExitCode(0);
        $this->assertGreaterThanOrEqual(2, $lists);
        $this->assertSame(1, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame(0, (int) DB::table('processing_runtime_states')->value('tmux_running'));
        Process::assertNotRan(fn (PendingProcess $process): bool => in_array('kill-session', $process->command, true)
            || in_array('send-keys', $process->command, true));
    }

    public function test_drain_timeout_restores_processing_without_interrupting_workers(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (in_array('#{session_id}', $process->command, true)) {
                return Process::result('$1');
            }
            if (in_array('list-panes', $process->command, true)) {
                return Process::result("%2\tbinaries\t0\t\t123\t0\t@1\t\t999\n");
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode(1);
        $this->assertSame(0, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame(1, (int) DB::table('processing_runtime_states')->value('tmux_running'));
        Process::assertNotRan(fn (PendingProcess $process): bool => in_array('kill-session', $process->command, true)
            || in_array('send-keys', $process->command, true));
    }

    #[DataProvider('blockingRoles')]
    public function test_live_unclassified_console_and_monitor_panes_block_drain(string $role): void
    {
        $lists = 0;
        Process::fake(function (PendingProcess $process) use ($role, &$lists) {
            if (in_array('#{session_id}', $process->command, true)) {
                return Process::result('$1');
            }
            if (in_array('list-panes', $process->command, true)) {
                $dead = $role === 'monitor' && ++$lists >= 3 ? '1' : '0';

                return Process::result("%2\t{$role}\t{$dead}\t0\t123\t0\t@1\t\t999\n");
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode(1);
        $this->assertSame(0, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame(1, (int) DB::table('processing_runtime_states')->value('tmux_running'));
        if ($role === 'monitor') {
            Process::assertRan(fn (PendingProcess $process): bool => in_array('respawn-pane', $process->command, true));
        } else {
            Process::assertNotRan(fn (PendingProcess $process): bool => in_array('respawn-pane', $process->command, true));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function blockingRoles(): iterable
    {
        yield 'untagged' => [''];
        yield 'unknown' => ['custom_writer'];
        yield 'console' => ['console'];
        yield 'monitor dispatch barrier' => ['monitor'];
        yield 'cooperative IRC' => ['irc_scraper'];
    }

    public function test_drain_allows_known_diagnostics_and_dead_console_or_unclassified_panes(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (in_array('#{session_id}', $process->command, true)) {
                return Process::result('$1');
            }
            if (in_array('list-panes', $process->command, true)) {
                return Process::result("%1\thtop\t0\t\t123\t0\t@1\t\t999\n%2\tnmon\t0\t\t124\t0\t@1\t\t999\n%3\tvnstat\t0\t\t125\t0\t@1\t\t999\n%4\ttcptrack\t0\t\t126\t0\t@1\t\t999\n%5\tbandwidth_monitor\t0\t\t127\t0\t@1\t\t999\n%6\tmytop\t0\t\t128\t0\t@1\t\t999\n%7\tredis\t0\t\t129\t0\t@1\t\t999\n%8\tconsole\t1\t0\t130\t0\t@1\t\t999\n%9\t\t1\t0\t131\t0\t@1\t\t999\n");
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode(0);
        $this->assertSame(1, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame(0, (int) DB::table('processing_runtime_states')->value('tmux_running'));
    }

    #[DataProvider('monitorRecoveryModes')]
    public function test_recovery_restarts_only_a_previously_live_exited_monitor(bool $timeout, bool $alive, bool $stopped): void
    {
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => ! $stopped, 'stop_requested' => $stopped]);
        $options = [];
        $monitorDead = ! $alive;
        Process::fake(function (PendingProcess $process) use (&$options, &$monitorDead, $timeout) {
            $arguments = $process->command;
            if (in_array('#{session_id}', $arguments, true)) {
                return Process::result('$1');
            }
            if (in_array('set-option', $arguments, true)) {
                $key = $arguments[count($arguments) - 2];
                $options[$key] = $arguments[array_key_last($arguments)];
                if ($key === '@nntmux_draining' && $options[$key] === '1') {
                    $monitorDead = true;
                }
            }
            if (in_array('show-options', $arguments, true)) {
                return Process::result(($options[$arguments[array_key_last($arguments)]] ?? '')."\n");
            }
            if (in_array('list-panes', $arguments, true)) {
                $dead = $monitorDead ? '1' : '0';
                $worker = $timeout ? "%2\tbinaries\t0\t\t123\t0\t@1\t\t999\n" : '';

                return Process::result("%1\tmonitor\t{$dead}\t0\t456\t0\t@1\t\t999\n".$worker);
            }
            if (in_array('respawn-pane', $arguments, true)) {
                $monitorDead = false;
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode($timeout ? 1 : 0);
        if (! $timeout) {
            $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode(0);
            $this->artisan('tmux:drain --session=nntmux --resume')->assertExitCode(0);
            $this->artisan('tmux:drain --session=nntmux --resume')->assertExitCode(0);
        }
        $this->assertSame(! $alive || $stopped, $monitorDead);
        $this->assertSame((int) $stopped, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame((int) ! $stopped, (int) DB::table('processing_runtime_states')->value('tmux_running'));
        Process::assertNotRan(fn (PendingProcess $process): bool => in_array('-k', $process->command, true)
            || in_array('send-keys', $process->command, true));
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function monitorRecoveryModes(): iterable
    {
        yield 'resume previously live' => [false, true, false];
        yield 'timeout previously live' => [true, true, false];
        yield 'resume never started' => [false, false, false];
        yield 'timeout never started' => [true, false, false];
        yield 'resume prior stop request' => [false, true, true];
        yield 'timeout prior stop request' => [true, true, true];
    }

    public function test_timeout_recovery_waits_for_a_monitor_already_committed_to_exit(): void
    {
        $lists = 0;
        $recoveryStopFlags = [];
        $restarted = false;
        Process::fake(function (PendingProcess $process) use (&$lists, &$recoveryStopFlags, &$restarted) {
            if (in_array('#{session_id}', $process->command, true)) {
                return Process::result('$1');
            }
            if (in_array('list-panes', $process->command, true)) {
                $lists++;
                if ($lists >= 3) {
                    $recoveryStopFlags[] = (int) DB::table('processing_runtime_states')->value('stop_requested');
                }
                $dead = $lists >= 4 ? '1' : '0';

                return Process::result("%1\tmonitor\t{$dead}\t0\t456\t0\t@1\t\t999\n");
            }
            if (in_array('respawn-pane', $process->command, true)) {
                $restarted = true;
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode(1);
        $this->assertSame([1, 1], $recoveryStopFlags);
        $this->assertTrue($restarted);
        $this->assertSame(0, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame(1, (int) DB::table('processing_runtime_states')->value('tmux_running'));
    }

    public function test_monitor_recovery_timeout_leaves_scheduling_paused_for_explicit_resume(): void
    {
        $command = new class extends TmuxDrain
        {
            protected function monitorRecoveryTimeout(): int
            {
                return 0;
            }
        };
        Artisan::registerCommand($command);
        Process::fake(function (PendingProcess $process) {
            if (in_array('#{session_id}', $process->command, true)) {
                return Process::result('$1');
            }
            if (in_array('list-panes', $process->command, true)) {
                return Process::result("%1\tmonitor\t0\t\t456\t0\t@1\t\t999\n");
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=0')
            ->expectsOutputToContain('retry tmux:drain --resume')
            ->assertExitCode(1);
        $this->assertSame(1, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame(0, (int) DB::table('processing_runtime_states')->value('tmux_running'));
        Process::assertNotRan(fn (PendingProcess $process): bool => in_array('respawn-pane', $process->command, true)
            || in_array('send-keys', $process->command, true));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_signal_during_drain_restores_scheduling_without_stopping_workers(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are required.');
        }
        $original = pcntl_signal_get_handler(SIGTERM);
        $lists = 0;
        $restarted = false;
        Process::fake(function (PendingProcess $process) use (&$lists, &$restarted) {
            if (in_array('#{session_id}', $process->command, true)) {
                return Process::result('$1');
            }
            if (in_array('list-panes', $process->command, true)) {
                if (++$lists === 2) {
                    posix_kill(getmypid(), SIGTERM);
                }
                $dead = $lists > 1 ? '1' : '0';

                return Process::result("%2\tbinaries\t0\t\t123\t0\t@1\t\t999\n%1\tmonitor\t{$dead}\t0\t456\t0\t@1\t\t999\n");
            }
            if (in_array('respawn-pane', $process->command, true)) {
                $restarted = true;
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=10')->assertExitCode(1);
        $this->assertSame(0, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame(1, (int) DB::table('processing_runtime_states')->value('tmux_running'));
        $this->assertSame($original, pcntl_signal_get_handler(SIGTERM));
        $this->assertTrue($restarted);
        Process::assertNotRan(fn (PendingProcess $process): bool => in_array('kill-session', $process->command, true)
            || in_array('send-keys', $process->command, true));
    }

    #[DataProvider('originalRuntimeStates')]
    public function test_resume_restores_the_original_runtime_flags_after_repeated_drain(bool $running, bool $stopped): void
    {
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => $running, 'stop_requested' => $stopped]);
        $options = [];
        Process::fake(function (PendingProcess $process) use (&$options) {
            $arguments = $process->command;
            if (in_array('#{session_id}', $arguments, true)) {
                return Process::result('$1');
            }
            if (in_array('set-option', $arguments, true)) {
                $options[$arguments[count($arguments) - 2]] = $arguments[array_key_last($arguments)];
            }
            if (in_array('show-options', $arguments, true)) {
                return Process::result(($options[$arguments[array_key_last($arguments)]] ?? '')."\n");
            }

            return Process::result();
        });

        $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode(0);
        $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode(0);
        $exitCode = Artisan::call('tmux:drain', ['--session' => 'nntmux', '--resume' => true]);
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame((int) $stopped, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame((int) $running, (int) DB::table('processing_runtime_states')->value('tmux_running'));
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function originalRuntimeStates(): iterable
    {
        yield 'running' => [true, false];
        yield 'operator paused' => [false, false];
        yield 'stop requested' => [false, true];
    }

    #[DataProvider('originalRuntimeStates')]
    public function test_absent_session_drain_preserves_runtime_state(bool $running, bool $stopped): void
    {
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => $running, 'stop_requested' => $stopped]);
        Process::fake(fn (): mixed => Process::result(errorOutput: "can't find session: nntmux", exitCode: 1));

        $this->artisan('tmux:drain --session=nntmux --timeout=0')->assertExitCode(1);
        $this->assertSame((int) $stopped, (int) DB::table('processing_runtime_states')->value('stop_requested'));
        $this->assertSame((int) $running, (int) DB::table('processing_runtime_states')->value('tmux_running'));
    }
}
