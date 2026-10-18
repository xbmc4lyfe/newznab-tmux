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
 * A forwarder is a writer only while it works, and it writes only inside the shared worker group:
 * every change to the outbox (claiming, settling, adopting, sweeping and even touching its alive
 * marker) and every lease renewal happens while it holds the group, which a drain, an exclusive
 * holder such as a reset, or a pause refuses it. Idle, it merely reads the outbox directory. A
 * refused forwarder writes nothing: it leaves its entries alone (their leases are fenced, and lapse
 * if nobody renews them), stops advertising itself, and tries to rejoin every few seconds.
 */
class ForwardVerifiedPublications extends Command
{
    /** Seconds between lease renewal and orphan passes over the outbox. */
    private const float MAINTENANCE_SECONDS = 10.0;

    /** Microseconds a forwarder sleeps when the outbox has nothing due. */
    private const int IDLE_MICROSECONDS = 250_000;

    /** Seconds between attempts to rejoin the worker group after it was refused. */
    private const float REJOIN_SECONDS = 2.0;

    /** Seconds between touches of the alive marker, idle or in the middle of a request. */
    private const int MARKER_SECONDS = 5;

    protected $signature = 'releases:forward-verified-publications {--slot=1 : Forwarder number, 1 to RELEASE_VERIFICATION_PUBLISH_FORWARDERS} {--max-seconds=0 : Stop taking work after this duration; zero uses RELEASE_VERIFICATION_PUBLISH_FORWARDER_SECONDS} {--passes=0 : Stop after this many loop passes (zero runs until the deadline)} {--diagnostics : Log a bounded run summary}';

    protected $description = 'Publish verified candidates handed off by this host\'s workers to the primary in batches';

    private PublicationOutbox $outbox;

    private VerificationWorkerLocks $locks;

    private ?CandidateRepository $repository = null;

    private ?PublicationForwarderDiagnostics $diagnostics = null;

    private int $slot = 1;

    private bool $terminate = false;

    /** Whether this process currently holds the shared worker group. */
    private bool $joined = false;

    /** Whether the worker group last refused this forwarder: workers should then publish for themselves. */
    private bool $blocked = false;

    private float $nextJoin = 0.0;

    /** Monotonic start of the run and its length, so a request loop stops when the run's time is up. */
    private int $runStarted = 0;

    private int $runSeconds = 0;

    /** Items per request; lowered when the primary advertises a smaller limit. */
    private int $limit = 16;

    /** @var array<string, int> Unix seconds of each entry's last lease renewal, by file name. */
    private array $beats = [];

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
        $this->locks = new VerificationWorkerLocks;
        if (! $this->locks->startForwarder($this->slot)) {
            $this->diagnostics?->stop('worker_unavailable');
            $this->info('This forwarder is already running.');

            return self::SUCCESS;
        }
        $this->outbox = new PublicationOutbox;
        $this->joined = false;
        $this->blocked = false;
        $this->nextJoin = 0.0;
        $this->beats = [];
        $this->limit = max(1, min(RemotePublicationService::BATCH_HARD_MAX_ITEMS, (int) config('release_verification.publish_forward_items', 16)));
        try {
            $this->diagnostics?->adopted(count($this->outbox->inflight($this->slot)));
            $this->loop($publisher, $maxSeconds);
        } finally {
            // Workers must not hand off to a forwarder that is gone. Removing the marker only deletes.
            $this->outbox->clearAlive($this->slot);
            $this->locks->close();
        }

