<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ContainerLifecycleTest extends TestCase
{
    private string $directory;

    /** @var list<Process> */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/nntmux-lifecycle-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory.'/_install');
        touch($this->directory.'/_install/install.lock');
        file_put_contents($this->directory.'/php', <<<'SH'
#!/bin/sh
set -eu
printf '%s\n' "${2:-cli}" >> "$LIFECYCLE_LOG"
if [ "${2:-}" = "$BLOCK_COMMAND" ]; then
    sleeper=''
    trap 'test -z "$sleeper" || kill "$sleeper" 2>/dev/null || true; printf "terminated\n" >> "$LIFECYCLE_LOG"; exit 143' TERM INT
    touch "$LIFECYCLE_READY"
    while [ ! -f "$LIFECYCLE_RELEASE" ]; do
        sleep 0.01 &
        sleeper=$!
        wait "$sleeper"
    done
fi
if [ "${2:-}" = "$FAIL_COMMAND" ]; then
    exit 42
fi
SH);
        chmod($this->directory.'/php', 0755);
        file_put_contents($this->directory.'/service', "#!/bin/sh\nprintf 'service\n' >> \"\$LIFECYCLE_LOG\"\n");
        chmod($this->directory.'/service', 0755);
    }

    protected function tearDown(): void
    {
        // Release a blocked fixture even when an assertion fails before shutdown finishes.
        touch($this->directory.'/release');
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.1);
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_entrypoint_cancels_cache_warmup_without_launching_the_service(): void
    {
        $process = $this->start('deploy/cloud/entrypoint.sh', [$this->directory.'/service'], ['BLOCK_COMMAND' => 'config:cache']);
        $this->awaitFile($this->directory.'/ready', $process);
        $process->signal(SIGTERM);
        $process->wait();

        $this->assertSame(143, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("config:cache\nterminated\n", $this->log());
    }

    public function test_entrypoint_preserves_initialization_failure_without_launching_the_service(): void
    {
        $process = $this->start('deploy/cloud/entrypoint.sh', [$this->directory.'/service'], ['FAIL_COMMAND' => 'route:cache']);
        $process->wait();

        $this->assertSame(42, $process->getExitCode());
        $this->assertSame("config:cache\nroute:cache\n", $this->log());
    }

    public function test_entrypoint_cli_commands_do_not_warm_caches_or_require_the_install_lock(): void
    {
        unlink($this->directory.'/_install/install.lock');
        $process = $this->start('deploy/cloud/entrypoint.sh', ['php', 'artisan', 'tmux:drain']);
        $process->wait();

        $this->assertSame(0, $process->getExitCode());
        $this->assertSame("tmux:drain\n", $this->log());
    }

    public function test_indexer_cancels_startup_before_draining_and_never_launches_health_repairs(): void
    {
        $process = $this->start('docker/app/bin/indexer.sh', [], ['BLOCK_COMMAND' => 'tmux:start']);
        $this->awaitFile($this->directory.'/ready', $process);
        $process->signal(SIGTERM);
        $process->wait();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("tmux:start\nterminated\ntmux:drain\ntmux:stop\n", $this->log());
    }

    public function test_indexer_cleans_up_failed_initialization_and_preserves_its_exit_status(): void
    {
        $process = $this->start('docker/app/bin/indexer.sh', [], ['FAIL_COMMAND' => 'tmux:start']);
        $process->wait();

        $this->assertSame(42, $process->getExitCode());
        $this->assertSame("tmux:start\ntmux:drain\ntmux:stop\n", $this->log());
    }

    public function test_indexer_cancels_an_inflight_health_repair_before_draining(): void
    {
        $process = $this->start('docker/app/bin/indexer.sh', [], ['BLOCK_COMMAND' => 'tmux:health-check']);
        $this->awaitFile($this->directory.'/ready', $process);
        $process->signal(SIGTERM);
        $process->wait();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("tmux:start\ntmux:health-check\nterminated\ntmux:drain\ntmux:stop\n", $this->log());
    }

    public function test_indexer_readiness_is_absent_during_startup_and_removed_during_shutdown(): void
    {
        mkdir($this->directory.'/scratch');
        touch($this->directory.'/scratch/indexer.ready');
        $process = $this->start('docker/app/bin/indexer.sh', [], ['BLOCK_COMMAND' => 'tmux:start']);
        $this->awaitFile($this->directory.'/ready', $process);
        $this->assertFileDoesNotExist($this->directory.'/scratch/indexer.ready');
        $process->signal(SIGTERM);
        $process->wait();
        $this->assertFileDoesNotExist($this->directory.'/scratch/indexer.ready');
    }

    public function test_indexer_shutdown_ignores_repeated_signals_until_drain_finishes(): void
    {
        $process = $this->start('docker/app/bin/indexer.sh', [], ['BLOCK_COMMAND' => 'tmux:drain']);
        $this->awaitFile($this->directory.'/scratch/indexer.ready', $process);
        $process->signal(SIGTERM);
        $this->awaitFile($this->directory.'/ready', $process);
        $this->assertFileDoesNotExist($this->directory.'/scratch/indexer.ready');
        $process->signal(SIGTERM);
        usleep(50000);
        $this->assertTrue($process->isRunning());
        $this->assertStringNotContainsString('terminated', $this->log());
        $this->assertStringNotContainsString('tmux:stop', $this->log());
        touch($this->directory.'/release');
        $process->wait();
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame(1, substr_count($this->log(), 'tmux:drain'));
        $this->assertSame(1, substr_count($this->log(), 'tmux:stop'));
    }

    public function test_indexer_reports_a_failed_drain_after_stopping_remaining_workers(): void
    {
        $process = $this->start('docker/app/bin/indexer.sh', [], ['FAIL_COMMAND' => 'tmux:drain']);
        $this->awaitFile($this->directory.'/scratch/indexer.ready', $process);
        $process->signal(SIGTERM);
        $process->wait();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString("tmux:drain\ntmux:stop\n", $this->log());
        $this->assertStringContainsString('tmux drain failed', $process->getErrorOutput());
    }

    /** @param list<string> $arguments
     * @param  array<string, string>  $environment
     */
    private function start(string $script, array $arguments, array $environment = []): Process
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$script);
        $source = str_replace(['/app', '/var/tmp/nntmux'], [$this->directory, $this->directory.'/scratch'], $source);
        $target = $this->directory.'/supervisor.sh';
        file_put_contents($target, $source);
        $process = new Process(['sh', $target, ...$arguments], $this->directory, [
            'PATH' => $this->directory.':'.getenv('PATH'),
            'LIFECYCLE_LOG' => $this->directory.'/commands',
            'LIFECYCLE_READY' => $this->directory.'/ready',
            'LIFECYCLE_RELEASE' => $this->directory.'/release',
            'BLOCK_COMMAND' => '',
            'FAIL_COMMAND' => '',
            'INDEXER_HEALTH_INTERVAL' => '0.05',
            ...$environment,
        ]);
        $process->setTimeout(4);
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function awaitFile(string $path, Process $process): void
    {
        $deadline = microtime(true) + 3;
        while (! is_file($path) && microtime(true) < $deadline && $process->isRunning()) {
            usleep(10000);
        }
        $this->assertFileExists($path, $process->getErrorOutput());
    }

    private function log(): string
    {
        return file_get_contents($this->directory.'/commands');
    }
}
