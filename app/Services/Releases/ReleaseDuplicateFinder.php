<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds an existing release that should be treated as a duplicate of an incoming import.
 *
 * Metadata equality only nominates candidates. A caller must prove actual article coverage
 * before a release can be treated as a duplicate. Metadata-only callers retain uploads.
 */
final class ReleaseDuplicateFinder
{
    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $boundsBuf = null;

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
        [$lowSize, $highSize] = $this->sizeBounds($filesize, $tolerance);

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

    /**
     * @return array{0: int, 1: int}
     */
    public function sizeBounds(int $filesize, float $tolerance = 0.05): array
    {
        return self::$useRustFfi
            ? $this->sizeBoundsNative($filesize, $tolerance)
            : $this->sizeBoundsPhp($filesize, $tolerance);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function sizeBoundsPhp(int $filesize, float $tolerance = 0.05): array
    {
        return [
            (int) floor($filesize * (1 - $tolerance)),
            (int) ceil($filesize * (1 + $tolerance)),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function sizeBoundsNative(int $filesize, float $tolerance = 0.05): array
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->sizeBoundsPhp($filesize, $tolerance);
        }

        $buf = self::$boundsBuf ??= $ffi->new('int64_t[2]');

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_release_duplicate_size_bounds($filesize, $tolerance, FFI::addr($buf[0]), FFI::addr($buf[1]));
        if (! \is_int($res) || $res !== 0) {
            return $this->sizeBoundsPhp($filesize, $tolerance);
        }

        return [(int) $buf[0], (int) $buf[1]];
    }

    public function resolveReason(Release $dup, string $searchName, int $predbId): string
    {
        return self::$useRustFfi
            ? $this->resolveReasonNative($dup, $searchName, $predbId)
            : $this->resolveReasonPhp($dup, $searchName, $predbId);
    }

    public function resolveReasonPhp(Release $dup, string $searchName, int $predbId): string
    {
        if ($predbId > 0 && (int) $dup->predb_id === $predbId) {
            return 'predb_id_match';
        }

        if ($searchName !== '' && (string) $dup->searchname === $searchName) {
            return 'searchname_match';
        }

        return 'name_match_fallback';
    }

    public function resolveReasonNative(Release $dup, string $searchName, int $predbId): string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->resolveReasonPhp($dup, $searchName, $predbId);
        }

        $dupPredbId = (int) $dup->predb_id;
        $dupSearchName = (string) $dup->searchname;

        // @phpstan-ignore method.notFound
        $code = $ffi->nntmux_release_duplicate_resolve_reason(
            $dupPredbId,
            $predbId,
            $dupSearchName,
            \strlen($dupSearchName),
            $searchName,
            \strlen($searchName),
        );
        if (! \is_int($code) || $code < 0) {
            return $this->resolveReasonPhp($dup, $searchName, $predbId);
        }

        return match ($code) {
            1 => 'predb_id_match',
            2 => 'searchname_match',
            default => 'name_match_fallback',
        };
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
}
