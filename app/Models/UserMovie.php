<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\UserMovie.
 *
 * @property int $id
 * @property int $users_id
 * @property string|null $imdbid
 * @property string|null $categories List of categories for user movies
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie whereCategories($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie whereImdbid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie whereUsersId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserMovie query()
 */
class UserMovie extends Model
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_movie_serialize_category_ids_nul(
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
     * @param  array<int|string, mixed>  $catID
     * @return int|Builder
     */
    public static function addMovie(mixed $uid, mixed $imdbid, array $catID = []) // @phpstan-ignore missingType.generics
    {
        return self::query()
            ->insertGetId(
                [
                    'users_id' => $uid,
                    'imdbid' => $imdbid,
                    'categories' => self::serializeCategoryIds($catID),
                    'created_at' => now(),
                ]
            );
    }

    /**
     * @return array<string, mixed>
     */
    public static function getMovies(mixed $uid): array
    {
        return self::query()
            ->where('users_id', $uid)
            ->leftJoin('movieinfo as mi', 'mi.imdbid', '=', 'user_movies.imdbid')
            ->orderBy('mi.title')
            ->get(['user_movies.*', 'mi.year', 'mi.plot', 'mi.cover', 'mi.title'])
            ->toArray();
    }

    /**
     * @return mixed
     */
    public static function delMovie(mixed $uid, mixed $imdbid)
    {
        return self::query()->where(['users_id' => $uid, 'imdbid' => $imdbid])->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public static function getMovie(mixed $uid, mixed $imdbid): array
    {
        $result = self::query()
            ->where(['user_movies.users_id' => $uid, 'user_movies.imdbid' => $imdbid])
            ->leftJoin('movieinfo as mi', 'mi.imdbid', '=', 'user_movies.imdbid')
            ->first(['user_movies.*', 'mi.title']);

        return $result ? $result->toArray() : [];
    }

    public static function delMovieForUser(mixed $uid): void
    {
        self::query()->where('users_id', $uid)->delete();
    }

    /**
     * @param  array<int|string, mixed>  $catID
     */
    public static function updateMovie(mixed $uid, mixed $imdbid, array $catID = []): void
    {
        self::query()
            ->where(['users_id' => $uid, 'imdbid' => $imdbid])
            ->update(['categories' => self::serializeCategoryIds($catID)]);
    }

    /**
     * @param  array<int|string, mixed>  $catIds
     */
    public static function serializeCategoryIds(array $catIds): string
    {
        if (self::$useRustFfi) {
            return self::serializeCategoryIdsNative($catIds);
        }

        return self::serializeCategoryIdsPhp($catIds);
    }

    /**
     * @param  array<int|string, mixed>  $catIds
     */
    public static function serializeCategoryIdsPhp(array $catIds): string
    {
        return ! empty($catIds) ? implode('|', array_map(self::coerceString(...), $catIds)) : 'NULL';
    }

    /**
     * @param  array<int|string, mixed>  $catIds
     */
    public static function serializeCategoryIdsNative(array $catIds): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::serializeCategoryIdsPhp($catIds);
        }

        $parts = [];
        foreach ($catIds as $catId) {
            $str = self::coerceString($catId);
            if (str_contains($str, "\0")) {
                return self::serializeCategoryIdsPhp($catIds);
            }
            $parts[] = $str;
        }

        try {
            $packed = implode("\0", $parts);
            $cap = max(16, strlen($packed) + count($parts) + 8);
            $out = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_movie_serialize_category_ids_nul(
                $packed,
                strlen($packed),
                count($parts),
                $out,
                $cap
            );
            if (! is_int($written) || $written < 0) {
                return self::serializeCategoryIdsPhp($catIds);
            }
            if ($written === 0) {
                return '';
            }

            return FFI::string($out, $written);
        } catch (Throwable) {
            return self::serializeCategoryIdsPhp($catIds);
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
