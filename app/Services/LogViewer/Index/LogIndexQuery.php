<?php

declare(strict_types=1);

namespace App\Services\LogViewer\Index;

use App\Services\LogViewer\SearchQuery;
use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Translates a log viewer search into Manticore JSON query clauses.
 *
 * Manticore matches whole tokens, while the viewer promises substring matches, so a plain term becomes
 * a phrase of its word tokens with infix wildcards on the edge tokens (which may be cut-off words):
 * `Jobs\ReindexRel` → `"*jobs reindexrel*"`. That only selects candidates; {@see ManticoreLogSearcher}
 * re-checks each one with {@see SearchQuery::matches()} so results stay exactly what grep would return.
 *
 * {@see matchExpressionNative()} (Rust `nntmux_log_index_match_expression`) is kept for parity tests only: one
 * call per search did not reach the 1.5x speedup gate once FFI overhead is included.
 */
final class LogIndexQuery
{
    public const int MIN_INFIX_LENGTH = 3;

    /**
     * The Wave 44.5 summary defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x;
     * see tests/Unit/RustLogViewerIndexParserSearchPrometheusDocW44ParityTest.php.
     */
    public const bool W445_RUST_FFI_DEFAULT = true;

    private const string WORD_CHARACTER = '[\p{L}\p{N}_]';

