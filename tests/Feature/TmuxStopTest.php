<?php

namespace Tests\Feature;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Runners\BaseRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class TmuxStopTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => true, 'stop_requested' => false]);
    }

    public function test_stopping_tmux_allows_a_standalone_runner_to_start_real_work(): void
    {
        $this->fakeSession();
        $this->artisan('tmux:stop --session=processing --force --timeout=0')->assertExitCode(0);
        $runner = new class extends BaseRunner
        {
            public function run(): string
            {
                return $this->executeCommand([PHP_BINARY, '-r', 'echo "standalone executed";']);
            }
        };

        $this->assertSame('standalone executed', $runner->run());
        $this->assertRuntime(false, false);
    }

    public function test_stopping_tmux_allows_standalone_service_processing_to_scan_its_next_chunk(): void
    {
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->unsignedBigInteger('last_record');
            $table->dateTime('last_record_postdate')->nullable();
            $table->dateTime('last_updated')->nullable();
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'last_record' => 1000]);
        $this->fakeSession();
        $this->artisan('tmux:stop --session=processing --force --timeout=0')->assertExitCode(0);
        $service = new class(new BinariesConfig(messageBuffer: 3, echoCli: false)) extends BinariesService
        {
            /** @var list<array{int, int}> */
            public array $ranges = [];

            public function scan(array $groupMySQL, int $first, int $last, string $type = 'update', ?array $missingParts = null): array
            {
                $this->ranges[] = [$first, $last];

                return ['firstArticleNumber' => $first, 'lastArticleNumber' => $last,
                    'firstArticleDate' => '2026-10-14 00:00:00', 'lastArticleDate' => '2026-10-14 00:00:00'];
            }
        };
        $group = ['id' => 1, 'name' => 'alt.test', 'first_record' => 1, 'first_record_postdate' => '2026-10-01 00:00:00'];
        (new ReflectionMethod(BinariesService::class, 'processArticleRange'))->invokeArgs(
            $service, [&$group, [], ['first' => 1000, 'last' => 1003, 'groupLast' => 1003, 'total' => 3]],
        );

        $this->assertSame([[1001, 1003]], $service->ranges);
        $this->assertSame(1003, (int) DB::table('usenet_groups')->value('last_record'));
    }

    public function test_failed_session_kill_preserves_the_stop_request(): void
    {
        $this->fakeSession(killSucceeds: false);
        $this->artisan('tmux:stop --session=processing --force --timeout=0')->assertExitCode(1);
        $this->assertRuntime(false, true);
    }

    public function test_already_absent_session_clears_a_stale_stop_request(): void
    {
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
        $this->fakeSession(exists: false);
        $this->artisan('tmux:stop --session=processing --force')->assertExitCode(0);
        $this->assertRuntime(false, false);
    }

    public function test_uncertain_session_probe_preserves_the_stop_request(): void
    {
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => false, 'stop_requested' => true]);
        Process::fake(fn (): mixed => Process::result(errorOutput: 'permission denied', exitCode: 1));
        $this->artisan('tmux:stop --session=processing --force')->assertExitCode(1);
        $this->assertRuntime(false, true);
    }

    #[DataProvider('absentServerMessages')]
    public function test_confirmed_absent_server_clears_a_stale_stop_request(string $error): void
    {
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => true]);
        Process::fake(fn (): mixed => Process::result(errorOutput: $error, exitCode: 1));

        $this->artisan('tmux:stop --session=processing --force')->assertExitCode(0);
        $this->assertRuntime(false, false);
    }

    /** @return iterable<string, array{string}> */
    public static function absentServerMessages(): iterable
    {
        yield 'exited server' => ['no server running on /tmp/tmux-0/default'];
        yield 'missing socket' => ['error connecting to /tmp/tmux-0/default (No such file or directory)'];
    }

    private function assertRuntime(bool $running, bool $stopped): void
    {
        $this->assertSame((int) $running, (int) DB::table('processing_runtime_states')->value('tmux_running'));
        $this->assertSame((int) $stopped, (int) DB::table('processing_runtime_states')->value('stop_requested'));
    }

    private function fakeSession(bool $exists = true, bool $killSucceeds = true): void
    {
        Process::fake(function (PendingProcess $process) use (&$exists, $killSucceeds) {
            if (in_array('has-session', $process->command, true)) {
                return Process::result(errorOutput: $exists ? '' : "can't find session: processing", exitCode: $exists ? 0 : 1);
            }
            if (in_array('#{session_id}', $process->command, true)) {
                return Process::result('$1');
            }
            if (in_array('list-panes', $process->command, true)) {
                return Process::result("%1\tmonitor\t1\t0\t456\t0\t@1\t\t999\n");
            }
            if (in_array('kill-session', $process->command, true)) {
                $this->assertRuntime(false, true);
                $exists = ! $killSucceeds;

                return Process::result(exitCode: $killSucceeds ? 0 : 1);
            }
            if (in_array('list-sessions', $process->command, true)) {
                return Process::result($exists ? "processing\n" : "unrelated\n");
            }

            return Process::result();
        });
    }
}
