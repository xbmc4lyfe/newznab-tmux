<?php

declare(strict_types=1);

namespace App\Support;

use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Observers\ReleaseObserver;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Re-sync releases_rt / ES release documents after query-builder or raw SQL updates
 * that bypass Eloquent {@see ReleaseObserver} events.
 */
final class ReleaseSearchIndexSync
{
    /** Rust helpers are opt-in; neither beat PHP by 1.5x in the paired benchmark. */
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_search_sync_positive_ids(const int64_t *ids, size_t count, int64_t *out, size_t cap);
        int32_t nntmux_search_sync_where_condition(const char *input, size_t len, int64_t *out);
        C;

    /**
     * @param  iterable<int|string>  $releaseIds
     */
    public static function forIds(iterable $releaseIds, bool $useRustFfi = false): void
    {
        foreach (self::positiveIds($releaseIds, $useRustFfi) as $id) {
            Search::updateRelease($id);
        }
    }

    /**
     * `(int)` ids greater than zero, in order and with duplicates kept.
     *
     * @param  iterable<mixed>  $releaseIds
     * @return list<int>
     */
    public static function positiveIds(iterable $releaseIds, bool $useRustFfi = false): array
    {
        if (! $useRustFfi) {
            return self::positiveIdsPhp($releaseIds);
        }
        // Materialize once so a consumed generator cannot leave the PHP fallback empty.
        $ids = \is_array($releaseIds) ? $releaseIds : iterator_to_array($releaseIds, false);

        return self::positiveIdsNative($ids) ?? self::positiveIdsPhp($ids);
    }

    /**
     * @param  iterable<mixed>  $releaseIds
     * @return list<int>
     */
    public static function positiveIdsPhp(iterable $releaseIds): array
    {
        $ids = [];
        foreach ($releaseIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[] = $intId;
            }
        }

        return $ids;
    }

    /**
     * Rust filter for all-int lists; null (PHP decides) for other values or without FFI.
     *
     * @param  array<array-key, mixed>  $releaseIds
     * @return list<int>|null
     */
    public static function positiveIdsNative(array $releaseIds): ?array
    {
        $count = \count($releaseIds);
        if ($count === 0) {
            return [];
        }
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        static $input, $output, $capacity = 0;
        try {
            if ($capacity < $count) {
                $capacity = max(512, $count * 2);
                $input = $ffi->new("int64_t[{$capacity}]");
                $output = $ffi->new("int64_t[{$capacity}]");
            }
            $i = 0;
            foreach ($releaseIds as $id) {
                if (! \is_int($id)) {
                    return null;
                }
                $input[$i++] = $id;
            }
            $kept = $ffi->nntmux_search_sync_positive_ids($input, $count, $output, $capacity);
            if (! \is_int($kept) || $kept < 0 || $kept > $count) {
                return null;
            }
            $ids = [];
            for ($i = 0; $i < $kept; $i++) {
                $ids[] = (int) $output[$i];
            }

            return $ids;
        } catch (Throwable) {
            return null;
        }
    }

    public static function forMovieInfo(int $movieInfoId): void
    {
        if ($movieInfoId > 0) {
            self::forQuery(Release::query()->where('movieinfo_id', $movieInfoId));
        }
    }

    public static function forVideo(int $videoId): void
    {
        if ($videoId > 0) {
            self::forQuery(Release::query()->where('videos_id', $videoId));
        }
    }

    public static function forCategory(int $categoryId): void
    {
        if ($categoryId > 0) {
            self::forQuery(Release::query()->where('categories_id', $categoryId));
        }
    }

    public static function forRootCategory(int $rootCategoryId): void
    {
        if ($rootCategoryId <= 0) {
            return;
        }

        $categoryIds = Category::query()
            ->where('root_categories_id', $rootCategoryId)
            ->pluck('id');

        if ($categoryIds->isNotEmpty()) {
            self::forQuery(Release::query()->whereIn('categories_id', $categoryIds));
        }
    }

    public static function forQueryGroup(int $groupId): void
    {
        if ($groupId > 0) {
            self::forQuery(Release::query()->where('groups_id', $groupId));
        }
    }

    /**
     * @param  Builder<Release>  $query
     */
    private static function forQuery($query): void
    {
        $query->select('releases.id')->orderBy('releases.id')->chunkById(500, function ($releases): bool {
            self::forIds($releases->pluck('id'));

            return true;
        }, 'releases.id');
    }

    /**
     * Reindex every release (chunked). Use after mass UPDATEs that match an optional raw WHERE suffix.
     *
     * @param  string  $whereSuffix  SQL fragment starting with "AND ..." or empty for all rows
     */
    public static function reindexMatchingWhere(string $whereSuffix = '', bool $useRustFfi = false): void
    {
        $query = Release::query()->select('releases.id')->orderBy('releases.id');
        $condition = self::whereCondition($whereSuffix, $useRustFfi);
        if ($condition !== '') {
            $query->whereRaw($condition);
        }

        $query->chunkById(500, function ($releases): bool {
            foreach ($releases as $release) {
                Search::updateRelease((int) $release->id);
            }

            return true;
        });
    }

    /**
     * The raw WHERE suffix without its leading `AND`; '' when there is no condition.
     */
    public static function whereCondition(string $whereSuffix, bool $useRustFfi = false): string
    {
        return ($useRustFfi ? self::whereConditionNative($whereSuffix) : null) ?? self::whereConditionPhp($whereSuffix);
    }

    public static function whereConditionPhp(string $whereSuffix): string
    {
        $trimmed = trim($whereSuffix);
        if ($trimmed === '') {
            return '';
        }

        return preg_replace('/^\s*AND\s+/i', '', $trimmed) ?? '';
    }

    public static function whereConditionNative(string $whereSuffix): ?string
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        if ($ffi === false) {
            return null;
        }
        static $span;
        try {
            $span ??= $ffi->new('int64_t[2]');
            if ($ffi->nntmux_search_sync_where_condition($whereSuffix, \strlen($whereSuffix), $span) !== 0) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return substr($whereSuffix, (int) $span[0], (int) $span[1]);
    }
}
