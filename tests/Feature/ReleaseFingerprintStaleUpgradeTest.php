<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\YencService;
use App\Support\ReleaseArchiveNamingEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReleaseFingerprintStaleUpgradeTest extends TestCase
{
    #[Test]
    public function a_job_queued_before_upgrade_generations_existed_still_releases_its_unique_lock(): void
    {
        // The payload such a job was queued with: the release id alone.
        $payload = ['releaseId' => 42, 'connection' => 'release-fingerprint', 'queue' => 'release-fingerprint'];
        $payload = array_combine(array_map(static fn (string $key): string => $key === 'releaseId'
            ? "\0".CollectReleaseFingerprintMetadata::class."\0releaseId" : $key, array_keys($payload)), $payload);
        $job = (new \ReflectionClass(CollectReleaseFingerprintMetadata::class))->newInstanceWithoutConstructor();
        $job->__unserialize($payload);

        $this->assertSame('42', $job->uniqueId());
        $upgrade = unserialize(serialize(new CollectReleaseFingerprintMetadata(42, 'upgrade-x')));
        $this->assertSame('42:upgrade-x', $upgrade->uniqueId());
    }

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

    #[Test]
    public function a_failing_job_never_ends_a_run_that_took_the_record_over(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
        });
        DB::table('releases')->insert(['id' => 1, 'guid' => 'overlapping']);
        (require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php'))->up();
        (require database_path('migrations/2026_10_18_000000_add_run_token_to_release_fingerprint_metadata.php'))->up();
        $older = unserialize(serialize(new CollectReleaseFingerprintMetadata(1)));
        $token = (fn (): string => $this->runToken)->call($older);
        DB::table('release_fingerprint_metadata')->insert(['releases_id' => 1, 'status' => 'processing', 'run_token' => 'upgraderun000000',
            'created_at' => now(), 'updated_at' => now()]);

        $older->failed(new \RuntimeException('exhausted'));
        $this->assertSame('processing', DB::table('release_fingerprint_metadata')->value('status'), 'The upgrade run still owns it.');

        DB::table('release_fingerprint_metadata')->update(['run_token' => $token]);
        $older->failed(new \RuntimeException('exhausted'));
        $this->assertSame('failed', DB::table('release_fingerprint_metadata')->value('status'), 'Its own run is ended.');
    }

    #[Test]
    public function metadata_of_a_replacement_whose_upgrade_rolled_back_is_never_saved(): void
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
        (require database_path('migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => 'rolled-back']);
        $committed = '<nzb><file subject="one.rar"><segments><segment bytes="5" number="1">one@example.net</segment></segments></file></nzb>';
        $replacement = '<nzb><file subject="one.rar"><segments><segment bytes="5" number="1">one@example.net</segment></segments></file>'
            .'<file subject="two.rar"><segments><segment bytes="5" number="1">two@example.net</segment></segments></file></nzb>';
        config(['nntmux_nntp.server' => 'news.primary.invalid', 'nntmux_nntp.alternate_server' => '']);
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('getFingerprintServerProfile')->andReturn(['capabilities_raw' => []]);
        $nntp->shouldReceive('getFingerprintArticleByMessageID')->andThrow(new \RuntimeException('Article unavailable.'));
        DB::table('release_archive_naming_evidence')->insert(['releases_id' => 1, 'release_guid' => 'rolled-back',
            'source_sha256' => NzbSourceGuard::digest($committed), 'proof_version' => ReleaseArchiveNamingEvidence::PROOF_VERSION,
            'scope' => 'unknown', 'episode_files' => '[]', 'updated_at' => now()]);
        $nzb = \Mockery::mock(NzbService::class);
        $reads = [];
        // The first read sees a replacement whose upgrade then rolled back; the next reads the restored source.
        $nzb->shouldReceive('readNzbContents')->with('rolled-back')->andReturnUsing(static function () use (&$reads, $committed, $replacement): string {
            $reads[] = $reads === [] ? 'replacement' : 'committed';

            return count($reads) === 1 ? $replacement : $committed;
        });
        $yenc = new YencService;

        (new CollectReleaseFingerprintMetadata(1))->withFakeQueueInteractions()
            ->handle(new ReleaseFingerprintCollector($nzb, new NzbFingerprintAnalyzer($yenc), new Par2MetadataParser, $yenc));

        $this->assertSame(['replacement', 'committed'], $reads, 'The rolled-back replacement was not saved; it collected again.');
        $this->assertNotSame('processing', DB::table('release_fingerprint_metadata')->value('status'));
    }

    #[Test]
    public function a_failing_job_queued_before_run_tokens_only_ends_its_own_run(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null]);
        DB::purge();
        DB::reconnect();
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
        });
        DB::table('releases')->insert(['id' => 1, 'guid' => 'legacy']);
        (require database_path('migrations/2026_10_12_120000_create_release_fingerprint_metadata_table.php'))->up();
        (require database_path('migrations/2026_10_18_000000_add_run_token_to_release_fingerprint_metadata.php'))->up();
        // The payload such a job was queued with: the release id alone.
        $legacy = static function (): CollectReleaseFingerprintMetadata {
            $job = (new \ReflectionClass(CollectReleaseFingerprintMetadata::class))->newInstanceWithoutConstructor();
            $job->__unserialize(["\0".CollectReleaseFingerprintMetadata::class."\0releaseId" => 1, 'connection' => 'release-fingerprint', 'queue' => 'release-fingerprint']);

            return $job;
        };
        $ownToken = (fn (): string => $this->claimToken())->call($legacy());
        DB::table('release_fingerprint_metadata')->insert(['releases_id' => 1, 'status' => 'processing', 'run_token' => 'upgraderun000000',
            'created_at' => now(), 'updated_at' => now()]);

        $legacy()->failed(new \RuntimeException('exhausted'));
        $this->assertSame('processing', DB::table('release_fingerprint_metadata')->value('status'), 'The upgrade run still owns it.');

        DB::table('release_fingerprint_metadata')->update(['run_token' => $ownToken]);
        $legacy()->failed(new \RuntimeException('exhausted'));
        $this->assertSame('failed', DB::table('release_fingerprint_metadata')->value('status'), 'Its own run, claimed in an earlier attempt, is ended.');
    }
}
