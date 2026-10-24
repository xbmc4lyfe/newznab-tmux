<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use TeamTeaTime\Forum\Models\Post;

class PostPolicy extends \TeamTeaTime\Forum\Policies\PostPolicy
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_post_policy_can_modify(
    uint8_t is_user_instance,
    uint8_t is_admin,
    uint8_t user_kind,
    int64_t user_int,
    const char *user_ptr,
    size_t user_len,
    uint8_t author_kind,
    int64_t author_int,
    const char *author_ptr,
    size_t author_len
);
CDEF;

    private const ID_KIND_NULL = 0;

    private const ID_KIND_INT = 1;

    private const ID_KIND_STRING = 2;

    /**
     * Production default stays PHP: paired benchmarks in RustForumPolicyPostParityTest show
     * per-call FFI marshaling outweighs this ownership check (< 1.5x required speedup).
     */
    private static bool $useRustFfi = false;

    private static \FFI|false|null $ffi = null;

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

    public function edit(mixed $user, Post $post): bool
    {
        // Admins can edit any post; users can edit their own
        return $this->canModify($user, $post);
    }

    public function delete(mixed $user, Post $post): bool
    {
        // Admins can delete any post; users can delete their own
        return $this->canModify($user, $post);
    }

    public function restore(mixed $user, Post $post): bool
    {
        // Admins can restore any post; users can restore their own
        return $this->canModify($user, $post);
    }

    /**
     * Admins may modify any post; other users only posts whose author id is strictly
     * identical (`===`) to their key.
     */
    public static function canModifyPost(bool $isUserInstance, bool $isAdmin, int|string|null $userId, int|string|null $authorId): bool
    {
        if (self::$useRustFfi) {
            return self::canModifyPostNative($isUserInstance, $isAdmin, $userId, $authorId);
        }

        return self::canModifyPostPhp($isUserInstance, $isAdmin, $userId, $authorId);
    }

    public static function canModifyPostPhp(bool $isUserInstance, bool $isAdmin, int|string|null $userId, int|string|null $authorId): bool
    {
        return $isAdmin || ($isUserInstance && $userId === $authorId);
    }

    public static function canModifyPostNative(bool $isUserInstance, bool $isAdmin, int|string|null $userId, int|string|null $authorId): bool
    {
        $ffi = self::categorizeFfi();
        if (! $ffi instanceof \FFI) {
            return self::canModifyPostPhp($isUserInstance, $isAdmin, $userId, $authorId);
        }

        [$userKind, $userInt, $userStr] = self::encodeId($userId);
        [$authorKind, $authorInt, $authorStr] = self::encodeId($authorId);

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_post_policy_can_modify(
                $isUserInstance ? 1 : 0,
                $isAdmin ? 1 : 0,
                $userKind,
                $userInt,
                $userStr,
                strlen($userStr),
                $authorKind,
                $authorInt,
                $authorStr,
                strlen($authorStr)
            );
        } catch (\Throwable) {
            return self::canModifyPostPhp($isUserInstance, $isAdmin, $userId, $authorId);
        }

        return match ($rc) {
            1 => true,
            0 => false,
            default => self::canModifyPostPhp($isUserInstance, $isAdmin, $userId, $authorId),
        };
    }

    private function canModify(mixed $user, Post $post): bool
    {
        $isUserInstance = $user instanceof User;
        $isAdmin = $this->isAdmin($user);
        $userId = $isUserInstance ? $user->getKey() : null;
        $authorId = $post->author_id;

        if (! self::isIdentifier($userId) || ! self::isIdentifier($authorId)) {
            // Keys outside int|string|null keep the original strict comparison.
            return $isAdmin || ($isUserInstance && $userId === $authorId);
        }

        return self::canModifyPost($isUserInstance, $isAdmin, $userId, $authorId);
    }

    private function isAdmin(mixed $user): bool
    {
        return $user instanceof User && $user->hasRole('Admin');
    }

    /**
     * @phpstan-assert-if-true int|string|null $value
     */
    private static function isIdentifier(mixed $value): bool
    {
        return $value === null || is_int($value) || is_string($value);
    }

    /**
     * @return array{int, int, string}
     */
    private static function encodeId(int|string|null $id): array
    {
        if ($id === null) {
            return [self::ID_KIND_NULL, 0, ''];
        }
        if (is_int($id)) {
            return [self::ID_KIND_INT, $id, ''];
        }

        return [self::ID_KIND_STRING, 0, $id];
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
