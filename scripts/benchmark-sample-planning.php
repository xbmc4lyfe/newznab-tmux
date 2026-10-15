<?php

declare(strict_types=1);

/**
 * php scripts/benchmark-sample-planning.php --native=/absolute/libnzbyenc.so
 * --runtime-manifest=/absolute/build.json --output-dir=/new/evidence
 * [--source-dir=ROOT] [--mode=screen|qualification] [--samples=31]
 * [--order-seed=20261015] [--timeout=30]
 * Qualification requires 1000 independent fresh-process pairs per stratum.
 * Build receipt keys: candidate_library_sha256 and candidate_source_sha256.
 */
use App\Services\Releases\Verification\NativeSpotCheckPlanner;
use App\Services\Releases\Verification\SpotCheckPlanner;
use Tests\Fixtures\SamplePlanningBenchmarkFixtures;

if (! function_exists('articleBenchmarkFreezeGraph')) {
    if (! defined('NNTMUX_ARTICLE_BENCHMARK_FUNCTIONS_ONLY')) {
        define('NNTMUX_ARTICLE_BENCHMARK_FUNCTIONS_ONLY', true);
    }
    require __DIR__.'/benchmark-article-processing.php';
}
require_once dirname(__DIR__).'/tests/Fixtures/SamplePlanningBenchmarkFixtures.php';
if (defined('NNTMUX_SAMPLE_BENCHMARK_FUNCTIONS_ONLY')) {
    return;
}
$options = getopt('', ['native:', 'runtime-manifest:', 'output-dir:', 'source-dir:', 'mode:', 'samples:', 'order-seed:', 'timeout:', 'worker:', 'fixture-path:', 'result-output:']);
try {
    if (isset($options['worker'])) {
        $root = realpath((string) ($options['source-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Source directory is missing.');
        require $root.'/vendor/autoload.php';
        $fixture = unserialize((string) file_get_contents((string) ($options['fixture-path'] ?? '')), ['allowed_classes' => false]);
        if (! is_array($fixture)) {
            throw new InvalidArgumentException('Worker requires a frozen fixture.');
        }
        echo json_encode(sampleBenchmarkWorker((string) $options['worker'], $fixture, (string) ($options['native'] ?? ''), $root,
            (string) ($options['result-output'] ?? '')), JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    exit(sampleBenchmarkRun($options));
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'invalid', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}

/** @return array<string,string> */
function sampleBenchmarkSources(string $root): array
{
    $paths = ['app/Services/Releases/Verification/SpotCheckPlanner.php', 'app/Services/Releases/Verification/NativeSpotCheckPlanner.php',
        'composer.json', 'composer.lock', 'docker/app/nzb-yenc-ffi/Cargo.toml', 'docker/app/nzb-yenc-ffi/Cargo.lock'];
    $native = $root.'/docker/app/nzb-yenc-ffi/src';
    if (! is_dir($native)) {
        throw new InvalidArgumentException('Native source inventory is missing.');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($native, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'rs') {
            $paths[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($paths);
    $hashes = [];
    foreach ($paths as $path) {
        $hashes[$path] = hash_file('sha256', $root.'/'.$path) ?: throw new RuntimeException('Source identity missing: '.$path);
    }

    return $hashes;
}

/**
 * @param  array<string,mixed>  $manifest
 * @param  array<string,string>  $sources
 */
function sampleBenchmarkVerifyManifest(array $manifest, array $sources, string $libraryHash): void
{
    if (($manifest['candidate_library_sha256'] ?? null) !== $libraryHash) {
        throw new RuntimeException('Build receipt does not identify the native library.');
    }
    foreach ($sources as $path => $hash) {
        if (($manifest['candidate_source_sha256'][$path] ?? null) !== $hash) {
            throw new RuntimeException('Build receipt requires source identity: '.$path);
        }
    }
}

/** @return array<string,string> */
function sampleBenchmarkInstrumentation(): array
{
    $hashes = [];
    foreach (['scripts/benchmark-sample-planning.php', 'scripts/benchmark-article-processing.php', 'scripts/benchmark-yenc.php',
        'tests/Fixtures/SamplePlanningBenchmarkFixtures.php'] as $path) {
        $hashes[$path] = hash_file('sha256', dirname(__DIR__).'/'.$path) ?: throw new RuntimeException('Instrumentation is missing.');
    }

    return $hashes;
}

/** @return array{root:string,files:array<string,string>,classes:array<string,string>} */
function sampleBenchmarkRuntime(string $root): array
{
    $allowed = array_map(static fn (string $path): string => dirname(__DIR__).'/'.$path, array_keys(sampleBenchmarkInstrumentation()));
    $files = [];
    $classes = [];
    foreach ([SpotCheckPlanner::class, NativeSpotCheckPlanner::class] as $class) {
        $path = (new ReflectionClass($class))->getFileName();
        if (! is_string($path) || ! str_starts_with($path, $root.'/')) {
            throw new RuntimeException('Planner class escaped the selected runtime.');
        }
        $classes[$class] = substr($path, strlen($root) + 1);
    }
    foreach (get_included_files() as $path) {
        $path = realpath($path) ?: throw new RuntimeException('Included runtime file is missing.');
        if (in_array($path, $allowed, true)) {
            continue;
        }
        if (! str_starts_with($path, $root.'/')) {
            throw new RuntimeException('Included file escaped selected runtime: '.$path);
        }
        $files[substr($path, strlen($root) + 1)] = hash_file('sha256', $path) ?: throw new RuntimeException('Runtime hash unavailable.');
    }
    ksort($files);

    return ['root' => $root, 'files' => $files, 'classes' => $classes];
}

/**
 * @param  array<string,mixed>  $fixture
 * @return array<string,mixed>
 */
function sampleBenchmarkWorker(string $backend, array $fixture, string $library, string $root, string $output): array
{
    if (! in_array($backend, ['php', 'native'], true) || $output === '') {
        throw new InvalidArgumentException('Worker requires a backend and serialized result output.');
    }
    $trace = new class($backend, $library) extends NativeSpotCheckPlanner
    {
        public int $calls = 0;

        public int $successes = 0;

        public function __construct(private readonly string $backend, string $library)
        {
            parent::__construct($library);
        }

        /** @param array<string,mixed> $manifest @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>|null */
        public function select(array $manifest, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes = null): ?array
        {
            $this->calls++;
            if ($this->backend === 'php') {
                return null;
            }
            $result = parent::select($manifest, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes);
            if ($result !== null) {
                $this->successes++;
            }

            return $result;
        }
    };
    $planner = new SpotCheckPlanner($trace);
    $observations = [];
    $serialized = null;
    foreach (['cold', 'warmup', 'warm'] as $phase) {
        $beforeCalls = $trace->calls;
        $beforeSuccesses = $trace->successes;
        $cpu = getrusage();
        $throttlingBefore = @file_get_contents('/sys/fs/cgroup/cpu.stat');
        $started = hrtime(true);
        $sample = $planner->select($fixture['manifest'], $fixture['maximum_articles'], $fixture['maximum_header_bytes'], $fixture['maximum_article_bytes']);
        $elapsed = (hrtime(true) - $started) / 1e6;
        $encoded = serialize($sample);
        $correct = SamplePlanningBenchmarkFixtures::correct($fixture, $sample) && ($serialized === null || $encoded === $serialized);
        $serialized ??= $encoded;
        $observations[$phase] = ['elapsed_ms' => $elapsed, 'correct' => $correct, 'native_attempts' => $trace->calls - $beforeCalls,
            'native_successes' => $trace->successes - $beforeSuccesses, 'result_sha256' => hash('sha256', $encoded),
            'process_cpu_before' => $cpu, 'process_cpu_after' => getrusage(),
            'cgroup_cpu_stat_before' => $throttlingBefore, 'cgroup_cpu_stat_after' => @file_get_contents('/sys/fs/cgroup/cpu.stat')];
    }
    if (file_put_contents($output, $serialized) === false) {
        throw new RuntimeException('Cannot retain exact serialized output.');
    }

    return ['status' => 'ok', 'backend' => $backend, 'median_ms' => $observations['warm']['elapsed_ms'], 'observations' => $observations,
        'result_path' => $output, 'result_sha256' => hash('sha256', $serialized), 'fixture_sha256' => hash('sha256', serialize($fixture)),
        'library_sha256' => $backend === 'native' ? hash_file('sha256', $library) : null,
        'runtime_sources' => sampleBenchmarkRuntime($root), 'instrumentation' => sampleBenchmarkInstrumentation(),
        'runtime' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'sapi' => PHP_SAPI, 'ffi' => ini_get('ffi.enable'),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit')]];
}

/**
 * @param  array<string,mixed>  $child
 * @param  array{root:string,files:array<string,string>,git_head:?string,git_tree:?string}  $snapshot
 * @param  array<string,string>  $instrumentation
 */
function sampleBenchmarkEligible(array $child, string $backend, bool $nativeExpected, string $fixtureHash, string $libraryHash,
    string $resultPath, array $snapshot, array $instrumentation, int $expectedAttempts = 1): bool
{
    $result = $child['result'] ?? null;
    if (($child['status'] ?? null) !== 'ok' || ($child['stdout_truncated_for_json'] ?? true) || ! is_array($result)
        || ($result['backend'] ?? null) !== $backend || ($result['fixture_sha256'] ?? null) !== $fixtureHash
        || ($result['instrumentation'] ?? null) !== $instrumentation || ! articleBenchmarkRuntimeMatches($child, $snapshot)
        || ($result['result_path'] ?? null) !== $resultPath || ! is_file($resultPath)
        || ($result['result_sha256'] ?? null) !== hash_file('sha256', $resultPath)
        || ($backend === 'native' && ($result['library_sha256'] ?? null) !== $libraryHash)) {
        return false;
    }
    foreach (['cold', 'warmup', 'warm'] as $phase) {
        $row = $result['observations'][$phase] ?? null;
        if (! is_array($row) || ($row['correct'] ?? false) !== true || ! is_numeric($row['elapsed_ms'] ?? null)
            || $row['elapsed_ms'] <= 0 || ($row['result_sha256'] ?? null) !== $result['result_sha256']
            || ($row['native_successes'] ?? null) !== ($backend === 'native' && $nativeExpected ? 1 : 0)
            || ($row['native_attempts'] ?? null) !== $expectedAttempts) {
            return false;
        }
    }

    return true;
}

/**
 * Distribution-free order-statistic interval for the population quantile.
 * Number of observations above the population quantile is Binomial(n, 1-q).
 * Null upper bound means this sample cannot bound the tail at this confidence.
 *
 * @param  list<float>  $values
 * @return array{estimate:float,interval:array{?float,?float},ranks:array{int,int},samples:int,confidence:float}
 */
function sampleBenchmarkQuantile(array $values, float $quantile, float $alpha): array
{
    if ($values === [] || $quantile <= 0 || $quantile >= 1 || $alpha <= 0 || $alpha >= 1) {
        throw new InvalidArgumentException('Invalid quantile evidence.');
    }
    sort($values);
    $n = count($values);
    $failureProbability = 1 - $quantile;
    $modeCount = (int) floor(($n + 1) * $failureProbability);
    $weights = [$modeCount => 1.0];
    for ($count = $modeCount; $count > 0; $count--) {
        $weights[$count - 1] = $weights[$count] * $count / ($n - $count + 1) * $quantile / $failureProbability;
    }
    for ($count = $modeCount; $count < $n; $count++) {
        $weights[$count + 1] = $weights[$count] * ($n - $count) / ($count + 1) * $failureProbability / $quantile;
    }
    $normalizer = array_sum($weights);
    $cumulative = 0.0;
    $lowerCount = 0;
    $upperCount = $n;
    $foundLower = false;
    for ($count = 0; $count <= $n; $count++) {
        $cumulative += $weights[$count] / $normalizer;
        if (! $foundLower && $cumulative >= $alpha / 2) {
            $lowerCount = $count;
            $foundLower = true;
        }
        if ($cumulative >= 1 - $alpha / 2) {
            $upperCount = $count;
            break;
        }
    }
    $lowRank = $n - $upperCount;
    $highRank = $n - $lowerCount + 1;

    return ['estimate' => $quantile === 0.5 ? yencBenchmarkMedian($values) : $values[max(0, (int) ceil($quantile * $n) - 1)],
        'interval' => [$lowRank >= 1 ? $values[$lowRank - 1] : null, $highRank <= $n ? $values[$highRank - 1] : null],
        'ranks' => [$lowRank, $highRank], 'samples' => $n, 'confidence' => 1 - $alpha];
}

/**
 * @param  list<float>  $baseline
 * @param  list<float>  $native
 * @return array<string,mixed>
 */
function sampleBenchmarkDecision(array $baseline, array $native, int $invalidPairs, string $category, string $mode): array
{
    if ($invalidPairs > 0 || count($baseline) !== count($native) || $baseline === []) {
        return ['status' => 'invalid', 'invalid_pairs' => $invalidPairs, 'eligible_pairs' => count($baseline), 'qualifies' => false];
    }
    $quantile = $category === 'uncommon' ? 0.99 : 0.5;
    // 4 native strata x 2 cold/warm phases x 2 marginal quantiles.
    $alpha = 0.05 / 16;
    $php = sampleBenchmarkQuantile($baseline, $quantile, $alpha);
    $rust = sampleBenchmarkQuantile($native, $quantile, $alpha);
    $low = $php['interval'][0] !== null && $rust['interval'][1] !== null ? $php['interval'][0] / $rust['interval'][1] : null;
    $high = $php['interval'][1] !== null && $rust['interval'][0] !== null ? $php['interval'][1] / $rust['interval'][0] : null;
    $qualified = $mode === 'qualification' && count($baseline) >= 1000 && $low !== null && $low >= 1.5;

    return ['status' => $qualified ? 'qualified' : 'inconclusive', 'eligible_pairs' => count($baseline), 'invalid_pairs' => 0,
        'metric' => $quantile === 0.99 ? 'p99_latency' : 'median_latency', 'php' => $php, 'native' => $rust,
        'php_over_native' => $php['estimate'] / $rust['estimate'], 'simultaneous_95_percent_speedup_interval' => [$low, $high],
        'qualifies' => $qualified, 'evidence' => $mode === 'screen' ? 'screen_only_no_adoption' : 'qualification'];
}

/** @param array<string,mixed> $options */
function sampleBenchmarkRun(array $options): int
{
    $mode = (string) ($options['mode'] ?? 'screen');
    $samples = filter_var($options['samples'] ?? ($mode === 'qualification' ? 1000 : 31), FILTER_VALIDATE_INT);
    $seed = filter_var($options['order-seed'] ?? 20261015, FILTER_VALIDATE_INT);
    $timeout = filter_var($options['timeout'] ?? 30, FILTER_VALIDATE_FLOAT);
    if (! in_array($mode, ['screen', 'qualification'], true) || $samples === false || $samples < 1 || $samples > 10000
        || ($mode === 'qualification' && $samples < 1000) || $seed === false || $seed < 1 || $seed > 2147483646
        || $timeout === false || $timeout <= 0 || $timeout > 300) {
        throw new InvalidArgumentException('Invalid protocol; qualification needs at least 1000 pairs per stratum.');
    }
    $directory = (string) ($options['output-dir'] ?? sys_get_temp_dir().'/sample-planning-'.bin2hex(random_bytes(8)));
    if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be new.');
    }
    $directory = realpath($directory) ?: throw new RuntimeException('Output directory cannot resolve.');
    $write = static function (array $row) use ($directory): void {
        $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
        file_put_contents($directory.'/observations.jsonl', $line, FILE_APPEND);
        echo $line;
    };
    $instrumentation = sampleBenchmarkInstrumentation();
    $write(['type' => 'protocol', 'protocol' => 'sample-planning-v1', 'mode' => $mode, 'samples_per_stratum' => $samples,
        'order_seed' => $seed, 'timeout_seconds' => $timeout, 'strata' => SamplePlanningBenchmarkFixtures::strata(),
        'instrumentation' => $instrumentation, 'native_proof' => 'Public caller tracing subclass counts non-null parent native results; positive budgets force FFI call before any non-null result.',
        'timing' => 'Fresh process per observation; cold public call, one warmup and one warm public call. Projection, JSON, FFI, decode and native validation are inside call timing. Process elapsed retained separately.',
        'decision_rule' => 'Zero invalid pairs; >=1000 independent pairs; simultaneous conservative 95% lower speedup >=1.5 for common median and uncommon p99, both cold and warm. Controls N/A.',
        'tail_uncertainty' => 'Distribution-free binomial order-statistic intervals; Bonferroni over 16 marginal quantiles. Screen cannot qualify adoption.']);
    if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
        $write(['type' => 'summary', 'status' => 'skipped', 'reason' => 'Real native admission requires Linux CLI with FFI; no platform spoofing.', 'acceptance_criteria_met' => false]);

        return 2;
    }
    try {
        $origin = realpath((string) ($options['source-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Source directory is missing.');
        $sources = sampleBenchmarkSources($origin);
        $library = articleBenchmarkFreezeLibrary((string) ($options['native'] ?? ''), $directory.'/native.so');
        $manifest = articleBenchmarkReadManifest((string) ($options['runtime-manifest'] ?? ''));
        sampleBenchmarkVerifyManifest($manifest, $sources, $library['sha256']);
        file_put_contents($directory.'/build-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $snapshot = articleBenchmarkFreezeGraph($origin, $directory.'/source');
        foreach ($instrumentation as $path => $hash) {
            $target = $directory.'/instrumentation/'.$path;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            if (! copy(dirname(__DIR__).'/'.$path, $target) || hash_file('sha256', $target) !== $hash) {
                throw new RuntimeException('Cannot freeze instrumentation: '.$path);
            }
        }
        $write(['type' => 'setup', 'source' => $snapshot, 'build_source_identities' => $sources, 'library' => $library,
            'hardware' => ['uname' => php_uname(), 'cpu' => @file_get_contents('/proc/cpuinfo'),
                'cgroup_cpu_max' => @file_get_contents('/sys/fs/cgroup/cpu.max'), 'cgroup_memory_max' => @file_get_contents('/sys/fs/cgroup/memory.max')]]);
        $state = $seed;
        $allQualified = true;
        $invalidTotal = 0;
        $fixtureHashes = [];
        $strata = [];
        foreach (SamplePlanningBenchmarkFixtures::strata() as $name => $definition) {
            $latencies = ['cold' => ['php' => [], 'native' => []], 'warm' => ['php' => [], 'native' => []]];
            $invalid = 0;
            $fixture = SamplePlanningBenchmarkFixtures::make($name, 0);
            $fixturePath = $directory.'/'.$name.'.fixture.serialized';
            file_put_contents($fixturePath, serialize($fixture));
            $fixtureHash = hash_file('sha256', $fixturePath);
            $fixtureHashes[$fixturePath] = $fixtureHash;
            $write(['type' => 'fixture', 'stratum' => $name, 'path' => $fixturePath, 'sha256' => $fixtureHash, 'expected_count' => $fixture['expected_count']]);
            for ($pair = 0; $pair < $samples; $pair++) {
                $order = yencBenchmarkRandom($state) % 2 === 0 ? ['php', 'native'] : ['native', 'php'];
                $children = [];
                $eligible = true;
                foreach ($order as $backend) {
                    $prefix = $directory.'/'.$name.'-'.$pair.'-'.$backend;
                    $resultPath = $prefix.'.result.serialized';
                    $command = [...articleBenchmarkPhpCommand(), $directory.'/instrumentation/scripts/benchmark-sample-planning.php', '--worker='.$backend, '--source-dir='.$snapshot['root'],
                        '--native='.$library['path'], '--fixture-path='.$fixturePath, '--result-output='.$resultPath];
                    $fixtureBefore = hash_file('sha256', $fixturePath) === $fixtureHash;
                    $child = yencBenchmarkRunChild($command, $prefix, $timeout);
                    $good = $fixtureBefore && hash_file('sha256', $fixturePath) === $fixtureHash && sampleBenchmarkEligible($child, $backend, $definition['native'], $fixtureHash, $library['sha256'], $resultPath, $snapshot, $instrumentation, $definition['articles'] >= 1024 ? 1 : 0);
                    $eligible = $eligible && $good;
                    $children[$backend] = $child;
                    $write(['type' => 'observation', 'stratum' => $name, 'pair' => $pair, 'backend' => $backend, 'command' => $command,
                        'eligible' => $good, 'child' => $child]);
                }
                $phpPath = $children['php']['result']['result_path'] ?? null;
                $nativePath = $children['native']['result']['result_path'] ?? null;
                $parity = is_string($phpPath) && is_string($nativePath) && is_file($phpPath) && is_file($nativePath)
                    && file_get_contents($phpPath) === file_get_contents($nativePath);
                $eligible = $eligible && $parity;
                if ($eligible) {
                    foreach (['cold', 'warm'] as $phase) {
                        foreach (['php', 'native'] as $backend) {
                            $latencies[$phase][$backend][] = $children[$backend]['result']['observations'][$phase]['elapsed_ms'];
                        }
                    }
                } else {
                    $invalid++;
                    $invalidTotal++;
                }
                $write(['type' => 'pair', 'stratum' => $name, 'pair' => $pair, 'order' => $order, 'fixture_sha256' => $fixtureHash,
                    'eligible' => $eligible, 'exact_serialized_parity' => $parity, 'native_admission_expected' => $definition['native']]);
            }
            $decision = [];
            foreach (['cold', 'warm'] as $phase) {
                $decision[$phase] = $definition['native']
                    ? sampleBenchmarkDecision($latencies[$phase]['php'], $latencies[$phase]['native'], $invalid, $definition['category'], $mode)
                    : ['status' => $invalid > 0 ? 'invalid' : 'not_applicable', 'reason' => $definition['category'],
                        'eligible_pairs' => $samples - $invalid, 'invalid_pairs' => $invalid, 'qualifies' => false,
                        'php_median_ms' => $latencies[$phase]['php'] === [] ? null : yencBenchmarkMedian($latencies[$phase]['php']),
                        'candidate_fallback_median_ms' => $latencies[$phase]['native'] === [] ? null : yencBenchmarkMedian($latencies[$phase]['native'])];
                if ($definition['native']) {
                    $allQualified = $allQualified && $decision[$phase]['qualifies'];
                }
            }
            $strata[$name] = $decision;
            $write(['type' => 'stratum', 'stratum' => $name, 'category' => $definition['category'], 'decision' => $decision]);
        }
        $stable = articleBenchmarkGraphStable($snapshot, $origin) && articleBenchmarkGraphStable($snapshot)
            && hash_file('sha256', $library['path']) === $library['sha256'] && sampleBenchmarkSources($origin) === $sources
            && sampleBenchmarkInstrumentation() === $instrumentation;
        foreach ($instrumentation as $path => $hash) {
            $stable = $stable && hash_file('sha256', $directory.'/instrumentation/'.$path) === $hash;
        }
        foreach ($fixtureHashes as $path => $hash) {
            $stable = $stable && hash_file('sha256', $path) === $hash;
        }
        $accepted = $stable && $invalidTotal === 0 && $allQualified;
        $write(['type' => 'summary', 'status' => ! $stable || $invalidTotal > 0 ? 'invalid' : ($accepted ? 'qualified' : 'inconclusive'),
            'identities_stable' => $stable, 'invalid_pairs' => $invalidTotal, 'strata' => $strata, 'acceptance_criteria_met' => $accepted]);

        return ! $stable || $invalidTotal > 0 ? 2 : ($mode === 'qualification' && ! $accepted ? 1 : 0);
    } catch (Throwable $exception) {
        $write(['type' => 'summary', 'status' => 'invalid', 'reason' => $exception->getMessage(), 'acceptance_criteria_met' => false]);

        return 2;
    }
}
