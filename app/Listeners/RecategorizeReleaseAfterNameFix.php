<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReleaseNameFixed;
use App\Models\Release;
use App\Services\Categorization\CategorizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class RecategorizeReleaseAfterNameFix
{
    public function __construct(private readonly CategorizationService $categorization) {}

    public function handle(ReleaseNameFixed $event): void
    {
        $snapshot = $event->expectedSnapshot;
        if ($snapshot !== null && ($snapshot['id'] !== $event->releaseId || $snapshot['searchname'] !== $event->newName)) {
            return;
        }
        $query = $snapshot === null
            ? Release::query()->where('id', $event->releaseId)->whereRaw('HEX(searchname) = ?', [strtoupper(bin2hex($event->newName))])
            : $this->snapshotQuery($snapshot);
        $release = $query->first([
            'id',
            'guid',
            'name',
            'groups_id',
            'fromname',
            'categories_id',
            'iscategorized',
            'searchname',
        ]);

        if ($release === null) {
            return;
        }

        $snapshot = ['id' => (int) $release->id, 'guid' => (string) $release->guid, 'name' => $release->name, 'searchname' => $release->searchname];
        $result = $this->categorization->determineCategory(
            $release->groups_id,
            $event->newName,
            (string) ($release->fromname ?? $event->poster)
        );

        $newCategoryId = (int) ($result['categories_id'] ?? $release->categories_id);

        if ((int) $release->categories_id === $newCategoryId && (int) $release->iscategorized === 1) {
            return;
        }

        $affected = $this->snapshotQuery($snapshot)->update([
            'categories_id' => $newCategoryId,
            'iscategorized' => 1,
        ]);
        if ($affected !== 1) {
            return;
        }

        if (config('nntmux.categorization.log', false)) {
            Log::info('categorization.rename_recategorized', [
                'release_id' => $release->id,
                'old_name' => $event->oldName,
                'new_name' => $event->newName,
                'old_category_id' => $event->oldCategoryId,
                'new_category_id' => $newCategoryId,
            ]);
        }
    }

    /**
     * @param  array{id:int,guid:string,name:?string,searchname:?string}  $snapshot
     * @return Builder<Release>
     */
    private function snapshotQuery(array $snapshot): Builder
    {
        $query = Release::query()->where('id', $snapshot['id']);
        foreach (['guid', 'name', 'searchname'] as $column) {
            if ($snapshot[$column] === null) {
                $query->whereNull($column);
            } else {
                $query->whereRaw('HEX('.$column.') = ?', [strtoupper(bin2hex($snapshot[$column]))]);
            }
        }

        return $query;
    }
}
