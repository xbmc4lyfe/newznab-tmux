<?php

declare(strict_types=1);

/**
 * Offline Linux CLI benchmark: --native=LIB --runtime-manifest=JSON --source-dir=ROOT
 * --output-dir=NEW --samples=31 --mode=screen|qualification [--order-seed=20261015] [--timeout=30].
 * Qualification requires 1000 independent pairs per PAR2 stratum. Comparison is a shared
 * semantic projection only: this harness never qualifies overall Rust adoption.
 */
use App\Services\Releases\Fingerprint\NativeMetadataParser;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Tests\Fixtures\ArchiveParsingBenchmarkFixtures;

if (! defined('NNTMUX_SAMPLE_BENCHMARK_FUNCTIONS_ONLY')) {
    define('NNTMUX_SAMPLE_BENCHMARK_FUNCTIONS_ONLY', true);
}
require_once __DIR__.'/benchmark-sample-planning.php';
require_once dirname(__DIR__).'/tests/Fixtures/ArchiveParsingBenchmarkFixtures.php';
if (defined('NNTMUX_ARCHIVE_BENCHMARK_FUNCTIONS_ONLY')) {
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
        echo json_encode(archiveBenchmarkWorker((string) $options['worker'], $fixture, (string) ($options['native'] ?? ''), $root,
            (string) ($options['result-output'] ?? '')), JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    exit(archiveBenchmarkRun($options));
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'invalid', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}

/**
 * @return array<string,string> */
function archiveBenchmarkInstrumentation(): array
{
    $hashes = sampleBenchmarkInstrumentation();
    foreach (['scripts/benchmark-archive-parsing.php', 'tests/Fixtures/ArchiveParsingBenchmarkFixtures.php'] as $path) {
        $hashes[$path] = hash_file('sha256', dirname(__DIR__).'/'.$path) ?: throw new RuntimeException('Missing instrumentation.');
    }
    ksort($hashes);

    return $hashes;
}

/**
 * @return array<string,string> */
function archiveBenchmarkSources(string $root): array
{
    $paths = array_keys(sampleBenchmarkSources($root));
    $paths = [...$paths, 'app/Services/Releases/Fingerprint/NativeMetadataParser.php',
        'app/Services/Releases/Fingerprint/Par2MetadataParser.php', 'tests/Fixtures/par2/rename-index.par2', ...array_keys(archiveBenchmarkInstrumentation())];
    $hashes = [];
    foreach (array_unique($paths) as $path) {
        $hashes[$path] = hash_file('sha256', $root.'/'.$path) ?: throw new RuntimeException('Missing source: '.$path);
    }
    ksort($hashes);

    return $hashes;
}

/**
 * @return array{root:string,files:array<string,string>,classes:array<string,string>} */
function archiveBenchmarkRuntime(string $root): array
{
    $allowed = array_map(static fn (string $path): string => dirname(__DIR__).'/'.$path, array_keys(archiveBenchmarkInstrumentation()));
    $classes = [];
    foreach ([NativeMetadataParser::class, Par2MetadataParser::class, Container::class, Repository::class] as $class) {
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
function archiveBenchmarkWorker(string $backend, array $fixture, string $library, string $root, string $output): array
{
    if (! in_array($backend, ['php', 'native'], true) || $output === '' || ($backend === 'php' && $fixture['operation'] !== 'par2')) {
        throw new InvalidArgumentException('Invalid archive worker backend/output.');
    }
    if ($backend === 'php' && ! in_array(ini_get('ffi.enable'), ['', '0', 'false'], true)) {
        throw new RuntimeException('PHP fallback requires CLI ffi.enable=false.');
    }
    $container = new Container;
    $container->instance('config', new Repository(['yenc' => ['native_library' => $backend === 'native' ? $library : '']]));
    Container::setInstance($container);
    $native = new NativeMetadataParser;
    $par2 = new Par2MetadataParser;
    if (archiveBenchmarkLoadedLibraries() !== []) {
        throw new RuntimeException('Fresh process already has native metadata libraries.');
    }
    $runtimeBefore = archiveBenchmarkRuntime($root);
    $libraryBefore = $backend === 'native' ? hash_file('sha256', $library) : null;
    $observations = [];
    $results = [];
    $serialized = null;
    foreach (['cold', 'warmup', 'warm'] as $phase) {
        $cpu = getrusage();
        $throttling = @file_get_contents('/sys/fs/cgroup/cpu.stat');
        $started = hrtime(true);
        $result = match ($fixture['operation']) {
            'par2' => $par2->parse($fixture['bytes']),
            'formats' => $native->parseFormats($fixture['bytes']),
            'seven-ranges' => $native->resolveSevenZipRanges($fixture['bytes']),
            'par2-ranges' => $native->resolvePar2SourceRanges($fixture['bytes']),
            default => throw new InvalidArgumentException('Unknown parser operation.'),
        };
        $elapsed = (hrtime(true) - $started) / 1e6;
        $cpuAfter = getrusage();
        $throttlingAfter = @file_get_contents('/sys/fs/cgroup/cpu.stat');
        $encoded = serialize($result);
        $results[$phase] = archiveBenchmarkStore($encoded, dirname($output).'/results', 'serialized');
        $loaded = archiveBenchmarkLoadedLibraries();
        $key = getmypid().':'.realpath($library);
        $nativeLoaded = isset($loaded[$key]) && count($loaded) === 1;
        $optional = (new ReflectionClass(NativeMetadataParser::class))->hasProperty('par2IntegerRangeLibraries')
            ? (new ReflectionProperty(NativeMetadataParser::class, 'par2IntegerRangeLibraries'))->getValue() : [];
        $normalizedLoaded = isset($optional[$key]) && count($optional) === 1;
        $nativeLoaded = $nativeLoaded || $normalizedLoaded;
        $engine = is_array($result) ? ($result['parser_engine'] ?? null) : null;
        $engineExpected = $backend === 'php' ? 'php-fallback' : 'nntmux-rust-ffi';
        $proof = $backend === 'native' ? $nativeLoaded : $loaded === [];
        if (in_array($fixture['operation'], ['par2', 'formats'], true)) {
            $proof = $proof && $engine === $engineExpected;
        }
        $correct = ArchiveParsingBenchmarkFixtures::correct($fixture, $result) && $proof && ($serialized === null || $serialized === $encoded);
        $serialized ??= $encoded;
        $observations[$phase] = ['elapsed_ms' => $elapsed, 'correct' => $correct, 'native_library_loaded' => $nativeLoaded,
            'integer_ranges_backend_proven' => $normalizedLoaded && $loaded === [] && $engine === 'nntmux-rust-ffi',
            'backend_proven' => $proof, 'parser_engine' => $engine, 'result_sha256' => hash('sha256', $encoded),
            'process_cpu_before' => $cpu, 'process_cpu_after' => $cpuAfter, 'cgroup_cpu_stat_before' => $throttling, 'cgroup_cpu_stat_after' => $throttlingAfter];
    }
    $runtimeAfter = archiveBenchmarkRuntime($root);
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
        'runtime_sources_before' => $runtimeBefore, 'runtime_sources' => $runtimeAfter, 'instrumentation' => archiveBenchmarkInstrumentation(),
        'runtime' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'sapi' => PHP_SAPI, 'ffi' => ini_get('ffi.enable'),
            'native_library_config' => config('yenc.native_library'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit')]];
}

/**
 * @param  array<string,mixed>  $child
 * @param  array<string,mixed>  $fixture
 * @param  array{root:string,files:array<string,string>,git_head:?string,git_tree:?string}  $snapshot
 * @param  array<string,string>  $instrumentation
 */
function archiveBenchmarkEligible(array $child, string $backend, array $fixture, string $libraryHash, string $resultPath, array $snapshot, array $instrumentation): bool
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
            || ! ArchiveParsingBenchmarkFixtures::correct($fixture, $actual)
            || ($row['result_sha256'] ?? null) !== hash('sha256', serialize($actual))
            || ($row['native_library_loaded'] ?? null) !== ($backend === 'native') || $actual !== ($retained['cold'] ?? null)) {
            return false;
        }
    }

    return true;
}

function archiveBenchmarkLatency(mixed $value): bool
{
    return (is_float($value) || is_int($value)) && is_finite((float) $value) && $value > 0;
}

/**
 * @param list<float> $php
 * @param list<float> $native
 * @return array<string,mixed> */
function archiveBenchmarkDecision(array $php, array $native, int $invalid, string $category, string $mode): array
{
    if (count(array_filter([...$php, ...$native], 'archiveBenchmarkLatency')) !== count($php) + count($native)) {
        return ['status' => 'invalid', 'qualifies' => false, 'reason' => 'Nonfinite/nonpositive latency.'];
    }

    return sampleBenchmarkDecision($php, $native, $invalid, $category, $mode);
}

/**
 * @param array<string,mixed> $options */
function archiveBenchmarkRun(array $options): int
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
    $directory = (string) ($options['output-dir'] ?? sys_get_temp_dir().'/archive-parsing-'.bin2hex(random_bytes(8)));
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
    $instrumentation = archiveBenchmarkInstrumentation();
    $write(['type' => 'protocol', 'protocol' => 'archive-parsing-v1', 'mode' => $mode, 'samples_per_stratum' => $samples,
        'diagnostic_samples_per_stratum' => min(31, $samples), 'order_seed' => $seed, 'timeout_seconds' => $timeout, 'strata' => ArchiveParsingBenchmarkFixtures::strata(), 'instrumentation' => $instrumentation,
        'comparison_scope' => 'PAR2 shared semantic projection only; backend-specific extras retained, no overall adoption claim. Formats and range wrappers have no PHP baseline and are native-only diagnostics.',
        'native_proof' => 'Fresh-process NativeMetadataParser static FFI registry must contain only frozen library; parser engine checked for PAR2/formats, independent relationship oracle for ranges.',
        'timing' => 'Fresh process per backend; cold actual PHP caller, one warmup and one warm call. FFI memcpy/JSON/free plus PHP caller normalization included. Verification/serialization outside timers; process elapsed retained separately.',
        'decision_rule' => '4 PAR2 strata x cold/warm, common median and uncommon p99; 1000 independent pairs, zero invalid pairs, conservative simultaneous 95% lower speedup >=1.5. Bonferroni over 16 marginal quantiles. Screen never qualifies.',
        'retention' => 'Exact serialized phase outputs and full semantic differences stored once per SHA256; every observation/pair retains immutable references and raw stdout/stderr. No failure discarded.',
        'limitations' => 'Bounded minimal format headers, not archive extraction or exhaustive format coverage; no production capacity inference.']);
    if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
        $write(['type' => 'summary', 'status' => 'skipped', 'reason' => 'Real native parser requires Linux CLI with FFI; no platform spoofing.', 'acceptance_criteria_met' => false]);

        return 2;
    }
    try {
        $origin = realpath((string) ($options['source-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Missing source.');
        $sources = archiveBenchmarkSources($origin);
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
        $write(['type' => 'setup', 'source' => $snapshot, 'build_source_identities' => $sources, 'library' => $library,
            'hardware' => ['uname' => php_uname(), 'cpu' => @file_get_contents('/proc/cpuinfo'),
                'cgroup_cpu_max' => @file_get_contents('/sys/fs/cgroup/cpu.max'), 'cgroup_memory_max' => @file_get_contents('/sys/fs/cgroup/memory.max')]]);
        $state = $seed;
        $qualified = true;
        $invalidTotal = 0;
        $fixtureHashes = [];
        $artifactHashes = [];
        $decisions = [];
        foreach (ArchiveParsingBenchmarkFixtures::strata() as $name => $definition) {
            $fixture = ArchiveParsingBenchmarkFixtures::make($name, $snapshot['root']);
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
                        $directory.'/instrumentation/scripts/benchmark-archive-parsing.php', '--worker='.$backend, '--source-dir='.$snapshot['root'],
                        '--native='.$library['path'], '--fixture-path='.$fixturePath, '--result-output='.$resultPath];
                    $fixtureBefore = hash_file('sha256', $fixturePath) === $fixtureHash;
                    $child = yencBenchmarkRunChild($command, $prefix, $timeout);
                    if (is_file($resultPath)) {
                        $artifactHashes[$resultPath] = hash_file('sha256', $resultPath);
                    }
                    $good = $fixtureBefore && hash_file('sha256', $fixturePath) === $fixtureHash
                        && archiveBenchmarkEligible($child, $backend, $fixture, $library['sha256'], $resultPath, $snapshot, $instrumentation);
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
                                $parity = $parity && ArchiveParsingBenchmarkFixtures::projection($php[$phase]) === ArchiveParsingBenchmarkFixtures::projection($native[$phase]);
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
                    ? archiveBenchmarkDecision($latencies[$phase]['php'], $latencies[$phase]['native'], $invalid, $definition['category'], $mode)
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
            && hash_file('sha256', $library['path']) === $library['sha256'] && archiveBenchmarkSources($origin) === $sources
            && archiveBenchmarkInstrumentation() === $instrumentation;
        foreach ($instrumentation as $path => $hash) {
            $stable = $stable && hash_file('sha256', $directory.'/instrumentation/'.$path) === $hash;
        }
        foreach ($fixtureHashes + $artifactHashes as $path => $hash) {
            $stable = $stable && hash_file('sha256', $path) === $hash;
        }
        $stable = $stable && archiveBenchmarkStoresStable($directory);
        $projectedQualified = $stable && $invalidTotal === 0 && $qualified;
        $write(['type' => 'summary', 'status' => ! $stable || $invalidTotal > 0 ? 'invalid' : 'partial_semantics_only', 'identities_stable' => $stable,
            'invalid_observations_or_pairs' => $invalidTotal, 'strata' => $decisions, 'par2_shared_projection_qualified' => $projectedQualified,
            'acceptance_criteria_met' => false, 'reason' => 'PAR2 projection does not establish full semantic equivalence; format/range diagnostics have no PHP baseline.']);

        return ! $stable || $invalidTotal > 0 ? 2 : ($mode === 'qualification' && ! $projectedQualified ? 1 : 0);
    } catch (Throwable $exception) {
        $write(['type' => 'summary', 'status' => 'invalid', 'reason' => $exception->getMessage(), 'acceptance_criteria_met' => false]);

        return 2;
    }
}

/**
 * @param array<array-key,mixed> $left
 * @param array<array-key,mixed> $right
 * @return list<array<string,mixed>> */
function archiveBenchmarkDifferences(array $left, array $right, string $path = ''): array
{
    $diffs = [];
    foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
        $location = $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
        if (! array_key_exists($key, $left) || ! array_key_exists($key, $right)) {
            $diffs[] = ['path' => $location, 'php_present' => array_key_exists($key, $left), 'native_present' => array_key_exists($key, $right), 'php' => $left[$key] ?? null, 'native' => $right[$key] ?? null];
        } elseif (is_array($left[$key]) && is_array($right[$key])) {
            $diffs = [...$diffs, ...archiveBenchmarkDifferences($left[$key], $right[$key], $location)];
        } elseif ($left[$key] !== $right[$key]) {
            $diffs[] = ['path' => $location, 'php' => $left[$key], 'native' => $right[$key]];
        }
    }

    return $diffs;
}

