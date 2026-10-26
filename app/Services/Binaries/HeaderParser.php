<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\BlacklistService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Parses and filters raw NNTP headers.
 */
final class HeaderParser
{
    /**
     * Base name, part number and part total of a yEnc subject such as `name (12/45)`.
     */
    private const string SUBJECT_PATTERN = '/^\s*(?!Usenet Index Post)(.+?)\s+\((\d+)\/(\d+)\)/';

    /**
     * File counter inside a subject base name, such as `[03/62]` or `(3 of 62)`.
     */
    public const string FILE_COUNTER_PATTERN = '/[[(\s](\d{1,5})(\/|[\s_]of[\s_]|-)(\d{1,5})[])[\s$:]/i';

    private const string FFI_CDEF = <<<'C'
        int64_t nntmux_header_parse_subject(const char *input, size_t len);
        int64_t nntmux_header_parse_batch(const char *input, size_t len, size_t count, char *out, size_t cap);
        C;

    private FFI|false|null $subjectFfi = null;

    private BlacklistService $blacklistService;

    private int $notYEnc = 0;

    private int $blacklisted = 0;

    private int $obfuscated = 0;

    /** @var array<int, true> Article numbers whose subject is randomised per article. */
    private array $perArticleObfuscated = [];

    /**
     * Mirrors NativePerArticleObfuscation::MIN_HEADERS. Smaller ranges stay on PHP without loading
     * any routing or Rust class (each costs a compile per process without opcache).
     */
    public const int NATIVE_MIN_HEADERS = 16000;

    /** Rust port of markPerArticleObfuscated(); false keeps the PHP rule only, null builds it on first need. */
    private NativePerArticleObfuscation|false|null $native;

    public function __construct(
        ?BlacklistService $blacklistService = null,
        NativePerArticleObfuscation|false|null $native = null,
        private readonly ?string $libraryPath = null,
        private readonly bool $useRust = true
    ) {
        $this->blacklistService = $blacklistService ?? new BlacklistService;
        $this->native = $native;
    }

    /**
     * Reset counters for a new batch.
     */
    public function reset(): void
    {
        $this->notYEnc = 0;
        $this->blacklisted = 0;
        $this->obfuscated = 0;
    }

