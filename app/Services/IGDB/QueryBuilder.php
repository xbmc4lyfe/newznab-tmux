<?php

declare(strict_types=1);

namespace App\Services\IGDB;

use App\Services\IGDB\Models\BaseModel;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * @template TModel of BaseModel
 */
class QueryBuilder
{
    public const QUERY_FFI_CDEF = <<<'C'
        ssize_t nntmux_igdb_format_string(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_igdb_where_in(const char *field, size_t field_len, const char *ser, size_t ser_len, char *out, size_t cap);
        ssize_t nntmux_igdb_to_query_nul(const char *fields, size_t fields_len, size_t fields_count, const char *where_clauses, size_t where_len, size_t where_count, const char *search_term, size_t search_len, const char *sort, size_t sort_len, uint32_t has_sort, uint32_t has_limit, int64_t limit_val, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default. */
    public ?bool $useRustFfi = null;

    private static FFI|false|null $queryFfi = null;

    /**
     * @var array<int, string>
     */
    protected array $fields = ['*'];

    /**
     * @var array<int, string>
     */
    protected array $whereClauses = [];

    protected ?string $searchTerm = null;

    protected ?string $sort = null;

    protected ?int $limitValue = null;

    /**
     * @param  class-string<TModel>  $modelClass
     */
    public function __construct(
        protected string $modelClass,
        protected ?Client $client = null,
    ) {
        $this->client ??= app(Client::class);
    }

    public function search(string $query): self
    {
        $this->searchTerm = $query;

        return $this;
    }

    public function where(string $field, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (! is_string($operator) || $operator === '') {
            throw new InvalidArgumentException('The IGDB where operator must be a non-empty string.');
        }

        $this->whereClauses[] = sprintf('%s %s %s', $field, $operator, $this->formatValue($value));

        return $this;
    }

    /**
     * @param  array<int, int|string>  $values
     * @return $this
     */
    public function whereIn(string $field, array $values): self
    {
        return ($this->useRustFfi ?? false)
            ? $this->whereInNative($field, $values)
            : $this->whereInPhp($field, $values);
    }

    /**
     * @param  array<int, int|string>  $values
     * @return $this
     */
    public function whereInPhp(string $field, array $values): self
    {
        $formattedValues = implode(',', array_map($this->formatValuePhp(...), $values));
        $this->whereClauses[] = sprintf('%s = (%s)', $field, $formattedValues);

        return $this;
    }

    /**
     * @param  array<int, int|string>  $values
     * @return $this
     */
    public function whereInNative(string $field, array $values): self
    {
        if (($ffi = self::queryFfi()) === null) {
            return $this->whereInPhp($field, $values);
        }
        try {
            $serialized = serialize($values);
            $fieldLen = \strlen($field);
            $serLen = \strlen($serialized);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $fieldLen + $serLen + 32, $capacity);
            if ($buffer === null) {
                return $this->whereInPhp($field, $values);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_where_in($field, $fieldLen, $serialized, $serLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->whereInPhp($field, $values);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_igdb_where_in($field, $fieldLen, $serialized, $serLen, $buffer, $capacity);
            }
            if (\is_int($written) && $written >= 0 && $written <= $capacity) {
                $this->whereClauses[] = FFI::string($buffer, $written);

                return $this;
            }
        } catch (Throwable) {
        }

        return $this->whereInPhp($field, $values);
    }

    /**
     * @param  array<int|string, array<int, string>|string>  $relationships
     */
    public function with(array $relationships): self
    {
        foreach ($relationships as $relationship => $fields) {
            if (is_int($relationship)) {
                $relationship = $fields;
                $fields = ['*'];
            }

            foreach ((array) $fields as $field) {
                $this->fields[] = sprintf('%s.%s', $relationship, $field);
            }
        }

        return $this;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->sort = $field.' '.strtolower($direction);

        return $this;
    }

    public function orderByDesc(string $field): self
    {
        return $this->orderBy($field, 'desc');
    }

    public function limit(int $limit): self
    {
        $this->limitValue = max(1, $limit);

        return $this;
    }

    /**
     * @return Collection<int, TModel>
     */
    public function get(): Collection
    {
        $payload = $this->client?->request($this->modelClass::endpoint(), $this->toQuery()) ?? [];

        return collect($payload)->map(fn (array $attributes) => new $this->modelClass($attributes));
    }

    /**
     * @return TModel|null
     */
    public function first(): ?BaseModel
    {
        $query = clone $this;
        $query->limit(1);

        /** @var TModel|null $result */
        $result = $query->get()->first();

        return $result;
    }

    /**
     * @return TModel|null
     */
    public function find(int|string $id): ?BaseModel
    {
        return $this->where('id', (int) $id)->first();
    }

    public static function queryFfi(): ?FFI
    {
        if (self::$queryFfi === null) {
            self::$queryFfi = RustPipelineLibrary::resolveDomain('metadata', self::QUERY_FFI_CDEF) ?? false;
        }

        return self::$queryFfi === false ? null : self::$queryFfi;
    }

    public function toQuery(): string
    {
        return ($this->useRustFfi ?? false)
            ? $this->toQueryNative()
            : $this->toQueryPhp();
    }

    public function toQueryNative(): string
    {
        if (($ffi = self::queryFfi()) === null) {
            return $this->toQueryPhp();
        }
        try {
            $fieldsBlob = implode("\0", $this->fields);
            $fieldsLen = \strlen($fieldsBlob);
            $fieldsCount = \count($this->fields);
            $whereBlob = implode("\0", $this->whereClauses);
            $whereLen = \strlen($whereBlob);
            $whereCount = \count($this->whereClauses);
            $search = $this->searchTerm ?? '';
            $searchLen = \strlen($search);
            $sort = $this->sort ?? '';
            $sortLen = \strlen($sort);
            $hasSort = $this->sort !== null ? 1 : 0;
            $hasLimit = $this->limitValue !== null ? 1 : 0;
            $limitVal = $this->limitValue ?? 0;

            $hint = $fieldsLen + ($whereCount * 3) + $whereLen + ($searchLen * 2) + $sortLen + 96;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return $this->toQueryPhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_to_query_nul(
                $fieldsBlob,
                $fieldsLen,
                $fieldsCount,
                $whereBlob,
                $whereLen,
                $whereCount,
                $search,
                $searchLen,
                $sort,
                $sortLen,
                $hasSort,
                $hasLimit,
                $limitVal,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->toQueryPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_igdb_to_query_nul(
                    $fieldsBlob,
                    $fieldsLen,
                    $fieldsCount,
                    $whereBlob,
                    $whereLen,
                    $whereCount,
                    $search,
                    $searchLen,
                    $sort,
                    $sortLen,
                    $hasSort,
                    $hasLimit,
                    $limitVal,
                    $buffer,
                    $capacity,
                );
            }
            if (\is_int($written) && $written >= 0 && $written <= $capacity) {
                return FFI::string($buffer, $written);
            }
        } catch (Throwable) {
        }

        return $this->toQueryPhp();
    }

    public function toQueryPhp(): string
    {
        $segments = [
            'fields '.implode(',', array_unique($this->fields)).';',
        ];

        if ($this->searchTerm !== null && $this->searchTerm !== '') {
            $segments[] = 'search '.$this->formatValuePhp($this->searchTerm).';';
        }

        if ($this->whereClauses !== []) {
            $segments[] = 'where '.implode(' & ', array_unique($this->whereClauses)).';';
        }

        if ($this->sort !== null) {
            $segments[] = 'sort '.$this->sort.';';
        }

        if ($this->limitValue !== null) {
            $segments[] = 'limit '.$this->limitValue.';';
        }

        return implode("\n", $segments);
    }

    public function formatValue(mixed $value): string
    {
        return ($this->useRustFfi ?? false)
            ? $this->formatValueNative($value)
            : $this->formatValuePhp($value);
    }

    public function formatValuePhp(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'null',
            default => '"'.addcslashes((string) $value, '\\"').'"',
        };
    }

    public function formatValueNative(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null => 'null',
            default => $this->formatStringValueNative((string) $value),
        };
    }

