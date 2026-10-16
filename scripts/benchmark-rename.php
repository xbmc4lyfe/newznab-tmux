<?php

declare(strict_types=1);

// ============================================================================
// PRE-BOOTSTRAP SAFETY GUARD (Fail-Closed)
// Inspect raw ambient environment BEFORE setting any overrides or loading app.
// ============================================================================
$rawAppEnvs = [
    getenv('APP_ENV') !== false ? getenv('APP_ENV') : null,
    $_ENV['APP_ENV'] ?? null,
    $_SERVER['APP_ENV'] ?? null,
];

foreach ($rawAppEnvs as $rawEnvVal) {
    if (is_string($rawEnvVal) && $rawEnvVal !== '') {
        $normalizedEnv = strtolower(trim($rawEnvVal));
        if (in_array($normalizedEnv, ['production', 'prod'], true)) {
            fwrite(STDERR, 'FATAL: Benchmark cannot execute in production environment.'.PHP_EOL);
            exit(75);
        }
    }
}

$rawDbIndicators = [
    getenv('DB_HOST') !== false ? getenv('DB_HOST') : null,
    $_ENV['DB_HOST'] ?? null,
    $_SERVER['DB_HOST'] ?? null,
    getenv('DATABASE_URL') !== false ? getenv('DATABASE_URL') : null,
    $_ENV['DATABASE_URL'] ?? null,
    $_SERVER['DATABASE_URL'] ?? null,
];

foreach ($rawDbIndicators as $rawDbVal) {
    if (is_string($rawDbVal) && $rawDbVal !== '') {
        $dbValLower = strtolower(trim($rawDbVal));
        $strippedHost = explode(':', $dbValLower)[0];
        if ($strippedHost === '192.168.1.110' || str_contains($dbValLower, '192.168.1.110') || str_contains($dbValLower, 'm5stephen')) {
            fwrite(STDERR, 'FATAL: Target database resolves to production host 192.168.1.110.'.PHP_EOL);
            exit(75);
        }
    }
}

// Guard against bare execution on production host where .env defines production
$dotEnvPath = dirname(__DIR__).'/.env';
if (file_exists($dotEnvPath) && is_readable($dotEnvPath)) {
    $lines = @file($dotEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^APP_ENV\s*=\s*["\']?([^"\'#\s]+)/i', $line, $m)) {
                $val = strtolower(trim($m[1]));
                if (in_array($val, ['production', 'prod'], true)) {
                    fwrite(STDERR, 'FATAL: Benchmark cannot execute in production environment.'.PHP_EOL);
                    exit(75);
                }
            }
            if (preg_match('/^(DB_HOST|DATABASE_URL)\s*=\s*["\']?([^"\'#\s]+)/i', $line, $m)) {
                $val = strtolower(trim($m[2]));
                $stripped = explode(':', $val)[0];
                if ($stripped === '192.168.1.110' || str_contains($val, '192.168.1.110') || str_contains($val, 'm5stephen')) {
                    fwrite(STDERR, 'FATAL: Target database resolves to production host 192.168.1.110.'.PHP_EOL);
                    exit(75);
                }
            }
        }
    }
}

use App\Console\Commands\BenchmarkRename;
use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\FileNameCleaner;
use App\Traits\DetectsHashedNames;
use Illuminate\Contracts\Console\Kernel;
use Tests\Fixtures\RenameBenchmarkFixtures;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
if (file_exists(dirname(__DIR__).'/.env.test')) {
    $app->loadEnvironmentFrom('.env.test');
}

foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'testing',
    'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$app->make(Kernel::class)->bootstrap();

if (! class_exists(RenameBenchmarkFixtures::class)) {
    require_once dirname(__DIR__).'/tests/Fixtures/RenameBenchmarkFixtures.php';
}
if (! class_exists(BenchmarkRename::class)) {
    require_once dirname(__DIR__).'/app/Console/Commands/BenchmarkRename.php';
}

$options = getopt('', [
    'samples::',
    'warmup::',
    'order-seed::',
    'tier::',
    'stratum::',
    'format::',
    'output::',
    'help',
]);

