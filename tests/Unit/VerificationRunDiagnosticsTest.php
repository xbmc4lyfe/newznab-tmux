<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\VerificationRunDiagnostics;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VerificationRunDiagnosticsTest extends TestCase
{
    public function test_diagnostics_capture_the_actual_limit_branch_when_both_thresholds_are_reached(): void
    {
        $diagnostics = new VerificationRunDiagnostics(0, '2026-10-15T20:00:00Z', 1, 1, 2);
        $diagnostics->workerAcquired();
        $diagnostics->maintenanceStarted(1_000_000_000);
        $diagnostics->maintenanceFinished(6_000_000_000, true);
        $diagnostics->loopStarted(6_000_000_000);
        $diagnostics->claimCalled();
        $diagnostics->claimed();
        $diagnostics->stop('limit', 7_100_000_000);
        $diagnostics->returned(0);

        $report = $diagnostics->report(20_000_000_000);

        self::assertSame('limit', $report['stop_reason']);
        self::assertTrue($report['limit_reached']);
        self::assertTrue($report['deadline_reached_at_stop']);
        self::assertSame(1, $report['claims_admitted']);
        self::assertSame(['total' => 20000.0, 'maintenance' => 5000.0, 'claim_loop' => 1100.0], $report['durations_ms']);
    }

    public function test_diagnostics_freeze_stop_evidence_before_observer_or_cleanup_time(): void
    {
        $diagnostics = new VerificationRunDiagnostics(0, '2026-10-15T20:00:00Z', 1000, 300, 0);
        $diagnostics->loopStarted(5_000_000_000);
        $diagnostics->claimCalled();
        $diagnostics->stop('no_claim', 6_000_000_000);
        $diagnostics->stop('limit', 400_000_000_000);
        $diagnostics->returned(0);

        $report = $diagnostics->report(500_000_000_000);

        self::assertSame('no_claim', $report['stop_reason']);
        self::assertFalse($report['limit_reached']);
        self::assertFalse($report['deadline_reached_at_stop']);
        self::assertSame(1, $report['claim_calls']);
        self::assertSame(0, $report['claims_admitted']);
        self::assertSame(1000.0, $report['durations_ms']['claim_loop']);
        self::assertArrayNotHasKey('queues_exhausted', $report);
    }

    public function test_diagnostics_keep_overlapping_publication_and_error_facts_without_private_values(): void
    {
        $diagnostics = new VerificationRunDiagnostics(0, '2026-10-15T20:00:00Z', 1, 0, 1);
        $diagnostics->loopStarted(0);
        $diagnostics->claimCalled();
        $diagnostics->claimed();
        $diagnostics->verified('sampled');
        $diagnostics->published();
        $diagnostics->handedOff();
        $diagnostics->handedOff();
        $diagnostics->outboxFallback();
        $diagnostics->outboxWaited(1_000_000_000, 1_250_000_000);
        $diagnostics->candidateFailed();
        $diagnostics->finished();
        $diagnostics->verified('private unrecognized verdict');
        $diagnostics->stop('limit', 1_000_000_000);
        $diagnostics->returned(0);

        $report = $diagnostics->report(2_000_000_000);

        self::assertSame(1, $report['publish_returned']);
        self::assertSame(4, $report['schema_version']);
        self::assertSame(2, $report['handed_off']);
        self::assertSame(1, $report['outbox_fallbacks']);
        self::assertSame(250.0, $report['outbox_wait_ms']);
        self::assertSame(1, $report['candidate_errors']);
        self::assertSame(1, $report['finish_returned']);
        self::assertSame(1, $report['verifier_verdicts']['sampled']);
        self::assertSame(1, $report['verifier_verdicts']['other']);
        self::assertFalse($report['deadline_reached_at_stop']);
        self::assertStringNotContainsString('private', json_encode($report, JSON_THROW_ON_ERROR));
        self::assertSame(['schema_version', 'started_at', 'requested_slot', 'limit', 'max_seconds',
            'worker_acquired', 'loop_started', 'claim_calls', 'claims_admitted', 'queued_returned', 'queued_lost', 'queued_return_failed', 'stop_reason',
            'limit_reached', 'deadline_reached_at_stop', 'verifier_verdicts', 'publish_returned',
            'handed_off', 'outbox_fallbacks', 'outbox_wait_ms', 'finish_returned', 'filtered_recoveries', 'candidate_errors', 'fatal_error',
            'command_result', 'exit_code', 'maintenance_state', 'durations_ms', 'phases'], array_keys($report));
        array_walk_recursive($report, static function (mixed $value): void {
            self::assertTrue($value === null || is_scalar($value));
        });
    }

    public function test_queued_claim_outcomes_reconcile_admission_without_changing_the_admission_limit(): void
    {
        $diagnostics = new VerificationRunDiagnostics(0, '2026-10-20T10:00:00Z', 4, 1, 1);
        for ($index = 0; $index < 4; $index++) {
            $diagnostics->claimed();
        }
        $diagnostics->verified('sampled');
        $diagnostics->queuedReturned();
        $diagnostics->queuedLost();
        $diagnostics->queuedReturnFailed();
        $diagnostics->stop('deadline', 2_000_000_000);
        $report = $diagnostics->report(3_000_000_000);
        self::assertSame(1, $report['queued_returned']);
        self::assertSame(1, $report['queued_lost']);
        self::assertSame(1, $report['queued_return_failed']);
        self::assertSame($report['claims_admitted'], array_sum($report['verifier_verdicts'])
            + $report['queued_returned'] + $report['queued_lost'] + $report['queued_return_failed']);
        self::assertTrue($report['limit_reached'], 'The limit bounds admissions, including returned work.');
        self::assertSame('deadline', $report['stop_reason']);
    }

    public function test_phase_timings_accumulate_only_known_phases(): void
    {
        $diagnostics = new VerificationRunDiagnostics(hrtime(true), '2026-10-17T16:00:00Z', 10, 300, 1);
        $next = $diagnostics->phase('claim', hrtime(true) - 2_000_000);
        $diagnostics->phase('claim', $next - 1_000_000);
        $diagnostics->phase('private-phase', hrtime(true) - 5_000_000);
        $diagnostics->phase('finish', null);

        $phases = $diagnostics->report(hrtime(true))['phases'];

        self::assertSame(['claim', 'prefetch', 'bookkeeping', 'manifest_read', 'verify_passed', 'verify_other', 'publish', 'finish', 'status_write'], array_keys($phases));
        self::assertSame(2, $phases['claim']['count']);
        self::assertGreaterThanOrEqual(3.0, $phases['claim']['ms']);
        self::assertSame(['ms' => 0.0, 'count' => 0], $phases['finish']);
    }

    public function test_diagnostics_observer_failure_cannot_hide_an_escaping_error(): void
    {
        $diagnostics = new VerificationRunDiagnostics(hrtime(true), '2026-10-15T20:00:00Z', 1, 300, 1);
        $original = new RuntimeException('Original private error');
        $observed = null;

        try {
            try {
                throw $original;
            } finally {
                $diagnostics->failed(hrtime(true));
                $diagnostics->observe(static function (array $report) use (&$observed): never {
                    $observed = $report;
                    throw new RuntimeException('Observer failed');
                });
            }
        } catch (RuntimeException $error) {
            self::assertSame($original, $error);
        }

        self::assertTrue($observed['fatal_error']);
        self::assertSame('exception', $observed['command_result']);
        self::assertNull($observed['exit_code']);
        self::assertSame('error', $observed['stop_reason']);
        self::assertFalse($observed['loop_started']);
        self::assertNull($observed['durations_ms']['claim_loop']);
    }
}
