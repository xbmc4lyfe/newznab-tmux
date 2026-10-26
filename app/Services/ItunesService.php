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

    /** C signature of the Wave 61.5 {@see self::lookupPlanBatch()} helper. */
    private const string W61_ITUNES_PLAN_FFI_CDEF = 'int64_t nntmux_w615_itunes_lookup_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 61.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 6.14/5.29/4.11/3.11x); see tests/Unit/RustGoogleBooksIgdbImdbScraperItunesLogIndexerW61ParityTest.php.
     */
    public const bool W61_RUST_FFI_DEFAULT = true;

    /** {@see self::lookupPlanBatch()} entity, media and result kind codes (1-based). */
    private const array W61_ENTITIES = ['album', 'song', 'musicartist', 'ebook', 'audiobook'];

    private const array W61_MEDIA = ['music', 'ebook', 'audiobook', 'all'];

    private const array W61_KINDS = ['track', 'collection', 'ebook', 'audiobook', 'artist'];

    /**
     * Wave 61.5 routing and FFI cache, kept in function-local statics (no static properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w61_5', self::W61_ITUNES_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W61_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW61RustFfi(): bool
    {
        return self::w61RustFfiState() === true;
    }

    /**
     * Runs $callback with lookupPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 61.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w61Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w61RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w615_itunes_lookup_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch iTunes Store lookup plan over `term\tentity\tmedia\tcountry\tlimit\tresult_name\tresult_artist\tresult_kind`.
     * Nine words per record: `[term_len, term_words, entity_code, media_code, country_code, limit, score, kind_code,
     * flags]`. The term splits on ASCII whitespace (space, tab, LF, CR, VT, FF) and its length joins the words with
     * single spaces. Entity codes are 1 album, 2 song, 3 musicArtist, 4 ebook, 5 audiobook; media 1 music, 2 ebook,
     * 3 audiobook, 4 all; result kinds 1 track, 2 collection, 3 ebook, 4 audiobook, 5 artist (trimmed, ASCII
     * case-insensitive, 0 empty, -1 unknown). The country is two ASCII letters as `(A-Z) * 26 + (A-Z) + 1` (empty or
     * invalid: US, 539). Limit (empty 10) is 1-3 digits clamped to 1..200. The score is `100 * matched / tokens` of
     * the term tokens (ASCII alphanumeric or non-ASCII runs) found among the result name and artist tokens. Flags:
     * 1 empty term, 2 unknown entity, 4 unknown media, 8 entity outside its media, 16 invalid country, 32 clamped
     * limit, 64 invalid limit, 128 exact name match, 256 unknown kind, 512 not exactly eight fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function lookupPlanBatch(array $records): array
    {
        return self::usesW61RustFfi() ? self::lookupPlanBatchNative($records) : self::lookupPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function lookupPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 8 ? 0 : 512;
            $words = preg_split('/[ \t\n\r\x0b\x0c]+/', $fields[0], -1, PREG_SPLIT_NO_EMPTY);
            $words = \is_array($words) ? $words : [];
            $termLength = \strlen(implode(' ', $words));
            if ($words === []) {
                $flags |= 1;
            }
            $entity = self::w61Code($fields[1] ?? '', self::W61_ENTITIES, 2, $flags);
            $media = self::w61Code($fields[2] ?? '', self::W61_MEDIA, 4, $flags);
            $expected = match ($entity) {
                1, 2, 3 => 1,
                4 => 2,
                5 => 3,
                default => 0,
            };
            if ($expected > 0 && $media > 0 && $media !== 4 && $media !== $expected) {
                $flags |= 8;
            }
            $country = 539;
            $text = trim($fields[3] ?? '');
            if (preg_match('/^[A-Za-z]{2}$/D', $text) === 1) {
                $upper = strtoupper($text);
                $country = (\ord($upper[0]) - 65) * 26 + \ord($upper[1]) - 65 + 1;
            } elseif ($text !== '') {
                $flags |= 16;
            }
            $limit = 10;
            $text = trim($fields[4] ?? '');
            if ($text !== '') {
                $value = self::w61Digits($text, 3);
                if ($value === null) {
                    $flags |= 64;
                } elseif ($value < 1 || $value > 200) {
                    $limit = max(1, min(200, $value));
                    $flags |= 32;
                } else {
                    $limit = $value;
                }
            }
            $term = self::w61Tokens($fields[0]);
            $name = self::w61Tokens($fields[5] ?? '');
            $set = array_flip([...$name, ...self::w61Tokens($fields[6] ?? '')]);
            $matched = 0;
            foreach ($term as $token) {
                if (isset($set[$token])) {
                    $matched++;
                }
            }
            $score = $term !== [] ? intdiv(100 * $matched, \count($term)) : 0;
            if ($term !== [] && $term === $name) {
                $flags |= 128;
            }
            $kind = self::w61Code($fields[7] ?? '', self::W61_KINDS, 256, $flags);
            array_push($out, $termLength, \count($words), $entity, $media, $country, $limit, $score, $kind, $flags);
        }

        return $out;
    }

    /**
     * Trimmed, ASCII case-insensitive 1-based code in $names; 0 empty, -1 unknown (sets $flag).
     *
     * @param  list<string>  $names
     */
    private static function w61Code(string $field, array $names, int $flag, int &$flags): int
    {
        $field = strtolower(trim($field));
        if ($field === '') {
            return 0;
        }
        $position = array_search($field, $names, true);
        if ($position === false) {
            $flags |= $flag;

            return -1;
        }

        return $position + 1;
    }

    /**
     * Lowercased runs of ASCII alphanumeric or non-ASCII bytes.
     *
     * @return list<string>
     */
    private static function w61Tokens(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9\x80-\xff]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return \is_array($tokens) ? $tokens : [];
    }

    /** 1-$max ASCII digits as an integer, else null. */
    private static function w61Digits(string $text, int $max): ?int
    {
        return $text !== '' && \strlen($text) <= $max && ctype_digit($text) ? (int) $text : null;
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

        return self::w61Words($count, implode("\0", $records), 9 * $count) ?? self::lookupPlanBatchPhp($records);
    }
}
