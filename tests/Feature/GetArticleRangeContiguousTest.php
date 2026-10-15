<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\GetArticleRange;
use App\Enums\ConfigurationDomain;
use App\Services\Binaries\MissedPartHandler;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class GetArticleRangeContiguousTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('last_record');
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test', 'last_record' => 1000]);
        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, ['part_repair' => false]);
    }

    public function test_later_range_cannot_advance_past_an_interrupted_sibling(): void
    {
        $command = new class extends GetArticleRange
        {
            public bool $contacted = false;

            protected function getNntp(): NNTPService
            {
                $this->contacted = true;
                throw new \LogicException('Do not fetch a range beyond an unfinished predecessor.');
            }
        };
        $this->app->instance(GetArticleRange::class, $command);
        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => 2001, 'last' => 3000, '--contiguous' => true])
            ->assertFailed();
        $this->assertFalse($command->contacted);
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
    }

    /** @param list<array<string, mixed>>|NntpError $headers */
    #[DataProvider('completedOverviews')]
    public function test_confirmed_empty_or_sparse_overview_allows_the_next_range_to_progress(array|NntpError $headers): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->with('alt.test')->twice()->andReturn(['first' => 1001, 'last' => 1006]);
        $nntp->shouldReceive('getXOVER')->with('1001-1003')->once()->andReturn($headers);
        $nntp->shouldReceive('getXOVER')->with('1004-1006')->once()->andReturn([
            ['Number' => 1004, 'Subject' => 'A plain text header', 'Date' => '2026-10-14 00:00:00'],
        ]);
        $this->useNntp($nntp);

        $this->runRange(1001, 1003)->assertSuccessful();
        $this->assertSame(1003, (int) DB::table('usenet_groups')->value('last_record'));
        $this->runRange(1004, 1006)->assertSuccessful();
        $this->assertSame(1006, (int) DB::table('usenet_groups')->value('last_record'));
    }

    /** @return array<string, array{list<array<string, mixed>>|NntpError}> */
    public static function completedOverviews(): array
    {
        return [
            'empty window' => [[]],
            'no articles status 423' => [new NntpError('No articles in that range', 423)],
            'missing tail' => [[['Number' => 1001, 'Subject' => 'A plain text header', 'Date' => '2026-10-14 00:00:00']]],
        ];
    }

    public function test_failed_overview_never_advances_the_cursor_or_unlocks_a_successor(): void
    {
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->with('alt.test')->once()->andReturn(['first' => 1001, 'last' => 1006]);
        $nntp->shouldReceive('getXOVER')->with('1001-1003')->once()->andReturn(false);
        $nntp->shouldReceive('reconnect')->with(false)->once()->andReturn(false);
        $this->useNntp($nntp);

        $this->runRange(1001, 1003)->assertFailed();
        $this->runRange(1004, 1006)->assertFailed();
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
    }

    /** @param array<int, int> $pending */
    #[DataProvider('drainedRepairModes')]
    public function test_stop_during_repairs_defers_the_normal_range_and_preserves_its_cursor(bool $contiguous, bool $alreadyPending, array $pending): void
    {
        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, ['part_repair' => true, 'max_messages' => 100]);
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('attempts');
            $table->unique(['numberid', 'groups_id']);
        });
        DB::table('missed_parts')->insert([
            ['numberid' => 101, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 102, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 201, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 202, 'groups_id' => 1, 'attempts' => 2],
        ]);
        if ($alreadyPending) {
            DB::table('missed_parts')->insert([
                ['numberid' => 1001, 'groups_id' => 1, 'attempts' => 2],
                ['numberid' => 1002, 'groups_id' => 1, 'attempts' => 2],
                ['numberid' => 1003, 'groups_id' => 1, 'attempts' => 2],
            ]);
        }
        $ranges = [];
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->with('alt.test')->times($alreadyPending ? 2 : 1)->andReturn(['first' => 1, 'last' => 1003]);
        $nntp->shouldReceive('getOverview')->andReturnUsing(function (string $range) use (&$ranges): array {
            $ranges[] = 'repair:'.$range;
            app(ProcessingRuntimeStateRepository::class)->requestStop();

            return [];
        });
        $nntp->shouldReceive('getXOVER')->andReturnUsing(function (string $range) use (&$ranges): array {
            $ranges[] = 'normal:'.$range;

            return [];
        });
        $this->useNntp($nntp);

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => 1001,
            'last' => 1003, '--contiguous' => $contiguous, '--repair' => true])->assertSuccessful();
        if ($alreadyPending) {
            $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => 1001,
                'last' => 1003, '--contiguous' => false, '--repair' => true])->assertSuccessful();
        }

        $this->assertSame(['repair:101-102'], $ranges);
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
        $this->assertSame($pending,
            DB::table('missed_parts')->orderBy('numberid')->pluck('attempts', 'numberid')->all());
        if (! $contiguous) {
            DB::table('usenet_groups')->update(['last_record' => 2000]);
            $repairable = array_map(static fn (\stdClass $part): int => (int) $part->numberid,
                (new MissedPartHandler)->getMissingParts(1));
            $this->assertSame([1001, 1002, 1003], array_values(array_intersect($repairable, [1001, 1002, 1003])));
            $this->assertSame(2000, (int) DB::table('usenet_groups')->value('last_record'));
        }
    }

    /** @return array<string, array{bool, bool, array<int, int>}> */
    public static function drainedRepairModes(): array
    {
        return [
            'contiguous cursor preserves pending range' => [true, false, [101 => 2, 102 => 2, 201 => 2, 202 => 2]],
            'parallel range remains repairable past a sibling cursor' => [false, false, [101 => 2, 102 => 2, 201 => 2, 202 => 2,
                1001 => 0, 1002 => 0, 1003 => 0]],
            'repeated deferral preserves existing tries' => [false, true, [101 => 2, 102 => 2, 201 => 2, 202 => 2,
                1001 => 2, 1002 => 2, 1003 => 2]],
        ];
    }

    #[DataProvider('uncertainAdmissionModes')]
    public function test_uncertain_admission_fails_without_consuming_existing_repair_attempts(bool $contiguous): void
    {
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('attempts');
            $table->unique(['numberid', 'groups_id']);
        });
        DB::table('missed_parts')->insert([
            ['numberid' => 1001, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 1002, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 1003, 'groups_id' => 1, 'attempts' => 2],
        ]);
        $failedLookup = false;
        DB::listen(static function (QueryExecuted $query) use (&$failedLookup): void {
            if (! $failedLookup && str_starts_with($query->sql, 'select * from "processing_runtime_states"')) {
                $failedLookup = true;
                throw new \RuntimeException('Runtime admission state unavailable.');
            }
        });
        $ranges = [];
        $nntp = $this->mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->with('alt.test')->once()->andReturn(['first' => 1, 'last' => 1003]);
        $nntp->shouldReceive('getXOVER')->andReturnUsing(static function (string $range) use (&$ranges): array {
            $ranges[] = $range;

            return [];
        });
        $this->useNntp($nntp);

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => 1001,
            'last' => 1003, '--contiguous' => $contiguous])->assertFailed();

        $this->assertTrue($failedLookup);
        $this->assertSame([], $ranges);
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
        $this->assertSame([1001 => 2, 1002 => 2, 1003 => 2],
            DB::table('missed_parts')->orderBy('numberid')->pluck('attempts', 'numberid')->all());
        if (! $contiguous) {
            DB::table('usenet_groups')->update(['last_record' => 2000]);
            $repairable = (new MissedPartHandler)->getMissingParts(1);
            $this->assertSame([1001, 1002, 1003], array_map(static fn (\stdClass $part): int => (int) $part->numberid, $repairable));
        }
    }

    /** @return array<string, array{bool}> */
    public static function uncertainAdmissionModes(): array
    {
        return ['contiguous' => [true], 'parallel' => [false]];
    }

    /** @param list<string> $expectedRepairs */
    #[DataProvider('preScanFailures')]
    public function test_pre_scan_failure_preserves_normal_range_and_unstarted_repair_attempts(
        bool $contiguous, string $phase, int $failedPoll, array $expectedRepairs, int $firstRepairAttempts
    ): void {
        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, ['part_repair' => true, 'max_messages' => 100]);
        Schema::create('missed_parts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedBigInteger('numberid');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('attempts');
            $table->timestamps();
            $table->unique(['numberid', 'groups_id']);
        });
        DB::table('missed_parts')->insert([
            ['numberid' => 101, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 102, 'groups_id' => 1, 'attempts' => 1],
            ['numberid' => 201, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 202, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 1001, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 1002, 'groups_id' => 1, 'attempts' => 2],
            ['numberid' => 1003, 'groups_id' => 1, 'attempts' => 2],
        ]);
        $polls = 0;
        $injected = false;
        DB::listen(static function (QueryExecuted $query) use ($failedPoll, &$polls, &$injected): void {
            if (str_starts_with($query->sql, 'select * from "processing_runtime_states"')) {
                $polls++;
                if ($failedPoll > 0 && $polls === $failedPoll) {
                    $injected = true;
                    throw new \RuntimeException('Runtime state failed before the normal range.');
                }
            }
        });
        $repairs = [];
        $normalRanges = [];
        if ($phase === 'connection') {
            $this->app->instance(GetArticleRange::class, new class extends GetArticleRange
            {
                protected function getNntp(): NNTPService
                {
                    throw new \RuntimeException('Connection failed before the normal range.');
                }
            });
        } else {
            $nntp = $this->mock(NNTPService::class);
            $nntp->shouldReceive('selectGroup')->with('alt.test')->once()->andReturnUsing(static function () use ($phase): array|NntpError {
                if ($phase === 'selection-throw') {
                    throw new \RuntimeException('Selection failed before the normal range.');
                }

                return $phase === 'selection-error' ? new NntpError('Group selection failed', 411) : ['first' => 1, 'last' => 1003];
            });
            if ($phase === 'selection-error') {
                $nntp->shouldReceive('dataError')->with($nntp, 'alt.test')->once()->andReturn(new NntpError('Group selection retry failed', 411));
            }
            $nntp->shouldReceive('getOverview')->andReturnUsing(static function (string $range) use (&$repairs): false {
                $repairs[] = $range;

                return false;
            });
            $nntp->shouldReceive('getXOVER')->andReturnUsing(static function (string $range) use (&$normalRanges): array {
                $normalRanges[] = $range;

                return [];
            });
            $this->useNntp($nntp);
        }

        $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => 1001,
            'last' => 1003, '--contiguous' => $contiguous, '--repair' => true])->assertFailed();

        $this->assertSame($failedPoll > 0, $injected);
        $this->assertSame($failedPoll, $polls);
        $this->assertSame($expectedRepairs, $repairs);
        $this->assertSame([], $normalRanges);
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
        $this->assertSame([101 => $firstRepairAttempts, 102 => $firstRepairAttempts, 201 => 2, 202 => 2,
            1001 => 2, 1002 => 2, 1003 => 2], DB::table('missed_parts')->orderBy('numberid')->pluck('attempts', 'numberid')->all());
        if (! $contiguous) {
            DB::table('usenet_groups')->update(['last_record' => 2000]);
            $repairable = array_map(static fn (\stdClass $part): int => (int) $part->numberid,
                (new MissedPartHandler)->getMissingParts(1));
            $this->assertSame([1001, 1002, 1003], array_values(array_intersect($repairable, [1001, 1002, 1003])));
        }
    }

    /** @return iterable<string, array{bool, string, int, list<string>, int}> */
    public static function preScanFailures(): iterable
    {
        $phases = [
            'repair entry' => ['repair-entry', 1, [], 1],
            'first repair boundary' => ['repair-first-range', 2, [], 1],
            'next repair boundary' => ['repair-next-range', 3, ['101-102'], 2],
            'connection exception' => ['connection', 0, [], 1],
            'selection exception' => ['selection-throw', 0, [], 1],
            'selection error response' => ['selection-error', 0, [], 1],
        ];
        foreach ($phases as $name => [$phase, $poll, $repairs, $attempts]) {
            yield 'contiguous '.$name => [true, $phase, $poll, $repairs, $attempts];
            yield 'parallel '.$name => [false, $phase, $poll, $repairs, $attempts];
        }
    }

    private function runRange(int $first, int $last): PendingCommand
    {
        return $this->artisan('articles:get-range', ['mode' => 'binaries', 'group' => 'alt.test', 'first' => $first, 'last' => $last, '--contiguous' => true]);
    }

    private function useNntp(NNTPService $nntp): void
    {
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
    }
}
