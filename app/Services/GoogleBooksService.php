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
}
