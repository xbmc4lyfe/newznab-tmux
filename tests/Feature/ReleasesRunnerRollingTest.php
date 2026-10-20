<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConfigurationDomain;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Runners\ReleasesRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class ReleasesRunnerRollingTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.echocli' => false,
            'nntmux.stream_fork_output' => false,
            'nntmux.concurrency_timeout' => 30,
        ]);
        DB::purge('sqlite');
        Schema::create('usenet_groups', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
            $table->integer('active');
            $table->integer('backfill');
        });
        Schema::create('collections', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('groups_id');
        });
        foreach ([1, 2] as $id) {
            DB::table('usenet_groups')->insert(['id' => $id, 'name' => 'alt.test.'.$id, 'active' => 1, 'backfill' => 0]);
            DB::table('collections')->insert(['groups_id' => $id]);
        }
        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, ['release_threads' => 2]);
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-rolling-');
        $this->assertNotFalse($marker);
        $this->marker = $marker;
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    /** @return array<string, array{bool}> */
    public static function outputModes(): array
    {
        return ['collected output' => [false], 'streamed output' => [true]];
    }

    #[DataProvider('outputModes')]
    public function test_a_fast_group_runs_again_while_the_round_waits_on_a_slow_group(bool $streaming): void
    {
        config(['nntmux.cbp.release_rolling_interval' => 1, 'nntmux.stream_fork_output' => $streaming]);

        $this->runRound(new RollingReleasesRunner($this->marker, slowGroup: 2, slowSeconds: 3));

        $full = $this->fullPasses();
        $this->assertGreaterThanOrEqual(2, $full[1] ?? 0, 'The fast group should start another pass while group 2 runs.');
        $this->assertLessThanOrEqual(4, $full[1] ?? 0, 'Passes of one group must start at most once per interval.');
        $this->assertSame(1, $full[2] ?? 0);
        $this->assertSame(['ready:1', 'ready:2'], $this->readyPasses());
    }

    public function test_follow_ups_still_waiting_for_their_interval_are_dropped_when_the_slowest_group_ends(): void
    {
        config(['nntmux.cbp.release_rolling_interval' => 600]);
        $runner = new RollingReleasesRunner($this->marker, slowGroup: 2, slowSeconds: 1);

        $started = microtime(true);
        $this->runRound($runner);

        $this->assertLessThan(15, microtime(true) - $started);
        $this->assertSame([1 => 1, 2 => 1], $this->fullPasses());
        $this->assertSame('success', $runner->outcomes()[1]['status']);
        $this->assertSame('success', $runner->outcomes()[2]['status']);
    }

    public function test_a_zero_interval_keeps_one_pass_per_group_per_round(): void
    {
        config(['nntmux.cbp.release_rolling_interval' => 0]);

        $this->runRound(new RollingReleasesRunner($this->marker, slowGroup: 2, slowSeconds: 2));

        $this->assertSame([1 => 1, 2 => 1], $this->fullPasses());
    }

    public function test_a_drain_request_stops_further_passes_without_failing_the_round(): void
    {
        config(['nntmux.cbp.release_rolling_interval' => 1]);
        $runner = new RollingReleasesRunner($this->marker, slowGroup: 2, slowSeconds: 3, drainAfterFirstFullPass: true);

        $this->runRound($runner);

        $this->assertSame([1 => 1, 2 => 1], $this->fullPasses());
        $this->assertSame('success', $runner->outcomes()[2]['status']);
    }

    public function test_a_failed_pass_is_not_repeated_and_still_fails_the_round(): void
    {
        config(['nntmux.cbp.release_rolling_interval' => 1]);
        $runner = new RollingReleasesRunner($this->marker, slowGroup: 2, slowSeconds: 3, failingGroup: 1);

        try {
            $this->runRound($runner);
            $this->fail('Expected the round to report the failed group.');
        } catch (RuntimeException) {
            $this->assertSame([1 => 1, 2 => 1], $this->fullPasses());
            $this->assertSame('failure', $runner->outcomes()[1]['status']);
        }
    }

    private function runRound(ReleasesRunner $runner): void
    {
        ob_start();
        try {
            $runner->releases();
        } finally {
            ob_end_clean();
        }
    }

    /** @return array<int, int> */
    private function fullPasses(): array
    {
        $counts = [];
        foreach ($this->events() as $event) {
            if (str_starts_with($event, 'full:')) {
                $id = (int) substr($event, 5);
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /** @return list<string> */
    private function readyPasses(): array
    {
        $ready = array_values(array_filter($this->events(), static fn (string $event): bool => str_starts_with($event, 'ready:')));
        sort($ready);

        return $ready;
    }

    /** @return list<string> */
    private function events(): array
    {
        $events = file($this->marker, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($events);

        return $events;
    }
}

class RollingReleasesRunner extends ReleasesRunner
{
    public function __construct(
        private string $marker,
        private int $slowGroup,
        private int $slowSeconds,
        private bool $drainAfterFirstFullPass = false,
        private ?int $failingGroup = null,
    ) {}

    /** @return array<string|int, array{status: string, exit_code: ?int}> */
    public function outcomes(): array
    {
        return $this->workerOutcomes;
    }

    protected function buildDnrCommand(string $args): array
    {
        $parts = explode('  ', $args);
        $id = (int) ($parts[1] ?? throw new RuntimeException('Expected a group worker command.'));
        $sleep = $id === $this->slowGroup ? $this->slowSeconds : 0;
        $exit = $id === $this->failingGroup ? 1 : 0;

        return [PHP_BINARY, '-r',
            '$ready = in_array("--ready-only", $argv, true);'
            .'file_put_contents($argv[1], ($ready ? "ready:" : "full:").$argv[2].PHP_EOL, FILE_APPEND | LOCK_EX);'
            .'if (! $ready) { sleep((int) $argv[3]); exit((int) $argv[4]); }',
            $this->marker, (string) $id, (string) $sleep, (string) $exit];
    }

    protected function workerCompleted(string|int $key, string $output): void
    {
        if ($this->drainAfterFirstFullPass && (int) $key !== $this->slowGroup && str_contains((string) file_get_contents($this->marker), 'full:')) {
            app(ProcessingRuntimeStateRepository::class)->requestStop();
        }
    }
}
