<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Durable reservations count retained files and in-flight growth across each host's workers. */
final class ScratchBudget
{
    public function __construct(private readonly ?int $entryId = null) {}

    /** Bootstrap old physical scratch once, while every verifier and audit is excluded. */
    public function initialize(): bool
    {
        if (! Schema::hasTable('release_verification_scratch_budget')) {
            throw new RuntimeException('Shared scratch budget migration must run before verification workers.');
        }
        $host = ScratchInventory::host();
        $state = DB::table('release_verification_scratch_budget')->where('host', $host)->first();
        if ($state === null && $host !== '') {
            // A joining host creates its row in join(), under a worker slot and the deployment fence.
            return true;
        }
        if ($state === null) {
            throw new RuntimeException('Shared scratch budget state is unavailable.');
        }
        if ($state->initialized) {
            return true;
        }
        $exclusive = new VerificationWorkerLocks;
        if (! $exclusive->tryExclusive()) {
            return false;
        }
        try {
            $root = (string) config('release_verification.storage_path').'/scratch';
            $total = $this->directoryBytes($root);
            DB::transaction(function () use ($total, $root, $host): void {
                $state = DB::table('release_verification_scratch_budget')->where('host', $host)->lockForUpdate()->sole();
                if ($state->initialized) {
                    return;
                }
                foreach (DB::table('release_verification_scratch')->where('host', $host)->orderBy('id')->cursor() as $entry) {
                    $directory = $root.'/'.app(ScratchInventory::class)->directoryName($entry);
                    $bytes = is_link($directory) ? 0 : $this->directoryBytes($directory);
                    DB::table('release_verification_scratch')->where('id', $entry->id)->update(['reserved_bytes' => $bytes]);
                }
                DB::table('release_verification_scratch_budget')->where('host', $host)->update(['initialized' => true, 'reserved_bytes' => $total]);
            });

            return true;
        } finally {
            $exclusive->close();
        }
    }

    public function reserveOwned(int $bytes): void
    {
        if ($this->entryId === null) {
            throw new RuntimeException('Scratch reservation must be bound to its registered inventory.');
        }
        $this->reserve($this->entryId, $bytes);
    }

    public function reserve(int $entryId, int $bytes): void
    {
        if ($bytes < 0) {
            throw new RuntimeException('Scratch reservation cannot be negative.');
        }
        // Measured before any lock; counting retained bytes again in the free-space margin is
        // deliberately conservative after crashes.
        $free = disk_free_space((string) config('release_verification.storage_path'));
        if ($free === false) {
            throw new RuntimeException('Verification is waiting for shared scratch capacity.');
        }
        $limit = min((int) config('release_verification.scratch_bytes'), (int) floor($free) - (int) config('release_verification.free_bytes'));
        DB::transaction(function () use ($entryId, $bytes, $limit): void {
            // Lock the entry, then change the shared budget with one conditional delta as the last
            // lock request, so concurrent workers hold the budget row only until commit.
            $entry = DB::table('release_verification_scratch')->where('id', $entryId)->lockForUpdate()->sole();
            $delta = $bytes - (int) $entry->reserved_bytes;
            $changed = DB::table('release_verification_scratch_budget')->where('host', $entry->host)->where('initialized', true)
                ->whereRaw('reserved_bytes + ? <= ?', [$delta, $limit])
                ->update(['reserved_bytes' => DB::raw('reserved_bytes + ('.$delta.')')]);
            if ($changed !== 1) {
                $state = DB::table('release_verification_scratch_budget')->where('host', $entry->host)->first();
                if ($state === null || ! $state->initialized) {
                    throw new RuntimeException('Shared scratch budget is not initialized.');
                }
                // An unchanged total within the limit changes no row.
                if ($delta !== 0 || (int) $state->reserved_bytes > $limit) {
                    throw new RuntimeException('Verification is waiting for shared scratch capacity.');
                }
            }
            DB::table('release_verification_scratch')->where('id', $entryId)->update(['reserved_bytes' => $bytes]);
        }, 5);
    }

