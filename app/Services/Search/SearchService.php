<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\SecondarySearchIndex;
use App\Services\Search\Contracts\BulkReleaseIndexUpdater;
use App\Services\Search\Contracts\PredbFilenameCandidateLookup;
use App\Services\Search\Contracts\ReleaseCandidatePager;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchPage;
use App\Support\RustPipelineLibrary;
use FFI;
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
     * Rust candidates for the pure helpers below. They stay opt-in: none beat PHP by
     * 1.5x in the paired benchmark (tests/Unit/RustSearchMediaApiCapabilitiesParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_search_service_escape_string(int32_t kind, const char *input, size_t len, char *out, size_t cap);
        int32_t nntmux_search_fuzzy_config_normalize(const int32_t *tags, const int64_t *ints, const char *input, size_t len, const int64_t *lens, int64_t *out);
        ssize_t nntmux_search_release_ids_diff(const int64_t *ids, size_t count, const int64_t *failed, size_t failed_count, int64_t *out, size_t cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
     * Encode PHP values for the Rust helpers as (tag, int, bytes): 0 null, 1 bool,
     * 2 int, 3 string, 5 array (int holds its count). Null for other types (floats,
     * objects), which stay on the PHP path.
     *
     * @param  list<mixed>  $values
     * @return array{tags: list<int>, ints: list<int>, bytes: string, lens: list<int>}|null
     */
    public static function encodeFfiValues(array $values): ?array
    {
        $encoded = ['tags' => [], 'ints' => [], 'bytes' => '', 'lens' => []];
        foreach ($values as $value) {
            [$tag, $int, $bytes] = match (true) {
                $value === null => [0, 0, ''],
                \is_bool($value) => [1, (int) $value, ''],
                \is_int($value) => [2, $value, ''],
                \is_string($value) => [3, 0, $value],
                \is_array($value) => [5, \count($value), ''],
                default => [null, 0, ''],
            };
            if ($tag === null) {
                return null;
            }
            $encoded['tags'][] = $tag;
            $encoded['ints'][] = $int;
            $encoded['bytes'] .= $bytes;
            $encoded['lens'][] = \strlen($bytes);
        }

        return $encoded;
    }

    /**
     * @param  list<int>  $values
     */
    public static function ffiInt64Array(FFI $ffi, array $values): ?FFI\CData
    {
        $count = \count($values);
        if ($count === 0) {
            return null;
        }
        $array = $ffi->new("int64_t[{$count}]");
        foreach ($values as $i => $value) {
            $array[$i] = $value;
        }

        return $array;
    }

    /**
     * @param  list<int>  $values
     */
    public static function ffiInt32Array(FFI $ffi, array $values): ?FFI\CData
    {
        $count = \count($values);
        if ($count === 0) {
            return null;
        }
        $array = $ffi->new("int32_t[{$count}]");
        foreach ($values as $i => $value) {
            $array[$i] = $value;
        }

        return $array;
    }

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
        return self::escapeForDriverClass(get_class($this->driver()), $string);
    }

    /**
     * @param  class-string<SearchDriverInterface>  $driverClass
     */
    public static function escapeForDriverClass(string $driverClass, string $string): string
    {
        return self::$useRustFfi
            ? self::escapeForDriverClassNative($driverClass, $string)
            : self::escapeForDriverClassPhp($driverClass, $string);
    }

    /**
     * @param  class-string<SearchDriverInterface>  $driverClass
     */
    public static function escapeForDriverClassPhp(string $driverClass, string $string): string
    {
        return $driverClass::escapeString($string);
    }

    /**
     * @param  class-string<SearchDriverInterface>  $driverClass
     */
    public static function escapeForDriverClassNative(string $driverClass, string $string): string
    {
        $ffi = $driverClass === ManticoreSearchDriver::class ? self::searchFfi() : null;
        if ($ffi === null) {
            return self::escapeForDriverClassPhp($driverClass, $string);
        }

        $len = \strlen($string);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len * 3 + 16), $cap);
        if ($scratch === null) {
            return self::escapeForDriverClassPhp($driverClass, $string);
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_search_service_escape_string(0, $string, $len, $scratch, $cap);
        if (\is_int($written) && $written < -2) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written - 3, $cap);
            if ($scratch === null) {
                return self::escapeForDriverClassPhp($driverClass, $string);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_search_service_escape_string(0, $string, $len, $scratch, $cap);
        }
        if (! \is_int($written) || $written < 0) {
            return self::escapeForDriverClassPhp($driverClass, $string);
        }

        return $written === 0 ? '' : FFI::string($scratch, $written);
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
     * Fuzzy settings with the defaults and casts the drivers apply when they read them.
     *
     * @return array{enabled: bool, max_distance: int, prefix_length: int, max_expansions: int}
     */
    public function getNormalizedFuzzyConfig(): array
    {
        return self::normalizeFuzzyConfig($this->getFuzzyConfig());
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{enabled: bool, max_distance: int, prefix_length: int, max_expansions: int}
     */
    public static function normalizeFuzzyConfig(array $config): array
    {
        return self::$useRustFfi ? self::normalizeFuzzyConfigNative($config) : self::normalizeFuzzyConfigPhp($config);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{enabled: bool, max_distance: int, prefix_length: int, max_expansions: int}
     */
    public static function normalizeFuzzyConfigPhp(array $config): array
    {
        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'max_distance' => (int) ($config['max_distance'] ?? 2),
            'prefix_length' => (int) ($config['prefix_length'] ?? 2),
            'max_expansions' => (int) ($config['max_expansions'] ?? 50),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{enabled: bool, max_distance: int, prefix_length: int, max_expansions: int}
     */
    public static function normalizeFuzzyConfigNative(array $config): array
    {
        $ffi = self::searchFfi();
        $encoded = $ffi === null ? null : self::encodeFfiValues([
            $config['enabled'] ?? null,
            $config['max_distance'] ?? null,
            $config['prefix_length'] ?? null,
            $config['max_expansions'] ?? null,
        ]);
        if ($ffi === null || $encoded === null) {
            return self::normalizeFuzzyConfigPhp($config);
        }

        $out = $ffi->new('int64_t[4]');
        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_search_fuzzy_config_normalize(
            self::ffiInt32Array($ffi, $encoded['tags']),
            self::ffiInt64Array($ffi, $encoded['ints']),
            $encoded['bytes'],
            \strlen($encoded['bytes']),
            self::ffiInt64Array($ffi, $encoded['lens']),
            $out,
        );
        if ($rc !== 0) {
            return self::normalizeFuzzyConfigPhp($config);
        }

        return [
            'enabled' => $out[0] === 1,
            'max_distance' => (int) $out[1],
            'prefix_length' => (int) $out[2],
            'max_expansions' => (int) $out[3],
        ];
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

            $this->clearDeferredReleaseMarkers(self::refreshedReleaseIds($releaseIds, $failed));
            // Whatever is left: reported failures, and markers on which another worker
            // counted a failure after the refresh read its rows.
            $this->handDeferredReleasesToRepair($releaseIds);
        }
    }

    /**
     * The deferred releases the driver refreshed: `$releaseIds` without the failures,
     * in order.
     *
     * @param  list<int>  $releaseIds
     * @param  array<int, int|string>  $failed
     * @return list<int>
     */
    public static function refreshedReleaseIds(array $releaseIds, array $failed): array
    {
        return self::$useRustFfi
            ? self::refreshedReleaseIdsNative($releaseIds, $failed)
            : self::refreshedReleaseIdsPhp($releaseIds, $failed);
    }

    /**
     * @param  list<int>  $releaseIds
     * @param  array<int, int|string>  $failed
     * @return list<int>
     */
    public static function refreshedReleaseIdsPhp(array $releaseIds, array $failed): array
    {
        return array_values(array_diff($releaseIds, $failed));
    }

    /**
     * @param  list<int>  $releaseIds
     * @param  array<int, int|string>  $failed
     * @return list<int>
     */
    public static function refreshedReleaseIdsNative(array $releaseIds, array $failed): array
    {
        if ($releaseIds === [] || $failed === []) {
            return self::refreshedReleaseIdsPhp($releaseIds, $failed);
        }
        foreach ($releaseIds as $id) {
            if (! \is_int($id)) {
                return self::refreshedReleaseIdsPhp($releaseIds, $failed);
            }
        }
        $failedIds = [];
        foreach ($failed as $id) {
            if (! \is_int($id)) {
                return self::refreshedReleaseIdsPhp($releaseIds, $failed);
            }
            $failedIds[] = $id;
        }
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::refreshedReleaseIdsPhp($releaseIds, $failed);
        }

        $count = \count($releaseIds);
        $out = $ffi->new("int64_t[{$count}]");
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_search_release_ids_diff(
            self::ffiInt64Array($ffi, $releaseIds),
            $count,
            self::ffiInt64Array($ffi, $failedIds),
            \count($failedIds),
            $out,
            $count,
        );
        if (! \is_int($written) || $written < 0) {
            return self::refreshedReleaseIdsPhp($releaseIds, $failed);
        }

        $kept = [];
        for ($i = 0; $i < $written; $i++) {
            $kept[] = (int) $out[$i];
        }

        return $kept;
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
     * is written with zero attempts; one that has counted a failure since (another
     * worker's update failed, possibly after the refresh read the row) stays, as does a
     * marker another scope has since taken over, which carries that scope's token.
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
                ->where('attempts', 0)
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

    public function supportsReleaseCandidatePaging(): bool
    {
        return $this->driver() instanceof ReleaseCandidatePager;
    }

    /** @return array{ids: list<int>, available: bool, has_more: bool} */
    public function searchReleaseCandidatesPage(string $title, int $limit = 21, int $afterId = 0): array
    {
        $driver = $this->driver();
        if (! $driver instanceof ReleaseCandidatePager) {
            return ['ids' => [], 'available' => false, 'has_more' => false];
        }

        return $driver->searchReleaseCandidatesPage($title, $limit, $afterId);
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

    /** @return list<array{id: int, title: string, filename: string, source: string}>|null */
    public function searchPredbFilenameCandidates(string $searchTerm): ?array
    {
        $driver = $this->driver();

        return $driver instanceof PredbFilenameCandidateLookup
            ? $driver->searchPredbFilenameCandidates($searchTerm)
            : null;
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
