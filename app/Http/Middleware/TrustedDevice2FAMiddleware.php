<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TrustedDevice;
use App\Support\Google2FAAuthenticator;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrustedDevice2FAMiddleware
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_trusted_device_cookie_json(int64_t user_id, const char *token, size_t token_len, int64_t expires_at, char *out, size_t cap);
        C;

    /** Trusted device cookies last 30 days. */
    public const int COOKIE_MINUTES = 60 * 24 * 30;

    /** `nntmux_trusted_device_cookie_json()` result when `json_encode()` fails on invalid UTF-8. */
    private const int FFI_JSON_INVALID_UTF8 = -2147483647;

    /**
     * The Rust helper stays opt-in: it missed the 1.5x paired benchmark gate (common 0.37x mean /
     * 0.36x p99, uncommon 0.64x / 0.66x;
     * tests/Unit/RustSecurityForumSupportParityTest.php).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $trustedCookie = $request->cookie('2fa_trusted_device');

        if ($trustedCookie && auth()->check()) {
            try {
                if (TrustedDevice::cookieIsValidForUser($trustedCookie, (int) auth()->id())) {
                    $authenticator = app(Google2FAAuthenticator::class);
                    $authenticator->boot($request);
                    $authenticator->loginFromTrustedDevice();
                }
            } catch (\Exception $e) {
                Log::error('TrustedDevice2FAMiddleware - Error processing cookie', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $response = $next($request);

        if ($request->session()->has('2fa_trusted_device_pending')) {
            $cookieData = $request->session()->pull('2fa_trusted_device_pending');
            $cookie = \is_array($cookieData)
                ? self::serializeTrustedDeviceCookie($cookieData)
                : self::trustedDeviceCookieAttributes(json_encode($cookieData, JSON_UNESCAPED_SLASHES));

            try {
                if ($cookie !== null) {
                    $response->headers->setCookie(cookie(
                        '2fa_trusted_device',
                        $cookie['value'],
                        $cookie['minutes'],
                        $cookie['path'],
                        config('session.domain'),
                        config('session.secure'),
                        $cookie['http_only'],
                        $cookie['raw'],
                        config('session.same_site', 'lax')
                    ));
                }
            } catch (\Exception $e) {
                Log::error('TrustedDevice2FAMiddleware - Error setting cookie', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $response;
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
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
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
     * The trusted device cookie value, `json_encode($cookieData, JSON_UNESCAPED_SLASHES)`, with
     * its 30-day lifetime and fixed attributes, or null when the data cannot be encoded.
     *
     * @param  array<mixed>  $cookieData
     * @return array{value: string, minutes: int, path: string, http_only: bool, raw: bool}|null
     */
    public static function serializeTrustedDeviceCookie(array $cookieData): ?array
    {
        return self::usesRustFfi()
            ? self::serializeTrustedDeviceCookieNative($cookieData)
            : self::serializeTrustedDeviceCookiePhp($cookieData);
    }

    /**
     * @param  array<mixed>  $cookieData
     * @return array{value: string, minutes: int, path: string, http_only: bool, raw: bool}|null
     */
    public static function serializeTrustedDeviceCookiePhp(array $cookieData): ?array
    {
        return self::trustedDeviceCookieAttributes(json_encode($cookieData, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Rust encodes the canonical `['user_id' => int, 'token' => string, 'expires_at' => int]`
     * payload; any other shape uses PHP.
     *
     * @param  array<mixed>  $cookieData
     * @return array{value: string, minutes: int, path: string, http_only: bool, raw: bool}|null
     */
    public static function serializeTrustedDeviceCookieNative(array $cookieData): ?array
    {
        if (array_keys($cookieData) !== ['user_id', 'token', 'expires_at'] ||
            ! \is_int($cookieData['user_id']) ||
            ! \is_string($cookieData['token']) ||
            ! \is_int($cookieData['expires_at'])) {
            return self::serializeTrustedDeviceCookiePhp($cookieData);
        }

        $token = $cookieData['token'];
        $ffi = self::ffi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, 6 * \strlen($token) + 64, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::serializeTrustedDeviceCookiePhp($cookieData);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_trusted_device_cookie_json($cookieData['user_id'], $token, \strlen($token), $cookieData['expires_at'], $scratch, $cap);
        } catch (Throwable) {
            return self::serializeTrustedDeviceCookiePhp($cookieData);
        }

        if ($written === self::FFI_JSON_INVALID_UTF8) {
            return null;
        }
        if (! \is_int($written) || $written <= 0 || $written > $cap) {
            return self::serializeTrustedDeviceCookiePhp($cookieData);
        }

        return self::trustedDeviceCookieAttributes(FFI::string($scratch, $written));
    }

    /**
     * @return array{value: string, minutes: int, path: string, http_only: bool, raw: bool}|null
     */
    private static function trustedDeviceCookieAttributes(string|false $value): ?array
    {
        if ($value === false) {
            return null;
        }

        return [
            'value' => $value,
            'minutes' => self::COOKIE_MINUTES,
            'path' => '/',
            'http_only' => true,
            'raw' => false,
        ];
    }
}
