<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to prevent caching of authenticated and auth-flow pages.
 *
 * This prevents issues where:
 * - CDNs (like Cloudflare) cache user-specific pages
 * - Flash messages on login/register flows are served from stale HTML
 * - Browsers reuse cached auth forms after redirects or CAPTCHA validation
 * - One user could see another user's data due to cache poisoning
 */
class NoCacheForAuthenticatedUsers
{
    /**
     * Rust candidates for the eligibility check and `Vary` merge. They stay opt-in:
     * neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchApiThrottleNoCacheFiltersParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_nocache_should_prevent(int32_t authenticated, const char *route_name, size_t route_name_len, int32_t has_route_name);
        intptr_t nntmux_nocache_merge_vary(const char *existing, size_t existing_len, int32_t has_bearer, char *out, size_t cap);
        C;

    /**
     * Session-backed auth-flow routes that are never cached.
     *
     * @var list<string>
     */
    public const array AUTH_FLOW_ROUTES = [
        'login',
        'login.post',
        'register',
        'register.post',
        'logout',
        'forgottenpassword',
        'password.request',
        '2fa.verify',
        '2fa.post',
        '2faVerify',
    ];

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

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldPreventCaching($request)) {
            $this->applyNoCacheHeaders($request, $response);
        }

        return $response;
    }

    /**
     * Keep session-backed auth flows out of CDN/browser caches.
     */
    private function shouldPreventCaching(Request $request): bool
    {
        $authenticated = Auth::check();
        $route = $request->route();

        return self::shouldPreventCachingFor($authenticated, $route instanceof Route ? $route->getName() : null);
    }

    /**
     * `Auth::check() || $request->routeIs(...AUTH_FLOW_ROUTES)`; the literal route
     * patterns make `routeIs()` an exact, case-sensitive name match.
     */
    public static function shouldPreventCachingFor(bool $authenticated, ?string $routeName): bool
    {
        return self::$useRustFfi
            ? self::shouldPreventCachingForNative($authenticated, $routeName)
            : self::shouldPreventCachingForPhp($authenticated, $routeName);
    }

    public static function shouldPreventCachingForPhp(bool $authenticated, ?string $routeName): bool
    {
        return $authenticated || ($routeName !== null && \in_array($routeName, self::AUTH_FLOW_ROUTES, true));
    }

    public static function shouldPreventCachingForNative(bool $authenticated, ?string $routeName): bool
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::shouldPreventCachingForPhp($authenticated, $routeName);
        }

        $name = $routeName ?? '';
        // @phpstan-ignore method.notFound
        $result = $ffi->nntmux_nocache_should_prevent($authenticated ? 1 : 0, $name, \strlen($name), $routeName === null ? 0 : 1);

        return \is_int($result) && $result >= 0 ? $result === 1 : self::shouldPreventCachingForPhp($authenticated, $routeName);
    }

    /**
     * Add `Cookie` (and `Authorization` for bearer requests) to an existing `Vary` value.
     */
    public static function mergeVary(string $existingVary, bool $hasBearerToken): string
    {
        return self::$useRustFfi
            ? self::mergeVaryNative($existingVary, $hasBearerToken)
            : self::mergeVaryPhp($existingVary, $hasBearerToken);
    }

    public static function mergeVaryPhp(string $existingVary, bool $hasBearerToken): string
    {
        $varyHeaders = array_filter(array_map('trim', explode(',', $existingVary)));

        if (! in_array('Cookie', $varyHeaders, true)) {
            $varyHeaders[] = 'Cookie';
        }

        if ($hasBearerToken && ! in_array('Authorization', $varyHeaders, true)) {
            $varyHeaders[] = 'Authorization';
        }

        return implode(', ', $varyHeaders);
    }

    public static function mergeVaryNative(string $existingVary, bool $hasBearerToken): string
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, \strlen($existingVary) + 32, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::mergeVaryPhp($existingVary, $hasBearerToken);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_nocache_merge_vary($existingVary, \strlen($existingVary), $hasBearerToken ? 1 : 0, $scratch, $cap);

        return \is_int($written) && $written > 0 ? FFI::string($scratch, $written) : self::mergeVaryPhp($existingVary, $hasBearerToken);
    }

    private function applyNoCacheHeaders(Request $request, Response $response): void
    {
        // 'private' tells shared caches that this response must not be reused.
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private, max-age=0, s-maxage=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');

        // Cloudflare-specific headers to reinforce cache bypass at the edge.
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');

        $existingVary = (string) $response->headers->get('Vary', '');

        $response->headers->set('Vary', self::mergeVary($existingVary, (bool) $request->bearerToken()));
    }
}
