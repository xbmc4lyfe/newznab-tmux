<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Throwable;

/**
 * Queue depth, wait time and throughput from Horizon's Redis repositories.
 */
class HorizonCollector implements MetricsCollector
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_horizon_should_skip_queue(
        const char *queue_ptr,
        size_t queue_len,
        const char *worker_ptr,
        size_t worker_len
    );
    int32_t nntmux_horizon_any_master_running(
        const char *statuses_ptr,
        size_t statuses_len,
        size_t count
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

    public function __construct(
        private readonly WorkloadRepository $workload,
        private readonly MasterSupervisorRepository $masters,
        private readonly JobRepository $jobs,
        private readonly MetricsRepository $metrics,
    ) {}

    public function collect(): iterable
    {
        // Horizon only tracks Redis queues; with the sync/database drivers there is nothing to read.
        if (config('queue.default') !== 'redis') {
            return;
        }

        $length = MetricFamily::gauge('nntmux_queue_jobs', 'Jobs waiting in each Horizon queue.');
        $wait = MetricFamily::gauge('nntmux_queue_wait_seconds', 'Estimated seconds to clear each Horizon queue.');
        $processes = MetricFamily::gauge('nntmux_queue_processes', 'Horizon worker processes per queue.');

        /** @var array<int, array{name: string, length: int, wait: int|float, processes: int}> $queues */
        $queues = $this->workload->get();
        $fingerprintWorker = (string) config('nntmux.fingerprint_worker', 'rust');

        foreach ($queues as $queue) {
            if (self::shouldSkipHorizonQueue($queue['name'], $fingerprintWorker)) {
                continue;
            }
            $labels = ['queue' => $queue['name']];
            $length->add((int) $queue['length'], $labels);
            $wait->add((float) $queue['wait'], $labels);
            $processes->add((int) $queue['processes'], $labels);
        }

        if ($fingerprintWorker === 'rust') {
            $pending = app(BoundedMetricQuery::class)->get(DB::table('release_fingerprint_jobs')
                ->where('status', 'pending')->selectRaw('COUNT(*) AS total'));
            $length->add((int) $pending[0]->total, ['queue' => 'release-fingerprint']);
        }

        yield $length;
        yield $wait;
        yield $processes;

        $running = self::anyMasterSupervisorRunning(
            array_map(static fn (object $master): mixed => $master->status ?? null, $this->masters->all())
        );

        yield MetricFamily::gauge('nntmux_horizon_up', 'Whether a Horizon master supervisor is running.')->add($running ? 1 : 0);

        yield MetricFamily::gauge('nntmux_horizon_recently_failed_jobs', 'Jobs Horizon recorded as failed in its retention window.')
            ->add((int) $this->jobs->countRecentlyFailed());

        yield MetricFamily::gauge('nntmux_horizon_jobs_per_minute', 'Jobs processed per minute, as measured by Horizon.')
            ->add((float) $this->metrics->jobsProcessedPerMinute());
    }

    public static function shouldSkipHorizonQueue(string $queueName, string $fingerprintWorker): bool
    {
        return self::$useRustFfi
            ? self::shouldSkipHorizonQueueNative($queueName, $fingerprintWorker)
            : self::shouldSkipHorizonQueuePhp($queueName, $fingerprintWorker);
    }

    public static function shouldSkipHorizonQueueNative(string $queueName, string $fingerprintWorker): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::shouldSkipHorizonQueuePhp($queueName, $fingerprintWorker);
        }

        try {
            $rc = self::callFfi(
                $ffi,
                'nntmux_horizon_should_skip_queue',
                $queueName,
                strlen($queueName),
                $fingerprintWorker,
                strlen($fingerprintWorker)
            );
            if ($rc === 1) {
                return true;
            }
            if ($rc === 0) {
                return false;
            }
        } catch (Throwable) {
        }

        return self::shouldSkipHorizonQueuePhp($queueName, $fingerprintWorker);
    }

    public static function shouldSkipHorizonQueuePhp(string $queueName, string $fingerprintWorker): bool
    {
        return $queueName === 'release-fingerprint' && $fingerprintWorker === 'rust';
    }

    /**
     * @param  array<array-key, mixed>  $statuses
     */
    public static function anyMasterSupervisorRunning(array $statuses): bool
    {
        return self::$useRustFfi
            ? self::anyMasterSupervisorRunningNative($statuses)
            : self::anyMasterSupervisorRunningPhp($statuses);
    }

    /**
     * @param  array<array-key, mixed>  $statuses
     */
    public static function anyMasterSupervisorRunningNative(array $statuses): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::anyMasterSupervisorRunningPhp($statuses);
        }

        $packedItems = [];
        foreach ($statuses as $item) {
            $raw = is_object($item) ? ($item->status ?? null) : $item;
            if (is_string($raw) && ! str_contains($raw, "\0")) {
                $packedItems[] = $raw;
            } else {
                $packedItems[] = '';
            }
        }

        $count = count($packedItems);
        $packed = $count === 0 ? '' : implode("\0", $packedItems);

        try {
            $rc = self::callFfi(
                $ffi,
                'nntmux_horizon_any_master_running',
                $packed,
                strlen($packed),
                $count
            );
            if ($rc === 1) {
                return true;
            }
            if ($rc === 0) {
                return false;
            }
        } catch (Throwable) {
        }

        return self::anyMasterSupervisorRunningPhp($statuses);
    }

    /**
     * @param  array<array-key, mixed>  $statuses
     */
    public static function anyMasterSupervisorRunningPhp(array $statuses): bool
    {
        foreach ($statuses as $item) {
            $status = is_object($item) ? ($item->status ?? null) : $item;
            if ($status === 'running') {
                return true;
            }
        }

        return false;
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

    /**
     * Enabled: the paired benchmark in the W57 parity test measures 4.693x common mean, 4.512x
     * common p99, 3.606x uncommon mean and 3.342x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = 'ssize_t nntmux_w571_horizon_workload_batch(const char *rows, size_t rows_len, size_t count, const char *worker, size_t worker_len, int64_t *out, size_t out_cap);';

    public const int W57_WORKLOAD_WORDS = 5;

    public const int W57_WORKLOAD_IDLE = 0;

    public const int W57_WORKLOAD_HEALTHY = 1;

    public const int W57_WORKLOAD_BACKLOGGED = 2;

    public const int W57_WORKLOAD_STALLED = 3;

    public const int W57_WORKLOAD_INVALID = 7;

    public const int W57_WORKLOAD_SKIP = 8;

    public const int W57_WORKLOAD_FAILING = 16;

    public const int W57_WORKLOAD_NAME_VALID = 32;

    public const int W57_WORKLOAD_NAME_SHIFT = 8;

    public const int W57_WORKLOAD_BACKLOG_SECONDS = 60;

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 57 batch routed to Rust or PHP, then restores the routing. */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /**
     * Five words per `name\tlength\twait\tprocesses\tfailed` Horizon workload row: its status
     * (W57_WORKLOAD_INVALID unless exactly five fields with 1-18 digit counters, else IDLE
     * without jobs, STALLED without processes, BACKLOGGED from W57_WORKLOAD_BACKLOG_SECONDS of
     * wait, else HEALTHY) plus W57_WORKLOAD_SKIP (shouldSkipHorizonQueuePhp()),
     * W57_WORKLOAD_FAILING, W57_WORKLOAD_NAME_VALID and the name length
     * `<< W57_WORKLOAD_NAME_SHIFT`; then the four counters (-1 when invalid).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function workloadBatch(array $rows, string $fingerprintWorker): array
    {
        return self::usesW57RustFfi() ? self::workloadBatchNative($rows, $fingerprintWorker) : self::workloadBatchPhp($rows, $fingerprintWorker);
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function workloadBatchPhp(array $rows, string $fingerprintWorker): array
    {
        $words = [];
        foreach ($rows as $row) {
            $fields = explode("\t", $row);
            $name = $fields[0];
            $counters = [-1, -1, -1, -1];
            if (\count($fields) === 5) {
                for ($i = 1; $i < 5; $i++) {
                    $field = $fields[$i];
                    $counters[$i - 1] = \strlen($field) <= 18 && ctype_digit($field) ? (int) $field : -1;
                }
            }
            [$length, $wait, $processes, $failed] = $counters;
            $status = match (true) {
                \in_array(-1, $counters, true) => self::W57_WORKLOAD_INVALID,
                $length === 0 => self::W57_WORKLOAD_IDLE,
                $processes === 0 => self::W57_WORKLOAD_STALLED,
                $wait >= self::W57_WORKLOAD_BACKLOG_SECONDS => self::W57_WORKLOAD_BACKLOGGED,
                default => self::W57_WORKLOAD_HEALTHY,
            };
            $words[] = $status
                | (self::shouldSkipHorizonQueuePhp($name, $fingerprintWorker) ? self::W57_WORKLOAD_SKIP : 0)
                | ($failed > 0 ? self::W57_WORKLOAD_FAILING : 0)
                | (preg_match('/\A[A-Za-z0-9_.:-]+\z/', $name) === 1 ? self::W57_WORKLOAD_NAME_VALID : 0)
                | \strlen($name) << self::W57_WORKLOAD_NAME_SHIFT;
            array_push($words, $length, $wait, $processes, $failed);
        }

        return $words;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a row
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function workloadBatchNative(array $rows, string $fingerprintWorker): array
    {
        $count = \count($rows);
        $words = $count * self::W57_WORKLOAD_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w57RustFfiState('out', $words);
        if ($buffer === null) {
            return self::workloadBatchPhp($rows, $fingerprintWorker);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w571_horizon_workload_batch($joined, \strlen($joined), $count, $fingerprintWorker, \strlen($fingerprintWorker), $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $codes = $written === $count ? unpack('P*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($codes)) {
            return self::workloadBatchPhp($rows, $fingerprintWorker);
        }

        /** @var list<int> */
        return array_values($codes);
    }

    /**
     * Wave 57 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('horizon_collector_w57', self::W57_FFI_CDEF) ?? false;
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
