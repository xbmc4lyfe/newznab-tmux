<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\InstallNntmux;
use App\Console\Commands\UpdateNNTmux;
use App\Console\Commands\UpdateNNTmuxComposer;
use App\Console\Commands\UpdateNNTmuxDB;
use App\Console\Commands\UpdateNNTmuxGit;
use FFI;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class RustInstallerUpdaterCommandsParityTest extends TestCase
{
    private const COMMANDS = [InstallNntmux::class, UpdateNNTmux::class, UpdateNNTmuxComposer::class, UpdateNNTmuxDB::class, UpdateNNTmuxGit::class];

    protected function tearDown(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(null);
            (new ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
        parent::tearDown();
    }

    private function loadLibrary(): FFI
    {
        $cdef = '';
        foreach (self::COMMANDS as $class) {
            $cdef .= (new ReflectionClass($class))->getConstant('FFI_CDEF')."\n";
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $path = dirname(__DIR__, 2).'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix;
        $this->assertFileExists($path, 'Build the release library before running native parity/benchmarks.');

        return FFI::cdef($cdef, $path);
    }

    public function test_toggles_and_missing_or_incompatible_library_fallback(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
        }
        foreach ([false, FFI::cdef('')] as $unavailable) {
            foreach (self::COMMANDS as $class) {
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $unavailable);
                $class::setUseRustFfi(true);
            }
            $this->assertSame(['driver' => '', 'host' => "\xff", 'port' => '00', 'database' => "a\0", 'username' => ''], InstallNntmux::normalizeDatabaseInstallConfig('', "\xff", '00', "a\0", ''));
            $this->assertSame(['nntmux:git', 'nntmux:composer', 'nntmux:db'], UpdateNNTmux::buildUpdateSubcommandSequence(true, false));
            $this->assertSame('c install --no-interaction --no-progress', UpdateNNTmuxComposer::buildComposerInstallCommand('testing', 'c'));
            $this->assertSame(['migrate' => ['--force' => true], 'seed' => true], UpdateNNTmuxDB::normalizeMigrationMode('production', true));
            $this->assertTrue(UpdateNNTmuxGit::isManagedBranch('codex/test'));
        }
    }

    public function test_native_and_toggle_parity_with_randomized_binary_inputs(): void
    {
        $ffi = $this->loadLibrary();
        $random = new Randomizer(new Mt19937(326));
        foreach (self::COMMANDS as $class) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, $ffi);
        }
        for ($i = 0; $i < 250; $i++) {
            $bytes = $i === 0 ? '' : $random->getBytes($i);
            $port = $i % 2 === 0 ? $i - 125 : $bytes;
            $cases = [
                [InstallNntmux::class, 'normalizeDatabaseInstallConfig', [$bytes, $bytes, $port, $bytes, $bytes]],
                [UpdateNNTmux::class, 'buildUpdateSubcommandSequence', [($i & 1) !== 0, ($i & 2) !== 0]],
                [UpdateNNTmuxComposer::class, 'buildComposerInstallCommand', [$i % 3 === 0 ? 'production' : $bytes, $bytes]],
                [UpdateNNTmuxDB::class, 'normalizeMigrationMode', [$i % 3 === 0 ? 'production' : $bytes, ($i & 1) !== 0]],
                [UpdateNNTmuxGit::class, 'isManagedBranch', [$bytes]],
            ];
            foreach ($cases as [$class, $method, $args]) {
                $php = $method.'Php';
                $native = $method.'Native';
                $expected = $class::$php(...$args);
                $this->assertSame($expected, $class::$native(...$args));
                foreach ([false, true, null] as $toggle) {
                    $class::setUseRustFfi($toggle);
                    $this->assertSame($expected, $class::$method(...$args));
                }
            }
        }
    }

    public function test_raw_exports_reject_invalid_inputs_and_leave_short_buffers_untouched(): void
    {
        $ffi = $this->loadLibrary();
        $out = $ffi->new('uint8_t[256]');
        FFI::memset($out, 90, 256);
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_installer_managed_branch('feature/x', 9));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_installer_managed_branch('', 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_installer_managed_branch(null, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(3, $ffi->nntmux_installer_migration_mode('production', 10, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_installer_migration_mode('', 0, 2));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_installer_migration_mode(null, 0, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_installer_database_config('bad', 3, $out, 256));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_installer_update_sequence(2, 0, $out, 256));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_installer_composer_command(null, 0, 'c', 1, $out, 256));
        // @phpstan-ignore method.notFound
        $this->assertSame(-44, $ffi->nntmux_installer_update_sequence(0, 0, $out, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(-39, $ffi->nntmux_installer_composer_command('', 0, '', 0, $out, 1));
        $frame = str_repeat(pack('N', 0), 5);
        // @phpstan-ignore method.notFound
        $this->assertSame(-20, $ffi->nntmux_installer_database_config($frame, 20, $out, 19));
        $this->assertSame(str_repeat('Z', 256), FFI::string($out, 256));
        // @phpstan-ignore method.notFound
        $this->assertSame(20, $ffi->nntmux_installer_database_config($frame, 20, $out, 20));
        $this->assertSame($frame, FFI::string($out, 20));
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_installer_composer_command('production', 10, 'c', 1, $out, 256);
        $this->assertSame('c install --no-interaction --no-progress --optimize-autoloader --classmap-authoritative', FFI::string($out, $written));
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_installer_update_sequence(1, 1, $out, 256);
        $this->assertSame("nntmux:git\nnntmux:composer\nnntmux:db\ndb:seed", FFI::string($out, $written));
    }

    public function test_composer_caller_preserves_action_option_and_flag_order_in_both_modes(): void
    {
        $this->loadLibrary();
        foreach ([false, true] as $toggle) {
            UpdateNNTmuxComposer::setUseRustFfi($toggle);
            foreach (['production', 'testing', 'Production'] as $env) {
                $this->app->instance('env', $env);
                foreach (['install', 'update'] as $action) {
                    for ($flags = 0; $flags < 8; $flags++) {
                        $command = new UpdateNNTmuxComposer;
                        $command->setLaravel($this->app);
                        $command->setInput(new ArrayInput([
                            '--no-dev' => ($flags & 1) !== 0,
                            '--prefer-dist' => ($flags & 2) !== 0,
                            '--optimize' => ($flags & 4) !== 0,
                        ], $command->getDefinition()));
                        $expected = 'composer '.$action.' --no-interaction --no-progress';
                        if (($flags & 1) !== 0) {
                            $expected .= ' --no-dev';
                        }
                        if (($flags & 2) !== 0) {
                            $expected .= ' --prefer-dist';
                        }
                        if (($flags & 4) !== 0 || $env === 'production') {
                            $expected .= ' --optimize-autoloader --classmap-authoritative';
                        }
                        $this->assertSame($expected, (new ReflectionMethod($command, 'buildComposerCommand'))->invoke($command, $action));
                    }
                }
            }
        }
    }

    public function test_migration_callers_keep_separate_commands_and_force_flags(): void
    {
        $this->loadLibrary();
        foreach ([false, true] as $toggle) {
            UpdateNNTmuxDB::setUseRustFfi($toggle);
            foreach (['production', 'testing', 'Production'] as $env) {
                $this->app->instance('env', $env);
                $command = new class extends UpdateNNTmuxDB
                {
                    /** @var list<array{mixed, array<string, mixed>}> */
                    public array $calls = [];

                    /** @param array<string, mixed> $arguments */
                    public function call($command, array $arguments = []): int
                    {
                        $this->calls[] = [$command, $arguments];

                        return 0;
                    }
                };
                $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
                (new ReflectionMethod(UpdateNNTmuxDB::class, 'runMigrations'))->invoke($command);
                (new ReflectionMethod(UpdateNNTmuxDB::class, 'runSeeders'))->invoke($command);
                $options = $env === 'production' ? ['--force' => true] : [];
                $this->assertSame([['migrate', $options], ['db:seed', $options]], $command->calls);
            }
        }
    }

    public function test_installer_caller_only_changes_selected_driver_and_preserves_other_env_fields(): void
    {
        $this->loadLibrary();
        $files = File::getFacadeRoot();
        foreach ([false, true] as $toggle) {
            InstallNntmux::setUseRustFfi($toggle);
            config(['database.default' => 'mariadb', 'database.connections.mariadb' => [
                'host' => " host\0", 'port' => '003306', 'database' => 'db', 'username' => 'user',
            ]]);
            $writes = [];
            File::shouldReceive('exists')->andReturnTrue();
            File::shouldReceive('get')->with(base_path('.env'))->andReturn("DB_CONNECTION=mysql\nDB_HOST=keep\n");
            File::shouldReceive('put')->withArgs(static function (string $path, string $contents) use (&$writes): bool {
                $writes[] = [$path, $contents];

                return true;
            })->andReturn(1);
            $command = new class extends InstallNntmux
            {
                /** @var list<mixed> */
                public array $calls = [];

                /** @param array<string, mixed> $arguments */
                public function call($command, array $arguments = []): int
                {
                    $this->calls[] = $command;

                    return 0;
                }
            };
            $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
            (new ReflectionMethod(InstallNntmux::class, 'configureDatabaseDriver'))->invoke($command, true);
            $this->assertSame([[base_path('.env'), "DB_CONNECTION=mariadb\nDB_HOST=keep\n"]], $writes);
            $this->assertSame(['config:clear'], $command->calls);
            $this->assertSame('mariadb', config('database.default'));
            File::swap($files);
        }
    }

    /**
     * Reproduce with NNTMUX_INSTALLER_UPDATER_BENCH=1 php artisan test --compact
     * tests/Unit/RustInstallerUpdaterCommandsParityTest.php. Raw pairs and runtime
     * metadata are retained in the reported temporary JSON artifact.
     * The Apple Silicon/PHP 8.5 release-library comparison failed the 1.5x
     * common mean and uncommon p99 gate for all five helpers; PHP stays default.
     */
    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_INSTALLER_UPDATER_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_INSTALLER_UPDATER_BENCH=1 for paired performance measurements.');
        }
        $ffi = $this->loadLibrary();
        $random = new Randomizer(new Mt19937(3206));
        $large = str_repeat("é\0\xff ", 1024);
        $workloads = [
            [InstallNntmux::class, 'normalizeDatabaseInstallConfig', ['mysql', 'localhost', 3306, 'nntmux', 'root'], [$large, $large, '003306', $large, $large]],
            [UpdateNNTmux::class, 'buildUpdateSubcommandSequence', [false, false], [true, true]],
            [UpdateNNTmuxComposer::class, 'buildComposerInstallCommand', ['production', 'composer'], ["production\0", $large]],
            [UpdateNNTmuxDB::class, 'normalizeMigrationMode', ['production', false], [$large, true]],
            [UpdateNNTmuxGit::class, 'isManagedBranch', ['master'], [$large]],
        ];
        $results = [];
        foreach ($workloads as [$class, $method, $common, $uncommon]) {
            (new ReflectionProperty($class, 'ffi'))->setValue(null, $ffi);
            foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $args) {
                $php = $method.'Php';
                $native = $method.'Native';
                $expected = $class::$php(...$args);
                $this->assertSame($expected, $class::$native(...$args));
                foreach ([false, true] as $toggle) {
                    $class::setUseRustFfi($toggle);
                    for ($warmup = 0; $warmup < 200; $warmup++) {
                        $class::$method(...$args);
                    }
                }
                $raw = ['php' => [], 'rust' => []];
                // Three rounds of 2000 paired individual-call timings give 6000
                // observations per mode/workload (60 samples in the p99 tail).
                for ($round = 0; $round < 3; $round++) {
                    for ($sample = 0; $sample < 2000; $sample++) {
                        $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                        foreach ($order as $toggle) {
                            $class::setUseRustFfi($toggle);
                            $start = hrtime(true);
                            $actual = $class::$method(...$args);
                            $elapsed = hrtime(true) - $start;
                            $raw[$toggle ? 'rust' : 'php'][] = $elapsed;
                            $this->assertSame($expected, $actual);
                        }
                    }
                }
                $summary = [];
                foreach ($raw as $mode => $samples) {
                    sort($samples);
                    $summary[$mode] = ['mean_ns' => array_sum($samples) / count($samples), 'p99_ns' => $samples[(int) ceil(count($samples) * 0.99) - 1], 'samples' => count($samples)];
                }
                $meanSpeedup = $summary['php']['mean_ns'] / max(1, $summary['rust']['mean_ns']);
                $p99Speedup = $summary['php']['p99_ns'] / max(1, $summary['rust']['p99_ns']);
                $class::setUseRustFfi(null);
                if ($meanSpeedup < 1.5 || $p99Speedup < 1.5) {
                    $this->assertFalse($class::usesRustFfi(), $class.' did not qualify for Rust adoption');
                }
                $key = $class.'::'.$method.'/'.$kind;
                $results[$key] = ['summary' => $summary, 'mean_speedup' => $meanSpeedup, 'p99_speedup' => $p99Speedup, 'input_hash' => hash('sha256', serialize($args)), 'raw_ns' => $raw];
                fwrite(STDERR, sprintf("\n%s mean=%.3fx p99=%.3fx", $key, $meanSpeedup, $p99Speedup));
            }
        }
        $sourceHashes = [];
        foreach (self::COMMANDS as $class) {
            $source = (new ReflectionClass($class))->getFileName();
            $sourceHashes[$class] = is_string($source) ? hash_file('sha256', $source) : false;
        }
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-w32-6-bench-');
        $this->assertNotFalse($artifact);
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $library = dirname(__DIR__, 2).'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix;
        $this->assertNotFalse(file_put_contents($artifact, json_encode([
            'php' => PHP_VERSION, 'os' => php_uname(), 'utc' => gmdate(DATE_ATOM),
            'jit' => ini_get('opcache.jit'), 'profile' => 'cargo release',
            'source_hashes' => $sourceHashes, 'library_sha256' => hash_file('sha256', $library),
            'rust_source_sha256' => hash_file('sha256', dirname(__DIR__, 2).'/rust/pipeline-ffi/src/additional.rs'),
            'lock_sha256' => hash_file('sha256', dirname(__DIR__, 2).'/composer.lock'),
            'warmup_per_mode' => 200, 'rounds' => 3, 'pairs_per_round' => 2000,
            'random_seed' => 3206, 'results' => $results,
        ], JSON_THROW_ON_ERROR)));
        fwrite(STDERR, "\nRaw benchmark evidence: {$artifact}\n");
    }

    public function test_composer_install_retains_exact_environment_and_command_bytes(): void
    {
        foreach ([
            ['local', 'composer', 'composer install --no-interaction --no-progress'],
            ['production', 'composer', 'composer install --no-interaction --no-progress --optimize-autoloader --classmap-authoritative'],
            ['Production', '', ' install --no-interaction --no-progress'],
            ["production\0", "php composer.phar\0", "php composer.phar\0 install --no-interaction --no-progress"],
        ] as [$env, $command, $expected]) {
            foreach (['Php', 'Native', ''] as $suffix) {
                $method = 'buildComposerInstallCommand'.$suffix;
                $this->assertSame($expected, UpdateNNTmuxComposer::$method($env, $command));
            }
        }
    }

    public function test_migration_mode_preserves_separate_seeding_and_exact_production_check(): void
    {
        foreach (['production', 'testing', '', 'PRODUCTION', "production\0", "\xff"] as $env) {
            foreach ([false, true] as $seed) {
                $expected = ['migrate' => $env === 'production' ? ['--force' => true] : [], 'seed' => $seed];
                foreach (['Php', 'Native', ''] as $suffix) {
                    $method = 'normalizeMigrationMode'.$suffix;
                    $this->assertSame($expected, UpdateNNTmuxDB::$method($env, $seed));
                }
            }
        }
    }

    public function test_database_config_preserves_types_and_arbitrary_bytes(): void
    {
        foreach ([
            ['mysql', 'localhost', 3306, 'nntmux', 'root'],
            ['MariaDB ', " host\0", '03306', '', "u\xff"],
            ['', '', -1, "db\n", ''],
        ] as $args) {
            $expected = array_combine(['driver', 'host', 'port', 'database', 'username'], $args);
            foreach (['Php', 'Native', ''] as $suffix) {
                $method = 'normalizeDatabaseInstallConfig'.$suffix;
                $this->assertSame($expected, InstallNntmux::$method(...$args));
            }
        }
    }

    public function test_update_sequence_keeps_maintenance_ownership_and_optional_seed_order(): void
    {
        foreach ([false, true] as $down) {
            foreach ([false, true] as $seed) {
                $expected = ['nntmux:git', 'nntmux:composer', 'nntmux:db'];
                if ($seed) {
                    $expected[] = 'db:seed';
                }
                if (! $down) {
                    array_unshift($expected, 'down');
                    $expected[] = 'up';
                }
                foreach (['Php', 'Native', ''] as $suffix) {
                    $method = 'buildUpdateSubcommandSequence'.$suffix;
                    $this->assertSame($expected, UpdateNNTmux::$method($down, $seed));
                }
            }
        }
    }

    public function test_managed_branch_retains_existing_nonempty_branch_policy(): void
    {
        foreach (['' => false, 'master' => true, 'develop' => true, 'feature/x' => true, ' ' => true, "a\0b" => true, "\xff" => true] as $branch => $expected) {
            foreach (['Php', 'Native', ''] as $suffix) {
                $method = 'isManagedBranch'.$suffix;
                $this->assertSame($expected, UpdateNNTmuxGit::$method($branch));
            }
        }
    }
}
