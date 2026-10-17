<?php

declare(strict_types=1);

namespace Tests\Feature;

/** Reruns {@see ReleaseVerificationCandidatesTest} with candidate manifests stored in the database. */
class ReleaseVerificationCandidatesDatabaseManifestTest extends ReleaseVerificationCandidatesTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.manifest_storage' => 'database']);
        (require database_path('migrations/2026_10_17_000000_create_release_verification_manifests_table.php'))->up();
    }
}
