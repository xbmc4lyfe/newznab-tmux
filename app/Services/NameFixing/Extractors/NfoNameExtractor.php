<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Extractors;

use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use FFI\CData;

/**
 * Extracts release names from NFO content.
 *
 * Handles various NFO parsing patterns for TV, Movies, Music, Games, etc.
 */
class NfoNameExtractor
{
    public const FFI_CDEF = <<<'CDEF'
        ssize_t nntmux_nfo_predb_candidates(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_pattern_match(const char *text, size_t text_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nfo_extract_pattern_mode(const char *text, size_t text_len, uint32_t mode, char *out_buf, size_t out_cap);
        ssize_t nntmux_filename_extract_candidate(const char *filename, size_t filename_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_file_extract_candidate(const char *filename, size_t filename_len, char *out_buf, size_t out_cap);
    CDEF;

    public const TAGS_FFI_CDEF = <<<'CDEF'
        ssize_t nntmux_nfo_append_title_year_tags(const char *, size_t, const char *, size_t, char *, size_t);
        ssize_t nntmux_nfo_append_language(const char *, size_t, const char *, size_t, char *, size_t);
        ssize_t nntmux_nfo_append_resolution(const char *, size_t, const char *, size_t, char *, size_t);
        ssize_t nntmux_nfo_append_source(const char *, size_t, const char *, size_t, char *, size_t);
        ssize_t nntmux_nfo_append_audio_codec(const char *, size_t, const char *, size_t, char *, size_t);
    CDEF;

    private static bool $tagsFfiResolved = false;

    private static ?\FFI $tagsFfi = null;

    private function appendTagRust(string $symbol, string $name, string $nfo): ?string
    {
        try {
            if ($this->libraryPath !== null) {
                $ffi = RustPipelineLibrary::resolveDomain('nfo_title_year_tags', self::TAGS_FFI_CDEF, $this->libraryPath);
            } else {
                if (! self::$tagsFfiResolved) {
                    self::$tagsFfi = RustPipelineLibrary::resolveDomain('nfo_title_year_tags', self::TAGS_FFI_CDEF);
                    self::$tagsFfiResolved = true;
                }
                $ffi = self::$tagsFfi;
            }
            if ($ffi === null) {
                return null;
            }
            $cap = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($name) + 512, $cap);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol($name, strlen($name), $nfo, strlen($nfo), $buffer, $cap);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($name, strlen($name), $nfo, strlen($nfo), $buffer, $cap);
            }

            return is_int($written) && $written >= 0 && $written <= $cap ? \FFI::string($buffer, $written) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private ?string $libraryPath = null;

    private ?string $lastRawNfo = null;

    private ?string $lastIdentityText = null;

    public function __construct(?string $libraryPath = null)
    {
        $this->libraryPath = $libraryPath;
    }

    /**
     * Exclude upstream Source fields from release identity evidence.
     *
     * Keep line boundaries, and stop indented continuations at a new field or
     * blank line. The original NFO remains available for technical metadata.
     */
    public function identityText(string $nfoContent): string
    {
        if ($this->lastRawNfo === $nfoContent && $this->lastIdentityText !== null) {
            return $this->lastIdentityText;
        }

        $native = RustPipelineLibrary::nfoIdentityText($nfoContent, $this->libraryPath);
        if ($native !== null) {
            $this->lastRawNfo = $nfoContent;
            $this->lastIdentityText = $native;

            return $native;
        }

        $php = $this->identityTextPhp($nfoContent);
        $this->lastRawNfo = $nfoContent;
        $this->lastIdentityText = $php;

        return $php;
    }

    public function identityTextPhp(string $nfoContent): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $nfoContent);
        if ($lines === false) {
            return '';
        }
        $sourceField = false;
        foreach ($lines as &$line) {
            if (preg_match('/^[ \t]*Source[ \t]*:/i', $line)) {
                $sourceField = true;
                $line = '';

                continue;
            }
            if ($sourceField && preg_match('/^[ \t]+\S/', $line)
                && (! preg_match('/^[ \t]*[A-Za-z][A-Za-z0-9 _-]*[ \t]*:/', $line)
                    || preg_match('~^[ \t]*[A-Za-z]:[\\\\/]~', $line))) {
                $line = '';

                continue;
            }
            $sourceField = false;
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * A bounded Source-safe view for authoritative lookup. Declared filename
     * paths and suffixes use the same literal cleanup as filename matching.
     *
     * @return array{titles: list<string>, complete: bool}
     */
    public function predbCandidates(string $nfoContent): array
    {
        if ($nfoContent === '') {
            return ['titles' => [], 'complete' => true];
        }

        if (get_class($this) === self::class) {
            $native = $this->predbCandidatesRust($nfoContent);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->predbCandidatesPhp($nfoContent);
    }

    private static bool $ffiResolved = false;

    private static ?\FFI $defaultFfi = null;

    private static ?CData $defaultBuf = null;

    private static int $defaultCap = 0;

    private function resolveFfi(): ?\FFI
    {
        if ($this->libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('nfo_extractors', self::FFI_CDEF, $this->libraryPath);
        }
        if (! self::$ffiResolved) {
            self::$defaultFfi = RustPipelineLibrary::resolveDomain('nfo_extractors', self::FFI_CDEF);
            if (self::$defaultFfi !== null) {
                self::$defaultBuf = RustPipelineLibrary::obtainScratch(self::$defaultFfi, 8192, self::$defaultCap);
            }
            self::$ffiResolved = true;
        }

        return self::$defaultFfi;
    }

    /**
     * @return array{titles: list<string>, complete: bool}|null
     */
    private function predbCandidatesRust(string $nfoContent): ?array
    {
        $ffi = $this->resolveFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $len = strlen($nfoContent);
            if ($this->libraryPath === null && self::$defaultBuf !== null && $len + 64 <= self::$defaultCap) {
                $buf = self::$defaultBuf;
                $cap = self::$defaultCap;
            } else {
                $cap = 0;
                $buf = RustPipelineLibrary::obtainScratch($ffi, max(512, min(65536, $len + 64)), $cap);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nfo_predb_candidates($nfoContent, $len, $buf, $cap);
            if ($written <= -16) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_nfo_predb_candidates($nfoContent, $len, $buf, $cap);
            }
            if ($written === 0) {
                return ['titles' => [], 'complete' => true];
            }
            if ($written === -4) {
                return ['titles' => [], 'complete' => false];
            }
            if ($written > 0) {
                $raw = \FFI::string($buf, $written);

                return ['titles' => explode("\0", $raw), 'complete' => true];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array{titles: list<string>, complete: bool}
     */
    public function predbCandidatesPhp(string $nfoContent): array
    {
        $identity = $this->identityTextPhp($nfoContent);
        $cleaner = new FileNameCleaner;
        $titles = [];
        $raw = [];
        foreach ($this->literalNameFields($identity) as $field) {
            $title = $cleaner->computeForMatchingPhp($field, false);
            if ($title !== false) {
                $raw[] = $title;
            }
        }
        if (preg_match_all(ReleaseUpdateService::PREDB_REGEX, $identity, $matches)) {
            foreach ($matches[0] as $title) {
                $raw[] = trim($title);
            }
        }
        foreach ($raw as $title) {
            if (! mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > 255
                || preg_match('/\p{C}/u', $title) !== 0) {
                continue;
            }
            $titles[PredbSearchDocument::exactValue($title)] = $title;
            if (count($titles) > 100) {
                return ['titles' => [], 'complete' => false];
            }
        }

        return ['titles' => array_values($titles), 'complete' => true];
    }

    /** @return list<string> */
    private function literalNameFields(string $identity): array
    {
        if (! preg_match_all('/^[ \t]*(?:Filename|File[ \t]+Name|Complete[ \t]+Name|Release(?:[ \t]+Name)?)[ \t]*[:=][ \t]*([^\r\n]+)$/im', $identity, $fields)) {
            return [];
        }

        return array_map(trim(...), $fields[1]);
    }

    /**
     * @return array{0: bool, 1: ?NameFixResult}
     */
    private function extractPatternModeRust(string $nfoContent, int $mode): array
    {
        if ($nfoContent === '') {
            return [true, null];
        }

        $ffi = $this->resolveFfi();
        if ($ffi === null) {
            return [false, null];
        }

        try {
            $len = strlen($nfoContent);
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 512, $cap);
            if ($mode === 0) {
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_nfo_extract_pattern_match($nfoContent, $len, $buf, $cap);
            } else {
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_nfo_extract_pattern_mode($nfoContent, $len, $mode, $buf, $cap);
            }
            if ($written <= -16) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($mode === 0) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_nfo_extract_pattern_match($nfoContent, $len, $buf, $cap);
                } else {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_nfo_extract_pattern_mode($nfoContent, $len, $mode, $buf, $cap);
                }
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
                            'NFO',
                        ),
                    ];
                }
            }
        } catch (\Throwable) {
            return [false, null];
        }

        return [false, null];
    }

