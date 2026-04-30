<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Locks in the "(7d) = last 7 calendar days inclusive of today" semantics
 * and the source layering used by the headline summary tiles:
 *
 *  - Closed days come from `user_activity_stats`.
 *  - Today's closed hours come from `user_activity_stats_hourly`.
 *  - The current in-progress hour comes from the live `user_downloads` /
 *    `user_requests` tables (which are pruned hourly to ~24h).
 *
 * The assertions are static (source-content) to mirror the existing
 * AdminDashboardUserCountTest pattern and avoid depending on the legacy
 * MySQL-only schema dump for in-memory SQLite tests.
 */
class AdminDashboardSummaryWindowTest extends TestCase
{
    public function test_summary_window_layers_daily_hourly_and_live_sources(): void
    {
        $servicePath = app_path('Services/UserStatsService.php');

        $this->assertFileExists($servicePath);

        $content = (string) file_get_contents($servicePath);

        // 7-day window inclusive of today (today-6 .. today).
        $this->assertStringContainsString(
            "\$weekStart = Carbon::now()->subDays(6)->startOfDay();",
            $content,
            'Summary window must be the last 7 calendar days inclusive of today.'
        );

        // Closed days from the daily aggregate, strictly before today.
        $this->assertStringContainsString("UserActivityStat::query()", $content);
        $this->assertStringContainsString(
            "->where('stat_date', '>=', \$weekStart->format('Y-m-d'))",
            $content
        );
        $this->assertStringContainsString(
            "->where('stat_date', '<', \$today->format('Y-m-d'))",
            $content
        );

        // Today's closed hours from the hourly aggregate.
        $this->assertStringContainsString(
            "DB::table('user_activity_stats_hourly')",
            $content
        );
        $this->assertStringContainsString(
            "->where('stat_hour', '<', \$currentHourStart->format('Y-m-d H:00:00'))",
            $content
        );

        // Current in-progress hour from the live tables.
        $this->assertStringContainsString(
            "UserDownload::query()\n            ->where('timestamp', '>=', \$currentHourStart)",
            $content
        );
        $this->assertStringContainsString(
            "UserRequest::query()\n            ->where('timestamp', '>=', \$currentHourStart)",
            $content
        );

        // Today and week totals must combine all three sources.
        $this->assertStringContainsString(
            "'downloads_today' => \$downloadsToday,",
            $content
        );
        $this->assertStringContainsString(
            "'downloads_week' => (int) (\$historical->downloads ?? 0) + \$downloadsToday,",
            $content
        );
        $this->assertStringContainsString(
            "'api_hits_today' => \$apiHitsToday,",
            $content
        );
        $this->assertStringContainsString(
            "'api_hits_week' => (int) (\$historical->api_hits ?? 0) + \$apiHitsToday,",
            $content
        );

        // The previous "two-days-ago" boundary that produced the under-reporting
        // bug must be gone from getSummaryStats.
        $this->assertStringNotContainsString(
            "\$twoDaysAgo = Carbon::now()->subDays(2)->startOfDay();",
            $content
        );
    }

    public function test_per_day_series_uses_same_source_layering(): void
    {
        $servicePath = app_path('Services/UserStatsService.php');
        $content = (string) file_get_contents($servicePath);

        // Both per-day series helpers must delegate to the shared builder so
        // the chart and headline summary stay consistent.
        $this->assertStringContainsString(
            'private function buildPerDaySeries(int $days, string $statColumn, callable $liveCurrentHourCounter): array',
            $content
        );
        $this->assertStringContainsString(
            "return \$this->buildPerDaySeries(\n            \$days,\n            'downloads_count',",
            $content
        );
        $this->assertStringContainsString(
            "return \$this->buildPerDaySeries(\n            \$days,\n            'api_hits_count',",
            $content
        );
    }

    public function test_dashboard_data_endpoint_returns_stats_and_registration(): void
    {
        $controllerPath = app_path('Http/Controllers/Admin/AdminPageController.php');

        $this->assertFileExists($controllerPath);

        $content = (string) file_get_contents($controllerPath);

        // Auto-refresh JS depends on these payload keys to update the
        // headline tiles and registration panel without a page reload.
        $this->assertStringContainsString("'stats' => \$payload['stats'],", $content);
        $this->assertStringContainsString("'registrationStatus' => \$registrationPayload,", $content);
        $this->assertStringContainsString("'nextRegistrationPeriod' => \$serializePeriod(\$payload['nextRegistrationPeriod']),", $content);
        $this->assertStringContainsString("'generated_at_time' => \$generatedAtCarbon->format('H:i:s'),", $content);
    }

    public function test_collect_stats_runs_every_fifteen_minutes(): void
    {
        $consolePath = base_path('routes/console.php');

        $this->assertFileExists($consolePath);

        $content = (string) file_get_contents($consolePath);

        $this->assertStringContainsString(
            "Schedule::command('nntmux:collect-stats')->everyFifteenMinutes()",
            $content,
            'The user activity stats collector should run every 15 minutes so '
            .'the hourly aggregate catches up shortly after each hour boundary.'
        );
    }

    public function test_registration_writes_invalidate_dashboard_snapshot(): void
    {
        $servicePath = app_path('Services/RegistrationStatusService.php');
        $content = (string) file_get_contents($servicePath);

        $this->assertStringContainsString('use Illuminate\\Support\\Facades\\Cache;', $content);
        $this->assertStringContainsString(
            'Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);',
            $content
        );
        // Each public write path drops the snapshot.
        $this->assertSame(
            6,
            substr_count($content, '$this->forgetDashboardSnapshot();'),
            'updateManualStatus, createPeriod, updatePeriod, togglePeriod, '
            .'disableExpiredPeriods and deletePeriod must all forget the dashboard snapshot.'
        );
    }
}

