<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ProcessBinaries;
use App\Console\Commands\ProcessReleasesMulti;
use App\Console\Commands\ProcessUpdatePerGroup;
use App\Http\Controllers\Admin\AdminCommentsController;
use App\Http\Controllers\Admin\AdminFailedReleasesController;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Pagination\Paginator;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class RustMultiprocessingAdminControllersParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASSES = [ProcessBinaries::class, ProcessReleasesMulti::class, ProcessUpdatePerGroup::class,
        AdminFailedReleasesController::class, AdminCommentsController::class];

    private const int SEED = 32032026;

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
        parent::tearDown();
    }

    public function test_toggles_default_to_php_and_are_independent(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), $class.' must keep the PHP production default.');
        }
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            foreach (self::CLASSES as $sibling) {
                if ($sibling !== $class) {
                    $this->assertFalse($sibling::usesRustFfi(), $sibling.' must not follow '.$class);
                }
            }
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_php_helpers_match_the_previous_inline_rules(): void
    {
        $this->assertSame(['max' => 0, 'limited' => false], ProcessBinaries::normalizeMaxHeadersOptionPhp('0'));
        $this->assertSame(['max' => 1000, 'limited' => true], ProcessBinaries::normalizeMaxHeadersOptionPhp('1e3'));
        $this->assertSame(['max' => -5, 'limited' => false], ProcessBinaries::normalizeMaxHeadersOptionPhp(-5));
        $this->assertSame(['max' => 0, 'limited' => false], ProcessBinaries::normalizeMaxHeadersOptionPhp(null));

        $this->assertSame(['configuredThreads' => 0, 'maxProcesses' => 1, 'clamped' => true], ProcessReleasesMulti::buildMultiReleaseExecutionPlanPhp(0));
        $this->assertSame(['configuredThreads' => 8, 'maxProcesses' => 8, 'clamped' => false], ProcessReleasesMulti::buildMultiReleaseExecutionPlanPhp(8));

        $this->assertSame(['valid' => true, 'groupId' => 7, 'dnrArgs' => 'update_per_group  7'], ProcessUpdatePerGroup::parseGroupProcessTargetPhp('007'));
        $this->assertSame(['valid' => false, 'groupId' => null, 'dnrArgs' => null], ProcessUpdatePerGroup::parseGroupProcessTargetPhp('0'));
        $this->assertSame(PHP_INT_MAX, ProcessUpdatePerGroup::parseGroupProcessTargetPhp('99999999999999999999')['groupId']);

        $this->assertSame(['page' => 1, 'perPage' => 50, 'offset' => 0], AdminFailedReleasesController::normalizeFailedReleasesIndexStatePhp(null));
        $this->assertSame(['page' => 3, 'perPage' => 50, 'offset' => 100], AdminFailedReleasesController::normalizeFailedReleasesIndexStatePhp('3'));
        $this->assertSame(['page' => 2, 'perPage' => 1, 'offset' => 1], AdminFailedReleasesController::normalizeFailedReleasesIndexStatePhp(2, 0));
        $this->assertSame(['page' => PHP_INT_MAX, 'perPage' => 50, 'offset' => PHP_INT_MAX], AdminFailedReleasesController::normalizeFailedReleasesIndexStatePhp(PHP_INT_MAX));

        $this->assertSame(['delete' => true, 'id' => 5], AdminCommentsController::normalizeCommentDeleteTargetPhp(5));
        $this->assertSame(['delete' => false, 'id' => 0], AdminCommentsController::normalizeCommentDeleteTargetPhp(0));
        $this->assertSame(['delete' => true, 'id' => 12], AdminCommentsController::normalizeCommentDeleteTargetPhp(' 12 '));
        $this->assertSame(['delete' => false, 'id' => 0], AdminCommentsController::normalizeCommentDeleteTargetPhp('12abc'));
        $this->assertSame(['delete' => false, 'id' => 0], AdminCommentsController::normalizeCommentDeleteTargetPhp(true));

        // The previous inline rules: `(int) $this->argument('max')` and `if ($id)`.
        foreach (self::fuzzStrings(400) as $text) {
            $this->assertSame((int) $text, ProcessBinaries::normalizeMaxHeadersOptionPhp($text)['max'], bin2hex($text));
        }
        foreach ([0, 1, -1, 42, PHP_INT_MAX, PHP_INT_MIN] as $id) {
            $this->assertSame((bool) $id, AdminCommentsController::normalizeCommentDeleteTargetPhp($id)['delete']);
        }
    }

    public function test_page_state_matches_laravels_current_page_resolver(): void
    {
        $values = [null, 0, 1, -1, 7, PHP_INT_MAX, PHP_INT_MIN, ...self::fuzzStrings(300)];
        foreach ($values as $value) {
            $this->app['request']->query->replace($value === null ? [] : ['page' => $value]);
            $expected = Paginator::resolveCurrentPage();
            foreach ([false, true] as $enabled) {
                AdminFailedReleasesController::setUseRustFfi($enabled);
                $this->assertSame($expected, AdminFailedReleasesController::normalizeFailedReleasesIndexState($value)['page'], var_export($value, true));
            }
        }
    }

    public function test_native_and_dispatch_parity_including_binary_and_uncommon_inputs(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = $class::$php(...$args);
                $this->assertSame($expected, $class::$native(...$args), $class.'::'.$native.' '.var_export($args, true));
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    $this->assertSame($expected, $class::$method(...$args), $class.'::'.$method);
                }
                $class::setUseRustFfi(null);
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        $ints = [0, 1, -1, PHP_INT_MAX, PHP_INT_MIN];
        for ($i = 0; $i < 200; $i++) {
            $ints[] = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
            $ints[] = $random->getInt(-100, 100);
        }
        foreach (self::fuzzStrings(1500) as $text) {
            $this->assertSame(ProcessBinaries::normalizeMaxHeadersOptionPhp($text), ProcessBinaries::normalizeMaxHeadersOptionNative($text), bin2hex($text));
            $this->assertSame(ProcessUpdatePerGroup::parseGroupProcessTargetPhp($text), ProcessUpdatePerGroup::parseGroupProcessTargetNative($text), bin2hex($text));
            $perPage = $random->getInt(-5, 200);
            $this->assertSame(AdminFailedReleasesController::normalizeFailedReleasesIndexStatePhp($text, $perPage),
                AdminFailedReleasesController::normalizeFailedReleasesIndexStateNative($text, $perPage), bin2hex($text));
            $this->assertSame(AdminCommentsController::normalizeCommentDeleteTargetPhp($text), AdminCommentsController::normalizeCommentDeleteTargetNative($text), bin2hex($text));
        }
        foreach ($ints as $int) {
            $this->assertSame(ProcessBinaries::normalizeMaxHeadersOptionPhp($int), ProcessBinaries::normalizeMaxHeadersOptionNative($int));
            $this->assertSame(ProcessReleasesMulti::buildMultiReleaseExecutionPlanPhp($int), ProcessReleasesMulti::buildMultiReleaseExecutionPlanNative($int));
            $perPage = $random->getInt(0, 1) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt(-2, 100);
            $this->assertSame(AdminFailedReleasesController::normalizeFailedReleasesIndexStatePhp($int, $perPage),
                AdminFailedReleasesController::normalizeFailedReleasesIndexStateNative($int, $perPage), $int.'/'.$perPage);
            $this->assertSame(AdminCommentsController::normalizeCommentDeleteTargetPhp($int), AdminCommentsController::normalizeCommentDeleteTargetNative($int));
        }
    }

    public function test_native_exports_answer_without_php_fallback(): void
    {
        $ffi = $this->nativeFfi();
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionMethod($class, 'ffi'))->invoke(null), $class.' must load its Wave 32.3 declarations.');
        }
        $value = $ffi->new('int64_t[1]');
        $call = static fn (string $symbol, mixed ...$args): mixed => self::callExport($ffi, 'nntmux_cat_mac_'.$symbol, ...$args);

        $this->assertSame(1, $call('max_headers', 4, 0, ' 1e3 ', 5, $value));
        $this->assertSame(1000, $value[0]);
        $this->assertSame(0, $call('max_headers', 0, 9, '', 0, $value));
        $this->assertSame(0, $value[0]);
        $this->assertSame([-1, -1, -1], [$call('max_headers', 5, 0, '', 0, $value), $call('max_headers', 4, 0, null, 0, $value), $call('max_headers', 2, 1, '', 0, null)]);

        $this->assertSame(1, $call('release_plan', -3, $value));
        $this->assertSame(1, $value[0]);
        $this->assertSame(0, $call('release_plan', 12, $value));
        $this->assertSame(12, $value[0]);
        $this->assertSame(-1, $call('release_plan', 12, null));

        $value[0] = 99;
        $this->assertSame(0, $call('group_target', '0', 1, $value));
        $this->assertSame(99, $value[0], 'An invalid target leaves the output untouched.');
        $this->assertSame(1, $call('group_target', '0042', 4, $value));
        $this->assertSame(42, $value[0]);
        $this->assertSame([-1, -1], [$call('group_target', null, 1, $value), $call('group_target', '1', 1, null)]);

        $this->assertSame(1, $call('comment_target', 4, 0, '-9', 2, $value));
        $this->assertSame(-9, $value[0]);
        $this->assertSame(0, $call('comment_target', 5, 1, '', 0, $value));
        $this->assertSame(0, $value[0]);
        $this->assertSame([-1, -1, -1], [$call('comment_target', 9, 1, '', 0, $value), $call('comment_target', 4, 0, null, 3, $value),
            $call('comment_target', 2, 1, '', 0, null)]);
    }

    public function test_direct_abi_short_buffers_do_not_write(): void
    {
        $ffi = $this->nativeFfi();
        $state = $ffi->new('int64_t[4]');
        foreach ([0, 1, 2, 3, 4] as $capacity) {
            for ($i = 0; $i < 4; $i++) {
                $state[$i] = 0x5A;
            }
            $rc = self::callExport($ffi, 'nntmux_cat_mac_index_state', 4, 0, '3', 1, 20, $state, $capacity);
            if ($capacity < 3) {
                $this->assertSame(-3, $rc, 'A short buffer reports the required length.');
                $this->assertSame([0x5A, 0x5A, 0x5A, 0x5A], [$state[0], $state[1], $state[2], $state[3]], 'A short buffer must not be written.');
            } else {
                $this->assertSame(3, $rc);
                $this->assertSame([3, 20, 40, 0x5A], [$state[0], $state[1], $state[2], $state[3]], 'Only three values are written.');
            }
        }
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_mac_index_state', 4, 0, '3', 1, 20, null, 3));
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_mac_index_state', 4, 0, null, 1, 20, $state, 3));
        $this->assertSame(-1, self::callExport($ffi, 'nntmux_cat_mac_index_state', 1, 0, '', 0, 20, $state, 3));
    }

    public function test_comment_destroy_deletes_only_truthy_ids(): void
    {
        foreach ([false, true] as $enabled) {
            AdminCommentsController::setUseRustFfi($enabled);
            foreach ([0 => false, 1 => true, -1 => true, PHP_INT_MAX => true] as $id => $deletes) {
                $this->assertSame(['delete' => $deletes, 'id' => $deletes ? $id : 0], AdminCommentsController::normalizeCommentDeleteTarget($id));
            }
        }
    }

    public function test_missing_library_and_disabled_extension_fall_back_in_fresh_processes(): void
    {
        $vectors = [];
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $vectors[] = [$class, $method, $common[0]];
            $vectors[] = [$class, $method, $uncommon[0]];
        }
        $encoded = base64_encode(serialize($vectors));
        $script = 'require "vendor/autoload.php"; foreach (unserialize(base64_decode("'.$encoded.'")) as [$class,$method,$args]) {'
            .'$class::setUseRustFfi(true); $php=$method."Php"; $native=$method."Native";'
            .'if ($class::$php(...$args)!==$class::$native(...$args) || $class::$php(...$args)!==$class::$method(...$args)) throw new RuntimeException($method);'
            .'} echo "fallback verified";';
        foreach ([['-d', 'ffi.enable=0'], []] as $options) {
            $process = new Process([PHP_BINARY, ...$options, '-r', $script], base_path(), ['NNTMUX_PIPELINE_FFI_LIB' => '/tmp/w32-3-missing-library.dylib']);
            $process->mustRun();
            $this->assertSame('fallback verified', $process->getOutput());
        }
    }

    public function test_cached_load_failure_and_call_time_errors_fall_back_for_every_helper(): void
    {
        $this->nativeFfi();
        $missingSymbols = FFI::cdef('uint32_t nntmux_pipeline_abi_version(void);', self::libraryPath());
        foreach ([false, $missingSymbols] as $handle) {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $class::setFfiForTesting($handle);
                $class::setUseRustFfi(true);
                $php = $method.'Php';
                $native = $method.'Native';
                foreach ([...$common, ...$uncommon] as $args) {
                    $expected = $class::$php(...$args);
                    $this->assertSame($expected, $class::$native(...$args), $class.'::'.$native);
                    $this->assertSame($expected, $class::$method(...$args), $class.'::'.$method);
                }
                $class::setFfiForTesting(null);
                $class::setUseRustFfi(null);
            }
        }
    }

    /**
     * Paired, randomized-order benchmark of the public dispatch helpers. Rust stays disabled
     * unless every common and uncommon mean AND p99 speedup reaches 1.5x in every round.
     */
    public function test_paired_randomized_benchmark_keeps_php_default_below_gate(): void
    {
        $this->nativeFfi();
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $warmup = 64;
        $path = sys_get_temp_dir().'/wave32-3-multiprocessing-admin-controllers-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => $warmup, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; parity outside the timed call.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including type checks, FFI marshalling, out-parameter allocation and result reconstruction. Excludes app boot, forking, console and view rendering, and database I/O.',
                'reproduce' => 'php artisan test --compact tests/Unit/RustMultiprocessingAdminControllersParityTest.php --filter=benchmark'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'host' => php_uname(),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'release: opt-level=3, lto=true, codegen-units=1',
            'library' => ['path' => realpath(self::libraryPath()), 'sha256' => hash_file('sha256', self::libraryPath())],
            'results' => [],
        ];
        $random = new Randomizer(new Mt19937(self::SEED));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                $qualifies = true;
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = array_map(static fn (array $args): string => serialize($class::$php(...$args)), $inputs);
                    $cell = ['samples_per_side_per_round' => $pairs * $calls, 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
                            for ($i = 0; $i < $warmup; $i++) {
                                $class::$method(...$inputs[$i % \count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < $pairs; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < $calls; $i++) {
                                $indices[] = $random->getInt(0, \count($inputs) - 1);
                            }
                            foreach ($random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'] as $side) {
                                $class::setUseRustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $raw[$side][] = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / max(1.0, $nativeStats['mean_ns']),
                            'p99_speedup' => $phpStats['p99_ns'] / max(1, $nativeStats['p99_ns'])];
                    }
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $qualifies = $qualifies && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $report['results'][$name]['qualifies'] = $qualifies;
                $class::setUseRustFfi(null);
                if (! $qualifies) {
                    $this->assertFalse($class::usesRustFfi(), $name.' is below the 1.5x gate and must keep the PHP default.');
                }
            }
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            $summary = ["\nWave 32.3 benchmark evidence: {$path}"];
            foreach ($report['results'] as $helper => $cells) {
                foreach (['common', 'uncommon'] as $kind) {
                    if (isset($cells[$kind]['round_range'])) {
                        $range = $cells[$kind]['round_range'];
                        $summary[] = sprintf('%s/%s mean %.2f-%.2fx p99 %.2f-%.2fx', $helper, $kind,
                            $range['mean_speedup']['min'], $range['mean_speedup']['max'], $range['p99_speedup']['min'], $range['p99_speedup']['max']);
                    }
                }
            }
            fwrite(STDERR, implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $cdef = implode("\n", array_map(static fn (string $class): string => trim($class::FFI_CDEF), self::CLASSES));
        $ffi = RustPipelineLibrary::resolveDomain('w32-3-multiprocessing-admin-controllers-test', $cdef, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library and Wave 32.3 exports must load; native coverage must not silently skip.');

        return $ffi;
    }

    private static function libraryPath(): string
    {
        $explicit = getenv('NNTMUX_PIPELINE_FFI_LIB');

        return $explicit !== false && $explicit !== ''
            ? $explicit
            : base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
    }

    /**
     * Deterministic numeric-looking and malformed strings around PHP's cast and filter rules.
     *
     * @return list<string>
     */
    private static function fuzzStrings(int $count): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + $count));
        $alphabet = ['0', '1', '7', '9', '-', '+', ' ', "\t", "\n", "\r", "\v", "\f", "\0", '.', 'e', 'x', 'a', "\xff"];
        $strings = ['', '0', '00', '-0', '+0', '9223372036854775807', '9223372036854775808', '-9223372036854775808', '-9223372036854775809',
            '99999999999999999999', '1e1000', '1e-400', '0x1A', ' 12 ', "\v4\r", "\f4", '04', '+4', '12abc', "5\0"];
        while (\count($strings) < $count) {
            if ($random->getInt(0, 2) === 0) {
                $strings[] = (string) $random->getInt(PHP_INT_MIN, PHP_INT_MAX);

                continue;
            }
            $length = $random->getInt(0, 22);
            $text = '';
            for ($i = 0; $i < $length; $i++) {
                $text .= $alphabet[$random->getInt(0, \count($alphabet) - 1)];
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $long = str_repeat("9\0\xff", 512);

        return [
            [ProcessBinaries::class, 'normalizeMaxHeadersOption', [['0'], ['10000'], ['500000']],
                [[null], [0], [-5], [PHP_INT_MAX], [PHP_INT_MIN], [''], [' 25 '], ['1e3'], ['12abc'], ['abc'], ['-7'], ['99999999999999999999'],
                    ['1e1000'], ['0x1A'], ["5\0"], ["\f9"], [$long]]],
            [ProcessReleasesMulti::class, 'buildMultiReleaseExecutionPlan', [[1], [4], [8], [16]],
                [[0], [-1], [PHP_INT_MIN], [PHP_INT_MAX], [1024]]],
            [ProcessUpdatePerGroup::class, 'parseGroupProcessTarget', [['1'], ['42'], ['12345']],
                [[''], ['0'], ['000'], ['007'], ['-1'], ['+1'], [' 1'], ['1 '], ['1.0'], ['1e3'], ["1\0"], ['99999999999999999999'],
                    ['9223372036854775808'], [$long]]],
            [AdminFailedReleasesController::class, 'normalizeFailedReleasesIndexState', [[null, 50], ['1', 50], ['2', 50], ['17', 50]],
                [[0, 50], [-3, 50], [PHP_INT_MAX, 50], [PHP_INT_MAX, 1], ['', 50], ['abc', 50], [' 4 ', 10], ["\v4\r", 10], ["\f4", 10], ['04', 10],
                    ['+4', 10], ['-0', 10], ['1e3', 10], ['9223372036854775807', 2], ['9223372036854775808', 50], ['3', 0], ['3', PHP_INT_MIN], [$long, 50]]],
            [AdminCommentsController::class, 'normalizeCommentDeleteTarget', [[1], [42], [123456]],
                [[0], [-5], [PHP_INT_MAX], [PHP_INT_MIN], ['12'], ['0'], [' 12 '], ['012'], ['12abc'], [''], [null], [true], [false], [1.0], [[]],
                    [['1']], [$long]]],
        ];
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = \count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1]];
    }

    private static function callExport(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }
}
