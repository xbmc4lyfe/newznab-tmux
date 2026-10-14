<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Releases\Verification\CandidatePreparationService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerificationWorkerLocks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class VerificationReadyQueueTest extends ReleaseVerificationCandidatesTest
{
    public function test_admission_parks_missing_headers_without_publication_or_claim(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue($this->manifest('partial', 2));
        $this->assertSame('waiting_upload', DB::table('release_verification_candidates')->value('status'));
        $this->assertNull($repository->claim());
        $this->assertGreaterThan(0, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertNull(DB::table('release_verification_candidates')->value('release_id'));
    }

    public function test_ready_saturation_reserves_every_fifth_claim_for_legacy_work(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue($this->manifest('legacy'));
        DB::table('release_verification_candidates')->update(['status' => 'pending', 'next_attempt_at' => now()->subHour()]);
        for ($i = 0; $i < 8; $i++) {
            $repository->enqueue($this->manifest('ready'.$i));
        }
        $claims = [];
        for ($i = 0; $i < 5; $i++) {
            $claims[] = $repository->claim()->identity;
        }
        $this->assertSame(['ready0', 'ready1', 'ready2', 'ready3', 'legacy'], $claims);
    }

    public function test_preparation_is_metadata_only_and_preserves_retry_and_capacity(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue($this->manifest('complete'));
        $repository->enqueue($this->manifest('partial', 2));
        $repository->enqueue($this->manifest('retry'));
        DB::table('release_verification_candidates')->whereIn('identity', ['complete', 'partial'])->update(['status' => 'pending']);
        DB::table('release_verification_candidates')->where('identity', 'retry')->update(['status' => 'unknown', 'reason' => 'NNTP unavailable', 'next_attempt_at' => now()->subMinute()]);
        $bytes = DB::table('release_verification_state')->value('pending_bytes');
        $retryAt = DB::table('release_verification_candidates')->where('identity', 'retry')->value('next_attempt_at');
        $this->mock(NNTPService::class, fn ($mock) => $mock->shouldNotReceive('doConnect', 'verificationArticle'));
        $this->assertSame(0, Artisan::call('releases:prepare-candidates', ['--limit' => 3]));
        $this->assertSame('ready', DB::table('release_verification_candidates')->where('identity', 'complete')->value('status'));
        $this->assertSame('waiting_upload', DB::table('release_verification_candidates')->where('identity', 'partial')->value('status'));
        $this->assertSame('ready', DB::table('release_verification_candidates')->where('identity', 'retry')->value('status'));
        $this->assertSame($retryAt, DB::table('release_verification_candidates')->where('identity', 'retry')->value('next_attempt_at'));
        $this->assertSame('NNTP unavailable', DB::table('release_verification_candidates')->where('identity', 'retry')->value('reason'));
        $this->assertSame($bytes, DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_preparation_excludes_other_preparers_and_reset_but_not_verifier_slots(): void
    {
        $preparer = new VerificationWorkerLocks;
        $second = new VerificationWorkerLocks;
        $worker = new VerificationWorkerLocks;
        $reset = new VerificationWorkerLocks;
        try {
            $this->assertTrue($preparer->startPreparation());
            $this->assertFalse($second->startPreparation());
            $this->assertTrue($worker->start());
            $this->assertFalse($reset->tryExclusive());
            $worker->close();
            $preparer->close();
            $this->assertTrue($reset->tryExclusive());
        } finally {
            $preparer->close();
            $second->close();
            $worker->close();
            $reset->close();
        }
    }

    private function manifest(string $identity, int $total = 1): array
    {
        return ['identity' => $identity, 'groups_id' => 1, 'expected_files' => 1,
            'files' => [['key' => 'movie', 'totalparts' => $total, 'segments' => [['number' => 1, 'messageid' => $identity.'@article', 'bytes' => 5]]]]];
    }

    public function test_late_header_archival_promotes_the_merged_inventory_and_preserves_every_article(): void
    {
        $repository = app(CandidateRepository::class);
        $arrival = function (int $part): int {
            $id = DB::table('collections')->insertGetId(['collectionhash' => sha1('upload', true), 'subject' => 'Movie',
                'fromname' => 'poster', 'totalfiles' => 1, 'groups_id' => 1, 'date' => now()]);
            $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5('file', true), 'name' => 'movie.mkv', 'totalparts' => 2]);
            DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => $part, 'messageid' => 'part'.$part.'@article', 'size' => 5]);

            return $id;
        };
        $repository->archive($arrival(1));
        $before = DB::table('release_verification_candidates')->sole();
        $this->assertSame('waiting_upload', $before->status);
        $this->assertNull($repository->claim());
        $repository->archive($arrival(2));
        $after = DB::table('release_verification_candidates')->sole();
        $this->assertSame('ready', $after->status);
        $this->assertGreaterThan($before->version, $after->version);
        $manifest = app(ManifestStore::class)->read($after->manifest_path);
        $this->assertSame(['part1@article', 'part2@article'], array_column($manifest['files'][0]['segments'], 'messageid'));
        $this->assertNotNull($repository->claim());
    }

    public function test_preparation_does_not_overwrite_concurrent_claim_replay_expiry_or_backoff(): void
    {
        $repository = app(CandidateRepository::class);
        foreach (['claim', 'replay', 'expiry', 'backoff'] as $identity) {
            $repository->enqueue($this->manifest($identity));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $once = false;
        DB::listen(static function (QueryExecuted $query) use (&$once): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"manifest_path"') && str_contains($query->sql, 'order by')) {
                $once = true;
                DB::table('release_verification_candidates')->where('identity', 'claim')->update(['status' => 'verifying', 'lease_token' => 'active', 'lease_until' => now()->addHour()]);
                DB::table('release_verification_candidates')->where('identity', 'replay')->increment('version');
                DB::table('release_verification_candidates')->where('identity', 'expiry')->update(['status' => 'expired', 'manifest_path' => '']);
                DB::table('release_verification_candidates')->where('identity', 'backoff')->update(['next_attempt_at' => now()->addHour()]);
            }
        });
        $this->assertSame(0, app(CandidatePreparationService::class)->prepare(4, 30));
        $this->assertTrue($once);
        $this->assertSame('active', DB::table('release_verification_candidates')->where('identity', 'claim')->value('lease_token'));
        $this->assertSame('pending', DB::table('release_verification_candidates')->where('identity', 'replay')->value('status'));
        $this->assertSame('expired', DB::table('release_verification_candidates')->where('identity', 'expiry')->value('status'));
        $this->assertSame('pending', DB::table('release_verification_candidates')->where('identity', 'backoff')->value('status'));
    }

    public function test_preparation_bounds_inspected_records_and_does_not_accelerate_future_retries(): void
    {
        $repository = app(CandidateRepository::class);
        for ($i = 0; $i < 5; $i++) {
            $repository->enqueue($this->manifest('legacy'.$i));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $repository->enqueue($this->manifest('future'));
        DB::table('release_verification_candidates')->where('identity', 'future')->update(['status' => 'unknown', 'next_attempt_at' => now()->addHour()]);
        $this->assertSame(2, app(CandidatePreparationService::class)->prepare(2, 30));
        $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'ready')->count());
        $this->assertSame('unknown', DB::table('release_verification_candidates')->where('identity', 'future')->value('status'));
    }

    public function test_waiting_upload_expiry_releases_reserved_storage_and_complete_work_falls_back(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue($this->manifest('partial', 2));
        $this->travel(49)->hours();
        $repository->expire();
        $this->assertSame('expired', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('pending_bytes'));
        $repository->enqueue($this->manifest('legacy'));
        DB::table('release_verification_candidates')->where('identity', 'legacy')->update(['status' => 'verifying', 'lease_token' => 'expired', 'lease_until' => now()->subSecond()]);
        $claim = $repository->claim();
        $this->assertSame('legacy', $claim->identity);
        $repository->finish($claim, new VerificationResult('unknown', 'Missing articles', waitingForHeaders: true));
        $this->assertSame('waiting_upload', DB::table('release_verification_candidates')->where('identity', 'legacy')->value('status'));
    }

    public function test_preparation_read_failure_retains_storage_and_retries_other_records(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue($this->manifest('bad'));
        $repository->enqueue($this->manifest('good'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $path = DB::table('release_verification_candidates')->where('identity', 'bad')->value('manifest_path');
        file_put_contents(app(ManifestStore::class)->path($path), 'invalid compressed data');
        $bytes = DB::table('release_verification_state')->value('pending_bytes');
        $this->assertSame(2, app(CandidatePreparationService::class)->prepare(2, 30));
        $bad = DB::table('release_verification_candidates')->where('identity', 'bad')->sole();
        $this->assertSame('unknown', $bad->status);
        $this->assertSame($path, $bad->manifest_path);
        $this->assertGreaterThan(now()->format('Y-m-d H:i:s'), $bad->next_attempt_at);
        $this->assertSame('ready', DB::table('release_verification_candidates')->where('identity', 'good')->value('status'));
        $this->assertSame($bytes, DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_preparation_deadline_stops_before_the_next_metadata_read(): void
    {
        app(CandidateRepository::class)->enqueue($this->manifest('deadline'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $once = false;
        DB::listen(static function (QueryExecuted $query) use (&$once): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"manifest_path"') && str_contains($query->sql, 'order by')) {
                $once = true;
                usleep(1100000);
            }
        });
        $this->assertSame(0, app(CandidatePreparationService::class)->prepare(1, 1));
        $this->assertTrue($once);
        $this->assertSame('pending', DB::table('release_verification_candidates')->value('status'));
    }

    public function test_preparation_schedule_has_a_single_slot_and_respects_verification_switch(): void
    {
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($event): bool => is_string($event->command)
            && str_contains($event->command, 'releases:prepare-candidates')));
        $this->assertCount(1, $events);
        $this->assertStringContainsString('memory_limit=256M', $events[0]->command);
        $this->assertStringContainsString('--limit=5000 --max-seconds=30', $events[0]->command);
        $this->assertSame('* * * * *', $events[0]->expression);
        config(['release_verification.scheduled' => false]);
        $this->assertFalse($events[0]->filtersPass($this->app));
        config(['release_verification.scheduled' => true]);
        $this->assertTrue($events[0]->filtersPass($this->app));
    }
}
