<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class MeasureApiPerformance
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/*', 'api/v2/*') || ! $this->sampled()) {
            return $next($request);
        }

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $startedAt = hrtime(true);
        $memoryAtStart = memory_get_usage(true);

        try {
            $response = $next($request);
        } finally {
            $queries = $connection->getQueryLog();
            $connection->disableQueryLog();
        }

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        $queryMs = array_sum(array_map(static fn (array $query): float => (float) ($query['time'] ?? 0), $queries));
        $metrics = [
            'route' => $request->route()?->getActionName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round($durationMs, 2),
            'query_count' => count($queries),
            'query_ms' => round($queryMs, 2),
            'memory_delta_bytes' => max(0, memory_get_usage(true) - $memoryAtStart),
            'response_bytes' => strlen((string) $response->getContent()),
            'release_cache' => $request->attributes->get('nntmux.api_release_cache', 'unused'),
        ];

        Log::channel(config('logging.default'))->info('API performance', $metrics);
        $response->headers->set('Server-Timing', sprintf('app;dur=%.2f, db;dur=%.2f;desc="%d queries"', $durationMs, $queryMs, count($queries)));

        return $response;
    }

    private function sampled(): bool
    {
        $rate = min(1.0, max(0.0, (float) config('nntmux.api.metrics_sample_rate', 0.01)));

        return $rate > 0.0 && random_int(1, 1_000_000) <= (int) ($rate * 1_000_000);
    }
}
