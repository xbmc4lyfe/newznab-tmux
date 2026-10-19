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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StoredManifests;

class ReleaseVerificationCandidateLifecycleTest extends ReleaseVerificationCandidatesTest
{
    use StoredManifests;

    public function test_expiry_deadline_finishes_admitted_transaction_and_retains_later_candidates(): void
    {
        $repository = app(CandidateRepository::class);
        foreach (['old-one', 'old-two', 'old-three'] as $identity) {
            $repository->enqueue(['identity' => sha1($identity), 'groups_id' => 1, 'files' => []]);
        }
        DB::table('release_verification_candidates')->update(['last_unique_at' => now()->subDays(3)]);
        $before = DB::table('release_verification_candidates')->orderBy('id')->get();
        $remaining = 2;
        $repository->expire(static function () use (&$remaining): bool {
            return $remaining-- > 0;
        });

        $this->assertSame(['expired', $before[1]->status, $before[2]->status], DB::table('release_verification_candidates')->orderBy('id')->pluck('status')->all());
        $this->assertSame((int) $before[1]->manifest_bytes + (int) $before[2]->manifest_bytes,
            (int) DB::table('release_verification_state')->value('pending_bytes'));
        foreach ([$before[1], $before[2]] as $candidate) {
            $this->assertManifestStored($candidate->manifest_path);
        }
        $this->assertSame(0, DB::transactionLevel());

        $repository->expire();
        $this->assertSame(3, DB::table('release_verification_candidates')->where('status', 'expired')->count());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_expiry_defers_selection_when_maintenance_deadline_has_passed(): void
    {
        $repository = app(CandidateRepository::class);
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $repository->expire(static fn (): bool => false);

        $this->assertSame(0, $queries);
    }

    public function test_expiry_selection_consuming_deadline_retains_candidates_for_retry(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue(['identity' => sha1('old-after-slow-query'), 'groups_id' => 1, 'files' => []]);
        DB::table('release_verification_candidates')->update(['last_unique_at' => now()->subDays(3)]);
        $before = DB::table('release_verification_candidates')->sole();
        $allowed = true;
        DB::listen(static function ($query) use (&$allowed): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"last_unique_at"')) {
                $allowed = false;
            }
        });
        $repository->expire(static function () use (&$allowed): bool {
            return $allowed;
        });

        $this->assertEquals($before, DB::table('release_verification_candidates')->sole());
        $this->assertManifestStored($before->manifest_path);
        $this->assertSame((int) $before->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $repository->expire();
        $this->assertSame('expired', DB::table('release_verification_candidates')->value('status'));
    }

    /** @return iterable<string, array{bool}> */
    public static function expiryOuterTransactions(): iterable
    {
        yield 'outer commit' => [true];
        yield 'outer rollback' => [false];
    }

