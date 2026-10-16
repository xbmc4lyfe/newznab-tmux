<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\BenchmarkRename;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\RenameBenchmarkFixtures;
use Tests\TestCase;

class RenameBenchmarkHarnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(RenameBenchmarkFixtures::class)) {
            require_once base_path('tests/Fixtures/RenameBenchmarkFixtures.php');
        }
        if (! class_exists(BenchmarkRename::class)) {
            require_once base_path('app/Console/Commands/BenchmarkRename.php');
        }
    }

    #[Test]
    public function test_benchmark_rename_command_runs_cleanly_on_test_environment(): void
    {
        $this->artisan('benchmark:rename', [
            '--samples' => 2,
            '--warmup' => 1,
            '--format' => 'both',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Starting Rename Benchmark Harness')
            ->expectsOutputToContain('OVERALL (100 Vectors)')
            ->expectsOutputToContain('Accuracy Verification: MATCH')
            ->expectsOutputToContain(RenameBenchmarkFixtures::ORACLE_FULL_SHA256)
            ->expectsOutputToContain(RenameBenchmarkFixtures::ORACLE_FULL_CRC32);
    }

    #[Test]
    public function test_warmup_iterations_are_discarded(): void
    {
        $durationsNs = [100_000, 200_000, 300_000];
        $stats = BenchmarkRename::computeStatistics($durationsNs, 20);

        // Warmup runs are untimed and must never pollute sample array
        $this->assertSame(3, $stats['count']);
        $this->assertSame(0.1, $stats['min_ms']);
        $this->assertSame(0.2, $stats['p50_ms']);
        $this->assertSame(0.3, $stats['max_ms']);
    }

    #[Test]
    public function test_seeded_ordering_is_deterministic(): void
    {
        $dataset = RenameBenchmarkFixtures::dataset();

        // Identical seeds must produce strictly identical item orders
        $run1 = BenchmarkRename::seededShuffle($dataset, 20261016);
        $run2 = BenchmarkRename::seededShuffle($dataset, 20261016);
        $this->assertSame($run1, $run2);

        // Different seeds must produce distinct item orders
        $run3 = BenchmarkRename::seededShuffle($dataset, 19990101);
        $this->assertNotSame($run1, $run3);

        // Successive sample iterations must produce distinct item orders
        $iter0 = BenchmarkRename::seededShuffle($dataset, 20261016 + 0);
        $iter1 = BenchmarkRename::seededShuffle($dataset, 20261016 + 1);
        $iter2 = BenchmarkRename::seededShuffle($dataset, 20261016 + 2);
        $this->assertNotSame($iter0, $iter1);
        $this->assertNotSame($iter1, $iter2);
        $this->assertNotSame($iter0, $iter2);
    }

    #[Test]
    public function test_seeded_shuffle_preserves_dataset_integrity(): void
    {
        $dataset = RenameBenchmarkFixtures::dataset();
        $shuffled = BenchmarkRename::seededShuffle($dataset, 20261016);

        // Count must match exactly
        $this->assertCount(count($dataset), $shuffled);

        // All original item IDs must be preserved (multiset equality)
        $originalIds = array_column($dataset, 'id');
        $shuffledIds = array_column($shuffled, 'id');
        sort($originalIds);
        sort($shuffledIds);
        $this->assertSame($originalIds, $shuffledIds);

        // Boundary cases: empty and single-element arrays
        $this->assertSame([], BenchmarkRename::seededShuffle([], 20261016));
        $single = [['id' => 1, 'name' => 'single']];
        $this->assertSame($single, BenchmarkRename::seededShuffle($single, 20261016));
    }

    #[Test]
    public function test_safety_guard_aborts_on_production_environment(): void
    {
        $originalEnv = config('app.env');
        config(['app.env' => 'production']);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('FATAL: Benchmark cannot execute in production environment.');
            BenchmarkRename::assertExecutionSafety();
        } finally {
            config(['app.env' => $originalEnv]);
        }
    }

    #[Test]
    #[DataProvider('productionEnvironmentProvider')]
    public function test_safety_guard_aborts_on_various_production_environment_casing(string $env): void
    {
        $originalEnv = config('app.env');
        config(['app.env' => $env]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('FATAL: Benchmark cannot execute in production environment.');
            BenchmarkRename::assertExecutionSafety();
        } finally {
            config(['app.env' => $originalEnv]);
        }
    }

    #[Test]
    public function test_safety_guard_aborts_on_production_database_host(): void
    {
        $conn = config('database.default', 'mysql');
        $originalHost = config("database.connections.{$conn}.host");
        config(["database.connections.{$conn}.host" => '192.168.1.110']);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('FATAL: Target database resolves to production host 192.168.1.110.');
            BenchmarkRename::assertExecutionSafety();
        } finally {
            config(["database.connections.{$conn}.host" => $originalHost]);
        }
    }

    #[Test]
    #[DataProvider('productionDatabaseHostProvider')]
    public function test_safety_guard_aborts_on_production_database_targets(string $connection, string $configKey, string $targetValue): void
    {
        $originalDefault = config('database.default');
        $originalValue = config("database.connections.{$connection}.{$configKey}");

        config(["database.connections.{$connection}.{$configKey}" => $targetValue]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('FATAL: Target database resolves to production host 192.168.1.110.');
            BenchmarkRename::assertExecutionSafety();
        } finally {
            config([
                'database.default' => $originalDefault,
                "database.connections.{$connection}.{$configKey}" => $originalValue,
            ]);
        }
    }

    #[Test]
    public function test_safety_guard_aborts_when_default_is_sqlite_but_mysql_points_to_production(): void
    {
        $originalDefault = config('database.default');
        $originalMysqlHost = config('database.connections.mysql.host');

        config([
            'database.default' => 'testing',
            'database.connections.testing.host' => null,
            'database.connections.mysql.host' => '192.168.1.110:3306',
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('FATAL: Target database resolves to production host 192.168.1.110.');
            BenchmarkRename::assertExecutionSafety();
        } finally {
            config([
                'database.default' => $originalDefault,
                'database.connections.mysql.host' => $originalMysqlHost,
            ]);
        }
    }

    #[Test]
    public function test_safety_guard_command_exits_with_code_75_on_production_config(): void
    {
        $originalEnv = config('app.env');
        config(['app.env' => 'production']);

        try {
            $this->artisan('benchmark:rename')
                ->assertExitCode(75)
                ->expectsOutputToContain('FATAL: Benchmark cannot execute in production environment.');
        } finally {
            config(['app.env' => $originalEnv]);
        }
    }

    #[Test]
    #[DataProvider('productionEnvironmentProvider')]
    public function test_artisan_command_exits_with_code_75_on_production_environment(string $env): void
    {
        $originalEnv = config('app.env');
        config(['app.env' => $env]);

        try {
            $this->artisan('benchmark:rename')
                ->assertExitCode(75)
                ->expectsOutputToContain('FATAL: Benchmark cannot execute in production environment.');
        } finally {
            config(['app.env' => $originalEnv]);
        }
    }

    #[Test]
    public function test_artisan_command_format_json_emits_valid_json_without_banner(): void
    {
        $exitCode = Artisan::call('benchmark:rename', [
            '--samples' => 1,
            '--warmup' => 0,
            '--format' => 'json',
        ]);
        $this->assertSame(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringNotContainsString('Starting Rename Benchmark Harness', $output);
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertArrayHasKey('metadata', $decoded);
        $this->assertArrayHasKey('configuration', $decoded);
        $this->assertArrayHasKey('accuracy', $decoded);
        $this->assertArrayHasKey('strata', $decoded);
        $this->assertArrayHasKey('overall', $decoded);
        $this->assertTrue($decoded['accuracy']['verified']);
    }

    /**
     * @param  array<string, string>  $envVars
     */
    #[Test]
    #[DataProvider('standaloneScriptSafetyBypassVectorsProvider')]
    public function test_standalone_script_aborts_with_code_75_on_production_indicators(array $envVars, string $expectedStderr): void
    {
        $process = new Process(
            [PHP_BINARY, base_path('scripts/benchmark-rename.php'), '--samples=1', '--warmup=0'],
            base_path(),
            $envVars
        );
        $process->run();

        $this->assertSame(75, $process->getExitCode(), 'Process stdout: '.$process->getOutput().' | stderr: '.$process->getErrorOutput());
        $this->assertStringContainsString($expectedStderr, $process->getErrorOutput());
    }

    #[Test]
    public function test_standalone_script_executes_successfully_on_clean_test_environment(): void
    {
        $process = new Process(
            [PHP_BINARY, base_path('scripts/benchmark-rename.php'), '--samples=1', '--warmup=0', '--format=json'],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'testing',
                'DB_DATABASE' => ':memory:',
            ]
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), 'Process output: '.$process->getOutput().' | Error: '.$process->getErrorOutput());
        $json = json_decode($process->getOutput(), true);
        $this->assertIsArray($json);
        $this->assertTrue($json['accuracy']['verified']);
        $this->assertSame(RenameBenchmarkFixtures::ORACLE_FULL_SHA256, $json['accuracy']['oracle_sha256']);
        $this->assertSame(RenameBenchmarkFixtures::ORACLE_FULL_CRC32, $json['accuracy']['oracle_crc32']);
    }

    #[Test]
    public function test_standalone_script_has_matching_cli_arguments(): void
    {
        /** @var Kernel $kernel */
        $kernel = app(Kernel::class);
        $command = $kernel->all()['benchmark:rename'] ?? null;
        $this->assertNotNull($command, 'Artisan command benchmark:rename must be registered.');

        $definition = $command->getDefinition();
        $expectedOptions = ['samples', 'warmup', 'order-seed', 'tier', 'stratum', 'format', 'output'];

        foreach ($expectedOptions as $opt) {
            $this->assertTrue($definition->hasOption($opt), "Artisan command missing option: --{$opt}");
        }

        $process = new Process(
            [PHP_BINARY, base_path('scripts/benchmark-rename.php'), '--help'],
            base_path()
        );
        $process->run();
        $this->assertSame(0, $process->getExitCode());
        $helpOutput = $process->getOutput();

        foreach ($expectedOptions as $opt) {
            $this->assertStringContainsString("--{$opt}", $helpOutput, "Standalone script help missing option: --{$opt}");
        }
    }

    #[Test]
    public function test_accuracy_validation_detects_corruptions(): void
    {
        $oracle = RenameBenchmarkFixtures::expectedOracle();
        $this->assertTrue(RenameBenchmarkFixtures::verifyAccuracy($oracle));

        // Corrupt single character
        $corrupt1 = $oracle;
        $corrupt1[1] = 'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-NonX';
        $this->assertFalse(RenameBenchmarkFixtures::verifyAccuracy($corrupt1));

        // Turn resolvable string into null
        $corrupt2 = $oracle;
        $corrupt2[1] = null;
        $this->assertFalse(RenameBenchmarkFixtures::verifyAccuracy($corrupt2));

        // Turn null rejection into false-positive string
        $corrupt3 = $oracle;
        $corrupt3[81] = 'FalsePositive.Release.Name';
        $this->assertFalse(RenameBenchmarkFixtures::verifyAccuracy($corrupt3));
    }

    #[Test]
    public function test_fixtures_match_canonical_oracle_checksums(): void
    {
        $dataset = RenameBenchmarkFixtures::dataset();
        $this->assertCount(100, $dataset);

        $strata = RenameBenchmarkFixtures::strata();
        $this->assertCount(5, $strata);

        foreach ($strata as $key => $info) {
            $items = array_filter($dataset, static fn (array $i): bool => $i['category'] === $key);
            $this->assertCount($info['count'], $items);
        }

        $oracle = RenameBenchmarkFixtures::expectedOracle();
        $json = json_encode($oracle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);
        $this->assertSame(3563, strlen($json));
        $this->assertSame(RenameBenchmarkFixtures::ORACLE_FULL_CRC32, sprintf('%08x', crc32($json)));
        $this->assertSame(RenameBenchmarkFixtures::ORACLE_FULL_SHA256, hash('sha256', $json));

        $resolved = RenameBenchmarkFixtures::resolvedOracle();
        $this->assertCount(70, $resolved);
        $resolvedJson = json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($resolvedJson);
        $this->assertSame(3262, strlen($resolvedJson));
        $this->assertSame(RenameBenchmarkFixtures::ORACLE_RESOLVED_CRC32, sprintf('%08x', crc32($resolvedJson)));
        $this->assertSame(RenameBenchmarkFixtures::ORACLE_RESOLVED_SHA256, hash('sha256', $resolvedJson));
    }

    #[Test]
    public function test_statistical_computation_accuracy(): void
    {
        // 5 samples: 100ms, 200ms, 300ms, 400ms, 500ms
        $durationsNs = [100_000_000, 200_000_000, 300_000_000, 400_000_000, 500_000_000];
        $stats = BenchmarkRename::computeStatistics($durationsNs, 10);

        $this->assertSame(5, $stats['count']);
        $this->assertSame(100.0, $stats['min_ms']);
        $this->assertSame(300.0, $stats['p50_ms']);
        $this->assertSame(500.0, $stats['max_ms']);
        $this->assertSame(300.0, $stats['mean_ms']);
        $this->assertSame(200.0, $stats['iqr_ms']); // p75 (400) - p25 (200) = 200
        $this->assertSame(33.33, $stats['ops_per_sec']); // 50 items / 1.5 sec = 33.33
    }

    /**
     * @return array<string, array{string}>
     */
    public static function productionEnvironmentProvider(): array
    {
        return [
            'lowercase production' => ['production'],
            'uppercase PRODUCTION' => ['PRODUCTION'],
            'mixed-case Production' => ['Production'],
            'abbreviation prod' => ['prod'],
            'uppercase abbreviation PROD' => ['PROD'],
            'padded whitespace' => ['  production  '],
            'padded abbreviation' => ['  prod  '],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function productionDatabaseHostProvider(): array
    {
        return [
            'mysql exact IP' => ['mysql', 'host', '192.168.1.110'],
            'mysql IP with port' => ['mysql', 'host', '192.168.1.110:3306'],
            'mysql alternate port' => ['mysql', 'host', '192.168.1.110:3307'],
            'mysql hostname m5stephen' => ['mysql', 'host', 'm5stephen'],
            'mysql hostname m5stephen with port' => ['mysql', 'host', 'm5stephen:3306'],
            'mysql hostname m5stephen FQDN' => ['mysql', 'host', 'm5stephen.local:3306'],
            'mysql database url with IP' => ['mysql', 'url', 'mysql://root:secret@192.168.1.110:3306/nntmux'],
            'mysql database url with hostname' => ['mysql', 'url', 'mysql://root:secret@m5stephen:3306/nntmux'],
        ];
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function standaloneScriptSafetyBypassVectorsProvider(): array
    {
        return [
            'APP_ENV lowercase' => [
                ['APP_ENV' => 'production'],
                'FATAL: Benchmark cannot execute in production environment.',
            ],
            'APP_ENV uppercase' => [
                ['APP_ENV' => 'PRODUCTION'],
                'FATAL: Benchmark cannot execute in production environment.',
            ],
            'APP_ENV mixed-case' => [
                ['APP_ENV' => 'Production'],
                'FATAL: Benchmark cannot execute in production environment.',
            ],
            'APP_ENV abbreviation' => [
                ['APP_ENV' => 'prod'],
                'FATAL: Benchmark cannot execute in production environment.',
            ],
            'DB_HOST exact IP' => [
                ['DB_HOST' => '192.168.1.110'],
                'FATAL: Target database resolves to production host 192.168.1.110.',
            ],
            'DB_HOST IP with port' => [
                ['DB_HOST' => '192.168.1.110:3306'],
                'FATAL: Target database resolves to production host 192.168.1.110.',
            ],
            'DB_HOST hostname' => [
                ['DB_HOST' => 'm5stephen'],
                'FATAL: Target database resolves to production host 192.168.1.110.',
            ],
            'DATABASE_URL with IP' => [
                ['DATABASE_URL' => 'mysql://root:secret@192.168.1.110:3306/nntmux'],
                'FATAL: Target database resolves to production host 192.168.1.110.',
            ],
        ];
    }
}
