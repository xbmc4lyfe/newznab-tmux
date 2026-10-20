<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use FFI;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Throwable;

/**
 * Rust port of {@see HeaderParser::markPerArticleObfuscated()}, adopted after
 * scripts/benchmark-per-article-obfuscation.php measured it at 1.7-3.6x the PHP rule on the ranges it is routed to (warm and cold) with identical
 * marks. Null means "use PHP": not a Linux CLI with FFI, library missing, failing its known-answer
 * probe, or input the native side declines (a subject longer than MAX_SUBJECT_LENGTH, where PHP's
 * backtrack limit could stop the subject pattern).
 */
final class NativePerArticleObfuscation
{
    /** Mirrors MAX_SUBJECT_LENGTH in the Rust port; longer subjects are declined before packing. */
    public const int MAX_SUBJECT_LENGTH = 512;

    /** Headers sampled to decide whether a range is worth marshalling to Rust: one in 128, 32 to 128. */
    private const int SAMPLE_MIN = 32;

    private const int SAMPLE_MAX = 128;

    /**
     * Smaller ranges stay on PHP: there the PHP rule takes well under a millisecond, and even the
     * sample would cost several percent of it.
     */
    public const int MIN_HEADERS = 4000;

    /**
     * Per-header cost by sampled class (no part count, a part count only, a candidate) as
     * [PHP base ns, PHP tenths of a ns per subject byte, Rust base ns, Rust tenths of a ns per byte].
     * Fitted to XOVER-shaped ranges of 60-480 byte subjects with
     * scripts/benchmark-per-article-obfuscation.php on Linux arm64.
     */
    private const array COSTS = [[210, 5, 265, 10], [80, 80, 110, 39], [1350, 60, 330, 37]];

    /**
     * A range goes to Rust when its predicted speedup reaches 2x. The prices run about a sixth
     * optimistic on mixed ranges, so this keeps the measured speedup above the 1.5x rule.
     */
    private const int PREDICTED_SPEEDUP_PERCENT = 200;

    /**
     * Loading the library and running its probe took 0.6-1.8 ms per process; a range that would
     * load it must be predicted to save at least 4 ms.
     */
    private const int FIRST_LOAD_SAVING_NS = 4_000_000;

    private const string DIGITS = '0123456789';

    private const string ALNUM = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /** PCRE's non-UTF `\s`. */
    private const string WHITESPACE = " \t\n\v\f\r";

    /** @var array<string, FFI|null> */
    private static array $libraries = [];

    /** Sample positions only need to be unpredictable per range, not secure: one seeded engine per process. */
    private static ?Randomizer $sampler = null;

    private ?FFI $library = null;

    private bool $resolved = false;

    public function __construct(private readonly ?string $libraryPath = null)
    {
        // Seeded here, with the service, so a process's first range does not pay for it.
        self::$sampler ??= new Randomizer(new Xoshiro256StarStar);
    }

    /** Whether a verified native library backs this instance. */
    public function available(): bool
    {
        return $this->library() !== null;
    }

    /**
     * Article numbers the PHP rule would mark, or null to keep the PHP result. These ranges stay
     * on PHP:
     * - under MIN_HEADERS, where Rust's margin is too small to establish;
     * - before the library is loaded, where the predicted saving is under FIRST_LOAD_SAVING_NS;
     * - where a spread sample, priced with COSTS, predicts under 2x: the PHP rule rejects
     *   subjects without a part count faster than they can be handed to Rust;
     * - with any subject over MAX_SUBJECT_LENGTH, which Rust would decline.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return list<int>|null
     */
    public function mark(array $headers, int $minArticles): ?array
    {
        return $this->worthMarshalling($headers) ? $this->markWithoutSampling($headers, $minArticles) : null;
    }

    /**
     * The Rust result for every range, without the sampling that routes token-poor ranges to PHP
     * (parity tests).
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return list<int>|null
     */
    public function markWithoutSampling(array $headers, int $minArticles): ?array
    {
        $library = $this->library();

        return $library === null ? null : self::call($library, $headers, $minArticles);
    }

