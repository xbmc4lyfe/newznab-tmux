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
 * Categorizer for Movie content including HD, SD, UHD, 3D, Blu-ray, DVD, etc.
 */
class MovieCategorizer extends AbstractCategorizer
{
    /**
     * Wave 42.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustCategorizersBookConsoleGroupMiscMovieW42ParityTest.php measures at least 3.6x
     * (mean and p99, common and uncommon batches). PHP stays the fallback when the library is
     * unavailable.
     * Named W423 so it stays distinct from other Rust FFI toggles.
     */
    public const bool W423_RUST_FFI_DEFAULT = true;

    public const string W423_FFI_CDEF = <<<'C'
        ssize_t nntmux_w423_movie_quality_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Movie resolution marker substrings, in match order. The first tier with a substring of the
     * lowercased release name wins.
     *
     * @var array<string, list<string>>
     */
    public const array W423_MOVIE_RESOLUTION_MARKERS = [
        'uhd' => ['2160p', '4k', 'uhd'],
        'hd' => ['1080p', '1080i', '720p'],
        'sd' => ['480p', '576p', 'dvdrip', 'xvid', 'sdtv'],
    ];

    /** @var list<string> */
    public const array W423_HEVC_MARKERS = ['x265', 'hevc', 'h265', 'h.265'];

    /** @var list<string> */
    public const array W423_BLURAY_MARKERS = ['bluray', 'blu-ray', 'bdremux', 'bdrip'];

    /** Exactly 7 0x1F-separated decimals of 1-19 digits. */
    private const string W423_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){6}\d{1,19}\z/';

    private static ?bool $w423RustFfiOverride = null;

    private static FFI|false|null $w423RustFfiHandle = null;

    protected int $priority = 25;

    /**
     * Native (Rust FFI) bindings. Rule ids returned by the library index {@see self::NATIVE_RULES}.
     */
    private const string NATIVE_CDEF = <<<'CDEF'
        int64_t nntmux_categorize_movie(const char *name, size_t name_len, uint32_t flags);
