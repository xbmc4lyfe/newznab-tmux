<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Facades\Search;
use App\Services\BlacklistService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Verification\CandidateAdmissionDeferredException;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class NzbPublishedPasswordRaceTest extends ReleaseVerificationCandidatesTest
{
    public static function raceCases(): array
    {
        $cases = [];
        foreach (['unpublished', 'absent'] as $observation) {
            foreach ([true, false] as $passwordBearing) {
                $cases[] = [$observation, $passwordBearing, false, false, false];
                foreach ([false, true] as $delete) {
                    foreach ([false, true] as $deleteFailed) {
                        $cases[] = [$observation, $passwordBearing, true, $delete, $deleteFailed];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('raceCases')]
    public function test_actual_publication_between_advisory_and_locked_read(string $observation, bool $passwordBearing, bool $useImport, bool $delete, bool $deleteFailed): void
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

        $manifest = ['identity' => sha1('advisory-race'), 'metadata_source' => 'import', 'subject' => 'fixture', 'fromname' => 'poster', 'date' => '2026-10-11 12:00:00', 'groups_id' => 1,
            'groups' => ['alt.test'], 'expected_files' => 1, 'regex_id' => 0, 'verified_bytes' => 5,
            'verified_file_names' => ['fixture.bin'], 'verified_file_count' => 1,
            'files' => [['key' => 'file', 'subject' => 'fixture.bin', 'totalparts' => 1,
                'segments' => [['number' => 1, 'messageid' => 'verified@article', 'bytes' => 5]]]]];
        if ($passwordBearing) {
            $manifest['passwords'] = ['fixture first', 'fixture first', '', 'fixture last'];
        }
        $source = config('release_verification.storage_path').'/race-source.nzb';
        app('files')->ensureDirectoryExists(dirname($source));
        $incoming = app(VerifiedNzbWriter::class)->document($manifest);
        file_put_contents($source, $incoming);
        if ($useImport) {
            app(NzbUploadManifestService::class)->create(dirname($source), 'publication-race', basename($source), null);
        }
        $statuses = [];
        $callback = static function (array $result) use (&$statuses): void {
            $statuses[] = $result['status'];
        };
        if ($useImport) {
            $blacklist = $this->createStub(BlacklistService::class);
            $blacklist->method('isBlackListed')->willReturn(false);
            $blacklist->method('getAndClearIdsToUpdate')->willReturn([]);
            $service = new class($blacklist) extends NzbImportService
            {
                public function __construct($blacklist)
                {
                    parent::__construct(['Browser' => true]);
                    $this->blacklistService = $blacklist;
                }

                protected function getAllGroups(): bool
                {
                    $this->allGroups = ['alt.test' => 1];

                    return true;
                }
            };
            $service->beginImport([$source], resultCallback: $callback);
            self::assertSame([NzbImportStatus::Queued], $statuses);
            $manifest = app(ManifestStore::class)->read(DB::table('release_verification_candidates')->value('manifest_path'));
        } else {
            self::assertTrue(app(CandidateRepository::class)->enqueue($manifest));
        }
        if ($useImport) {
            $fresh = dirname($source).'/fresh-origin';
            app('files')->ensureDirectoryExists($fresh);
            $source = $fresh.'/race-source.nzb';
            file_put_contents($source, $incoming);
            app(NzbUploadManifestService::class)->create($fresh, 'fresh-publication-race', basename($source), null);
        }
        // Observe a genuine queued candidate, or no candidate at all. Publication below
        // uses the real claim/publisher path; no fixture writes published status fields.
        $queued = DB::table('release_verification_candidates')->first();
        self::assertNull($queued->release_id);
        self::assertNull($queued->verified_at);
        self::assertNotSame('', $queued->manifest_path);
        self::assertSame(0, DB::table('releases')->count());
        if ($observation === 'absent') {
            DB::table('release_verification_candidates')->delete();
            DB::table('release_verification_state')->update(['pending_bytes' => 0]);
        }
        $fired = false;
        $published = null;
        $publishedState = null;
        $artifact = null;
        $artifactBytes = null;
        $observedEvidence = null;
        $lockedReadAfterPublication = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, &$published, &$publishedState, &$artifact, &$artifactBytes, &$observedEvidence, &$lockedReadAfterPublication, $observation, $manifest): void {
            if ($fired) {
                if ($published !== null && str_starts_with($query->sql, 'select * from "release_verification_candidates" where "identity"')) {
                    $lockedReadAfterPublication = true;
                }

                return;
            }
            if (! str_starts_with($query->sql, 'select "release_id" from "release_verification_candidates"')) {
                return;
            }
            $fired = true;
            $observed = DB::table('release_verification_candidates')->first();
            $observedEvidence = $observed === null ? 'absent' : ($observed->release_id === null && $observed->verified_at === null ? 'unpublished' : 'invalid');
            self::assertSame($observation, $observedEvidence);
            self::assertSame(0, DB::table('releases')->count());
            if ($observed === null) {
                self::assertTrue(app(CandidateRepository::class)->enqueue($manifest));
            }
            $claim = app(CandidateRepository::class)->claim();
            self::assertNotNull($claim);
            self::assertNull($claim->release_id);
            $id = app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Offline fixture verified', $manifest));
            self::assertNotNull($id);
            $published = (array) DB::table('release_verification_candidates')->where('id', $claim->id)->first();
            $publishedState = (array) DB::table('release_verification_state')->first();
            self::assertSame('complete', $published['status']);
            self::assertNotNull($published['verified_at']);
            self::assertSame($id, (int) $published['release_id']);
            self::assertSame('', $published['manifest_path']);
            self::assertSame(0, (int) $published['manifest_bytes']);
            self::assertSame(0, (int) $publishedState['pending_bytes']);
            $release = DB::table('releases')->where('id', $id)->first();
            self::assertSame(NzbService::NZB_ADDED, (int) $release->nzbstatus);
            self::assertNotSame('', $release->guid);
            $artifact = app(NzbService::class)->getNzbPath($release->guid);
            self::assertFileExists($artifact);
            $artifactBytes = file_get_contents($artifact);
            $retained = app(NzbManifestReader::class)->read(app(NzbService::class)->readNzbContents($release->guid), (object) ['groups_id' => 1, 'searchname' => 'fixture', 'fromname' => 'poster', 'postdate' => $manifest['date']]);
            self::assertSame($manifest['passwords'] ?? [], $retained['passwords'] ?? []);
            self::assertSame(substr(app(ManifestStore::class)->prepare($manifest)->name, 0, 64), $published['manifest_hash']);
        });
        if ($useImport) {
            $service->beginImport([$source], delete: $delete, deleteFailed: $deleteFailed, resultCallback: $callback);
            self::assertSame($passwordBearing ? NzbImportStatus::MetadataDeferred : NzbImportStatus::Queued, end($statuses));
        } else {
            if ($passwordBearing) {
                $deferred = false;
                try {
                    app(CandidateRepository::class)->enqueue($manifest);
                } catch (CandidateAdmissionDeferredException) {
                    $deferred = true;
                }
                self::assertTrue($deferred, 'Missing replay evidence is retryable, not a permanent metadata rejection.');
            } else {
                self::assertTrue(app(CandidateRepository::class)->enqueue($manifest));
            }
        }
        self::assertTrue($fired);
        self::assertTrue($lockedReadAfterPublication);
        self::assertSame($observation, $observedEvidence);
        self::assertSame($published, (array) DB::table('release_verification_candidates')->first());
        self::assertSame($publishedState, (array) DB::table('release_verification_state')->first());
        self::assertSame($artifactBytes, file_get_contents($artifact));
        if ($passwordBearing || ! $useImport || ! $delete) {
            self::assertSame($incoming, file_get_contents($source));
        } else {
            self::assertFileDoesNotExist($source);
        }
        if ($useImport && $passwordBearing) {
            self::assertTrue(app(NzbUploadManifestService::class)->shouldImportNzb($source));
        }
        // A fresh admission now observes the published link, prepares XML, and admits
        // the same exact document without changing candidate/version/capacity/artifact.
        if ($useImport) {
            file_put_contents($source, $incoming);
            $service->beginImport([$source], delete: $delete, deleteFailed: $deleteFailed, resultCallback: $callback);
            self::assertSame(NzbImportStatus::Queued, end($statuses));
            if ($delete) {
                self::assertFileDoesNotExist($source);
            } else {
                self::assertSame($incoming, file_get_contents($source));
            }
        } else {
            self::assertTrue(app(CandidateRepository::class)->enqueue($manifest));
        }
        self::assertSame($published, (array) DB::table('release_verification_candidates')->first());
        self::assertSame($publishedState, (array) DB::table('release_verification_state')->first());
        self::assertSame($artifactBytes, file_get_contents($artifact));
        self::assertSame(1, DB::table('releases')->count());
    }
}
