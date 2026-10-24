<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Facades\Search;
use App\Models\MovieInfo;
use App\Models\Release;
use App\Models\Video;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;

/**
 * Service for optimized media searches using search indexes.
 *
 * This service provides methods to search for movies and TV shows
 * using the dedicated search indexes, reducing database queries
 * by leveraging cached external IDs (IMDB, TMDB, TVDB, etc.).
 */
class MediaSearchService
{
    /**
     * Cache TTL for media search results (minutes).
     */
    private const CACHE_TTL = 5;

    /**
     * Rust candidates for the pure helpers below. They stay opt-in: none beat PHP by
     * 1.5x in the paired benchmark (tests/Unit/RustSearchMediaApiCapabilitiesParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_media_external_id_presence(const int32_t *tags, const int64_t *ints, const char *input, size_t len, const int64_t *lens, size_t count, uint64_t *out_mask);
        ssize_t nntmux_search_release_ids_unique_limit(const int64_t *ids, size_t count, int64_t limit, int64_t *out, size_t cap);
        int32_t nntmux_media_search_route(int32_t has_external_ids, const char *kind, size_t len);
        C;

    /**
     * Movie index fields, in lookup priority order, mapped to release external-ID fields.
     */
    public const array MOVIE_EXTERNAL_ID_FIELDS = ['imdbid' => 'imdbid', 'tmdbid' => 'tmdbid', 'traktid' => 'traktid'];

    /**
     * TV show index fields, in lookup priority order, mapped to release external-ID fields.
     */
    public const array TV_EXTERNAL_ID_FIELDS = ['tvdb' => 'tvdb', 'trakt' => 'traktid', 'tvmaze' => 'tvmaze', 'tvrage' => 'tvrage'];

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Find movie by any external ID and return cached movie data from index.
     *
     * @param  array<string, mixed>  $externalIds  Array with keys like 'imdbid', 'tmdbid', 'traktid'
     * @return array<string, mixed>|null Movie data from search index
     */
    public function findMovie(array $externalIds): ?array
    {
        return Search::searchMovieByExternalIds($externalIds);
    }

    /**
     * Find TV show by any external ID and return cached show data from index.
     *
     * @param  array<string, mixed>  $externalIds  Array with keys like 'tvdb', 'trakt', 'tvmaze', 'tvrage', 'imdb', 'tmdb'
     * @return array<string, mixed>|null TV show data from search index
     */
    public function findTvShow(array $externalIds): ?array
    {
        return Search::searchTvShowByExternalIds($externalIds);
    }

    /**
     * Search for movie releases by movie title.
     *
     * First searches the movies index for matching titles, then finds
     * releases with matching external IDs - reducing database JOINs.
     *
     * @param  string  $title  Movie title to search
     * @param  int  $limit  Maximum results
     * @return array<string, mixed> Array of release IDs
     */
    public function searchMovieReleases(string $title, int $limit = 1000): array
    {
        $cacheKey = 'media:movie_releases:'.md5($title.$limit);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // First, search movies index for matching titles
        $movieResults = Search::searchMovies($title, 50);

        if (empty($movieResults['id'])) {
            return [];
        }

        // Collect all external IDs from matched movies
        $externalIdSets = [];

        foreach ($movieResults['data'] ?? [] as $movie) {
            $externalIds = \is_array($movie) ? self::collectExternalIds($movie, self::MOVIE_EXTERNAL_ID_FIELDS) : [];

            if (! empty($externalIds)) {
                $externalIdSets[] = $externalIds;
            }
        }

        $allReleaseIds = Search::searchReleasesByMultipleExternalIds($externalIdSets, $limit);

        // Remove duplicates and limit results
        $allReleaseIds = self::uniqueLimitedReleaseIds($allReleaseIds, $limit);

        if (! empty($allReleaseIds)) {
            Cache::put($cacheKey, $allReleaseIds, now()->addMinutes(self::CACHE_TTL));
        }

        return $allReleaseIds;
    }

    /**
     * Search for TV show releases by show title.
     *
     * First searches the tvshows index for matching titles, then finds
     * releases with matching external IDs - reducing database JOINs.
     *
     * @param  string  $title  TV show title to search
     * @param  int  $limit  Maximum results
     * @return array<string, mixed> Array of release IDs
     */
    public function searchTvShowReleases(string $title, int $limit = 1000): array
    {
        $cacheKey = 'media:tvshow_releases:'.md5($title.$limit);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // First, search tvshows index for matching titles
        $tvResults = Search::searchTvShows($title, 50);

        if (empty($tvResults['id'])) {
            return [];
        }

        // Collect all external IDs from matched TV shows
        $externalIdSets = [];

        foreach ($tvResults['data'] ?? [] as $tvShow) {
            $externalIds = \is_array($tvShow) ? self::collectExternalIds($tvShow, self::TV_EXTERNAL_ID_FIELDS) : [];

            if (! empty($externalIds)) {
                $externalIdSets[] = $externalIds;
            }
        }

        $allReleaseIds = Search::searchReleasesByMultipleExternalIds($externalIdSets, $limit);

        // Remove duplicates and limit results
        $allReleaseIds = self::uniqueLimitedReleaseIds($allReleaseIds, $limit);

        if (! empty($allReleaseIds)) {
            Cache::put($cacheKey, $allReleaseIds, now()->addMinutes(self::CACHE_TTL));
        }

        return $allReleaseIds;
    }