CDEF;

    /** @var array<int, string> */
    private const array NATIVE_RULES = [
        0 => 'foreign_language',
        1 => 'foreign_castellano',
        2 => 'foreign_pattern',
        3 => 'x265_group',
        4 => 'uhd',
        5 => '3d',
        6 => 'bluray',
        7 => 'dvd',
        8 => 'webdl',
        9 => 'hd',
        10 => 'hd_webdl_fallback',
        11 => 'sd',
        12 => 'cam',
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
            $packed = $ffi->nntmux_categorize_movie($context->releaseName, \strlen($context->releaseName), ($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0));
        } catch (Throwable) {
            return null;
        }

        return \is_int($packed) && $packed >= 0 ? $packed : null;
    }

    public function getName(): string
    {
        return 'Movie';
    }

    public function shouldSkip(ReleaseContext $context): bool
    {
        // One native call decides the skip and, when processing continues, the categorization
        // that the pipe requests next for the same (immutable) context.
        $ffi = self::$nativeFfi ?? self::resolveNative();
        if ($ffi !== false) {
            try {
                // @phpstan-ignore method.notFound
                $packed = $ffi->nntmux_categorize_movie($context->releaseName, \strlen($context->releaseName), (($context->categorizeForeign ? 1 : 0) | ($context->catWebDL ? 2 : 0)) | 4);
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
        // Skip if this looks like adult content
        if ($context->hasAdultMarkers()) {
            return true;
        }

        // A standalone Sxx token identifies a full TV season even when year or
        // edition markers appear before the source and quality markers.
        if ($context->hasStandaloneSeasonToken()) {
            return true;
        }

        // Skip if it looks like a TV episode (S01E01) or season pack (S01.1080p)
        if (preg_match('/[._ -]S\d{1,3}[._ -]?(E\d|D\d|Complete|Full|1080|720|480|2160|WEB|HDTV|BluRay|NF|AMZN)/i', $context->releaseName)) {
            return true;
        }

        // Skip episode-only patterns (E01, E02) - common in anime
        if (preg_match('/[._ -]E\d{1,4}[._ -]/i', $context->releaseName)) {
            return true;
        }

        // Skip known anime release groups
        if (preg_match('/(?:^|[.\-_ \[])(URANiME|ANiHLS|HaiKU|ANiURL|SkyAnime|Erai-raws|LostYears|Vodes|SubsPlease|Judas|Ember|YuiSubs|ASW|Tsundere-Raws|Anime-Raws)(?:[.\-_ \]]|$)/i', $context->releaseName)) {
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

        // Check if it looks like movie content
        if (! $this->looksLikeMovie($name)) {
            return $this->noMatch();
        }

        // Try specific movie subcategories in order of specificity
        if ($context->categorizeForeign && ($result = $this->checkForeign($name))) {
            return $result;
        }

        if ($result = $this->checkX265($name)) {
            return $result;
        }

        if ($result = $this->checkUHD($name)) {
            return $result;
        }

        if ($result = $this->check3D($name)) {
            return $result;
        }

        if ($result = $this->checkBluRay($name)) {
            return $result;
        }

        if ($result = $this->checkDVD($name)) {
            return $result;
        }

        if ($context->catWebDL && ($result = $this->checkWebDL($name))) {
            return $result;
        }

        if ($result = $this->checkHD($name, $context->catWebDL)) {
            return $result;
        }

        if ($result = $this->checkSD($name)) {
            return $result;
        }

        if ($result = $this->checkOther($name)) {
            return $result;
        }

        return $this->noMatch();
    }

    /**
     * Check if release name looks like movie content.
     */
    protected function looksLikeMovie(string $name): bool
    {
        return (bool) preg_match('/[._ -]AVC|[BH][DR]RIP|(Bluray|Blu-Ray)|BD[._ -]?(25|50)?|\bBR\b|Camrip|[._ -]\d{4}[._ -].+(720p|1080p|Cam|HDTS|2160p)|DIVX|[._ -]DVD[._ -]|DVD-?(5|9|R|Rip)|Untouched|VHSRip|XVID|[._ -](DTS|TVrip|webrip|WEBDL|WEB-DL)[._ -]|\b(2160)p\b.*\b(Netflix|Amazon|NF|AMZN|Disney)\b/i', $name);
    }

    protected function checkForeign(string $name): ?CategorizationResult
    {
        if (preg_match('/(danish|flemish|Deutsch|dutch|french|german|heb|hebrew|nl[._ -]?sub|dub(bed|s)?|\.NL|norwegian|swedish|swesub|spanish|Staffel)[._ -]|\(german\)|Multisub/i', $name)) {
            return $this->matched(Category::MOVIE_FOREIGN, 0.8, 'foreign_language');
        }

        if (stripos($name, 'Castellano') !== false) {
            return $this->matched(Category::MOVIE_FOREIGN, 0.8, 'foreign_castellano');
        }

        if (preg_match('/(720p|1080p|AC3|AVC|DIVX|DVD(5|9|RIP|R)|XVID)[._ -](Dutch|French|German|ITA)|\(?(Dutch|French|German|ITA)\)?[._ -](720P|1080p|AC3|AVC|DIVX|DVD(5|9|RIP|R)|WEB(-DL|-?RIP)|HD[._ -]|XVID)/i', $name)) {
            return $this->matched(Category::MOVIE_FOREIGN, 0.85, 'foreign_pattern');
        }

        return null;
    }

    protected function checkX265(string $name): ?CategorizationResult
    {
        if (preg_match('/(\w+[\.-_\s]+).*(x265).*(Tigole|SESKAPiLE|CHD|IAMABLE|THREESOME|OohLaLa|DEFLATE|NCmt)/i', $name)) {
            return $this->matched(Category::MOVIE_X265, 0.9, 'x265_group');
        }

        return null;
    }

    protected function checkUHD(string $name): ?CategorizationResult
    {
        // Skip TV shows
        if (preg_match('/(S\d+).*(2160p).*(Netflix|Amazon|NF|AMZN).*(TrollUHD|NTb|VLAD|DEFLATE|CMRG)/i', $name)) {
            return null;
        }

        // Check for UHD indicators
        if (stripos($name, '2160p') !== false ||
            preg_match('/\b(UHD|Ultra[._ -]HD|4K)\b/i', $name) ||
            (preg_match('/\b(HDR|HDR10|HDR10\+|Dolby[._ -]?Vision)\b/i', $name) &&
             preg_match('/\b(HEVC|H\.?265|x265)\b/i', $name)) ||
            (stripos($name, 'UHD') !== false &&
             preg_match('/\b(BR|BluRay|Blu[._ -]?Ray)\b/i', $name))) {
            return $this->matched(Category::MOVIE_UHD, 0.9, 'uhd');
        }

        return null;
    }

    protected function check3D(string $name): ?CategorizationResult
    {
        if (preg_match('/[._ -]3D\s?[\.\-_\[ ](1080p|(19|20)\d\d|AVC|BD(25|50)|Blu[._ -]?ray|CEE|Complete|GER|MVC|MULTi|SBS|H(-)?SBS)[._ -]/i', $name)) {
            return $this->matched(Category::MOVIE_3D, 0.9, '3d');
        }

        return null;
    }

    protected function checkBluRay(string $name): ?CategorizationResult
    {
        if (preg_match('/bluray-|[._ -]bd?[._ -]?(25|50)|blu-ray|Bluray\s-\sUntouched|[._ -]untouched[._ -]/i', $name) &&
            ! preg_match('/SecretUsenet\.com$/i', $name)) {
            return $this->matched(Category::MOVIE_BLURAY, 0.9, 'bluray');
        }

        return null;
    }

    protected function checkDVD(string $name): ?CategorizationResult
    {
        if (preg_match('/(dvd\-?r|[._ -]dvd|dvd9|dvd5|[._ -]r5)[._ -]/i', $name)) {
            return $this->matched(Category::MOVIE_DVD, 0.85, 'dvd');
        }

        return null;
    }

    protected function checkWebDL(string $name): ?CategorizationResult
    {
        if (preg_match('/web[._ -]dl|web-?rip/i', $name)) {
            return $this->matched(Category::MOVIE_WEBDL, 0.85, 'webdl');
        }

        return null;
    }

    protected function checkHD(string $name, bool $catWebDL): ?CategorizationResult
    {
        if (preg_match('/720p|1080p|AVC|VC1|VC-1|web-dl|wmvhd|x264|XvidHD|bdrip/i', $name)) {
            return $this->matched(Category::MOVIE_HD, 0.85, 'hd');
        }

        if (! $catWebDL && preg_match('/web[._ -]dl|web-?rip/i', $name)) {
            return $this->matched(Category::MOVIE_HD, 0.8, 'hd_webdl_fallback');
        }

        return null;
    }

    protected function checkSD(string $name): ?CategorizationResult
    {
        if (preg_match('/(divx|dvdscr|extrascene|dvdrip|\.CAM|HDTS(-LINE)?|vhsrip|xvid(vd)?)[._ -]/i', $name)) {
            return $this->matched(Category::MOVIE_SD, 0.8, 'sd');
        }

        return null;
    }

    protected function checkOther(string $name): ?CategorizationResult
    {
        if (preg_match('/[._ -]cam[._ -]/i', $name)) {
            return $this->matched(Category::MOVIE_OTHER, 0.6, 'cam');
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
     * Movie quality summary for a batch of release names. Each lowercased name adds to its first
     * W423_MOVIE_RESOLUTION_MARKERS tier, else to unknown, and separately to hevc and bluray when
     * it holds any W423_HEVC_MARKERS or W423_BLURAY_MARKERS substring. Non-strings count as empty
     * names.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, uhd: int, hd: int, sd: int, unknown: int, hevc: int, bluray: int}
     */
    public static function summarizeMovieQualityBatch(array $releaseNames): array
    {
        return (self::usesW423RustFfi() ? self::summarizeMovieQualityBatchNative($releaseNames) : null)
            ?? self::summarizeMovieQualityBatchPhp($releaseNames);
    }

    /**
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, uhd: int, hd: int, sd: int, unknown: int, hevc: int, bluray: int}
     */
    public static function summarizeMovieQualityBatchPhp(array $releaseNames): array
    {
        $tiers = ['uhd' => 0, 'hd' => 0, 'sd' => 0];
        $unknown = 0;
        $hevc = 0;
        $bluray = 0;
        foreach ($releaseNames as $releaseName) {
            $name = strtolower(\is_string($releaseName) ? $releaseName : '');
            foreach (self::W423_HEVC_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $hevc++;

                    break;
                }
            }
            foreach (self::W423_BLURAY_MARKERS as $needle) {
                if (str_contains($name, $needle)) {
                    $bluray++;

                    break;
                }
            }
            foreach (self::W423_MOVIE_RESOLUTION_MARKERS as $tier => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $tiers[$tier]++;

                        continue 3;
                    }
                }
            }
            $unknown++;
        }

        return [
            'total' => \count($releaseNames),
            'uhd' => $tiers['uhd'],
            'hd' => $tiers['hd'],
            'sd' => $tiers['sd'],
            'unknown' => $unknown,
            'hevc' => $hevc,
            'bluray' => $bluray,
        ];
    }

    /**
     * Declines (null) for non-string items so PHP counts them.
     *
     * @param  array<array-key, mixed>  $releaseNames
     * @return array{total: int, uhd: int, hd: int, sd: int, unknown: int, hevc: int, bluray: int}|null
     */
    public static function summarizeMovieQualityBatchNative(array $releaseNames): ?array
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
            'uhd' => (int) $fields[1],
            'hd' => (int) $fields[2],
            'sd' => (int) $fields[3],
            'unknown' => (int) $fields[4],
            'hevc' => (int) $fields[5],
            'bluray' => (int) $fields[6],
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
            $written = $ffi->nntmux_w423_movie_quality_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w423_movie_quality_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
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
