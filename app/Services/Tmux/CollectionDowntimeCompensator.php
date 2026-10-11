<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Gives incomplete collections back the time they lost while the indexer was down.
 *
 * Collections wait collectionDelayHours for missing parts before they are processed as
 * stale. When ingestion stops, that clock keeps running, so after a long outage
 * collections would be cut short. This used to be handled by resetting dateadded to now
 * for every collection older than the delay on every start, which on a busy indexer
 * rewrote millions of rows and, with frequent restarts, kept collections from ever
 * expiring. Instead the monitor records when it was last active, and on start only the
 * collections still waiting at that moment are pushed back by the downtime.
 */
final class CollectionDowntimeCompensator
{
    public const string LAST_ACTIVE_KEY = 'tmux:collections:last_active_at';

    /**
     * Restarts shorter than this (deploys, crash recovery) need no compensation.
     */
    public const int MIN_DOWNTIME_SECONDS = 300;

    private const int WINDOW_SECONDS = 60;

    /**
     * Record that ingestion is running now. Called from the monitor loop.
     */
    public function recordActivity(): void
    {
        Cache::forever(self::LAST_ACTIVE_KEY, Carbon::now()->getTimestamp());
    }

    /**
     * Push back the collections that were still waiting when the indexer stopped.
     *
     * @return int The number of collections whose dateadded moved.
     */
    public function compensate(int $delayHours): int
    {
        $lastActive = Cache::get(self::LAST_ACTIVE_KEY);
        $now = Carbon::now()->getTimestamp();
        if (! is_numeric($lastActive)) {
            // Unknown downtime: leave the delays alone rather than extend everything.
            $this->recordActivity();

            return 0;
        }

        $downtime = $now - (int) $lastActive;
        if ($downtime < self::MIN_DOWNTIME_SECONDS) {
            return 0;
        }

        // A collection never gets more than a fresh full delay, and only the collections
        // that were waiting when activity stopped lost time (newer ones were added by
        // something running without the monitor and are left alone).
        $shift = min($downtime, max(0, $delayHours) * 3600);
        $moved = $this->shiftDateAdded((int) $lastActive - max(0, $delayHours) * 3600, (int) $lastActive, $shift);

        $this->recordActivity();

        return $moved;
    }

    private function shiftDateAdded(int $waitingFrom, int $waitingUntil, int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        $shifted = DB::getDriverName() === 'sqlite'
            ? DB::raw("datetime(dateadded, '+{$seconds} seconds')")
            : DB::raw("dateadded + INTERVAL {$seconds} SECOND");
        $moved = 0;

        // One dateadded window at a time, newest first: each UPDATE is a range on the
        // dateadded index, and a moved row lands in a later window that is already done,
        // so nothing is shifted twice (the walk follows the waiting rows, not the id range).
        for ($windowEnd = $waitingUntil; $windowEnd > $waitingFrom; $windowEnd -= self::WINDOW_SECONDS) {
            $windowStart = max($waitingFrom, $windowEnd - self::WINDOW_SECONDS);
            $moved += DB::table('collections')
                ->where('dateadded', '>=', Carbon::createFromTimestamp($windowStart)->format('Y-m-d H:i:s'))
                ->where('dateadded', $windowEnd === $waitingUntil ? '<=' : '<', Carbon::createFromTimestamp($windowEnd)->format('Y-m-d H:i:s'))
                ->update(['dateadded' => $shifted]);
        }

        return $moved;
    }
}
