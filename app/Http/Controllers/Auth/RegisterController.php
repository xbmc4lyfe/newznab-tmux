<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRegisterRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Rules\ValidEmailDomain;
use App\Services\RegistrationStatusService;
use App\Support\Auth\RegistersUsers;
use App\Support\PermissionSyncHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Throwable;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_register_extract_invite_code_nul(
    uint8_t has_invitecode,
    const char *invitecode_ptr,
    size_t invitecode_len,
    uint8_t has_token,
    const char *token_ptr,
    size_t token_len,
    uint8_t has_invitation,
    const char *invitation_ptr,
    size_t invitation_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_register_emails_match(
    const char *first_ptr,
    size_t first_len,
    const char *second_ptr,
    size_t second_len,
    int32_t *out_matches
);
ssize_t nntmux_register_evaluate_form_state_nul(
    uint8_t is_open,
    uint8_t is_invite_only,
    uint8_t scheduled_override_active,
    const char *status_msg_ptr,
    size_t status_msg_len,
    const char *invite_code_ptr,
    size_t invite_code_len,
    uint8_t invitation_token_valid,
    uint8_t has_invitation_email,
    const char *invitation_email_ptr,
    size_t invitation_email_len,
    const char *initial_error_ptr,
    size_t initial_error_len,
    int32_t initial_show_register,
    int32_t *out_show_register,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Where to redirect users after registration.
     */
    protected string $redirectTo = '/';

    private string $inviteCodeQuery = '';

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

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
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
     * @return array{invite_code: string, invite_code_query: string, matched_param: string}
     */
    public static function resolveInviteCode(
        ?string $invitecode,
        ?string $token = null,
        ?string $invitation = null
    ): array {
        if (self::$useRustFfi) {
            return self::extractInviteCodeNative($invitecode, $token, $invitation);
        }

        return self::extractInviteCodePhp($invitecode, $token, $invitation);
    }

    /**
     * @return array{invite_code: string, invite_code_query: string, matched_param: string}
     */
    public static function extractInviteCodePhp(
        ?string $invitecode,
        ?string $token = null,
        ?string $invitation = null
    ): array {
        $candidates = [
            'invitecode' => $invitecode,
            'token' => $token,
            'invitation' => $invitation,
        ];

        foreach ($candidates as $parameter => $value) {
            if ($value !== null && trim($value) !== '') {
                return [
                    'invite_code' => $value,
                    'invite_code_query' => '&'.$parameter.'='.$value,
                    'matched_param' => $parameter,
                ];
            }
        }

        return [
            'invite_code' => '',
            'invite_code_query' => '',
            'matched_param' => '',
        ];
    }

    /**
     * @return array{invite_code: string, invite_code_query: string, matched_param: string}
     */
    public static function extractInviteCodeNative(
        ?string $invitecode,
        ?string $token = null,
        ?string $invitation = null
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::extractInviteCodePhp($invitecode, $token, $invitation);
        }

        try {
            $invCodeStr = $invitecode ?? '';
            $tokStr = $token ?? '';
            $invStr = $invitation ?? '';
            $cap = max(128, (strlen($invCodeStr) + strlen($tokStr) + strlen($invStr)) * 2 + 64);
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_register_extract_invite_code_nul(
                $invitecode !== null ? 1 : 0,
                $invCodeStr,
                strlen($invCodeStr),
                $token !== null ? 1 : 0,
                $tokStr,
                strlen($tokStr),
                $invitation !== null ? 1 : 0,
                $invStr,
                strlen($invStr),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::extractInviteCodePhp($invitecode, $token, $invitation);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 3) {
                return self::extractInviteCodePhp($invitecode, $token, $invitation);
            }

            return [
                'invite_code' => $parts[0],
                'invite_code_query' => $parts[1],
                'matched_param' => $parts[2],
            ];
        } catch (Throwable) {
            return self::extractInviteCodePhp($invitecode, $token, $invitation);
        }
    }

    public static function emailsMatch(string $firstEmail, string $secondEmail): bool
    {
        if (self::$useRustFfi) {
            return self::emailsMatchNative($firstEmail, $secondEmail);
        }

        return self::emailsMatchPhp($firstEmail, $secondEmail);
    }

    public static function emailsMatchPhp(string $firstEmail, string $secondEmail): bool
    {
        return strcasecmp(trim($firstEmail), trim($secondEmail)) === 0;
    }

    public static function emailsMatchNative(string $firstEmail, string $secondEmail): bool
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::emailsMatchPhp($firstEmail, $secondEmail);
        }

        try {
            $outMatches = $ffi->new('int32_t[1]');

            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_register_emails_match(
                $firstEmail,
                strlen($firstEmail),
                $secondEmail,
                strlen($secondEmail),
                $outMatches
            );
            $matched = $outMatches[0];
            if ($rc !== 1 || ! is_int($matched)) {
                return self::emailsMatchPhp($firstEmail, $secondEmail);
            }

            return $matched === 1;
        } catch (Throwable) {
            return self::emailsMatchPhp($firstEmail, $secondEmail);
        }
    }

    /**
     * @return array{
     *     showregister: int,
     *     error: string,
     *     notice: string,
     *     email: string
     * }
     */
    public static function evaluateRegistrationFormState(
        bool $isOpen,
        bool $isInviteOnly,
        bool $scheduledOverrideActive,
        string $statusMessage,
        string $inviteCode,
        bool $invitationTokenValid,
        ?string $invitationEmail = null,
        string $initialError = '',
        int $initialShowRegister = 0
    ): array {
        if (self::$useRustFfi) {
            return self::evaluateRegistrationFormStateNative(
                $isOpen,
                $isInviteOnly,
                $scheduledOverrideActive,
                $statusMessage,
                $inviteCode,
                $invitationTokenValid,
                $invitationEmail,
                $initialError,
                $initialShowRegister
            );
        }

        return self::evaluateRegistrationFormStatePhp(
            $isOpen,
            $isInviteOnly,
            $scheduledOverrideActive,
            $statusMessage,
            $inviteCode,
            $invitationTokenValid,
            $invitationEmail,
            $initialError,
            $initialShowRegister
        );
    }

    /**
     * @return array{
     *     showregister: int,
     *     error: string,
     *     notice: string,
     *     email: string
     * }
     */
    public static function evaluateRegistrationFormStatePhp(
        bool $isOpen,
        bool $isInviteOnly,
        bool $scheduledOverrideActive,
        string $statusMessage,
        string $inviteCode,
        bool $invitationTokenValid,
        ?string $invitationEmail = null,
        string $initialError = '',
        int $initialShowRegister = 0
    ): array {
        $showRegister = $initialShowRegister;
        $error = $initialError;
        $notice = '';
        $emailFromInvite = '';

        if ($isOpen) {
            $showRegister = 1;

            if (! empty($inviteCode)) {
                if ($invitationTokenValid && ! empty($invitationEmail)) {
                    $emailFromInvite = $invitationEmail;
                } elseif (! $invitationTokenValid) {
                    $notice = 'Registration is currently open, so you can register without a valid invitation.';
                }
            }

            if ($scheduledOverrideActive) {
                $notice = $statusMessage;
            }
        } elseif ($isInviteOnly) {
            if (! empty($inviteCode) && $invitationTokenValid) {
                $showRegister = 1;

                if (! empty($invitationEmail)) {
                    $emailFromInvite = $invitationEmail;
                }
            } elseif (! empty($inviteCode)) {
                $error = 'Invalid or expired invitation token!';
                $showRegister = 0;
            } else {
                $error = $statusMessage;
                $showRegister = 0;
            }
        } else {
            $error = $statusMessage;
            $showRegister = 0;
        }

        return [
            'showregister' => $showRegister,
            'error' => $error,
            'notice' => $notice,
            'email' => $emailFromInvite,
        ];
    }

    /**
     * @return array{
     *     showregister: int,
     *     error: string,
     *     notice: string,
     *     email: string
     * }
     */
    public static function evaluateRegistrationFormStateNative(
        bool $isOpen,
        bool $isInviteOnly,
        bool $scheduledOverrideActive,
        string $statusMessage,
        string $inviteCode,
        bool $invitationTokenValid,
        ?string $invitationEmail = null,
        string $initialError = '',
        int $initialShowRegister = 0
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::evaluateRegistrationFormStatePhp(
                $isOpen,
                $isInviteOnly,
                $scheduledOverrideActive,
                $statusMessage,
                $inviteCode,
                $invitationTokenValid,
                $invitationEmail,
                $initialError,
                $initialShowRegister
            );
        }

        try {
            $invEmailStr = $invitationEmail ?? '';
            $cap = max(
                256,
                strlen($statusMessage) + strlen($invEmailStr) + strlen($initialError) + 160
            );
            $outShowRegister = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_register_evaluate_form_state_nul(
                $isOpen ? 1 : 0,
                $isInviteOnly ? 1 : 0,
                $scheduledOverrideActive ? 1 : 0,
                $statusMessage,
                strlen($statusMessage),
                $inviteCode,
                strlen($inviteCode),
                $invitationTokenValid ? 1 : 0,
                $invitationEmail !== null ? 1 : 0,
                $invEmailStr,
                strlen($invEmailStr),
                $initialError,
                strlen($initialError),
                $initialShowRegister,
                $outShowRegister,
                $outBuf,
                $cap
            );
            $showRegister = $outShowRegister[0];
            if (! is_int($written) || $written <= 0 || ! is_int($showRegister)) {
                return self::evaluateRegistrationFormStatePhp(
                    $isOpen,
                    $isInviteOnly,
                    $scheduledOverrideActive,
                    $statusMessage,
                    $inviteCode,
                    $invitationTokenValid,
                    $invitationEmail,
                    $initialError,
                    $initialShowRegister
                );
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 3) {
                return self::evaluateRegistrationFormStatePhp(
                    $isOpen,
                    $isInviteOnly,
                    $scheduledOverrideActive,
                    $statusMessage,
                    $inviteCode,
                    $invitationTokenValid,
                    $invitationEmail,
                    $initialError,
                    $initialShowRegister
                );
            }

            return [
                'showregister' => $showRegister,
                'error' => $parts[0],
                'notice' => $parts[1],
                'email' => $parts[2],
            ];
        } catch (Throwable) {
            return self::evaluateRegistrationFormStatePhp(
                $isOpen,
                $isInviteOnly,
                $scheduledOverrideActive,
                $statusMessage,
                $inviteCode,
                $invitationTokenValid,
                $invitationEmail,
                $initialError,
                $initialShowRegister
            );
        }
    }

    public function __construct(
        private readonly RegistrationStatusService $registrationStatusService
    ) {
        $this->middleware('guest');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function create(array $data): User
    {
        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => User::hashPassword($data['password']),
            'host' => $data['host'] ?? '',
            'roles_id' => $data['roles_id'],
            'notes' => $data['notes'],
            'invites' => $data['defaultinvites'],
            'api_token' => md5(Str::random(40)),
            'verified' => false,
            'email_verified_at' => null,
        ]);

        $role = Role::query()->where('id', '=', $data['roles_id'])->first();

        if ($role !== null) {
            $user->syncRoles([$role->name]);
            PermissionSyncHelper::grantInheritedPermissions($user);
        }

        return $user;
    }

    /**
     * @return RedirectResponse|Redirector|void
     *
     * @throws ValidationException
     */
    public function register(RegisterRegisterRequest $request)
    {
        $error = '';
        $showRegister = 1;
        $inviteCode = $this->extractInviteCode($request);

        $validator = Validator::make($request->all(), [
            'username' => ['required', 'string', 'min:5', 'max:255', 'unique:users'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->where(fn ($query) => $query->whereNull('deleted_at')),
                app(ValidEmailDomain::class),
            ],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ], [
            'password.min' => 'The password must be at least 8 characters.',
            'password.letters' => 'The password must contain letters.',
            'password.mixed_case' => 'The password must contain both uppercase and lowercase letters.',
            'password.numbers' => 'The password must contain at least one number.',
            'password.symbols' => 'The password must contain at least one symbol.',
            'password.uncompromised' => 'The password appears in a data breach and should not be used.',
        ]);

        if ($validator->fails()) {
            $this->logRegistrationFailure(
                $request,
                'validation_failed',
                'The registration request failed validation.',
                'warning',
                ['validation_errors' => $validator->errors()->toArray()]
            );

            return redirect()->back()
                ->withErrors($validator)
                ->withInput($request->except('password', 'password_confirmation'));
        }

        $action = $request->input('action') ?? 'view';

        switch ($action) {
            case 'submit':
                $userName = (string) $request->input('username');
                $password = (string) $request->input('password');
                $email = (string) $request->input('email');

                // Check if the email is associated with a soft-deleted user
                $existingUser = User::withTrashed()->where('email', $email)->first();
                if ($existingUser && $existingUser->trashed()) {
                    return $this->redirectBackWithRegistrationError(
                        $request,
                        'This email address belongs to a deactivated account. Please contact us if you need help reactivating it.',
                        'deactivated_account'
                    );
                }

                // Get the default user role.
                $userDefault = Role::query()->where('isdefault', '=', 1)->first();

                $status = $this->registrationStatusService->resolve();
                $invitationValid = $this->isInvitationValid($inviteCode, $email);

                if ($status['is_open'] || ($status['is_invite_only'] && $invitationValid)) {
                    $invitation = null;

                    if ($invitationValid) {
                        $invitation = Invitation::findValidByToken($inviteCode);
                    }

                    $userData = [
                        'username' => $userName,
                        'password' => $password,
                        'email' => $email,
                        'host' => $request->ip(),
                        'roles_id' => $userDefault !== null ? $userDefault['id'] : UserRole::USER->value,
                        'notes' => '',
                        'defaultinvites' => $userDefault !== null ? $userDefault['defaultinvites'] : Invitation::DEFAULT_INVITES,
                    ];

                    // Apply invitation metadata if available
                    if ($invitation && $invitation->metadata) {
                        if (isset($invitation->metadata['role'])) {
                            $userData['roles_id'] = $invitation->metadata['role'];
                        }
                    }

                    try {
                        DB::transaction(function () use ($invitation, $userData): void {
                            $user = $this->create($userData);

                            if ($invitation && ! $invitation->markAsUsed($user->id)) {
                                throw new \RuntimeException('Failed to mark invitation as used.');
                            }
                        });
                    } catch (Throwable $exception) {
                        return $this->redirectBackWithRegistrationError(
                            $request,
                            'We could not complete your registration right now. Please try again in a few minutes. If the problem continues, contact support.',
                            'unexpected_exception',
                            'error',
                            [
                                'error' => $exception->getMessage(),
                                'exception' => $exception::class,
                            ]
                        );
                    }

                    return redirect()
                        ->route('login')
                        ->with('info', 'Your account has been created. You will receive an account confirmation email shortly. Please verify your email address before logging in.');
                }

                return $this->rejectUnavailableRegistration($request, $status, $inviteCode);

            case 'view':
                // Don't set showRegister here - let showRegistrationForm handle it
                // Only validate invite code if present
                if ($inviteCode !== '' && ! $this->isInvitationTokenValid($inviteCode)) {
                    $error = 'Invalid invitation token!';
                }
                break;
        }

        return $this->showRegistrationForm($request, $error, $showRegister);
    }

    public function showRegistrationForm(Request $request, string $error = '', int $showRegister = 0): mixed
    {
        $inviteCode = $this->extractInviteCode($request);

        $status = $this->registrationStatusService->resolve();
        $invitation = (! empty($inviteCode) && ($status['is_open'] || $status['is_invite_only']))
            ? Invitation::findValidByToken($inviteCode)
            : null;
        $invitationTokenValid = $invitation !== null;
        $invitationEmail = ($invitation !== null && ! empty($invitation->email)) ? (string) $invitation->email : null;

        $state = self::evaluateRegistrationFormState(
            (bool) $status['is_open'],
            (bool) $status['is_invite_only'],
            (bool) $status['scheduled_override_active'],
            (string) $status['message'],
            $inviteCode,
            $invitationTokenValid,
            $invitationEmail,
            $error,
            $showRegister
        );

        return view('auth.register')->with([
            'showregister' => $state['showregister'],
            'error' => $state['error'],
            'notice' => $state['notice'],
            'email' => $state['email'],
            'invite_code_query' => $this->inviteCodeQuery,
            'invitecode' => $inviteCode,
            'registrationStatus' => $status,
        ]);
    }

    /**
     * Check if invitation is valid for given email
     */
    private function isInvitationValid(string $token, string $email): bool
    {
        if (empty($token)) {
            return false;
        }

        $invitation = Invitation::findValidByToken($token);

        if (! $invitation) {
            return false;
        }

        // If invitation has specific email, validate it matches
        if (! empty($invitation->email) && ! $this->emailsMatch($invitation->email, $email)) {
            return false;
        }

        return true;
    }

    /**
     * Check if invitation token is valid (without email check)
     */
    private function isInvitationTokenValid(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $invitation = Invitation::findValidByToken($token);

        return $invitation !== null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function redirectBackWithRegistrationError(
        Request $request,
        string $message,
        string $reason = 'registration_failed',
        string $level = 'warning',
        array $context = []
    ): RedirectResponse {
        $this->logRegistrationFailure($request, $reason, $message, $level, $context);

        return redirect()
            ->back()
            ->withErrors(['registration' => $message])
            ->withInput($request->except('password', 'password_confirmation'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logRegistrationFailure(
        Request $request,
        string $reason,
        string $message,
        string $level = 'warning',
        array $context = []
    ): void {
        $status = $this->registrationStatusService->resolve();

        $logContext = array_merge([
            'reason' => $reason,
            'message' => $message,
            'username' => $request->input('username'),
            'email' => $request->input('email'),
            'ip' => $request->ip(),
            'registration_status' => $status['effective_status'],
            'manual_registration_status' => $status['manual_status'],
            'registration_status_reason' => $status['reason'],
            'has_invite_code' => $request->filled('invitecode') || $request->filled('token') || $request->filled('invitation'),
        ], $context);

        Log::channel('registration')->{$level}("Registration attempt failed: {$reason}", $logContext);
    }

    /**
     * @return RedirectResponse|Redirector
     */
    private function rejectUnavailableRegistration(Request $request, array $status, string $inviteCode)
    {
        if ($status['is_closed']) {
            return $this->redirectBackWithRegistrationError(
                $request,
                $status['message'],
                'registrations_closed'
            );
        }

        if ($status['is_invite_only']) {
            if (! empty($inviteCode) && ! $this->isInvitationTokenValid($inviteCode)) {
                return $this->redirectBackWithRegistrationError(
                    $request,
                    'Invalid or expired invitation token!',
                    'invalid_or_expired_invitation'
                );
            }

            return $this->redirectBackWithRegistrationError(
                $request,
                $status['message'],
                'registrations_invite_only'
            );
        }

        return $this->redirectBackWithRegistrationError(
            $request,
            'Registrations are not currently available.',
            'registrations_unavailable'
        );
    }

    private function extractInviteCode(Request $request): string
    {
        $resolved = self::resolveInviteCode(
            $request->filled('invitecode') ? (string) $request->input('invitecode') : null,
            $request->filled('token') ? (string) $request->input('token') : null,
            $request->filled('invitation') ? (string) $request->input('invitation') : null,
        );
        $this->inviteCodeQuery = $resolved['invite_code_query'];

        return $resolved['invite_code'];
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
