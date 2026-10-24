<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use TeamTeaTime\Forum\Models\Category;

class CategoryPolicy extends \TeamTeaTime\Forum\Policies\CategoryPolicy
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_category_policy_evaluate_permission(
    const char *action_ptr,
    size_t action_len,
    uint8_t is_user_instance,
    uint8_t is_admin
);
CDEF;

    /**
     * Production default stays PHP: paired benchmarks in RustForumPolicyPostParityTest show
     * per-call FFI marshaling outweighs this match expression (< 1.5x required speedup).
     */
    private static bool $useRustFfi = false;

    private static \FFI|false|null $ffi = null;

    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
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

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(\FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function manageThreads(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('manageThreads', $user);
    }

    public function deleteThreads(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('deleteThreads', $user);
    }

    public function restoreThreads(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('restoreThreads', $user);
    }

    public function enableThreads(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('enableThreads', $user);
    }

    public function moveThreadsFrom(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('moveThreadsFrom', $user);
    }

    public function moveThreadsTo(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('moveThreadsTo', $user);
    }

    public function lockThreads(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('lockThreads', $user);
    }

    public function pinThreads(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('pinThreads', $user);
    }

    public function view(mixed $user, Category $category): bool
    {
        // Forum v8 applies category view checks when filtering private category trees.
        // Allow any authenticated forum user to see categories while keeping category
        // management actions restricted to admins. The role lookup is not needed here.
        return self::evaluateCategoryPermission('view', $user instanceof User, false);
    }

    public function delete(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('delete', $user);
    }

    public function edit(mixed $user, Category $category): bool
    {
        return $this->authorizeAdminAction('edit', $user);
    }

    /**
     * Evaluate a category policy action: `view` requires an authenticated user, management
     * actions require an admin, and unknown actions are denied.
     */
    public static function evaluateCategoryPermission(string $action, bool $isUserInstance, bool $isAdmin): bool
    {
        if (self::$useRustFfi) {
            return self::evaluateCategoryPermissionNative($action, $isUserInstance, $isAdmin);
        }

        return self::evaluateCategoryPermissionPhp($action, $isUserInstance, $isAdmin);
    }

    public static function evaluateCategoryPermissionPhp(string $action, bool $isUserInstance, bool $isAdmin): bool
    {
        return match ($action) {
            'view' => $isUserInstance,
            'manageThreads',
            'deleteThreads',
            'restoreThreads',
            'enableThreads',
            'moveThreadsFrom',
            'moveThreadsTo',
            'lockThreads',
            'pinThreads',
            'delete',
            'edit' => $isAdmin,
            default => false,
        };
    }

    public static function evaluateCategoryPermissionNative(string $action, bool $isUserInstance, bool $isAdmin): bool
    {
        $ffi = self::categorizeFfi();
        if (! $ffi instanceof \FFI) {
            return self::evaluateCategoryPermissionPhp($action, $isUserInstance, $isAdmin);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_category_policy_evaluate_permission(
                $action,
                strlen($action),
                $isUserInstance ? 1 : 0,
                $isAdmin ? 1 : 0
            );
        } catch (\Throwable) {
            return self::evaluateCategoryPermissionPhp($action, $isUserInstance, $isAdmin);
        }

        return match ($rc) {
            1 => true,
            0 => false,
            default => self::evaluateCategoryPermissionPhp($action, $isUserInstance, $isAdmin),
        };
    }

    private function authorizeAdminAction(string $action, mixed $user): bool
    {
        return self::evaluateCategoryPermission($action, $user instanceof User, $this->isAdmin($user));
    }

    private function isAdmin(mixed $user): bool
    {
        return $user instanceof User && $user->hasRole('Admin');
    }

    private static function categorizeFfi(): ?\FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof \FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
