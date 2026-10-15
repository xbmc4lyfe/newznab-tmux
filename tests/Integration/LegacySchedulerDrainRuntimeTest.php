<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Opt-in real Laravel scheduling against the pre-drain app in an isolated namespace. */
class LegacySchedulerDrainRuntimeTest extends TestCase
{
    private string $container;

    private string $directory;

    protected function setUp(): void
    {
        $image = getenv('NNTMUX_LEGACY_TEST_IMAGE');
        if (! is_string($image) || $image === '') {
            $this->markTestSkipped('Set NNTMUX_LEGACY_TEST_IMAGE to a local app image with its locked vendor dependencies.');
        }
        $this->container = 'nntmux-legacy-scheduler-'.bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir().'/'.$this->container;
        mkdir($this->directory, 0777);
        chmod($this->directory, 0777);
        $owner = getenv('NNTMUX_LEGACY_TEST_OWNER') ?: $this->container;
        (new Process([
            'docker', 'run', '-d', '--init', '--user=33:33', '--name', $this->container, '--network=none', '--memory=512m', '--cpus=2',
            '--label', 'scheduler.stop.runtime.owner='.$owner,
            '--restart=unless-stopped', '--tmpfs=/var/tmp/nntmux:uid=33,gid=33,mode=0770', '-v', $this->directory.':/state',
            '-e', 'APP_ENV=testing', '-e', 'DB_CONNECTION=sqlite', '-e', 'DB_DATABASE=/tmp/legacy.sqlite',
            '-e', 'CACHE_STORE=array', '-e', 'LOG_CHANNEL=stderr', '--entrypoint=sh', $image,
            '-c', 'while [ ! -f /state/start ]; do sleep .05; done; exec php artisan schedule:work',
        ]))->mustRun();
        $archive = new Process(['git', 'archive', '425681f79', 'app', 'bootstrap', 'config', 'artisan', 'routes'], dirname(__DIR__, 2));
        $archive->mustRun();
        $this->rootExec(['sh', '-c', 'rm -f /app/.env; rm -rf /app/app /app/bootstrap /app/config /app/routes /app/artisan'])->mustRun();
        $this->rootExec(['tar', '-xf', '-', '-C', '/app'], $archive->getOutput())->mustRun();
        $this->rootExec(['mkdir', '-p', '/app/bootstrap/cache'])->mustRun();
        $this->rootExec(['chown', '33:33', '/app/bootstrap/cache'])->mustRun();
        $this->rootExec(['mv', '/app/artisan', '/app/artisan.original'])->mustRun();
        $this->copy(__DIR__.'/../Fixtures/legacy-scheduler/artisan.php', '/app/artisan');
        $this->copy(__DIR__.'/../Fixtures/legacy-scheduler/console.php', '/app/routes/console.php');
        $this->copy(__DIR__.'/../Fixtures/legacy-scheduler/worker.php', '/tmp/scheduled-worker.php');
        $this->rootExec(['php', '-r', <<<'INIT'
$db = new PDO('sqlite:/tmp/legacy.sqlite');
$db->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, name TEXT, value TEXT)');
$db->exec("INSERT INTO settings (name,value) VALUES ('categorizeforeign','0'),('catwebdl','0')");
chmod('/tmp/legacy.sqlite', 0666);
$db = new PDO('sqlite:/state/work.sqlite');
$db->exec('CREATE TABLE completed (id TEXT PRIMARY KEY)');
chmod('/state/work.sqlite', 0666);
INIT])->mustRun();
        touch($this->directory.'/start');
        $this->awaitCoordinator(false);
        $run = $this->exec(['php', 'artisan', 'schedule:run']);
        $run->run();
        $logs = new Process(['docker', 'logs', $this->container]);
        $logs->run();
        $this->assertSame(0, $run->getExitCode(), $run->getErrorOutput().$logs->getOutput().$logs->getErrorOutput());
        $this->awaitFile('worker.ready');
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            touch($this->directory.'/release');
            (new Process(['docker', 'rm', '-f', $this->container]))->run();
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    public function test_background_transactions_finish_before_the_frozen_coordinator_is_stopped(): void
    {
        $drain = $this->bridge('drain', 15);
        $drain->start();
        $this->awaitCoordinator(true);
        $this->assertTrue($drain->isRunning());
        $this->assertSame(0, $this->completed());
        touch($this->directory.'/release');
        $drain->wait();
        $this->assertSame(0, $drain->getExitCode(), $drain->getErrorOutput());
        $admitted = count(file($this->directory.'/admitted'));
        $this->assertSame($admitted, $this->completed());
        $this->bridge('check')->mustRun();
        (new Process(['docker', 'stop', '--time=1', $this->container]))->mustRun();
        usleep(250000);
        $state = new Process(['docker', 'inspect', '-f', '{{.State.Running}} {{.RestartCount}}', $this->container]);
        $state->mustRun();
        $this->assertSame("false 0\n", $state->getOutput());
        $this->assertCount($admitted, file($this->directory.'/admitted'));
    }