    /**
     * Find articles whose subject is randomised per article across a downloaded range.
     *
     * Some uploaders (nyuu's article obfuscation) give every article its own random
     * subject token, and often its own random poster, so no two parts of a file share
     * a binary or a collection. Each such article becomes a one-part collection that
     * can never complete. Multi-part articles whose base name holds a random-looking
     * token (16+ alphanumerics) are grouped by the subject's shape (alphanumeric runs
     * collapsed), part total and file counter; a group of at least $minArticles in
     * which at least 90% of base names are distinct (digit runs outside the token
     * ignored) has its once-seen names, other than first and last parts, skipped by
     * parse() when they span at least $minArticles part positions. A
     * legitimate upload repeats its base name for every part, which keeps its group
     * out. Pass 0 to disable, as part repair does for its sparse ranges.
     *
     * @param  array<int, array<string, mixed>>  $headers  Raw headers from NNTP
     * @return int Number of articles marked
     */
    public function markPerArticleObfuscated(array $headers, int $minArticles): int
    {
        $this->perArticleObfuscated = [];
        if ($minArticles < 1) {
            return 0;
        }
        // The Rust port marks the same articles about 1.7-2.9x faster on ranges it is routed to (scripts/benchmark-per-article-obfuscation.php);
        // null means it is unavailable or declined, and the PHP rule below decides.
        // Routing runs before the wrapper exists, so a range kept on PHP never loads its class.
        $marked = null;
        if ($this->native !== false && \count($headers) >= self::NATIVE_MIN_HEADERS && ! ($this->native?->unavailable() ?? false)
            && PerArticleObfuscationGate::worthNativeMarking($headers, $this->native?->loaded() ?? false)) {
            $this->native ??= new NativePerArticleObfuscation;
            $marked = $this->native->markWithoutSampling($headers, $minArticles);
        }
        if ($marked !== null) {
            $this->perArticleObfuscated = array_fill_keys($marked, true);

            return \count($this->perArticleObfuscated);
        }

        /** @var array<string, array{names: array<string, list<array{int, int}>>, articles: int, total: int}> $signatures */
        $signatures = [];
        foreach ($headers as $header) {
            if (! isset($header['Number'], $header['Subject']) || ! is_numeric($header['Number'])
                || ! is_string($header['Subject'])
                || ! preg_match(self::SUBJECT_PATTERN, $header['Subject'], $matches)
                || (int) $matches[3] < 2
                || ! preg_match('/[A-Za-z0-9]{16,}/', $matches[1])) {
                continue;
            }

            // Digit runs outside the long tokens are ignored, so numbered volumes
            // (`<hash>.part07.rar`) of one upload share a name while random tokens stay whole.
            $name = implode('', array_map(
                static fn (string $piece): string => preg_match('/^[A-Za-z0-9]{16,}$/', $piece) === 1
                    ? $piece
                    : (preg_replace('/\d+/', '', $piece) ?? $piece),
                preg_split('/([A-Za-z0-9]{16,})/', $matches[1], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$matches[1]]
            ));
            $fileCounter = preg_match(self::FILE_COUNTER_PATTERN, $matches[1], $counter) ? $counter[1].'/'.$counter[3] : '';
            $key = (preg_replace('/[A-Za-z0-9]+/', 'x', $matches[1]) ?? $matches[1])."\0".$matches[3]."\0".$fileCounter;
            // Write in place: copying the growing arrays per article would be quadratic.
            $signatures[$key] ??= ['names' => [], 'articles' => 0, 'total' => (int) $matches[3]];
            $signatures[$key]['names'][$name][] = [(int) $header['Number'], (int) $matches[2]];
            $signatures[$key]['articles']++;
        }

        foreach ($signatures as $signature) {
            $articles = $signature['articles'];
            // A stray duplicate line must not shield the group; a real upload repeats on nearly every part.
            if ($articles < $minArticles || \count($signature['names']) * 10 < $articles * 9) {
                continue;
            }
            // Only once-seen names are skipped, so a real upload sharing the group keeps its
            // repeated parts. A first or last part is kept too: a real upload cut by the range
            // boundary can show just that one article, and part repair would never restore it.
            $candidates = [];
            foreach ($signature['names'] as $articlesForName) {
                [$number, $part] = $articlesForName[0];
                if (\count($articlesForName) === 1 && $part !== 1 && $part !== $signature['total']) {
                    $candidates[$number] = $part;
                }
            }
            // Obfuscated articles spread over many part positions. A slice of files posted
            // round-robin, each with its own random name, shows one article per file at only
            // one or two positions (more positions would repeat each name).
            if (\count(array_unique($candidates)) < $minArticles) {
                continue;
            }
            foreach ($candidates as $number => $part) {
                $this->perArticleObfuscated[$number] = true;
            }
        }

        return \count($this->perArticleObfuscated);
    }

    /**
     * Article numbers marked by the last markPerArticleObfuscated() call.
     *
     * @return list<int>
     */
    public function perArticleObfuscatedNumbers(): array
    {
        return array_keys($this->perArticleObfuscated);
    }

    /**
     * Preserve the original PCRE captures, including raw digit strings and yEnc normalization.
     *
     * @return array<int, string>|null
     */
    public function parseSubject(string $subject): ?array
    {
        // Paired caller benchmarks keep short subjects and negative tail probes on PCRE.
        $ffi = $this->useRust && self::worthNativeSubject($subject) ? $this->subjectLibrary() : null;
        if ($ffi !== null) {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_header_parse_subject($subject, \strlen($subject));
            if (\is_int($packed) && $packed >= 0) {
                return self::subjectCaptures($subject, $packed);
            }
        }
        if (! preg_match(self::SUBJECT_PATTERN, $subject, $matches)) {
            return null;
        }
        if (stripos($subject, 'yEnc') === false) {
            $matches[1] .= ' yEnc';
        }

        return $matches;
    }

