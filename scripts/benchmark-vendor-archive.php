<?php

declare(strict_types=1);

/**
 * Offline Linux CLI benchmark: --native=LIB --runtime-manifest=JSON --source-dir=ROOT
 * --output-dir=NEW --samples=31 --mode=screen|qualification [--order-seed=20261015] [--timeout=30].
 * Qualification requires 1000 independent pairs per required vendor archive stratum. Comparison is a shared
 * semantic projection only: this harness never qualifies overall Rust adoption.
 */
use App\Services\Releases\Fingerprint\NativeMetadataParser;
use dariusiii\rarinfo\RarInfo;
use dariusiii\rarinfo\SzipInfo;
use dariusiii\rarinfo\ZipInfo;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Tests\Fixtures\VendorArchiveBenchmarkFixtures;

if (! defined('NNTMUX_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY')) {
    define('NNTMUX_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY', true);
}
require_once __DIR__.'/benchmark-archive-parsing.php';
require_once dirname(__DIR__).'/tests/Fixtures/VendorArchiveBenchmarkFixtures.php';
if (defined('NNTMUX_VENDOR_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY')) {
    return;
}
$options = getopt('', ['native:', 'runtime-manifest:', 'output-dir:', 'source-dir:', 'mode:', 'samples:', 'order-seed:', 'timeout:', 'worker:', 'fixture-path:', 'result-output:']);
try {
    if (isset($options['worker'])) {
        $root = realpath((string) ($options['source-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Source directory is missing.');
        require $root.'/vendor/autoload.php';
        $fixture = unserialize((string) file_get_contents((string) ($options['fixture-path'] ?? '')), ['allowed_classes' => false]);
        if (! is_array($fixture)) {
            throw new InvalidArgumentException('Worker requires frozen fixture.');
        }
        echo json_encode(vendorArchiveBenchmarkWorker((string) $options['worker'], $fixture, (string) ($options['native'] ?? ''), $root,
            (string) ($options['result-output'] ?? '')), JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    exit(vendorArchiveBenchmarkRun($options));
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'invalid', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}

/**
 * @return array<string,string> */
function vendorArchiveBenchmarkInstrumentation(): array
{
    $hashes = archiveBenchmarkInstrumentation();
    foreach (['scripts/benchmark-vendor-archive.php', 'tests/Fixtures/VendorArchiveBenchmarkFixtures.php'] as $path) {
        $hashes[$path] = hash_file('sha256', dirname(__DIR__).'/'.$path) ?: throw new RuntimeException('Missing instrumentation.');
    }
    ksort($hashes);

    return $hashes;
}

/**
 * @return array<string,string> */
function vendorArchiveBenchmarkSources(string $root): array
{
    $paths = array_keys(sampleBenchmarkSources($root));
    $paths = [...$paths, 'app/Services/Releases/Fingerprint/NativeMetadataParser.php',
        'vendor/dariusiii/rarinfo/ArchiveReader.php', 'vendor/dariusiii/rarinfo/RarInfo.php',
        'vendor/dariusiii/rarinfo/ZipInfo.php', 'vendor/dariusiii/rarinfo/SzipInfo.php', 'vendor/dariusiii/rarinfo/composer.json', 'vendor/composer/installed.json', ...array_keys(vendorArchiveBenchmarkInstrumentation())];
    foreach (VendorArchiveBenchmarkFixtures::strata() as $definition) {
        $paths[] = $definition['path'];
    }
    $hashes = [];
    foreach (array_unique($paths) as $path) {
        $hashes[$path] = hash_file('sha256', $root.'/'.$path) ?: throw new RuntimeException('Missing source: '.$path);
    }
    ksort($hashes);

    return $hashes;
}

/**
 * @return array{root:string,files:array<string,string>,classes:array<string,string>} */
function vendorArchiveBenchmarkRuntime(string $root): array
{
    $allowed = array_map(static fn (string $path): string => dirname(__DIR__).'/'.$path, array_keys(vendorArchiveBenchmarkInstrumentation()));
    $classes = [];
    foreach ([NativeMetadataParser::class, RarInfo::class, ZipInfo::class, SzipInfo::class, Container::class, Repository::class] as $class) {
        $path = (new ReflectionClass($class))->getFileName();
        if (! is_string($path) || ! str_starts_with($path, $root.'/')) {
            throw new RuntimeException('Parser class escaped selected runtime.');
        }
        $classes[$class] = substr($path, strlen($root) + 1);
    }
    $files = [];
    foreach (get_included_files() as $path) {
        $path = realpath($path) ?: throw new RuntimeException('Included file is missing.');
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
 * @param array<string,mixed> $fixture
 * @return array<string,mixed> */
function vendorArchiveBenchmarkWorker(string $backend, array $fixture, string $library, string $root, string $output): array
{
    if (! in_array($backend, ['php', 'native'], true) || $output === '') {
        throw new InvalidArgumentException('Invalid archive worker backend/output.');
    }
    if ($backend === 'php' && ! in_array(ini_get('ffi.enable'), ['', '0', 'false'], true)) {
        throw new RuntimeException('Vendor PHP reader requires CLI ffi.enable=false.');
    }
    $container = new Container;
    $container->instance('config', new Repository(['yenc' => ['native_library' => $backend === 'native' ? $library : '']]));
    Container::setInstance($container);
    $native = new NativeMetadataParser;
    if (archiveBenchmarkLoadedLibraries() !== []) {
        throw new RuntimeException('Fresh process already has native metadata libraries.');
    }
    $runtimeBefore = vendorArchiveBenchmarkRuntime($root);
    $libraryBefore = $backend === 'native' ? hash_file('sha256', $library) : null;
    $observations = [];
    $results = [];
    $serialized = null;
    foreach (['cold', 'warmup', 'warm'] as $phase) {
        $cpu = getrusage();
        $throttling = @file_get_contents('/sys/fs/cgroup/cpu.stat');
        $started = hrtime(true);
        $result = $backend === 'php' ? VendorArchiveBenchmarkFixtures::parseVendor($fixture['format'], $fixture['bytes']) : $native->parseFormats($fixture['bytes']);
        $elapsed = (hrtime(true) - $started) / 1e6;
        $cpuAfter = getrusage();
        $throttlingAfter = @file_get_contents('/sys/fs/cgroup/cpu.stat');
        $encoded = serialize($result);
        $results[$phase] = archiveBenchmarkStore($encoded, dirname($output).'/results', 'serialized');
        $loaded = archiveBenchmarkLoadedLibraries();
        $key = getmypid().':'.realpath($library);
        $nativeLoaded = isset($loaded[$key]) && count($loaded) === 1;
        $engine = is_array($result) ? ($result['parser_engine'] ?? null) : null;
        $engineExpected = $backend === 'php' ? null : 'nntmux-rust-ffi';
        $proof = $backend === 'native' ? $nativeLoaded : $loaded === [];
        $proof = $proof && $engine === $engineExpected;
        $correct = VendorArchiveBenchmarkFixtures::correct($fixture, $result) && $proof && ($serialized === null || $serialized === $encoded);
        $serialized ??= $encoded;
        $observations[$phase] = ['elapsed_ms' => $elapsed, 'correct' => $correct, 'native_library_loaded' => $nativeLoaded,
            'backend_proven' => $proof, 'parser_engine' => $engine, 'result_sha256' => hash('sha256', $encoded),
            'process_cpu_before' => $cpu, 'process_cpu_after' => $cpuAfter, 'cgroup_cpu_stat_before' => $throttling, 'cgroup_cpu_stat_after' => $throttlingAfter];
    }
    $runtimeAfter = vendorArchiveBenchmarkRuntime($root);
    $stable = true;
    foreach ($runtimeBefore['files'] as $path => $hash) {
        $stable = $stable && ($runtimeAfter['files'][$path] ?? null) === $hash;
    }
    $libraryAfter = $backend === 'native' ? hash_file('sha256', $library) : null;
    $retained = serialize($results);
    if (file_exists($output) || file_put_contents($output, $retained) === false) {
        throw new RuntimeException('Cannot retain full parser results.');
    }

    return ['status' => 'ok', 'backend' => $backend, 'median_ms' => $observations['warm']['elapsed_ms'], 'observations' => $observations,
        'result_path' => $output, 'result_sha256' => hash('sha256', $retained), 'fixture_sha256' => hash('sha256', serialize($fixture)),
        'library_sha256' => $libraryAfter, 'library_sha256_before' => $libraryBefore, 'loaded_runtime_stable' => $stable,
        'runtime_sources_before' => $runtimeBefore, 'runtime_sources' => $runtimeAfter, 'instrumentation' => vendorArchiveBenchmarkInstrumentation(),
        'runtime' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'sapi' => PHP_SAPI, 'ffi' => ini_get('ffi.enable'),
            'native_library_config' => config('yenc.native_library'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit')]];
}

/**
 * @param  array<string,mixed>  $child
 * @param  array<string,mixed>  $fixture
 * @param  array{root:string,files:array<string,string>,git_head:?string,git_tree:?string}  $snapshot
 * @param  array<string,string>  $instrumentation
 */
function vendorArchiveBenchmarkEligible(array $child, string $backend, array $fixture, string $libraryHash, string $resultPath, array $snapshot, array $instrumentation): bool
{
    $result = $child['result'] ?? null;
    if (($child['status'] ?? null) !== 'ok' || ($child['stdout_truncated_for_json'] ?? true) || ! is_array($result)
        || ($result['backend'] ?? null) !== $backend || ($result['fixture_sha256'] ?? null) !== hash('sha256', serialize($fixture))
        || ($result['instrumentation'] ?? null) !== $instrumentation || ($result['loaded_runtime_stable'] ?? false) !== true
        || ! articleBenchmarkRuntimeMatches($child, $snapshot)
        || ! articleBenchmarkRuntimeMatches(['result' => ['runtime_sources' => $result['runtime_sources_before'] ?? null]], $snapshot)
        || ($result['result_path'] ?? null) !== $resultPath || ! is_file($resultPath)
        || ($result['result_sha256'] ?? null) !== hash_file('sha256', $resultPath)
        || ($backend === 'native' && (($result['library_sha256'] ?? null) !== $libraryHash || ($result['library_sha256_before'] ?? null) !== $libraryHash))) {
        return false;
    }
    $retained = archiveBenchmarkReadResults($resultPath);
    if ($retained === null) {
        return false;
    }
    foreach (['cold', 'warmup', 'warm'] as $phase) {
        $row = $result['observations'][$phase] ?? null;
        $actual = $retained[$phase] ?? null;
        if (! is_array($row) || ($row['correct'] ?? false) !== true || ($row['backend_proven'] ?? false) !== true
            || ! archiveBenchmarkLatency($row['elapsed_ms'] ?? null) || ! is_array($actual)
            || ! VendorArchiveBenchmarkFixtures::correct($fixture, $actual)
            || ($row['parser_engine'] ?? null) !== ($backend === 'native' ? 'nntmux-rust-ffi' : null)
            || ($actual['parser_engine'] ?? null) !== ($backend === 'native' ? 'nntmux-rust-ffi' : null)
            || ($row['result_sha256'] ?? null) !== hash('sha256', serialize($actual))
            || ($row['native_library_loaded'] ?? null) !== ($backend === 'native') || $actual !== ($retained['cold'] ?? null)) {
            return false;
        }
    }

    return true;
}

/**
 * @param list<float> $php
 * @param list<float> $native
 * @return array<string,mixed> */
function vendorArchiveBenchmarkDecision(array $php, array $native, int $invalid, string $category, string $mode): array
{
    if (count(array_filter([...$php, ...$native], 'archiveBenchmarkLatency')) !== count($php) + count($native)) {
        return ['status' => 'invalid', 'qualifies' => false, 'reason' => 'Nonfinite/nonpositive latency.'];
    }

    if ($invalid > 0 || count($php) !== count($native) || $php === []) {
        return ['status' => 'invalid', 'invalid_pairs' => $invalid, 'eligible_pairs' => count($php), 'qualifies' => false];
    }
    $quantile = $category === 'uncommon' ? 0.99 : 0.5;
    $alpha = 0.05 / (count(VendorArchiveBenchmarkFixtures::strata()) * 2 * 2);
    $baseline = sampleBenchmarkQuantile($php, $quantile, $alpha);
    $candidate = sampleBenchmarkQuantile($native, $quantile, $alpha);
    $low = $baseline['interval'][0] !== null && $candidate['interval'][1] !== null ? $baseline['interval'][0] / $candidate['interval'][1] : null;
    $high = $baseline['interval'][1] !== null && $candidate['interval'][0] !== null ? $baseline['interval'][1] / $candidate['interval'][0] : null;
    $qualified = $mode === 'qualification' && count($php) >= 1000 && $low !== null && $low >= 1.5;

    return ['status' => $qualified ? 'qualified' : 'inconclusive', 'eligible_pairs' => count($php), 'invalid_pairs' => 0,
        'metric' => $quantile === 0.99 ? 'p99_latency' : 'median_latency', 'php' => $baseline, 'native' => $candidate,
        'php_over_native' => $baseline['estimate'] / $candidate['estimate'], 'simultaneous_95_percent_speedup_interval' => [$low, $high],
        'marginal_alpha' => $alpha, 'qualifies' => $qualified, 'evidence' => $mode === 'screen' ? 'screen_only_no_adoption' : 'qualification'];
}

/**
 * @param array<string,mixed> $options */
function vendorArchiveBenchmarkRun(array $options): int
{
    $mode = (string) ($options['mode'] ?? 'screen');
    $samples = filter_var($options['samples'] ?? ($mode === 'qualification' ? 1000 : 31), FILTER_VALIDATE_INT);
    $seed = filter_var($options['order-seed'] ?? 20261015, FILTER_VALIDATE_INT);
    $timeout = filter_var($options['timeout'] ?? 30, FILTER_VALIDATE_FLOAT);
    if (! in_array($mode, ['screen', 'qualification'], true) || $samples === false || $samples < 1 || $samples > 10000
        || ($mode === 'qualification' && $samples < 1000) || $seed === false || $seed < 1 || $seed > 2147483646
        || $timeout === false || ! is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
        throw new InvalidArgumentException('Invalid protocol; qualification requires at least 1000 pairs.');
    }
    $directory = (string) ($options['output-dir'] ?? sys_get_temp_dir().'/vendor-archive-'.bin2hex(random_bytes(8)));
    if (file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be new.');
    }
    $directory = realpath($directory) ?: throw new RuntimeException('Cannot resolve output directory.');
    $write = static function (array $row) use ($directory): void {
        $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($directory.'/observations.jsonl', $line, FILE_APPEND) === false) {
            throw new RuntimeException('Cannot retain observation.');
        }
        echo $line;
    };
    $instrumentation = vendorArchiveBenchmarkInstrumentation();
    $write(['type' => 'protocol', 'protocol' => 'vendor-archive-v1', 'mode' => $mode, 'samples_per_stratum' => $samples,
        'order_seed' => $seed, 'timeout_seconds' => $timeout, 'strata' => VendorArchiveBenchmarkFixtures::strata(), 'instrumentation' => $instrumentation,
        'comparison_scope' => 'Locked BSD-3-Clause dariusiii/rarinfo v3.1 actual in-memory readers versus actual NativeMetadataParser::parseFormats; common initial-header projection only. Full backend outputs retained separately. No full semantic, error, extraction, or adoption equivalence.',
        'native_proof' => 'Fresh-process NativeMetadataParser FFI registry must contain only frozen library, native engine must match, loaded runtime and library hashes must match frozen source/build receipt.',
        'timing' => 'Fresh process per backend; cold actual PHP caller, one warmup and one warm call. FFI memcpy/JSON/free plus PHP caller normalization included. Verification/serialization outside timers; process elapsed retained separately.',
        'decision_rule' => '8 required strata x cold/warm, common median and uncommon p99; at least 1000 independent pairs per stratum, zero invalid pairs, conservative simultaneous 95% lower speedup >=1.5. Bonferroni over 32 marginal quantiles. Screen never qualifies.',
        'retention' => 'Exact serialized phase outputs and full semantic differences stored once per SHA256; every observation/pair retains immutable references and raw stdout/stderr. No failure discarded.',
        'limitations' => 'Complete real public archive fixtures with scenario strata, not measured production frequencies. Shared projection excludes file inventory, errors, range semantics and extraction. Library work differs outside the projection; no production capacity or adoption inference.']);
    if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
        $write(['type' => 'summary', 'status' => 'skipped', 'reason' => 'Real native parser requires Linux CLI with FFI; no platform spoofing.', 'acceptance_criteria_met' => false]);

        return 2;
    }
    try {
        $origin = realpath((string) ($options['source-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Missing source.');
        $sources = vendorArchiveBenchmarkSources($origin);
        $vendorReceipt = vendorArchiveBenchmarkVendorReceipt($origin);
        foreach ($instrumentation as $path => $hash) {
            if (($sources[$path] ?? null) !== $hash) {
                throw new RuntimeException('Selected source instrumentation differs from executing harness: '.$path);
            }
        }
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
        $write(['type' => 'setup', 'source' => $snapshot, 'build_source_identities' => $sources, 'vendor_receipt' => $vendorReceipt, 'library' => $library,
            'hardware' => ['uname' => php_uname(), 'cpu' => @file_get_contents('/proc/cpuinfo'),
                'cgroup_cpu_max' => @file_get_contents('/sys/fs/cgroup/cpu.max'), 'cgroup_memory_max' => @file_get_contents('/sys/fs/cgroup/memory.max')]]);
        $state = $seed;
        $qualified = true;
        $invalidTotal = 0;
        $fixtureHashes = [];
        $artifactHashes = [];
        $decisions = [];
        foreach (VendorArchiveBenchmarkFixtures::strata() as $name => $definition) {
            $fixture = VendorArchiveBenchmarkFixtures::make($name, $snapshot['root']);
            $fixturePath = $directory.'/'.$name.'.fixture.serialized';
            file_put_contents($fixturePath, serialize($fixture));
            $fixtureHash = hash_file('sha256', $fixturePath);
            $fixtureHashes[$fixturePath] = $fixtureHash;
            $write(['type' => 'fixture', 'stratum' => $name, 'path' => $fixturePath, 'sha256' => $fixtureHash, 'input_sha256' => $fixture['input_sha256'], 'input_bytes' => strlen($fixture['bytes']), 'oracle' => $fixture['oracle']]);
            $latencies = ['cold' => ['php' => [], 'native' => []], 'warm' => ['php' => [], 'native' => []]];
            $invalid = 0;
            $stratumSamples = $definition['paired'] ? $samples : min(31, $samples);
            for ($pair = 0; $pair < $stratumSamples; $pair++) {
                $order = $definition['paired'] ? (yencBenchmarkRandom($state) % 2 === 0 ? ['php', 'native'] : ['native', 'php']) : ['native'];
                $children = [];
                $eligible = true;
                foreach ($order as $backend) {
                    $prefix = $directory.'/'.$name.'-'.$pair.'-'.$backend;
                    $resultPath = $prefix.'.result.serialized';
                    $command = [...articleBenchmarkPhpCommand(), '-d', 'ffi.enable='.($backend === 'php' ? 'false' : 'true'),
                        $directory.'/instrumentation/scripts/benchmark-vendor-archive.php', '--worker='.$backend, '--source-dir='.$snapshot['root'],
                        '--native='.$library['path'], '--fixture-path='.$fixturePath, '--result-output='.$resultPath];
                    $fixtureBefore = hash_file('sha256', $fixturePath) === $fixtureHash;
                    $child = yencBenchmarkRunChild($command, $prefix, $timeout);
                    if (is_file($resultPath)) {
                        $artifactHashes[$resultPath] = hash_file('sha256', $resultPath);
                    }
                    $good = $fixtureBefore && hash_file('sha256', $fixturePath) === $fixtureHash
                        && vendorArchiveBenchmarkEligible($child, $backend, $fixture, $library['sha256'], $resultPath, $snapshot, $instrumentation);
                    $eligible = $eligible && $good;
                    $children[$backend] = $child;
                    $write(['type' => 'observation', 'stratum' => $name, 'pair' => $pair, 'backend' => $backend, 'command' => $command, 'eligible' => $good, 'child' => $child]);
                }
                $parity = null;
                if ($definition['paired']) {
                    $parity = false;
                    $phpPath = $directory.'/'.$name.'-'.$pair.'-php.result.serialized';
                    $nativePath = $directory.'/'.$name.'-'.$pair.'-native.result.serialized';
                    if (is_file($phpPath) && is_file($nativePath)) {
                        $php = archiveBenchmarkReadResults($phpPath);
                        $native = archiveBenchmarkReadResults($nativePath);
                        $parity = true;
                        $diffs = [];
                        foreach (['cold', 'warmup', 'warm'] as $phase) {
                            if (! is_array($php[$phase] ?? null) || ! is_array($native[$phase] ?? null)) {
                                $parity = false;
                                $diffs[$phase] = ['status' => 'unavailable', 'reason' => 'Backend did not return an array.'];
                            } else {
                                $parity = $parity && VendorArchiveBenchmarkFixtures::projection($php[$phase], $fixture['format']) === VendorArchiveBenchmarkFixtures::projection($native[$phase], $fixture['format']);
                                $diffs[$phase] = isset($diffs['cold']) && $php[$phase] === $php['cold'] && $native[$phase] === $native['cold']
                                    ? $diffs['cold'] : archiveBenchmarkDifferences($php[$phase], $native[$phase]);
                            }
                        }
                        $diffReference = archiveBenchmarkStore(json_encode($diffs, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), $directory.'/differences', 'json');
                        $diffPath = $directory.'/'.$name.'-'.$pair.'.full-result-differences.json';
                        file_put_contents($diffPath, json_encode($diffReference, JSON_THROW_ON_ERROR));
                        $artifactHashes[$diffPath] = hash_file('sha256', $diffPath);
                    }
                    $eligible = $eligible && $parity;
                }
                if ($eligible) {
                    foreach (['cold', 'warm'] as $phase) {
                        foreach ($order as $backend) {
                            $latencies[$phase][$backend][] = $children[$backend]['result']['observations'][$phase]['elapsed_ms'];
                        }
                    }
                } else {
                    $invalid++;
                    $invalidTotal++;
                }
                $write(['type' => 'pair', 'stratum' => $name, 'pair' => $pair, 'order' => $order, 'fixture_sha256' => $fixtureHash,
                    'eligible' => $eligible, 'shared_projection_parity' => $parity, 'paired' => $definition['paired']]);
            }
            $decision = [];
            foreach (['cold', 'warm'] as $phase) {
                $decision[$phase] = $definition['paired']
                    ? vendorArchiveBenchmarkDecision($latencies[$phase]['php'], $latencies[$phase]['native'], $invalid, $definition['category'], $mode)
                    : ['status' => $invalid > 0 ? 'invalid' : 'diagnostic_only', 'eligible_observations' => $stratumSamples - $invalid, 'invalid_observations' => $invalid,
                        'native_ms' => $latencies[$phase]['native'], 'qualifies' => false, 'reason' => 'No semantically equivalent PHP baseline.'];
                if ($definition['paired']) {
                    $qualified = $qualified && $decision[$phase]['qualifies'];
                }
            }
            $decisions[$name] = $decision;
            $write(['type' => 'stratum', 'stratum' => $name, 'category' => $definition['category'], 'samples' => $stratumSamples, 'decision' => $decision]);
        }
        $stable = articleBenchmarkGraphStable($snapshot, $origin) && articleBenchmarkGraphStable($snapshot)
            && hash_file('sha256', $library['path']) === $library['sha256'] && vendorArchiveBenchmarkSources($origin) === $sources
            && vendorArchiveBenchmarkInstrumentation() === $instrumentation;
        foreach ($instrumentation as $path => $hash) {
            $stable = $stable && hash_file('sha256', $directory.'/instrumentation/'.$path) === $hash;
        }
        foreach ($fixtureHashes + $artifactHashes as $path => $hash) {
            $stable = $stable && hash_file('sha256', $path) === $hash;
        }
        $stable = $stable && archiveBenchmarkStoresStable($directory);
        $projectedQualified = $stable && $invalidTotal === 0 && $qualified;
        $write(['type' => 'summary', 'status' => ! $stable || $invalidTotal > 0 ? 'invalid' : 'partial_semantics_only', 'identities_stable' => $stable,
            'invalid_observations_or_pairs' => $invalidTotal, 'strata' => $decisions, 'vendor_archive_shared_projection_qualified' => $projectedQualified,
            'acceptance_criteria_met' => false, 'reason' => 'Shared initial-header projection does not establish full archive semantics, matching errors, or adoption qualification.']);

        return ! $stable || $invalidTotal > 0 ? 2 : ($mode === 'qualification' && ! $projectedQualified ? 1 : 0);
    } catch (Throwable $exception) {
        $write(['type' => 'summary', 'status' => 'invalid', 'reason' => $exception->getMessage(), 'acceptance_criteria_met' => false]);

        return 2;
    }
}

/** @return array<string,mixed> */
function vendorArchiveBenchmarkVendorReceipt(string $root): array
{
    $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $installed = json_decode((string) file_get_contents($root.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    $locked = array_values(array_filter($lock['packages'] ?? [], static fn (array $p): bool => ($p['name'] ?? null) === 'dariusiii/rarinfo'));
    $actual = array_values(array_filter($installed['packages'] ?? [], static fn (array $p): bool => ($p['name'] ?? null) === 'dariusiii/rarinfo'));
    if (count($locked) !== 1 || count($actual) !== 1 || ! in_array($locked[0]['version'] ?? null, ['3.1', 'v3.1'], true)
        || ($locked[0]['version'] ?? null) !== ($actual[0]['version'] ?? null)
        || ($locked[0]['source']['reference'] ?? null) !== ($actual[0]['source']['reference'] ?? null)) {
        throw new RuntimeException('Vendor reader installation does not match locked v3.1 dependency.');
    }
    $files = [];
    foreach (vendorArchiveBenchmarkSources($root) as $path => $hash) {
        if (str_starts_with($path, 'vendor/') || $path === 'composer.lock') {
            $files[$path] = $hash;
        }
    }

    return ['package' => 'dariusiii/rarinfo', 'version' => $locked[0]['version'], 'license' => $locked[0]['license'],
        'source_reference' => $locked[0]['source']['reference'], 'files' => $files];
}
