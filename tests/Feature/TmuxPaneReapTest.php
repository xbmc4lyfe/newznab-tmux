<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Tmux\TmuxPaneManager;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Dead panes whose exit status tmux never recorded (lost SIGCHLD) must be reaped on request.
 */
class TmuxPaneReapTest extends TestCase
{
    /** @var list<string> */
    private array $listings = [];

    private int $listCalls = 0;

    /** @var list<int> */
    private array $reapRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['tmux.socket_name' => '']);
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) {
            if (in_array('display-message', $process->command, true)) {
                return Process::result('$1');
            }
            $listing = $this->listings[min($this->listCalls, count($this->listings) - 1)];
            $this->listCalls++;

            return Process::result($listing);
        });
    }

    public function test_asks_tmux_to_reap_a_dead_pane_without_an_exit_status_and_rereads_it(): void
    {
        $this->listings = [self::pane('%5', dead: true, pid: 4242), self::pane('%5', dead: true, status: '3', pid: 4242)];
        $manager = $this->manager();

        $state = $manager->paneSnapshot()['%5'];

        $this->assertSame(3, $state['exit_code']);
        $this->assertSame([777], $this->reapRequests);
        $this->assertSame(2, $this->listCalls);
    }

    public function test_asks_at_most_every_retry_interval_while_the_status_is_missing(): void
    {
        $this->listings = [self::pane('%5', dead: true, pid: 4242)];
        $manager = $this->manager();

        $this->assertNull($manager->paneSnapshot()['%5']['exit_code']);
        $manager->refresh();
        $this->assertNull($manager->paneSnapshot()['%5']['exit_code']);
        $this->assertSame([777], $this->reapRequests);

        // The first SIGCHLD can land in another libutempter window; ask again later.
        $this->travel(31)->seconds();
        $manager->refresh();
        $manager->paneSnapshot();
        $this->assertSame([777, 777], $this->reapRequests);
    }

    public function test_asks_again_once_the_pane_runs_a_new_process(): void
    {
        $this->listings = [self::pane('%5', dead: true, pid: 4242)];
        $manager = $this->manager();
        $manager->paneSnapshot();

        $this->listings = [self::pane('%5', dead: true, pid: 5151)];
        $this->listCalls = 0;
        $manager->refresh();
        $manager->paneSnapshot();

        $this->assertSame([777, 777], $this->reapRequests);
    }

    public function test_live_panes_and_recorded_exits_need_no_reaping(): void
    {
        $this->listings = [implode("\n", [
            self::pane('%1', dead: false, pid: 10),
            self::pane('%2', dead: true, status: '0', pid: 11),
            self::pane('%3', dead: true, signal: '15', pid: 12),
        ])];
        $manager = $this->manager();

        $snapshot = $manager->paneSnapshot();

        $this->assertSame([], $this->reapRequests);
        $this->assertSame(1, $this->listCalls);
        $this->assertSame(0, $snapshot['%2']['exit_code']);
        $this->assertNull($snapshot['%2']['exit_signal']);
        $this->assertNull($snapshot['%3']['exit_code']);
        $this->assertSame(15, $snapshot['%3']['exit_signal']);
        $this->assertFalse($snapshot['%1']['dead']);
    }

    public function test_reports_the_pane_unchanged_when_tmux_cannot_be_signalled(): void
    {
        $this->listings = [self::pane('%5', dead: true, pid: 4242), self::pane('%5', dead: true, status: '3', pid: 4242)];
        $manager = $this->manager(signalled: false);

        $this->assertNull($manager->paneSnapshot()['%5']['exit_code']);
        $this->assertSame(1, $this->listCalls);
    }

    public function test_parses_listings_from_tmux_without_the_newer_fields(): void
    {
        $this->listings = ["%7\tmonitor\t0\t\t99\t0\t@2"];

        $state = $this->manager()->paneSnapshot()['%7'];

        $this->assertSame(['role' => 'monitor', 'dead' => false, 'exit_code' => null, 'exit_signal' => null, 'pid' => 99, 'pipe' => false, 'window' => '@2'], $state);
    }

    public function test_only_signals_a_tmux_process(): void
    {
        $manager = new class('reap') extends TmuxPaneManager
        {
            public function signal(int $pid): bool
            {
                return $this->requestReap($pid);
            }
        };

        $this->assertFalse($manager->signal(0));
        $this->assertFalse($manager->signal(getmypid()), 'The test runner is not a tmux server.');
    }

    private static function pane(string $id, bool $dead, int $pid, string $status = '', string $signal = ''): string
    {
        return implode("\t", [$id, 'post_additional', $dead ? '1' : '0', $status, (string) $pid, '0', '@1', $signal, '777']);
    }

    private function manager(bool $signalled = true): TmuxPaneManager
    {
        $record = function (int $serverPid): void {
            $this->reapRequests[] = $serverPid;
        };

        return new class('reap', $record, $signalled) extends TmuxPaneManager
        {
            public function __construct(string $sessionName, private readonly \Closure $record, private readonly bool $signalled)
            {
                parent::__construct($sessionName);
            }

            protected function requestReap(int $serverPid): bool
            {
                ($this->record)($serverPid);

                return $this->signalled;
            }
        };
    }
}
