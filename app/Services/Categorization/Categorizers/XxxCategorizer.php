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
 * Categorizer for Adult/XXX content.
 */
class XxxCategorizer extends AbstractCategorizer
{
    /**
     * Wave 43.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersMusicPcTvXxxPipeW43ParityTest.php measures at least 3.7x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W433 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W433_RUST_FFI_DEFAULT = true;

    public const string W433_FFI_CDEF = <<<'C'
        ssize_t nntmux_w433_xxx_format_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * XXX format marker substrings, in match order. The first class with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W433_XXX_FORMAT_MARKERS = [
        'onlyfans' => ['onlyfans', 'fansly', 'manyvids'],
        'vr' => ['vr180', 'vr360', 'oculus', 'gearvr', 'psvr', 'sidebyside'],
        'uhd' => ['2160p', '4k', 'uhd'],
        'hd' => ['1080p', '720p'],
        'imageset' => ['imageset', 'image.set', 'pics', 'photos'],
        'dvd' => ['dvdrip', 'dvd5', 'dvd9', 'dvdr'],
        'sd' => ['480p', 'xvid', '.wmv', 'mp4'],
    ];

    /** @var list<string> */
    public const array W433_PACK_MARKERS = ['pack', 'siterip'];

    /** Exactly 10 0x1F-separated decimals of 1-19 digits. */
    private const string W433_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){9}\d{1,19}\z/';

    private static ?bool $w433RustFfiOverride = null;

    private static FFI|false|null $w433RustFfiHandle = null;

    protected int $priority = 10; // High priority - should run early

    protected const JACQUIE_ET_MICHEL = 'Jacquie[._ -]?Et[._ -]?Michel(?:[._ -]?(?:TV|Elite))?';

    // Known adult studios/sites - comprehensive list
    protected const KNOWN_STUDIOS = 'Brazzers|NaughtyAmerica|RealityKings|Bangbros|BangBros18|TeenFidelity|PornPros|SexArt|WowGirls|Vixen|Blacked|Tushy|Deeper|Bellesa|Defloration|MetArt|MetArtX|TheLifeErotic|VivThomas|JoyMii|Nubiles|NubileFilms|Anilos|FamilyStrokes|X-Art|Babes|Twistys|WetAndPuffy|WowPorn|MomsTeachSex|Mofos|BangBus|Passion-HD|EvilAngel|DorcelClub|Private|Hustler|CherryPimps|PureTaboo|Lady[._ -]?Lyne|TeamSkeet|GirlsWay|SweetSinner|NewSensations|Digital[._ -]?Playground|Wicked|Penthouse|Playboy|Kink|HardX|ArchAngel|JulesJordan|ManuelFerrara|LesbianX|AllAnal|DarkX|Elegant[._ -]?Angel|ZeroTolerance|Score|PornFidelity|Kelly[._ -]?Madison|DDF[._ -]?Network|21Sextury|21Naturals|Colette|SexMex|Bang|SpankBang|PornWorld|LegalPorno|AnalVids|GonzoXXX|RoccoSiffredi|Fake[._ -]?Hub|FakeAgent|FakeTaxi|FakeHostel|PublicAgent|StrandedTeens|Property[._ -]?Sex|Dane[._ -]?Jones|Lets[._ -]?Doe[._ -]?It|Office[._ -]?Obsession|SexyHub|Massage[._ -]?Rooms|Fitness[._ -]?Rooms|Female[._ -]?Agent|MissaX|All[._ -]?Girl[._ -]?Massage|Fantasy[._ -]?Massage|Nurumassage|Soapymassage|Reality[._ -]?Junkies|Perv[._ -]?Mom|Bad[._ -]?Milfs|Milf[._ -]?Body|Step[._ -]?Siblings|Sis[._ -]?Loves[._ -]?Me|Brother[._ -]??Crush|Dad[._ -]?Crush|Mom[._ -]?Knows[._ -]?Best|Bratty[._ -]?Sis|My[._ -]?Family[._ -]?Pies|Family[._ -]?Therapy|Nubiles[._ -]?Porn|Step[._ -]?Fantasy|Caught[._ -]?Fapping|She[._ -]?Will[._ -]?Cheat|Dirty[._ -]?Wives[._ -]?Club|Big[._ -]?Tits[._ -]?Round[._ -]?Asses|Ass[._ -]?Parade|Monsters[._ -]?Of[._ -]?Cock|Brown[._ -]?Bunnies|Teens[._ -]?Love[._ -]?Huge[._ -]?Cocks|Ass[._ -]?Masterpiece|Bang[._ -]?Casting|Holed|Tiny4K|Lubed|POVD|Exotic4K|CastingCouch[._ -]?X|Casting[._ -]?Couch|Creampie[._ -]?Angels|Digital[._ -]?Desire|Femjoy|Hegre|Joymii|Met[._ -]?Art|MPL[._ -]?Studios|Rylsky[._ -]?Art|Showy[._ -]?Beauty|Stunning18|Photodromm|Watch4Beauty|Wow[._ -]?Girls|Yonitale|Mommys[._ -]?Boy|AllOver30|MyFirst|10musume|Caribbeancom|Heyzo|Pacopacomama|1Pondo|TokyoHot|Mommy[._ -]?Blows[._ -]?Best|Milfs[._ -]?Like[._ -]?It[._ -]?Big|Mommy[._ -]?Got[._ -]?Boobs|My[._ -]?Friends[._ -]?Hot[._ -]?Mom|Seduced[._ -]?By[._ -]?A[._ -]?Cougar|Hot[._ -]?Mom[._ -]?Next[._ -]?Door|'.self::JACQUIE_ET_MICHEL;

    // Adult keywords
    protected const ADULT_KEYWORDS = 'Anal|Ass|BBW|BDSM|Blow|Boob|Bukkake|Casting|Couch|Cock|Compilation|Creampie|Cum|Dick|Dildo|Facial|Fetish|Fuck|Gang|Hardcore|Homemade|Horny|Interracial|Lesbian|MILF|Masturbat|Nympho|Oral|Orgasm|Penetrat|Pornstar|POV|Pussy|Riding|Seduct|Sex|Shaved|Slut|Squirt|Suck|Swallow|Threesome|Tits|Titty|Toy|Virgin|Whore';

    // VR sites
    protected const VR_SITES = 'SexBabesVR|LittleCapriceVR|VRoomed|VRMagic|TonightsGirlfriend|NaughtyAmericaVR|BaDoinkVR|WankzVR|VRBangers|StripzVR|RealJamVR|TmwVRnet|MilfVR|KinkVR|CzechVR(?:Fetish)?|HoloGirlsVR|WetVR|XSinsVR|VRCosplayX|BIBIVR|SLR|SexLikeReal|CutiesVR|18VR|PornCornVR|DarkRoomVR|JackAndJillVR|PasstroughVR|JUVR|KIWVR|KAVR|NKKVR|VRSPy|BadoinkVR|SinsVR|FuckPassVR|CRVR|CatalinaCruzVR|StockingsVR|VirtualRealPorn|VRHush|VRConk|SwallowBay';

    // Generic VR adult site pattern (e.g. StockingsVR.com, SomeSiteVR.net)
    protected const GENERIC_VR_SITE = '\b[A-Z][A-Za-z0-9]{2,}VR(?:\.com|\.net|\.tv)?\b';

    // VR headset / platform tokens
    protected const VR_DEVICES = 'GearVR|Oculus|Quest[123]?|PSVR|Vive|Index|Pimax';

    /**
     * Native (Rust FFI) bindings. Rule ids returned by the library index {@see self::NATIVE_RULES}.
     */
    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_xxx(const char *name, size_t name_len, const char *poster, size_t poster_len, uint32_t flags);
