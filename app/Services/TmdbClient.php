<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TvProviderException;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Custom TMDB (The Movie Database) API Client
 *
 * This service provides methods to interact with The Movie Database API
 * for fetching movie and TV show information.
 */
class TmdbClient
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmdb_get_string(const char *ser_ptr, size_t ser_len, const char *key_ptr, size_t key_len, const char *def_ptr, size_t def_len, char *out, size_t cap);
        int32_t nntmux_tmdb_get_int(const char *ser_ptr, size_t ser_len, const char *key_ptr, size_t key_len, int64_t default_val, int64_t *out_val);
        int32_t nntmux_tmdb_get_float(const char *ser_ptr, size_t ser_len, const char *key_ptr, size_t key_len, double default_val, double *out_val);
        int32_t nntmux_tmdb_get_bool(const char *ser_ptr, size_t ser_len, const char *key_ptr, size_t key_len, uint32_t default_val);
        ssize_t nntmux_tmdb_get_array(const char *ser_ptr, size_t ser_len, const char *key_ptr, size_t key_len, char *out, size_t cap);
        ssize_t nntmux_tmdb_get_image_url(const char *path_ptr, size_t path_len, const char *size_ptr, size_t size_len, char *out, size_t cap);
        C;

    protected const BASE_URL = 'https://api.themoviedb.org/3';

    protected const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p';

    /** Null selects the benchmark-qualified production default. */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    protected string $apiKey;

    protected int $timeout;

    protected int $retryTimes;

    protected int $retryDelay;

    /**
     * Throw TvProviderException on rate limits, server errors and connection failures instead of returning null.
     */
    protected bool $throwOnTransientFailure = false;

    public function __construct()
    {
        $this->apiKey = (string) config('tmdb.api_key', '');
        $this->timeout = (int) config('tmdb.timeout', 30);
        $this->retryTimes = (int) config('tmdb.retry_times', 3);
        $this->retryDelay = (int) config('tmdb.retry_delay', 100);
    }

    /**
     * Check if the API key is configured
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * A copy of this client that throws TvProviderException on transient failures
     * (429, 5xx, connection errors) so callers can retry later instead of treating them as "not found".
     */
    public function throwingOnTransientFailure(): static
    {
        $client = clone $this;
        $client->throwOnTransientFailure = true;

        return $client;
    }

    /**
     * Make a GET request to the TMDB API
     *
     * @param  string  $endpoint  The API endpoint
     * @param  array<string, mixed>  $params  Additional query parameters
     * @return array<string, mixed>|null Response data or null on failure
     */
    protected function get(string $endpoint, array $params = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $params['api_key'] = $this->apiKey;

        try {
            $response = Http::timeout($this->timeout)
                ->retry($this->retryTimes, $this->retryDelay, function (Throwable $exception, PendingRequest $request, ?string $key = null) {
                    // Don't retry on 404 errors - resource simply doesn't exist
                    if ($exception instanceof RequestException) {
                        $status = $exception->response->status();

                        // A rate limit won't clear within the retry delay; let the caller defer instead.
                        return $status !== 404 && ! ($status === 429 && $this->throwOnTransientFailure);
                    }

                    return true;
                }, throw: false)
                ->get(self::BASE_URL.$endpoint, $params);

            if ($response->successful()) {
                return $response->json();
            }

            // Handle specific error codes - 404 is normal (resource not found)
            if ($response->status() === 404) {
                Log::debug('TMDB: Resource not found', ['endpoint' => $endpoint]);

                return null;
            }

            if ($this->throwOnTransientFailure && TvProviderException::isTransientStatus($response->status())) {
                throw new TvProviderException(
                    'tmdb',
                    sprintf('TMDB returned %d for %s', $response->status(), $endpoint),
                    $response->status(),
                    TvProviderException::parseRetryAfter($response->header('Retry-After')),
                );
            }

            Log::warning('TMDB API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (TvProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Check if this is a 404 wrapped in an exception
            if ($e instanceof RequestException && $e->response->status() === 404) {
                Log::debug('TMDB: Resource not found', ['endpoint' => $endpoint]);

                return null;
            }

            if ($this->throwOnTransientFailure) {
                throw new TvProviderException('tmdb', 'TMDB request failed: '.$e->getMessage(), null, null, $e);
            }

            Log::warning('TMDB API request exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the full image URL
     *
     * @param  string|null  $path  The image path from TMDB
     * @param  string  $size  The image size (w92, w154, w185, w342, w500, w780, original)
     */
    public function getImageUrl(?string $path, string $size = 'w500'): string
    {
        return (self::$useRustFfi ?? false)
            ? $this->getImageUrlNative($path, $size)
            : $this->getImageUrlPhp($path, $size);
    }

    public function getImageUrlNative(?string $path, string $size = 'w500'): string
    {
        if ($path === null || $path === '' || $path === '0') {
            return '';
        }
        if (($ffi = self::ffi()) === null) {
            return $this->getImageUrlPhp($path, $size);
        }
        try {
            $pathLen = strlen($path);
            $sizeLen = strlen($size);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $pathLen + $sizeLen + 64), $capacity);
            if ($buffer === null) {
                return $this->getImageUrlPhp($path, $size);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmdb_get_image_url($path, $pathLen, $size, $sizeLen, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->getImageUrlPhp($path, $size);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmdb_get_image_url($path, $pathLen, $size, $sizeLen, $buffer, $capacity);
            }
            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                return $written === 0 ? '' : FFI::string($buffer, $written);
            }
        } catch (Throwable) {
        }

        return $this->getImageUrlPhp($path, $size);
    }

    public function getImageUrlPhp(?string $path, string $size = 'w500'): string
    {
        if (empty($path)) {
            return '';
        }

        return self::IMAGE_BASE_URL.'/'.$size.$path;
    }

    // =========================================================================
    // MOVIE METHODS
    // =========================================================================

    /**
     * Search for movies by title
     *
     * @param  string  $query  The search query
     * @param  int  $page  Page number for pagination
     * @param  string|null  $year  Filter by release year
     * @return array<string, mixed>|null Search results or null on failure
     */
    public function searchMovies(string $query, int $page = 1, ?string $year = null): ?array
    {
        $params = [
            'query' => $query,
            'page' => $page,
            'include_adult' => false,
        ];

        if ($year !== null) {
            $params['year'] = $year;
        }

        return $this->get('/search/movie', $params);
    }

    /**
     * Get movie details by TMDB ID or IMDB ID
     *
     * @param  int|string  $id  The TMDB ID or IMDB ID (with 'tt' prefix)
     * @param  array<string, mixed>  $appendToResponse  Additional data to append (e.g., ['credits', 'external_ids'])
     * @return array<string, mixed>|null Movie data or null on failure
     */
    public function getMovie(int|string $id, array $appendToResponse = []): ?array
    {
        if (is_string($id) && str_starts_with($id, 'tt')) {
            $movie = $this->findMovieByExternalId($id, 'imdb_id');
            if ($movie === null) {
                return null;
            }

            $tmdbId = self::getInt($movie, 'id');
            if ($tmdbId <= 0) {
                return null;
            }

            return $this->getMovie($tmdbId, $appendToResponse);
        }

        $params = [];

        if (! empty($appendToResponse)) {
            $params['append_to_response'] = implode(',', $appendToResponse);
        }

        return $this->get('/movie/'.$id, $params);
    }

    /**
     * Find a movie by external ID (IMDb, etc.)
     *
     * @param  string  $externalId  The external ID value
     * @param  string  $source  The source: 'imdb_id'
     * @return array<string, mixed>|null The TMDB movie data or null if not found
     */
    public function findMovieByExternalId(string $externalId, string $source = 'imdb_id'): ?array
    {
        if (empty($externalId)) {
            return null;
        }

        if ($source !== 'imdb_id') {
            return null;
        }

        $result = $this->get('/find/'.$externalId, [
            'external_source' => $source,
        ]);

        if ($result === null || empty($result['movie_results'])) {
            return null;
        }

        return $result['movie_results'][0] ?? null;
    }

    /**
     * Get movie credits (cast and crew)
     *
     * @param  int  $movieId  The TMDB movie ID
     * @return array<string, mixed>|null Credits data or null on failure
     */
    public function getMovieCredits(int $movieId): ?array
    {
        return $this->get('/movie/'.$movieId.'/credits');
    }

    /**
     * Get movie external IDs (IMDB, etc.)
     *
     * @param  int  $movieId  The TMDB movie ID
     * @return array<string, mixed>|null External IDs or null on failure
     */
    public function getMovieExternalIds(int $movieId): ?array
    {
        return $this->get('/movie/'.$movieId.'/external_ids');
    }

    /**
     * Get alternative titles for a movie (including international / alternate names).
     *
     * @param  int  $movieId  The TMDB movie ID
     * @return array<string, mixed>|null Response with 'titles' array (each has 'title', 'iso_3166_1', 'type') or null on failure
     */
    public function getMovieAlternativeTitles(int $movieId): ?array
    {
        return $this->get('/movie/'.$movieId.'/alternative_titles');
    }

    // =========================================================================
    // TV SHOW METHODS
    // =========================================================================

    /**
     * Search for TV shows by title
     *
     * @param  string  $query  The search query
     * @param  int  $page  Page number for pagination
     * @param  int|null  $firstAirDateYear  Filter by first air date year
     * @return array<string, mixed>|null Search results or null on failure
     */
    public function searchTv(string $query, int $page = 1, ?int $firstAirDateYear = null): ?array
    {
        $params = [
            'query' => $query,
            'page' => $page,
            'include_adult' => false,
        ];

        if ($firstAirDateYear !== null) {
            $params['first_air_date_year'] = $firstAirDateYear;
        }

        return $this->get('/search/tv', $params);
    }

    /**
     * Get TV show details by ID
     *
     * @param  int|string  $id  The TMDB TV show ID
     * @param  list<string>  $appendToResponse  Additional data to append
     * @return array<string, mixed>|null TV show data or null on failure
     */
    public function getTvShow(int|string $id, array $appendToResponse = []): ?array
    {
        $params = [];

        if (! empty($appendToResponse)) {
            $params['append_to_response'] = implode(',', $appendToResponse);
        }

        return $this->get('/tv/'.$id, $params);
    }

    /**
     * Get TV show external IDs (IMDB, TVDB, etc.)
     *
     * @param  int  $tvId  The TMDB TV show ID
     * @return array<string, mixed>|null External IDs or null on failure
     */
    public function getTvExternalIds(int $tvId): ?array
    {
        return $this->get('/tv/'.$tvId.'/external_ids');
    }

    /**
     * Get TV show alternative titles
     *
     * @param  int  $tvId  The TMDB TV show ID
     * @return array<string, mixed>|null Alternative titles or null on failure
     */
    public function getTvAlternativeTitles(int $tvId): ?array
    {
        return $this->get('/tv/'.$tvId.'/alternative_titles');
    }

    /**
     * Get TV season details
     *
     * @param  int  $tvId  The TMDB TV show ID
     * @param  int  $seasonNumber  The season number
     * @return array<string, mixed>|null Season data or null on failure
     */
    public function getTvSeason(int $tvId, int $seasonNumber): ?array
    {
        // Validate parameters to avoid unnecessary API calls with invalid IDs
        if ($tvId <= 0 || $seasonNumber < 0) {
            return null;
        }

        return $this->get('/tv/'.$tvId.'/season/'.$seasonNumber);
    }

    /**
     * Get TV episode details
     *
     * @param  int  $tvId  The TMDB TV show ID
     * @param  int  $seasonNumber  The season number
     * @param  int  $episodeNumber  The episode number
     * @return array<string, mixed>|null Episode data or null on failure
     */
    public function getTvEpisode(int $tvId, int $seasonNumber, int $episodeNumber): ?array
    {
        // Validate parameters to avoid unnecessary API calls with invalid IDs
        if ($tvId <= 0 || $seasonNumber < 0 || $episodeNumber < 0) {
            return null;
        }

        return $this->get('/tv/'.$tvId.'/season/'.$seasonNumber.'/episode/'.$episodeNumber);
    }

    /**
     * Find a TV show by external ID (IMDB, TVDB, etc.)
     *
     * @param  string  $externalId  The external ID value
     * @param  string  $source  The source: 'imdb_id', 'tvdb_id', 'tvrage_id'
     * @return array<string, mixed>|null The TMDB TV show data or null if not found
     */
    public function findTvByExternalId(string $externalId, string $source = 'tvdb_id'): ?array
    {
        if (empty($externalId)) {
            return null;
        }

        $validSources = ['imdb_id', 'tvdb_id', 'tvrage_id'];
        if (! in_array($source, $validSources, true)) {
            return null;
        }

        $result = $this->get('/find/'.$externalId, [
            'external_source' => $source,
        ]);

        if ($result === null || empty($result['tv_results'])) {
            return null;
        }

        // Return the first TV result
        return $result['tv_results'][0] ?? null;
    }

    /**
     * Get TV episode with fallback using multiple external IDs.
     * Tries TMDB ID first, then looks up by TVDB or IMDB if needed.
     *
     * @param  array<string, mixed>  $ids  Array of IDs: ['tmdb' => X, 'tvdb' => Y, 'imdb' => Z]
     * @param  int  $seasonNumber  The season number
     * @param  int  $episodeNumber  The episode number
     * @return array<string, mixed>|null Episode data or null on failure
     */
    public function getTvEpisodeWithFallback(array $ids, int $seasonNumber, int $episodeNumber): ?array
    {
        // First try with TMDB ID if available
        $tmdbId = (int) ($ids['tmdb'] ?? 0);
        if ($tmdbId > 0) {
            $result = $this->getTvEpisode($tmdbId, $seasonNumber, $episodeNumber);
            if ($result !== null) {
                return $result;
            }
        }

        // Try to find the show by TVDB ID
        $tvdbId = (int) ($ids['tvdb'] ?? 0);
        if ($tvdbId > 0) {
            $show = $this->findTvByExternalId((string) $tvdbId, 'tvdb_id');
            if ($show !== null && isset($show['id'])) {
                $result = $this->getTvEpisode((int) $show['id'], $seasonNumber, $episodeNumber);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Try to find the show by IMDB ID
        $imdbId = $ids['imdb'] ?? '';
        if (! empty($imdbId)) {
            $imdbFormatted = str_starts_with((string) $imdbId, 'tt')
                ? (string) $imdbId
                : 'tt'.(string) $imdbId;

            $show = $this->findTvByExternalId($imdbFormatted, 'imdb_id');
            if ($show !== null && isset($show['id'])) {
                $result = $this->getTvEpisode((int) $show['id'], $seasonNumber, $episodeNumber);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Look up a TV show and get all its external IDs.
     * Can be used to cross-reference between providers.
     *
     * @param  int|string  $id  The external ID
     * @param  string  $source  The ID source: 'tmdb', 'tvdb', 'imdb'
     * @return array<string, mixed>|null Array with all IDs: ['tmdb' => X, 'imdb' => Y, 'tvdb' => Z] or null
     */
    public function lookupTvShowIds(int|string $id, string $source = 'tmdb'): ?array
    {
        $show = null;

        if ($source === 'tmdb' && is_numeric($id) && (int) $id > 0) {
            // Direct TMDB lookup
            $show = $this->getTvShow((int) $id);
            if ($show !== null) {
                $externalIds = $this->getTvExternalIds((int) $id);
                if ($externalIds !== null) {
                    $show['external_ids'] = $externalIds;
                }
            }
        } elseif ($source === 'tvdb' && is_numeric($id) && (int) $id > 0) {
            $show = $this->findTvByExternalId((string) $id, 'tvdb_id');
        } elseif ($source === 'imdb') {
            $imdbFormatted = str_starts_with((string) $id, 'tt')
                ? (string) $id
                : 'tt'.(string) $id;
            $show = $this->findTvByExternalId($imdbFormatted, 'imdb_id');
        }

        if ($show === null) {
            return null;
        }

        // If we found by external ID, we need to fetch external IDs for full data
        $tmdbId = self::getInt($show, 'id');
        if ($tmdbId > 0 && ! isset($show['external_ids'])) {
            $externalIds = $this->getTvExternalIds($tmdbId);
            $show['external_ids'] = $externalIds ?? [];
        }

        $externalIds = self::getArray($show, 'external_ids');

        // Parse IMDB ID to raw numeric string
        $imdbId = '';
        if (! empty($externalIds['imdb_id'])) {
            preg_match('/tt(?P<imdbid>\d{6,})$/i', (string) $externalIds['imdb_id'], $imdb);
            $imdbId = (string) ($imdb['imdbid'] ?? '');
        }

        return [
            'tmdb' => $tmdbId,
            'imdb' => $imdbId,
            'tvdb' => self::getInt($externalIds, 'tvdb_id'),
            'tvrage' => self::getInt($externalIds, 'tvrage_id'),
        ];
    }

    // =========================================================================
    // HELPER METHODS FOR NULL-SAFE DATA EXTRACTION
    // =========================================================================

    /**
     * Safely get a string value from an array
     *
     * @param  array<string, mixed>  $data
     */
    public static function getString(array $data, string $key, string $default = ''): string
    {
        return (self::$useRustFfi ?? false)
            ? self::getStringNative($data, $key, $default)
            : self::getStringPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function getStringNative(array $data, string $key, string $default = ''): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::getStringPhp($data, $key, $default);
        }
        try {
            $ser = @serialize($data);
            $serLen = strlen($ser);
            $keyLen = strlen($key);
            $defLen = strlen($default);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $serLen + $defLen + 32), $capacity);
            if ($buffer === null) {
                return self::getStringPhp($data, $key, $default);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmdb_get_string($ser, $serLen, $key, $keyLen, $default, $defLen, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::getStringPhp($data, $key, $default);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmdb_get_string($ser, $serLen, $key, $keyLen, $default, $defLen, $buffer, $capacity);
            }
            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                return $written === 0 ? '' : FFI::string($buffer, $written);
            }
        } catch (Throwable) {
        }

        return self::getStringPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function getStringPhp(array $data, string $key, string $default = ''): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : $default;
    }

    /**
     * Safely get an integer value from an array
     *
     * @param  array<string, mixed>  $data
     */
    public static function getInt(array $data, string $key, int $default = 0): int
    {
        return (self::$useRustFfi ?? false)
            ? self::getIntNative($data, $key, $default)
            : self::getIntPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function getIntNative(array $data, string $key, int $default = 0): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::getIntPhp($data, $key, $default);
        }
        try {
            $ser = @serialize($data);
            $outVal = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_tmdb_get_int($ser, strlen($ser), $key, strlen($key), $default, FFI::addr($outVal[0]));
            if ($status === 1) {
                /** @var int $val */
                $val = $outVal[0];

                return $val;
            }
        } catch (Throwable) {
        }

        return self::getIntPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function getIntPhp(array $data, string $key, int $default = 0): int
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : $default;
    }

    /**
     * Safely get a float value from an array
     *
     * @param  array<string, mixed>  $data
     */
    public static function getFloat(array $data, string $key, float $default = 0.0): float
    {
        return (self::$useRustFfi ?? false)
            ? self::getFloatNative($data, $key, $default)
            : self::getFloatPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function getFloatNative(array $data, string $key, float $default = 0.0): float
    {
        if (($ffi = self::ffi()) === null) {
            return self::getFloatPhp($data, $key, $default);
        }
        try {
            $ser = @serialize($data);
            $outVal = $ffi->new('double[1]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_tmdb_get_float($ser, strlen($ser), $key, strlen($key), $default, FFI::addr($outVal[0]));
            if ($status === 1) {
                /** @var float $val */
                $val = $outVal[0];

                return $val;
            }
        } catch (Throwable) {
        }

        return self::getFloatPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function getFloatPhp(array $data, string $key, float $default = 0.0): float
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (float) $data[$key] : $default;
    }

    /**
     * Safely get a boolean value from an array
     *
     * @param  array<string, mixed>  $data
     */
    public static function getBool(array $data, string $key, bool $default = false): bool
    {
        return (self::$useRustFfi ?? false)
            ? self::getBoolNative($data, $key, $default)
            : self::getBoolPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function getBoolNative(array $data, string $key, bool $default = false): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::getBoolPhp($data, $key, $default);
        }
        try {
            $ser = @serialize($data);
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_tmdb_get_bool($ser, strlen($ser), $key, strlen($key), $default ? 1 : 0);
            if ($status === 0 || $status === 1) {
                return $status === 1;
            }
        } catch (Throwable) {
        }

        return self::getBoolPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function getBoolPhp(array $data, string $key, bool $default = false): bool
    {
        if (! isset($data[$key])) {
            return $default;
        }
        $val = $data[$key];
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val)) {
            return $val !== 0;
        }
        if (is_float($val)) {
            return (int) $val !== 0;
        }
        if (is_string($val)) {
            $norm = strtolower(trim($val));
            if (in_array($norm, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($norm, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Safely get an array value from an array
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public static function getArray(array $data, string $key, array $default = []): array
    {
        return (self::$useRustFfi ?? false)
            ? self::getArrayNative($data, $key, $default)
            : self::getArrayPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public static function getArrayNative(array $data, string $key, array $default = []): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::getArrayPhp($data, $key, $default);
        }
        try {
            $ser = @serialize($data);
            $serLen = strlen($ser);
            $keyLen = strlen($key);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $serLen + 32), $capacity);
            if ($buffer === null) {
                return self::getArrayPhp($data, $key, $default);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmdb_get_array($ser, $serLen, $key, $keyLen, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::getArrayPhp($data, $key, $default);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmdb_get_array($ser, $serLen, $key, $keyLen, $buffer, $capacity);
            }
            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                if ($written === 0) {
                    return $default;
                }
                $unserialized = @unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);
                if (is_array($unserialized)) {
                    return $unserialized;
                }
            }
        } catch (Throwable) {
        }

        return self::getArrayPhp($data, $key, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public static function getArrayPhp(array $data, string $key, array $default = []): array
    {
        return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : $default;
    }

    /**
     * Safely get a nested value from an array using dot notation
     *
     * @param  array<string, mixed>  $data
     */
    public static function getNested(array $data, string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value ?? $default;
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.22x, p99 1.53x; uncommon mean 2.13x, p99 2.05x; see the W46 parity test). */
    public const bool W464_RUST_FFI_DEFAULT = true;

    public const string W464_FFI_CDEF = <<<'C'
        ssize_t nntmux_w464_tmdb_image_path_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W464_SUMMARY_KEYS = ['total_entries', 'empty_count', 'rooted_count', 'absolute_url_count', 'jpeg_count', 'png_count', 'svg_count', 'canonical_count', 'unsafe_char_count', 'double_slash_count', 'traversal_count'];

    /** unpack() format for the eleven little-endian int64 counters, in W464_SUMMARY_KEYS order. */
    private const string W464_UNPACK = 'Ptotal_entries/Pempty_count/Prooted_count/Pabsolute_url_count/Pjpeg_count/Ppng_count/Psvg_count/Pcanonical_count/Punsafe_char_count/Pdouble_slash_count/Ptraversal_count';

    private static ?bool $w464UseRustFfi = null;

    private static FFI|false|null $w464Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW464RustFfi(?bool $enable = true): void
    {
        self::$w464UseRustFfi = $enable;
    }

    public static function usesW464RustFfi(): bool
    {
        return self::$w464UseRustFfi ?? self::W464_RUST_FFI_DEFAULT;
    }

    public static function resetW464RustFfi(): void
    {
        self::$w464UseRustFfi = null;
        self::$w464Ffi = null;
    }

    /**
     * Summarize image paths as getImageUrl() sees them (untrimmed; '' and
     * '0' are empty): rooted paths, absolute URLs, JPEG/PNG/SVG extensions,
     * canonical /<alnum>.<ext> paths, bytes outside [A-Za-z0-9._/-], double
     * slashes and .. segments. Entries come from strings, integers, or the
     * file_path, poster_path, backdrop_path, profile_path, still_path or
     * logo_path field; other values count as empty.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTmdbImagePathBatch(array $entries): array
    {
        return self::usesW464RustFfi()
            ? self::summarizeTmdbImagePathBatchNative($entries)
            : self::summarizeTmdbImagePathBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTmdbImagePathBatchNative(array $entries): array
    {
        return self::w464Call(self::w464Entries($entries)) ?? self::summarizeTmdbImagePathBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTmdbImagePathBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W464_SUMMARY_KEYS, 0);
        foreach (self::w464Entries($entries) as $entry) {
            $summary['total_entries']++;
            if ($entry === '' || $entry === '0') {
                $summary['empty_count']++;

                continue;
            }
            $summary['rooted_count'] += (int) ($entry[0] === '/');
            $summary['absolute_url_count'] += (int) (preg_match('#\A[Hh][Tt][Tt][Pp][Ss]?://#', $entry) === 1);
            $summary['jpeg_count'] += (int) (preg_match('/\.[Jj][Pp][Ee]?[Gg]\z/', $entry) === 1);
            $summary['png_count'] += (int) (preg_match('/\.[Pp][Nn][Gg]\z/', $entry) === 1);
            $summary['svg_count'] += (int) (preg_match('/\.[Ss][Vv][Gg]\z/', $entry) === 1);
            $summary['canonical_count'] += (int) (preg_match('#\A/[A-Za-z0-9]+\.(?:jpg|png|svg)\z#', $entry) === 1);
            $summary['unsafe_char_count'] += (int) (preg_match('#[^A-Za-z0-9._/-]#', $entry) === 1);
            $summary['double_slash_count'] += (int) str_contains($entry, '//');
            $summary['traversal_count'] += (int) str_contains($entry, '..');
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w464Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['file_path'] ?? $item['poster_path'] ?? $item['backdrop_path'] ?? $item['profile_path'] ?? $item['still_path'] ?? $item['logo_path'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w464Call(array $entries): ?array
    {
        try {
            $input = serialize($entries);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w464Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w464', self::W464_FFI_CDEF) ?? false;
            $ffi = self::$w464Ffi;
            if ($ffi === false) {
                return null;
            }
            // obtainScratch() returns at least the 88 bytes requested.
            $out = RustPipelineLibrary::obtainScratch($ffi, 88);
            // @phpstan-ignore method.notFound (Declared by W464_FFI_CDEF.)
            if ($out === null || $ffi->nntmux_w464_tmdb_image_path_batch($input, strlen($input), $out, 88) !== 88) {
                return null;
            }
            /** @var array<string, int>|false $summary */
            $summary = unpack(self::W464_UNPACK, FFI::string($out, 88));
            $total = count($entries);
            if ($summary === false || $summary['total_entries'] !== $total || min($summary) < 0 || max($summary) > $total) {
                return null;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    /** C signature of the Wave 62.5 {@see self::requestPlanBatch()} helper. */
    private const string W62_TMDB_PLAN_FFI_CDEF = 'int64_t nntmux_w625_tmdb_request_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 62.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 7.65/5.41/6.67/6.80x); see tests/Unit/RustSteamTmdbClientTraktTurnstileReleaseSearchW62ParityTest.php.
     */
    public const bool W62_RUST_FFI_DEFAULT = true;

    /**
     * Wave 62.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w62_5', self::W62_TMDB_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W62_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW62RustFfi(): bool
    {
        return self::w62RustFfiState() === true;
    }

    /**
     * Runs $callback with requestPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 62.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w62Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w62RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w625_tmdb_request_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch TMDB request plan over
     * `endpoint\tid\tsource\tquery\tyear\tlanguage\timage_path\timage_size\trequests\twindow_started\tnow`. Eleven
     * words per record: `[endpoint_code, id, source_code, query_len, year, language, image_kind, size_code,
     * allowed, rate_wait, flags]`. Endpoints are 1 search_movie, 2 movie, 3 find, 4 search_tv, 5 tv, 6 tv_season,
     * 7 tv_episode, 8 credits, 9 external_ids, 10 alternative_titles; sources 1 imdb_id, 2 tvdb_id, 3 tmdb
     * (trimmed, ASCII case-insensitive, 0 empty, -1 unknown). The trimmed id is an ASCII case-insensitive `tt`
     * plus 6-10 digits for imdb_id, else 1-10 digits, with a positive value; endpoints 2-3 and 5-10 require one.
     * The query length joins its ASCII whitespace words with single spaces; search endpoints require one. The
     * year is four digits in 1874..2100 (empty 0). The language is `ll` or `ll-RR`/`ll_RR` letters as
     * `lang * 1000 + region` (letter pairs `(a-z) * 26 + (a-z) + 1`, region 0 when absent; empty or invalid
     * en-US). The trimmed image path is 0 empty, 1 rooted, 2 absolute `http(s)://`, 3 relative; it is unsafe with
     * `..`, control, space or DEL bytes. Sizes are 1 w92, 2 w154, 3 w185, 4 w300, 5 w342, 6 w500, 7 w780, 8
     * w1280, 9 h632, 10 original (empty or unknown w500). The rate window allows 40 requests (1-6 digits) per 10
     * seconds between 1-12 digit Unix seconds (empty 0) and reports the seconds until it reopens. Flags: 1 empty
     * endpoint, 2 unknown endpoint, 4 unknown source, 8 invalid id, 16 missing id, 32 missing query, 64 invalid
     * year, 128 invalid language, 256 unsafe image path, 512 unknown image size, 1024 invalid rate number, 2048
     * window starting in the future, 4096 not exactly eleven fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function requestPlanBatch(array $records): array
    {
        return self::usesW62RustFfi() ? self::requestPlanBatchNative($records) : self::requestPlanBatchPhp($records);
    }

    /** Wave 62.5 endpoints, external-id sources and image sizes in code order (1-based). */
    private const array W62_ENDPOINTS = ['search_movie', 'movie', 'find', 'search_tv', 'tv', 'tv_season', 'tv_episode', 'credits', 'external_ids', 'alternative_titles'];

    private const array W62_SOURCES = ['imdb_id', 'tvdb_id', 'tmdb'];

    private const array W62_IMAGE_SIZES = ['w92', 'w154', 'w185', 'w300', 'w342', 'w500', 'w780', 'w1280', 'h632', 'original'];

    /** `en-US` as `lang * 1000 + region`. */
    private const int W62_DEFAULT_LANGUAGE = 118_539;

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function requestPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 11 ? 0 : 4096;
            $endpoint = self::w62Code($fields[0], self::W62_ENDPOINTS, 2, $flags);
            if ($endpoint === 0) {
                $flags |= 1;
            }
            $source = self::w62Code($fields[2] ?? '', self::W62_SOURCES, 4, $flags);
            $id = trim($fields[1] ?? '');
            $idValue = 0;
            if ($id === '') {
                if ($endpoint === 2 || $endpoint === 3 || $endpoint >= 5) {
                    $flags |= 16;
                }
            } else {
                $value = $source === 1
                    ? (\strlen($id) >= 8 && strncasecmp($id, 'tt', 2) === 0 ? self::w62Digits(substr($id, 2), 10) : null)
                    : self::w62Digits($id, 10);
                if ($value !== null && $value > 0) {
                    $idValue = $value;
                } else {
                    $flags |= 8;
                }
            }
            $words = preg_split('/[ \t\n\r\x0b\x0c]+/', $fields[3] ?? '', -1, PREG_SPLIT_NO_EMPTY);
            $words = \is_array($words) ? $words : [];
            if (($endpoint === 1 || $endpoint === 4) && $words === []) {
                $flags |= 32;
            }
            $year = 0;
            $text = trim($fields[4] ?? '');
            if ($text !== '') {
                $value = self::w62Digits($text, 4);
                if ($value !== null && \strlen($text) === 4 && $value >= 1874 && $value <= 2100) {
                    $year = $value;
                } else {
                    $flags |= 64;
                }
            }
            $language = self::W62_DEFAULT_LANGUAGE;
            $text = trim($fields[5] ?? '');
            if ($text !== '') {
                if (preg_match('/^([A-Za-z]{2})(?:[-_]([A-Za-z]{2}))?$/D', $text, $match) === 1) {
                    $language = self::w62Letters($match[1]) * 1000 + (isset($match[2]) ? self::w62Letters($match[2]) : 0);
                } else {
                    $flags |= 128;
                }
            }
            $path = trim($fields[6] ?? '');
            $imageKind = $path === '' ? 0 : (str_starts_with($path, 'https://') || str_starts_with($path, 'http://') ? 2 : ($path[0] === '/' ? 1 : 3));
            if ($imageKind !== 0 && (str_contains($path, '..') || preg_match('/[\x00-\x20\x7f]/', $path) === 1)) {
                $flags |= 256;
            }
            $size = self::w62Code($fields[7] ?? '', self::W62_IMAGE_SIZES, 512, $flags);
            $requests = self::w62Number($fields[8] ?? '', 6, 0, 1024, $flags);
            $window = self::w62Number($fields[9] ?? '', 12, 0, 1024, $flags);
            $now = self::w62Number($fields[10] ?? '', 12, 0, 1024, $flags);
            [$allowed, $wait] = [1, 0];
            if ($window !== 0) {
                $elapsed = $now - $window;
                if ($now < $window) {
                    $flags |= 2048;
                    $elapsed = 0;
                }
                if ($elapsed < 10 && $requests >= 40) {
                    [$allowed, $wait] = [0, 10 - $elapsed];
                }
            }
            array_push($out, $endpoint, $idValue, $source, $words === [] ? 0 : \strlen(implode(' ', $words)), $year, $language, $imageKind, $size > 0 ? $size : 6, $allowed, $wait, $flags);
        }

        return $out;
    }

    /** 1-$max ASCII digits as an integer, else null. */
    private static function w62Digits(string $text, int $max): ?int
    {
        return $text !== '' && \strlen($text) <= $max && ctype_digit($text) ? (int) $text : null;
    }

    /**
     * Trimmed, ASCII case-insensitive 1-based position of $field in the lowercase $names; 0 empty, -1 unknown
     * (sets $flag).
     *
     * @param  list<string>  $names
     */
    private static function w62Code(string $field, array $names, int $flag, int &$flags): int
    {
        $field = trim($field);
        if ($field === '') {
            return 0;
        }
        $at = array_search(strtolower($field), $names, true);
        if ($at === false) {
            $flags |= $flag;

            return -1;
        }

        return (int) $at + 1;
    }

    /** Trimmed 1-$max digits (empty $default); anything else sets $flag and yields $default. */
    private static function w62Number(string $field, int $max, int $default, int $flag, int &$flags): int
    {
        $field = trim($field);
        if ($field === '') {
            return $default;
        }
        $value = self::w62Digits($field, $max);
        if ($value === null) {
            $flags |= $flag;

            return $default;
        }

        return $value;
    }

    /** `(a-z) * 26 + (a-z) + 1` for two ASCII letters (any case). */
    private static function w62Letters(string $pair): int
    {
        $pair = strtolower($pair);

        return (\ord($pair[0]) - 97) * 26 + (\ord($pair[1]) - 97) + 1;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function requestPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w62Words($count, implode("\0", $records), 11 * $count) ?? self::requestPlanBatchPhp($records);
    }
}
