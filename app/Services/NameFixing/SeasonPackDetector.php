<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Recognises whole-season TV releases and derives a season-level release name
 * from the episode file names inside them.
 *
 * Season tokens must stand alone between separators, so audio and codec tokens
 * such as DTS5.1, Atmos7.1 or x264 are never mistaken for a season.
 */
final class SeasonPackDetector
{
    private const string CLEANING_CDEF = <<<'C'
        int32_t nntmux_season_pack_is_unclassified_episode(const char *name, size_t len);
        int32_t nntmux_season_pack_has_distinct_identities_nul(const char *names, size_t len);
        int32_t nntmux_season_pack_is_ancillary_name(const char *name, size_t len);
        int32_t nntmux_season_pack_season_folder(const char *name, size_t len);
        C;

    private static FFI|false|null $cleaningFfi = null;

    private static function cleaningLibrary(): ?FFI
    {
        if (self::$cleaningFfi === null) {
            self::$cleaningFfi = RustPipelineLibrary::resolveDomain('season_pack_cleaning', self::CLEANING_CDEF) ?? false;
        }

        return self::$cleaningFfi ?: null;
    }

    /** S01, S1, S01-S03, S01-03, S01D1, S01.Disc.2 between separators */
    private const string SEASON_TOKEN_REGEX = '/(?:^|[._ \-\[(])S\d{1,4}(?:[._ ]?D(?:is[ck])?[._ ]?\d{1,2})?(?=$|[._ \-\])])/i';

    /** Season.1, Season 2, Seasons.1-3 */
    private const string SEASON_WORD_REGEX = '/(?:^|[._ \-\[(])Seasons?[._ ]?\d{1,2}(?=$|[._ \-\])])/i';

    /** Complete.Series, Complete.Season */
    private const string COMPLETE_REGEX = '/(?:^|[._ \-\[(])Complete[._ ](?:Series|Seasons?)(?=$|[._ \-\])])/i';

    /** Potential episode markers include compound/oversized forms; admission parses strictly below. */
    private const string EPISODE_REGEX = '/(?:^|[^a-z0-9])(?:S\d+[._ ]?E\d+|\d{1,2}x\d{2,}(?=$|[^a-z0-9]|[exs]\d)|Episode[._ ]?\d{1,4}(?!\d)|EP?\d{2,3}(?![0-9a-z]))/i';

    /** Show prefix, separator, season, first episode, extra episodes (E02, -E03, -03) and the rest */
    private const string EPISODE_SPLIT_REGEX = '/^(?<prefix>.+?)(?<sep>[._ \-]+)S(?<season>\d{1,4})[._ ]?E(?<episode>\d{1,4})(?!\d)(?:[._\-]?E\d{1,4}|-\d{1,4}(?=$|[._ ]))*(?<rest>.*)$/i';

    private const string X_EPISODE_SPLIT_REGEX = '/^(?<prefix>.+?)(?<sep>[._ \-]+)(?<season>\d{1,2})x(?<episode>\d{2,3})(?![0-9a-z])(?<rest>.*)$/i';

    /** Admission must inspect the unconsumed suffix, unlike season-title derivation. */
    private const string SINGLE_EPISODE_SPLIT_REGEX = '/^(?<prefix>.+?)[._ \-]+(?:S(?<season>\d{1,4})[._ ]?E(?<episode>\d{1,4})|(?<xseason>\d{1,2})x(?<xepisode>\d{2,3}))(?!\d)(?<rest>.*)$/i';

    /** A separate season token; S01.E01 is an episode, not an explicit pack. */
    private const string ADMISSION_SEASON_TOKEN_REGEX = '/(?:^|[._ \-\[(])S\d{1,4}(?!\d)(?![._ ]?E\d)(?:[._ ]?D(?:is[ck])?[._ ]?\d{1,2})?(?=$|[._ \-\])])/i';

