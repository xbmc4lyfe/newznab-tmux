<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\Contracts\BulkReleaseIndexUpdater;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\SearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\MockObject\Stub;
use RuntimeException;
use Tests\TestCase;

final class SearchServiceDeferredReleaseUpdatesTest extends TestCase
{
    /** @var list<list<int>> */
    private array $bulkCalls = [];

    /** @var list<int> */
    private array $singleCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('search_index_failures');
        Schema::create('search_index_failures', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id')->unique();
            $table->string('operation', 32)->default('upsert');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('search_index_failures');

        parent::tearDown();
    }

    public function test_updates_immediately_outside_a_deferral_scope(): void
    {
        $search = $this->search();

        $search->updateRelease(5);

        $this->assertSame([5], $this->singleCalls);
        $this->assertSame([], $this->bulkCalls);
        $this->assertSame(0, DB::table('search_index_failures')->count());
    }

    public function test_refreshes_each_deferred_release_once_in_bulk_when_the_scope_ends(): void
    {
        $search = $this->search();

        $result = $search->deferReleaseUpdates(function () use ($search): string {
            $search->updateRelease(5);
            $search->updateRelease('7');
            $search->updateRelease(5);
            $search->updateRelease(0);
            $this->assertSame([], $this->bulkCalls, 'Nothing is indexed until the scope ends.');

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame([[5, 7]], $this->bulkCalls);
        $this->assertSame([0], $this->singleCalls, 'An invalid id still goes straight to the driver.');
    }

    public function test_records_a_repairable_marker_for_each_deferred_release_and_clears_it_after_the_flush(): void
    {
        $search = $this->search();
        $this->travelTo(now()->startOfSecond());

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $search->updateRelease(7);

            $markers = DB::table('search_index_failures')->orderBy('release_id')->get();
            $this->assertSame([5, 7], $markers->pluck('release_id')->map(static fn ($id): int => (int) $id)->all());
            $this->assertSame(['deferred'], $markers->pluck('operation')->unique()->values()->all());
            $this->assertCount(1, $markers->pluck('last_error')->unique(), 'One token per scope.');
            $this->assertStringStartsWith('deferred:', (string) $markers->first()->last_error);
            $this->assertSame(now()->addSeconds(600)->toDateTimeString(), (string) $markers->first()->next_attempt_at);
            $this->assertNull($markers->first()->resolved_at);
        });

        $this->assertSame(0, DB::table('search_index_failures')->count());
    }