/**
 * The real wrapper mutates this registry during parse calls.
 *
 * @phpstan-impure
 *
 * @return array<string,FFI>
 */
function archiveBenchmarkLoadedLibraries(): array
{
    return (new ReflectionProperty(NativeMetadataParser::class, 'libraries'))->getValue();
}

/** @return array{path:string,sha256:string,bytes:int} */
function archiveBenchmarkStore(string $bytes, string $directory, string $extension): array
{
    if (! in_array($extension, ['serialized', 'json'], true)) {
        throw new InvalidArgumentException('Unsupported retained artifact encoding.');
    }
    if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700))) {
        throw new RuntimeException('Cannot create owned result store.');
    }
    $hash = hash('sha256', $bytes);
    $path = $directory.'/'.$hash.'.'.$extension;
    if (file_exists($path)) {
        if (is_link($path) || hash_file('sha256', $path) !== $hash) {
            throw new RuntimeException('Existing content-addressed artifact changed.');
        }
    } elseif (file_put_contents($path, $bytes) !== strlen($bytes) || hash_file('sha256', $path) !== $hash) {
        throw new RuntimeException('Cannot retain content-addressed artifact.');
    }

    return ['path' => $path, 'sha256' => $hash, 'bytes' => strlen($bytes)];
}

/** Validate owned references before reading any retained full outputs.
 * @return array<string,mixed>|null
 */
