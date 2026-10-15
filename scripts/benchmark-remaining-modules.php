<?php

declare(strict_types=1);

use App\Services\Releases\Fingerprint\NativeMetadataParser;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Tests\Fixtures\RemainingArchiveBenchmarkFixtures;
use Tests\Fixtures\RemainingMetadataBenchmarkFixtures;

if (! defined('NNTMUX_SAMPLE_BENCHMARK_FUNCTIONS_ONLY')) {
    define('NNTMUX_SAMPLE_BENCHMARK_FUNCTIONS_ONLY', true);
}
require_once __DIR__.'/benchmark-sample-planning.php';
foreach (['RemainingMetadataBenchmarkFixtures', 'RemainingArchiveBenchmarkFixtures'] as $fixtureClass) {
    $fixtureFile = dirname(__DIR__).'/tests/Fixtures/'.$fixtureClass.'.php';
    if (is_file($fixtureFile)) {
        require_once $fixtureFile;
    }
}
if (defined('NNTMUX_REMAINING_BENCHMARK_FUNCTIONS_ONLY')) {
    return;
}
$options = getopt('', ['family:', 'native:', 'runtime-manifest:', 'output-dir:', 'source-dir:', 'mode:', 'samples:',
    'order-seed:', 'timeout:', 'worker:', 'fixture-path:', 'result-store:', 'phase-journal:']);
