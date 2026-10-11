<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use Illuminate\Support\Carbon;
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
    /**
     * Kept under storage/ rather than the cache: it must survive container restarts and
     * the Cache::flush() that the updater runs while tmux is stopped.
     */
    private readonly string $markerPath;

    /**
     * Restarts shorter than this (deploys, crash recovery) need no compensation.
     */
    public const int MIN_DOWNTIME_SECONDS = 300;

    private const int WINDOW_SECONDS = 60;

    public function __construct(?string $markerPath = null)
    {
        $this->markerPath = $markerPath ?? storage_path('app/tmux/last-ingestion-activity');
    }

    /**
     * Record that ingestion is running now. Called from the monitor loop.
     */
    public function recordActivity(): void
    {
        $directory = \dirname($this->markerPath);
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        file_put_contents($this->markerPath, (string) Carbon::now()->getTimestamp(), LOCK_EX);
    }

    public function lastActivity(): ?int
    {
        $value = is_file($this->markerPath) ? trim((string) @file_get_contents($this->markerPath)) : '';

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Push back the collections that were still waiting when the indexer stopped.
     *
     * @return int The number of collections whose dateadded moved.
     */
    public function compensate(int $delayHours): int
    {
        $lastActive = $this->lastActivity();
        $now = Carbon::now()->getTimestamp();
        if ($lastActive === null) {
            // Unknown downtime: leave the delays alone rather than extend everything.
            $this->recordActivity();

            return 0;
        }

        $downtime = $now - (int) $lastActive;
        if ($downtime < self::MIN_DOWNTIME_SECONDS) {
            return 0;
        }

        // Each collection that was waiting when ingestion stopped gets back the waiting
        // time it lost: the downtime for those added before the last recorded activity,
        // and only the time since they were added for those written in the moments
        // after it, never more than one full delay.
        // Record the new start first: if this run dies partway, the next start sees a
        // short downtime and skips, so rows can be under-compensated but never shifted twice.
        $this->recordActivity();

        return $this->shiftDateAdded($lastActive, $now, max(0, $delayHours) * 3600);
    }

    private function shiftDateAdded(int $lastActive, int $now, int $delaySeconds): int
    {
        if ($delaySeconds <= 0) {
            return 0;
        }

        $lastActiveAt = Carbon::createFromTimestamp($lastActive)->format('Y-m-d H:i:s');
        $nowAt = Carbon::createFromTimestamp($now)->format('Y-m-d H:i:s');
        $shifted = DB::getDriverName() === 'sqlite'
            ? DB::raw("datetime(dateadded, '+' || MIN(strftime('%s', '{$nowAt}') - MAX(strftime('%s', dateadded), strftime('%s', '{$lastActiveAt}')), {$delaySeconds}) || ' seconds')")
            : DB::raw("dateadded + INTERVAL LEAST(TIMESTAMPDIFF(SECOND, GREATEST(dateadded, '{$lastActiveAt}'), '{$nowAt}'), {$delaySeconds}) SECOND");
        $moved = 0;

        // One dateadded window at a time, newest first, jumping straight to the next
        // occupied window: each UPDATE is a range on the dateadded index, and a moved row
        // lands in a later window that is already done (or at now), so nothing is shifted
        // twice, and the work follows the waiting rows rather than the id range or the
        // length of the outage.
        $waitingFrom = Carbon::createFromTimestamp($lastActive - $delaySeconds)->format('Y-m-d H:i:s');
        $windowEnd = $nowAt;
        $inclusive = true;
        while (($newest = $this->newestWaiting($waitingFrom, $windowEnd, $inclusive)) !== null) {
            $windowStart = max(
                Carbon::parse($newest)->getTimestamp() - self::WINDOW_SECONDS,
                $lastActive - $delaySeconds,
            );
            $windowStartAt = Carbon::createFromTimestamp($windowStart)->format('Y-m-d H:i:s');
            $moved += DB::table('collections')
                ->where('dateadded', '>=', $windowStartAt)
                ->where('dateadded', '<=', $newest)
                ->update(['dateadded' => $shifted]);
            $windowEnd = $windowStartAt;
            $inclusive = false;
        }

        return $moved;
    }

    /**
     * The newest dateadded at or after $from and before (or at) $before, found through
     * the dateadded index.
     */
    private function newestWaiting(string $from, string $before, bool $inclusive): ?string
    {
        $newest = DB::table('collections')
            ->where('dateadded', '>=', $from)
            ->where('dateadded', $inclusive ? '<=' : '<', $before)
            ->max('dateadded');

        return $newest === null ? null : (string) $newest;
    }
}