    /**
     * Get releases for a specific IMDB ID directly from the index.
     *
     * @param  int|string  $imdbId  IMDB ID (without 'tt' prefix)
     * @param  int  $limit  Maximum results
     * @return array<string, mixed> Array of release IDs
     */
    public function getReleasesByImdbId(int|string $imdbId, int $limit = 1000): array
    {
        return Search::searchReleasesByExternalId(['imdbid' => (int) $imdbId], $limit);
    }

    /**
     * Get releases for a specific TMDB ID directly from the index.
     *
     * @param  int|string  $tmdbId  TMDB ID
     * @param  int  $limit  Maximum results
     * @return array<string, mixed> Array of release IDs
     */
    public function getReleasesByTmdbId(int|string $tmdbId, int $limit = 1000): array
    {
        return Search::searchReleasesByExternalId(['tmdbid' => (int) $tmdbId], $limit);
    }

    /**
     * Get releases for a specific TVDB ID directly from the index.
     *
     * @param  int|string  $tvdbId  TVDB ID
     * @param  int  $limit  Maximum results
     * @return array<string, mixed> Array of release IDs
     */
    public function getReleasesByTvdbId(int|string $tvdbId, int $limit = 1000): array
    {
        return Search::searchReleasesByExternalId(['tvdb' => (int) $tvdbId], $limit);
    }

    /**
     * Get releases for a specific Trakt ID directly from the index.
     *
     * @param  int|string  $traktId  Trakt ID
     * @param  int  $limit  Maximum results
     * @return array<string, mixed> Array of release IDs
     */
    public function getReleasesByTraktId(int|string $traktId, int $limit = 1000): array
    {
        return Search::searchReleasesByExternalId(['traktid' => (int) $traktId], $limit);
    }

    /**
     * Combined search: search by title and optionally by external IDs.
     *
     * @param  string  $title  Media title
     * @param  array<string, mixed>  $externalIds  Optional external IDs to narrow search
     * @param  string  $type  'movie' or 'tv'
     * @param  int  $limit  Maximum results
     * @return array<string, mixed> Array of release IDs
     */
    public function searchMedia(string $title, array $externalIds = [], string $type = 'movie', int $limit = 1000): array
    {
        // External IDs are used directly; otherwise fall back to a title search.
        return match (self::searchMediaRoute($externalIds, $type)) {
            'external' => Search::searchReleasesByExternalId($externalIds, $limit),
            'movie' => $this->searchMovieReleases($title, $limit),
            default => $this->searchTvShowReleases($title, $limit),
        };
    }

    /**
     * The non-empty external IDs of an index row, in `$fields` priority order and
     * renamed to the release fields.
     *
     * @param  array<array-key, mixed>  $row
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public static function collectExternalIds(array $row, array $fields): array
    {
        return self::$useRustFfi ? self::collectExternalIdsNative($row, $fields) : self::collectExternalIdsPhp($row, $fields);
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public static function collectExternalIdsPhp(array $row, array $fields): array
    {
        $externalIds = [];
        foreach ($fields as $source => $target) {
            if (! empty($row[$source])) {
                $externalIds[$target] = $row[$source];
            }
        }

        return $externalIds;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    public static function collectExternalIdsNative(array $row, array $fields): array
    {
        $ffi = self::searchFfi();
        $values = [];
        foreach ($fields as $source => $target) {
            $values[] = $row[$source] ?? null;
        }
        $count = \count($values);
        $encoded = $ffi === null || $count === 0 || $count > 63 ? null : SearchService::encodeFfiValues($values);
        if ($ffi === null || $encoded === null) {
            return self::collectExternalIdsPhp($row, $fields);
        }

        $mask = $ffi->new('uint64_t[1]');
        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_media_external_id_presence(
            SearchService::ffiInt32Array($ffi, $encoded['tags']),
            SearchService::ffiInt64Array($ffi, $encoded['ints']),
            $encoded['bytes'],
            \strlen($encoded['bytes']),
            SearchService::ffiInt64Array($ffi, $encoded['lens']),
            $count,
            $mask,
        );
        if ($rc !== 0) {
            return self::collectExternalIdsPhp($row, $fields);
        }

        $present = (int) $mask[0];
        $externalIds = [];
        $i = 0;
        foreach ($fields as $source => $target) {
            if (($present >> $i) & 1) {
                $externalIds[$target] = $row[$source];
            }
            $i++;
        }

        return $externalIds;
    }

    /**
     * Release IDs without duplicates (first occurrence wins), cut to `$limit` with
     * `array_slice()` length rules.
     *
     * @param  array<array-key, mixed>  $releaseIds
     * @return array<array-key, mixed>
     */
    public static function uniqueLimitedReleaseIds(array $releaseIds, int $limit): array
    {
        return self::$useRustFfi
            ? self::uniqueLimitedReleaseIdsNative($releaseIds, $limit)
            : self::uniqueLimitedReleaseIdsPhp($releaseIds, $limit);
    }

