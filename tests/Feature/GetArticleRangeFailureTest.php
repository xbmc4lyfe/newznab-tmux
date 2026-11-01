<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\GetArticleRange;
use App\Services\Binaries\MissedPartHandler;
use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

final class GetArticleRangeFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('first_record')->default(1001);
            $table->unsignedBigInteger('last_record')->default(1000);
        });
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('attempts')->default(0);
            $table->unique(['numberid', 'groups_id']);
        });
        DB::table('usenet_groups')->insert(['id' => 7, 'name' => 'alt.binaries.test']);
    }

    #[DataProvider('pausedRanges')]
    public function test_launched_range_paused_by_capacity_remains_repairable_after_sibling_cursor_progress(
        string $mode, int $first, int $last, string $cursor, int $siblingPosition
    ): void {
        $this->fillCapacity();
        $command = $this->refuseNntp();
        $this->artisan('articles:get-range', ['mode' => $mode, 'group' => 'alt.binaries.test', 'first' => $first, 'last' => $last])
            ->assertSuccessful();
        $this->assertFalse($command->contacted);
        DB::table('usenet_groups')->where('id', 7)->update([$cursor => $siblingPosition]);

        $repair = (new MissedPartHandler)->getMissingParts(7);
        $this->assertCount(500, $repair);
        $this->assertSame($first, (int) $repair[0]->numberid);
        $this->assertSame($last, (int) $repair[499]->numberid);
        $this->assertSame(0, (int) $repair[0]->attempts);
        $this->assertSame($siblingPosition, (int) DB::table('usenet_groups')->where('id', 7)->value($cursor));
    }

    /** @return array<string, array{string, int, int, string, int}> */
    public static function pausedRanges(): array
    {
        return ['forward' => ['binaries', 1001, 1500, 'last_record', 2000],
            'backfill' => ['backfill', 501, 1000, 'first_record', 1]];
    }

    public function test_repeated_capacity_deferral_preserves_existing_attempts_and_charges_no_new_attempts(): void
    {
        $this->fillCapacity();
        DB::table('missed_parts')->insert([
            ['numberid' => 1001, 'groups_id' => 7, 'attempts' => 2],
            ['numberid' => 1002, 'groups_id' => 7, 'attempts' => 2],
            ['numberid' => 1001, 'groups_id' => 8, 'attempts' => 1],
        ]);
        $command = $this->refuseNntp();

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.test', 'first' => 1001, 'last' => 1500])
            ->assertSuccessful();
        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.test', 'first' => 1001, 'last' => 1500])
            ->assertSuccessful();

        $this->assertFalse($command->contacted);
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
        $this->assertSame([1001 => 2, 1002 => 2], DB::table('missed_parts')->where('groups_id', 7)
            ->where('attempts', '>', 0)->orderBy('numberid')->pluck('attempts', 'numberid')->all());
        $this->assertSame(498, DB::table('missed_parts')->where('groups_id', 7)->where('attempts', 0)->count());
        $this->assertSame(1, (int) DB::table('missed_parts')->where(['groups_id' => 8, 'numberid' => 1001])->value('attempts'));
        DB::table('usenet_groups')->where('id', 7)->update(['last_record' => 2000]);
        $repairable = (new MissedPartHandler)->getMissingParts(7);
        $this->assertCount(500, $repairable);
        $this->assertSame(1001, (int) $repairable[0]->numberid);
        $this->assertSame(1500, (int) $repairable[499]->numberid);
    }

    public function test_contiguous_capacity_pause_leaves_the_cursor_pending_without_creating_repair_work(): void
    {
        $this->fillCapacity();
        $command = $this->refuseNntp();

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.test', 'first' => 1001,
            'last' => 1500, '--contiguous' => true])->assertSuccessful();
        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.test', 'first' => 1001,
            'last' => 1500, '--contiguous' => true])->assertSuccessful();

        $this->assertFalse($command->contacted);
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
        $this->assertSame(0, DB::table('missed_parts')->count());
    }

    #[DataProvider('capacityAdmissionFailures')]
    public function test_capacity_admission_failure_preserves_unattempted_range_after_later_cursor_progress(
        string $mode, bool $contiguous, string $phase, int $first, int $last, string $cursor, int $initialCursor, int $laterCursor
    ): void {
        ConfigurationTestBuilder::installDefaults();
        config(['release_verification.required' => true, 'release_verification.pending_bytes' => 100]);
        Schema::create('release_verification_state', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('pending_bytes');
        });
        DB::table('release_verification_state')->insert(['id' => 1, 'pending_bytes' => 0]);
        DB::table('missed_parts')->insert([
            ['numberid' => $first, 'groups_id' => 7, 'attempts' => 2],
            ['numberid' => $first + 1, 'groups_id' => 7, 'attempts' => 2],
            ['numberid' => $last, 'groups_id' => 7, 'attempts' => 2],
        ]);
        $checks = 0;
        $injected = false;
        DB::listen(static function (QueryExecuted $query) use ($phase, &$checks, &$injected): void {
            if (! str_starts_with($query->sql, 'select "pending_bytes" from "release_verification_state"')) {
                return;
            }
            $checks++;
            if (($phase === 'initial-lookup' && $checks === 1) || ($phase === 'scan-lookup' && $checks === 2)) {
                $injected = true;
                throw new \RuntimeException('Capacity state lookup failed before normal headers.');
            }
            if ($phase === 'scan-full' && $checks === 1) {
                $injected = true;
                DB::table('release_verification_state')->where('id', 1)->update(['pending_bytes' => 100]);
            }
        });
        $ranges = [];
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->with('alt.binaries.test')->andReturn(['first' => 1, 'last' => 2000]);
        $nntp->shouldReceive('getXOVER')->andReturnUsing(static function (string $range) use (&$ranges): array {
            $ranges[] = $range;

            return [];
        });
        $command = new class($nntp) extends GetArticleRange
        {
            public function __construct(private NNTPService $nntp)
            {
                parent::__construct();
            }

            protected function getNntp(): NNTPService
            {
                return $this->nntp;
            }
        };
        $this->app->instance(GetArticleRange::class, $command);

        $this->artisan('articles:get-range', ['mode' => $mode, 'group' => 'alt.binaries.test', 'first' => $first,
            'last' => $last, '--contiguous' => $contiguous])->assertFailed();

        $this->assertTrue($injected);
        $this->assertSame($phase === 'initial-lookup' ? 1 : 2, $checks);
        $this->assertSame([], $ranges);
        $this->assertSame($initialCursor, (int) DB::table('usenet_groups')->value($cursor));
        $this->assertSame([$first => 2, $first + 1 => 2, $last => 2],
            DB::table('missed_parts')->where('groups_id', 7)->orderBy('numberid')->pluck('attempts', 'numberid')->all());
        DB::table('usenet_groups')->where('id', 7)->update([$cursor => $laterCursor]);
        $repairable = (new MissedPartHandler)->getMissingParts(7);
        $this->assertSame([$first, $first + 1, $last], array_map(static fn (\stdClass $part): int => (int) $part->numberid, $repairable));
    }

    /** @return iterable<string, array{string, bool, string, int, int, string, int, int}> */
    public static function capacityAdmissionFailures(): iterable
    {
        foreach (['initial-lookup', 'scan-lookup', 'scan-full'] as $phase) {
            yield 'legacy forward '.$phase => ['binaries', false, $phase, 1001, 1003, 'last_record', 1000, 2000];
            yield 'contiguous forward '.$phase => ['binaries', true, $phase, 1001, 1003, 'last_record', 1000, 2000];
            yield 'legacy backfill '.$phase => ['backfill', false, $phase, 501, 503, 'first_record', 1001, 1];
        }
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_range_or_mode_cannot_enqueue_articles_even_when_capacity_is_full(string $mode, string $first, string $last): void
    {
        $this->fillCapacity();
        $command = $this->refuseNntp();
        $this->artisan('articles:get-range', ['mode' => $mode, 'group' => 'alt.binaries.test', 'first' => $first, 'last' => $last])
            ->assertFailed();
        $this->assertFalse($command->contacted);
        $this->assertSame(0, DB::table('missed_parts')->count());
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidRanges(): array
    {
        return ['invalid mode' => ['invalid', '1', '50'], 'reversed' => ['binaries', '50', '1'],
            'non numeric' => ['binaries', 'invalid', '50'], 'zero' => ['backfill', '0', '50']];
    }

    public function test_capacity_deferral_insert_failure_reports_failure_without_nntp_or_cursor_changes(): void
    {
        $this->fillCapacity();
        DB::statement("CREATE TRIGGER reject_repair BEFORE INSERT ON missed_parts BEGIN SELECT RAISE(ABORT, 'repair storage unavailable'); END");
        $command = $this->refuseNntp();

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.test', 'first' => 1001, 'last' => 1500])
            ->assertFailed();

        $this->assertFalse($command->contacted);
        $this->assertSame(0, DB::table('missed_parts')->count());
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
    }

    public function test_capacity_deferral_for_unknown_group_cannot_report_a_durable_queue(): void
    {
        $this->fillCapacity();
        $command = $this->refuseNntp();

        $this->artisan('articles:get-range', ['mode' => 'backfill', 'group' => 'alt.binaries.missing', 'first' => 501, 'last' => 1000])
            ->assertFailed();

        $this->assertFalse($command->contacted);
        $this->assertSame(0, DB::table('missed_parts')->count());
        $this->assertSame(1001, (int) DB::table('usenet_groups')->value('first_record'));
    }

    private function fillCapacity(): void
    {
        config(['release_verification.required' => true, 'release_verification.pending_bytes' => 100]);
        Schema::create('release_verification_state', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('pending_bytes');
        });
        DB::table('release_verification_state')->insert(['id' => 1, 'pending_bytes' => 100]);
    }

    /** @return GetArticleRange&object{contacted: bool} */
    private function refuseNntp(): GetArticleRange
    {
        $command = new class extends GetArticleRange
        {
            public bool $contacted = false;

            protected function getNntp(): NNTPService
            {
                $this->contacted = true;
                throw new \LogicException('A capacity-paused or invalid range must not contact NNTP.');
            }
        };
        $this->app->instance(GetArticleRange::class, $command);

        return $command;
    }

    /**
     * Ranges run in parallel and every successful one advances the group cursor to its own end, so a
     * range whose worker could not log in would otherwise never be fetched (it was not in missed_parts).
     */
    public function test_a_range_that_cannot_connect_is_queued_for_part_repair(): void
    {
        $this->app->bind(GetArticleRange::class, fn (): GetArticleRange => new class extends GetArticleRange
        {
            protected function getNntp(): NNTPService
            {
                throw new \Exception('Unable to connect to usenet. Error: Cannot authenticate to server news.example.test (ssl) (Authentication rejected)');
            }
        });

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.test', 'first' => 1001, 'last' => 1500])
            ->assertFailed();

        $this->assertSame(500, DB::table('missed_parts')->where('groups_id', 7)->count());
        $this->assertSame(1001, (int) DB::table('missed_parts')->min('numberid'));
        $this->assertSame(1500, (int) DB::table('missed_parts')->max('numberid'));
        $this->assertSame(0, (int) DB::table('missed_parts')->value('attempts'));
    }

    public function test_a_range_whose_xover_scan_fails_is_queued_for_part_repair_and_returns_failure(): void
    {
        ConfigurationTestBuilder::installDefaults();
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->with('alt.binaries.test')->andReturn(['first' => 1, 'last' => 2000]);
        $nntp->shouldReceive('getXOVER')->once()->with('1001-1500')->andReturn(new Error('XOVER failed', 503));
        $nntp->shouldReceive('reconnect')->once()->with(false)->andReturn(false);

        $command = new class($nntp) extends GetArticleRange
        {
            public function __construct(private NNTPService $nntp)
            {
                parent::__construct();
            }

            protected function getNntp(): NNTPService
            {
                return $this->nntp;
            }
        };
        $this->app->instance(GetArticleRange::class, $command);

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.test', 'first' => 1001, 'last' => 1500])
            ->assertFailed();

        $this->assertSame(500, DB::table('missed_parts')->where('groups_id', 7)->count());
        $this->assertSame(1001, (int) DB::table('missed_parts')->min('numberid'));
        $this->assertSame(1500, (int) DB::table('missed_parts')->max('numberid'));
        $this->assertSame(0, (int) DB::table('missed_parts')->value('attempts'));
        $this->assertSame(1000, (int) DB::table('usenet_groups')->where('id', 7)->value('last_record'));
    }

    public function test_a_failed_xover_scan_whose_repair_insert_fails_does_not_report_a_durable_queue(): void
    {
        ConfigurationTestBuilder::installDefaults();
        DB::statement("CREATE TRIGGER reject_repair BEFORE INSERT ON missed_parts BEGIN SELECT RAISE(ABORT, 'repair storage unavailable'); END");
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->with('alt.binaries.test')->andReturn(['first' => 1, 'last' => 2000]);
        $nntp->shouldReceive('getXOVER')->once()->with('1001-1500')->andReturn(new Error('XOVER failed', 503));
        $nntp->shouldReceive('reconnect')->once()->with(false)->andReturn(false);

        $command = new class($nntp) extends GetArticleRange
        {
            public function __construct(private NNTPService $nntp)
            {
                parent::__construct();
            }

            protected function getNntp(): NNTPService
            {
                return $this->nntp;
            }
        };
        $this->app->instance(GetArticleRange::class, $command);

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.test', 'first' => 1001, 'last' => 1500])
            ->expectsOutput('Header download did not complete and this range could not be queued for repair; retry this worker.')
            ->doesntExpectOutput('Header download did not complete; queued this range for part repair.')
            ->assertFailed();

        $this->assertSame(0, DB::table('missed_parts')->count());
        $this->assertSame(1000, (int) DB::table('usenet_groups')->where('id', 7)->value('last_record'));
    }

    public function test_an_unknown_group_queues_nothing(): void
    {
        $this->app->bind(GetArticleRange::class, fn (): GetArticleRange => new class extends GetArticleRange
        {
            protected function getNntp(): NNTPService
            {
                throw new \Exception('Unable to connect to usenet.');
            }
        });

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.binaries.missing', 'first' => 1, 'last' => 50])
            ->assertFailed();

        $this->assertSame(0, DB::table('missed_parts')->count());
    }
}
