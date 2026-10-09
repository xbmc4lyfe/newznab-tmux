<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds an existing release that should be treated as a duplicate of an incoming import.
 *
 * Uses (predb_id OR searchname) within a configurable size band. Falls back to raw {@see Release::$name}
 * when {@see Release::$searchname} is empty and there is no predb id. When
 * `nntmux.release_dedupe_enabled` is false only an identical upload (same name, poster and size) matches.
 */
final class ReleaseDuplicateFinder
{
    /**
     * @return array{0: ?Release, 1: ?string} Tuple of matched release (if any) and dedupe reason for logging.
     */
    public function findDuplicate(
        string $cleanRelName,
        string $searchName,
        int $predbId,
        int $filesize,
        ?string $fromName = null,
    ): array {
        if (! (bool) config('nntmux.release_dedupe_enabled', true)) {
            return $this->findIdenticalUpload($cleanRelName, $fromName, $filesize);
        }

        $tolerance = (float) config('nntmux.release_dedupe_size_tolerance', 0.05);
        $lowSize = (int) floor($filesize * (1 - $tolerance));
        $highSize = (int) ceil($filesize * (1 + $tolerance));

        $query = Release::query()
            ->whereBetween('size', [$lowSize, $highSize]);

        if ($predbId > 0 || $searchName !== '') {
            $query->where(function (Builder $w) use ($searchName, $predbId): void {
                if ($predbId > 0) {
                    $w->where('predb_id', $predbId);
                    if ($searchName !== '') {
                        $w->orWhere('searchname', $searchName);
                    }
                } elseif ($searchName !== '') {
                    $w->where('searchname', $searchName);
                } else {
                    $w->whereRaw('1 = 0');
                }
            });
        } else {
            $query->where('name', $cleanRelName);
        }

        $dup = $query->first(['id', 'predb_id', 'searchname', 'fromname', 'size', 'name']);

        if ($dup === null) {
            return [null, null];
        }

        $reason = $this->resolveReason($dup, $searchName, $predbId);

        return [$dup, $reason];
    }

    /**
     * With name/PreDB dedupe disabled, still reject the exact same upload (for example an NZB
     * imported twice): same raw name, same poster and the same total size.
     *
     * @return array{0: ?Release, 1: ?string}
     */
    private function findIdenticalUpload(string $cleanRelName, ?string $fromName, int $filesize): array
    {
        if ($fromName === null || $fromName === '' || $cleanRelName === '') {
            return [null, null];
        }

        $dup = Release::query()
            ->where('name', $cleanRelName)
            ->where('fromname', $fromName)
            ->where('size', $filesize)
            ->first(['id', 'predb_id', 'searchname', 'fromname', 'size', 'name']);

        return $dup === null ? [null, null] : [$dup, 'identical_upload'];
    }

    private function resolveReason(Release $dup, string $searchName, int $predbId): string
    {
        if ($predbId > 0 && (int) $dup->predb_id === $predbId) {
            return 'predb_id_match';
        }

        if ($searchName !== '' && (string) $dup->searchname === $searchName) {
            return 'searchname_match';
        }

        return 'name_match_fallback';
    }
}