if (isset($options['help'])) {
    echo <<<'HELP'
NNTmux Release Rename Benchmark Runner (Standalone CLI)

Usage:
  php scripts/benchmark-rename.php [options]

Options:
  --samples[=N]     Number of measurement iterations (default: 31)
  --warmup[=N]      Number of warmup iterations, discarded (default: 5)
  --order-seed[=N]  PRNG seed for randomized trial ordering (default: 20261016)
  --tier[=NAME]     Benchmark tier: micro, macro, all (default: all)
  --stratum[=NAME]  Workload stratum: clean_scene, obfuscated, unicode, multi_archive, noise, all (default: all)
  --format[=FMT]    Output format: table, json, both (default: table)
  --output[=PATH]   Write machine-readable JSON artifact to specified path
  --help            Display this help message

HELP;
    exit(0);
}

try {
    BenchmarkRename::assertExecutionSafety();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(75);
}

$samples = max(1, (int) ($options['samples'] ?? 31));
$warmup = max(0, (int) ($options['warmup'] ?? 5));
$orderSeed = (int) ($options['order-seed'] ?? 20261016);
$tier = (string) ($options['tier'] ?? 'all');
$selectedStratum = (string) ($options['stratum'] ?? 'all');
$format = (string) ($options['format'] ?? 'table');
$outputPath = $options['output'] ?? null;

mt_srand($orderSeed);

$cleaner = new FileNameCleaner;
$obf = new ObfuscatedSubjectExtractor;
$detector = new class
{
    use DetectsHashedNames;
};

$dataset = RenameBenchmarkFixtures::dataset();
$strataDefs = RenameBenchmarkFixtures::strata();

$strataToRun = [];
if ($selectedStratum === 'all') {
    $strataToRun = array_keys($strataDefs);
} elseif (isset($strataDefs[$selectedStratum])) {
    $strataToRun = [$selectedStratum];
} else {
    fwrite(STDERR, "Unknown stratum: {$selectedStratum}. Available: all, ".implode(', ', array_keys($strataDefs)).PHP_EOL);
    exit(1);
}

// 1. Warmup cycles (discarded)
if ($warmup > 0) {
    for ($w = 0; $w < $warmup; $w++) {
        $warmupItems = BenchmarkRename::seededShuffle($dataset, $orderSeed + 100_000 + $w);
        foreach ($warmupItems as $item) {
            RenameBenchmarkFixtures::processItem($item, $cleaner, $obf, $detector);
        }
    }
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
}

// 2. Accuracy validation against ground truth oracle
$actualOutputs = [];
foreach ($dataset as $item) {
    $actualOutputs[$item['id']] = RenameBenchmarkFixtures::processItem($item, $cleaner, $obf, $detector);
}
$accuracyVerified = RenameBenchmarkFixtures::verifyAccuracy($actualOutputs);
$mismatches = 0;
foreach ($dataset as $item) {
    if ($actualOutputs[$item['id']] !== $item['expected']) {
        $mismatches++;
    }
}

// 3. Timed measurements per stratum
$strataResults = [];
foreach ($strataToRun as $stratumKey) {
    $stratumItems = array_values(array_filter($dataset, static fn (array $i): bool => $i['category'] === $stratumKey));
    $itemCount = count($stratumItems);

    $durationsNs = [];
    for ($s = 0; $s < $samples; $s++) {
        $iterationItems = BenchmarkRename::seededShuffle($stratumItems, $orderSeed + $s);
        $t0 = hrtime(true);
        foreach ($iterationItems as $item) {
            RenameBenchmarkFixtures::processItem($item, $cleaner, $obf, $detector);
        }
        $t1 = hrtime(true);
        $durationsNs[] = (int) ($t1 - $t0);
    }

    $stats = BenchmarkRename::computeStatistics($durationsNs, $itemCount);
    $strataResults[$stratumKey] = [
        'name' => $strataDefs[$stratumKey]['name'],
        'category' => $strataDefs[$stratumKey]['category'],
        'items' => $itemCount,
        'stats' => $stats,
    ];
}

