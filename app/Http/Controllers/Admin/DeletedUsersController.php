<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\GdprRequest;
use App\Models\User;
use App\Services\AdminDashboardSnapshotService;
use App\Services\Gdpr\GdprErasureService;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DeletedUsersController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_deleted_users_resolve_sort_order_nul(
    const char *order_by_ptr,
    size_t order_by_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_deleted_users_filter_query_string(
    const char *keys_nul_ptr,
    size_t keys_nul_len,
    const char *vals_nul_ptr,
    size_t vals_nul_len,
    const uint8_t *null_mask_ptr,
    size_t count,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_deleted_users_validate_bulk_action_nul(
    uint8_t action_is_string,
    const char *action_ptr,
    size_t action_len,
    uint8_t ids_is_array,
    const int64_t *in_ids_ptr,
    size_t ids_count,
    int64_t *out_valid_ids_ptr,
    size_t out_ids_cap,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Parse and validate sort order.
     *
     * @return array{0: string, 1: string}
     */
    public static function getSortOrder(string $orderBy): array
    {
        if (self::$useRustFfi) {
            return self::getSortOrderNative($orderBy);
        }

        return self::getSortOrderPhp($orderBy);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function getSortOrderPhp(string $orderBy): array
    {
        // Accept patterns like `deleted_at_desc`, `deletedat_desc`, `username`, etc.
        $orderBy = strtolower(trim($orderBy));
        $orderField = 'users.deleted_at'; // sensible default
        $orderSort = 'desc';

        if (preg_match('/^(?P<field>[a-z0-9_]+?)(?:_(?P<dir>asc|desc))?$/', $orderBy, $m)) {
            $rawField = $m['field'];
            $normalized = str_replace(['_', '-'], '', $rawField); // normalize for mapping keys
            $dir = $m['dir'] ?? null;
            $map = [
                'username' => 'users.username',
                'email' => 'users.email',
                'host' => 'users.host',
                'createdat' => 'users.created_at',
                'deletedat' => 'users.deleted_at',
                'lastlogin' => 'users.lastlogin',
                'apiaccess' => 'users.apiaccess',
                'grabs' => 'users.grabs',
                'role' => 'rolename', // alias
                'rolename' => 'rolename',
            ];
            if (isset($map[$normalized])) {
                $orderField = $map[$normalized];
            }
            if ($dir) {
                $orderSort = $dir;
            }
        }

        return [$orderField, $orderSort];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function getSortOrderNative(string $orderBy): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::getSortOrderPhp($orderBy);
        }

        try {
            $cap = 64;
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_deleted_users_resolve_sort_order_nul(
                $orderBy,
                strlen($orderBy),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::getSortOrderPhp($orderBy);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 2) {
                return self::getSortOrderPhp($orderBy);
            }

            return [$parts[0], $parts[1]];
        } catch (Throwable) {
            return self::getSortOrderPhp($orderBy);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function buildFilteredQueryString(array $params): string
    {
        if (self::$useRustFfi) {
            return self::buildFilteredQueryStringNative($params);
        }

        return self::buildFilteredQueryStringPhp($params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function buildFilteredQueryStringPhp(array $params): string
    {
        unset($params['ob'], $params['page']);

        return http_build_query(array_filter($params, fn ($v) => $v !== '' && $v !== null));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function buildFilteredQueryStringNative(array $params): string
    {
        if ($params === []) {
            return '';
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildFilteredQueryStringPhp($params);
        }

        $keys = [];
        $vals = [];
        $nullMask = [];

        foreach ($params as $key => $val) {
            $keyStr = (string) $key;
            if (str_contains($keyStr, "\0")) {
                return self::buildFilteredQueryStringPhp($params);
            }
            if ($val === null) {
                $keys[] = $keyStr;
                $vals[] = '';
                $nullMask[] = 1;

                continue;
            }
            if (! is_string($val) && ! is_int($val)) {
                return self::buildFilteredQueryStringPhp($params);
            }
            $valStr = (string) $val;
            if (str_contains($valStr, "\0")) {
                return self::buildFilteredQueryStringPhp($params);
            }
            $keys[] = $keyStr;
            $vals[] = $valStr;
            $nullMask[] = 0;
        }

        try {
            $count = count($keys);
            $keysNul = implode("\0", $keys);
            $valsNul = implode("\0", $vals);
            $cap = max(128, (strlen($keysNul) + strlen($valsNul)) * 3 + $count * 2 + 32);
            $maskBuf = $ffi->new("uint8_t[{$count}]");
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            for ($i = 0; $i < $count; $i++) {
                $maskBuf[$i] = $nullMask[$i];
            }

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_deleted_users_filter_query_string(
                $keysNul,
                strlen($keysNul),
                $valsNul,
                strlen($valsNul),
                $maskBuf,
                $count,
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written < 0) {
                return self::buildFilteredQueryStringPhp($params);
            }
            if ($written === 0) {
                return '';
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::buildFilteredQueryStringPhp($params);
        }
    }

    /**
     * @return array{
     *     is_valid: bool,
     *     action: string,
     *     user_ids: list<int>,
     *     error_message: string|null,
     *     redirect_route: string
     * }
     */
    public static function evaluateBulkActionInput(mixed $action, mixed $userIds): array
    {
        if (self::$useRustFfi) {
            return self::evaluateBulkActionInputNative($action, $userIds);
        }

        return self::evaluateBulkActionInputPhp($action, $userIds);
    }

    /**
     * @return array{
     *     is_valid: bool,
     *     action: string,
     *     user_ids: list<int>,
     *     error_message: string|null,
     *     redirect_route: string
     * }
     */
    public static function evaluateBulkActionInputPhp(mixed $action, mixed $userIds): array
    {
        $actionStr = is_string($action) ? $action : '';

        if ($actionStr === '' || ! in_array($actionStr, ['restore', 'delete'], true)) {
            return [
                'is_valid' => false,
                'action' => $actionStr,
                'user_ids' => [],
                'error_message' => 'Please select a valid action.',
                'redirect_route' => 'admin.deleted.users.index',
            ];
        }

        if (! is_array($userIds)) {
            return [
                'is_valid' => false,
                'action' => $actionStr,
                'user_ids' => [],
                'error_message' => 'Invalid user selection format.',
                'redirect_route' => 'admin.deleted.users.index',
            ];
        }

        $filteredIds = array_values(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        ));

        if ($filteredIds === []) {
            return [
                'is_valid' => false,
                'action' => $actionStr,
                'user_ids' => [],
                'error_message' => 'Please select at least one user.',
                'redirect_route' => 'admin.deleted.users.index',
            ];
        }

        return [
            'is_valid' => true,
            'action' => $actionStr,
            'user_ids' => $filteredIds,
            'error_message' => null,
            'redirect_route' => 'admin.deleted.users.index',
        ];
    }

    /**
     * @return array{
     *     is_valid: bool,
     *     action: string,
     *     user_ids: list<int>,
     *     error_message: string|null,
     *     redirect_route: string
     * }
     */
    public static function evaluateBulkActionInputNative(mixed $action, mixed $userIds): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::evaluateBulkActionInputPhp($action, $userIds);
        }

        try {
            $actionIsString = is_string($action);
            $actionStr = $actionIsString ? $action : '';
            $idsIsArray = is_array($userIds);
            $intIds = $idsIsArray ? array_values(array_map('intval', $userIds)) : [];
            $idsCount = count($intIds);
            $allocCount = max(1, $idsCount);

            $inIds = $ffi->new("int64_t[{$allocCount}]");
            $outValidIds = $ffi->new("int64_t[{$allocCount}]");
            $outScalars = $ffi->new('int64_t[2]');
            $cap = max(128, strlen($actionStr) + 96);
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            for ($i = 0; $i < $idsCount; $i++) {
                $inIds[$i] = $intIds[$i];
            }

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_deleted_users_validate_bulk_action_nul(
                $actionIsString ? 1 : 0,
                $actionStr,
                strlen($actionStr),
                $idsIsArray ? 1 : 0,
                $inIds,
                $idsCount,
                $outValidIds,
                $allocCount,
                $outScalars,
                $outBuf,
                $cap
            );
            $isValidFlag = $outScalars[0];
            $validCount = $outScalars[1];
            if (! is_int($written) || $written <= 0 || ! is_int($isValidFlag) || ! is_int($validCount) || $validCount < 0 || $validCount > $idsCount) {
                return self::evaluateBulkActionInputPhp($action, $userIds);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 3) {
                return self::evaluateBulkActionInputPhp($action, $userIds);
            }

            $filteredIds = [];
            for ($i = 0; $i < $validCount; $i++) {
                $idVal = $outValidIds[$i];
                if (! is_int($idVal)) {
                    return self::evaluateBulkActionInputPhp($action, $userIds);
                }
                $filteredIds[] = $idVal;
            }

            $isValid = $isValidFlag === 1;

            return [
                'is_valid' => $isValid,
                'action' => $parts[0],
                'user_ids' => $filteredIds,
                'error_message' => $isValid ? null : $parts[2],
                'redirect_route' => $parts[1],
            ];
        } catch (Throwable) {
            return self::evaluateBulkActionInputPhp($action, $userIds);
        }
    }

    /**
     * Display a listing of soft-deleted users with filtering, sorting and pagination.
     */
    public function index(Request $request): mixed
    {
        $this->setAdminPrefs();

        // Filters
        $username = $this->scalarInput($request, 'username');
        $email = $this->scalarInput($request, 'email');
        $host = $this->scalarInput($request, 'host');
        $orderBy = $this->scalarInput($request, 'ob', 'deleted_at_desc');
        $createdFrom = $this->scalarInput($request, 'created_from');
        $createdTo = $this->scalarInput($request, 'created_to');
        $deletedFrom = $this->scalarInput($request, 'deleted_from');
        $deletedTo = $this->scalarInput($request, 'deleted_to');

        $deletedUsers = User::onlyTrashed()
            ->leftJoin('roles', 'roles.id', '=', 'users.roles_id')
            ->select('users.*', 'roles.name as rolename')
            ->when(Schema::hasTable('gdpr_requests'), function ($query): void {
                $query->selectSub(
                    GdprRequest::query()
                        ->select('gdpr_requests.id')
                        ->whereColumn('gdpr_requests.user_id', 'users.id')
                        ->where('gdpr_requests.type', GdprRequest::TYPE_ERASURE)
                        ->where('gdpr_requests.status', GdprRequest::STATUS_COMPLETED)
                        ->orderByDesc('gdpr_requests.completed_at')
                        ->limit(1),
                    'gdpr_erasure_request_id'
                );
            })
            // Qualify all columns to avoid ambiguity with joined tables
            ->when($username !== '', fn ($q) => $q->where('users.username', 'like', "%$username%"))
            ->when($email !== '', fn ($q) => $q->where('users.email', 'like', "%$email%"))
            ->when($host !== '', fn ($q) => $q->where('users.host', 'like', "%$host%"))
            // Created date filters
            ->when($createdFrom !== '' || $createdTo !== '', function ($q) use ($createdFrom, $createdTo) {
                try {
                    if ($createdFrom !== '' && $createdTo !== '') {
                        $from = Carbon::createFromFormat('Y-m-d', $createdFrom)->startOfDay();
                        $to = Carbon::createFromFormat('Y-m-d', $createdTo)->endOfDay();
                        $q->whereBetween('users.created_at', [$from, $to]);
                    } elseif ($createdFrom !== '') {
                        $from = Carbon::createFromFormat('Y-m-d', $createdFrom)->startOfDay();
                        $q->where('users.created_at', '>=', $from);
                    } elseif ($createdTo !== '') {
                        $to = Carbon::createFromFormat('Y-m-d', $createdTo)->endOfDay();
                        $q->where('users.created_at', '<=', $to);
                    }
                } catch (\Exception $e) {
                    // ignore invalid dates
                }
            })
            // Deleted date filters
            ->when($deletedFrom !== '' || $deletedTo !== '', function ($q) use ($deletedFrom, $deletedTo) {
                try {
                    if ($deletedFrom !== '' && $deletedTo !== '') {
                        $from = Carbon::createFromFormat('Y-m-d', $deletedFrom)->startOfDay();
                        $to = Carbon::createFromFormat('Y-m-d', $deletedTo)->endOfDay();
                        $q->whereBetween('users.deleted_at', [$from, $to]);
                    } elseif ($deletedFrom !== '') {
                        $from = Carbon::createFromFormat('Y-m-d', $deletedFrom)->startOfDay();
                        $q->where('users.deleted_at', '>=', $from);
                    } elseif ($deletedTo !== '') {
                        $to = Carbon::createFromFormat('Y-m-d', $deletedTo)->endOfDay();
                        $q->where('users.deleted_at', '<=', $to);
                    }
                } catch (\Exception $e) {
                    // ignore invalid dates
                }
            });

        // Sorting
        [$orderField, $orderSort] = self::getSortOrder($orderBy);
        $deletedUsers = $deletedUsers->orderBy($orderField, $orderSort)
            ->paginate(25)
            ->appends($request->except('page'));

        // Build query string (exclude ordering + pagination) for sort links
        $queryString = self::buildFilteredQueryString($request->except(['ob', 'page']));

        $this->viewData = array_merge($this->viewData, [
            'deletedusers' => $deletedUsers,
            'username' => $username,
            'email' => $email,
            'host' => $host,
            'orderby' => $orderBy,
            'created_from' => $createdFrom,
            'created_to' => $createdTo,
            'deleted_from' => $deletedFrom,
            'deleted_to' => $deletedTo,
            'queryString' => $queryString,
            'meta_title' => 'Deleted Users',
            'meta_keywords' => 'view,deleted,users,softdeleted',
            'meta_description' => 'View and restore soft-deleted user accounts',
            'title' => 'Deleted Users',
        ]);

        return view('admin.users.deleted', $this->viewData);
    }

    /**
     * Bulk restore or permanent delete.
     */
    public function bulkAction(Request $request, GdprErasureService $erasureService): mixed
    {
        $decision = self::evaluateBulkActionInput(
            $request->input('action'),
            $request->input('user_ids', [])
        );

        if (! $decision['is_valid']) {
            return redirect()->route($decision['redirect_route'])->with('error', (string) $decision['error_message']);
        }

        $action = $decision['action'];
        $userIds = $decision['user_ids'];

        if ($action === 'restore') {
            $count = User::onlyTrashed()->whereIn('id', $userIds)->restore();
            Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);

            return redirect()->route('admin.deleted.users.index')->with('success', $count.' user(s) restored successfully.');
        }

        $count = 0;
        $actor = Auth::user();
        $actor = $actor instanceof User ? $actor : null;

        User::onlyTrashed()->whereIn('id', $userIds)->get()->each(function (User $user) use ($erasureService, $actor, &$count): void {
            $erasureService->forceDeleteWithErasure($user, $actor);
            $count++;
        });
        Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);

        return redirect()->route('admin.deleted.users.index')->with('success', $count.' user(s) permanently deleted with GDPR cleanup.');
    }

    /**
     * Restore single user.
     */
    public function restore(mixed $id): mixed
    {
        $user = User::onlyTrashed()->find($id);
        if ($user) {
            $user->restore();
            Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);

            return redirect()->route('admin.deleted.users.index')->with('success', "User '{$user->username}' has been restored successfully.");
        }

        return redirect()->route('admin.deleted.users.index')->with('error', 'User not found.');
    }

    /**
     * Permanently delete single user.
     */
    public function permanentDelete(mixed $id, GdprErasureService $erasureService): mixed
    {
        $user = User::onlyTrashed()->find($id);
        if ($user) {
            $username = $user->username;
            $actor = Auth::user();
            $erasureService->forceDeleteWithErasure($user, $actor instanceof User ? $actor : null);

            return redirect()->route('admin.deleted.users.index')->with('success', "User '{$username}' has been permanently deleted with GDPR cleanup.");
        }

        return redirect()->route('admin.deleted.users.index')->with('error', 'User not found.');
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
