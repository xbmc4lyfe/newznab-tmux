<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\CandidateMetadataBudgetExceeded;
use App\Facades\Search;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\CollectionReleasePolicy;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PartialPublicationDeferredException;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use App\Services\YencService;
use App\Support\Configuration\IngestionConfigurationData;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class VerifiedCollectionPolicyTest extends ReleaseVerificationCandidatesTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('usenet_groups', function (Blueprint $table): void {
            $table->integer('minsizetoformrelease')->default(0);
            $table->integer('minfilestoformrelease')->default(0);
        });
        Schema::create('ingestion_configurations', function (Blueprint $table): void {
            $table->id();
        });
        $this->limits();
    }

    /** @return array<string, array{int, int, int, int, int}> */
    public static function headerLimits(): array
    {
        return [
            'site minimum size' => [10, 0, 0, 0, 0],
            'group minimum size' => [0, 0, 0, 10, 0],
            'site minimum files' => [0, 0, 2, 0, 0],
            'group minimum files' => [0, 0, 0, 0, 2],
        ];
    }

    #[DataProvider('headerLimits')]
    public function test_below_limit_headers_wait_for_download_then_verified_policy_filters_them(int $min, int $max, int $files, int $groupMin, int $groupFiles): void
    {
        config(['release_verification.free_bytes' => 0, 'release_verification.scratch_bytes' => 2147483648, 'release_verification.bytes_per_second' => 0]);
        $this->limits($min, $max, $files, $groupMin, $groupFiles);
        $id = $this->collectionForPolicy();
        app(ReleaseCreationService::class)->createReleases(1, 10, false);
        $candidate = DB::table('release_verification_candidates')->first();
        $this->assertSame('ready', $candidate->status);
        $this->assertFalse(DB::table('collections')->where('id', $id)->exists());
        $this->assertGreaterThan(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertFileExists(app(ManifestStore::class)->path($candidate->manifest_path));
        $this->publicationSchema();
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->twice()->with(false)->andReturn(false, true);
            $mock->shouldReceive('verificationArticle')->once()->with('a@b', 1048576)->andReturn((new YencService)->encode('hello', 'movie.mkv'));
            $mock->shouldReceive('doQuit')->andReturn(null);
        });
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertSame('unknown', DB::table('release_verification_candidates')->value('status'));
        $this->travel(16)->minutes();
        $this->limits($min, $max, $files, $groupMin, $groupFiles);
        $this->artisan('releases:verify-candidates')->expectsOutputToContain('filtered — Declared')->assertSuccessful();
        $this->assertSame('filtered', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_processing_filter_preserves_unverified_headers_before_release_creation(): void
    {
        $this->limits(files: 2);
        $id = $this->collectionForPolicy();
        $this->assertSame([], app(ReleaseProcessingService::class)->deleteUnwantedCollections(1));
        $this->assertTrue(DB::table('collections')->where('id', $id)->exists());
        $this->assertSame(0, DB::table('release_verification_candidates')->count());
    }

    public function test_verification_creation_scopes_candidate_queueing_to_the_supplied_window(): void
    {
        $this->limits(files: 2);
        $rejected = $this->collectionForPolicy();
        $partial = $this->collectionForPolicy(totalParts: 2);
        $tail = $this->collectionForPolicy();
        $window = [$rejected, $partial];
        $rejectedIds = app(ReleaseProcessingService::class)->deleteUnwantedCollections(1, $window);
        $this->assertSame([], $rejectedIds);
        $this->assertTrue(DB::table('collections')->where('id', $tail)->exists());
        $allowed = array_values(array_diff($window, $rejectedIds));
        $result = app(ReleaseCreationService::class)->createReleases(1, 10, false, $allowed);
        $this->assertSame(0, $result['added']);
        $this->assertSame(2, DB::table('release_verification_candidates')->whereIn('status', ['ready', 'waiting_upload'])->count());
        $this->assertFalse(DB::table('collections')->where('id', $rejected)->exists());
        $this->assertFalse(DB::table('collections')->where('id', $partial)->exists());
        $this->assertTrue(DB::table('collections')->where('id', $tail)->exists());
    }

    public function test_below_policy_windows_do_not_starve_later_partial_candidates(): void
    {
        $this->limits(files: 2);
        $settings = Cache::get('configuration:ingestion');
        Cache::put('configuration:ingestion', new IngestionConfigurationData(...array_replace($settings->toArray(), ['maxReleasesCreated' => 1])), 300);
        $first = $this->collectionForPolicy();
        $partial = $this->collectionForPolicy(totalParts: 2);
        $tail = $this->collectionForPolicy();
        $this->publicationSchema();
        $tailWasPreservedDuringFirstPass = false;
        $totals = app(ReleaseProcessingService::class)->processReleaseCreationBatches(1, function () use ($first, $partial, $tail, &$tailWasPreservedDuringFirstPass): void {
            if (DB::table('collections')->where('id', $partial)->exists() && ! DB::table('collections')->where('id', $first)->exists()) {
                $tailWasPreservedDuringFirstPass = DB::table('collections')->where('id', $tail)->exists();
            }
        });
        $this->assertTrue($tailWasPreservedDuringFirstPass);
        $this->assertSame(0, $totals['releases']);
        $this->assertSame(3, $totals['iterations']);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('release_verification_candidates')->where('status', 'filtered')->count());
        $this->assertSame(3, DB::table('release_verification_candidates')->whereIn('status', ['ready', 'waiting_upload'])->count());
    }

    public function test_partial_and_parity_only_inputs_remain_eligible_for_download_proof(): void
    {
        $this->limits(min: 1000, files: 5);
        $partial = $this->collectionForPolicy(totalParts: 2);
        $parity = $this->collectionForPolicy(name: 'movie.par2');
        app(ReleaseCreationService::class)->createReleases(1, 10, false);
        $this->assertSame(2, DB::table('release_verification_candidates')->whereIn('status', ['ready', 'waiting_upload'])->count());
        $this->assertFalse(DB::table('collections')->whereIn('id', [$partial, $parity])->exists());
    }

    public function test_encoded_header_bytes_above_maximum_wait_for_actual_decoded_size(): void
    {
        $this->limits(max: 4);
        app(CandidateRepository::class)->archive($this->collectionForPolicy());
        $this->assertNotNull(app(CandidateRepository::class)->claim());
    }

    /** @return array<string, array{int, int, int, int, int, int, list<string>}> */
    public static function finalLimits(): array
    {
        return [
            'site min size' => [10, 0, 0, 0, 0, 5, ['movie.mkv']],
            'group min size' => [0, 0, 0, 10, 0, 5, ['movie.mkv']],
            'site max size' => [0, 4, 0, 0, 0, 5, ['movie.mkv']],
            'site min files' => [0, 0, 2, 0, 0, 5, ['movie.mkv']],
            'group min files' => [0, 0, 0, 0, 2, 5, ['movie.mkv']],
            'parity without proved sources' => [0, 0, 0, 0, 0, 5, ['movie.par2', 'movie.vol0+1.par2']],
        ];
    }

    #[DataProvider('finalLimits')]
    public function test_verified_inventory_outside_collection_policy_cannot_publish_and_is_terminal(int $min, int $max, int $files, int $groupMin, int $groupFiles, int $bytes, array $names): void
    {
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collectionForPolicy(totalParts: 2));
        // Legacy retained candidates remain claimable until preparation parks them.
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $claim = $repository->claim();
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $manifest['verified_bytes'] = $bytes;
        $manifest['verified_file_names'] = $names;
        $manifest['verified_file_count'] = count($names);
        $this->limits($min, $max, $files, $groupMin, $groupFiles);
        $this->publicationSchema();
        app(ScratchInventory::class)->register((int) $claim->id, (int) $claim->version);
        $scratch = config('release_verification.storage_path').'/scratch/'.$claim->id.'-'.$claim->version;
        app('files')->ensureDirectoryExists($scratch);
        file_put_contents($scratch.'/payload', 'verified bytes');
        $id = app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Verified payload.', $manifest));
        $this->assertNull($id);
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame('filtered', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertFileDoesNotExist(app(ManifestStore::class)->path($claim->manifest_path));
        $this->assertFileExists($scratch.'/payload');
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($scratch);
        $this->travel(16)->minutes();
        $this->assertNull($repository->claim());
    }

    public function test_reconstructed_sources_satisfy_policy_even_when_headers_only_contained_parity(): void
    {
        $this->limits(min: 10, files: 2);
        app(CandidateRepository::class)->archive($this->collectionForPolicy(name: 'movie.par2'));
        $claim = app(CandidateRepository::class)->claim();
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $manifest += ['verified_bytes' => 100, 'verified_file_names' => ['movie.par2', 'reconstructed.mkv'], 'verified_file_count' => 2];
        $this->publicationSchema();
        Search::shouldReceive('updateRelease')->once();
        $id = app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('repaired', 'PAR2 sources verified.', $manifest));
        $this->assertIsInt($id);
        $this->assertSame(2, (int) DB::table('releases')->where('id', $id)->value('totalpart'));
    }

    public function test_manual_import_preserves_legacy_bypass_of_collection_size_and_count_filters(): void
    {
        $manifest = ['identity' => sha1('manual'), 'metadata_source' => 'import', 'subject' => 'Movie', 'fromname' => 'poster',
            'date' => '2026-10-11 12:00:00', 'groups_id' => 1, 'groups' => ['alt.test'], 'regex_id' => 0, 'expected_files' => 1,
            'files' => [['key' => 'file', 'subject' => 'movie.mkv', 'totalparts' => 1, 'segments' => [['number' => 1, 'messageid' => 'a@b', 'bytes' => 5]]]],
            'verified_bytes' => 5, 'verified_file_names' => ['movie.mkv'], 'verified_file_count' => 1];
        $this->limits(min: 1000, max: 1, files: 10);
        app(CandidateRepository::class)->enqueue($manifest);
        $claim = app(CandidateRepository::class)->claim();
        $this->publicationSchema();
        Search::shouldReceive('updateRelease')->once();
        $id = app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Verified manual import.', $manifest));
        $this->assertIsInt($id);
    }

    public function test_spot_checked_candidate_publishes_with_declared_size_and_explicit_spot_check_metadata(): void
    {
        $id = $this->collectionForPolicy();
        DB::table('collections')->where('id', $id)->update(['filesize' => 100000]);
        DB::table('parts')->update(['size' => 100000]);
        $repository = app(CandidateRepository::class);
        $repository->archive($id);
        $claim = $repository->claim();
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $manifest += ['verification_mode' => 'spot_check', 'declared_size' => 100000,
            'publication_size' => 100000, 'publication_file_count' => 1, 'verified_file_names' => ['movie.mkv'],
            'verified_file_count' => 1, 'identity_hints' => [['name' => 'movie.mkv', 'source' => 'yenc-spot-check']],
            'spot_check' => ['articles' => 1, 'bytes' => 256, 'sampled_files' => 1, 'method' => 'yenc-crc-and-identity']];
        $this->publicationSchema();
        Search::shouldReceive('updateRelease')->once();

        $releaseId = app(VerifiedReleasePublisher::class)->publish($claim,
            new VerificationResult('sampled', 'Sampled article checks passed; the full payload was not downloaded.', $manifest));

        $this->assertIsInt($releaseId);
        $this->assertSame(100000, (int) DB::table('releases')->where('id', $releaseId)->value('size'));
        $this->assertSame('sampled', DB::table('release_verification_candidates')->where('id', $claim->id)->value('status'));
        $this->assertSame($releaseId, (int) DB::table('release_verification_candidates')->where('id', $claim->id)->value('release_id'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $contents = app(NzbService::class)->readNzbContents((string) DB::table('releases')->where('id', $releaseId)->value('guid'));
        $this->assertIsString($contents);
        $this->assertStringContainsString('nntmux:verification-mode', $contents);
        $this->assertStringContainsString('spot-check', $contents);
    }

    /** @return array<string, array{?string}> */
    public static function partialPublications(): array
    {
        return ['stalled upload publishes with its article counts' => [null],
            'new articles inside the hold window refuse partial publication' => ['recent'],
            'counts that differ from the headers refuse partial publication' => ['tampered']];
    }

    #[DataProvider('partialPublications')]
    public function test_stalled_incomplete_upload_publishes_as_partial_with_found_and_declared_articles(?string $refusal): void
    {
        config(['release_verification.partial_after_hours' => 4]);
        $id = $this->collectionForPolicy('movie.mkv', 3);
        $repository = app(CandidateRepository::class);
        $repository->archive($id);
        $this->assertSame('waiting_upload', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, $repository->promotePartial(10), 'Inside the hold window the upload keeps waiting.');
        $this->travel(5)->hours();
        $this->limits();
        $this->assertSame(1, $repository->promotePartial(10));
        $claim = $repository->claim();
        $this->assertNotNull($claim);
        $this->assertTrue($repository->partialAllowed($claim));
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $manifest += ['verification_mode' => 'spot_check', 'declared_size' => 5,
            'publication_size' => 5, 'publication_file_count' => 1, 'verified_file_names' => ['movie.mkv'],
            'verified_file_count' => 1, 'identity_hints' => [['name' => 'movie.mkv', 'source' => 'yenc-spot-check']],
            'spot_check' => ['articles' => 1, 'bytes' => 5, 'sampled_files' => 1, 'method' => 'yenc-crc-and-identity'],
            'partial' => ['articles_found' => 1, 'articles_expected' => 3, 'files_found' => 1, 'files_expected' => 1]];
        if ($refusal === 'recent') {
            DB::table('release_verification_candidates')->where('id', $claim->id)->update(['last_unique_at' => now()->subHour()]);
        } elseif ($refusal === 'tampered') {
            $manifest['partial']['articles_expected'] = 1;
        }
        $this->publicationSchema();
        Schema::table('releases', function (Blueprint $table): void {
            foreach (['articles_found', 'articles_expected', 'files_found', 'files_expected'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
        });
        if ($refusal !== null) {
            try {
                app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('sampled', 'Partial sample.', $manifest));
                $this->fail('An ineligible partial publication must be refused.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Partial publication is no longer eligible.', $error->getMessage());
            }
            $this->assertSame(0, DB::table('releases')->count());

            return;
        }
        Search::shouldReceive('updateRelease')->once();

        $releaseId = app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('sampled', 'Partial sample.', $manifest));

        $release = DB::table('releases')->where('id', $releaseId)->first();
        $this->assertSame(1, (int) $release->articles_found);
        $this->assertSame(3, (int) $release->articles_expected);
        $this->assertSame(1, (int) $release->files_found);
        $this->assertSame(1, (int) $release->files_expected);
        $this->assertSame($releaseId, (int) DB::table('release_verification_candidates')->where('id', $claim->id)->value('release_id'));
        $this->assertNotSame('', DB::table('release_verification_candidates')->where('id', $claim->id)->value('manifest_path'),
            'A partial publication keeps its inventory for late articles.');
    }

    public function test_late_articles_merge_the_retained_partial_inventory_and_upgrade_its_release_in_place(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('late-articles', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $previous = DB::table('release_verification_candidates')->sole();
        $holder = fn (): ?int => ($id = DB::table('releases')->where('id', $first)->value('late_article_candidate_id')) === null ? null : (int) $id;
        $this->assertSame((int) $previous->id, $holder(), 'NZB-reading workers leave the partial alone while it can grow.');

        // Backfill finds an earlier-posted article of the same upload.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));

        $rows = DB::table('release_verification_candidates')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('', $rows[0]->manifest_path, 'The new generation holds the retained inventory.');
        $this->assertSame($first, (int) $rows[0]->release_id);
        $merged = app(ManifestStore::class)->read($rows[1]->manifest_path);
        $this->assertSame(['a@b', 'c@d'], array_column($merged['files'][0]['segments'], 'messageid'));
        $this->assertSame($first, $merged['upgrades_release_id']);
        $this->assertSame((int) $rows[1]->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertNotSame($previous->identity, $rows[1]->identity);
        $this->assertSame((int) $rows[1]->id, $holder(), 'Only the merging generation may upgrade it.');
        app(CandidateRepository::class)->expire();
        $this->assertSame((int) $rows[1]->id, $holder(), 'The sweep leaves a live holder alone.');

        $guid = DB::table('releases')->where('id', $first)->value('guid');
        // Post-processing already ran on the smaller NZB; a title lookup ran on a later-normalized name.
        DB::table('releases')->where('id', $first)->update(['haspreview' => 0, 'nfostatus' => 0, 'searchname' => 'Normalized', 'imdbid' => '0000000']);
        $upgraded = $this->publishStalledPartial(null);

        $this->assertSame($first, $upgraded, 'The partial is upgraded in place, keeping its id and GUID.');
        $this->assertSame((int) $rows[1]->id, $holder(), 'Still partial: it keeps waiting for late articles.');
        $this->assertSame([$guid, 2, 3], [DB::table('releases')->where('id', $first)->value('guid'),
            (int) DB::table('releases')->where('id', $first)->value('articles_found'), (int) DB::table('releases')->where('id', $first)->value('articles_expected')]);
        $nzb = (string) app(NzbService::class)->readNzbContents($guid);
        $this->assertStringContainsString('a@b', $nzb);
        $this->assertStringContainsString('c@d', $nzb, 'Its NZB now carries the late article.');
        $this->assertSame(['Movie', null], [DB::table('releases')->where('id', $first)->value('searchname'),
            DB::table('releases')->where('id', $first)->value('imdbid')], 'The fuller name is applied and title lookups rerun.');
        $this->assertSame([-1, -1], [(int) DB::table('releases')->where('id', $first)->value('haspreview'),
            (int) DB::table('releases')->where('id', $first)->value('nfostatus')], 'Post-processing reruns on the upgraded NZB.');
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame([$first, $first], DB::table('release_verification_candidates')->orderBy('id')->pluck('release_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_a_replay_of_headers_the_partial_holds_changes_nothing(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('replayed-headers', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $before = DB::table('release_verification_candidates')->sole();

        $replay = $this->collectionForPolicy('movie.mkv', 3, $hash);
        app(CandidateRepository::class)->archive($replay);

        $after = DB::table('release_verification_candidates')->sole();
        $this->assertSame([$before->manifest_path, $before->last_unique_at, $first], [$after->manifest_path, $after->last_unique_at, (int) $after->release_id]);
        $this->assertFalse(DB::table('collections')->where('id', $replay)->exists(), 'The replayed headers are already retained.');
    }

    public function test_late_articles_for_a_published_partial_are_archived_promptly(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('hot-late-articles', true);
        $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $late = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d');
        DB::table('collections')->where('id', $late)->update(['filecheck' => 0]);
        $this->travel(1)->hours();
        $this->limits();

        app(CandidateRepository::class)->invalidateHotCollections([$late]);

        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame(3, (int) DB::table('collections')->where('id', $late)->value('filecheck'));
        $this->assertSame(now()->format('Y-m-d H:i:s'), Carbon::parse($candidate->last_unique_at)->format('Y-m-d H:i:s'));
        $this->assertNotNull($candidate->release_id, 'The published partial is not reopened.');
        $this->assertSame('sampled', $candidate->status);
    }

    public function test_inventory_of_a_deleted_partial_release_is_released_not_merged(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('deleted-partial', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        // Deleted elsewhere (admin, cleanup or audit) while its inventory is still retained.
        DB::table('releases')->where('id', $first)->delete();
        $late = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d');
        DB::table('collections')->where('id', $late)->update(['filecheck' => 0]);

        app(CandidateRepository::class)->invalidateHotCollections([$late]);
        $this->assertSame(0, (int) DB::table('collections')->where('id', $late)->value('filecheck'), 'No live partial to complete.');

        app(CandidateRepository::class)->archive($late);

        $rows = DB::table('release_verification_candidates')->orderBy('id')->get();
        $this->assertSame(['', 0], [$rows[0]->manifest_path, (int) $rows[0]->manifest_bytes]);
        $fresh = app(ManifestStore::class)->read($rows[1]->manifest_path);
        $this->assertSame(['c@d'], array_column($fresh['files'][0]['segments'], 'messageid'));
        $this->assertArrayNotHasKey('upgrades_release_id', $fresh);
        $this->assertSame((int) $rows[1]->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    /** @return array<string, array{string}> */
    public static function conflictingPostStates(): array
    {
        return ['conflicting post still pending' => ['pending'], 'conflicting post filtered' => ['filtered'],
            'conflicting post published as its own partial' => ['published']];
    }

    #[DataProvider('conflictingPostStates')]
    public function test_conflicting_late_headers_are_kept_apart_and_never_shadow_the_partial(string $state): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('conflicting-post', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $retained = DB::table('release_verification_candidates')->sole();
        // Another poster under the same collection hash.
        $other = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d');
        DB::table('collections')->where('id', $other)->update(['fromname' => 'someone-else', 'filecheck' => 0]);

        app(CandidateRepository::class)->invalidateHotCollections([$other]);
        $this->assertSame(0, (int) DB::table('collections')->where('id', $other)->value('filecheck'), 'Not the partial\'s articles.');
        app(CandidateRepository::class)->archive($other);

        $rows = DB::table('release_verification_candidates')->orderBy('id')->get();
        $this->assertSame($retained->manifest_path, $rows[0]->manifest_path, 'The clean inventory is kept.');
        $this->assertSame((int) $retained->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
        $this->assertArrayNotHasKey('upgrades_release_id', app(ManifestStore::class)->read($rows[1]->manifest_path));
        if ($state === 'filtered') {
            DB::table('release_verification_candidates')->where('id', $rows[1]->id)->update(['status' => 'filtered', 'manifest_path' => '', 'manifest_bytes' => 0]);
        } elseif ($state === 'published') {
            $own = DB::table('releases')->insertGetId(['name' => 'Other', 'searchname' => 'Other', 'guid' => 'other-partial', 'leftguid' => 'o',
                'fromname' => 'someone-else', 'postdate' => '2026-10-11 12:00:00', 'adddate' => '2026-10-11 12:00:00', 'totalpart' => 1, 'groups_id' => 1,
                'size' => 5, 'passwordstatus' => 0, 'haspreview' => -1, 'categories_id' => 2000, 'nfostatus' => -1, 'nzbstatus' => 1, 'isrenamed' => 1,
                'iscategorized' => 1, 'predb_id' => 0, 'articles_expected' => 3, 'late_article_candidate_id' => $rows[1]->id]);
            DB::table('release_verification_candidates')->where('id', $rows[1]->id)->update(['release_id' => $own, 'status' => 'sampled']);
        }
        $late = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f');
        DB::table('collections')->where('id', $late)->update(['filecheck' => 0]);
        $statusBefore = DB::table('release_verification_candidates')->where('id', $rows[1]->id)->value('status');
        app(CandidateRepository::class)->invalidateHotCollections([$late]);
        $this->assertSame(3, (int) DB::table('collections')->where('id', $late)->value('filecheck'), 'The partial\'s late article is fast-tracked.');
        $this->assertSame($statusBefore, DB::table('release_verification_candidates')->where('id', $rows[1]->id)->value('status'), 'The other post is left alone.');

        // The original poster's next article still reaches the partial's inventory, past the newer post.
        app(CandidateRepository::class)->archive($late);

        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $merged = app(ManifestStore::class)->read($generation->manifest_path);
        $this->assertSame(['a@b', 'e@f'], array_column($merged['files'][0]['segments'], 'messageid'));
        $this->assertSame($first, $merged['upgrades_release_id']);
        $this->assertSame((int) $generation->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
        $this->assertSame('', DB::table('release_verification_candidates')->where('id', $retained->id)->value('manifest_path'));
    }

    public function test_a_pending_upgrade_generation_holds_the_inventory_against_conflicting_posts(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('pending-holder', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $holder = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $this->assertSame((int) $holder->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));

        // Another poster under the same hash, while the upgrade is pending.
        $other = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'x@y');
        DB::table('collections')->where('id', $other)->update(['fromname' => 'someone-else']);
        app(CandidateRepository::class)->archive($other);
        $after = DB::table('release_verification_candidates')->where('id', $holder->id)->first();
        $this->assertSame([$holder->manifest_path, $holder->status], [$after->manifest_path, $after->status], 'The pending holder is untouched.');

        // The original poster's next article joins the pending holder, not the newer post.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f'));
        $holderManifest = app(ManifestStore::class)->read((string) DB::table('release_verification_candidates')->where('id', $holder->id)->value('manifest_path'));
        $this->assertSame(['a@b', 'c@d', 'e@f'], array_column($holderManifest['files'][0]['segments'], 'messageid'));
        $this->assertSame((int) $holder->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_a_header_rejected_holder_keeps_the_partial_fenced_until_it_ends(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('rejected-holder', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $holder = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        // Later headers may still repair it, so it is not over.
        DB::table('release_verification_candidates')->where('id', $holder->id)->update(['status' => 'header_rejected']);

        $this->limits();
        app(CandidateRepository::class)->expire();

        $this->assertSame((int) $holder->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_unreadable_late_headers_are_kept_not_deleted_as_a_filtered_replay(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('over-budget-late', true);
        $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $other = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'x@y');
        DB::table('collections')->where('id', $other)->update(['fromname' => 'someone-else']);
        app(CandidateRepository::class)->archive($other);
        $this->filterNewestCandidate();
        // Late headers past the 16 MiB metadata budget.
        $late = $this->collectionForPolicy('movie.mkv', 20000, $hash, part: 3, messageId: 'e@f');
        $binary = (int) DB::table('binaries')->where('collections_id', $late)->value('id');
        foreach (array_chunk(range(4, 15003), 500) as $numbers) {
            DB::table('parts')->insert(array_map(static fn (int $number): array => ['binaries_id' => $binary, 'partnumber' => $number,
                'messageid' => str_pad((string) $number, 1000, 'm').'@late', 'size' => 5], $numbers));
        }

        try {
            app(CandidateRepository::class)->archive($late);
        } catch (CandidateMetadataBudgetExceeded) {
            // Retained for a later pass, as for any over-budget collection.
        }

        $this->assertTrue(DB::table('collections')->where('id', $late)->exists(), 'They may be the partial\'s late articles.');
    }

    /** @return array<string, array{string}> */
    public static function newerGenerationFates(): array
    {
        return ['generation still pending' => ['pending'], 'generation completed the upgrade' => ['published'],
            'generation filtered' => ['filtered'], 'generation expired' => ['expired']];
    }

    #[DataProvider('newerGenerationFates')]
    public function test_another_posts_later_parts_join_its_own_candidate_past_a_newer_holder(string $fate): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('two-posters', true);
        $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $poster = function (int $part, string $messageId) use ($hash): int {
            $id = $this->collectionForPolicy('movie.mkv', 3, $hash, part: $part, messageId: $messageId);
            DB::table('collections')->where('id', $id)->update(['fromname' => 'poster-b']);

            return $id;
        };
        app(CandidateRepository::class)->archive($poster(1, 'b1@b'));
        $own = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        // Poster A's late article: a newer generation now holds the partial.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $this->assertGreaterThan((int) $own->id, (int) DB::table('release_verification_candidates')->max('id'));
        $generation = (int) DB::table('release_verification_candidates')->max('id');
        $partial = (int) DB::table('releases')->value('id');
        if ($fate === 'published') {
            DB::table('release_verification_candidates')->where('id', $generation)->update(['release_id' => $partial, 'status' => 'sampled', 'manifest_path' => '', 'manifest_bytes' => 0]);
            DB::table('releases')->where('id', $partial)->update(['late_article_candidate_id' => null]);
        } elseif ($fate !== 'pending') {
            DB::table('release_verification_candidates')->where('id', $generation)->update(['status' => $fate, 'manifest_path' => '', 'manifest_bytes' => 0]);
            DB::table('releases')->where('id', $partial)->update(['late_article_candidate_id' => null]);
        }

        $second = $poster(2, 'b2@b');
        DB::table('collections')->where('id', $second)->update(['filecheck' => 0]);
        app(CandidateRepository::class)->invalidateHotCollections([$second]);
        app(CandidateRepository::class)->archive($second);

        $count = DB::table('release_verification_candidates')->count();
        $this->assertSame(3, $count, 'No candidate is split off for poster B.');
        $manifest = app(ManifestStore::class)->read((string) DB::table('release_verification_candidates')->where('id', $own->id)->value('manifest_path'));
        $this->assertSame(['b1@b', 'b2@b'], array_column($manifest['files'][0]['segments'], 'messageid'));
        $this->assertNotSame('dirty', DB::table('release_verification_candidates')->where('id', $own->id)->value('status'));
    }

    public function test_defective_late_headers_never_spoil_the_partials_inventory(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('defective-late', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $retained = DB::table('release_verification_candidates')->sole();
        // Same poster, but a zero-byte segment: merging only adds, so it could never be repaired.
        $defective = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d');
        DB::table('parts')->where('messageid', 'c@d')->update(['size' => 0]);

        app(CandidateRepository::class)->archive($defective);

        $this->assertSame($retained->manifest_path, DB::table('release_verification_candidates')->where('id', $retained->id)->value('manifest_path'));
        $this->assertSame((int) $retained->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_an_over_budget_hot_collection_never_aborts_header_storage(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('over-budget-hot', true);
        $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $late = $this->collectionForPolicy('movie.mkv', 20000, $hash, part: 3, messageId: 'e@f');
        $binary = (int) DB::table('binaries')->where('collections_id', $late)->value('id');
        foreach (array_chunk(range(4, 15003), 500) as $numbers) {
            DB::table('parts')->insert(array_map(static fn (int $number): array => ['binaries_id' => $binary, 'partnumber' => $number,
                'messageid' => str_pad((string) $number, 1000, 'm').'@late', 'size' => 5], $numbers));
        }

        app(CandidateRepository::class)->invalidateHotCollections([$late]);

        $this->assertTrue(DB::table('collections')->where('id', $late)->exists());
    }

    public function test_a_generation_orphaned_while_pending_is_retired_and_later_uploads_are_admitted(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('orphaned-pending', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $orphan = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $this->assertSame($first, (int) $orphan->upgrades_release_id);
        DB::table('releases')->where('id', $first)->delete();

        $next = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f');
        app(CandidateRepository::class)->archive($next);

        $retired = DB::table('release_verification_candidates')->where('id', $orphan->id)->first();
        $this->assertSame(['expired', '', null], [$retired->status, $retired->manifest_path, $retired->upgrades_release_id], 'Its deleted partial\'s articles are gone.');
        $row = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $this->assertNotSame((int) $orphan->id, (int) $row->id, 'The retired row is never revived.');
        $manifest = app(ManifestStore::class)->read((string) $row->manifest_path);
        $this->assertSame(['e@f'], array_column($manifest['files'][0]['segments'], 'messageid'), 'Only the new upload, not the deleted partial\'s articles.');
        $this->assertSame((int) $row->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_retiring_an_orphan_before_a_filtered_replay_still_releases_its_capacity(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('orphan-then-filtered', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $other = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'x@y');
        DB::table('collections')->where('id', $other)->update(['fromname' => 'someone-else']);
        app(CandidateRepository::class)->archive($other);
        $this->filterNewestCandidate();
        DB::table('releases')->where('id', $first)->delete();

        // A replay of the filtered post: the orphan is retired before the replay returns early.
        $replay = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'x@y');
        DB::table('collections')->where('id', $replay)->update(['fromname' => 'someone-else']);
        app(CandidateRepository::class)->archive($replay);

        $this->assertSame((int) DB::table('release_verification_candidates')->sum('manifest_bytes'),
            (int) DB::table('release_verification_state')->value('pending_bytes'), 'No capacity leaks.');
    }

    public function test_a_defective_late_header_never_poisons_another_posts_pending_candidate(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('defect-past-poster-b', true);
        $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $b = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b1@b');
        DB::table('collections')->where('id', $b)->update(['fromname' => 'poster-b']);
        app(CandidateRepository::class)->archive($b);
        $own = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        // Poster A's defective late article spoils both A's partial and B's post.
        $defective = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d');
        DB::table('parts')->where('messageid', 'c@d')->update(['size' => 0]);

        app(CandidateRepository::class)->archive($defective);

        $after = DB::table('release_verification_candidates')->where('id', $own->id)->first();
        $this->assertSame([$own->manifest_path, $own->status], [$after->manifest_path, $after->status], 'Poster B\'s post is untouched.');
    }

    public function test_an_expired_pending_generation_releases_its_partial_to_workers(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('expired-generation', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();

        $this->travel((int) config('release_verification.expiry_hours') + 1)->hours();
        $this->limits();
        app(CandidateRepository::class)->expire();

        $this->assertNull(DB::table('release_verification_candidates')->where('id', $generation->id)->value('upgrades_release_id'));
        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_late_articles_never_republish_a_deleted_partial(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('deleted-before-upgrade', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        // Deleted (admin, cleanup) while the merging generation is pending.
        DB::table('releases')->where('id', $first)->delete();

        $this->assertNull($this->publishStalledPartial(null));

        $this->assertSame('expired', DB::table('release_verification_candidates')->orderByDesc('id')->value('status'));
        $this->assertSame(0, DB::table('releases')->count());

        // A later upload under the same hash is admitted, not discarded as a filtered replay.
        $next = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f');
        app(CandidateRepository::class)->archive($next);
        $this->assertFalse(DB::table('collections')->where('id', $next)->exists());
        $this->assertNotSame('', DB::table('release_verification_candidates')->orderByDesc('id')->value('manifest_path'));
    }

    public function test_retained_partial_inventory_is_released_at_expiry(): void
    {
        $this->publicationSchema(partialColumns: true);
        $releaseId = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3));
        $this->assertGreaterThan(0, (int) DB::table('release_verification_state')->value('pending_bytes'));

        $this->travel((int) config('release_verification.expiry_hours') + 1)->hours();
        $this->limits();
        app(CandidateRepository::class)->expire();

        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame('', $candidate->manifest_path);
        $this->assertSame(0, (int) $candidate->manifest_bytes);
        $this->assertSame($releaseId, (int) $candidate->release_id);
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertNull(DB::table('releases')->where('id', $releaseId)->value('late_article_candidate_id'), 'Workers may now process it.');
    }

    public function test_a_merging_generation_that_ends_unpublished_releases_the_partial_to_workers(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('filtered-generation', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $this->assertSame((int) $generation->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));

        DB::table('release_verification_candidates')->where('id', $generation->id)->update(['status' => 'filtered', 'manifest_path' => '', 'manifest_bytes' => 0]);
        $this->limits();
        app(CandidateRepository::class)->expire();

        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_oversized_partial_upload_is_filtered_instead_of_deferred(): void
    {
        $this->publicationSchema(partialColumns: true);

        // A 1-byte maximum: the stalled upload already exceeds it, and later articles only add bytes.
        $this->assertNull($this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3), limits: false));

        $this->assertSame('filtered', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, DB::table('releases')->count());
    }

    public function test_an_imported_partial_releases_its_inventory_because_no_late_headers_can_merge(): void
    {
        $this->publicationSchema(partialColumns: true);

        $releaseId = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3), source: 'import');

        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame($releaseId, (int) $candidate->release_id);
        $this->assertSame(['', 0], [$candidate->manifest_path, (int) $candidate->manifest_bytes]);
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    /** Policy-filter the newest candidate as rejectByPolicy() would, releasing its capacity. */
    private function filterNewestCandidate(): void
    {
        $newest = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        DB::table('release_verification_candidates')->where('id', $newest->id)->update(['status' => 'filtered', 'manifest_path' => '', 'manifest_bytes' => 0]);
        DB::table('release_verification_state')->decrement('pending_bytes', (int) $newest->manifest_bytes);
    }

    /** Archive a collection (unless null), let the upload stall past the hold, and publish it as partial. */
    private function publishStalledPartial(?int $collectionId, bool $limits = true, ?string $source = null): ?int
    {
        config(['release_verification.partial_after_hours' => 4]);
        $repository = app(CandidateRepository::class);
        $maximum = $limits ? 0 : 1;
        $this->limits(max: $maximum);
        if ($collectionId !== null) {
            $repository->archive($collectionId);
        }
        $this->travel(5)->hours();
        $this->limits(max: $maximum);
        $this->assertSame(1, $repository->promotePartial(10));
        $claim = $repository->claim();
        $this->assertNotNull($claim);
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $manifest += ['verification_mode' => 'spot_check', 'declared_size' => 5,
            'publication_size' => 5, 'publication_file_count' => 1, 'verified_file_names' => ['movie.mkv'],
            'verified_file_count' => 1, 'identity_hints' => [['name' => 'movie.mkv', 'source' => 'yenc-spot-check']],
            'spot_check' => ['articles' => 1, 'bytes' => 5, 'sampled_files' => 1, 'method' => 'yenc-crc-and-identity']];
        $manifest['partial'] = (new CandidateHeaderInventory)->completeness($manifest);
        if ($source !== null) {
            $manifest['metadata_source'] = $source;
        }
        Search::shouldReceive('updateRelease')->zeroOrMoreTimes();

        return app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('sampled', 'Partial sample.', $manifest));
    }

    public function test_partial_upload_below_policy_keeps_waiting_instead_of_being_filtered(): void
    {
        config(['release_verification.partial_after_hours' => 4]);
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collectionForPolicy('movie.mkv', 3));
        $this->travel(5)->hours();
        $this->limits(files: 2);
        $repository->promotePartial(10);
        $claim = $repository->claim();
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $manifest += ['verification_mode' => 'spot_check', 'verified_file_names' => ['movie.mkv'],
            'spot_check' => ['articles' => 1, 'bytes' => 5, 'sampled_files' => 1, 'method' => 'yenc-crc-and-identity'],
            'partial' => ['articles_found' => 1, 'articles_expected' => 3, 'files_found' => 1, 'files_expected' => 1]];
        $this->publicationSchema();

        try {
            app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('sampled', 'Partial sample.', $manifest));
            $this->fail('A partial upload below policy must be deferred.');
        } catch (PartialPublicationDeferredException $deferred) {
            $repository->deferPartial($claim, $deferred->getMessage());
        }

        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame('waiting_upload', $candidate->status, 'Later articles may still satisfy the policy.');
        $this->assertNotSame('', $candidate->manifest_path);
        $this->assertNull($candidate->lease_token);
        $this->assertSame(0, $repository->promotePartial(10), 'It waits one more hold window.');
        $this->assertSame(0, DB::table('releases')->count());
    }

    public function test_partial_policy_counts_only_files_written_to_the_nzb(): void
    {
        // The payload was declared but none of its articles were seen; only PAR2 would be published.
        $manifest = ['groups_id' => 1, 'expected_files' => 2, 'verification_mode' => 'spot_check',
            'spot_check' => ['articles' => 1, 'bytes' => 5, 'sampled_files' => 1, 'method' => 'yenc-crc-and-identity'],
            'verified_file_names' => ['movie.par2'], 'partial' => ['articles_found' => 1, 'articles_expected' => 4, 'files_found' => 1, 'files_expected' => 2],
            'files' => [['key' => 'movie', 'subject' => 'Movie "movie.mkv"', 'totalparts' => 3, 'segments' => []],
                ['key' => 'par2', 'subject' => 'Movie "movie.par2"', 'totalparts' => 1,
                    'segments' => [['number' => 1, 'messageid' => 'p@article', 'bytes' => 5]]]]];

        $this->assertSame('Header inventory contains only PAR2 files without a declared source payload.',
            app(CollectionReleasePolicy::class)->verifiedRejection($manifest));
    }

    public function test_passed_collection_without_authoritative_inventory_fails_closed(): void
    {
        app(CandidateRepository::class)->archive($this->collectionForPolicy());
        $claim = app(CandidateRepository::class)->claim();
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $this->publicationSchema();
        try {
            app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Missing inventory.', $manifest));
            $this->fail('Publication must require authoritative verified inventory.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Authoritative verified', $error->getMessage());
        }
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame('verifying', DB::table('release_verification_candidates')->value('status'));
    }

    /** @return array<string, array{bool}> */
    public static function cleanupFailures(): array
    {
        return ['normal cleanup' => [false], 'after commit cleanup failure' => [true]];
    }

    #[DataProvider('cleanupFailures')]
    public function test_worker_reports_durable_filtered_verdict_after_real_download_proof(bool $cleanupFails): void
    {
        config(['release_verification.free_bytes' => 0, 'release_verification.scratch_bytes' => 2147483648, 'release_verification.bytes_per_second' => 0]);
        app(CandidateRepository::class)->archive($this->collectionForPolicy());
        $this->limits(max: 1);
        $this->publicationSchema();
        if ($cleanupFails) {
            DB::listen(static function (QueryExecuted $event): void {
                if (str_starts_with($event->sql, 'update "release_verification_candidates"') && in_array('filtered', $event->bindings, true)) {
                    DB::afterCommit(static function (): void {
                        throw new \RuntimeException('Forced post-commit cleanup failure.');
                    });
                }
            });
        }
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->once()->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->once()->with('a@b', 1048576)->andReturn((new YencService)->encode('hello', 'movie.mkv'));
            $mock->shouldReceive('doQuit')->andReturn(null);
        });
        $this->artisan('releases:verify-candidates')->expectsOutputToContain('filtered — Declared collection size exceeds')->assertSuccessful();
        $this->assertSame('filtered', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    private function limits(int $min = 0, int $max = 0, int $files = 0, int $groupMin = 0, int $groupFiles = 0): void
    {
        Cache::put('configuration:ingestion', new IngestionConfigurationData(...array_replace(IngestionConfigurationData::defaults()->toArray(),
            ['minSizeToFormRelease' => $min, 'maxSizeToFormRelease' => $max, 'minFilesToFormRelease' => $files])), 300);
        DB::table('usenet_groups')->where('id', 1)->update(['minsizetoformrelease' => $groupMin, 'minfilestoformrelease' => $groupFiles]);
    }

    private function collectionForPolicy(string $name = 'movie.mkv', int $totalParts = 1, ?string $hash = null, int $part = 1, string $messageId = 'a@b'): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => $hash ?? sha1(random_bytes(8), true), 'subject' => 'Movie', 'fromname' => 'poster',
            'totalfiles' => 1, 'groups_id' => 1, 'date' => '2026-10-11 12:00:00', 'filecheck' => 3]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5('file', true), 'name' => $name, 'totalparts' => $totalParts]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => $part, 'messageid' => $messageId, 'size' => 5]);

        return $id;
    }

    private function publicationSchema(bool $partialColumns = false): void
    {
        config(['nntmux.release_dedupe_enabled' => false, 'nntmux_settings.path_to_nzbs' => config('release_verification.storage_path').'/nzbs']);
        app('files')->ensureDirectoryExists(config('nntmux_settings.path_to_nzbs'));
        Schema::create('releases', function (Blueprint $table) use ($partialColumns): void {
            $table->id();
            foreach (['name', 'searchname', 'guid', 'leftguid', 'fromname', 'postdate', 'adddate'] as $column) {
                $table->string($column);
            }
            foreach (['totalpart', 'groups_id', 'size', 'passwordstatus', 'haspreview', 'categories_id', 'nfostatus', 'nzbstatus', 'isrenamed', 'iscategorized', 'predb_id'] as $column) {
                $table->integer($column);
            }
            if ($partialColumns) {
                foreach (['articles_found', 'articles_expected', 'files_found', 'files_expected'] as $column) {
                    $table->unsignedInteger($column)->nullable();
                }
                $table->unsignedBigInteger('late_article_candidate_id')->nullable();
                $table->string('imdbid')->nullable();
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
        $this->app->instance(ReleaseMetadataResolver::class, new class(app(ReleaseCleaningService::class)) extends ReleaseMetadataResolver
        {
            public function resolve(array $manifest): array
            {
                return ['name' => 'Movie', 'searchname' => 'Movie', 'fromname' => 'poster', 'predb_id' => 0, 'isrenamed' => 1, 'categories_id' => 2000, 'naming_regex_id' => 0];
            }
        });
    }
}
