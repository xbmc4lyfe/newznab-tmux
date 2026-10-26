<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BookInfo;
use App\Support\Data\BookParseResult;
use FFI;
use FFI\CData;
use Throwable;

class BookMatchScorer
{
    /** Null selects the benchmark-qualified production defaults. */
    public ?bool $useRustFfi = null;

    public const RANKING_FFI_CDEF = <<<'C'
        int64_t nntmux_book_rank_helper(uint32_t op, const char *input, size_t len, int64_t year);
        ssize_t nntmux_book_work_key_nul(const char *input, size_t len, char *out, size_t cap);
        int32_t nntmux_book_identity_nul(const char *input, size_t len, uint32_t flags, double score, double runner);
        int64_t nntmux_book_rank_candidates_nul(const char *input, size_t len);
        ssize_t nntmux_isbndb_fields_nul(const char *input, size_t len, char *out, size_t cap);
        C;

    private static FFI|false|null $rankingFfi = null;

    public static function rankingFfi(): ?FFI
    {
        if (self::$rankingFfi === null) {
            self::$rankingFfi = RustPipelineLibrary::resolveDomain('metadata', self::RANKING_FFI_CDEF) ?? false;
        }

        return self::$rankingFfi === false ? null : self::$rankingFfi;
    }

    public static function rankingEnvironmentSupported(): bool
    {
        return mb_internal_encoding() === 'UTF-8'
            && in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true);
    }

    /** Return null when Rust declines or the library is unavailable. */
    public static function rankingInteger(int $operation, string $input, int $year = 0): ?int
    {
        if (! self::rankingEnvironmentSupported() || ($ffi = self::rankingFfi()) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $value = $ffi->nntmux_book_rank_helper($operation, $input, strlen($input), $year);

            return is_int($value) && $value >= 0 ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Return null when Rust declines or the library is unavailable. */
    public static function rankingString(string $symbol, string $input): ?string
    {
        $ffi = self::rankingFfi();
        if ($ffi === null) {
            return null;
        }
        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) * 2 + 64), $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
            }

            return is_int($written) && $written >= 0 && $written <= $capacity ? FFI::string($buffer, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function score(array $candidate, BookParseResult $parsed): float
    {
        $fast = RustPipelineLibrary::bookScore(
            $parsed->rawName,
            $parsed->title,
            $parsed->hasAuthor() ? (string) $parsed->author : null,
            $parsed->isbn,
            (string) ($candidate['title'] ?? ''),
            (string) ($candidate['author'] ?? ''),
            isset($candidate['isbn']) ? (string) $candidate['isbn'] : null,
            isset($candidate['ean']) ? (string) $candidate['ean'] : null,
        );
        if ($fast !== null) {
            return $fast;
        }

        return $this->scorePhp($candidate, $parsed);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function scorePhp(array $candidate, BookParseResult $parsed): float
    {
        $parsedIsbn = BookIsbn::normalize($parsed->isbn);
        $candidateIdentifiers = $this->candidateIdentifiers($candidate);
        if ($parsedIsbn !== null && $candidateIdentifiers !== []) {
            foreach ($candidateIdentifiers as $candidateIdentifier) {
                if (! BookIsbn::equivalent($parsedIsbn, $candidateIdentifier)) {
                    return 0.0;
                }
            }

            return 1.0;
        }

        if ($this->hasStructuralConflict($parsed, (string) ($candidate['title'] ?? ''))) {
            return 0.0;
        }

        $titleScore = $this->titleScorePhp($parsed->title, (string) ($candidate['title'] ?? ''));
        $authorScore = $parsed->hasAuthor()
            ? $this->authorScorePhp((string) $parsed->author, (string) ($candidate['author'] ?? ''))
            : 0.0;

        if ($parsed->hasAuthor()) {
            return (0.65 * $titleScore) + (0.35 * $authorScore);
        }

        return $titleScore;
    }

    public function scoreBookInfo(BookInfo $book, BookParseResult $parsed): float
    {
        return $this->score([
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'ean' => $book->ean,
            'publishdate' => $book->publishdate,
            'publisher' => $book->publisher,
            'cover' => $book->cover ? 1 : 0,
        ], $parsed);
    }

    public function titleScore(string $left, string $right): float
    {
        $fast = RustPipelineLibrary::bookTitleScore($left, $right);
        if ($fast !== null) {
            return $fast;
        }

        return $this->titleScorePhp($left, $right);
    }

    public function titleScorePhp(string $left, string $right): float
    {
        $leftVariants = $this->titleVariants($left);
        $rightVariants = $this->titleVariants($right);
        foreach ($leftVariants as $leftVariant) {
            foreach ($rightVariants as $rightVariant) {
                if ($leftVariant !== '' && $leftVariant === $rightVariant) {
                    return $leftVariant === $this->normalizeText($left)
                        && $rightVariant === $this->normalizeText($right)
                        ? 1.0
                        : 0.97;
                }
            }
        }

        $left = $this->normalizeText($left);
        $right = $this->normalizeText($right);
        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $leftWords = array_filter(explode(' ', $left), fn (string $w): bool => mb_strlen($w) > 1);
        $rightWords = array_filter(explode(' ', $right), fn (string $w): bool => mb_strlen($w) > 1);
        if ($leftWords === [] || $rightWords === []) {
            return 0.0;
        }

        $shared = array_intersect(array_unique($leftWords), array_unique($rightWords));
        $tokenScore = (2 * count($shared)) / (count(array_unique($leftWords)) + count(array_unique($rightWords)));

        similar_text($left, $right, $percent);
        $simTextScore = max(0.0, min(1.0, $percent / 100));

        return min(1.0, max($tokenScore, $simTextScore));
    }

    public function authorScore(string $left, string $right): float
    {
        $fast = RustPipelineLibrary::bookAuthorScore($left, $right);
        if ($fast !== null) {
            return $fast;
        }

        return $this->authorScorePhp($left, $right);
    }

    public function authorScorePhp(string $left, string $right): float
    {
        $leftTokens = $this->tokens($left);
        $rightTokens = $this->tokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $leftCoverage = $this->authorTokenCoverage($leftTokens, $rightTokens);
        $rightCoverage = $this->authorTokenCoverage($rightTokens, $leftTokens);

        return ($leftCoverage + $rightCoverage) / 2;
    }

    public function hasStructuralConflict(BookParseResult $parsed, string $candidateTitle): bool
    {
        return ($this->useRustFfi ?? false)
            ? $this->hasStructuralConflictNative($parsed, $candidateTitle)
            : $this->hasStructuralConflictPhp($parsed, $candidateTitle);
    }

    public function hasStructuralConflictPhp(BookParseResult $parsed, string $candidateTitle): bool
    {
        $parsedMarkers = $this->structuralMarkersPhp($parsed->rawName.' '.$parsed->title);
        $candidateMarkers = $this->structuralMarkersPhp($candidateTitle);
        foreach ($parsedMarkers as $type => $number) {
            if (isset($candidateMarkers[$type]) && $candidateMarkers[$type] !== $number) {
                return true;
            }
        }

        return false;
    }

    public function meaningfulTitleTokenCount(string $title): int
    {
        return ($this->useRustFfi ?? true)
            ? $this->meaningfulTitleTokenCountNative($title)
            : $this->meaningfulTitleTokenCountPhp($title);
    }

    public function meaningfulTitleTokenCountPhp(string $title): int
    {
        $stopWords = ['a', 'an', 'and', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];

        return count(array_filter(
            $this->tokens($title),
            static fn (string $token): bool => mb_strlen($token) > 1 && ! in_array($token, $stopWords, true)
        ));
    }

    /** @param array<string, mixed> $candidate */
    public function workKey(array $candidate): string
    {
        return ($this->useRustFfi ?? false)
            ? $this->workKeyNative($candidate)
            : $this->workKeyPhp($candidate);
    }

    /** @param array<string, mixed> $candidate */
    public function workKeyPhp(array $candidate): string
    {
        $authorTokens = $this->tokens((string) ($candidate['author'] ?? ''));
        sort($authorTokens);

        return $this->normalizeText((string) ($candidate['title'] ?? '')).'|'
            .implode(' ', $authorTokens);
    }

    public function hasStructuralConflictNative(BookParseResult $parsed, string $candidateTitle): bool
    {
        $left = $parsed->rawName.' '.$parsed->title;
        if (str_contains($left.$candidateTitle, "\0")) {
            return $this->hasStructuralConflictPhp($parsed, $candidateTitle);
        }
        $value = self::rankingInteger(4, $left."\0".$candidateTitle."\0");

        return $value === null ? $this->hasStructuralConflictPhp($parsed, $candidateTitle) : $value === 1;
    }

    /** @return array<string, int> */
    public function structuralMarkersNative(string $value): array
    {
        $packed = self::rankingInteger(0, $value);
        if ($packed === null) {
            return $this->structuralMarkersPhp($value);
        }
        $markers = [];
        if (($packed & 1023) !== 0) {
            $markers['volume'] = ($packed & 1023) - 1;
        }
        if (($packed >> 10) !== 0) {
            $markers['edition'] = ($packed >> 10) - 1;
        }

        return $markers;
    }

    public function meaningfulTitleTokenCountNative(string $title): int
    {
        return self::rankingInteger(1, $title) ?? $this->meaningfulTitleTokenCountPhp($title);
    }

    /** @param array<string, mixed> $candidate */
    public function workKeyNative(array $candidate): string
    {
        foreach (['title', 'author'] as $field) {
            if (isset($candidate[$field]) && ! is_scalar($candidate[$field])) {
                return $this->workKeyPhp($candidate);
            }
        }
        $title = (string) ($candidate['title'] ?? '');
        $author = (string) ($candidate['author'] ?? '');
        if (! self::rankingEnvironmentSupported() || str_contains($title.$author, "\0")) {
            return $this->workKeyPhp($candidate);
        }

        return self::rankingString('nntmux_book_work_key_nul', $title."\0".$author."\0") ?? $this->workKeyPhp($candidate);
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[._-]+/', ' ', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function titleVariants(string $value): array
    {
        $variants = [$this->normalizeText($value)];
        $parts = preg_split('/\s+(?::|-)\s+|:\s*/u', $value, 2);
        if (is_array($parts) && count($parts) === 2) {
            $variants[] = $this->normalizeText($parts[0]);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function candidateIdentifiers(array $candidate): array
    {
        $identifiers = [];
        foreach (['isbn', 'ean'] as $field) {
            $isbn = BookIsbn::normalize(isset($candidate[$field]) ? (string) $candidate[$field] : null);
            if ($isbn !== null) {
                $identifiers[] = $isbn;
            }
        }

        return array_values(array_unique($identifiers));
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $normalized = $this->normalizeText($value);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * @param  list<string>  $needles
     * @param  list<string>  $haystack
     */
    private function authorTokenCoverage(array $needles, array $haystack): float
    {
        $matched = 0;
        foreach ($needles as $needle) {
            foreach ($haystack as $candidate) {
                if ($needle === $candidate
                    || (mb_strlen($needle) === 1 && str_starts_with($candidate, $needle))
                    || (mb_strlen($candidate) === 1 && str_starts_with($needle, $candidate))) {
                    $matched++;

                    break;
                }
            }
        }

        return $matched / count($needles);
    }

    /**
     * @return array<string, int>
     */
    public function structuralMarkers(string $value): array
    {
        return ($this->useRustFfi ?? false) ? $this->structuralMarkersNative($value) : $this->structuralMarkersPhp($value);
    }

    /** @return array<string, int> */
    public function structuralMarkersPhp(string $value): array
    {
        $markers = [];
        if (preg_match('/\b(?:book|series|vol(?:ume)?)\.?\s*#?\s*(\d{1,3})\b/i', $value, $match) === 1) {
            $markers['volume'] = (int) $match[1];
        }
        if (preg_match('/\b(\d{1,3})(?:st|nd|rd|th)?\s+(?:edition|ed\.?)\b/i', $value, $match) === 1) {
            $markers['edition'] = (int) $match[1];
        }

        return $markers;
    }

    // Rust is the default only while the paired W59.6 benchmark clears 1.5x on all four metrics.
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_FFI_CDEF = <<<'C'
        int32_t nntmux_w596_book_rank_batch(const char *title, size_t title_len, const char *author, size_t author_len, const char *strings, size_t strings_len, const char *twin, size_t twin_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private const int W59_MAX_ITEMS = 1_048_576;

    public const int W59_BOOK_HEADER = 9;

    /** Stop and edition words dropped from title tokens. */
    private const array W59_BOOK_DROP = ['an' => true, 'and' => true, 'the' => true, 'of' => true, 'to' => true, 'in' => true,
        'on' => true, 'for' => true, 'by' => true, 'with' => true, 'or' => true, 'edition' => true, 'ed' => true, 'revised' => true,
        'unabridged' => true, 'abridged' => true, 'illustrated' => true, 'annotated' => true, 'anniversary' => true, 'deluxe' => true,
        'expanded' => true, 'retail' => true, 'ebook' => true, 'epub' => true, 'mobi' => true, 'pdf' => true, 'azw3' => true];

    /**
     * Rank candidate titles/authors against a query in permille. Tokens are lowercased runs of ASCII
     * letters, digits and bytes 0x80-0xFF, first occurrence kept; title tokens also drop one-byte, stop,
     * edition and ordinal (`2nd`) tokens. The main title ends at the first `:`, `(`, `[` or ` - `. Title:
     * 1000 for equal token keys, 970 when a main-title key matches, else the Dice coefficient. Author:
     * mean two-way token coverage (a one-byte token matches as an initial). Composite: `(650 title + 350
     * author) / 1000` with query author tokens, else the title score. Rank orders composites descending,
     * ties by index.
     *
     * Layout: `[0]` count, `[1]` best index (-1), `[2]` best and `[3]` runner-up composite (-1), `[4]`
     * composites of at least 800, `[5]` query title, `[6]` author and `[7]` main-title tokens, `[8]` FNV-1a
     * digest; then per candidate `composite | title << 10 | author << 20 | rank << 30 | flags << 50`
     * (flags: 1 exact, 2 main title, 4 no author tokens, 8 no title tokens).
     *
     * @param  array<array-key, string>  $titles
     * @param  array<array-key, string>  $authors  Parallel to $titles.
     * @return list<int>
     */
    public static function rankCandidateBatch(string $title, string $author, array $titles, array $authors): array
    {
        return (self::usesW59RustFfi() ? self::rankCandidateBatchNative($title, $author, $titles, $authors) : null)
            ?? self::rankCandidateBatchPhp($title, $author, $titles, $authors);
    }

    /**
     * @param  array<array-key, string>  $titles
     * @param  array<array-key, string>  $authors
     * @return list<int>
     */
    public static function rankCandidateBatchPhp(string $title, string $author, array $titles, array $authors): array
    {
        $titles = array_values($titles);
        $authors = array_values($authors);
        $count = \count($titles);
        [$queryTokens, $queryKey, $queryMainKey, $queryMainCount] = self::w59Title($title);
        $querySet = array_flip($queryTokens);
        $queryAuthors = self::w59Tokens($author, false);
        $out = array_fill(0, self::W59_BOOK_HEADER, 0);
        $out[0] = $count;
        $scores = $words = [];
        foreach ($titles as $at => $candidateTitle) {
            [$tokens, $key, $mainKey] = self::w59Title((string) $candidateTitle);
            $titleScore = $flags = 0;
            if ($queryKey !== '' && $queryKey === $key) {
                $titleScore = 1_000;
                $flags |= 1;
            } elseif (($queryMainKey !== '' && ($queryMainKey === $mainKey || $queryMainKey === $key)) || ($queryKey !== '' && $queryKey === $mainKey)) {
                $titleScore = 970;
                $flags |= 2;
            } elseif ($queryTokens !== [] && $tokens !== []) {
                $shared = 0;
                foreach ($tokens as $token) {
                    if (isset($querySet[$token])) {
                        $shared++;
                    }
                }
                $titleScore = intdiv(2_000 * $shared, \count($queryTokens) + \count($tokens));
            }
            $authorTokens = self::w59Tokens((string) ($authors[$at] ?? ''), false);
            $authorScore = $queryAuthors === [] || $authorTokens === []
                ? 0
                : intdiv(self::w59Coverage($queryAuthors, $authorTokens) + self::w59Coverage($authorTokens, $queryAuthors), 2);
            $composite = $queryAuthors === [] ? $titleScore : intdiv(650 * $titleScore + 350 * $authorScore, 1_000);
            if ($authorTokens === []) {
                $flags |= 4;
            }
            if ($tokens === []) {
                $flags |= 8;
            }
            $out[4] += (int) ($composite >= 800);
            $scores[] = $composite;
            $words[] = $titleScore << 10 | $authorScore << 20 | $flags << 50;
        }
        $order = $count > 0 ? range(0, $count - 1) : [];
        usort($order, static fn (int $a, int $b): int => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
        $rows = [];
        foreach ($order as $rank => $index) {
            $rows[$index] = $scores[$index] | $words[$index] | $rank << 30;
        }
        $digest = 2_166_136_261;
        for ($at = 0; $at < $count; $at++) {
            $row = $rows[$at];
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $out[] = $row;
        }
        $out[1] = $order[0] ?? -1;
        $out[2] = isset($order[0]) ? $scores[$order[0]] : -1;
        $out[3] = isset($order[1]) ? $scores[$order[1]] : -1;
        $out[5] = \count($queryTokens);
        $out[6] = \count($queryAuthors);
        $out[7] = $queryMainCount;
        $out[8] = $digest;

        return $out;
    }

    /**
     * Rust ranking, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $titles
     * @param  array<array-key, string>  $authors
     * @return list<int>|null
     */
    public static function rankCandidateBatchNative(string $title, string $author, array $titles, array $authors): ?array
    {
        $count = \count($titles);
        if ($count > self::W59_MAX_ITEMS || \count($authors) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w59Strings(array_values($titles), array_values($authors));
        $fields = self::W59_BOOK_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w59Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w596_book_rank_batch($title, \strlen($title), $author, \strlen($author), $joined, \strlen($joined), $twin, \strlen($twin), $count, $out, $fields));
    }

    /**
     * Unique title tokens, their key, the main-title key and its token count.
     *
     * @return array{0: list<string>, 1: string, 2: string, 3: int}
     */
    private static function w59Title(string $title): array
    {
        $tokens = self::w59Tokens($title, true);
        $cut = strcspn($title, ':([');
        $dash = strpos($title, ' - ');
        if ($dash !== false && $dash < $cut) {
            $cut = $dash;
        }
        $main = $cut < \strlen($title) ? self::w59Tokens(substr($title, 0, $cut), true) : $tokens;

        return [$tokens, implode(' ', $tokens), implode(' ', $main), \count($main)];
    }

    /**
     * First-seen unique lowercased tokens; title tokens drop one-byte, stop, edition and ordinal tokens.
     *
     * @return list<string>
     */
    private static function w59Tokens(string $value, bool $title): array
    {
        $tokens = $seen = [];
        foreach (preg_split('/[^a-z0-9\x80-\xff]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (isset($seen[$token])) {
                continue;
            }
            if ($title) {
                $length = \strlen($token);
                if ($length < 2 || isset(self::W59_BOOK_DROP[$token])
                    || ($length > 2 && strspn($token, '0123456789') === $length - 2 && \in_array(substr($token, -2), ['st', 'nd', 'rd', 'th'], true))) {
                    continue;
                }
            }
            $seen[$token] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Permille of `$needles` found in `$haystack`; a one-byte token matches as an initial.
     *
     * @param  list<string>  $needles
     * @param  list<string>  $haystack
     */
    private static function w59Coverage(array $needles, array $haystack): int
    {
        $matched = 0;
        foreach ($needles as $needle) {
            foreach ($haystack as $candidate) {
                if ($needle === $candidate
                    || (\strlen($needle) === 1 && str_starts_with($candidate, $needle))
                    || (\strlen($candidate) === 1 && str_starts_with($needle, $candidate))) {
                    $matched++;

                    break;
                }
            }
        }

        return intdiv($matched * 1_000, \count($needles));
    }

    /**
     * Run `$callback` with the Wave 59.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    public static function usesW59RustFfi(): bool
    {
        $forced = self::w59RustFfiState();

        return \is_bool($forced) ? $forced : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 59.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w59` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w59', self::W59_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 59.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w59Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w59RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w59Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
