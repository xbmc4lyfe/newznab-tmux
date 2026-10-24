<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonOnAPI
{
    /**
     * Rust candidate for the `Accept` rewrite. It stays opt-in: it did not beat PHP by
     * 1.5x in the paired benchmark
     * (tests/Unit/RustRedisDegradeQuerySessionJsonMiddlewareParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_force_json_accept(const char *path, size_t path_len, const char *accept, size_t accept_len, int32_t has_accept, char *out, size_t cap);
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
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force Json accept type on api routes
        $accept = $request->header('accept');
        $forced = self::forcedAcceptHeader($request->decodedPath(), \is_string($accept) ? $accept : null);
        if ($forced !== null) {
            $request->headers->set('accept', $forced);
        }

        return $next($request);
    }

    /**
     * The rewritten `Accept` header for `api/*` paths that don't already accept JSON,
     * or null to leave the header unchanged.
     */
    public static function forcedAcceptHeader(string $decodedPath, ?string $accept): ?string
    {
        return self::$useRustFfi
            ? self::forcedAcceptHeaderNative($decodedPath, $accept)
            : self::forcedAcceptHeaderPhp($decodedPath, $accept);
    }

    public static function forcedAcceptHeaderPhp(string $decodedPath, ?string $accept): ?string
    {
        if (Str::is('api/*', $decodedPath) && ! Str::contains($accept, ['/json', '+json'])) {
            return 'application/json,'.$accept;
        }

        return null;
    }

    public static function forcedAcceptHeaderNative(string $decodedPath, ?string $accept): ?string
    {
        $ffi = self::searchFfi();
        $value = $accept ?? '';
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, \strlen($value) + 32, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::forcedAcceptHeaderPhp($decodedPath, $accept);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_force_json_accept($decodedPath, \strlen($decodedPath), $value, \strlen($value), $accept === null ? 0 : 1, $scratch, $cap);
        if (! \is_int($written) || $written < 0) {
            return self::forcedAcceptHeaderPhp($decodedPath, $accept);
        }

        return $written === 0 ? null : FFI::string($scratch, $written);
    }
}
