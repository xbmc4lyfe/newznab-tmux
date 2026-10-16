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
    'baseline-native:', 'baseline-runtime-manifest:', 'order-seed:', 'timeout:', 'worker:', 'fixture-path:', 'result-store:', 'phase-journal:']);
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
        'metadata-modules', 'date-cpp' => RemainingMetadataBenchmarkFixtures::class,
        'archive-modules' => RemainingArchiveBenchmarkFixtures::class,
        default => throw new InvalidArgumentException('Unknown remaining-module family.'),
    };
}

/** @return array<string, array{module:string,category:string,comparison_scope:string}> */
function remainingBenchmarkStrata(string $family): array
{
    $class = remainingBenchmarkClass($family);
    $strata = $class::strata();

    if ($family === 'date-cpp') {
        $strata = array_filter($strata, static fn (array $definition): bool => $definition['module'] === 'article_dates');
        foreach ($strata as &$definition) {
            $definition['comparison_scope'] = 'restricted-date-complete-metadata-v1';
        }
        unset($definition);
    }

    return $strata;
}

function remainingBenchmarkBaselineKind(string $family): string
{
    remainingBenchmarkClass($family);

    return $family === 'date-cpp' ? 'cpp_nzbget_restricted_date_complete_metadata' : 'independent_php_reference_projection';
}

/**
 * @param  array<string,mixed>  $fixture
 * @param  array<string,mixed>  $left
 * @param  array<string,mixed>  $right
 */
function remainingBenchmarkDateFullParity(array $fixture, array $left, array $right): bool
{
    $expected = RemainingMetadataBenchmarkFixtures::expectedFullDates($fixture);

    return RemainingMetadataBenchmarkFixtures::normalizeFullDates($left) === $expected
        && RemainingMetadataBenchmarkFixtures::normalizeFullDates($right) === $expected;
}

function remainingBenchmarkValidateWorker(string $family, string $backend, string $module): void
{
    remainingBenchmarkClass($family);
    if (! in_array($backend, $family === 'date-cpp' ? ['cpp', 'native'] : ['php', 'native'], true)
        || ($family === 'date-cpp' && $module !== 'article_dates')) {
        throw new InvalidArgumentException('Worker backend or module is outside its declared family.');
    }
}

/**
 * @param  array<string,mixed>  $manifest
 * @param  array<string,string>  $sources
 */
