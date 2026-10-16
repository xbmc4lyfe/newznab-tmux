<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Facades\Search;
use App\Services\NfoService;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CapturedUploadFixture;

class MetadataRepublicationLineageTest extends ReleaseVerificationCandidatesTest
{
    use CapturedUploadFixture;

    public function test_sequential_old_admission_inherits_later_publication(): void
    {
        config(['nntmux.release_dedupe_enabled' => true, 'nntmux_settings.path_to_nzbs' => config('release_verification.storage_path').'/nzbs']);
        app('files')->ensureDirectoryExists(config('nntmux_settings.path_to_nzbs'));
        Schema::create('releases', function (Blueprint $t) {
            $t->id();
            $t->string('article_fingerprint')->nullable();
            foreach (['name', 'searchname', 'guid', 'leftguid', 'fromname', 'postdate', 'adddate'] as $c) {
                $t->string($c);
            } foreach (['totalpart', 'groups_id', 'size', 'passwordstatus', 'haspreview', 'categories_id', 'nfostatus', 'nzbstatus', 'isrenamed', 'iscategorized', 'predb_id'] as $c) {
                $t->integer($c);
            }
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        Schema::create('release_regexes', function (Blueprint $t) {
            foreach (['releases_id', 'collection_regex_id', 'naming_regex_id'] as $c) {
                $t->integer($c);
            }
        });
        Schema::create('releases_groups', function (Blueprint $t) {
            $t->integer('releases_id');
            $t->integer('groups_id');
        });
        Schema::create('release_nfos', function (Blueprint $t) {
            $t->integer('releases_id')->primary();
            $t->binary('nfo');
        });
        (require database_path('migrations/2026_10_15_034000_create_nfo_cleanup_commits_table.php'))->up();
        $this->app->instance(ReleaseMetadataResolver::class, new class(app(ReleaseCleaningService::class)) extends ReleaseMetadataResolver
        {
            public function resolve(array $manifest): array
            {
                return ['name' => 'Fixture', 'searchname' => 'Fixture', 'fromname' => 'fixture', 'predb_id' => 0, 'isrenamed' => 1, 'categories_id' => 2000, 'naming_regex_id' => 0];
            }
        });
        Search::shouldReceive('updateRelease')->never();
        $manifests = app(NzbUploadManifestService::class);
        $repo = app(CandidateRepository::class);
        $store = app(ManifestStore::class);
        $importer = $this->capturedFixtureImporter('lineage-fixture');
        $base = config('release_verification.storage_path');
        $paths = [];
        foreach (['A', 'B'] as $tag) {
            $dir = $base.'/'.$tag;
            app('files')->ensureDirectoryExists($dir);
            $paths[$tag] = $dir.'/incoming.nzb';
            file_put_contents($paths[$tag], '<nzb><head><meta type="password">assertion-'.$tag.'</meta></head><file poster="fixture" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">lineage@example.test</segment></segments></file></nzb>');
            file_put_contents($dir.'/info.nfo', 'companion-'.$tag);
            $manifests->create($dir, 'upload-'.$tag, 'incoming.nzb', 'info.nfo', 'companion-'.$tag);
        }
        $callback = static function (array $r) use ($manifests) {
            $manifests->recordNzbOutcome($r['path'], $r['status'], $r['release_id'], $r['release_guid'], candidateIdentity: $r['candidate_identity'], inputCapture: $r['input_capture']);
        };
        $importer->beginImport([$paths['A']], resultCallback: $callback);
        $a = DB::table('release_verification_candidates')->sole();
        $archivedA = $store->read($a->manifest_path);
        self::assertSame(['assertion-A'], $archivedA['passwords']);
        $retained = $archivedA;
        $retained['passwords'] = ['assertion-B'];
        $guid = 'existing-synthetic-release';
        DB::table('releases')->insert(['id' => 777, 'name' => 'Fixture', 'searchname' => 'Fixture', 'guid' => $guid, 'leftguid' => '1', 'fromname' => 'fixture', 'postdate' => '1970-01-01 00:00:01', 'adddate' => '1970-01-01 00:00:01', 'totalpart' => 1, 'groups_id' => 1, 'size' => 5, 'passwordstatus' => -1, 'haspreview' => -1, 'categories_id' => 2000, 'nfostatus' => -1, 'nzbstatus' => 1, 'isrenamed' => 1, 'iscategorized' => 1, 'predb_id' => 0]);
        app(VerifiedNzbWriter::class)->write($retained, $guid);
        $claim = $repo->claim();
        self::assertNotNull($claim);
        self::assertNull(app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'synthetic offline verification', $archivedA)));
        self::assertSame('metadata_review', DB::table('release_verification_candidates')->value('status'));
        $mp = dirname($paths['A']).'/'.NzbUploadManifestService::FILENAME;
        self::assertSame('metadata_review', $manifests->reconcilePending($mp)['state']);
        $conflicting = [];
        $importer->beginImport([$paths['B']], resultCallback: static function (array $r) use (&$conflicting): void {
            $conflicting[] = $r;
        });
        self::assertSame(NzbImportStatus::MetadataIgnored, $conflicting[0]['status']);
        self::assertNull($conflicting[0]['candidate_admission']);
        self::assertSame('metadata_review', DB::table('release_verification_candidates')->value('status'));
        self::assertSame(['assertion-A'], $store->read($a->manifest_path)['passwords']);
        DB::table('release_verification_candidates')->update(['last_unique_at' => now()->subDays(100)]);
        $repo->expire();
        self::assertSame('metadata_expired', DB::table('release_verification_candidates')->value('status'));
        $importer->beginImport([$paths['B']], resultCallback: $callback);
        $b = DB::table('release_verification_candidates')->sole();
        self::assertSame($a->identity, $b->identity);
        self::assertGreaterThan($a->version, $b->version);
        $archivedB = $store->read($b->manifest_path);
        self::assertSame(['assertion-B'], $archivedB['passwords']);
        $claim = $repo->claim();
        self::assertNotNull($claim);
        self::assertSame(777, app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'synthetic offline verification', $archivedB)));
        $resolved = $manifests->reconcilePending($mp);
        self::assertSame('metadata_review', $resolved['state']);
        self::assertNull($resolved['release_id']);
        $nfo = new NfoImportService(new Filesystem, $manifests, new NfoService);
        self::assertSame('skipped', $nfo->importManifest($mp, true, true)['status']);
        self::assertSame(0, DB::table('release_nfos')->count());
        self::assertSame(0, DB::table('nfo_cleanup_commits')->count());
        self::assertFileExists(dirname($paths['A']).'/info.nfo');
        $bManifestPath = dirname($paths['B']).'/'.NzbUploadManifestService::FILENAME;
        self::assertSame('nzb_imported', $manifests->reconcilePending($bManifestPath)['state']);
        $acceptedB = $manifests->read($bManifestPath)['accepted_capture'];
        $importer->beginImport([$paths['B']], resultCallback: $callback);
        self::assertSame($acceptedB, $manifests->read($bManifestPath)['input_capture']);
        DB::unprepared("CREATE TRIGGER fail_receipt BEFORE INSERT ON nfo_cleanup_commits BEGIN SELECT RAISE(ABORT, 'synthetic receipt failure'); END");
        self::assertSame('failed', $nfo->importManifest(dirname($paths['B']).'/'.NzbUploadManifestService::FILENAME, true, true)['status']);
        self::assertSame(0, DB::table('release_nfos')->count());
        self::assertSame(0, DB::table('nfo_cleanup_commits')->count());
        self::assertSame(-1, DB::table('releases')->where('id', 777)->value('nfostatus'));
        self::assertFileExists(dirname($paths['B']).'/info.nfo');
        DB::unprepared('DROP TRIGGER fail_receipt');
        self::assertSame('imported', $nfo->importManifest(dirname($paths['B']).'/'.NzbUploadManifestService::FILENAME, true, true)['status']);
        self::assertSame("\x1f\x8b\x08\x00".gzcompress('companion-B'), DB::table('release_nfos')->where('releases_id', 777)->value('nfo'));
        // An independently admitted identical origin may share B's revision and consume its own companion.
        $dir = $base.'/C';
        app('files')->ensureDirectoryExists($dir);
        copy($paths['B'], $dir.'/incoming.nzb');
        file_put_contents($dir.'/info.nfo', 'companion-C');
        $manifests->create($dir, 'upload-C', 'incoming.nzb', 'info.nfo', 'companion-C');
        $reports = [];
        $importer->beginImport([$dir.'/incoming.nzb'], resultCallback: static function (array $r) use (&$reports) {
            $reports[] = $r;
        });
        self::assertSame(NzbImportStatus::Queued, $reports[0]['status']);
        $bm = $manifests->read(dirname($paths['B']).'/'.NzbUploadManifestService::FILENAME);
        $cm = $manifests->read($dir.'/'.NzbUploadManifestService::FILENAME);
        self::assertSame($bm['accepted_admission'], $cm['accepted_admission']);
        self::assertNotSame($bm['accepted_capture'], $cm['accepted_capture']);
        // Legitimately relinquished admitted source retains companion authority.
        self::assertTrue($manifests->deleteCapturedInput($dir.'/incoming.nzb', $cm['accepted_capture']));
        self::assertSame('imported', $nfo->importManifest($dir.'/'.NzbUploadManifestService::FILENAME, true, true)['status']);
        self::assertSame("\x1f\x8b\x08\x00".gzcompress('companion-C'), DB::table('release_nfos')->where('releases_id', 777)->value('nfo'));
        self::assertSame(2, DB::table('nfo_cleanup_commits')->count());
        // An identical-byte replacement has a new source generation and no admission.
        $replacementDir = $base.'/D';
        app('files')->ensureDirectoryExists($replacementDir);
        copy($paths['B'], $replacementDir.'/incoming.nzb');
        file_put_contents($replacementDir.'/info.nfo', 'companion-D');
        $manifests->create($replacementDir, 'upload-D', 'incoming.nzb', 'info.nfo', 'companion-D');
        $importer->beginImport([$replacementDir.'/incoming.nzb']);
        $originalIdentity = lstat($replacementDir.'/incoming.nzb')['ino'];
        copy($replacementDir.'/incoming.nzb', $replacementDir.'/replacement.nzb');
        rename($replacementDir.'/replacement.nzb', $replacementDir.'/incoming.nzb');
        clearstatcache(true, $replacementDir.'/incoming.nzb');
        self::assertNotSame($originalIdentity, lstat($replacementDir.'/incoming.nzb')['ino']);
        self::assertSame('skipped', $nfo->importManifest($replacementDir.'/'.NzbUploadManifestService::FILENAME, true, true)['status']);
        self::assertSame(2, DB::table('nfo_cleanup_commits')->count());
        self::assertSame("\x1f\x8b\x08\x00".gzcompress('companion-C'), DB::table('release_nfos')->where('releases_id', 777)->value('nfo'));
        self::assertFileExists($replacementDir.'/info.nfo');

        self::assertSame('skipped', $nfo->importManifest($mp, true, true)['status']);
        self::assertFileExists(dirname($paths['A']).'/info.nfo');
        fwrite(STDERR, "LINEAGE_REGRESSION: A remains held; B and independent exact-origin C each consume their own companions under shared admitted revision.\n");
    }
}