    public function test_hands_a_release_whose_refresh_failed_to_repair(): void
    {
        $search = $this->search(function (array $releaseIds): void {
            // What ManticoreSearchDriver::recordReleaseIndexFailure() does on a leased release.
            DB::table('search_index_failures')->where('release_id', 7)->increment('attempts');
        });

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $search->updateRelease(7);
        });

        $row = DB::table('search_index_failures')->sole();
        $this->assertSame(7, (int) $row->release_id);
        $this->assertSame('deferred', $row->operation);
        $this->assertLessThanOrEqual(now()->toDateTimeString(), (string) $row->next_attempt_at, 'Repair picks it up on its next run.');
    }

    public function test_takes_over_an_existing_failure_row(): void
    {
        DB::table('search_index_failures')->insert([
            ['release_id' => 5, 'operation' => 'upsert', 'attempts' => 3, 'last_error' => 'old', 'next_attempt_at' => now()->addHour(), 'resolved_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['release_id' => 7, 'operation' => 'upsert', 'attempts' => 1, 'last_error' => 'old', 'next_attempt_at' => null, 'resolved_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $search = $this->search(function (): void {
            throw new RuntimeException('index unavailable');
        });

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $search->updateRelease(7);
        });

        $rows = DB::table('search_index_failures')->orderBy('release_id')->get()->keyBy('release_id');
        $this->assertSame('deferred', $rows[5]->operation);
        $this->assertSame(0, (int) $rows[5]->attempts, 'A marker starts at zero so a failed refresh shows up.');
        $this->assertSame('deferred', $rows[7]->operation);
        $this->assertNull($rows[7]->resolved_at, 'A resolved row is reopened so repair picks it up.');
    }

    public function test_updates_immediately_when_the_marker_cannot_be_written(): void
    {
        $search = $this->search();
        Schema::drop('search_index_failures');

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $this->assertSame([5], $this->singleCalls);
        });

        $this->assertSame([], $this->bulkCalls);
    }

    public function test_updates_immediately_while_another_live_scope_holds_the_lease(): void
    {
        DB::table('search_index_failures')->insert([
            ['release_id' => 5, 'operation' => 'deferred', 'attempts' => 0, 'last_error' => 'deferred:other', 'next_attempt_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()],
            ['release_id' => 7, 'operation' => 'deferred', 'attempts' => 0, 'last_error' => 'deferred:dead', 'next_attempt_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $search = $this->search();

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $search->updateRelease(7);
            $this->assertSame([5], $this->singleCalls);
        });

        $this->assertSame([[7]], $this->bulkCalls, 'An expired lease is taken over.');
        $this->assertSame('deferred:other', DB::table('search_index_failures')->where('release_id', 5)->value('last_error'));
        $this->assertSame(0, DB::table('search_index_failures')->where('release_id', 7)->count());
    }

    public function test_a_failing_flush_leaves_markers_for_repair_and_does_not_throw(): void
    {
        $search = $this->search(function (): void {
            throw new RuntimeException('index unavailable');
        });

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
        });

        $this->assertSame(1, DB::table('search_index_failures')->where('operation', 'deferred')->count());
        $this->assertLessThanOrEqual(now()->toDateTimeString(), (string) DB::table('search_index_failures')->value('next_attempt_at'));
    }

    public function test_collects_for_the_whole_scope_and_refreshes_in_chunks_of_200(): void
    {
        $search = $this->search();

        $search->deferReleaseUpdates(function () use ($search): void {
            foreach ([1, 2] as $phase) { // creation, then NZBs for the same releases
                foreach (range(1, 201) as $releaseId) {
                    $search->updateRelease($releaseId);
                }
            }
            $this->assertSame([], $this->bulkCalls);
        });

        $this->assertSame([range(1, 200), [201]], $this->bulkCalls);
    }

    public function test_renews_the_lease_of_a_release_updated_again_after_five_minutes(): void
    {
        $search = $this->search();
        $this->travelTo(now()->startOfSecond());

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $this->travel(299)->seconds();
            $search->updateRelease(5);
            $this->assertSame(now()->subSeconds(299)->addSeconds(600)->toDateTimeString(), (string) DB::table('search_index_failures')->value('next_attempt_at'));

            $this->travel(2)->seconds();
            $search->updateRelease(5);
            $this->assertSame(now()->addSeconds(600)->toDateTimeString(), (string) DB::table('search_index_failures')->value('next_attempt_at'));
        });

        $this->assertSame([[5]], $this->bulkCalls);
    }

    public function test_leaves_a_marker_another_scope_has_taken_over(): void
    {
        $search = $this->search(function (): void {
            DB::table('search_index_failures')->where('release_id', 7)->update(['last_error' => 'deferred:other']);
        });

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $search->updateRelease(7);
        });

        $this->assertSame([7], DB::table('search_index_failures')->pluck('release_id')->map(static fn ($id): int => (int) $id)->all());
    }

    public function test_nested_scopes_flush_once_when_the_outermost_scope_ends(): void
    {
        $search = $this->search();

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $search->deferReleaseUpdates(function () use ($search): void {
                $search->updateRelease(7);
            });
            $this->assertSame([], $this->bulkCalls);
        });

        $this->assertSame([[5, 7]], $this->bulkCalls);
    }

    public function test_flushes_and_rethrows_when_the_work_fails(): void
    {
        $search = $this->search();

        try {
            $search->deferReleaseUpdates(function () use ($search): void {
                $search->updateRelease(5);

                throw new RuntimeException('release creation failed');
            });
            $this->fail('The exception should propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('release creation failed', $e->getMessage());
        }

        $this->assertSame([[5]], $this->bulkCalls);
        $search->updateRelease(9);
        $this->assertSame([9], $this->singleCalls, 'The scope is closed again after the failure.');
    }

    public function test_drivers_without_bulk_support_are_updated_immediately(): void
    {
        $driver = $this->createStub(SearchDriverInterface::class);
        $driver->method('updateRelease')->willReturnCallback(function (int|string $releaseId): void {
            $this->singleCalls[] = (int) $releaseId;
        });
        $search = $this->searchWith($driver);

        $search->deferReleaseUpdates(function () use ($search): void {
            $search->updateRelease(5);
            $search->updateRelease(7);
            $search->updateRelease(5);
            $this->assertSame([5, 7, 5], $this->singleCalls, 'Their updateRelease() cannot report failure, so nothing is deferred.');
        });

        $this->assertSame([5, 7, 5], $this->singleCalls);
        $this->assertSame(0, DB::table('search_index_failures')->count());
    }

    /**
     * @param  (callable(list<int>): void)|null  $onBulk
     */
    private function search(?callable $onBulk = null): SearchService
    {
        /** @var SearchDriverInterface&BulkReleaseIndexUpdater&Stub $driver */
        $driver = $this->createStubForIntersectionOfInterfaces([SearchDriverInterface::class, BulkReleaseIndexUpdater::class]);
        $driver->method('updateReleases')->willReturnCallback(function (array $releaseIds) use ($onBulk): void {
            $this->bulkCalls[] = $releaseIds;
            if ($onBulk !== null) {
                $onBulk($releaseIds);
            }
        });
        $driver->method('updateRelease')->willReturnCallback(function (int|string $releaseId): void {
            $this->singleCalls[] = (int) $releaseId;
        });

        return $this->searchWith($driver);
    }

    private function searchWith(SearchDriverInterface $driver): SearchService
    {
        config(['search.default' => 'fake']);
        $search = new SearchService($this->app);
        $search->extend('fake', static fn (): SearchDriverInterface => $driver);

        return $search;
    }
}
