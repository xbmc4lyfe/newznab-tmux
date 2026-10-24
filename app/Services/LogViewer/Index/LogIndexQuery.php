<?php

declare(strict_types=1);

namespace App\Services\LogViewer\Index;

use App\Services\LogViewer\SearchQuery;
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

    private const string WORD_CHARACTER = '[\p{L}\p{N}_]';

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_log_index_match_expression(const char *term, size_t term_len, size_t min_infix, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    /**
     * Whether Manticore can answer this search: regular expressions and terms without a usable token cannot.
     */
    public static function supports(SearchQuery $query): bool
    {
        return ! $query->regex && (! $query->hasTerm() || self::matchExpression($query->term) !== null);
    }

    public static function matchExpression(string $term): ?string
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $term, -1, PREG_SPLIT_NO_EMPTY);

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
