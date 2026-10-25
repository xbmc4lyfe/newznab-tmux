<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Facades\Search;
use App\Models\MovieInfo;
use App\Models\Release;
use App\Models\Video;
use App\Services\Search\DTO\SearchCursor;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

    /**
     * Rust stays opt-in for the Wave 41.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchDtoQueryCursorPageMediaCodecW41ParityTest.php.
     */
    public const bool W415_RUST_FFI_DEFAULT = false;

    private static ?bool $useW415RustFfi = null;

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

    public static function useW415RustFfi(bool $enable = true): void
    {
        self::$useW415RustFfi = $enable;
    }

    public static function usesW415RustFfi(): bool
    {
        return self::$useW415RustFfi ?? self::W415_RUST_FFI_DEFAULT;
    }

    public static function resetW415RustFfi(): void
    {
        self::$useW415RustFfi = null;
    }

    /**
     * Summarizes external ID coverage of index rows with the `collectExternalIds()` rule (a field is
     * present when it is not `empty()`), using the movie fields for `$type === 'movie'` and the TV
     * fields otherwise. Non-array rows are invalid. `field_counts` is keyed by the release field in
     * lookup priority order.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid_rows: int, with_any: int, max_per_row: int, field_counts: array<string, int>}
     */
    public static function summarizeExternalIdCoverageBatch(array $rows, string $type = 'movie'): array
    {
        return self::usesW415RustFfi()
            ? self::summarizeExternalIdCoverageBatchNative($rows, $type)
            : self::summarizeExternalIdCoverageBatchPhp($rows, $type);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid_rows: int, with_any: int, max_per_row: int, field_counts: array<string, int>}
     */
    public static function summarizeExternalIdCoverageBatchPhp(array $rows, string $type = 'movie'): array
    {
        $fields = $type === 'movie' ? self::MOVIE_EXTERNAL_ID_FIELDS : self::TV_EXTERNAL_ID_FIELDS;
        $counts = array_fill_keys(array_values($fields), 0);
        $invalid = 0;
        $withAny = 0;
        $maxPerRow = 0;
        foreach ($rows as $row) {
            if (! \is_array($row)) {
                $invalid++;

                continue;
            }
            $found = 0;
            foreach ($fields as $source => $target) {
                if (! empty($row[$source])) {
                    $counts[$target]++;
                    $found++;
                }
            }
            $withAny += (int) ($found > 0);
            $maxPerRow = max($maxPerRow, $found);
        }

        return [
            'rows' => \count($rows),
            'invalid_rows' => $invalid,
            'with_any' => $withAny,
            'max_per_row' => $maxPerRow,
            'field_counts' => $counts,
        ];
    }

    /**
     * Rust receives integers and strings as tagged bytes; PHP decides `empty()` for other types.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid_rows: int, with_any: int, max_per_row: int, field_counts: array<string, int>}
     */
    public static function summarizeExternalIdCoverageBatchNative(array $rows, string $type = 'movie'): array
    {
        $fields = $type === 'movie' ? self::MOVIE_EXTERNAL_ID_FIELDS : self::TV_EXTERNAL_ID_FIELDS;
        $sources = array_keys($fields);
        $width = \count($sources);
        $parts = [(string) $width];
        foreach ($rows as $row) {
            if (! \is_array($row)) {
                $parts[] = '0';
                array_push($parts, ...array_fill(0, $width, 'z'));

                continue;
            }
            $parts[] = '1';
            foreach ($sources as $source) {
                $value = $row[$source] ?? null;
                $parts[] = match (true) {
                    \is_string($value) => 's'.$value,
                    \is_int($value) => 'i'.$value,
                    default => $value ? 't' : 'z',
                };
            }
        }
        $result = SearchCursor::evaluateW415Native(4, $parts);
        if ($result === null || \count($result) !== 4 + $width) {
            return self::summarizeExternalIdCoverageBatchPhp($rows, $type);
        }
        $counts = [];
        foreach (array_values($fields) as $i => $target) {
            $counts[$target] = (int) $result[4 + $i];
        }

        return [
            'rows' => (int) $result[0],
            'invalid_rows' => (int) $result[1],
            'with_any' => (int) $result[2],
            'max_per_row' => (int) $result[3],
            'field_counts' => $counts,
        ];
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

    public const string W51_FFI_CDEF = 'int64_t nntmux_w515_media_external_id_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W51.5 parity test's QUALIFICATION). */
    private const bool W51_RUST_FFI_DEFAULT = true;

    private static ?bool $w51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$w51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with externalIdPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w51RustFfi;
        self::$w51RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w51RustFfi = $previous;
        }
    }

    /**
     * Exactly $words output words from the Wave 51.5 Rust helper, or null to fall back to PHP. The helper
     * leads with the number of rows PHP must compute, returned in $patches.
     *
     * @return list<int>|null
     */
    private static function w51Words(int $count, string $data, string $ints, int $words, ?int &$patches = null): ?array
    {
        if (self::$w51Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w51Ffi = RustPipelineLibrary::resolveDomain('search_w51', self::W51_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w51Out = null;
            self::$w51OutCap = 0;
        }
        $ffi = self::$w51Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w51Out === null || $words >= self::$w51OutCap) {
                $cap = max(1024, $words + 1);
                self::$w51Out = $ffi->new('int64_t['.$cap.']');
                self::$w51OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w515_media_external_id_plan($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w51Out, self::$w51OutCap);
            if ($written !== $words + 1) {
                return null;
            }
            $patches = self::$w51Out[0];
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w51Out, $written * 8), 8);

            return \is_array($values) ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** PHP `hash('xxh64')` of $value read as a signed big-endian word, as the Rust helper reports it. */
    private static function w51Digest(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $value, true));

        return $unpacked[1];
    }

    /**
     * Batch lookup plan for index rows: the IDs collectExternalIdsPhp() keeps for `$type` (movie fields for
     * 'movie', TV fields otherwise) and the searchMediaRoutePhp() route. Four words per row: `[presence mask
     * (bit = field priority) | ID count << 8 | route (0 external, 1 movie, 2 tv) << 12 | key length << 20,
     * (int) first ID, xxh64(key), xxh64(filter terms)]`. The key is `http_build_query()` of the collected
     * IDs; the filter terms are the release-index `field:value` pairs the search driver queries for them
     * (imdbid as a string, other IDs cast to int), joined by newlines. A non-array row is
     * `[1 << 16, 0, 0, 0]`.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<int>
     */
    public static function externalIdPlanBatch(array $rows, string $type = 'movie'): array
    {
        return self::usesW51RustFfi() ? self::externalIdPlanBatchNative($rows, $type) : self::externalIdPlanBatchPhp($rows, $type);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<int>
     */
    public static function externalIdPlanBatchPhp(array $rows, string $type = 'movie'): array
    {
        $fields = $type === 'movie' ? self::MOVIE_EXTERNAL_ID_FIELDS : self::TV_EXTERNAL_ID_FIELDS;
        $titleRoute = self::searchMediaRoutePhp([], $type) === 'movie' ? 1 : 2;
        $out = [];
        foreach ($rows as $row) {
            if (! \is_array($row)) {
                array_push($out, 1 << 16, 0, 0, 0);

                continue;
            }
            $ids = self::collectExternalIdsPhp($row, $fields);
            $mask = 0;
            $bit = 1;
            foreach ($fields as $target) {
                if (\array_key_exists($target, $ids)) {
                    $mask |= $bit;
                }
                $bit <<= 1;
            }
            $terms = [];
            foreach ($ids as $field => $value) {
                $terms[] = $field.':'.($field === 'imdbid' ? (string) $value : (int) $value);
            }
            $count = \count($ids);
            $key = http_build_query($ids);
            $route = self::searchMediaRoutePhp($ids, $type) === 'external' ? 0 : $titleRoute;
            array_push($out, $mask | $count << 8 | $route << 12 | \strlen($key) << 20, $count > 0 ? (int) reset($ids) : 0, self::w51Digest($key), self::w51Digest(implode("\n", $terms)));
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing. Rows that all hold every field travel as one
     * `json_encode()` of the field columns, others field by field. Rows holding values other than integers,
     * strings, booleans and null (or a first ID string PHP must cast) are computed with PHP.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<int>
     */
    public static function externalIdPlanBatchNative(array $rows, string $type = 'movie'): array
    {
        $count = \count($rows);
        if ($count === 0) {
            return self::externalIdPlanBatchPhp($rows, $type);
        }
        $fields = $type === 'movie' ? self::MOVIE_EXTERNAL_ID_FIELDS : self::TV_EXTERNAL_ID_FIELDS;
        $list = array_values($rows);
        $arrays = array_filter($list, '\is_array');
        $columns = [];
        foreach ($fields as $source => $target) {
            $column = array_column($arrays, $source);
            if (\count($column) !== \count($arrays)) {
                $columns = null;

                break;
            }
            $columns[] = $column;
        }
        $json = $columns === null ? false : json_encode($columns);
        if ($json !== false) {
            $invalid = \count($arrays) === $count ? [] : array_keys(array_diff_key($list, $arrays));
            $words = self::w51Words($count, $json, pack('P*', $type === 'movie' ? 1 : 0, 1, \count($invalid), ...$invalid), 4 * $count, $patches);
        } else {
            $ints = [$type === 'movie' ? 1 : 0, 0];
            $data = '';
            foreach ($list as $row) {
                if (! \is_array($row)) {
                    $ints[] = 0;

                    continue;
                }
                $at = \count($ints);
                $ints[] = 1;
                foreach ($fields as $source => $target) {
                    $value = $row[$source] ?? null;
                    if (\is_int($value)) {
                        $ints[] = 0;
                        $ints[] = $value;
                    } elseif (\is_string($value)) {
                        $ints[] = 1;
                        $ints[] = \strlen($value);
                        $data .= $value;
                    } else {
                        if ($value !== null && ! \is_bool($value)) {
                            $ints[$at] = 2;
                        }
                        $ints[] = $value === true ? 3 : 2;
                        $ints[] = 0;
                    }
                }
            }
            $words = self::w51Words($count, $data, pack('P*', ...$ints), 4 * $count, $patches);
        }
        if ($words === null) {
            return self::externalIdPlanBatchPhp($rows, $type);
        }
        if ($patches > 0) {
            $keys = $patchRows = [];
            foreach (array_keys($words, -1, true) as $key) {
                if ($key % 4 === 0) {
                    $keys[] = $key;
                    $patchRows[] = $list[intdiv($key, 4)];
                }
            }
            $fixed = self::externalIdPlanBatchPhp($patchRows, $type);
            foreach ($keys as $n => $key) {
                for ($word = 0; $word < 4; $word++) {
                    $words[$key + $word] = $fixed[4 * $n + $word];
                }
            }
        }

        return $words;
    }
}
