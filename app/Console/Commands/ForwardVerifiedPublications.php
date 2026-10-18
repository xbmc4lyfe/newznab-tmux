<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\LeaseLostException;
use App\Services\Releases\Verification\PublicationForwarderDiagnostics;
use App\Services\Releases\Verification\PublicationOutbox;
use App\Services\Releases\Verification\PublicationOutboxEntry;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\RemotePublicationService;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends the verified candidates that this host's workers left in the publication outbox to the
 * primary, in batches. Several forwarders (numbered from 1) may run; each holds the shared worker
 * lock, so a deployment barrier, reset or audit waits for it, and stops taking work as soon as a
 * pause or drain is visible.
 */
class ForwardVerifiedPublications extends Command
{
    /** Seconds between lease renewal passes over the outbox. */
    private const float RENEWAL_PASS_SECONDS = 10.0;

    /** Microseconds a forwarder sleeps when the outbox has nothing due. */
    private const int IDLE_MICROSECONDS = 250_000;

    protected $signature = 'releases:forward-verified-publications {--slot=1 : Forwarder number, 1 to RELEASE_VERIFICATION_PUBLISH_FORWARDERS} {--max-seconds=0 : Stop taking work after this duration; zero uses RELEASE_VERIFICATION_PUBLISH_FORWARDER_SECONDS} {--passes=0 : Stop after this many loop passes (zero runs until the deadline)} {--diagnostics : Log a bounded run summary}';

    protected $description = 'Publish verified candidates handed off by this host\'s workers to the primary in batches';

    private PublicationOutbox $outbox;

    private CandidateRepository $repository;

    private ?PublicationForwarderDiagnostics $diagnostics = null;

    private int $slot = 1;

    public function handle(CandidateRepository $repository, RemoteReleasePublisher $publisher): int
    {
        $this->slot = max(1, (int) $this->option('slot'));
        $this->repository = $repository;
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        if ($maxSeconds === 0) {
            $maxSeconds = max(1, (int) config('release_verification.publish_forwarder_seconds', 300));
        }
        $this->diagnostics = $this->option('diagnostics') ? new PublicationForwarderDiagnostics(hrtime(true), now()->utc()->toIso8601String(),
            $this->slot, $maxSeconds) : null;
        try {
            $exitCode = $this->forward($publisher, $maxSeconds);
            $this->diagnostics?->returned($exitCode);

            return $exitCode;
        } catch (Throwable $error) {
            $this->diagnostics?->failed();

            throw $error;
        } finally {
            $this->diagnostics?->observe(static function (array $report): void {
                Log::channel('daily')->info('Publication forwarder run summary.', $report);
            });
        }
    }

    private function forward(RemoteReleasePublisher $publisher, int $maxSeconds): int
    {
        if (! PublicationOutbox::enabled()) {
            $this->diagnostics?->stop('disabled');
            $this->info('Asynchronous publication is not enabled on this host.');

            return self::SUCCESS;
        }
        if ($this->slot > PublicationOutbox::forwarders()) {
            $this->diagnostics?->stop('slot_unavailable');
            $this->info('This forwarder number is above the configured count.');

            return self::SUCCESS;
        }
        $locks = new VerificationWorkerLocks;
        if (! $locks->startForwarder($this->slot)) {
            $this->diagnostics?->stop('worker_unavailable');
            $this->info('This forwarder is already running, or exclusive maintenance is running.');

            return self::SUCCESS;
        }
        $this->outbox = new PublicationOutbox;
        try {
            $this->outbox->prepare($this->slot);
            $this->outbox->touchAlive($this->slot);
            $this->diagnostics?->adopted(count($this->outbox->inflight($this->slot)));
            $this->loop($locks, $publisher, $maxSeconds);
        } finally {
            // Workers must not hand off to a forwarder that is gone.
            $this->outbox->clearAlive($this->slot);
            $locks->close();
        }

        return self::SUCCESS;
    }

