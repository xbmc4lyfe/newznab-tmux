<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvitationService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_invitation_availability(int64_t total_invites, int64_t active_invitations, int64_t *out_available);
        C;

    /**
     * PHP stays the production default: the Rust helper misses the 1.5x mean/p99 gate
     * (see RustUserStatsInvitationParityTest for the paired benchmark evidence).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
     * Calculate a user's invitation quota breakdown: available invites are the total allowance
     * minus active pending invitations, and another invite may be sent while any remain.
     *
     * @return array{total_invites: int, active_pending: int, used_invitations: int, expired_invitations: int, cancelled_invitations: int, calculated_available: int, can_send_invite: bool}
     */
    public static function calculateInviteAvailability(
        int $totalInvites,
        int $activeInvitations,
        int $usedInvitations,
        int $expiredInvitations,
        int $cancelledInvitations,
    ): array {
        return self::$useRustFfi
            ? self::calculateInviteAvailabilityNative($totalInvites, $activeInvitations, $usedInvitations, $expiredInvitations, $cancelledInvitations)
            : self::calculateInviteAvailabilityPhp($totalInvites, $activeInvitations, $usedInvitations, $expiredInvitations, $cancelledInvitations);
    }

    /**
     * @return array{total_invites: int, active_pending: int, used_invitations: int, expired_invitations: int, cancelled_invitations: int, calculated_available: int, can_send_invite: bool}
     */
    public static function calculateInviteAvailabilityNative(
        int $totalInvites,
        int $activeInvitations,
        int $usedInvitations,
        int $expiredInvitations,
        int $cancelledInvitations,
    ): array {
        if (($ffi = self::ffi()) === null) {
            return self::calculateInviteAvailabilityPhp($totalInvites, $activeInvitations, $usedInvitations, $expiredInvitations, $cancelledInvitations);
        }
        try {
            $available = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_invitation_availability($totalInvites, $activeInvitations, $available);
            if ($rc !== 0 && $rc !== 1) {
                return self::calculateInviteAvailabilityPhp($totalInvites, $activeInvitations, $usedInvitations, $expiredInvitations, $cancelledInvitations);
            }

            return [
                'total_invites' => $totalInvites,
                'active_pending' => $activeInvitations,
                'used_invitations' => $usedInvitations,
                'expired_invitations' => $expiredInvitations,
                'cancelled_invitations' => $cancelledInvitations,
                'calculated_available' => (int) $available[0],
                'can_send_invite' => $rc === 1,
            ];
        } catch (Throwable) {
            return self::calculateInviteAvailabilityPhp($totalInvites, $activeInvitations, $usedInvitations, $expiredInvitations, $cancelledInvitations);
        }
    }

    /**
     * @return array{total_invites: int, active_pending: int, used_invitations: int, expired_invitations: int, cancelled_invitations: int, calculated_available: int, can_send_invite: bool}
     */
    public static function calculateInviteAvailabilityPhp(
        int $totalInvites,
        int $activeInvitations,
        int $usedInvitations,
        int $expiredInvitations,
        int $cancelledInvitations,
    ): array {
        $availableInvites = $totalInvites - $activeInvitations;

        return [
            'total_invites' => $totalInvites,
            'active_pending' => $activeInvitations,
            'used_invitations' => $usedInvitations,
            'expired_invitations' => $expiredInvitations,
            'cancelled_invitations' => $cancelledInvitations,
            'calculated_available' => $availableInvites,
            'can_send_invite' => $availableInvites > 0,
        ];
    }

    /**
     * Create and send an invitation
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createAndSendInvitation(
        string $email,
        int $invitedBy,
        int $expiryDays = Invitation::DEFAULT_INVITE_EXPIRY_DAYS,
        array $metadata = []
    ): Invitation {
        // Get the user sending the invitation
        $user = User::find($invitedBy);
        if (! $user) {
            throw new \Exception('User not found.');
        }

        // Calculate how many invites are currently "in use" (pending/active)
        $activeInvitations = Invitation::where('invited_by', $invitedBy)
            ->where('is_active', true)
            ->where('used_at', null)
            ->where('expires_at', '>', now())
            ->count();

        // Check if user has invites available (total invites - active pending invitations)
        $availableInvites = $user->invites - $activeInvitations;
        if ($availableInvites <= 0) {
            throw new \Exception('You have no invitations available. You have '.$activeInvitations.' pending invitation(s). Contact an administrator if you need more invitations.');
        }

        // Validate email
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email|unique:users,email',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Check if there's already a valid invitation for this email
        $existingInvitation = Invitation::where('email', Invitation::normalizeEmail($email))
            ->valid()
            ->first();

        if ($existingInvitation) {
            throw new \Exception('A valid invitation already exists for this email address.');
        }

        // Create the invitation (don't decrement invites here - only when used)
        $invitation = Invitation::createInvitation($email, $invitedBy, $expiryDays, $metadata);

        // Send the invitation email
        $this->sendInvitationEmail($invitation);

        return $invitation;
    }

    /**
     * Send invitation email
     */
    public function sendInvitationEmail(Invitation $invitation): void
    {
        Mail::to($invitation->email)->send(new InvitationMail($invitation));
    }

    /**
     * Resend an invitation
     */
    public function resendInvitation(int $invitationId): Invitation
    {
        $invitation = Invitation::findOrFail($invitationId);

        if (! $invitation->isValid()) {
            throw new \Exception('Cannot resend an invalid invitation.');
        }

        $this->sendInvitationEmail($invitation);

        return $invitation;
    }

    /**
     * Cancel an invitation
     */
    public function cancelInvitation(int $invitationId): bool
    {
        $invitation = Invitation::findOrFail($invitationId);

        if ($invitation->isUsed()) {
            throw new \Exception('Cannot cancel a used invitation.');
        }

        $invitation->is_active = false;

        return $invitation->save();
    }

    /**
     * Accept an invitation (use it for registration)
     *
     * @param  array<string, mixed>  $userData
     */
    public function acceptInvitation(string $token, array $userData): User
    {
        $invitation = Invitation::findValidByToken($token);

        if (! $invitation) {
            throw new \Exception('Invalid or expired invitation token.');
        }

        // Get the user who sent the invitation
        $inviter = User::find($invitation->invited_by);

        // Create the user
        $user = User::create(array_merge($userData, [
            'email' => $invitation->email,
            'invited_by' => $invitation->invited_by,
        ]));

        // Mark invitation as used
        $invitation->markAsUsed($user->id);

        // Now decrement the inviter's available invites (only when actually used)
        if ($inviter) {
            $inviter->decrement('invites');
        }

        return $user;
    }

    /**
     * Get invitation statistics for a user
     *
     * @return array<string, mixed>
     */
    public function getUserInvitationStats(int $userId): array
    {
        return [
            'sent' => Invitation::where('invited_by', $userId)->count(),
            'pending' => Invitation::where('invited_by', $userId)->valid()->count(),
            'used' => Invitation::where('invited_by', $userId)->used()->count(),
            'expired' => Invitation::where('invited_by', $userId)->expired()->count(),
        ];
    }

    /**
     * Get all invitations for a user
     */
    public function getUserInvitations(int $userId, ?string $status = null): mixed
    {
        $query = Invitation::where('invited_by', $userId)
            ->with(['usedBy'])
            ->orderBy('created_at', 'desc');

        return match ($status) {
            'valid' => $query->valid()->paginate(15),
            'used' => $query->used()->paginate(15),
            'expired' => $query->expired()->paginate(15),
            'pending' => $query->unused()->paginate(15),
            default => $query->paginate(15),
        };
    }

    /**
     * Clean up expired invitations
     */
    public function cleanupExpiredInvitations(): int
    {
        return Invitation::cleanupExpired();
    }

    /**
     * Check if a user can send more invitations
     */
    public function canUserSendInvitation(int $userId, ?int $maxInvitations = null): bool
    {
        if ($maxInvitations === null) {
            return true; // No limit set
        }

        $sentCount = Invitation::where('invited_by', $userId)->count();

        // The configured cap is the allowance and every sent invitation consumes it.
        return self::calculateInviteAvailability($maxInvitations, $sentCount, 0, 0, 0)['can_send_invite'];
    }

    /**
     * Get invitation by token for preview
     *
     * @return array<string, mixed>
     */
    public function getInvitationPreview(string $token): ?array
    {
        $invitation = Invitation::findByToken($token);

        if (! $invitation) {
            return null;
        }

        return [
            'email' => $invitation->email,
            'invited_by' => $invitation->invitedBy->username ?? 'Unknown',
            'expires_at' => $invitation->expires_at,
            'is_valid' => $invitation->isValid(),
            'is_expired' => $invitation->isExpired(),
            'is_used' => $invitation->isUsed(),
            'metadata' => $invitation->metadata,
        ];
    }

    /**
     * Get detailed invitation information for a user (for debugging)
     *
     * @return array<string, mixed>
     */
    public function getUserInvitationDetails(int $userId): array
    {
        $user = User::find($userId);
        if (! $user) {
            return [];
        }

        $totalInvites = (int) $user->invites;

        $activeInvitations = Invitation::where('invited_by', $userId)
            ->where('is_active', true)
            ->where('used_at', null)
            ->where('expires_at', '>', now())
            ->count();

        $usedInvitations = Invitation::where('invited_by', $userId)
            ->whereNotNull('used_at')
            ->count();

        $expiredInvitations = Invitation::where('invited_by', $userId)
            ->where('expires_at', '<=', now())
            ->where('used_at', null)
            ->count();

        $cancelledInvitations = Invitation::where('invited_by', $userId)
            ->where('is_active', false)
            ->where('used_at', null)
            ->count();

        return [
            'user_id' => $userId,
            'username' => $user->username,
        ] + self::calculateInviteAvailability(
            $totalInvites,
            $activeInvitations,
            $usedInvitations,
            $expiredInvitations,
            $cancelledInvitations,
        );
    }
}
