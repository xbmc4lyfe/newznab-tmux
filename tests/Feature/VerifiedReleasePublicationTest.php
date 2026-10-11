<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VerifiedReleasePublicationTest extends ReleaseVerificationCandidatesTest
{
    public function test_sized_headers_are_archived_instead_of_published(): void
    {
        config(['release_verification.required' => true]);
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1('pending', true), 'subject' => 'Movie',
            'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 1, 'date' => '2026-10-11 12:00:00']);
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
        });
        $result = app(ReleaseCreationService::class)->createReleases(1, 10, false);
        $this->assertSame(0, $result['added']);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
        $this->assertFalse(DB::table('collections')->where('id', $id)->exists());
    }

    public function test_successful_download_publishes_exact_verified_nzb_once(): void
    {
        config(['nntmux.release_dedupe_enabled' => false, 'nntmux_settings.path_to_nzbs' => config('release_verification.storage_path').'/nzbs']);
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
        Search::shouldReceive('updateRelease')->once();
        $this->app->instance(ReleaseMetadataResolver::class,
            new class(app(ReleaseCleaningService::class)) extends ReleaseMetadataResolver
            {
                public function resolve(array $manifest): array
                {
                    return ['name' => 'Movie', 'searchname' => 'Movie', 'fromname' => 'poster', 'predb_id' => 0,
                        'isrenamed' => 1, 'categories_id' => 2000, 'naming_regex_id' => 0];
                }
            });
        $store = app(ManifestStore::class);
        $manifest = ['subject' => 'Movie', 'fromname' => 'poster', 'date' => '2026-10-11 12:00:00', 'groups_id' => 1,
            'groups' => ['alt.test'], 'expected_files' => 1, 'regex_id' => 0,
            'files' => [['key' => 'file', 'subject' => 'movie.mkv', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'verified@article', 'bytes' => 5]]]]];
        $path = $store->write($manifest);
        DB::table('release_verification_candidates')->insert(['identity' => sha1('publish'), 'groups_id' => 1,
            'manifest_path' => $path, 'manifest_hash' => substr($path, 0, 64), 'manifest_bytes' => filesize($store->path($path)),
            'last_unique_at' => now(), 'next_attempt_at' => now()]);
        DB::table('release_verification_state')->update(['pending_bytes' => filesize($store->path($path))]);
        $claim = app(CandidateRepository::class)->claim();
        $id = app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Downloaded and verified', $manifest));
        $release = DB::table('releases')->where('id', $id)->first();
        $xml = app(NzbService::class)->readNzbContents($release->guid);
        $this->assertStringContainsString('verified@article', $xml);
        $this->assertSame(1, (int) $release->nzbstatus);
        $this->assertSame($id, (int) DB::table('release_verification_candidates')->value('release_id'));
        $this->expectException(\RuntimeException::class);
        app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Duplicate stale claim', $manifest));
    }

    public function test_unknown_download_verdict_cannot_publish(): void
    {
        $result = app(VerifiedReleasePublisher::class)->publish((object) ['id' => 999], new VerificationResult('unknown', 'No download'));
        $this->assertNull($result);
    }

    public function test_successful_verdict_from_a_lost_lease_cannot_publish(): void
    {
        $claim = (object) ['id' => 999, 'version' => 1, 'lease_token' => 'lost', 'manifest_hash' => 'old'];
        $this->expectException(\RuntimeException::class);
        app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Verified', []));
    }
}
