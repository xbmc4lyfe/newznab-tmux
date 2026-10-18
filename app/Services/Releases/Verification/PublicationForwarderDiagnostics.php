<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Throwable;

/**
 * Fixed-size facts about one publication forwarder run, without candidate metadata: how many
 * batches it sent, how large they were, what the primary answered for each item, and how long the
 * round trips took.
 */
final class PublicationForwarderDiagnostics
{
    public const int SCHEMA_VERSION = 1;

    /** Outcome labels counted per item; anything else is "other". */
    private const array OUTCOMES = ['published', 'filtered', 'metadata_review', 'stale', 'invalid', 'error', 'paused', 'retry', 'no_release'];

    private ?string $stopReason = null;

    private int $adopted = 0;

    private int $batches = 0;

    private int $items = 0;

    private int $smallestBatch = 0;

    private int $largestBatch = 0;

    private int $transportFailures = 0;

    private int $requeued = 0;

    private int $expired = 0;

    private int $leaseLost = 0;

    private int $drainFinished = 0;

    private int $finishFailures = 0;

    private float $roundTripMilliseconds = 0.0;

    private float $slowestRoundTripMilliseconds = 0.0;

    /** @var array<string, int> */
    private array $outcomes = [];

    private string $commandResult = 'exception';

    public function __construct(private readonly int $started, private readonly string $startedAt, private readonly int $slot,
        private readonly int $maxSeconds) {}

    public function stop(string $reason): void
    {
        $this->stopReason ??= in_array($reason, ['deadline', 'passes', 'draining', 'locks_lost', 'worker_unavailable', 'disabled', 'slot_unavailable', 'error'], true)
            ? $reason : 'error';
    }

    public function returned(int $exitCode): void
    {
        $this->commandResult = $exitCode === 0 ? 'success' : 'failure';
    }

    public function failed(): void
    {
        $this->commandResult = 'exception';
        $this->stop('error');
    }

    public function adopted(int $count): void
    {
        $this->adopted += $count;
    }

    /** One request: $size items, and the time its round trip took ($threw when it produced no per-item answers). */
    public function batch(int $size, int $started, int $finished, bool $threw): void
    {
        $this->batches++;
        $this->items += $size;
        $this->smallestBatch = $this->batches === 1 ? $size : min($this->smallestBatch, $size);
        $this->largestBatch = max($this->largestBatch, $size);
        $milliseconds = ($finished - $started) / 1_000_000.0;
        $this->roundTripMilliseconds += $milliseconds;
        $this->slowestRoundTripMilliseconds = max($this->slowestRoundTripMilliseconds, $milliseconds);
        if ($threw) {
            $this->transportFailures++;
        }
    }

    public function outcome(string $status): void
    {
        $label = in_array($status, self::OUTCOMES, true) ? $status : 'other';
        $this->outcomes[$label] = ($this->outcomes[$label] ?? 0) + 1;
    }

    public function requeued(): void
    {
        $this->requeued++;
    }

    public function expired(): void
    {
        $this->expired++;
    }

    public function leaseLost(): void
    {
        $this->leaseLost++;
    }

    public function drainFinished(): void
    {
        $this->drainFinished++;
    }

    public function finishFailed(): void
    {
        $this->finishFailures++;
    }

    /** @return array<string, mixed> */
    public function report(int $finished): array
    {
        $outcomes = [];
        foreach ([...self::OUTCOMES, 'other'] as $label) {
            $outcomes[$label] = $this->outcomes[$label] ?? 0;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => 'publication_forwarder',
            'started_at' => $this->startedAt,
            'slot' => $this->slot,
            'max_seconds' => $this->maxSeconds,
            'stop_reason' => $this->stopReason,
            'command_result' => $this->commandResult,
            'adopted' => $this->adopted,
            'batches_sent' => $this->batches,
            'items_sent' => $this->items,
            'items_per_batch' => ['min' => $this->smallestBatch, 'max' => $this->largestBatch,
                'mean' => $this->batches === 0 ? 0.0 : round($this->items / $this->batches, 2)],
            'outcomes' => $outcomes,
            'transport_failures' => $this->transportFailures,
            'requeued' => $this->requeued,
            'expired' => $this->expired,
            'lease_lost' => $this->leaseLost,
            'finished_for_drain' => $this->drainFinished,
            'finish_failures' => $this->finishFailures,
            'round_trip_ms' => ['total' => round($this->roundTripMilliseconds, 3), 'max' => round($this->slowestRoundTripMilliseconds, 3),
                'mean' => $this->batches === 0 ? 0.0 : round($this->roundTripMilliseconds / $this->batches, 3)],
            'duration_ms' => round(($finished - $this->started) / 1_000_000.0, 3),
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