    /** A stale lease never authorizes reclaiming a reservation from a still-running process. */
    public function reconcile(int $entryId, VerificationWorkerLocks $locks, ?callable $heartbeat = null): void
    {
        $entry = DB::table('release_verification_scratch')->where('id', $entryId)->sole();
        if ($entry->candidate_id === null || ! $locks->protects((int) $entry->candidate_id)) {
            throw new RuntimeException('Scratch reconciliation requires the stable candidate lock.');
        }
        $bytes = $this->directoryBytes((string) config('release_verification.storage_path').'/scratch/'.app(ScratchInventory::class)->directoryName($entry), $heartbeat);
        DB::transaction(function () use ($entryId, $bytes): void {
            $entry = DB::table('release_verification_scratch')->where('id', $entryId)->lockForUpdate()->sole();
            $this->adjust((string) $entry->host, $bytes - (int) $entry->reserved_bytes);
            DB::table('release_verification_scratch')->where('id', $entryId)->update(['reserved_bytes' => $bytes]);
        }, 5);
    }

    /** Called only after indexed cleanup has proved the exact directory absent. */
    public function forget(int $entryId): void
    {
        DB::transaction(function () use ($entryId): void {
            $entry = DB::table('release_verification_scratch')->where('id', $entryId)->lockForUpdate()->first();
            if ($entry === null) {
                return;
            }
            $this->adjust((string) $entry->host, -(int) $entry->reserved_bytes);
            DB::table('release_verification_scratch')->where('id', $entryId)->delete();
        }, 5);
    }

    /**
     * Apply an unconditional delta to the entry host's total, floored at zero, as the transaction's
     * last lock request. Runtime paths lock their entry row first, so they cannot deadlock on the pair.
     */
    private function adjust(string $host, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        DB::table('release_verification_scratch_budget')->where('host', $host)->update(['reserved_bytes' => DB::raw(
            'CASE WHEN reserved_bytes + ('.$delta.') > 0 THEN reserved_bytes + ('.$delta.') ELSE 0 END')]);
    }

    /** Create this worker host's budget row while its worker slot is held. */
    public function join(VerificationWorkerLocks $worker): void
    {
        $host = ScratchInventory::host();
        if ($host === '' || DB::table('release_verification_scratch_budget')->where('host', $host)->exists()) {
            return;
        }
        if (! $worker->held()) {
            throw new RuntimeException('A worker host joins the scratch budget only while its worker slot is held.');
        }
        if ($this->createHostBudget($host) === null) {
            throw new RuntimeException('Shared scratch budget state is unavailable.');
        }
    }

    /**
     * A joining host owns no inventory yet, so its scratch needs no exclusive bootstrap; any
     * unregistered directories already there are counted conservatively.
     */
    private function createHostBudget(string $host): ?object
    {
        $owned = DB::table('release_verification_scratch')->where('host', $host)->exists();
        $values = ['host' => $host, 'initialized' => ! $owned,
            'reserved_bytes' => $owned ? 0 : $this->directoryBytes((string) config('release_verification.storage_path').'/scratch')];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = (int) DB::table('release_verification_scratch_budget')->max('id') + 1;
            DB::table('release_verification_scratch_budget')->insertOrIgnore($values + ['id' => $id]);
            $state = DB::table('release_verification_scratch_budget')->where('host', $host)->first();
            if ($state !== null) {
                return $state;
            }
        }

        return null;
    }

    private function directoryBytes(string $directory, ?callable $heartbeat = null): int
    {
        if (is_link($directory)) {
            throw new RuntimeException('Scratch budget cannot measure linked directories.');
        }
        if (! file_exists($directory)) {
            $parent = dirname($directory);
            if (is_dir($parent) && (! is_readable($parent) || ! is_executable($parent))) {
                throw new RuntimeException('Scratch budget cannot prove an inaccessible path absent.');
            }

            return 0;
        }
        if (! is_dir($directory) || ! is_readable($directory) || ! is_executable($directory)) {
            throw new RuntimeException('Scratch budget directory is inaccessible.');
        }
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            if ($entry->isLink()) {
                continue;
            }
            if ($entry->isFile()) {
                $bytes += $entry->getSize();
            }
        }

        return $bytes;
    }
}
