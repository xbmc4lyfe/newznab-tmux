<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseSearchService;
use App\Services\Search\Contracts\SearchServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class SearchController extends BasePageController
{
    private SearchServiceInterface $searchService;

    private ReleaseSearchService $releaseSearchService;

    private ReleaseBrowseService $releaseBrowseService;

    public function __construct(
        SearchServiceInterface $searchService,
        ReleaseSearchService $releaseSearchService,
        ReleaseBrowseService $releaseBrowseService
    ) {
        parent::__construct();
        $this->searchService = $searchService;
        $this->releaseSearchService = $releaseSearchService;
        $this->releaseBrowseService = $releaseBrowseService;
    }

    /**
     * @throws \Exception
     */
    public function search(Request $request): mixed
    {

        $results = [];

        $searchType = 'basic';
        if ($request->has('search_type') && $request->input('search_type') === 'adv') {
            $searchType = 'advanced';
        }

        $ordering = $this->releaseBrowseService->getBrowseOrdering();
        $orderBy = $this->resolveOrderBy($request, $ordering);
        $page = $this->resolvePage($request);
        $perPage = (int) config('nntmux.items_per_page');
        $offset = $this->paginationOffset($page, $perPage);

        $subject = '';
        $search = '';
        $id = '';
        $category = [0];
        $lastvisit = $this->userdata->lastlogin;

        if ($searchType === 'basic' && ($request->filled('id') || $request->filled('subject') || $request->filled('search'))) {
            $searchString = [];
            switch (true) {
                case $request->filled('subject'):
                    $searchString['searchname'] = $this->scalarInput($request, 'subject');
                    $subject = $searchString['searchname'];
                    break;
                case $request->filled('id'):
                    $searchString['searchname'] = $this->scalarInput($request, 'id');
                    $id = $searchString['searchname'];
                    break;
                case $request->filled('search'):
                    $searchString['searchname'] = $this->scalarInput($request, 'search');
                    $search = $searchString['searchname'];
                    break;
                default:
                    $searchString['searchname'] = '';
            }

            $categoryID = $this->resolveCategoryIdsFromRequest($request);

            $orderByUrls = [];
            foreach ($this->releaseBrowseService->getBrowseOrdering() as $orderType) {
                $orderByUrls['orderby'.$orderType] = url('/search?search='.htmlentities($searchString['searchname'], ENT_QUOTES | ENT_HTML5).'&t='.implode(',', $categoryID).'&ob='.$orderType);
            }

            $rslt = $this->releaseSearchService->search(
                $searchString,
                -1,
                -1,
                -1,
                -1,
                -1,
                $offset,
                $perPage,
                $orderBy,
                -1,
                $this->userdata->categoryexclusions ?? [],
                'basic',
                $categoryID);

            $results = $this->paginate($rslt ?? [], $rslt[0]->_totalrows ?? 0, $perPage, $page, $request->url(), $request->query());
            $category = $categoryID;
        } else {
            $orderByUrls = [];
        }

        $searchVars = [
            'searchadvr' => '',
            'searchadvsubject' => '',
            'searchadvposter' => '',
            'searchadvfilename' => '',
            'searchadvdaysnew' => '',
            'searchadvdaysold' => '',
            'searchadvgroups' => '',
            'searchadvcat' => '',
            'searchadvsizefrom' => '',
            'searchadvsizeto' => '',
            'searchadvhasnfo' => '',
            'searchadvhascomments' => '',
        ];

        foreach ($searchVars as $searchVarKey => $searchVar) {
            $searchVars[$searchVarKey] = $this->scalarInput($request, $searchVarKey);
        }

        // Map new form field names to old internal names
        if ($request->has('minage')) {
            $searchVars['searchadvdaysnew'] = $this->scalarInput($request, 'minage');
        }
        if ($request->has('maxage')) {
            $searchVars['searchadvdaysold'] = $this->scalarInput($request, 'maxage');
        }
        if ($request->has('group')) {
            $searchVars['searchadvgroups'] = $this->scalarInput($request, 'group');
        }
        if ($request->has('minsize')) {
            $searchVars['searchadvsizefrom'] = $this->scalarInput($request, 'minsize');
        }
        if ($request->has('maxsize')) {
            $searchVars['searchadvsizeto'] = $this->scalarInput($request, 'maxsize');
        }
        // Map basic search field to advanced search when in advanced mode
        if ($request->has('search') && $searchType === 'advanced') {
            $searchVars['searchadvr'] = $this->scalarInput($request, 'search');
        }
        // Map basic category field to advanced category when in advanced mode
        if ($request->has('t') && $searchType === 'advanced') {
            $searchVars['searchadvcat'] = implode(',', $this->resolveCategoryIdsFromRequest($request));
        }

        $searchVars['selectedgroup'] = $searchVars['searchadvgroups'];
        $searchVars['selectedcat'] = $searchVars['searchadvcat'];
        $searchVars['selectedsizefrom'] = $searchVars['searchadvsizefrom'];
        $searchVars['selectedsizeto'] = $searchVars['searchadvsizeto'];

        if ($searchType !== 'basic' && $request->missing('id') && $request->missing('subject') && $request->anyFilled(['searchadvr', 'searchadvsubject', 'searchadvfilename', 'searchadvposter', 'search'])) {
            $orderByString = '';
            foreach ($searchVars as $searchVarKey => $searchVar) {
                $orderByString .= "&$searchVarKey=".htmlentities($searchVar, ENT_QUOTES | ENT_HTML5);
            }
            $orderByString = ltrim($orderByString, '&');
            if ($request->filled('t')) {
                $orderByString .= '&t='.urlencode(implode(',', $this->resolveCategoryIdsFromRequest($request)));
            }

            $orderByUrls = [];
            foreach ($ordering as $orderType) {
                $orderByUrls['orderby'.$orderType] = url('/search?'.$orderByString.'&search_type=adv&ob='.$orderType);
            }

            $searchArr = [
                'searchname' => $searchVars['searchadvr'] === '' ? -1 : $searchVars['searchadvr'],
                'name' => $searchVars['searchadvsubject'] === '' ? -1 : $searchVars['searchadvsubject'],
                'fromname' => $searchVars['searchadvposter'] === '' ? -1 : $searchVars['searchadvposter'],
                'filename' => $searchVars['searchadvfilename'] === '' ? -1 : $searchVars['searchadvfilename'],
            ];

            $rslt = $this->releaseSearchService->search(
                $searchArr,
                $searchVars['searchadvgroups'],
                $searchVars['searchadvsizefrom'],
                $searchVars['searchadvsizeto'],
                ($searchVars['searchadvdaysnew'] === '' ? -1 : $searchVars['searchadvdaysnew']),
                ($searchVars['searchadvdaysold'] === '' ? -1 : $searchVars['searchadvdaysold']),
                $offset,
                $perPage,
                $orderBy,
                -1,
                $this->userdata->categoryexclusions ?? [],
                'advanced',
                $this->resolveCategoryIdsFromRequest($request)
            );

            $results = $this->paginate($rslt ?? [], $rslt[0]->_totalrows ?? 0, $perPage, $page, $request->url(), $request->query());
        }

        $suggestEnabled = $this->searchService->isSuggestEnabled();
        $spellSuggestion = $this->resolveSpellSuggestion($search ?: $searchVars['searchadvr'], $results, $suggestEnabled);

        $this->viewData = array_merge($this->viewData, $searchVars, $orderByUrls, [
            'subject' => $subject,
            'search' => $search,
            'id' => $id,
            'category' => $category,
            'covgroup' => '',
            'lastvisit' => $lastvisit,
            'results' => $results,
            'sadvanced' => $searchType !== 'basic',
            'catlist' => $this->getCategorySelectOptions(),
            'meta_title' => 'Search Nzbs',
            'meta_keywords' => 'search,nzb,description,details',
            'meta_description' => 'Search for Nzbs',
            // Search enhanced features
            'spellSuggestion' => $spellSuggestion,
            'autocompleteEnabled' => $this->searchService->isAutocompleteEnabled(),
            'suggestEnabled' => $suggestEnabled,
        ]);

        return view('search.index', $this->viewData);
    }

    /**
     * @return array<int>
     */
    private function resolveCategoryIdsFromRequest(Request $request): array
    {
        $raw = $this->scalarInput($request, 't', $this->scalarInput($request, 'searchadvcat'));

        if ($raw === '' || $raw === '-1') {
            return [-1];
        }

        $categoryIds = [];
        foreach (explode(',', $raw) as $categoryId) {
            $categoryId = trim($categoryId);
            if (preg_match('/^-?\d+$/', $categoryId) === 1) {
                $categoryIds[] = (int) $categoryId;
            }
        }

        return $categoryIds === [] ? [-1] : array_values(array_unique($categoryIds));
    }

    /**
     * @param  array<int, mixed>|LengthAwarePaginator<int, mixed>  $results
     */
    private function resolveSpellSuggestion(string $searchQuery, array|LengthAwarePaginator $results, bool $suggestEnabled): ?string
    {
        if ($searchQuery === '' || ! $suggestEnabled || $this->resultCount($results) > 3) {
            return null;
        }

        $suggestions = $this->searchService->suggest($searchQuery);
        if ($suggestions === []) {
            return null;
        }

        usort($suggestions, static fn (array $a, array $b): int => (int) $b['docs'] <=> (int) $a['docs']);

        return $suggestions[0]['suggest'] !== $searchQuery ? $suggestions[0]['suggest'] : null;
    }

    /**
     * @param  array<int, mixed>|LengthAwarePaginator<int, mixed>  $results
     */
    private function resultCount(array|LengthAwarePaginator $results): int
    {
        if ($results instanceof LengthAwarePaginator) {
            return $results->total();
        }

        return count($results);
    }

    /**
     * @return array<int, string>
     */
    private function getCategorySelectOptions(): array
    {
        try {
            return Cache::remember('search:category-select-options', now()->addMinutes(10), fn (): array => Category::getForSelect());
        } catch (\Throwable) {
            return Category::getForSelect();
        }
    }
}
