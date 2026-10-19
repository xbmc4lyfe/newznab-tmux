<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\CandidateMetadataBudgetExceeded;
use App\Facades\Search;
use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\CollectionReleasePolicy;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PartialPublicationDeferredException;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationResetService;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use App\Services\Releases\Verification\VerifiedNzbWriter;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use App\Services\YencService;
use App\Support\Configuration\IngestionConfigurationData;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
        $this->assertSame(['claim_check', 'metadata_resolve', 'key_derivation', 'archive_read', 'policy_check'], array_keys($reports[0]['pre_lock_phases_ns']));
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
        DB::table('releases')->where('id', $first)->update(['haspreview' => 0, 'nfostatus' => 0, 'searchname' => 'Normalized', 'imdbid' => '0000000', 'gamesinfo_id' => 5]);
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
        $this->assertSame(0, (int) DB::table('releases')->where('id', $first)->value('gamesinfo_id'), 'Its PC-game lookup reruns too.');
        $this->assertSame([-1, -1], [(int) DB::table('releases')->where('id', $first)->value('haspreview'),
            (int) DB::table('releases')->where('id', $first)->value('nfostatus')], 'Post-processing reruns on the upgraded NZB.');
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame([$first, $first], DB::table('release_verification_candidates')->orderBy('id')->pluck('release_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_an_upgrade_swaps_its_nzb_in_only_after_its_transaction_commits(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('post-commit-swap', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $guid = (string) DB::table('releases')->where('id', $first)->value('guid');
        $canonical = app(NzbService::class)->getNzbPath($guid);
        $seen = [];
        // What a download or duplicate-coverage reader (neither takes the release lock) finds as the upgrade commits.
        Event::listen(TransactionCommitting::class, static function () use ($canonical, &$seen): void {
            $staged = glob($canonical.'.c*-*.next') ?: [];
            if ($staged !== []) {
                $seen[] = [(string) gzdecode((string) file_get_contents($canonical)), (string) gzdecode((string) file_get_contents($staged[0]))];
            }
        });

        $this->assertSame($first, $this->publishStalledPartial(null));

        $this->assertCount(1, $seen, 'The upgrade staged its NZB beside the canonical file.');
        $this->assertStringNotContainsString('c@d', $seen[0][0], 'The canonical file held only the committed generation.');
        $this->assertStringContainsString('c@d', $seen[0][1]);
        $this->assertStringContainsString('c@d', (string) app(NzbService::class)->readNzbContents($guid), 'Swapped in after commit.');
        $this->assertSame([], glob($canonical.'.c*-*.next') ?: []);
    }

    public function test_a_completed_upgrade_stays_fenced_until_its_staged_nzb_is_installed(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('completed-fence', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $holder = DB::table('release_verification_candidates')->sole();
        // As a completing upgrade leaves it when its post-commit swap did not happen: published, inventory released,
        // the release still fenced, and its staged generation still beside the canonical NZB.
        DB::table('release_verification_candidates')->where('id', $holder->id)->update(['manifest_path' => '', 'manifest_bytes' => 0]);
        $guid = (string) DB::table('releases')->where('id', $first)->value('guid');
        $staged = app(NzbService::class)->getNzbPath($guid).'.c'.$holder->id.'-'.str_repeat('a', 16).'.next';
        file_put_contents($staged, gzencode('<nzb>unresolvable</nzb>'));
        // The committed evidence names neither file, so the staged generation cannot be settled yet.
        DB::table('release_archive_naming_evidence')->where('releases_id', $first)->update(['source_sha256' => NzbSourceGuard::digest('another')]);

        app(CandidateRepository::class)->expire();
        $this->assertSame((int) $holder->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'),
            'Workers that read the NZB wait while the staged generation is unresolved.');

        unlink($staged);
        Cache::forget('release_verification:settle_cursor');
        app(CandidateRepository::class)->expire();
        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'), 'Released once it is settled.');
    }

    public function test_retained_expiry_installs_a_committed_staged_nzb_before_releasing_the_fence(): void
    {
        $this->publicationSchema(partialColumns: true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, sha1('retained-staged', true)));
        $holder = DB::table('release_verification_candidates')->sole();
        // As a partial-to-partial upgrade leaves it when its post-commit swap did not happen.
        [$canonical, $staged] = $this->stageGeneration($first, (int) $holder->id, 'unresolvable');

        $this->travel((int) config('release_verification.expiry_hours') + 1)->hours();
        $this->limits();
        app(CandidateRepository::class)->expire();
        $this->assertSame('', DB::table('release_verification_candidates')->where('id', $holder->id)->value('manifest_path'), 'Its inventory is released.');
        $this->assertSame((int) $holder->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'),
            'Workers that read the NZB wait while the staged generation is unresolved.');

        DB::table('release_archive_naming_evidence')->where('releases_id', $first)->update(['source_sha256' => NzbSourceGuard::digest('<nzb>upgraded</nzb>')]);
        file_put_contents($staged, gzencode('<nzb>upgraded</nzb>'));
        Cache::forget('release_verification:settle_cursor');
        app(CandidateRepository::class)->expire();
        $this->assertSame('<nzb>upgraded</nzb>', gzdecode((string) file_get_contents($canonical)), 'The committed generation is installed first.');
        $this->assertFileDoesNotExist($staged);
        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    #[DataProvider('resetStagedGenerations')]
    public function test_a_verification_reset_installs_a_committed_staged_nzb_and_keeps_an_unresolved_one_fenced(bool $committed): void
    {
        $this->publicationSchema(partialColumns: true);
        $release = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, sha1('reset-staged', true)));
        $holder = (int) DB::table('release_verification_candidates')->sole()->id;
        [$canonical, $staged] = $this->stageGeneration($release, $holder, 'upgraded');
        if ($committed) {
            DB::table('release_archive_naming_evidence')->where('releases_id', $release)->update(['source_sha256' => NzbSourceGuard::digest('<nzb>upgraded</nzb>')]);
        }

        // The fixture keeps NZBs under the verification storage root, which a reset empties.
        $storage = config('release_verification.storage_path');
        config(['release_verification.storage_path' => $storage.'-reset']);
        try {
            app(VerificationResetService::class)->run(static fn () => null);
        } finally {
            app('files')->deleteDirectory($storage.'-reset');
            config(['release_verification.storage_path' => $storage]);
        }

        $this->assertSame(0, DB::table('release_verification_candidates')->count());
        if ($committed) {
            $this->assertSame('<nzb>upgraded</nzb>', gzdecode((string) file_get_contents($canonical)), 'The committed generation is installed first.');
            $this->assertFileDoesNotExist($staged);
            $this->assertNull(DB::table('releases')->where('id', $release)->value('late_article_candidate_id'));
        } else {
            $this->assertFileExists($staged);
            $this->assertSame($holder, (int) DB::table('releases')->where('id', $release)->value('late_article_candidate_id'),
                'Workers that read the NZB wait until the settle sweep can install it.');
        }
    }

    /** @return array<string, array{bool}> */
    public static function resetStagedGenerations(): array
    {
        return ['committed' => [true], 'unresolved' => [false]];
    }

    /**
     * A committed upgrade's staged NZB beside the canonical one; the evidence names neither file until a test says so.
     *
     * @return array{string, string}
     */
    private function stageGeneration(int $release, int $candidate, string $content): array
    {
        $canonical = app(NzbService::class)->getNzbPath((string) DB::table('releases')->where('id', $release)->value('guid'));
        $staged = $canonical.'.c'.$candidate.'-'.str_repeat('a', 16).'.next';
        file_put_contents($staged, gzencode('<nzb>'.$content.'</nzb>'));
        DB::table('release_archive_naming_evidence')->where('releases_id', $release)->update(['source_sha256' => NzbSourceGuard::digest('another')]);

        return [$canonical, $staged];
    }

    public function test_a_rolled_back_upgrade_leaves_the_published_nzb_untouched(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('rolled-back-upgrade', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $guid = (string) DB::table('releases')->where('id', $first)->value('guid');
        $canonical = app(NzbService::class)->getNzbPath($guid);
        $before = [(string) file_get_contents($canonical), fileinode($canonical)];
        // Fail the upgrade once its NZB is staged, before it commits.
        DB::listen(static function (QueryExecuted $query) use ($canonical): void {
            if (str_contains($query->sql, 'update "release_verification_candidates"') && (glob($canonical.'.c*-*.next') ?: []) !== []) {
                throw new \RuntimeException('Upgrade interrupted.');
            }
        });

        try {
            $this->publishStalledPartial(null);
            $this->fail('The upgrade must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Upgrade interrupted.', $exception->getMessage());
        }

        $this->assertSame($before, [(string) file_get_contents($canonical), fileinode($canonical)], 'The canonical file was never replaced.');
        $this->assertSame([], glob($canonical.'.c*-*.next') ?: [], 'The staged NZB is removed.');
        $this->assertSame(1, (int) DB::table('releases')->where('id', $first)->value('articles_found'));
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

    public function test_hot_invalidation_leaves_a_post_alone_that_archive_keeps_spoiling_headers_apart_from(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('orphan-then-spoiling', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $b = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b1@b');
        DB::table('collections')->where('id', $b)->update(['fromname' => 'poster-b']);
        app(CandidateRepository::class)->archive($b);
        $own = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $orphan = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $this->assertSame($first, (int) $orphan->upgrades_release_id);
        DB::table('releases')->where('id', $first)->delete();
        // Poster B's conflicting part 1: archive() retires the orphan and keeps these apart from B's post.
        $late = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b2@b');
        DB::table('collections')->where('id', $late)->update(['fromname' => 'poster-b']);

        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late]));
        app(CandidateRepository::class)->archive($late);

        $after = DB::table('release_verification_candidates')->where('id', $own->id)->first();
        $this->assertSame([$own->status, (int) $own->version], [$after->status, (int) $after->version], 'B\'s post stays claimable.');
        $this->assertSame(['b1@b'], array_column(app(ManifestStore::class)->read($after->manifest_path)['files'][0]['segments'], 'messageid'));
    }

    /** @return array<string, array{int}> */
    public static function replayGroups(): array
    {
        return ['same group' => [1], 'another group' => [2]];
    }

    #[DataProvider('replayGroups')]
    public function test_retiring_an_orphan_before_a_filtered_replay_still_releases_its_capacity(int $replayGroup): void
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
        DB::table('collections')->where('id', $replay)->update(['fromname' => 'someone-else', 'groups_id' => $replayGroup]);
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
        $this->assertSame('waiting_upload', DB::table('release_verification_candidates')->where('id', $generation->id)->value('status'),
            'Still owed its partial attempt: promoted and published, not expired.');

        // Its partial attempt was deferred (it failed policy), so it may now expire.
        DB::table('release_verification_candidates')->where('id', $generation->id)->update(['reason' => 'Partial publication deferred: below minimum size']);
        app(CandidateRepository::class)->expire();

        $this->assertSame('expired', DB::table('release_verification_candidates')->where('id', $generation->id)->value('status'));
        $this->assertNull(DB::table('release_verification_candidates')->where('id', $generation->id)->value('upgrades_release_id'));
        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_headers_two_reposts_could_take_are_kept_apart(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('two-reposts', true);
        // The same poster's repost: same subject and file count, so the same hash.
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        // Its part 1 under another message id conflicts with the first post, so it is archived apart.
        $other = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b1@b');
        app(CandidateRepository::class)->archive($other);
        $repost = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        DB::table('releases')->insert(['name' => 'Repost', 'searchname' => 'Repost', 'guid' => 'repost', 'leftguid' => 'r', 'fromname' => 'poster',
            'postdate' => '2026-10-11 12:00:00', 'adddate' => '2026-10-11 12:00:00', 'totalpart' => 1, 'groups_id' => 1, 'size' => 5,
            'passwordstatus' => 0, 'haspreview' => -1, 'categories_id' => 2000, 'nfostatus' => -1, 'nzbstatus' => 1, 'isrenamed' => 1,
            'iscategorized' => 1, 'predb_id' => 0, 'articles_expected' => 3, 'late_article_candidate_id' => $repost->id]);
        DB::table('release_verification_candidates')->where('id', $repost->id)->update(['release_id' => DB::table('releases')->max('id'), 'status' => 'sampled']);
        $before = DB::table('release_verification_candidates')->pluck('manifest_path', 'id')->all();

        // Part 3 fits either inventory without conflict.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f'));

        $this->assertSame($before, array_intersect_key(DB::table('release_verification_candidates')->pluck('manifest_path', 'id')->all(), $before),
            'Neither repost\'s inventory absorbs ambiguous headers.');
        $this->assertSame(3, DB::table('release_verification_candidates')->count());
        $this->assertArrayNotHasKey('upgrades_release_id', app(ManifestStore::class)->read((string) DB::table('release_verification_candidates')->orderByDesc('id')->value('manifest_path')));
        $this->assertNotNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_retained_inventory_is_kept_while_late_headers_await_archiving(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('pending-late-at-expiry', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d');

        $this->travel((int) config('release_verification.expiry_hours') + 1)->hours();
        $this->limits();
        app(CandidateRepository::class)->expire();

        $this->assertNotSame('', DB::table('release_verification_candidates')->where('release_id', $first)->value('manifest_path'));
        $this->assertNotNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_a_leased_orphan_never_admits_a_new_upload(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('leased-orphan', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $orphan = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        DB::table('release_verification_candidates')->where('id', $orphan->id)->update(['lease_token' => 'worker', 'lease_until' => now()->addMinutes(5)]);
        DB::table('releases')->where('id', $first)->delete();

        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f'));

        $this->assertSame($orphan->manifest_path, DB::table('release_verification_candidates')->where('id', $orphan->id)->value('manifest_path'),
            'Left to its worker, and the new upload is not merged into it.');
        $fresh = app(ManifestStore::class)->read((string) DB::table('release_verification_candidates')->orderByDesc('id')->value('manifest_path'));
        $this->assertSame(['e@f'], array_column($fresh['files'][0]['segments'], 'messageid'));
    }

    public function test_headers_a_pending_repost_also_takes_follow_the_repost_not_the_partial(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('pending-repost', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $retained = DB::table('release_verification_candidates')->sole();
        // The same poster's repost: its part 1 conflicts with the partial, so it is archived apart, unpublished.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b1@b'));
        $repost = DB::table('release_verification_candidates')->orderByDesc('id')->first();

        // Part 3 fits both the partial's inventory and the repost.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f'));

        $this->assertSame($retained->manifest_path, DB::table('release_verification_candidates')->where('id', $retained->id)->value('manifest_path'));
        $this->assertSame((int) $retained->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
        $repostManifest = app(ManifestStore::class)->read((string) DB::table('release_verification_candidates')->where('id', $repost->id)->value('manifest_path'));
        $this->assertSame(['b1@b', 'e@f'], array_column($repostManifest['files'][0]['segments'], 'messageid'));
    }

    public function test_stalled_uploads_are_promoted_only_into_idle_ready_capacity(): void
    {
        $this->publicationSchema(partialColumns: true);
        $this->limits();
        config(['release_verification.partial_after_hours' => 4, 'release_verification.partial_ready_limit' => 2]);
        $stalled = now()->subHours(5)->format('Y-m-d H:i:s');
        $row = static fn (string $name, string $status): array => ['identity' => sha1($name), 'source_identity' => sha1($name), 'groups_id' => 1,
            'manifest_path' => hash('sha256', $name).'.json.gz', 'manifest_bytes' => 0, 'manifest_hash' => hash('sha256', $name), 'status' => $status,
            'version' => 1, 'last_unique_at' => $stalled, 'next_attempt_at' => $stalled];
        DB::table('release_verification_candidates')->insert($row('fresh-a', 'ready'));
        DB::table('release_verification_candidates')->insert($row('fresh-b', 'ready'));
        foreach (range(1, 3) as $n) {
            DB::table('release_verification_candidates')->insert($row('stalled-'.$n, 'waiting_upload'));
        }
        $repository = app(CandidateRepository::class);

        $this->assertSame(0, $repository->promotePartial(10), 'New uploads ready for verification are worked first.');
        DB::table('release_verification_candidates')->where('source_identity', sha1('fresh-a'))->update(['status' => 'sampled']);
        $this->assertSame(1, $repository->promotePartial(10), 'Only up to the ready limit.');
        $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'ready')->count());
    }

    public function test_a_stalled_upload_is_owed_its_partial_attempt_for_one_expiry_window_only(): void
    {
        $this->publicationSchema(partialColumns: true);
        $this->limits();
        config(['release_verification.partial_after_hours' => 4]);
        $expiry = (int) config('release_verification.expiry_hours');
        $row = static fn (string $name, int $hoursAgo): array => ['identity' => sha1($name), 'source_identity' => sha1($name), 'groups_id' => 1,
            'manifest_path' => hash('sha256', $name).'.json.gz', 'manifest_bytes' => 0, 'manifest_hash' => hash('sha256', $name), 'status' => 'waiting_upload',
            'version' => 1, 'last_unique_at' => now()->subHours($hoursAgo)->format('Y-m-d H:i:s'), 'next_attempt_at' => now()->addHour()->format('Y-m-d H:i:s')];
        DB::table('release_verification_candidates')->insert($row('owed', $expiry + 1));
        DB::table('release_verification_candidates')->insert($row('overdue', $expiry + 5));

        app(CandidateRepository::class)->expire();

        $this->assertSame('waiting_upload', DB::table('release_verification_candidates')->where('source_identity', sha1('owed'))->value('status'));
        $this->assertSame('expired', DB::table('release_verification_candidates')->where('source_identity', sha1('overdue'))->value('status'),
            'A promotion backlog cannot keep its inventory indefinitely.');
    }

    public function test_the_settle_sweep_pages_through_held_releases_across_passes(): void
    {
        $this->publicationSchema(partialColumns: true);
        $this->limits();
        $now = now()->format('Y-m-d H:i:s');
        $release = static fn (int $id, int $holder): array => ['id' => $id, 'late_article_candidate_id' => $holder, 'guid' => 'held-'.$id]
            + array_fill_keys(['name', 'searchname', 'leftguid', 'fromname', 'postdate', 'adddate'], 'x')
            + array_fill_keys(['totalpart', 'groups_id', 'size', 'passwordstatus', 'haspreview', 'categories_id', 'nfostatus', 'nzbstatus', 'isrenamed', 'iscategorized', 'predb_id'], 0);
        // A full page of live partials, each held by its own retaining candidate, then one whose holder ended.
        $live = [];
        foreach (range(1, 1000) as $n) {
            $live[] = ['identity' => sha1('live-'.$n), 'source_identity' => sha1('live-'.$n), 'groups_id' => 1, 'manifest_path' => 'live-'.$n.'.json.gz',
                'manifest_bytes' => 0, 'manifest_hash' => hash('sha256', 'live-'.$n), 'status' => 'sampled', 'version' => 1,
                'last_unique_at' => $now, 'next_attempt_at' => $now, 'release_id' => $n];
        }
        foreach (array_chunk($live, 200) as $chunk) {
            DB::table('release_verification_candidates')->insert($chunk);
        }
        $ids = DB::table('release_verification_candidates')->orderBy('id')->pluck('id')->all();
        foreach (array_chunk(array_map(fn (int $n): array => $release($n, (int) $ids[$n - 1]), range(1, 1000)), 200) as $chunk) {
            DB::table('releases')->insert($chunk);
        }
        DB::table('releases')->insert($release(5000, 999999));

        app(CandidateRepository::class)->expire();
        $this->assertSame(999999, (int) DB::table('releases')->where('id', 5000)->value('late_article_candidate_id'), 'Beyond this pass\'s page.');
        app(CandidateRepository::class)->expire();

        $this->assertNull(DB::table('releases')->where('id', 5000)->value('late_article_candidate_id'), 'The next pass resumes and settles it.');
        $this->assertSame(1000, DB::table('releases')->whereNotNull('late_article_candidate_id')->count(), 'Live partials stay held.');
    }

    public function test_a_deleted_partials_retained_inventory_is_still_released_at_expiry(): void
    {
        $this->publicationSchema(partialColumns: true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, sha1('deleted-retained', true)));
        $this->assertGreaterThan(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        DB::table('releases')->where('id', $first)->delete();

        $this->travel((int) config('release_verification.expiry_hours') + 1)->hours();
        $this->limits();
        app(CandidateRepository::class)->expire();

        $this->assertSame(['', 0, null, null], [DB::table('release_verification_candidates')->value('manifest_path'),
            (int) DB::table('release_verification_candidates')->value('manifest_bytes'), DB::table('release_verification_candidates')->value('retained_for_release_id'),
            DB::table('release_verification_candidates')->value('retained_last_unique_at')]);
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'), 'No capacity leaks with the release.');
    }

    /** @return array<string, array{int, int}> */
    public static function blockedRetainedPrefixes(): array
    {
        return ['one page' => [100, 1], 'past the per-pass page cap' => [1000, 2]];
    }

    #[DataProvider('blockedRetainedPrefixes')]
    public function test_retained_expiry_pages_past_rows_held_by_pending_late_headers(int $blocked, int $passes): void
    {
        $this->publicationSchema(partialColumns: true);
        $this->limits();
        $cutoff = now()->subHours((int) config('release_verification.expiry_hours') + 1)->format('Y-m-d H:i:s');
        $row = static fn (int $n): array => ['identity' => sha1('held-'.$n), 'source_identity' => sha1('held-'.$n), 'groups_id' => 1,
            'manifest_path' => hash('sha256', 'held-'.$n).'.json.gz', 'manifest_bytes' => 0, 'manifest_hash' => hash('sha256', 'held-'.$n), 'status' => 'sampled',
            'version' => 1, 'last_unique_at' => $cutoff, 'next_attempt_at' => $cutoff, 'release_id' => 100000 + $n, 'retained_for_release_id' => 100000 + $n, 'retained_last_unique_at' => $cutoff];
        // Each retained partial is the candidate its release's late-article pointer names.
        $retain = function (int $n) use ($row): void {
            $id = DB::table('release_verification_candidates')->insertGetId($row($n));
            DB::table('releases')->insert(['id' => 100000 + $n, 'late_article_candidate_id' => $id, 'guid' => 'held-'.$n]
                + array_fill_keys(['name', 'searchname', 'leftguid', 'fromname', 'postdate', 'adddate'], 'x')
                + array_fill_keys(['totalpart', 'groups_id', 'size', 'passwordstatus', 'haspreview', 'categories_id', 'nfostatus', 'nzbstatus', 'isrenamed', 'iscategorized', 'predb_id'], 0));
        };
        // A full page of retained partials whose uploads still have late headers waiting to be archived.
        foreach (range(1, $blocked) as $n) {
            $retain($n);
            DB::table('collections')->insert(['collectionhash' => hex2bin(sha1('held-'.$n)), 'subject' => 'Movie', 'fromname' => 'poster',
                'totalfiles' => 1, 'groups_id' => 1, 'date' => '2026-10-11 12:00:00', 'filecheck' => 0]);
        }
        $retain($blocked + 1);

        foreach (range(1, $passes) as $pass) {
            app(CandidateRepository::class)->expire();
        }

        $this->assertSame('', DB::table('release_verification_candidates')->where('release_id', 100000 + $blocked + 1)->value('manifest_path'),
            'Not starved behind them: each pass resumes where the last stopped.');
        $this->assertSame($blocked, DB::table('release_verification_candidates')->where('manifest_path', '<>', '')->count());
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
    public function test_an_upgrade_queues_its_fingerprint_and_invalidates_cached_rows_even_when_search_sync_fails(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('search-down', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $version = (int) Cache::get('releases:cache_version', 1);
        Queue::fake();

        // Logged, not thrown, so the after-commit callbacks registered after it (manifest cleanup) still run.
        $this->assertSame($first, $this->publishStalledPartial(null, searchFails: true));

        $this->assertSame(2, (int) DB::table('releases')->where('id', $first)->value('articles_found'), 'The upgrade committed.');
        Queue::assertPushed(CollectReleaseFingerprintMetadata::class);
        $this->assertSame($version + 1, (int) Cache::get('releases:cache_version', 1), 'Cached API rows no longer describe the smaller inventory.');
    }

    public function test_an_oversized_hot_snapshot_fences_verifications_under_way_and_leaves_posts_claimable(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('over-budget-hot', true);
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash));
        $pending = DB::table('release_verification_candidates')->sole();
        DB::table('release_verification_candidates')->where('id', $pending->id)->update(['lease_token' => 'worker', 'lease_until' => now()->addMinutes(5)]);
        $late = $this->collectionForPolicy('movie.mkv', 20000, $hash, part: 3, messageId: 'e@f');
        $binary = (int) DB::table('binaries')->where('collections_id', $late)->value('id');
        foreach (array_chunk(range(4, 15003), 500) as $numbers) {
            DB::table('parts')->insert(array_map(static fn (int $number): array => ['binaries_id' => $binary, 'partnumber' => $number,
                'messageid' => str_pad((string) $number, 1000, 'm').'@late', 'size' => 5], $numbers));
        }

        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late]));

        $after = DB::table('release_verification_candidates')->where('id', $pending->id)->first();
        $this->assertSame([$pending->status, null, (int) $pending->version + 1], [$after->status, $after->lease_token, (int) $after->version],
            'The verification under way cannot publish; the post is verified again.');
        try {
            app(CandidateRepository::class)->archive($late);
        } catch (CandidateMetadataBudgetExceeded) {
            // archive() cannot merge it either.
        }
        $this->assertSame($pending->status, DB::table('release_verification_candidates')->where('id', $pending->id)->value('status'), 'Never stranded.');
    }

    public function test_over_budget_late_headers_settle_a_retained_partial_instead_of_holding_it_forever(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('over-budget-partial', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $retained = DB::table('release_verification_candidates')->sole();
        $this->assertSame((int) $retained->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
        $late = $this->collectionForPolicy('movie.mkv', 20000, $hash, part: 3, messageId: 'e@f');
        $binary = (int) DB::table('binaries')->where('collections_id', $late)->value('id');
        foreach (array_chunk(range(4, 15003), 500) as $numbers) {
            DB::table('parts')->insert(array_map(static fn (int $number): array => ['binaries_id' => $binary, 'partnumber' => $number,
                'messageid' => str_pad((string) $number, 1000, 'm').'@late', 'size' => 5], $numbers));
        }

        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late]));

        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'), 'Workers may process it.');
        $after = DB::table('release_verification_candidates')->where('id', $retained->id)->first();
        $this->assertSame(['', 0, null], [$after->manifest_path, (int) $after->manifest_bytes, $after->retained_for_release_id]);
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'), 'Its capacity is released.');
    }

    public function test_headers_a_since_published_repost_could_own_never_upgrade_the_partial(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('published-repost', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $retained = DB::table('release_verification_candidates')->sole();
        // The same poster's conflicting repost, archived apart, then published complete: its inventory is gone.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b1@b'));
        $repost = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $copy = (array) DB::table('releases')->where('id', $first)->first();
        DB::table('releases')->insert(['id' => $first + 100, 'guid' => 'repost', 'articles_found' => null, 'articles_expected' => null,
            'files_found' => null, 'files_expected' => null, 'late_article_candidate_id' => null] + $copy);
        DB::table('release_verification_candidates')->where('id', $repost->id)->update(['release_id' => $first + 100, 'status' => 'sampled',
            'manifest_path' => '', 'manifest_bytes' => 0]);

        // An unseen segment that fits the partial, and may equally be the published repost's.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'b3@b'));

        $this->assertSame((int) $retained->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'), 'No generation upgrades it.');
        $this->assertSame(['a@b'], array_column(app(ManifestStore::class)->read((string) DB::table('release_verification_candidates')->where('id', $retained->id)->value('manifest_path'))['files'][0]['segments'], 'messageid'));
        $this->assertSame(0, DB::table('release_verification_candidates')->whereNotNull('upgrades_release_id')->count());
    }

    public function test_headers_a_repost_published_into_an_older_legacy_release_could_own_never_upgrade_the_partial(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('legacy-repost', true);
        // A legacy release that predates the partial, which a repost publishes into later.
        $legacy = ['id' => 50, 'guid' => 'legacy', 'name' => 'Movie', 'searchname' => 'Movie', 'leftguid' => 'l', 'fromname' => 'poster',
            'postdate' => '2026-10-11 12:00:00', 'adddate' => now()->subDay()->format('Y-m-d H:i:s'), 'totalpart' => 1, 'groups_id' => 1, 'size' => 5,
            'passwordstatus' => 0, 'haspreview' => 0, 'categories_id' => 2000, 'nfostatus' => 0, 'nzbstatus' => NzbService::NZB_NONE,
            'isrenamed' => 0, 'iscategorized' => 1, 'predb_id' => 0];
        DB::table('releases')->insert($legacy);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $this->assertGreaterThan(50, $first);
        $retained = DB::table('release_verification_candidates')->sole();
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b1@b'));
        DB::table('release_verification_candidates')->orderByDesc('id')->limit(1)->update(['release_id' => 50, 'status' => 'sampled',
            'manifest_path' => '', 'manifest_bytes' => 0, 'verified_at' => now()->addMinute()->format('Y-m-d H:i:s')]);

        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'b3@b'));

        $this->assertSame((int) $retained->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
        $this->assertSame(0, DB::table('release_verification_candidates')->whereNotNull('upgrades_release_id')->count(), 'No generation upgrades it.');
    }

    public function test_headers_an_older_pending_repost_also_takes_never_upgrade_the_partial(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('two-reposts-pending', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $retained = DB::table('release_verification_candidates')->sole();
        // Repost B, then repost C, each archived apart by its conflicting part 1; C then gains its part 2.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b1@b'));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'c1@c'));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c2@c'));

        // B's part 2 conflicts with C but fits both B and the partial.
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'b2@b'));

        $this->assertSame((int) $retained->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'), 'No generation upgrades it.');
        $this->assertSame(['a@b'], array_column(app(ManifestStore::class)->read((string) DB::table('release_verification_candidates')->where('id', $retained->id)->value('manifest_path'))['files'][0]['segments'], 'messageid'));
        $this->assertSame(0, DB::table('release_verification_candidates')->whereNotNull('upgrades_release_id')->count());
    }

    public function test_a_generation_filtered_by_policy_releases_its_partial_to_workers_at_once(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('generation-filtered', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $this->assertSame((int) $generation->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
        $this->travel(5)->hours();
        $this->limits();
        app(CandidateRepository::class)->promotePartial(10);
        $claim = app(CandidateRepository::class)->claim();
        $this->assertSame((int) $generation->id, (int) $claim->id);

        app(CandidateRepository::class)->rejectByPolicy($claim, 'Merged inventory exceeds the maximum size.');

        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'), 'Without waiting for an expiry pass.');
    }

    public function test_a_collection_archive_never_took_stops_holding_a_retained_partial_after_an_expiry_window(): void
    {
        $this->publicationSchema(partialColumns: true);
        Schema::table('collections', fn (Blueprint $table) => $table->dateTime('dateadded')->nullable());
        $hash = sha1('unarchivable-late', true);
        // An over-budget (unarchivable) collection under the hash arrived before the partial published.
        $stuck = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f');
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, sha1('unarchivable-late-own', true)));
        DB::table('release_verification_candidates')->update(['source_identity' => bin2hex($hash)]);
        $retained = DB::table('release_verification_candidates')->sole();
        DB::table('collections')->where('id', $stuck)->update(['dateadded' => now()]);
        $this->travel((int) config('release_verification.expiry_hours') + 1)->hours();
        $this->limits();

        app(CandidateRepository::class)->expire();

        $this->assertSame('', DB::table('release_verification_candidates')->where('id', $retained->id)->value('manifest_path'), 'Released at expiry.');
        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'), 'Workers may process it.');
    }

    public function test_capacity_released_by_hot_invalidation_is_returned_after_every_collection_is_locked(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('over-budget-first', true);
        $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $late = $this->collectionForPolicy('movie.mkv', 20000, $hash, part: 3, messageId: 'e@f');
        $binary = (int) DB::table('binaries')->where('collections_id', $late)->value('id');
        foreach (array_chunk(range(4, 15003), 500) as $numbers) {
            DB::table('parts')->insert(array_map(static fn (int $number): array => ['binaries_id' => $binary, 'partnumber' => $number,
                'messageid' => str_pad((string) $number, 1000, 'm').'@late', 'size' => 5], $numbers));
        }
        // A later tracked collection in the same chunk, with its own pending post.
        $otherHash = sha1('later-collection', true);
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $otherHash));
        $other = $this->collectionForPolicy('movie.mkv', 3, $otherHash, part: 2, messageId: 'o@p');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late, $other]));

        $ledger = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'release_verification_state') && str_starts_with(strtolower($sql), 'update')));
        // The candidate lookups by upload (the manifest cleanup after commit looks rows up by path).
        $candidates = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"release_verification_candidates"')
            && str_contains($sql, '"source_identity"')));
        self::assertNotSame([], $ledger, 'The settled partial\'s capacity is returned.');
        self::assertGreaterThan(max($candidates), min($ledger), 'The capacity row is the last lock taken.');
    }

    public function test_hot_invalidation_takes_the_legacy_writer_lock_before_any_candidate_lock(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('legacy-hot', true);
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash));
        $late = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d');
        config(['release_verification.manifest_storage' => 'files']);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late]));

        $state = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"release_verification_state"')));
        $candidates = array_keys(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"release_verification_candidates"')));
        self::assertNotSame([], $state);
        self::assertLessThan(min($candidates), min($state), 'As archive() does with file manifests.');
    }

    public function test_a_covering_copy_settles_the_partials_staged_nzb_before_releasing_its_fence(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('covered-staged', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $holder = (int) DB::table('release_verification_candidates')->sole()->id;
        // An earlier partial-to-partial upgrade committed, but its post-commit swap did not happen.
        [$canonical, $staged] = $this->stageGeneration($first, $holder, 'upgraded');
        DB::table('release_archive_naming_evidence')->where('releases_id', $first)->update(['source_sha256' => NzbSourceGuard::digest('<nzb>upgraded</nzb>')]);
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $merged = app(ManifestStore::class)->read($generation->manifest_path);
        // A complete copy of the merged articles, published meanwhile.
        $copy = (array) DB::table('releases')->where('id', $first)->first();
        $copy = ['id' => $first + 100, 'guid' => 'covering-copy', 'articles_found' => null, 'articles_expected' => null,
            'files_found' => null, 'files_expected' => null, 'late_article_candidate_id' => null] + $copy;
        DB::table('releases')->insert($copy);
        app(VerifiedNzbWriter::class)->write($merged, 'covering-copy');

        $this->publishStalledPartial(null);

        $this->assertSame($first + 100, (int) DB::table('release_verification_candidates')->where('id', $generation->id)->value('release_id'), 'The copy is reused.');
        $this->assertSame('<nzb>upgraded</nzb>', gzdecode((string) file_get_contents($canonical)), 'The committed generation is installed first.');
        $this->assertFileDoesNotExist($staged);
        $this->assertNull(DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_a_covering_copy_whose_upgrade_is_still_staged_is_reused_not_duplicated(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('covering-staged-copy', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $partialNzb = (string) app(NzbService::class)->readNzbContents(DB::table('releases')->where('id', $first)->value('guid'));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $merged = app(ManifestStore::class)->read($generation->manifest_path);
        // A copy whose own upgrade to the merged articles committed, but whose swap did not happen: its canonical
        // file is still the smaller generation.
        $copy = (array) DB::table('releases')->where('id', $first)->first();
        $copy = ['id' => $first + 100, 'guid' => 'covering-copy', 'articles_found' => null, 'articles_expected' => null,
            'files_found' => null, 'files_expected' => null, 'late_article_candidate_id' => null] + $copy;
        DB::table('releases')->insert($copy);
        app(VerifiedNzbWriter::class)->write($merged, 'covering-copy');
        $canonical = app(NzbService::class)->getNzbPath('covering-copy');
        $staged = $canonical.'.c999-'.str_repeat('a', 16).'.next';
        rename($canonical, $staged);
        file_put_contents($canonical, gzencode($partialNzb));
        $evidence = array_diff_key((array) DB::table('release_archive_naming_evidence')->where('releases_id', $first)->first(), ['id' => 0, 'releases_id' => 0]);
        DB::table('release_archive_naming_evidence')->updateOrInsert(['releases_id' => $first + 100], ['release_guid' => 'covering-copy',
            'source_sha256' => NzbSourceGuard::digest((string) gzdecode((string) file_get_contents($staged)))] + $evidence);

        $this->publishStalledPartial(null);

        $this->assertSame($first + 100, (int) DB::table('release_verification_candidates')->where('id', $generation->id)->value('release_id'),
            'Coverage read the generation the copy row describes.');
        $this->assertFileDoesNotExist($staged);
        $this->assertSame(1, (int) DB::table('releases')->where('id', $first)->value('articles_found'), 'The partial is not upgraded into a second copy.');
    }

    public function test_coverage_defers_publication_while_a_candidates_staged_nzb_cannot_be_put_in_place(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('covering-unresolved-copy', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $merged = app(ManifestStore::class)->read($generation->manifest_path);
        $copy = (array) DB::table('releases')->where('id', $first)->first();
        $copy = ['id' => $first + 100, 'guid' => 'covering-copy', 'articles_found' => null, 'articles_expected' => null,
            'files_found' => null, 'files_expected' => null, 'late_article_candidate_id' => null] + $copy;
        DB::table('releases')->insert($copy);
        app(VerifiedNzbWriter::class)->write($merged, 'covering-copy');
        $staged = app(NzbService::class)->getNzbPath('covering-copy').'.c999-'.str_repeat('a', 16).'.next';
        file_put_contents($staged, gzencode('<nzb>unresolvable</nzb>'));
        // The copy's committed source is neither file: its staged generation cannot be settled.
        DB::table('release_archive_naming_evidence')->where('releases_id', $first + 100)->update(['source_sha256' => NzbSourceGuard::digest('another')]);

        try {
            $this->publishStalledPartial(null);
        } catch (\RuntimeException) {
            // Deferred: the claim is retried once the copy's NZB is resolved.
        }

        $this->assertNull(DB::table('release_verification_candidates')->where('id', $generation->id)->value('release_id'), 'Nothing is published on stale coverage.');
        $this->assertSame(1, (int) DB::table('releases')->where('id', $first)->value('articles_found'));
        $this->assertSame(2, DB::table('releases')->count());
        $this->assertFileExists($staged);
    }

    public function test_the_settle_sweep_stops_at_the_maintenance_deadline_and_resumes_after_the_last_row_it_settled(): void
    {
        $this->publicationSchema(partialColumns: true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, sha1('sweep-deadline', true)));
        $row = (array) DB::table('releases')->where('id', $first)->first();
        // Two partials whose holders are gone: each is settled when the sweep reaches it.
        foreach ([900, 901] as $pointer) {
            DB::table('releases')->insert(['id' => $pointer, 'guid' => 'held-'.$pointer, 'late_article_candidate_id' => $pointer] + $row);
        }
        Cache::forget('release_verification:settle_cursor');
        $starts = 0;
        $mayStart = static function () use (&$starts): bool {
            return ++$starts <= 2;
        };

        (new \ReflectionMethod(CandidateRepository::class, 'settleLateArticleHolders'))->invoke(app(CandidateRepository::class), $mayStart);

        $this->assertNull(DB::table('releases')->where('id', 900)->value('late_article_candidate_id'));
        $this->assertSame(901, (int) DB::table('releases')->where('id', 901)->value('late_article_candidate_id'), 'Left for the next pass.');
        $this->assertSame(900, (int) Cache::get('release_verification:settle_cursor'));

        (new \ReflectionMethod(CandidateRepository::class, 'settleLateArticleHolders'))->invoke(app(CandidateRepository::class), null);
        $this->assertNull(DB::table('releases')->where('id', 901)->value('late_article_candidate_id'), 'The next pass resumes there.');
        $this->assertSame(0, (int) Cache::get('release_verification:settle_cursor'));
    }

    public function test_the_settle_sweep_installs_a_live_holders_staged_nzb_and_keeps_it_fenced(): void
    {
        $this->publicationSchema(partialColumns: true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, sha1('live-staged', true)));
        $holder = (int) DB::table('release_verification_candidates')->sole()->id;
        [$canonical, $staged] = $this->stageGeneration($first, $holder, 'upgraded');
        DB::table('release_archive_naming_evidence')->where('releases_id', $first)->update(['source_sha256' => NzbSourceGuard::digest('<nzb>upgraded</nzb>')]);

        app(CandidateRepository::class)->expire();

        $this->assertSame('<nzb>upgraded</nzb>', gzdecode((string) file_get_contents($canonical)), 'Readers see the committed generation.');
        $this->assertFileDoesNotExist($staged);
        $this->assertSame($holder, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'), 'Its holder still awaits late articles.');
    }

    public function test_a_covering_release_with_conflicting_metadata_sends_the_generation_to_review_not_an_upgrade(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('covered-conflict', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        $merged = app(ManifestStore::class)->read($generation->manifest_path);
        // A complete copy of the merged articles, published meanwhile under a different password assertion.
        $copy = (array) DB::table('releases')->where('id', $first)->first();
        $copy = ['id' => $first + 100, 'guid' => 'covering-copy', 'articles_found' => null, 'articles_expected' => null,
            'files_found' => null, 'files_expected' => null, 'late_article_candidate_id' => null] + $copy;
        DB::table('releases')->insert($copy);
        app(VerifiedNzbWriter::class)->write(['passwords' => ['theirs']] + $merged, 'covering-copy');
        $nzb = (string) app(NzbService::class)->readNzbContents(DB::table('releases')->where('id', $first)->value('guid'));

        $this->assertNull($this->publishStalledPartial(null, passwords: ['ours']));

        $this->assertSame('metadata_review', DB::table('release_verification_candidates')->where('id', $generation->id)->value('status'));
        $this->assertSame([1, (int) $generation->id], [(int) DB::table('releases')->where('id', $first)->value('articles_found'),
            (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id')], 'The partial is neither upgraded nor released.');
        $this->assertSame($nzb, (string) app(NzbService::class)->readNzbContents(DB::table('releases')->where('id', $first)->value('guid')));
        $this->assertSame(2, DB::table('releases')->count());
    }

    /** @return array<string, array{string}> */
    public static function divertedLateHeaders(): array
    {
        return ['a pending repost also takes them' => ['repost'], 'a legacy release' => ['legacy']];
    }

    #[DataProvider('divertedLateHeaders')]
    public function test_hot_invalidation_never_marks_a_generation_archive_will_not_merge_into(string $case): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('hot-'.$case, true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d'));
        $generation = DB::table('release_verification_candidates')->orderByDesc('id')->first();
        if ($case === 'repost') {
            // The same poster's conflicting part 1 is archived apart, unpublished.
            app(CandidateRepository::class)->archive($this->collectionForPolicy('movie.mkv', 3, $hash, part: 1, messageId: 'b1@b'));
        }
        $late = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 3, messageId: 'e@f');
        if ($case === 'legacy') {
            $release = (array) DB::table('releases')->where('id', $first)->first();
            DB::table('releases')->insert(['id' => $first + 100, 'guid' => 'legacy-hot', 'nzbstatus' => NzbService::NZB_NONE,
                'articles_found' => null, 'articles_expected' => null, 'files_found' => null, 'files_expected' => null, 'late_article_candidate_id' => null] + $release);
            if (! Schema::hasColumn('collections', 'releases_id')) {
                Schema::table('collections', fn (Blueprint $table) => $table->unsignedBigInteger('releases_id')->nullable());
            }
            DB::table('collections')->where('id', $late)->update(['filecheck' => 4, 'releases_id' => $first + 100]);
        }

        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late]));
        app(CandidateRepository::class)->archive($late);

        $after = DB::table('release_verification_candidates')->where('id', $generation->id)->first();
        $this->assertSame([$generation->status, (int) $generation->version], [$after->status, (int) $after->version],
            'The generation is left claimable: these headers went elsewhere.');
        $this->assertSame(['a@b', 'c@d'], array_column(app(ManifestStore::class)->read($after->manifest_path)['files'][0]['segments'], 'messageid'));
        $this->assertSame((int) $generation->id, (int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'));
    }

    public function test_a_legacy_releases_late_headers_are_kept_apart_from_a_partial(): void
    {
        $this->publicationSchema(partialColumns: true);
        $hash = sha1('legacy-late', true);
        $first = $this->publishStalledPartial($this->collectionForPolicy('movie.mkv', 3, $hash));
        $retained = DB::table('release_verification_candidates')->sole();
        $legacy = $this->collectionForPolicy('movie.mkv', 3, $hash, part: 2, messageId: 'c@d');
        $legacyRelease = (array) DB::table('releases')->where('id', $first)->first();
        DB::table('releases')->insert(['id' => $first + 100, 'guid' => 'legacy-release', 'nzbstatus' => NzbService::NZB_NONE,
            'articles_found' => null, 'articles_expected' => null, 'files_found' => null, 'files_expected' => null, 'late_article_candidate_id' => null] + $legacyRelease);
        if (! Schema::hasColumn('collections', 'releases_id')) {
            Schema::table('collections', fn (Blueprint $table) => $table->unsignedBigInteger('releases_id')->nullable());
        }
        DB::table('collections')->where('id', $legacy)->update(['filecheck' => 4, 'releases_id' => $first + 100]);

        app(CandidateRepository::class)->archive($legacy);

        $this->assertSame([(int) $retained->id, $retained->manifest_path], [(int) DB::table('releases')->where('id', $first)->value('late_article_candidate_id'),
            DB::table('release_verification_candidates')->where('id', $retained->id)->value('manifest_path')], 'The partial keeps its own inventory.');
        $apart = DB::table('release_verification_candidates')->where('id', '>', $retained->id)->sole();
        $manifest = app(ManifestStore::class)->read($apart->manifest_path);
        $this->assertSame([$first + 100, null], [$manifest['legacy_release_id'] ?? null, $manifest['upgrades_release_id'] ?? null]);
        $this->assertSame(['c@d'], array_column($manifest['files'][0]['segments'], 'messageid'));
    }

    /** @param  list<string>  $passwords */
    private function publishStalledPartial(?int $collectionId, bool $limits = true, ?string $source = null, array $passwords = [], bool $searchFails = false): ?int
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
        if ($passwords !== []) {
            $manifest['passwords'] = $passwords;
        }
        if ($searchFails) {
            Search::swap(\Mockery::mock()->shouldReceive('updateRelease')->andThrow(new \RuntimeException('Search backend unavailable.'))->getMock());
        } else {
            Search::shouldReceive('updateRelease')->zeroOrMoreTimes();
        }

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
        $this->assertSame(0, $repository->promotePartial(10));
        $this->travel(5)->hours();
        $this->assertSame(0, $repository->promotePartial(10), 'Not spot checked again for the same policy outcome.');
        $this->assertSame(0, DB::table('releases')->count());

        // New headers requeue it.
        $repository->archive($this->collectionForPolicy('movie.mkv', 3, hex2bin($candidate->source_identity), part: 2, messageId: 'c@d'));
        $requeued = DB::table('release_verification_candidates')->where('id', $candidate->id)->first();
        $this->assertFalse(str_starts_with((string) $requeued->reason, 'Partial publication deferred:'));
        $this->assertLessThanOrEqual(now()->format('Y-m-d H:i:s'), $requeued->next_attempt_at);
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
                $table->unsignedInteger('gamesinfo_id')->default(0);
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
