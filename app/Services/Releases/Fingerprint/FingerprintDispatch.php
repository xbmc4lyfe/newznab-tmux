<?php

declare(strict_types=1);

namespace App\Services\Releases\Fingerprint;

use App\Jobs\CollectReleaseFingerprintMetadata;
use RuntimeException;

final class FingerprintDispatch
{
    public static function enqueue(int $releaseId, string $generation = '', bool $force = false): void
    {
        if (config('nntmux.fingerprint_worker', 'rust') === 'rust') {
            if (! app(DatabaseFingerprintQueue::class)->enqueue($releaseId, $force)) {
                throw new RuntimeException('Fingerprint work has no database NZB source.');
            }

            return;
        }
        CollectReleaseFingerprintMetadata::dispatch($releaseId, $generation);
    }
}
