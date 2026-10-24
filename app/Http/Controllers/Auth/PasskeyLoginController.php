<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Events\UserLoggedIn;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CaptchaHelper;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PragmaRX\Google2FALaravel\Facade as Google2FA;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Events\PasskeyUsedToAuthenticateEvent;
use Spatie\LaravelPasskeys\Http\Requests\AuthenticateUsingPasskeysRequest;
use Spatie\LaravelPasskeys\Support\Config;
use Throwable;

final class PasskeyLoginController extends Controller
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_passkey_login_preflight_nul(
    uint8_t has_captcha_rules,
    uint8_t has_captcha_val,
    const char *captcha_ptr,
    size_t captcha_len,
    uint8_t has_options,
    size_t options_len,
    int32_t *out_flags,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_passkey_login_resolve_redirect_nul(
    uint8_t store_user_ip,
    uint8_t has_req_ip,
    const char *req_ip_ptr,
    size_t req_ip_len,
    uint8_t has_client_ip,
    const char *client_ip_ptr,
    size_t client_ip_len,
    uint8_t has_sess_redirect,
    const char *sess_redirect_ptr,
    size_t sess_redirect_len,
    uint8_t has_cfg_redirect,
    const char *cfg_redirect_ptr,
    size_t cfg_redirect_len,
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
     *     should_validate_captcha: bool,
     *     has_valid_options_json: bool,
     *     missing_options_message: string,
     *     missing_options_reason: string
     * }
     */
    public static function evaluatePasskeyLoginPreflight(
        bool $hasCaptchaRules,
        ?string $captchaValue,
        ?string $passkeyOptionsJson
    ): array {
        if (self::$useRustFfi) {
            return self::evaluatePasskeyLoginPreflightNative($hasCaptchaRules, $captchaValue, $passkeyOptionsJson);
        }

        return self::evaluatePasskeyLoginPreflightPhp($hasCaptchaRules, $captchaValue, $passkeyOptionsJson);
    }

    /**
     * @return array{
     *     should_validate_captcha: bool,
     *     has_valid_options_json: bool,
     *     missing_options_message: string,
     *     missing_options_reason: string
     * }
     */
    public static function evaluatePasskeyLoginPreflightPhp(
        bool $hasCaptchaRules,
        ?string $captchaValue,
        ?string $passkeyOptionsJson
    ): array {
        return [
            'should_validate_captcha' => $hasCaptchaRules && $captchaValue !== null && trim($captchaValue) !== '',
            'has_valid_options_json' => $passkeyOptionsJson !== null && $passkeyOptionsJson !== '',
            'missing_options_message' => 'Your passkey sign-in session expired or was interrupted. Please try signing in with a passkey again.',
            'missing_options_reason' => 'missing_auth_options',
        ];
    }

    /**
     * @return array{
     *     should_validate_captcha: bool,
     *     has_valid_options_json: bool,
     *     missing_options_message: string,
     *     missing_options_reason: string
     * }
     */
    public static function evaluatePasskeyLoginPreflightNative(
        bool $hasCaptchaRules,
        ?string $captchaValue,
        ?string $passkeyOptionsJson
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::evaluatePasskeyLoginPreflightPhp($hasCaptchaRules, $captchaValue, $passkeyOptionsJson);
        }

        try {
            $captchaStr = $captchaValue ?? '';
            $optionsLen = $passkeyOptionsJson !== null ? strlen($passkeyOptionsJson) : 0;
            $cap = 256;
            $outFlags = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_passkey_login_preflight_nul(
                $hasCaptchaRules ? 1 : 0,
                $captchaValue !== null ? 1 : 0,
                $captchaStr,
                strlen($captchaStr),
                $passkeyOptionsJson !== null ? 1 : 0,
                $optionsLen,
                $outFlags,
                $outBuf,
                $cap
            );
            $flags = $outFlags[0];
            if (! is_int($written) || $written <= 0 || ! is_int($flags)) {
                return self::evaluatePasskeyLoginPreflightPhp($hasCaptchaRules, $captchaValue, $passkeyOptionsJson);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 2) {
                return self::evaluatePasskeyLoginPreflightPhp($hasCaptchaRules, $captchaValue, $passkeyOptionsJson);
            }

            return [
                'should_validate_captcha' => ($flags & 1) !== 0,
                'has_valid_options_json' => ($flags & 2) !== 0,
                'missing_options_message' => $parts[0],
                'missing_options_reason' => $parts[1],
            ];
        } catch (Throwable) {
            return self::evaluatePasskeyLoginPreflightPhp($hasCaptchaRules, $captchaValue, $passkeyOptionsJson);
        }
    }

    /**
     * @return array{
     *     user_ip: string,
     *     redirect_url: string,
     *     flash_key: string,
     *     flash_message: string
     * }
     */
    public static function resolvePasskeyLoginRedirectAndIp(
        bool $storeUserIp,
        ?string $requestIp,
        ?string $clientIp,
        bool $hasSessionRedirect,
        ?string $sessionRedirect,
        ?string $configRedirect
    ): array {
        if (self::$useRustFfi) {
            return self::resolvePasskeyLoginRedirectAndIpNative(
                $storeUserIp,
                $requestIp,
                $clientIp,
                $hasSessionRedirect,
                $sessionRedirect,
                $configRedirect
            );
        }

        return self::resolvePasskeyLoginRedirectAndIpPhp(
            $storeUserIp,
            $requestIp,
            $clientIp,
            $hasSessionRedirect,
            $sessionRedirect,
            $configRedirect
        );
    }

    /**
     * @return array{
     *     user_ip: string,
     *     redirect_url: string,
     *     flash_key: string,
     *     flash_message: string
     * }
     */
    public static function resolvePasskeyLoginRedirectAndIpPhp(
        bool $storeUserIp,
        ?string $requestIp,
        ?string $clientIp,
        bool $hasSessionRedirect,
        ?string $sessionRedirect,
        ?string $configRedirect
    ): array {
        $userIp = $storeUserIp ? ($requestIp ?? ($clientIp ?? '')) : '';
        $redirectUrl = $hasSessionRedirect ? ($sessionRedirect ?? '') : ($configRedirect ?? '/');

        return [
            'user_ip' => $userIp,
            'redirect_url' => $redirectUrl,
            'flash_key' => 'info',
            'flash_message' => 'You have been logged in',
        ];
    }

    /**
     * @return array{
     *     user_ip: string,
     *     redirect_url: string,
     *     flash_key: string,
     *     flash_message: string
     * }
     */
    public static function resolvePasskeyLoginRedirectAndIpNative(
        bool $storeUserIp,
        ?string $requestIp,
        ?string $clientIp,
        bool $hasSessionRedirect,
        ?string $sessionRedirect,
        ?string $configRedirect
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolvePasskeyLoginRedirectAndIpPhp(
                $storeUserIp,
                $requestIp,
                $clientIp,
                $hasSessionRedirect,
                $sessionRedirect,
                $configRedirect
            );
        }

        try {
            $reqIpStr = $requestIp ?? '';
            $clientIpStr = $clientIp ?? '';
            $sessRedirStr = $sessionRedirect ?? '';
            $cfgRedirStr = $configRedirect ?? '';
            $cap = max(
                128,
                strlen($reqIpStr) + strlen($clientIpStr) + strlen($sessRedirStr) + strlen($cfgRedirStr) + 64
            );
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_passkey_login_resolve_redirect_nul(
                $storeUserIp ? 1 : 0,
                $requestIp !== null ? 1 : 0,
                $reqIpStr,
                strlen($reqIpStr),
                $clientIp !== null ? 1 : 0,
                $clientIpStr,
                strlen($clientIpStr),
                $hasSessionRedirect ? 1 : 0,
                $sessRedirStr,
                strlen($sessRedirStr),
                $configRedirect !== null ? 1 : 0,
                $cfgRedirStr,
                strlen($cfgRedirStr),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::resolvePasskeyLoginRedirectAndIpPhp(
                    $storeUserIp,
                    $requestIp,
                    $clientIp,
                    $hasSessionRedirect,
                    $sessionRedirect,
                    $configRedirect
                );
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 4) {
                return self::resolvePasskeyLoginRedirectAndIpPhp(
                    $storeUserIp,
                    $requestIp,
                    $clientIp,
                    $hasSessionRedirect,
                    $sessionRedirect,
                    $configRedirect
                );
            }

            return [
                'user_ip' => $parts[0],
                'redirect_url' => $parts[1],
                'flash_key' => $parts[2],
                'flash_message' => $parts[3],
            ];
        } catch (Throwable) {
            return self::resolvePasskeyLoginRedirectAndIpPhp(
                $storeUserIp,
                $requestIp,
                $clientIp,
                $hasSessionRedirect,
                $sessionRedirect,
                $configRedirect
            );
        }
    }

    public function __invoke(AuthenticateUsingPasskeysRequest $request): RedirectResponse
    {
        $captchaRules = CaptchaHelper::getValidationRules();
        $captchaField = CaptchaHelper::getResponseFieldName();
        $captchaValue = $request->input($captchaField);
        $passkeyOptionsJson = Session::pull('passkey-authentication-options');

        $preflight = self::evaluatePasskeyLoginPreflight(
            $captchaRules !== [],
            is_string($captchaValue) ? $captchaValue : null,
            is_string($passkeyOptionsJson) ? $passkeyOptionsJson : null
        );

        // Passkey login should not be blocked when captcha widgets rotate/expire
        // during WebAuthn prompts. If a captcha token is present, still validate it.
        if ($preflight['should_validate_captcha']) {
            $validator = Validator::make($request->all(), $captchaRules);
            if ($validator->fails()) {
                Log::channel('failed_login')->error(
                    'Failed passkey login captcha check from IP address: '.$request->ip()
                );

                session()->flash('authenticatePasskey::message', $validator->errors()->first());
                session()->flash('authenticatePasskey::reason', 'captcha');

                return back();
            }
        }

        if (! $preflight['has_valid_options_json'] || ! is_string($passkeyOptionsJson)) {
            Log::channel('failed_login')->warning(
                'Passkey login submitted without passkey-authentication-options in session from IP address: '.$request->ip()
            );

            session()->flash('authenticatePasskey::message', $preflight['missing_options_message']);
            session()->flash('authenticatePasskey::reason', $preflight['missing_options_reason']);

            return back();
        }

        $findAuthenticatableUsingPasskey = Config::getAction(
            'find_passkey',
            FindPasskeyToAuthenticateAction::class
        );

        $passkey = $findAuthenticatableUsingPasskey->execute(
            $request->input('start_authentication_response'),
            $passkeyOptionsJson,
        );

        if (! $passkey || ! $passkey->authenticatable instanceof User) {
            Log::channel('failed_login')->error(
                'Failed passkey login attempt from IP address: '.$request->ip()
            );

            session()->flash('authenticatePasskey::message', __('passkeys::passkeys.invalid'));
            session()->flash('authenticatePasskey::reason', 'invalid_passkey');

            return back();
        }

        $user = $passkey->authenticatable;

        if (! $user->isEligibleForAuthentication()) {
            Log::channel('failed_login')->error(
                'Failed passkey login for ineligible user: '.$user->username.' from IP address: '.$request->ip()
            );

            session()->flash('authenticatePasskey::message', __('passkeys::passkeys.invalid'));
            session()->flash('authenticatePasskey::reason', 'ineligible_account');

            return back();
        }

        $newSessionToken = Str::random(60);

        $user->setRememberToken(Str::random(60));
        $user->forceFill([
            'session_token' => $newSessionToken,
        ])->save();

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put('session_token_web', $newSessionToken);

        event(new OtherDeviceLogout(Auth::getDefaultDriver(), $user));

        // Passkey auth is treated as sufficient MFA, so skip additional OTP gate.
        $request->session()->forget('2fa:trusted_device');
        Google2FA::boot($request)->login();

        $hasSessionRedirect = Session::has('passkeys.redirect');
        $sessionRedirect = $hasSessionRedirect ? (string) Session::pull('passkeys.redirect') : null;
        $configRedirect = (string) config('passkeys.redirect_to_after_login', '/');

        $postLogin = self::resolvePasskeyLoginRedirectAndIp(
            (bool) config('nntmux_settings.store_user_ip'),
            $request->ip(),
            $request->getClientIp(),
            $hasSessionRedirect,
            $sessionRedirect,
            $configRedirect
        );

        event(new UserLoggedIn($user, $postLogin['user_ip']));
        event(new PasskeyUsedToAuthenticateEvent($passkey, $request));

        return redirect($postLogin['redirect_url'])->with($postLogin['flash_key'], $postLogin['flash_message']);
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
