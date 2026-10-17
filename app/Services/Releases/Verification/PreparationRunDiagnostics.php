<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/**
 * Bounded per-run counters; never retain candidate metadata or exception text.
 *
 * @phpstan-type ReadPhase 'file_read'|'gzip_decode'|'hash'|'json_decode'|'assessment'
 * @phpstan-type Distribution array{count: int, failures: int, total_ms: float, max_ms: float, latency_buckets: array<string, int>}
 */
final class PreparationRunDiagnostics
{
    /** @var array<string, float> */
    private const array LATENCY_BOUNDS = ['le_0_1' => 0.1, 'le_1' => 1.0, 'le_10' => 10.0,
        'le_100' => 100.0, 'le_1000' => 1000.0, 'le_10000' => 10000.0];

    /** @var array<ReadPhase, Distribution> */
    private array $phaseDistributions = [];

    /** @var array<string, array<string, int>> */
    private array $manifestSizes = [
        'compressed_bytes' => ['le_4096' => 0, 'le_65536' => 0, 'le_1048576' => 0, 'le_16777216' => 0, 'gt_16777216' => 0],
        'json_bytes' => ['le_4096' => 0, 'le_65536' => 0, 'le_1048576' => 0, 'le_16777216' => 0, 'gt_16777216' => 0],
        'files' => ['le_1' => 0, 'le_10' => 0, 'le_100' => 0, 'le_1000' => 0, 'gt_1000' => 0],
    ];

    /** @var array{ready: int, waiting_upload: int, unknown: int} */
    private array $assessments = ['ready' => 0, 'waiting_upload' => 0, 'unknown' => 0, 'header_rejected' => 0];

    /** @var array{ready: int, waiting_upload: int, unknown: int} */
    private array $updated = ['ready' => 0, 'waiting_upload' => 0, 'unknown' => 0, 'header_rejected' => 0];

    /** @var array{selection: float, read_assessment: float, update: float} */
    private array $durations = ['selection' => 0.0, 'read_assessment' => 0.0, 'update' => 0.0];

    private int $zeroRowUpdates = 0;

    private int $readAssessmentFailures = 0;

    private int $uninspectedSelected = 0;

    public function __construct(private readonly int $started)
    {
        foreach (['file_read', 'gzip_decode', 'hash', 'json_decode', 'assessment'] as $phase) {
            $this->phaseDistributions[$phase] = ['count' => 0, 'failures' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0,
                'latency_buckets' => [...array_fill_keys(array_keys(self::LATENCY_BOUNDS), 0), 'gt_10000' => 0]];
        }
    }

    /**
     * Wall spans include descheduling and cache effects; they are not disk or CPU time.
     *
     * @template T
     *
     * @param  ReadPhase  $phase
     * @param  callable(): T  $operation
     * @return T
     */
    public function measurePhase(string $phase, callable $operation): mixed
    {
        $started = hrtime(true);
        $failed = true;
        try {
            $result = $operation();
            // JSON false is a successful decode; manifest shape is checked by the reader.
            $failed = $result === false && $phase !== 'json_decode';

            return $result;
        } finally {
            try {
                $this->recordPhase($phase, (hrtime(true) - $started) / 1_000_000, $failed);
            } catch (\Throwable) {
                // Reporting cannot replace the operation's result, warning, or exception.
            }
        }
    }

    /** @param ReadPhase $phase */
    public function recordPhase(string $phase, float $milliseconds, bool $failed): void
    {
        if (! isset($this->phaseDistributions[$phase])) {
            return;
        }
        $distribution = &$this->phaseDistributions[$phase];
        $distribution['count']++;
        $distribution['failures'] += (int) $failed;
        $distribution['total_ms'] += $milliseconds;
        $distribution['max_ms'] = max($distribution['max_ms'], $milliseconds);
        foreach (self::LATENCY_BOUNDS as $bucket => $bound) {
            if ($milliseconds <= $bound) {
                $distribution['latency_buckets'][$bucket]++;

                return;
            }
        }
        $distribution['latency_buckets']['gt_10000']++;
    }

    /** @param 'compressed_bytes'|'json_bytes'|'files' $kind */
    public function recordSize(string $kind, int $size): void
    {
        if (! isset($this->manifestSizes[$kind])) {
            return;
        }
        try {
            foreach ($this->manifestSizes[$kind] as $bucket => $count) {
                if (str_starts_with($bucket, 'gt_') || $size <= (int) substr($bucket, 3)) {
                    $this->manifestSizes[$kind][$bucket]++;

                    return;
                }
            }
        } catch (\Throwable) {
            // Sizes come from already read data; diagnostics cannot fail the read.
        }
    }

    public function start(): int
    {
        return hrtime(true);
    }

    /** @param 'selection'|'read_assessment'|'update' $phase */
    public function finish(string $phase, ?int $started): void
    {
        if ($started !== null) {
            $this->durations[$phase] += (hrtime(true) - $started) / 1_000_000;
        }
    }

    public function selected(int $count): void
    {
        $this->uninspectedSelected += $count;
    }

    public function admitted(): void
    {
        $this->uninspectedSelected--;
    }

    /** @param 'ready'|'waiting_upload'|'unknown'|'header_rejected' $status */
    public function assessed(string $status): void
    {
        $this->assessments[$status]++;
    }

    public function readAssessmentFailed(): void
    {
        $this->readAssessmentFailures++;
    }

    /**
     * Affected rows describe the update result, not an outer transaction's commit.
     *
     * @param  'ready'|'waiting_upload'|'unknown'|'header_rejected'  $status
     */
    public function updated(string $status, int $affected, int $attempted = 1): void
    {
        $this->updated[$status] += $affected;
        $this->zeroRowUpdates += max(0, $attempted - $affected);
    }

    /** @return array<string, mixed> */
    public function report(int $limit, int $seconds, int $inspected, int $deadline, bool $exhausted, bool $error): array
    {
        $finished = hrtime(true);
        $limitReached = $inspected >= $limit;
        $deadlineReached = $finished >= $deadline;
        $queuesExhausted = $exhausted && $this->uninspectedSelected === 0;
        $stopReason = match (true) {
            $error => 'error',
            $limitReached && $deadlineReached => 'limit_and_deadline',
            $limitReached => 'limit',
            $deadlineReached => 'deadline',
            default => 'exhausted',
        };

        return [
            'limit' => $limit,
            'max_seconds' => $seconds,
            'inspected' => $inspected,
            'assessments' => $this->assessments,
            'updated' => $this->updated,
            'ready_promotions' => $this->updated['ready'],
            'zero_row_updates' => $this->zeroRowUpdates,
            'read_assessment_failures' => $this->readAssessmentFailures,
            'stop_reason' => $stopReason,
            'limit_reached' => $limitReached,
            'deadline_reached' => $deadlineReached,
            'queues_exhausted' => $queuesExhausted,
            'durations_ms' => [...$this->durations, 'total' => ($finished - $this->started) / 1_000_000],
            'phase_distributions' => $this->phaseDistributions,
            'manifest_sizes' => $this->manifestSizes,
        ];
    }
}
