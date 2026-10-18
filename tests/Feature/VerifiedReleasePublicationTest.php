<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PublicationTiming;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\VerificationLedger;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class VerifiedReleasePublicationTest extends ReleaseVerificationCandidatesTest
{
    public function test_timing_does_not_log_after_a_failed_commit_leaves_pdo_in_a_transaction(): void
    {
        config(['release_verification.publication_timing_sample_every' => 1]);
        DB::statement('PRAGMA foreign_keys = ON');
        DB::statement('CREATE TABLE timing_parents (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE timing_children (parent_id INTEGER REFERENCES timing_parents(id) DEFERRABLE INITIALLY DEFERRED)');
        $pdo = DB::connection()->getPdo();
        $timing = PublicationTiming::sample();
        $this->assertNotNull($timing);
        Log::spy();

        try {
            try {
                DB::transaction(function () use ($timing): void {
                    $timing->beginAttempt();
                    $timing->nextPhase('sql_accounting');
                    DB::statement('INSERT INTO timing_children (parent_id) VALUES (123)');
                    $timing->endAttempt(true);
                }, 5);
                $this->fail('The deferred foreign key must fail at commit.');
            } catch (\PDOException $exception) {
                $this->assertStringContainsString('FOREIGN KEY constraint failed', $exception->getMessage());
            } finally {
                $timing->report('threw');
            }

            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            Log::shouldNotHaveReceived('info');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function test_sized_headers_are_archived_instead_of_published(): void
    {
        config(['release_verification.required' => true]);
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1('pending', true), 'subject' => 'Movie',
            'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 1, 'date' => '2026-10-11 12:00:00']);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5('movie', true),
            'name' => 'movie.mkv', 'totalparts' => 1]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1, 'messageid' => 'source@article', 'size' => 5]);
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        $result = app(ReleaseCreationService::class)->createReleases(1, 10, false);
        $this->assertSame(0, $result['added']);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
        $this->assertFalse(DB::table('collections')->where('id', $id)->exists());
    }

    /** @return array<string, array{0: ?int, 1: int, 2: ?string, 3?: ?string, 4?: list<string>, 5?: ?string}> */
    public static function verifiedSizes(): array
    {
        return [
            'valid uncatalogued crosspost and invalid group' => [12345, 12345, null, null, ['alt.test', 'alt.crosspost', 'bad group', 'a.b.fixture']],
            'exact duplicate reconciles uncatalogued crosspost groups' => [12345, 12345, 'exact', null, ['alt.test', 'alt.crosspost', 'bad group', 'a.b.fixture']],
            'lease lost during duplicate scan cannot publish' => [12345, 12345, 'exact', 'scan_lease_lost'],
            'earlier published nonmatching NZB does not hide later exact match' => [12345, 12345, 'published_shadow_exact'],
            'later exact match beyond first candidate page is reused' => [12345, 12345, 'paged_exact'],
            'unpublished lower id does not hide later exact published duplicate' => [12345, 12345, 'shadow_exact'],
            'legacy manifest' => [null, 5, null],
            'including reconstructed file' => [12345, 12345, null],
            'trusted unpublished legacy row' => [12345, 12345, 'legacy'],
            'changed legacy publication is not overwritten' => [12345, 12345, 'legacy_changed', 'legacy_changed'],
            'missing legacy row cannot recreate duplicates' => [12345, 12345, 'legacy_missing', 'legacy_missing'],
            'forged result legacy identity cannot publish' => [12345, 12345, 'legacy', 'legacy_forged'],
            'omitted result legacy identity cannot publish' => [12345, 12345, 'legacy', 'legacy_omitted'],
            'legacy update rollback removes new NZB' => [12345, 12345, 'legacy', 'legacy_update'],
            'covered article subset is reused after verification' => [12345, 12345, 'covered_subset'],
            'covered article subset ignores metadata dedupe switch' => [12345, 12345, 'covered_subset_disabled'],
            'case-distinct article remains independently published' => [12345, 12345, 'case_distinct'],
            'metadata duplicate with different articles' => [12345, 12345, 'different'],
            'metadata duplicate with missing NZB' => [12345, 12345, 'missing'],
            'metadata duplicate with corrupt compressed NZB' => [12345, 12345, 'corrupt'],
            'unpublished duplicate with exact verified inventory is not reused' => [12345, 12345, 'unpublished_exact'],
            'duplicate removed after metadata match is not reused' => [12345, 12345, 'deleted_exact'],
            'duplicate with exact verified article inventory' => [12345, 12345, 'exact'],
            'database insert failure removes new NZB' => [12345, 12345, null, 'insert'],
            'database retry removes rolled-back NZB' => [12345, 12345, null, 'retry'],
            'after commit failure preserves published NZB' => [12345, 12345, null, 'after_commit'],
            'disabled publication timing emits nothing' => [12345, 12345, null, null, ['alt.test'], 'disabled'],
            'sampled publication timing separates ordered phases outside transaction' => [12345, 12345, null, null, ['alt.test'], 'enabled'],
            'sampled duplicate retains publication behavior' => [12345, 12345, 'exact', null, ['alt.test'], 'enabled'],
            'sampled retry records distinct attempts' => [12345, 12345, null, 'retry', ['alt.test'], 'enabled'],
            'timing logger failure preserves committed publication' => [12345, 12345, null, null, ['alt.test'], 'throw_logger'],
            'timing logger failure preserves after commit exception' => [12345, 12345, null, 'after_commit', ['alt.test'], 'throw_logger'],
            'publication timing skips outer transactions' => [12345, 12345, null, null, ['alt.test'], 'nested'],
        ];
    }

    /** @param list<string> $groupNames */
    #[DataProvider('verifiedSizes')]
    public function test_successful_download_publishes_exact_verified_nzb_once(?int $verifiedBytes, int $expectedSize, ?string $duplicateKind, ?string $failure = null, array $groupNames = ['alt.test'], ?string $timingMode = null): void
    {
        config(['nntmux.release_dedupe_enabled' => $duplicateKind !== null, 'nntmux_settings.path_to_nzbs' => config('release_verification.storage_path').'/nzbs']);
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
        Search::shouldReceive('updateRelease')->times(in_array($duplicateKind, ['exact', 'shadow_exact', 'published_shadow_exact', 'paged_exact', 'covered_subset', 'covered_subset_disabled'], true) || in_array($failure, ['insert', 'after_commit', 'legacy_changed', 'legacy_missing', 'legacy_update', 'legacy_forged', 'legacy_omitted'], true) ? 0 : 1);
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
            'groups' => $groupNames, 'expected_files' => 1, 'regex_id' => 0,
            'files' => [['key' => 'file', 'subject' => 'movie.mkv', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'verified@article', 'bytes' => 5]]]]];
        if ($verifiedBytes !== null) {
            $manifest['verified_bytes'] = $verifiedBytes;
        }
        $manifest['verified_bytes'] ??= $expectedSize;
        $manifest['verified_file_names'] = ['movie.mkv'];
        $manifest['verified_file_count'] = 1;
        if ($duplicateKind !== null) {
            $duplicateGuid = (string) Str::uuid();
            DB::table('releases')->insert([
                'id' => 777, 'name' => 'Movie', 'searchname' => 'Movie', 'guid' => $duplicateGuid,
                'leftguid' => '1', 'fromname' => 'poster', 'postdate' => $manifest['date'], 'adddate' => $manifest['date'],
                'totalpart' => 1, 'groups_id' => 1, 'size' => $expectedSize, 'passwordstatus' => -1,
                'haspreview' => -1, 'categories_id' => 2000, 'nfostatus' => -1, 'nzbstatus' => 1,
                'isrenamed' => 1, 'iscategorized' => 1, 'predb_id' => 0,
            ]);
            if (str_starts_with($duplicateKind, 'legacy')) {
                $manifest['legacy_release_id'] = 777;
                DB::table('releases')->where('id', 777)->update(['nzbstatus' => NzbService::NZB_NONE, 'size' => 1]);
            } elseif ($duplicateKind !== 'missing') {
                $oldManifest = $manifest;
                if (in_array($duplicateKind, ['covered_subset', 'covered_subset_disabled'], true)) {
                    $oldManifest['files'][0]['segments'][] = ['number' => 2, 'messageid' => 'extra@article', 'bytes' => 9];
                    $oldManifest['files'][0]['totalparts'] = 2;
                    if ($duplicateKind === 'covered_subset_disabled') {
                        config(['nntmux.release_dedupe_enabled' => false]);
                    }
                }
                if ($duplicateKind === 'case_distinct') {
                    $oldManifest['files'][0]['segments'][0]['messageid'] = 'Verified@article';
                }
                if ($duplicateKind === 'different') {
                    $oldManifest['files'][0]['segments'][0]['messageid'] = 'unverified-old@article';
                }
                app(VerifiedNzbWriter::class)->write($oldManifest, $duplicateGuid);
                if ($duplicateKind === 'corrupt') {
                    file_put_contents(app(NzbService::class)->getNzbPath($duplicateGuid), "\x1f\x8b\x08\x00\x00\x00\x00\x00\x00\x03\xff\xff\xff\xff");
                }
            }
        }
        if (in_array($duplicateKind, ['published_shadow_exact', 'paged_exact'], true)) {
            $other = (array) DB::table('releases')->where('id', 777)->first();
            $other['id'] = 776;
            $other['guid'] = (string) Str::uuid();
            DB::table('releases')->insert($other);
            $different = $manifest;
            $different['files'][0]['segments'][0]['messageid'] = 'different@article';
            app(VerifiedNzbWriter::class)->write($different, $other['guid']);
            if ($duplicateKind === 'paged_exact') {
                for ($earlier = 1; $earlier <= 100; $earlier++) {
                    $other['id'] = $earlier;
                    $other['guid'] = (string) Str::uuid();
                    DB::table('releases')->insert($other);
                    app(VerifiedNzbWriter::class)->write($different, $other['guid']);
                }
            }
        }
        if ($duplicateKind === 'shadow_exact') {
            $stub = (array) DB::table('releases')->where('id', 777)->first();
            $stub['id'] = 776;
            $stub['guid'] = (string) Str::uuid();
            $stub['nzbstatus'] = NzbService::NZB_NONE;
            DB::table('releases')->insert($stub);
            $finder = app(ReleaseDuplicateFinder::class);
            [$legacyMatch] = $finder->findDuplicate('Movie', 'Movie', 0, $expectedSize);
            $this->assertNull($legacyMatch);
            [$publishedMatch] = $finder->findDuplicate('Movie', 'Movie', 0, $expectedSize, publishedOnly: true, coversArticles: fn ($release): bool => app(VerifiedNzbWriter::class)->matches($manifest, (string) $release->guid));
            $this->assertSame(777, $publishedMatch->id);
            config(['nntmux.release_dedupe_enabled' => false]);
            $this->assertSame([null, null], $finder->findDuplicate('Movie', 'Movie', 0, $expectedSize, publishedOnly: true));
            $this->assertSame([], iterator_to_array($finder->publishedCandidates('Movie', 'Movie', 0, $expectedSize)));
            config(['nntmux.release_dedupe_enabled' => true]);
        }
        if ($duplicateKind === 'unpublished_exact') {
            DB::table('releases')->where('id', 777)->update(['nzbstatus' => NzbService::NZB_NONE]);
        }
        $path = $store->write($manifest);
        DB::table('release_verification_candidates')->insert(['identity' => sha1('publish'), 'groups_id' => 1,
            'manifest_path' => $path, 'manifest_hash' => substr($path, 0, 64), 'manifest_bytes' => $store->size($path),
            'last_unique_at' => now(), 'next_attempt_at' => now()]);
        DB::table('release_verification_state')->update(['pending_bytes' => $store->size($path)]);
        $claim = app(CandidateRepository::class)->claim();
        if ($failure === 'legacy_forged') {
            $manifest['legacy_release_id'] = 778;
        } elseif ($failure === 'legacy_omitted') {
            unset($manifest['legacy_release_id']);
        }
        if ($failure === 'legacy_changed') {
            DB::table('releases')->where('id', 777)->update(['nzbstatus' => NzbService::NZB_ADDED]);
        } elseif ($failure === 'legacy_missing') {
            DB::table('releases')->where('id', 777)->delete();
        } elseif ($failure === 'legacy_update') {
            DB::unprepared("CREATE TRIGGER reject_legacy BEFORE UPDATE ON releases BEGIN SELECT RAISE(ABORT, 'forced legacy update failure'); END");
        }
        if ($failure === 'insert') {
            DB::unprepared("CREATE TRIGGER reject_release BEFORE INSERT ON releases BEGIN SELECT RAISE(ABORT, 'forced insert failure'); END");
        }
        $inserts = 0;
        if (in_array($failure, ['retry', 'after_commit'], true)) {
            DB::listen(function (QueryExecuted $event) use ($failure, &$inserts): void {
                if (! str_starts_with($event->sql, 'insert into "releases"')) {
                    return;
                }
                $inserts++;
                if ($failure === 'retry' && $inserts === 1) {
                    throw new \PDOException('deadlock detected');
                }
                if ($failure === 'after_commit') {
                    DB::afterCommit(static function (): void {
                        throw new \RuntimeException('forced after commit failure');
                    });
                }
            });
        }
        $stripeLocks = [];
        DB::listen(function (QueryExecuted $event) use (&$stripeLocks): void {
            if (str_contains($event->sql, 'from "release_verification_locks"') && str_contains($event->sql, ' in (')) {
                $stripeLocks[] = $event->bindings;
            }
        });
        $pages = 0;
        DB::listen(function (QueryExecuted $event) use (&$pages): void {
            if (str_starts_with($event->sql, 'select "id", "guid"') && str_contains($event->sql, 'limit 128')) {
                $pages++;
            }
        });
        $leaseLost = false;
        if ($failure === 'scan_lease_lost') {
            DB::listen(function (QueryExecuted $event) use (&$leaseLost, $claim): void {
                if (! $leaseLost && str_starts_with($event->sql, 'select "id", "guid"')
                    && str_contains($event->sql, 'from "releases"') && ! str_contains($event->sql, 'limit 100')) {
                    $leaseLost = true;
                    DB::table('release_verification_candidates')->where('id', $claim->id)->update(['lease_until' => now()->subSecond()]);
                }
            });
        }
        $duplicateDeleted = false;
        if ($duplicateKind === 'deleted_exact') {
            DB::listen(function (QueryExecuted $event) use (&$duplicateDeleted): void {
                if (! $duplicateDeleted && str_starts_with($event->sql, 'select "id", "guid"')
                    && str_contains($event->sql, 'from "releases"')) {
                    $duplicateDeleted = true;
                    DB::table('releases')->where('id', 777)->delete();
                }
            });
        }
        $timingReports = [];
        $stateAcquiredAt = null;
        $afterCommitStartedAt = null;
        $afterCommitDuration = null;
        if ($timingMode !== null) {
            config(['release_verification.publication_timing_sample_every' => $timingMode === 'disabled' ? 0 : 1]);
            if ($timingMode === 'enabled' && $duplicateKind === null && $failure === null) {
                DB::listen(function (QueryExecuted $event) use (&$stateAcquiredAt, &$afterCommitStartedAt, &$afterCommitDuration): void {
                    if ($stateAcquiredAt !== null || ! str_starts_with($event->sql, 'select * from "release_verification_state"')) {
                        return;
                    }
                    usleep(2000);
                    $stateAcquiredAt = hrtime(true);
                    DB::afterCommit(function () use (&$afterCommitStartedAt, &$afterCommitDuration): void {
                        $afterCommitStartedAt = hrtime(true);
                        usleep(10000);
                        $afterCommitDuration = hrtime(true) - $afterCommitStartedAt;
                    });
                });
            }
            Log::shouldReceive('warning')->andReturnNull();
            Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context) use (&$timingReports, $timingMode): void {
                $this->assertSame('Verified release publication timing.', $message);
                $this->assertSame(0, DB::transactionLevel(), 'Timing logs must be emitted after the publication transaction ends.');
                $timingReports[] = $context;
                if ($timingMode === 'throw_logger') {
                    throw new \RuntimeException('forced timing logger failure');
                }
            });
            if ($timingMode === 'nested') {
                DB::beginTransaction();
            }
        }
        try {
            $id = app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Downloaded and verified', $manifest));
            if ($timingMode === 'nested') {
                DB::commit();
            }
            $this->assertNotContains($failure, ['insert', 'after_commit', 'legacy_changed', 'legacy_missing', 'legacy_update', 'legacy_forged', 'legacy_omitted', 'scan_lease_lost']);
        } catch (\Throwable $error) {
            if ($failure === 'scan_lease_lost') {
                $this->assertTrue($leaseLost, 'Fixture must invalidate the lease during duplicate scanning.');
                $this->assertNull(DB::table('release_verification_candidates')->value('release_id'));
                $this->assertSame(1, DB::table('releases')->count());
                $this->assertSame(NzbService::NZB_ADDED, (int) DB::table('releases')->value('nzbstatus'));
                $this->assertStringContainsString('Verification lease was lost', $error->getMessage());

                return;
            }
            if (in_array($failure, ['legacy_changed', 'legacy_missing', 'legacy_update', 'legacy_forged', 'legacy_omitted'], true)) {
                $this->assertNull(DB::table('release_verification_candidates')->value('release_id'));
                $this->assertCount(0, app('files')->allFiles(config('nntmux_settings.path_to_nzbs')));
                $this->assertSame($failure === 'legacy_missing' ? 0 : 1, DB::table('releases')->count());
                if ($failure === 'legacy_update') {
                    $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->value('nzbstatus'));
                }

                return;
            }
            if ($failure === 'insert') {
                $this->assertStringContainsString('forced insert failure', $error->getMessage());
                $this->assertSame(0, DB::table('releases')->count());
                $this->assertNull(DB::table('release_verification_candidates')->value('release_id'));
                $this->assertCount(0, app('files')->allFiles(config('nntmux_settings.path_to_nzbs')));

                return;
            }
            if ($failure !== 'after_commit') {
                throw $error;
            }
            $this->assertSame('forced after commit failure', $error->getMessage());
            $id = (int) DB::table('releases')->value('id');
        }
        if ($failure === 'retry') {
            $this->assertSame(2, $inserts);
            $this->assertCount(1, app('files')->allFiles(config('nntmux_settings.path_to_nzbs')));
        }
        if (in_array($duplicateKind, ['exact', 'legacy', 'shadow_exact', 'published_shadow_exact', 'paged_exact', 'covered_subset', 'covered_subset_disabled'], true)) {
            $this->assertSame(777, $id);
            $this->assertSame($duplicateKind === 'paged_exact' ? 102 : (in_array($duplicateKind, ['shadow_exact', 'published_shadow_exact'], true) ? 2 : 1), DB::table('releases')->count());
            $this->assertSame($duplicateGuid, DB::table('releases')->where('id', 777)->value('guid'));
        } elseif ($duplicateKind !== null) {
            $this->assertNotSame(777, $id);
        }
        if ($duplicateKind === 'unpublished_exact') {
            $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 777)->value('nzbstatus'));
            $this->assertSame(2, DB::table('releases')->count());
        } elseif ($duplicateKind === 'deleted_exact') {
            $this->assertTrue($duplicateDeleted, 'Fixture must remove the matched row before its publication revalidation.');
            $this->assertFalse(DB::table('releases')->where('id', 777)->exists());
            $this->assertSame(1, DB::table('releases')->count());
        }
        if ($duplicateKind === 'paged_exact') {
            $this->assertGreaterThan(0, $pages, 'The later matching inventory must be considered within the bounded nominations.');
            $this->assertLessThanOrEqual(3, $pages, 'Metadata nominations use at most one bounded query per indexed identity.');
        }
        if ($groupNames !== ['alt.test']) {
            $group = DB::table('usenet_groups')->where('name', 'alt.crosspost')->first();
            $this->assertNotNull($group);
            $this->assertSame(0, (int) $group->active);
            $this->assertSame(0, (int) $group->backfill);
            $this->assertSame('Added by Release processing', $group->description);
            $this->assertTrue(DB::table('releases_groups')->where('releases_id', $id)->where('groups_id', $group->id)->exists());
            $alias = DB::table('usenet_groups')->where('name', 'alt.binaries.fixture')->first();
            $this->assertNotNull($alias);
            $this->assertTrue(DB::table('releases_groups')->where('releases_id', $id)->where('groups_id', $alias->id)->exists());
            $this->assertFalse(DB::table('usenet_groups')->whereIn('name', ['bad group', 'a.b.fixture'])->exists());
            $this->assertSame(3, DB::table('releases_groups')->where('releases_id', $id)->count());
        }
        $release = DB::table('releases')->where('id', $id)->first();
        if (config('release_verification.manifest_storage') === 'database' && $timingMode === 'nested') {
            // Inside an outer transaction, publication takes the exclusive fallback before any read.
            $this->assertSame([], $stripeLocks);
        } elseif (config('release_verification.manifest_storage') === 'database') {
            // The publication locked every stripe its duplicate search could look this release up by.
            $this->assertNotSame([], $stripeLocks);
            foreach (VerificationLedger::publicationKeys((int) $release->size, null, (string) $release->name,
                (string) $release->searchname, (int) $release->predb_id) as $key) {
                $this->assertContains(VerificationLedger::publicationStripe($key), $stripeLocks[array_key_last($stripeLocks)], $key);
            }
        }
        $xml = app(NzbService::class)->readNzbContents($release->guid);
        $this->assertStringContainsString('verified@article', $xml);
        $this->assertSame(1, (int) $release->nzbstatus);
        $this->assertSame($expectedSize, (int) $release->size);
        $this->assertSame($id, (int) DB::table('release_verification_candidates')->value('release_id'));
        if ($timingMode !== null) {
            if (in_array($timingMode, ['disabled', 'nested'], true)) {
                $this->assertSame([], $timingReports);
            } else {
                $this->assertCount(1, $timingReports);
                $report = $timingReports[0];
                $this->assertSame(['status', 'total_ns', 'outcome', 'segment_count', 'keys_present', 'source',
                    'pre_lock_ns', 'pre_lock_phases_ns', 'attempts'], array_keys($report));
                $this->assertSame('local', $report['source']);
                $this->assertSame($failure === 'after_commit' ? 'threw' : ($duplicateKind === 'exact' ? 'duplicate' : 'published'), $report['outcome']);
                $this->assertIsInt($report['segment_count']);
                $this->assertGreaterThan(0, $report['segment_count']);
                $this->assertIsBool($report['keys_present']);
                $this->assertSame(['claim_check', 'metadata_resolve', 'incoming_document', 'key_derivation'], array_keys($report['pre_lock_phases_ns']));
                $this->assertSame($report['pre_lock_ns'], array_sum($report['pre_lock_phases_ns']));
                $this->assertGreaterThanOrEqual($report['pre_lock_ns'] + array_sum($report['attempts'][0]['phases_ns']), $report['total_ns']);
                $this->assertSame($failure === 'after_commit' ? 'threw' : 'returned', $report['status']);
                $this->assertCount($failure === 'retry' ? 2 : 1, $report['attempts']);
                $attempt = $report['attempts'][array_key_last($report['attempts'])];
                $this->assertSame(['state_lock_acquisition', 'candidate_policy', 'metadata_size', 'archive_legacy',
                    'incoming_xml_fingerprint', 'duplicate_search_revalidation', ...($duplicateKind === 'exact' ? [] : ['release_identity_sql', 'final_xml_gzip', 'durable_install']),
                    'sql_accounting', 'ledger_adjust'], array_keys($attempt['phases_ns']));
                $this->assertSame(array_sum($attempt['phases_ns']) - $attempt['phases_ns']['state_lock_acquisition'],
                    $attempt['callback_after_state_lock_ns'], 'Pre-lock work must not count as in-lock time.');
                if (config('release_verification.manifest_storage') === 'database') {
                    $this->assertSame(['path', 'stripe_count', 'stripe_lock_ns', 'publication_row_lock_ns'], array_keys($attempt['lock']));
                    $this->assertSame('striped', $attempt['lock']['path']);
                    $this->assertGreaterThan(0, $attempt['lock']['stripe_count']);
                    $this->assertGreaterThanOrEqual(0, $attempt['lock']['stripe_lock_ns']);
                    $this->assertGreaterThanOrEqual(0, $attempt['lock']['publication_row_lock_ns']);
                    $this->assertLessThanOrEqual($attempt['phases_ns']['state_lock_acquisition'],
                        $attempt['lock']['stripe_lock_ns'] + $attempt['lock']['publication_row_lock_ns']);
                }
                $this->assertSame('returned', $attempt['callback_status']);
                $this->assertGreaterThanOrEqual(0, $attempt['transaction_finalization_ns']);
                $this->assertGreaterThanOrEqual(array_sum($attempt['phases_ns']), $report['total_ns']);
                if ($afterCommitDuration !== null) {
                    $this->assertGreaterThanOrEqual(2000000, $attempt['phases_ns']['state_lock_acquisition']);
                    $this->assertGreaterThanOrEqual($afterCommitDuration, $attempt['transaction_finalization_ns']);
                    $this->assertLessThanOrEqual($afterCommitStartedAt - $stateAcquiredAt, $attempt['callback_after_state_lock_ns'],
                        'After-commit work must not be attributed to the callback span following state lock acquisition.');
                }
                if ($failure === 'retry') {
                    $this->assertSame('threw', $report['attempts'][0]['callback_status']);
                    $this->assertArrayHasKey('release_identity_sql', $report['attempts'][0]['phases_ns']);
                    $this->assertArrayNotHasKey('durable_install', $report['attempts'][0]['phases_ns']);
                }
                $this->assertStringNotContainsString('Movie', json_encode($report, JSON_THROW_ON_ERROR));
                $this->assertStringNotContainsString('verified@article', json_encode($report, JSON_THROW_ON_ERROR));
                $this->assertStringNotContainsString($release->guid, json_encode($report, JSON_THROW_ON_ERROR));
            }
        }
        $this->expectException(\RuntimeException::class);
        try {
            app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Duplicate stale claim', $manifest));
        } catch (\RuntimeException $error) {
            if ($timingMode === 'enabled') {
                $this->assertCount(2, $timingReports);
                $this->assertSame('threw', $timingReports[1]['status']);
                $this->assertSame('stale', $timingReports[1]['outcome']);
                $this->assertSame(['state_lock_acquisition', 'candidate_policy'], array_keys($timingReports[1]['attempts'][0]['phases_ns']));
            }
            $this->assertSame('Stale verification result cannot publish.', $error->getMessage());

            throw $error;
        }
    }

    public function test_published_candidate_scan_uses_fixed_upper_id_when_new_matches_arrive(): void
    {
        config(['nntmux.release_dedupe_enabled' => true]);
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('searchname');
            $table->string('guid');
            $table->integer('size');
            $table->integer('predb_id')->default(0);
            $table->integer('nzbstatus')->default(NzbService::NZB_ADDED);
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        for ($id = 1; $id <= 100; $id++) {
            DB::table('releases')->insert(['id' => $id, 'name' => 'Movie', 'searchname' => 'Movie', 'guid' => 'guid-'.$id, 'size' => 5]);
        }
        $scan = app(ReleaseDuplicateFinder::class)->publishedCandidates('Movie', 'Movie', 0, 5);
        $this->assertInstanceOf(\Generator::class, $scan);
        $scan->rewind();
        $this->assertSame(1, $scan->current()->id);
        DB::table('releases')->insert(['id' => 101, 'name' => 'Movie', 'searchname' => 'Movie', 'guid' => 'new-guid', 'size' => 5]);
        $ids = [];
        foreach ($scan as $release) {
            $ids[] = (int) $release->id;
        }
        $this->assertSame(range(1, 100), $ids);
        $this->assertSame(101, DB::table('releases')->count());
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
