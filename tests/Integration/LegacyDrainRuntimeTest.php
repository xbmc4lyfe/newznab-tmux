<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Isolated, opt-in compatibility check against the real pre-drain source revision. */
class LegacyDrainRuntimeTest extends TestCase
{
    private string $container;

    protected function setUp(): void
    {
        $image = getenv('NNTMUX_LEGACY_TEST_IMAGE');
        if (! is_string($image) || $image === '') {
            $this->markTestSkipped('Set NNTMUX_LEGACY_TEST_IMAGE to a local PHP/tmux image with locked production vendor dependencies.');
        }
        $this->container = 'nntmux-legacy-test-'.bin2hex(random_bytes(6));
        (new Process([
            'docker', 'run', '-d', '--user', '0', '--name', $this->container, '--network', 'none', '--memory', '512m', '--cpus', '2',
            '-e', 'APP_ENV=testing', '-e', 'DB_CONNECTION=sqlite', '-e', 'DB_DATABASE=/tmp/legacy.sqlite',
            '-e', 'CACHE_STORE=array', '-e', 'LOG_CHANNEL=stderr', '--entrypoint', 'sleep', $image, '600',
        ]))->mustRun();
        $archive = new Process(['git', 'archive', '425681f79', 'app', 'bootstrap', 'config', 'artisan', 'routes'], dirname(__DIR__, 2));
        $archive->mustRun();
        $this->exec(['sh', '-c', 'rm -f /app/.env; rm -rf /app/app /app/bootstrap /app/config /app/routes /app/artisan'])->mustRun();
        $this->exec(['tar', '-xf', '-', '-C', '/app'], $archive->getOutput())->mustRun();
        $this->exec(['mkdir', '-p', '/app/bootstrap/cache'])->mustRun();
        (new Process(['docker', 'cp', __DIR__.'/../Fixtures/legacy-drain/runtime.php', $this->container.':/tmp/runtime.php']))->mustRun();
        $this->exec(['touch', '/tmp/legacy.sqlite'])->mustRun();
        $help = $this->exec(['php', 'artisan', 'list', '--raw']);
        $help->mustRun();
        $this->assertStringNotContainsString('tmux:drain', $help->getOutput());
    }

    protected function tearDown(): void
    {
        if (isset($this->container)) {
            (new Process(['docker', 'rm', '-f', $this->container]))->run();
        }
    }

