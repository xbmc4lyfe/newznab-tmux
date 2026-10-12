<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds an existing release that should be treated as a duplicate of an incoming import.
 *
 * Metadata equality only nominates candidates. A caller must prove actual article coverage
 * before a release can be treated as a duplicate. Metadata-only callers retain uploads.
 */
final class ReleaseDuplicateFinder
{
    /**
     * @param  (callable(Release): bool)|null  $coversArticles
     * @return array{0: ?Release, 1: ?string} Tuple of matched release (if any) and dedupe reason for logging.
     */
    public function findDuplicate(
        string $cleanRelName,
        string $searchName,
        int $predbId,
        int $filesize,
        bool $publishedOnly = false,
        ?callable $coversArticles = null,
    ): array {
        if (! (bool) config('nntmux.release_dedupe_enabled', true)) {
            return [null, null];
        }

        if ($coversArticles === null) {
            return [null, null];
        }

        foreach ($this->candidates($cleanRelName, $searchName, $predbId, 128, $publishedOnly) as $candidate) {
            if ($coversArticles($candidate)) {
                return [$candidate, $this->resolveReason($candidate, $searchName, $predbId).'_articles'];
            }
        }

        return [null, null];
    }

    /**
     * Size annotations do not define article identity. The bounded list may miss a
     * covering upload; exhaustion retains the incoming upload instead of guessing.
     *
     * @return list<Release>
     */
    public function candidates(string $cleanRelName, string $searchName, int $predbId, int $limit = 128, bool $publishedOnly = false): array
    {
        $limit = max(0, min(128, $limit));
        $candidates = [];
        $nominations = [];
        if ($predbId > 0) {
            $nominations['predb_id'] = $predbId;
        }
        if ($searchName !== '') {
            $nominations['searchname'] = $searchName;
        }
        if ($cleanRelName !== '') {
            $nominations['name'] = $cleanRelName;
        }
        foreach ($nominations as $column => $value) {
            if (count($candidates) >= $limit) {
                break;
            }
            $rows = Release::query()->where($column, $value)
                ->when($publishedOnly, static fn (Builder $query) => $query->where('nzbstatus', NzbService::NZB_ADDED))
                ->orderBy('id')->limit($limit)
                ->get(['id', 'guid', 'predb_id', 'searchname', 'fromname', 'size', 'name']);
            foreach ($rows as $row) {
                $candidates[$row->id] = $row;
                if (count($candidates) >= $limit) {
                    break;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * Iterate a fixed snapshot of matching published releases in bounded keyset pages.
     *
     * @return iterable<Release>
     */
    public function publishedCandidates(string $cleanRelName, string $searchName, int $predbId, int $filesize): iterable
    {
        if (! (bool) config('nntmux.release_dedupe_enabled', true)) {
            return;
        }
        $query = $this->matchingQuery($cleanRelName, $searchName, $predbId, $filesize)
            ->where('nzbstatus', NzbService::NZB_ADDED);
        $ceiling = (clone $query)->max('id');
        if ($ceiling === null) {
            return;
        }
        $after = 0;
        do {
            $page = (clone $query)->where('id', '>', $after)->where('id', '<=', $ceiling)
                ->orderBy('id')->limit(100)->get(['id', 'guid']);
            foreach ($page as $release) {
                $after = (int) $release->id;
                yield $release;
            }
        } while ($page->count() === 100);
    }

    /** @return Builder<Release> */
    private function matchingQuery(string $cleanRelName, string $searchName, int $predbId, int $filesize): Builder
    {
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

        return $query;
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
