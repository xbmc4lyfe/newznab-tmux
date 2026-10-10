<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cleans names for collections/imports/namefixer.
 *
 * This class processes Usenet subject lines to extract clean collection names
 * by removing common patterns like file extensions, sizes, and yEnc markers.
 */
class CollectionsCleaningService
{
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
     * The encodings that can decode printable ASCII differently from UTF-8 (2- and
     * 4-byte code units), plus UTF-8 itself. See normalizeString().
     *
     * @var list<string>
     */
    private const array ASCII_DETECTION_ENCODINGS = [
        'UCS-4', 'UCS-4BE', 'UCS-4LE', 'UCS-2', 'UCS-2BE', 'UCS-2LE',
        'UTF-32', 'UTF-32BE', 'UTF-32LE', 'UTF-16', 'UTF-16BE', 'UTF-16LE', 'UTF-8',
    ];

    /**
     * Every encoding mbstring supports, cached per process.
     *
     * @var list<string>|null
     */
    private static ?array $allEncodings = null;

    /**
     * ASCII_DETECTION_ENCODINGS in mb_list_encodings() order, cached per process.
     *
     * @var list<string>|null
     */
    private static ?array $asciiDetectionEncodings = null;

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
     * Clean music group subjects with generic patterns.
     *
     * @return array<string, mixed> Returns ['id' => match_type, 'name' => cleaned_name]
     */
    protected function cleanMusicSubject(): array
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
     * Enhance short or generic subjects by extracting additional information.
     *
     * @param  string  $cleanSubject  The cleaned subject that is too short
     * @return string Enhanced subject string
     */
    protected function enhanceShortSubject(string $cleanSubject): string
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
        // Pattern: Broderick_Smith-Unknown_Country-2009-404 "00-broderick_smith-unknown_country-2009.sfv" yEnc
        if (preg_match('/^(\w{10,}-[a-zA-Z0-9]+ ")\d\d-.+?" yEnc$/', $this->subject, $hit)) {
            return $hit[1];
        }

        return false;
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

        // Ensure UTF-8 encoding. Collection hashes depend on this output, so it must
        // match detection over every encoding, which can rewrite even plain ASCII
        // (some even-length strings are detected as UCS-2). For printable ASCII without
        // the UTF-7 (+), UTF7-IMAP (&) and HZ (~) escape characters, every encoding
        // outside ASCII_DETECTION_ENCODINGS decodes like UTF-8 and comes after it in
        // mb_list_encodings(), so detecting over just those, kept in list order, picks
        // the same encoding while scanning 13 encodings instead of about 80.
        self::$allEncodings ??= mb_list_encodings();
        self::$asciiDetectionEncodings ??= array_values(array_intersect(self::$allEncodings, self::ASCII_DETECTION_ENCODINGS));
        $candidates = preg_match('/^[\x20-\x25\x27-\x2a\x2c-\x7d]*$/', $normalized) === 1
            ? self::$asciiDetectionEncodings
            : self::$allEncodings;

        return mb_convert_encoding($normalized, 'UTF-8', $candidates);
    }

    /**
     * Check if the current group is a music group.
     *
     * @return bool True if the group is music-related, false otherwise
     */
    protected function isMusicGroup(): bool
    {
        return preg_match('/\.(flac|lossless|mp3|music|sounds)/', $this->groupName) === 1;
    }
}
