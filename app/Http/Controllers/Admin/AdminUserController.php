<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SignupError;
use App\Enums\UserRole;
use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\AdminUserListRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Models\UserDownload;
use App\Models\UserRequest;
use App\Services\AdminDashboardSnapshotService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPasskeys\Models\Passkey;
use Spatie\Permission\Models\Role;
use Throwable;

class AdminUserController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_admin_user_signup_error_message(
    int64_t code,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_admin_user_build_order_urls_nul(
    const char *order_types_nul_ptr,
    size_t order_types_nul_len,
    size_t count,
    const char *base_url_ptr,
    size_t base_url_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_admin_user_normalize_bulk_ids(
    const int64_t *in_ids,
    size_t count,
    int64_t *out_ids,
    size_t out_cap,
    size_t *out_unique_count
);
ssize_t nntmux_admin_user_format_passkey_wipe_nul(
    int64_t target_user_id,
    const char *target_username_ptr,
    size_t target_username_len,
    int64_t wiped_count,
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

    public static function resolveSignupErrorMessage(int $code): string
    {
        if (self::$useRustFfi) {
            return self::resolveSignupErrorMessageNative($code);
        }

        return self::resolveSignupErrorMessagePhp($code);
    }

    public static function resolveSignupErrorMessagePhp(int $code): string
    {
        return match ($code) {
            SignupError::BAD_USERNAME->value => 'Bad username. Try a better one.',
            SignupError::BAD_PASSWORD->value => 'Bad password. Try a longer one.',
            SignupError::BAD_EMAIL->value => 'Bad email.',
            SignupError::USERNAME_IN_USE->value => 'Username in use.',
            SignupError::EMAIL_IN_USE->value => 'Email in use.',
            default => 'Unknown save error.',
        };
    }

    public static function resolveSignupErrorMessageNative(int $code): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolveSignupErrorMessagePhp($code);
        }

        try {
            $cap = 64;
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_admin_user_signup_error_message($code, $outBuf, $cap);
            if (! is_int($written) || $written <= 0) {
                return self::resolveSignupErrorMessagePhp($code);
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::resolveSignupErrorMessagePhp($code);
        }
    }

    /**
     * @param  array<int, string>  $ordering
     * @return array<string, string>
     */
    public static function buildAdminUserOrderByUrls(array $ordering, ?string $baseUrl = null): array
    {
        $resolvedBaseUrl = $baseUrl ?? url('admin/user-list');
        if (self::$useRustFfi) {
            return self::buildAdminUserOrderByUrlsNative($ordering, $resolvedBaseUrl);
        }

        return self::buildAdminUserOrderByUrlsPhp($ordering, $resolvedBaseUrl);
    }

    /**
     * @param  array<int, string>  $ordering
     * @return array<string, string>
     */
    public static function buildAdminUserOrderByUrlsPhp(array $ordering, string $baseUrl = 'admin/user-list'): array
    {
        $orderByUrls = [];
        foreach ($ordering as $orderType) {
            $orderByUrls['orderby'.$orderType] = $baseUrl.'?ob='.$orderType;
        }

        return $orderByUrls;
    }

    /**
     * @param  array<int, string>  $ordering
     * @return array<string, string>
     */
    public static function buildAdminUserOrderByUrlsNative(array $ordering, string $baseUrl = 'admin/user-list'): array
    {
        if ($ordering === []) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildAdminUserOrderByUrlsPhp($ordering, $baseUrl);
        }

        foreach ($ordering as $orderType) {
            if (! is_string($orderType) || str_contains($orderType, "\0")) {
                return self::buildAdminUserOrderByUrlsPhp($ordering, $baseUrl);
            }
        }

        try {
            $count = count($ordering);
            $joined = implode("\0", $ordering);
            $cap = max(256, $count * (strlen($baseUrl) + 48) + strlen($joined) * 2);
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_admin_user_build_order_urls_nul(
                $joined,
                strlen($joined),
                $count,
                $baseUrl,
                strlen($baseUrl),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::buildAdminUserOrderByUrlsPhp($ordering, $baseUrl);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) !== $count * 2) {
                return self::buildAdminUserOrderByUrlsPhp($ordering, $baseUrl);
            }

            $urls = [];
            for ($i = 0; $i < $count; $i++) {
                $urls[$parts[$i * 2]] = $parts[$i * 2 + 1];
            }

            return $urls;
        } catch (Throwable) {
            return self::buildAdminUserOrderByUrlsPhp($ordering, $baseUrl);
        }
    }

    /**
     * @param  array<int|string, mixed>  $userIds
     * @return list<int>
     */
    public static function normalizeBulkUserIds(array $userIds): array
    {
        if (self::$useRustFfi) {
            return self::normalizeBulkUserIdsNative($userIds);
        }

        return self::normalizeBulkUserIdsPhp($userIds);
    }

    /**
     * @param  array<int|string, mixed>  $userIds
     * @return list<int>
     */
    public static function normalizeBulkUserIdsPhp(array $userIds): array
    {
        return array_values(array_unique(array_map('intval', $userIds)));
    }

    /**
     * @param  array<int|string, mixed>  $userIds
     * @return list<int>
     */
    public static function normalizeBulkUserIdsNative(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::normalizeBulkUserIdsPhp($userIds);
        }

        try {
            $intValues = array_values(array_map('intval', $userIds));
            $count = count($intValues);
            $inIds = $ffi->new("int64_t[{$count}]");
            $outIds = $ffi->new("int64_t[{$count}]");
            $outUniqueCount = $ffi->new('size_t[1]');

            for ($i = 0; $i < $count; $i++) {
                $inIds[$i] = $intValues[$i];
            }

            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_admin_user_normalize_bulk_ids(
                $inIds,
                $count,
                $outIds,
                $count,
                $outUniqueCount
            );
            $uniqueCount = $outUniqueCount[0];
            if ($rc !== 1 || ! is_int($uniqueCount) || $uniqueCount < 0 || $uniqueCount > $count) {
                return self::normalizeBulkUserIdsPhp($userIds);
            }

            $result = [];
            for ($i = 0; $i < $uniqueCount; $i++) {
                $val = $outIds[$i];
                if (! is_int($val)) {
                    return self::normalizeBulkUserIdsPhp($userIds);
                }
                $result[] = $val;
            }

            return $result;
        } catch (Throwable) {
            return self::normalizeBulkUserIdsPhp($userIds);
        }
    }

    /**
     * @return array{
     *     log_message: string,
     *     audit_context: array{
     *         admin_user_id: int|null,
     *         target_user_id: int,
     *         target_username: string,
     *         wiped_count: int
     *     },
     *     message: string,
     *     redirect_url: string
     * }
     */
    public static function formatPasskeyWipeOutcome(
        ?int $adminUserId,
        int $targetUserId,
        string $targetUsername,
        int $wipedCount
    ): array {
        if (self::$useRustFfi) {
            return self::formatPasskeyWipeOutcomeNative($adminUserId, $targetUserId, $targetUsername, $wipedCount);
        }

        return self::formatPasskeyWipeOutcomePhp($adminUserId, $targetUserId, $targetUsername, $wipedCount);
    }

    /**
     * @return array{
     *     log_message: string,
     *     audit_context: array{
     *         admin_user_id: int|null,
     *         target_user_id: int,
     *         target_username: string,
     *         wiped_count: int
     *     },
     *     message: string,
     *     redirect_url: string
     * }
     */
    public static function formatPasskeyWipeOutcomePhp(
        ?int $adminUserId,
        int $targetUserId,
        string $targetUsername,
        int $wipedCount
    ): array {
        return [
            'log_message' => 'Admin wiped user passkeys',
            'audit_context' => [
                'admin_user_id' => $adminUserId,
                'target_user_id' => $targetUserId,
                'target_username' => $targetUsername,
                'wiped_count' => $wipedCount,
            ],
            'message' => "{$wipedCount} passkeys removed from {$targetUsername}. They can now regain access with their password or an admin-issued reset.",
            'redirect_url' => 'admin/user-edit?id='.$targetUserId,
        ];
    }

    /**
     * @return array{
     *     log_message: string,
     *     audit_context: array{
     *         admin_user_id: int|null,
     *         target_user_id: int,
     *         target_username: string,
     *         wiped_count: int
     *     },
     *     message: string,
     *     redirect_url: string
     * }
     */
    public static function formatPasskeyWipeOutcomeNative(
        ?int $adminUserId,
        int $targetUserId,
        string $targetUsername,
        int $wipedCount
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatPasskeyWipeOutcomePhp($adminUserId, $targetUserId, $targetUsername, $wipedCount);
        }

        try {
            $cap = max(256, strlen($targetUsername) + 224);
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_admin_user_format_passkey_wipe_nul(
                $targetUserId,
                $targetUsername,
                strlen($targetUsername),
                $wipedCount,
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatPasskeyWipeOutcomePhp($adminUserId, $targetUserId, $targetUsername, $wipedCount);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 3) {
                return self::formatPasskeyWipeOutcomePhp($adminUserId, $targetUserId, $targetUsername, $wipedCount);
            }

            return [
                'log_message' => $parts[0],
                'audit_context' => [
                    'admin_user_id' => $adminUserId,
                    'target_user_id' => $targetUserId,
                    'target_username' => $targetUsername,
                    'wiped_count' => $wipedCount,
                ],
                'message' => $parts[1],
                'redirect_url' => $parts[2],
            ];
        } catch (Throwable) {
            return self::formatPasskeyWipeOutcomePhp($adminUserId, $targetUserId, $targetUsername, $wipedCount);
        }
    }

    /**
     * @throws Throwable
     */
    public function index(AdminUserListRequest $request): View
    {
        $this->setAdminPrefs();

        $meta_title = $title = 'User List';

        $roles = Role::query()->orderBy('name')->pluck('name', 'id')->toArray();

        $ordering = getUserBrowseOrdering();
        $orderBy = $request->orderBy();
        $variables = $request->filters();

        $results = User::adminListPaginator(
            $variables,
            $orderBy,
            (int) config('nntmux.items_per_page'),
        )->withQueryString();

        // Build order by URLs
        $orderByUrls = self::buildAdminUserOrderByUrls($ordering);

        $this->viewData = array_merge($this->viewData, [
            'username' => $variables['username'],
            'email' => $variables['email'],
            'host' => $variables['host'],
            'role' => $variables['role'],
            'verified' => $variables['verified'],
            'created_from' => $variables['created_from'],
            'created_to' => $variables['created_to'],
            'role_ids' => array_keys($roles),
            'role_names' => $roles,
            'userlist' => $results,
            'title' => $title,
            'meta_title' => $meta_title,
        ], $orderByUrls);

        return view('admin.users.index', $this->viewData);
    }

    /**
     * @return RedirectResponse|\Illuminate\View\View
     *
     * @throws \Exception|Throwable
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();

        $user = [
            'id' => '',
            'username' => '',
            'email' => '',
            'password' => '',
            'role' => UserRole::USER->value,
            'notes' => '',
            'rate_limit' => 60,
        ];

        $meta_title = $title = 'View User';

        // set the current action
        $action = $this->formAction($request, ['view', 'add']);

        $roleId = null;
        if ($action === 'submit') {
            $validated = $request->validate([
                'role' => ['required', 'integer', 'exists:roles,id'],
            ]);
            $roleId = (int) $validated['role'];
        }

        // get the user roles
        $userRoles = Role::cursor()->remember();
        $roles = [];
        $defaultRole = UserRole::USER->value;
        $defaultInvites = Invitation::DEFAULT_INVITES;
        foreach ($userRoles as $r) {
            $roles[$r->id] = $r->name;
            if ($r->isdefault === 1) {
                $defaultRole = $r->id;
                $defaultInvites = $r->defaultinvites;
            }
        }

        $error = null;

        switch ($action) {
            case 'add':
                $user += [
                    'role' => $defaultRole,
                    'notes' => '',
                    'invites' => $defaultInvites,
                    'movieview' => 0,
                    'musicview' => 0,
                    'consoleview' => 0,
                    'gameview' => 0,
                    'bookview' => 0,
                ];
                break;
            case 'submit':
                if (empty($request->input('id'))) {
                    $invites = $defaultInvites;
                    foreach ($userRoles as $role) {
                        if ((int) $role['id'] === $roleId) {
                            $invites = $role['defaultinvites'];
                        }
                    }
                    $ret = User::signUp($request->input('username'), $request->input('password'), $request->input('email'), '', $request->input('notes'), $invites, '', true, $roleId, false);
                } else {
                    $editedUser = User::find($request->input('id'));

                    // Check if role is changing and get stack preference
                    $roleChanged = (int) $editedUser->roles_id !== $roleId;
                    $stackRole = $request->input('stack_role') ? true : false; // Check if checkbox is checked
                    $changedBy = Auth::check() ? Auth::id() : null;

                    // CRITICAL: Capture the ORIGINAL rolechangedate BEFORE any updates
                    // This is needed for accurate role history tracking
                    // Convert to string to avoid any Carbon object reference issues
                    $originalRoleChangeDate = $editedUser->rolechangedate
                        ? $editedUser->rolechangedate->toDateTimeString()
                        : null;

                    Log::info('AdminUserController - Before updates', [
                        'user_id' => $editedUser->id,
                        'originalRoleChangeDate' => $originalRoleChangeDate,
                        'current_roles_id' => $editedUser->roles_id,
                        'requested_role' => $roleId,
                        'roleChanged' => $roleChanged,
                        'stackRole' => $stackRole,
                        'form_rolechangedate' => $request->input('rolechangedate'),
                    ]);

                    // Handle pending role cancellation
                    if ($request->has('cancel_pending_role') && $request->input('cancel_pending_role')) {
                        $editedUser->cancelPendingRole();
                    }

                    // Handle rolechangedate - Update the expiry for the CURRENT role FIRST
                    // This must happen BEFORE role change so the new expiry applies to the old role
                    $adminManuallySetExpiry = false;
                    if ($request->has('rolechangedate')) {
                        $roleChangeDate = $request->input('rolechangedate');
                        if (! empty($roleChangeDate)) {
                            User::updateUserRoleChangeDate($editedUser->id, $roleChangeDate);
                            $adminManuallySetExpiry = true; // Flag that admin set custom expiry
                        } else {
                            // Clear the rolechangedate if empty string is provided
                            $editedUser->update(['rolechangedate' => null]);
                        }
                        $editedUser->refresh();

                        Log::info('AdminUserController - After expiry update', [
                            'user_id' => $editedUser->id,
                            'new_rolechangedate' => $editedUser->rolechangedate,
                            'adminManuallySetExpiry' => $adminManuallySetExpiry,
                        ]);
                    }

                    // If role is changing, handle it with stacking logic
                    // Pass the original expiry so history records the correct old_expiry_date
                    if ($roleChanged) {
                        Log::info('AdminUserController - About to call updateUserRole', [
                            'user_id' => $editedUser->id,
                            'new_role' => $roleId,
                            'originalRoleChangeDate_passed' => $originalRoleChangeDate,
                            'current_user_rolechangedate' => $editedUser->rolechangedate,
                        ]);

                        User::updateUserRole(
                            $editedUser->id,
                            $roleId,
                            ! $adminManuallySetExpiry, // Only apply promotions if admin didn't set custom expiry
                            $stackRole, // Stack role if requested
                            $changedBy,
                            $originalRoleChangeDate, // Pass original expiry for history
                            $adminManuallySetExpiry // Preserve admin's manually set expiry date
                        );
                        $editedUser->refresh();
                    }
                    // Note: We don't call updateUserRole when role hasn't changed
                    // If admin manually set a rolechangedate, that's already applied above

                    // Update user basic information (but NOT the role - it's handled above)
                    // Use current role to avoid overwriting
                    $ret = User::updateUser(
                        $editedUser->id,
                        $request->input('username'),
                        $request->input('email'),
                        $editedUser->grabs,
                        $editedUser->roles_id, // Use current role, not the request role
                        $request->input('notes'),
                        (int) $request->input('invites', 0),
                        ($request->has('movieview') ? 1 : 0),
                        ($request->has('musicview') ? 1 : 0),
                        ($request->has('gameview') ? 1 : 0),
                        ($editedUser->xxxview ? 1 : 0),
                        ($request->has('consoleview') ? 1 : 0),
                        ($request->has('bookview') ? 1 : 0)
                    );

                    if ($request->input('password') !== null) {
                        User::updatePassword($editedUser->id, $request->input('password'));
                    }
                }

                if ($ret >= 0) {
                    return redirect()->to('admin/user-list');
                }

                $error = self::resolveSignupErrorMessage((int) $ret);
                $user += [
                    'id' => $request->input('id'),
                    'username' => $request->input('username'),
                    'email' => $request->input('email'),
                    'role' => $roleId,
                    'notes' => $request->input('notes'),
                ];
                break;
            case 'view':
            default:
                if ($request->has('id')) {
                    $title = 'User Edit';
                    $id = $request->input('id');
                    $user = User::find($id);

                    // Add daily API and download counts
                    if ($user) {
                        $user->load('passkeys');

                        try {
                            $user->daily_api_count = UserRequest::getApiRequests($user->id);
                            $user->daily_download_count = UserDownload::getDownloadRequests($user->id);
                        } catch (\Exception $e) {
                            $user->daily_api_count = 0;
                            $user->daily_download_count = 0;
                        }
                    }
                }

                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'yesno_ids' => [1, 0],
            'yesno_names' => ['Yes', 'No'],
            'role_ids' => array_keys($roles),
            'role_names' => $roles,
            'user' => $user,
            'error' => $error,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.users.edit', $this->viewData);
    }

    public function bulkAction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:delete,verify,resend_verification'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $userIds = self::normalizeBulkUserIds($validated['user_ids']);

        if ($validated['action'] === 'delete') {
            $count = $this->bulkActionUsersQuery($userIds)->delete();

            Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);

            return redirect()->back()->with('success', $count.' user(s) soft-deleted successfully.');
        }

        if ($validated['action'] === 'verify') {
            $count = 0;
            $this->bulkActionUsersQuery($userIds)
                ->where('verified', false)
                ->whereNull('email_verified_at')
                ->get()
                ->each(function (User $user) use (&$count): void {
                    if ($user->markEmailAsVerified()) {
                        $count++;
                    }
                });

            return redirect()->back()->with('success', $count.' user(s) marked as verified successfully.');
        }

        $count = 0;
        $this->bulkActionUsersQuery($userIds)
            ->where('verified', false)
            ->whereNull('email_verified_at')
            ->get()
            ->each(function (User $user) use (&$count): void {
                $user->sendEmailVerificationNotification();
                $count++;
            });

        return redirect()->back()->with('success', $count.' verification email(s) queued successfully.');
    }

    /**
     * @param  list<int>  $userIds
     * @return Builder<User>
     */
    private function bulkActionUsersQuery(array $userIds): Builder
    {
        $adminRoleName = 'Admin';
        $adminRoleIds = Role::query()
            ->where('name', $adminRoleName)
            ->pluck('id')
            ->all();

        return User::query()
            ->whereIn('id', $userIds)
            ->when($adminRoleIds !== [], fn (Builder $query): Builder => $query->whereNotIn('roles_id', $adminRoleIds))
            ->whereDoesntHave('role', fn (Builder $query): Builder => $query->where('name', $adminRoleName))
            ->whereDoesntHave('roles', fn (Builder $query): Builder => $query->where('name', $adminRoleName));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->has('id')) {
            $user = User::find($request->input('id'));
            if ($user === null) {
                return redirect()->back();
            }
            $username = $user->username;

            $user->delete();
            Cache::forget(AdminDashboardSnapshotService::CACHE_KEY);

            return redirect()
                ->route('admin.user-list')
                ->with('success', 'User "'.$username.'" has been deleted successfully.');
        }

        if ($request->has('redir')) {
            return redirect()->to($request->input('redir'));
        }

        return redirect()->to($request->server('HTTP_REFERER'));
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        if ($request->has('id')) {
            $user = User::find($request->input('id'));
            if ($user === null) {
                return redirect()->back()->with('error', 'User is invalid');
            }

            $user->sendEmailVerificationNotification();

            return redirect()->back()->with('success', 'Email verification for '.$user->username.' sent');
        }

        return redirect()->back()->with('error', 'User is invalid');
    }

    public function verify(Request $request): RedirectResponse
    {
        if ($request->has('id')) {
            $user = User::find($request->input('id'));
            if ($user === null) {
                return redirect()->back()->with('error', 'User is invalid');
            }

            $user->markEmailAsVerified();

            return redirect()->back()->with('success', 'Email verification for '.$user->username.' completed');
        }

        return redirect()->back()->with('error', 'User is invalid');
    }

    public function destroyPasskey(Request $request, Passkey $passkey): RedirectResponse|JsonResponse
    {
        $targetUser = $passkey->authenticatable;
        $targetUserId = $targetUser?->id;
        $targetUsername = $targetUser?->username;
        $passkeyName = $passkey->name;

        $passkey->delete();

        Log::channel('admin')->info('Admin deleted user passkey', [
            'admin_user_id' => Auth::id(),
            'target_user_id' => $targetUserId,
            'target_username' => $targetUsername,
            'passkey_id' => $passkey->id,
            'passkey_name' => $passkeyName,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        $redirectUrl = $targetUserId
            ? 'admin/user-edit?id='.$targetUserId
            : 'admin/user-list';

        return redirect()->to($redirectUrl)->with('success', 'Passkey deleted successfully.');
    }

    public function wipePasskeys(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'confirmation' => ['required', 'string', 'in:WIPE'],
        ]);

        $target = User::findOrFail((int) $validated['user_id']);
        $wipedCount = $target->passkeys()->count();
        $target->passkeys()->delete();

        $adminId = Auth::id();
        $outcome = self::formatPasskeyWipeOutcome(
            is_int($adminId) ? $adminId : (is_numeric($adminId) ? (int) $adminId : null),
            (int) $target->id,
            (string) $target->username,
            $wipedCount
        );

        Log::channel('admin')->warning($outcome['log_message'], $outcome['audit_context']);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'wiped_count' => $wipedCount,
                'message' => $outcome['message'],
            ]);
        }

        return redirect()->to($outcome['redirect_url'])->with('success', $outcome['message']);
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