    private static function worthNativeSubject(string $subject): bool
    {
        return self::nativeSubjectShape($subject)
            && (int) ini_get('pcre.backtrack_limit') >= 1000000
            && (int) ini_get('pcre.recursion_limit') >= 100000
            && \in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true);
    }

    private static function nativeSubjectShape(string $subject): bool
    {
        $length = \strlen($subject);

        return $length >= 768 && $length <= 2048 && $subject[$length - 1] === ')'
            && preg_match('/\\([0-9]+\\/[0-9]+\\)$/D', $subject) === 1;
    }

    private function subjectLibrary(): ?FFI
    {
        if (! $this->useRust) {
            return null;
        }
        if ($this->subjectFfi === null) {
            $this->subjectFfi = RustPipelineLibrary::resolveDomain('header_parser', self::FFI_CDEF, $this->libraryPath) ?? false;
        }

        return $this->subjectFfi === false ? null : $this->subjectFfi;
    }

    /** @return array<int, string>|null */
    private static function subjectCaptures(string $subject, int $packed): ?array
    {
        if ($packed === 0) {
            return null;
        }
        $start = $packed & 255;
        $length = ($packed >> 8) & 65535;
        $partLength = ($packed >> 32) & 255;
        $totalLength = ($packed >> 40) & 255;
        $partStart = $start + $length + (($packed >> 24) & 255) + 1;
        $totalStart = $partStart + $partLength + 1;

        return [
            substr($subject, 0, $totalStart + $totalLength + 1),
            substr($subject, $start, $length).(($packed >> 48) === 1 ? ' yEnc' : ''),
            substr($subject, $partStart, $partLength),
            substr($subject, $totalStart, $totalLength),
        ];
    }

    /**
     * Copy results before returning: blacklist calls may reuse the shared scratch buffer.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return array<int, int>|null
     */
    private function batchSubjects(array $headers): ?array
    {
        if (\count($headers) < 32) {
            return null;
        }
        $first = reset($headers);
        if (! isset($first['Subject']) || ! \is_string($first['Subject']) || ! self::worthNativeSubject($first['Subject'])) {
            return null;
        }
        $ffi = $this->subjectLibrary();
        if ($ffi === null) {
            return null;
        }
        $framed = '';
        foreach ($headers as $header) {
            if (! isset($header['Subject']) || ! \is_string($header['Subject']) || ! self::nativeSubjectShape($header['Subject'])) {
                return null;
            }
            $framed .= pack('V', \strlen($header['Subject'])).$header['Subject'];
        }
        $needed = \count($headers) * 8;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $needed);
        if ($scratch === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_header_parse_batch($framed, \strlen($framed), \count($headers), $scratch, $needed);
        if ($written !== $needed) {
            return null;
        }
        /** @var array<int, int> $packed */
        $packed = unpack('q*', FFI::string($scratch, $needed));

        return $packed;
    }

    /**
     * Parse and filter raw headers from NNTP.
     *
     * @param  array<int, array<string, mixed>>  $headers  Raw headers from NNTP
     * @param  string  $groupName  The newsgroup name
     * @param  bool  $partRepair  Whether this is a part repair scan
     * @param  array<int, mixed>|null  $missingParts  Missing part numbers if part repair
     * @return array<string, mixed> Filtered and parsed headers with article info
     */
    public function parse(
        array $headers,
        string $groupName,
        bool $partRepair = false,
        ?array $missingParts = null
    ): array {
        $parsed = [];
        $headersRepaired = [];
        $receivedNumbers = [];
        $missingPartSet = $missingParts === null
            ? null
            : (array_is_list($missingParts)
                ? array_fill_keys(array_map('intval', $missingParts), true)
                : $missingParts);

        $nativeSubjects = $this->useRust && ! $partRepair && $this->perArticleObfuscated === []
            ? $this->batchSubjects($headers) : null;
        $subjectIndex = 0;
        foreach ($headers as $header) {
            $subjectIndex++;
            // Check if we got the article
            if (! isset($header['Number'])) {
                continue;
            }

            $receivedNumbers[] = $header['Number'];

            // For part repair, only process missing parts
            if ($partRepair && $missingPartSet !== null) {
                if (! isset($missingPartSet[(int) $header['Number']])) {
                    continue;
                }
                $headersRepaired[] = $header['Number'];
            }

            if (isset($this->perArticleObfuscated[(int) $header['Number']])) {
                $this->obfuscated++;

                continue;
            }

            // Parse subject to get base name and part/total like "(12/45)"
            if ($nativeSubjects !== null && $nativeSubjects[$subjectIndex] >= 0) {
                $matches = self::subjectCaptures($header['Subject'], $nativeSubjects[$subjectIndex]);
                if ($matches === null) {
                    $this->notYEnc++;

                    continue;
                }
            } else {
                if (! preg_match(self::SUBJECT_PATTERN, $header['Subject'], $matches)) {
                    $this->notYEnc++;

                    continue;
                }
                if (stripos($header['Subject'], 'yEnc') === false) {
                    $matches[1] .= ' yEnc';
                }
            }

            $header['matches'] = $matches;

            // Filter subject based on black/white list
            if ($this->blacklistService->isBlackListed($header, $groupName)) {
                $this->blacklisted++;

                continue;
            }

            // Ensure Bytes is set
            if (empty($header['Bytes'])) {
                $header['Bytes'] = $header[':bytes'] ?? 0;
            }

            $parsed[] = $header;
        }

        return [
            'headers' => $parsed,
            'repaired' => $headersRepaired,
            'received' => $receivedNumbers,
            'notYEnc' => $this->notYEnc,
            'blacklisted' => $this->blacklisted,
            'obfuscated' => $this->obfuscated,
        ];
    }

    /**
     * Update blacklist last_activity for matched rules.
     */
    public function flushBlacklistUpdates(): void
    {
        $ids = $this->blacklistService->getAndClearIdsToUpdate();
        if (! empty($ids)) {
            $this->blacklistService->updateBlacklistUsage($ids); // @phpstan-ignore argument.type
        }
    }

    /**
     * Get count of non-yEnc headers filtered.
     */
    public function getNotYEncCount(): int
    {
        return $this->notYEnc;
    }

    /**
     * Get count of blacklisted headers.
     */
    public function getBlacklistedCount(): int
    {
        return $this->blacklisted;
    }

    /**
     * Extract highest and lowest article info from headers.
     *
     * Only numbers inside the requested range count: a malformed overview line can
     * carry a bogus number, and saving it as last_record makes the group re-scan
     * from article 0.
     *
     * @param  array<int, array<string, mixed>>  $headers
     * @return array<string, mixed>
     */
    public function getArticleRange(array $headers, ?int $first = null, ?int $last = null): array
    {
        $result = [];
        $low = $high = null;

        foreach ($headers as $header) {
            if (! isset($header['Number']) || ! is_numeric($header['Number'])) {
                continue;
            }
            $number = (int) $header['Number'];
            if (($first !== null && $number < $first) || ($last !== null && $number > $last)) {
                continue;
            }
            if ($low === null || $number < (int) $low['Number']) {
                $low = $header;
            }
            if ($high === null || $number > (int) $high['Number']) {
                $high = $header;
            }
        }

        if ($low !== null) {
            $result['firstArticleNumber'] = $low['Number'];
            $result['firstArticleDate'] = $low['Date'] ?? null;
            $result['lastArticleNumber'] = $high['Number'];
            $result['lastArticleDate'] = $high['Date'] ?? null;
        }

        return $result;
    }

    /**
     * PHP stays the default for summarizeHeaderBatch(): the paired benchmark in
     * tests/Unit/RustBinariesConfigServiceHandlerHeaderW41ParityTest.php does not clear 1.5x.
     */
    public const bool W411_RUST_FFI_DEFAULT = false;

    private const array W411_HEADER_KEYS = ['headers', 'invalid_rows', 'missing_subject', 'yenc', 'part_counter', 'bad_number', 'min_number', 'max_number'];

    private static ?bool $w411UseRustFfi = null;

    private static FFI|false|null $w411Ffi = null;

    /**
     * Summarizes overview headers (`Subject`, `Number`): a subject that is not a string or trim()s to '' is missing,
     * string subjects containing `yenc` (ASCII case-insensitive) or a `(digits/digits)` part counter are counted, and
     * article numbers that are not positive ints (or 1-18 digit strings) are bad; the smallest and largest good
     * numbers are reported (0 when none). Non-array rows are invalid.
     *
     * @param  array<array-key, mixed>  $headers
     * @return array<string, int>
     */
    public static function summarizeHeaderBatch(array $headers): array
    {
        return (self::usesW411RustFfi() ? self::summarizeHeaderBatchNative($headers) : null)
            ?? self::summarizeHeaderBatchPhp($headers);
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, int>
     */
    public static function summarizeHeaderBatchPhp(array $headers): array
    {
        $summary = array_fill_keys(self::W411_HEADER_KEYS, 0);
        $summary['headers'] = \count($headers);
        $min = null;
        $max = 0;
        foreach ($headers as $header) {
            if (! \is_array($header)) {
                $summary['invalid_rows']++;

                continue;
            }
            $subject = $header['Subject'] ?? null;
            if (\is_string($subject)) {
                $summary['missing_subject'] += (int) (trim($subject) === '');
                $summary['yenc'] += (int) (stripos($subject, 'yenc') !== false);
                $summary['part_counter'] += (int) preg_match('/\(\d+\/\d+\)/', $subject);
            } else {
                $summary['missing_subject']++;
            }
            $number = BinariesService::w411Int($header['Number'] ?? null);
            if ($number === null || $number <= 0) {
                $summary['bad_number']++;
            } else {
                $min = $min === null ? $number : min($min, $number);
                $max = max($max, $number);
            }
        }
        $summary['min_number'] = $min ?? 0;
        $summary['max_number'] = $max;

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, int>|null
     */
    public static function summarizeHeaderBatchNative(array $headers): ?array
    {
        $words = [\count($headers)];
        $blob = [];
        foreach ($headers as $header) {
            if (\is_array($header)) {
                BinariesService::w411PackRow($words, $blob, [$header['Subject'] ?? null, $header['Number'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = BinariesService::w411Invoke(self::$w411Ffi, 4, $words, $blob);

        return $values !== null && \count($values) === \count(self::W411_HEADER_KEYS) ? array_combine(self::W411_HEADER_KEYS, $values) : null;
    }

    public static function useW411RustFfi(bool $enable = true): void
    {
        self::$w411UseRustFfi = $enable;
    }

    public static function usesW411RustFfi(): bool
    {
        return self::$w411UseRustFfi ?? self::W411_RUST_FFI_DEFAULT;
    }

    public static function resetW411RustFfi(): void
    {
        self::$w411UseRustFfi = null;
        self::$w411Ffi = null;
    }

    /** Wave 52.2 paired benchmarks decide this default; see RustBinariesCollectionHeaderMissedPartHandlerW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        intptr_t nntmux_w522_header_subject_plan_batch(const char *subjects, size_t subjects_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 52 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w52', self::W52_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w52RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planSubjects() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * Batch SUBJECT_PATTERN plan, as parse() splits subjects. Three words per subject: `flags |
     * min(strlen(base), 0xFFFFFF) << 8 | crc32(base) << 32`, then `(int)` of the part and of the
     * total (all three zero when the pattern does not match). The base is capture 1, plus ` yEnc`
     * when the subject lacks `yEnc` in any case. Flags: 1 matched, 2 yEnc appended, 4 part above
     * total, 8 part zero, 16 total of at least 2. Matched, unmatched, yEnc-appended and multi-part
     * counts, the largest matched total and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planSubjects(array $subjects): array
    {
        return self::usesW52RustFfi() ? self::planSubjectsNative($subjects) : self::planSubjectsPhp($subjects);
    }

    /**
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planSubjectsPhp(array $subjects): array
    {
        $out = [];
        $matched = $appended = $multi = $maxTotal = 0;
        foreach ($subjects as $subject) {
            if (preg_match(self::SUBJECT_PATTERN, $subject, $matches) !== 1) {
                $out[] = 0;
                $out[] = 0;
                $out[] = 0;

                continue;
            }
            $matched++;
            $base = $matches[1];
            $flags = 1;
            if (stripos($subject, 'yEnc') === false) {
                $base .= ' yEnc';
                $flags |= 2;
                $appended++;
            }
            $part = (int) $matches[2];
            $total = (int) $matches[3];
            $flags |= ($part > $total ? 4 : 0) | ($part === 0 ? 8 : 0);
            if ($total >= 2) {
                $flags |= 16;
                $multi++;
            }
            $maxTotal = max($maxTotal, $total);
            $out[] = $flags | min(\strlen($base), 0xFFFFFF) << 8 | crc32($base) << 32;
            $out[] = $part;
            $out[] = $total;
        }
        $out[] = $matched;
        $out[] = \count($subjects) - $matched;
        $out[] = $appended;
        $out[] = $multi;
        $out[] = $maxTotal;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the input is not a list or a
     * subject holds a NUL byte.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planSubjectsNative(array $subjects): array
    {
        $count = \count($subjects);
        $words = 3 * $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w52RustFfiState('out', $words);
        if ($state === null || ! array_is_list($subjects)) {
            return self::planSubjectsPhp($subjects);
        }
        [$ffi, $out, $cap] = $state;
        $joined = implode("\0", $subjects);
        try {
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w522_header_subject_plan_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planSubjectsPhp($subjects);
    }

    // ---- Wave 66.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 8.55x/8.10x, uncommon 8.92x/8.09x); see RustBinariesCollectionHeaderParserStorageTxMissedPartW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    private const string W66_HEADER_PARSER_FFI_CDEF = <<<'C'
        intptr_t nntmux_w662_header_parser_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W66_HEADER_PARSER_TAIL = 22;

    /**
     * Wave 66 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w66', self::W66_HEADER_PARSER_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w66RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w66HeaderParserPlanBatch() to Rust until reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Routes w66HeaderParserPlanBatch() to PHP until reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores W66_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    public static function usesW66RustFfi(): bool
    {
        $enabled = self::w66RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W66_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w66HeaderParserPlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
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
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderParserPlanBatch(array $rows): array
    {
        return self::usesW66RustFfi() ? self::w66HeaderParserPlanBatchNative($rows) : self::w66HeaderParserPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderParserPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W66_HEADER_PARSER_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w66RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w66HeaderParserPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W66_HEADER_PARSER_FFI_CDEF.)
            $written = $ffi->nntmux_w662_header_parser_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w66HeaderParserPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w66Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w66Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Lowercased month abbreviation => month. */
    private const array W66_MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10,
        'nov' => 11, 'dec' => 12];

    /** Bytes a message id may not hold between its angle brackets. */
    private const string W66_MESSAGE_ID_BAD = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\x7f<>";

    /** Days since 1970-01-01 of a proleptic Gregorian date (year >= 1970). */
    private static function w66DaysFromCivil(int $y, int $m, int $d): int
    {
        $y = $m <= 2 ? $y - 1 : $y;
        $era = intdiv($y, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m > 2 ? $m - 3 : $m + 9) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /** Exactly two ASCII digits, else null. */
    private static function w66Two(string $value): ?int
    {
        return \strlen($value) === 2 && strspn($value, '0123456789') === 2 ? (int) $value : null;
    }

    /**
     * 0 missing, 1 Unix seconds (1 to 10 digits), 2 `[Day,] DD Mon YYYY HH:MM[:SS] [zone]`
     * (space-separated, 1970-2099, zone `+HHMM`, `-HHMM`, GMT, UTC, UT or Z), 3 invalid; with the
     * epoch seconds.
     *
     * @return array{int, int}
     */
    private static function w66XoverDate(string $value): array
    {
        $v = trim($value);
        if ($v === '') {
            return [0, 0];
        }
        $length = \strlen($v);
        if ($length <= 10 && strspn($v, '0123456789') === $length) {
            return [1, (int) $v];
        }
        $comma = strpos($v, ',');
        $tokens = [];
        foreach (explode(' ', $comma !== false && $comma <= 4 ? substr($v, $comma + 1) : $v) as $token) {
            if ($token !== '') {
                if (\count($tokens) === 5) {
                    return [3, 0];
                }
                $tokens[] = $token;
            }
        }
        if (\count($tokens) < 4) {
            return [3, 0];
        }
        [$day, $mon, $year, $time] = $tokens;
        $zone = $tokens[4] ?? null;
        $dayLength = \strlen($day);
        $m = self::W66_MONTHS[strtolower($mon)] ?? 0;
        if ($dayLength > 2 || strspn($day, '0123456789') !== $dayLength || $m === 0 || \strlen($year) !== 4 || strspn($year, '0123456789') !== 4) {
            return [3, 0];
        }
        $timeLength = \strlen($time);
        if (($timeLength !== 5 && $timeLength !== 8) || $time[2] !== ':' || ($timeLength === 8 && $time[5] !== ':')) {
            return [3, 0];
        }
        $h = self::w66Two(substr($time, 0, 2));
        $mi = self::w66Two(substr($time, 3, 2));
        $sec = $timeLength === 8 ? self::w66Two(substr($time, 6, 2)) : 0;
        if ($h === null || $mi === null || $sec === null) {
            return [3, 0];
        }
        $offset = 0;
        if ($zone !== null && ! \in_array(strtolower($zone), ['gmt', 'utc', 'ut', 'z'], true)) {
            $zh = \strlen($zone) === 5 && ($zone[0] === '+' || $zone[0] === '-') ? self::w66Two(substr($zone, 1, 2)) : null;
            $zm = $zh === null ? null : self::w66Two(substr($zone, 3, 2));
            if ($zh === null || $zm === null || $zh > 23 || $zm > 59) {
                return [3, 0];
            }
            $offset = ($zh * 3600 + $zm * 60) * ($zone[0] === '-' ? -1 : 1);
        }
        $d = (int) $day;
        $y = (int) $year;
        if ($d < 1 || $d > 31 || $y < 1970 || $y > 2099 || $h > 23 || $mi > 59 || $sec > 59) {
            return [3, 0];
        }

        return [2, self::w66DaysFromCivil($y, $m, $d) * 86400 + $h * 3600 + $mi * 60 + $sec - $offset];
    }

    /**
     * The last `(part/total)` of a subject, 1 to 6 digits each.
     *
     * @return array{int, int}|null
     */
    private static function w66SubjectPart(string $subject): ?array
    {
        $open = strrpos($subject, '(');
        if ($open === false) {
            return null;
        }
        $rest = substr($subject, $open + 1);
        $slash = strpos($rest, '/');
        $close = strpos($rest, ')');
        if ($slash === false || $close === false || $slash < 1 || $slash > 6 || $close <= $slash + 1 || $close - $slash - 1 > 6) {
            return null;
        }
        $part = substr($rest, 0, $slash);
        $total = substr($rest, $slash + 1, $close - $slash - 1);

        return strspn($part, '0123456789') === $slash && strspn($total, '0123456789') === \strlen($total) ? [(int) $part, (int) $total] : null;
    }

    /** 0 valid `<local@domain>`, 1 missing, 2 no angle brackets, 3 invalid. */
    private static function w66MessageIdState(string $mid): int
    {
        if ($mid === '') {
            return 1;
        }
        $length = \strlen($mid);
        if ($length < 3 || $mid[0] !== '<' || $mid[$length - 1] !== '>') {
            return 2;
        }
        $inner = substr($mid, 1, -1);
        $innerLength = $length - 2;

        return strcspn($inner, self::W66_MESSAGE_ID_BAD) !== $innerLength || substr_count($inner, '@') !== 1 || $inner[0] === '@' || $inner[$innerLength - 1] === '@' ? 3 : 0;
    }

    /**
     * Wave 66.2 XOVER parse plan. Per `number\tsubject\tfrom\tdate\tmessage-id\treferences\tbytes\tlines\txref`
     * row `valid | message_id_state << 1 | date_state << 3 | yenc << 5 | has_part << 6 |
     * min(part, 4095) << 7 | min(total, 4095) << 19 | byte_class << 31 | duplicate_message_id << 34 |
     * duplicate_number << 35 | min(subject_length, 1023) << 36 | from_empty << 46 |
     * min(xref_groups, 15) << 47 | message_id_crc << 51`. Subject, from and message id are
     * trimmed; number, bytes and lines are w66Int() fields of 12, 12 and 9 digits; the date is a
     * w66XoverDate(), the part a w66SubjectPart() and the message id a w66MessageIdState() (its
     * crc32 & 0xFFF when valid). A row is valid with a positive number, a subject, a valid message
     * id, parsable bytes and lines and no invalid date. yEnc is a case-insensitive `yenc` in the
     * subject; xref groups are the space-separated trimmed xref tokens holding a colon; byte
     * classes are 0 unknown, then under 1 KiB, 64 KiB, 1 MiB and 16 MiB, and 5 larger. Then valid
     * and invalid rows, four message id and four date state counts, yEnc, part, duplicate message
     * id and duplicate number rows, the lowest and highest positive number, bytes and lines of
     * valid rows, the earliest and latest parsed date, distinct valid message ids and the XXH64
     * digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderParserPlanBatchPhp(array $rows): array
    {
        $out = $mids = $nums = [];
        $midStates = array_fill(0, 4, 0);
        $dateStates = array_fill(0, 4, 0);
        $validRows = $yencRows = $partRows = $dupMidRows = $dupNumRows = $minNum = $maxNum = $totalBytes = $totalLines = $minEpoch = $maxEpoch = 0;
        $dated = false;
        foreach ($rows as $row) {
            [$number, $subject, $from, $date, $mid, , $bytes, $lines, $xref] = explode("\t", (string) $row, 9) + ['', '', '', '', '', '', '', '', ''];
            $number = self::w66Int($number, 12);
            $n = \is_int($number) && $number > 0 ? $number : 0;
            $subject = trim($subject);
            $mid = trim($mid);
            [$dateState, $epoch] = self::w66XoverDate($date);
            $midState = self::w66MessageIdState($mid);
            $bytes = self::w66Int($bytes, 12);
            $lines = self::w66Int($lines, 9);
            $part = self::w66SubjectPart($subject);
            $yenc = stripos($subject, 'yenc') !== false;
            $groups = 0;
            foreach (explode(' ', trim($xref)) as $token) {
                if ($token !== '' && str_contains($token, ':')) {
                    $groups++;
                }
            }
            $valid = $n > 0 && $subject !== '' && $midState === 0 && $bytes !== false && $lines !== false && $dateState !== 3;
            $dupMid = false;
            if ($midState === 0) {
                $dupMid = isset($mids[$mid]);
                $mids[$mid] = true;
            }
            $dupNum = false;
            if ($n > 0) {
                $dupNum = isset($nums[$n]);
                $nums[$n] = true;
                if ($minNum === 0) {
                    $minNum = $maxNum = $n;
                } else {
                    $minNum = min($minNum, $n);
                    $maxNum = max($maxNum, $n);
                }
            }
            if ($dateState === 1 || $dateState === 2) {
                if ($dated) {
                    $minEpoch = min($minEpoch, $epoch);
                    $maxEpoch = max($maxEpoch, $epoch);
                } else {
                    [$minEpoch, $maxEpoch, $dated] = [$epoch, $epoch, true];
                }
            }
            if ($valid) {
                $totalBytes += (int) $bytes;
                $totalLines += (int) $lines;
            }
            $size = \is_int($bytes) ? $bytes : null;
            $class = match (true) {
                $size === null => 0,
                $size < 1 << 10 => 1,
                $size < 1 << 16 => 2,
                $size < 1 << 20 => 3,
                $size < 1 << 24 => 4,
                default => 5,
            };
            [$p, $t] = $part ?? [0, 0];
            $validRows += (int) $valid;
            $midStates[$midState]++;
            $dateStates[$dateState]++;
            $yencRows += (int) $yenc;
            $partRows += (int) ($part !== null);
            $dupMidRows += (int) $dupMid;
            $dupNumRows += (int) $dupNum;
            $out[] = (int) $valid | $midState << 1 | $dateState << 3 | (int) $yenc << 5 | (int) ($part !== null) << 6 | min($p, 4095) << 7 | min($t, 4095) << 19
                | $class << 31 | (int) $dupMid << 34 | (int) $dupNum << 35 | min(\strlen($subject), 1023) << 36 | (int) (trim($from) === '') << 46 | min($groups, 15) << 47
                | ($midState === 0 ? crc32($mid) & 0xFFF : 0) << 51;
        }
        array_push($out, $validRows, \count($rows) - $validRows, ...$midStates, ...$dateStates);
        array_push($out, $yencRows, $partRows, $dupMidRows, $dupNumRows, $minNum, $maxNum, $totalBytes, $totalLines, $minEpoch, $maxEpoch, \count($mids));
        $out[] = self::w66Digest($out);

        return $out;
    }
}
