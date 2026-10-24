<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * App\Models\RolePromotionStat
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_promotion_id
 * @property int $role_id
 * @property int $days_added
 * @property Carbon|null $previous_expiry_date
 * @property Carbon|null $new_expiry_date
 * @property Carbon $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read RolePromotion $promotion
 * @property-read Role $role
 */
class RolePromotionStat extends Model
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_role_promotion_stat_summarize(
            size_t count,
            const int64_t *user_ids_ptr,
            const int64_t *role_ids_ptr,
            const int64_t *days_added_ptr,
            int64_t *out_summary
        );
        C;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustRolePaymentGenreMetricModelParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $fillable = [
        'user_id',
        'role_promotion_id',
        'role_id',
        'days_added',
        'previous_expiry_date',
        'new_expiry_date',
        'applied_at',
    ];

    protected $casts = [
        'days_added' => 'integer',
        'previous_expiry_date' => 'datetime',
        'new_expiry_date' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public static function setUseRustFfi(bool $useRustFfi = true): void
    {
        self::$useRustFfi = $useRustFfi;
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

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Get the user that received the promotion
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the promotion that was applied
     *
     * @return BelongsTo<RolePromotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(RolePromotion::class, 'role_promotion_id');
    }

    /**
     * Get the role that was upgraded
     */
    public function role(): BelongsTo // @phpstan-ignore missingType.generics
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Scope to get stats for a specific promotion
     */
    public function scopeForPromotion(mixed $query, int $promotionId): mixed
    {
        return $query->where('role_promotion_id', $promotionId);
    }

    /**
     * Scope to get stats for a specific user
     */
    public function scopeForUser(mixed $query, int $userId): mixed
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get stats for a specific role
     */
    public function scopeForRole(mixed $query, int $roleId): mixed
    {
        return $query->where('role_id', $roleId);
    }

    /**
     * Scope to get stats within a date range
     */
    public function scopeAppliedBetween(mixed $query, mixed $startDate, mixed $endDate): mixed
    {
        return $query->whereBetween('applied_at', [$startDate, $endDate]);
    }

    /**
     * Get statistics summary for a promotion
     *
     * @return array<string, mixed>
     */
    public static function getPromotionSummary(int $promotionId): array
    {
        $stats = static::forPromotion($promotionId)->get();

        return self::summarizePromotionStats($stats->all());
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $rows
     * @return array{
     *     total_upgrades: int,
     *     total_days_added: int,
     *     unique_users: int,
     *     roles_affected: int,
     *     latest_application: mixed,
     *     earliest_application: mixed
     * }
     */
    public static function summarizePromotionStats(array $rows): array
    {
        return self::$useRustFfi
            ? self::summarizePromotionStatsNative($rows)
            : self::summarizePromotionStatsPhp($rows);
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $rows
     * @return array{
     *     total_upgrades: int,
     *     total_days_added: int,
     *     unique_users: int,
     *     roles_affected: int,
     *     latest_application: mixed,
     *     earliest_application: mixed
     * }
     */
    public static function summarizePromotionStatsNative(array $rows): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::summarizePromotionStatsPhp($rows);
        }

        $count = count($rows);
        $slotCount = max(1, $count);

        try {
            $userIdsSlot = $ffi->new("int64_t[{$slotCount}]");
            $roleIdsSlot = $ffi->new("int64_t[{$slotCount}]");
            $daysSlot = $ffi->new("int64_t[{$slotCount}]");
            $summarySlot = $ffi->new('int64_t[4]');

            $latest = null;
            $earliest = null;
            $idx = 0;

            foreach ($rows as $row) {
                $userId = is_array($row) ? ($row['user_id'] ?? 0) : ($row->user_id ?? 0);
                $roleId = is_array($row) ? ($row['role_id'] ?? 0) : ($row->role_id ?? 0);
                $daysAdded = is_array($row) ? ($row['days_added'] ?? 0) : ($row->days_added ?? 0);
                $appliedAt = is_array($row) ? ($row['applied_at'] ?? null) : ($row->applied_at ?? null);

                $userIdsSlot[$idx] = (int) $userId;
                $roleIdsSlot[$idx] = (int) $roleId;
                $daysSlot[$idx] = (int) $daysAdded;
                $idx++;

                if ($appliedAt !== null) {
                    if ($latest === null || $appliedAt > $latest) {
                        $latest = $appliedAt;
                    }
                    if ($earliest === null || $appliedAt < $earliest) {
                        $earliest = $appliedAt;
                    }
                }
            }

            $rc = self::callFfi(
                $ffi,
                'nntmux_role_promotion_stat_summarize',
                $count,
                $userIdsSlot,
                $roleIdsSlot,
                $daysSlot,
                $summarySlot
            );

            if ($rc === 1) {
                return [
                    'total_upgrades' => (int) $summarySlot[0],
                    'total_days_added' => (int) $summarySlot[1],
                    'unique_users' => (int) $summarySlot[2],
                    'roles_affected' => (int) $summarySlot[3],
                    'latest_application' => $latest,
                    'earliest_application' => $earliest,
                ];
            }
        } catch (Throwable) {
        }

        return self::summarizePromotionStatsPhp($rows);
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $rows
     * @return array{
     *     total_upgrades: int,
     *     total_days_added: int,
     *     unique_users: int,
     *     roles_affected: int,
     *     latest_application: mixed,
     *     earliest_application: mixed
     * }
     */
    public static function summarizePromotionStatsPhp(array $rows): array
    {
        $totalDays = 0;
        $users = [];
        $roles = [];
        $latest = null;
        $earliest = null;

        foreach ($rows as $row) {
            $userId = (int) (is_array($row) ? ($row['user_id'] ?? 0) : ($row->user_id ?? 0));
            $roleId = (int) (is_array($row) ? ($row['role_id'] ?? 0) : ($row->role_id ?? 0));
            $daysAdded = (int) (is_array($row) ? ($row['days_added'] ?? 0) : ($row->days_added ?? 0));
            $appliedAt = is_array($row) ? ($row['applied_at'] ?? null) : ($row->applied_at ?? null);

            $totalDays += $daysAdded;
            $users[$userId] = true;
            $roles[$roleId] = true;

            if ($appliedAt !== null) {
                if ($latest === null || $appliedAt > $latest) {
                    $latest = $appliedAt;
                }
                if ($earliest === null || $appliedAt < $earliest) {
                    $earliest = $appliedAt;
                }
            }
        }

        return [
            'total_upgrades' => count($rows),
            'total_days_added' => $totalDays,
            'unique_users' => count($users),
            'roles_affected' => count($roles),
            'latest_application' => $latest,
            'earliest_application' => $earliest,
        ];
    }

    /**
     * Get statistics summary for a user
     *
     * @return array<string, mixed>
     */
    public static function getUserSummary(int $userId): array
    {
        $stats = static::forUser($userId)->get();

        return [
            'total_promotions_received' => $stats->count(),
            'total_days_added' => $stats->sum('days_added'),
            'promotions' => $stats->groupBy('role_promotion_id')->map(function ($group) {
                return [
                    'promotion_id' => $group->first()->role_promotion_id,
                    'promotion_name' => $group->first()->promotion->name,
                    'times_applied' => $group->count(),
                    'total_days' => $group->sum('days_added'),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Record a promotion application
     */
    public static function recordPromotion(
        int $userId,
        int $promotionId,
        int $roleId,
        int $daysAdded,
        ?Carbon $previousExpiryDate = null,
        ?Carbon $newExpiryDate = null
    ): self {
        return static::create([
            'user_id' => $userId,
            'role_promotion_id' => $promotionId,
            'role_id' => $roleId,
            'days_added' => $daysAdded,
            'previous_expiry_date' => $previousExpiryDate,
            'new_expiry_date' => $newExpiryDate,
            'applied_at' => now(),
        ]);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
