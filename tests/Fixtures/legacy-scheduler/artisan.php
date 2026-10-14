<?php

declare(strict_types=1);

if (($argv[1] ?? '') === 'schedule:work') {
    // A frozen scheduler cannot reap an already finished direct child. Keep one
    // unreaped deliberately so the compatibility drain exercises that case.
    $child = pcntl_fork();
    if ($child === -1) {
        throw new RuntimeException('Cannot create the scheduler zombie fixture.');
    }
    if ($child === 0) {
        exit(0);
    }
    file_put_contents('/state/zombie-pid', (string) $child);
}
$original = getcwd().'/artisan.original';
if (! is_file($original)) {
    throw new RuntimeException('The archived original artisan entrypoint is missing.');
}
require $original;
