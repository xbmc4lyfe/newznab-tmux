<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Google2FAAuthenticator;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Google2FAMiddleware
{
    /** Leave the intended URL untouched. */
    public const int INTENDED_NONE = 0;

    /** Send the user back to the profile security section after verification. */
    public const int INTENDED_PROFILE_SECURITY = 1;

    /** Send the user back to the requested URL after verification. */
    public const int INTENDED_FULL_URL = 2;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_google2fa_intended_action(uint32_t has_route_name, const char *name, size_t name_len, const char *method, size_t method_len);
        C;

    /**
     * The Rust helper stays opt-in: it did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchUnavailableObserversFailedRelease2faParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Google2FAAuthenticator $authenticator */
        $authenticator = app(Google2FAAuthenticator::class)->boot($request);

        if ($authenticator->isAuthenticated()) {
            return $next($request);
        }

        $route = $request->route();
        $intended = self::intendedUrlAction(
            $route instanceof Route ? $route->getName() : null,
            $request->getMethod(),
        );

        if ($intended === self::INTENDED_PROFILE_SECURITY) {
            $request->session()->put('url.intended', route('profileedit').'#security');
        } elseif ($intended === self::INTENDED_FULL_URL) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Two-factor authentication required.',
                'redirect' => route('2fa.verify'),
            ], 403);
        }

        return $authenticator->makeRequestOneTimePasswordResponse();
    }

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
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::DEFAULT_USE_RUST_FFI;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * One of the `INTENDED_*` constants for the current route name and uppercase
     * method (`Request::getMethod()`), matching
     * `routeIs('passkeys.*', 'password.confirm')` then `isMethod('GET')`.
     */
    public static function intendedUrlAction(?string $routeName, string $method): int
    {
        return self::usesRustFfi()
            ? self::intendedUrlActionNative($routeName, $method)
            : self::intendedUrlActionPhp($routeName, $method);
    }

    public static function intendedUrlActionPhp(?string $routeName, string $method): int
    {
        if ($routeName !== null && Str::is(['passkeys.*', 'password.confirm'], $routeName)) {
            return self::INTENDED_PROFILE_SECURITY;
        }

        return $method === 'GET' ? self::INTENDED_FULL_URL : self::INTENDED_NONE;
    }

    public static function intendedUrlActionNative(?string $routeName, string $method): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::intendedUrlActionPhp($routeName, $method);
        }

        $name = $routeName ?? '';
        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_google2fa_intended_action($routeName === null ? 0 : 1, $name, \strlen($name), $method, \strlen($method));
        } catch (Throwable) {
            return self::intendedUrlActionPhp($routeName, $method);
        }

        return \is_int($result) && $result >= self::INTENDED_NONE && $result <= self::INTENDED_FULL_URL
            ? $result
            : self::intendedUrlActionPhp($routeName, $method);
    }
}
