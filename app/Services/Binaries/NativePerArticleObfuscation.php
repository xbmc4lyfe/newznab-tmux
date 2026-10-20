<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use FFI;
use Throwable;

/**
 * Rust port of {@see HeaderParser::markPerArticleObfuscated()}, adopted after
 * scripts/benchmark-per-article-obfuscation.php measured it at about 1.7-2.9x the PHP rule on the ranges it is routed to (warm and cold) with identical
 * marks. Null means "use PHP": not a Linux CLI with FFI, library missing, failing its known-answer
 * probe, or input the native side declines (a subject longer than MAX_SUBJECT_LENGTH, where PHP's
 * backtrack limit could stop the subject pattern).
 */
final class NativePerArticleObfuscation
{
    /** Mirrors MAX_SUBJECT_LENGTH in the Rust port; longer subjects are declined before packing. */
    public const int MAX_SUBJECT_LENGTH = 512;

    /** The library's `usize::MAX - 1` as PHP's signed int: Rust declined over the article numbers. */
    private const int NUMBERS_DECLINED = -2;

    /** Mirrors HeaderParser::NATIVE_MIN_HEADERS: smaller ranges stay on PHP. */
    public const int MIN_HEADERS = HeaderParser::NATIVE_MIN_HEADERS;

    /** @var array<string, FFI|null> */
    private static array $libraries = [];

    private ?FFI $library = null;

    private bool $resolved = false;

    public function __construct(private readonly ?string $libraryPath = null) {}

    /** Whether a verified native library backs this instance (loading it on first call). */
    public function available(): bool
    {
        return $this->library() !== null;
    }

    /** Whether the library is already loaded and verified, without loading it. */
    public function loaded(): bool
    {
        return $this->library !== null;
    }

    /** Whether loading was tried and failed, so only PHP remains. */
    public function unavailable(): bool
    {
        return $this->resolved && $this->library === null;
    }

    /**
     * Article numbers the PHP rule would mark, or null to keep the PHP result: the range is routed
     * with PerArticleObfuscationGate::worthNativeMarking() first, and stays on PHP once loading has failed.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return list<int>|null
     */
    public function mark(array $headers, int $minArticles): ?array
    {
        return ! $this->unavailable() && PerArticleObfuscationGate::worthNativeMarking($headers, $this->loaded())
            ? $this->markWithoutSampling($headers, $minArticles) : null;
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
        // `-?[0-9]+`. Only a decline over the numbers is retried, with the filtered loop's ints;
        // any other decline would recur, so PHP decides at once.
        $columns = self::columns($headers, true);
        $marked = $columns === null ? self::NUMBERS_DECLINED : self::invoke($library, $columns, $minArticles);
        if ($marked === self::NUMBERS_DECLINED) {
            $marked = self::invoke($library, self::columns($headers, false), $minArticles);
        }

        return is_array($marked) ? $marked : null;
    }

    /**
     * Marked numbers, NUMBERS_DECLINED when Rust rejected a number, or null for any other decline.
     *
     * @param  array{list<int|string>, list<string>}  $columns
     * @return list<int>|int|null
     */
    private static function invoke(FFI $library, array $columns, int $minArticles): array|int|null
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
            if ($marked === self::NUMBERS_DECLINED) {
                return self::NUMBERS_DECLINED;
            }
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
