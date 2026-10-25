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
}
