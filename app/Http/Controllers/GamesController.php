<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Genre;
use App\Services\GamesService;
use App\Services\GenreService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Throwable;

class GamesController extends BasePageController
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
    ssize_t nntmux_media_browse_game_filters(
        const char *category_ptr,
        size_t category_len,
        const int64_t *category_ids,
        size_t category_count,
        int64_t default_category,
        const char *year_ptr,
        size_t year_len,
        int64_t min_year,
        int64_t max_year,
        const char *genre_ptr,
        size_t genre_len,
        const int64_t *genre_ids,
        size_t genre_count,
        int64_t *out_fields
    );
    CDEF;

    private const ORDER_BY_KIND = 2;

    private const MIN_YEAR = 1903;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMediaBrowseControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @throws \Exception
     */
    public function show(Request $request): mixed
    {
        $games = new GamesService;
        $gen = new GenreService;

        $concats = Category::getChildren(Category::PC_ROOT);
        $ctmp = [];
        foreach ($concats as $ccat) {
            $ctmp[$ccat['id']] = $ccat;
        }

        $genres = $gen->getGenres((string) GenreService::GAME_TYPE, true);
        $tmpgnr = [];
        foreach ($genres as $gn) {
            /** @var Genre $gn */
            $tmpgnr[$gn->id] = $gn->title;
        }

        $years = range(self::MIN_YEAR, date('Y') + 1);
        rsort($years);
        $filters = self::gameFilters(
            $this->scalarInput($request, 't'),
            $ctmp,
            Category::PC_GAMES,
            $this->scalarInput($request, 'year'),
            self::MIN_YEAR,
            (int) date('Y') + 1,
            $this->scalarInput($request, 'genre'),
            $tmpgnr
        );
        $category = $filters['category'];
        $year = $filters['year'];
        $genre = $filters['genre'];

        $catarray = [];
        $catarray[] = $category;

        $page = $this->resolvePage($request);
        $ordering = $games->getGamesOrdering();
        $orderby = $this->resolveOrderBy($request, $ordering);
        $perPage = (int) config('nntmux.items_per_cover_page');
        $offset = $this->paginationOffset($page, $perPage);
        $rslt = $games->getGamesRange($page, $catarray, $offset, $perPage, $orderby, '', (array) $this->userdata->categoryexclusions);
        $results = $this->paginate($rslt, $rslt[0]->_totalcount ?? 0, $perPage, $page, $request->url(), $request->query());

        $titleInput = $this->scalarInput($request, 'title');
        $title = $titleInput !== '' ? stripslashes($titleInput) : '';

        $cdata = $category === -1 ? null : Category::find($category);
        $catname = self::categoryDisplayName($category, $cdata?->title);

        // Build order by URLs
        $orderByUrls = array_map(
            static fn (string $path): string => url($path),
            self::orderByPaths($ordering)
        );

        $this->viewData = array_merge($this->viewData, [
            'catlist' => $ctmp,
            'category' => $category,
            'catname' => $catname,
            'title' => $title,
            'genres' => $genres,
            'genre' => $genre,
            'years' => $years,
            'year' => $year,
            'results' => $results,
            'covgroup' => 'games',
            'meta_title' => 'Browse Games',
            'meta_keywords' => 'browse,nzb,games,description,details',
            'meta_description' => 'Browse for Games',
        ], $orderByUrls);

        return view('games.index', $this->viewData);
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
    public static function orderByPaths(array $ordering): array
    {
        return self::$useRustFfi
            ? self::orderByPathsNative($ordering)
            : self::orderByPathsPhp($ordering);
    }

    /**
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPathsPhp(array $ordering): array
    {
        $paths = [];
        foreach ($ordering as $ordertype) {
            $paths['orderby'.$ordertype] = 'Games?ob='.$ordertype;
        }

        return $paths;
    }

    /**
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPathsNative(array $ordering): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::orderByPathsPhp($ordering);
        }

        try {
            $types = [];
            foreach ($ordering as $ordertype) {
                $type = (string) $ordertype;
                if (str_contains($type, "\0")) {
                    return self::orderByPathsPhp($ordering);
                }
                $types[] = $type;
            }

            $count = \count($types);
            $packed = implode("\0", $types);
            $idText = '';
            $categoryText = '';
            $extraText = '';
            $estimate = $count * (32 + max(3, \strlen($idText)) + \strlen($categoryText) + \strlen($extraText)) + \strlen($packed);
            $lens = $ffi->new('size_t['.max(1, $count).']');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return self::orderByPathsPhp($ordering);
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
                return self::orderByPathsPhp($ordering);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $paths = [];
            $offset = 0;
            foreach ($types as $index => $type) {
                $len = (int) $lens[$index];
                $paths['orderby'.$type] = (string) substr($payload, $offset, $len);
                $offset += $len;
            }

            return $offset === $written ? $paths : self::orderByPathsPhp($ordering);
        } catch (Throwable) {
            return self::orderByPathsPhp($ordering);
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
     * Validates the PC game category, year and genre browse filters.
     *
     * @param  array<array-key, mixed>  $categories  keyed by category id
     * @param  array<array-key, mixed>  $genreTitles  keyed by genre id
     * @return array{category: int, year: int|string, genre: string}
     */
    public static function gameFilters(
        string $categoryInput,
        array $categories,
        int $defaultCategory,
        string $yearInput,
        int $minYear,
        int $maxYear,
        string $genreInput,
        array $genreTitles
    ): array {
        return self::$useRustFfi
            ? self::gameFiltersNative($categoryInput, $categories, $defaultCategory, $yearInput, $minYear, $maxYear, $genreInput, $genreTitles)
            : self::gameFiltersPhp($categoryInput, $categories, $defaultCategory, $yearInput, $minYear, $maxYear, $genreInput, $genreTitles);
    }

    /**
     * @param  array<array-key, mixed>  $categories
     * @param  array<array-key, mixed>  $genreTitles
     * @return array{category: int, year: int|string, genre: string}
     */
    public static function gameFiltersPhp(
        string $categoryInput,
        array $categories,
        int $defaultCategory,
        string $yearInput,
        int $minYear,
        int $maxYear,
        string $genreInput,
        array $genreTitles
    ): array {
        $category = $defaultCategory;
        if ($categoryInput !== '' && isset($categories[$categoryInput])) {
            $category = (int) $categoryInput;
        }

        $yearValue = is_numeric($yearInput) ? (int) $yearInput : null;
        $year = $yearValue !== null && $yearValue >= $minYear && $yearValue <= $maxYear ? $yearValue : '';

        return [
            'category' => $category,
            'year' => $year,
            'genre' => isset($genreTitles[$genreInput]) ? $genreInput : '',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $categories
     * @param  array<array-key, mixed>  $genreTitles
     * @return array{category: int, year: int|string, genre: string}
     */
    public static function gameFiltersNative(
        string $categoryInput,
        array $categories,
        int $defaultCategory,
        string $yearInput,
        int $minYear,
        int $maxYear,
        string $genreInput,
        array $genreTitles
    ): array {
        $ffi = $minYear > $maxYear ? null : self::ffi();
        $categoryIds = self::presentIntKeys($categories);
        $genreIds = self::presentIntKeys($genreTitles);
        if ($ffi === null || $categoryIds === null || $genreIds === null) {
            return self::gameFiltersPhp($categoryInput, $categories, $defaultCategory, $yearInput, $minYear, $maxYear, $genreInput, $genreTitles);
        }

        try {
            $categoryBuf = $ffi->new('int64_t['.max(1, \count($categoryIds)).']');
            foreach ($categoryIds as $index => $id) {
                $categoryBuf[$index] = $id;
            }
            $genreBuf = $ffi->new('int64_t['.max(1, \count($genreIds)).']');
            foreach ($genreIds as $index => $id) {
                $genreBuf[$index] = $id;
            }
            $out = $ffi->new('int64_t[4]');

            $rc = self::callFfi(
                $ffi,
                'nntmux_media_browse_game_filters',
                $categoryInput,
                \strlen($categoryInput),
                $categoryBuf,
                \count($categoryIds),
                $defaultCategory,
                $yearInput,
                \strlen($yearInput),
                $minYear,
                $maxYear,
                $genreInput,
                \strlen($genreInput),
                $genreBuf,
                \count($genreIds),
                $out
            );
            if ($rc !== 0) {
                return self::gameFiltersPhp($categoryInput, $categories, $defaultCategory, $yearInput, $minYear, $maxYear, $genreInput, $genreTitles);
            }

            return [
                'category' => (int) $out[0],
                'year' => (int) $out[1] === 1 ? (int) $out[2] : '',
                'genre' => (int) $out[3] === 1 ? $genreInput : '',
            ];
        } catch (Throwable) {
            return self::gameFiltersPhp($categoryInput, $categories, $defaultCategory, $yearInput, $minYear, $maxYear, $genreInput, $genreTitles);
        }
    }

    /**
     * Int keys whose value passes `isset()`, or null when a string key would need PHP lookup.
     *
     * @param  array<array-key, mixed>  $map
     * @return list<int>|null
     */
    private static function presentIntKeys(array $map): ?array
    {
        $keys = [];
        foreach ($map as $key => $value) {
            if (! \is_int($key)) {
                return null;
            }
            if ($value !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
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
