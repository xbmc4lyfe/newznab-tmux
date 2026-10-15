<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * NZB import result values used while processing imported NZB files.
 */
enum NzbImportStatus: string
{
    case Inserted = 'inserted';

    case Queued = 'queued';

    case MetadataIgnored = 'metadata_ignored';

    case MetadataDeferred = 'metadata_deferred';

    case Duplicate = 'duplicate';

    case Blacklisted = 'blacklisted';

    case NoGroup = 'nogroup';

    case Failed = 'failed';
}
