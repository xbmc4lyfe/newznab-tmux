<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Patterns;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * Regex patterns for Game release name matching.
 *
 * Supports modern platforms and scene groups.
 */
final class GamePatterns
{
    // ========================================================================
    // MODERN CONSOLE PATTERNS
    // ========================================================================

    /**
     * Modern console releases (PS5, Xbox Series, Switch).
     * Example: Game.Name.NSW-GROUP or Game.Name.PS5-GROUP
     */
    public const MODERN_CONSOLE = '/\w[\-\w.\',;& ]+(NSW|PS[345P]|PSV|XBSX|XSX|XBOX[._ -]?SERIES[._ -]?[XS]|XBOX[._ -]?ONE|XBOX360?|WiiU?|Switch)[._ -](INTERNAL|PROPER|READNFO|READ[._ -]?NFO|MULTI\d{1,2})?[._ -]?[\-\w.\',;& ]+\-[A-Za-z0-9]+$/i';

    /**
     * Region-based game releases.
     * Example: Game.Name.EUR.PS5-GROUP
     */
    public const REGION_CONSOLE = '/\w[\-\w.\',;& ]+(ASIA|DLC|EUR|GOTY|JPN|KOR|MULTI\d{1}|NTSCU?|PAL|RF|Region[._ -]?Free|USA|XBLA)[._ -](DLC[._ -]Complete|FRENCH|GERMAN|MULTI\d{1}|PROPER|PSN|READ[._ -]?NFO|UMD)?[._ -]?(GC|NDS|NGC|PS[345P]|PSP|PSV|Switch|NSW|Wii(U)?|XBOX(360|ONE|SERIES)?|XBSX)[\-\w.\',;& ]+\w/i';

    /**
     * Console with scene group.
     * Example: Game.Name.PS5-CODEX
     */
    public const CONSOLE_SCENE_GROUP = '/\w[\-\w.\',;& ]+(GC|NDS|NGC|PS[345P]|Switch|NSW|Wii(U)?|XBOX(360|ONE|SERIES)?|XBSX)[._ -](CODEX|DUPLEX|PLAZA|SKIDROW|RELOADED|CPY|EMPRESS|RAZOR1911|HOODLUM|DARKSiDERS|FLT|TiNYiSO|ANOMALY|iNSOMNi|OneUp|STRANGE|SWAG|SKY|SUXXORS)[\-\w.\',;& ]+\w/i';

    // ========================================================================
    // PC GAME PATTERNS
    // ========================================================================

    /**
     * PC Games with scene groups.
     * Example: Game.Name-CODEX
     */
    public const PC_SCENE_GROUP = '/\w[\-\w.\',;& ]+(PC|WIN(32|64)?|MAC(OSX?)?|LINUX)[._ -]?(CODEX|SKIDROW|RELOADED|CPY|EMPRESS|RAZOR1911|HOODLUM|DARKSiDERS|FLT|GOG|PROPHET|TiNYiSO|PLAZA|P2P|SiMPLEX|rG)[\-\w.\',;& ]+\w/i';

    /**
     * DLC and Update releases.
     * Example: Game.Name.DLC-CODEX or Game.Name.Update.v1.2-PLAZA
     */
    public const DLC_UPDATE = '/\w[\-\w.\',;& ]+(DLC|Update|Patch|Hotfix)[._ -](v?\d+[\.\d]*)?[._ -]?(CODEX|SKIDROW|RELOADED|PLAZA|EMPRESS|FLT|GOG|P2P|TiNYiSO)[\-\w.\',;& ]+\w/i';

    /**
     * OUTLAWS group releases.
     * Example: Game.Name-OUTLAWS
     */
    public const OUTLAWS = '/\w[\w.\',;-].+-OUTLAWS/i';

    /**
     * ALiAS group releases.
     * Example: Game.Name-ALiAS
     */
    public const ALIAS = '/\w[\w.\',;-].+\-ALiAS/i';

