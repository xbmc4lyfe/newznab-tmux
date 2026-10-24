<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Disable2faPasswordSecurityRequest;
use App\Models\PasswordSecurity;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException;
use PragmaRX\Google2FA\Exceptions\InvalidCharactersException;
use PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException;
use PragmaRX\Google2FALaravel\Facade as Google2FA;
use Throwable;

class PasswordSecurityController extends Controller
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_password_security_profile_referer(
        uint8_t from_profile,
        uint8_t has_referer,
        const char *referer,
        size_t referer_len
    );
    int32_t nntmux_password_security_trusted_cookie(
        int64_t user_id,
        const char *token,
        size_t token_len,
        int64_t expires_at,
        char *out,
        size_t cap
    );
    int32_t nntmux_password_security_can_cancel(uint8_t exists, uint8_t enabled);
    CDEF;

    private const int JSON_INVALID_UTF8 = -2147483647;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustProfileSecurityPrivacyInviteControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function show2faForm(Request $request): Application|View|Factory|\Illuminate\Contracts\Foundation\Application|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->redirectToLoginWithError('Please log in to access 2FA settings.');
        }

        $google2fa_url = '';
        if ($user->passwordSecurity()->exists()) {
            $google2fa_url = Google2FA::getQRCodeInline(
                config('app.name'),
                $user->email,
                $user->passwordSecurity->google2fa_secret
            );
        }
        $data = [
            'user' => $user,
            'google2fa_url' => $google2fa_url,
        ];

        return view('auth.2fa')->with('data', $data);
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws InvalidCharactersException
     * @throws SecretKeyTooShortException
     */
    public function generate2faSecret(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->redirectToLoginWithError('Please log in to access 2FA settings.');
        }

        // Add the secret key to the registration data
        PasswordSecurity::create(
            [
                'user_id' => $user->id,
                'google2fa_enable' => 0,
                'google2fa_secret' => Google2FA::generateSecretKey(),
            ]
        );

        // Check if request is from profile page
        if (self::isProfileReferer($request->has('from_profile'), $request->headers->get('referer'))) {
            return redirect()->to('profileedit#security')->with('success_2fa', 'Secret Key is generated, Please scan the QR code and verify to Enable 2FA');
        }

        return redirect()->to('2fa')->with('success', 'Secret Key is generated, Please verify Code to Enable 2FA');
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws InvalidCharactersException
     * @throws SecretKeyTooShortException
     */
    public function enable2fa(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->redirectToLoginWithError('Please log in to access 2FA settings.');
        }

        $secret = $request->input('verify-code');
        $valid = Google2FA::verifyKey($user->passwordSecurity->google2fa_secret, $secret);
        if ($valid) {
            $user->passwordSecurity->google2fa_enable = 1;
            $user->passwordSecurity->save();

            // Always redirect to profile page after enabling 2FA
            return redirect()->to('profileedit#security')->with('success_2fa', '2FA is Enabled Successfully.');
        }

        // Always redirect to profile page on failure as well
        return redirect()->to('profileedit#security')->with('error_2fa', 'Invalid Verification Code, Please try again.');
    }

    public function cancelSetup(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->redirectToLoginWithError('Please log in to access 2FA settings.');
        }

        // Only allow canceling if 2FA is not yet enabled
        $exists = $user->passwordSecurity()->exists();
        if (self::canCancelSetup($exists, $exists && (bool) $user->passwordSecurity->google2fa_enable)) {
            $user->passwordSecurity()->delete();

            return redirect()->to('profileedit#security')->with('success_2fa', '2FA setup has been cancelled.');
        }

        return redirect()->to('profileedit#security')->with('error_2fa', 'Unable to cancel 2FA setup.');
    }

    public function disable2fa(Disable2faPasswordSecurityRequest $request): Redirector|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        $user = $request->user();

        if (! $user) {
            return $this->redirectToLoginWithError('Please log in to access 2FA settings.');
        }

        if (! (Hash::check($request->get('current-password'), $user->password))) {
            // Password doesn't match - always redirect to profile page with error
            return redirect()->to('profileedit#security')->with('error_2fa', 'Your password does not match with your account password. Please try again.');
        }

        $validatedData = $request->validated();

        // Delete the password security record entirely to fully disable 2FA
        if ($user->passwordSecurity) {
            $user->passwordSecurity->delete();
        }

        // Always redirect to profile page after disabling 2FA
        return redirect()->to('profileedit#security')->with('success_2fa', '2FA is now Disabled.');
    }

    /**
     * Verify the 2FA code provided by the user.
     */
    public function verify2fa(Request $request): RedirectResponse
    {
        $request->validate([
            'one_time_password' => 'required|numeric',
            'trust_device' => 'nullable|boolean',
        ]);

        $authenticatedUser = $request->user();
        $user = $authenticatedUser ?? User::find($request->session()->get('2fa:user:id'));

        if (! $user || ! $user->passwordSecurity?->google2fa_enable) {
            $request->session()->forget('2fa:user:id');

            return $this->redirectToLoginWithError('User not found or 2FA not configured. Please login again.');
        }

        // Verify the OTP code
        $valid = Google2FA::verifyKey(
            $user->passwordSecurity->google2fa_secret,
            $request->input('one_time_password')
        );

        if (! $valid) {
            return redirect()->route('2fa.verify')
                ->with('error', 'Invalid authentication code. Please try again.');
        }

        $passwordBreached = false;
        if ($authenticatedUser === null) {
            $rememberMe = (bool) $request->session()->get('2fa:remember', false);
            Auth::login($user, $rememberMe);

            $passwordBreached = (bool) $request->session()->get('2fa:password_breached', false);
            $newSessionToken = Str::random(60);
            $user->forceFill(['session_token' => $newSessionToken])->save();
            $request->session()->put('session_token_web', $newSessionToken);
            event(new OtherDeviceLogout(Auth::getDefaultDriver(), $user));
        }

        $request->session()->regenerate();
        $request->session()->forget('2fa:trusted_device');
        Google2FA::boot($request)->login();

        $request->session()->forget(['2fa:user:id', '2fa:remember', '2fa:password_breached']);

        // Determine where to redirect after successful verification
        $redirectUrl = $request->session()->pull('url.intended', '/');

        // Create the redirect response
        $redirect = redirect()->to($redirectUrl)
            ->with('success', 'Two-factor authentication verified successfully.');

        if ($passwordBreached) {
            $redirect = $redirect->with('warning', 'Security Alert: Your password has been found in a data breach. We strongly recommend changing it immediately in your account settings.');
        }

        // If the user has checked "trust this device", create a trust token
        if ($request->has('trust_device') && $request->input('trust_device') == 1) {
            $trustedDevice = TrustedDevice::issueForUser($user, $request->ip(), $request->userAgent());
            $expiresAt = $trustedDevice['device']->expires_at->getTimestamp();

            $cookieValue = self::trustedDeviceCookiePayload($user->id, $trustedDevice['plain'], $expiresAt);
            if ($cookieValue !== null) {
                $redirect->withCookie(cookie(
                    '2fa_trusted_device',
                    $cookieValue,
                    60 * 24 * 30,
                    '/',
                    config('session.domain'),
                    config('session.secure'),
                    true,
                    false,
                    config('session.same_site', 'lax')
                ));
            }
        }

        return $redirect;
    }

    /**
     * Display the 2FA verification form for a user who has already authenticated with username/password
     * but needs to enter their 2FA code.
     */
    public function getVerify2fa(Request $request): mixed
    {
        $user = $request->user() ?? User::find($request->session()->get('2fa:user:id'));
        if (! $user || ! $user->passwordSecurity?->google2fa_enable) {
            $request->session()->forget('2fa:user:id');

            return $this->redirectToLoginWithError('User not found. Please login again.');
        }

        return view('auth.2fa_verify', compact('user'));
    }

    /**
     * Handle disabling 2FA directly from profile page to avoid form conflicts.
     * This route is specifically for the profile page 2FA section.
     */
    public function profileDisable2fa(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->redirectToLoginWithError('Please log in to access 2FA settings.');
        }

        $request->validate([
            'current-password' => 'required',
        ]);

        if (! (Hash::check($request->get('current-password'), $user->password))) {
            return redirect()->to('profileedit#security')->with('error_2fa', 'Your password does not match with your account password. Please try again.');
        }

        if ($user->passwordSecurity) {
            $user->passwordSecurity->google2fa_enable = 0;
            $user->passwordSecurity->save();
        }

        return redirect()->to('profileedit#security')->with('success_2fa', '2FA is now Disabled.');
    }

    /**
     * Show the 2FA enable form on a dedicated page
     */
    public function showEnable2faForm(Request $request): Application|View|Factory|\Illuminate\Contracts\Foundation\Application|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->redirectToLoginWithError('Please log in to access 2FA settings.');
        }

        $google2fa_url = '';
        if ($user->passwordSecurity()->exists()) {
            $google2fa_url = Google2FA::getQRCodeInline(
                config('app.name'),
                $user->email,
                $user->passwordSecurity->google2fa_secret
            );
        }

        $data = [
            'user' => $user,
            'google2fa_url' => $google2fa_url,
        ];

        return view('auth.2fa')->with('data', $data);
    }

    /**
     * Show the 2FA disable form on a dedicated page
     */
    public function showDisable2faForm(Request $request): Application|View|Factory|\Illuminate\Contracts\Foundation\Application|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->redirectToLoginWithError('Please log in to access 2FA settings.');
        }

        $google2fa_url = '';
        if ($user->passwordSecurity()->exists()) {
            $google2fa_url = Google2FA::getQRCodeInline(
                config('app.name'),
                $user->email,
                $user->passwordSecurity->google2fa_secret
            );
        }

        $data = [
            'user' => $user,
            'google2fa_url' => $google2fa_url,
        ];

        return view('auth.2fa')->with('data', $data);
    }

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
     * The 2FA secret request came from the profile page: `from_profile` is present or the referer contains `profileedit`.
     */
    public static function isProfileReferer(bool $fromProfile, ?string $referer): bool
    {
        return self::$useRustFfi
            ? self::isProfileRefererNative($fromProfile, $referer)
            : self::isProfileRefererPhp($fromProfile, $referer);
    }

    public static function isProfileRefererPhp(bool $fromProfile, ?string $referer): bool
    {
        return $fromProfile || ($referer !== null && str_contains($referer, 'profileedit'));
    }

    public static function isProfileRefererNative(bool $fromProfile, ?string $referer): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::isProfileRefererPhp($fromProfile, $referer);
        }

        try {
            $value = $referer ?? '';
            $result = self::callFfi($ffi, 'nntmux_password_security_profile_referer', $fromProfile ? 1 : 0, $referer === null ? 0 : 1, $value, \strlen($value));

            return match ($result) {
                0 => false,
                1 => true,
                default => self::isProfileRefererPhp($fromProfile, $referer),
            };
        } catch (Throwable) {
            return self::isProfileRefererPhp($fromProfile, $referer);
        }
    }

    /**
     * Trusted-device cookie JSON, or null when `json_encode()` fails (invalid UTF-8 token).
     */
    public static function trustedDeviceCookiePayload(int $userId, string $token, int $expiresAt): ?string
    {
        return self::$useRustFfi
            ? self::trustedDeviceCookiePayloadNative($userId, $token, $expiresAt)
            : self::trustedDeviceCookiePayloadPhp($userId, $token, $expiresAt);
    }

    public static function trustedDeviceCookiePayloadPhp(int $userId, string $token, int $expiresAt): ?string
    {
        $payload = json_encode([
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES);

        return $payload === false ? null : $payload;
    }

    public static function trustedDeviceCookiePayloadNative(int $userId, string $token, int $expiresAt): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::trustedDeviceCookiePayloadPhp($userId, $token, $expiresAt);
        }

        try {
            $capacity = 0;
            // Escaping expands a byte to at most six; the fixed keys and two integers fit in 96.
            $out = RustPipelineLibrary::obtainScratch($ffi, 6 * \strlen($token) + 96, $capacity);
            if ($out === null) {
                return self::trustedDeviceCookiePayloadPhp($userId, $token, $expiresAt);
            }
            $written = self::callFfi($ffi, 'nntmux_password_security_trusted_cookie', $userId, $token, \strlen($token), $expiresAt, $out, $capacity);
            if ($written === self::JSON_INVALID_UTF8) {
                return null;
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::trustedDeviceCookiePayloadPhp($userId, $token, $expiresAt);
            }

            return FFI::string($out, $written);
        } catch (Throwable) {
            return self::trustedDeviceCookiePayloadPhp($userId, $token, $expiresAt);
        }
    }

    /**
     * A 2FA setup can be cancelled only while its record exists and is not yet enabled.
     */
    public static function canCancelSetup(bool $exists, bool $enabled): bool
    {
        return self::$useRustFfi
            ? self::canCancelSetupNative($exists, $enabled)
            : self::canCancelSetupPhp($exists, $enabled);
    }

    public static function canCancelSetupPhp(bool $exists, bool $enabled): bool
    {
        return $exists && ! $enabled;
    }

    public static function canCancelSetupNative(bool $exists, bool $enabled): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::canCancelSetupPhp($exists, $enabled);
        }

        try {
            $result = self::callFfi($ffi, 'nntmux_password_security_can_cancel', $exists ? 1 : 0, $enabled ? 1 : 0);

            return match ($result) {
                0 => false,
                1 => true,
                default => self::canCancelSetupPhp($exists, $enabled),
            };
        } catch (Throwable) {
            return self::canCancelSetupPhp($exists, $enabled);
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

    private function redirectToLoginWithError(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
