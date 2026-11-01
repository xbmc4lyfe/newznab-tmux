<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\PredbSearchDocument;
use App\Traits\DetectsHashedNames;

/**
 * Utility class for cleaning and normalizing filenames.
 *
 * Extracts filename cleaning logic from NameFixer for better testability
 * and reusability.
 */
class FileNameCleaner
{
    public const PREDB_IDENTITY_POLICY = '6c53f5c588a38da6b02de28ce972fc69903a6969e860cabc71c1b3a8b55ff0ca';

    use DetectsHashedNames;

    protected NzbSplitUnwrapper $nzbSplitUnwrapper;

    /** @var array<string, string|false>|null */
    private ?array $matchingReuse = null;

    /**
     * Reuse pure filename cleaning only during one synchronous PreDB lookup.
     * Custom cleaners may depend on mutable state and must retain every call.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $lookup
     * @return TResult
     */
    public function withMatchingReuse(callable $lookup, bool $joinActiveLookup = false): mixed
    {
        if (get_class($this) !== self::class || ($joinActiveLookup && $this->matchingReuse !== null)) {
            return $lookup();
        }

        $previous = $this->matchingReuse;
        $this->matchingReuse = [];
        try {
            return $lookup();
        } finally {
            $this->matchingReuse = $previous;
        }
    }

    public function __construct(?NzbSplitUnwrapper $nzbSplitUnwrapper = null)
    {
        $this->nzbSplitUnwrapper = $nzbSplitUnwrapper ?? new NzbSplitUnwrapper;
    }

    /**
     * Generic DVD structure filenames and similarly low-information names.
     *
     * @var list<string>
     */
    private const IGNORABLE_NAME_PATTERNS = [
        '/^(?:audio|video)[._-]?ts$/i',
        '/^vts[._-]?\d{1,2}[._-]?\d{1,2}$/i',
        '/^\d{1,3}$/',
    ];

    /**
     * Archive extension patterns to remove.
     */
    private const ARCHIVE_PATTERNS = [
        '/\.part\d{1,4}\.rar$/i',
        '/\.r\d{2,4}$/i',
        '/\.rar$/i',
        '/\.z\d{2}$/i',
        '/\.zip$/i',
        '/\.7z\.\d{3}$/i',
        '/\.7z$/i',
        '/\.vol\d+[\+\-]\d+\.par2?$/i',
        '/\.par2?$/i',
        '/\.(tar|gz|bz2|xz|lz|lzma|cab|arj|ace|arc)$/i',
        '/\.\d{3}$/i',
    ];

    /** @return list<string> */
    public static function archiveSuffixPatterns(): array
    {
        return [
            '/\.7z\.\d{2,4}$/i',
            '/\.part\d+\.rar$/i',
            ...self::ARCHIVE_PATTERNS,
            '/\.(zst|tgz|lz4|enc|rev)$/i',
        ];
    }

    /**
     * Video file extension pattern.
     */
    private const VIDEO_EXTENSIONS = '/\.(mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|webm|ogv|3gp|asf|rm|rmvb|f4v)$/i';

    /**
     * Audio file extension pattern.
     */
    private const AUDIO_EXTENSIONS = '/\.(mp3|flac|m4a|aac|ogg|wav|wma|ape|opus|mka|ac3|dts|eac3|truehd|mpc|shn|tak|tta|wv)$/i';

    /**
     * Image and misc file extension pattern.
     */
    private const IMAGE_EXTENSIONS = '/\.(nfo|sfv|nzb|srr|srs|jpg|jpeg|png|gif|bmp|tiff?|webp|pdf|txt|diz|md5|sha1|cue|log)$/i';

    /**
     * Ebook file extension pattern.
     */
    private const EBOOK_EXTENSIONS = '/\.(epub|mobi|azw3?|pdf|djvu|cbr|cbz|fb2|lit|prc|opf)$/i';

    /**
     * Game/App file extension pattern.
     */
    private const GAMEAPP_EXTENSIONS = '/\.(iso|bin|cue|mdf|mds|nrg|img|ccd|sub|exe|msi|dmg|pkg|apk|xap|appx|deb|rpm)$/i';

    /**
     * Subtitle file extension pattern.
     */
    private const SUBTITLE_EXTENSIONS = '/\.(srt|sub|idx|ass|ssa|vtt|sup)$/i';