    public function test_unreaped_child_of_frozen_coordinator_does_not_block_a_completed_drain(): void
    {
        touch($this->directory.'/release');
        $this->bridge('drain', 10)->mustRun();
        $pid = trim(file_get_contents($this->directory.'/zombie-pid'));
        $state = $this->exec(['ps', '-o', 'stat=', '-p', $pid]);
        $state->mustRun();
        $this->assertStringStartsWith('Z', trim($state->getOutput()));
        $this->bridge('check')->mustRun();
        $this->assertSame(count(file($this->directory.'/admitted')), $this->completed());
    }

    public function test_timeout_restores_admission_and_preserves_detached_jobs(): void
    {
        $drain = $this->bridge('drain', 0);
        $drain->run();
        $this->assertSame(1, $drain->getExitCode(), $drain->getErrorOutput());
        $this->assertStringContainsString('timed out', $drain->getErrorOutput());
        $this->awaitCoordinator(false);
        $this->assertSame(0, $this->completed());
        touch($this->directory.'/release');
        $this->awaitCompletion();
    }

    public function test_resume_restores_running_state_after_a_successful_drain(): void
    {
        touch($this->directory.'/release');
        $this->bridge('drain', 10)->mustRun();
        $this->awaitCoordinator(true);
        $this->bridge('resume')->mustRun();
        $this->awaitCoordinator(false);
        $this->bridge('resume')->mustRun();
    }

    public function test_timeout_preserves_a_coordinator_that_was_already_stopped(): void
    {
        $this->exec(['pkill', '-STOP', '-f', '^php artisan schedule:work$'])->mustRun();
        $drain = $this->bridge('drain', 0);
        $drain->run();
        $this->assertSame(1, $drain->getExitCode(), $drain->getErrorOutput());
        $this->awaitCoordinator(true);
        touch($this->directory.'/release');
        $this->awaitCompletion();
    }

    public function test_interrupt_restores_admission_without_signalling_finite_work(): void
    {
        $drain = $this->bridge('drain', 20);
        $drain->start();
        $this->awaitCoordinator(true);
        $this->exec(['pkill', '-TERM', '-f', '^python3 - drain 20$'])->mustRun();
        $drain->wait();
        $this->assertSame(1, $drain->getExitCode(), $drain->getErrorOutput());
        $this->awaitCoordinator(false);
        touch($this->directory.'/release');
        $this->awaitCompletion();
    }

    public function test_unclassified_live_process_blocks_drain_in_the_dedicated_container(): void
    {
        touch($this->directory.'/release');
        $this->awaitCompletion();
        (new Process(['docker', 'exec', '-d', $this->container, 'sleep', '30']))->mustRun();
        $drain = $this->bridge('drain', 0);
        $drain->run();
        $this->assertSame(1, $drain->getExitCode(), $drain->getErrorOutput());
        $this->assertStringContainsString('active processes', $drain->getErrorOutput());
        $this->awaitCoordinator(false);
        $this->exec(['pgrep', '-x', 'sleep'])->mustRun();
    }

