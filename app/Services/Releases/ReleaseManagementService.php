<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Search\Support\ManticoreSchemaInspector;
use App\Support\ReleaseSearchIndexSync;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service for managing releases (delete, update, export).
 */
class ReleaseManagementService
{
    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Rust stays opt-in for the Wave 43.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustManticoreSchemaReleaseBrowseDuplicateGroupMgmtW43ParityTest.php.
     */
    public const bool W43_RELEASE_MANAGEMENT_RUST_FFI_DEFAULT = false;

    private static ?bool $useW43RustFfi = null;

    public function __construct() {}

    /**
     * @param  array<string, mixed>  $list
     *
     * @throws \Exception
     */
    public function deleteMultiple(int|array|string $list): void
    {
        $list = (array) $list;

        $nzb = app(NzbService::class);
        $releaseImage = new ReleaseImageService;

        foreach ($list as $identifier) {
            $this->deleteSingleWithService(['g' => $identifier, 'i' => false], $nzb, $releaseImage);
        }
    }

    /**
     * Deletes a single release by GUID, and all the corresponding files.
     *
     * @param  array<string, mixed>  $identifiers  ['g' => Release GUID(mandatory), 'id => ReleaseID(optional, pass
     *                                             false)]
     *
     * @throws \Exception
     */
    public function deleteSingle(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        // Delete NZB from disk.
        $nzb->deleteNzb($identifiers['g']);

        // Delete images.
        $releaseImage->delete($identifiers['g']);

        // Get release ID if not provided
        if ($identifiers['i'] === false) {
            $release = Release::query()->where('guid', $identifiers['g'])->first(['id']);
            if ($release !== null) {
                $identifiers['i'] = $release->id;
            }
        }

        // Delete from search index
        if (! empty($identifiers['i'])) {
            Search::deleteRelease((int) $identifiers['i']);
        }

        // Delete from DB.
        Release::whereGuid($identifiers['g'])->delete();
    }

    /**
     * Alias for deleteSingle for backwards compatibility.
     *
     * @param  array<string, mixed>  $identifiers  ['g' => Release GUID(mandatory), 'i => ReleaseID(optional, pass false)]
     *
     * @throws \Exception
     */
    public function deleteSingleWithService(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        $this->deleteSingle($identifiers, $nzb, $releaseImage);
    }

