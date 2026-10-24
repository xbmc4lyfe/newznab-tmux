<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\UserStatsService;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class UserActivityStat extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    protected $guarded = [];

    protected $casts = [
        'stat_date' => 'date',
    ];

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_user_activity_hourly_label(int64_t hour_local, int64_t now_local, const char *time_hm_ptr, size_t time_hm_len, const char *day_hm_ptr, size_t day_hm_len, const char *month_day_hm_ptr, size_t month_day_hm_len, char *out_buf, size_t out_cap);
        C;

    private const int SECONDS_PER_DAY = 86400;

    /**
     * PHP stays the production default: the Rust helper misses the 1.5x mean/p99 gate
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
     * Format the relative chart label for an hourly bucket.
     *
     * `$hourTs` and `$nowTs` are wall-clock seconds (Unix timestamp plus UTC offset), so whole
     * days compare as calendar dates in the display timezone: today `H:i`, yesterday
     * `Yesterday H:i`, under seven days ago `D H:i`, otherwise `M d H:i`.
     */
    public static function formatHourlyStatLabel(int $hourTs, int $nowTs, string $timeHm, string $dayHm, string $monthDayHm): string
    {
        return self::$useRustFfi
            ? self::formatHourlyStatLabelNative($hourTs, $nowTs, $timeHm, $dayHm, $monthDayHm)
            : self::formatHourlyStatLabelPhp($hourTs, $nowTs, $timeHm, $dayHm, $monthDayHm);
    }

    public static function formatHourlyStatLabelNative(int $hourTs, int $nowTs, string $timeHm, string $dayHm, string $monthDayHm): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::formatHourlyStatLabelPhp($hourTs, $nowTs, $timeHm, $dayHm, $monthDayHm);
        }
        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch(
                $ffi,
                max(64, 10 + max(\strlen($timeHm), \strlen($dayHm), \strlen($monthDayHm))),
                $capacity,
            );
            if ($buffer === null) {
                return self::formatHourlyStatLabelPhp($hourTs, $nowTs, $timeHm, $dayHm, $monthDayHm);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_activity_hourly_label(
                $hourTs,
                $nowTs,
                $timeHm,
                \strlen($timeHm),
                $dayHm,
                \strlen($dayHm),
                $monthDayHm,
                \strlen($monthDayHm),
                $buffer,
                $capacity,
            );
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::formatHourlyStatLabelPhp($hourTs, $nowTs, $timeHm, $dayHm, $monthDayHm);
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::formatHourlyStatLabelPhp($hourTs, $nowTs, $timeHm, $dayHm, $monthDayHm);
        }
    }

    public static function formatHourlyStatLabelPhp(int $hourTs, int $nowTs, string $timeHm, string $dayHm, string $monthDayHm): string
    {
        $hourDay = self::floorDay($hourTs);
        $nowDay = self::floorDay($nowTs);

        if ($hourDay === $nowDay) {
            return $timeHm;
        }
        if ($hourDay === $nowDay - 1) {
            return 'Yesterday '.$timeHm;
        }
        if ($nowTs - $hourTs < 7 * self::SECONDS_PER_DAY) {
            return $dayHm;
        }

        return $monthDayHm;
    }

    private static function floorDay(int $wallSeconds): int
    {
        $day = intdiv($wallSeconds, self::SECONDS_PER_DAY);

        return $wallSeconds % self::SECONDS_PER_DAY < 0 ? $day - 1 : $day;
    }

    /**
     * Relative chart label for an hourly bucket compared with the current time.
     */
    private static function hourlyLabel(Carbon $time, Carbon $now): string
    {
        return self::formatHourlyStatLabel(
            $time->getTimestamp() + $time->getOffset(),
            $now->getTimestamp() + $now->getOffset(),
            $time->format('H:i'),
            $time->format('D H:i'),
            $time->format('M d H:i'),
        );
    }

    /**
     * Collect and store user activity stats for a specific date
     * This rolls up the retained hourly aggregates so the result cannot shrink
     * after the raw user_downloads and user_requests rows are pruned.
     */
    public static function collectDailyStats(?string $date = null): void
    {
        $statDate = $date ? Carbon::parse($date)->format('Y-m-d') : Carbon::yesterday()->format('Y-m-d');
        $dayStart = Carbon::parse($statDate)->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();

        $totals = DB::table('user_activity_stats_hourly')
            ->where('stat_hour', '>=', $dayStart->format('Y-m-d H:00:00'))
            ->where('stat_hour', '<', $dayEnd->format('Y-m-d H:00:00'))
            ->selectRaw('COALESCE(SUM(downloads_count), 0) as downloads, COALESCE(SUM(api_hits_count), 0) as api_hits')
            ->first();

        $values = [
            'downloads_count' => (int) ($totals->downloads ?? 0),
            'api_hits_count' => (int) ($totals->api_hits ?? 0),
            'updated_at' => now(),
        ];

        if (! DB::table('user_activity_stats')->where('stat_date', $statDate)->exists()) {
            $values['created_at'] = now();
        }

        DB::table('user_activity_stats')->updateOrInsert(
            ['stat_date' => $statDate],
            $values,
        );
    }

    /**
     * Get download stats for the last N days
     *
     * @return array<string, mixed>
     */
    public static function getDownloadsPerDay(int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days - 1)->format('Y-m-d');

        $stats = self::query()
            ->select('stat_date', 'downloads_count')
            ->where('stat_date', '>=', $startDate)
            ->orderBy('stat_date', 'asc')
            ->get()
            ->keyBy('stat_date');

        // Fill in missing days with zero counts
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $stat = $stats->get($date);
            $result[] = [
                'date' => Carbon::parse($date)->format('M d'),
                'count' => $stat ? $stat->downloads_count : 0,
            ];
        }

        return $result;
    }

    /**
     * Get API hits stats for the last N days
     *
     * @return list<array<string, int|string>>
     */
    public static function getApiHitsPerDay(int $days = 30): array
    {
        $startDate = Carbon::now()->subDays($days - 1)->format('Y-m-d');

        $stats = self::query()
            ->select('stat_date', 'api_hits_count')
            ->where('stat_date', '>=', $startDate)
            ->orderBy('stat_date', 'asc')
            ->get()
            ->keyBy('stat_date');

        // Fill in missing days with zero counts
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $stat = $stats->get($date);
            $result[] = [
                'date' => Carbon::parse($date)->format('M d'),
                'count' => $stat ? $stat->api_hits_count : 0,
            ];
        }

        return $result;
    }

    /**
     * Get total downloads across the last N days inclusive of today.
     *
     * Sums closed hours from `user_activity_stats_hourly`. The current
     * in-progress hour is not included here — callers needing live totals use
     * {@see UserStatsService::getSummaryStats()} which layers
     * the live tables on top.
     */
    public static function getTotalDownloads(int $days = 7): int
    {
        return self::sumLastNDays($days, 'downloads_count');
    }

    /**
     * Get total API hits across the last N days inclusive of today.
     *
     * @see self::getTotalDownloads() for source-layering notes.
     */
    public static function getTotalApiHits(int $days = 7): int
    {
        return self::sumLastNDays($days, 'api_hits_count');
    }

    private static function sumLastNDays(int $days, string $column): int
    {
        $weekStart = Carbon::now()->subDays($days - 1)->startOfDay();
        $currentHour = Carbon::now()->startOfHour();

        return (int) DB::table('user_activity_stats_hourly')
            ->where('stat_hour', '>=', $weekStart->format('Y-m-d H:00:00'))
            ->where('stat_hour', '<', $currentHour->format('Y-m-d H:00:00'))
            ->sum($column);
    }

    /**
     * Cleanup old stats (keep last N days)
     */
    public static function cleanupOldStats(int $keepDays = 90): int
    {
        $cutoffDate = Carbon::now()->subDays($keepDays)->format('Y-m-d');

        return self::query()
            ->where('stat_date', '<', $cutoffDate)
            ->delete();
    }

    /**
     * Collect and store hourly user activity stats for a specific hour
     * This aggregates data from user_downloads and user_requests tables
     */
    public static function collectHourlyStats(?string $hour = null): void
    {
        $statHour = $hour ? Carbon::parse($hour)->format('Y-m-d H:00:00') : Carbon::now()->subHour()->startOfHour()->format('Y-m-d H:00:00');

        // Count downloads for the hour
        $downloadsCount = UserDownload::query()
            ->where('timestamp', '>=', $statHour)
            ->where('timestamp', '<', Carbon::parse($statHour)->addHour()->format('Y-m-d H:00:00'))
            ->count();

        // Count API hits for the hour
        $apiHitsCount = UserRequest::query()
            ->where('timestamp', '>=', $statHour)
            ->where('timestamp', '<', Carbon::parse($statHour)->addHour()->format('Y-m-d H:00:00'))
            ->count();

        // Store in hourly stats table
        DB::table('user_activity_stats_hourly')->updateOrInsert(
            ['stat_hour' => $statHour],
            [
                'downloads_count' => $downloadsCount,
                'api_hits_count' => $apiHitsCount,
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]
        );
    }

    /**
     * Get download stats per hour for the last N hours
     *
     * @return array<string, mixed>
     */
    public static function getDownloadsPerHour(int $hours = 168): array
    {
        $startHour = Carbon::now()->subHours($hours - 1)->startOfHour()->format('Y-m-d H:00:00');

        $stats = DB::table('user_activity_stats_hourly')
            ->select('stat_hour', 'downloads_count')
            ->where('stat_hour', '>=', $startHour)
            ->orderBy('stat_hour', 'asc')
            ->get()
            ->keyBy('stat_hour');

        // Fill in missing hours with zero counts
        $result = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $time = Carbon::now()->subHours($i)->startOfHour();
            $hourKey = $time->format('Y-m-d H:00:00');
            $stat = $stats->get($hourKey);

            // Format label based on how recent the hour is
            $label = self::hourlyLabel($time, Carbon::now());

            $result[] = [
                'time' => $label,
                'count' => $stat ? $stat->downloads_count : 0,
            ];
        }

        return $result;
    }

    /**
     * Get API hits stats per hour for the last N hours
     *
     * @return list<array<string, mixed>>
     */
    public static function getApiHitsPerHour(int $hours = 168): array
    {
        $startHour = Carbon::now()->subHours($hours - 1)->startOfHour()->format('Y-m-d H:00:00');

        $stats = DB::table('user_activity_stats_hourly')
            ->select('stat_hour', 'api_hits_count')
            ->where('stat_hour', '>=', $startHour)
            ->orderBy('stat_hour', 'asc')
            ->get()
            ->keyBy('stat_hour');

        // Fill in missing hours with zero counts
        $result = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $time = Carbon::now()->subHours($i)->startOfHour();
            $hourKey = $time->format('Y-m-d H:00:00');
            $stat = $stats->get($hourKey);

            // Format label based on how recent the hour is
            $label = self::hourlyLabel($time, Carbon::now());

            $result[] = [
                'time' => $label,
                'count' => $stat ? $stat->api_hits_count : 0,
            ];
        }

        return $result;
    }

    /**
     * Cleanup old hourly stats (keep last N days)
     */
    public static function cleanupOldHourlyStats(int $keepDays = 30): int
    {
        $cutoffHour = Carbon::now()->subDays($keepDays)->startOfHour()->format('Y-m-d H:00:00');

        return DB::table('user_activity_stats_hourly')
            ->where('stat_hour', '<', $cutoffHour)
            ->delete();
    }
}
