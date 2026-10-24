<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\BoundedMetricQuery;
use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Support\RustPipelineLibrary;
use FFI;
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
}
