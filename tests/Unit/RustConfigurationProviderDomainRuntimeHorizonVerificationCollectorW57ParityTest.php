<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\DomainConfigurationRepository;
use App\Services\Configuration\ProcessingRuntimeStateRepository;
use App\Services\Monitoring\Collectors\HorizonCollector;
use App\Services\Monitoring\Collectors\ReleaseVerificationCollector;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustConfigurationProviderDomainRuntimeHorizonVerificationCollectorW57ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ConfigurationProvider::class => 'valueCodesBatch',
        DomainConfigurationRepository::class => 'settingDiffBatch',
        ProcessingRuntimeStateRepository::class => 'runtimeStateBatch',
        HorizonCollector::class => 'workloadBatch',
        ReleaseVerificationCollector::class => 'latencyBatch',
    ];

    /** Output words per row (per stored/submitted pair for DomainConfigurationRepository). */
    private const WORDS_PER_ROW = [
        ConfigurationProvider::class => 2,
        DomainConfigurationRepository::class => 2,
        ProcessingRuntimeStateRepository::class => 3,
        HorizonCollector::class => 5,
        ReleaseVerificationCollector::class => 8,
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns) from
     * test_paired_randomized_common_and_uncommon_benchmarks (NNTMUX_W571_BENCH=1): seed 57,
     * ROUNDS interleaved pairs per workload after WARMUPS, ROWS rows per batch, randomized call
     * order, each side the fastest of REPEATS back-to-back calls, release Rust, PHP 8.5 CLI
     * without opcache, Apple Silicon macOS. Timings include dispatch, implode(), the FFI call and
     * unpack('P*'). Per-metric minimum of four runs. A class defaults to Rust only when all four
     * reach 1.5x.
     */
    private const QUALIFICATION = [
        ConfigurationProvider::class => [4.629, 4.44, 2.844, 1.975],
        DomainConfigurationRepository::class => [2.991, 1.735, 2.656, 2.428],
        ProcessingRuntimeStateRepository::class => [5.551, 5.39, 4.859, 2.781],
        HorizonCollector::class => [4.693, 4.512, 3.606, 3.342],
        ReleaseVerificationCollector::class => [8.649, 8.797, 7.915, 5.502],
    ];

    private const ROUNDS = 60;

    private const WARMUPS = 8;

    private const ROWS = 320;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 57;

    private const WORDS = ['alpha', 'Bravo', 'charlie', 'DELTA', 'echo', 'Foxtrot', 'golf', 'hotel', 'India', 'juliet', 'kilo', 'Lima', 'mike', 'November'];

    private const QUEUES = ['default', 'high', 'low', 'releases', 'release-fingerprint', 'notifications', 'emails', 'backfill', 'postprocess:tv', 'nzb.import'];

    private const STATES = ['running', 'paused', 'draining', 'drained', 'stopped'];

    private const SCALARS = ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'];

    private const DOMAINS = ['site', 'registration', 'ingestion', 'post-processing', 'metadata', 'tmux'];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW57RustFfi', 'withW57RustFfi', 'w57RustFfiState', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW57RustFfi', 'withW57RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('w57RustFfiState')->isPrivate(), $class);
            $constant = $reflection->getReflectionConstant('W57_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w571_', (string) $reflection->getConstant('W57_FFI_CDEF'));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w57', $property->getName(), $class.' keeps Wave 57 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
            if (min($speedups) < 1.5) {
                self::assertFalse($this->uses($class), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_w57_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW57RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW57RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW57RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
    }

    public function test_toggle_routes_entry_point(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $args = $this->args($class, ['tmux_running=1;state=running', "default\t1\t2\t3\t0", '10,20', 'Yes']);
            $call = $this->entry($class);
            $php = $class::{self::ENTRIES[$class].'Php'}(...$args);
            self::assertSame($php, $class::withW57RustFfi(true, static fn (): mixed => $call(...$args)), $class);
            self::assertSame($php, $class::withW57RustFfi(false, static fn (): mixed => $call(...$args)), $class);
        }
    }

    public function test_earlier_toggles_are_untouched(): void
    {
        self::assertFalse(ConfigurationProvider::usesRustFfi());
        self::assertFalse(DomainConfigurationRepository::usesRustFfi());
        self::assertFalse(ProcessingRuntimeStateRepository::usesRustFfi());
        self::assertFalse(HorizonCollector::usesRustFfi());
        self::assertFalse(ReleaseVerificationCollector::usesRustFfi());
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    /**
     * @return iterable<string, array{0: class-string, 1: list<string>, 2: list<int>}>
     */
    public static function knownCodes(): iterable
    {
        $value = static fn (int $flags, int $length): int => $flags | $length << ConfigurationProvider::W57_VALUE_LENGTH_SHIFT;
        yield 'value padded int' => [ConfigurationProvider::class, ['  42 '], [$value(ConfigurationProvider::W57_VALUE_INT | ConfigurationProvider::W57_VALUE_TRIMMED, 2), 42]];
        yield 'value signed int' => [ConfigurationProvider::class, ['-007'], [$value(ConfigurationProvider::W57_VALUE_INT, 4), -7]];
        yield 'value nineteen digits' => [ConfigurationProvider::class, ['1234567890123456789'], [$value(ConfigurationProvider::W57_VALUE_STRING, 19), 0]];
        yield 'value float' => [ConfigurationProvider::class, ['-12.75'], [$value(ConfigurationProvider::W57_VALUE_FLOAT, 6), -12]];
        yield 'value bare fraction' => [ConfigurationProvider::class, ['.5'], [$value(ConfigurationProvider::W57_VALUE_FLOAT, 2), 0]];
        yield 'value off' => [ConfigurationProvider::class, ['off'], [$value(ConfigurationProvider::W57_VALUE_FALSE | ConfigurationProvider::W57_VALUE_KEY, 3), 0]];
        yield 'value YES' => [ConfigurationProvider::class, ['YES'], [$value(ConfigurationProvider::W57_VALUE_TRUE, 3), 1]];
        yield 'value json' => [ConfigurationProvider::class, ['{"a":1}'], [$value(ConfigurationProvider::W57_VALUE_JSON, 7), 0]];
        yield 'value domain' => [ConfigurationProvider::class, ['Post-Processing'], [$value(ConfigurationProvider::W57_VALUE_STRING | 4 << ConfigurationProvider::W57_VALUE_DOMAIN_SHIFT, 15), 0]];
        yield 'value dotted key' => [ConfigurationProvider::class, ['site.title_2'], [$value(ConfigurationProvider::W57_VALUE_STRING | ConfigurationProvider::W57_VALUE_KEY, 12), 0]];
        yield 'value non ascii' => [ConfigurationProvider::class, ["caf\u{e9}"], [$value(ConfigurationProvider::W57_VALUE_STRING | ConfigurationProvider::W57_VALUE_NON_ASCII, 5), 0]];
        yield 'value empty' => [ConfigurationProvider::class, [''], [0, 0]];

        yield 'diff numeric' => [DomainConfigurationRepository::class, ['007', '7 '], [DomainConfigurationRepository::W57_DIFF_CHANGED | DomainConfigurationRepository::W57_DIFF_NUMERIC_EQUAL | 2 << DomainConfigurationRepository::W57_DIFF_LENGTH_SHIFT, crc32('7 ')]];
        yield 'diff cleared' => [DomainConfigurationRepository::class, ['x', ''], [DomainConfigurationRepository::W57_DIFF_CHANGED | DomainConfigurationRepository::W57_DIFF_CLEARED, 0]];
        yield 'diff added json' => [DomainConfigurationRepository::class, ['', "[1]\n"], [DomainConfigurationRepository::W57_DIFF_CHANGED | DomainConfigurationRepository::W57_DIFF_ADDED | DomainConfigurationRepository::W57_DIFF_JSON | DomainConfigurationRepository::W57_DIFF_MULTILINE | 4 << DomainConfigurationRepository::W57_DIFF_LENGTH_SHIFT, crc32("[1]\n")]];
        yield 'diff same' => [DomainConfigurationRepository::class, ['abc', 'abc'], [DomainConfigurationRepository::W57_DIFF_TRIM_EQUAL | DomainConfigurationRepository::W57_DIFF_CASE_EQUAL | 3 << DomainConfigurationRepository::W57_DIFF_LENGTH_SHIFT, crc32('abc')]];

        yield 'state allowed' => [ProcessingRuntimeStateRepository::class, ['tmux_running=1;stop_requested=0;state=running;next=paused;pending=12;active=3'], [1 | 1 << 4 | 2 << 8 | 1 << 12, 12, 3]];
        yield 'state rejected conflict' => [ProcessingRuntimeStateRepository::class, [' state = DRAINED ; next=draining;tmux_running=yes'], [1 | 4 << 4 | 3 << 8 | 2 << 12 | ProcessingRuntimeStateRepository::W57_STATE_CONFLICT, -1, -1]];
        yield 'state invalid unknown' => [ProcessingRuntimeStateRepository::class, ['state=bogus;next=running;pending=x;foo'], [4 | 8 | 1 << 8 | 2 << 12, -1, -1]];
        yield 'state noop' => [ProcessingRuntimeStateRepository::class, ['state=stopped;next=stopped;stop_requested=on'], [2 | 5 << 4 | 5 << 8 | 3 << 12, -1, -1]];
        yield 'state empty' => [ProcessingRuntimeStateRepository::class, [''], [0, -1, -1]];

        $named = static fn (int $flags, int $length): int => $flags | HorizonCollector::W57_WORKLOAD_NAME_VALID | $length << HorizonCollector::W57_WORKLOAD_NAME_SHIFT;
        yield 'workload healthy' => [HorizonCollector::class, ["default\t12\t3\t4\t0"], [$named(HorizonCollector::W57_WORKLOAD_HEALTHY, 7), 12, 3, 4, 0]];
        yield 'workload skipped backlog' => [HorizonCollector::class, ["release-fingerprint\t5\t90\t1\t2"], [$named(HorizonCollector::W57_WORKLOAD_BACKLOGGED | HorizonCollector::W57_WORKLOAD_SKIP | HorizonCollector::W57_WORKLOAD_FAILING, 19), 5, 90, 1, 2]];
        yield 'workload stalled' => [HorizonCollector::class, ["q a\t5\t0\t0\t0"], [HorizonCollector::W57_WORKLOAD_STALLED | 3 << HorizonCollector::W57_WORKLOAD_NAME_SHIFT, 5, 0, 0, 0]];
        yield 'workload short' => [HorizonCollector::class, ["q\t5\t-1\t0"], [$named(HorizonCollector::W57_WORKLOAD_INVALID, 1), -1, -1, -1, -1]];

        yield 'latency mixed' => [ReleaseVerificationCollector::class, ['300, 50 ,,x,20000'], [ReleaseVerificationCollector::W57_HEALTH_CRITICAL | ReleaseVerificationCollector::W57_LATENCY_INVALID | 1 << 8, 3, 20350, 300, 20000, 20000, 20000, 1 | 1 << 16 | 1 << 56]];
        yield 'latency healthy' => [ReleaseVerificationCollector::class, ['10,20,30,40'], [ReleaseVerificationCollector::W57_HEALTH_HEALTHY, 4, 100, 20, 40, 40, 40, 4]];
        yield 'latency empty' => [ReleaseVerificationCollector::class, [''], [0, 0, 0, -1, -1, -1, -1, 0]];
        yield 'latency saturated top bucket' => [ReleaseVerificationCollector::class, [implode(',', array_fill(0, 300, '20000'))], [ReleaseVerificationCollector::W57_HEALTH_CRITICAL, 300, 6_000_000, 20000, 20000, 20000, 20000, 255 << 56]];
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $row
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCodes')]
    public function test_known_codes(string $class, array $row, array $expected): void
    {
        $args = $class === DomainConfigurationRepository::class ? [[$row[0]], [$row[1]]] : $this->args($class, $row);
        self::assertSame($expected, $this->assertParity($class, $args));
    }

    /**
     * @return iterable<string, array{0: class-string, 1: array<array-key, string>}>
     */
    public static function edgeBatches(): iterable
    {
        $rows = ['', ' ', "\t\n", "\n", "\r\n", "\x0b", "\x0c", ',', ';', '=', "\t", "\t\t\t\t", ',,,', ';;;', '==', 'a=', '=b',
            '0', '1', '-0', '+0', '-1', '+1', '00', '007', '123456789012345678', '1234567890123456789', '-123456789012345678', '999999999', '1000000000',
            '.', '-', '+', '+.', '-.', '.5', '5.', '-.5', '+5.', '1.2.3', '1e5', '0x1A', '123456789012345.123456789012345', '1234567890123456.5', '1.1234567890123456',
            'true', 'TRUE', 'True', 'false', 'yes', 'No', 'ON', 'off', ' on ', 'tru', 'yess', '{}', '[]', '{', '}', '[', ']', '{]', '[}', ' {"a":[1]} ', '[1,2,3]',
            'site', 'SITE', ' tmux ', 'post-processing', 'Post-Processing', 'post_processing', 'metadata ', 'registrations', 'a.b', 'a..b', '.a', 'a.', 'a_b.c9_d', 'A.b', '9a.b',
            "caf\u{e9}", "\xc3\x28", "\xff", "\u{a0}x", "\u{2014}",
            'tmux_running=1;stop_requested=0;state=running', 'state=RUNNING;next=Paused', 'state=drained;next=stopped;tmux_running=0', 'state=stopped;next=running',
            'state=paused;next=paused', 'state=;next=', 'tmux_running=maybe', 'stop_requested=', 'pending=12;active=', 'pending=1234567890123456789', 'pending= 12 ;active=\t3',
            'tmux_running=1;tmux_running=0', 'state=running;state=bogus', 'foo=bar', 'novalue', ' ; ; ', 'next=draining', 'state = draining ; next = drained ; tmux_running = on',
            "default\t0\t0\t0\t0", "default\t1\t2\t3\t4", "release-fingerprint\t1\t1\t1\t0", "high\t5\t60\t1\t0", "high\t5\t59\t1\t0", "low\t5\t0\t0\t9", "\t1\t1\t1\t1",
            "q\t1\t1\t1", "q\t1\t1\t1\t1\t1", "q\t-1\t1\t1\t1", "q\t1.5\t1\t1\t1", "q\t\t1\t1\t1", "q\t1234567890123456789\t1\t1\t1", "q q\t1\t1\t1\t1", "q\u{e9}\t1\t1\t1\t1",
            '10,20,30', ' 10 , 20 ', '100,250,500,1000,2500,5000,10000,10001', '101,251,501,1001,2501,5001', '0', '999999999', '1000000000', '-5', '1.5', 'x,y,z', '5000,5000',
            '1000,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1,1', "1\n,2", "1\t,\t2"];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' mixed edges' => [$class, $rows];
            yield $class.' single empty row' => [$class, ['']];
            yield $class.' empty batch' => [$class, []];
            yield $class.' keyed rows' => [$class, ['k' => 'state=running', 7 => "default\t1\t1\t1\t0", 'z' => '1,2,3', 'q' => 'Yes']];
        }
    }

    /**
     * @param  class-string  $class
     * @param  array<array-key, string>  $rows
     */
    #[DataProvider('edgeBatches')]
    public function test_edge_batch_parity(string $class, array $rows): void
    {
        $result = $this->assertParity($class, $this->args($class, $rows));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result), $class);
        self::assertCount(\count($rows) * self::WORDS_PER_ROW[$class], $result, $class);
    }

    public function test_rows_holding_nul_fall_back_to_php(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $rows = ["a\0b", 'state=running', "\0", "default\t1\t1\t1\t0"];
            self::assertCount(4 * self::WORDS_PER_ROW[$class], $this->assertParity($class, $this->args($class, $rows)), $class);
        }
        self::assertCount(2, $this->assertParity(DomainConfigurationRepository::class, [['a'], ["b\0"]]));
    }

    public function test_horizon_worker_variants_match(): void
    {
        $rows = ["release-fingerprint\t1\t2\t3\t0", "default\t1\t2\t3\t0", "Release-Fingerprint\t0\t0\t0\t0"];
        foreach (['rust', 'php', '', 'Rust', 'rust ', "rust\0", 'horizon'] as $worker) {
            $words = $this->assertParity(HorizonCollector::class, [$rows, $worker]);
            self::assertIsArray($words);
            self::assertSame($worker === 'rust', ($words[0] & HorizonCollector::W57_WORKLOAD_SKIP) !== 0, $worker);
            self::assertSame(0, $words[5] & HorizonCollector::W57_WORKLOAD_SKIP, $worker);
        }
    }

    public function test_setting_diff_rejects_unpaired_lists(): void
    {
        foreach ([false, true] as $native) {
            try {
                DomainConfigurationRepository::withW57RustFfi($native, static fn (): array => DomainConfigurationRepository::settingDiffBatch(['a', 'b'], ['a']));
                self::fail('Unpaired settings must be rejected');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Stored and submitted settings must pair up.', $exception->getMessage());
            }
        }
        self::assertSame([], DomainConfigurationRepository::settingDiffBatchNative([], []));
    }

    /** At least 300 randomized rows per class and workload, checked as batches and one row at a time. */
    public function test_randomized_dataset_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $args = $this->fixture($class, $random, $uncommon, 320);
                $words = $this->assertParity($class, $args);
                self::assertIsArray($words);
                self::assertCount(320 * self::WORDS_PER_ROW[$class], $words, $class);
                for ($i = 0; $i < 40; $i++) {
                    $this->assertParity($class, array_map(static fn (mixed $arg): mixed => \is_array($arg) ? [$arg[$i]] : $arg, $args));
                }
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W571_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W571_BENCH=1 to run the Wave 57.1 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(self::SEED));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $call = $this->entry($class);
            foreach (['common', 'uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'uncommon', self::ROWS);
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::ROUNDS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $args = $fixtures[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW57RustFfi($native, static function () use ($call, $args): array {
                            $result = null;
                            $best = PHP_INT_MAX;
                            for ($r = 0; $r < self::REPEATS; $r++) {
                                $start = hrtime(true);
                                $result = $call(...$args);
                                $best = min($best, hrtime(true) - $start);
                            }

                            return [$result, $best];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0], $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(\count($php) * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / max(1, array_sum($rust)), 3),
                    'p99_speedup' => round($php[$p99] / max(1, $rust[$p99]), 3),
                    'php_mean_ns' => intdiv(array_sum($php), \count($php)), 'rust_mean_ns' => intdiv(array_sum($rust), \count($rust)),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $artifact = sys_get_temp_dir().'/w571-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'warmups' => self::WARMUPS, 'rows' => self::ROWS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW57.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW57RustFfi'];

        return $uses();
    }

    private function entry(string $class): Closure
    {
        /** @var callable $entry */
        $entry = [$class, self::ENTRIES[$class]];

        return Closure::fromCallable($entry);
    }

    /**
     * Entry-point arguments for `$rows`; DomainConfigurationRepository pairs each row with an
     * equal, re-cased, padded or neighbouring submitted value.
     *
     * @param  array<array-key, string>  $rows
     * @return list<mixed>
     */
    private function args(string $class, array $rows): array
    {
        if ($class === DomainConfigurationRepository::class) {
            $values = array_values($rows);
            $submitted = [];
            foreach ($values as $i => $row) {
                $submitted[] = match ($i % 4) {
                    0 => $row,
                    1 => strtoupper($row),
                    2 => ' '.$row,
                    default => $values[($i + 1) % \count($values)],
                };
            }

            return [$rows, $submitted];
        }

        return $class === HorizonCollector::class ? [$rows, 'rust'] : [$rows];
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): mixed
    {
        $call = $this->entry($class);
        $php = $class::withW57RustFfi(false, static fn (): mixed => $call(...$args));
        $native = $class::withW57RustFfi(true, static fn (): mixed => $call(...$args));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);

        return $php;
    }

    /** @return list<mixed> */
    private function fixture(string $class, Randomizer $random, bool $uncommon, int $count): array
    {
        if ($class === DomainConfigurationRepository::class) {
            $stored = $submitted = [];
            for ($i = 0; $i < $count; $i++) {
                [$stored[], $submitted[]] = $this->settingPair($random, $uncommon);
            }

            return [$stored, $submitted];
        }
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = match ($class) {
                ConfigurationProvider::class => $this->configValue($random, $uncommon),
                ProcessingRuntimeStateRepository::class => $this->stateRecord($random, $uncommon),
                HorizonCollector::class => $this->workloadRow($random, $uncommon),
                default => $this->latencyWindow($random, $uncommon),
            };
        }

        return $class === HorizonCollector::class ? [$rows, $uncommon && $random->getInt(0, 3) === 0 ? 'php' : 'rust'] : [$rows];
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            ConfigurationProvider::class => ['nntmux_w571_config_value_batch', ["a\0b", 3, 2], [], 2],
            DomainConfigurationRepository::class => ['nntmux_w571_domain_setting_diff_batch', ["a\0b\0c\0d", 7, 2], [], 2],
            ProcessingRuntimeStateRepository::class => ['nntmux_w571_runtime_state_batch', ["a=1\0b", 5, 2], [], 3],
            HorizonCollector::class => ['nntmux_w571_horizon_workload_batch', ["q\t1\t2\t3\t0\0x", 11, 2], ['rust', 4], 5],
            ReleaseVerificationCollector::class => ['nntmux_w571_verification_latency_batch', ["1,2\0x", 5, 2], [], 8],
        ];
        foreach ($calls as $class => [$symbol, $args, $extra, $perRow]) {
            $buffer = (new ReflectionMethod($class, 'w57RustFfiState'))->invoke(null, 'out', 4);
            self::assertIsArray($buffer, $class.' must load its Wave 57 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame(2, $this->invoke($ffi, $symbol, [...$args, ...$extra, $out, 32]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, ...$extra, $out, 2 * $perRow - 1]), $symbol.' short capacity');
            $miscounted = $args;
            $miscounted[2] = 3;
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$miscounted, ...$extra, $out, 32]), $symbol.' row count mismatch');
        }
    }

    /**
     * Calls an export declared by a class's W57_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /**
     * @param  list<string>  $values
     */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private function words(Randomizer $random, int $min, int $max, string $glue = '_'): string
    {
        $words = [];
        $count = $random->getInt($min, $max);
        for ($i = 0; $i < $count; $i++) {
            $words[] = $this->pick($random, self::WORDS);
        }

        return implode($glue, $words);
    }

    private function configValue(Randomizer $random, bool $uncommon): string
    {
        if (! $uncommon) {
            return match ($random->getInt(0, 7)) {
                0 => (string) $random->getInt(0, 100_000),
                1 => $this->pick($random, self::SCALARS),
                2 => sprintf('%d.%02d', $random->getInt(0, 999), $random->getInt(0, 99)),
                3 => $this->pick($random, self::DOMAINS),
                4 => 'nntmux.'.strtolower($this->words($random, 1, 3, '.')).'_'.$random->getInt(1, 9),
                5 => 'https://'.strtolower($this->words($random, 1, 2, '-')).'.example.com/api',
                6 => '{"'.strtolower($this->pick($random, self::WORDS)).'":'.$random->getInt(0, 999).'}',
                default => $this->words($random, 1, 5, ' '),
            };
        }

        return match ($random->getInt(0, 9)) {
            0 => "\t ".$this->configValue($random, false)." \n",
            1 => str_repeat($this->words($random, 3, 8, ' ').' ', $random->getInt(3, 12)),
            2 => "caf\u{e9} ".$this->words($random, 2, 6, " \u{2014} "),
            3 => ($random->getInt(0, 1) === 0 ? '-' : '+').str_repeat((string) $random->getInt(1, 9), $random->getInt(16, 25)),
            4 => $this->pick($random, ['+.5', '-0.', '1.2.3', '1e5', '.', '-', '+', '0x1F', '1234567890123456.5', '12.1234567890123456']),
            5 => strtoupper($this->pick($random, [...self::SCALARS, ...self::DOMAINS])),
            6 => '['.implode(',', array_map(static fn (int $n): string => (string) $n, range(1, $random->getInt(1, 40)))).']',
            7 => $this->pick($random, ['[', '{]', '}', '{"a":', ' [ ] ']),
            8 => strtolower($this->words($random, 3, 12, '.')),
            default => str_repeat(' ', $random->getInt(0, 3)).sprintf('%s%d.%d', $random->getInt(0, 1) === 0 ? '-' : '', $random->getInt(0, 999_999_999), $random->getInt(0, 999_999)),
        };
    }

    /** @return array{0: string, 1: string} */
    private function settingPair(Randomizer $random, bool $uncommon): array
    {
        if (! $uncommon) {
            $before = $this->configValue($random, false);
            $after = match ($random->getInt(0, 19)) {
                0, 1, 2 => $this->configValue($random, false),
                3, 4 => strtoupper($before),
                5, 6 => ctype_digit($before) ? '00'.$before : $before.' ',
                default => $before,
            };

            return [$before, $after];
        }
        $text = implode("\n", array_map(fn (): string => $this->words($random, 4, 12, ' '), range(1, $random->getInt(2, 12))));

        return match ($random->getInt(0, 5)) {
            0 => [$text, $text."\n".$this->words($random, 2, 6, ' ')],
            1 => ['', $text],
            2 => [$text, ''],
            3 => ['{"rules":['.$random->getInt(0, 99).']}', ' {"rules":['.$random->getInt(0, 99).',"'.$this->words($random, 1, 3).'"]} '],
            4 => [" \t".$random->getInt(0, 9999)."\r\n", '+'.$random->getInt(0, 9999)],
            default => ["caf\u{e9} ".$text, "CAF\u{e9} ".strtoupper($text)],
        };
    }

    private function stateRecord(Randomizer $random, bool $uncommon): string
    {
        $segments = [
            'tmux_running='.$random->getInt(0, 1),
            'stop_requested='.$random->getInt(0, 1),
            'state='.$this->pick($random, self::STATES),
        ];
        if ($random->getInt(0, 1) === 0) {
            $segments[] = 'next='.$this->pick($random, self::STATES);
        }
        $segments[] = 'pending='.$random->getInt(0, 50_000);
        $segments[] = 'active='.$random->getInt(0, 64);
        if (! $uncommon) {
            return implode(';', $segments);
        }
        for ($i = $random->getInt(4, 24); $i > 0; $i--) {
            $segments[] = match ($random->getInt(0, 8)) {
                0 => ' '.$this->pick($random, ['tmux_running', 'stop_requested']).' = '.strtoupper($this->pick($random, self::SCALARS))."\t",
                1 => 'state='.$this->pick($random, ['RUNNING', 'Paused', 'bogus', '', 'drain']),
                2 => 'next = '.$this->pick($random, [...self::STATES, 'restart']),
                3 => $this->pick($random, ['pending', 'active']).'='.$this->pick($random, ['-1', '12a', '1234567890123456789', ' 7 ', '']),
                4 => strtolower($this->words($random, 1, 3)).'='.$random->getInt(0, 999),
                5 => strtolower($this->words($random, 1, 2)),
                6 => '  ',
                7 => 'stop_requested='.$this->pick($random, ['maybe', '2', 'yes', 'OFF']),
                default => 'pending='.$random->getInt(0, PHP_INT_MAX >> 4),
            };
        }
        $segments = $random->shuffleArray($segments);

        return implode(';', $segments);
    }

    private function workloadRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [
            $random->getInt(0, 3) === 0 ? strtolower($this->words($random, 1, 3, '-')) : $this->pick($random, self::QUEUES),
            (string) ($random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 5000)),
            (string) $random->getInt(0, 600),
            (string) ($random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 20)),
            (string) ($random->getInt(0, 9) === 0 ? $random->getInt(1, 50) : 0),
        ];
        if (! $uncommon) {
            return implode("\t", $fields);
        }
        switch ($random->getInt(0, 6)) {
            case 0:
                $fields[0] = $this->words($random, 2, 6, ' ')."\u{e9}";
                break;
            case 1:
                array_splice($fields, $random->getInt(1, 4), 1);
                break;
            case 2:
                $fields[] = (string) $random->getInt(0, 9);
                break;
            case 3:
                $fields[$random->getInt(1, 4)] = $this->pick($random, ['-1', '1.5', '', ' 3', '1234567890123456789', 'NaN']);
                break;
            case 4:
                $fields[1] = (string) $random->getInt(1_000_000_000, 999_999_999_999_999_999);
                break;
            case 5:
                $fields[0] = '';
                break;
            default:
                $fields[0] = str_repeat(strtolower($this->words($random, 2, 4, '.')), $random->getInt(1, 6));
        }

        return implode("\t", $fields);
    }

    private function latencyWindow(Randomizer $random, bool $uncommon): string
    {
        $samples = [];
        if (! $uncommon) {
            for ($i = $random->getInt(20, 60); $i > 0; $i--) {
                $samples[] = (string) ($random->getInt(0, 19) === 0 ? $random->getInt(1000, 12_000) : $random->getInt(5, 900));
            }

            return implode(',', $samples);
        }
        if ($random->getInt(0, 19) === 0) {
            return $this->pick($random, ['', ' ', ',,', 'x']);
        }
        for ($i = $random->getInt(200, 500); $i > 0; $i--) {
            $samples[] = match ($random->getInt(0, 24)) {
                0 => $this->pick($random, ['x', '-5', '1.5', '1234567890', 'NaN', '1e3']),
                1 => '',
                2 => ' '.$random->getInt(0, 999_999_999)."\t",
                3 => (string) $random->getInt(10_000, 999_999),
                default => (string) $random->getInt(0, 3000),
            };
        }

        return implode(',', $samples);
    }
}
