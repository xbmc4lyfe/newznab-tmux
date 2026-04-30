<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserActivityStat;
use App\Models\UserDownload;
use App\Models\UserRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UserStatsService
{
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
     * Source layering — designed to avoid the 1-day rolling purge of
     * `user_downloads` (see routes/console.php cleanup-api-request-logs):
     *  - Closed days (today-(N-1) … yesterday) come from `user_activity_stats`.
     *  - Today is composed of closed hours from `user_activity_stats_hourly`
     *    + the in-progress hour from the live `user_downloads` table.
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
        // Use the aggregated hourly stats from UserActivityStat model
        return UserActivityStat::getDownloadsPerHour($hours);
    }

    /**
     * Get downloads per minute for the last N minutes
     *
     * @return array<string, mixed>
     */
    public function getDownloadsPerMinute(int $minutes = 60): array
    {
        $startTime = Carbon::now()->subMinutes($minutes);

        $downloads = UserDownload::query()
            ->select(
                DB::raw('DATE_FORMAT(timestamp, "%Y-%m-%d %H:%i:00") as minute'),
                DB::raw('COUNT(*) as count')
            )
            ->where('timestamp', '>=', $startTime)
            ->groupBy(DB::raw('DATE_FORMAT(timestamp, "%Y-%m-%d %H:%i:00")'))
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
     * Mirrors {@see self::getDownloadsPerDay()} — closed days from
     * `user_activity_stats`, today composed of `user_activity_stats_hourly`
     * + the in-progress hour from `user_requests`.
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
        // Use the aggregated hourly stats from UserActivityStat model
        return UserActivityStat::getApiHitsPerHour($hours);
    }

    /**
     * Get API hits per minute for the last N minutes
     *
     * @return array<string, mixed>
     */
    public function getApiHitsPerMinute(int $minutes = 60): array
    {
        $startTime = Carbon::now()->subMinutes($minutes);

        // Track actual API requests from user_requests table
        $apiHits = UserRequest::query()
            ->select(
                DB::raw('DATE_FORMAT(timestamp, "%Y-%m-%d %H:%i:00") as minute'),
                DB::raw('COUNT(*) as count')
            )
            ->where('timestamp', '>=', $startTime)
            ->groupBy(DB::raw('DATE_FORMAT(timestamp, "%Y-%m-%d %H:%i:00")'))
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
     * The live `user_downloads` / `user_requests` tables are pruned hourly to
     * the last ~24h (see routes/console.php), so we layer sources to keep the
     * totals consistent regardless of when the purge ran:
     *  - Closed days (today-6 … yesterday) → `user_activity_stats`.
     *  - Today's closed hours → `user_activity_stats_hourly`.
     *  - Current in-progress hour → live `user_downloads` / `user_requests`.
     *
     * @return array{total_users: int, downloads_today: int, downloads_week: int, api_hits_today: int, api_hits_week: int}
     */
    public function getSummaryStats(): array
    {
        $today = Carbon::now()->startOfDay();
        $weekStart = Carbon::now()->subDays(6)->startOfDay();
        $currentHourStart = Carbon::now()->startOfHour();

        // Closed days: [today-6 .. yesterday]
        $historical = UserActivityStat::query()
            ->where('stat_date', '>=', $weekStart->format('Y-m-d'))
            ->where('stat_date', '<', $today->format('Y-m-d'))
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

        $downloadsToday = (int) ($todayClosed->downloads ?? 0) + $downloadsCurrentHour;
        $apiHitsToday = (int) ($todayClosed->api_hits ?? 0) + $apiHitsCurrentHour;

        return [
            'total_users' => User::whereNull('deleted_at')->count(),
            'downloads_today' => $downloadsToday,
            'downloads_week' => (int) ($historical->downloads ?? 0) + $downloadsToday,
            'api_hits_today' => $apiHitsToday,
            'api_hits_week' => (int) ($historical->api_hits ?? 0) + $apiHitsToday,
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
     * Build a "last N days inclusive of today" series, layering closed days
     * (from `user_activity_stats`), today's closed hours (from
     * `user_activity_stats_hourly`) and the current in-progress hour from a
     * caller-supplied live counter.
     *
     * @param  callable(Carbon): int  $liveCurrentHourCounter
     * @return list<array{date: string, count: int}>
     */
    private function buildPerDaySeries(int $days, string $statColumn, callable $liveCurrentHourCounter): array
    {
        $today = Carbon::now()->startOfDay();
        $startDate = $today->copy()->subDays($days - 1);

        $historical = UserActivityStat::query()
            ->select('stat_date', $statColumn)
            ->where('stat_date', '>=', $startDate->format('Y-m-d'))
            ->where('stat_date', '<', $today->format('Y-m-d'))
            ->orderBy('stat_date', 'asc')
            ->get()
            ->keyBy(static fn (UserActivityStat $s): string => $s->stat_date instanceof Carbon
                ? $s->stat_date->format('Y-m-d')
                : (string) $s->stat_date);

        $result = [];
        $cursor = $startDate->copy();
        while ($cursor->lt($today)) {
            $stat = $historical->get($cursor->format('Y-m-d'));
            $result[] = [
                'date' => $cursor->format('M d'),
                'count' => $stat ? (int) $stat->{$statColumn} : 0,
            ];
            $cursor->addDay();
        }

        // Today: closed hours from hourly aggregate + the current hour from live.
        $currentHourStart = Carbon::now()->startOfHour();
        $hourlyColumn = $statColumn === 'api_hits_count' ? 'api_hits_count' : 'downloads_count';
        $todayClosed = (int) DB::table('user_activity_stats_hourly')
            ->where('stat_hour', '>=', $today->format('Y-m-d H:00:00'))
            ->where('stat_hour', '<', $currentHourStart->format('Y-m-d H:00:00'))
            ->sum($hourlyColumn);

        $todayLive = (int) $liveCurrentHourCounter($currentHourStart);

        $result[] = [
            'date' => $today->format('M d'),
            'count' => $todayClosed + $todayLive,
        ];

        return $result;
    }
}
