<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NameFixing\Extractors\ObfuscatedSubjectExtractor;
use App\Services\NameFixing\FileNameCleaner;
use App\Support\RustPipelineLibrary;
use App\Traits\DetectsHashedNames;
use FFI;
use Illuminate\Console\Command;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Tests\Fixtures\RenameBenchmarkFixtures;
use Throwable;

class BenchmarkRename extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w31_benchmark_binaries_cbp_reset_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const RUST_FFI_DEFAULT = false;

    private static bool $useRustFfi = self::RUST_FFI_DEFAULT;

    private static FFI|false|null $ffi = null;

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

    private const STATISTICS_KEYS = ['count', 'min_ms', 'p50_ms', 'p95_ms', 'p99_ms', 'max_ms', 'mean_ms', 'stddev_ms', 'iqr_ms', 'ops_per_sec'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'benchmark:rename
                            {--samples=31 : Number of measurement sample trials (minimum 1)}
                            {--warmup=5 : Number of warmup iterations (discarded from statistics)}
                            {--order-seed=20261016 : Seed for deterministic PRNG randomization}
                            {--tier=all : Benchmark tier to execute: micro, macro, or all}
                            {--stratum=all : Specific stratum to benchmark (clean_scene, obfuscated, unicode, multi_archive, noise, all)}
                            {--format=table : Output format: table, json, or both}
                            {--output= : Custom path for machine-readable JSON artifact output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute reproducible, isolated rename backlog benchmark harness with statistical percentile reporting';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! class_exists(RenameBenchmarkFixtures::class)) {
            require_once base_path('tests/Fixtures/RenameBenchmarkFixtures.php');
        }

        try {
            self::assertExecutionSafety();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return 75;
        }

        $samples = max(1, (int) $this->option('samples'));
        $warmup = max(0, (int) $this->option('warmup'));
        $orderSeed = (int) $this->option('order-seed');
        $tier = (string) $this->option('tier');
        $selectedStratum = (string) $this->option('stratum');
        $format = (string) $this->option('format');
        $outputPath = $this->option('output');

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
            $this->error("Unknown stratum: {$selectedStratum}. Available: all, ".implode(', ', array_keys($strataDefs)));

            return 1;
        }

        if ($format !== 'json') {
            $this->info(sprintf(
                'Starting Rename Benchmark Harness [tier=%s, samples=%d, warmup=%d, seed=%d]',
                $tier,
                $samples,
                $warmup,
                $orderSeed
            ));
        }

        // 1. Warmup cycles (strictly discarded from timing measurements)
        if ($warmup > 0) {
            for ($w = 0; $w < $warmup; $w++) {
                $warmupItems = self::seededShuffle($dataset, $orderSeed + 100_000 + $w);
                foreach ($warmupItems as $item) {
                    RenameBenchmarkFixtures::processItem($item, $cleaner, $obf, $detector);
                }
            }
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        // 2. Accuracy validation over entire 100-vector dataset
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

        // 3. Timed measurement runs per stratum
        $strataResults = [];
        foreach ($strataToRun as $stratumKey) {
            $stratumItems = array_values(array_filter($dataset, static fn (array $i): bool => $i['category'] === $stratumKey));
            $itemCount = count($stratumItems);

            $durationsNs = [];
            for ($s = 0; $s < $samples; $s++) {
                $iterationItems = self::seededShuffle($stratumItems, $orderSeed + $s);
                $t0 = hrtime(true);
                foreach ($iterationItems as $item) {
                    RenameBenchmarkFixtures::processItem($item, $cleaner, $obf, $detector);
                }
                $t1 = hrtime(true);
                $durationsNs[] = (int) ($t1 - $t0);
            }

            $stats = self::computeStatistics($durationsNs, $itemCount);
            $strataResults[$stratumKey] = [
                'name' => $strataDefs[$stratumKey]['name'],
                'category' => $strataDefs[$stratumKey]['category'],
                'items' => $itemCount,
                'stats' => $stats,
            ];
        }

        // Also record overall 100-item benchmark
        $overallDurationsNs = [];
        for ($s = 0; $s < $samples; $s++) {
            $iterationItems = self::seededShuffle($dataset, $orderSeed + $s);
            $t0 = hrtime(true);
            foreach ($iterationItems as $item) {
                RenameBenchmarkFixtures::processItem($item, $cleaner, $obf, $detector);
            }
            $t1 = hrtime(true);
            $overallDurationsNs[] = (int) ($t1 - $t0);
        }
        $overallStats = self::computeStatistics($overallDurationsNs, count($dataset));

        // Format Console Table
        if ($format === 'table' || $format === 'both') {
            $tableRows = [];
            foreach ($strataResults as $res) {
                $tableRows[] = self::projectBenchmarkTableRow($res['name'], [
                    'items' => $res['items'],
                    'stats' => $res['stats'],
                    'accuracy_verified' => $accuracyVerified,
                ]);
            }
            $tableRows[] = self::projectBenchmarkTableRow('OVERALL (100 Vectors)', [
                'items' => count($dataset),
                'stats' => $overallStats,
                'accuracy_verified' => $accuracyVerified,
            ]);

            $this->newLine();
            $this->table(
                ['Stratum', 'Items', 'Ops/sec', 'Min (ms)', 'p50 (ms)', 'p95 (ms)', 'p99 (ms)', 'Max (ms)', 'Accuracy'],
                $tableRows
            );
            $this->info(sprintf(
                'Accuracy Verification: %s (Mismatches: %d/100)',
                $accuracyVerified ? 'MATCH' : 'MISMATCH',
                $mismatches
            ));
            $this->line('Oracle SHA256: '.RenameBenchmarkFixtures::ORACLE_FULL_SHA256);
            $this->line('Oracle CRC32: '.RenameBenchmarkFixtures::ORACLE_FULL_CRC32);
        }

        // Format JSON payload
        $jsonPayload = [
            'metadata' => [
                'timestamp' => now()->toIso8601String(),
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
            $this->line((string) $encodedJson);
        }

        if (is_string($outputPath) && $outputPath !== '') {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($outputPath, $encodedJson);
            if ($format !== 'json') {
                $this->info("Benchmark artifact saved to: {$outputPath}");
            }
        }

        return $accuracyVerified ? 0 : 1;
    }

    /**
     * Compute statistical percentiles and throughput from raw observations.
     *
     * @param  list<int>  $durationsNs
     * @return array{
     *     count: int,
     *     min_ms: float,
     *     p50_ms: float,
     *     p95_ms: float,
     *     p99_ms: float,
     *     max_ms: float,
     *     mean_ms: float,
     *     stddev_ms: float,
     *     iqr_ms: float,
     *     ops_per_sec: float
     * }
     */
    public static function computeStatistics(array $durationsNs, int $itemsPerSample): array
    {
        return self::$useRustFfi ? self::computeStatisticsNative($durationsNs, $itemsPerSample) : self::computeStatisticsPhp($durationsNs, $itemsPerSample);
    }

    /**
     * @param  list<int>  $durationsNs
     * @return array{
     *     count: int,
     *     min_ms: float,
     *     p50_ms: float,
     *     p95_ms: float,
     *     p99_ms: float,
     *     max_ms: float,
     *     mean_ms: float,
     *     stddev_ms: float,
     *     iqr_ms: float,
     *     ops_per_sec: float
     * }
     */
    public static function computeStatisticsNative(array $durationsNs, int $itemsPerSample): array
    {
        if (self::callNative(['benchmark_rename.compute_statistics', $durationsNs, $itemsPerSample], $result)
            && is_array($result) && array_keys($result) === self::STATISTICS_KEYS && is_int($result['count'])) {
            $stats = ['count' => $result['count']];
            foreach (array_slice(self::STATISTICS_KEYS, 1) as $key) {
                if (! is_float($result[$key])) {
                    return self::computeStatisticsPhp($durationsNs, $itemsPerSample);
                }
                $stats[$key] = $result[$key];
            }

            /** @var array{count: int, min_ms: float, p50_ms: float, p95_ms: float, p99_ms: float, max_ms: float, mean_ms: float, stddev_ms: float, iqr_ms: float, ops_per_sec: float} $stats */
            return $stats;
        }

        return self::computeStatisticsPhp($durationsNs, $itemsPerSample);
    }

    /**
     * @param  list<int>  $durationsNs
     * @return array{
     *     count: int,
     *     min_ms: float,
     *     p50_ms: float,
     *     p95_ms: float,
     *     p99_ms: float,
     *     max_ms: float,
     *     mean_ms: float,
     *     stddev_ms: float,
     *     iqr_ms: float,
     *     ops_per_sec: float
     * }
     */
    public static function computeStatisticsPhp(array $durationsNs, int $itemsPerSample): array
    {
        $count = count($durationsNs);
        if ($count === 0) {
            return [
                'count' => 0,
                'min_ms' => 0.0,
                'p50_ms' => 0.0,
                'p95_ms' => 0.0,
                'p99_ms' => 0.0,
                'max_ms' => 0.0,
                'mean_ms' => 0.0,
                'stddev_ms' => 0.0,
                'iqr_ms' => 0.0,
                'ops_per_sec' => 0.0,
            ];
        }

        $timesMs = array_map(static fn (int $ns): float => $ns / 1_000_000.0, $durationsNs);
        sort($timesMs);

        $min = $timesMs[0];
        $max = $timesMs[$count - 1];
        $mean = array_sum($timesMs) / $count;

        $p50 = $count % 2 === 0
            ? ($timesMs[(int) ($count / 2) - 1] + $timesMs[(int) ($count / 2)]) / 2.0
            : $timesMs[(int) floor($count / 2)];

        $p95 = $timesMs[(int) min($count - 1, (int) ceil(0.95 * $count) - 1)];
        $p99 = $timesMs[(int) min($count - 1, (int) ceil(0.99 * $count) - 1)];
        $p25 = $timesMs[(int) min($count - 1, (int) ceil(0.25 * $count) - 1)];
        $p75 = $timesMs[(int) min($count - 1, (int) ceil(0.75 * $count) - 1)];
        $iqr = $p75 - $p25;

        $variance = 0.0;
        if ($count > 1) {
            $sumSq = 0.0;
            foreach ($timesMs as $val) {
                $sumSq += ($val - $mean) ** 2;
            }
            $variance = $sumSq / ($count - 1);
        }
        $stddev = sqrt($variance);

        $totalNs = array_sum($durationsNs);
        $totalOps = $itemsPerSample * $count;
        $opsPerSec = $totalNs > 0 ? ($totalOps * 1_000_000_000.0) / $totalNs : 0.0;

        return [
            'count' => $count,
            'min_ms' => round($min, 6),
            'p50_ms' => round($p50, 6),
            'p95_ms' => round($p95, 6),
            'p99_ms' => round($p99, 6),
            'max_ms' => round($max, 6),
            'mean_ms' => round($mean, 6),
            'stddev_ms' => round($stddev, 6),
            'iqr_ms' => round($iqr, 6),
            'ops_per_sec' => round($opsPerSec, 2),
        ];
    }

    /**
     * Deterministically shuffle an array of items using a seeded Fisher-Yates algorithm.
     *
     * An isolated PRNG engine is initialized with the provided seed so that global PRNG state
     * is not mutated, and identical seeds produce identical permutations.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function seededShuffle(array $items, int $seed): array
    {
        $count = count($items);
        if ($count <= 1) {
            return array_values($items);
        }

        if (class_exists(Randomizer::class) && class_exists(Mt19937::class)) {
            $randomizer = new Randomizer(new Mt19937($seed));

            /** @var list<T> $shuffled */
            $shuffled = $randomizer->shuffleArray(array_values($items));

            return $shuffled;
        }

        $shuffled = array_values($items);
        mt_srand($seed);
        for ($i = $count - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $shuffled[$i];
            $shuffled[$i] = $shuffled[$j];
            $shuffled[$j] = $tmp;
        }

        return $shuffled;
    }

    /**
     * Hard production safety assertion guard.
     *
     * @throws RuntimeException if production access or production database host is detected
     */
    public static function assertExecutionSafety(): void
    {
        // 1. Environment check (normalized, case-insensitive, trimmed, including 'prod')
        $candidateEnvs = [
            config('app.env'),
            getenv('APP_ENV') !== false ? getenv('APP_ENV') : null,
            $_SERVER['APP_ENV'] ?? null,
            $_ENV['APP_ENV'] ?? null,
        ];

        foreach ($candidateEnvs as $rawEnv) {
            if ($rawEnv !== null && $rawEnv !== false && $rawEnv !== ''
                && self::isBlockedProductionContext((string) $rawEnv, '', '')) {
                throw new RuntimeException('FATAL: Benchmark cannot execute in production environment.');
            }
        }

        if (function_exists('app') && app()->bound('env') && app()->environment('production', 'prod')) {
            throw new RuntimeException('FATAL: Benchmark cannot execute in production environment.');
        }

        // 2. Database target checks (all connections, host, url, read/write, port-stripped)
        $targets = [];

        // Check raw environment variables
        foreach (['DB_HOST', 'DATABASE_URL'] as $envKey) {
            $raw = getenv($envKey);
            if ($raw !== false && $raw !== '') {
                $targets[] = (string) $raw;
            }
            if (isset($_SERVER[$envKey]) && is_string($_SERVER[$envKey]) && $_SERVER[$envKey] !== '') {
                $targets[] = $_SERVER[$envKey];
            }
            if (isset($_ENV[$envKey]) && is_string($_ENV[$envKey]) && $_ENV[$envKey] !== '') {
                $targets[] = $_ENV[$envKey];
            }
        }

        // Check all configured database connections
        $connections = (array) config('database.connections', []);
        if (! isset($connections['mysql']) && config('database.connections.mysql')) {
            $connections['mysql'] = (array) config('database.connections.mysql');
        }

        foreach ($connections as $conn) {
            if (! is_array($conn)) {
                continue;
            }

            if (isset($conn['host'])) {
                if (is_array($conn['host'])) {
                    foreach ($conn['host'] as $h) {
                        if (is_string($h) && $h !== '') {
                            $targets[] = $h;
                        }
                    }
                } elseif (is_string($conn['host']) && $conn['host'] !== '') {
                    $targets[] = $conn['host'];
                }
            }

            if (isset($conn['url']) && is_string($conn['url']) && $conn['url'] !== '') {
                $targets[] = $conn['url'];
            }

            foreach (['read', 'write'] as $rw) {
                if (isset($conn[$rw]['host'])) {
                    $rwHost = $conn[$rw]['host'];
                    if (is_array($rwHost)) {
                        foreach ($rwHost as $h) {
                            if (is_string($h) && $h !== '') {
                                $targets[] = $h;
                            }
                        }
                    } elseif (is_string($rwHost) && $rwHost !== '') {
                        $targets[] = $rwHost;
                    }
                }
            }
        }

        foreach ($targets as $target) {
            if (self::isBlockedProductionContext('', $target, '')) {
                throw new RuntimeException('FATAL: Target database resolves to production host 192.168.1.110.');
            }
        }
    }

    /**
     * Whether a normalized application environment or database host/target names production.
     */
    public static function isBlockedProductionContext(string $appEnv, string $dbHost, string $dbDatabase): bool
    {
        return self::$useRustFfi
            ? self::isBlockedProductionContextNative($appEnv, $dbHost, $dbDatabase)
            : self::isBlockedProductionContextPhp($appEnv, $dbHost, $dbDatabase);
    }

    public static function isBlockedProductionContextPhp(string $appEnv, string $dbHost, string $dbDatabase): bool
    {
        if (in_array(strtolower(trim($appEnv)), ['production', 'prod'], true)) {
            return true;
        }

        foreach ([$dbHost, $dbDatabase] as $target) {
            $targetLower = strtolower(trim($target));
            $strippedHost = explode(':', $targetLower)[0];

            if (
                $strippedHost === '192.168.1.110'
                || str_contains($targetLower, '192.168.1.110')
                || str_contains($targetLower, 'm5stephen')
            ) {
                return true;
            }
        }

        return false;
    }

    public static function isBlockedProductionContextNative(string $appEnv, string $dbHost, string $dbDatabase): bool
    {
        if (self::callNative(['benchmark_rename.is_blocked_production_context', $appEnv, $dbHost, $dbDatabase], $result) && is_bool($result)) {
            return $result;
        }

        return self::isBlockedProductionContextPhp($appEnv, $dbHost, $dbDatabase);
    }

    /**
     * Console table row for one benchmark result.
     *
     * @param  array{items: int, stats: array{ops_per_sec: float, min_ms: float, p50_ms: float, p95_ms: float, p99_ms: float, max_ms: float}, accuracy_verified: bool}  $result
     * @return list<int|string>
     */
    public static function projectBenchmarkTableRow(string $method, array $result): array
    {
        return self::$useRustFfi ? self::projectBenchmarkTableRowNative($method, $result) : self::projectBenchmarkTableRowPhp($method, $result);
    }

    /**
     * @param  array{items: int, stats: array{ops_per_sec: float, min_ms: float, p50_ms: float, p95_ms: float, p99_ms: float, max_ms: float}, accuracy_verified: bool}  $result
     * @return list<int|string>
     */
    public static function projectBenchmarkTableRowPhp(string $method, array $result): array
    {
        $s = $result['stats'];

        return [
            $method,
            $result['items'],
            number_format($s['ops_per_sec'], 1),
            number_format($s['min_ms'], 4),
            number_format($s['p50_ms'], 4),
            number_format($s['p95_ms'], 4),
            number_format($s['p99_ms'], 4),
            number_format($s['max_ms'], 4),
            $result['accuracy_verified'] ? 'PASS' : 'FAIL',
        ];
    }

    /**
     * @param  array{items: int, stats: array{ops_per_sec: float, min_ms: float, p50_ms: float, p95_ms: float, p99_ms: float, max_ms: float}, accuracy_verified: bool}  $result
     * @return list<int|string>
     */
    public static function projectBenchmarkTableRowNative(string $method, array $result): array
    {
        if (self::callNative(['benchmark_rename.project_table_row', $method, $result], $row)
            && is_array($row) && array_is_list($row) && count($row) === 9 && is_int($row[1])) {
            $cells = [];
            foreach ($row as $index => $cell) {
                if ($index !== 1 && ! is_string($cell)) {
                    return self::projectBenchmarkTableRowPhp($method, $result);
                }
                $cells[] = $cell;
            }

            /** @var list<int|string> $cells */
            return $cells;
        }

        return self::projectBenchmarkTableRowPhp($method, $result);
    }

    /**
     * Evaluates `[operation, ...arguments]` in Rust; false means the caller keeps its PHP result.
     *
     * @param  list<mixed>  $request
     *
     * @param-out mixed $result
     */
    private static function callNative(array $request, mixed &$result = null): bool
    {
        $ffi = self::nativeFfi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $capacity = max(256, strlen($json) * 2 + 256);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = $ffi->new("uint8_t[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w31_benchmark_binaries_cbp_reset_commands($json, strlen($json), $buffer, $capacity);
                if (! is_int($written) || $written === -1 || $written === 0) {
                    return false;
                }
                if ($written < 0) {
                    $capacity = -$written;

                    continue;
                }
                $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || count($decoded) !== 1 || ! array_key_exists(0, $decoded)) {
                    return false;
                }
                $result = $decoded[0];

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function nativeFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
