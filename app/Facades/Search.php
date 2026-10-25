<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\Search\SearchService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * Search Facade - provides static access to the SearchService.
 *
 * @method static bool isAutocompleteEnabled()
 * @method static bool isSuggestEnabled()
 * @method static string getReleasesIndex()
 * @method static string getPredbIndex()
 * @method static void insertRelease(array $parameters)
 * @method static void updateRelease(int|string $releaseID)
 * @method static mixed deferReleaseUpdates(callable $work)
 * @method static void deleteRelease(int $id)
 * @method static void deleteReleases(iterable<int|string> $ids)
 * @method static void insertPredb(array $parameters)
 * @method static void updatePreDb(array $parameters)
 * @method static void deletePreDb(int $id)
 * @method static array searchReleases(array|string $phrases, int $limit = 1000)
 * @method static bool supportsReleaseCandidatePaging()
 * @method static array{ids: list<int>, available: bool, has_more: bool} searchReleaseCandidatesPage(string $title, int $limit = 21, int $afterId = 0)
 * @method static array searchReleasesWithFuzzy(array|string $phrases, int $limit = 1000, bool $forceFuzzy = false)
 * @method static array fuzzySearchReleases(array|string $phrases, int $limit = 1000)
 * @method static bool isFuzzyEnabled()
 * @method static array getFuzzyConfig()
 * @method static array searchPredb(array|string $searchTerm)
 * @method static array{id: int, title: string, filename: string, source: string}|null matchPredbExact(string $name)
 * @method static array autocomplete(string $query, ?string $index = null)
 * @method static array suggest(string $query, ?string $index = null)
 * @method static bool isAvailable()
 * @method static string getCurrentDriver()
 * @method static string escapeString(string $string)
 * @method static void truncateIndex(array|string $indexes)
 * @method static void optimizeIndex()
 * @method static array bulkInsertReleases(array $releases)
 * @method static array searchReleasesByExternalId(array $externalIds, int $limit = 1000)
 * @method static array searchReleasesByCategory(array $categoryIds, int $limit = 1000)
 * @method static array searchReleasesWithCategoryFilter(string $searchTerm, array $categoryIds = [], int $limit = 1000)
 * @method static array searchReleasesFiltered(array $criteria, int $limit, int $offset = 0)
 * @method static void insertSecondary(\App\Enums\SecondarySearchIndex $index, int $id, array $document)
 * @method static void updateSecondary(\App\Enums\SecondarySearchIndex $index, int $id)
 * @method static void deleteSecondary(\App\Enums\SecondarySearchIndex $index, int $id)
 * @method static array bulkInsertSecondary(\App\Enums\SecondarySearchIndex $index, array $documents)
 * @method static array searchSecondary(\App\Enums\SecondarySearchIndex $index, string $query, int $limit = 100)
 * @method static array searchAnimeTitle(string $query, int $limit = 100)
 * @method static array searchMoviesByFields(array $fieldTerms, int $limit = 5000)
 * @method static list<array{id: int, title: string, filename: string, source: string}>|null searchPredbFilenameCandidates(string $searchTerm)
 *
 * @see SearchService
 */
class Search extends Facade // @phpstan-ignore missingType.iterableValue
{
    public const string FFI_CDEF = 'ptrdiff_t nntmux_w38_search_facade_controller_guest_layout(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchFacadeControllerGuestLayoutParityTest.php.
     */
    public const bool FACADE_RUST_FFI_DEFAULT = false;

    private const int SEARCH_DEFAULT_LIMIT = 1000;

    private const int SEARCH_MAX_LIMIT = 10000;

    private const int NATIVE_MAX_PARTS = 4096;

    private const int NATIVE_MAX_BYTES = 16 * 1024 * 1024;

    private static ?bool $useFacadeRustFfi = null;

