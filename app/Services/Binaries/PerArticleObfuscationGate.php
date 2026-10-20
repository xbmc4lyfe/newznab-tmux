<?php

declare(strict_types=1);

namespace App\Services\Binaries;

/**
 * The first routing step for {@see NativePerArticleObfuscation}, loaded only for ranges of
 * HeaderParser::NATIVE_MIN_HEADERS or more: a cheap check of 16 subjects keeps mostly plain-text
 * ranges on PHP before the larger {@see PerArticleObfuscationRouter} loads.
 */
final class PerArticleObfuscationGate
{
    /**
     * Whether the Rust port should take this range.
     *
     * @param  array<int, array<string, mixed>>  $headers
     */
    public static function worthNativeMarking(array $headers, bool $libraryLoaded): bool
    {
        return \count($headers) >= HeaderParser::NATIVE_MIN_HEADERS && self::mayCarryPartCounts($headers)
            && PerArticleObfuscationRouter::worthNativeMarking($headers, $libraryLoaded);
    }

    /**
     * Whether at least a quarter of 16 evenly spaced subjects hold ` (`, which every yEnc part
     * count needs. Ranges of mostly plain text, which the PHP rule rejects fastest, stay on PHP
     * without loading the router. It can only keep a range on PHP, never send one to Rust.
     *
     * @param  array<int, array<string, mixed>>  $headers
     */
    private static function mayCarryPartCounts(array $headers): bool
    {
        $values = array_is_list($headers) ? $headers : array_values($headers);
        $count = \count($values);
        $hits = 0;
        for ($probe = 0; $probe < 16; $probe++) {
            $subject = $values[intdiv((2 * $probe + 1) * $count, 32)]['Subject'] ?? null;
            if (\is_string($subject) && str_contains($subject, ' (')) {
                $hits++;
            }
        }

        return $hits >= 4;
    }
}
