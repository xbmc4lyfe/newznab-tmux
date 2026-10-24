<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * App\Models\UserRequest.
 *
 * @property int $id
 * @property int $users_id
 * @property string $hosthash
 * @property string $request
 * @property string $timestamp
 * @property int|null $count Computed count from aggregate queries
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserRequest whereHosthash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserRequest whereRequest($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserRequest whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserRequest whereUsersId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserRequest query()
 */
class UserRequest extends Model
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_request_aggregate_hourly_buckets_nul(
    const char *hour_keys_ptr,
    size_t hour_keys_len,
    size_t hour_keys_count,
    const char *event_keys_ptr,
    size_t event_keys_len,
    size_t event_keys_count,
    int64_t *out_counts,
    size_t out_cap
);
int32_t nntmux_user_request_is_direct_user_id(
    uint8_t value_kind,
    int64_t int_val
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['id', 'users_id', 'request', 'hosthash', 'timestamp'];

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
     * @throws Throwable
     */
    public static function delApiRequests(mixed $userID): void
    {
        DB::transaction(function () use ($userID) {
            self::query()->where('users_id', $userID)->delete();
        }, 3);
    }

    /**
     * Get the quantity of API requests in the last day for the users_id.
     * Note: Old request cleanup is no longer done inline to avoid blocking API responses.
     * Use clearApiRequests() via a scheduled command or queue job instead.
     *
     * @throws \Exception
     * @throws Throwable
     */
    public static function getApiRequests(int $userID): int
    {
        $requests = self::query()
            ->where('users_id', $userID)
            ->where('timestamp', '>', now()->subDay())
            ->count('id');

        return $requests ?: 0;
    }

    /**
     * Get hourly API request counts for the last 24 hours.
     *
     * @return array<int|string, int> Array of hourly counts indexed by hour
     *
     * @throws \Exception
     */
    public static function getHourlyApiRequests(int $userID): array
    {
        $hourKeys = [];
        $now = now();

        // Initialize all 24 hours
        for ($i = 23; $i >= 0; $i--) {
            $hour = $now->copy()->subHours($i);
            $hourKeys[] = $hour->format('H:00');
        }

        // Get API requests from the last 24 hours grouped by hour
        $requests = self::query()
            ->where('users_id', $userID)
            ->where('timestamp', '>', $now->subDay())
            ->get();

        $eventHourKeys = [];
        foreach ($requests as $request) {
            $eventHourKeys[] = Carbon::parse($request->timestamp)->format('H:00');
        }

        return self::aggregateHourlyRequestBuckets($hourKeys, $eventHourKeys);
    }

    /**
     * @param  array<int, string|int>  $hourKeys
     * @param  array<int, string|int>  $eventHourKeys
     * @return array<int|string, int>
     */
    public static function aggregateHourlyRequestBuckets(array $hourKeys, array $eventHourKeys): array
    {
        if (self::$useRustFfi) {
            return self::aggregateHourlyRequestBucketsNative($hourKeys, $eventHourKeys);
        }

        return self::aggregateHourlyRequestBucketsPhp($hourKeys, $eventHourKeys);
    }

    /**
     * @param  array<int, string|int>  $hourKeys
     * @param  array<int, string|int>  $eventHourKeys
     * @return array<int|string, int>
     */
    public static function aggregateHourlyRequestBucketsPhp(array $hourKeys, array $eventHourKeys): array
    {
        $hourlyData = [];
        foreach ($hourKeys as $key) {
            $hourlyData[(string) $key] = 0;
        }

        foreach ($eventHourKeys as $hourKey) {
            $key = (string) $hourKey;
            if (isset($hourlyData[$key])) {
                $hourlyData[$key]++;
            }
        }

        return $hourlyData;
    }

    /**
     * @param  array<int, string|int>  $hourKeys
     * @param  array<int, string|int>  $eventHourKeys
     * @return array<int|string, int>
     */
    public static function aggregateHourlyRequestBucketsNative(array $hourKeys, array $eventHourKeys): array
    {
        if ($hourKeys === []) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::aggregateHourlyRequestBucketsPhp($hourKeys, $eventHourKeys);
        }

        $uniqueKeys = [];
        $hourStrs = [];
        foreach ($hourKeys as $key) {
            $str = (string) $key;
            if (str_contains($str, "\0")) {
                return self::aggregateHourlyRequestBucketsPhp($hourKeys, $eventHourKeys);
            }
            $hourStrs[] = $str;
            if (! array_key_exists($str, $uniqueKeys)) {
                $uniqueKeys[$str] = 0;
            }
        }

        $eventStrs = [];
        foreach ($eventHourKeys as $hourKey) {
            $str = (string) $hourKey;
            if (str_contains($str, "\0")) {
                return self::aggregateHourlyRequestBucketsPhp($hourKeys, $eventHourKeys);
            }
            $eventStrs[] = $str;
        }

        try {
            $hourPacked = implode("\0", $hourStrs);
            $eventPacked = implode("\0", $eventStrs);
            $uniqueCount = count($uniqueKeys);
            $outCap = max(1, $uniqueCount);
            $outCounts = $ffi->new("int64_t[{$outCap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_request_aggregate_hourly_buckets_nul(
                $hourPacked,
                strlen($hourPacked),
                count($hourStrs),
                $eventPacked,
                strlen($eventPacked),
                count($eventStrs),
                $outCounts,
                $outCap
            );
            if (! is_int($written) || $written !== $uniqueCount) {
                return self::aggregateHourlyRequestBucketsPhp($hourKeys, $eventHourKeys);
            }

            $idx = 0;
            foreach ($uniqueKeys as $k => $_) {
                $val = $outCounts[$idx];
                if (! is_int($val)) {
                    return self::aggregateHourlyRequestBucketsPhp($hourKeys, $eventHourKeys);
                }
                $uniqueKeys[$k] = $val;
                $idx++;
            }

            return $uniqueKeys;
        } catch (Throwable) {
            return self::aggregateHourlyRequestBucketsPhp($hourKeys, $eventHourKeys);
        }
    }

    /**
     * If a user accesses the API, log it.
     *
     * @param  string|int  $tokenOrUserId  API token string or user ID integer
     * @param  string  $request  The API request.
     */
    public static function addApiRequest(string|int $tokenOrUserId, string $request): void
    {
        if (self::isDirectUserId($tokenOrUserId)) {
            $userID = (int) $tokenOrUserId;
        } else {
            $userID = User::query()->select(['id'])->where('api_token', (string) $tokenOrUserId)->value('id');
        }
        self::recordApiRequest((int) $userID, $request, now()->toDateTimeString());
    }

    public static function isDirectUserId(mixed $tokenOrUserId): bool
    {
        if (self::$useRustFfi) {
            return self::isDirectUserIdNative($tokenOrUserId);
        }

        return self::isDirectUserIdPhp($tokenOrUserId);
    }

    public static function isDirectUserIdPhp(mixed $tokenOrUserId): bool
    {
        return is_int($tokenOrUserId);
    }

    public static function isDirectUserIdNative(mixed $tokenOrUserId): bool
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::isDirectUserIdPhp($tokenOrUserId);
        }

        try {
            $kind = is_int($tokenOrUserId) ? 1 : 0;
            $intVal = is_int($tokenOrUserId) ? $tokenOrUserId : 0;
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_user_request_is_direct_user_id($kind, $intVal);
            if (! is_int($rc) || ($rc !== 0 && $rc !== 1)) {
                return self::isDirectUserIdPhp($tokenOrUserId);
            }

            return $rc === 1;
        } catch (Throwable) {
            return self::isDirectUserIdPhp($tokenOrUserId);
        }
    }

    public static function recordApiRequest(int $userId, string $request, string $occurredAt): void
    {
        self::query()->insert([
            'users_id' => $userId,
            'request' => $request,
            'timestamp' => $occurredAt,
        ]);
    }

    /**
     * Delete api requests older than a day.
     *
     * @param  int|bool  $userID
     *                            int The users ID.
     *                            bool false do all user ID's..
     *
     * @throws \Exception
     * @throws Throwable
     */
    public static function clearApiRequests($userID): void
    {
        DB::transaction(function () use ($userID) {
            if ($userID === false) {
                self::query()->where('timestamp', '<', now()->subDay())->delete();
            } else {
                self::query()->where('users_id', $userID)->where('timestamp', '<', now()->subDay())->delete();
            }
        }, 3);
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
