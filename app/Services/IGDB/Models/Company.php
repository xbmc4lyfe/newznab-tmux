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

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.96x, p99 2.24x; uncommon mean 3.16x, p99 2.76x; see the W48 parity test). */
    public const bool W48_COMPANY_ROLE_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_igdb_company_role_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W48_COMPANY_ROLE_KEYS = ['total_companies', 'empty_name_count', 'developer_count', 'publisher_count', 'dual_role_count', 'auxiliary_only_count', 'no_role_count', 'slug_match_count', 'suffix_stripped_count', 'known_publisher_count', 'valid_country_count', 'canonical_digest'];

    /** IGDB involved_companies role flags, as bits. */
    private const array W48_ROLE_BITS = ['developer' => 1, 'publisher' => 2, 'porting' => 4, 'supporting' => 8];

    /** Trailing slug segments dropped while another segment remains. */
    private const array W48_SUFFIXES = ['ab' => true, 'ag' => true, 'bv' => true, 'co' => true, 'corp' => true, 'corporation' => true, 'gmbh' => true, 'inc' => true, 'kk' => true, 'limited' => true, 'llc' => true, 'ltd' => true, 'oy' => true, 'plc' => true, 'sa' => true, 'srl' => true];

    private const array W48_KNOWN_PUBLISHERS = ['activision', 'bandai-namco', 'bethesda', 'capcom', 'devolver', 'electronic-arts', 'konami', 'microsoft', 'nintendo', 'sega', 'sony', 'square-enix', 'take-two', 'thq', 'ubisoft', 'warner-bros'];

    private static ?bool $w48UseRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW48CompanyRoleRustFfi(?bool $enable = true): void
    {
        self::$w48UseRustFfi = $enable;
    }

    public static function usesW48CompanyRoleRustFfi(): bool
    {
        return self::$w48UseRustFfi ?? self::W48_COMPANY_ROLE_RUST_FFI_DEFAULT;
    }

    public static function resetW48CompanyRoleRustFfi(): void
    {
        self::$w48UseRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Classify IGDB companies: names normalized as normalizeCompanyRecordPhp()
     * does, slugs compared with the supplied slug, trailing corporate suffix
     * segments (inc, ltd, gmbh, ...) stripped into a canonical slug that is
     * matched against well-known publishers and folded into an FNV-1a digest,
     * developer/publisher/porting/supporting role mixes and ISO 3166-1 numeric
     * countries. Rows are ['name', 'slug', 'developer', 'publisher',
     * 'porting', 'supporting', 'country'] maps or bare names.
     *
     * @param  array<array-key, mixed>  $companies
     * @return array<string, int>
     */
    public static function classifyCompanyRoleBatch(array $companies): array
    {
        return self::usesW48CompanyRoleRustFfi()
            ? self::classifyCompanyRoleBatchNative($companies)
            : self::classifyCompanyRoleBatchPhp($companies);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $companies
     * @return array<string, int>
     */
    public static function classifyCompanyRoleBatchNative(array $companies): array
    {
        $fields = self::w48Fields($companies);

        return self::w48Call($fields) ?? self::w48Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $companies
     * @return array<string, int>
     */
    public static function classifyCompanyRoleBatchPhp(array $companies): array
    {
        return self::w48Summary(self::w48Fields($companies));
    }

    /**
     * [name, slug, role bits, country].
     *
     * @param  array<array-key, mixed>  $companies
     * @return list<string>
     */
    private static function w48Fields(array $companies): array
    {
        $fields = [];
        foreach ($companies as $row) {
            if (! is_array($row)) {
                array_push($fields, is_string($row) ? $row : (is_int($row) ? (string) $row : ''), '', '', '');

                continue;
            }
            $roles = 0;
            foreach (self::W48_ROLE_BITS as $flag => $bit) {
                if (($row[$flag] ?? null) === true) {
                    $roles |= $bit;
                }
            }
            $name = $row['name'] ?? null;
            $slug = $row['slug'] ?? null;
            $country = $row['country'] ?? null;
            array_push(
                $fields,
                is_string($name) ? $name : (is_int($name) ? (string) $name : ''),
                is_string($slug) ? $slug : (is_int($slug) ? (string) $slug : ''),
                (string) $roles,
                is_string($country) ? $country : (is_int($country) ? (string) $country : ''),
            );
        }

        return $fields;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private static function w48Summary(array $fields): array
    {
        $summary = array_fill_keys(self::W48_COMPANY_ROLE_KEYS, 0);
        $digest = hash_init('fnv1a64');
        for ($i = 0, $n = count($fields); $i < $n; $i += 4) {
            $summary['total_companies']++;
            $name = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($fields[$i], self::WHITESPACE)) ?? '';
            if ($name === '') {
                $summary['empty_name_count']++;
            } else {
                $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
                $given = strtolower(trim($fields[$i + 1], self::WHITESPACE));
                $summary['slug_match_count'] += (int) ($given !== '' && $given === $slug);
                $segments = explode('-', $slug);
                $stripped = false;
                while (count($segments) > 1 && isset(self::W48_SUFFIXES[$segments[count($segments) - 1]])) {
                    array_pop($segments);
                    $stripped = true;
                }
                $summary['suffix_stripped_count'] += (int) $stripped;
                $canonical = implode('-', $segments);
                foreach (self::W48_KNOWN_PUBLISHERS as $publisher) {
                    if ($canonical === $publisher || str_starts_with($canonical, $publisher.'-')) {
                        $summary['known_publisher_count']++;
                        break;
                    }
                }
                if ($canonical !== '') {
                    hash_update($digest, $canonical."\n");
                }
            }
            $text = $fields[$i + 2];
            $roles = $text !== '' && strlen($text) <= 2 && ctype_digit($text) ? (int) $text & 15 : 0;
            $summary['developer_count'] += $roles & 1;
            $summary['publisher_count'] += ($roles >> 1) & 1;
            $summary['dual_role_count'] += (int) (($roles & 3) === 3);
            $summary['auxiliary_only_count'] += (int) (($roles & 3) === 0 && ($roles & 12) !== 0);
            $summary['no_role_count'] += (int) ($roles === 0);
            $country = $fields[$i + 3];
            $summary['valid_country_count'] += (int) ($country !== '' && strlen($country) <= 3 && ctype_digit($country) && (int) $country >= 1);
        }
        $value = unpack('J', hash_final($digest, true));
        $summary['canonical_digest'] = is_array($value) ? (int) $value[1] : 0;

        return $summary;
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
            if ($out === null || $ffi->nntmux_w48_igdb_company_role_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 4) {
                return null;
            }

            return array_combine(self::W48_COMPANY_ROLE_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
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
