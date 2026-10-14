<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Throwable;

/** Bounded per-run counters; never retain candidate metadata or exception text. */
final class PreparationRunDiagnostics
{
    /** @var array{ready: int, waiting_upload: int, unknown: int} */
    private array $assessments = ['ready' => 0, 'waiting_upload' => 0, 'unknown' => 0];

    /** @var array{ready: int, waiting_upload: int, unknown: int} */
    private array $updated = ['ready' => 0, 'waiting_upload' => 0, 'unknown' => 0];

    /** @var array{selection: float, read_assessment: float, update: float} */
    private array $durations = ['selection' => 0.0, 'read_assessment' => 0.0, 'update' => 0.0];

    private int $zeroRowUpdates = 0;

    private int $readAssessmentFailures = 0;

    private int $uninspectedSelected = 0;

    /** @var array{file_read: float, gzip_decode: float, integrity: float, json_decode: float, inventory: float} */
    private array $readPhases = ['file_read' => 0.0, 'gzip_decode' => 0.0, 'integrity' => 0.0, 'json_decode' => 0.0, 'inventory' => 0.0];

    private readonly ?float $cpuStarted;

    public function __construct(private readonly int $started)
    {
        $this->cpuStarted = $this->cpuMilliseconds();
    }

    public function observeReadPhase(string $phase, float $milliseconds): void
    {
        if (array_key_exists($phase, $this->readPhases) && is_finite($milliseconds) && $milliseconds >= 0) {
            $this->readPhases[$phase] += $milliseconds;
        }
    }

    private function cpuMilliseconds(): ?float
    {
        try {
            if (! function_exists('getrusage')) {
                return null;
            }
            $usage = getrusage();
            if ($usage === false) {
                return null;
            }

            return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1000.0
                + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1000.0;
        } catch (Throwable) {
            return null;
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

    /** @param 'ready'|'waiting_upload'|'unknown' $status */
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
     * @param  'ready'|'waiting_upload'|'unknown'  $status
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
        $cpuFinished = $this->cpuMilliseconds();
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
            'read_phases_ms' => $this->readPhases,
            'cpu_milliseconds' => $this->cpuStarted === null || $cpuFinished === null ? null : max(0.0, $cpuFinished - $this->cpuStarted),
        ];
    }
}
