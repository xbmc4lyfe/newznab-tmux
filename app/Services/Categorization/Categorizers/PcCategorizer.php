<?php

declare(strict_types=1);

namespace App\Services\Categorization\Categorizers;

use App\Models\Category;
use App\Services\Categorization\CategorizationResult;
use App\Services\Categorization\ReleaseContext;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Categorizer for PC content (Games, Software, ISO, 0day, Mac, Phone apps).
 */
class PcCategorizer extends AbstractCategorizer
{
    protected int $priority = 30;

    // Common PC game release groups (matched at end of release name only)
    protected const PC_GROUPS = '0x0007|ALiAS|ANOMALY|BACKLASH|BAT|BLASTCiTY|CHRONOS|CODEX|CONSPIRACY|CPY|CROSSFiRE|DARKS(?:iDERS|IDERS)|DELiGHT|DEViANCE|DINOByTES|DOGE|DODI|DVN|ELAMIGOS|EMPRESS|ENiGMA|FANiSO|FAS(?:DOX|iSO)|FCKDRM|FITGIRL|FLT|FLTDOX|GGS|GOG(?:-?GAMES)?|GOLDBERG|HATRED|HI2U|HOODLUM|I_KnoW|iNLAWS|iNTERNAL|JAGUAR|KACZKRULL|KAOS|KaOsKrew|LAZARUS|LiGHTFORCE|MEPHISTO|OUTLAWS|PARADOX|PHOENIX|PLAZA|POSTMORTEM|PROPHET|PROVOKED|RADIUM|RAZOR(?:1911|DOX)|RELOADED|REVOLUTiON|RUNE|SAFARi|SCRUBS|SiLENTGATE|SiMPLEX|SKIDROW|SPLiNTERCELL|STEAMPUNKS|SUXXORS|TENOKE|TiNYiSO|UNLEASHED|VENGEANCE|ViTALiTY|VENOM|VACE|ZEKE|P2P';

    // PC-only keywords (can appear anywhere) - triggers PC_GAMES category
    protected const PC_KEYWORDS = 'PC[ _.-]?GAMES?|\[PC\]|\(PC\)|Steam[._-]?Rip|Retail\s*PC|DRM-?Free';

    /**
     * Native (Rust FFI) bindings. Rule ids returned by the library index {@see self::NATIVE_RULES}.
     */
    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_pc(const char *name, size_t name_len, const char *poster, size_t poster_len, uint32_t flags);
CDEF;

