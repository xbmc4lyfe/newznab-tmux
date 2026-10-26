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

/**
 * Categorizer for PC content (Games, Software, ISO, 0day, Mac, Phone apps).
 */
class PcCategorizer extends AbstractCategorizer
{
    /**
     * Wave 43.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersMusicPcTvXxxPipeW43ParityTest.php measures at least 3.3x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W433 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W433_RUST_FFI_DEFAULT = true;

    public const string W433_FFI_CDEF = <<<'C'
        ssize_t nntmux_w433_pc_platform_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * PC platform marker substrings, in match order. The first class with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W433_PC_PLATFORM_MARKERS = [
        'phone' => ['iphone', 'ipad', 'android', '.apk', '.ipa'],
        'mac' => ['macos', 'mac.os', 'osx', '.dmg'],
        'game' => ['pc.game', 'pcgame', 'steamrip', 'codex', 'plaza', 'fitgirl', 'gog'],
        'iso' => ['.iso', '.img', 'dvd9'],
        'zeroday' => ['keygen', 'win64', 'win32', 'x64', 'x86', 'portable', 'incl.crack'],
    ];

    /** @var list<string> */
    public const array W433_MULTI_MARKERS = ['multi'];

    /** Exactly 8 0x1F-separated decimals of 1-19 digits. */
    private const string W433_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){7}\d{1,19}\z/';

    private static ?bool $w433RustFfiOverride = null;

    private static FFI|false|null $w433RustFfiHandle = null;

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

    /**
     * Enables (true) or disables (false) the Wave 43.3 Rust helper; null restores
     * W433_RUST_FFI_DEFAULT.
     */
    public static function useW433RustFfi(?bool $enable = true): void
    {
        self::$w433RustFfiOverride = $enable;
    }

    public static function usesW433RustFfi(): bool
    {
        return self::$w433RustFfiOverride ?? self::W433_RUST_FFI_DEFAULT;
    }

    public static function resetW433RustFfi(): void
    {
        self::$w433RustFfiOverride = null;
        self::$w433RustFfiHandle = null;
    }

    /**
     * PC platform summary for a batch of release names. Each lowercased name adds to its first
     * W433_PC_PLATFORM_MARKERS class, else to unknown, and separately to multi when it holds any
     * W433_MULTI_MARKERS entry. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, phone: int, mac: int, game: int, iso: int, zeroday: int, unknown: int, multi: int}
     */
    public static function summarizePcPlatformBatch(array $releaseNames): array
    {
        return (self::usesW433RustFfi() ? self::summarizePcPlatformBatchNative($releaseNames) : null)
            ?? self::summarizePcPlatformBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, phone: int, mac: int, game: int, iso: int, zeroday: int, unknown: int, multi: int}
     */
    public static function summarizePcPlatformBatchPhp(array $releaseNames): array
    {
        $phone = 0;
        $mac = 0;
        $game = 0;
        $iso = 0;
        $zeroday = 0;
        $unknown = 0;
        $multiCount = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W433_MULTI_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $multiCount++;

                    break;
                }
            }
            foreach (self::W433_PC_PLATFORM_MARKERS['phone'] as $needle) {
                if (str_contains($name, $needle)) {
                    $phone++;

                    continue 2;
                }
            }
            foreach (self::W433_PC_PLATFORM_MARKERS['mac'] as $needle) {
                if (str_contains($name, $needle)) {
                    $mac++;

                    continue 2;
                }
            }
            foreach (self::W433_PC_PLATFORM_MARKERS['game'] as $needle) {
                if (str_contains($name, $needle)) {
                    $game++;

                    continue 2;
                }
            }
            foreach (self::W433_PC_PLATFORM_MARKERS['iso'] as $needle) {
                if (str_contains($name, $needle)) {
                    $iso++;

                    continue 2;
                }
            }
            foreach (self::W433_PC_PLATFORM_MARKERS['zeroday'] as $needle) {
                if (str_contains($name, $needle)) {
                    $zeroday++;

                    continue 2;
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'phone' => $phone,
            'mac' => $mac,
            'game' => $game,
            'iso' => $iso,
            'zeroday' => $zeroday,
            'unknown' => $unknown,
            'multi' => $multiCount,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, phone: int, mac: int, game: int, iso: int, zeroday: int, unknown: int, multi: int}|null
     */
    public static function summarizePcPlatformBatchNative(array $releaseNames): ?array
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
        $fields = self::callW433Native($blob, $lens);
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'phone' => (int) $fields[1],
            'mac' => (int) $fields[2],
            'game' => (int) $fields[3],
            'iso' => (int) $fields[4],
            'zeroday' => (int) $fields[5],
            'unknown' => (int) $fields[6],
            'multi' => (int) $fields[7],
        ];
    }

    /**
     * Calls this class's Wave 43.3 export with the shared scratch buffer, retrying once at the size
     * it asks for. Returns its fields only when they match W433_FIELDS_PATTERN: exactly the expected
     * count of 1-19 digit decimals.
     *
     * @return list<string>|null
     */
    private static function callW433Native(string $blob, string $lens): ?array
    {
        $ffi = self::w433RustFfi();
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
            $written = $ffi->nntmux_w433_pc_platform_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w433_pc_platform_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W433_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w433RustFfi(): ?FFI
    {
        if (self::$w433RustFfiHandle === null) {
            self::$w433RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w433', self::W433_FFI_CDEF) ?? false;
        }

        return self::$w433RustFfiHandle === false ? null : self::$w433RustFfiHandle;
    }

    // ========================================================================
    // WAVE 61.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.51x, p99 3.60x; uncommon mean 6.88x, p99 4.95x); see RustCategorizersMiscMovieMusicPcAnimeProcessorW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    public const string W61_PC_CATEGORIZER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w611_pc_token_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w61PcTokenBatch(). */
    public const int W61_PC_CATEGORIZER_TRAILER = 37;

    /**
     * PC token flags: 1 software/architecture, 2 disc image, 4 Mac, 8 iOS, 16 Android, 32 game, 64 Windows, 128 Linux,
     * 256 software brand, 1024 multi-language, 2048 course (blocks), 4096 crack/keygen, 8192 console (blocks) and
     * 32768 0day (512 for `v` + 1-4 digits, 1024 for `multi` + 1-2 digits and 16384 for a 19xx/20xx year are
     * detected by shape).
     *
     * @var array<string, int>
     */
    public const array W61_PC_TOKEN_FLAGS = ['x86' => 1, '32bit' => 1, 'win32' => 65, 'x64' => 1, 'amd64' => 1, '64bit' => 1, 'win64' => 65,
        'arm64' => 1, 'aarch64' => 1, 'arm' => 1, 'portable' => 1, 'setup' => 1, 'installer' => 1, 'retail' => 1, 'final' => 1, 'build' => 1,
        'pro' => 1, 'professional' => 1, 'enterprise' => 1, 'ultimate' => 1, 'iso' => 2, 'img' => 2, 'bin' => 2, 'cue' => 2, 'nrg' => 2,
        'mdf' => 2, 'macos' => 4, 'osx' => 4, 'macosx' => 4, 'dmg' => 4, 'mac' => 4, 'ios' => 8, 'iphone' => 8, 'ipad' => 8, 'ipa' => 8,
        'android' => 16, 'apk' => 16, 'xapk' => 16, 'game' => 32, 'games' => 32, 'gog' => 32, 'steam' => 32, 'fitgirl' => 32, 'dodi' => 32,
        'codex' => 32, 'plaza' => 32, 'skidrow' => 32, 'reloaded' => 32, 'cpy' => 32, 'empress' => 32, 'razor1911' => 32, 'tenoke' => 32,
        'flt' => 32, 'rune' => 32, 'elamigos' => 32, 'prophet' => 32, 'hoodlum' => 32, 'dlc' => 32, 'windows' => 64, 'win' => 64, 'win10' => 64,
        'win11' => 64, 'winall' => 64, 'linux' => 128, 'ubuntu' => 128, 'debian' => 128, 'fedora' => 128, 'deb' => 128, 'rpm' => 128,
        'appimage' => 128, 'office' => 256, 'adobe' => 256, 'photoshop' => 256, 'autocad' => 256, 'microsoft' => 256, 'vmware' => 256,
        'multi' => 1024, 'multilingual' => 1024, 'tutorial' => 2048, 'course' => 2048, 'udemy' => 2048, 'lynda' => 2048, 'pluralsight' => 2048,
        'training' => 2048, 'crack' => 4096, 'cracked' => 4096, 'keygen' => 4096, 'patch' => 4096, 'serial' => 4096, 'activator' => 4096,
        'regged' => 4096, 'incl' => 4096, 'ps4' => 8192, 'ps5' => 8192, 'xbox' => 8192, 'switch' => 8192, 'nsp' => 8192, 'wii' => 8192,
        'xci' => 8192, '0day' => 32768, 'zeroday' => 32768];

    /**
     * Architecture mask bits: 1 x86, 2 x64 and 4 ARM.
     *
     * @var array<string, int>
     */
    public const array W61_PC_ARCHITECTURES = ['x86' => 1, '32bit' => 1, 'win32' => 1, 'x64' => 2, 'amd64' => 2, '64bit' => 2, 'win64' => 2,
        'arm64' => 4, 'aarch64' => 4, 'arm' => 4];

    /**
     * Category per w61PcTokenBatch() rule.
     *
     * @var list<int>
     */
    public const array W61_PC_RULE_CATEGORIES = [0, Category::PC_PHONE_IOS, Category::PC_PHONE_ANDROID, Category::PC_MAC, Category::PC_GAMES,
        Category::PC_ISO, Category::PC_0DAY];

    public static function usesW61RustFfi(): bool
    {
        $override = self::w61RustFfiState();

        return \is_bool($override) ? $override : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the PcCategorizer Wave 61 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 61.1 PC software and game token plans. Each item is a release name (trimmed). Per item `rule | platform
     * << 3 | arch << 6 | flags << 9 | version << 25 | confidenceMilli << 39 | min(tokens, 63) << 49 |
     * min(nameLength, 255) << 55`: flags OR the W61_PC_TOKEN_FLAGS of every `[^a-z0-9]+`-separated lowercase token
     * plus the shape flags; the architecture is 4 ARM, 3 x86 and x64, 2 x64, 1 x86 or 0 from the
     * W61_PC_ARCHITECTURES mask; the platform is 4 iOS, 5 Android, 2 Mac, 3 Linux, 1 Windows (flag or x86/x64) or 0;
     * the version is the first `v` + 1-4 digit token (0 none). The rule (W61_PC_RULE_CATEGORIES) is 0 with a course
     * or console flag, else in order 1 iOS (0.9), 2 Android (0.9), 3 Mac (0.85), 4 game (0.85), 5 disc image (0.8),
     * 6 0day (software, Windows, Linux, brand, version, crack or 0day flag; 0.8 with a crack, else 0.75), else 0.
     * Then seven rule, six platform, five architecture and sixteen flag counts, the confidence sum, the largest
     * version and the distinct versions of items with a version token.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61PcTokenBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::w61PcTokenBatchNative($items) : self::w61PcTokenBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61PcTokenBatchNative(array $items): array
    {
        return self::w61Invoke($items, \count($items) + self::W61_PC_CATEGORIZER_TRAILER) ?? self::w61PcTokenBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w61PcTokenBatchPhp(array $items): array
    {
        $out = [];
        $rules = array_fill(0, 7, 0);
        $platforms = [0, 0, 0, 0, 0, 0];
        $architectures = [0, 0, 0, 0, 0];
        $flagCounts = array_fill(0, 16, 0);
        $confidenceSum = $maxVersion = 0;
        $versions = [];
        foreach ($items as $item) {
            $name = trim((string) $item);
            $tokens = $name === '' ? [] : (preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $flags = $mask = 0;
            $version = null;
            foreach ($tokens as $token) {
                $flags |= self::W61_PC_TOKEN_FLAGS[$token] ?? 0;
                $mask |= self::W61_PC_ARCHITECTURES[$token] ?? 0;
                $tokenLength = \strlen($token);
                if ($token[0] === 'v' && $tokenLength >= 2 && $tokenLength <= 5 && strspn($token, '0123456789', 1) === $tokenLength - 1) {
                    $flags |= 512;
                    $version ??= (int) substr($token, 1);
                }
                if ($tokenLength >= 6 && $tokenLength <= 7 && str_starts_with($token, 'multi') && strspn($token, '0123456789', 5) === $tokenLength - 5) {
                    $flags |= 1024;
                }
                if ($tokenLength === 4 && strspn($token, '0123456789') === 4 && ($token[0].$token[1] === '19' || $token[0].$token[1] === '20')) {
                    $flags |= 16384;
                }
            }
            $architecture = ($mask & 4) !== 0 ? 4 : (($mask & 3) === 3 ? 3 : (($mask & 2) !== 0 ? 2 : $mask & 1));
            $platform = match (true) {
                ($flags & 8) !== 0 => 4,
                ($flags & 16) !== 0 => 5,
                ($flags & 4) !== 0 => 2,
                ($flags & 128) !== 0 => 3,
                ($flags & 64) !== 0 || ($mask & 3) !== 0 => 1,
                default => 0,
            };
            [$rule, $confidence] = match (true) {
                ($flags & (2048 | 8192)) !== 0 => [0, 0],
                ($flags & 8) !== 0 => [1, 900],
                ($flags & 16) !== 0 => [2, 900],
                ($flags & 4) !== 0 => [3, 850],
                ($flags & 32) !== 0 => [4, 850],
                ($flags & 2) !== 0 => [5, 800],
                ($flags & (1 | 64 | 128 | 256 | 512 | 4096 | 32768)) !== 0 => [6, ($flags & 4096) !== 0 ? 800 : 750],
                default => [0, 0],
            };
            $rules[$rule]++;
            $platforms[$platform]++;
            $architectures[$architecture]++;
            for ($i = 0; $i < 16; $i++) {
                $flagCounts[$i] += $flags >> $i & 1;
            }
            $confidenceSum += $confidence;
            $maxVersion = max($maxVersion, $version ?? 0);
            if ($version !== null) {
                $versions[$version] = true;
            }
            $out[] = $rule | $platform << 3 | $architecture << 6 | $flags << 9 | ($version ?? 0) << 25 | $confidence << 39
                | min(\count($tokens), 63) << 49 | min(\strlen($name), 255) << 55;
        }
        array_push($out, ...$rules, ...$platforms, ...$architectures, ...$flagCounts);
        array_push($out, $confidenceSum, $maxVersion, \count($versions));

        return $out;
    }

    /**
     * Wave 61 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w611', self::W61_PC_CATEGORIZER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w61RustFfiState('ffi');
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
                $handle = self::w61RustFfiState('ffi');
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
     * Calls the Wave 61.1 PcCategorizer export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w61Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w61RustFfiState('ffi');
            $out = self::w61RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w61RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W61_PC_CATEGORIZER_FFI_CDEF.)
            $written = $ffi->nntmux_w611_pc_token_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
