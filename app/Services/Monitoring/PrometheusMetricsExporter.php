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

    public const string W49_FFI_CDEF = 'int64_t nntmux_w49_prometheus_exporter_push_target(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Rust: ~2.2-3.1x mean, >=1.77x p99 in every strict run; see tests/Unit/RustMonitoringBoundedMetricFamilyExporterSnapshotJwtW49ParityTest.php. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    private static ?bool $w49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

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

    public static function usesW49RustFfi(): bool
    {
        return self::$w49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49.5 batch helper routed to Rust or PHP, then restores the routing. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w49RustFfi;
        self::$w49RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w49RustFfi = $previous;
        }
    }

    /**
     * Summarizes many Pushgateway targets and rejected-push bodies. Per `[base_url, job, body, max_chars]`
     * target: `[url_len, url_xxh64, body_len, body_xxh64]` for {@see formatPushgatewayUrlPhp()} and
     * {@see truncateErrorBodyPhp()}.
     *
     * @param  list<array{string, string, string, int}>  $targets
     * @return list<list<int>>
     */
    public static function pushTargetBatch(array $targets): array
    {
        return self::usesW49RustFfi() ? self::pushTargetBatchNative($targets) : self::pushTargetBatchPhp($targets);
    }

    /**
     * @param  list<array{string, string, string, int}>  $targets
     * @return list<list<int>>
     */
    public static function pushTargetBatchPhp(array $targets): array
    {
        $rows = [];

        foreach ($targets as [$baseUrl, $job, $body, $maxChars]) {
            $url = self::formatPushgatewayUrlPhp($baseUrl, $job);
            $kept = self::truncateErrorBodyPhp($body, $maxChars);
            /** @var array{1: int} $urlHash */
            $urlHash = unpack('J', hash('xxh64', $url, true));
            /** @var array{1: int} $bodyHash */
            $bodyHash = unpack('J', hash('xxh64', $kept, true));
            $rows[] = [\strlen($url), $urlHash[1], \strlen($kept), $bodyHash[1]];
        }

        return $rows;
    }

    /**
     * @param  list<array{string, string, string, int}>  $targets
     * @return list<list<int>>
     */
    public static function pushTargetBatchNative(array $targets): array
    {
        $count = \count($targets);
        if ($count === 0) {
            return [];
        }

        // NUL-delimited columns (base URLs, jobs, bodies); Rust declines a value holding NUL (the field count no longer matches) or a body PHP repairs.
        $data = implode("\0", array_column($targets, 0))."\0".implode("\0", array_column($targets, 1))."\0".implode("\0", array_column($targets, 2));
        $words = self::w49Words($count, $data, pack('P*', ...array_column($targets, 3)), 4 * $count);

        return $words === null ? self::pushTargetBatchPhp($targets) : array_chunk($words, 4);
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

    /**
     * Exactly $words output words from the Wave 49.5 Rust helper, or null to fall back to PHP.
     *
     * @return array<int, int>|null
     */
    private static function w49Words(int $count, string $data, string $ints, int $words): ?array
    {
        if (self::$w49Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w49Ffi = RustPipelineLibrary::resolveDomain('search_w49', self::W49_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w49Out = null;
            self::$w49OutCap = 0;
        }
        $ffi = self::$w49Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w49Out === null || $words > self::$w49OutCap) {
                $cap = max(1024, $words);
                self::$w49Out = $ffi->new('int64_t['.$cap.']');
                self::$w49OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_prometheus_exporter_push_target($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w49Out, self::$w49OutCap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w49Out, $words * 8));

            return \is_array($values) ? $values : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Enabled: the paired benchmark in the W60 parity test measures 4.39x common mean, 4.793x
     * common p99, 4.348x uncommon mean and 2.677x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_PROMETHEUS_EXPORTER_FFI_CDEF = 'ssize_t nntmux_w601_prometheus_exporter_scrape_plan_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W60_SCRAPE_WORDS = 7;

    public const int W60_SCRAPE_TAIL = 14;

    public const int W60_CLASS_SHIFT = 4;

    public const int W60_NAME_CONVENTIONAL = 128;

    public const int W60_NAMESPACED = 256;

    public const int W60_BUDGET_DEFAULTED = 512;

    public const int W60_CLAMPED = 1024;

    public const int W60_EMPTY_OUTPUT = 2048;

    public const int W60_OVER_BUDGET = 4096;

    public const int W60_SAMPLE_HEAVY = 8192;

    public const int W60_RETRY = 16384;

    public const int W60_MALFORMED = 32768;

    private const int W60_HOUR_US = 3_600_000_000;

    private const array W60_STATUSES = [
        'ok' => 1, 'success' => 1, '0' => 1,
        'error' => 2, 'failed' => 2, 'fail' => 2, 'exception' => 2, '1' => 2,
        'timeout' => 3, 'timed_out' => 3,
        'skipped' => 4, 'skip' => 4, 'disabled' => 4,
    ];

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 60 batch routed to Rust or PHP, then restores the routing. */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function scrapePlanBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::scrapePlanBatchNative($rows) : self::scrapePlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function scrapePlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W60_SCRAPE_WORDS + self::W60_SCRAPE_TAIL;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($buffer === null) {
            return self::scrapePlanBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound (Declared by W60_PROMETHEUS_EXPORTER_FFI_CDEF.)
            $written = $ffi->nntmux_w601_prometheus_exporter_scrape_plan_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::scrapePlanBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($result);
    }

    /**
     * Seven words per `collector\tstatus\tfamilies\tsamples\tduration_us\tbudget_us\tbytes`
     * row: the status (0 unknown, 1 ok, 2 error, 3 timeout, 4 skipped; trimmed,
     * case-insensitive), the latency class `<< W60_CLASS_SHIFT` (duration as a percentage of
     * the budget: 1 under 25, 2 under 75, 3 up to 100, 4 over) and the conventional
     * `*Collector` basename, namespaced, budget defaulted (5 s when <= 0), clamped (families
     * 0-1e9, samples and bytes 0-1e12, duration and budget up to an hour), empty ok output,
     * over-budget, sample-heavy (over 10000 samples per family) and retry (error or timeout)
     * flags; then the basename length, families, samples, samples per family, budget
     * percentage and bytes. A malformed row yields `[W60_MALFORMED, 0, 0, 0, 0, 0, 0]`. The
     * W60_SCRAPE_TAIL words count rows, ok, error, timeout, skipped, unknown and malformed rows,
     * then sum families, samples, bytes and duration, count over-budget rows, keep the largest
     * percentage and count conventional names.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function scrapePlanBatchPhp(array $rows): array
    {
        $words = [];
        $tail = array_fill(0, self::W60_SCRAPE_TAIL, 0);
        foreach ($rows as $row) {
            $tail[0]++;
            $f = explode("\t", $row);
            $ints = [];
            if (\count($f) === 7) {
                for ($i = 2; $i <= 6 && preg_match('/\A-?[0-9]{1,18}\z/', $f[$i]) === 1; $i++) {
                    $ints[] = (int) $f[$i];
                }
            }
            if (\count($ints) !== 5) {
                array_push($words, self::W60_MALFORMED, 0, 0, 0, 0, 0, 0);
                $tail[6]++;

                continue;
            }
            [$name, $status] = $f;
            [$rawFamilies, $rawSamples, $rawDuration, $rawBudget, $rawBytes] = $ints;
            $slash = strrpos($name, '\\');
            $base = $slash === false ? $name : substr($name, $slash + 1);
            $conventional = preg_match('/\A[A-Z][A-Za-z0-9]*Collector\z/', $base) === 1;
            $code = self::W60_STATUSES[strtolower(trim($status))] ?? 0;
            $families = max(0, min(1_000_000_000, $rawFamilies));
            $samples = max(0, min(1_000_000_000_000, $rawSamples));
            $duration = max(0, min(self::W60_HOUR_US, $rawDuration));
            $bytes = max(0, min(1_000_000_000_000, $rawBytes));
            $defaulted = $rawBudget <= 0;
            $budget = $defaulted ? 5_000_000 : min(self::W60_HOUR_US, $rawBudget);
            $clamped = $families !== $rawFamilies || $samples !== $rawSamples || $duration !== $rawDuration || $bytes !== $rawBytes || (! $defaulted && $budget !== $rawBudget);
            $pct = intdiv($duration * 100, $budget);
            $class = $pct < 25 ? 1 : ($pct < 75 ? 2 : ($pct <= 100 ? 3 : 4));
            $perFamily = $families > 0 ? intdiv($samples, $families) : 0;
            $flags = $code | $class << self::W60_CLASS_SHIFT
                | ($conventional ? self::W60_NAME_CONVENTIONAL : 0)
                | ($slash !== false ? self::W60_NAMESPACED : 0)
                | ($defaulted ? self::W60_BUDGET_DEFAULTED : 0)
                | ($clamped ? self::W60_CLAMPED : 0)
                | ($code === 1 && $families === 0 ? self::W60_EMPTY_OUTPUT : 0)
                | ($class === 4 ? self::W60_OVER_BUDGET : 0)
                | ($perFamily > 10000 ? self::W60_SAMPLE_HEAVY : 0)
                | ($code === 2 || $code === 3 ? self::W60_RETRY : 0);
            array_push($words, $flags, \strlen($base), $families, $samples, $perFamily, $pct, $bytes);
            $tail[$code === 0 ? 5 : $code]++;
            $tail[7] += $families;
            $tail[8] += $samples;
            $tail[9] += $bytes;
            $tail[10] += $duration;
            $tail[11] += $class === 4 ? 1 : 0;
            $tail[12] = max($tail[12], $pct);
            $tail[13] += $conventional ? 1 : 0;
        }

        return [...$words, ...$tail];
    }

    /**
     * Wave 60 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics so the
     * class gains no Wave 60 static properties.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('prometheus_metrics_exporter_w60', self::W60_PROMETHEUS_EXPORTER_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