function archiveBenchmarkReadResults(string $receiptPath): ?array
{
    $receiptBytes = file_get_contents($receiptPath);
    if ($receiptBytes === false) {
        return null;
    }
    $receipt = unserialize($receiptBytes, ['allowed_classes' => false]);
    if (! is_array($receipt) || array_keys($receipt) !== ['cold', 'warmup', 'warm']) {
        return null;
    }
    $results = [];
    $decoded = [];
    foreach ($receipt as $phase => $reference) {
        if (! is_array($reference) || ! is_string($reference['sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $reference['sha256']) !== 1) {
            return null;
        }
        $path = dirname($receiptPath).'/results/'.$reference['sha256'].'.serialized';
        if (($reference['path'] ?? null) !== $path || ! is_file($path) || is_link($path) || is_link(dirname($path))) {
            return null;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || hash('sha256', $bytes) !== $reference['sha256'] || strlen($bytes) !== ($reference['bytes'] ?? null)) {
            return null;
        }
        if (! array_key_exists($reference['sha256'], $decoded)) {
            $decoded[$reference['sha256']] = unserialize($bytes, ['allowed_classes' => false]);
        }
        $results[$phase] = $decoded[$reference['sha256']];
    }

    return $results;
}

function archiveBenchmarkStoresStable(string $directory): bool
{
    foreach (['results' => 'serialized', 'differences' => 'json'] as $store => $extension) {
        $path = $directory.'/'.$store;
        if (is_link($path)) {
            return false;
        }
        if (! is_dir($path)) {
            continue;
        }
        foreach (new DirectoryIterator($path) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if (! $file->isFile() || $file->isLink() || $file->getExtension() !== $extension
                || preg_match('/\A[0-9a-f]{64}\z/', $file->getBasename('.'.$extension)) !== 1
                || hash_file('sha256', $file->getPathname()) !== $file->getBasename('.'.$extension)) {
                return false;
            }
        }
    }

    return true;
}
