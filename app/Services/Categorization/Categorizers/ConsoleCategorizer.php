<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
