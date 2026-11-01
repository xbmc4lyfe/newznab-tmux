<?php

declare(strict_types=1);

namespace App\Services\Binaries;

/**
 * Decides, from a sample of a header range, whether {@see NativePerArticleObfuscation} should mark
 * it or the PHP rule in {@see HeaderParser::markPerArticleObfuscated()} should. Loaded only for
 * ranges of HeaderParser::NATIVE_MIN_HEADERS or more that pass {@see PerArticleObfuscationGate}'s check.
 */
final class PerArticleObfuscationRouter
{
    /** Headers sampled to decide whether a range is worth marshalling to Rust: one in 128, 32 to 128. */
    private const int SAMPLE_MIN = 32;

    private const int SAMPLE_MAX = 128;

    /**
     * Per-header cost by sampled class (no part count, a part count only, a candidate) as
     * [PHP base ns, PHP tenths of a ns per subject byte, Rust base ns, Rust tenths of a ns per byte].
     * Fitted to XOVER-shaped ranges of 60-480 byte subjects with
     * scripts/benchmark-per-article-obfuscation.php on Linux arm64; CI requires that benchmark to
     * qualify on both Linux x86_64 and arm64 (archive-sample-performance.yml).
     */
    private const array COSTS = [[210, 5, 265, 10], [80, 80, 110, 39], [1350, 60, 330, 37]];

    /**
     * A range goes to Rust when its predicted speedup reaches 2x. The prices run about a sixth
     * optimistic on mixed ranges, so this keeps the measured speedup above the 1.5x rule.
     */
    private const int PREDICTED_SPEEDUP_PERCENT = 200;

    /**
     * Building the wrapper, loading the library and running its probe took about 0.6 ms median and
     * 0.8-1.2 ms p99 per process; a range that would load it must be predicted to save 4 ms.
     */
    private const int FIRST_LOAD_SAVING_NS = 4_000_000;

    private const string DIGITS = '0123456789';

    private const string ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** PCRE's non-UTF `\s`. */
    private const string WHITESPACE = " \t\n\v\f\r";

    /** Mirrors NativePerArticleObfuscation::MAX_SUBJECT_LENGTH: Rust declines longer subjects. */
    public const int MAX_SUBJECT_LENGTH = 512;

    /**
     * Whether the Rust port should take this range of HeaderParser::NATIVE_MIN_HEADERS or more.
     * These stay on PHP:
     * - ranges where a spread sample, priced with COSTS, predicts under 2x: the PHP rule
     *   rejects subjects without a part count faster than they can be handed to Rust;
     * - before the library is loaded, ranges whose predicted saving is under
     *   FIRST_LOAD_SAVING_NS (building the wrapper, loading the library and its probe);
     * - ranges with a subject over MAX_SUBJECT_LENGTH, which Rust would decline.
     * Separate from the wrapper, so a range routed to PHP never loads the wrapper class.
     *
     * @param  array<int, array<string, mixed>>  $headers
     */
    public static function worthNativeMarking(array $headers, bool $libraryLoaded): bool
    {
        $values = array_is_list($headers) ? $headers : array_values($headers);
        $count = \count($values);
        // One pseudo-random header from each of $samples equal slices, so no periodic ordering of the
        // range decides the prediction.
        $samples = min(self::SAMPLE_MAX, max(self::SAMPLE_MIN, intdiv($count, 128)));
        $random = PerArticleObfuscationGate::sampler($values);
        $php = $rust = 0;
        for ($slice = 0; $slice < $samples; $slice++) {
            $start = intdiv($slice * $count, $samples);
            $index = $random->getInt($start, max($start, intdiv(($slice + 1) * $count, $samples) - 1));
            $subject = $values[$index]['Subject'] ?? null;
            $bytes = is_string($subject) ? strlen($subject) : 0;
            [$phpBase, $phpPerByte, $rustBase, $rustPerByte] = self::COSTS[self::classifySample($subject)];
            $php += $phpBase + intdiv($phpPerByte * $bytes, 10);
            $rust += $rustBase + intdiv($rustPerByte * $bytes, 10);
        }
        if ($php * 100 < $rust * self::PREDICTED_SPEEDUP_PERCENT) {
            return false;
        }
        // Before this process has loaded the library, the predicted saving must also pay for
        // loading it and running its probe.
        if (! $libraryLoaded && intdiv(($php - $rust) * $count, $samples) < self::FIRST_LOAD_SAVING_NS) {
            return false;
        }
        // A subject Rust would decline sends the range to PHP before any packing.
        $subjects = array_filter(array_column($values, 'Subject'), is_string(...));

        return $subjects === [] || max(array_map(strlen(...), $subjects)) <= self::MAX_SUBJECT_LENGTH;
    }

    /**
     * 0: no part count; 1: a part count only; 2: a candidate as the PHP rule sees it, a first
     * whitespace-led `(n/total)` with total of at least 2 and a 16+ alphanumeric token before it.
     * String scans rather than regexes, so a fresh process does not pay to compile patterns, and
     * linear in the subject length.
     */
    private static function classifySample(mixed $subject): int
    {
        // The subject pattern's negative lookahead rejects these at once (only with no leading
        // whitespace, where it has no shorter start to fall back on).
        if (! is_string($subject) || str_starts_with($subject, 'Usenet Index Post')) {
            return 0;
        }
        for ($open = strpos($subject, '('); $open !== false; $open = strpos($subject, '(', $open + 1)) {
            if ($open === 0 || strspn($subject, self::WHITESPACE, $open - 1, 1) === 0) {
                continue;
            }
            $part = strspn($subject, self::DIGITS, $open + 1);
            $totalAt = $open + 2 + $part;
            if ($part === 0 || ($subject[$totalAt - 1] ?? '') !== '/') {
                continue;
            }
            $total = strspn($subject, self::DIGITS, $totalAt);
            if ($total === 0 || ($subject[$totalAt + $total] ?? '') !== ')') {
                continue;
            }

            return (int) substr($subject, $totalAt, $total) >= 2 && self::hasToken($subject, $open) ? 2 : 1;
        }

        return 0;
    }

    /** Whether `$subject` holds a run of 16+ ASCII alphanumerics before byte `$end`. */
    private static function hasToken(string $subject, int $end): bool
    {
        $at = strcspn($subject, self::ALNUM, 0, $end);
        while ($at < $end) {
            $run = strspn($subject, self::ALNUM, $at, $end - $at);
            if ($run >= 16) {
                return true;
            }
            $at += $run;
            $at += strcspn($subject, self::ALNUM, $at, $end - $at);
        }

        return false;
    }
}
