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

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all eight runs (min 2.06x; see the W43 parity test). */
    public const bool W431_RUST_FFI_DEFAULT = true;

    public const string W431_FFI_CDEF = <<<'C'
        ssize_t nntmux_w431_collections_subject_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W431_SUMMARY_KEYS = ['total_subjects', 'empty_subject_count', 'yenc_suffix_count', 'size_tagged_count', 'file_extension_count', 'part_counter_count', 'music_group_count', 'invalid_utf8_count'];

    /** Largest Wave 43.1 input frame passed to Rust. */
    private const int W431_MAX_INPUT = 16777216;

    /**
     * Wave 43.1 entries: strings and integers as-is, the first non-null `$fields` value of an array row,
     * otherwise ''. Objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $fields
     * @return list<string>
     */
    public static function w431Entries(array $input, array $fields): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (\is_array($item)) {
                $value = null;
                foreach ($fields as $field) {
                    if (isset($item[$field])) {
                        $value = $item[$field];
                        break;
                    }
                }
                $item = $value;
            }
            $entries[] = \is_string($item) || \is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /**
     * Calls a Wave 43.1 export with the w431Entries() of `$rows` and decodes its `i64le` counts. Entries
     * are framed as `u32le count, u32le 0xFFFFFFFF, NUL-joined bytes`, or as `u32le count, u32le lengths,
     * bytes` when an entry contains NUL. `$ffi` is the caller's cached handle. Returns null (fall back
     * to PHP) when the frame is too large, the library or symbol is unavailable, or the output fails
     * validation.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $fields
     * @return array<int, int>|null
     */
    public static function w431Invoke(FFI|false|null &$ffi, string $cdef, string $symbol, array $rows, array $fields, int $keys): ?array
    {
        // Same extraction as w431Entries(), inlined to keep marshaling to one pass.
        $entries = [];
        foreach ($rows as $item) {
            if (\is_array($item)) {
                $value = null;
                foreach ($fields as $field) {
                    if (isset($item[$field])) {
                        $value = $item[$field];
                        break;
                    }
                }
                $item = $value;
            }
            $entries[] = \is_string($item) || \is_int($item) ? (string) $item : '';
        }
        $count = \count($entries);
        $blob = implode("\0", $entries);
        $input = $count > 0 && substr_count($blob, "\0") === $count - 1
            ? pack('VV', $count, 0xFFFFFFFF).$blob
            : pack('V*', $count, ...array_map(strlen(...), $entries)).implode('', $entries);
        if (\strlen($input) > self::W431_MAX_INPUT) {
            return null;
        }
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w431', $cdef) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, $keys * 8, $capacity);
            if ($out === null) {
                return null;
            }
            $written = $ffi->{$symbol}($input, \strlen($input), $out, $capacity);
            if ($written !== $keys * 8) {
                return null;
            }
            $values = unpack('q*', FFI::string($out, $written));
            if (! \is_array($values) || $values[1] !== $count || min($values) < 0 || max($values) > $count) {
                return null;
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    private static ?bool $w431UseRustFfi = null;

    private static FFI|false|null $w431Ffi = null;

    public static function useW431RustFfi(bool $enable = true): void
    {
        self::$w431UseRustFfi = $enable;
    }

    public static function usesW431RustFfi(): bool
    {
        return self::$w431UseRustFfi ?? self::W431_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW431RustFfi(): void
    {
        self::$w431UseRustFfi = null;
        self::$w431Ffi = null;
    }

    /**
     * Summarizes trimmed article subjects with the cleaner's patterns: a REGEX_END yEnc suffix, a
     * REGEX_SUBJECT_SIZE size, a quoted `.ext"` ending, a `[n/m]` or `(n/m)` counter, a music group token
     * (isMusicGroupPhp()) and invalid UTF-8.
     * Entries come from strings, integers, or the first non-null subject, name array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeSubjectBatch(array $rows): array
    {
        return self::usesW431RustFfi() ? self::summarizeSubjectBatchNative($rows) : self::summarizeSubjectBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeSubjectBatchNative(array $rows): array
    {
        $values = self::w431Invoke(self::$w431Ffi, self::W431_FFI_CDEF, 'nntmux_w431_collections_subject_batch', $rows, ['subject', 'name'], \count(self::W431_SUMMARY_KEYS));

        return $values === null ? self::summarizeSubjectBatchPhp($rows) : array_combine(self::W431_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeSubjectBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W431_SUMMARY_KEYS, 0);
        foreach (self::w431Entries($rows, ['subject', 'name']) as $entry) {
            $summary['total_subjects']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_subject_count']++;

                continue;
            }
            $summary['yenc_suffix_count'] += (int) (preg_match('/'.self::REGEX_END, $entry) === 1);
            $summary['size_tagged_count'] += (int) (preg_match('/'.self::REGEX_SUBJECT_SIZE.'/', $entry) === 1);
            $summary['file_extension_count'] += (int) (preg_match('/\.[A-Za-z0-9]{2,4}"/', $entry) === 1);
            $summary['part_counter_count'] += (int) (preg_match('/[\(\[]\d+\/\d+[\)\]]/', $entry) === 1);
            $summary['music_group_count'] += (int) (preg_match('/\.(flac|lossless|mp3|music|sounds)/', $entry) === 1);
            $summary['invalid_utf8_count'] += (int) (preg_match('//u', $entry) !== 1);
        }

        return $summary;
    }

    /**
     * Enabled: the paired benchmark in the W54 parity test measures 4.44x common mean, 3.90x common
     * p99, 3.18x uncommon mean and 2.25x uncommon p99 (per-metric minimum of three runs), above the
     * 1.5x rule.
     */
    private const bool W54_RUST_FFI_DEFAULT = true;

    private const string W54_FFI_CDEF = 'ssize_t nntmux_w541_subject_feature_mask_batch(const char *subjects, size_t subjects_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 54 batch routed to Rust or PHP, then restores the routing. */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    public const int W54_FEATURE_YENC_SUFFIX = 1;

    public const int W54_FEATURE_SIZE_TAG = 2;

    public const int W54_FEATURE_FILE_COUNTER = 4;

    public const int W54_FEATURE_QUOTED_NAME = 8;

    public const int W54_FEATURE_PAR2 = 16;

    public const int W54_FEATURE_RAR_PART = 32;

    public const int W54_FEATURE_PART_COUNTER = 64;

    /**
     * One `W54_FEATURE_*` mask per collection subject: a `yEnc` suffix, a size tag
     * ({@see REGEX_SUBJECT_SIZE}), a `[n/m]` file counter, a quoted file name, a PAR2 file
     * (`.par2` or `.volN+M`), a `.partN.rar` volume and a `(n/m)` part counter. Byte patterns,
     * ASCII case-insensitive.
     *
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function subjectFeatureMasks(array $subjects): array
    {
        return self::usesW54RustFfi() ? self::subjectFeatureMasksNative($subjects) : self::subjectFeatureMasksPhp($subjects);
    }

    /**
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function subjectFeatureMasksPhp(array $subjects): array
    {
        $masks = [];
        foreach ($subjects as $subject) {
            $mask = 0;
            if (preg_match('/yEnc\z/i', $subject) === 1) {
                $mask |= self::W54_FEATURE_YENC_SUFFIX;
            }
            if (preg_match('/\d+(?:[.,]\d+)? [kmg]b/i', $subject) === 1) {
                $mask |= self::W54_FEATURE_SIZE_TAG;
            }
            if (preg_match('/\[\d+\/\d+\]/', $subject) === 1) {
                $mask |= self::W54_FEATURE_FILE_COUNTER;
            }
            if (preg_match('/"[^"]+"/', $subject) === 1) {
                $mask |= self::W54_FEATURE_QUOTED_NAME;
            }
            if (preg_match('/\.(?:par2|vol\d+\+\d+)/i', $subject) === 1) {
                $mask |= self::W54_FEATURE_PAR2;
            }
            if (preg_match('/\.part\d+\.rar/i', $subject) === 1) {
                $mask |= self::W54_FEATURE_RAR_PART;
            }
            if (preg_match('/\(\d+\/\d+\)/', $subject) === 1) {
                $mask |= self::W54_FEATURE_PART_COUNTER;
            }
            $masks[] = $mask;
        }

        return $masks;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a subject
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $subjects
     * @return list<int>
     */
    public static function subjectFeatureMasksNative(array $subjects): array
    {
        $count = \count($subjects);
        $buffer = $count === 0 ? null : self::w54Buffer($count);
        if ($buffer === null) {
            return self::subjectFeatureMasksPhp($subjects);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $subjects);
        try {
            // @phpstan-ignore method.notFound
            $masks = self::w54Decode($out, $ffi->nntmux_w541_subject_feature_mask_batch($joined, \strlen($joined), $count, $out, $cap), $count);
        } catch (Throwable) {
            $masks = null;
        }

        return $masks !== null && \count($masks) === $count ? $masks : self::subjectFeatureMasksPhp($subjects);
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: FFI\CData, 2: int}|null
     */
    private static function w54Buffer(int $words): ?array
    {
        $buffer = self::w54RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w54Decode(FFI\CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 54 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('collections_cleaning_w54', self::W54_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof FFI\CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    // ========================================================================
    // WAVE 67.1 BATCH HELPER
    // ========================================================================

    /** Benchmark-gated default; see RustXxxPipeCollectionCleanupCleaningConfigProviderRepoW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    public const string W67_COLLECTIONS_CLEANING_FFI_CDEF = <<<'C'
        ssize_t nntmux_w671_collections_cleaning_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w67CollectionsCleaningPlanBatch(). */
    public const int W67_COLLECTIONS_CLEANING_TRAILER = 34;

    public static function usesW67RustFfi(): bool
    {
        $override = self::w67RustFfiState();

        return \is_bool($override) ? $override : self::W67_RUST_FFI_DEFAULT;
    }

    /** Forces the CollectionsCleaningService Wave 67 Rust path on until reset. */
    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    /** Forces the CollectionsCleaningService Wave 67 PHP path until reset. */
    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    /**
     * Runs $callback with the CollectionsCleaningService Wave 67 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 67.1 collection subject plans, one per `subject|group` row (the group trimmed and lowercased). The subject loses a
     * trailing yEnc tag with up to three `-`, `_` or whitespace bytes before it, and a trailing `(part/total)` counter of
     * 1-10 digit numbers with the spaces, tabs, `-` and `_` before it; the rest is lowercased, whitespace runs collapse to one
     * space and it is trimmed. Flags: 1 yEnc, 2 counter, 4 `.par2`, 8 `.vol` with `+`, 16 quoted file name, 32 `.partN`
     * file, 64 music group (sounds/mp3/music), 128 `N kB/MB/GB` size, 256 non-ASCII, 512 control byte. The quoted name's
     * extension code: 0 none, 1-13 rar, par2, nfo, sfv, nzb, mkv, mp4, avi, 7z, zip, jpg, mp3, flac, 14 other. Decision:
     * 0 empty subject, 1 nothing left after cleaning, 2 music, 3 generic with a quoted name, 4 generic. Collection keys are
     * `music:`/`generic:` + cleaned subject + NUL + group. Per item `decision | flags << 3 | ext << 13 | min(part, 4095) << 17
     * | min(total, 4095) << 29 | (fnv1a32(key) & 0xFFFFF) << 41` (no hash below decision 2); then 5 decision, 10 flag and
     * 15 extension counts, the part sum, the largest total and the distinct keys and groups.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67CollectionsCleaningPlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67CollectionsCleaningPlanBatchNative($items) : self::w67CollectionsCleaningPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67CollectionsCleaningPlanBatchNative(array $items): array
    {
        return self::w67Invoke($items, \count($items) + self::W67_COLLECTIONS_CLEANING_TRAILER) ?? self::w67CollectionsCleaningPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67CollectionsCleaningPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W67_COLLECTIONS_CLEANING_TRAILER, 0);
        $keys = [];
        $groups = [];
        foreach ($items as $item) {
            $item = (string) $item;
            [$subject, $rawGroup] = explode('|', $item, 2) + ['', ''];
            $group = strtolower(trim($rawGroup));
            [$stripped, $part, $total, $flags] = self::w67StripSubject($subject);
            $lower = strtolower($subject);
            $flags |= (str_contains($lower, '.par2') ? 4 : 0) | (str_contains($lower, '.vol') && str_contains($lower, '+') ? 8 : 0);
            $ext = 0;
            $open = strpos($subject, '"');
            $close = $open === false ? false : strpos($subject, '"', $open + 1);
            if ($open !== false && $close !== false) {
                $flags |= 16;
                $file = substr($lower, $open + 1, $close - $open - 1);
                $dot = strrpos($file, '.');
                $ext = $dot === false ? 14 : (self::W67_EXTENSIONS[substr($file, $dot + 1)] ?? 14);
                $flags |= preg_match('/\.part[0-9]/', $file) === 1 ? 32 : 0;
            }
            $music = self::w67ContainsAny($group, self::W67_MUSIC_GROUPS);
            $flags |= ($music ? 64 : 0) | (preg_match('/[0-9] [kmgKMG][bB]/', $subject) === 1 ? 128 : 0) | (preg_match('/[\x80-\xff]/', $item) === 1 ? 256 : 0)
                | (strcspn($item, self::W67_CONTROL_BYTES) !== \strlen($item) ? 512 : 0);
            $cleaned = trim((string) preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', strtolower($stripped)), ' ');
            $decision = match (true) {
                trim($subject) === '' => 0,
                $cleaned === '' => 1,
                $music => 2,
                ($flags & 16) !== 0 => 3,
                default => 4,
            };
            $hash = 0;
            if ($decision >= 2) {
                $key = ($music ? 'music:' : 'generic:').$cleaned."\0".$group;
                $hash = (int) hexdec(hash('fnv1a32', $key)) & 0xFFFFF;
                $keys[$key] = true;
            }
            if ($group !== '') {
                $groups[$group] = true;
            }
            $tail[$decision]++;
            for ($b = 0; $b < 10; $b++) {
                $tail[5 + $b] += $flags >> $b & 1;
            }
            $tail[15 + $ext]++;
            $tail[30] += min($part, self::W67_SUM_CAP);
            $tail[31] = max($tail[31], $total);
            $out[] = $decision | $flags << 3 | $ext << 13 | min($part, 4095) << 17 | min($total, 4095) << 29 | $hash << 41;
        }
        $tail[32] = \count($keys);
        $tail[33] = \count($groups);
        array_push($out, ...$tail);

        return $out;
    }

    private const string W67_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private const string W67_DIGITS = '0123456789';

    private const int W67_SUM_CAP = 1 << 40;

    /** @var list<string> */
    private const array W67_MUSIC_GROUPS = ['sounds', 'mp3', 'music'];

    /** Quoted file extension => code; any other quoted name is 14. */
    private const array W67_EXTENSIONS = ['rar' => 1, 'par2' => 2, 'nfo' => 3, 'sfv' => 4, 'nzb' => 5, 'mkv' => 6, 'mp4' => 7, 'avi' => 8, '7z' => 9,
        'zip' => 10, 'jpg' => 11, 'mp3' => 12, 'flac' => 13];

    /** $s without a trailing yEnc tag and up to three separators before it, or null without one. */
    private static function w67StripYenc(string $s): ?string
    {
        $t = rtrim($s);
        if (\strlen($t) < 4 || strcasecmp(substr($t, -4), 'yenc') !== 0) {
            return null;
        }
        $s = substr($t, 0, -4);
        for ($k = 0; $k < 3 && $s !== '' && str_contains("-_ \t\n\r\x0B\x0C", $s[-1]); $k++) {
            $s = substr($s, 0, -1);
        }

        return $s;
    }

    /**
     * `[subject before, part, total]` of a trailing `(part/total)` counter of 1-10 digit numbers, or null.
     *
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function w67StripCounter(string $s): ?array
    {
        $t = rtrim($s, " \t");
        if ($t === '' || $t[-1] !== ')') {
            return null;
        }
        $open = strrpos(substr($t, 0, -1), '(');
        if ($open === false) {
            return null;
        }
        $inner = substr($t, $open + 1, -1);
        $slash = strpos($inner, '/');
        if ($slash === false) {
            return null;
        }
        $a = substr($inner, 0, $slash);
        $b = substr($inner, $slash + 1);
        $la = \strlen($a);
        $lb = \strlen($b);
        if ($la < 1 || $la > 10 || $lb < 1 || $lb > 10 || strspn($a, self::W67_DIGITS) !== $la || strspn($b, self::W67_DIGITS) !== $lb) {
            return null;
        }

        return [rtrim(substr($t, 0, $open), " \t-_"), (int) $a, (int) $b];
    }

    /**
     * `[subject without the yEnc tag and part counter, part, total, flags]`: the yEnc tag (flag 1) is stripped before and,
     * when it was not, after the counter (flag 2).
     *
     * @return array{0: string, 1: int, 2: int, 3: int}
     */
    private static function w67StripSubject(string $subject): array
    {
        $flags = 0;
        $part = $total = 0;
        $rest = self::w67StripYenc($subject);
        if ($rest !== null) {
            $subject = $rest;
            $flags |= 1;
        }
        $counter = self::w67StripCounter($subject);
        if ($counter !== null) {
            [$subject, $part, $total] = $counter;
            $flags |= 2;
        }
        if (($flags & 1) === 0) {
            $rest = self::w67StripYenc($subject);
            if ($rest !== null) {
                $subject = $rest;
                $flags |= 1;
            }
        }

        return [$subject, $part, $total, $flags];
    }

    /**
     * Whether $haystack holds any of $needles.
     *
     * @param  list<string>  $needles
     */
    private static function w67ContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wave 67 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var FFI\CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var FFI\CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('collections_cleaning_w671', self::W67_COLLECTIONS_CLEANING_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 67.1 CollectionsCleaningService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w67Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w67RustFfiState('ffi');
            $out = self::w67RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof FFI\CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w67RustFfiState('lens', $count);
                if (! $column instanceof FFI\CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W67_COLLECTIONS_CLEANING_FFI_CDEF.)
            $written = $ffi->nntmux_w671_collections_cleaning_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
