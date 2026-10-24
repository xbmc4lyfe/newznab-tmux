<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\RedirectsUsers;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class ResetPasswordController extends Controller
{
    use RedirectsUsers;

    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_reset_password_evaluate_legacy_guid_nul(
    uint8_t has_guid_param,
    uint8_t user_found,
    uint8_t user_trashed,
    int32_t *out_is_valid,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Where to redirect users after resetting their password.
     */
    protected string $redirectTo = '/';

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

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
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
     * @return array{
     *     is_valid: bool,
     *     status: string,
     *     redirect_route: string,
     *     error_message: string|null
     * }
     */
    public static function evaluateLegacyResetGuidState(
        bool $hasGuidParam,
        bool $userFound,
        bool $userTrashed
    ): array {
        if (self::$useRustFfi) {
            return self::evaluateLegacyResetGuidStateNative($hasGuidParam, $userFound, $userTrashed);
        }

        return self::evaluateLegacyResetGuidStatePhp($hasGuidParam, $userFound, $userTrashed);
    }

    /**
     * @return array{
     *     is_valid: bool,
     *     status: string,
     *     redirect_route: string,
     *     error_message: string|null
     * }
     */
    public static function evaluateLegacyResetGuidStatePhp(
        bool $hasGuidParam,
        bool $userFound,
        bool $userTrashed
    ): array {
        if (! $hasGuidParam) {
            return [
                'is_valid' => false,
                'status' => 'missing_guid',
                'redirect_route' => 'password.request',
                'error_message' => 'No reset code provided.',
            ];
        }

        if (! $userFound) {
            return [
                'is_valid' => false,
                'status' => 'bad_guid',
                'redirect_route' => 'password.request',
                'error_message' => 'Bad reset code provided.',
            ];
        }

        if ($userTrashed) {
            return [
                'is_valid' => false,
                'status' => 'deactivated',
                'redirect_route' => 'password.request',
                'error_message' => 'This account has been deactivated.',
            ];
        }

        return [
            'is_valid' => true,
            'status' => 'issue_token',
            'redirect_route' => 'password.reset',
            'error_message' => null,
        ];
    }

    /**
     * @return array{
     *     is_valid: bool,
     *     status: string,
     *     redirect_route: string,
     *     error_message: string|null
     * }
     */
    public static function evaluateLegacyResetGuidStateNative(
        bool $hasGuidParam,
        bool $userFound,
        bool $userTrashed
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::evaluateLegacyResetGuidStatePhp($hasGuidParam, $userFound, $userTrashed);
        }

        try {
            $cap = 128;
            $outIsValid = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_reset_password_evaluate_legacy_guid_nul(
                $hasGuidParam ? 1 : 0,
                $userFound ? 1 : 0,
                $userTrashed ? 1 : 0,
                $outIsValid,
                $outBuf,
                $cap
            );
            $isValidInt = $outIsValid[0];
            if (! is_int($written) || $written <= 0 || ! is_int($isValidInt)) {
                return self::evaluateLegacyResetGuidStatePhp($hasGuidParam, $userFound, $userTrashed);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 3) {
                return self::evaluateLegacyResetGuidStatePhp($hasGuidParam, $userFound, $userTrashed);
            }

            $isValid = $isValidInt === 1;

            return [
                'is_valid' => $isValid,
                'status' => $parts[0],
                'redirect_route' => $parts[1],
                'error_message' => $isValid ? null : $parts[2],
            ];
        } catch (Throwable) {
            return self::evaluateLegacyResetGuidStatePhp($hasGuidParam, $userFound, $userTrashed);
        }
    }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    public function showLegacyResetForm(Request $request): mixed
    {
        if ($request->missing('guid')) {
            $state = self::evaluateLegacyResetGuidState(false, false, false);

            return redirect()->route($state['redirect_route'])->with('error', $state['error_message']);
        }

        $user = User::findByResetGuid((string) $request->input('guid'));
        if ($user === null) {
            $state = self::evaluateLegacyResetGuidState(true, false, false);

            return redirect()->route($state['redirect_route'])->with('error', $state['error_message']);
        }

        // Check if user is soft deleted
        if ($user->trashed()) {
            $state = self::evaluateLegacyResetGuidState(true, true, true);

            return redirect()->route($state['redirect_route'])->with('error', $state['error_message']);
        }

        $state = self::evaluateLegacyResetGuidState(true, true, false);
        $token = Password::createToken($user);
        User::updatePassResetGuid($user->id, null);

        return redirect()->route($state['redirect_route'], [
            'token' => $token,
            'email' => $user->email,
        ]);
    }

    public function reset(Request $request): mixed
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                    'resetguid' => null,
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('success', __($status));
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }

    public function showResetForm(Request $request, mixed $token = null): mixed
    {
        return view('auth.passwords.reset')->with([
            'token' => $token,
            'email' => $request->email,
        ]);
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
