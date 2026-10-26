<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BookProviderException;
use App\Support\BookIsbn;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use FFI\CData;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Throwable;

class GoogleBooksService
{
    protected const API_URL = 'https://www.googleapis.com/books/v1/volumes';

    public const NORMALIZER_FFI_CDEF = <<<'C'
        ssize_t nntmux_google_books_query_nul(const char *input, size_t len, char *out, size_t cap);
        ssize_t nntmux_book_provider_fields(uint32_t op, const char *input, size_t len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default. */
    public ?bool $useRustFfi = null;

    private static FFI|false|null $normalizerFfi = null;

    protected Client $client;

    protected ?string $apiKey;

    protected int $maxResults = 5;

    public function __construct(?Client $client = null, ?string $apiKey = null)
    {
        $this->apiKey = trim($apiKey ?? (string) config('nntmux_api.google_books_api_key', '')) ?: null;

        $this->client = $client ?? new Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'NNTmux/1.0',
            ],
        ]);
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIsbn(string $isbn): ?array
    {
        $books = $this->searchBooks('', null, null, $isbn);

        return $books[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchBook(string $query, ?string $title = null, ?string $author = null): ?array
    {
        $books = $this->searchBooks($query, $title, $author);

        return $books[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchBooks(string $query, ?string $title = null, ?string $author = null, ?string $isbn = null): array
    {
        $query = trim($query);
        $title = $title !== null ? trim($title) : null;
        $author = $author !== null ? trim($author) : null;
        $isbn = BookIsbn::normalize($isbn);

        $searchQuery = $this->buildSearchQuery($query, $title, $author, $isbn);
        if ($searchQuery === '') {
            return [];
        }

        $cacheKey = 'google_books_search_'.md5($searchQuery.'_'.$this->maxResults.'_'.$this->apiKey);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request([
            'q' => $searchQuery,
            'maxResults' => $this->maxResults,
            'printType' => 'books',
        ]);
        if (! is_array($response['items'] ?? null)) {
            return [];
        }

        $books = [];
        foreach ($response['items'] as $item) {
            if (is_array($item)) {
                $books[] = $this->normalizeBookResult($item);
            }
        }

        Cache::put($cacheKey, $books, now()->addHours(12));

        return $books;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    protected function request(array $query): ?array
    {
        try {
            if ($this->apiKey !== null) {
                $query['key'] = $this->apiKey;
            }

            $response = $this->client->get(self::API_URL, [
                'http_errors' => false,
                'query' => $query,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new BookProviderException(
                    'google_books',
                    'Google Books API request failed.',
                    $response->getStatusCode(),
                );
            }

            $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new BookProviderException('google_books', 'Google Books returned an invalid response payload.', 200);
            }

            return $decoded;
        } catch (BookProviderException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new BookProviderException(
                'google_books',
                'Google Books returned malformed JSON.',
                200,
                null,
                $exception,
            );
        } catch (GuzzleException $e) {
            throw new BookProviderException(
                'google_books',
                'Google Books API request error: '.$e->getMessage(),
                null,
                null,
                $e,
            );
        }
    }

    public static function normalizerFfi(): ?FFI
    {
        if (self::$normalizerFfi === null) {
            self::$normalizerFfi = RustPipelineLibrary::resolveDomain('metadata', self::NORMALIZER_FFI_CDEF) ?? false;
        }

        return self::$normalizerFfi === false ? null : self::$normalizerFfi;
    }

    /**
     * Return null when Rust declines or the library is unavailable.
     *
     * @param  callable(FFI, CData, int): mixed  $call
     */
    public static function normalizerString(callable $call, int $hint): ?string
    {
        $ffi = self::normalizerFfi();
        if ($ffi === null) {
            return null;
        }
        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $hint, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $call($ffi, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $call($ffi, $buffer, $capacity);
            }

            return is_int($written) && $written >= 0 && $written <= $capacity ? FFI::string($buffer, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Split a NUL-terminated field frame from `nntmux_book_provider_fields`.
     *
     * @param  array<int, mixed>  $values
     * @return list<string>|null
     */
    public static function providerFields(int $operation, array $values, int $count): ?array
    {
        try {
            $input = serialize($values);
        } catch (Throwable) {
            return null;
        }
        $result = self::normalizerString(
            // @phpstan-ignore method.notFound
            static fn (FFI $ffi, CData $buffer, int $capacity): mixed => $ffi->nntmux_book_provider_fields($operation, $input, strlen($input), $buffer, $capacity),
            strlen($input) + 256,
        );
        if ($result === null) {
            return null;
        }
        $parts = explode("\0", $result);

        return count($parts) === $count + 1 && array_pop($parts) === '' ? $parts : null;
    }

    private function buildSearchQuery(string $query = '', ?string $title = null, ?string $author = null, ?string $isbn = null): string
    {
        return ($this->useRustFfi ?? false)
            ? $this->buildSearchQueryNative($query, $title, $author, $isbn)
            : $this->buildSearchQueryPhp($query, $title, $author, $isbn);
    }

    public function buildSearchQueryNative(string $query = '', ?string $title = null, ?string $author = null, ?string $isbn = null): string
    {
        $input = $query."\0".$title."\0".$author."\0".$isbn."\0";
        if (substr_count($input, "\0") === 4) {
            $result = self::normalizerString(
                // @phpstan-ignore method.notFound
                static fn (FFI $ffi, CData $buffer, int $capacity): mixed => $ffi->nntmux_google_books_query_nul($input, strlen($input), $buffer, $capacity),
                strlen($input) + 32,
            );
            if ($result !== null) {
                return $result;
            }
        }

        return $this->buildSearchQueryPhp($query, $title, $author, $isbn);
    }

    public function buildSearchQueryPhp(string $query = '', ?string $title = null, ?string $author = null, ?string $isbn = null): string
    {
        if ($isbn !== null && $isbn !== '') {
            return 'isbn:'.$isbn;
        }

        $parts = [];
        if ($title !== null && $title !== '') {
            $parts[] = 'intitle:'.$title;
        }
        if ($author !== null && $author !== '') {
            $parts[] = 'inauthor:'.$author;
        }

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeBookResult(array $item): array
    {
        return ($this->useRustFfi ?? false) ? $this->normalizeBookResultNative($item) : $this->normalizeBookResultPhp($item);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function normalizeBookResultNative(array $item): array
    {
        $volumeInfo = is_array($item['volumeInfo'] ?? null) ? $item['volumeInfo'] : [];
        $parts = self::providerFields(0, [
            $volumeInfo['industryIdentifiers'] ?? null, $volumeInfo['authors'] ?? null,
            $volumeInfo['categories'] ?? null, $volumeInfo['description'] ?? null, $volumeInfo['publishedDate'] ?? null,
        ], 7);
        if ($parts === null) {
            return $this->normalizeBookResultPhp($item);
        }

        return $this->normalizeBookResultPhp($item, [
            'isbn13' => $parts[0], 'isbn10' => $parts[1], 'author' => $parts[2], 'genre' => $parts[3], 'overview' => $parts[4],
            'publishdate' => $parts[5] === '1' ? ($parts[6] === '' ? null : $parts[6]) : $this->normalizePublishDate($volumeInfo['publishedDate'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{isbn13: string, isbn10: string, author: string, genre: string, overview: string, publishdate: ?string}|null  $native
     * @return array<string, mixed>
     */
    public function normalizeBookResultPhp(array $item, ?array $native = null): array
    {
        $volumeInfo = is_array($item['volumeInfo'] ?? null) ? $item['volumeInfo'] : [];
        $industryIdentifiers = $native === null && is_array($volumeInfo['industryIdentifiers'] ?? null) ? $volumeInfo['industryIdentifiers'] : [];

        $isbn13 = $native['isbn13'] ?? '';
        $isbn10 = $native['isbn10'] ?? '';
        foreach ($industryIdentifiers as $identifier) {
            if (! is_array($identifier)) {
                continue;
            }
            $type = (string) ($identifier['type'] ?? '');
            $value = BookIsbn::normalize((string) ($identifier['identifier'] ?? ''));
            if ($type === 'ISBN_13' && $value !== null && strlen($value) === 13) {
                $isbn13 = $value;
            }
            if ($type === 'ISBN_10' && $value !== null && strlen($value) === 10) {
                $isbn10 = $value;
            }
        }

        $identifier = $isbn13 !== '' ? $isbn13 : ($isbn10 !== '' ? $isbn10 : (string) ($item['id'] ?? md5(json_encode($item) ?: '')));
        $authors = $native === null && is_array($volumeInfo['authors'] ?? null)
            ? array_values(array_filter(array_map('strval', $volumeInfo['authors'])))
            : [];
        $categories = $native === null && is_array($volumeInfo['categories'] ?? null)
            ? array_values(array_filter(array_map('strval', $volumeInfo['categories'])))
            : [];
        $imageLinks = is_array($volumeInfo['imageLinks'] ?? null) ? $volumeInfo['imageLinks'] : [];
        $coverUrl = (string) ($imageLinks['thumbnail'] ?? $imageLinks['smallThumbnail'] ?? '');

        return [
            'title' => (string) ($volumeInfo['title'] ?? ''),
            'author' => $native['author'] ?? implode(', ', $authors),
            'asin' => 'googlebooks:'.$identifier,
            'isbn' => $isbn13 !== '' ? $isbn13 : null,
            'ean' => $isbn10 !== '' ? $isbn10 : null,
            'url' => (string) ($volumeInfo['infoLink'] ?? ''),
            'salesrank' => '',
            'publisher' => (string) ($volumeInfo['publisher'] ?? ''),
            'publishdate' => $native !== null ? $native['publishdate'] : $this->normalizePublishDate($volumeInfo['publishedDate'] ?? null),
            'pages' => isset($volumeInfo['pageCount']) && is_numeric($volumeInfo['pageCount']) ? (string) ((int) $volumeInfo['pageCount']) : '',
            'overview' => $native['overview'] ?? strip_tags((string) ($volumeInfo['description'] ?? '')),
            'genre' => $native['genre'] ?? implode(', ', $categories),
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

    /** Enabled: five paired runs cleared 1.5x on every gate (min common mean 3.40x, p99 2.54x; uncommon mean 11.41x, p99 9.11x; see the W43 parity test). */
    public const bool W434_RUST_FFI_DEFAULT = true;

    public const string W434_FFI_CDEF = <<<'C'
        ssize_t nntmux_w434_book_identifier_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W434_SUMMARY_KEYS = ['total_identifiers', 'empty_identifier_count', 'isbn13_count', 'isbn10_count', 'invalid_checksum_count', 'non_isbn_count', 'hyphenated_count', 'prefixed_count'];

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
     * Summarize industry identifiers as BookIsbn::normalizePhp() sees them:
     * [0-9X] bytes (case-insensitive) forming a valid ISBN-13 (978/979) or
     * ISBN-10, a 10/13-byte candidate failing its checksum, or another
     * non-empty value; plus hyphens and a leading "isbn" (case-insensitive).
     * Entries come from strings, integers, or the identifier or isbn array
     * field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeBookIdentifierBatch(array $entries): array
    {
        return self::usesW434RustFfi()
            ? self::summarizeBookIdentifierBatchNative($entries)
            : self::summarizeBookIdentifierBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeBookIdentifierBatchNative(array $entries): array
    {
        return self::w434Call(self::w434Entries($entries)) ?? self::summarizeBookIdentifierBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeBookIdentifierBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W434_SUMMARY_KEYS, 0);
        foreach (self::w434Entries($entries) as $entry) {
            $summary['total_identifiers']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_identifier_count']++;

                continue;
            }
            $isbn = strtoupper((string) preg_replace('/[^0-9X]/i', '', $entry));
            $length = strlen($isbn);
            $isbn13 = false;
            $isbn10 = false;
            if ($length === 13 && preg_match('/^97[89]\d{10}\z/', $isbn) === 1) {
                $sum = 0;
                for ($index = 0; $index < 13; $index++) {
                    $sum += ((int) $isbn[$index]) * ($index % 2 === 0 ? 1 : 3);
                }
                $isbn13 = $sum % 10 === 0;
            } elseif ($length === 10 && preg_match('/^\d{9}[\dX]\z/', $isbn) === 1) {
                $sum = 0;
                for ($index = 0; $index < 10; $index++) {
                    $sum += ($isbn[$index] === 'X' ? 10 : (int) $isbn[$index]) * (10 - $index);
                }
                $isbn10 = $sum % 11 === 0;
            }
            $shaped = $length === 10 || $length === 13;
            $summary['isbn13_count'] += (int) $isbn13;
            $summary['isbn10_count'] += (int) $isbn10;
            $summary['invalid_checksum_count'] += (int) ($shaped && ! $isbn13 && ! $isbn10);
            $summary['non_isbn_count'] += (int) ! $shaped;
            $summary['hyphenated_count'] += (int) str_contains($entry, '-');
            $summary['prefixed_count'] += (int) (strncasecmp($entry, 'isbn', 4) === 0);
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
                $item = $item['identifier'] ?? $item['isbn'] ?? null;
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
            $written = $ffi->nntmux_w434_book_identifier_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W434_FFI_CDEF.)
                $written = $ffi->nntmux_w434_book_identifier_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W434_SUMMARY_KEYS || $result['total_identifiers'] !== count($entries)) {
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

    /** C signature of the Wave 61.5 {@see self::lookupPlanBatch()} helper. */
    private const string W61_GOOGLE_BOOKS_PLAN_FFI_CDEF = 'int64_t nntmux_w615_google_books_lookup_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 61.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 6.29/4.98/3.54/3.14x); see tests/Unit/RustGoogleBooksIgdbImdbScraperItunesLogIndexerW61ParityTest.php.
     */
    public const bool W61_RUST_FFI_DEFAULT = true;

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
                    $ffi = RustPipelineLibrary::resolveDomain('w61_5', self::W61_GOOGLE_BOOKS_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
            $written = $ffi->nntmux_w615_google_books_lookup_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch Google Books lookup plan over `query\ttitle\tauthor\tisbn\tcandidate_title\tcandidate_author\tmax_results`.
     * Eight words per record: `[mode, query_len, isbn_kind, title_score, author_score, max_results, title_tokens, flags]`.
     * The ISBN keeps `[0-9Xx]` bytes (uppercased) and is 10 or 13 when it passes its checksum, else 0. Mode follows
     * buildSearchQueryPhp() over the trimmed fields: 1 `isbn:`, 2 `intitle:`/`inauthor:`, 3 plain query, 0 empty; the
     * query length is that built string's. Scores are `100 * matched / tokens` of the title (the query without one)
     * against the candidate title and of the author against the candidate author; tokens are ASCII alphanumeric or
     * non-ASCII runs compared ASCII case-insensitively. Max results (empty 10) are 1-3 digits clamped to 1..40.
     * Flags: 1 empty request, 2 invalid ISBN, 4 formatted ISBN, 8 clamped max results, 16 invalid max results,
     * 32 exact title match, 64 empty candidate title, 128 non-ASCII title or author, 256 not exactly seven fields.
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
            $flags = \count($fields) === 7 ? 0 : 256;
            $query = trim($fields[0]);
            $title = trim($fields[1] ?? '');
            $author = trim($fields[2] ?? '');
            $rawIsbn = $fields[3] ?? '';
            $kept = strtoupper((string) preg_replace('/[^0-9Xx]+/', '', $rawIsbn));
            $kind = self::w61IsbnKind($kept);
            $trimmedIsbn = trim($rawIsbn);
            if ($kind === 0 && $trimmedIsbn !== '') {
                $flags |= 2;
            }
            if ($kind !== 0 && \strlen($kept) !== \strlen($trimmedIsbn)) {
                $flags |= 4;
            }
            if ($kind !== 0) {
                [$mode, $queryLength] = [1, 5 + $kind];
            } elseif ($title !== '' || $author !== '') {
                $queryLength = ($title !== '' ? 8 + \strlen($title) : 0) + ($author !== '' ? 9 + \strlen($author) : 0) + ($title !== '' && $author !== '' ? 1 : 0);
                $mode = 2;
            } elseif ($query !== '') {
                [$mode, $queryLength] = [3, \strlen($query)];
            } else {
                [$mode, $queryLength] = [0, 0];
                $flags |= 1;
            }
            $needle = self::w61Tokens($title !== '' ? $title : $query);
            $candidate = self::w61Tokens($fields[4] ?? '');
            [$matched, $titleTokens] = self::w61Overlap($needle, $candidate);
            $titleScore = $titleTokens > 0 ? intdiv(100 * $matched, $titleTokens) : 0;
            [$matched, $authorTokens] = self::w61Overlap(self::w61Tokens($author), self::w61Tokens($fields[5] ?? ''));
            $authorScore = $authorTokens > 0 ? intdiv(100 * $matched, $authorTokens) : 0;
            $max = 10;
            $text = trim($fields[6] ?? '');
            if ($text !== '') {
                $value = self::w61Digits($text, 3);
                if ($value === null) {
                    $flags |= 16;
                } elseif ($value < 1 || $value > 40) {
                    $max = max(1, min(40, $value));
                    $flags |= 8;
                } else {
                    $max = $value;
                }
            }
            if ($needle !== [] && $needle === $candidate) {
                $flags |= 32;
            }
            if (trim($fields[4] ?? '') === '') {
                $flags |= 64;
            }
            if (preg_match('/[\x80-\xff]/', $title.$author) === 1) {
                $flags |= 128;
            }
            array_push($out, $mode, $queryLength, $kind, $titleScore, $authorScore, $max, $titleTokens, $flags);
        }

        return $out;
    }

    /** 13 or 10 for kept ISBN bytes passing their checksum (ISBN-13 with a 978/979 prefix), else 0. */
    private static function w61IsbnKind(string $isbn): int
    {
        if (\strlen($isbn) === 10 && preg_match('/^\d{9}[\dX]$/D', $isbn) === 1) {
            $sum = 0;
            for ($index = 0; $index < 10; $index++) {
                $sum += ($isbn[$index] === 'X' ? 10 : (int) $isbn[$index]) * (10 - $index);
            }

            return $sum % 11 === 0 ? 10 : 0;
        }
        if (\strlen($isbn) === 13 && preg_match('/^97[89]\d{10}$/D', $isbn) === 1) {
            $sum = 0;
            for ($index = 0; $index < 13; $index++) {
                $sum += (int) $isbn[$index] * ($index % 2 === 0 ? 1 : 3);
            }

            return $sum % 10 === 0 ? 13 : 0;
        }

        return 0;
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

    /**
     * Needle tokens found among the haystack tokens and the needle token count.
     *
     * @param  list<string>  $needle
     * @param  list<string>  $haystack
     * @return array{int, int}
     */
    private static function w61Overlap(array $needle, array $haystack): array
    {
        $set = array_flip($haystack);
        $matched = 0;
        foreach ($needle as $token) {
            if (isset($set[$token])) {
                $matched++;
            }
        }

        return [$matched, \count($needle)];
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

        return self::w61Words($count, implode("\0", $records), 8 * $count) ?? self::lookupPlanBatchPhp($records);
    }
}
