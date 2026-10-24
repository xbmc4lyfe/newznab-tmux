<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Patterns;

use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Regex patterns for TV release name matching.
 *
 * Organized by pattern complexity and specificity for efficient matching.
 */
final class TvPatterns
{
    // ========================================================================
    // STREAMING SERVICE PATTERNS (Modern releases - highest priority)
    // ========================================================================

    /**
     * Streaming service 4K releases with HDR.
     * Example: Show.Name.S01E01.2160p.AMZN.WEB-DL.HDR.HEVC-GROUP
     */
    public const STREAMING_4K_HDR = '/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?e\d{1,3}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2,3})[._ -](2160p|4K|UHD)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[._ -](HDR10?\+?|DV|Dolby[._ -]?Vision)?[._ -]?(HEVC|x265|H\.?265)[\-\w.\',;& ]+\w/i';

    /**
     * Streaming service 1080p/720p releases.
     * Example: Show.Name.S01E01.1080p.NF.WEB-DL-GROUP
     */
    public const STREAMING_HD = '/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?e\d{1,3}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2,3})[._ -](1080p|720p)[._ -](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|VUDU)[._ -](WEB-?DL|WEB-?RIP)[\-\w.\',;& ]+\w/i';

    // ========================================================================
    // STANDARD TV PATTERNS
    // ========================================================================

    /**
     * Standard TV with source and group.
     * Example: Show.Name.S01E01.HDTV.x264-GROUP
     */
    public const TV_SOURCE_GROUP = '/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)[S|]\d{1,2}[E|x]\d{1,}(?!\d)|ep[._ -]?\d{2})[\-\w.\',;.()]+(BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -][\-\w.\',;& ]+\w/i';

    /**
     * TV with year.
     * Example: Show.Name.S01E01.Something.2023.x264-GROUP
     */
    public const TV_WITH_YEAR = '/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[\-\w.\',;& ]+((19|20)\d\d)[\-\w.\',;& ]+\w/i';

    /**
     * TV with resolution, source, and video codec.
     * Example: Show.Name.S01E01.720p.HDTV.x264-GROUP
     */
    public const TV_RES_SOURCE_VCODEC = '/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2,3})[\-\w.\',;& ]+(480|720|1080|2160)[ip]?[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.\',;& ]+\w/i';

    /**
     * TV with source and video codec only.
     * Example: Show.Name.S01E01.HDTV.x264-GROUP
     */
    public const TV_SOURCE_VCODEC = '/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.\',;& ]+\w/i';

    /**
     * TV with audio, source, resolution, and video codec.
     * Example: Show.Name.S01E01.DD5.1.HDTV.720p.x264-GROUP
     */
    public const TV_AUDIO_SOURCE_RES_VCODEC = '/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](AAC( LC)?|AC-?3|DD5([._ -]1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby( ?TrueHD)?|MP3|TrueHD|Atmos|EAC3)[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[._ -](480|720|1080|2160)[ip]?[._ -](DivX|[HX][._ -]?264|[HX][._ -]?265|HEVC|MPEG2|XviD(HD)?|WMV|AV1)[\-\w.\',;& ]+\w/i';

    /**
     * TV with year and season/episode.
     * Example: Show.Name.2023.S01E01.HDTV-GROUP
     */
    public const TV_YEAR_SEASON = '/\w[\-\w.\',;& ]+((19|20)\d\d)[._ -]((s\d{1,2}[._ -]?[bde]\d{1,2}(-?e\d{1,3})?)|(?<!\d)\d{1,2}x\d{2}(?!\d)|ep[._ -]?\d{2})[._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-?DL|WEB-?RIP|REMUX)[\-\w.\',;& ]+\w/i';

    // ========================================================================
    // SPECIAL FORMAT PATTERNS
    // ========================================================================

    /**
     * Daily shows with date format (YYYY.MM.DD or YYYY-MM-DD).
     * Example: Daily.Show.2023.12.15.720p.HDTV-GROUP
     */
    public const DAILY_SHOW = '/\w[\-\w.\',;& ]+(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](720p|1080p|2160p|HDTV|WEB-?DL|WEB-?RIP)[\-\w.\',;& ]+\w/i';

    /**
     * Sports releases with event date.
     * Example: UFC.2023.12.15.1080p.WEB-GROUP
     */
    public const SPORTS = '/\w(19|20)\d\d[._ -]\d{2}[._ -]\d{2}[._ -](IndyCar|F1|Formula[._ -]?1|MotoGP|NBA|NCW([TY])S|NNS|NSCS?|NFL|NHL|MLB|UFC|WWE|Boxing)([._ -](19|20)\d\d)?[\-\w.\',;& ]+\w/i';

    /**
     * Complete season packs.
     * Example: Show.Name.S01.COMPLETE.1080p.WEB-DL-GROUP
     */
    public const COMPLETE_SEASON = '/\w[\-\w.\',;& ]+[._ -]S\d{1,2}[._ -](COMPLETE|FULL)[._ -](720p|1080p|2160p|HDTV|WEB-?DL|WEB-?RIP|BluRay)[\-\w.\',;& ]+\w/i';

    /**
     * Anime releases with episode numbers.
     * Example: Anime.Name.001.1080p.HEVC.10bit-GROUP
     */
    public const ANIME = '/\w[\-\w.\',;& ]+[._ -](\d{2,4})[._ -](480p|720p|1080p|2160p)[._ -](HEVC|x265|x264|H\.?264)[._ -](10bit)?[\-\w.\',;& ]+\w/i';

    // ========================================================================
    // PATTERN COLLECTIONS
    // ========================================================================

