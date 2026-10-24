<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Genre;
use App\Services\GenreService;
use App\Services\MusicService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Throwable;

class MusicController extends BasePageController
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
    ssize_t nntmux_media_browse_strip_inputs(
        const char *artist_ptr,
        size_t artist_len,
        const char *title_ptr,
        size_t title_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    private const ORDER_BY_KIND = 3;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMediaBrowseControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected MusicService $musicService;

    protected GenreService $genreService;

    public function __construct(MusicService $musicService, GenreService $genreService)
    {
        parent::__construct();
        $this->musicService = $musicService;
        $this->genreService = $genreService;
    }

    /**
     * @throws \Exception
     */
    public function show(Request $request, string $id = ''): mixed
    {
        $musiccats = Category::getChildren(Category::MUSIC_ROOT);
        $mtmp = [];
        foreach ($musiccats as $mcat) {
            $mtmp[] =
                [
                    'id' => $mcat->id,
                    'title' => $mcat->title,
                ];
        }

        $category = $request->has('t') ? $this->scalarInput($request, 't', (string) Category::MUSIC_ROOT) : Category::MUSIC_ROOT;
        if ($id && \in_array($id, Arr::pluck($mtmp, 'title'), true)) {
            $cat = Category::query()
                ->where('title', $id)
                ->where('root_categories_id', '=', Category::MUSIC_ROOT)
                ->first(['id']);
            $category = $cat !== null ? (int) $cat['id'] : Category::MUSIC_ROOT;
        }

        $catarray = [];
        $catarray[] = (int) $category;

        $page = $this->resolvePage($request);
        $perPage = (int) config('nntmux.items_per_cover_page');
        $offset = $this->paginationOffset($page, $perPage);
        $ordering = $this->musicService->getMusicOrdering();
        $orderby = $this->resolveOrderBy($request, $ordering);

        $musics = [];
        $rslt = $this->musicService->getMusicRange($page, $catarray, $offset, $perPage, $orderby, (array) $this->userdata->categoryexclusions);
        $results = $this->paginate($rslt ?? [], $rslt[0]->_totalcount ?? 0, $perPage, $page, $request->url(), $request->query());

        $browseInputs = self::browseInputs($this->scalarInput($request, 'artist'), $this->scalarInput($request, 'title'));
        $artist = $browseInputs['artist'];
        $title = $browseInputs['title'];

        $genres = $this->genreService->getGenres((string) GenreService::MUSIC_TYPE, true);
        $tmpgnr = [];
        foreach ($genres as $gn) {
            /** @var Genre $gn */
            $tmpgnr[$gn->id] = $gn->title;
        }

        foreach ($results as $result) {
            $res = $result;
            $result->genre = $tmpgnr[$res->genres_id] ?? '';
            $musics[] = $result;
        }

        $genreInput = $this->scalarInput($request, 'genre');
        $genre = isset($tmpgnr[$genreInput]) ? $genreInput : '';

        $years = range(1950, date('Y') + 1);
        rsort($years);
        $yearInput = $this->scalarInput($request, 'year');
        $yearValue = is_numeric($yearInput) ? (int) $yearInput : null;
        $year = $yearValue !== null && \in_array($yearValue, $years, true) ? $yearValue : '';

        $cdata = (int) $category === -1 ? null : Category::find($category);
        $catname = self::categoryDisplayName($category, $cdata?->title);

        // Build order by URLs
        $orderByUrls = array_map(
            static fn (string $path): string => url($path),
            self::orderByPaths($ordering, $id)
        );

        $this->viewData = array_merge($this->viewData, [
            'catlist' => $mtmp,
            'category' => $category,
            'categorytitle' => $id,
            'catname' => $catname,
            'artist' => $artist,
            'title' => $title,
            'genres' => $genres,
            'genre' => $genre,
            'years' => $years,
            'year' => $year,
            'resultsadd' => $musics,
            'results' => $results,
            'covgroup' => 'music',
            'meta_title' => 'Browse Albums',
            'meta_keywords' => 'browse,nzb,albums,description,details',
            'meta_description' => 'Browse for Albums',
        ], $orderByUrls);

        return view('music.index', $this->viewData);
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
    public static function orderByPaths(array $ordering, string $id): array
    {
        return self::$useRustFfi
            ? self::orderByPathsNative($ordering, $id)
            : self::orderByPathsPhp($ordering, $id);
    }

    /**
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPathsPhp(array $ordering, string $id): array
    {
        $paths = [];
        foreach ($ordering as $ordertype) {
            $paths['orderby'.$ordertype] = 'music/'.($id ?: 'All').'?ob='.$ordertype;
        }

        return $paths;
    }

    /**
     * @param  array<array-key, string>  $ordering
     * @return array<string, string>
     */
    public static function orderByPathsNative(array $ordering, string $id): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::orderByPathsPhp($ordering, $id);
        }

        try {
            $types = [];
            foreach ($ordering as $ordertype) {
                $type = (string) $ordertype;
                if (str_contains($type, "\0")) {
                    return self::orderByPathsPhp($ordering, $id);
                }
                $types[] = $type;
            }

            $count = \count($types);
            $packed = implode("\0", $types);
            $idText = $id;
            $categoryText = '';
            $extraText = '';
            $estimate = $count * (32 + max(3, \strlen($idText)) + \strlen($categoryText) + \strlen($extraText)) + \strlen($packed);
            $lens = $ffi->new('size_t['.max(1, $count).']');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return self::orderByPathsPhp($ordering, $id);
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
                return self::orderByPathsPhp($ordering, $id);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $paths = [];
            $offset = 0;
            foreach ($types as $index => $type) {
                $len = (int) $lens[$index];
                $paths['orderby'.$type] = (string) substr($payload, $offset, $len);
                $offset += $len;
            }

            return $offset === $written ? $paths : self::orderByPathsPhp($ordering, $id);
        } catch (Throwable) {
            return self::orderByPathsPhp($ordering, $id);
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
     * `stripslashes()`-normalized artist/title browse filters.
     *
     * @return array{artist: string, title: string}
     */
    public static function browseInputs(string $artist, string $title): array
    {
        return self::$useRustFfi
            ? self::browseInputsNative($artist, $title)
            : self::browseInputsPhp($artist, $title);
    }

    /**
     * @return array{artist: string, title: string}
     */
    public static function browseInputsPhp(string $artist, string $title): array
    {
        return [
            'artist' => $artist !== '' ? stripslashes($artist) : '',
            'title' => $title !== '' ? stripslashes($title) : '',
        ];
    }

    /**
     * @return array{artist: string, title: string}
     */
    public static function browseInputsNative(string $artist, string $title): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::browseInputsPhp($artist, $title);
        }

        try {
            $lens = $ffi->new('size_t[2]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, \strlen($artist) + \strlen($title)), $capacity);
            if ($outBuf === null) {
                return self::browseInputsPhp($artist, $title);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_media_browse_strip_inputs',
                $artist,
                \strlen($artist),
                $title,
                \strlen($title),
                $lens,
                $outBuf,
                $capacity
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::browseInputsPhp($artist, $title);
            }

            $artistLen = (int) $lens[0];
            $titleLen = (int) $lens[1];
            if ($artistLen + $titleLen !== $written) {
                return self::browseInputsPhp($artist, $title);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);

            return [
                'artist' => (string) substr($payload, 0, $artistLen),
                'title' => (string) substr($payload, $artistLen, $titleLen),
            ];
        } catch (Throwable) {
            return self::browseInputsPhp($artist, $title);
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
