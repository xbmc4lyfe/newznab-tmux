<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Custom iTunes Search API Service.
 * Replaces the DariusIII/php-itunes-api package with a direct implementation.
 * Supports music and ebook searches only (movies are not supported due to API issues).
 *
 * @see https://developer.apple.com/library/archive/documentation/AudioVideo/Conceptual/iTuneSearchAPI/index.html
 */
class ItunesService
{
    protected const API_URL = 'https://itunes.apple.com/search';

    protected const LOOKUP_URL = 'https://itunes.apple.com/lookup';

    public const NORMALIZER_FFI_CDEF = <<<'C'
        ssize_t nntmux_itunes_high_res_cover(const char *input, size_t len, int64_t size, char *out, size_t cap);
        ssize_t nntmux_itunes_result_fields(const char *input, size_t len, int64_t size, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default. */
    public ?bool $useRustFfi = null;

    private static FFI|false|null $normalizerFfi = null;

    protected Client $client;

    protected int $limit = 25;

    protected string $country = 'US';

    private bool $lastRequestFailed = false;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'NNTmux/1.0',
            ],
        ]);
    }

    /**
     * Set the result limit.
     */
    public function limit(int $limit): self
    {
        $this->limit = min($limit, 200); // iTunes max is 200

        return $this;
    }

    /**
     * Set the country code for the search.
     */
    public function country(string $country): self
    {
        $this->country = strtoupper($country);

        return $this;
    }

    public function lastRequestFailed(): bool
    {
        return $this->lastRequestFailed;
    }

    /**
     * Search for music albums.
     *
     * @return array<string, mixed>
     */
    public function searchAlbums(string $term): ?array
    {
        return $this->search($term, 'album', 'music');
    }

    /**
     * Search for music tracks.
     *
     * @return array<string, mixed>
     */
    public function searchTracks(string $term): ?array
    {
        return $this->search($term, 'song', 'music');
    }

    /**
     * Search for artists.
     *
     * @return array<string, mixed>
     */
    public function searchArtists(string $term): ?array
    {
        return $this->search($term, 'musicArtist', 'music');
    }

    /**
     * Search for ebooks.
     *
     * @return array<string, mixed>
     */
    public function searchEbooks(string $term): ?array
    {
        return $this->search($term, 'ebook', 'ebook');
    }

    /**
     * Search for audiobooks.
     *
     * @return array<string, mixed>
     */
    public function searchAudiobooks(string $term): ?array
    {
        return $this->search($term, 'audiobook', 'audiobook');
    }

    /**
     * Lookup by iTunes ID.
     *
     * @return array<string, mixed>
     */
    public function lookupById(int $id): ?array
    {
        $cacheKey = 'itunes_lookup_'.$id;
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->client->get(self::LOOKUP_URL, [
                'query' => [
                    'id' => $id,
                    'country' => $this->country,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                Log::warning("iTunes API lookup returned status {$statusCode}");

                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);

            if (! isset($data['results']) || empty($data['results'])) {
                return null;
            }

            Cache::put($cacheKey, $data['results'][0], now()->addDays(7));

            return $data['results'][0];
        } catch (GuzzleException $e) {
            Log::error('iTunes API lookup error: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Lookup artist by ID.
     *
     * @return array<string, mixed>
     */
    public function lookupArtist(int $artistId): ?array
    {
        return $this->lookupById($artistId);
    }

    /**
     * Get the first album result for a search term.
     *
     * @return array<string, mixed>|null Normalized album data or null
     */
    public function findAlbum(string $term): ?array
    {
        $results = $this->searchAlbums($term);

        if (empty($results)) {
            return null;
        }

        return $this->normalizeAlbumResult($results[0]); // @phpstan-ignore offsetAccess.notFound
    }

    /**
     * Get the first track result for a search term.
     *
     * @return array<string, mixed>|null Normalized track data or null
     */
    public function findTrack(string $term): ?array
    {
        $results = $this->searchTracks($term);

        if (empty($results)) {
            return null;
        }

        return $this->normalizeTrackResult($results[0]); // @phpstan-ignore offsetAccess.notFound
    }

    /**
     * Get the first ebook result for a search term.
     *
     * @return array<string, mixed>|null Normalized ebook data or null
     */
    public function findEbook(string $term): ?array
    {
        $results = $this->findEbooks($term);

        return $results[0] ?? null;
    }

    /**
     * Get normalized ebook results for a search term.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findEbooks(string $term): array
    {
        $results = $this->searchEbooks($term);
        if (empty($results)) {
            return [];
        }

        $normalized = [];
        foreach ($results as $result) {
            if (is_array($result)) {
                $normalized[] = $this->normalizeEbookResult($result);
            }
        }

        return $normalized;
    }

    /**
     * Perform a search request to iTunes API.
     *
     * @return array<string, mixed>
     */
    protected function search(string $term, string $entity, string $media): ?array
    {
        $this->lastRequestFailed = false;
        $term = trim($term);
        if (empty($term)) {
            return null;
        }

        $cacheKey = 'itunes_search_'.md5($term.$entity.$media.$this->country.$this->limit);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->client->get(self::API_URL, [
                'query' => [
                    'term' => $term,
                    'entity' => $entity,
                    'media' => $media,
                    'country' => $this->country,
                    'limit' => $this->limit,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->lastRequestFailed = true;
                Log::warning("iTunes API search returned status {$statusCode}");

                return null;
            }

            $data = json_decode($response->getBody()->getContents(), true);

            if (! isset($data['results'])) {
                $this->lastRequestFailed = true;

                return null;
            }

            $results = $data['results'];
            Cache::put($cacheKey, $results, now()->addHours(6));

            return $results;
        } catch (GuzzleException $e) {
            $this->lastRequestFailed = true;
            Log::error('iTunes API search error: '.$e->getMessage());

            return null;
        }
    }

    public static function normalizerFfi(): ?FFI
    {
        if (self::$normalizerFfi === null) {
            self::$normalizerFfi = RustPipelineLibrary::resolveDomain('metadata', self::NORMALIZER_FFI_CDEF) ?? false;
        }

        return self::$normalizerFfi === false ? null : self::$normalizerFfi;
    }

    /** PCRE `\d` follows LC_CTYPE character tables outside the C locales. */
    public static function normalizerEnvironmentSupported(): bool
    {
        return in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true);
    }

    /** Return null when Rust declines or the library is unavailable. */
    private static function normalizerString(string $symbol, string $input, int $size): ?string
    {
        if (! self::normalizerEnvironmentSupported() || ($ffi = self::normalizerFfi()) === null) {
            return null;
        }
        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 64, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol($input, strlen($input), $size, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($input, strlen($input), $size, $buffer, $capacity);
            }

            return is_int($written) && $written >= 0 && $written <= $capacity ? FFI::string($buffer, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Rust `release_date` and `cover`; null keeps the PHP path (including its TypeErrors).
     *
     * @param  array<string, mixed>  $result
     * @return array{release_date: ?string, cover: string}|null
     */
    private function nativeResultFields(array $result): ?array
    {
        $date = $result['releaseDate'] ?? null;
        $artwork = $result['artworkUrl100'] ?? '';
        if (($date !== null && ! is_string($date)) || ! is_string($artwork)) {
            return null;
        }
        $input = ($date === null ? '0' : '1')."\0".$date."\0".$artwork."\0";
        if (substr_count($input, "\0") !== 3) {
            return null;
        }
        $output = self::normalizerString('nntmux_itunes_result_fields', $input, 800);
        $parts = $output === null ? [] : explode("\0", $output);
        if (count($parts) !== 4 || $parts[3] !== '') {
            return null;
        }

        return [
            'release_date' => $parts[0] === '1' ? ($parts[1] === '' ? null : $parts[1]) : $this->parseDate((string) $date),
            'cover' => $parts[2],
        ];
    }

    /**
     * Normalize album result to consistent format.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function normalizeAlbumResult(array $result): array
    {
        return ($this->useRustFfi ?? true) ? $this->normalizeAlbumResultNative($result) : $this->normalizeAlbumResultPhp($result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function normalizeAlbumResultNative(array $result): array
    {
        return $this->normalizeAlbumResultPhp($result, $this->nativeResultFields($result));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array{release_date: ?string, cover: string}|null  $native
     * @return array<string, mixed>
     */
    public function normalizeAlbumResultPhp(array $result, ?array $native = null): array
    {
        return [
            'id' => $result['collectionId'] ?? null,
            'name' => $result['collectionName'] ?? '',
            'artist' => $result['artistName'] ?? '',
            'artist_id' => $result['artistId'] ?? null,
            'genre' => $result['primaryGenreName'] ?? '',
            'release_date' => $native !== null ? $native['release_date'] : (isset($result['releaseDate']) ? $this->parseDate($result['releaseDate']) : null),
            'track_count' => $result['trackCount'] ?? 0,
            'cover' => $native['cover'] ?? $this->getHighResCover($result['artworkUrl100'] ?? ''),
            'store_url' => $result['collectionViewUrl'] ?? '',
            'price' => $result['collectionPrice'] ?? null,
            'currency' => $result['currency'] ?? 'USD',
            'copyright' => $result['copyright'] ?? '',
            'explicit' => ($result['collectionExplicitness'] ?? '') === 'explicit',
            'raw' => $result,
        ];
    }

    /**
     * Normalize track result to consistent format.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function normalizeTrackResult(array $result): array
    {
        return ($this->useRustFfi ?? true) ? $this->normalizeTrackResultNative($result) : $this->normalizeTrackResultPhp($result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function normalizeTrackResultNative(array $result): array
    {
        return $this->normalizeTrackResultPhp($result, $this->nativeResultFields($result));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array{release_date: ?string, cover: string}|null  $native
     * @return array<string, mixed>
     */
    public function normalizeTrackResultPhp(array $result, ?array $native = null): array
    {
        return [
            'id' => $result['trackId'] ?? null,
            'name' => $result['trackName'] ?? '',
            'artist' => $result['artistName'] ?? '',
            'artist_id' => $result['artistId'] ?? null,
            'album' => $result['collectionName'] ?? '',
            'album_id' => $result['collectionId'] ?? null,
            'genre' => $result['primaryGenreName'] ?? '',
            'release_date' => $native !== null ? $native['release_date'] : (isset($result['releaseDate']) ? $this->parseDate($result['releaseDate']) : null),
            'duration_ms' => $result['trackTimeMillis'] ?? 0,
            'cover' => $native['cover'] ?? $this->getHighResCover($result['artworkUrl100'] ?? ''),
            'preview_url' => $result['previewUrl'] ?? '',
            'store_url' => $result['trackViewUrl'] ?? '',
            'price' => $result['trackPrice'] ?? null,
            'currency' => $result['currency'] ?? 'USD',
            'explicit' => ($result['trackExplicitness'] ?? '') === 'explicit',
            'raw' => $result,
        ];
    }

    /**
     * Normalize ebook result to consistent format.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function normalizeEbookResult(array $result): array
    {
        return ($this->useRustFfi ?? true) ? $this->normalizeEbookResultNative($result) : $this->normalizeEbookResultPhp($result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function normalizeEbookResultNative(array $result): array
    {
        return $this->normalizeEbookResultPhp($result, $this->nativeResultFields($result));
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array{release_date: ?string, cover: string}|null  $native
     * @return array<string, mixed>
     */
    public function normalizeEbookResultPhp(array $result, ?array $native = null): array
    {
        return [
            'id' => $result['trackId'] ?? null,
            'name' => $result['trackName'] ?? '',
            'author' => $result['artistName'] ?? '',
            'author_id' => $result['artistId'] ?? null,
            'genres' => $result['genres'] ?? [],
            'genre' => $result['primaryGenreName'] ?? ($result['genres'][0] ?? ''),
            'release_date' => $native !== null ? $native['release_date'] : (isset($result['releaseDate']) ? $this->parseDate($result['releaseDate']) : null),
            'description' => $result['description'] ?? '',
            'cover' => $native['cover'] ?? $this->getHighResCover($result['artworkUrl100'] ?? ''),
            'store_url' => $result['trackViewUrl'] ?? '',
            'price' => $result['price'] ?? null,
            'currency' => $result['currency'] ?? 'USD',
            'average_rating' => $result['averageUserRating'] ?? null,
            'rating_count' => $result['userRatingCount'] ?? 0,
            'raw' => $result,
        ];
    }

    /**
     * Convert iTunes 100x100 artwork URL to higher resolution.
     */
    protected function getHighResCover(string $url, int $size = 800): string
    {
        return ($this->useRustFfi ?? false) ? $this->getHighResCoverNative($url, $size) : $this->getHighResCoverPhp($url, $size);
    }

    public function getHighResCoverNative(string $url, int $size = 800): string
    {
        if (empty($url)) {
            return '';
        }

        return self::normalizerString('nntmux_itunes_high_res_cover', $url, $size) ?? $this->getHighResCoverPhp($url, $size);
    }

    public function getHighResCoverPhp(string $url, int $size = 800): string
    {
        if (empty($url)) {
            return '';
        }

        // iTunes artwork URLs typically end with /100x100bb.jpg
        // We can replace it with larger sizes up to 3000x3000
        return preg_replace('/\d+x\d+(bb)?\./', $size.'x'.$size.'bb.', $url) ?? $url;
    }

    /**
     * Parse ISO 8601 date string.
     */
    protected function parseDate(string $dateString): ?string
    {
        try {
            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 3.16x, p99 2.49x; uncommon mean 2.97x, p99 2.88x; see the W46 parity test). */
    public const bool W464_RUST_FFI_DEFAULT = true;

    public const string W464_FFI_CDEF = <<<'C'
        ssize_t nntmux_w464_itunes_artwork_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W464_SUMMARY_KEYS = ['total_entries', 'empty_count', 'resizable_count', 'bb_suffix_count', 'https_count', 'mzstatic_count', 'jpeg_count', 'png_count', 'whitespace_count', 'non_ascii_count', 'query_count'];

    /** unpack() format for the eleven little-endian int64 counters, in W464_SUMMARY_KEYS order. */
    private const string W464_UNPACK = 'Ptotal_entries/Pempty_count/Presizable_count/Pbb_suffix_count/Phttps_count/Pmzstatic_count/Pjpeg_count/Ppng_count/Pwhitespace_count/Pnon_ascii_count/Pquery_count';

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
     * Summarize artwork URLs as getHighResCover() sees them (untrimmed; ''
     * and '0' are empty): size tokens its rewrite replaces, bb suffixes,
     * https and mzstatic hosts, JPEG/PNG extensions, whitespace, non-ASCII
     * bytes and query strings. Entries come from strings, integers, or the
     * artworkUrl100, artworkUrl60 or artworkUrl30 field; other values count
     * as empty. Byte classes are ASCII-only.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeItunesArtworkBatch(array $entries): array
    {
        return self::usesW464RustFfi()
            ? self::summarizeItunesArtworkBatchNative($entries)
            : self::summarizeItunesArtworkBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeItunesArtworkBatchNative(array $entries): array
    {
        return self::w464Call(self::w464Entries($entries)) ?? self::summarizeItunesArtworkBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeItunesArtworkBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W464_SUMMARY_KEYS, 0);
        foreach (self::w464Entries($entries) as $entry) {
            $summary['total_entries']++;
            if ($entry === '' || $entry === '0') {
                $summary['empty_count']++;

                continue;
            }
            $summary['resizable_count'] += (int) (preg_match('/[0-9]+x[0-9]+(?:bb)?\./', $entry) === 1);
            $summary['bb_suffix_count'] += (int) (preg_match('/[0-9]+x[0-9]+bb\./', $entry) === 1);
            $summary['https_count'] += (int) str_starts_with($entry, 'https://');
            $summary['mzstatic_count'] += (int) str_contains($entry, '.mzstatic.com/');
            $summary['jpeg_count'] += (int) (preg_match('/\.[Jj][Pp][Ee]?[Gg]\z/', $entry) === 1);
            $summary['png_count'] += (int) (preg_match('/\.[Pp][Nn][Gg]\z/', $entry) === 1);
            $summary['whitespace_count'] += (int) (preg_match('/[ \t\n\r\x0b\f]/', $entry) === 1);
            $summary['non_ascii_count'] += (int) (preg_match('/[\x80-\xff]/', $entry) === 1);
            $summary['query_count'] += (int) str_contains($entry, '?');
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
                $item = $item['artworkUrl100'] ?? $item['artworkUrl60'] ?? $item['artworkUrl30'] ?? null;
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
            if ($out === null || $ffi->nntmux_w464_itunes_artwork_batch($input, strlen($input), $out, 88) !== 88) {
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
}
