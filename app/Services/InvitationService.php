<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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

    // ---------------------------------------------------------------------
    // Wave 50.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 3.33x, p99 2.62x; uncommon mean 3.25x, p99 2.63x); see RustBookCandidateFanartForkingGenreInvitationW50ParityTest. */
    private const bool W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        ssize_t nntmux_w504_invitation_ledger_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** State flags returned by invitationLedgerBatch(). */
    public const int W50_VALID = 1;

    public const int W50_EXPIRED = 2;

    public const int W50_USED = 4;

    public const int W50_CANCELLED = 8;

    public const int W50_PLAUSIBLE_EMAIL = 16;

    public const int W50_UNNORMALIZED_EMAIL = 32;

    private const int W50_SCALARS = 3;

    private static ?bool $useW50RustFfi = null;

    private static FFI|false|null $w50Ffi = null;

    private static ?CData $w50Out = null;

    private static int $w50OutCap = 0;

    public static function usesW50RustFfi(): bool
    {
        return self::$useW50RustFfi ?? self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W50 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW50RustFfi;
        self::$useW50RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW50RustFfi = $previous;
        }
    }

    /**
     * Ledger a batch of invitations, three ints each: [state flags, first earlier duplicate,
     * trimmed email span]. Each invitation supplies three ints in $scalars ([is_active 0/1,
     * expires_at µs, has used_at 0/1]) and its email; $nowUs is the shared clock in
     * Invitation::toMicroTimestamp() units.
     *
     * States follow Invitation::evaluateInvitationState(): 1 valid, 2 expired, 4 used, plus
     * 8 cancelled (inactive and unused). 16 marks a plausible normalized email (one '@'
     * after a non-empty local part, a dotted domain neither starting nor ending in '.', no
     * space, control or DEL bytes) and 32 an email that Invitation::normalizeEmail() would
     * change. The duplicate is the first earlier row with the same non-empty normalized email,
     * otherwise -1; the span is the trimmed email as offset << 32 | length.
     *
     * @param  list<int>  $scalars
     * @param  list<string>  $emails
     * @return list<int>
     */
    public static function invitationLedgerBatch(int $nowUs, array $scalars, array $emails): array
    {
        return self::usesW50RustFfi()
            ? self::invitationLedgerBatchNative($nowUs, $scalars, $emails)
            : self::invitationLedgerBatchPhp($nowUs, $scalars, $emails);
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $emails
     * @return list<int>
     */
    public static function invitationLedgerBatchNative(int $nowUs, array $scalars, array $emails): array
    {
        self::w50AssertShape($scalars, $emails);
        if ($emails === []) {
            return [];
        }

        return self::w50Call(implode("\0", $emails), pack('P*', $nowUs, ...$scalars), \count($emails))
            ?? self::invitationLedgerBatchPhp($nowUs, $scalars, $emails);
    }

    /**
     * @param  list<int>  $scalars
     * @param  list<string>  $emails
     * @return list<int>
     */
    public static function invitationLedgerBatchPhp(int $nowUs, array $scalars, array $emails): array
    {
        self::w50AssertShape($scalars, $emails);
        $out = [];
        $seen = [];
        $at = 0;
        foreach ($emails as $i => $raw) {
            $active = $scalars[$at] !== 0;
            $expiresAt = $scalars[$at + 1];
            $used = $scalars[$at + 2] !== 0;
            $at += self::W50_SCALARS;
            $state = 0;
            if ($active && $expiresAt > $nowUs && ! $used) {
                $state |= self::W50_VALID;
            }
            if ($expiresAt < $nowUs) {
                $state |= self::W50_EXPIRED;
            }
            if ($used) {
                $state |= self::W50_USED;
            } elseif (! $active) {
                $state |= self::W50_CANCELLED;
            }
            $email = trim($raw);
            $normalized = strtolower($email);
            if ($normalized !== $raw) {
                $state |= self::W50_UNNORMALIZED_EMAIL;
            }
            $first = -1;
            if ($normalized !== '') {
                $first = $seen[$normalized] ??= $i;
                $first = $first === $i ? -1 : $first;
                $sign = strpos($normalized, '@');
                if ($sign !== false && $sign > 0 && strpos($normalized, '@', $sign + 1) === false
                    && \strlen($normalized) - $sign > 3 && $normalized[$sign + 1] !== '.' && $normalized[-1] !== '.'
                    && strpos($normalized, '.', $sign + 2) !== false && preg_match('/[\x00-\x20\x7F]/', $normalized) !== 1) {
                    $state |= self::W50_PLAUSIBLE_EMAIL;
                }
            }
            array_push($out, $state, $first, (strspn($raw, " \t\n\r\0\x0B") << 32) | \strlen($email));
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined emails, little-endian int64 clock and
     * scalars, three int64 words back per invitation. Null when the library is missing or
     * declines.
     *
     * @return list<int>|null
     */
    private static function w50Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w50Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w50', self::W50_FFI_CDEF) ?? false;
            $ffi = self::$w50Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 3;
            $out = self::$w50Out;
            if ($out === null || self::$w50OutCap < $need) {
                self::$w50OutCap = max(1024, $need);
                $out = self::$w50Out = $ffi->new('int64_t['.self::$w50OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
            $written = $ffi->nntmux_w504_invitation_ledger_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w50OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $scalars
     * @param  array<array-key, mixed>  $emails
     */
    private static function w50AssertShape(array $scalars, array $emails): void
    {
        if (! array_is_list($scalars) || ! array_is_list($emails) || \count($scalars) !== \count($emails) * self::W50_SCALARS) {
            throw new InvalidArgumentException('W50 invitation batches need list columns and three scalars per invitation.');
        }
    }

    // ========================================================================
    // WAVE 67.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four 1000-round paired runs reached 2.09x common mean, 1.78x common p99, 1.78x uncommon mean and 1.64x uncommon p99; see RustIgdbCompanyGamePlatformInvitationIsbnDbW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    private const string W67_FFI_CDEF = 'ssize_t nntmux_w674_invitation_service_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w67InvitationServicePlanBatch() record. */
    public const int W67_ROW_WORDS = 3;

    /** int64 summary words after the w67InvitationServicePlanBatch() records (none for an empty batch). */
    public const int W67_TRAILER_WORDS = 10;

    /** Trimmed, lower-cased inviter role => [code, invite quota (-1 unlimited)]. */
    private const array W67_ROLES = ['admin' => [1, -1], 'moderator' => [2, 25], 'friend' => [3, 10], 'user' => [4, 3], 'disabled' => [5, 0]];

    /** Clocks clamp to [0, 2^40] seconds so differences cannot overflow. */
    private const int W67_CLOCK_MAX = 1 << 40;

    private const string W67_ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const string W67_CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20\x7F";

    /**
     * Invitation plan rows for [token, email, inviter role, now, created at, expires at, used
     * at, active flag, inviter ID, inviter invite balance] items (clocks in Unix seconds).
     *
     * Token: 0 empty, 1 64 ASCII alphanumerics (Str::random(64)), 2 wrong length, 3 bad
     * character. Role (trimmed, case-insensitive): 1 admin (unlimited), 2 moderator (25), 3
     * friend (10), 4 user (3), 5 disabled (0), 0 unknown (0); the available invites are the role
     * quota capped by the non-negative balance (-1 unlimited). Expiry: 0 none, 1 expired, 2 under
     * a day left, 3 later. Statuses: 3 used, 4 inactive, 2 expired, 5 bad token, then pending
     * invitations take per-inviter ordinals: 6 over the available invites, 1 sendable. The
     * trimmed email is plausible with one '@' after a non-empty local part, a domain that neither
     * starts nor ends with '.' and holds a later '.', and no control bytes or spaces; it is
     * unnormalized when trimming or lower-casing changes it.
     *
     * Rows [status | token << 3 | expiry << 5 | created after expiry << 7 | lifetime over 30
     * days << 8 | role << 9 | plausible << 12 | unnormalized << 13 | hours left << 14 | lifetime
     * days << 30 | available + 1 << 46, fnv1a32(strtolower(email)), inviter ordinal (-1 unless
     * pending)], then the trailer [records, the six status counts, plausible emails, distinct
     * pending inviters, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w67InvitationServicePlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67InvitationServicePlanBatchNative($items) : self::w67InvitationServicePlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w67InvitationServicePlanBatchPhp(array $items): array
    {
        self::w67Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        $sent = [];
        foreach ($items as [$token, $raw, $inviterRole, $now, $created, $expires, $used, $active, $inviter, $invites]) {
            $length = \strlen($token);
            $tokenCode = $length === 0 ? 0 : ($length !== 64 ? 2 : (strspn($token, self::W67_ALNUM) === 64 ? 1 : 3));
            $email = trim($raw);
            [$role, $quota] = self::W67_ROLES[strtolower(trim($inviterRole))] ?? [0, 0];
            $now = max(0, min($now, self::W67_CLOCK_MAX));
            $created = max(0, min($created, self::W67_CLOCK_MAX));
            $expires = max(0, min($expires, self::W67_CLOCK_MAX));
            $used = max(0, min($used, self::W67_CLOCK_MAX));
            $expiry = $expires === 0 ? 0 : ($expires < $now ? 1 : ($expires - $now < 86_400 ? 2 : 3));
            $hours = $expires > $now ? min(intdiv($expires - $now, 3600), 0xFFFF) : 0;
            $inconsistent = $created > 0 && $expires > 0 && $created > $expires;
            $days = $created > 0 && $expires >= $created ? intdiv($expires - $created, 86_400) : 0;
            $available = $quota < 0 ? -1 : min($quota, max($invites, 0));
            $ordinal = -1;
            if ($used > 0) {
                $status = 3;
            } elseif ($active === 0) {
                $status = 4;
            } elseif ($expiry === 1) {
                $status = 2;
            } elseif ($tokenCode !== 1) {
                $status = 5;
            } else {
                $ordinal = $sent[$inviter] ?? 0;
                $sent[$inviter] = $ordinal + 1;
                $status = $available >= 0 && $ordinal >= $available ? 6 : 1;
            }
            $lower = strtolower($email);
            $emailLength = \strlen($email);
            $sign = strpos($email, '@');
            $plausible = $sign !== false && $sign > 0 && $emailLength - $sign > 3 && strpos($email, '@', $sign + 1) === false && $email[$sign + 1] !== '.'
                && $email[$emailLength - 1] !== '.' && strpos($email, '.', $sign + 2) !== false && strcspn($email, self::W67_CONTROL) === $emailLength;
            $unnormalized = $emailLength !== \strlen($raw) || $lower !== $email;
            $word = $status | $tokenCode << 3 | $expiry << 5 | (int) $inconsistent << 7 | (int) ($days > 30) << 8 | $role << 9 | (int) $plausible << 12
                | (int) $unnormalized << 13 | $hours << 14 | min($days, 0xFFFF) << 30 | ($available + 1) << 46;
            $emailHash = self::w67Fnv($lower);
            array_push($out, $word, $emailHash, $ordinal);
            $t[$status]++;
            $t[7] += (int) $plausible;
            $value = $word ^ $emailHash << 8 ^ $ordinal;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[8] = \count($sent);
        $t[9] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int}>  $items
     * @return list<int>
     */
    public static function w67InvitationServicePlanBatchNative(array $items): array
    {
        self::w67Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w67Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)]);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6),
            ...array_column($items, 7), ...array_column($items, 8), ...array_column($items, 9));

        return self::w67Call($blob, $twin, $ints, $count, $count * self::W67_ROW_WORDS + self::W67_TRAILER_WORDS)
            ?? self::w67InvitationServicePlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w67Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W67 invitation service plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 10 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2])
                || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8])
                || ! \is_int($item[9])) {
                throw new InvalidArgumentException('W67 invitation service plan batch items must be [string token, string email, string inviter role, int now, int created at, int expires at, int used at, int active, int inviter ID, int invite balance] lists.');
            }
        }
    }

    public static function usesW67RustFfi(): bool
    {
        $enabled = self::w67RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W67_RUST_FFI_DEFAULT;
    }

    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('enabled', true);
    }

    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('enabled', false);
    }

    /** Drop the W67 override, binding, output buffer and declined-call count; routing returns to W67_RUST_FFI_DEFAULT. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    /**
     * Run $callback with the W67 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('enabled');
        self::w67RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w67Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w67Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w67RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w67', self::W67_FFI_CDEF) ?? false;
            self::w67RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w67RustFfiState('out', $out);
                self::w67RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W67_FFI_CDEF.)
            $written = $ffi->nntmux_w674_invitation_service_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w67RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W67 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w67Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
