<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Patterns;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

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

    // ========================================================================
    // WAVE 57.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 6.35x, p99 5.92x; uncommon mean 2.97x, p99 2.53x); see RustNameFixingPatternsAppGameMovieTvNameFixResultW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_app_pattern_row_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per appPatternRowBatch() record. */
    public const int W57_ROW_WORDS = 6;

    /**
     * Lowercase needle groups per ALL_PATTERNS entry, in priority order. A pattern can match
     * only when every group has a needle somewhere in the lowercased name.
     */
    private const array W57_PRESCREEN = [
        [['keygen', 'patch', 'crack', 'serial', 'license']],
        [['winall', 'win32', 'win64', 'x64', 'x86']],
        [['mac', 'osx']],
        [['linux', 'ubuntu', 'debian', 'centos', 'rhel', 'fedora']],
        [['adobe', 'photoshop', 'illustrator', 'premiere', 'effects', 'indesign', 'lightroom']],
        [['microsoft', 'office', 'windows', 'visual']],
        [['multi', 'portable', 'repack', 'cracked']],
    ];

    /**
     * App rows for candidate release names. Each record yields six words: the pre-screen mask
     * (bit i: ALL_PATTERNS entry i can match), the ARCHITECTURES and QUALIFIERS masks of
     * delimited tokens (ASCII case-insensitive, neighbours not ASCII alphanumeric), the version
     * span of /(?<![A-Za-z0-9])[vV]?([0-9]+(?:\.[0-9]+)+)/ (offset << 32 | length, or -1), the
     * first candidate pattern index (or -1) and the score 8 * candidates + architectures +
     * qualifiers + 4 with a version.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function appPatternRowBatch(array $names): array
    {
        return self::usesW57RustFfi() ? self::appPatternRowBatchNative($names) : self::appPatternRowBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function appPatternRowBatchPhp(array $names): array
    {
        self::w57Names($names);
        [$arch, $qualifiers] = self::w57Tokens();
        $out = [];
        foreach ($names as $name) {
            $lower = strtolower($name);
            $runs = array_flip(preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $pre = self::w57Prescreen($lower);
            $archMask = self::w57TokenMask($lower, $runs, $arch);
            $qualMask = self::w57TokenMask($lower, $runs, $qualifiers);
            $version = preg_match('/(?<![A-Za-z0-9])[vV]?([0-9]+(?:\.[0-9]+)+)/', $name, $m, PREG_OFFSET_CAPTURE) === 1
                ? ($m[1][1] << 32) | \strlen($m[1][0])
                : -1;
            array_push(
                $out,
                $pre,
                $archMask,
                $qualMask,
                $version,
                $pre === 0 ? -1 : self::w57LowestBit($pre),
                8 * self::w57Bits($pre) + self::w57Bits($archMask) + self::w57Bits($qualMask) + ($version >= 0 ? 4 : 0),
            );
        }

        return $out;
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function appPatternRowBatchNative(array $names): array
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
            ?? self::appPatternRowBatchPhp($names);
    }

    /** @return array{0: list<string>, 1: list<string>} Lowercased ARCHITECTURES and QUALIFIERS. */
    private static function w57Tokens(): array
    {
        static $tokens = null;

        return $tokens ??= [array_map(strtolower(...), self::ARCHITECTURES), array_map(strtolower(...), self::QUALIFIERS)];
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
            $written = $ffi->nntmux_w57_app_pattern_row_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
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
