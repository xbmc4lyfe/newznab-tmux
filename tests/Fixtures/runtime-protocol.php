#!/usr/bin/env php
<?php

declare(strict_types=1);

// Synthetic protocol replies only; no native hashing or external I/O.
$mode = basename(__FILE__, '.php');
$hasArticle = false;
while (($command = fread(STDIN, 1)) !== '') {
    if ($command === 'A') {
        $lengthBytes = fread(STDIN, 4);
        if (strlen($lengthBytes) !== 4) {
            exit(1);
        }
        $length = unpack('Nlength', $lengthBytes)['length'];
        while ($length > 0) {
            $chunk = fread(STDIN, $length);
            if ($chunk === '') {
                exit(1);
            }
            $length -= strlen($chunk);
        }
        $hasArticle = true;
    } elseif ($command === 'V') {
        if (! $hasArticle || $mode === 'unknown') {
            fwrite(STDOUT, "\0");
        } elseif ($mode === 'invalid') {
            fwrite(STDOUT, "\2");
        } else {
            fwrite(STDOUT, "\1".str_repeat('a', 40));
        }
        fflush(STDOUT);
        if ($hasArticle && $mode === 'known-exit') {
            exit(0);
        }
    } else {
        exit(1);
    }
}
