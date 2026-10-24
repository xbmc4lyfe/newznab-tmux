<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Rust clients report through SQL; their work is independent of Horizon. */
final class DatabaseFingerprintCollector implements MetricsCollector
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_db_fingerprint_job_statuses(
        size_t *out_count,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_db_fingerprint_connection_providers(
        size_t *out_count,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMonitoringProbeComposerParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
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

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function __construct(private readonly BoundedMetricQuery $queries) {}

    public function collect(): iterable
    {
        if (config('nntmux.fingerprint_worker', 'rust') !== 'rust') {
            return;
        }
        $jobs = MetricFamily::gauge('nntmux_fingerprint_jobs', 'Database fingerprint jobs by state.');
        $counts = array_fill_keys(self::fingerprintJobStatuses(), 0);
        foreach ($this->queries->get(DB::table('release_fingerprint_jobs')->selectRaw('status, COUNT(*) AS total')->groupBy('status')) as $row) {
            $counts[$row->status] = (int) $row->total;
        }
        foreach ($counts as $status => $count) {
            $jobs->add($count, ['status' => $status]);
        }
        yield $jobs;
        $expired = DB::table('release_fingerprint_jobs')->where('status', 'processing')->where('lease_until', '<=', now('UTC'))->count();
        $live = DB::table('release_fingerprint_jobs')->where('status', 'processing')->where('lease_until', '>', now('UTC'))->count();
        yield MetricFamily::gauge('nntmux_fingerprint_live_claims', 'Rust fingerprint jobs with unexpired leases.')->add($live);
        $recent = $this->queries->get(DB::table('release_fingerprint_jobs')->where('status', 'complete')
            ->where('updated_at', '>=', now('UTC')->subMinutes(5))->selectRaw('COUNT(*) AS total'));
        yield MetricFamily::gauge('nntmux_fingerprint_completions_last_five_minutes', 'Releases whose Rust fingerprint job completed in the last five minutes.')
            ->add((int) $recent[0]->total);
        yield MetricFamily::gauge('nntmux_fingerprint_expired_claims', 'Rust fingerprint claims awaiting takeover.')->add($expired);
        $slots = MetricFamily::gauge('nntmux_fingerprint_connection_slots', 'Shared NNTP connection budget for Rust clients.');
        $used = MetricFamily::gauge('nntmux_fingerprint_connections_active', 'Currently leased Rust NNTP connection slots.');
        foreach (self::fingerprintConnectionProviders() as $provider) {
            $query = DB::table('release_fingerprint_connections')->where('provider', $provider);
            $slots->add((clone $query)->count(), ['provider' => $provider]);
            $used->add($query->where('lease_until', '>', now('UTC'))->count(), ['provider' => $provider]);
        }
        yield $slots;
        yield $used;
    }

    /**
     * @return list<string>
     */
    public static function fingerprintJobStatuses(): array
    {
        return self::$useRustFfi
            ? self::fingerprintJobStatusesNative()
            : self::fingerprintJobStatusesPhp();
    }

    /**
     * @return list<string>
     */
    public static function fingerprintJobStatusesNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::fingerprintJobStatusesPhp();
        }

        try {
            $countSlot = $ffi->new('size_t[1]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($outBuf === null) {
                return self::fingerprintJobStatusesPhp();
            }

            $written = self::callFfi($ffi, 'nntmux_db_fingerprint_job_statuses', $countSlot, $outBuf, $capacity);
            if (is_int($written) && $written > 0 && $written <= $capacity) {
                $parts = explode("\0", FFI::string($outBuf, $written));
                if (count($parts) === (int) $countSlot[0]) {
                    return $parts;
                }
            }
        } catch (Throwable) {
        }

        return self::fingerprintJobStatusesPhp();
    }

    /**
     * @return list<string>
     */
    public static function fingerprintJobStatusesPhp(): array
    {
        return ['pending', 'processing', 'complete', 'failed'];
    }

    /**
     * @return list<string>
     */
    public static function fingerprintConnectionProviders(): array
    {
        return self::$useRustFfi
            ? self::fingerprintConnectionProvidersNative()
            : self::fingerprintConnectionProvidersPhp();
    }

    /**
     * @return list<string>
     */
    public static function fingerprintConnectionProvidersNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::fingerprintConnectionProvidersPhp();
        }

        try {
            $countSlot = $ffi->new('size_t[1]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
            if ($outBuf === null) {
                return self::fingerprintConnectionProvidersPhp();
            }

            $written = self::callFfi($ffi, 'nntmux_db_fingerprint_connection_providers', $countSlot, $outBuf, $capacity);
            if (is_int($written) && $written > 0 && $written <= $capacity) {
                $parts = explode("\0", FFI::string($outBuf, $written));
                if (count($parts) === (int) $countSlot[0]) {
                    return $parts;
                }
            }
        } catch (Throwable) {
        }

        return self::fingerprintConnectionProvidersPhp();
    }

    /**
     * @return list<string>
     */
    public static function fingerprintConnectionProvidersPhp(): array
    {
        return ['primary', 'alternate'];
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
