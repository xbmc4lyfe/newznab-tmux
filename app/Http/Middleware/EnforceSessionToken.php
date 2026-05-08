<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->user()) {
            return $next($request);
        }

        $user = $request->user();
        $userToken = (string) ($user->session_token ?? '');
        $sessionToken = (string) ($request->session()->get('session_token_web') ?? '');

        if ($userToken === '') {
            return $next($request);
        }

        if ($sessionToken === '') {
            $request->session()->put('session_token_web', $userToken);

            return $next($request);
        }

        if (! hash_equals($userToken, $sessionToken)) {
            Auth::guard()->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, 'Session has been logged out from another device.');
            }

            return redirect()->route('login')
                ->with('info', 'You were logged out because a passkey login was started on another device.');
        }

        return $next($request);
    }
}
