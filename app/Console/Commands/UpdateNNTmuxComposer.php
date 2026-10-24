<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class UpdateNNTmuxComposer extends Command
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_installer_composer_command(const char *env, size_t env_len, const char *command, size_t command_len, uint8_t *out, size_t cap);
CDEF;

    // The end-to-end FFI benchmark must qualify before changing this default.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }

    public static function buildComposerInstallCommand(string $appEnv, string $composerCommand = 'composer'): string
    {
        return self::usesRustFfi()
            ? self::buildComposerInstallCommandNative($appEnv, $composerCommand)
            : self::buildComposerInstallCommandPhp($appEnv, $composerCommand);
    }

    public static function buildComposerInstallCommandPhp(string $appEnv, string $composerCommand = 'composer'): string
    {
        return $composerCommand.' install --no-interaction --no-progress'.($appEnv === 'production' ? ' --optimize-autoloader --classmap-authoritative' : '');
    }

    public static function buildComposerInstallCommandNative(string $appEnv, string $composerCommand = 'composer'): string
    {
        try {
            $ffi = self::additionalFfi();
            if ($ffi !== null) {
                $cap = strlen($composerCommand) + 128;
                $out = $ffi->new("uint8_t[{$cap}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_installer_composer_command($appEnv, strlen($appEnv), $composerCommand, strlen($composerCommand), $out, $cap);
                if (is_int($written) && $written >= 0 && $written <= $cap) {
                    return FFI::string($out, $written);
                }
            }
        } catch (Throwable) {
            // A missing or incompatible native library retains the PHP behavior.
        }

        return self::buildComposerInstallCommandPhp($appEnv, $composerCommand);
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:composer
                            {--no-dev : Skip development dependencies}
                            {--optimize : Optimize autoloader}
                            {--prefer-dist : Prefer distribution packages}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update composer dependencies with optimizations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->info('📦 Starting composer update process...');

            // Check if composer.json exists
            if (! File::exists(base_path('composer.json'))) {
                $this->error('composer.json not found');

                return Command::FAILURE;
            }

            // Check if composer.lock exists to determine install vs update
            $hasLockFile = File::exists(base_path('composer.lock'));

            if ($hasLockFile) {
                $this->info('🔄 Installing dependencies from lock file...');
                $this->composerInstall();
            } else {
                $this->info('🆕 Creating new lock file and installing dependencies...');
                $this->composerUpdate();
            }

            // Clear autoloader cache
            $this->info('🧹 Clearing autoloader cache...');
            $this->clearAutoloaderCache();

            $this->info('✅ Composer update completed successfully');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Composer update failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Run composer install
     */
    private function composerInstall(): void
    {
        $command = $this->buildComposerCommand('install');

        $process = Process::timeout(600)
            ->path(base_path())
            ->run($command);

        if (! $process->successful()) {
            throw new \Exception('Composer install failed: '.$process->errorOutput());
        }

        $this->line('  ✓ Dependencies installed successfully');
    }

    /**
     * Run composer update
     */
    private function composerUpdate(): void
    {
        $command = $this->buildComposerCommand('update');

        $process = Process::timeout(600)
            ->path(base_path())
            ->run($command);

        if (! $process->successful()) {
            throw new \Exception('Composer update failed: '.$process->errorOutput());
        }

        $this->line('  ✓ Dependencies updated successfully');
    }

    /**
     * Build composer command with options
     */
    private function buildComposerCommand(string $action): string
    {
        // Add environment-specific flags after the explicit options, preserving order.
        $command = self::buildComposerInstallCommand('local');
        if ($action !== 'install') {
            $command = 'composer '.$action.substr($command, strlen('composer install'));
        }

        if ($this->option('no-dev')) {
            $command .= ' --no-dev';
        }

        if ($this->option('prefer-dist')) {
            $command .= ' --prefer-dist';
        }

        if ($this->option('optimize') || app()->environment('production')) {
            $production = self::buildComposerInstallCommand('production');
            $command .= substr($production, strlen(self::buildComposerInstallCommand('local')));
        }

        return $command;
    }

    /**
     * Clear autoloader cache
     */
    private function clearAutoloaderCache(): void
    {
        $process = Process::timeout(30)
            ->path(base_path())
            ->run('composer dump-autoload --optimize');

        if (! $process->successful()) {
            $this->warn('  ⚠ Failed to optimize autoloader');
        } else {
            $this->line('  ✓ Autoloader optimized');
        }
    }
}
