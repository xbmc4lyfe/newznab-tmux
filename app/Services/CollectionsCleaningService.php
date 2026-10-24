<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Cleans names for collections/imports/namefixer.
 *
 * This class processes Usenet subject lines to extract clean collection names
 * by removing common patterns like file extensions, sizes, and yEnc markers.
 */
class CollectionsCleaningService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_collections_is_music_group(const char *group, size_t group_len);
        ssize_t nntmux_collections_extract_music_subject(const char *input, size_t input_len, char *out, size_t out_cap);
        ssize_t nntmux_collections_clean_generic_pre_normalize(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_collections_music_subject(const char *input, size_t len, const char *group, size_t group_len, char *out, size_t cap);
        ssize_t nntmux_collections_enhance_short_subject(const char *input, size_t len, const char *clean, size_t clean_len, char *out, size_t cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?FFI\CData $scratch = null;

    private static int $scratchCap = 0;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function scratch(FFI $ffi, int $minBytes): ?FFI\CData
    {
        if (self::$scratch === null || self::$scratchCap < $minBytes) {
            self::$scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, self::$scratchCap);
        }

        return self::$scratch;
    }

    /**
     * Used for matching endings in article subjects.
     */
    public const string REGEX_END = '[\-_\s]{0,3}yEnc$/ui';

    /**
     * Used for matching file extension endings in article subjects.
     */
    public const string REGEX_FILE_EXTENSIONS = '([\-_](proof|sample|thumbs?))*(\.part\d*(\.rar)?|\.rar|\.7z|\.par2)?(\d{1,3}\.rev"|\.vol\d+\+\d+.+?"|\.[A-Za-z0-9]{2,4}"|")';

    /**
     * Used for matching size strings in article subjects.
     *
     * @example ' - 365.15 KB - '
     */
    public const string REGEX_SUBJECT_SIZE = '[\-_\s]{0,3}\d+([.,]\d+)? [kKmMgG][bB][\-_\s]{0,3}';

    /**
     * Collection subject matched the Generic regular expression.
     */
    public const int REGEX_GENERIC_MATCH = -10;

    /**
     * Collection subject matched the Music generic regular expression.
     */
    public const int REGEX_MUSIC_MATCH = -20;

    /**
     * Normalized subjects remembered per process before the memo is cleared. See normalizeString().
     */
    private const int NORMALIZE_MEMO_SIZE = 4096;

    /**
     * Every encoding mbstring supports, cached per process.
     *
     * @var list<string>|null
     */
    private static ?array $allEncodings = null;

    /**
     * Recent normalizeString() results keyed by the collapsed subject.
     *
     * @var array<string, string>
     */
    private static array $normalizeMemo = [];

    /**
     * Cached file extension patterns
     */
    public string $e0;

    public string $e1;

    public string $e2;

    /**
     * Current group name being processed
     */
    public string $groupName = '';

    /**
     * Current subject being processed
     */
    public string $subject = '';

    /**
     * Regex handler for database-stored patterns
     */
    protected RegexService $_regexes;

    /**
     * CollectionsCleaningService constructor.
     *
     * Initializes regex patterns and the database regex handler.
     *
     * @throws \Exception
     */
    public function __construct()
    {
        // Cache commonly used extension patterns for performance
        $this->e0 = self::REGEX_FILE_EXTENSIONS;
        $this->e1 = self::REGEX_FILE_EXTENSIONS.self::REGEX_END;
        $this->e2 = self::REGEX_FILE_EXTENSIONS.self::REGEX_SUBJECT_SIZE.self::REGEX_END;

        $this->_regexes = new RegexService('collection_regexes');
    }

    /**
     * Clean a collection subject line.
     *
     * Attempts to extract a clean collection name from a Usenet subject line.
     * First tries database regexes, then falls back to generic cleaning patterns.
     *
     * @param  string  $subject  The raw subject line to clean
     * @param  string  $groupName  The newsgroup name (optional, used for context-specific cleaning)
     * @return array<string, mixed> Returns ['id' => regex_id, 'name' => cleaned_name]
     *
     * @throws \Exception
     */
    public function collectionsCleaner(string $subject, string $groupName = ''): array
    {
        $this->subject = $subject;
        $this->groupName = $groupName;

        // Try database regex patterns first for better accuracy
        $potentialString = $this->_regexes->tryRegex($subject, $groupName);
        if ($potentialString) {
            return [
                'id' => $this->_regexes->matchedRegex,
                'name' => $potentialString,
            ];
        }

        // Fall back to generic cleaning patterns
        return $this->generic();
    }

    /**
     * Cleans usenet subject before inserting, used for collection hash.
     *
     * If no regexes matched on collectionsCleaner, this method applies generic cleaning patterns.
     *
     * @return array<string, mixed> Returns ['id' => match_type, 'name' => cleaned_name]
     */
    protected function generic(): array
    {
        // For non-music groups
        if (! $this->isMusicGroup()) {
            return $this->cleanGenericSubject();
        }

        // Music groups processing
        $musicSubject = $this->musicSubject();
        if ($musicSubject !== false) {
            return [
                'id' => self::REGEX_MUSIC_MATCH,
                'name' => $musicSubject,
            ];
        }

        return $this->cleanMusicSubject();
    }

    /**
     * Clean non-music group subjects.
     *
     * @return array<string, mixed> Returns ['id' => match_type, 'name' => cleaned_name]
     */
    protected function cleanGenericSubject(): array
    {
        return (self::$useRustFfi ? $this->cleanGenericSubjectNative() : null)
            ?? $this->cleanGenericSubjectPhp();
    }

    /**
     * @return array<string, mixed>
     */
    public function cleanGenericSubjectPhp(): array
    {
        // Remove common patterns:
        // - File/part count indicators
        // - File extensions
        // - File sizes (non-unique identifiers)
        // - Random/generic metadata
        $cleanSubject = preg_replace([
            // File sizes and yEnc markers
            '/\d{1,3}([,.\\/])\d{1,3}\s([kmg])b|(])?\s\d+KB\s(yENC)?|"?\s\d+\sbytes?|[- ]?\d+([.,])?\d+\s([gkm])?B\s-?(\s?yenc)?|\s\(d{1,3},\d{1,3}\s{K,M,G}B\)\s|yEnc \d+k$|{\d+ yEnc bytes}|yEnc \d+ |\(\d+ ?([kmg])?b(ytes)?\) yEnc$/i',
            // AutoRarPar and yEnc markers
            '/AutoRarPar\d{1,5}|\(\d+\)\s?yEnc|\d+(Amateur|Classic)| \d{4,}[a-z]{4,} |.vol\d+\+\d+|.part\d+/i',
            // Part/file numbering patterns
            '/((( \(\d\d\) -|(\d\d)? - \d\d\.|\d{4} \d\d -) | - \d\d-| \d\d\. [a-z]).+| \d\d of \d\d| \dof\d)\.mp3"?|([)(\[\s])\d{1,5}(\/|([\s_])of([\s_])|-)\d{1,5}([)\]\s$:])|\(\d{1,3}\|\d{1,3}\)|[^\d]{4}-\d{1,3}-\d{1,3}\.|\s\d{1,3}\sof\s\d{1,3}\.|\s\d{1,3}\/\d{1,3}|\d{1,3}of\d{1,3}\.|^\d{1,3}\/\d{1,3}\s|\d{1,3} - of \d{1,3}/i',
            // File extensions and common patterns
            '/(-? [a-z0-9]+-?|\(?\d{4}\)?([_-])[a-z0-9]+)\.jpg"?| [a-z0-9]+\.mu3"?|((\d{1,3})?\.part(\d{1,5})?|\d{1,5} ?|sample|- Partie \d+)?\.(7z|\d{3}(?=([\s"]))|avi|diz|docx?|epub|idx|iso|jpg|m3u|m4a|mds|mkv|mobi|mp4|nfo|nzb|par(\s?2|")|pdf|rar|rev|rtf|r\d\d|sfv|srs|srr|sub|txt|vol.+(par2)|xls|zip|z{2,3})"?|(\s|(\d{2,3})?-)\d{2,3}\.mp3|\d{2,3}\.pdf|\.part\d{1,4}\./i',
            // Cached extension patterns
            '/'.$this->e0.'/i',
        ], ' ', $this->subject);

        return [
            'id' => self::REGEX_GENERIC_MATCH,
            'name' => $this->normalizeString($cleanSubject),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cleanGenericSubjectNative(): ?array
    {
        if (! $this->canUsePcreFastPath()) {
            return $this->cleanGenericSubjectPhp();
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($this->subject);
        $scratch = self::scratch($ffi, max(256, $len * 2 + 32));
        if ($scratch === null) {
            return null;
        }
        $cap = self::$scratchCap;
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collections_clean_generic_pre_normalize($this->subject, $len, $scratch, $cap);
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return $this->cleanGenericSubjectPhp();
            }
            $preNormalized = $written === 0 ? '' : FFI::string($scratch, $written);

            return [
                'id' => self::REGEX_GENERIC_MATCH,
                'name' => static::class === self::class
                    ? $this->memoizeNormalizedEncoding($preNormalized)
                    : $this->normalizeString($preNormalized),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Clean music group subjects with generic patterns.
     *
     * @return array<string, mixed> Returns ['id' => match_type, 'name' => cleaned_name]
     */
    protected function cleanMusicSubject(): array
    {
        return (self::$useRustFfi ? $this->cleanMusicSubjectNative() : null)
            ?? $this->cleanMusicSubjectPhp();
    }

    /**
     * @return array<string, mixed>
     */
    public function cleanMusicSubjectPhp(): array
    {
        // Generic music cleaning patterns
        $cleanSubject = preg_replace([
            // Parts/files numbering
            '/((( \(\d\d\) -|(\d\d)? - \d\d\.|\d{4} \d\d -) | - \d\d-| \d\d\. [a-z]).+| \d\d of \d\d| \dof\d)\.mp3"?|([(\[\s])\d{1,4}(\/|([\s_])of([\s_])|-)\d{1,4}([)\]\s$:])|\(\d{1,3}\|\d{1,3}\)|-\d{1,3}-\d{1,3}\.|\s\d{1,3}\sof\s\d{1,3}\.|\s\d{1,3}\/\d{1,3}|\d{1,3}of\d{1,3}\.|^\d{1,3}\/\d{1,3}\s|\d{1,3} - of \d{1,3}/i',
            // Remove anything between quotes (too much variance)
            '/".+"/i',
            // File extensions
            '/(-? [a-z0-9]+-?|\(?\d{4}\)?([_-])[a-z0-9]+)\.jpg"?| [a-z0-9]+\.mu3"?|((\d{1,3})?\.part(\d{1,5})?|\d{1,5} ?|sample|- Partie \d+)?\.(7z|\d{3}(?=([\s"]))|avi|diz|docx?|epub|idx|iso|jpg|m3u|m4a|mds|mkv|mobi|mp4|nfo|nzb|par(\s?2|")|pdf|rar|rev|rtf|r\d\d|sfv|srs|srr|sub|txt|vol.+(par2)|xls|zip|z{2,3})"?|(\s|(\d{2,3})?-)\d{2,3}\.mp3|\d{2,3}\.pdf|\.part\d{1,4}\./i',
            // File sizes
            '/\d{1,3}([,.\\/])\d{1,3}\s([kmg])b|(])?\s\d+KB\s(yENC)?|"?\s\d+\sbytes?|[- ]?\d+[.,]?\d+\s([gkm])?B\s-?(\s?yenc)?|\s\(d{1,3},\d{1,3}\s{K,M,G}B\)\s|yEnc \d+k$|{\d+ yEnc bytes}|yEnc \d+ |\(\d+ ?([kmg])?b(ytes)?\) yEnc$/i',
            // AutoRarPar and yEnc markers
            '/AutoRarPar\d{1,5}|\(\d+\)\s?yEnc|\d+(Amateur|Classic)| \d{4,}[a-z]{4,} |.vol\d+\+\d+|.part\d+/i',
        ], ' ', $this->subject);

        $cleanSubject = $this->normalizeString($cleanSubject);

        // If the subject is too short or generic, try to extract additional info
        if (\strlen($cleanSubject) <= 10 || preg_match('/^[\-a-z0-9$ ]{1,7}yEnc$/i', $cleanSubject)) {
            $cleanSubject = $this->enhanceShortSubject($cleanSubject);
        }

        return [
            'id' => self::REGEX_MUSIC_MATCH,
            'name' => $cleanSubject,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cleanMusicSubjectNative(): ?array
    {
        if (! $this->canUsePcreFastPath()) {
            return $this->cleanMusicSubjectPhp();
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($this->subject);
        $scratch = self::scratch($ffi, max(256, $len * 2 + 32));
        if ($scratch === null) {
            return null;
        }
        $cap = self::$scratchCap;
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collections_music_subject($this->subject, $len, '.music', 6, $scratch, $cap);
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return $this->cleanMusicSubjectPhp();
            }
            $preNormalized = $written === 0 ? '' : FFI::string($scratch, $written);
            $cleanSubject = static::class === self::class
                ? $this->memoizeNormalizedEncoding($preNormalized)
                : $this->normalizeString($preNormalized);
            if (\strlen($cleanSubject) <= 10 || preg_match('/^[\-a-z0-9$ ]{1,7}yEnc$/i', $cleanSubject)) {
                $cleanSubject = $this->enhanceShortSubjectNative($cleanSubject)
                    ?? $this->enhanceShortSubjectPhp($cleanSubject);
            }

            return [
                'id' => self::REGEX_MUSIC_MATCH,
                'name' => $cleanSubject,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Enhance short or generic subjects by extracting additional information.
     *
     * @param  string  $cleanSubject  The cleaned subject that is too short
     * @return string Enhanced subject string
     */
    protected function enhanceShortSubject(string $cleanSubject): string
    {
        return (self::$useRustFfi ? $this->enhanceShortSubjectNative($cleanSubject) : null)
            ?? $this->enhanceShortSubjectPhp($cleanSubject);
    }

    public function enhanceShortSubjectPhp(string $cleanSubject): string
    {
        $x = '';
        if (preg_match('/.*("[A-Z0-9]+).*?"/i', $this->subject, $hit)) {
            $x = $hit[1];
        }

        if (preg_match_all('/[^A-Z0-9]/i', $this->subject, $match1)) {
            $start = 0;
            foreach ($match1[0] as $add) {
                if ($start > 2) {
                    break;
                }
                $x .= $add;
                $start++;
            }
        }

        $newName = preg_replace(['/".+?"/', '/[a-z0-9]|'.$this->e0.'/i'], '', $this->subject);

        return $cleanSubject.$newName.$x;
    }

    public function enhanceShortSubjectNative(string $cleanSubject): ?string
    {
        if (! $this->canUsePcreFastPath()) {
            return $this->enhanceShortSubjectPhp($cleanSubject);
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($this->subject);
        $cleanLen = \strlen($cleanSubject);
        $scratch = self::scratch($ffi, max(256, $len * 2 + $cleanLen + 32));
        if ($scratch === null) {
            return null;
        }
        $cap = self::$scratchCap;
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collections_enhance_short_subject($this->subject, $len, $cleanSubject, $cleanLen, $scratch, $cap);
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return $this->enhanceShortSubjectPhp($cleanSubject);
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Process music-specific subject patterns.
     *
     * Attempts to extract clean names from music group subject lines using
     * specialized patterns for music releases.
     *
     * @return string|false Returns cleaned subject or false if no pattern matches
     */
    protected function musicSubject(): bool|string
    {
        if (self::$useRustFfi) {
            $native = $this->musicSubjectNative();
            if ($native !== null) {
                return $native;
            }
        }

        return $this->musicSubjectPhp();
    }

    public function musicSubjectPhp(): bool|string
    {
        // Pattern: Broderick_Smith-Unknown_Country-2009-404 "00-broderick_smith-unknown_country-2009.sfv" yEnc
        if (preg_match('/^(\w{10,}-[a-zA-Z0-9]+ ")\d\d-.+?" yEnc$/', $this->subject, $hit)) {
            return $hit[1];
        }

        return false;
    }

    public function musicSubjectNative(): bool|string|null
    {
        if (! $this->canUsePcreFastPath()) {
            return $this->musicSubjectPhp();
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $len = \strlen($this->subject);
        $scratch = self::scratch($ffi, max(256, $len + 32));
        if ($scratch === null) {
            return null;
        }
        $cap = self::$scratchCap;
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_collections_extract_music_subject($this->subject, $len, $scratch, $cap);
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return null;
            }

            return $written === 0 ? false : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalize a string by collapsing multiple spaces and encoding to UTF-8.
     *
     * @param  string  $subject  The subject to normalize
     * @return string Normalized and UTF-8 encoded string
     */
    protected function normalizeString(string $subject): string
    {
        // Collapse multiple spaces into one
        $normalized = trim(preg_replace('/\s\s+/', ' ', $subject));

        return $this->memoizeNormalizedEncoding($normalized);
    }

    private function memoizeNormalizedEncoding(string $normalized): string
    {
        // Ensure UTF-8 encoding. Collection hashes depend on this output, so it must be
        // detection over every encoding: mbstring's choice depends on the whole candidate
        // set, so even a reduced list that decodes each byte the same can pick another
        // encoding (`S/3/S*Z%7}` is UTF-8 over all encodings but UCS-2 over a UTF/UCS
        // subset). Detection is costly, but every part of a post shares its collection
        // name, so the result is memoized per process.
        if (isset(self::$normalizeMemo[$normalized])) {
            return self::$normalizeMemo[$normalized];
        }

        if (count(self::$normalizeMemo) >= self::NORMALIZE_MEMO_SIZE) {
            self::$normalizeMemo = [];
        }

        self::$allEncodings ??= mb_list_encodings();

        return self::$normalizeMemo[$normalized] = mb_convert_encoding($normalized, 'UTF-8', self::$allEncodings);
    }

    /**
     * Check if the current group is a music group.
     *
     * @return bool True if the group is music-related, false otherwise
     */
    protected function isMusicGroup(): bool
    {
        return (self::$useRustFfi ? $this->isMusicGroupNative() : null)
            ?? $this->isMusicGroupPhp();
    }

    public function isMusicGroupPhp(): bool
    {
        return preg_match('/\.(flac|lossless|mp3|music|sounds)/', $this->groupName) === 1;
    }

    public function isMusicGroupNative(): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_collections_is_music_group($this->groupName, \strlen($this->groupName));
            if (! \is_int($status) || $status < 0) {
                return null;
            }

            return $status === 1;
        } catch (Throwable) {
            return null;
        }
    }

    private function canUsePcreFastPath(): bool
    {
        return $this->e0 === self::REGEX_FILE_EXTENSIONS
            && ini_get('pcre.backtrack_limit') === '1000000'
            && ini_get('pcre.recursion_limit') === '100000'
            && \in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8'], true);
    }
}
