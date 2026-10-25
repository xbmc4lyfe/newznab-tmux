<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\RssHealthController;
use App\Providers\AppServiceProvider;
use App\Support\Auth\RedirectsUsers;
use App\Support\Configuration\ConfigurationField;
use App\Support\RustPipelineLibrary;
use App\View\Components\AppLayout;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;

final class W376RedirectHarness
{
    use RedirectsUsers;

    public ?string $redirectTo = null;
}

/**
 * Local pure-CPU helper screen only; no production throughput claim.
 * PHP 8.5.12, Apple M5/macOS arm64, rustc 1.99.1 release/LTO.
 * All five candidates failed the 1.5x mean/p99 gate; defaults remain off.
 * Reproduce with NNTMUX_W376_BENCH=1 NNTMUX_W376_BENCH_OUTPUT=/tmp/w376-benchmark.json
 * php vendor/bin/phpunit tests/Unit/RustAppServiceProviderRssHealthConfigAuthParityTest.php
 * Seed 3762026; 256 warmups, 3 x 4096 paired single calls per regime;
 * randomized order, nearest-rank p99, full dispatcher/serialization/FFI cost.
 */
final class RustAppServiceProviderRssHealthConfigAuthParityTest extends TestCase
{
    private const array HELPERS = [
        [AppServiceProvider::class, 'summarizePulseCommand'],
        [RssHealthController::class, 'summarizeRssHealth'],
        [ConfigurationField::class, 'summarizeFieldMetadata'],
        [W376RedirectHarness::class, 'resolveRedirectPlan'],
        [AppLayout::class, 'summarizeLayoutView'],
    ];

    private static function toggle(string $class, ?bool $enabled): void
    {
        if ($class === W376RedirectHarness::class) {
            $class::useRedirectRustFfi($enabled);
        } else {
            $class::useRustFfi($enabled);
        }
    }

