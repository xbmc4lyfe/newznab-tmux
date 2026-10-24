<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\MovieBrowseService;
use App\Services\MovieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MovieController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @param array<mixed> $genres
     * @return array<string, mixed>
     */
    public static function browsePayload(int $category, string $title, string $actors, string $director, int $rating, string $genre, int $year, array $genres, int $nextYear): array
    {
        return self::$useRustFfi ? self::browsePayloadNative($category, $title, $actors, $director, $rating, $genre, $year, $genres, $nextYear) : self::browsePayloadPhp($category, $title, $actors, $director, $rating, $genre, $year, $genres, $nextYear);
    }

    /** @param array<mixed> $genres
     * @return array<string, mixed>
     */
    public static function browsePayloadPhp(int $category, string $title, string $actors, string $director, int $rating, string $genre, int $year, array $genres, int $nextYear): array
    {
        $years = range(1903, $nextYear);
        rsort($years);
        $ratings = range(1, 9);

        return [
            'categories' => $category === -1 ? [] : [$category],
            'title' => stripslashes($title),
            'actors' => stripslashes($actors),
            'director' => stripslashes($director),
            'ratings' => $ratings,
            'rating' => in_array($rating, $ratings, true) ? $rating : '',
            'genres' => $genres,
            'genre' => in_array($genre, $genres, true) ? $genre : '',
            'years' => $years,
            'year' => in_array($year, $years, true) ? $year : '',
        ];
    }

    /** @param array<mixed> $genres
     * @return array<string, mixed>
     */
    public static function browsePayloadNative(int $category, string $title, string $actors, string $director, int $rating, string $genre, int $year, array $genres, int $nextYear): array
    {
        $result = GetNzbController::transformNative(4, [$category, $title, $actors, $director, $rating, $genre, $year, $genres, $nextYear]);

        return is_array($result) ? $result : self::browsePayloadPhp($category, $title, $actors, $director, $rating, $genre, $year, $genres, $nextYear);
    }

    /** @param array<mixed> $movie
     * @return array<mixed>
     */
    public static function detailPayload(array $movie, string $imdbid): array
    {
        return self::$useRustFfi ? self::detailPayloadNative($movie, $imdbid) : self::detailPayloadPhp($movie, $imdbid);
    }

    /** @param array<mixed> $movie
     * @return array<mixed>
     */
    public static function detailPayloadPhp(array $movie, string $imdbid): array
    {
        if (empty($movie['title'])) {
            $movie['title'] = 'Unknown Title';
        }
        if (empty($movie['imdbid'])) {
            $movie['imdbid'] = $imdbid;
        }

        return $movie;
    }

    /** @param array<mixed> $movie
     * @return array<mixed>
     */
    public static function detailPayloadNative(array $movie, string $imdbid): array
    {
        $result = GetNzbController::transformNative(5, [$movie, $imdbid]);

        return is_array($result) ? $result : self::detailPayloadPhp($movie, $imdbid);
    }

    /** @param array<mixed> $movie
     * @return array<mixed>
     */
    public static function coverPayload(array $movie): array
    {
        return self::$useRustFfi ? self::coverPayloadNative($movie) : self::coverPayloadPhp($movie);
    }

    /** @param array<mixed> $movie
     * @return array<mixed>
     */
    public static function coverPayloadPhp(array $movie): array
    {
        $payload = [];
        foreach (['imdbid', 'videos_id', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'gamesinfo_id', 'anidbid'] as $key) {
            if (array_key_exists($key, $movie)) {
                $payload[$key] = $movie[$key];
            }
        }

        return $payload;
    }

    /** @param array<mixed> $movie
     * @return array<mixed>
     */
    public static function coverPayloadNative(array $movie): array
    {
        $result = GetNzbController::transformNative(6, [$movie]);

        return is_array($result) ? $result : self::coverPayloadPhp($movie);
    }

    public static function validTrailerId(string $id): bool
    {
        return self::$useRustFfi ? self::validTrailerIdNative($id) : self::validTrailerIdPhp($id);
    }

    public static function validTrailerIdPhp(string $id): bool
    {
        return $id !== '' && ctype_digit($id);
    }

    public static function validTrailerIdNative(string $id): bool
    {
        $result = GetNzbController::transformNative(7, [$id]);

        return is_bool($result) ? $result : self::validTrailerIdPhp($id);
    }

    protected MovieBrowseService $movieBrowseService;

    protected MovieService $movieService;

    public function __construct(MovieBrowseService $movieBrowseService, MovieService $movieService)
    {
        parent::__construct();
        $this->movieBrowseService = $movieBrowseService;
        $this->movieService = $movieService;
    }

    /**
     * @throws \Exception
     */
    public function showMovies(Request $request, string $id = ''): mixed
    {
        $moviecats = Category::getChildren(Category::MOVIE_ROOT)->map(function ($mcat) {
            return ['id' => $mcat->id, 'title' => $mcat->title];
        });

        $category = $request->has('imdb') ? -1 : $this->integerInput($request, 't', Category::MOVIE_ROOT);
        if ($id && $moviecats->pluck('title')->contains($id)) {
            $cat = Category::where(['title' => $id, 'root_categories_id' => Category::MOVIE_ROOT])->first(['id']);
            $category = $cat !== null ? (int) $cat->id : Category::MOVIE_ROOT;
        }

        $browse = self::browsePayload(
            $category,
            $this->scalarInput($request, 'title'),
            $this->scalarInput($request, 'actors'),
            $this->scalarInput($request, 'director'),
            $this->integerInput($request, 'rating', 0),
            $this->scalarInput($request, 'genre'),
            $this->integerInput($request, 'year', 0),
            $this->movieBrowseService->getGenres(),
            now()->addYear()->year
        );
        $catarray = $browse['categories'];

        $page = $this->resolvePage($request);
        $perPage = (int) config('nntmux.items_per_cover_page');
        $offset = $this->paginationOffset($page, $perPage);
        $ordering = $this->movieBrowseService->getMovieOrdering();
        $orderby = $this->resolveOrderBy($request, $ordering);

        $rslt = $this->movieBrowseService->getMovieRange($page, $catarray, $offset, $perPage, $orderby, -1, (array) $this->userdata->categoryexclusions);
        $totalCount = $rslt->isNotEmpty() ? ($rslt[0]->_totalcount ?? 0) : 0;
        $results = $this->paginate($rslt ?? [], $totalCount, $perPage, $page, $request->url(), $request->query());

        $movies = $results->map(function ($result) {
            $result->genre = makeFieldLinks($result, 'genre', 'movies');
            $result->actors = makeFieldLinks($result, 'actors', 'movies');
            $result->director = makeFieldLinks($result, 'director', 'movies');

            // Add cover image URL using helper function
            $result->cover = getReleaseCover($result);

            return $result;
        });

        $catname = $category === -1 ? 'All' : Category::find($category) ?? 'All';

        $this->viewData = array_merge($this->viewData, [
            'cpapi' => $this->userdata->cp_api,
            'cpurl' => $this->userdata->cp_url,
            'catlist' => $moviecats,
            'category' => $category,
            'categorytitle' => $id,
            'title' => $browse['title'],
            'actors' => $browse['actors'],
            'director' => $browse['director'],
            'ratings' => $browse['ratings'],
            'rating' => $browse['rating'],
            'genres' => $browse['genres'],
            'genre' => $browse['genre'],
            'years' => $browse['years'],
            'year' => $browse['year'],
            'catname' => $catname,
            'resultsadd' => $movies,
            'results' => $results,
            'covgroup' => 'movies',
            'meta_title' => 'Browse Movies',
            'meta_keywords' => 'browse,nzb,description,details',
            'meta_description' => 'Browse for Movies',
            'movie_layout' => $this->userdata->movie_layout ?? 2,
        ]);

        // Return the appropriate view
        $viewName = $request->has('imdb') ? 'movies.viewmoviefull' : 'movies.index';

        return view($viewName, $this->viewData);
    }

    /**
     * Show a single movie with all its releases
     *
     * @throws \Exception
     */
    public function showMovie(Request $request, string $imdbid): mixed
    {
        // Get movie info
        $movieInfo = $this->movieService->getMovieInfo($imdbid);

        if (! $movieInfo) {
            return redirect()->route('Movies')->with('error', 'Movie not found');
        }

        // Convert Eloquent model to array
        $movieArray = self::detailPayload($movieInfo->toArray(), $imdbid);

        // Only process fields if they exist and are not empty
        if (! empty($movieArray['genre'])) {
            $movieArray['genre'] = makeFieldLinks($movieArray, 'genre', 'movies');
        }
        if (! empty($movieArray['actors'])) {
            $movieArray['actors'] = makeFieldLinks($movieArray, 'actors', 'movies');
        }
        if (! empty($movieArray['director'])) {
            $movieArray['director'] = makeFieldLinks($movieArray, 'director', 'movies');
        }

        // Add cover image URL using helper function
        $movieArray['cover'] = getReleaseCover(self::coverPayload($movieArray));

        // Get all releases for this movie directly (no limit)
        $releases = $this->movieBrowseService->getMovieReleases($imdbid, (array) $this->userdata->categoryexclusions);

        $this->viewData = array_merge($this->viewData, [
            'movie' => $movieArray,
            'releases' => $releases,
            'meta_title' => ($movieArray['title'] ?? 'Movie').' - Movie Details',
            'meta_keywords' => 'movie,details,releases',
            'meta_description' => 'View all releases for '.($movieArray['title'] ?? 'this movie'),
        ]);

        return view('movies.viewmoviefull', $this->viewData);
    }

    /**
     * @return JsonResponse|View
     */
    public function showTrailer(Request $request)
    {
        $movieId = $this->scalarInput($request, 'id');
        if (self::validTrailerId($movieId)) {
            $mov = $this->movieService->getMovieInfo($movieId);

            if (! $mov) {
                return response()->json(['message' => 'There is no trailer for this movie.'], 404);
            }

            $modal = $request->has('modal');

            $viewData = [
                'movie' => $mov,
            ];

            // Return different views for modal vs full page
            if ($modal) {
                return view('movies.trailer-modal', $viewData);
            }

            $this->viewData = array_merge($this->viewData, [
                'movie' => $mov,
                'title' => 'Info for '.$mov['title'],
                'meta_title' => '',
                'meta_keywords' => '',
                'meta_description' => '',
            ]);

            return view('movies.viewmovietrailer', $this->viewData);
        }

        return response()->json(['message' => 'Invalid movie ID.'], 400);
    }

    /**
     * Show trending movies (top 15 most downloaded in last 48 hours)
     *
     * @throws \Exception
     */
    public function showTrending(Request $request): mixed
    {

        // Cache key for trending movies (48 hours)
        $cacheKey = 'trending_movies_top_15_48h';

        // Get trending movies from cache or calculate (refresh every hour)
        $trendingMovies = Cache::remember($cacheKey, 3600, function () {
            // Calculate timestamp for 48 hours ago
            $fortyEightHoursAgo = Carbon::now()->subHours(48);

            // Get movies with their download counts from last 48 hours
            // Join with user_downloads to get actual download timestamps
            $query = DB::table('movieinfo as m')
                ->join('releases as r', 'm.imdbid', '=', 'r.imdbid')
                ->leftJoin('user_downloads as ud', 'r.id', '=', 'ud.releases_id')
                ->select([
                    'm.imdbid',
                    'm.title',
                    'm.year',
                    'm.rating',
                    'm.plot',
                    'm.genre',
                    'm.cover',
                    'm.tmdbid',
                    'm.traktid',
                    DB::raw('COUNT(DISTINCT ud.id) as total_downloads'),
                    DB::raw('COUNT(DISTINCT r.id) as release_count'),
                ])
                ->where('m.title', '!=', '')
                ->whereNotNull('m.imdbid')
                ->where('m.imdbid', '!=', '')
                ->where('ud.timestamp', '>=', $fortyEightHoursAgo)
                ->groupBy('m.imdbid', 'm.title', 'm.year', 'm.rating', 'm.plot', 'm.genre', 'm.cover', 'm.tmdbid', 'm.traktid')
                ->havingRaw('COUNT(DISTINCT ud.id) > 0')
                ->orderByDesc('total_downloads')
                ->limit(15)
                ->get();

            // Process the results
            return $query->map(function ($item) {
                // Add cover image URL using helper function
                $coverArray = [
                    'imdbid' => $item->imdbid,
                    'tmdbid' => $item->tmdbid,
                    'cover' => $item->cover,
                ];
                $item->cover = getReleaseCover(self::coverPayload($coverArray));

                return $item;
            });
        });

        $this->viewData = array_merge($this->viewData, [
            'trendingMovies' => $trendingMovies,
            'meta_title' => 'Trending Movies - Last 48 Hours',
            'meta_keywords' => 'trending,movies,popular,downloads,recent',
            'meta_description' => 'Browse the most popular and downloaded movies in the last 48 hours',
        ]);

        return view('movies.trending', $this->viewData);
    }

    /**
     * Update user's movie layout preference
     */
    public function updateLayout(Request $request): mixed
    {
        $request->validate([
            'layout' => 'required|integer|in:1,2',
        ]);

        $user = auth()->user();
        if ($user) {
            $user->movie_layout = (int) $request->input('layout');
            $user->save();

            return response()->json(['success' => true, 'layout' => $user->movie_layout]);
        }

        return response()->json(['success' => false, 'message' => 'User not authenticated'], 401);
    }
}
