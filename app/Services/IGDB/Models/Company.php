<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class Company extends BaseModel
{
    protected const string ENDPOINT = 'companies';

    public const string RECORD_FFI_CDEF = <<<'C'
        ssize_t nntmux_w35_igdb_company_record(
            int64_t id,
            uint32_t has_country,
            int64_t country,
            const char *name_ptr,
            size_t name_len,
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

    private const int COUNTRY_MAX = 999;

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
     * Normalizes an IGDB company row: a non-positive id becomes null, the name is trimmed with
     * whitespace runs collapsed, the slug is `[a-z0-9-]` and the country must be an ISO 3166-1
     * numeric code (1-999).
     *
     * @return array{id: ?int, name: string, slug: string, country: ?int, is_valid: bool}
     */
    public static function normalizeCompanyRecord(int $id, string $name, ?int $country = null): array
    {
        return self::usesRustFfi()
            ? self::normalizeCompanyRecordNative($id, $name, $country)
            : self::normalizeCompanyRecordPhp($id, $name, $country);
    }

    /**
     * @return array{id: ?int, name: string, slug: string, country: ?int, is_valid: bool}
     */
    public static function normalizeCompanyRecordNative(int $id, string $name, ?int $country = null): array
    {
        $fields = self::callNative('nntmux_w35_igdb_company_record', [$id, $country === null ? 0 : 1, $country ?? 0, $name, strlen($name)]);
        if ($fields === null || count($fields) !== 5 || $fields[1] === null || $fields[2] === null || $fields[4] === null) {
            return self::normalizeCompanyRecordPhp($id, $name, $country);
        }

        return [
            'id' => $fields[0] === null ? null : (int) $fields[0],
            'name' => $fields[1],
            'slug' => $fields[2],
            'country' => $fields[3] === null ? null : (int) $fields[3],
            'is_valid' => $fields[4] === '1',
        ];
    }

    /**
     * @return array{id: ?int, name: string, slug: string, country: ?int, is_valid: bool}
     */
    public static function normalizeCompanyRecordPhp(int $id, string $name, ?int $country = null): array
    {
        $id = $id > 0 ? $id : null;
        $name = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($name, self::WHITESPACE)) ?? '';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
        $country = $country !== null && $country >= 1 && $country <= self::COUNTRY_MAX ? $country : null;

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
            'country' => $country,
            'is_valid' => $id !== null && $name !== '',
        ];
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
