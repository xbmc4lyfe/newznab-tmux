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
use Throwable;

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

    /** Wave 55.3 paired benchmarks decide this default; see RustReleaseImageBrowseDuplicateGroupManagementW55ParityTest. */
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        ssize_t nntmux_w553_release_duplicate_name_match_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    public static function usesW55RustFfi(): bool
    {
        $override = self::w55RustFfiState();

        return \is_bool($override) ? $override : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 55 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 55.3 duplicate candidate name matches against the incoming $searchName. Per candidate
     * `flags | shared << 16 | tokens << 32`, with flags 1 empty, 2 exact match (non-empty search name),
     * 4 folded key match and 8 folded key prefix either way (keys are the lowercased ASCII alphanumerics,
     * both non-empty), `shared` the distinct search name tokens the candidate carries and `tokens` its
     * token count (tokens are maximal ASCII alphanumeric runs, lowercased); then the four flag counts and
     * the distinct search name token count.
     *
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w55CandidateNameMatchBatch(string $searchName, array $names): array
    {
        return self::usesW55RustFfi() ? self::w55CandidateNameMatchBatchNative($searchName, $names) : self::w55CandidateNameMatchBatchPhp($searchName, $names);
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w55CandidateNameMatchBatchNative(string $searchName, array $names): array
    {
        array_unshift($names, $searchName);
        $words = \count($names) + 4;

        return self::w55Invoke($names, $words) ?? self::w55CandidateNameMatchBatchPhp($searchName, \array_slice($names, 1));
    }

    /**
     * @param  array<array-key, string>  $names
     * @return list<int>
     */
    public static function w55CandidateNameMatchBatchPhp(string $searchName, array $names): array
    {
        $targetTokens = preg_split('/[^a-z0-9]+/', strtolower($searchName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $targetKey = implode('', $targetTokens);
        $targetSet = array_flip($targetTokens);
        $out = [];
        $totals = [0, 0, 0, 0];
        foreach ($names as $name) {
            $name = (string) $name;
            $tokens = preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $key = implode('', $tokens);
            $flags = ($name === '' ? 1 : 0) | ($searchName !== '' && $name === $searchName ? 2 : 0);
            if ($key !== '') {
                $flags |= ($key === $targetKey ? 4 : 0)
                    | ($targetKey !== '' && (str_starts_with($key, $targetKey) || str_starts_with($targetKey, $key)) ? 8 : 0);
            }
            $shared = $targetSet === [] || $tokens === [] ? 0 : \count(array_intersect_key($targetSet, array_flip($tokens)));
            $totals[0] += $flags & 1;
            $totals[1] += ($flags >> 1) & 1;
            $totals[2] += ($flags >> 2) & 1;
            $totals[3] += ($flags >> 3) & 1;
            $out[] = $flags | (min($shared, 0xFFFF) << 16) | (min(\count($tokens), 0x7FFFFFFF) << 32);
        }
        array_push($out, ...$totals);
        $out[] = \count($targetSet);

        return $out;
    }

    /**
     * Wave 55 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('release_w55', self::W55_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w55RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w55RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 55.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w55Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w55RustFfiState('ffi');
            $out = self::w55RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w55RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W55_FFI_CDEF.)
            $written = $ffi->nntmux_w553_release_duplicate_name_match_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
