<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mcp\ProcessRunner;
use App\Services\Mcp\RuntimeProbeRunner;
use App\Services\Mcp\VerificationPlanner;
use App\Services\Mcp\VerificationRunner;
use App\Services\Monitoring\Collectors\DatabaseFingerprintCollector;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustMcpProcessProbeVerificationPlannerRunnerDbFpCollectorW54ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ProcessRunner::class => 'planOutputLimitBatch',
        RuntimeProbeRunner::class => 'planProbeBatch',
        VerificationPlanner::class => 'planPathBatch',
        VerificationRunner::class => 'planResultBatch',
        DatabaseFingerprintCollector::class => 'planJobStatusBatch',
    ];

    /** Class => Wave 54.2 export. */
    private const SYMBOLS = [
        ProcessRunner::class => 'nntmux_w542_process_output_limit_batch',
        RuntimeProbeRunner::class => 'nntmux_w542_runtime_probe_plan_batch',
        VerificationPlanner::class => 'nntmux_w542_verification_path_plan_batch',
        VerificationRunner::class => 'nntmux_w542_verification_result_plan_batch',
        DatabaseFingerprintCollector::class => 'nntmux_w542_db_fingerprint_status_plan_batch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns): the per-metric median of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 54, each metric
     * the median of 3 rounds of 1000 pairs after 50 warmups, each side the fastest of 3
     * back-to-back calls, randomized fixtures, alternating call order, release Rust, PHP 8.5.12
     * CLI without opcache, Apple Silicon macOS on a shared host at load average 6-9). Timings
     * include dispatch, packing, the FFI call and unpack('P*'). The runs' low was
     * RuntimeProbeRunner Common mean 1.822x in one run (1.927x and 1.904x in the others). A class
     * defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ProcessRunner::class => [5.199, 4.058, 3.897, 3.543],
        RuntimeProbeRunner::class => [1.904, 1.985, 2.488, 2.737],
        VerificationPlanner::class => [6.041, 5.758, 6.571, 5.35],
        VerificationRunner::class => [3.027, 3.035, 3.583, 3.506],
        DatabaseFingerprintCollector::class => [2.523, 2.483, 3.546, 3.554],
    ];

    private const ROUNDS = 3;

    private const PAIRS = 1000;

    private const WARMUPS = 50;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const PROBE_IDS = ['database', 'disk', 'nntp', 'queue', 'redis', 'search'];

    private const CHECK_NAMES = ['phpunit', 'pint', 'phpstan', 'php-lint', 'frontend', 'composer', 'diff-unstaged', 'diff-staged'];

    private const JOB_STATUSES = ['pending', 'processing', 'complete', 'failed'];

    /** Changed paths an agent typically verifies. */
    private const PATHS = [
        'app/Services/Mcp/ProcessRunner.php', 'app/Services/Mcp/VerificationPlanner.php', 'app/Mcp/Tools/RunVerificationTool.php',
        'app/Services/Monitoring/Collectors/DatabaseFingerprintCollector.php', 'app/Models/Release.php', 'app/Http/Controllers/Api/ApiController.php',
        'tests/Unit/ProcessRunnerTest.php', 'tests/Feature/McpVerificationServicesTest.php', 'tests/Unit/RustPipelineLibraryTest.php',
        'resources/views/themes/Gentele/browse.blade.php', 'resources/js/app.js', 'resources/css/app.css', 'package.json', 'vite.config.js',
        'composer.json', 'composer.lock', 'config/nntmux-mcp.php', 'config/nntmux.php', 'routes/ai.php', 'routes/web.php',
        'database/migrations/2026_01_01_000000_create_release_fingerprint_jobs_table.php', 'rust/pipeline-ffi/src/nfo.rs', 'docs/README.md',
        'Blacklight/NZBGet.php', 'app/Support/RustPipelineLibrary.php',
    ];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW54RustFfi', 'withW54RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w54RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertTrue($reflection->hasConstant('W54_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W54_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W54_RUST_FFI_DEFAULT')?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant('W54_FFI_CDEF'), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w54', strtolower((string) $property), 'Wave 54 state lives in w54RustFfiState().');
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW54RustFfi(), $class);
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW54RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW54RustFfi(! $enabled, static fn (): bool => $class::usesW54RustFfi());

                    return [$class::usesW54RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW54RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW54RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW54RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_waves(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW54RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW54RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        // The Wave 54 toggles leave the earlier single-call Rust switches untouched.
        ProcessRunner::withW54RustFfi(true, static function (): void {
            VerificationPlanner::withW54RustFfi(true, static function (): void {
                DatabaseFingerprintCollector::withW54RustFfi(true, static function (): void {
                    self::assertFalse(ProcessRunner::usesRustFfi());
                    self::assertFalse(RuntimeProbeRunner::usesRustFfi());
                    self::assertFalse(VerificationPlanner::usesRustFfi());
                    self::assertFalse(VerificationRunner::usesRustFfi());
                    self::assertFalse(DatabaseFingerprintCollector::usesRustFfi());
                });
            });
        });
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5400));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $args = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $args);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW54RustFfi($native, fn (): array => $this->call($class, '', $args)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $args), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes, ragged columns and keyed arrays keep the PHP semantics.
        $this->assertParity(ProcessRunner::class, [['abc', 'de'], [2]]);
        $this->assertParity(ProcessRunner::class, [[1 => 'abc', 2 => 'de'], [1 => 2, 2 => 1]]);
        $this->assertParity(RuntimeProbeRunner::class, [["redis\0", 'disk'], [true, false], [1, 2]]);
        $this->assertParity(RuntimeProbeRunner::class, [['redis', 'disk'], [true], [1, 2]]);
        $this->assertParity(VerificationPlanner::class, [["app/A.php\0", 'b.php']]);
        $this->assertParity(VerificationPlanner::class, [['x' => 'app/A.php']]);
        $this->assertParity(VerificationRunner::class, [['pint', "phpstan\0"], ['passed', 'failed'], [false, false]]);
        $this->assertParity(VerificationRunner::class, [['pint', 'phpstan'], ['passed'], [false, false]]);
        $this->assertParity(DatabaseFingerprintCollector::class, [["pending\0", 'failed'], [1, 2]]);
        $this->assertParity(DatabaseFingerprintCollector::class, [['pending', 'failed'], [1]]);

        // A non-UTF-8 internal encoding cuts outputs by that encoding, so PHP handles it.
        $encoding = mb_internal_encoding();
        mb_internal_encoding('ISO-8859-1');
        try {
            $this->assertParity(ProcessRunner::class, [["ab\xC3\xA9x", "\xC3\xA9\xC3\xA9"], [3, 1]]);
        } finally {
            mb_internal_encoding($encoding);
        }
        // A backtrack limit the stem or filter pattern could exceed keeps PHP.
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '4');
        try {
            $this->assertParity(VerificationPlanner::class, [['tests/Unit/FooTest.php', 'app/Foo.php']]);
            $this->assertParity(VerificationRunner::class, [['Tests\\Unit\\FooTest::test_a', 'pint'], ['passed', 'failed'], [false, true]]);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        $process = $this->ffi(ProcessRunner::class);
        $out = $process->new('int64_t[32]');
        $symbol = self::SYMBOLS[ProcessRunner::class];
        $ints = pack('P*', 3, 5);
        self::assertSame(8, $this->invoke($process, $symbol, ['abc', 3, $ints, 16, 1, $out, 32]));
        self::assertSame(-1, $this->invoke($process, $symbol, ['abc', 3, $ints, 16, 1, null, 32]));
        self::assertSame(-8, $this->invoke($process, $symbol, ['abc', 3, $ints, 16, 1, $out, 4]));
        self::assertSame(-1, $this->invoke($process, $symbol, ['abcd', 4, $ints, 16, 1, $out, 32]));
        self::assertSame(-1, $this->invoke($process, $symbol, ['abc', 3, pack('P*', -1, 5), 16, 1, $out, 32]));
        self::assertSame(-1, $this->invoke($process, $symbol, [null, 3, $ints, 16, 1, $out, 32]));
        self::assertSame(-1, $this->invoke($process, $symbol, ['abc', 3, $ints, 8, 1, $out, 32]));
        self::assertSame(6, $this->invoke($process, $symbol, [null, 0, null, 0, 0, $out, 32]));

        $guards = [
            RuntimeProbeRunner::class => [['disk', 4, pack('P*', 1, 5), 16, 1], ['disk', 4, pack('P', 1), 8, 1], 8, 7],
            DatabaseFingerprintCollector::class => [['failed', 6, pack('P', 1), 8, 1], ["failed\0x", 8, pack('P', 1), 8, 1], 10, 8],
        ];
        foreach ($guards as $class => [$good, $bad, $words, $empty]) {
            $ffi = $this->ffi($class);
            $classOut = $ffi->new('int64_t[32]');
            $classSymbol = self::SYMBOLS[$class];
            self::assertSame($words, $this->invoke($ffi, $classSymbol, [...$good, $classOut, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $classSymbol, [...$good, null, 32]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $classSymbol, [...$good, $classOut, 2]), $class);
            self::assertSame(-1, $this->invoke($ffi, $classSymbol, [...$bad, $classOut, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $classSymbol, [null, $good[1], ...\array_slice($good, 2), $classOut, 32]), $class);
            self::assertSame($empty, $this->invoke($ffi, $classSymbol, [null, 0, null, 0, 0, $classOut, 32]), $class);
        }

        $planner = $this->ffi(VerificationPlanner::class);
        $plannerOut = $planner->new('int64_t[32]');
        $plannerSymbol = self::SYMBOLS[VerificationPlanner::class];
        self::assertSame(10, $this->invoke($planner, $plannerSymbol, ["a.php\0b.js", 10, 2, $plannerOut, 32]));
        self::assertSame(-1, $this->invoke($planner, $plannerSymbol, ["a.php\0b.js", 10, 1, $plannerOut, 32]));
        self::assertSame(-1, $this->invoke($planner, $plannerSymbol, ["a.php\0b.js", 10, 2, null, 32]));
        self::assertSame(-10, $this->invoke($planner, $plannerSymbol, ["a.php\0b.js", 10, 2, $plannerOut, 9]));
        self::assertSame(-1, $this->invoke($planner, $plannerSymbol, [null, 5, 1, $plannerOut, 32]));
        self::assertSame(8, $this->invoke($planner, $plannerSymbol, [null, 0, 0, $plannerOut, 32]));

        $runner = $this->ffi(VerificationRunner::class);
        $runnerOut = $runner->new('int64_t[32]');
        $runnerSymbol = self::SYMBOLS[VerificationRunner::class];
        $one = pack('P', 1);
        self::assertSame(8, $this->invoke($runner, $runnerSymbol, ['pint', 4, 'failed', 6, $one, 8, 1, $runnerOut, 32]));
        self::assertSame(-1, $this->invoke($runner, $runnerSymbol, ['pint', 4, "failed\0", 7, $one, 8, 1, $runnerOut, 32]));
        self::assertSame(-1, $this->invoke($runner, $runnerSymbol, ['pint', 4, 'failed', 6, $one, 4, 1, $runnerOut, 32]));
        self::assertSame(-1, $this->invoke($runner, $runnerSymbol, ['pint', 4, null, 6, $one, 8, 1, $runnerOut, 32]));
        self::assertSame(-1, $this->invoke($runner, $runnerSymbol, ['pint', 4, 'failed', 6, $one, 8, 1, null, 32]));
        self::assertSame(-8, $this->invoke($runner, $runnerSymbol, ['pint', 4, 'failed', 6, $one, 8, 1, $runnerOut, 7]));
        self::assertSame(7, $this->invoke($runner, $runnerSymbol, [null, 0, null, 0, null, 0, 0, $runnerOut, 32]));
    }

    public function test_output_limit_plan_parity(): void
    {
        $this->requireBindings();
        $outputs = ['abc', 'defg', "ab\xC3\xA9x", '', "\0", "\xff\x80\x80zz"];
        $maximums = [10, 2, 3, 0, -4, 2];
        $words = [
            3, crc32('abc') << 32,
            2, 1 | crc32('de\n[output truncated]') << 32,
            2, 1 | 4 | crc32('ab\n[output truncated]') << 32,
            0, 2 | 8,
            1, 8 | 16 | crc32("\0") << 32,
            0, 1 | 4 | 32 | crc32('\n[output truncated]') << 32,
            3, 18, 8, 1, 5,
        ];
        $this->assertWords(ProcessRunner::class, [$outputs, $maximums], $words);
        $this->assertParity(ProcessRunner::class, [$outputs, $maximums]);
        // The plan agrees with limitOutputPhp() for every output.
        $random = new Randomizer(new Mt19937(5410));
        [$outputs, $maximums] = $this->fixture(ProcessRunner::class, $random, true);
        $plan = $this->call(ProcessRunner::class, 'Native', [$outputs, $maximums]);
        foreach ($outputs as $i => $output) {
            [$limited, $truncated] = ProcessRunner::limitOutputPhp($output, $maximums[$i]);
            self::assertSame($truncated ? \strlen($limited) - 20 : \strlen($output), $plan[2 * $i]);
            self::assertSame($truncated, ($plan[2 * $i + 1] & 1) === 1);
            self::assertSame(crc32($limited), $plan[2 * $i + 1] >> 32 & 0xFFFFFFFF);
        }
        $this->assertRandomParity(ProcessRunner::class, 5420);
    }

    public function test_probe_plan_parity(): void
    {
        $this->requireBindings();
        $args = [['redis', 'custom', 'redis', '', '1', '01'], [true, false, false, true, true, false], [5, 1200, 7, -3, 0, 9]];
        $words = [
            2 | 4 | crc32('redis') << 32,
            16 | 1 << 8 | crc32('custom') << 32,
            1 | 4 | crc32('redis') << 32,
            2 | 8 | 3 << 8,
            2 | 4 << 8 | crc32('1') << 32,
            5 << 8 | crc32('01') << 32,
            5, 3, 4, 0, 1200, -3,
        ];
        $this->assertWords(RuntimeProbeRunner::class, $args, $words);
        $this->assertParity(RuntimeProbeRunner::class, $args);
        // The plan agrees with deduplicateIdentifiersPhp() and resolveProbeBatchStatusPhp().
        $random = new Randomizer(new Mt19937(5430));
        [$ids, $oks, $latencies] = $this->fixture(RuntimeProbeRunner::class, $random, false);
        $plan = $this->call(RuntimeProbeRunner::class, 'Native', [$ids, $oks, $latencies]);
        $unique = RuntimeProbeRunner::deduplicateIdentifiersPhp($ids);
        $kept = [];
        foreach ($ids as $i => $id) {
            if (($plan[$i] & 1) === 0) {
                $kept[] = ['ok' => $oks[$i]];
            }
        }
        self::assertSame(\count($unique), $plan[\count($ids)]);
        self::assertSame(RuntimeProbeRunner::resolveProbeBatchStatusPhp($kept) === 'passed' ? 1 : 0, $plan[\count($ids) + 3]);
        $this->assertRandomParity(RuntimeProbeRunner::class, 5440);
    }

    public function test_path_plan_parity(): void
    {
        $this->requireBindings();
        $paths = ['app/Services/Mcp/ProcessRunner.php', 'tests/Unit/ProcessRunnerTest.php', 'resources/views/a.blade.php', 'composer.json', '0.php', "a.php\n", "a\n", 'dir//', 'Test.php'];
        $words = [
            1 | 4 | 32 | 13 << 8 | crc32('ProcessRunner') << 32,
            1 | 8 | 64 | 13 << 8 | crc32('ProcessRunner') << 32,
            2 | 32 | 7 << 8 | crc32('a.blade') << 32,
            16 | 32 | 13 << 8 | crc32('composer.json') << 32,
            1 | 128 | 1 << 8 | crc32('0') << 32,
            32 | 2 << 8 | crc32("a\n") << 32,
            64 | 2 << 8 | crc32("a\n") << 32,
            32 | 3 << 8 | crc32('dir') << 32,
            1 | 128,
            4, 1, 1, 1, 1, 5, 6,
        ];
        $this->assertWords(VerificationPlanner::class, [$paths], $words);
        $this->assertParity(VerificationPlanner::class, [$paths]);
        // The plan agrees with the planner's single-path helpers.
        $random = new Randomizer(new Mt19937(5450));
        [$paths] = $this->fixture(VerificationPlanner::class, $random, true);
        $plan = $this->call(VerificationPlanner::class, 'Native', [$paths]);
        $kept = 0;
        foreach ($paths as $i => $path) {
            self::assertSame(VerificationPlanner::isFrontendPathPhp($path), ($plan[$i] & 2) !== 0, $path);
            self::assertSame(VerificationPlanner::containsMcpPathPhp([$path]), ($plan[$i] & 4) !== 0, $path);
            $kept += ($plan[$i] & 32) !== 0 ? 1 : 0;
        }
        self::assertSame(\count(VerificationPlanner::relatedTestStemsPhp($paths)), $kept);
        self::assertSame($kept, $plan[\count($paths) + 5]);
        $this->assertRandomParity(VerificationPlanner::class, 5460);
    }

    public function test_result_plan_parity(): void
    {
        $this->requireBindings();
        $args = [['pint', 'diff-staged', 'pint', 'x y', "a\n"], ['passed', 'skipped', 'timed_out', '', 'failed'], [false, true, false, false, true]];
        $words = [
            4 | 8 | 1 << 8 | crc32('pint') << 32,
            2 | 4 | 16 | 2 << 8 | crc32('diff-staged') << 32,
            1 | 4 | 8 | 4 << 8 | crc32('pint') << 32,
            1 | crc32('x y') << 32,
            1 | 2 | 4 | 3 << 8 | crc32("a\n") << 32,
            1, 1, 3, 1, 1, 4,
        ];
        $this->assertWords(VerificationRunner::class, $args, $words);
        $this->assertParity(VerificationRunner::class, $args);
        // Flag 4 agrees with validFilterPhp() for every name.
        $random = new Randomizer(new Mt19937(5470));
        [$names, $statuses, $truncated] = $this->fixture(VerificationRunner::class, $random, true);
        $plan = $this->call(VerificationRunner::class, 'Native', [$names, $statuses, $truncated]);
        foreach ($names as $i => $name) {
            self::assertSame(VerificationRunner::validFilterPhp($name), ($plan[$i] & 4) !== 0, $name);
        }
        $this->assertRandomParity(VerificationRunner::class, 5480);
    }

    public function test_job_status_plan_parity(): void
    {
        $this->requireBindings();
        $args = [['complete', 'orphan', 'complete', '', '7', 'pending'], [5, -2, 9, 0, 3, 4]];
        $words = [
            1 | 2 << 8 | crc32('complete') << 32, 9,
            8 | 4 << 8 | crc32('orphan') << 32, -2,
            1 | 2 | 2 << 8 | crc32('complete') << 32, 9,
            4 | 5 << 8, 0,
            6 << 8 | crc32('7') << 32, 3,
            1 | crc32('pending') << 32, 4,
            4, 0, 9, 0, 7, 4, 9,
        ];
        $this->assertWords(DatabaseFingerprintCollector::class, $args, $words);
        $this->assertParity(DatabaseFingerprintCollector::class, $args);
        $this->assertRandomParity(DatabaseFingerprintCollector::class, 5490);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $args): void
    {
        $this->requireBindings();
        $this->assertParity($class, $args);
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<mixed>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('k', 70000);
        $utf8 = str_repeat("\xC3\xA9", 40).str_repeat("\xE2\x82\xAC", 40).str_repeat("\xF0\x9F\x98\x80", 40);
        $invalid = ["\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xE2\x82", "\x80", "\xF8\x88\x80\x80\x80", "\xEF\xBF\xBF", "\xF0\x9F\x98"];
        yield 'output empty' => [ProcessRunner::class, [[], []]];
        yield 'output bounds' => [ProcessRunner::class, [
            ['', 'a', 'ab', $long, $long, $utf8, $utf8, $utf8, "a\0b\0c", ...$invalid],
            [0, -1, PHP_INT_MIN, PHP_INT_MAX, 69999, 1, 2, 3, 2, ...array_fill(0, \count($invalid), 1)],
        ]];
        yield 'output utf8 cuts' => [ProcessRunner::class, [array_fill(0, 12, $utf8), range(1, 12)]];
        yield 'output invalid cuts' => [ProcessRunner::class, [$invalid, array_fill(0, \count($invalid), 2)]];
        yield 'probe empty' => [RuntimeProbeRunner::class, [[], [], []]];
        yield 'probe bounds' => [RuntimeProbeRunner::class, [
            ['', '0', '00', '1', '-1', 'database', 'Database', 'database ', $long, "\xff", 'disk', 'disk'],
            [true, false, true, false, true, true, false, true, false, true, false, true],
            [PHP_INT_MIN, PHP_INT_MAX, 0, -1, 999, 1000, 1001, 0, 0, 0, 5, PHP_INT_MAX],
        ]];
        yield 'probe all failed' => [RuntimeProbeRunner::class, [['redis', 'redis'], [false, true], [1, 2]]];
        yield 'path empty' => [VerificationPlanner::class, [[]]];
        yield 'path bounds' => [VerificationPlanner::class, [[
            '', '/', '//', 'a/', 'a//b//', '.php', 'Test.php', 'TestTest.php', 'Foo.PHP', "Foo.php\n", "Foo.php\n\n", "FooTest.php\n",
            'foo.blade.php', 'resources/views', 'resources/views/', 'resources/jsx/a.js', 'app/Mcp', 'app/Mcp/', 'app/services/Mcp/X.php',
            'config/nntmux-mcp.php ', 'tests/Test.php', 'tests/', 'tests/a.Test.php', 'composer.json/', '0', '0.php', '00.php', '0Test.php',
            "\xff/\xfe.php", 'ünïcode/Fïle.php', 'a/'.$long.'.php', $long, 'vite.config.js', 'package.json', 'routes/ai.php',
        ]]];
        yield 'path repeats' => [VerificationPlanner::class, [['a/Foo.php', 'b/FooTest.php', 'c/Foo.php', 'Foo', "Foo\n", "Foo.php\n", '1.php', '01.php', '1']]];
        yield 'result empty' => [VerificationRunner::class, [[], [], []]];
        yield 'result bounds' => [VerificationRunner::class, [
            ['', "\n", "a\n", "a\n\n", 'a b', 'Tests\\Unit\\FooTest::test_x', 'php-lint', 'diff', 'diff-', 'Pint', $long, "\xff", 'a-z.0:9_\\'],
            ['passed', 'Passed', 'passed ', '', 'skipped', 'failed', 'timed_out', 'timedout', 'running', 'passed', 'skipped', 'failed', 'passed'],
            [true, false, true, false, true, false, true, false, true, false, true, false, true],
        ]];
        yield 'result all passed' => [VerificationRunner::class, [['pint', 'phpstan'], ['passed', 'skipped'], [false, false]]];
        yield 'status empty' => [DatabaseFingerprintCollector::class, [[], []]];
        yield 'status bounds' => [DatabaseFingerprintCollector::class, [
            ['', '0', '00', '1', '-1', 'pending', 'Pending', 'pending ', $long, "\xff", 'failed', 'failed', '9223372036854775807', '9223372036854775808'],
            [PHP_INT_MIN, PHP_INT_MAX, 0, -1, 1, 2, 3, 4, 5, 6, 7, -8, 9, 10],
        ]];
        yield 'status all zero' => [DatabaseFingerprintCollector::class, [['pending', 'complete'], [0, 0]]];
    }

    /**
     * Paired randomized benchmark: per class and workload, each pair times the PHP and Rust
     * batch entry point on one randomly chosen fixture, alternating which runs first; each side
     * keeps the fastest of REPEATS back-to-back calls. Common batches hold 64 typical records,
     * Uncommon batches 128-256 long, malformed or adversarial ones. Each of ROUNDS rounds times
     * PAIRS pairs after WARMUPS warmups; a metric is the median of its round values (nearest-rank
     * p99 per round). Every pair must agree; raw pairs are written outside the checkout. A class
     * must default to Rust exactly when all four metrics reach 1.5x.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(54));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $metrics = [];
            foreach (['Common', 'Uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
                $rounds = [];
                for ($round = 0; $round < self::ROUNDS; $round++) {
                    $php = $rust = [];
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $args = $fixtures[$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW54RustFfi($native, fn (): array => $this->timed($class, $args));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $php[] = $elapsed[0];
                            $rust[] = $elapsed[1];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                    sort($php);
                    sort($rust);
                    $p99 = (int) ceil(\count($php) * 0.99) - 1;
                    $rounds[] = [
                        'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                        'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                        'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                    ];
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => $this->median(array_column($rounds, 'mean_speedup')), 'p99_speedup' => $this->median(array_column($rounds, 'p99_speedup')),
                    'php_mean_ns' => $this->median(array_column($rounds, 'php_mean_ns')), 'rust_mean_ns' => $this->median(array_column($rounds, 'rust_mean_ns')),
                    'php_p99_ns' => $this->median(array_column($rounds, 'php_p99_ns')), 'rust_p99_ns' => $this->median(array_column($rounds, 'rust_p99_ns')),
                    'rounds' => $rounds,
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w542-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => 54, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW54.2 paired benchmark artifact: {$artifact}\n".json_encode(array_map(static fn (array $class): array => array_map(static fn (mixed $w): mixed => \is_array($w) ? array_diff_key($w, ['rounds' => true]) : $w, $class), $summary), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW54RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $args): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $args);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  array<int|float>  $values */
    private function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)] ?? 0;
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W54_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $args): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix](...$args);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): void
    {
        $php = $class::withW54RustFfi(false, fn (): array => $this->call($class, '', $args));
        $rust = $class::withW54RustFfi(true, fn (): array => $this->call($class, '', $args));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $args), $class);
        self::assertSame($php, $this->call($class, 'Native', $args), $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * Documented words (without the digest) from both implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<mixed>  $args
     * @param  list<int>  $words
     */
    private function assertWords(string $class, array $args, array $words): void
    {
        foreach (['Php', 'Native'] as $suffix) {
            self::assertSame($words, \array_slice($this->call($class, $suffix, $args), 0, -1), $class.'::'.$suffix);
        }
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function assertRandomParity(string $class, int $seed): void
    {
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity($class, $this->fixture($class, new Randomizer(new Mt19937($seed + $i)), $uncommon));
            }
        }
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w54RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w54 binding');

        return $ffi;
    }

    /** Each Wave 54.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            ProcessRunner::class => [['a', 1, pack('P*', 1, 5), 16, 1], 8],
            RuntimeProbeRunner::class => [['a', 1, pack('P*', 1, 5), 16, 1], 8],
            VerificationPlanner::class => [['a', 1, 1], 9],
            VerificationRunner::class => [['a', 1, 'passed', 6, pack('P', 0), 8, 1], 8],
            DatabaseFingerprintCollector::class => [['a', 1, pack('P', 3), 8, 1], 10],
        ];
        foreach ($calls as $class => [$args, $words]) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame($words, $this->invoke($ffi, self::SYMBOLS[$class], [...$args, $out, 32]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W54_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<mixed>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            ProcessRunner::class => $this->outputBatch($random, $uncommon),
            RuntimeProbeRunner::class => $this->probeBatch($random, $uncommon),
            VerificationPlanner::class => [$this->pathBatch($random, $uncommon)],
            VerificationRunner::class => $this->resultBatch($random, $uncommon),
            DatabaseFingerprintCollector::class => $this->statusBatch($random, $uncommon),
        };
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789._-/', $length);
    }

    private function batchSize(Randomizer $random, bool $uncommon): int
    {
        return $uncommon ? $random->getInt(128, 256) : 64;
    }

    /** Process output lines in the shapes PHPUnit, Pint, PHPStan, php -l and npm print. */
    private function outputLine(Randomizer $random): string
    {
        return match ($random->getInt(0, 5)) {
            0 => 'PHPUnit 12.5.0 by Sebastian Bergmann and contributors. Runtime: PHP 8.5.12',
            1 => 'No syntax errors detected in app/Services/Mcp/'.$this->noise($random, 24).'.php',
            2 => ' '.$random->getInt(1, 400).'/'.$random->getInt(400, 900).' [▓▓▓▓▓▓░░░░░░] '.$random->getInt(0, 100).'%',
            3 => '  Line '.$random->getInt(1, 900).'  Parameter #1 $value of function strlen expects string, int given.',
            4 => '✓ '.$this->noise($random, 40).' ('.$random->getInt(1, 999).' ms)',
            default => 'vite v7.0.0 building for production... '.$this->noise($random, 30),
        };
    }

    /** @return array{list<string>, list<int>} */
    private function outputBatch(Randomizer $random, bool $uncommon): array
    {
        $outputs = $maximums = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $lines = [];
            for ($l = $random->getInt(1, $uncommon ? 60 : 12); $l > 0; $l--) {
                $lines[] = $this->outputLine($random);
            }
            $output = implode("\n", $lines);
            $maximum = $random->getInt(0, 3) === 0 ? $random->getInt(64, 512) : 32_768;
            if ($uncommon) {
                $output = match ($random->getInt(0, 7)) {
                    0 => '',
                    1 => $this->noise($random, 600, true),
                    2 => $output."\0".$this->noise($random, 80, true),
                    3 => str_repeat("\xC3\xA9\xE2\x82\xAC\xF0\x9F\x98\x80", $random->getInt(10, 200)),
                    4 => $output."\xC0\xAF\xED\xA0\x80".$output,
                    default => $output,
                };
                $maximum = match ($random->getInt(0, 5)) {
                    0 => $this->pick($random, [0, -1, 1, 2, 3, PHP_INT_MIN, PHP_INT_MAX]),
                    1 => max(1, \strlen($output) - $random->getInt(0, 3)),
                    2 => $random->getInt(1, 64),
                    default => $maximum,
                };
            }
            $outputs[] = $output;
            $maximums[] = $maximum;
        }

        return [$outputs, $maximums];
    }

    /** @return array{list<string>, list<bool>, list<int>} */
    private function probeBatch(Randomizer $random, bool $uncommon): array
    {
        $ids = $oks = $latencies = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $id = $this->pick($random, self::PROBE_IDS);
            $latency = $random->getInt(0, 40) === 0 ? $random->getInt(1000, 5000) : $random->getInt(0, 250);
            if ($uncommon) {
                $id = match ($random->getInt(0, 6)) {
                    0 => '',
                    1 => $this->noise($random, 40),
                    2 => $this->noise($random, 24, true),
                    3 => (string) $random->getInt(-5, 99),
                    4 => strtoupper($id),
                    default => $id,
                };
                $latency = $random->getInt(0, 5) === 0 ? $this->pick($random, [PHP_INT_MIN, PHP_INT_MAX, -1, 999, 1000]) : $latency;
            }
            $ids[] = $id;
            $oks[] = $random->getInt(0, $uncommon ? 2 : 12) !== 0;
            $latencies[] = $latency;
        }

        return [$ids, $oks, $latencies];
    }

    /** @return list<string> */
    private function pathBatch(Randomizer $random, bool $uncommon): array
    {
        $paths = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $path = $this->pick($random, self::PATHS);
            if ($random->getInt(0, 2) === 0) {
                $path = $this->pick($random, ['app/Services/', 'tests/Unit/', 'resources/views/', 'app/Mcp/Tools/']).ucfirst($this->noise($random, 20)).$this->pick($random, ['.php', 'Test.php', '.blade.php', '.js', '']);
            }
            if ($uncommon) {
                $path = match ($random->getInt(0, 9)) {
                    0 => '',
                    1 => $path.'/',
                    2 => $path."\n",
                    3 => $this->noise($random, 300),
                    4 => $this->noise($random, 40, true),
                    5 => $this->pick($random, ['0.php', 'Test.php', 'TestTest.php', '.php', '/', '0', 'x.PHP', 'a//']),
                    6 => str_repeat('deep/', $random->getInt(10, 80)).$path,
                    default => $path,
                };
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** @return array{list<string>, list<string>, list<bool>} */
    private function resultBatch(Randomizer $random, bool $uncommon): array
    {
        $names = $statuses = $truncated = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $name = $this->pick($random, self::CHECK_NAMES);
            $status = $random->getInt(0, 8) === 0 ? $this->pick($random, ['failed', 'skipped', 'timed_out']) : 'passed';
            if ($uncommon) {
                $name = match ($random->getInt(0, 7)) {
                    0 => '',
                    1 => $this->noise($random, 60),
                    2 => $this->noise($random, 30, true),
                    3 => $name."\n",
                    4 => 'Tests\\Unit\\'.ucfirst($this->noise($random, 30)).'Test::test_'.$this->noise($random, 20),
                    default => $name,
                };
                $status = match ($random->getInt(0, 5)) {
                    0 => $this->pick($random, ['', 'Passed', 'passed ', 'running', 'timedout']),
                    1 => $this->noise($random, 12),
                    default => $status,
                };
            }
            $names[] = $name;
            $statuses[] = $status;
            $truncated[] = $random->getInt(0, $uncommon ? 3 : 20) === 0;
        }

        return [$names, $statuses, $truncated];
    }

    /** @return array{list<string>, list<int>} */
    private function statusBatch(Randomizer $random, bool $uncommon): array
    {
        $statuses = $totals = [];
        for ($i = $this->batchSize($random, $uncommon); $i > 0; $i--) {
            $status = $random->getInt(0, 15) === 0 ? $this->pick($random, ['cancelled', 'retrying', 'stale']) : $this->pick($random, self::JOB_STATUSES);
            $total = $random->getInt(0, 250_000);
            if ($uncommon) {
                $status = match ($random->getInt(0, 6)) {
                    0 => '',
                    1 => $this->noise($random, 40),
                    2 => $this->noise($random, 20, true),
                    3 => (string) $random->getInt(-9, 99),
                    4 => strtoupper($status),
                    default => $status,
                };
                $total = $random->getInt(0, 4) === 0 ? $this->pick($random, [PHP_INT_MIN, PHP_INT_MAX, -1, 0]) : $total;
            }
            $statuses[] = $status;
            $totals[] = $total;
        }

        return [$statuses, $totals];
    }
}
