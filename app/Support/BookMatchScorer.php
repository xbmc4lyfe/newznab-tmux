<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\BookInfo;
use App\Support\Data\BookParseResult;
use FFI;
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
}
