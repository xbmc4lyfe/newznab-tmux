<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use DateTimeInterface;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * App\Models\Invitation
 *
 * @property int $id
 * @property string $token
 * @property string $email
 * @property int $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $used_by
 * @property bool $is_active
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $invitedBy
 * @property-read User|null $usedBy
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Invitation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Invitation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Invitation query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Invitation active()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Invitation valid()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Invitation expired()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Invitation unused()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Invitation used()
 */
class Invitation extends Model // @phpstan-ignore missingType.iterableValue
{
    public const DEFAULT_INVITES = 1;

    public const DEFAULT_INVITE_EXPIRY_DAYS = 7;

    public const int STATE_VALID = 1;

    public const int STATE_EXPIRED = 2;

    public const int STATE_USED = 4;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_invitation_normalize_email(const char *email_ptr, size_t email_len, char *out_buf, size_t out_cap);
        int32_t nntmux_invitation_state(int32_t is_active, int64_t expires_at_us, int64_t now_us, int32_t has_used_at);
        C;

    /**
     * PHP stays the production default: the Rust helpers miss the 1.5x mean/p99 gate
     * (see RustUserStatsInvitationParityTest for the paired benchmark evidence).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'token',
        'email',
        'invited_by',
        'expires_at',
        'used_at',
        'used_by',
        'is_active',
        'metadata',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
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
     * Normalize an invitation email address: `strtolower(trim($email))`.
     */
    public static function normalizeEmail(string $email): string
    {
        return self::$useRustFfi ? self::normalizeEmailNative($email) : self::normalizeEmailPhp($email);
    }

