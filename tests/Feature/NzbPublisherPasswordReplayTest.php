<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\VerifyReleaseCandidates;
use App\Facades\Search;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\VerificationResetService;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\YencArticles;

class NzbPublisherPasswordReplayTest extends ReleaseVerificationCandidatesTest
{
    public static function cases(): array
    {
        return ['real publisher retained duplicate' => ['exact', 'same', false], 'real publisher fresh artifact' => ['different', 'different', false]];
    }

    #[DataProvider('cases')]
    public function test_publisher_preserves_document_assertions(string $relation, string $assertions, bool $hold, bool $workerException = false): void
    {
        config(['nntmux.release_dedupe_enabled' => true, 'nntmux_settings.path_to_nzbs' => config('release_verification.storage_path').'/nzbs']);
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
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        Schema::create('release_regexes', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('collection_regex_id');
            $table->integer('naming_regex_id');
        });
        Schema::create('releases_groups', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->integer('groups_id');
        });
        Schema::table('usenet_groups', function (Blueprint $table): void {
            $table->string('description')->default('');
            foreach (['backfill_target', 'first_record', 'last_record', 'active', 'backfill'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->string('minfilestoformrelease')->default('');
            $table->string('minsizetoformrelease')->default('');
        });
        Search::shouldReceive('updateRelease')->times(in_array($relation, ['different', 'legacy'], true) ? 1 : 0);
        $this->app->instance(ReleaseMetadataResolver::class,
            new class(app(ReleaseCleaningService::class)) extends ReleaseMetadataResolver
            {
                public function resolve(array $manifest): array
                {
                    return ['name' => 'Movie', 'searchname' => 'Movie', 'fromname' => 'poster', 'predb_id' => 0,
                        'isrenamed' => 1, 'categories_id' => 2000, 'naming_regex_id' => 0];
                }
            });

        $manifest = ['identity' => sha1('fixture'), 'metadata_source' => 'import', 'subject' => 'Movie', 'fromname' => 'poster', 'date' => '2026-10-11 12:00:00', 'groups_id' => 1,
            'groups' => ['alt.test'], 'expected_files' => 1, 'regex_id' => 0, 'verified_bytes' => 5,
            'verified_file_names' => ['movie.mkv'], 'verified_file_count' => 1, 'passwords' => ['fixture assertion'],
            'files' => [['key' => 'file', 'subject' => 'movie.mkv', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => 'verified@article', 'bytes' => 5]]]]];
        $guid = (string) Str::uuid();
        DB::table('releases')->insert(['id' => 777, 'name' => 'Movie', 'searchname' => 'Movie', 'guid' => $guid,
            'leftguid' => '1', 'fromname' => 'poster', 'postdate' => $manifest['date'], 'adddate' => $manifest['date'],
            'totalpart' => 1, 'groups_id' => 1, 'size' => 5, 'passwordstatus' => -1, 'haspreview' => -1,
            'categories_id' => 2000, 'nfostatus' => -1, 'nzbstatus' => $relation === 'legacy' ? 0 : 1,
            'isrenamed' => 1, 'iscategorized' => 1, 'predb_id' => 0]);
        $retained = $manifest;
        if ($assertions === 'missing') {
            unset($retained['passwords']);
        }
        if ($assertions === 'different') {
            $retained['passwords'] = ['fixture other assertion'];
        }
        if ($relation === 'subset') {
            $retained['files'][0]['segments'][] = ['number' => 2, 'messageid' => 'extra@article', 'bytes' => 9];
            $retained['files'][0]['totalparts'] = 2;
        }
        if ($relation === 'different') {
            $retained['files'][0]['segments'][0]['messageid'] = 'other@article';
        }
        if ($relation === 'legacy') {
            $manifest['legacy_release_id'] = 777;
        } else {
            app(VerifiedNzbWriter::class)->write($retained, $guid);
        }
        $retainedPath = app(NzbService::class)->getNzbPath($guid);
        $retainedBytes = is_file($retainedPath) ? file_get_contents($retainedPath) : null;
        $store = app(ManifestStore::class);
        $path = $store->write($manifest);
        $bytes = $store->size($path);
        DB::table('release_verification_candidates')->insert(['identity' => sha1('fixture'), 'groups_id' => 1,
            'manifest_path' => $path, 'manifest_hash' => substr($path, 0, 64), 'manifest_bytes' => $bytes,
            'last_unique_at' => now(), 'next_attempt_at' => now()]);
        DB::table('release_verification_state')->update(['pending_bytes' => $bytes]);
        $claim = $workerException ? null : app(CandidateRepository::class)->claim();
        if ($workerException) {
            config(['release_verification.spot_check_articles' => 1, 'release_verification.free_bytes' => 0]);
            DB::table('release_verification_scratch_budget')->where('id', 1)->update(['initialized' => true]);
            $article = YencArticles::part('hello', 'movie.mkv', 5, 1, 1, 1);
            $this->mock(NNTPService::class, static function ($mock) use ($article): void {
                $mock->shouldReceive('doConnect')->with(false)->andReturn(true);
                $mock->shouldReceive('verificationArticle')->with('verified@article', \Mockery::type('int'))->andReturn($article);
                $mock->shouldReceive('doQuit')->andReturn(null);
            });
            $interrupted = false;
            DB::listen(static function (QueryExecuted $query) use (&$interrupted, &$claim): void {
                if ($claim === null && str_starts_with($query->sql, 'update "release_verification_candidates"')
                    && in_array('verifying', $query->bindings, true)) {
                    $claim = DB::table('release_verification_candidates')->first();
                }
                if (! $interrupted && str_starts_with($query->sql, 'update "release_verification_candidates"')
                    && in_array('metadata_review', $query->bindings, true)) {
                    $interrupted = true;
                    DB::afterCommit(static function (): void {
                        throw new \RuntimeException('Fixture publication interrupted after commit.');
                    });
                }
            });
            $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--no-summary' => true]));
            $output = Artisan::output();
            $this->assertStringContainsString(DB::table('release_verification_candidates')->value('id').': metadata_review — Covered release does not preserve supplied metadata; review retained candidate.', $output);
            $this->assertStringNotContainsString(': filtered —', $output);
            $this->assertStringNotContainsString('retry pending', $output);
            $this->assertStringNotContainsString('fixture assertion', $output);
            $this->assertTrue($interrupted);
            $id = null;
        } else {
            $id = app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Fixture verified', $manifest));
        }
        $candidate = DB::table('release_verification_candidates')->where('id', $claim->id)->first();
        self::assertNotNull($candidate->verified_at);
        self::assertSame(substr($store->prepare($manifest)->name, 0, 64), $candidate->manifest_hash);
        $beforeReplay = (array) $candidate;
        self::assertTrue(app(CandidateRepository::class)->enqueue($manifest));
        self::assertSame($beforeReplay, (array) DB::table('release_verification_candidates')->where('id', $claim->id)->first());

        if ($relation !== 'legacy') {
            $this->assertSame($retainedBytes, file_get_contents($retainedPath));
        }
        if ($hold) {
            $this->assertNull($id);
            $this->assertSame(1, DB::table('releases')->count());
            $this->assertSame('metadata_review', $candidate->status);
            $this->assertNull($candidate->release_id);
            $this->assertNull($candidate->lease_token);
            $this->assertNull($candidate->lease_until);
            $this->assertSame($path, $candidate->manifest_path);
            $this->assertSame($bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
            $this->assertSame($manifest['passwords'], $store->read($path)['passwords']);
            $this->assertNull(app(CandidateRepository::class)->claim());
            app(CandidateRepository::class)->finish($claim, new VerificationResult('unknown', 'Stale fixture'));
            $this->assertEquals($candidate, DB::table('release_verification_candidates')->where('id', $claim->id)->first());
            $method = new \ReflectionMethod(VerifyReleaseCandidates::class, 'filteredResult');
            $this->assertSame('metadata_review', $method->invoke(app(VerifyReleaseCandidates::class), $claim)->verdict);
            DB::table('release_verification_candidates')->where('id', $claim->id)->update(['last_unique_at' => now()->subYears(2)]);
            app(CandidateRepository::class)->expire();
            $this->assertSame($path, DB::table('release_verification_candidates')->where('id', $claim->id)->value('manifest_path'));
            $this->assertTrue(is_file($store->path($path)));
            app(VerificationResetService::class)->run(static function (): void {});
            $this->assertSame(0, DB::table('release_verification_candidates')->count());
            $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
            $this->assertFalse(is_file($store->path($path)));

        } else {
            $this->assertSame($relation === 'different' ? 2 : 1, DB::table('releases')->count());
            $this->assertSame($relation !== 'different', $id === 777);
            $publishedGuid = DB::table('releases')->where('id', $id)->value('guid');
            $reader = app(NzbManifestReader::class);
            $published = $reader->read(app(NzbService::class)->readNzbContents($publishedGuid), (object) [
                'groups_id' => 1, 'searchname' => 'Movie', 'fromname' => 'poster', 'postdate' => $manifest['date']]);
            $this->assertSame($manifest['passwords'], $published['passwords']);
            $this->assertSame('', $candidate->manifest_path);
            $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        }
    }
}
