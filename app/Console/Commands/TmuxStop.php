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
use App\Support\RustPipelineLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class TmuxStop extends Command
{
    public const string W386_FFI_CDEF = 'ssize_t nntmux_w386_tmux_drain_stop_runners(uint8_t operation, uint8_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    // Paired caller benchmarks must qualify before enabling this separate candidate.
    private static ?bool $useRustW386 = null;

    public static function setUseRustW386(?bool $enabled): void
    {
        self::$useRustW386 = $enabled;
    }

    public static function usesRustW386(): bool
    {
        return self::$useRustW386 ?? false;
    }

    private static function w386Transform(int $flags, string $input): ?string
    {
        $length = strlen($input);
        if ($length > 16_777_216) {
            return null;
        }
        $ffi = RustPipelineLibrary::resolveDomain('additional_w386', self::W386_FFI_CDEF);
        if ($ffi === null) {
            return null;
        }
        try {
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w386_tmux_drain_stop_runners(2, $flags, $input, $length, $scratch, min($capacity, 16_777_216));
            if (is_int($written) && $written < -1 && -$written <= 16_777_216) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w386_tmux_drain_stop_runners(2, $flags, $input, $length, $scratch, min($capacity, 16_777_216));
            }
            if (! is_int($written) || $written < 0 || $written > min($capacity, 16_777_216)) {
                return null;
            }

            return $written === 0 ? '' : \FFI::string($scratch, $written);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Interrupt decisions for an already-selected list of active worker roles.
     * This plan neither selects workers nor sends signals.
     *
     * @param  list<string>  $roles
     * @return list<bool>
     */
    public static function stopInterruptPlan(array $roles, bool $wait): array
    {
        return (self::usesRustW386() ? self::stopInterruptPlanNative($roles, $wait) : null)
            ?? self::stopInterruptPlanPhp($roles, $wait);
    }

    /**
     * @param  list<string>  $roles
     * @return list<bool>
     */
    public static function stopInterruptPlanPhp(array $roles, bool $wait): array
    {
        return array_map(static fn (string $role): bool => ! $wait || in_array($role, ['monitor', 'irc_scraper'], true), $roles);
    }

    /**
     * @param  list<string>  $roles
     * @return list<bool>|null
     */
    public static function stopInterruptPlanNative(array $roles, bool $wait): ?array
    {
        $input = pack('V', count($roles));
        foreach ($roles as $role) {
            $input .= pack('V', strlen($role)).$role;
        }
        $output = self::w386Transform((int) $wait, $input);
        if ($output === null || strlen($output) !== 4 + count($roles)) {
            return null;
        }

        return array_map(static fn (string $flag): bool => $flag === "\1", str_split(substr($output, 4)));
    }

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