    /**
     * Strip archive wrappers and part/volume markers repeatedly (`.rar.par2`, `.tar.zst`, `.7z.003`,
     * `.part01.rar`), then at most one payload extension. Only one, so a title that ends in a token that is
     * also an extension (`Movie.2020.1080p.DTS.mkv`) keeps it.
     */
    public function stripFileSuffixes(string $fileName): string
    {
        return $this->stripKnownFileSuffixes($fileName, preserveCodecAfterWrapper: true);
    }

    /**
     * Validate only bytes appended after an already confirmed complete canonical title.
     * Codec words here are payload extensions, so title-preservation heuristics do not apply.
     */
    public function isKnownFileSuffix(string $suffix): bool
    {
        return $suffix === '' || $this->stripKnownFileSuffixes('Title'.$suffix, preserveCodecAfterWrapper: false) === 'Title';
    }

    private function stripKnownFileSuffixes(string $fileName, bool $preserveCodecAfterWrapper): string
    {
        $wrappers = [
            ...self::archiveSuffixPatterns(),
            '/\.vol\d+[+-]\d+$/i',
            '/\.part\d*$/i',
            '/\.(nfo|sfv|nzb|srr|srs)$/i',
        ];

        $original = $fileName;
        do {
            $previous = $fileName;
            $fileName = preg_replace($wrappers, '', $fileName) ?? $fileName;
        } while ($fileName !== $previous);

        // After a wrapper (`.rar`, `.par2`) came off, a trailing codec word is part of the title, not a file
        // extension: `Movie.2020.1080p.DTS.rar` is the title `Movie.2020.1080p.DTS`.
        if ($preserveCodecAfterWrapper && $fileName !== $original
            && (preg_match(self::AUDIO_EXTENSIONS, $fileName) === 1
                || preg_match('/\.(ts|divx|wmv|mpg|mpeg|rm|rmvb|m1v|m2v|mpv|mpgv|mp2|m4b|aiff?|amr|ra|w64)$/i', $fileName) === 1)) {
            return $fileName;
        }

        // Document and playlist files that the shared payload lists do not cover.
        $extraPayload = '/\.(docx?|rtf|xlsx?|pptx?|odt|m3u8?|txt|m4b|aiff?|amr|m2v|m1v|mpv|mpgv|qt|mp2|ra|w64|ifo|mks|ogm)$/i';

        foreach ([self::VIDEO_EXTENSIONS, self::AUDIO_EXTENSIONS, self::IMAGE_EXTENSIONS, self::EBOOK_EXTENSIONS, self::GAMEAPP_EXTENSIONS, self::SUBTITLE_EXTENSIONS, $extraPayload] as $payload) {
            $stripped = preg_replace($payload, '', $fileName) ?? $fileName;
            if ($stripped !== $fileName) {
                return $stripped;
            }
        }

        return $fileName;
    }

    /** Preserve literal spelling for nomination; generic cleaning only validates usability. */
    public function cleanForFilenameDiscovery(OriginalFilenameEvidence $evidence): string|false
    {
        return $this->cleanForMatching($evidence->stem, preserveLeadingNumber: true) === false
            ? false
            : $evidence->stem;
    }

    /**
     * Clean a filename for PreDB matching.
     *
     * @param  string  $fileName  The filename to clean
     * @return string|false The cleaned filename or false if invalid
     */
    public function cleanForMatching(string $fileName, bool $preserveLeadingNumber = false): string|false
    {
        // Bound retained entries and raw key bytes; oversize inputs still use the normal cleaner.
        if ($this->matchingReuse === null || strlen($fileName) > 4096) {
            return $this->computeForMatching($fileName, $preserveLeadingNumber);
        }

        $key = ($preserveLeadingNumber ? '1:' : '0:').$fileName;
        if (array_key_exists($key, $this->matchingReuse)) {
            return $this->matchingReuse[$key];
        }

        $cleaned = $this->computeForMatching($fileName, $preserveLeadingNumber);
        if (count($this->matchingReuse) < 256) {
            $this->matchingReuse[$key] = $cleaned;
        }

        return $cleaned;
    }

