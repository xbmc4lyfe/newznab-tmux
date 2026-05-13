<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Predb;
use App\Models\Release;
use App\Models\ReleaseRegex;
use App\Models\ReleasesGroups;
use App\Models\UsenetGroup;
use App\Services\Categorization\CategorizationService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Support\Utf8;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReleaseCreationService
{
    public function __construct(
        private readonly ReleaseCleaningService $releaseCleaning,
        private readonly CollectionCleanupService $collectionCleanupService,
        private readonly ReleaseDuplicateFinder $releaseDuplicateFinder,
    ) {}

    /**
     * Create releases from complete collections.
     *
     * @return array{added:int,dupes:int}
     *
     * @throws \Throwable
     */
    public function createReleases(int|string|null $groupID, int $limit, bool $echoCLI): array
    {
        $startTime = now()->toImmutable();
        $categorize = new CategorizationService;
        $returnCount = 0;
        $duplicate = 0;

        if ($echoCLI) {
            cli()->header('Process Releases -> Create releases from complete collections.');
        }

        $collectionsQuery = Collection::query()
            ->where('collections.filecheck', CollectionFileCheckStatus::Sized->value)
            ->where('collections.filesize', '>', 0);
        if (! empty($groupID)) {
            $collectionsQuery->where('collections.groups_id', $groupID);
        }
        $collectionsQuery->select(['collections.*', 'usenet_groups.name as gname'])
            ->join('usenet_groups', 'usenet_groups.id', '=', 'collections.groups_id')
            ->limit($limit);
        $collections = $collectionsQuery->get();

        if ($echoCLI && $collections->count() > 0) {
            cli()->primary(\count($collections).' Collections ready to be converted to releases.', true);
        }

        foreach ($collections as $collection) {
            $cleanRelName = Utf8::clean(str_replace(['#', '@', '$', '%', '^', '§', '¨', '©', 'Ö'], '', $collection->subject));
            $fromName = Utf8::clean(trim($collection->fromname, "'"));

            $cleanedMeta = $this->releaseCleaning->releaseCleaner(
                $collection->subject,
                $collection->fromname,
                $collection->gname
            );

            $namingRegexId = 0;
            if (\is_array($cleanedMeta)) {
                $namingRegexId = isset($cleanedMeta['id']) ? (int) $cleanedMeta['id'] : 0;
            }

            if (\is_array($cleanedMeta)) {
                $properName = $cleanedMeta['properlynamed'] ?? false;
                $preID = $cleanedMeta['predb'] ?? false;
                $cleanedName = $cleanedMeta['cleansubject'] ?? $cleanRelName;
            } else {
                $properName = true;
                $preID = false;
                $cleanedName = $cleanRelName;
            }

            if ($preID === false && $cleanedName !== '') {
                $preMatch = Predb::matchPre($cleanedName);
                if ($preMatch !== false) {
                    $cleanedName = $preMatch['title'];
                    $preID = $preMatch['predb_id'];
                    $properName = true;
                }
            }

            $searchName = ! empty($cleanedName) ? Utf8::clean($cleanedName) : $cleanRelName;
            $predbIdInt = $preID === false ? 0 : (int) $preID;

            [$dupeCheck, $dupeReason] = $this->releaseDuplicateFinder->findDuplicate(
                $cleanRelName,
                $searchName,
                $predbIdInt,
                (int) $collection->filesize
            );

            if ($dupeCheck === null) {
                $determinedCategory = $categorize->determineCategory($collection->groups_id, $cleanedName, $fromName);

                $releaseID = Release::insertRelease([
                    'name' => $cleanRelName,
                    'searchname' => $searchName,
                    'totalpart' => $collection->totalfiles,
                    'groups_id' => $collection->groups_id,
                    'guid' => Str::uuid()->toString(),
                    'postdate' => $collection->date,
                    'fromname' => $fromName,
                    'size' => $collection->filesize,
                    'categories_id' => $determinedCategory['categories_id'] ?? Category::OTHER_MISC,
                    'isrenamed' => $properName === true ? 1 : 0,
                    'predb_id' => $predbIdInt,
                    'nzbstatus' => NzbService::NZB_NONE,
                ]);

                if ($releaseID !== null) {
                    DB::transaction(static function () use ($collection, $releaseID) {
                        Collection::query()->where('id', $collection->id)->update([
                            'filecheck' => CollectionFileCheckStatus::Inserted->value,
                            'releases_id' => $releaseID,
                        ]);
                    }, 10);

                    ReleaseRegex::insertOrIgnore([
                        'releases_id' => $releaseID,
                        'collection_regex_id' => $collection->collection_regexes_id,
                        'naming_regex_id' => $namingRegexId,
                    ]);

                    if (preg_match_all('#(\S+):\S+#', $collection->xref, $hits)) {
                        foreach ($hits[1] as $grp) {
                            $grpTmp = UsenetGroup::isValidGroup($grp);
                            if ($grpTmp !== false) {
                                $xrefGrpID = UsenetGroup::getIDByName($grpTmp);
                                if ($xrefGrpID === '') {
                                    $xrefGrpID = UsenetGroup::addGroup([
                                        'name' => $grpTmp,
                                        'description' => 'Added by Release processing',
                                        'backfill_target' => 1,
                                        'first_record' => 0,
                                        'last_record' => 0,
                                        'active' => 0,
                                        'backfill' => 0,
                                        'minfilestoformrelease' => '',
                                        'minsizetoformrelease' => '',
                                    ]);
                                }

                                $relGroupsChk = ReleasesGroups::query()->where([
                                    ['releases_id', '=', $releaseID],
                                    ['groups_id', '=', $xrefGrpID],
                                ])->first();

                                if ($relGroupsChk === null) {
                                    ReleasesGroups::query()->insert([
                                        'releases_id' => $releaseID,
                                        'groups_id' => $xrefGrpID,
                                    ]);
                                }
                            }
                        }
                    }

                    $returnCount++;
                    if ($echoCLI) {
                        echo "Added $returnCount releases.\r";
                    }
                }
            } else {
                Log::info('Release import skipped as duplicate', [
                    'reason' => $dupeReason,
                    'matched_release_id' => $dupeCheck->id,
                    'new_searchname' => $searchName,
                    'existing_searchname' => $dupeCheck->searchname,
                    'new_size' => (int) $collection->filesize,
                    'existing_size' => (int) $dupeCheck->size,
                    'new_fromname' => $fromName,
                    'existing_fromname' => $dupeCheck->fromname,
                    'new_name' => $cleanRelName,
                    'existing_name' => $dupeCheck->name,
                ]);

                $this->collectionCleanupService->deleteCollectionsAndDescendants(
                    [$collection->id],
                    'Duplicate cleanup',
                    $echoCLI
                );

                $duplicate++;
            }
        }

        $totalTime = now()->diffInSeconds($startTime, true);
        if ($echoCLI) {
            cli()->primary(
                PHP_EOL.
                number_format($returnCount).
                ' Releases added and '.
                number_format($duplicate).
                ' duplicate collections deleted in '.
                $totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        return ['added' => $returnCount, 'dupes' => $duplicate];
    }
}
