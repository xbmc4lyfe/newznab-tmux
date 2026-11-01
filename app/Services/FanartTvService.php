<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

    /** Disabled: common mean 1.29-1.45x, common p99 1.06-1.44x and uncommon mean 1.41-1.47x missed 1.5x in five paired runs; see the W43 parity test. */
    public const bool W434_RUST_FFI_DEFAULT = false;

    public const string W434_FFI_CDEF = <<<'C'
        ssize_t nntmux_w434_fanart_image_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W434_SUMMARY_KEYS = ['total_images', 'empty_url_count', 'https_count', 'fanart_host_count', 'jpg_count', 'png_count', 'preview_count', 'query_count'];

    private static ?bool $w434UseRustFfi = null;

    private static FFI|false|null $w434Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW434RustFfi(?bool $enable = true): void
    {
        self::$w434UseRustFfi = $enable;
    }

    public static function usesW434RustFfi(): bool
    {
        return self::$w434UseRustFfi ?? self::W434_RUST_FFI_DEFAULT;
    }

    public static function resetW434RustFfi(): void
    {
        self::$w434UseRustFfi = null;
        self::$w434Ffi = null;
    }

    /**
     * Summarize Fanart.TV artwork URLs: https scheme, fanart.tv hosts and
     * /preview/ paths (ASCII case-insensitive), jpg/jpeg or png text after the
     * last dot, and query strings. Entries come from strings, integers, or the
     * url array field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeFanartImageBatch(array $entries): array
    {
        return self::usesW434RustFfi()
            ? self::summarizeFanartImageBatchNative($entries)
            : self::summarizeFanartImageBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeFanartImageBatchNative(array $entries): array
    {
        return self::w434Call(self::w434Entries($entries)) ?? self::summarizeFanartImageBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeFanartImageBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W434_SUMMARY_KEYS, 0);
        foreach (self::w434Entries($entries) as $entry) {
            $summary['total_images']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_url_count']++;

                continue;
            }
            $dot = strrpos($entry, '.');
            $extension = $dot === false ? '' : strtolower(substr($entry, $dot + 1));
            $summary['https_count'] += (int) (strncasecmp($entry, 'https://', 8) === 0);
            $summary['fanart_host_count'] += (int) (stripos($entry, 'fanart.tv/') !== false);
            $summary['jpg_count'] += (int) ($extension === 'jpg' || $extension === 'jpeg');
            $summary['png_count'] += (int) ($extension === 'png');
            $summary['preview_count'] += (int) (stripos($entry, '/preview/') !== false);
            $summary['query_count'] += (int) str_contains($entry, '?');
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w434Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['url'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w434Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w434Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w434', self::W434_FFI_CDEF) ?? false;
            $ffi = self::$w434Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W434_FFI_CDEF.)
            $written = $ffi->nntmux_w434_fanart_image_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W434_FFI_CDEF.)
                $written = $ffi->nntmux_w434_fanart_image_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W434_SUMMARY_KEYS || $result['total_images'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W434_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Wave 50.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.25x, p99 2.24x; uncommon mean 3.19x, p99 2.75x); see RustBookCandidateFanartForkingGenreInvitationW50ParityTest. */
    private const bool W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w504_fanart_image_rank_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Image flags returned by rankImageBatch(). */
    public const int W50_BEST = 1;

    public const int W50_NUMERIC_LIKES = 2;

    public const int W50_HTTPS = 4;

    public const int W50_FANART_HOST = 8;

    public const int W50_PREVIEW = 16;

    public const int W50_JPG = 32;

    public const int W50_PNG = 64;

    public const int W50_EMPTY_URL = 128;

    private static ?bool $useW50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$useW50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W50 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW50RustFfi;
        self::$useW50RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW50RustFfi = $previous;
        }
    }

    /**
     * Rank Fanart.TV image entries, two ints each: [likes, flags]. Likes are the trimmed
     * value when it is all ASCII digits (saturating at PHP_INT_MAX, flag 2), otherwise 0.
     * Consecutive entries with the same group id form one artwork list; its first entry
     * with the most likes (the selectBestPosterUrl() stable-sort pick) gets flag 1.
     *
     * URL flags on the trimmed URL: 4 https:// prefix, 8 contains fanart.tv/, 16 contains
     * /preview/ (all ASCII case-insensitive), 32 jpg/jpeg or 64 png text after the last
     * dot, 128 empty.
     *
     * @param  list<int>  $groups
     * @param  list<string>  $likes
     * @param  list<string>  $urls
     * @return list<int>
     */
    public static function rankImageBatch(array $groups, array $likes, array $urls): array
    {
        return self::usesW50RustFfi()
            ? self::rankImageBatchNative($groups, $likes, $urls)
            : self::rankImageBatchPhp($groups, $likes, $urls);
    }

    /**
     * @param  list<int>  $groups
     * @param  list<string>  $likes
     * @param  list<string>  $urls
     * @return list<int>
     */
    public static function rankImageBatchNative(array $groups, array $likes, array $urls): array
    {
        self::w50AssertColumns($groups, $likes, $urls);
        if ($groups === []) {
            return [];
        }

        return self::w50Call(implode("\0", $likes)."\0".implode("\0", $urls), pack('P*', ...$groups), \count($groups))
            ?? self::rankImageBatchPhp($groups, $likes, $urls);
    }

    /**
     * @param  list<int>  $groups
     * @param  list<string>  $likes
     * @param  list<string>  $urls
     * @return list<int>
     */
    public static function rankImageBatchPhp(array $groups, array $likes, array $urls): array
    {
        self::w50AssertColumns($groups, $likes, $urls);
        $out = [];
        $best = 0;
        $bestLikes = 0;
        foreach ($groups as $i => $group) {
            $like = trim($likes[$i]);
            $numeric = $like !== '' && ctype_digit($like);
            // (int) saturates digit strings beyond PHP_INT_MAX.
            $value = $numeric ? (int) $like : 0;
            $flags = $numeric ? self::W50_NUMERIC_LIKES : 0;
            $url = trim($urls[$i]);
            if ($url === '') {
                $flags |= self::W50_EMPTY_URL;
            } else {
                if (strncasecmp($url, 'https://', 8) === 0) {
                    $flags |= self::W50_HTTPS;
                }
                if (stripos($url, 'fanart.tv/') !== false) {
                    $flags |= self::W50_FANART_HOST;
                }
                if (stripos($url, '/preview/') !== false) {
                    $flags |= self::W50_PREVIEW;
                }
                $dot = strrpos($url, '.');
                if ($dot !== false) {
                    $extension = strtolower(substr($url, $dot + 1));
                    if ($extension === 'jpg' || $extension === 'jpeg') {
                        $flags |= self::W50_JPG;
                    } elseif ($extension === 'png') {
                        $flags |= self::W50_PNG;
                    }
                }
            }
            if ($i > 0 && $group !== $groups[$i - 1]) {
                $out[$best * 2 + 1] |= self::W50_BEST;
                $best = $i;
                $bestLikes = $value;
            } elseif ($i === 0 || $value > $bestLikes) {
                $best = $i;
                $bestLikes = $value;
            }
            $out[] = $value;
            $out[] = $flags;
        }
        if ($out !== []) {
            $out[$best * 2 + 1] |= self::W50_BEST;
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns, little-endian int64 group ids,
     * two int64 words back per record. Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w50Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w50Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w50', self::W50_FFI_CDEF) ?? false;
            $ffi = self::$w50Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 2;
            $out = self::$w50Out;
            if ($out === null || self::$w50OutCap < $need) {
                self::$w50OutCap = max(1024, $need);
                $out = self::$w50Out = $ffi->new('int64_t['.self::$w50OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
            $written = $ffi->nntmux_w504_fanart_image_rank_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w50OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  ...$columns
     */
    private static function w50AssertColumns(array ...$columns): void
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count || ! array_is_list($column)) {
                throw new InvalidArgumentException('W50 batch columns must be lists of equal length.');
            }
        }
    }
}
