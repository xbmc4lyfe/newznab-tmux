<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Enums\TmuxPaneRole;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Service for managing individual tmux panes
 */
class TmuxPaneManager
{
    private const ROLE_OPTION = '@nntmux_role';

    /**
     * waitForExit() waits in slices this long and checks for unreaped panes in between.
     */
    private const int EXIT_WAIT_SLICE_SECONDS = 1;

    /**
     * Seconds before asking tmux again to reap a pane that still has no exit status. The SIGCHLD can
     * itself land in another libutempter window, so one request is not always enough.
     */
    private const int REAP_RETRY_SECONDS = 30;

    /**
     * How often waitForExit() checks whether its tmux waiter has been woken.
     */
    private const int EXIT_WAIT_TICK_MICROSECONDS = 50_000;

    protected string $sessionName;

    /**
     * @var array<string, string>|null
     */
    private ?array $roleTargets = null;

    private ?string $lastError = null;

    private TmuxSessionManager $sessionManager;

    /** @var array<string, array{role: string, dead: bool, exit_code: ?int, exit_signal: ?int, pid: int, pipe: bool, window: string}>|null */
    private ?array $snapshot = null;

    /**
     * Dead pane processes ("pane:pid") this manager has asked tmux to reap, with when it last asked.
     *
     * @var array<string, int>
     */
    private array $reapRequested = [];

    /**
     * The `wait-for` client shared by waitForExit() calls until tmux wakes it.
     */
    private ?InvokedProcess $exitWaiter = null;

    public function __construct(string $sessionName)
    {
        $this->sessionName = $sessionName;
        $this->sessionManager = new TmuxSessionManager($sessionName);
    }

    /**
     * Create a new window
     */
    public function createWindow(int $index, string $name): ?string
    {
        $result = Process::timeout(10)->run(
            TmuxCommand::arguments(['new-window', '-P', '-F', '#{pane_id}', '-t', $this->sessionManager->target().":{$index}", '-n', $name, '-c', base_path(), 'sleep', '86400'])
        );

        return $this->createdPaneId($result->successful(), $result->output(), $result->errorOutput());
    }

    /**
     * Split a pane horizontally
     */
    public function splitHorizontal(string $target, int $percentage): ?string
    {
        $result = Process::timeout(10)->run(
            TmuxCommand::arguments(['split-window', '-P', '-F', '#{pane_id}', '-t', $this->target($target), '-h', '-l', "{$percentage}%", '-c', base_path(), 'sleep', '86400'])
        );

        return $this->createdPaneId($result->successful(), $result->output(), $result->errorOutput());
    }

    /**
     * Split a pane vertically
     */
    public function splitVertical(string $target, int $percentage): ?string
    {
        $result = Process::timeout(10)->run(
            TmuxCommand::arguments(['split-window', '-P', '-F', '#{pane_id}', '-t', $this->target($target), '-v', '-l', "{$percentage}%", '-c', base_path(), 'sleep', '86400'])
        );

        return $this->createdPaneId($result->successful(), $result->output(), $result->errorOutput());
    }