    #[DataProvider('expiryOuterTransactions')]
    public function test_bounded_expiry_defers_manifest_deletion_until_outer_transaction_finishes(bool $commit): void
    {
        $repository = app(CandidateRepository::class);
        foreach (['outer-one', 'outer-two'] as $identity) {
            $repository->enqueue(['identity' => sha1($identity), 'groups_id' => 1, 'files' => []]);
        }
        DB::table('release_verification_candidates')->update(['last_unique_at' => now()->subDays(3)]);
        $before = DB::table('release_verification_candidates')->orderBy('id')->get();
        $bytes = (int) DB::table('release_verification_state')->value('pending_bytes');
        DB::beginTransaction();
        try {
            $remaining = 2;
            $repository->expire(static function () use (&$remaining): bool {
                return $remaining-- > 0;
            });
            $this->assertSame('expired', DB::table('release_verification_candidates')->where('id', $before[0]->id)->value('status'));
            $this->assertManifestStored($before[0]->manifest_path);
            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertEquals($before[1], DB::table('release_verification_candidates')->where('id', $before[1]->id)->sole());
        $this->assertManifestStored($before[1]->manifest_path);
        if ($commit) {
            $this->assertManifestNotStored($before[0]->manifest_path);
            $this->assertSame($bytes - (int) $before[0]->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        } else {
            $this->assertEquals($before[0], DB::table('release_verification_candidates')->where('id', $before[0]->id)->sole());
            $this->assertManifestStored($before[0]->manifest_path);
            $this->assertSame($bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function filteredReplays(): iterable
    {
        yield 'late partial headers' => [false];
        yield 'complete replay' => [true];
    }

    #[DataProvider('filteredReplays')]
    public function test_policy_filtered_identity_remains_terminal_on_recreated_headers(bool $completeReplay): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
        $repository->archive($this->arrival('second@article', 2));
        $claim = $repository->claim();
        $repository->rejectByPolicy($claim, 'Verified payload below minimum size.');
        $before = DB::table('release_verification_candidates')->sole();
        $replay = $this->arrival($completeReplay ? 'first@article' : 'late@article', $completeReplay ? 1 : 2);
        if ($completeReplay) {
            $binary = DB::table('binaries')->where('collections_id', $replay)->value('id');
            DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 2, 'messageid' => 'second@article', 'size' => 5]);
        }
        $repository->archive($replay);

        $this->assertEquals($before, DB::table('release_verification_candidates')->sole());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame([], glob(config('release_verification.storage_path').'/manifests/*.json.gz'));
        $this->assertNull($repository->claim());
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldNotReceive('doConnect');
            $mock->shouldNotReceive('verificationArticle');
        });
        $this->artisan('releases:verify-candidates')->assertSuccessful();
    }

