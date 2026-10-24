<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * App\Models\UserDownload.
 *
 * @property int $id
 * @property int $users_id
 * @property string $hosthash
 * @property string $timestamp
 * @property int $releases_id FK to releases.id
 * @property int|null $count Computed count from aggregate queries
 * @property-read Release $release
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereHosthash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload whereUsersId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\UserDownload query()
 */
class UserDownload extends Model
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_download_aggregate_hourly_buckets_nul(
    const char *hour_keys_ptr,
    size_t hour_keys_len,
    size_t hour_keys_count,
    const char *event_keys_ptr,
    size_t event_keys_len,
    size_t event_keys_count,
    int64_t *out_counts,
    size_t out_cap
);
ssize_t nntmux_user_download_normalize_batch_ids(
    int64_t user_id,
    const int64_t *release_ids_ptr,
    size_t release_ids_len,
    int64_t *out_pairs,
    size_t out_pairs_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

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

    /**
     * Get the COUNT of how many NZB's the user has downloaded in the past day.
     * Note: Old request cleanup is no longer done inline to avoid blocking API responses.
     * Use a scheduled command to periodically clean old records instead.
     *
     * @throws \Exception
     */
    public static function getDownloadRequests(int $userID): int
    {
        $value = self::whereUsersId($userID)->where('timestamp', '>', now()->subDay())->count('id');

        return $value ?: 0;
    }

    /**
     * Get hourly download counts for the last 24 hours.
     *
     * @return array<int|string, int> Array of hourly counts indexed by hour
     *
     * @throws \Exception
     */
    public static function getHourlyDownloads(int $userID): array
    {
        $hourKeys = [];
        $now = now();

        // Initialize all 24 hours
        for ($i = 23; $i >= 0; $i--) {
            $hour = $now->copy()->subHours($i);
            $hourKeys[] = $hour->format('H:00');
        }

        // Get downloads from the last 24 hours grouped by hour
        $downloads = self::whereUsersId($userID)
            ->where('timestamp', '>', $now->subDay())
            ->get();

        $eventHourKeys = [];
        foreach ($downloads as $download) {
            $eventHourKeys[] = Carbon::parse($download->timestamp)->format('H:00');
        }

        return self::aggregateHourlyBuckets($hourKeys, $eventHourKeys);
    }

    /**
     * @param  array<int, string|int>  $hourKeys
     * @param  array<int, string|int>  $eventHourKeys
     * @return array<int|string, int>
     */
    public static function aggregateHourlyBuckets(array $hourKeys, array $eventHourKeys): array
    {
        if (self::$useRustFfi) {
            return self::aggregateHourlyBucketsNative($hourKeys, $eventHourKeys);
        }

        return self::aggregateHourlyBucketsPhp($hourKeys, $eventHourKeys);
    }

    /**
     * @param  array<int, string|int>  $hourKeys
     * @param  array<int, string|int>  $eventHourKeys
     * @return array<int|string, int>
     */
    public static function aggregateHourlyBucketsPhp(array $hourKeys, array $eventHourKeys): array
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
    public static function aggregateHourlyBucketsNative(array $hourKeys, array $eventHourKeys): array
    {
        if ($hourKeys === []) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::aggregateHourlyBucketsPhp($hourKeys, $eventHourKeys);
        }

        $uniqueKeys = [];
        $hourStrs = [];
        foreach ($hourKeys as $key) {
            $str = (string) $key;
            if (str_contains($str, "\0")) {
                return self::aggregateHourlyBucketsPhp($hourKeys, $eventHourKeys);
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
                return self::aggregateHourlyBucketsPhp($hourKeys, $eventHourKeys);
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
            $written = $ffi->nntmux_user_download_aggregate_hourly_buckets_nul(
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
                return self::aggregateHourlyBucketsPhp($hourKeys, $eventHourKeys);
            }

            $idx = 0;
            foreach ($uniqueKeys as $k => $_) {
                $val = $outCounts[$idx];
                if (! is_int($val)) {
                    return self::aggregateHourlyBucketsPhp($hourKeys, $eventHourKeys);
                }
                $uniqueKeys[$k] = $val;
                $idx++;
            }

            return $uniqueKeys;
        } catch (Throwable) {
            return self::aggregateHourlyBucketsPhp($hourKeys, $eventHourKeys);
        }
    }

    /**
     * @return Collection<int, self>
     */
    public static function getDownloadRequestsForUser(mixed $userID)
    {
        return self::whereUsersId($userID)->with('release')->orderByDesc('timestamp')->get();
    }

    /**
     * If a user downloads a NZB, log it.
     *
     *
     * @return int|Builder
     */
    public static function addDownloadRequest(mixed $userID, mixed $releaseID) // @phpstan-ignore missingType.generics
    {
        return self::query()
            ->insertGetId(
                [
                    'users_id' => $userID,
                    'releases_id' => $releaseID,
                    'timestamp' => now(),
                ]
            );
    }

    /**
     * Log multiple NZB downloads for a user in a single insert.
     *
     * @param  list<int>  $releaseIds  Release IDs (releases.id)
     */
    public static function addDownloadRequestsBatch(int $userID, array $releaseIds): void
    {
        if ($releaseIds === []) {
            return;
        }
        $now = now()->toDateTimeString();
        $rows = self::buildDownloadBatchRows($userID, $releaseIds, $now);
        self::query()->insert($rows);
    }

    /**
     * @param  array<int, int|string>  $releaseIds
     * @return list<array{users_id: int, releases_id: int, timestamp: string}>
     */
    public static function buildDownloadBatchRows(int $userId, array $releaseIds, string $timestamp): array
    {
        if (self::$useRustFfi) {
            return self::buildDownloadBatchRowsNative($userId, $releaseIds, $timestamp);
        }

        return self::buildDownloadBatchRowsPhp($userId, $releaseIds, $timestamp);
    }

    /**
     * @param  array<int, int|string>  $releaseIds
     * @return list<array{users_id: int, releases_id: int, timestamp: string}>
     */
    public static function buildDownloadBatchRowsPhp(int $userId, array $releaseIds, string $timestamp): array
    {
        $rows = [];
        foreach ($releaseIds as $releaseId) {
            $rows[] = [
                'users_id' => $userId,
                'releases_id' => (int) $releaseId,
                'timestamp' => $timestamp,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, int|string>  $releaseIds
     * @return list<array{users_id: int, releases_id: int, timestamp: string}>
     */
    public static function buildDownloadBatchRowsNative(int $userId, array $releaseIds, string $timestamp): array
    {
        if ($releaseIds === []) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildDownloadBatchRowsPhp($userId, $releaseIds, $timestamp);
        }

        try {
            $count = count($releaseIds);
            $inIds = $ffi->new("int64_t[{$count}]");
            $idx = 0;
            foreach ($releaseIds as $releaseId) {
                $inIds[$idx] = (int) $releaseId;
                $idx++;
            }

            $pairsCap = $count * 2;
            $outPairs = $ffi->new("int64_t[{$pairsCap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_download_normalize_batch_ids(
                $userId,
                $inIds,
                $count,
                $outPairs,
                $pairsCap
            );
            if (! is_int($written) || $written !== $count) {
                return self::buildDownloadBatchRowsPhp($userId, $releaseIds, $timestamp);
            }

            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $uId = $outPairs[$i * 2];
                $rId = $outPairs[$i * 2 + 1];
                if (! is_int($uId) || ! is_int($rId)) {
                    return self::buildDownloadBatchRowsPhp($userId, $releaseIds, $timestamp);
                }
                $rows[] = [
                    'users_id' => $uId,
                    'releases_id' => $rId,
                    'timestamp' => $timestamp,
                ];
            }

            return $rows;
        } catch (Throwable) {
            return self::buildDownloadBatchRowsPhp($userId, $releaseIds, $timestamp);
        }
    }

    /**
     * @return mixed
     *
     * @throws \Exception
     */
    public static function delDownloadRequestsForRelease(int $releaseID)
    {
        return self::whereReleasesId($releaseID)->delete();
    }

    /**
     * @throws \Exception
     */
    public static function delDownloadRequests(mixed $userID): void
    {
        self::whereUsersId($userID)->delete();
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
