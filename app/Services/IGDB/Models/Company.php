<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // ---------------------------------------------------------------------
    // Wave 54.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 5.49x, p99 3.36x; uncommon mean 5.16x, p99 4.32x); see RustXxxPipeIgdbClientCompanyGamePlatformW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w544_igdb_company_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * normalizeCompanyRecordPhp() for many companies at once, as lengths and offsets instead of
     * copies, plus the W48 canonical slug. Each (id, name, country) yields four words: the
     * normalized name as a span (trimmed offset << 32 | collapsed length), the slug length |
     * the canonical slug length (trailing W48_SUFFIXES segments stripped) << 32, the FNV-1a 64
     * hash of the canonical slug and flags (W48_KNOWN_PUBLISHERS index + 1, else 0 | 32 suffix
     * stripped | 64 country 1-999 | 128 positive id | 256 is_valid).
     *
     * @param  list<int>  $ids
     * @param  list<string>  $names
     * @param  list<int>  $countries
     * @return list<int>
     */
    public static function companyRecordRowBatch(array $ids, array $names, array $countries): array
    {
        return self::usesW54RustFfi()
            ? self::companyRecordRowBatchNative($ids, $names, $countries)
            : self::companyRecordRowBatchPhp($ids, $names, $countries);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $names
     * @param  list<int>  $countries
     * @return list<int>
     */
    public static function companyRecordRowBatchNative(array $ids, array $names, array $countries): array
    {
        $count = self::w54AssertColumns($ids, $names, $countries);
        if ($count === 0) {
            return [];
        }

        return self::w54Call(implode("\0", $names), pack('P*', ...$ids, ...$countries), $count, 4)
            ?? self::companyRecordRowBatchPhp($ids, $names, $countries);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $names
     * @param  list<int>  $countries
     * @return list<int>
     */
    public static function companyRecordRowBatchPhp(array $ids, array $names, array $countries): array
    {
        self::w54AssertColumns($ids, $names, $countries);
        $out = [];
        foreach ($names as $i => $name) {
            $collapsed = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($name, self::WHITESPACE)) ?? '';
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($collapsed)) ?? '', '-');
            $segments = explode('-', $slug);
            $stripped = 0;
            while (\count($segments) > 1 && isset(self::W48_SUFFIXES[$segments[\count($segments) - 1]])) {
                array_pop($segments);
                $stripped = 32;
            }
            $canonical = implode('-', $segments);
            $publisher = 0;
            foreach (self::W48_KNOWN_PUBLISHERS as $k => $known) {
                if ($canonical === $known || str_starts_with($canonical, $known.'-')) {
                    $publisher = $k + 1;

                    break;
                }
            }
            $hash = unpack('J', hash('fnv1a64', $canonical, true));
            $id = $ids[$i];
            $country = $countries[$i];
            array_push(
                $out,
                strspn($name, self::WHITESPACE) << 32 | \strlen($collapsed),
                \strlen($slug) | \strlen($canonical) << 32,
                \is_array($hash) ? (int) $hash[1] : 0,
                $publisher | $stripped
                    | ($country >= 1 && $country <= self::COUNTRY_MAX ? 64 : 0)
                    | ($id > 0 ? 128 : 0)
                    | ($id > 0 && $collapsed !== '' ? 256 : 0),
            );
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $first
     * @param  array<array-key, mixed>  $second
     * @param  array<array-key, mixed>  $third
     */
    private static function w54AssertColumns(array $first, array $second, array $third): int
    {
        self::w54AssertList($first);
        self::w54AssertList($second);
        self::w54AssertList($third);
        $count = \count($first);
        if (\count($second) !== $count || \count($third) !== $count) {
            throw new \InvalidArgumentException('W54 batch columns must have the same length.');
        }

        return $count;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function w54AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W54 batch input must be a list.');
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W54 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('enabled');
        self::w54RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns and packed int64 columns in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w54Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w54RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w54', self::W54_FFI_CDEF) ?? false;
                self::w54RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w54RustFfiState('out', $out);
                self::w54RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w544_igdb_company_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * W54 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }

    // ========================================================================
    // WAVE 67.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four 1000-round paired runs reached 4.15x common mean, 3.40x common p99, 3.90x uncommon mean and 3.80x uncommon p99; see RustIgdbCompanyGamePlatformInvitationIsbnDbW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    private const string W67_FFI_CDEF = 'ssize_t nntmux_w674_igdb_company_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w67IgdbCompanyPlanBatch() record. */
    public const int W67_ROW_WORDS = 4;

    /** int64 summary words after the w67IgdbCompanyPlanBatch() records (none for an empty batch). */
    public const int W67_TRAILER_WORDS = 10;

    /** Parent links followed before a chain is reported as too deep. */
    private const int W67_PARENT_DEPTH_MAX = 8;

    /**
     * Company plan rows for [name, slug, ID, parent ID, role bits, country] items.
     *
     * The name collapses WHITESPACE runs to one space; the slug lower-cases it and joins its
     * ASCII alphanumeric runs with '-'; trailing W48_SUFFIXES segments are stripped while another
     * remains (the canonical slug), which is matched against W48_KNOWN_PUBLISHERS (equal or a
     * '-' prefix). Role bits (1 developer, 2 publisher, 4 porting, 8 supporting) give the class:
     * 3 developer and publisher, 1 developer, 2 publisher, 4 porting/supporting only, 0 none.
     * The parent chain follows parent IDs through the first record holding each positive ID:
     * decision 0 no parent, 1 reaches a root, 2 parent not in the batch, 3 cycles back to the
     * record, 4 stops at depth 8; the root is the last record reached (-1 at depth 0).
     *
     * Rows [class | publisher index + 1 << 3 | suffix stripped << 8 | given slug matches << 9 |
     * country 1-999 << 10 | positive ID << 11 | positive ID with a name << 12 | decision << 13 |
     * depth << 16 | name length << 20 | slug length << 36 | canonical length << 52,
     * fnv1a32(canonical), root record index, fnv1a32(name)], then the trailer [records,
     * developers, publishers, both, porting/supporting only, no roles, known publishers, rooted
     * chains, broken chains, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w67IgdbCompanyPlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67IgdbCompanyPlanBatchNative($items) : self::w67IgdbCompanyPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w67IgdbCompanyPlanBatchPhp(array $items): array
    {
        self::w67Items($items);
        if ($items === []) {
            return [];
        }
        $byId = [];
        foreach ($items as $i => $item) {
            if ($item[2] > 0 && ! isset($byId[$item[2]])) {
                $byId[$item[2]] = $i;
            }
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as $i => [$rawName, $givenSlug, $id, $parent, $roles, $country]) {
            $name = preg_replace('/[ \t\n\r\0\x0B\x0C]+/', ' ', trim($rawName, self::WHITESPACE)) ?? '';
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-');
            $segments = explode('-', $slug);
            $total = \count($segments);
            $kept = $total;
            while ($kept > 1 && isset(self::W48_SUFFIXES[$segments[$kept - 1]])) {
                $kept--;
            }
            $stripped = $kept < $total;
            $canonical = $stripped ? implode('-', \array_slice($segments, 0, $kept)) : $slug;
            $publisher = 0;
            foreach (self::W48_KNOWN_PUBLISHERS as $k => $known) {
                if ($canonical === $known || str_starts_with($canonical, $known.'-')) {
                    $publisher = $k + 1;
                    break;
                }
            }
            $given = trim($givenSlug, self::WHITESPACE);
            $slugMatch = $given !== '' && strtolower($given) === $slug;
            $roles &= 15;
            $decision = $depth = 0;
            $cur = $i;
            if ($parent > 0) {
                while (true) {
                    $p = $items[$cur][3];
                    if ($p <= 0) {
                        $decision = 1;
                        break;
                    }
                    $j = $byId[$p] ?? null;
                    if ($j === null) {
                        $decision = 2;
                        break;
                    }
                    if ($j === $i) {
                        $decision = 3;
                        break;
                    }
                    $cur = $j;
                    if (++$depth === self::W67_PARENT_DEPTH_MAX) {
                        $decision = 4;
                        break;
                    }
                }
            }
            $root = $depth > 0 ? $cur : -1;
            $class = ($roles & 3) === 3 ? 3 : (($roles & 1) !== 0 ? 1 : (($roles & 2) !== 0 ? 2 : (($roles & 12) !== 0 ? 4 : 0)));
            $word = $class | $publisher << 3 | (int) $stripped << 8 | (int) $slugMatch << 9 | (int) ($country >= 1 && $country <= self::COUNTRY_MAX) << 10
                | (int) ($id > 0) << 11 | (int) ($id > 0 && $name !== '') << 12 | $decision << 13 | $depth << 16 | min(\strlen($name), 0xFFFF) << 20
                | min(\strlen($slug), 0xFFFF) << 36 | min(\strlen($canonical), 0x3FF) << 52;
            $canonHash = self::w67Fnv($canonical);
            array_push($out, $word, $canonHash, $root, self::w67Fnv($name));
            $t[1] += $roles & 1;
            $t[2] += $roles >> 1 & 1;
            $t[3] += (int) ($class === 3);
            $t[4] += (int) ($class === 4);
            $t[5] += (int) ($roles === 0);
            $t[6] += (int) ($publisher > 0);
            $t[7] += (int) ($decision === 1);
            $t[8] += (int) ($decision >= 2);
            $value = $word ^ $canonHash << 8 ^ $root;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[9] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w67IgdbCompanyPlanBatchNative(array $items): array
    {
        self::w67Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w67Join([array_column($items, 0), array_column($items, 1)]);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5));

        return self::w67Call($blob, $twin, $ints, $count, $count * self::W67_ROW_WORDS + self::W67_TRAILER_WORDS)
            ?? self::w67IgdbCompanyPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w67Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W67 IGDB company plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 6 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2])
                || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5])) {
                throw new \InvalidArgumentException('W67 IGDB company plan batch items must be [string name, string slug, int ID, int parent ID, int role bits, int country] lists.');
            }
        }
    }

    public static function usesW67RustFfi(): bool
    {
        $enabled = self::w67RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W67_RUST_FFI_DEFAULT;
    }

    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('enabled', true);
    }

    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('enabled', false);
    }

    /** Drop the W67 override, binding, output buffer and declined-call count; routing returns to W67_RUST_FFI_DEFAULT. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    /**
     * Run $callback with the W67 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('enabled');
        self::w67RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w67Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w67Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w67RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w67', self::W67_FFI_CDEF) ?? false;
            self::w67RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w67RustFfiState('out', $out);
                self::w67RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W67_FFI_CDEF.)
            $written = $ffi->nntmux_w674_igdb_company_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w67RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W67 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w67Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
