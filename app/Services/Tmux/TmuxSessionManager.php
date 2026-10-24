<?php

declare(strict_types=1);

namespace App\Services\Tmux;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Service for managing tmux sessions and panes
 */
class TmuxSessionManager
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_session_build_window_cmd_nul(const char *base_args, size_t base_args_len, size_t base_count, uint8_t cmd_kind, const char *session_target, size_t session_target_len, const char *window, size_t window_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_tmux_session_get_split_setup(int32_t sequential_mode, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    protected string $sessionName;

    protected string $configFile;

    private ?string $lastError = null;

    private ?string $sessionId = null;

    public function __construct(?string $sessionName = null)
    {
        $this->sessionName = $sessionName ?? $this->getSessionName();
        $this->configFile = config('tmux.config_file');
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('irc', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * Get the tmux session name from settings or config
     */
    public function getSessionName(): string
    {
        return app(ConfigurationProvider::class)->tmux()->sessionName;
    }

    public function sessionName(): string
    {
        return $this->sessionName;
    }

    /**
     * Check if tmux session exists
     */
    public function sessionExists(): bool
    {
        $result = Process::timeout(10)
            ->run(TmuxCommand::arguments(['has-session', '-t', $this->sessionId ?? '='.$this->sessionName]));

        if (! $result->successful()) {
            return false;
        }
        $this->target();

        return true;
    }

    /**
     * Create a new tmux session
     */
    public function createSession(string $windowName = 'Monitor'): ?string
    {
        if ($this->sessionExists()) {
            $this->lastError = "Tmux session '{$this->sessionName}' already exists.";

            return null;
        }

        $arguments = TmuxCommand::arguments([]);
        if (file_exists($this->configFile)) {
            array_push($arguments, '-f', $this->configFile);
        }
        array_push(
            $arguments,
            'new-session',
            '-d',
            '-P',
            '-F',
            '#{pane_id}',
            '-s',
            $this->sessionName,
            '-n',
            $windowName,
            '-c',
            base_path(),
            'sleep',
            '86400',
        );

        $result = Process::timeout(30)->run($arguments);

        if (! $result->successful()) {
            $this->lastError = trim($result->errorOutput());

            return null;
        }

        $paneId = trim($result->output());
        if (! preg_match('/^%[0-9]+$/', $paneId)) {
            $this->lastError = "Tmux returned an invalid pane ID: '{$paneId}'.";

            return null;
        }

        $this->lastError = null;
        $this->sessionId = null;
        $this->resolveTarget($paneId);

        $panes = new TmuxPaneManager($this->sessionName);
        if (! $panes->releasePlaceholder($paneId)) {
            $this->killSession();
            $this->lastError = $panes->lastError();

            return null;
        }

        return $paneId;
    }

    /**
     * Kill the tmux session
     */
    public function killSession(): bool
    {
        if (! $this->sessionExists()) {
            return true;
        }

        $result = Process::timeout(30)->run(TmuxCommand::arguments(['kill-session', '-t', $this->target()]));

        return $result->successful();
    }

    /**
     * Attach to the tmux session
     */
    public function attachSession(): bool
    {
        if (! $this->sessionExists()) {
            return false;
        }

        passthru(implode(' ', array_map('escapeshellarg', TmuxCommand::arguments(['attach-session', '-t', $this->target()]))), $exitCode);

        return $exitCode === 0;
    }

    /**
     * List all panes in the session
     *
     * @return array<string, mixed>
     */
    public function listPanes(): array
    {
        if (! $this->sessionExists()) {
            return [];
        }

        $result = Process::timeout(10)->run(
            TmuxCommand::arguments(['list-panes', '-s', '-t', $this->target(), '-F', "#{window_index}:#{pane_index}\t#{pane_title}"])
        );

        if (! $result->successful()) {
            return [];
        }

        $panes = [];
        $lines = explode("\n", trim($result->output()));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            [$position, $title] = array_pad(explode("\t", $line, 2), 2, '');
            $panes[$position] = $title;
        }

        return $panes;
    }

    /**
     * Get pane status
     */
    public function getPaneStatus(string $window, string $pane): ?string
    {
        $result = Process::timeout(5)->run(
            TmuxCommand::arguments(['display-message', '-p', '-t', $this->target().":{$window}.{$pane}", '#{pane_current_command}'])
        );

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * Check if a pane is running a command
     */
    public function isPaneActive(string $window, string $pane): bool
    {
        $status = $this->getPaneStatus($window, $pane);

        return $status !== null && $status !== 'bash' && $status !== 'sh';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Build the `select-layout -t {session}:{window} tiled` tmux command argv.
     *
     * @param  list<string>|null  $baseArguments
     * @return list<string>
     */
    public function buildSelectTiledCommand(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): array {
        if (self::$useRustFfi) {
            $native = $this->buildSelectTiledCommandNative($window, $sessionTarget, $baseArguments);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->buildSelectTiledCommandPhp($window, $sessionTarget, $baseArguments);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>|null
     */
    public function buildSelectTiledCommandNative(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): ?array {
        return $this->buildWindowCommandNative(0, (string) $window, $sessionTarget, $baseArguments);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>
     */
    public function buildSelectTiledCommandPhp(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): array {
        $target = $sessionTarget ?? $this->target();
        $base = $baseArguments ?? TmuxCommand::arguments([]);

        return array_merge($base, ['select-layout', '-t', "{$target}:{$window}", 'tiled']);
    }

    /**
     * Build the `kill-window -t {session}:{window}` tmux command argv.
     *
     * @param  list<string>|null  $baseArguments
     * @return list<string>
     */
    public function buildKillWindowCommand(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): array {
        if (self::$useRustFfi) {
            $native = $this->buildKillWindowCommandNative($window, $sessionTarget, $baseArguments);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->buildKillWindowCommandPhp($window, $sessionTarget, $baseArguments);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>|null
     */
    public function buildKillWindowCommandNative(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): ?array {
        return $this->buildWindowCommandNative(1, (string) $window, $sessionTarget, $baseArguments);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>
     */
    public function buildKillWindowCommandPhp(
        int|string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): array {
        $target = $sessionTarget ?? $this->target();
        $base = $baseArguments ?? TmuxCommand::arguments([]);

        return array_merge($base, ['kill-window', '-t', "{$target}:{$window}"]);
    }

    /**
     * @param  list<string>|null  $baseArguments
     * @return list<string>|null
     */
    private function buildWindowCommandNative(
        int $cmdKind,
        string $window,
        ?string $sessionTarget = null,
        ?array $baseArguments = null,
    ): ?array {
        $ffi = self::ffi();
        if ($ffi === null || $window === '' || str_contains($window, "\0")) {
            return null;
        }

        $target = $sessionTarget ?? $this->target();
        if ($target === '' || str_contains($target, "\0")) {
            return null;
        }

        $base = $baseArguments ?? TmuxCommand::arguments([]);
        if ($base === []) {
            return null;
        }
        foreach ($base as $arg) {
            if (str_contains($arg, "\0")) {
                return null;
            }
        }

        $basePacked = implode("\0", $base);

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_tmux_session_build_window_cmd_nul(
                $basePacked,
                strlen($basePacked),
                count($base),
                $cmdKind,
                $target,
                strlen($target),
                $window,
                strlen($window),
                $scratch,
                $cap,
            );
            if ($rc <= -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $rc = (int) $ffi->nntmux_tmux_session_build_window_cmd_nul(
                    $basePacked,
                    strlen($basePacked),
                    count($base),
                    $cmdKind,
                    $target,
                    strlen($target),
                    $window,
                    strlen($window),
                    $scratch,
                    $cap,
                );
            }
            if ($rc <= 0) {
                return null;
            }

            /** @var list<string> $argv */
            $argv = explode("\0", FFI::string($scratch, $rc));

            return $argv;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Return the pane split layout plan for the given sequential mode (`0` = full, `1` = basic, `2` = stripped).
     *
     * @return list<array{window: int, direction: string, target_role: string, percentage: int, role: string}>
     */
    public function getSplitSetup(int $sequentialMode = 0): array
    {
        if (self::$useRustFfi) {
            $native = $this->getSplitSetupNative($sequentialMode);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->getSplitSetupPhp($sequentialMode);
    }

    /**
     * @return list<array{window: int, direction: string, target_role: string, percentage: int, role: string}>|null
     */
    public function getSplitSetupNative(int $sequentialMode = 0): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $rc = (int) $ffi->nntmux_tmux_session_get_split_setup(
                $sequentialMode,
                $scratch,
                $cap,
            );
            if ($rc <= -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $rc = (int) $ffi->nntmux_tmux_session_get_split_setup(
                    $sequentialMode,
                    $scratch,
                    $cap,
                );
            }
            if ($rc <= 0) {
                return null;
            }

            $rows = [];
            foreach (explode("\n", FFI::string($scratch, $rc)) as $line) {
                $parts = explode("\t", $line);
                if (count($parts) !== 5) {
                    return null;
                }
                $rows[] = [
                    'window' => (int) $parts[0],
                    'direction' => $parts[1],
                    'target_role' => $parts[2],
                    'percentage' => (int) $parts[3],
                    'role' => $parts[4],
                ];
            }

            return $rows;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{window: int, direction: string, target_role: string, percentage: int, role: string}>
     */
    public function getSplitSetupPhp(int $sequentialMode = 0): array
    {
        return match ($sequentialMode) {
            1 => [
                ['window' => 0, 'direction' => 'h', 'target_role' => 'monitor', 'percentage' => 67, 'role' => 'releases'],
                ['window' => 1, 'direction' => 'h', 'target_role' => 'fix_names', 'percentage' => 50, 'role' => 'remove_crap'],
                ['window' => 2, 'direction' => 'h', 'target_role' => 'post_additional', 'percentage' => 50, 'role' => 'post_movies'],
                ['window' => 2, 'direction' => 'v', 'target_role' => 'post_additional', 'percentage' => 67, 'role' => 'post_tv'],
                ['window' => 2, 'direction' => 'v', 'target_role' => 'post_tv', 'percentage' => 50, 'role' => 'post_metadata'],
            ],
            2 => [
                ['window' => 0, 'direction' => 'h', 'target_role' => 'monitor', 'percentage' => 67, 'role' => 'sequential'],
                ['window' => 1, 'direction' => 'h', 'target_role' => 'fix_names', 'percentage' => 50, 'role' => 'post_metadata'],
            ],
            default => [
                ['window' => 0, 'direction' => 'h', 'target_role' => 'monitor', 'percentage' => 67, 'role' => 'binaries'],
                ['window' => 0, 'direction' => 'v', 'target_role' => 'binaries', 'percentage' => 67, 'role' => 'backfill'],
                ['window' => 0, 'direction' => 'v', 'target_role' => 'backfill', 'percentage' => 50, 'role' => 'releases'],
                ['window' => 1, 'direction' => 'h', 'target_role' => 'fix_names', 'percentage' => 50, 'role' => 'remove_crap'],
                ['window' => 2, 'direction' => 'h', 'target_role' => 'post_additional', 'percentage' => 50, 'role' => 'post_movies'],
                ['window' => 2, 'direction' => 'v', 'target_role' => 'post_additional', 'percentage' => 67, 'role' => 'post_tv'],
                ['window' => 2, 'direction' => 'v', 'target_role' => 'post_tv', 'percentage' => 50, 'role' => 'post_metadata'],
            ],
        };
    }

    public function target(): string
    {
        return $this->sessionId ?? $this->resolveTarget('='.$this->sessionName.':');
    }

    private function resolveTarget(string $target): string
    {
        $result = Process::timeout(10)->run(TmuxCommand::arguments([
            'display-message', '-p', '-t', $target, '#{session_id}',
        ]));
        $id = trim($result->output());
        if (! $result->successful() || ! preg_match('/^\$[0-9]+$/', $id)) {
            throw new \RuntimeException("Unable to resolve exact tmux session '{$this->sessionName}'.");
        }
        $this->sessionId = $id;

        return $id;
    }
}
