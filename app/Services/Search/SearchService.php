<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SecondarySearchIndex;
use App\Services\Search\Contracts\BulkReleaseIndexUpdater;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Manager;

/**
 * Search Service Manager - handles driver resolution for search functionality.
 *
 * This service manager provides a unified interface for full-text search operations,
 * supporting multiple backends (ManticoreSearch, Elasticsearch) that can be configured
 * via the SEARCH_DRIVER environment variable.
 */
class SearchService extends Manager implements SearchServiceInterface
{
    /**
     * search_index_failures.operation for a deferred update that has not been flushed.
     */
    public const string DEFERRED_RELEASE_OPERATION = 'deferred';

    /**
     * When the scope ends, deferred release ids are refreshed in chunks of this size.
     */
    private const int DEFERRED_RELEASE_CHUNK = 200;

    /**
     * A deferral marker is a lease: if it is not renewed or cleared within this time
     * (the worker died), nntmux:search-repair refreshes the release.
     */
    private const int DEFERRED_RELEASE_LEASE_SECONDS = 600;

    /**
     * A deferred release updated again after this long renews its lease.
     */
    private const int DEFERRED_RELEASE_RENEW_AFTER_SECONDS = 300;

    private int $releaseUpdateDeferralDepth = 0;

    /**
     * Identifies the markers of the current scope (stored in last_error), so only
     * this scope or an expired-lease repair removes them.
     */
    private string $deferralToken = '';