try {
    if (isset($options['worker'])) {
        $root = realpath((string) ($options['source-dir'] ?? '')) ?: throw new InvalidArgumentException('Missing worker source.');
        require $root.'/vendor/autoload.php';
        $fixture = unserialize((string) file_get_contents((string) ($options['fixture-path'] ?? '')), ['allowed_classes' => false]);
        if (! is_array($fixture)) {
            throw new InvalidArgumentException('Missing frozen fixture.');
        }
        echo json_encode(remainingBenchmarkWorker((string) ($options['family'] ?? ''), (string) $options['worker'], $fixture,
            (string) ($options['native'] ?? ''), $root, (string) ($options['result-store'] ?? ''),
            (string) ($options['phase-journal'] ?? '')), JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    exit(remainingBenchmarkRun($options));
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['status' => 'invalid', 'error' => $error->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(2);
}

/** @return class-string<RemainingMetadataBenchmarkFixtures>|class-string<RemainingArchiveBenchmarkFixtures> */
function remainingBenchmarkClass(string $family): string
{
    return match ($family) {
        'metadata-modules' => RemainingMetadataBenchmarkFixtures::class,
        'archive-modules' => RemainingArchiveBenchmarkFixtures::class,
        default => throw new InvalidArgumentException('Unknown remaining-module family.'),
    };
}

/**
 * @param  array<string,mixed>  $options
 * @return array{family:string,mode:string,samples:int,seed:int,timeout:float}
 */
function remainingBenchmarkOptions(array $options): array
{
    $family = (string) ($options['family'] ?? '');
    remainingBenchmarkClass($family);
    $mode = (string) ($options['mode'] ?? 'screen');
    $samples = filter_var($options['samples'] ?? ($mode === 'qualification' ? 1000 : 31), FILTER_VALIDATE_INT);
    $seed = filter_var($options['order-seed'] ?? 20261015, FILTER_VALIDATE_INT);
    $timeout = filter_var($options['timeout'] ?? 30, FILTER_VALIDATE_FLOAT);
    if (! in_array($mode, ['screen', 'qualification'], true) || $samples === false || $samples < 1 || $samples > 10000
        || ($mode === 'qualification' && $samples < 1000) || $seed === false || $seed < 1 || $seed > 2147483646
        || $timeout === false || ! is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
        throw new InvalidArgumentException('Invalid paired protocol; qualification requires at least 1000 pairs.');
    }

    return ['family' => $family, 'mode' => $mode, 'samples' => $samples, 'seed' => $seed, 'timeout' => $timeout];
}

/** @return array<string,string> */
function remainingBenchmarkInstrumentation(): array
{
    $paths = ['scripts/benchmark-remaining-modules.php', 'scripts/benchmark-sample-planning.php',
        'scripts/benchmark-article-processing.php', 'scripts/benchmark-yenc.php',
        'tests/Fixtures/SamplePlanningBenchmarkFixtures.php', 'tests/Fixtures/RemainingMetadataBenchmarkFixtures.php',
        'tests/Fixtures/RemainingArchiveBenchmarkFixtures.php'];
    $hashes = [];
    foreach ($paths as $path) {
        $hashes[$path] = hash_file('sha256', dirname(__DIR__).'/'.$path) ?: throw new RuntimeException('Missing instrumentation: '.$path);
    }

    return $hashes;
}

/** @return array<string,string> */
function remainingBenchmarkSources(string $root): array
{
    $sources = sampleBenchmarkSources($root);
    $paths = ['app/Services/Releases/Fingerprint/NativeMetadataParser.php',
        ...RemainingMetadataBenchmarkFixtures::sourcePaths(), ...RemainingArchiveBenchmarkFixtures::sourcePaths()];
    foreach (array_unique($paths) as $path) {
        $sources[$path] = hash_file('sha256', $root.'/'.$path) ?: throw new RuntimeException('Missing benchmark source: '.$path);
    }
    ksort($sources);

    return $sources;
}

/** @return array{root:string,files:array<string,string>,classes:array<string,string>} */
function remainingBenchmarkRuntime(string $root): array
{
    $allowed = array_map(static fn (string $path): string => dirname(__DIR__).'/'.$path, array_keys(remainingBenchmarkInstrumentation()));
    $path = (new ReflectionClass(NativeMetadataParser::class))->getFileName();
    if (! is_string($path) || ! str_starts_with($path, $root.'/')) {
        throw new RuntimeException('Native parser escaped the frozen runtime.');
    }
    $files = [];
    foreach (get_included_files() as $file) {
        $file = realpath($file) ?: throw new RuntimeException('Missing runtime file.');
        if (in_array($file, $allowed, true)) {
            continue;
        }
        if (! str_starts_with($file, $root.'/')) {
            throw new RuntimeException('Included file escaped frozen runtime.');
        }
        $files[substr($file, strlen($root) + 1)] = hash_file('sha256', $file) ?: throw new RuntimeException('Missing runtime hash.');
    }
    ksort($files);

    return ['root' => $root, 'files' => $files, 'classes' => [NativeMetadataParser::class => substr($path, strlen($root) + 1)]];
}

/** @return array{path:string,sha256:string} */
function remainingBenchmarkRetain(string $directory, mixed $value): array
{
    if (! is_dir($directory) || is_link($directory)) {
        throw new RuntimeException('Result store must be an owned existing directory.');
    }
    $bytes = serialize($value);
    $hash = hash('sha256', $bytes);
    $path = $directory.'/'.$hash.'.serialized';
    if (is_link($path)) {
        throw new RuntimeException('Refuse symlink in result store.');
    }
    if (! file_exists($path)) {
        $stream = fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot create retained result.');
        }
        try {
            $written = fwrite($stream, $bytes);
            if ($written !== strlen($bytes) || ! fflush($stream)) {
                throw new RuntimeException('Cannot retain complete result.');
            }
        } finally {
            fclose($stream);
        }
    }
    if (hash_file('sha256', $path) !== $hash) {
        throw new RuntimeException('Retained result identity changed.');
    }

    return ['path' => $path, 'sha256' => $hash];
}

/** @param array<string,mixed> $row */
function remainingBenchmarkJournal(string $path, array $row): void
{
    $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
    if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
        throw new RuntimeException('Cannot flush complete phase receipt.');
    }
}

/**
 * @param  array{full:mixed,projection:mixed}  $value
 * @param  array<string,mixed>  $row
 * @return array<string,mixed>
 */
function remainingBenchmarkRetainPhase(string $store, string $journal, string $phase, array $value, array $row): array
{
    $row['projection'] = remainingBenchmarkRetain($store, $value['projection']);
    $row['full'] = remainingBenchmarkRetain($store, $value['full']);
    remainingBenchmarkJournal($journal, ['phase' => $phase, 'status' => 'observed', ...$row]);

    return $row;
}

/** @return array{cache:string,engine:string} */
function remainingBenchmarkNativeIdentity(string $module): array
{
    return match ($module) {
        'article_dates', 'article-dates' => ['cache' => 'dateLibraries', 'engine' => 'nntmux-rust-article-dates'],
        'article_identity', 'article-identity' => ['cache' => 'identityLibraries', 'engine' => 'nntmux-rust-article-identity'],
        'article_xref', 'article-xref' => ['cache' => 'xrefLibraries', 'engine' => 'nntmux-rust-article-xref'],
        'iso_primary_copies', 'iso-primary-copies', 'rar5_copy_checksums', 'rar5-copy-checksums', 'rar5_quick_open', 'rar5-quick-open' => ['cache' => 'libraries', 'engine' => 'nntmux-rust-ffi'],
        default => throw new InvalidArgumentException('Unknown module.'),
    };
}

/**
 * @param  array<string,mixed>  $fixture
 * @return array<string,mixed>
 */
function remainingBenchmarkWorker(string $family, string $backend, array $fixture, string $library, string $root, string $store, string $journal = ''): array
{
    if (! in_array($backend, ['php', 'native'], true)) {
        throw new InvalidArgumentException('Unknown worker backend.');
    }
    $class = remainingBenchmarkClass($family);
    $journal = $journal !== '' ? $journal : $store.'/'.bin2hex(random_bytes(8)).'.phases.jsonl';
    $stream = fopen($journal, 'x');
    if ($stream === false) {
        throw new RuntimeException('Phase journal must be new.');
    }
    fclose($stream);
    $previous = Container::getInstance();
    $container = new Container;
    $container->instance('config', new Repository(['yenc' => ['native_library' => $library]]));
    Container::setInstance($container);
    try {
        $parser = new NativeMetadataParser;
        $observations = [];
        $serialized = null;
        $full = null;
        $identity = remainingBenchmarkNativeIdentity((string) $fixture['module']);
        foreach (['cold', 'warmup', 'warm'] as $phase) {
            $cpu = getrusage();
            $throttling = @file_get_contents('/sys/fs/cgroup/cpu.stat');
            $started = hrtime(true);
            try {
                $value = $backend === 'native' ? $class::native($parser, $fixture) : ['full' => null, 'projection' => $class::baseline($fixture)];
            } catch (Throwable $error) {
                remainingBenchmarkJournal($journal, ['phase' => $phase, 'status' => 'invalid', 'error' => $error->getMessage()]);
                throw $error;
            }
            $elapsed = (hrtime(true) - $started) / 1e6;
            $encoded = serialize($value['projection']);
            $cache = (new ReflectionProperty(NativeMetadataParser::class, $identity['cache']))->getValue();
            $cached = ($cache[getmypid().':'.(realpath($library) ?: '')] ?? null) instanceof FFI;
            $engine = $value['full']['parser_engine'] ?? null;
            $correct = $class::correct($fixture, $value['projection']) && ($serialized === null || $encoded === $serialized)
                && ($backend !== 'native' || ($cached && $engine === $identity['engine']));
            $serialized ??= $encoded;
            if ($full !== null && serialize($value['full']) !== serialize($full)) {
                $correct = false;
            }
            $full = $value['full'];
            $row = ['elapsed_ms' => $elapsed, 'correct' => $correct,
                'projection_sha256' => hash('sha256', $encoded), 'selected_library_cached' => $cached, 'parser_engine' => $engine,
                'process_cpu_before' => $cpu, 'process_cpu_after' => getrusage(), 'cgroup_cpu_stat_before' => $throttling,
                'cgroup_cpu_stat_after' => @file_get_contents('/sys/fs/cgroup/cpu.stat')];
            $observations[$phase] = remainingBenchmarkRetainPhase($store, $journal, $phase, $value, $row);
        }

        return ['status' => 'ok', 'backend' => $backend, 'median_ms' => $observations['warm']['elapsed_ms'],
            'observations' => $observations, 'projection' => $observations['warm']['projection'], 'full' => $observations['warm']['full'],
            'phase_journal' => ['path' => $journal, 'sha256' => hash_file('sha256', $journal)],
            'fixture_sha256' => hash('sha256', serialize($fixture)), 'library_sha256' => $backend === 'native' ? hash_file('sha256', $library) : null,
            'runtime_sources' => remainingBenchmarkRuntime($root), 'instrumentation' => remainingBenchmarkInstrumentation(),
            'runtime' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'sapi' => PHP_SAPI, 'ffi' => ini_get('ffi.enable'),
                'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit')]];
    } finally {
        Container::setInstance($previous);
    }
}

