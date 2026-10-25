<?php

declare(strict_types=1);

namespace App\Services\IGDB;

use App\Support\RustPipelineLibrary;
use ArrayAccess;
use FFI;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Throwable;

class DataNode implements Arrayable, ArrayAccess
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_igdb_datanode_is_date_field_nul(const char *key, size_t key_len, const char *fields, size_t fields_len, size_t count);
        int32_t nntmux_igdb_datanode_resolve_timestamp_nul(const char *key, size_t key_len, const char *fields, size_t fields_len, size_t count, int32_t val_kind, int64_t int_val, const char *str_val, size_t str_len, int64_t *out_ts);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvPipelineAndIgdbDataNodeParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $dateFields
     */
    public function __construct(array $attributes = [], protected array $dateFields = [])
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $this->normalizeValue((string) $key, $value);
        }
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $this->normalizeValue($key, $value);
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[(string) $offset] = $this->normalizeValue((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[(string) $offset]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = [];

        foreach ($this->attributes as $key => $value) {
            $attributes[$key] = $this->normalizeArrayValue($value);
        }

        return $attributes;
    }

    protected function normalizeValue(string $key, mixed $value): mixed
    {
        $timestamp = self::resolveDateTimestamp($key, $value, $this->dateFields);
        if ($timestamp !== null) {
            return Carbon::createFromTimestamp($timestamp);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            return new self($value, $this->dateFields);
        }

        return array_map(function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            return array_is_list($item)
                ? array_map(fn (mixed $nested) => is_array($nested) && ! array_is_list($nested)
                    ? new self($nested, $this->dateFields)
                    : $nested, $item)
                : new self($item, $this->dateFields);
        }, $value);
    }

    /**
     * @param  array<int, string>  $dateFields
     */
    public static function isDateField(string $key, array $dateFields): bool
    {
        return self::usesRustFfi()
            ? self::isDateFieldNative($key, $dateFields)
            : self::isDateFieldPhp($key, $dateFields);
    }

    /**
     * @param  array<int, string>  $dateFields
     */
    public static function isDateFieldNative(string $key, array $dateFields): bool
    {
        $count = \count($dateFields);
        if ($count === 0) {
            return false;
        }
        if (($ffi = self::ffi()) === null || str_contains($key, "\0")) {
            return self::isDateFieldPhp($key, $dateFields);
        }
        foreach ($dateFields as $field) {
            if (! \is_string($field) || str_contains($field, "\0")) {
                return self::isDateFieldPhp($key, $dateFields);
            }
        }
        $joined = implode("\0", $dateFields);
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_igdb_datanode_is_date_field_nul($key, \strlen($key), $joined, \strlen($joined), $count);
        } catch (Throwable) {
            return self::isDateFieldPhp($key, $dateFields);
        }

        return match ($res) {
            1 => true,
            0 => false,
            default => self::isDateFieldPhp($key, $dateFields),
        };
    }

    /**
     * @param  array<int, string>  $dateFields
     */
    public static function isDateFieldPhp(string $key, array $dateFields): bool
    {
        return in_array($key, $dateFields, true);
    }

    /**
     * @param  array<int, string>  $dateFields
     */
    public static function resolveDateTimestamp(string $key, mixed $value, array $dateFields): ?int
    {
        return self::usesRustFfi()
            ? self::resolveDateTimestampNative($key, $value, $dateFields)
            : self::resolveDateTimestampPhp($key, $value, $dateFields);
    }

    /**
     * @param  array<int, string>  $dateFields
     */
    public static function resolveDateTimestampNative(string $key, mixed $value, array $dateFields): ?int
    {
        $count = \count($dateFields);
        if ($count === 0) {
            return null;
        }
        if (($ffi = self::ffi()) === null || str_contains($key, "\0")) {
            return self::resolveDateTimestampPhp($key, $value, $dateFields);
        }
        foreach ($dateFields as $field) {
            if (! \is_string($field) || str_contains($field, "\0")) {
                return self::resolveDateTimestampPhp($key, $value, $dateFields);
            }
        }

        $valKind = 0;
        $intVal = 0;
        $strVal = '';
        if (\is_int($value)) {
            $valKind = 1;
            $intVal = $value;
        } elseif (\is_float($value)) {
            if (! is_finite($value) || $value < (float) PHP_INT_MIN || $value >= (float) PHP_INT_MAX) {
                return self::resolveDateTimestampPhp($key, $value, $dateFields);
            }
            $valKind = 2;
            $intVal = (int) $value;
        } elseif (\is_string($value)) {
            $valKind = 3;
            $strVal = $value;
        }

        $joined = implode("\0", $dateFields);
        try {
            $outTs = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_igdb_datanode_resolve_timestamp_nul(
                $key,
                \strlen($key),
                $joined,
                \strlen($joined),
                $count,
                $valKind,
                $intVal,
                $strVal,
                \strlen($strVal),
                $outTs,
            );
            if ($res === 1) {
                return (int) $outTs[0];
            }
            if ($res === 0) {
                return null;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::resolveDateTimestampPhp($key, $value, $dateFields);
    }

    /**
     * @param  array<int, string>  $dateFields
     */
    public static function resolveDateTimestampPhp(string $key, mixed $value, array $dateFields): ?int
    {
        if (in_array($key, $dateFields, true) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    protected function normalizeArrayValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toArray();
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $item) => $this->normalizeArrayValue($item), $value);
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.31x, p99 1.63x; uncommon mean 1.94x, p99 1.63x; see the W47 parity test). */
    public const bool W47_FIELD_PATH_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_igdb_datanode_field_path_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W47_FIELD_PATH_KEYS = ['total_paths', 'invalid_path_count', 'nested_path_count', 'deep_path_count', 'wildcard_path_count', 'date_leaf_count', 'known_leaf_count', 'type_match_count', 'type_mismatch_count', 'bad_char_count', 'depth_sum', 'timestamp_ready_count'];

    /** Leaf => [accepted type tags, date field]; tags: i int, f float, s string, l list, m map. */
    private const array W47_SCHEMA = [
        'abbreviation' => ['s', false], 'age_ratings' => ['l', false], 'aggregated_rating' => ['if', false], 'aggregated_rating_count' => ['i', false],
        'alternative_names' => ['l', false], 'artworks' => ['l', false], 'category' => ['i', false], 'checksum' => ['s', false],
        'company' => ['im', false], 'cover' => ['im', false], 'created_at' => ['i', true], 'date' => ['i', true],
        'first_release_date' => ['i', true], 'franchises' => ['l', false], 'game' => ['im', false], 'game_modes' => ['l', false],
        'genres' => ['l', false], 'human' => ['s', false], 'id' => ['i', false], 'image_id' => ['s', false],
        'involved_companies' => ['l', false], 'name' => ['s', false], 'platform' => ['im', false], 'platforms' => ['l', false],
        'popularity' => ['if', false], 'rating' => ['if', false], 'rating_count' => ['i', false], 'release_dates' => ['l', false],
        'screenshots' => ['l', false], 'similar_games' => ['l', false], 'slug' => ['s', false], 'start_date' => ['i', true],
        'status' => ['i', false], 'storyline' => ['s', false], 'summary' => ['s', false], 'themes' => ['l', false],
        'total_rating' => ['if', false], 'updated_at' => ['i', true], 'url' => ['s', false], 'websites' => ['l', false],
    ];

    private static ?bool $w47UseRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW47FieldPathRustFfi(?bool $enable = true): void
    {
        self::$w47UseRustFfi = $enable;
    }

    public static function usesW47FieldPathRustFfi(): bool
    {
        return self::$w47UseRustFfi ?? self::W47_FIELD_PATH_RUST_FFI_DEFAULT;
    }

    public static function resetW47FieldPathRustFfi(): void
    {
        self::$w47UseRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Summarize dotted IGDB field paths with the type of the value found
     * there: invalid paths (empty, empty segments, `*` other than as the
     * whole last segment, or bytes outside [a-z0-9_.*]), nested (2+) and
     * deep (3+) paths, trailing wildcards, the depth sum, leaves in the
     * IGDB schema, date leaves, schema type matches and mismatches, and date
     * leaves holding integers resolveDateTimestamp() can convert. Rows are
     * ['path' => string, 'value' => mixed] or bare path strings (untyped).
     *
     * @param  array<array-key, mixed>  $paths
     * @return array<string, int>
     */
    public static function summarizeFieldPathBatch(array $paths): array
    {
        return self::usesW47FieldPathRustFfi()
            ? self::summarizeFieldPathBatchNative($paths)
            : self::summarizeFieldPathBatchPhp($paths);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $paths
     * @return array<string, int>
     */
    public static function summarizeFieldPathBatchNative(array $paths): array
    {
        $fields = self::w47Fields($paths);

        return self::w47Call($fields) ?? self::w47Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $paths
     * @return array<string, int>
     */
    public static function summarizeFieldPathBatchPhp(array $paths): array
    {
        return self::w47Summary(self::w47Fields($paths));
    }

    /**
     * @param  array<array-key, mixed>  $paths
     * @return list<string>
     */
    private static function w47Fields(array $paths): array
    {
        $fields = [];
        foreach ($paths as $row) {
            if (! is_array($row)) {
                $fields[] = is_string($row) ? $row : (is_int($row) ? (string) $row : '');
                $fields[] = '';

                continue;
            }
            $tag = '';
            if (array_key_exists('value', $row)) {
                $value = $row['value'];
                $tag = match (true) {
                    is_int($value) => 'i',
                    is_float($value) => 'f',
                    is_string($value) => 's',
                    is_bool($value) => 'b',
                    $value === null => 'n',
                    is_array($value) => array_is_list($value) ? 'l' : 'm',
                    default => 'o',
                };
            }
            $v = $row['path'] ?? null;
            $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
            $fields[] = $tag;
        }

        return $fields;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private static function w47Summary(array $fields): array
    {
        $summary = array_fill_keys(self::W47_FIELD_PATH_KEYS, 0);
        for ($i = 0, $n = count($fields); $i < $n; $i += 2) {
            $summary['total_paths']++;
            $path = $fields[$i];
            if ($path === '') {
                $summary['invalid_path_count']++;

                continue;
            }
            $bad = strspn($path, 'abcdefghijklmnopqrstuvwxyz0123456789_.*') !== strlen($path);
            $summary['bad_char_count'] += (int) $bad;
            $segments = explode('.', $path);
            $depth = count($segments);
            $valid = ! $bad;
            foreach ($segments as $k => $segment) {
                if ($segment === '' || (str_contains($segment, '*') && ($segment !== '*' || $k !== $depth - 1))) {
                    $valid = false;
                    break;
                }
            }
            if (! $valid) {
                $summary['invalid_path_count']++;

                continue;
            }
            $leaf = $segments[$depth - 1];
            $summary['nested_path_count'] += (int) ($depth >= 2);
            $summary['deep_path_count'] += (int) ($depth >= 3);
            $summary['wildcard_path_count'] += (int) ($leaf === '*');
            $summary['depth_sum'] += $depth;
            if (! isset(self::W47_SCHEMA[$leaf])) {
                continue;
            }
            [$accepted, $date] = self::W47_SCHEMA[$leaf];
            $summary['date_leaf_count'] += (int) $date;
            $summary['known_leaf_count']++;
            $tag = $fields[$i + 1];
            if (strlen($tag) === 1 && str_contains('ifsbnlmo', $tag)) {
                $matched = str_contains($accepted, $tag);
                $summary['type_match_count'] += (int) $matched;
                $summary['type_mismatch_count'] += (int) ! $matched;
            }
            $summary['timestamp_ready_count'] += (int) ($date && $tag === 'i');
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
            if ($out === null || $ffi->nntmux_w47_igdb_datanode_field_path_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 2) {
                return null;
            }

            return array_combine(self::W47_FIELD_PATH_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
    }
}
