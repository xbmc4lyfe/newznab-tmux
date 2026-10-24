<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use FFI;
use Illuminate\Http\Request;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Role;
use Throwable;

class PermissionSyncHelper
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_permission_user_diff(uint32_t present, uint32_t direct, uint32_t effective, uint32_t count);
        int32_t nntmux_permission_role_diff(const int64_t *values, size_t count, uint32_t current, uint32_t revoke_zero);
        C;

    /**
     * The Rust helpers stay opt-in: they missed the 1.5x paired benchmark gate (user diff common
     * 0.55x mean / 0.53x p99, uncommon 0.44x / 0.31x; role diff common 0.53x / 0.53x, uncommon
     * 0.79x / 0.62x;
     * tests/Unit/RustSecurityForumSupportParityTest.php).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private const VIEW_PERMISSIONS = [
        'viewconsole' => 'view console',
        'viewmovies' => 'view movies',
        'viewaudio' => 'view audio',
        'viewpc' => 'view pc',
        'viewtv' => 'view tv',
        'viewadult' => 'view adult',
        'viewbooks' => 'view books',
        'viewother' => 'view other',
    ];

    private const ROLE_EXTRA_PERMISSIONS = [
        'canpreview' => 'preview',
        'hideads' => 'hideads',
        'editrelease' => 'edit release',
    ];

    /**
     * Sync view permissions for a user based on request checkboxes.
     * Grants permission if checkbox is present, revokes if absent.
     */
    public static function syncUserPermissions(User $entity, Request $request): void
    {
        $present = [];
        $direct = [];
        $effective = [];
        foreach (self::VIEW_PERMISSIONS as $inputKey => $permission) {
            if ($request->has($inputKey)) {
                $present[] = $inputKey;
                if ($entity->hasDirectPermission($permission)) {
                    $direct[] = $permission;
                }
            } elseif ($entity->hasPermissionTo($permission)) {
                $effective[] = $permission;
            }
        }

        $diff = self::computeUserPermissionDiff($present, $direct, $effective);
        foreach ($diff['grant'] as $permission) {
            $entity->givePermissionTo($permission);
        }
        foreach ($diff['revoke'] as $permission) {
            $entity->revokePermissionTo($permission);
        }
    }

    /**
     * Grant permissions to a newly created role based on request inputs.
     * Only grants (no revoke needed for new roles).
     */
    public static function grantRolePermissions(Role|RoleContract $role, Request $request): void
    {
        $values = [];
        foreach (self::rolePermissions() as $inputKey => $permission) {
            $values[$inputKey] = (int) $request->input($inputKey);
        }

        foreach (self::computeRolePermissionDiff($values, [], false)['grant'] as $permission) {
            $role->givePermissionTo($permission);
        }
    }

    /**
     * Sync permissions on an existing role based on request inputs.
     * Grants if input is 1, revokes if input is 0.
     */
    public static function syncRolePermissions(Role|RoleContract $role, Request $request): void
    {
        $values = [];
        $current = [];
        foreach (self::rolePermissions() as $inputKey => $permission) {
            $value = (int) $request->input($inputKey);
            $values[$inputKey] = $value;
            if (($value === 1 || $value === 0) && $role->hasPermissionTo($permission)) {
                $current[] = $permission;
            }
        }

        $diff = self::computeRolePermissionDiff($values, $current, true);
        foreach ($diff['grant'] as $permission) {
            $role->givePermissionTo($permission);
        }
        foreach ($diff['revoke'] as $permission) {
            $role->revokePermissionTo($permission);
        }
    }

    /**
     * Grant a user direct permissions for all view permissions their role allows.
     * Used during registration to copy role-level permissions to direct user permissions.
     */
    public static function grantInheritedPermissions(User $user): void
    {
        foreach (self::VIEW_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                $user->givePermissionTo($permission);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function rolePermissions(): array
    {
        return array_merge(self::ROLE_EXTRA_PERMISSIONS, self::VIEW_PERMISSIONS);
    }

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
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * View permissions to grant and revoke for a user, in permission order: grant each
     * permission whose input key is present but is not held directly, revoke each permission
     * whose input key is absent but is held (directly or through a role).
     *
     * @param  array<int|string, mixed>  $presentInputs  input keys present in the request
     * @param  array<int|string, mixed>  $directPermissions  permission names held directly
     * @param  array<int|string, mixed>  $effectivePermissions  permission names held at all
     * @return array{grant: list<string>, revoke: list<string>}
     */
    public static function computeUserPermissionDiff(array $presentInputs, array $directPermissions, array $effectivePermissions): array
    {
        return self::usesRustFfi()
            ? self::computeUserPermissionDiffNative($presentInputs, $directPermissions, $effectivePermissions)
            : self::computeUserPermissionDiffPhp($presentInputs, $directPermissions, $effectivePermissions);
    }

    /**
     * @param  array<int|string, mixed>  $presentInputs
     * @param  array<int|string, mixed>  $directPermissions
     * @param  array<int|string, mixed>  $effectivePermissions
     * @return array{grant: list<string>, revoke: list<string>}
     */
    public static function computeUserPermissionDiffPhp(array $presentInputs, array $directPermissions, array $effectivePermissions): array
    {
        $grant = [];
        $revoke = [];
        foreach (self::VIEW_PERMISSIONS as $inputKey => $permission) {
            if (\in_array($inputKey, $presentInputs, true)) {
                if (! \in_array($permission, $directPermissions, true)) {
                    $grant[] = $permission;
                }
            } elseif (\in_array($permission, $effectivePermissions, true)) {
                $revoke[] = $permission;
            }
        }

        return ['grant' => $grant, 'revoke' => $revoke];
    }

    /**
     * @param  array<int|string, mixed>  $presentInputs
     * @param  array<int|string, mixed>  $directPermissions
     * @param  array<int|string, mixed>  $effectivePermissions
     * @return array{grant: list<string>, revoke: list<string>}
     */
    public static function computeUserPermissionDiffNative(array $presentInputs, array $directPermissions, array $effectivePermissions): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::computeUserPermissionDiffPhp($presentInputs, $directPermissions, $effectivePermissions);
        }

        $present = 0;
        $direct = 0;
        $effective = 0;
        $bit = 1;
        foreach (self::VIEW_PERMISSIONS as $inputKey => $permission) {
            if (\in_array($inputKey, $presentInputs, true)) {
                $present |= $bit;
            }
            if (\in_array($permission, $directPermissions, true)) {
                $direct |= $bit;
            }
            if (\in_array($permission, $effectivePermissions, true)) {
                $effective |= $bit;
            }
            $bit <<= 1;
        }

        try {
            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_permission_user_diff($present, $direct, $effective, \count(self::VIEW_PERMISSIONS));
        } catch (Throwable) {
            return self::computeUserPermissionDiffPhp($presentInputs, $directPermissions, $effectivePermissions);
        }

        return \is_int($packed) && $packed >= 0
            ? self::unpackDiff(array_values(self::VIEW_PERMISSIONS), $packed)
            : self::computeUserPermissionDiffPhp($presentInputs, $directPermissions, $effectivePermissions);
    }

    /**
     * Role permissions to grant and revoke, in permission order: grant each permission whose
     * input value is 1 and is not current; when `$revokeZero` is set, revoke each permission
     * whose input value is 0 and is current. A missing input value counts as 0.
     *
     * @param  array<string, int>  $inputValues  integer input value by input key
     * @param  array<int|string, mixed>  $currentPermissions  permission names the role holds
     * @return array{grant: list<string>, revoke: list<string>}
     */
    public static function computeRolePermissionDiff(array $inputValues, array $currentPermissions, bool $revokeZero): array
    {
        return self::usesRustFfi()
            ? self::computeRolePermissionDiffNative($inputValues, $currentPermissions, $revokeZero)
            : self::computeRolePermissionDiffPhp($inputValues, $currentPermissions, $revokeZero);
    }

    /**
     * @param  array<string, int>  $inputValues
     * @param  array<int|string, mixed>  $currentPermissions
     * @return array{grant: list<string>, revoke: list<string>}
     */
    public static function computeRolePermissionDiffPhp(array $inputValues, array $currentPermissions, bool $revokeZero): array
    {
        $grant = [];
        $revoke = [];
        foreach (self::rolePermissions() as $inputKey => $permission) {
            $value = $inputValues[$inputKey] ?? 0;
            $held = \in_array($permission, $currentPermissions, true);

            if ($value === 1 && ! $held) {
                $grant[] = $permission;
            } elseif ($revokeZero && $value === 0 && $held) {
                $revoke[] = $permission;
            }
        }

        return ['grant' => $grant, 'revoke' => $revoke];
    }

    /**
     * @param  array<string, int>  $inputValues
     * @param  array<int|string, mixed>  $currentPermissions
     * @return array{grant: list<string>, revoke: list<string>}
     */
    public static function computeRolePermissionDiffNative(array $inputValues, array $currentPermissions, bool $revokeZero): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::computeRolePermissionDiffPhp($inputValues, $currentPermissions, $revokeZero);
        }

        $permissions = self::rolePermissions();
        $count = \count($permissions);
        $current = 0;
        $bit = 1;
        $i = 0;

        try {
            $values = $ffi->new("int64_t[{$count}]");
            foreach ($permissions as $inputKey => $permission) {
                $value = $inputValues[$inputKey] ?? 0;
                if (! \is_int($value)) {
                    return self::computeRolePermissionDiffPhp($inputValues, $currentPermissions, $revokeZero);
                }
                $values[$i++] = $value;
                if (\in_array($permission, $currentPermissions, true)) {
                    $current |= $bit;
                }
                $bit <<= 1;
            }

            // @phpstan-ignore method.notFound
            $packed = $ffi->nntmux_permission_role_diff($values, $count, $current, $revokeZero ? 1 : 0);
        } catch (Throwable) {
            return self::computeRolePermissionDiffPhp($inputValues, $currentPermissions, $revokeZero);
        }

        return \is_int($packed) && $packed >= 0
            ? self::unpackDiff(array_values($permissions), $packed)
            : self::computeRolePermissionDiffPhp($inputValues, $currentPermissions, $revokeZero);
    }

    /**
     * @param  list<string>  $permissions
     * @return array{grant: list<string>, revoke: list<string>}
     */
    private static function unpackDiff(array $permissions, int $packed): array
    {
        $grant = [];
        $revoke = [];
        foreach ($permissions as $i => $permission) {
            if (($packed >> $i) & 1) {
                $grant[] = $permission;
            }
            if (($packed >> ($i + 16)) & 1) {
                $revoke[] = $permission;
            }
        }

        return ['grant' => $grant, 'revoke' => $revoke];
    }
}
