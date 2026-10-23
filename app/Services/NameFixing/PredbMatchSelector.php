<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;

/**
 * Selects a PreDB hit whose title or cleaned filename has the query's literal UTF-8 identity.
 * Backend discovery and ranking may be fuzzy; acceptance permits only letter case differences.
 */
class PredbMatchSelector
{
    public const PREDB_IDENTITY_POLICY = '7ed004035636bb0c620a40b1b3349bfea912846d81bdfd64c5f0f738098fa2a7';

    private const MAX_RESULTS_TO_SCORE = 50;

    private const MIN_ACCEPTABLE_SCORE = 60.0;

    /**
     * @var list<string>
     */
    private const NOISE_TOKENS = [
        'a', 'an', 'and', 'audio', 'audio_ts', 'bup', 'cd', 'cover', 'covers',
        'disc', 'disk', 'dvd', 'file', 'files', 'folder', 'ifo', 'image',
        'images', 'img', 'jpg', 'jpeg', 'nfo', 'of', 'par2', 'part', 'pdf',
        'png', 'proof', 'rar', 'sample', 'screen', 'screens', 'sfv', 'srr',
        'srs', 'sub', 'subs', 'the', 'thumb', 'thumbs', 'txt', 'url',
        'video', 'video_ts', 'vob', 'vts', 'webp', 'zip',
    ];

    /**
     * @var list<string>
     */
    private const GENERIC_QUERY_PATTERNS = [
        '/^\d+$/',
        '/^(?:audio|video)[ ._-]?ts$/i',
        '/^vts[ ._-]?\d+(?:[ ._-]\d+)?$/i',
        '/^(?:image|img)\d*$/i',
        '/^(?:cd|dvd|disc|disk|part|vol)\d*$/i',
    ];

    protected FileNameCleaner $fileNameCleaner;

