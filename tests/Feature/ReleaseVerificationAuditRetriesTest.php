<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseManagementService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\VerificationResetService;
use App\Services\YencService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\YencArticles;
use Tests\TestCase;

class ReleaseVerificationAuditRetriesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/audit-retries-'.bin2hex(random_bytes(8));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'release_verification.storage_path' => $this->directory, 'release_verification.retry_seconds' => 900,
            'release_verification.scratch_bytes' => 2147483648, 'release_verification.free_bytes' => 0,
            'release_verification.bytes_per_second' => 0]);
        DB::purge();
        (require database_path('migrations/2026_10_11_154028_create_release_verification_candidates_table.php'))->up();
        (require database_path('migrations/2026_10_17_220000_add_header_check_pending_to_release_verification_candidates.php'))->up();
        (require database_path('migrations/2026_10_11_175742_create_release_verification_audit_retries_table.php'))->up();
        (require database_path('migrations/2026_10_12_004819_create_release_verification_scratch_table.php'))->up();
        (require database_path('migrations/2026_10_12_053000_add_shared_verification_scratch_budget.php'))->up();
        (require database_path('migrations/2026_10_17_200000_add_host_to_release_verification_scratch.php'))->up();
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->string('searchname');
            $table->string('fromname');
            $table->integer('groups_id');
            $table->string('postdate');
        });
        $this->mock(ReleaseManagementService::class, function ($mock): void {
            $mock->shouldNotReceive('deleteSingle');
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_missing_nzb_is_retried_by_later_resume_and_success_clears_retry(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->with('audit-1')->andReturn(false, $this->xml());
        });
        $this->availableNntp();
        $this->audit();
        $this->travel(16)->minutes();
        $this->audit();

        $this->assertSame(['unknown', 'sampled'], array_column($this->records(), 'verdict'));
        $this->assertSame([1, 1], array_column($this->records(), 'id'));
        $this->assertSame(1, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $this->assertSame(0, DB::table('release_verification_audit_retries')->count());
        $this->assertSame(1, DB::table('releases')->count());
    }

    public function test_nntp_outage_is_retried_after_backoff_instead_of_skipped_forever(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->with('audit-1')->andReturn($this->xml());
        });
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturn(false, true);
            $mock->shouldReceive('verificationArticle')->with('a@b', 1048576)->andReturn((new YencService)->encode('hello', 'movie.mkv'));
            $mock->shouldReceive('doQuit')->andReturn(null);
        });
        $this->audit();
        $this->audit();
        $this->assertCount(1, $this->records(), 'Retry backoff must prevent immediate repeat attempts.');
        $this->travel(16)->minutes();
        $this->audit();

        $this->assertSame(['unknown', 'sampled'], array_column($this->records(), 'verdict'));
        $this->assertSame(0, DB::table('release_verification_audit_retries')->count());
    }

    public function test_audit_samples_a_partial_publication_instead_of_retrying_it_forever(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            foreach (['articles_found', 'articles_expected', 'files_found', 'files_expected'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
        });
        $this->release(1);
        $this->release(2);
        $this->release(3);
        $this->release(4);
        $counts = ['articles_expected' => 3, 'files_found' => 1, 'files_expected' => 1];
        DB::table('releases')->where('id', 1)->update($counts + ['articles_found' => 1]);
        // Release 3 was published with two articles; its NZB has since lost one.
        DB::table('releases')->where('id', 3)->update($counts + ['articles_found' => 2]);
        // Release 4's upgrade installed a fuller NZB but never committed its counts (an interrupted worker).
        DB::table('releases')->where('id', 4)->update(['articles_found' => 0, 'articles_expected' => 3, 'files_found' => 0, 'files_expected' => 1]);
        // Every NZB declares three articles and contains one; releases 1 and 3 were published as partial.
        $xml = '<nzb><file subject="Release [1/1] &quot;movie.mkv&quot; (1/3)"><groups><group>alt.test</group></groups>'
            .'<segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
        $this->mock(NzbService::class, function ($mock) use ($xml): void {
            $mock->shouldReceive('readNzbContents')->andReturn($xml);
        });
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->with('a@b', 1048576)->andReturn(YencArticles::part('11111', 'movie.mkv', 15, 1, 1, 3));
            $mock->shouldReceive('doQuit')->andReturn(null);
        });

        $this->audit(4);

        $this->assertSame([1 => 'sampled', 2 => 'unknown', 3 => 'unrepairable', 4 => 'sampled'], array_column($this->records(), 'verdict', 'id'));
    }

    public function test_a_partial_awaiting_late_articles_is_retried_without_reading_its_nzb(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->unsignedBigInteger('late_article_candidate_id')->nullable();
        });
        $this->release(1);
        DB::table('releases')->where('id', 1)->update(['late_article_candidate_id' => 9]);
        $this->mock(NzbService::class)->shouldNotReceive('readNzbContents');

        $this->audit();

        $this->assertSame([['id' => 1, 'verdict' => 'unknown']], array_map(fn (array $record): array => ['id' => $record['id'], 'verdict' => $record['verdict']], $this->records()));
        $this->assertTrue(DB::table('release_verification_audit_retries')->where('release_id', 1)->exists(), 'It is audited once settled.');
    }

    public function test_sample_budget_exhaustion_can_recover_on_a_later_resume(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->with('audit-1')->andReturn($this->xml());
        });
        $this->availableNntp();
        config(['release_verification.spot_check_bytes' => 1]);
        $this->audit();
        config(['release_verification.spot_check_bytes' => 1048576]);
        $this->travel(16)->minutes();
        $this->audit();

        $this->assertSame(['unknown', 'sampled'], array_column($this->records(), 'verdict'));
    }

    public function test_one_release_limit_alternates_due_retries_and_forward_progress(): void
    {
        $this->release(1);
        $this->release(2);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->andReturn(false);
        });
        $this->audit();
        $this->travel(16)->minutes();
        $this->audit();
        $this->travel(16)->minutes();
        $this->audit();

        $this->travel(16)->minutes();
        $this->audit();
        $this->assertSame([1, 1, 2, 1], array_column($this->records(), 'id'));
        $this->assertSame(2, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $this->assertSame(2, DB::table('release_verification_audit_retries')->count());
        $this->assertSame(2, DB::table('releases')->count());
    }

    public function test_deleted_retry_release_is_cleaned_without_blocking_unseen_releases(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->andReturn(false);
        });
        $this->audit();
        $this->assertTrue(DB::table('release_verification_audit_retries')->where('release_id', 1)->exists());
        DB::table('releases')->where('id', 1)->delete();
        $this->release(2);
        $this->travel(16)->minutes();
        $this->audit();

        $this->assertSame([1, 2], array_column($this->records(), 'id'));
        $this->assertFalse(DB::table('release_verification_audit_retries')->where('release_id', 1)->exists());
        $this->assertSame(2, (int) DB::table('release_verification_state')->value('audit_cursor'));
    }

    public function test_mixed_retry_and_fresh_work_never_exceeds_requested_batch_limit(): void
    {
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->release($id);
        }
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->andReturn(false);
        });
        $this->audit(3);
        $this->travel(16)->minutes();
        $this->audit(3);

        $this->assertSame([1, 2, 3, 1, 4, 2], array_column($this->records(), 'id'));
        $this->assertSame(4, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $this->assertSame(5, DB::table('releases')->count());
    }

    public function test_reclaimable_verifying_candidate_defers_audit_but_active_lease_does_not(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->once()->andReturn(false);
        });
        $id = DB::table('release_verification_candidates')->insertGetId([
            'identity' => sha1('crashed-worker'), 'groups_id' => 1, 'manifest_path' => '',
            'manifest_hash' => str_repeat('a', 64), 'manifest_bytes' => 0, 'status' => 'verifying',
            'last_unique_at' => now(), 'next_attempt_at' => now(), 'lease_until' => now()->subSecond(),
        ]);
        $this->audit();
        $this->assertFileDoesNotExist($this->directory.'/audit.jsonl');
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('audit_cursor'));
        DB::table('release_verification_candidates')->where('id', $id)->update(['lease_until' => now()->addMinute()]);
        $this->audit();
        $this->assertSame([1], array_column($this->records(), 'id'));
    }

    public function test_terminal_audit_scratch_survives_failed_cleanup_and_is_recovered_by_candidate_worker(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->once()->andReturn($this->xml());
        });
        $this->availableNntp();
        File::partialMock()->shouldReceive('deleteDirectory')->andReturn(false);
        $this->audit();
        $scratch = glob($this->directory.'/scratch/audit-*')[0];
        file_put_contents($scratch.'/legacy-checkpoint', 'retained prior audit state');
        $this->assertSame(1, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $this->assertSame(1, DB::table('release_verification_scratch')->count(), 'Committed terminal audit must retain durable cleanup work.');
        $filesystem = new Filesystem;
        $attempts = 0;
        File::shouldReceive('delete')->andReturnUsing(static function ($path) use ($filesystem, &$attempts): bool {
            return ++$attempts === 1 ? false : $filesystem->delete($path);
        });
        $this->assertSame(0, Artisan::call('releases:verify-candidates'));
        $this->assertDirectoryExists($scratch);
        $this->assertSame(1, DB::table('release_verification_scratch')->count());
        $this->assertSame(0, Artisan::call('releases:verify-candidates'));
        $this->assertDirectoryDoesNotExist($scratch);
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
    }

    public function test_audit_yields_after_current_release_when_a_new_candidate_arrives(): void
    {
        $this->release(1);
        $this->release(2);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->with('audit-1')->once()->andReturn($this->xml());
        });
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->once()->andReturnUsing(static function (): string {
                app(CandidateRepository::class)->enqueue(['identity' => sha1('mid-audit-arrival'), 'groups_id' => 1, 'files' => []]);

                return (new YencService)->encode('hello', 'movie.mkv');
            });
            $mock->shouldReceive('doQuit')->andReturn(null);
        });
        $this->audit(100);

        $this->assertSame([1], array_column($this->records(), 'id'));
        $this->assertSame(1, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $this->assertSame('unknown', DB::table('release_verification_candidates')->value('status'));
        $lock = fopen($this->directory.'/worker.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function test_incomplete_audit_defers_without_downloading_and_retains_registered_inventory(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->twice()->andReturn(str_replace('[1/1]', '[1/2]', $this->xml()));
        });
        $this->mock(NNTPService::class, static function ($mock): void {
            $mock->shouldNotReceive('doConnect');
        });
        $this->audit();
        $cache = glob($this->directory.'/scratch/audit-*/articles/*');
        $this->assertCount(0, $cache);
        $this->assertSame(0, Artisan::call('releases:verify-candidates'));
        $this->assertCount(0, glob($this->directory.'/scratch/audit-*/articles/*'));
        $this->travel(16)->minutes();
        $this->audit();
        $this->assertCount(0, glob($this->directory.'/scratch/audit-*/articles/*'));
        $this->assertSame(1, DB::table('release_verification_scratch')->count());
        $this->assertSame(['unknown', 'unknown'], array_column($this->records(), 'verdict'));
    }

    public function test_terminal_audit_marker_rolls_back_with_cursor_and_preserves_live_cache(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->once()->andReturn($this->xml());
        });
        $this->availableNntp();
        DB::unprepared("CREATE TRIGGER fail_audit_cursor BEFORE UPDATE ON release_verification_state WHEN NEW.audit_cursor > 0 BEGIN SELECT RAISE(ABORT, 'cursor commit failed'); END");
        try {
            Artisan::call('releases:verify-repairable', ['--resume' => true, '--dry-run' => true]);
            $this->fail('Audit cursor commit must fail.');
        } catch (\Throwable $error) {
            $this->assertInstanceOf(QueryException::class, $error);
            $this->assertStringContainsString('cursor commit failed', $error->getMessage());
        }
        $scratch = glob($this->directory.'/scratch/audit-*')[0];
        $this->assertSame(0, (int) DB::table('release_verification_state')->value('audit_cursor'));
        $this->assertFalse((bool) DB::table('release_verification_scratch')->value('terminal'));
        $this->assertSame(0, Artisan::call('releases:verify-candidates'));
        $this->assertDirectoryExists($scratch);
        $this->assertSame(1, DB::table('release_verification_scratch')->count());
    }

    public function test_cleanup_of_terminal_generation_preserves_newer_unknown_audit(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->twice()->andReturn($this->xml(), str_replace('[1/1]', '[1/2]', $this->xml()));
        });
        $this->availableNntp();
        $this->audit();
        $old = glob($this->directory.'/scratch/audit-*')[0];
        $this->assertSame(0, Artisan::call('releases:verify-repairable', ['--dry-run' => true, '--limit' => 1]));
        $paths = glob($this->directory.'/scratch/audit-*') ?: [];
        $this->assertCount(2, $paths);
        $new = array_values(array_diff($paths, [$old]))[0];
        $this->assertSame(0, Artisan::call('releases:verify-candidates'));
        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($new);
        $this->assertFalse((bool) DB::table('release_verification_scratch')->sole()->terminal);
        app(VerificationResetService::class)->run(static function (): void {});
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
        $this->assertDirectoryDoesNotExist($new);
    }

    public function test_verification_reset_releases_partials_fenced_by_deleted_candidates(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->unsignedBigInteger('late_article_candidate_id')->nullable();
        });
        $this->release(1);
        DB::table('releases')->where('id', 1)->update(['late_article_candidate_id' => 7]);

        app(VerificationResetService::class)->run(static function (): void {});

        $this->assertNull(DB::table('releases')->where('id', 1)->value('late_article_candidate_id'), 'Its candidate is gone; workers may process it.');
    }

    public function test_terminal_audit_symlink_target_and_index_are_preserved(): void
    {
        $this->release(1);
        $this->mock(NzbService::class, function ($mock): void {
            $mock->shouldReceive('readNzbContents')->once()->andReturn($this->xml());
        });
        $this->availableNntp();
        $this->audit();
        $scratch = glob($this->directory.'/scratch/audit-*')[0];
        (new Filesystem)->deleteDirectory($scratch);
        mkdir($this->directory.'/outside');
        file_put_contents($this->directory.'/outside/sentinel', 'preserved');
        symlink($this->directory.'/outside', $scratch);

        $this->assertSame(0, Artisan::call('releases:verify-candidates'));
        $this->assertFileExists($this->directory.'/outside/sentinel');
        $this->assertTrue(is_link($scratch));
        $this->assertSame(1, DB::table('release_verification_scratch')->count());
    }

    public function test_audit_rejects_linked_scratch_parent_before_registration_or_fetch(): void
    {
        $this->release(1);
        mkdir($this->directory.'/outside', 0700, true);
        file_put_contents($this->directory.'/outside/sentinel', 'preserved');
        symlink($this->directory.'/outside', $this->directory.'/scratch');
        $this->mock(NzbService::class, static function ($mock): void {
            $mock->shouldNotReceive('readNzbContents');
        });
        try {
            Artisan::call('releases:verify-repairable', ['--dry-run' => true]);
            $this->fail('Linked scratch parent must prevent audit work.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('scratch root is inaccessible or linked', $error->getMessage());
        }
        $this->assertFileExists($this->directory.'/outside/sentinel');
        $this->assertSame(0, DB::table('release_verification_scratch')->count());
    }

    private function audit(int $limit = 1): void
    {
        $this->assertSame(0, Artisan::call('releases:verify-repairable', ['--resume' => true, '--dry-run' => true, '--limit' => $limit]));
    }

    private function availableNntp(): void
    {
        $this->mock(NNTPService::class, function ($mock): void {
            $mock->shouldReceive('doConnect')->with(false)->andReturn(true);
            $mock->shouldReceive('verificationArticle')->with('a@b', 1048576)->andReturn((new YencService)->encode('hello', 'movie.mkv'));
            $mock->shouldReceive('doQuit')->andReturn(null);
        });
    }

    private function release(int $id): void
    {
        DB::table('releases')->insert(['id' => $id, 'guid' => 'audit-'.$id, 'searchname' => 'Movie',
            'fromname' => 'poster', 'groups_id' => 1, 'postdate' => '2026-10-11 12:00:00']);
    }

    private function xml(): string
    {
        return '<nzb><file subject="Release [1/1] &quot;movie.mkv&quot; (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">a@b</segment></segments></file></nzb>';
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->directory.'/audit.jsonl', FILE_IGNORE_NEW_LINES));
    }
}
