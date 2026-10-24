<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * App\Models\ReleaseComment.
 *
 * @property int $id
 * @property int $releases_id FK to releases.id
 * @property string $text
 * @property bool $isvisible
 * @property string $username
 * @property int $users_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $host
 * @property-read Release $release
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereIsvisible($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereText($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment whereUsersId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseComment query()
 */
class ReleaseComment extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_wave21_comment_host(uint32_t mode, const char *input, size_t len, char *out, size_t cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;

            return self::$ffi === false ? null : self::$ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    public static function commentHost(?string $host, mixed $storeUserIp): ?string
    {
        $native = self::$useRustFfi ? self::commentHostNative($host, $storeUserIp) : null;

        return $native !== null ? $native['host'] : self::commentHostPhp($host, $storeUserIp);
    }

    public static function commentHostPhp(?string $host, mixed $storeUserIp): ?string
    {
        return $storeUserIp === false ? '' : $host;
    }

    /**
     * @return array{host: string|null}|null
     */
    public static function commentHostNative(?string $host, mixed $storeUserIp): ?array
    {
        if (! is_bool($storeUserIp)) {
            return null;
        }

        try {
            $ffi = self::ffi();
            if ($ffi === null) {
                return null;
            }

            $mode = $storeUserIp === false ? 0 : ($host === null ? 2 : 1);
            $input = $host ?? '';
            $length = strlen($input);
            $capacity = $mode === 1 ? $length + 1 : 1;
            $buffer = $ffi->new("char[$capacity]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_wave21_comment_host($mode, $input, $length, $buffer, $capacity);
            if (! is_int($written) || $written !== $capacity) {
                return null;
            }

            $result = FFI::string($buffer, $written);
            if ($mode === 2) {
                return $result === "\x00" ? ['host' => null] : null;
            }
            if ($result[0] !== "\x01") {
                return null;
            }

            return ['host' => substr($result, 1)];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @var array<string>
     */
    protected $guarded = [];

    protected $dateFormat = false;

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * Get a comment by id.
     *
     *
     * @return Model|null|static
     */
    public static function getCommentById(mixed $id)
    {
        return self::query()->where('id', $id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    public static function getComments(mixed $id): array
    {
        return self::query()->where('releases_id', $id)->orderByDesc('created_at')->get()->toArray();
    }

    public static function getCommentCount(): int
    {
        return self::query()->count('id');
    }

    /**
     * Delete single comment on the site.
     */
    public static function deleteComment(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $comment = self::query()->find($id, ['id', 'releases_id']);
            if ($comment === null) {
                return;
            }

            $releaseId = (int) $comment->releases_id;
            $comment->delete();
            self::updateReleaseCommentCount($releaseId);
        });
    }

    /**
     * Add a release_comments row.
     *
     *
     *
     * @throws \Exception
     */
    public static function addComment(int $releaseId, string $text, int $userId, ?string $host): int
    {
        $host = self::commentHost($host, config('nntmux:settings.store_user_ip'));

        $username = User::query()->where('id', $userId)->first(['username']);
        $username = ($username === null ? 'ANON' : $username['username']);

        return DB::transaction(function () use ($releaseId, $text, $userId, $host, $username): int {
            $commentId = self::query()->insertGetId(
                [
                    'releases_id' => $releaseId,
                    'text' => $text,
                    'users_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'host' => $host,
                    'username' => $username,
                ]
            );
            self::updateReleaseCommentCount($releaseId);

            return $commentId;
        });
    }

    /**
     * Get release_comments rows by limit.
     */
    public static function getCommentsRange(): LengthAwarePaginator // @phpstan-ignore missingType.generics
    {
        $range = self::query()
            ->select(['release_comments.*', 'releases.guid'])
            ->leftJoin('releases', 'releases.id', '=', 'release_comments.releases_id')
            ->orderByDesc('release_comments.created_at');

        return $range->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Update the denormalised count of comments for a release.
     */
    public static function updateReleaseCommentCount(int $releaseId): void
    {
        $commentCount = self::query()
            ->where('releases_id', $releaseId)
            ->where('isvisible', 1)
            ->count('id');
        Release::query()->whereKey($releaseId)->update(['comments' => $commentCount]);
    }

    /**
     * Get a count of all comments for a user.
     */
    public static function getCommentCountForUser(mixed $uid): int
    {
        $res = self::query()->where(['users_id' => $uid, 'isvisible' => 1])->count('id');

        return $res;
    }

    public static function getCommentsForUserRange(mixed $uid): LengthAwarePaginator // @phpstan-ignore missingType.generics
    {
        return self::query()
            ->select(['release_comments.*', 'r.guid', 'r.searchname', 'u.username'])
            ->join('releases as r', 'r.id', '=', 'release_comments.releases_id')
            ->leftJoin('users as u', 'u.id', '=', 'release_comments.users_id')
            ->where('users_id', $uid)
            ->orderByDesc('created_at')
            ->paginate(config('nntmux.items_per_page'));
    }
}
