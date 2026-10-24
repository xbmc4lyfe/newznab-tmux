<?php

declare(strict_types=1);

namespace App\Observers;

use App\Facades\Search;
use App\Models\Video;
use App\Support\ReleaseSearchIndexSync;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class VideoObserver
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_video_observer_search_doc(const char *ser_ptr, size_t ser_len, char *out_buf, size_t out_cap);
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
     * Build the normalized TV show search document passed to `Search::insertTvShow()`.
     *
     * @return array<string, mixed>
     */
    public static function buildSearchDocument(Video $video): array
    {
        return self::usesRustFfi()
            ? self::buildSearchDocumentNative($video)
            : self::buildSearchDocumentPhp($video);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentNative(Video $video): array
    {
        return self::normalizeSearchDocumentNative(self::extractAttributes($video));
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentPhp(Video $video): array
    {
        return self::normalizeSearchDocumentPhp(self::extractAttributes($video));
    }

    /**
     * Normalize raw video attributes into the 10-key search document array.
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
            $written = $ffi->nntmux_video_observer_search_doc($serialized, $serLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeSearchDocumentPhp($attributes);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_video_observer_search_doc($serialized, $serLen, $buffer, $capacity);
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
            'title' => $attributes['title'] ?? '',
            'tvdb' => $attributes['tvdb'] ?? 0,
            'trakt' => $attributes['trakt'] ?? 0,
            'tvmaze' => $attributes['tvmaze'] ?? 0,
            'tvrage' => $attributes['tvrage'] ?? 0,
            'imdb' => $attributes['imdb'] ?? 0,
            'tmdb' => $attributes['tmdb'] ?? 0,
            'started' => $attributes['started'] ?? '',
            'type' => $attributes['type'] ?? 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractAttributes(Video $video): array
    {
        return [
            'id' => $video->id,
            'title' => $video->title,
            'tvdb' => $video->tvdb,
            'trakt' => $video->trakt,
            'tvmaze' => $video->tvmaze,
            'tvrage' => $video->tvrage,
            'imdb' => $video->imdb,
            'tmdb' => $video->tmdb,
            'started' => $video->started,
            'type' => $video->type,
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
     * Handle the Video "created" event.
     */
    public function created(Video $video): void
    {
        $this->syncToSearchIndex($video);
    }

    /**
     * Handle the Video "updated" event.
     */
    public function updated(Video $video): void
    {
        $this->syncToSearchIndex($video);
        DB::afterCommit(fn (): bool => $this->syncReleases($video));
    }

    /**
     * Handle the Video "deleted" event.
     */
    public function deleted(Video $video): void
    {
        try {
            Search::deleteTvShow($video->id);
        } catch (Throwable $e) {
            Log::error('VideoObserver: Failed to delete TV show from search index', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
            ]);
        }
        DB::afterCommit(fn (): bool => $this->syncReleases($video));
    }

    /**
     * Sync the TV show to the search index.
     */
    private function syncToSearchIndex(Video $video): void
    {
        try {
            Search::insertTvShow(self::buildSearchDocument($video));
        } catch (Throwable $e) {
            Log::error('VideoObserver: Failed to sync TV show to search index', [
                'video_id' => $video->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncReleases(Video $video): bool
    {
        ReleaseSearchIndexSync::forVideo((int) $video->id);

        return true;
    }
}