    /**
     * GOG releases (DRM-free).
     * Example: Game.Name.GOG.Classic-GROUP
     */
    public const GOG = '/\w[\-\w.\',;& ]+[._ -]GOG[._ -]?(Classic|Galaxy)?[\-\w.\',;& ]+\w/i';

    /**
     * REPACK releases.
     * Example: Game.Name.REPACK-FitGirl
     */
    public const REPACK = '/\w[\-\w.\',;& ]+[._ -](REPACK|RIP)[._ -](FitGirl|DODI|xatab|R\.G\.|Mechanics)[\-\w.\',;& ]+\w/i';

    // ========================================================================
    // SCENE GROUP LIST
    // ========================================================================

    /**
     * Known PC game scene groups.
     */
    public const SCENE_GROUPS = [
        'CODEX',
        'SKIDROW',
        'RELOADED',
        'CPY',
        'EMPRESS',
        'RAZOR1911',
        'HOODLUM',
        'DARKSiDERS',
        'FLT',
        'GOG',
        'PROPHET',
        'TiNYiSO',
        'PLAZA',
        'P2P',
        'SiMPLEX',
        'rG',
        'ANOMALY',
        'iNSOMNi',
        'OneUp',
        'STRANGE',
        'SWAG',
        'SKY',
        'SUXXORS',
        'DOGE',
        'TENOKE',
        'RUNE',
    ];

    /**
     * Modern platforms.
     */
    public const PLATFORMS = [
        'NSW' => 'Nintendo Switch',
        'PS5' => 'PlayStation 5',
        'PS4' => 'PlayStation 4',
        'PS3' => 'PlayStation 3',
        'PSP' => 'PlayStation Portable',
        'PSV' => 'PlayStation Vita',
        'XBSX' => 'Xbox Series X/S',
        'XSX' => 'Xbox Series X',
        'XBOX ONE' => 'Xbox One',
        'XBOX360' => 'Xbox 360',
        'Switch' => 'Nintendo Switch',
        'WiiU' => 'Wii U',
        'Wii' => 'Wii',
        'NDS' => 'Nintendo DS',
        '3DS' => 'Nintendo 3DS',
        'PC' => 'PC',
        'MAC' => 'macOS',
        'LINUX' => 'Linux',
    ];

    // ========================================================================
    // PATTERN COLLECTIONS
    // ========================================================================

    /** @var array<string, string> Priority order is part of the checker contract. */
    public const array ALL_PATTERNS = [
        'MODERN_CONSOLE' => self::MODERN_CONSOLE,
        'REGION_CONSOLE' => self::REGION_CONSOLE,
        'CONSOLE_SCENE_GROUP' => self::CONSOLE_SCENE_GROUP,
        'PC_SCENE_GROUP' => self::PC_SCENE_GROUP,
        'DLC_UPDATE' => self::DLC_UPDATE,
        'OUTLAWS' => self::OUTLAWS,
        'ALIAS' => self::ALIAS,
        'GOG' => self::GOG,
        'REPACK' => self::REPACK,
    ];

    public const string FFI_CDEF = 'ssize_t nntmux_name_fixing_patterns(uint32_t kind, uint32_t mode, const char *key, size_t key_len, size_t index, char *out, size_t cap);';

    // Single-call FFI must pass all four 1.5x benchmark gates before adoption.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** @return array<string, string> */
    public static function getAllPatterns(): array
    {
        return (self::$useRustFfi ? self::getAllPatternsNative() : null) ?? self::getAllPatternsPhp();
    }

    /** @return array<string, string> */
    public static function getAllPatternsPhp(): array
    {
        return self::ALL_PATTERNS;
    }

