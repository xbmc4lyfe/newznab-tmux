<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Genre;
use App\Services\ConsoleService;
use App\Services\GenreService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class ConsoleController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
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

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMediaBrowseControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected ConsoleService $consoleService;

    public function __construct(ConsoleService $consoleService)
    {
        parent::__construct();
        $this->consoleService = $consoleService;
    }

    /**
     * @throws \Exception
     */
    public function show(Request $request, string $id = ''): mixed
    {
        if ($id === 'WiiVare') {
            $id = 'WiiVareVC';
        }
        $gen = new GenreService;

        $concats = Category::getChildren(Category::GAME_ROOT);
        $ctmp = [];
        foreach ($concats as $ccat) {
            $ctmp[] =
                [
                    'id' => $ccat->id,
                    'title' => $ccat->title,
                ];
        }
        $category = $request->has('t') ? $this->scalarInput($request, 't', (string) Category::GAME_ROOT) : Category::GAME_ROOT;
        if ($id && \in_array($id, Arr::pluck($ctmp, 'title'), false)) {
            $cat = Category::query()
                ->where('title', $id)
                ->where('root_categories_id', '=', Category::GAME_ROOT)
                ->first(['id']);
            $category = $cat !== null ? (int) $cat['id'] : Category::GAME_ROOT;
        }

        $catarray = [];
        $catarray[] = (int) $category;

        $ordering = $this->consoleService->getConsoleOrdering();
        $orderby = $this->resolveOrderBy($request, $ordering);
        $page = $this->resolvePage($request);
        $perPage = (int) config('nntmux.items_per_cover_page');
        $offset = $this->paginationOffset($page, $perPage);

        $consoles = [];
        $rslt = $this->consoleService->getConsoleRange($page, $catarray, $offset, $perPage, $orderby, (array) $this->userdata->categoryexclusions);
        $results = $this->paginate($rslt, $rslt[0]->_totalcount ?? 0, $perPage, $page, $request->url(), $request->query());

        $maxwords = 50;
        foreach ($results as $result) {
            if (! empty($result->review) && \is_string($result->review)) {
                $result->review = self::excerptWords($result->review, $maxwords);
            }
            $consoles[] = $result;
        }

        $platformInput = $this->scalarInput($request, 'platform');
        $platform = $platformInput !== '' ? stripslashes($platformInput) : '';
        $titleInput = $this->scalarInput($request, 'title');
        $title = $titleInput !== '' ? stripslashes($titleInput) : '';

        $genres = $gen->getGenres((string) GenreService::CONSOLE_TYPE, true);
        $tmpgnr = [];
        foreach ($genres as $gn) {
            /** @var Genre $gn */
            $tmpgnr[$gn->id] = $gn->title;
        }
        $genreInput = $this->scalarInput($request, 'genre');
        $genre = isset($tmpgnr[$genreInput]) ? $genreInput : '';

        // The console view receives the Category model itself when one is found.
        $cdata = (int) $category === -1 ? null : Category::find($category);
        $catname = $cdata ?? self::categoryDisplayName($category, null);

        $this->viewData = array_merge($this->viewData, [
            'catlist' => $ctmp,
            'category' => $category,
            'categorytitle' => $id,
            'platform' => $platform,
            'title' => $title,
            'genres' => $genres,
            'genre' => $genre,
            'catname' => $catname,
            'resultsadd' => $consoles,
            'results' => $results,
            'covgroup' => 'console',
            'meta_title' => 'Browse Console',
            'meta_keywords' => 'browse,nzb,console,games,description,details',
            'meta_description' => 'Browse for Console Games',
        ]);

        return view('console.index', $this->viewData);
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
