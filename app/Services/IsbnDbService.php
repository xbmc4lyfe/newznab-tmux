<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BookProviderException;
use App\Support\BookIsbn;
use App\Support\BookMatchScorer;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use FFI\CData;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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

    // ---------------------------------------------------------------------
    // Wave 51.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 10.57x, p99 9.73x; uncommon mean 8.08x, p99 6.30x); see RustIsbnOpenLibPar2ListProcessorNfoW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w514_isbn_token_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$useW51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W51 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW51RustFfi;
        self::$useW51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW51RustFfi = $previous;
        }
    }

    /**
     * Classify raw ISBN tokens, three ints each: [13 or 10 for a valid ISBN-13 or ISBN-10, 0
     * when invalid; canonical ISBN-13 as an integer or 0; index of the first earlier token with
     * the same canonical ISBN-13 or -1]. Tokens are normalized as in
     * normalizeIsbnBatchCandidatesPhp(): ASCII whitespace and hyphens removed, x uppercased.
     *
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function isbnTokenBatch(array $tokens): array
    {
        return self::usesW51RustFfi()
            ? self::isbnTokenBatchNative($tokens)
            : self::isbnTokenBatchPhp($tokens);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function isbnTokenBatchNative(array $tokens): array
    {
        self::w51AssertList($tokens);
        if ($tokens === []) {
            return [];
        }

        return self::w51Call(implode("\0", $tokens), '', \count($tokens))
            ?? self::isbnTokenBatchPhp($tokens);
    }

    /**
     * @param  list<string>  $tokens
     * @return list<int>
     */
    public static function isbnTokenBatchPhp(array $tokens): array
    {
        self::w51AssertList($tokens);
        $out = [];
        $seen = [];
        foreach ($tokens as $i => $raw) {
            $token = str_replace('x', 'X', str_replace([' ', "\t", "\r", "\n", "\v", "\f", '-'], '', $raw));
            $kind = 0;
            $isbn13 = '';
            if (preg_match('/\A97[89][0-9]{10}\z/D', $token) === 1) {
                $sum = 0;
                for ($k = 0; $k < 13; $k++) {
                    $sum += (ord($token[$k]) - 48) * ($k % 2 === 0 ? 1 : 3);
                }
                if ($sum % 10 === 0) {
                    $kind = 13;
                    $isbn13 = $token;
                }
            } elseif (preg_match('/\A[0-9]{9}[0-9X]\z/D', $token) === 1) {
                $sum = 0;
                for ($k = 0; $k < 10; $k++) {
                    $sum += ($token[$k] === 'X' ? 10 : ord($token[$k]) - 48) * (10 - $k);
                }
                if ($sum % 11 === 0) {
                    $kind = 10;
                    $prefix = '978'.substr($token, 0, 9);
                    $sum13 = 0;
                    for ($k = 0; $k < 12; $k++) {
                        $sum13 += (ord($prefix[$k]) - 48) * ($k % 2 === 0 ? 1 : 3);
                    }
                    $isbn13 = $prefix.(string) ((10 - $sum13 % 10) % 10);
                }
            }
            if ($isbn13 === '') {
                array_push($out, 0, 0, -1);

                continue;
            }
            $first = $seen[$isbn13] ??= $i;
            array_push($out, $kind, (int) $isbn13, $first === $i ? -1 : $first);
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined tokens, three int64 words back per record. Null when
     * the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w51Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w51', self::W51_FFI_CDEF) ?? false;
            $ffi = self::$w51Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 3;
            $out = self::$w51Out;
            if ($out === null || self::$w51OutCap < $need) {
                self::$w51OutCap = max(1024, $need);
                $out = self::$w51Out = $ffi->new('int64_t['.self::$w51OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
            $written = $ffi->nntmux_w514_isbn_token_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w51OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function w51AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('W51 batch input must be a list.');
        }
    }

    // ========================================================================
    // WAVE 67.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four 1000-round paired runs reached 4.07x common mean, 4.07x common p99, 2.62x uncommon mean and 2.49x uncommon p99; see RustIgdbCompanyGamePlatformInvitationIsbnDbW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    private const string W67_FFI_CDEF = 'ssize_t nntmux_w674_isbndb_service_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w67IsbnDbServicePlanBatch() record. */
    public const int W67_ROW_WORDS = 4;

    /** int64 summary words after the w67IsbnDbServicePlanBatch() records (none for an empty batch). */
    public const int W67_TRAILER_WORDS = 10;

    /** Trailing publisher tokens dropped while another token remains. */
    private const array W67_PUBLISHER_SUFFIXES = ['inc' => true, 'ltd' => true, 'llc' => true, 'co' => true, 'corp' => true, 'publishing' => true, 'publishers' => true,
        'publisher' => true, 'press' => true, 'books' => true, 'group' => true, 'limited' => true, 'gmbh' => true, 'plc' => true];

    /**
     * ISBNdb book plan rows for [ISBN, title, '|'-separated authors, publisher, publish date,
     * pages, cover flag, query year] items.
     *
     * The ISBN keeps its upper-cased [0-9X] bytes: kind 0 empty, 1 valid ISBN-10, 2 valid
     * ISBN-13 (978/979), 3 bad ISBN-10 checksum, 4 bad ISBN-13 checksum, 5 malformed, with the
     * ISBN-13 value (0 when not valid) and the ISBN-10 check digit (X 10, 15 when not
     * applicable). Authors are trimmed non-empty '|' parts. The publisher's lower-cased ASCII
     * alphanumeric tokens drop trailing W67_PUBLISHER_SUFFIXES while another remains. The trimmed
     * publish date has precision 0 empty, 1 YYYY, 2 YYYY-MM, 3 YYYY-MM-DD..., 4 malformed (year
     * 0, or a year below 1000). The field mask (valid ISBN, title, authors, publisher, dated,
     * pages, cover) gives completeness = fields x 100 / 7 and the decision: 1 no valid ISBN or
     * title, 2 under four fields, 3 under six, 4 complete. Edition score: completeness plus, with
     * a query year 1-9999 (clamped) and a dated year, 50 minus 10 per year apart (to 0).
     *
     * Rows [kind | check digit << 3 | precision << 7 | year << 10 | mask << 24 | completeness <<
     * 31 | decision << 38 | authors << 41 | comma in an author << 49 | publisher suffix stripped
     * << 50 | edition score << 51 | empty title << 59, ISBN-13, fnv1a32(kept publisher tokens
     * joined by ' '), fnv1a32(strtolower(first author))], then the trailer [records, valid ISBNs,
     * bad checksums, the four decision counts, covers, stripped publishers, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w67IsbnDbServicePlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67IsbnDbServicePlanBatchNative($items) : self::w67IsbnDbServicePlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w67IsbnDbServicePlanBatchPhp(array $items): array
    {
        self::w67Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$isbn, $title, $authorList, $publisherName, $published, $pages, $cover, $queryYear]) {
            [$kind, $isbn13, $check10] = self::w67Isbn($isbn);
            $titleEmpty = trim($title) === '';
            $authors = $first = 0;
            $comma = false;
            foreach (explode('|', $authorList) as $author) {
                $author = trim($author);
                if ($author === '') {
                    continue;
                }
                if ($authors++ === 0) {
                    $first = self::w67Fnv(strtolower($author));
                }
                $comma = $comma || str_contains($author, ',');
            }
            $tokens = preg_split('/[^a-z0-9]+/', strtolower($publisherName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $total = \count($tokens);
            $kept = $total;
            while ($kept > 1 && isset(self::W67_PUBLISHER_SUFFIXES[$tokens[$kept - 1]])) {
                $kept--;
            }
            $stripped = $kept < $total;
            $publisher = self::w67Fnv(implode(' ', $stripped ? \array_slice($tokens, 0, $kept) : $tokens));
            [$precision, $year] = self::w67PublishDate($published);
            $cover = $cover !== 0;
            $queryYear = max(0, min($queryYear, 9999));
            $valid = $kind === 1 || $kind === 2;
            $mask = (int) $valid | (int) ! $titleEmpty << 1 | (int) ($authors > 0) << 2 | (int) ($kept > 0) << 3 | (int) ($precision >= 1 && $precision <= 3) << 4
                | (int) ($pages > 0) << 5 | (int) $cover << 6;
            $fields = substr_count(decbin($mask), '1');
            $completeness = intdiv($fields * 100, 7);
            $decision = ! $valid && $titleEmpty ? 1 : ($fields < 4 ? 2 : ($fields < 6 ? 3 : 4));
            $edition = $completeness + ($queryYear > 0 && $year > 0 ? max(0, 50 - min(abs($year - $queryYear), 5) * 10) : 0);
            $word = $kind | $check10 << 3 | $precision << 7 | $year << 10 | $mask << 24 | $completeness << 31 | $decision << 38 | min($authors, 255) << 41
                | (int) $comma << 49 | (int) $stripped << 50 | $edition << 51 | (int) $titleEmpty << 59;
            array_push($out, $word, $isbn13, $publisher, $first);
            $t[1] += (int) $valid;
            $t[2] += (int) ($kind === 3 || $kind === 4);
            $t[2 + $decision]++;
            $t[7] += (int) $cover;
            $t[8] += (int) $stripped;
            $value = $word ^ $isbn13 ^ $publisher << 4 ^ $first;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[9] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w67IsbnDbServicePlanBatchNative(array $items): array
    {
        self::w67Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w67Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3), array_column($items, 4)]);
        $ints = pack('P*', ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7));

        return self::w67Call($blob, $twin, $ints, $count, $count * self::W67_ROW_WORDS + self::W67_TRAILER_WORDS)
            ?? self::w67IsbnDbServicePlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w67Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W67 ISBNdb service plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 8 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2])
                || ! \is_string($item[3]) || ! \is_string($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7])) {
                throw new InvalidArgumentException('W67 ISBNdb service plan batch items must be [string ISBN, string title, string authors, string publisher, string publish date, int pages, int cover, int query year] lists.');
            }
        }
    }

    /**
     * [kind, ISBN-13 value, ISBN-10 check digit] of a raw ISBN; see w67IsbnDbServicePlanBatch().
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function w67Isbn(string $raw): array
    {
        $d = strtoupper(preg_replace('/[^0-9Xx]+/', '', $raw) ?? '');
        $n = \strlen($d);
        if ($n === 0) {
            return [0, 0, 15];
        }
        if ($n === 10) {
            if (! ctype_digit(substr($d, 0, 9))) {
                return [5, 0, 15];
            }
            $check = $d[9] === 'X' ? 10 : (int) $d[9];
            $sum = $check;
            $s13 = 38;
            $value = 978;
            for ($k = 0; $k < 9; $k++) {
                $digit = (int) $d[$k];
                $sum += (10 - $k) * $digit;
                $s13 += $digit * ($k % 2 === 1 ? 1 : 3);
                $value = $value * 10 + $digit;
            }
            if ($sum % 11 !== 0) {
                return [3, 0, 15];
            }

            return [1, $value * 10 + (10 - $s13 % 10) % 10, $check];
        }
        if ($n !== 13 || ! ctype_digit($d) || ! (str_starts_with($d, '978') || str_starts_with($d, '979'))) {
            return [5, 0, 15];
        }
        $sum = 0;
        for ($k = 0; $k < 13; $k++) {
            $sum += (int) $d[$k] * ($k % 2 === 0 ? 1 : 3);
        }
        if ($sum % 10 !== 0) {
            return [4, 0, 15];
        }
        $check = 15;
        if (str_starts_with($d, '978')) {
            $s = 0;
            for ($k = 0; $k < 9; $k++) {
                $s += (10 - $k) * (int) $d[3 + $k];
            }
            $check = (11 - $s % 11) % 11;
        }

        return [2, (int) $d, $check];
    }

    /**
     * [precision, year] of a publish date; see w67IsbnDbServicePlanBatch().
     *
     * @return array{0: int, 1: int}
     */
    private static function w67PublishDate(string $raw): array
    {
        $d = trim($raw);
        $length = \strlen($d);
        if ($length === 0) {
            return [0, 0];
        }
        $year = substr($d, 0, 4);
        if ($length < 4 || ! ctype_digit($year) || (int) $year < 1000) {
            return [4, 0];
        }
        if ($length === 4) {
            return [1, (int) $year];
        }
        $month = substr($d, 5, 2);
        if ($length < 7 || $d[4] !== '-' || ! ctype_digit($month) || (int) $month < 1 || (int) $month > 12) {
            return [4, 0];
        }
        if ($length === 7) {
            return [2, (int) $year];
        }
        $day = substr($d, 8, 2);
        if ($length < 10 || $d[7] !== '-' || ! ctype_digit($day) || (int) $day < 1 || (int) $day > 31) {
            return [4, 0];
        }

        return [3, (int) $year];
    }

    public static function usesW67RustFfi(): bool
    {
        $enabled = self::w67RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W67_RUST_FFI_DEFAULT;
    }

    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('enabled', true);
    }

    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('enabled', false);
    }

    /** Drop the W67 override, binding, output buffer and declined-call count; routing returns to W67_RUST_FFI_DEFAULT. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    /**
     * Run $callback with the W67 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('enabled');
        self::w67RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w67Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w67Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w67RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w67', self::W67_FFI_CDEF) ?? false;
            self::w67RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w67RustFfiState('out', $out);
                self::w67RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W67_FFI_CDEF.)
            $written = $ffi->nntmux_w674_isbndb_service_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w67RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W67 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w67Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