    /** @return array<string, string>|null */
    public static function getAllPatternsNative(): ?array
    {
        $fields = self::nativeFields(0);
        if ($fields === null || count($fields) % 2 !== 0) {
            return null;
        }
        $patterns = [];
        for ($i = 0; $i < count($fields); $i += 2) {
            $patterns[$fields[$i]] = $fields[$i + 1];
        }

        return $patterns;
    }

    public static function getPattern(string $name): ?string
    {
        return (self::$useRustFfi ? self::getPatternNative($name) : null) ?? self::getPatternPhp($name);
    }

    public static function getPatternPhp(string $name): ?string
    {
        return self::ALL_PATTERNS[$name] ?? null;
    }

    public static function getPatternNative(string $name): ?string
    {
        $value = self::patternsNative(3, $name);

        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    public static function getPriorityOrder(): array
    {
        return (self::$useRustFfi ? self::getPriorityOrderNative() : null) ?? self::getPriorityOrderPhp();
    }

    /** @return list<string> */
    public static function getPriorityOrderPhp(): array
    {
        return array_keys(self::ALL_PATTERNS);
    }

    /** @return list<string>|null */
    public static function getPriorityOrderNative(): ?array
    {
        return self::nativeFields(1);
    }

    public static function getPatternCount(): int
    {
        return (self::$useRustFfi ? self::getPatternCountNative() : null) ?? self::getPatternCountPhp();
    }

    public static function getPatternCountPhp(): int
    {
        return count(self::ALL_PATTERNS);
    }

    public static function getPatternCountNative(): ?int
    {
        $value = self::patternsNative(2);

        return is_int($value) ? $value : null;
    }

    public static function getPatternKey(int $index): ?string
    {
        return (self::$useRustFfi ? self::getPatternKeyNative($index) : null) ?? self::getPatternKeyPhp($index);
    }

    public static function getPatternKeyPhp(int $index): ?string
    {
        return self::getPriorityOrderPhp()[$index] ?? null;
    }

    public static function getPatternKeyNative(int $index): ?string
    {
        if ($index < 0) {
            return null;
        }
        $value = self::patternsNative(4, index: $index);

        return is_string($value) ? $value : null;
    }

    private static function patternsNative(int $mode, string $key = '', int $index = 0): string|int|null
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 32768, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_name_fixing_patterns(1, $mode, $key, strlen($key), $index, $buffer, $capacity);
            if (! is_int($written) || $written < 0) {
                return null;
            }
            if ($mode === 2) {
                return $written;
            }

            return $written <= $capacity ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null */
    private static function nativeFields(int $mode): ?array
    {
        $raw = self::patternsNative($mode);
        if (! is_string($raw)) {
            return null;
        }
        $fields = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            if ($length - $offset < 4) {
                return null;
            }
            $size = unpack('Vsize', substr($raw, $offset, 4))['size'];
            $offset += 4;
            if ($size > $length - $offset) {
                return null;
            }
            $fields[] = substr($raw, $offset, $size);
            $offset += $size;
        }

        return $fields;
    }

    /**
     * Get console patterns only.
     *
     * @return array<string, mixed>
     */
    public static function getConsolePatterns(): array
    {
        return [
            'MODERN_CONSOLE' => self::MODERN_CONSOLE,
            'REGION_CONSOLE' => self::REGION_CONSOLE,
            'CONSOLE_SCENE_GROUP' => self::CONSOLE_SCENE_GROUP,
        ];
    }

    /**
     * Get PC game patterns only.
     *
     * @return array<string, mixed>
     */
    public static function getPCPatterns(): array
    {
        return [
            'PC_SCENE_GROUP' => self::PC_SCENE_GROUP,
            'DLC_UPDATE' => self::DLC_UPDATE,
            'OUTLAWS' => self::OUTLAWS,
            'ALIAS' => self::ALIAS,
            'GOG' => self::GOG,
            'REPACK' => self::REPACK,
        ];
    }

