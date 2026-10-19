<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\DirtyCandidateSettler;
use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DirtyCandidateSettlerTest extends ReleaseVerificationCandidatesTest
{
    public function test_late_headers_of_a_dirty_candidate_are_archived_and_it_becomes_claimable(): void
    {
        $this->releasesTable();
        $this->upload('movie', 1);
        app(CandidateRepository::class)->archive((int) DB::table('collections')->value('id'));
        $late = $this->upload('movie', 2);
        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late]));
        $this->assertSame('dirty', DB::table('release_verification_candidates')->value('status'));

        $totals = app(DirtyCandidateSettler::class)->settle(200, 20);

        $this->assertSame(['inspected' => 1, 'settled' => 1, 'kept' => 0], $totals);
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertNotSame('dirty', $candidate->status);
        $this->assertSame([1, 2], array_column(app(ManifestStore::class)->read($candidate->manifest_path)['files'][0]['segments'], 'number'));
        $this->assertFalse(DB::table('collections')->where('id', $late)->exists(), 'Archived like any ready collection.');
    }

    public function test_the_walk_resumes_after_the_last_candidate_and_wraps(): void
    {
        $this->releasesTable();
        foreach (['a', 'b', 'c'] as $label) {
            $this->dirtyCandidate($label);
        }

        $first = app(DirtyCandidateSettler::class)->settle(2, 20);
        $this->assertSame(2, $first['settled']);
        $this->assertNotNull(Cache::get('release-verification:dirty-settle:cursor'));
        $second = app(DirtyCandidateSettler::class)->settle(2, 20);

        $this->assertSame(['inspected' => 1, 'settled' => 1, 'kept' => 0], $second);
        $this->assertSame(0, DB::table('release_verification_candidates')->where('status', 'dirty')->count());
        $this->assertNull(Cache::get('release-verification:dirty-settle:cursor'), 'Reaching the newest starts over from the oldest.');
    }

    public function test_a_dirty_candidate_without_ready_headers_is_passed_over_without_stalling_the_walk(): void
    {
        $this->releasesTable();
        $waiting = $this->dirtyCandidate('waiting');
        DB::table('collections')->where('id', $waiting)->update(['filecheck' => 1]);
        $ready = $this->dirtyCandidate('ready');

        $totals = app(DirtyCandidateSettler::class)->settle(200, 20);

        $this->assertSame(['inspected' => 2, 'settled' => 1, 'kept' => 0], $totals);
        $this->assertTrue(DB::table('collections')->where('id', $waiting)->exists(), 'Headers still being reconciled stay in place.');
        $this->assertFalse(DB::table('collections')->where('id', $ready)->exists());
    }

    public function test_an_over_budget_late_collection_is_kept_and_deferred(): void
    {
        (require database_path('migrations/2026_10_19_120000_create_release_verification_oversized_collections_table.php'))->up();
        $this->releasesTable();
        $late = $this->dirtyCandidate('big', messageId: str_repeat('m', 200).'@late');
        // Lowered once the candidate is dirty: its late header alone is now over the budget.
        config(['release_verification.metadata_bytes' => 300]);

        $totals = app(DirtyCandidateSettler::class)->settle(200, 20);

        $this->assertSame(['inspected' => 1, 'settled' => 0, 'kept' => 1], $totals);
        $this->assertTrue(DB::table('collections')->where('id', $late)->exists());
        $this->assertSame($late, (int) DB::table('release_verification_oversized_collections')->value('collection_id'));
        $this->assertSame('dirty', DB::table('release_verification_candidates')->value('status'), 'Never published on what it lacks.');
    }

    public function test_a_candidate_whose_archive_kills_the_process_does_not_block_the_walk(): void
    {
        $this->releasesTable();
        $this->dirtyCandidate('first');
        $this->dirtyCandidate('second');
        $cursors = [];
        // Records where the cursor stands each time archive() starts, then dies like a fatal error would.
        $this->app->instance(CandidateRepository::class, new class($cursors)
        {
            /** @param list<mixed> $cursors */
            public function __construct(public array &$cursors) {}

            public function archive(int $collectionId): void
            {
                $this->cursors[] = Cache::get('release-verification:dirty-settle:cursor');

                throw new \Error('Allowed memory size exhausted');
            }
        });

        app(DirtyCandidateSettler::class)->settle(200, 20);

        // A fatal error cannot be caught, so the cursor saved before each archive is what a killed process leaves.
        $this->assertSame(DB::table('release_verification_candidates')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            array_map(static fn (array $cursor): int => (int) $cursor['id'], $cursors), 'The cursor is past each candidate before its archive starts.');
        $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'dirty')->count(), 'Failed archives leave them dirty.');
    }

    public function test_the_time_budget_is_checked_before_every_archive(): void
    {
        $this->releasesTable();
        foreach (['a', 'b', 'c'] as $label) {
            $this->dirtyCandidate($label);
        }

        $this->assertSame(['inspected' => 1, 'settled' => 1, 'kept' => 0], app(DirtyCandidateSettler::class)->settle(200, 0),
            'One archive always runs; none starts past the deadline.');
        $this->assertSame(2, DB::table('release_verification_candidates')->where('status', 'dirty')->count());
    }

    public function test_nothing_is_archived_when_verification_is_not_required(): void
    {
        $this->releasesTable();
        $late = $this->dirtyCandidate('off');
        config(['release_verification.required' => false]);

        $this->assertSame(['inspected' => 0, 'settled' => 0, 'kept' => 0], app(DirtyCandidateSettler::class)->settle(200, 20));
        $this->assertTrue(DB::table('collections')->where('id', $late)->exists());
    }

    public function test_a_passed_over_candidate_is_revisited_though_new_ones_keep_arriving_ahead_of_the_cursor(): void
    {
        $this->releasesTable();
        $waiting = $this->dirtyCandidate('waiting');
        DB::table('collections')->where('id', $waiting)->update(['filecheck' => 1]);
        $this->dirtyCandidate('ready');
        app(DirtyCandidateSettler::class)->settle(1, 20);
        $this->assertSame('dirty', DB::table('release_verification_candidates')->where('source_identity', bin2hex(sha1('waiting', true)))->value('status'));
        DB::table('collections')->where('id', $waiting)->update(['filecheck' => 3]);

        // Every run another candidate becomes dirty ahead of the cursor; the sweep still ends and starts over.
        $settled = false;
        foreach (['n1', 'n2', 'n3', 'n4'] as $label) {
            $this->dirtyCandidate($label);
            DB::table('release_verification_candidates')->where('source_identity', bin2hex(sha1($label, true)))->update(['next_attempt_at' => now()->addDay()]);
            app(DirtyCandidateSettler::class)->settle(1, 20);
            if (DB::table('release_verification_candidates')->where('source_identity', bin2hex(sha1('waiting', true)))->value('status') !== 'dirty') {
                $settled = true;
                break;
            }
        }

        $this->assertTrue($settled, 'The candidate it passed over is reached again.');
    }

    public function test_the_preparer_settles_dirty_candidates(): void
    {
        $this->releasesTable();
        $this->dirtyCandidate('cmd');

        $this->artisan('releases:prepare-candidates', ['--dirty-limit' => 10])->expectsOutputToContain('Settled 1 of 1 dirty candidates')->assertSuccessful();

        $this->assertSame(0, DB::table('release_verification_candidates')->where('status', 'dirty')->count());
    }

    /** A pending candidate whose late article has arrived; returns the late collection. */
    private function dirtyCandidate(string $label, string $messageId = ''): int
    {
        $first = $this->upload($label, 1);
        app(CandidateRepository::class)->archive($first);
        $late = $this->upload($label, 2, $messageId);
        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late]));
        $this->assertSame('dirty', DB::table('release_verification_candidates')->where('source_identity', bin2hex(sha1($label, true)))->value('status'));

        return $late;
    }

    private function upload(string $label, int $part, string $messageId = ''): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1($label, true), 'subject' => $label.'.mkv', 'fromname' => 'poster',
            'totalfiles' => 1, 'groups_id' => 1, 'date' => now()->format('Y-m-d H:i:s')]);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5($label, true), 'name' => $label.'.mkv', 'totalparts' => 3]);
        DB::table('parts')->insert(['binaries_id' => $binary, 'partnumber' => $part, 'messageid' => $messageId !== '' ? $messageId : $label.$part.'@fixture', 'size' => 5]);

        return $id;
    }

    /** The parent suite creates it in some tests, so it cannot be made in setUp. */
    private function releasesTable(): void
    {
        if (! Schema::hasTable('releases')) {
            Schema::create('releases', static function (Blueprint $table): void {
                $table->id();
                $table->integer('nzbstatus')->default(0);
            });
        }
    }
}
