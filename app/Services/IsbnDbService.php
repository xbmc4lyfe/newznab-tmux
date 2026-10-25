<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BookProviderException;
use App\Support\BookIsbn;
use App\Support\BookMatchScorer;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class IsbnDbService
{
    /** Disabled until correctness and the common-path and p99 benchmark gates qualify. */
    public const bool W394_RUST_FFI_DEFAULT = false;

    public const string W394_FFI_CDEF = <<<'C'
        ssize_t nntmux_w394_isbn_batch_candidates(const char *input, size_t len, char *out, size_t cap);
        C;

    private static ?bool $w394UseRustFfi = null;

    private static FFI|false|null $w394Ffi = null;

    public static function useW394RustFfi(?bool $use = true): void
    {
        self::$w394UseRustFfi = $use;
        if ($use === null) {
            self::$w394Ffi = null;
        }
    }

    public static function usesW394RustFfi(): bool
    {
        return self::$w394UseRustFfi ?? self::W394_RUST_FFI_DEFAULT;
    }

    public static function resetW394RustFfi(): void
    {
        self::$w394UseRustFfi = null;
        self::$w394Ffi = null;
    }

    /**
     * Arguments contain only projected scalar fields, never caller-owned objects.
     *
     * @param  list<mixed>  $arguments
     * @return array<string, int|string|bool>|null
     */
    private static function w394Call(array $arguments): ?array
    {
        try {
            $input = serialize($arguments);
            if (strlen($input) > 16777216) {
                return null;
            }
            if (self::$w394Ffi === null) {
                self::$w394Ffi = RustPipelineLibrary::resolveDomain('metadata_w394', self::W394_FFI_CDEF) ?? false;
            }
            $ffi = self::$w394Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W394_FFI_CDEF.)
            $written = $ffi->nntmux_w394_isbn_batch_candidates($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W394_FFI_CDEF.)
                $written = $ffi->nntmux_w394_isbn_batch_candidates($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            $types = ['valid_isbn13_count' => 'int', 'valid_isbn10_count' => 'int', 'invalid_count' => 'int', 'deduplicated_count' => 'int', 'first_canonical_isbn13' => 'string'];
            if (! is_array($result) || array_keys($result) !== array_keys($types)) {
                return null;
            }
            foreach ($types as $key => $type) {
                if (get_debug_type($result[$key]) !== $type) {
                    return null;
                }
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $rawTokens
     * @return array<string, int|string|bool>
     */
    public static function normalizeIsbnBatchCandidates(array $rawTokens): array
    {
        if (self::usesW394RustFfi()) {
            $safe = [];
            foreach ($rawTokens as $token) {
                $safe[] = is_string($token) ? $token : null;
            }
            $native = self::w394Call([$safe]);
            if ($native !== null) {
                return $native;
            }
        }

        return self::normalizeIsbnBatchCandidatesPhp($rawTokens);
    }

    /** @param array<array-key, mixed> $rawTokens
     * @return array<string, int|string>
     */
    public static function normalizeIsbnBatchCandidatesPhp(array $rawTokens): array
    {
        $valid13 = $valid10 = $invalid = 0;
        $canonical = [];
        $first = '';
        foreach ($rawTokens as $token) {
            if (! is_string($token)) {
                $invalid++;

                continue;
            }
            $token = str_replace([' ', "\t", "\r", "\n", "\v", "\f", '-'], '', $token);
            $token = str_replace('x', 'X', $token);
            $isbn13 = '';
            if (preg_match('/\A97[89][0-9]{10}\z/D', $token) === 1) {
                $sum = 0;
                for ($i = 0; $i < 13; $i++) {
                    $sum += (ord($token[$i]) - 48) * ($i % 2 === 0 ? 1 : 3);
                }
                if ($sum % 10 === 0) {
                    $valid13++;
                    $isbn13 = $token;
                }
            } elseif (preg_match('/\A[0-9]{9}[0-9X]\z/D', $token) === 1) {
                $sum = 0;
                for ($i = 0; $i < 10; $i++) {
                    $sum += ($token[$i] === 'X' ? 10 : ord($token[$i]) - 48) * (10 - $i);
                }
                if ($sum % 11 === 0) {
                    $valid10++;
                    $prefix = '978'.substr($token, 0, 9);
                    $sum13 = 0;
                    for ($i = 0; $i < 12; $i++) {
                        $sum13 += (ord($prefix[$i]) - 48) * ($i % 2 === 0 ? 1 : 3);
                    }
                    $isbn13 = $prefix.(string) ((10 - $sum13 % 10) % 10);
                }
            }
            if ($isbn13 === '') {
                $invalid++;

                continue;
            }
            $canonical[$isbn13] = true;
            if ($first === '') {
                $first = $isbn13;
            }
        }

        return ['valid_isbn13_count' => $valid13, 'valid_isbn10_count' => $valid10, 'invalid_count' => $invalid, 'deduplicated_count' => count($canonical), 'first_canonical_isbn13' => $first];
    }

    /** Null selects the benchmark-qualified production default. */
    public ?bool $useRustFfi = null;

    public const COOLDOWN_CACHE_KEY = 'isbndb_provider_cooldown';

    protected const API_URL = 'https://api2.isbndb.com';

    protected Client $client;

    protected string $apiKey;

    protected int $pageSize = 10;

    public function __construct(?Client $client = null, ?string $apiKey = null)
    {
        $this->apiKey = trim($apiKey ?? (string) config('nntmux_api.isbndb_api_key', ''));

        $this->client = $client ?? new Client([
            'base_uri' => self::API_URL,
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'NNTmux/1.0',
            ],
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchBook(string $query, ?string $author = null, bool $shouldMatchAll = true): ?array
    {
        $books = $this->searchBooks($query, $author, $shouldMatchAll);

        return $books[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchBooks(string $query, ?string $author = null, bool $shouldMatchAll = true): array
    {
        $query = trim($query);
        $author = $author !== null ? trim($author) : null;
        if ($query === '' || ! $this->isConfigured()) {
            return [];
        }

        if (Cache::has(self::COOLDOWN_CACHE_KEY)) {
            throw new BookProviderException('isbndb', 'ISBNdb is temporarily cooling down after a failed request.');
        }

        $cacheKey = 'isbndb_search_'.md5(implode('|', [
            mb_strtolower((string) preg_replace('/\s+/', ' ', $query)),
            mb_strtolower((string) preg_replace('/\s+/', ' ', (string) $author)),
            $shouldMatchAll ? 'all' : 'any',
            (string) $this->pageSize,
        ]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $requestQuery = [
            'shouldMatchAll' => $shouldMatchAll ? 'true' : 'false',
            'pageSize' => $this->pageSize,
            'column' => 'title',
        ];

        $response = $this->request('/books/'.rawurlencode($query), $requestQuery);

        if ($response === null) {
            Cache::put($cacheKey, [], now()->addHours(6));

            return [];
        }

        $books = $response['data'] ?? null;
        if (! is_array($books)) {
            Cache::put(self::COOLDOWN_CACHE_KEY, true, now()->addMinutes(5));

            throw new BookProviderException('isbndb', 'ISBNdb search response did not contain a data array.', 200, 300);
        }
        if ($books === []) {
            Cache::put($cacheKey, [], now()->addHours(6));

            return [];
        }

        $normalized = [];
        foreach ($books as $book) {
            if (is_array($book)) {
                $normalized[] = $this->normalizeBookResult($book);
            }
        }

        Cache::put($cacheKey, $normalized, now()->addHours(24));

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIsbn(string $isbn): ?array
    {
        $isbn = BookIsbn::normalize($isbn);
        if ($isbn === null || ! $this->isConfigured()) {
            return null;
        }

        if (Cache::has(self::COOLDOWN_CACHE_KEY)) {
            throw new BookProviderException('isbndb', 'ISBNdb is temporarily cooling down after a failed request.');
        }

        $cacheKey = 'isbndb_isbn_'.$isbn;
        $cached = Cache::get($cacheKey);
        if ($cached === false) {
            return null;
        }
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('/book/'.rawurlencode($isbn));
        if ($response === null) {
            Cache::put($cacheKey, false, now()->addHours(6));

            return null;
        }
        $bookRaw = $response['book'] ?? null;
        if (! is_array($bookRaw) || $bookRaw === []) {
            Cache::put(self::COOLDOWN_CACHE_KEY, true, now()->addMinutes(5));

            throw new BookProviderException('isbndb', 'ISBNdb ISBN response did not contain a book object.', 200, 300);
        }

        $book = $this->normalizeBookResult($bookRaw);
        Cache::put($cacheKey, $book, now()->addHours(24));

        return $book;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    protected function request(string $path, array $query = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->client->get($path, [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => $this->apiKey,
                ],
                'query' => $query,
            ]);

            $statusCode = $response->getStatusCode();
            $this->logRateLimitHeaders($response);

            if ($statusCode === 404) {
                return null;
            }

            if ($statusCode !== 200) {
                $retryAfter = $this->retryAfterSeconds($response);
                if ($statusCode === 429 || $statusCode >= 500) {
                    $retryAfter ??= 300;
                    Cache::put(self::COOLDOWN_CACHE_KEY, true, now()->addSeconds($retryAfter));
                }

                throw new BookProviderException(
                    'isbndb',
                    "ISBNdb request returned status {$statusCode}.",
                    $statusCode,
                    $retryAfter,
                );
            }

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                throw new BookProviderException('isbndb', 'ISBNdb returned an invalid response payload.', $statusCode);
            }

            return $data;
        } catch (BookProviderException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            Cache::put(self::COOLDOWN_CACHE_KEY, true, now()->addMinutes(5));

            throw new BookProviderException(
                'isbndb',
                'ISBNdb returned malformed JSON.',
                200,
                300,
                $exception,
            );
        } catch (GuzzleException $e) {
            Cache::put(self::COOLDOWN_CACHE_KEY, true, now()->addMinutes(5));

            throw new BookProviderException(
                'isbndb',
                'ISBNdb API request error: '.$e->getMessage(),
                null,
                300,
                $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    private function normalizeBookResult(array $book): array
    {
        return ($this->useRustFfi ?? false) ? $this->normalizeBookResultNative($book) : $this->normalizeBookResultPhp($book);
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    public function normalizeBookResultNative(array $book): array
    {
        $date = $book['date_published'] ?? null;
        $authors = is_array($book['authors'] ?? null) ? $book['authors'] : [];
        $subjects = is_array($book['subjects'] ?? null) ? $book['subjects'] : [];
        foreach ([$authors, $subjects] as $items) {
            foreach ($items as $value) {
                if (! is_scalar($value) && $value !== null) {
                    return $this->normalizeBookResultPhp($book);
                }
            }
        }
        $fields = [is_string($date) ? '1' : '0', is_string($date) ? $date : '', (string) count($authors), (string) count($subjects)];
        foreach ([$authors, $subjects] as $items) {
            foreach ($items as $value) {
                $fields[] = (string) $value;
            }
        }
        $input = implode("\0", $fields)."\0";
        if (substr_count($input, "\0") !== count($fields)) {
            return $this->normalizeBookResultPhp($book);
        }
        $result = BookMatchScorer::rankingString('nntmux_isbndb_fields_nul', $input);
        $parts = $result === null ? [] : explode("\0", $result);
        if (count($parts) !== 5 || $parts[4] !== '') {
            return $this->normalizeBookResultPhp($book);
        }

        return $this->normalizeBookResultPhp($book, [
            'author' => $parts[2], 'genre' => $parts[3],
            'publishdate' => $parts[0] === '1' ? ($parts[1] === '' ? null : $parts[1]) : $this->normalizePublishDate($date),
        ]);
    }

    /**
     * @param  array<string, mixed>  $book
     * @param  array{author: string, genre: string, publishdate: ?string}|null  $nativeFields
     * @return array<string, mixed>
     */
    public function normalizeBookResultPhp(array $book, ?array $nativeFields = null): array
    {
        $isbn13 = isset($book['isbn13']) ? BookIsbn::normalize((string) $book['isbn13']) : null;
        if ($isbn13 === null && isset($book['isbn'])) {
            $isbn13 = BookIsbn::normalize((string) $book['isbn']);
        }
        if ($isbn13 !== null && strlen($isbn13) === 10) {
            $isbn13 = BookIsbn::toIsbn13($isbn13);
        }

        $isbn10 = isset($book['isbn10']) ? BookIsbn::normalize((string) $book['isbn10']) : null;
        if ($isbn10 !== null && strlen($isbn10) === 13) {
            $isbn10 = BookIsbn::toIsbn10($isbn10);
        }

        $authors = $nativeFields === null && is_array($book['authors'] ?? null)
            ? array_values(array_filter(array_map('strval', $book['authors'])))
            : [];
        $subjects = $nativeFields === null && is_array($book['subjects'] ?? null)
            ? array_values(array_filter(array_map('strval', $book['subjects'])))
            : [];

        $identifier = $isbn13 ?? $isbn10 ?? md5((string) json_encode($book));
        $coverUrl = (string) ($book['image'] ?? $book['image_original'] ?? '');
        $overview = trim((string) ($book['synopsis'] ?? $book['overview'] ?? $book['excerpt'] ?? ''));

        return [
            'title' => (string) ($book['title'] ?? ''),
            'author' => $nativeFields['author'] ?? implode(', ', $authors),
            'asin' => 'isbndb:'.$identifier,
            'isbn' => $isbn13,
            'ean' => $isbn10,
            'url' => '',
            'salesrank' => '',
            'publisher' => (string) ($book['publisher'] ?? ''),
            'publishdate' => $nativeFields !== null ? $nativeFields['publishdate'] : $this->normalizePublishDate($book['date_published'] ?? null),
            'pages' => isset($book['pages']) && is_numeric($book['pages']) ? (string) ((int) $book['pages']) : '',
            'overview' => strip_tags($overview),
            'genre' => $nativeFields['genre'] ?? implode(', ', $subjects),
            'coverurl' => $coverUrl,
            'cover' => $coverUrl !== '' ? 1 : 0,
        ];
    }

    private function normalizePublishDate(mixed $date): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    private function logRateLimitHeaders(ResponseInterface $response): void
    {
        $rateLimit = $response->getHeaderLine('ratelimit');
        $rateLimitPolicy = $response->getHeaderLine('ratelimit-policy');

        if ($rateLimit === '' && $rateLimitPolicy === '') {
            return;
        }

        preg_match('/remaining[=:\s"]+(\d+)/i', $rateLimit, $remainingMatches);
        $remaining = isset($remainingMatches[1]) ? (int) $remainingMatches[1] : null;

        if ($remaining !== null && $remaining <= 50) {
            Log::warning('ISBNdb API rate limit getting low', [
                'ratelimit' => $rateLimit,
                'ratelimit_policy' => $rateLimitPolicy,
            ]);

            return;
        }

        Log::debug('ISBNdb API rate limit status', [
            'ratelimit' => $rateLimit,
            'ratelimit_policy' => $rateLimitPolicy,
        ]);
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        if ($retryAfter === '') {
            return null;
        }
        if (ctype_digit($retryAfter)) {
            return max(1, (int) $retryAfter);
        }

        $retryAt = strtotime($retryAfter);

        return $retryAt === false ? null : max(1, $retryAt - time());
    }
}