/**
 * @param  array<string,mixed>  $child
 * @param  array<string,mixed>  $snapshot
 * @param  array<string,string>  $instrumentation
 */
function remainingBenchmarkEligible(array $child, string $backend, string $module, string $fixtureHash, string $libraryHash,
    string $store, array $snapshot, array $instrumentation, string $journal = ''): bool
{
    $result = $child['result'] ?? null;
    if (($child['status'] ?? null) !== 'ok' || ($child['stdout_truncated_for_json'] ?? true) || ! is_array($result)
        || ($result['backend'] ?? null) !== $backend || ($result['fixture_sha256'] ?? null) !== $fixtureHash
        || ($result['instrumentation'] ?? null) !== $instrumentation || ! articleBenchmarkRuntimeMatches($child, $snapshot)
        || ($result['runtime_sources']['classes'][NativeMetadataParser::class] ?? null) !== 'app/Services/Releases/Fingerprint/NativeMetadataParser.php'
        || ($backend === 'native' && ($result['library_sha256'] ?? null) !== $libraryHash)) {
        return false;
    }
    $receipt = $result['phase_journal'] ?? null;
    if (! is_array($receipt) || ! is_string($receipt['path'] ?? null)
        || ($journal !== '' ? $receipt['path'] !== $journal : ! str_starts_with($receipt['path'], $store.'/'))
        || is_link($receipt['path']) || ! is_file($receipt['path']) || hash_file('sha256', $receipt['path']) !== ($receipt['sha256'] ?? null)) {
        return false;
    }
    foreach (['projection', 'full'] as $key) {
        $object = $result[$key] ?? null;
        if (! is_array($object) || ! is_string($object['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $object['sha256'])
            || ($object['path'] ?? null) !== $store.'/'.$object['sha256'].'.serialized' || is_link($object['path'])
            || ! is_file($object['path']) || hash_file('sha256', $object['path']) !== $object['sha256']) {
            return false;
        }
    }
    $identity = remainingBenchmarkNativeIdentity($module);
    foreach (['cold', 'warmup', 'warm'] as $phase) {
        $row = $result['observations'][$phase] ?? null;
        if (! is_array($row) || ($row['correct'] ?? null) !== true || ! is_numeric($row['elapsed_ms'] ?? null)
            || ! is_finite((float) $row['elapsed_ms']) || $row['elapsed_ms'] <= 0
            || ($row['projection_sha256'] ?? null) !== $result['projection']['sha256']
            || ($backend === 'php' && (($row['selected_library_cached'] ?? null) !== false || ($row['parser_engine'] ?? null) !== null))
            || ($backend === 'native' && (($row['selected_library_cached'] ?? null) !== true || ($row['parser_engine'] ?? null) !== $identity['engine']))) {
            return false;
        }
        foreach (['projection', 'full'] as $key) {
            $object = $row[$key] ?? null;
            if (! is_array($object) || ! is_string($object['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $object['sha256'])
                || ($object['path'] ?? null) !== $store.'/'.$object['sha256'].'.serialized' || is_link($object['path'])
                || ! is_file($object['path']) || hash_file('sha256', $object['path']) !== $object['sha256']) {
                return false;
            }
        }
    }

    return true;
}

/**
 * @param  list<float>  $baseline
 * @param  list<float>  $native
 * @return array<string,mixed>
 */
function remainingBenchmarkDecision(array $baseline, array $native, int $invalid, string $category, string $mode, int $strata): array
{
    if ($invalid > 0 || $baseline === [] || count($baseline) !== count($native)) {
        return ['status' => 'invalid', 'invalid_pairs' => $invalid, 'eligible_pairs' => count($baseline), 'qualifies' => false];
    }
    $quantile = $category === 'uncommon' ? 0.99 : 0.5;
    $alpha = 0.05 / ($strata * 2 * 2);
    $php = sampleBenchmarkQuantile($baseline, $quantile, $alpha);
    $rust = sampleBenchmarkQuantile($native, $quantile, $alpha);
    $low = $php['interval'][0] !== null && $rust['interval'][1] !== null ? $php['interval'][0] / $rust['interval'][1] : null;
    $high = $php['interval'][1] !== null && $rust['interval'][0] !== null ? $php['interval'][1] / $rust['interval'][0] : null;
    $passes = $mode === 'qualification' && count($baseline) >= 1000 && $low !== null && $low >= 1.5;

    return ['status' => $passes ? 'qualified_projection' : ($high !== null && $high < 1.5 ? 'threshold_not_met' : 'inconclusive'),
        'eligible_pairs' => count($baseline), 'invalid_pairs' => 0, 'metric' => $quantile === 0.99 ? 'p99_latency' : 'median_latency',
        'php' => $php, 'native' => $rust, 'php_over_native' => $php['estimate'] / $rust['estimate'], 'marginal_alpha' => $alpha,
        'simultaneous_95_percent_speedup_interval' => [$low, $high], 'qualifies' => $passes,
        'evidence' => $mode === 'screen' ? 'screen_only_no_adoption' : 'reference_projection_only_no_adoption'];
}

/** @return array<string,mixed> */
function remainingBenchmarkAcceptance(bool $stable, int $invalid, bool $allGates): array
{
    return ['baseline_kind' => 'independent_php_reference_projection', 'performance_gates_passed' => $stable && $invalid === 0 && $allGates,
        'adoption_qualified' => false, 'acceptance_criteria_met' => false,
        'scope_limitation' => 'New PHP reference covers only declared shared outputs; richer native output and complete application semantics are not equivalent. No production replacement qualification.'];
}

/** @param array<string,mixed> $options */
function remainingBenchmarkRun(array $options): int
{
    $protocol = remainingBenchmarkOptions($options);
    $family = $protocol['family'];
    $class = remainingBenchmarkClass($family);
    $strata = $class::strata();
    $directory = (string) ($options['output-dir'] ?? '');
    if ($directory === '' || file_exists($directory) || ! mkdir($directory, 0700, true)) {
        throw new InvalidArgumentException('Output directory must be explicit and new.');
    }
    $directory = realpath($directory) ?: throw new RuntimeException('Output cannot resolve.');
    $write = static function (array $row) use ($directory): void {
        $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($directory.'/observations.jsonl', $line, FILE_APPEND) !== strlen($line)) {
            throw new RuntimeException('Cannot retain complete observation.');
        }
        echo $line;
    };
    $instrumentation = remainingBenchmarkInstrumentation();
    $write(['type' => 'protocol', 'protocol' => 'remaining-modules-v1', 'family' => $family, 'mode' => $protocol['mode'],
        'samples_per_stratum' => $protocol['samples'], 'order_seed' => $protocol['seed'], 'timeout_seconds' => $protocol['timeout'],
        'strata' => $strata, 'instrumentation' => $instrumentation, 'baseline_kind' => 'independent_php_reference_projection',
        'confidence_family_size' => count($strata) * 4, 'decision_rule' => 'Zero invalid pairs; >=1000 independent pairs; simultaneous conservative 95% lower speedup >=1.5 on common medians and uncommon p99, cold and warm. Projection only; no adoption.',
        'failure_policy' => 'Stop the entire run after the first invalid pair; retain both observations and the failed pair. No observation retries; unattempted strata do not qualify.',
        'timing' => 'Fresh PHP process per backend observation; cold call, one warmup, warm call. Native actual wrapper packs input, allocates/copies FFI, computes full richer result, copies/frees JSON, decodes and projects inside call timing. PHP reference computes shared projection only. PHP startup/autoload excluded and process elapsed separately retained.',
        'native_proof' => 'Final real NativeMetadataParser class from selected source; module engine and selected-library private FFI cache verified after every call. No fallback.']);
    if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
        $write(['type' => 'summary', 'status' => 'skipped', 'reason' => 'Actual native wrappers require Linux CLI with FFI.', ...remainingBenchmarkAcceptance(false, 0, false)]);

        return 2;
    }
    try {
        $origin = realpath((string) ($options['source-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Missing source.');
        $sources = remainingBenchmarkSources($origin);
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
                throw new RuntimeException('Cannot freeze instrumentation.');
            }
        }
        $store = $directory.'/objects';
        mkdir($store, 0700);
        $write(['type' => 'setup', 'source' => $snapshot, 'build_source_identities' => $sources, 'library' => $library]);
        $state = $protocol['seed'];
        $invalidTotal = 0;
        $allGates = true;
        $results = [];
        $fixtures = [];
        $objects = [];
        foreach ($strata as $name => $definition) {
            $fixture = $class::make($name);
            if (($fixture['module'] ?? null) !== $definition['module'] || ! $class::correct($fixture, $class::baseline($fixture))) {
                throw new RuntimeException('Reference failed independent fixture oracle: '.$name);
            }
            $fixturePath = $directory.'/'.$name.'.fixture.serialized';
            file_put_contents($fixturePath, serialize($fixture));
            $fixtureHash = hash_file('sha256', $fixturePath);
            $fixtures[$fixturePath] = $fixtureHash;
            $write(['type' => 'fixture', 'stratum' => $name, 'module' => $definition['module'], 'sha256' => $fixtureHash, 'path' => $fixturePath]);
            $latency = ['cold' => ['php' => [], 'native' => []], 'warm' => ['php' => [], 'native' => []]];
            $invalid = 0;
            for ($pair = 0; $pair < $protocol['samples']; $pair++) {
                $order = yencBenchmarkRandom($state) % 2 === 0 ? ['php', 'native'] : ['native', 'php'];
                $children = [];
                $eligible = true;
                foreach ($order as $backend) {
                    $journal = $directory.'/'.$name.'-'.$pair.'-'.$backend.'.phases.jsonl';
                    $command = [...articleBenchmarkPhpCommand(), $directory.'/instrumentation/scripts/benchmark-remaining-modules.php',
                        '--family='.$family, '--worker='.$backend, '--source-dir='.$snapshot['root'], '--native='.$library['path'],
                        '--fixture-path='.$fixturePath, '--result-store='.$store, '--phase-journal='.$journal];
                    $before = hash_file('sha256', $fixturePath) === $fixtureHash;
                    $child = yencBenchmarkRunChild($command, $directory.'/'.$name.'-'.$pair.'-'.$backend, $protocol['timeout']);
                    $good = $before && hash_file('sha256', $fixturePath) === $fixtureHash
                        && remainingBenchmarkEligible($child, $backend, $definition['module'], $fixtureHash, $library['sha256'], $store, $snapshot, $instrumentation, $journal);
                    $eligible = $eligible && $good;
                    if ($good) {
                        foreach (['cold', 'warmup', 'warm'] as $phase) {
                            foreach (['projection', 'full'] as $key) {
                                $objects[$child['result']['observations'][$phase][$key]['path']] = $child['result']['observations'][$phase][$key]['sha256'];
                            }
                        }
                        $objects[$journal] = $child['result']['phase_journal']['sha256'];
                    }
                    $children[$backend] = $child;
                    $write(['type' => 'observation', 'stratum' => $name, 'pair' => $pair, 'backend' => $backend, 'eligible' => $good, 'child' => $child, 'command' => $command]);
                }
                $left = $children['php']['result']['projection']['path'] ?? null;
                $right = $children['native']['result']['projection']['path'] ?? null;
                $parity = is_string($left) && is_string($right) && is_file($left) && is_file($right) && file_get_contents($left) === file_get_contents($right);
                $eligible = $eligible && $parity;
                if ($eligible) {
                    foreach (['cold', 'warm'] as $phase) {
                        foreach (['php', 'native'] as $backend) {
                            $latency[$phase][$backend][] = $children[$backend]['result']['observations'][$phase]['elapsed_ms'];
                        }
                    }
                } else {
                    $invalid++;
                    $invalidTotal++;
                }
                $write(['type' => 'pair', 'stratum' => $name, 'pair' => $pair, 'order' => $order, 'fixture_sha256' => $fixtureHash,
                    'eligible' => $eligible, 'exact_serialized_projection_parity' => $parity]);
                if (! $eligible) {
                    throw new RuntimeException('Invalid pair stopped the run: '.$name.' pair '.$pair);
                }
            }
            $decision = [];
            foreach (['cold', 'warm'] as $phase) {
                $decision[$phase] = remainingBenchmarkDecision($latency[$phase]['php'], $latency[$phase]['native'], $invalid, $definition['category'], $protocol['mode'], count($strata));
                $allGates = $allGates && $decision[$phase]['qualifies'];
            }
            $results[$name] = $decision;
            $write(['type' => 'stratum', 'stratum' => $name, 'module' => $definition['module'], 'category' => $definition['category'], 'decision' => $decision]);
        }
        $stable = articleBenchmarkGraphStable($snapshot, $origin) && articleBenchmarkGraphStable($snapshot)
            && hash_file('sha256', $library['path']) === $library['sha256'] && remainingBenchmarkSources($origin) === $sources
            && remainingBenchmarkInstrumentation() === $instrumentation;
        foreach ($instrumentation as $path => $hash) {
            $stable = $stable && hash_file('sha256', $directory.'/instrumentation/'.$path) === $hash;
        }
        foreach ($fixtures as $path => $hash) {
            $stable = $stable && hash_file('sha256', $path) === $hash;
        }
        foreach ($objects as $path => $hash) {
            $stable = $stable && ! is_link($path) && is_file($path) && hash_file('sha256', $path) === $hash;
        }
        $write(['type' => 'summary', 'status' => ! $stable || $invalidTotal > 0 ? 'invalid' : 'measured', 'identities_stable' => $stable,
            'invalid_pairs' => $invalidTotal, 'strata' => $results, ...remainingBenchmarkAcceptance($stable, $invalidTotal, $allGates)]);

        return ! $stable || $invalidTotal > 0 ? 2 : ($protocol['mode'] === 'qualification' && ! $allGates ? 1 : 0);
    } catch (Throwable $error) {
        $write(['type' => 'summary', 'status' => 'invalid', 'reason' => $error->getMessage(),
            'invalid_pairs' => $invalidTotal ?? 0, 'strata' => $results ?? [],
            ...remainingBenchmarkAcceptance(false, $invalidTotal ?? 0, false)]);

        return 2;
    }
}
