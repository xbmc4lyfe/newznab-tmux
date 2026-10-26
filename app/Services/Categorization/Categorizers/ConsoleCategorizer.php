<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

class ConsoleCategorizer extends AbstractCategorizer
{
    /**
     * Wave 42.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersBookConsoleGroupMiscMovieW42ParityTest.php measures at least 4.1x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W423 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W423_RUST_FFI_DEFAULT = true;

    public const string W423_FFI_CDEF = <<<'C'
        ssize_t nntmux_w423_console_platform_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Console family marker substrings, in match order. The first family with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W423_CONSOLE_FAMILY_MARKERS = [
        'playstation' => ['ps5', 'ps4', 'ps3', 'psvita', 'psp', 'playstation'],
        'xbox' => ['xbox', 'x360', 'xbone', 'xbsx'],
        'nintendo' => ['switch', 'nsw', 'wiiu', 'wii', '3ds', 'nds', 'nintendo'],
    ];

    /** @var list<string> */
    public const array W423_REGION_MARKERS = ['pal', 'ntsc', 'usa', 'eur', 'jpn', 'region.free'];

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W423_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w423RustFfiOverride = null;

    private static FFI|false|null $w423RustFfiHandle = null;

    protected int $priority = 35;

    /**
     * Native (Rust FFI) bindings. Rule ids returned by the library index {@see self::NATIVE_RULES}.
     */
    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_console(const char *name, size_t name_len, uint32_t flags);
CDEF;

    /** @var array<int, string> */
    private const array NATIVE_RULES = [
        0 => 'ps4',
        1 => 'ps3',
        2 => 'psvita',
        3 => 'psp',
        4 => 'xboxone',
        5 => 'xbox360',
        6 => 'xbox',
        7 => 'wiiu',
        8 => 'wii',
        9 => '3ds',
        10 => 'nds',
        11 => 'retro_console',
    ];

    /** Returned by the library instead of a result when `shouldSkip` is true. */
    private const int NATIVE_SKIP = 1 << 62;

    /** Resolved library handle: null until first use, false when the library is unavailable. */
    private static FFI|false|null $nativeFfi = null;

    private static ?CategorizationResult $nativeNoMatch = null;

    /** Native result computed by `shouldSkip()` for the context that `categorize()` receives next. */
    private ?ReleaseContext $nativeContext = null;

    private int $nativePacked = 0;

    private static function resolveNative(): FFI|false
    {
        return self::$nativeFfi = RustPipelineLibrary::resolveDomain('categorizers', self::NATIVE_CDEF) ?? false;
    }

