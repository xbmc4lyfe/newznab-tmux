<?php

declare(strict_types=1);

namespace App\Observers;

use App\Facades\Search;
use App\Models\MovieInfo;
use App\Support\ReleaseSearchIndexSync;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MovieInfoObserver
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_movie_info_observer_search_doc(const char *ser_ptr, size_t ser_len, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvProviderLookupVideoMovieObserverParityTest). */
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
     * Build the normalized movie search document passed to `Search::insertMovie()`.
     *
     * @return array<string, mixed>
     */
    public static function buildSearchDocument(MovieInfo $movie): array
    {
        return self::usesRustFfi()
            ? self::buildSearchDocumentNative($movie)
            : self::buildSearchDocumentPhp($movie);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentNative(MovieInfo $movie): array
    {
        return self::normalizeSearchDocumentNative(self::extractAttributes($movie));
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentPhp(MovieInfo $movie): array
    {
        return self::normalizeSearchDocumentPhp(self::extractAttributes($movie));
    }

    /**
     * Normalize raw movie attributes into the 11-key search document array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocument(array $attributes): array
    {
        return self::usesRustFfi()
            ? self::normalizeSearchDocumentNative($attributes)
            : self::normalizeSearchDocumentPhp($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocumentNative(array $attributes): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
        $remaining = 256;
        if (! self::isSafeSerializableValue($attributes, $remaining, 0)) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
        try {
            $serialized = serialize($attributes);
            $serLen = \strlen($serialized);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(512, $serLen + 256), $capacity);
            if ($buffer === null) {
                return self::normalizeSearchDocumentPhp($attributes);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_movie_info_observer_search_doc($serialized, $serLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeSearchDocumentPhp($attributes);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_movie_info_observer_search_doc($serialized, $serLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizeSearchDocumentPhp($attributes);
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, mixed> $result */
            $result = \is_array($decoded) ? $decoded : self::normalizeSearchDocumentPhp($attributes);

            return $result;
        } catch (Throwable) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocumentPhp(array $attributes): array
    {
        return [
            'id' => $attributes['id'] ?? null,
            'imdbid' => $attributes['imdbid'] ?? '',
            'tmdbid' => $attributes['tmdbid'] ?? 0,
            'traktid' => $attributes['traktid'] ?? 0,
            'title' => $attributes['title'] ?? '',
            'year' => $attributes['year'] ?? '',
            'genre' => $attributes['genre'] ?? '',
            'actors' => $attributes['actors'] ?? '',
            'director' => $attributes['director'] ?? '',
            'rating' => $attributes['rating'] ?? '',
            'plot' => $attributes['plot'] ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractAttributes(MovieInfo $movie): array
    {
        return [
            'id' => $movie->id,
            'imdbid' => $movie->imdbid,
            'tmdbid' => $movie->tmdbid,
            'traktid' => $movie->traktid,
            'title' => $movie->title,
            'year' => $movie->year,
            'genre' => $movie->genre,
            'actors' => $movie->actors,
            'director' => $movie->director,
            'rating' => $movie->rating,
            'plot' => $movie->plot,
        ];
    }

    private static function isSafeSerializableValue(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 16) {
            return false;
        }
        if (\is_array($value)) {
            foreach ($value as $v) {
                if (! self::isSafeSerializableValue($v, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || \is_int($value) || \is_string($value) || \is_bool($value);
    }

    /**
     * Handle the MovieInfo "created" event.
     */
    public function created(MovieInfo $movie): void
    {
        $this->syncToSearchIndex($movie);
    }

    /**
     * Handle the MovieInfo "updated" event.
     */
    public function updated(MovieInfo $movie): void
    {
        $this->syncToSearchIndex($movie);
        DB::afterCommit(fn (): bool => $this->syncReleases($movie));
    }

    /**
     * Handle the MovieInfo "deleted" event.
     */
    public function deleted(MovieInfo $movie): void
    {
        try {
            Search::deleteMovie($movie->id);
        } catch (Throwable $e) {
            Log::error('MovieInfoObserver: Failed to delete movie from search index', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);
        }
        DB::afterCommit(fn (): bool => $this->syncReleases($movie));
    }

    /**
     * Sync the movie to the search index.
     */
    private function syncToSearchIndex(MovieInfo $movie): void
    {
        try {
            Search::insertMovie(self::buildSearchDocument($movie));
        } catch (Throwable $e) {
            Log::error('MovieInfoObserver: Failed to sync movie to search index', [
                'movie_id' => $movie->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncReleases(MovieInfo $movie): bool
    {
        ReleaseSearchIndexSync::forMovieInfo((int) $movie->id);

        return true;
    }
}
