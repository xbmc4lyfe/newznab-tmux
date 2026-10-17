<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Throwable;

/**
 * Fixed-size facts about one natural command invocation, without candidate metadata.
 * Returned calls/results can overlap; a returned finish does not prove its CAS matched.
 */
final class VerificationRunDiagnostics
{
    private bool $workerAcquired = false;

    private ?int $loopStarted = null;

    private ?int $stopped = null;

    private ?string $stopReason = null;

    private bool $limitReached = false;

    private bool $deadlineReached = false;

    private int $claimCalls = 0;

    private int $claimsAdmitted = 0;

    /** @var array{complete: int, repaired: int, sampled: int, unknown: int, unrepairable: int, other: int} */
    private array $verdicts = ['complete' => 0, 'repaired' => 0, 'sampled' => 0, 'unknown' => 0, 'unrepairable' => 0, 'other' => 0];

    private int $publishReturned = 0;

    private int $finishReturned = 0;

    private int $filteredRecoveries = 0;

    private int $candidateErrors = 0;

    private bool $fatalError = false;

    private string $commandResult = 'exception';

    private ?int $exitCode = null;

    private string $maintenanceState = 'not_reached';

    private ?int $maintenanceStarted = null;

    private float $maintenanceMilliseconds = 0.0;

    public function __construct(private readonly int $started, private readonly string $startedAt,
        private readonly int $limit, private readonly int $maxSeconds, private readonly int $requestedSlot) {}

    public function workerAcquired(): void
    {
        $this->workerAcquired = true;
    }

    public function maintenanceSkipped(): void
    {
        $this->maintenanceState = 'not_acquired';
    }

    public function maintenanceStarted(int $started): void
    {
        $this->maintenanceStarted = $started;
        $this->maintenanceState = 'ran';
    }

    public function maintenanceFinished(int $finished, bool $successful): void
    {
        if ($this->maintenanceStarted !== null) {
            $this->maintenanceMilliseconds = ($finished - $this->maintenanceStarted) / 1_000_000.0;
            $this->maintenanceStarted = null;
        }
        $this->maintenanceState = $successful ? 'ran' : 'error';
    }

    public function loopStarted(int $started): void
    {
        $this->loopStarted = $started;
    }

    public function claimCalled(): void
    {
        $this->claimCalls++;
    }

    public function claimed(): void
    {
        $this->claimsAdmitted++;
    }

    public function verified(string $verdict): void
    {
        $this->verdicts[array_key_exists($verdict, $this->verdicts) ? $verdict : 'other']++;
    }

    public function published(): void
    {
        $this->publishReturned++;
    }

    public function finished(): void
    {
        $this->finishReturned++;
    }

    public function filtered(): void
    {
        $this->filteredRecoveries++;
    }

    public function candidateFailed(): void
    {
        $this->candidateErrors++;
    }

    /** Capture the admission decision before cleanup or a slow log sink can change threshold evidence. */
    public function stop(string $reason, int $stopped): void
    {
        if ($this->stopReason !== null) {
            return;
        }
        $this->stopReason = in_array($reason, ['limit', 'deadline', 'no_claim', 'draining', 'worker_unavailable',
            'initialization_unavailable', 'initialization_error', 'unsafe_scratch_root', 'error'], true) ? $reason : 'error';
        $this->stopped = $stopped;
        $this->limitReached = $this->claimsAdmitted >= $this->limit;
        $this->deadlineReached = $this->loopStarted !== null && $this->maxSeconds > 0
            && ($stopped - $this->loopStarted) / 1e9 >= $this->maxSeconds;
    }

    public function returned(int $exitCode): void
    {
        $this->exitCode = $exitCode;
        $this->commandResult = $exitCode === 0 ? 'success' : 'failure';
    }

    public function failed(int $finished): void
    {
        $this->fatalError = true;
        $this->commandResult = 'exception';
        $this->exitCode = null;
        if ($this->maintenanceStarted !== null) {
            $this->maintenanceFinished($finished, false);
        }
        $this->stop('error', $finished);
    }

    /** @return array<string, mixed> */
    public function report(int $finished): array
    {
        return [
            'schema_version' => 1,
            'started_at' => $this->startedAt,
            'requested_slot' => $this->requestedSlot,
            'limit' => $this->limit,
            'max_seconds' => $this->maxSeconds,
            'worker_acquired' => $this->workerAcquired,
            'loop_started' => $this->loopStarted !== null,
            'claim_calls' => $this->claimCalls,
            'claims_admitted' => $this->claimsAdmitted,
            'stop_reason' => $this->stopReason,
            'limit_reached' => $this->limitReached,
            'deadline_reached_at_stop' => $this->deadlineReached,
            'verifier_verdicts' => $this->verdicts,
            'publish_returned' => $this->publishReturned,
            'finish_returned' => $this->finishReturned,
            'filtered_recoveries' => $this->filteredRecoveries,
            'candidate_errors' => $this->candidateErrors,
            'fatal_error' => $this->fatalError,
            'command_result' => $this->commandResult,
            'exit_code' => $this->exitCode,
            'maintenance_state' => $this->maintenanceState,
            'durations_ms' => [
                'total' => ($finished - $this->started) / 1_000_000.0,
                'maintenance' => $this->maintenanceMilliseconds,
                'claim_loop' => $this->loopStarted === null ? null : (($this->stopped ?? $finished) - $this->loopStarted) / 1_000_000.0,
            ],
        ];
    }

    /** @param callable(array<string, mixed>): void $observer */
    public function observe(callable $observer): void
    {
        try {
            $observer($this->report(hrtime(true)));
        } catch (Throwable) {
            // Diagnostics cannot change processing outcomes or replace an original exception.
        }
    }
}
