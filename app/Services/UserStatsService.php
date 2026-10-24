<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityStat;
use App\Models\UserDownload;
use App\Models\UserRequest;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserStatsService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_user_stats_minute_bucket_expression(const char *driver_ptr, size_t driver_len, char *out_buf, size_t out_cap);
        int32_t nntmux_user_stats_summary_totals(int64_t total_users, int64_t today_closed_downloads, int64_t week_closed_downloads, int64_t today_closed_api_hits, int64_t week_closed_api_hits, int64_t downloads_current_hour, int64_t api_hits_current_hour, int64_t *out_totals);
        C;

    /**
     * PHP stays the production default: the Rust helpers miss the 1.5x mean/p99 gate
     * (see RustUserStatsInvitationParityTest for the paired benchmark evidence).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Resolve the SQL expression that buckets `timestamp` to its minute for a database driver.
     */
    public static function resolveMinuteBucketExpression(string $driver): string
    {
        return self::$useRustFfi
            ? self::resolveMinuteBucketExpressionNative($driver)
            : self::resolveMinuteBucketExpressionPhp($driver);
    }

    public static function resolveMinuteBucketExpressionNative(string $driver): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::resolveMinuteBucketExpressionPhp($driver);
        }
        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
            if ($buffer === null) {
                return self::resolveMinuteBucketExpressionPhp($driver);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_stats_minute_bucket_expression($driver, \strlen($driver), $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::resolveMinuteBucketExpressionPhp($driver);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::resolveMinuteBucketExpressionPhp($driver);
        }
    }

    public static function resolveMinuteBucketExpressionPhp(string $driver): string
    {
        return $driver === 'sqlite'
            ? "strftime('%Y-%m-%d %H:%M:00', timestamp)"
            : 'DATE_FORMAT(timestamp, "%Y-%m-%d %H:%i:00")';
    }

    /**
     * Combine closed-hour aggregates with the live current hour into the headline summary tiles.
     *
     * @return array{total_users: int, downloads_today: int, downloads_week: int, api_hits_today: int, api_hits_week: int}
     */
    public static function computeSummaryTotals(
        int $totalUsers,
        int $todayClosedDownloads,
        int $weekClosedDownloads,
        int $todayClosedApiHits,
        int $weekClosedApiHits,
        int $downloadsCurrentHour,
        int $apiHitsCurrentHour,
    ): array {
        return self::$useRustFfi
            ? self::computeSummaryTotalsNative($totalUsers, $todayClosedDownloads, $weekClosedDownloads, $todayClosedApiHits, $weekClosedApiHits, $downloadsCurrentHour, $apiHitsCurrentHour)
            : self::computeSummaryTotalsPhp($totalUsers, $todayClosedDownloads, $weekClosedDownloads, $todayClosedApiHits, $weekClosedApiHits, $downloadsCurrentHour, $apiHitsCurrentHour);
    }

    /**
     * @return array{total_users: int, downloads_today: int, downloads_week: int, api_hits_today: int, api_hits_week: int}
     */
    public static function computeSummaryTotalsNative(
        int $totalUsers,
        int $todayClosedDownloads,
        int $weekClosedDownloads,
        int $todayClosedApiHits,
        int $weekClosedApiHits,
        int $downloadsCurrentHour,
        int $apiHitsCurrentHour,
    ): array {
        if (($ffi = self::ffi()) === null) {
            return self::computeSummaryTotalsPhp($totalUsers, $todayClosedDownloads, $weekClosedDownloads, $todayClosedApiHits, $weekClosedApiHits, $downloadsCurrentHour, $apiHitsCurrentHour);
        }
        try {
            $out = $ffi->new('int64_t[5]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_user_stats_summary_totals(
                $totalUsers,
                $todayClosedDownloads,
                $weekClosedDownloads,
                $todayClosedApiHits,
                $weekClosedApiHits,
                $downloadsCurrentHour,
                $apiHitsCurrentHour,
                $out,
            );
            if ($rc !== 1) {
                return self::computeSummaryTotalsPhp($totalUsers, $todayClosedDownloads, $weekClosedDownloads, $todayClosedApiHits, $weekClosedApiHits, $downloadsCurrentHour, $apiHitsCurrentHour);
            }

            return [
                'total_users' => (int) $out[0],
                'downloads_today' => (int) $out[1],
                'downloads_week' => (int) $out[2],
                'api_hits_today' => (int) $out[3],
                'api_hits_week' => (int) $out[4],
            ];
        } catch (Throwable) {
            return self::computeSummaryTotalsPhp($totalUsers, $todayClosedDownloads, $weekClosedDownloads, $todayClosedApiHits, $weekClosedApiHits, $downloadsCurrentHour, $apiHitsCurrentHour);
        }
    }

    /**
     * @return array{total_users: int, downloads_today: int, downloads_week: int, api_hits_today: int, api_hits_week: int}
     */
    public static function computeSummaryTotalsPhp(
        int $totalUsers,
        int $todayClosedDownloads,
        int $weekClosedDownloads,
        int $todayClosedApiHits,
        int $weekClosedApiHits,
        int $downloadsCurrentHour,
        int $apiHitsCurrentHour,
    ): array {
        return [
            'total_users' => $totalUsers,
            'downloads_today' => $todayClosedDownloads + $downloadsCurrentHour,
            'downloads_week' => $weekClosedDownloads + $downloadsCurrentHour,
            'api_hits_today' => $todayClosedApiHits + $apiHitsCurrentHour,
            'api_hits_week' => $weekClosedApiHits + $apiHitsCurrentHour,
        ];
    }

    /**
     * Get user statistics by role
     *
     * @return array<string, mixed>
     */
    public function getUsersByRole(): array
    {
        $usersByRole = User::query()
            ->join('roles', 'users.roles_id', '=', 'roles.id')
            ->select('roles.name as role_name', DB::raw('COUNT(users.id) as count'))
            ->whereNull('users.deleted_at')
            ->groupBy('roles.id', 'roles.name')
            ->get();

        return $usersByRole->map(function ($item) {
            return [
                'role' => $item->role_name,
                'count' => $item->count,
            ];
        })->toArray();
    }

    /**
     * Get downloads per day for the last N days (inclusive of today).
     *
     * Closed hours come from `user_activity_stats_hourly`; the current hour
     * comes from the live `user_downloads` table.
     *
     * @return list<array<string, int|string>>
     */
    public function getDownloadsPerDay(int $days = 7): array
    {
        return $this->buildPerDaySeries(
            $days,
            'downloads_count',
            static fn (Carbon $from): int => UserDownload::query()
                ->where('timestamp', '>=', $from)
                ->count()
        );
    }

    /**
     * Get downloads per hour for the last N hours
     * Uses aggregated hourly stats from user_activity_stats_hourly table
     *
     * @return list<array<string, int|string|null>>
     */
    public function getDownloadsPerHour(int $hours = 168): array
    {
        $result = UserActivityStat::getDownloadsPerHour($hours);

        if ($result !== []) {
            $result[count($result) - 1]['count'] = UserDownload::query()
                ->where('timestamp', '>=', Carbon::now()->startOfHour())
                ->count();
        }

        return $result;
    }

    /**
     * Get downloads per minute for the last N minutes
     *
     * @return array<string, mixed>
     */
    public function getDownloadsPerMinute(int $minutes = 60): array
    {
        $startTime = Carbon::now()->subMinutes($minutes);
        $minuteExpression = $this->minuteBucketExpression();

        $downloads = UserDownload::query()
            ->selectRaw($minuteExpression.' as minute, COUNT(*) as count')
            ->where('timestamp', '>=', $startTime)
            ->groupByRaw($minuteExpression)
            ->orderBy('minute', 'asc')
            ->get()
            ->keyBy('minute');

        // Fill in missing minutes with zero counts (O(n) instead of firstWhere O(n^2))
        $result = [];
        for ($i = $minutes - 1; $i >= 0; $i--) {
            $time = Carbon::now()->subMinutes($i);
            $minuteKey = $time->format('Y-m-d H:i:00');
            $found = $downloads->get($minuteKey);
            $result[] = [
                'time' => $time->format('H:i'),
                'count' => $found ? (int) $found->count : 0,
            ];
        }

        return $result;
    }

    /**
     * Get API hits per day for the last N days (inclusive of today).
     *
     * Mirrors {@see self::getDownloadsPerDay()} using combined authenticated
     * API v1/v2 and RSS requests from `user_requests`.
     *
     * @return list<array<string, int|string>>
     */
    public function getApiHitsPerDay(int $days = 7): array
    {
        return $this->buildPerDaySeries(
            $days,
            'api_hits_count',
            static fn (Carbon $from): int => UserRequest::query()
                ->where('timestamp', '>=', $from)
                ->count()
        );
    }

    /**
     * Get API hits per hour for the last N hours
     * Uses aggregated hourly stats from user_activity_stats_hourly table
     *
     * @return list<array<string, int|string|null>>
     */
    public function getApiHitsPerHour(int $hours = 168): array
    {
        $result = UserActivityStat::getApiHitsPerHour($hours);

        if ($result !== []) {
            $result[count($result) - 1]['count'] = UserRequest::query()
                ->where('timestamp', '>=', Carbon::now()->startOfHour())
                ->count();
        }

        return $result;
    }

    /**
     * Get API hits per minute for the last N minutes
     *
     * @return array<string, mixed>
     */
    public function getApiHitsPerMinute(int $minutes = 60): array
    {
        $startTime = Carbon::now()->subMinutes($minutes);
        $minuteExpression = $this->minuteBucketExpression();

        // Track actual API requests from user_requests table
        $apiHits = UserRequest::query()
            ->selectRaw($minuteExpression.' as minute, COUNT(*) as count')
            ->where('timestamp', '>=', $startTime)
            ->groupByRaw($minuteExpression)
            ->orderBy('minute', 'asc')
            ->get()
            ->keyBy('minute');

        // Fill in missing minutes with zero counts (O(n) instead of firstWhere O(n^2))
        $result = [];
        for ($i = $minutes - 1; $i >= 0; $i--) {
            $time = Carbon::now()->subMinutes($i);
            $minuteKey = $time->format('Y-m-d H:i:00');
            $found = $apiHits->get($minuteKey);
            $result[] = [
                'time' => $time->format('H:i'),
                'count' => $found ? (int) $found->count : 0,
            ];
        }

        return $result;
    }

    /**
     * Get summary statistics for the headline tiles.
     *
     * Window: "today" and "(7d) = last 7 calendar days inclusive of today".
     *
     * Closed hours come from `user_activity_stats_hourly`; the current
     * in-progress hour comes from live `user_downloads` / `user_requests`.
     *
     * @return array{total_users: int, downloads_today: int, downloads_week: int, api_hits_today: int, api_hits_week: int}
     */
    public function getSummaryStats(): array
    {
        $today = Carbon::now()->startOfDay();
        $weekStart = Carbon::now()->subDays(6)->startOfDay();
        $currentHourStart = Carbon::now()->startOfHour();

        $weekClosed = DB::table('user_activity_stats_hourly')
            ->where('stat_hour', '>=', $weekStart->format('Y-m-d H:00:00'))
            ->where('stat_hour', '<', $currentHourStart->format('Y-m-d H:00:00'))
            ->selectRaw('COALESCE(SUM(downloads_count), 0) as downloads, COALESCE(SUM(api_hits_count), 0) as api_hits')
            ->first();

        // Today: closed hours from hourly aggregate.
        $todayClosed = DB::table('user_activity_stats_hourly')
            ->where('stat_hour', '>=', $today->format('Y-m-d H:00:00'))
            ->where('stat_hour', '<', $currentHourStart->format('Y-m-d H:00:00'))
            ->selectRaw('COALESCE(SUM(downloads_count), 0) as downloads, COALESCE(SUM(api_hits_count), 0) as api_hits')
            ->first();

        // Current in-progress hour from live tables.
        $downloadsCurrentHour = UserDownload::query()
            ->where('timestamp', '>=', $currentHourStart)
            ->count();
        $apiHitsCurrentHour = UserRequest::query()
            ->where('timestamp', '>=', $currentHourStart)
            ->count();

        if (self::$useRustFfi) {
            return self::computeSummaryTotals(
                User::whereNull('deleted_at')->count(),
                (int) ($todayClosed->downloads ?? 0),
                (int) ($weekClosed->downloads ?? 0),
                (int) ($todayClosed->api_hits ?? 0),
                (int) ($weekClosed->api_hits ?? 0),
                $downloadsCurrentHour,
                $apiHitsCurrentHour,
            );
        }

        $downloadsToday = (int) ($todayClosed->downloads ?? 0) + $downloadsCurrentHour;
        $apiHitsToday = (int) ($todayClosed->api_hits ?? 0) + $apiHitsCurrentHour;

        return [
            'total_users' => User::whereNull('deleted_at')->count(),
            'downloads_today' => $downloadsToday,
            'downloads_week' => (int) ($weekClosed->downloads ?? 0) + $downloadsCurrentHour,
            'api_hits_today' => $apiHitsToday,
            'api_hits_week' => (int) ($weekClosed->api_hits ?? 0) + $apiHitsCurrentHour,
        ];
    }

    /**
     * Get top downloaders
     *
     * @return array{total_users: int<0, max>, downloads_today: int<0, max>, downloads_week: float|int, api_hits_today: int<0, max>, api_hits_week: float|int}
     */
    public function getTopDownloaders(int $limit = 5): array
    {
        $weekAgo = Carbon::now()->subDays(7);

        return UserDownload::query()
            ->join('users', 'user_downloads.users_id', '=', 'users.id')
            ->select('users.username', DB::raw('COUNT(*) as download_count'))
            ->where('user_downloads.timestamp', '>=', $weekAgo)
            ->groupBy('users.id', 'users.username')
            ->orderByDesc('download_count')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Build a "last N days inclusive of today" series from hourly aggregates
     * plus the current in-progress hour from a caller-supplied live counter.
     *
     * @param  callable(Carbon): int  $liveCurrentHourCounter
     * @return list<array{date: string, count: int}>
     */
    private function buildPerDaySeries(int $days, string $statColumn, callable $liveCurrentHourCounter): array
    {
        $today = Carbon::now()->startOfDay();
        $startDate = $today->copy()->subDays($days - 1);

        $currentHourStart = Carbon::now()->startOfHour();
        $hourly = DB::table('user_activity_stats_hourly')
            ->select('stat_hour', $statColumn)
            ->where('stat_hour', '>=', $startDate->format('Y-m-d H:00:00'))
            ->where('stat_hour', '<', $currentHourStart->format('Y-m-d H:00:00'))
            ->orderBy('stat_hour')
            ->get();

        $dailyCounts = [];
        foreach ($hourly as $stat) {
            $date = Carbon::parse((string) $stat->stat_hour)->format('Y-m-d');
            $dailyCounts[$date] = ($dailyCounts[$date] ?? 0) + (int) $stat->{$statColumn};
        }

        $result = [];
        $cursor = $startDate->copy();
        while ($cursor->lte($today)) {
            $date = $cursor->format('Y-m-d');
            $result[] = [
                'date' => $cursor->format('M d'),
                'count' => $dailyCounts[$date] ?? 0,
            ];
            $cursor->addDay();
        }

        if ($result !== []) {
            $result[count($result) - 1]['count'] += (int) $liveCurrentHourCounter($currentHourStart);
        }

        return $result;
    }

    private function minuteBucketExpression(): string
    {
        return self::resolveMinuteBucketExpression(DB::connection()->getDriverName());
    }
}
