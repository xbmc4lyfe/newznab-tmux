<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Api\ApiUserResolver;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ThrottleApiRequestsByToken
{
    /**
     * Rust candidates for the key, limit and header helpers. They stay opt-in: none
     * beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchApiThrottleNoCacheFiltersParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_throttle_rate_limit_key(int64_t user_id, char *out, size_t cap);
        int64_t nntmux_throttle_max_attempts(int64_t rate_limit, int32_t is_null, int64_t default_limit);
        int64_t nntmux_throttle_remaining(int64_t max_attempts, int64_t attempts);
        int64_t nntmux_throttle_retry_after(int64_t available_in);
        intptr_t nntmux_throttle_error_header(int64_t code, const char *message, size_t message_len, char *out, size_t cap);
        C;

    public const int DEFAULT_RATE_LIMIT = 60;

    private const int DECAY_SECONDS = 60;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ApiUserResolver $userResolver,
    ) {}

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
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return $next($request);
        }

        $request->attributes->set('nntmux.api_user', $user);

        $maxAttempts = self::maxAttempts($user->rate_limit);
        $rateLimitKey = self::rateLimitKey($user->id);

        if ($this->limiter->tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return $this->buildTooManyRequestsResponse($rateLimitKey, $maxAttempts);
        }

        $this->limiter->hit($rateLimitKey, self::DECAY_SECONDS);

        $response = $next($request);
        $remainingAttempts = self::remainingAttempts($maxAttempts, $this->limiter->attempts($rateLimitKey));

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $remainingAttempts);

        return $response;
    }

    private function resolveUser(Request $request): ?User
    {
        $credentials = ApiUserResolver::resolveCredentials($request->input('api_token'), $request->input('apikey'));

        if ($credentials === null) {
            return null;
        }

        $user = $credentials['version'] === ApiUserResolver::VERSION_2
            ? $this->userResolver->v2($credentials['token'])
            : $this->userResolver->v1($credentials['token']);

        if ($user?->roles_id === UserRole::DISABLED->value) {
            return null;
        }

        return $user;
    }

    public static function rateLimitKey(int $userId): string
    {
        return self::$useRustFfi ? self::rateLimitKeyNative($userId) : self::rateLimitKeyPhp($userId);
    }

    public static function rateLimitKeyPhp(int $userId): string
    {
        return 'api-rate-limit:user:'.$userId;
    }

    public static function rateLimitKeyNative(int $userId): string
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, 64, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::rateLimitKeyPhp($userId);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_throttle_rate_limit_key($userId, $scratch, $cap);

        return \is_int($written) && $written > 0 ? FFI::string($scratch, $written) : self::rateLimitKeyPhp($userId);
    }

    /**
     * Requests allowed per decay window: the user's limit, or the default when unset/zero.
     */
    public static function maxAttempts(mixed $rateLimit): int
    {
        return self::$useRustFfi ? self::maxAttemptsNative($rateLimit) : self::maxAttemptsPhp($rateLimit);
    }

    public static function maxAttemptsPhp(mixed $rateLimit): int
    {
        return max(1, (int) ($rateLimit ?: self::DEFAULT_RATE_LIMIT));
    }

    public static function maxAttemptsNative(mixed $rateLimit): int
    {
        $ffi = self::searchFfi();
        // Only integers and null cross the ABI; PHP keeps its cast rules for anything else.
        if ($ffi === null || ($rateLimit !== null && ! \is_int($rateLimit))) {
            return self::maxAttemptsPhp($rateLimit);
        }

        // @phpstan-ignore method.notFound
        $limit = $ffi->nntmux_throttle_max_attempts($rateLimit ?? 0, $rateLimit === null ? 1 : 0, self::DEFAULT_RATE_LIMIT);

        return \is_int($limit) && $limit >= 1 ? $limit : self::maxAttemptsPhp($rateLimit);
    }

    /**
     * Attempts left in the window. Cache stores such as Redis return the counter as a
     * numeric string, so `$attempts` keeps PHP's arithmetic rules.
     */
    public static function remainingAttempts(int $maxAttempts, mixed $attempts): int|float
    {
        return self::$useRustFfi
            ? self::remainingAttemptsNative($maxAttempts, $attempts)
            : self::remainingAttemptsPhp($maxAttempts, $attempts);
    }

    public static function remainingAttemptsPhp(int $maxAttempts, mixed $attempts): int|float
    {
        return max(0, $maxAttempts - $attempts);
    }

    public static function remainingAttemptsNative(int $maxAttempts, mixed $attempts): int|float
    {
        $ffi = self::searchFfi();
        if (\is_string($attempts) && $attempts === (string) (int) $attempts) {
            $attempts = (int) $attempts;
        }
        if ($ffi === null || ! \is_int($attempts)) {
            return self::remainingAttemptsPhp($maxAttempts, $attempts);
        }

        // @phpstan-ignore method.notFound
        $remaining = $ffi->nntmux_throttle_remaining($maxAttempts, $attempts);

        return \is_int($remaining) && $remaining >= 0 ? $remaining : self::remainingAttemptsPhp($maxAttempts, $attempts);
    }

    public static function retryAfter(int|float $availableIn): int|float
    {
        return self::$useRustFfi ? self::retryAfterNative($availableIn) : self::retryAfterPhp($availableIn);
    }

    public static function retryAfterPhp(int|float $availableIn): int|float
    {
        return max(1, $availableIn);
    }

    public static function retryAfterNative(int|float $availableIn): int|float
    {
        $ffi = self::searchFfi();
        if ($ffi === null || ! \is_int($availableIn)) {
            return self::retryAfterPhp($availableIn);
        }

        // @phpstan-ignore method.notFound
        $retryAfter = $ffi->nntmux_throttle_retry_after($availableIn);

        return \is_int($retryAfter) && $retryAfter >= 1 ? $retryAfter : self::retryAfterPhp($availableIn);
    }

    /**
     * The `X-NNTmux` error header value.
     */
    public static function errorHeader(int $code, string $message): string
    {
        return self::$useRustFfi ? self::errorHeaderNative($code, $message) : self::errorHeaderPhp($code, $message);
    }

    public static function errorHeaderPhp(int $code, string $message): string
    {
        return 'API ERROR ['.$code.'] '.$message;
    }

    public static function errorHeaderNative(int $code, string $message): string
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, \strlen($message) + 48, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::errorHeaderPhp($code, $message);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_throttle_error_header($code, $message, \strlen($message), $scratch, $cap);

        return \is_int($written) && $written > 0 ? FFI::string($scratch, $written) : self::errorHeaderPhp($code, $message);
    }

    private function buildTooManyRequestsResponse(string $rateLimitKey, int $maxAttempts): JsonResponse
    {
        $retryAfter = self::retryAfter($this->limiter->availableIn($rateLimitKey));
        $error = apiErrorDetails(500, 'Request limit reached');

        return response()->json([
            'error' => $error['message'],
            'retry_after' => $retryAfter,
        ], $error['status'], [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $maxAttempts,
            'X-RateLimit-Remaining' => '0',
            'X-NNTmux' => self::errorHeader($error['code'], $error['message']),
        ]);
    }
}