    /** A token where the release information starts; an episode title ends before it */
    private const string RELEASE_INFO_TOKEN_REGEX = '/^(?:\d{3,4}[pi]|4k|uhd|hdr(?:10)?(?:plus)?|dv|sdr|web|webrip|webdl|bluray|blu|bd|bdrip|bdremux|brrip|remux|dvd|dvdrip|dvdr|dvd5|dvd9|hdtv|pdtv|sdtv|hdrip|dsr|amzn|nf|dsnp|hmax|atvp|hulu|pcok|pmtp|stan|crav|x26[45]|h26[45]|hevc|avc|xvid|divx|10bit|8bit|aac\d*|e?ac3|ddp?\d*|dts|truehd|atmos|flac|proper|repack\d*|rerip|internal|multi|dual|subbed|dubbed|extended|uncut|german|french|spanish|italian|dutch|nordic|swedish|danish|finnish|norwegian|polish|russian|hindi|japanese|korean)$/i';

    /** File extensions (and volume suffixes) stripped before a file name is parsed */
    private const string EXTENSION_REGEX = '/(?:\.part\d+)?\.(?:rar|r\d{2,3}|zip|7z|\d{3}|par2|vol\d+[+-]\d+|mkv|mp4|avi|m4v|mov|wmv|mpg|mpeg|ts|m2ts|vob|webm|flv|divx|nfo|sfv|srt|sub|idx|ass|ssa|jpg|png)$/i';

    private const string SAMPLE_REGEX = '/(?:^|[._ \-])(?:sample|proof)(?:$|[._ \-])/i';

    /**
     * Whether a release name describes a whole season (or several) rather than an episode.
     */
    public static function isSeasonPack(string $name): bool
    {
        $flags = RustPipelineLibrary::seasonPackFlags($name);
        if ($flags !== null) {
            return ($flags & 2) !== 0;
        }

        return self::isSeasonPackPhp($name);
    }

    public static function isSeasonPackPhp(string $name): bool
    {
        if (self::isEpisodeNamePhp($name)) {
            return false;
        }

        return preg_match(self::SEASON_TOKEN_REGEX, $name) === 1
            || preg_match(self::SEASON_WORD_REGEX, $name) === 1
            || preg_match(self::COMPLETE_REGEX, $name) === 1;
    }

    /**
     * Whether a name carries an episode marker (S01E01, 1x05, Episode.5, E05...).
     */
    public static function isEpisodeName(string $name): bool
    {
        $flags = RustPipelineLibrary::seasonPackFlags($name);
        if ($flags !== null) {
            return ($flags & 1) !== 0;
        }

        return self::isEpisodeNamePhp($name);
    }

    public static function isEpisodeNamePhp(string $name): bool
    {
        return preg_match(self::EPISODE_REGEX, $name) === 1;
    }

    /** Recognized episode markers without enough information to infer a season safely. */
    public static function isUnclassifiedEpisodeName(string $name): bool
    {
        return self::isUnclassifiedEpisodeNameNative($name) ?? self::isUnclassifiedEpisodeNamePhp($name);
    }

    public static function isUnclassifiedEpisodeNamePhp(string $name): bool
    {
        $base = self::baseName($name);

        return preg_match(self::SAMPLE_REGEX, $base) !== 1
            && self::isEpisodeName($base) && self::parseEpisode($name) === null;
    }

    /**
     * Whether the file names are at least two distinct episodes of one show.
     *
     * @param  list<string>  $names  File names, optionally with paths and extensions
     */
    public static function isMultiEpisodeSet(array $names): bool
    {
        $supported = false;
        $isMultiEpisode = false;
        RustPipelineLibrary::seasonNameFromEpisodes($names, null, $supported, $isMultiEpisode);
        if ($supported) {
            return $isMultiEpisode;
        }

        return self::parseEpisodeSet($names) !== null;
    }