    /**
     * Select a specific pane
     */
    public function selectPane(string $target): bool
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['select-pane', '-t', $this->target($target)])
        );

        return $this->recordResult($result);
    }

    /**
     * Select a specific window
     */
    public function selectWindow(int $window): bool
    {
        $target = $this->sessionManager->target().":{$window}";
        $resolved = Process::timeout(5)->run(TmuxCommand::arguments([
            'display-message', '-p', '-t', $target, '#{window_id}',
        ]));
        $windowId = trim($resolved->output());
        if (! $resolved->successful() || ! preg_match('/^@[0-9]+$/', $windowId)) {
            $this->lastError = 'Unable to resolve tmux window ID.';
            $this->refresh();

            return false;
        }
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['select-window', '-t', $windowId])
        );

        return $this->recordResult($result);
    }

    /**
     * Respawn a pane with a new command
     *
     * @param  string|list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function respawnPane(string $target, string|array $command, bool $kill = false, ?string $directory = null, array $environment = []): bool
    {
        $arguments = TmuxCommand::arguments(['respawn-pane']);
        if ($kill) {
            $arguments[] = '-k';
        }
        array_push($arguments, '-t', $this->target($target), '-c', $directory ?? base_path());
        foreach ($environment as $name => $value) {
            array_push($arguments, '-e', $name.'='.$value);
        }
        $workerArguments = is_array($command) ? $command : [$command];
        // tmux treats a trailing semicolon in an argv item as a command separator.
        $workerArguments = array_map(static fn (string $argument): string => str_ends_with($argument, ';')
            ? substr($argument, 0, -1).'\\;' : $argument, $workerArguments);
        array_push($arguments, ...$workerArguments);

        $result = Process::timeout(10)->run(
            $arguments
        );

        return $this->recordResult($result);
    }

    public function heartbeat(): bool
    {
        return $this->recordResult(Process::timeout(5)->run(TmuxCommand::arguments([
            'set-option', '-t', $this->sessionManager->target(), '@nntmux_heartbeat', (string) time(),
        ])));
    }

    public function heartbeatTime(): ?int
    {
        $result = Process::timeout(5)->run(TmuxCommand::arguments([
            'show-options', '-v', '-t', $this->sessionManager->target(), '@nntmux_heartbeat',
        ]));

        return $result->successful() && ctype_digit(trim($result->output())) ? (int) trim($result->output()) : null;
    }

    public function installExitHook(): bool
    {
        $signal = implode(' ', array_map('escapeshellarg', TmuxCommand::arguments([
            'wait-for', '-S', $this->eventChannel(),
        ])));

        return $this->recordResult(Process::timeout(5)->run(TmuxCommand::arguments([
            'set-hook', '-t', $this->sessionManager->target(), 'pane-died', 'run-shell '.escapeshellarg($signal),
        ])));
    }

    private function eventChannel(): string
    {
        return 'nntmux-pane-exit-'.hash('sha256', $this->sessionName);
    }

    /**
     * Wait up to $seconds for a pane to exit.
     *
     * The pane-died hook wakes the wait at once, but it cannot be relied on alone: tmux
     * fires it only after reaping the pane process, which it can miss (see requestReap()),
     * and a wake-up sent while nobody is waiting goes to the stale waiter left by an earlier
     * timed-out wait (tmux never removes those) instead of being remembered. So while one
     * waiter runs, pane state is polled every slice, and the wait returns once a pane that
     * was alive has died or gone.
     *
     * Every `wait-for` client stopped before the channel is signalled stays registered in
     * tmux until the next signal, so the waiter is never stopped on a timeout: it is kept
     * across calls (monitor cycles) and replaced only once tmux has woken it.
     */
    public function waitForExit(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        $alive = $this->alivePanes();
        if ($this->exitWaiter === null || ! $this->exitWaiter->running()) {
            $this->exitWaiter = Process::forever()->start(TmuxCommand::arguments(['wait-for', $this->eventChannel()]));
        }
        $nextPoll = microtime(true) + self::EXIT_WAIT_SLICE_SECONDS;

        while (microtime(true) < $deadline) {
            if (! $this->exitWaiter->running()) {
                $this->exitWaiter = null; // Woken by the pane-died hook.

                return;
            }
            usleep(self::EXIT_WAIT_TICK_MICROSECONDS);
            if (microtime(true) < $nextPoll) {
                continue;
            }
            $nextPoll = microtime(true) + self::EXIT_WAIT_SLICE_SECONDS;
            if ($alive !== null && array_diff($alive, $this->alivePanes() ?? $alive) !== []) {
                return;
            }
        }
    }

    public function __destruct()
    {
        if ($this->exitWaiter?->running()) {
            $this->exitWaiter->signal(defined('SIGTERM') ? SIGTERM : 15);
        }
    }

    /**
     * @return list<string>|null Live pane IDs, or null when the session cannot be listed.
     */
    private function alivePanes(): ?array
    {
        $this->refresh();
        try {
            return array_keys(array_filter($this->paneSnapshot(), static fn (array $state): bool => ! $state['dead']));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Send keys to a pane
     */
    public function sendKeys(string $target, string $keys, bool $enter = true): bool
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(array_values(array_filter(
                ['send-keys', '-t', $this->target($target), '--', $keys, $enter ? 'Enter' : null],
                static fn (?string $argument): bool => $argument !== null,
            )))
        );

        return $this->recordResult($result);
    }

    /**
     * Kill a specific pane
     */
    public function killPane(string $target): bool
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['kill-pane', '-t', $this->target($target)])
        );

        return $this->recordResult($result);
    }

    /**
     * Set pane title
     */
    public function setPaneTitle(string $target, string $title): bool
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['select-pane', '-t', $this->target($target), '-T', $title])
        );

        return $this->recordResult($result);
    }

    /**
     * Get pane title
     */
    public function getPaneTitle(string $target): ?string
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['display-message', '-p', '-t', $this->target($target), '#{pane_title}'])
        );

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * Capture pane content
     */
    public function capturePane(string $target, int $lines = 100): string
    {
        $result = Process::timeout(10)->run(
            TmuxCommand::arguments(['capture-pane', '-p', '-t', $this->target($target), '-S', "-{$lines}"])
        );

        return $result->successful() ? $result->output() : '';
    }

    public function setPaneRole(string $target, TmuxPaneRole $role): bool
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['set-option', '-p', '-t', $this->target($target), self::ROLE_OPTION, $role->value])
        );

        if ($this->recordResult($result)) {
            $this->refresh();

            return true;
        }

        return false;
    }

    public function retainPane(string $pane): bool
    {
        return $this->recordResult(Process::timeout(5)->run(TmuxCommand::arguments([
            'set-option', '-p', '-t', $pane, 'remain-on-exit', 'on',
        ])));
    }

    public function paneForRole(TmuxPaneRole $role, ?string $legacyTarget = null): string
    {
        $targets = $this->roleTargets();

        if (isset($targets[$role->value])) {
            return $targets[$role->value];
        }

        if ($legacyTarget !== null) {
            return $this->tagLegacyPane($role, $legacyTarget);
        }

        throw new RuntimeException("Tmux pane role '{$role->value}' was not found in session '{$this->sessionName}'.");
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return array<string, string>
     */
    private function roleTargets(): array
    {
        if ($this->roleTargets !== null) {
            return $this->roleTargets;
        }

        $targets = [];
        foreach ($this->paneSnapshot() as $paneId => $state) {
            $role = $state['role'];
            if ($role === '') {
                continue;
            }

            if (isset($targets[$role])) {
                throw new RuntimeException(
                    "Tmux pane role '{$role}' is assigned more than once in session '{$this->sessionName}'."
                );
            }

            $targets[$role] = $paneId;
        }

        return $this->roleTargets = $targets;
    }

    private function target(string $target): string
    {
        return str_starts_with($target, '%') ? $target : $this->sessionManager->target().":{$target}";
    }

    private function tagLegacyPane(TmuxPaneRole $role, string $legacyTarget): string
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['display-message', '-p', '-t', $this->target($legacyTarget), "#{pane_id}\t#{".self::ROLE_OPTION.'}'])
        );
        [$paneId, $existingRole] = array_pad(explode("\t", trim($result->output()), 2), 2, '');

        if (! $result->successful() || ! preg_match('/^%[0-9]+$/', $paneId)) {
            throw new RuntimeException(
                "Tmux pane role '{$role->value}' and legacy target '{$legacyTarget}' were not found in session '{$this->sessionName}'."
            );
        }

        if ($existingRole !== '' && $existingRole !== $role->value) {
            throw new RuntimeException("Legacy tmux pane '{$legacyTarget}' already has role '{$existingRole}'.");
        }

        if (! $this->setPaneRole($paneId, $role)) {
            throw new RuntimeException(
                "Unable to tag legacy tmux pane '{$legacyTarget}' as '{$role->value}': {$this->lastError}."
            );
        }

        return $paneId;
    }

    private function createdPaneId(bool $successful, string $output, string $errorOutput): ?string
    {
        $this->refresh();
        if (! $successful) {
            $this->lastError = trim($errorOutput);

            return null;
        }

        $paneId = trim($output);
        if (! preg_match('/^%[0-9]+$/', $paneId)) {
            $this->lastError = "Tmux returned an invalid pane ID: '{$paneId}'.";

            return null;
        }

        $this->lastError = null;

        if (! $this->releasePlaceholder($paneId)) {
            return null;
        }

        return $paneId;
    }

    public function releasePlaceholder(string $pane): bool
    {
        if (! $this->retainPane($pane) || ! $this->respawnPane($pane, ['true'], kill: true)) {
            return false;
        }
        $deadline = microtime(true) + 3;
        do {
            $result = Process::timeout(5)->run(TmuxCommand::arguments([
                'display-message', '-p', '-t', $pane, '#{pane_dead}',
            ]));
            if ($result->successful() && trim($result->output()) === '1') {
                return true;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->lastError = 'Tmux placeholder did not exit.';

        return false;
    }

    private function recordResult(ProcessResult $result): bool
    {
        if ($result->successful()) {
            $this->lastError = null;

            return true;
        }

        $this->lastError = trim($result->errorOutput());
        $this->refresh();

        return false;
    }

    public function refresh(): void
    {
        $this->snapshot = null;
        $this->roleTargets = null;
    }

    /** @return array<string, array{role: string, dead: bool, exit_code: ?int, exit_signal: ?int, pid: int, pipe: bool, window: string}> */
    public function paneSnapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }
        [$panes, $serverPid] = $this->listPanes();

        // A dead pane should report how its process ended. When it does not, tmux has
        // not reaped the process (see requestReap()); nudge it, at most every REAP_RETRY_SECONDS
        // per process while the status is still missing.
        $now = now()->getTimestamp();
        $unreaped = [];
        foreach ($panes as $id => $state) {
            if ($state['dead'] && $state['exit_code'] === null && $state['exit_signal'] === null
                && $now - ($this->reapRequested[$id.':'.$state['pid']] ?? PHP_INT_MIN) >= self::REAP_RETRY_SECONDS) {
                $unreaped[$id] = $state['pid'];
            }
        }
        if ($unreaped !== [] && $this->requestReap($serverPid)) {
            foreach ($unreaped as $id => $pid) {
                $this->reapRequested[$id.':'.$pid] = $now;
            }
            // tmux handles the signal from its event loop, so allow it a moment.
            for ($attempt = 0; $attempt < 10; $attempt++) {
                usleep(10000);
                [$panes] = $this->listPanes();
                if (! $this->hasUnreportedExit($panes, array_keys($unreaped))) {
                    break;
                }
            }
        }
        $current = [];
        foreach ($panes as $id => $state) {
            $current[$id.':'.$state['pid']] = true;
        }
        $this->reapRequested = array_intersect_key($this->reapRequested, $current);

        return $this->snapshot = $panes;
    }

    /**
     * Ask the tmux server to reap exited pane processes.
     *
     * Debian's tmux is built with libutempter, which resets SIGCHLD to its default
     * disposition while it removes a pane's login record. A pane process whose exit
     * signal lands in that window is never reaped, so tmux shows the pane as dead
     * with no exit status or signal. A fresh SIGCHLD makes tmux run its waitpid()
     * loop and record how the process ended.
     */
    protected function requestReap(int $serverPid): bool
    {
        if ($serverPid <= 0 || ! function_exists('posix_kill') || ! defined('SIGCHLD')) {
            return false;
        }
        // The PID comes from the tmux server; make sure it is that process in this PID namespace.
        $command = @file_get_contents("/proc/{$serverPid}/comm");
        if ($command === false || ! str_starts_with($command, 'tmux')) {
            return false;
        }

        return posix_kill($serverPid, SIGCHLD);
    }

    /**
     * @return array{array<string, array{role: string, dead: bool, exit_code: ?int, exit_signal: ?int, pid: int, pipe: bool, window: string}>, int}
     */
    private function listPanes(): array
    {
        $result = Process::timeout(10)->run(TmuxCommand::arguments([
            'list-panes', '-s', '-t', $this->sessionManager->target(), '-F',
            "#{pane_id}\t#{".self::ROLE_OPTION."}\t#{pane_dead}\t#{pane_dead_status}\t#{pane_pid}\t#{pane_pipe}\t#{window_id}\t#{pane_dead_signal}\t#{pid}",
        ]));
        if (! $result->successful()) {
            throw new RuntimeException("Unable to list panes for tmux session '{$this->sessionName}'.");
        }
        $panes = [];
        $serverPid = 0;
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            [$id, $role, $dead, $status, $pid, $pipe, $window, $signal, $server] = array_pad(explode("\t", $line), 9, '');
            if (! preg_match('/^%[0-9]+$/', $id)) {
                continue;
            }
            $panes[$id] = [
                'role' => $role,
                'dead' => $dead === '1',
                'exit_code' => $status === '' ? null : (int) $status,
                'exit_signal' => $signal === '' ? null : (int) $signal,
                'pid' => (int) $pid,
                'pipe' => $pipe === '1',
                'window' => $window,
            ];
            $serverPid = (int) $server;
        }

        return [$panes, $serverPid];
    }

    /**
     * @param  array<string, array{role: string, dead: bool, exit_code: ?int, exit_signal: ?int, pid: int, pipe: bool, window: string}>  $panes
     * @param  list<string>  $ids
     */
    private function hasUnreportedExit(array $panes, array $ids): bool
    {
        foreach ($ids as $id) {
            if (isset($panes[$id]) && $panes[$id]['dead'] && $panes[$id]['exit_code'] === null && $panes[$id]['exit_signal'] === null) {
                return true;
            }
        }

        return false;
    }

    public function isAlive(string $pane): bool
    {
        return isset($this->paneSnapshot()[$pane]) && ! $this->paneSnapshot()[$pane]['dead'];
    }

    public function logPane(string $pane, string $file): bool
    {
        if ($this->paneSnapshot()[$pane]['pipe'] ?? false) {
            return true;
        }

        $successful = $this->recordResult(Process::timeout(10)->run(TmuxCommand::arguments([
            'pipe-pane', '-O', '-t', $pane, 'cat >> '.escapeshellarg($file),
        ])));
        if ($successful && isset($this->snapshot[$pane])) {
            $this->snapshot[$pane]['pipe'] = true;
        }

        return $successful;
    }

    /** @param list<string> $command */
    public function respawnLoggedPane(string $pane, array $command, string $file): bool
    {
        $channel = 'nntmux-log-'.bin2hex(random_bytes(12));
        $wait = implode(' ', array_map('escapeshellarg', TmuxCommand::arguments(['wait-for', $channel])));
        $worker = implode(' ', array_map('escapeshellarg', $command));
        // pipe-pane needs a live pane in tmux 3.5. Hold this owned worker until logging is attached.
        if (! $this->respawnPane($pane, ['bash', '-c', 'timeout 10 '.$wait.' || exit 125; exec '.$worker])) {
            return false;
        }
        if (! $this->logPane($pane, $file)) {
            return false;
        }

        return $this->recordResult(Process::timeout(5)->run(TmuxCommand::arguments(['wait-for', '-S', $channel])));
    }
}
