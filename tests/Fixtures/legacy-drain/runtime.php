<?php

declare(strict_types=1);

use App\Enums\TmuxPaneRole;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Tmux\TmuxMonitorService;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxTaskRunner;
use Illuminate\Contracts\Console\Kernel;

$argv = $_SERVER['argv'] ?? [];
$mode = $argv[1] ?? '';
$root = getcwd();
if ($mode === 'init') {
    $db = new PDO('sqlite:/tmp/legacy.sqlite');
    $db->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY, name TEXT, value TEXT)');
    $db->exec("INSERT INTO settings (name,value) VALUES ('categorizeforeign','0'),('catwebdl','0')");
    $db->exec('CREATE TABLE processing_runtime_states (id INTEGER PRIMARY KEY, tmux_running INTEGER, stop_requested INTEGER, created_at TEXT, updated_at TEXT)');
    $db->exec('INSERT INTO processing_runtime_states VALUES (1,1,0,NULL,NULL)');
    (new PDO('sqlite:/tmp/work.sqlite'))->exec('CREATE TABLE completed (id INTEGER PRIMARY KEY)');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['tmux.socket_name' => '', 'tmux.config_file' => '/dev/null']);

if ($mode === 'worker') {
    $db = new PDO('sqlite:/tmp/work.sqlite');
    $db->beginTransaction();
    $db->exec('INSERT INTO completed VALUES (1)');
    file_put_contents('/tmp/worker-started', '1');
    usleep((int) $argv[2] * 1000000);
    $db->commit();
    exit(0);
}
if ($mode === 'monitor') {
    $monitor = new TmuxMonitorService;
    file_put_contents('/tmp/monitor-started', '1');
    while ($monitor->shouldContinue()) {
        usleep(100000);
    }
    file_put_contents('/tmp/monitor-stopping', '1');
    if (file_exists('/tmp/monitor-exit-delay')) {
        sleep((int) file_get_contents('/tmp/monitor-exit-delay'));
    }
    exit(0);
}
if ($mode === 'init') {
    if (isset($argv[4])) {
        file_put_contents('/tmp/monitor-exit-delay', $argv[4]);
        // Keep the restoration command real while replacing only the monitor's
        // unrelated statistics/bootstrap dependencies with this stop-loop fixture.
        rename('/app/artisan', '/app/artisan.original');
        file_put_contents('/app/artisan', <<<'WRAPPER'
<?php
if (($argv[1] ?? '') === 'tmux:monitor') {
    $_SERVER['argv'] = ['runtime.php', 'monitor'];
    require '/tmp/runtime.php';
} else {
    require '/app/artisan.original';
}
WRAPPER);
    }
    passthru('tmux new-session -d -s nntmux sleep 60', $status);
    if ($status !== 0) {
        exit($status);
    }
    passthru('tmux set-option -gw remain-on-exit on');
    passthru('tmux set-option -p -t nntmux:0.0 @nntmux_role monitor');
    passthru('tmux respawn-pane -k -t nntmux:0.0 php /tmp/runtime.php monitor');
    passthru('tmux split-window -d -t nntmux:0 sleep 60');
    passthru('tmux set-option -p -t nntmux:0.1 @nntmux_role binaries');
    $panes = new TmuxPaneManager('nntmux');
    $pane = $panes->paneForRole(TmuxPaneRole::Binaries);
    $runner = new TmuxTaskRunner('nntmux');
    $command = $runner->buildCommand('php /tmp/runtime.php worker '.(int) $argv[2]);
    if (! $panes->respawnPane($pane, $command, kill: true)) {
        throw new RuntimeException('Cannot start the old task wrapper.');
    }
    $irc = trim((string) shell_exec('tmux split-window -d -P -F "#{pane_id}" -t nntmux:0 sleep 300'));
    passthru('tmux set-option -p -t '.escapeshellarg($irc).' @nntmux_role '.escapeshellarg($argv[3]));
    $deadline = microtime(true) + 10;
    while (! file_exists('/tmp/worker-started') || ! file_exists('/tmp/monitor-started')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Fixture workers did not start.');
        }
        usleep(100000);
    }
}
if ($mode === 'state') {
    $runtime = app(ProcessingRuntimeStateRepository::class);
    echo json_encode([
        'running' => $runtime->isTmuxRunning(),
        'stopped' => $runtime->stopRequested(),
        'committed' => (int) (new PDO('sqlite:/tmp/work.sqlite'))->query('SELECT COUNT(*) FROM completed')->fetchColumn(),
        'panes' => (new TmuxPaneManager('nntmux'))->paneSnapshot(),
    ], JSON_THROW_ON_ERROR);
}
