<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\GetArticleRange;
use App\Enums\ConfigurationDomain;
use App\Services\NNTP\NNTPService;
use DariusIII\NetNntp\Error as NntpError;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Mockery;
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

    #[DataProvider('completedOverviews')]
    public function test_confirmed_empty_or_sparse_overview_allows_the_next_range_to_progress(array|NntpError $headers): void
    {
        $nntp = Mockery::mock(NNTPService::class);
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
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('selectGroup')->with('alt.test')->once()->andReturn(['first' => 1001, 'last' => 1006]);
        $nntp->shouldReceive('getXOVER')->with('1001-1003')->once()->andReturn(false);
        $nntp->shouldReceive('doQuit')->once();
        $nntp->shouldReceive('doConnect')->with(false)->once()->andReturn(false);
        $this->useNntp($nntp);

        $this->runRange(1001, 1003)->assertFailed();
        $this->runRange(1004, 1006)->assertFailed();
        $this->assertSame(1000, (int) DB::table('usenet_groups')->value('last_record'));
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
