<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TvEpisode;
use App\Models\UserSerie;
use App\Models\Video;
use App\Services\SeriesReleaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SeriesController extends BasePageController
{
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

    private function resolveSeason(Request $request): ?int
    {
        $seasonInput = $this->scalarInput($request, 'season');
        if ($seasonInput === '' || preg_match('/^\d+$/', $seasonInput) !== 1) {
            return null;
        }

        return (int) $seasonInput;
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