// 4. Overall 100-vector measurement
$overallDurationsNs = [];
for ($s = 0; $s < $samples; $s++) {
    $iterationItems = BenchmarkRename::seededShuffle($dataset, $orderSeed + $s);
    $t0 = hrtime(true);
    foreach ($iterationItems as $item) {
        RenameBenchmarkFixtures::processItem($item, $cleaner, $obf, $detector);
    }
    $t1 = hrtime(true);
    $overallDurationsNs[] = (int) ($t1 - $t0);
}
$overallStats = BenchmarkRename::computeStatistics($overallDurationsNs, count($dataset));

// 5. Render output
if ($format === 'table' || $format === 'both') {
    echo PHP_EOL;
    echo '======================================================================================================='.PHP_EOL;
    echo '                            NNTmux Rename Processing Benchmark Results                                '.PHP_EOL;
    echo '======================================================================================================='.PHP_EOL;
    printf("Environment: %s | PHP: %s | Tier: %s | Samples: %d | Warmup: %d | Seed: %d\n", PHP_OS, PHP_VERSION, $tier, $samples, $warmup, $orderSeed);
    echo '-------------------------------------------------------------------------------------------------------'.PHP_EOL;
    printf("%-36s %6s %13s %10s %10s %10s %10s %8s\n", 'Stratum', 'Items', 'Ops/sec', 'Min (ms)', 'p50 (ms)', 'p95 (ms)', 'p99 (ms)', 'Status');
    echo '-------------------------------------------------------------------------------------------------------'.PHP_EOL;

    foreach ($strataResults as $res) {
        $s = $res['stats'];
        printf(
            "%-36s %6d %13s %10.4f %10.4f %10.4f %10.4f %8s\n",
            $res['name'],
            $res['items'],
            number_format($s['ops_per_sec'], 1),
            $s['min_ms'],
            $s['p50_ms'],
            $s['p95_ms'],
            $s['p99_ms'],
            $accuracyVerified ? 'PASS' : 'FAIL'
        );
    }
    printf(
        "%-36s %6d %13s %10.4f %10.4f %10.4f %10.4f %8s\n",
        'OVERALL (100 Vectors)',
        count($dataset),
        number_format($overallStats['ops_per_sec'], 1),
        $overallStats['min_ms'],
        $overallStats['p50_ms'],
        $overallStats['p95_ms'],
        $overallStats['p99_ms'],
        $accuracyVerified ? 'PASS' : 'FAIL'
    );
    echo '-------------------------------------------------------------------------------------------------------'.PHP_EOL;
    printf(
        "Accuracy Checksum: %s (SHA256: %s, CRC32: %s, Mismatches: %d/100)\n",
        $accuracyVerified ? 'MATCH' : 'MISMATCH',
        RenameBenchmarkFixtures::ORACLE_FULL_SHA256,
        RenameBenchmarkFixtures::ORACLE_FULL_CRC32,
        $mismatches
    );
    echo '======================================================================================================='.PHP_EOL;
}

$jsonPayload = [
    'metadata' => [
        'timestamp' => date('c'),
        'php_version' => PHP_VERSION,
        'os' => PHP_OS,
        'tier' => $tier,
    ],
    'configuration' => [
        'samples' => $samples,
        'warmup' => $warmup,
        'order_seed' => $orderSeed,
        'stratum' => $selectedStratum,
    ],
    'accuracy' => [
        'verified' => $accuracyVerified,
        'total_checked' => count($dataset),
        'mismatches' => $mismatches,
        'oracle_sha256' => RenameBenchmarkFixtures::ORACLE_FULL_SHA256,
        'oracle_crc32' => RenameBenchmarkFixtures::ORACLE_FULL_CRC32,
    ],
    'strata' => $strataResults,
    'overall' => $overallStats,
];

$encodedJson = json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($format === 'json') {
    echo $encodedJson.PHP_EOL;
}

if (is_string($outputPath) && $outputPath !== '') {
    $dir = dirname($outputPath);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($outputPath, $encodedJson);
    if ($format !== 'json') {
        echo "Benchmark artifact saved to: {$outputPath}".PHP_EOL;
    } else {
        fwrite(STDERR, "Benchmark artifact saved to: {$outputPath}".PHP_EOL);
    }
}

exit($accuracyVerified ? 0 : 1);
