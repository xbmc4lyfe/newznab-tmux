<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TvProviderException;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Trakt.tv Service
 *
 * A modern service wrapper for the Trakt.tv API.
 * Provides methods to fetch movie and TV show information.
 *
 * API Documentation: https://docs.trakt.tv/
 *
 * This wrapper intentionally uses public metadata endpoints only. OAuth should
 * only be added if a future feature needs user-scoped Trakt data such as
 * watchlists, watched history, private lists, ratings, or sync endpoints.
 */
class TraktService
{
    protected const BASE_URL = 'https://api.trakt.tv';

    protected const CACHE_TTL_HOURS = 24;

    protected const API_VERSION = 2;

    protected const ID_TYPES = ['imdb', 'tmdb', 'trakt', 'tvdb'];

    protected const SEARCH_TYPES = ['movie', 'show', 'episode', 'person', 'list'];

    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_trakt_image_url(const char *url, size_t url_len, char *out, size_t cap);
        ssize_t nntmux_trakt_format_imdb_id(const char *id, size_t id_len, char *out, size_t cap);
        ssize_t nntmux_trakt_format_media_id(const char *id, size_t id_len, char *out, size_t cap);
        int32_t nntmux_trakt_usable_external_id(const char *id, size_t id_len, uint32_t imdb);
        C;

    private static ?FFI $traktFfi = null;

    private static bool $traktFfiResolved = false;

    /**
     * Id helpers through Rust; null selects the benchmark-qualified production defaults: Rust for
     * the formatMediaId() slug path only, PHP for the helpers that miss the 1.5x gate
     * (see tests/Unit/RustTvProvidersTraktMatchingParityTest.php).
     */
    public ?bool $useRustFfi = null;

    /** Minimum seconds between requests to avoid rate limiting (Trakt/Cloudflare). */
    protected const THROTTLE_SECONDS = 1;

    /** Default backoff (seconds) when rate limited (429). */
    protected const RATE_LIMIT_BACKOFF_SECONDS = 60;

    protected string $clientId;

    protected int $timeout;

    protected int $retryTimes;

    protected int $retryDelay;

    /**
     * Throw TvProviderException on rate limits, server errors and connection failures instead of returning null.
     */
    protected bool $throwOnTransientFailure = false;

    public function __construct(?string $clientId = null)
    {
        $this->clientId = trim($clientId ?? (string) config('nntmux_api.trakttv_api_key', ''));
        $this->timeout = (int) config('nntmux_api.trakttv_timeout', 30);
        $this->retryTimes = (int) config('nntmux_api.trakttv_retry_times', 3);
        $this->retryDelay = (int) config('nntmux_api.trakttv_retry_delay', 100);
    }

    /**
     * Throttle requests: ensure minimum delay since last Trakt API call (global across workers).
     */
    protected function throttle(): void
    {
        $key = 'trakt_last_request_at';
        $last = Cache::get($key);
        if ($last !== null) {
            $elapsed = microtime(true) - (float) $last;
            if ($elapsed < self::THROTTLE_SECONDS) {
                usleep((int) ((self::THROTTLE_SECONDS - $elapsed) * 1_000_000));
            }
        }
        Cache::put($key, microtime(true), 10);
    }

    /**
     * Check if the API key is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->clientId);
    }

    /**
     * A copy of this service that throws TvProviderException on transient failures. A 429 is
     * thrown at once with its Retry-After instead of sleeping the worker before one more try.
     */
    public function throwingOnTransientFailure(): static
    {
        $service = clone $this;
        $service->throwOnTransientFailure = true;

        return $service;
    }

