<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TmuxPaneRole;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Tmux\TmuxPaneManager;
use App\Services\Tmux\TmuxSessionManager;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class TmuxAttach extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_tmux_attach_resolve_session_name(
    uint8_t has_option,
    const char *option_ptr,
    size_t option_len,
    const char *default_ptr,
    size_t default_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_tmux_attach_format_attaching_message(
    const char *name_ptr,
    size_t name_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_tmux_attach_format_session_not_found_message(
    const char *name_ptr,
    size_t name_len,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: resolving one session name is too small for FFI to beat PHP by 1.5x. */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tmux:attach
                            {--session= : Tmux session name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Attach to the tmux processing session';

    public static function useRustFfi(bool $enabled = true): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = self::RUST_FFI_DEFAULT;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    public static function resolveSessionName(?string $optionSession, string $defaultSession): string
    {
        return self::$useRustFfi ? self::resolveSessionNameNative($optionSession, $defaultSession) : self::resolveSessionNamePhp($optionSession, $defaultSession);
    }

    public static function resolveSessionNamePhp(?string $optionSession, string $defaultSession): string
    {
        return $optionSession ?? $defaultSession;
    }

    public static function resolveSessionNameNative(?string $optionSession, string $defaultSession): string
    {
        $option = $optionSession ?? '';
        $cap = max(strlen($option), strlen($defaultSession)) + 1;
        $result = self::callNative(static function (FFI $ffi) use ($optionSession, $option, $defaultSession, $cap): ?string {
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_attach_resolve_session_name(
                $optionSession !== null ? 1 : 0,
                $option,
                strlen($option),
                $defaultSession,
                strlen($defaultSession),
                $out,
                $cap
            );

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($out, $written) : null;
        });

        return is_string($result) ? $result : self::resolveSessionNamePhp($optionSession, $defaultSession);
    }

    public static function formatAttachingMessage(string $sessionName): string
    {
        return self::$useRustFfi ? self::formatAttachingMessageNative($sessionName) : self::formatAttachingMessagePhp($sessionName);
    }

    public static function formatAttachingMessagePhp(string $sessionName): string
    {
        return "Attaching to tmux session: {$sessionName}";
    }

    public static function formatAttachingMessageNative(string $sessionName): string
    {
        $cap = strlen($sessionName) + 32;
        $result = self::callNative(static function (FFI $ffi) use ($sessionName, $cap): ?string {
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_attach_format_attaching_message($sessionName, strlen($sessionName), $out, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($out, $written) : null;
        });

        return is_string($result) ? $result : self::formatAttachingMessagePhp($sessionName);
    }

    public static function formatSessionNotFoundMessage(string $sessionName): string
    {
        return self::$useRustFfi ? self::formatSessionNotFoundMessageNative($sessionName) : self::formatSessionNotFoundMessagePhp($sessionName);
    }

    public static function formatSessionNotFoundMessagePhp(string $sessionName): string
    {
        return "Tmux session \"{$sessionName}\" does not exist.";
    }

    public static function formatSessionNotFoundMessageNative(string $sessionName): string
    {
        $cap = strlen($sessionName) + 40;
        $result = self::callNative(static function (FFI $ffi) use ($sessionName, $cap): ?string {
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_attach_format_session_not_found_message($sessionName, strlen($sessionName), $out, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($out, $written) : null;
        });

        return is_string($result) ? $result : self::formatSessionNotFoundMessagePhp($sessionName);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $option = $this->option('session');
        $optionSession = is_string($option) ? $option : null;
        // The configured default is only loaded when --session is omitted.
        $sessionName = self::resolveSessionName(
            $optionSession,
            $optionSession === null ? app(ConfigurationProvider::class)->tmux()->sessionName : ''
        );

        $sessionManager = new TmuxSessionManager($sessionName);

        if (! $sessionManager->sessionExists()) {
            $this->error("❌ Session '{$sessionName}' does not exist");
            $this->info("💡 Run 'php artisan tmux:start' to create it");

            return Command::FAILURE;
        }

        $this->info("📎 Attaching to session '{$sessionName}'...");
        $this->info('💡 Press Ctrl+A then D to detach');

        // Select monitor pane before attaching so user lands there
        $paneManager = new TmuxPaneManager($sessionName);
        try {
            $monitorPane = $paneManager->paneForRole(TmuxPaneRole::Monitor, '0.0');
        } catch (RuntimeException $exception) {
            $this->error('❌ '.$exception->getMessage());

            return Command::FAILURE;
        }

        if (! $paneManager->selectWindow(0) || ! $paneManager->selectPane($monitorPane)) {
            $this->error('❌ Unable to select the tmux monitor pane.');

            return Command::FAILURE;
        }

        return $sessionManager->attachSession() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param  callable(FFI): mixed  $call
     */
    private static function callNative(callable $call): mixed
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            return $call($ffi);
        } catch (Throwable) {
            return null;
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
