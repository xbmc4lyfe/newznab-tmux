<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Genre;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

class GenreService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_genre_service_cache_key(const char *type_ptr, size_t type_len, uint32_t active_only, char *out, size_t cap);
        ssize_t nntmux_genre_service_normalize_map(const char *ser_ptr, size_t ser_len, char *out, size_t cap);
        C;

    public const int CONSOLE_TYPE = Category::GAME_ROOT;

    public const int MUSIC_TYPE = Category::MUSIC_ROOT;

    public const int GAME_TYPE = Category::PC_ROOT;

    public const int STATUS_ENABLED = 0;

    public const int STATUS_DISABLED = 1;

    /** Null selects the benchmark-qualified production default (PHP; see RustGenreResourcesMetadataConfigParityTest). */
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

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Build the cache key used by `getGenres()`.
     */
    public static function buildGenresCacheKey(string $type = '', bool $activeOnly = false): string
    {
        return self::usesRustFfi()
            ? self::buildGenresCacheKeyNative($type, $activeOnly)
            : self::buildGenresCacheKeyPhp($type, $activeOnly);
    }

    public static function buildGenresCacheKeyNative(string $type = '', bool $activeOnly = false): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::buildGenresCacheKeyPhp($type, $activeOnly);
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return self::buildGenresCacheKeyPhp($type, $activeOnly);
        }
        try {
            $typeLen = \strlen($type);
            $activeFlag = $activeOnly ? 1 : 0;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_genre_service_cache_key($type, $typeLen, $activeFlag, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::buildGenresCacheKeyPhp($type, $activeOnly);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_genre_service_cache_key($type, $typeLen, $activeFlag, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::buildGenresCacheKeyPhp($type, $activeOnly);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::buildGenresCacheKeyPhp($type, $activeOnly);
        }
    }

    public static function buildGenresCacheKeyPhp(string $type = '', bool $activeOnly = false): string
    {
        return 'genres_'.md5($type.'_'.($activeOnly ? '1' : '0'));
    }

    /**
     * Get genres with optional filtering and caching.
     */
    public function getGenres(string $type = '', bool $activeOnly = false): Collection // @phpstan-ignore missingType.generics
    {
        return self::usesRustFfi()
            ? $this->getGenresNative($type, $activeOnly)
            : $this->getGenresPhp($type, $activeOnly);
    }

    public function getGenresNative(string $type = '', bool $activeOnly = false): Collection // @phpstan-ignore missingType.generics
    {
        $cacheKey = self::buildGenresCacheKeyNative($type, $activeOnly);

        return Cache::remember($cacheKey, now()->addMinutes(config('nntmux.cache_expiry_long')), function () use ($type, $activeOnly) {
            return Genre::getFiltered($type, $activeOnly);
        });
    }

    public function getGenresPhp(string $type = '', bool $activeOnly = false): Collection // @phpstan-ignore missingType.generics
    {
        $cacheKey = self::buildGenresCacheKeyPhp($type, $activeOnly);

        return Cache::remember($cacheKey, now()->addMinutes(config('nntmux.cache_expiry_long')), function () use ($type, $activeOnly) {
            return Genre::getFiltered($type, $activeOnly);
        });
    }

    /**
     * Normalize an iterable of genres into an associative map (`id => strtolower(title)`).
     *
     * @param  iterable<mixed>  $genres
     * @return array<int|string, string>
     */
    public static function normalizeGenresMap(iterable $genres): array
    {
        return self::usesRustFfi()
            ? self::normalizeGenresMapNative($genres)
            : self::normalizeGenresMapPhp($genres);
    }

    /**
     * @param  iterable<mixed>  $genres
     * @return array<int|string, string>
     */
    public static function normalizeGenresMapNative(iterable $genres): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeGenresMapPhp($genres);
        }
        try {
            $rows = [];
            foreach ($genres as $genre) {
                if (\is_array($genre)) {
                    if (! \array_key_exists('id', $genre) || ! \is_string($genre['title'] ?? null)) {
                        return self::normalizeGenresMapPhp($genres);
                    }
                    $id = $genre['id'];
                    $title = $genre['title'];
                } elseif (\is_object($genre)) {
                    $id = $genre->id ?? null;
                    $title = $genre->title ?? null;
                    if (($id === null && ! isset($genre->id) && ! property_exists($genre, 'id')) || ! \is_string($title)) {
                        return self::normalizeGenresMapPhp($genres);
                    }
                } else {
                    return self::normalizeGenresMapPhp($genres);
                }
                if (\is_object($id) || \is_array($id) || \is_float($id)) {
                    return self::normalizeGenresMapPhp($genres);
                }
                $rows[] = ['id' => $id, 'title' => $title];
            }
            if ($rows === []) {
                return [];
            }
            $ser = serialize($rows);
            $serLen = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $serLen + 64), $capacity);
            if ($buffer === null) {
                return self::normalizeGenresMapPhp($genres);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_genre_service_normalize_map($ser, $serLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeGenresMapPhp($genres);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_genre_service_normalize_map($ser, $serLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizeGenresMapPhp($genres);
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<int|string, string> $result */
            $result = \is_array($decoded) ? $decoded : self::normalizeGenresMapPhp($genres);

            return $result;
        } catch (Throwable) {
            return self::normalizeGenresMapPhp($genres);
        }
    }

    /**
     * @param  iterable<mixed>  $genres
     * @return array<int|string, string>
     */
    public static function normalizeGenresMapPhp(iterable $genres): array
    {
        $genresArray = [];
        foreach ($genres as $genre) {
            if (\is_array($genre)) {
                $id = $genre['id'];
                $title = (string) $genre['title'];
            } else {
                /** @var Genre $genre */
                $id = $genre->id;
                $title = $genre->title;
            }
            $genresArray[$id] = strtolower($title);
        }

        return $genresArray;
    }

    /**
     * Load genres as an associative array (id => lowercase title).
     *
     * @return array<string, mixed>
     */
    public function loadGenres(string $type): array
    {
        return self::usesRustFfi()
            ? $this->loadGenresNative($type)
            : $this->loadGenresPhp($type);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadGenresNative(string $type): array
    {
        /** @var array<string, mixed> */
        return self::normalizeGenresMapNative($this->getGenresNative($type));
    }

    /**
     * @return array<string, mixed>
     */
    public function loadGenresPhp(string $type): array
    {
        /** @var array<string, mixed> */
        return self::normalizeGenresMapPhp($this->getGenresPhp($type));
    }

    /**
     * Get a range of genres with pagination.
     *
     * @return array<int, string>
     */
    public function getRange(int $start, int $num, string $type = '', bool $activeOnly = false): Collection // @phpstan-ignore missingType.generics
    {
        return Genre::getFiltered($type, $activeOnly)
            ->skip($start)
            ->take($num);
    }

    /**
     * Get count of genres.
     */
    public function getCount(string $type = '', bool $activeOnly = false): int
    {
        return Genre::getFilteredCount($type, $activeOnly);
    }

    /**
     * Get genre by ID.
     */
    public function getById(int $id): ?Genre
    {
        return Genre::find($id);
    }

    /**
     * Update genre disabled status.
     */
    public function update(int $id, int $disabled): int
    {
        $this->clearCache();

        return Genre::where('id', $id)->update(['disabled' => $disabled]);
    }

    /**
     * Get all disabled genre IDs.
     */
    public function getDisabledIDs(): Collection // @phpstan-ignore missingType.generics
    {
        return Cache::remember('disabled_genres', now()->addMinutes(config('nntmux.cache_expiry_long')), function () {
            return Genre::disabled()->get(['id']);
        });
    }

    /**
     * Clear genre-related cache.
     */
    public function clearCache(): void
    {
        Cache::forget('disabled_genres');
        // Clear other genre caches by pattern if needed
    }

    /** Disabled: common mean 1.45-1.49x and p99 1.21-1.39x missed 1.5x in five paired runs; uncommon passed (mean >= 1.65x, p99 >= 1.50x). See the W43 parity test. */
    public const bool W434_RUST_FFI_DEFAULT = false;

    public const string W434_FFI_CDEF = <<<'C'
        ssize_t nntmux_w434_genre_title_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W434_SUMMARY_KEYS = ['total_genres', 'empty_title_count', 'duplicate_title_count', 'multi_genre_count', 'non_ascii_count', 'hyphenated_count', 'numeric_count', 'mixed_case_count'];

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
     * Summarize genre titles: trimmed titles whose strtolower() form (the
     * normalizeGenresMap() value) repeats an earlier one, combined genres
     * (/ , & | ;), bytes above 0x7F, hyphens, digits and mixed ASCII case.
     * Entries come from strings, integers, or the title or genre array field;
     * other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeGenreTitleBatch(array $entries): array
    {
        return self::usesW434RustFfi()
            ? self::summarizeGenreTitleBatchNative($entries)
            : self::summarizeGenreTitleBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeGenreTitleBatchNative(array $entries): array
    {
        return self::w434Call(self::w434Entries($entries)) ?? self::summarizeGenreTitleBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeGenreTitleBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W434_SUMMARY_KEYS, 0);
        $seen = [];
        foreach (self::w434Entries($entries) as $entry) {
            $summary['total_genres']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $lower = strtolower($entry);
            if (isset($seen[$lower])) {
                $summary['duplicate_title_count']++;
            } else {
                $seen[$lower] = true;
            }
            $summary['multi_genre_count'] += (int) (strpbrk($entry, '/,&|;') !== false);
            $summary['non_ascii_count'] += (int) (preg_match('/[\x80-\xFF]/', $entry) === 1);
            $summary['hyphenated_count'] += (int) str_contains($entry, '-');
            $summary['numeric_count'] += (int) (strpbrk($entry, '0123456789') !== false);
            $summary['mixed_case_count'] += (int) ($lower !== $entry && strtoupper($entry) !== $entry);
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
                $item = $item['title'] ?? $item['genre'] ?? null;
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
            $written = $ffi->nntmux_w434_genre_title_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W434_FFI_CDEF.)
                $written = $ffi->nntmux_w434_genre_title_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W434_SUMMARY_KEYS || $result['total_genres'] !== count($entries)) {
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

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 4.12x, p99 4.04x; uncommon mean 4.21x, p99 3.56x); see RustBookCandidateFanartForkingGenreInvitationW50ParityTest. */
    private const bool W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w504_genre_title_key_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Title flags returned by titleKeyBatch(); the token count is stored from bit 8. */
    public const int W50_EMPTY = 1;

    public const int W50_MULTI_GENRE = 2;

    public const int W50_NON_ASCII = 4;

    public const int W50_HYPHENATED = 8;

    public const int W50_NUMERIC = 16;

    public const int W50_MIXED_CASE = 32;

    public const int W50_UPPERCASE = 64;

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
     * Key genre titles for a batch, three ints each: [first earlier duplicate, trimmed span,
     * flags | tokens << 8]. The key is strtolower(trim($title)), the normalizeGenresMap()
     * value; the duplicate is the index of the first earlier title with the same non-empty
     * key, otherwise -1. The span is the trimmed title as offset << 32 | length.
     *
     * Flags: 1 empty, 2 contains / , & | or ;, 4 bytes above 0x7F, 8 hyphen, 16 digit,
     * 32 mixed ASCII case, 64 has an ASCII uppercase letter. Tokens count the separator-split
     * parts that are non-empty after trim() (0 for an empty title).
     *
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function titleKeyBatch(array $titles): array
    {
        return self::usesW50RustFfi()
            ? self::titleKeyBatchNative($titles)
            : self::titleKeyBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function titleKeyBatchNative(array $titles): array
    {
        self::w50AssertList($titles);
        if ($titles === []) {
            return [];
        }

        return self::w50Call(implode("\0", $titles), '', \count($titles))
            ?? self::titleKeyBatchPhp($titles);
    }

    /**
     * @param  list<string>  $titles
     * @return list<int>
     */
    public static function titleKeyBatchPhp(array $titles): array
    {
        self::w50AssertList($titles);
        $out = [];
        $seen = [];
        foreach ($titles as $i => $raw) {
            $title = trim($raw);
            $span = (strspn($raw, " \t\n\r\0\x0B") << 32) | \strlen($title);
            if ($title === '') {
                array_push($out, -1, $span, self::W50_EMPTY);

                continue;
            }
            $lower = strtolower($title);
            $first = $seen[$lower] ??= $i;
            $flags = 0;
            $multi = strpbrk($title, '/,&|;') !== false;
            if ($multi) {
                $flags |= self::W50_MULTI_GENRE;
            }
            if (preg_match('/[\x80-\xFF]/', $title) === 1) {
                $flags |= self::W50_NON_ASCII;
            }
            if (str_contains($title, '-')) {
                $flags |= self::W50_HYPHENATED;
            }
            if (strpbrk($title, '0123456789') !== false) {
                $flags |= self::W50_NUMERIC;
            }
            if ($lower !== $title) {
                $flags |= self::W50_UPPERCASE;
                if (strtoupper($title) !== $title) {
                    $flags |= self::W50_MIXED_CASE;
                }
            }
            $tokens = 1;
            if ($multi) {
                $tokens = 0;
                foreach (preg_split('~[/,&|;]~', $title) ?: [] as $part) {
                    $tokens += (int) (trim($part) !== '');
                }
            }
            array_push($out, $first === $i ? -1 : $first, $span, $flags | ($tokens << 8));
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined titles, three int64 words back per title.
     * Null when the library is missing or declines.
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
            $need = $count * 3;
            $out = self::$w50Out;
            if ($out === null || self::$w50OutCap < $need) {
                self::$w50OutCap = max(1024, $need);
                $out = self::$w50Out = $ffi->new('int64_t['.self::$w50OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
            $written = $ffi->nntmux_w504_genre_title_key_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w50OutCap);
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
     * @param  array<array-key, mixed>  $titles
     */
    private static function w50AssertList(array $titles): void
    {
        if (! array_is_list($titles)) {
            throw new InvalidArgumentException('W50 batch columns must be lists.');
        }
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 6.94x, p99 5.32x; uncommon mean 4.89x, p99 3.45x); see RustGamesProcessorGdprNotificationGenreIgdbClientExceptionW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    public const string W67_GENRE_SERVICE_FFI_CDEF = <<<'C'
        ssize_t nntmux_w673_genre_service_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 67.3 batch. */
    public const int W67_GENRE_SERVICE_TRAILER = 20;

    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores W67_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    public static function usesW67RustFfi(): bool
    {
        $override = self::w67RustFfiState();

        return \is_bool($override) ? $override : self::W67_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the GenreService Wave 67 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 67.3 genre upsert plan. Each item is `title|type|disabled|existingId`. The trimmed title is canonicalized
     * (lower-cased, `_` as a space, whitespace runs collapsed to one space) and mapped through W67_GENRE_ALIASES.
     * Type is 0 empty, 1 console (CONSOLE_TYPE or "console"), 2 music (MUSIC_TYPE or "music"), 3 game (GAME_TYPE,
     * "game" or "pc"), 4 another number or 5 malformed; its state is the w67Count() state of up to 6 digits. Disabled
     * is 0 empty, 1 "0", 2 "1" or 3 other; the existing ID (up to 10 digits) is 0 empty, 1 digits or 2 other. Action
     * 6 invalid (malformed type, disabled or ID, or a title over 255 bytes), 0 skipped (empty canonical title, empty
     * or unknown type), 1 duplicate of an earlier (type, canonical title) in the batch, 2 disable an existing genre, 3
     * drop a disabled new genre, 4 update an existing genre or 5 insert. Per item `kind | typeState << 3 |
     * disabledState << 5 | existingState << 7 | alias << 9 | multi (any of `/,&|;`) << 10 | nonAscii << 11 | changed
     * (canonical differs from the trimmed title) << 12 | action << 13 | canonicalLength (cap 255) << 16 | words (cap
     * 15) << 24 | duplicateOf (1-based first item, cap 2^24 - 1) << 28 | titleLength (cap 255) << 52`. Then the seven
     * action and six kind counts, the aliased, multi-genre, non-ASCII and changed titles, the distinct (type,
     * canonical title) keys, the disabled rows and the 1-based first inserted item.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67GenreServicePlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67GenreServicePlanBatchNative($items) : self::w67GenreServicePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67GenreServicePlanBatchNative(array $items): array
    {
        return self::w67Invoke($items, \count($items) + self::W67_GENRE_SERVICE_TRAILER) ?? self::w67GenreServicePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67GenreServicePlanBatchPhp(array $items): array
    {
        $out = [];
        $actions = [0, 0, 0, 0, 0, 0, 0];
        $kinds = [0, 0, 0, 0, 0, 0];
        $aliases = $multis = $nonAsciiCount = $changedCount = $disabledCount = $firstInsert = 0;
        $seen = [];
        $index = 0;
        foreach ($items as $item) {
            [$rawTitle, $rawType, $rawDisabled, $rawExisting] = explode('|', (string) $item, 4) + ['', '', '', ''];
            $title = trim($rawTitle);
            $canonical = trim(preg_replace('/[ \t\n\r\0\x0B]+/', ' ', strtolower(strtr($title, '_', ' '))) ?? '');
            $alias = self::W67_GENRE_ALIASES[$canonical] ?? null;
            if ($alias !== null) {
                $canonical = $alias;
            }
            $changed = $canonical !== $title;
            $words = $canonical === '' ? 0 : substr_count($canonical, ' ') + 1;
            [$typeState, $typeValue] = self::w67Count($rawType, 6);
            $kind = match ($typeState) {
                0 => 0,
                1 => match ($typeValue) {
                    self::CONSOLE_TYPE => 1,
                    self::MUSIC_TYPE => 2,
                    self::GAME_TYPE => 3,
                    default => 4,
                },
                default => match (self::w67Keyword($rawType, self::W67_GENRE_TYPE_WORDS)) {
                    1 => 1,
                    2 => 2,
                    3, 4 => 3,
                    default => 5,
                },
            };
            [$disabledRaw, $disabledValue] = self::w67Count($rawDisabled, 1);
            $disabledState = match (true) {
                $disabledRaw === 0 => 0,
                $disabledRaw === 1 && $disabledValue === 0 => 1,
                $disabledRaw === 1 && $disabledValue === 1 => 2,
                default => 3,
            };
            [$existingState, $existing] = self::w67Count($rawExisting, 10);
            $multi = strpbrk($title, '/,&|;') !== false;
            $nonAscii = preg_match('/[\x80-\xFF]/', $title) === 1;
            $duplicateOf = 0;
            if ($kind === 5 || $disabledState === 3 || $existingState === 2 || \strlen($title) > 255) {
                $action = 6;
            } elseif ($canonical === '' || $kind === 0 || $kind === 4) {
                $action = 0;
            } else {
                $key = $kind.':'.$canonical;
                if (isset($seen[$key])) {
                    $duplicateOf = $seen[$key] + 1;
                    $action = 1;
                } else {
                    $seen[$key] = $index;
                    $action = match (true) {
                        $disabledState === 2 && $existing > 0 => 2,
                        $disabledState === 2 => 3,
                        $existing > 0 => 4,
                        default => 5,
                    };
                }
            }
            $actions[$action]++;
            $kinds[$kind]++;
            $aliases += $alias !== null ? 1 : 0;
            $multis += $multi ? 1 : 0;
            $nonAsciiCount += $nonAscii ? 1 : 0;
            $changedCount += $changed ? 1 : 0;
            $disabledCount += $disabledState === 2 ? 1 : 0;
            if ($action === 5 && $firstInsert === 0) {
                $firstInsert = $index + 1;
            }
            $out[] = $kind | $typeState << 3 | $disabledState << 5 | $existingState << 7 | ($alias !== null ? 1 : 0) << 9 | ($multi ? 1 : 0) << 10
                | ($nonAscii ? 1 : 0) << 11 | ($changed ? 1 : 0) << 12 | $action << 13 | min(\strlen($canonical), 255) << 16 | min($words, 15) << 24
                | min($duplicateOf, 16_777_215) << 28 | min(\strlen($title), 255) << 52;
            $index++;
        }
        array_push($out, ...$actions);
        array_push($out, ...$kinds);
        array_push($out, $aliases, $multis, $nonAsciiCount, $changedCount, \count($seen), $disabledCount, $firstInsert);

        return $out;
    }

    /** Canonical genre aliases. */
    private const array W67_GENRE_ALIASES = ['sci-fi' => 'science fiction', 'scifi' => 'science fiction', 'rpg' => 'role-playing', 'r&b' => 'rhythm and blues',
        'hip hop' => 'hip-hop', 'rock & roll' => 'rock and roll'];

    /** Type keywords: console, music, then game and pc for GAME_TYPE. */
    private const array W67_GENRE_TYPE_WORDS = ['console', 'music', 'game', 'pc'];

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w67Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * The trimmed field, lower-cased and matched against $words: 0 empty, 1-based index, or the word count + 1.
     *
     * @param  list<string>  $words
     */
    private static function w67Keyword(string $text, array $words): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $position = array_search(strtolower($text), $words, true);

        return $position === false ? \count($words) + 1 : $position + 1;
    }

    /**
     * Wave 67 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('services_w673', self::W67_GENRE_SERVICE_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 67.3 GenreService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w67Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w67RustFfiState('ffi');
            $out = self::w67RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w67RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W67_GENRE_SERVICE_FFI_CDEF.)
            $written = $ffi->nntmux_w673_genre_service_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
