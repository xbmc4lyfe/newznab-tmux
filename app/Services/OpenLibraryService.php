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
use InvalidArgumentException;
use JsonException;
use Throwable;

class OpenLibraryService
{
    /** Disabled until correctness and the common-path and p99 benchmark gates qualify. */
    public const bool W394_RUST_FFI_DEFAULT = false;

    public const string W394_FFI_CDEF = <<<'C'
        ssize_t nntmux_w394_openlibrary_work_editions(const char *input, size_t len, char *out, size_t cap);
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
            $written = $ffi->nntmux_w394_openlibrary_work_editions($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W394_FFI_CDEF.)
                $written = $ffi->nntmux_w394_openlibrary_work_editions($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            $types = ['total_editions' => 'int', 'with_isbn13_count' => 'int', 'with_cover_count' => 'int', 'exact_year_match_count' => 'int', 'earliest_publication_year' => 'int'];
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

    /** @param array<array-key, mixed> $editions
     * @return array<string, int|string|bool>
     */
    public static function summarizeOpenLibraryWorkEditions(array $editions, int $preferredYear = 0): array
    {
        if (self::usesW394RustFfi()) {
            $safe = [];
            foreach ($editions as $row) {
                $row = is_array($row) ? $row : [];
                $isbns = $covers = [];
                foreach (is_array($row['isbn_13'] ?? null) ? $row['isbn_13'] : [] as $isbn) {
                    if (is_string($isbn)) {
                        $isbns[] = $isbn;
                    }
                }
                foreach (is_array($row['covers'] ?? null) ? $row['covers'] : [] as $cover) {
                    if (is_int($cover)) {
                        $covers[] = $cover;
                    }
                }
                $safe[] = ['isbn_13' => $isbns, 'covers' => $covers, 'publish_date' => is_string($row['publish_date'] ?? null) ? $row['publish_date'] : null];
            }
            $native = self::w394Call([$safe, $preferredYear]);
            if ($native !== null) {
                return $native;
            }
        }

        return self::summarizeOpenLibraryWorkEditionsPhp($editions, $preferredYear);
    }

    /** @param array<array-key, mixed> $editions
     * @return array<string, int>
     */
    public static function summarizeOpenLibraryWorkEditionsPhp(array $editions, int $preferredYear = 0): array
    {
        $withIsbn = $withCover = $exact = $earliest = 0;
        foreach ($editions as $row) {
            $row = is_array($row) ? $row : [];
            foreach (is_array($row['isbn_13'] ?? null) ? $row['isbn_13'] : [] as $isbn) {
                if (is_string($isbn) && trim($isbn) !== '') {
                    $withIsbn++;
                    break;
                }
            }
            foreach (is_array($row['covers'] ?? null) ? $row['covers'] : [] as $cover) {
                if (is_int($cover) && $cover > 0) {
                    $withCover++;
                    break;
                }
            }
            $year = 0;
            if (is_string($row['publish_date'] ?? null) && preg_match('/(?<![0-9])[0-9]{4}(?![0-9])/', $row['publish_date'], $match) === 1) {
                $year = (int) $match[0];
            }
            $exact += (int) ($preferredYear > 0 && $year === $preferredYear);
            if ($year > 0 && ($earliest === 0 || $year < $earliest)) {
                $earliest = $year;
            }
        }

        return ['total_editions' => count($editions), 'with_isbn13_count' => $withIsbn, 'with_cover_count' => $withCover, 'exact_year_match_count' => $exact, 'earliest_publication_year' => $earliest];
    }

    protected const SEARCH_URL = 'https://openlibrary.org/search.json';

    protected const ISBN_URL = 'https://openlibrary.org/isbn/';

    /** Null selects the benchmark-qualified production default. */
    public ?bool $useRustFfi = null;

    protected Client $client;

    protected int $limit = 5;

    public function __construct(?Client $client = null)
    {
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

    /**
     * @return array<string, mixed>|null
     */
    public function findByIsbn(string $isbn): ?array
    {
        $isbn = BookIsbn::normalize($isbn);
        if ($isbn === null) {
            return null;
        }

        $cacheKey = 'openlibrary_isbn_'.$isbn;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get(self::ISBN_URL.rawurlencode($isbn).'.json', ['http_errors' => false]);
            if ($response->getStatusCode() === 404) {
                return null;
            }
            if ($response->getStatusCode() !== 200) {
                throw new BookProviderException(
                    'open_library',
                    'Open Library ISBN request failed.',
                    $response->getStatusCode(),
                );
            }

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new BookProviderException('open_library', 'Open Library returned an invalid ISBN payload.', 200);
            }

            $book = $this->normalizeIsbnResult($data, $isbn);
            Cache::put($cacheKey, $book, now()->addHours(24));

            return $book;
        } catch (BookProviderException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new BookProviderException('open_library', 'Open Library returned malformed JSON.', 200, null, $exception);
        } catch (GuzzleException $e) {
            throw new BookProviderException('open_library', 'Open Library ISBN lookup error: '.$e->getMessage(), null, null, $e);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function searchBook(string $query): ?array
    {
        $results = $this->searchBooks($query);

        return $results[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchBooks(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $cacheKey = 'openlibrary_search_'.md5($query.'_'.$this->limit);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->client->get(self::SEARCH_URL, [
                'http_errors' => false,
                'query' => [
                    'q' => $query,
                    'limit' => $this->limit,
                ],
            ]);
            if ($response->getStatusCode() === 404) {
                return [];
            }
            if ($response->getStatusCode() !== 200) {
                throw new BookProviderException(
                    'open_library',
                    'Open Library search request failed.',
                    $response->getStatusCode(),
                );
            }

            $data = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data['docs'] ?? null)) {
                throw new BookProviderException('open_library', 'Open Library returned an invalid search payload.', 200);
            }

            $results = [];
            foreach ($data['docs'] as $doc) {
                if (is_array($doc)) {
                    $results[] = $this->normalizeSearchResult($doc);
                }
            }

            Cache::put($cacheKey, $results, now()->addHours(12));

            return $results;
        } catch (BookProviderException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new BookProviderException('open_library', 'Open Library returned malformed JSON.', 200, null, $exception);
        } catch (GuzzleException $e) {
            throw new BookProviderException('open_library', 'Open Library search error: '.$e->getMessage(), null, null, $e);
        }
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    private function normalizeIsbnResult(array $book, string $isbn): array
    {
        return ($this->useRustFfi ?? false) ? $this->normalizeIsbnResultNative($book, $isbn) : $this->normalizeIsbnResultPhp($book, $isbn);
    }

    /**
     * @param  array<string, mixed>  $book
     * @return array<string, mixed>
     */
    public function normalizeIsbnResultNative(array $book, string $isbn): array
    {
        $parts = GoogleBooksService::providerFields(2, [$book['authors'] ?? null, $book['publish_date'] ?? null], 3);
        if ($parts === null) {
            return $this->normalizeIsbnResultPhp($book, $isbn);
        }

        return $this->normalizeIsbnResultPhp($book, $isbn, [
            'author' => $parts[0],
            'publishdate' => $parts[1] === '1' ? ($parts[2] === '' ? null : $parts[2]) : $this->normalizePublishDate($book['publish_date'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $book
     * @param  array{author: string, publishdate: ?string}|null  $native
     * @return array<string, mixed>
     */
    public function normalizeIsbnResultPhp(array $book, string $isbn, ?array $native = null): array
    {
        $title = (string) ($book['title'] ?? '');
        $authors = [];
        if ($native === null && is_array($book['authors'] ?? null)) {
            foreach ($book['authors'] as $author) {
                if (is_array($author) && isset($author['name'])) {
                    $authors[] = (string) $author['name'];
                }
            }
        }

        $publishDate = $native !== null ? $native['publishdate'] : $this->normalizePublishDate($book['publish_date'] ?? null);
        $coverUrl = '';
        if (is_array($book['covers'] ?? null) && isset($book['covers'][0])) {
            $coverUrl = 'https://covers.openlibrary.org/b/id/'.(int) $book['covers'][0].'-L.jpg';
        }

        return [
            'title' => $title,
            'author' => $native['author'] ?? implode(', ', $authors),
            'asin' => 'openlibrary:'.$isbn,
            'isbn' => strlen($isbn) === 13 ? $isbn : null,
            'ean' => strlen($isbn) === 10 ? $isbn : null,
            'url' => (string) ($book['url'] ?? ''),
            'salesrank' => '',
            'publisher' => is_array($book['publishers'] ?? null) ? (string) ($book['publishers'][0] ?? '') : '',
            'publishdate' => $publishDate,
            'pages' => isset($book['number_of_pages']) && is_numeric($book['number_of_pages']) ? (string) ((int) $book['number_of_pages']) : '',
            'overview' => '',
            'genre' => '',
            'coverurl' => $coverUrl,
            'cover' => $coverUrl !== '' ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function normalizeSearchResult(array $doc): array
    {
        return ($this->useRustFfi ?? false) ? $this->normalizeSearchResultNative($doc) : $this->normalizeSearchResultPhp($doc);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public function normalizeSearchResultNative(array $doc): array
    {
        $parts = GoogleBooksService::providerFields(1, [$doc['isbn'] ?? null, $doc['author_name'] ?? null], 3);
        if ($parts === null) {
            return $this->normalizeSearchResultPhp($doc);
        }

        return $this->normalizeSearchResultPhp($doc, [
            'isbn13' => $parts[0] === '' ? null : $parts[0], 'isbn10' => $parts[1] === '' ? null : $parts[1], 'author' => $parts[2],
        ]);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @param  array{isbn13: ?string, isbn10: ?string, author: string}|null  $native
     * @return array<string, mixed>
     */
    public function normalizeSearchResultPhp(array $doc, ?array $native = null): array
    {
        $isbn13 = $native['isbn13'] ?? null;
        $isbn10 = $native['isbn10'] ?? null;
        if ($native === null && is_array($doc['isbn'] ?? null)) {
            foreach ($doc['isbn'] as $isbn) {
                $normalized = BookIsbn::normalize((string) $isbn);
                if ($normalized === null) {
                    continue;
                }
                if (strlen($normalized) === 13 && $isbn13 === null) {
                    $isbn13 = $normalized;
                } elseif (strlen($normalized) === 10 && $isbn10 === null) {
                    $isbn10 = $normalized;
                }
            }
        }

        $identifier = $isbn13 ?? $isbn10 ?? (string) ($doc['key'] ?? md5((string) json_encode($doc)));
        $authorNames = $native === null && is_array($doc['author_name'] ?? null)
            ? array_values(array_filter(array_map('strval', $doc['author_name'])))
            : [];

        $coverUrl = isset($doc['cover_i']) && is_numeric($doc['cover_i'])
            ? 'https://covers.openlibrary.org/b/id/'.(int) $doc['cover_i'].'-L.jpg'
            : '';

        return [
            'title' => (string) ($doc['title'] ?? ''),
            'author' => $native['author'] ?? implode(', ', $authorNames),
            'asin' => 'openlibrary:'.$identifier,
            'isbn' => $isbn13,
            'ean' => $isbn10,
            'url' => isset($doc['key']) ? 'https://openlibrary.org'.$doc['key'] : '',
            'salesrank' => '',
            'publisher' => is_array($doc['publisher'] ?? null) ? (string) ($doc['publisher'][0] ?? '') : '',
            'publishdate' => isset($doc['first_publish_year']) ? ((int) $doc['first_publish_year']).'-01-01' : null,
            'pages' => '',
            'overview' => '',
            'genre' => '',
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

    // ---------------------------------------------------------------------
    // Wave 51.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.10x, p99 1.94x; uncommon mean 2.29x, p99 1.87x); see RustIsbnOpenLibPar2ListProcessorNfoW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w514_openlibrary_edition_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Edition flags returned by editionBatch(). */
    public const int W51_ISBN13 = 1;

    public const int W51_COVER = 2;

    public const int W51_EXACT_YEAR = 4;

    public const int W51_EARLIEST = 8;

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
     * Classify Open Library editions, two ints each: [publication year or 0, flags]. The year
     * is the first standalone four-digit run in the publish date (0000 counts as none). Flags:
     * 1 trimmed first ISBN-13 is non-empty, 2 first cover id is positive, 4 year equals a
     * positive $preferredYear, 8 the first edition with the earliest positive year. These are
     * the per-edition terms summarizeOpenLibraryWorkEditionsPhp() counts.
     *
     * @param  list<int>  $covers
     * @param  list<string>  $publishDates
     * @param  list<string>  $isbn13s
     * @return list<int>
     */
    public static function editionBatch(int $preferredYear, array $covers, array $publishDates, array $isbn13s): array
    {
        return self::usesW51RustFfi()
            ? self::editionBatchNative($preferredYear, $covers, $publishDates, $isbn13s)
            : self::editionBatchPhp($preferredYear, $covers, $publishDates, $isbn13s);
    }

    /**
     * @param  list<int>  $covers
     * @param  list<string>  $publishDates
     * @param  list<string>  $isbn13s
     * @return list<int>
     */
    public static function editionBatchNative(int $preferredYear, array $covers, array $publishDates, array $isbn13s): array
    {
        self::w51AssertColumns($covers, $publishDates, $isbn13s);
        if ($covers === []) {
            return [];
        }

        return self::w51Call(implode("\0", $publishDates)."\0".implode("\0", $isbn13s), pack('P*', $preferredYear, ...$covers), \count($covers))
            ?? self::editionBatchPhp($preferredYear, $covers, $publishDates, $isbn13s);
    }

    /**
     * @param  list<int>  $covers
     * @param  list<string>  $publishDates
     * @param  list<string>  $isbn13s
     * @return list<int>
     */
    public static function editionBatchPhp(int $preferredYear, array $covers, array $publishDates, array $isbn13s): array
    {
        self::w51AssertColumns($covers, $publishDates, $isbn13s);
        $out = [];
        $earliest = 0;
        $earliestAt = -1;
        foreach ($covers as $i => $cover) {
            $year = preg_match('/(?<![0-9])[0-9]{4}(?![0-9])/', $publishDates[$i], $match) === 1 ? (int) $match[0] : 0;
            $flags = trim($isbn13s[$i]) !== '' ? self::W51_ISBN13 : 0;
            if ($cover > 0) {
                $flags |= self::W51_COVER;
            }
            if ($preferredYear > 0 && $year === $preferredYear) {
                $flags |= self::W51_EXACT_YEAR;
            }
            if ($year > 0 && ($earliest === 0 || $year < $earliest)) {
                $earliest = $year;
                $earliestAt = $i;
            }
            $out[] = $year;
            $out[] = $flags;
        }
        if ($earliestAt >= 0) {
            $out[$earliestAt * 2 + 1] |= self::W51_EARLIEST;
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined publish dates then ISBN-13s, little-endian int64 preferred year and cover ids, two int64 words back per record. Null when
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
            $need = $count * 2;
            $out = self::$w51Out;
            if ($out === null || self::$w51OutCap < $need) {
                self::$w51OutCap = max(1024, $need);
                $out = self::$w51Out = $ffi->new('int64_t['.self::$w51OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
            $written = $ffi->nntmux_w514_openlibrary_edition_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w51OutCap);
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
    private static function w51AssertColumns(array ...$columns): void
    {
        $count = \count($columns[0]);
        foreach ($columns as $column) {
            if (\count($column) !== $count || ! array_is_list($column)) {
                throw new InvalidArgumentException('W51 batch columns must be lists of equal length.');
            }
        }
    }
}