    public function test_resume_never_signals_a_pid_with_a_different_start_identity(): void
    {
        touch($this->directory.'/release');
        $this->bridge('drain', 10)->mustRun();
        $this->exec(['python3', '-c', <<<'PYTHON'
import json
from pathlib import Path
path = Path('/var/tmp/nntmux/legacy-scheduler-drain.json')
state = json.loads(path.read_text())
state['start'] = '0'
path.write_text(json.dumps(state))
PYTHON])->mustRun();
        $resume = $this->bridge('resume');
        $resume->run();
        $this->assertSame(1, $resume->getExitCode(), $resume->getErrorOutput());
        $this->assertStringContainsString('identity changed', $resume->getErrorOutput());
        $this->awaitCoordinator(true);
    }

    public function test_resume_preserves_an_external_stop_after_unconfirmed_stop_intent(): void
    {
        $this->assertUnconfirmedResumePreservesExternalStop('intent');
    }

    public function test_resume_preserves_an_external_stop_with_an_old_unconfirmed_record(): void
    {
        $this->assertUnconfirmedResumePreservesExternalStop(null);
    }

    private function assertUnconfirmedResumePreservesExternalStop(?string $stop): void
    {
        $this->exec(['pkill', '-STOP', '-f', '^php artisan schedule:work$'])->mustRun();
        $this->awaitCoordinator(true);
        $this->exec(['python3', '-c', <<<'PYTHON'
import json
from pathlib import Path
import sys
items = []
for path in Path('/proc').iterdir():
    if path.name.isdecimal():
        try:
            if (path / 'cmdline').read_bytes() == b'php\0artisan\0schedule:work\0':
                fields = (path / 'stat').read_text().rsplit(')', 1)[1].split()
                items.append({'pid': int(path.name), 'start': fields[19],
                              'initially_stopped': False, 'token': 'owner'})
        except FileNotFoundError:
            pass
assert len(items) == 1, items
state = items[0]
if sys.argv[1]:
    state['stop'] = sys.argv[1]
    state['protocol'] = 'legacy-scheduler-stop-v1'
Path('/var/tmp/nntmux/legacy-scheduler-drain.json').write_text(json.dumps(state))
PYTHON, $stop ?? ''])->mustRun();

        $check = $this->bridge('check', 10, 'owner');
        $check->run();
        $this->assertSame(1, $check->getExitCode(), $check->getErrorOutput());
        $resume = $this->bridge('resume', 10, 'owner');
        $resume->run();
        $this->assertSame(1, $resume->getExitCode(), $resume->getErrorOutput());
        $this->awaitCoordinator(true);
        $this->exec(['test', '-f', '/var/tmp/nntmux/legacy-scheduler-drain.json'])->mustRun();
        touch($this->directory.'/release');
        $this->awaitCompletion();
    }

    public function test_resume_rejects_a_foreign_stop_protocol(): void
    {
        touch($this->directory.'/release');
        $this->bridge('drain', 10, 'owner')->mustRun();
        $this->exec(['python3', '-c', <<<'PYTHON'
import json
from pathlib import Path
path = Path('/var/tmp/nntmux/legacy-scheduler-drain.json')
state = json.loads(path.read_text())
state['protocol'] = 'foreign'
path.write_text(json.dumps(state))
PYTHON])->mustRun();
        $resume = $this->bridge('resume', 10, 'owner');
        $resume->run();
        $this->assertSame(1, $resume->getExitCode(), $resume->getErrorOutput());
        $this->awaitCoordinator(true);
        $this->exec(['test', '-f', '/var/tmp/nntmux/legacy-scheduler-drain.json'])->mustRun();
    }