    /** @var array<string, string> Priority order is part of the checker contract. */
    public const array ALL_PATTERNS = [
        'STREAMING_4K_HDR' => self::STREAMING_4K_HDR,
        'STREAMING_HD' => self::STREAMING_HD,
        'TV_RES_SOURCE_VCODEC' => self::TV_RES_SOURCE_VCODEC,
        'TV_AUDIO_SOURCE_RES_VCODEC' => self::TV_AUDIO_SOURCE_RES_VCODEC,
        'TV_SOURCE_VCODEC' => self::TV_SOURCE_VCODEC,
        'TV_SOURCE_GROUP' => self::TV_SOURCE_GROUP,
        'TV_YEAR_SEASON' => self::TV_YEAR_SEASON,
        'TV_WITH_YEAR' => self::TV_WITH_YEAR,
        'DAILY_SHOW' => self::DAILY_SHOW,
        'SPORTS' => self::SPORTS,
        'COMPLETE_SEASON' => self::COMPLETE_SEASON,
        'ANIME' => self::ANIME,
    ];

    public const string FFI_CDEF = 'ssize_t nntmux_name_fixing_patterns(uint32_t kind, uint32_t mode, const char *key, size_t key_len, size_t index, char *out, size_t cap);';

    // Single-call FFI must pass all four 1.5x benchmark gates before adoption.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** @return array<string, string> */
    public static function getAllPatterns(): array
    {
        return (self::$useRustFfi ? self::getAllPatternsNative() : null) ?? self::getAllPatternsPhp();
    }

    /** @return array<string, string> */
    public static function getAllPatternsPhp(): array
    {
        return self::ALL_PATTERNS;
    }

    /** @return array<string, string>|null */
    public static function getAllPatternsNative(): ?array
    {
        $fields = self::nativeFields(0);
        if ($fields === null || count($fields) % 2 !== 0) {
            return null;
        }
        $patterns = [];
        for ($i = 0; $i < count($fields); $i += 2) {
            $patterns[$fields[$i]] = $fields[$i + 1];
        }

        return $patterns;
    }

    public static function getPattern(string $name): ?string
    {
        return (self::$useRustFfi ? self::getPatternNative($name) : null) ?? self::getPatternPhp($name);
    }

    public static function getPatternPhp(string $name): ?string
    {
        return self::ALL_PATTERNS[$name] ?? null;
    }

    public static function getPatternNative(string $name): ?string
    {
        $value = self::patternsNative(3, $name);

        return is_string($value) ? $value : null;
    }

    /** @return list<string> */
    public static function getPriorityOrder(): array
    {
        return (self::$useRustFfi ? self::getPriorityOrderNative() : null) ?? self::getPriorityOrderPhp();
    }

    /** @return list<string> */
    public static function getPriorityOrderPhp(): array
    {
        return array_keys(self::ALL_PATTERNS);
    }

    /** @return list<string>|null */
    public static function getPriorityOrderNative(): ?array
    {
        return self::nativeFields(1);
    }

    public static function getPatternCount(): int
    {
        return (self::$useRustFfi ? self::getPatternCountNative() : null) ?? self::getPatternCountPhp();
    }

    public static function getPatternCountPhp(): int
    {
        return count(self::ALL_PATTERNS);
    }

    public static function getPatternCountNative(): ?int
    {
        $value = self::patternsNative(2);

        return is_int($value) ? $value : null;
    }

    public static function getPatternKey(int $index): ?string
    {
        return (self::$useRustFfi ? self::getPatternKeyNative($index) : null) ?? self::getPatternKeyPhp($index);
    }

    public static function getPatternKeyPhp(int $index): ?string
    {
        return self::getPriorityOrderPhp()[$index] ?? null;
    }

    public static function getPatternKeyNative(int $index): ?string
    {
        if ($index < 0) {
            return null;
        }
        $value = self::patternsNative(4, index: $index);

        return is_string($value) ? $value : null;
    }

    private static function patternsNative(int $mode, string $key = '', int $index = 0): string|int|null
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 32768, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_name_fixing_patterns(3, $mode, $key, strlen($key), $index, $buffer, $capacity);
            if (! is_int($written) || $written < 0) {
                return null;
            }
            if ($mode === 2) {
                return $written;
            }

            return $written <= $capacity ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string>|null */
    private static function nativeFields(int $mode): ?array
    {
        $raw = self::patternsNative($mode);
        if (! is_string($raw)) {
            return null;
        }
        $fields = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            if ($length - $offset < 4) {
                return null;
            }
            $size = unpack('Vsize', substr($raw, $offset, 4))['size'];
            $offset += 4;
            if ($size > $length - $offset) {
                return null;
            }
            $fields[] = substr($raw, $offset, $size);
            $offset += $size;
        }

        return $fields;
    }

    /**
     * Get streaming service patterns only.
     *
     * @return array<string, mixed>
     */
    public static function getStreamingPatterns(): array
    {
        return [
            'STREAMING_4K_HDR' => self::STREAMING_4K_HDR,
            'STREAMING_HD' => self::STREAMING_HD,
        ];
    }

    /**
     * Get standard TV patterns.
     *
     * @return array<string, mixed>
     */
    public static function getStandardPatterns(): array
    {
        return [
            'TV_RES_SOURCE_VCODEC' => self::TV_RES_SOURCE_VCODEC,
            'TV_AUDIO_SOURCE_RES_VCODEC' => self::TV_AUDIO_SOURCE_RES_VCODEC,
            'TV_SOURCE_VCODEC' => self::TV_SOURCE_VCODEC,
            'TV_SOURCE_GROUP' => self::TV_SOURCE_GROUP,
            'TV_YEAR_SEASON' => self::TV_YEAR_SEASON,
            'TV_WITH_YEAR' => self::TV_WITH_YEAR,
        ];
    }
}
