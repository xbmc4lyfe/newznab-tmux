<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\CandidateMetadataBudgetExceeded;
use App\Services\CollectionCleanupService;
use App\Services\ReleaseCreationService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\VerificationResetService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OversizedCollectionDeferralTest extends ReleaseVerificationCandidatesTest
{
    private const int BUDGET = 65536;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_10_19_120000_create_release_verification_oversized_collections_table.php'))->up();
        Schema::table('collections', static function (Blueprint $table): void {
            $table->dateTime('dateadded')->nullable();
            $table->unsignedBigInteger('releases_id')->nullable();
        });
        config(['release_verification.metadata_bytes' => self::BUDGET]);
        Carbon::setTestNow('2026-10-19 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_over_budget_upload_keeps_its_headers_and_is_not_read_again_until_its_backoff_ends(): void
    {
        $oversized = $this->upload('oversized', 600);
        $small = $this->upload('small', 1);

        $this->createReleases();

        $this->assertTrue(DB::table('collections')->where('id', $oversized)->exists(), 'Its headers are retained.');
        $this->assertSame(600, $this->partsOf($oversized));
        $this->assertFalse(DB::table('collections')->where('id', $small)->exists(), 'Later collections are still archived.');
        $deferral = DB::table('release_verification_oversized_collections')->sole();
        $this->assertSame([$oversized, self::BUDGET, 1, '2026-10-19 18:00:00'],
            [(int) $deferral->collection_id, (int) $deferral->budget_bytes, (int) $deferral->attempts, $deferral->retry_after]);

        $this->assertSame(0, $this->partReadsDuring(fn () => $this->createReleases()), 'A deferred upload is not snapshotted again.');

        Carbon::setTestNow('2026-10-19 18:00:01');
        $this->createReleases();
        $deferral = DB::table('release_verification_oversized_collections')->sole();
        $this->assertSame([2, '2026-10-20 06:00:01'], [(int) $deferral->attempts, $deferral->retry_after], 'The backoff doubles.');
        $this->assertSame(0, DB::table('release_verification_candidates')->where('source_identity', bin2hex(sha1('oversized', true)))->count());
    }

    public function test_raising_the_budget_archives_a_deferred_upload_at_once_and_forgets_it(): void
    {
        $oversized = $this->upload('oversized', 600);
        $this->createReleases();
        $this->assertSame(1, DB::table('release_verification_oversized_collections')->count());

        config(['release_verification.metadata_bytes' => self::BUDGET * 4]);
        $this->createReleases();

        $this->assertFalse(DB::table('collections')->where('id', $oversized)->exists());
        $this->assertSame(0, DB::table('release_verification_oversized_collections')->count());
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame(600, count(app(ManifestStore::class)->read($candidate->manifest_path)['files'][0]['segments']), 'Every article is archived.');
    }

    public function test_raising_the_budget_after_expiry_hours_admits_the_deferred_upload_as_ready(): void
    {
        $oversized = $this->upload('oversized', 600);
        $this->createReleases();
        $this->assertSame(1, DB::table('release_verification_oversized_collections')->count());

        Carbon::setTestNow('2026-10-22 12:00:01');
        config(['release_verification.metadata_bytes' => self::BUDGET * 4]);
        $this->createReleases();

        $this->assertFalse(DB::table('collections')->where('id', $oversized)->exists());
        $this->assertSame(0, DB::table('release_verification_oversized_collections')->count());
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame('ready', $candidate->status);
        $this->assertSame(600, count(app(ManifestStore::class)->read($candidate->manifest_path)['files'][0]['segments']));
    }

    public function test_the_stale_sweep_archives_a_deferred_upload_once_the_budget_is_raised(): void
    {
        $oversized = $this->upload('oversized', 600);
        $this->createReleases();
        DB::table('collections')->where('id', $oversized)->update(['dateadded' => '2026-10-01 00:00:00']);
        config(['release_verification.metadata_bytes' => self::BUDGET * 4]);

        $this->sweep();

        $this->assertFalse(DB::table('collections')->where('id', $oversized)->exists());
        $this->assertSame(0, DB::table('release_verification_oversized_collections')->count());
        $candidate = DB::table('release_verification_candidates')->sole();
        $this->assertSame('ready', $candidate->status);
    }

    public function test_a_deferral_left_by_a_replaced_collection_does_not_hold_back_another_upload(): void
    {
        $id = $this->upload('fresh', 1);
        DB::table('release_verification_oversized_collections')->insert(['collection_id' => $id, 'collectionhash' => sha1('earlier upload', true),
            'budget_bytes' => self::BUDGET, 'attempts' => 3, 'failed_at' => '2026-10-19 11:00:00', 'retry_after' => '2026-10-21 00:00:00']);

        $this->createReleases();

        $this->assertFalse(DB::table('collections')->where('id', $id)->exists());
        $this->assertSame(0, DB::table('release_verification_oversized_collections')->count());
    }

    public function test_the_log_names_when_an_over_budget_upload_is_retried(): void
    {
        $oversized = $this->upload('oversized', 600);
        $log = Log::spy();

        $this->createReleases();

        $log->shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => $context === [
            'collection_id' => $oversized, 'error_type' => CandidateMetadataBudgetExceeded::class,
            'retry_after' => '2026-10-19T18:00:00+00:00']);
    }

    public function test_an_upload_within_the_configured_budget_is_archived(): void
    {
        // Past the 16 MiB budget production used before, within the default.
        config(['release_verification.metadata_bytes' => 50331648]);
        $id = $this->upload('large', 17000, 1000);

        $this->createReleases();

        $this->assertFalse(DB::table('collections')->where('id', $id)->exists());
        $this->assertSame(1, DB::table('release_verification_candidates')->count());
    }

    public function test_a_verification_reset_clears_deferrals(): void
    {
        $this->upload('oversized', 600);
        $this->createReleases();

        app(VerificationResetService::class)->run(static function (): void {});

        $this->assertSame(0, DB::table('release_verification_oversized_collections')->count());
    }

    public function test_the_stale_sweep_keeps_a_deferred_upload_without_reading_it_again(): void
    {
        $oversized = $this->upload('oversized', 600);
        $this->createReleases();
        DB::table('collections')->where('id', $oversized)->update(['dateadded' => '2026-10-01 00:00:00']);
        // Still within the candidate expiry, so archival would admit it rather than record it expired.
        config(['release_verification.expiry_hours' => 24 * 365]);

        $reads = $this->partReadsDuring(fn () => $this->sweep());

        $this->assertSame(0, $reads, 'The sweep honours the deferral.');
        $this->assertSame(600, $this->partsOf($oversized), 'Its headers are retained.');
        $this->assertSame(1, (int) DB::table('release_verification_oversized_collections')->value('attempts'));
    }

    public function test_the_stale_sweep_records_an_over_budget_upload_and_keeps_its_headers(): void
    {
        $oversized = $this->upload('oversized', 600);
        DB::table('collections')->where('id', $oversized)->update(['dateadded' => '2026-10-01 00:00:00']);
        // Still within the candidate expiry, so archival would admit it rather than record it expired.
        config(['release_verification.expiry_hours' => 24 * 365]);

        $this->sweep();

        $this->assertSame(600, $this->partsOf($oversized));
        $this->assertSame($oversized, (int) DB::table('release_verification_oversized_collections')->value('collection_id'));
    }

    public function test_the_stale_sweep_deletes_an_upload_past_the_candidate_expiry_without_reading_it(): void
    {
        $oversized = $this->upload('oversized', 600);
        $this->createReleases();
        // Archival could only record it as an expired candidate holding no headers.
        DB::table('collections')->where('id', $oversized)->update(['dateadded' => '2026-10-01 00:00:00']);
        $this->assertSame($oversized, (int) DB::table('release_verification_oversized_collections')->value('collection_id'));

        $reads = $this->partReadsDuring(fn () => $this->sweep());

        $this->assertSame(0, $reads);
        $this->assertFalse(DB::table('collections')->where('id', $oversized)->exists());
        $this->assertSame(0, $this->partsOf($oversized));
        $this->assertSame(0, DB::table('release_verification_candidates')->count());
        $this->assertSame(0, DB::table('release_verification_oversized_collections')->count(), 'Its deferral goes with it.');
    }

    public function test_a_deferral_survives_when_a_new_article_holds_back_the_expired_deletion(): void
    {
        $oversized = $this->upload('oversized', 600);
        $this->createReleases();
        DB::table('collections')->where('id', $oversized)->update(['dateadded' => '2026-10-01 00:00:00']);
        $injected = false;
        DB::listen(static function (QueryExecuted $event) use (&$injected, $oversized): void {
            if (! $injected && str_contains($event->sql, 'from "release_verification_candidates"')
                && str_contains($event->sql, '"source_identity" in')) {
                $injected = true;
                DB::table('collections')->where('id', $oversized)->update(['dateadded' => now()]);
            }
        });

        $this->sweep();

        $this->assertTrue($injected);
        $this->assertTrue(DB::table('collections')->where('id', $oversized)->exists());
        $this->assertSame($oversized, (int) DB::table('release_verification_oversized_collections')->value('collection_id'),
            'A collection the locked recheck keeps keeps its deferral.');
    }

    public function test_late_headers_that_would_push_a_pending_candidate_over_budget_are_kept_apart_for_a_later_pass(): void
    {
        // Each fits the budget on its own (45,600 bytes); merged they do not.
        $this->upload('first', 200, hash: 'upload', totalParts: 400);
        $this->createReleases();
        $candidate = DB::table('release_verification_candidates')->sole();
        $late = $this->upload('late', 200, hash: 'upload', first: 201, totalParts: 400);

        DB::transaction(fn () => app(CandidateRepository::class)->invalidateHotCollections([$late]));
        $fenced = DB::table('release_verification_candidates')->sole();
        $this->assertSame([$candidate->status, null, (int) $candidate->version + 1], [$fenced->status, $fenced->lease_token, (int) $fenced->version],
            'Still claimable, and a verification already under way cannot publish.');
        $this->createReleases();

        $this->assertSame(200, $this->partsOf($late), 'The late headers are retained.');
        $this->assertSame($fenced->manifest_path, DB::table('release_verification_candidates')->sole()->manifest_path, 'The candidate keeps what it had.');
        $this->assertSame(200, count(app(ManifestStore::class)->read($fenced->manifest_path)['files'][0]['segments']));
        $this->assertSame($late, (int) DB::table('release_verification_oversized_collections')->value('collection_id'));
        $this->assertSame((int) $fenced->manifest_bytes, (int) DB::table('release_verification_state')->value('pending_bytes'), 'Nothing more is charged.');
    }

    public function test_a_record_survives_when_archive_returns_without_archiving(): void
    {
        $oversized = $this->upload('oversized', 600);
        $this->createReleases();
        Carbon::setTestNow('2026-10-19 18:00:01');
        // Another archiver holds the collection: archive() returns without touching it.
        $this->app->instance(CandidateRepository::class, new class
        {
            public function archive(int $collectionId): void {}
        });

        $this->createReleases();

        $this->assertSame([$oversized, 1], [(int) DB::table('release_verification_oversized_collections')->value('collection_id'),
            (int) DB::table('release_verification_oversized_collections')->value('attempts')]);
    }

    public function test_bookkeeping_failures_never_stop_archival(): void
    {
        $id = $this->upload('small', 1);
        Cache::partialMock()->shouldReceive('add')->andThrow(new \RuntimeException('cache down'));

        $this->createReleases();

        $this->assertFalse(DB::table('collections')->where('id', $id)->exists());
    }

    private function createReleases(): void
    {
        $this->releasesTable();
        app(ReleaseCreationService::class)->createReleases(null, 100, false);
    }

    private function sweep(): void
    {
        $this->releasesTable();
        app(CollectionCleanupService::class)->deleteFinishedAndOrphans(false);
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

    private function partReadsDuring(callable $work): int
    {
        $reads = 0;
        DB::listen(static function (QueryExecuted $query) use (&$reads): void {
            if (str_starts_with($query->sql, 'select') && preg_match('/from [`"]parts[`"]/', $query->sql) === 1) {
                $reads++;
            }
        });
        $work();

        return $reads;
    }

    private function partsOf(int $collectionId): int
    {
        return DB::table('parts')->join('binaries', 'binaries.id', '=', 'parts.binaries_id')->where('binaries.collections_id', $collectionId)->count();
    }

    private function upload(string $label, int $parts, int $messageIdBytes = 100, ?string $hash = null, int $first = 1, ?int $totalParts = null): int
    {
        $id = DB::table('collections')->insertGetId(['collectionhash' => sha1($hash ?? $label, true), 'subject' => 'movie.mkv', 'fromname' => 'poster',
            'totalfiles' => 1, 'groups_id' => 1, 'date' => '2026-10-19 11:00:00', 'dateadded' => '2026-10-19 11:00:00']);
        $binary = DB::table('binaries')->insertGetId(['collections_id' => $id, 'binaryhash' => md5($hash ?? $label, true), 'name' => 'movie.mkv',
            'totalparts' => $totalParts ?? $parts]);
        foreach (array_chunk(range($first, $first + $parts - 1), 500) as $numbers) {
            DB::table('parts')->insert(array_map(static fn (int $number): array => ['binaries_id' => $binary, 'partnumber' => $number,
                'messageid' => str_pad($label.$number, $messageIdBytes - 6, 'x').'@fixed', 'size' => 5], $numbers));
        }

        return $id;
    }
}