    public static function normalizeEmailNative(string $email): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeEmailPhp($email);
        }
        try {
            $length = \strlen($email);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $length), $capacity);
            if ($buffer === null) {
                return self::normalizeEmailPhp($email);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_invitation_normalize_email($email, $length, $buffer, $capacity);
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::normalizeEmailPhp($email);
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::normalizeEmailPhp($email);
        }
    }

    public static function normalizeEmailPhp(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Evaluate the validity, expiry, and used flags of an invitation.
     *
     * Timestamps are microseconds since the Unix epoch so comparisons match Carbon's
     * microsecond-precision `isFuture()` / `isPast()` exactly.
     *
     * @return array{is_valid: bool, is_expired: bool, is_used: bool}
     */
    public static function evaluateInvitationState(bool $isActive, int $expiresAtTs, int $nowTs, bool $hasUsedAt): array
    {
        return self::$useRustFfi
            ? self::evaluateInvitationStateNative($isActive, $expiresAtTs, $nowTs, $hasUsedAt)
            : self::evaluateInvitationStatePhp($isActive, $expiresAtTs, $nowTs, $hasUsedAt);
    }

    /**
     * @return array{is_valid: bool, is_expired: bool, is_used: bool}
     */
    public static function evaluateInvitationStateNative(bool $isActive, int $expiresAtTs, int $nowTs, bool $hasUsedAt): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::evaluateInvitationStatePhp($isActive, $expiresAtTs, $nowTs, $hasUsedAt);
        }
        try {
            // @phpstan-ignore method.notFound
            $state = $ffi->nntmux_invitation_state((int) $isActive, $expiresAtTs, $nowTs, (int) $hasUsedAt);
            if (! \is_int($state) || $state < 0) {
                return self::evaluateInvitationStatePhp($isActive, $expiresAtTs, $nowTs, $hasUsedAt);
            }

            return [
                'is_valid' => ($state & self::STATE_VALID) !== 0,
                'is_expired' => ($state & self::STATE_EXPIRED) !== 0,
                'is_used' => ($state & self::STATE_USED) !== 0,
            ];
        } catch (Throwable) {
            return self::evaluateInvitationStatePhp($isActive, $expiresAtTs, $nowTs, $hasUsedAt);
        }
    }

    /**
     * @return array{is_valid: bool, is_expired: bool, is_used: bool}
     */
    public static function evaluateInvitationStatePhp(bool $isActive, int $expiresAtTs, int $nowTs, bool $hasUsedAt): array
    {
        return [
            'is_valid' => $isActive && $expiresAtTs > $nowTs && ! $hasUsedAt,
            'is_expired' => $expiresAtTs < $nowTs,
            'is_used' => $hasUsedAt,
        ];
    }

    /**
     * Microseconds since the Unix epoch for a date, preserving sub-second precision.
     */
    public static function toMicroTimestamp(DateTimeInterface $date): int
    {
        return $date->getTimestamp() * 1_000_000 + (int) $date->format('u');
    }

    /**
     * @return array{is_valid: bool, is_expired: bool, is_used: bool}
     */
    private function currentState(): array
    {
        return self::evaluateInvitationState(
            (bool) $this->is_active,
            self::toMicroTimestamp($this->expires_at),
            self::toMicroTimestamp(Carbon::now()),
            $this->used_at !== null,
        );
    }

    /**
     * Get the user who created this invitation
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Get the user who used this invitation
     *
     * @return BelongsTo<User, $this>
     */
    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    /**
     * Scope to get only active invitations
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get valid (active and not expired) invitations
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->active()
            ->where('expires_at', '>', now())
            ->whereNull('used_at');
    }

    /**
     * Scope to get expired invitations
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope to get unused invitations
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereNull('used_at');
    }

    /**
     * Scope to get used invitations
     *
     * @param  Builder<Invitation>  $query
     * @return Builder<Invitation>
     */
    public function scopeUsed(Builder $query): Builder
    {
        return $query->whereNotNull('used_at');
    }

    /**
     * Check if the invitation is valid
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->currentState()['is_valid'];
    }

    /**
     * Check if the invitation is expired
     */
    public function isExpired(): bool
    {
        return $this->currentState()['is_expired'];
    }

    /**
     * Check if the invitation has been used
     */
    public function isUsed(): bool
    {
        return self::evaluateInvitationState(false, 0, 0, $this->used_at !== null)['is_used'];
    }

    /**
     * Mark the invitation as used
     */
    public function markAsUsed(int $userId): bool
    {
        $this->used_at = now();
        $this->used_by = $userId;
        $this->is_active = false;

        return $this->save();
    }

    /**
     * Mark the invitation as expired
     */
    public function markAsExpired(): bool
    {
        $this->is_active = false;

        return $this->save();
    }

    /**
     * Create a new invitation
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function createInvitation(
        string $email,
        int $invitedBy,
        int $expiryDays = self::DEFAULT_INVITE_EXPIRY_DAYS,
        array $metadata = []
    ): self {
        return self::create([
            'token' => Str::random(64),
            'email' => self::normalizeEmail($email),
            'invited_by' => $invitedBy,
            'expires_at' => now()->addDays($expiryDays),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Find invitation by token
     */
    public static function findByToken(string $token): ?self
    {
        return self::where('token', $token)->first();
    }

    /**
     * Find valid invitation by token
     */
    public static function findValidByToken(string $token): ?self
    {
        return self::valid()->where('token', $token)->first();
    }

    /**
     * Find invitation by email
     */
    public static function findByEmail(string $email): ?self
    {
        return self::where('email', self::normalizeEmail($email))->first();
    }

    /**
     * Get invitation by token (legacy compatibility)
     */
    public static function getInvite(string $token): ?self
    {
        return self::findValidByToken($token);
    }

    /**
     * Delete invitation by token (legacy compatibility)
     */
    public static function deleteInvite(string $token): bool
    {
        $invitation = self::findByToken($token);
        if ($invitation) {
            return $invitation->delete();
        }

        return false;
    }

    /**
     * Add invite (legacy compatibility)
     */
    public static function addInvite(int $userId, string $token): self
    {
        // For legacy compatibility, we create an invitation with a specific token
        // In practice, this should use createInvitation method instead
        return self::create([
            'token' => $token,
            'email' => '', // Legacy method doesn't specify email
            'invited_by' => $userId,
            'expires_at' => now()->addDays(self::DEFAULT_INVITE_EXPIRY_DAYS),
        ]);
    }

    /**
     * Clean up expired invitations
     */
    public static function cleanupExpired(): int
    {
        $expiredCount = self::expired()->active()->count();
        self::expired()->active()->update(['is_active' => false]);

        return $expiredCount;
    }

    /**
     * Get invitation statistics
     *
     * @return array<string, mixed>
     */
    public static function getStats(): array
    {
        return [
            'total' => self::count(),
            'active' => self::active()->count(),
            'valid' => self::valid()->count(),
            'expired' => self::expired()->count(),
            'used' => self::used()->count(),
            'unused' => self::unused()->count(),
        ];
    }
}
