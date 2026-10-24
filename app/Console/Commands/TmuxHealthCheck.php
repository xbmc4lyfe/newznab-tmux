<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TmuxPaneRole;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Tmux\TmuxCommand;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class TmuxHealthCheck extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_tmux_health_check_evaluate_heartbeat(
    uint8_t has_heartbeat,
    int64_t heartbeat,
    int64_t now_ts,
    int64_t heartbeat_max_age,
    int64_t delay,
    int64_t *out_scalars
);
ssize_t nntmux_tmux_health_check_resolve_log_context_nul(
    const char *level_ptr,
    size_t level_len,
    const char *outcome_ptr,
    size_t outcome_len,
    const char *session_ptr,
    size_t session_len,
    uint8_t auto_restart,
    uint8_t should_be_running,
    uint8_t has_exit_code,
    int64_t exit_code,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tmux:health-check
                            {--session= : Tmux session name}
                            {--require-session : Check only whether the exact session exists}
                            {--auto-restart : Automatically restart tmux if monitor pane is dead}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if tmux session exists and monitor pane is alive, optionally restart if dead';

    private TmuxSessionManager $sessionManager;

    private string $sessionName;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @return array{max_age: int, is_stale: bool}
     */
    public static function evaluateHeartbeatStaleness(
        ?int $heartbeat,
        int $now,
        int $heartbeatMaxAge = 180,
        int $delay = 10
    ): array {
        if (self::$useRustFfi) {
            return self::evaluateHeartbeatStalenessNative($heartbeat, $now, $heartbeatMaxAge, $delay);
        }

        return self::evaluateHeartbeatStalenessPhp($heartbeat, $now, $heartbeatMaxAge, $delay);
    }

    /**
     * @return array{max_age: int, is_stale: bool}
     */
    public static function evaluateHeartbeatStalenessPhp(
        ?int $heartbeat,
        int $now,
        int $heartbeatMaxAge = 180,
        int $delay = 10
    ): array {
        $maximumAge = max(1, $heartbeatMaxAge, $delay + 15);
        $isStale = $heartbeat !== null && ($now - $heartbeat > $maximumAge);

        return [
            'max_age' => $maximumAge,
            'is_stale' => $isStale,
        ];
    }

    /**
     * @return array{max_age: int, is_stale: bool}
     */
    public static function evaluateHeartbeatStalenessNative(
        ?int $heartbeat,
        int $now,
        int $heartbeatMaxAge = 180,
        int $delay = 10
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::evaluateHeartbeatStalenessPhp($heartbeat, $now, $heartbeatMaxAge, $delay);
        }

        try {
            $outScalars = $ffi->new('int64_t[2]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_tmux_health_check_evaluate_heartbeat(
                $heartbeat !== null ? 1 : 0,
                $heartbeat ?? 0,
                $now,
                $heartbeatMaxAge,
                $delay,
                $outScalars
            );
            $maxAge = $outScalars[0];
            $isStale = $outScalars[1];
            if ($rc !== 1 || ! is_int($maxAge) || ! is_int($isStale)) {
                return self::evaluateHeartbeatStalenessPhp($heartbeat, $now, $heartbeatMaxAge, $delay);
            }

            return [
                'max_age' => $maxAge,
                'is_stale' => $isStale === 1,
            ];
        } catch (Throwable) {
            return self::evaluateHeartbeatStalenessPhp($heartbeat, $now, $heartbeatMaxAge, $delay);
        }
    }

    /**
     * @return array{
     *     level: string,
     *     message: string,
     *     context: array{
     *         session: string,
     *         auto_restart: bool,
     *         should_be_running: bool,
     *         outcome: string,
     *         exit_code: int|null
     *     }
     * }
     */
    public static function resolveHealthCheckLogContext(
        string $level,
        string $outcome,
        string $session,
        bool $autoRestart,
        bool $shouldBeRunning,
        ?int $exitCode = null
    ): array {
        if (self::$useRustFfi) {
            return self::resolveHealthCheckLogContextNative(
                $level,
                $outcome,
                $session,
                $autoRestart,
                $shouldBeRunning,
                $exitCode
            );
        }

        return self::resolveHealthCheckLogContextPhp(
            $level,
            $outcome,
            $session,
            $autoRestart,
            $shouldBeRunning,
            $exitCode
        );
    }

    /**
     * @return array{
     *     level: string,
     *     message: string,
     *     context: array{
     *         session: string,
     *         auto_restart: bool,
     *         should_be_running: bool,
     *         outcome: string,
     *         exit_code: int|null
     *     }
     * }
     */
    public static function resolveHealthCheckLogContextPhp(
        string $level,
        string $outcome,
        string $session,
        bool $autoRestart,
        bool $shouldBeRunning,
        ?int $exitCode = null
    ): array {
        return [
            'level' => $level,
            'message' => 'Tmux health check result',
            'context' => [
                'session' => $session,
                'auto_restart' => $autoRestart,
                'should_be_running' => $shouldBeRunning,
                'outcome' => $outcome,
                'exit_code' => $exitCode,
            ],
        ];
    }

    /**
     * @return array{
     *     level: string,
     *     message: string,
     *     context: array{
     *         session: string,
     *         auto_restart: bool,
     *         should_be_running: bool,
     *         outcome: string,
     *         exit_code: int|null
     *     }
     * }
     */
    public static function resolveHealthCheckLogContextNative(
        string $level,
        string $outcome,
        string $session,
        bool $autoRestart,
        bool $shouldBeRunning,
        ?int $exitCode = null
    ): array {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolveHealthCheckLogContextPhp(
                $level,
                $outcome,
                $session,
                $autoRestart,
                $shouldBeRunning,
                $exitCode
            );
        }

        try {
            $cap = max(128, strlen($level) + strlen($outcome) + strlen($session) + 64);
            $outScalars = $ffi->new('int64_t[4]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_health_check_resolve_log_context_nul(
                $level,
                strlen($level),
                $outcome,
                strlen($outcome),
                $session,
                strlen($session),
                $autoRestart ? 1 : 0,
                $shouldBeRunning ? 1 : 0,
                $exitCode !== null ? 1 : 0,
                $exitCode ?? 0,
                $outScalars,
                $outBuf,
                $cap
            );
            $autoRestartFlag = $outScalars[0];
            $shouldRunFlag = $outScalars[1];
            $hasExitCodeFlag = $outScalars[2];
            $exitCodeVal = $outScalars[3];
            if (
                ! is_int($written)
                || $written <= 0
                || ! is_int($autoRestartFlag)
                || ! is_int($shouldRunFlag)
                || ! is_int($hasExitCodeFlag)
                || ! is_int($exitCodeVal)
            ) {
                return self::resolveHealthCheckLogContextPhp(
                    $level,
                    $outcome,
                    $session,
                    $autoRestart,
                    $shouldBeRunning,
                    $exitCode
                );
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) !== 4) {
                return self::resolveHealthCheckLogContextPhp(
                    $level,
                    $outcome,
                    $session,
                    $autoRestart,
                    $shouldBeRunning,
                    $exitCode
                );
            }

            return [
                'level' => $parts[0],
                'message' => $parts[1],
                'context' => [
                    'session' => $parts[2],
                    'auto_restart' => $autoRestartFlag === 1,
                    'should_be_running' => $shouldRunFlag === 1,
                    'outcome' => $parts[3],
                    'exit_code' => $hasExitCodeFlag === 1 ? $exitCodeVal : null,
                ],
            ];
        } catch (Throwable) {
            return self::resolveHealthCheckLogContextPhp(
                $level,
                $outcome,
                $session,
                $autoRestart,
                $shouldBeRunning,
                $exitCode
            );
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->sessionName = $this->option('session')
                ?? app(ConfigurationProvider::class)->tmux()->sessionName;

            $this->sessionManager = new TmuxSessionManager($this->sessionName);
            $quiet = $this->output->isQuiet();
            $shouldBeRunning = $this->shouldBeRunning();
            $autoRestart = (bool) $this->option('auto-restart');

            if (! $this->sessionManager->sessionExists()) {
                if (! $quiet) {
                    $this->warn("⚠️  Tmux session '{$this->sessionName}' does not exist.");
                }

                if ($this->option('require-session')) {
                    return Command::FAILURE;
                }

                if (! $shouldBeRunning) {
                    $this->logHealthCheck('notice', 'stopped_intentionally');

                    return Command::SUCCESS;
                }

                if ($autoRestart) {
                    $this->logHealthCheck('warning', 'session_missing_restart_attempted');

                    return $this->restartTmux();
                }

                $this->logHealthCheck('warning', 'session_missing_unrecovered');

                return Command::FAILURE;
            }

            if ($this->option('require-session')) {
                return Command::SUCCESS;
            }

            if (! $quiet) {
                $this->info("✅ Tmux session '{$this->sessionName}' exists.");
            }

            if (! $shouldBeRunning) {
                $this->logHealthCheck('notice', 'stopped_intentionally');

                return Command::SUCCESS;
            }

            $monitorPaneDead = $this->isMonitorPaneDead();

            if ($monitorPaneDead) {
                if (! $quiet) {
                    $this->warn('⚠️  Monitor pane is dead.');
                }

                if ($autoRestart) {
                    $this->logHealthCheck('warning', 'monitor_dead_restart_attempted');

                    return $this->restartMonitor();
                }

                $this->logHealthCheck('warning', 'monitor_dead_unrecovered');

                return Command::FAILURE;
            }

            if (! $quiet) {
                $this->info('✅ Monitor pane is alive and running.');
            }

            $heartbeat = (new TmuxPaneManager($this->sessionName))->heartbeatTime();
            // The monitor beats once per loop and then sleeps for its delay, so a healthy heartbeat is at least that old.
            $heartbeatState = self::evaluateHeartbeatStaleness(
                $heartbeat,
                time(),
                (int) config('tmux.monitor.heartbeat_max_age', 180),
                (int) config('tmux.monitor.delay', 10)
            );
            if ($heartbeatState['is_stale']) {
                $this->logHealthCheck('warning', 'monitor_heartbeat_stale');
                $this->warn('Monitor heartbeat is stale; its active pane was preserved.');

                return Command::FAILURE;
            }

            $this->logHealthCheck('info', 'healthy');

            return Command::SUCCESS;

        } catch (Throwable $e) {
            $this->error('❌ Health check failed: '.$e->getMessage());
            logger()->error('Tmux health check error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Check if the monitor pane is dead.
     */
    private function isMonitorPaneDead(): bool
    {
        $paneManager = new TmuxPaneManager($this->sessionName);

        try {
            $monitorPane = $paneManager->paneForRole(TmuxPaneRole::Monitor, '0.0');
        } catch (\RuntimeException) {
            return true;
        }

        return ! $paneManager->isAlive($monitorPane);
    }

    private function restartMonitor(): int
    {
        $panes = new TmuxPaneManager($this->sessionName);
        $pane = $panes->paneForRole(TmuxPaneRole::Monitor, '0.0');
        $successful = $panes->respawnPane($pane, TmuxCommand::monitor($this->sessionName));
        $this->logHealthCheck($successful ? 'notice' : 'error', $successful ? 'monitor_restart_succeeded' : 'monitor_restart_failed');

        return $successful ? Command::SUCCESS : Command::FAILURE;
    }

    private function shouldBeRunning(): bool
    {
        $runtime = app(ProcessingRuntimeStateRepository::class);

        return ! $runtime->stopRequested() && $runtime->isTmuxRunning();
    }

    /**
     * Restart the tmux session.
     */
    private function restartTmux(): int
    {
        $this->info('🔄 Restarting tmux session...');

        $this->info('▶️  Starting new tmux session...');
        $exitCode = $this->call('tmux:start', [
            '--session' => $this->sessionName,
        ]);

        if ($exitCode === Command::SUCCESS) {
            $this->info("✅ Tmux session '{$this->sessionName}' restarted successfully.");
            $this->logHealthCheck('notice', 'restart_succeeded', $exitCode);
        } else {
            $this->error("❌ Failed to restart tmux session '{$this->sessionName}'.");
            $this->logHealthCheck('error', 'restart_failed', $exitCode);
        }

        return $exitCode;
    }

    private function logHealthCheck(string $level, string $outcome, ?int $exitCode = null): void
    {
        $record = self::resolveHealthCheckLogContext(
            $level,
            $outcome,
            $this->sessionName,
            (bool) $this->option('auto-restart'),
            $this->shouldBeRunning(),
            $exitCode
        );

        Log::log($record['level'], $record['message'], $record['context']);
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