    /**
     * Release ids whose index update waits for the end of the current scope,
     * mapped to when their marker was last written.
     *
     * @var array<int, int>
     */
    private array $deferredReleaseIds = [];

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('search.default', 'manticore');
    }

    /**
     * Create the ManticoreSearch driver instance.
     */
    protected function createManticoreDriver(): SearchDriverInterface
    {
        $config = $this->config->get('search.drivers.manticore', []);

        return new ManticoreSearchDriver($config);
    }

    /**
     * Create the Elasticsearch driver instance.
     */
    protected function createElasticsearchDriver(): SearchDriverInterface
    {
        $config = $this->config->get('search.drivers.elasticsearch', []);

        return new ElasticSearchDriver($config);
    }

    /**
     * Get a driver instance.
     *
     * @param  string|null  $driver
     *
     * @throws \InvalidArgumentException
     */
    public function driver($driver = null): SearchDriverInterface
    {
        return parent::driver($driver);
    }

    /**
     * Check if the current driver is available.
     */
    public function isAvailable(): bool
    {
        return $this->driver()->isAvailable();
    }

    /**
     * Get the current driver name.
     */
    public function getCurrentDriver(): string
    {
        return $this->driver()->getDriverName();
    }

    /**
     * Escape a search string using the current driver's escape method.
     */
    public function escapeString(string $string): string
    {
        $driverClass = get_class($this->driver());

        return $driverClass::escapeString($string);
    }

    // Implement SearchServiceInterface methods by delegating to the current driver

    /**
     * Check if autocomplete is enabled.
     */
    public function isAutocompleteEnabled(): bool
    {
        return $this->driver()->isAutocompleteEnabled();
    }

    /**
     * Check if suggest is enabled.
     */
    public function isSuggestEnabled(): bool
    {
        return $this->driver()->isSuggestEnabled();
    }

    /**
     * Check if fuzzy search is enabled.
     */
    public function isFuzzyEnabled(): bool
    {
        return $this->driver()->isFuzzyEnabled();
    }

    /**
     * Get fuzzy search configuration.
     *
     * @return array<string, mixed>
     */
    public function getFuzzyConfig(): array
    {
        return $this->driver()->getFuzzyConfig();
    }

    /**
     * Get the releases index name.
     */
    public function getReleasesIndex(): string
    {
        return $this->driver()->getReleasesIndex();
    }

    /**
     * Get the predb index name.
     */
    public function getPredbIndex(): string
    {
        return $this->driver()->getPredbIndex();
    }

    /**
     * Insert a release into the search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function insertRelease(array $parameters): void
    {
        $this->driver()->insertRelease($parameters);
    }

    /**
     * Update a release in the search index.
     */
    public function updateRelease(int|string $releaseID): void
    {
        $releaseId = (int) $releaseID;
        if ($this->releaseUpdateDeferralDepth > 0 && $releaseId > 0 && $this->driver() instanceof BulkReleaseIndexUpdater) {
            $this->deferReleaseUpdate($releaseId);

            return;
        }

        $this->driver()->updateRelease($releaseID);
    }

    /**
     * Run $work with release index updates deferred, then refresh each release once.
     *
     * Inside the scope, updateRelease() only records the id, so a release that is
     * created and then gets its NZB in the same pass is indexed once, from its final
     * row, in bulk. Each deferred id also gets a 'deferred' row in search_index_failures
     * holding this scope's token and a lease; the flush removes it, and if the worker
     * dies first, nntmux:search-repair refreshes the release once the lease expires.
     * Scopes nest; the outermost one flushes. Drivers without bulk support (whose
     * updateRelease() can't report failure) are updated immediately as before. Run it
     * outside database transactions, so the flush reads committed rows.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult
     */
    public function deferReleaseUpdates(callable $work): mixed
    {
        if ($this->releaseUpdateDeferralDepth === 0) {
            $this->deferralToken = self::DEFERRED_RELEASE_OPERATION.':'.bin2hex(random_bytes(8));
        }
        $this->releaseUpdateDeferralDepth++;
        try {
            return $work();
        } finally {
            $this->releaseUpdateDeferralDepth--;
            if ($this->releaseUpdateDeferralDepth === 0) {
                $this->flushDeferredReleaseUpdates();
            }
        }
    }

    private function deferReleaseUpdate(int $releaseId): void
    {
        $now = now()->getTimestamp();
        $markedAt = $this->deferredReleaseIds[$releaseId] ?? null;
        if ($markedAt !== null && $now - $markedAt < self::DEFERRED_RELEASE_RENEW_AFTER_SECONDS) {
            return;
        }

        if (! $this->markReleaseUpdateDeferred($releaseId)) {
            // Without a lease of our own, a crash before the flush would leave nothing
            // for repair, so index this release now.
            unset($this->deferredReleaseIds[$releaseId]);
            $this->driver()->updateRelease($releaseId);

            return;
        }

        $this->deferredReleaseIds[$releaseId] = $now;
    }

    private function flushDeferredReleaseUpdates(): void
    {
        $chunks = array_chunk(array_keys($this->deferredReleaseIds), self::DEFERRED_RELEASE_CHUNK);
        $this->deferredReleaseIds = [];

        foreach ($chunks as $releaseIds) {
            try {
                $driver = $this->driver();
                if (! $driver instanceof BulkReleaseIndexUpdater) {
                    throw new \LogicException('The search driver changed to one without bulk release updates.');
                }
                $failed = $driver->updateReleases($releaseIds);
            } catch (\Throwable $e) {
                Log::error('Search: deferred release index flush failed: '.$e->getMessage(), ['releases' => count($releaseIds)]);
                $this->handDeferredReleasesToRepair($releaseIds);

                continue;
            }

            $this->clearDeferredReleaseMarkers(array_values(array_diff($releaseIds, $failed)));
            if ($failed !== []) {
                $this->handDeferredReleasesToRepair($failed);
            }
        }
    }

    /**
     * Write or renew this scope's lease on a release. Fails, without touching the row,
     * when another live scope holds the lease or the database write fails.
     */
    private function markReleaseUpdateDeferred(int $releaseId): bool
    {
        $now = now();
        $lease = [
            'operation' => self::DEFERRED_RELEASE_OPERATION,
            'attempts' => 0,
            'last_error' => $this->deferralToken,
            'next_attempt_at' => $now->copy()->addSeconds(self::DEFERRED_RELEASE_LEASE_SECONDS),
            'resolved_at' => null,
            'updated_at' => $now,
        ];

        try {
            if (DB::table('search_index_failures')->insertOrIgnore([$lease + ['release_id' => $releaseId, 'created_at' => $now]]) > 0) {
                return true;
            }

            // Take over an ordinary failure row, renew our own lease, or take over a lease
            // whose scope died; never another live scope's lease.
            return DB::table('search_index_failures')
                ->where('release_id', $releaseId)
                ->where(function ($query) use ($now): void {
                    $query->where('operation', '!=', self::DEFERRED_RELEASE_OPERATION)
                        ->orWhere('last_error', $this->deferralToken)
                        ->orWhere('next_attempt_at', '<=', $now);
                })
                ->update($lease) > 0;
        } catch (\Throwable $e) {
            Log::debug('Search: unable to record a deferred release index update', [
                'release_id' => $releaseId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Remove this scope's markers for releases the driver reported refreshed. A marker
     * another scope has since taken over carries that scope's token and stays.
     *
     * @param  list<int>  $releaseIds
     */
    private function clearDeferredReleaseMarkers(array $releaseIds): void
    {
        if ($releaseIds === []) {
            return;
        }

        try {
            DB::table('search_index_failures')
                ->whereIn('release_id', $releaseIds)
                ->where('operation', self::DEFERRED_RELEASE_OPERATION)
                ->where('last_error', $this->deferralToken)
                ->delete();
        } catch (\Throwable $e) {
            Log::debug('Search: unable to clear deferred release index markers', [
                'releases' => count($releaseIds),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * End this scope's lease on releases it could not refresh, so nntmux:search-repair
     * retries them now instead of when the lease would have run out.
     *
     * @param  list<int>  $releaseIds
     */
    private function handDeferredReleasesToRepair(array $releaseIds): void
    {
        try {
            DB::table('search_index_failures')
                ->whereIn('release_id', $releaseIds)
                ->where('operation', self::DEFERRED_RELEASE_OPERATION)
                ->where('last_error', $this->deferralToken)
                ->update(['next_attempt_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::debug('Search: unable to hand deferred releases to repair', [
                'releases' => count($releaseIds),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete a release from the search index.
     */
    public function deleteRelease(int $id): void
    {
        $this->deleteReleases([$id]);
    }

    public function deleteReleases(iterable $ids): void
    {
        $this->driver()->deleteReleases($ids);
    }

    /**
     * Insert a predb record into the search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function insertPredb(array $parameters): void
    {
        $this->driver()->insertPredb($parameters);
    }

    /**
     * Update a predb record in the search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function updatePreDb(array $parameters): void
    {
        $this->driver()->updatePreDb($parameters);
    }

    /**
     * Search the releases index.
     *
     * @param  array<string, mixed>  $phrases
     * @return array<string, mixed>
     */
    public function searchReleases(array|string $phrases, int $limit = 1000): array
    {
        return $this->driver()->searchReleases($phrases, $limit);
    }

    /**
     * Search releases with fuzzy fallback.
     *
     * If exact search returns no results and fuzzy is enabled, this method
     * will automatically try a fuzzy search as a fallback.
     *
     * @param  array<string, mixed>  $phrases
     * @return array<string, mixed>
     */
    public function searchReleasesWithFuzzy(array|string $phrases, int $limit = 1000, bool $forceFuzzy = false): array
    {
        return $this->driver()->searchReleasesWithFuzzy($phrases, $limit, $forceFuzzy);
    }

    /**
     * Perform fuzzy search on releases index.
     *
     * @param  array<string, mixed>  $phrases
     * @return array<string, mixed>
     */
    public function fuzzySearchReleases(array|string $phrases, int $limit = 1000): array
    {
        return $this->driver()->fuzzySearchReleases($phrases, $limit);
    }

    /**
     * Search the predb index.
     *
     * @param  array<string, mixed>  $searchTerm
     * @return list<array{id: int, title: string, filename: string, source: string}>
     */
    public function searchPredb(array|string $searchTerm): array
    {
        return $this->driver()->searchPredb($searchTerm);
    }

    /** @return array{id: int, title: string, filename: string, source: string}|null */
    public function matchPredbExact(string $name): ?array
    {
        return $this->driver()->matchPredbExact($name);
    }

    /**
     * Get autocomplete suggestions for a search query.
     */
    public function autocomplete(string $query, ?string $index = null): array
    {
        return $this->driver()->autocomplete($query, $index);
    }

    /**
     * Get spell correction suggestions.
     */
    public function suggest(string $query, ?string $index = null): array
    {
        return $this->driver()->suggest($query, $index);
    }

    /**
     * Truncate/clear an index (remove all documents).
     *
     * @param  array<string, mixed>|string  $indexes  Index name(s) to truncate
     */
    public function truncateIndex(array|string $indexes): void
    {
        $this->driver()->truncateIndex($indexes);
    }

    /**
     * Optimize index for better search performance.
     */
    public function optimizeIndex(): void
    {
        $this->driver()->optimizeIndex();
    }

    /**
     * Bulk insert multiple releases into the index.
     *
     * @param  array<string, mixed>  $releases  Array of release data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertReleases(array $releases): array
    {
        return $this->driver()->bulkInsertReleases($releases);
    }

    /**
     * Bulk insert multiple predb records into the index.
     *
     * @param  array<string, mixed>  $predbRecords  Array of predb data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertPredb(array $predbRecords): array
    {
        return $this->driver()->bulkInsertPredb($predbRecords);
    }

    /**
     * Delete a predb record from the index.
     *
     * @param  int  $id  Predb ID
     */
    public function deletePreDb(int $id): void
    {
        $this->driver()->deletePreDb($id);
    }

    /**
     * Get the movies index name.
     */
    public function getMoviesIndex(): string
    {
        return $this->driver()->getMoviesIndex();
    }

    /**
     * Get the TV shows index name.
     */
    public function getTvShowsIndex(): string
    {
        return $this->driver()->getTvShowsIndex();
    }

    /**
     * Insert a movie into the movies search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function insertMovie(array $parameters): void
    {
        $this->driver()->insertMovie($parameters);
    }

    /**
     * Update a movie in the search index.
     */
    public function updateMovie(int $movieId): void
    {
        $this->driver()->updateMovie($movieId);
    }

    /**
     * Delete a movie from the search index.
     */
    public function deleteMovie(int $id): void
    {
        $this->driver()->deleteMovie($id);
    }

    /**
     * Bulk insert multiple movies into the index.
     *
     * @param  array<string, mixed>  $movies  Array of movie data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertMovies(array $movies): array
    {
        return $this->driver()->bulkInsertMovies($movies);
    }

    /**
     * Search the movies index.
     *
     * @param  array<string, mixed>  $searchTerm
     * @return array<string, mixed>
     */
    public function searchMovies(array|string $searchTerm, int $limit = 1000): array
    {
        return $this->driver()->searchMovies($searchTerm, $limit);
    }

    /**
     * Search movies by external ID (IMDB, TMDB, Trakt).
     *
     * @return array<string, mixed>
     */
    public function searchMovieByExternalId(string $field, int|string $value): ?array
    {
        return $this->driver()->searchMovieByExternalId($field, $value);
    }

    /**
     * @param  array<string, mixed>  $externalIds
     * @return array<string, mixed>|null
     */
    public function searchMovieByExternalIds(array $externalIds): ?array
    {
        return $this->driver()->searchMovieByExternalIds($externalIds);
    }

    /**
     * @param  array<string, string>  $fieldTerms
     * @return array{imdbids: list<string>, movieinfo_ids: list<int>, data: list<array<string, mixed>>}
     */
    public function searchMoviesByFields(array $fieldTerms, int $limit = 5000): array
    {
        return $this->driver()->searchMoviesByFields($fieldTerms, $limit);
    }

    /**
     * Insert a TV show into the tvshows search index.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function insertTvShow(array $parameters): void
    {
        $this->driver()->insertTvShow($parameters);
    }

    /**
     * Update a TV show in the search index.
     */
    public function updateTvShow(int $videoId): void
    {
        $this->driver()->updateTvShow($videoId);
    }

    /**
     * Delete a TV show from the search index.
     */
    public function deleteTvShow(int $id): void
    {
        $this->driver()->deleteTvShow($id);
    }

    /**
     * Bulk insert multiple TV shows into the index.
     *
     * @param  array<string, mixed>  $tvShows  Array of TV show data arrays
     * @return array<string, mixed> Results with 'success' and 'errors' counts
     */
    public function bulkInsertTvShows(array $tvShows): array
    {
        return $this->driver()->bulkInsertTvShows($tvShows);
    }

    /**
     * Search the TV shows index.
     *
     * @param  array<string, mixed>  $searchTerm
     * @return array<string, mixed>
     */
    public function searchTvShows(array|string $searchTerm, int $limit = 1000): array
    {
        return $this->driver()->searchTvShows($searchTerm, $limit);
    }

    /**
     * Search TV shows by external ID (TVDB, Trakt, TVMaze, TVRage, IMDB, TMDB).
     *
     * @return array<string, mixed>
     */
    public function searchTvShowByExternalId(string $field, int|string $value): ?array
    {
        return $this->driver()->searchTvShowByExternalId($field, $value);
    }

    /**
     * @param  array<string, mixed>  $externalIds
     * @return array<string, mixed>|null
     */
    public function searchTvShowByExternalIds(array $externalIds): ?array
    {
        return $this->driver()->searchTvShowByExternalIds($externalIds);
    }

    /**
     * Search releases by external media IDs.
     * Used to find releases associated with a specific movie or TV show.
     *
     * @param  array<string, mixed>  $externalIds
     * @return array<string, mixed>
     */
    public function searchReleasesByExternalId(array $externalIds, int $limit = 1000): array
    {
        return $this->driver()->searchReleasesByExternalId($externalIds, $limit);
    }

    /**
     * @param  array<int, array<string, mixed>>  $externalIdSets
     * @return array<string, mixed>
     */
    public function searchReleasesByMultipleExternalIds(array $externalIdSets, int $limit = 1000): array
    {
        return $this->driver()->searchReleasesByMultipleExternalIds($externalIdSets, $limit);
    }

    /**
     * Search releases by category ID using the search index.
     * This provides a fast way to get release IDs for a specific category without hitting the database.
     *
     * @param  array<string, mixed>  $categoryIds
     * @return array<string, mixed>
     */
    public function searchReleasesByCategory(array $categoryIds, int $limit = 1000): array
    {
        return $this->driver()->searchReleasesByCategory($categoryIds, $limit);
    }

    /**
     * Combined search: text search with category filtering.
     * First searches by text, then filters by category IDs using the search index.
     *
     * @param  array<string, mixed>  $categoryIds
     * @return array<string, mixed>
     */
    public function searchReleasesWithCategoryFilter(string $searchTerm, array $categoryIds = [], int $limit = 1000): array
    {
        return $this->driver()->searchReleasesWithCategoryFilter($searchTerm, $categoryIds, $limit);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array{ids: list<int>, total: int, fuzzy: bool}
     */
    public function searchReleasesFiltered(array $criteria, int $limit, int $offset = 0): array
    {
        return $this->driver()->searchReleasesFiltered($criteria, $limit, $offset);
    }

    public function searchReleasePage(ReleaseSearchQuery $query): SearchPage
    {
        return $this->driver()->searchReleasePage($query);
    }

    public function insertSecondary(SecondarySearchIndex $index, int $id, array $document): void
    {
        $this->driver()->insertSecondary($index, $id, $document);
    }

    public function updateSecondary(SecondarySearchIndex $index, int $id): void
    {
        $this->driver()->updateSecondary($index, $id);
    }

    public function deleteSecondary(SecondarySearchIndex $index, int $id): void
    {
        $this->driver()->deleteSecondary($index, $id);
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     */
    public function bulkInsertSecondary(SecondarySearchIndex $index, array $documents): array
    {
        return $this->driver()->bulkInsertSecondary($index, $documents);
    }

    public function searchSecondary(SecondarySearchIndex $index, string $query, int $limit = 100): array
    {
        return $this->driver()->searchSecondary($index, $query, $limit);
    }

    public function searchAnimeTitle(string $query, int $limit = 100): array
    {
        return $this->driver()->searchAnimeTitle($query, $limit);
    }
}