    /**
     * Get the request headers for Trakt API.
     *
     * @return array<string, mixed>
     */
    protected function getHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'trakt-api-version' => (string) self::API_VERSION,
            'trakt-api-key' => $this->clientId,
        ];
    }

    /**
     * Make a GET request to the Trakt API.
     * Throttles requests, retries on transient failures, and on 429 (rate limit) backs off then retries once.
     *
     * @param  string  $endpoint  The API endpoint
     * @param  array<string, mixed>  $params  Query parameters
     * @param  bool  $isRetryAfterRateLimit  Internal: true when this is the single retry after a 429
     * @return array<string, mixed>|null Response data or null on failure
     */
    protected function get(string $endpoint, array $params = [], bool $isRetryAfterRateLimit = false): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $this->throttle();

        $url = self::BASE_URL.'/'.ltrim($endpoint, '/');

        try {
            $response = Http::timeout($this->timeout)
                ->retry($this->retryTimes, $this->retryDelay, function (Throwable $exception, PendingRequest $request, ?string $key = null) {
                    // Don't retry on 404 - resource doesn't exist
                    if ($exception instanceof RequestException) {
                        $status = $exception->response->status();
                        if ($status === 404) {
                            return false;
                        }
                        // Don't retry 429 with short delay - we handle it below with backoff
                        if ($status === 429) {
                            return false;
                        }
                    }

                    return true;
                }, throw: false)
                ->withHeaders($this->getHeaders())
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();

                // Check for API error responses
                if (isset($data['status']) && $data['status'] === 'failure') {
                    Log::debug('Trakt API returned failure status', [
                        'endpoint' => $endpoint,
                    ]);

                    return null;
                }

                return $data;
            }

            if ($this->throwOnTransientFailure && TvProviderException::isTransientStatus($response->status())) {
                throw new TvProviderException(
                    'trakt',
                    sprintf('Trakt returned %d for %s', $response->status(), $endpoint),
                    $response->status(),
                    $response->status() === 429 ? $this->getRateLimitBackoff($response) : null,
                );
            }

            // Handle 429 (rate limit / Cloudflare 1015): back off then retry once
            if ($response->status() === 429 && ! $isRetryAfterRateLimit) {
                $backoff = $this->getRateLimitBackoff($response);
                Log::warning('Trakt API rate limited (429), backing off then retrying once', [
                    'endpoint' => $endpoint,
                    'backoff_seconds' => $backoff,
                ]);
                sleep($backoff);

                return $this->get($endpoint, $params, true);
            }

            // Handle 404 - normal (resource not found)
            if ($response->status() === 404) {
                Log::debug('Trakt: Resource not found', ['endpoint' => $endpoint]);

                return null;
            }

            Log::warning('Trakt API request failed', [
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
                Log::debug('Trakt: Resource not found', ['endpoint' => $endpoint]);

                return null;
            }

            if ($this->throwOnTransientFailure) {
                throw new TvProviderException('trakt', 'Trakt request failed: '.$e->getMessage(), null, null, $e);
            }

            Log::warning('Trakt API request exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get backoff seconds from 429 response (Retry-After header or default).
     */
    protected function getRateLimitBackoff(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            $seconds = (int) $retryAfter;

            return min(max($seconds, 1), 300);
        }

        return self::RATE_LIMIT_BACKOFF_SECONDS;
    }

    /**
     * Get episode summary using multiple ID types for fallback.
     *
     * @param  int|string  $showId  The show ID (Trakt, IMDB, TMDB, or TVDB)
     * @param  int|string  $season  The season number
     * @param  int|string  $episode  The episode number
     * @param  string  $extended  Extended info level: 'min', 'full', 'aliases', 'full,aliases'
     * @param  string  $idType  The ID type: 'trakt', 'imdb', 'tmdb', 'tvdb' (default: 'trakt')
     * @return array<string, mixed>|null Episode data or null on failure
     */
    public function getEpisodeSummary(
        int|string $showId,
        int|string $season,
        int|string $episode,
        string $extended = 'min',
        string $idType = 'trakt'
    ): ?array {
        // Validate parameters to avoid unnecessary API calls with invalid IDs
        $showIdInt = is_numeric($showId) ? (int) $showId : 0;
        $seasonInt = is_numeric($season) ? (int) $season : -1;
        $episodeInt = is_numeric($episode) ? (int) $episode : -1;

        // For numeric show IDs, validate they are positive
        // Season and episode must be non-negative (season 0 can be specials)
        if (($showIdInt <= 0 && is_numeric($showId)) || $seasonInt < 0 || $episodeInt < 0) {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full', 'aliases', 'full,aliases']);

        // Format the show ID based on type for cache key and API call
        $formattedId = $this->formatShowIdForApi($showId, $idType);
        if ($formattedId === null) {
            return null;
        }

        $cacheKey = "trakt_episode_{$idType}_{$formattedId}_{$season}_{$episode}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($formattedId, $season, $episode, $extended) {
            return $this->get("shows/{$formattedId}/seasons/{$season}/episodes/{$episode}", [
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Get episode summary with fallback to multiple ID types.
     * Tries Trakt ID first, then falls back to TMDB, TVDB, and IMDB.
     *
     * @param  array<string, mixed>  $ids  Array of IDs: ['trakt' => X, 'tmdb' => Y, 'tvdb' => Z, 'imdb' => W]
     * @param  int|string  $season  The season number
     * @param  int|string  $episode  The episode number
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Episode data or null on failure
     */
    public function getEpisodeSummaryWithFallback(
        array $ids,
        int|string $season,
        int|string $episode,
        string $extended = 'min'
    ): ?array {
        // Priority order for ID types
        $idPriority = ['trakt', 'tmdb', 'tvdb', 'imdb'];

        foreach ($idPriority as $idType) {
            $id = $ids[$idType] ?? null;
            if ($this->isUsableExternalId($id, $idType)) {
                $result = $this->getEpisodeSummary($id, $season, $episode, $extended, $idType);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Format show ID for Trakt API based on ID type.
     * Trakt API accepts different ID formats for lookups.
     *
     * @param  int|string  $id  The show ID
     * @param  string  $idType  The ID type: 'trakt', 'imdb', 'tmdb', 'tvdb'
     * @return string|null Formatted ID for API call or null if invalid
     */
    protected function formatShowIdForApi(int|string $id, string $idType): ?string
    {
        if (! in_array($idType, self::ID_TYPES, true)) {
            return null;
        }

        $formattedId = trim((string) $id);
        if ($formattedId === '') {
            return null;
        }

        if ($idType === 'imdb') {
            return $this->formatImdbId($formattedId);
        }

        return $formattedId;
    }

    /**
     * Get show summary with all external IDs.
     * This enriches the response with IDs from all supported providers.
     *
     * @param  int|string  $showId  Show ID
     * @param  string  $idType  The ID type: 'trakt', 'imdb', 'tmdb', 'tvdb'
     * @return array<string, mixed>|null Show data with all IDs or null on failure
     */
    public function getShowWithAllIds(int|string $showId, string $idType = 'trakt'): ?array
    {
        $formattedId = $this->formatShowIdForApi($showId, $idType);
        if ($formattedId === null) {
            return null;
        }

        $cacheKey = "trakt_show_ids_{$idType}_{$showId}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($formattedId) {
            return $this->get("shows/{$formattedId}", ['extended' => 'full']);
        });
    }

    /**
     * Look up a show by external ID and return all available IDs.
     * Useful for cross-referencing between TMDB, TVDB, Trakt, and IMDB.
     *
     * @param  int|string  $id  The external ID
     * @param  string  $idType  The ID type: 'imdb', 'tmdb', 'tvdb'
     * @return array<string, mixed>|null Array with all IDs: ['trakt' => X, 'imdb' => Y, 'tmdb' => Z, 'tvdb' => W] or null
     */
    public function lookupShowIds(int|string $id, string $idType = 'tmdb'): ?array
    {
        if (! in_array($idType, self::ID_TYPES, true)) {
            return null;
        }

        // Use the search endpoint to find the show by external ID
        $results = $this->searchById($id, $idType, 'show');

        if (empty($results)) {
            return null;
        }

        // Get the first matching show
        $show = $results[0]['show'] ?? null;
        if ($show === null || ! isset($show['ids'])) {
            return null;
        }

        return [
            'trakt' => $show['ids']['trakt'] ?? 0,
            'imdb' => $show['ids']['imdb'] ?? null,
            'tmdb' => $show['ids']['tmdb'] ?? 0,
            'tvdb' => $show['ids']['tvdb'] ?? 0,
            'tvrage' => $show['ids']['tvrage'] ?? 0,
            'slug' => $show['ids']['slug'] ?? '',
        ];
    }

    /**
     * Get current box office movies.
     *
     * @return array<string, mixed>|null Box office data or null on failure
     */
    public function getBoxOffice(): ?array
    {
        $cacheKey = 'trakt_boxoffice_'.date('Y-m-d');

        return Cache::remember($cacheKey, now()->addHours(6), function () {
            return $this->get('movies/boxoffice');
        });
    }

    /**
     * Get TV show calendar.
     *
     * @param  string  $startDate  Start date (YYYY-MM-DD format, defaults to today)
     * @param  int  $days  Number of days to retrieve (default: 7)
     * @return array<string, mixed>|null Calendar data or null on failure
     */
    public function getCalendar(string $startDate = '', int $days = 7): ?array
    {
        if (empty($startDate)) {
            $startDate = date('Y-m-d');
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return null;
        }

        $days = max(1, min($days, 33));

        $cacheKey = "trakt_calendar_{$startDate}_{$days}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($startDate, $days) {
            return $this->get("calendars/all/shows/{$startDate}/{$days}");
        });
    }

    /**
     * Get movie summary.
     *
     * @param  string  $movie  Movie slug or ID
     * @param  string  $extended  Extended info level: 'min' or 'full'
     * @return array<string, mixed>|null Movie data or null on failure
     */
    public function getMovieSummary(string $movie, string $extended = 'min'): ?array
    {
        if (empty($movie)) {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $movieId = $this->formatMediaId($movie);
        if ($movieId === null) {
            return null;
        }

        $cacheKey = 'trakt_movie_'.md5($movieId).'_'.$extended;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($movieId, $extended) {
            return $this->get("movies/{$movieId}", ['extended' => $extended]);
        });
    }

    /**
     * Get IMDB ID for a movie.
     *
     * @param  string  $movie  Movie slug or ID
     * @return string|null IMDB ID or null on failure
     */
    public function getMovieImdbId(string $movie): ?string
    {
        $data = $this->getMovieSummary($movie, 'min');

        return $data['ids']['imdb'] ?? null;
    }

    /**
     * Search by external ID.
     *
     * @param  int|string  $id  The ID to search for
     * @param  string  $idType  The ID type: 'imdb', 'tmdb', 'trakt', 'tvdb'
     * @param  string  $mediaType  Media type: 'movie', 'show', 'episode', or empty for all
     * @return array<string, mixed>|null Search results or null on failure
     */
    public function searchById(int|string $id, string $idType = 'trakt', string $mediaType = ''): ?array
    {
        if (! in_array($idType, self::ID_TYPES, true)) {
            Log::warning('Trakt: Invalid ID type', ['idType' => $idType]);

            return null;
        }

        $formattedId = trim((string) $id);
        if ($formattedId === '') {
            return null;
        }

        if ($idType === 'imdb') {
            $formattedId = $this->formatImdbId($formattedId);
            if ($formattedId === null) {
                return null;
            }
        }

        $params = [
            'id_type' => $idType,
            'id' => $formattedId,
        ];

        if ($mediaType !== '' && ! in_array($mediaType, self::SEARCH_TYPES, true)) {
            return null;
        }

        if ($mediaType !== '') {
            $params['type'] = $mediaType;
        }

        $cacheKey = 'trakt_search_'.$idType.'_'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $formattedId).'_'.($params['type'] ?? 'all');

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($formattedId, $idType, $params) {
            return $this->get('search/'.$idType.'/'.$formattedId, array_intersect_key($params, ['type' => true]));
        });
    }

    /**
     * Search for a show by name.
     *
     * @param  string  $query  Search query
     * @param  string  $type  Search type: 'show', 'movie', 'episode', 'person', 'list'
     * @return array<string, mixed>|null Search results or null on failure
     */
    public function searchShows(string $query, string $type = 'show'): ?array
    {
        $query = trim($query);
        if ($query === '' || ! in_array($type, self::SEARCH_TYPES, true)) {
            return null;
        }

        $slug = Str::slug($query);
        $cacheKey = "trakt_search_{$type}_{$slug}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($query, $type) {
            return $this->get('search/'.$type, ['query' => $query]);
        });
    }

    /**
     * Get show summary.
     *
     * @param  int|string  $show  Show slug or ID
     * @param  string  $extended  Extended info level: 'min' or 'full'
     * @return array<string, mixed>|null Show data or null on failure
     */
    public function getShowSummary(int|string $show, string $extended = 'full'): ?array
    {
        $showKey = trim((string) $show);

        if ($showKey === '') {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $showId = $this->formatMediaId($showKey);
        if ($showId === null) {
            return null;
        }

        $cacheKey = 'trakt_show_'.md5($showId).'_'.$extended;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($showId, $extended) {
            return $this->get("shows/{$showId}", ['extended' => $extended]);
        });
    }

    /**
     * Get show seasons.
     *
     * @param  string  $show  Show slug or ID
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Seasons data or null on failure
     */
    public function getShowSeasons(string $show, string $extended = 'full'): ?array
    {
        $showId = $this->formatMediaId($show);
        if ($showId === null) {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full', 'episodes', 'full,episodes']);
        $cacheKey = 'trakt_seasons_'.md5($showId).'_'.$extended;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($showId, $extended) {
            return $this->get("shows/{$showId}/seasons", ['extended' => $extended]);
        });
    }

    /**
     * Get season episodes.
     *
     * @param  string  $show  Show slug or ID
     * @param  int  $season  Season number
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Episodes data or null on failure
     */
    public function getSeasonEpisodes(string $show, int $season, string $extended = 'full'): ?array
    {
        $showId = $this->formatMediaId($show);
        if ($showId === null || $season < 0) {
            return null;
        }

        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = 'trakt_season_episodes_'.md5($showId)."_{$season}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($showId, $season, $extended) {
            return $this->get("shows/{$showId}/seasons/{$season}", ['extended' => $extended]);
        });
    }

    /**
     * Get trending shows.
     *
     * @param  int  $limit  Number of results to return
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Trending shows or null on failure
     */
    public function getTrendingShows(int $limit = 10, string $extended = 'full'): ?array
    {
        $limit = $this->normalizeLimit($limit);
        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = "trakt_trending_shows_{$limit}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(1), function () use ($limit, $extended) {
            return $this->get('shows/trending', [
                'limit' => $limit,
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Get trending movies.
     *
     * @param  int  $limit  Number of results to return
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Trending movies or null on failure
     */
    public function getTrendingMovies(int $limit = 10, string $extended = 'full'): ?array
    {
        $limit = $this->normalizeLimit($limit);
        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = "trakt_trending_movies_{$limit}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(1), function () use ($limit, $extended) {
            return $this->get('movies/trending', [
                'limit' => $limit,
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Get popular shows.
     *
     * @param  int  $limit  Number of results to return
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Popular shows or null on failure
     */
    public function getPopularShows(int $limit = 10, string $extended = 'full'): ?array
    {
        $limit = $this->normalizeLimit($limit);
        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = "trakt_popular_shows_{$limit}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($limit, $extended) {
            return $this->get('shows/popular', [
                'limit' => $limit,
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Get popular movies.
     *
     * @param  int  $limit  Number of results to return
     * @param  string  $extended  Extended info level
     * @return array<string, mixed>|null Popular movies or null on failure
     */
    public function getPopularMovies(int $limit = 10, string $extended = 'full'): ?array
    {
        $limit = $this->normalizeLimit($limit);
        $extended = $this->normalizeExtended($extended, ['min', 'full']);
        $cacheKey = "trakt_popular_movies_{$limit}_{$extended}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($limit, $extended) {
            return $this->get('movies/popular', [
                'limit' => $limit,
                'extended' => $extended,
            ]);
        });
    }

    /**
     * Clear cached data for a specific show.
     */
    public function clearShowCache(string $show): void
    {
        $slug = Str::slug($show);
        Cache::forget("trakt_show_{$slug}_min");
        Cache::forget("trakt_show_{$slug}_full");
        Cache::forget("trakt_seasons_{$slug}_full");
        Cache::forget("trakt_seasons_{$slug}_min");

        $showId = $this->formatMediaId($show);
        if ($showId !== null) {
            Cache::forget('trakt_show_'.md5($showId).'_min');
            Cache::forget('trakt_show_'.md5($showId).'_full');
            Cache::forget('trakt_seasons_'.md5($showId).'_min');
            Cache::forget('trakt_seasons_'.md5($showId).'_full');
            Cache::forget('trakt_seasons_'.md5($showId).'_episodes');
            Cache::forget('trakt_seasons_'.md5($showId).'_full,episodes');
        }
    }

    /**
     * Clear cached data for a specific movie.
     */
    public function clearMovieCache(string $movie): void
    {
        $slug = Str::slug($movie);
        Cache::forget("trakt_movie_{$slug}_min");
        Cache::forget("trakt_movie_{$slug}_full");

        $movieId = $this->formatMediaId($movie);
        if ($movieId !== null) {
            Cache::forget('trakt_movie_'.md5($movieId).'_min');
            Cache::forget('trakt_movie_'.md5($movieId).'_full');
        }
    }

    /**
     * Normalize IMDb IDs for Trakt endpoints without padding away meaningful
     * leading zeroes (for example, tt0137523 must stay tt0137523).
     */
    protected function formatImdbId(string $id): ?string
    {
        return ($this->useRustFfi ?? false) ? $this->formatImdbIdNative($id) : $this->formatImdbIdPhp($id);
    }

    public function formatImdbIdPhp(string $id): ?string
    {
        if (preg_match('/^tt\d{6,}$/i', $id) === 1) {
            return $id;
        }

        $digits = preg_replace('/\D/', '', $id);

        return $digits !== null && preg_match('/^\d{6,}$/', $digits) === 1 ? 'tt'.$digits : null;
    }

    /**
     * formatImdbIdPhp() through Rust `nntmux_trakt_format_imdb_id`.
     */
    public function formatImdbIdNative(string $id): ?string
    {
        $result = self::stringCall('nntmux_trakt_format_imdb_id', $id);

        return $result === false ? $this->formatImdbIdPhp($id) : $result;
    }

    protected function formatMediaId(int|string $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }
        if (str_starts_with(strtolower($id), 'tt')) {
            return $this->formatImdbId($id);
        }

        // Rust slugs printable ASCII (Trakt slugs and numeric ids); IMDb ids stay in PHP, where
        // the regex is faster than the FFI round trip.
        if (($this->useRustFfi ?? true) && \is_string($slug = self::stringCall('nntmux_trakt_format_media_id', $id))) {
            return $slug;
        }

        return Str::slug($id);
    }

    public function formatMediaIdPhp(int|string $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }

        return str_starts_with(strtolower($id), 'tt') ? $this->formatImdbId($id) : Str::slug($id);
    }

    /**
     * formatMediaIdPhp() through Rust `nntmux_trakt_format_media_id` (printable ASCII slugs and
     * IMDb ids); falls back to PHP when Rust declines.
     */
    public function formatMediaIdNative(int|string $id): ?string
    {
        $result = self::stringCall('nntmux_trakt_format_media_id', (string) $id);

        return $result === false ? $this->formatMediaIdPhp($id) : $result;
    }

    /**
     * The shared Rust library with the Trakt helper exports, or null when unavailable.
     */
    public static function traktFfi(): ?FFI
    {
        if (! self::$traktFfiResolved) {
            self::$traktFfi = RustPipelineLibrary::resolveDomain('trakt_matching', self::FFI_CDEF);
            self::$traktFfiResolved = true;
        }

        return self::$traktFfi;
    }

    /**
     * A string-returning Trakt export: its result, null for PHP `null`, or false to fall back.
     */
    private static function stringCall(string $function, string $input): string|false|null
    {
        $ffi = self::traktFfi();
        if ($ffi === null) {
            return false;
        }
        $len = \strlen($input);
        $cap = 0;
        // `@` expands to `-at-`: four output bytes per input byte at most.
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 4 * $len + 16, $cap);
        if ($scratch === null) {
            return false;
        }
        try {
            $written = $ffi->{$function}($input, $len, $scratch, $cap);
        } catch (Throwable) {
            return false;
        }
        if ($written === -2) {
            return null;
        }

        return \is_int($written) && $written >= 0 ? ($written === 0 ? '' : FFI::string($scratch, $written)) : false;
    }

    /**
     * @param  list<string>  $allowed
     */
    protected function normalizeExtended(string $extended, array $allowed): string
    {
        return in_array($extended, $allowed, true) ? $extended : $allowed[0];
    }

    protected function normalizeLimit(int $limit): int
    {
        return max(1, min($limit, 100));
    }

    protected function isUsableExternalId(mixed $id, string $idType): bool
    {
        return ($this->useRustFfi ?? false) ? $this->isUsableExternalIdNative($id, $idType) : $this->isUsableExternalIdPhp($id, $idType);
    }

    public function isUsableExternalIdPhp(mixed $id, string $idType): bool
    {
        if ($id === null || $id === '') {
            return false;
        }

        if ($idType === 'imdb') {
            return $this->formatImdbIdPhp((string) $id) !== null;
        }

        return is_numeric($id) ? (int) $id > 0 : trim((string) $id) !== '';
    }

    /**
     * isUsableExternalIdPhp() through Rust `nntmux_trakt_usable_external_id` for string ids.
     */
    public function isUsableExternalIdNative(mixed $id, string $idType): bool
    {
        if (\is_string($id) && ($ffi = self::traktFfi()) !== null) {
            try {
                // @phpstan-ignore method.notFound
                $usable = $ffi->nntmux_trakt_usable_external_id($id, \strlen($id), $idType === 'imdb' ? 1 : 0);
                if ($usable === 0 || $usable === 1) {
                    return $usable === 1;
                }
            } catch (Throwable) {
                // Older native libraries retain the PHP implementation.
            }
        }

        return $this->isUsableExternalIdPhp($id, $idType);
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 4.29x, p99 3.47x; uncommon mean 4.08x, p99 3.83x; see the W46 parity test). */
    public const bool W464_RUST_FFI_DEFAULT = true;

    public const string W464_FFI_CDEF = <<<'C'
        ssize_t nntmux_w464_trakt_media_id_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W464_SUMMARY_KEYS = ['total_entries', 'empty_count', 'imdb_prefixed_count', 'imdb_valid_count', 'numeric_count', 'positive_numeric_count', 'slug_ready_count', 'uppercase_count', 'whitespace_count', 'at_sign_count', 'non_ascii_count'];

    /** unpack() format for the eleven little-endian int64 counters, in W464_SUMMARY_KEYS order. */
    private const string W464_UNPACK = 'Ptotal_entries/Pempty_count/Pimdb_prefixed_count/Pimdb_valid_count/Pnumeric_count/Ppositive_numeric_count/Pslug_ready_count/Puppercase_count/Pwhitespace_count/Pat_sign_count/Pnon_ascii_count';

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
     * Summarize show/movie ids as formatMediaId() routes them (trimmed):
     * tt-prefixed ids and those formatImdbIdPhp() accepts, numeric and
     * positive numeric ids, ids already in Str::slug() form, and uppercase,
     * whitespace, @ and non-ASCII bytes the slugger rewrites. Entries come
     * from strings, integers, or the slug, imdb, trakt, tmdb, tvdb or id
     * field; other values count as empty. Byte classes are ASCII-only.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTraktMediaIdBatch(array $entries): array
    {
        return self::usesW464RustFfi()
            ? self::summarizeTraktMediaIdBatchNative($entries)
            : self::summarizeTraktMediaIdBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTraktMediaIdBatchNative(array $entries): array
    {
        return self::w464Call(self::w464Entries($entries)) ?? self::summarizeTraktMediaIdBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeTraktMediaIdBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W464_SUMMARY_KEYS, 0);
        foreach (self::w464Entries($entries) as $entry) {
            $summary['total_entries']++;
            $id = trim($entry);
            if ($id === '') {
                $summary['empty_count']++;

                continue;
            }
            $prefixed = strncasecmp($id, 'tt', 2) === 0;
            $numeric = preg_match('/\A[0-9]+\z/', $id) === 1;
            $summary['imdb_prefixed_count'] += (int) $prefixed;
            $summary['imdb_valid_count'] += (int) ($prefixed && strlen(preg_replace('/[^0-9]/', '', $id) ?? '') >= 6);
            $summary['numeric_count'] += (int) $numeric;
            $summary['positive_numeric_count'] += (int) ($numeric && preg_match('/[1-9]/', $id) === 1);
            $summary['slug_ready_count'] += (int) (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id) === 1);
            $summary['uppercase_count'] += (int) (preg_match('/[A-Z]/', $id) === 1);
            $summary['whitespace_count'] += (int) (preg_match('/[ \t\n\r\x0b\f]/', $id) === 1);
            $summary['at_sign_count'] += (int) str_contains($id, '@');
            $summary['non_ascii_count'] += (int) (preg_match('/[\x80-\xff]/', $id) === 1);
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
                $item = $item['slug'] ?? $item['imdb'] ?? $item['trakt'] ?? $item['tmdb'] ?? $item['tvdb'] ?? $item['id'] ?? null;
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
            if ($out === null || $ffi->nntmux_w464_trakt_media_id_batch($input, strlen($input), $out, 88) !== 88) {
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

    /** C signature of the Wave 62.5 {@see self::lookupPlanBatch()} helper. */
    private const string W62_TRAKT_PLAN_FFI_CDEF = 'int64_t nntmux_w625_trakt_lookup_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 62.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 6.92/5.60/6.64/5.75x); see tests/Unit/RustSteamTmdbClientTraktTurnstileReleaseSearchW62ParityTest.php.
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
                    $ffi = RustPipelineLibrary::resolveDomain('w62_5', self::W62_TRAKT_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
     * Runs $callback with lookupPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
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
            $written = $ffi->nntmux_w625_trakt_lookup_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch Trakt lookup plan over `media_type\tid\tid_type\tseason\tepisode\textended\tlimit`. Nine words per
     * record: `[media_code, id_type_code, id_value, slug_len, season, episode, extended_code, limit, flags]`. Media
     * follow SEARCH_TYPES (1 movie, 2 show, 3 episode, 4 person, 5 list) and id types ID_TYPES plus slug (1 imdb,
     * 2 tmdb, 3 trakt, 4 tvdb, 5 slug), trimmed and ASCII case-insensitive (0 empty, -1 unknown). An empty id type
     * is inferred from the trimmed id: imdb with an ASCII case-insensitive `tt` prefix, trakt for 1-18 digits,
     * else slug. IMDb ids keep all their digits (formatImdbIdPhp()) when there are 6-18; tmdb, trakt and tvdb ids
     * are 1-18 digits with a positive value; slugs join lowercased ASCII alphanumeric runs with `-` and report
     * that length. Season and episode are trimmed 1-4 digits (empty -1); episode lookups need a season and a
     * positive episode. Extended levels are 1 min, 2 full, 3 metadata, 4 episodes (empty or unknown min). The
     * limit (empty 10) is 1-6 digits clamped to 1..100 (normalizeLimit()). Flags: 1 empty id, 2 unknown media, 4
     * unknown id type, 8 invalid id, 16 invalid season or episode, 32 incomplete episode lookup, 64 unknown
     * extended, 128 clamped limit, 256 invalid limit, 512 non-ASCII id, 1024 not exactly seven fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function lookupPlanBatch(array $records): array
    {
        return self::usesW62RustFfi() ? self::lookupPlanBatchNative($records) : self::lookupPlanBatchPhp($records);
    }

    /** Wave 62.5 extended levels in code order (1-based). */
    private const array W62_EXTENDED = ['min', 'full', 'metadata', 'episodes'];

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function lookupPlanBatchPhp(array $records): array
    {
        $idTypes = [...self::ID_TYPES, 'slug'];
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 7 ? 0 : 1024;
            $media = self::w62Code($fields[0], self::SEARCH_TYPES, 2, $flags);
            $id = trim($fields[1] ?? '');
            if (preg_match('/[\x80-\xff]/', $id) === 1) {
                $flags |= 512;
            }
            $idType = self::w62Code($fields[2] ?? '', $idTypes, 4, $flags);
            if ($idType === 0 && $id !== '') {
                $idType = strncasecmp($id, 'tt', 2) === 0 ? 1 : (self::w62Digits($id, 18) !== null ? 3 : 5);
            }
            [$value, $slugLength] = [0, 0];
            if ($id === '') {
                $flags |= 1;
            } elseif ($idType === 1) {
                $digits = (string) preg_replace('/[^0-9]+/', '', $id);
                if (\strlen($digits) >= 6 && \strlen($digits) <= 18) {
                    $value = (int) $digits;
                } else {
                    $flags |= 8;
                }
            } elseif ($idType >= 2 && $idType <= 4) {
                $number = self::w62Digits($id, 18);
                if ($number !== null && $number > 0) {
                    $value = $number;
                } else {
                    $flags |= 8;
                }
            } elseif ($idType === 5) {
                $runs = preg_split('/[^A-Za-z0-9]+/', $id, -1, PREG_SPLIT_NO_EMPTY);
                $slugLength = \is_array($runs) && $runs !== [] ? \strlen(implode('-', $runs)) : 0;
                if ($slugLength === 0) {
                    $flags |= 8;
                }
            }
            $season = self::w62Number($fields[3] ?? '', 4, -1, 16, $flags);
            $episode = self::w62Number($fields[4] ?? '', 4, -1, 16, $flags);
            if ($media === 3 && ($season < 0 || $episode < 1)) {
                $flags |= 32;
            }
            $extended = self::w62Code($fields[5] ?? '', self::W62_EXTENDED, 64, $flags);
            $limit = self::w62Clamped($fields[6] ?? '', 6, 10, 1, 100, 128, 256, $flags);
            array_push($out, $media, $idType, $value, $slugLength, $season, $episode, $extended > 0 ? $extended : 1, $limit, $flags);
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

    /**
     * Trimmed 1-$max digits clamped to $low..$high (empty $default); clamping sets $clamped, other input sets
     * $invalid and yields $default.
     */
    private static function w62Clamped(string $field, int $max, int $default, int $low, int $high, int $clamped, int $invalid, int &$flags): int
    {
        $field = trim($field);
        if ($field === '') {
            return $default;
        }
        $value = self::w62Digits($field, $max);
        if ($value === null) {
            $flags |= $invalid;

            return $default;
        }
        if ($value < $low || $value > $high) {
            $flags |= $clamped;

            return max($low, min($high, $value));
        }

        return $value;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function lookupPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w62Words($count, implode("\0", $records), 9 * $count) ?? self::lookupPlanBatchPhp($records);
    }
}
