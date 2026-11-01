<?php

declare(strict_types=1);

namespace App\Services\IGDB\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** Disabled: seven paired runs missed the 1.5x common p99 gate in five (min common mean 1.60x, p99 1.23x; uncommon mean 2.95x, p99 2.35x); shared PHP flattening dominates these short rows (see the W48 parity test). */
    public const bool W48_PLATFORM_FAMILY_RUST_FFI_DEFAULT = false;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_igdb_platform_family_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W48_PLATFORM_FAMILY_KEYS = ['total_platforms', 'sony_count', 'microsoft_count', 'nintendo_count', 'sega_count', 'pc_count', 'mobile_count', 'unmatched_count', 'handheld_count', 'generation_match_count', 'generation_mismatch_count', 'generation_sum'];

    /** Family id => summary key. */
    private const array W48_FAMILY_KEYS = [1 => 'sony_count', 2 => 'microsoft_count', 3 => 'nintendo_count', 4 => 'sega_count', 5 => 'pc_count', 6 => 'mobile_count'];

    /** Lower case [a-z0-9] alias key => [family, console generation, handheld]. */
    private const array W48_ALIASES = [
        'ps1' => [1, 5, false], 'psx' => [1, 5, false], 'playstation' => [1, 5, false],
        'ps2' => [1, 6, false], 'playstation2' => [1, 6, false],
        'ps3' => [1, 7, false], 'playstation3' => [1, 7, false],
        'ps4' => [1, 8, false], 'playstation4' => [1, 8, false],
        'ps5' => [1, 9, false], 'playstation5' => [1, 9, false],
        'psp' => [1, 7, true], 'playstationportable' => [1, 7, true],
        'vita' => [1, 8, true], 'psvita' => [1, 8, true], 'playstationvita' => [1, 8, true],
        'xbox' => [2, 6, false],
        'x360' => [2, 7, false], 'xbox360' => [2, 7, false],
        'xone' => [2, 8, false], 'xboxone' => [2, 8, false], 'xb1' => [2, 8, false],
        'xsx' => [2, 9, false], 'xboxseriesx' => [2, 9, false], 'xboxseriesxs' => [2, 9, false],
        'nes' => [3, 3, false], 'famicom' => [3, 3, false], 'nintendoentertainmentsystem' => [3, 3, false],
        'snes' => [3, 4, false], 'supernintendo' => [3, 4, false], 'supernintendoentertainmentsystem' => [3, 4, false], 'superfamicom' => [3, 4, false],
        'n64' => [3, 5, false], 'nintendo64' => [3, 5, false],
        'gc' => [3, 6, false], 'ngc' => [3, 6, false], 'gamecube' => [3, 6, false], 'nintendogamecube' => [3, 6, false],
        'wii' => [3, 7, false],
        'wiiu' => [3, 8, false],
        'switch' => [3, 8, true], 'nintendoswitch' => [3, 8, true], 'ns' => [3, 8, true],
        'gb' => [3, 4, true], 'gameboy' => [3, 4, true],
        'gba' => [3, 6, true], 'gameboyadvance' => [3, 6, true],
        'ds' => [3, 7, true], 'nds' => [3, 7, true], 'nintendods' => [3, 7, true],
        '3ds' => [3, 8, true], 'nintendo3ds' => [3, 8, true],
        'sms' => [4, 3, false], 'mastersystem' => [4, 3, false], 'segamastersystem' => [4, 3, false],
        'md' => [4, 4, false], 'genesis' => [4, 4, false], 'megadrive' => [4, 4, false], 'segagenesis' => [4, 4, false], 'segamegadrive' => [4, 4, false],
        'saturn' => [4, 5, false], 'segasaturn' => [4, 5, false],
        'dc' => [4, 6, false], 'dreamcast' => [4, 6, false], 'segadreamcast' => [4, 6, false],
        'gg' => [4, 4, true], 'gamegear' => [4, 4, true], 'segagamegear' => [4, 4, true],
        'pc' => [5, 0, false], 'win' => [5, 0, false], 'windows' => [5, 0, false], 'pcmicrosoftwindows' => [5, 0, false], 'mac' => [5, 0, false], 'macos' => [5, 0, false], 'linux' => [5, 0, false], 'dos' => [5, 0, false],
        'ios' => [6, 0, true], 'android' => [6, 0, true], 'iphone' => [6, 0, true], 'ipad' => [6, 0, true],
    ];

    /** Alias lookup order: abbreviation, name, alternative name. */
    private const array W48_ALIAS_FIELDS = [1, 0, 2];

    /** IGDB platform category for portable consoles. */
    private const int W48_PORTABLE_CATEGORY = 5;

    private static ?bool $w48UseRustFfi = null;

    private static FFI|false|null $w48Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW48PlatformFamilyRustFfi(?bool $enable = true): void
    {
        self::$w48UseRustFfi = $enable;
    }

    public static function usesW48PlatformFamilyRustFfi(): bool
    {
        return self::$w48UseRustFfi ?? self::W48_PLATFORM_FAMILY_RUST_FFI_DEFAULT;
    }

    public static function resetW48PlatformFamilyRustFfi(): void
    {
        self::$w48UseRustFfi = null;
        self::$w48Ffi = null;
    }

    /**
     * Map IGDB platforms to families (Sony, Microsoft, Nintendo, Sega, PC,
     * mobile) by matching the abbreviation, then the name, then the
     * alternative name, lower-cased and reduced to [a-z0-9], against an alias
     * table that also carries the console generation and handheld flag;
     * counts handhelds (alias or portable category 5) and compares supplied
     * generations with the derived ones. Rows are ['name', 'abbreviation',
     * 'alternative_name', 'category', 'generation'] maps or bare names.
     *
     * @param  array<array-key, mixed>  $platforms
     * @return array<string, int>
     */
    public static function mapPlatformFamilyBatch(array $platforms): array
    {
        return self::usesW48PlatformFamilyRustFfi()
            ? self::mapPlatformFamilyBatchNative($platforms)
            : self::mapPlatformFamilyBatchPhp($platforms);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $platforms
     * @return array<string, int>
     */
    public static function mapPlatformFamilyBatchNative(array $platforms): array
    {
        $fields = self::w48Fields($platforms);

        return self::w48Call($fields) ?? self::w48Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $platforms
     * @return array<string, int>
     */
    public static function mapPlatformFamilyBatchPhp(array $platforms): array
    {
        return self::w48Summary(self::w48Fields($platforms));
    }

    /**
     * [name, abbreviation, alternative name, category, generation].
     *
     * @param  array<array-key, mixed>  $platforms
     * @return list<string>
     */
    private static function w48Fields(array $platforms): array
    {
        $fields = [];
        foreach ($platforms as $row) {
            if (! is_array($row)) {
                array_push($fields, is_string($row) ? $row : (is_int($row) ? (string) $row : ''), '', '', '', '');

                continue;
            }
            foreach (['name', 'abbreviation', 'alternative_name', 'category', 'generation'] as $key) {
                $value = $row[$key] ?? null;
                $fields[] = is_string($value) ? $value : (is_int($value) ? (string) $value : '');
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
        $summary = array_fill_keys(self::W48_PLATFORM_FAMILY_KEYS, 0);
        for ($i = 0, $n = count($fields); $i < $n; $i += 5) {
            $summary['total_platforms']++;
            $alias = null;
            foreach (self::W48_ALIAS_FIELDS as $offset) {
                $key = preg_replace('/[^a-z0-9]+/', '', strtolower($fields[$i + $offset])) ?? '';
                if (isset(self::W48_ALIASES[$key])) {
                    $alias = self::W48_ALIASES[$key];
                    break;
                }
            }
            if ($alias === null) {
                $summary['unmatched_count']++;
            } else {
                $summary[self::W48_FAMILY_KEYS[$alias[0]]]++;
            }
            $text = $fields[$i + 3];
            $category = $text !== '' && strlen($text) <= 2 && ctype_digit($text) ? (int) $text : 0;
            $summary['handheld_count'] += (int) ($category === self::W48_PORTABLE_CATEGORY || ($alias !== null && $alias[2]));
            $text = $fields[$i + 4];
            $generation = $text !== '' && strlen($text) <= 2 && ctype_digit($text) ? (int) $text : 0;
            if ($alias !== null) {
                if ($alias[1] > 0 && $generation > 0) {
                    $summary['generation_match_count'] += (int) ($alias[1] === $generation);
                    $summary['generation_mismatch_count'] += (int) ($alias[1] !== $generation);
                }
                $summary['generation_sum'] += $alias[1];
            }
        }

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
            if ($out === null || $ffi->nntmux_w48_igdb_platform_family_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 5) {
                return null;
            }

            return array_combine(self::W48_PLATFORM_FAMILY_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
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

    // ---------------------------------------------------------------------
    // Wave 54.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 4.80x, p99 2.75x; uncommon mean 4.78x, p99 3.52x); see RustXxxPipeIgdbClientCompanyGamePlatformW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w544_igdb_platform_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * normalizePlatformRecordPhp() for many platforms at once, as lengths and offsets instead of
     * copies, plus the W48 family alias. Each (id, name, abbreviation) yields four words: the
     * normalized name as a span (trimmed offset << 32 | collapsed length), the slug length, the
     * normalized abbreviation as a span (-1 when null or blank) and the W48_ALIASES match of
     * the abbreviation, else the name (family | generation << 4 | 256 handheld | source << 9
     * with source 1 abbreviation, 2 name, 0 none | 2048 positive id | 4096 is_valid).
     *
     * @param  list<int>  $ids
     * @param  list<string>  $names
     * @param  list<?string>  $abbreviations
     * @return list<int>
     */
    public static function platformRecordRowBatch(array $ids, array $names, array $abbreviations): array
    {
        return self::usesW54RustFfi()
            ? self::platformRecordRowBatchNative($ids, $names, $abbreviations)
            : self::platformRecordRowBatchPhp($ids, $names, $abbreviations);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $names
     * @param  list<?string>  $abbreviations
     * @return list<int>
     */
    public static function platformRecordRowBatchNative(array $ids, array $names, array $abbreviations): array
    {
        $count = self::w54AssertColumns($ids, $names, $abbreviations);
        if ($count === 0) {
            return [];
        }

        // Names then abbreviations; implode() writes a null abbreviation as '', which normalizes alike.
        return self::w54Call(implode("\0", $names)."\0".implode("\0", $abbreviations), pack('P*', ...$ids), $count, 4)
            ?? self::platformRecordRowBatchPhp($ids, $names, $abbreviations);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $names
     * @param  list<?string>  $abbreviations
     * @return list<int>
     */
    public static function platformRecordRowBatchPhp(array $ids, array $names, array $abbreviations): array
    {
        self::w54AssertColumns($ids, $names, $abbreviations);
        $out = [];
        foreach ($names as $i => $name) {
            $collapsed = self::collapseWhitespace($name);
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($collapsed)) ?? '', '-');
            $abbreviation = $abbreviations[$i] ?? '';
            $normalized = self::collapseWhitespace($abbreviation);
            $source = 1 << 9;
            $alias = self::W48_ALIASES[preg_replace('/[^a-z0-9]+/', '', strtolower($abbreviation)) ?? ''] ?? null;
            if ($alias === null) {
                $source = 2 << 9;
                $alias = self::W48_ALIASES[preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? ''] ?? null;
            }
            $id = $ids[$i];
            array_push(
                $out,
                strspn($name, self::WHITESPACE) << 32 | \strlen($collapsed),
                \strlen($slug),
                $normalized === '' ? -1 : strspn($abbreviation, self::WHITESPACE) << 32 | \strlen($normalized),
                ($alias === null ? 0 : $alias[0] | $alias[1] << 4 | ($alias[2] ? 256 : 0) | $source)
                    | ($id > 0 ? 2048 : 0)
                    | ($id > 0 && $collapsed !== '' ? 4096 : 0),
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
            $written = $ffi->nntmux_w544_igdb_platform_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
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
}
