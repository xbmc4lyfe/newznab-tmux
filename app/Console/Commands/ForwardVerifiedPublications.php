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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Sends the verified candidates that this host's workers left in the publication outbox to the
 * primary, in batches. Several forwarders (numbered from 1) may run.
 *
 * A forwarder is a writer only while it works: it joins the shared worker group to claim, send and
 * settle entries and to renew their leases, and leaves it whenever the outbox is quiet, so an
 * idle forwarder never delays a barrier, reset or audit and costs no database queries. A drain,
 * an exclusive holder or a pause refuses it the group; it then leaves its entries alone (their
 * leases are fenced, and lapse if nobody renews them) and tries again every few seconds.
 */
class ForwardVerifiedPublications extends Command
{
    /** Seconds between lease renewal and orphan passes over the outbox. */
    private const float MAINTENANCE_SECONDS = 10.0;

    /** Microseconds a forwarder sleeps when the outbox has nothing due. */
    private const int IDLE_MICROSECONDS = 250_000;

    /** Seconds between attempts to rejoin the worker group after it was refused. */
    private const float REJOIN_SECONDS = 2.0;

    protected $signature = 'releases:forward-verified-publications {--slot=1 : Forwarder number, 1 to RELEASE_VERIFICATION_PUBLISH_FORWARDERS} {--max-seconds=0 : Stop taking work after this duration; zero uses RELEASE_VERIFICATION_PUBLISH_FORWARDER_SECONDS} {--passes=0 : Stop after this many loop passes (zero runs until the deadline)} {--diagnostics : Log a bounded run summary}';

    protected $description = 'Publish verified candidates handed off by this host\'s workers to the primary in batches';

    private PublicationOutbox $outbox;

    private ?CandidateRepository $repository = null;

    private ?PublicationForwarderDiagnostics $diagnostics = null;

    private int $slot = 1;

    private bool $terminate = false;

    /** Whether the worker group last refused this forwarder: workers should then publish for themselves. */
    private bool $blocked = false;