    public function test_filtered_replay_uses_no_capacity_and_preserves_another_identity_for_admission(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
        $repository->rejectByPolicy($repository->claim(), 'Verified policy rejection.');
        config(['release_verification.pending_bytes' => 0]);
        $repository->archive($this->arrival('late@article', 2));
        $this->assertSame(0, DB::table('collections')->count());
        $other = $this->arrival('other@article');
        DB::table('collections')->where('id', $other)->update(['collectionhash' => sha1('other-identity', true)]);
        try {
            $repository->archive($other);
            $this->fail('An unrelated identity must retain its headers when capacity is full.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('capacity is full', $error->getMessage());
        }
        $this->assertSame(1, DB::table('collections')->where('id', $other)->count());
        $this->assertSame('other@article', DB::table('parts')->value('messageid'));
        config(['release_verification.pending_bytes' => 1048576]);
        $repository->archive($other);
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
        $next = $repository->claim();
        $this->assertNotNull($next);
        $this->assertSame(sha1('other-identity'), $next->source_identity);
        $this->assertSame(1, DB::table('release_verification_candidates')->where('status', 'filtered')->count());
        $this->assertSame(0, DB::table('collections')->count());
    }

    /** @return iterable<string, array{array<string, int|string>, bool}> */
    public static function unsafeFilteredReplays(): iterable
    {
        yield 'different group' => [['groups_id' => 2], true];
        yield 'retained manifest ownership' => [['manifest_path' => str_repeat('a', 64).'.json.gz'], true];
        yield 'retained capacity ownership' => [['manifest_bytes' => 1], true];
        yield 'publication reference' => [['release_id' => 7], true];
        yield 'no usable articles' => [[], false];
    }

    /** @param array<string, int|string> $changes */
    #[DataProvider('unsafeFilteredReplays')]
    public function test_filtered_replay_retains_headers_without_safe_terminal_ownership(array $changes, bool $usable): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
        $repository->rejectByPolicy($repository->claim(), 'Verified policy rejection.');
        if ($changes !== []) {
            DB::table('release_verification_candidates')->update($changes);
        }
        $before = DB::table('release_verification_candidates')->sole();
        $replay = $this->arrival('late@article', 2);
        if (! $usable) {
            DB::table('parts')->update(['size' => 0, 'messageid' => '']);
        }
        $repository->archive($replay);

        $this->assertEquals($before, DB::table('release_verification_candidates')->sole());
        $this->assertSame(1, DB::table('collections')->where('id', $replay)->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_filtered_replay_retirement_rolls_back_with_the_callers_transaction(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
        $repository->rejectByPolicy($repository->claim(), 'Verified policy rejection.');
        $replay = $this->arrival('late@article', 2);
        DB::beginTransaction();
        $repository->archive($replay);
        $this->assertSame(0, DB::table('collections')->count());
        DB::rollBack();

        $this->assertSame(1, DB::table('collections')->where('id', $replay)->count());
        $this->assertSame('late@article', DB::table('parts')->value('messageid'));
        $this->assertSame('filtered', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    /** @return iterable<string, array{bool}> */
    public static function expiryTerminalTransitions(): iterable
    {
        yield 'filtered after expiry selection' => [false];
        yield 'published after expiry selection' => [true];
    }

    #[DataProvider('expiryTerminalTransitions')]
    public function test_expiry_current_read_preserves_a_terminal_transition_after_selection(bool $published): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
        $claim = $repository->claim();
        DB::table('release_verification_candidates')->where('id', $claim->id)->update([
            'last_unique_at' => now()->subHours(49), 'lease_until' => now()->subSecond(),
        ]);
        $transitioned = false;
        $terminal = null;
        DB::listen(function (QueryExecuted $query) use ($repository, $claim, $published, &$transitioned, &$terminal): void {
            if ($transitioned || ! str_starts_with($query->sql, 'select "id"')
                || ! str_contains($query->sql, 'release_verification_candidates') || ! str_contains($query->sql, 'last_unique_at')) {
                return;
            }
            $transitioned = true;
            if ($published) {
                DB::transaction(function () use ($claim): void {
                    DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first();
                    $row = DB::table('release_verification_candidates')->where('id', $claim->id)->lockForUpdate()->sole();
                    DB::table('release_verification_state')->where('id', 1)->decrement('pending_bytes', (int) $row->manifest_bytes);
                    DB::table('release_verification_candidates')->where('id', $claim->id)->update([
                        'status' => 'complete', 'release_id' => 7, 'verified_at' => now(), 'manifest_path' => '',
                        'manifest_bytes' => 0, 'lease_token' => null, 'lease_until' => null,
                    ]);
                });
            } else {
                $repository->rejectByPolicy($claim, 'Verified policy rejection during expiry selection.');
            }
            $terminal = DB::table('release_verification_candidates')->sole();
        });
        $repository->expire();

        $this->assertTrue($transitioned);
        $this->assertEquals($terminal, DB::table('release_verification_candidates')->sole());
        $this->assertSame($published ? 'complete' : 'filtered', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_proven_unrepairable_manifest_waits_for_new_headers_without_refetching(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
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
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
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
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
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
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
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
        $this->assertManifestNotStored($claim->manifest_path);
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
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return $this->insertNZB(['verificationXml' => $nzbXML->asXML(), 'groups_id' => 1, 'useFName' => 'Movie', 'subject' => 'Movie',
                    'from' => 'poster', 'postDate' => '2026-10-11 12:00:00', 'articleFingerprint' => sha1('same-articles')]);
            }
        };
        $reports = [];
        $capture = static function (array $report) use (&$reports): void {
            $reports[] = $report;
        };
        $service->beginImport([$path], resultCallback: $capture);
        $this->assertSame(NzbImportStatus::Queued, $reports[0]['status']);
        $first = $repository->claim();
        $manifest = app(ManifestStore::class)->read($first->manifest_path);
        $firstId = app(VerifiedReleasePublisher::class)->publish($first, new VerificationResult('complete', 'Verified payload.', $manifest));
        DB::table('releases')->where('id', $firstId)->delete();
        $service->beginImport([$path], resultCallback: $capture);
        $this->assertSame(NzbImportStatus::Queued, $reports[1]['status']);
        $identity = sha1('import:'.sha1('same-articles'));
        $this->assertTrue($manifests->deleteCapturedInput($path, $reports[1]['input_capture']));
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
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        DB::table('releases')->insert(['id' => 7]);
        $repository = app(CandidateRepository::class);
        $repository->archive($this->arrival());
        // Exercise retained legacy candidates before metadata preparation.
        DB::table('release_verification_candidates')->where('status', 'waiting_upload')->update(['status' => 'pending']);
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
        // Without partial publication: a stalled upload owed its partial attempt is published, not expired.
        config(['release_verification.partial_after_hours' => 0]);
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
