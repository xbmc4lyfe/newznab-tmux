<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\UpdateNNTmux;
use App\Services\NntmuxUpdateLock;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\ConfigurationTestBuilder;
use Tests\TestCase;

class UpdateNNTmuxTest extends TestCase
{
    private string $directory;

    private string $originalBasePath;

    private string $originalStoragePath;

    private bool $maintenanceActive = false;

    private int $tmuxStartExitCode = 0;

    private int $redisPreflightExitCode = 0;

    private string $redisPreflightError = '';

    private bool $expectFreshRedisPreflight = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.redis.client' => 'phpredis']);
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => true]);
        $this->directory = sys_get_temp_dir().'/nntmux-all-update-'.bin2hex(random_bytes(8));
        $this->originalBasePath = $this->app->basePath();
        $this->originalStoragePath = $this->app->storagePath();
        $this->app->setBasePath($this->directory);
        $this->app->useStoragePath($this->directory.'/storage');
        File::ensureDirectoryExists($this->directory.'/.git');
        $this->mock(MaintenanceMode::class)->shouldReceive('active')->andReturnUsing(fn (): bool => $this->maintenanceActive);
        Process::preventStrayProcesses();
        Process::fake(fn (PendingProcess $process) => match (true) {
            $this->isFreshTmuxStart($process) => Process::result(exitCode: $this->tmuxStartExitCode),
            $this->isFreshRedisPreflight($process) => Process::result(errorOutput: $this->redisPreflightError, exitCode: $this->redisPreflightExitCode),
            default => Process::result($this->directory.'/.git/index.lock'),
        });
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);
        $this->app->useStoragePath($this->originalStoragePath);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_git_lock_aborts_before_maintenance_or_tmux_shutdown_and_leaves_lock_intact(): void
    {
        File::put($this->directory.'/.git/index.lock', 'another Git process');
        $command = $this->command();
        $command->shouldReceive('call')->never();

        [$status, $output] = $this->runCommand($command, ['--git-lock-timeout' => 0]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Git index is locked', $output);
        $this->assertStringNotContainsString('Preparing environment', $output);
        $this->assertFalse($this->maintenanceActive);
        $this->assertSame('another Git process', File::get($this->directory.'/.git/index.lock'));
    }

    public function test_concurrent_updates_are_rejected_even_when_git_is_skipped(): void
    {
        $lock = new NntmuxUpdateLock;
        $lock->run(function (): int {
            $command = $this->command();
            $command->shouldReceive('call')->never();

            [$status, $output] = $this->runCommand($command, ['--skip-git' => true]);

            $this->assertSame(1, $status);
            $this->assertStringContainsString('Another NNTmux update is already running', $output);
            $this->assertFalse($this->maintenanceActive);

            return 0;
        }, checkGit: false);
    }

    public function test_repository_preflight_failure_leaves_environment_untouched(): void
    {
        Process::fake(fn () => Process::result('', 'not a git repository', 128));
        $command = $this->command();
        $command->shouldReceive('call')->never();

        [$status, $output] = $this->runCommand($command);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('not a git repository', $output);
        $this->assertFalse($this->maintenanceActive);
    }

    public function test_failed_update_restores_web_access_but_does_not_restart_tmux_or_report_completion(): void
    {
        $command = $this->command();
        $this->expectPreparation($command);
        $command->shouldReceive('call')->once()->with('nntmux:git', ['--git-lock-timeout' => 0])->andReturn(1);
        $this->expectWebRestore($command);
        $command->shouldReceive('call')->with('tmux:start')->never();

        [$status, $output] = $this->runCommand($command, ['--git-lock-timeout' => 0]);

        $this->assertSame(1, $status);
        $this->assertFalse($this->maintenanceActive);
        $this->assertStringContainsString('Skipping tmux restart', $output);
        $this->assertStringNotContainsString('100%', $output);
        $this->assertStringNotContainsString('update completed successfully', $output);
        $this->assertSame(0, (new NntmuxUpdateLock)->run(fn (): int => 0, checkGit: false));
    }

    public function test_successful_update_restores_tmux_and_uses_a_random_maintenance_secret(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $command = $this->command();
        $this->expectPreparation($command);
        $command->shouldReceive('call')->once()->with('nntmux:git', ['--git-lock-timeout' => 0])->andReturn(0);
        $this->expectMaintenanceTasks($command);
        $this->expectHealthyRedis();
        $this->expectWebRestore($command);
        $command->shouldReceive('call')->with('tmux:start')->never();

        [$status, $output] = $this->runCommand($command, ['--git-lock-timeout' => 0]);

        $this->assertSame(0, $status);
        Process::assertRanTimes(fn (PendingProcess $process): bool => $this->isFreshTmuxStart($process), 1);
        $this->assertFalse($this->maintenanceActive);
        $this->assertStringContainsString('update completed successfully', $output);
        $this->assertStringNotContainsString(config('app.key'), $output);
    }

    public function test_maintenance_merges_missing_environment_keys_before_caching_configuration(): void
    {
        File::put($this->directory.'/.env', "APP_NAME=ExistingName\n");
        File::put($this->directory.'/.env.example', "APP_NAME=TemplateName\nCACHE_STORE=failover_redis_file\n");
        $command = $this->command();
        $output = new OutputStyle(new ArrayInput([]), new BufferedOutput);
        $command->setOutput($output);
        (new \ReflectionProperty(UpdateNNTmux::class, 'progressBar'))->setValue($command, new ProgressBar($output, 1));
        foreach (['config:clear', 'view:clear', 'route:clear', 'route:cache'] as $name) {
            $command->shouldReceive('call')->once()->with($name)->andReturn(0);
        }
        $command->shouldReceive('call')->once()->with('config:cache')->andReturnUsing(function (): int {
            $environment = File::get($this->directory.'/.env');
            $this->assertStringContainsString("CACHE_STORE=failover_redis_file\n", $environment);
            $this->assertStringContainsString("APP_NAME=ExistingName\n", $environment);
            $this->assertStringNotContainsString('APP_NAME=TemplateName', $environment);

            return 0;
        });

        (new \ReflectionMethod(UpdateNNTmux::class, 'performMaintenanceTasks'))->invoke($command);
    }

    #[DataProvider('updatedRedisEndpoints')]
    public function test_redis_preflight_uses_worker_configuration_after_real_cache_commands(string $newHost, bool $healthy): void
    {
        File::ensureDirectoryExists($this->directory.'/bootstrap/cache');
        File::ensureDirectoryExists($this->directory.'/config');
        File::ensureDirectoryExists($this->directory.'/storage/framework/views');
        File::put($this->directory.'/.env', "REDIS_HOST={$newHost}\n");
        File::put($this->directory.'/.env.example', "REDIS_HOST={$newHost}\n");
        File::put($this->directory.'/config/database.php', <<<'PHP'
<?php
return ['redis' => ['client' => 'phpredis', 'default' => ['host' => env('REDIS_HOST')]]];
PHP);
        // The extension and endpoints are simulated only inside this isolated fixture process.
        File::put($this->directory.'/redis-fixture.php', <<<'PHP'
<?php
namespace App\Console\Commands {
    function extension_loaded(string $name): bool
    {
        return $name === 'redis' || \extension_loaded($name);
    }
}
namespace UpdaterFixture {
    class RedisProvider extends \Illuminate\Support\ServiceProvider
    {
        public function register(): void
        {
            $this->app->extend('redis', static fn ($manager, $app) => new class($app) {
                public function __construct(private $app) {}
                public function connection(): self { return $this; }
                public function ping(): string
                {
                    $host = $this->app['config']->get('database.redis.default.host');
                    file_put_contents($this->app->basePath('probes.jsonl'), json_encode(['host' => $host, 'pid' => getmypid(), 'transport' => 'isolated-fixture-provider'])."\n", FILE_APPEND);
                    if (! str_starts_with($host, 'healthy-')) {
                        throw new \RuntimeException('fixture endpoint unreachable');
                    }
                    return 'PONG';
                }
            });
        }
    }
}
PHP);
        $autoload = var_export($this->originalBasePath.'/vendor/autoload.php', true);
        File::put($this->directory.'/bootstrap/app.php', str_replace('__AUTOLOAD__', $autoload, <<<'PHP'
<?php
require_once __AUTOLOAD__;
require_once dirname(__DIR__).'/redis-fixture.php';
return \Illuminate\Foundation\Application::configure(basePath: dirname(__DIR__))
    ->withProviders([\UpdaterFixture\RedisProvider::class], withBootstrapProviders: false)
    ->withCommands([\App\Console\Commands\UpdateNNTmux::class])
    ->withExceptions()
    ->withRouting(using: static function (): void {
        \Illuminate\Support\Facades\Route::get('/fixture', [\Illuminate\Routing\RedirectController::class, '__invoke']);
    })->create();
PHP));
        File::put($this->directory.'/artisan', <<<'PHP'
<?php
$app = require __DIR__.'/bootstrap/app.php';
exit($app->handleCommand(new \Symfony\Component\Console\Input\ArgvInput));
PHP);
        File::put($this->directory.'/scenario.php', <<<'PHP'
<?php
try {
$app = require __DIR__.'/bootstrap/app.php';
$providers = [...\Illuminate\Support\ServiceProvider::defaultProviders()->toArray(), \UpdaterFixture\RedisProvider::class];
$old = [
    'app' => ['name' => 'UpdaterFixture', 'env' => 'testing', 'timezone' => 'UTC', 'providers' => $providers, 'debug' => false, 'locale' => 'en', 'fallback_locale' => 'en'],
    'database' => ['redis' => ['client' => 'phpredis', 'default' => ['host' => 'healthy-old']]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'view' => ['compiled' => __DIR__.'/storage/framework/views', 'paths' => []],
];
file_put_contents($app->getCachedConfigPath(), '<?php return '.var_export($old, true).';');
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$oldPong = \Illuminate\Support\Facades\Redis::connection()->ping();
$command = \Mockery::mock(\App\Console\Commands\UpdateNNTmux::class)->makePartial();
$command->__construct();
$command->setLaravel($app);
$buffer = new \Symfony\Component\Console\Output\BufferedOutput;
$output = new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), $buffer);
$command->setOutput($output);
(new \ReflectionProperty(\App\Console\Commands\UpdateNNTmux::class, 'progressBar'))->setValue($command, new \Symfony\Component\Console\Helper\ProgressBar($output, 1));
$command->shouldReceive('call')->andReturnUsing(static fn (string $name, array $arguments = []): int => $kernel->call($name, $arguments));
(new \ReflectionMethod(\App\Console\Commands\UpdateNNTmux::class, 'performMaintenanceTasks'))->invoke($command);
$cached = require $app->getCachedConfigPath();
$restoredHost = config('database.redis.default.host');
$accepted = false;
try {
    (new \ReflectionMethod(\App\Console\Commands\UpdateNNTmux::class, 'ensureRedisRuntimeIsHealthy'))->invoke($command);
    $accepted = true;
} catch (\Throwable $error) {
    $message = $error->getMessage();
}
$probes = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file(__DIR__.'/probes.jsonl', FILE_IGNORE_NEW_LINES));
file_put_contents(__DIR__.'/result.json', json_encode(['old_pong' => $oldPong, 'restored_host' => $restoredHost, 'cached_host' => $cached['database']['redis']['default']['host'], 'accepted' => $accepted, 'probes' => $probes, 'output' => $buffer->fetch(), 'error' => $message ?? null], JSON_THROW_ON_ERROR));
} catch (\Throwable $error) {
    fwrite(STDERR, (string) $error);
    exit(1);
}
PHP);
        // Use the worker's normal extension INI: Linux DOM may be a dynamic module.
        // The provider replaces every Redis connection before either process can ping.
        $process = new SymfonyProcess([PHP_BINARY, $this->directory.'/scenario.php'], $this->directory, [
            'REDIS_HOST' => false,
            'REDIS_CLIENT' => false,
            'APP_CONFIG_CACHE' => false,
            'APP_ROUTES_CACHE' => false,
        ]);
        $process->setTimeout(15);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $resultPath = $this->directory.'/result.json';
        $this->assertFileExists($resultPath, $process->getOutput().$process->getErrorOutput());
        $observed = json_decode(File::get($resultPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PONG', $observed['old_pong']);
        $this->assertSame('healthy-old', $observed['restored_host']);
        $this->assertSame($newHost, $observed['cached_host']);
        $this->assertSame($healthy, $observed['accepted'], $observed['output']);
        $this->assertCount(2, $observed['probes']);
        $this->assertSame(['isolated-fixture-provider', 'isolated-fixture-provider'], array_column($observed['probes'], 'transport'));
        $this->assertSame($newHost, $observed['probes'][array_key_last($observed['probes'])]['host']);
        $this->assertNotSame($observed['probes'][0]['pid'], $observed['probes'][array_key_last($observed['probes'])]['pid']);
        if (! $healthy) {
            $this->assertStringContainsString('fixture endpoint unreachable', $observed['output']);
        }
    }

    public static function updatedRedisEndpoints(): array
    {
        return [
            'changed endpoint is unreachable' => ['unreachable-new', false],
            'changed endpoint is healthy' => ['healthy-new', true],
        ];
    }

    public function test_internal_redis_preflight_does_not_prepare_or_acquire_the_update_lock(): void
    {
        config(['database.redis.client' => 'predis']);
        $command = $this->command();
        $command->shouldReceive('call')->never();
        $status = (new NntmuxUpdateLock)->run(function () use ($command): int {
            [$status, $output] = $this->runCommand($command, ['--redis-preflight' => true]);
            $this->assertStringContainsString('Redis client mismatch', $output);

            return $status;
        }, checkGit: false);

        $this->assertSame(1, $status);
        $this->assertFalse($this->maintenanceActive);
        Process::assertNothingRan();
    }

    #[DataProvider('failedProbeStates')]
    public function test_fresh_redis_probe_failure_preserves_worker_pause_and_restores_web_access(bool $wasRunning, bool $timeout): void
    {
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => $wasRunning]);
        $command = $this->command();
        $this->expectPreparation($command);
        $this->expectMaintenanceTasks($command);
        $this->expectWebRestore($command);
        $this->expectFreshRedisPreflight = ! $timeout;
        $probeAttempted = false;
        $this->redisPreflightExitCode = 1;
        $this->redisPreflightError = 'fixture fresh Redis probe failed';
        if ($timeout) {
            Process::fake(function (PendingProcess $process) use (&$probeAttempted) {
                if ($this->isFreshRedisPreflight($process)) {
                    $probeAttempted = true;
                    $this->assertSame(30, $process->timeout);
                    $original = new \Symfony\Component\Process\Exception\ProcessTimedOutException(
                        (new SymfonyProcess([PHP_BINARY, 'artisan', 'nntmux:all', '--redis-preflight']))->setTimeout(30),
                        \Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL,
                    );
                    throw new ProcessTimedOutException($original, Process::result(exitCode: 1));
                }

                return Process::result();
            });
        }

        [$status, $output] = $this->runCommand($command, ['--skip-git' => true]);

        $this->assertSame(1, $status);
        $this->assertFalse($this->maintenanceActive);
        $this->assertStringNotContainsString('update completed successfully', $output);
        if ($timeout) {
            $this->assertTrue($probeAttempted);
        }
        Process::assertNotRan(fn (PendingProcess $process): bool => $this->isFreshTmuxStart($process));
        $this->assertSame(0, (new NntmuxUpdateLock)->run(fn (): int => 0, checkGit: false));
    }

    public static function failedProbeStates(): array
    {
        return [
            'running, nonzero' => [true, false],
            'paused, nonzero' => [false, false],
            'running, timeout' => [true, true],
            'paused, timeout' => [false, true],
        ];
    }

    public function test_failed_tmux_shutdown_aborts_before_git_and_restores_web_access(): void
    {
        $command = $this->command();
        $this->expectPreparation($command, stopExitCode: 1);
        $command->shouldReceive('call')->with('nntmux:git', Mockery::any())->never();
        $command->shouldReceive('call')->with('nntmux:composer')->never();
        $this->expectWebRestore($command);

        [$status, $output] = $this->runCommand($command);

        $this->assertSame(1, $status);
        $this->assertFalse($this->maintenanceActive);
        $this->assertStringContainsString('Failed to stop tmux before updating', $output);
        Process::assertNotRan(fn (PendingProcess $process): bool => $this->isFreshTmuxStart($process));
        $this->assertSame(0, (new NntmuxUpdateLock)->run(fn (): int => 0, checkGit: false));
    }

    public function test_paused_tmux_is_drained_before_git_without_restarting_it(): void
    {
        ConfigurationTestBuilder::updateRuntime(['tmux_running' => false]);
        $command = $this->command();
        $this->expectPreparation($command);
        $command->shouldReceive('call')->once()->with('nntmux:git', ['--git-lock-timeout' => 0])->andReturn(0);
        $this->expectMaintenanceTasks($command);
        $this->expectHealthyRedis();
        $this->expectWebRestore($command);

        [$status] = $this->runCommand($command, ['--git-lock-timeout' => 0]);

        $this->assertSame(0, $status);
        Process::assertNotRan(fn (PendingProcess $process): bool => $this->isFreshTmuxStart($process));
    }

    public function test_pre_existing_maintenance_mode_is_preserved_after_failure(): void
    {
        $this->maintenanceActive = true;
        $command = $this->command();
        $command->shouldReceive('call')->with('down', Mockery::any())->never();
        $command->shouldReceive('call')->once()->with('tmux:stop', ['--force' => true, '--wait' => true])->andReturn(0);
        $command->shouldReceive('call')->once()->with('nntmux:git', ['--git-lock-timeout' => 0])->andReturn(1);
        $command->shouldReceive('call')->with('up')->never();
        $command->shouldReceive('call')->with('tmux:start')->never();

        [$status] = $this->runCommand($command, ['--git-lock-timeout' => 0]);

        $this->assertSame(1, $status);
        $this->assertTrue($this->maintenanceActive);
    }

    public function test_redis_preflight_failure_restores_web_access_without_restarting_tmux(): void
    {
        config(['database.redis.client' => 'predis']);
        $this->redisPreflightExitCode = 1;
        $this->redisPreflightError = 'Redis client mismatch';
        $this->expectFreshRedisPreflight = true;
        $command = $this->command();
        $this->expectPreparation($command);
        $this->expectMaintenanceTasks($command);
        $this->expectWebRestore($command);
        $command->shouldReceive('call')->with('tmux:start')->never();
        Redis::shouldReceive('connection')->never();

        [$status, $output] = $this->runCommand($command, ['--skip-git' => true]);

        $this->assertSame(1, $status);
        $this->assertFalse($this->maintenanceActive);
        $this->assertStringContainsString('Redis client mismatch', $output);
        $this->assertStringContainsString('Skipping tmux restart', $output);
        $this->assertStringNotContainsString('update completed successfully', $output);
        $this->assertSame(0, (new NntmuxUpdateLock)->run(fn (): int => 0, checkGit: false));
    }

    public function test_tmux_restart_failure_fails_the_update_and_releases_the_lock(): void
    {
        $command = $this->command();
        $this->expectPreparation($command);
        $this->expectMaintenanceTasks($command);
        $this->expectHealthyRedis();
        $this->expectWebRestore($command);
        $this->tmuxStartExitCode = 1;

        [$status, $output] = $this->runCommand($command, ['--skip-git' => true]);

        $this->assertSame(1, $status);
        $this->assertFalse($this->maintenanceActive);
        $this->assertStringContainsString('tmux:start failed while restoring environment', $output);
        $this->assertStringNotContainsString('update completed successfully', $output);
        $this->assertSame(0, (new NntmuxUpdateLock)->run(fn (): int => 0, checkGit: false));
    }

    public function test_skip_git_does_not_require_a_repository_or_check_the_git_index_lock(): void
    {
        File::deleteDirectory($this->directory.'/.git');
        $command = $this->command();
        $this->expectPreparation($command);
        $command->shouldReceive('call')->with('nntmux:git', Mockery::any())->never();
        $this->expectMaintenanceTasks($command);
        $this->expectHealthyRedis();
        $this->expectWebRestore($command);

        [$status] = $this->runCommand($command, ['--skip-git' => true]);

        $this->assertSame(0, $status);
        Process::assertNotRan(fn (PendingProcess $process): bool => ! $this->isFreshTmuxStart($process)
            && ! $this->isFreshRedisPreflight($process));
    }

    public function test_npm_ci_failure_reports_diagnostics_and_completes_using_install_fallback(): void
    {
        $this->fakeBuildFailure('npm ci --silent', 'dependency conflict', 'lockfile mismatch', 1);
        $command = $this->command();
        $this->expectPreparation($command);
        $this->expectMaintenanceTasks($command);
        $this->expectHealthyRedis();
        $this->expectWebRestore($command);

        [$status, $output] = $this->runCommand($command, ['--skip-git' => true, '--skip-npm' => false]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('npm ci failed, falling back to npm install', $output);
        $this->assertStringContainsString('stderr=lockfile mismatch', $output);
        $this->assertStringContainsString('stdout=dependency conflict', $output);
        foreach (['npm install --silent', 'php artisan typescript:transform --force', 'npm exec vite build'] as $buildCommand) {
            Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === $buildCommand && $process->path === $this->directory && $process->timeout === 600, 1);
        }
        Process::assertNotRan('npm run build');
    }

    #[DataProvider('buildFailures')]
    /** @param list<string> $skippedCommands */
    public function test_build_failure_restores_web_access_and_skips_later_steps(string $failedCommand, string $stdout, string $stderr, string $expected, array $skippedCommands): void
    {
        $this->fakeBuildFailure($failedCommand, $stdout, $stderr, 2);
        $command = $this->command();
        $this->expectPreparation($command);
        $this->expectWebRestore($command);
        $command->shouldReceive('call')->with('config:clear')->never();
        Cache::shouldReceive('flush')->never();
        Redis::shouldReceive('connection')->never();

        [$status, $output] = $this->runCommand($command, ['--skip-git' => true, '--skip-npm' => false]);

        $this->assertSame(1, $status);
        $this->assertFalse($this->maintenanceActive);
        $this->assertStringContainsString($expected, $output);
        $this->assertStringContainsString('exit_code=2', $output);
        $this->assertStringNotContainsString('update completed successfully', $output);
        foreach ($skippedCommands as $skippedCommand) {
            Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === $skippedCommand);
        }
        Process::assertNotRan(fn (PendingProcess $process): bool => $this->isFreshTmuxStart($process));
        $this->assertSame(0, (new NntmuxUpdateLock)->run(fn (): int => 0, checkGit: false));
    }

    /** @return iterable<string, array{string, string, string, string, list<string>}> */
    public static function buildFailures(): iterable
    {
        yield 'install fallback fails' => ['npm install --silent', '', 'dependency resolution failed', 'npm install failed', ['php artisan typescript:transform --force', 'npm exec vite build']];
        yield 'type transform fails' => ['php artisan typescript:transform --force', 'transform error', '', 'stdout=transform error', ['npm exec vite build']];
        yield 'vite fails silently' => ['npm exec vite build', '', '', 'no stdout/stderr captured', []];
    }

    private function fakeBuildFailure(string $failedCommand, string $stdout, string $stderr, int $exitCode): void
    {
        Process::fake(function (PendingProcess $process) use ($failedCommand, $stdout, $stderr, $exitCode) {
            if ($process->command === $failedCommand || ($failedCommand === 'npm install --silent' && $process->command === 'npm ci --silent')) {
                return Process::result($stdout, $stderr, $exitCode);
            }

            return Process::result();
        });
    }

    public function test_redis_connection_failure_restores_web_access_without_restarting_tmux(): void
    {
        $command = $this->command();
        $this->expectPreparation($command);
        $this->expectMaintenanceTasks($command);
        $this->expectWebRestore($command);
        $this->redisPreflightExitCode = 1;
        $this->redisPreflightError = 'Redis preflight failed: connection refused';
        $this->expectFreshRedisPreflight = true;

        [$status, $output] = $this->runCommand($command, ['--skip-git' => true]);

        $this->assertSame(1, $status);
        $this->assertFalse($this->maintenanceActive);
        $this->assertStringContainsString('Redis preflight failed: connection refused', $output);
        Process::assertNotRan(fn (PendingProcess $process): bool => $this->isFreshTmuxStart($process));
        $this->assertSame(0, (new NntmuxUpdateLock)->run(fn (): int => 0, checkGit: false));
    }

    private function isFreshRedisPreflight(PendingProcess $process): bool
    {
        return $process->command === [PHP_BINARY, 'artisan', 'nntmux:all', '--redis-preflight'];
    }

    private function isFreshTmuxStart(PendingProcess $process): bool
    {
        return $process->command === [PHP_BINARY, 'artisan', 'tmux:start'];
    }

    /** @return UpdateNNTmux&MockInterface */
    private function command(): UpdateNNTmux
    {
        $command = Mockery::mock(UpdateNNTmux::class)->makePartial();
        $command->__construct();
        $command->setLaravel($this->app);

        return $command;
    }

    /** @param UpdateNNTmux&MockInterface $command */
    private function expectPreparation(UpdateNNTmux $command, int $stopExitCode = 0): void
    {
        $command->shouldReceive('call')->once()->with('down', Mockery::on(function (array $options): bool {
            $this->assertNotSame(config('app.key'), $options['--secret']);
            $this->assertSame(40, strlen($options['--secret']));

            return true;
        }))->andReturnUsing(function (): int {
            $this->maintenanceActive = true;

            return 0;
        });
        $command->shouldReceive('call')->once()->with('tmux:stop', ['--force' => true, '--wait' => true])->andReturn($stopExitCode);
    }

    /** @param UpdateNNTmux&MockInterface $command */
    private function expectWebRestore(UpdateNNTmux $command): void
    {
        $command->shouldReceive('call')->once()->with('up')->andReturnUsing(function (): int {
            $this->maintenanceActive = false;

            return 0;
        });
    }

    /** @param UpdateNNTmux&MockInterface $command */
    private function expectMaintenanceTasks(UpdateNNTmux $command): void
    {
        foreach (['config:clear', 'config:cache', 'view:clear', 'route:clear', 'route:cache'] as $name) {
            $command->shouldReceive('call')->once()->with($name)->andReturn(0);
        }
        Cache::shouldReceive('flush')->once()->andReturn(true);
    }

    private function expectHealthyRedis(): void
    {
        $this->redisPreflightExitCode = 0;
        $this->redisPreflightError = '';
        $this->expectFreshRedisPreflight = true;
    }

    /**
     * @param  UpdateNNTmux&MockInterface  $command
     * @param  array<string, mixed>  $options
     * @return array{int, string}
     */
    private function runCommand(UpdateNNTmux $command, array $options = []): array
    {
        $output = new BufferedOutput;
        $status = $command->run(new ArrayInput([
            '--skip-composer' => true,
            '--skip-npm' => true,
            '--skip-db' => true,
            ...$options,
        ]), $output);
        if ($this->expectFreshRedisPreflight) {
            Process::assertRanTimes(fn (PendingProcess $process): bool => $this->isFreshRedisPreflight($process), 1);
        }

        return [$status, $output->fetch()];
    }
}
