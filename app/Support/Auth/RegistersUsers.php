<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

trait RegistersUsers
{
    use RedirectsUsers;

    public const string REGISTERS_USERS_FFI_CDEF = <<<'CDEF'
ssize_t nntmux_auth_register_outcome_nul(
    uint8_t has_custom_response,
    uint8_t wants_json,
    const char *redirect_ptr,
    size_t redirect_len,
    int32_t *out_flags_and_status,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRegistersUsersRustFfi = false;

    private static FFI|false|null $registersUsersFfi = null;

    public static function enableRustFfi(): void
    {
        self::$useRegistersUsersRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRegistersUsersRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRegistersUsersRustFfi = false;
        self::$registersUsersFfi = null;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRegistersUsersRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRegistersUsersRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$registersUsersFfi = $override;
    }

    /**
     * @return array{
     *     action: string,
     *     is_json: bool,
     *     status: int,
     *     redirect_path: string,
     *     view_name: string
     * }
     */
    public static function resolvePostRegistrationOutcome(
        bool $wantsJson,
        string $redirectPath,
        bool $hasCustomResponse = false
    ): array {
        if (self::$useRegistersUsersRustFfi) {
            return self::resolvePostRegistrationOutcomeNative($wantsJson, $redirectPath, $hasCustomResponse);
        }

        return self::resolvePostRegistrationOutcomePhp($wantsJson, $redirectPath, $hasCustomResponse);
    }

    /**
     * @return array{
     *     action: string,
     *     is_json: bool,
     *     status: int,
     *     redirect_path: string,
     *     view_name: string
     * }
     */
    public static function resolvePostRegistrationOutcomePhp(
        bool $wantsJson,
        string $redirectPath,
        bool $hasCustomResponse = false
    ): array {
        if ($hasCustomResponse) {
            return [
                'action' => 'custom',
                'is_json' => false,
                'status' => 200,
                'redirect_path' => $redirectPath,
                'view_name' => 'auth.register',
            ];
        }

        if ($wantsJson) {
            return [
                'action' => 'json',
                'is_json' => true,
                'status' => 201,
                'redirect_path' => $redirectPath,
                'view_name' => 'auth.register',
            ];
        }

        return [
            'action' => 'redirect',
            'is_json' => false,
            'status' => 302,
            'redirect_path' => $redirectPath,
            'view_name' => 'auth.register',
        ];
    }

    /**
     * @return array{
     *     action: string,
     *     is_json: bool,
     *     status: int,
     *     redirect_path: string,
     *     view_name: string
     * }
     */
    public static function resolvePostRegistrationOutcomeNative(
        bool $wantsJson,
        string $redirectPath,
        bool $hasCustomResponse = false
    ): array {
        $ffi = self::registersUsersFfi();
        if (! $ffi instanceof FFI) {
            return self::resolvePostRegistrationOutcomePhp($wantsJson, $redirectPath, $hasCustomResponse);
        }

        try {
            $cap = max(64, strlen($redirectPath) + 48);
            $outFlagsAndStatus = $ffi->new('int32_t[2]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_auth_register_outcome_nul(
                $hasCustomResponse ? 1 : 0,
                $wantsJson ? 1 : 0,
                $redirectPath,
                strlen($redirectPath),
                $outFlagsAndStatus,
                $outBuf,
                $cap
            );
            $isJsonFlag = $outFlagsAndStatus[0];
            $status = $outFlagsAndStatus[1];
            if (! is_int($written) || $written <= 0 || ! is_int($isJsonFlag) || ! is_int($status)) {
                return self::resolvePostRegistrationOutcomePhp($wantsJson, $redirectPath, $hasCustomResponse);
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 3) {
                return self::resolvePostRegistrationOutcomePhp($wantsJson, $redirectPath, $hasCustomResponse);
            }

            return [
                'action' => $parts[0],
                'is_json' => $isJsonFlag === 1,
                'status' => $status,
                'redirect_path' => $parts[1],
                'view_name' => $parts[2],
            ];
        } catch (Throwable) {
            return self::resolvePostRegistrationOutcomePhp($wantsJson, $redirectPath, $hasCustomResponse);
        }
    }

    /**
     * Show the application registration form.
     *
     * @return View
     */
    public function showRegistrationForm(): mixed
    {
        return view('auth.register');
    }

    /**
     * Handle a registration request for the application.
     */
    public function register(Request $request): RedirectResponse|JsonResponse
    {
        $this->validator($request->all())->validate();

        event(new Registered($user = $this->create($request->all())));

        $this->guard()->login($user);

        if ($response = $this->registered($request, $user)) {
            return $response;
        }

        $outcome = self::resolvePostRegistrationOutcome($request->wantsJson(), $this->redirectPath());

        return $outcome['is_json']
            ? new JsonResponse([], $outcome['status'])
            : redirect($outcome['redirect_path']);
    }

    /**
     * Get the guard to be used during registration.
     */
    protected function guard(): StatefulGuard
    {
        return Auth::guard();
    }

    /**
     * The user has been registered.
     *
     * @param  mixed  $user
     * @return RedirectResponse|JsonResponse|null
     */
    protected function registered(Request $request, $user): mixed
    {
        return null;
    }

    private static function registersUsersFfi(): ?FFI
    {
        if (self::$registersUsersFfi === false) {
            return null;
        }

        if (self::$registersUsersFfi instanceof FFI) {
            return self::$registersUsersFfi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::REGISTERS_USERS_FFI_CDEF);
        self::$registersUsersFfi = $ffi ?? false;

        return $ffi;
    }
}
