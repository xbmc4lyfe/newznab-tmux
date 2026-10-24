<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminReleaseListRequest;
use App\Models\Category;
use App\Models\Release;
use App\Services\Releases\ReleaseManagementService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\View\View;
use Throwable;

class AdminReleasesController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_admin_ctl_release_destroy_redirect(
        uint8_t has_referer,
        const char *referer_ptr,
        size_t referer_len,
        uint8_t has_intended
    );
    ssize_t nntmux_admin_ctl_release_bulk_category_message(int64_t count, char *out_buf, size_t out_cap);
    CDEF;

    /** Redirect to the home `All` route (the release was deleted from its details page). */
    public const string REDIRECT_HOME = 'home';

    /** Redirect to the session's `intended_redirect` URL. */
    public const string REDIRECT_INTENDED = 'intended';

    /** Redirect to the `admin.release-list` route. */
    public const string REDIRECT_RELEASE_LIST = 'release-list';

    private const array REDIRECT_CODES = [self::REDIRECT_HOME, self::REDIRECT_INTENDED, self::REDIRECT_RELEASE_LIST];

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminCategoryReleaseContentControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private ReleaseManagementService $releaseManagement;

    public function __construct(ReleaseManagementService $releaseManagement)
    {
        parent::__construct();
        $this->releaseManagement = $releaseManagement;
    }

    /**
     * @throws \Exception
     */
    public function index(AdminReleaseListRequest $request): mixed
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Release List';

        $page = (int) $request->input('page', 1);
        $search = $request->searchTerm();
        $categoryId = $request->categoryId();

        $releaseList = Release::getReleasesRange($page, $search, $categoryId);
        $releaseList->appends($request->only(['search', 'category_id']));

        return view('admin.releases.index', [
            'releaselist' => $releaseList,
            'catlist' => Category::getForSelect(true),
            'title' => $title,
            'meta_title' => $meta_title,
        ]);
    }

    public function bulkCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'guids' => 'required|array|min:1',
            'guids.*' => 'string',
            'categories_id' => 'required|integer|min:1|exists:categories,id',
        ]);

        $count = $this->releaseManagement->bulkUpdateCategory(
            $validated['guids'],
            (int) $validated['categories_id'],
        );

        return back()->with('success', self::bulkCategoryMessage($count));
    }

    /**
     * @return \Illuminate\Contracts\Foundation\Application|Application|RedirectResponse|Redirector|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        // Set the current action.
        $action = $this->formAction($request);

        switch ($action) {
            case 'submit':
                Release::updateRelease(
                    $request->input('id'),
                    $request->input('name'),
                    $request->input('searchname'),
                    $request->input('fromname'),
                    $request->input('category'),
                    $request->input('totalpart'),
                    $request->input('grabs'),
                    $request->input('size'),
                    $request->input('postdate'),
                    $request->input('adddate'),
                    $request->input('videos_id'),
                    $request->input('tv_episodes_id'),
                    $request->input('imdbid'),
                    $request->input('anidbid')
                );

                $release = Release::getByGuid($request->input('guid'));

                return redirect('details/'.$release['guid'])->with('success', 'Release updated successfully');

            case 'view':
            default:
                $id = $request->input('id');
                $release = Release::getByGuid($id);
                break;
        }

        $yesno_ids = [1, 0];
        $yesno_names = ['Yes', 'No'];
        $catlist = Category::getForSelect(false);

        return view('admin.releases.edit', [
            'release' => $release,
            'yesno_ids' => $yesno_ids,
            'yesno_names' => $yesno_names,
            'catlist' => $catlist,
            'title' => 'Release Edit',
            'meta_title' => 'Release Edit',
        ]);
    }

    public function destroy(mixed $id): mixed
    {
        try {
            if ($id) {
                $this->releaseManagement->deleteMultiple($id);
                Release::clearAdminReleasesRangeCache();

                // Handle AJAX requests
                if (request()->wantsJson() || request()->ajax()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Release deleted successfully',
                    ]);
                }

                session()->flash('success', 'Release deleted successfully');
            }

            // Handle AJAX requests
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No release ID provided',
                ], 400);
            }

            // Coming from the NZB details page redirects to the home page; otherwise use the
            // intended URL or the release list.
            $intended = session('intended_redirect');
            $target = self::destroyRedirectTarget(request()->headers->get('referer'), $intended !== null);
            if ($target === self::REDIRECT_HOME) {
                return redirect()->route('All');
            }

            $redirectUrl = $target === self::REDIRECT_INTENDED ? $intended : route('admin.release-list');
            session()->forget('intended_redirect');

            return redirect($redirectUrl);
        } catch (\Exception $e) {
            // Handle AJAX requests
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error deleting release: '.$e->getMessage(),
                ], 500);
            }

            session()->flash('error', 'Error deleting release: '.$e->getMessage());

            return redirect()->route('admin.release-list');
        }
    }

    /**
     * Overrides the base page toggle for this controller's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
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
     * `REDIRECT_*` target after a non-AJAX release delete.
     */
    public static function destroyRedirectTarget(?string $referer, bool $hasIntended): string
    {
        return self::$useRustFfi
            ? self::destroyRedirectTargetNative($referer, $hasIntended)
            : self::destroyRedirectTargetPhp($referer, $hasIntended);
    }

    public static function destroyRedirectTargetPhp(?string $referer, bool $hasIntended): string
    {
        if ($referer && str_contains($referer, '/details/')) {
            return self::REDIRECT_HOME;
        }

        return $hasIntended ? self::REDIRECT_INTENDED : self::REDIRECT_RELEASE_LIST;
    }

    public static function destroyRedirectTargetNative(?string $referer, bool $hasIntended): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::destroyRedirectTargetPhp($referer, $hasIntended);
        }

        try {
            $code = self::callFfi(
                $ffi,
                'nntmux_admin_ctl_release_destroy_redirect',
                $referer === null ? 0 : 1,
                $referer ?? '',
                \strlen($referer ?? ''),
                $hasIntended ? 1 : 0
            );

            return \is_int($code) && isset(self::REDIRECT_CODES[$code])
                ? self::REDIRECT_CODES[$code]
                : self::destroyRedirectTargetPhp($referer, $hasIntended);
        } catch (Throwable) {
            return self::destroyRedirectTargetPhp($referer, $hasIntended);
        }
    }

    public static function bulkCategoryMessage(int $count): string
    {
        return self::$useRustFfi ? self::bulkCategoryMessageNative($count) : self::bulkCategoryMessagePhp($count);
    }

    public static function bulkCategoryMessagePhp(int $count): string
    {
        return $count.' release(s) re-categorised.';
    }

    public static function bulkCategoryMessageNative(int $count): string
    {
        $ffi = self::ffi();
        if ($ffi === null || PHP_INT_SIZE !== 8) {
            return self::bulkCategoryMessagePhp($count);
        }

        return self::callForString($ffi, 'nntmux_admin_ctl_release_bulk_category_message', 64, $count)
            ?? self::bulkCategoryMessagePhp($count);
    }

    /**
     * Calls a byte-output export with a scratch buffer, growing it once when Rust reports a larger requirement.
     */
    private static function callForString(FFI $ffi, string $symbol, int $estimate, mixed ...$args): ?string
    {
        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(1, $estimate), $capacity);
            if ($outBuf === null) {
                return null;
            }
            $written = self::callFfi($ffi, $symbol, ...[...$args, $outBuf, $capacity]);
            if (\is_int($written) && $written < -1 && -$written > $capacity) {
                $outBuf = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($outBuf === null) {
                    return null;
                }
                $written = self::callFfi($ffi, $symbol, ...[...$args, $outBuf, $capacity]);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($outBuf, $written);
        } catch (Throwable) {
            return null;
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

        $ffi = RustPipelineLibrary::resolveDomain('w28-admin-category-release-content-controllers', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
