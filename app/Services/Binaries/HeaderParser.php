<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\BlacklistService;
use App\Support\RustPipelineLibrary;
use FFI;

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
}
