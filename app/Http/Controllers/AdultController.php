<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class AdultController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_media_browse_order_by_paths(
        uint8_t kind,
        const char *id_ptr,
        size_t id_len,
        const char *category_ptr,
        size_t category_len,
        const char *extra_ptr,
        size_t extra_len,
        const char *orderings_ptr,
        size_t orderings_len,
        size_t ordering_count,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_media_browse_category_display_name(
        uint8_t is_all,
        uint8_t has_title,
        const char *title_ptr,
        size_t title_len,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    private const ORDER_BY_KIND = 0;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMediaBrowseControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private ReleaseBrowseService $releaseBrowseService;

    public function __construct(ReleaseBrowseService $releaseBrowseService)
    {
        parent::__construct();
        $this->releaseBrowseService = $releaseBrowseService;
    }

    /**
     * @throws \Exception
     */
    public function show(Request $request, string $id = ''): mixed
    {
        $moviecats = Category::getChildren(Category::XXX_ROOT);
        $mtmp = [];
        foreach ($moviecats as $mcat) {
            $mtmp[] = [
                'id' => $mcat->id,
                'title' => $mcat->title,
            ];
        }
        $category = $this->integerInput($request, 't', Category::XXX_ROOT);
        if ($id && \in_array($id, Arr::pluck($mtmp, 'title'), true)) {
            $cat = Category::query()
                ->where('title', $id)
                ->where('root_categories_id', '=', Category::XXX_ROOT)
                ->first(['id']);
            $category = $cat !== null ? (int) $cat['id'] : Category::XXX_ROOT;
        }
        $catarray = [];
        $catarray[] = $category;

        $ordering = $this->releaseBrowseService->getBrowseOrdering();
        $orderby = $this->resolveOrderBy($request, $ordering);

        $page = $this->resolvePage($request);
        $perPage = (int) config('nntmux.items_per_page');
        $offset = $this->paginationOffset($page, $perPage);
        $rslt = $this->releaseBrowseService->getBrowseRange($page, $catarray, $offset, $perPage, $orderby, -1, (array) ($this->userdata->categoryexclusions ?? []), -1);
        $results = $this->paginate($rslt ?? [], isset($rslt[0]->_totalcount) ? $rslt[0]->_totalcount : 0, $perPage, $page, $request->url(), $request->query());

        $cdata = $category === -1 ? null : Category::find($category);
        $catname = self::categoryDisplayName($category, $cdata?->title);

        $orderByUrls = array_map(
            static fn (string $path): string => url($path),
            self::orderByPaths($ordering, $id, $category)
        );

        $this->viewData = array_merge($this->viewData, [
            'catlist' => $mtmp,
            'category' => $category,
            'categorytitle' => $id,
            'catname' => $catname,
            'ordering' => $ordering,
            'orderByUrls' => $orderByUrls,
            'results' => $results,
            'lastvisit' => $this->userdata['lastlogin'] ?? null,
            'meta_title' => 'Browse XXX',
            'meta_keywords' => 'browse,xxx,nzb,description,details',
            'meta_description' => 'Browse for XXX Movies',
        ], $orderByUrls);

        return view('xxx.index', $this->viewData);
    }

    /**
     * Overrides the base page toggle for this controller's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
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
     * Order-by paths keyed `orderby<type>`, before `url()` is applied.
     *
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPaths(array $ordering, string $id, int|string $category): array
    {
        return self::$useRustFfi
            ? self::orderByPathsNative($ordering, $id, $category)
            : self::orderByPathsPhp($ordering, $id, $category);
    }

    /**
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPathsPhp(array $ordering, string $id, int|string $category): array
    {
        $paths = [];
        foreach ($ordering as $ordertype) {
            $paths['orderby'.$ordertype] = '/XXX/'.($id ?: 'All').'?t='.$category.'&ob='.$ordertype;
        }

        return $paths;
    }

    /**
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPathsNative(array $ordering, string $id, int|string $category): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::orderByPathsPhp($ordering, $id, $category);
        }

        try {
            $types = [];
            foreach ($ordering as $ordertype) {
                $type = (string) $ordertype;
                if (str_contains($type, "\0")) {
                    return self::orderByPathsPhp($ordering, $id, $category);
                }
                $types[] = $type;
            }

            $count = \count($types);
            $packed = implode("\0", $types);
            $categoryText = (string) $category;
            $estimate = $count * (32 + max(3, \strlen($id)) + \strlen($categoryText)) + \strlen($packed);
            $lens = $ffi->new('size_t['.max(1, $count).']');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return self::orderByPathsPhp($ordering, $id, $category);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_media_browse_order_by_paths',
                self::ORDER_BY_KIND,
                $id,
                \strlen($id),
                $categoryText,
                \strlen($categoryText),
                '',
                0,
                $packed,
                \strlen($packed),
                $count,
                $lens,
                $outBuf,
                $capacity
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::orderByPathsPhp($ordering, $id, $category);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $paths = [];
            $offset = 0;
            foreach ($types as $index => $type) {
                $len = (int) $lens[$index];
                $paths['orderby'.$type] = (string) substr($payload, $offset, $len);
                $offset += $len;
            }

            return $offset === $written ? $paths : self::orderByPathsPhp($ordering, $id, $category);
        } catch (Throwable) {
            return self::orderByPathsPhp($ordering, $id, $category);
        }
    }

    /**
     * `All` for the `-1` category or a missing category, otherwise its title.
     */
    public static function categoryDisplayName(int|string $category, ?string $title): string
    {
        return self::$useRustFfi
            ? self::categoryDisplayNameNative($category, $title)
            : self::categoryDisplayNamePhp($category, $title);
    }

    public static function categoryDisplayNamePhp(int|string $category, ?string $title): string
    {
        if ((int) $category === -1 || $title === null) {
            return 'All';
        }

        return $title;
    }

    public static function categoryDisplayNameNative(int|string $category, ?string $title): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::categoryDisplayNamePhp($category, $title);
        }

        try {
            $titleText = $title ?? '';
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(3, \strlen($titleText)), $capacity);
            if ($outBuf === null) {
                return self::categoryDisplayNamePhp($category, $title);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_media_browse_category_display_name',
                (int) $category === -1 ? 1 : 0,
                $title === null ? 0 : 1,
                $titleText,
                \strlen($titleText),
                $outBuf,
                $capacity
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::categoryDisplayNamePhp($category, $title);
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::categoryDisplayNamePhp($category, $title);
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

        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