    // ========================================================================
    // WAVE 57.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 9.63x, p99 8.96x; uncommon mean 3.89x, p99 3.53x); see RustNameFixingPatternsAppGameMovieTvNameFixResultW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_game_pattern_row_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per gamePatternRowBatch() record. */
    public const int W57_ROW_WORDS = 7;

    /** Console regions reported by gamePatternRowBatch(), lowercase. */
    private const array W57_REGIONS = ['asia', 'eur', 'jpn', 'kor', 'ntsc', 'ntscu', 'pal', 'usa', 'rf', 'xbla'];

    private const array W57_CONSOLES = ['gc', 'nds', 'ps3', 'ps4', 'ps5', 'psp', 'switch', 'nsw', 'wii', 'xbox', 'xbsx'];

    /**
     * Lowercase needle groups per ALL_PATTERNS entry, in priority order. A pattern can match
     * only when every group has a needle somewhere in the lowercased name.
     */
    private const array W57_PRESCREEN = [
        [['nsw', 'ps3', 'ps4', 'ps5', 'psp', 'psv', 'xbsx', 'xsx', 'xbox', 'wii', 'switch'], ['-']],
        [
            ['asia', 'dlc', 'eur', 'goty', 'jpn', 'kor', 'multi', 'ntsc', 'pal', 'rf', 'region', 'usa', 'xbla'],
            ['gc', 'nds', 'ps3', 'ps4', 'ps5', 'psp', 'psv', 'switch', 'nsw', 'wii', 'xbox', 'xbsx'],
        ],
        [
            self::W57_CONSOLES,
            ['codex', 'duplex', 'plaza', 'skidrow', 'reloaded', 'cpy', 'empress', 'razor1911', 'hoodlum', 'darksiders',
                'flt', 'tinyiso', 'anomaly', 'insomni', 'oneup', 'strange', 'swag', 'sky', 'suxxors'],
        ],
        [
            ['pc', 'win', 'mac', 'linux'],
            ['codex', 'skidrow', 'reloaded', 'cpy', 'empress', 'razor1911', 'hoodlum', 'darksiders', 'flt', 'gog',
                'prophet', 'tinyiso', 'plaza', 'p2p', 'simplex', 'rg'],
        ],
        [['dlc', 'update', 'patch', 'hotfix'], ['codex', 'skidrow', 'reloaded', 'plaza', 'empress', 'flt', 'gog', 'p2p', 'tinyiso']],
        [['-outlaws']],
        [['-alias']],
        [['gog']],
        [['repack', 'rip'], ['fitgirl', 'dodi', 'xatab', 'r.g.', 'mechanics']],
    ];

