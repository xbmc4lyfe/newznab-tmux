<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class BackfillDrainTest extends TestCase
{
    public function test_backfill_commits_the_active_range_before_yielding_and_resumes_from_that_cursor(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, first_record INTEGER, first_record_postdate TEXT, last_updated TEXT)');
        DB::table('usenet_groups')->insert(['id' => 1, 'first_record' => 301]);
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('FROM_UNIXTIME', static fn (int $time): string => date('Y-m-d H:i:s', $time));
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $ranges = [];
        $binaries = Mockery::mock(BinariesService::class);
        $binaries->shouldReceive('getMessageBuffer')->andReturn(100);
        $binaries->shouldReceive('scan')->andReturnUsing(static function (array $group, int $first, int $last) use (&$ranges): array {
            $ranges[] = [$first, $last];
            ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);

            return ['firstArticleDate' => '2026-10-01'];
        });
        $service = new BackfillService(new BackfillConfig, $binaries, Mockery::mock(NNTPService::class));
        $method = new ReflectionMethod($service, 'processBackfillChunks');
        $group = ['id' => 1, 'name' => 'alt.test', 'first_record' => 301];
        $method->invoke($service, $group, 1, 0, 'test');
        $this->assertSame([[201, 300]], $ranges);
        $this->assertSame(201, (int) DB::table('usenet_groups')->value('first_record'));

        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        $group['first_record'] = 201;
        $method->invoke($service, $group, 1, 0, 'test');
        $this->assertSame([[201, 300], [101, 200]], $ranges);
        $this->assertSame(101, (int) DB::table('usenet_groups')->value('first_record'));
    }
}
