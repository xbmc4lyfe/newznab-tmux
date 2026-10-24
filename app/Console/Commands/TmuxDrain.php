<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TmuxPaneRole;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Runners\BaseRunner;
use App\Services\Tmux\TmuxCommand;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class TmuxDrain extends Command
{
    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    protected $signature = 'tmux:drain
                            {--session= : Tmux session name}
                            {--timeout=90 : Maximum seconds to wait for active processing workers}
                            {--resume : Resume scheduling after a successful drain}
                            {--token= : Restore only the drain owned by this operation}';

    protected $description = 'Pause new processing work and wait for active workers to finish';

    public function handle(): int
    {
        $runtime = app(ProcessingRuntimeStateRepository::class);
        $running = false;
        $stopped = false;
        $lock = null;
        $paused = false;
        $session = null;
        $panes = null;
        $wasDraining = false;
        $monitorPane = '';
        $monitorAlive = false;
        $deadline = microtime(true) + max(0, (int) $this->option('timeout'));
        $interrupted = false;
        $handlers = [];
        $asyncSignals = false;
        if (function_exists('pcntl_async_signals')) {
            $asyncSignals = pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static function () use (&$interrupted): void {
                    $interrupted = true;
                });
            }
        }
        try {
            $name = $this->option('session') ?? app(ConfigurationProvider::class)->tmux()->sessionName;
            $lock = fopen(sys_get_temp_dir().'/nntmux-drain-'.hash('sha256', json_encode(TmuxCommand::arguments([$name]), JSON_THROW_ON_ERROR)).'.lock', 'ce');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another drain control operation is active; processing state was preserved.');
            }
            $running = $runtime->isTmuxRunning();
            $stopped = $runtime->stopRequested();
            $token = (string) ($this->option('token') ?? '');
            if ($this->option('token') !== null && ! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $token)) {
                throw new RuntimeException('The operation token must contain 1–128 letters, digits, underscores or hyphens.');
            }
            $session = new TmuxSessionManager($name);
            $exists = $session->sessionExists();
            $panes = new TmuxPaneManager($name);
            if ($this->option('resume')) {
                if ($token !== '' && (! $exists || $this->sessionOption($session, '@nntmux_draining') !== '1'
                    || $this->sessionOption($session, '@nntmux_drain_token') !== $token)) {
                    $this->info('This operation owns no active drain; processing state was preserved.');

                    return self::SUCCESS;
                }
                if (! $exists) {
                    throw new RuntimeException('Cannot resume a missing tmux session.');
                }
                $savedRunning = $this->sessionOption($session, '@nntmux_drain_was_running');
                $savedStopped = $this->sessionOption($session, '@nntmux_drain_was_stopped');
                $savedMonitorAlive = $this->sessionOption($session, '@nntmux_drain_monitor_alive');
                if (! in_array($savedRunning, ['0', '1'], true) || ! in_array($savedStopped, ['0', '1'], true)
                    || ! in_array($savedMonitorAlive, ['0', '1'], true)) {
                    throw new RuntimeException('The session has no saved pre-drain state to restore.');
                }
                $this->restoreProcessing($session, $panes, $runtime, $savedRunning === '1', $savedStopped === '1',
                    $this->sessionOption($session, '@nntmux_drain_monitor_pane'), $savedMonitorAlive === '1');
                $this->setDrainFlag($session, false);
                $this->info('Pre-drain processing state restored.');

                return self::SUCCESS;
            }

            if (! $exists) {
                throw new RuntimeException('Cannot drain a missing tmux session; processing state was preserved.');
            }
            $wasDraining = $this->sessionOption($session, '@nntmux_draining') === '1';
            foreach ($panes->paneSnapshot() as $pane => $state) {
                if ($state['role'] === TmuxPaneRole::Monitor->value) {
                    if ($monitorPane !== '') {
                        throw new RuntimeException('Multiple monitor panes found; refusing to guess the drain state.');
                    }
                    $monitorPane = $pane;
                    $monitorAlive = ! $state['dead'];
                }
            }
            if (! $wasDraining) {
                $this->setSessionOption($session, '@nntmux_drain_was_running', $running ? '1' : '0');
                $this->setSessionOption($session, '@nntmux_drain_was_stopped', $stopped ? '1' : '0');
                $this->setSessionOption($session, '@nntmux_drain_monitor_pane', $monitorPane);
                $this->setSessionOption($session, '@nntmux_drain_monitor_alive', $monitorAlive ? '1' : '0');
                $this->setSessionOption($session, '@nntmux_drain_token', $token);
            }
            if ($wasDraining && $token === '') {
                // An explicit operator pause supersedes automated restoration ownership.
                $this->setSessionOption($session, '@nntmux_drain_token', '');
            }
            $this->setDrainFlag($session, true);
            $paused = true;
            $runtime->setTmuxRunning(false);
            $runtime->requestStop();
            $diagnosticRoles = [
                TmuxPaneRole::Htop->value, TmuxPaneRole::Nmon->value, TmuxPaneRole::Vnstat->value,
                TmuxPaneRole::Tcptrack->value, TmuxPaneRole::BandwidthMonitor->value,
                TmuxPaneRole::Mytop->value, TmuxPaneRole::Redis->value,
            ];
            do {
                $panes->refresh();
                if ($interrupted) {
                    throw new RuntimeException('Drain interrupted; restoring the pre-drain processing state.');
                }
                $active = array_filter($panes->paneSnapshot(), static fn (array $state): bool => ! $state['dead']
                    && ! in_array($state['role'], $diagnosticRoles, true));
                if ($active === []) {
                    $this->info('Active processing workers finished; scheduling remains paused.');

                    return self::SUCCESS;
                }
                if (microtime(true) >= $deadline) {
                    $roles = array_unique(array_map(static fn (array $state): string => TmuxPaneRole::tryFrom($state['role'])->value ?? 'unclassified', $active));
                    throw new RuntimeException('Drain timeout reached; active workers were preserved. Blocking roles: '.implode(', ', $roles).'.');
                }
                usleep(100000);
            } while (true);
        } catch (Throwable $error) {
            if ($paused) {
                try {
                    if ($session !== null && $panes !== null && $session->sessionExists()) {
                        $this->restoreProcessing($session, $panes, $runtime, $running, $stopped, $monitorPane, $monitorAlive);
                        $this->setDrainFlag($session, $wasDraining);
                    } else {
                        $runtime->requestStop($stopped);
                        $runtime->setTmuxRunning($running);
                    }
                } catch (Throwable $restoreError) {
                    $this->error('Unable to restore scheduling: '.$restoreError->getMessage());
                }
            }
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals($asyncSignals);
            }
        }
    }

    private function restoreProcessing(TmuxSessionManager $session, TmuxPaneManager $panes, ProcessingRuntimeStateRepository $runtime, bool $running, bool $stopped, string $monitorPane, bool $monitorAlive): void
    {
        $restartMonitor = $monitorAlive && ! $stopped;
        if ($restartMonitor) {
            $deadline = microtime(true) + $this->monitorRecoveryTimeout();
            $awaitExit = $runtime->stopRequested();
            do {
                $panes->refresh();
                $state = $panes->paneSnapshot()[$monitorPane] ?? null;
                if ($state === null || $state['role'] !== TmuxPaneRole::Monitor->value) {
                    throw new RuntimeException('The original monitor pane is missing or its role changed; scheduling remains paused.');
                }
                if ($state['dead'] || ! $awaitExit) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Monitor recovery timed out; scheduling remains paused. After the monitor exits, retry tmux:drain --resume.');
                }
                usleep(100000);
            } while (true);
            $restartMonitor = $state['dead'];
        }
        $runtime->requestStop($stopped);
        $runtime->setTmuxRunning($running);
        if ($restartMonitor && ! $panes->respawnPane($monitorPane, TmuxCommand::monitor($session->sessionName()))) {
            $panes->refresh();
            if (! $panes->isAlive($monitorPane)) {
                throw new RuntimeException('Runtime flags restored, but the monitor could not restart.');
            }
        }
    }

    protected function monitorRecoveryTimeout(): int
    {
        return (self::usesRustFfi() ? $this->monitorRecoveryTimeoutNative() : null) ?? $this->monitorRecoveryTimeoutPhp();
    }

    public function monitorRecoveryTimeoutPhp(): int
    {
        return 15;
    }

    public function monitorRecoveryTimeoutNative(): ?int
    {
        $value = BaseRunner::runnerTransformNative(14, []);

        return $value !== null ? (int) $value : null;
    }

    private function setDrainFlag(TmuxSessionManager $session, bool $draining): void
    {
        $this->setSessionOption($session, '@nntmux_draining', $draining ? '1' : '0');
    }

    private function sessionOption(TmuxSessionManager $session, string $option): string
    {
        $result = Process::timeout(5)->run(TmuxCommand::arguments([
            'show-options', '-qv', '-t', $session->target(), $option,
        ]));
        if (! $result->successful()) {
            throw new RuntimeException('Unable to read the tmux session drain state.');
        }

        return trim($result->output());
    }

    private function setSessionOption(TmuxSessionManager $session, string $option, string $value): void
    {
        $result = Process::timeout(5)->run(TmuxCommand::arguments([
            'set-option', '-t', $session->target(), $option, $value,
        ]));
        if (! $result->successful()) {
            throw new RuntimeException('Unable to update the tmux session drain flag.');
        }
    }
}
