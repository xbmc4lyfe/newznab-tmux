<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\BookInfo;
use App\Services\BookService;
use App\Services\ReleaseImageService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

class AdminBookController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_admin_book_normalize_edit_fields(
        const char *publishdate_ptr,
        size_t publishdate_len,
        uint32_t is_utc,
        int64_t has_cover,
        int64_t *out_scalars
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminAnidbBookMediaCommandsParityTest.php.
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
     * Display a listing of books
     */
    public function index(): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Book List';

        $bookList = BookInfo::query()->orderByDesc('created_at')->paginate(config('nntmux.items_per_page'));

        return view('admin.books.index', compact('bookList', 'title', 'meta_title'));
    }

    /**
     * Show the form for editing a book
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $this->setAdminPrefs();
        $bookService = new BookService;

        $meta_title = $title = 'Book Edit';

        // set the current action
        $action = $this->formAction($request);

        if ($request->has('id')) {
            $id = $request->input('id');
            $b = $bookService->getBookInfo($id);

            if (! $b) {
                abort(404);
            }

            switch ($action) {
                case 'submit':
                    $coverDirectory = storage_path('covers/book/');
                    $imageService = app(ReleaseImageService::class);

                    if ($request->hasFile('cover') && $request->file('cover')->isValid()) {
                        $imageService->saveUploadedImage((string) $id, $request->file('cover'), $coverDirectory);
                    }

                    $normalized = self::normalizeBookEditFields(
                        $request->input('publishdate'),
                        $b['publishdate'] ?? null,
                        $imageService->imageExists($coverDirectory, (string) $id),
                    );
                    $hasCover = $normalized['has_cover'];
                    $publishdate = $normalized['publishdate'];

                    $bookService->update(
                        $id,
                        $request->input('title'),
                        $request->input('asin'),
                        $request->input('url'),
                        $request->input('author'),
                        $request->input('publisher'),
                        $publishdate,
                        $hasCover
                    );

                    return redirect()->route('admin.book-list')->with('success', 'Book updated successfully');
                case 'view':
                default:
                    return view('admin.books.edit', compact('title', 'meta_title'))->with('book', $b);
            }
        }

        return redirect()->route('admin.book-list');
    }

    /**
     * @return array{publishdate: mixed, has_cover: int}
     */
    public static function normalizeBookEditFields(
        mixed $publishdateInput,
        mixed $fallbackPublishDate,
        bool|int $hasCover = false,
    ): array {
        if (self::$useRustFfi) {
            return self::normalizeBookEditFieldsNative(
                $publishdateInput,
                $fallbackPublishDate,
                $hasCover,
            );
        }

        return self::normalizeBookEditFieldsPhp(
            $publishdateInput,
            $fallbackPublishDate,
            $hasCover,
        );
    }

    /**
     * @return array{publishdate: mixed, has_cover: int}
     */
    public static function normalizeBookEditFieldsPhp(
        mixed $publishdateInput,
        mixed $fallbackPublishDate,
        bool|int $hasCover = false,
    ): array {
        $publishdate = (empty($publishdateInput) || ! is_string($publishdateInput) || ! strtotime($publishdateInput))
            ? $fallbackPublishDate
            : Carbon::parse($publishdateInput)->timestamp;

        return [
            'publishdate' => $publishdate,
            'has_cover' => ((int) $hasCover) !== 0 ? 1 : 0,
        ];
    }

    /**
     * @return array{publishdate: mixed, has_cover: int}
     */
    public static function normalizeBookEditFieldsNative(
        mixed $publishdateInput,
        mixed $fallbackPublishDate,
        bool|int $hasCover = false,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeBookEditFieldsPhp(
                $publishdateInput,
                $fallbackPublishDate,
                $hasCover,
            );
        }

        if ($publishdateInput !== null && ! is_string($publishdateInput)) {
            return self::normalizeBookEditFieldsPhp(
                $publishdateInput,
                $fallbackPublishDate,
                $hasCover,
            );
        }

        $publishdateStr = $publishdateInput ?? '';
        $isUtc = date_default_timezone_get() === 'UTC' ? 1 : 0;

        try {
            $scalars = $ffi->new('int64_t[3]');
            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_admin_book_normalize_edit_fields',
                $publishdateStr,
                strlen($publishdateStr),
                $isUtc,
                (int) $hasCover,
                $scalars
            );
            if ($rc !== 0) {
                return self::normalizeBookEditFieldsPhp(
                    $publishdateInput,
                    $fallbackPublishDate,
                    $hasCover,
                );
            }

            return [
                'publishdate' => ((int) $scalars[0]) === 1 ? $fallbackPublishDate : (int) $scalars[1],
                'has_cover' => (int) $scalars[2],
            ];
        } catch (Throwable) {
            return self::normalizeBookEditFieldsPhp(
                $publishdateInput,
                $fallbackPublishDate,
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