        return self::SUCCESS;
    }

    private function loop(RemoteReleasePublisher $publisher, int $maxSeconds): void
    {
        $started = $this->runStarted = hrtime(true);
        $this->runSeconds = $maxSeconds;
        $maxPasses = max(0, (int) $this->option('passes'));
        $nextMaintenance = 0.0;
        $nextMark = 0.0;
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
            if ($this->blocked) {
                // Refused: no filesystem writes at all until the group admits writers again.
                if ($now < $this->nextJoin) {
                    Sleep::usleep(self::IDLE_MICROSECONDS);

                    continue;
                }
                $this->nextJoin = $now + self::REJOIN_SECONDS;
                if (! $this->enter()) {
                    Sleep::usleep(self::IDLE_MICROSECONDS);

                    continue;
                }
                $this->leave();
                $this->blocked = false;
                $nextMark = 0.0;
            }
            if ($now >= $nextMaintenance) {
                $nextMaintenance = $now + self::MAINTENANCE_SECONDS;
                $this->maintain();
            }
            if (! $this->blocked && $now >= $nextMark) {
                $nextMark = $now + self::MARKER_SECONDS;
                $this->advertise();
            }
            if ($this->blocked) {
                Sleep::usleep(self::IDLE_MICROSECONDS);

                continue;
            }
            $due = $this->outbox->hasDue($this->slot, $now);
            // Documents that cannot be decoded are removed even when no valid entry is waiting.
            if ($this->outbox->hasInvalid()) {
                $this->purgeInvalid();
            }
            if ($this->blocked || ! $due) {
                $this->leave();
                Sleep::usleep(self::IDLE_MICROSECONDS);

                continue;
            }
            if (! $this->enter()) {
                $this->refuse();
                Sleep::usleep(self::IDLE_MICROSECONDS);

                continue;
            }
            if (! $this->locks->held() || ! $this->locks->forwarderHeld()) {
                $this->diagnostics?->stop('locks_lost');

                throw new RuntimeException('Publication forwarder locks were lost.');
            }
            if (! $this->locks->admitting()) {
                $this->refuse();

                continue;
            }
            $batch = $this->outbox->claim($this->slot, $this->limit, $now);
            if ($batch === []) {
                $this->leave();
                Sleep::usleep(self::IDLE_MICROSECONDS);

                continue;
            }
            $this->forwardBatch($publisher, $batch, $now);
        }
    }

    /** Join the shared worker group, which a drain, an exclusive holder or a pause refuses. */
    private function enter(): bool
    {
        return $this->joined = $this->joined || $this->locks->joinWorkers();
    }

    private function leave(): void
    {
        if ($this->joined) {
            $this->locks->leaveWorkers();
            $this->joined = false;
        }
    }

    /** The group refused us: workers should publish for themselves, and we write nothing until it reopens. */
    private function refuse(): void
    {
        $this->leave();
        $this->blocked = true;
        $this->nextJoin = microtime(true) + self::REJOIN_SECONDS;
        $this->diagnostics?->blocked();
        $this->outbox->clearAlive($this->slot);
    }

    /**
     * Run $work while holding the worker group, joining it for the occasion. False, with the
     * forwarder marked refused, when the group would not admit it; $work then did not run.
     *
     * @param  callable(): void  $work
     */
    private function inGroup(callable $work): bool
    {
        $joinedHere = ! $this->joined;
        if (! $this->enter()) {
            $this->refuse();

            return false;
        }
        try {
            $work();
        } finally {
            if ($joinedHere) {
                $this->leave();
            }
        }

        return true;
    }

    /** Remove the undecodable documents the last reads met, inside the group. */
    private function purgeInvalid(): void
    {
        $this->inGroup(function (): void {
            $result = $this->outbox->purgeInvalid();
            for ($parked = 0; $parked < $result['parked']; $parked++) {
                $this->diagnostics?->quarantined();
            }
            if ($result['parked'] > 0) {
                Log::warning('Publication outbox documents could not be removed; they were set aside.', ['count' => $result['parked']]);
            }
        });
    }

    /** Create the outbox folders if a reset removed them, and touch the marker workers look for. */
    private function advertise(): void
    {
        $this->inGroup(function (): void {
            $this->outbox->prepare($this->slot);
            $this->outbox->touchAlive($this->slot);
        });
    }

    /**
     * Housekeeping that reads the filesystem and writes only inside the group, and only when
     * something is due: move back the entries of forwarder numbers nobody runs, renew leases every
     * lease/3 seconds, and sweep stray temporary files.
     */
    private function maintain(): void
    {
        $foreign = $this->outbox->foreignSlots($this->slot);
        $queued = [...$this->outbox->pending(), ...$this->outbox->inflight($this->slot)];
        // Forget the renewals of entries that have left the outbox.
        $this->beats = array_intersect_key($this->beats, array_flip(array_map(static fn (PublicationOutboxEntry $entry): string => $entry->name, $queued)));
        $due = $this->leasesDue($queued);
        $stale = $this->outbox->staleFiles();
        if ($foreign === [] && $due === [] && $stale === []) {
            return;
        }
        $this->inGroup(function () use ($foreign, $due, $stale): void {
            if ($stale !== []) {
                $this->outbox->sweepTemporaryFiles();
            }
            foreach ($foreign as $other) {
                // Only a number whose lock is free is orphaned; a running forwarder keeps its own folder.
                $this->locks->withForwarderNumber($other, function () use ($other): void {
                    $this->diagnostics?->orphansAdopted($this->outbox->adopt($other));
                });
            }
            $this->renewLeases($due);
        });
    }

    /**
     * The entries whose lease is older than a third of the lease, by their last renewal.
     *
     * @param  list<PublicationOutboxEntry>  $entries
     * @return list<PublicationOutboxEntry>
     */
    private function leasesDue(array $entries): array
    {
        $interval = max(1.0, (int) config('release_verification.lease_seconds') / 3);
        $now = now()->getTimestamp();
        $due = [];
        foreach ($entries as $entry) {
            // The schedule follows real renewals only; a requeue rewrites the file but renews nothing.
            if ($now - max($this->beats[$entry->name] ?? 0, $entry->heartbeatAt) >= $interval) {
                $due[] = $entry;
            }
        }

        return $due;
    }

    /**
     * A lost lease means a newer attempt owns the candidate, so the entry can never publish and is
     * dropped; any other failure is not proof the lease is gone, and the next pass tries again.
     *
     * @param  list<PublicationOutboxEntry>  $due
     */
    private function renewLeases(array $due): void
    {
        $now = now()->getTimestamp();
        foreach ($due as $entry) {
            try {
                $this->repository()->heartbeat($entry->claim['id'], $entry->claim['lease_token']);
                $this->beats[$entry->name] = $now;
            } catch (LeaseLostException) {
                $this->drop($entry);
                unset($this->beats[$entry->name]);
                $this->diagnostics?->leaseLost();
            } catch (Throwable $error) {
                Log::warning('Publication outbox lease renewal failed.', ['error' => $error->getMessage()]);
            }
        }
    }

    /** Resolved when first needed: building it queries the database, which an idle forwarder never does. */
    private function repository(): CandidateRepository
    {
        return $this->repository ??= app(CandidateRepository::class);
    }

    /**
     * Called by the publisher before each request and about once a second while one waits for the
     * primary, inside the group. Keeps the alive marker fresh through a request that takes up to the
     * publication timeout, and renews the leases of the entries still waiting their turn, which a
     * one-at-a-time fallback can leave unsent for longer than the lease lasts.
     *
     * @param  list<PublicationOutboxEntry>  $live
     */
    private function tick(array $live, int $completed): void
    {
        try {
            // A lost database session means a reset or another forwarder may own the storage now.
            if (! $this->ownsLocks()) {
                return;
            }
            $this->outbox->touchAliveIfStale($this->slot, self::MARKER_SECONDS);
            // Everything queued, not just this batch: a long fallback keeps the whole loop from running.
            // Items of this batch that already have an outcome need no renewal.
            $done = array_flip(array_map(static fn (PublicationOutboxEntry $entry): string => $entry->name, array_slice($live, 0, $completed)));
            $queued = array_filter([...$this->outbox->pending(), ...$this->outbox->inflight($this->slot)],
                static fn (PublicationOutboxEntry $entry): bool => ! isset($done[$entry->name]));
            $this->renewLeases($this->leasesDue(array_values($queued)));
        } catch (Throwable) {
            // Best effort; the request itself is what matters.
        }
    }

    /** Whether this process still holds the worker group and its forwarder lock (a database lock ends with its session). */
    private function ownsLocks(): bool
    {
        return $this->joined && $this->locks->held() && $this->locks->forwarderHeld();
    }

    /**
     * Whether no further request should start: SIGTERM or SIGINT arrived, the run's time is up (only
     * the request under way may finish, so the group is never held far past the lifetime), or the
     * locks were lost.
     */
    private function shouldStop(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->terminate || (hrtime(true) - $this->runStarted) / 1e9 >= $this->runSeconds || ! $this->ownsLocks();
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
        $failure = null;
        $outcomes = [];
        try {
            $outcomes = $publisher->publishMany(array_map(static fn (PublicationOutboxEntry $entry) => $entry->item(), $live),
                fn (int $completed) => $this->tick($live, $completed), fn (): bool => $this->shouldStop());
            if (count($outcomes) !== count($live)) {
                throw new RuntimeException('The primary answered a different number of results than were sent.');
            }
        } catch (Throwable $error) {
            $failure = $error;
        }
        // One entry per HTTP request, so a one-by-one fallback is not mistaken for one big batch.
        foreach ($publisher->requests() as $request) {
            $this->diagnostics?->batch($request['items'], $request['started'], $request['finished'], $request['failed']);
        }
        // The request may have outlived the database session that held the locks. Then nothing may be
        // written: the entries stay in flight, to be replayed (the primary treats a resend idempotently)
        // or left to lease expiry, once the storage has an owner again.
        if (! $this->ownsLocks()) {
            $this->diagnostics?->stop('locks_lost');

            throw new RuntimeException('Publication forwarder locks were lost.');
        }
        $this->outbox->touchAlive($this->slot);
        $this->adaptToPrimary($publisher);
        if ($failure !== null) {
            // No per-item answer: the primary treats a resent item idempotently, so all of them may be sent again.
            // An unreachable primary processed nothing, which is no failure of the entry.
            foreach ($live as $entry) {
                $this->retry($entry, $failure->getMessage(), counted: ! $failure instanceof ConnectionException);
            }

            return;
        }
        foreach ($live as $position => $entry) {
            $this->settle($entry, $outcomes[$position]);
        }
    }

    /** Claim no more items per request than the primary said it takes. */
    private function adaptToPrimary(RemoteReleasePublisher $publisher): void
    {
        $advertised = $publisher->advertisedMaxItems();
        if ($advertised !== null && $advertised < $this->limit) {
            $this->limit = max(1, $advertised);
            $this->diagnostics?->itemLimit($this->limit);
        }
    }

    private function settle(PublicationOutboxEntry $entry, PublicationOutcome $outcome): void
    {
        $this->diagnostics?->outcome($outcome->status);
        switch ($outcome->status) {
            case PublicationOutcome::PUBLISHED:
            case PublicationOutcome::FILTERED:
            case PublicationOutcome::METADATA_REVIEW:
                $this->drop($entry);

                return;
            case PublicationOutcome::NO_RELEASE:
                // The single endpoint reports a filtered or reviewed candidate as a bare null; the row says which.
                if ($this->resolvedWithoutRelease($entry)) {
                    $this->drop($entry);
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
        $this->outbox->requeue($entry->withFailure($now + ($delay ?? min(30.0, 2.0 ** ($entry->failures() + 1))), $counted,
            heartbeatAt: isset($this->beats[$entry->name]) ? (float) $this->beats[$entry->name] : null));
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
        $this->drop($entry);
    }

    /**
     * Drop an entry that is done with. If it cannot be removed it is parked, not forgotten: it is
     * logged once and counted, and never claimed again, so it cannot be sent in a loop.
     */
    private function drop(PublicationOutboxEntry $entry): void
    {
        if (! $this->outbox->remove($entry)) {
            $this->diagnostics?->quarantined();
            Log::warning('Publication outbox entry could not be removed; it was set aside.', ['entry' => $entry->name]);
        }
    }

    private function resolvedWithoutRelease(PublicationOutboxEntry $entry): bool
    {
        return DB::table('release_verification_candidates')->where('id', $entry->claim['id'])->where('version', $entry->claim['version'])
            ->where('manifest_hash', $entry->claim['manifest_hash'])->whereIn('status', [PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW])
            ->whereNull('release_id')->exists();
    }
}
