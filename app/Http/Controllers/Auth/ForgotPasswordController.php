<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordForgottenEmail;
use App\Models\User;
use App\Support\CaptchaHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ForgotPasswordController extends Controller
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_forgot_password_evaluate_nul(
    const char *email_ptr,
    size_t email_len,
    uint8_t captcha_enabled,
    uint8_t captcha_failed,
    uint8_t user_exists,
    uint8_t user_trashed,
    int32_t *out_flags,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
     *     status: string,
     *     should_send_reset: bool,
     *     route: string,
     *     error_field: string|null,
     *     error_message: string|null,
     *     success_message: string|null
     * }
     */
    public static function evaluateForgotPasswordRequest(
        string $email,
        bool $captchaEnabled,
        bool $captchaFailed,
        bool $userExists,
        bool $userTrashed
    ): array {
        if (self::$useRustFfi) {
            return self::evaluateForgotPasswordRequestNative(
                $email,
                $captchaEnabled,
                $captchaFailed,
                $userExists,
                $userTrashed
            );
        }

        return self::evaluateForgotPasswordRequestPhp(
            $email,
            $captchaEnabled,
            $captchaFailed,
            $userExists,
            $userTrashed
        );
    }

    /**
     * @return array{
     *     status: string,
     *     should_send_reset: bool,
     *     route: string,
     *     error_field: string|null,
     *     error_message: string|null,
     *     success_message: string|null
     * }
     */
    public static function evaluateForgotPasswordRequestPhp(
        string $email,
        bool $captchaEnabled,
        bool $captchaFailed,
        bool $userExists,
        bool $userTrashed
    ): array {
        if ($email === '') {
            return [
                'status' => 'missing_email',
                'should_send_reset' => false,
                'route' => 'forgottenpassword',
                'error_field' => 'email',
                'error_message' => 'Please enter your email address to send a password reset link.',
                'success_message' => null,
            ];
        }

        if ($captchaEnabled && $captchaFailed) {
            return [
                'status' => 'captcha_failed',
                'should_send_reset' => false,
                'route' => 'forgottenpassword',
                'error_field' => 'error',
                'error_message' => 'Captcha validation failed.',
                'success_message' => null,
            ];
        }

        if (! $userExists || $userTrashed) {
            return [
                'status' => 'enumerated_noop',
                'should_send_reset' => false,
                'route' => 'forgottenpassword',
                'error_field' => null,
                'error_message' => null,
                'success_message' => 'Password reset email has been sent!',
            ];
        }

        return [
            'status' => 'send_reset',
            'should_send_reset' => true,
            'route' => 'forgottenpassword',
            'error_field' => null,
            'error_message' => null,
            'success_message' => 'Password reset email has been sent!',
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     should_send_reset: bool,
     *     route: string,
     *     error_field: string|null,
     *     error_message: string|null,
     *     success_message: string|null
     * }
     */
    public static function evaluateForgotPasswordRequestNative(
        string $email,
        bool $captchaEnabled,
        bool $captchaFailed,
        bool $userExists,
        bool $userTrashed
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::evaluateForgotPasswordRequestPhp(
                $email,
                $captchaEnabled,
                $captchaFailed,
                $userExists,
                $userTrashed
            );
        }

        try {
            $cap = 256;
            $outFlags = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_forgot_password_evaluate_nul(
                $email,
                strlen($email),
                $captchaEnabled ? 1 : 0,
                $captchaFailed ? 1 : 0,
                $userExists ? 1 : 0,
                $userTrashed ? 1 : 0,
                $outFlags,
                $outBuf,
                $cap
            );
            $flags = $outFlags[0];
            if (! is_int($written) || $written <= 0 || ! is_int($flags)) {
                return self::evaluateForgotPasswordRequestPhp(
                    $email,
                    $captchaEnabled,
                    $captchaFailed,
                    $userExists,
                    $userTrashed
                );
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 5) {
                return self::evaluateForgotPasswordRequestPhp(
                    $email,
                    $captchaEnabled,
                    $captchaFailed,
                    $userExists,
                    $userTrashed
                );
            }

            $hasError = ($flags & 2) !== 0;
            $hasSuccess = ($flags & 4) !== 0;

            return [
                'status' => $parts[0],
                'should_send_reset' => ($flags & 1) !== 0,
                'route' => $parts[1],
                'error_field' => $hasError ? $parts[2] : null,
                'error_message' => $hasError ? $parts[3] : null,
                'success_message' => $hasSuccess ? $parts[4] : null,
            ];
        } catch (Throwable) {
            return self::evaluateForgotPasswordRequestPhp(
                $email,
                $captchaEnabled,
                $captchaFailed,
                $userExists,
                $userTrashed
            );
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

    /**
     * @throws \Exception
     */
    public function showLinkRequestForm(Request $request): mixed
    {
        // If it's a GET request, just show the form
        if ($request->isMethod('get')) {
            return view('auth.passwords.email');
        }

        $email = (string) $request->input('email', '');
        $preCheck = self::evaluateForgotPasswordRequest($email, false, false, false, false);

        if ($preCheck['status'] === 'missing_email' && $preCheck['error_field'] !== null && $preCheck['error_message'] !== null) {
            return redirect()
                ->route($preCheck['route'])
                ->withErrors([$preCheck['error_field'] => $preCheck['error_message']])
                ->withInput($request->except(CaptchaHelper::getResponseFieldName()));
        }

        $captchaEnabled = CaptchaHelper::isEnabled();
        if ($captchaEnabled) {
            $validate = Validator::make($request->all(), CaptchaHelper::getValidationRules());
            if ($validate->fails()) {
                $captchaDecision = self::evaluateForgotPasswordRequest($email, true, true, false, false);

                return redirect()
                    ->route($captchaDecision['route'])
                    ->withErrors([(string) $captchaDecision['error_field'] => (string) $captchaDecision['error_message']])
                    ->withInput($request->except(CaptchaHelper::getResponseFieldName()));
            }
        }

        // Check whether the user exists, but always return the same success message
        // to avoid account enumeration.
        $user = User::findByEmail($email);
        $userTrashed = $user !== null && (bool) User::withTrashed()->find($user->id)?->trashed();

        $decision = self::evaluateForgotPasswordRequest(
            $email,
            $captchaEnabled,
            false,
            $user !== null,
            $userTrashed
        );

        if (! $decision['should_send_reset'] || $user === null) {
            return redirect()->route($decision['route'])->with('success', $decision['success_message']);
        }

        $this->sendResetLink($user);

        // Clear any legacy GUID once a broker token has been issued.
        User::updatePassResetGuid($user->id, null);

        return redirect()->route($decision['route'])->with('success', $decision['success_message']);
    }

    private function sendResetLink(User $user): void
    {
        $token = Password::createToken($user);

        SendPasswordForgottenEmail::dispatch(
            $user,
            route('password.reset', [
                'token' => $token,
                'email' => $user->email,
            ]),
        );
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
