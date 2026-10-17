<?php

declare(strict_types=1);

namespace Tests\Feature;

/** Reruns {@see OccupiedManifestImportCustodyTest} with candidate manifests stored in the database. */
class OccupiedManifestImportCustodyDatabaseManifestTest extends OccupiedManifestImportCustodyTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.manifest_storage' => 'database']);
        (require database_path('migrations/2026_10_17_000000_create_release_verification_manifests_table.php'))->up();
        (require database_path('migrations/2026_10_17_120000_create_release_verification_locks_table.php'))->up();
        (require database_path('migrations/2026_10_17_180000_add_release_verification_publication_stripes.php'))->up();
    }
}