    /**
     * Naming admission needs one episode identity, even when different shows
     * cannot supply the common title required by seasonNameFromEpisodes().
     * Different encodes or ancillary files for the same episode remain one.
     *
     * @param  list<string>  $names
     */
    public static function hasDistinctEpisodeIdentities(array $names): bool
    {
        // Counting every ancillary entry in Rust loses PHP's two-identity early
        // exit on large batches; keep this caller native (benchmark in parity test).
        return self::hasDistinctEpisodeIdentitiesPhp($names);
    }

    /** @param list<string> $names */
    public static function hasDistinctEpisodeIdentitiesPhp(array $names): bool
    {
        $first = null;
        foreach ($names as $name) {
            $episode = self::parseEpisode($name);
            if ($episode === null) {
                continue;
            }
            $identity = $episode['key'].':'.$episode['season'].':'.$episode['episode'];
            if ($first !== null && $first !== $identity) {
                return true;
            }
            $first = $identity;
        }

        return false;
    }

    public static function episodeIdentity(string $name): ?string
    {
        $supported = false;
        $ffiRes = RustPipelineLibrary::seasonPackEpisodeIdentity($name, null, $supported);
        if ($supported) {
            return $ffiRes;
        }

        return self::episodeIdentityPhp($name);
    }

    public static function episodeIdentityPhp(string $name): ?string
    {
        $base = self::baseName($name);
        if ($base === '' || preg_match(self::SAMPLE_REGEX, $base) === 1 || self::hasExplicitPackMarker($base)
            || preg_match(self::SINGLE_EPISODE_SPLIT_REGEX, $base, $match) !== 1) {
            return null;
        }
        $rest = $match['rest'];
        if (self::isEpisodeName($match['prefix']) || self::isEpisodeName($rest)
            || preg_match('/^[a-z0-9]/i', $rest) === 1
            || preg_match('/(?:^|[^a-z0-9])(?:S\d+[._ ]?E\d+|E(?:pisode|P)?[._ ]?\d+)/i', $rest) === 1
            || self::hasXEpisodeContinuation($rest)
            || preg_match('/^[._ ]*(?:[-+&,]|–|—|(?:to|through)[._ ]+)[._ ]*\d+(?=$|[^a-z0-9])/i', $rest) === 1) {
            return null;
        }
        $prefix = strtolower(trim((string) preg_replace('/[._ \-]+/', '.', $match['prefix']), '.'));
        if ($prefix === '') {
            return null;
        }
        $season = $match['season'] !== '' ? $match['season'] : $match['xseason'];
        $episode = $match['episode'] !== '' ? $match['episode'] : $match['xepisode'];

        return $prefix.':'.(int) $season.':'.(int) $episode;
    }

    private static function hasXEpisodeContinuation(string $rest): bool
    {
        // Keep the whole token: x264/x265 are codecs, but x02 and x264E02
        // cannot become codecs by accepting only an initial numeric prefix.
        preg_match_all('/(?:^|[^a-z0-9])(x\d+[^._ \-\[\](){}]*)/i', $rest, $matches);
        foreach ($matches[1] as $token) {
            if (preg_match(self::RELEASE_INFO_TOKEN_REGEX, $token) !== 1) {
                return true;
            }
        }

        return false;
    }

    /** Explicit pack/compound evidence cannot become an opaque canonical singleton.
     *
     * @param  list<string>  $names
     */
    public static function hasNonSingleEpisodeEvidence(array $names): bool
    {
        $ffiRes = RustPipelineLibrary::hasNonSingleEpisodeEvidence($names);
        if ($ffiRes !== null) {
            return $ffiRes;
        }

        return self::hasNonSingleEpisodeEvidencePhp($names);
    }

    /**
     * @param  list<string>  $names
     */
    public static function hasNonSingleEpisodeEvidencePhp(array $names): bool
    {
        foreach ($names as $name) {
            $base = self::baseName($name);
            if (preg_match(self::SAMPLE_REGEX, $base) === 1) {
                continue;
            }
            if (self::hasExplicitPackMarker($base)
                || (self::isEpisodeName($base) && self::episodeIdentity($name) === null)) {
                return true;
            }
        }

        return self::hasDistinctEpisodeIdentities($names);
    }

