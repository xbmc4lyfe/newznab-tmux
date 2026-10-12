<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Contracts;

interface CompleteCatalogueSource extends PredbFeedSource
{
    /** Number of eligible entries in the complete cached catalogue, before local paging. */
    public function acceptedCount(): int;
}