    /**
     * Run the native categorizer without the skip check; null when the library is unavailable or declines.
     */
    private function nativeCall(ReleaseContext $context): ?int
    {
        $ffi = self::$nativeFfi ?? self::resolveNative();
        if ($ffi === false) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_categorize_console($context->releaseName, \strlen($context->releaseName), ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0));
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? $packed : null;
    }

    public function getName(): string
    {
        return 'Console';
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        // One native call decides the skip and, when processing continues, the categorization
        // that the pipe requests next for the same (immutable) context.
        $ffi = self::$nativeFfi ?? self::resolveNative();
        if ($ffi !== false) {
            try {
                // @phpstan-ignore method.notFound
                $packed = $ffi->nntmux_categorize_console($context->releaseName, \strlen($context->releaseName), (($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0)) | 4);
            } catch (Throwable) {
                $packed = -1;
            }
            if (\is_int($packed) && $packed >= 0) {
                if ($packed === self::NATIVE_SKIP) {
                    $this->nativeContext = null;

                    return true;
                }
                $this->nativeContext = $context;
                $this->nativePacked = $packed;

                return false;
            }
        }
        $this->nativeContext = null;

        return $this->shouldSkipPhp($context);
    }

    /**
     * Pure-PHP skip check (fallback when the native library is unavailable).
     */
    public function shouldSkipPhp(ReleaseContext $context): bool
    {
        if ($context->hasAdultMarkers()) {
            return true;
        }
        // Skip TV shows (season patterns)
        if (preg_match('/[._ -]S\d{1,3}[._ -]?(E\d|Complete|Full|1080|720|480|2160|WEB|HDTV|BluRay)/i', $context->releaseName)) {
            return true;
        }

        return false;
    }

    public function categorize(ReleaseContext $context): CategorizationResult
    {
        if ($this->nativeContext === $context) {
            $packed = $this->nativePacked;
            $this->nativeContext = null;
        } else {
            $packed = $this->nativeCall($context);
        }
        if ($packed !== null) {
            if ($packed === 0) {
                return self::$nativeNoMatch ??= CategorizationResult::noMatch();
            }
            $rule = self::NATIVE_RULES[$packed >> 32] ?? null;
            if ($rule !== null) {
                return new CategorizationResult($packed & 0xFFFF, (($packed >> 16) & 0xFFFF) / 1000, $rule);
            }
        }

        return $this->categorizePhp($context);
    }

    /**
     * Pure-PHP categorization (fallback when the native library is unavailable).
     */
    public function categorizePhp(ReleaseContext $context): CategorizationResult
    {
        $name = $context->releaseName;
        if ($result = $this->checkPS4($name)) {
            return $result;
        }
        if ($result = $this->checkPS3($name)) {
            return $result;
        }
        if ($result = $this->checkPSVita($name)) {
            return $result;
        }
        if ($result = $this->checkPSP($name)) {
            return $result;
        }
        if ($result = $this->checkXboxOne($name)) {
            return $result;
        }
        if ($result = $this->checkXbox360($name)) {
            return $result;
        }
        if ($result = $this->checkXbox($name)) {
            return $result;
        }
        if ($result = $this->checkWiiU($name)) {
            return $result;
        }
        if ($result = $this->checkWii($name)) {
            return $result;
        }
        if ($result = $this->check3DS($name)) {
            return $result;
        }
        if ($result = $this->checkNDS($name)) {
            return $result;
        }
        if ($result = $this->checkOther($name)) {
            return $result;
        }

        return $this->noMatch();
    }

    protected function checkPS4(string $name): ?CategorizationResult
    {
        if (preg_match('/^PS4[_\.\-]/i', $name) || preg_match('/CUSA\d{5}/i', $name) ||
            preg_match('/\.PS4-DUPLEX$/i', $name) || preg_match('/\bPS4\b|PlayStation\s*4/i', $name)) {
            return $this->matched(Category::GAME_PS4, 0.9, 'ps4');
        }

        return null;
    }

    protected function checkPS3(string $name): ?CategorizationResult
    {
        if (preg_match('/\bPS3\b|PlayStation\s*3/i', $name)) {
            return $this->matched(Category::GAME_PS3, 0.9, 'ps3');
        }

        return null;
    }

    protected function checkPSVita(string $name): ?CategorizationResult
    {
        if (preg_match('/\bPS\s?Vita\b|PSV(ita)?\b/i', $name)) {
            return $this->matched(Category::GAME_PSVITA, 0.9, 'psvita');
        }

        return null;
    }

    protected function checkPSP(string $name): ?CategorizationResult
    {
        if (preg_match('/\bPSP\b|PlayStation\s*Portable/i', $name)) {
            return $this->matched(Category::GAME_PSP, 0.9, 'psp');
        }

        return null;
    }

    protected function checkXboxOne(string $name): ?CategorizationResult
    {
        if (preg_match('/\b(XboxOne|XBOX\s*One|XBONE|XB1|Xbox\s*Series[._ -]?[SX]|XSX|XSS)\b/i', $name)) {
            return $this->matched(Category::GAME_XBOXONE, 0.9, 'xboxone');
        }

        return null;
    }

    protected function checkXbox360(string $name): ?CategorizationResult
    {
        if (preg_match('/\b(Xbox360|XBOX360|X360)\b/i', $name)) {
            return $this->matched(Category::GAME_XBOX360, 0.9, 'xbox360');
        }

        return null;
    }

    protected function checkXbox(string $name): ?CategorizationResult
    {
        if (preg_match('/\bXBOX\b/i', $name) && ! preg_match('/\b(XBOX\s?360|XBOX\s?ONE|Series)\b/i', $name)) {
            return $this->matched(Category::GAME_XBOX, 0.85, 'xbox');
        }

        return null;
    }

    protected function checkWiiU(string $name): ?CategorizationResult
    {
        if (preg_match('/\bWii\s*U\b|WiiU/i', $name)) {
            return $this->matched(Category::GAME_WIIU, 0.9, 'wiiu');
        }

        return null;
    }

    protected function checkWii(string $name): ?CategorizationResult
    {
        if (preg_match('/\bWii\b/i', $name) && ! preg_match('/WiiU/i', $name)) {
            return $this->matched(Category::GAME_WII, 0.85, 'wii');
        }

        return null;
    }

    protected function check3DS(string $name): ?CategorizationResult
    {
        if (preg_match('/\b3DS\b|Nintendo\s*3DS/i', $name)) {
            return $this->matched(Category::GAME_3DS, 0.9, '3ds');
        }

        return null;
    }

    protected function checkNDS(string $name): ?CategorizationResult
    {
        if (preg_match('/\bNDS\b|Nintendo\s*DS/i', $name)) {
            return $this->matched(Category::GAME_NDS, 0.9, 'nds');
        }

        return null;
    }

    protected function checkOther(string $name): ?CategorizationResult
    {
        if (preg_match('/\b(PS[12X]|PS2|SNES|NES|SEGA|GB[AC]?|Dreamcast|Saturn|Atari|N64)\b/i', $name) &&
            preg_match('/\b(EUR|JP|JPN|NTSC|PAL|USA|ROM)\b/i', $name)) {
            return $this->matched(Category::GAME_OTHER, 0.8, 'retro_console');
        }

        return null;
    }

    public static function useW423RustFfi(bool $enable = true): void
    {
        self::$w423RustFfiOverride = $enable;
    }

    public static function usesW423RustFfi(): bool
    {
        return self::$w423RustFfiOverride ?? self::W423_RUST_FFI_DEFAULT;
    }

    public static function resetW423RustFfi(): void
    {
        self::$w423RustFfiOverride = null;
        self::$w423RustFfiHandle = null;
    }

    /**
     * Console platform summary for a batch of release names. Each lowercased name adds to its first
     * W423_CONSOLE_FAMILY_MARKERS family, else to unknown, and separately to region when it holds
     * any W423_REGION_MARKERS substring. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, playstation: int, xbox: int, nintendo: int, unknown: int, region: int}
     */
    public static function summarizeConsolePlatformBatch(array $releaseNames): array
    {
        return (self::usesW423RustFfi() ? self::summarizeConsolePlatformBatchNative($releaseNames) : null)
            ?? self::summarizeConsolePlatformBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, playstation: int, xbox: int, nintendo: int, unknown: int, region: int}
     */
    public static function summarizeConsolePlatformBatchPhp(array $releaseNames): array
    {
        $families = ['playstation' => 0, 'xbox' => 0, 'nintendo' => 0];
        $unknown = 0;
        $region = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W423_REGION_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $region++;

                    break;
                }
            }
            foreach (self::W423_CONSOLE_FAMILY_MARKERS as $family => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $families[$family]++;

                        continue 3;
                    }
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'playstation' => $families['playstation'],
            'xbox' => $families['xbox'],
            'nintendo' => $families['nintendo'],
            'unknown' => $unknown,
            'region' => $region,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, playstation: int, xbox: int, nintendo: int, unknown: int, region: int}|null
     */
    public static function summarizeConsolePlatformBatchNative(array $releaseNames): ?array
    {
        $lengths = [];
        foreach ($releaseNames as $item) {
            if (! \is_string($item)) {
                return null;
            }
            $lengths[] = \strlen($item);
        }
        $blob = implode('', $releaseNames);
        $lens = pack('V*', ...$lengths);
        $fields = self::callW423Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'playstation' => (int) $fields[1],
            'xbox' => (int) $fields[2],
            'nintendo' => (int) $fields[3],
            'unknown' => (int) $fields[4],
            'region' => (int) $fields[5],
        ];
    }

    /**
     * Calls this class's Wave 42.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W423_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW423Native(string $blob, string $lens): ?array
    {
        $ffi = self::w423RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w423_console_platform_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w423_console_platform_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W423_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w423RustFfi(): ?FFI
    {
        if (self::$w423RustFfiHandle === null) {
            self::$w423RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w423', self::W423_FFI_CDEF) ?? false;
        }

        return self::$w423RustFfiHandle === false ? null : self::$w423RustFfiHandle;
    }

    // ========================================================================
    // WAVE 60.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.36x, p99 6.78x; uncommon mean 7.73x, p99 5.21x); see RustCategorizersAbstractBookConsoleGroupNameReleaseContextW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    public const string W60_CONSOLE_CATEGORIZER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w603_console_platform_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w60ConsolePlatformBatch(). */
    public const int W60_CONSOLE_CATEGORIZER_TRAILER = 36;

    /**
     * Platform tokens: 1 PS5, 2 PS4, 3 PS3, 4 PS Vita, 5 PSP, 6 Xbox One/Series, 7 Xbox 360, 8 Xbox, 9 Switch, 10 Wii U,
     * 11 Wii, 12 3DS, 13 NDS and 14 retro (`xbox` and `wii` depend on the next token; PPSA/CUSA ids by shape).
     *
     * @var array<string, int>
     */
    public const array W60_CONSOLE_PLATFORM_TOKENS = ['ps5' => 1, 'ps4' => 2, 'ps3' => 3, 'psvita' => 4, 'vita' => 4, 'psv' => 4, 'psp' => 5,
        'xboxone' => 6, 'xbone' => 6, 'xb1' => 6, 'xsx' => 6, 'xss' => 6, 'xbsx' => 6, 'xbox360' => 7, 'x360' => 7, 'switch' => 9, 'nsw' => 9,
        'nsp' => 9, 'xci' => 9, 'wiiu' => 10, 'wbfs' => 11, '3ds' => 12, 'cia' => 12, 'nds' => 13, 'snes' => 14, 'nes' => 14, 'n64' => 14,
        'gba' => 14, 'gbc' => 14, 'gb' => 14, 'dreamcast' => 14, 'saturn' => 14, 'atari' => 14, 'sega' => 14, 'ps2' => 14, 'ps1' => 14,
        'psx' => 14, 'mame' => 14, 'genesis' => 14, 'megadrive' => 14];

    /**
     * Region tokens: 1 PAL, 2 NTSC, 3 USA, 4 EUR, 5 JPN, 6 Asia, 7 Korea, 8 region free and 9 multi-language
     * (`region free` and `multiN` are detected in code).
     *
     * @var array<string, int>
     */
    public const array W60_CONSOLE_REGION_TOKENS = ['pal' => 1, 'ntsc' => 2, 'usa' => 3, 'us' => 3, 'eur' => 4, 'europe' => 4, 'eu' => 4,
        'jpn' => 5, 'japan' => 5, 'jp' => 5, 'asia' => 6, 'kor' => 7, 'korea' => 7, 'regionfree' => 8, 'multi' => 9];

    /**
     * Format flags: 1 DLC, 2 update, 4 PKG, 8 ISO, 16 NSP/XCI, 32 CIA, 64 WBFS and 128 ROM.
     *
     * @var array<string, int>
     */
    public const array W60_CONSOLE_FORMAT_TOKENS = ['dlc' => 1, 'update' => 2, 'patch' => 2, 'pkg' => 4, 'iso' => 8, 'nsp' => 16, 'xci' => 16,
        'cia' => 32, 'wbfs' => 64, 'rom' => 128, 'roms' => 128];

    /** Category ids by platform (PS5 shares the PS4 category; Switch and retro use GAME_OTHER). */
    public const array W60_CONSOLE_CATEGORIES = [0, Category::GAME_PS4, Category::GAME_PS4, Category::GAME_PS3, Category::GAME_PSVITA,
        Category::GAME_PSP, Category::GAME_XBOXONE, Category::GAME_XBOX360, Category::GAME_XBOX, Category::GAME_OTHER, Category::GAME_WIIU,
        Category::GAME_WII, Category::GAME_3DS, Category::GAME_NDS, Category::GAME_OTHER];

    /** Confidence in thousandths by platform. */
    public const array W60_CONSOLE_CONFIDENCE = [0, 800, 900, 900, 900, 900, 900, 900, 850, 850, 900, 850, 900, 900, 800];

    /** @var list<string> */
    public const array W60_CONSOLE_GROUP_HINTS = ['console', 'games', 'nintendo', 'wii', 'xbox', 'ps3', 'ps4', 'psp', 'sony'];

    public static function usesW60RustFfi(): bool
    {
        $override = self::w60RustFfiState();

        return \is_bool($override) ? $override : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ConsoleCategorizer Wave 60 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 60.3 console platform and region plans. Each item is `releaseName|groupName` (fields trimmed). Per item
     * `platform | region << 4 | formats << 8 | altBinaries << 16 | groupHint << 17 | categoryId << 18 | confidenceMilli
     * << 29 | min(tokens, 63) << 39 | min(nameLength, 255) << 45` over the `[^a-z0-9]+`-separated lowercase tokens:
     * platform is the smallest W60_CONSOLE_PLATFORM_TOKENS index of any token (`xbox` is 6 before `one`/`series`, 7
     * before `360`, else 8; `wii` is 10 before `u`, else 11; `ppsa` plus 4-6 digits is 1 and `cusa` plus 5 digits is
     * 2), region the smallest W60_CONSOLE_REGION_TOKENS index (`region` before `free` is 8, `multi` plus 1-2 digits
     * is 9), formats OR W60_CONSOLE_FORMAT_TOKENS, alt.binaries marks a case-insensitive `alt.binaries.` group
     * prefix and the hint any W60_CONSOLE_GROUP_HINTS substring; category and confidence come from
     * W60_CONSOLE_CATEGORIES and W60_CONSOLE_CONFIDENCE. Then fifteen platform, ten region and eight format counts,
     * the alt.binaries and hint counts and the distinct (platform, region) pairs of items with a platform.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60ConsolePlatformBatch(array $items): array
    {
        return self::usesW60RustFfi() ? self::w60ConsolePlatformBatchNative($items) : self::w60ConsolePlatformBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60ConsolePlatformBatchNative(array $items): array
    {
        return self::w60Invoke($items, \count($items) + self::W60_CONSOLE_CATEGORIZER_TRAILER) ?? self::w60ConsolePlatformBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w60ConsolePlatformBatchPhp(array $items): array
    {
        $out = [];
        $platforms = array_fill(0, 15, 0);
        $regions = array_fill(0, 10, 0);
        $formats = array_fill(0, 8, 0);
        $alt = $hints = 0;
        $pairs = [];
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 2) + ['', ''];
            $name = trim($f[0]);
            $group = strtolower(trim($f[1]));
            $tokens = $name === '' ? [] : (preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $platform = $region = $format = 0;
            foreach ($tokens as $i => $token) {
                $token = (string) $token;
                $next = (string) ($tokens[$i + 1] ?? '');
                $length = \strlen($token);
                $p = match (true) {
                    $token === 'xbox' => $next === 'one' || $next === 'series' ? 6 : ($next === '360' ? 7 : 8),
                    $token === 'wii' => $next === 'u' ? 10 : 11,
                    $length >= 8 && $length <= 10 && str_starts_with($token, 'ppsa') && strspn($token, '0123456789', 4) === $length - 4 => 1,
                    $length === 9 && str_starts_with($token, 'cusa') && strspn($token, '0123456789', 4) === 5 => 2,
                    default => self::W60_CONSOLE_PLATFORM_TOKENS[$token] ?? 0,
                };
                if ($p !== 0 && ($platform === 0 || $p < $platform)) {
                    $platform = $p;
                }
                $r = match (true) {
                    $token === 'region' => $next === 'free' ? 8 : 0,
                    ($length === 6 || $length === 7) && str_starts_with($token, 'multi') && strspn($token, '0123456789', 5) === $length - 5 => 9,
                    default => self::W60_CONSOLE_REGION_TOKENS[$token] ?? 0,
                };
                if ($r !== 0 && ($region === 0 || $r < $region)) {
                    $region = $r;
                }
                $format |= self::W60_CONSOLE_FORMAT_TOKENS[$token] ?? 0;
            }
            $isAlt = str_starts_with($group, 'alt.binaries.') ? 1 : 0;
            $hint = 0;
            foreach (self::W60_CONSOLE_GROUP_HINTS as $needle) {
                if (str_contains($group, $needle)) {
                    $hint = 1;

                    break;
                }
            }
            $platforms[$platform]++;
            $regions[$region]++;
            for ($b = 0; $b < 8; $b++) {
                $formats[$b] += $format >> $b & 1;
            }
            $alt += $isAlt;
            $hints += $hint;
            if ($platform !== 0) {
                $pairs[$platform * 10 + $region] = true;
            }
            $out[] = $platform | $region << 4 | $format << 8 | $isAlt << 16 | $hint << 17 | self::W60_CONSOLE_CATEGORIES[$platform] << 18
                | self::W60_CONSOLE_CONFIDENCE[$platform] << 29 | min(\count($tokens), 63) << 39 | min(\strlen($name), 255) << 45;
        }
        array_push($out, ...$platforms, ...$regions, ...$formats);
        array_push($out, $alt, $hints, \count($pairs));

        return $out;
    }

    /**
     * Wave 60 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w603', self::W60_CONSOLE_CATEGORIZER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w60RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w60RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 60.3 ConsoleCategorizer export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w60Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w60RustFfiState('ffi');
            $out = self::w60RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w60RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W60_CONSOLE_CATEGORIZER_FFI_CDEF.)
            $written = $ffi->nntmux_w603_console_platform_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
