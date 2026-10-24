<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Services\IGDB\DataNode;
use App\Services\IGDB\QueryBuilder;
use App\Support\RustPipelineLibrary;
use FFI;
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

    public static function __callStatic(string $method, array $parameters): mixed
    {
        return static::query()->{$method}(...$parameters);
    }
}
