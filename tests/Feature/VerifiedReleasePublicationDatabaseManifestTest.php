<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\PublicationTiming;
use App\Services\Releases\Verification\VerificationLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Reruns {@see VerifiedReleasePublicationTest} with candidate manifests stored in the database. */
class VerifiedReleasePublicationDatabaseManifestTest extends VerifiedReleasePublicationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.manifest_storage' => 'database']);
        (require database_path('migrations/2026_10_17_000000_create_release_verification_manifests_table.php'))->up();
        (require database_path('migrations/2026_10_17_120000_create_release_verification_locks_table.php'))->up();
        (require database_path('migrations/2026_10_17_180000_add_release_verification_publication_stripes.php'))->up();
    }

    public function test_keyless_publication_reports_the_exclusive_fallback_lock_timing(): void
    {
        config(['release_verification.publication_timing_sample_every' => 1]);
        $timing = PublicationTiming::sample();
        $this->assertNotNull($timing);
        $reports = [];
        Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context) use (&$reports): void {
            $reports[] = $context;
        });

        DB::transaction(function () use ($timing): void {
            $timing->beginAttempt();
            app(VerificationLedger::class)->enterPublication([], timing: $timing);
            $timing->endAttempt(true);
        });
        $timing->report('returned');

        $lock = $reports[0]['attempts'][0]['lock'];
        $this->assertSame('exclusive', $lock['path']);
        $this->assertSame(0, $lock['stripe_count']);
        $this->assertGreaterThanOrEqual(0, $lock['stripe_lock_ns']);
        $this->assertGreaterThan(0, $lock['publication_row_lock_ns']);
        $this->assertSame('threw', $reports[0]['outcome']);
        $this->assertSame([], $reports[0]['pre_lock_phases_ns']);
    }

    public function test_a_failed_lock_statement_still_records_its_partial_lock_timing(): void
    {
        config(['release_verification.publication_timing_sample_every' => 1]);
        $timing = PublicationTiming::sample();
        $this->assertNotNull($timing);
        Schema::drop(VerificationLedger::LOCKS_TABLE);

        $timing->beginAttempt();
        try {
            app(VerificationLedger::class)->enterPublication(['a-key'], timing: $timing);
            $this->fail('The missing lock table must throw.');
        } catch (QueryException) {
        }
        $attempts = (new \ReflectionProperty($timing, 'attempts'))->getValue($timing);

        $lock = $attempts[0]['lock'];
        $this->assertFalse($lock['completed']);
        $this->assertSame('striped', $lock['path']);
        $this->assertSame(1, $lock['stripe_count']);
        $this->assertGreaterThan(0, $lock['stripe_lock_ns']);
        $this->assertSame(0, $lock['publication_row_lock_ns']);
    }
}
