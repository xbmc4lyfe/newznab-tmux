<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Models\RootCategory;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class AdminCategoryController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_admin_ctl_category_parent_is_set(const char *value_ptr, size_t value_len);
    ssize_t nntmux_admin_ctl_category_delete_message(
        uint8_t kind,
        const char *title_ptr,
        size_t title_len,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    public const int DELETE_MISSING_ID = 0;

    public const int DELETE_NOT_FOUND = 1;

    public const int DELETE_HAS_CHILDREN = 2;

    public const int DELETE_DELETED = 3;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminCategoryReleaseContentControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @throws \Exception
     */
    public function index(): mixed
    {
        $this->setAdminPrefs();
        $meta_title = $title = 'Category List';

        $categorylist = Category::getFlat();

        $this->viewData = array_merge($this->viewData, [
            'categorylist' => $categorylist,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.categories.index', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function create(Request $request)
    {
        $this->setAdminPrefs();

        // set the current action
        $action = $this->formAction($request);

        switch ($action) {
            case 'submit':
                // Create new category
                $category = new Category;

                // Allow custom ID only when creating
                if ($request->filled('id')) {
                    $customId = $request->input('id');
                    // Check if ID already exists
                    if (Category::where('id', $customId)->exists()) {
                        return redirect()->back()->withInput()->with('error', 'Category ID '.$customId.' already exists. Please choose a different ID.');
                    }
                    $category->id = $customId;
                }

                $category->title = $request->input('title');
                $category->forceFill(self::categoryAttributes($request->input('root_categories_id'), $request->input('description')));
                $category->save();

                return redirect()->to('admin/category-list')->with('success', 'Category created successfully');
            case 'view':
            default:
                $title = 'Add New Category';
                break;
        }

        // Get root categories for parent selection
        $rootCategories = RootCategory::orderBy('title')->get();

        $this->viewData = array_merge($this->viewData, [
            'category' => null,
            'rootCategories' => $rootCategories,
            'title' => $title,
            'meta_title' => 'Add New Category',
            'isCreate' => true,
        ]);

        return view('admin.categories.edit', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();

        // set the current action
        $action = $this->formAction($request);

        switch ($action) {
            case 'submit':
                $category = Category::find($request->input('id'));
                if ($category) {
                    $category->forceFill(self::categoryAttributes($request->input('root_categories_id'), $request->input('description')));
                    $category->save();
                }

                return redirect()->to('admin/category-list')->with('success', 'Category updated successfully');
            case 'view':
            default:
                $category = null;
                $title = 'Category Edit';
                if ($request->has('id')) {
                    $id = $request->input('id');
                    $category = Category::find($id);
                }
                break;
        }

        // Get root categories for parent selection
        $rootCategories = RootCategory::orderBy('title')->get();

        $this->viewData = array_merge($this->viewData, [
            'category' => $category,
            'rootCategories' => $rootCategories,
            'title' => $title,
            'meta_title' => 'View/Edit categories',
            'isCreate' => false,
        ]);

        return view('admin.categories.edit', $this->viewData);
    }

    /**
     * Delete a category.
     *
     * @return RedirectResponse
     *
     * @throws \Exception
     */
    public function destroy(Request $request)
    {
        $this->setAdminPrefs();

        $id = $request->input('id');

        if (! $id) {
            return redirect()->to('admin/category-list')->with(...self::deleteFlash(self::DELETE_MISSING_ID));
        }

        $category = Category::find($id);

        if (! $category) {
            return redirect()->to('admin/category-list')->with(...self::deleteFlash(self::DELETE_NOT_FOUND));
        }

        // Check for child categories
        if (Category::where('root_categories_id', $id)->exists()) {
            return redirect()->to('admin/category-list')->with(...self::deleteFlash(self::DELETE_HAS_CHILDREN, $category->title));
        }

        $title = $category->title;
        $category->delete();

        return redirect()->to('admin/category-list')->with(...self::deleteFlash(self::DELETE_DELETED, $title));
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
     * Create/edit attributes: a falsy parent id becomes `null`; status, preview and size limits are fixed.
     *
     * @return array{root_categories_id: mixed, status: int, description: mixed, disablepreview: bool, minsizetoformrelease: int, maxsizetoformrelease: int}
     */
    public static function categoryAttributes(mixed $rootCategoriesId, mixed $description): array
    {
        return self::$useRustFfi
            ? self::categoryAttributesNative($rootCategoriesId, $description)
            : self::categoryAttributesPhp($rootCategoriesId, $description);
    }

    /**
     * @return array{root_categories_id: mixed, status: int, description: mixed, disablepreview: bool, minsizetoformrelease: int, maxsizetoformrelease: int}
     */
    public static function categoryAttributesPhp(mixed $rootCategoriesId, mixed $description): array
    {
        return [
            'root_categories_id' => $rootCategoriesId ?: null,
            'status' => Category::STATUS_ACTIVE,  // Always active
            'description' => $description,
            'disablepreview' => false,  // Always enabled
            'minsizetoformrelease' => 0,
            'maxsizetoformrelease' => 0,
        ];
    }

    /**
     * @return array{root_categories_id: mixed, status: int, description: mixed, disablepreview: bool, minsizetoformrelease: int, maxsizetoformrelease: int}
     */
    public static function categoryAttributesNative(mixed $rootCategoriesId, mixed $description): array
    {
        $ffi = self::ffi();
        if ($ffi === null || ! \is_string($rootCategoriesId)) {
            return self::categoryAttributesPhp($rootCategoriesId, $description);
        }

        try {
            $isSet = self::callFfi($ffi, 'nntmux_admin_ctl_category_parent_is_set', $rootCategoriesId, \strlen($rootCategoriesId));
            if ($isSet !== 0 && $isSet !== 1) {
                return self::categoryAttributesPhp($rootCategoriesId, $description);
            }

            return [
                'root_categories_id' => $isSet === 1 ? $rootCategoriesId : null,
                'status' => Category::STATUS_ACTIVE,
                'description' => $description,
                'disablepreview' => false,
                'minsizetoformrelease' => 0,
                'maxsizetoformrelease' => 0,
            ];
        } catch (Throwable) {
            return self::categoryAttributesPhp($rootCategoriesId, $description);
        }
    }

    /**
     * Flash key and message for a `DELETE_*` outcome of `destroy()`.
     *
     * @return array{0: 'error'|'success', 1: string}
     */
    public static function deleteFlash(int $outcome, ?string $title = null): array
    {
        return self::$useRustFfi ? self::deleteFlashNative($outcome, $title) : self::deleteFlashPhp($outcome, $title);
    }

    /**
     * @return array{0: 'error'|'success', 1: string}
     */
    public static function deleteFlashPhp(int $outcome, ?string $title = null): array
    {
        return match ($outcome) {
            self::DELETE_MISSING_ID => ['error', 'No category ID provided.'],
            self::DELETE_NOT_FOUND => ['error', 'Category not found.'],
            self::DELETE_HAS_CHILDREN => ['error', 'Cannot delete category "'.$title.'" because it has child categories. Remove or reassign them first.'],
            self::DELETE_DELETED => ['success', 'Category "'.$title.'" deleted successfully.'],
            default => throw new InvalidArgumentException('Unknown category delete outcome '.$outcome.'.'),
        };
    }

    /**
     * @return array{0: 'error'|'success', 1: string}
     */
    public static function deleteFlashNative(int $outcome, ?string $title = null): array
    {
        $ffi = self::ffi();
        if ($ffi === null || $outcome < self::DELETE_MISSING_ID || $outcome > self::DELETE_DELETED) {
            return self::deleteFlashPhp($outcome, $title);
        }

        $text = $title ?? '';
        $message = self::callForString($ffi, 'nntmux_admin_ctl_category_delete_message', \strlen($text) + 96, $outcome, $text, \strlen($text));

        return $message === null
            ? self::deleteFlashPhp($outcome, $title)
            : [$outcome === self::DELETE_DELETED ? 'success' : 'error', $message];
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
