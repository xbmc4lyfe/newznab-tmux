<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pending-capacity accounting and the locks that order verification writers.
 *
 * Legacy file manifests serialize every writer on the capacity row for its whole transaction.
 * Database manifests are protected by candidate and manifest row locks instead, so writers run
 * concurrently: each applies its capacity change as one conditional delta, its last lock request
 * before commit, and publications serialize only with each other on their own row.
 */
final class VerificationLedger
{
    public const string LOCKS_TABLE = 'release_verification_locks';

    public const string PUBLICATION_LOCK = 'publication';

    /** Publication lock rows `publication:0` … `publication:63`, chosen by release size. */
    public const int PUBLICATION_STRIPES = 64;

    /** Database manifests let verification writers run concurrently. */
    public function concurrent(): bool
    {
        return config('release_verification.manifest_storage', 'database') === 'database';
    }

    /** Take the legacy global writer lock when manifests are files; database manifests need none. */
    public function enter(string $missing = 'Verification migration has not initialized capacity state.'): void
    {
        if ($this->concurrent()) {
            return;
        }
        if (DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first() === null) {
            throw new RuntimeException($missing);
        }
    }

    /**
     * Serialize publications (and their duplicate detection) of one release size with each other,
     * not with admission or other sizes. Before the stripe rows exist, every size shares one row.
     */
    public function enterPublication(int $size, string $missing = 'Verification migration has not initialized capacity state.'): void
    {
        if (! $this->concurrent()) {
            $this->enter($missing);

            return;
        }
        $stripe = self::PUBLICATION_LOCK.':'.(abs($size) % self::PUBLICATION_STRIPES);
        if (DB::table(self::LOCKS_TABLE)->where('name', $stripe)->lockForUpdate()->first() !== null) {
            return;
        }
        if (DB::table(self::LOCKS_TABLE)->where('name', self::PUBLICATION_LOCK)->lockForUpdate()->first() === null) {
            throw new RuntimeException('Verification publication lock has not been initialized.');
        }
    }

    /** Advisory, non-locking capacity check that lets a full ledger fail before expensive work. */
    public function fits(int $delta): bool
    {
        if ($delta <= 0) {
            return true;
        }
        $pending = DB::table('release_verification_state')->where('id', 1)->value('pending_bytes');
        if ($pending === null) {
            throw new RuntimeException('Verification migration has not initialized capacity state.');
        }

        return (int) $pending + $delta <= (int) config('release_verification.pending_bytes');
    }

    /**
     * Apply a pending-bytes delta, refusing a growth that would exceed capacity.
     *
     * The capacity row stays locked until commit, so callers issue this as their last lock request:
     * afterwards they may write only rows their transaction already holds.
     */
    public function adjust(int $delta): bool
    {
        if ($delta === 0) {
            return true;
        }
        $query = DB::table('release_verification_state')->where('id', 1);
        if ($delta > 0) {
            $changed = $query->whereRaw('pending_bytes + ? <= ?', [$delta, (int) config('release_verification.pending_bytes')])
                ->increment('pending_bytes', $delta);
        } else {
            $changed = $query->decrement('pending_bytes', -$delta);
        }
        if ($changed === 1) {
            return true;
        }
        if (! DB::table('release_verification_state')->where('id', 1)->exists()) {
            throw new RuntimeException('Verification migration has not initialized capacity state.');
        }

        return false;
    }
}
