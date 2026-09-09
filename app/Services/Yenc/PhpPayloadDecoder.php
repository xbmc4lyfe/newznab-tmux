<?php

declare(strict_types=1);

namespace App\Services\Yenc;

final class PhpPayloadDecoder implements PayloadDecoder
{
    private static string $from = '';

    private static string $to = '';

    public function decode(string $payload): string
    {
        if (self::$from === '') {
            for ($byte = 0; $byte < 256; $byte++) {
                self::$from .= chr($byte);
                self::$to .= chr(($byte - 42) & 255);
            }
        }

        $position = strpos($payload, '=');
        if ($position === false) {
            return strtr($payload, self::$from, self::$to);
        }

        $decoded = '';
        $offset = 0;
        do {
            if ($position > $offset) {
                $decoded .= strtr(substr($payload, $offset, $position - $offset), self::$from, self::$to);
            }
            if (! isset($payload[$position + 1])) {
                return $decoded;
            }
            $decoded .= chr((ord($payload[$position + 1]) - 106) & 255);
            $offset = $position + 2;
            $position = strpos($payload, '=', $offset);
        } while ($position !== false);

        return $decoded.strtr(substr($payload, $offset), self::$from, self::$to);
    }
}