CDEF;

    /** @var array<int, string> */
    private const array NATIVE_RULES = [
        0 => 'onlyfans',
        1 => 'vr_site',
        2 => 'vr_site_generic',
        3 => 'vr_device',
        4 => 'vr_180_360',
        5 => 'uhd_group',
        6 => 'uhd',
        7 => 'clip_hd_studio',
        8 => 'clip_hd_studio_date',
        9 => 'studio_date',
        10 => 'clip_hd_date_4digit',
        11 => 'clip_hd_date',
        12 => 'clip_hd_jav_date',
        13 => 'jav_date',
        14 => 'clip_hd_studio_xxx',
        15 => 'clip_hd_xxx',
        16 => 'pack',
        17 => 'clip_sd_poster',
        18 => 'clip_sd',
        19 => 'sd',
        20 => 'webdl',
        21 => 'x264',
        22 => 'xvid',
        23 => 'imageset',
        24 => 'wmv',
        25 => 'dvd',
        26 => 'other',
    ];

    /** Resolved library handle: null until first use, false when the library is unavailable. */
    private static FFI|false|null $nativeFfi = null;

    private static ?CategorizationResult $nativeNoMatch = null;

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
            $packed = $ffi->nntmux_categorize_xxx($context->releaseName, \strlen($context->releaseName), $context->poster, \strlen($context->poster), ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0));
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? $packed : null;
    }

    public function getName(): string
    {
        return 'XXX';
    }

    public function categorize(ReleaseContext $context): CategorizationResult
    {
        $packed = $this->nativeCall($context);
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

        // Check if it looks like adult content
        if (! $context->hasOnlyFansMarker() && ! $this->looksLikeXxx($name)) {
            return $this->noMatch();
        }

        // Try specific XXX subcategories in order of specificity
        if ($result = $this->checkOnlyFans($context)) {
            return $result;
        }

        if ($result = $this->checkVR($name)) {
            return $result;
        }

        if ($result = $this->checkUHD($name)) {
            return $result;
        }

        if ($result = $this->checkClipHD($name)) {
            return $result;
        }

        if ($result = $this->checkPack($name)) {
            return $result;
        }

        if ($result = $this->checkClipSD($name, $context->poster)) {
            return $result;
        }

        if ($result = $this->checkSD($name)) {
            return $result;
        }

        if ($context->catWebDL && ($result = $this->checkWebDL($name))) {
            return $result;
        }

        if ($result = $this->checkX264($name)) {
            return $result;
        }

        if ($result = $this->checkXvid($name)) {
            return $result;
        }

        if ($result = $this->checkImageset($name)) {
            return $result;
        }

        if ($result = $this->checkWMV($name)) {
            return $result;
        }

        if ($result = $this->checkDVD($name)) {
            return $result;
        }

        if ($result = $this->checkOther($name)) {
            return $result;
        }

        return $this->noMatch();
    }

    /**
     * Check if release name looks like adult content.
     */
    protected function looksLikeXxx(string $name): bool
    {
        // Check for XXX marker
        if (preg_match('/\bXXX\b/i', $name)) {
            return true;
        }

        // Check for known studios/sites
        if (preg_match('/\b('.self::KNOWN_STUDIOS.')\b/i', $name, $matches)) {
            $matchedStudio = $matches[1];

            // If the studio name appears at the start, check if it's actually a movie release pattern
            // Movie pattern: Title.Year.Resolution.Source (e.g., Wicked.2024.1080p.WEB-DL)
            // Adult pattern: Studio.Date.Performer (e.g., Wicked.24.01.15.Performer.Name)
            if (preg_match('/^'.preg_quote($matchedStudio, '/').'[.\-_ ]/i', $name)) {
                // Check if this looks like a movie release: Studio.Year.Resolution or Studio.Title.Year
                // Movie releases typically have: 4-digit year followed by resolution/source markers
                if (preg_match('/^'.preg_quote($matchedStudio, '/').'[.\-_ ](?:[A-Za-z]+[.\-_ ])*(?:19|20)\d{2}[.\-_ ](?:720p|1080p|2160p|4K|UHD)/i', $name) &&
                    preg_match('/\b(WEB-?DL|WEBRip|BluRay|BDRip|HDRip|HDTV|DVDRip|Remux|PROPER|REPACK|HC|KORSUB)\b/i', $name) &&
                    ! preg_match('/\b('.self::ADULT_KEYWORDS.')\b/i', $name)) {
                    // This looks like a movie release (Title.Year.Resolution.Source), not adult content
                    return false;
                }

                // Adult studio releases typically use date patterns: Studio.YY.MM.DD or Studio.YYYY.MM.DD
                if (preg_match('/^'.preg_quote($matchedStudio, '/').'[.\-_ ](19|20)?\d{2}[.\-_ ]\d{2}[.\-_ ]\d{2}[.\-_ ]/i', $name)) {
                    return true;
                }
            }

            return true;
        }

        // Check for known VR sites
        if (preg_match('/\b('.self::VR_SITES.')\b/i', $name)) {
            return true;
        }

        // Generic VR adult site (e.g. StockingsVR.com)
        if (preg_match('/'.self::GENERIC_VR_SITE.'/i', $name)) {
            return true;
        }

        // VR device combined with HD/UHD video markers
        if (preg_match('/\b(?:'.self::VR_DEVICES.')\b/i', $name) &&
            preg_match('/\b(720p|1080p|2160p|4K|VR180|VR360)\b/i', $name)) {
            return true;
        }

        // Check for adult content indicators combined with video markers
        if (preg_match('/\b('.self::ADULT_KEYWORDS.')\b/i', $name) &&
            preg_match('/\b(720p|1080p|2160p|4k|mp4|mkv|avi|wmv)\b/i', $name)) {
            return true;
        }

        // Check for JAV/AV marker (common in Japanese adult releases)
        if (preg_match('/\b(AV|JAV)\b/', $name) && preg_match('/\b('.self::KNOWN_STUDIOS.')\b/i', $name)) {
            return true;
        }

        // Site with date pattern: sitename.YYYY.MM.DD or sitename.YY.MM.DD
        // This pattern is very common for adult sites but rare for regular content
        if (preg_match('/^[A-Za-z]+[.\-_ ](19|20)?\d{2}[.\-_ ]\d{2}[.\-_ ]\d{2}[.\-_ ][A-Za-z]/i', $name)) {
            // Check it's not a TV daily show by checking for adult keywords or specific patterns
            if (preg_match('/\b('.self::ADULT_KEYWORDS.')\b/i', $name)) {
                return true;
            }
            // Check for performer name patterns (firstname.lastname) after the date
            if (preg_match('/\d{2}[.\-_ ]([a-z]+)[.\-_ ]([a-z]+)[.\-_ ]/i', $name)) {
                // Has a "firstname.lastname" pattern after date - likely adult
                // But exclude obvious TV patterns
                if (! preg_match('/\b(S\d{1,2}E\d{1,2}|Episode|Season|HDTV|PDTV)\b/i', $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function checkOnlyFans(ReleaseContext $context): ?CategorizationResult
    {
        $name = str_replace('_', ' ', $context->releaseName);

        // Skip photo packs unless there's a video hint
        if (preg_match('/\b(photo(?:set)?s?|image(?:set)?s?|pics?|wallpapers?|collections?|packs?)\b/i', $name) &&
            ! preg_match('/\b(mp4|mkv|mov|wmv|avi|webm|h\.?264|x264|h\.?265|x265)\b/i', $name)) {
            return null;
        }

        if ($context->hasOnlyFansMarker()) {
            return $this->matched(Category::XXX_ONLYFANS, 0.95, 'onlyfans');
        }

        return null;
    }

    protected function checkVR(string $name): ?CategorizationResult
    {
        $hasKnownVRSite = (bool) preg_match('/\b('.self::VR_SITES.')\b/i', $name);
        $hasGenericVRSite = (bool) preg_match('/'.self::GENERIC_VR_SITE.'/i', $name);
        $hasVRSite = $hasKnownVRSite || $hasGenericVRSite;
        $hasVRDevice = (bool) preg_match('/\b(?:'.self::VR_DEVICES.')\b/i', $name);
        $hasVR180360 = (bool) preg_match('/\bVR(?:180|360)\b/i', $name);

        if (! $hasVRSite && ! $hasVRDevice && ! $hasVR180360 &&
            stripos($name, 'vr') === false && stripos($name, 'oculus') === false && stripos($name, 'quest') === false) {
            return null;
        }

        // Require either a VR site token, explicit VR180/VR360, or VR device
        if (! $hasVR180360 && ! $hasVRSite && ! $hasVRDevice) {
            return null;
        }

        // VR pattern matching - includes VR devices and generic VR sites
        $vrPattern = '/\b('.self::VR_SITES.')\b|'.self::GENERIC_VR_SITE.'|\bVR(?:180|360)\b|\b(?:5K|6K|7K|8K)\b.*\bVR\b|\b(?:'.self::VR_DEVICES.')\b/i';

        if (preg_match($vrPattern, $name)) {
            // Known VR sites are definitively adult content
            if ($hasKnownVRSite) {
                return $this->matched(Category::XXX_VR, 0.95, 'vr_site');
            }

            // Generic VR site pattern (e.g. StockingsVR.com)
            if ($hasGenericVRSite) {
                return $this->matched(Category::XXX_VR, 0.9, 'vr_site_generic');
            }

            // VR device with adult studio, keywords, or XXX marker
            if ($hasVRDevice && (
                preg_match('/\bXXX\b/i', $name) ||
                preg_match('/\b('.self::ADULT_KEYWORDS.')\b/i', $name) ||
                preg_match('/\b('.self::KNOWN_STUDIOS.')\b/i', $name)
            )) {
                return $this->matched(Category::XXX_VR, 0.9, 'vr_device');
            }

            // VR180/VR360 with adult markers
            if ($hasVR180360 && (
                preg_match('/\bXXX\b/i', $name) ||
                preg_match('/\b('.self::ADULT_KEYWORDS.')\b/i', $name) ||
                preg_match('/\b('.self::VR_SITES.')\b/i', $name)
            )) {
                return $this->matched(Category::XXX_VR, 0.9, 'vr_180_360');
            }
        }

        return null;
    }

    protected function checkUHD(string $name): ?CategorizationResult
    {
        if (! preg_match('/\b(2160p|4k|UHD|Ultra[._ -]?HD)\b/i', $name)) {
            return null;
        }

        // Check for adult markers
        $hasAdultMarker = preg_match('/\bXXX\b/i', $name) ||
                          preg_match('/\b('.self::KNOWN_STUDIOS.')\b/i', strtolower($name)) ||
                          preg_match('/\b(Hardcore|Porn|Sex|Anal|Creampie|MILF|Lesbian|Teen|Interracial)\b/i', $name);

        if (! $hasAdultMarker) {
            return null;
        }

        // Known UHD release groups
        if (preg_match('/XXX.+2160p[\w\-.]+M[PO][V4]-(KTR|GUSH|FaiLED|SEXORS|hUSHhUSH|YAPG|WRB|NBQ|FETiSH)/i', $name)) {
            return $this->matched(Category::XXX_UHD, 0.95, 'uhd_group');
        }

        return $this->matched(Category::XXX_UHD, 0.9, 'uhd');
    }

    protected function checkClipHD(string $name): ?CategorizationResult
    {
        // Exclude packs and collections
        if (preg_match('/^(Complete|Pack|Collection|Anthology|Siterip|SiteRip)\b/i', $name)) {
            return null;
        }

        // Exclude TV shows
        if (preg_match('/\b(S\d{1,2}E\d{1,2}|S\d{1,2}|Season\s\d{1,2})\b/i', $name)) {
            return null;
        }

        // Check for HD resolution
        $hasHD = preg_match('/\b(720p|1080p|2160p|HD|4K)\b/i', $name);

        // Expand separators only for the explicit new site; shared studio words can be movie titles.
        if (preg_match('/^(?:(?:'.self::KNOWN_STUDIOS.')\.|'.self::JACQUIE_ET_MICHEL.'[.\-_ ])([A-Z][a-z]+).*?(720p|1080p|2160p|HD|4K)/i', $name)) {
            return $this->matched(Category::XXX_CLIPHD, 0.9, 'clip_hd_studio');
        }

        // Known studio with date pattern: site.YYYY.MM.DD or site.YY.MM.DD
        if (preg_match('/^('.self::KNOWN_STUDIOS.')[.\-_ ](19|20)?\d{2}[.\-_ ]\d{2}[.\-_ ]\d{2}/i', $name)) {
            if ($hasHD) {
                return $this->matched(Category::XXX_CLIPHD, 0.95, 'clip_hd_studio_date');
            }

            // Even without HD marker, if it's a known studio with date pattern, likely XXX
            return $this->matched(Category::XXX_X264, 0.85, 'studio_date');
        }

        // Date pattern with 4-digit year: site.YYYY.MM.DD.performer.title.1080p
        if (preg_match('/^([A-Z][a-zA-Z0-9]+)[.\-_ ](19|20)\d{2}[.\-_ ]\d{2}[.\-_ ]\d{2}[.\-_ ]/i', $name) &&
            ! preg_match('/\b(S\d{2}E\d{2}|Documentary|Series)\b/i', $name)) {
            // Check if it has adult keywords or HD resolution
            if ($hasHD || preg_match('/\b('.self::ADULT_KEYWORDS.')\b/i', $name)) {
                return $this->matched(Category::XXX_CLIPHD, 0.85, 'clip_hd_date_4digit');
            }
        }

        // Date pattern with 2-digit year: site.YY.MM.DD.performer.title.1080p
        if (preg_match('/^([A-Z][a-zA-Z0-9]+)\.(\d{2})\.(\d{2})\.(\d{2})\..*?(720p|1080p|2160p|HD|4K)/i', $name) &&
            ! preg_match('/\b(S\d{2}E\d{2}|Documentary|Series)\b/i', $name)) {
            return $this->matched(Category::XXX_CLIPHD, 0.85, 'clip_hd_date');
        }

        // JAV compact date pattern: site.YYMMDD (e.g., 10musume.121025)
        if (preg_match('/^('.self::KNOWN_STUDIOS.')[.\-_ ](\d{6})/i', $name)) {
            if ($hasHD) {
                return $this->matched(Category::XXX_CLIPHD, 0.9, 'clip_hd_jav_date');
            }

            return $this->matched(Category::XXX_X264, 0.85, 'jav_date');
        }

        // Known studio with XXX marker and HD resolution
        if (preg_match('/^('.self::KNOWN_STUDIOS.')[.\-_ ].*\bXXX\b.*?(720p|1080p|2160p|HD|4K)/i', $name)) {
            return $this->matched(Category::XXX_CLIPHD, 0.9, 'clip_hd_studio_xxx');
        }

        // XXX with HD resolution
        if (preg_match('/\b(XXX|MILF|Anal|Sex|Porn)[._ -]+(720p|1080p|2160p|HD|4K)\b/i', $name) ||
            preg_match('/\b(720p|1080p|2160p|HD|4K)[._ -]+(XXX|MILF|Anal|Sex|Porn)\b/i', $name)) {
            return $this->matched(Category::XXX_CLIPHD, 0.8, 'clip_hd_xxx');
        }

        return null;
    }

    protected function checkPack(string $name): ?CategorizationResult
    {
        if (preg_match('/[ .]PACK[ .]/i', $name)) {
            return $this->matched(Category::XXX_PACK, 0.85, 'pack');
        }

        return null;
    }

    protected function checkClipSD(string $name, string $poster): ?CategorizationResult
    {
        if (preg_match('/anon@y[.]com|@md-hobbys[.]com|oz@lot[.]com/i', $poster)) {
            return $this->matched(Category::XXX_CLIPSD, 0.85, 'clip_sd_poster');
        }

        if (preg_match('/(iPT\sTeam|KLEENEX)/i', $name) || stripos($name, 'SDPORN') !== false) {
            return $this->matched(Category::XXX_CLIPSD, 0.85, 'clip_sd');
        }

        return null;
    }

    protected function checkSD(string $name): ?CategorizationResult
    {
        if (preg_match('/SDX264XXX|XXX\.HR\./i', $name)) {
            return $this->matched(Category::XXX_SD, 0.85, 'sd');
        }

        return null;
    }

    protected function checkWebDL(string $name): ?CategorizationResult
    {
        // Exclude TV shows
        if (preg_match('/\b(S\d{1,2}E\d{1,2})\b/i', $name)) {
            return null;
        }

        if (preg_match('/web[._ -]dl|web-?rip/i', $name) &&
            (preg_match('/\b('.self::ADULT_KEYWORDS.')\b/i', $name) ||
             preg_match('/\b('.self::KNOWN_STUDIOS.')\b/i', $name) ||
             preg_match('/\b(XXX|Porn|Adult|JAV|Hentai)\b/i', $name))) {
            return $this->matched(Category::XXX_WEBDL, 0.85, 'webdl');
        }

        return null;
    }

    protected function checkX264(string $name): ?CategorizationResult
    {
        // Exclude HEVC/x265
        if (preg_match('/\b(x265|hevc)\b/i', $name)) {
            return null;
        }

        // Require H.264/x264/AVC
        if (! preg_match('/\b((x|h)[\.\-_ ]?264|AVC)\b/i', $name)) {
            return null;
        }

        // Reject obvious non-targets
        if (preg_match('/\bwmv\b|S\d{1,2}E\d{1,2}|\d+x\d+/i', $name)) {
            return null;
        }

        // Check for adult content
        $adultPattern = '/\bXXX\b|a\.b\.erotica|BangBros|'.self::JACQUIE_ET_MICHEL.'|Cum|Defloration|Err?oticax?|JoyMii|MetArt|Nubiles|Porn|SexArt|Tushy|Vixen|JAV|Brazzers|NaughtyAmerica|RealityKings/i';

        if (preg_match($adultPattern, $name)) {
            return $this->matched(Category::XXX_X264, 0.85, 'x264');
        }

        return null;
    }

    protected function checkXvid(string $name): ?CategorizationResult
    {
        if (preg_match('/(b[dr]|dvd)rip|detoxication|divx|nympho|pornolation|swe6|tesoro|xvid/i', $name)) {
            return $this->matched(Category::XXX_XVID, 0.8, 'xvid');
        }

        return null;
    }

    protected function checkImageset(string $name): ?CategorizationResult
    {
        if (preg_match('/IMAGESET|PICTURESET|ABPEA/i', $name)) {
            return $this->matched(Category::XXX_IMAGESET, 0.9, 'imageset');
        }

        return null;
    }

    protected function checkWMV(string $name): ?CategorizationResult
    {
        // Exclude modern formats
        if (preg_match('/\b(720p|1080p|2160p|x264|x265|h264|h265|hevc|XviD|MP4-|\.mp4)[._ -]/i', $name)) {
            return null;
        }

        if (preg_match('/\b(WMV|Windows\s?Media\s?Video)\b|\.wmv$|[._ -]wmv[._ -]/i', $name)) {
            return $this->matched(Category::XXX_WMV, 0.8, 'wmv');
        }

        return null;
    }

    protected function checkDVD(string $name): ?CategorizationResult
    {
        if (preg_match('/dvdr[^i]|dvd[59]/i', $name)) {
            return $this->matched(Category::XXX_DVD, 0.85, 'dvd');
        }

        return null;
    }

    protected function checkOther(string $name): ?CategorizationResult
    {
        if (preg_match('/[._ -]Brazzers|Creampie|[._ -]JAV[._ -]|North\.Pole|She[._ -]?Male|Transsexual|OLDER ANGELS/i', $name)) {
            return $this->matched(Category::XXX_OTHER, 0.7, 'other');
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
     * XXX format summary for a batch of release names. Each lowercased name adds to its first
     * W433_XXX_FORMAT_MARKERS class, else to unknown, and separately to pack when it holds any
     * W433_PACK_MARKERS entry. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, onlyfans: int, vr: int, uhd: int, hd: int, imageset: int, dvd: int, sd: int, unknown: int, pack: int}
     */
    public static function summarizeXxxFormatBatch(array $releaseNames): array
    {
        return (self::usesW433RustFfi() ? self::summarizeXxxFormatBatchNative($releaseNames) : null)
            ?? self::summarizeXxxFormatBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, onlyfans: int, vr: int, uhd: int, hd: int, imageset: int, dvd: int, sd: int, unknown: int, pack: int}
     */
    public static function summarizeXxxFormatBatchPhp(array $releaseNames): array
    {
        $onlyfans = 0;
        $vr = 0;
        $uhd = 0;
        $hd = 0;
        $imageset = 0;
        $dvd = 0;
        $sd = 0;
        $unknown = 0;
        $packCount = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W433_PACK_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $packCount++;

                    break;
                }
            }
            foreach (self::W433_XXX_FORMAT_MARKERS['onlyfans'] as $needle) {
                if (str_contains($name, $needle)) {
                    $onlyfans++;

                    continue 2;
                }
            }
            foreach (self::W433_XXX_FORMAT_MARKERS['vr'] as $needle) {
                if (str_contains($name, $needle)) {
                    $vr++;

                    continue 2;
                }
            }
            foreach (self::W433_XXX_FORMAT_MARKERS['uhd'] as $needle) {
                if (str_contains($name, $needle)) {
                    $uhd++;

                    continue 2;
                }
            }
            foreach (self::W433_XXX_FORMAT_MARKERS['hd'] as $needle) {
                if (str_contains($name, $needle)) {
                    $hd++;

                    continue 2;
                }
            }
            foreach (self::W433_XXX_FORMAT_MARKERS['imageset'] as $needle) {
                if (str_contains($name, $needle)) {
                    $imageset++;

                    continue 2;
                }
            }
            foreach (self::W433_XXX_FORMAT_MARKERS['dvd'] as $needle) {
                if (str_contains($name, $needle)) {
                    $dvd++;

                    continue 2;
                }
            }
            foreach (self::W433_XXX_FORMAT_MARKERS['sd'] as $needle) {
                if (str_contains($name, $needle)) {
                    $sd++;

                    continue 2;
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'onlyfans' => $onlyfans,
            'vr' => $vr,
            'uhd' => $uhd,
            'hd' => $hd,
            'imageset' => $imageset,
            'dvd' => $dvd,
            'sd' => $sd,
            'unknown' => $unknown,
            'pack' => $packCount,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, onlyfans: int, vr: int, uhd: int, hd: int, imageset: int, dvd: int, sd: int, unknown: int, pack: int}|null
     */
    public static function summarizeXxxFormatBatchNative(array $releaseNames): ?array
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
            'onlyfans' => (int) $fields[1],
            'vr' => (int) $fields[2],
            'uhd' => (int) $fields[3],
            'hd' => (int) $fields[4],
            'imageset' => (int) $fields[5],
            'dvd' => (int) $fields[6],
            'sd' => (int) $fields[7],
            'unknown' => (int) $fields[8],
            'pack' => (int) $fields[9],
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
            $written = $ffi->nntmux_w433_xxx_format_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w433_xxx_format_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
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

    // ---- Wave 61.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 9.10x/6.78x, uncommon 9.57x/7.55x); see RustCategorizersTvXxxConsoleGamesServiceTitleParserW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_XXX_CATEGORIZER_FFI_CDEF = <<<'C'
        intptr_t nntmux_w612_xxx_categorize_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W61_XXX_CATEGORIZER_TAIL = 35;

    /** Category id per w61XxxCategorizePlanBatch() category index (0: not adult). */
    public const array W61_XXX_CATEGORIES = [0, Category::XXX_ONLYFANS, Category::XXX_VR, Category::XXX_IMAGESET, Category::XXX_PACK, Category::XXX_UHD,
        Category::XXX_CLIPSD, Category::XXX_SD, Category::XXX_WMV, Category::XXX_DVD, Category::XXX_XVID, Category::XXX_CLIPHD, Category::XXX_X264,
        Category::XXX_OTHER];

    /** Case-insensitive poster substrings of the SD clip uploaders. */
    private const array W61_XXX_POSTERS = ['anon@y.com', 'md-hobbys.com', 'oz@lot.com'];

    /** Lowercase token => `res | codec << 2 | container << 6 | flags << 10`. */
    private const array W61_XXX_TOKENS = [
        '2160p' => 3, '4k' => 3, 'uhd' => 3, '1080p' => 2, '1080i' => 2, '720p' => 2, 'hd' => 2, '480p' => 1, '360p' => 1, '576p' => 1, 'sd' => 1,
        'x264' => 1 << 2, 'h264' => 1 << 2, 'avc' => 1 << 2, 'x265' => 2 << 2, 'h265' => 2 << 2, 'hevc' => 2 << 2,
        'xvid' => 4 << 2, 'divx' => 4 << 2, 'dvdrip' => 4 << 2, 'bdrip' => 4 << 2, 'brrip' => 4 << 2, 'wmv' => 8 << 2,
        'mp4' => 1 << 6, 'mkv' => 2 << 6, 'avi' => 4 << 6, 'mov' => 8 << 6,
        'xxx' => 1 << 10,
        'brazzers' => 1 << 11, 'naughtyamerica' => 1 << 11, 'realitykings' => 1 << 11, 'bangbros' => 1 << 11, 'vixen' => 1 << 11, 'blacked' => 1 << 11,
        'tushy' => 1 << 11, 'deeper' => 1 << 11, 'nubiles' => 1 << 11, 'sexart' => 1 << 11, 'metart' => 1 << 11, 'metartx' => 1 << 11,
        'evilangel' => 1 << 11, 'private' => 1 << 11, 'hustler' => 1 << 11, 'penthouse' => 1 << 11, 'playboy' => 1 << 11, 'kink' => 1 << 11,
        'teamskeet' => 1 << 11, 'mofos' => 1 << 11, 'twistys' => 1 << 11, 'babes' => 1 << 11, 'digitalplayground' => 1 << 11, 'wicked' => 1 << 11,
        'julesjordan' => 1 << 11, 'pornpros' => 1 << 11, 'familystrokes' => 1 << 11, 'tiny4k' => 1 << 11, 'povd' => 1 << 11, 'holed' => 1 << 11,
        'lubed' => 1 << 11, 'fakehub' => 1 << 11, 'faketaxi' => 1 << 11, 'publicagent' => 1 << 11, 'hegre' => 1 << 11, 'femjoy' => 1 << 11,
        'wowgirls' => 1 << 11, 'legalporno' => 1 << 11, 'dorcelclub' => 1 << 11, 'newsensations' => 1 << 11,
        'anal' => 1 << 12, 'milf' => 1 << 12, 'porn' => 1 << 12, 'sex' => 1 << 12, 'hardcore' => 1 << 12, 'lesbian' => 1 << 12, 'creampie' => 1 << 12,
        'pov' => 1 << 12, 'teen' => 1 << 12, 'interracial' => 1 << 12, 'bbw' => 1 << 12, 'bdsm' => 1 << 12, 'fetish' => 1 << 12, 'blowjob' => 1 << 12,
        'threesome' => 1 << 12, 'pornstar' => 1 << 12, 'squirt' => 1 << 12, 'facial' => 1 << 12, 'hentai' => 1 << 12, 'jav' => 1 << 12,
        'adult' => 1 << 12,
        'onlyfans' => 1 << 13, 'fansly' => 1 << 13, 'manyvids' => 1 << 13, 'clips4sale' => 1 << 13, 'justforfans' => 1 << 13,
        'imageset' => 1 << 14, 'imagesets' => 1 << 14, 'pictureset' => 1 << 14, 'imgset' => 1 << 14, 'photoset' => 1 << 14, 'photosets' => 1 << 14,
        'pics' => 1 << 14, 'photos' => 1 << 14, 'images' => 1 << 14, 'wallpapers' => 1 << 14, 'abpea' => 1 << 14,
        'pack' => 1 << 15, 'siterip' => 1 << 15, 'collection' => 1 << 15, 'complete' => 1 << 15, 'megapack' => 1 << 15,
        'dvdr' => 1 << 16, 'dvd5' => 1 << 16, 'dvd9' => 1 << 16, 'dvd' => 1 << 16,
        'vr' => 1 << 17, 'vr180' => 1 << 17, 'vr360' => 1 << 17, 'oculus' => 1 << 17, 'gearvr' => 1 << 17, 'quest' => 1 << 17, 'quest2' => 1 << 17,
        'quest3' => 1 << 17, 'psvr' => 1 << 17, 'vive' => 1 << 17,
        'web' => 1 << 18, 'webdl' => 1 << 18, 'webrip' => 1 << 18,
        'sdx264xxx' => 1 << 21, 'sdporn' => 1 << 21,
    ];

    /**
     * Wave 61 routing, binding and output buffer, kept in function-local statics (no static
     * properties). Operations: null reads the routing override, 'set' replaces it (returning the
     * previous one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]`
     * with room for at least $val words (null without the library).
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w61', self::W61_XXX_CATEGORIZER_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w61RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $enabled = self::w61RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w61XxxCategorizePlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
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
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61XxxCategorizePlanBatch(array $rows): array
    {
        return self::usesW61RustFfi() ? self::w61XxxCategorizePlanBatchNative($rows) : self::w61XxxCategorizePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61XxxCategorizePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W61_XXX_CATEGORIZER_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w61RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w61XxxCategorizePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W61_XXX_CATEGORIZER_FFI_CDEF.)
            $written = $ffi->nntmux_w612_xxx_categorize_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w61XxxCategorizePlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w61Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** $min to $max ASCII digits as an integer, otherwise null. */
    private static function w61Digits(string $value, int $min, int $max): ?int
    {
        $length = \strlen($value);

        return $length >= $min && $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : null;
    }

    /**
     * The maximal `[a-z0-9]` runs of the ASCII-lowercased value.
     *
     * @return list<string>
     */
    private static function w61Tokens(string $value): array
    {
        $tokens = $value === '' ? false : preg_split('/[^a-z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }

    /**
     * Wave 61.2 adult release token classification and categorization plan. Per `name\tposter`
     * row `res | codec << 2 | container << 6 | flags << 10 | min(keywords, 15) << 23 | category
     * << 27 | min(tokens, 255) << 31 | min(len(name), 0xFFFF) << 39` over the w61Tokens() of the
     * name: res is the highest W61_XXX_TOKENS resolution (3 UHD, 2 HD, 1 SD); codec (1 x264, 2
     * x265, 4 XviD or a DVD/BD rip, 8 WMV), container (1 mp4, 2 mkv, 4 avi, 8 mov) and flags (1
     * xxx, 2 studio, 4 keyword, 8 OnlyFans-style site, 16 imageset, 32 pack, 64 DVD, 128 VR, also
     * any token of five or more bytes starting with a letter and ending in `vr`, 256 web, 512 a
     * date: a 1900-2099 year or two digits followed by 01-12 and 01-31 tokens, 1024 an `s` + 1-2
     * digits + `e` + 1-2 digits episode token, 2048 an SD release tag, 4096 an SD clip poster
     * from W61_XXX_POSTERS) OR their tokens; keywords counts keyword tokens. Adult rows carry
     * xxx, studio, keyword, site, SD tag or poster flags. Category indexes W61_XXX_CATEGORIES:
     * non-adult 0, then site 1, VR 2, an imageset without codec or container 3, pack 4, UHD 5,
     * poster 6, SD tag 7, WMV 8, DVD 9, XviD 10, x264/x265 or HD 11 with a date or studio else
     * 12, SD 6, else 13. Then fourteen category, four resolution and thirteen flag counts, the
     * adult rows, the keyword tokens, the distinct lowercase trimmed non-empty posters of adult
     * rows (by crc32) and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61XxxCategorizePlanBatchPhp(array $rows): array
    {
        $out = $posters = [];
        $categories = array_fill(0, 14, 0);
        $resolutions = [0, 0, 0, 0];
        $flagCounts = array_fill(0, 13, 0);
        $adultRows = $keywordTotal = 0;
        foreach ($rows as $row) {
            [$name, $poster] = explode("\t", (string) $row, 2) + ['', ''];
            $tokens = self::w61Tokens($name);
            $res = $codec = $container = $flags = $keywords = 0;
            foreach ($tokens as $i => $token) {
                $code = self::W61_XXX_TOKENS[$token] ?? 0;
                $res = max($res, $code & 3);
                $codec |= $code >> 2 & 15;
                $container |= $code >> 6 & 15;
                $flags |= $code >> 10;
                if (($code & 1 << 12) !== 0) {
                    $keywords++;
                }
                if (\strlen($token) >= 5 && str_ends_with($token, 'vr') && $token[0] >= 'a' && $token[0] <= 'z') {
                    $flags |= 1 << 7;
                }
                if (($flags & 1 << 10) === 0 && $token[0] === 's' && ($e = strpos($token, 'e', 1)) !== false
                    && self::w61Digits(substr($token, 1, $e - 1), 1, 2) !== null && self::w61Digits(substr($token, $e + 1), 1, 2) !== null) {
                    $flags |= 1 << 10;
                }
                if (($flags & 1 << 9) === 0) {
                    $year = self::w61Digits($token, 4, 4);
                    if (($year !== null && $year >= 1900 && $year <= 2099) || self::w61Digits($token, 2, 2) !== null) {
                        $month = self::w61Digits($tokens[$i + 1] ?? '', 2, 2);
                        $day = self::w61Digits($tokens[$i + 2] ?? '', 2, 2);
                        if ($month !== null && $month >= 1 && $month <= 12 && $day !== null && $day >= 1 && $day <= 31) {
                            $flags |= 1 << 9;
                        }
                    }
                }
            }
            foreach (self::W61_XXX_POSTERS as $needle) {
                if (stripos($poster, $needle) !== false) {
                    $flags |= 1 << 12;
                    break;
                }
            }
            $adult = ($flags & (1 | 2 | 4 | 8 | 1 << 11 | 1 << 12)) !== 0;
            $category = match (true) {
                ! $adult => 0,
                ($flags & 8) !== 0 => 1,
                ($flags & 1 << 7) !== 0 => 2,
                ($flags & 1 << 4) !== 0 && $codec === 0 && $container === 0 => 3,
                ($flags & 1 << 5) !== 0 => 4,
                $res === 3 => 5,
                ($flags & 1 << 12) !== 0 => 6,
                ($flags & 1 << 11) !== 0 => 7,
                ($codec & 8) !== 0 => 8,
                ($flags & 1 << 6) !== 0 => 9,
                ($codec & 4) !== 0 => 10,
                ($codec & 3) !== 0 || $res === 2 => ($flags & (1 << 9 | 2)) !== 0 ? 11 : 12,
                $res === 1 => 6,
                default => 13,
            };
            $categories[$category]++;
            $resolutions[$res]++;
            for ($k = 0; $k < 13; $k++) {
                $flagCounts[$k] += $flags >> $k & 1;
            }
            $keywordTotal += $keywords;
            if ($adult) {
                $adultRows++;
                $trimmed = trim($poster);
                if ($trimmed !== '') {
                    $posters[crc32(strtolower($trimmed))] = true;
                }
            }
            $out[] = $res | $codec << 2 | $container << 6 | $flags << 10 | min($keywords, 15) << 23 | $category << 27 | min(\count($tokens), 255) << 31
                | min(\strlen($name), 0xFFFF) << 39;
        }
        array_push($out, ...$categories, ...$resolutions, ...$flagCounts);
        array_push($out, $adultRows, $keywordTotal, \count($posters));
        $out[] = self::w61Digest($out);

        return $out;
    }
}
