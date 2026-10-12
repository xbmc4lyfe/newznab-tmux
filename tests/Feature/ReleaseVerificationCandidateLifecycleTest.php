<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Facades\Search;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReleaseVerificationCandidateLifecycleTest extends ReleaseVerificationCandidatesTest
{
    public function test_proven_unrepairable_manifest_waits_for_new_headers_without_refetching(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        $claim = $repository->claim();
        $repository->finish($claim, new VerificationResult('unrepairable', 'Insufficient validated recovery data.'));
        $this->assertSame('unrepairable', DB::table('release_verification_candidates')->value('status'));
        $this->travel(16)->minutes();
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldNotReceive('doConnect');
            $mock->shouldNotReceive('verificationArticle');
        });
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertNull($repository->claim());
        $repository->archive($this->arrival());
        $this->assertNull($repository->claim());
        $repository->archive($this->arrival('late@article', 2));
        $next = $repository->claim();
        $this->assertNotNull($next);
        $this->assertGreaterThan($claim->version, $next->version);
        $manifest = app(ManifestStore::class)->read($next->manifest_path);
        $this->assertSame(['first@article', 'late@article'], array_column($manifest['files'][0]['segments'], 'messageid'));
    }

    public function test_unknown_remains_retryable_after_the_backoff(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        $claim = $repository->claim();
        $repository->finish($claim, new VerificationResult('unknown', 'NNTP unavailable.'));
        $this->assertNull($repository->claim());
        $this->travel(16)->minutes();
        $this->assertNotNull($repository->claim());
    }

    public function test_unrepairable_expiry_releases_capacity_and_scratch(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        $claim = $repository->claim();
        $repository->finish($claim, new VerificationResult('unrepairable', 'Insufficient validated recovery data.'));
        app(ScratchInventory::class)->register((int) $claim->id, (int) $claim->version);
        $scratch = config('release_verification.storage_path').'/scratch/'.$claim->id.'-'.$claim->version;
        app('files')->ensureDirectoryExists($scratch);
        file_put_contents($scratch.'/payload', 'retained bytes');
        $this->travel(49)->hours();
        $repository->expire();
        $this->assertSame('expired', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertFileDoesNotExist(app(ManifestStore::class)->path($claim->manifest_path));
        $this->assertFileExists($scratch.'/payload');
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($scratch);
    }

    public function test_deleted_publication_can_be_reimported_and_upload_reconciles_the_new_release(): void
    {
        $this->publicationSchema();
        $repository = app(CandidateRepository::class);
        $directory = config('release_verification.storage_path').'/upload';
        app('files')->ensureDirectoryExists($directory);
        $xml = '<nzb><file subject="Release [1/1] &quot;movie.mkv&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">first@article</segment></segments></file></nzb>';
        $path = $directory.'/Release.nzb';
        file_put_contents($path, $xml);
        $manifests = new NzbUploadManifestService(new Filesystem);
        $manifests->create($directory, 'reimport', 'Release.nzb', null);
        $manifestPath = $directory.'/'.NzbUploadManifestService::FILENAME;
        $service = app(NzbImportService::class);
        $details = ['verificationXml' => $xml, 'groups_id' => 1, 'useFName' => 'Movie', 'subject' => 'Movie',
            'from' => 'poster', 'postDate' => '2026-10-11 12:00:00', 'articleFingerprint' => sha1('same-articles')];
        $insert = new \ReflectionMethod($service, 'insertNZB');
        $this->assertSame(NzbImportStatus::Queued, $insert->invoke($service, $details));
        $first = $repository->claim();
        $manifest = app(ManifestStore::class)->read($first->manifest_path);
        $firstId = app(VerifiedReleasePublisher::class)->publish($first, new VerificationResult('complete', 'Verified payload.', $manifest));
        DB::table('releases')->where('id', $firstId)->delete();
        $this->assertSame(NzbImportStatus::Queued, $insert->invoke($service, $details));
        $identity = sha1('import:'.sha1('same-articles'));
        $manifests->recordNzbOutcome($path, NzbImportStatus::Queued, null, null, candidateIdentity: $identity);
        unlink($path);
        $second = $repository->claim();
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($identity, $second->identity);
        $this->assertGreaterThan($first->version, $second->version);
        $this->assertNull($second->release_id);
        $this->assertNull($second->verified_at);
        $this->assertSame(NzbUploadManifestService::STATE_NZB_PENDING, $manifests->reconcilePending($manifestPath)['state']);
        $secondId = app(VerifiedReleasePublisher::class)->publish($second, new VerificationResult('complete', 'Verified again.', $manifest));
        $this->assertNotSame($firstId, $secondId);
        $reconciled = $manifests->reconcilePending($manifestPath);
        $this->assertSame(NzbUploadManifestService::STATE_NZB_IMPORTED, $reconciled['state']);
        $this->assertSame($secondId, $reconciled['release_id']);
        $this->assertSame(DB::table('releases')->where('id', $secondId)->value('guid'), $reconciled['release_guid']);
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_reimport_keeps_a_live_published_identity_and_does_not_allocate_capacity(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('releases')->insert(['id' => 7]);
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        $candidate = DB::table('release_verification_candidates')->first();
        $manifest = app(ManifestStore::class)->read($candidate->manifest_path);
        DB::table('release_verification_candidates')->update(['release_id' => 7, 'status' => 'complete']);
        $before = DB::table('release_verification_state')->value('pending_bytes');
        $repository->enqueue($manifest);
        $this->assertSame(7, (int) DB::table('release_verification_candidates')->value('release_id'));
        $this->assertSame($candidate->version, DB::table('release_verification_candidates')->value('version'));
        $this->assertSame($before, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertNull($repository->claim());
    }

    public function test_archiving_uses_last_unique_arrival_age_instead_of_archival_time(): void
    {
        $this->arrivalColumns();
        $id = $this->arrival();
        $arrival = now()->subHours(47)->format('Y-m-d H:i:s');
        DB::table('collections')->where('id', $id)->update(['last_seen_at' => $arrival, 'dateadded' => now()->subHours(50), 'added' => now()->subHours(51)]);
        app(CandidateRepository::class)->archive($id);
        $this->assertSame($arrival, DB::table('release_verification_candidates')->value('last_unique_at'));
        $this->travel(2)->hours();
        app(CandidateRepository::class)->expire();
        $this->assertSame('expired', DB::table('release_verification_candidates')->value('status'));
    }

    public function test_already_expired_hot_rows_are_archived_without_becoming_downloadable(): void
    {
        $this->arrivalColumns();
        $id = $this->arrival();
        DB::table('collections')->where('id', $id)->update(['dateadded' => now()->subHours(49), 'added' => now()->subHours(50)]);
        $repository = app(CandidateRepository::class);
        $repository->archive($id);
        $this->assertSame('expired', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertNull($repository->claim());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertCount(0, glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
    }

    public function test_added_time_is_the_fallback_when_last_seen_and_dateadded_are_null(): void
    {
        $this->arrivalColumns();
        $id = $this->arrival();
        $arrival = now()->subHours(3)->format('Y-m-d H:i:s');
        DB::table('collections')->where('id', $id)->update(['added' => $arrival]);
        app(CandidateRepository::class)->archive($id);
        $this->assertSame($arrival, DB::table('release_verification_candidates')->value('last_unique_at'));
    }

    public function test_late_unique_merge_uses_observation_time_without_rewinding_previous_arrival(): void
    {
        $this->arrivalColumns();
        $repository = app(CandidateRepository::class);
        $id = $this->arrival();
        DB::table('collections')->where('id', $id)->update(['last_seen_at' => now()->subHours(4)]);
        $repository->archive($id);
        $late = $this->arrival('late@article', 2);
        $arrival = now()->subHours(2)->format('Y-m-d H:i:s');
        DB::table('collections')->where('id', $late)->update(['last_seen_at' => $arrival]);
        $repository->archive($late);
        $this->assertSame($arrival, DB::table('release_verification_candidates')->value('last_unique_at'));
        $older = $this->arrival('older@article', 3);
        DB::table('collections')->where('id', $older)->update(['last_seen_at' => now()->subHours(3)]);
        $repository->archive($older);
        $this->assertSame($arrival, DB::table('release_verification_candidates')->value('last_unique_at'));
    }

    private function arrival(string $message = 'first@article', int $part = 1): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1('lifecycle', true), 'subject' => 'Movie',
            'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 1, 'date' => '2026-10-11 12:00:00']);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5('file', true), 'name' => 'movie.mkv', 'totalparts' => 2]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => $part, 'messageid' => $message, 'size' => 5]);

        return $id;
    }

    private function arrivalColumns(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('dateadded')->nullable();
            $table->dateTime('added')->nullable();
        });
    }

    private function publicationSchema(): void
    {
        config(['nntmux.release_dedupe_enabled' => false, 'nntmux_settings.path_to_nzbs' => config('release_verification.storage_path').'/nzbs']);
        app('files')->ensureDirectoryExists(config('nntmux_settings.path_to_nzbs'));
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            foreach (['name', 'searchname', 'guid', 'leftguid', 'fromname', 'postdate', 'adddate'] as $column) {
                $table->string($column);
            }
            foreach (['totalpart', 'groups_id', 'size', 'passwordstatus', 'haspreview', 'categories_id', 'nfostatus', 'nzbstatus', 'isrenamed', 'iscategorized', 'predb_id'] as $column) {
                $table->integer($column);
            }
        });
        Schema::create('release_regexes', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('collection_regex_id');
            $table->integer('naming_regex_id');
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('groups_id');
        });
        Search::shouldReceive('updateRelease')->twice();
        $this->app->instance(ReleaseMetadataResolver::class, new class(app(ReleaseCleaningService::class)) extends ReleaseMetadataResolver
        {
            public function resolve(array $manifest): array
            {
                return ['name' => 'Movie', 'searchname' => 'Movie', 'fromname' => 'poster', 'predb_id' => 0, 'isrenamed' => 1, 'categories_id' => 2000, 'naming_regex_id' => 0];
            }
        });
    }
}
