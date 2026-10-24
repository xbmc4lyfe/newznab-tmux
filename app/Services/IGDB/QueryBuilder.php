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
}
