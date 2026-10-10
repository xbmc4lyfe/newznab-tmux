<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Facades\Search;
use App\Services\Search\Contracts\BulkReleaseIndexUpdater;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\SearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NntmuxSearchRepairCommandTest extends SearchConsoleCommandTestCase
{
    /** @var list<int> */
    private array $updated = [];

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_refreshes_a_release_whose_deferral_lease_expired_and_removes_the_marker(): void
    {
        $this->useDriver();
        $this->insertMarker(5, 'deferred:dead', now()->subMinute());
        $this->insertMarker(7, 'deferred:alive', now()->addMinutes(5));

        $this->artisan('nntmux:search-repair')->assertSuccessful();

        $this->assertSame([5], $this->updated);
        $this->assertSame([7], DB::table('search_index_failures')->pluck('release_id')->map(static fn ($id): int => (int) $id)->all());
    }

    public function test_keeps_the_row_when_the_refresh_fails(): void
    {
        $this->useDriver(static function (int $releaseId): bool {
            // What ManticoreSearchDriver::recordReleaseIndexFailure() does on a leased release.
            DB::table('search_index_failures')->where('release_id', $releaseId)->increment('attempts');

            return false;
        });
        $this->insertMarker(5, 'deferred:dead', now()->subMinute());

        $this->artisan('nntmux:search-repair')->assertSuccessful();

        $row = DB::table('search_index_failures')->where('release_id', 5)->sole();
        $this->assertSame(1, (int) $row->attempts);
        $this->assertGreaterThan(now()->toDateTimeString(), (string) $row->next_attempt_at, 'Retried with backoff.');
    }

    public function test_keeps_a_marker_its_release_pass_renewed_during_the_refresh(): void
    {
        $this->useDriver(static function (int $releaseId): bool {
            DB::table('search_index_failures')->where('release_id', $releaseId)->update(['next_attempt_at' => now()->addMinutes(10)]);

            return true;
        });
        $this->insertMarker(5, 'deferred:slow', now()->subMinute());

        $this->artisan('nntmux:search-repair')->assertSuccessful();

        $this->assertSame(1, DB::table('search_index_failures')->where('release_id', 5)->count());
    }

    /**
     * @param  (callable(int): bool)|null  $onUpdate  Returns whether the refresh worked
     */
    private function useDriver(?callable $onUpdate = null): void
    {
        $driver = $this->createStubForIntersectionOfInterfaces([SearchDriverInterface::class, BulkReleaseIndexUpdater::class]);
        $driver->method('updateReleases')->willReturnCallback(function (array $releaseIds) use ($onUpdate): array {
            $failed = [];
            foreach ($releaseIds as $releaseId) {
                $this->updated[] = $releaseId;
                if ($onUpdate !== null && ! $onUpdate($releaseId)) {
                    $failed[] = $releaseId;
                }
            }

            return $failed;
        });
        config(['search.default' => 'fake']);
        $search = new SearchService($this->app);
        $search->extend('fake', static fn (): SearchDriverInterface => $driver);
        Search::swap($search);
    }

    private function insertMarker(int $releaseId, string $token, \DateTimeInterface $leaseEnds): void
    {
        DB::table('search_index_failures')->insert([
            'release_id' => $releaseId,
            'operation' => SearchService::DEFERRED_RELEASE_OPERATION,
            'attempts' => 0,
            'last_error' => $token,
            'next_attempt_at' => $leaseEnds,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