    private static FFI|false|null $helperFfi = null;

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return SearchService::class;
    }

    public static function useFacadeRustFfi(?bool $use = true): void
    {
        self::$useFacadeRustFfi = $use;
    }

    public static function usesFacadeRustFfi(): bool
    {
        return self::$useFacadeRustFfi ?? self::FACADE_RUST_FFI_DEFAULT;
    }

    public static function resetFacadeRustFfi(): void
    {
        self::$useFacadeRustFfi = null;
    }

    /**
     * Normalizes search phrases before they reach the search driver: whitespace runs collapse to one
     * space, blank phrases are dropped and case-insensitive (ASCII) duplicates keep the first spelling.
     * A non-positive limit becomes 1000 and larger limits are capped at 10000.
     *
     * @param  array<array-key, string>|string  $phrases
     * @return array{phrases: list<string>, query: string, limit: int, empty: int, duplicates: int}
     */
    public static function planSearchPhrases(array|string $phrases, int $limit = 1000): array
    {
        return self::usesFacadeRustFfi()
            ? self::planSearchPhrasesNative($phrases, $limit)
            : self::planSearchPhrasesPhp($phrases, $limit);
    }

    /**
     * @param  array<array-key, string>|string  $phrases
     * @return array{phrases: list<string>, query: string, limit: int, empty: int, duplicates: int}
     */
    private static function planSearchPhrasesNative(array|string $phrases, int $limit): array
    {
        $items = \is_string($phrases) ? [$phrases] : array_values($phrases);
        $fields = self::evaluateNative(1, [\is_string($phrases) ? '0' : '1', (string) $limit, ...$items]);
        if ($fields === null || \count($fields) < 4) {
            return self::planSearchPhrasesPhp($phrases, $limit);
        }

        return [
            'phrases' => \array_slice($fields, 4),
            'query' => $fields[3],
            'limit' => (int) $fields[0],
            'empty' => (int) $fields[1],
            'duplicates' => (int) $fields[2],
        ];
    }

    /**
     * @param  array<array-key, string>|string  $phrases
     * @return array{phrases: list<string>, query: string, limit: int, empty: int, duplicates: int}
     */
    private static function planSearchPhrasesPhp(array|string $phrases, int $limit): array
    {
        $kept = [];
        $seen = [];
        $empty = 0;
        $duplicates = 0;
        foreach (\is_string($phrases) ? [$phrases] : $phrases as $phrase) {
            $phrase = self::collapseWhitespace($phrase);
            if ($phrase === '') {
                $empty++;
            } elseif (isset($seen[$key = strtolower($phrase)])) {
                $duplicates++;
            } else {
                $seen[$key] = true;
                $kept[] = $phrase;
            }
        }

        return [
            'phrases' => $kept,
            'query' => implode(' ', $kept),
            'limit' => $limit < 1 ? self::SEARCH_DEFAULT_LIMIT : min($limit, self::SEARCH_MAX_LIMIT),
            'empty' => $empty,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Collapses runs of ASCII whitespace and NUL bytes into single spaces and trims the ends.
     */
    public static function collapseWhitespace(string $value): string
    {
        $words = preg_split('/[ \t\n\r\x00\x0B\x0C]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? '' : implode(' ', $words);
    }

    /**
     * Shared Wave 38.5 bridge to `nntmux_w38_search_facade_controller_guest_layout`. Returns the
     * decoded result fields, or null when the library is missing, the input is too large or Rust
     * declines (callers then use their PHP implementation).
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateNative(int $mode, array $parts): ?array
    {
        $ffi = self::helperFfi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = \strlen($input) * 2 + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w38_search_facade_controller_guest_layout($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackNative(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function unpackNative(string $packet): ?array
    {
        $length = \strlen($packet);
        $header = unpack('V', $packet);
        $count = \is_array($header) ? $header[1] : null;
        if (! \is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $size = unpack('V', $packet, $offset);
            $size = \is_array($size) ? $size[1] : null;
            if (! \is_int($size) || $offset + 4 + $size > $length) {
                return null;
            }
            $parts[] = substr($packet, $offset + 4, $size);
            $offset += 4 + $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function helperFfi(): ?FFI
    {
        if (self::$helperFfi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$helperFfi = RustPipelineLibrary::resolveDomain('search_w385', self::FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$helperFfi instanceof FFI ? self::$helperFfi : null;
    }
}
