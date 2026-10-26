<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Enums\CollectionFileCheckStatus;
use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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
final class CollectionDowntimeCompensator implements CollectionReconciliationBarrier
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_downtime_format_at(int64_t timestamp, int64_t utc_offset_seconds, char *out_buf, size_t out_cap);
        int32_t nntmux_downtime_parse_civil_datetime(const char *input_ptr, size_t input_len, int64_t *out_civil_epoch);
        int64_t nntmux_downtime_largest_offset_change(const int64_t *offsets_ptr, size_t count);
        int32_t nntmux_downtime_crosses_offset_change(ssize_t transition_count);
        int32_t nntmux_downtime_select_timestamp_not_after(int64_t first, int64_t not_after, const int64_t *candidates_ptr, size_t count, int64_t *out_timestamp);
        int32_t nntmux_downtime_verify_marker_dir(const char *temp_dir_ptr, size_t temp_dir_len, const char *real_dir_ptr, size_t real_dir_len);
        int32_t nntmux_downtime_verify_marker_write(size_t expected_len, ssize_t written_bytes, uint32_t flushed, uint32_t synced);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * Restarts shorter than this (deploys, crash recovery) need no compensation.
     */
    public const int MIN_DOWNTIME_SECONDS = 300;

    private const int WINDOW_SECONDS = 60;

    private const int NOTE_INTERVAL_SECONDS = 30;

    private const int ROW_CHUNK = 5_000;

    /** Statuses whose reconciliation is gated by collectionDelayTime (10 is legacy). */
    private const array WAITING_FILECHECKS = [
        CollectionFileCheckStatus::Default->value,
        CollectionFileCheckStatus::CompleteCollection->value,
        CollectionFileCheckStatus::TempComplete->value,
        CollectionFileCheckStatus::ZeroPart->value,
        10,
    ];

    /**
     * Safety cap on UPDATE windows per start; each window holds at least one row.
     */
    private const int MAX_WINDOWS = 100_000;

    /**
     * Kept under storage/ rather than the cache: it must survive container restarts and
     * the Cache::flush() that the updater runs while tmux is stopped.
     */
    private readonly string $markerPath;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function categorizeFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(?string $markerPath = null)
    {
        $this->markerPath = $markerPath ?? storage_path('app/tmux/last-ingestion-activity');
    }

    /**
     * Record that ingestion is running now. Called from the monitor loop.
     */
    public function recordActivity(): void
    {
        $this->withLockedMarker(function (): void {
            $this->compensateLocked(null, true);
        });
    }

    /**
     * Record activity from ingestion workers, at most every NOTE_INTERVAL_SECONDS (by the
     * marker's mtime) so many workers don't contend for the lock.
     */
    public function noteIngestion(): void
    {
        clearstatcache(true, $this->markerPath);
        $modified = @filemtime($this->markerPath);
        // mtime is filesystem (real) time, so compare against the real clock.
        if ($modified !== false && time() - $modified < self::NOTE_INTERVAL_SECONDS) {
            return;
        }
        $this->recordActivity();
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
        return $this->withLockedMarker(fn (): int => $this->compensateLocked($delayHours, false));
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult
     */
    public function withReconciliationBarrier(int $delayHours, callable $work): mixed
    {
        return $this->withLockedMarker(function () use ($delayHours, $work): mixed {
            $this->compensateLocked($delayHours, false);

            return $work();
        });
    }

    /**
     * Claim any stale interval before recording resumed activity, including direct
     * header workers that run before tmux starts. The caller holds the stable lock.
     */
    private function compensateLocked(?int $delayHours, bool $refreshRecent): int
    {
        $lastActive = $this->lastActivity();
        $now = Carbon::now('UTC')->getTimestamp();
        $stale = $lastActive !== null && $now - $lastActive >= self::MIN_DOWNTIME_SECONDS;
        if (! $stale && ! $refreshRecent && $lastActive !== null) {
            return 0;
        }
        $upperId = 0;
        if ($stale) {
            $delayHours ??= app(ConfigurationProvider::class)->ingestion()->collectionDelayHours;
            // Capture the ID ceiling before the recovery time. Active header workers
            // may insert rows while a widened DST scan walks subsequent chunks.
            $upperId = (int) DB::table('collections')->max('id');
            $now = Carbon::now('UTC')->getTimestamp();
        }

        // Claim before moving rows: an interrupted run can under-compensate rows,
        // but a retry cannot shift them twice. Failed publication leaves the old claim.
        $this->writeMarker($now);
        if (! $stale || $lastActive === null) {
            return 0;
        }

        return $this->shiftDateAdded($lastActive, $now, max(0, $delayHours ?? 0) * 3600, $upperId);
    }

    /**
     * Each collection that was waiting when ingestion stopped gets back the waiting time
     * it lost: the full downtime for those added before the last recorded activity (which
     * can't move them past the restart), and only the time since they were added for
     * those written in the moments after it.
     */
    private function shiftDateAdded(int $lastActive, int $now, int $delaySeconds, int $upperId): int
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
        if ($perValue) {
            // Wall-clock values from a repeated fall-back hour don't say which occurrence
            // they were, so widen both bounds by the offset change to include them; each
            // row's real age is then checked after resolving its timestamp.
            $fold = $this->largestOffsetChange($waitingFrom, $lastActive, $now);

            return $this->shiftRowsById(
                $this->at($waitingFrom - $fold),
                $this->at(max($now, $lastActive) + $fold),
                $waitingFrom,
                $lastActive,
                $now,
                $downtime,
                $upperId,
            );
        }
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
            $newest = $this->newestWaiting($this->at($waitingFrom), $windowEnd, $inclusive, $upperId);
            if ($newest === null) {
                break;
            }
            $windowStartAt = $this->at(max($this->timestamp($newest) - self::WINDOW_SECONDS, $waitingFrom));
            $moved += DB::table('collections')
                ->whereIn('filecheck', self::WAITING_FILECHECKS)
                ->where('id', '<=', $upperId)
                ->where('dateadded', '>=', $windowStartAt)
                ->where('dateadded', '<=', $newest)
                ->update(['dateadded' => $shifted]);
            $windowEnd = $windowStartAt;
            $inclusive = false;
        }

        return $moved;
    }

    /**
     * Across a DST change, wall-clock values can move backwards even though real time
     * moves forward, so the newest-first windows could meet a moved row again. Walk the
     * waiting rows by id instead (a stable key), computing each new value with real
     * timezone arithmetic; every row is read and moved exactly once. This only runs for
     * a restart whose span crosses an offset change.
     */
    private function shiftRowsById(string $from, string $to, int $waitingFrom, int $lastActive, int $now, int $downtime, int $upperId): int
    {
        $moved = 0;
        $lastId = 0;
        do {
            $rows = DB::table('collections')
                ->whereIn('filecheck', self::WAITING_FILECHECKS)
                ->where('id', '<=', $upperId)
                ->where('dateadded', '>=', $from)
                ->where('dateadded', '<=', $to)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::ROW_CHUNK)
                ->get(['id', 'dateadded']);
            $byValue = [];
            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $added = $this->timestampNotAfter((string) $row->dateadded, $now);
                if ($added < $waitingFrom) {
                    continue; // Picked up only by the widened bound: it had already expired.
                }
                $shift = $added <= $lastActive ? $downtime : max(0, min($now - $added, $downtime));
                if ($shift > 0) {
                    $byValue[$this->at($added + $shift)][] = $lastId;
                }
            }
            foreach ($byValue as $value => $ids) {
                $moved += DB::table('collections')->whereIn('filecheck', self::WAITING_FILECHECKS)
                    ->where('id', '<=', $upperId)->whereIn('id', $ids)->update(['dateadded' => $value]);
            }
        } while ($rows->count() === self::ROW_CHUNK);

        return $moved;
    }

    /**
     * A wall-clock value from a repeated fall-back hour names two instants; take the
     * later one unless it is after $notAfter (the restart: no row was added after it).
     */
    public function timestampNotAfter(string $dateTime, int $notAfter): int
    {
        return self::$useRustFfi
            ? $this->timestampNotAfterNative($dateTime, $notAfter)
            : $this->timestampNotAfterPhp($dateTime, $notAfter);
    }

    public function timestampNotAfterPhp(string $dateTime, int $notAfter): int
    {
        $first = $this->timestampPhp($dateTime);
        $value = substr($dateTime, 0, 19);
        $zone = new \DateTimeZone(date_default_timezone_get());
        $candidates = [$first];
        $transitions = $zone->getTransitions($first - 7 * 86400, $first + 7 * 86400) ?: [];
        for ($i = 1, $count = \count($transitions); $i < $count; $i++) {
            $fold = $transitions[$i - 1]['offset'] - $transitions[$i]['offset'];
            if ($fold <= 0) {
                continue;
            }
            foreach ([$first - $fold, $first + $fold] as $candidate) {
                if ((new \DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d H:i:s') === $value) {
                    $candidates[] = $candidate;
                }
            }
        }
        $beforeRestart = array_filter($candidates, static fn (int $candidate): bool => $candidate <= $notAfter);

        return $beforeRestart === [] ? $first : max($beforeRestart);
    }

    public function timestampNotAfterNative(string $dateTime, int $notAfter): int
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->timestampNotAfterPhp($dateTime, $notAfter);
        }

        $first = $this->timestampNative($dateTime);
        $value = substr($dateTime, 0, 19);
        $zone = new \DateTimeZone(date_default_timezone_get());
        $candidates = [$first];
        $transitions = $zone->getTransitions($first - 7 * 86400, $first + 7 * 86400) ?: [];
        for ($i = 1, $count = \count($transitions); $i < $count; $i++) {
            $fold = $transitions[$i - 1]['offset'] - $transitions[$i]['offset'];
            if ($fold <= 0) {
                continue;
            }
            foreach ([$first - $fold, $first + $fold] as $candidate) {
                if ((new \DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d H:i:s') === $value) {
                    $candidates[] = $candidate;
                }
            }
        }

        $candCount = \count($candidates);
        $cCands = $ffi->new("int64_t[{$candCount}]");
        $outTs = $ffi->new('int64_t[1]');
        for ($i = 0; $i < $candCount; $i++) {
            $cCands[$i] = $candidates[$i];
        }

        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_downtime_select_timestamp_not_after(
            $first,
            $notAfter,
            $cCands,
            $candCount,
            $outTs
        );
        if (! \is_int($rc) || $rc !== 1) {
            return $this->timestampNotAfterPhp($dateTime, $notAfter);
        }

        /** @var int $resolved */
        $resolved = $outTs[0];

        return $resolved;
    }

    /**
     * The largest backward UTC-offset change (a fall-back, which repeats wall-clock
     * times), in seconds, in the span. Spring-forward changes repeat nothing.
     */
    public function largestOffsetChange(int ...$timestamps): int
    {
        return self::$useRustFfi
            ? $this->largestOffsetChangeNative(...$timestamps)
            : $this->largestOffsetChangePhp(...$timestamps);
    }

    public function largestOffsetChangePhp(int ...$timestamps): int
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        $offsets = array_column($zone->getTransitions(min($timestamps), max($timestamps)) ?: [], 'offset');
        $largest = 0;
        for ($i = 1, $n = \count($offsets); $i < $n; $i++) {
            $largest = max($largest, $offsets[$i - 1] - $offsets[$i]);
        }

        return $largest;
    }

    public function largestOffsetChangeNative(int ...$timestamps): int
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->largestOffsetChangePhp(...$timestamps);
        }

        $zone = new \DateTimeZone(date_default_timezone_get());
        $offsets = array_column($zone->getTransitions(min($timestamps), max($timestamps)) ?: [], 'offset');
        $count = \count($offsets);
        if ($count < 2) {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_downtime_largest_offset_change(null, 0);

            return \is_int($res) && $res >= 0 ? $res : $this->largestOffsetChangePhp(...$timestamps);
        }

        $cOffsets = $ffi->new("int64_t[{$count}]");
        for ($i = 0; $i < $count; $i++) {
            $cOffsets[$i] = (int) $offsets[$i];
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_downtime_largest_offset_change($cOffsets, $count);
        if (! \is_int($res) || $res < 0) {
            return $this->largestOffsetChangePhp(...$timestamps);
        }

        return $res;
    }

    /**
     * Whether the application timezone changes its UTC offset anywhere in the span.
     */
    public function crossesOffsetChange(int ...$timestamps): bool
    {
        return self::$useRustFfi
            ? $this->crossesOffsetChangeNative(...$timestamps)
            : $this->crossesOffsetChangePhp(...$timestamps);
    }

    public function crossesOffsetChangePhp(int ...$timestamps): bool
    {
        $zone = new \DateTimeZone(date_default_timezone_get());
        $transitions = $zone->getTransitions(min($timestamps), max($timestamps));

        // The first entry describes the offset at the start; any more are changes.
        return \is_array($transitions) && \count($transitions) > 1;
    }

    public function crossesOffsetChangeNative(int ...$timestamps): bool
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->crossesOffsetChangePhp(...$timestamps);
        }

        $zone = new \DateTimeZone(date_default_timezone_get());
        $transitions = $zone->getTransitions(min($timestamps), max($timestamps));
        $transitionCount = \is_array($transitions) ? \count($transitions) : -1;

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_downtime_crosses_offset_change($transitionCount);
        if (! \is_int($res) || $res < 0) {
            return $this->crossesOffsetChangePhp(...$timestamps);
        }

        return $res === 1;
    }

    /**
     * The newest dateadded at or after $from and before (or at) $before, found through
     * the dateadded index.
     */
    private function newestWaiting(string $from, string $before, bool $inclusive, int $upperId): ?string
    {
        $newest = DB::table('collections')
            ->whereIn('filecheck', self::WAITING_FILECHECKS)
            ->where('id', '<=', $upperId)
            ->where('dateadded', '>=', $from)
            ->where('dateadded', $inclusive ? '<=' : '<', $before)
            ->orderByDesc('dateadded')
            ->value('dateadded');

        return $newest === null ? null : (string) $newest;
    }

    /**
     * Datetime columns hold application-timezone wall-clock values, so convert both ways
     * in that timezone.
     */
    public function at(int $timestamp): string
    {
        return self::$useRustFfi
            ? $this->atNative($timestamp)
            : $this->atPhp($timestamp);
    }

    public function atPhp(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, date_default_timezone_get())->format('Y-m-d H:i:s');
    }

    public function atNative(int $timestamp): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->atPhp($timestamp);
        }

        $tzName = date_default_timezone_get();
        $offset = ($tzName === 'UTC' || $tzName === 'Etc/UTC')
            ? 0
            : (new \DateTimeZone($tzName))->getOffset(new \DateTimeImmutable('@'.$timestamp));

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return $this->atPhp($timestamp);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_downtime_format_at($timestamp, $offset, $scratch, $cap);
        if (! \is_int($written) || $written !== 19) {
            return $this->atPhp($timestamp);
        }

        return FFI::string($scratch, 19);
    }

    public function timestamp(string $dateTime): int
    {
        return self::$useRustFfi
            ? $this->timestampNative($dateTime)
            : $this->timestampPhp($dateTime);
    }

    public function timestampPhp(string $dateTime): int
    {
        return Carbon::createFromFormat('Y-m-d H:i:s', substr($dateTime, 0, 19), date_default_timezone_get())->getTimestamp();
    }

    public function timestampNative(string $dateTime): int
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->timestampPhp($dateTime);
        }

        $outCivil = $ffi->new('int64_t[1]');

        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_downtime_parse_civil_datetime($dateTime, \strlen($dateTime), $outCivil);
        if (! \is_int($rc) || $rc !== 1) {
            return $this->timestampPhp($dateTime);
        }

        /** @var int $civilEpoch */
        $civilEpoch = $outCivil[0];
        $tzName = date_default_timezone_get();
        if ($tzName === 'UTC' || $tzName === 'Etc/UTC') {
            return $civilEpoch;
        }

        $zone = new \DateTimeZone($tzName);
        $transitions = $zone->getTransitions($civilEpoch - 86400, $civilEpoch + 86400);
        if (\is_array($transitions) && \count($transitions) === 1) {
            return $civilEpoch - (int) $transitions[0]['offset'];
        }

        return $this->timestampPhp($dateTime);
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult
     */
    private function withLockedMarker(callable $work): mixed
    {
        $directory = \dirname($this->markerPath);
        $parents = [\dirname($directory)];
        $missing = $directory;
        while (! is_dir($missing)) {
            $parent = \dirname($missing);
            if ($parent === $missing) {
                throw new \RuntimeException('Unable to create marker directory.');
            }
            $parents[] = $parent;
            $missing = $parent;
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create marker directory.');
        }
        $marker = fopen($this->markerPath.'.lock', 'c+');
        if ($marker === false) {
            throw new \RuntimeException("Unable to open {$this->markerPath}.");
        }

        try {
            if (! flock($marker, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock {$this->markerPath}.");
            }

            // Always repeat the parent sync: a prior failed creator, or a concurrent
            // creator, can leave an existing directory with an unsynced parent entry.
            foreach (array_unique($parents) as $parent) {
                $parentStream = fopen($parent, 'r');
                if ($parentStream === false) {
                    throw new \RuntimeException('Unable to sync marker parent directory.');
                }
                try {
                    if (! $this->syncDirectory($parentStream)) {
                        throw new \RuntimeException('Unable to sync marker parent directory.');
                    }
                } finally {
                    fclose($parentStream);
                }
            }
            $this->recoverPublication();

            return $work();
        } finally {
            flock($marker, LOCK_UN);
            fclose($marker);
        }
    }

    /**
     * Publish only a complete, flushed timestamp. The separate lock file is never
     * replaced, so waiters still serialize against the same inode after publication.
     */
    private function writeMarker(int $timestamp): void
    {
        $directoryStream = fopen(\dirname($this->markerPath), 'r');
        if ($directoryStream === false) {
            throw new \RuntimeException('Unable to sync marker directory.');
        }
        $temporary = null;
        $recoveryTemporary = null;
        $published = false;
        $recoveryPath = $this->markerPath.'.recovery';
        try {
            $previous = is_file($this->markerPath) ? file_get_contents($this->markerPath) : null;
            if ($previous === false) {
                throw new \RuntimeException('Unable to read marker before replacement.');
            }
            $temporary = $this->prepareMarker((string) $timestamp);
            $record = json_encode(['previous' => $previous === null ? null : base64_encode($previous)], JSON_THROW_ON_ERROR)."\n";
            $recoveryTemporary = $this->prepareMarker($record);
            if (! rename($recoveryTemporary, $recoveryPath)) {
                throw new \RuntimeException('Unable to replace marker recovery record.');
            }
            // Make recovery evidence durable while the original marker is still intact.
            if (! $this->syncDirectory($directoryStream)) {
                throw new \RuntimeException('Unable to sync marker recovery directory.');
            }
            if (! rename($temporary, $this->markerPath)) {
                throw new \RuntimeException('Unable to replace marker.');
            }
            $published = true;
            if (! $this->syncDirectory($directoryStream)) {
                throw new \RuntimeException('Unable to sync marker directory.');
            }
            $checkpoint = fopen($recoveryPath, 'ab');
            if ($checkpoint === false) {
                throw new \RuntimeException('Unable to open marker recovery checkpoint.');
            }
            try {
                $committed = "COMMITTED\n";
                if (fwrite($checkpoint, $committed) !== \strlen($committed) || ! fflush($checkpoint) || ! fsync($checkpoint)) {
                    throw new \RuntimeException('Unable to persist marker recovery checkpoint.');
                }
            } finally {
                fclose($checkpoint);
            }
            // The committed record is durable before rows move. If this unlink is lost
            // on a crash, recovery sees COMMITTED and never restores the old timestamp.
            @unlink($recoveryPath);
        } catch (\Throwable $exception) {
            if ($published) {
                $this->recoverPublication(forceRollback: true);
            }
            throw $exception;
        } finally {
            fclose($directoryStream);
            foreach ([$temporary, $recoveryTemporary] as $path) {
                if ($path !== null && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * Under the stable lock, recover a publication that never reached its durable
     * checkpoint. Persistent storage errors retain the previously synced evidence.
     */
    private function recoverPublication(bool $forceRollback = false): void
    {
        $recoveryPath = $this->markerPath.'.recovery';
        $forcedRecord = file_get_contents($this->markerPath.'.lock');
        if ($forcedRecord === false) {
            throw new \RuntimeException('Unrecoverable marker publication: pending lock record is unreadable.');
        }
        if ($forcedRecord === '' && ! is_file($recoveryPath)) {
            return;
        }
        $record = $forcedRecord !== '' ? $forcedRecord : file_get_contents($recoveryPath);
        if ($record === false) {
            throw new \RuntimeException('Unrecoverable marker publication: recovery record is unreadable.');
        }
        if (! $forceRollback && $forcedRecord === '' && str_contains($record, "\nCOMMITTED\n")) {
            @unlink($recoveryPath);

            return;
        }
        $pendingRecord = explode("\n", $record, 2)[0]."\n";
        if ($forceRollback && $forcedRecord === '') {
            // A failed checkpoint can leave a visible COMMITTED suffix. Persist an
            // overriding pending record in the already durable, stable lock inode
            // before attempting any rollback rename that might itself be lost.
            $this->writeLockRecovery($pendingRecord);
        }
        $metadata = json_decode(trim($pendingRecord), true, 512, JSON_THROW_ON_ERROR);
        if (! \is_array($metadata) || ! array_key_exists('previous', $metadata) || ($metadata['previous'] !== null && ! \is_string($metadata['previous']))) {
            throw new \RuntimeException('Unrecoverable marker publication: invalid recovery record.');
        }
        $previous = $metadata['previous'] === null ? null : base64_decode($metadata['previous'], true);
        if ($previous === false) {
            throw new \RuntimeException('Unrecoverable marker publication: invalid recovery timestamp.');
        }
        $directoryStream = fopen(\dirname($this->markerPath), 'r');
        if ($directoryStream === false) {
            throw new \RuntimeException('Unrecoverable marker publication: directory is unavailable.');
        }
        $temporary = null;
        try {
            if ($previous !== null) {
                $temporary = $this->prepareMarker($previous);
                if (! rename($temporary, $this->markerPath)) {
                    throw new \RuntimeException('Unrecoverable marker publication: timestamp cannot be restored.');
                }
            } elseif (is_file($this->markerPath) && ! unlink($this->markerPath)) {
                throw new \RuntimeException('Unrecoverable marker publication: unknown timestamp cannot be removed.');
            }
            if (! $this->syncDirectory($directoryStream)) {
                throw new \RuntimeException('Unrecoverable marker publication: rollback cannot be synced; recovery evidence retained.');
            }
            $this->writeLockRecovery('');
            @unlink($recoveryPath);
        } finally {
            fclose($directoryStream);
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Persist pending rollback evidence without changing a directory entry.
     * Called only while the same stable lock inode is exclusively held.
     */
    private function writeLockRecovery(string $record): void
    {
        $stream = fopen($this->markerPath.'.lock', 'c+');
        if ($stream === false) {
            throw new \RuntimeException('Unrecoverable marker publication: pending lock record cannot be opened.');
        }
        try {
            if (! rewind($stream) || fwrite($stream, $record) !== \strlen($record)
                || ! ftruncate($stream, \strlen($record)) || ! fflush($stream) || ! fsync($stream)) {
                throw new \RuntimeException('Unrecoverable marker publication: pending lock record cannot be synced.');
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     *
     * @phpstan-impure
     */
    private function syncDirectory($stream): bool
    {
        return fsync($stream);
    }

    /**
     * Prepare a complete file before either publication or rollback changes the marker.
     */
    public function prepareMarker(string $value): string
    {
        return self::$useRustFfi
            ? $this->prepareMarkerNative($value)
            : $this->prepareMarkerPhp($value);
    }

    public function prepareMarkerPhp(string $value): string
    {
        $directory = \dirname($this->markerPath);
        $temporary = tempnam($directory, 'activity-');
        if ($temporary === false) {
            throw new \RuntimeException("Unable to write {$this->markerPath}.");
        }
        try {
            if (\dirname($temporary) !== realpath($directory)) {
                throw new \RuntimeException("Unable to write {$this->markerPath} in its directory.");
            }
            $stream = fopen($temporary, 'wb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to write {$this->markerPath}.");
            }
            try {
                if (fwrite($stream, $value) !== \strlen($value) || ! fflush($stream) || ! fsync($stream)) {
                    throw new \RuntimeException("Unable to write {$this->markerPath}.");
                }
            } finally {
                fclose($stream);
            }

            return $temporary;
        } catch (\Throwable $exception) {
            unlink($temporary);
            throw $exception;
        }
    }

    public function prepareMarkerNative(string $value): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->prepareMarkerPhp($value);
        }

        $directory = \dirname($this->markerPath);
        $temporary = tempnam($directory, 'activity-');
        if ($temporary === false) {
            throw new \RuntimeException("Unable to write {$this->markerPath}.");
        }
        try {
            $tempDir = \dirname($temporary);
            $realDir = realpath($directory);
            $realDirStr = $realDir === false ? '' : $realDir;

            // @phpstan-ignore method.notFound
            $dirOk = $ffi->nntmux_downtime_verify_marker_dir(
                $tempDir,
                \strlen($tempDir),
                $realDirStr,
                \strlen($realDirStr)
            );
            if ($dirOk !== 1) {
                throw new \RuntimeException("Unable to write {$this->markerPath} in its directory.");
            }

            $stream = fopen($temporary, 'wb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to write {$this->markerPath}.");
            }
            try {
                $expectedLen = \strlen($value);
                $written = fwrite($stream, $value);
                $writtenBytes = $written === false ? -1 : $written;
                $flushed = ($writtenBytes === $expectedLen) && fflush($stream);
                $synced = $flushed && fsync($stream);

                // @phpstan-ignore method.notFound
                $writeOk = $ffi->nntmux_downtime_verify_marker_write(
                    $expectedLen,
                    $writtenBytes,
                    $flushed ? 1 : 0,
                    $synced ? 1 : 0
                );
                if ($writeOk !== 1) {
                    throw new \RuntimeException("Unable to write {$this->markerPath}.");
                }
            } finally {
                fclose($stream);
            }

            return $temporary;
        } catch (\Throwable $exception) {
            unlink($temporary);
            throw $exception;
        }
    }

    // ========================================================================
    // WAVE 58.3 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 4.72x, p99 4.25x; uncommon mean 2.53x, p99 2.85x); see RustPostProcessReleasesRunnerDowntimeCompensatorTmuxCommandW58ParityTest. */
    public const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_DOWNTIME_COMPENSATOR_FFI_CDEF = <<<'C'
        ssize_t nntmux_w58_downtime_compensator_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w58DowntimePlanBatch(). */
    public const int W58_DOWNTIME_TRAILER = 8;

    /** Largest collectionDelayHours that w58DowntimePlanBatch() extends by. */
    public const int W58_MAX_DELAY_HOURS = 168;

    public static function usesW58RustFfi(): bool
    {
        $override = self::w58RustFfiState();

        return \is_bool($override) ? $override : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the Wave 58 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 58.3 downtime compensation plans for `lastActive,now,delayHours` items (each field 1-10 ASCII
     * digits, exactly three fields). Two words per item: `class | windows << 3 | delay << 20` and the timeout
     * extension in seconds. Class: 0 malformed, 1 clock skew (now before lastActive), 2 a gap shorter than
     * MIN_DOWNTIME_SECONDS, 3 compensate and 4 compensate past MAX_WINDOWS; compensating plans hold
     * ceil(gap / WINDOW_SECONDS) windows (capped at MAX_WINDOWS) and extend by delay * 3600 + gap, with delay
     * the hours capped at W58_MAX_DELAY_HOURS (0 for malformed items). Then the five class counts, the total
     * extension, the max gap (at least 0) and the total windows.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58DowntimePlanBatch(array $items): array
    {
        return self::usesW58RustFfi() ? self::w58DowntimePlanBatchNative($items) : self::w58DowntimePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58DowntimePlanBatchNative(array $items): array
    {
        return self::w58Invoke($items, 2 * \count($items) + self::W58_DOWNTIME_TRAILER) ?? self::w58DowntimePlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w58DowntimePlanBatchPhp(array $items): array
    {
        $out = [];
        $classes = [0, 0, 0, 0, 0];
        $totalExtension = 0;
        $maxGap = 0;
        $totalWindows = 0;
        foreach ($items as $item) {
            $fields = explode(',', (string) $item);
            $class = 0;
            $windows = 0;
            $delay = 0;
            $extension = 0;
            if (\count($fields) === 3 && self::w58Digits($fields[0]) && self::w58Digits($fields[1]) && self::w58Digits($fields[2])) {
                $gap = (int) $fields[1] - (int) $fields[0];
                $delay = min((int) $fields[2], self::W58_MAX_DELAY_HOURS);
                $maxGap = max($maxGap, $gap);
                if ($gap < 0) {
                    $class = 1;
                } elseif ($gap < self::MIN_DOWNTIME_SECONDS) {
                    $class = 2;
                } else {
                    $windows = intdiv($gap + self::WINDOW_SECONDS - 1, self::WINDOW_SECONDS);
                    $class = $windows > self::MAX_WINDOWS ? 4 : 3;
                    $windows = min($windows, self::MAX_WINDOWS);
                    $extension = $delay * 3600 + $gap;
                }
            }
            $classes[$class]++;
            $totalExtension += $extension;
            $totalWindows += $windows;
            array_push($out, $class | $windows << 3 | $delay << 20, $extension);
        }
        array_push($out, ...$classes);
        array_push($out, $totalExtension, $maxGap, $totalWindows);

        return $out;
    }

    /** One to ten ASCII digits. */
    private static function w58Digits(string $value): bool
    {
        $length = \strlen($value);

        return $length >= 1 && $length <= 10 && strspn($value, '0123456789') === $length;
    }

    /**
     * Wave 58 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('categorize_w58', self::W58_DOWNTIME_COMPENSATOR_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w58RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w58RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 58.3 export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w58Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w58RustFfiState('ffi');
            $out = self::w58RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w58RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W58_DOWNTIME_COMPENSATOR_FFI_CDEF.)
            $written = $ffi->nntmux_w58_downtime_compensator_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }
}
