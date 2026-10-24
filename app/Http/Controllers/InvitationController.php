<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\RegistrationStatusService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class InvitationController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_invitation_available(int64_t total, int64_t active_pending, int64_t *out_available);
    int32_t nntmux_invitation_row_plan(uint8_t has_used_by, uint8_t has_used_at);
    int32_t nntmux_invitation_role_index(
        const char *role,
        size_t role_len,
        const char *keys,
        size_t keys_len,
        const size_t *key_lens,
        size_t count
    );
    CDEF;

    private const int ROW_SET_USED_BY_USER = 1;

    private const int ROW_SET_USED_AT = 2;

    private const int ROLE_NOT_FOUND = -1;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustProfileSecurityPrivacyInviteControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected InvitationService $invitationService;

    public function __construct(
        InvitationService $invitationService,
        private readonly RegistrationStatusService $registrationStatusService
    ) {
        parent::__construct();
        $this->invitationService = $invitationService;
        $this->middleware('auth')->except(['show', 'accept']);
    }

    /**
     * Display a listing of the user's invitations
     */
    public function index(Request $request): View
    {

        $inviteMode = $this->registrationStatusService->resolve()['is_invite_only'];
        $status = $request->get('status');

        $this->viewData['meta_title'] = 'My Invitations';
        $this->viewData['meta_keywords'] = 'invitations,invite,users,manage';
        $this->viewData['meta_description'] = 'Manage your sent invitations and send new invitations to friends';
        $this->viewData['status'] = $status;

        if (! $inviteMode) {
            // Invitations disabled: show informational message only, no queries.
            $this->viewData['invite_mode'] = false;
            $this->viewData['stats'] = [];
            $this->viewData['invitations'] = [];
            $this->viewData['pagination_links'] = null;

            $this->viewData['meta_title'] = 'Invitations Disabled';
            $this->viewData['meta_keywords'] = 'invitations,disabled';
            $this->viewData['meta_description'] = 'Invitations are currently disabled on this site.';

            return view('invitations.index', $this->viewData);
        }

        /** @var User $user */
        $user = Auth::user();

        $invitations = $this->invitationService->getUserInvitations($user->id, $status);
        $stats = $this->invitationService->getUserInvitationStats($user->id);

        // Convert paginated results to array for Blade
        $invitationsArray = [];
        foreach ($invitations as $invitation) {
            // Add related user data and convert timestamps with Carbon's timestamp property
            $invitationsArray[] = self::normalizeInvitationRow(
                $invitation->toArray(),
                $invitation->usedBy ? $invitation->usedBy->toArray() : null,
                $invitation->created_at?->timestamp,
                $invitation->expires_at?->timestamp,
                $invitation->used_at ? $invitation->used_at->timestamp : null,
            );
        }

        $this->viewData['invite_mode'] = true;
        $this->viewData['invitations'] = $invitationsArray;
        $this->viewData['stats'] = $stats;
        $this->viewData['status'] = $status;
        $this->viewData['pagination_links'] = $invitations->links();

        return view('invitations.index', $this->viewData);
    }

    /**
     * Show the form for creating a new invitation
     */
    public function create(): View
    {

        $inviteMode = $this->registrationStatusService->resolve()['is_invite_only'];

        $this->viewData['meta_title'] = 'Send New Invitation';
        $this->viewData['meta_keywords'] = 'invitation,invite,send,new,user';
        $this->viewData['meta_description'] = 'Send a new invitation to invite someone to join the site';

        if (! $inviteMode) {
            $this->viewData['invite_mode'] = false;
            $this->viewData['meta_title'] = 'Invitations Disabled';
            $this->viewData['meta_keywords'] = 'invitations,disabled';
            $this->viewData['meta_description'] = 'Invitations are currently disabled on this site.';

            return view('invitations.create', $this->viewData);
        }

        /** @var User $user */
        $user = Auth::user();

        // Calculate available invites (total - active pending invitations)
        $activeInvitations = Invitation::where('invited_by', $user->id)
            ->where('is_active', true)
            ->where('used_at', null)
            ->where('expires_at', '>', now())
            ->count();

        $availableInvites = self::availableInvites($user->invites, $activeInvitations);

        $this->viewData['invite_mode'] = true;
        $this->viewData['user_roles'] = config('nntmux.user_roles', []);
        $this->viewData['user_invites_left'] = $availableInvites['available'];
        $this->viewData['user_invites_total'] = $user->invites;
        $this->viewData['user_invites_pending'] = $activeInvitations;
        $this->viewData['can_send_invites'] = $availableInvites['can_send_invites'];

        return view('invitations.create', $this->viewData);
    }

    /**
     * Store a newly created invitation
     */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->registrationStatusService->resolve()['is_invite_only']) {
            return redirect()->route('invitations.index')->with('error', 'Invitations are currently disabled.');
        }

        $request->validate([
            'email' => 'required|email|unique:users,email',
            'expiry_days' => 'sometimes|integer|min:1|max:30',
            'role' => 'sometimes|integer|in:'.implode(',', array_keys(config('nntmux.user_roles', []))),
        ]);

        try {
            $expiryDays = $request->get('expiry_days', Invitation::DEFAULT_INVITE_EXPIRY_DAYS);
            $metadata = [];

            if ($request->has('role')) {
                $metadata['role'] = $request->get('role');
            }

            $invitation = $this->invitationService->createAndSendInvitation(
                $request->email,
                (int) Auth::id(),
                $expiryDays,
                $metadata
            );

            return redirect()->route('invitations.index')
                ->with('success', 'Invitation sent successfully to '.$request->email);

        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Display the specified invitation
     */
    public function show(string $token): View
    {

        $preview = $this->invitationService->getInvitationPreview($token);

        // Convert timestamps - use Carbon's timestamp property
        if ($preview && isset($preview['expires_at'])) {
            $preview['expires_at'] = $preview['expires_at']->timestamp;
        }

        // Add role name if role is set
        if ($preview && isset($preview['metadata']['role'])) {
            $preview['role_name'] = self::previewRoleName($preview['metadata']['role'], config('nntmux.user_roles', []));
        }

        $this->viewData['preview'] = $preview;
        $this->viewData['token'] = $token;
        $this->viewData['registrationStatus'] = $this->registrationStatusService->resolve();

        // Set meta information
        $this->viewData['meta_title'] = $preview ? 'Invitation to Join' : 'Invalid Invitation';
        $this->viewData['meta_keywords'] = 'invitation,join,register,signup';
        $this->viewData['meta_description'] = $preview ? 'You have been invited to join our community' : 'This invitation link is invalid or expired';

        return view('invitations.show', $this->viewData);
    }

    /**
     * Resend an invitation
     */
    public function resend(int $id): RedirectResponse
    {
        if (! $this->registrationStatusService->resolve()['is_invite_only']) {
            return redirect()->route('invitations.index')->with('error', 'Invitations are currently disabled.');
        }

        try {
            $invitation = Invitation::findOrFail($id);

            // Check if user owns this invitation
            if ($invitation->invited_by !== (int) Auth::id()) {
                abort(403, 'Unauthorized');
            }

            $this->invitationService->resendInvitation($id);

            return redirect()->route('invitations.index')
                ->with('success', 'Invitation resent successfully');

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel an invitation
     */
    public function destroy(int $id): RedirectResponse
    {
        if (! $this->registrationStatusService->resolve()['is_invite_only']) {
            return redirect()->route('invitations.index')->with('error', 'Invitations are currently disabled.');
        }

        try {
            $invitation = Invitation::findOrFail($id);

            // Check if user owns this invitation
            if ($invitation->invited_by !== (int) Auth::id()) {
                abort(403, 'Unauthorized');
            }

            $this->invitationService->cancelInvitation($id);

            return redirect()->route('invitations.index')
                ->with('success', 'Invitation cancelled successfully');

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Get invitation statistics (API endpoint)
     */
    public function stats(): JsonResponse
    {
        if (! $this->registrationStatusService->resolve()['is_invite_only']) {
            return response()->json(['message' => 'Invitations are disabled'], 404);
        }

        $stats = $this->invitationService->getUserInvitationStats((int) Auth::id());

        return response()->json($stats);
    }

    /**
     * Clean up expired invitations (admin only)
     */
    public function cleanup(): JsonResponse
    {
        // Check if user is admin
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user?->hasRole('Admin')) {
            abort(403, 'Unauthorized');
        }

        $cleanedCount = $this->invitationService->cleanupExpiredInvitations();

        return response()->json([
            'message' => "Cleaned up {$cleanedCount} expired invitations",
            'count' => $cleanedCount,
        ]);
    }

    /**
     * Overrides the base page toggle for this controller's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Available invites (total minus active pending invitations) and whether any remain.
     *
     * @return array{available: int|float, can_send_invites: bool}
     */
    public static function availableInvites(int $total, int $activePending): array
    {
        return self::$useRustFfi
            ? self::availableInvitesNative($total, $activePending)
            : self::availableInvitesPhp($total, $activePending);
    }

    /**
     * @return array{available: int|float, can_send_invites: bool}
     */
    public static function availableInvitesPhp(int $total, int $activePending): array
    {
        $available = $total - $activePending;

        return ['available' => $available, 'can_send_invites' => $available > 0];
    }

    /**
     * @return array{available: int|float, can_send_invites: bool}
     */
    public static function availableInvitesNative(int $total, int $activePending): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::availableInvitesPhp($total, $activePending);
        }

        try {
            $out = $ffi->new('int64_t[1]');
            $canSend = self::callFfi($ffi, 'nntmux_invitation_available', $total, $activePending, $out);

            return match ($canSend) {
                0, 1 => ['available' => (int) $out[0], 'can_send_invites' => $canSend === 1],
                // Overflow: PHP promotes the difference to float.
                default => self::availableInvitesPhp($total, $activePending),
            };
        } catch (Throwable) {
            return self::availableInvitesPhp($total, $activePending);
        }
    }

    /**
     * Blade invitation row: `used_by_user` when the invitee is loaded, Unix timestamps for
     * `created_at`/`expires_at`, and `used_at` only once used.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $usedByUser
     * @return array<string, mixed>
     */
    public static function normalizeInvitationRow(array $row, ?array $usedByUser, ?int $createdAt, ?int $expiresAt, ?int $usedAt): array
    {
        return self::$useRustFfi
            ? self::normalizeInvitationRowNative($row, $usedByUser, $createdAt, $expiresAt, $usedAt)
            : self::normalizeInvitationRowPhp($row, $usedByUser, $createdAt, $expiresAt, $usedAt);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $usedByUser
     * @return array<string, mixed>
     */
    public static function normalizeInvitationRowPhp(array $row, ?array $usedByUser, ?int $createdAt, ?int $expiresAt, ?int $usedAt): array
    {
        if ($usedByUser !== null) {
            $row['used_by_user'] = $usedByUser;
        }
        $row['created_at'] = $createdAt;
        $row['expires_at'] = $expiresAt;
        if ($usedAt !== null) {
            $row['used_at'] = $usedAt;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $usedByUser
     * @return array<string, mixed>
     */
    public static function normalizeInvitationRowNative(array $row, ?array $usedByUser, ?int $createdAt, ?int $expiresAt, ?int $usedAt): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeInvitationRowPhp($row, $usedByUser, $createdAt, $expiresAt, $usedAt);
        }

        try {
            $plan = self::callFfi($ffi, 'nntmux_invitation_row_plan', $usedByUser !== null ? 1 : 0, $usedAt !== null ? 1 : 0);
            if (! \is_int($plan) || $plan < 0 || ($plan & ~(self::ROW_SET_USED_BY_USER | self::ROW_SET_USED_AT)) !== 0) {
                return self::normalizeInvitationRowPhp($row, $usedByUser, $createdAt, $expiresAt, $usedAt);
            }
            if (($plan & self::ROW_SET_USED_BY_USER) !== 0) {
                $row['used_by_user'] = $usedByUser;
            }
            $row['created_at'] = $createdAt;
            $row['expires_at'] = $expiresAt;
            if (($plan & self::ROW_SET_USED_AT) !== 0) {
                $row['used_at'] = $usedAt;
            }

            return $row;
        } catch (Throwable) {
            return self::normalizeInvitationRowPhp($row, $usedByUser, $createdAt, $expiresAt, $usedAt);
        }
    }

    /**
     * Invitation preview role name: the configured role for the metadata role key, or `Default`.
     *
     * @param  array<array-key, mixed>  $roles
     */
    public static function previewRoleName(mixed $role, array $roles): mixed
    {
        return self::$useRustFfi
            ? self::previewRoleNameNative($role, $roles)
            : self::previewRoleNamePhp($role, $roles);
    }

    /**
     * @param  array<array-key, mixed>  $roles
     */
    public static function previewRoleNamePhp(mixed $role, array $roles): mixed
    {
        return $roles[$role] ?? 'Default';
    }

    /**
     * Integer and string role keys are matched natively; PHP stores integer-like string keys as
     * integers, so comparing key strings byte for byte matches PHP array key lookup.
     *
     * @param  array<array-key, mixed>  $roles
     */
    public static function previewRoleNameNative(mixed $role, array $roles): mixed
    {
        $ffi = self::ffi();
        if ($ffi === null || (! \is_int($role) && ! \is_string($role))) {
            return self::previewRoleNamePhp($role, $roles);
        }

        try {
            $roleKey = (string) $role;
            $keys = array_map(strval(...), array_keys($roles));
            $count = \count($keys);
            $lens = null;
            if ($count > 0) {
                $lens = $ffi->new("size_t[{$count}]");
                foreach ($keys as $index => $key) {
                    $lens[$index] = \strlen($key);
                }
            }
            $payload = implode('', $keys);
            $index = self::callFfi($ffi, 'nntmux_invitation_role_index', $roleKey, \strlen($roleKey), $payload, \strlen($payload), $lens, $count);
            if ($index === self::ROLE_NOT_FOUND) {
                return 'Default';
            }
            if (! \is_int($index) || $index < 0 || $index >= $count) {
                return self::previewRoleNamePhp($role, $roles);
            }

            return array_values($roles)[$index] ?? 'Default';
        } catch (Throwable) {
            return self::previewRoleNamePhp($role, $roles);
        }
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

        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
