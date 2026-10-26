<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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
        // Only architectures whose CI job requires the benchmark to qualify (x86_64 and arm64).
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')
            || ! \in_array(php_uname('m'), ['x86_64', 'aarch64', 'arm64'], true)) {
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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 4.93x, p99 3.94x; uncommon mean 5.40x, p99 4.52x); see RustBinariesObfuscationPartHandlerGateBookQueryServiceW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    public const string W66_PER_ARTICLE_OBFUSCATION_FFI_CDEF = <<<'C'
        ssize_t nntmux_w663_per_article_obfuscation_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 66.3 batch. */
    public const int W66_PER_ARTICLE_OBFUSCATION_TRAILER = 17;

    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores W66_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    public static function usesW66RustFfi(): bool
    {
        $override = self::w66RustFfiState();

        return \is_bool($override) ? $override : self::W66_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the NativePerArticleObfuscation Wave 66 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 66.3 per-article obfuscation plan. Each item is `subject|poster|message-id`. The subject's core token is its
     * first longest ASCII alphanumeric run: `hex` when it is at least 16 hex digits, `gibberish` when it is at least 12
     * bytes with letters and digits and either under 20% vowels among its letters or class changes (digit, lower,
     * upper) on at least half its length; `uuid` when the subject holds an 8-4-4-4-12 hex UUID. The first
     * `(\d{1,6}/\d{1,6})` yEnc marker gives the part state (0 none, 1 valid, 2 zero or part > total) with first and
     * final bits. The trimmed poster is 0 empty, 3 a hex local part of at least 8 bytes, 1 `local@domain.tld` or 2
     * other; the trimmed message-id is 0 empty, 1 `<local@domain>` or 2 other, `random` when a well-formed local part
     * is at least 16 alphanumerics. Score = hex 40 + uuid 40 + gibberish 25 + 15 for a core of at least 12 bytes whose
     * distinct bytes (the entropy proxy) cover half its length + 10 for a hex poster + 5 for a random message-id; the
     * decision is 1 obfuscated (>= 50), 2 suspect (>= 25) or 0 clean. Per item `subjectLength (cap 4095) | coreLength
     * (cap 255) << 12 | distinctSubjectBytes (cap 255) << 20 | hex << 28 | uuid << 29 | gibberish << 30 | partState
     * << 31 | first << 33 | final << 34 | poster << 35 | messageId << 37 | random << 39 | score << 40 | decision <<
     * 48`. Then the three decision counts, the hex, uuid and gibberish counts, the four poster and three message-id
     * counts, the score sum, the highest score, the 1-based index of the first obfuscated item (0 none) and the
     * distinct non-empty poster count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66PerArticleObfuscationPlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66PerArticleObfuscationPlanBatchNative($items) : self::w66PerArticleObfuscationPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66PerArticleObfuscationPlanBatchNative(array $items): array
    {
        return self::w66Invoke($items, \count($items) + self::W66_PER_ARTICLE_OBFUSCATION_TRAILER) ?? self::w66PerArticleObfuscationPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66PerArticleObfuscationPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0];
        $posters = [0, 0, 0, 0];
        $mids = [0, 0, 0];
        $hexCount = $uuidCount = $gibberishCount = $scoreSum = $maxScore = $firstObfuscated = $index = 0;
        $distinctPosters = [];
        foreach ($items as $item) {
            $index++;
            [$subject, $rawPoster, $rawMid] = explode('|', (string) $item, 3) + ['', '', ''];
            $core = '';
            if (preg_match_all('/[A-Za-z0-9]+/', $subject, $runs) > 0) {
                foreach ($runs[0] as $run) {
                    if (\strlen($run) > \strlen($core)) {
                        $core = $run;
                    }
                }
            }
            $coreLength = \strlen($core);
            $hex = $coreLength >= 16 && strspn($core, self::W66_HEX) === $coreLength;
            $uuid = preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $subject) === 1;
            $letters = $digits = $vowels = $transitions = 0;
            $lastClass = 3;
            for ($i = 0; $i < $coreLength; $i++) {
                $byte = \ord($core[$i]);
                if ($byte <= 57) {
                    $digits++;
                    $class = 0;
                } else {
                    $letters++;
                    $vowels += str_contains('aeiouAEIOU', $core[$i]) ? 1 : 0;
                    $class = $byte >= 97 ? 1 : 2;
                }
                if ($i > 0 && $class !== $lastClass) {
                    $transitions++;
                }
                $lastClass = $class;
            }
            $gibberish = $coreLength >= 12 && $digits > 0 && $letters > 0 && ($vowels * 5 < $letters || $transitions * 2 >= $coreLength);
            $partState = 0;
            $first = $final = false;
            if (preg_match('/\((\d{1,6})\/(\d{1,6})\)/', $subject, $marker) === 1) {
                [$part, $total] = [(int) $marker[1], (int) $marker[2]];
                if ($part >= 1 && $total >= 1 && $part <= $total) {
                    [$partState, $first, $final] = [1, $part === 1, $part === $total];
                } else {
                    $partState = 2;
                }
            }
            $poster = trim($rawPoster);
            $at = strpos($poster, '@');
            $local = $at === false ? $poster : substr($poster, 0, $at);
            $localLength = \strlen($local);
            if ($poster === '') {
                $posterKind = 0;
            } elseif ($localLength >= 8 && strspn($local, self::W66_HEX) === $localLength) {
                $posterKind = 3;
            } elseif ($at !== false && $at > 0 && str_contains(substr($poster, $at + 1), '.')) {
                $posterKind = 1;
            } else {
                $posterKind = 2;
            }
            $mid = trim($rawMid);
            $midLength = \strlen($mid);
            $midState = $midLength === 0 ? 0 : 2;
            $random = false;
            if ($midLength >= 3 && $mid[0] === '<' && $mid[$midLength - 1] === '>') {
                $inner = substr($mid, 1, -1);
                $p = strpos($inner, '@');
                if ($p !== false && $p > 0 && $p + 1 < \strlen($inner)) {
                    $midState = 1;
                    $random = $p >= 16 && strspn($inner, self::W66_ALNUM, 0, $p) === $p;
                }
            }
            $score = ($hex ? 40 : 0) + ($uuid ? 40 : 0) + ($gibberish ? 25 : 0)
                + ($coreLength >= 12 && \count(count_chars($core, 1)) * 2 >= $coreLength ? 15 : 0)
                + ($posterKind === 3 ? 10 : 0) + ($random ? 5 : 0);
            $decision = $score >= 50 ? 1 : ($score >= 25 ? 2 : 0);
            $decisions[$decision]++;
            $posters[$posterKind]++;
            $mids[$midState]++;
            $hexCount += $hex ? 1 : 0;
            $uuidCount += $uuid ? 1 : 0;
            $gibberishCount += $gibberish ? 1 : 0;
            $scoreSum += $score;
            $maxScore = max($maxScore, $score);
            if ($decision === 1 && $firstObfuscated === 0) {
                $firstObfuscated = $index;
            }
            if ($poster !== '') {
                $distinctPosters[$poster] = true;
            }
            $out[] = min(\strlen($subject), 4095) | min($coreLength, 255) << 12 | min(\count(count_chars($subject, 1)), 255) << 20
                | ($hex ? 1 : 0) << 28 | ($uuid ? 1 : 0) << 29 | ($gibberish ? 1 : 0) << 30 | $partState << 31 | ($first ? 1 : 0) << 33
                | ($final ? 1 : 0) << 34 | $posterKind << 35 | $midState << 37 | ($random ? 1 : 0) << 39 | $score << 40 | $decision << 48;
        }
        array_push($out, ...$decisions);
        array_push($out, $hexCount, $uuidCount, $gibberishCount, ...$posters);
        array_push($out, ...$mids);
        array_push($out, $scoreSum, $maxScore, $firstObfuscated, \count($distinctPosters));

        return $out;
    }

    private const string W66_HEX = '0123456789abcdefABCDEF';

    private const string W66_ALNUM = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Wave 66 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('binaries_w663', self::W66_PER_ARTICLE_OBFUSCATION_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 66.3 NativePerArticleObfuscation export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w66Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w66RustFfiState('ffi');
            $out = self::w66RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w66RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W66_PER_ARTICLE_OBFUSCATION_FFI_CDEF.)
            $written = $ffi->nntmux_w663_per_article_obfuscation_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
