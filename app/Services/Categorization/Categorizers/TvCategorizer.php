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
 * Categorizer for TV content including HD, SD, UHD, Anime, Sports, Documentaries, etc.
 */
class TvCategorizer extends AbstractCategorizer
{
    /**
     * Wave 43.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersMusicPcTvXxxPipeW43ParityTest.php measures at least 3.4x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W433 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W433_RUST_FFI_DEFAULT = true;

    public const string W433_FFI_CDEF = <<<'C'
        ssize_t nntmux_w433_tv_content_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * TV content marker substrings, in match order. The first class with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W433_TV_CONTENT_MARKERS = [
        'anime' => ['anime', 'subsplease', 'erai-raws', 'horriblesubs'],
        'sport' => ['nfl', 'nba', 'nhl', 'ufc', 'wwe', 'formula1', 'nascar'],
        'documentary' => ['documentary', 'docu', 'nat.geo', 'bbc.earth'],
        'uhd' => ['2160p', 'uhd', '4k'],
        'hd' => ['1080p', '1080i', '720p'],
        'sd' => ['480p', 'sdtv', 'pdtv', 'dvdrip', 'xvid'],
    ];

    /** @var list<string> */
    public const array W433_WEB_MARKERS = ['web-dl', 'webrip', 'web.dl'];

    /** Exactly 9 0x1F-separated decimals of 1-19 digits. */
    private const string W433_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){8}\d{1,19}\z/';

    private static ?bool $w433RustFfiOverride = null;

    private static FFI|false|null $w433RustFfiHandle = null;

    private const string SPORTS_MARKER_REGEX = '/\b(NFL|NBA|NHL|MLB|MLS|EPL|UFC|WWE|Boxing|F1|Formula[._ -]?1|NASCAR|PGA|Tennis|Golf|Soccer|Football|Cricket|Rugby|Olympics?|Olympic[._ -]?Games?|Paralympics?)\b/i';

    private const string SPORTS_EVENT_CONTEXT_REGEX = '/\d{4}|\b(Season|Week|Round|Match|Game|vs|Playoffs?|Finals?|Qualifying|Opening|Closing|Ceremony|Championship)\b/i';

    /**
     * Known anime release/fansub group tags. Matches when the tag appears
     * as a standalone token in the release name (bracketed, dotted, etc.).
     */
    private const string ANIME_GROUPS_REGEX = '/(?:^|[.\-_ \[])(URANiME|ANiHLS|HaiKU|ANiURL|SkyAnime|Erai-raws|LostYears|Vodes|SubsPlease|Judas|Ember|EMBER|YuiSubs|ASW|Tsundere-Raws|Anime-Raws|Kekkan|SSA|MoyaiSubs|QCE|Nyanpasu|UCCUSS|NS|DKB|MTBB|NanDesuKa|smol|Cleo|ToshY|Kawaiika-Raws|Beatrice-Raws|Reaktor|FLE|Commie|HorribleSubs|Doki|FFF|UTW|Coalgirls|CoalGuys|Mezashite|Vivid|Kaylith|Underwater|gg|Chihiro|Hatsuyuki|Mazui|WhyNot|Migoto|Doutei|GJM|Asenshi|Inka-Subs|Akatsuki)(?:[.\-_ \]]|$)/i';

    protected int $priority = 20;

    /**
     * Native (Rust FFI) bindings. Rule ids returned by the library index {@see self::NATIVE_RULES}.
     */
    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_tv(const char *name, size_t name_len, const char *group, size_t group_len, const char *poster, size_t poster_len, uint32_t flags);
