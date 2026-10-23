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
}
