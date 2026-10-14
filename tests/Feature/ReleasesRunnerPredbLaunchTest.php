<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\NameFixing\NameFixingQueryService;
use App\Services\Runners\ReleasesRunner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReleasesRunnerPredbLaunchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['nntmux.echocli' => false, 'nntmux.stream_fork_output' => true]);
    }

    /** @return array<string, array{int, int}> */
    public static function populations(): array
    {
        return [
            'empty' => [0, 0],
            'one title' => [1, 1],
            'partial first batch' => [99, 1],
            'full first batch' => [100, 1],
            'second batch' => [101, 2],
            'partial sixteenth batch' => [1599, 16],
            'saturated' => [1600, 16],
            'larger than threshold' => [1000000, 16],
        ];
    }

    #[DataProvider('populations')]
    public function test_bounded_count_preserves_worker_slots_and_modulo_partition_count(int $population, int $expectedWorkers): void
    {
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects($this->once())->method('select')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'LIMIT ?')),
                $this->callback(static fn (array $bindings): bool => $bindings[array_key_last($bindings)] === 1600)
            )
            ->willReturn([(object) ['num' => min(1600, $population)]]);
        $this->app->instance(NameFixingQueryService::class, new NameFixingQueryService($database));
        DB::shouldReceive('select')->never();

        $runner = new CapturingPredbLaunchRunner;
        $runner->fixRelNames('predbft', 100, 3);

        $this->assertCount($expectedWorkers, $runner->commands);
        foreach ($runner->commands as $index => $command) {
            $arguments = explode(' ', $command[2]);
            $this->assertSame('predbft', $arguments[0]);
            $this->assertSame('100', $arguments[2]);
            $this->assertSame((string) ($index + 1), $arguments[3]);
            $this->assertSame((string) $expectedWorkers, $arguments[4]);
        }
        if ($expectedWorkers > 0) {
            $this->assertSame(3, $runner->parallelism);
        }
    }

    /** @return array<string, array{int}> */
    public static function disabledLimits(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    #[DataProvider('disabledLimits')]
    public function test_a_disabled_launch_does_not_scan_the_catalog(int $limit): void
    {
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects($this->never())->method('select');
        $this->app->instance(NameFixingQueryService::class, new NameFixingQueryService($database));
        DB::shouldReceive('select')->never();

        $runner = new CapturingPredbLaunchRunner;
        $runner->fixRelNames('predbft', $limit, 3);

        $this->assertSame([], $runner->commands);
    }

    public function test_standard_guid_partitions_do_not_depend_on_the_predb_catalog(): void
    {
        $database = $this->createMock(ConnectionInterface::class);
        $database->expects($this->never())->method('select');
        $this->app->instance(NameFixingQueryService::class, new NameFixingQueryService($database));
        DB::shouldReceive('select')->never();

        $runner = new CapturingPredbLaunchRunner;
        $runner->fixRelNames('standard', 100, 3);

        $this->assertCount(16, $runner->commands);
        foreach ($runner->commands as $index => $command) {
            $arguments = explode(' ', $command[2]);
            $this->assertSame(dechex($index), $arguments[1]);
            $this->assertSame('16', $arguments[4]);
        }
    }
}

class CapturingPredbLaunchRunner extends ReleasesRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public int $parallelism = 0;

    protected function runStreamingCommands(array $commands, int $maxProcesses, string $desc): void
    {
        $this->commands = $commands;
        $this->parallelism = $maxProcesses;
    }
}
