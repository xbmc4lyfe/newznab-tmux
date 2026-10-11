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
 * collections still waiting at that moment get their lost time back.
 */
final class CollectionDowntimeCompensator
{
    /**
     * Restarts shorter than this (deploys, crash recovery) need no compensation.
     */
    public const int MIN_DOWNTIME_SECONDS = 300;

    private const int WINDOW_SECONDS = 60;

    /**
     * Safety cap on UPDATE windows per start; each window holds at least one row.
     */
    private const int MAX_WINDOWS = 100_000;

    /**
     * Kept under storage/ rather than the cache: it must survive container restarts and
     * the Cache::flush() that the updater runs while tmux is stopped.
     */
    private readonly string $markerPath;

    public function __construct(?string $markerPath = null)
    {
        $this->markerPath = $markerPath ?? storage_path('app/tmux/last-ingestion-activity');
    }

    /**
     * Record that ingestion is running now. Called from the monitor loop.
     */
    public function recordActivity(): void
    {
        $this->withLockedMarker(function ($marker): void {
            $this->writeMarker($marker, Carbon::now('UTC')->getTimestamp());
        });
    }

    public function lastActivity(): ?int
    {
        $value = is_file($this->markerPath) ? trim((string) @file_get_contents($this->markerPath)) : '';

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * Push back the collections that were still waiting when the indexer stopped.
     *
     * The marker stays exclusively locked from reading it to the last update, so two
     * overlapping starts can't both claim the same outage: the second one waits, then
     * sees a short downtime.
     *
     * @return int The number of collections whose dateadded moved.
     */
    public function compensate(int $delayHours): int
    {
        return $this->withLockedMarker(function ($marker) use ($delayHours): int {
            rewind($marker);
            $value = trim((string) stream_get_contents($marker));
            $lastActive = ctype_digit($value) ? (int) $value : null;
            $now = Carbon::now('UTC')->getTimestamp();

            if ($lastActive !== null && $now - $lastActive < self::MIN_DOWNTIME_SECONDS) {
                return 0;
            }

            // Record the new start before touching rows: if this run dies partway, the next
            // start sees a short downtime and skips, so rows can be under-compensated but
            // never shifted twice. Unknown downtime changes nothing.
            $this->writeMarker($marker, $now);
            if ($lastActive === null) {
                return 0;
            }

            return $this->shiftDateAdded($lastActive, $now, max(0, $delayHours) * 3600);
        });
    }

    /**
     * Each collection that was waiting when ingestion stopped gets back the waiting time
     * it lost: the full downtime for those added before the last recorded activity (which
     * can't move them past the restart), and only the time since they were added for
     * those written in the moments after it.
     */
    private function shiftDateAdded(int $lastActive, int $now, int $delaySeconds): int
    {
        if ($delaySeconds <= 0) {
            return 0;
        }

        // The downtime comes from epochs, so a DST change can't distort or negate it.
        $downtime = max(0, $now - $lastActive);
        $lastActiveAt = $this->at($lastActive);
        $nowAt = $this->at($now);
        $waitingFrom = $lastActive - $delaySeconds;
        // dateadded holds wall-clock time, so adding seconds to it is only right while the
        // UTC offset stays put; across a DST change, shift each distinct value in PHP.
        $perValue = $this->crossesOffsetChange($waitingFrom, $lastActive, $now);
        $shifted = DB::getDriverName() === 'sqlite'
            ? DB::raw("datetime(dateadded, '+' || (CASE WHEN dateadded <= '{$lastActiveAt}' THEN {$downtime} ELSE MAX(0, MIN(strftime('%s', '{$nowAt}') - strftime('%s', dateadded), {$downtime})) END) || ' seconds')")
            : DB::raw("dateadded + INTERVAL (CASE WHEN dateadded <= '{$lastActiveAt}' THEN {$downtime} ELSE GREATEST(0, LEAST(TIMESTAMPDIFF(SECOND, dateadded, '{$nowAt}'), {$downtime})) END) SECOND");
        $moved = 0;

        // One dateadded window at a time, newest first, jumping straight to the next
        // occupied window: each UPDATE is a range on the dateadded index, and a moved row
        // lands in a later window that is already done (or at now), so nothing is shifted
        // twice, and the work follows the waiting rows rather than the id range or the
        // length of the outage. Start from the later wall-clock value: after a DST
        // fall-back, now can read earlier than the last activity.
        $windowEnd = max($nowAt, $lastActiveAt);
        $inclusive = true;
        for ($window = 0; $window < self::MAX_WINDOWS; $window++) {
            $newest = $this->newestWaiting($this->at($waitingFrom), $windowEnd, $inclusive);
            if ($newest === null) {
                break;
            }
            $windowStartAt = $this->at(max($this->timestamp($newest) - self::WINDOW_SECONDS, $waitingFrom));
            $moved += $perValue
                ? $this->shiftEachValue($windowStartAt, $newest, $lastActive, $now, $downtime)
                : DB::table('collections')
                    ->where('dateadded', '>=', $windowStartAt)
                    ->where('dateadded', '<=', $newest)
                    ->update(['dateadded' => $shifted]);
            $windowEnd = $windowStartAt;
            $inclusive = false;
        }

        return $moved;
    }

    /**
     * Shift each distinct dateadded value in the window through real timezone arithmetic,
     * newest first so a moved value never lands on one still to be processed.
     */
    private function shiftEachValue(string $from, string $to, int $lastActive, int $now, int $downtime): int
    {
        $moved = 0;
        $values = DB::table('collections')
            ->where('dateadded', '>=', $from)
            ->where('dateadded', '<=', $to)
            ->distinct()
            ->orderByDesc('dateadded')
            ->pluck('dateadded');
        foreach ($values as $value) {
            $added = $this->timestamp((string) $value);
            $shift = $added <= $lastActive ? $downtime : max(0, min($now - $added, $downtime));
            if ($shift === 0) {
                continue;
            }
            $moved += DB::table('collections')
                ->where('dateadded', $value)
                ->update(['dateadded' => $this->at($added + $shift)]);
        }

        return $moved;
    }

    /**
     * Whether the application timezone changes its UTC offset anywhere in the span.
     */
    private function crossesOffsetChange(int ...$timestamps): bool
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        $transitions = $zone->getTransitions(min($timestamps), max($timestamps));

        // The first entry describes the offset at the start; any more are changes.
        return \is_array($transitions) && \count($transitions) > 1;
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

    /**
     * Datetime columns hold application-timezone wall-clock values, so convert both ways
     * in that timezone.
     */
    private function at(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, date_default_timezone_get())->format('Y-m-d H:i:s');
    }

    private function timestamp(string $dateTime): int
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', substr($dateTime, 0, 19), date_default_timezone_get())->getTimestamp();
    }

    /**
     * @template TResult
     *
     * @param  callable(resource): TResult  $work
     * @return TResult
     */
    private function withLockedMarker(callable $work): mixed
    {
        $directory = \dirname($this->markerPath);
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $marker = fopen($this->markerPath, 'c+');
        if ($marker === false) {
            throw new \RuntimeException("Unable to open {$this->markerPath}.");
        }

        try {
            flock($marker, LOCK_EX);

            return $work($marker);
        } finally {
            flock($marker, LOCK_UN);
            fclose($marker);
        }
    }

    /**
     * @param  resource  $marker
     */
    private function writeMarker($marker, int $timestamp): void
    {
        $value = (string) $timestamp;
        // Callers rely on the marker being saved before any row moves, so fail loudly.
        if (! ftruncate($marker, 0) || ! rewind($marker)
            || fwrite($marker, $value) !== \strlen($value) || ! fflush($marker)) {
            throw new \RuntimeException("Unable to write {$this->markerPath}.");
        }
    }
}
