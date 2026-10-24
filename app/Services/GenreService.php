<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Genre;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
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
}
