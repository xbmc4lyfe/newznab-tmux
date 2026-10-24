<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Forumpost.
 *
 * @property int $id
 * @property int $forumid
 * @property int $parentid
 * @property int $users_id
 * @property string $subject
 * @property string $message
 * @property bool $locked
 * @property bool $sticky
 * @property int $replies
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereForumid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereLocked($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereParentid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereReplies($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereSticky($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost whereUsersId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Forumpost query()
 */
class Forumpost extends Model
{
    /**
     * @var string
     */
    protected $table = 'forumpost';

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_forumpost_build_post_attributes(
    int64_t parent_id,
    int64_t user_id,
    const char *message_ptr,
    size_t message_len,
    int64_t locked,
    int64_t sticky,
    int64_t replies,
    int64_t *out_fields,
    size_t out_cap
);
int32_t nntmux_forumpost_is_root_topic(int64_t parent_id);
CDEF;

    /**
     * `forumid`, `parentid`, `users_id`, `locked`, `sticky`, `replies`.
     */
    private const POST_ATTRIBUTE_FIELDS = 6;

    /**
     * Production default stays PHP: paired benchmarks in RustForumPolicyPostParityTest show
     * per-call FFI marshaling outweighs this validation/routing (< 1.5x required speedup).
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

    public static function add(mixed $parentId, mixed $userid, mixed $subject, mixed $message, int $locked = 0, int $sticky = 0, int $replies = 0): int
    {
        if (is_int($parentId) && is_int($userid) && is_string($subject) && is_string($message)) {
            $attributes = self::validateAndBuildPostAttributes($parentId, $userid, $subject, $message, $locked, $sticky, $replies);
            $isReply = ! self::isRootTopicPost($parentId);
        } else {
            // Untyped callers keep the original strict checks and raw payload values.
            $attributes = $message === '' ? null : [
                'forumid' => 1,
                'parentid' => $parentId,
                'users_id' => $userid,
                'subject' => $subject,
                'message' => $message,
                'locked' => $locked,
                'sticky' => $sticky,
                'replies' => $replies,
            ];
            $isReply = $parentId !== 0;
        }

        if ($attributes === null) {
            return -1;
        }

        if ($isReply) {
            $par = self::getParent($parentId);
            if ($par === false) {
                return -1;
            }

            self::query()->where('id', $parentId)->increment('replies', 1, ['updated_at' => now()]);
        }

        return self::create($attributes)->id;
    }

    /**
     * Validate a new post and build its insert payload; returns null when the message is empty.
     *
     * @return array{forumid: int, parentid: int, users_id: int, subject: string, message: string, locked: int, sticky: int, replies: int}|null
     */
    public static function validateAndBuildPostAttributes(int $parentId, int $userId, string $subject, string $message, int $locked, int $sticky, int $replies): ?array
    {
        if (self::$useRustFfi) {
            return self::validateAndBuildPostAttributesNative($parentId, $userId, $subject, $message, $locked, $sticky, $replies);
        }

        return self::validateAndBuildPostAttributesPhp($parentId, $userId, $subject, $message, $locked, $sticky, $replies);
    }

    /**
     * @return array{forumid: int, parentid: int, users_id: int, subject: string, message: string, locked: int, sticky: int, replies: int}|null
     */
    public static function validateAndBuildPostAttributesPhp(int $parentId, int $userId, string $subject, string $message, int $locked, int $sticky, int $replies): ?array
    {
        if ($message === '') {
            return null;
        }

        return [
            'forumid' => 1,
            'parentid' => $parentId,
            'users_id' => $userId,
            'subject' => $subject,
            'message' => $message,
            'locked' => $locked,
            'sticky' => $sticky,
            'replies' => $replies,
        ];
    }

    /**
     * @return array{forumid: int, parentid: int, users_id: int, subject: string, message: string, locked: int, sticky: int, replies: int}|null
     */
    public static function validateAndBuildPostAttributesNative(int $parentId, int $userId, string $subject, string $message, int $locked, int $sticky, int $replies): ?array
    {
        $ffi = self::forumpostFfi();
        if (! $ffi instanceof \FFI) {
            return self::validateAndBuildPostAttributesPhp($parentId, $userId, $subject, $message, $locked, $sticky, $replies);
        }

        try {
            $fields = $ffi->new('int64_t['.self::POST_ATTRIBUTE_FIELDS.']');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_forumpost_build_post_attributes(
                $parentId,
                $userId,
                $message,
                strlen($message),
                $locked,
                $sticky,
                $replies,
                $fields,
                self::POST_ATTRIBUTE_FIELDS
            );
            if ($rc === 0) {
                return null;
            }
            if ($rc !== 1) {
                return self::validateAndBuildPostAttributesPhp($parentId, $userId, $subject, $message, $locked, $sticky, $replies);
            }

            return [
                'forumid' => (int) $fields[0],
                'parentid' => (int) $fields[1],
                'users_id' => (int) $fields[2],
                'subject' => $subject,
                'message' => $message,
                'locked' => (int) $fields[3],
                'sticky' => (int) $fields[4],
                'replies' => (int) $fields[5],
            ];
        } catch (\Throwable) {
            return self::validateAndBuildPostAttributesPhp($parentId, $userId, $subject, $message, $locked, $sticky, $replies);
        }
    }

    /**
     * Root topics have no parent; deleting one removes the whole topic with its replies.
     */
    public static function isRootTopicPost(int $parentId): bool
    {
        if (self::$useRustFfi) {
            return self::isRootTopicPostNative($parentId);
        }

        return self::isRootTopicPostPhp($parentId);
    }

    public static function isRootTopicPostPhp(int $parentId): bool
    {
        return $parentId === 0;
    }

    public static function isRootTopicPostNative(int $parentId): bool
    {
        $ffi = self::forumpostFfi();
        if (! $ffi instanceof \FFI) {
            return self::isRootTopicPostPhp($parentId);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_forumpost_is_root_topic($parentId);
        } catch (\Throwable) {
            return self::isRootTopicPostPhp($parentId);
        }

        return match ($rc) {
            1 => true,
            0 => false,
            default => self::isRootTopicPostPhp($parentId),
        };
    }

    /**
     * Get parent of the forum post.
     *
     *
     * @return Model|null|static
     */
    public static function getParent(mixed $parent)
    {
        return self::query()
            ->where('forumpost.id', $parent)
            ->select(['forumpost.*', 'users.username'])
            ->leftJoin('users', 'users.id', '=', 'forumpost.users_id')
            ->first();
    }

    /**
     * Get forum posts for a parent category.
     */
    public static function getPosts(mixed $parent): mixed
    {
        return self::query()
            ->where('forumpost.id', $parent)
            ->orWhere('forumpost.parentid', $parent)
            ->leftJoin('users', 'users.id', '=', 'forumpost.users_id')
            ->leftJoin('roles', 'roles.id', '=', 'users.roles_id')
            ->orderBy('forumpost.created_at')
            ->limit(250)
            ->select(['forumpost.*', 'users.username', 'roles.name as rolename'])
            ->get();
    }

    /**
     * Get post from forum.
     *
     *
     * @return Model|null|static
     */
    public static function getPost(mixed $id)
    {
        return self::query()->where('id', $id)->first();
    }

    /**
     * Get browse range for forum.
     *
     *
     * @param  $start
     */
    public static function getBrowseRange(): LengthAwarePaginator // @phpstan-ignore missingType.generics
    {
        return self::query()
            ->where('forumpost.parentid', '=', 0)
            ->leftJoin('users', 'users.id', '=', 'forumpost.users_id')
            ->leftJoin('roles', 'roles.id', '=', 'users.roles_id')
            ->select(['forumpost.*', 'users.username', 'roles.name as rolename'])
            ->orderByDesc('forumpost.updated_at')
            ->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Delete parent category from forum.
     */
    public static function deleteParent(mixed $parent): void
    {
        self::query()->where('id', $parent)->orWhere('parentid', $parent)->delete();
    }

    /**
     * Delete post from forum.
     */
    public static function deletePost(mixed $id): void
    {
        $post = self::getPost($id);
        if ($post) {
            if (self::isRootTopicPost((int) $post['parentid'])) {
                self::deleteParent($id);
            } else {
                self::query()->where('id', $id)->delete();
            }
        }
    }

    /**
     * Delete user from forum.
     */
    public static function deleteUser(mixed $id): void
    {
        self::query()->where('users_id', $id)->delete();
    }

    public static function getCountForUser(mixed $uid): int
    {
        $res = self::query()->where('users_id', $uid)->count('id');

        return $res ?? 0;
    }

    /**
     * Get range of posts for user.
     */
    public static function getForUserRange(mixed $uid, mixed $start, mixed $num): mixed
    {
        $range = self::query()
            ->where('forumpost.users_id', $uid)
            ->select(['forumpost.*', 'users.username'])
            ->leftJoin('users', 'users.id', '=', 'forumpost.users_id')
            ->orderByDesc('forumpost.created_at');
        if ($start !== false) {
            $range->limit($num)->offset($start);
        }

        return $range->get();
    }

    /**
     * Edit forum post for user.
     */
    public static function editPost(mixed $id, mixed $message, mixed $uid): void
    {
        $post = self::getPost($id);
        if ($post) {
            self::query()->where(['id' => $id, 'users_id' => $uid])->update(['message' => $message]);
        }
    }

    /**
     * Lock forum topic.
     */
    public static function lockUnlockTopic(mixed $id, mixed $lock): void
    {
        self::query()->where('id', $id)->orWhere('parentid', $id)->update(['locked' => $lock]);
    }

    private static function forumpostFfi(): ?\FFI
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
