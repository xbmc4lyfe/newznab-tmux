<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class MeasureApiPerformance
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_api_perf_elapsed_ms(int64_t started_at_ns, int64_t finished_at_ns, double *out_raw_ms, double *out_rounded_ms);
        ssize_t nntmux_api_perf_format_server_timing(double duration_ms, double query_ms, int64_t query_count, char *out_buf, size_t out_cap);
        int32_t nntmux_api_perf_classify_slow_request(double duration_ms, double query_ms, double slow_duration_threshold_ms, double slow_query_threshold_ms, double sample_rate, int64_t *out_sample_threshold);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $elapsedOut = null;

    private static ?CData $sampleThresholdOut = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function mediaFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('media', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

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

        $elapsed = $this->elapsedMilliseconds($startedAt, hrtime(true));
        $durationMs = $elapsed['raw_ms'];
        $queryMs = array_sum(array_map(static fn (array $query): float => (float) ($query['time'] ?? 0), $queries));
        $queryCount = count($queries);
        $metrics = [
            'route' => $request->route()?->getActionName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $elapsed['rounded_ms'],
            'query_count' => $queryCount,
            'query_ms' => round($queryMs, 2),
            'memory_delta_bytes' => max(0, memory_get_usage(true) - $memoryAtStart),
            'response_bytes' => strlen((string) $response->getContent()),
            'release_cache' => $request->attributes->get('nntmux.api_release_cache', 'unused'),
        ];

        Log::channel(config('logging.default'))->info('API performance', $metrics);
        $response->headers->set('Server-Timing', $this->formatServerTimingHeader($durationMs, $queryMs, $queryCount));

        return $response;
    }

    /**
     * @return array{raw_ms: float, rounded_ms: float}
     */
    public function elapsedMilliseconds(int $startedAtNs, int $finishedAtNs): array
    {
        return self::$useRustFfi
            ? $this->elapsedMillisecondsNative($startedAtNs, $finishedAtNs)
            : $this->elapsedMillisecondsPhp($startedAtNs, $finishedAtNs);
    }

    /**
     * @return array{raw_ms: float, rounded_ms: float}
     */
    public function elapsedMillisecondsPhp(int $startedAtNs, int $finishedAtNs): array
    {
        $rawMs = (float) (($finishedAtNs - $startedAtNs) / 1_000_000);

        return [
            'raw_ms' => $rawMs,
            'rounded_ms' => round($rawMs, 2),
        ];
    }

    /**
     * @return array{raw_ms: float, rounded_ms: float}
     */
    public function elapsedMillisecondsNative(int $startedAtNs, int $finishedAtNs): array
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->elapsedMillisecondsPhp($startedAtNs, $finishedAtNs);
        }

        $buf = self::$elapsedOut ??= $ffi->new('double[2]');

        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_api_perf_elapsed_ms(
            $startedAtNs,
            $finishedAtNs,
            FFI::addr($buf[0]),
            FFI::addr($buf[1]),
        );
        if ($rc !== 0) {
            return $this->elapsedMillisecondsPhp($startedAtNs, $finishedAtNs);
        }

        return [
            'raw_ms' => (float) $buf[0],
            'rounded_ms' => (float) $buf[1],
        ];
    }

    public function formatServerTimingHeader(float $durationMs, float $queryMs, int $queryCount): string
    {
        return self::$useRustFfi
            ? $this->formatServerTimingHeaderNative($durationMs, $queryMs, $queryCount)
            : $this->formatServerTimingHeaderPhp($durationMs, $queryMs, $queryCount);
    }

    public function formatServerTimingHeaderPhp(float $durationMs, float $queryMs, int $queryCount): string
    {
        return sprintf('app;dur=%.2f, db;dur=%.2f;desc="%d queries"', $durationMs, $queryMs, $queryCount);
    }

    public function formatServerTimingHeaderNative(float $durationMs, float $queryMs, int $queryCount): string
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->formatServerTimingHeaderPhp($durationMs, $queryMs, $queryCount);
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return $this->formatServerTimingHeaderPhp($durationMs, $queryMs, $queryCount);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_perf_format_server_timing($durationMs, $queryMs, $queryCount, $scratch, $cap);
        if (! \is_int($written) || $written === -1) {
            return $this->formatServerTimingHeaderPhp($durationMs, $queryMs, $queryCount);
        }
        if ($written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->formatServerTimingHeaderPhp($durationMs, $queryMs, $queryCount);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_api_perf_format_server_timing($durationMs, $queryMs, $queryCount, $scratch, $cap);
            if (! \is_int($written) || $written <= 0) {
                return $this->formatServerTimingHeaderPhp($durationMs, $queryMs, $queryCount);
            }
        }

        return $written <= 0
            ? $this->formatServerTimingHeaderPhp($durationMs, $queryMs, $queryCount)
            : FFI::string($scratch, $written);
    }

    public function classifySlowRequest(
        float $durationMs,
        float $queryMs,
        float $slowDurationThresholdMs = 500.0,
        float $slowQueryThresholdMs = 250.0,
    ): int {
        return self::$useRustFfi
            ? $this->classifySlowRequestNative($durationMs, $queryMs, $slowDurationThresholdMs, $slowQueryThresholdMs)
            : $this->classifySlowRequestPhp($durationMs, $queryMs, $slowDurationThresholdMs, $slowQueryThresholdMs);
    }

    public function classifySlowRequestPhp(
        float $durationMs,
        float $queryMs,
        float $slowDurationThresholdMs = 500.0,
        float $slowQueryThresholdMs = 250.0,
    ): int {
        if (! is_finite($durationMs)
            || ! is_finite($queryMs)
            || ! is_finite($slowDurationThresholdMs)
            || ! is_finite($slowQueryThresholdMs)) {
            return 0;
        }

        $slowApp = $slowDurationThresholdMs > 0.0 && $durationMs >= $slowDurationThresholdMs;
        $slowDb = $slowQueryThresholdMs > 0.0 && $queryMs >= $slowQueryThresholdMs;

        return ($slowApp ? 1 : 0) | ($slowDb ? 2 : 0);
    }

    public function classifySlowRequestNative(
        float $durationMs,
        float $queryMs,
        float $slowDurationThresholdMs = 500.0,
        float $slowQueryThresholdMs = 250.0,
    ): int {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->classifySlowRequestPhp($durationMs, $queryMs, $slowDurationThresholdMs, $slowQueryThresholdMs);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_api_perf_classify_slow_request(
            $durationMs,
            $queryMs,
            $slowDurationThresholdMs,
            $slowQueryThresholdMs,
            0.0,
            null,
        );

        return \is_int($res) && $res >= 0
            ? $res
            : $this->classifySlowRequestPhp($durationMs, $queryMs, $slowDurationThresholdMs, $slowQueryThresholdMs);
    }

    public function sampleThreshold(float $rate): int
    {
        return self::$useRustFfi
            ? $this->sampleThresholdNative($rate)
            : $this->sampleThresholdPhp($rate);
    }

    public function sampleThresholdPhp(float $rate): int
    {
        if (is_nan($rate)) {
            return 0;
        }

        $clamped = min(1.0, max(0.0, $rate));

        return (int) ($clamped * 1_000_000);
    }

    public function sampleThresholdNative(float $rate): int
    {
        $ffi = self::mediaFfi();
        if ($ffi === null) {
            return $this->sampleThresholdPhp($rate);
        }

        $buf = self::$sampleThresholdOut ??= $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_api_perf_classify_slow_request(
            0.0,
            0.0,
            500.0,
            250.0,
            $rate,
            FFI::addr($buf[0]),
        );
        if (! \is_int($rc) || $rc < 0) {
            return $this->sampleThresholdPhp($rate);
        }

        return (int) $buf[0];
    }

    private function sampled(): bool
    {
        $threshold = $this->sampleThreshold((float) config('nntmux.api.metrics_sample_rate', 0.01));

        return $threshold > 0 && random_int(1, 1_000_000) <= $threshold;
    }
}