    public function handle(RemoteReleasePublisher $publisher): int
    {
        $this->slot = max(1, (int) $this->option('slot'));
        $this->repository = null;
        $maxSeconds = max(0, (int) $this->option('max-seconds'));
        if ($maxSeconds === 0) {
            $maxSeconds = max(1, (int) config('release_verification.publish_forwarder_seconds', 300));
        }
        $this->diagnostics = $this->option('diagnostics') ? new PublicationForwarderDiagnostics(hrtime(true), now()->utc()->toIso8601String(),
            $this->slot, $maxSeconds) : null;
        $this->terminate = false;
        // A container stop must not wait for a run's deadline: finish the request in hand, then leave.
        if (defined('SIGTERM')) {
            $this->trap([SIGTERM, SIGINT], function (): void {
                $this->terminate = true;
            });
        }
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
            $this->info('This forwarder is already running.');

            return self::SUCCESS;
        }
        $this->outbox = new PublicationOutbox;
        $this->blocked = false;
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
        $nextMaintenance = 0.0;
        $nextJoin = 0.0;
        $joined = false;
        for ($pass = 0; ; $pass++) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->terminate) {
                $this->diagnostics?->stop('terminated');

                return;
            }
            if ($maxPasses > 0 && $pass >= $maxPasses) {
                $this->diagnostics?->stop('passes');

                return;
            }
            if ((hrtime(true) - $started) / 1e9 >= $maxSeconds) {
                $this->diagnostics?->stop('deadline');

                return;
            }
            $now = microtime(true);
            if ($now >= $nextMaintenance) {
                $nextMaintenance = $now + self::MAINTENANCE_SECONDS;
                $this->maintain($locks, $beats, $joined);
            }
            // Moving due entries into this forwarder's in-flight folder touches only the filesystem.
            $batch = $this->outbox->claim($this->slot, $limit, $now);
            if ($batch === []) {
                if ($joined) {
                    $locks->leaveWorkers();
                    $joined = false;
                } elseif ($this->blocked && $now >= $nextJoin) {
                    // Nothing to do, but find out whether the group has reopened, so workers may hand off again.
                    $nextJoin = $now + self::REJOIN_SECONDS;
                    $this->blocked = ! $locks->joinWorkers();
                    $locks->leaveWorkers();
                }
                $this->mark();
                Sleep::usleep(self::IDLE_MICROSECONDS);

                continue;
            }
            if (! $joined) {
                if ($this->blocked && $now < $nextJoin) {
                    $this->mark();
                    Sleep::usleep(self::IDLE_MICROSECONDS);

                    continue;
                }
                $joined = $locks->joinWorkers();
                if (! $joined) {
                    // The entries stay in flight, untouched, until the group admits writers again.
                    $this->blocked = true;
                    $nextJoin = $now + self::REJOIN_SECONDS;
                    $this->diagnostics?->blocked();
                    $this->mark();
                    Sleep::usleep(self::IDLE_MICROSECONDS);

                    continue;
                }
            }
            if (! $locks->held() || ! $locks->forwarderHeld()) {
                $this->diagnostics?->stop('locks_lost');

                throw new RuntimeException('Publication forwarder locks were lost.');
            }
            if (! $locks->admitting()) {
                $locks->leaveWorkers();
                $joined = false;
                $this->blocked = true;
                $nextJoin = $now + self::REJOIN_SECONDS;
                $this->diagnostics?->blocked();
                $this->mark();

                continue;
            }
            $this->blocked = false;
            $this->forwardBatch($publisher, $batch, $now);
        }
    }

    /** Resolved when first needed: building it queries the database, which an idle forwarder never does. */
    private function repository(): CandidateRepository
    {
        return $this->repository ??= app(CandidateRepository::class);
    }

    /** Workers hand off only to a forwarder that can work, so a refused one stops advertising itself. */
    private function mark(): void
    {
        if ($this->blocked) {
            $this->outbox->clearAlive($this->slot);
        } else {
            $this->outbox->touchAlive($this->slot);
        }
    }

    /**
     * Housekeeping that needs the filesystem, and the database only when something is due: move
     * back the entries of forwarder numbers nobody runs, and renew leases every lease/3 seconds.
     *
     * @param  array<string, int>  $beats  Unix seconds of each entry's last renewal, by file name.
     */
    private function maintain(VerificationWorkerLocks $locks, array &$beats, bool &$joined): void
    {
        $this->outbox->sweepTemporaryFiles();
        foreach ($this->outbox->foreignSlots($this->slot) as $other) {
            // Only a number whose lock is free is orphaned; a running forwarder keeps its own folder.
            $locks->withForwarderNumber($other, function () use ($other): void {
                $this->diagnostics?->orphansAdopted($this->outbox->adopt($other));
            });
        }
        $interval = max(1.0, (int) config('release_verification.lease_seconds') / 3);
        $now = time();
        $seen = [];
        $due = [];
        foreach ([...$this->outbox->pending(), ...$this->outbox->inflight($this->slot)] as $entry) {
            $seen[$entry->name] = true;
            if ($now - max($beats[$entry->name] ?? 0, $entry->modifiedAt) >= $interval) {
                $due[] = $entry;
            }
        }
        $beats = array_intersect_key($beats, $seen);
        if ($due === []) {
            return;
        }
        $wasJoined = $joined;
        $joined = $joined || $locks->joinWorkers();
        if (! $joined) {
            // Refused (a barrier is running): renewing now would be a write it is waiting out. Try next time.
            return;
        }
        try {
            $this->renewLeases($due, $beats, $now);
        } finally {
            if (! $wasJoined) {
                $locks->leaveWorkers();
                $joined = false;
            }
        }
    }

    /**
     * A lost lease means a newer attempt owns the candidate, so the entry can never publish and is
     * dropped; any other failure is not proof the lease is gone, and the next pass tries again.
     *
     * @param  list<PublicationOutboxEntry>  $due
     * @param  array<string, int>  $beats
     */
    private function renewLeases(array $due, array &$beats, int $now): void
    {
        foreach ($due as $entry) {
            try {
                $this->repository()->heartbeat($entry->claim['id'], $entry->claim['lease_token']);
                $beats[$entry->name] = $now;
            } catch (LeaseLostException) {
                $this->outbox->remove($entry);
                unset($beats[$entry->name]);
                $this->diagnostics?->leaseLost();
            } catch (Throwable $error) {
                Log::warning('Publication outbox lease renewal failed.', ['error' => $error->getMessage()]);
            }
        }
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
            // An unreachable primary processed nothing, which is no failure of the entry.
            $this->diagnostics?->batch(count($live), $started, hrtime(true), true);
            $this->outbox->touchAlive($this->slot);
            foreach ($live as $entry) {
                $this->retry($entry, $error->getMessage(), counted: ! $error instanceof ConnectionException);
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
            case PublicationOutcome::TRANSPORT:
                // The primary was not reached; it never processed the item, so this is no failure of the entry.
                $this->retry($entry, $outcome->error ?? 'The primary could not be reached.', counted: false);

                return;
            case PublicationOutcome::RETRY:
                // The primary's batch budget ran out before it started this item.
                $this->retry($entry, $outcome->error ?? 'The primary did not start this item; sending it again.', counted: false, delay: 1.0);

                return;
            default:
                // stale, invalid, error, paused: as a worker's own failed publication finishes it, fenced by the lease token.
                $this->finishUnknown($entry, $outcome->error ?? 'Remote publication ended as '.$outcome->status.'.');
        }
    }

    /**
     * Send it again later. A failure the primary produced ($counted) uses up an attempt; one it never
     * processed does not, so overload or a flapping link cannot finish an entry the primary never saw.
     * Either way the age limit ends it, when it is finished unknown.
     */
    private function retry(PublicationOutboxEntry $entry, string $reason, bool $counted, ?float $delay = null): void
    {
        $now = microtime(true);
        if ($entry->olderThan(PublicationOutbox::maxAgeSeconds(), $now)
            || ($counted && $entry->attempts + 1 >= max(1, (int) config('release_verification.publish_forward_max_attempts', 5)))) {
            $this->diagnostics?->expired();
            $this->finishUnknown($entry, 'Remote publication did not succeed: '.$reason);

            return;
        }
        $this->diagnostics?->requeued();
        $this->outbox->requeue($entry->withFailure($now + ($delay ?? min(30.0, 2.0 ** ($entry->failures() + 1))), $counted));
    }

    /**
     * Finish the candidate unknown (fenced by its lease token, so a newer attempt is untouched)
     * and drop the entry. If the finish itself fails the entry is dropped anyway: the lease expires
     * and the candidate is verified again.
     */
    private function finishUnknown(PublicationOutboxEntry $entry, string $reason): void
    {
        try {
            $this->repository()->finish($entry->claim(), new VerificationResult('unknown', $reason));
        } catch (Throwable $error) {
            $this->diagnostics?->finishFailed();
            Log::warning('Publication outbox entry could not be finished; its lease will expire.', ['error' => $error->getMessage()]);
        }
        $this->outbox->remove($entry);
    }

    private function resolvedWithoutRelease(PublicationOutboxEntry $entry): bool
    {
        return DB::table('release_verification_candidates')->where('id', $entry->claim['id'])->where('version', $entry->claim['version'])
            ->where('manifest_hash', $entry->claim['manifest_hash'])->whereIn('status', [PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW])
            ->whereNull('release_id')->exists();
    }
}