    private static function hasExplicitPackMarker(string $base): bool
    {
        return preg_match(self::ADMISSION_SEASON_TOKEN_REGEX, $base) === 1
            || preg_match(self::SEASON_WORD_REGEX, $base) === 1
            || preg_match(self::COMPLETE_REGEX, $base) === 1;
    }

    /** Only explicit metadata/subtitle/artwork and sample conventions are ancillary. */
    public static function isAncillaryName(string $name): bool
    {
        return self::isAncillaryNameNative($name) ?? self::isAncillaryNamePhp($name);
    }

    public static function isAncillaryNamePhp(string $name): bool
    {
        if (preg_match(self::SAMPLE_REGEX, self::baseName($name)) === 1) {
            return true;
        }
        $base = basename(str_replace('\\', '/', trim($name)));

        // A narrow subset of FileNameCleaner's metadata/subtitle suffixes and
        // ProcessingConfiguration's support files. Unknown suffixes, archives,
        // documents and extensionless names remain possible episode payloads.
        return preg_match('/\.(?:nfo|sfv|nzb|srr|srs|par2|jpe?g|png|gif|bmp|tiff?|webp|txt|diz|md5|sha1|cue|log|srt|sub|idx|ass|ssa|vtt|sup)$/i', $base) === 1;
    }

    /**
     * Collapse the episode file names of one show into a season-level release name.
     *
     * The common release information (resolution, source, codec, group) is kept and
     * per-episode titles are dropped, e.g. Show.S01E01.Pilot.1080p.WEB-DL-GRP.mkv and
     * Show.S01E02.The.Title.1080p.WEB-DL-GRP.mkv become Show.S01.1080p.WEB-DL-GRP.
     *
     * @param  list<string>  $names  File names, optionally with paths and extensions
     */
    public static function seasonNameFromEpisodes(array $names): ?string
    {
        $supported = false;
        $ffiRes = RustPipelineLibrary::seasonNameFromEpisodes($names, null, $supported);
        if ($supported) {
            return $ffiRes;
        }

        return self::seasonNameFromEpisodesPhp($names);
    }

    /**
     * @param  list<string>  $names
     */
    public static function seasonNameFromEpisodesPhp(array $names): ?string
    {
        $episodes = self::parseEpisodeSet($names);
        if ($episodes === null) {
            return null;
        }

        $first = $episodes[0];
        $seasons = array_values(array_unique(array_map(static fn (array $episode): int => $episode['season'], $episodes)));
        sort($seasons);
        $width = max(2, \strlen($first['seasonDigits']));
        $seasonToken = 'S'.str_pad((string) $seasons[0], $width, '0', STR_PAD_LEFT);
        if (\count($seasons) > 1) {
            $seasonToken .= '-S'.str_pad((string) end($seasons), $width, '0', STR_PAD_LEFT);
        }

        $common = self::trimToReleaseInfo(self::commonTokenSuffix(array_map(
            static fn (array $episode): array => self::tokenize($episode['rest']),
            $episodes,
        )));

        if ($common === null) {
            if (\count($seasons) > 1) {
                return null;
            }

            return self::seasonNameFromEpisode($first['name']);
        }

        return $first['prefix'].$first['sep'].$seasonToken.self::joinTokens($common);
    }

    /**
     * Turn one episode file name into the season-level name of its pack, dropping the
     * episode number and title; null when no release information follows the episode.
     */
    public static function seasonNameFromEpisode(string $name): ?string
    {
        $supported = false;
        $ffiRes = RustPipelineLibrary::seasonNameFromEpisode($name, null, $supported);
        if ($supported) {
            return $ffiRes;
        }

        return self::seasonNameFromEpisodePhp($name);
    }

