<?php

declare(strict_types=1);

namespace App\Observers;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Observer for Release model to keep search indexes in sync.
 *
 * Delegates document building to Search::updateRelease(), which loads the full
 * row (including movieinfo/videos joins and release_files filenames).
 */
class ReleaseObserver
{
    /** Release attributes copied into the search document. */
    public const array INDEXED_FIELDS = [
        'name',
        'guid',
        'searchname',
        'fromname',
        'categories_id',
        'imdbid',
        'anidbid',
        'movieinfo_id',
        'videos_id',
        'tv_episodes_id',
        'size',
        'totalpart',
        'grabs',
        'passwordstatus',
        'groups_id',
        'nzbstatus',
        'nfostatus',
        'haspreview',
        'jpgstatus',
        'comments',
        'postdate',
        'adddate',
    ];

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_release_observer_has_indexed_change(const char *keys, size_t keys_len, const size_t *lens, size_t count);
        C;

    /**
     * The Rust helper stays opt-in: it did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchUnavailableObserversFailedRelease2faParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::DEFAULT_USE_RUST_FFI;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Whether the dirty attributes (`Model::getDirty()`) touch an indexed field,
     * matching `Model::isDirty($field)` for each of `INDEXED_FIELDS`.
     *
     * @param  array<array-key, mixed>  $dirty
     */
    public static function hasIndexedFieldChange(array $dirty): bool
    {
        return self::usesRustFfi()
            ? self::hasIndexedFieldChangeNative($dirty)
            : self::hasIndexedFieldChangePhp($dirty);
    }

    /**
     * @param  array<array-key, mixed>  $dirty
     */
    public static function hasIndexedFieldChangePhp(array $dirty): bool
    {
        foreach (self::INDEXED_FIELDS as $field) {
            if (array_key_exists($field, $dirty)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $dirty
     */
    public static function hasIndexedFieldChangeNative(array $dirty): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::hasIndexedFieldChangePhp($dirty);
        }

        try {
            $count = \count($dirty);
            $lens = $ffi->new('size_t['.max(1, $count).']');
            $packed = '';
            $i = 0;
            foreach ($dirty as $key => $_) {
                $key = (string) $key;
                $packed .= $key;
                $lens[$i++] = \strlen($key);
            }
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_release_observer_has_indexed_change($packed, \strlen($packed), $lens, $count);
        } catch (Throwable) {
            return self::hasIndexedFieldChangePhp($dirty);
        }

        return $result === 0 || $result === 1
            ? $result === 1
            : self::hasIndexedFieldChangePhp($dirty);
    }

    /**
     * Handle the Release "created" event.
     *
     * When a release is created (e.g., during NZB import), add it to the search index.
     */
    public function created(Release $release): void
    {
        $this->syncAfterCommit($release);
    }

    /**
     * Handle the Release "updated" event.
     *
     * When a release is updated (e.g., during post-processing with movie/TV data),
     * update the search index with the new external IDs.
     */
    public function updated(Release $release): void
    {
        if (self::hasIndexedFieldChange($release->getDirty())) {
            $this->syncAfterCommit($release);
        }
    }

    /**
     * Handle the Release "deleting" event.
     *
     * When a release is about to be deleted, remove the NZB file and associated images from disk.
     * This runs before the model is deleted so we still have access to the guid.
     */
    public function deleting(Release $release): void
    {
        // Delete NZB file
        try {
            $nzbService = app(NzbService::class);
            $nzbService->deleteNzb($release->guid);
        } catch (Throwable $e) {
            Log::error('ReleaseObserver: Failed to delete NZB file', [
                'release_id' => $release->id,
                'guid' => $release->guid,
                'error' => $e->getMessage(),
            ]);
        }

        // Delete associated images (previews, thumbnails, video samples)
        try {
            $releaseImageService = app(ReleaseImageService::class);
            $releaseImageService->delete($release->guid);
        } catch (Throwable $e) {
            Log::error('ReleaseObserver: Failed to delete release images', [
                'release_id' => $release->id,
                'guid' => $release->guid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Release "deleted" event.
     */
    public function deleted(Release $release): void
    {
        DB::afterCommit(function () use ($release): void {
            try {
                Search::deleteRelease($release->id);
            } catch (Throwable $e) {
                Log::error('ReleaseObserver: Failed to delete release from search index', [
                    'release_id' => $release->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    private function syncAfterCommit(Release $release): void
    {
        DB::afterCommit(fn (): bool => $this->syncToSearchIndex($release));
    }

    /**
     * Sync the release to the search index (full document from DB + joins).
     */
    private function syncToSearchIndex(Release $release): bool
    {
        try {
            Search::updateRelease($release->id);

            if (config('app.debug')) {
                Log::debug('ReleaseObserver: Updated search index for release', [
                    'release_id' => $release->id,
                ]);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('ReleaseObserver: Failed to sync release to search index', [
                'release_id' => $release->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
