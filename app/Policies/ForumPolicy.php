<?php

declare(strict_types=1);

namespace App\Policies;

use App\Support\RustPipelineLibrary;

class ForumPolicy extends \TeamTeaTime\Forum\Policies\ForumPolicy
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_forum_policy_evaluate_admin_capability(
    uint8_t has_user,
    uint8_t has_admin_role
);
CDEF;

    /**
     * Production default stays PHP: paired benchmarks in RustForumPolicyPostParityTest show
     * per-call FFI overhead outweighs this boolean check (< 1.5x required speedup).
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

    public function createCategories(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function manageCategories(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function moveCategories(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function renameCategories(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function viewTrashedThreads(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function viewTrashedPosts(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function deleteCategories(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function deletePosts(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function deleteThreads(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function restorePosts(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function restoreThreads(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function purgePosts(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function purgeThreads(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    public function deleteThrashedPosts(mixed $user): bool
    {
        return $this->isForumAdmin($user);
    }

    /**
     * Forum-level capabilities require a user that holds the Admin role.
     */
    public static function evaluateForumAdminCapability(bool $hasUser, bool $hasAdminRole): bool
    {
        if (self::$useRustFfi) {
            return self::evaluateForumAdminCapabilityNative($hasUser, $hasAdminRole);
        }

        return self::evaluateForumAdminCapabilityPhp($hasUser, $hasAdminRole);
    }

    public static function evaluateForumAdminCapabilityPhp(bool $hasUser, bool $hasAdminRole): bool
    {
        return $hasUser && $hasAdminRole;
    }

    public static function evaluateForumAdminCapabilityNative(bool $hasUser, bool $hasAdminRole): bool
    {
        $ffi = self::categorizeFfi();
        if (! $ffi instanceof \FFI) {
            return self::evaluateForumAdminCapabilityPhp($hasUser, $hasAdminRole);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_forum_policy_evaluate_admin_capability($hasUser ? 1 : 0, $hasAdminRole ? 1 : 0);
        } catch (\Throwable) {
            return self::evaluateForumAdminCapabilityPhp($hasUser, $hasAdminRole);
        }

        return match ($rc) {
            1 => true,
            0 => false,
            default => self::evaluateForumAdminCapabilityPhp($hasUser, $hasAdminRole),
        };
    }

    private function isForumAdmin(mixed $user): bool
    {
        return self::evaluateForumAdminCapability($user !== null, (bool) ($user?->hasRole('Admin') ?? false));
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
