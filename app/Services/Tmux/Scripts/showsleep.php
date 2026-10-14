<?php

declare(strict_types=1);

/**
 * Sleep display script for tmux panes
 * Shows a countdown timer in the pane
 */
$sleepTime = (int) ($argv[1] ?? 60);

if ($sleepTime <= 0) {
    exit(0);
}

$endTime = time() + $sleepTime;
$session = $argv[2] ?? '';
$socket = $argv[3] ?? '';
$command = array_merge(['tmux', '-u'], $socket === '' ? [] : ['-L', $socket], ['display-message', '-p', '-t', '='.$session.':', '#{@nntmux_draining}']);

while (time() < $endTime) {
    if ($session !== '') {
        $state = [];
        exec(implode(' ', array_map('escapeshellarg', $command)).' 2>/dev/null', $state);
        if (trim(implode('', $state)) === '1') {
            echo "\r⏸ Draining; countdown stopped".PHP_EOL;

            exit(0);
        }
    }
    $remaining = $endTime - time();
    $hours = floor($remaining / 3600);
    $minutes = floor(($remaining % 3600) / 60);
    $seconds = $remaining % 60;

    printf("\r⏳ Sleeping: %02d:%02d:%02d remaining", $hours, $minutes, $seconds);
    sleep(1);
}

echo "\r✅ Sleep complete".str_repeat(' ', 30)."\n";
