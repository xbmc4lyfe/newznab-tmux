<?php

declare(strict_types=1);

// Disposable-container fixture: real runtime state, worker runner, tmux panes and drain/stop commands.
require (getenv('NNTMUX_TEST_APP_ROOT') ?: dirname(__DIR__, 2)).'/vendor/autoload.php';

use App\Console\Commands\TmuxDrain;
use App\Console\Commands\TmuxStop;
use App\Enums\TmuxPaneRole;
use App\Services\Runners\BaseRunner;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use App\Services\Tmux\TmuxTaskRunner;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

$app = new Application('/app');
$app->instance('config', new Repository(['tmux' => ['socket_name' => 'container-lifecycle', 'config_file' => '/dev/null']]));
$app->instance('log', new NullLogger);
$app->instance('events', new Dispatcher($app));
$app->instance('process', new Factory);
$capsule = new Manager;
$capsule->addConnection(['driver' => 'sqlite', 'database' => '/fixture/runtime.sqlite']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($app);

$command = $argv[2] ?? '';
if ($command === 'worker') {
    $runner = new class extends BaseRunner
    {
        public function batch(): void
        {
            $this->runParallelCommands([
                'active' => [PHP_BINARY, '-r', <<<'WORKER'
file_put_contents('/fixture/work', "started\n");
$database = new PDO('sqlite:/fixture/runtime.sqlite');
$deadline = microtime(true) + 15;
while (!(bool) $database->query('SELECT stop_requested FROM processing_runtime_states WHERE id=1')->fetchColumn()) {
    if (microtime(true) > $deadline) { exit(9); }
    usleep(10000);
}
file_put_contents('/fixture/draining', '1');
usleep(250000);
file_put_contents('/fixture/work', "finished\n", FILE_APPEND);
WORKER],
                'queued' => [PHP_BINARY, '-r', 'file_put_contents("/fixture/work", "queued\n", FILE_APPEND);'],
            ], 1, 20);
        }
    };
    $runner->batch();
    exit(0);
}

file_put_contents('/fixture/commands', $command."\n", FILE_APPEND);
if ($command === 'tmux:start') {
    $database = new PDO('sqlite:/fixture/runtime.sqlite');
    $database->exec('CREATE TABLE processing_runtime_states (id INTEGER PRIMARY KEY, tmux_running BOOLEAN, stop_requested BOOLEAN, created_at TEXT, updated_at TEXT)');
    $database->exec('INSERT INTO processing_runtime_states (id, tmux_running, stop_requested) VALUES (1, 1, 0)');
    $session = new TmuxSessionManager('nntmux');
    $pane = $session->createSession();
    if ($pane === null) {
        throw new RuntimeException((string) $session->lastError());
    }
    $panes = new TmuxPaneManager('nntmux');
    if (! $panes->setPaneRole($pane, TmuxPaneRole::Binaries)) {
        throw new RuntimeException((string) $panes->lastError());
    }
    $runner = new TmuxTaskRunner('nntmux');
    $batch = (new ReflectionMethod($runner, 'batchCommand'))->invoke($runner, [
        PHP_BINARY.' /fixture/artisan.php artisan worker',
        'printf "later shell command\n" >> /fixture/work',
    ]);
    if (! $panes->respawnPane($pane, ['bash', '-c', $runner->buildCommand($batch)])) {
        throw new RuntimeException((string) $panes->lastError());
    }
    exit(0);
}
if ($command === 'tmux:health-check') {
    exit((new TmuxSessionManager('nntmux'))->sessionExists() ? 0 : 1);
}
if ($command === 'tmux:drain' || $command === 'tmux:stop') {
    $artisan = $command === 'tmux:drain' ? new TmuxDrain : new TmuxStop;
    $artisan->setLaravel($app);
    $options = ['--session' => 'nntmux', '--timeout' => '6'];
    if ($command === 'tmux:stop') {
        $options['--force'] = true;
    }
    exit($artisan->run(new ArrayInput($options), new ConsoleOutput));
}
throw new RuntimeException('Unexpected fixture command: '.$command);
