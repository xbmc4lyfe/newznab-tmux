<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TmuxMode;
use App\Enums\TmuxPaneRole;
use App\Enums\TmuxPostProcessMode;
use App\Enums\TmuxSequentialMode;
use App\Providers\HorizonServiceProvider;
use App\Services\Monitoring\Collectors\FailedJobsCollector;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Wave 36.6: PHP 8.5.12 / Apple M5 macOS arm64, rustc 1.99.1 release/LTO.
 * Base: 4b99c948c58dfe866046f2e9af6d8db3b52c889c. The opt-in screen below
 * retains source/library hashes and raw paired latencies. All five candidates
 * fall below the 1.5x gate, so PHP remains the production default. This is a
 * local helper benchmark, not an application-throughput or deployment claim.
 */
final class RustTmuxEnumsHorizonFailedJobsParityTest extends TestCase
{
    private const array CLASSES = [TmuxPaneRole::class, TmuxPostProcessMode::class, TmuxSequentialMode::class, HorizonServiceProvider::class, FailedJobsCollector::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional', HorizonServiceProvider::FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release library with the Wave 36.6 export.');

        return $ffi;
    }

    public function test_normalization_contracts_and_fail_closed_access(): void
    {
        self::assertSame(['value' => 'monitor', 'is_valid' => true], TmuxPaneRole::normalizePaneRoleValue(TmuxPaneRole::Monitor));
        self::assertSame(['value' => null, 'is_valid' => false], TmuxPaneRole::normalizePaneRoleValue(' MONITOR '));
        self::assertSame(['value' => 3, 'is_valid' => true], TmuxPostProcessMode::normalizePostProcessModeValue('3'));
        self::assertSame(['value' => 0, 'is_valid' => false], TmuxPostProcessMode::normalizePostProcessModeValue(3.0));
        self::assertSame(['value' => 2, 'is_valid' => true], TmuxSequentialMode::normalizeSequentialModeValue(TmuxSequentialMode::Stripped));
        self::assertSame(['value' => 0, 'is_valid' => false], TmuxSequentialMode::normalizeSequentialModeValue('02'));
        self::assertSame(['authenticated' => false, 'is_admin' => true, 'is_local' => false, 'gate_allowed' => false, 'allowed' => false], HorizonServiceProvider::summarizeHorizonAccessDecision(null, true, 'production'));
        self::assertSame(['authenticated' => false, 'is_admin' => false, 'is_local' => true, 'gate_allowed' => false, 'allowed' => true], HorizonServiceProvider::summarizeHorizonAccessDecision(null, false, 'local'));
        self::assertSame(['total_failed' => 0, 'recent_failed_last_hour' => 3, 'recent_failed_last_day' => 8, 'has_failures' => false], FailedJobsCollector::summarizeFailedJobCounts(-1, 3, 8));
    }

    public function test_toggles_are_independent_and_enums_have_no_static_properties(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            foreach (self::CLASSES as $other) {
                if ($other !== $class) {
                    self::assertFalse($other::usesRustFfi());
                }
            }
            $class::setUseRustFfi(false);
            self::assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(null);
            self::assertFalse($class::usesRustFfi());
        }
        foreach ([TmuxPaneRole::class, TmuxPostProcessMode::class, TmuxSequentialMode::class] as $class) {
            self::assertSame([], (new \ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC));
        }
    }

    /** @return list<array{class-string, string, list<mixed>}> */
    private static function cases(): array
    {
        $cases = [];
        $random = new Randomizer(new Mt19937(3662026));
        $invalid = [null, true, false, [], new \stdClass, new class implements \Stringable
        {
            public function __toString(): string
            {
                throw new \LogicException('Never coerce objects.');
            }
        }, 1.0, NAN, INF, -INF, PHP_INT_MIN, PHP_INT_MAX, '', '00', ' 1 ', '+1', "1\0", "\xff", str_repeat('x', 4096)];
        foreach ([TmuxPaneRole::class => 'normalizePaneRoleValue', TmuxPostProcessMode::class => 'normalizePostProcessModeValue', TmuxSequentialMode::class => 'normalizeSequentialModeValue'] as $class => $method) {
            $values = $invalid;
            foreach ($class::cases() as $case) {
                $values[] = $case;
                $values[] = $case->value;
                $values[] = (string) $case->value;
            }
            $values[] = TmuxMode::Full;
            foreach ($values as $value) {
                $cases[] = [$class, $method, [$value]];
            }
        }
        for ($i = 0; $i < 300; $i++) {
            $cases[] = [TmuxPostProcessMode::class, 'normalizePostProcessModeValue', [$random->getInt(-20, 20)]];
            $cases[] = [TmuxSequentialMode::class, 'normalizeSequentialModeValue', [(string) $random->getInt(-20, 20)]];
            $cases[] = [HorizonServiceProvider::class, 'summarizeHorizonAccessDecision', [[null, 0, -1, 1, PHP_INT_MAX][$i % 5], $i % 2 === 0, [null, 'local', 'production', 'LOCAL', "local\0", str_repeat('x', 4096)][$i % 6]]];
            $cases[] = [FailedJobsCollector::class, 'summarizeFailedJobCounts', [$random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(PHP_INT_MIN, PHP_INT_MAX)]];
        }
        $cases[] = [FailedJobsCollector::class, 'summarizeFailedJobCounts', [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX]];
        $cases[] = [FailedJobsCollector::class, 'summarizeFailedJobCounts', [PHP_INT_MIN, 0, -1]];

        return $cases;
    }

    public function test_native_and_dispatch_parity(): void
    {
        self::ffi();
        foreach (self::cases() as [$class, $method, $args]) {
            $expected = $class::{$method.'Php'}(...$args);
            // Native returns null on error so parity cannot silently pass via fallback.
            self::assertSame($expected, $class::{$method.'Native'}(...$args), $class.'::'.$method);
            foreach ([false, true] as $enabled) {
                $class::setUseRustFfi($enabled);
                self::assertSame($expected, $class::$method(...$args));
            }
        }
    }

    /** @return array<int, array{string, int}> */
    private static function abiInputs(): array
    {
        return [1 => ["\2".pack('V', 7).'monitor', 9], 2 => ["\1".pack('q', 3), 9], 3 => ["\2".pack('V', 1).'2', 9], 4 => ["\1\1".pack('qV', 7, 5).'local', 5], 5 => [pack('q3', 10, 2, 4), 25]];
    }

    public function test_abi_sentinels_and_output_ownership(): void
    {
        $ffi = self::ffi();
        $output = $ffi->new('char[128]');
        $wraps = $ffi->cast('char *', -1);
        foreach (self::abiInputs() as $operation => [$input, $needed]) {
            FFI::memset($output, 90, 128);
            // @phpstan-ignore method.notFound
            $call = static fn ($ptr, int $len, $out, int $cap): int => $ffi->nntmux_w366_tmux_horizon_failed_jobs($operation, $ptr, $len, $out, $cap);
            foreach ([[null, strlen($input), $output, 128], [$input, -1, $output, 128], [$input, strlen($input), null, 128], [$input, strlen($input), $output, -1], [$wraps, strlen($input), $output, 128], [$input, strlen($input), $wraps, 128], [$input, 16_777_217, $output, 128], [$input, strlen($input), $output, 16_777_217], [$input, strlen($input) - 1, $output, 128], [$input.'x', strlen($input) + 1, $output, 128]] as $args) {
                self::assertSame(-1, $call(...$args));
                self::assertSame(str_repeat('Z', 128), FFI::string($output, 128));
            }
            self::assertSame(-$needed, $call($input, strlen($input), $output, 0));
            self::assertSame(-$needed, $call($input, strlen($input), $output, $needed - 1));
            self::assertSame(str_repeat('Z', 128), FFI::string($output, 128));
            FFI::memcpy($output, $input, strlen($input));
            self::assertSame(-1, $call($output, strlen($input), $output, 128));
            self::assertSame($input, FFI::string($output, strlen($input)));
            FFI::memset($output, 90, 128);
            self::assertSame($needed, $call($input, strlen($input), $output, $needed));
            self::assertSame(str_repeat('Z', 128 - $needed), FFI::string(FFI::addr($output[$needed]), 128 - $needed));
        }
        foreach ([0, 6, 255] as $operation) {
            FFI::memset($output, 90, 128);
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w366_tmux_horizon_failed_jobs($operation, '', 0, $output, 128));
            self::assertSame(str_repeat('Z', 128), FFI::string($output, 128));
        }
        foreach ([1 => ["\3", "\2".pack('V', 0xFFFFFFFF)], 4 => ["\2\0".pack('qV', 0, 0), "\0\2".pack('qV', 0, 0)]] as $operation => $frames) {
            foreach ($frames as $input) {
                FFI::memset($output, 90, 128);
                // @phpstan-ignore method.notFound
                self::assertSame(-1, $ffi->nntmux_w366_tmux_horizon_failed_jobs($operation, $input, strlen($input), $output, 128));
                self::assertSame(str_repeat('Z', 128), FFI::string($output, 128));
            }
        }
    }

    public function test_missing_library_and_symbol_fall_back(): void
    {
        self::assertNull(RustPipelineLibrary::resolveDomain('additional', HorizonServiceProvider::FFI_CDEF, '/missing/w366/libnntmux_pipeline.dylib'));
        foreach ([false, FFI::cdef('int abs(int);')] as $unavailable) {
            $handles = array_fill_keys(self::CLASSES, $unavailable);
            (new ReflectionProperty(HorizonServiceProvider::class, 'rustFfiHandles'))->setValue(null, $handles);
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(true);
            }
            foreach (self::cases() as [$class, $method, $args]) {
                self::assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args));
            }
        }
    }

    public function test_oversized_inputs_fall_back_without_coercion(): void
    {
        $value = str_repeat('x', 16_777_217);
        foreach ([[TmuxPaneRole::class, 'normalizePaneRoleValue', [$value]], [HorizonServiceProvider::class, 'summarizeHorizonAccessDecision', [1, true, $value]]] as [$class, $method, $args]) {
            $class::setUseRustFfi(true);
            self::assertNull($class::{$method.'Native'}(...$args));
            self::assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args));
        }
    }

    /**
     * Reproduce: NNTMUX_W366_BENCH=1 NNTMUX_W366_BENCH_OUTPUT=/tmp/w36-6-benchmark.json
     * php artisan test --compact tests/Unit/RustTmuxEnumsHorizonFailedJobsParityTest.php
     * --filter=test_paired_randomized_order_benchmarks
     *
     * Frozen protocol: seed 3662026, 256 warmup pairs, 3 repetitions of 4096
     * single-call pairs per workload, random PHP/Rust order, nearest-rank p99.
     * Timing includes the public dispatcher, packing, FFI, allocation and decoding.
     * Raw samples, source/library hashes and environment are retained in the output.
     * Require >=1.5x mean and p99 in both regimes before changing a production default.
     */
    public function test_paired_randomized_order_benchmarks(): void
    {
        if (getenv('NNTMUX_W366_BENCH') !== '1') {
            $this->markTestSkipped('Opt-in paired benchmark; set NNTMUX_W366_BENCH=1.');
        }
        self::ffi();
        $random = new Randomizer(new Mt19937(3662026));
        $workloads = [
            [TmuxPaneRole::class, 'normalizePaneRoleValue', ['post_additional'], [str_repeat("x\0", 2048)]],
            [TmuxPostProcessMode::class, 'normalizePostProcessModeValue', [3], [str_repeat('0', 4096)]],
            [TmuxSequentialMode::class, 'normalizeSequentialModeValue', ['1'], [PHP_INT_MAX]],
            [HorizonServiceProvider::class, 'summarizeHorizonAccessDecision', [7, true, 'production'], [null, false, str_repeat("local\0", 1024)]],
            [FailedJobsCollector::class, 'summarizeFailedJobCounts', [100, 2, 10], [PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX]],
        ];
        $root = dirname(__DIR__, 2);
        $sourceHashes = [];
        foreach (self::CLASSES as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $sourceHashes[substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
        }
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'tests/Unit/RustTmuxEnumsHorizonFailedJobsParityTest.php'] as $file) {
            $sourceHashes[$file] = hash_file('sha256', $root.'/'.$file);
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $report = [
            'protocol' => ['seed' => 3662026, 'warmupPairs' => 256, 'pairsPerRepetition' => 4096, 'repetitions' => 3, 'p99' => 'nearest-rank'],
            'environment' => ['php' => PHP_VERSION, 'os' => php_uname(), 'rustc' => trim((string) shell_exec('rustc -V')), 'base' => trim((string) shell_exec('git rev-parse HEAD')), 'profile' => 'release, opt-level=3, LTO, codegen-units=1', 'opcache' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'librarySha256' => hash_file('sha256', $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix)],
            'sourceSha256' => $sourceHashes,
            'results' => [],
        ];
        foreach ($workloads as [$class, $method, $common, $uncommon]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $regime => $args) {
                $expected = $class::{$method.'Php'}(...$args);
                // A missing/declined Rust implementation cannot qualify via fallback.
                self::assertSame($expected, $class::{$method.'Native'}(...$args));
                for ($warmup = 0; $warmup < 256; $warmup++) {
                    foreach ([false, true] as $enabled) {
                        $class::setUseRustFfi($enabled);
                        self::assertSame($expected, $class::$method(...$args));
                    }
                }
                for ($rep = 0; $rep < 3; $rep++) {
                    $samples = ['php' => [], 'rust' => []];
                    for ($pair = 0; $pair < 4096; $pair++) {
                        $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                        foreach ($order as $enabled) {
                            $class::setUseRustFfi($enabled);
                            $start = hrtime(true);
                            $actual = $class::$method(...$args);
                            $elapsed = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                            $samples[$enabled ? 'rust' : 'php'][] = $elapsed;
                        }
                    }
                    $stats = [];
                    foreach ($samples as $path => $values) {
                        sort($values);
                        $stats[$path] = ['meanNs' => array_sum($values) / count($values), 'p99Ns' => $values[(int) ceil(count($values) * 0.99) - 1]];
                    }
                    $speedup = ['mean' => $stats['php']['meanNs'] / max(1, $stats['rust']['meanNs']), 'p99' => $stats['php']['p99Ns'] / max(1, $stats['rust']['p99Ns'])];
                    $report['results'][] = ['method' => $method, 'regime' => $regime, 'repetition' => $rep, 'stats' => $stats, 'speedup' => $speedup, 'rawNs' => $samples];
                    fwrite(STDERR, json_encode(['method' => $method, 'regime' => $regime, 'rep' => $rep, 'speedup' => $speedup], JSON_THROW_ON_ERROR)."\n");
                }
            }
        }
        $output = getenv('NNTMUX_W366_BENCH_OUTPUT');
        if (is_string($output) && $output !== '') {
            self::assertNotFalse(file_put_contents($output, json_encode($report, JSON_THROW_ON_ERROR)));
        }
    }
}
