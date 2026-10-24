<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Services\GenreService;
use App\Services\MusicService;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Stringable;
use Throwable;

class AdminMusicController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_music_normalize_edit_fields(
        const char *salesrank_ptr,
        size_t salesrank_len,
        const char *releasedate_ptr,
        size_t releasedate_len,
        uint32_t is_utc,
        const char *genre_ptr,
        size_t genre_len,
        int64_t *out_scalars,
        char *out_buf,
        size_t out_cap
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
     * Display a listing of music
     */
    public function index(Request $request): mixed
    {
        $meta_title = $title = 'Music List';

        // Get search parameter
        $search = $this->scalarInput($request, 'musicsearch');

        if (! empty($search)) {
            $musicList = getRange('musicinfo');
            $lastSearch = $search;
        } else {
            $musicList = getRange('musicinfo');
            $lastSearch = '';
        }

        return view('admin.music.index', compact('title', 'meta_title', 'musicList', 'lastSearch'));
    }

    /**
     * Show the form for editing music
     */
    public function edit(Request $request): mixed
    {
        $music = new MusicService;
        $gen = new GenreService;

        $meta_title = $title = 'Music Edit';

        // Set the current action
        $action = $this->formAction($request);

        if ($request->has('id')) {
            $id = (int) $request->input('id');
            $mus = $music->getMusicInfo($id);

            if (! $mus) {
                abort(404, 'Music not found');
            }

            switch ($action) {
                case 'submit':
                    $coverDirectory = storage_path('covers/music/');
                    $imageService = app(ReleaseImageService::class);

                    if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
                        $imageService->saveUploadedImage((string) $id, $request->file('cover'), $coverDirectory);
                    }

                    $cover = (int) $imageService->imageExists($coverDirectory, (string) $id);
                    $normalized = self::normalizeMusicEditFields(
                        $request->input('salesrank'),
                        $request->input('releasedate'),
                        $mus->releasedate !== null ? (string) $mus->releasedate : null,
                        $request->input('genre'),
                    );
                    $salesrank = $normalized['salesrank'];
                    $releasedate = $normalized['releasedate'];
                    $genreId = $normalized['genre_id'];

                    $music->update(
                        $id,
                        (string) $request->input('title', ''),
                        $request->input('asin'),
                        $request->input('url'),
                        $salesrank,
                        $request->input('artist'),
                        $request->input('publisher'),
                        $releasedate,
                        $request->input('year'),
                        $request->input('tracks'),
                        $cover,
                        $genreId
                    );

                    return redirect()->route('admin.music-list')->with('success', 'Music updated successfully');

                case 'view':
                default:
                    $genres = $gen->getGenres((string) GenreService::MUSIC_TYPE);

                    return view('admin.music.edit', compact('title', 'meta_title', 'mus', 'genres'));
            }
        }

        abort(404, 'Music ID required');
    }

    /**
     * @return array{salesrank: ?int, releasedate: ?string, genre_id: ?int}
     */
    public static function normalizeMusicEditFields(
        mixed $salesrankInput,
        mixed $releasedateInput,
        ?string $fallbackReleaseDate,
        mixed $genreInput,
    ): array {
        if (self::$useRustFfi) {
            return self::normalizeMusicEditFieldsNative(
                $salesrankInput,
                $releasedateInput,
                $fallbackReleaseDate,
                $genreInput,
            );
        }

        return self::normalizeMusicEditFieldsPhp(
            $salesrankInput,
            $releasedateInput,
            $fallbackReleaseDate,
            $genreInput,
        );
    }

    /**
     * @return array{salesrank: ?int, releasedate: ?string, genre_id: ?int}
     */
    public static function normalizeMusicEditFieldsPhp(
        mixed $salesrankInput,
        mixed $releasedateInput,
        ?string $fallbackReleaseDate,
        mixed $genreInput,
    ): array {
        $salesrank = (empty($salesrankInput)
            || (! is_scalar($salesrankInput) && ! $salesrankInput instanceof Stringable)
            || ! ctype_digit((string) $salesrankInput))
            ? null
            : (int) $salesrankInput;
        $releasedate = (empty($releasedateInput) || ! is_string($releasedateInput) || ! strtotime($releasedateInput))
            ? $fallbackReleaseDate
            : Carbon::parse($releasedateInput)->toDateTimeString();
        $genreId = ! empty($genreInput) ? (int) $genreInput : null;

        return [
            'salesrank' => $salesrank,
            'releasedate' => $releasedate,
            'genre_id' => $genreId,
        ];
    }

    /**
     * @return array{salesrank: ?int, releasedate: ?string, genre_id: ?int}
     */
    public static function normalizeMusicEditFieldsNative(
        mixed $salesrankInput,
        mixed $releasedateInput,
        ?string $fallbackReleaseDate,
        mixed $genreInput,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeMusicEditFieldsPhp(
                $salesrankInput,
                $releasedateInput,
                $fallbackReleaseDate,
                $genreInput,
            );
        }

        if (($salesrankInput !== null && ! is_scalar($salesrankInput) && ! $salesrankInput instanceof Stringable)
            || ($releasedateInput !== null && ! is_string($releasedateInput))
            || ($genreInput !== null && ! is_scalar($genreInput) && ! $genreInput instanceof Stringable)) {
            return self::normalizeMusicEditFieldsPhp(
                $salesrankInput,
                $releasedateInput,
                $fallbackReleaseDate,
                $genreInput,
            );
        }

        $salesrankStr = empty($salesrankInput) ? '' : (string) $salesrankInput;
        $releasedateStr = empty($releasedateInput) ? '' : $releasedateInput;
        $genreStr = empty($genreInput) ? '' : (string) $genreInput;
        $isUtc = date_default_timezone_get() === 'UTC' ? 1 : 0;

        try {
            $scalars = $ffi->new('int64_t[5]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
            if ($buf === null) {
                return self::normalizeMusicEditFieldsPhp(
                    $salesrankInput,
                    $releasedateInput,
                    $fallbackReleaseDate,
                    $genreInput,
                );
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_music_normalize_edit_fields',
                $salesrankStr,
                strlen($salesrankStr),
                $releasedateStr,
                strlen($releasedateStr),
                $isUtc,
                $genreStr,
                strlen($genreStr),
                $scalars,
                $buf,
                $cap
            );
            if ($rc < 0) {
                return self::normalizeMusicEditFieldsPhp(
                    $salesrankInput,
                    $releasedateInput,
                    $fallbackReleaseDate,
                    $genreInput,
                );
            }

            return [
                'salesrank' => ((int) $scalars[0]) === 1 ? (int) $scalars[1] : null,
                'releasedate' => ((int) $scalars[2]) === 1
                    ? $fallbackReleaseDate
                    : FFI::string($buf, $rc),
                'genre_id' => ((int) $scalars[3]) === 1 ? (int) $scalars[4] : null,
            ];
        } catch (Throwable) {
            return self::normalizeMusicEditFieldsPhp(
                $salesrankInput,
                $releasedateInput,
                $fallbackReleaseDate,
                $genreInput,
            );
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
