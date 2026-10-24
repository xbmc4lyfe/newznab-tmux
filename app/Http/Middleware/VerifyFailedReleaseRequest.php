<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyFailedReleaseRequest
{
    /** API token requests are authenticated by the controller. */
    public const int ACTION_PASS_THROUGH = 0;

    /** Browser POST reports go through CSRF verification. */
    public const int ACTION_VERIFY_CSRF = 1;

    /** Browser reports with any other method are rejected with 405. */
    public const int ACTION_REJECT_METHOD = 2;

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_failed_release_request_action(uint32_t has_api_token, const char *method, size_t method_len);
        C;

    /**
     * The Rust helper stays opt-in: it did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchUnavailableObserversFailedRelease2faParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function __construct(private readonly PreventRequestForgery $csrf) {}

    /**
     * Handle an incoming request.
     * API token requests are authenticated exclusively by the controller.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $action = self::requestAction($request->has('api_token'), $request->getMethod());

        if ($action === self::ACTION_PASS_THROUGH) {
            return $next($request);
        }

        abort_unless($action === self::ACTION_VERIFY_CSRF, 405, 'Browser failure reports require POST.');

        return $this->csrf->handle($request, $next);
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
     * One of the `ACTION_*` constants for a request with or without an `api_token`
     * input and its uppercase method (`Request::getMethod()`).
     */
    public static function requestAction(bool $hasApiToken, string $method): int
    {
        return self::usesRustFfi()
            ? self::requestActionNative($hasApiToken, $method)
            : self::requestActionPhp($hasApiToken, $method);
    }

    public static function requestActionPhp(bool $hasApiToken, string $method): int
    {
        if ($hasApiToken) {
            return self::ACTION_PASS_THROUGH;
        }

        return $method === 'POST' ? self::ACTION_VERIFY_CSRF : self::ACTION_REJECT_METHOD;
    }

    public static function requestActionNative(bool $hasApiToken, string $method): int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::requestActionPhp($hasApiToken, $method);
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_failed_release_request_action($hasApiToken ? 1 : 0, $method, \strlen($method));
        } catch (Throwable) {
            return self::requestActionPhp($hasApiToken, $method);
        }

        return \is_int($result) && $result >= self::ACTION_PASS_THROUGH && $result <= self::ACTION_REJECT_METHOD
            ? $result
            : self::requestActionPhp($hasApiToken, $method);
    }
}
