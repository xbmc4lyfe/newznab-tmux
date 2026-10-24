<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\User;
use App\Models\UserRoleHistory;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class AdminUserRoleHistoryController extends BasePageController
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
     * Display user role history list
     */
    public function index(Request $request): mixed
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'User Role History';

        // Get all roles for filter
        $roles = Role::all()->pluck('name', 'id')->toArray();

        $filters = self::normalizeFilters($request->all());
        $boundaries = self::dateBoundaries($filters['date_from'], $filters['date_to']);

        // Build query
        $query = UserRoleHistory::with(['user', 'oldRole', 'newRole', 'changedByUser'])
            ->orderBy('created_at', 'desc');

        // Apply filters
        if ($filters['user_id'] !== '') {
            $query->where('user_id', $filters['user_id']);
        }

        if ($filters['username'] !== '') {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('username', 'like', '%'.$filters['username'].'%');
            });
        }

        if ($filters['role_id'] !== '') {
            $query->where(function ($q) use ($filters) {
                $q->where('old_role_id', $filters['role_id'])
                    ->orWhere('new_role_id', $filters['role_id']);
            });
        }

        if ($filters['change_reason'] !== '') {
            $query->where('change_reason', 'like', '%'.$filters['change_reason'].'%');
        }

        if ($boundaries['date_from'] !== null) {
            $query->where('created_at', '>=', $boundaries['date_from']);
        }

        if ($boundaries['date_to'] !== null) {
            $query->where('created_at', '<=', $boundaries['date_to']);
        }

        // Pagination
        $page = $this->resolvePage($request);
        $perPage = config('nntmux.items_per_page', 50);

        $results = $query->paginate($perPage, ['*'], 'page', $page);

        $this->viewData = array_merge($this->viewData, [
            'title' => $title,
            'meta_title' => $meta_title,
            'history' => $results,
            'roles' => $roles,
            'filters' => $filters,
        ]);

        return view('admin.user-role-history.index', $this->viewData);
    }

    /**
     * Display role history for a specific user
     */
    public function show(Request $request, int $userId): mixed
    {
        $this->setAdminPrefs();

        $user = User::findOrFail($userId);
        $meta_title = $title = 'Role History for '.$user->username;

        $history = UserRoleHistory::with(['oldRole', 'newRole', 'changedByUser'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->paginate(config('nntmux.items_per_page', 50));

        $this->viewData = array_merge($this->viewData, [
            'title' => $title,
            'meta_title' => $meta_title,
            'user' => $user,
            'history' => $history,
        ]);

        return view('admin.user-role-history.show', $this->viewData);
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{user_id: string, username: string, role_id: string, change_reason: string, date_from: string, date_to: string}
     */
    public static function normalizeFilters(array $input): array
    {
        return self::usesRustFfi() ? self::normalizeFiltersNative($input) : self::normalizeFiltersPhp($input);
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{user_id: string, username: string, role_id: string, change_reason: string, date_from: string, date_to: string}
     */
    public static function normalizeFiltersPhp(array $input): array
    {
        $filters = [];
        foreach (['user_id', 'username', 'role_id', 'change_reason', 'date_from', 'date_to'] as $key) {
            $value = $input[$key] ?? '';
            $filters[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $filters;
    }

    /**
     * @param  array<int|string, mixed>  $input
     * @return array{user_id: string, username: string, role_id: string, change_reason: string, date_from: string, date_to: string}
     */
    public static function normalizeFiltersNative(array $input): array
    {
        return GdprRequestController::wave29Native(14, ['input' => $input]) ?? self::normalizeFiltersPhp($input);
    }

    /**
     * @return array{date_from: string|null, date_to: string|null}
     */
    public static function dateBoundaries(string $from, string $to): array
    {
        return self::usesRustFfi() ? self::dateBoundariesNative($from, $to) : self::dateBoundariesPhp($from, $to);
    }

    /**
     * @return array{date_from: string|null, date_to: string|null}
     */
    public static function dateBoundariesPhp(string $from, string $to): array
    {
        return [
            'date_from' => $from === '' ? null : $from.' 00:00:00',
            'date_to' => $to === '' ? null : $to.' 23:59:59',
        ];
    }

    /**
     * @return array{date_from: string|null, date_to: string|null}
     */
    public static function dateBoundariesNative(string $from, string $to): array
    {
        return GdprRequestController::wave29Native(15, ['date_from' => $from, 'date_to' => $to]) ?? self::dateBoundariesPhp($from, $to);
    }
}