    public function __construct(?FileNameCleaner $fileNameCleaner = null)
    {
        $this->fileNameCleaner = $fileNameCleaner ?? new FileNameCleaner;
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $hits
     * @return array<string, mixed>|null
     */
    public function selectBestMatch(string $query, array $hits, ?OriginalFilenameEvidence $originalFilename = null): ?array
    {
        return $this->fileNameCleaner->withMatchingReuse(
            fn (): ?array => $this->selectWithinLookup($query, $hits, $originalFilename),
            joinActiveLookup: true
        );
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $hits
     * @return array<string, mixed>|null
     */
    private function selectWithinLookup(string $query, array $hits, ?OriginalFilenameEvidence $originalFilename): ?array
    {
        if (get_class($this) === self::class && get_class($this->fileNameCleaner) === FileNameCleaner::class) {
            $slice = array_values(array_slice($hits, 0, self::MAX_RESULTS_TO_SCORE));
            $supported = false;
            $idx = RustPipelineLibrary::predbSelectBestMatchIndex(
                $query,
                $originalFilename !== null,
                $originalFilename !== null ? $originalFilename->basename : '',
                $slice,
                null,
                $supported
            );
            if ($supported) {
                if ($idx === null || $idx < 0 || ! isset($slice[$idx])) {
                    return null;
                }
                $winner = $slice[$idx];

                return is_array($winner) ? $winner : (array) $winner;
            }
        }

        return $this->selectWithinLookupPhp($query, $hits, $originalFilename);
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $hits
     * @return array<string, mixed>|null
     */
    public function selectWithinLookupPhp(string $query, array $hits, ?OriginalFilenameEvidence $originalFilename): ?array
    {
        if (! mb_check_encoding($query, 'UTF-8')) {
            return null;
        }
        $queryIdentity = PredbSearchDocument::exactValue($query);
        $normalizedQuery = $this->normalizeForComparison($query);
        if (! $this->isSearchableQuery($normalizedQuery)) {
            return null;
        }

        $queryTokens = $this->extractMeaningfulTokens($normalizedQuery);
        if ($queryTokens === []) {
            return null;
        }

        $literalTitles = $literalFilenames = [];
        $filenameTitles = [];
        foreach (array_slice($hits, 0, self::MAX_RESULTS_TO_SCORE) as $hit) {
            $hitData = is_array($hit) ? $hit : (array) $hit;
            $title = (string) ($hitData['title'] ?? '');
            if ($title === '' || ! mb_check_encoding($title, 'UTF-8')) {
                continue;
            }
            $titleIdentity = PredbSearchDocument::exactValue($title);
            if ($titleIdentity === $queryIdentity) {
                $literalTitles[] = $hitData;
            } elseif ($originalFilename !== null
                ? $originalFilename->accepts($title, (string) ($hitData['filename'] ?? ''))
                : $this->hasLiteralFilename($queryIdentity, $hitData)) {
                $literalFilenames[] = $hitData;
                $filenameTitles[$titleIdentity] = true;
            }
        }
        if ($literalTitles === [] && count($filenameTitles) !== 1) {
            return null;
        }

        $bestMatch = null;
        $bestScore = 0.0;
        foreach ($literalTitles !== [] ? $literalTitles : $literalFilenames as $hitData) {
            $score = $this->scoreHit($normalizedQuery, $queryTokens, $hitData);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $hitData;
            }
        }

        if ($bestScore < self::MIN_ACCEPTABLE_SCORE) {
            return null;
        }

        return $bestMatch;
    }

    /** @param array<string, mixed> $hit */
    private function hasLiteralFilename(string $queryIdentity, array $hit): bool
    {
        return $this->fileNameCleaner->matchesPredbFilenameEvidence((string) ($hit['filename'] ?? ''), $queryIdentity);
    }

    /**
     * @param  list<string>  $queryTokens
     * @param  array<string, mixed>  $hit
     */
    protected function scoreHit(string $normalizedQuery, array $queryTokens, array $hit): float
    {
        $title = $this->normalizeForComparison((string) ($hit['title'] ?? ''));
        $filename = $this->normalizePredbFilename((string) ($hit['filename'] ?? ''));

        if ($title === '' && $filename === '') {
            return 0.0;
        }

        $titleTokens = $this->extractMeaningfulTokens($title);
        $filenameTokens = $this->extractMeaningfulTokens($filename);

        $score = max(
            $this->scoreAgainstField($normalizedQuery, $queryTokens, $title, $titleTokens),
            $this->scoreAgainstField($normalizedQuery, $queryTokens, $filename, $filenameTokens) + ($filename !== '' ? 5.0 : 0.0)
        );

        if ($this->hasMarkerMismatch($normalizedQuery, $title, $filename, '/\bxxx\b/i')) {
            $score -= 30.0;
        }

        return max($score, 0.0);
    }

    /**
     * @param  list<string>  $queryTokens
     * @param  list<string>  $fieldTokens
     */
    protected function scoreAgainstField(string $query, array $queryTokens, string $field, array $fieldTokens): float
    {
        if ($field === '') {
            return 0.0;
        }

        $similarity = $this->similarityPercent($query, $field);
        $sharedTokens = array_values(array_intersect($queryTokens, $fieldTokens));
        $sharedTokenCount = count($sharedTokens);
        $overlapRatio = $this->tokenOverlapRatio($queryTokens, $fieldTokens);

        $score = ($similarity * 0.6) + ($overlapRatio * 45.0) + ($sharedTokenCount * 6.0);

        if ($field === $query) {
            $score += 80.0;
        } elseif (strlen($query) >= 8 && str_contains($field, $query)) {
            $score += 18.0;
        }

        if ($this->longestSharedTokenLength($sharedTokens) >= 8) {
            $score += 8.0;
        }

        if ($sharedTokenCount === 0) {
            $score -= 15.0;
        }

        return $score;
    }

    protected function normalizePredbFilename(string $filename): string
    {
        $cleaned = $this->fileNameCleaner->cleanForMatching($filename, preserveLeadingNumber: true);
        $value = is_string($cleaned) && $cleaned !== '' ? $cleaned : $filename;

        return $this->normalizeForComparison($value);
    }

    protected function normalizeForComparison(string $value): string
    {
        $value = $this->fileNameCleaner->normalizeCandidateTitle($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    protected function isSearchableQuery(string $normalizedQuery): bool
    {
        if ($normalizedQuery === '' || strlen($normalizedQuery) < 3) {
            return false;
        }

        foreach (self::GENERIC_QUERY_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalizedQuery)) {
                return false;
            }
        }

        $tokens = $this->extractMeaningfulTokens($normalizedQuery);
        if ($tokens === []) {
            return false;
        }

        if (count($tokens) === 1 && strlen($tokens[0]) < 5) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    protected function extractMeaningfulTokens(string $normalizedValue): array
    {
        $tokens = preg_split('/\s+/u', $normalizedValue) ?: [];
        $meaningfulTokens = [];

        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, self::NOISE_TOKENS, true)) {
                continue;
            }

            if (preg_match('/^(?:cd|dvd|disc|disk|part|vol)\d*$/i', $token)) {
                continue;
            }

            if (preg_match('/^\d{1,2}$/', $token)) {
                continue;
            }

            if (strlen($token) < 2) {
                continue;
            }

            $meaningfulTokens[] = $token;
        }

        return array_values(array_unique($meaningfulTokens));
    }

    /**
     * @param  list<string>  $leftTokens
     * @param  list<string>  $rightTokens
     */
    protected function tokenOverlapRatio(array $leftTokens, array $rightTokens): float
    {
        if ($leftTokens === []) {
            return 0.0;
        }

        $sharedTokens = array_intersect($leftTokens, $rightTokens);

        return count($sharedTokens) / count($leftTokens);
    }

    /**
     * @param  list<string>  $sharedTokens
     */
    protected function longestSharedTokenLength(array $sharedTokens): int
    {
        $longest = 0;

        foreach ($sharedTokens as $token) {
            $longest = max($longest, strlen($token));
        }

        return $longest;
    }

    protected function similarityPercent(string $left, string $right): float
    {
        if ($left !== '' && $left === $right) {
            return 100.0;
        }

        $ffiRes = RustPipelineLibrary::similarityPercent($left, $right);
        if ($ffiRes !== null) {
            return $ffiRes;
        }

        $percent = 0.0;
        similar_text($left, $right, $percent);

        return $percent;
    }

    protected function hasMarkerMismatch(string $query, string $title, string $filename, string $pattern): bool
    {
        $queryHasMarker = preg_match($pattern, $query) === 1;
        $candidateHasMarker = preg_match($pattern, $title) === 1 || preg_match($pattern, $filename) === 1;

        return $queryHasMarker xor $candidateHasMarker;
    }
}
