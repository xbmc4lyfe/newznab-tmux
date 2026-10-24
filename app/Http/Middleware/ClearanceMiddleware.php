<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\RootCategory;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ClearanceMiddleware
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_clearance_matches_category_path(const char *path, size_t path_len, const char *category, size_t category_len);
        ssize_t nntmux_clearance_resolve_main_category(const char *parent_category, size_t parent_category_len, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Handle an incoming request.
     *
     *
     * @throws \Exception
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Admin/Moderator bypass only applies to Admin section pages
        // They should still respect their personal category permission settings
        if ($request->is(['Admin', 'Admin/*'])) {
            if (! $user->hasAnyRole(['Admin', 'Moderator'])) {
                abort(403, 'Unauthorized access to admin area');
            }

            return $next($request);
        }

        // Get the path for pattern matching
        $path = $request->path();

        // Check for browse routes with subcategory - e.g., browse/Movies/HD
        if (preg_match('#^browse/([^/]+)/([^/]+)$#i', $path, $matches)) {
            $parentCategoryName = $matches[1];
            $subcategoryName = $matches[2];

            // First check if the main category is blocked
            $blockedCategory = $this->checkMainCategoryPermission($user, $parentCategoryName);
            if ($blockedCategory) {
                return $this->abortCategoryDisabled($blockedCategory);
            }

            // Then check if the subcategory is excluded (unless it's "All")
            if (strtolower($subcategoryName) !== 'all') {
                $blockedSubcategory = $this->checkSubcategoryExclusion($user, $parentCategoryName, $subcategoryName);
                if ($blockedSubcategory) {
                    return $this->abortSubcategoryDisabled($parentCategoryName, $blockedSubcategory);
                }
            }

            return $next($request);
        }

        // Check for browse routes without subcategory - e.g., browse/Movies
        if (preg_match('#^browse/([^/]+)$#i', $path, $matches)) {
            $parentCategoryName = $matches[1];

            $blockedCategory = $this->checkMainCategoryPermission($user, $parentCategoryName);
            if ($blockedCategory) {
                return $this->abortCategoryDisabled($blockedCategory);
            }

            return $next($request);
        }

        // Movies category
        if ($this->matchesCategoryPath($path, 'Movies')
            || $this->matchesCategoryPath($path, 'movie')
            || $this->matchesCategoryPath($path, 'trending-movies')
            || $this->matchesCategoryPath($path, 'movietrailers')
            || $this->matchesCategoryPath($path, 'mymovies')) {
            if (! $user->hasDirectPermission('view movies')) {
                return $this->abortCategoryDisabled('Movies');
            }

            return $next($request);
        }

        // Console category
        if ($this->matchesCategoryPath($path, 'Console')) {
            if (! $user->hasDirectPermission('view console')) {
                return $this->abortCategoryDisabled('Console');
            }

            return $next($request);
        }

        // Books category
        if ($this->matchesCategoryPath($path, 'Books')) {
            if (! $user->hasDirectPermission('view books')) {
                return $this->abortCategoryDisabled('Books');
            }

            return $next($request);
        }

        // Audio category
        if ($this->matchesCategoryPath($path, 'Audio')) {
            if (! $user->hasDirectPermission('view audio')) {
                return $this->abortCategoryDisabled('Audio');
            }

            return $next($request);
        }

        // Adult (XXX) category
        if ($this->matchesCategoryPath($path, 'XXX')) {
            if (! $user->hasDirectPermission('view adult')) {
                return $this->abortCategoryDisabled('Adult');
            }

            return $next($request);
        }

        // PC/Games category
        if ($this->matchesCategoryPath($path, 'Games') || $this->matchesCategoryPath($path, 'PC')) {
            if (! $user->hasDirectPermission('view pc')) {
                return $this->abortCategoryDisabled('PC');
            }

            return $next($request);
        }

        // TV category
        if ($this->matchesCategoryPath($path, 'TV')
            || $this->matchesCategoryPath($path, 'series')
            || $this->matchesCategoryPath($path, 'trending-tv')
            || $this->matchesCategoryPath($path, 'myshows')) {
            if (! $user->hasDirectPermission('view tv')) {
                return $this->abortCategoryDisabled('TV');
            }

            return $next($request);
        }

        return $next($request);
    }

    /**
     * Check if the user has permission to view a main category.
     *
     * @return string|null The blocked category name, or null if allowed
     */
    public function checkMainCategoryPermission(mixed $user, string $parentCategoryName): ?string
    {
        return self::$useRustFfi
            ? $this->checkMainCategoryPermissionNative($user, $parentCategoryName)
            : $this->checkMainCategoryPermissionPhp($user, $parentCategoryName);
    }

    /**
     * @return string|null The blocked category name, or null if allowed
     */
    public function checkMainCategoryPermissionPhp(mixed $user, string $parentCategoryName): ?string
    {
        $categoryPermissions = [
            'movies' => 'view movies',
            'console' => 'view console',
            'books' => 'view books',
            'audio' => 'view audio',
            'xxx' => 'view adult',
            'games' => 'view pc',
            'pc' => 'view pc',
            'tv' => 'view tv',
        ];

        $categoryDisplayNames = [
            'movies' => 'Movies',
            'console' => 'Console',
            'books' => 'Books',
            'audio' => 'Audio',
            'xxx' => 'Adult',
            'games' => 'PC',
            'pc' => 'PC',
            'tv' => 'TV',
        ];

        $lowerName = strtolower($parentCategoryName);
        if (isset($categoryPermissions[$lowerName])) {
            if (! $user->hasDirectPermission($categoryPermissions[$lowerName])) {
                return $categoryDisplayNames[$lowerName];
            }
        }

        return null;
    }

    /**
     * @return string|null The blocked category name, or null if allowed
     */
    public function checkMainCategoryPermissionNative(mixed $user, string $parentCategoryName): ?string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->checkMainCategoryPermissionPhp($user, $parentCategoryName);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
        if ($scratch === null) {
            return $this->checkMainCategoryPermissionPhp($user, $parentCategoryName);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_clearance_resolve_main_category(
            $parentCategoryName,
            \strlen($parentCategoryName),
            $scratch,
            $cap,
        );
        if (! \is_int($written) || $written < 0) {
            return $this->checkMainCategoryPermissionPhp($user, $parentCategoryName);
        }
        if ($written === 0) {
            return null;
        }

        $pair = explode("\0", FFI::string($scratch, $written), 2);
        if (\count($pair) !== 2) {
            return $this->checkMainCategoryPermissionPhp($user, $parentCategoryName);
        }

        if (! $user->hasDirectPermission($pair[0])) {
            return $pair[1];
        }

        return null;
    }

    /**
     * Check if the user has excluded a specific subcategory.
     *
     * @return string|null The blocked subcategory name, or null if allowed
     */
    protected function checkSubcategoryExclusion(mixed $user, string $parentCategoryName, string $subcategoryName): ?string
    {
        // Get the root category ID
        $rootCategory = RootCategory::query()
            ->whereRaw('LOWER(title) = ?', [strtolower($parentCategoryName)])
            ->first();

        if (! $rootCategory) {
            return null;
        }

        // Get the subcategory
        $subcategory = Category::query()
            ->where('root_categories_id', $rootCategory->id)
            ->whereRaw('LOWER(title) = ?', [strtolower($subcategoryName)])
            ->first();

        if (! $subcategory) {
            return null;
        }

        // Check if this subcategory is in the user's exclusion list
        $isExcluded = $user->excludedCategories()
            ->where('categories_id', $subcategory->id)
            ->exists();

        if ($isExcluded) {
            return $subcategory->title;
        }

        return null;
    }

    /**
     * Check if the path matches a category pattern (case-insensitive).
     */
    public function matchesCategoryPath(string $path, string $category): bool
    {
        return self::$useRustFfi
            ? $this->matchesCategoryPathNative($path, $category)
            : $this->matchesCategoryPathPhp($path, $category);
    }

    public function matchesCategoryPathPhp(string $path, string $category): bool
    {
        $lowerPath = strtolower($path);
        $lowerCategory = strtolower($category);

        // Match: Category, Category/*, browse/Category, browse/Category/*
        return $lowerPath === $lowerCategory
            || str_starts_with($lowerPath, $lowerCategory.'/')
            || $lowerPath === 'browse/'.$lowerCategory
            || str_starts_with($lowerPath, 'browse/'.$lowerCategory.'/');
    }

    public function matchesCategoryPathNative(string $path, string $category): bool
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->matchesCategoryPathPhp($path, $category);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_clearance_matches_category_path(
            $path,
            \strlen($path),
            $category,
            \strlen($category),
        );
        if (! \is_int($res) || $res < 0) {
            return $this->matchesCategoryPathPhp($path, $category);
        }

        return $res === 1;
    }

    /**
     * Abort with a category disabled response.
     */
    protected function abortCategoryDisabled(string $category): Response
    {
        return response()->view('errors.category-disabled', [
            'category' => $category,
        ], 403);
    }

    /**
     * Abort with a subcategory disabled response.
     */
    protected function abortSubcategoryDisabled(string $parentCategory, string $subcategory): Response
    {
        return response()->view('errors.category-disabled', [
            'category' => $parentCategory.' - '.$subcategory,
            'isSubcategory' => true,
        ], 403);
    }
}
