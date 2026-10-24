<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminPromotionRequest;
use App\Models\RolePromotion;
use App\Models\RolePromotionStat;
use App\Support\DateRangeFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdminPromotionController extends BasePageController
{
    private static ?bool $useWave29RustFfi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useWave29RustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useWave29RustFfi ?? false;
    }

    /**
     * Display a listing of promotions.
     */
    public function index(): View
    {
        $promotions = RolePromotion::orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('admin.promotions.index', [
            'promotions' => $promotions,
        ]);
    }

    /**
     * Show the form for creating a new promotion.
     */
    public function create(): View
    {
        $customRoles = RolePromotion::getCustomRoles();

        return view('admin.promotions.create', [
            'customRoles' => $customRoles,
        ]);
    }

    /**
     * Store a newly created promotion in storage.
     */
    public function store(AdminPromotionRequest $request): RedirectResponse
    {
        RolePromotion::create(self::promotionAttributes($request->all(), $request->has('is_active')));

        return redirect()->route('admin.promotions.index')
            ->with('success', 'Promotion created successfully.');
    }

    /**
     * Show the form for editing the specified promotion.
     */
    public function edit(int $id): View
    {
        $promotion = RolePromotion::findOrFail($id);
        $customRoles = RolePromotion::getCustomRoles();

        return view('admin.promotions.edit', [
            'promotion' => $promotion,
            'customRoles' => $customRoles,
        ]);
    }

    /**
     * Update the specified promotion in storage.
     */
    public function update(AdminPromotionRequest $request, int $id): RedirectResponse
    {
        $promotion = RolePromotion::findOrFail($id);

        $promotion->update(self::promotionAttributes($request->all(), $request->has('is_active')));

        return redirect()->route('admin.promotions.index')
            ->with('success', 'Promotion updated successfully.');
    }

    /**
     * Remove the specified promotion from storage.
     */
    public function destroy(int $id): RedirectResponse
    {
        $promotion = RolePromotion::findOrFail($id);
        $promotion->delete();

        return redirect()->route('admin.promotions.index')
            ->with('success', 'Promotion deleted successfully.');
    }

    /**
     * Toggle the active status of the specified promotion.
     */
    public function toggle(int $id): RedirectResponse
    {
        $promotion = RolePromotion::findOrFail($id);
        $promotion->update(['is_active' => ! $promotion->is_active]);

        $status = self::toggleStatusLabel((bool) $promotion->is_active);

        return redirect()->route('admin.promotions.index')
            ->with('success', "Promotion {$status} successfully.");
    }

    /**
     * Display overall promotion statistics.
     */
    public function statistics(Request $request): View
    {
        [$startDate, $endDate] = DateRangeFilter::fromRequest($request);

        // Get all promotions with their statistics
        $promotions = RolePromotion::withCount(['statistics' => function ($query) use ($startDate, $endDate) {
            if ($startDate) {
                $query->whereBetween('applied_at', [$startDate, $endDate]);
            }
        }])
            ->with(['statistics' => function ($query) use ($startDate, $endDate) {
                if ($startDate) {
                    $query->whereBetween('applied_at', [$startDate, $endDate]);
                }
                $query->with(['user', 'role']);
            }])
            ->get();

        // Calculate overall statistics
        $overallStats = array_merge(self::overallStatistics($promotions->map(fn (RolePromotion $promotion): array => [
            'is_active' => (bool) $promotion->is_active,
            'statistics_count' => (int) $promotion->statistics_count,
        ])->all()), [
            'unique_users' => RolePromotionStat::query()
                ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
                ->distinct('user_id')
                ->count('user_id'),
            'total_days_added' => RolePromotionStat::query()
                ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
                ->sum('days_added'),
        ]);

        // Get top promotions by usage
        $topPromotions = $promotions->sortByDesc('statistics_count')->take(5);

        // Get recent activity
        $recentActivity = RolePromotionStat::with(['user', 'promotion', 'role'])
            ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
            ->latest('applied_at')
            ->limit(10)
            ->get();

        // Get statistics by role
        $statsByRole = RolePromotionStat::query()
            ->selectRaw('role_id, COUNT(*) as total_upgrades, SUM(days_added) as total_days, COUNT(DISTINCT user_id) as unique_users')
            ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
            ->groupBy('role_id')
            ->with('role')
            ->get();

        return view('admin.promotions.statistics', [
            'promotions' => $promotions,
            'overallStats' => $overallStats,
            'topPromotions' => $topPromotions,
            'recentActivity' => $recentActivity,
            'statsByRole' => $statsByRole,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'selectedPeriod' => $request->input('period', '30days'),
        ]);
    }

    /**
     * Display statistics for a specific promotion.
     */
    public function showStatistics(int $id, Request $request): View
    {
        $promotion = RolePromotion::findOrFail($id);

        [$startDate, $endDate] = DateRangeFilter::fromRequest($request);

        // Get promotion statistics
        $stats = $promotion->getStatisticsForPeriod($startDate ?? Carbon::createFromTimestamp(0), $endDate);
        $statsByRole = $promotion->getStatisticsByRole();

        // Get applications with users
        $applications = RolePromotionStat::forPromotion($id)
            ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
            ->with(['user', 'role'])
            ->latest('applied_at')
            ->paginate(20);

        // Get daily statistics for chart
        $dailyStats = RolePromotionStat::forPromotion($id)
            ->when($startDate, fn ($q) => $q->whereBetween('applied_at', [$startDate, $endDate]))
            ->selectRaw('DATE(applied_at) as date, COUNT(*) as count, SUM(days_added) as days')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return view('admin.promotions.show-statistics', [
            'promotion' => $promotion,
            'stats' => $stats,
            'statsByRole' => $statsByRole,
            'applications' => $applications,
            'dailyStats' => $dailyStats,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'selectedPeriod' => $request->input('period', '30days'),
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{name: mixed, description: mixed, applicable_roles: mixed, additional_days: mixed, start_date: mixed, end_date: mixed, is_active: bool}
     */
    public static function promotionAttributes(array $input, bool $isActive): array
    {
        return self::usesRustFfi() ? self::promotionAttributesNative($input, $isActive) : self::promotionAttributesPhp($input, $isActive);
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{name: mixed, description: mixed, applicable_roles: mixed, additional_days: mixed, start_date: mixed, end_date: mixed, is_active: bool}
     */
    public static function promotionAttributesPhp(array $input, bool $isActive): array
    {
        return [
            'name' => $input['name'] ?? null,
            'description' => $input['description'] ?? null,
            'applicable_roles' => array_key_exists('applicable_roles', $input) ? $input['applicable_roles'] : [],
            'additional_days' => $input['additional_days'] ?? null,
            'start_date' => $input['start_date'] ?? null,
            'end_date' => $input['end_date'] ?? null,
            'is_active' => $isActive,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{name: mixed, description: mixed, applicable_roles: mixed, additional_days: mixed, start_date: mixed, end_date: mixed, is_active: bool}
     */
    public static function promotionAttributesNative(array $input, bool $isActive): array
    {
        return GdprRequestController::wave29Native(8, ['input' => $input, 'is_active' => $isActive]) ?? self::promotionAttributesPhp($input, $isActive);
    }

    public static function toggleStatusLabel(bool $active): string
    {
        return self::usesRustFfi() ? self::toggleStatusLabelNative($active) : self::toggleStatusLabelPhp($active);
    }

    public static function toggleStatusLabelPhp(bool $active): string
    {
        return $active ? 'activated' : 'deactivated';
    }

    public static function toggleStatusLabelNative(bool $active): string
    {
        $result = GdprRequestController::wave29Native(9, ['active' => $active]);

        return is_string($result['label'] ?? null) ? $result['label'] : self::toggleStatusLabelPhp($active);
    }

    /**
     * @param  list<array{is_active: bool, statistics_count: int}>  $promotions
     * @return array{total_promotions: int, active_promotions: int, total_applications: int|float}
     */
    public static function overallStatistics(array $promotions): array
    {
        return self::usesRustFfi() ? self::overallStatisticsNative($promotions) : self::overallStatisticsPhp($promotions);
    }

    /**
     * @param  list<array{is_active: bool, statistics_count: int}>  $promotions
     * @return array{total_promotions: int, active_promotions: int, total_applications: int|float}
     */
    public static function overallStatisticsPhp(array $promotions): array
    {
        return [
            'total_promotions' => count($promotions),
            'active_promotions' => count(array_filter($promotions, fn (array $promotion): bool => $promotion['is_active'])),
            'total_applications' => array_sum(array_column($promotions, 'statistics_count')),
        ];
    }

    /**
     * @param  list<array{is_active: bool, statistics_count: int}>  $promotions
     * @return array{total_promotions: int, active_promotions: int, total_applications: int|float}
     */
    public static function overallStatisticsNative(array $promotions): array
    {
        return GdprRequestController::wave29Native(10, ['promotions' => $promotions]) ?? self::overallStatisticsPhp($promotions);
    }
}
