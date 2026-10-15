<?php

declare(strict_types=1);

// SOURCE-ONLY: execution requires the coordinator's frozen protocol and queue grant.
use App\Services\Releases\Verification\NativeSpotCheckPlanner;
use App\Services\Releases\Verification\ProfiledNativeSpotCheckPlanner;
use App\Services\Releases\Verification\SpotCheckPlanner;
use Tests\Fixtures\SamplePlanningBenchmarkFixtures;

$arguments = $_SERVER['argv'] ?? null;
if (! is_array($arguments) || ! array_is_list($arguments) || count($arguments) !== 6) {
    throw new RuntimeException('Requires arm, source root, fixture, library and output arguments.');
}
foreach ($arguments as $argument) {
    if (! is_string($argument)) {
        throw new RuntimeException('CLI arguments must be strings.');
    }
}
[$script, $arm, $root, $fixturePath, $library, $output] = $arguments;
if (! in_array($arm, ['control', 'instrumented', 'php-reference'], true)
    || PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || php_uname('m') !== 'x86_64') {
    throw new RuntimeException('Requires a selected arm and actual x86_64 Linux CLI executor.');
}
$originalWrapper = $root.'/app/Services/Releases/Verification/NativeSpotCheckPlanner.php';
$wrapper = $arm === 'instrumented' ? __DIR__.'/NativeSpotCheckPlanner.instrumented.php' : $originalWrapper;
if (hash_file('sha256', $originalWrapper) !== 'aa43ff2b697dbdd91fef8d8de104579181828772a72a5db1bf0468e5950d8f42') {
    throw new RuntimeException('Control source identity mismatch.');
}
if (hash_file('sha256', $library) !== '02eeb2be7d182cbc046cd5358792795825acf9daac549a020afc0fb2ea847213') {
    throw new RuntimeException('Frozen native library identity mismatch.');
}
// Both arms load the exact original first. The private subclass is additional.
require $originalWrapper;
require $root.'/vendor/autoload.php';
if ($arm === 'instrumented') {
    require $wrapper;
}
require_once $root.'/app/Services/Releases/Verification/SpotCheckPlanner.php';
require_once $root.'/tests/Fixtures/SamplePlanningBenchmarkFixtures.php';
$fixtureBytes = file_get_contents($fixturePath);
$fixture = unserialize($fixtureBytes, ['allowed_classes' => false]);
if (! is_array($fixture)) {
    throw new RuntimeException('Frozen fixture missing.');
}
trait SampleProfileCounters
{
    public int $calls = 0;

    public int $successes = 0;

    public function __construct(private readonly string $arm, string $library)
    {
        parent::__construct($library);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<array{file_key:string,totalparts:int,totalparts_inferred:bool,number:int,messageid:string,bytes:int}>|null
     */
    public function select(array $manifest, int $maximumArticles, int $maximumHeaderBytes, ?int $maximumArticleBytes = null): ?array
    {
        $this->calls++;
        if ($this->arm === 'php-reference') {
            return null;
        }
        $result = parent::select($manifest, $maximumArticles, $maximumHeaderBytes, $maximumArticleBytes);
        if ($result !== null) {
            $this->successes++;
        }

        return $result;
    }
}
$trace = $arm === 'instrumented'
    ? new class($arm, $library) extends ProfiledNativeSpotCheckPlanner
    {
        use SampleProfileCounters;
    }
: new class($arm, $library) extends NativeSpotCheckPlanner
{
    use SampleProfileCounters;
};
$planner = new SpotCheckPlanner($trace);
$observations = [];
$serialized = null;
foreach (['cold', 'warmup', 'warm'] as $ordinal => $phase) {
    // Cold uses static scalar defaults: NO observer read/reset or FFI preload.
    if ($ordinal > 0 && $arm === 'instrumented') {
        ProfiledNativeSpotCheckPlanner::attributionPhase($ordinal, $ordinal);
    }
    $beforeCalls = $trace->calls;
    $beforeSuccesses = $trace->successes;
    $cpu = getrusage();
    $throttlingBefore = @file_get_contents('/sys/fs/cgroup/cpu.stat');
    $started = hrtime(true);
    $sample = $planner->select($fixture['manifest'], $fixture['maximum_articles'], $fixture['maximum_header_bytes'], $fixture['maximum_article_bytes']);
    $ended = hrtime(true);
    // Readout is AFTER the exact complete caller boundary, in every arm.
    $readoutStarted = hrtime(true);
    $observer = $arm === 'instrumented' ? ProfiledNativeSpotCheckPlanner::attributionRead() : null;
    $readoutEnded = hrtime(true);
    $encoded = serialize($sample);
    $correct = SamplePlanningBenchmarkFixtures::correct($fixture, $sample) && ($serialized === null || $encoded === $serialized);
    $serialized ??= $encoded;
    $observations[$phase] = ['elapsed_ns' => $ended - $started, 'started_ns' => $started, 'ended_ns' => $ended,
        'correct' => $correct, 'native_attempts' => $trace->calls - $beforeCalls,
        'native_successes' => $trace->successes - $beforeSuccesses, 'serialized_result' => $encoded,
        'observer' => $observer, 'observer_readout_ns' => $readoutEnded - $readoutStarted,
        'process_cpu_before' => $cpu, 'process_cpu_after' => getrusage(),
        'cgroup_cpu_stat_before' => $throttlingBefore, 'cgroup_cpu_stat_after' => @file_get_contents('/sys/fs/cgroup/cpu.stat')];
}
$included = [];
foreach (get_included_files() as $file) {
    $included[$file] = hash_file('sha256', $file);
}
$result = ['status' => 'observed_requires_external_validation', 'arm' => $arm,
    'observations' => $observations, 'fixture_sha256' => hash('sha256', $fixtureBytes),
    'library_sha256' => hash_file('sha256', $library), 'wrapper_sha256' => hash_file('sha256', $wrapper),
    'loaded_wrapper' => (new ReflectionClass($arm === 'instrumented' ? ProfiledNativeSpotCheckPlanner::class : NativeSpotCheckPlanner::class))->getFileName(), 'included_files' => $included,
    'runtime' => ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'sapi' => PHP_SAPI,
        'ffi' => ini_get('ffi.enable'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
        'memory_limit' => ini_get('memory_limit')]];
if (file_put_contents($output, json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL) === false) {
    throw new RuntimeException('Cannot retain observation.');
}
