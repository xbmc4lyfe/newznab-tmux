<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SendAccountChangedEmail;
use App\Models\User;
use App\Models\UserActivity;
use App\Support\RustPipelineLibrary;
use Spatie\Permission\Models\Role;

class UserActivityObserver
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_activity_observer_resolve_actor(
    uint8_t has_auth_username,
    const char *auth_username,
    size_t auth_username_len,
    uint8_t has_auth_id,
    int64_t auth_id,
    int64_t target_user_id,
    uint8_t check_self,
    uint8_t *out,
    size_t out_cap
);
ssize_t nntmux_user_activity_observer_fallback_role_name(
    uint8_t has_role_id,
    int64_t role_id,
    uint8_t *out,
    size_t out_cap
);
ssize_t nntmux_user_activity_observer_format_event(
    const char *event,
    size_t event_len,
    const char *username,
    size_t username_len,
    const char *role_name,
    size_t role_name_len,
    int32_t *out_event_code,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static \FFI|false|null $ffi = null;

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

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(\FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        UserActivity::create(self::buildActivityPayload(
            'created',
            (int) $user->id,
            (string) $user->username,
            [
                'email' => $user->email,
                'ip_address' => config('nntmux_settings.store_user_ip') ? request()->ip() : null,
            ]
        ));
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        // Check if the role was changed
        if ($user->isDirty('roles_id')) {
            $oldRoleId = $user->getOriginal('roles_id');
            $newRoleId = $user->roles_id;

            // Get role names
            $oldRoleName = $this->getRoleName($oldRoleId !== null ? (int) $oldRoleId : null);
            $newRoleName = $this->getRoleName($newRoleId !== null ? (int) $newRoleId : null);
            $updatedBy = self::resolveActorLabel(
                auth()->user()?->username,
                null,
                (int) $user->id,
                false
            );

            UserActivity::create(self::buildActivityPayload(
                'role_updated',
                (int) $user->id,
                (string) $user->username,
                [
                    'old_role_id' => $oldRoleId,
                    'new_role_id' => $newRoleId,
                    'old_role_name' => $oldRoleName,
                    'new_role_name' => $newRoleName,
                    'updated_by' => $updatedBy,
                ]
            ));

            // Send email notification to user about role change
            SendAccountChangedEmail::dispatch($user);
        }
    }

    /**
     * Handle the User "deleting" event (before soft delete).
     * Sets the deleted_by field to track who deleted the account.
     */
    public function deleting(User $user): void
    {
        $rawAuthId = auth()->check() ? auth()->id() : null;
        $authId = is_int($rawAuthId) ? $rawAuthId : (is_numeric($rawAuthId) ? (int) $rawAuthId : null);
        $deletedBy = self::resolveActorLabel(
            auth()->user()?->username,
            $authId,
            (int) $user->id,
            auth()->check() && $authId !== null
        );

        // Update the deleted_by field directly in the database
        // We use a direct query to avoid triggering update events
        User::withoutEvents(function () use ($user, $deletedBy) {
            User::withTrashed()
                ->where('id', $user->id)
                ->update(['deleted_by' => $deletedBy]);
        });

        // Store the deleted_by value on the model for the deleted event
        $user->deleted_by = $deletedBy;
    }

    /**
     * Handle the User "deleted" event (soft delete).
     */
    public function deleted(User $user): void
    {
        // Only log if it's a soft delete (not force delete)
        if ($user->trashed()) {
            UserActivity::create(self::buildActivityPayload(
                'deleted',
                (int) $user->id,
                (string) $user->username,
                [
                    'email' => $user->email,
                    'deleted_by' => $user->deleted_by ?? 'System',
                ]
            ));
        }
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        // Clear the deleted_by field when user is restored
        User::withoutEvents(function () use ($user) {
            User::where('id', $user->id)->update(['deleted_by' => null]);
        });

        $restoredBy = self::resolveActorLabel(
            auth()->user()?->username,
            null,
            (int) $user->id,
            false
        );

        UserActivity::create(self::buildActivityPayload(
            'restored',
            (int) $user->id,
            (string) $user->username,
            [
                'restored_by' => $restoredBy,
            ]
        ));
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
        $rawAuthId = auth()->check() ? auth()->id() : null;
        $authId = is_int($rawAuthId) ? $rawAuthId : (is_numeric($rawAuthId) ? (int) $rawAuthId : null);
        $deletedBy = self::resolveActorLabel(
            auth()->user()?->username,
            $authId,
            (int) $user->id,
            auth()->check() && $authId !== null
        );

        // Log permanent deletion
        UserActivity::create(self::buildActivityPayload(
            'force_deleted',
            null,
            (string) $user->username,
            [
                'email' => $user->email,
                'deleted_by' => $deletedBy,
            ]
        ));
    }

    public static function resolveActorLabel(
        ?string $authUsername,
        ?int $authId,
        int $targetUserId,
        bool $checkSelf
    ): string {
        if (self::$useRustFfi) {
            return self::resolveActorLabelNative($authUsername, $authId, $targetUserId, $checkSelf);
        }

        return self::resolveActorLabelPhp($authUsername, $authId, $targetUserId, $checkSelf);
    }

    public static function resolveActorLabelPhp(
        ?string $authUsername,
        ?int $authId,
        int $targetUserId,
        bool $checkSelf
    ): string {
        $actor = $authUsername ?? 'System';
        if ($checkSelf && $authId !== null && $authId === $targetUserId) {
            return 'Self';
        }

        return $actor;
    }

    public static function resolveActorLabelNative(
        ?string $authUsername,
        ?int $authId,
        int $targetUserId,
        bool $checkSelf
    ): string {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::resolveActorLabelPhp($authUsername, $authId, $targetUserId, $checkSelf);
        }

        try {
            $usernameStr = $authUsername ?? '';
            $cap = max(32, strlen($usernameStr) + 16);
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_activity_observer_resolve_actor(
                $authUsername !== null ? 1 : 0,
                $usernameStr,
                strlen($usernameStr),
                $authId !== null ? 1 : 0,
                $authId ?? 0,
                $targetUserId,
                $checkSelf ? 1 : 0,
                $out,
                $cap
            );
            if (! is_int($written) || $written < 0) {
                return self::resolveActorLabelPhp($authUsername, $authId, $targetUserId, $checkSelf);
            }
            if ($written === 0) {
                return '';
            }

            return \FFI::string($out, $written);
        } catch (\Throwable) {
            return self::resolveActorLabelPhp($authUsername, $authId, $targetUserId, $checkSelf);
        }
    }

    public static function formatFallbackRoleName(?int $roleId): string
    {
        if (self::$useRustFfi) {
            return self::formatFallbackRoleNameNative($roleId);
        }

        return self::formatFallbackRoleNamePhp($roleId);
    }

    public static function formatFallbackRoleNamePhp(?int $roleId): string
    {
        if (! $roleId) {
            return 'None';
        }

        return "Role #{$roleId}";
    }

    public static function formatFallbackRoleNameNative(?int $roleId): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::formatFallbackRoleNamePhp($roleId);
        }

        try {
            $cap = 32;
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_activity_observer_fallback_role_name(
                $roleId !== null ? 1 : 0,
                $roleId ?? 0,
                $out,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatFallbackRoleNamePhp($roleId);
            }

            return \FFI::string($out, $written);
        } catch (\Throwable) {
            return self::formatFallbackRoleNamePhp($roleId);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function buildActivityPayload(
        string $event,
        ?int $userId,
        string $username,
        array $context = []
    ): array {
        if (self::$useRustFfi) {
            return self::buildActivityPayloadNative($event, $userId, $username, $context);
        }

        return self::buildActivityPayloadPhp($event, $userId, $username, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function buildActivityPayloadPhp(
        string $event,
        ?int $userId,
        string $username,
        array $context = []
    ): array {
        $newRoleName = isset($context['new_role_name']) ? (string) $context['new_role_name'] : '';
        $summary = self::formatEventSummaryPhp($event, $username, $newRoleName);

        return self::assemblePayloadFromSummary($event, $userId, $username, $summary, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function buildActivityPayloadNative(
        string $event,
        ?int $userId,
        string $username,
        array $context = []
    ): array {
        $newRoleName = isset($context['new_role_name']) ? (string) $context['new_role_name'] : '';
        $summary = self::formatEventSummaryNative($event, $username, $newRoleName);

        return self::assemblePayloadFromSummary($event, $userId, $username, $summary, $context);
    }

    /**
     * @return array{activity_type: string, description: string, is_permanent: bool}
     */
    public static function formatEventSummaryPhp(
        string $event,
        string $username,
        string $newRoleName = ''
    ): array {
        return match ($event) {
            'created' => [
                'activity_type' => 'registered',
                'description' => "New user registered: {$username}",
                'is_permanent' => false,
            ],
            'role_updated' => [
                'activity_type' => 'role_updated',
                'description' => "User role updated: {$username} → {$newRoleName}",
                'is_permanent' => false,
            ],
            'deleted' => [
                'activity_type' => 'deleted',
                'description' => "User deleted: {$username}",
                'is_permanent' => false,
            ],
            'restored' => [
                'activity_type' => 'registered',
                'description' => "User restored: {$username}",
                'is_permanent' => false,
            ],
            'force_deleted' => [
                'activity_type' => 'deleted',
                'description' => "User permanently deleted: {$username}",
                'is_permanent' => true,
            ],
            default => [
                'activity_type' => 'registered',
                'description' => "New user registered: {$username}",
                'is_permanent' => false,
            ],
        };
    }

    /**
     * @return array{activity_type: string, description: string, is_permanent: bool}
     */
    public static function formatEventSummaryNative(
        string $event,
        string $username,
        string $newRoleName = ''
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::formatEventSummaryPhp($event, $username, $newRoleName);
        }

        try {
            $cap = max(128, strlen($username) + strlen($newRoleName) + 64);
            $out = $ffi->new("uint8_t[{$cap}]");
            $outEventCode = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_activity_observer_format_event(
                $event,
                strlen($event),
                $username,
                strlen($username),
                $newRoleName,
                strlen($newRoleName),
                $outEventCode,
                $out,
                $cap
            );
            $eventCode = $outEventCode[0];
            if (! is_int($written) || $written <= 0 || ! is_int($eventCode)) {
                return self::formatEventSummaryPhp($event, $username, $newRoleName);
            }

            $raw = \FFI::string($out, $written);
            $parts = explode("\0", $raw);
            if (count($parts) < 2) {
                return self::formatEventSummaryPhp($event, $username, $newRoleName);
            }

            return [
                'activity_type' => $parts[0],
                'description' => $parts[1],
                'is_permanent' => $eventCode === 4,
            ];
        } catch (\Throwable) {
            return self::formatEventSummaryPhp($event, $username, $newRoleName);
        }
    }

    /**
     * @param  array{activity_type: string, description: string, is_permanent: bool}  $summary
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function assemblePayloadFromSummary(
        string $event,
        ?int $userId,
        string $username,
        array $summary,
        array $context
    ): array {
        return match ($event) {
            'role_updated' => [
                'user_id' => $userId,
                'username' => $username,
                'activity_type' => $summary['activity_type'],
                'description' => $summary['description'],
                'metadata' => [
                    'old_role_id' => $context['old_role_id'] ?? null,
                    'new_role_id' => $context['new_role_id'] ?? null,
                    'old_role_name' => (string) ($context['old_role_name'] ?? 'None'),
                    'new_role_name' => (string) ($context['new_role_name'] ?? 'None'),
                    'updated_by' => (string) ($context['updated_by'] ?? 'System'),
                ],
            ],
            'deleted' => [
                'user_id' => $userId,
                'username' => $username,
                'activity_type' => $summary['activity_type'],
                'description' => $summary['description'],
                'metadata' => [
                    'email' => $context['email'] ?? null,
                    'deleted_by' => (string) ($context['deleted_by'] ?? 'System'),
                ],
            ],
            'restored' => [
                'user_id' => $userId,
                'username' => $username,
                'activity_type' => $summary['activity_type'],
                'description' => $summary['description'],
                'metadata' => [
                    'restored_by' => (string) ($context['restored_by'] ?? 'System'),
                ],
            ],
            'force_deleted' => [
                'user_id' => null,
                'username' => $username,
                'activity_type' => $summary['activity_type'],
                'description' => $summary['description'],
                'is_permanent' => $summary['is_permanent'],
                'metadata' => [
                    'email' => $context['email'] ?? null,
                    'deleted_by' => (string) ($context['deleted_by'] ?? 'System'),
                    'permanent' => true,
                ],
            ],
            default => [
                'user_id' => $userId,
                'username' => $username,
                'activity_type' => $summary['activity_type'],
                'description' => $summary['description'],
                'metadata' => array_filter([
                    'email' => $context['email'] ?? null,
                    'ip_address' => $context['ip_address'] ?? null,
                ], static fn ($value): bool => $value !== null),
            ],
        };
    }

    /**
     * Get role name by ID
     */
    private function getRoleName(?int $roleId): string
    {
        if (! $roleId) {
            return self::formatFallbackRoleName($roleId);
        }

        try {
            $role = Role::find($roleId);

            return $role ? $role->name : self::formatFallbackRoleName($roleId);
        } catch (\Exception $e) {
            return self::formatFallbackRoleName($roleId);
        }
    }

    private static function additionalFfi(): ?\FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof \FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
