<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outermost global middleware for HTTP QUERY (RFC 10008) response headers.
 *
 * Running outside every other layer means throttle 429s, 404/405s, maintenance
 * and Redis-degrade responses are covered too. QUERY responses carry per-user
 * quota data and token-bearing links, so they must never be stored by caches.
 * Responses produced before routing get the cache policy but no Accept-Query.
 */
final class DecorateHttpQueryResponses
{
    public const ACCEPT_QUERY = 'application/json';

    /** Header flag: set `Cache-Control: private, no-store`. */
    public const int SET_CACHE_CONTROL = 1;

    /** Header flag: set `Accept-Query`. */
    public const int SET_ACCEPT_QUERY = 2;

    /**
     * Rust candidate for the header decision. It stays opt-in: it did not beat PHP by
     * 1.5x in the paired benchmark
     * (tests/Unit/RustRedisDegradeQuerySessionJsonMiddlewareParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_http_query_header_flags(const char *method, size_t method_len, const char *path, size_t path_len, int32_t has_route, const char *methods, size_t methods_len, const size_t *lens, size_t count);
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

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $route = $request->route();
        $flags = self::headerFlags(
            $request->getMethod(),
            $request->decodedPath(),
            $route instanceof Route ? $route->methods() : null,
        );

        if (($flags & self::SET_CACHE_CONTROL) !== 0) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        if (($flags & self::SET_ACCEPT_QUERY) !== 0) {
            $response->headers->set('Accept-Query', self::ACCEPT_QUERY);
        }

        return $response;
    }

    /**
     * `$request->isMethod('QUERY') && $request->is('api/*')` and a matched route
     * listing `QUERY`, as `SET_*` flags.
     *
     * @param  array<int, mixed>|null  $routeMethods  null when no route matched
     */
    public static function headerFlags(string $method, string $decodedPath, ?array $routeMethods): int
    {
        return self::$useRustFfi
            ? self::headerFlagsNative($method, $decodedPath, $routeMethods)
            : self::headerFlagsPhp($method, $decodedPath, $routeMethods);
    }

    /**
     * @param  array<int, mixed>|null  $routeMethods
     */
    public static function headerFlagsPhp(string $method, string $decodedPath, ?array $routeMethods): int
    {
        $flags = 0;
        if (strtoupper($method) === 'QUERY' && Str::is('api/*', $decodedPath)) {
            $flags |= self::SET_CACHE_CONTROL;
        }

        if ($routeMethods !== null && \in_array('QUERY', $routeMethods, true)) {
            $flags |= self::SET_ACCEPT_QUERY;
        }

        return $flags;
    }

    /**
     * Route methods must be strings (as `Route::methods()` returns); anything else keeps
     * the strict `in_array()` semantics in PHP.
     *
     * @param  array<int, mixed>|null  $routeMethods
     */
    public static function headerFlagsNative(string $method, string $decodedPath, ?array $routeMethods): int
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::headerFlagsPhp($method, $decodedPath, $routeMethods);
        }

        $methods = $routeMethods ?? [];
        $count = \count($methods);
        $lens = null;
        if ($count > 0) {
            $lens = $ffi->new('size_t['.$count.']');
            $i = 0;
            foreach ($methods as $routeMethod) {
                if (! \is_string($routeMethod)) {
                    return self::headerFlagsPhp($method, $decodedPath, $routeMethods);
                }
                $lens[$i++] = \strlen($routeMethod);
            }
        }

        $packed = implode('', $methods);
        // @phpstan-ignore method.notFound
        $result = $ffi->nntmux_http_query_header_flags(
            $method,
            \strlen($method),
            $decodedPath,
            \strlen($decodedPath),
            $routeMethods === null ? 0 : 1,
            $packed,
            \strlen($packed),
            $lens,
            $count,
        );

        return \is_int($result) && $result >= 0 ? $result : self::headerFlagsPhp($method, $decodedPath, $routeMethods);
    }
}