    public function test_resume_without_a_token_preserves_another_operations_barrier(): void
    {
        touch($this->directory.'/release');
        $this->bridge('drain', 10, 'owner')->mustRun();
        $check = $this->bridge('check');
        $check->run();
        $this->assertSame(1, $check->getExitCode(), $check->getErrorOutput());
        $this->bridge('resume')->mustRun();
        $this->awaitCoordinator(true);
        $this->bridge('resume', 10, 'owner')->mustRun();
        $this->awaitCoordinator(false);
    }

    public function test_a_lost_admission_barrier_fails_the_final_check(): void
    {
        touch($this->directory.'/release');
        $this->bridge('drain', 10)->mustRun();
        $this->exec(['pkill', '-CONT', '-f', '^php artisan schedule:work$'])->mustRun();
        $check = $this->bridge('check');
        $check->run();
        $this->assertSame(1, $check->getExitCode(), $check->getErrorOutput());
        $this->assertStringContainsString('barrier was lost', $check->getErrorOutput());
    }

    public function test_only_the_operation_that_paused_admission_can_restore_it(): void
    {
        touch($this->directory.'/release');
        $this->bridge('drain', 10, 'owner')->mustRun();
        $other = $this->bridge('drain', 0, 'other');
        $other->run();
        $this->assertSame(1, $other->getExitCode(), $other->getErrorOutput());
        $this->assertStringContainsString('Another operation owns', $other->getErrorOutput());
        $this->bridge('resume', 10, 'other')->mustRun();
        $this->awaitCoordinator(true);
        $this->bridge('resume', 10, 'owner')->mustRun();
        $this->awaitCoordinator(false);
    }

    /** @param list<string> $arguments */
    private function exec(array $arguments, ?string $input = null): Process
    {
        return new Process(['docker', 'exec', '-i', $this->container, ...$arguments], input: $input, timeout: 30);
    }

    /** @param list<string> $arguments */
    private function rootExec(array $arguments, ?string $input = null): Process
    {
        return new Process(['docker', 'exec', '-u', '0', '-i', $this->container, ...$arguments], input: $input, timeout: 30);
    }

    private function bridge(string $mode, int $timeout = 10, ?string $token = null): Process
    {
        $source = __DIR__.'/../../docker/bin/legacy-scheduler-drain.py';
        $this->assertFileExists($source);

        return $this->exec(['python3', '-', $mode, (string) $timeout, ...($token === null ? [] : [$token])], file_get_contents($source));
    }

    private function copy(string $source, string $destination): void
    {
        (new Process(['docker', 'cp', $source, $this->container.':'.$destination]))->mustRun();
    }

    private function awaitFile(string $name): void
    {
        $deadline = microtime(true) + 5;
        while (! file_exists($this->directory.'/'.$name) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($this->directory.'/'.$name);
    }

    private function awaitCoordinator(bool $stopped): void
    {
        $deadline = microtime(true) + 5;
        do {
            $probe = $this->exec(['sh', '-c', 'pid=$(pgrep -f "^php artisan schedule:work$"); [ -n "$pid" ] && ps -o stat= -p "$pid"']);
            $probe->run();
            $state = trim($probe->getOutput());
            if ($state !== '' && str_starts_with($state, 'T') === $stopped) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Legacy scheduler did not reach the expected stopped/running state: '.$state);
    }

    private function completed(): int
    {
        $probe = $this->exec(['php', '-r', 'echo (new PDO("sqlite:/state/work.sqlite"))->query("SELECT COUNT(*) FROM completed")->fetchColumn();']);
        $probe->mustRun();

        return (int) $probe->getOutput();
    }

    private function awaitCompletion(): void
    {
        $deadline = microtime(true) + 5;
        do {
            $completed = $this->completed();
            $admitted = count(file($this->directory.'/admitted'));
            if ($completed === $admitted) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertSame($admitted, $completed);
    }
}
