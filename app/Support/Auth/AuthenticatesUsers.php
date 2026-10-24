<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

trait AuthenticatesUsers
{
    use RedirectsUsers, ThrottlesLogins {
        ThrottlesLogins::enableRustFfi as enableThrottleRustFfi;
        ThrottlesLogins::disableRustFfi as disableThrottleRustFfi;
        ThrottlesLogins::resetRustFfi as resetThrottleRustFfi;
        ThrottlesLogins::usesRustFfi as usesThrottleRustFfi;
        ThrottlesLogins::setFfiForTesting as setThrottleFfiForTesting;
    }

    public const string AUTHENTICATES_USERS_FFI_CDEF = <<<'CDEF'
ssize_t nntmux_auth_login_spec_nul(
    const char *username_ptr,
    size_t username_len,
    uint8_t has_custom_response,
    uint8_t wants_json,
    const char *redirect_ptr,
    size_t redirect_len,
    int32_t *out_status,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useAuthenticatesUsersRustFfi = false;

    private static FFI|false|null $authenticatesUsersFfi = null;

    public static function enableRustFfi(): void
    {
        self::$useAuthenticatesUsersRustFfi = true;
        self::enableThrottleRustFfi();
    }

    public static function disableRustFfi(): void
    {
        self::$useAuthenticatesUsersRustFfi = false;
        self::disableThrottleRustFfi();
    }

    public static function resetRustFfi(): void
    {
        self::$useAuthenticatesUsersRustFfi = false;
        self::$authenticatesUsersFfi = null;
        self::resetThrottleRustFfi();
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useAuthenticatesUsersRustFfi = $useRustFfi;
        if ($useRustFfi) {
            self::enableThrottleRustFfi();
        } else {
            self::disableThrottleRustFfi();
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useAuthenticatesUsersRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$authenticatesUsersFfi = $override;
        self::setThrottleFfiForTesting($override);
    }

    public static function enableAuthenticatesUsersRustFfi(): void
    {
        self::$useAuthenticatesUsersRustFfi = true;
    }

    public static function disableAuthenticatesUsersRustFfi(): void
    {
        self::$useAuthenticatesUsersRustFfi = false;
    }

    public static function resetAuthenticatesUsersRustFfi(): void
    {
        self::$useAuthenticatesUsersRustFfi = false;
        self::$authenticatesUsersFfi = null;
    }

    public static function setUseAuthenticatesUsersRustFfi(bool $useRustFfi): void
    {
        self::$useAuthenticatesUsersRustFfi = $useRustFfi;
    }

    public static function usesAuthenticatesUsersRustFfi(): bool
    {
        return self::$useAuthenticatesUsersRustFfi;
    }

    public static function setAuthenticatesUsersFfiForTesting(FFI|false|null $override = null): void
    {
        self::$authenticatesUsersFfi = $override;
    }

    /**
     * @return array<string, string>
     */
    public static function buildLoginValidationRules(string $usernameField): array
    {
        if (self::$useAuthenticatesUsersRustFfi) {
            return self::buildLoginValidationRulesNative($usernameField);
        }

        return self::buildLoginValidationRulesPhp($usernameField);
    }

    /**
     * @return array<string, string>
     */
    public static function buildLoginValidationRulesPhp(string $usernameField): array
    {
        return [
            $usernameField => 'required|string',
            'password' => 'required|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function buildLoginValidationRulesNative(string $usernameField): array
    {
        $spec = self::invokeLoginSpecNative($usernameField, false, false, '/');
        if ($spec === null) {
            return self::buildLoginValidationRulesPhp($usernameField);
        }

        return $spec['rules'];
    }

    /**
     * @return list<string>
     */
    public static function buildLoginCredentialKeys(string $usernameField): array
    {
        if (self::$useAuthenticatesUsersRustFfi) {
            return self::buildLoginCredentialKeysNative($usernameField);
        }

        return self::buildLoginCredentialKeysPhp($usernameField);
    }

    /**
     * @return list<string>
     */
    public static function buildLoginCredentialKeysPhp(string $usernameField): array
    {
        return [$usernameField, 'password'];
    }

    /**
     * @return list<string>
     */
    public static function buildLoginCredentialKeysNative(string $usernameField): array
    {
        $spec = self::invokeLoginSpecNative($usernameField, false, false, '/');
        if ($spec === null) {
            return self::buildLoginCredentialKeysPhp($usernameField);
        }

        return $spec['credential_keys'];
    }

    /**
     * @return array{action: string, status: int, redirect_path: string}
     */
    public static function resolvePostLoginAction(
        bool $hasCustomResponse,
        bool $wantsJson,
        string $redirectPath
    ): array {
        if (self::$useAuthenticatesUsersRustFfi) {
            return self::resolvePostLoginActionNative($hasCustomResponse, $wantsJson, $redirectPath);
        }

        return self::resolvePostLoginActionPhp($hasCustomResponse, $wantsJson, $redirectPath);
    }

    /**
     * @return array{action: string, status: int, redirect_path: string}
     */
    public static function resolvePostLoginActionPhp(
        bool $hasCustomResponse,
        bool $wantsJson,
        string $redirectPath
    ): array {
        if ($hasCustomResponse) {
            return [
                'action' => 'custom',
                'status' => 200,
                'redirect_path' => $redirectPath,
            ];
        }

        if ($wantsJson) {
            return [
                'action' => 'json',
                'status' => 204,
                'redirect_path' => $redirectPath,
            ];
        }

        return [
            'action' => 'redirect_intended',
            'status' => 302,
            'redirect_path' => $redirectPath,
        ];
    }

    /**
     * @return array{action: string, status: int, redirect_path: string}
     */
    public static function resolvePostLoginActionNative(
        bool $hasCustomResponse,
        bool $wantsJson,
        string $redirectPath
    ): array {
        $spec = self::invokeLoginSpecNative('email', $hasCustomResponse, $wantsJson, $redirectPath);
        if ($spec === null) {
            return self::resolvePostLoginActionPhp($hasCustomResponse, $wantsJson, $redirectPath);
        }

        return [
            'action' => $spec['action'],
            'status' => $spec['status'],
            'redirect_path' => $spec['redirect_path'],
        ];
    }

    /**
     * Show the application's login form.
     *
     * @return View
     */
    public function showLoginForm(): mixed
    {
        return view('auth.login');
    }

    /**
     * Validate the user login request.
     *
     * @throws ValidationException
     */
    protected function validateLogin(Request $request): void
    {
        $request->validate(self::buildLoginValidationRules($this->username()));
    }

    /**
     * Attempt to log the user into the application.
     */
    protected function attemptLogin(Request $request): bool
    {
        return $this->guard()->attempt(
            $this->credentials($request), $request->boolean('remember')
        );
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @return array<string, mixed>
     */
    protected function credentials(Request $request): array
    {
        return $request->only(self::buildLoginCredentialKeys($this->username()));
    }

    /**
     * Send the response after the user was authenticated.
     */
    protected function sendLoginResponse(Request $request): RedirectResponse|JsonResponse
    {
        $request->session()->regenerate();

        $this->clearLoginAttempts($request);

        if ($response = $this->authenticated($request, $this->guard()->user())) {
            return $response;
        }

        $action = self::resolvePostLoginAction(false, $request->wantsJson(), $this->redirectPath());

        return $action['action'] === 'json'
            ? new JsonResponse([], $action['status'])
            : redirect()->intended($action['redirect_path']);
    }

    /**
     * The user has been authenticated.
     *
     * @param  mixed  $user
     * @return RedirectResponse|JsonResponse|null
     */
    protected function authenticated(Request $request, $user): mixed
    {
        return null;
    }

    /**
     * Get the failed login response instance.
     *
     * @throws ValidationException
     */
    protected function sendFailedLoginResponse(Request $request): never
    {
        throw ValidationException::withMessages([
            $this->username() => [trans('auth.failed')],
        ]);
    }

    /**
     * Get the login username to be used by the controller.
     */
    public function username(): string
    {
        return 'email';
    }

    /**
     * Get the guard to be used during authentication.
     */
    protected function guard(): StatefulGuard
    {
        return Auth::guard();
    }

    /**
     * @return array{
     *     rules: array<string, string>,
     *     credential_keys: list<string>,
     *     action: string,
     *     status: int,
     *     redirect_path: string
     * }|null
     */
    private static function invokeLoginSpecNative(
        string $usernameField,
        bool $hasCustomResponse,
        bool $wantsJson,
        string $redirectPath
    ): ?array {
        $ffi = self::authenticatesUsersFfi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            $cap = max(128, strlen($usernameField) + strlen($redirectPath) + 96);
            $outStatus = $ffi->new('int32_t[1]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_auth_login_spec_nul(
                $usernameField,
                strlen($usernameField),
                $hasCustomResponse ? 1 : 0,
                $wantsJson ? 1 : 0,
                $redirectPath,
                strlen($redirectPath),
                $outStatus,
                $outBuf,
                $cap
            );
            $status = $outStatus[0];
            if (! is_int($written) || $written <= 0 || ! is_int($status)) {
                return null;
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 6) {
                return null;
            }

            return [
                'rules' => [
                    $parts[0] => $parts[1],
                    $parts[2] => $parts[3],
                ],
                'credential_keys' => [$parts[0], $parts[2]],
                'action' => $parts[4],
                'status' => $status,
                'redirect_path' => $parts[5],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function authenticatesUsersFfi(): ?FFI
    {
        if (self::$authenticatesUsersFfi === false) {
            return null;
        }

        if (self::$authenticatesUsersFfi instanceof FFI) {
            return self::$authenticatesUsersFfi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::AUTHENTICATES_USERS_FFI_CDEF);
        self::$authenticatesUsersFfi = $ffi ?? false;

        return $ffi;
    }
}
