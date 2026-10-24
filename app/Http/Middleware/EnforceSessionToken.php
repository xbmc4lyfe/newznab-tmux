<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSessionToken
{
    /** Continue the request. */
    public const int ACTION_ALLOW = 0;

    /** Store the user's token in the empty session slot, then continue. */
    public const int ACTION_SEED = 1;

    /** Log out: the session belongs to another sign-in. */
    public const int ACTION_REJECT = 2;

    /**
     * Rust candidate for the token comparison. It stays opt-in: it did not beat PHP by
     * 1.5x in the paired benchmark
     * (tests/Unit/RustRedisDegradeQuerySessionJsonMiddlewareParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_session_token_action(const char *user_token, size_t user_token_len, const char *session_token, size_t session_token_len);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->user()) {
            return $next($request);
        }

        $user = $request->user();
        $userToken = (string) ($user->session_token ?? '');
        $sessionToken = (string) ($request->session()->get('session_token_web') ?? '');

        $action = self::sessionTokenAction($userToken, $sessionToken);

        if ($action === self::ACTION_SEED) {
            $request->session()->put('session_token_web', $userToken);

            return $next($request);
        }

        if ($action === self::ACTION_REJECT) {
            Auth::guard()->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, 'Session has been logged out from another device.');
            }

            return redirect()->route('login')
                ->with('info', 'You were logged out because your account was signed in on another device or session.');
        }

        return $next($request);
    }

    /**
     * One of the `ACTION_*` constants for the user's and session's tokens.
     */
    public static function sessionTokenAction(string $userToken, string $sessionToken): int
    {
        return self::$useRustFfi
            ? self::sessionTokenActionNative($userToken, $sessionToken)
            : self::sessionTokenActionPhp($userToken, $sessionToken);
    }

    public static function sessionTokenActionPhp(string $userToken, string $sessionToken): int
    {
        if ($userToken === '') {
            return self::ACTION_ALLOW;
        }

        if ($sessionToken === '') {
            return self::ACTION_SEED;
        }

        return hash_equals($userToken, $sessionToken) ? self::ACTION_ALLOW : self::ACTION_REJECT;
    }

    public static function sessionTokenActionNative(string $userToken, string $sessionToken): int
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::sessionTokenActionPhp($userToken, $sessionToken);
        }

        // @phpstan-ignore method.notFound
        $result = $ffi->nntmux_session_token_action($userToken, \strlen($userToken), $sessionToken, \strlen($sessionToken));

        return \is_int($result) && $result >= 0 && $result <= self::ACTION_REJECT
            ? $result
            : self::sessionTokenActionPhp($userToken, $sessionToken);
    }
}
