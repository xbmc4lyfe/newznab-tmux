<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * App\Models\UserSerie.
 *
 * @property int $id
 * @property int $users_id
 * @property int $videos_id FK to videos.id
 * @property string|null $categories List of categories for user tv shows
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereCategories($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereUsersId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie whereVideosId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserSerie query()
 */
class UserSerie extends Model
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_serie_serialize_category_ids_nul(
    const char *cats_ptr,
    size_t cats_len,
    size_t cats_count,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    protected $dateFormat = false;

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

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * When a user wants to add a show to "my shows" insert it into the user series table.
     *
     *
     * @param  array<int|string, mixed>  $catID
     * @return int|Builder
     */
    public static function addShow(mixed $userId, mixed $videoId, array $catID = []) // @phpstan-ignore missingType.generics
    {
        return self::query()
            ->insertGetId(
                [
                    'users_id' => $userId,
                    'videos_id' => $videoId,
                    'categories' => self::serializeSeriesCategoryIds($catID),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
    }

    /**
     * Get all the user's "my shows".
     */
    public static function getShows(mixed $userId): mixed
    {
        return self::query()
            ->where('user_series.users_id', $userId)
            ->select(['user_series.*', 'v.title'])
            ->join('videos as v', 'v.id', '=', 'user_series.videos_id')
            ->orderBy('v.title')
            ->get();
    }

    /**
     * Delete a tv show from the user's "my shows".
     */
    public static function delShow(mixed $users_id, mixed $videos_id): void
    {
        self::query()->where(compact('users_id', 'videos_id'))->delete();
    }

    /**
     * Get tv show information for a user.
     *
     *
     * @return Model|null|static
     */
    public static function getShow(mixed $userId, mixed $videoId)
    {
        return self::query()
            ->where(['user_series.users_id' => $userId, 'user_series.videos_id' => $videoId])
            ->select(['user_series.*', 'v.title'])
            ->leftJoin('videos as v', 'v.id', '=', 'user_series.videos_id')->first();
    }

    /**
     * Delete all shows from the user's "my shows".
     */
    public static function delShowForUser(mixed $userId): void
    {
        self::query()->where('users_id', $userId)->delete();
    }

    /**
     * Delete TV shows from all user's "my shows" that match a TV id.
     */
    public static function delShowForSeries(mixed $videoId): void
    {
        self::query()->where('videos_id', $videoId)->delete();
    }

    /**
     * Update a TV show category ID for a user's "my show" TV show.
     *
     * @param  array<int|string, mixed>  $catID  List of category ID's.
     */
    public static function updateShow(mixed $users_id, mixed $videos_id, array $catID = []): void
    {
        self::query()->where(compact('users_id', 'videos_id'))->update(['categories' => self::serializeSeriesCategoryIds($catID)]);
    }

    /**
     * @param  array<int|string, mixed>  $catIds
     */
    public static function serializeSeriesCategoryIds(array $catIds): string
    {
        if (self::$useRustFfi) {
            return self::serializeSeriesCategoryIdsNative($catIds);
        }

        return self::serializeSeriesCategoryIdsPhp($catIds);
    }

    /**
     * @param  array<int|string, mixed>  $catIds
     */
    public static function serializeSeriesCategoryIdsPhp(array $catIds): string
    {
        return ! empty($catIds) ? implode('|', array_map(self::coerceString(...), $catIds)) : 'NULL';
    }

    /**
     * @param  array<int|string, mixed>  $catIds
     */
    public static function serializeSeriesCategoryIdsNative(array $catIds): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::serializeSeriesCategoryIdsPhp($catIds);
        }

        $parts = [];
        foreach ($catIds as $catId) {
            $str = self::coerceString($catId);
            if (str_contains($str, "\0")) {
                return self::serializeSeriesCategoryIdsPhp($catIds);
            }
            $parts[] = $str;
        }

        try {
            $packed = implode("\0", $parts);
            $cap = max(16, strlen($packed) + count($parts) + 8);
            $out = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_serie_serialize_category_ids_nul(
                $packed,
                strlen($packed),
                count($parts),
                $out,
                $cap
            );
            if (! is_int($written) || $written < 0) {
                return self::serializeSeriesCategoryIdsPhp($catIds);
            }
            if ($written === 0) {
                return '';
            }

            return FFI::string($out, $written);
        } catch (Throwable) {
            return self::serializeSeriesCategoryIdsPhp($catIds);
        }
    }

    private static function coerceString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