    private function loop(VerificationWorkerLocks $locks, RemoteReleasePublisher $publisher, int $maxSeconds): void
    {
        $started = hrtime(true);
        $maxPasses = max(0, (int) $this->option('passes'));
        $limit = max(1, min(RemotePublicationService::BATCH_HARD_MAX_ITEMS, (int) config('release_verification.publish_forward_items', 16)));
        /** @var array<string, int> $beats */
        $beats = [];
        $nextRenewal = 0.0;
        for ($pass = 0; ; $pass++) {
            if ($maxPasses > 0 && $pass >= $maxPasses) {
                $this->diagnostics?->stop('passes');

                return;
            }
            if ((hrtime(true) - $started) / 1e9 >= $maxSeconds) {
                $this->diagnostics?->stop('deadline');

                return;
            }
            // Database locks end with their session; stop rather than send unprotected.
            if (! $locks->held()) {
                $this->diagnostics?->stop('locks_lost');

                throw new RuntimeException('Publication forwarder locks were lost.');
            }
            if (! $this->admitting($locks)) {
                $this->diagnostics?->stop('draining');
                $this->finishForDrain();

                return;
            }
            $this->outbox->touchAlive($this->slot);
            $now = microtime(true);
            if ($now >= $nextRenewal) {
                $this->renewLeases($beats);
                $this->outbox->sweepTemporaryFiles();
                $nextRenewal = $now + self::RENEWAL_PASS_SECONDS;
            }
            $batch = $this->outbox->claim($this->slot, $limit, $now);
            if ($batch === []) {
                usleep(self::IDLE_MICROSECONDS);

                continue;
            }
            $this->forwardBatch($publisher, $batch, $now);
        }
    }

