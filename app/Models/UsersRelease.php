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
 * App\Models\UsersRelease.
 *
 * @property int $id
 * @property int $users_id
 * @property int $releases_id FK to releases.id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Release $release
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UsersRelease whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UsersRelease whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UsersRelease whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UsersRelease whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UsersRelease whereUsersId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UsersRelease newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UsersRelease newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UsersRelease query()
 */
class UsersRelease extends Model
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_users_release_normalize_cart_guids_nul(
    const char *guids_ptr,
    size_t guids_len,
    size_t guids_count,
    size_t *out_count,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_users_release_deduplicate_release_ids(
    const int64_t *release_ids_ptr,
    size_t release_ids_len,
    int64_t *out_ids,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = ['id'];

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
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
    }

    public static function delCartForUser(mixed $uid): void
    {
        self::query()->where('users_id', $uid)->delete();
    }

    /**
     * @return int|Builder
     */
    public static function addCart(mixed $uid, mixed $releaseid) // @phpstan-ignore missingType.generics
    {
        return self::query()->insertGetId(
            [
                'users_id' => $uid,
                'releases_id' => $releaseid,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Add multiple releases to a user's cart in one insert statement.
     *
     * @param  list<int>  $releaseIds
     */
    public static function addCartForReleases(int $uid, array $releaseIds): int
    {
        if ($releaseIds === []) {
            return 0;
        }

        $uniqueIds = self::deduplicateReleaseIds($releaseIds);
        if ($uniqueIds === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(
            static fn (int $releaseId): array => [
                'users_id' => $uid,
                'releases_id' => $releaseId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $uniqueIds
        );

        return self::query()->insertOrIgnore($rows);
    }

    /**
     * @param  array<int, int|string>  $releaseIds
     * @return list<int>
     */
    public static function deduplicateReleaseIds(array $releaseIds): array
    {
        if (self::$useRustFfi) {
            return self::deduplicateReleaseIdsNative($releaseIds);
        }

        return self::deduplicateReleaseIdsPhp($releaseIds);
    }

    /**
     * @param  array<int, int|string>  $releaseIds
     * @return list<int>
     */
    public static function deduplicateReleaseIdsPhp(array $releaseIds): array
    {
        if ($releaseIds === []) {
            return [];
        }

        $seen = [];
        $unique = [];
        foreach ($releaseIds as $id) {
            $intId = (int) $id;
            if (! array_key_exists($intId, $seen)) {
                $seen[$intId] = true;
                $unique[] = $intId;
            }
        }

        return $unique;
    }

    /**
     * @param  array<int, int|string>  $releaseIds
     * @return list<int>
     */
    public static function deduplicateReleaseIdsNative(array $releaseIds): array
    {
        if ($releaseIds === []) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::deduplicateReleaseIdsPhp($releaseIds);
        }

        try {
            $count = count($releaseIds);
            $inIds = $ffi->new("int64_t[{$count}]");
            $idx = 0;
            foreach ($releaseIds as $id) {
                $inIds[$idx] = (int) $id;
                $idx++;
            }

            $outIds = $ffi->new("int64_t[{$count}]");
            // @phpstan-ignore method.notFound
            $uniqueCount = $ffi->nntmux_users_release_deduplicate_release_ids(
                $inIds,
                $count,
                $outIds,
                $count
            );
            if (! is_int($uniqueCount) || $uniqueCount < 0 || $uniqueCount > $count) {
                return self::deduplicateReleaseIdsPhp($releaseIds);
            }

            $result = [];
            for ($i = 0; $i < $uniqueCount; $i++) {
                $val = $outIds[$i];
                if (! is_int($val)) {
                    return self::deduplicateReleaseIdsPhp($releaseIds);
                }
                $result[] = $val;
            }

            return $result;
        } catch (Throwable) {
            return self::deduplicateReleaseIdsPhp($releaseIds);
        }
    }

    public static function getCart(mixed $uid): mixed
    {
        return self::query()
            ->with('release')
            ->whereHas('release')
            ->where(['users_id' => $uid])
            ->get();
    }

    /**
     * @return bool|mixed
     */
    public static function delCartByGuid(mixed $guids, mixed $userID)
    {
        if (! \is_array($guids)) {
            return false;
        }

        $normalizedGuids = self::normalizeCartGuids($guids);

        if ($normalizedGuids === []) {
            return false;
        }

        return self::query()
            ->where('users_id', $userID)
            ->whereIn('releases_id', Release::query()->select('id')->whereIn('guid', $normalizedGuids))
            ->delete() > 0;
    }

    /**
     * @return list<string>
     */
    public static function normalizeCartGuids(mixed $guids): array
    {
        if (self::$useRustFfi) {
            return self::normalizeCartGuidsNative($guids);
        }

        return self::normalizeCartGuidsPhp($guids);
    }

    /**
     * @return list<string>
     */
    public static function normalizeCartGuidsPhp(mixed $guids): array
    {
        if (! \is_array($guids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $guid): string => trim(self::coerceString($guid)),
            $guids
        ))));
    }

    /**
     * @return list<string>
     */
    public static function normalizeCartGuidsNative(mixed $guids): array
    {
        if (! \is_array($guids)) {
            return [];
        }
        if ($guids === []) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::normalizeCartGuidsPhp($guids);
        }

        $strs = [];
        foreach ($guids as $guid) {
            $str = self::coerceString($guid);
            if (str_contains($str, "\0")) {
                return self::normalizeCartGuidsPhp($guids);
            }
            $trimmed = trim($str);
            // Defer numeric-like strings where PHP array_unique(SORT_STRING) vs byte comparison is identical,
            // or non-scalar inputs.
            if (! is_string($guid) && ! is_int($guid) && ! is_float($guid) && ! is_bool($guid) && $guid !== null) {
                return self::normalizeCartGuidsPhp($guids);
            }
            unset($trimmed);
            $strs[] = $str;
        }

        try {
            $packed = implode("\0", $strs);
            $cap = max(32, strlen($packed) + 16);
            $outCount = $ffi->new('size_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_users_release_normalize_cart_guids_nul(
                $packed,
                strlen($packed),
                count($strs),
                $outCount,
                $outBuf,
                $cap
            );
            $itemCount = $outCount[0];
            if (! is_int($written) || $written < 0 || ! is_int($itemCount)) {
                return self::normalizeCartGuidsPhp($guids);
            }
            if ($itemCount === 0 || $written === 0) {
                return [];
            }

            $raw = FFI::string($outBuf, $written);

            return explode("\0", $raw);
        } catch (Throwable) {
            return self::normalizeCartGuidsPhp($guids);
        }
    }

    public static function delCartByUserAndRelease(mixed $guid, mixed $uid): void
    {
        self::query()
            ->where('users_id', $uid)
            ->whereIn('releases_id', Release::query()->select('id')->where('guid', $guid))
            ->delete();
    }

    /**
     * Remove multiple releases from a user's cart in one query.
     *
     * @param  list<int>  $releaseIds
     */
    public static function delCartByUserAndReleases(int $uid, array $releaseIds): void
    {
        if ($releaseIds === []) {
            return;
        }
        self::query()->where('users_id', $uid)->whereIn('releases_id', $releaseIds)->delete();
    }

    public static function delCartForRelease(mixed $rid): void
    {
        self::query()->where('releases_id', $rid)->delete();
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
