<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Extractors;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\FileNameCleaner;
use App\Support\RustPipelineLibrary;
use FFI\CData;

/**
 * Extracts release names from file names and paths.
 *
 * Handles various filename patterns for scene releases, streaming services,
 * video files, music, games, ebooks, and more.
 */
class FileNameExtractor
{
    /**
     * PreDB regex pattern for scene release names.
     */
    public const PREDB_REGEX = '/([\w.\'()\[\]-]+(?:[\s._-]+[\w.\'()\[\]-]+)+[-.][\w]+)/ui';

    protected FileNameCleaner $cleaner;

    private ?string $libraryPath = null;

    public function __construct(?FileNameCleaner $cleaner = null, ?string $libraryPath = null)
    {
        $this->cleaner = $cleaner ?? new FileNameCleaner;
        $this->libraryPath = $libraryPath;
    }

    /**
     * Extract a release name from a filename.
     */
    public function extractFromFile(string $filename): ?NameFixResult
    {
        if ($filename === '') {
            return null;
        }

        if (get_class($this) === self::class && get_class($this->cleaner) === FileNameCleaner::class) {
            [$handled, $result] = $this->extractFromFileRust($filename);
            if ($handled) {
                return $result;
            }
        }

        return $this->extractFromFilePhp($filename);
    }

    private static bool $ffiResolved = false;

    private static ?\FFI $defaultFfi = null;

    private static ?CData $defaultBuf = null;

    private static int $defaultCap = 0;

    private function resolveFfi(): ?\FFI
    {
        if ($this->libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('nfo_extractors', NfoNameExtractor::FFI_CDEF, $this->libraryPath);
        }
        if (! self::$ffiResolved) {
            self::$defaultFfi = RustPipelineLibrary::resolveDomain('nfo_extractors', NfoNameExtractor::FFI_CDEF);
            if (self::$defaultFfi !== null) {
                self::$defaultBuf = RustPipelineLibrary::obtainScratch(self::$defaultFfi, 4096, self::$defaultCap);
            }
            self::$ffiResolved = true;
        }

        return self::$defaultFfi;
    }

    /**
     * @return array{0: bool, 1: ?NameFixResult}
     */
    private function extractFromFileRust(string $filename): array
    {
        $ffi = $this->resolveFfi();
        if ($ffi === null) {
            return [false, null];
        }

        try {
            $len = strlen($filename);
            if ($this->libraryPath === null && self::$defaultBuf !== null && $len + 64 <= self::$defaultCap) {
                $buf = self::$defaultBuf;
                $cap = self::$defaultCap;
            } else {
                $cap = 0;
                $buf = RustPipelineLibrary::obtainScratch($ffi, max(512, min(16384, $len + 64)), $cap);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_filename_extract_candidate($filename, $len, $buf, $cap);
            if ($written <= -16) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_filename_extract_candidate($filename, $len, $buf, $cap);
            }
            if ($written === 0) {
                return [true, null];
            }
            if ($written > 0) {
                $raw = \FFI::string($buf, $written);
                $nul = strpos($raw, "\0");
                if ($nul !== false) {
                    return [
                        true,
                        NameFixResult::fromMatch(
                            substr($raw, 0, $nul),
                            substr($raw, $nul + 1),
                            'File',
                        ),
                    ];
                }
            }
        } catch (\Throwable) {
            return [false, null];
        }

        return [false, null];
    }

