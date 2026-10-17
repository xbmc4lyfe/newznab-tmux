<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Monitoring\Collectors\ReleaseVerificationCollector;
use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseManagementService;
use App\Services\Releases\Verification\CandidateHeaderInventory;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ReleaseVerificationCommandsTest extends ReleaseVerificationCandidatesTest
{
    private int $summaryQueries = 0;

    public function test_manual_worker_reports_global_status_counts_by_default(): void
    {
        foreach (['complete', 'complete', 'filtered'] as $index => $status) {
            DB::table('release_verification_candidates')->insert([
                'identity' => sha1('summary-'.$index), 'groups_id' => 1, 'manifest_path' => '',
                'manifest_hash' => str_repeat('a', 64), 'manifest_bytes' => 0, 'status' => $status,
                'last_unique_at' => now(), 'next_attempt_at' => now(),
            ]);
        }
        $this->recordSummaryQueries();

        $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--slot' => 1]));

        $output = Artisan::output();
        $this->assertStringContainsString('complete: 2', $output);
        $this->assertStringContainsString('filtered: 1', $output);
        $this->assertSame(1, $this->summaryQueries);
        $this->assertWorkerLocksReleased();
    }

    public function test_worker_can_skip_global_summary_when_queue_is_empty(): void
    {
        $this->recordSummaryQueries();

        $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--slot' => 1, '--no-summary' => true]));

        $this->assertSame(0, $this->summaryQueries);
        $this->assertWorkerLocksReleased();
    }

    public function test_worker_without_summary_finishes_candidates_and_keeps_individual_results(): void
    {
        $this->freezeTime();
        foreach (['one', 'two'] as $name) {
            app(CandidateRepository::class)->enqueue(['identity' => sha1('no-summary-'.$name), 'groups_id' => 1, 'files' => []]);
        }
        $this->recordSummaryQueries();

        $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--limit' => 2, '--slot' => 1, '--no-summary' => true]));

        $output = Artisan::output();
        $this->assertSame(0, $this->summaryQueries);
        $candidates = DB::table('release_verification_candidates')->orderBy('id')->get();
        $this->assertCount(2, $candidates);
        foreach ($candidates as $candidate) {
            $this->assertSame('header_rejected', $candidate->status);
            $this->assertNull($candidate->lease_token);
            $this->assertNull($candidate->lease_until);
            $this->assertNull($candidate->release_id);
            $this->assertSame(now()->addSeconds((int) config('release_verification.retry_seconds'))->format('Y-m-d H:i:s'), $candidate->next_attempt_at);
            $this->assertStringContainsString($candidate->id.': unknown — '.$candidate->reason, $output);
        }
        $this->assertStringNotContainsString('unknown: 2', $output);
        $status = json_decode(file_get_contents(config('release_verification.storage_path').'/status.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $status['verified_bytes_per_second']);
        $this->assertSame('unknown', $status['scratch_bytes_status']);
        $this->assertWorkerLocksReleased();
    }

    private function recordSummaryQueries(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'release_verification_candidates') && str_contains($sql, 'count(') && str_contains($sql, 'group by')) {
                $this->summaryQueries++;
            }
        });
    }

    private function assertWorkerLocksReleased(): void
    {
        $locks = new VerificationWorkerLocks;
        try {
            $this->assertTrue($locks->start(1));
            $locks->close();
            $this->assertTrue($locks->tryExclusive());
        } finally {
            $locks->close();
        }
    }

    public function test_worker_is_discovered_and_does_not_publish_when_queue_is_empty(): void
    {
        $this->artisan('releases:verify-candidates', ['--limit' => 1])->assertSuccessful();
    }

    public function test_stale_scratch_cleanup_renews_lease_between_slow_file_deletions(): void
    {
        [$id, $directory] = $this->staleScratch();
        $filesystem = new Filesystem;
        File::partialMock()->shouldReceive('delete')->andReturnUsing(function ($path) use ($filesystem): bool {
            $deleted = $filesystem->delete($path);
            $this->travel(4)->seconds();

            return $deleted;
        });
        File::shouldReceive('deleteDirectory')->andReturnUsing(function ($path) use ($filesystem): bool {
            $deleted = $filesystem->deleteDirectory($path);
            $this->travel(16)->seconds();

            return $deleted;
        });

        $this->artisan('releases:verify-candidates', ['--limit' => 1])->assertSuccessful();

        $this->assertDirectoryDoesNotExist($directory);
        $reason = DB::table('release_verification_candidates')->where('id', $id)->value('reason');
        $this->assertStringNotContainsString('lease was lost', $reason);
    }

    public function test_stale_scratch_cleanup_stops_immediately_when_lease_is_lost(): void
    {
        [$id, $directory] = $this->staleScratch();
        $filesystem = new Filesystem;
        $deletedCount = 0;
        File::partialMock()->shouldReceive('delete')->andReturnUsing(function ($path) use ($filesystem, $id, &$deletedCount): bool {
            $deletedCount++;
            DB::table('release_verification_candidates')->where('id', $id)->update(['lease_token' => 'new-owner']);

            return $filesystem->delete($path);
        });
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldNotReceive('doConnect');
        });

        $this->artisan('releases:verify-candidates', ['--limit' => 1])->assertSuccessful();

        $this->assertSame(1, $deletedCount);
        $this->assertCount(3, glob($directory.'/*'));
        $this->assertSame('new-owner', DB::table('release_verification_candidates')->where('id', $id)->value('lease_token'));
    }

    public function test_stale_version_root_symlink_never_deletes_its_target(): void
    {
        DB::table('release_verification_scratch_budget')->where('id', 1)->update(['initialized' => true]);
        [$id, $directory] = $this->staleScratch();
        File::deleteDirectory($directory);
        $outside = config('release_verification.storage_path').'/outside';
        mkdir($outside);
        file_put_contents($outside.'/sentinel', 'keep');
        symlink($outside, $directory);
        $this->mock(NNTPService::class, static function ($mock): void {
            $mock->shouldNotReceive('doConnect');
        });

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertFileExists($outside.'/sentinel');
        $this->assertTrue(is_link($directory));
        $this->assertStringContainsString('symlink', DB::table('release_verification_candidates')->where('id', $id)->value('reason'));
    }

    public function test_linked_scratch_parent_stops_before_claim_or_stale_version_deletion(): void
    {
        [$id, $directory] = $this->staleScratch();
        $root = config('release_verification.storage_path');
        rename($root.'/scratch', $root.'/outside');
        symlink($root.'/outside', $root.'/scratch');
        $this->mock(NNTPService::class, static function ($mock): void {
            $mock->shouldNotReceive('doConnect');
        });

        $this->artisan('releases:verify-candidates')->assertFailed();

        $this->assertCount(4, glob($directory.'/*'));
        $this->assertSame('unknown', DB::table('release_verification_candidates')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('release_verification_scratch')->count());
    }

    public function test_a_header_defect_is_claimed_once_and_not_retried(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue(['identity' => sha1('defective'), 'groups_id' => 1, 'files' => []]);
        $this->assertSame('unknown', DB::table('release_verification_candidates')->value('status'));
        $this->artisan('releases:verify-candidates', ['--no-summary' => true])->assertSuccessful();
        $rejected = DB::table('release_verification_candidates')->sole();
        $this->assertSame(CandidateHeaderInventory::REJECTED, $rejected->status);
        $this->assertSame('Candidate has no file inventory to spot check.', $rejected->reason);

        // Long after the retry interval it is still not claimed again.
        $this->travel(2)->hours();
        $this->assertNull($repository->claim());
        $this->artisan('releases:verify-candidates', ['--no-summary' => true])->assertSuccessful();
        $this->assertSame($rejected->version, DB::table('release_verification_candidates')->value('version'));
        $this->assertSame(CandidateHeaderInventory::REJECTED, DB::table('release_verification_candidates')->value('status'));

    }

    public function test_an_unrevived_header_defect_still_expires_and_frees_its_manifest(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue(['identity' => sha1('defective-expiry'), 'groups_id' => 1, 'files' => []]);
        $this->artisan('releases:verify-candidates', ['--no-summary' => true])->assertSuccessful();
        $this->assertGreaterThan(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->travel((int) config('release_verification.expiry_hours') + 1)->hours();

        $repository->expire();

        $expired = DB::table('release_verification_candidates')->sole();
        $this->assertSame('expired', $expired->status);
        $this->assertSame('', $expired->manifest_path);
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_reclassification_moves_only_idle_unknown_header_defects_out_of_the_queue(): void
    {
        $rows = [];
        foreach ([['unknown', 'Invalid or duplicate article reference in candidate headers.', null],
            ['unknown', 'Conflicting header identities require resolution.', null],
            ['unknown', 'Invalid or duplicate article reference in candidate headers.', 'held-by-a-worker'],
            ['unknown', 'Article is unavailable; retry verification later.', null],
            ['pending', 'Invalid or duplicate article reference in candidate headers.', null]] as $number => [$status, $reason, $lease]) {
            $rows[] = ['identity' => sha1('reclassify-'.$number), 'groups_id' => 1, 'manifest_path' => '', 'manifest_bytes' => 0,
                'manifest_hash' => str_repeat('0', 64), 'status' => $status, 'reason' => $reason, 'lease_token' => $lease,
                'lease_until' => $lease === null ? null : now()->addMinute(), 'last_unique_at' => now(), 'next_attempt_at' => now()];
        }
        DB::table('release_verification_candidates')->insert($rows);

        $this->artisan('releases:reclassify-header-rejections', ['--chunk' => 2])->assertSuccessful()
            ->expectsOutputToContain('Reclassified 2 candidates');

        $this->assertSame([CandidateHeaderInventory::REJECTED, CandidateHeaderInventory::REJECTED, 'unknown', 'unknown', 'pending'],
            DB::table('release_verification_candidates')->orderBy('id')->pluck('status')->all());
    }

    public function test_reclassification_resumes_after_a_given_candidate(): void
    {
        $rows = [];
        foreach (range(1, 4) as $number) {
            $rows[] = ['identity' => sha1('resume-'.$number), 'groups_id' => 1, 'manifest_path' => '', 'manifest_bytes' => 0,
                'manifest_hash' => str_repeat('0', 64), 'status' => 'unknown', 'reason' => 'Invalid or duplicate article reference in candidate headers.',
                'last_unique_at' => now(), 'next_attempt_at' => now()];
        }
        DB::table('release_verification_candidates')->insert($rows);

        $this->artisan('releases:reclassify-header-rejections', ['--after' => 2, '--chunk' => 1])->assertSuccessful();

        $this->assertSame(['unknown', 'unknown', CandidateHeaderInventory::REJECTED, CandidateHeaderInventory::REJECTED],
            DB::table('release_verification_candidates')->orderBy('id')->pluck('status')->all());
    }

    public function test_old_version_cleanup_uses_bounded_index_pages_and_keeps_current_version(): void
    {
        app(CandidateRepository::class)->enqueue(['identity' => sha1('many-old-versions'), 'groups_id' => 1, 'files' => []]);
        $id = (int) DB::table('release_verification_candidates')->value('id');
        DB::table('release_verification_candidates')->where('id', $id)->update(['version' => 103]);
        $root = config('release_verification.storage_path').'/scratch';
        foreach (range(1, 102) as $version) {
            app(ScratchInventory::class)->register($id, $version);
            mkdir($root.'/'.$id.'-'.$version, 0700, true);
        }

        $this->artisan('releases:verify-candidates')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($root.'/'.$id.'-100');
        $this->assertDirectoryExists($root.'/'.$id.'-101');
        $this->assertDirectoryExists($root.'/'.$id.'-102');
        $this->assertDirectoryDoesNotExist($root.'/'.$id.'-103');
        $this->assertSame(3, DB::table('release_verification_scratch')->count());
        // A retryable outcome brings the candidate back for its next claim.
        DB::table('release_verification_candidates')->where('id', $id)->update(['status' => 'unknown', 'next_attempt_at' => now()]);
        $this->artisan('releases:verify-candidates')->assertSuccessful();
        $this->assertDirectoryDoesNotExist($root.'/'.$id.'-101');
        $this->assertDirectoryDoesNotExist($root.'/'.$id.'-102');
        $this->assertDirectoryDoesNotExist($root.'/'.$id.'-103');
        $this->assertSame(1, DB::table('release_verification_scratch')->count());
    }

    /** @return array{int, string} */
    private function staleScratch(): array
    {
        config(['release_verification.lease_seconds' => 5]);
        app(CandidateRepository::class)->enqueue(['identity' => sha1('scratch-cleanup'), 'groups_id' => 1, 'files' => []]);
        $id = (int) DB::table('release_verification_candidates')->value('id');
        DB::table('release_verification_candidates')->where('id', $id)->update(['version' => 2]);
        app(ScratchInventory::class)->register($id, 1);
        $directory = config('release_verification.storage_path').'/scratch/'.$id.'-1';
        mkdir($directory, 0700, true);
        foreach (range(1, 4) as $number) {
            file_put_contents($directory.'/'.$number, 'stale bytes');
        }

        return [$id, $directory];
    }

    public function test_expiry_releases_manifest_capacity_but_never_expires_active_lease(): void
    {
        $now = now();
        $store = app(ManifestStore::class);
        $name = $store->write(['files' => [], 'expected_files' => 0]);
        $bytes = $store->size($name);
        DB::table('release_verification_state')->update(['pending_bytes' => $bytes]);
        $id = DB::table('release_verification_candidates')->insertGetId(['identity' => sha1('expired'), 'groups_id' => 1,
            'manifest_path' => $name, 'manifest_bytes' => $bytes, 'manifest_hash' => substr($name, 0, 64),
            'last_unique_at' => $now->copy()->subHours(49), 'next_attempt_at' => $now, 'lease_until' => $now->copy()->addMinute()]);
        app(CandidateRepository::class)->expire();
        $this->assertSame('pending', DB::table('release_verification_candidates')->where('id', $id)->value('status'));
        $this->travel(2)->minutes();
        app(CandidateRepository::class)->expire();
        $this->assertSame('expired', DB::table('release_verification_candidates')->where('id', $id)->value('status'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertFileDoesNotExist($store->path($name));
    }

    public function test_trailing_article_count_is_not_a_declared_file_inventory(): void
    {
        $xml = '<nzb><file subject="movie.mkv (1/10)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
        $manifest = app(NzbManifestReader::class)->read($xml, (object) ['groups_id' => 1, 'searchname' => 'Movie', 'fromname' => 'poster', 'postdate' => '2026-10-11 12:00:00']);
        $this->assertSame(0, $manifest['expected_files']);
        $this->assertSame(10, $manifest['files'][0]['totalparts']);
    }

    public function test_monitoring_exports_verifier_capacity_and_throughput(): void
    {
        $root = (string) config('release_verification.storage_path');
        mkdir($root, 0700, true);
        file_put_contents($root.'/status.json', '{"scratch_bytes":1234,"verified_bytes_per_second":5678}');
        $families = [...app(ReleaseVerificationCollector::class)->collect()];
        $text = (new TextFormatter)->format($families);
        $this->assertStringContainsString('nntmux_release_verification_scratch_bytes 1234', $text);
        $this->assertStringContainsString('nntmux_release_verification_verified_bytes_per_second 5678', $text);
    }

    public function test_terminal_filtered_candidates_do_not_age_the_active_backlog(): void
    {
        $this->freezeTime();
        foreach (['filtered' => 100, 'expired' => 200, 'complete' => 300, 'pending' => 20, 'unrepairable' => 40] as $status => $age) {
            DB::table('release_verification_candidates')->insert([
                'identity' => sha1($status), 'groups_id' => 1, 'manifest_path' => '',
                'manifest_hash' => str_repeat('a', 64), 'manifest_bytes' => 0, 'status' => $status,
                'last_unique_at' => now()->subSeconds($age), 'next_attempt_at' => now(),
            ]);
        }
        $text = (new TextFormatter)->format([...app(ReleaseVerificationCollector::class)->collect()]);
        $this->assertStringContainsString('nntmux_release_verification_backlog_age_seconds 40', $text);
        DB::table('release_verification_candidates')->whereIn('status', ['pending', 'unrepairable'])->delete();
        $text = (new TextFormatter)->format([...app(ReleaseVerificationCollector::class)->collect()]);
        $this->assertStringContainsString('nntmux_release_verification_backlog_age_seconds 0', $text);
    }

    public function test_audit_dry_run_keeps_unknown_release_even_with_delete_option(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->string('searchname');
            $table->string('fromname');
            $table->integer('groups_id');
            $table->string('postdate');
        });
        DB::table('releases')->insert(['id' => 1, 'guid' => 'audit-guid', 'searchname' => 'Movie', 'fromname' => 'poster', 'groups_id' => 1, 'postdate' => '2026-10-11 12:00:00']);
        $xml = '<nzb><file subject="Release [1/1] &quot;movie.mkv&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
        $this->mock(NzbService::class, function ($mock) use ($xml): void {
            $mock->shouldReceive('readNzbContents')->once()->with('audit-guid')->andReturn($xml);
        });
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->once()->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->once()->andReturn('unsupported plain text');
            $mock->shouldReceive('doQuit')->andReturn(null);
        });
        $this->mock(ReleaseManagementService::class, function ($mock): void {
            $mock->shouldNotReceive('deleteSingle');
        });
        $this->artisan('releases:verify-repairable', ['--dry-run' => true, '--delete' => true, '--limit' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $report = file_get_contents((string) config('release_verification.storage_path').'/audit.jsonl');
        $this->assertStringContainsString('"verdict":"unknown"', $report);
    }

    public function test_existing_nzb_keeps_declared_part_and_file_counts(): void
    {
        $xml = '<nzb><file poster="poster" date="1791720000" subject="Release [1/2] &quot;movie.mkv&quot; (1/10)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
        $manifest = app(NzbManifestReader::class)->read($xml, (object) ['groups_id' => 1, 'searchname' => 'Movie', 'fromname' => 'poster', 'postdate' => '2026-10-11 12:00:00']);
        $this->assertSame(2, $manifest['expected_files']);
        $this->assertSame(10, $manifest['files'][0]['totalparts']);
        $this->assertCount(1, $manifest['files'][0]['segments']);
    }
}
