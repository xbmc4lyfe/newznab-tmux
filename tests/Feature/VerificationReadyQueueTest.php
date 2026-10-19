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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

    public function test_preparation_batches_waiting_classifications_without_changing_inventories_or_capacity(): void
    {
        $repository = app(CandidateRepository::class);
        for ($i = 0; $i < 151; $i++) {
            $repository->enqueue($this->manifest('partial'.$i, 2));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $before = DB::table('release_verification_candidates')->orderBy('id')->get()->toArray();
        $bytes = DB::table('release_verification_state')->value('pending_bytes');
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'release_verification_candidates')) {
                $writes[] = $query;
            }
        });
        $report = [];
        $changed = app(CandidatePreparationService::class)->prepare(151, 30, observer: static function (array $value) use (&$report): void {
            $report = $value;
        });

        $this->assertSame(151, $changed);
        $this->assertCount(7, $writes);
        $this->assertSame(151, $report['updated']['waiting_upload']);
        $this->assertSame(0, $report['zero_row_updates']);
        $this->assertSame($bytes, DB::table('release_verification_state')->value('pending_bytes'));
        foreach (DB::table('release_verification_candidates')->orderBy('id')->get() as $index => $row) {
            $this->assertSame('waiting_upload', $row->status);
            $this->assertSame('Declared article inventory is still uploading; publication deferred.', $row->reason);
            $this->assertSame($before[$index]->manifest_path, $row->manifest_path);
            $this->assertSame($before[$index]->version, $row->version);
            $this->assertSame($before[$index]->next_attempt_at, $row->next_attempt_at);
            $this->assertSame('partial'.$index.'@article', app(ManifestStore::class)->read($row->manifest_path)['files'][0]['segments'][0]['messageid']);
            $this->assertNull($row->release_id);
        }
    }

    public function test_waiting_batch_rejects_changed_snapshots_and_counts_each_unmatched_row(): void
    {
        $this->freezeTime();
        foreach (['claim', 'version', 'unchanged'] as $identity) {
            app(CandidateRepository::class)->enqueue($this->manifest($identity, 2));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $once = false;
        DB::listen(static function (QueryExecuted $query) use (&$once): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"manifest_path"') && str_contains($query->sql, 'order by')) {
                $once = true;
                DB::table('release_verification_candidates')->where('identity', 'claim')->update(['lease_token' => 'other']);
                DB::table('release_verification_candidates')->where('identity', 'version')->increment('version');
            }
        });
        $report = [];
        $this->assertSame(1, app(CandidatePreparationService::class)->prepare(3, 30, observer: static function (array $value) use (&$report): void {
            $report = $value;
        }));
        $this->assertTrue($once);
        $this->assertSame(2, $report['zero_row_updates']);
        $this->assertSame(1, $report['updated']['waiting_upload']);
        $this->assertSame(['pending', 'pending', 'waiting_upload'], DB::table('release_verification_candidates')->orderBy('id')->pluck('status')->all());
    }

    public function test_failed_waiting_batch_retains_a_durable_prefix_and_recovers_without_row_fallback(): void
    {
        for ($i = 0; $i < 51; $i++) {
            app(CandidateRepository::class)->enqueue($this->manifest('failure'.$i, 2));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $bytes = DB::table('release_verification_state')->value('pending_bytes');
        $id = (int) DB::table('release_verification_candidates')->where('identity', 'failure27')->value('id');
        DB::unprepared("CREATE TRIGGER fail_waiting_batch BEFORE UPDATE ON release_verification_candidates WHEN NEW.status = 'waiting_upload' AND NEW.id = {$id} BEGIN SELECT RAISE(ABORT, 'qualification batch failure'); END");
        $report = [];
        try {
            app(CandidatePreparationService::class)->prepare(51, 30, observer: static function (array $value) use (&$report): void {
                $report = $value;
            });
            $this->fail('A failed statement must propagate without per-row fallback.');
        } catch (QueryException $failure) {
            $this->assertStringContainsString('qualification batch failure', $failure->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER fail_waiting_batch');
        }
        $this->assertSame('error', $report['stop_reason']);
        $this->assertSame(50, $report['inspected']);
        $this->assertSame(25, $report['updated']['waiting_upload']);
        $this->assertSame(25, DB::table('release_verification_candidates')->where('status', 'waiting_upload')->count());
        $this->assertSame(26, DB::table('release_verification_candidates')->where('status', 'pending')->count());
        $this->assertSame(26, app(CandidatePreparationService::class)->prepare(51, 30));
        $this->assertSame(51, DB::table('release_verification_candidates')->where('status', 'waiting_upload')->count());
        $this->assertSame($bytes, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame(0, DB::table('release_verification_candidates')->whereNotNull('release_id')->count());
    }

    public function test_deadline_flushes_admitted_waiting_rows_without_reading_another_manifest(): void
    {
        foreach (['first', 'unread'] as $identity) {
            app(CandidateRepository::class)->enqueue($this->manifest($identity, 2));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $advances = 0;
        $report = [];
        $this->assertSame(1, app(CandidatePreparationService::class)->prepare(2, 1, static function (bool $advance) use (&$advances): bool {
            if ($advance) {
                $advances++;
                usleep(1100000);
            }

            return true;
        }, static function (array $value) use (&$report): void {
            $report = $value;
        }));
        $this->assertSame(1, $advances);
        $this->assertSame('deadline', $report['stop_reason']);
        $this->assertSame(1, $report['inspected']);
        $this->assertSame(['waiting_upload', 'pending'], DB::table('release_verification_candidates')->orderBy('id')->pluck('status')->all());
    }

    public function test_waiting_batches_preserve_reason_boundaries_and_immediate_ready_and_retry_updates(): void
    {
        $this->freezeTime();
        $manifests = [
            $this->manifest('article-one', 2), $this->manifest('article-two', 2),
            array_replace($this->manifest('file-one'), ['expected_files' => 2]),
            array_replace($this->manifest('file-two'), ['expected_files' => 2]),
            $this->manifest('complete'),
            array_replace($this->manifest('conflict'), ['conflict' => true]),
            $this->manifest('article-three', 2),
        ];
        foreach ($manifests as $manifest) {
            app(CandidateRepository::class)->enqueue($manifest);
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending', 'reason' => 'old reason']);
        $originalRetry = DB::table('release_verification_candidates')->where('identity', 'complete')->value('next_attempt_at');
        $updates = [];
        DB::listen(static function (QueryExecuted $query) use (&$updates): void {
            if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'release_verification_candidates')) {
                $updates[] = $query->bindings[0];
            }
        });
        $this->assertSame(7, app(CandidatePreparationService::class)->prepare(7, 30));
        $this->assertSame(['waiting_upload', 'waiting_upload', 'ready', 'header_rejected', 'waiting_upload'], $updates);
        $rows = DB::table('release_verification_candidates')->orderBy('id')->get();
        $this->assertSame($rows[0]->reason, $rows[1]->reason);
        $this->assertSame($rows[2]->reason, $rows[3]->reason);
        $this->assertNotSame($rows[0]->reason, $rows[2]->reason);
        $this->assertSame('old reason', $rows[4]->reason);
        $this->assertSame($originalRetry, $rows[4]->next_attempt_at);
        // A header defect leaves the claim queue rather than being retried after retry_seconds.
        $this->assertSame('header_rejected', $rows[5]->status);
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
        // Without partial publication: a stalled upload owed its partial attempt is published, not expired.
        config(['release_verification.partial_after_hours' => 0]);
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

    public function test_unknown_preparation_updates_are_immediate_with_individual_retry_timestamps(): void
    {
        $this->freezeTime();
        foreach (['first-unknown', 'second-unknown'] as $identity) {
            app(CandidateRepository::class)->enqueue($this->manifest($identity));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        foreach (DB::table('release_verification_candidates')->get() as $row) {
            file_put_contents(app(ManifestStore::class)->path($row->manifest_path), 'invalid gzip');
        }
        $observed = [];
        DB::listen(function (QueryExecuted $query) use (&$observed): void {
            if (! str_starts_with($query->sql, 'update') || ! str_contains($query->sql, 'release_verification_candidates')) {
                return;
            }
            $observed[] = DB::table('release_verification_candidates')->orderBy('id')->get(['status', 'next_attempt_at'])->toArray();
            if (count($observed) === 1) {
                $this->travel(1)->seconds();
            }
        });
        $this->assertSame(2, app(CandidatePreparationService::class)->prepare(2, 30));
        $this->assertCount(2, $observed);
        $this->assertSame(['unknown', 'pending'], array_column($observed[0], 'status'));
        $this->assertSame(['unknown', 'unknown'], array_column($observed[1], 'status'));
        $this->assertSame($observed[0][0]->next_attempt_at, $observed[1][0]->next_attempt_at);
        $this->assertSame(1, strtotime($observed[1][1]->next_attempt_at) - strtotime($observed[1][0]->next_attempt_at));
    }

    public function test_unknown_preparation_does_not_overwrite_a_new_generation_after_selection(): void
    {
        app(CandidateRepository::class)->enqueue($this->manifest('unknown-generation'));
        DB::table('release_verification_candidates')->update(['status' => 'pending', 'reason' => 'retained']);
        $before = DB::table('release_verification_candidates')->sole();
        file_put_contents(app(ManifestStore::class)->path($before->manifest_path), 'invalid gzip');
        $changedGeneration = false;
        DB::listen(static function (QueryExecuted $query) use (&$changedGeneration): void {
            if (! $changedGeneration && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"manifest_path"') && str_contains($query->sql, 'order by')) {
                $changedGeneration = true;
                DB::table('release_verification_candidates')->increment('version');
            }
        });
        $report = [];
        $this->assertSame(0, app(CandidatePreparationService::class)->prepare(1, 30, observer: static function (array $value) use (&$report): void {
            $report = $value;
        }));
        $after = DB::table('release_verification_candidates')->sole();
        $this->assertTrue($changedGeneration);
        $this->assertSame('pending', $after->status);
        $this->assertSame('retained', $after->reason);
        $this->assertSame($before->next_attempt_at, $after->next_attempt_at);
        $this->assertSame(1, $report['zero_row_updates']);
    }

    public function test_failed_unknown_update_preserves_the_committed_prefix(): void
    {
        $this->assertUnknownUpdateFailureBoundary(false);
    }

    public function test_failed_unknown_update_preserves_the_callers_rollback_boundary(): void
    {
        $this->assertUnknownUpdateFailureBoundary(true);
    }

    private function assertUnknownUpdateFailureBoundary(bool $outerTransaction): void
    {
        foreach (['prefix-unknown', 'failure-unknown'] as $identity) {
            app(CandidateRepository::class)->enqueue($this->manifest($identity));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $rows = DB::table('release_verification_candidates')->orderBy('id')->get();
        foreach ($rows as $row) {
            file_put_contents(app(ManifestStore::class)->path($row->manifest_path), 'invalid gzip');
        }
        $failureId = (int) $rows[1]->id;
        DB::unprepared("CREATE TRIGGER fail_unknown_update BEFORE UPDATE ON release_verification_candidates WHEN NEW.status = 'unknown' AND NEW.id = {$failureId} BEGIN SELECT RAISE(ABORT, 'qualification scalar failure'); END");
        if ($outerTransaction) {
            DB::beginTransaction();
        }
        try {
            app(CandidatePreparationService::class)->prepare(2, 30);
            $this->fail('The second update failure must propagate.');
        } catch (QueryException $failure) {
            $this->assertStringContainsString('qualification scalar failure', $failure->getMessage());
            $this->assertSame(['unknown', 'pending'], DB::table('release_verification_candidates')->orderBy('id')->pluck('status')->all());
        } finally {
            if ($outerTransaction) {
                DB::rollBack();
            }
            DB::unprepared('DROP TRIGGER fail_unknown_update');
        }
        $this->assertSame($outerTransaction ? ['pending', 'pending'] : ['unknown', 'pending'], DB::table('release_verification_candidates')->orderBy('id')->pluck('status')->all());
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
        $this->assertStringContainsString('--limit=5000 --max-seconds=30 --diagnostics', $events[0]->command);
        $this->assertSame('* * * * *', $events[0]->expression);
        config(['release_verification.scheduled' => false]);
        $this->assertFalse($events[0]->filtersPass($this->app));
        config(['release_verification.scheduled' => true]);
        $this->assertTrue($events[0]->filtersPass($this->app));
    }

    public function test_single_candidate_worker_runs_preserve_legacy_fairness_across_restarts(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue($this->manifest('legacy'));
        DB::table('release_verification_candidates')->update(['status' => 'pending', 'next_attempt_at' => now()->subHour()]);
        for ($i = 0; $i < 5; $i++) {
            $repository->enqueue($this->manifest('ready'.$i));
        }
        $claims = [];
        DB::listen(static function (QueryExecuted $query) use (&$claims): void {
            if (str_starts_with($query->sql, 'update "release_verification_candidates"') && ($query->bindings[0] ?? null) === 'verifying') {
                $claims[] = end($query->bindings);
            }
        });
        $this->mock(NNTPService::class, static function ($mock): void {
            $mock->shouldReceive('doConnect')->times(5)->with(false)->andReturn(false);
            $mock->shouldReceive('doQuit')->andReturn(null);
            $mock->shouldNotReceive('verificationArticle');
        });
        for ($run = 0; $run < 5; $run++) {
            $this->assertSame(0, Artisan::call('releases:verify-candidates', ['--limit' => 1, '--slot' => 1]));
        }
        $this->assertSame([2, 3, 4, 5, 1], $claims);
    }

    public function test_preparation_prioritizes_unclassified_backlog_without_starving_retries(): void
    {
        $bytes = $this->seedPreparationQueues();

        $this->assertSame(500, app(CandidatePreparationService::class)->prepare(500, 30));

        $this->assertSame(400, DB::table('release_verification_candidates')->where('status', 'ready')->where('identity', 'like', 'pending-%')->count());
        $this->assertSame(100, DB::table('release_verification_candidates')->where('status', 'ready')->where('identity', 'like', 'unknown-%')->count());
        $this->assertSame(500, DB::table('release_verification_candidates')->where('status', 'ready')->count());
        $this->assertSame($bytes, (int) DB::table('release_verification_state')->value('pending_bytes'));
    }

    public function test_short_preparation_runs_preserve_retry_turns_and_an_expired_deadline_does_not_spend_one(): void
    {
        $this->seedPreparationQueues();
        for ($run = 0; $run < 4; $run++) {
            $this->assertSame(0, Artisan::call('releases:prepare-candidates', ['--limit' => 100]));
        }
        $once = false;
        DB::listen(static function (QueryExecuted $query) use (&$once): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'manifest_path')
                && in_array('unknown', $query->bindings, true)) {
                $once = true;
                usleep(1100000);
            }
        });
        $this->assertSame(0, Artisan::call('releases:prepare-candidates', ['--limit' => 100, '--max-seconds' => 1]));
        $this->assertTrue($once, 'The fifth invocation must select the retry queue.');
        $this->assertSame(400, DB::table('release_verification_candidates')->where('status', 'ready')->count());
        $this->assertSame(0, Artisan::call('releases:prepare-candidates', ['--limit' => 100]));
        $this->assertSame(400, DB::table('release_verification_candidates')->where('status', 'ready')->where('identity', 'like', 'pending-%')->count());
        $this->assertSame(100, DB::table('release_verification_candidates')->where('status', 'ready')->where('identity', 'like', 'unknown-%')->count());
    }

    public function test_preparation_diagnostics_count_real_classifications_and_read_failures(): void
    {
        $this->freezeTime();
        $repository = app(CandidateRepository::class);
        $repository->enqueue($this->manifest('complete'));
        $repository->enqueue($this->manifest('partial', 2));
        $repository->enqueue(array_replace($this->manifest('conflict'), ['conflict' => true]));
        $repository->enqueue($this->manifest('bad-reference'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        DB::table('release_verification_candidates')->where('identity', 'bad-reference')->update(['manifest_path' => 'invalid-private-reference']);
        $bytes = DB::table('release_verification_state')->value('pending_bytes');
        $reports = [];

        $this->assertSame(4, app(CandidatePreparationService::class)->prepare(10, 30, null,
            static function (array $report) use (&$reports): void {
                $reports[] = $report;
            }));

        $this->assertCount(1, $reports);
        $report = $reports[0];
        $this->assertSame(4, $report['inspected']);
        $this->assertSame(['ready' => 1, 'waiting_upload' => 1, 'unknown' => 0, 'header_rejected' => 1], $report['assessments']);
        $this->assertSame(['ready' => 1, 'waiting_upload' => 1, 'unknown' => 1, 'header_rejected' => 1], $report['updated']);
        $this->assertSame(1, $report['ready_promotions']);
        $this->assertSame(1, $report['read_assessment_failures']);
        $this->assertSame(0, $report['zero_row_updates']);
        $this->assertSame('exhausted', $report['stop_reason']);
        $this->assertFalse($report['limit_reached']);
        $this->assertFalse($report['deadline_reached']);
        $this->assertTrue($report['queues_exhausted']);
        $this->assertSame($bytes, DB::table('release_verification_state')->value('pending_bytes'));
        $this->assertSame('unknown', DB::table('release_verification_candidates')->where('identity', 'bad-reference')->value('status'));
        $this->assertSame(now()->addSeconds(900)->format('Y-m-d H:i:s'), DB::table('release_verification_candidates')->where('identity', 'bad-reference')->value('next_attempt_at'));
        $this->assertSame(['limit', 'max_seconds', 'inspected', 'assessments', 'updated', 'ready_promotions', 'zero_row_updates', 'read_assessment_failures', 'stop_reason', 'limit_reached', 'deadline_reached', 'queues_exhausted', 'durations_ms', 'phase_distributions', 'manifest_sizes'], array_keys($report));
        $this->assertSame(['selection', 'read_assessment', 'update', 'total'], array_keys($report['durations_ms']));
        foreach (['file_read', 'gzip_decode', 'hash', 'json_decode', 'assessment'] as $phase) {
            $this->assertSame(3, $report['phase_distributions'][$phase]['count']);
            $this->assertSame(0, $report['phase_distributions'][$phase]['failures']);
            $this->assertSame(3, array_sum($report['phase_distributions'][$phase]['latency_buckets']));
        }
        $this->assertSame(3, array_sum($report['manifest_sizes']['compressed_bytes']));
        $this->assertSame(3, $report['manifest_sizes']['files']['le_1']);
        foreach ($report['durations_ms'] as $duration) {
            $this->assertGreaterThanOrEqual(0, $duration);
        }
        $this->assertGreaterThanOrEqual(array_sum(array_slice($report['durations_ms'], 0, 3)), $report['durations_ms']['total']);
    }

    public function test_preparation_diagnostics_distinguish_cap_from_queue_exhaustion(): void
    {
        $repository = app(CandidateRepository::class);
        $repository->enqueue($this->manifest('first'));
        $repository->enqueue($this->manifest('second'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $reports = [];
        $observer = static function (array $report) use (&$reports): void {
            $reports[] = $report;
        };

        $this->assertSame(1, app(CandidatePreparationService::class)->prepare(1, 30, null, $observer));
        $this->assertSame(1, app(CandidatePreparationService::class)->prepare(10, 30, null, $observer));

        $this->assertSame(['limit', 'exhausted'], array_column($reports, 'stop_reason'));
        $this->assertTrue($reports[0]['limit_reached']);
        $this->assertFalse($reports[0]['queues_exhausted']);
        $this->assertSame(1, $reports[0]['inspected']);
        $this->assertSame(1, DB::table('release_verification_candidates')->where('identity', 'first')->where('status', 'ready')->count());
        $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'ready')->count());
    }

    public function test_preparation_diagnostics_deadline_before_first_read_preserves_fairness(): void
    {
        app(CandidateRepository::class)->enqueue($this->manifest('deadline'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $once = false;
        $advances = 0;
        $reports = [];
        DB::listen(static function (QueryExecuted $query) use (&$once): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"manifest_path"') && str_contains($query->sql, 'order by')) {
                $once = true;
                usleep(1100000);
            }
        });
        $this->assertSame(0, app(CandidatePreparationService::class)->prepare(2, 1,
            static function (bool $advance) use (&$advances): bool {
                $advances += (int) $advance;

                return true;
            },
            static function (array $report) use (&$reports): void {
                $reports[] = $report;
            }));
        $this->assertTrue($once);
        $this->assertSame(0, $advances);
        $this->assertCount(1, $reports);
        $this->assertSame(0, $reports[0]['inspected']);
        $this->assertSame('deadline', $reports[0]['stop_reason']);
        $this->assertTrue($reports[0]['deadline_reached']);
        $this->assertGreaterThanOrEqual(1000, $reports[0]['durations_ms']['selection']);
        $this->assertSame('pending', DB::table('release_verification_candidates')->value('status'));
    }

    public function test_preparation_diagnostics_do_not_count_a_lost_cas_as_a_ready_promotion(): void
    {
        app(CandidateRepository::class)->enqueue($this->manifest('race'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $once = false;
        $reports = [];
        DB::listen(static function (QueryExecuted $query) use (&$once): void {
            if (! $once && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"manifest_path"') && str_contains($query->sql, 'order by')) {
                $once = true;
                DB::table('release_verification_candidates')->increment('version');
            }
        });
        $this->assertSame(0, app(CandidatePreparationService::class)->prepare(1, 30, null,
            static function (array $report) use (&$reports): void {
                $reports[] = $report;
            }));
        $this->assertCount(1, $reports);
        $this->assertSame(1, $reports[0]['assessments']['ready']);
        $this->assertSame(0, $reports[0]['updated']['ready']);
        $this->assertSame(0, $reports[0]['ready_promotions']);
        $this->assertSame(1, $reports[0]['zero_row_updates']);
        $this->assertSame('pending', DB::table('release_verification_candidates')->value('status'));
    }

    public function test_preparation_observer_failure_preserves_success_and_original_exception(): void
    {
        app(CandidateRepository::class)->enqueue($this->manifest('success'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $reports = [];
        $observer = static function (array $report) use (&$reports): void {
            $reports[] = $report;
            throw new RuntimeException('Observer failed');
        };
        $this->assertSame(1, app(CandidatePreparationService::class)->prepare(1, 30, null, $observer));
        $this->assertSame('ready', DB::table('release_verification_candidates')->value('status'));
        $original = new RuntimeException('Original selection failure');
        try {
            app(CandidatePreparationService::class)->prepare(1, 30, static function (bool $advance) use ($original): bool {
                throw $original;
            }, $observer);
            $this->fail('The original exception must escape.');
        } catch (RuntimeException $error) {
            $this->assertSame($original, $error);
        }
        $this->assertCount(2, $reports);
        $this->assertSame('error', $reports[1]['stop_reason']);
    }

    public function test_preparation_diagnostics_preserve_the_query_transcript_without_global_counts(): void
    {
        $this->freezeTime();
        for ($number = 0; $number < 115; $number++) {
            app(CandidateRepository::class)->enqueue($this->manifest('parity'.$number));
        }
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $queries = [];
        $capture = true;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$capture): void {
            if ($capture) {
                $queries[] = [$query->sql, $query->connection->prepareBindings($query->bindings)];
            }
        });
        $this->assertSame(115, app(CandidatePreparationService::class)->prepare(200, 30));
        $plain = $queries;
        $capture = false;
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $capture = true;
        $queries = [];
        $reports = [];
        $this->assertSame(115, app(CandidatePreparationService::class)->prepare(200, 30, null,
            static function (array $report) use (&$reports): void {
                $reports[] = $report;
            }));
        $capture = false;
        $this->assertCount(1, $reports);
        $this->assertSame($plain, $queries);
        foreach ($queries as [$sql]) {
            $this->assertStringNotContainsString('count(', strtolower($sql));
        }
    }

    public function test_preparation_command_logs_only_opted_in_runs_after_releasing_locks(): void
    {
        $reports = [];
        $lockReleased = false;
        Log::shouldReceive('info')->once()->andReturnUsing(static function (string $message, array $report) use (&$reports, &$lockReleased): void {
            $reports[] = $report;
            $exclusive = new VerificationWorkerLocks;
            try {
                $lockReleased = $exclusive->tryExclusive();
            } finally {
                $exclusive->close();
            }
            throw new RuntimeException('Logger failed');
        });
        $this->assertTrue(Artisan::all()['releases:prepare-candidates']->getDefinition()->hasOption('diagnostics'));
        $this->assertSame(0, Artisan::call('releases:prepare-candidates'));
        app(CandidateRepository::class)->enqueue($this->manifest('logged-success'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $this->assertSame(0, Artisan::call('releases:prepare-candidates', ['--diagnostics' => true]));
        $this->assertTrue($lockReleased);
        $this->assertCount(1, $reports);
        $this->assertSame(1, $reports[0]['ready_promotions']);
        $this->assertSame('ready', DB::table('release_verification_candidates')->value('status'));
        $this->assertSame('exhausted', $reports[0]['stop_reason']);
        $this->assertStringContainsString('Classified 1 candidate header inventories.', Artisan::output());
        $preparer = new VerificationWorkerLocks;
        try {
            $this->assertTrue($preparer->startPreparation());
            $this->assertSame(0, Artisan::call('releases:prepare-candidates', ['--diagnostics' => true]));
            $this->assertCount(1, $reports, 'A lock-skipped invocation is not a preparation run.');
        } finally {
            $preparer->close();
        }
    }

    public function test_preparation_command_reports_errors_without_masking_them_or_retaining_locks(): void
    {
        app(CandidateRepository::class)->enqueue($this->manifest('update-error'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $original = new RuntimeException('Original update listener error');
        $once = false;
        $reports = [];
        DB::listen(static function (QueryExecuted $query) use ($original, &$once): void {
            if (! $once && str_starts_with($query->sql, 'update "release_verification_candidates"') && ($query->bindings[0] ?? null) === 'ready') {
                $once = true;
                throw $original;
            }
        });
        $lockReleased = false;
        Log::shouldReceive('info')->once()->andReturnUsing(static function (string $message, array $report) use (&$reports, &$lockReleased): void {
            $reports[] = $report;
            $exclusive = new VerificationWorkerLocks;
            try {
                $lockReleased = $exclusive->tryExclusive();
            } finally {
                $exclusive->close();
            }
            throw new RuntimeException('Logger failed during error reporting');
        });
        try {
            Artisan::call('releases:prepare-candidates', ['--diagnostics' => true]);
            $this->fail('The original update exception must escape.');
        } catch (RuntimeException $error) {
            $this->assertSame($original, $error);
        }
        $this->assertTrue($lockReleased);
        $this->assertCount(1, $reports);
        $this->assertSame('error', $reports[0]['stop_reason']);
        $this->assertSame(1, $reports[0]['inspected']);
        $this->assertSame(1, $reports[0]['assessments']['ready']);
        $this->assertSame(0, $reports[0]['ready_promotions'], 'A throwing update did not provide an affected-row result.');
        $this->assertSame('ready', DB::table('release_verification_candidates')->value('status'), 'Error reporting must not imply rollback of this autocommitted update.');
    }

    public function test_preparation_diagnostics_report_simultaneous_limit_and_deadline_boundaries(): void
    {
        app(CandidateRepository::class)->enqueue($this->manifest('both-boundaries'));
        DB::table('release_verification_candidates')->update(['status' => 'pending']);
        $reports = [];
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'update "release_verification_candidates"') && ($query->bindings[0] ?? null) === 'ready') {
                usleep(1100000);
            }
        });
        $this->assertSame(1, app(CandidatePreparationService::class)->prepare(1, 1, null,
            static function (array $report) use (&$reports): void {
                $reports[] = $report;
            }));
        $this->assertCount(1, $reports);
        $this->assertSame('limit_and_deadline', $reports[0]['stop_reason']);
        $this->assertTrue($reports[0]['limit_reached']);
        $this->assertTrue($reports[0]['deadline_reached']);
        $this->assertSame(1, $reports[0]['ready_promotions']);
        $this->assertGreaterThanOrEqual(1000, $reports[0]['durations_ms']['update']);
    }

    private function seedPreparationQueues(): int
    {
        $this->freezeTime();
        app(CandidateRepository::class)->enqueue($this->manifest('seed'));
        $template = (array) DB::table('release_verification_candidates')->sole();
        unset($template['id']);
        DB::table('release_verification_candidates')->delete();
        $rows = [];
        foreach (['pending', 'unknown'] as $status) {
            for ($number = 0; $number < 500; $number++) {
                $rows[] = array_replace($template, ['identity' => $status.'-'.$number,
                    'source_identity' => sha1($status.':'.$number), 'status' => $status]);
            }
        }
        DB::table('release_verification_candidates')->insert($rows);
        DB::table('release_verification_state')->update(['pending_bytes' => $template['manifest_bytes'] * count($rows)]);

        return (int) DB::table('release_verification_state')->value('pending_bytes');
    }
}
