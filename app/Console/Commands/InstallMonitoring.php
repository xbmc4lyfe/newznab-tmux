<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

#[AsCommand(name: 'monitoring:install')]
class InstallMonitoring extends Command
{
    private const array SUPPORTED_UBUNTU = ['22.04', '24.04'];

    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_install_monitoring_is_valid_web_server(const char *value_ptr, size_t value_len);
int32_t nntmux_install_monitoring_is_supported_ubuntu_version(const char *value_ptr, size_t value_len);
ssize_t nntmux_install_monitoring_project_relative_path(
    const char *base_ptr,
    size_t base_len,
    const char *path_ptr,
    size_t path_len,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: these one-shot checks are too small for FFI to beat PHP by 1.5x. */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $signature = 'monitoring:install
                            {--sail : Configure Sail (docker-compose.monitoring.yml) instead of this host}
                            {--run : Run the installer with sudo after the checks}
                            {--web-server= : nginx or apache (detected by the installer when omitted)}';

    /**
     * @var string
     */
    protected $description = 'Check prerequisites and install Prometheus + Grafana monitoring (Ubuntu host or Sail)';

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

    public static function isValidWebServer(string $webServer): bool
    {
        return self::$useRustFfi ? self::isValidWebServerNative($webServer) : self::isValidWebServerPhp($webServer);
    }

    public static function isValidWebServerPhp(string $webServer): bool
    {
        return in_array($webServer, ['nginx', 'apache'], true);
    }

    public static function isValidWebServerNative(string $webServer): bool
    {
        // @phpstan-ignore method.notFound
        $result = self::callNative(static fn (FFI $ffi): mixed => $ffi->nntmux_install_monitoring_is_valid_web_server($webServer, strlen($webServer)));

        return match ($result) {
            1 => true,
            0 => false,
            default => self::isValidWebServerPhp($webServer),
        };
    }

    public static function isSupportedUbuntuVersion(string $version): bool
    {
        return self::$useRustFfi ? self::isSupportedUbuntuVersionNative($version) : self::isSupportedUbuntuVersionPhp($version);
    }

    public static function isSupportedUbuntuVersionPhp(string $version): bool
    {
        return in_array($version, self::SUPPORTED_UBUNTU, true);
    }

    public static function isSupportedUbuntuVersionNative(string $version): bool
    {
        // @phpstan-ignore method.notFound
        $result = self::callNative(static fn (FFI $ffi): mixed => $ffi->nntmux_install_monitoring_is_supported_ubuntu_version($version, strlen($version)));

        return match ($result) {
            1 => true,
            0 => false,
            default => self::isSupportedUbuntuVersionPhp($version),
        };
    }

    public static function formatProjectRelativePath(string $basePath, string $path): string
    {
        return self::$useRustFfi ? self::formatProjectRelativePathNative($basePath, $path) : self::formatProjectRelativePathPhp($basePath, $path);
    }

    public static function formatProjectRelativePathPhp(string $basePath, string $path): string
    {
        $base = rtrim($basePath, '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    public static function formatProjectRelativePathNative(string $basePath, string $path): string
    {
        $cap = strlen($path) + 1;
        $result = self::callNative(static function (FFI $ffi) use ($basePath, $path, $cap): ?string {
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_install_monitoring_project_relative_path($basePath, strlen($basePath), $path, strlen($path), $out, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($out, $written) : null;
        });

        return is_string($result) ? $result : self::formatProjectRelativePathPhp($basePath, $path);
    }

    public function handle(): int
    {
        return $this->option('sail') ? $this->configureSail() : $this->prepareHostInstall();
    }

    private function prepareHostInstall(): int
    {
        $script = base_path('scripts/install-monitoring.sh');
        $webServer = $this->option('web-server');

        if ($webServer !== null && (! is_string($webServer) || ! self::isValidWebServer($webServer))) {
            $this->components->error('--web-server must be nginx or apache.');

            return self::FAILURE;
        }

        if (! File::exists($this->laravel->environmentFilePath())) {
            $this->components->error('No .env file found; finish the NNTmux install first.');

            return self::FAILURE;
        }

        $release = $this->ubuntuRelease();
        if ($release === null || ! self::isSupportedUbuntuVersion($release)) {
            $this->components->warn('The installer supports Ubuntu '.implode(' and ', self::SUPPORTED_UBUNTU).'; this host reports '.($release === null ? 'a non-Ubuntu OS' : "Ubuntu {$release}").'.');
        }

        // Same detection the installer runs, so the suggested flags match what it will do.
        $this->components->info('Detecting existing monitoring components');
        $detection = Process::path(base_path())->timeout(60)->run(['bash', $script, '--detect', '--app-path='.base_path()]);
        $this->output->write($detection->output());
        if (! $detection->successful()) {
            $this->output->write($detection->errorOutput());
        }

        $command = ['sudo', 'bash', $script, '--app-path='.base_path()];
        if ($webServer !== null) {
            $command[] = '--web-server='.$webServer;
        }

        $this->newLine();
        $this->components->info('Run the installer as root:');
        $this->line('  '.implode(' ', array_map('escapeshellarg', $command)));
        $this->line('  Add --dry-run to preview, or --node-exporter-url=… if an existing exporter needs credentials (see --help).');
        $this->components->warn('The Laravel scheduler must be running for `monitoring:export-metrics` to push NNTmux metrics every minute.');

        if (! $this->option('run')) {
            return self::SUCCESS;
        }

        $result = Process::path(base_path())->forever()->tty(SymfonyProcess::isTtySupported())->run($command, function (string $type, string $output): void {
            $this->output->write($output);
        });

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function configureSail(): int
    {
        $keyDirectory = storage_path('app/monitoring');
        $privateKey = $keyDirectory.'/grafana-jwt.key';
        // Only the public/ directory is mounted into the Grafana container.
        $publicKey = $keyDirectory.'/public/grafana-jwt.pub';

        File::ensureDirectoryExists($keyDirectory.'/public', 0755);

        if (File::exists($privateKey) && File::exists($publicKey)) {
            $this->components->info('Keeping the existing Grafana JWT keypair in '.$keyDirectory);
        } else {
            [$private, $public] = $this->generateKeypair();
            File::put($privateKey, $private);
            File::chmod($privateKey, 0600);
            File::put($publicKey, $public);
            // Grafana runs as its own user inside the container and only needs the public key.
            File::chmod($publicKey, 0644);
            $this->components->info('Generated a Grafana JWT keypair in '.$keyDirectory);
        }

        $this->setEnvironmentValues([
            'MONITORING_ENABLED' => 'true',
            'GRAFANA_URL' => '/grafana',
            // docker/8.5/nginx.conf authenticates /grafana/ with auth_request.
            'GRAFANA_AUTH' => 'proxy',
            'GRAFANA_JWT_PRIVATE_KEY_PATH' => self::formatProjectRelativePath(base_path(), $privateKey),
            'MONITORING_PUSHGATEWAY_URL' => 'http://pushgateway:9091',
        ]);

        $this->components->info('Next steps:');
        $this->line('  just build          # picks up the /grafana/ proxy in docker/8.5/nginx.conf');
        $this->line('  just up             # now also starts Prometheus, Grafana, the Pushgateway and exporters');
        $this->line('  ./sail artisan config:clear');

        return self::SUCCESS;
    }

    /**
     * @return array{string, string}
     */
    private function generateKeypair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if ($key === false || ! openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Unable to generate an RSA keypair: '.(openssl_error_string() ?: 'unknown OpenSSL error'));
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false) {
            throw new RuntimeException('Unable to read the generated RSA public key.');
        }

        return [$private, $details['key']];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function setEnvironmentValues(array $values): void
    {
        $path = $this->laravel->environmentFilePath();

        if (! File::exists($path)) {
            throw new RuntimeException("No .env file at [{$path}].");
        }

        $contents = File::get($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents) === 1
                ? (string) preg_replace($pattern, $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";

            $this->line("  .env: {$line}");
        }

        File::put($path, $contents);
    }

    private function ubuntuRelease(): ?string
    {
        if (! is_readable('/etc/os-release')) {
            return null;
        }

        $release = parse_ini_file('/etc/os-release');

        if (! is_array($release) || ($release['ID'] ?? null) !== 'ubuntu') {
            return null;
        }

        return isset($release['VERSION_ID']) ? (string) $release['VERSION_ID'] : null;
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
