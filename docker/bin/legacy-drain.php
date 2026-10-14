<?php

declare(strict_types=1);

// Streamed into a pre-drain image: use its installed classes, without replacing
// source underneath running PHP processes. No worker receives a signal here.
use App\Enums\TmuxPaneRole;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Tmux\TmuxCommand;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;

require ($argv[1] ?? '/app').'/vendor/autoload.php';
$app = require ($argv[1] ?? '/app').'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$session = new TmuxSessionManager('nntmux');
$panes = new TmuxPaneManager('nntmux');
$runtime = app(ProcessingRuntimeStateRepository::class);
$option = static function (string $key, ?string $value = null) use ($session): string {
    $arguments = $value === null
        ? ['show-options', '-qv', '-t', $session->target(), $key]
        : ['set-option', '-t', $session->target(), $key, $value];
    $result = Process::timeout(5)->run(TmuxCommand::arguments($arguments));
    if (! $result->successful()) {
        throw new RuntimeException('Cannot access the legacy transition state.');
    }

    return trim($result->output());
};
$restore = static function (string $running, string $stopped, string $monitorAlive, bool $wasDraining = false) use ($option, $runtime, $panes): void {
    foreach ([$running, $stopped, $monitorAlive] as $value) {
        if (! in_array($value, ['0', '1'], true)) {
            throw new RuntimeException('Missing legacy pre-drain state; refusing to guess.');
        }
    }
    $panes->refresh();
    $monitor = $panes->paneForRole(TmuxPaneRole::Monitor);
    if ($monitorAlive === '1' && $stopped === '0' && $runtime->stopRequested()) {
        // The old monitor may have consumed stop_requested but not exited yet.
        // Keep the stop request asserted until that generation has finished;
        // otherwise it can die just after an apparently successful restoration.
        $deadline = microtime(true) + 15;
        while ($panes->isAlive($monitor)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Legacy monitor did not finish within 15 seconds; scheduling remains paused. Retry resume after it exits.');
            }
            usleep(100000);
            $panes->refresh();
        }
    }
    $runtime->requestStop($stopped === '1');
    $runtime->setTmuxRunning($running === '1');
    if ($monitorAlive === '1' && $stopped === '0' && ! $panes->isAlive($monitor)
        && ! $panes->respawnPane($monitor, TmuxCommand::monitor('nntmux'))) {
        throw new RuntimeException('Runtime flags restored, but the legacy monitor could not restart.');
    }
    $option('@nntmux_legacy_draining', $wasDraining ? '1' : '0');
};
$paused = false;
$interrupted = false;
if (! function_exists('pcntl_async_signals')) {
    fwrite(STDERR, "Legacy transition requires PCNTL for interrupt recovery.\n");
    exit(1);
}
pcntl_async_signals(true);
foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
    pcntl_signal($signal, static function () use (&$interrupted): void {
        $interrupted = true;
    });
}
try {
    $lock = fopen(sys_get_temp_dir().'/nntmux-drain-'.hash('sha256', json_encode(TmuxCommand::arguments(['nntmux']), JSON_THROW_ON_ERROR)).'.lock', 'ce');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another drain control operation is active; processing state was preserved.');
    }
    $token = $argv[4] ?? '';
    if (isset($argv[4]) && ! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $token)) {
        throw new RuntimeException('Invalid legacy drain operation token.');
    }
    if (! $session->sessionExists()) {
        throw new RuntimeException('Legacy transition requires an existing, role-tagged tmux session.');
    }
    if (($argv[2] ?? '') === 'resume') {
        if ($token !== '' && ($option('@nntmux_legacy_draining') !== '1' || $option('@nntmux_legacy_token') !== $token)) {
            fwrite(STDOUT, "This operation owns no active legacy drain; processing state was preserved.\n");
            exit(0);
        }
        $restore($option('@nntmux_legacy_running'), $option('@nntmux_legacy_stopped'), $option('@nntmux_legacy_monitor_alive'));
        exit(0);
    }
    if (($argv[2] ?? '') !== 'drain' || ! ctype_digit($argv[3] ?? '')) {
        throw new RuntimeException('Expected drain with a nonnegative timeout, or resume.');
    }
    $panes->refresh();
    $monitor = $panes->paneForRole(TmuxPaneRole::Monitor);
    $running = $runtime->isTmuxRunning() ? '1' : '0';
    $stopped = $runtime->stopRequested() ? '1' : '0';
    $monitorAlive = $panes->isAlive($monitor) ? '1' : '0';
    $wasDraining = $option('@nntmux_legacy_draining') === '1';
    if (! $wasDraining) {
        $option('@nntmux_legacy_running', $running);
        $option('@nntmux_legacy_stopped', $stopped);
        $option('@nntmux_legacy_monitor_alive', $monitorAlive);
        $option('@nntmux_legacy_token', $token);
    } elseif ($token === '') {
        $option('@nntmux_legacy_token', '');
    }
    $option('@nntmux_legacy_draining', '1');
    $paused = true;
    // Pause health-check recovery before requesting the old monitor's natural exit.
    // Its dead pane is the barrier for a dispatch cycle already in flight.
    $runtime->setTmuxRunning(false);
    $runtime->requestStop();
    $deadline = microtime(true) + (int) $argv[3];
    $nonBatchRoles = [
        TmuxPaneRole::IrcScraper->value, TmuxPaneRole::Htop->value,
        TmuxPaneRole::Nmon->value, TmuxPaneRole::Vnstat->value,
        TmuxPaneRole::Tcptrack->value, TmuxPaneRole::BandwidthMonitor->value,
        TmuxPaneRole::Mytop->value, TmuxPaneRole::Redis->value,
    ];
    do {
        if ($interrupted) {
            throw new RuntimeException('Legacy drain interrupted; active workers were preserved.');
        }
        $panes->refresh();
        // Unknown roles and an interactive console also block: they may own writes.
        $active = array_filter($panes->paneSnapshot(), static fn (array $state): bool => ! $state['dead']
            && ! in_array($state['role'], $nonBatchRoles, true));
        if ($active === []) {
            fwrite(STDOUT, "Legacy finite workers and monitor finished. IRC disconnects at container stop.\n");
            exit(0);
        }
        if (microtime(true) >= $deadline) {
            $roles = array_unique(array_map(static fn (array $state): string => TmuxPaneRole::tryFrom($state['role'])->value ?? 'unclassified', $active));
            throw new RuntimeException('Legacy drain timed out; active workers were preserved. Blocking roles: '.implode(', ', $roles).'.');
        }
        usleep(100000);
    } while (true);
} catch (Throwable $error) {
    if ($paused) {
        try {
            $restore($running, $stopped, $monitorAlive, $wasDraining);
        } catch (Throwable $restoreError) {
            fwrite(STDERR, $restoreError->getMessage()."\n");
        }
    }
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
