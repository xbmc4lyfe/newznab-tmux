<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TmuxMode;
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

class TmuxStop extends Command
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

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tmux:stop
                            {--session= : Tmux session name}
                            {--force : Stop without confirmation}
                            {--wait : Let active processing batches finish before closing the session}
                            {--timeout=5 : Maximum seconds for interrupted workers to clean up before closing the session}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Stop tmux processing session (modernized)';

    private TmuxSessionManager $sessionManager;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {

        try {
            // Get session name
            $sessionName = $this->option('session')
                ?? app(ConfigurationProvider::class)->tmux()->sessionName;

            $this->sessionManager = new TmuxSessionManager($sessionName);

            // Check if session exists
            if (! $this->sessionManager->sessionExists()) {
                $this->confirmSessionAbsent($sessionName);
                $runtimeState = app(ProcessingRuntimeStateRepository::class);
                $runtimeState->setTmuxRunning(false);
                $runtimeState->requestStop(false);
                $this->warn("⚠️  Session '{$sessionName}' is not running");

                return Command::SUCCESS;
            }

            // Confirm unless forced
            if (! $this->option('force')) {
                if (! $this->confirm("Stop tmux session '{$sessionName}'?", true)) {
                    $this->info('Cancelled');

                    return Command::SUCCESS;
                }
            }

            cli()->header('Stopping Tmux Session');

            // Set running flag to 0
            $runtimeState = app(ProcessingRuntimeStateRepository::class);
            $runtimeState->requestStop();
            $runtimeState->setTmuxRunning(false);
            $this->info('✅ Running flag cleared');

            $panes = new TmuxPaneManager($sessionName);
            $workerRoles = array_merge(array_values(TmuxMode::Full->tasks()), [TmuxPaneRole::Sequential, TmuxPaneRole::Monitor]);
            $timeout = max(0, (int) $this->option('timeout'));
            $deadline = microtime(true) + $timeout;
            $interrupted = [];
            $wait = (bool) $this->option('wait');
            $this->info($wait
                ? 'Waiting for active processing panes to finish their work...'
                : 'Interrupting processing and cleaning up workers...');
            do {
                $panes->refresh();
                $active = array_filter($panes->paneSnapshot(), static fn (array $state): bool => ! $state['dead']
                    && in_array(TmuxPaneRole::tryFrom($state['role']), $workerRoles, true));
                if ($active === []) {
                    break;
                }
                foreach (array_keys($active) as $pane) {
                    if ($wait && ! in_array(TmuxPaneRole::tryFrom($active[$pane]['role']), [TmuxPaneRole::Monitor, TmuxPaneRole::IrcScraper], true)) {
                        continue;
                    }
                    if (! isset($interrupted[$pane])) {
                        $panes->sendKeys($pane, 'C-c', enter: false);
                        $interrupted[$pane] = true;
                    }
                }
                if (! $wait && microtime(true) >= $deadline) {
                    $this->warn('Cleanup timeout reached; closing the processing session.');

                    break;
                }
                usleep(250000);
            } while (true);

            // Kill the session
            if ($this->sessionManager->killSession()) {
                $this->confirmSessionAbsent($sessionName);
                $runtimeState->requestStop(false);
                $this->info("✅ Session '{$sessionName}' stopped successfully");

                return Command::SUCCESS;
            } else {
                $this->error("❌ Failed to stop session '{$sessionName}'");

                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $this->error('❌ Failed to stop tmux: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    private function confirmSessionAbsent(string $sessionName): void
    {
        $result = Process::timeout(10)->run(TmuxCommand::arguments(['list-sessions', '-F', '#{session_name}']));
        $successful = $result->successful();
        $output = $successful ? $result->output() : '';
        $error = $successful ? '' : $result->errorOutput();
        $exitCode = $successful ? null : $result->exitCode();
        $status = (self::usesRustFfi() ? self::sessionAbsenceNative($sessionName, $successful, $output, $error, $exitCode) : null)
            ?? self::sessionAbsencePhp($sessionName, $successful, $output, $error, $exitCode);
        if ($status === 1) {
            throw new RuntimeException('The tmux session still exists; keeping the stop request.');
        }
        if ($status === 2) {
            throw new RuntimeException('Unable to confirm the tmux session is absent; keeping the stop request.');
        }
    }

    /** 0 means absent, 1 means still present, 2 means the check failed. */
    public static function sessionAbsenceNative(string $sessionName, bool $successful, string $output, string $error, ?int $exitCode): ?int
    {
        $result = BaseRunner::runnerTransformNative(11, [$sessionName, $successful ? '1' : '0', $output, $error, $exitCode === null ? '' : (string) $exitCode]);

        return in_array($result, ['0', '1', '2'], true) ? (int) $result : null;
    }

    /** 0 means absent, 1 means still present, 2 means the check failed. */
    public static function sessionAbsencePhp(string $sessionName, bool $successful, string $output, string $error, ?int $exitCode): int
    {
        if ($successful) {
            return in_array($sessionName, explode("\n", trim($output)), true) ? 1 : 0;
        }
        $error = trim($error);
        if ($exitCode === 1 && (str_starts_with($error, 'no server running on ')
            || (str_starts_with($error, 'error connecting to ') && str_ends_with($error, '(No such file or directory)')))) {
            return 0;
        }

        return 2;
    }
}
