<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TmuxPaneRole;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Tmux\CollectionDowntimeCompensator;
use App\Services\Tmux\TmuxCommand;
use App\Services\Tmux\TmuxLayoutBuilder;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

class TmuxStart extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_tmux_start_verify_which_output(
    uint8_t successful,
    const char *output_ptr,
    size_t output_len,
    int32_t *out_installed
);
ssize_t nntmux_tmux_start_format_ready_channel(
    const char *hex_ptr,
    size_t hex_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_tmux_start_format_attach_hint(
    const char *session_ptr,
    size_t session_len,
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
    protected $signature = 'tmux:start
                            {--session= : Tmux session name}
                            {--force : Force start even if session exists}
                            {--attach : Attach to session after starting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start tmux processing session (modernized)';

    private TmuxSessionManager $sessionManager;

    private TmuxLayoutBuilder $layoutBuilder;

    private bool $sessionCreated = false;

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

    public static function verifyWhichTmuxOutput(bool $successful, string $output): bool
    {
        if (self::$useRustFfi) {
            return self::verifyWhichTmuxOutputNative($successful, $output);
        }

        return self::verifyWhichTmuxOutputPhp($successful, $output);
    }

    public static function verifyWhichTmuxOutputPhp(bool $successful, string $output): bool
    {
        return $successful && str_contains($output, 'tmux');
    }

    public static function verifyWhichTmuxOutputNative(bool $successful, string $output): bool
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::verifyWhichTmuxOutputPhp($successful, $output);
        }

        try {
            $outInstalled = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_tmux_start_verify_which_output(
                $successful ? 1 : 0,
                $output,
                strlen($output),
                $outInstalled
            );
            $val = $outInstalled[0];
            if ($rc !== 1 || ! is_int($val)) {
                return self::verifyWhichTmuxOutputPhp($successful, $output);
            }

            return $val === 1;
        } catch (Throwable) {
            return self::verifyWhichTmuxOutputPhp($successful, $output);
        }
    }

    public static function formatReadyChannelToken(string $hex): string
    {
        if (self::$useRustFfi) {
            return self::formatReadyChannelTokenNative($hex);
        }

        return self::formatReadyChannelTokenPhp($hex);
    }

    public static function formatReadyChannelTokenPhp(string $hex): string
    {
        return 'nntmux-ready-'.$hex;
    }

    public static function formatReadyChannelTokenNative(string $hex): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatReadyChannelTokenPhp($hex);
        }

        try {
            $cap = max(64, strlen($hex) + 32);
            $outBuf = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_start_format_ready_channel(
                $hex,
                strlen($hex),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatReadyChannelTokenPhp($hex);
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::formatReadyChannelTokenPhp($hex);
        }
    }

    public static function formatAttachCommandHint(string $sessionName): string
    {
        if (self::$useRustFfi) {
            return self::formatAttachCommandHintNative($sessionName);
        }

        return self::formatAttachCommandHintPhp($sessionName);
    }

    public static function formatAttachCommandHintPhp(string $sessionName): string
    {
        return '💡 To attach, run: php artisan tmux:attach --session='.escapeshellarg($sessionName);
    }

    public static function formatAttachCommandHintNative(string $sessionName): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::formatAttachCommandHintPhp($sessionName);
        }

        try {
            $cap = max(128, strlen($sessionName) * 4 + 96);
            $outBuf = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_start_format_attach_hint(
                $sessionName,
                strlen($sessionName),
                $outBuf,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::formatAttachCommandHintPhp($sessionName);
            }

            return FFI::string($outBuf, $written);
        } catch (Throwable) {
            return self::formatAttachCommandHintPhp($sessionName);
        }
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            cli()->header('Starting Tmux Processing');

            // Get session name
            $sessionName = $this->option('session')
                ?? app(ConfigurationProvider::class)->tmux()->sessionName;

            // Initialize services
            $this->sessionManager = new TmuxSessionManager($sessionName);
            $this->layoutBuilder = new TmuxLayoutBuilder($this->sessionManager);

            // Check if tmux is installed
            if (! $this->checkTmuxInstalled()) {
                $this->error('❌ tmux is not installed');

                return Command::FAILURE;
            }

            // Check if session already exists
            if ($this->sessionManager->sessionExists()) {
                if (! $this->option('force')) {
                    $this->error("❌ Session '{$sessionName}' already exists");
                    if (! $this->confirm('Would you like to restart it?', false)) {
                        return Command::FAILURE;
                    }
                }
                if ($this->call('tmux:stop', ['--session' => $sessionName, '--force' => true]) !== Command::SUCCESS) {
                    $this->error('❌ Existing session could not stop safely. Restart cancelled.');

                    return Command::FAILURE;
                }
            }

            // Reset old collections
            $this->info('🔄 Resetting old collections...');
            $this->resetOldCollections();

            // Get sequential mode
            $sequential = app(ConfigurationProvider::class)->tmux()->sequentialMode;
            $this->info("📐 Building layout (mode: {$sequential})");

            // Build the tmux layout
            if (! $this->layoutBuilder->buildLayout($sequential)) {
                $this->error('❌ Failed to build tmux layout: '.($this->layoutBuilder->lastError() ?? 'unknown error'));

                return Command::FAILURE;
            }

            $this->info('✅ Tmux layout created');
            $this->sessionCreated = true;

            // Set running flag
            $runtimeState = app(ProcessingRuntimeStateRepository::class);
            $runtimeState->requestStop(false);
            $runtimeState->setTmuxRunning(true);
            $this->info('✅ Running flag set');

            foreach (MetadataSources::logSummary() as $line) {
                $this->warn('  ⚠ '.$line);
            }

            // Start monitor in background
            $this->info('🚀 Starting monitor...');
            $this->startMonitor($sessionName);

            // Select monitor pane so attach lands there
            $paneManager = new TmuxPaneManager($sessionName);
            if (! $paneManager->selectWindow(0)
                || ! $paneManager->selectPane($paneManager->paneForRole(TmuxPaneRole::Monitor, '0.0'))) {
                throw new \RuntimeException($paneManager->lastError() ?? 'Unable to select the tmux monitor pane.');
            }

            $this->info("✅ Tmux session '{$sessionName}' started successfully");

            // Attach if requested
            if ($this->option('attach')) {
                $this->info('📎 Attaching to session...');
                if (! $this->sessionManager->attachSession()) {
                    $this->error('❌ Unable to attach to the tmux session.');

                    return Command::FAILURE;
                }
            } else {
                $this->info(self::formatAttachCommandHint($sessionName));
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            if ($this->sessionCreated && isset($this->sessionManager)) {
                $this->sessionManager->killSession();
                app(ProcessingRuntimeStateRepository::class)->setTmuxRunning(false);
            }

            $this->error('❌ Failed to start tmux: '.$e->getMessage());
            logger()->error('Tmux start error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Check if tmux is installed
     */
    private function checkTmuxInstalled(): bool
    {
        $result = Process::timeout(5)
            ->run('which tmux 2>/dev/null');

        return self::verifyWhichTmuxOutput($result->successful(), $result->output());
    }

    /**
     * Reset old collections
     */
    private function resetOldCollections(): void
    {
        $delayTime = app(ConfigurationProvider::class)->ingestion()->collectionDelayHours;

        $count = app(CollectionDowntimeCompensator::class)->compensate($delayTime);
        $this->info($count > 0
            ? "  ✓ Gave {$count} waiting collections back the time lost while the indexer was down"
            : '  ✓ No collections needed more time');
    }

    /**
     * Start the monitor process
     */
    private function startMonitor(string $sessionName): void
    {
        $paneManager = new TmuxPaneManager($sessionName);

        $command = TmuxCommand::monitor($sessionName);
        $channel = self::formatReadyChannelToken(bin2hex(random_bytes(12)));
        $command[] = '--ready-channel='.$channel;

        $monitorPane = $paneManager->paneForRole(TmuxPaneRole::Monitor, '0.0');
        if (! $paneManager->respawnPane($monitorPane, $command)) {
            throw new \RuntimeException('Unable to start the tmux monitor pane.');
        }
        $ready = Process::timeout(30)->run(TmuxCommand::arguments(['wait-for', $channel]));
        if (! $ready->successful()) {
            throw new \RuntimeException('Tmux monitor did not acknowledge readiness.');
        }
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
