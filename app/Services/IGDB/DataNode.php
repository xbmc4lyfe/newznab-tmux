<?php

declare(strict_types=1);

namespace App\Services\IGDB;

use App\Support\RustPipelineLibrary;
use ArrayAccess;
use FFI;
use FFI\CData;
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

    // ========================================================================
    // WAVE 63.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.96x, p99 7.32x; uncommon mean 5.14x, p99 4.46x); see RustIgdbDataNodeBaseModelQueryBuilderIrcClientScraperW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_DATA_NODE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w631_data_node_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w63DataNodePlanBatch(). */
    public const int W63_DATA_NODE_TRAILER = 24;

    public static function usesW63RustFfi(): bool
    {
        $override = self::w63RustFfiState();

        return \is_bool($override) ? $override : self::W63_RUST_FFI_DEFAULT;
    }

    /** Forces the DataNode Wave 63 Rust path on until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Forces the DataNode Wave 63 PHP path until reset. */
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
     * Runs $callback with the DataNode Wave 63 Rust path forced on or off, then restores the previous setting.
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
     * Wave 63.1 IGDB data-node plans. Each item is `key|type|value|shape` (trimmed fields): the attribute key, a
     * one-letter type tag from `ifsbnlmo` (int, float, string, bool, null, list, map, object), the scalar value
     * and, for lists and maps, the comma-separated element tags. The action mirrors normalizeValue(): 0 empty key,
     * 1 date key whose int/float/string value is `-?digits(.digits)?` (Carbon timestamp), 2 scalar passed through,
     * 3 map wrapped in a DataNode, 4 list of scalars, 5 list holding nested lists or maps, 6 malformed (unknown or
     * empty tag, an unknown element tag in a container, or a shape on a scalar). Key class: 0 empty, 1
     * `[a-z0-9_]`, 2 `[A-Za-z0-9_]` with uppercase, 3 other bytes. Timestamp class: 0 none, 1 negative, 2 zero,
     * 3 before 2000, 4 before 2030, 5 later. Flags: 1 date key, 2 uppercase key, 4 non-ASCII key or value, 8 control
     * byte in the value, 16 value over 255 bytes, 32 list mixing scalars and containers, 64 key repeated in the
     * batch, 128 `id` or `*_id` relation key. Per item `action | type << 3 | flags << 7 | min(elements, 1023) << 15
     * | min(nodes, 1023) << 25 | bitLength(|timestamp|) << 35 | timestampClass << 41 | keyClass << 44 |
     * min(keyLength, 255) << 46 | min(valueLength, 255) << 54` (type 8 unknown, 9 empty; nodes are map elements
     * plus one for a map); then 7 action counts, 8 flag counts, the element, node, scalar, list and invalid element
     * sums, the distinct keys, the largest element count, the latest timestamp and the class-3 key count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63DataNodePlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63DataNodePlanBatchNative($items) : self::w63DataNodePlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63DataNodePlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_DATA_NODE_TRAILER) ?? self::w63DataNodePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63DataNodePlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W63_DATA_NODE_TRAILER, 0);
        $keys = [];
        foreach ($items as $item) {
            [$rawKey, $rawTag, $rawValue, $rawShape] = explode('|', (string) $item, 4) + ['', '', '', ''];
            $key = trim($rawKey);
            $tag = trim($rawTag);
            $value = trim($rawValue);
            $shape = trim($rawShape);
            $tagIdx = 8;
            if ($tag === '') {
                $tagIdx = 9;
            } elseif (\strlen($tag) === 1) {
                $position = strpos('ifsbnlmo', $tag);
                $tagIdx = $position === false ? 8 : $position;
            }
            $keyLength = \strlen($key);
            $keyClass = match (true) {
                $keyLength === 0 => 0,
                strspn($key, self::W63_LOWER_KEY_CHARS) === $keyLength => 1,
                strspn($key, self::W63_KEY_CHARS) === $keyLength => 2,
                default => 3,
            };
            $date = isset(self::W63_DATE_KEYS[$key]);
            $elements = $scalars = $lists = $maps = $invalid = 0;
            if ($shape !== '') {
                foreach (explode(',', $shape) as $element) {
                    $elements++;
                    $element = trim($element);
                    if ($element === 'l') {
                        $lists++;
                    } elseif ($element === 'm') {
                        $maps++;
                    } elseif (\strlen($element) === 1 && str_contains('ifsbn', $element)) {
                        $scalars++;
                    } else {
                        $invalid++;
                    }
                }
            }
            $ts = $date && $tagIdx <= 2 ? self::w63TimestampValue($value) : null;
            $tsClass = match (true) {
                $ts === null => 0,
                $ts < 0 => 1,
                $ts === 0 => 2,
                $ts < 946684800 => 3,
                $ts < 1893456000 => 4,
                default => 5,
            };
            $container = $tagIdx === 5 || $tagIdx === 6;
            $action = match (true) {
                $key === '' => 0,
                $tagIdx >= 8 => 6,
                $ts !== null => 1,
                $container && $invalid > 0 => 6,
                $tagIdx === 6 => 3,
                $tagIdx === 5 => $maps + $lists > 0 ? 5 : 4,
                $shape !== '' => 6,
                default => 2,
            };
            $nodes = $container ? $maps + ($tagIdx === 6 ? 1 : 0) : 0;
            $flags = ($date ? 1 : 0) | ($keyClass === 2 ? 2 : 0) | (preg_match('/[\x80-\xff]/', $key.$value) === 1 ? 4 : 0)
                | (strcspn($value, self::W63_CONTROL_BYTES) !== \strlen($value) ? 8 : 0) | (\strlen($value) > 255 ? 16 : 0)
                | ($scalars > 0 && $maps + $lists > 0 ? 32 : 0) | ($key === 'id' || str_ends_with($key, '_id') ? 128 : 0);
            if ($key !== '') {
                if (isset($keys[$key])) {
                    $flags |= 64;
                } else {
                    $keys[$key] = true;
                }
            }
            $tail[$action]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[7 + $b] += $flags >> $b & 1;
            }
            $tail[15] += $elements;
            $tail[16] += $nodes;
            $tail[17] += $scalars;
            $tail[18] += $lists;
            $tail[19] += $invalid;
            $tail[21] = max($tail[21], $elements);
            if ($action === 1) {
                $tail[22] = max($tail[22], (int) $ts);
            }
            if ($keyClass === 3) {
                $tail[23]++;
            }
            $out[] = $action | $tagIdx << 3 | $flags << 7 | min($elements, 1023) << 15 | min($nodes, 1023) << 25
                | self::w63BitLength(abs((int) $ts)) << 35 | $tsClass << 41 | $keyClass << 44 | min($keyLength, 255) << 46
                | min(\strlen($value), 255) << 54;
        }
        $tail[20] = \count($keys);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, true> */
    private const array W63_DATE_KEYS = ['created_at' => true, 'updated_at' => true, 'change_date' => true, 'start_date' => true,
        'published_at' => true, 'first_release_date' => true, 'date' => true];

    private const string W63_LOWER_KEY_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789_';

    private const string W63_KEY_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    private const string W63_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** Optional `-`, 1-15 digits, then optionally `.` and up to 15 digits: the truncated integer, else null. */
    private static function w63TimestampValue(string $value): ?int
    {
        $negative = str_starts_with($value, '-');
        $rest = $negative ? substr($value, 1) : $value;
        $intLength = strspn($rest, '0123456789');
        if ($intLength === 0 || $intLength > 15) {
            return null;
        }
        $fraction = substr($rest, $intLength);
        if ($fraction !== '' && ($fraction[0] !== '.' || \strlen($fraction) > 16 || strspn($fraction, '0123456789', 1) !== \strlen($fraction) - 1)) {
            return null;
        }
        $whole = (int) substr($rest, 0, $intLength);

        return $negative ? -$whole : $whole;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('igdb_data_node_w631', self::W63_DATA_NODE_FFI_CDEF) ?? false;

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
     * Calls the Wave 63.1 DataNode export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W63_DATA_NODE_FFI_CDEF.)
            $written = $ffi->nntmux_w631_data_node_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
