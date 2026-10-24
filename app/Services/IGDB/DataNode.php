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
}