    private static function enabled(string $class): bool
    {
        return $class === W376RedirectHarness::class ? $class::usesRedirectRustFfi() : $class::usesRustFfi();
    }

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::toggle($class, null);
        }
        parent::tearDown();
    }

    /** @param list<mixed> $args */
    private static function native(string $class, string $method, array $args): mixed
    {
        return (new ReflectionMethod($class, $method.'Native'))->invokeArgs(null, $args);
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional_w376', AppServiceProvider::W376_FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release library with the w376 export.');

        return $ffi;
    }

    public function test_literal_contracts(): void
    {
        self::assertSame(['disable_pulse' => true], AppServiceProvider::summarizePulseCommand(true, false, 'tmux:start'));
        self::assertSame(['disable_pulse' => false], AppServiceProvider::summarizePulseCommand(true, false, 'TMUX:start'));
        self::assertSame(['status' => 'ok', 'service' => 'rss'], RssHealthController::summarizeRssHealth(true));
        self::assertSame(['status' => 'error', 'service' => 'rss'], RssHealthController::summarizeRssHealth(false));
        self::assertSame(['control' => 'enum', 'rule_count' => 0, 'option_count' => 3, 'sensitive' => true, 'has_options' => true], ConfigurationField::summarizeFieldMetadata('enum', -1, 3, true));
        self::assertSame('', W376RedirectHarness::resolveRedirectPlan(true, '', '/property'));
        self::assertSame('0', W376RedirectHarness::resolveRedirectPlan(false, '/method', '0'));
        self::assertSame('/home', W376RedirectHarness::resolveRedirectPlan(false, '', null));
        self::assertSame(['view' => 'layouts.app', 'is_app' => true], AppLayout::summarizeLayoutView(null));
        self::assertSame(['view' => '', 'is_app' => false], AppLayout::summarizeLayoutView(''));
    }

    /** @return list<list<list<mixed>>> */
    private static function inputs(): array
    {
        $random = new Randomizer(new Mt19937(3762026));
        $strings = ['', '0', '/home', 'layouts.app', 'tmux:', 'tmux:start', 'TMUX:start', 'releases:verify', "\0\xff\n", 'é日本語', str_repeat('x', 4096)];
        $cases = [[], [[true], [false]], [], [], []];
        foreach ($strings as $s) {
            foreach ([true, false] as $a) {
                foreach ([true, false] as $b) {
                    $cases[0][] = [$a, $b, $s];
                }
            }
            $cases[2][] = [$s, PHP_INT_MIN, PHP_INT_MAX, true];
            $cases[3][] = [true, $s, null];
            $cases[3][] = [false, '/unused', $s];
            $cases[4][] = [$s];
        }
        $cases[0][] = [true, false, null];
        $cases[3][] = [false, '', null];
        $cases[4][] = [null];
        for ($i = 0; $i < 1000; $i++) {
            $s = $i % 3 === 0 ? $random->getBytes($random->getInt(1, 200)) : $strings[$random->getInt(0, count($strings) - 1)];
            $cases[0][] = [$random->getInt(0, 1) === 1, $random->getInt(0, 1) === 1, $s];
            $cases[1][] = [$random->getInt(0, 1) === 1];
            $cases[2][] = [$s, $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(0, 1) === 1];
            $cases[3][] = [$random->getInt(0, 1) === 1, $s, $i % 4 === 0 ? null : $strings[$random->getInt(0, count($strings) - 1)]];
            $cases[4][] = [$i % 7 === 0 ? null : $s];
        }

        return $cases;
    }

    public function test_randomized_parity_cannot_pass_through_fallback(): void
    {
        self::ffi();
        foreach (self::inputs() as $index => $cases) {
            [$class, $method] = self::HELPERS[$index];
            foreach ($cases as $args) {
                self::toggle($class, false);
                $expected = $class::$method(...$args);
                self::assertSame($expected, self::native($class, $method, $args), $method);
                self::toggle($class, true);
                self::assertSame($expected, $class::$method(...$args), $method);
            }
        }
    }

    public function test_toggles_and_auth_trait_composition_remain_independent(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::assertFalse(self::enabled($class));
            self::toggle($class, true);
            foreach (self::HELPERS as [$other]) {
                self::assertSame($class === $other, self::enabled($other));
            }
            if ($class === W376RedirectHarness::class) {
                $class::resetRedirectRustFfi();
            } else {
                $class::resetRustFfi();
            }
            self::assertFalse(self::enabled($class));
        }
        foreach ([LoginController::class, RegisterController::class, ResetPasswordController::class] as $class) {
            self::assertTrue(class_exists($class));
            $previous = $class::usesRustFfi();
            $class::useRedirectRustFfi(true);
            self::assertSame($previous, $class::usesRustFfi());
            $class::resetRedirectRustFfi();
        }
        self::assertSame('/home', (new W376RedirectHarness)->redirectPath());
        $controller = new class
        {
            use RedirectsUsers;

            public string $redirectTo = '/property';

            public function redirectTo(): string
            {
                return '/method';
            }
        };
        self::assertSame('/method', $controller->redirectPath());
        $field = new ConfigurationField('col', 'Label', 'Help', 'anything', ['required'], [0 => 'Zero'], null, true);
        self::assertSame('anything', $field->control);
        self::assertSame([0 => 'Zero'], $field->options);
    }

    /** @return array<int, array{string, string}> */
    private static function frames(): array
    {
        $string = static fn (string $s): string => pack('V', strlen($s)).$s;

        return [
            1 => ["\1\0".$string('tmux:start'), "\1"],
            2 => ["\1", "\1"],
            3 => ["\1".pack('q2', -1, 2).$string('enum'), pack('q2', 0, 2)."\1\1".$string('enum')],
            4 => ["\0\0".$string('/unused').$string(''), $string('/home')],
            5 => ["\0".$string(''), "\1".$string('layouts.app')],
        ];
    }

    public function test_direct_abi_validation_and_capacity_retry_never_write_on_error(): void
    {
        $ffi = self::ffi();
        $out = $ffi->new('char[128]');
        $wrap = $ffi->cast('char *', -1);
        foreach (self::frames() as $op => [$input, $expected]) {
            // @phpstan-ignore method.notFound
            $call = static fn ($p, int $n, $o, int $c): int => $ffi->nntmux_w376_app_rss_config_auth($op, $p, $n, $o, $c);
            FFI::memset($out, 90, 128);
            foreach ([[null, strlen($input), $out, 128], [$input, strlen($input), null, 128], [$wrap, strlen($input), $out, 128], [$input, strlen($input), $wrap, 128], [$input, -1, $out, 128], [$input, strlen($input), $out, -1], [$input, 16_777_217, $out, 128], [$input, strlen($input), $out, 16_777_217], [$input.'x', strlen($input) + 1, $out, 128]] as $args) {
                self::assertSame(-1, $call(...$args));
                self::assertSame(str_repeat('Z', 128), FFI::string($out, 128));
            }
            for ($n = 0; $n < strlen($input); $n++) {
                self::assertSame(-1, $call($input, $n, $out, 128));
            }
            self::assertSame(-strlen($expected), $call($input, strlen($input), $out, 0));
            if (strlen($expected) > 1) {
                self::assertSame(-strlen($expected), $call($input, strlen($input), $out, strlen($expected) - 1));
            }
            self::assertSame(str_repeat('Z', 128), FFI::string($out, 128));
            self::assertSame(strlen($expected), $call($input, strlen($input), $out, strlen($expected)));
            self::assertSame($expected, FFI::string($out, strlen($expected)));
            self::assertSame(str_repeat('Z', 128 - strlen($expected)), FFI::string(FFI::addr($out[strlen($expected)]), 128 - strlen($expected)));
            $alias = $ffi->new('char[128]');
            FFI::memcpy($alias, $input, strlen($input));
            self::assertSame(-1, $call($alias, strlen($input), $alias, 128));
            self::assertSame($input, FFI::string($alias, strlen($input)));
        }
        foreach ([0, 6, 255] as $op) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w376_app_rss_config_auth($op, '', 0, $out, 128));
        }
        foreach (self::frames() as $op => [$input]) {
            $input[0] = "\2";
            FFI::memset($out, 90, 128);
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w376_app_rss_config_auth($op, $input, strlen($input), $out, 128));
            self::assertSame(str_repeat('Z', 128), FFI::string($out, 128));
        }
    }

    public function test_secondary_flags_and_noncanonical_nullable_frames_are_invalid(): void
    {
        $ffi = self::ffi();
        $out = $ffi->new('char[128]');
        $frames = self::frames();
        $pulse = $frames[1][0];
        $pulse[1] = "\2";
        $redirect = $frames[4][0];
        $redirect[1] = "\2";
        foreach ([[1, $pulse], [4, $redirect], [4, "\0\0".pack('V', 0).pack('V', 1).'x'], [5, "\0".pack('V', 1).'x'], [5, "\1".pack('V', 0xFFFFFFFF)]] as [$op, $input]) {
            FFI::memset($out, 90, 128);
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w376_app_rss_config_auth($op, $input, strlen($input), $out, 128));
            self::assertSame(str_repeat('Z', 128), FFI::string($out, 128));
        }
    }

    public function test_missing_library_symbol_and_declined_inputs_fall_back(): void
    {
        self::assertNull(RustPipelineLibrary::resolveDomain('additional_w376', AppServiceProvider::W376_FFI_CDEF, '/missing/w376.dylib'));
        $handle = new ReflectionProperty(AppServiceProvider::class, 'w376Ffi');
        foreach ([false, FFI::cdef('int abs(int);')] as $unavailable) {
            $handle->setValue(null, $unavailable);
            foreach (self::inputs() as $index => $cases) {
                [$class, $method] = self::HELPERS[$index];
                foreach (array_slice($cases, 0, 10) as $args) {
                    self::toggle($class, false);
                    $expected = $class::$method(...$args);
                    self::toggle($class, true);
                    self::assertSame($expected, $class::$method(...$args));
                }
            }
        }
        AppServiceProvider::resetRustFfi();
        $large = str_repeat('x', 16_777_217);
        foreach ([[0, [true, false, $large]], [2, [$large, 1, 2, false]], [3, [true, $large, null]], [4, [$large]]] as [$index, $args]) {
            [$class, $method] = self::HELPERS[$index];
            self::toggle($class, false);
            $expected = $class::$method(...$args);
            self::assertNull(self::native($class, $method, $args));
            self::toggle($class, true);
            self::assertSame($expected, $class::$method(...$args));
        }
    }

    public function test_large_binary_output_retries_shared_scratch(): void
    {
        self::ffi();
        $binary = str_repeat("/é\0\xff", 70000);
        foreach ([[2, [$binary, PHP_INT_MAX, PHP_INT_MIN, true]], [3, [true, $binary, null]], [4, [$binary]]] as [$index, $args]) {
            [$class, $method] = self::HELPERS[$index];
            self::toggle($class, false);
            $expected = $class::$method(...$args);
            self::assertSame($expected, self::native($class, $method, $args));
            self::toggle($class, true);
            self::assertSame($expected, $class::$method(...$args));
        }
        self::assertNull(AppServiceProvider::w376Call(0, ''));
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_W376_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W376_BENCH=1 for paired benchmarks.');
        }
        self::ffi();
        $random = new Randomizer(new Mt19937(3762026));
        $workloads = [
            [[true, false, 'tmux:start'], [true, false, str_repeat("x\0", 2048)]],
            [[true], [false]],
            [['enum', 3, 8, false], [str_repeat("x\xff", 2048), PHP_INT_MIN, PHP_INT_MAX, true]],
            [[false, '', '/home'], [true, str_repeat("/é\0", 1024), null]],
            [[null], [str_repeat("layouts.app\0", 512)]],
        ];
        $root = dirname(__DIR__, 2);
        $hashes = [];
        foreach ([...array_map(static fn (array $h): string => (string) (new \ReflectionClass($h[0]))->getFileName(), self::HELPERS), $root.'/app/Support/Auth/RedirectsUsers.php', $root.'/rust/pipeline-ffi/src/additional.rs', $root.'/rust/pipeline-ffi/Cargo.lock', $root.'/composer.lock'] as $file) {
            $hashes[substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $report = ['protocol' => ['seed' => 3762026, 'warmups' => 256, 'pairs' => 4096, 'repetitions' => 3, 'p99' => 'nearest-rank'], 'environment' => ['php' => PHP_VERSION, 'os' => php_uname(), 'rustc' => trim((string) shell_exec('rustc -V')), 'base' => trim((string) shell_exec('git rev-parse HEAD')), 'profile' => 'release/LTO', 'opcache' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'library_sha256' => hash_file('sha256', $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix)], 'source_sha256' => $hashes, 'results' => []];
        foreach (self::HELPERS as $index => [$class, $method]) {
            foreach (['common', 'uncommon'] as $regimeIndex => $regime) {
                $args = $workloads[$index][$regimeIndex];
                self::toggle($class, false);
                $expected = $class::$method(...$args);
                self::assertSame($expected, self::native($class, $method, $args));
                for ($i = 0; $i < 256; $i++) {
                    foreach ([false, true] as $enabled) {
                        self::toggle($class, $enabled);
                        self::assertSame($expected, $class::$method(...$args));
                    }
                }
                for ($rep = 0; $rep < 3; $rep++) {
                    $samples = ['php' => [], 'rust' => []];
                    for ($pair = 0; $pair < 4096; $pair++) {
                        foreach ($random->getInt(0, 1) === 0 ? [false, true] : [true, false] as $enabled) {
                            self::toggle($class, $enabled);
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
                        $stats[$path] = ['mean_ns' => array_sum($values) / count($values), 'p99_ns' => $values[(int) ceil(count($values) * 0.99) - 1]];
                    }
                    $speedup = ['mean' => $stats['php']['mean_ns'] / max(1, $stats['rust']['mean_ns']), 'p99' => $stats['php']['p99_ns'] / max(1, $stats['rust']['p99_ns'])];
                    $report['results'][] = compact('method', 'regime', 'rep', 'stats', 'speedup', 'samples');
                    fwrite(STDERR, json_encode(compact('method', 'regime', 'rep', 'speedup'), JSON_THROW_ON_ERROR)."\n");
                    self::toggle($class, null);
                    if (min($speedup) < 1.5) {
                        self::assertFalse(self::enabled($class));
                    }
                }
            }
        }
        $destination = getenv('NNTMUX_W376_BENCH_OUTPUT') ?: '/tmp/w376-benchmark.json';
        self::assertNotFalse(file_put_contents($destination, json_encode($report, JSON_THROW_ON_ERROR)));
    }
}
