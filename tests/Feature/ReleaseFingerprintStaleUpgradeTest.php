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
    public function a_run_overtaken_by_an_in_place_upgrade_requeues_instead_of_saving_old_metadata(): void
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
        DB::table('releases')->insert(['id' => 1, 'guid' => 'upgraded-partial']);
        $nzb = \Mockery::mock(NzbService::class);
        // The partial is upgraded in place while this run reads the smaller NZB.
        $nzb->shouldReceive('readNzbContents')->once()->with('upgraded-partial')->andReturnUsing(static function (): bool {
            DB::table('release_fingerprint_metadata')->where('releases_id', 1)->update(['status' => 'stale']);

            return false;
        });
        $yenc = new YencService;
        $job = (new CollectReleaseFingerprintMetadata(1))->withFakeQueueInteractions();

        $job->handle(new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc));

        $job->assertReleased(30);
        $this->assertSame('stale', DB::table('release_fingerprint_metadata')->where('releases_id', 1)->value('status'));
    }
}
