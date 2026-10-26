<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TvEpisode;
use App\Models\UserSerie;
use App\Models\Video;
use App\Services\Api\ApiQueryParameters;
use App\Services\SeriesReleaseService;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use FFI\CData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class SeriesController extends BasePageController
{
    private static FFI|false|null $seriesFfi = null;

    private static ?CData $seriesOutBuf1024 = null;

    private static ?bool $useSeriesRustFfi = null;

    private static bool $adoptGroupSeriesByBucketFfi = true;

    private static bool $adoptClassifyTitleBucketFfi = false;

    private static bool $adoptResolveSeasonFfi = false;

    private SeriesReleaseService $seriesReleaseService;

    public function __construct(SeriesReleaseService $seriesReleaseService)
    {
        parent::__construct();
        $this->seriesReleaseService = $seriesReleaseService;
    }

    /**
     * @throws \Exception
     */
    public function index(Request $request, string $id = ''): mixed
    {

        if ($id && ctype_digit($id)) {
            $category = -1;
            $categoryInput = $this->scalarInput($request, 't');
            if ($categoryInput !== '' && ctype_digit($categoryInput)) {
                $category = (int) $categoryInput;
            }

            $catarray = [];
            $catarray[] = $category;

            $seriesLimit = (int) config('nntmux.series_view_limit', config('nntmux.items_per_page', 50));
            $page = $this->resolvePage($request);
            $offset = $seriesLimit > 0 ? $this->paginationOffset($page, $seriesLimit) : 0;

            $show = Video::getByVideoID($id);

            $nodata = '';
            $seasons = [];
            $seasonTabs = [];
            $selectedSeason = null;
            $myshows = null;
            $seriestitles = '';
            $seriessummary = '';
            $seriescountry = '';
            $totalRows = 0;

            if (! $show) {
                $nodata = 'No video information for this series.';
            } else {
                $myshows = UserSerie::getShow($this->userdata->id, $show['id']);
                $categoryIds = $this->seriesReleaseService->categoryIds($catarray);
                $seasonCounts = $this->seriesReleaseService->seasonCounts((int) $show['id'], $categoryIds);

                if ($seasonCounts === []) {
                    $nodata = 'No releases for this series.';
                } else {
                    $requestedSeason = $this->resolveSeason($request);
                    $selectedSeason = $requestedSeason !== null && array_key_exists($requestedSeason, $seasonCounts)
                        ? $requestedSeason
                        : array_key_first($seasonCounts);

                    $seasonReleaseResult = $this->seriesReleaseService->releasesForSeason(
                        (int) $show['id'],
                        (int) $selectedSeason,
                        $offset,
                        $seriesLimit,
                        $categoryIds
                    );

                    $series = [];
                    foreach ($seasonReleaseResult['releases'] as $release) {
                        $series[(int) $selectedSeason][(int) $release->getAttribute('episode')][] = $release;
                    }
                    if (isset($series[(int) $selectedSeason])) {
                        ksort($series[(int) $selectedSeason], SORT_NUMERIC);
                    }

                    $seasons = $series;
                    $totalRows = $seasonReleaseResult['total'];
                    $seasonTabs = $this->buildSeasonTabs($request, $seasonCounts, (int) $selectedSeason);
                }

                // get series name(s), description, country and genre
                $seriestitlesArray = $seriessummaryArray = $seriescountryArray = [];
                $seriestitlesArray[] = $show['title'];

                if (! empty($show['summary'])) {
                    $seriessummaryArray[] = $show['summary'];
                }

                if (! empty($show['countries_id'])) {
                    $seriescountryArray[] = $show['countries_id'];
                }

                $seriestitles = implode('/', array_map('trim', $seriestitlesArray));
                $seriessummary = $seriessummaryArray ? array_shift($seriessummaryArray) : '';
                $seriescountry = $seriescountryArray ? array_shift($seriescountryArray) : '';
            }

            // Calculate statistics
            $episodeCount = 0;
            $seasonCount = count($seasonTabs);
            $totalSeasonsAvailable = $seasonCount;

            // Get first and last aired dates from TV episodes
            $firstEpisodeAired = null;
            $lastEpisodeAired = null;
            $totalSeasonsAired = 0;
            $totalEpisodesAired = 0;

            if (! empty($show['id'])) {
                $episodeStats = TvEpisode::query()
                    ->where('videos_id', $show['id'])
                    ->whereNotNull('firstaired')
                    ->where('firstaired', '!=', '')
                    ->selectRaw('MIN(firstaired) as first_aired, MAX(firstaired) as last_aired, COUNT(DISTINCT series) as total_seasons, COUNT(*) as total_episodes')
                    ->first();

                if ($episodeStats) {
                    if (! empty($episodeStats->first_aired) && $episodeStats->first_aired != '0000-00-00') {
                        $firstEpisodeAired = Carbon::parse($episodeStats->first_aired);
                    }
                    if (! empty($episodeStats->last_aired) && $episodeStats->last_aired != '0000-00-00') {
                        $lastEpisodeAired = Carbon::parse($episodeStats->last_aired);
                    }
                    $totalSeasonsAired = $episodeStats->total_seasons ?? 0;
                    $totalEpisodesAired = $episodeStats->total_episodes ?? 0;
                }
            }

            foreach ($seasons as $seasonNum => $episodes) {
                $episodeCount += count($episodes);
            }

            $catid = $category !== -1 ? $category : '';
            $totalPages = $seriesLimit > 0 ? (int) ceil(max($totalRows, 1) / $seriesLimit) : 1;

            $this->viewData = array_merge($this->viewData, [
                'seasons' => $seasons,
                'seasonTabs' => $seasonTabs,
                'selectedSeason' => $selectedSeason,
                'show' => $show,
                'myshows' => $myshows,
                'seriestitles' => $seriestitles,
                'seriessummary' => $seriessummary,
                'seriescountry' => $seriescountry,
                'category' => $catid,
                'nodata' => $nodata,
                'episodeCount' => $episodeCount,
                'seasonCount' => $seasonCount,
                'firstEpisodeAired' => $firstEpisodeAired,
                'lastEpisodeAired' => $lastEpisodeAired,
                'totalSeasonsAvailable' => $totalSeasonsAvailable,
                'totalSeasonsAired' => $totalSeasonsAired,
                'totalEpisodesAired' => $totalEpisodesAired,
                'pagination' => [
                    'per_page' => $seriesLimit,
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_rows' => $totalRows,
                ],
                'meta_title' => 'View TV Series',
                'meta_keywords' => 'view,series,tv,show,description,details',
                'meta_description' => 'View TV Series',
            ]);

            if ($this->scalarInput($request, '_fragment') === 'season') {
                return response()->json([
                    'selectedSeason' => $selectedSeason,
                    'contentHtml' => view('series.partials.season-content', $this->viewData)->render(),
                    'paginationHtml' => view('series.partials.season-pagination', $this->viewData)->render(),
                    'url' => $selectedSeason !== null
                        ? $this->seriesUrlWithQuery($request, ['season' => (int) $selectedSeason, 'page' => $page])
                        : $this->seriesUrlWithQuery($request),
                ]);
            }

            return view('series.viewseries', $this->viewData);
        } else {
            $letter = ($id && preg_match('/^(0-9|[A-Z])$/i', $id)) ? $id : '0-9';

            $showname = $this->scalarInput($request, 'title');

            if ($showname !== '' && ! $id) {
                $letter = '';
            }

            $masterserieslist = Video::getSeriesList($this->userdata->id, $letter, $showname);
            $serieslist = $this->groupSeriesByBucket($masterserieslist);

            $this->viewData = array_merge($this->viewData, [
                'serieslist' => $serieslist,
                'seriesrange' => range('A', 'Z'),
                'seriesletter' => $letter,
                'showname' => $showname,
                'meta_title' => 'View Series List',
                'meta_keywords' => 'view,series,tv,show,description,details',
                'meta_description' => 'View Series List',
            ]);

            return view('series.viewserieslist', $this->viewData);
        }
    }

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useSeriesRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useSeriesRustFfi ?? self::$adoptGroupSeriesByBucketFfi;
    }

    public static function seriesFfi(): ?FFI
    {
        return self::$seriesFfi !== null ? (self::$seriesFfi === false ? null : self::$seriesFfi) : self::resolveSeriesFfi();
    }

    public function classifyTitleBucket(string $title): string
    {
        if (self::$useSeriesRustFfi ?? self::$adoptClassifyTitleBucketFfi) {
            $supported = false;
            $native = $this->classifyTitleBucketNative($title, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->classifyTitleBucketPhp($title);
    }

    /**
     * @param-out bool $supported
     */
    public function classifyTitleBucketNative(string $title, bool &$supported = false): ?string
    {
        $supported = false;
        if (self::$useSeriesRustFfi === false) {
            return null;
        }
        $ffi = self::$seriesFfi ?? self::resolveSeriesFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_series_classify_title_bucket($title, \strlen($title));
            if (! \is_int($code) || $code < 0) {
                return null;
            }
            $supported = true;

            return $code === 48 ? '0-9' : \chr($code);
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function classifyTitleBucketPhp(string $title): string
    {
        if (preg_match('/^[0-9]/', $title)) {
            return '0-9';
        }
        if (preg_match('/([A-Z]).*/i', $title, $hits)) {
            return strtoupper($hits[1]);
        }

        return '#';
    }

    /**
     * @param  array<int|string, mixed>  $masterserieslist
     * @return array<string, list<mixed>>
     */
    public function groupSeriesByBucket(array $masterserieslist): array
    {
        if (self::$useSeriesRustFfi ?? self::$adoptGroupSeriesByBucketFfi) {
            $supported = false;
            $native = $this->groupSeriesByBucketNative($masterserieslist, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->groupSeriesByBucketPhp($masterserieslist);
    }

    /**
     * @param  array<int|string, mixed>  $masterserieslist
     *
     * @param-out bool $supported
     *
     * @return array<string, list<mixed>>|null
     */
    public function groupSeriesByBucketNative(array $masterserieslist, bool &$supported = false): ?array
    {
        $supported = false;
        if (self::$useSeriesRustFfi === false) {
            return null;
        }
        $count = \count($masterserieslist);
        if ($count === 0) {
            $supported = true;

            return [];
        }

        $ffi = self::$seriesFfi ?? self::resolveSeriesFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $titles = \array_column($masterserieslist, 'title');
        if (\count($titles) !== $count) {
            return null;
        }
        $joined = \implode("\0", $titles);

        $cap = 1024;
        $scratch = $count <= 1024 ? self::$seriesOutBuf1024 : RustPipelineLibrary::obtainScratch($ffi, $count, $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_series_classify_titles_nul($joined, \strlen($joined), $count, $scratch, $cap);
            if (! \is_int($written) || $written !== $count) {
                return null;
            }

            $codes = FFI::string($scratch, $count);
            /** @var array<int|string, list<mixed>> $serieslist */
            $serieslist = [];
            $idx = 0;
            foreach ($masterserieslist as $s) {
                $serieslist[$codes[$idx++]][] = $s;
            }
            if (isset($serieslist[0])) {
                $serieslist['0-9'] = $serieslist[0];
                unset($serieslist[0]);
            }
            /** @var array<string, list<mixed>> $serieslist */
            \ksort($serieslist);
            $supported = true;

            return $serieslist;
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    /**
     * @param  array<int|string, mixed>  $masterserieslist
     * @return array<string, list<mixed>>
     */
    public function groupSeriesByBucketPhp(array $masterserieslist): array
    {
        $serieslist = [];
        foreach ($masterserieslist as $s) {
            if (preg_match('/^[0-9]/', $s['title'])) {
                $thisrange = '0-9';
            } elseif (preg_match('/([A-Z]).*/i', $s['title'], $hits)) {
                $thisrange = strtoupper($hits[1]);
            } else {
                // Handle titles that don't start with a letter or number
                $thisrange = '#';
            }
            $serieslist[$thisrange][] = $s;
        }
        ksort($serieslist);

        return $serieslist;
    }

    public function resolveSeason(Request $request): ?int
    {
        if (self::$useSeriesRustFfi ?? self::$adoptResolveSeasonFfi) {
            $supported = false;
            $native = $this->resolveSeasonNative($request, $supported);
            if ($supported) {
                return $native;
            }
        }

        return $this->resolveSeasonPhp($request);
    }

    /**
     * @param-out bool $supported
     */
    public function resolveSeasonNative(Request $request, bool &$supported = false): ?int
    {
        $supported = false;
        if (self::$useSeriesRustFfi === false) {
            return null;
        }

        $seasonInput = $this->scalarInput($request, 'season');
        if ($seasonInput === '') {
            $supported = true;

            return null;
        }

        $ffi = self::$seriesFfi ?? self::resolveSeriesFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_series_resolve_season($seasonInput, \strlen($seasonInput));
            if (! \is_int($res) || $res === -1) {
                return null;
            }
            $supported = true;

            return $res === -2 ? null : $res;
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function resolveSeasonPhp(Request $request): ?int
    {
        $seasonInput = $this->scalarInput($request, 'season');
        if ($seasonInput === '' || preg_match('/^\d+$/', $seasonInput) !== 1) {
            return null;
        }

        return (int) $seasonInput;
    }

    private static function resolveSeriesFfi(): ?FFI
    {
        if (self::$seriesFfi !== null) {
            return self::$seriesFfi === false ? null : self::$seriesFfi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('categorize', ApiQueryParameters::FFI_CDEF);
        if ($ffi === null) {
            self::$seriesFfi = false;

            return null;
        }
        try {
            self::$seriesOutBuf1024 = $ffi->new('char[1024]');
            self::$seriesFfi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$seriesFfi = false;

            return null;
        }
    }

    /**
     * @param  array<int, int>  $seasonCounts
     * @return array<int, array{season:int,count:int,url:string,active:bool}>
     */
    private function buildSeasonTabs(Request $request, array $seasonCounts, int $selectedSeason): array
    {
        $tabs = [];
        foreach ($seasonCounts as $season => $count) {
            $tabs[] = [
                'season' => (int) $season,
                'count' => (int) $count,
                'url' => $this->seriesUrlWithQuery($request, [
                    'season' => (int) $season,
                    'page' => 1,
                ]).'#series-episodes',
                'active' => (int) $season === $selectedSeason,
            ];
        }

        return $tabs;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function seriesUrlWithQuery(Request $request, array $query = []): string
    {
        $currentQuery = $request->query();
        unset($currentQuery['_fragment']);

        $merged = array_merge($currentQuery, $query);
        $queryString = http_build_query($merged);

        return url()->current().($queryString !== '' ? '?'.$queryString : '');
    }

    // ========================================================================
    // WAVE 59.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.50x, p99 6.72x; uncommon mean 5.90x, p99 4.11x); see RustSeriesControllerAdminLogRequestsHorizonProviderHttpFeedW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_SERIES_CONTROLLER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w593_series_query_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Sort keys of w59SeriesQueryBatch(), indexed from 1 (0 absent, 11 unknown). */
    public const array W59_SERIES_SORTS = ['posted_desc' => 1, 'posted_asc' => 2, 'name_asc' => 3, 'name_desc' => 4, 'size_asc' => 5,
        'size_desc' => 6, 'files_asc' => 7, 'files_desc' => 8, 'stats_asc' => 9, 'stats_desc' => 10];

    /** Trailer words after the per-item words of w59SeriesQueryBatch(). */
    public const int W59_SERIES_TRAILER = 49;

    public static function usesW59RustFfi(): bool
    {
        $override = self::w59RustFfiState();

        return \is_bool($override) ? $override : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 59 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 59.3 series view queries. Each item is `query|season|episode|sort|page` (missing fields are empty,
     * every field is trimmed). Per item `bucket | sxe << 5 | seasonState << 6 | episodeState << 8 | sort << 10 |
     * pageState << 14 | season << 16 | episode << 30 | score << 44 | min(queryLength, 1023) << 51`: bucket is 0 for a
     * leading digit, 1-26 for the first ASCII letter and 27 otherwise; sxe marks an `S<1-3 digits>E<1-4 digits>`
     * marker in the query; season/episode states are 0 empty, 1 one to four digits and 2 invalid, and the values
     * are the valid field or else the marker's number (0 without either); sort is 0 empty, 1-10
     * W59_SERIES_SORTS (case-insensitive) or 11 unknown; page state is 0 empty, 1 a 1-6 digit page of at least 1
     * and 2 invalid; score (0-95) rewards a usable query and penalizes invalid parameters and long queries.
     * Then 28 bucket counts, the marker, valid season and valid episode counts, 12 sort counts, three page state
     * counts, the largest page, the score sum and the distinct `season * 10000 + episode` pairs of items with a
     * valid season or a marker.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59SeriesQueryBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::w59SeriesQueryBatchNative($items) : self::w59SeriesQueryBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59SeriesQueryBatchNative(array $items): array
    {
        return self::w59Invoke($items, \count($items) + self::W59_SERIES_TRAILER) ?? self::w59SeriesQueryBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w59SeriesQueryBatchPhp(array $items): array
    {
        $out = [];
        $buckets = array_fill(0, 28, 0);
        $sorts = array_fill(0, 12, 0);
        $pages = [0, 0, 0];
        $markers = $seasons = $episodes = $maxPage = $scoreSum = 0;
        $pairs = [];
        foreach ($items as $item) {
            $f = explode('|', (string) $item, 5) + ['', '', '', '', ''];
            $q = trim($f[0]);
            $length = \strlen($q);
            if (strspn($q, '0123456789', 0, 1) === 1) {
                $bucket = 0;
            } else {
                $letter = strcspn($q, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz');
                $bucket = $letter < $length ? (\ord($q[$letter]) & 0xDF) - 64 : 27;
            }
            $sxe = preg_match('/s(\d{1,3})e(\d{1,4})/', strtolower($q), $m) === 1;
            $season = trim($f[1]);
            $seasonState = $season === '' ? 0 : (\strlen($season) <= 4 && strspn($season, '0123456789') === \strlen($season) ? 1 : 2);
            $episode = trim($f[2]);
            $episodeState = $episode === '' ? 0 : (\strlen($episode) <= 4 && strspn($episode, '0123456789') === \strlen($episode) ? 1 : 2);
            $sort = strtolower(trim($f[3]));
            $sortIdx = $sort === '' ? 0 : (self::W59_SERIES_SORTS[$sort] ?? 11);
            $page = trim($f[4]);
            if ($page === '') {
                [$pageState, $pageValue] = [0, 1];
            } elseif (\strlen($page) <= 6 && strspn($page, '0123456789') === \strlen($page) && (int) $page >= 1) {
                [$pageState, $pageValue] = [1, (int) $page];
            } else {
                [$pageState, $pageValue] = [2, 1];
            }
            $effSeason = $seasonState === 1 ? (int) $season : ($sxe ? (int) $m[1] : 0);
            $effEpisode = $episodeState === 1 ? (int) $episode : ($sxe ? (int) $m[2] : 0);
            $score = max(0, ($length > 0 ? 20 : 0) + ($bucket >= 1 && $bucket <= 26 ? 10 : 0) + ($sxe ? 30 : 0)
                + ($seasonState === 1 ? 15 : 0) + ($episodeState === 1 ? 15 : 0) + ($sortIdx >= 1 && $sortIdx <= 10 ? 5 : 0)
                - ($seasonState === 2 ? 10 : 0) - ($episodeState === 2 ? 10 : 0) - ($sortIdx === 11 ? 10 : 0)
                - ($pageState === 2 ? 10 : 0) - min(intdiv($length, 32), 20));
            $buckets[$bucket]++;
            $markers += $sxe ? 1 : 0;
            $seasons += $seasonState === 1 ? 1 : 0;
            $episodes += $episodeState === 1 ? 1 : 0;
            $sorts[$sortIdx]++;
            $pages[$pageState]++;
            $maxPage = max($maxPage, $pageValue);
            $scoreSum += $score;
            if ($seasonState === 1 || $sxe) {
                $pairs[$effSeason * 10000 + $effEpisode] = true;
            }
            $out[] = $bucket | ($sxe ? 1 : 0) << 5 | $seasonState << 6 | $episodeState << 8 | $sortIdx << 10 | $pageState << 14
                | $effSeason << 16 | $effEpisode << 30 | $score << 44 | min($length, 1023) << 51;
        }
        array_push($out, ...$buckets);
        array_push($out, $markers, $seasons, $episodes, ...$sorts);
        array_push($out, ...$pages);
        array_push($out, $maxPage, $scoreSum, \count($pairs));

        return $out;
    }

    /**
     * Wave 59 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w593', self::W59_SERIES_CONTROLLER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w59RustFfiState('ffi');
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
                $handle = self::w59RustFfiState('ffi');
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
     * Calls the Wave 59.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w59Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w59RustFfiState('ffi');
            $out = self::w59RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w59RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W59_SERIES_CONTROLLER_FFI_CDEF.)
            $written = $ffi->nntmux_w593_series_query_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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

    /**
     * Show trending TV shows (top 15 most downloaded in last 48 hours)
     *
     * @throws \Exception
     */
    public function showTrending(Request $request): mixed
    {
        // Cache key for trending TV shows (48 hours)
        $cacheKey = 'trending_tv_top_15_48h';

        // Get trending TV shows from cache or calculate (refresh every hour)
        $trendingShows = Cache::remember($cacheKey, 3600, function () {
            $fortyEightHoursAgo = Carbon::now()->subHours(48);

            $query = DB::table('videos as v')
                ->join('tv_info as ti', 'v.id', '=', 'ti.videos_id')
                ->join('releases as r', 'v.id', '=', 'r.videos_id')
                ->leftJoin('user_downloads as ud', 'r.id', '=', 'ud.releases_id')
                ->select([
                    'v.id',
                    'v.title',
                    'v.started',
                    'v.tvdb',
                    'v.tvmaze',
                    'v.trakt',
                    'v.tmdb',
                    'v.countries_id',
                    'ti.summary',
                    'ti.image',
                    DB::raw('COUNT(DISTINCT ud.id) as total_downloads'),
                    DB::raw('COUNT(DISTINCT r.id) as release_count'),
                ])
                ->where('v.type', 0) // 0 = TV
                ->where('v.title', '!=', '')
                ->where('ud.timestamp', '>=', $fortyEightHoursAgo)
                ->groupBy('v.id', 'v.title', 'v.started', 'v.tvdb', 'v.tvmaze', 'v.trakt', 'v.tmdb', 'v.countries_id', 'ti.summary', 'ti.image')
                ->havingRaw('COUNT(DISTINCT ud.id) > 0')
                ->orderByDesc('total_downloads')
                ->limit(15)
                ->get();

            return $query;
        });

        $this->viewData = array_merge($this->viewData, [
            'trendingShows' => $trendingShows,
            'meta_title' => 'Trending TV Shows - Last 48 Hours',
            'meta_keywords' => 'trending,tv,shows,series,popular,downloads,recent',
            'meta_description' => 'Browse the most popular and downloaded TV shows in the last 48 hours',
        ]);

        return view('series.trending', $this->viewData);
    }
}
