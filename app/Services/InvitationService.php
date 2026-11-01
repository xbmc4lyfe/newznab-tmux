<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvitationService
{
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
        $existingInvitation = Invitation::where('email', strtolower(trim($email)))
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
}
