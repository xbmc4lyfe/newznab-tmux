<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Facades\Search;
use App\Http\Controllers\BasePageController;
use App\Models\MovieInfo;
use App\Models\Release;
use App\Services\MovieService;
use App\Services\ReleaseImageService;
use App\Support\ReleaseSearchIndexSync;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AdminMovieController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_admin_movie_is_numeric_imdb_id(
        const char *id_ptr,
        size_t id_len
    );
    ssize_t nntmux_admin_movie_build_image_keys(
        const char *id_ptr,
        size_t id_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_movie_normalize_update_flags(
        int64_t has_cover,
        int64_t has_backdrop,
        int64_t *out_flags
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminMediaCatalogControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected MovieService $movieService;

    protected ReleaseImageService $imageService;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
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

    public function __construct(MovieService $movieService, ReleaseImageService $imageService)
    {
        parent::__construct();
        $this->movieService = $movieService;
        $this->imageService = $imageService;
    }

    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $lastSearch = $this->scalarInput($request, 'moviesearch');

        if ($request->has('moviesearch')) {
            $movielist = MovieInfo::getAll($lastSearch);
        } else {
            $movielist = MovieInfo::getAll();
        }

        $title = 'Movie List';

        return view('admin.movies.index', compact('title', 'movielist', 'lastSearch'));
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function create(Request $request)
    {
        if (! \defined('STDOUT')) {
            \define('STDOUT', fopen('php://stdout', 'wb'));
        }

        $title = 'Movie Add';

        // Only POST submissions may fetch and persist a new movie.
        if (! $request->isMethod('post') || ! $request->has('id')) {
            return view('admin.movies.add', compact('title'));
        }

        // Validate the IMDB ID
        $id = $request->input('id');

        if (! self::isValidNumericImdbId($id)) {
            return redirect()->back()
                ->with('error', 'Invalid IMDB ID. Please enter only numeric digits (without the "tt" prefix).')
                ->withInput();
        }

        // Check if movie already exists
        $movCheck = $this->movieService->getMovieInfo($id);

        if ($movCheck !== null) {
            return redirect()->to('/admin/movie-edit?id='.$id)
                ->with('warning', 'Movie already exists in the database. Redirected to edit page.');
        }

        // Try to fetch and add the movie from TMDB
        try {
            $movieInfo = $this->movieService->updateMovieInfo($id);

            if ($movieInfo) {
                // Link any existing releases to this movie
                $forUpdate = Release::query()->where('imdbid', $id)->get(['id']);
                if ($forUpdate !== null && $forUpdate->count() > 0) {
                    $movieInfoId = MovieInfo::query()->where('imdbid', $id)->first(['id']);
                    if ($movieInfoId !== null) {
                        foreach ($forUpdate as $rel) {
                            Release::query()->where('id', $rel->id)->update(['movieinfo_id' => $movieInfoId->id]);
                            Search::updateRelease((int) $rel->id);
                        }
                    }
                }

                return redirect()->to('/admin/movie-list')
                    ->with('success', 'Movie successfully added! IMDB ID: '.$id);
            } else {
                return redirect()->back()
                    ->with('error', 'Could not fetch movie information from TMDB/IMDB. Please verify the IMDB ID is correct.')
                    ->withInput();
            }
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Error adding movie: '.$e->getMessage())
                ->withInput();
        }
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $title = 'Movie Edit';

        // Check if ID is provided
        if (! $request->has('id')) {
            return redirect()->to('admin/movie-list')
                ->with('error', 'No movie ID provided.');
        }

        $id = $request->input('id');
        $mov = $this->movieService->getMovieInfo($id);

        if ($mov === null) {
            return redirect()->to('admin/movie-list')
                ->with('error', 'Movie with IMDB ID '.$id.' does not exist in database.');
        }

        // Handle update from TMDB
        if ($request->isMethod('post') && $request->has('update') && (int) $request->input('update') === 1) {
            try {
                if (! \defined('STDOUT')) {
                    \define('STDOUT', fopen('php://stdout', 'wb'));
                }

                $movieInfo = $this->movieService->updateMovieInfo($id);

                if ($movieInfo) {
                    return redirect()->back()
                        ->with('success', 'Movie information updated successfully from TMDB!');
                } else {
                    return redirect()->back()
                        ->with('error', 'Failed to update movie information from TMDB. Please try again.');
                }
            } catch (\Exception $e) {
                return redirect()->back()
                    ->with('error', 'Error updating movie: '.$e->getMessage());
            }
        }

        // Handle form submission
        $action = $this->formAction($request);

        if ($action === 'submit') {
            try {
                $imageDirectory = public_path('covers/movies/');

                // Ensure directory exists
                if (! file_exists(public_path('covers/movies'))) {
                    mkdir(public_path('covers/movies'), 0755, true);
                }

                $imageKeys = self::buildMovieImageKeys((string) $id);

                // Handle cover upload
                if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
                    $this->imageService->saveUploadedImage($imageKeys['cover'], $request->file('cover'), $imageDirectory);
                }

                // Handle backdrop upload
                if ($request->hasFile('backdrop') && $request->file('backdrop')->isValid()) {
                    $this->imageService->saveUploadedImage($imageKeys['backdrop'], $request->file('backdrop'), $imageDirectory);
                }

                $payload = self::buildMovieUpdatePayload(
                    $id,
                    $request->all(),
                    $this->imageService->imageExists($imageDirectory, $imageKeys['cover']),
                    $this->imageService->imageExists($imageDirectory, $imageKeys['backdrop']),
                );
                $request->merge(['cover' => $payload['cover']]);
                $request->merge(['backdrop' => $payload['backdrop']]);

                $this->movieService->update($payload);

                // Link releases to this movie
                $movieInfo = MovieInfo::query()->where('imdbid', $id)->first(['id']);
                if ($movieInfo !== null) {
                    Release::query()->where('imdbid', $id)->update(['movieinfo_id' => $movieInfo->id]);
                    ReleaseSearchIndexSync::forIds(
                        Release::query()->where('imdbid', $id)->pluck('id')
                    );
                }

                return redirect()->to('admin/movie-list')
                    ->with('success', 'Movie updated successfully!');
            } catch (\Exception $e) {
                return redirect()->back()
                    ->with('error', 'Error saving movie: '.$e->getMessage())
                    ->withInput();
            }
        }

        return view('admin.movies.edit', compact('title', 'mov'))->with('movie', $mov);
    }

    public static function isValidNumericImdbId(mixed $id): bool
    {
        if (self::$useRustFfi) {
            return self::isValidNumericImdbIdNative($id);
        }

        return self::isValidNumericImdbIdPhp($id);
    }

    public static function isValidNumericImdbIdPhp(mixed $id): bool
    {
        return is_numeric($id);
    }

    public static function isValidNumericImdbIdNative(mixed $id): bool
    {
        if (! is_string($id)) {
            return self::isValidNumericImdbIdPhp($id);
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isValidNumericImdbIdPhp($id);
        }

        try {
            $rc = (int) self::callFfi($ffi, 'nntmux_admin_movie_is_numeric_imdb_id', $id, strlen($id));
            if ($rc < 0) {
                return self::isValidNumericImdbIdPhp($id);
            }

            return $rc === 1;
        } catch (Throwable) {
            return self::isValidNumericImdbIdPhp($id);
        }
    }

    /**
     * @return array{cover: string, backdrop: string}
     */
    public static function buildMovieImageKeys(int|string $id): array
    {
        if (self::$useRustFfi) {
            return self::buildMovieImageKeysNative($id);
        }

        return self::buildMovieImageKeysPhp($id);
    }

    /**
     * @return array{cover: string, backdrop: string}
     */
    public static function buildMovieImageKeysPhp(int|string $id): array
    {
        $idStr = (string) $id;

        return [
            'cover' => $idStr.'-cover',
            'backdrop' => $idStr.'-backdrop',
        ];
    }

    /**
     * @return array{cover: string, backdrop: string}
     */
    public static function buildMovieImageKeysNative(int|string $id): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildMovieImageKeysPhp($id);
        }

        $idStr = (string) $id;

        try {
            $lens = $ffi->new('size_t[2]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(64, strlen($idStr) * 2 + 32), $cap);
            if ($buf === null) {
                return self::buildMovieImageKeysPhp($id);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_movie_build_image_keys',
                $idStr,
                strlen($idStr),
                $lens,
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::buildMovieImageKeysPhp($id);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_admin_movie_build_image_keys',
                    $idStr,
                    strlen($idStr),
                    $lens,
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::buildMovieImageKeysPhp($id);
            }

            $coverLen = (int) $lens[0];
            $backdropLen = (int) $lens[1];
            if ($coverLen + $backdropLen !== $rc) {
                return self::buildMovieImageKeysPhp($id);
            }

            $packed = FFI::string($buf, $rc);

            return [
                'cover' => substr($packed, 0, $coverLen),
                'backdrop' => substr($packed, $coverLen, $backdropLen),
            ];
        } catch (Throwable) {
            return self::buildMovieImageKeysPhp($id);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     actors: mixed,
     *     backdrop: int,
     *     cover: int,
     *     director: mixed,
     *     genre: mixed,
     *     imdbid: mixed,
     *     language: mixed,
     *     plot: mixed,
     *     rating: mixed,
     *     tagline: mixed,
     *     title: mixed,
     *     year: mixed
     * }
     */
    public static function buildMovieUpdatePayload(
        mixed $id,
        array $input,
        bool|int $hasCover = false,
        bool|int $hasBackdrop = false,
    ): array {
        if (self::$useRustFfi) {
            return self::buildMovieUpdatePayloadNative($id, $input, $hasCover, $hasBackdrop);
        }

        return self::buildMovieUpdatePayloadPhp($id, $input, $hasCover, $hasBackdrop);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     actors: mixed,
     *     backdrop: int,
     *     cover: int,
     *     director: mixed,
     *     genre: mixed,
     *     imdbid: mixed,
     *     language: mixed,
     *     plot: mixed,
     *     rating: mixed,
     *     tagline: mixed,
     *     title: mixed,
     *     year: mixed
     * }
     */
    public static function buildMovieUpdatePayloadPhp(
        mixed $id,
        array $input,
        bool|int $hasCover = false,
        bool|int $hasBackdrop = false,
    ): array {
        return [
            'actors' => $input['actors'] ?? null,
            'backdrop' => ((int) $hasBackdrop) !== 0 ? 1 : 0,
            'cover' => ((int) $hasCover) !== 0 ? 1 : 0,
            'director' => $input['director'] ?? null,
            'genre' => $input['genre'] ?? null,
            'imdbid' => $id,
            'language' => $input['language'] ?? null,
            'plot' => $input['plot'] ?? null,
            'rating' => $input['rating'] ?? null,
            'tagline' => $input['tagline'] ?? null,
            'title' => $input['title'] ?? null,
            'year' => $input['year'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     actors: mixed,
     *     backdrop: int,
     *     cover: int,
     *     director: mixed,
     *     genre: mixed,
     *     imdbid: mixed,
     *     language: mixed,
     *     plot: mixed,
     *     rating: mixed,
     *     tagline: mixed,
     *     title: mixed,
     *     year: mixed
     * }
     */
    public static function buildMovieUpdatePayloadNative(
        mixed $id,
        array $input,
        bool|int $hasCover = false,
        bool|int $hasBackdrop = false,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildMovieUpdatePayloadPhp($id, $input, $hasCover, $hasBackdrop);
        }

        try {
            $flags = $ffi->new('int64_t[2]');
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_movie_normalize_update_flags',
                (int) $hasCover,
                (int) $hasBackdrop,
                $flags
            );
            if ($rc !== 0) {
                return self::buildMovieUpdatePayloadPhp($id, $input, $hasCover, $hasBackdrop);
            }

            return [
                'actors' => $input['actors'] ?? null,
                'backdrop' => (int) $flags[1],
                'cover' => (int) $flags[0],
                'director' => $input['director'] ?? null,
                'genre' => $input['genre'] ?? null,
                'imdbid' => $id,
                'language' => $input['language'] ?? null,
                'plot' => $input['plot'] ?? null,
                'rating' => $input['rating'] ?? null,
                'tagline' => $input['tagline'] ?? null,
                'title' => $input['title'] ?? null,
                'year' => $input['year'] ?? null,
            ];
        } catch (Throwable) {
            return self::buildMovieUpdatePayloadPhp($id, $input, $hasCover, $hasBackdrop);
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

        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::FFI_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