    /**
     * @param  array<array-key, mixed>  $releaseIds
     * @return array<array-key, mixed>
     */
    public static function uniqueLimitedReleaseIdsPhp(array $releaseIds, int $limit): array
    {
        return array_slice(array_unique($releaseIds), 0, $limit);
    }

    /**
     * @param  array<array-key, mixed>  $releaseIds
     * @return array<array-key, mixed>
     */
    public static function uniqueLimitedReleaseIdsNative(array $releaseIds, int $limit): array
    {
        $ffi = self::searchFfi();
        if ($ffi === null || $releaseIds === [] || ! array_is_list($releaseIds)) {
            return self::uniqueLimitedReleaseIdsPhp($releaseIds, $limit);
        }
        $ids = [];
        foreach ($releaseIds as $id) {
            if (! \is_int($id)) {
                return self::uniqueLimitedReleaseIdsPhp($releaseIds, $limit);
            }
            $ids[] = $id;
        }

        $count = \count($ids);
        $out = $ffi->new("int64_t[{$count}]");
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_search_release_ids_unique_limit(
            SearchService::ffiInt64Array($ffi, $ids),
            $count,
            $limit,
            $out,
            $count,
        );
        if (! \is_int($written) || $written < 0) {
            return self::uniqueLimitedReleaseIdsPhp($releaseIds, $limit);
        }

        $unique = [];
        for ($i = 0; $i < $written; $i++) {
            $unique[] = (int) $out[$i];
        }

        return $unique;
    }

    /**
     * How searchMedia() looks releases up: 'external' when external IDs are given,
     * otherwise a title search, 'movie' for `$type === 'movie'` and 'tv' for any other type.
     *
     * @param  array<string, mixed>  $externalIds
     * @return 'external'|'movie'|'tv'
     */
    public static function searchMediaRoute(array $externalIds, string $type): string
    {
        return self::$useRustFfi ? self::searchMediaRouteNative($externalIds, $type) : self::searchMediaRoutePhp($externalIds, $type);
    }

    /**
     * @param  array<string, mixed>  $externalIds
     * @return 'external'|'movie'|'tv'
     */
    public static function searchMediaRoutePhp(array $externalIds, string $type): string
    {
        if (! empty($externalIds)) {
            return 'external';
        }

        return $type === 'movie' ? 'movie' : 'tv';
    }

    /**
     * @param  array<string, mixed>  $externalIds
     * @return 'external'|'movie'|'tv'
     */
    public static function searchMediaRouteNative(array $externalIds, string $type): string
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::searchMediaRoutePhp($externalIds, $type);
        }

        // @phpstan-ignore method.notFound
        $route = $ffi->nntmux_media_search_route($externalIds === [] ? 0 : 1, $type, \strlen($type));

        return match ($route) {
            0 => 'external',
            1 => 'movie',
            2 => 'tv',
            default => self::searchMediaRoutePhp($externalIds, $type),
        };
    }

    /**
     * Get movie info from the search index (faster than DB query).
     *
     * @param  int  $movieInfoId  MovieInfo ID
     * @return array<string, mixed>|null Movie data or null
     */
    public function getMovieFromIndex(int $movieInfoId): ?array
    {
        $cacheKey = 'media:movie:'.$movieInfoId;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Search movies index by ID
        $result = Search::searchMovieByExternalId('id', $movieInfoId);

        if ($result) {
            Cache::put($cacheKey, $result, now()->addMinutes(self::CACHE_TTL));
        }

        return $result;
    }

    /**
     * Get TV show info from the search index (faster than DB query).
     *
     * @param  int  $videoId  Video/TV show ID
     * @return array<string, mixed>|null TV show data or null
     */
    public function getTvShowFromIndex(int $videoId): ?array
    {
        $cacheKey = 'media:tvshow:'.$videoId;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Search tvshows index by ID
        $result = Search::searchTvShowByExternalId('id', $videoId);

        if ($result) {
            Cache::put($cacheKey, $result, now()->addMinutes(self::CACHE_TTL));
        }

        return $result;
    }
}
