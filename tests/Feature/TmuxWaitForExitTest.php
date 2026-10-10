<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Tmux\TmuxPaneManager;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * A timed-out `tmux wait-for` client stays registered until the channel is signalled, so a wait must not
 * start a new waiter for every polling slice.
 */
class TmuxWaitForExitTest extends TestCase
{
    private int $waiters = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tmux.socket_name' => '']);
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if (in_array('wait-for', $process->command, true)) {
                $this->waiters++;

                // Never woken: the pane-died hook does not fire while every pane keeps running.
                return Process::describe()->iterations(10_000);
            }
            if (in_array('display-message', $process->command, true)) {
                return Process::result('$1');
            }

            return Process::result(implode("\t", ['%1', 'monitor', '0', '', '123', '0', '@1', '', '777']));
        });
    }

    public function test_a_wait_with_no_exits_starts_a_single_tmux_waiter(): void
    {
        $started = microtime(true);
        (new TmuxPaneManager('waiters'))->waitForExit(3);

        $this->assertGreaterThanOrEqual(2.9, microtime(true) - $started);
        $this->assertSame(1, $this->waiters);
    }
}