    private function formatStringValueNative(string $raw): string
    {
        if (($ffi = self::queryFfi()) === null) {
            return '"'.addcslashes($raw, '\\"').'"';
        }
        try {
            $len = \strlen($raw);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, ($len * 2) + 4, $capacity);
            if ($buffer === null) {
                return '"'.addcslashes($raw, '\\"').'"';
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_format_string($raw, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return '"'.addcslashes($raw, '\\"').'"';
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_igdb_format_string($raw, $len, $buffer, $capacity);
            }
            if (\is_int($written) && $written >= 0 && $written <= $capacity) {
                return FFI::string($buffer, $written);
            }
        } catch (Throwable) {
        }

        return '"'.addcslashes($raw, '\\"').'"';
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 6.23x, p99 4.42x; uncommon mean 7.19x, p99 5.35x; see the W47 parity test). */
    public const bool W47_QUERY_CLAUSE_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_igdb_query_clause_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W47_QUERY_CLAUSE_KEYS = ['total_queries', 'empty_query_count', 'valid_query_count', 'fields_query_count', 'search_query_count', 'where_query_count', 'valid_sort_count', 'valid_limit_count', 'unbalanced_count', 'unknown_keyword_count', 'clause_sum', 'where_condition_sum'];

    private const array W47_KEYWORDS = ['fields' => true, 'exclude' => true, 'search' => true, 'where' => true, 'sort' => true, 'limit' => true, 'offset' => true];

