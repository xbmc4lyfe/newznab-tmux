<?php

declare(strict_types=1);

namespace Tests\Feature;

/** Reruns {@see NzbQueuedUploadLifecycleTest} with candidate manifests stored in the database. */
class NzbQueuedUploadLifecycleDatabaseManifestTest extends NzbQueuedUploadLifecycleTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.manifest_storage' => 'database']);
        (require database_path('migrations/2026_10_17_000000_create_release_verification_manifests_table.php'))->up();
    }
}
