<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchReleaseListRequest;
use App\Models\Category;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseSearchService;
use App\Services\Search\Contracts\SearchServiceInterface;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SearchController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_search_poster_reconcile(
        uint8_t has_poster,
        uint8_t has_advanced_poster,
        uint8_t list_has_advanced_key,
        const char *exact,
        size_t exact_len,
        const char *advanced,
        size_t advanced_len
    );
    int32_t nntmux_search_field_map(
        const char *search,
        size_t search_len,
        const char *subject,
        size_t subject_len,
        uint8_t exact_poster_empty,
        const char *advanced,
        size_t advanced_len,
        const char *filename,
        size_t filename_len,
        size_t *out_lens,
        char *out,
        size_t cap
    );
    CDEF;

    private const int POSTER_SOURCE_MASK = 0b11;

    private const int POSTER_FROM_EXACT = 0;

    private const int POSTER_FROM_ADVANCED = 1;

    private const int POSTER_EMPTY = 2;

    private const int POSTER_KEY_MASK = 0b1100;

    private const int POSTER_KEY_NONE = 0;

    private const int POSTER_KEY_UNSET = 1 << 2;

    private const int POSTER_KEY_SET = 2 << 2;

    private const int ADVANCED_KEY_SET = 1 << 4;

    private const int STATE_MASK = 0b11111;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustSearchContentWatchlistControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
    public function search(SearchReleaseListRequest $request): mixed
    {
        $listQuery = $request->listQuery();
        $search = (string) $listQuery['search'];
        $category = $request->categoryIds();
        $orderBy = (string) $listQuery['ob'];
        $page = $request->integer('page', 1);
        $perPage = max(1, (int) config('nntmux.items_per_page'));
        $posterReleaseId = $request->integer('poster_release_id') ?: null;
        $advancedPoster = $this->scalarInput($request, 'searchadvposter');
        [$exactPoster, $listQuery] = self::reconcilePosterQuery(
            $request->exists('poster'),
            $request->exists('searchadvposter'),
            $this->scalarInput($request, 'poster'),
            $advancedPoster,
            $listQuery,
        );
        $group = $this->scalarInput($request, 'group');
        $ranges = $request->ranges();
        $searchFields = self::searchFieldMap(
            $search,
            $this->scalarInput($request, 'searchadvsubject'),
            $exactPoster,
            $advancedPoster,
            $this->scalarInput($request, 'searchadvfilename'),
        );
        $hasText = $posterReleaseId === null && $exactPoster === '' && $request->anyFilled(['search', 'searchadvsubject', 'searchadvposter', 'searchadvfilename']);
        $hasFilters = $posterReleaseId !== null || $exactPoster !== '' || $ranges !== [] || $group !== '' || $category !== [-1] || $request->anyFilled(['minage', 'maxage']);
        $results = [];
        if ($hasText || $hasFilters) {
            $matches = $this->releaseSearchService->search(
                $searchFields,
                $group === '' ? -1 : $group,
                -1,
                -1,
                $request->filled('minage') ? $request->integer('minage') : -1,
                $request->filled('maxage') ? $request->integer('maxage') : -1,
                $this->paginationOffset($page, $perPage),
                $perPage,
                $orderBy,
                -1,
                $this->userdata->categoryexclusions ?? [],
                (string) $listQuery['search_type'],
                $category,
                0,
                ['ranges' => $ranges, 'filter_only' => ! $hasText && $hasFilters, 'poster_release_id' => $posterReleaseId, 'poster' => $exactPoster],
            );
            $results = $this->paginate($matches ?? [], $matches[0]->_totalrows ?? 0, $perPage, $page, $request->url(), $listQuery);
        }
        $orderByUrls = [];
        foreach ($this->releaseBrowseService->getBrowseOrdering() as $order) {
            $orderByUrls['orderby'.$order] = $request->url().'?'.http_build_query(array_replace($listQuery, ['ob' => $order]));
        }
        $suggestEnabled = $this->searchService->isSuggestEnabled();
        $this->viewData = array_merge($this->viewData, $orderByUrls, [
            'subject' => $this->scalarInput($request, 'subject'),
            'search' => $search,
            'id' => $this->scalarInput($request, 'id'),
            'category' => $category,
            'covgroup' => '',
            'lastvisit' => $this->userdata->lastlogin,
            'results' => $results,
            'searchSubmitted' => $hasText || $hasFilters,
            'posterReleaseId' => $posterReleaseId,
            'exactPoster' => $exactPoster,
            'sadvanced' => $listQuery['search_type'] === 'adv',
            'listQuery' => $listQuery,
            'catlist' => $this->getCategorySelectOptions(),
            'meta_title' => 'Search Nzbs',
            'meta_keywords' => 'search,nzb,description,details',
            'meta_description' => 'Search for Nzbs',
            'spellSuggestion' => $this->resolveSpellSuggestion($search, $results, $suggestEnabled),
            'autocompleteEnabled' => $this->searchService->isAutocompleteEnabled(),
            'suggestEnabled' => $suggestEnabled,
        ]);

        return view('search.index', $this->viewData);
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
        } catch (Throwable) {
            return Category::getForSelect();
        }
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
     * Resolve the exact-poster filter from the `poster`/`searchadvposter` inputs: a changed
     * advanced poster replaces (or, when empty, removes) the `poster` list-query key, and a
     * resolved poster seeds a missing `searchadvposter` key.
     *
     * @param  array<string, mixed>  $listQuery
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function reconcilePosterQuery(bool $hasPoster, bool $hasAdvancedPoster, string $exactPoster, string $advancedPoster, array $listQuery): array
    {
        return self::$useRustFfi
            ? self::reconcilePosterQueryNative($hasPoster, $hasAdvancedPoster, $exactPoster, $advancedPoster, $listQuery)
            : self::reconcilePosterQueryPhp($hasPoster, $hasAdvancedPoster, $exactPoster, $advancedPoster, $listQuery);
    }

    /**
     * @param  array<string, mixed>  $listQuery
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function reconcilePosterQueryPhp(bool $hasPoster, bool $hasAdvancedPoster, string $exactPoster, string $advancedPoster, array $listQuery): array
    {
        if ($hasPoster && $hasAdvancedPoster && $advancedPoster !== $exactPoster) {
            $exactPoster = $advancedPoster;
            if ($exactPoster === '') {
                unset($listQuery['poster']);
            } else {
                $listQuery['poster'] = $exactPoster;
            }
        } elseif (! $hasPoster) {
            $exactPoster = '';
        }
        if ($exactPoster !== '' && ! array_key_exists('searchadvposter', $listQuery)) {
            $listQuery['searchadvposter'] = $exactPoster;
        }

        return [$exactPoster, $listQuery];
    }

    /**
     * @param  array<string, mixed>  $listQuery
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function reconcilePosterQueryNative(bool $hasPoster, bool $hasAdvancedPoster, string $exactPoster, string $advancedPoster, array $listQuery): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::reconcilePosterQueryPhp($hasPoster, $hasAdvancedPoster, $exactPoster, $advancedPoster, $listQuery);
        }

        try {
            $state = self::callFfi(
                $ffi,
                'nntmux_search_poster_reconcile',
                $hasPoster ? 1 : 0,
                $hasAdvancedPoster ? 1 : 0,
                array_key_exists('searchadvposter', $listQuery) ? 1 : 0,
                $exactPoster,
                \strlen($exactPoster),
                $advancedPoster,
                \strlen($advancedPoster),
            );
            if (! \is_int($state) || $state < 0 || ($state & ~self::STATE_MASK) !== 0) {
                return self::reconcilePosterQueryPhp($hasPoster, $hasAdvancedPoster, $exactPoster, $advancedPoster, $listQuery);
            }

            $resolved = match ($state & self::POSTER_SOURCE_MASK) {
                self::POSTER_FROM_EXACT => $exactPoster,
                self::POSTER_FROM_ADVANCED => $advancedPoster,
                self::POSTER_EMPTY => '',
                default => null,
            };
            if ($resolved === null) {
                return self::reconcilePosterQueryPhp($hasPoster, $hasAdvancedPoster, $exactPoster, $advancedPoster, $listQuery);
            }
            switch ($state & self::POSTER_KEY_MASK) {
                case self::POSTER_KEY_NONE:
                    break;
                case self::POSTER_KEY_UNSET:
                    unset($listQuery['poster']);
                    break;
                case self::POSTER_KEY_SET:
                    $listQuery['poster'] = $resolved;
                    break;
                default:
                    return self::reconcilePosterQueryPhp($hasPoster, $hasAdvancedPoster, $exactPoster, $advancedPoster, $listQuery);
            }
            if (($state & self::ADVANCED_KEY_SET) !== 0) {
                $listQuery['searchadvposter'] = $resolved;
            }

            return [$resolved, $listQuery];
        } catch (Throwable) {
            return self::reconcilePosterQueryPhp($hasPoster, $hasAdvancedPoster, $exactPoster, $advancedPoster, $listQuery);
        }
    }

    /**
     * Release search field map; the advanced poster is a `fromname` text match only
     * when no exact poster filter applies.
     *
     * @return array{searchname: string, name: string, fromname: string, filename: string}
     */
    public static function searchFieldMap(string $search, string $subject, string $exactPoster, string $advancedPoster, string $filename): array
    {
        return self::$useRustFfi
            ? self::searchFieldMapNative($search, $subject, $exactPoster, $advancedPoster, $filename)
            : self::searchFieldMapPhp($search, $subject, $exactPoster, $advancedPoster, $filename);
    }

    /**
     * @return array{searchname: string, name: string, fromname: string, filename: string}
     */
    public static function searchFieldMapPhp(string $search, string $subject, string $exactPoster, string $advancedPoster, string $filename): array
    {
        return [
            'searchname' => $search,
            'name' => $subject,
            'fromname' => $exactPoster === '' ? $advancedPoster : '',
            'filename' => $filename,
        ];
    }

    /**
     * @return array{searchname: string, name: string, fromname: string, filename: string}
     */
    public static function searchFieldMapNative(string $search, string $subject, string $exactPoster, string $advancedPoster, string $filename): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::searchFieldMapPhp($search, $subject, $exactPoster, $advancedPoster, $filename);
        }

        try {
            $lens = $ffi->new('size_t[4]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, \strlen($search) + \strlen($subject) + \strlen($advancedPoster) + \strlen($filename)), $capacity);
            if ($outBuf === null) {
                return self::searchFieldMapPhp($search, $subject, $exactPoster, $advancedPoster, $filename);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_search_field_map',
                $search,
                \strlen($search),
                $subject,
                \strlen($subject),
                $exactPoster === '' ? 1 : 0,
                $advancedPoster,
                \strlen($advancedPoster),
                $filename,
                \strlen($filename),
                $lens,
                $outBuf,
                $capacity,
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::searchFieldMapPhp($search, $subject, $exactPoster, $advancedPoster, $filename);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $fields = [];
            $offset = 0;
            foreach (['searchname', 'name', 'fromname', 'filename'] as $index => $key) {
                $len = (int) $lens[$index];
                $fields[$key] = (string) substr($payload, $offset, $len);
                $offset += $len;
            }
            if ($offset !== $written) {
                return self::searchFieldMapPhp($search, $subject, $exactPoster, $advancedPoster, $filename);
            }

            return $fields;
        } catch (Throwable) {
            return self::searchFieldMapPhp($search, $subject, $exactPoster, $advancedPoster, $filename);
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
