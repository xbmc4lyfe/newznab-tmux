<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\Country.
 *
 * @property string $iso_3166_2
 * @property string $name
 * @property string|null $full_name
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Country whereFullName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Country whereIso31662($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Country whereName($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Country newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Country newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Country query()
 */
class Country extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_country_normalize_iso_record(
            uint32_t has_full_name,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustCategoryGroupParhashModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const string ASCII_LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $primaryKey = 'iso_3166_2';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes a `countries` row: accepts only two ASCII letters as the uppercase ISO 3166-1
     * alpha-2 code, collapses and truncates the names and maps an empty full name to null.
     *
     * @return array{iso_3166_2: ?string, name: string, full_name: ?string, is_valid: bool}
     */
    public static function normalizeIsoCountryRecord(string $isoCode, string $name, ?string $fullName = null): array
    {
        return self::usesRustFfi()
            ? self::normalizeIsoCountryRecordNative($isoCode, $name, $fullName)
            : self::normalizeIsoCountryRecordPhp($isoCode, $name, $fullName);
    }

    /**
     * @return array{iso_3166_2: ?string, name: string, full_name: ?string, is_valid: bool}
     */
    public static function normalizeIsoCountryRecordNative(string $isoCode, string $name, ?string $fullName = null): array
    {
        $input = $isoCode.self::FIELD_SEPARATOR.$name.self::FIELD_SEPARATOR.($fullName ?? '');
        $fields = self::callNative('nntmux_country_normalize_iso_record', [$fullName === null ? 0 : 1, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 4 || $fields[1] === null || $fields[3] === null) {
            return self::normalizeIsoCountryRecordPhp($isoCode, $name, $fullName);
        }

        return [
            'iso_3166_2' => $fields[0],
            'name' => $fields[1],
            'full_name' => $fields[2],
            'is_valid' => $fields[3] === '1',
        ];
    }

    /**
     * @return array{iso_3166_2: ?string, name: string, full_name: ?string, is_valid: bool}
     */
    public static function normalizeIsoCountryRecordPhp(string $isoCode, string $name, ?string $fullName = null): array
    {
        $iso = trim($isoCode, self::WHITESPACE);
        $iso = strlen($iso) === 2 && strspn($iso, self::ASCII_LETTERS) === 2 ? strtoupper($iso) : null;
        $name = self::cleanName($name);
        $fullName = $fullName === null ? null : self::cleanName($fullName);

        return [
            'iso_3166_2' => $iso,
            'name' => $name,
            'full_name' => $fullName === '' ? null : $fullName,
            'is_valid' => $iso !== null && $name !== '',
        ];
    }

    private static function cleanName(string $value): string
    {
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $value) ?? $value;

        return mb_substr(trim($collapsed, self::WHITESPACE), 0, 255, 'UTF-8');
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
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
