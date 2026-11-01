<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Par2RenameProgress
{
    /** @return array{signature: string, offset: int, retry_indexes: list<int>}|null */
    public static function get(int $releaseId): ?array
    {
        $row = DB::table('par2_rename_progress')->where('releases_id', $releaseId)->first();
        if ($row === null) {
            return null;
        }
        $retry = json_decode((string) $row->retry_indexes, true, flags: JSON_THROW_ON_ERROR);

        return ['signature' => (string) $row->signature, 'offset' => (int) $row->offset,
            'retry_indexes' => is_array($retry) ? array_values(array_filter($retry, is_int(...))) : []];
    }

    /** @param list<int> $retryIndexes */
    public static function save(int $releaseId, string $signature, int $offset, array $retryIndexes): bool
    {
        return DB::transaction(function () use ($releaseId, $signature, $offset, $retryIndexes): bool {
            $release = DB::table('releases')->where('id', $releaseId)->lockForUpdate()
                ->first(['isrenamed', 'proc_par2', 'predb_id']);
            if ($release === null || (int) $release->isrenamed === 1 || (int) $release->proc_par2 === 1 || (int) $release->predb_id > 0) {
                DB::table('par2_rename_progress')->where('releases_id', $releaseId)->delete();

                return false;
            }
            $previous = DB::table('par2_rename_progress')->where('releases_id', $releaseId)->first(['signature']);
            if ($previous !== null && $previous->signature !== $signature) {
                DB::table('par2_rename_progress')->where('releases_id', $releaseId)->delete();
            }
            DB::table('par2_rename_progress')->updateOrInsert(['releases_id' => $releaseId],
                ['signature' => $signature, 'offset' => $offset, 'retry_indexes' => json_encode($retryIndexes, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return true;
        });
    }

    /**
     * Clear completed work only while the committed identity is still terminal. A reset or
     * replacement release may already have new candidate buffers by the time callbacks run.
     *
     * @param  array{id:int,guid:string,name:?string,searchname:?string}  $snapshot
     */
    public static function forgetCompletedSnapshot(array $snapshot): bool
    {
        return DB::transaction(function () use ($snapshot): bool {
            $release = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()
                ->first();
            if ($release === null || ! isset($release->id, $release->guid)
                || ! property_exists($release, 'name') || ! property_exists($release, 'searchname')
                || (int) $release->id !== $snapshot['id']
                || $release->guid !== $snapshot['guid'] || $release->name !== $snapshot['name']
                || $release->searchname !== $snapshot['searchname']
                || ((int) ($release->predb_id ?? 0) <= 0 && (int) ($release->isrenamed ?? 0) !== 1 && (int) ($release->proc_par2 ?? 0) !== 1)) {
                return false;
            }
            DB::table('par2_rename_progress')->where('releases_id', $snapshot['id'])->delete();

            return true;
        });
    }

    public static function forget(int $releaseId): void
    {
        try {
            DB::table('par2_rename_progress')->where('releases_id', $releaseId)->delete();
        } catch (Throwable $e) {
            Log::warning('Could not clear completed PAR2 probe progress', ['release_id' => $releaseId, 'error' => $e->getMessage()]);
        }
    }
}
