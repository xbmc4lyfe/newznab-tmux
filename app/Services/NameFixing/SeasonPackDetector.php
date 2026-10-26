<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // ---- Wave 59.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.91x/5.83x, uncommon 6.62x/5.89x); see RustEnumsTmuxPanePostProcessSequentialPredbKeySeasonPackW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_SEASON_PACK_FFI_CDEF = <<<'C'
        intptr_t nntmux_w592_season_pack_detection_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 59 routing, binding and output buffer, kept in function-local statics (no new static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w59', self::W59_SEASON_PACK_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w59RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
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

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planSeasonPackBatch() routed to Rust ($enabled) or PHP,
     * then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w59Digest(array $out): int
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
    private static function w59Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * One lowercased token: 1 season (2 its episode groups, 3 its disc), 4 a bare episode, 5/6 an
     * NxEE episode, 7 a disc, 8 `complete`, 9 a season word's number.
     */
    private const string W59_TOKEN = '/\A(?:s([0-9]{1,4})(?:((?:e[0-9]{1,4})+)|d([0-9]{1,2}))?|ep?([0-9]{1,4})|([0-9]{1,2})x([0-9]{2,3})|(?:disc|disk|d)([0-9]{1,2})|(complete)|seasons?([0-9]{1,2})?)\z/';

    /**
     * Batch season-pack detection plan over each lowercased name split on `[._ \-\[\]()]`. Tokens:
     * `s\d{1,4}` a season, `s\d{1,4}d\d{1,2}` a season on a disc, `s\d{1,4}(e\d{1,4})+` episodes,
     * `ep?\d{1,4}` a bare episode (season 0), `\d{1,2}x\d{2,3}` an episode, `(disc|disk|d)\d{1,2}`
     * a disc, `complete`, and `seasons?\d{1,2}` or `season`/`seasons` before a `\d{1,2}` token as a
     * season word. Flags: 1 season token, 2 episode, 4 several episodes (one token with two or
     * more, or two distinct season/episode pairs), 8 two or more distinct seasons from season
     * tokens and words, 16 disc, 32 complete, 64 season word. Class: 2 several episodes, 1 an
     * episode, 4 a season range, 3 a season token, word or `complete`, else 0. Score: 40 season
     * token + 30 word + 25 complete + 20 range + 10 disc, quartered with an episode. Per name
     * `flags | class << 7 | score << 10 | first_season << 18 | min(tokens, 0xFFFF) << 32 |
     * first_episode << 48`; then the per-class counts, the score sum, the union of flags and the
     * XXH64 digest of all earlier words.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planSeasonPackBatch(array $names): array
    {
        return self::usesW59RustFfi() ? self::planSeasonPackBatchNative($names) : self::planSeasonPackBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planSeasonPackBatchPhp(array $names): array
    {
        $out = [];
        $classes = [0, 0, 0, 0, 0];
        $scoreSum = $union = 0;
        foreach ($names as $name) {
            $tokens = preg_split('/[._ \-\[\]()]+/', strtolower((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $flags = 0;
            $multi = false;
            $firstSeason = $firstEpisode = null;
            $seasons = $pairs = [];
            foreach ($tokens as $i => $token) {
                if (preg_match(self::W59_TOKEN, $token, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
                    continue;
                }
                if ($m[1] !== null) {
                    $season = (int) $m[1];
                    $firstSeason ??= $season;
                    if ($m[2] !== null) {
                        $flags |= 2;
                        $episodes = explode('e', substr($m[2], 1));
                        $firstEpisode ??= (int) $episodes[0];
                        $multi = $multi || \count($episodes) >= 2;
                        foreach ($episodes as $episode) {
                            $pairs[$season.':'.(int) $episode] = true;
                        }
                    } else {
                        $flags |= $m[3] !== null ? 17 : 1;
                        $seasons[$season] = true;
                    }
                } elseif ($m[4] !== null) {
                    $flags |= 2;
                    $firstEpisode ??= (int) $m[4];
                    $pairs['0:'.(int) $m[4]] = true;
                } elseif ($m[5] !== null) {
                    $flags |= 2;
                    $firstSeason ??= (int) $m[5];
                    $firstEpisode ??= (int) $m[6];
                    $pairs[(int) $m[5].':'.(int) $m[6]] = true;
                } elseif ($m[7] !== null) {
                    $flags |= 16;
                } elseif ($m[8] !== null) {
                    $flags |= 32;
                } else {
                    $next = $tokens[$i + 1] ?? '';
                    $length = \strlen($next);
                    $season = $m[9] ?? ($length >= 1 && $length <= 2 && strspn($next, '0123456789') === $length ? $next : null);
                    if ($season !== null) {
                        $flags |= 64;
                        $firstSeason ??= (int) $season;
                        $seasons[(int) $season] = true;
                    }
                }
            }
            if ($multi || \count($pairs) >= 2) {
                $flags |= 4;
            }
            if (\count($seasons) >= 2) {
                $flags |= 8;
            }
            $class = match (true) {
                ($flags & 4) !== 0 => 2,
                ($flags & 2) !== 0 => 1,
                ($flags & 8) !== 0 => 4,
                ($flags & 97) !== 0 => 3,
                default => 0,
            };
            $score = 40 * ($flags & 1) + 30 * ($flags >> 6 & 1) + 25 * ($flags >> 5 & 1) + 20 * ($flags >> 3 & 1) + 10 * ($flags >> 4 & 1);
            if (($flags & 2) !== 0) {
                $score = intdiv($score, 4);
            }
            $classes[$class]++;
            $scoreSum += $score;
            $union |= $flags;
            $out[] = $flags | $class << 7 | $score << 10 | ($firstSeason ?? 0) << 18 | min(\count($tokens), 0xFFFF) << 32 | ($firstEpisode ?? 0) << 48;
        }
        array_push($out, ...$classes);
        array_push($out, $scoreSum, $union);
        $out[] = self::w59Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, an item holds a NUL byte or the
     * column is not a list.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planSeasonPackBatchNative(array $names): array
    {
        $count = \count($names);
        $words = $count + 8;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($state === null || ! array_is_list($names)) {
            return self::planSeasonPackBatchPhp($names);
        }
        $blob = implode("\0", $names);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W59_SEASON_PACK_FFI_CDEF.)
            $written = $ffi->nntmux_w592_season_pack_detection_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w59Words($written, $out, $words) ?? self::planSeasonPackBatchPhp($names);
    }
}
