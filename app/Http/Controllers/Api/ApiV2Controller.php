<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Api\ReleaseData;
use App\Http\Controllers\BasePageController;
use App\Http\Controllers\GetNzbController;
use App\Models\Category;
use App\Models\Release;
use App\Models\User;
use App\Services\Api\ApiCapabilitiesService;
use App\Services\Api\ApiQueryParameters;
use App\Services\Api\ApiReleaseRowCache;
use App\Services\Api\ApiUsageService;
use App\Services\Api\ApiUserResolver;
use App\Services\Api\V2\ApiV2Presenter;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseSearchService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ApiV2Controller extends BasePageController
{
    private ReleaseSearchService $releaseSearchService;

    private ReleaseBrowseService $releaseBrowseService;

    private ApiReleaseRowCache $releaseRowCache;

    private ApiQueryParameters $queryParameters;

    private ApiUsageService $usageService;

    private ApiUserResolver $userResolver;

    private ApiV2Presenter $presenter;

    private ApiCapabilitiesService $capabilitiesService;

    /**
     * @var array<int, object>
     */
    private array $resolvedUserStats = [];

    public function __construct(
        ReleaseSearchService $releaseSearchService,
        ReleaseBrowseService $releaseBrowseService,
        ?ApiReleaseRowCache $releaseRowCache = null,
        ?ApiQueryParameters $queryParameters = null,
        ?ApiUsageService $usageService = null,
        ?ApiUserResolver $userResolver = null,
        ?ApiV2Presenter $presenter = null,
        ?ApiCapabilitiesService $capabilitiesService = null,
    ) {
        $this->releaseSearchService = $releaseSearchService;
        $this->releaseBrowseService = $releaseBrowseService;
        $this->releaseRowCache = $releaseRowCache ?? app(ApiReleaseRowCache::class);
        $this->queryParameters = $queryParameters ?? app(ApiQueryParameters::class);
        $this->usageService = $usageService ?? app(ApiUsageService::class);
        $this->userResolver = $userResolver ?? app(ApiUserResolver::class);
        $this->presenter = $presenter ?? app(ApiV2Presenter::class);
        $this->capabilitiesService = $capabilitiesService ?? app(ApiCapabilitiesService::class);
    }

    /**
     * Validate API token and return cached user, or a normalized JSON API error on failure.
     * Caches user lookup for 5 minutes to reduce DB hits.
     */
    private function resolveUser(Request $request): User|JsonResponse
    {
        if ($request->missing('api_token') || $request->isNotFilled('api_token')) {
            return apiJsonError(200, 'Missing parameter (api_token)');
        }

        $apiToken = $request->input('api_token');
        $resolved = $request->attributes->get('nntmux.api_user');
        $user = $resolved instanceof User ? $resolved : $this->userResolver->v2((string) $apiToken);

        if (! $user || ! $user->hasVerifiedEmail()) {
            return apiJsonError(100);
        }

        if ($user->is_disabled || $user->hasRole('Disabled')) {
            return apiJsonError(101);
        }

        $user->loadMissing('role');

        $userStats = $this->userStatsFor($user);
        $thisRequests = (int) ($userStats->api_count ?? 0);
        $maxRequests = (int) $user->role->apirequests;
        if ($thisRequests > $maxRequests) {
            return apiJsonError(500, 'Request limit reached');
        }

        return $user;
    }

    /**
     * Build the standard user stats portion of an API response.
     * Uses the consolidated single-query + 60s cache from ApiController.
     *
     * @return array<string, mixed>
     */
    private function buildUserStatsResponse(User $user): array
    {
        $userStats = $this->userStatsFor($user);

        return [
            'apiCurrent' => (int) ($userStats->api_count ?? 0),
            'apiMax' => $user->role->apirequests,
            'grabCurrent' => (int) ($userStats->grab_count ?? 0),
            'grabMax' => $user->role->downloadrequests,
            'apiOldestTime' => $userStats->api_time ? Carbon::parse($userStats->api_time)->toRfc2822String() : '',
            'grabOldestTime' => $userStats->grab_time ? Carbon::parse($userStats->grab_time)->toRfc2822String() : '',
        ];
    }

    private function userStatsFor(User $user): object
    {
        return $this->resolvedUserStats[$user->id] ??= $this->usageService->statistics($user->id);
    }

    private function recordApiRequest(User $user, Request $request): void
    {
        $this->usageService->record($user, $request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonResponse(array $data, int $status = 200): JsonResponse
    {
        return $this->presenter->json($data, $status);
    }

    /**
     * Build the standard search-results JSON response.
     *
     * Replaces the legacy Fractal `['Results' => fractal(...)]` envelope with a
     * lower-case `results` array of {@see ReleaseData} payloads. Pagination
     * total and per-user API/grab quotas are kept inline alongside the array
     * because a JSON object cannot carry both top-level metadata fields and a
     * bare array body.
     *
     * @param  iterable<int, Release|\stdClass>  $rows
     */
    private function buildSearchResponse(iterable $rows, User $user): JsonResponse
    {
        return $this->presenter->search($rows, $user, $this->buildUserStatsResponse($user));
    }

    private function parseMaxAge(Request $request): int|JsonResponse
    {
        if (! $request->has('maxage')) {
            return -1;
        }
        if ($request->isNotFilled('maxage')) {
            return $this->jsonResponse(['error' => 'Incorrect parameter (maxage must not be empty)'], 400);
        }
        if (! is_numeric($request->input('maxage'))) {
            return $this->jsonResponse(['error' => 'Incorrect parameter (maxage must be numeric)'], 400);
        }

        return (int) $request->input('maxage');
    }

    private function parseSort(Request $request): string|JsonResponse
    {
        if (! $request->has('sort')) {
            return 'posted_desc';
        }

        $sort = strtolower(trim((string) $request->input('sort')));
        if ($sort === '') {
            return $this->jsonResponse(['error' => 'Incorrect parameter (sort must not be empty)'], 400);
        }
        if (! preg_match('/^(cat|name|size|files|stats|posted)_(asc|desc)$/', $sort)) {
            return $this->jsonResponse(['error' => 'Incorrect parameter (sort must be one of: cat_asc/desc, name_asc/desc, size_asc/desc, files_asc/desc, stats_asc/desc, posted_asc/desc)'], 400);
        }

        return $sort;
    }

    public function capabilities(Request $request): JsonResponse
    {
        $response = $this->jsonResponse($this->capabilitiesService->v2());
        $response->setPublic();
        $response->setMaxAge(300);
        $response->setEtag(hash('sha256', (string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @throws \Throwable
     */
    public function movie(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->recordApiRequest($user, $request);

        // Get request parameters efficiently
        $imdbId = (string) Str::replace('tt', '', (string) $request->input('imdbid', ''));
        $tmdbId = (int) $request->input('tmdbid', -1);
        $traktId = (int) $request->input('traktid', -1);
        $minSize = max(0, (int) $request->input('minsize', 0));
        $searchName = $request->input('id', '');
        if ($searchName === '' && ! imdb_id_is_valid($imdbId) && $tmdbId <= 0 && $traktId <= 0) {
            return $this->jsonResponse(['error' => 'Specify id (query), imdbid, tmdbid, or traktid'], 400);
        }
        $offset = $this->queryParameters->offset($request);
        $limit = $this->queryParameters->limit($request);
        $categoryID = $this->queryParameters->categories($request);
        $maxAge = $this->parseMaxAge($request);
        if (! is_int($maxAge)) {
            return $maxAge;
        }
        $sort = $this->parseSort($request);
        if (! is_string($sort)) {
            return $sort;
        }
        $catExclusions = User::getCachedCategoryExclusionById($user->id);

        $relData = $this->releaseRowCache->remember('v2', 'movie', [
            'imdbid' => $imdbId,
            'tmdbid' => $tmdbId,
            'traktid' => $traktId,
            'offset' => $offset,
            'limit' => $limit,
            'id' => $searchName,
            'sort' => $sort,
            'category' => $categoryID,
            'max_age' => $maxAge,
            'min_size' => $minSize,
            'excluded' => $catExclusions,
        ], function () use (
            $imdbId, $tmdbId, $traktId, $offset, $limit, $searchName, $sort,
            $categoryID, $maxAge, $minSize, $catExclusions
        ) {
            return $this->releaseSearchService->moviesSearch(
                $imdbId,
                $tmdbId,
                $traktId,
                $offset,
                $limit,
                $searchName,
                $categoryID,
                $maxAge,
                $minSize,
                $catExclusions,
                $sort
            );
        });

        return $this->buildSearchResponse($relData, $user);
    }

    public function audio(Request $request): JsonResponse|Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->recordApiRequest($user, $request);

        if ($request->has('id') && $request->isNotFilled('id')) {
            return $this->jsonResponse(['error' => 'Incorrect parameter (id must not be empty)'], 400);
        }

        $offset = $this->queryParameters->offset($request);
        $limit = $this->queryParameters->limit($request);
        $categoryID = $this->queryParameters->categories($request);
        $maxAge = $this->parseMaxAge($request);
        if (! is_int($maxAge)) {
            return $maxAge;
        }
        $sort = $this->parseSort($request);
        if (! is_string($sort)) {
            return $sort;
        }

        $minSize = max(0, (int) $request->input('minsize', 0));
        $catExclusions = User::getCachedCategoryExclusionById($user->id);
        $groupName = $this->queryParameters->group($request);
        $searchName = (string) $request->input('id', '');

        if ($searchName === '') {
            if ($categoryID === [-1]) {
                $categoryID = [Category::MUSIC_ROOT];
            }
        }

        $relData = $this->releaseRowCache->remember('v2', 'audio', [
            'id' => $searchName,
            'group' => $groupName,
            'offset' => $offset,
            'limit' => $limit,
            'sort' => $sort,
            'category' => $categoryID,
            'max_age' => $maxAge,
            'min_size' => $minSize,
            'excluded' => $catExclusions,
        ], function () use ($searchName, $groupName, $offset, $limit, $sort, $maxAge, $catExclusions, $categoryID, $minSize) {
            if ($searchName === '') {
                return $this->releaseBrowseService->getBrowseRangeForApi(
                    1,
                    $categoryID,
                    $offset,
                    $limit,
                    $sort,
                    $maxAge,
                    $catExclusions,
                    $groupName,
                    $minSize
                );
            }

            return $this->releaseSearchService->apiMusicSearch(
                $searchName,
                $groupName,
                $offset,
                $limit,
                $maxAge,
                $catExclusions,
                $categoryID,
                $minSize,
                $sort
            );
        });

        return $this->buildSearchResponse($relData, $user);
    }

    public function books(Request $request): JsonResponse|Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->recordApiRequest($user, $request);

        if ($request->has('id') && $request->isNotFilled('id')) {
            return $this->jsonResponse(['error' => 'Incorrect parameter (id must not be empty)'], 400);
        }

        $offset = $this->queryParameters->offset($request);
        $limit = $this->queryParameters->limit($request);
        $categoryID = $this->queryParameters->categories($request);
        $maxAge = $this->parseMaxAge($request);
        if (! is_int($maxAge)) {
            return $maxAge;
        }
        $sort = $this->parseSort($request);
        if (! is_string($sort)) {
            return $sort;
        }

        $minSize = max(0, (int) $request->input('minsize', 0));
        $catExclusions = User::getCachedCategoryExclusionById($user->id);
        $groupName = $this->queryParameters->group($request);
        $searchName = (string) $request->input('id', '');

        if ($searchName === '') {
            if ($categoryID === [-1]) {
                $categoryID = [Category::BOOKS_ROOT];
            }
        }

        $relData = $this->releaseRowCache->remember('v2', 'books', [
            'id' => $searchName,
            'group' => $groupName,
            'offset' => $offset,
            'limit' => $limit,
            'sort' => $sort,
            'category' => $categoryID,
            'max_age' => $maxAge,
            'min_size' => $minSize,
            'excluded' => $catExclusions,
        ], function () use ($searchName, $groupName, $offset, $limit, $sort, $maxAge, $catExclusions, $categoryID, $minSize) {
            if ($searchName === '') {
                return $this->releaseBrowseService->getBrowseRangeForApi(
                    1,
                    $categoryID,
                    $offset,
                    $limit,
                    $sort,
                    $maxAge,
                    $catExclusions,
                    $groupName,
                    $minSize
                );
            }

            return $this->releaseSearchService->apiBookSearch(
                $searchName,
                $groupName,
                $offset,
                $limit,
                $maxAge,
                $catExclusions,
                $categoryID,
                $minSize,
                $sort
            );
        });

        return $this->buildSearchResponse($relData, $user);
    }

    public function anime(Request $request): JsonResponse|Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->recordApiRequest($user, $request);

        $q = (string) $request->input('id', '');
        $anidb = (int) $request->input('anidbid', -1);
        $anilist = (int) $request->input('anilistid', -1);
        if ($q === '' && $anidb <= 0 && $anilist <= 0) {
            return $this->jsonResponse(['error' => 'Specify id (query), anidbid, or anilistid'], 400);
        }

        $offset = $this->queryParameters->offset($request);
        $limit = $this->queryParameters->limit($request);
        $categoryID = $this->queryParameters->categories($request);
        $maxAge = $this->parseMaxAge($request);
        if (! is_int($maxAge)) {
            return $maxAge;
        }
        $sort = $this->parseSort($request);
        if (! is_string($sort)) {
            return $sort;
        }

        $catExclusions = User::getCachedCategoryExclusionById($user->id);

        $relData = $this->releaseRowCache->remember('v2', 'anime', [
            'id' => $q,
            'anidbid' => $anidb,
            'anilistid' => $anilist,
            'offset' => $offset,
            'limit' => $limit,
            'sort' => $sort,
            'category' => $categoryID,
            'max_age' => $maxAge,
            'excluded' => $catExclusions,
        ], fn () => $this->releaseSearchService->animeSearch(
            $anidb,
            $offset,
            $limit,
            $q,
            $categoryID,
            $maxAge,
            $catExclusions,
            $anilist,
            $sort
        ));

        return $this->buildSearchResponse($relData, $user);
    }

    /**
     * @throws \Exception
     * @throws \Throwable
     */
    public function apiSearch(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->recordApiRequest($user, $request);

        $offset = $this->queryParameters->offset($request);
        $catExclusions = User::getCachedCategoryExclusionById($user->id);
        $minSize = $request->has('minsize') && $request->input('minsize') > 0 ? $request->input('minsize') : 0;
        $maxAge = $this->parseMaxAge($request);
        if (! is_int($maxAge)) {
            return $maxAge;
        }
        $sort = $this->parseSort($request);
        if (! is_string($sort)) {
            return $sort;
        }
        $groupName = $this->queryParameters->group($request);
        if (is_array($groupName)) {
            $groupName = $groupName[0] ?? -1;
        }
        $categoryID = $this->queryParameters->categories($request);
        $limit = $this->queryParameters->limit($request);

        $searchName = $request->input('id');
        $relData = $this->releaseRowCache->remember('v2', 'search', [
            'id' => $searchName,
            'group' => $groupName,
            'offset' => $offset,
            'limit' => $limit,
            'sort' => $sort,
            'category' => $categoryID,
            'max_age' => $maxAge,
            'min_size' => $minSize,
            'excluded' => $catExclusions,
        ], function () use ($request, $searchName, $groupName, $offset, $limit, $maxAge, $catExclusions, $categoryID, $minSize, $sort) {
            if ($request->has('id')) {
                return $this->releaseSearchService->apiSearch(
                    $searchName,
                    $groupName,
                    $offset,
                    $limit,
                    $maxAge,
                    $catExclusions,
                    $categoryID,
                    $minSize,
                    $sort
                );
            }

            return $this->releaseBrowseService->getBrowseRangeForApi(
                1,
                $categoryID,
                $offset,
                $limit,
                $sort,
                $maxAge,
                $catExclusions,
                $groupName,
                $minSize
            );
        });

        return $this->buildSearchResponse($relData, $user);
    }

    /**
     * @throws \Exception
     * @throws \Throwable
     */
    public function tv(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $catExclusions = User::getCachedCategoryExclusionById($user->id);
        $minSize = $request->has('minsize') && $request->input('minsize') > 0 ? $request->input('minsize') : 0;
        if (! $this->hasTvSearchParameters($request)) {
            return $this->jsonResponse(['error' => 'Specify id (query), vid, tvdbid, traktid, rid, tvmazeid, imdbid, or tmdbid'], 400);
        }
        $maxAge = $this->parseMaxAge($request);
        if (! is_int($maxAge)) {
            return $maxAge;
        }
        $sort = $this->parseSort($request);
        if (! is_string($sort)) {
            return $sort;
        }
        $this->recordApiRequest($user, $request);

        $siteIdArr = [
            'id' => $request->input('vid') ?? null,
            'tvdb' => $request->input('tvdbid') ?? null,
            'trakt' => $request->input('traktid') ?? null,
            'tvrage' => $request->input('rid') ?? null,
            'tvmaze' => $request->input('tvmazeid') ?? null,
            'imdb' => $request->input('imdbid') ?? null,
            'tmdb' => $request->input('tmdbid') ?? null,
        ];

        // Process season only queries or Season and Episode/Airdate queries

        $series = $request->input('season') ?? '';
        $episode = $request->input('ep') ?? '';

        if (preg_match('#^(19|20)\d{2}$#', $series, $year) && str_contains($episode, '/')) {
            $airDate = str_replace('/', '-', $year[0].'-'.$episode);
        }

        $offset = $this->queryParameters->offset($request);
        $limit = $this->queryParameters->limit($request);
        $categoryID = $this->queryParameters->categories($request);
        $airDate = $airDate ?? '';
        $searchName = $request->input('id') ?? '';

        $relData = $this->releaseRowCache->remember('v2', 'tv', [
            'site_ids' => $siteIdArr,
            'season' => $series,
            'episode' => $episode,
            'air_date' => $airDate,
            'offset' => $offset,
            'limit' => $limit,
            'id' => $searchName,
            'category' => $categoryID,
            'max_age' => $maxAge,
            'min_size' => $minSize,
            'excluded' => $catExclusions,
            'sort' => $sort,
        ], fn () => $this->releaseSearchService->apiTvSearch(
            $siteIdArr,
            $series,
            $episode,
            $airDate,
            $offset,
            $limit,
            $searchName,
            $categoryID,
            $maxAge,
            $minSize,
            $catExclusions,
            $sort
        ));

        return $this->buildSearchResponse($relData, $user);
    }

    public function getNzb(Request $request): Application|ResponseFactory|JsonResponse|Redirector|RedirectResponse
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->recordApiRequest($user, $request);
        $relData = Release::checkGuidForApi($request->input('id'));
        if ($relData) {
            $request->attributes->set(GetNzbController::REQUEST_USER_ATTRIBUTE, $user);

            return app(GetNzbController::class)->getNzb($request);
        }

        return $this->jsonResponse(['data' => 'No such item (the guid you provided has no release in our database)'], 404);
    }

    public function details(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($request->missing('id')) {
            return $this->jsonResponse(['error' => 'Missing parameter (guid is required for single release details)'], 400);
        }

        $this->recordApiRequest($user, $request);
        $guid = $request->input('id');
        $relData = $this->releaseRowCache->remember('v2', 'details', [
            'guid' => $guid,
        ], fn () => Release::getByGuidForApi($guid, false));

        if ($relData === null) {
            return $this->jsonResponse(['error' => 'No such item'], 404);
        }

        return $this->presenter->details($relData, $user);
    }

    private function hasTvSearchParameters(Request $request): bool
    {
        return $request->filled('id')
            || $request->filled('vid')
            || $request->filled('tvdbid')
            || $request->filled('traktid')
            || $request->filled('rid')
            || $request->filled('tvmazeid')
            || $request->filled('imdbid')
            || $request->filled('tmdbid');
    }
}
