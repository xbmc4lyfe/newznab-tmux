#!/usr/bin/env php
<?php

declare(strict_types=1);

// Protocol-only fixture: tests writer lifecycle, not Rust fingerprint correctness.
$hasArticle = false;
while (($command = fread(STDIN, 1)) !== '') {
    if ($command === 'A') {
        exit(9);
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
        fwrite(STDOUT, $hasArticle ? "\1".str_repeat('a', 40) : "\0");
        fflush(STDOUT);
    } else {
        exit(1);
    }
}
