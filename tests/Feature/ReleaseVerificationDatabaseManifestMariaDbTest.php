<?php

declare(strict_types=1);

namespace Tests\Feature;

/** Reruns {@see ReleaseVerificationMariaDbTest} on MariaDB with candidate manifests stored in the database. */
class ReleaseVerificationDatabaseManifestMariaDbTest extends ReleaseVerificationMariaDbTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.manifest_storage' => 'database']);
        foreach (['2026_10_17_000000_create_release_verification_manifests_table', '2026_10_17_120000_create_release_verification_locks_table',
            '2026_10_17_180000_add_release_verification_publication_stripes'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
    }
}
