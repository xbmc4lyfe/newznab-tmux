<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Controllers\GetNzbController;
use App\Services\AdminDashboardSnapshotService;
use App\Services\Monitoring\GrafanaEmbedService;
use App\Services\RegistrationStatusService;
use App\Services\SiteStatusService;
use App\Services\SystemMetricsService;
use App\Services\UserStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminPageController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public const string FFI_CDEF = GetNzbController::FFI_CDEF;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function serviceRows(array $rows): array
    {
        return self::$useRustFfi ? self::serviceRowsNative($rows) : self::serviceRowsPhp($rows);
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function serviceRowsPhp(array $rows): array
    {
        return array_values(array_map(static fn (array $row): array => [
            'name' => $row['name'],
            'slug' => $row['slug'],
            'check_type' => $row['check_type'],
            'status' => $row['status'],
            'status_label' => $row['status_label'],
            'uptime_percentage' => $row['uptime_percentage'],
        ], $rows));
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function serviceRowsNative(array $rows): array
    {
        $result = GetNzbController::transformNative(11, [$rows]);

        return is_array($result) ? $result : self::serviceRowsPhp($rows);
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function incidentRows(array $rows): array
    {
        return self::$useRustFfi ? self::incidentRowsNative($rows) : self::incidentRowsPhp($rows);
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function incidentRowsPhp(array $rows): array
    {
        return array_values(array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'title' => $row['title'],
            'impact' => $row['impact'],
            'impact_label' => $row['impact_label'],
            'started_at' => $row['started_at'],
            'started_at_human' => $row['started_at_human'],
            'is_auto' => $row['is_auto'],
            'services' => $row['services'],
        ], $rows));
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function incidentRowsNative(array $rows): array
    {
        $result = GetNzbController::transformNative(12, [$rows]);

        return is_array($result) ? $result : self::incidentRowsPhp($rows);
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return array<array-key, array<string, mixed>>
     */
    public static function activityRows(array $rows, bool $humanOnly = false): array
    {
        return self::$useRustFfi ? self::activityRowsNative($rows, $humanOnly) : self::activityRowsPhp($rows, $humanOnly);
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return array<array-key, array<string, mixed>>
     */
    public static function activityRowsPhp(array $rows, bool $humanOnly = false): array
    {
        return array_map(static function (array $row) use ($humanOnly): array {
            $activity = [
                'type' => $row['type'],
                'message' => $row['message'],
                'icon' => $row['icon'],
                'icon_bg' => $row['icon_bg'],
                'icon_color' => $row['icon_color'],
                'created_at' => $humanOnly ? $row['created_at_human'] : $row['created_at'],
            ];
            if (! $humanOnly) {
                $activity['created_at_human'] = $row['created_at_human'];
            }

            return [...$activity, 'username' => $row['username'], 'metadata' => $row['metadata']];
        }, $rows);
    }

    /** @param array<array-key, array<string, mixed>> $rows
     * @return array<array-key, array<string, mixed>>
     */
    public static function activityRowsNative(array $rows, bool $humanOnly = false): array
    {
        $result = GetNzbController::transformNative(13, [$rows, $humanOnly]);

        return is_array($result) ? $result : self::activityRowsPhp($rows, $humanOnly);
    }

    /** @param array<string, mixed>|null $period
     * @return array<string, mixed>|null
     */
    public static function registrationPeriod(?array $period): ?array
    {
        return self::$useRustFfi ? self::registrationPeriodNative($period) : self::registrationPeriodPhp($period);
    }

    /** @param array<string, mixed>|null $period
     * @return array<string, mixed>|null
     */
    public static function registrationPeriodPhp(?array $period): ?array
    {
        return $period === null ? null : ['id' => $period['id'], 'name' => $period['name'], 'starts_at' => $period['starts_at'], 'ends_at' => $period['ends_at']];
    }

    /** @param array<string, mixed>|null $period
     * @return array<string, mixed>|null
     */
    public static function registrationPeriodNative(?array $period): ?array
    {
        $result = GetNzbController::transformNative(14, [$period]);

        return is_array($result) ? $result : self::registrationPeriodPhp($period);
    }

    /** @return array{daily: bool, count: int} */
    public static function metricWindow(string $range): array
    {
        return self::$useRustFfi ? self::metricWindowNative($range) : self::metricWindowPhp($range);
    }

    /** @return array{daily: bool, count: int} */
    public static function metricWindowPhp(string $range): array
    {
        return ['daily' => $range === '30d', 'count' => $range === '30d' ? 30 : 24];
    }

    /** @return array{daily: bool, count: int} */
    public static function metricWindowNative(string $range): array
    {
        $result = GetNzbController::transformNative(15, [$range]);

        return is_array($result) ? $result : self::metricWindowPhp($range);
    }

    protected UserStatsService $userStatsService;

    protected SystemMetricsService $systemMetricsService;

    protected RegistrationStatusService $registrationStatusService;

    protected SiteStatusService $siteStatusService;

    protected AdminDashboardSnapshotService $snapshot;

    public function __construct(
        UserStatsService $userStatsService,
        SystemMetricsService $systemMetricsService,
        RegistrationStatusService $registrationStatusService,
        SiteStatusService $siteStatusService,
        AdminDashboardSnapshotService $snapshot,
    ) {
        parent::__construct();
        $this->userStatsService = $userStatsService;
        $this->systemMetricsService = $systemMetricsService;
        $this->registrationStatusService = $registrationStatusService;
        $this->siteStatusService = $siteStatusService;
        $this->snapshot = $snapshot;
    }

    /**
     * @throws \Exception
     */
    public function index(GrafanaEmbedService $grafana): mixed
    {
        $this->setAdminPrefs();

        $payload = $this->snapshot->get();

        return view('admin.dashboard', array_merge($this->viewData, [
            'meta_title' => 'Admin Home',
            'meta_description' => 'Admin home page',
            // Lightweight data is rendered server-side; heavy widgets fetch on mount.
            'stats' => $payload['stats'],
            'dashboardLastRefreshedAt' => Carbon::parse($payload['generated_at'])->format('H:i:s'),
            'registrationStatus' => $payload['registrationStatus'],
            'nextRegistrationPeriod' => $payload['nextRegistrationPeriod'],
            'hasRecentPayments' => ! empty($payload['recent_payments']),
            // Grafana panels replace the Chart.js CPU/RAM history when monitoring is set up.
            'grafanaPanels' => $grafana->dashboardPanels(),
            'grafanaTokenUrl' => $grafana->tokenUrl(),
        ]));
    }

    /**
     * JSON payload consumed by the dashboard's deferred widgets (User Statistics,
     * System Resources history, Recent Activity, Site Status) and by the
     * client-side auto-refresh which also re-renders the headline stat tiles
     * and the registration status panel from this payload. Reads from the
     * same snapshot cache the index uses, so the warmer keeps it hot.
     */
    public function getDashboardData(): JsonResponse
    {
        $payload = $this->snapshot->get();

        $serviceStatuses = self::serviceRows(collect($payload['serviceStatuses'])->map(static fn ($svc) => [
            'name' => $svc->name,
            'slug' => $svc->slug,
            'check_type' => $svc->check_type,
            'status' => $svc->status->value,
            'status_label' => $svc->status->label(),
            'uptime_percentage' => (float) $svc->uptime_percentage,
        ])->values()->all());

        $activeIncidents = self::incidentRows(collect($payload['activeIncidents'])->map(static fn ($incident) => [
            'id' => $incident->id,
            'title' => $incident->title,
            'impact' => $incident->impact->value,
            'impact_label' => $incident->impact->label(),
            'started_at' => $incident->started_at?->toIso8601String(),
            'started_at_human' => $incident->started_at?->diffForHumans(),
            'is_auto' => (bool) $incident->is_auto,
            'services' => $incident->services->pluck('name')->all(),
        ])->values()->all());

        $recentActivity = self::activityRows(array_map(static fn ($activity): array => [
            'type' => $activity->type,
            'message' => $activity->message,
            'icon' => $activity->icon,
            'icon_bg' => $activity->icon_bg,
            'icon_color' => $activity->icon_color,
            'created_at' => $activity->created_at?->toIso8601String(),
            'created_at_human' => $activity->created_at?->diffForHumans(),
            'username' => $activity->username,
            'metadata' => $activity->metadata,
        ], $payload['recent_activity']));

        $registrationStatus = $payload['registrationStatus'];
        $serializePeriod = static fn ($period): ?array => self::registrationPeriod($period === null ? null : [
            'id' => $period->id,
            'name' => $period->name,
            'starts_at' => $period->starts_at?->format('Y-m-d H:i'),
            'ends_at' => $period->ends_at?->format('Y-m-d H:i'),
        ]);

        $registrationPayload = [
            'manual_status' => $registrationStatus['manual_status'],
            'manual_status_label' => $registrationStatus['manual_status_label'],
            'effective_status' => $registrationStatus['effective_status'],
            'effective_status_label' => $registrationStatus['effective_status_label'],
            'scheduled_override_active' => (bool) $registrationStatus['scheduled_override_active'],
            'message' => $registrationStatus['message'],
            'active_period' => $serializePeriod($registrationStatus['active_period']),
        ];

        $generatedAt = $payload['generated_at'] ?? now()->toIso8601String();
        $generatedAtCarbon = Carbon::parse($generatedAt);

        return response()->json([
            'success' => true,
            'generated_at' => $generatedAt,
            'generated_at_time' => $generatedAtCarbon->format('H:i:s'),
            'stats' => $payload['stats'],
            'registrationStatus' => $registrationPayload,
            'nextRegistrationPeriod' => $serializePeriod($payload['nextRegistrationPeriod']),
            'userStats' => $payload['userStats'],
            'systemMetrics' => $payload['systemMetrics'],
            'recent_activity' => $recentActivity,
            'recent_payments' => $payload['recent_payments'] ?? [],
            'serviceStatuses' => $serviceStatuses,
            'activeIncidents' => $activeIncidents,
        ]);
    }

    public function getRecentActivity(): JsonResponse
    {
        $payload = $this->snapshot->get();

        $activities = self::activityRows(array_map(static fn ($activity): array => [
            'type' => $activity->type,
            'message' => $activity->message,
            'icon' => $activity->icon,
            'icon_bg' => $activity->icon_bg,
            'icon_color' => $activity->icon_color,
            'created_at' => $activity->created_at?->toIso8601String(),
            'created_at_human' => $activity->created_at?->diffForHumans(),
            'username' => $activity->username,
            'metadata' => $activity->metadata,
        ], $payload['recent_activity']), true);

        return response()->json([
            'success' => true,
            'activities' => $activities,
        ]);
    }

    public function getUserActivityMinutes(): JsonResponse
    {
        $downloadsPerMinute = $this->userStatsService->getDownloadsPerMinute(60);
        $apiHitsPerMinute = $this->userStatsService->getApiHitsPerMinute(60);

        return response()->json([
            'downloads' => $downloadsPerMinute,
            'api_hits' => $apiHitsPerMinute,
        ]);
    }

    public function getCurrentMetrics(): JsonResponse
    {
        $cpuUsage = $this->systemMetricsService->getCpuUsage();
        $ramUsage = $this->systemMetricsService->getRamUsage();
        $cpuInfo = $this->systemMetricsService->getCpuInfo();
        $loadAverage = $this->systemMetricsService->getLoadAverage();

        return response()->json([
            'success' => true,
            'cpu' => [
                'current' => $cpuUsage,
                'cores' => $cpuInfo['cores'],
                'threads' => $cpuInfo['threads'],
                'model' => $cpuInfo['model'],
                'load_average' => $loadAverage,
            ],
            'ram' => [
                'used' => $ramUsage['used'],
                'total' => $ramUsage['total'],
                'percentage' => $ramUsage['percentage'],
            ],
        ]);
    }

    public function getHistoricalMetrics(): JsonResponse
    {
        $timeRange = request('range', '24h');

        $window = self::metricWindow(is_string($timeRange) ? $timeRange : '');

        if ($window['daily']) {
            $cpuHistory = $this->systemMetricsService->getDailyMetrics('cpu', $window['count']);
            $ramHistory = $this->systemMetricsService->getDailyMetrics('ram', $window['count']);
        } else {
            $cpuHistory = $this->systemMetricsService->getHourlyMetrics('cpu', $window['count']);
            $ramHistory = $this->systemMetricsService->getHourlyMetrics('ram', $window['count']);
        }

        return response()->json([
            'success' => true,
            'cpu_history' => $cpuHistory,
            'ram_history' => $ramHistory,
            'range' => $timeRange,
        ]);
    }
}