    /** A pause or drain stops the forwarder, but only once it persists: an exclusive attempt that fails frees the gate at once. */
    private function admitting(VerificationWorkerLocks $locks): bool
    {
        if ($locks->admitting()) {
            return true;
        }
        for ($check = 0; $check < 3; $check++) {
            usleep(200_000);
            if ($locks->admitting()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep the lease of every pending entry and of this forwarder's in-flight entries fresh every
     * lease/3 seconds, each forwarder doing so independently (a renewal is idempotent). A lost lease
     * means a newer attempt owns the candidate, so the entry can never publish and is dropped.
     *
     * @param  array<string, int>  $beats  Unix seconds of each entry's last renewal, by file name.
     */
    private function renewLeases(array &$beats): void
    {
        $interval = max(1.0, (int) config('release_verification.lease_seconds') / 3);
        $now = time();
        $seen = [];
        foreach ([...$this->outbox->pending(), ...$this->outbox->inflight($this->slot)] as $entry) {
            $seen[$entry->name] = true;
            if ($now - max($beats[$entry->name] ?? 0, $entry->modifiedAt) < $interval) {
                continue;
            }
            try {
                $this->repository->heartbeat($entry->claim['id'], $entry->claim['lease_token']);
                $beats[$entry->name] = $now;
            } catch (LeaseLostException) {
                $this->outbox->remove($entry);
                unset($beats[$entry->name]);
                $this->diagnostics?->leaseLost();
            } catch (Throwable $error) {
                // Not proof the lease is gone; the next pass tries again.
                Log::warning('Publication outbox lease renewal failed.', ['error' => $error->getMessage()]);
            }
        }
        $beats = array_intersect_key($beats, $seen);
    }

    /** @param list<PublicationOutboxEntry> $batch */
    private function forwardBatch(RemoteReleasePublisher $publisher, array $batch, float $now): void
    {
        $live = [];
        foreach ($batch as $entry) {
            if ($entry->olderThan(PublicationOutbox::maxAgeSeconds(), $now)) {
                $this->diagnostics?->expired();
                $this->finishUnknown($entry, 'The primary did not accept this result before it expired; retry later.');
            } else {
                $live[] = $entry;
            }
        }
        if ($live === []) {
            return;
        }
        $this->outbox->touchAlive($this->slot);
        $started = hrtime(true);
        try {
            $outcomes = $publisher->publishMany(array_map(static fn (PublicationOutboxEntry $entry) => $entry->item(), $live));
            if (count($outcomes) !== count($live)) {
                throw new RuntimeException('The primary answered a different number of results than were sent.');
            }
        } catch (Throwable $error) {
            // No per-item answer: the primary treats a resent item idempotently, so all of them may be sent again.
            $this->diagnostics?->batch(count($live), $started, hrtime(true), true);
            $this->outbox->touchAlive($this->slot);
            foreach ($live as $entry) {
                $this->retry($entry, $error->getMessage(), min(30.0, 2.0 ** ($entry->attempts + 1)));
            }

            return;
        }
        $this->diagnostics?->batch(count($live), $started, hrtime(true), false);
        $this->outbox->touchAlive($this->slot);
        foreach ($live as $position => $entry) {
            $this->settle($entry, $outcomes[$position]);
        }
    }

    private function settle(PublicationOutboxEntry $entry, PublicationOutcome $outcome): void
    {
        $this->diagnostics?->outcome($outcome->status);
        switch ($outcome->status) {
            case PublicationOutcome::PUBLISHED:
            case PublicationOutcome::FILTERED:
            case PublicationOutcome::METADATA_REVIEW:
                $this->outbox->remove($entry);

                return;
            case PublicationOutcome::NO_RELEASE:
                // The single endpoint reports a filtered or reviewed candidate as a bare null; the row says which.
                if ($this->resolvedWithoutRelease($entry)) {
                    $this->outbox->remove($entry);
                } else {
                    $this->finishUnknown($entry, 'Publication did not produce a verified current release.');
                }

                return;
            case PublicationOutcome::RETRY:
                $this->retry($entry, $outcome->error ?? 'The primary did not start this item; sending it again.', 1.0);

                return;
            default:
                // stale, invalid, error, paused: as a worker's own failed publication finishes it, fenced by the lease token.
                $this->finishUnknown($entry, $outcome->error ?? 'Remote publication ended as '.$outcome->status.'.');
        }
    }

    /** Send it again later, unless it has failed too often or waited too long, when it is finished unknown. */
    private function retry(PublicationOutboxEntry $entry, string $reason, float $delaySeconds): void
    {
        $now = microtime(true);
        if ($entry->attempts + 1 >= max(1, (int) config('release_verification.publish_forward_max_attempts', 5))
            || $entry->olderThan(PublicationOutbox::maxAgeSeconds(), $now)) {
            $this->diagnostics?->expired();
            $this->finishUnknown($entry, 'Remote publication did not succeed: '.$reason);

            return;
        }
        $this->diagnostics?->requeued();
        $this->outbox->requeue($entry->withAttempt($now + $delaySeconds));
    }

    /**
     * Finish the candidate unknown (fenced by its lease token, so a newer attempt is untouched)
     * and drop the entry. If the finish itself fails the entry is dropped anyway: the lease expires
     * and the candidate is verified again.
     */
    private function finishUnknown(PublicationOutboxEntry $entry, string $reason): void
    {
        try {
            $this->repository->finish($entry->claim(), new VerificationResult('unknown', $reason));
        } catch (Throwable $error) {
            $this->diagnostics?->finishFailed();
            Log::warning('Publication outbox entry could not be finished; its lease will expire.', ['error' => $error->getMessage()]);
        }
        $this->outbox->remove($entry);
    }

    /** Matches the primary's answer to a worker that asks while verification is paused. */
    private function finishForDrain(): void
    {
        foreach ([...$this->outbox->pending(), ...$this->outbox->inflight($this->slot)] as $entry) {
            $this->diagnostics?->drainFinished();
            $this->finishUnknown($entry, RemotePublicationService::PAUSED_MESSAGE);
        }
    }

    private function resolvedWithoutRelease(PublicationOutboxEntry $entry): bool
    {
        return DB::table('release_verification_candidates')->where('id', $entry->claim['id'])->where('version', $entry->claim['version'])
            ->where('manifest_hash', $entry->claim['manifest_hash'])->whereIn('status', [PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW])
            ->whereNull('release_id')->exists();
    }
}
