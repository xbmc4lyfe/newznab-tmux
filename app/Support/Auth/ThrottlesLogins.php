<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

trait ThrottlesLogins
{
    public const string THROTTLE_FFI_CDEF = <<<'C'
        int32_t nntmux_throttle_key(const char *username, size_t username_len, const char *ip, size_t ip_len, char *out, size_t cap);
        int64_t nntmux_throttle_lockout_minutes(int64_t seconds);
        C;

    /**
     * The Rust helpers stay opt-in: they missed the 1.5x paired benchmark gate (throttle key common
     * 0.79x mean / 0.76x p99, uncommon 2.10x / 1.68x; lockout minutes common 0.40x / 0.26x,
     * uncommon 0.43x / 0.33x;
     * tests/Unit/RustSecurityForumSupportParityTest.php).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Determine if the user has too many failed login attempts.
     */
    protected function hasTooManyLoginAttempts(Request $request): bool
    {
        return $this->limiter()->tooManyAttempts(
            $this->throttleKey($request), $this->maxAttempts()
        );
    }

    /**
     * Increment the login attempts for the user.
     */
    protected function incrementLoginAttempts(Request $request): void
    {
        $this->limiter()->hit(
            $this->throttleKey($request), $this->decayMinutes() * 60
        );
    }

    /**
     * Redirect the user after determining they are locked out.
     *
     * @throws ValidationException
     */
    protected function sendLockoutResponse(Request $request): never
    {
        $seconds = $this->limiter()->availableIn(
            $this->throttleKey($request)
        );

        throw ValidationException::withMessages([
            $this->username() => [trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => self::computeLockoutMinutes($seconds),
            ])],
        ])->status(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * Clear the login locks for the given user credentials.
     */
    protected function clearLoginAttempts(Request $request): void
    {
        $this->limiter()->clear($this->throttleKey($request));
    }

    /**
     * Fire an event when a lockout occurs.
     */
    protected function fireLockoutEvent(Request $request): void
    {
        event(new Lockout($request));
    }

    /**
     * Get the throttle key for the given request.
     */
    protected function throttleKey(Request $request): string
    {
        return self::buildThrottleKey((string) $request->input($this->username()), (string) $request->ip());
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

    private static function throttleFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::THROTTLE_FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * The rate limiter key: `Str::transliterate(Str::lower($username.'|'.$ip))`.
     */
    public static function buildThrottleKey(string $username, string $ip): string
    {
        return self::usesRustFfi()
            ? self::buildThrottleKeyNative($username, $ip)
            : self::buildThrottleKeyPhp($username, $ip);
    }

    public static function buildThrottleKeyPhp(string $username, string $ip): string
    {
        return Str::transliterate(Str::lower($username.'|'.$ip));
    }

    /**
     * Rust handles keys that transliteration leaves unchanged; any other byte uses PHP.
     */
    public static function buildThrottleKeyNative(string $username, string $ip): string
    {
        $ffi = self::throttleFfi();
        $needed = \strlen($username) + 1 + \strlen($ip);
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, $needed, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::buildThrottleKeyPhp($username, $ip);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_throttle_key($username, \strlen($username), $ip, \strlen($ip), $scratch, $cap);

            return $written === $needed ? FFI::string($scratch, $needed) : self::buildThrottleKeyPhp($username, $ip);
        } catch (Throwable) {
            return self::buildThrottleKeyPhp($username, $ip);
        }
    }

    /**
     * Whole lockout minutes shown for the remaining seconds: `(int) ceil($seconds / 60)`.
     */
    public static function computeLockoutMinutes(int $seconds): int
    {
        return self::usesRustFfi()
            ? self::computeLockoutMinutesNative($seconds)
            : self::computeLockoutMinutesPhp($seconds);
    }

    public static function computeLockoutMinutesPhp(int $seconds): int
    {
        return (int) ceil($seconds / 60);
    }

    public static function computeLockoutMinutesNative(int $seconds): int
    {
        $ffi = self::throttleFfi();
        if ($ffi === null) {
            return self::computeLockoutMinutesPhp($seconds);
        }

        try {
            // @phpstan-ignore method.notFound
            $minutes = $ffi->nntmux_throttle_lockout_minutes($seconds);

            return \is_int($minutes) ? $minutes : self::computeLockoutMinutesPhp($seconds);
        } catch (Throwable) {
            return self::computeLockoutMinutesPhp($seconds);
        }
    }

    /**
     * Get the rate limiter instance.
     */
    protected function limiter(): RateLimiter
    {
        return app(RateLimiter::class);
    }

    /**
     * Get the maximum number of attempts to allow.
     */
    public function maxAttempts(): int
    {
        return isset($this->maxAttempts) ? $this->maxAttempts : 5;
    }

    /**
     * Get the number of minutes to throttle for.
     */
    public function decayMinutes(): int
    {
        return isset($this->decayMinutes) ? $this->decayMinutes : 1;
    }
}
