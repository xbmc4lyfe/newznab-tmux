<?php

declare(strict_types=1);

// Laravel merges this connection with its framework queue defaults.
return [
    'connections' => [
        'release-fingerprint' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'release-fingerprint',
            // Keep reservations beyond the 1800s job and 1860s supervisor limits.
            'retry_after' => max(1920, (int) env('RELEASE_FINGERPRINT_QUEUE_RETRY_AFTER', 1920)),
            'block_for' => null,
            'after_commit' => true,
        ],
    ],
];
