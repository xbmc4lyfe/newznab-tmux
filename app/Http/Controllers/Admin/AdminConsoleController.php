<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Services\ConsoleService;
use App\Services\GenreService;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

class AdminConsoleController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_console_normalize_edit_fields(
        const char *salesrank_ptr,
        size_t salesrank_len,
        const char *releasedate_ptr,
        size_t releasedate_len,
        uint32_t is_utc,
        int64_t has_cover,
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

    protected ConsoleService $consoleService;

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

    public function __construct(ConsoleService $consoleService, ReleaseImageService $imageService)
    {
        parent::__construct();
        $this->consoleService = $consoleService;
        $this->imageService = $imageService;
    }

    /**
     * Display a listing of console games
     */
    public function index(): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Console List';

        $consoleList = getRange('consoleinfo');

        return view('admin.console.index', compact('consoleList', 'title', 'meta_title'));
    }

    /**
     * Show the form for editing a console game
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $this->setAdminPrefs();
        $gen = new GenreService;
        $meta_title = $title = 'Console Edit';

        // set the current action
        $action = $this->formAction($request);

        if ($request->has('id')) {
            $id = $request->input('id');
            $con = $this->consoleService->getConsoleInfo($id);

            if (! $con) {
                abort(404);
            }

            switch ($action) {
                case 'submit':
                    $coverDirectory = storage_path('covers/console/');

                    if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
                        $this->imageService->saveUploadedImage((string) $id, $request->file('cover'), $coverDirectory);
                    }

                    $normalized = self::normalizeConsoleEditFields(
                        $request->input('salesrank'),
                        $request->input('releasedate'),
                        $con['releasedate'],
                        $this->imageService->imageExists($coverDirectory, (string) $id),
                    );
                    $hasCover = $normalized['has_cover'];
                    $salesrank = $normalized['salesrank'];
                    $releasedate = $normalized['releasedate'];

                    $this->consoleService->update(
                        $id,
                        $request->input('title'),
                        $request->input('asin'),
                        $request->input('url'),
                        $salesrank,
                        $request->input('platform'),
                        $request->input('publisher'),
                        $releasedate,
                        $request->input('esrb'),
                        $hasCover,
                        $request->input('genre')
                    );

                    return redirect()->route('admin.console-list')->with('success', 'Console game updated successfully');

                case 'view':
                default:
                    $genres = $gen->getGenres((string) GenreService::CONSOLE_TYPE);

                    return view('admin.console.edit', compact('con', 'genres', 'title', 'meta_title'));
            }
        }

        return redirect()->route('admin.console-list');
    }

    /**
     * @return array{salesrank: mixed, releasedate: mixed, has_cover: int}
     */
    public static function normalizeConsoleEditFields(
        mixed $salesrankInput,
        mixed $releasedateInput,
        mixed $fallbackReleaseDate,
        bool|int $hasCover = false,
    ): array {
        if (self::$useRustFfi) {
            return self::normalizeConsoleEditFieldsNative(
                $salesrankInput,
                $releasedateInput,
                $fallbackReleaseDate,
                $hasCover,
            );
        }

        return self::normalizeConsoleEditFieldsPhp(
            $salesrankInput,
            $releasedateInput,
            $fallbackReleaseDate,
            $hasCover,
        );
    }

    /**
     * @return array{salesrank: mixed, releasedate: mixed, has_cover: int}
     */
    public static function normalizeConsoleEditFieldsPhp(
        mixed $salesrankInput,
        mixed $releasedateInput,
        mixed $fallbackReleaseDate,
        bool|int $hasCover = false,
    ): array {
        $salesrank = (empty($salesrankInput) || ! is_string($salesrankInput) || ! ctype_digit($salesrankInput))
            ? null
            : $salesrankInput;
        $releasedate = (empty($releasedateInput) || ! is_string($releasedateInput) || ! strtotime($releasedateInput))
            ? $fallbackReleaseDate
            : Carbon::parse($releasedateInput)->timestamp;

        return [
            'salesrank' => $salesrank,
            'releasedate' => $releasedate,
            'has_cover' => ((int) $hasCover) !== 0 ? 1 : 0,
        ];
    }

    /**
     * @return array{salesrank: mixed, releasedate: mixed, has_cover: int}
     */
    public static function normalizeConsoleEditFieldsNative(
        mixed $salesrankInput,
        mixed $releasedateInput,
        mixed $fallbackReleaseDate,
        bool|int $hasCover = false,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeConsoleEditFieldsPhp(
                $salesrankInput,
                $releasedateInput,
                $fallbackReleaseDate,
                $hasCover,
            );
        }

        if (($salesrankInput !== null && ! is_string($salesrankInput))
            || ($releasedateInput !== null && ! is_string($releasedateInput))) {
            return self::normalizeConsoleEditFieldsPhp(
                $salesrankInput,
                $releasedateInput,
                $fallbackReleaseDate,
                $hasCover,
            );
        }

        $salesrankStr = $salesrankInput ?? '';
        $releasedateStr = $releasedateInput ?? '';
        $isUtc = date_default_timezone_get() === 'UTC' ? 1 : 0;

        try {
            $scalars = $ffi->new('int64_t[4]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(32, strlen($salesrankStr) + 8), $cap);
            if ($buf === null) {
                return self::normalizeConsoleEditFieldsPhp(
                    $salesrankInput,
                    $releasedateInput,
                    $fallbackReleaseDate,
                    $hasCover,
                );
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_console_normalize_edit_fields',
                $salesrankStr,
                strlen($salesrankStr),
                $releasedateStr,
                strlen($releasedateStr),
                $isUtc,
                (int) $hasCover,
                $scalars,
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::normalizeConsoleEditFieldsPhp(
                        $salesrankInput,
                        $releasedateInput,
                        $fallbackReleaseDate,
                        $hasCover,
                    );
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_admin_console_normalize_edit_fields',
                    $salesrankStr,
                    strlen($salesrankStr),
                    $releasedateStr,
                    strlen($releasedateStr),
                    $isUtc,
                    (int) $hasCover,
                    $scalars,
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::normalizeConsoleEditFieldsPhp(
                    $salesrankInput,
                    $releasedateInput,
                    $fallbackReleaseDate,
                    $hasCover,
                );
            }

            return [
                'salesrank' => ((int) $scalars[0]) === 1 ? FFI::string($buf, $rc) : null,
                'releasedate' => ((int) $scalars[1]) === 1 ? $fallbackReleaseDate : (int) $scalars[2],
                'has_cover' => (int) $scalars[3],
            ];
        } catch (Throwable) {
            return self::normalizeConsoleEditFieldsPhp(
                $salesrankInput,
                $releasedateInput,
                $fallbackReleaseDate,
                $hasCover,
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
