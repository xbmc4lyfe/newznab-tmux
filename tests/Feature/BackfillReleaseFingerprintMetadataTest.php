<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackfillReleaseFingerprintMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'nntmux.fingerprint_format' => 'compact']);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->unsignedTinyInteger('nzbstatus')->default(1);
        });
        $migration = require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php');
        $migration->up();
    }

    #[Test]
    public function it_queues_only_releases_without_an_exactly_current_fingerprint(): void
    {
        $compact = ['format' => CompactFingerprintCollector::FORMAT, 'schema_version' => CompactFingerprintCollector::SCHEMA_VERSION];
        $records = [
            1 => null,                                                     // no fingerprint yet
            2 => ['complete', $compact],                                   // current
            3 => ['partial', $compact],                                    // not complete
            4 => ['complete', ['schema_version' => '1'] + $compact],       // version stored as a string
            5 => ['complete', ['schema_version' => 1.5] + $compact],       // version stored as a float
            6 => ['complete', ['format' => []] + $compact],                // non-string format marker
            7 => ['complete', ['schema_version' => ReleaseFingerprintCollector::SCHEMA_VERSION]], // legacy full record
            8 => ['complete', ['schema_version' => 2] + $compact],         // newer version after a rollback
            9 => ['complete', $compact],                                   // current
        ];
        foreach ($records as $id => $record) {
            DB::table('releases')->insert(['id' => $id, 'guid' => 'g'.$id, 'nzbstatus' => 1]);
            if ($record !== null) {
                DB::table('release_fingerprint_metadata')->insert(['releases_id' => $id, 'status' => $record[0],
                    'metadata' => json_encode($record[1], JSON_THROW_ON_ERROR)]);
            }
        }
        DB::table('releases')->insert(['id' => 10, 'guid' => 'g10', 'nzbstatus' => 0]); // no NZB
        Queue::fake();

        Artisan::call('releases:fingerprint-backfill', ['--limit' => 100]);

        $queued = [];
        Queue::assertPushed(CollectReleaseFingerprintMetadata::class, static function (CollectReleaseFingerprintMetadata $job) use (&$queued): bool {
            $queued[] = $job->uniqueId();

            return true;
        });
        sort($queued);
        $this->assertSame(['1', '3', '4', '5', '6', '7', '8'], $queued);
    }

    #[Test]
    public function the_limit_and_resume_point_page_through_releases(): void
    {
        foreach (range(1, 5) as $id) {
            DB::table('releases')->insert(['id' => $id, 'guid' => 'g'.$id, 'nzbstatus' => 1]);
        }
        Queue::fake();

        Artisan::call('releases:fingerprint-backfill', ['--limit' => 2, '--after-id' => 1]);

        Queue::assertPushed(CollectReleaseFingerprintMetadata::class, 2);
        $this->assertStringContainsString('--after-id=3', Artisan::output());
    }
}
