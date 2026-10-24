<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Events\UserLoggedIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginLoginRequest;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\PasswordBreachService;
use App\Support\Auth\AuthenticatesUsers;
use App\Support\Google2FAAuthenticator;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_login_detect_field_type_nul(
    uint8_t is_string_input,
    const char *input_ptr,
    size_t input_len,
    int32_t *out_is_email,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_login_trusted_device_remaining(
    int64_t expires_at,
    int64_t now_ts,
    int64_t *out_scalars
);
int32_t nntmux_login_requires_two_factor(
    uint8_t has_password_security,
    uint8_t google2fa_enable_true,
    uint8_t secret_is_string,
    const char *secret_ptr,
    size_t secret_len,
    int32_t *out_requires
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected int $maxAttempts = 3; // Default is 5

    protected int $decayMinutes = 2; // Default is 1

    /**
     * Where to redirect users after login.
     */
    protected string $redirectTo = '/';

    private const string GENERIC_LOGIN_FAILURE = 'Username or email and password combination used does not match our records!';

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

    public static function detectLoginFieldType(mixed $usernameInput): string
    {
        if (self::$useRustFfi) {
            return self::detectLoginFieldTypeNative($usernameInput);
        }

        return self::detectLoginFieldTypePhp($usernameInput);
    }

    public static function detectLoginFieldTypePhp(mixed $usernameInput): string
    {
        return filter_var($usernameInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    }

    public static function detectLoginFieldTypeNative(mixed $usernameInput): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::detectLoginFieldTypePhp($usernameInput);
        }

        try {
            $isString = is_string($usernameInput);
            $inputStr = $isString ? $usernameInput : '';
            $cap = 16;
            $outIsEmail = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_login_detect_field_type_nul(
                $isString ? 1 : 0,
                $inputStr,
                strlen($inputStr),
                $outIsEmail,
                $outBuf,
                $cap
            );
            $isEmailFlag = $outIsEmail[0];
            if (! is_int($written) || $written <= 0 || ! is_int($isEmailFlag)) {
                return self::detectLoginFieldTypePhp($usernameInput);
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::detectLoginFieldTypePhp($usernameInput);
        }
    }

    /**
     * @return array{remaining_seconds: int, remaining_minutes: int, should_preserve: bool}
     */
    public static function calculateTrustedDeviceCookieRemaining(int $expiresAt, int $now): array
    {
        if (self::$useRustFfi) {
            return self::calculateTrustedDeviceCookieRemainingNative($expiresAt, $now);
        }

        return self::calculateTrustedDeviceCookieRemainingPhp($expiresAt, $now);
    }

    /**
     * @return array{remaining_seconds: int, remaining_minutes: int, should_preserve: bool}
     */
    public static function calculateTrustedDeviceCookieRemainingPhp(int $expiresAt, int $now): array
    {
        $remainingSeconds = max(0, $expiresAt - $now);
        $remainingMinutes = (int) ceil($remainingSeconds / 60);

        return [
            'remaining_seconds' => $remainingSeconds,
            'remaining_minutes' => $remainingMinutes,
            'should_preserve' => $remainingMinutes > 0,
        ];
    }

    /**
     * @return array{remaining_seconds: int, remaining_minutes: int, should_preserve: bool}
     */
    public static function calculateTrustedDeviceCookieRemainingNative(int $expiresAt, int $now): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::calculateTrustedDeviceCookieRemainingPhp($expiresAt, $now);
        }

        try {
            $outScalars = $ffi->new('int64_t[3]');

            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_login_trusted_device_remaining($expiresAt, $now, $outScalars);
            $remSec = $outScalars[0];
            $remMin = $outScalars[1];
            $preserve = $outScalars[2];
            if ($rc !== 1 || ! is_int($remSec) || ! is_int($remMin) || ! is_int($preserve)) {
                return self::calculateTrustedDeviceCookieRemainingPhp($expiresAt, $now);
            }

            return [
                'remaining_seconds' => $remSec,
                'remaining_minutes' => $remMin,
                'should_preserve' => $preserve === 1,
            ];
        } catch (Throwable) {
            return self::calculateTrustedDeviceCookieRemainingPhp($expiresAt, $now);
        }
    }

    public static function requiresTwoFactorAuthentication(
        bool $hasPasswordSecurity,
        mixed $google2faEnable,
        mixed $google2faSecret
    ): bool {
        if (self::$useRustFfi) {
            return self::requiresTwoFactorAuthenticationNative(
                $hasPasswordSecurity,
                $google2faEnable,
                $google2faSecret
            );
        }

        return self::requiresTwoFactorAuthenticationPhp(
            $hasPasswordSecurity,
            $google2faEnable,
            $google2faSecret
        );
    }

    public static function requiresTwoFactorAuthenticationPhp(
        bool $hasPasswordSecurity,
        mixed $google2faEnable,
        mixed $google2faSecret
    ): bool {
        return $hasPasswordSecurity
            && $google2faEnable === true
            && is_string($google2faSecret)
            && $google2faSecret !== '';
    }

    public static function requiresTwoFactorAuthenticationNative(
        bool $hasPasswordSecurity,
        mixed $google2faEnable,
        mixed $google2faSecret
    ): bool {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::requiresTwoFactorAuthenticationPhp(
                $hasPasswordSecurity,
                $google2faEnable,
                $google2faSecret
            );
        }

        try {
            $secretIsString = is_string($google2faSecret);
            $secretStr = $secretIsString ? $google2faSecret : '';
            $outRequires = $ffi->new('int32_t[1]');

            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_login_requires_two_factor(
                $hasPasswordSecurity ? 1 : 0,
                $google2faEnable === true ? 1 : 0,
                $secretIsString ? 1 : 0,
                $secretStr,
                strlen($secretStr),
                $outRequires
            );
            $req = $outRequires[0];
            if ($rc !== 1 || ! is_int($req)) {
                return self::requiresTwoFactorAuthenticationPhp(
                    $hasPasswordSecurity,
                    $google2faEnable,
                    $google2faSecret
                );
            }

            return $req === 1;
        } catch (Throwable) {
            return self::requiresTwoFactorAuthenticationPhp(
                $hasPasswordSecurity,
                $google2faEnable,
                $google2faSecret
            );
        }
    }

    /**
     * Get the login username to be used (form field name; value may be email or username).
     */
    public function username(): string
    {
        return 'username';
    }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    /**
     * @throws AuthenticationException
     */
    public function login(LoginLoginRequest $request): \Illuminate\Foundation\Application|Redirector|Application|RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => ['required'],
            'password' => ['required'],
        ]);

        $login_type = self::detectLoginFieldType($request->input('username'));

        $request->merge([
            $login_type => $request->input('username'),
        ]);

        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);
            $request->session()->flash('warning', 'You have failed to log in too many times. Try again in '.$this->decayMinutes().' minutes.');

            return redirect()->to('login');
        }

        if ($validator->passes()) {
            $rememberMe = $request->boolean('rememberme') || $request->boolean('remember');

            if (! Auth::attempt($request->only($login_type, 'password'), $rememberMe)) {
                $this->incrementLoginAttempts($request);
                Log::channel('failed_login')->error('Failed login attempt by user: '.$request->input('username').' from IP address: '.$request->ip());
                $request->session()->flash('error', self::GENERIC_LOGIN_FAILURE);

                return redirect()->to('login');
            }

            /** @var User $user */
            $user = Auth::user();

            if (! $user->isEligibleForAuthentication()) {
                Auth::logout();
                $this->incrementLoginAttempts($request);
                Log::channel('failed_login')->error('Failed login attempt by user: '.$request->input('username').' from IP address: '.$request->ip());
                $request->session()->flash('error', self::GENERIC_LOGIN_FAILURE);

                return redirect()->to('login');
            }

            $request->session()->regenerate();

            $userIp = config('nntmux_settings.store_user_ip') ? ($request->ip() ?? $request->getClientIp()) : '';
            event(new UserLoggedIn($user, $userIp));

            $passwordBreached = $this->isPasswordBreached((string) $request->input('password'));

            if ($this->userRequiresTwoFactor($user)) {
                if ($this->trustedDeviceCookieIsValid($request, $user)) {
                    $authenticator = app(Google2FAAuthenticator::class);
                    $authenticator->boot($request);
                    $authenticator->loginFromTrustedDevice();

                    Auth::logoutOtherDevices((string) $request->input('password'));
                    $this->rotateSessionTokenForCurrentSession($request, $user);
                    $this->clearLoginAttempts($request);

                    return $this->appendPasswordBreachWarning(
                        redirect()->intended($this->redirectPath())->with('info', 'You have been logged in'),
                        $passwordBreached
                    );
                }

                $this->prepareTwoFactorChallenge($request, $user, $rememberMe, $passwordBreached);

                return redirect()->route('2fa.verify');
            }

            Auth::logoutOtherDevices((string) $request->input('password'));
            $this->rotateSessionTokenForCurrentSession($request, $user);
            $this->clearLoginAttempts($request);

            return $this->appendPasswordBreachWarning(
                redirect()->intended($this->redirectPath())->with('info', 'You have been logged in'),
                $passwordBreached
            );

        }

        $this->incrementLoginAttempts($request);
        $request->session()->flash('error', implode('', Arr::collapse($validator->errors()->toArray())));
        Log::channel('failed_login')->error('Failed login attempt by user: '.$request->input('username').' from IP address: '.$request->ip());

        return redirect()->to('login');
    }

    public function showLoginForm(): mixed
    {
        return view('auth.login');
    }

    public function logout(Request $request): Redirector|RedirectResponse
    {
        // Save 2FA trusted device cookie value before logout
        $trustedDeviceCookie = $request->cookie('2fa_trusted_device');

        // Perform standard logout
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerate();

        if ($trustedDeviceCookie) {
            try {
                $cookieData = json_decode($trustedDeviceCookie, true);

                if (isset($cookieData['expires_at'])) {
                    // Calculate remaining minutes until expiration
                    $now = time();
                    $remaining = self::calculateTrustedDeviceCookieRemaining((int) $cookieData['expires_at'], $now);
                    $remainingSeconds = $remaining['remaining_seconds'];
                    $remainingMinutes = $remaining['remaining_minutes'];

                    Log::info('Logout - Cookie Expiration', [
                        'expires_at' => $cookieData['expires_at'],
                        'current_time' => $now,
                        'remaining_seconds' => $remainingSeconds,
                        'remaining_minutes' => $remainingMinutes,
                    ]);

                    // Only preserve the cookie if it hasn't expired yet
                    if ($remaining['should_preserve']) {
                        $cookie = cookie(
                            '2fa_trusted_device',
                            $trustedDeviceCookie,
                            $remainingMinutes,
                            '/',
                            config('session.domain'), // use session domain config
                            config('session.secure'), // use session secure config
                            true,
                            false,                   // raw
                            config('session.same_site', 'lax') // use session same_site config
                        );

                        // Queue the cookie to be sent with the response
                        cookie()->queue($cookie);
                    } else {
                        Log::warning('Logout - Cookie Already Expired');
                    }
                } else {
                    Log::warning('Logout - Cookie Missing Expiration Data');
                }
            } catch (\Exception $e) {
                Log::error('Logout - Error Processing Cookie', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->to('login')->with('success', 'You have been logged out successfully');
    }

    /**
     * Check if the password has been compromised in a data breach and add a warning if so.
     */
    protected function checkPasswordBreachAndRedirect(string $password, RedirectResponse $redirect): RedirectResponse
    {
        return $this->appendPasswordBreachWarning($redirect, $this->isPasswordBreached($password));
    }

    protected function isPasswordBreached(string $password): bool
    {
        try {
            $breachService = app(PasswordBreachService::class);

            if ($breachService->isPasswordBreached($password)) {
                return true;
            }
        } catch (\Exception $e) {
            Log::error('Password breach check failed during login', [
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    protected function appendPasswordBreachWarning(RedirectResponse $redirect, bool $passwordBreached): RedirectResponse
    {
        if ($passwordBreached) {
            return $redirect->with('warning', 'Security Alert: Your password has been found in a data breach. We strongly recommend changing it immediately in your account settings.');
        }

        return $redirect;
    }

    private function userRequiresTwoFactor(User $user): bool
    {
        $user->unsetRelation('passwordSecurity');
        $passwordSecurity = $user->passwordSecurity()->first();

        return self::requiresTwoFactorAuthentication(
            $passwordSecurity !== null,
            $passwordSecurity?->google2fa_enable,
            $passwordSecurity?->google2fa_secret
        );
    }

    private function prepareTwoFactorChallenge(Request $request, User $user, bool $rememberMe, bool $passwordBreached): void
    {
        $sessionVar = config('google2fa.session_var');
        if (is_string($sessionVar) && $sessionVar !== '') {
            $request->session()->forget($sessionVar);
        }

        $request->session()->put('url.intended', $this->redirectPath());
        $request->session()->put('2fa:remember', $rememberMe);
        $request->session()->put('2fa:password_breached', $passwordBreached);

        Auth::logout();
        $request->session()->put('2fa:user:id', $user->id);
    }

    private function trustedDeviceCookieIsValid(Request $request, User $user): bool
    {
        $trustedCookie = $request->cookie('2fa_trusted_device');
        if (! is_string($trustedCookie) || $trustedCookie === '') {
            return false;
        }

        try {
            return TrustedDevice::cookieIsValidForUser($trustedCookie, (int) $user->id);
        } catch (\Exception $e) {
            Log::error('Login - Error processing trusted device cookie', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function rotateSessionTokenForCurrentSession(Request $request, User $user): void
    {
        $request->session()->put(
            'password_hash_'.Auth::getDefaultDriver(),
            Auth::hashPasswordForCookie($user->getAuthPassword())
        );

        $newSessionToken = Str::random(60);

        $user->forceFill([
            'session_token' => $newSessionToken,
        ])->save();

        $request->session()->put('session_token_web', $newSessionToken);
        event(new OtherDeviceLogout(Auth::getDefaultDriver(), $user));
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
