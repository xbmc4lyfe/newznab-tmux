<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\RetryArchiveInspection;
use App\Console\Commands\TmuxDrain;
use App\Console\Commands\TmuxStop;
use App\Services\Runners\PostProcessRunner;
use App\Services\Runners\ReleasesRunner;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Reproduce: NNTMUX_W386_BENCH_OUTPUT=/tmp/w38-6-benchmark.json php vendor/bin/phpunit
 * tests/Unit/RustTmuxScriptsMonitorGroupsPreSleepParityTest.php
 * Local pure-helper evidence only; no process, database or deployment claims.
 */
final class RustTmuxScriptsMonitorGroupsPreSleepParityTest extends TestCase
{
    private const array HELPERS = [
        1 => [TmuxDrain::class, 'summarizeDrainToken'],
        2 => [TmuxStop::class, 'stopInterruptPlan'],
        3 => [RetryArchiveInspection::class, 'retryChunkPlan'],
        4 => [PostProcessRunner::class, 'guidBucketPlan'],
        5 => [ReleasesRunner::class, 'readyGroupPlan'],
    ];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class::setUseRustW386(null);
        }
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional_w386', TmuxDrain::W386_FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release library containing the W386 export.');

        return $ffi;
    }

    private static function stringFrame(string $value): string
    {
        return pack('V', strlen($value)).$value;
    }

    /** @param list<mixed> $args */
    private static function input(int $op, array $args): string
    {
        if ($op === 1) {
            return self::stringFrame($args[0]);
        }
        if ($op === 3) {
            return pack('q2', ...$args);
        }
        $frame = pack('V', count($args[0]));
        foreach ($args[0] as $value) {
            $frame .= $op === 5 ? pack('q', $value) : self::stringFrame($value);
        }

        return $frame;
    }

    private static function outputFrame(int $op, mixed $result): string
    {
        return match ($op) {
            1 => chr((int) $result['present']).chr((int) $result['valid']),
            2 => pack('V', count($result)).implode('', array_map(static fn (bool $v): string => chr((int) $v), $result)),
            3 => pack('q', $result),
            4 => pack('V', count($result)).implode('', array_map(self::stringFrame(...), $result)),
            5 => pack('V', count($result)).implode('', array_map(static fn (int $v): string => pack('q', $v), $result)),
            default => throw new \InvalidArgumentException('Unknown W386 operation.'),
        };
    }

    /** @return array<int, list<list<mixed>>> */
    private static function cases(): array
    {
        $cases = [
            1 => array_map(static fn (string $s): array => [$s], ['', 'a', 'AZ09_-', 'a b', "a\n", "a\0b", "\xff", '日本語', str_repeat('a', 128), str_repeat('a', 129)]),
            2 => [[[], false], [[], true], [['monitor', 'irc_scraper', 'releases', '', 'MONITOR', "monitor\0"], true]],
            3 => [[0, 0], [-1, -1], [10, 0], [10, 10], [10, 11], [PHP_INT_MAX, PHP_INT_MIN], [PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MAX]],
            4 => [[[]], [['', 'a', 'abc', '0', "\0abc", "\xffz", '日本語', 'abc']]],
            5 => [[[]], [[0, -1, 1, -1, PHP_INT_MIN, PHP_INT_MAX, 0]]],
        ];
        $random = new Randomizer(new Mt19937(3862026));
        $roles = ['monitor', 'irc_scraper', 'releases', 'post_additional', '', 'unknown', "monitor\0", "\xff"];
        for ($i = 0; $i < 1100; $i++) {
            $token = $random->getBytes($random->getInt(1, 180));
            $cases[1][] = [$i % 2 ? bin2hex(substr($token, 0, $random->getInt(1, 64))) : $token];
            $roleList = $ids = $buckets = [];
            for ($j = 0, $n = $random->getInt(0, 40); $j < $n; $j++) {
                $roleList[] = $roles[$random->getInt(0, count($roles) - 1)];
                $ids[] = $random->getInt(-15, 15);
                $buckets[] = $j % 5 === 0 ? '' : $random->getBytes($random->getInt(1, 120));
            }
            $cases[2][] = [$roleList, (bool) $random->getInt(0, 1)];
            $cases[3][] = [$random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(PHP_INT_MIN, PHP_INT_MAX)];
            $cases[4][] = [$buckets];
            $cases[5][] = [$ids];
        }

        return $cases;
    }

    public function test_literal_contracts_and_independent_defaults(): void
    {
        self::assertSame(['present' => false, 'valid' => false], TmuxDrain::summarizeDrainToken(''));
        self::assertSame(['present' => true, 'valid' => true], TmuxDrain::summarizeDrainToken('AZ_09-'));
        self::assertSame(['present' => true, 'valid' => false], TmuxDrain::summarizeDrainToken("a\n"));
        self::assertSame([true, true, false], TmuxStop::stopInterruptPlan(['monitor', 'irc_scraper', 'releases'], true));
        self::assertSame([true, true, true], TmuxStop::stopInterruptPlan(['', 'unknown', 'releases'], false));
        self::assertSame(1000, RetryArchiveInspection::retryChunkPlan(0, PHP_INT_MAX));
        self::assertSame(3, RetryArchiveInspection::retryChunkPlan(10, 7));
        self::assertSame(0, RetryArchiveInspection::retryChunkPlan(10, 11));
        self::assertSame(['', 'a', "\xe6", "\0"], PostProcessRunner::guidBucketPlan(['', 'abc', '日本語', "\0abc"]));
        self::assertSame([2, -1, 0], ReleasesRunner::readyGroupPlan([2, -1, 2, 0, -1]));
        foreach (self::HELPERS as [$class]) {
            self::assertFalse($class::usesRustW386());
            $class::setUseRustW386(true);
            self::assertTrue($class::usesRustW386());
            foreach (self::HELPERS as [$other]) {
                if ($other !== $class) {
                    self::assertFalse($other::usesRustW386());
                }
            }
            $class::setUseRustW386(null);
            self::assertFalse($class::usesRustW386());
        }
    }

    public function test_exact_parity_for_edges_and_1100_random_inputs_per_helper(): void
    {
        $ffi = self::ffi();
        $out = $ffi->new('char[65536]');
        foreach (self::cases() as $op => $cases) {
            [$class, $method] = self::HELPERS[$op];
            foreach ($cases as $args) {
                $expected = $class::{$method.'Php'}(...$args);
                self::assertSame($expected, $class::{$method.'Native'}(...$args));
                foreach ([false, true] as $enabled) {
                    $class::setUseRustW386($enabled);
                    self::assertSame($expected, $class::$method(...$args));
                }
                $input = self::input($op, $args);
                $flags = $op === 2 ? (int) $args[1] : 0;
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w386_tmux_drain_stop_runners($op, $flags, $input, strlen($input), $out, 65536);
                $bytes = self::outputFrame($op, $expected);
                self::assertSame(strlen($bytes), $written);
                self::assertSame($bytes, FFI::string($out, $written));
            }
        }
    }

    public function test_abi_rejects_invalid_frames_flags_ranges_and_preserves_output(): void
    {
        $ffi = self::ffi();
        $out = $ffi->new('char[256]');
        $wrap = $ffi->cast('char *', -1);
        foreach ([1 => ['abc'], 2 => [['monitor', 'releases'], true], 3 => [10, 2], 4 => [['abc', '']], 5 => [[2, 2, -1]]] as $op => $args) {
            $input = self::input($op, $args);
            [$class, $method] = self::HELPERS[$op];
            $expected = self::outputFrame($op, $class::{$method.'Php'}(...$args));
            $flags = $op === 2 ? 1 : 0;
            // @phpstan-ignore method.notFound
            $call = static fn ($p, int $n, $o, int $c, int $f = -1): int => $ffi->nntmux_w386_tmux_drain_stop_runners($op, $f === -1 ? $flags : $f, $p, $n, $o, $c);
            FFI::memset($out, 90, 256);
            foreach ([[null, strlen($input), $out, 256], [$input, strlen($input), null, 256], [$input, -1, $out, 256], [$input, strlen($input), $out, -1], [$wrap, 4, $out, 256], [$input, strlen($input), $wrap, 4], [$input, 16_777_217, $out, 256], [$input, strlen($input), $out, 16_777_217], [$input, strlen($input) - 1, $out, 256], [$input.'x', strlen($input) + 1, $out, 256], [$input, strlen($input), $out, 256, 2], [$input, strlen($input), $out, 256, 255]] as $invalid) {
                self::assertSame(-1, $call(...$invalid));
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
            if ($op !== 2) {
                self::assertSame(-1, $call($input, strlen($input), $out, 256, 1));
            }
            foreach (range(0, strlen($input) - 1) as $len) {
                self::assertSame(-1, $call($input, $len, $out, 256));
            }
            foreach ([0, 1, strlen($expected) - 1] as $cap) {
                self::assertSame(-strlen($expected), $call($input, strlen($input), $out, $cap));
                self::assertSame(str_repeat('Z', 256), FFI::string($out, 256));
            }
            self::assertSame(strlen($expected), $call($input, strlen($input), $out, strlen($expected)));
            self::assertSame($expected.str_repeat('Z', 256 - strlen($expected)), FFI::string($out, 256));
            FFI::memcpy($out, $input, strlen($input));
            self::assertSame(-1, $call($out, strlen($input), $out, 256));
            self::assertSame($input, FFI::string($out, strlen($input)));
        }
        foreach ([0, 6, 255] as $op) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w386_tmux_drain_stop_runners($op, 0, '', 0, $out, 256));
        }
        foreach ([1, 2, 4, 5] as $op) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w386_tmux_drain_stop_runners($op, 0, pack('V', 0xFFFFFFFF), 4, $out, 256));
        }
    }

    public function test_missing_symbol_library_and_declined_inputs_fall_back(): void
    {
        self::ffi();
        self::assertNull(RustPipelineLibrary::resolveDomain('additional_w386', TmuxDrain::W386_FFI_CDEF, '/missing/w386/library'));
        $cache = new ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $cache->getValue();
        $key = 'additional_w386|default|'.hash('xxh3', TmuxDrain::W386_FFI_CDEF);
        try {
            foreach ([false, FFI::cdef('int abs(int);')] as $missing) {
                $cache->setValue(null, [$key => $missing]);
                foreach (self::cases() as $op => $cases) {
                    [$class, $method] = self::HELPERS[$op];
                    $class::setUseRustW386(true);
                    foreach (array_slice($cases, 0, 12) as $args) {
                        self::assertNull($class::{$method.'Native'}(...$args));
                        self::assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args));
                    }
                }
            }
        } finally {
            $cache->setValue(null, $saved);
        }
        foreach ([1 => [str_repeat('x', 16_777_217)], 2 => [[str_repeat('x', 16_777_217)], true], 4 => [[str_repeat('x', 16_777_217)]]] as $op => $args) {
            [$class, $method] = self::HELPERS[$op];
            $class::setUseRustW386(true);
            self::assertNull($class::{$method.'Native'}(...$args));
            self::assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args));
        }
        $values = array_fill(0, 1600, 'abc');
        self::assertSame(PostProcessRunner::guidBucketPlanPhp($values), PostProcessRunner::guidBucketPlanNative($values));
    }

    /**
     * @param  list<int>  $values
     * @return array{mean_ns: float, p99_ns: int}
     */
    private static function stats(array $values): array
    {
        sort($values);

        return ['mean_ns' => array_sum($values) / count($values), 'p99_ns' => $values[(int) ceil(count($values) * 0.99) - 1]];
    }

    /**
     * Frozen protocol: seed 3862026, 256 warmup pairs, three runs of 4096 pairs
     * per helper/regime; randomized order and nearest-rank p99. Includes dispatcher,
     * packing, scratch allocation, FFI and decoding. Raw pairs and source hashes
     * are retained in NNTMUX_W386_BENCH_OUTPUT when set. Per-run estimates expose
     * variability; these are not confidence bounds or full-application speedups.
     */
    public function test_paired_randomized_benchmark_and_adoption_gate(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(3862026));
        $workloads = [
            1 => [['operation_123'], [str_repeat('a', 128)]],
            2 => [[['monitor', 'releases', 'post_additional'], true], [array_fill(0, 512, 'releases'), false]],
            3 => [[1500, 1000], [PHP_INT_MAX, PHP_INT_MIN]],
            4 => [[['a012', 'b123', 'c456']], [array_fill(0, 512, "\xffabc")]],
            5 => [[[1, 2, 3]], [array_merge(range(-256, 255), range(-256, 255))]],
        ];
        $root = dirname(__DIR__, 2);
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $report = ['protocol' => ['seed' => 3862026, 'warmup_pairs' => 256, 'pairs_per_run' => 4096, 'runs' => 3, 'p99' => 'nearest-rank'],
            'environment' => ['php' => PHP_VERSION, 'os' => php_uname(), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'rustc' => trim((string) shell_exec('rustc -Vv')), 'cargo' => trim((string) shell_exec('cargo -V')), 'base' => trim((string) shell_exec('git rev-parse HEAD')), 'profile' => 'release opt-level=3 LTO codegen-units=1', 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'limits' => 'host process; no experiment-specific caps', 'library_sha256' => hash_file('sha256', $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix)],
            'source_sha256' => [], 'results' => [], 'raw' => [], 'failures' => []];
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.php', 'tests/Unit/RustTmuxScriptsMonitorGroupsPreSleepParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', $root.'/'.$file);
        }
        foreach (self::HELPERS as $op => [$class, $method]) {
            $file = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $report['source_sha256'][substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
            $qualified = true;
            foreach (['common', 'uncommon'] as $index => $regime) {
                $args = $workloads[$op][$index];
                $expected = $class::{$method.'Php'}(...$args);
                self::assertSame($expected, $class::{$method.'Native'}(...$args), 'Rust must handle this workload without fallback.');
                for ($warm = 0; $warm < 256; $warm++) {
                    foreach ([false, true] as $use) {
                        $class::setUseRustW386($use);
                        self::assertSame($expected, $class::$method(...$args));
                    }
                }
                $all = ['php' => [], 'rust' => []];
                for ($rep = 0; $rep < 3; $rep++) {
                    $samples = ['php' => [], 'rust' => [], 'rust_first' => []];
                    for ($pair = 0; $pair < 4096; $pair++) {
                        $first = (bool) $random->getInt(0, 1);
                        $samples['rust_first'][] = $first;
                        foreach ([$first, ! $first] as $use) {
                            $class::setUseRustW386($use);
                            $start = hrtime(true);
                            $actual = $class::$method(...$args);
                            $ns = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                            $samples[$use ? 'rust' : 'php'][] = $ns;
                        }
                    }
                    $report['raw'][$method][$regime][] = $samples;
                    foreach (['php', 'rust'] as $path) {
                        array_push($all[$path], ...$samples[$path]);
                    }
                    $php = self::stats($samples['php']);
                    $rust = self::stats($samples['rust']);
                    $qualified = $qualified && min($php['mean_ns'] / $rust['mean_ns'], $php['p99_ns'] / max(1, $rust['p99_ns'])) >= 1.5;
                }
                $php = self::stats($all['php']);
                $rust = self::stats($all['rust']);
                $report['results'][$method][$regime] = ['samples' => count($all['php']), 'php' => $php, 'rust' => $rust, 'mean_speedup' => $php['mean_ns'] / $rust['mean_ns'], 'p99_speedup' => $php['p99_ns'] / max(1, $rust['p99_ns'])];
            }
            $class::setUseRustW386(null);
            if (! $qualified) {
                self::assertFalse($class::usesRustW386(), 'A failed gate must retain the PHP default.');
            }
            $report['results'][$method]['qualified'] = $qualified;
        }
        $output = getenv('NNTMUX_W386_BENCH_OUTPUT');
        if (is_string($output) && $output !== '') {
            self::assertNotFalse(file_put_contents($output, json_encode($report, JSON_THROW_ON_ERROR)));
        }
        fwrite(STDERR, "\nW386 benchmark: ".json_encode($report['results'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
    }
}