    private static ?bool $w47UseRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW47QueryClauseRustFfi(?bool $enable = true): void
    {
        self::$w47UseRustFfi = $enable;
    }

    public static function usesW47QueryClauseRustFfi(): bool
    {
        return self::$w47UseRustFfi ?? self::W47_QUERY_CLAUSE_RUST_FFI_DEFAULT;
    }

    public static function resetW47QueryClauseRustFfi(): void
    {
        self::$w47UseRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Validate raw Apicalypse bodies such as toQuery() emits. Clauses split
     * on `;` outside double quotes (where backslash escapes the next byte)
     * and are trimmed; blank clauses are skipped. Counts blank queries,
     * valid ones (balanced quotes and parentheses, every clause terminated
     * and starting with a distinct known keyword, fields present), queries
     * with fields/search/where clauses, `sort <field> asc|desc`, `limit`
     * of 1-500, unbalanced and unknown-keyword queries, the clause sum and
     * the where condition sum (`&`/`|` separators plus one per where).
     *
     * @param  array<array-key, mixed>  $queries
     * @return array<string, int>
     */
    public static function summarizeQueryClauseBatch(array $queries): array
    {
        return self::usesW47QueryClauseRustFfi()
            ? self::summarizeQueryClauseBatchNative($queries)
            : self::summarizeQueryClauseBatchPhp($queries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $queries
     * @return array<string, int>
     */
    public static function summarizeQueryClauseBatchNative(array $queries): array
    {
        $fields = self::w47Fields($queries);

        return self::w47Call($fields) ?? self::w47Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $queries
     * @return array<string, int>
     */
    public static function summarizeQueryClauseBatchPhp(array $queries): array
    {
        return self::w47Summary(self::w47Fields($queries));
    }

    /**
     * @param  array<array-key, mixed>  $queries
     * @return list<string>
     */
    private static function w47Fields(array $queries): array
    {
        $fields = [];
        foreach ($queries as $query) {
            $fields[] = is_string($query) ? $query : '';
        }

        return $fields;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private static function w47Summary(array $fields): array
    {
        $summary = array_fill_keys(self::W47_QUERY_CLAUSE_KEYS, 0);
        foreach ($fields as $query) {
            $summary['total_queries']++;
            if (trim($query) === '') {
                $summary['empty_query_count']++;

                continue;
            }
            $clauses = [];
            $n = strlen($query);
            $i = $start = $separators = $depth = 0;
            $inQuote = $unbalanced = false;
            while ($i < $n) {
                if ($inQuote) {
                    $i += strcspn($query, '"\\', $i);
                    if ($i >= $n) {
                        break;
                    }
                    if ($query[$i] === '\\') {
                        $i++;
                    } else {
                        $inQuote = false;
                    }
                    $i++;

                    continue;
                }
                $i += strcspn($query, '"();&|', $i);
                if ($i >= $n) {
                    break;
                }
                $byte = $query[$i];
                if ($byte === '"') {
                    $inQuote = true;
                } elseif ($byte === '(') {
                    $depth++;
                } elseif ($byte === ')' && $depth === 0) {
                    $unbalanced = true;
                } elseif ($byte === ')') {
                    $depth--;
                } elseif ($byte === ';') {
                    $clauses[] = [substr($query, $start, $i - $start), $separators];
                    $unbalanced = $unbalanced || $depth !== 0;
                    $start = $i + 1;
                    $separators = $depth = 0;
                } else {
                    $separators++;
                }
                $i++;
            }
            $unbalanced = $unbalanced || $inQuote || $depth !== 0;
            $tail = substr($query, $start);
            $clauses[] = [$tail, $separators];
            $seen = [];
            $duplicate = $unknown = $sortValid = $limitValid = false;
            $count = $conditions = 0;
            foreach ($clauses as [$raw, $clauseSeparators]) {
                $clause = trim($raw);
                if ($clause === '') {
                    continue;
                }
                $count++;
                $length = strspn($clause, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
                $keyword = strtolower(substr($clause, 0, $length));
                $rest = substr($clause, $length);
                if (! isset(self::W47_KEYWORDS[$keyword]) || ($rest !== '' && ! str_contains(" \t\n\r\0\x0B", $rest[0]))) {
                    $unknown = true;

                    continue;
                }
                $duplicate = $duplicate || isset($seen[$keyword]);
                $seen[$keyword] = true;
                $rest = trim($rest);
                if ($keyword === 'where') {
                    $conditions += $clauseSeparators + 1;
                } elseif ($keyword === 'sort') {
                    $words = preg_split('/[ \t\n\r\x00\x0B]+/', $rest) ?: [];
                    $sortValid = $sortValid || ($rest !== '' && count($words) === 2 && in_array(strtolower($words[1]), ['asc', 'desc'], true));
                } elseif ($keyword === 'limit') {
                    $limitValid = $limitValid || (strlen($rest) <= 3 && ctype_digit($rest) && (int) $rest >= 1 && (int) $rest <= 500);
                }
            }
            $hasFields = isset($seen['fields']);
            $summary['valid_query_count'] += (int) (! $unbalanced && trim($tail) === '' && ! $unknown && ! $duplicate && $hasFields);
            $summary['fields_query_count'] += (int) $hasFields;
            $summary['search_query_count'] += (int) isset($seen['search']);
            $summary['where_query_count'] += (int) isset($seen['where']);
            $summary['valid_sort_count'] += (int) $sortValid;
            $summary['valid_limit_count'] += (int) $limitValid;
            $summary['unbalanced_count'] += (int) $unbalanced;
            $summary['unknown_keyword_count'] += (int) $unknown;
            $summary['clause_sum'] += $count;
            $summary['where_condition_sum'] += $conditions;
        }

        return $summary;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>|null
     */
    private static function w47Call(array $fields): ?array
    {
        try {
            $input = serialize($fields);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w47Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w47', self::W47_FFI_CDEF) ?? false;
            $ffi = self::$w47Ffi;
            if ($ffi === false) {
                return null;
            }
            // obtainScratch() returns at least the 96 bytes requested.
            $out = RustPipelineLibrary::obtainScratch($ffi, 96);
            // @phpstan-ignore method.notFound (Declared by W47_FFI_CDEF.)
            if ($out === null || $ffi->nntmux_w47_igdb_query_clause_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 1) {
                return null;
            }

            return array_combine(self::W47_QUERY_CLAUSE_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
    }

    // ========================================================================
    // WAVE 63.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.72x, p99 6.53x; uncommon mean 4.57x, p99 4.45x); see RustIgdbDataNodeBaseModelQueryBuilderIrcClientScraperW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_QUERY_BUILDER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w631_query_builder_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w63QueryBuilderPlanBatch(). */
    public const int W63_QUERY_BUILDER_TRAILER = 19;

    public static function usesW63RustFfi(): bool
    {
        $override = self::w63RustFfiState();

        return \is_bool($override) ? $override : self::W63_RUST_FFI_DEFAULT;
    }

    /** Forces the QueryBuilder Wave 63 Rust path on until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Forces the QueryBuilder Wave 63 PHP path until reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    /**
     * Runs $callback with the QueryBuilder Wave 63 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 63.1 Apicalypse query compilation plans. Each item is `fields|search|where|sort|limit|offset`, compiled
     * like toQueryPhp() into newline-joined `fields a,b;`, `search "..."` (addcslashes of `\` and `"`), `where c1 &
     * c2;`, `sort field dir;`, `limit n;` and `offset n;` segments. Fields and `&`-separated where conditions are
     * trimmed and deduplicated in order; an empty projection compiles to `*`. An empty field or one outside
     * `[A-Za-z0-9_.*]` is invalid; a condition is `[A-Za-z0-9_.]+`, spaces, one of `!= >= <= = < > ~`, spaces and a
     * non-empty value, whose `(a,b,...)` list counts its values. Sort splits at the last space (direction
     * lowercased, default `asc`) and is valid for a `[A-Za-z0-9_.]` field with `asc` or `desc`. The limit is a
     * signed integer raised to 1 and the offset 1-15 digits. Status: 5 every field empty, 1 invalid field, 2
     * invalid condition, 3 invalid sort, 4 invalid limit or offset, else 0. Flags: 1 search, 2 escaped search, 4
     * non-ASCII, 8 repeated field, 16 repeated condition, 32 descending, 64 limit over 500, 128 value list. Per
     * item `status | flags << 3 | min(fields, 63) << 11 | min(conditions, 63) << 17 | min(segments, 7) << 23 |
     * min(compiledLength, 65535) << 26 | bitLength(limit) << 42 | bitLength(offset) << 48 | min(listValues, 255) <<
     * 54`; then 6 status counts, 8 flag counts, the compiled length, field and condition sums, the longest compiled
     * query and the invalid field and condition sum.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63QueryBuilderPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63QueryBuilderPlanBatchNative($items) : self::w63QueryBuilderPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63QueryBuilderPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_QUERY_BUILDER_TRAILER) ?? self::w63QueryBuilderPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63QueryBuilderPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W63_QUERY_BUILDER_TRAILER, 0);
        foreach ($items as $item) {
            $item = (string) $item;
            [$rawFields, $search, $rawWhere, $rawSort, $rawLimit, $rawOffset] = explode('|', $item, 6) + ['', '', '', '', '', ''];
            $projection = trim($rawFields);
            $conditions = trim($rawWhere);
            $sort = trim($rawSort);
            $limitRaw = trim($rawLimit);
            $offsetRaw = trim($rawOffset);
            $flags = 0;
            $invalidFields = 0;
            $fieldList = [];
            if ($projection === '') {
                $fieldList[] = '*';
            } else {
                foreach (explode(',', $projection) as $rawSegment) {
                    $segment = trim($rawSegment);
                    if ($segment === '') {
                        $invalidFields++;

                        continue;
                    }
                    if (strspn($segment, self::W63_FIELD_CHARS.'*') !== \strlen($segment)) {
                        $invalidFields++;
                    }
                    if (\in_array($segment, $fieldList, true)) {
                        $flags |= 8;
                    } else {
                        $fieldList[] = $segment;
                    }
                }
            }
            $segments = ['fields '.implode(',', $fieldList).';'];
            if ($search !== '') {
                $escaped = addcslashes($search, '\\"');
                $flags |= 1 | (\strlen($escaped) !== \strlen($search) ? 2 : 0);
                $segments[] = 'search "'.$escaped.'";';
            }
            $invalidWhere = $listValues = 0;
            $whereList = [];
            if ($conditions !== '') {
                foreach (explode('&', $conditions) as $rawCondition) {
                    $condition = trim($rawCondition);
                    if ($condition === '') {
                        $invalidWhere++;

                        continue;
                    }
                    $values = self::w63WhereCondition($condition);
                    if ($values === null) {
                        $invalidWhere++;
                    } else {
                        $listValues += $values;
                        $flags |= $values > 0 ? 128 : 0;
                    }
                    if (\in_array($condition, $whereList, true)) {
                        $flags |= 16;
                    } else {
                        $whereList[] = $condition;
                    }
                }
            }
            if ($whereList !== []) {
                $segments[] = 'where '.implode(' & ', $whereList).';';
            }
            $sortInvalid = false;
            if ($sort !== '') {
                $space = strrpos($sort, ' ');
                [$field, $direction] = $space === false ? [$sort, 'asc'] : [trim(substr($sort, 0, $space)), strtolower(trim(substr($sort, $space + 1)))];
                $flags |= $direction === 'desc' ? 32 : 0;
                $sortInvalid = $field === '' || strspn($field, self::W63_FIELD_CHARS) !== \strlen($field) || ($direction !== 'asc' && $direction !== 'desc');
                $segments[] = 'sort '.$field.' '.$direction.';';
            }
            $pagingInvalid = false;
            $limitBits = $offsetBits = 0;
            if ($limitRaw !== '') {
                $negative = $limitRaw[0] === '-';
                $digits = $negative ? substr($limitRaw, 1) : $limitRaw;
                $length = \strlen($digits);
                if ($length === 0 || $length > 15 || strspn($digits, '0123456789') !== $length) {
                    $pagingInvalid = true;
                } else {
                    $value = $negative ? -(int) $digits : (int) $digits;
                    $limit = max(1, $value);
                    $flags |= $value > 500 ? 64 : 0;
                    $limitBits = self::w63BitLength($limit);
                    $segments[] = 'limit '.$limit.';';
                }
            }
            if ($offsetRaw !== '') {
                $length = \strlen($offsetRaw);
                if ($length <= 15 && strspn($offsetRaw, '0123456789') === $length) {
                    $offset = (int) $offsetRaw;
                    $offsetBits = self::w63BitLength($offset);
                    $segments[] = 'offset '.$offset.';';
                } else {
                    $pagingInvalid = true;
                }
            }
            $flags |= preg_match('/[\x80-\xff]/', $item) === 1 ? 4 : 0;
            $compiled = \strlen(implode("\n", $segments));
            $status = match (true) {
                $projection === '' && $search === '' && $conditions === '' && $sort === '' && $limitRaw === '' && $offsetRaw === '' => 5,
                $invalidFields > 0 => 1,
                $invalidWhere > 0 => 2,
                $sortInvalid => 3,
                $pagingInvalid => 4,
                default => 0,
            };
            $fieldCount = \count($fieldList);
            $whereCount = \count($whereList);
            $tail[$status]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[6 + $b] += $flags >> $b & 1;
            }
            $tail[14] += $compiled;
            $tail[15] += $fieldCount;
            $tail[16] += $whereCount;
            $tail[17] = max($tail[17], $compiled);
            $tail[18] += $invalidFields + $invalidWhere;
            $out[] = $status | $flags << 3 | min($fieldCount, 63) << 11 | min($whereCount, 63) << 17 | min(\count($segments), 7) << 23
                | min($compiled, 65535) << 26 | $limitBits << 42 | $offsetBits << 48 | min($listValues, 255) << 54;
        }
        array_push($out, ...$tail);

        return $out;
    }

    private const string W63_FIELD_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.';

    /** A where condition's `(a,b,...)` value count (0 for a scalar value), or null when it is malformed. */
    private static function w63WhereCondition(string $condition): ?int
    {
        $field = strspn($condition, self::W63_FIELD_CHARS);
        if ($field === 0) {
            return null;
        }
        $at = $field + strspn($condition, ' ', $field);
        $operator = substr($condition, $at, 2);
        if ($operator === '!=' || $operator === '>=' || $operator === '<=') {
            $at += 2;
        } elseif ($operator !== '' && str_contains('=<>~', $operator[0])) {
            $at++;
        } else {
            return null;
        }
        $value = substr($condition, $at + strspn($condition, ' ', $at));
        $length = \strlen($value);
        if ($length === 0) {
            return null;
        }

        return $length >= 2 && $value[0] === '(' && $value[$length - 1] === ')' ? substr_count($value, ',') + 1 : 0;
    }

    private static function w63BitLength(int $value): int
    {
        $bits = 0;
        for (; $value > 0; $value >>= 1) {
            $bits++;
        }

        return $bits;
    }

    /**
     * Wave 63 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('igdb_query_builder_w631', self::W63_QUERY_BUILDER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w63RustFfiState('ffi');
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
                $handle = self::w63RustFfiState('ffi');
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
     * Calls the Wave 63.1 QueryBuilder export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w63Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w63RustFfiState('ffi');
            $out = self::w63RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w63RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W63_QUERY_BUILDER_FFI_CDEF.)
            $written = $ffi->nntmux_w631_query_builder_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
