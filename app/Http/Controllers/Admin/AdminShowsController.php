<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Release;
use App\Models\Video;
use App\Services\TvProcessing\TvShowAdder;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AdminShowsController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_shows_normalize_preview_ids(
        uint32_t has_tvdb,
        int64_t tvdb,
        uint32_t has_tvmaze,
        int64_t tvmaze,
        uint32_t has_tmdb,
        int64_t tmdb,
        uint32_t has_trakt,
        int64_t trakt,
        uint32_t has_tvrage,
        int64_t tvrage,
        uint32_t has_imdb,
        const char *imdb_ptr,
        size_t imdb_len,
        int64_t *out_ids,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_shows_format_create_flash(
        uint32_t existed,
        const char *title_ptr,
        size_t title_len,
        size_t *out_lens,
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
     * Display a listing of TV shows
     */
    public function index(Request $request): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'TV Shows List';

        $showname = $this->scalarInput($request, 'showname');
        $tvshowlist = Video::getRange($showname);

        return view('admin.shows.index', compact('tvshowlist', 'showname', 'title', 'meta_title'));
    }

    /**
     * Show the form for editing a TV show
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $this->setAdminPrefs();

        $action = $this->formAction($request);

        if ($action === 'submit') {
            if ($request->has('from') && ! empty($request->input('from'))) {
                return redirect($request->input('from'));
            }

            return redirect()->route('admin.show-list')->with('success', 'TV show updated successfully');
        }

        $show = null;
        if ($request->has('id')) {
            $show = Video::getByVideoID($request->input('id'));
        }

        if (! $show) {
            return redirect()->route('admin.show-list')->with('error', 'TV show not found');
        }

        $meta_title = $title = 'Edit TV Show Data';

        return view('admin.shows.edit', compact('show', 'title', 'meta_title'));
    }

    /**
     * Show the "Add TV Show" form and handle submissions.
     *
     * Accepts any supported external ID (tvdb, tvmaze, tmdb, trakt, imdb),
     * resolves it via TvShowAdder and persists it through the regular TV
     * provider pipeline so the videos / tv_info / videos_aliases tables and
     * the search index all stay in sync.
     */
    public function create(Request $request, TvShowAdder $adder): View|RedirectResponse
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Add TV Show';
        $sources = TvShowAdder::SUPPORTED_SOURCES;

        if ($request->isMethod('post')) {
            $data = $request->validate([
                'source' => 'required|string|in:'.implode(',', $sources),
                'external_id' => 'required|string|max:32',
                'type' => 'nullable|integer|in:0,2',
            ]);

            try {
                $result = $adder->add(
                    $data['source'],
                    (string) $data['external_id'],
                    (int) ($data['type'] ?? 0),
                );
            } catch (\InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage())->withInput();
            } catch (Throwable $e) {
                return back()->with('error', 'Lookup failed: '.$e->getMessage())->withInput();
            }

            if ($result['videoId'] <= 0) {
                return back()->with('error', 'No show could be added for that ID.')->withInput();
            }

            $flash = self::formatCreateFlash(
                (bool) $result['existed'],
                isset($result['title']) ? (string) $result['title'] : null,
            );

            return redirect()->to('/admin/show-edit?id='.$result['videoId'])->with($flash['key'], $flash['message']);
        }

        return view('admin.shows.add', compact('title', 'meta_title', 'sources'));
    }

    /**
     * JSON endpoint for the optional "preview before submit" button.
     */
    public function lookup(Request $request, TvShowAdder $adder): JsonResponse
    {
        $this->setAdminPrefs();

        $data = $request->validate([
            'source' => 'required|string|in:'.implode(',', TvShowAdder::SUPPORTED_SOURCES),
            'external_id' => 'required|string|max:32',
        ]);

        try {
            $preview = $adder->preview($data['source'], (string) $data['external_id']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Lookup failed: '.$e->getMessage()], 500);
        }

        if ($preview === null) {
            return response()->json(['ok' => false, 'error' => 'No show found for that ID.'], 404);
        }

        return response()->json([
            'ok' => true,
            'show' => self::normalizeLookupPreviewPayload($preview),
        ]);
    }

    /**
     * Remove video ID from releases
     */
    public function destroy(Request $request): View
    {
        $this->setAdminPrefs();

        $id = $request->route('id');
        $success = false;
        $videoid = null;

        if ($id) {
            $success = Release::removeVideoIdFromReleases($id);
            $videoid = $id;
        }

        $meta_title = $title = 'Remove Video and Episode IDs from Releases';

        return view('admin.shows.remove', compact('success', 'videoid', 'title', 'meta_title'));
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{
     *     title: mixed,
     *     summary: mixed,
     *     started: mixed,
     *     publisher: mixed,
     *     poster: mixed,
     *     ids: array{tvdb: mixed, tvmaze: mixed, tmdb: mixed, trakt: mixed, imdb: mixed, tvrage: mixed}
     * }
     */
    public static function normalizeLookupPreviewPayload(array $preview): array
    {
        if (self::$useRustFfi) {
            return self::normalizeLookupPreviewPayloadNative($preview);
        }

        return self::normalizeLookupPreviewPayloadPhp($preview);
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{
     *     title: mixed,
     *     summary: mixed,
     *     started: mixed,
     *     publisher: mixed,
     *     poster: mixed,
     *     ids: array{tvdb: mixed, tvmaze: mixed, tmdb: mixed, trakt: mixed, imdb: mixed, tvrage: mixed}
     * }
     */
    public static function normalizeLookupPreviewPayloadPhp(array $preview): array
    {
        return [
            'title' => $preview['title'] ?? null,
            'summary' => $preview['summary'] ?? null,
            'started' => $preview['started'] ?? null,
            'publisher' => $preview['publisher'] ?? null,
            'poster' => $preview['poster'] ?? null,
            'ids' => [
                'tvdb' => $preview['tvdb'] ?? 0,
                'tvmaze' => $preview['tvmaze'] ?? 0,
                'tmdb' => $preview['tmdb'] ?? 0,
                'trakt' => $preview['trakt'] ?? 0,
                'imdb' => $preview['imdb'] ?? '',
                'tvrage' => $preview['tvrage'] ?? 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{
     *     title: mixed,
     *     summary: mixed,
     *     started: mixed,
     *     publisher: mixed,
     *     poster: mixed,
     *     ids: array{tvdb: mixed, tvmaze: mixed, tmdb: mixed, trakt: mixed, imdb: mixed, tvrage: mixed}
     * }
     */
    public static function normalizeLookupPreviewPayloadNative(array $preview): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeLookupPreviewPayloadPhp($preview);
        }

        foreach (['tvdb', 'tvmaze', 'tmdb', 'trakt', 'tvrage'] as $intKey) {
            if (isset($preview[$intKey]) && ! is_int($preview[$intKey])) {
                return self::normalizeLookupPreviewPayloadPhp($preview);
            }
        }
        if (isset($preview['imdb']) && ! is_string($preview['imdb'])) {
            return self::normalizeLookupPreviewPayloadPhp($preview);
        }

        $imdb = isset($preview['imdb']) ? $preview['imdb'] : '';

        try {
            $outIds = $ffi->new('int64_t[5]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(32, strlen($imdb) + 8), $cap);
            if ($buf === null) {
                return self::normalizeLookupPreviewPayloadPhp($preview);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_shows_normalize_preview_ids',
                isset($preview['tvdb']) ? 1 : 0,
                isset($preview['tvdb']) ? $preview['tvdb'] : 0,
                isset($preview['tvmaze']) ? 1 : 0,
                isset($preview['tvmaze']) ? $preview['tvmaze'] : 0,
                isset($preview['tmdb']) ? 1 : 0,
                isset($preview['tmdb']) ? $preview['tmdb'] : 0,
                isset($preview['trakt']) ? 1 : 0,
                isset($preview['trakt']) ? $preview['trakt'] : 0,
                isset($preview['tvrage']) ? 1 : 0,
                isset($preview['tvrage']) ? $preview['tvrage'] : 0,
                isset($preview['imdb']) ? 1 : 0,
                $imdb,
                strlen($imdb),
                $outIds,
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::normalizeLookupPreviewPayloadPhp($preview);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_admin_shows_normalize_preview_ids',
                    isset($preview['tvdb']) ? 1 : 0,
                    isset($preview['tvdb']) ? $preview['tvdb'] : 0,
                    isset($preview['tvmaze']) ? 1 : 0,
                    isset($preview['tvmaze']) ? $preview['tvmaze'] : 0,
                    isset($preview['tmdb']) ? 1 : 0,
                    isset($preview['tmdb']) ? $preview['tmdb'] : 0,
                    isset($preview['trakt']) ? 1 : 0,
                    isset($preview['trakt']) ? $preview['trakt'] : 0,
                    isset($preview['tvrage']) ? 1 : 0,
                    isset($preview['tvrage']) ? $preview['tvrage'] : 0,
                    isset($preview['imdb']) ? 1 : 0,
                    $imdb,
                    strlen($imdb),
                    $outIds,
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::normalizeLookupPreviewPayloadPhp($preview);
            }

            return [
                'title' => $preview['title'] ?? null,
                'summary' => $preview['summary'] ?? null,
                'started' => $preview['started'] ?? null,
                'publisher' => $preview['publisher'] ?? null,
                'poster' => $preview['poster'] ?? null,
                'ids' => [
                    'tvdb' => (int) $outIds[0],
                    'tvmaze' => (int) $outIds[1],
                    'tmdb' => (int) $outIds[2],
                    'trakt' => (int) $outIds[3],
                    'imdb' => $rc > 0 ? FFI::string($buf, $rc) : '',
                    'tvrage' => (int) $outIds[4],
                ],
            ];
        } catch (Throwable) {
            return self::normalizeLookupPreviewPayloadPhp($preview);
        }
    }

    /**
     * @return array{key: string, message: string}
     */
    public static function formatCreateFlash(bool $existed, ?string $title): array
    {
        if (self::$useRustFfi) {
            return self::formatCreateFlashNative($existed, $title);
        }

        return self::formatCreateFlashPhp($existed, $title);
    }

    /**
     * @return array{key: string, message: string}
     */
    public static function formatCreateFlashPhp(bool $existed, ?string $title): array
    {
        $titleStr = $title ?? '';

        return [
            'key' => $existed ? 'warning' : 'success',
            'message' => $existed
                ? sprintf('Show "%s" already exists — opened existing record.', $titleStr)
                : sprintf('TV show "%s" added successfully.', $titleStr),
        ];
    }

    /**
     * @return array{key: string, message: string}
     */
    public static function formatCreateFlashNative(bool $existed, ?string $title): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::formatCreateFlashPhp($existed, $title);
        }

        $titleStr = $title ?? '';

        try {
            $lens = $ffi->new('size_t[2]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, strlen($titleStr) + 96), $cap);
            if ($buf === null) {
                return self::formatCreateFlashPhp($existed, $title);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_shows_format_create_flash',
                $existed ? 1 : 0,
                $titleStr,
                strlen($titleStr),
                $lens,
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::formatCreateFlashPhp($existed, $title);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_admin_shows_format_create_flash',
                    $existed ? 1 : 0,
                    $titleStr,
                    strlen($titleStr),
                    $lens,
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::formatCreateFlashPhp($existed, $title);
            }

            $keyLen = (int) $lens[0];
            $msgLen = (int) $lens[1];
            if ($keyLen + $msgLen !== $rc) {
                return self::formatCreateFlashPhp($existed, $title);
            }

            $packed = FFI::string($buf, $rc);

            return [
                'key' => substr($packed, 0, $keyLen),
                'message' => substr($packed, $keyLen, $msgLen),
            ];
        } catch (Throwable) {
            return self::formatCreateFlashPhp($existed, $title);
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
