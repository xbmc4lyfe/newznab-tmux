<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Collection;
use App\Models\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionCleanupService
{
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
        $cutoff = now()->subHours(Settings::settingValue('partretentionhours'));
        $batchDeleted = 0;
        $maxRetries = 5;
        do {
            $affected = 0;
            $attempt = 0;
            do {
                try {
                    // Delete by id list derived in a nested subquery to avoid "Record has changed since last read".
                    $affected = DB::affectingStatement(
                        'DELETE FROM collections WHERE id IN (
                            SELECT id FROM (
                                SELECT id FROM collections WHERE dateadded < ? ORDER BY id LIMIT 500
                            ) AS x
                        )',
                        [$cutoff]
                    );
                    break; // success
                } catch (\Throwable $e) {
                    // Retry on lock/timeout errors
                    $attempt++;
                    if ($attempt >= $maxRetries) {
                        if ($echoCLI) {
                            cli()->error('Cleanup delete failed after retries: '.$e->getMessage());
                        }
                        break;
                    }
                    usleep(20000 * $attempt);
                }
            } while (true);

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
        // in batches via an id-subselect so we never materialise the full id set in PHP
        // and never issue per-row DELETEs.
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
     * Uses a NOT EXISTS subquery (cheap with the binaries.collections_id index) and
     * an id-subselect to avoid the multi-table JOIN DELETE that previously could
     * scan the full parts table.
     */
    private function deleteOrphanCollections(bool $echoCLI): int
    {
        $deleted = 0;
        $maxBatches = 20; // hard cap per cycle; bounded backlog drain
        $batchSize = 500;

        for ($i = 0; $i < $maxBatches; $i++) {
            $affected = 0;
            $attempt = 0;

            do {
                try {
                    $affected = DB::affectingStatement(
                        'DELETE FROM collections WHERE id IN (
                            SELECT id FROM (
                                SELECT c.id FROM collections c
                                WHERE NOT EXISTS (
                                    SELECT 1 FROM binaries b WHERE b.collections_id = c.id
                                )
                                ORDER BY c.id LIMIT '.(int) $batchSize.'
                            ) AS x
                        )'
                    );
                    break;
                } catch (\Throwable $e) {
                    $attempt++;
                    if ($attempt >= 5) {
                        if ($echoCLI) {
                            cli()->error('Orphan cleanup delete failed after retries: '.$e->getMessage());
                        }
                        return $deleted;
                    }
                    usleep(20000 * $attempt);
                }
            } while (true);

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
     * (releases.nzbstatus = 1). Batched via id-subselect so a large backlog
     * cannot stall the cycle or load every id into PHP memory.
     */
    private function deleteCollectionsMissedAfterNzb(bool $echoCLI): int
    {
        $deleted = 0;
        $maxBatches = 20;
        $batchSize = 500;

        for ($i = 0; $i < $maxBatches; $i++) {
            $affected = 0;
            $attempt = 0;

            do {
                try {
                    $affected = DB::affectingStatement(
                        'DELETE FROM collections WHERE id IN (
                            SELECT id FROM (
                                SELECT c.id FROM collections c
                                INNER JOIN releases r ON r.id = c.releases_id
                                WHERE r.nzbstatus = 1
                                ORDER BY c.id LIMIT '.(int) $batchSize.'
                            ) AS x
                        )'
                    );
                    break;
                } catch (\Throwable $e) {
                    $attempt++;
                    if ($attempt >= 5) {
                        if ($echoCLI) {
                            cli()->error('Missed-NZB cleanup delete failed after retries: '.$e->getMessage());
                        }
                        return $deleted;
                    }
                    usleep(20000 * $attempt);
                }
            } while (true);

            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
            usleep(10000);
        }

        return $deleted;
    }
}