    /** @param  array<int, array<string, mixed>>  $headers */
    private function worthMarshalling(array $headers): bool
    {
        // A library that failed to load or probe leaves only PHP; sampling it would be wasted.
        if (count($headers) < self::MIN_HEADERS || ($this->resolved && $this->library === null)) {
            return false;
        }
        $values = array_is_list($headers) ? $headers : array_values($headers);
        $count = count($values);
        // One random header from each of $samples equal slices, so no periodic ordering of the
        // range decides the prediction.
        $samples = min(self::SAMPLE_MAX, max(self::SAMPLE_MIN, intdiv($count, 128)));
        $random = self::$sampler ??= new Randomizer(new Xoshiro256StarStar);
        $php = $rust = 0;
        for ($slice = 0; $slice < $samples; $slice++) {
            $start = intdiv($slice * $count, $samples);
            $index = $random->getInt($start, max($start, intdiv(($slice + 1) * $count, $samples) - 1));
            $subject = $values[$index]['Subject'] ?? null;
            $bytes = is_string($subject) ? strlen($subject) : 0;
            [$phpBase, $phpPerByte, $rustBase, $rustPerByte] = self::COSTS[self::classify($subject)];
            $php += $phpBase + intdiv($phpPerByte * $bytes, 10);
            $rust += $rustBase + intdiv($rustPerByte * $bytes, 10);
        }
        if ($php * 100 < $rust * self::PREDICTED_SPEEDUP_PERCENT) {
            return false;
        }
        // Before this process has loaded the library, the predicted saving must also pay for
        // loading it and running its probe.
        if (! $this->resolved && intdiv(($php - $rust) * $count, $samples) < self::FIRST_LOAD_SAVING_NS) {
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
    private static function classify(mixed $subject): int
    {
        if (! is_string($subject)) {
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

    private function library(): ?FFI
    {
        if (! $this->resolved) {
            $this->library = $this->load();
            $this->resolved = true;
        }

        return $this->library;
    }

    private function load(): ?FFI
    {
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
            return null;
        }
        try {
            $path = $this->libraryPath ?? (string) config('yenc.native_library', '');
        } catch (Throwable) {
            return null;
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $key = getmypid().':'.$resolved;
        if (array_key_exists($key, self::$libraries)) {
            return self::$libraries[$key];
        }
        self::$libraries[$key] = null;
        try {
            $library = FFI::cdef(
                'size_t nntmux_per_article_obfuscation_v2(const char *numbers, size_t numbers_len, const char *lengths,'
                .' size_t count, const char *subjects, size_t subjects_len, size_t min_articles, char *output,'
                .' size_t output_capacity);',
                $resolved,
            );
            $probe = [];
            for ($i = 0; $i < 20; $i++) {
                $probe[] = ['Number' => 100 + $i, 'Subject' => '[40/62] "'.md5((string) $i).'" yEnc ('.($i + 2).'/687)'];
            }
            $probe[] = ['Number' => 200, 'Subject' => '[40/62] "'.md5('first').'" yEnc (1/687)'];
            if (self::call($library, $probe, 16) !== range(100, 119)) {
                return null;
            }
            self::$libraries[$key] = $library;

            return $library;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $headers
     * @return list<int>|null
     */
    private static function call(FFI $library, array $headers, int $minArticles): ?array
    {
        if ($minArticles < 1) {
            return [];
        }
        // XOVER rows carry numbers as strings: hand the column over as text and let Rust parse
        // `-?[0-9]+`. Any other form makes Rust decline, and the filtered loop retries with ints.
        $columns = self::columns($headers, true);
        $marked = $columns === null ? null : self::invoke($library, $columns, $minArticles);

        return $marked ?? self::invoke($library, self::columns($headers, false), $minArticles);
    }

    /**
     * @param  array{list<int|string>, list<string>}  $columns
     * @return list<int>|null
     */
    private static function invoke(FFI $library, array $columns, int $minArticles): ?array
    {
        [$numbers, $subjects] = $columns;
        $count = count($subjects);
        if ($count === 0) {
            return [];
        }
        $lengths = array_map(strlen(...), $subjects);
        if (max($lengths) > self::MAX_SUBJECT_LENGTH) {
            return null;
        }
        $numberText = implode("\n", $numbers);
        $subjectBytes = implode('', $subjects);
        try {
            $output = $library->new('char['.($count * 8).']');
            // @phpstan-ignore method.notFound
            $marked = $library->nntmux_per_article_obfuscation_v2(
                $numberText, strlen($numberText), pack('V*', ...$lengths), $count, $subjectBytes, strlen($subjectBytes),
                $minArticles, $output, $count * 8,
            );
            if (! is_int($marked) || $marked < 0 || $marked > $count) {
                return null;
            }
            if ($marked === 0) {
                return [];
            }
            $numbers = unpack('q*', FFI::string($output, $marked * 8));
        } catch (Throwable) {
            return null;
        }

        return $numbers === false ? null : array_values(array_map('intval', $numbers));
    }

    /**
     * Article numbers and subjects of the headers the PHP rule reads (a numeric Number and a string
     * Subject), in order. In bulk, whole columns are taken as they are when every header has both
     * keys, a string Subject and a string or int Number (null otherwise); the filtered form casts each Number with (int).
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return ($bulk is true ? array{list<int|string>, list<string>}|null : array{list<int>, list<string>})
     */
    private static function columns(array $headers, bool $bulk): ?array
    {
        if ($bulk) {
            $count = count($headers);
            $numbers = array_column($headers, 'Number');
            $subjects = array_column($headers, 'Subject');

            // Numbers must all be strings or all ints: implode would turn true into "1", which the
            // PHP rule skips as non-numeric.
            return count($numbers) === $count && count($subjects) === $count
                && count(array_filter($subjects, is_string(...))) === $count
                && (count(array_filter($numbers, is_string(...))) === $count || count(array_filter($numbers, is_int(...))) === $count)
                ? [$numbers, $subjects] : null;
        }
        $numbers = $subjects = [];
        foreach ($headers as $header) {
            if (isset($header['Number'], $header['Subject']) && is_numeric($header['Number']) && is_string($header['Subject'])) {
                $numbers[] = (int) $header['Number'];
                $subjects[] = $header['Subject'];
            }
        }

        return [$numbers, $subjects];
    }
}
