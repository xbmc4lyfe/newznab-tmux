<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use DateTimeInterface;
use FFI;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * App\Models\RolePromotion
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array $applicable_roles
 * @property int $additional_days
 * @property \Carbon\Carbon|null $start_date
 * @property \Carbon\Carbon|null $end_date
 * @property bool $is_active
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Collection|RolePromotionStat[] $statistics
 */
class RolePromotion extends Model // @phpstan-ignore missingType.iterableValue
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_role_promotion_active_window(int32_t is_active, int32_t has_start, int64_t start_us, int32_t has_end, int64_t end_us, int64_t now_us);
        ssize_t nntmux_role_promotion_system_roles(char *out_buf, size_t out_cap);
        C;

    /**
     * PHP stays the production default: the Rust helpers miss the 1.5x mean/p99 gate
     * (see RustUserStatsInvitationParityTest for the paired benchmark evidence).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $fillable = [
        'name',
        'description',
        'applicable_roles',
        'additional_days',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'additional_days' => 'integer',
        'applicable_roles' => 'array',
    ];

    public static function setUseRustFfi(bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
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
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Evaluate whether a promotion is active at `$nowTs`.
     *
     * Timestamps are microseconds since the Unix epoch so comparisons match Carbon's
     * microsecond-precision `lt()` / `gt()`; a null bound leaves that side of the window open.
     */
    public static function evaluatePromotionActiveWindow(bool $isActive, ?int $startTs, ?int $endTs, int $nowTs): bool
    {
        return self::$useRustFfi
            ? self::evaluatePromotionActiveWindowNative($isActive, $startTs, $endTs, $nowTs)
            : self::evaluatePromotionActiveWindowPhp($isActive, $startTs, $endTs, $nowTs);
    }

    public static function evaluatePromotionActiveWindowNative(bool $isActive, ?int $startTs, ?int $endTs, int $nowTs): bool
    {
        if (($ffi = self::ffi()) === null) {
            return self::evaluatePromotionActiveWindowPhp($isActive, $startTs, $endTs, $nowTs);
        }
        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_role_promotion_active_window(
                (int) $isActive,
                (int) ($startTs !== null),
                $startTs ?? 0,
                (int) ($endTs !== null),
                $endTs ?? 0,
                $nowTs,
            );

            return match ($rc) {
                1 => true,
                0 => false,
                default => self::evaluatePromotionActiveWindowPhp($isActive, $startTs, $endTs, $nowTs),
            };
        } catch (Throwable) {
            return self::evaluatePromotionActiveWindowPhp($isActive, $startTs, $endTs, $nowTs);
        }
    }

    public static function evaluatePromotionActiveWindowPhp(bool $isActive, ?int $startTs, ?int $endTs, int $nowTs): bool
    {
        if (! $isActive) {
            return false;
        }

        if ($startTs !== null && $nowTs < $startTs) {
            return false;
        }

        if ($endTs !== null && $nowTs > $endTs) {
            return false;
        }

        return true;
    }

    /**
     * System role names excluded from custom promotion roles.
     *
     * @return list<string>
     */
    public static function systemRoleNames(): array
    {
        return self::$useRustFfi ? self::systemRoleNamesNative() : self::systemRoleNamesPhp();
    }

    /**
     * @return list<string>
     */
    public static function systemRoleNamesNative(): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::systemRoleNamesPhp();
        }
        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
            if ($buffer === null) {
                return self::systemRoleNamesPhp();
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_role_promotion_system_roles($buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::systemRoleNamesPhp();
            }

            return explode("\n", FFI::string($buffer, $written));
        } catch (Throwable) {
            return self::systemRoleNamesPhp();
        }
    }

    /**
     * @return list<string>
     */
    public static function systemRoleNamesPhp(): array
    {
        return ['Admin', 'User', 'Moderator', 'Disabled', 'Friend'];
    }

    private static function toMicroTimestamp(DateTimeInterface $date): int
    {
        return $date->getTimestamp() * 1_000_000 + (int) $date->format('u');
    }

    /**
     * Get the statistics for this promotion
     *
     * @return HasMany<RolePromotionStat, $this>
     */
    public function statistics(): HasMany
    {
        return $this->hasMany(RolePromotionStat::class);
    }

    /**
     * Get the roles this promotion applies to
     */
    public function getApplicableRolesModels(): mixed
    {
        if (empty($this->applicable_roles)) {
            return static::getCustomRoles();
        }

        return Role::whereIn('id', $this->applicable_roles)->get();
    }

    /**
     * Scope to get only valid (non-expired) promotions
     */
    public function scopeValid(mixed $query): mixed
    {
        $now = Carbon::now();

        return $query->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', $now);
            });
    }

    /**
     * Get custom roles (excluding system roles)
     */
    public static function getCustomRoles(): mixed
    {
        return Role::whereNotIn('name', self::systemRoleNames())->get();
    }

    /**
     * Check if the promotion is currently active
     */
    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return self::evaluatePromotionActiveWindow(
            true,
            $this->start_date ? self::toMicroTimestamp($this->start_date) : null,
            $this->end_date ? self::toMicroTimestamp($this->end_date) : null,
            self::toMicroTimestamp(Carbon::now()),
        );
    }

    /**
     * Get active promotions for a specific role
     */
    public static function getActivePromotions(?int $roleId = null): mixed
    {
        $query = static::where('is_active', true);

        $now = Carbon::now();
        $query->where(function ($q) use ($now) {
            $q->whereNull('start_date')
                ->orWhere('start_date', '<=', $now);
        });

        $query->where(function ($q) use ($now) {
            $q->whereNull('end_date')
                ->orWhere('end_date', '>=', $now);
        });

        if ($roleId !== null) {
            $query->where(function ($q) use ($roleId) {
                // If applicable_roles is empty, applies to all custom roles
                $q->whereJsonLength('applicable_roles', 0)
                    ->orWhereJsonContains('applicable_roles', $roleId);
            });
        }

        return $query->get();
    }

    /**
     * Calculate total additional days from active promotions
     */
    public static function calculateAdditionalDays(int $roleId): int
    {
        $promotions = static::getActivePromotions($roleId);

        return $promotions->sum('additional_days');
    }

    /**
     * Get summary statistics for this promotion
     *
     * @return array<string, mixed>
     */
    public function getSummaryStatistics(): array
    {
        return RolePromotionStat::getPromotionSummary($this->id);
    }

    /**
     * Get the count of users who have received this promotion
     */
    public function getTotalUpgrades(): int
    {
        return $this->statistics()->count();
    }

    /**
     * Get the count of unique users who have received this promotion
     */
    public function getUniqueUsersCount(): int
    {
        return $this->statistics()->distinct('user_id')->count('user_id');
    }

    /**
     * Get the total days added across all applications of this promotion
     */
    public function getTotalDaysAdded(): int
    {
        return $this->statistics()->sum('days_added');
    }

    /**
     * Track a promotion application
     */
    public function trackApplication(
        int $userId,
        int $roleId,
        ?Carbon $previousExpiryDate = null,
        ?Carbon $newExpiryDate = null
    ): RolePromotionStat {
        return RolePromotionStat::recordPromotion(
            $userId,
            $this->id,
            $roleId,
            $this->additional_days,
            $previousExpiryDate,
            $newExpiryDate
        );
    }

    /**
     * Get statistics grouped by role
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStatisticsByRole(): array
    {
        return $this->statistics()
            ->with('role')
            ->get()
            ->groupBy('role_id')
            ->map(function ($stats, $roleId) {
                /** @var RolePromotionStat|null $firstStat */
                $firstStat = $stats->first();

                return [
                    'role_id' => $roleId,
                    'role_name' => $firstStat?->role?->name,
                    'total_upgrades' => $stats->count(),
                    'total_days_added' => $stats->sum('days_added'),
                    'unique_users' => $stats->unique('user_id')->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Get statistics for a specific time period
     *
     * @return array<string, mixed>
     */
    public function getStatisticsForPeriod(Carbon $startDate, Carbon $endDate): array
    {
        $stats = $this->statistics()
            ->appliedBetween($startDate, $endDate)
            ->get();

        return [
            'period' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'total_upgrades' => $stats->count(),
            'total_days_added' => $stats->sum('days_added'),
            'unique_users' => $stats->unique('user_id')->count(),
            'roles_affected' => $stats->unique('role_id')->count(),
        ];
    }
}