    private function computeForMatching(string $fileName, bool $preserveLeadingNumber): string|false
    {
        if (! mb_check_encoding($fileName, 'UTF-8')
            || preg_match('/(?![\x00-\x7F])\p{C}/u', $fileName) !== 0) {
            return false;
        }

        // Keep ASCII control cleanup without erasing valid Unicode spelling into another title.
        $fileName = preg_replace('/[\x00-\x1F\x7F]/', '', $fileName);

        if ($fileName === '' || str_starts_with($fileName, '.')) {
            return false;
        }

        $fileName = $this->nzbSplitUnwrapper->stripLiteralSubjectTrailer($fileName);

        // Extract filename from path
        $fileName = $this->extractFilenameFromPath($fileName);

        $fileName = $this->extractNzbSplitName($fileName) ?? $fileName;

        if ($this->isIgnorableForMatching($fileName)) {
            return false;
        }

        // Remove sample/proof indicators
        $fileName = preg_replace('/[\.\-_](sample|proof|subs?|thumbs?|cover|screens?)[\.\-_]?$/i', '', $fileName);

        // Remove archive extensions
        if (str_contains($fileName, '.')) {
            foreach (self::ARCHIVE_PATTERNS as $pattern) {
                $fileName = preg_replace($pattern, '', $fileName);
            }

            // Remove media extensions
            $fileName = preg_replace(self::VIDEO_EXTENSIONS, '', $fileName);
            $fileName = preg_replace(self::AUDIO_EXTENSIONS, '', $fileName);
            $fileName = preg_replace(self::IMAGE_EXTENSIONS, '', $fileName);
            $fileName = preg_replace(self::EBOOK_EXTENSIONS, '', $fileName);
            $fileName = preg_replace(self::GAMEAPP_EXTENSIONS, '', $fileName);
            $fileName = preg_replace(self::SUBTITLE_EXTENSIONS, '', $fileName);
        }

        // Remove part/volume indicators
        $fileName = preg_replace('/[\.\-_]?(part|vol|cd|dvd|disc|disk)\d*$/i', '', $fileName);

        // Remove leading track numbers
        if (! $preserveLeadingNumber) {
            $fileName = preg_replace('/^\d{1,3}[\.\-_\s]+(?=[A-Za-z])/', '', $fileName);
        }

        // Trim whitespace and punctuation
        $fileName = trim($fileName, " \t\n\r\0\x0B.-_");

        if ($fileName === '' || $this->looksLikeStructuralJunk($fileName)) {
            return false;
        }

        return $fileName;
    }

    /** Numeric-only aliases cannot establish a canonical release identity. */
    public function matchesPredbFilenameEvidence(string $fileName, string $query): bool
    {
        $cleaned = $this->cleanForMatching($fileName, preserveLeadingNumber: true);

        return $cleaned !== false
            && preg_match('/^[\p{N}\p{P}\p{Z}\s]+$/uD', $cleaned) === 0
            && PredbSearchDocument::exactValue($cleaned) === PredbSearchDocument::exactValue($query);
    }

    /**
     * Extract filename from a path.
     *
     * @param  string  $path  Full path or filename
     * @return string The filename portion
     */
    public function extractFilenameFromPath(string $path): string
    {
        if (preg_match('/[\\\\\/]([^\\\\\/]+)$/', $path, $match)) {
            return $match[1];
        }

        return $path;
    }

    /**
     * Normalize a candidate title.
     *
     * @param  string  $title  The title to normalize
     * @return string The normalized title
     */
    public function normalizeCandidateTitle(string $title): string
    {
        $t = trim($title);

        $t = $this->extractNzbSplitName($t) ?? $t;

        // Remove common video file extensions
        $t = preg_replace('/\.(mkv|avi|mp4|m4v|mpg|mpeg|wmv|flv|mov|ts|vob|iso|divx)$/i', '', $t) ?? $t;

        // Remove archive, metadata, and common document/file extensions
        $t = preg_replace(
            '/\.(par2?|nfo|sfv|nzb|rar|zip|7z|gz|tar|bz2|xz|r\d{2,3}|\d{3}|pkg|exe|msi|jpe?g|png|gif|bmp|pdf|epub|mobi|azw3?|djvu|cbr|cbz|fb2|lit|prc|opf|txt|log)$/i',
            '',
            $t
        ) ?? $t;

        // Remove common trailing segment markers
        $t = preg_replace('/[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$/i', '', $t) ?? $t;

        // Collapse multiple spaces/underscores
        $t = preg_replace('/[\s_]+/', ' ', $t) ?? $t;

        // Trim stray punctuation
        return trim($t, " .-_\t\r\n");
    }

