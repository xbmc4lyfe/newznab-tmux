<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function __construct() {}

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
                    'Deleting collections/binaries/parts older than %d hours.',
                    Settings::settingValue('partretentionhours')
                ), true);
        }

        // Batch-delete old collections using a safe id-subselect to avoid read-then-delete races.
        // The DELETE here is single-table (no JOIN), so cross-table deadlocks
        // are not a concern; the helper still gives us bounded retries with
        // jittered backoff and re-throws non-lock errors instead of swallowing them.
        $cutoff = now()->subHours(Settings::settingValue('partretentionhours'));
        $batchDeleted = 0;
        do {
            $affected = $this->retryOnLockError(
                fn (): int => DB::affectingStatement(
                    'DELETE FROM collections WHERE id IN (
                        SELECT id FROM (
                            SELECT id FROM collections WHERE dateadded < ? ORDER BY id LIMIT 500
                        ) AS x
                    )',
                    [$cutoff]
                ),
                'Cleanup',
                $echoCLI,
            );

            $batchDeleted += $affected;
            if ($affected < 500) {
                break;
            }
            // Brief pause to reduce pressure on the lock manager in busy systems.
            usleep(10000);
        } while (true);

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
                PHP_EOL.'Removed '.number_format($deletedCount).' parts/binaries/collection rows in '.$totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        return $deletedCount;
    }

    /**
     * Delete collections that have no binaries (CBP orphans), in bounded batches.
     *
     * Uses the same two-phase pattern as deleteCollectionsMissedAfterNzb():
     * a plain NOT EXISTS SELECT against `binaries` (no row locks) followed
     * by a single-table DELETE FROM collections WHERE id IN (...). This
     * avoids cross-table lock acquisition between `collections` and
     * `binaries`, which can deadlock against concurrent BinaryHandler writes.
     */
    private function deleteOrphanCollections(bool $echoCLI): int
    {
        $deleted = 0;
        $maxBatches = 20; // hard cap per cycle; bounded backlog drain
        $batchSize = 500;

        for ($i = 0; $i < $maxBatches; $i++) {
            $ids = DB::table('collections as c')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('binaries as b')
                    ->whereColumn('b.collections_id', 'c.id'))
                ->orderBy('c.id')
                ->limit($batchSize)
                ->pluck('c.id')
                ->all();

            if ($ids === []) {
                break;
            }

            $affected = $this->retryOnLockError(
                fn (): int => DB::table('collections')->whereIn('id', $ids)->delete(),
                'Orphan cleanup',
                $echoCLI,
            );

            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
            usleep(10000);
        }

        return $deleted;
    }

    /**
     * Delete collections whose release was already turned into an NZB
     * (releases.nzbstatus = 1). Batched in two phases per iteration:
     *
     *   1. Non-locking SELECT (autocommit MVCC snapshot) to gather a small
     *      list of `collections.id` values whose joined release row has
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
        $batchSize = 500;

        for ($i = 0; $i < $maxBatches; $i++) {
            $ids = DB::table('collections as c')
                ->join('releases as r', 'r.id', '=', 'c.releases_id')
                ->where('r.nzbstatus', '=', 1)
                ->orderBy('c.id')
                ->limit($batchSize)
                ->pluck('c.id')
                ->all();

            if ($ids === []) {
                break;
            }

            $affected = $this->retryOnLockError(
                fn (): int => DB::table('collections')->whereIn('id', $ids)->delete(),
                'Missed-NZB cleanup',
                $echoCLI,
            );

            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
            usleep(10000);
        }

        return $deleted;
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
            } catch (\Throwable $e) {
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
    private function isLockError(\Throwable $e): bool
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
