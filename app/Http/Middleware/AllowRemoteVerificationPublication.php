<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The unauthenticated remote-worker publication endpoint is off unless configured, and then
 * answers only private-network clients. Serve it on a port reachable only from the LAN: Docker
 * port forwarding can present every client as the bridge gateway.
 */
class AllowRemoteVerificationPublication
{
    /**
     * Rust candidate for the network list parser. It stays opt-in: it did not beat PHP
     * by 1.5x in the paired benchmark
     * (tests/Unit/RustRedisDegradeQuerySessionJsonMiddlewareParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_verification_networks_parse(const char *input, size_t input_len, char *out, size_t cap);
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
        if (! (bool) config('release_verification.publish_endpoint')) {
            abort(404);
        }
        $networks = self::allowedNetworks((string) config('release_verification.publish_allowed_networks'));
        $ip = $request->ip();
        if ($ip === null || $networks === [] || ! IpUtils::checkIp($ip, $networks)) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * Comma-separated allowed networks, trimmed, without empty (or `0`) entries.
     *
     * @return list<string>
     */
    public static function allowedNetworks(string $networks): array
    {
        return self::$useRustFfi ? self::allowedNetworksNative($networks) : self::allowedNetworksPhp($networks);
    }

    /**
     * @return list<string>
     */
    public static function allowedNetworksPhp(string $networks): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $networks))));
    }

    /**
     * Rust joins the entries with NUL, so a list containing NUL stays on the PHP path.
     *
     * @return list<string>
     */
    public static function allowedNetworksNative(string $networks): array
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, \strlen($networks) + 1, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::allowedNetworksPhp($networks);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_verification_networks_parse($networks, \strlen($networks), $scratch, $cap);
        if (! \is_int($written) || $written < 0) {
            return self::allowedNetworksPhp($networks);
        }

        return $written === 0 ? [] : explode("\0", FFI::string($scratch, $written));
    }
}
