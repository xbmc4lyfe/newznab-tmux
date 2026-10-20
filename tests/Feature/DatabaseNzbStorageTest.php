<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Models\Release;
use App\Services\Monitoring\Collectors\DatabaseFingerprintCollector;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Services\Monitoring\PrometheusMetricsExporter;
use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\ReleaseImageService;
use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use App\Services\Releases\Fingerprint\FingerprintDispatch;
use App\Services\Releases\Verification\AuditReleaseDeletion;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class DatabaseNzbStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['nntmux.nzb_storage' => 'database', 'nntmux.fingerprint_worker' => 'rust']);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid', 40)->unique();
            $table->unsignedTinyInteger('nzbstatus')->default(1);
        });
        foreach (['2026_10_12_120000_create_release_fingerprint_metadata_table.php',
            '2026_10_18_000000_add_run_token_to_release_fingerprint_metadata.php',
            '2026_10_16_010000_create_release_archive_naming_evidence_table.php',
            '2026_10_20_200000_create_database_fingerprint_storage.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        DB::table('releases')->insert(['id' => 1, 'guid' => 'db-nzb-one']);
        $nzb = Mockery::mock(NzbService::class)->makePartial();
        $this->app->instance(NzbService::class, $nzb);
    }

    private function release(): object
    {
        return (object) ['id' => 1, 'guid' => 'db-nzb-one'];
    }

    public function test_database_roundtrip_also_queues_rust_work_without_dispatching_php(): void
    {
        Queue::fake();
        $xml = '<nzb><head><meta type="password">x&amp;y</meta></head></nzb>';
        $digest = app(DatabaseNzbStore::class)->put($this->release(), $xml);
        self::assertSame($xml, app(NzbService::class)->readNzbContents('db-nzb-one'));
        self::assertTrue(app(NzbService::class)->hasNzb('db-nzb-one'));
        self::assertFalse(app(NzbService::class)->nzbPath('db-nzb-one'));
        self::assertSame(NzbSourceGuard::digest($xml), $digest);
        self::assertSame($digest, DB::table('release_fingerprint_jobs')->value('source_digest'));
        self::assertSame('pending', DB::table('release_fingerprint_jobs')->value('status'));
        FingerprintDispatch::enqueue(1);
        (new CollectReleaseFingerprintMetadata(1))->handle();
        self::assertSame(1, DB::table('release_fingerprint_jobs')->count());
        Queue::assertNothingPushed();
    }

    public function test_rollback_restores_source_metadata_evidence_and_claim_together(): void
    {
        $store = app(DatabaseNzbStore::class);
        $original = $store->put($this->release(), '<nzb>original</nzb>');
        DB::table('release_fingerprint_jobs')->update(['status' => 'processing', 'lease_token' => str_repeat('a', 32), 'lease_until' => now()->addMinutes(2)]);
        try {
            DB::transaction(function () use ($store): void {
                $store->put($this->release(), '<nzb>replacement</nzb>');
                self::assertNull(DB::table('release_fingerprint_jobs')->value('lease_token'));
                throw new RuntimeException('publication failed');
            });
            self::fail('Expected transaction rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame('publication failed', $exception->getMessage());
        }
        self::assertSame('<nzb>original</nzb>', $store->read('db-nzb-one'));
        self::assertSame($original, DB::table('release_archive_naming_evidence')->value('source_sha256'));
        self::assertSame(str_repeat('a', 32), DB::table('release_fingerprint_jobs')->value('lease_token'));
    }

    public function test_replacement_fences_an_existing_claim_and_marks_old_metadata_stale(): void
    {
        $store = app(DatabaseNzbStore::class);
        $store->put($this->release(), '<nzb>old</nzb>');
        DB::table('release_fingerprint_metadata')->insert(['releases_id' => 1, 'status' => 'complete', 'run_token' => 'old']);
        DB::table('release_fingerprint_jobs')->update(['status' => 'processing', 'attempts' => 2, 'lease_token' => str_repeat('a', 32), 'lease_until' => now()->addMinutes(2)]);
        $digest = $store->put($this->release(), '<nzb>new</nzb>');
        $job = DB::table('release_fingerprint_jobs')->first();
        self::assertSame('pending', $job->status);
        self::assertSame(0, $job->attempts);
        self::assertNull($job->lease_token);
        self::assertSame($digest, $job->source_digest);
        self::assertSame('stale', DB::table('release_fingerprint_metadata')->value('status'));
    }

    public function test_same_source_and_backfill_cannot_steal_an_active_claim(): void
    {
        $store = app(DatabaseNzbStore::class);
        $store->put($this->release(), '<nzb>same</nzb>');
        $generation = DB::table('release_nzb_contents')->value('generation');
        DB::table('release_fingerprint_jobs')->update(['status' => 'processing', 'lease_token' => str_repeat('b', 32), 'lease_until' => now()->addMinutes(2)]);
        $store->put($this->release(), '<nzb>same</nzb>');
        app(DatabaseFingerprintQueue::class)->enqueue(1, true);
        self::assertSame(str_repeat('b', 32), DB::table('release_fingerprint_jobs')->value('lease_token'));
        self::assertSame($generation, DB::table('release_nzb_contents')->value('generation'));
    }

    public function test_a_repair_with_an_old_digest_cannot_replace_a_new_source(): void
    {
        app(DatabaseNzbStore::class)->put($this->release(), '<nzb>new</nzb>');
        try {
            DB::transaction(fn () => app(DatabaseNzbStore::class)->putLocked($this->release(), gzencode('<nzb>repair</nzb>'), NzbSourceGuard::digest('<nzb>old</nzb>')));
            self::fail('Stale repair must fail.');
        } catch (RuntimeException) {
            self::assertSame('<nzb>new</nzb>', app(DatabaseNzbStore::class)->read('db-nzb-one'));
        }
    }

    public function test_bounded_reader_and_integrity_validation_reject_bad_sources(): void
    {
        $store = app(DatabaseNzbStore::class);
        $store->put($this->release(), '<nzb>payload</nzb>');
        self::assertFalse($store->read('db-nzb-one', 1, 100));
        self::assertFalse($store->read('db-nzb-one', 1000, 1));
        self::assertFalse(DatabaseNzbStore::decode(gzencode(str_repeat('x', 20000)), 1000));
        self::assertFalse(DatabaseNzbStore::decode(gzencode('x').'trailing', 1000));
        self::assertFalse(DatabaseNzbStore::decode(substr(gzencode('x'), 0, -2), 1000));
        DB::table('release_nzb_contents')->update(['source_digest' => str_repeat('0', 64)]);
        $this->expectException(RuntimeException::class);
        $store->read('db-nzb-one');
    }

    public function test_verified_publication_never_asks_for_a_filesystem_path(): void
    {
        $manifest = ['expected_files' => 1, 'fromname' => 'poster', 'date' => '2026-10-20', 'groups' => ['alt.test'],
            'files' => [['subject' => '"file.bin" yEnc', 'totalparts' => 1, 'segments' => [['number' => 1, 'bytes' => 10, 'messageid' => 'fixture@test']]]]];
        $writer = new VerifiedNzbWriter(app(NzbService::class));
        $path = $writer->write($manifest, 'db-nzb-one');
        self::assertSame('database-nzb:db-nzb-one', $path);
        self::assertSame($writer->document($manifest), app(DatabaseNzbStore::class)->read('db-nzb-one'));
        $source = (new NzbSourceGuard(app(NzbService::class)))->snapshot('db-nzb-one');
        self::assertSame($writer->document($manifest), $source['contents']);
    }

    public function test_audited_deletion_cannot_delete_a_republication_with_the_same_guid(): void
    {
        $store = app(DatabaseNzbStore::class);
        $store->put($this->release(), '<nzb>same</nzb>');
        $guard = new NzbSourceGuard(app(NzbService::class));
        $receipt = DB::transaction(function () use ($guard): array {
            $receipt = $guard->captureDeletionAnchor($this->release());
            DB::table('releases')->where('id', 1)->delete();

            return $receipt;
        });
        self::assertSame(0, DB::table('release_nzb_contents')->count());
        self::assertSame(0, DB::table('release_fingerprint_jobs')->count());
        DB::table('releases')->insert(['id' => 2, 'guid' => 'db-nzb-one']);
        $store->put((object) ['id' => 2, 'guid' => 'db-nzb-one'], '<nzb>same</nzb>');
        self::assertTrue($guard->deleteAnchoredSource($receipt));
        self::assertSame('<nzb>same</nzb>', $store->read('db-nzb-one'));
    }

    public function test_import_is_resumable_and_does_not_remove_legacy_files(): void
    {
        $directory = sys_get_temp_dir().'/nzb-db-import-'.bin2hex(random_bytes(8));
        mkdir($directory.'/d', 0700, true);
        $file = $directory.'/d/db-nzb-one.nzb.gz';
        file_put_contents($file, gzencode('<nzb>imported</nzb>'));
        try {
            $this->artisan('nzbs:import-database', ['--root' => [$directory], '--limit' => 1])->assertExitCode(0);
            self::assertSame('<nzb>imported</nzb>', app(DatabaseNzbStore::class)->read('db-nzb-one'));
            self::assertFileExists($file);
            self::assertSame(1, DB::table('nzb_storage_imports')->value('last_release_id'));
            $this->artisan('nzbs:import-database', ['--root' => [$directory], '--limit' => 1])->assertExitCode(0);
            self::assertSame(0, DB::table('release_fingerprint_jobs')->count());
        } finally {
            unlink($file);
            rmdir($directory.'/d');
            rmdir($directory);
        }
    }

    private function legacyPayload(int $releaseId): string
    {
        return json_encode(['displayName' => CollectReleaseFingerprintMetadata::class,
            'data' => ['command' => serialize(new CollectReleaseFingerprintMetadata($releaseId))]], JSON_THROW_ON_ERROR);
    }

    private function redisMock(): MockInterface
    {
        $redis = Mockery::mock(PhpRedisConnection::class);
        $manager = Mockery::mock(RedisManager::class);
        $manager->shouldReceive('connection')->with('default')->andReturn($redis);
        $this->app->instance(RedisManager::class, $manager);

        return $redis;
    }

    public function test_legacy_payload_is_removed_only_after_its_database_job_commits(): void
    {
        app(DatabaseNzbStore::class)->put($this->release(), '<nzb>backlog</nzb>');
        DB::table('release_fingerprint_jobs')->delete();
        $payload = $this->legacyPayload(1);
        $redis = $this->redisMock();
        $redis->shouldReceive('zcard')->with('queues:release-fingerprint:reserved')->once()->andReturn(0);
        $redis->shouldReceive('lindex')->with('queues:release-fingerprint', 0)->twice()->andReturn($payload, null);
        $redis->shouldReceive('zrange')->with('queues:release-fingerprint:delayed', 0, 0)->once()->andReturn([]);
        $redis->shouldReceive('lrem')->with('queues:release-fingerprint', 1, $payload)->once()->andReturnUsing(function (): int {
            self::assertSame(0, DB::transactionLevel());
            self::assertSame('pending', DB::table('release_fingerprint_jobs')->where('release_id', 1)->value('status'));

            return 1;
        });
        $this->artisan('releases:fingerprint-transfer')->assertExitCode(0);
    }

    public function test_transfer_keeps_a_legacy_payload_when_its_nzb_has_not_been_imported(): void
    {
        $redis = $this->redisMock();
        $redis->shouldReceive('zcard')->once()->andReturn(0);
        $redis->shouldReceive('lindex')->once()->andReturn($this->legacyPayload(1));
        $redis->shouldNotReceive('lrem');
        $this->artisan('releases:fingerprint-transfer')->assertExitCode(1);
        self::assertSame(0, DB::table('release_fingerprint_jobs')->count());
    }

    public function test_transfer_refuses_to_race_an_in_flight_php_job(): void
    {
        $redis = $this->redisMock();
        $redis->shouldReceive('zcard')->once()->andReturn(1);
        $redis->shouldNotReceive('lindex');
        $this->artisan('releases:fingerprint-transfer')->assertExitCode(1);
    }

    public function test_dry_run_checks_both_ready_and_delayed_payloads_without_mutating_them(): void
    {
        DB::table('releases')->insert(['id' => 2, 'guid' => 'db-nzb-two']);
        app(DatabaseNzbStore::class)->put($this->release(), '<nzb>one</nzb>');
        app(DatabaseNzbStore::class)->put((object) ['id' => 2, 'guid' => 'db-nzb-two'], '<nzb>two</nzb>');
        DB::table('release_fingerprint_jobs')->delete();
        $redis = $this->redisMock();
        $redis->shouldReceive('zcard')->once()->andReturn(0);
        $redis->shouldReceive('lindex')->with('queues:release-fingerprint', 0)->once()->andReturn($this->legacyPayload(1));
        $redis->shouldReceive('lindex')->with('queues:release-fingerprint', 1)->once()->andReturn(null);
        $redis->shouldReceive('zrange')->with('queues:release-fingerprint:delayed', 0, 0)->once()->andReturn([$this->legacyPayload(2)]);
        $redis->shouldNotReceive('lrem');
        $redis->shouldNotReceive('zrem');
        $this->artisan('releases:fingerprint-transfer', ['--dry-run' => true, '--limit' => 2])->assertExitCode(0);
        self::assertSame(0, DB::table('release_fingerprint_jobs')->count());
    }

    public function test_failed_import_keeps_its_checkpoint_before_the_missing_source(): void
    {
        DB::table('releases')->insert(['id' => 2, 'guid' => 'db-nzb-two']);
        $directory = sys_get_temp_dir().'/nzb-db-resume-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/db-nzb-one.nzb.gz', gzencode('<nzb>one</nzb>'));
        try {
            $this->artisan('nzbs:import-database', ['--root' => [$directory]])->assertExitCode(1);
            self::assertSame(1, DB::table('nzb_storage_imports')->value('last_release_id'));
            file_put_contents($directory.'/db-nzb-two.nzb.gz', gzencode('<nzb>two</nzb>'));
            $this->artisan('nzbs:import-database', ['--root' => [$directory]])->assertExitCode(0);
            self::assertSame(2, DB::table('release_nzb_contents')->count());
            self::assertSame(2, DB::table('nzb_storage_imports')->value('last_release_id'));
        } finally {
            @unlink($directory.'/db-nzb-one.nzb.gz');
            @unlink($directory.'/db-nzb-two.nzb.gz');
            rmdir($directory);
        }
    }

    public function test_database_queue_monitoring_reports_work_and_shared_connection_slots(): void
    {
        app(DatabaseNzbStore::class)->put($this->release(), '<nzb>metrics</nzb>');
        DB::table('release_fingerprint_connections')->where('provider', 'primary')->where('slot', 1)
            ->update(['lease_token' => str_repeat('c', 32), 'lease_until' => now()->addMinute()]);
        $exporter = new PrometheusMetricsExporter([
            app(DatabaseFingerprintCollector::class),
        ], new TextFormatter);
        $text = $exporter->render();
        self::assertStringContainsString('nntmux_fingerprint_jobs{status="pending"} 1', $text);
        self::assertStringContainsString('nntmux_fingerprint_connection_slots{provider="primary"} 32', $text);
        self::assertStringContainsString('nntmux_fingerprint_connections_active{provider="primary"} 1', $text);
        self::assertStringContainsString('nntmux_metrics_collector_errors{collector="DatabaseFingerprintCollector"} 0', $text);
    }

    public function test_rust_currency_uses_its_compact_contract_even_with_a_legacy_full_format_setting(): void
    {
        config(['nntmux.fingerprint_format' => 'full']);
        self::assertSame('compact-v1', CollectReleaseFingerprintMetadata::currentFormat());
        self::assertSame(1, CollectReleaseFingerprintMetadata::currentSchemaVersion());
    }

    public function test_audit_deletion_and_artifact_cleanup_accept_a_database_source_receipt(): void
    {
        app(DatabaseNzbStore::class)->put($this->release(), '<nzb>audit</nzb>');
        Schema::create('release_verification_scratch', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('audit_release_id');
            $table->boolean('terminal')->default(false);
            $table->string('audit_verdict');
            $table->string('delete_guid')->nullable();
            $table->text('delete_paths')->nullable();
        });
        DB::table('release_verification_scratch')->insert(['id' => 1, 'audit_release_id' => 1, 'audit_verdict' => 'unrepairable']);
        $images = Mockery::mock(ReleaseImageService::class);
        $prefix = sys_get_temp_dir().'/unused-db-audit-image-'.bin2hex(random_bytes(16));
        $images->shouldReceive('releaseFiles')->once()->with('db-nzb-one')->andReturn(array_map(static fn (int $n): string => $prefix.'-'.$n, range(1, 8)));
        $deletion = new AuditReleaseDeletion(app(NzbService::class), $images);
        DB::transaction(function () use ($deletion): void {
            $deletion->commit(Release::query()->findOrFail(1), 1);
            DB::table('release_verification_scratch')->where('id', 1)->update(['terminal' => true]);
        });
        self::assertFalse(DB::table('release_nzb_contents')->exists());
        self::assertFalse(DB::table('release_fingerprint_jobs')->exists());
        Search::shouldReceive('deleteRelease')->once()->with(1);
        $entry = DB::table('release_verification_scratch')->first();
        $inventory = json_decode($entry->delete_paths, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('database-nzb:db-nzb-one', $inventory['paths'][0]);
        $deletion->cleanup($entry);
    }

    public function test_legacy_missing_file_cleanup_cannot_delete_releases_during_database_import(): void
    {
        self::assertFalse(app(DatabaseNzbStore::class)->exists('db-nzb-one'));
        $this->artisan('nntmux:nzbclean', ['--notondisk' => true, '--delete' => true])->assertExitCode(1);
        self::assertTrue(DB::table('releases')->where('id', 1)->exists());
    }

    public function test_unimported_database_source_cannot_qualify_for_audit_deletion(): void
    {
        $deletion = new AuditReleaseDeletion(app(NzbService::class), Mockery::mock(ReleaseImageService::class));
        try {
            DB::transaction(fn () => $deletion->commit(Release::query()->findOrFail(1), 1));
            self::fail('An unimported source must never qualify a release for deletion.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unimported database NZB', $exception->getMessage());
        }
        self::assertTrue(DB::table('releases')->where('id', 1)->exists());
    }

    public function test_legacy_upgrade_generation_callback_does_not_requeue_a_finished_rust_job(): void
    {
        app(DatabaseNzbStore::class)->put($this->release(), '<nzb>upgrade</nzb>');
        DB::table('release_fingerprint_jobs')->where('release_id', 1)->update(['status' => 'complete']);
        FingerprintDispatch::enqueue(1, 'upgrade-candidate-123');
        self::assertSame('complete', DB::table('release_fingerprint_jobs')->where('release_id', 1)->value('status'));
        FingerprintDispatch::enqueue(1, '', true);
        self::assertSame('pending', DB::table('release_fingerprint_jobs')->where('release_id', 1)->value('status'));
    }

    public function test_database_job_availability_uses_utc_when_the_application_timezone_differs(): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            app(DatabaseNzbStore::class)->put($this->release(), '<nzb>utc</nzb>');
            self::assertSame(now('UTC')->format('Y-m-d H:i:s'), DB::table('release_fingerprint_jobs')->value('available_at'));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_legacy_reads_are_explicit_read_only_and_database_content_wins(): void
    {
        $root = sys_get_temp_dir().'/nzb-cutover-'.bin2hex(random_bytes(8));
        mkdir($root.'/d', 0700, true);
        $path = $root.'/d/db-nzb-one.nzb.gz';
        file_put_contents($path, gzencode('<nzb>legacy</nzb>'));
        $nzb = app(NzbService::class);
        foreach (['siteNzbPaths' => [$root.'/'], 'siteNzbPath' => $root.'/', 'nzbSplitLevel' => 1] as $property => $value) {
            (new \ReflectionProperty(NzbService::class, $property))->setValue($nzb, $value);
        }
        try {
            self::assertFalse($nzb->readNzbContents('db-nzb-one'));
            config(['nntmux.nzb_legacy_reads' => true]);
            self::assertSame('<nzb>legacy</nzb>', $nzb->readNzbContents('db-nzb-one'));
            self::assertTrue(app(DatabaseFingerprintQueue::class)->enqueue(1, importLegacy: true));
            self::assertSame('<nzb>legacy</nzb>', app(DatabaseNzbStore::class)->read('db-nzb-one'));
            self::assertSame('pending', DB::table('release_fingerprint_jobs')->value('status'));
            app(DatabaseNzbStore::class)->put($this->release(), '<nzb>replacement</nzb>');
            self::assertSame('<nzb>replacement</nzb>', $nzb->readNzbContents('db-nzb-one'));
            self::assertSame('<nzb>legacy</nzb>', gzdecode(file_get_contents($path)));
            config(['nntmux.nzb_legacy_reads' => false]);
            self::assertSame('<nzb>replacement</nzb>', $nzb->readNzbContents('db-nzb-one'));
        } finally {
            unlink($path);
            rmdir($root.'/d');
            rmdir($root);
        }
    }
}
