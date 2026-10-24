<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\DeleteReleases;
use App\Console\Commands\ReleasesOptimizePreflight;
use App\Services\Mcp\VerificationPlanner;
use App\Services\Mcp\VerificationRunner;
use App\Services\RegistrationFailureLogService;
use App\Services\TempWorkspaceService;
use App\Support\RustPipelineLibrary;
use App\Support\UpdatePerformanceHelper;
use Closure;
use Illuminate\Support\Facades\File;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Darwin arm64 / PHP 8.5.12 / release Rust, 2026-10-24: 20,000 randomized
 * single-call pairs per workload after 1,000 warmup pairs. Ratios are
 * PHP latency / Rust latency, mean/p99 (including FFI and allocation).
 * Candidate                common       uncommon
 * plan                     0.94/0.92    0.92/0.90
 * parseCriteria            0.19/0.17    0.14/0.10
 * cleanSpaces              0.25/0.22    2.96/2.48
 * formatBytes              0.22/0.15    0.27/0.10
 * indexDiff                0.12/0.10    0.07/0.10
 * indexIntersect           0.17/0.12    0.44/0.46
 * relatedTestStems         0.30/0.24    0.13/0.15
 * isFrontendPath           0.15/0.21    0.14/0.17
 * containsMcpPath          0.18/0.26    0.17/0.20
 * vendorBinary             0.16/0.26    0.16/0.21
 * validFilter              0.18/0.25    0.37/0.21
 * targetedChecks           0.20/0.19    0.05/0.05
 * mainTempPath             0.39/0.37    1.03/1.08
 * parseMemoryInfo          0.28/0.31    0.05/0.04
 * parseMemoryLimit         0.22/0.22    0.21/0.27
 * None qualifies for default adoption: every common workload is below 1.5x.
 * Raw samples and source/runtime hashes: /tmp/wave13-maintenance-verification-
 * 20261024.{raw,summary}.json. Reproduce with MAINTENANCE_BENCHMARK=1.
 * These fixtures screen pure helpers and a small disposable planner tree;
 * they do not establish end-to-end production maintenance throughput.
 */
final class RustMaintenanceVerificationParityTest extends TestCase
{
    private const array CLASSES = [DeleteReleases::class, ReleasesOptimizePreflight::class, VerificationPlanner::class, VerificationRunner::class, TempWorkspaceService::class, UpdatePerformanceHelper::class];

