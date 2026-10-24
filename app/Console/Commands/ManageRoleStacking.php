<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserRoleHistory;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Throwable;

class ManageRoleStacking extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_role_stacking_resolve_action_nul(
    const char *action_ptr,
    size_t action_len,
    int32_t *out_is_valid,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_role_stacking_format_pending_row_nul(
    int64_t user_id,
    const char *username_ptr,
    size_t username_len,
    uint8_t has_current_role,
    const char *current_role_ptr,
    size_t current_role_len,
    uint8_t has_pending_role,
    const char *pending_role_ptr,
    size_t pending_role_len,
    const char *formatted_date_ptr,
    size_t formatted_date_len,
    const char *time_until_ptr,
    size_t time_until_len,
    int64_t *out_user_id,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_role_stacking_format_history_row_nul(
    const char *formatted_date_ptr,
    size_t formatted_date_len,
    uint8_t has_old_role,
    const char *old_role_ptr,
    size_t old_role_len,
    uint8_t has_new_role,
    const char *new_role_ptr,
    size_t new_role_len,
    uint8_t is_stacked,
    uint8_t has_change_reason,
    const char *change_reason_ptr,
    size_t change_reason_len,
    uint8_t has_changed_by,
    const char *changed_by_ptr,
    size_t changed_by_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_role_stacking_classify_identifier_nul(
    uint8_t is_int_input,
    int64_t int_val,
    const char *str_ptr,
    size_t str_len,
    int32_t *out_is_numeric,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'role:manage
                          {action : Action to perform (list-pending, cancel-pending, history, activate-pending)}
                          {user? : User ID or username}
                          {--all : Apply to all users}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage role stacking for users';

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
     * @return array{
     *     route: string,
     *     is_valid: bool,
     *     error_message: string|null
     * }
     */
    public static function resolveActionRoute(string $action): array
    {
        if (self::$useRustFfi) {
            return self::resolveActionRouteNative($action);
        }

        return self::resolveActionRoutePhp($action);
    }

    /**
     * @return array{
     *     route: string,
     *     is_valid: bool,
     *     error_message: string|null
     * }
     */
    public static function resolveActionRoutePhp(string $action): array
    {
        return match ($action) {
            'list-pending', 'cancel-pending', 'history', 'activate-pending' => [
                'route' => $action,
                'is_valid' => true,
                'error_message' => null,
            ],
            default => [
                'route' => 'unknown',
                'is_valid' => false,
                'error_message' => "Unknown action: {$action}",
            ],
        };
    }

    /**
     * @return array{
     *     route: string,
     *     is_valid: bool,
     *     error_message: string|null
     * }
     */
    public static function resolveActionRouteNative(string $action): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolveActionRoutePhp($action);
        }

        try {
            $cap = max(96, strlen($action) + 48);
            $outIsValid = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_role_stacking_resolve_action_nul(
                $action,
                strlen($action),
                $outIsValid,
                $outBuf,
                $cap
            );
            $isValid = $outIsValid[0];
            if (! is_int($written) || $written <= 0 || ! is_int($isValid)) {
                return self::resolveActionRoutePhp($action);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) !== 2) {
                return self::resolveActionRoutePhp($action);
            }

            return [
                'route' => $parts[0],
                'is_valid' => $isValid === 1,
                'error_message' => $isValid === 1 ? null : $parts[1],
            ];
        } catch (Throwable) {
            return self::resolveActionRoutePhp($action);
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    public static function formatPendingRoleRow(
        int $userId,
        string $username,
        ?string $currentRoleName,
        ?string $pendingRoleName,
        string $formattedDate,
        string $timeUntil
    ): array {
        if (self::$useRustFfi) {
            return self::formatPendingRoleRowNative(
                $userId,
                $username,
                $currentRoleName,
                $pendingRoleName,
                $formattedDate,
                $timeUntil
            );
        }

        return self::formatPendingRoleRowPhp(
            $userId,
            $username,
            $currentRoleName,
            $pendingRoleName,
            $formattedDate,
            $timeUntil
        );
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    public static function formatPendingRoleRowPhp(
        int $userId,
        string $username,
        ?string $currentRoleName,
        ?string $pendingRoleName,
        string $formattedDate,
        string $timeUntil
    ): array {
        return [
            $userId,
            $username,
            $currentRoleName ?? 'None',
            $pendingRoleName ?? 'Unknown',
            $formattedDate,
            $timeUntil,
        ];
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    public static function formatPendingRoleRowNative(
        int $userId,
        string $username,
        ?string $currentRoleName,
        ?string $pendingRoleName,
        string $formattedDate,
        string $timeUntil
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatPendingRoleRowPhp(
                $userId,
                $username,
                $currentRoleName,
                $pendingRoleName,
                $formattedDate,
                $timeUntil
            );
        }

        try {
            $currStr = $currentRoleName ?? '';
            $pendStr = $pendingRoleName ?? '';
            $cap = max(
                128,
                strlen($username) + strlen($currStr) + strlen($pendStr) + strlen($formattedDate) + strlen($timeUntil) + 48
            );
            $outUserId = $ffi->new('int64_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_role_stacking_format_pending_row_nul(
                $userId,
                $username,
                strlen($username),
                $currentRoleName !== null ? 1 : 0,
                $currStr,
                strlen($currStr),
                $pendingRoleName !== null ? 1 : 0,
                $pendStr,
                strlen($pendStr),
                $formattedDate,
                strlen($formattedDate),
                $timeUntil,
                strlen($timeUntil),
                $outUserId,
                $outBuf,
                $cap
            );
            $resolvedId = $outUserId[0];
            if (! is_int($written) || $written <= 0 || ! is_int($resolvedId)) {
                return self::formatPendingRoleRowPhp(
                    $userId,
                    $username,
                    $currentRoleName,
                    $pendingRoleName,
                    $formattedDate,
                    $timeUntil
                );
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) !== 5) {
                return self::formatPendingRoleRowPhp(
                    $userId,
                    $username,
                    $currentRoleName,
                    $pendingRoleName,
                    $formattedDate,
                    $timeUntil
                );
            }

            return [
                $resolvedId,
                $parts[0],
                $parts[1],
                $parts[2],
                $parts[3],
                $parts[4],
            ];
        } catch (Throwable) {
            return self::formatPendingRoleRowPhp(
                $userId,
                $username,
                $currentRoleName,
                $pendingRoleName,
                $formattedDate,
                $timeUntil
            );
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    public static function formatRoleHistoryRow(
        string $formattedDate,
        ?string $oldRoleName,
        ?string $newRoleName,
        bool $isStacked,
        ?string $changeReason,
        ?string $changedByUsername
    ): array {
        if (self::$useRustFfi) {
            return self::formatRoleHistoryRowNative(
                $formattedDate,
                $oldRoleName,
                $newRoleName,
                $isStacked,
                $changeReason,
                $changedByUsername
            );
        }

        return self::formatRoleHistoryRowPhp(
            $formattedDate,
            $oldRoleName,
            $newRoleName,
            $isStacked,
            $changeReason,
            $changedByUsername
        );
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    public static function formatRoleHistoryRowPhp(
        string $formattedDate,
        ?string $oldRoleName,
        ?string $newRoleName,
        bool $isStacked,
        ?string $changeReason,
        ?string $changedByUsername
    ): array {
        return [
            $formattedDate,
            $oldRoleName ?? 'None',
            $newRoleName ?? 'Unknown',
            $isStacked ? 'Stacked' : 'Immediate',
            $changeReason ?? 'N/A',
            $changedByUsername ?? 'System',
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    public static function formatRoleHistoryRowNative(
        string $formattedDate,
        ?string $oldRoleName,
        ?string $newRoleName,
        bool $isStacked,
        ?string $changeReason,
        ?string $changedByUsername
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatRoleHistoryRowPhp(
                $formattedDate,
                $oldRoleName,
                $newRoleName,
                $isStacked,
                $changeReason,
                $changedByUsername
            );
        }

        try {
            $oldStr = $oldRoleName ?? '';
            $newStr = $newRoleName ?? '';
            $reasonStr = $changeReason ?? '';
            $byStr = $changedByUsername ?? '';
            $cap = max(
                128,
                strlen($formattedDate) + strlen($oldStr) + strlen($newStr) + strlen($reasonStr) + strlen($byStr) + 64
            );
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_role_stacking_format_history_row_nul(
                $formattedDate,
                strlen($formattedDate),
                $oldRoleName !== null ? 1 : 0,
                $oldStr,
                strlen($oldStr),
                $newRoleName !== null ? 1 : 0,
                $newStr,
                strlen($newStr),
                $isStacked ? 1 : 0,
                $changeReason !== null ? 1 : 0,
                $reasonStr,
                strlen($reasonStr),
                $changedByUsername !== null ? 1 : 0,
                $byStr,
                strlen($byStr),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatRoleHistoryRowPhp(
                    $formattedDate,
                    $oldRoleName,
                    $newRoleName,
                    $isStacked,
                    $changeReason,
                    $changedByUsername
                );
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) !== 6) {
                return self::formatRoleHistoryRowPhp(
                    $formattedDate,
                    $oldRoleName,
                    $newRoleName,
                    $isStacked,
                    $changeReason,
                    $changedByUsername
                );
            }

            return [
                $parts[0],
                $parts[1],
                $parts[2],
                $parts[3],
                $parts[4],
                $parts[5],
            ];
        } catch (Throwable) {
            return self::formatRoleHistoryRowPhp(
                $formattedDate,
                $oldRoleName,
                $newRoleName,
                $isStacked,
                $changeReason,
                $changedByUsername
            );
        }
    }

    /**
     * @return array{
     *     is_numeric: bool,
     *     lookup_field: string,
     *     not_found_message: string
     * }
     */
    public static function classifyUserIdentifier(string|int $identifier): array
    {
        if (self::$useRustFfi) {
            return self::classifyUserIdentifierNative($identifier);
        }

        return self::classifyUserIdentifierPhp($identifier);
    }

    /**
     * @return array{
     *     is_numeric: bool,
     *     lookup_field: string,
     *     not_found_message: string
     * }
     */
    public static function classifyUserIdentifierPhp(string|int $identifier): array
    {
        $isNumeric = is_numeric($identifier);

        return [
            'is_numeric' => $isNumeric,
            'lookup_field' => $isNumeric ? 'id' : 'username',
            'not_found_message' => "User not found: {$identifier}",
        ];
    }

    /**
     * @return array{
     *     is_numeric: bool,
     *     lookup_field: string,
     *     not_found_message: string
     * }
     */
    public static function classifyUserIdentifierNative(string|int $identifier): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::classifyUserIdentifierPhp($identifier);
        }

        try {
            $isInt = is_int($identifier);
            $intVal = $isInt ? $identifier : 0;
            $strVal = $isInt ? '' : $identifier;
            $cap = max(96, strlen($strVal) + 48);
            $outIsNumeric = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_role_stacking_classify_identifier_nul(
                $isInt ? 1 : 0,
                $intVal,
                $strVal,
                strlen($strVal),
                $outIsNumeric,
                $outBuf,
                $cap
            );
            $isNumFlag = $outIsNumeric[0];
            if (! is_int($written) || $written <= 0 || ! is_int($isNumFlag)) {
                return self::classifyUserIdentifierPhp($identifier);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) !== 2) {
                return self::classifyUserIdentifierPhp($identifier);
            }

            return [
                'is_numeric' => $isNumFlag === 1,
                'lookup_field' => $parts[0],
                'not_found_message' => $parts[1],
            ];
        } catch (Throwable) {
            return self::classifyUserIdentifierPhp($identifier);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $resolved = self::resolveActionRoute($action);

        return match ($resolved['route']) {
            'list-pending' => $this->listPendingRoles(),
            'cancel-pending' => $this->cancelPendingRole(),
            'history' => $this->showHistory(),
            'activate-pending' => $this->activatePendingRoles(),
            default => $this->handleUnknownAction($action),
        };
    }

    /**
     * Handle unknown action
     */
    protected function handleUnknownAction(string $action): int
    {
        $resolved = self::resolveActionRoute($action);
        $this->error($resolved['error_message'] ?? "Unknown action: {$action}");

        return 1;
    }

    /**
     * List all users with pending roles
     */
    protected function listPendingRoles(): int
    {
        $users = User::whereNotNull('pending_roles_id')
            ->whereNotNull('pending_role_start_date')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No users with pending roles found.');

            return 0;
        }

        $this->info('Users with Pending Roles:');
        $this->newLine();

        $headers = ['User ID', 'Username', 'Current Role', 'Pending Role', 'Activation Date', 'Time Until'];
        $rows = [];

        foreach ($users as $user) {
            /** @var Role|null $currentRole */
            $currentRole = $user->roles->first();
            $pendingRole = $user->getPendingRole();
            $activationDate = Carbon::parse($user->pending_role_start_date);

            $rows[] = self::formatPendingRoleRow(
                (int) $user->id,
                (string) $user->username,
                $currentRole ? $currentRole->name : null,
                $pendingRole ? $pendingRole->name : null,
                $activationDate->format('Y-m-d H:i'),
                $activationDate->diffForHumans(),
            );
        }

        $this->table($headers, $rows);
        $this->info(sprintf('Total: %d users', count($rows)));

        return 0;
    }

    /**
     * Cancel pending role for user(s)
     */
    protected function cancelPendingRole(): int
    {
        if ($this->option('all')) {
            if (! $this->confirm('Are you sure you want to cancel ALL pending roles?')) {
                $this->info('Operation cancelled.');

                return 0;
            }

            $users = User::whereNotNull('pending_roles_id')->get();
            $count = 0;

            foreach ($users as $user) {
                if ($user->cancelPendingRole()) {
                    $count++;
                    $this->info("Cancelled pending role for user: {$user->username}");
                }
            }

            $this->info("Cancelled pending roles for {$count} users.");

            return 0;
        }

        $userId = $this->argument('user');
        if (! $userId) {
            $this->error('User ID or username required. Use --all to cancel for all users.');

            return 1;
        }

        $user = $this->findUser($userId);
        if (! $user) {
            return 1;
        }

        if (! $user->hasPendingRole()) {
            $this->info("User {$user->username} has no pending role.");

            return 0;
        }

        $pendingRole = $user->getPendingRole();
        if (! $this->confirm("Cancel pending role '{$pendingRole->name}' for user '{$user->username}'?")) {
            $this->info('Operation cancelled.');

            return 0;
        }

        if ($user->cancelPendingRole()) {
            $this->info("Successfully cancelled pending role for user: {$user->username}");

            return 0;
        }

        $this->error('Failed to cancel pending role.');

        return 1;
    }

    /**
     * Show role change history for user(s)
     */
    protected function showHistory(): int
    {
        $userId = $this->argument('user');
        if (! $userId) {
            $this->error('User ID or username required.');

            return 1;
        }

        $user = $this->findUser($userId);
        if (! $user) {
            return 1;
        }

        $history = UserRoleHistory::getUserHistory($user->id);

        if ($history->isEmpty()) {
            $this->info("No role change history found for user: {$user->username}");

            return 0;
        }

        $this->info("Role Change History for: {$user->username} (ID: {$user->id})");
        $this->newLine();

        $headers = ['Date', 'From Role', 'To Role', 'Type', 'Reason', 'Changed By'];
        $rows = [];

        foreach ($history as $change) {
            $oldRole = $change->old_role_id ? Role::find($change->old_role_id) : null;
            $newRole = Role::find($change->new_role_id);
            $changedBy = $change->changed_by ? User::find($change->changed_by) : null;

            $rows[] = self::formatRoleHistoryRow(
                $change->effective_date->format('Y-m-d H:i'),
                $oldRole ? $oldRole->name : null,
                $newRole ? $newRole->name : null,
                (bool) $change->is_stacked,
                $change->change_reason,
                $changedBy ? $changedBy->username : null,
            );
        }

        $this->table($headers, $rows);
        $this->info(sprintf('Total changes: %d', count($rows)));

        // Show stacked changes summary
        $stackedCount = $history->where('is_stacked', true)->count();
        if ($stackedCount > 0) {
            $this->newLine();
            $this->info("Stacked role changes: {$stackedCount}");
        }

        return 0;
    }

    /**
     * Manually activate pending roles (normally done automatically)
     */
    protected function activatePendingRoles(): int
    {
        $this->warn('This command manually activates pending roles that are scheduled for activation.');
        $this->warn('Normally, this is done automatically by the scheduled task.');
        $this->newLine();

        if (! $this->confirm('Do you want to continue?')) {
            $this->info('Operation cancelled.');

            return 0;
        }

        $now = Carbon::now();
        $users = User::whereNotNull('pending_roles_id')
            ->whereNotNull('pending_role_start_date')
            ->where('pending_role_start_date', '<=', $now)
            ->get();

        if ($users->isEmpty()) {
            $this->info('No pending roles ready for activation.');

            return 0;
        }

        $this->info(sprintf('Found %d pending roles ready for activation:', $users->count()));
        $this->newLine();

        $activated = 0;
        $failed = 0;

        foreach ($users as $user) {
            $pendingRole = $user->getPendingRole();
            /** @var Role|null $oldRole */
            $oldRole = $user->roles->first();

            try {
                // Activate the pending role
                $user->update([
                    'roles_id' => $user->pending_roles_id,
                    'pending_roles_id' => null,
                    'pending_role_start_date' => null,
                ]);

                if ($pendingRole) {
                    $user->syncRoles([$pendingRole->name]);
                }

                // Record in history
                UserRoleHistory::recordRoleChange(
                    userId: $user->id,
                    oldRoleId: $oldRole ? $oldRole->id : null,
                    newRoleId: $pendingRole ? $pendingRole->id : $user->roles_id,
                    oldExpiryDate: null,
                    newExpiryDate: null,
                    effectiveDate: $now,
                    isStacked: true,
                    changeReason: 'manual_activation',
                    changedBy: null
                );

                $this->info(sprintf(
                    '✓ Activated %s -> %s for user: %s',
                    $oldRole ? $oldRole->name : 'None',
                    $pendingRole ? $pendingRole->name : 'Unknown',
                    $user->username
                ));

                $activated++;
            } catch (\Exception $e) {
                $this->error(sprintf(
                    '✗ Failed to activate role for user %s: %s',
                    $user->username,
                    $e->getMessage()
                ));
                $failed++;
            }
        }

        $this->newLine();
        $this->info('Activation complete:');
        $this->info("  Successful: {$activated}");
        if ($failed > 0) {
            $this->error("  Failed: {$failed}");
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Find user by ID or username
     */
    protected function findUser(string|int $identifier): ?User
    {
        $classification = self::classifyUserIdentifier($identifier);

        $user = $classification['is_numeric']
            ? User::find($identifier)
            : User::where('username', $identifier)->first();

        if (! $user) {
            $this->error($classification['not_found_message']);

            return null;
        }

        return $user;
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
