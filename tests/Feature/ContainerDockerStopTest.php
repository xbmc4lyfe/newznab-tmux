<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ContainerDockerStopTest extends TestCase
{
    public function test_docker_stop_drains_real_tmux_workers_without_launching_queued_work(): void
    {
        $image = getenv('NNTMUX_TEST_CONTAINER_IMAGE');
        if (! is_string($image) || $image === '') {
            $this->markTestSkipped('Set NNTMUX_TEST_CONTAINER_IMAGE to an existing isolated validation image.');
        }
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir().'/nntmux-container-stop-'.bin2hex(random_bytes(8));
        $name = 'nntmux-container-stop-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777);
        mkdir($directory.'/bin');
        copy($root.'/tests/Fixtures/ContainerLifecycleArtisan.php', $directory.'/artisan.php');
        touch($directory.'/runtime.sqlite');
        file_put_contents($directory.'/bin/php', <<<'SH'
#!/bin/sh
case "${2:-}" in
    config:cache|route:cache|view:cache|event:cache|data:cache-structures) exit 0 ;;
esac
exec /usr/local/bin/php /fixture/artisan.php "$@"
SH);
        chmod($directory.'/bin/php', 0755);
        file_put_contents($directory.'/start.sh', <<<'SH'
#!/bin/sh
set -eu
mkdir -p /app/_install /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs
touch /app/_install/install.lock
export PATH=/fixture/bin:$PATH
exec sh /app/deploy/cloud/entrypoint.sh /usr/local/bin/nntmux-indexer
SH);
        $arguments = ['docker', 'run', '-d', '--init', '--network=none', '--name', $name, '--user=0:0', '--stop-timeout=10', '--entrypoint=sh'];
        foreach (['app', 'vendor'] as $path) {
            array_push($arguments, '--mount', 'type=bind,src='.$root.'/'.$path.',dst=/app/'.$path.',readonly');
        }
        foreach (['deploy/cloud/entrypoint.sh' => '/app/deploy/cloud/entrypoint.sh', 'docker/app/bin/indexer.sh' => '/usr/local/bin/nntmux-indexer'] as $path => $target) {
            array_push($arguments, '--mount', 'type=bind,src='.$root.'/'.$path.',dst='.$target.',readonly');
        }
        array_push($arguments, '--mount', 'type=bind,src='.$directory.',dst=/fixture', '--tmpfs', '/var/tmp/nntmux', '-e', 'NNTMUX_TEST_APP_ROOT=/app', '-e', 'INDEXER_DRAIN_TIMEOUT=6', '-e', 'INDEXER_HEALTH_INTERVAL=30', $image, '/fixture/start.sh');
        try {
            (new Process($arguments))->mustRun();
            $this->awaitFile($directory.'/work');
            $ready = new Process(['docker', 'exec', $name, 'test', '-f', '/var/tmp/nntmux/indexer.ready']);
            $ready->mustRun();
            $started = microtime(true);
            $stop = new Process(['docker', 'stop', $name]);
            $stop->setTimeout(15);
            $stop->start();
            $this->awaitFile($directory.'/draining');
            $stop->wait();
            $this->assertTrue($stop->isSuccessful(), $stop->getErrorOutput());
            $this->assertLessThan(10, microtime(true) - $started);
            $inspect = new Process(['docker', 'inspect', '--format={{.State.ExitCode}}', $name]);
            $inspect->mustRun();
            $logs = new Process(['docker', 'logs', $name]);
            $logs->run();
            $this->assertSame('0', trim($inspect->getOutput()), $logs->getOutput().$logs->getErrorOutput());
            $this->assertSame("started\nfinished\n", file_get_contents($directory.'/work'));
            $this->assertSame("tmux:start\ntmux:drain\ntmux:stop\n", file_get_contents($directory.'/commands'));
            $this->assertStringContainsString('Active processing workers finished', $logs->getOutput());
        } finally {
            (new Process(['docker', 'rm', '-f', $name]))->run();
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 8;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertFileExists($path);
    }
}
