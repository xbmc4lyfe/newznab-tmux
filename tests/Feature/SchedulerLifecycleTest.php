<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class SchedulerLifecycleTest extends TestCase
{
    private string $directory;

    private string $container;

    private string $image;

    private ?Process $supervisor = null;

    private bool $containerMode;

    protected function setUp(): void
    {
        parent::setUp();
        $image = getenv('SCHEDULER_TEST_IMAGE');
        $this->containerMode = (bool) $image;
        if (! $this->containerMode && PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux subreaper tests run on Linux or with SCHEDULER_TEST_IMAGE set to an existing app image.');
        }
        $this->image = $image ?: '';
        $this->directory = sys_get_temp_dir().'/nntmux-scheduler-'.bin2hex(random_bytes(8));
        $this->container = 'nntmux-scheduler-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0777);
        chmod($this->directory, 0777);
        file_put_contents($this->directory.'/fixture.py', <<<'PYTHON'
import os
import signal
import subprocess
import sys
import time
from pathlib import Path
state = Path('/state')
if len(sys.argv) > 1:
    if sys.argv[1] == 'worker':
        if os.fork():
            sys.exit(0)
        os.setsid()
        if os.fork():
            sys.exit(0)
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    (state / 'worker.ready').touch()
    while not (state / 'release').exists():
        time.sleep(0.01)
    (state / 'completed').touch()
    sys.exit(0)
signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))
with (state / 'admissions').open('a') as output:
    output.write('started\n')
subprocess.run([sys.executable, __file__, 'worker'], check=True)
if (state / 'startup.failure').exists():
    while not (state / 'worker.ready').exists():
        time.sleep(0.01)
    sys.exit(42)
while True:
    time.sleep(0.01)
PYTHON);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            touch($this->directory.'/release');
            if ($this->containerMode) {
                $this->docker(['rm', '-f', $this->container]);
            } elseif ($this->supervisor !== null && $this->supervisor->isRunning()) {
                $this->supervisor->stop(2, SIGTERM);
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_term_waits_for_nested_orphan_job_and_ignores_repeated_signals(): void
    {
        $this->start();
        $this->awaitFile('worker.ready');
        $this->signalSupervisor();
        usleep(150000);
        $this->assertRunning();
        $this->signalSupervisor();
        $this->assertFileDoesNotExist($this->directory.'/completed');
        touch($this->directory.'/release');
        if ($this->containerMode) {
            $this->assertSame("0\n", $this->docker(['wait', $this->container])->getOutput());
        } else {
            $this->assertNotNull($this->supervisor);
            $this->supervisor->wait();
            $this->assertSame(0, $this->supervisor->getExitCode(), $this->supervisor->getErrorOutput());
        }
        $this->assertFileExists($this->directory.'/completed');
    }

    public function test_drain_waits_for_finite_jobs_stays_alive_and_resume_reopens_admission(): void
    {
        $this->start();
        $this->awaitFile('worker.ready');
        $drain = $this->control(['drain', '--timeout=4']);
        $drain->start();
        $this->awaitState('draining');
        $this->assertTrue($drain->isRunning());
        touch($this->directory.'/release');
        $drain->wait();
        $this->assertSame(0, $drain->getExitCode(), $drain->getErrorOutput());
        $this->assertSame('paused', $this->schedulerStatus()['state']);
        $check = $this->control(['check']);
        $check->run();
        $this->assertSame(0, $check->getExitCode(), $check->getErrorOutput());
        $this->assertRunning();
        $resume = $this->control(['resume']);
        $resume->run();
        $this->assertSame(0, $resume->getExitCode(), $resume->getErrorOutput());
        $this->awaitAdmissions(2);
        $this->assertSame('running', $this->schedulerStatus()['state']);
        $check = $this->control(['check']);
        $check->run();
        $this->assertSame(1, $check->getExitCode());
    }

    public function test_drain_timeout_restores_admission_without_killing_finite_work(): void
    {
        $this->start();
        $this->awaitFile('worker.ready');
        $drain = $this->control(['drain', '--timeout=0.2']);
        $drain->run();
        $this->assertSame(1, $drain->getExitCode(), $drain->getErrorOutput());
        $this->awaitAdmissions(2);
        $this->assertSame('running', $this->schedulerStatus()['state']);
        $this->assertFileDoesNotExist($this->directory.'/completed');
        touch($this->directory.'/release');
        $this->awaitFile('completed');
    }

    public function test_interrupted_drain_client_restores_admission(): void
    {
        $this->start();
        $this->awaitFile('worker.ready');
        $drain = $this->control(['drain', '--timeout=4']);
        $drain->start();
        $this->awaitState('draining');
        // Signal the CLI inside the container; killing docker exec alone can leave it running.
        if ($this->containerMode) {
            $interrupt = $this->docker(['exec', $this->container, 'pkill', '-TERM', '-f', '/supervisor.py drain']);
            $this->assertSame(0, $interrupt->getExitCode());
        } else {
            $drain->signal(SIGTERM);
        }
        $drain->wait();
        $this->awaitAdmissions(2);
        $this->assertSame('running', $this->schedulerStatus()['state']);
        $this->assertFileDoesNotExist($this->directory.'/completed');
    }

    public function test_real_laravel_background_event_survives_docker_stop(): void
    {
        if (! $this->containerMode) {
            $this->markTestSkipped('Actual Docker stop requires SCHEDULER_TEST_IMAGE.');
        }
        $this->start(true);
        $this->awaitFile('worker.ready');
        $stop = new Process(['docker', 'stop', '--timeout=5', $this->container], null, null, null, 8);
        $stop->start();
        usleep(250000);
        $this->assertTrue($stop->isRunning());
        $this->assertRunning();
        touch($this->directory.'/release');
        $stop->wait();
        $this->assertSame(0, $stop->getExitCode(), $stop->getErrorOutput());
        $this->assertSame("0\n", $this->docker(['inspect', '-f', '{{.State.ExitCode}}', $this->container])->getOutput());
        $this->assertFileExists($this->directory.'/completed');
        $this->assertFileExists($this->directory.'/finished');
    }

    public function test_real_laravel_background_event_survives_term(): void
    {
        $this->start(true);
        $this->awaitFile('worker.ready');
        $this->signalSupervisor();
        usleep(150000);
        $this->assertRunning();
        touch($this->directory.'/release');
        if ($this->containerMode) {
            $this->assertSame("0\n", $this->docker(['wait', $this->container])->getOutput());
        } else {
            $this->assertNotNull($this->supervisor);
            $this->supervisor->wait();
            $this->assertSame(0, $this->supervisor->getExitCode(), $this->supervisor->getErrorOutput());
        }
        $this->assertFileExists($this->directory.'/completed');
    }

    public function test_restore_preserves_pre_existing_paused_admission(): void
    {
        $this->start();
        $this->awaitFile('worker.ready');
        touch($this->directory.'/release');
        foreach (['drain', 'drain', 'restore'] as $action) {
            $process = $this->control([$action, '--timeout=4']);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        }
        $this->assertSame('paused', $this->schedulerStatus()['state']);
        $this->assertSame(1, substr_count(file_get_contents($this->directory.'/admissions'), 'started'));
    }

    public function test_unaccepted_drain_restore_token_does_not_reopen_manual_pause(): void
    {
        $this->start();
        $this->awaitFile('worker.ready');
        touch($this->directory.'/release');
        $drain = $this->control(['drain', '--token=manual', '--timeout=4']);
        $drain->run();
        $this->assertSame(0, $drain->getExitCode(), $drain->getErrorOutput());
        $invalid = $this->control(['drain', '--token=deployment', '--timeout=-1']);
        $invalid->run();
        $this->assertNotSame(0, $invalid->getExitCode());
        $restore = $this->control(['restore', '--token=deployment']);
        $restore->run();
        $this->assertSame(0, $restore->getExitCode(), $restore->getErrorOutput());
        $this->assertSame('paused', $this->schedulerStatus()['state']);
    }

    public function test_rejected_concurrent_drain_restore_preserves_the_original_owner(): void
    {
        $this->start();
        $this->awaitFile('worker.ready');
        $original = $this->control(['drain', '--token=original', '--timeout=4']);
        $original->start();
        $this->awaitState('draining');
        $other = $this->control(['drain', '--token=other', '--timeout=4']);
        $other->run();
        $this->assertSame(1, $other->getExitCode());
        $restore = $this->control(['restore', '--token=other']);
        $restore->run();
        $this->assertSame(0, $restore->getExitCode(), $restore->getErrorOutput());
        $this->assertSame('draining', $this->schedulerStatus()['state']);
        $this->assertTrue($original->isRunning());
        touch($this->directory.'/release');
        $original->wait();
        $this->assertSame(0, $original->getExitCode(), $original->getErrorOutput());
        $restore = $this->control(['restore', '--token=original']);
        $restore->run();
        $this->assertSame(0, $restore->getExitCode(), $restore->getErrorOutput());
        $this->awaitAdmissions(2);
    }

    public function test_second_supervisor_cannot_replace_live_control_socket(): void
    {
        $this->start();
        $this->awaitFile('worker.ready');
        $second = $this->control(['serve']);
        $second->run();
        $this->assertSame(1, $second->getExitCode());
        $this->assertSame('running', $this->schedulerStatus()['state']);
        $this->assertRunning();
    }

    public function test_failed_coordinator_preserves_admitted_work_and_failure_status(): void
    {
        touch($this->directory.'/startup.failure');
        $this->start();
        $this->awaitFile('worker.ready');
        usleep(150000);
        $this->assertRunning();
        $status = $this->control(['status']);
        $status->run();
        $this->assertSame(1, $status->getExitCode());
        touch($this->directory.'/release');
        if ($this->containerMode) {
            $this->assertSame("42\n", $this->docker(['wait', $this->container])->getOutput());
        } else {
            $this->assertNotNull($this->supervisor);
            $this->supervisor->wait();
            $this->assertSame(42, $this->supervisor->getExitCode());
        }
        $this->assertFileExists($this->directory.'/completed');
    }

    private function start(bool $laravel = false): void
    {
        $source = dirname(__DIR__, 2).'/docker/app/bin/scheduler.py';
        if ($laravel) {
            copy(dirname(__DIR__).'/Fixtures/SchedulerLifecycle/artisan.php', $this->directory.'/artisan');
        }
        $this->assertFileExists($source, 'The scheduler must own and reap admitted background jobs.');
        if (! $this->containerMode) {
            $supervisorSource = str_replace('/var/tmp/nntmux', $this->directory.'/scratch', file_get_contents($source));
            file_put_contents($this->directory.'/supervisor.py', $supervisorSource);
            $fixture = str_replace('/state', $this->directory, file_get_contents($this->directory.'/fixture.py'));
            file_put_contents($this->directory.'/fixture.py', $fixture);
            $worker = $laravel ? ['php', $this->directory.'/artisan', 'schedule:work'] : ['python3', $this->directory.'/fixture.py'];
            $this->supervisor = new Process(['python3', $this->directory.'/supervisor.py', 'serve', '--', ...$worker], null, ['SCHEDULER_AUTOLOAD' => dirname(__DIR__, 2).'/vendor/autoload.php'], null, 8);
            $this->supervisor->start();

            return;
        }
        $worker = $laravel ? ['php', '/state/artisan', 'schedule:work'] : ['python3', '/state/fixture.py'];
        $process = $this->docker(['run', '-d', '--init', '--network=none', '--name', $this->container,
            '--user=33:33', '--entrypoint=python3', '--tmpfs=/var/tmp/nntmux:uid=33,gid=33,mode=0770',
            '-v', $source.':/supervisor.py:ro', '-v', $this->directory.':/state',
            $this->image, '/supervisor.py', 'serve', '--', ...$worker]);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /** @param list<string> $arguments */
    private function control(array $arguments): Process
    {
        $command = $this->containerMode
            ? ['docker', 'exec', $this->container, 'python3', '/supervisor.py', ...$arguments]
            : ['python3', $this->directory.'/supervisor.py', ...$arguments];

        return new Process($command, null, null, null, 8);
    }

    /** @param list<string> $arguments */
    private function docker(array $arguments): Process
    {
        $process = new Process(['docker', ...$arguments], null, null, null, 10);
        $process->run();

        return $process;
    }

    /** @return array{state: string} */
    private function schedulerStatus(): array
    {
        $process = $this->control(['status']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertRunning(): void
    {
        if ($this->containerMode) {
            $this->assertSame("true\n", $this->docker(['inspect', '-f', '{{.State.Running}}', $this->container])->getOutput());
        } else {
            $this->assertNotNull($this->supervisor);
            $this->assertTrue($this->supervisor->isRunning(), $this->supervisor->getErrorOutput());
        }
    }

    private function signalSupervisor(): void
    {
        if ($this->containerMode) {
            $this->assertSame(0, $this->docker(['kill', '--signal=TERM', $this->container])->getExitCode());
        } else {
            $this->assertNotNull($this->supervisor);
            $this->supervisor->signal(SIGTERM);
        }
    }

    private function awaitFile(string $name): void
    {
        $deadline = microtime(true) + 4;
        while (! is_file($this->directory.'/'.$name) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($this->directory.'/'.$name, $this->containerMode ? $this->docker(['logs', $this->container])->getErrorOutput() : $this->supervisor?->getErrorOutput() ?? '');
    }

    private function awaitState(string $state): void
    {
        $deadline = microtime(true) + 4;
        do {
            $actual = $this->schedulerStatus()['state'];
            if ($actual === $state) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertSame($state, $actual);
    }

    private function awaitAdmissions(int $count): void
    {
        $deadline = microtime(true) + 4;
        do {
            $actual = substr_count(file_get_contents($this->directory.'/admissions'), 'started');
            if ($actual >= $count) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertSame($count, $actual);
    }
}
