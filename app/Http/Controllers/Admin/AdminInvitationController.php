<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AdminInvitationController extends BasePageController
{
    private static ?bool $useWave29RustFfi = null;

    protected InvitationService $invitationService;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useWave29RustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useWave29RustFfi ?? false;
    }

    public function __construct(InvitationService $invitationService)
    {
        parent::__construct();
        $this->invitationService = $invitationService;
    }

    /**
     * Display all invitations statistics and management page
     */
    public function index(Request $request): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'Invitation Management';

        // Get filter parameters
        $filters = self::invitationFilters($request->all());
        $status = $filters['status'];
        $invited_by = $filters['invited_by'];
        $email = $filters['email'];
        $orderBy = $filters['orderBy'];

        // Build query
        $query = Invitation::with(['invitedBy', 'usedBy']);

        // Apply filters
        if ($status) {
            switch ($status) {
                case 'pending':
                    $query->valid(); // Active, not expired, not used
                    break;
                case 'used':
                    $query->used(); // Has used_at timestamp
                    break;
                case 'expired':
                    $query->expired(); // expires_at is in the past
                    break;
                case 'cancelled':
                    $query->where('is_active', false)
                        ->whereNull('used_at'); // Inactive but not used = cancelled
                    break;
            }
        }

        if ($invited_by) {
            $user = User::where('username', 'like', '%'.$invited_by.'%')->first();
            if ($user) {
                $query->where('invited_by', $user->id);
            }
        }

        if ($email) {
            $query->where('email', 'like', '%'.$email.'%');
        }

        // Apply ordering
        $query->orderBy($filters['column'], $filters['direction']);

        // Paginate results
        $invitations = $query->paginate(config('nntmux.items_per_page', 25))->withQueryString();

        // Get overall statistics
        $stats = $this->getOverallStats();

        // Get user statistics (top inviters)
        $topInviters = $this->getTopInviters();

        $statusOptions = self::statusOptions();

        return view('admin.invitations.index', compact(
            'invitations',
            'stats',
            'topInviters',
            'status',
            'invited_by',
            'email',
            'orderBy',
            'statusOptions',
            'title',
            'meta_title'
        ));
    }

    /**
     * Get overall invitation statistics with caching
     *
     * @return array<string, mixed>
     */
    private function getOverallStats(): array
    {
        return Cache::remember('admin_invitation_stats', 300, function () {
            return [
                'total' => Invitation::count(),
                'pending' => Invitation::valid()->count(),
                'used' => Invitation::used()->count(),
                'expired' => Invitation::expired()->count(),
                'cancelled' => Invitation::where('is_active', false)->whereNull('used_at')->count(),
                'today' => Invitation::whereDate('created_at', today())->count(),
                'this_week' => Invitation::whereBetween('created_at', [
                    now()->startOfWeek(),
                    now()->endOfWeek(),
                ])->count(),
                'this_month' => Invitation::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
            ];
        });
    }

    /**
     * Get top inviters statistics with caching
     *
     * @return array<string, mixed>
     */
    private function getTopInviters(int $limit = 10): array
    {
        return Cache::remember('admin_top_inviters', 300, function () use ($limit) {
            return User::select('users.*')
                ->selectRaw('COUNT(invitations.id) as total_invitations')
                ->selectRaw('COUNT(CASE WHEN invitations.used_at IS NOT NULL THEN 1 END) as successful_invitations')
                ->leftJoin('invitations', 'users.id', '=', 'invitations.invited_by')
                ->groupBy('users.id')
                ->having('total_invitations', '>', 0)
                ->orderBy('total_invitations', 'desc')
                ->limit($limit)
                ->get()
                ->toArray();
        });
    }

    /**
     * Cancel an invitation
     */
    public function cancel(Request $request): RedirectResponse
    {
        try {
            $id = (int) $request->route('id');
            $this->invitationService->cancelInvitation($id);

            return redirect()->back()->with('success', 'Invitation cancelled successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to cancel invitation: '.$e->getMessage());
        }
    }

    /**
     * Resend an invitation
     */
    public function resend(Request $request): RedirectResponse
    {
        try {
            $id = (int) $request->route('id');
            $this->invitationService->resendInvitation($id);

            return redirect()->back()->with('success', 'Invitation resent successfully');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to resend invitation: '.$e->getMessage());
        }
    }

    /**
     * Cleanup expired invitations
     */
    public function cleanup(Request $request): RedirectResponse
    {
        try {
            $cleanedCount = $this->invitationService->cleanupExpiredInvitations();

            return redirect()->back()->with('success', "Cleaned up {$cleanedCount} expired invitations");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to cleanup invitations: '.$e->getMessage());
        }
    }

    /**
     * View detailed invitation
     */
    public function show(Request $request): View
    {
        $this->setAdminPrefs();

        $id = $request->route('id');
        $invitation = Invitation::with(['invitedBy', 'usedBy'])->findOrFail($id);

        $meta_title = $title = 'Invitation Details';

        return view('admin.invitations.show', compact('invitation', 'title', 'meta_title'));
    }

    /**
     * Bulk actions on invitations
     */
    public function bulkAction(Request $request): RedirectResponse
    {
        $action = $request->get('bulk_action');
        $invitationIds = $request->get('invitation_ids', []);

        $state = self::bulkActionState($action, $invitationIds);
        if ($state['error'] !== null) {
            return redirect()->back()->with('error', $state['error']);
        }

        try {
            $count = 0;
            switch ($action) {
                case 'cancel':
                    foreach ($invitationIds as $id) {
                        $this->invitationService->cancelInvitation($id);
                        $count++;
                    }

                    return redirect()->back()->with('success', self::bulkActionState($action, $invitationIds, $count)['message']);

                case 'resend':
                    foreach ($invitationIds as $id) {
                        $this->invitationService->resendInvitation($id);
                        $count++;
                    }

                    return redirect()->back()->with('success', self::bulkActionState($action, $invitationIds, $count)['message']);

                case 'delete':
                    Invitation::whereIn('id', $invitationIds)->delete();
                    $count = count($invitationIds);

                    return redirect()->back()->with('success', self::bulkActionState($action, $invitationIds, $count)['message']);

                default:
                    return redirect()->back()->with('error', 'Invalid bulk action');
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Bulk action failed: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{status: string, invited_by: string, email: string, orderBy: string, column: string, direction: string}
     */
    public static function invitationFilters(array $input): array
    {
        return self::usesRustFfi() ? self::invitationFiltersNative($input) : self::invitationFiltersPhp($input);
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{status: string, invited_by: string, email: string, orderBy: string, column: string, direction: string}
     */
    public static function invitationFiltersPhp(array $input): array
    {
        $filters = [];
        foreach (['status', 'invited_by', 'email', 'ob'] as $key) {
            $default = $key === 'ob' ? 'created_at_desc' : '';
            $value = $input[$key] ?? $default;
            $filters[$key === 'ob' ? 'orderBy' : $key] = is_scalar($value) ? (string) $value : $default;
        }
        $ordering = [
            'created_at_asc' => ['created_at', 'asc'],
            'created_at_desc' => ['created_at', 'desc'],
            'expires_at_asc' => ['expires_at', 'asc'],
            'expires_at_desc' => ['expires_at', 'desc'],
            'email_asc' => ['email', 'asc'],
            'email_desc' => ['email', 'desc'],
        ];
        [$filters['column'], $filters['direction']] = $ordering[$filters['orderBy']] ?? ['created_at', 'desc'];

        return $filters;
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{status: string, invited_by: string, email: string, orderBy: string, column: string, direction: string}
     */
    public static function invitationFiltersNative(array $input): array
    {
        return GdprRequestController::wave29Native(11, ['input' => $input]) ?? self::invitationFiltersPhp($input);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return self::usesRustFfi() ? self::statusOptionsNative() : self::statusOptionsPhp();
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptionsPhp(): array
    {
        return ['' => 'All', 'pending' => 'Pending', 'used' => 'Used', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptionsNative(): array
    {
        return GdprRequestController::wave29Native(12, []) ?? self::statusOptionsPhp();
    }

    /**
     * @return array{error: string|null, message: string|null}
     */
    public static function bulkActionState(mixed $action, mixed $ids, int $count = 0): array
    {
        return self::usesRustFfi() ? self::bulkActionStateNative($action, $ids, $count) : self::bulkActionStatePhp($action, $ids, $count);
    }

    /**
     * @return array{error: string|null, message: string|null}
     */
    public static function bulkActionStatePhp(mixed $action, mixed $ids, int $count = 0): array
    {
        if (empty($ids)) {
            return ['error' => 'No invitations selected', 'message' => null];
        }
        switch ($action) {
            case 'cancel':
                $verb = 'Cancelled';
                break;
            case 'resend':
                $verb = 'Resent';
                break;
            case 'delete':
                $verb = 'Deleted';
                break;
            default:
                $verb = null;
        }

        return $verb === null
            ? ['error' => 'Invalid bulk action', 'message' => null]
            : ['error' => null, 'message' => "{$verb} {$count} invitations"];
    }

    /**
     * @return array{error: string|null, message: string|null}
     */
    public static function bulkActionStateNative(mixed $action, mixed $ids, int $count = 0): array
    {
        return GdprRequestController::wave29Native(13, ['action' => $action, 'ids' => $ids, 'count' => $count]) ?? self::bulkActionStatePhp($action, $ids, $count);
    }
}
