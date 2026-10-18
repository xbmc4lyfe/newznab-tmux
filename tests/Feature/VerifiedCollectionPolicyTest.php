<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\CollectionReleasePolicy;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use App\Services\YencService;
use App\Support\Configuration\IngestionConfigurationData;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_a_failed_pre_lock_step_is_timed_under_the_step_that_threw(): void
    {
        config(['release_verification.publication_timing_sample_every' => 1]);
        app(CandidateRepository::class)->archive($this->collectionForPolicy());
        $claim = app(CandidateRepository::class)->claim();
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $this->mock(ReleaseMetadataResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->andThrow(new \RuntimeException('Resolver unavailable.'));
        });
        $reports = [];
        Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context = []) use (&$reports): void {
            if ($message === 'Verified release publication timing.') {
                $reports[] = $context;
            }
        });
        Log::shouldReceive('warning', 'debug', 'error')->andReturnNull();

        try {
            app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Verified.', $manifest + ['verified_bytes' => 100,
                'verified_file_names' => ['movie.mkv'], 'verified_file_count' => 1]));
            $this->fail('The resolver failure must propagate from the in-lock fallback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Resolver unavailable.', $error->getMessage());
        }

        $this->assertCount(1, $reports);
        $this->assertSame(['claim_check', 'metadata_resolve', 'key_derivation'], array_keys($reports[0]['pre_lock_phases_ns']));
        $this->assertFalse($reports[0]['keys_present']);
        $this->assertSame('threw', $reports[0]['outcome']);
    }

    public function test_only_a_sampled_body_name_with_an_extension_proves_a_parity_only_header_has_a_payload(): void
    {
        app(CandidateRepository::class)->archive($this->collectionForPolicy(name: 'Xk29dPq8LmZ0Yt5R.par2'));
        $claim = app(CandidateRepository::class)->claim();
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $manifest += ['verification_mode' => 'spot_check',
            'spot_check' => ['articles' => 1, 'bytes' => 256, 'sampled_files' => 1, 'method' => 'yenc-crc-and-identity']];
        $policy = app(CollectionReleasePolicy::class);

        foreach ([['9d3276d357227b7811b97dfd'], ['148f76878d7732b981734a9e0c51a348:stream'], ['Xk29dPq8LmZ0Yt5R.par2'], ['Xk29dPq8LmZ0Yt5R.'], ['Xk29dPq8.LmZ0Yt5RabCdEf'], ['x.mk-v']] as $names) {
            $this->assertSame('Header inventory contains only PAR2 files without a declared source payload.',
                $policy->verifiedRejection(['verified_file_names' => $names] + $manifest), $names[0]);
        }
        $this->assertNull($policy->verifiedRejection(['verified_file_names' => ['9d3276d357227b78.mkv']] + $manifest));
        $this->assertNull($policy->verifiedRejection(['verified_file_names' => ['metadata.torrent']] + $manifest));
        $this->assertNull($policy->verifiedRejection(['verified_file_names' => ['README']] + $manifest));
        $this->assertNull($policy->verifiedRejection(['verified_file_names' => ['README.']] + $manifest));
        $this->assertNull($policy->verifiedRejection(['verified_file_names' => ['payload.mkv:stream']] + $manifest));
        $this->assertNull($policy->verifiedRejection(['verified_file_names' => ['文档.数据']] + $manifest));
        $this->assertNull($policy->verifiedRejection(['verified_file_names' => ['payload.mkv ']] + $manifest));
        $this->assertSame('Header inventory contains only PAR2 files without a declared source payload.',
            $policy->verifiedRejection(['verified_file_names' => ['set.vol00+01.par2:payload.mkv']] + $manifest));
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

    public function test_remote_worker_publishes_through_the_primary_endpoint_idempotently(): void
    {
        config(['release_verification.publish_endpoint' => true, 'release_verification.publish_url' => 'http://primary.test/internal/release-verification/publish']);
        $id = $this->collectionForPolicy();
        DB::table('collections')->where('id', $id)->update(['filesize' => 100000]);
        DB::table('parts')->update(['size' => 100000]);
        $repository = app(CandidateRepository::class);
        $repository->archive($id);
        $claim = $repository->claim();
        $stored = app(ManifestStore::class)->read($claim->manifest_path);
        $local = SpotCheckVerifier::withEvidence($stored, ['articles' => 1, 'bytes' => 256, 'sampled_files' => 1,
            'verified_file_names' => ['movie.mkv'], 'identity_hints' => ['movie.mkv']]);
        $this->publicationSchema();
        Search::shouldReceive('updateRelease')->once();
        $requests = [];
        // Route the worker's HTTP request into this application, as the primary host would serve it.
        Http::fake(function (HttpRequest $request) use (&$requests) {
            $requests[] = $request->data();
            $response = $this->postJson('/internal/release-verification/publish', $request->data());

            return Http::response($response->json(), $response->status());
        });
        $result = new VerificationResult('sampled', 'Sampled article checks passed; the full payload was not downloaded.', $local);

        $releaseId = app(RemoteReleasePublisher::class)->publish($claim, $result);
        $again = app(RemoteReleasePublisher::class)->publish($claim, $result);

        $this->assertIsInt($releaseId);
        $this->assertSame($releaseId, $again);
        $this->assertArrayNotHasKey('files', $requests[0]['evidence']);
        $this->assertSame(SpotCheckVerifier::evidence($local), $requests[0]['evidence']);
        $this->assertSame(100000, (int) DB::table('releases')->where('id', $releaseId)->value('size'));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame('sampled', DB::table('release_verification_candidates')->where('id', $claim->id)->value('status'));
        $contents = app(NzbService::class)->readNzbContents((string) DB::table('releases')->where('id', $releaseId)->value('guid'));
        $this->assertIsString($contents);
        $this->assertStringContainsString('spot-check', $contents);
    }

    public function test_filtered_publication_timing_splits_the_ledger_adjustment_out_of_the_policy_phase(): void
    {
        config(['release_verification.publication_timing_sample_every' => 1]);
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collectionForPolicy(totalParts: 2));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $claim = $repository->claim();
        $manifest = app(ManifestStore::class)->read($claim->manifest_path);
        $manifest['verified_bytes'] = 5;
        $manifest['verified_file_names'] = ['movie.mkv'];
        $manifest['verified_file_count'] = 1;
        $this->limits(10, 0, 0, 0, 0);
        $this->publicationSchema();
        $reports = [];
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context) use (&$reports): void {
            $reports[] = [$message, $context];
        });

        $this->assertNull(app(VerifiedReleasePublisher::class)->publish($claim, new VerificationResult('complete', 'Verified payload.', $manifest)));

        $this->assertCount(1, $reports);
        $report = $reports[0][1];
        $this->assertSame('Verified release publication timing.', $reports[0][0]);
        $this->assertSame('filtered', $report['outcome']);
        $this->assertSame('local', $report['source']);
        $this->assertSame(['state_lock_acquisition', 'candidate_policy', 'ledger_adjust'], array_keys($report['attempts'][0]['phases_ns']));
        $this->assertArrayNotHasKey('request_phases_ns', $report);
        $this->assertSame('legacy_state', $report['attempts'][0]['lock']['path']);
        $this->assertSame(0, $report['attempts'][0]['lock']['stripe_count']);
    }

    public function test_remote_publication_logs_request_phases_once_and_the_worker_round_trip(): void
    {
        config(['release_verification.publish_endpoint' => true, 'release_verification.publish_url' => 'http://primary.test/internal/release-verification/publish',
            'release_verification.publication_timing_sample_every' => 1]);
        $id = $this->collectionForPolicy();
        DB::table('collections')->where('id', $id)->update(['filesize' => 100000]);
        DB::table('parts')->update(['size' => 100000]);
        $repository = app(CandidateRepository::class);
        $repository->archive($id);
        $claim = $repository->claim();
        $local = SpotCheckVerifier::withEvidence(app(ManifestStore::class)->read($claim->manifest_path), ['articles' => 1, 'bytes' => 256,
            'sampled_files' => 1, 'verified_file_names' => ['movie.mkv'], 'identity_hints' => ['movie.mkv']]);
        $this->publicationSchema();
        Search::shouldReceive('updateRelease')->once();
        Http::fake(function (HttpRequest $request) {
            $response = $this->postJson('/internal/release-verification/publish', $request->data());

            return Http::response($response->json(), $response->status());
        });
        $reports = [];
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context) use (&$reports): void {
            $reports[$message][] = $context;
        });

        $releaseId = app(RemoteReleasePublisher::class)->publish($claim, new VerificationResult('sampled', 'Sampled.', $local));

        $this->assertIsInt($releaseId);
        // Sampled once by the controller and passed to publish(): one publication line, no double count.
        $this->assertCount(1, $reports['Verified release publication timing.']);
        $report = $reports['Verified release publication timing.'][0];
        $this->assertSame('remote', $report['source']);
        $this->assertSame('published', $report['outcome']);
        $this->assertSame(['validation', 'published_check', 'candidate_read', 'manifest_read', 'worker_lock'], array_keys($report['request_phases_ns']));
        $this->assertSame($report['request_ns'], array_sum($report['request_phases_ns']));
        $this->assertGreaterThanOrEqual($report['request_ns'] + $report['pre_lock_ns'], $report['total_ns']);
        $this->assertIsBool($report['keys_present']);
        $this->assertIsInt($report['segment_count']);
        $this->assertCount(1, $reports['Remote publication round trip timing.']);
        $trip = $reports['Remote publication round trip timing.'][0];
        $this->assertSame(['status', 'http_status', 'round_trip_ns'], array_keys($trip));
        $this->assertSame('returned', $trip['status']);
        $this->assertSame(200, $trip['http_status']);
        $this->assertGreaterThanOrEqual($report['total_ns'], $trip['round_trip_ns']);
    }

    public function test_remote_publisher_round_trip_timing_reports_a_refusal(): void
    {
        config(['release_verification.publish_url' => 'http://primary.test/internal/release-verification/publish',
            'release_verification.publication_timing_sample_every' => 1]);
        Http::fake(['primary.test/*' => Http::response(['error' => 'Stale.'], 409)]);
        $claim = (object) ['id' => 1, 'version' => 1, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => 'lease'];
        $manifest = SpotCheckVerifier::withEvidence(['files' => [], 'groups_id' => 1], ['articles' => 1, 'bytes' => 1,
            'sampled_files' => 1, 'verified_file_names' => [], 'identity_hints' => []]);
        $trips = [];
        Log::shouldReceive('info')->andReturnUsing(function (string $message, array $context) use (&$trips): void {
            $trips[] = [$message, $context];
        });

        try {
            app(RemoteReleasePublisher::class)->publish($claim, new VerificationResult('sampled', 'Sampled.', $manifest));
            $this->fail('A refused remote publication must fail.');
        } catch (\RuntimeException) {
        }

        $this->assertCount(1, $trips);
        $this->assertSame('Remote publication round trip timing.', $trips[0][0]);
        $this->assertSame('failed', $trips[0][1]['status']);
        $this->assertSame(409, $trips[0][1]['http_status']);
    }

    public function test_remote_publication_endpoint_refuses_disabled_public_and_stale_requests(): void
    {
        $id = $this->collectionForPolicy();
        $repository = app(CandidateRepository::class);
        $repository->archive($id);
        $claim = $repository->claim();
        $this->publicationSchema();
        $payload = ['claim' => ['id' => $claim->id, 'version' => $claim->version, 'manifest_hash' => $claim->manifest_hash,
            'lease_token' => 'not-the-lease'], 'reason' => 'Sampled.', 'evidence' => ['articles' => 1, 'bytes' => 256,
                'sampled_files' => 1, 'verified_file_names' => ['movie.mkv'], 'identity_hints' => ['movie.mkv']]];

        $this->postJson('/internal/release-verification/publish', $payload)->assertNotFound();
        config(['release_verification.publish_endpoint' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/internal/release-verification/publish', $payload)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.113'])->postJson('/internal/release-verification/publish', $payload)
            ->assertStatus(409)->assertJson(['error' => 'Stale verification result cannot publish.']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.113'])->postJson('/internal/release-verification/publish',
            array_replace_recursive($payload, ['evidence' => ['articles' => 0]]))->assertUnprocessable();
        // A durable deployment pause stops publication as it stops workers.
        $payload['claim']['lease_token'] = $claim->lease_token;
        (require database_path('migrations/2026_10_17_210000_create_release_verification_pauses_table.php'))->up();
        VerificationWorkerLocks::pause('deploy', 'deployment');
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.113'])->postJson('/internal/release-verification/publish', $payload)
            ->assertStatus(409)->assertJson(['error' => 'Verification is paused for exclusive maintenance; retry later.']);
        $this->assertFalse((new VerificationWorkerLocks)->start());
        VerificationWorkerLocks::unpause('deploy');
        // An exclusive holder of the worker group (reset, audit, deployment barrier) pauses publication.
        $exclusive = new VerificationWorkerLocks;
        try {
            $this->assertTrue($exclusive->tryExclusive());
            $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.113'])->postJson('/internal/release-verification/publish', $payload)
                ->assertStatus(409)->assertJson(['error' => 'Verification is paused for exclusive maintenance; retry later.']);
        } finally {
            $exclusive->close();
        }
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame('verifying', DB::table('release_verification_candidates')->where('id', $claim->id)->value('status'));
    }

    public function test_a_retry_racing_its_committed_first_attempt_gets_the_published_release(): void
    {
        config(['release_verification.publish_endpoint' => true]);
        $repository = app(CandidateRepository::class);
        $repository->archive($this->collectionForPolicy());
        $claim = $repository->claim();
        $this->publicationSchema();
        $payload = ['claim' => ['id' => $claim->id, 'version' => $claim->version, 'manifest_hash' => $claim->manifest_hash,
            'lease_token' => $claim->lease_token], 'reason' => 'Sampled.', 'evidence' => ['articles' => 1, 'bytes' => 256,
                'sampled_files' => 1, 'verified_file_names' => ['movie.mkv'], 'identity_hints' => ['movie.mkv']]];
        $raced = false;
        // The first attempt commits right after this retry's initial published-release lookup.
        DB::listen(static function (QueryExecuted $query) use ($claim, &$raced): void {
            if (! $raced && str_contains($query->sql, '"release_id" is not null')) {
                $raced = true;
                DB::table('release_verification_candidates')->where('id', $claim->id)
                    ->update(['release_id' => 4242, 'status' => 'sampled', 'lease_token' => null, 'lease_until' => null]);
            }
        });

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.113'])->postJson('/internal/release-verification/publish', $payload)
            ->assertOk()->assertJson(['release_id' => 4242]);
        $this->assertTrue($raced);
    }

    public function test_remote_publisher_reports_the_primary_refusal(): void
    {
        config(['release_verification.publish_url' => 'http://primary.test/internal/release-verification/publish']);
        Http::fake(['primary.test/*' => Http::response(['error' => 'Stale verification result cannot publish.'], 409)]);
        $claim = (object) ['id' => 1, 'version' => 1, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => 'lease'];
        $manifest = SpotCheckVerifier::withEvidence(['files' => [], 'groups_id' => 1], ['articles' => 1, 'bytes' => 1,
            'sampled_files' => 1, 'verified_file_names' => [], 'identity_hints' => []]);

        try {
            app(RemoteReleasePublisher::class)->publish($claim, new VerificationResult('sampled', 'Sampled.', $manifest));
            $this->fail('A refused remote publication must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Remote publication failed: Stale verification result cannot publish.', $error->getMessage());
        }
        $this->assertNull(app(RemoteReleasePublisher::class)->publish($claim, new VerificationResult('unknown', 'Not verified.')));
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

    private function collectionForPolicy(string $name = 'movie.mkv', int $totalParts = 1): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1(random_bytes(8), true), 'subject' => 'Movie', 'fromname' => 'poster',
            'totalfiles' => 1, 'groups_id' => 1, 'date' => '2026-10-11 12:00:00', 'filecheck' => 3]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5('file', true), 'name' => $name, 'totalparts' => $totalParts]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => 1, 'messageid' => 'a@b', 'size' => 5]);

        return $id;
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
        $this->app->instance(ReleaseMetadataResolver::class, new class(app(ReleaseCleaningService::class)) extends ReleaseMetadataResolver
        {
            public function resolve(array $manifest): array
            {
                return ['name' => 'Movie', 'searchname' => 'Movie', 'fromname' => 'poster', 'predb_id' => 0, 'isrenamed' => 1, 'categories_id' => 2000, 'naming_regex_id' => 0];
            }
        });
    }
}
