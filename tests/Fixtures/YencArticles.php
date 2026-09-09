<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** Independent scalar fixture writer; never calls the production encoder. */
final class YencArticles
{
    public static function article(string $data, string $eol = "\r\n"): string
    {
        $lines = [];
        $line = '';
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset++) {
            $encoded = (ord($data[$offset]) + 42) % 256;
            if (in_array($encoded, [0, 10, 13, 61], true)) {
                $line .= '='.chr(($encoded + 64) % 256);
            } else {
                $line .= chr($encoded);
            }
            if (strlen($line) >= 128) {
                $lines[] = $line;
                $line = '';
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        $size = strlen($data);

        return '=ybegin line=128 size='.$size.' name=fixture file.bin'.$eol
            .implode($eol, $lines).$eol.'=yend size='.$size.' crc32='.hash('crc32b', $data);
    }

    public static function bytes(): string
    {
        return implode('', array_map(chr(...), range(0, 255)));
    }

    public static function randomBytes(int $length): string
    {
        $data = '';
        for ($counter = 0; strlen($data) < $length; $counter++) {
            $data .= hash('sha256', 'yenc-fixture-'.$counter, true);
        }

        return substr($data, 0, $length);
    }
}
