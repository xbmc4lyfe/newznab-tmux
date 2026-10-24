<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * FanartTV Service
 *
 * A modern service wrapper for the Fanart.TV API.
 * Provides methods to fetch fan art for movies and TV shows.
 *
 * API Documentation: https://fanarttv.docs.apiary.io/
 */
class FanartTvService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_fanart_normalize_imdb_id(const char *ptr, size_t len, char *out, size_t cap);
        ssize_t nntmux_fanart_parse_movie_properties(const char *ser_ptr, size_t ser_len, const char *imdb_ptr, size_t imdb_len, char *out, size_t cap);
        ssize_t nntmux_fanart_select_best_poster(const char *ser_ptr, size_t ser_len, char *out, size_t cap);
        C;

    protected const BASE_URL = 'https://webservice.fanart.tv/v3';

    protected const CACHE_TTL_HOURS = 24;

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

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (string) config('nntmux_api.fanarttv_api_key', '');
        $this->timeout = (int) config('nntmux_api.fanarttv_timeout', 30);
        $this->retryTimes = (int) config('nntmux_api.fanarttv_retry_times', 3);
        $this->retryDelay = (int) config('nntmux_api.fanarttv_retry_delay', 100);
    }

    /**
     * Check if the API key is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Make a GET request to the Fanart.TV API.
     *
     * @param  string  $endpoint  The API endpoint
     * @return array<string, mixed>|null Response data or null on failure
     */
    protected function get(string $endpoint): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $url = self::BASE_URL.'/'.$endpoint;

        try {
            $response = Http::timeout($this->timeout)
                ->retry($this->retryTimes, $this->retryDelay)
                ->withQueryParameters(['api_key' => $this->apiKey])
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();

                // Check for API error responses
                if (isset($data['status']) && $data['status'] === 'error') {
                    Log::debug('FanartTV API returned error status', [
                        'endpoint' => $endpoint,
                        'message' => $data['error message'] ?? 'Unknown error',
                    ]);

                    return null;
                }

                return $data;
            }

            // Handle specific error codes
            if ($response->status() === 404) {
                Log::debug('FanartTV: Resource not found', ['endpoint' => $endpoint]);

                return null;
            }

            Log::warning('FanartTV API request failed', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('FanartTV API request exception', [
                'endpoint' => $endpoint,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get fan art for a movie by IMDB ID.
     *
     * @param  string  $imdbId  IMDB ID (with or without 'tt' prefix)
     * @return array<string, mixed>|null Movie art data or null on failure
     */
    public function getMovieFanArt(string $imdbId): ?array
    {
        // Ensure the ID has the 'tt' prefix
        $id = $this->normalizeImdbId($imdbId);

        $cacheKey = 'fanarttv_movie_'.$id;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($id) {
            return $this->get('movies/'.$id);
        });
    }

    /**
     * Get fan art for a TV show by TVDB ID.
     *
     * @param  int|string  $tvdbId  TVDB ID
     * @return array<string, mixed>|null TV show art data or null on failure
     */
    public function getTvFanArt(int|string $tvdbId): ?array
    {
        $cacheKey = 'fanarttv_tv_'.$tvdbId;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($tvdbId) {
            return $this->get('tv/'.$tvdbId);
        });
    }

    /**
     * Get fan art for music by MusicBrainz ID.
     *
     * @param  string  $mbId  MusicBrainz ID
     * @return array<string, mixed>|null Music art data or null on failure
     */
    public function getMusicFanArt(string $mbId): ?array
    {
        $cacheKey = 'fanarttv_music_'.$mbId;

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($mbId) {
            return $this->get('music/'.$mbId);
        });
    }

    /**
     * Extract movie properties from Fanart.TV response.
     *
     * @param  string  $imdbId  IMDB ID (with or without 'tt' prefix)
     * @return array<string, mixed>|null Array with 'cover', 'backdrop', 'banner', 'title' keys or null on failure
     */
    public function getMovieProperties(string $imdbId): ?array
    {
        $art = $this->getMovieFanArt($imdbId);

        if (empty($art)) {
            return null;
        }

        return $this->parseMovieProperties($art, $imdbId);
    }

    /**
     * @param  array<string, mixed>  $art
     * @return array<string, mixed>|null
     */
    public function parseMovieProperties(array $art, string $imdbId = ''): ?array
    {
        return (self::$useRustFfi ?? false)
            ? $this->parseMoviePropertiesNative($art, $imdbId)
            : $this->parseMoviePropertiesPhp($art, $imdbId);
    }

    /**
     * @param  array<string, mixed>  $art
     * @return array<string, mixed>|null
     */
    public function parseMoviePropertiesNative(array $art, string $imdbId = ''): ?array
    {
        if (empty($art)) {
            return null;
        }
        if (($ffi = self::ffi()) === null) {
            return $this->parseMoviePropertiesPhp($art, $imdbId);
        }
        try {
            $ser = @serialize($art);
            $serLen = strlen($ser);
            $imdbLen = strlen($imdbId);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(512, $serLen + $imdbLen + 64), $capacity);
            if ($buffer === null) {
                return $this->parseMoviePropertiesPhp($art, $imdbId);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fanart_parse_movie_properties($ser, $serLen, $imdbId, $imdbLen, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->parseMoviePropertiesPhp($art, $imdbId);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_fanart_parse_movie_properties($ser, $serLen, $imdbId, $imdbLen, $buffer, $capacity);
            }
            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                if ($written === 0) {
                    return null;
                }
                $parts = explode("\0", FFI::string($buffer, $written));
                if (count($parts) >= 5) {
                    $result = [
                        'backdrop' => $parts[0],
                        'cover' => $parts[1],
                    ];
                    if ($parts[2] === '1') {
                        $result['banner'] = $parts[3];
                    }
                    $result['title'] = $parts[4];

                    return $result;
                }
            }
        } catch (Throwable) {
        }

        return $this->parseMoviePropertiesPhp($art, $imdbId);
    }

    /**
     * @param  array<string, mixed>  $art
     * @return array<string, mixed>|null
     */
    public function parseMoviePropertiesPhp(array $art, string $imdbId = ''): ?array
    {
        if (empty($art)) {
            return null;
        }

        $result = [];

        // Get backdrop (preferring moviebackground, falling back to moviethumb)
        if (! empty($art['moviebackground'][0]['url'])) {
            $result['backdrop'] = $art['moviebackground'][0]['url'];
        } elseif (! empty($art['moviethumb'][0]['url'])) {
            $result['backdrop'] = $art['moviethumb'][0]['url'];
        }

        // Get cover/poster
        if (! empty($art['movieposter'][0]['url'])) {
            $result['cover'] = $art['movieposter'][0]['url'];
        }

        // Get banner
        if (! empty($art['moviebanner'][0]['url'])) {
            $result['banner'] = $art['moviebanner'][0]['url'];
        }

        // Only return if we have both backdrop and cover
        if (isset($result['backdrop'], $result['cover'])) {
            $result['title'] = $art['name'] ?? $imdbId;

            return $result;
        }

        return null;
    }

    /**
     * Extract TV show properties from Fanart.TV response.
     *
     * @param  int|string  $tvdbId  TVDB ID
     * @return array<string, mixed>|null Array with 'poster', 'banner', 'background', 'title' keys or null on failure
     */
    public function getTvProperties(int|string $tvdbId): ?array
    {
        $art = $this->getTvFanArt($tvdbId);

        if (empty($art)) {
            return null;
        }

        $result = [];

        // Get poster (sort by likes)
        if (! empty($art['tvposter'])) {
            /** @phpstan-ignore argument.templateType */
            $best = collect($art['tvposter'])->sortByDesc('likes')->first();
            if (! empty($best['url'])) {
                $result['poster'] = $best['url'];
            }
        }

        // Get banner (sort by likes)
        if (! empty($art['tvbanner'])) {
            /** @phpstan-ignore argument.templateType */
            $best = collect($art['tvbanner'])->sortByDesc('likes')->first();
            if (! empty($best['url'])) {
                $result['banner'] = $best['url'];
            }
        }

        // Get background (sort by likes)
        if (! empty($art['showbackground'])) {
            /** @phpstan-ignore argument.templateType */
            $best = collect($art['showbackground'])->sortByDesc('likes')->first();
            if (! empty($best['url'])) {
                $result['background'] = $best['url'];
            }
        }

        // Get HD clearlogo (sort by likes)
        if (! empty($art['hdtvlogo'])) {
            /** @phpstan-ignore argument.templateType */
            $best = collect($art['hdtvlogo'])->sortByDesc('likes')->first();
            if (! empty($best['url'])) {
                $result['logo'] = $best['url'];
            }
        }

        if (! empty($result)) {
            $result['title'] = $art['name'] ?? (string) $tvdbId;

            return $result;
        }

        return null;
    }

    /**
     * Get the best poster URL for a TV show.
     *
     * @param  int|string  $tvdbId  TVDB ID
     * @return string|null Poster URL or null if not found
     */
    public function getBestTvPoster(int|string $tvdbId): ?string
    {
        $art = $this->getTvFanArt($tvdbId);

        if (empty($art['tvposter'])) {
            return null;
        }

        if ((self::$useRustFfi ?? false) && is_array($art['tvposter'])) {
            return $this->selectBestPosterUrlNative($art['tvposter']);
        }

        /** @phpstan-ignore argument.templateType */
        $best = collect($art['tvposter'])->sortByDesc('likes')->first();

        return $best['url'] ?? null;
    }

    /**
     * Get the best poster URL for a movie.
     *
     * @param  string  $imdbId  IMDB ID (with or without 'tt' prefix)
     * @return string|null Poster URL or null if not found
     */
    public function getBestMoviePoster(string $imdbId): ?string
    {
        $art = $this->getMovieFanArt($imdbId);

        if (empty($art['movieposter'])) {
            return null;
        }

        if ((self::$useRustFfi ?? false) && is_array($art['movieposter'])) {
            return $this->selectBestPosterUrlNative($art['movieposter']);
        }

        /** @phpstan-ignore argument.templateType */
        $best = collect($art['movieposter'])->sortByDesc('likes')->first();

        return $best['url'] ?? null;
    }

    /**
     * Select the poster URL with the highest like count from a list of Fanart.TV poster entries.
     *
     * @param  array<int|string, mixed>  $posters
     */
    public function selectBestPosterUrl(array $posters): ?string
    {
        return (self::$useRustFfi ?? false)
            ? $this->selectBestPosterUrlNative($posters)
            : $this->selectBestPosterUrlPhp($posters);
    }

    /**
     * @param  array<int|string, mixed>  $posters
     */
    public function selectBestPosterUrlNative(array $posters): ?string
    {
        if (empty($posters)) {
            return null;
        }
        if (($ffi = self::ffi()) === null) {
            return $this->selectBestPosterUrlPhp($posters);
        }
        try {
            $ser = @serialize($posters);
            $serLen = strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $serLen + 32), $capacity);
            if ($buffer === null) {
                return $this->selectBestPosterUrlPhp($posters);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fanart_select_best_poster($ser, $serLen, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->selectBestPosterUrlPhp($posters);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_fanart_select_best_poster($ser, $serLen, $buffer, $capacity);
            }
            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                if ($written === 0) {
                    return null;
                }
                $raw = FFI::string($buffer, $written);
                if ($raw[0] === '1') {
                    return substr($raw, 1);
                }
            }
        } catch (Throwable) {
        }

        return $this->selectBestPosterUrlPhp($posters);
    }

    /**
     * @param  array<int|string, mixed>  $posters
     */
    public function selectBestPosterUrlPhp(array $posters): ?string
    {
        if (empty($posters)) {
            return null;
        }

        usort($posters, static function (mixed $a, mixed $b): int {
            $likesA = is_array($a) ? (int) ($a['likes'] ?? 0) : 0;
            $likesB = is_array($b) ? (int) ($b['likes'] ?? 0) : 0;

            return $likesB <=> $likesA;
        });

        $best = reset($posters);

        return is_array($best) && isset($best['url']) && is_string($best['url']) ? $best['url'] : null;
    }

    /**
     * Normalize IMDB ID to include 'tt' prefix.
     */
    public function normalizeImdbId(string $imdbId): string
    {
        return (self::$useRustFfi ?? false)
            ? $this->normalizeImdbIdNative($imdbId)
            : $this->normalizeImdbIdPhp($imdbId);
    }

    public function normalizeImdbIdNative(string $imdbId): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->normalizeImdbIdPhp($imdbId);
        }
        try {
            $len = strlen($imdbId);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 16), $capacity);
            if ($buffer === null) {
                return $this->normalizeImdbIdPhp($imdbId);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_fanart_normalize_imdb_id($imdbId, $len, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->normalizeImdbIdPhp($imdbId);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_fanart_normalize_imdb_id($imdbId, $len, $buffer, $capacity);
            }
            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                return $written === 0 ? '' : FFI::string($buffer, $written);
            }
        } catch (Throwable) {
        }

        return $this->normalizeImdbIdPhp($imdbId);
    }

    public function normalizeImdbIdPhp(string $imdbId): string
    {
        if (str_starts_with(strtolower($imdbId), 'tt')) {
            return $imdbId;
        }

        return 'tt'.$imdbId;
    }

    /**
     * Clear cached data for a specific movie.
     */
    public function clearMovieCache(string $imdbId): bool
    {
        $id = $this->normalizeImdbId($imdbId);

        return Cache::forget('fanarttv_movie_'.$id);
    }

    /**
     * Clear cached data for a specific TV show.
     */
    public function clearTvCache(int|string $tvdbId): bool
    {
        return Cache::forget('fanarttv_tv_'.$tvdbId);
    }
}
