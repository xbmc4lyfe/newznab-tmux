<?php

declare(strict_types=1);

namespace App\Services\IGDB;

use App\Services\IGDB\Models\BaseModel;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