    /**
     * Game rows for candidate release names. Each record yields seven words: the pre-screen
     * mask (bit i: ALL_PATTERNS entry i can match), the PLATFORMS-key, region and SCENE_GROUPS
     * masks of delimited tokens (ASCII case-insensitive, neighbours not ASCII alphanumeric), the
     * SCENE_GROUPS index of the text after the last '-' (or -1), the first candidate pattern
     * index (or -1) and the score 8 * candidates + platforms + regions + groups + 4 with a
     * trailing group.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function gamePatternRowBatch(array $names): array
    {
        return self::usesW57RustFfi() ? self::gamePatternRowBatchNative($names) : self::gamePatternRowBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function gamePatternRowBatchPhp(array $names): array
    {
        self::w57Names($names);
        [$platforms, $groups, $groupIndex] = self::w57Tokens();
        $out = [];
        foreach ($names as $name) {
            $lower = strtolower($name);
            $runs = array_flip(preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $pre = self::w57Prescreen($lower);
            $platformMask = self::w57TokenMask($lower, $runs, $platforms);
            $regionMask = self::w57TokenMask($lower, $runs, self::W57_REGIONS);
            $groupMask = self::w57TokenMask($lower, $runs, $groups);
            $dash = strrpos($lower, '-');
            $trailing = $dash === false ? -1 : ($groupIndex[substr($lower, $dash + 1)] ?? -1);
            array_push(
                $out,
                $pre,
                $platformMask,
                $regionMask,
                $groupMask,
                $trailing,
                $pre === 0 ? -1 : self::w57LowestBit($pre),
                8 * self::w57Bits($pre) + self::w57Bits($platformMask) + self::w57Bits($regionMask)
                    + self::w57Bits($groupMask) + ($trailing >= 0 ? 4 : 0),
            );
        }

        return $out;
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function gamePatternRowBatchNative(array $names): array
    {
        self::w57Names($names);
        $count = \count($names);
        if ($count === 0) {
            return [];
        }
        $lens = [];
        foreach ($names as $name) {
            $lens[] = \strlen($name);
        }

        return self::w57Call(implode('', $names), pack('P*', ...$lens), $count, $count * self::W57_ROW_WORDS)
            ?? self::gamePatternRowBatchPhp($names);
    }

    /**
     * Lowercased PLATFORMS keys and SCENE_GROUPS, plus the group lookup by lowercase name.
     *
     * @return array{0: list<string>, 1: list<string>, 2: array<string, int>}
     */
    private static function w57Tokens(): array
    {
        static $tokens = null;
        if ($tokens === null) {
            $groups = array_map(strtolower(...), self::SCENE_GROUPS);
            $tokens = [array_map(strtolower(...), array_keys(self::PLATFORMS)), $groups, array_flip($groups)];
        }

        return $tokens;
    }

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W57 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('enabled');
        self::w57RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $names */
    private static function w57Names(array $names): void
    {
        if (! array_is_list($names)) {
            throw new \InvalidArgumentException('W57 batch names must be a list.');
        }
    }

    private static function w57Prescreen(string $lower): int
    {
        $mask = 0;
        foreach (self::W57_PRESCREEN as $bit => $groups) {
            foreach ($groups as $group) {
                foreach ($group as $needle) {
                    if (str_contains($lower, $needle)) {
                        continue 2;
                    }
                }

                continue 2;
            }
            $mask |= 1 << $bit;
        }

        return $mask;
    }

    /**
     * Bit j for each delimited occurrence of $tokens[j]: alphanumeric tokens must equal a run,
     * others need non-alphanumeric neighbours.
     *
     * @param  array<array-key, int>  $runs
     * @param  list<string>  $tokens
     */
    private static function w57TokenMask(string $lower, array $runs, array $tokens): int
    {
        $mask = 0;
        foreach ($tokens as $bit => $token) {
            if (isset($runs[$token]) || (! ctype_alnum($token) && self::w57Delimited($lower, $token))) {
                $mask |= 1 << $bit;
            }
        }

        return $mask;
    }

    private static function w57Delimited(string $lower, string $token): bool
    {
        $length = \strlen($token);
        $offset = 0;
        while (($pos = strpos($lower, $token, $offset)) !== false) {
            $before = $pos === 0 ? '' : $lower[$pos - 1];
            $after = $lower[$pos + $length] ?? '';
            if (strspn($before, 'abcdefghijklmnopqrstuvwxyz0123456789') === 0
                && strspn($after, 'abcdefghijklmnopqrstuvwxyz0123456789') === 0) {
                return true;
            }
            $offset = $pos + 1;
        }

        return false;
    }

    private static function w57Bits(int $mask): int
    {
        return substr_count(decbin($mask), '1');
    }

    private static function w57LowestBit(int $mask): int
    {
        return \strlen(decbin($mask & -$mask)) - 1;
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w57Call(string $blob, string $ints, int $count, int $need): ?array
    {
        try {
            /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
            $state = self::w57RustFfiState();
            $ffi = $state['ffi'];
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w57', self::W57_FFI_CDEF) ?? false;
                self::w57RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w57RustFfiState('out', $out);
                self::w57RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W57_FFI_CDEF.)
            $written = $ffi->nntmux_w57_game_pattern_row_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * W57 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
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