    /**
     * Format a candidate for storage in searchname.
     */
    public function formatSearchName(string $title, ?string $normalizedFallback = null): string
    {
        $formatted = trim($title);

        $formatted = $this->extractNzbSplitName($formatted) ?? $formatted;

        // Remove common media/archive extensions while keeping scene separators.
        $formatted = preg_replace(self::VIDEO_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::AUDIO_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::IMAGE_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::EBOOK_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::GAMEAPP_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::SUBTITLE_EXTENSIONS, '', $formatted) ?? $formatted;

        foreach (self::ARCHIVE_PATTERNS as $pattern) {
            $formatted = preg_replace($pattern, '', $formatted) ?? $formatted;
        }

        $formatted = preg_replace('/[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$/i', '', $formatted) ?? $formatted;
        $formatted = trim($formatted, " .-_\t\r\n");

        if ($formatted === '') {
            return $normalizedFallback ?? $this->normalizeCandidateTitle($title);
        }

        if (! $this->looksLikeSceneRelease($formatted)) {
            return $normalizedFallback ?? $this->normalizeCandidateTitle($title);
        }

        $formatted = preg_replace('/[ _]+/', '.', $formatted) ?? $formatted;
        $formatted = preg_replace('/\.{2,}/', '.', $formatted) ?? $formatted;

        return trim($formatted, " .-_\t\r\n");
    }

    /**
     * Check if a title is plausible for a release.
     *
     * @param  string  $title  The title to check
     * @return bool True if the title looks like a valid release name
     */
    public function isPlausibleReleaseTitle(string $title): bool
    {
        $t = trim($title);

        if ($t === '' || strlen($t) < 12) {
            return false;
        }

        $wordCount = preg_match_all('/[A-Za-z0-9]{3,}/', $t);
        if ($wordCount < 2) {
            return false;
        }

        if ($this->looksLikeHashedName($t)) {
            return false;
        }

        // Reject if still ends with segment marker
        if (preg_match('/(?:^|[.\-_ ])(?:part|vol|r)\d+(?:\+\d+)?$/i', $t)) {
            return false;
        }

        // Reject generic installer filenames
        if (preg_match('/^(setup|install|installer|patch|update|crack|keygen)\d*[\s._-]/i', $t)) {
            return false;
        }

        // Check for valid release indicators
        if (preg_match('/[-.][A-Za-z0-9]{2,}$/', $t)) {
            return true;
        }
        $hasTV = (bool) preg_match('/\bS\d{1,2}[Eex]\d{1,3}\b/i', $t) || SeasonPackDetector::isSeasonPack($t);
        $hasQuality = (bool) preg_match('/\b(480p|720p|1080p|2160p|4k|webrip|web[ .-]?dl|bluray|bdrip|dvdrip|hdtv|hdrip|xvid|x264|x265|hevc|h\.?264|ts|cam|r5|proper|repack)\b/i', $t);
        if ($hasTV && $hasQuality) {
            return true;
        }
        $hasYear = (bool) preg_match('/\b(19|20)\d{2}\b/', $t);
        if ($hasYear && ($hasQuality || $hasTV)) {
            return true;
        }
        if (preg_match('/\bXXX\b/i', $t)) {
            return true;
        }

        return $hasQuality || $hasTV;
    }

    /**
     * Check if a string looks like a hashed/obfuscated name.
     *
     * @param  string  $title  The title to check
     * @return bool True if the title appears to be hashed/obfuscated
     */
    public function looksLikeHashedName(string $title): bool
    {
        return $this->isHashedOrGibberish($title);
    }