    public function extractFromFilePhp(string $filename): ?NameFixResult
    {
        $result = [];
        $cleanedFilename = $this->cleaner->cleanForTitleMatch($filename);

        // Try each pattern in order of specificity

        if (str_contains($filename, '__NZBSPLIT__')) {
            $unwrapped = $this->cleaner->extractNzbSplitName($filename);

            if ($unwrapped !== null && $this->cleaner->isPlausibleReleaseTitlePhp($unwrapped)) {
                return NameFixResult::fromMatch($unwrapped, 'NZBSPLIT wrapper', 'File');
            }

            return null;
        }

        $basename = $this->cleaner->extractFilenameFromPath($filename);
        $literalTitle = $this->cleaner->stripKnownFileSuffixesPhp($basename, preserveCodecAfterWrapper: true);
        if ($literalTitle !== $basename
            && preg_match('/\.(?:mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|webm|ogv|3gp|asf|rm|rmvb|f4v|mp3|flac|m4a|aac|ogg|wav|wma|ape|opus|mka|ac3|dts|eac3|truehd|mpc|shn|tak|tta|wv|rar|r\d{2,4}|zip|z\d{2}|7z|par2?|tar|gz|bz2|xz|lz|lzma|zst|tgz|lz4|rev|enc|\d{3})$/iD', $basename)
            && $this->cleaner->isLiteralTechnicalSceneTitle($literalTitle)
        ) {
            return NameFixResult::fromMatch($literalTitle, 'Complete literal basename', 'File');
        }

        // Scene TV release with group suffix
        if (preg_match('/^(.+?(x264|x265|HEVC|XviD|H\.?264|H\.?265)\-[A-Za-z0-9]+)\\\\/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Scene release with group', 'File');
        }

        // TVP group format
        if (preg_match('/^(.+?(x264|XviD)\-TVP)\\\\/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'TVP', 'File');
        }

        // Generic TV - SxxExx format with quality/source info
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?E\d{1,3}(?:[.-_ ]?E\d{1,3})?[.-_ ].+?(?:720p|1080p|2160p|4K|HDTV|WEB-?DL|WEB-?RIP|BluRay|AMZN|HMAX|NF|DSNP).+?)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], 'TV SxxExx with quality', 'File');
        }

        // Generic TV - any SxxExx format
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], 'Generic TV', 'File');
        }

        // 4K/UHD Movies - modern formats
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?[\.\-_ ](19|20)\d\d[\.\-_ ].+?(2160p|4K|UHD).+?(HDR10?\+?|DV|Dolby[\.\-_ ]?Vision)?.+?(HEVC|x265|H\.?265).+?)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], '4K/UHD Movie', 'File');
        }

        // HD Movies with modern codecs
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?[\.\-_ ](19|20)\d\d[\.\-_ ].+?(720p|1080p).+?(BluRay|WEB-?DL|WEB-?RIP|BDRip|REMUX).+?(x264|x265|HEVC|H\.?264|H\.?265|AVC).+?)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], 'HD Movie modern codec', 'File');
        }

        // Standard HD Movies
        if (preg_match('/^(\\\\|\/)?(.+(\\\\|\/))*(.+?([\.\-_ ]\d{4}[\.\-_ ].+?(BDRip|bluray|DVDRip|XVID|WEB-?DL|HDTV)).+)\.(.+)$/iu', $filename, $result)) {
            return NameFixResult::fromMatch($result[4], 'Generic movie 1', 'File');
        }

        if (preg_match('/^([a-z0-9\.\-_]+(19|20)\d\d[a-z0-9\.\-_]+[\.\-_ ](720p|1080p|2160p|4K|BDRip|bluray|DVDRip|x264|x265|XviD|HEVC)[a-z0-9\.\-_]+)\.[a-z]{2,}$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Generic movie 2', 'File');
        }

        // Streaming service releases
        if (preg_match('/^([A-Za-z0-9\.\-_]+[\.\-_ ](AMZN|ATVP|DSNP|HMAX|HULU|iT|NF|PMTP|PCOK|ROKU|STAN|TVNZ|VUDU)[\.\-_ ].+?(WEB-?DL|WEB-?RIP).+?)\.(.+)$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Streaming service release', 'File');
        }

        // Music releases
        if (preg_match('/(.+?([\.\-_ ](CD|FM)|[\.\-_ ]\dCD|CDR|FLAC|SAT|WEB).+?(19|20)\d\d.+?)\\\\.+/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Generic music', 'File');
        }

        if (preg_match('/^(.+?(19|20)\d\d\-([a-z0-9]{3}|[a-z]{2,}|C4))\\\\/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'music groups', 'File');
        }

        // FLAC music releases
        if (preg_match('/^(.+?[\.\-_ ](FLAC|MP3|AAC|OGG)[\.\-_ ].+?[\.\-_ ]\d{4}[\.\-_ ].+?\-[A-Za-z0-9]+)[\\\\\/.]/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Music with codec', 'File');
        }

        // Movie with year in parentheses - AVI format
        if (preg_match('/.+\\\\(.+\((19|20)\d\d\)\.avi)$/i', $filename, $result)) {
            $newName = str_replace('.avi', ' DVDRip XVID NoGroup', $result[1]);

            return NameFixResult::fromMatch($newName, 'Movie (year) avi', 'File');
        }

        // Movie with year in parentheses - ISO format
        if (preg_match('/.+\\\\(.+\((19|20)\d\d\)\.iso)$/i', $filename, $result)) {
            $newName = str_replace('.iso', ' DVD NoGroup', $result[1]);

            return NameFixResult::fromMatch($newName, 'Movie (year) iso', 'File');
        }

        // Movie with year in parentheses - MKV format
        if (preg_match('/.+\\\\(.+\((19|20)\d\d\)\.(mkv|mp4|m4v))$/i', $filename, $result)) {
            $newName = preg_replace('/\.(mkv|mp4|m4v)$/i', ' BDRip x264 NoGroup', $result[1]);

            return NameFixResult::fromMatch($newName, 'Movie (year) mkv/mp4', 'File');
        }

        // RAR file contents - look for release name in RAR path
        if ((strpbrk($filename, '\\/') !== false)
            && preg_match('/^([A-Za-z0-9][\w.\-]+(?:[\.\-_ ][\w.\-]+)+)[\\\\\\/](?:CD\d|Disc\d|DVD\d|Subs?)?[\\\\\\/]?.+\.(rar|r\d{2,4}|zip|7z)$/i', $filename, $result)
        ) {
            return NameFixResult::fromMatch($result[1], 'RAR archive path', 'File');
        }

        // Scene release in RAR
        if (preg_match('/^([A-Za-z0-9][\w.\-]+\-[A-Za-z0-9]+)[\\\\\\/].+\.(rar|r\d{2,4})$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Scene RAR release', 'File');
        }

        // XXX Imagesets
        if (preg_match('/^(.+?IMAGESET.+?)\\\\.+/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'XXX Imagesets', 'File');
        }

        // VIDEOOT releases
        if (preg_match('/^VIDEOOT-[A-Z0-9]+\\\\([\w!.,& ()\[\]\'\`-]{8,}?\b.?)([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z)?(\d{1,3}\.rev|\.vol.+?|\.mp4)/', $filename, $result)) {
            return NameFixResult::fromMatch($result[1].' XXX DVDRIP XviD-VIDEOOT', 'XXX XviD VIDEOOT', 'File');
        }

        // XXX SDPORN
        if (preg_match('/^.+?SDPORN/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'XXX SDPORN', 'File');
        }

        // R&C releases
        if (preg_match('/\w[\-\w.\',;& ]+1080i[._ -]DD5[._ -]1[._ -]MPEG2-R&C(?=\.ts)$/i', $filename, $result)) {
            $newResult = str_replace('MPEG2', 'MPEG2.HDTV', $result[0]);

            return NameFixResult::fromMatch($newResult, 'R&C', 'File');
        }

        // NhaNc3 releases
        if (preg_match('/\w[\-\w.\',;& ]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](480|720|1080)[ip][._ -](BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)[._ -]nSD[._ -](DivX|[HX][._ -]?264|MPEG2|XviD(HD)?|WMV)[._ -]NhaNC3[\-\w.\',;& ]+\w/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'NhaNc3', 'File');
        }

        // TVP releases (alternate pattern)
        if (preg_match('/\wtvp-[\w.\',;]+((s\d{1,2}[._ -]?[bde]\d{1,2})|\d{1,2}x\d{2}|ep[._ -]?\d{2})[._ -](720p|1080p|xvid)(?=\.(avi|mkv))$/i', $filename, $result)) {
            $newResult = str_replace('720p', '720p.HDTV.X264', $result[0]);
            $newResult = str_replace('1080p', '1080p.Bluray.X264', $newResult);
            $newResult = str_replace('xvid', 'XVID.DVDrip', $newResult);

            return NameFixResult::fromMatch($newResult, 'tvp', 'File');
        }

        // LOL releases
        if (preg_match('/\w[\-\w.\',;& ]+\d{3,4}\.hdtv-lol\.(avi|mp4|mkv|ts|nfo|nzb)/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'Title.211.hdtv-lol.extension', 'File');
        }

        // DL releases
        if (preg_match('/\w[\-\w.\',;& ]+-S\d{1,2}[EX]\d{1,2}-XVID-DL\.avi/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'Title-SxxExx-XVID-DL.avi', 'File');
        }

        // Title - SxxExx - Episode title format
        if (preg_match('/\S.*[\w.\-\',;]+\s\-\ss\d{2}[ex]\d{2}\s\-\s[\w.\-\',;].+\./i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], 'Title - SxxExx - Eptitle', 'File');
        }

        // Nintendo DS
        if (preg_match('/\w.+?\)\.nds$/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[0], ').nds Nintendo DS', 'File');
        }

        // Nintendo 3DS
        if (preg_match('/3DS_\d{4}.+\d{4} - (.+?)\.3ds/i', $filename, $result)) {
            return NameFixResult::fromMatch('3DS '.$result[1], '.3ds Nintendo 3DS', 'File');
        }

        // Nintendo Switch
        if (preg_match('/^(.+?)\[[\w]+\]\.(?:nsp|xci|nsz)$/i', $filename, $result)) {
            return NameFixResult::fromMatch(trim($result[1]).' Switch', 'Nintendo Switch', 'File');
        }

        // PlayStation/Xbox game releases
        if (preg_match('/^(.+?[\.\-_ ](PS[345P]|PSV|XBOX360|XBOXONE|NSW)[\.\-_ ].+?\-[A-Za-z0-9]+)[\\\\\/.]/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Console game release', 'File');
        }

        // EBooks
        if (preg_match('/\w.+?\.(epub|mobi|azw3?|opf|fb2|prc|djvu|cb[rz])/i', $filename, $result)) {
            $newResult = str_replace('.'.$result[1], ' ('.$result[1].')', $result[0]);

            return NameFixResult::fromMatch($newResult, 'EBook', 'File');
        }

        // Audiobooks
        if (preg_match('/^(.+?[\.\-_ ]Audiobook[\.\-_ ].+?)[\\\\\/.]/i', $filename, $result)) {
            return NameFixResult::fromMatch($result[1], 'Audiobook', 'File');
        }

        // Scene release from cleaned filename
        if (preg_match('/^([A-Za-z0-9][\w.\-]+\-[A-Za-z0-9]{2,15})$/i', $cleanedFilename, $result) && preg_match(self::PREDB_REGEX, $cleanedFilename)) {
            return NameFixResult::fromMatch($result[1], 'Cleaned scene name', 'File');
        }

        $basename = basename(str_replace('\\', '/', $filename));
        $candidate = $this->cleaner->stripKnownFileSuffixesPhp($basename, preserveCodecAfterWrapper: true);

        if ($candidate !== $basename
            && preg_match('/\.(?:mkv|avi|mp4|m4v|ts|m2ts|rar|r\d{2,4}|7z|par2|nfo|sfv|md5|sha1)$/i', $basename)
            && preg_match('/^[A-Za-z0-9 ._()\[\]@+,!\'&-]+$/', $candidate)
            && preg_match('/^(.+?)[ ._(\[](?:19|20)\d{2}(?=$|[ ._)\]-])/u', $candidate, $result)
            && preg_match('/[A-Za-z]{3}/', (string) preg_replace('/^(?:\[[^\]]+\]\s*)+/', '', $result[1]))
            && preg_match('/(?<![a-z0-9])(?:720p|1080p|2160p)(?![a-z0-9])/i', $candidate)
            && preg_match('/(?<![a-z0-9])(?:HDTV(?:Light)?|BluRay|BDRip|WEB[- .]?DL|WEBRip|BD)(?![a-z0-9])/i', $candidate)
            && preg_match('/(?<![a-z0-9])(?:x264|x265|HEVC|H[ .]?264|H[ .]?265|XviD)(?![a-z0-9])/i', $candidate)
            && $this->cleaner->isPlausibleReleaseTitlePhp($this->cleaner->normalizeCandidateTitle($candidate))
        ) {
            return NameFixResult::fromMatch($candidate, 'Technical video basename', 'File');
        }

        $parent = basename(dirname(str_replace('\\', '/', $filename)));
        if ($parent === $candidate
            && preg_match('/\.(?:mkv|avi|mp4|m4v)$/i', $basename)
            && preg_match('/^[A-Za-z0-9 ._()\[\]@+,!\'&-]+$/', $candidate)
            && preg_match('/^(.+?)[ ._(\[](?:19|20)\d{2}(?=$|[ ._)\]-])/u', $candidate, $result)
            && preg_match('/[A-Za-z]{3}/', (string) preg_replace('/^(?:\[[^\]]+\]\s*)+/', '', $result[1]))
            && preg_match('/(?<![a-z0-9])(?:720p|1080p|2160p)(?![a-z0-9])/i', $candidate)
            && $this->cleaner->isPlausibleReleaseTitlePhp($this->cleaner->normalizeCandidateTitle($candidate))
        ) {
            return NameFixResult::fromMatch($candidate, 'Matching video parent basename', 'File');
        }

        // Folder name fallback
        if (preg_match('/\w+[\-\w.\',;& ]+$/i', $filename, $result) && preg_match(self::PREDB_REGEX, $filename)) {
            return NameFixResult::fromMatch($result[0], 'Folder name', 'File');
        }

        return null;
    }

    // ---- Wave 58.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 4.06x/3.67x, uncommon 3.64x/3.59x); see RustConstrainedPredbFileNameNfoObfuscatedNameCheckerW58ParityTest. */
    private const bool W58_RUST_FFI_DEFAULT = true;

    private const string W58_FFI_CDEF = <<<'C'
        intptr_t nntmux_w58_filename_candidate_plan_batch(const char *names, size_t names_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 58 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w58', self::W58_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w58RustFfiState('ffi');
                    $buffer = $resolved instanceof \FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
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

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planFileNameBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w58Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w58Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', \FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** Leftmost archive suffix of the lowercased basename; group 1 marks part/volume suffixes. */
    private const string W58_ARCHIVE = '/\.(?:(part[0-9]{1,4}\.rar|vol[0-9]{1,4}[+-][0-9]{1,4}\.par2|7z\.[0-9]{3}|r[0-9]{2,3}|z[0-9]{2}|[0-9]{3})|rar|zip|7z|par2|sfv|nzb)\z/';

    private const array W58_MEDIA = ['mkv' => true, 'mp4' => true, 'avi' => true, 'm4v' => true, 'ts' => true, 'm2ts' => true,
        'iso' => true, 'nfo' => true, 'srt' => true, 'idx' => true, 'sub' => true];

    private const array W58_JUNK = ['sample' => true, 'proof' => true, 'subs' => true, 'thumbs' => true, 'screens' => true,
        'cover' => true, 'extras' => true];

    private const string W58_ALNUM = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Batch plan over inner archive filenames. The basename (after the last `/` or `\`) loses
     * its leftmost archive suffix (flag 1; flag 16 for `.partN.rar`, `.volN+N.par2`, `.7z.NNN`,
     * `.rNN`, `.zNN` and `.NNN` parts) and then one media extension (flag 2); `_` and spaces
     * become dots, dot runs collapse and dots/dashes are trimmed (flag 64 when that changed the
     * stem). Flag 4: a junk token (sample, proof, subs, thumbs, screens, cover, extras); 8
     * generic (under 8 bytes, only hex digits, or fewer than two `[a-z0-9]` tokens); 32 a
     * scene-shaped candidate (neither, with a `-GROUP` of 2-15 alphanumerics after offset 0).
     * Per filename `flags | min(len, 0xFFFFFF) << 8 | crc32(candidate) << 32`; then the scene,
     * junk, generic and archive counts, the distinct scene candidates and the XXH64 digest of
     * all earlier words.
     *
     * @param  list<string>  $filenames
     * @return list<int>
     */
    public static function planFileNameBatch(array $filenames): array
    {
        return self::usesW58RustFfi() ? self::planFileNameBatchNative($filenames) : self::planFileNameBatchPhp($filenames);
    }

    /**
     * @param  list<string>  $filenames
     * @return list<int>
     */
    public static function planFileNameBatchPhp(array $filenames): array
    {
        $out = $seen = [];
        $scene = $junk = $generic = $archive = 0;
        foreach ($filenames as $filename) {
            $filename = (string) $filename;
            $base = substr($filename, \strlen($filename) - strcspn(strrev($filename), '/\\'));
            $lower = strtolower($base);
            $flags = 0;
            $cut = \strlen($base);
            if (preg_match(self::W58_ARCHIVE, $lower, $m, PREG_OFFSET_CAPTURE) === 1) {
                $cut = $m[0][1];
                $flags |= isset($m[1]) && $m[1][1] >= 0 ? 17 : 1;
            }
            $dot = strrpos(substr($lower, 0, $cut), '.');
            if ($dot !== false && isset(self::W58_MEDIA[substr($lower, $dot + 1, $cut - $dot - 1)])) {
                $cut = $dot;
                $flags |= 2;
            }
            $stem = substr($base, 0, $cut);
            $candidate = trim((string) preg_replace('/\.{2,}/', '.', strtr($stem, '_ ', '..')), '.-');
            if ($candidate !== $stem) {
                $flags |= 64;
            }
            $candidateLower = strtolower($candidate);
            $tokens = preg_split('/[^a-z0-9]+/', $candidateLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $isJunk = false;
            foreach ($tokens as $token) {
                if (isset(self::W58_JUNK[$token])) {
                    $isJunk = true;
                    break;
                }
            }
            $length = \strlen($candidate);
            $isGeneric = $length < 8 || strspn($candidateLower, '0123456789abcdef') === $length || \count($tokens) < 2;
            if ($isJunk) {
                $flags |= 4;
                $junk++;
            }
            if ($isGeneric) {
                $flags |= 8;
                $generic++;
            }
            $p = strrpos($candidate, '-');
            if (! $isJunk && ! $isGeneric && $p !== false && $p > 0 && ($g = $length - $p - 1) >= 2 && $g <= 15
                && strspn($candidate, self::W58_ALNUM, $p + 1) === $g) {
                $flags |= 32;
                $scene++;
                $seen[$candidate] = true;
            }
            $archive += $flags & 1;
            $out[] = $flags | min($length, 0xFFFFFF) << 8 | crc32($candidate) << 32;
        }
        array_push($out, $scene, $junk, $generic, $archive, \count($seen));
        $out[] = self::w58Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a filename holds a NUL byte or
     * the column is not a list.
     *
     * @param  list<string>  $filenames
     * @return list<int>
     */
    public static function planFileNameBatchNative(array $filenames): array
    {
        $count = \count($filenames);
        $words = $count + 6;
        /** @var array{\FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w58RustFfiState('out', $words);
        if ($state === null || ! array_is_list($filenames)) {
            return self::planFileNameBatchPhp($filenames);
        }
        $blob = implode("\0", $filenames);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_filename_candidate_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }

        return self::w58Words($written, $out, $words) ?? self::planFileNameBatchPhp($filenames);
    }
}
