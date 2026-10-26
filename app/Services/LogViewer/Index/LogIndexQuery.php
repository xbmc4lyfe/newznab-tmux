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

    /** C signature of the Wave 60.5 {@see self::queryPlanBatch()} helper. */
    private const string W60_LOG_QUERY_PLAN_FFI_CDEF = 'int64_t nntmux_w605_log_query_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 60.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 5.53/3.60/5.79/3.47x); see
     * tests/Unit/RustApiCanonicalizerQueryParamsV1BuilderV2PresenterLogQueryW60ParityTest.php.
     */
    public const bool W60_RUST_FFI_DEFAULT = true;

    /** Log levels and their {@see self::queryPlanBatch()} mask bits. */
    private const array W60_LEVELS = ['debug' => 1, 'info' => 2, 'notice' => 4, 'warning' => 8, 'error' => 16, 'critical' => 32, 'alert' => 64, 'emergency' => 128];

    /**
     * Wave 60.5 routing and FFI cache, kept in function-local statics (no static properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w60_5', self::W60_LOG_QUERY_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W60_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW60RustFfi(): bool
    {
        return self::w60RustFfiState() === true;
    }

    /**
     * Runs $callback with queryPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 60.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w60Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w60RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w605_log_query_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch log index query plan over `term\tlevels\tfrom\tto\tregex\toffset\tlimit`. Nine words per record:
     * `[mode, level_mask, from, to, offset, limit, direction, day_span, flags]`. Mode: 0 empty trimmed term, 2 regex,
     * 1 indexable (holds an ASCII alphanumeric, `_` or non-ASCII byte), 3 grep only. Levels split on commas, trimmed,
     * ASCII case-insensitive, set bits in debug..emergency order. From and to are 1-12 digit Unix seconds (empty 0),
     * swapped when reversed. Offset (empty 0) is 1-9 digits capped at 100000; limit (empty 50) is 1-9 digits clamped
     * to 1..500. Direction is 2 (oldest first) with only a lower bound, else 1; the day span counts UTC days touched
     * by a closed range. The regex switch accepts `1`/`true`/`yes`/`on` and empty/`0`/`false`/`no`/`off`. Flags:
     * 1 unknown level, 2 invalid bound, 4 swapped range, 8 invalid offset, 16 capped offset, 32 clamped limit,
     * 64 invalid limit, 128 invalid regex switch, 256 not exactly seven fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function queryPlanBatch(array $records): array
    {
        return self::usesW60RustFfi() ? self::queryPlanBatchNative($records) : self::queryPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function queryPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 7 ? 0 : 256;
            $regex = self::w60Switch($fields[4] ?? '');
            if ($regex === null) {
                $regex = false;
                $flags |= 128;
            }
            $term = trim($fields[0]);
            $mode = match (true) {
                $term === '' => 0,
                $regex => 2,
                preg_match('/[A-Za-z0-9_\x80-\xff]/', $term) === 1 => 1,
                default => 3,
            };
            $mask = 0;
            foreach (explode(',', $fields[1] ?? '') as $part) {
                $level = strtolower(trim($part));
                if ($level === '') {
                    continue;
                }
                $bit = self::W60_LEVELS[$level] ?? 0;
                if ($bit === 0) {
                    $flags |= 1;
                } else {
                    $mask |= $bit;
                }
            }
            $from = self::w60Bound($fields[2] ?? '', $flags);
            $to = self::w60Bound($fields[3] ?? '', $flags);
            if ($from > 0 && $to > 0 && $from > $to) {
                [$from, $to] = [$to, $from];
                $flags |= 4;
            }
            $offset = 0;
            $text = trim($fields[5] ?? '');
            if ($text !== '') {
                $value = self::w60Digits($text, 9);
                if ($value === null) {
                    $flags |= 8;
                } elseif ($value > 100000) {
                    $offset = 100000;
                    $flags |= 16;
                } else {
                    $offset = $value;
                }
            }
            $limit = 50;
            $text = trim($fields[6] ?? '');
            if ($text !== '') {
                $value = self::w60Digits($text, 9);
                if ($value === null) {
                    $flags |= 64;
                } elseif ($value < 1 || $value > 500) {
                    $limit = max(1, min(500, $value));
                    $flags |= 32;
                } else {
                    $limit = $value;
                }
            }
            $direction = $from > 0 && $to === 0 ? 2 : 1;
            $span = $from > 0 && $to > 0 ? intdiv($to, 86400) - intdiv($from, 86400) + 1 : 0;
            array_push($out, $mode, $mask, $from, $to, $offset, $limit, $direction, $span, $flags);
        }

        return $out;
    }

    /** Trimmed 1-12 digit Unix seconds (empty 0); anything else sets flag 2. */
    private static function w60Bound(string $field, int &$flags): int
    {
        $text = trim($field);
        if ($text === '') {
            return 0;
        }
        $value = self::w60Digits($text, 12);
        if ($value === null) {
            $flags |= 2;

            return 0;
        }

        return $value;
    }

    /** Trimmed ASCII case-insensitive switch; null when unrecognized. */
    private static function w60Switch(string $field): ?bool
    {
        return match (strtolower(trim($field))) {
            '1', 'true', 'yes', 'on' => true,
            '', '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    /** 1-$max ASCII digits as an integer, else null. */
    private static function w60Digits(string $text, int $max): ?int
    {
        return $text !== '' && \strlen($text) <= $max && ctype_digit($text) ? (int) $text : null;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function queryPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w60Words($count, implode("\0", $records), 9 * $count) ?? self::queryPlanBatchPhp($records);
    }
}
