<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CollectionFileCheckStatus;
use App\Services\Binaries\BinariesConfig;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\OversizedCollectionDeferrals;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class CollectionCleanupService
{
    /**
     * Maximum number of retries for a lock-related DB error before giving up.
     */
    private const LOCK_RETRY_MAX = 5;

    /**
     * SQLSTATE returned by InnoDB on deadlock (1213).
     */
    private const SQLSTATE_DEADLOCK = '40001';

    /**
     * MySQL/MariaDB driver error codes we treat as transient lock contention
     * and therefore safe to retry: 1213 = deadlock, 1205 = lock wait timeout.
     *
     * @var int[]
     */
    private const LOCK_DRIVER_CODES = [1213, 1205];

    /**
     * Cache lock that keeps the global orphan sweep to one worker at a time.
     */
    public const ORPHAN_SWEEP_LOCK = 'cbp:orphan-sweep:lock';

    /**
     * Cache key holding the collection id the next orphan sweep starts after.
     */
    public const ORPHAN_SWEEP_CURSOR = 'cbp:orphan-sweep:cursor';

    public const VERIFICATION_OLD_SWEEP_LOCK = 'cbp:verification-old-sweep:lock';

    public const VERIFICATION_OLD_SWEEP_CURSOR = 'cbp:verification-old-sweep:cursor';

    private const VERIFICATION_OLD_SWEEP_MAX_CANDIDATES = 25;

    /**
     * Lock lifetime; a crashed sweeper releases the sweep after this long.
     */
    private const ORPHAN_SWEEP_LOCK_SECONDS = 600;

    /**
     * Hard cap on delete batches per sweep so a dense orphan window stays bounded.
     */
    private const ORPHAN_SWEEP_MAX_BATCHES = 20;

    private const RETENTION_BAND_LOCK = 'cbp:retention-band:lock';

    private const RETENTION_BAND_CURSOR = 'cbp:retention-band:cursor';

    private const RETENTION_BAND_MAX_PAGES = 20;

    private ?bool $cascadeDeleteReady = null;

    private readonly BinariesConfig $binariesConfig;

    public function __construct(?BinariesConfig $binariesConfig = null)
    {
        $this->binariesConfig = $binariesConfig ?? BinariesConfig::fromSettings();
    }

    /**
     * Deletes finished/old collections, cleans orphans, and removes collections missed after NZB creation.
     * Mirrors the previous ProcessReleases::deleteCollections logic.
     *
     * @return int total deleted rows across operations (approximate)
     */
    public function deleteFinishedAndOrphans(bool $echoCLI): int
    {
        $startTime = now()->toImmutable();
        $deletedCount = 0;

        if ($echoCLI) {
            echo cli()->header('Process Releases -> Delete finished collections.'.PHP_EOL).
                cli()->primary(sprintf(
                    config('release_verification.required', true)
                        ? 'Deleting collections/binaries/parts older than %d hours.'
                        : 'Deleting collections/binaries/parts older than %d hours (up to twice that while still owed processing).',
                    app(ConfigurationProvider::class)->ingestion()->partRetentionHours
                ), true);
        }

        // Batch-delete old collections using select-then-delete so we can
        // explicitly remove parts/binaries/collections even when FK cascades
        // are not present in the runtime schema.
        $retentionHours = app(ConfigurationProvider::class)->ingestion()->partRetentionHours;
        $cutoff = now()->subHours($retentionHours);
        $batchDeleted = 0;
        $verificationRequired = (bool) config('release_verification.required', true);
        if ($verificationRequired) {
            $batchDeleted = $this->deleteOldVerificationWindow($cutoff, $echoCLI);
        } else {
            // Collections still waiting for reconciliation or release creation are owed an attempt
            // that a capped release pass may not have reached yet, so they get one more retention period.
            $readyCutoff = now()->subHours(2 * $retentionHours);
            do {
                $ids = DB::table('collections')
                    ->where(static fn ($age) => $age->where('dateadded', '<', $readyCutoff)
                        ->orWhere(static fn ($legacy) => $legacy->whereNull('dateadded')->where('added', '<', $readyCutoff)))
                    ->orderBy('id')
                    ->limit($this->sqlChunkSize())
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                $affected = $this->deleteCollectionsAndDescendants($ids, 'Cleanup', $echoCLI);

                $batchDeleted += $affected;
                if ($affected < $this->sqlChunkSize()) {
                    break;
                }
                // Brief pause to reduce pressure on the lock manager in busy systems.
                usleep(10000);
            } while (true);
            $batchDeleted += $this->deleteRetentionBandExceptReady($readyCutoff, $cutoff, $echoCLI);
        }

        $deletedCount += $batchDeleted;

        if ($echoCLI) {
            $elapsed = now()->diffInSeconds($startTime, true);
            cli()->primary(
                'Finished deleting '.$batchDeleted.' old collections/binaries/parts in '.
                $elapsed.Str::plural(' second', (int) $elapsed),
                true
            );
        }

        // Prune orphaned collections (no binaries) every run, but bounded so a large
        // backlog cannot stall the cycle or exhaust memory. Subsequent runs will keep
        // chipping away until the backlog is gone.
        if ($echoCLI) {
            echo cli()->header('Process Releases -> Remove CBP orphans.'.PHP_EOL).
                cli()->primary('Deleting orphaned collections.', true);
        }

        $orphanDeleted = $this->deleteOrphanCollections($echoCLI);
        $deletedCount += $orphanDeleted;

        if ($echoCLI) {
            $totalTime = now()->diffInSeconds($startTime, true);
            cli()->primary(
                'Finished deleting '.$orphanDeleted.' orphaned collections in '.
                $totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        // Collections whose release has already been NZB'd are dead weight; drop them
        // in bounded batches via a non-locking SELECT-then-single-table-DELETE so we
        // never materialise the full id set in PHP, never issue per-row DELETEs, and
        // never form a cross-table lock cycle with NzbService::writeNzbForReleaseId().
        if ($echoCLI) {
            cli()->primary('Deleting collections that were missed after NZB creation.', true);
        }

        $missedDeleted = $this->deleteCollectionsMissedAfterNzb($echoCLI);
        $deletedCount += $missedDeleted;

        $totalTime = now()->diffInSeconds($startTime, true);

        if ($echoCLI) {
            cli()->primary(
                'Finished deleting '.$missedDeleted.' collections missed after NZB creation in '.($totalTime).Str::plural(' second', (int) $totalTime).
                PHP_EOL.'Removed '.number_format($deletedCount).' collections (with related binaries/parts) in '.$totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        return $deletedCount;
    }

    /**
     * Delete collections between the two retention cutoffs unless reconciliation or release
     * creation still owes them an attempt. The kept rows stay in this band, so it is walked in dateadded order from a stored
     * position, a bounded number of pages per call, and each kept row is read once per walk.
     */
    private function deleteRetentionBandExceptReady(DateTimeInterface $readyCutoff, DateTimeInterface $cutoff, bool $echoCLI): int
    {
        // Every release child runs cleanup; one walker at a time keeps the cursor moving forward.
        $lock = Cache::lock(self::RETENTION_BAND_LOCK, self::ORPHAN_SWEEP_LOCK_SECONDS);
        if (! $lock->get()) {
            return 0;
        }
        try {
            // The statuses the reconciliation walk revisits, and sized rows waiting for creation.
            $ready = [...array_map(static fn (CollectionFileCheckStatus $status): int => $status->value,
                [...CollectionFileCheckStatus::inProgress(), CollectionFileCheckStatus::Sized]), 10];
            $saved = Cache::get(self::RETENTION_BAND_CURSOR);
            [$lastDate, $lastId] = is_array($saved) && is_string($saved['dateadded'] ?? null) && is_numeric($saved['id'] ?? null)
                ? [$saved['dateadded'], (int) $saved['id']] : [null, 0];
            $limit = $this->sqlChunkSize();
            $deleted = 0;
            for ($page = 0; $page < self::RETENTION_BAND_MAX_PAGES; $page++) {
                $rows = DB::table('collections')
                    ->where('dateadded', '>=', $readyCutoff)
                    ->where('dateadded', '<', $cutoff)
                    ->when($lastDate !== null, static fn ($query) => $query->where(static fn ($after) => $after
                        ->where('dateadded', '>', $lastDate)
                        ->orWhere(static fn ($same) => $same->where('dateadded', $lastDate)->where('id', '>', $lastId))))
                    ->orderBy('dateadded')->orderBy('id')
                    ->limit($limit)
                    ->get(['id', 'dateadded', 'filecheck']);
                $ids = $rows->reject(static fn ($row): bool => \in_array((int) $row->filecheck, $ready, true))
                    ->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
                if ($ids !== []) {
                    // A release pass may have promoted a row since the page was read.
                    $ids = DB::table('collections')->whereIn('id', $ids)->whereNotIn('filecheck', $ready)
                        ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                }
                if ($ids !== []) {
                    $deleted += $this->deleteCollectionsAndDescendants($ids, 'Cleanup', $echoCLI);
                }
                if ($rows->count() < $limit) {
                    Cache::forget(self::RETENTION_BAND_CURSOR);

                    break;
                }
                $last = $rows->last();
                $lastDate = (string) $last->dateadded;
                $lastId = (int) $last->id;
                Cache::forever(self::RETENTION_BAND_CURSOR, ['dateadded' => $lastDate, 'id' => $lastId]);
            }

            return $deleted;
        } finally {
            $lock->release();
        }
    }

    /**
     * Archive stale windows, retaining failed inputs until the snapshot wraps.
     *
     * Each pass hands at most a small number of collections to verification archival, which reads
     * every header. Stale collections whose archival would only record an already expired candidate
     * are deleted directly instead, so a pass keeps paging through such windows until its time
     * budget runs out.
     */
    private function deleteOldVerificationWindow(DateTimeInterface $cutoff, bool $echoCLI): int
    {
        $lock = Cache::lock(self::VERIFICATION_OLD_SWEEP_LOCK, self::ORPHAN_SWEEP_LOCK_SECONDS);
        if (! $lock->get()) {
            return 0;
        }
        try {
            $saved = Cache::get(self::VERIFICATION_OLD_SWEEP_CURSOR);
            if (is_array($saved) && isset($saved['last_id'], $saved['max_id'])
                && is_int($saved['last_id']) && is_int($saved['max_id'])
                && $saved['last_id'] >= 0 && $saved['max_id'] > $saved['last_id']) {
                $lastId = $saved['last_id'];
                $maxId = $saved['max_id'];
            } else {
                $lastId = 0;
                $maxId = (int) DB::table('collections')->max('id');
            }
            $archiveBudget = min(self::VERIFICATION_OLD_SWEEP_MAX_CANDIDATES, $this->sqlChunkSize());
            $deadline = microtime(true) + $this->verificationSweepSeconds();
            $deleted = 0;
            do {
                $page = $this->sweepOldVerificationPage($cutoff, $lastId, $maxId, $archiveBudget, $echoCLI);
                if ($page === null) {
                    Cache::forget(self::VERIFICATION_OLD_SWEEP_CURSOR);

                    return $deleted;
                }
                [$lastId, $archived, $pageDeleted, $exhausted] = $page;
                $deleted += $pageDeleted;
                $archiveBudget -= $archived;
                if ($exhausted || $lastId >= $maxId) {
                    Cache::forget(self::VERIFICATION_OLD_SWEEP_CURSOR);

                    return $deleted;
                }
                Cache::forever(self::VERIFICATION_OLD_SWEEP_CURSOR, ['last_id' => $lastId, 'max_id' => $maxId]);
            } while ($archiveBudget > 0 && microtime(true) < $deadline);

            return $deleted;
        } finally {
            $lock->release();
        }
    }

    /**
     * Process one page after $lastId: archive at most $archiveBudget collections that still need verification,
     * delete those whose archival could only expire, and plain-delete the rest. The page ends at the last
     * collection archival was attempted for, so none still owed archival is skipped.
     *
     * @return array{0: int, 1: int, 2: int, 3: bool}|null [last id, archival attempts, deleted rows, snapshot exhausted]
     */
    private function sweepOldVerificationPage(DateTimeInterface $cutoff, int $lastId, int $maxId, int $archiveBudget, bool $echoCLI): ?array
    {
        $limit = $this->sqlChunkSize();
        $ids = DB::table('collections')->where('dateadded', '<', $cutoff)
            ->where('id', '>', $lastId)->where('id', '<=', $maxId)
            ->orderBy('id')->limit($limit)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            return null;
        }
        $exhausted = count($ids) < $limit;
        $eligible = array_fill_keys($this->verificationEligibleCollections()->whereIn('id', $ids)
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all(), true);
        $expired = array_fill_keys($this->expiredOnArchival(array_keys($eligible)), true);
        $archive = [];
        foreach ($ids as $index => $id) {
            if (! isset($eligible[$id]) || isset($expired[$id])) {
                continue;
            }
            if (count($archive) === $archiveBudget) {
                $ids = array_slice($ids, 0, $index);
                $exhausted = false;
                break;
            }
            $archive[] = $id;
        }
        // Deferred over-budget uploads keep their headers, like failed ones, without being read again.
        $kept = app(OversizedCollectionDeferrals::class)->archiveEach($archive, static function (int $id): void {
            app(CandidateRepository::class)->archive($id);
        });
        foreach ($kept as $id => $outcome) {
            if ($outcome['error'] !== null) {
                Log::warning('Verification archival failed during stale cleanup.', ['collection_id' => $id,
                    'error_type' => $outcome['error']::class] + ($outcome['retry_after'] === null ? [] : ['retry_after' => $outcome['retry_after']]));
            }
        }
        $expiredIds = array_values(array_filter($ids, static fn (int $id): bool => isset($expired[$id])));
        $plain = array_values(array_filter($ids, static fn (int $id): bool => ! isset($expired[$id]) && ! isset($kept[$id])));
        $deleted = $this->deleteExpiredOnArchival($expiredIds, $echoCLI)
            + $this->deleteCollectionsAndDescendants($plain, 'Cleanup', $echoCLI);
        // The deferral table has no foreign key, and these deletions bypass archiveEach's own cleanup.
        app(OversizedCollectionDeferrals::class)->forget([...$expiredIds, ...$plain]);

        return [$ids[array_key_last($ids)], count($archive), $deleted, $exhausted];
    }

    /**
     * Archival records a collection whose newest article is older than the candidate expiry, and that
     * has no candidate to join, as an expired candidate holding no headers and then deletes it.
     * Collection hashes are unique, so only this collection's own archival could create that candidate.
     * Legacy inserted rows keep the full archival path for their release handoff.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function expiredOnArchival(array $ids, bool $lock = false): array
    {
        if ($ids === []) {
            return [];
        }
        sort($ids);
        $expiresBefore = now()->subHours((int) config('release_verification.expiry_hours'))->format('Y-m-d H:i:s');
        $rows = DB::table('collections')
            ->when($lock && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), fn ($query) => $query->forceIndex('PRIMARY'))
            ->whereIn('id', $ids)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $sources = [];
        foreach ($rows as $row) {
            $arrival = $row->last_seen_at ?? $row->dateadded ?? $row->added ?? null;
            if ((int) $row->filecheck === CollectionFileCheckStatus::Inserted->value || ! is_string($row->collectionhash)
                || $arrival === null || Carbon::parse($arrival)->format('Y-m-d H:i:s') >= $expiresBefore) {
                continue;
            }
            $sources[bin2hex($row->collectionhash)] = (int) $row->id;
        }
        if ($sources === []) {
            return [];
        }
        foreach (DB::table('release_verification_candidates')->whereIn('source_identity', array_map('strval', array_keys($sources)))
            ->distinct()->pluck('source_identity') as $known) {
            unset($sources[$known]);
        }
        $expired = array_values($sources);
        sort($expired);

        return $expired;
    }

    /**
     * Delete collections whose archival would only expire, rechecking each under its row lock: header
     * storage locks the parent first, so a new article either landed before the recheck or waits for it.
     *
     * @param  list<int>  $ids
     */
    private function deleteExpiredOnArchival(array $ids, bool $echoCLI): int
    {
        $deleted = 0;
        foreach (array_chunk($ids, $this->sqlChunkSize()) as $chunk) {
            $deleted += $this->retryOnLockError(
                fn (): int => DB::transaction(fn (): int => $this->deleteRows($this->expiredOnArchival($chunk, lock: true))),
                'Expired stale cleanup',
                $echoCLI,
            );
        }

        return $deleted;
    }

    private function verificationSweepSeconds(): int
    {
        // Archival after the last page starts is not timed, so stay well inside the sweep lock's lifetime.
        return max(0, min(120, (int) config('nntmux.cbp.verification_sweep_seconds', 60)));
    }

    /**
     * Preserve retrievable payload even when header counts or aggregate sizes are incomplete.
     * Terminal/invalid collections and rows without any usable articles do not need a download attempt.
     */
    public function verificationEligibleCollections(): Builder
    {
        $statuses = $this->verificationStatuses();
        $supportsLegacyBacklog = $this->supportsLegacyVerification();

        return DB::table('collections')
            ->where(function (Builder $query) use ($statuses, $supportsLegacyBacklog): void {
                $query->whereIn('filecheck', $statuses);
                if ($supportsLegacyBacklog) {
                    $query->orWhere(function (Builder $legacy): void {
                        $legacy->where('filecheck', CollectionFileCheckStatus::Inserted->value)
                            ->whereExists(function (Builder $release): void {
                                $release->selectRaw('1')->from('releases as legacy_release')
                                    ->whereColumn('legacy_release.id', 'collections.releases_id')
                                    ->where('legacy_release.nzbstatus', NzbService::NZB_NONE);
                            });
                    });
                }
            })
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('binaries as b')
                    ->join('parts as p', 'p.binaries_id', '=', 'b.id')
                    ->whereColumn('b.collections_id', 'collections.id')
                    ->where('p.size', '>', 0)
                    ->where('p.messageid', '<>', '');
            });
    }

    public function verificationReadyCollections(): Builder
    {
        return $this->verificationEligibleCollections()->whereIn('filecheck', [
            CollectionFileCheckStatus::Sized->value, CollectionFileCheckStatus::Inserted->value]);
    }

    /**
     * Delete collections that have no binaries (CBP orphans), in bounded batches.
     *
     * Every per-group release job calls this, but the sweep is global, so a
     * non-blocking cache lock keeps it to one worker at a time and the others
     * skip it. Each sweep checks only one id window that resumes from a stored
     * cursor: a NOT EXISTS scan from the lowest id has to probe `binaries` for
     * every collection before it can return when there are few orphans.
     */
    private function deleteOrphanCollections(bool $echoCLI): int
    {
        $lock = Cache::lock(self::ORPHAN_SWEEP_LOCK, self::ORPHAN_SWEEP_LOCK_SECONDS);
        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->sweepOrphanWindow($echoCLI);
        } finally {
            $lock->release();
        }
    }

    /**
     * Delete orphans in the id window after the stored cursor, then advance the
     * cursor, wrapping to the start once the window reaches the highest id.
     *
     * Uses the same two-phase pattern as deleteCollectionsMissedAfterNzb():
     * a plain NOT EXISTS SELECT against `binaries` (no row locks) followed
     * by a single-table DELETE FROM collections WHERE id IN (...). This
     * avoids cross-table lock acquisition between `collections` and
     * `binaries`, which can deadlock against concurrent BinaryHandler writes.
     */
    private function sweepOrphanWindow(bool $echoCLI): int
    {
        $maxId = (int) DB::table('collections')->max('id');
        if ($maxId === 0) {
            Cache::forget(self::ORPHAN_SWEEP_CURSOR);

            return 0;
        }

        $cursor = (int) Cache::get(self::ORPHAN_SWEEP_CURSOR, 0);
        if ($cursor >= $maxId) {
            $cursor = 0;
        }
        $windowEnd = min($cursor + $this->orphanScanWindow(), $maxId);
        $batchSize = $this->sqlChunkSize();
        $deleted = 0;

        for ($i = 0; $i < self::ORPHAN_SWEEP_MAX_BATCHES; $i++) {
            $ids = DB::table('collections as c')
                ->where('c.id', '>', $cursor)
                ->where('c.id', '<=', $windowEnd)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('binaries as b')
                    ->whereColumn('b.collections_id', 'c.id'))
                ->orderBy('c.id')
                ->limit($batchSize)
                ->pluck('c.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($ids === []) {
                $cursor = $windowEnd;
                break;
            }

            $deleted += $this->deleteCollectionsAndDescendants($ids, 'Orphan cleanup', $echoCLI);

            if (count($ids) < $batchSize) {
                $cursor = $windowEnd;
                break;
            }
            $cursor = $ids[array_key_last($ids)];
            usleep(10000);
        }

        Cache::forever(self::ORPHAN_SWEEP_CURSOR, $cursor >= $maxId ? 0 : $cursor);

        return $deleted;
    }

    private function orphanScanWindow(): int
    {
        return max(1000, (int) config('nntmux.cbp.orphan_scan_window', 250000));
    }

    /**
     * Delete collections whose release was already turned into an NZB
     * (releases.nzbstatus = 1). Batched in two phases per iteration:
     *
     *   1. Non-locking SELECT (autocommit MVCC snapshot) to gather a small
     *      list of `collections.id` values whose linked release row has
     *      nzbstatus = 1. No row locks are taken on `releases`.
     *   2. Single-table DELETE FROM collections WHERE id IN (...). The DELETE
     *      never references `releases`, so the lock graph reduces to one
     *      table and concurrent NzbService transactions (which lock
     *      releases -> collections) cannot form a cross-table cycle.
     *
     * This intentionally replaces the previous single DELETE-with-JOIN
     * subselect, which caused recurring `1213 Deadlock found` errors when
     * multiple `multiprocessing:releases` workers ran in parallel against
     * `NzbService::writeNzbForReleaseId()` on the same DB.
     */
    private function deleteCollectionsMissedAfterNzb(bool $echoCLI): int
    {
        $deleted = 0;
        $maxBatches = 20;
        $batchSize = $this->sqlChunkSize();

        for ($i = 0; $i < $maxBatches; $i++) {
            // Drive from the few collections linked to a release: a join lets the
            // optimizer start from every nzbstatus = 1 release instead.
            $ids = DB::table('collections as c')
                ->whereNotNull('c.releases_id')
                ->when(config('release_verification.required', true), fn ($query) => $query
                    ->whereNotExists($this->verificationEligibleCollections()->whereColumn('collections.id', 'c.id')->selectRaw('1')))
                ->whereExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('releases as r')
                    ->whereColumn('r.id', 'c.releases_id')
                    ->where('r.nzbstatus', '=', 1))
                ->orderBy('c.id')
                ->limit($batchSize)
                ->pluck('c.id')
                ->all();

            if ($ids === []) {
                break;
            }

            $affected = $this->deleteCollectionsAndDescendants($ids, 'Missed-NZB cleanup', $echoCLI);

            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
            usleep(10000);
        }

        return $deleted;
    }

    /**
     * Explicitly delete parts, binaries, then collections for the given IDs.
     * This path does not rely on DB-level cascade constraints.
     *
     * @param  list<int>  $collectionIds
     */
    public function deleteCollectionsAndDescendants(
        array $collectionIds,
        string $label = 'CBP cleanup',
        bool $echoCLI = false,
        bool $manifestArchived = false,
    ): int {
        if ($collectionIds === []) {
            return 0;
        }

        $deletedCollections = 0;

        foreach (array_chunk($collectionIds, $this->sqlChunkSize()) as $chunk) {
            $deletedCollections += $this->retryOnLockError(
                fn (): int => DB::transaction(
                    function () use ($chunk, $manifestArchived): int {
                        if (config('release_verification.required', true) && ! $manifestArchived) {
                            $chunk = $this->deletableVerificationIds($chunk);
                        }

                        return $this->deleteRows($chunk);
                    }
                ),
                $label,
                $echoCLI,
            );
        }

        return $deletedCollections;
    }

    /**
     * Delete the given collections with their binaries and parts; the caller holds the transaction.
     *
     * @param  list<int>  $ids
     */
    private function deleteRows(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($this->cascadeDeleteReady()) {
            return (int) DB::affectingStatement("DELETE FROM collections WHERE id IN ({$placeholders})", $ids);
        }

        DB::statement(
            "DELETE FROM parts WHERE binaries_id IN (SELECT id FROM binaries WHERE collections_id IN ({$placeholders}))",
            $ids
        );
        DB::statement("DELETE FROM binaries WHERE collections_id IN ({$placeholders})", $ids);

        return (int) DB::affectingStatement("DELETE FROM collections WHERE id IN ({$placeholders})", $ids);
    }

    /** @return list<int> */
    private function verificationStatuses(): array
    {
        return [...array_map(static fn (CollectionFileCheckStatus $status): int => $status->value,
            [...CollectionFileCheckStatus::inProgress(), ...CollectionFileCheckStatus::readyForRelease()]), 10];
    }

    private function supportsLegacyVerification(): bool
    {
        return Schema::hasColumn('collections', 'releases_id') && Schema::hasTable('releases')
            && Schema::hasColumn('releases', 'nzbstatus');
    }

    /**
     * Lock parent rows before checking descendants, matching header storage's lock order.
     * Explicit locking reads see newly committed parts even within an older repeatable-read snapshot.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function deletableVerificationIds(array $ids): array
    {
        $legacySupported = $this->supportsLegacyVerification();
        $columns = ['id', 'filecheck'];
        if ($legacySupported) {
            $columns[] = 'releases_id';
        }
        $parents = DB::table('collections')
            ->when(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), fn ($query) => $query->forceIndex('PRIMARY'))
            ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get($columns);
        $statuses = $this->verificationStatuses();
        $deletable = [];
        foreach ($parents as $parent) {
            $needsVerification = in_array((int) $parent->filecheck, $statuses, true);
            if (! $needsVerification && $legacySupported && (int) $parent->filecheck === CollectionFileCheckStatus::Inserted->value) {
                $needsVerification = DB::table('releases')->where('id', $parent->releases_id)
                    ->where('nzbstatus', NzbService::NZB_NONE)->lockForUpdate()->first(['id']) !== null;
            }
            if ($needsVerification && DB::table('binaries as b')->join('parts as p', 'p.binaries_id', '=', 'b.id')
                ->where('b.collections_id', $parent->id)->where('p.size', '>', 0)->where('p.messageid', '<>', '')
                ->orderBy('b.id')->lockForUpdate()->first(['b.id']) !== null) {
                continue;
            }
            $deletable[] = (int) $parent->id;
        }

        return $deletable;
    }

    private function sqlChunkSize(): int
    {
        return $this->binariesConfig->sqlChunkSize;
    }

    /**
     * Production deletes can safely target only the parent table when both
     * descendant foreign keys cascade. SQLite fixtures and incomplete legacy
     * schemas deliberately retain the explicit descendant-delete fallback.
     */
    private function cascadeDeleteReady(): bool
    {
        if ($this->cascadeDeleteReady !== null) {
            return $this->cascadeDeleteReady;
        }
        if (DB::getDriverName() === 'sqlite') {
            return $this->cascadeDeleteReady = false;
        }

        try {
            $rows = DB::select(
                "SELECT TABLE_NAME, DELETE_RULE
                 FROM information_schema.REFERENTIAL_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME IN ('binaries', 'parts')"
            );
            $cascades = [];
            foreach ($rows as $row) {
                if (strtoupper((string) $row->DELETE_RULE) === 'CASCADE') {
                    $cascades[(string) $row->TABLE_NAME] = true;
                }
            }

            return $this->cascadeDeleteReady = isset($cascades['binaries'], $cascades['parts']);
        } catch (Throwable) {
            return $this->cascadeDeleteReady = false;
        }
    }

    /**
     * Run a DB write inside a bounded retry loop that only swallows transient
     * InnoDB lock errors (deadlock 1213, lock wait timeout 1205). Any other
     * exception is re-thrown so real failures (constraint violations, schema
     * issues, connection drops, etc.) are not silently retried.
     *
     * Backoff is `min(500ms, 20ms * attempt) + 0..25ms jitter` so concurrent
     * cleanup workers stop colliding on the exact same retry cadence.
     *
     * @param  callable():int  $op  Returns the number of rows affected by the write.
     * @param  string  $label  Human-readable label used in the CLI error message.
     * @param  bool  $echoCLI  Whether to echo a final error after exhausting retries.
     * @return int Rows affected on success, or 0 if all retries exhausted.
     */
    private function retryOnLockError(callable $op, string $label, bool $echoCLI): int
    {
        $attempt = 0;

        while (true) {
            try {
                return (int) $op();
            } catch (Throwable $e) {
                if (! $this->isLockError($e)) {
                    throw $e;
                }

                $attempt++;
                if ($attempt >= self::LOCK_RETRY_MAX) {
                    if ($echoCLI) {
                        cli()->error($label.' delete failed after retries: '.$e->getMessage());
                    }

                    return 0;
                }

                $sleepMs = min(500, 20 * $attempt) + random_int(0, 25);
                usleep($sleepMs * 1000);
            }
        }
    }

    /**
     * Determine whether the given throwable represents a transient InnoDB
     * lock error (deadlock or lock wait timeout) that is safe to retry.
     */
    private function isLockError(Throwable $e): bool
    {
        if ($e instanceof QueryException) {
            $sqlState = (string) $e->getCode();
            $driverCode = (int) ($e->errorInfo[1] ?? 0);

            if ($sqlState === self::SQLSTATE_DEADLOCK) {
                return true;
            }

            if (in_array($driverCode, self::LOCK_DRIVER_CODES, true)) {
                return true;
            }
        }

        // Some drivers surface PDOException directly; fall back to the message.
        $message = $e->getMessage();
        if (str_contains($message, 'Deadlock found')) {
            return true;
        }

        if (str_contains($message, 'Lock wait timeout exceeded')) {
            return true;
        }

        return false;
    }
}
