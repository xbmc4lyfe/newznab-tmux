<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * App\Models\UserRoleHistory
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $old_role_id
 * @property int $new_role_id
 * @property string|null $old_expiry_date
 * @property string|null $new_expiry_date
 * @property string $effective_date
 * @property bool $is_stacked
 * @property string|null $change_reason
 * @property int|null $changed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $changedByUser
 */
class UserRoleHistory extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_user_role_history_pack_scalar_fields(
            int64_t user_id,
            int32_t has_old_role_id,
            int64_t old_role_id,
            int64_t new_role_id,
            int32_t is_stacked,
            int32_t has_changed_by,
            int64_t changed_by,
            int32_t has_reason,
            const char *reason_ptr,
            size_t reason_len,
            int64_t *out_scalars,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustRolePaymentGenreMetricModelParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $table = 'user_role_history';

    protected $fillable = [
        'user_id',
        'old_role_id',
        'new_role_id',
        'old_expiry_date',
        'new_expiry_date',
        'effective_date',
        'is_stacked',
        'change_reason',
        'changed_by',
    ];

    protected $casts = [
        'old_expiry_date' => 'datetime',
        'new_expiry_date' => 'datetime',
        'effective_date' => 'datetime',
        'is_stacked' => 'boolean',
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
     * Get the user this history belongs to
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who made this change
     *
     * @return BelongsTo<User, $this>
     */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Get the old role
     */
    public function oldRole(): BelongsTo // @phpstan-ignore missingType.generics
    {
        return $this->belongsTo(Role::class, 'old_role_id');
    }

    /**
     * Get the new role
     */
    public function newRole(): BelongsTo // @phpstan-ignore missingType.generics
    {
        return $this->belongsTo(Role::class, 'new_role_id');
    }

    /**
     * Record a role change
     */
    public static function recordRoleChange(
        int $userId,
        ?int $oldRoleId,
        int $newRoleId,
        ?CarbonInterface $oldExpiryDate,
        ?CarbonInterface $newExpiryDate,
        CarbonInterface $effectiveDate,
        bool $isStacked = false,
        ?string $changeReason = null,
        ?int $changedBy = null
    ): self {
        return static::create(self::buildRoleChangeAttributes(
            $userId,
            $oldRoleId,
            $newRoleId,
            $oldExpiryDate,
            $newExpiryDate,
            $effectiveDate,
            $isStacked,
            $changeReason,
            $changedBy
        ));
    }

    /**
     * @return array{
     *     user_id: int,
     *     old_role_id: ?int,
     *     new_role_id: int,
     *     old_expiry_date: ?CarbonInterface,
     *     new_expiry_date: ?CarbonInterface,
     *     effective_date: CarbonInterface,
     *     is_stacked: bool,
     *     change_reason: ?string,
     *     changed_by: ?int
     * }
     */
    public static function buildRoleChangeAttributes(
        int $userId,
        ?int $oldRoleId,
        int $newRoleId,
        ?CarbonInterface $oldExpiryDate,
        ?CarbonInterface $newExpiryDate,
        CarbonInterface $effectiveDate,
        bool $isStacked = false,
        ?string $changeReason = null,
        ?int $changedBy = null,
    ): array {
        return self::$useRustFfi
            ? self::buildRoleChangeAttributesNative(
                $userId,
                $oldRoleId,
                $newRoleId,
                $oldExpiryDate,
                $newExpiryDate,
                $effectiveDate,
                $isStacked,
                $changeReason,
                $changedBy
            )
            : self::buildRoleChangeAttributesPhp(
                $userId,
                $oldRoleId,
                $newRoleId,
                $oldExpiryDate,
                $newExpiryDate,
                $effectiveDate,
                $isStacked,
                $changeReason,
                $changedBy
            );
    }

    /**
     * @return array{
     *     user_id: int,
     *     old_role_id: ?int,
     *     new_role_id: int,
     *     old_expiry_date: ?CarbonInterface,
     *     new_expiry_date: ?CarbonInterface,
     *     effective_date: CarbonInterface,
     *     is_stacked: bool,
     *     change_reason: ?string,
     *     changed_by: ?int
     * }
     */
    public static function buildRoleChangeAttributesNative(
        int $userId,
        ?int $oldRoleId,
        int $newRoleId,
        ?CarbonInterface $oldExpiryDate,
        ?CarbonInterface $newExpiryDate,
        CarbonInterface $effectiveDate,
        bool $isStacked = false,
        ?string $changeReason = null,
        ?int $changedBy = null,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildRoleChangeAttributesPhp(
                $userId,
                $oldRoleId,
                $newRoleId,
                $oldExpiryDate,
                $newExpiryDate,
                $effectiveDate,
                $isStacked,
                $changeReason,
                $changedBy
            );
        }

        $hasReason = $changeReason !== null;
        $reasonStr = $changeReason ?? '';

        try {
            $scalarsSlot = $ffi->new('int64_t[8]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(64, strlen($reasonStr) + 16), $capacity);
            if ($outBuf === null) {
                return self::buildRoleChangeAttributesPhp(
                    $userId,
                    $oldRoleId,
                    $newRoleId,
                    $oldExpiryDate,
                    $newExpiryDate,
                    $effectiveDate,
                    $isStacked,
                    $changeReason,
                    $changedBy
                );
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_user_role_history_pack_scalar_fields',
                $userId,
                $oldRoleId !== null ? 1 : 0,
                $oldRoleId ?? 0,
                $newRoleId,
                $isStacked ? 1 : 0,
                $changedBy !== null ? 1 : 0,
                $changedBy ?? 0,
                $hasReason ? 1 : 0,
                $reasonStr,
                strlen($reasonStr),
                $scalarsSlot,
                $outBuf,
                $capacity
            );

            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                $reasonOut = ((int) $scalarsSlot[7]) === 1
                    ? ($written === 0 ? '' : FFI::string($outBuf, $written))
                    : null;

                return [
                    'user_id' => (int) $scalarsSlot[0],
                    'old_role_id' => ((int) $scalarsSlot[1]) === 1 ? (int) $scalarsSlot[2] : null,
                    'new_role_id' => (int) $scalarsSlot[3],
                    'old_expiry_date' => $oldExpiryDate,
                    'new_expiry_date' => $newExpiryDate,
                    'effective_date' => $effectiveDate,
                    'is_stacked' => ((int) $scalarsSlot[4]) === 1,
                    'change_reason' => $reasonOut,
                    'changed_by' => ((int) $scalarsSlot[5]) === 1 ? (int) $scalarsSlot[6] : null,
                ];
            }
        } catch (Throwable) {
        }

        return self::buildRoleChangeAttributesPhp(
            $userId,
            $oldRoleId,
            $newRoleId,
            $oldExpiryDate,
            $newExpiryDate,
            $effectiveDate,
            $isStacked,
            $changeReason,
            $changedBy
        );
    }

    /**
     * @return array{
     *     user_id: int,
     *     old_role_id: ?int,
     *     new_role_id: int,
     *     old_expiry_date: ?CarbonInterface,
     *     new_expiry_date: ?CarbonInterface,
     *     effective_date: CarbonInterface,
     *     is_stacked: bool,
     *     change_reason: ?string,
     *     changed_by: ?int
     * }
     */
    public static function buildRoleChangeAttributesPhp(
        int $userId,
        ?int $oldRoleId,
        int $newRoleId,
        ?CarbonInterface $oldExpiryDate,
        ?CarbonInterface $newExpiryDate,
        CarbonInterface $effectiveDate,
        bool $isStacked = false,
        ?string $changeReason = null,
        ?int $changedBy = null,
    ): array {
        return [
            'user_id' => $userId,
            'old_role_id' => $oldRoleId,
            'new_role_id' => $newRoleId,
            'old_expiry_date' => $oldExpiryDate,
            'new_expiry_date' => $newExpiryDate,
            'effective_date' => $effectiveDate,
            'is_stacked' => $isStacked,
            'change_reason' => $changeReason,
            'changed_by' => $changedBy,
        ];
    }

    /**
     * Get role history for a user
     */
    public static function getUserHistory(int $userId): mixed
    {
        return static::where('user_id', $userId)
            ->orderBy('effective_date', 'desc')
            ->get();
    }

    /**
     * Get stacked role changes for a user
     */
    public static function getUserStackedChanges(int $userId): mixed
    {
        return static::where('user_id', $userId)
            ->where('is_stacked', true)
            ->orderBy('effective_date', 'desc')
            ->get();
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
