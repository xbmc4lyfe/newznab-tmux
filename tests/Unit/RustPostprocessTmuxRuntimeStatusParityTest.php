<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Enums\ServiceStatusEnum;
use App\Models\PostProcessingConfiguration;
use App\Models\ProcessingRuntimeState;
use App\Models\ServiceStatus;
use App\Models\TmuxConfiguration;
use App\Services\StatusProbes\ProbeResult;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Wave 34.6 candidates remain opt-in. Apple M5 / PHP 8.5.12 / rustc 1.99.1,
 * release profile, base d3fef574e: 3 x 1,000 randomized paired single calls
 * per workload (128 warmups). Initial screen's worst repetition mean/p99
 * speedups (PHP latency / Rust latency): limits common 0.17/0.12, uncommon
 * 0.17/0.17; modes common 0.25/0.15, uncommon 0.19/0.29; runtime common
 * 0.15/0.12, uncommon 0.15/0.08; transition common 0.14/0.09, uncommon
 * 0.17/0.17; probe common 0.15/0.09, uncommon 0.09/0.07. None meets 1.5x.
 * Raw samples and p99 rank intervals are emitted outside the worktree by the
 * opt-in benchmark below. These are pure helper timings, not production throughput.
 */
final class RustPostprocessTmuxRuntimeStatusParityTest extends TestCase
{
    private const array CLASSES = [PostProcessingConfiguration::class, TmuxConfiguration::class, ProcessingRuntimeState::class, ServiceStatus::class, ProbeResult::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'setUseRustFfi')) {
                $class::setUseRustFfi(null);
            }
        }
        parent::tearDown();
    }

    public function test_pure_helpers_normalize_validate_and_preserve_pauses(): void
    {
        foreach ([false, true] as $rust) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
            }
            self::assertSame(['threads' => 4, 'timeout_seconds' => 600, 'max_timeout_count' => 3], PostProcessingConfiguration::normalizeLimits(4, 600, 3));
            self::assertSame(['threads' => 1, 'timeout_seconds' => 0, 'max_timeout_count' => 0], PostProcessingConfiguration::normalizeLimits(-5, -1, PHP_INT_MIN));
            self::assertSame(['sequential_mode' => 0, 'backfill_mode' => 0, 'post_mode' => 0, 'post_amazon_mode' => 3, 'post_non_mode' => 2], TmuxConfiguration::normalizeModes(3, 2, -1, 3, 2));
            self::assertSame(['tmux_running' => true, 'stop_requested' => true, 'accepting_work' => false], ProcessingRuntimeState::transitionFlags(false, true, true));
            self::assertSame(['tmux_running' => true, 'stop_requested' => false, 'accepting_work' => true], ProcessingRuntimeState::transitionFlags(true, true, null, false));
            self::assertSame(['status' => ServiceStatusEnum::PartialOutage, 'changed' => true], ServiceStatus::probeTransition(ServiceStatusEnum::Operational, false, null));
            self::assertSame(['status' => ServiceStatusEnum::Degraded, 'changed' => false], ServiceStatus::probeTransition(ServiceStatusEnum::Degraded, false, IncidentImpactEnum::None));
            $probe = new ProbeResult(false, -1, IncidentImpactEnum::Critical, "reason\0\xff", ['binary' => "\xff"]);
            self::assertSame(['ok' => false, 'response_time_ms' => 0, 'impact' => 'critical', 'reason' => "reason\0\xff", 'metadata' => ['binary' => "\xff"]], $probe->summary());
            self::assertSame(-1, $probe->responseTimeMs);
        }
    }

    private static function ffi(): FFI
    {
        self::assertTrue(extension_loaded('FFI'), 'FFI extension is required for native parity');
        $ffi = RustPipelineLibrary::resolveDomain('w346', PostProcessingConfiguration::FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release library with Wave 34.6 symbols before testing native parity');

        return $ffi;
    }

    /** @param list<int> $values
     * @return list<int>
     */
    private static function native(int $operation, array $values, int $count): array
    {
        $ffi = self::ffi();
        $length = count($values);
        $input = $ffi->new("int64_t[{$length}]");
        $output = $ffi->new("int64_t[{$count}]");
        foreach ($values as $index => $value) {
            $input[$index] = $value;
        }
        // @phpstan-ignore method.notFound
        self::assertSame($count, $ffi->nntmux_w346_configuration_status($operation, $input, $length, $output, $count));
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = (int) $output[$i];
        }

        return $result;
    }

    public function test_real_native_exports_match_php_boundaries_and_all_modes(): void
    {
        self::ffi();
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 3, 4, PHP_INT_MAX] as $value) {
            self::assertSame([max(1, $value), max(0, $value), max(0, $value)], self::native(1, [$value, $value, $value], 3));
            self::assertSame(PostProcessingConfiguration::normalizeLimitsPhp($value, $value, $value), PostProcessingConfiguration::normalizeLimits($value, $value, $value));
            self::assertSame(TmuxConfiguration::normalizeModesPhp($value, $value, $value, $value, $value), TmuxConfiguration::normalizeModes($value, $value, $value, $value, $value));
            $expected = TmuxConfiguration::normalizeModesPhp($value, $value, $value, $value, $value);
            self::assertSame(array_values($expected), self::native(2, [$value, $value, $value, $value, $value], 5));
        }
        $random = new Randomizer(new Mt19937(346));
        for ($i = 0; $i < 100; $i++) {
            $values = [];
            for ($j = 0; $j < 5; $j++) {
                $values[] = $random->getInt(-5, 8);
            }
            self::assertSame(PostProcessingConfiguration::normalizeLimitsPhp(...array_slice($values, 0, 3)), PostProcessingConfiguration::normalizeLimits(...array_slice($values, 0, 3)));
            self::assertSame(array_values(PostProcessingConfiguration::normalizeLimitsPhp(...array_slice($values, 0, 3))), self::native(1, array_slice($values, 0, 3), 3));
            self::assertSame(TmuxConfiguration::normalizeModesPhp(...$values), TmuxConfiguration::normalizeModes(...$values));
            self::assertSame(array_values(TmuxConfiguration::normalizeModesPhp(...$values)), self::native(2, $values, 5));
        }
        foreach ([false, true] as $running) {
            foreach ([false, true] as $stop) {
                foreach ([null, false, true] as $nextRunning) {
                    foreach ([null, false, true] as $nextStop) {
                        $expected = ProcessingRuntimeState::transitionFlagsPhp($running, $stop, $nextRunning, $nextStop);
                        self::assertSame($expected, ProcessingRuntimeState::transitionFlags($running, $stop, $nextRunning, $nextStop));
                        self::assertSame(array_map(intval(...), array_values($expected)), self::native(3, [(int) $running, (int) $stop, $nextRunning === null ? -1 : (int) $nextRunning, $nextStop === null ? -1 : (int) $nextStop], 3));
                    }
                }
            }
        }
        $statuses = [ServiceStatusEnum::Operational, ServiceStatusEnum::Degraded, ServiceStatusEnum::PartialOutage, ServiceStatusEnum::MajorOutage, ServiceStatusEnum::Maintenance];
        foreach ($statuses as $currentCode => $current) {
            foreach ([null, ...IncidentImpactEnum::cases()] as $impactCode => $impact) {
                foreach ([false, true] as $ok) {
                    $expected = ServiceStatus::probeTransitionPhp($current, $ok, $impact);
                    self::assertSame($expected, ServiceStatus::probeTransition($current, $ok, $impact));
                    self::assertSame([array_search($expected['status'], $statuses, true), (int) $expected['changed']], self::native(4, [$currentCode, (int) $ok, $impactCode - 1], 2));
                    foreach ([PHP_INT_MIN, -1, 0, 150, PHP_INT_MAX] as $latency) {
                        $probe = new ProbeResult($ok, $latency, $impact, "raw\0\xff", ['nested' => [null, true, 1.25, "\xff"]]);
                        self::assertSame($probe->summaryPhp(), $probe->summary());
                        self::assertSame([(int) $ok, max(0, $latency), $impactCode - 1], self::native(5, [(int) $ok, $latency, $impactCode - 1], 3));
                    }
                }
            }
        }
        // Default/reset is separate from an explicit opt-in.
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            self::assertFalse($class::usesRustFfi());
        }
    }

    public function test_abi_rejects_malformed_inputs_and_never_partially_writes(): void
    {
        $ffi = self::ffi();
        $input = $ffi->new('int64_t[5]');
        $output = $ffi->new('int64_t[5]');
        $call = static function (int $op, mixed $source, int $length, mixed $out, int $capacity) use ($ffi): int {
            // @phpstan-ignore method.notFound
            return $ffi->nntmux_w346_configuration_status($op, $source, $length, $out, $capacity);
        };
        $fixtures = [1 => [[2, 10, 3], 3], 2 => [[2, 4, 1, 2, 3], 5], 3 => [[1, 1, -1, -1], 3], 4 => [[0, 0, -1], 2], 5 => [[1, -10, -1], 3]];
        foreach ($fixtures as $op => [$values, $size]) {
            foreach ($values as $i => $value) {
                $input[$i] = $value;
            }
            for ($i = 0; $i < 5; $i++) {
                $output[$i] = 90;
            }
            self::assertSame(-$size, $call($op, $input, count($values), $output, $size - 1));
            self::assertSame(-$size, $call($op, $input, count($values), $output, 0));
            foreach ([0, count($values) - 1, count($values) + 1, PHP_INT_MAX, -1] as $length) {
                self::assertSame(-1, $call($op, $input, $length, $output, 5));
            }
            self::assertSame(-1, $call($op, null, count($values), $output, 5));
            self::assertSame(-1, $call($op, $input, count($values), null, 5));
            self::assertSame(-1, $call($op, $input, count($values), $output, PHP_INT_MAX));
            self::assertSame(-1, $call($op, $input, count($values), $output, -1));
            self::assertSame(-1, $call($op, $input, count($values), $input, 5));
            self::assertSame($values, array_map(static fn (int $i): int => (int) $input[$i], range(0, count($values) - 1)));
            for ($i = 0; $i < 5; $i++) {
                self::assertSame(90, (int) $output[$i]);
            }
        }
        foreach ([0, 6, 255] as $op) {
            self::assertSame(-1, $call($op, $input, 3, $output, 5));
        }
        foreach ([[3, [2, 0, -1, -1]], [3, [1, -1, -1, -1]], [3, [1, 0, -2, -1]], [3, [1, 0, -1, 2]], [4, [5, 1, 0]], [4, [0, 2, 0]], [4, [0, 0, 4]], [5, [-1, 0, 0]], [5, [1, 0, -2]]] as [$op, $values]) {
            foreach ($values as $i => $value) {
                $input[$i] = $value;
            }
            self::assertSame(-1, $call($op, $input, count($values), $output, 5));
        }
        $bytes = $ffi->new('char[64]');
        $unaligned = $ffi->cast('int64_t *', FFI::addr($bytes[1]));
        self::assertSame(-1, $call(1, $unaligned, 3, $output, 5));
        self::assertSame(-1, $call(1, $input, 3, $unaligned, 5));
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(90, (int) $output[$i]);
        }
    }

    public function test_missing_library_and_missing_symbol_fall_back_for_every_class(): void
    {
        $property = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $property->getValue();
        $missing = RustPipelineLibrary::resolveDomain('w346', PostProcessingConfiguration::FFI_CDEF, '/nonexistent/w346-library');
        self::assertNull($missing);
        $key = 'w346|default|'.hash('xxh3', PostProcessingConfiguration::FFI_CDEF);
        try {
            // Exercise the production loader's cached missing-library result and a stale handle.
            foreach ([false, FFI::cdef('int abs(int value);')] as $handle) {
                $property->setValue(null, [$key => $handle]);
                $this->test_pure_helpers_normalize_validate_and_preserve_pauses();
            }
        } finally {
            $property->setValue(null, $saved);
        }
    }

    /**
     * Pure helper experiment only. 3 repetitions of 1,000 same-input pairs per
     * workload, seed 346, 128 warmup pairs, random order within every pair.
     * Includes PHP dispatch, marshalling, allocation and FFI; no production I/O.
     * Reproduce: NNTMUX_W346_BENCH=1 php artisan test --compact
     * tests/Unit/RustPostprocessTmuxRuntimeStatusParityTest.php --filter=benchmark
     */
    public function test_paired_randomized_order_benchmarks(): void
    {
        if (getenv('NNTMUX_W346_BENCH') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_W346_BENCH=1 for paired benchmark evidence.');
        }
        self::ffi();
        $random = new Randomizer(new Mt19937(346));
        $common = new ProbeResult(true, 25, null, 'Healthy', []);
        $uncommon = new ProbeResult(false, PHP_INT_MIN, IncidentImpactEnum::Critical, str_repeat("raw\0\xff", 10000), ['large' => range(0, 999)]);
        $fixtures = [
            [PostProcessingConfiguration::class, 'limits/common', fn () => PostProcessingConfiguration::normalizeLimits(4, 600, 3)],
            [PostProcessingConfiguration::class, 'limits/uncommon', fn () => PostProcessingConfiguration::normalizeLimits(PHP_INT_MIN, PHP_INT_MAX, -1)],
            [TmuxConfiguration::class, 'modes/common', fn () => TmuxConfiguration::normalizeModes(0, 4, 3, 1, 2)],
            [TmuxConfiguration::class, 'modes/uncommon', fn () => TmuxConfiguration::normalizeModes(PHP_INT_MIN, 2, PHP_INT_MAX, -1, 4)],
            [ProcessingRuntimeState::class, 'runtime/common', fn () => ProcessingRuntimeState::transitionFlags(true, false)],
            [ProcessingRuntimeState::class, 'runtime/uncommon', fn () => ProcessingRuntimeState::transitionFlags(false, true, true, null)],
            [ServiceStatus::class, 'transition/common', fn () => ServiceStatus::probeTransition(ServiceStatusEnum::Operational, true, null)],
            [ServiceStatus::class, 'transition/uncommon', fn () => ServiceStatus::probeTransition(ServiceStatusEnum::Maintenance, false, IncidentImpactEnum::Critical)],
            [ProbeResult::class, 'probe/common', fn () => $common->summary()],
            [ProbeResult::class, 'probe/uncommon', fn () => $uncommon->summary()],
        ];
        $root = dirname(__DIR__, 2);
        $sources = ['app/Models/PostProcessingConfiguration.php', 'app/Models/TmuxConfiguration.php', 'app/Models/ProcessingRuntimeState.php', 'app/Models/ServiceStatus.php', 'app/Services/StatusProbes/ProbeResult.php', 'rust/pipeline-ffi/src/additional.rs', 'tests/Unit/RustPostprocessTmuxRuntimeStatusParityTest.php'];
        $hashes = [];
        foreach ($sources as $source) {
            $hashes[$source] = hash_file('sha256', $root.'/'.$source);
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $library = $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix;
        $evidence = ['meta' => [
            'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'utc' => gmdate(DATE_ATOM), 'php' => PHP_VERSION, 'os' => php_uname(),
            'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')),
            'rustc' => trim((string) shell_exec('rustc --version')), 'profile' => 'release: opt-level=3 lto=true codegen-units=1',
            'build' => 'CARGO_TARGET_DIR=/tmp/cargo-w34-6 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml',
            'library_sha256' => hash_file('sha256', $library), 'source_sha256' => $hashes,
            'composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'),
            'cargo_lock_sha256' => hash_file('sha256', $root.'/rust/pipeline-ffi/Cargo.lock'),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'memory_limit' => ini_get('memory_limit'), 'resource_limits' => (string) shell_exec('ulimit -a'),
            'load_start' => sys_getloadavg(), 'executor' => 'single process, no imposed CPU quota; no production services',
            'seed' => 346, 'warmup_pairs' => 128, 'repetitions' => 3, 'pairs_per_repetition' => 1000,
            'protocol' => 'single-call caller-inclusive pairs; parity outside timing; random order; nearest-rank p99; approximate 95 percent binomial rank interval; defaults stay PHP unless every mean and p99 speedup >=1.5',
            'fixtures' => 'literal fixtures in test_paired_randomized_order_benchmarks, identified by source hash',
        ], 'workloads' => []];
        foreach ($fixtures as [$class, $name, $call]) {
            $class::setUseRustFfi(false);
            $expected = $call();
            $class::setUseRustFfi(true);
            self::assertSame($expected, $call(), $name);
            for ($i = 0; $i < 128; $i++) {
                foreach ([false, true] as $rust) {
                    $class::setUseRustFfi($rust);
                    $call();
                }
            }
            $raw = [];
            $repetitions = [];
            for ($repeat = 0; $repeat < 3; $repeat++) {
                $php = [];
                $rust = [];
                for ($i = 0; $i < 1000; $i++) {
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $pair = ['order' => $order];
                    foreach ($order as $native) {
                        $class::setUseRustFfi($native);
                        $start = hrtime(true);
                        $result = $call();
                        $duration = hrtime(true) - $start;
                        self::assertSame($expected, $result, $name);
                        $pair[$native ? 'rust_ns' : 'php_ns'] = $duration;
                        if ($native) {
                            $rust[] = $duration;
                        } else {
                            $php[] = $duration;
                        }
                    }
                    $raw[] = $pair;
                }
                sort($php);
                sort($rust);
                $repetitions[] = [
                    'php_mean_ns' => array_sum($php) / 1000, 'rust_mean_ns' => array_sum($rust) / 1000,
                    'php_p99_ns' => $php[989], 'rust_p99_ns' => $rust[989],
                    'php_p99_rank_interval_ns' => [$php[983], $php[996]],
                    'rust_p99_rank_interval_ns' => [$rust[983], $rust[996]],
                    'mean_speedup' => array_sum($php) / array_sum($rust), 'p99_speedup' => $php[989] / $rust[989],
                ];
            }
            $evidence['workloads'][$name] = ['repetitions' => $repetitions, 'raw' => $raw];
        }
        $evidence['meta']['load_end'] = sys_getloadavg();
        $artifact = sys_get_temp_dir().'/wave34-6-benchmark-'.getmypid().'.json';
        self::assertNotFalse(file_put_contents($artifact, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
        fwrite(STDERR, "\nWave 34.6 paired benchmark evidence: {$artifact}\n");
    }
}