    /** @var array<int, string> */
    private const array NATIVE_RULES = [
        0 => 'ios',
        1 => 'android',
        2 => 'phone_other',
        3 => 'mac',
        4 => 'macos',
        5 => 'pc_game',
        6 => 'pc_game_poster',
        7 => 'iso',
        8 => 'training_iso',
        9 => '0day_msix_installer',
        10 => '0day_explicit',
        11 => '0day_system',
        12 => '0day_software',
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
            $packed = $ffi->nntmux_categorize_pc($context->releaseName, \strlen($context->releaseName), $context->poster, \strlen($context->poster), ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0));
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? $packed : null;
    }

    public function getName(): string
    {
        return 'PC';
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        // One native call decides the skip and, when processing continues, the categorization
        // that the pipe requests next for the same (immutable) context.
        $ffi = self::$nativeFfi ?? self::resolveNative();
        if ($ffi !== false) {
            try {
                // @phpstan-ignore method.notFound
                $packed = $ffi->nntmux_categorize_pc($context->releaseName, \strlen($context->releaseName), $context->poster, \strlen($context->poster), (($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0)) | 4);
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

        // Try each PC category
        if ($result = $this->checkPhone($name)) {
            return $result;
        }

        if ($result = $this->checkMac($name)) {
            return $result;
        }

        if ($result = $this->checkPCGame($name, $context->poster)) {
            return $result;
        }

        if ($result = $this->checkISO($name)) {
            return $result;
        }

        if ($result = $this->check0day($name)) {
            return $result;
        }

        return $this->noMatch();
    }

    protected function checkPhone(string $name): ?CategorizationResult
    {
        // iOS
        if (preg_match('/[^a-z0-9](IPHONE|ITOUCH|IPAD)[._ -]/i', $name)) {
            return $this->matched(Category::PC_PHONE_IOS, 0.9, 'ios');
        }

        // Android
        if (preg_match('/[._ -]?(ANDROID)[._ -]/i', $name)) {
            return $this->matched(Category::PC_PHONE_ANDROID, 0.9, 'android');
        }

        // Other mobile platforms
        if (preg_match('/[^a-z0-9](symbian|xscale|wm5|wm6)[._ -]/i', $name)) {
            return $this->matched(Category::PC_PHONE_OTHER, 0.85, 'phone_other');
        }

        return null;
    }

    protected function checkMac(string $name): ?CategorizationResult
    {
        if (preg_match('/(\b|[._ -])mac([\.\s])?osx(\b|[\-_. ])/i', $name)) {
            return $this->matched(Category::PC_MAC, 0.9, 'mac');
        }

        if (preg_match('/\b(Mac\s?OS\s?X|macOS)\b/i', $name)) {
            return $this->matched(Category::PC_MAC, 0.9, 'macos');
        }

        return null;
    }

    protected function checkPCGame(string $name, string $poster): ?CategorizationResult
    {
        // Exclude console releases
        $consoleOrMac = '/\b(PS5|PS4|PS3|PlayStation|PS(Vita|V)\b|Xbox\s?(Series|One|360)|XBOX(ONE|360|SERIES|SX|SS)?|XSX|XSS|XBSX|NSW|Switch|WiiU|Wii|3DS|NDS|PSP|PSV(ita)?|GameCube|NGC|CUSA\d{5}|XCI|NSP|PKG)\b/i';
        if (preg_match($consoleOrMac, $name) || preg_match('/\b(Mac\s?OS\s?X|macOS)\b/i', $name)) {
            return null;
        }

        // Exclude TV shows
        $tvPatterns = '/\b(S\d{1,4}[._ -]?E\d{1,4}|S\d{1,4}[._ -]?D\d{1,4}|\d{1,2}x\d{2,3}|Season[._ -]?\d{1,3}|Episode[._ -]?\d{1,4}|HDTV|PDTV|DSR|WEB[._ -]?DL|WEB[._ -]?RIP|TVRip)\b/i';
        if (preg_match($tvPatterns, $name)) {
            return null;
        }

        // Check for PC game patterns
        // PC_GROUPS must be at the END of the release name (scene naming: Game.Name-GROUP)
        // PC_KEYWORDS can appear anywhere
        $groupPattern = '/[-.]('.self::PC_GROUPS.')$/i';
        $keywordPattern = '/'.self::PC_KEYWORDS.'/i';

        if (preg_match($groupPattern, $name) || preg_match($keywordPattern, $name)) {
            return $this->matched(Category::PC_GAMES, 0.9, 'pc_game');
        }

        // Check poster
        if (preg_match('/<PC@MASTER\.RACE>/i', $poster)) {
            return $this->matched(Category::PC_GAMES, 0.85, 'pc_game_poster');
        }

        return null;
    }

    protected function checkISO(string $name): ?CategorizationResult
    {
        if (preg_match('/[._ -]([a-zA-Z]{2,10})?iso[ _.-]|[\-. ]([a-z]{2,10})?iso$/i', $name)) {
            return $this->matched(Category::PC_ISO, 0.85, 'iso');
        }

        // Training/Tutorial ISOs
        if (preg_match('/[._ -](DYNAMiCS|INFINITESKILLS|UDEMY|kEISO|PLURALSIGHT|DIGITALTUTORS|TUTSPLUS|OSTraining|PRODEV|CBT\.Nuggets|COMPRISED)/i', $name)) {
            return $this->matched(Category::PC_ISO, 0.9, 'training_iso');
        }

        return null;
    }

    protected function check0day(string $name): ?CategorizationResult
    {
        if (preg_match('/\.(msix|msixbundle|appx|appxbundle|msi)$/i', $name)) {
            return $this->matched(Category::PC_0DAY, 0.9, '0day_msix_installer');
        }

        // Explicit 0day indicators
        if (preg_match('/[._ -]exe$|[._ -](utorrent|Virtualbox)[._ -]|\b0DAY\b|incl.+crack| DRM$|>DRM</i', $name)) {
            return $this->matched(Category::PC_0DAY, 0.9, '0day_explicit');
        }

        // System/architecture indicators
        if (preg_match('/[._ -]((32|64)bit|converter|i\d86|key(gen|maker)|freebsd|GAMEGUiDE|hpux|irix|linux|multilingual|Patch|Pro v\d{1,3}|portable|regged|software|solaris|template|unix|win2kxp2k3|win64|win(2k|32|64|all|dows|nt(2k)?(xp)?|xp)|win9x(me|nt)?|x(32|64|86))[._ -]/i', $name)) {
            return $this->matched(Category::PC_0DAY, 0.85, '0day_system');
        }

        // Software vendors and patterns
        if (preg_match('/\b(Adobe|auto(cad|desk)|-BEAN|Cracked|Cucusoft|CYGNUS|Divx[._ -]Plus|\.(deb|exe)|DIGERATI|FOSI|-FONT|Key(filemaker|gen|maker)|Lynda\.com|lz0|MULTiLANGUAGE|Microsoft\s*(Office|Windows|Server)|MultiOS|-(iNViSiBLE|SPYRAL|SUNiSO|UNION|TE)|v\d{1,3}.*?Pro|[._ -]v\d{1,3}[._ -]|\(x(64|86)\)|Xilisoft)\b/i', $name)) {
            return $this->matched(Category::PC_0DAY, 0.85, '0day_software');
        }

        return null;
    }
}
