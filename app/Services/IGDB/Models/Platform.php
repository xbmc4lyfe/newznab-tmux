<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class Platform extends BaseModel
{
    protected const string ENDPOINT = 'platforms';

    public const string RECORD_FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_igdb_platform_record(
            int64_t id,
            uint32_t has_abbreviation,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustIgdbBookTvProviderMetadataParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Toggles only the helper below; BaseModel keeps its own endpoint/key toggle.
     */
    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes an IGDB platform row: a non-positive id becomes null, the name is trimmed with
     * whitespace runs collapsed, the slug is `[a-z0-9-]`, the abbreviation is upper-cased (blank
     * becomes null) and the label prefers the abbreviation.
     *
     * @return array{id: ?int, name: string, slug: string, abbreviation: ?string, label: string, is_valid: bool}
     */
    public static function normalizePlatformRecord(int $id, string $name, ?string $abbreviation = null): array
    {
        return self::usesRustFfi()
            ? self::normalizePlatformRecordNative($id, $name, $abbreviation)
            : self::normalizePlatformRecordPhp($id, $name, $abbreviation);
    }

    /**
     * @return array{id: ?int, name: string, slug: string, abbreviation: ?string, label: string, is_valid: bool}
     */
    public static function normalizePlatformRecordNative(int $id, string $name, ?string $abbreviation = null): array
    {
        $input = $name.self::FIELD_SEPARATOR.($abbreviation ?? '');
        $fields = self::callNative('nntmux_w35_igdb_platform_record', [$id, $abbreviation === null ? 0 : 1, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 6 || $fields[1] === null || $fields[2] === null || $fields[4] === null || $fields[5] === null) {
            return self::normalizePlatformRecordPhp($id, $name, $abbreviation);
        }

        return [
            'id' => $fields[0] === null ? null : (int) $fields[0],
            'name' => $fields[1],
            'slug' => $fields[2],
            'abbreviation' => $fields[3],
            'label' => $fields[4],
            'is_valid' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{id: ?int, name: string, slug: string, abbreviation: ?string, label: string, is_valid: bool}
     */
    public static function normalizePlatformRecordPhp(int $id, string $name, ?string $abbreviation = null): array
    {
        $id = $id > 0 ? $id : null;
        $name = self::collapseWhitespace($name);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        if ($abbreviation !== null) {
            $abbreviation = strtoupper(self::collapseWhitespace($abbreviation));
            $abbreviation = $abbreviation === '' ? null : $abbreviation;
        }

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'abbreviation' => $abbreviation,
            'label' => $abbreviation ?? $name,
            'is_valid' => $id !== null && $name !== '',
        ];
    }

    private static function collapseWhitespace(string $value): string
    {
        return preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($value, self::WHITESPACE)) ?? '';
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::RECORD_FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
