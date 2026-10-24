<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Services\Monitoring\Collectors\MetricsCollector;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects NNTmux application metrics and pushes them to the Prometheus Pushgateway.
 */
class PrometheusMetricsExporter
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_prometheus_exporter_pushgateway_url(const char *base_ptr, size_t base_len, const char *job_ptr, size_t job_len, char *out_buf, size_t out_cap);
        int32_t nntmux_prometheus_exporter_duration_seconds(int64_t started_at_ns, int64_t ended_at_ns, double *out_seconds);
        ssize_t nntmux_prometheus_exporter_truncate_body(const char *body_ptr, size_t body_len, size_t max_chars, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?CData $doubleSlot = null;

    private static ?FFI $doubleSlotOwner = null;

    /**
     * @param  iterable<MetricsCollector>  $collectors
     */
    public function __construct(
        private readonly iterable $collectors,
        private readonly TextFormatter $formatter,
    ) {}

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use ?? false;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Run every collector and render the result. A failing collector is
     * reported through nntmux_metrics_collector_errors instead of aborting.
     */
    public function render(): string
    {
        $startedAt = hrtime(true);
        $families = [];
        $errors = MetricFamily::gauge('nntmux_metrics_collector_errors', 'Whether a metrics collector failed during the last export.');

        foreach ($this->collectors as $collector) {
            $name = class_basename($collector);

            try {
                // Materialize first so a collector that throws midway contributes nothing partial.
                $collected = [...$collector->collect()];
                array_push($families, ...$collected);

                $errors->add(0, ['collector' => $name]);
            } catch (Throwable $exception) {
                $errors->add(1, ['collector' => $name]);
                Log::warning('Monitoring collector failed', ['collector' => $name, 'exception' => $exception->getMessage()]);
            }
        }

        $families[] = $errors;
        $families[] = MetricFamily::gauge('nntmux_metrics_export_duration_seconds', 'Time spent collecting NNTmux metrics.')
            ->add(self::computeExportDurationSeconds($startedAt, hrtime(true)));

        return $this->formatter->format($families);
    }

    /**
     * Replace the NNTmux metric group on the Pushgateway. PUT (not POST) drops
     * series that are no longer reported, e.g. a queue that was removed.
     *
     * @return bool Whether the Pushgateway accepted the metrics.
     */
    public function push(): bool
    {
        $url = self::formatPushgatewayUrl(
            (string) config('monitoring.pushgateway.url'),
            (string) config('monitoring.pushgateway.job')
        );

        try {
            $response = Http::timeout((int) config('monitoring.pushgateway.timeout', 5))
                ->withBody($this->render(), TextFormatter::CONTENT_TYPE)
                ->put($url);
        } catch (ConnectionException $exception) {
            Log::warning('Monitoring metrics push failed', ['url' => $url, 'exception' => $exception->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Monitoring metrics push rejected', [
                'url' => $url,
                'status' => $response->status(),
                'body' => self::truncateErrorBody($response->body(), 500),
            ]);

            return false;
        }

        return true;
    }

    public static function formatPushgatewayUrl(string $baseUrl, string $job): string
    {
        return self::$useRustFfi
            ? self::formatPushgatewayUrlNative($baseUrl, $job)
            : self::formatPushgatewayUrlPhp($baseUrl, $job);
    }

    public static function formatPushgatewayUrlPhp(string $baseUrl, string $job): string
    {
        return $baseUrl.'/metrics/job/'.rawurlencode($job);
    }

    public static function formatPushgatewayUrlNative(string $baseUrl, string $job): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::formatPushgatewayUrlPhp($baseUrl, $job);
        }

        $baseLen = \strlen($baseUrl);
        $jobLen = \strlen($job);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $baseLen + $jobLen * 3 + 32), $cap);
        if ($scratch === null) {
            return self::formatPushgatewayUrlPhp($baseUrl, $job);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_prometheus_exporter_pushgateway_url($baseUrl, $baseLen, $job, $jobLen, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::formatPushgatewayUrlPhp($baseUrl, $job);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_prometheus_exporter_pushgateway_url($baseUrl, $baseLen, $job, $jobLen, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::formatPushgatewayUrlPhp($baseUrl, $job);
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::formatPushgatewayUrlPhp($baseUrl, $job);
        }
    }

    public static function computeExportDurationSeconds(int $startedAtNs, int $endedAtNs): float
    {
        return self::$useRustFfi
            ? self::computeExportDurationSecondsNative($startedAtNs, $endedAtNs)
            : self::computeExportDurationSecondsPhp($startedAtNs, $endedAtNs);
    }

    public static function computeExportDurationSecondsPhp(int $startedAtNs, int $endedAtNs): float
    {
        return ($endedAtNs - $startedAtNs) / 1e9;
    }

    public static function computeExportDurationSecondsNative(int $startedAtNs, int $endedAtNs): float
    {
        $ffi = self::categorizeFfi();
        $slot = $ffi === null ? null : self::doubleSlot($ffi);
        if ($ffi === null || $slot === null) {
            return self::computeExportDurationSecondsPhp($startedAtNs, $endedAtNs);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_prometheus_exporter_duration_seconds($startedAtNs, $endedAtNs, $slot);
            if ($rc === 1) {
                /** @var float $val */
                $val = $slot[0];

                return $val;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::computeExportDurationSecondsPhp($startedAtNs, $endedAtNs);
    }

    public static function truncateErrorBody(string $body, int $maxChars = 500): string
    {
        return self::$useRustFfi
            ? self::truncateErrorBodyNative($body, $maxChars)
            : self::truncateErrorBodyPhp($body, $maxChars);
    }

    public static function truncateErrorBodyPhp(string $body, int $maxChars = 500): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        return mb_substr($body, 0, $maxChars);
    }

    public static function truncateErrorBodyNative(string $body, int $maxChars = 500): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::truncateErrorBodyPhp($body, $maxChars);
        }

        $len = \strlen($body);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, min($len, $maxChars * 4) + 32), $cap);
        if ($scratch === null) {
            return self::truncateErrorBodyPhp($body, $maxChars);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_prometheus_exporter_truncate_body($body, $len, $maxChars, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::truncateErrorBodyPhp($body, $maxChars);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_prometheus_exporter_truncate_body($body, $len, $maxChars, $scratch, $cap);
            }
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return self::truncateErrorBodyPhp($body, $maxChars);
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::truncateErrorBodyPhp($body, $maxChars);
        }
    }

    private static function doubleSlot(FFI $ffi): ?CData
    {
        if (self::$doubleSlotOwner !== $ffi || self::$doubleSlot === null) {
            try {
                self::$doubleSlot = $ffi->new('double[1]');
                self::$doubleSlotOwner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return self::$doubleSlot;
    }
}
