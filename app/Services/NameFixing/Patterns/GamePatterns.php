<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Patterns;

use App\Support\RustPipelineLibrary;
use FFI;

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
}