    /**
     * Delete a bounded set of releases while batching search and database work.
     *
     * @param  iterable<int, object|array<string, mixed>>  $releases
     */
    public function deleteBatch(iterable $releases, NzbService $nzb, ReleaseImageService $releaseImage): int
    {
        $rows = collect($releases)
            ->map(static fn (object|array $release): array => [
                'id' => (int) data_get($release, 'id'),
                'guid' => (string) data_get($release, 'guid'),
            ])
            ->filter(static fn (array $release): bool => $release['id'] > 0 && $release['guid'] !== '')
            ->unique('id')
            ->values();

        if ($rows->isEmpty()) {
            return 0;
        }

        foreach ($rows as $release) {
            try {
                $nzb->deleteNzb($release['guid']);
                $releaseImage->delete($release['guid']);
            } catch (Throwable $e) {
                Log::error('Release batch filesystem cleanup failed', [
                    'release_id' => $release['id'],
                    'guid' => $release['guid'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $ids = $rows->pluck('id')->all();

        try {
            Search::deleteReleases($ids);
        } catch (Throwable $e) {
            Log::error('Release batch search cleanup failed', [
                'release_ids' => $ids,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            return Release::query()->whereIn('id', $ids)->delete();
        } catch (Throwable $e) {
            Log::error('Release batch database cleanup failed', [
                'release_ids' => $ids,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @return bool|int
     */
    public function updateMulti(mixed $guids, mixed $category, mixed $grabs, mixed $videoId, mixed $episodeId, mixed $anidbId, mixed $imdbId)
    {
        if (! \is_array($guids) || \count($guids) < 1) {
            return false;
        }

        $update = [
            'categories_id' => $category === -1 ? 'categories_id' : $category,
            'grabs' => $grabs,
            'videos_id' => $videoId,
            'tv_episodes_id' => $episodeId,
            'anidbid' => $anidbId,
            'imdbid' => $imdbId,
        ];

        $releaseIds = Release::query()->whereIn('guid', $guids)->pluck('id');
        $updated = Release::query()->whereIn('guid', $guids)->update($update);
        ReleaseSearchIndexSync::forIds($releaseIds);

        return $updated;
    }

    /**
     * @param  list<string>  $guids
     */
    public function bulkUpdateCategory(array $guids, int $categoryId): int
    {
        $guids = array_values(array_filter($guids));
        if ($guids === [] || $categoryId <= 0) {
            return 0;
        }

        $updated = 0;

        DB::transaction(function () use ($guids, $categoryId, &$updated): void {
            $releaseIds = Release::query()
                ->whereIn('guid', $guids)
                ->pluck('id');

            $updated = Release::query()
                ->whereIn('guid', $guids)
                ->update(['categories_id' => $categoryId, 'iscategorized' => 1]);

            if ($updated > 0) {
                $this->syncReleasesToSearchIndex($releaseIds);
                Release::clearAdminReleasesRangeCache();
            }
        });

        return $updated;
    }

    /**
     * Re-index releases after query-builder updates that bypass {@see ReleaseObserver}.
     *
     * @param  Collection<int, int|string>|iterable<int|string>  $releaseIds
     */
    private function syncReleasesToSearchIndex(iterable $releaseIds): void
    {
        foreach ($releaseIds as $releaseId) {
            $intId = (int) $releaseId;
            if ($intId <= 0) {
                continue;
            }

            try {
                Search::updateRelease($intId);
            } catch (Throwable $e) {
                Log::error('ReleaseManagementService: Failed to sync release to search index after category change', [
                    'release_id' => $intId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return Release[]|Builder[]|\Illuminate\Database\Eloquent\Collection<int, mixed>|\Illuminate\Database\Query\Builder[]|Collection<int, mixed>
     */
    public function getForExport(string $postFrom = '', string $postTo = '', string $groupID = '') // @phpstan-ignore missingType.generics
    {
        $query = Release::query()
            ->select(['r.searchname', 'r.guid', 'g.name as gname', DB::raw("CONCAT(cp.title,'_',c.title) AS catName")])
            ->from('releases as r')
            ->leftJoin('categories as c', 'c.id', '=', 'r.categories_id')
            ->leftJoin('root_categories as cp', 'cp.id', '=', 'c.root_categories_id')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'r.groups_id');

        if ($groupID !== '') {
            $query->where('r.groups_id', $groupID);
        }

        if ($postFrom !== '') {
            $fromBound = $this->formatExportDateBound($postFrom, false);
            if ($fromBound !== null) {
                $query->where('r.postdate', '>', $fromBound);
            }
        }

        if ($postTo !== '') {
            $toBound = $this->formatExportDateBound($postTo, true);
            if ($toBound !== null) {
                $query->where('r.postdate', '<', $toBound);
            }
        }

        return $query->get();
    }

    public function formatExportDateBound(string $date, bool $isEndOfDay = false): ?string
    {
        return self::$useRustFfi
            ? $this->formatExportDateBoundNative($date, $isEndOfDay)
            : $this->formatExportDateBoundPhp($date, $isEndOfDay);
    }

    public function formatExportDateBoundPhp(string $date, bool $isEndOfDay = false): ?string
    {
        if ($date === '') {
            return null;
        }

        $dateParts = explode('/', $date);
        if (\count($dateParts) !== 3) {
            return null;
        }

        return $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].($isEndOfDay ? '23:59:59' : '00:00:00');
    }

    public function formatExportDateBoundNative(string $date, bool $isEndOfDay = false): ?string
    {
        if ($date === '') {
            return null;
        }

        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->formatExportDateBoundPhp($date, $isEndOfDay);
        }

        $len = \strlen($date);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(64, $len + 16), $cap);
        if ($scratch === null) {
            return $this->formatExportDateBoundPhp($date, $isEndOfDay);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_release_management_export_date($date, $len, $isEndOfDay ? 1 : 0, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return $this->formatExportDateBoundPhp($date, $isEndOfDay);
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->formatExportDateBoundPhp($date, $isEndOfDay);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_management_export_date($date, $len, $isEndOfDay ? 1 : 0, $scratch, $cap);
            if (! \is_int($written) || $written < 0) {
                return $this->formatExportDateBoundPhp($date, $isEndOfDay);
            }
        }

        return $written === 0 ? null : FFI::string($scratch, $written);
    }

    /** Pass null to restore {@see W43_RELEASE_MANAGEMENT_RUST_FFI_DEFAULT}. */
    public static function useW43RustFfi(?bool $enable = true): void
    {
        self::$useW43RustFfi = $enable;
    }

    public static function usesW43RustFfi(): bool
    {
        return self::$useW43RustFfi ?? self::W43_RELEASE_MANAGEMENT_RUST_FFI_DEFAULT;
    }

    /**
     * Summarizes export date filters as {@see formatExportDateBoundPhp()} formats them: `empty` dates
     * and `malformed` ones (not three `/` pieces) yield no bound; `earliest` and `latest` are the
     * byte-wise (`strcmp`) extremes of the formatted bounds, or `''` without any. Non-string dates
     * are invalid.
     *
     * @param  array<array-key, mixed>  $dates
     * @return array{dates: int, invalid: int, empty: int, malformed: int, formatted: int, distinct_bounds: int, earliest: string, latest: string}
     */
    public function summarizeExportDateBoundBatch(array $dates, bool $isEndOfDay = false): array
    {
        return self::usesW43RustFfi() ? $this->summarizeExportDateBoundBatchNative($dates, $isEndOfDay) : $this->summarizeExportDateBoundBatchPhp($dates, $isEndOfDay);
    }

    /**
     * @param  array<array-key, mixed>  $dates
     * @return array{dates: int, invalid: int, empty: int, malformed: int, formatted: int, distinct_bounds: int, earliest: string, latest: string}
     */
    public function summarizeExportDateBoundBatchPhp(array $dates, bool $isEndOfDay = false): array
    {
        $summary = ['dates' => \count($dates), 'invalid' => 0, 'empty' => 0, 'malformed' => 0, 'formatted' => 0, 'distinct_bounds' => 0, 'earliest' => '', 'latest' => ''];
        $bounds = [];
        foreach ($dates as $date) {
            if (! \is_string($date)) {
                $summary['invalid']++;

                continue;
            }
            $bound = $this->formatExportDateBoundPhp($date, $isEndOfDay);
            if ($bound === null) {
                $summary[$date === '' ? 'empty' : 'malformed']++;

                continue;
            }
            if ($summary['formatted'] === 0 || strcmp($bound, $summary['earliest']) < 0) {
                $summary['earliest'] = $bound;
            }
            if ($summary['formatted'] === 0 || strcmp($bound, $summary['latest']) > 0) {
                $summary['latest'] = $bound;
            }
            $summary['formatted']++;
            $bounds[$bound] = true;
        }
        $summary['distinct_bounds'] = \count($bounds);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $dates
     * @return array{dates: int, invalid: int, empty: int, malformed: int, formatted: int, distinct_bounds: int, earliest: string, latest: string}
     */
    public function summarizeExportDateBoundBatchNative(array $dates, bool $isEndOfDay = false): array
    {
        $parts = [$isEndOfDay ? '1' : '0'];
        foreach ($dates as $date) {
            if (\is_string($date)) {
                array_push($parts, '1', $date);
            } else {
                $parts[] = '0';
            }
        }
        $fields = ManticoreSchemaInspector::evaluateW43Native(5, $parts);
        if ($fields === null || \count($fields) !== 8) {
            return $this->summarizeExportDateBoundBatchPhp($dates, $isEndOfDay);
        }

        return [
            'dates' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'empty' => (int) $fields[2],
            'malformed' => (int) $fields[3],
            'formatted' => (int) $fields[4],
            'distinct_bounds' => (int) $fields[5],
            'earliest' => $fields[6],
            'latest' => $fields[7],
        ];
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', ReleaseImageService::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @return mixed|string
     */
    public function getEarliestUsenetPostDate(): mixed
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(min(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * @return mixed|string
     */
    public function getLatestUsenetPostDate(): mixed
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(max(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getReleasedGroupsForSelect(bool $blnIncludeAll = true): array
    {
        $groups = Release::query()
            ->selectRaw('DISTINCT g.id, g.name')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'releases.groups_id')
            ->get();
        $temp_array = [];

        if ($blnIncludeAll) {
            $temp_array[-1] = '--All Groups--';
        }

        foreach ($groups as $group) {
            $temp_array[$group['id']] = $group['name'];
        }

        return $temp_array;
    }
}
