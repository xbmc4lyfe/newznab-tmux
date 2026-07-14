<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Api\ApiUserResolver;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ThrottleApiRequestsByToken
{
    private const int DEFAULT_RATE_LIMIT = 60;

    private const int DECAY_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly ApiUserResolver $userResolver,
    ) {}

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

        $maxAttempts = max(1, (int) ($user->rate_limit ?: self::DEFAULT_RATE_LIMIT));
        $rateLimitKey = $this->rateLimitKey($user->id);

        if ($this->limiter->tooManyAttempts($rateLimitKey, $maxAttempts)) {
            return $this->buildTooManyRequestsResponse($rateLimitKey, $maxAttempts);
        }

        $this->limiter->hit($rateLimitKey, self::DECAY_SECONDS);

        $response = $next($request);
        $remainingAttempts = max(0, $maxAttempts - $this->limiter->attempts($rateLimitKey));

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $remainingAttempts);

        return $response;
    }

    private function resolveUser(Request $request): ?User
    {
        $apiToken = $request->input('api_token') ?? $request->input('apikey');

        if (! is_string($apiToken) || $apiToken === '') {
            return null;
        }

        $user = $request->filled('api_token')
            ? $this->userResolver->v2($apiToken)
            : $this->userResolver->v1($apiToken);

        if ($user?->roles_id === UserRole::DISABLED->value) {
            return null;
        }

        return $user;
    }

    private function rateLimitKey(int $userId): string
    {
        return 'api-rate-limit:user:'.$userId;
    }

    private function buildTooManyRequestsResponse(string $rateLimitKey, int $maxAttempts): JsonResponse
    {
        $retryAfter = max(1, $this->limiter->availableIn($rateLimitKey));
        $error = apiErrorDetails(500, 'Request limit reached');

        return response()->json([
            'error' => $error['message'],
            'retry_after' => $retryAfter,
        ], $error['status'], [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $maxAttempts,
            'X-RateLimit-Remaining' => '0',
            'X-NNTmux' => 'API ERROR ['.$error['code'].'] '.$error['message'],
        ]);
    }
}
