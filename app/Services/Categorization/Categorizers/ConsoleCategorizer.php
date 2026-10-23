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
}