    /**
     * Check NFO content for TV patterns.
     */
    public function checkForTv(string $nfoContent): ?NameFixResult
    {
        return $this->checkForTvPhp($nfoContent);
    }

    public function checkForTvRust(string $nfoContent): ?NameFixResult
    {
        [$handled, $result] = $this->extractPatternModeRust($nfoContent, 1);

        return $handled ? $result : $this->checkForTvPhp($nfoContent);
    }

    public function checkForTvPhp(string $nfoContent): ?NameFixResult
    {
        $nfoContent = $this->identityText($nfoContent);
        $result = [];

        // Generic TV pattern 1: path with SxxExx
        if (preg_match('/:\s*.*[\\\\\/]([A-Z0-9].+?S\d+[.-_ ]?[ED]\d+.+?)\.\w{2,}\s+/i', $nfoContent, $result)) {
            return NameFixResult::fromMatch($result[1], 'Generic TV 1', 'NFO');
        }

        // Generic TV pattern 2: colon with SxxExx
        if (preg_match('/(?:(\:\s{1,}))(.+?S\d{1,3}[.-_ ]?[ED]\d{1,3}.+?)(\s{2,}|\r|\n)/i', $nfoContent, $result)) {
            return NameFixResult::fromMatch($result[2], 'Generic TV 2', 'NFO');
        }

        return null;
    }

