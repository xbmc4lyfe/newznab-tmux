<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class DeployInitialize extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_deploy_initialize_is_valid_search_driver(const char *driver_ptr, size_t driver_len);
ssize_t nntmux_deploy_initialize_decode_app_key(
    const char *key_ptr,
    size_t key_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_deploy_initialize_search_index_command(
    const char *driver_ptr,
    size_t driver_len,
    uint8_t recreate
);
CDEF;

    /** Rust stays opt-in: one-time initialization checks are too small for FFI to beat PHP by 1.5x. */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

    protected $signature = 'nntmux:deploy-init';

    protected $description = 'Initialize an empty deployment database without replacing data or the application key';

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

    public static function isValidSearchDriver(string $driver): bool
    {
        return self::$useRustFfi ? self::isValidSearchDriverNative($driver) : self::isValidSearchDriverPhp($driver);
    }

    public static function isValidSearchDriverPhp(string $driver): bool
    {
        return in_array($driver, ['manticore', 'elasticsearch'], true);
    }

    public static function isValidSearchDriverNative(string $driver): bool
    {
        // @phpstan-ignore method.notFound
        $result = self::callNative(static fn (FFI $ffi): mixed => $ffi->nntmux_deploy_initialize_is_valid_search_driver($driver, strlen($driver)));

        return match ($result) {
            1 => true,
            0 => false,
            default => self::isValidSearchDriverPhp($driver),
        };
    }

    /**
     * Returns the raw key bytes; an invalid `base64:` payload decodes to an empty string.
     */
    public static function decodeAppKeyValue(string $key): string
    {
        return self::$useRustFfi ? self::decodeAppKeyValueNative($key) : self::decodeAppKeyValuePhp($key);
    }

    public static function decodeAppKeyValuePhp(string $key): string
    {
        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;
    }

    public static function decodeAppKeyValueNative(string $key): string
    {
        $cap = strlen($key) + 1;
        $result = self::callNative(static function (FFI $ffi) use ($key, $cap): ?string {
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_deploy_initialize_decode_app_key($key, strlen($key), $out, $cap);

            return is_int($written) && $written >= 0 && $written <= $cap ? FFI::string($out, $written) : null;
        });

        return is_string($result) ? $result : self::decodeAppKeyValuePhp($key);
    }

    /**
     * @return array{string, array<string, bool>} The search index command and its options.
     */
    public static function resolveSearchIndexCommand(string $driver, bool $recreate): array
    {
        return self::$useRustFfi ? self::resolveSearchIndexCommandNative($driver, $recreate) : self::resolveSearchIndexCommandPhp($driver, $recreate);
    }

    /**
     * @return array{string, array<string, bool>}
     */
    public static function resolveSearchIndexCommandPhp(string $driver, bool $recreate): array
    {
        $options = ['--no-interaction' => true];
        if ($driver === 'elasticsearch') {
            if (! $recreate) {
                $options['--create-missing'] = true;
            }

            return ['nntmux:create-es-indexes', $options];
        }

        if ($recreate) {
            $options['--drop'] = true;
        }

        return ['manticore:create-indexes', $options];
    }

    /**
     * @return array{string, array<string, bool>}
     */
    public static function resolveSearchIndexCommandNative(string $driver, bool $recreate): array
    {
        // @phpstan-ignore method.notFound
        $code = self::callNative(static fn (FFI $ffi): mixed => $ffi->nntmux_deploy_initialize_search_index_command($driver, strlen($driver), $recreate ? 1 : 0));

        return match ($code) {
            0 => ['manticore:create-indexes', ['--no-interaction' => true]],
            1 => ['manticore:create-indexes', ['--no-interaction' => true, '--drop' => true]],
            2 => ['nntmux:create-es-indexes', ['--no-interaction' => true, '--create-missing' => true]],
            3 => ['nntmux:create-es-indexes', ['--no-interaction' => true]],
            default => self::resolveSearchIndexCommandPhp($driver, $recreate),
        };
    }

    public function handle(): int
    {
        $marker = base_path('_install/install.lock');
        $lock = null;

        try {
            File::ensureDirectoryExists(dirname($marker), 0770);
            $lock = fopen(dirname($marker).'/deploy-init.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another initialization is running.');
            }

            if (File::exists($marker)) {
                throw new RuntimeException('This installation is already initialized.');
            }

            $this->validateConfiguration();
            if (Schema::getTableListing() !== [] || Schema::getViews() !== []) {
                throw new RuntimeException('Initialization requires an empty database. Existing tables or views will not be changed.');
            }

            foreach ([storage_path('app/public'), storage_path('framework/cache/data'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs'), config('nntmux_settings.covers_path'), config('nntmux_settings.path_to_nzbs'), config('nntmux.tmp_unzip_path'), config('nntmux.tmp_unrar_path')] as $directory) {
                if (! is_string($directory) || $directory === '') {
                    throw new RuntimeException('Deployment storage paths must be configured.');
                }
                File::ensureDirectoryExists($directory, 0770);
                if (! is_writable($directory)) {
                    throw new RuntimeException('Deployment storage paths must be writable.');
                }
            }

            if ($this->call('migrate', ['--force' => true, '--seed' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                throw new RuntimeException('Migration or seeding failed; initialization remains incomplete.');
            }

            $this->createAdministrator();
            $admin = DB::table('users')->where('username', config('nntmux.admin_username'))->first();
            if ($admin === null || (int) $admin->roles_id !== 2 || $admin->email_verified_at === null || ! DB::table('ingestion_configurations')->where('id', 1)->exists()) {
                throw new RuntimeException('Administrator or configuration verification failed.');
            }

            // validateConfiguration() guarantees a supported string driver.
            [$searchCommand, $searchOptions] = self::resolveSearchIndexCommand((string) config('search.default'), false);
            if ($this->call($searchCommand, $searchOptions) !== self::SUCCESS) {
                throw new RuntimeException('Search initialization failed; initialization remains incomplete.');
            }

            if (File::put($marker, 'deployment initialized on '.now()) === false) {
                throw new RuntimeException('Unable to record successful initialization.');
            }

            $this->info('Deployment initialized. The supplied application key was preserved.');

            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Initialization failed. No success marker was written. Inspect the deployment and restore an empty database before retrying.');

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function validateConfiguration(): void
    {
        $driver = config('search.default');
        if (! is_string($driver) || ! self::isValidSearchDriver($driver)) {
            throw new RuntimeException('Unsupported deployment search driver.');
        }

        $key = config('app.key');
        if (! is_string($key)) {
            throw new RuntimeException('A valid APP_KEY must be supplied before initialization.');
        }
        // An invalid base64 payload decodes to '', which no cipher supports.
        $decoded = self::decodeAppKeyValue($key);
        if ($decoded === '' || ! Encrypter::supported($decoded, config('app.cipher'))) {
            throw new RuntimeException('A valid APP_KEY must be supplied before initialization.');
        }

        $username = config('nntmux.admin_username');
        $password = config('nntmux.admin_password');
        $email = config('nntmux.admin_email');
        if (! is_string($username) || trim($username) === '' || ! is_string($password) || strlen($password) < 12 || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Configure ADMIN_USER, ADMIN_EMAIL, and an ADMIN_PASS of at least 12 characters.');
        }
    }

    protected function createAdministrator(): void
    {
        DB::transaction(function (): void {
            $id = User::add(config('nntmux.admin_username'), config('nntmux.admin_password'), config('nntmux.admin_email'), 2);
            User::findOrFail($id)->markEmailAsVerified();
        });
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
