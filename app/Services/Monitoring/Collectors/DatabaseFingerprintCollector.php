<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** Wave 54.2 paired benchmarks decide this default; see RustMcpProcessProbeVerificationPlannerRunnerDbFpCollectorW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    private const string W54_FFI_CDEF = <<<'C'
        intptr_t nntmux_w542_db_fingerprint_status_plan_batch(const char *statuses, size_t statuses_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 54 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w54', self::W54_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w54RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planJobStatusBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /**
     * Batch job status plan, as collect() fills `array_fill_keys(fingerprintJobStatuses(), 0)`
     * from the grouped rows. Two words per row: `flags | slot << 8 | crc32(status) << 32` and
     * the slot's final count. Flags: 1 known status, 2 status repeated by an earlier row, 4
     * empty status, 8 negative total. The final pending, processing, complete and failed counts,
     * the slots, the nonzero slots, the largest final count and the XXH64 digest of all earlier
     * words follow.
     *
     * @param  list<string>  $statuses
     * @param  list<int>  $totals
     * @return list<int>
     */
    public static function planJobStatusBatch(array $statuses, array $totals): array
    {
        return self::usesW54RustFfi()
            ? self::planJobStatusBatchNative($statuses, $totals)
            : self::planJobStatusBatchPhp($statuses, $totals);
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<int>  $totals
     * @return list<int>
     */
    public static function planJobStatusBatchPhp(array $statuses, array $totals): array
    {
        $totals = array_values($totals);
        $counts = array_fill_keys(self::fingerprintJobStatusesPhp(), 0);
        $slots = array_flip(array_keys($counts));
        $seen = $rows = [];
        foreach (array_values($statuses) as $i => $status) {
            $total = $totals[$i] ?? 0;
            $slots[$status] ??= \count($slots);
            $counts[$status] = $total;
            $rows[] = [($slots[$status] < 4 ? 1 : 0) | (isset($seen[$status]) ? 2 : 0) | ($status === '' ? 4 : 0) | ($total < 0 ? 8 : 0)
                | $slots[$status] << 8 | crc32($status) << 32, $status];
            $seen[$status] = true;
        }
        $out = [];
        foreach ($rows as [$word, $status]) {
            $out[] = $word;
            $out[] = $counts[$status];
        }
        $values = array_values($counts);
        array_push($out, $values[0], $values[1], $values[2], $values[3], \count($values), \count(array_filter($values)), max($values));
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * are not lists, or a status holds a NUL byte.
     *
     * @param  list<string>  $statuses
     * @param  list<int>  $totals
     * @return list<int>
     */
    public static function planJobStatusBatchNative(array $statuses, array $totals): array
    {
        $count = \count($statuses);
        $words = 2 * $count + 8;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w54RustFfiState('out', $words);
        if ($state === null || \count($totals) !== $count || ! array_is_list($statuses) || ! array_is_list($totals)) {
            return self::planJobStatusBatchPhp($statuses, $totals);
        }
        $joined = implode("\0", $statuses);
        $ints = pack('P*', ...$totals);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w542_db_fingerprint_status_plan_batch($joined, \strlen($joined), $ints, \strlen($ints), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planJobStatusBatchPhp($statuses, $totals);
    }
}
