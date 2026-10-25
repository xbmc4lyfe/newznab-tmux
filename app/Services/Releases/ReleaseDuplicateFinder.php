<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Models\Release;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Search\Support\ManticoreSchemaInspector;
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
     * Rust stays opt-in for the Wave 43.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustManticoreSchemaReleaseBrowseDuplicateGroupMgmtW43ParityTest.php.
     */
    public const bool W43_RELEASE_DUPLICATE_RUST_FFI_DEFAULT = false;

    private static ?bool $useW43RustFfi = null;

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

    /** Pass null to restore {@see W43_RELEASE_DUPLICATE_RUST_FFI_DEFAULT}. */
    public static function useW43RustFfi(?bool $enable = true): void
    {
        self::$useW43RustFfi = $enable;
    }

    public static function usesW43RustFfi(): bool
    {
        return self::$useW43RustFfi ?? self::W43_RELEASE_DUPLICATE_RUST_FFI_DEFAULT;
    }

    /**
     * Summarizes duplicate candidates the way {@see resolveReasonPhp()} labels them, plus how many
     * fall inside {@see sizeBoundsPhp()} for the incoming size (`whereBetween` inclusive bounds) and
     * how many distinct search names they carry. Candidates are attribute arrays or releases with
     * scalar `predb_id`, `searchname` and `size`; anything else is invalid.
     *
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, invalid: int, predb_id_match: int, searchname_match: int, name_match_fallback: int, within_size_bounds: int, distinct_searchnames: int}
     */
    public function summarizeCandidateBatch(array $candidates, string $searchName, int $predbId, int $filesize, float $tolerance = 0.05): array
    {
        return self::usesW43RustFfi()
            ? $this->summarizeCandidateBatchNative($candidates, $searchName, $predbId, $filesize, $tolerance)
            : $this->summarizeCandidateBatchPhp($candidates, $searchName, $predbId, $filesize, $tolerance);
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, invalid: int, predb_id_match: int, searchname_match: int, name_match_fallback: int, within_size_bounds: int, distinct_searchnames: int}
     */
    public function summarizeCandidateBatchPhp(array $candidates, string $searchName, int $predbId, int $filesize, float $tolerance = 0.05): array
    {
        [$low, $high] = $this->sizeBoundsPhp($filesize, $tolerance);
        $summary = ['candidates' => \count($candidates), 'invalid' => 0, 'predb_id_match' => 0, 'searchname_match' => 0, 'name_match_fallback' => 0, 'within_size_bounds' => 0, 'distinct_searchnames' => 0];
        $names = [];
        foreach ($candidates as $candidate) {
            $fields = self::w43CandidateFields($candidate);
            if ($fields === null) {
                $summary['invalid']++;

                continue;
            }
            [$dupPredbId, $dupSearchName, $size] = $fields;
            if ($predbId > 0 && $dupPredbId === $predbId) {
                $summary['predb_id_match']++;
            } elseif ($searchName !== '' && $dupSearchName === $searchName) {
                $summary['searchname_match']++;
            } else {
                $summary['name_match_fallback']++;
            }
            $summary['within_size_bounds'] += (int) ($size >= $low && $size <= $high);
            $names[$dupSearchName] = true;
        }
        $summary['distinct_searchnames'] = \count($names);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array{candidates: int, invalid: int, predb_id_match: int, searchname_match: int, name_match_fallback: int, within_size_bounds: int, distinct_searchnames: int}
     */
    public function summarizeCandidateBatchNative(array $candidates, string $searchName, int $predbId, int $filesize, float $tolerance = 0.05): array
    {
        [$low, $high] = $this->sizeBoundsPhp($filesize, $tolerance);
        $parts = [$searchName, (string) $predbId, (string) $low, (string) $high];
        foreach ($candidates as $candidate) {
            $fields = self::w43CandidateFields($candidate);
            if ($fields === null) {
                $parts[] = '0';
            } else {
                array_push($parts, '1', (string) $fields[0], $fields[1], (string) $fields[2]);
            }
        }
        $fields = ManticoreSchemaInspector::evaluateW43Native(3, $parts);
        if ($fields === null || \count($fields) !== 7) {
            return $this->summarizeCandidateBatchPhp($candidates, $searchName, $predbId, $filesize, $tolerance);
        }

        return [
            'candidates' => (int) $fields[0],
            'invalid' => (int) $fields[1],
            'predb_id_match' => (int) $fields[2],
            'searchname_match' => (int) $fields[3],
            'name_match_fallback' => (int) $fields[4],
            'within_size_bounds' => (int) $fields[5],
            'distinct_searchnames' => (int) $fields[6],
        ];
    }

    /** @return array{0: int, 1: string, 2: int}|null */
    private static function w43CandidateFields(mixed $candidate): ?array
    {
        if ($candidate instanceof Release) {
            $candidate = $candidate->getAttributes();
        } elseif (! \is_array($candidate)) {
            return null;
        }
        $predbId = $candidate['predb_id'] ?? null;
        $searchName = $candidate['searchname'] ?? null;
        $size = $candidate['size'] ?? null;
        if ((! \is_scalar($predbId) && $predbId !== null) || (! \is_scalar($searchName) && $searchName !== null) || (! \is_scalar($size) && $size !== null)) {
            return null;
        }

        return [(int) $predbId, (string) $searchName, (int) $size];
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