    /**
     * Clean filename for title matching against PreDB.
     *
     * @param  string  $filename  The filename to clean
     * @return string The cleaned filename
     */
    public function cleanForTitleMatch(string $filename): string
    {
        $filename = $this->extractNzbSplitName($filename) ?? $filename;

        // Remove file extension
        $clean = preg_replace('/\.(mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|nfo|sfv|nzb|srr|srs|rar|r\d{2,4}|zip|7z|par2?|vol\d+[\+\-]\d+|\d{3})$/i', '', $filename);

        // Remove part/volume indicators
        $clean = preg_replace('/[\.\-_]?(part|vol|cd|dvd|disc|disk)\d+$/i', '', $clean);

        // Remove sample/proof indicators
        $clean = preg_replace('/[\.\-_](sample|proof|subs?)$/i', '', $clean);

        return trim($clean, " \t\n\r\0\x0B.-_");
    }

    /**
     * Check if a filename looks like a scene release.
     *
     * @param  string  $filename  The filename to check
     * @return bool True if it appears to be a scene release
     */
    public function looksLikeSceneRelease(string $filename): bool
    {
        $filename = $this->extractNzbSplitName($filename) ?? $filename;

        $baseName = preg_replace('/\.[a-z0-9]{2,4}$/i', '', $filename);

        // Check for group suffix
        if (! preg_match('/\-[A-Za-z0-9]{2,15}$/', $baseName)) {
            return false;
        }

        // Check for word separation
        if (! preg_match('/[._-]/', $baseName)) {
            return false;
        }

        // Check for common scene tags
        $sceneTags = [
            '720p', '1080p', '2160p', '4k',
            'x264', 'x265', 'hevc', 'xvid', 'divx',
            'bluray', 'bdrip', 'dvdrip', 'hdtv', 'webrip', 'web-dl', 'webdl',
            'aac', 'ac3', 'dts', 'flac', 'mp3',
            'proper', 'repack', 'internal', 'retail',
            'pal', 'ntsc', 'multi', 'dual',
        ];

        $baseNameLower = strtolower($baseName);
        foreach ($sceneTags as $tag) {
            if (str_contains($baseNameLower, $tag)) {
                return true;
            }
        }

        // Check for TV episode patterns
        if (preg_match('/s\d{1,2}e\d{1,3}/i', $baseName)) {
            return true;
        }

        // Check for year pattern
        if (preg_match('/[._-](19|20)\d{2}[._-]/i', $baseName)) {
            return true;
        }

        return false;
    }

    /** Keep only complete technical scene titles eligible for literal byte preservation. */
    public function isLiteralTechnicalSceneTitle(string $title): bool
    {
        if (! mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > 255
            || preg_match('/\p{C}/u', $title) !== 0
            || preg_match('/\A(?<stem>[\p{L}\p{N}][\p{L}\p{N}\p{M}._-]*)-[A-Za-z0-9]{2,15}\z/u', $title, $parts) !== 1
            || preg_match('/[._-](?:sample|proof|thumbs?|part\d+|vol\d+(?:[+-]\d+)?)\z/i', $title) === 1) {
            return false;
        }

        $technicalToken = '/(?:^|[._-])(?:480p|576p|720p|1080[pi]|2160p|4320p|[48]k|web[._-]?dl|webrip|bluray|bdrip|brrip|dvdrip|hdtv|sdtv|x264|x265|h[._]?26[45]|hevc|avc|xvid|divx|aac|ac3|eac3|ddp?[0-9](?:[._][0-9])?|dts(?:[._-]?hd)?|flac|mp3|truehd|pal|ntsc)(?=$|[._-])/i';

        return preg_match($technicalToken, $parts['stem']) === 1
            && $this->looksLikeSceneRelease($title)
            && $this->isPlausibleReleaseTitle($title);
    }

    public function extractNzbSplitName(string $value): ?string
    {
        return $this->nzbSplitUnwrapper->unwrap($value);
    }

    /**
     * Ignore junk inputs that should never drive PreDB matches.
     */
    protected function isIgnorableForMatching(string $fileName): bool
    {
        if (preg_match('/\.url$/i', $fileName)) {
            return true;
        }

        return $this->looksLikeStructuralJunk((string) pathinfo($fileName, PATHINFO_FILENAME));
    }

    /**
     * Detect low-information DVD structure names and numeric image files.
     */
    protected function looksLikeStructuralJunk(string $fileName): bool
    {
        $normalized = strtolower(trim($fileName, " \t\n\r\0\x0B.-_"));

        if ($normalized === '') {
            return true;
        }

        foreach (self::IGNORABLE_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }
}
