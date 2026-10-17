<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\Nzb\NzbService;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\YencService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReleaseFingerprintStaleUpgradeTest extends TestCase
{
    #[Test]
    public function a_run_overtaken_by_an_in_place_upgrade_collects_again_instead_of_saving_old_metadata(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
        });
        (require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php'))->up();
        (require database_path('migrations/2026_10_18_000000_add_run_token_to_release_fingerprint_metadata.php'))->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => 'upgraded-partial']);
        $nzb = \Mockery::mock(NzbService::class);
        // The partial is upgraded in place while this run reads the smaller NZB.
        $reads = 0;
        $nzb->shouldReceive('readNzbContents')->twice()->with('upgraded-partial')->andReturnUsing(static function () use (&$reads): bool {
            if (++$reads === 1) {
                DB::table('release_fingerprint_metadata')->where('releases_id', 1)->update(['status' => 'stale']);
            }

            return false;
        });
        $yenc = new YencService;
        $job = (new CollectReleaseFingerprintMetadata(1))->withFakeQueueInteractions();

        $job->handle(new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc));

        $job->assertNotReleased();
        $this->assertSame(2, $reads, 'The overtaken run collected again from the upgraded NZB.');
        $this->assertSame('unavailable', DB::table('release_fingerprint_metadata')->where('releases_id', 1)->value('status'));
    }

    #[Test]
    public function a_run_whose_record_another_run_took_over_never_saves(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
        });
        (require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php'))->up();
        (require database_path('migrations/2026_10_18_000000_add_run_token_to_release_fingerprint_metadata.php'))->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => 'taken-over']);
        $nzb = \Mockery::mock(NzbService::class);
        $reads = 0;
        // The upgrade's own run takes the record over (status still processing) while this run reads the old NZB.
        $nzb->shouldReceive('readNzbContents')->twice()->with('taken-over')->andReturnUsing(static function () use (&$reads): bool {
            if (++$reads === 1) {
                DB::table('release_fingerprint_metadata')->where('releases_id', 1)->update(['status' => 'processing', 'run_token' => 'othertoken000000']);
            }

            return false;
        });
        $yenc = new YencService;

        (new CollectReleaseFingerprintMetadata(1))->withFakeQueueInteractions()
            ->handle(new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc));

        $this->assertSame(2, $reads, 'The overtaken run did not save; it collected again as the new owner.');
    }
}
