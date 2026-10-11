<?php

declare(strict_types=1);

namespace Tests\Support {
    final class MarkerIoFailures
    {
        public static bool $shortWrite = false;

        public static bool $renameFails = false;

        public static bool $directorySyncFails = false;
    }
}

namespace App\Services\Tmux {
    use Tests\Support\MarkerIoFailures;

    /** @param resource $stream */
    function fwrite($stream, string $data): int|false
    {
        if (MarkerIoFailures::$shortWrite) {
            return \fwrite($stream, substr($data, 0, 6));
        }

        return \fwrite($stream, $data);
    }

    function rename(string $from, string $to): bool
    {
        return ! MarkerIoFailures::$renameFails && \rename($from, $to);
    }

    /** @param resource $stream */
    function fsync($stream): bool
    {
        $stat = fstat($stream);
        if ($stat !== false && ($stat['mode'] & 0170000) === 0040000 && MarkerIoFailures::$directorySyncFails) {
            return false;
        }

        return \fsync($stream);
    }
}
