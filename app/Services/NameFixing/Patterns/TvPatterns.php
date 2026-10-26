<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Patterns;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

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

    // ========================================================================
    // WAVE 57.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean 9.79x, p99 7.93x; uncommon mean 4.60x, p99 4.09x); see RustNameFixingPatternsAppGameMovieTvNameFixResultW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_tv_pattern_row_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per tvPatternRowBatch() record. */
    public const int W57_ROW_WORDS = 9;

    /** Source, video codec and audio codec tokens (lowercase); bit order is the row contract. */
    private const array W57_SOURCES = ['bluray', 'blu-ray', 'bdrip', 'brrip', 'remux', 'web-dl', 'webdl', 'webrip', 'web-rip',
        'web', 'hdtv', 'pdtv', 'sdtv', 'hdrip', 'dvdrip', 'dvd', 'dvdscr', 'cam', 'ts', 'vhs', 'vod', 'hdcam'];

    private const array W57_VIDEO_CODECS = ['x264', 'x265', 'h264', 'h.264', 'h265', 'h.265', 'hevc', 'avc', 'xvid', 'divx',
        'av1', 'mpeg2', 'wmv', 'vp9'];

    private const array W57_AUDIO_CODECS = ['aac', 'ac3', 'dd5.1', 'ddp5.1', 'dts', 'dts-hd', 'truehd', 'atmos', 'eac3',
        'flac', 'mp3', 'opus'];

    /** Resolution tokens (alphanumeric runs, lowercase) and their heights. */
    private const array W57_RESOLUTIONS = ['480p' => 480, '480i' => 480, '480' => 480, '576p' => 576, '576i' => 576,
        '576' => 576, '720p' => 720, '720' => 720, '1080p' => 1080, '1080i' => 1080, '1080' => 1080, '2160p' => 2160,
        '2160' => 2160, '4k' => 2160, 'uhd' => 2160];

    private const array W57_YEAR = ['19', '20'];

    private const array W57_RES = ['480', '720', '1080', '2160'];

    private const array W57_SRC = ['bd', 'blu', 'cam', 'dtv', 'dvd', 'h', 'p', 's', 'r5', 'vcd', 'vod', 'web', 'remux'];

    private const array W57_VCODEC = ['divx', '264', '265', 'hevc', 'mpeg2', 'xvid', 'wmv', 'av1'];

    private const array W57_ACODEC = ['aac', 'ac3', 'ac-3', 'dd5', 'dts', 'dolby', 'mp3', 'truehd', 'atmos', 'eac3'];

    private const array W57_SERVICES = ['amzn', 'atvp', 'dsnp', 'hmax', 'hulu', 'it', 'nf', 'pmtp', 'pcok', 'roku', 'stan', 'vudu'];

    /** Episode markers: [S|]..[E|x].. and s..e.. (pipe form) or s..[bde].. and ..x.. (x form), or ep... */
    private const array W57_EP_PIPE = ['s', '|', 'ep'];

    private const array W57_EP_X = ['s', 'x', 'ep'];

    /**
     * Lowercase needle groups per ALL_PATTERNS entry, in priority order. A pattern can match
     * only when every group has a needle somewhere in the lowercased name.
     */
    private const array W57_PRESCREEN = [
        [self::W57_EP_PIPE, ['2160p', '4k', 'uhd'], self::W57_SERVICES, ['web'], ['hevc', '265']],
        [self::W57_EP_PIPE, ['1080p', '720p'], self::W57_SERVICES, ['web']],
        [self::W57_EP_X, self::W57_RES, self::W57_SRC, self::W57_VCODEC],
        [self::W57_EP_X, self::W57_ACODEC, self::W57_SRC, self::W57_RES, self::W57_VCODEC],
        [self::W57_EP_X, self::W57_SRC, self::W57_VCODEC],
        [self::W57_EP_PIPE, self::W57_SRC],
        [self::W57_YEAR, self::W57_EP_X, self::W57_SRC],
        [self::W57_EP_X, self::W57_YEAR],
        [self::W57_YEAR, ['720p', '1080p', '2160p', 'hdtv', 'web']],
        [self::W57_YEAR, ['indycar', 'f1', 'formula', 'motogp', 'nba', 'ncw', 'nns', 'nsc', 'nfl', 'nhl', 'mlb', 'ufc', 'wwe', 'boxing']],
        [['s'], ['complete', 'full'], ['720p', '1080p', '2160p', 'hdtv', 'web', 'bluray']],
        [['480p', '720p', '1080p', '2160p'], ['hevc', '265', '264']],
    ];

    /**
     * TV rows for candidate release names. Each record yields nine words: the pre-screen mask
     * (bit i: ALL_PATTERNS entry i can match), season and episode from the first
     * /(?<![a-z0-9])s([0-9]{1,2})[._ -]?e([0-9]{1,3})/ or else /(?<![0-9])([0-9]{1,2})x([0-9]{2,3})(?![0-9])/
     * on the lowercased name (or -1), the first (19|20)YY[._ -]MM[._ -]DD air date as YYYYMMDD
     * (or -1), the first resolution token's height (or -1), the source mask and the codec mask
     * (video bits 0-15, audio bits 16-31) of delimited tokens (ASCII case-insensitive,
     * neighbours not ASCII alphanumeric), the first candidate pattern index (or -1) and the
     * score 8 * candidates + sources + codecs + 4 per found episode, air date and resolution.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function tvPatternRowBatch(array $names): array
    {
        return self::usesW57RustFfi() ? self::tvPatternRowBatchNative($names) : self::tvPatternRowBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function tvPatternRowBatchPhp(array $names): array
    {
        self::w57Names($names);
        $out = [];
        foreach ($names as $name) {
            $lower = strtolower($name);
            $list = preg_split('/[^a-z0-9]+/', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $runs = array_flip($list);
            $pre = self::w57Prescreen($lower);
            if (preg_match('/(?<![a-z0-9])s([0-9]{1,2})[._ -]?e([0-9]{1,3})/', $lower, $m) === 1
                || preg_match('/(?<![0-9])([0-9]{1,2})x([0-9]{2,3})(?![0-9])/', $lower, $m) === 1) {
                [$season, $episode] = [(int) $m[1], (int) $m[2]];
            } else {
                [$season, $episode] = [-1, -1];
            }
            $airdate = preg_match('/(?<![0-9])((?:19|20)[0-9]{2})[._ -]([0-9]{2})[._ -]([0-9]{2})(?![0-9])/', $lower, $m) === 1
                ? (int) ($m[1].$m[2].$m[3])
                : -1;
            $resolution = -1;
            foreach ($list as $run) {
                if (isset(self::W57_RESOLUTIONS[$run])) {
                    $resolution = self::W57_RESOLUTIONS[$run];
                    break;
                }
            }
            $sources = self::w57TokenMask($lower, $runs, self::W57_SOURCES);
            $codecs = self::w57TokenMask($lower, $runs, self::W57_VIDEO_CODECS)
                | (self::w57TokenMask($lower, $runs, self::W57_AUDIO_CODECS) << 16);
            array_push(
                $out,
                $pre,
                $season,
                $episode,
                $airdate,
                $resolution,
                $sources,
                $codecs,
                $pre === 0 ? -1 : self::w57LowestBit($pre),
                8 * self::w57Bits($pre) + self::w57Bits($sources) + self::w57Bits($codecs)
                    + ($season >= 0 ? 4 : 0) + ($airdate >= 0 ? 4 : 0) + ($resolution >= 0 ? 4 : 0),
            );
        }

        return $out;
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function tvPatternRowBatchNative(array $names): array
    {
        self::w57Names($names);
        $count = \count($names);
        if ($count === 0) {
            return [];
        }
        $lens = [];
        foreach ($names as $name) {
            $lens[] = \strlen($name);
        }

        return self::w57Call(implode('', $names), pack('P*', ...$lens), $count, $count * self::W57_ROW_WORDS)
            ?? self::tvPatternRowBatchPhp($names);
    }

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W57 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('enabled');
        self::w57RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $names */
    private static function w57Names(array $names): void
    {
        if (! array_is_list($names)) {
            throw new \InvalidArgumentException('W57 batch names must be a list.');
        }
    }

    private static function w57Prescreen(string $lower): int
    {
        $mask = 0;
        foreach (self::W57_PRESCREEN as $bit => $groups) {
            foreach ($groups as $group) {
                foreach ($group as $needle) {
                    if (str_contains($lower, $needle)) {
                        continue 2;
                    }
                }

                continue 2;
            }
            $mask |= 1 << $bit;
        }

        return $mask;
    }

    /**
     * Bit j for each delimited occurrence of $tokens[j]: alphanumeric tokens must equal a run,
     * others need non-alphanumeric neighbours.
     *
     * @param  array<array-key, int>  $runs
     * @param  list<string>  $tokens
     */
    private static function w57TokenMask(string $lower, array $runs, array $tokens): int
    {
        $mask = 0;
        foreach ($tokens as $bit => $token) {
            if (isset($runs[$token]) || (! ctype_alnum($token) && self::w57Delimited($lower, $token))) {
                $mask |= 1 << $bit;
            }
        }

        return $mask;
    }

    private static function w57Delimited(string $lower, string $token): bool
    {
        $length = \strlen($token);
        $offset = 0;
        while (($pos = strpos($lower, $token, $offset)) !== false) {
            $before = $pos === 0 ? '' : $lower[$pos - 1];
            $after = $lower[$pos + $length] ?? '';
            if (strspn($before, 'abcdefghijklmnopqrstuvwxyz0123456789') === 0
                && strspn($after, 'abcdefghijklmnopqrstuvwxyz0123456789') === 0) {
                return true;
            }
            $offset = $pos + 1;
        }

        return false;
    }

    private static function w57Bits(int $mask): int
    {
        return substr_count(decbin($mask), '1');
    }

    private static function w57LowestBit(int $mask): int
    {
        return \strlen(decbin($mask & -$mask)) - 1;
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w57Call(string $blob, string $ints, int $count, int $need): ?array
    {
        try {
            /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
            $state = self::w57RustFfiState();
            $ffi = $state['ffi'];
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w57', self::W57_FFI_CDEF) ?? false;
                self::w57RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w57RustFfiState('out', $out);
                self::w57RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W57_FFI_CDEF.)
            $written = $ffi->nntmux_w57_tv_pattern_row_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * W57 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
