<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Services\IGDB\DataNode;
use App\Services\IGDB\QueryBuilder;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

abstract class BaseModel extends DataNode
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_igdb_model_normalize_endpoint(const char *ptr, size_t len, char *out, size_t cap);
        ssize_t nntmux_igdb_model_normalize_key(const char *ptr, size_t len, char *out, size_t cap);
        int32_t nntmux_igdb_model_extract_id(const char *ser, size_t ser_len, const char *key, size_t key_len, int64_t *out_id);
        ssize_t nntmux_igdb_model_extract_ids(const char *ser, size_t ser_len, const char *key, size_t key_len, int64_t *out_ids, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvIgdbSteamMetadataParityTest). */
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

    protected const string ENDPOINT = '';

    /**
     * @var array<int, string>
     */
    protected const array DATE_FIELDS = [
        'created_at',
        'updated_at',
        'change_date',
        'start_date',
        'published_at',
        'first_release_date',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes, static::DATE_FIELDS);
    }

    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::class);
    }

    public static function endpoint(): string
    {
        return self::normalizeEndpoint(static::ENDPOINT);
    }

    public static function normalizeEndpoint(string $endpoint): string
    {
        return self::usesRustFfi()
            ? self::normalizeEndpointNative($endpoint)
            : self::normalizeEndpointPhp($endpoint);
    }

    public static function normalizeEndpointNative(string $endpoint): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeEndpointPhp($endpoint);
        }
        $len = \strlen($endpoint);
        if ($len === 0) {
            return '';
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $len + 16), $capacity);
        if ($buffer === null) {
            return self::normalizeEndpointPhp($endpoint);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_model_normalize_endpoint($endpoint, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeEndpointPhp($endpoint);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_igdb_model_normalize_endpoint($endpoint, $len, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::normalizeEndpointPhp($endpoint);
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::normalizeEndpointPhp($endpoint);
        }
    }

    public static function normalizeEndpointPhp(string $endpoint): string
    {
        $trimmed = trim($endpoint, " \t\n\r\0\x0B/");
        if ($trimmed === '') {
            return '';
        }
        $out = '';
        $prevSlash = false;
        $len = \strlen($trimmed);
        for ($i = 0; $i < $len; $i++) {
            $ch = $trimmed[$i];
            if ($ch === '/') {
                if (! $prevSlash) {
                    $out .= '/';
                    $prevSlash = true;
                }
            } elseif (! \in_array($ch, [' ', "\t", "\n", "\r", "\0", "\x0B"], true)) {
                $out .= strtolower($ch);
                $prevSlash = false;
            }
        }

        return $out;
    }

    public static function normalizeAttributeKey(string $key): string
    {
        return self::usesRustFfi()
            ? self::normalizeAttributeKeyNative($key)
            : self::normalizeAttributeKeyPhp($key);
    }

    public static function normalizeAttributeKeyNative(string $key): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeAttributeKeyPhp($key);
        }
        $len = \strlen($key);
        if ($len === 0) {
            return '';
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, ($len * 2) + 16), $capacity);
        if ($buffer === null) {
            return self::normalizeAttributeKeyPhp($key);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_model_normalize_key($key, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeAttributeKeyPhp($key);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_igdb_model_normalize_key($key, $len, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::normalizeAttributeKeyPhp($key);
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::normalizeAttributeKeyPhp($key);
        }
    }

    public static function normalizeAttributeKeyPhp(string $key): string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return '';
        }
        $len = \strlen($trimmed);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $trimmed[$i];
            if (\in_array($ch, ['_', '-', '.', ' ', "\t", "\n", "\r", "\0", "\x0B"], true)) {
                if ($out !== '' && ! str_ends_with($out, '_')) {
                    $out .= '_';
                }

                continue;
            }
            if (ctype_upper($ch)) {
                $prevLowerOrDigit = $i > 0 && (ctype_lower($trimmed[$i - 1]) || ctype_digit($trimmed[$i - 1]));
                $prevUpperNextLower = $i > 0
                    && ctype_upper($trimmed[$i - 1])
                    && ($i + 1 < $len && ctype_lower($trimmed[$i + 1]));
                if (($prevLowerOrDigit || $prevUpperNextLower) && $out !== '' && ! str_ends_with($out, '_')) {
                    $out .= '_';
                }
                $out .= strtolower($ch);
            } else {
                $out .= $ch;
            }
        }

        return rtrim($out, '_');
    }

    public function getId(): ?int
    {
        return self::extractIdentifier($this->attributes, 'id');
    }

    public static function extractIdentifier(mixed $source, string $key = 'id'): ?int
    {
        return self::usesRustFfi()
            ? self::extractIdentifierNative($source, $key)
            : self::extractIdentifierPhp($source, $key);
    }

    public static function extractIdentifierNative(mixed $source, string $key = 'id'): ?int
    {
        if ($source instanceof DataNode || \is_array($source)) {
            $val = $source[$key] ?? null;
            if (\is_object($val) || \is_array($val)) {
                return self::extractIdentifierPhp($source, $key);
            }
            $payload = [$key => $val];
        } elseif (\is_object($source)) {
            return self::extractIdentifierPhp($source, $key);
        } else {
            $payload = $source;
        }
        if (($ffi = self::ffi()) === null) {
            return self::extractIdentifierPhp($source, $key);
        }
        try {
            $ser = serialize($payload);
            $outId = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_igdb_model_extract_id($ser, \strlen($ser), $key, \strlen($key), $outId);
            if ($res === 1) {
                return (int) $outId[0];
            }
            if ($res === 0) {
                return null;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::extractIdentifierPhp($source, $key);
    }

    public static function extractIdentifierPhp(mixed $source, string $key = 'id'): ?int
    {
        $raw = $source;
        if ($source instanceof DataNode) {
            $raw = $source[$key] ?? null;
        } elseif (\is_array($source)) {
            $raw = $source[$key] ?? null;
        } elseif (\is_object($source)) {
            $raw = $source->{$key} ?? null;
        }

        if (\is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (\is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed !== '' && preg_match('/^[+-]?\d+$/', $trimmed) === 1) {
                $val = (int) $trimmed;

                return $val > 0 ? $val : null;
            }
        }

        return null;
    }

    /**
     * Extract deduplicated positive integer identifiers in encounter order.
     *
     * @param  array<int|string, mixed>  $items
     * @return list<int>
     */
    public static function extractIdentifiers(array $items, string $key = 'id'): array
    {
        return self::usesRustFfi()
            ? self::extractIdentifiersNative($items, $key)
            : self::extractIdentifiersPhp($items, $key);
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<int>
     */
    public static function extractIdentifiersNative(array $items, string $key = 'id'): array
    {
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        if (($ffi = self::ffi()) === null) {
            return self::extractIdentifiersPhp($items, $key);
        }
        $normalized = [];
        foreach ($items as $item) {
            if ($item instanceof DataNode || \is_array($item)) {
                $val = $item[$key] ?? null;
                if (\is_object($val) || \is_array($val)) {
                    return self::extractIdentifiersPhp($items, $key);
                }
                $normalized[] = [$key => $val];
            } elseif (\is_object($item)) {
                return self::extractIdentifiersPhp($items, $key);
            } else {
                $normalized[] = $item;
            }
        }
        try {
            $ser = serialize($normalized);
            $outIds = $ffi->new("int64_t[{$count}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_model_extract_ids($ser, \strlen($ser), $key, \strlen($key), $outIds, $count);
            if ($written === 0) {
                return [];
            }
            if (! \is_int($written) || $written < 0 || $written > $count) {
                return self::extractIdentifiersPhp($items, $key);
            }
            $result = [];
            for ($i = 0; $i < $written; $i++) {
                $result[] = (int) $outIds[$i];
            }

            return $result;
        } catch (Throwable) {
            return self::extractIdentifiersPhp($items, $key);
        }
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<int>
     */
    public static function extractIdentifiersPhp(array $items, string $key = 'id'): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $id = self::extractIdentifierPhp($item, $key);
            if ($id !== null && ! isset($seen[$id])) {
                $seen[$id] = true;
                $result[] = $id;
            }
        }

        return $result;
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 3.21x, p99 2.35x; uncommon mean 9.13x, p99 6.11x; see the W48 parity test). */
    public const bool W48_ATTRIBUTE_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_igdb_model_attribute_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W48_ATTRIBUTE_KEYS = ['total_attributes', 'empty_key_count', 'renamed_key_count', 'date_field_count', 'date_castable_count', 'int_coercible_count', 'relation_field_count', 'relation_id_count', 'relation_id_sum', 'type_mismatch_count', 'key_digest', 'max_relation_ids'];

    /** Normalized key => [kind, accepted type tags]; kinds: d date, r relation, s text, n number. */
    private const array W48_ATTRIBUTE_SCHEMA = [
        'created_at' => ['d', 'i'], 'updated_at' => ['d', 'i'], 'change_date' => ['d', 'i'], 'start_date' => ['d', 'i'], 'published_at' => ['d', 'i'], 'first_release_date' => ['d', 'i'],
        'cover' => ['r', 'ilm'], 'platforms' => ['r', 'ilm'], 'platform' => ['r', 'ilm'], 'genres' => ['r', 'ilm'], 'themes' => ['r', 'ilm'], 'game_modes' => ['r', 'ilm'],
        'involved_companies' => ['r', 'ilm'], 'company' => ['r', 'ilm'], 'game' => ['r', 'ilm'], 'franchises' => ['r', 'ilm'], 'collection' => ['r', 'ilm'], 'artworks' => ['r', 'ilm'],
        'screenshots' => ['r', 'ilm'], 'similar_games' => ['r', 'ilm'], 'websites' => ['r', 'ilm'], 'age_ratings' => ['r', 'ilm'], 'alternative_names' => ['r', 'ilm'], 'release_dates' => ['r', 'ilm'],
        'parent_game' => ['r', 'ilm'], 'developed' => ['r', 'ilm'], 'published' => ['r', 'ilm'], 'logo' => ['r', 'ilm'], 'platform_family' => ['r', 'ilm'], 'versions' => ['r', 'ilm'],
        'name' => ['s', 's'], 'slug' => ['s', 's'], 'summary' => ['s', 's'], 'storyline' => ['s', 's'], 'url' => ['s', 's'], 'checksum' => ['s', 's'], 'abbreviation' => ['s', 's'],
        'alternative_name' => ['s', 's'], 'description' => ['s', 's'], 'image_id' => ['s', 's'],
        'id' => ['n', 'if'], 'rating' => ['n', 'if'], 'aggregated_rating' => ['n', 'if'], 'total_rating' => ['n', 'if'], 'rating_count' => ['n', 'if'], 'category' => ['n', 'if'],
        'status' => ['n', 'if'], 'generation' => ['n', 'if'], 'country' => ['n', 'if'], 'popularity' => ['n', 'if'],
    ];

    private static ?bool $w48UseRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW48AttributeRustFfi(?bool $enable = true): void
    {
        self::$w48UseRustFfi = $enable;
    }

    public static function usesW48AttributeRustFfi(): bool
    {
        return self::$w48UseRustFfi ?? self::W48_ATTRIBUTE_RUST_FFI_DEFAULT;
    }

    public static function resetW48AttributeRustFfi(): void
    {
        self::$w48UseRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Summarize raw IGDB attributes before hydration: keys normalized as
     * normalizeAttributeKeyPhp() does in the C locale (empty and renamed keys plus an FNV-1a
     * digest of the normalized keys), extractIdentifierPhp()-style integer
     * coercion, date fields and those holding a positive 1-10 digit
     * timestamp, relation fields with their unique positive 1-12 digit IDs
     * (count, sum, per-row maximum) and schema type mismatches. Rows are
     * ['key' => string, 'value' => mixed] or bare keys (untyped).
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, int>
     */
    public static function summarizeAttributeBatch(array $attributes): array
    {
        return self::usesW48AttributeRustFfi()
            ? self::summarizeAttributeBatchNative($attributes)
            : self::summarizeAttributeBatchPhp($attributes);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, int>
     */
    public static function summarizeAttributeBatchNative(array $attributes): array
    {
        $fields = self::w48Fields($attributes);

        return self::w48Call($fields) ?? self::w48Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, int>
     */
    public static function summarizeAttributeBatchPhp(array $attributes): array
    {
        return self::w48Summary(self::w48Fields($attributes));
    }

    /**
     * [key, type tag, value text]; lists join element IDs with commas and maps keep their id.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return list<string>
     */
    private static function w48Fields(array $attributes): array
    {
        $fields = [];
        foreach ($attributes as $row) {
            if (! is_array($row)) {
                $fields[] = is_string($row) ? $row : (is_int($row) ? (string) $row : '');
                $fields[] = '';
                $fields[] = '';

                continue;
            }
            $key = $row['key'] ?? null;
            $fields[] = is_string($key) ? $key : (is_int($key) ? (string) $key : '');
            if (! array_key_exists('value', $row)) {
                $fields[] = '';
                $fields[] = '';

                continue;
            }
            $value = $row['value'];
            if (is_int($value)) {
                $fields[] = 'i';
                $fields[] = (string) $value;
            } elseif (is_string($value)) {
                $fields[] = 's';
                $fields[] = $value;
            } elseif (is_array($value)) {
                if (array_is_list($value)) {
                    $ids = [];
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $item = $item['id'] ?? null;
                        }
                        $ids[] = is_string($item) ? $item : (is_int($item) ? (string) $item : '');
                    }
                    $fields[] = 'l';
                    $fields[] = implode(',', $ids);
                } else {
                    $id = $value['id'] ?? null;
                    $fields[] = 'm';
                    $fields[] = is_string($id) ? $id : (is_int($id) ? (string) $id : '');
                }
            } else {
                $fields[] = match (true) {
                    is_float($value) => 'f',
                    is_bool($value) => 'b',
                    $value === null => 'n',
                    default => 'o',
                };
                $fields[] = '';
            }
        }

        return $fields;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private static function w48Summary(array $fields): array
    {
        $summary = array_fill_keys(self::W48_ATTRIBUTE_KEYS, 0);
        $digest = hash_init('fnv1a64');
        for ($i = 0, $n = count($fields); $i < $n; $i += 3) {
            $summary['total_attributes']++;
            $key = self::w48NormalizeKey($fields[$i]);
            if ($key === '') {
                $summary['empty_key_count']++;

                continue;
            }
            $summary['renamed_key_count'] += (int) ($key !== $fields[$i]);
            hash_update($digest, $key."\n");
            $tag = $fields[$i + 1];
            $text = $fields[$i + 2];
            if ($tag === 'i') {
                $summary['int_coercible_count']++;
            } elseif ($tag === 's') {
                $summary['int_coercible_count'] += (int) (preg_match('/^[+-]?\d{1,18}$/D', trim($text)) === 1);
            }
            if (! isset(self::W48_ATTRIBUTE_SCHEMA[$key])) {
                continue;
            }
            [$kind, $accepted] = self::W48_ATTRIBUTE_SCHEMA[$key];
            if (strlen($tag) === 1 && str_contains('ifsbnlmo', $tag)) {
                $summary['type_mismatch_count'] += (int) ! str_contains($accepted, $tag);
            }
            if ($kind === 'd') {
                $summary['date_field_count']++;
                $summary['date_castable_count'] += (int) (($tag === 'i' || $tag === 's') && $text !== '' && strlen($text) <= 10 && ctype_digit($text) && (int) $text > 0);
            } elseif ($kind === 'r') {
                $summary['relation_field_count']++;
                $tokens = match ($tag) {
                    'i', 's', 'm' => [$text],
                    'l' => explode(',', $text),
                    default => [],
                };
                $ids = [];
                foreach ($tokens as $token) {
                    $token = trim($token);
                    if (($token[0] ?? '') === '+') {
                        $token = substr($token, 1);
                    }
                    if ($token !== '' && strlen($token) <= 12 && ctype_digit($token) && (int) $token > 0) {
                        $ids[(int) $token] = true;
                    }
                }
                $unique = count($ids);
                $summary['relation_id_count'] += $unique;
                $summary['relation_id_sum'] += array_sum(array_keys($ids));
                $summary['max_relation_ids'] = max($summary['max_relation_ids'], $unique);
            }
        }
        $value = unpack('J', hash_final($digest, true));
        $summary['key_digest'] = is_array($value) ? (int) $value[1] : 0;

        return $summary;
    }

    /**
     * normalizeAttributeKeyPhp() with ASCII byte classes: libc ctype under macOS
     * C.UTF-8 also classifies Latin-1 bytes, so ctype_*() would make the
     * summary depend on the host locale.
     */
    private static function w48NormalizeKey(string $key): string
    {
        $trimmed = trim($key);
        $out = '';
        for ($i = 0, $len = \strlen($trimmed); $i < $len; $i++) {
            $ch = $trimmed[$i];
            if (str_contains("_-. \t\n\r\0\x0B", $ch)) {
                if ($out !== '' && ! str_ends_with($out, '_')) {
                    $out .= '_';
                }

                continue;
            }
            if ($ch >= 'A' && $ch <= 'Z') {
                $prev = $i > 0 ? $trimmed[$i - 1] : '';
                $next = $trimmed[$i + 1] ?? '';
                $split = ($prev >= 'a' && $prev <= 'z') || ($prev >= '0' && $prev <= '9')
                    || ($prev >= 'A' && $prev <= 'Z' && $next >= 'a' && $next <= 'z');
                if ($split && $out !== '' && ! str_ends_with($out, '_')) {
                    $out .= '_';
                }
                $out .= strtolower($ch);
            } else {
                $out .= $ch;
            }
        }

        return rtrim($out, '_');
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>|null
     */
    private static function w48Call(array $fields): ?array
    {
        try {
            $input = serialize($fields);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w48Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w48', self::W48_FFI_CDEF) ?? false;
            $ffi = self::$w48Ffi;
            if ($ffi === false) {
                return null;
            }
            // obtainScratch() returns at least the 96 bytes requested.
            $out = RustPipelineLibrary::obtainScratch($ffi, 96);
            // @phpstan-ignore method.notFound (Declared by W48_FFI_CDEF.)
            if ($out === null || $ffi->nntmux_w48_igdb_model_attribute_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 3) {
                return null;
            }

            return array_combine(self::W48_ATTRIBUTE_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
    }

    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::query()->{$method}(...$parameters);
    }

    // ========================================================================
    // WAVE 63.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 4.05x, p99 3.43x; uncommon mean 4.73x, p99 3.57x); see RustIgdbDataNodeBaseModelQueryBuilderIrcClientScraperW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    public const string W63_MODEL_FFI_CDEF = <<<'C'
        ssize_t nntmux_w631_model_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w63ModelPlanBatch(). */
    public const int W63_MODEL_TRAILER = 23;

    public static function usesW63RustFfi(): bool
    {
        $override = self::w63RustFfiState();

        return \is_bool($override) ? $override : self::W63_RUST_FFI_DEFAULT;
    }

    /** Forces the BaseModel Wave 63 Rust path on until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Forces the BaseModel Wave 63 PHP path until reset. */
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
     * Runs $callback with the BaseModel Wave 63 Rust path forced on or off, then restores the previous setting.
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
     * Wave 63.1 IGDB model request plans. Each item is `endpoint|id|fields|limit|offset`. The endpoint is
     * normalized as normalizeEndpointPhp() (flags 1 uppercase, 2 inner whitespace dropped, 4 slashes collapsed, 8
     * outer whitespace or slashes trimmed, 16 bytes outside `[a-z0-9_/]`, 32 nested path) and indexed 1-22 in
     * W63_ENDPOINTS (0 empty, 31 unknown). The id is 1-15 digits (empty is none). Fields are a trimmed
     * comma-separated projection: an empty or non-`[a-z0-9_.*]` field, an empty dot segment, or `*` other than as
     * the whole last segment is invalid; wildcards end in `*`, nested fields hold a dot and repeats are dupes.
     * Limit (default 10; flag 64 above 500) and offset (default 0; flag 128 above 5000) are 1-15 digits. The cache
     * key is `endpoint:id:fields:limit:offset` from the normalized endpoint and the trimmed fields; flag 256 marks
     * a key repeated in the batch. Decision: 0 empty endpoint, 1 unknown or invalid endpoint, 2 invalid id, 3
     * invalid field, 4 invalid limit or offset, 5 fetch by id, 6 list query. Per item `decision | endpoint << 3 |
     * flags << 8 | bitLength(id) << 17 | min(fields, 63) << 23 | min(wildcards, 15) << 29 | min(nested, 63) << 33
     * | min(invalid, 63) << 39 | min(dupes, 15) << 45 | (fnv1a32(cacheKey) & 0x3fff) << 49`; then 7 decision
     * counts, 9 flag counts, the field, wildcard and invalid sums, the clamped limit sum of decisions 5-6, the
     * largest offset, and the distinct endpoints and cache keys.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63ModelPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63ModelPlanBatchNative($items) : self::w63ModelPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63ModelPlanBatchNative(array $items): array
    {
        return self::w63Invoke($items, \count($items) + self::W63_MODEL_TRAILER) ?? self::w63ModelPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w63ModelPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W63_MODEL_TRAILER, 0);
        $endpoints = [];
        $keys = [];
        foreach ($items as $item) {
            [$rawEndpoint, $rawId, $rawFields, $rawLimit, $rawOffset] = explode('|', (string) $item, 5) + ['', '', '', '', ''];
            $trimmed = trim($rawEndpoint, " \t\n\r\0\x0B/");
            $flags = \strlen($trimmed) !== \strlen($rawEndpoint) ? 8 : 0;
            $norm = '';
            $prevSlash = false;
            for ($i = 0, $n = \strlen($trimmed); $i < $n; $i++) {
                $ch = $trimmed[$i];
                if ($ch === '/') {
                    if ($prevSlash) {
                        $flags |= 4;
                    } else {
                        $norm .= '/';
                        $prevSlash = true;
                    }
                } elseif (str_contains(" \t\n\r\0\x0B", $ch)) {
                    $flags |= 2;
                } else {
                    if ($ch >= 'A' && $ch <= 'Z') {
                        $flags |= 1;
                    }
                    $norm .= strtolower($ch);
                    $prevSlash = false;
                }
            }
            $endpoint = $norm === '' ? 0 : (self::W63_ENDPOINTS[$norm] ?? 31);
            $badChars = strspn($norm, 'abcdefghijklmnopqrstuvwxyz0123456789_/') !== \strlen($norm);
            $flags |= ($badChars ? 16 : 0) | (str_contains($norm, '/') ? 32 : 0);
            $id = self::w63Uint($rawId, 0);
            $projection = trim($rawFields);
            $count = $wild = $nested = $invalid = $dupes = 0;
            if ($projection !== '') {
                $seen = [];
                foreach (explode(',', $projection) as $rawSegment) {
                    $count++;
                    $segment = trim($rawSegment);
                    if ($segment === '') {
                        $invalid++;

                        continue;
                    }
                    if (isset($seen[$segment])) {
                        $dupes++;
                    } else {
                        $seen[$segment] = true;
                    }
                    $parts = explode('.', $segment);
                    $last = \count($parts) - 1;
                    $valid = strspn($segment, 'abcdefghijklmnopqrstuvwxyz0123456789_.*') === \strlen($segment);
                    foreach ($parts as $k => $part) {
                        if (! $valid) {
                            break;
                        }
                        if ($part === '' || (str_contains($part, '*') && ($part !== '*' || $k !== $last))) {
                            $valid = false;
                        }
                    }
                    if (! $valid) {
                        $invalid++;

                        continue;
                    }
                    $wild += $parts[$last] === '*' ? 1 : 0;
                    $nested += $last > 0 ? 1 : 0;
                }
            }
            $limit = self::w63Uint($rawLimit, 10);
            $offset = self::w63Uint($rawOffset, 0);
            $flags |= ($limit > 500 ? 64 : 0) | ($offset > 5000 ? 128 : 0);
            $key = $norm.':'.trim($rawId).':'.$projection.':'.trim($rawLimit).':'.trim($rawOffset);
            $digest = (int) hexdec(hash('fnv1a32', $key));
            if (isset($keys[$key])) {
                $flags |= 256;
            } else {
                $keys[$key] = true;
            }
            if ($norm !== '') {
                $endpoints[$norm] = true;
            }
            $decision = match (true) {
                $norm === '' => 0,
                $endpoint === 31 || $badChars => 1,
                $id < 0 => 2,
                $invalid > 0 => 3,
                $limit < 0 || $offset < 0 => 4,
                $id > 0 => 5,
                default => 6,
            };
            $tail[$decision]++;
            for ($b = 0; $b < 9; $b++) {
                $tail[7 + $b] += $flags >> $b & 1;
            }
            $tail[16] += $count;
            $tail[17] += $wild;
            $tail[18] += $invalid;
            if ($decision >= 5) {
                $tail[19] += max(1, min(500, $limit));
            }
            $tail[20] = max($tail[20], $offset);
            $out[] = $decision | $endpoint << 3 | $flags << 8 | self::w63BitLength($id) << 17 | min($count, 63) << 23 | min($wild, 15) << 29
                | min($nested, 63) << 33 | min($invalid, 63) << 39 | min($dupes, 15) << 45 | ($digest & 0x3FFF) << 49;
        }
        $tail[21] = \count($endpoints);
        $tail[22] = \count($keys);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var array<string, int> */
    private const array W63_ENDPOINTS = ['games' => 1, 'platforms' => 2, 'companies' => 3, 'covers' => 4, 'genres' => 5, 'themes' => 6,
        'game_modes' => 7, 'involved_companies' => 8, 'release_dates' => 9, 'screenshots' => 10, 'artworks' => 11, 'websites' => 12,
        'franchises' => 13, 'age_ratings' => 14, 'alternative_names' => 15, 'platform_logos' => 16, 'game_videos' => 17, 'keywords' => 18,
        'collections' => 19, 'search' => 20, 'player_perspectives' => 21, 'multiquery' => 22];

    /** An unsigned field of 1-15 digits after trimming; empty is $empty, anything else -1. */
    private static function w63Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, '0123456789') === $length ? (int) $value : -1;
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
            $ffi ??= RustPipelineLibrary::resolveDomain('igdb_model_w631', self::W63_MODEL_FFI_CDEF) ?? false;

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
     * Calls the Wave 63.1 BaseModel export with the NUL-joined $items, or with the items concatenated plus an
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
            // @phpstan-ignore method.notFound (Declared by W63_MODEL_FFI_CDEF.)
            $written = $ffi->nntmux_w631_model_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