    private Randomizer $random;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
            self::assertNotNull(RustPipelineLibrary::resolveDomain('cleaning', $class::FFI_CDEF), $class.' native exports must be built; fallback-only runs do not prove parity.');
        }
        $this->random = new Randomizer(new Mt19937(20261024));
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'setUseRustFfi')) {
                $class::setUseRustFfi(false);
            }
        }
        parent::tearDown();
    }

    public function test_default_switches_and_disabled_candidates(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse((new ReflectionClass($class))->getDefaultProperties()['useRustFfi'], $class);
            $class::setUseRustFfi(false);
            self::assertFalse($class::usesRustFfi());
        }
        foreach ($this->workloads()['common'] as $name => [$php, $native]) {
            self::assertNull($native(), $name);
        }
        self::assertNull((new VerificationPlanner)->planNative([]));
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi());
        }
    }

    public function test_criteria_whitespace_and_filter_boundaries(): void
    {
        $strings = ['', '0', 'size=larger=100', 'searchname=like=a:b', 'size:larger:', ':equals:1', 'invalid', 'x:y:z:tail',
            "  a\t\r\n b \0", "é 😀\xff\0", "size:larger:1\n", "TestName\n", "TestName\n\n", 'Class\\Name::test_name',
            str_repeat('x', 70000), "\xa0a\xa0"];
        for ($i = 0; $i < 500; $i++) {
            $strings[] = $this->text(32);
        }
        foreach ($strings as $i => $text) {
            self::assertSame(DeleteReleases::parseCriteriaPhp($text), DeleteReleases::parseCriteriaNative($text), "criteria $i");
            if (preg_match('/[^\x00-\x7f]/', $text) === 1) {
                self::assertNull(DeleteReleases::cleanSpacesNative($text));
                self::assertSame(DeleteReleases::cleanSpacesPhp($text), (new ReflectionMethod(DeleteReleases::class, 'cleanSpaces'))->invoke(new DeleteReleases, $text));
            } else {
                self::assertSame(DeleteReleases::cleanSpacesPhp($text), DeleteReleases::cleanSpacesNative($text), "spaces $i");
            }
            self::assertSame(VerificationRunner::validFilterPhp($text), VerificationRunner::validFilterNative($text), "filter $i");
        }
        self::assertTrue(VerificationRunner::validFilterNative("TestName\n"));
        self::assertFalse(VerificationRunner::validFilterNative("TestName\n\n"));
        self::assertFalse(DeleteReleases::parseCriteriaNative('invalid'));
    }

    public function test_criteria_runtime_dispatch_preserves_query_fields_and_modifiers(): void
    {
        $command = new DeleteReleases;
        $query = new ReflectionMethod($command, 'buildQueryFromCriteria');
        $criteria = ['ignore', 'size=bigger=1024', 'totalpart=equals=3', 'completion=smaller=90', 'imdbid=equals=NULL', 'size=unknown=1', 'bad=criterion'];
        foreach ([false, true] as $enabled) {
            DeleteReleases::setUseRustFfi($enabled);
            self::assertSame('SELECT id, guid, searchname, name FROM releases WHERE 1=1 AND size > 1024 AND totalpart = 3 AND completion < 90 AND imdbid IS NULL', $query->invoke($command, $criteria));
        }
        DeleteReleases::setUseRustFfi(true);
    }

    public function test_locale_character_tables_restore_startup_semantics(): void
    {
        $startupC = preg_match('/^\s$/', "\xa0") === 0;
        $previous = setlocale(LC_CTYPE, '0');
        $auditToggle = RegistrationFailureLogService::usesRustFfi();
        RegistrationFailureLogService::setUseRustFfi(true);
        try {
            foreach (['C', 'en_US.ISO8859-1', 'en_US.UTF-8', 'C.UTF-8'] as $locale) {
                if (setlocale(LC_CTYPE, $locale) === false) {
                    continue;
                }
                if (preg_match('/^\s$/', "\xa0") === 1) {
                    $line = "[t]\xa0local.ERROR:\xa0message";
                    $expected = ['t', 'ERROR', 'message', []];
                    self::assertSame($expected, RegistrationFailureLogService::matchLine($line));
                    self::assertSame($expected, RegistrationFailureLogService::matchLineNative($line) ?? RegistrationFailureLogService::matchLine($line));
                    self::assertNull(RegistrationFailureLogService::matchLineNative($line));
                }
                foreach (["\xa0a\xa0", "\xff\t a\n", "size:larger:1\n"] as $text) {
                    if (preg_match('/[^\x00-\x7f]/', $text) === 1) {
                        self::assertNull(DeleteReleases::cleanSpacesNative($text));
                    } else {
                        self::assertSame(DeleteReleases::cleanSpacesPhp($text), DeleteReleases::cleanSpacesNative($text));
                    }
                    self::assertSame(DeleteReleases::cleanSpacesPhp($text), (new ReflectionMethod(DeleteReleases::class, 'cleanSpaces'))->invoke(new DeleteReleases, $text));
                    self::assertSame(DeleteReleases::parseCriteriaPhp($text), DeleteReleases::parseCriteriaNative($text));
                }
                setlocale(LC_CTYPE, 'C');
            }
        } finally {
            RegistrationFailureLogService::setUseRustFfi($auditToggle);
            if ($previous !== false) {
                setlocale(LC_CTYPE, $previous);
            }
            if ($startupC) {
                setlocale(LC_CTYPE, 'C');
            }
        }
    }

    public function test_index_sets_and_byte_formatting(): void
    {
        foreach ([PHP_INT_MIN, -1024, -1, 0, 1, 1023, 1024, 1025, 1048575, 1048576, PHP_INT_MAX] as $bytes) {
            self::assertSame(ReleasesOptimizePreflight::formatBytesPhp($bytes), ReleasesOptimizePreflight::formatBytesNative($bytes));
        }
        $cases = [[[], []], [['x', 'x', 'a'], ['x']], [[8 => 'a', 'key' => 'b', 'z' => 'a'], ['b']], [['same-key' => 'a'], ['same-key' => 'b']], [["\0", 'é', "\xff", "a\n"], ["\0", "\xff"]]];
        for ($i = 0; $i < 300; $i++) {
            $cases[] = [[$this->text(20), 'same', 'same'], ['same', $this->text(20)]];
            $bytes = $this->random->getInt(0, PHP_INT_MAX);
            self::assertSame(ReleasesOptimizePreflight::formatBytesPhp($bytes), ReleasesOptimizePreflight::formatBytesNative($bytes));
        }
        foreach ($cases as [$left, $right]) {
            foreach ([false, true] as $intersect) {
                self::assertSame(ReleasesOptimizePreflight::indexDiffPhp($left, $right, $intersect), ReleasesOptimizePreflight::indexDiffNative($left, $right, $intersect));
            }
        }
    }

    public function test_path_and_check_candidates(): void
    {
        $paths = ['', '/', '\\', '0', 'FooTest.php', 'Foo.php', 'FooTest.php\n', 'resources/views/a.blade.php', 'resources/js/a.js',
            'resources/css/a.css', 'package.json', 'vite.config.js', 'app/Mcp/Foo.php', 'app/Services/Mcp/Foo.php', 'routes/ai.php',
            'config/nntmux-mcp.php', "app/Mcp/é\0\xff.php", "tests/Unit/FooTest.php\n", str_repeat('a', 70000)];
        for ($i = 0; $i < 300; $i++) {
            $paths[] = $this->text(60);
        }
        foreach ($paths as $path) {
            self::assertSame(VerificationPlanner::isFrontendPathPhp($path), VerificationPlanner::isFrontendPathNative($path));
            if (preg_match('/[^\x00-\x7f]/', $path) === 1) {
                self::assertNull(VerificationPlanner::relatedTestStemsNative([$path]));
            } else {
                self::assertSame(VerificationPlanner::relatedTestStemsPhp([$path, $path, '0.php', 'Test.php']), VerificationPlanner::relatedTestStemsNative([$path, $path, '0.php', 'Test.php']));
            }
            self::assertSame(VerificationPlanner::containsMcpPathPhp([$path]), VerificationPlanner::containsMcpPathNative([$path]));
            self::assertSame(VerificationRunner::vendorBinaryPhp($path, "phpunit\0é"), VerificationRunner::vendorBinaryNative($path, "phpunit\0é"));
            self::assertSame(TempWorkspaceService::mainTempPathPhp($path, 'a', '', "worker\0é"), TempWorkspaceService::mainTempPathNative($path, 'a', '', "worker\0é"));
        }
        foreach ([[], ['diff', 'pint', 'diff', 'phpunit'], ['0', '', "diff\0", 'é']] as $planned) {
            foreach ([[], ['pint', 'diff'], ['0', '', 'é']] as $allowed) {
                self::assertSame(VerificationRunner::targetedChecksPhp($planned, $allowed), VerificationRunner::targetedChecksNative($planned, $allowed));
            }
        }
        foreach ([['', '', '', ''], ['/tmp/', '', '7', ''], ['/tmp\\', 'a', '7', 'worker'], ["base\0", "g\xff", '', 't']] as $args) {
            self::assertSame(TempWorkspaceService::mainTempPathPhp(...$args), TempWorkspaceService::mainTempPathNative(...$args));
        }
    }

    public function test_real_planner_and_temp_workspace_callers(): void
    {
        $planner = new VerificationPlanner;
        foreach ([[], ['app/Services/Mcp/VerificationPlanner.php'], ['app/Services/é.php'], ['app/Services/TempWorkspaceService.php', 'resources/views/a.blade.php', 'composer.json'], ['tests/Unit/RustMaintenanceVerificationParityTest.php', 'tests/Unit/RustMaintenanceVerificationParityTest.php']] as $paths) {
            $expected = $planner->planPhp($paths);
            self::assertSame($expected, $planner->planNative($paths));
            foreach ([false, true] as $toggle) {
                VerificationPlanner::setUseRustFfi($toggle);
                self::assertSame($expected, $planner->plan($paths));
            }
        }
        $root = sys_get_temp_dir().'/maintenance-parity-'.bin2hex(random_bytes(12));
        try {
            $service = new TempWorkspaceService;
            foreach ([false, true] as $toggle) {
                TempWorkspaceService::setUseRustFfi($toggle);
                $expected = TempWorkspaceService::mainTempPathPhp($root, 'a', '7', 'worker');
                self::assertSame($expected, $service->ensureMainTempPath($root, 'a', '7', 'worker'));
                self::assertDirectoryExists($expected);
                self::assertTrue(is_writable($expected));
            }
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_oversized_inputs_explicitly_decline_and_wrappers_fall_back(): void
    {
        $large = str_repeat('x', 1_048_577);
        self::assertNull(DeleteReleases::parseCriteriaNative($large));
        self::assertSame(DeleteReleases::parseCriteriaPhp($large), DeleteReleases::parseCriteria($large));
        self::assertNull(DeleteReleases::cleanSpacesNative($large));
        self::assertSame(DeleteReleases::cleanSpacesPhp($large), (new ReflectionMethod(DeleteReleases::class, 'cleanSpaces'))->invoke(new DeleteReleases, $large));
        self::assertNull(VerificationPlanner::relatedTestStemsNative([$large]));
        self::assertNull(VerificationRunner::vendorBinaryNative($large, 'phpunit'));
        self::assertNull(TempWorkspaceService::mainTempPathNative($large));
        self::assertNull(UpdatePerformanceHelper::parseMemoryInfoNative($large));
        self::assertSame(UpdatePerformanceHelper::parseMemoryInfoPhp($large), UpdatePerformanceHelper::parseMemoryInfo($large));
    }

    public function test_memory_parsers_and_fallback_boundaries(): void
    {
        $cases = ['', 'invalid', "MemTotal: 100 kB\nMemAvailable: 40 kB\n", "MemTotal:\t0001\nMemAvailable: 2", "MemTotal: 0\nMemAvailable: 0", "MemTotal: 1\nMemAvailable: 0", "MemTotal: -1\nMemAvailable: 1", "\0MemTotal: 100\nMemAvailable: 2\xff", "MemTotal: 999999999999999999999999999\nMemAvailable: 1", str_repeat('x', 70000)];
        for ($i = 0; $i < 300; $i++) {
            $cases[] = 'MemTotal: '.$this->random->getInt(1, 100000000).' kB'."\nMemAvailable: ".$this->random->getInt(0, 100000000).' kB';
        }
        foreach ($cases as $text) {
            $expected = $this->outcome(fn () => UpdatePerformanceHelper::parseMemoryInfoPhp($text));
            $native = UpdatePerformanceHelper::parseMemoryInfoNative($text);
            self::assertSame($expected, $this->outcome(fn () => UpdatePerformanceHelper::parseMemoryInfo($text)), bin2hex(substr($text, 0, 100)));
            if (strlen($text) < 100 && ! str_contains($text, '999999999999999999999999999') && ! str_contains($text, 'MemTotal: 0') && preg_match('/[^\x00-\x7f]/', $text) !== 1) {
                self::assertSame($expected, $native);
            }
        }
        self::assertFalse(UpdatePerformanceHelper::parseMemoryInfoNative('invalid'));
        self::assertNull(UpdatePerformanceHelper::parseMemoryInfoNative("MemTotal: 0\nMemAvailable: 0"));
        self::assertNull(UpdatePerformanceHelper::parseMemoryInfoNative("MemTotal: 999999999999999999999999999\nMemAvailable: 1"));
        foreach (['-1', '128M', '2g', '1024K', '0', ' 512m ', '1.5G', 'garbage', '+2M', '1e2K', "12\0M", '999999999999999999999999999G'] as $limit) {
            $expected = $this->outcome(fn () => UpdatePerformanceHelper::parseMemoryLimitPhp($limit));
            $native = $this->outcome(fn () => UpdatePerformanceHelper::parseMemoryLimitNative($limit));
            self::assertSame($expected, $this->outcome(fn () => UpdatePerformanceHelper::parseMemoryLimit($limit)), $limit);
            if ($native !== null) {
                self::assertSame($expected, $native, $limit);
            }
        }
    }

    public function test_paired_single_call_benchmark(): void
    {
        if (getenv('MAINTENANCE_BENCHMARK') !== '1') {
            self::markTestSkipped('Set MAINTENANCE_BENCHMARK=1 to preserve paired benchmark artifacts.');
        }
        $prefix = getenv('MAINTENANCE_BENCHMARK_OUTPUT') ?: sys_get_temp_dir().'/maintenance-benchmark-'.getmypid();
        $pairs = max(20000, (int) (getenv('MAINTENANCE_BENCHMARK_PAIRS') ?: 20000));
        $raw = [];
        $summary = [];
        $originalBase = $this->app->basePath();
        $fixture = sys_get_temp_dir().'/maintenance-benchmark-fixture-'.bin2hex(random_bytes(12));
        File::makeDirectory($fixture.'/tests/Unit', 0777, true);
        File::put($fixture.'/tests/Unit/FooTest.php', '<?php');
        try {
            $this->app->setBasePath($fixture);
            foreach ($this->workloads() as $workload => $methods) {
                foreach ($methods as $name => [$php, $native]) {
                    self::assertSame($php(), $native(), "$name $workload correctness before speed");
                    for ($i = 0; $i < 1000; $i++) {
                        $php();
                        $native();
                    }
                    $phpTimes = [];
                    $rustTimes = [];
                    $orders = [];
                    for ($i = 0; $i < $pairs; $i++) {
                        $rustFirst = $this->random->getInt(0, 1) === 1;
                        $orders[] = (int) $rustFirst;
                        if ($rustFirst) {
                            $rustTimes[] = $this->time($native);
                            $phpTimes[] = $this->time($php);
                        } else {
                            $phpTimes[] = $this->time($php);
                            $rustTimes[] = $this->time($native);
                        }
                    }
                    $raw[$name][$workload] = ['php_ns' => $phpTimes, 'rust_ns' => $rustTimes, 'rust_first' => $orders];
                    sort($phpTimes);
                    sort($rustTimes);
                    $phpMean = array_sum($phpTimes) / $pairs;
                    $rustMean = array_sum($rustTimes) / $pairs;
                    $rank = (int) ceil($pairs * .99) - 1;
                    $summary[$name][$workload] = ['pairs' => $pairs, 'php_mean_ns' => $phpMean, 'rust_mean_ns' => $rustMean, 'php_p99_ns' => $phpTimes[$rank], 'rust_p99_ns' => $rustTimes[$rank], 'mean_speedup' => $phpMean / max(1, $rustMean), 'p99_speedup' => $phpTimes[$rank] / max(1, $rustTimes[$rank])];
                }
            }
        } catch (\Throwable $failure) {
            file_put_contents($prefix.'.failed.raw.json', json_encode(['seed' => 20261024, 'warmup_pairs' => 1000, 'pairs_per_workload' => $pairs, 'failure' => [$failure::class, $failure->getMessage()], 'completed_timings' => $raw], JSON_THROW_ON_ERROR));
            throw $failure;
        } finally {
            $this->app->setBasePath($originalBase);
            File::deleteDirectory($fixture);
        }
        $sources = [__FILE__, base_path('rust/pipeline-ffi/src/cleaning.rs'), base_path('rust/pipeline-ffi/Cargo.toml'), base_path('rust/pipeline-ffi/Cargo.lock'), base_path('composer.lock'), base_path('vendor/composer/installed.php')];
        foreach (self::CLASSES as $class) {
            $sources[] = (string) (new ReflectionClass($class))->getFileName();
        }
        $libraries = [...(glob(base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.*')) ?: []), ...(glob(base_path('storage/app/nntmux-native/libnntmux_pipeline.*')) ?: [])];
        $hashes = [];
        foreach ([...$sources, ...$libraries] as $path) {
            if (is_file($path)) {
                $hashes[$path] = hash_file('sha256', $path);
            }
        }
        $metadata = ['timestamp' => date(DATE_ATOM), 'seed' => 20261024, 'warmup_pairs' => 1000, 'pairs_per_workload' => $pairs, 'protocol' => 'randomized paired single calls through preconstructed closures; nearest-rank p99; correctness before warmup', 'command' => 'MAINTENANCE_BENCHMARK=1 MAINTENANCE_BENCHMARK_OUTPUT='.$prefix.' php artisan test --compact tests/Unit/RustMaintenanceVerificationParityTest.php', 'php' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'os' => php_uname(), 'ffi_enable' => ini_get('ffi.enable'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'pcre_jit' => ini_get('pcre.jit'), 'memory_limit' => ini_get('memory_limit'), 'source_and_library_sha256' => $hashes, 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'rustc' => trim((string) shell_exec('rustc -Vv 2>&1')), 'cargo' => trim((string) shell_exec('cargo -V 2>&1')), 'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.memsize 2>&1' : 'lscpu 2>&1')), 'failures' => []];
        self::assertNotFalse(file_put_contents($prefix.'.raw.json', json_encode(['metadata' => $metadata, 'timings' => $raw], JSON_THROW_ON_ERROR)));
        self::assertNotFalse(file_put_contents($prefix.'.summary.json', json_encode(['metadata' => $metadata, 'results' => $summary], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)));
    }

    /** @return array<string, array<string, array{Closure, Closure}>> */
    private function workloads(): array
    {
        $result = [];
        foreach (['common', 'uncommon'] as $workload) {
            $large = $workload === 'uncommon';
            $path = $large ? str_repeat('long/a/', 250).'FooTest.php' : 'app/Services/Mcp/VerificationPlanner.php';
            $paths = $large ? array_fill(0, 100, $path) : [$path, 'resources/js/app.js'];
            $criterion = $large ? 'searchname=like='.str_repeat('title ', 800) : 'size=larger=100';
            $spaces = $large ? str_repeat(" a\t\r\n", 1000) : '  a  b  ';
            $left = $large ? array_map(static fn (int $i): string => 'idx_'.$i, range(0, 200)) : ['a', 'b', 'c'];
            $right = $large ? array_slice($left, 20, 100) : ['b'];
            $memory = ($large ? str_repeat("Other: 123 kB\n", 200) : '')."MemTotal: 16384000 kB\nMemAvailable: 4096000 kB\n";
            $inputs = [
                'parseCriteria' => [DeleteReleases::class, [$criterion]], 'cleanSpaces' => [DeleteReleases::class, [$spaces]],
                'formatBytes' => [ReleasesOptimizePreflight::class, [$large ? PHP_INT_MAX : 1048576]],
                'indexDiff' => [ReleasesOptimizePreflight::class, [$left, $right]], 'indexIntersect' => [ReleasesOptimizePreflight::class, [$left, $right, true]],
                'relatedTestStems' => [VerificationPlanner::class, [$paths]], 'isFrontendPath' => [VerificationPlanner::class, [$path]], 'containsMcpPath' => [VerificationPlanner::class, [$paths]],
                'vendorBinary' => [VerificationRunner::class, [$large ? str_repeat('a/', 500) : 'vendor/bin/', 'phpunit']],
                'validFilter' => [VerificationRunner::class, [$large ? str_repeat('Name\\', 500).'test' : 'Class::test_name']],
                'targetedChecks' => [VerificationRunner::class, [$large ? array_fill(0, 300, 'diff') : ['pint', 'diff', 'pint'], ['pint', 'diff']]],
                'mainTempPath' => [TempWorkspaceService::class, [$large ? str_repeat('/tmp/a', 200) : '/tmp/nntmux', 'a', '7', $large ? str_repeat('worker', 1000) : 'worker']],
                'parseMemoryInfo' => [UpdatePerformanceHelper::class, [$memory]], 'parseMemoryLimit' => [UpdatePerformanceHelper::class, [$large ? '8192G' : '512M']],
            ];
            $planner = new VerificationPlanner;
            $planPaths = $large ? ['app/Services/Mcp/Foo.php', 'resources/views/a.blade.php', 'composer.json', 'Foo.php'] : ['Foo.php'];
            $result[$workload]['plan'] = [static fn () => $planner->planPhp($planPaths), static fn () => $planner->planNative($planPaths)];
            foreach ($inputs as $name => [$class, $args]) {
                $method = $name === 'indexIntersect' ? 'indexDiff' : $name;
                $result[$workload][$name] = [static fn () => $class::{$method.'Php'}(...$args), static fn () => $class::{$method.'Native'}(...$args)];
            }
        }

        return $result;
    }

    private function text(int $max): string
    {
        $alphabet = ['a', 'Z', '0', ':', '/', '\\', '.', ' ', "\t", "\n", "\r", "\0", 'é', "\xff", 'Test.php'];
        $result = '';
        for ($i = 0, $n = $this->random->getInt(0, $max); $i < $n; $i++) {
            $result .= $alphabet[$this->random->getInt(0, count($alphabet) - 1)];
        }

        return $result;
    }

    private function time(Closure $call): int
    {
        $start = hrtime(true);
        $call();

        return hrtime(true) - $start;
    }

    private function outcome(Closure $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $error) {
            return [$error::class, $error->getMessage()];
        }
    }
}
