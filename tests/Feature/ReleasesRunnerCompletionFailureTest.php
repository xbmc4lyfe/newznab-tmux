<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConfigurationDomain;
use App\Services\Runners\ReleasesRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class ReleasesRunnerCompletionFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'nntmux.echocli' => false,
            'nntmux.stream_fork_output' => false,
            'nntmux.concurrency_timeout' => 3,
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
        foreach ([1, 2, 3] as $id) {
            DB::table('usenet_groups')->insert(['id' => $id, 'name' => 'alt.test.'.$id, 'active' => 1, 'backfill' => 0]);
            DB::table('collections')->insert(['groups_id' => $id]);
        }
        ConfigurationTestBuilder::update(ConfigurationDomain::Ingestion, ['release_threads' => 1]);
        ConfigurationTestBuilder::updateRuntime(['stop_requested' => false]);
    }

    /** @return array<string, array{string, string}> */
    public static function completionModes(): array
    {
        return [
            'releases callback' => ['releases', 'onComplete'],
            'releases hook' => ['releases', 'workerCompleted'],
            'update per group callback' => ['updatePerGroup', 'onComplete'],
            'update per group hook' => ['updatePerGroup', 'workerCompleted'],
        ];
    }

    #[DataProvider('completionModes')]
    public function test_ready_completion_error_is_logged_without_silently_draining_the_main_pass(string $method, string $hook): void
    {
        $log = Log::spy();
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-ready-completion-');
        $this->assertNotFalse($marker);
        $runner = new CompletionFailureReleasesRunner($marker, $hook);
        ob_start();
        try {
            $runner->{$method}();
            $events = file($marker, FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($events);
            $this->assertSame(['ready:1', 'full:1', 'full:2', 'full:3'], $events);
            $this->assertSame('success', $runner->readyOutcomes[1]['status']);
            $this->assertSame('failure', $runner->readyOutcomes[2]['status']);
            $this->assertSame('failure', $runner->readyOutcomes[3]['status']);
            $log->shouldHaveReceived('error')->with(\Mockery::on(
                static fn (string $message): bool => str_starts_with($message, 'Ready release pass failed: ')
                    && str_contains($message, 'Ready completion sentinel.'),
            ))->once();
        } finally {
            ob_end_clean();
            unlink($marker);
        }
    }

    public function test_genuine_ready_cancellation_still_prevents_the_main_pass(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }
        $marker = tempnam(sys_get_temp_dir(), 'nntmux-ready-cancellation-');
        $this->assertNotFalse($marker);
        $runner = new CompletionFailureReleasesRunner($marker, 'cancellation');
        ob_start();
        try {
            $failure = null;
            try {
                $runner->releases();
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }
            $this->assertNotNull($failure);
            $this->assertStringContainsString('cancellation', $failure->getMessage());
            $this->assertSame(['ready:1'], file($marker, FILE_IGNORE_NEW_LINES));
            $this->assertSame('cancellation', $runner->readyOutcomes[2]['status']);
            $this->assertSame('cancellation', $runner->readyOutcomes[3]['status']);
        } finally {
            ob_end_clean();
            unlink($marker);
        }
    }
}

class CompletionFailureReleasesRunner extends ReleasesRunner
{
    /** @var array<string|int, array{status: string, exit_code: ?int}> */
    public array $readyOutcomes = [];

    private bool $ready = false;

    private bool $failed = false;

    public function __construct(private string $marker, private string $hook) {}

    protected function buildDnrCommand(string $args): array
    {
        $parts = explode('  ', $args);
        $id = $parts[1] ?? throw new RuntimeException('Expected a group worker command.');

        return [PHP_BINARY, '-r',
            'file_put_contents($argv[1], (in_array("--ready-only", $argv, true) ? "ready:" : "full:").$argv[2].PHP_EOL, FILE_APPEND | LOCK_EX); echo "completed";',
            $this->marker, $id];
    }

    protected function runParallelCommandsWithCompletion(array $commands, int $maxProcesses, callable $onComplete): void
    {
        $first = reset($commands);
        $this->ready = is_array($first) && in_array('--ready-only', $first, true);
        try {
            parent::runParallelCommandsWithCompletion($commands, $maxProcesses, function (string|int $key, string $output) use ($onComplete): void {
                $onComplete($key, $output);
                if ($this->ready && ! $this->failed && $this->hook === 'onComplete') {
                    $this->failed = true;
                    throw new RuntimeException('Ready completion sentinel.');
                }
            });
        } finally {
            if ($this->ready) {
                $this->readyOutcomes = $this->workerOutcomes;
            }
        }
    }

    protected function workerCompleted(string|int $key, string $output): void
    {
        if ($this->ready && ! $this->failed && $this->hook === 'workerCompleted') {
            $this->failed = true;
            throw new RuntimeException('Ready completion sentinel.');
        }
        if ($this->ready && ! $this->failed && $this->hook === 'cancellation') {
            $this->failed = true;
            posix_kill(getmypid(), SIGTERM);
        }
    }
}
