<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\UserSerie;
use App\Models\Video;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Throwable;

class MyShowsController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_watchlist_post_gate(const char *action, size_t action_len, uint8_t is_post);
    int32_t nntmux_watchlist_selected_categories(
        uint32_t mode,
        const char *field,
        size_t field_len,
        size_t *out_lens,
        size_t lens_cap
    );
    CDEF;

    private const int SELECTED_NONE = 0;

    private const int SELECTED_EDIT = 1;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustSearchContentWatchlistControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private ReleaseBrowseService $releaseBrowseService;

    public function __construct(ReleaseBrowseService $releaseBrowseService)
    {
        parent::__construct();
        $this->releaseBrowseService = $releaseBrowseService;
    }

    public function show(Request $request): mixed
    {
        $action = $this->scalarInput($request, 'action');
        abort_if(self::postGateBlocked($action, $request->isMethod('post')), 405);

        $videoId = $this->scalarInput($request, 'id');
        $from = $this->localReturnUrl($request, '/myshows');

        $this->viewData['from'] = $from;

        switch ($action) {
            case 'delete':
                $show = UserSerie::getShow($this->userdata->id, $videoId);
                if (! $show) {
                    return redirect()->back();
                }

                UserSerie::delShow($this->userdata->id, $videoId);

                return redirect()->to($from);
            case 'add':
            case 'doadd':
                $show = UserSerie::getShow($this->userdata->id, $videoId);
                if ($show) {
                    return redirect()->to('myshows');
                }

                $show = Video::getByVideoID($videoId);
                if (! $show) {
                    return redirect()->to('myshows');
                }

                if ($action === 'doadd') {
                    $category = $this->selectedCategoryIds($request, $this->showCategories(true));
                    UserSerie::addShow($this->userdata->id, $videoId, $category);

                    return redirect()->to($from);
                }

                $this->viewData = array_merge($this->viewData, self::watchlistViewState('add', $this->showCategories(true), ''));
                $this->viewData['video'] = $videoId;
                $this->viewData['show'] = $show;
                $this->viewData['userdata'] = $this->userdata;
                $this->viewData['content'] = view('myshows.add', $this->viewData)->render();

                return $this->pagerender();

            case 'edit':
            case 'doedit':
                $show = UserSerie::getShow($this->userdata->id, $videoId);

                if (! $show) {
                    return redirect()->to('myshows');
                }

                if ($action === 'doedit') {
                    $category = $this->selectedCategoryIds($request, $this->showCategories(false));
                    UserSerie::updateShow($this->userdata->id, $videoId, $category);

                    return redirect()->to($from);
                }

                $this->viewData = array_merge($this->viewData, self::watchlistViewState('edit', $this->showCategories(false), $show['categories']));
                $this->viewData['video'] = $videoId;
                $this->viewData['show'] = $show;
                $this->viewData['userdata'] = $this->userdata;
                $this->viewData['content'] = view('myshows.add', $this->viewData)->render();

                return $this->pagerender();

            default:

                $title = 'My Shows';
                $meta_title = 'My Shows';
                $meta_keywords = 'search,add,to,cart,nzb,description,details';
                $meta_description = 'Manage Your Shows';

                $categories = $this->showCategories(false);

                $shows = UserSerie::getShows($this->userdata->id);
                $results = [];
                if ($shows !== null) {
                    foreach ($shows as $showk => $show) {
                        $catArr = [];
                        $showcats = explode('|', $show['categories'] ?? '');
                        if (! empty($showcats)) {
                            foreach ($showcats as $scat) {
                                if (! empty($scat) && isset($categories[$scat])) {
                                    $catArr[] = $categories[$scat];
                                }
                            }
                            $show['categoryNames'] = implode(', ', $catArr);
                        } else {
                            $show['categoryNames'] = '';
                        }

                        $results[$showk] = $show;
                    }
                }
                $this->viewData['shows'] = $results;
                $this->viewData['userdata'] = $this->userdata;
                $this->viewData['content'] = view('myshows.index', $this->viewData)->render();
                $this->viewData = array_merge($this->viewData, compact('title', 'meta_title', 'meta_keywords', 'meta_description'));

                return $this->pagerender();
        }

    }

    /**
     * @throws \Exception
     */
    public function browse(Request $request): mixed
    {
        $title = 'Browse My Shows';
        $meta_title = 'My Shows';
        $meta_keywords = 'search,add,to,cart,nzb,description,details';
        $meta_description = 'Browse Your Shows';

        $shows = UserSerie::getShows($this->userdata->id);

        $page = $this->resolvePage($request);
        $perPage = (int) config('nntmux.items_per_page');
        $offset = $this->paginationOffset($page, $perPage);
        $ordering = $this->releaseBrowseService->getBrowseOrdering();
        $orderby = $this->resolveOrderBy($request, $ordering);
        $browseCount = $shows ? $shows->count() : 0;

        $rslt = $this->releaseBrowseService->getShowsRange($shows ?? [], $offset, $perPage, $orderby, -1, (array) $this->userdata->categoryexclusions);
        $results = $this->paginate($rslt ?? [], $browseCount, $perPage, $page, $request->url(), $request->query());

        $this->viewData['covgroup'] = '';

        foreach ($ordering as $ordertype) {
            $this->viewData['orderby'.$ordertype] = url('/myshows/browse?ob='.$ordertype.'&amp;offset=0');
        }

        $this->viewData['lastvisit'] = $this->userdata->lastlogin;
        $this->viewData['results'] = $results;
        $this->viewData['resultsadd'] = $rslt;
        $this->viewData['shows'] = true;
        $this->viewData['content'] = view('browse.index', $this->viewData)->render();
        $this->viewData = array_merge($this->viewData, compact('title', 'meta_title', 'meta_keywords', 'meta_description'));

        return $this->pagerender();
    }

    /**
     * @return array<int, string>
     */
    private function showCategories(bool $excludeDisabledWebdl): array
    {
        $categories = [];
        foreach (Category::getChildren(Category::TV_ROOT) as $category) {
            if ($excludeDisabledWebdl && (int) $category['id'] === Category::TV_WEBDL && (int) app(ConfigurationProvider::class)->ingestion()->categorizeWebDl === 0) {
                continue;
            }

            $categories[(int) $category['id']] = (string) $category['title'];
        }

        return $categories;
    }

    /**
     * @param  array<int, string>  $allowedCategories
     * @return array<int, int>
     */
    private function selectedCategoryIds(Request $request, array $allowedCategories): array
    {
        $selected = [];
        foreach ($this->arrayInput($request, 'category') as $categoryId) {
            if (is_scalar($categoryId) && preg_match('/^\d+$/', (string) $categoryId) === 1 && isset($allowedCategories[(int) $categoryId])) {
                $selected[] = (int) $categoryId;
            }
        }

        return array_values(array_unique($selected));
    }

    /**
     * Overrides the base page toggle for this controller's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * True when a state-changing action (`delete`, `doadd`, `doedit`) arrives without POST.
     */
    public static function postGateBlocked(string $action, bool $isPost): bool
    {
        return self::$useRustFfi ? self::postGateBlockedNative($action, $isPost) : self::postGateBlockedPhp($action, $isPost);
    }

    public static function postGateBlockedPhp(string $action, bool $isPost): bool
    {
        return in_array($action, ['delete', 'doadd', 'doedit'], true) && ! $isPost;
    }

    public static function postGateBlockedNative(string $action, bool $isPost): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::postGateBlockedPhp($action, $isPost);
        }

        try {
            $result = self::callFfi($ffi, 'nntmux_watchlist_post_gate', $action, \strlen($action), $isPost ? 1 : 0);

            return match ($result) {
                1 => true,
                0 => false,
                default => self::postGateBlockedPhp($action, $isPost),
            };
        } catch (Throwable) {
            return self::postGateBlockedPhp($action, $isPost);
        }
    }

    /**
     * Add/edit form state: category ids and names, plus the `|`-separated selected
     * categories for `edit` (always split, like `explode()`).
     *
     * @param  array<int, string>  $categories
     * @return array{type: string, cat_ids: list<int>, cat_names: array<int, string>, cat_selected: list<string>}
     */
    public static function watchlistViewState(string $type, array $categories, string $selectedCategories): array
    {
        return self::$useRustFfi
            ? self::watchlistViewStateNative($type, $categories, $selectedCategories)
            : self::watchlistViewStatePhp($type, $categories, $selectedCategories);
    }

    /**
     * @param  array<int, string>  $categories
     * @return array{type: string, cat_ids: list<int>, cat_names: array<int, string>, cat_selected: list<string>}
     */
    public static function watchlistViewStatePhp(string $type, array $categories, string $selectedCategories): array
    {
        return [
            'type' => $type,
            'cat_ids' => array_keys($categories),
            'cat_names' => $categories,
            'cat_selected' => $type === 'edit' ? explode('|', $selectedCategories) : [],
        ];
    }

    /**
     * @param  array<int, string>  $categories
     * @return array{type: string, cat_ids: list<int>, cat_names: array<int, string>, cat_selected: list<string>}
     */
    public static function watchlistViewStateNative(string $type, array $categories, string $selectedCategories): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::watchlistViewStatePhp($type, $categories, $selectedCategories);
        }

        try {
            $length = \strlen($selectedCategories);
            // One segment per separator plus one.
            $segments = substr_count($selectedCategories, '|') + 1;
            $lens = $ffi->new('size_t['.$segments.']');
            $count = self::callFfi(
                $ffi,
                'nntmux_watchlist_selected_categories',
                $type === 'edit' ? self::SELECTED_EDIT : self::SELECTED_NONE,
                $selectedCategories,
                $length,
                $lens,
                $segments,
            );
            if (! \is_int($count) || $count < 0 || $count > $segments) {
                return self::watchlistViewStatePhp($type, $categories, $selectedCategories);
            }

            $selected = [];
            $offset = 0;
            for ($i = 0; $i < $count; $i++) {
                $len = (int) $lens[$i];
                $selected[] = (string) substr($selectedCategories, $offset, $len);
                $offset += $len + 1;
            }
            if ($count > 0 && $offset !== $length + 1) {
                return self::watchlistViewStatePhp($type, $categories, $selectedCategories);
            }

            return [
                'type' => $type,
                'cat_ids' => array_keys($categories),
                'cat_names' => $categories,
                'cat_selected' => $selected,
            ];
        } catch (Throwable) {
            return self::watchlistViewStatePhp($type, $categories, $selectedCategories);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
