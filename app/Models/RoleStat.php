<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class RoleStat extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    protected $guarded = [];

    // Opt-in experiment: retain PHP unless every caller-inclusive performance gate qualifies.
    private static bool $useRustFfi = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    /** @return array{role: mixed, users: mixed} */
    public static function buildRow(mixed $role, mixed $users): array
    {
        return self::$useRustFfi ? self::buildRowNative($role, $users) : self::buildRowPhp($role, $users);
    }

    /** @return array{role: mixed, users: mixed} */
    public static function buildRowPhp(mixed $role, mixed $users): array
    {
        return ['role' => $role, 'users' => $users];
    }

    /** @return array{role: mixed, users: mixed} */
    public static function buildRowNative(mixed $role, mixed $users): array
    {
        $result = DownloadStat::transformNative(5, [$role, $users]);

        return is_array($result) && array_keys($result) === ['role', 'users']
            ? $result : self::buildRowPhp($role, $users);
    }

    public static function insertUsersByRole(): void
    {
        $roles = Role::query()->select(['name'])->withCount('users')->groupBy('name')->having('users_count', '>', 0)->orderByDesc('users_count')->get();
        foreach ($roles as $role) {
            $row = self::buildRow($role->name, $role->users_count);
            // Check if we already have the information and if we do just update the count
            if (self::query()->where('role', $role->name)->exists()) {
                self::query()->where('role', $role->name)->update(['users' => $row['users']]);

                continue;
            }
            self::query()->create($row);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getUsersByRole(): array
    {
        return self::query()->select(['role', 'users'])->get()->toArray();
    }
}