    public static function seasonNameFromEpisodePhp(string $name): ?string
    {
        $episode = self::parseEpisode($name);
        if ($episode === null) {
            return null;
        }

        $releaseInfo = self::trimToReleaseInfo(self::tokenize($episode['rest']));
        if ($releaseInfo === null) {
            return null;
        }

        $width = max(2, \strlen($episode['seasonDigits']));

        return $episode['prefix'].$episode['sep'].'S'.str_pad((string) $episode['season'], $width, '0', STR_PAD_LEFT)
            .self::joinTokens($releaseInfo);
    }

    /**
     * The outermost folder in the paths that is named as a season pack.
     *
     * @param  list<string>  $paths
     */
    public static function seasonFolder(array $paths): ?string
    {
        $supported = false;
        $folder = self::seasonFolderNative($paths, $supported);

        return $supported ? $folder : self::seasonFolderPhp($paths);
    }

    /** @param list<string> $paths */
    public static function seasonFolderPhp(array $paths): ?string
    {
        foreach ($paths as $path) {
            $segments = explode('/', str_replace('\\', '/', $path));
            array_pop($segments);
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment !== '' && self::isSeasonPack($segment)) {
                    return $segment;
                }
            }
        }

        return null;
    }

    /**
     * Parsed episodes of one show with at least two distinct episodes, in input order.
     *
     * @param  list<string>  $names
     * @return non-empty-list<array{name: string, prefix: string, sep: string, key: string, season: int, seasonDigits: string, episode: int, rest: string}>|null
     */
    private static function parseEpisodeSet(array $names): ?array
    {
        $episodes = [];
        $seen = [];
        foreach ($names as $name) {
            $episode = self::parseEpisode($name);
            if ($episode === null) {
                continue;
            }
            $dedupe = strtolower($episode['name']);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $episodes[] = $episode;
        }

        if ($episodes === []) {
            return null;
        }

        $keys = array_unique(array_map(static fn (array $episode): string => $episode['key'], $episodes));
        $numbers = array_unique(array_map(static fn (array $episode): string => $episode['season'].'x'.$episode['episode'], $episodes));

        return \count($keys) === 1 && \count($numbers) >= 2 ? $episodes : null;
    }

    /**
     * @return array{name: string, prefix: string, sep: string, key: string, season: int, seasonDigits: string, episode: int, rest: string}|null
     */
    private static function parseEpisode(string $name): ?array
    {
        $base = self::baseName($name);
        if ($base === '' || preg_match(self::SAMPLE_REGEX, $base) === 1) {
            return null;
        }
        if (preg_match(self::EPISODE_SPLIT_REGEX, $base, $match) !== 1
            && preg_match(self::X_EPISODE_SPLIT_REGEX, $base, $match) !== 1
        ) {
            return null;
        }

        $prefix = trim($match['prefix']);
        if ($prefix === '') {
            return null;
        }

        return [
            'name' => $base,
            'prefix' => $prefix,
            'sep' => $match['sep'],
            'key' => strtolower(trim((string) preg_replace('/[._ \-]+/', '.', $prefix), '.')),
            'season' => (int) $match['season'],
            'seasonDigits' => $match['season'],
            'episode' => (int) $match['episode'],
            'rest' => $match['rest'],
        ];
    }

    private static function baseName(string $name): string
    {
        $base = basename(str_replace('\\', '/', trim($name)));
        for ($i = 0; $i < 3; $i++) {
            $stripped = (string) preg_replace(self::EXTENSION_REGEX, '', $base);
            if ($stripped === $base) {
                break;
            }
            $base = $stripped;
        }

        return trim($base, " \t\n\r\0\x0B.");
    }

    /** null requests exact PHP fallback, including PCRE newline edge cases. */
    public static function isUnclassifiedEpisodeNameNative(string $name): ?bool
    {
        $ffi = self::cleaningLibrary();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound (domain C ABI)
            $result = $ffi->nntmux_season_pack_is_unclassified_episode($name, \strlen($name));

            return $result < 0 ? null : $result === 1;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One FFI call parses identities and counts ancillary names. Bit zero carries
     * distinctness; the higher bits carry the ancillary count for batch consumers.
     * Embedded NULs cannot be represented by this wire format and fall back.
     *
     * @param  list<string>  $names
     */
    public static function hasDistinctEpisodeIdentitiesNative(array $names): ?bool
    {
        $ffi = self::cleaningLibrary();
        if ($ffi === null) {
            return null;
        }
        foreach ($names as $name) {
            if (str_contains($name, "\0")) {
                return null;
            }
        }
        $joined = implode("\0", $names);
        try {
            // @phpstan-ignore method.notFound (domain C ABI)
            $result = $ffi->nntmux_season_pack_has_distinct_identities_nul($joined, \strlen($joined));

            return $result < 0 ? null : ($result & 1) !== 0;
        } catch (Throwable) {
            return null;
        }
    }

    public static function isAncillaryNameNative(string $name): ?bool
    {
        $ffi = self::cleaningLibrary();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound (domain C ABI)
            $result = $ffi->nntmux_season_pack_is_ancillary_name($name, \strlen($name));

            return $result < 0 ? null : $result === 1;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The ABI returns a byte offset into each path; PHP preserves the spelling of
     * the first outermost matching folder and input path order.
     *
     * @param  list<string>  $paths
     */
    public static function seasonFolderNative(array $paths, bool &$supported = false): ?string
    {
        $supported = false;
        $ffi = self::cleaningLibrary();
        if ($ffi === null) {
            return null;
        }
        try {
            foreach ($paths as $path) {
                // @phpstan-ignore method.notFound (domain C ABI)
                $offset = $ffi->nntmux_season_pack_season_folder($path, \strlen($path));
                if ($offset === -1) {
                    return null;
                }
                if ($offset >= 0) {
                    $supported = true;

                    return trim(substr($path, $offset, strcspn($path, '/\\', $offset)));
                }
            }
            $supported = true;

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{0: string, 1: string}> [separator before the token, token]
     */
    private static function tokenize(string $rest): array
    {
        $parts = preg_split('/([._ \-]+)/', $rest, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $tokens = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1 || $part === '') {
                continue;
            }
            $tokens[] = [$index > 0 ? $parts[$index - 1] : '.', $part];
        }

        return $tokens;
    }

    /**
     * Tokens every list ends with, compared case-insensitively, in the first list's spelling.
     *
     * @param  list<list<array{0: string, 1: string}>>  $lists
     * @return list<array{0: string, 1: string}>
     */
    private static function commonTokenSuffix(array $lists): array
    {
        $first = $lists[0] ?? [];
        $length = \count($first);
        foreach ($lists as $tokens) {
            $length = min($length, \count($tokens));
        }

        $common = 0;
        while ($common < $length) {
            $token = strtolower($first[\count($first) - 1 - $common][1]);
            foreach ($lists as $tokens) {
                if (strtolower($tokens[\count($tokens) - 1 - $common][1]) !== $token) {
                    break 2;
                }
            }
            $common++;
        }

        return $common === 0 ? [] : \array_slice($first, -$common);
    }

    /**
     * Drop leading tokens (an episode title) up to the first release-information token.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return list<array{0: string, 1: string}>|null
     */
    private static function trimToReleaseInfo(array $tokens): ?array
    {
        foreach ($tokens as $index => $token) {
            if (preg_match(self::RELEASE_INFO_TOKEN_REGEX, $token[1]) === 1) {
                return \array_slice($tokens, $index);
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function joinTokens(array $tokens): string
    {
        $joined = '';
        foreach ($tokens as [$separator, $token]) {
            $joined .= $separator.$token;
        }

        return $joined;
    }
}
