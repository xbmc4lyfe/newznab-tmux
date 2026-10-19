<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use FFI;
use Throwable;

/**
 * Rust port of {@see HeaderParser::markPerArticleObfuscated()}, adopted after
 * scripts/benchmark-per-article-obfuscation.php measured it at 2-3x the PHP rule with identical
 * marks. Null means "use PHP": not a Linux CLI with FFI, library missing, failing its known-answer
 * probe, or input the native side declines (a subject longer than MAX_SUBJECT_LENGTH, where PHP's
 * backtrack limit could stop the subject pattern, or an integer field PHP would saturate).
 */
final class NativePerArticleObfuscation
{
    /** Mirrors MAX_SUBJECT_LENGTH in the Rust port; longer subjects are declined before packing. */
    public const int MAX_SUBJECT_LENGTH = 512;

    /** Headers sampled to decide whether a range is worth marshalling to Rust. */
    private const int SAMPLE_SIZE = 128;

    /** Smaller ranges stay on PHP: Rust's margin there is under a millisecond and not reliably 1.5x. */
    public const int MIN_HEADERS = 1000;

    /** @var array<string, FFI|null> */
    private static array $libraries = [];

    private ?FFI $library = null;

    private bool $resolved = false;

    public function __construct(private readonly ?string $libraryPath = null) {}

    /** Whether a verified native library backs this instance. */
    public function available(): bool
    {
        return $this->library() !== null;
    }

    /**
     * Article numbers the PHP rule would mark, or null to keep the PHP result. Two kinds of range
     * stay on PHP. Ranges under MIN_HEADERS stay because Rust's margin there is too small to
     * establish. Ranges where under a sixteenth of a spread sample is a multi-part subject with a
     * 16+ alphanumeric token in its name stay too (an eighth when most samples have no part
     * count), because the PHP rule rejects other subjects faster than they can be packed for FFI.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return list<int>|null
     */
    public function mark(array $headers, int $minArticles): ?array
    {
        return self::worthMarshalling($headers) ? $this->markWithoutSampling($headers, $minArticles) : null;
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
    private static function worthMarshalling(array $headers): bool
    {
        $values = array_values($headers);
        $count = count($values);
        if ($count < self::MIN_HEADERS) {
            return false;
        }
        // An odd step started mid-stride, so a range alternating at an even period is not seen
        // in one phase only.
        $step = intdiv($count, self::SAMPLE_SIZE) | 1;
        $sampled = $counted = $candidates = 0;
        for ($i = intdiv($step, 2); $i < $count; $i += $step) {
            $sampled++;
            $kind = self::classify($values[$i]['Subject'] ?? null);
            $counted += $kind > 0 ? 1 : 0;
            $candidates += $kind === 2 ? 1 : 0;
        }

        // PHP rejects subjects without a part count in about one regex, so a range made mostly of
        // them needs twice the candidate share before packing pays off.
        return $candidates * ($counted * 2 >= $sampled ? 16 : 8) >= $sampled;
    }

    /**
     * 0: no part count; 1: a part count only; 2: a candidate as the PHP rule sees it, a first
     * whitespace-led `(n/total)` with total of at least 2 and a 16+ alphanumeric token before it.
     * Both patterns run in linear time (the int cast drops leading zeros, so no `0*` overlaps `\d+`).
     */
    private static function classify(mixed $subject): int
    {
        if (! is_string($subject) || preg_match('/\s\(\d+\/(\d+)\)/', $subject, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return 0;
        }

        return (int) $match[1][0] >= 2 && preg_match('/[A-Za-z0-9]{16}/', substr($subject, 0, $match[0][1])) === 1 ? 2 : 1;
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
                'size_t nntmux_per_article_obfuscation_v1(const char *records, size_t records_len, size_t min_articles,'
                .' char *output, size_t output_capacity);',
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
        $records = '';
        $count = 0;
        foreach ($headers as $header) {
            if (! isset($header['Number'], $header['Subject']) || ! is_numeric($header['Number']) || ! is_string($header['Subject'])) {
                continue;
            }
            if (strlen($header['Subject']) > self::MAX_SUBJECT_LENGTH) {
                return null;
            }
            $records .= pack('PV', (int) $header['Number'], strlen($header['Subject'])).$header['Subject'];
            $count++;
        }
        if ($count === 0) {
            return [];
        }
        try {
            $output = $library->new('char['.($count * 8).']');
            // @phpstan-ignore method.notFound
            $marked = $library->nntmux_per_article_obfuscation_v1($records, strlen($records), $minArticles, $output, $count * 8);
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
}