function remainingBenchmarkVerifyCppManifest(array $manifest, array $sources, string $libraryHash): void
{
    $compiler = $manifest['compiler'] ?? null;
    $upstream = $manifest['upstream'] ?? null;
    if (($manifest['baseline_kind'] ?? null) !== remainingBenchmarkBaselineKind('date-cpp')
        || ($manifest['baseline_library_sha256'] ?? null) !== $libraryHash
        || ! is_array($manifest['baseline_source_sha256'] ?? null)
        || ! is_array($compiler) || ! is_string($compiler['path'] ?? null) || $compiler['path'] === ''
        || ! is_string($compiler['version'] ?? null) || $compiler['version'] === ''
        || ! is_string($compiler['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $compiler['sha256'])
        || ! is_array($compiler['flags'] ?? null) || ! in_array('-std=c++20', $compiler['flags'], true)
        || ! in_array('-O3', $compiler['flags'], true)
        || ! is_array($upstream) || ($upstream['commit'] ?? null) !== '8f2f409d4e263ecac3313404ed4685e7dca3d9ec'
        || ($upstream['source_sha256'] ?? null) !== '14a1c4c82c6c551806b142b3f9ac177a39af48dbaa92bceb763f98a21f0390d8') {
        throw new RuntimeException('Missing or inconsistent C++ baseline build receipt.');
    }
    foreach ($compiler['flags'] as $flag) {
        if (! is_string($flag) || ! in_array($flag, ['-std=c++20', '-O3', '-DNDEBUG', '-fPIC', '-shared', '-Wall', '-Wextra', '-Werror'], true)) {
            throw new RuntimeException('C++ baseline flags must identify the genuine native implementation.');
        }
    }
    foreach ($sources as $path => $hash) {
        if (($manifest['baseline_source_sha256'][$path] ?? null) !== $hash) {
            throw new RuntimeException('C++ build receipt requires source identity: '.$path);
        }
    }
}

/**
 * @param  array<string,mixed>  $options
 * @return array{family:string,mode:string,samples:int,seed:int,timeout:float}
 */
function remainingBenchmarkOptions(array $options): array
{
    $family = (string) ($options['family'] ?? '');
    remainingBenchmarkClass($family);
    $hasBaseline = isset($options['baseline-native']) || isset($options['baseline-runtime-manifest']);
    if (($family !== 'date-cpp' && $hasBaseline)
        || ($family === 'date-cpp' && ((string) ($options['baseline-native'] ?? '') === ''
            || (string) ($options['baseline-runtime-manifest'] ?? '') === ''))) {
        throw new InvalidArgumentException('The date-cpp family requires its own baseline library and build receipt.');
    }
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
function remainingBenchmarkNativeIdentity(string $module, string $backend = 'native'): array
{
    if ($backend === 'cpp') {
        if ($module !== 'article_dates') {
            throw new InvalidArgumentException('The C++ reference only supports article dates.');
        }

        return ['cache' => 'dateLibraries', 'engine' => 'nntmux-cpp-nzbget-date-reference'];
    }

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
    remainingBenchmarkValidateWorker($family, $backend, (string) ($fixture['module'] ?? ''));
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
        $identity = remainingBenchmarkNativeIdentity((string) $fixture['module'], $backend);
        foreach (['cold', 'warmup', 'warm'] as $phase) {
            $cpu = getrusage();
            $throttling = @file_get_contents('/sys/fs/cgroup/cpu.stat');
            $started = hrtime(true);
            try {
                $value = $backend !== 'php' ? $class::native($parser, $fixture) : ['full' => null, 'projection' => $class::baseline($fixture)];
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
                && ($backend === 'php' || ($cached && $engine === $identity['engine']));
            if ($family === 'date-cpp') {
                $correct = $correct && is_array($value['full'])
                    && remainingBenchmarkDateFullParity($fixture, $value['full'], $value['full']);
            }
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
            'fixture_sha256' => hash('sha256', serialize($fixture)), 'library_sha256' => $backend !== 'php' ? hash_file('sha256', $library) : null,
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
    if (! in_array($backend, ['php', 'native', 'cpp'], true) || ($backend === 'cpp' && $module !== 'article_dates')) {
        return false;
    }
    if (($child['status'] ?? null) !== 'ok' || ($child['stdout_truncated_for_json'] ?? true) || ! is_array($result)
        || ($result['backend'] ?? null) !== $backend || ($result['fixture_sha256'] ?? null) !== $fixtureHash
        || ($result['instrumentation'] ?? null) !== $instrumentation || ! articleBenchmarkRuntimeMatches($child, $snapshot)
        || ($result['runtime_sources']['classes'][NativeMetadataParser::class] ?? null) !== 'app/Services/Releases/Fingerprint/NativeMetadataParser.php'
        || ($backend !== 'php' && ($result['library_sha256'] ?? null) !== $libraryHash)) {
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
    $identity = remainingBenchmarkNativeIdentity($module, $backend);
    foreach (['cold', 'warmup', 'warm'] as $phase) {
        $row = $result['observations'][$phase] ?? null;
        if (! is_array($row) || ($row['correct'] ?? null) !== true || ! is_numeric($row['elapsed_ms'] ?? null)
            || ! is_finite((float) $row['elapsed_ms']) || $row['elapsed_ms'] <= 0
            || ($row['projection_sha256'] ?? null) !== $result['projection']['sha256']
            || ($backend === 'php' && (($row['selected_library_cached'] ?? null) !== false || ($row['parser_engine'] ?? null) !== null))
            || ($backend !== 'php' && (($row['selected_library_cached'] ?? null) !== true || ($row['parser_engine'] ?? null) !== $identity['engine']))) {
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
function remainingBenchmarkDecision(array $baseline, array $native, int $invalid, string $category, string $mode, int $strata, string $family = 'metadata-modules'): array
{
    if ($invalid > 0 || $baseline === [] || count($baseline) !== count($native)) {
        return ['status' => 'invalid', 'invalid_pairs' => $invalid, 'eligible_pairs' => count($baseline), 'qualifies' => false];
    }
    $quantile = $category === 'uncommon' ? 0.99 : 0.5;
    if ($family === 'date-cpp' && $mode === 'screen') {
        sort($baseline);
        sort($native);
        $rank = (int) ceil(count($baseline) * $quantile) - 1;
        $php = ['estimate' => $quantile === 0.5 ? yencBenchmarkMedian($baseline) : $baseline[$rank], 'interval' => [null, null], 'samples' => count($baseline)];
        $rust = ['estimate' => $quantile === 0.5 ? yencBenchmarkMedian($native) : $native[$rank], 'interval' => [null, null], 'samples' => count($native)];

        return ['status' => 'descriptive_screen', 'eligible_pairs' => count($baseline), 'invalid_pairs' => 0,
            'metric' => $quantile === 0.99 ? 'p99_latency' : 'median_latency', 'php' => $php, 'native' => $rust,
            'php_over_native' => $php['estimate'] / $rust['estimate'], 'marginal_alpha' => null,
            'simultaneous_95_percent_speedup_interval' => [null, null], 'qualifies' => false,
            'evidence' => 'screen_only_balanced_blocks_no_inference'];
    }
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

/** @return list<array{string,string}> */
function remainingBenchmarkDateCppPairOrders(int $samples, int $seed): array
{
    if ($samples < 1 || $samples > 10000 || $seed < 1 || $seed > 2147483646) {
        throw new InvalidArgumentException('Invalid bounded date screen ordering parameters.');
    }
    $state = $seed;
    $orders = [];
    for ($pair = 0; $pair < $samples; $pair += 2) {
        $order = yencBenchmarkRandom($state) % 2 === 0 ? ['cpp', 'native'] : ['native', 'cpp'];
        $orders[] = $order;
        if ($pair + 1 < $samples) {
            $orders[] = [$order[1], $order[0]];
        }
    }

    return $orders;
}

/**
 * @param  array{family:string,mode:string,samples:int,seed:int}  $protocol
 * @return list<array{string,string}>|null
 */
function remainingBenchmarkScreenOrders(array $protocol): ?array
{
    return $protocol['family'] === 'date-cpp' && $protocol['mode'] === 'screen'
        ? remainingBenchmarkDateCppPairOrders($protocol['samples'], $protocol['seed']) : null;
}

/** @return array<string,mixed> */
function remainingBenchmarkAcceptance(bool $stable, int $invalid, bool $allGates, string $family = 'metadata-modules'): array
{
    return ['baseline_kind' => remainingBenchmarkBaselineKind($family), 'performance_gates_passed' => $stable && $invalid === 0 && $allGates,
        'adoption_qualified' => false, 'acceptance_criteria_met' => false,
        'bounded_workload_qualified' => $family === 'date-cpp' && $stable && $invalid === 0 && $allGates,
        'scope_limitation' => $family === 'date-cpp'
            ? 'Complete date metadata parity for four fixed restricted C++ workloads, years 1900-9999 without comments. Only engine/profile identifiers may differ. No general grammar equivalence or production replacement qualification.'
            : 'New PHP reference covers only declared shared outputs; richer native output and complete application semantics are not equivalent. No production replacement qualification.'];
}

/**
 * Planned copy inventory, not a bound on earlier graph discovery or aggregate disk use.
 *
 * @param  list<string>  $paths
 * @return array{file_count:int,total_bytes:int,max_file_bytes:int,files:array<string,string>}
 */
function remainingBenchmarkSetupPreflight(string $root, array $paths, int $maximumFileBytes, int $maximumTotalBytes, int $maximumFiles): array
{
    if ($maximumFileBytes < 1 || $maximumTotalBytes < 1 || $maximumFiles < 1 || count($paths) > $maximumFiles) {
        throw new InvalidArgumentException('Invalid setup copy bounds or too many input files.');
    }
    $root = realpath($root) ?: throw new InvalidArgumentException('Setup source cannot resolve.');
    $files = [];
    $total = 0;
    $largest = 0;
    foreach ($paths as $path) {
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)
            || articleBenchmarkPrivatePath($path) || isset($files[$path])) {
            throw new InvalidArgumentException('Invalid or repeated setup input path.');
        }
        $resolved = realpath($root.'/'.$path);
        if ($resolved === false || ! str_starts_with($resolved, $root.'/') || ! is_file($resolved)
            || articleBenchmarkPrivatePath(substr($resolved, strlen($root) + 1))) {
            throw new RuntimeException('Setup input escapes source or is not a regular file.');
        }
        $handle = fopen($resolved, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open setup input.');
        }
        try {
            $before = fstat($handle);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000
                || $before['size'] > $maximumFileBytes || $before['size'] > $maximumTotalBytes - $total) {
                throw new RuntimeException('Setup input exceeds planned copy bytes.');
            }
            $size = $before['size'];
            $remaining = $size;
            $context = hash_init('sha256');
            while ($remaining > 0) {
                $chunk = fread($handle, min(65536, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Setup input changed or cannot be read.');
                }
                hash_update($context, $chunk);
                $remaining -= strlen($chunk);
            }
            $extra = fread($handle, 1);
            $after = fstat($handle);
            if ($extra !== '' || $after === false || $after['size'] !== $size
                || $after['mtime'] !== $before['mtime'] || $after['ctime'] !== $before['ctime']) {
                throw new RuntimeException('Setup input changed while hashing.');
            }
            $files[$path] = hash_final($context);
        } finally {
            fclose($handle);
        }
        $total += $size;
        $largest = max($largest, $size);
    }

    return ['file_count' => count($files), 'total_bytes' => $total, 'max_file_bytes' => $largest, 'files' => $files];
}

/**
 * Lower inherited regular-file output limits only after source setup has completed.
 *
 * @param  array<string,mixed>  $protocol
 * @return array<string,mixed>|null
 */
function remainingBenchmarkApplyScreenOutputLimit(array $protocol): ?array
{
    if (($protocol['family'] ?? null) !== 'date-cpp' || ($protocol['mode'] ?? null) !== 'screen') {
        return null;
    }
    if (PHP_OS_FAMILY !== 'Linux') {
        throw new RuntimeException('Date screen output enforcement requires Linux; limit readback alone is insufficient.');
    }
    if (! function_exists('posix_getrlimit') || ! function_exists('posix_setrlimit') || ! defined('POSIX_RLIMIT_FSIZE')) {
        throw new RuntimeException('Date screen needs POSIX regular-file limits.');
    }
    $limits = posix_getrlimit();
    if (! is_array($limits)) {
        throw new RuntimeException('Cannot inspect inherited file limits.');
    }
    $effective = 8 * 1024 * 1024;
    foreach (['soft filesize', 'hard filesize'] as $key) {
        $value = $limits[$key] ?? null;
        if ($value === 'unlimited' || $value === -1) {
            continue;
        }
        if (! is_int($value) || $value < 0) {
            throw new RuntimeException('Unknown inherited file limit.');
        }
        $effective = min($effective, $value);
    }
    $streams = [];
    foreach (['stdout' => STDOUT, 'stderr' => STDERR] as $name => $stream) {
        $stat = fstat($stream);
        if ($stat === false) {
            throw new RuntimeException('Cannot inspect benchmark output stream.');
        }
        $regular = ($stat['mode'] & 0170000) === 0100000;
        if ($regular && $stat['size'] >= $effective) {
            throw new RuntimeException('Existing output has no room under inherited file limit.');
        }
        $streams[$name] = $regular ? 'regular_file_capped' : 'not_a_regular_file_uncapped';
    }
    if (! posix_setrlimit(POSIX_RLIMIT_FSIZE, $effective, $effective)) {
        throw new RuntimeException('Cannot lower benchmark file limits.');
    }
    $observed = posix_getrlimit();
    if (! is_array($observed) || ($observed['soft filesize'] ?? null) !== $effective
        || ($observed['hard filesize'] ?? null) !== $effective) {
        throw new RuntimeException('Benchmark file limits did not take effect.');
    }

    return ['requested_file_limit_bytes' => 8388608, 'effective_file_limit_bytes' => $effective,
        'phase' => 'after_setup_before_workers', 'aggregate_limit_enforced' => false, 'streams' => $streams];
}

/** @param array<string,mixed> $options */
function remainingBenchmarkRun(array $options): int
{
    $protocol = remainingBenchmarkOptions($options);
    $screenOrders = remainingBenchmarkScreenOrders($protocol);
    $family = $protocol['family'];
    $class = remainingBenchmarkClass($family);
    $strata = remainingBenchmarkStrata($family);
    $baseline = $family === 'date-cpp' ? 'cpp' : 'php';
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
        'strata' => $strata, 'instrumentation' => $instrumentation, 'baseline_kind' => remainingBenchmarkBaselineKind($family),
        'confidence_family_size' => $screenOrders === null ? count($strata) * 4 : null,
        ...($screenOrders === null ? [] : ['order_strategy' => 'Seeded randomized two-pair blocks: four backend executions ABBA or BAAB; one final pair outside a complete block when the pair count is odd. Same seeded plan per stratum.']),
        'decision_rule' => $screenOrders !== null
            ? 'Descriptive empirical median/p99 ratios only; balanced two-pair blocks are not independent pair assignments. No inferential intervals, qualification or adoption.'
            : 'Zero invalid pairs; >=1000 independent pairs; simultaneous conservative 95% lower speedup >=1.5 on common medians and uncommon p99, cold and warm. Projection only; no adoption.',
        'failure_policy' => 'Stop the entire run after the first invalid pair; retain both observations and the failed pair. No observation retries; unattempted strata do not qualify.',
        'timing' => 'Fresh PHP process per backend observation; cold call, one warmup, warm call. Native actual wrapper packs input, allocates/copies FFI, computes full richer result, copies/frees JSON, decodes and projects inside call timing. Selected PHP reference computes shared projection only. The restricted C++ date family computes the complete date schema through the same FFI wrapper and ownership path; every phase must match independent complete-output oracles after normalizing only engine/profile identifiers. PHP startup/autoload excluded and process elapsed separately retained.',
        'native_proof' => 'Final real NativeMetadataParser class from selected source; module engine and selected-library private FFI cache verified after every call. No fallback.']);
    if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! extension_loaded('FFI')) {
        $write(['type' => 'summary', 'status' => 'skipped', 'reason' => 'Actual native wrappers require Linux CLI with FFI.', ...remainingBenchmarkAcceptance(false, 0, false, $family)]);

        return 2;
    }
    try {
        $origin = realpath((string) ($options['source-dir'] ?? dirname(__DIR__))) ?: throw new InvalidArgumentException('Missing source.');
        $setupPreflight = null;
        $setupReceipt = null;
        if ($screenOrders !== null) {
            // These are planned copy budgets, not aggregate storage quotas. Discovery precedes this check.
            $maximumFile = 32 * 1024 * 1024;
            $maximumTotal = 512 * 1024 * 1024;
            $maximumCount = 50000;
            $graphPlan = remainingBenchmarkSetupPreflight($origin, articleBenchmarkGraphFiles($origin), $maximumFile, $maximumTotal, $maximumCount);
            $instrumentationPlan = remainingBenchmarkSetupPreflight(dirname(__DIR__), array_keys($instrumentation), $maximumFile, $maximumTotal, $maximumCount);
            $plannedBytes = $graphPlan['total_bytes'] + $instrumentationPlan['total_bytes'];
            $plannedCount = $graphPlan['file_count'] + $instrumentationPlan['file_count'];
            $assetPlans = [];
            foreach (['native', 'runtime-manifest', 'baseline-native', 'baseline-runtime-manifest'] as $option) {
                $asset = realpath((string) ($options[$option] ?? ''));
                if ($asset === false) {
                    throw new InvalidArgumentException('Missing setup asset: '.$option);
                }
                $plan = remainingBenchmarkSetupPreflight(dirname($asset), [basename($asset)], $maximumFile, $maximumTotal, $maximumCount);
                $plannedBytes += $plan['total_bytes'];
                $plannedCount += $plan['file_count'];
                if (str_ends_with($option, 'manifest')) {
                    // Bound the physical input before decoding; account for the exact reencoded copy.
                    $encoded = json_encode(articleBenchmarkReadManifest($asset), JSON_THROW_ON_ERROR);
                    if (strlen($encoded) > $maximumFile) {
                        throw new RuntimeException('Reencoded setup manifest exceeds copy bound.');
                    }
                    $plannedBytes += strlen($encoded) - $plan['total_bytes'];
                }
                $options[$option] = $asset; // Consume the canonical input inspected by preflight.
                $assetPlans[$option] = ['path' => $asset, ...$plan];
            }
            if ($plannedBytes > $maximumTotal || $plannedCount > $maximumCount) {
                throw new RuntimeException('Combined setup exceeds planned copy bounds.');
            }
            $setupPreflight = ['graph' => $graphPlan, 'instrumentation' => $instrumentationPlan, 'assets' => $assetPlans,
                'planned_copy_bytes' => $plannedBytes, 'planned_copy_count' => $plannedCount,
                'maximum_input_file_bytes' => $maximumFile, 'maximum_planned_copy_bytes' => $maximumTotal,
                'maximum_planned_copy_count' => $maximumCount, 'aggregate_limit_enforced' => false,
                'discovery_work_bounded' => false];
            $setupJson = json_encode($setupPreflight, JSON_THROW_ON_ERROR);
            if (strlen($setupJson) > 8 * 1024 * 1024
                || file_put_contents($directory.'/setup-preflight.json', $setupJson) !== strlen($setupJson)) {
                throw new RuntimeException('Cannot retain bounded setup preflight inventory.');
            }
            $setupReceipt = array_diff_key($setupPreflight, array_flip(['graph', 'instrumentation', 'assets']));
            $setupReceipt['inventory_path'] = $directory.'/setup-preflight.json';
            $setupReceipt['inventory_sha256'] = hash('sha256', $setupJson);
            $write(['type' => 'setup_preflight', 'plan' => $setupReceipt]);
        }
        $sources = remainingBenchmarkSources($origin);
        $library = articleBenchmarkFreezeLibrary((string) ($options['native'] ?? ''), $directory.'/native.so');
        $manifest = articleBenchmarkReadManifest((string) ($options['runtime-manifest'] ?? ''));
        sampleBenchmarkVerifyManifest($manifest, $sources, $library['sha256']);
        file_put_contents($directory.'/build-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $receipts = [$directory.'/build-manifest.json' => hash_file('sha256', $directory.'/build-manifest.json')];
        if ($setupReceipt !== null) {
            $receipts[$setupReceipt['inventory_path']] = $setupReceipt['inventory_sha256'];
        }
        $libraries = ['native' => $library];
        if ($baseline === 'cpp') {
            $libraries['cpp'] = articleBenchmarkFreezeLibrary((string) $options['baseline-native'], $directory.'/baseline.so');
            $baselineManifest = articleBenchmarkReadManifest((string) $options['baseline-runtime-manifest']);
            remainingBenchmarkVerifyCppManifest($baselineManifest, $sources, $libraries['cpp']['sha256']);
            file_put_contents($directory.'/baseline-build-manifest.json', json_encode($baselineManifest, JSON_THROW_ON_ERROR));
            $receipts[$directory.'/baseline-build-manifest.json'] = hash_file('sha256', $directory.'/baseline-build-manifest.json');
        }

        $snapshot = articleBenchmarkFreezeGraph($origin, $directory.'/source');
        if ($setupPreflight !== null && $snapshot['files'] !== $setupPreflight['graph']['files']) {
            throw new RuntimeException('Frozen source graph differs from setup preflight.');
        }
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
        if ($setupPreflight !== null) {
            foreach ($setupPreflight['assets'] as $plan) {
                if (hash_file('sha256', $plan['path']) !== $plan['files'][basename($plan['path'])]) {
                    throw new RuntimeException('Setup asset changed after preflight.');
                }
            }
        }
        $outputLimit = remainingBenchmarkApplyScreenOutputLimit($protocol);
        $write(['type' => 'setup', 'source' => $snapshot, 'build_source_identities' => $sources, 'library' => $library, 'libraries' => $libraries,
            'setup_preflight' => $setupReceipt, 'output_limit' => $outputLimit]);
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
            $latency = ['cold' => [$baseline => [], 'native' => []], 'warm' => [$baseline => [], 'native' => []]];
            $invalid = 0;
            for ($pair = 0; $pair < $protocol['samples']; $pair++) {
                $order = $screenOrders !== null ? $screenOrders[$pair]
                    : (yencBenchmarkRandom($state) % 2 === 0 ? [$baseline, 'native'] : ['native', $baseline]);
                $children = [];
                $eligible = true;
                foreach ($order as $backend) {
                    $selectedLibrary = $libraries[$backend] ?? $library;
                    $journal = $directory.'/'.$name.'-'.$pair.'-'.$backend.'.phases.jsonl';
                    $command = [...articleBenchmarkPhpCommand(), $directory.'/instrumentation/scripts/benchmark-remaining-modules.php',
                        '--family='.$family, '--worker='.$backend, '--source-dir='.$snapshot['root'], '--native='.$selectedLibrary['path'],
                        '--fixture-path='.$fixturePath, '--result-store='.$store, '--phase-journal='.$journal];
                    $before = hash_file('sha256', $fixturePath) === $fixtureHash;
                    $child = yencBenchmarkRunChild($command, $directory.'/'.$name.'-'.$pair.'-'.$backend, $protocol['timeout']);
                    $good = $before && hash_file('sha256', $fixturePath) === $fixtureHash
                        && remainingBenchmarkEligible($child, $backend, $definition['module'], $fixtureHash, $selectedLibrary['sha256'], $store, $snapshot, $instrumentation, $journal);
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
                $left = $children[$baseline]['result']['projection']['path'] ?? null;
                $right = $children['native']['result']['projection']['path'] ?? null;
                $parity = is_string($left) && is_string($right) && is_file($left) && is_file($right) && file_get_contents($left) === file_get_contents($right);
                $fullParity = null;
                if ($family === 'date-cpp') {
                    $fullParity = true;
                    foreach (['cold', 'warmup', 'warm'] as $phase) {
                        $fullValues = [];
                        foreach ([$baseline, 'native'] as $backend) {
                            $path = $children[$backend]['result']['observations'][$phase]['full']['path'] ?? null;
                            $fullValues[] = is_string($path) && is_file($path)
                                ? unserialize((string) file_get_contents($path), ['allowed_classes' => false]) : null;
                        }
                        $fullParity = $fullParity && is_array($fullValues[0]) && is_array($fullValues[1])
                            && remainingBenchmarkDateFullParity($fixture, $fullValues[0], $fullValues[1]);
                    }
                }
                $eligible = $eligible && $parity && $fullParity !== false;
                if ($eligible) {
                    foreach (['cold', 'warm'] as $phase) {
                        foreach ([$baseline, 'native'] as $backend) {
                            $latency[$phase][$backend][] = $children[$backend]['result']['observations'][$phase]['elapsed_ms'];
                        }
                    }
                } else {
                    $invalid++;
                    $invalidTotal++;
                }
                $write(['type' => 'pair', 'stratum' => $name, 'pair' => $pair, 'order' => $order, 'fixture_sha256' => $fixtureHash,
                    'eligible' => $eligible, 'exact_serialized_projection_parity' => $parity, 'normalized_complete_date_parity' => $fullParity]);
                if (! $eligible) {
                    throw new RuntimeException('Invalid pair stopped the run: '.$name.' pair '.$pair);
                }
            }
            $decision = [];
            foreach (['cold', 'warm'] as $phase) {
                $decision[$phase] = remainingBenchmarkDecision($latency[$phase][$baseline], $latency[$phase]['native'], $invalid, $definition['category'], $protocol['mode'], count($strata), $family);
                if ($baseline === 'cpp') {
                    if ($decision[$phase]['status'] === 'qualified_projection') {
                        $decision[$phase]['status'] = 'qualified_restricted_complete_metadata';
                    }
                    $decision[$phase]['evidence'] = $protocol['mode'] === 'screen'
                        ? 'screen_only_balanced_blocks_no_inference' : 'restricted_complete_metadata_workload_only_no_adoption';
                    $decision[$phase]['cpp'] = $decision[$phase]['php'];
                    $decision[$phase]['cpp_over_native'] = $decision[$phase]['php_over_native'];
                    unset($decision[$phase]['php'], $decision[$phase]['php_over_native']);
                }
                $allGates = $allGates && $decision[$phase]['qualifies'];
            }
            $results[$name] = $decision;
            $write(['type' => 'stratum', 'stratum' => $name, 'module' => $definition['module'], 'category' => $definition['category'], 'decision' => $decision]);
        }
        $stable = articleBenchmarkGraphStable($snapshot, $origin) && articleBenchmarkGraphStable($snapshot)
            && hash_file('sha256', $library['path']) === $library['sha256'] && remainingBenchmarkSources($origin) === $sources
            && remainingBenchmarkInstrumentation() === $instrumentation;
        foreach ($receipts as $path => $hash) {
            $stable = $stable && ! is_link($path) && is_file($path) && hash_file('sha256', $path) === $hash;
        }
        foreach ($libraries as $frozenLibrary) {
            $stable = $stable && hash_file('sha256', $frozenLibrary['path']) === $frozenLibrary['sha256'];
        }
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
            'invalid_pairs' => $invalidTotal, 'strata' => $results, ...remainingBenchmarkAcceptance($stable, $invalidTotal, $allGates, $family)]);

        return ! $stable || $invalidTotal > 0 ? 2 : ($protocol['mode'] === 'qualification' && ! $allGates ? 1 : 0);
    } catch (Throwable $error) {
        $write(['type' => 'summary', 'status' => 'invalid', 'reason' => $error->getMessage(),
            'invalid_pairs' => $invalidTotal ?? 0, 'strata' => $results ?? [],
            ...remainingBenchmarkAcceptance(false, $invalidTotal ?? 0, false, $family)]);

        return 2;
    }
}
