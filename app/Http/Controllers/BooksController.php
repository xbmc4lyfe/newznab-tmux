<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\BookService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class BooksController extends BasePageController
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
    ssize_t nntmux_media_browse_truncate_words(
        const char *text_ptr,
        size_t text_len,
        size_t max_words,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    private const ORDER_BY_KIND = 1;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMediaBrowseControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected BookService $bookService;

    public function __construct(BookService $bookService)
    {
        parent::__construct();
        $this->bookService = $bookService;
    }

    /**
     * @throws \Exception
     */
    public function index(Request $request, string $id = ''): mixed
    {
        $boocats = Category::getChildren(Category::BOOKS_ROOT);

        $btmp = [];
        foreach ($boocats as $bcat) {
            $btmp[] =
                [
                    'id' => $bcat->id,
                    'title' => $bcat->title,
                ];
        }
        $category = $request->has('t') ? $this->scalarInput($request, 't', (string) Category::BOOKS_ROOT) : Category::BOOKS_ROOT;
        if ($id && \in_array($id, Arr::pluck($btmp, 'title'), false)) {
            $cat = Category::query()
                ->where('title', $id)
                ->where('root_categories_id', '=', Category::BOOKS_ROOT)
                ->first(['id']);
            $category = $cat !== null ? (int) $cat['id'] : Category::BOOKS_ROOT;
        }

        $catarray = [];
        $catarray[] = (int) $category;

        $ordering = $this->bookService->getBookOrdering();
        $orderby = $this->resolveOrderBy($request, $ordering);

        $books = [];
        $page = $this->resolvePage($request);
        $perPage = (int) config('nntmux.items_per_cover_page');
        $offset = $this->paginationOffset($page, $perPage);
        $rslt = $this->bookService->getBookRange($page, $catarray, $offset, $perPage, $orderby, (array) $this->userdata->categoryexclusions);
        $results = $this->paginate($rslt, $rslt[0]->_totalcount ?? 0, $perPage, $page, $request->url(), $request->query());
        $maxwords = 50;
        foreach ($results as $result) {
            if (! empty($result->overview) && \is_string($result->overview)) {
                $result->overview = self::excerptWords($result->overview, $maxwords);
            }
            $books[] = $result;
        }

        $authorInput = $this->scalarInput($request, 'author');
        $author = $authorInput !== '' ? stripslashes($authorInput) : '';

        $titleInput = $this->scalarInput($request, 'title');
        $title = $titleInput !== '' ? stripslashes($titleInput) : '';

        $browseby_link = '&title='.$title.'&author='.$author;

        $cdata = (int) $category === -1 ? null : Category::find($category);
        $catname = self::categoryDisplayName($category, $cdata?->title);

        // Build order by URLs
        $orderByUrls = array_map(
            static fn (string $path): string => url($path),
            self::orderByPaths($ordering, $id, $category, $browseby_link)
        );

        $this->viewData = array_merge($this->viewData, [
            'catlist' => $btmp,
            'category' => $category,
            'categorytitle' => $id,
            'catname' => $catname,
            'author' => $author,
            'title' => $title,
            'resultsadd' => $books,
            'results' => $results,
            'covgroup' => 'books',
            'meta_title' => 'Browse Books',
            'meta_keywords' => 'browse,nzb,books,description,details',
            'meta_description' => 'Browse for Books',
        ], $orderByUrls);

        return view('books.index', $this->viewData);
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
    public static function orderByPaths(array $ordering, string $id, int|string $category, string $browseByLink): array
    {
        return self::$useRustFfi
            ? self::orderByPathsNative($ordering, $id, $category, $browseByLink)
            : self::orderByPathsPhp($ordering, $id, $category, $browseByLink);
    }

    /**
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPathsPhp(array $ordering, string $id, int|string $category, string $browseByLink): array
    {
        $paths = [];
        foreach ($ordering as $ordertype) {
            $paths['orderby'.$ordertype] = '/Books/'.($id ?: 'All').'?t='.$category.$browseByLink.'&ob='.$ordertype.'&offset=0';
        }

        return $paths;
    }

    /**
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPathsNative(array $ordering, string $id, int|string $category, string $browseByLink): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::orderByPathsPhp($ordering, $id, $category, $browseByLink);
        }

        try {
            $types = [];
            foreach ($ordering as $ordertype) {
                $type = (string) $ordertype;
                if (str_contains($type, "\0")) {
                    return self::orderByPathsPhp($ordering, $id, $category, $browseByLink);
                }
                $types[] = $type;
            }

            $count = \count($types);
            $packed = implode("\0", $types);
            $idText = $id;
            $categoryText = (string) $category;
            $extraText = $browseByLink;
            $estimate = $count * (32 + max(3, \strlen($idText)) + \strlen($categoryText) + \strlen($extraText)) + \strlen($packed);
            $lens = $ffi->new('size_t['.max(1, $count).']');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return self::orderByPathsPhp($ordering, $id, $category, $browseByLink);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_media_browse_order_by_paths',
                self::ORDER_BY_KIND,
                $idText,
                \strlen($idText),
                $categoryText,
                \strlen($categoryText),
                $extraText,
                \strlen($extraText),
                $packed,
                \strlen($packed),
                $count,
                $lens,
                $outBuf,
                $capacity
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::orderByPathsPhp($ordering, $id, $category, $browseByLink);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $paths = [];
            $offset = 0;
            foreach ($types as $index => $type) {
                $len = (int) $lens[$index];
                $paths['orderby'.$type] = (string) substr($payload, $offset, $len);
                $offset += $len;
            }

            return $offset === $written ? $paths : self::orderByPathsPhp($ordering, $id, $category, $browseByLink);
        } catch (Throwable) {
            return self::orderByPathsPhp($ordering, $id, $category, $browseByLink);
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

    /**
     * Keeps the first `$maxWords` space-separated words followed by `...` when longer.
     */
    public static function excerptWords(string $text, int $maxWords): string
    {
        return self::$useRustFfi
            ? self::excerptWordsNative($text, $maxWords)
            : self::excerptWordsPhp($text, $maxWords);
    }

    public static function excerptWordsPhp(string $text, int $maxWords): string
    {
        $words = explode(' ', $text);
        if (\count($words) > $maxWords) {
            $newwords = \array_slice($words, 0, $maxWords);

            return implode(' ', $newwords).'...';
        }

        return $text;
    }

    public static function excerptWordsNative(string $text, int $maxWords): string
    {
        $ffi = $maxWords < 0 ? null : self::ffi();
        if ($ffi === null) {
            return self::excerptWordsPhp($text, $maxWords);
        }

        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, \strlen($text) + 3, $capacity);
            if ($outBuf === null) {
                return self::excerptWordsPhp($text, $maxWords);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_media_browse_truncate_words',
                $text,
                \strlen($text),
                $maxWords,
                $outBuf,
                $capacity
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::excerptWordsPhp($text, $maxWords);
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::excerptWordsPhp($text, $maxWords);
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