    private const string WORD_SEPARATORS = '/[^\p{L}\p{N}_]+/u';

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_log_index_match_expression(const char *term, size_t term_len, size_t min_infix, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    private static ?bool $useW445RustFfi = null;

    /**
     * Whether Manticore can answer this search: regular expressions and terms without a usable token cannot.
     */
    public static function supports(SearchQuery $query): bool
    {
        return ! $query->regex && (! $query->hasTerm() || self::matchExpression($query->term) !== null);
    }

    public static function matchExpression(string $term): ?string
    {
        $tokens = preg_split(self::WORD_SEPARATORS, $term, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $last = count($tokens) - 1;
        $startsInWord = preg_match('/^'.self::WORD_CHARACTER.'/u', $term) === 1;
        $endsInWord = preg_match('/'.self::WORD_CHARACTER.'$/u', $term) === 1;
        $parts = [];

        foreach ($tokens as $index => $token) {
            $prefix = $index === 0 && $startsInWord;
            $suffix = $index === $last && $endsInWord;
            $token = mb_strtolower($token);

            if (($prefix || $suffix) && mb_strlen($token) < self::MIN_INFIX_LENGTH) {
                // Too short to expand; dropping a cut-off edge word still leaves an adjacent phrase.
                continue;
            }

            $parts[] = ($prefix ? '*' : '').$token.($suffix ? '*' : '');
        }

        if ($parts === []) {
            return null;
        }

        return count($parts) === 1 ? $parts[0] : '"'.implode(' ', $parts).'"';
    }

    /**
     * Rust equivalent of {@see matchExpression()} for ASCII terms; $supported is false when Rust declined the term
     * (non-ASCII) or the library is unavailable.
     */
    public static function matchExpressionNative(string $term, bool &$supported = false): ?string
    {
        $supported = false;
        self::$ffi ??= RustPipelineLibrary::resolveDomain('log_index_query', self::FFI_CDEF) ?? false;

        if (self::$ffi === false) {
            return null;
        }

        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch(self::$ffi, max(64, strlen($term) * 2 + 8), $capacity);

        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_log_index_match_expression($term, strlen($term), self::MIN_INFIX_LENGTH, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }

        if ($written < 0) {
            return null;
        }

        $supported = true;

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /** Pass null to restore {@see W445_RUST_FFI_DEFAULT}. */
    public static function useW445RustFfi(?bool $enable = true): void
    {
        self::$useW445RustFfi = $enable;
    }

    public static function usesW445RustFfi(): bool
    {
        return self::$useW445RustFfi ?? self::W445_RUST_FFI_DEFAULT;
    }

    public static function resetW445RustFfi(): void
    {
        self::$useW445RustFfi = null;
    }

    /**
     * Summarizes terms as {@see matchExpression()} translates them: `supported` terms, `phrases` (more
     * than one kept token), kept `tokens`, word tokens `dropped` as too-short edges, `prefix_wildcards` /
     * `suffix_wildcards`, `max_tokens` per expression and `distinct_expressions`. Non-strings are invalid.
     * Rust declines batches with non-ASCII terms, which then run in PHP.
     *
     * @param  array<array-key, mixed>  $terms
     * @return array{terms: int, invalid: int, supported: int, phrases: int, tokens: int, dropped: int, prefix_wildcards: int, suffix_wildcards: int, max_tokens: int, distinct_expressions: int}
     */
    public static function summarizeMatchExpressionBatch(array $terms): array
    {
        return self::usesW445RustFfi() ? self::summarizeMatchExpressionBatchNative($terms) : self::summarizeMatchExpressionBatchPhp($terms);
    }

    /**
     * @param  array<array-key, mixed>  $terms
     * @return array{terms: int, invalid: int, supported: int, phrases: int, tokens: int, dropped: int, prefix_wildcards: int, suffix_wildcards: int, max_tokens: int, distinct_expressions: int}
     */
    public static function summarizeMatchExpressionBatchPhp(array $terms): array
    {
        $summary = ['terms' => \count($terms), 'invalid' => 0, 'supported' => 0, 'phrases' => 0, 'tokens' => 0, 'dropped' => 0, 'prefix_wildcards' => 0, 'suffix_wildcards' => 0, 'max_tokens' => 0, 'distinct_expressions' => 0];
        $distinct = [];
        foreach ($terms as $term) {
            if (! \is_string($term)) {
                $summary['invalid']++;

                continue;
            }
            $words = preg_split(self::WORD_SEPARATORS, $term, -1, PREG_SPLIT_NO_EMPTY);
            $wordCount = \is_array($words) ? \count($words) : 0;
            $expression = self::matchExpression($term);
            if ($expression === null) {
                $summary['dropped'] += $wordCount;

                continue;
            }
            $parts = explode(' ', trim($expression, '"'));
            $kept = \count($parts);
            $summary['supported']++;
            $summary['phrases'] += (int) ($kept > 1);
            $summary['tokens'] += $kept;
            $summary['dropped'] += $wordCount - $kept;
            foreach ($parts as $part) {
                $summary['prefix_wildcards'] += (int) str_starts_with($part, '*');
                $summary['suffix_wildcards'] += (int) str_ends_with($part, '*');
            }
            $summary['max_tokens'] = max($summary['max_tokens'], $kept);
            $distinct[$expression] = true;
        }
        $summary['distinct_expressions'] = \count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $terms
     * @return array{terms: int, invalid: int, supported: int, phrases: int, tokens: int, dropped: int, prefix_wildcards: int, suffix_wildcards: int, max_tokens: int, distinct_expressions: int}
     */
    public static function summarizeMatchExpressionBatchNative(array $terms): array
    {
        $strings = array_values(array_filter($terms, 'is_string'));
        $fields = ReleaseSearchIndexDocument::evaluateW445Native(1, [(string) self::MIN_INFIX_LENGTH, ...$strings], 8);
        if ($fields === null) {
            return self::summarizeMatchExpressionBatchPhp($terms);
        }

        return ['terms' => \count($terms), 'invalid' => \count($terms) - \count($strings)]
            + array_combine(['supported', 'phrases', 'tokens', 'dropped', 'prefix_wildcards', 'suffix_wildcards', 'max_tokens', 'distinct_expressions'], $fields);
    }

    /**
     * `bool.must` clauses for the term and filters; the level or channel filter can be left out for its own facet.
     *
     * @return list<array<string, mixed>>
     */
    public static function clauses(SearchQuery $query, bool $withLevels = true, bool $withChannels = true): array
    {
        $clauses = [];
        $expression = $query->hasTerm() ? self::matchExpression($query->term) : null;

        if ($expression !== null) {
            $clauses[] = ['query_string' => $expression];
        }

        if ($withLevels && $query->levels !== []) {
            $clauses[] = self::anyOf('level', $query->levels);
        }

        if ($withChannels && $query->channels !== []) {
            $clauses[] = self::anyOf('channel', $query->channels);
        }

        $range = array_filter([
            'gte' => $query->from?->getTimestamp(),
            'lte' => $query->to?->getTimestamp(),
        ], static fn (?int $value): bool => $value !== null);

        if ($range !== []) {
            // Entries without a timestamp are stored as 0 and never match a range, as in the grep engine.
            $clauses[] = ['range' => ['logged_at' => $range + ['gte' => 1]]];
        }

        return $clauses;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private static function anyOf(string $attribute, array $values): array
    {
        return ['bool' => ['should' => array_map(
            static fn (string $value): array => ['equals' => [$attribute => $value]],
            array_values($values),
        )]];
    }
}
