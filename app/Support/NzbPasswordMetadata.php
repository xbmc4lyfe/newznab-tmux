<?php

declare(strict_types=1);

namespace App\Support;

final class NzbPasswordMetadata
{
    public static function isValid(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > 64) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_string($value) || strlen($value) > 4096
                || preg_match('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value) !== 0) {
                return false;
            }
        }

        return true;
    }
}
