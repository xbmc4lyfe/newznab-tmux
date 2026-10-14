<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\NNTP\NNTPService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class BinariesServiceDrainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        DB::reconnect();
        ConfigurationTestBuilder::installDefaults();
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->unsignedBigInteger('last_record');
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'last_record' => 1000]);
    }

    public function test_drain_saves_completed_chunk_and_resume_starts_at_the_next_chunk(): void
    {
        $service = new class(new BinariesConfig(messageBuffer: 3, echoCli: false)) extends BinariesService
        {
            /** @var list<array{int, int}> */
            public array $ranges = [];

            public function scan(array $groupMySQL, int $first, int $last, string $type = 'update', ?array $missingParts = null): array
            {
                $this->ranges[] = [$first, $last];
                app(ProcessingRuntimeStateRepository::class)->requestStop();

                return ['firstArticleNumber' => $first, 'lastArticleNumber' => $last,
                    'firstArticleDate' => '2026-10-14 00:00:00', 'lastArticleDate' => '2026-10-14 00:00:00'];
            }
        };
        $group = ['id' => 1, 'name' => 'alt.test', 'first_record' => 1, 'first_record_postdate' => '2026-10-01 00:00:00'];
        $process = new ReflectionMethod(BinariesService::class, 'processArticleRange');
        $process->invokeArgs($service, [&$group, [], ['first' => 1000, 'last' => 1009, 'groupLast' => 1009, 'total' => 9]]);
        $this->assertSame([[1001, 1003]], $service->ranges);
        $this->assertSame(1003, (int) DB::table('usenet_groups')->value('last_record'));

        app(ProcessingRuntimeStateRepository::class)->requestStop(false);
        $cursor = (int) DB::table('usenet_groups')->value('last_record');
        $process->invokeArgs($service, [&$group, [], ['first' => $cursor, 'last' => 1009, 'groupLast' => 1009, 'total' => 6]]);
        $this->assertSame([[1001, 1003], [1004, 1006]], $service->ranges);
        $this->assertSame(1006, (int) DB::table('usenet_groups')->value('last_record'));
    }

    public function test_initial_group_scan_honors_visit_budget_without_skipping_its_older_headers(): void
    {
        $service = new BinariesService(new BinariesConfig(messageBuffer: 100, newGroupScanByDays: false, newGroupMessagesToScan: 1000, echoCli: false));
        $range = (new ReflectionMethod(BinariesService::class, 'calculateArticleRange'))->invoke(
            $service, ['last_record' => 0], ['first' => 1, 'last' => 10000], 50,
        );
        $this->assertSame(8900, $range['first']);
        $this->assertSame(8950, $range['last']);
        $this->assertSame(50, $range['total']);
    }

    #[DataProvider('overviewResults')]
    public function test_chunk_cursor_distinguishes_empty_overview_from_failed_download(bool $successful, int $expectedCursor): void
    {
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getXOVER')->with('1001-1003')->once()->andReturnUsing(function () use ($successful) {
            app(ProcessingRuntimeStateRepository::class)->requestStop();

            return $successful ? [] : false;
        });
        if (! $successful) {
            $nntp->shouldReceive('reconnect')->with(false)->once()->andReturn(false);
        }
        $service = new BinariesService(new BinariesConfig(messageBuffer: 3, echoCli: false), nntp: $nntp);
        $group = ['id' => 1, 'name' => 'alt.test', 'first_record' => 1, 'first_record_postdate' => '2026-10-01 00:00:00'];
        (new ReflectionMethod(BinariesService::class, 'processArticleRange'))->invokeArgs(
            $service, [&$group, [], ['first' => 1000, 'last' => 1009, 'groupLast' => 1009, 'total' => 9]],
        );

        $this->assertSame($expectedCursor, (int) DB::table('usenet_groups')->value('last_record'));
    }

    /** @return array<string, array{bool, int}> */
    public static function overviewResults(): array
    {
        return ['empty response' => [true, 1003], 'failed download' => [false, 1000]];
    }
}
