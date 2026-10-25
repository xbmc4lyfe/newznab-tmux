<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Release;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Throwable;

class SeriesReleaseService
{
    private const FALLBACK_SCAN_LIMIT = 500;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_series_plan_season_window(int64_t matched_count, int64_t fallback_count, int64_t offset, int64_t limit, int64_t *out_matched_limit, int64_t *out_matched_offset, int32_t *out_fallback_mode, int64_t *out_fallback_offset, int64_t *out_fallback_limit, int64_t *out_total);
        ssize_t nntmux_series_merge_season_counts(const char *matched_ser, size_t matched_ser_len, const char *fallback_ser, size_t fallback_ser_len, int64_t *out_seasons, int64_t *out_counts, size_t cap_pairs);
        int32_t nntmux_series_has_valid_fallback_episode(int32_t series_kind, size_t series_len, int32_t episode_kind, int64_t episode_int, const char *episode_ptr, size_t episode_len);
        ssize_t nntmux_series_normalize_category_ids(const char *ser, size_t ser_len, int64_t *out_ids, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvBookSeriesLookupParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(
        private readonly EpisodeHydrationService $episodeHydrationService,
        private readonly ReleaseBrowseService $releaseBrowseService
    ) {}

    /**
     * @param  array<int, int>  $categories
     * @return array<int, int>
     */
    public function seasonCounts(int $videoId, array $categories): array
    {
        /** @var array<int|string, int|string> $matched */
        $matched = $this->baseReleaseQuery($videoId, $categories)
            ->join('tv_episodes as tve', 'r.tv_episodes_id', '=', 'tve.id')
            ->where('tve.videos_id', $videoId)
            ->selectRaw('tve.series as season, COUNT(*) as release_count')
            ->groupBy('tve.series')
            ->orderBy('tve.series')
            ->pluck('release_count', 'season')
            ->all();

        $fallback = $this->fallbackSeasonCounts($videoId, $categories);

        return self::mergeSeasonCounts($matched, $fallback);
    }

    /**
     * @param  array<int|string, int|string>  $matchedCounts
     * @param  array<int|string, int|string>  $fallbackCounts
     * @return array<int, int>
     */
    public static function mergeSeasonCounts(array $matchedCounts, array $fallbackCounts): array
    {
        return self::usesRustFfi()
            ? self::mergeSeasonCountsNative($matchedCounts, $fallbackCounts)
            : self::mergeSeasonCountsPhp($matchedCounts, $fallbackCounts);
    }

    /**
     * @param  array<int|string, int|string>  $matchedCounts
     * @param  array<int|string, int|string>  $fallbackCounts
     * @return array<int, int>
     */
    public static function mergeSeasonCountsNative(array $matchedCounts, array $fallbackCounts): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::mergeSeasonCountsPhp($matchedCounts, $fallbackCounts);
        }
        $maxPairs = \count($matchedCounts) + \count($fallbackCounts);
        if ($maxPairs === 0) {
            return [];
        }
        try {
            $mSer = serialize($matchedCounts);
            $fSer = serialize($fallbackCounts);
            $outSeasons = $ffi->new("int64_t[{$maxPairs}]");
            $outCounts = $ffi->new("int64_t[{$maxPairs}]");
            // @phpstan-ignore method.notFound
            $pairs = $ffi->nntmux_series_merge_season_counts(
                $mSer,
                \strlen($mSer),
                $fSer,
                \strlen($fSer),
                $outSeasons,
                $outCounts,
                $maxPairs,
            );
            if (! \is_int($pairs) || $pairs < 0 || $pairs > $maxPairs) {
                return self::mergeSeasonCountsPhp($matchedCounts, $fallbackCounts);
            }
            $counts = [];
            for ($i = 0; $i < $pairs; $i++) {
                $counts[(int) $outSeasons[$i]] = (int) $outCounts[$i];
            }

            return $counts;
        } catch (Throwable) {
            return self::mergeSeasonCountsPhp($matchedCounts, $fallbackCounts);
        }
    }

    /**
     * @param  array<int|string, int|string>  $matchedCounts
     * @param  array<int|string, int|string>  $fallbackCounts
     * @return array<int, int>
     */
    public static function mergeSeasonCountsPhp(array $matchedCounts, array $fallbackCounts): array
    {
        $counts = [];
        foreach ($matchedCounts as $season => $count) {
            $counts[(int) $season] = (int) $count;
        }

        foreach ($fallbackCounts as $season => $count) {
            $seasonKey = (int) $season;
            $counts[$seasonKey] = ($counts[$seasonKey] ?? 0) + (int) $count;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array{matched_limit: int, matched_offset: int, fallback_mode: int, fallback_offset: int, fallback_limit: int, total: int}
     */
    public static function planSeasonWindow(
        int $matchedCount,
        int $fallbackCount,
        int $offset,
        int $limit,
    ): array {
        return self::usesRustFfi()
            ? self::planSeasonWindowNative($matchedCount, $fallbackCount, $offset, $limit)
            : self::planSeasonWindowPhp($matchedCount, $fallbackCount, $offset, $limit);
    }

    /**
     * @return array{matched_limit: int, matched_offset: int, fallback_mode: int, fallback_offset: int, fallback_limit: int, total: int}
     */
    public static function planSeasonWindowNative(
        int $matchedCount,
        int $fallbackCount,
        int $offset,
        int $limit,
    ): array {
        if (($ffi = self::ffi()) === null) {
            return self::planSeasonWindowPhp($matchedCount, $fallbackCount, $offset, $limit);
        }
        try {
            $outMatchedLimit = $ffi->new('int64_t[1]');
            $outMatchedOffset = $ffi->new('int64_t[1]');
            $outFallbackMode = $ffi->new('int32_t[1]');
            $outFallbackOffset = $ffi->new('int64_t[1]');
            $outFallbackLimit = $ffi->new('int64_t[1]');
            $outTotal = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_series_plan_season_window(
                $matchedCount,
                $fallbackCount,
                $offset,
                $limit,
                $outMatchedLimit,
                $outMatchedOffset,
                $outFallbackMode,
                $outFallbackOffset,
                $outFallbackLimit,
                $outTotal,
            );
            if ($res === 1) {
                return [
                    'matched_limit' => (int) $outMatchedLimit[0],
                    'matched_offset' => (int) $outMatchedOffset[0],
                    'fallback_mode' => (int) $outFallbackMode[0],
                    'fallback_offset' => (int) $outFallbackOffset[0],
                    'fallback_limit' => (int) $outFallbackLimit[0],
                    'total' => (int) $outTotal[0],
                ];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::planSeasonWindowPhp($matchedCount, $fallbackCount, $offset, $limit);
    }

    /**
     * @return array{matched_limit: int, matched_offset: int, fallback_mode: int, fallback_offset: int, fallback_limit: int, total: int}
     */
    public static function planSeasonWindowPhp(
        int $matchedCount,
        int $fallbackCount,
        int $offset,
        int $limit,
    ): array {
        $matchedLimit = $limit;
        $matchedOffset = $offset;
        $fallbackMode = 0;
        $fallbackOffset = 0;
        $fallbackLimit = 0;

        if ($limit > 0) {
            if ($offset >= $matchedCount) {
                $matchedLimit = 0;
                $matchedOffset = 0;
                $fallbackMode = 1;
                $fallbackOffset = $offset - $matchedCount;
                $fallbackLimit = $limit;
            } elseif (($offset + $limit) > $matchedCount) {
                $matchedLimit = $matchedCount - $offset;
                $fallbackMode = 2;
                $fallbackLimit = $limit - $matchedLimit;
            }
        }

        return [
            'matched_limit' => $matchedLimit,
            'matched_offset' => $matchedOffset,
            'fallback_mode' => $fallbackMode,
            'fallback_offset' => $fallbackOffset,
            'fallback_limit' => $fallbackLimit,
            'total' => $matchedCount + $fallbackCount,
        ];
    }

    /**
     * @param  array<int, int>  $categories
     * @return array{releases:EloquentCollection<int, Release>, total:int}
     */
    public function releasesForSeason(int $videoId, int $season, int $offset, int $limit, array $categories): array
    {
        $matchedCount = $this->matchedSeasonCount($videoId, $season, $categories);
        $fallback = $this->fallbackReleasesForSeason($videoId, $season, $categories);
        $fallbackCount = $fallback->count();

        $plan = self::planSeasonWindow($matchedCount, $fallbackCount, $offset, $limit);
        $matchedLimit = $plan['matched_limit'];
        $matchedOffset = $plan['matched_offset'];
        $fallbackSlice = match ($plan['fallback_mode']) {
            1 => new EloquentCollection(
                $fallback->slice($plan['fallback_offset'], $plan['fallback_limit'])->values()->all()
            ),
            2 => new EloquentCollection($fallback->take($plan['fallback_limit'])->values()->all()),
            default => new EloquentCollection,
        };

        $matched = $matchedLimit > 0
            ? $this->matchedSeasonReleaseQuery($videoId, $season, $categories)
                ->orderByDesc('r.postdate')
                ->limit($matchedLimit)
                ->offset($matchedOffset)
                ->get()
            : new EloquentCollection;

        $releases = new EloquentCollection($matched->concat($fallbackSlice)->values()->all());
        if ($releases->isNotEmpty()) {
            $releases->loadCount('failed');
        }

        return [
            'releases' => $releases,
            'total' => $plan['total'],
        ];
    }

    /**
     * @param  array<int, int>  $categories
     */
    private function matchedSeasonCount(int $videoId, int $season, array $categories): int
    {
        return $this->baseReleaseQuery($videoId, $categories)
            ->join('tv_episodes as tve', 'r.tv_episodes_id', '=', 'tve.id')
            ->where('tve.videos_id', $videoId)
            ->where('tve.series', $season)
            ->count();
    }

    /**
     * @param  array<int, int>  $categories
     * @return Builder<Release>
     */
    private function matchedSeasonReleaseQuery(int $videoId, int $season, array $categories): Builder
    {
        return $this->baseReleaseQuery($videoId, $categories)
            ->join('tv_episodes as tve', 'r.tv_episodes_id', '=', 'tve.id')
            ->where('tve.videos_id', $videoId)
            ->where('tve.series', $season)
            ->select([
                'r.id',
                'r.searchname',
                'r.guid',
                'r.postdate',
                'r.groups_id',
                'r.categories_id',
                'r.size',
                'r.totalpart',
                'r.fromname',
                'r.passwordstatus',
                'r.grabs',
                'r.comments',
                'r.adddate',
                'r.videos_id',
                'r.tv_episodes_id',
                'tve.series',
                'tve.episode',
                'tve.firstaired',
            ]);
    }

    /**
     * @param  array<int, int>  $categories
     * @return Builder<Release>
     */
    private function baseReleaseQuery(int $videoId, array $categories): Builder
    {
        $query = Release::query()
            ->from('releases as r')
            ->where('r.videos_id', $videoId)
            ->whereRaw('r.passwordstatus '.$this->releaseBrowseService->showPasswords());

        if ($categories !== []) {
            $query->whereIn('r.categories_id', $categories);
        }

        return $query;
    }

    /**
     * @param  array<int, int>  $categories
     * @return array<int, int>
     */
    private function fallbackSeasonCounts(int $videoId, array $categories): array
    {
        return $this->fallbackCandidates($videoId, $categories)
            ->groupBy(static fn (Release $release): int => (int) $release->getAttribute('series'))
            ->map(static fn (Collection $releases): int => $releases->count())
            ->all();
    }

    /**
     * @param  array<int, int>  $categories
     * @return EloquentCollection<int, Release>
     */
    private function fallbackReleasesForSeason(int $videoId, int $season, array $categories): EloquentCollection
    {
        return new EloquentCollection(
            $this->fallbackCandidates($videoId, $categories)
                ->filter(static fn (Release $release): bool => (int) $release->getAttribute('series') === $season)
                ->values()
                ->all()
        );
    }

    /**
     * @param  array<int, int>  $categories
     * @return Collection<int, Release>
     */
    private function fallbackCandidates(int $videoId, array $categories): Collection
    {
        $candidates = $this->baseReleaseQuery($videoId, $categories)
            ->where(static function (Builder $query): void {
                $query->whereNull('r.tv_episodes_id')
                    ->orWhere('r.tv_episodes_id', '<=', 0);
            })
            ->select([
                'r.id',
                'r.searchname',
                'r.guid',
                'r.postdate',
                'r.groups_id',
                'r.categories_id',
                'r.size',
                'r.totalpart',
                'r.fromname',
                'r.passwordstatus',
                'r.grabs',
                'r.comments',
                'r.adddate',
                'r.videos_id',
                'r.tv_episodes_id',
            ])
            ->orderByDesc('r.postdate')
            ->limit(self::FALLBACK_SCAN_LIMIT)
            ->get();

        $this->episodeHydrationService->hydrateEpisodeMetadata($candidates);

        return $candidates
            ->filter(static fn (Release $release): bool => self::hasValidFallbackEpisode(
                $release->getAttribute('series'),
                $release->getAttribute('episode'),
            ))
            ->values();
    }

    public static function hasValidFallbackEpisode(mixed $series, mixed $episode): bool
    {
        return self::usesRustFfi()
            ? self::hasValidFallbackEpisodeNative($series, $episode)
            : self::hasValidFallbackEpisodePhp($series, $episode);
    }

    public static function hasValidFallbackEpisodeNative(mixed $series, mixed $episode): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::hasValidFallbackEpisodePhp($series, $episode);
        }
        $seriesKind = match (true) {
            $series === null => 0,
            \is_string($series) => 1,
            default => 2,
        };
        $seriesLen = \is_string($series) ? \strlen($series) : 0;

        $episodeKind = 0;
        $episodeInt = 0;
        $episodeStr = '';
        $episodeLen = 0;
        if (\is_int($episode)) {
            $episodeKind = 1;
            $episodeInt = $episode;
        } elseif (\is_string($episode)) {
            $episodeKind = 2;
            $episodeStr = $episode;
            $episodeLen = \strlen($episode);
        } elseif (! empty($episode)) {
            $episodeKind = 3;
        }

        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_series_has_valid_fallback_episode(
                $seriesKind,
                $seriesLen,
                $episodeKind,
                $episodeInt,
                $episodeStr,
                $episodeLen,
            );
        } catch (Throwable) {
            return self::hasValidFallbackEpisodePhp($series, $episode);
        }

        return match ($res) {
            1 => true,
            0 => false,
            default => self::hasValidFallbackEpisodePhp($series, $episode),
        };
    }

    public static function hasValidFallbackEpisodePhp(mixed $series, mixed $episode): bool
    {
        return $series !== null && $series !== '' && ! empty($episode);
    }

    /**
     * @param  array<int, int|string>  $categoryInput
     * @return array<int, int>
     */
    public function categoryIds(array $categoryInput): array
    {
        $categories = Category::getCategorySearch($categoryInput, 'tv', true);

        return self::normalizeCategoryIds(\is_array($categories) ? $categories : null);
    }

    /**
     * @param  array<int|string, mixed>|null  $categories
     * @return array<int, int>
     */
    public static function normalizeCategoryIds(?array $categories): array
    {
        return self::usesRustFfi()
            ? self::normalizeCategoryIdsNative($categories)
            : self::normalizeCategoryIdsPhp($categories);
    }

    /**
     * @param  array<int|string, mixed>|null  $categories
     * @return array<int, int>
     */
    public static function normalizeCategoryIdsNative(?array $categories): array
    {
        if ($categories === null || $categories === []) {
            return [];
        }
        if (($ffi = self::ffi()) === null) {
            return self::normalizeCategoryIdsPhp($categories);
        }
        $count = \count($categories);
        try {
            $ser = serialize($categories);
            $outIds = $ffi->new("int64_t[{$count}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_series_normalize_category_ids($ser, \strlen($ser), $outIds, $count);
            if (! \is_int($written) || $written !== $count) {
                return self::normalizeCategoryIdsPhp($categories);
            }
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                $result[] = (int) $outIds[$i];
            }

            return $result;
        } catch (Throwable) {
            return self::normalizeCategoryIdsPhp($categories);
        }
    }

    /**
     * @param  array<int|string, mixed>|null  $categories
     * @return array<int, int>
     */
    public static function normalizeCategoryIdsPhp(?array $categories): array
    {
        return $categories === null ? [] : array_values(array_map('intval', $categories));
    }

    /** Enabled: three paired runs cleared every 1.5x gate (common mean 2.12-2.37x, p99 2.11-2.56x; uncommon mean 2.30-2.44x, p99 1.95-2.11x); see RustSystemTempUserInactivityStatsSeriesW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w513_series_fallback_season_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private static ?bool $useW51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$useW51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 51 Rust path forced on or off, then restores the previous setting. */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW51RustFfi;
        self::$useW51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW51RustFfi = $previous;
        }
    }

    /**
     * Runs the fallback-candidate filter and season grouping of fallbackCandidates(),
     * fallbackSeasonCounts() and fallbackReleasesForSeason() over the hydrated `series` and
     * `episode` columns (row i is the i-th value of each list; a missing episode reads as null).
     * A row is valid per hasValidFallbackEpisodePhp() and groups under `(int) series`. Words: a
     * valid-row bitmap and a $season-match bitmap (64 rows per word, row i at bit `i % 64` of word
     * `i / 64`), the distinct season count, ascending (season, count) pairs, the $season count and
     * the invalid count. Values hold int, string or null, as hydration leaves them; the Rust path
     * declines to PHP when a valid row's series is not a plain integer string.
     *
     * @param  array<int, int|string|null>  $series
     * @param  array<int, int|string|null>  $episodes
     * @return list<int>
     */
    public static function w51FallbackSeasonBatch(array $series, array $episodes, int $season): array
    {
        return self::usesW51RustFfi()
            ? self::w51FallbackSeasonBatchNative($series, $episodes, $season)
            : self::w51FallbackSeasonBatchPhp($series, $episodes, $season);
    }

    /**
     * @param  array<int, int|string|null>  $series
     * @param  array<int, int|string|null>  $episodes
     * @return list<int>
     */
    public static function w51FallbackSeasonBatchNative(array $series, array $episodes, int $season): array
    {
        $count = \count($series);
        if ($count === 0 || \count($episodes) !== $count) {
            return self::w51FallbackSeasonBatchPhp($series, $episodes, $season);
        }

        return self::w51Invoke(
            implode("\0", $series)."\0".implode("\0", $episodes),
            pack('q', $season),
            $count,
            intdiv($count + 63, 64) * 2 + $count * 2 + 3,
        )
            ?? self::w51FallbackSeasonBatchPhp($series, $episodes, $season);
    }

    /**
     * @param  array<int, int|string|null>  $series
     * @param  array<int, int|string|null>  $episodes
     * @return list<int>
     */
    public static function w51FallbackSeasonBatchPhp(array $series, array $episodes, int $season): array
    {
        $episodes = array_values($episodes);
        $words = intdiv(\count($series) + 63, 64);
        $valid = array_fill(0, $words, 0);
        $match = $valid;
        $counts = [];
        $matched = 0;
        $invalid = 0;
        $row = 0;
        foreach ($series as $value) {
            if (! self::hasValidFallbackEpisodePhp($value, $episodes[$row] ?? null)) {
                $invalid++;
                $row++;

                continue;
            }
            $bit = 1 << ($row & 63);
            $valid[$row >> 6] |= $bit;
            $key = (int) $value;
            if ($key === $season) {
                $match[$row >> 6] |= $bit;
                $matched++;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $row++;
        }
        ksort($counts);
        $out = [...$valid, ...$match, \count($counts)];
        foreach ($counts as $key => $n) {
            array_push($out, $key, $n);
        }
        array_push($out, $matched, $invalid);

        return $out;
    }

    /**
     * Calls the Wave 51 export with NUL-joined strings and packed `i64` integers. Null when the
     * library is missing, the call declines (for example a string holding a NUL byte) or it writes
     * more than $cap words, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w51Invoke(string $blob, string $ints, int $count, int $cap): ?array
    {
        self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('categorize_w51', self::W51_FFI_CDEF) ?? false;
        $ffi = self::$w51Ffi;
        if ($ffi === false) {
            return null;
        }
        try {
            if (self::$w51Out === null || self::$w51OutCap < $cap) {
                self::$w51OutCap = max(1024, $cap * 2);
                self::$w51Out = $ffi->new('int64_t['.self::$w51OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
            $written = $ffi->nntmux_w513_series_fallback_season_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, self::$w51Out, self::$w51OutCap);
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }
            $values = unpack('P*', FFI::string(self::$w51Out, $written * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