CDEF;

    /** @var array<int, string> */
    private const array NATIVE_RULES = [
        0 => 'anime_tosho_poster',
        1 => 'anime_group_name',
        2 => 'anime_group',
        3 => 'anime_hash',
        4 => 'anime_pattern',
        5 => 'anime_japanese_title',
        6 => 'anime_episode_bluray',
        7 => 'anime_roman_numeral_season',
        8 => 'sport',
        9 => 'documentary',
        10 => 'foreign_language',
        11 => 'x265_group',
        12 => 'webdl',
        13 => 'uhd_episode',
        14 => 'uhd_season',
        15 => 'uhd_streaming',
        16 => 'hd_resolution',
        17 => 'hd_webdl_fallback',
        18 => 'sd_format',
        19 => 'sd_codec',
        20 => 'tv_other',
        21 => 'tv_season_pack',
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
            $packed = $ffi->nntmux_categorize_tv($context->releaseName, \strlen($context->releaseName), $context->groupName, \strlen($context->groupName), $context->poster, \strlen($context->poster), ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0));
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? $packed : null;
    }

    public function getName(): string
    {
        return 'TV';
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        // One native call decides the skip and, when processing continues, the categorization
        // that the pipe requests next for the same (immutable) context.
        $ffi = self::$nativeFfi ?? self::resolveNative();
        if ($ffi !== false) {
            try {
                // @phpstan-ignore method.notFound
                $packed = $ffi->nntmux_categorize_tv($context->releaseName, \strlen($context->releaseName), $context->groupName, \strlen($context->groupName), $context->poster, \strlen($context->poster), (($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0)) | 4);
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
        return $context->hasAdultMarkers();
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

        // High-signal anime checks first. These can match release names that
        // don't follow standard TV S01E01 naming conventions (e.g. anime
        // fansub releases like "[Group] Show - 08 [1080p]").
        if ($result = $this->checkAnimeStrongSignals($name, $context)) {
            return $result;
        }

        if (! $this->looksLikeTV($context)) {
            return $this->noMatch();
        }

        if ($result = $this->checkAnime($name, $context)) {
            return $result;
        }
        if ($result = $this->checkSport($name)) {
            return $result;
        }
        if ($result = $this->checkDocumentary($name)) {
            return $result;
        }
        if ($result = $this->checkForeign($context)) {
            return $result;
        }
        if ($result = $this->checkX265($name)) {
            return $result;
        }
        if ($context->catWebDL && ($result = $this->checkWebDL($name))) {
            return $result;
        }
        if ($result = $this->checkUHD($name)) {
            return $result;
        }
        if ($result = $this->checkHD($name, $context->catWebDL)) {
            return $result;
        }
        if ($result = $this->checkSD($name)) {
            return $result;
        }
        if ($result = $this->checkOther($context)) {
            return $result;
        }

        return $this->noMatch();
    }

    protected function looksLikeTV(ReleaseContext $context): bool
    {
        $name = $context->releaseName;

        // Standalone season token: S01, S02, etc. Quality markers may appear later.
        if ($context->hasStandaloneSeasonToken()) {
            return true;
        }

        // Season + Episode pattern: S01E01, S01.E01, S1D1, etc.
        if (preg_match('/[._ -]s\d{1,3}[._ -]?(e|d(isc)?)\d{1,3}([._ -]|$)/i', $name)) {
            return true;
        }
        // Episode-only pattern: .E01., .E02., E01.1080p (common in anime)
        if (preg_match('/[._ -]E\d{1,4}[._ -]/i', $name)) {
            return true;
        }
        // Season pack with Complete/Full: S01.Complete, S01.Full
        if (preg_match('/[._ -]S\d{1,3}[._ -]?(Complete|COMPLETE|Full|FULL)/i', $name)) {
            return true;
        }
        // Season pack with resolution/quality: S01.1080p, S01.720p, S01.2160p, S02.WEB-DL
        if (preg_match('/[._ -]S\d{1,3}[._ -](480p|720p|1080[pi]|2160p|4K|UHD|WEB|HDTV|BluRay|NF|AMZN|DSNP|ATVP|HMAX)/i', $name)) {
            return true;
        }
        // Episode pattern: Episode 01, Ep.01, Ep 1
        if (preg_match('/\b(Episode|Ep)[._ -]?\d{1,4}\b/i', $name)) {
            return true;
        }
        // TV source markers
        if (preg_match('/\b(HDTV|PDTV|DSR|TVRip|SATRip|DTHRip)\b/i', $name)) {
            return true;
        }
        // Daily show pattern: Show.Name.2024.01.15 or Show.Name.2024-01-15
        if (preg_match('/[._ -](19|20)\d{2}[._ -]\d{2}[._ -]\d{2}[._ -]/i', $name)) {
            return true;
        }
        // Sports events are TV-like even without SxxExx markers.
        if ($this->isSportEvent($name)) {
            return true;
        }
        // Known anime release groups (should be treated as TV)
        if (preg_match(self::ANIME_GROUPS_REGEX, $name)) {
            return true;
        }

        return false;
    }

    /**
     * Strong, gate-bypassing anime signals: Anime Tosho poster, anime usenet
     * groups, and anime hash patterns. These can match even when the release
     * name doesn't look like a typical TV release.
     */
    protected function checkAnimeStrongSignals(string $name, ReleaseContext $context): ?CategorizationResult
    {
        // Anime Tosho poster (very strong signal)
        if ($context->poster !== '' && preg_match('/animetosho\.org/i', $context->poster)) {
            return $this->matched(Category::TV_ANIME, 0.98, 'anime_tosho_poster');
        }
        // Usenet group dedicated to anime (e.g. alt.binaries.*anime*)
        if ($context->groupName !== '' && preg_match('/alt\.binaries\..*anime/i', $context->groupName)) {
            return $this->matched(Category::TV_ANIME, 0.9, 'anime_group_name');
        }
        // Known anime fansub/release group tag
        if (preg_match(self::ANIME_GROUPS_REGEX, $name)) {
            return $this->matched(Category::TV_ANIME, 0.92, 'anime_group');
        }
        // Anime hash pattern: [GroupName] Title - 01 [ABCD1234]
        if (preg_match('/^\[.+\].*\d{2,3}.*\[[a-fA-F0-9]{8}\]/i', $name)) {
            return $this->matched(Category::TV_ANIME, 0.9, 'anime_hash');
        }

        return null;
    }

    protected function checkAnime(string $name, ReleaseContext $context): ?CategorizationResult
    {
        // Check for Anime Tosho poster
        if ($context->poster !== '' && preg_match('/animetosho\.org/i', $context->poster)) {
            return $this->matched(Category::TV_ANIME, 0.98, 'anime_tosho_poster');
        }
        if (preg_match('/[._ -]Anime[._ -]/i', $name)) {
            return $this->matched(Category::TV_ANIME, 0.95, 'anime_pattern');
        }
        // Known anime release groups - matches with brackets, dots, dashes, underscores, spaces, or at the start
        if (preg_match(self::ANIME_GROUPS_REGEX, $name)) {
            return $this->matched(Category::TV_ANIME, 0.95, 'anime_group');
        }
        // Anime hash pattern: [GroupName] Title - 01 [ABCD1234]
        if (preg_match('/^\[.+\].*\d{2,3}.*\[[a-fA-F0-9]{8}\]/i', $name)) {
            return $this->matched(Category::TV_ANIME, 0.9, 'anime_hash');
        }
        // Japanese title pattern with "no" particle (e.g., Shuumatsu.no.Valkyrie, Shingeki.no.Kyojin)
        // Combined with episode-only pattern (E05 without season prefix) or roman numeral season
        if (preg_match('/[._ ]no[._ ]/i', $name) &&
            (preg_match('/[._ ](I{1,3}|IV|V|VI{0,3}|IX|X)[._ ]?E\d{1,4}[._ ]/i', $name) ||
             (preg_match('/[._ ]E\d{1,4}[._ ]/i', $name) && ! preg_match('/[._ ]S\d{1,3}[._ ]?E\d/i', $name)))) {
            return $this->matched(Category::TV_ANIME, 0.9, 'anime_japanese_title');
        }
        // Episode pattern with known anime indicators
        if (preg_match('/[._ -]E\d{1,4}[._ -]/i', $name) &&
            preg_match('/\b(BluRay|BD|BDRip)\b/i', $name) &&
            ! preg_match('/\bS\d{1,3}\b/i', $name)) {
            // Episode-only pattern with BluRay but no season - likely anime
            return $this->matched(Category::TV_ANIME, 0.8, 'anime_episode_bluray');
        }
        // Roman numeral season with episode-only pattern (common in anime)
        // e.g., Title.III.E05, Title.II.E12 - typically anime naming convention
        if (preg_match('/[._ ](I{1,3}|IV|V|VI{0,3}|IX|X)[._ ]E\d{1,4}[._ ]/i', $name) &&
            ! preg_match('/[._ ]S\d{1,3}[._ ]?E\d/i', $name)) {
            return $this->matched(Category::TV_ANIME, 0.85, 'anime_roman_numeral_season');
        }

        return null;
    }

    protected function checkSport(string $name): ?CategorizationResult
    {
        if ($this->isSportEvent($name)) {
            return $this->matched(Category::TV_SPORT, 0.85, 'sport');
        }

        return null;
    }

    protected function checkDocumentary(string $name): ?CategorizationResult
    {
        if (preg_match('/\b(Documentary|Docu[._ -]?Series|DOCU)\b/i', $name)) {
            return $this->matched(Category::TV_DOCU, 0.85, 'documentary');
        }

        return null;
    }

    protected function checkForeign(ReleaseContext $context): ?CategorizationResult
    {
        if (! $context->categorizeForeign) {
            return null;
        }
        if (preg_match('/(danish|flemish|Deutsch|dutch|french|german|hebrew|nl[._ -]?sub|dub(bed|s)?|\.NL|norwegian|swedish|swesub|spanish|Staffel)[._ -]|\(german\)|Multisub/i', $context->releaseName)) {
            return $this->matched(Category::TV_FOREIGN, 0.8, 'foreign_language');
        }

        return null;
    }

    protected function checkX265(string $name): ?CategorizationResult
    {
        if (preg_match('/(S\d+).*(x265).*(rmteam|MeGusta|HETeam|PSA|ONLY|H4S5S|TrollHD|ImE)/i', $name)) {
            return $this->matched(Category::TV_X265, 0.9, 'x265_group');
        }

        return null;
    }

    protected function checkWebDL(string $name): ?CategorizationResult
    {
        if (preg_match('/web[._ -]dl|web-?rip/i', $name)) {
            return $this->matched(Category::TV_WEBDL, 0.85, 'webdl');
        }

        return null;
    }

    protected function checkUHD(string $name): ?CategorizationResult
    {
        // Single episode UHD
        if (preg_match('/S\d+[._ -]?E\d+/i', $name) && preg_match('/2160p/i', $name)) {
            return $this->matched(Category::TV_UHD, 0.9, 'uhd_episode');
        }
        // Season pack UHD
        if (preg_match('/[._ -]S\d+[._ -].*2160p/i', $name)) {
            return $this->matched(Category::TV_UHD, 0.9, 'uhd_season');
        }
        // UHD with streaming service markers
        if (preg_match('/(S\d+).*(2160p).*(Netflix|Amazon|NF|AMZN).*(TrollUHD|NTb|VLAD|DEFLATE|POFUDUK|CMRG)/i', $name)) {
            return $this->matched(Category::TV_UHD, 0.9, 'uhd_streaming');
        }

        return null;
    }

    protected function checkHD(string $name, bool $catWebDL): ?CategorizationResult
    {
        if (preg_match('/1080([ip])|720p|bluray/i', $name)) {
            return $this->matched(Category::TV_HD, 0.85, 'hd_resolution');
        }
        if (! $catWebDL && preg_match('/web[._ -]dl|web-?rip/i', $name)) {
            return $this->matched(Category::TV_HD, 0.8, 'hd_webdl_fallback');
        }

        return null;
    }

    protected function checkSD(string $name): ?CategorizationResult
    {
        if (preg_match('/(360|480|576)p|Complete[._ -]Season|dvdr(ip)?|dvd5|dvd9|\.pdtv|SD[._ -]TV|TVRip|NTSC|BDRip|hdtv|xvid/i', $name)) {
            return $this->matched(Category::TV_SD, 0.8, 'sd_format');
        }
        if (preg_match('/(([HP])D[._ -]?TV|DSR|WebRip)[._ -]x264/i', $name)) {
            return $this->matched(Category::TV_SD, 0.8, 'sd_codec');
        }

        return null;
    }

    protected function checkOther(ReleaseContext $context): ?CategorizationResult
    {
        $name = $context->releaseName;

        // Season + episode pattern
        if (preg_match('/[._ -]s\d{1,3}[._ -]?(e|d(isc)?)\d{1,3}([._ -]|$)/i', $name)) {
            return $this->matched(Category::TV_OTHER, 0.6, 'tv_other');
        }
        // Standalone season pack pattern (S01, S02, etc.)
        if ($context->hasStandaloneSeasonToken()) {
            return $this->matched(Category::TV_OTHER, 0.6, 'tv_season_pack');
        }

        return null;
    }

    protected function isSportEvent(string $name): bool
    {
        return preg_match(self::SPORTS_MARKER_REGEX, $name) === 1
            && preg_match(self::SPORTS_EVENT_CONTEXT_REGEX, $name) === 1;
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
     * TV content summary for a batch of release names. Each lowercased name adds to its first
     * W433_TV_CONTENT_MARKERS class, else to unknown, and separately to web when it holds any
     * W433_WEB_MARKERS entry. Non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, anime: int, sport: int, documentary: int, uhd: int, hd: int, sd: int, unknown: int, web: int}
     */
    public static function summarizeTvContentBatch(array $releaseNames): array
    {
        return (self::usesW433RustFfi() ? self::summarizeTvContentBatchNative($releaseNames) : null)
            ?? self::summarizeTvContentBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, anime: int, sport: int, documentary: int, uhd: int, hd: int, sd: int, unknown: int, web: int}
     */
    public static function summarizeTvContentBatchPhp(array $releaseNames): array
    {
        $anime = 0;
        $sport = 0;
        $documentary = 0;
        $uhd = 0;
        $hd = 0;
        $sd = 0;
        $unknown = 0;
        $webCount = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W433_WEB_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $webCount++;

                    break;
                }
            }
            foreach (self::W433_TV_CONTENT_MARKERS['anime'] as $needle) {
                if (str_contains($name, $needle)) {
                    $anime++;

                    continue 2;
                }
            }
            foreach (self::W433_TV_CONTENT_MARKERS['sport'] as $needle) {
                if (str_contains($name, $needle)) {
                    $sport++;

                    continue 2;
                }
            }
            foreach (self::W433_TV_CONTENT_MARKERS['documentary'] as $needle) {
                if (str_contains($name, $needle)) {
                    $documentary++;

                    continue 2;
                }
            }
            foreach (self::W433_TV_CONTENT_MARKERS['uhd'] as $needle) {
                if (str_contains($name, $needle)) {
                    $uhd++;

                    continue 2;
                }
            }
            foreach (self::W433_TV_CONTENT_MARKERS['hd'] as $needle) {
                if (str_contains($name, $needle)) {
                    $hd++;

                    continue 2;
                }
            }
            foreach (self::W433_TV_CONTENT_MARKERS['sd'] as $needle) {
                if (str_contains($name, $needle)) {
                    $sd++;

                    continue 2;
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'anime' => $anime,
            'sport' => $sport,
            'documentary' => $documentary,
            'uhd' => $uhd,
            'hd' => $hd,
            'sd' => $sd,
            'unknown' => $unknown,
            'web' => $webCount,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, anime: int, sport: int, documentary: int, uhd: int, hd: int, sd: int, unknown: int, web: int}|null
     */
    public static function summarizeTvContentBatchNative(array $releaseNames): ?array
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
            'anime' => (int) $fields[1],
            'sport' => (int) $fields[2],
            'documentary' => (int) $fields[3],
            'uhd' => (int) $fields[4],
            'hd' => (int) $fields[5],
            'sd' => (int) $fields[6],
            'unknown' => (int) $fields[7],
            'web' => (int) $fields[8],
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
            $written = $ffi->nntmux_w433_tv_content_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w433_tv_content_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
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

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 8.75x/6.20x, uncommon 10.71x/8.71x); see RustCategorizersTvXxxConsoleGamesServiceTitleParserW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_TV_CATEGORIZER_FFI_CDEF = <<<'C'
        intptr_t nntmux_w612_tv_categorize_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W61_TV_CATEGORIZER_TAIL = 32;

    /** Category id per w61TvCategorizePlanBatch() category index (0: not TV). */
    public const array W61_TV_CATEGORIES = [0, Category::TV_WEBDL, Category::TV_FOREIGN, Category::TV_SD, Category::TV_HD, Category::TV_UHD,
        Category::TV_SPORT, Category::TV_ANIME, Category::TV_DOCU, Category::TV_OTHER];

    /** Lowercase token => `res | source << 2 | codec << 6 | content << 9 | webdl << 15`. */
    private const array W61_TV_TOKENS = [
        '2160p' => 3, '4k' => 3, 'uhd' => 3, '1080p' => 2, '1080i' => 2, '720p' => 2, '480p' => 1, '576p' => 1, '360p' => 1, 'sdtv' => 1, 'sd' => 1,
        'hdtv' => 1 << 2, 'pdtv' => 1 << 2, 'dsr' => 1 << 2, 'tvrip' => 1 << 2, 'satrip' => 1 << 2, 'dthrip' => 1 << 2,
        'web' => 2 << 2, 'amzn' => 2 << 2, 'nf' => 2 << 2, 'dsnp' => 2 << 2, 'hmax' => 2 << 2, 'atvp' => 2 << 2, 'hulu' => 2 << 2,
        'webdl' => 2 << 2 | 1 << 15, 'webrip' => 2 << 2 | 1 << 15,
        'bluray' => 4 << 2, 'bdrip' => 4 << 2, 'bd' => 4 << 2, 'brrip' => 4 << 2, 'remux' => 4 << 2,
        'dvdrip' => 8 << 2, 'dvdr' => 8 << 2, 'dvd5' => 8 << 2, 'dvd9' => 8 << 2, 'dvd' => 8 << 2,
        'x264' => 1 << 6, 'h264' => 1 << 6, 'avc' => 1 << 6, 'x265' => 2 << 6, 'h265' => 2 << 6, 'hevc' => 2 << 6, 'xvid' => 4 << 6, 'divx' => 4 << 6,
        'anime' => 1 << 9, 'subsplease' => 1 << 9, 'horriblesubs' => 1 << 9, 'erai' => 1 << 9, 'judas' => 1 << 9, 'ember' => 1 << 9, 'asw' => 1 << 9,
        'commie' => 1 << 9,
        'nfl' => 2 << 9, 'nba' => 2 << 9, 'nhl' => 2 << 9, 'mlb' => 2 << 9, 'mls' => 2 << 9, 'epl' => 2 << 9, 'ufc' => 2 << 9, 'wwe' => 2 << 9,
        'boxing' => 2 << 9, 'f1' => 2 << 9, 'formula1' => 2 << 9, 'nascar' => 2 << 9, 'pga' => 2 << 9, 'tennis' => 2 << 9, 'golf' => 2 << 9,
        'soccer' => 2 << 9, 'football' => 2 << 9, 'cricket' => 2 << 9, 'rugby' => 2 << 9, 'olympics' => 2 << 9, 'olympic' => 2 << 9,
        'paralympics' => 2 << 9,
        'documentary' => 4 << 9, 'docu' => 4 << 9, 'docuseries' => 4 << 9,
        'danish' => 8 << 9, 'flemish' => 8 << 9, 'deutsch' => 8 << 9, 'dutch' => 8 << 9, 'french' => 8 << 9, 'german' => 8 << 9, 'hebrew' => 8 << 9,
        'nlsub' => 8 << 9, 'dubbed' => 8 << 9, 'dub' => 8 << 9, 'dubs' => 8 << 9, 'norwegian' => 8 << 9, 'swedish' => 8 << 9, 'swesub' => 8 << 9,
        'spanish' => 8 << 9, 'staffel' => 8 << 9, 'multisub' => 8 << 9,
        'complete' => 16 << 9, 'proper' => 32 << 9, 'repack' => 32 << 9, 'rerip' => 32 << 9,
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w61', self::W61_TV_CATEGORIZER_FFI_CDEF) ?? false;

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
     * Runs $callback with w61TvCategorizePlanBatch() routed to Rust ($enabled) or PHP, then restores the
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
    public static function w61TvCategorizePlanBatch(array $rows): array
    {
        return self::usesW61RustFfi() ? self::w61TvCategorizePlanBatchNative($rows) : self::w61TvCategorizePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61TvCategorizePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W61_TV_CATEGORIZER_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w61RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w61TvCategorizePlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W61_TV_CATEGORIZER_FFI_CDEF.)
            $written = $ffi->nntmux_w612_tv_categorize_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w61TvCategorizePlanBatchPhp($rows);
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
     * Wave 61.2 TV token classification and categorization plan. Per `name\tgroup` row `res |
     * source << 2 | codec << 6 | content << 9 | webdl << 16 | kind << 17 | season << 20 | episode
     * << 30 | category << 44 | min(tokens, 255) << 48 | min(len(name), 127) << 56` over the
     * w61Tokens() of the name: res is the highest W61_TV_TOKENS resolution (3 UHD, 2 HD, 1 SD);
     * source (1 HDTV, 2 web, 4 Blu-ray, 8 DVD), codec (1 x264, 2 x265, 4 XviD) and content (1
     * anime, also for a group containing `anime` in any case, 2 sport, 4 documentary, 8 foreign,
     * 16 complete, 32 repack, 64 a 1900-2099 year token) OR their tokens; webdl is a `webdl`
     * or `webrip` token or `web` followed by `dl` or `rip`. Kind, season and episode come from
     * the first token starting a marker (w61TvEpisode()). TV-like rows have a marker or an HDTV
     * source. Category indexes W61_TV_CATEGORIES: anime 7, sport 6 (when TV-like or with a
     * year), then non-TV-like 0, documentary 8, foreign 2, UHD 5, web-dl 1, HD or Blu-ray 4, SD,
     * HDTV, DVD or XviD 3, else 9. Then ten category, four resolution, six kind and eight flag
     * (seven content bits and webdl) counts, the TV-like rows, their distinct lowercase trimmed
     * non-empty groups (by crc32), the largest season of kinds 1-3 and the XXH64 digest of all
     * earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w61TvCategorizePlanBatchPhp(array $rows): array
    {
        $out = $groups = [];
        $categories = array_fill(0, 10, 0);
        $resolutions = [0, 0, 0, 0];
        $kinds = array_fill(0, 6, 0);
        $flags = array_fill(0, 8, 0);
        $tvRows = $maxSeason = 0;
        foreach ($rows as $row) {
            [$name, $group] = explode("\t", (string) $row, 2) + ['', ''];
            $tokens = self::w61Tokens($name);
            $res = $source = $codec = $content = $webdl = 0;
            $kind = $season = $episode = 0;
            foreach ($tokens as $i => $token) {
                $code = self::W61_TV_TOKENS[$token] ?? 0;
                if ($code !== 0) {
                    $res = max($res, $code & 3);
                    $source |= $code >> 2 & 15;
                    $codec |= $code >> 6 & 7;
                    $content |= $code >> 9 & 63;
                    $webdl |= $code >> 15 & 1;
                }
                if ($token === 'web' && \in_array($tokens[$i + 1] ?? '', ['dl', 'rip'], true)) {
                    $webdl = 1;
                }
                if (\strlen($token) === 4 && ($year = self::w61Digits($token, 4, 4)) !== null && $year >= 1900 && $year <= 2099) {
                    $content |= 64;
                }
                if ($kind === 0) {
                    [$kind, $season, $episode] = self::w61TvEpisode($tokens, $i);
                }
            }
            if (stripos($group, 'anime') !== false) {
                $content |= 1;
            }
            $tv = $kind !== 0 || ($source & 1) !== 0;
            $category = match (true) {
                ($content & 1) !== 0 => 7,
                ($content & 2) !== 0 && ($tv || ($content & 64) !== 0) => 6,
                ! $tv => 0,
                ($content & 4) !== 0 => 8,
                ($content & 8) !== 0 => 2,
                $res === 3 => 5,
                $webdl === 1 => 1,
                $res === 2 || ($source & 4) !== 0 => 4,
                $res === 1 || ($source & 9) !== 0 || ($codec & 4) !== 0 => 3,
                default => 9,
            };
            $categories[$category]++;
            $resolutions[$res]++;
            $kinds[$kind]++;
            for ($k = 0; $k < 7; $k++) {
                $flags[$k] += $content >> $k & 1;
            }
            $flags[7] += $webdl;
            if ($tv) {
                $tvRows++;
                $trimmed = trim($group);
                if ($trimmed !== '') {
                    $groups[crc32(strtolower($trimmed))] = true;
                }
            }
            if ($kind >= 1 && $kind <= 3) {
                $maxSeason = max($maxSeason, $season);
            }
            $out[] = $res | $source << 2 | $codec << 6 | $content << 9 | $webdl << 16 | $kind << 17 | $season << 20 | $episode << 30 | $category << 44
                | min(\count($tokens), 255) << 48 | min(\strlen($name), 127) << 56;
        }
        array_push($out, ...$categories, ...$resolutions, ...$kinds, ...$flags);
        array_push($out, $tvRows, \count($groups), $maxSeason);
        $out[] = self::w61Digest($out);

        return $out;
    }

    /**
     * `[kind, season, episode]` of the marker starting at token $i, checked in order: 1 `s` + 1-3
     * digits + `e` + 1-4 digits (or `s` + 1-3 digits followed by an `e` + 1-4 digits token), 2
     * `s` + 1-3 digits alone (a season pack; a token holding an `e` after its `s` only tries
     * kind 1), 3 1-2 digits + `x` + 2-3 digits, 4 a 1900-2099 year followed by a 01-12 month and
     * a 01-31 day token (season year - 1900, episode month * 32 + day), 5 `ep` or `e` + 1-4
     * digits, or `ep`/`episode` followed by a 1-4 digit token; else `[0, 0, 0]`.
     *
     * @param  list<string>  $tokens
     * @return array{int, int, int}
     */
    private static function w61TvEpisode(array $tokens, int $i): array
    {
        $token = $tokens[$i];
        if ($token[0] === 's') {
            $e = strpos($token, 'e', 1);
            if ($e === false) {
                $season = self::w61Digits(substr($token, 1), 1, 3);
                if ($season !== null) {
                    $next = $tokens[$i + 1] ?? '';
                    $episode = str_starts_with($next, 'e') ? self::w61Digits(substr($next, 1), 1, 4) : null;

                    return $episode === null ? [2, $season, 0] : [1, $season, $episode];
                }
            } else {
                $season = self::w61Digits(substr($token, 1, $e - 1), 1, 3);
                $episode = self::w61Digits(substr($token, $e + 1), 1, 4);
                if ($season !== null && $episode !== null) {
                    return [1, $season, $episode];
                }
            }
        }
        $x = strpos($token, 'x');
        if ($x !== false && $x > 0) {
            $season = self::w61Digits(substr($token, 0, $x), 1, 2);
            $episode = self::w61Digits(substr($token, $x + 1), 2, 3);
            if ($season !== null && $episode !== null) {
                return [3, $season, $episode];
            }
        }
        $year = self::w61Digits($token, 4, 4);
        if ($year !== null && $year >= 1900 && $year <= 2099) {
            $month = self::w61Digits($tokens[$i + 1] ?? '', 2, 2);
            $day = self::w61Digits($tokens[$i + 2] ?? '', 2, 2);
            if ($month !== null && $month >= 1 && $month <= 12 && $day !== null && $day >= 1 && $day <= 31) {
                return [4, $year - 1900, $month * 32 + $day];
            }
        }
        if ($token[0] === 'e') {
            $episode = str_starts_with($token, 'ep') ? self::w61Digits(substr($token, 2), 1, 4) : null;
            $episode ??= self::w61Digits(substr($token, 1), 1, 4);
            if ($episode === null && ($token === 'ep' || $token === 'episode')) {
                $episode = self::w61Digits($tokens[$i + 1] ?? '', 1, 4);
            }
            if ($episode !== null) {
                return [5, 0, $episode];
            }
        }

        return [0, 0, 0];
    }
}