    public function test_deployment_abort_and_repeated_failure_preserve_an_operator_pause(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '0', 'irc_scraper', '0'])->mustRun();
        $this->drain(10)->mustRun();
        $this->drain(0, 'deployment')->mustRun();
        $this->resume('deployment')->mustRun();
        $this->assertFalse($this->state()['running']);
        $this->assertTrue($this->state()['stopped']);
        $this->exec(['tmux', 'split-window', '-d', '-t', 'nntmux:0', 'sleep', '30'])->mustRun();
        $failed = $this->drain(0, 'rejected');
        $failed->run();
        $this->assertFalse($failed->isSuccessful());
        $this->resume('rejected')->mustRun();
        $this->assertFalse($this->state()['running']);
        $this->assertTrue($this->state()['stopped']);
        $this->resume()->mustRun();
        $this->assertTrue($this->state()['running']);
        $this->assertFalse($this->state()['stopped']);
    }

    public function test_only_the_accepted_legacy_drain_owner_can_restore_running_state(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '0', 'irc_scraper', '0'])->mustRun();
        $this->resume('never-accepted')->mustRun();
        $this->assertTrue($this->state()['running']);
        $this->drain(10, 'first')->mustRun();
        $this->resume('wrong')->mustRun();
        $this->assertFalse($this->state()['running']);
        $this->resume('first')->mustRun();
        $this->assertTrue($this->state()['running']);
        $this->assertFalse($this->state()['stopped']);
        $this->drain(10, 'second')->mustRun();
        $this->resume('first')->mustRun();
        $this->assertFalse($this->state()['running']);
        $this->resume('second')->mustRun();
        $this->assertTrue($this->state()['running']);
    }

    public function test_concurrent_legacy_controls_cannot_replace_the_owner_or_restore_its_pause(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '5', 'irc_scraper', '0'])->mustRun();
        $owner = $this->drain(15, 'owner');
        $owner->start();
        $this->awaitMonitorStopping();
        $rejected = $this->drain(0, 'rejected');
        $rejected->run();
        $this->assertFalse($rejected->isSuccessful());
        $this->assertStringContainsString('Another drain control operation is active', $rejected->getErrorOutput());
        $restore = $this->resume('owner');
        $restore->run();
        $this->assertFalse($restore->isSuccessful());
        $this->assertFalse($this->state()['running']);
        $owner->wait();
        $this->assertTrue($owner->isSuccessful(), $owner->getErrorOutput());
        $this->resume('rejected')->mustRun();
        $this->assertFalse($this->state()['running']);
        $this->resume('owner')->mustRun();
        $this->assertTrue($this->state()['running']);
        $this->assertSame(1, $this->state()['committed']);
    }

    public function test_operator_pause_revokes_legacy_owner_and_incomplete_snapshots_fail_closed(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '0', 'irc_scraper', '0'])->mustRun();
        $this->drain(10, 'deployment')->mustRun();
        $this->drain(0)->mustRun();
        $this->resume('deployment')->mustRun();
        $this->assertFalse($this->state()['running']);
        $this->resume()->mustRun();
        $this->drain(10, 'next')->mustRun();
        $this->exec(['tmux', 'set-option', '-u', '-t', 'nntmux', '@nntmux_legacy_running'])->mustRun();
        $resume = $this->resume('next');
        $resume->run();
        $this->assertFalse($resume->isSuccessful());
        $this->assertStringContainsString('Missing legacy pre-drain state', $resume->getErrorOutput());
        $this->assertFalse($this->state()['running']);
        $this->assertTrue($this->state()['stopped']);
    }

    public function test_old_wrapper_finishes_its_transaction_before_legacy_irc_disconnect(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '3', 'irc_scraper'])->mustRun();
        $this->drain(15)->mustRun();
        $state = $this->state();
        $this->assertFalse($state['running']);
        $this->assertTrue($state['stopped']);
        $this->assertSame(1, $state['committed']);
        $roles = array_column($state['panes'], 'dead', 'role');
        $this->assertTrue($roles['monitor']);
        $this->assertTrue($roles['binaries']);
        $this->assertFalse($roles['irc_scraper']);
        // The authorized one-time IRC disconnect is after the finite transaction.
        $this->exec(['php', 'artisan', 'tmux:stop', '--session=nntmux', '--force', '--no-interaction'])->mustRun();
        $probe = $this->exec(['tmux', 'has-session', '-t', '=nntmux']);
        $probe->run();
        $this->assertFalse($probe->isSuccessful());
    }

    public function test_timeout_preserves_active_old_work_and_restores_flags(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '5', 'irc_scraper'])->mustRun();
        $drain = $this->drain(0);
        $drain->run();
        $this->assertFalse($drain->isSuccessful());
        $state = $this->state();
        $this->assertTrue($state['running']);
        $this->assertFalse($state['stopped']);
        $this->assertSame(0, $state['committed']);
        $this->assertFalse(array_column($state['panes'], 'dead', 'role')['binaries']);
        $this->exec(['sleep', '5'])->mustRun();
        $this->assertSame(1, $this->state()['committed']);
    }

    public function test_interrupt_restores_flags_without_signalling_active_old_workers(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '5', 'irc_scraper'])->mustRun();
        $drain = $this->drain(30);
        $drain->start();
        $deadline = microtime(true) + 5;
        while (! $this->state()['stopped'] && microtime(true) < $deadline) {
            usleep(100000);
        }
        $this->assertTrue($this->state()['stopped']);
        $pid = $this->exec(['pgrep', '-f', '^php -- /app drain 30$']);
        $pid->mustRun();
        $this->exec(['kill', '-TERM', trim($pid->getOutput())])->mustRun();
        $drain->wait();
        $this->assertFalse($drain->isSuccessful());
        $state = $this->state();
        $this->assertTrue($state['running']);
        $this->assertFalse($state['stopped']);
        $this->assertFalse(array_column($state['panes'], 'dead', 'role')['binaries']);
        $this->exec(['sleep', '5'])->mustRun();
        $this->assertSame(1, $this->state()['committed']);
    }

    public function test_recovery_waits_for_a_monitor_that_has_already_observed_stop(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '5', 'irc_scraper', '2'])->mustRun();
        $drain = $this->drain(30);
        $drain->start();
        $this->awaitMonitorStopping();
        $pid = $this->exec(['pgrep', '-f', '^php -- /app drain 30$']);
        $pid->mustRun();
        $this->exec(['kill', '-TERM', trim($pid->getOutput())])->mustRun();
        $drain->wait();
        $this->assertFalse($drain->isSuccessful());
        $this->exec(['sleep', '3'])->mustRun();
        $state = $this->state();
        $this->assertTrue($state['running']);
        $this->assertFalse($state['stopped']);
        $this->assertFalse(array_column($state['panes'], 'dead', 'role')['monitor']);
        // An idempotent resume must not wait for the healthy replacement to exit.
        $resume = $this->exec(['php', '--', '/app', 'resume'], file_get_contents(__DIR__.'/../../docker/bin/legacy-drain.php'));
        $resume->setTimeout(3);
        $resume->mustRun();
        $this->assertFalse(array_column($this->state()['panes'], 'dead', 'role')['monitor']);
    }

    public function test_recovery_timeout_leaves_dispatch_paused_and_finite_work_intact(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '5', 'irc_scraper', '30'])->mustRun();
        $drain = $this->drain(30);
        $drain->start();
        $this->awaitMonitorStopping();
        $pid = $this->exec(['pgrep', '-f', '^php -- /app drain 30$']);
        $pid->mustRun();
        $this->exec(['kill', '-TERM', trim($pid->getOutput())])->mustRun();
        $drain->wait();
        $this->assertFalse($drain->isSuccessful());
        $this->assertStringContainsString('Legacy monitor did not finish within 15 seconds; scheduling remains paused', $drain->getErrorOutput());
        $state = $this->state();
        $this->assertFalse($state['running']);
        $this->assertTrue($state['stopped']);
        $this->assertSame(1, $state['committed']);
        $this->assertFalse(array_column($state['panes'], 'dead', 'role')['monitor']);
    }

    private function awaitMonitorStopping(): void
    {
        $deadline = microtime(true) + 5;
        do {
            $probe = $this->exec(['test', '-f', '/tmp/monitor-stopping']);
            $probe->run();
            if ($probe->isSuccessful()) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        $this->fail('The fixture monitor did not observe the stop request.');
    }

    public function test_unknown_active_pane_blocks_even_after_finite_work_finishes(): void
    {
        $this->exec(['php', '/tmp/runtime.php', 'init', '0', 'unclassified'])->mustRun();
        $drain = $this->drain(1);
        $drain->run();
        $this->assertFalse($drain->isSuccessful());
        $this->assertStringContainsString('Blocking roles: unclassified', $drain->getErrorOutput());
        $state = $this->state();
        $this->assertTrue($state['running']);
        $this->assertFalse(array_column($state['panes'], 'dead', 'role')['unclassified']);
        $this->assertSame(1, $state['committed']);
    }

    /** @param list<string> $command */
    private function exec(array $command, ?string $input = null): Process
    {
        return new Process(['docker', 'exec', '-i', $this->container, ...$command], null, null, $input, 60);
    }

    private function drain(int $timeout, ?string $token = null): Process
    {
        return $this->exec(['php', '--', '/app', 'drain', (string) $timeout, ...($token === null ? [] : [$token])], file_get_contents(__DIR__.'/../../docker/bin/legacy-drain.php'));
    }

    private function resume(?string $token = null): Process
    {
        return $this->exec(['php', '--', '/app', 'resume', '0', ...($token === null ? [] : [$token])], file_get_contents(__DIR__.'/../../docker/bin/legacy-drain.php'));
    }

    /** @return array{running: bool, stopped: bool, committed: int, panes: array<string, array{role: string, dead: bool}>} */
    private function state(): array
    {
        $process = $this->exec(['php', '/tmp/runtime.php', 'state']);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
