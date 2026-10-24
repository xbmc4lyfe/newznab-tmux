<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Services\GamesService;
use App\Services\GenreService;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class AdminGameController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_game_normalize_search_term(
        const char *search_ptr,
        size_t search_len,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_game_parse_release_timestamp(
        const char *releasedate_ptr,
        size_t releasedate_len,
        uint32_t is_utc,
        int64_t *out_scalars
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminMediaCatalogControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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

    /**
     * Display a listing of games
     */
    public function index(Request $request): mixed
    {
        $game = new GamesService;

        $meta_title = $title = 'Game List';

        // Get search parameter
        $lastSearch = self::normalizeGameSearchTerm($this->scalarInput($request, 'gamesearch'));
        $gamelist = $lastSearch !== '' ? $game->getRange($lastSearch) : $game->getRange();

        return view('admin.games.index', compact('title', 'meta_title', 'gamelist', 'lastSearch'));
    }

    /**
     * Show the form for editing a game
     */
    public function edit(Request $request): mixed
    {
        $games = new GamesService;
        $gen = new GenreService;
        $meta_title = $title = 'Game Edit';

        // Set the current action.
        $action = $this->formAction($request);

        if ($request->has('id')) {
            $id = $request->input('id');
            $game = $games->getGamesInfoById($id);

            if (! $game) {
                abort(404, 'Game not found');
            }

            switch ($action) {
                case 'submit':
                    $coverDirectory = storage_path('covers/games/');
                    $imageService = app(ReleaseImageService::class);

                    if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
                        $imageService->saveUploadedImage((string) $id, $request->file('cover'), $coverDirectory);
                    }

                    $cover = (int) $imageService->imageExists($coverDirectory, (string) $id);
                    $releasedate = self::normalizeGameReleaseDate(
                        $request->input('releasedate'),
                        $game['releasedate'],
                    );

                    $games->update(
                        $id,
                        $request->input('title'),
                        $request->input('asin'),
                        $request->input('url'),
                        $request->input('publisher'),
                        $releasedate,
                        $request->input('esrb'),
                        $cover,
                        $request->input('trailerurl'),
                        $request->input('genre')
                    );

                    return redirect()->route('admin.game-list')->with('success', 'Game updated successfully');

                case 'view':
                default:
                    $genres = $gen->getGenres((string) GenreService::GAME_TYPE);

                    return view('admin.games.edit', compact('title', 'meta_title', 'game', 'genres'));
            }
        }

        abort(404, 'Game ID required');
    }

    public static function normalizeGameSearchTerm(mixed $search): string
    {
        if (self::$useRustFfi) {
            return self::normalizeGameSearchTermNative($search);
        }

        return self::normalizeGameSearchTermPhp($search);
    }

    public static function normalizeGameSearchTermPhp(mixed $search): string
    {
        if (! is_scalar($search)) {
            return '';
        }

        $value = (string) $search;

        return ! empty($value) ? $value : '';
    }

    public static function normalizeGameSearchTermNative(mixed $search): string
    {
        if (! is_scalar($search)) {
            return '';
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeGameSearchTermPhp($search);
        }

        $value = (string) $search;

        try {
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(32, strlen($value) + 8), $cap);
            if ($buf === null) {
                return self::normalizeGameSearchTermPhp($search);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_game_normalize_search_term',
                $value,
                strlen($value),
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::normalizeGameSearchTermPhp($search);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_admin_game_normalize_search_term',
                    $value,
                    strlen($value),
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::normalizeGameSearchTermPhp($search);
            }

            return $rc > 0 ? FFI::string($buf, $rc) : '';
        } catch (Throwable) {
            return self::normalizeGameSearchTermPhp($search);
        }
    }

    public static function normalizeGameReleaseDate(mixed $releasedateInput, mixed $fallbackReleaseDate): mixed
    {
        if (self::$useRustFfi) {
            return self::normalizeGameReleaseDateNative($releasedateInput, $fallbackReleaseDate);
        }

        return self::normalizeGameReleaseDatePhp($releasedateInput, $fallbackReleaseDate);
    }

    public static function normalizeGameReleaseDatePhp(mixed $releasedateInput, mixed $fallbackReleaseDate): mixed
    {
        if (empty($releasedateInput) || ! is_string($releasedateInput) || ! strtotime($releasedateInput)) {
            return $fallbackReleaseDate;
        }

        return Carbon::parse($releasedateInput)->timestamp;
    }

    public static function normalizeGameReleaseDateNative(mixed $releasedateInput, mixed $fallbackReleaseDate): mixed
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeGameReleaseDatePhp($releasedateInput, $fallbackReleaseDate);
        }

        if ($releasedateInput !== null && ! is_string($releasedateInput)) {
            return self::normalizeGameReleaseDatePhp($releasedateInput, $fallbackReleaseDate);
        }

        $releasedateStr = $releasedateInput ?? '';
        $isUtc = date_default_timezone_get() === 'UTC' ? 1 : 0;

        try {
            $scalars = $ffi->new('int64_t[2]');
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_game_parse_release_timestamp',
                $releasedateStr,
                strlen($releasedateStr),
                $isUtc,
                $scalars
            );
            if ($rc !== 0) {
                return self::normalizeGameReleaseDatePhp($releasedateInput, $fallbackReleaseDate);
            }

            return ((int) $scalars[0]) === 1 ? $fallbackReleaseDate : (int) $scalars[1];
        } catch (Throwable) {
            return self::normalizeGameReleaseDatePhp($releasedateInput, $fallbackReleaseDate);
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
