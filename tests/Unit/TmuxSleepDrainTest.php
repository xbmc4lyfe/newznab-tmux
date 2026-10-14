<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class TmuxSleepDrainTest extends TestCase
{
    public function test_an_already_sleeping_countdown_wakes_when_the_session_is_drained(): void
    {
        $directory = sys_get_temp_dir().'/nntmux-sleep-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $marker = $directory.'/drain';
        file_put_contents($marker, '0');
        file_put_contents($directory.'/tmux', "#!/bin/sh\ncat \"\$NNTMUX_TEST_DRAIN_MARKER\"\n");
        chmod($directory.'/tmux', 0700);
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/app/Services/Tmux/Scripts/showsleep.php', '30', 'isolated', 'test-socket'], null,
            ['PATH' => $directory.':'.getenv('PATH'), 'NNTMUX_TEST_DRAIN_MARKER' => $marker], timeout: 5);
        try {
            $process->start();
            $deadline = microtime(true) + 2;
            while (! str_contains($process->getOutput(), 'Sleeping') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertTrue($process->isRunning());
            $this->assertStringContainsString('Sleeping', $process->getOutput());
            file_put_contents($marker, '1');
            $process->wait();
            $this->assertTrue($process->isSuccessful());
            $this->assertStringContainsString('Draining', $process->getOutput());
        } finally {
            $process->stop(0);
            unlink($marker);
            unlink($directory.'/tmux');
            rmdir($directory);
        }
    }
}
