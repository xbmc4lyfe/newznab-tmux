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

        $this->assertSame(['inspected' => 1, 'archived' => 1, 'kept' => 0], $totals);
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
        $this->assertSame(2, $first['archived']);
        $this->assertNotNull(Cache::get('release-verification:dirty-settle:cursor'));
        $second = app(DirtyCandidateSettler::class)->settle(2, 20);

        $this->assertSame(['inspected' => 1, 'archived' => 1, 'kept' => 0], $second);
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

        $this->assertSame(['inspected' => 2, 'archived' => 1, 'kept' => 0], $totals);
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

        $this->assertSame(['inspected' => 1, 'archived' => 0, 'kept' => 1], $totals);
        $this->assertTrue(DB::table('collections')->where('id', $late)->exists());
        $this->assertSame($late, (int) DB::table('release_verification_oversized_collections')->value('collection_id'));
        $this->assertSame('dirty', DB::table('release_verification_candidates')->value('status'), 'Never published on what it lacks.');
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
