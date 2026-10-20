<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The first routing step for {@see NativePerArticleObfuscation}, loaded only for ranges of
 * HeaderParser::NATIVE_MIN_HEADERS or more: a cheap check of 16 sampled subjects keeps mostly plain-text
 * ranges on PHP before the larger {@see PerArticleObfuscationRouter} loads.
 */
final class PerArticleObfuscationGate
{
    /** Probe positions only need to be unpredictable per range, not secure: one engine per process. */
    private static ?Randomizer $probes = null;

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
     * Whether at least a quarter of 16 subjects, one at random from each sixteenth of the range, hold ` (`, which every yEnc part
     * count needs. Ranges of mostly plain text, which the PHP rule rejects fastest, stay on PHP
     * without loading the router. It can only keep a range on PHP, never send one to Rust.
     *
     * @param  array<int, array<string, mixed>>  $headers
     */
    private static function mayCarryPartCounts(array $headers): bool
    {
        $values = array_is_list($headers) ? $headers : array_values($headers);
        $count = \count($values);
        $random = self::$probes ??= new Randomizer(new Xoshiro256StarStar);
        $hits = 0;
        for ($probe = 0; $probe < 16; $probe++) {
            // One random subject from each of 16 equal slices, so no periodic ordering decides.
            $subject = $values[$random->getInt(intdiv($probe * $count, 16), intdiv(($probe + 1) * $count, 16) - 1)]['Subject'] ?? null;
            if (\is_string($subject) && str_contains($subject, ' (')) {
                $hits++;
            }
        }

        return $hits >= 4;
    }
}
