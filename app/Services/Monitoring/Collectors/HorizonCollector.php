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

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 7.15x, p99 5.11x; uncommon mean 6.01x, p99 4.43x); see RustMonitoringHorizonVerificationStatusTmuxGrafanaEmbedW68ParityTest. */
    public const bool W68_RUST_FFI_DEFAULT = true;

    public const string W68_HORIZON_COLLECTOR_FFI_CDEF = <<<'C'
        ssize_t nntmux_w683_horizon_collector_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 68.3 batch. */
    public const int W68_HORIZON_COLLECTOR_TRAILER = 23;

    public static function enableW68RustFfi(): void
    {
        self::w68RustFfiState('set', true);
    }

    public static function disableW68RustFfi(): void
    {
        self::w68RustFfiState('set', false);
    }

    /** Restores W68_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW68RustFfi(): void
    {
        self::w68RustFfiState('reset');
    }

    public static function usesW68RustFfi(): bool
    {
        $override = self::w68RustFfiState();

        return \is_bool($override) ? $override : self::W68_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the HorizonCollector Wave 68 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW68RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w68RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w68RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 68.3 Horizon supervisor/queue plan. Each item is `supervisor|status|queue|length|waitSeconds|processes|
     * throughputPerMinute`. Status is matched caselessly after trimming (0 empty, 1 running, 2 paused, 3 inactive,
     * 4 terminating, 5 other). The trimmed queue is 0 empty, 1 up to W68_HORIZON_QUEUE_MAX `[A-Za-z0-9_.:-]` bytes or 2
     * other; `release-fingerprint` is skipped (shouldSkipHorizonQueuePhp() with the Rust worker). Length, wait and
     * throughput (9 digits) and processes (4) are 0 empty, 1 digits or 2 other. The wait level is 0 ok, 1 from
     * W68_HORIZON_WAIT_WARN or 2 from W68_HORIZON_WAIT_CRITICAL seconds; the drain estimate is ceil(length * 60 /
     * throughput) seconds when throughput is positive. Decision 6 malformed, 0 ignored (no queue or skipped), 3 idle
     * (no jobs), 1 halted (supervisor not running), 2 stalled (no processes), 4 backlogged (warning wait or a drain of
     * W68_HORIZON_WAIT_CRITICAL seconds) or 5 healthy; decisions 1-5 emit the length, wait and process gauges plus
     * the throughput gauge when measured. Per item `status | nameState << 3 | skip << 5 | lengthState << 6 |
     * waitState << 8 | processesState << 10 | throughputState << 12 | waitLevel << 14 | decision << 16 | samples << 19
     * | processes (cap 1023) << 22 | drain (cap 2^20 - 1) << 32 | queueLength (cap 255) << 52`. Then the seven
     * decision, six status and three wait-level counts, the gauge samples, the length and process sums, the longest
     * wait and the 1-based first critical item (decisions 1-5), and the distinct lower-cased supervisors and distinct
     * valid queues.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68HorizonCollectorPlanBatch(array $items): array
    {
        return self::usesW68RustFfi() ? self::w68HorizonCollectorPlanBatchNative($items) : self::w68HorizonCollectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68HorizonCollectorPlanBatchNative(array $items): array
    {
        return self::w68Invoke($items, \count($items) + self::W68_HORIZON_COLLECTOR_TRAILER) ?? self::w68HorizonCollectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68HorizonCollectorPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0, 0, 0];
        $statuses = [0, 0, 0, 0, 0, 0];
        $levels = [0, 0, 0];
        $samplesTotal = $lengthSum = $processSum = $maxWait = $firstCritical = 0;
        $supervisors = $queues = [];
        $index = 0;
        foreach ($items as $item) {
            [$rawSupervisor, $rawStatus, $rawQueue, $rawLength, $rawWait, $rawProcesses, $rawThroughput] = explode('|', (string) $item, 7) + ['', '', '', '', '', '', ''];
            $supervisor = trim($rawSupervisor);
            $status = self::w68Keyword($rawStatus, self::W68_HORIZON_STATUSES);
            $queue = trim($rawQueue);
            $queueLength = \strlen($queue);
            $nameState = $queueLength === 0 ? 0 : ($queueLength <= self::W68_HORIZON_QUEUE_MAX && strspn($queue, self::W68_HORIZON_QUEUE_BYTES) === $queueLength ? 1 : 2);
            $skip = $queue === 'release-fingerprint';
            [$lengthState, $length] = self::w68Count($rawLength, 9);
            [$waitState, $wait] = self::w68Count($rawWait, 9);
            [$processesState, $processes] = self::w68Count($rawProcesses, 4);
            [$throughputState, $throughput] = self::w68Count($rawThroughput, 9);
            $level = $wait >= self::W68_HORIZON_WAIT_CRITICAL ? 2 : ($wait >= self::W68_HORIZON_WAIT_WARN ? 1 : 0);
            $drain = $throughputState === 1 && $throughput > 0 ? intdiv($length * 60 + $throughput - 1, $throughput) : 0;
            if ($status === 5 || $nameState === 2 || $lengthState === 2 || $waitState === 2 || $processesState === 2 || $throughputState === 2) {
                $decision = 6;
            } elseif ($nameState === 0 || $skip) {
                $decision = 0;
            } elseif ($length === 0) {
                $decision = 3;
            } elseif ($status !== 1) {
                $decision = 1;
            } elseif ($processes === 0) {
                $decision = 2;
            } elseif ($level >= 1 || $drain >= self::W68_HORIZON_WAIT_CRITICAL) {
                $decision = 4;
            } else {
                $decision = 5;
            }
            $emits = $decision >= 1 && $decision <= 5;
            $samples = $emits ? 3 + ($throughputState === 1 ? 1 : 0) : 0;
            $decisions[$decision]++;
            $statuses[$status]++;
            $levels[$level]++;
            $samplesTotal += $samples;
            if ($emits) {
                $lengthSum = min($lengthSum + $length, self::W68_TOTAL_CAP);
                $processSum = min($processSum + $processes, self::W68_TOTAL_CAP);
                $maxWait = max($maxWait, $wait);
                if ($level === 2 && $firstCritical === 0) {
                    $firstCritical = $index + 1;
                }
            }
            if ($supervisor !== '') {
                $supervisors[strtolower($supervisor)] = true;
            }
            if ($nameState === 1) {
                $queues[$queue] = true;
            }
            $out[] = $status | $nameState << 3 | ($skip ? 1 : 0) << 5 | $lengthState << 6 | $waitState << 8 | $processesState << 10 | $throughputState << 12
                | $level << 14 | $decision << 16 | $samples << 19 | min($processes, 1023) << 22 | min($drain, 1_048_575) << 32 | min($queueLength, 255) << 52;
            $index++;
        }
        array_push($out, ...$decisions);
        array_push($out, ...$statuses);
        array_push($out, ...$levels);
        array_push($out, $samplesTotal, $lengthSum, $processSum, $maxWait, $firstCritical, \count($supervisors), \count($queues));

        return $out;
    }

    /** Wave 68.3 supervisor statuses, in plan order. */
    private const array W68_HORIZON_STATUSES = ['running', 'paused', 'inactive', 'terminating'];

    /** Bytes allowed in a Wave 68.3 queue name. */
    private const string W68_HORIZON_QUEUE_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.:-';

    /** Longest valid Wave 68.3 queue name. */
    public const int W68_HORIZON_QUEUE_MAX = 64;

    /** Wait seconds that mark a Wave 68.3 queue as warning, then critical. */
    public const int W68_HORIZON_WAIT_WARN = 60;

    public const int W68_HORIZON_WAIT_CRITICAL = 300;

    /** Cap for Wave 68.3 running totals. */
    private const int W68_TOTAL_CAP = 1 << 62;

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w68Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * The trimmed field, lower-cased and matched against $words: 0 empty, 1-based index, or the word count + 1.
     *
     * @param  list<string>  $words
     */
    private static function w68Keyword(string $text, array $words): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $position = array_search(strtolower($text), $words, true);

        return $position === false ? \count($words) + 1 : $position + 1;
    }

    /**
     * Wave 68 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w68RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('monitoring_w683', self::W68_HORIZON_COLLECTOR_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w68RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w68RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 68.3 HorizonCollector export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w68Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w68RustFfiState('ffi');
            $out = self::w68RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w68RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W68_HORIZON_COLLECTOR_FFI_CDEF.)
            $written = $ffi->nntmux_w683_horizon_collector_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
