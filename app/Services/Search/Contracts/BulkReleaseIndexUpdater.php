<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

/**
 * A search driver that can refresh many release documents at once, producing
 * exactly the documents that updateRelease() would build one at a time.
 */
interface BulkReleaseIndexUpdater
{
    /**
     * Refresh the index documents for these release ids from the database.
     *
     * Releases that no longer exist are removed from the index.
     *
     * @param  list<int>  $releaseIds
     */
    public function updateReleases(array $releaseIds): void;
}
