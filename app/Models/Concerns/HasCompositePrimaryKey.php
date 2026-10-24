<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Throwable;

/**
 * Scopes instance-level update, delete, refresh, and fresh queries to every
 * column of a table's composite primary key, which Eloquent's single
 * `$primaryKey` cannot express.
 *
 * `$primaryKey` must name the leading key column so relationship defaults and
 * `delete()`'s key-name guard keep working; `getKey()` therefore identifies a
 * row only together with the remaining compositeKeyColumns().
 */
trait HasCompositePrimaryKey
{
    public const string COMPOSITE_KEY_FFI_CDEF = <<<'CDEF'
ssize_t nntmux_composite_key_format_missing_message(
    const char *col_ptr,
    size_t col_len,
    const char *class_ptr,
    size_t class_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_composite_key_select_sources(
    size_t count,
    const uint8_t *in_original_mask,
    const uint8_t *in_attr_mask,
    uint8_t *out_source_codes,
    ssize_t *out_missing_index
);
CDEF;

    private static bool $useCompositeKeyRustFfi = false;

    private static FFI|false|null $compositeKeyFfi = null;

    public static function enableCompositeKeyRustFfi(): void
    {
        self::$useCompositeKeyRustFfi = true;
    }

    public static function disableCompositeKeyRustFfi(): void
    {
        self::$useCompositeKeyRustFfi = false;
    }

    public static function resetCompositeKeyRustFfi(): void
    {
        self::$useCompositeKeyRustFfi = false;
        self::$compositeKeyFfi = null;
    }

    public static function setUseCompositeKeyRustFfi(bool $useRustFfi): void
    {
        self::$useCompositeKeyRustFfi = $useRustFfi;
    }

    public static function usesCompositeKeyRustFfi(): bool
    {
        return self::$useCompositeKeyRustFfi;
    }

    public static function setCompositeKeyFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$compositeKeyFfi = $ffi;
    }

    public static function enableRustFfi(): void
    {
        self::$useCompositeKeyRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useCompositeKeyRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useCompositeKeyRustFfi = false;
        self::$compositeKeyFfi = null;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useCompositeKeyRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useCompositeKeyRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$compositeKeyFfi = $ffi;
    }

    public static function formatMissingCompositeKeyMessage(string $column, string $modelClass): string
    {
        if (self::$useCompositeKeyRustFfi) {
            return self::formatMissingCompositeKeyMessageNative($column, $modelClass);
        }

        return self::formatMissingCompositeKeyMessagePhp($column, $modelClass);
    }

    public static function formatMissingCompositeKeyMessagePhp(string $column, string $modelClass): string
    {
        return sprintf('Composite key column [%s] is missing on [%s].', $column, $modelClass);
    }

    public static function formatMissingCompositeKeyMessageNative(string $column, string $modelClass): string
    {
        $ffi = self::compositeKeyAdditionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatMissingCompositeKeyMessagePhp($column, $modelClass);
        }

        try {
            $cap = max(128, strlen($column) + strlen($modelClass) + 64);
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_composite_key_format_missing_message(
                $column,
                strlen($column),
                $modelClass,
                strlen($modelClass),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatMissingCompositeKeyMessagePhp($column, $modelClass);
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::formatMissingCompositeKeyMessagePhp($column, $modelClass);
        }
    }

    /**
     * @param  non-empty-list<string>  $columns
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function resolveCompositeKeyValues(
        array $columns,
        array $original,
        array $attributes,
        string $modelClass
    ): array {
        if (self::$useCompositeKeyRustFfi) {
            return self::resolveCompositeKeyValuesNative($columns, $original, $attributes, $modelClass);
        }

        return self::resolveCompositeKeyValuesPhp($columns, $original, $attributes, $modelClass);
    }

    /**
     * @param  non-empty-list<string>  $columns
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function resolveCompositeKeyValuesPhp(
        array $columns,
        array $original,
        array $attributes,
        string $modelClass
    ): array {
        $resolved = [];
        foreach ($columns as $column) {
            $value = $original[$column] ?? ($attributes[$column] ?? null);
            if ($value === null) {
                throw new LogicException(self::formatMissingCompositeKeyMessagePhp($column, $modelClass));
            }
            $resolved[$column] = $value;
        }

        return $resolved;
    }

    /**
     * @param  non-empty-list<string>  $columns
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function resolveCompositeKeyValuesNative(
        array $columns,
        array $original,
        array $attributes,
        string $modelClass
    ): array {
        $ffi = self::compositeKeyAdditionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolveCompositeKeyValuesPhp($columns, $original, $attributes, $modelClass);
        }

        $count = count($columns);
        try {
            $origMask = $ffi->new("uint8_t[{$count}]");
            $attrMask = $ffi->new("uint8_t[{$count}]");
            $outCodes = $ffi->new("uint8_t[{$count}]");
            $outMissing = $ffi->new('ssize_t[1]');

            foreach ($columns as $idx => $column) {
                $origMask[$idx] = ($original[$column] ?? null) !== null ? 1 : 0;
                $attrMask[$idx] = ($attributes[$column] ?? null) !== null ? 1 : 0;
            }

            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_composite_key_select_sources(
                $count,
                $origMask,
                $attrMask,
                $outCodes,
                $outMissing
            );
            $missingIdx = $outMissing[0];
        } catch (Throwable) {
            return self::resolveCompositeKeyValuesPhp($columns, $original, $attributes, $modelClass);
        }

        if ($rc === 0 && is_int($missingIdx) && isset($columns[$missingIdx])) {
            throw new LogicException(self::formatMissingCompositeKeyMessageNative($columns[$missingIdx], $modelClass));
        }

        if ($rc !== 1) {
            return self::resolveCompositeKeyValuesPhp($columns, $original, $attributes, $modelClass);
        }

        $resolved = [];
        foreach ($columns as $idx => $column) {
            $code = $outCodes[$idx];
            $resolved[$column] = $code === 1 ? $original[$column] : $attributes[$column];
        }

        return $resolved;
    }

    /**
     * Columns that together form the table's primary key, leading column first.
     *
     * @return non-empty-list<string>
     */
    abstract public function compositeKeyColumns(): array;

    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        return $this->whereCompositeKey($query);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $this->whereCompositeKey($query);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    private function whereCompositeKey(Builder $query): Builder
    {
        foreach ($this->compositeKeyColumns() as $column) {
            $value = $this->original[$column] ?? $this->getAttribute($column);

            if ($value === null) {
                throw new LogicException(self::formatMissingCompositeKeyMessage($column, static::class));
            }

            $query->where($column, '=', $value);
        }

        return $query;
    }

    private static function compositeKeyAdditionalFfi(): ?FFI
    {
        if (self::$compositeKeyFfi === false) {
            return null;
        }

        if (self::$compositeKeyFfi instanceof FFI) {
            return self::$compositeKeyFfi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::COMPOSITE_KEY_FFI_CDEF);
        self::$compositeKeyFfi = $ffi ?? false;

        return $ffi;
    }
}
