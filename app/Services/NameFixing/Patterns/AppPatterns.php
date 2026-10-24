<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Patterns;

use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Regex patterns for Application/Software release name matching.
 */
final class AppPatterns
{
    // ========================================================================
    // KEYGEN/CRACK PATTERNS
    // ========================================================================

    /**
     * Software with keygen/patch.
     * Example: App.Name.v1.0.Linux.Incl.Keygen-GROUP
     */
    public const WITH_KEYGEN = '/\w[\-\w.\',;& ]+(\d{1,10}|v\d+[\.\d]*|Linux|UNIX|MacOS)[._ -](RPM|DEB)?[._ -]?(X64|X86|ARM64)?[._ -]?(Incl|With)?[._ -]?(Keygen|Patch|Crack|Serial|License)[\-\w.\',;& ]+\w/i';

    // ========================================================================
    // PLATFORM-SPECIFIC PATTERNS
    // ========================================================================

    /**
     * Windows applications.
     * Example: App.Name.v1.0.WinAll-GROUP
     */
    public const WINDOWS = '/\w[\-\w.\',;& ]+\d{1,8}[._ -](winall|win32|win64|x64|x86)[._ -]?(freeware|portable|repack)?[\-\w.\',;& ]+\w/i';

    /**
     * macOS applications.
     * Example: App.Name.v1.0.MacOS-GROUP
     */
    public const MACOS = '/\w[\-\w.\',;& ]+(MacOS|Mac[._ -]?OS[._ -]?X|OSX)[._ -][\-\w.\',;& ]+\w/i';

    /**
     * Linux applications.
     * Example: App.Name.v1.0.Linux.x64-GROUP
     */
    public const LINUX = '/\w[\-\w.\',;& ]+(Linux|Ubuntu|Debian|CentOS|RHEL|Fedora)[._ -](x64|x86|arm64)?[\-\w.\',;& ]+\w/i';

    // ========================================================================
    // VENDOR-SPECIFIC PATTERNS
    // ========================================================================

    /**
     * Adobe software.
     * Example: Adobe.Photoshop.2024.v25.0-GROUP
     */
    public const ADOBE = '/\w[\-\w.\',;& ]*(Adobe|Photoshop|Illustrator|Premiere|After[._ -]?Effects|InDesign|Lightroom)[._ -][\-\w.\',;& ]+\w/i';

    /**
     * Microsoft software.
     * Example: Microsoft.Office.2021.Pro.Plus-GROUP
     */
    public const MICROSOFT = '/\w[\-\w.\',;& ]*(Microsoft|Office|Windows|Visual[._ -]?Studio)[._ -]\d{2,4}[._ -][\-\w.\',;& ]+\w/i';

    // ========================================================================
    // GENERIC PATTERNS
    // ========================================================================

    /**
     * Generic software with version.
     * Example: App.Name.v1.2.3.Multilingual-GROUP
     */
    public const WITH_VERSION = '/\w[\-\w.\',;& ]+[._ -]v?\d+[\.\d]+[._ -](Multilingual|MULTi|Portable|Repack|Cracked)[\-\w.\',;& ]+\w/i';

    // ========================================================================
    // ARCHITECTURE CONSTANTS
    // ========================================================================

    /**
     * Common architecture identifiers.
     */
    public const ARCHITECTURES = [
        'x64',
        'x86',
        'win64',
        'win32',
        'amd64',
        'arm64',
        'arm',
        'i386',
        'i686',
        'universal',
    ];

    /**
     * Common software qualifiers.
     */
    public const QUALIFIERS = [
        'Portable',
        'Repack',
        'Multilingual',
        'MULTi',
        'Cracked',
        'Registered',
        'Activated',
        'Pre-Activated',
        'Patched',
        'Fixed',
        'Retail',
        'Pro',
        'Enterprise',
        'Ultimate',
        'Professional',
    ];

    // ========================================================================
    // PATTERN COLLECTIONS
    // ========================================================================

    /** @var array<string, string> Priority order is part of the checker contract. */
    public const array ALL_PATTERNS = [
        'WITH_KEYGEN' => self::WITH_KEYGEN,
        'WINDOWS' => self::WINDOWS,
        'MACOS' => self::MACOS,
        'LINUX' => self::LINUX,
        'ADOBE' => self::ADOBE,
        'MICROSOFT' => self::MICROSOFT,
        'WITH_VERSION' => self::WITH_VERSION,
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
            $written = $ffi->nntmux_name_fixing_patterns(0, $mode, $key, strlen($key), $index, $buffer, $capacity);
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
     * Get platform-specific patterns.
     *
     * @return array<string, mixed>
     */
    public static function getPlatformPatterns(): array
    {
        return [
            'WINDOWS' => self::WINDOWS,
            'MACOS' => self::MACOS,
            'LINUX' => self::LINUX,
        ];
    }

    /**
     * Get vendor-specific patterns.
     *
     * @return array<string, mixed>
     */
    public static function getVendorPatterns(): array
    {
        return [
            'ADOBE' => self::ADOBE,
            'MICROSOFT' => self::MICROSOFT,
        ];
    }
}