    /**
     * Check NFO content for Movie patterns.
     */
    public function checkForMovie(string $nfoContent): ?NameFixResult
    {
        return $this->checkForMoviePhp($nfoContent);
    }

    public function checkForMovieRust(string $nfoContent): ?NameFixResult
    {
        [$handled, $result] = $this->extractPatternModeRust($nfoContent, 2);

        return $handled ? $result : $this->checkForMoviePhp($nfoContent);
    }

    public function checkForMoviePhp(string $nfoContent): ?NameFixResult
    {
        $nfoContent = $this->identityText($nfoContent);
        $result = [];

        // Generic Movies 1
        if (preg_match('/(?:((?!Source\s)\:\s{1,}))(.+?(19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $nfoContent, $result)) {
            return NameFixResult::fromMatch($result[2], 'Generic Movies 1', 'NFO');
        }

        // Generic Movies 2
        if (preg_match('/(?:(\s{2,}))((?!Source).+?[\.\-_ ](19|20)\d\d.+?(BDRip|bluray|DVD(R|Rip)?|XVID).+?)(\s{2,}|\r|\n)/i', $nfoContent, $result)) {
            return NameFixResult::fromMatch($result[2], 'Generic Movies 2', 'NFO');
        }

        // Generic Movies 3 (NTSC/MULTi)
        if (preg_match('/(?:(\s{2,}))(.+?[\.\-_ ](NTSC|MULTi).+?(MULTi|DVDR)[\.\-_ ].+?)(\s{2,}|\r|\n)/i', $nfoContent, $result)) {
            return NameFixResult::fromMatch($result[2], 'Generic Movies 3', 'NFO');
        }

        return null;
    }

    /**
     * Check NFO content for Music patterns.
     */
    public function checkForMusic(string $nfoContent): ?NameFixResult
    {
        return $this->checkForMusicPhp($nfoContent);
    }

    public function checkForMusicRust(string $nfoContent): ?NameFixResult
    {
        [$handled, $result] = $this->extractPatternModeRust($nfoContent, 3);

        return $handled ? $result : $this->checkForMusicPhp($nfoContent);
    }

    public function checkForMusicPhp(string $nfoContent): ?NameFixResult
    {
        $nfoContent = $this->identityText($nfoContent);
        $result = [];

        // FM Radio pattern
        if (preg_match('/(?:\s{2,})(.+?-FM-\d{2}-\d{2})/i', $nfoContent, $result)) {
            $newName = str_replace('-FM-', '-FM-Radio-MP3-', $result[1]);

            return NameFixResult::fromMatch($newName, 'Music FM RADIO', 'NFO');
        }

        return null;
    }

    /**
     * Check NFO content for Title (Year) patterns.
     *
     * This is a comprehensive check that also extracts codec info.
     */
    public function checkForTitleYear(string $nfoContent): ?NameFixResult
    {
        return $this->checkForTitleYearPhp($nfoContent);
    }

    public function checkForTitleYearRust(string $nfoContent): ?NameFixResult
    {
        [$handled, $result] = $this->extractPatternModeRust($nfoContent, 4);

        return $handled ? $result : $this->checkForTitleYearPhp($nfoContent);
    }

    public function checkForTitleYearPhp(string $nfoContent): ?NameFixResult
    {
        return $this->checkForTitleYearNative($nfoContent);
    }

    public function checkForTitleYearNative(string $nfoContent): ?NameFixResult
    {
        return $this->checkForTitleYearWithTags($nfoContent, false);
    }

    public function checkForTitleYearTagsRust(string $nfoContent): ?NameFixResult
    {
        return $this->checkForTitleYearWithTags($nfoContent, true);
    }

    private function checkForTitleYearWithTags(string $nfoContent, bool $rust): ?NameFixResult
    {
        $metadata = $nfoContent;
        $nfoContent = $this->identityText($nfoContent);
        $result = [];

        // Skip PDFs and audiobooks
        if (preg_match('/\.pdf|Audio ?Book/i', $nfoContent)) {
            return null;
        }

        // Look for Title (Year) pattern
        if (! preg_match('/(\w[\-\w`~!@#$%^&*()_+={}|"<>?\[\]\\;\',.\/ ]+\s?\((19|20)\d\d\))/i', $nfoContent, $result)) {
            return null;
        }

        $releaseName = $result[0];

        $releaseName = $rust
            ? $this->appendTitleYearTagsRust($releaseName, $metadata)
            : $this->appendTitleYearTagsNative($releaseName, $metadata);

        $releaseName .= '-NoGroup';

        return NameFixResult::fromMatch($releaseName, 'Title (Year)', 'NFO');
    }

    /**
     * Check NFO content for Game patterns.
     */
    public function checkForGame(string $nfoContent): ?NameFixResult
    {
        return $this->checkForGamePhp($nfoContent);
    }

    public function checkForGameRust(string $nfoContent): ?NameFixResult
    {
        [$handled, $result] = $this->extractPatternModeRust($nfoContent, 5);

        return $handled ? $result : $this->checkForGamePhp($nfoContent);
    }

    public function checkForGamePhp(string $nfoContent): ?NameFixResult
    {
        $nfoContent = $this->identityText($nfoContent);
        $result = [];

        // Check for known game groups
        if (! preg_match('/ALiAS|BAT-TEAM|FAiRLiGHT|Game Type|Glamoury|HI2U|iTWINS|JAGUAR|(LARGE|MEDIUM)ISO|MAZE|nERv|PROPHET|PROFiT|PROCYON|RELOADED|REVOLVER|ROGUE|ViTALiTY/i', $nfoContent)) {
            return null;
        }

        // (c) pattern
        if (preg_match('/\w[\w.+&*\/\()\',;: -]+\(c\)[\-\w.\',;& ]+\w/i', $nfoContent, $result)) {
            $releaseName = str_replace(['(c)', '(C)'], '(GAMES) (c)', $result[0]);

            return NameFixResult::fromMatch($releaseName, 'PC Games (c)', 'NFO');
        }

        // *ISO* pattern
        if (preg_match('/\w[\w.+&*\/()\',;: -]+\*ISO\*/i', $nfoContent, $result)) {
            $releaseName = str_replace('*ISO*', '*ISO* (PC GAMES)', $result[0]);

            return NameFixResult::fromMatch($releaseName, 'PC Games *ISO*', 'NFO');
        }

        return null;
    }

    /**
     * Check NFO content for IGUANA supplier patterns.
     */
    public function checkForIguana(string $nfoContent): ?NameFixResult
    {
        return $this->checkForIguanaPhp($nfoContent);
    }

    public function checkForIguanaRust(string $nfoContent): ?NameFixResult
    {
        [$handled, $result] = $this->extractPatternModeRust($nfoContent, 6);

        return $handled ? $result : $this->checkForIguanaPhp($nfoContent);
    }

    public function checkForIguanaPhp(string $nfoContent): ?NameFixResult
    {
        $nfoContent = $this->identityText($nfoContent);
        if (! preg_match('/Supplier.+?IGUANA/i', $nfoContent)) {
            return null;
        }

        $releaseName = '';
        $result = [];

        if (preg_match('/\w[\-\w`~!@#$%^&*()+={}|:"<>?\[\]\\;\',.\/ ]+\s\((19|20)\d\d\)/i', $nfoContent, $result)) {
            $releaseName = $result[0];
        }

        if (preg_match('/\s\[\*\] (English|Dutch|French|German|Spanish)\b/i', $nfoContent, $result)) {
            $releaseName .= '.'.$result[1];
        }

        if (preg_match('/\s\[\*\] (DT?S [2567][._ -][0-2]( MONO)?)\b/i', $nfoContent, $result)) {
            $releaseName .= '.'.$result[2];
        }

        if (preg_match('/Format.+(DVD([59R])?|[HX][._ -]?264)\b/i', $nfoContent, $result)) {
            $releaseName .= '.'.$result[1];
        }

        if (preg_match('/\[(640x.+|1280x.+|1920x.+)\] Resolution\b/i', $nfoContent, $result)) {
            $res = match (true) {
                $result[1] === '640x.+' => '480p',
                $result[1] === '1280x.+' => '720p',
                $result[1] === '1920x.+' => '1080p',
                default => $result[1],
            };
            $releaseName .= '.'.$res;
        }

        if ($releaseName !== '') {
            return NameFixResult::fromMatch($releaseName.'.IGUANA', 'IGUANA', 'NFO');
        }

        return null;
    }

    public function appendTitleYearTagsRust(string $releaseName, string $nfoContent): string
    {
        if (get_class($this) !== self::class) {
            return $this->appendTitleYearTagsNative($releaseName, $nfoContent);
        }

        return $this->appendTagRust('nntmux_nfo_append_title_year_tags', $releaseName, $nfoContent)
            ?? $this->appendTitleYearTagsNative($releaseName, $nfoContent);
    }

    public function appendTitleYearTagsNative(string $releaseName, string $nfoContent): string
    {
        $releaseName = $this->appendLanguageFromNfo($releaseName, $nfoContent);
        $releaseName = $this->appendResolutionFromNfo($releaseName, $nfoContent);
        $releaseName = $this->appendSourceFromNfo($releaseName, $nfoContent);
        $releaseName = $this->appendVideoCodecFromNfo($releaseName, $nfoContent);

        return $this->appendAudioCodecFromNfo($releaseName, $nfoContent);
    }

    /**
     * Append language from NFO content.
     */
    protected function appendLanguageFromNfo(string $releaseName, string $nfoContent): string
    {
        return $this->appendLanguageFromNfoNative($releaseName, $nfoContent);
    }

    public function appendLanguageFromNfoRust(string $releaseName, string $nfoContent): string
    {
        if (get_class($this) !== self::class) {
            return $this->appendLanguageFromNfoNative($releaseName, $nfoContent);
        }

        return $this->appendTagRust('nntmux_nfo_append_language', $releaseName, $nfoContent)
            ?? $this->appendLanguageFromNfoNative($releaseName, $nfoContent);
    }

    public function appendLanguageFromNfoNative(string $releaseName, string $nfoContent): string
    {
        $result = [];
        if (preg_match('/(idiomas|lang|language|langue|sprache).*?\b(?P<lang>Brazilian|Chinese|Croatian|Danish|DE|Deutsch|Dutch|Estonian|ES|English|Englisch|Finnish|Flemish|Francais|French|FR|German|Greek|Hebrew|Icelandic|Italian|Japenese|Japan|Japanese|Korean|Latin|Nordic|Norwegian|Polish|Portuguese|Russian|Serbian|Slovenian|Swedish|Spanisch|Spanish|Thai|Turkish)\b/i', $nfoContent, $result)) {
            $lang = match ($result['lang']) {
                'DE' => 'GERMAN',
                'Englisch' => 'ENGLISH',
                'FR' => 'FRENCH',
                'ES' => 'SPANISH',
                default => $result['lang'],
            };
            $releaseName .= '.'.$lang;
        }

        return $releaseName;
    }

    /**
     * Append resolution from NFO content.
     */
    protected function appendResolutionFromNfo(string $releaseName, string $nfoContent): string
    {
        return $this->appendResolutionFromNfoNative($releaseName, $nfoContent);
    }

    public function appendResolutionFromNfoRust(string $releaseName, string $nfoContent): string
    {
        if (get_class($this) !== self::class) {
            return $this->appendResolutionFromNfoNative($releaseName, $nfoContent);
        }

        return $this->appendTagRust('nntmux_nfo_append_resolution', $releaseName, $nfoContent)
            ?? $this->appendResolutionFromNfoNative($releaseName, $nfoContent);
    }

    public function appendResolutionFromNfoNative(string $releaseName, string $nfoContent): string
    {
        $result = [];

        if (preg_match('/(frame size|(video )?res(olution)?|video).*?(?P<res>(272|336|480|494|528|608|\(?640|688|704|720x480|810|816|820|1 ?080|1280( \@)?|1 ?920(x1080)?))/i', $nfoContent, $result)) {
            $res = match ($result['res']) {
                '272', '336', '480', '494', '608', '640', '(640', '688', '704', '720x480' => '480p',
                '1280x720', '1280', '1280 @' => '720p',
                '810', '816', '820', '1920', '1 920', '1080', '1 080', '1920x1080' => '1080p',
                '2160' => '2160p',
                default => $result['res'],
            };
            $releaseName .= '.'.$res;
        } elseif (preg_match('/(largeur|width).*?(?P<res>(\(?640|688|704|720|1280( \@)?|1 ?920))/i', $nfoContent, $result)) {
            $res = match ($result['res']) {
                '640', '(640', '688', '704', '720' => '480p',
                '1280 @', '1280' => '720p',
                '1920', '1 920' => '1080p',
                '2160' => '2160p',
                default => $result['res'],
            };
            $releaseName .= '.'.$res;
        }

        return $releaseName;
    }

    /**
     * Append source from NFO content.
     */
    protected function appendSourceFromNfo(string $releaseName, string $nfoContent): string
    {
        return $this->appendSourceFromNfoNative($releaseName, $nfoContent);
    }

    public function appendSourceFromNfoRust(string $releaseName, string $nfoContent): string
    {
        if (get_class($this) !== self::class) {
            return $this->appendSourceFromNfoNative($releaseName, $nfoContent);
        }

        return $this->appendTagRust('nntmux_nfo_append_source', $releaseName, $nfoContent)
            ?? $this->appendSourceFromNfoNative($releaseName, $nfoContent);
    }

    public function appendSourceFromNfoNative(string $releaseName, string $nfoContent): string
    {
        $result = [];

        if (preg_match('/source.*?\b(?P<source>BD(-?(25|50|RIP))?|Blu-?Ray ?(3D)?|BRRIP|CAM(RIP)?|DBrip|DTV|DVD\-?(5|9|(R(IP)?|scr(eener)?))?|[HPS]D?(RIP|TV(RIP)?)?|NTSC|PAL|R5|Ripped |S?VCD|scr(eener)?|SAT(RIP)?|TS|VHS(RIP)?|VOD|WEB-DL)\b/i', $nfoContent, $result)) {
            $source = match ($result['source']) {
                'BD' => 'Bluray.x264',
                'CAMRIP' => 'CAM',
                'DBrip' => 'BDRIP',
                'DVD R1', 'NTSC', 'PAL', 'VOD' => 'DVD',
                'HD' => 'HDTV',
                'Ripped ' => 'DVDRIP',
                default => $result['source'],
            };
            $releaseName .= '.'.$source;
        } elseif (preg_match('/(codec( (name|code))?|(original )?format|res(olution)|video( (codec|format|res))?|tv system|type|writing library).*?\b(?P<video>AVC|AVI|DBrip|DIVX|\(Divx|DVD|[HX][._ -]?264|MPEG-4 Visual|NTSC|PAL|WMV|XVID)\b/i', $nfoContent, $result)) {
            $video = match ($result['video']) {
                'AVI' => 'DVDRIP',
                'DBrip' => 'BDRIP',
                '(Divx' => 'DIVX',
                'h264', 'h-264', 'h.264' => 'H264',
                'MPEG-4 Visual', 'x264', 'x-264', 'x.264' => 'x264',
                'NTSC', 'PAL' => 'DVD',
                default => $result['video'],
            };
            $releaseName .= '.'.$video;
        }

        return $releaseName;
    }

    /**
     * Append video codec from NFO content.
     */
    protected function appendVideoCodecFromNfo(string $releaseName, string $nfoContent): string
    {
        // This is handled in appendSourceFromNfo for simplicity
        return $releaseName;
    }

    /**
     * Append audio codec from NFO content.
     */
    protected function appendAudioCodecFromNfo(string $releaseName, string $nfoContent): string
    {
        return $this->appendAudioCodecFromNfoNative($releaseName, $nfoContent);
    }

    public function appendAudioCodecFromNfoRust(string $releaseName, string $nfoContent): string
    {
        if (get_class($this) !== self::class) {
            return $this->appendAudioCodecFromNfoNative($releaseName, $nfoContent);
        }

        return $this->appendTagRust('nntmux_nfo_append_audio_codec', $releaseName, $nfoContent)
            ?? $this->appendAudioCodecFromNfoNative($releaseName, $nfoContent);
    }

    public function appendAudioCodecFromNfoNative(string $releaseName, string $nfoContent): string
    {
        $result = [];

        if (preg_match('/(audio( format)?|codec( name)?|format).*?\b(?P<audio>0x0055 MPEG-1 Layer 3|AAC( LC)?|AC-?3|\(AC3|DD5(.1)?|(A_)?DTS-?(HD)?(-?MA)?|Dolby(\s?TrueHD)?|TrueHD|FLAC|MP3)\b/i', $nfoContent, $result)) {
            $audio = match ($result['audio']) {
                '0x0055 MPEG-1 Layer 3' => 'MP3',
                'AC-3', '(AC3' => 'AC3',
                'AAC LC' => 'AAC',
                'A_DTS', 'DTS-HD', 'DTSHD' => 'DTS',
                default => $result['audio'],
            };
            $releaseName .= '.'.$audio;
        }

        return $releaseName;
    }

    /**
     * Check all NFO patterns and return the first match.
     */
    public function extractFromNfo(string $nfoContent): ?NameFixResult
    {
        $cleaner = new FileNameCleaner;
        foreach ($this->literalNameFields($this->identityText($nfoContent)) as $field) {
            $title = $cleaner->cleanForMatching($field);
            if ($title !== false && $cleaner->isLiteralTechnicalSceneTitle($title)) {
                return NameFixResult::fromMatch($title, 'Complete literal NFO field', 'NFO');
            }
        }

        // Try each pattern in order of reliability
        $result = $this->checkForTv($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForMovie($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForMusic($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForTitleYear($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForGame($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForIguana($nfoContent);
        if ($result !== null) {
            return $result;
        }

        return null;
    }

    public function extractFromNfoRust(string $nfoContent): ?NameFixResult
    {
        [$handled, $result] = $this->extractPatternModeRust($nfoContent, 0);

        return $handled ? $result : $this->extractFromNfoPhp($nfoContent);
    }

    public function extractFromNfoPhp(string $nfoContent): ?NameFixResult
    {
        $cleaner = new FileNameCleaner;
        foreach ($this->literalNameFields($this->identityTextPhp($nfoContent)) as $field) {
            $title = $cleaner->computeForMatchingPhp($field, false);
            if ($title !== false && $cleaner->isLiteralTechnicalSceneTitle($title)) {
                return NameFixResult::fromMatch($title, 'Complete literal NFO field', 'NFO');
            }
        }

        $result = $this->checkForTvPhp($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForMoviePhp($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForMusicPhp($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForTitleYearPhp($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForGamePhp($nfoContent);
        if ($result !== null) {
            return $result;
        }

        $result = $this->checkForIguanaPhp($nfoContent);
        if ($result !== null) {
            return $result;
        }

        return null;
    }

    // ---- Wave 58.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 3.69x/3.78x, uncommon 4.06x/4.00x); see RustConstrainedPredbFileNameNfoObfuscatedNameCheckerW58ParityTest. */
    private const bool W58_RUST_FFI_DEFAULT = true;

    private const string W58_FFI_CDEF = <<<'C'
        intptr_t nntmux_w58_nfo_title_plan_batch(const char *nfos, size_t nfos_len, size_t count, int64_t *out, size_t out_cap);
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
     * Runs $callback with planNfoBatch() routed to Rust ($enabled) or PHP, then restores the
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

    /** High bytes (CP437/UTF-8 box drawing) and ASCII art punctuation. */
    private const string W58_ART = '/[\x80-\xff|#*=~^<>\[\]{}`]/';

    private const string W58_ART_BYTES = '|#*=~^<>[]{}`';

    private const string W58_KEY_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz .';

    private const string W58_TITLE_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz._()-';

    private const string W58_ALNUM = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const array W58_TITLE_KEYS = ['release' => true, 'title' => true, 'name' => true, 'rls' => true, 'release name' => true,
        'releasename' => true, 'rls name' => true];

    /**
     * Batch plan over NFO texts. Lines split on `\n`; control bytes and art bytes become spaces
     * and lines are trimmed of spaces (empty lines skipped). A `key: value` line with a 2-24 byte
     * `[A-Za-z .]` key (right-trimmed of spaces) is key-value; the key, lowercased and
     * right-trimmed of dots and spaces, is a title key when it names the release (release,
     * title, name, rls, release name, releasename, rls name). The value (or the whole line) is
     * a title candidate when it is 8-255 bytes of `[A-Za-z0-9._()-]` with a `.` or `_`, scored
     * 8 for a title key, 4 for a `-GROUP` (2-15 alphanumerics after offset 0), min(separators,
     * 3) and 2 for a `(19|20)YY` digit run; the first highest score wins. Per NFO `best score |
     * 32 any key-value line | 64 any art byte | 128 any title key | min(len, 0xFFFFFF) << 8 |
     * crc32(best) << 32`; then the non-empty lines, art bytes, key-value lines, NFOs with a
     * candidate and the XXH64 digest of all earlier words.
     *
     * @param  list<string>  $nfos
     * @return list<int>
     */
    public static function planNfoBatch(array $nfos): array
    {
        return self::usesW58RustFfi() ? self::planNfoBatchNative($nfos) : self::planNfoBatchPhp($nfos);
    }

    /**
     * @param  list<string>  $nfos
     * @return list<int>
     */
    public static function planNfoBatchPhp(array $nfos): array
    {
        $from = implode('', array_map(chr(...), [...range(0x00, 0x1F), ...range(0x80, 0xFF)])).self::W58_ART_BYTES;
        $to = str_repeat(' ', \strlen($from));
        $out = [];
        $lines = $art = $kvLines = $found = 0;
        foreach ($nfos as $nfo) {
            $nfo = (string) $nfo;
            $nfoArt = (int) preg_match_all(self::W58_ART, $nfo);
            $art += $nfoArt;
            $flags = $nfoArt > 0 ? 64 : 0;
            $bestScore = 0;
            $best = '';
            foreach (explode("\n", $nfo) as $line) {
                $clean = trim(strtr($line, $from, $to), ' ');
                if ($clean === '') {
                    continue;
                }
                $lines++;
                $candidate = $clean;
                $titleKey = false;
                if (($p = strpos($clean, ':')) !== false) {
                    $key = rtrim(substr($clean, 0, $p), ' ');
                    $keyLength = \strlen($key);
                    if ($keyLength >= 2 && $keyLength <= 24 && strspn($key, self::W58_KEY_CHARS) === $keyLength) {
                        $kvLines++;
                        $flags |= 32;
                        $titleKey = isset(self::W58_TITLE_KEYS[rtrim(strtolower($key), '. ')]);
                        if ($titleKey) {
                            $flags |= 128;
                        }
                        $candidate = trim(substr($clean, $p + 1), ' ');
                    }
                }
                $score = self::w58NfoScore($candidate, $titleKey);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $candidate;
                }
            }
            if ($bestScore > 0) {
                $found++;
            }
            $out[] = $flags | $bestScore | min(\strlen($best), 0xFFFFFF) << 8 | crc32($best) << 32;
        }
        array_push($out, $lines, $art, $kvLines, $found);
        $out[] = self::w58Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, an NFO holds a NUL byte or the
     * column is not a list.
     *
     * @param  list<string>  $nfos
     * @return list<int>
     */
    public static function planNfoBatchNative(array $nfos): array
    {
        $count = \count($nfos);
        $words = $count + 5;
        /** @var array{\FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w58RustFfiState('out', $words);
        if ($state === null || ! array_is_list($nfos)) {
            return self::planNfoBatchPhp($nfos);
        }
        $blob = implode("\0", $nfos);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_nfo_title_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }

        return self::w58Words($written, $out, $words) ?? self::planNfoBatchPhp($nfos);
    }

    private static function w58NfoScore(string $candidate, bool $titleKey): int
    {
        $length = \strlen($candidate);
        if ($length < 8 || $length > 255 || strspn($candidate, self::W58_TITLE_CHARS) !== $length) {
            return 0;
        }
        $separators = substr_count($candidate, '.') + substr_count($candidate, '_');
        if ($separators === 0) {
            return 0;
        }
        $p = strrpos($candidate, '-');
        $group = $p !== false && $p > 0 && ($g = $length - $p - 1) >= 2 && $g <= 15 && strspn($candidate, self::W58_ALNUM, $p + 1) === $g;

        return ($titleKey ? 8 : 0) + ($group ? 4 : 0) + min($separators, 3)
            + (preg_match('/(?<![0-9])(?:19|20)[0-9]{2}(?![0-9])/', $candidate) === 1 ? 2 : 0);
    }
}
