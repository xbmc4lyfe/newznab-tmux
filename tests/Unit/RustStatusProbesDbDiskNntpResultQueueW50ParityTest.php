<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\StatusProbes\DatabaseProbe;
use App\Services\StatusProbes\DiskProbe;
use App\Services\StatusProbes\NntpProbe;
use App\Services\StatusProbes\ProbeResult;
use App\Services\StatusProbes\QueueProbe;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustStatusProbesDbDiskNntpResultQueueW50ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        DatabaseProbe::class => 'summarizeProbeSamples',
        DiskProbe::class => 'evaluateMountBatch',
        NntpProbe::class => 'evaluateProbeBatch',
        ProbeResult::class => 'summarizeBatch',
        QueueProbe::class => 'evaluateQueueBatch',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of three runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 50, 200 pairs
     * per workload after 20 warmups, randomized call order, each side the fastest of three
     * back-to-back calls, release Rust, PHP 8.5.12 CLI without opcache, Apple Silicon macOS
     * (shared host, load ~11).
     * Timings include dispatch, packing, the FFI call, unpack('P*') and PHP patching of declined
     * rows. A class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        DatabaseProbe::class => [5.501, 6.160, 6.762, 6.198],
        DiskProbe::class => [10.467, 8.546, 12.495, 7.511],
        NntpProbe::class => [6.226, 4.779, 6.698, 7.018],
        ProbeResult::class => [3.184, 3.230, 3.578, 3.187],
        QueueProbe::class => [5.096, 4.539, 5.678, 5.308],
    ];

    private const PAIRS = 200;

    private const WARMUPS = 20;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 50;

    private const GIB = 1_073_741_824;

    private const BASE_NS = 1_700_000_000_000_000;

    private const DB_ERRORS = [
        'SQLSTATE[HY000] [2002] Connection refused (Connection: mariadb, Host: 192.168.1.111, Port: 3306, Database: nntmux, SQL: select 1)',
        "SQLSTATE[HY000] [1045] Access denied for user 'nntmux'@'192.168.1.113' (using password: YES)",
        'SQLSTATE[HY000] [2006] MySQL server has gone away',
        'SQLSTATE[HY000] [2002] Operation timed out',
        'SQLSTATE[08S01]: Communication link failure: 1053 Server shutdown in progress (Connection: mariadb, Host: 192.168.1.111, Port: 3306, Database: nntmux, SQL: select 1)',
        'SQLSTATE[HY000] [1040] Too many connections',
    ];

    private const NNTP_ERRORS = [
        'Connection to news.example.com:563 timed out',
        'Authentication rejected (481 Authentication failed: invalid username/password)',
        'Could not connect to NNTP server: Connection refused',
        '502 Too many connections from your IP address, please wait before reconnecting to this server and try again later',
        'SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed',
    ];

    private const QUEUE_ERRORS = [
        'Connection refused [tcp://127.0.0.1:6379]',
        'read error on connection to redis:6379',
        'SQLSTATE[42S02]: Base table or view not found: 1146 Table \'nntmux.jobs\' doesn\'t exist (Connection: mariadb, SQL: select count(*) as aggregate from `jobs` where `queue` = default)',
        'php_network_getaddresses: getaddrinfo for redis failed: nodename nor servname provided, or not known',
    ];

    private const REASONS = [
        'Connected',
        'Primary NNTP connected',
        'Primary and alternate NNTP connected',
        'Queue "redis" reachable',
        'Queue uses sync driver',
        'Disk healthy (lowest: / 412.73 GB free)',
        'Disk space warning on /var/lib/mysql (4.12 GB free)',
        'Low disk space on /mnt/usenet (0.87 GB free)',
        'Redis ping returned unexpected response',
        'Primary NNTP failed: Connection to news.example.com:563 timed out',
    ];

    private const IMPACTS = ['none', 'minor', 'major', 'critical'];

    /** @var list<string>|null */
    private static ?array $edgeMessages = null;

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW50RustFfi', 'withW50RustFfi', 'w50RustFfiState', 'w50Ffi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('withW50RustFfi')->isStatic(), $class);
            self::assertTrue($reflection->getMethod('w50RustFfiState')->isPrivate(), $class);
            $constant = $reflection->getReflectionConstant('W50_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w501_', (string) $reflection->getConstant('W50_FFI_CDEF'));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w50', $property->getName(), $class.' keeps Wave 50 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
        }
    }

    public function test_with_w50_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW50RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW50RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW50RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
                self::fail('exception expected');
            } catch (RuntimeException $e) {
                self::assertSame('boom', $e->getMessage());
            }
            self::assertSame($default, $this->uses($class), $class.' routing must be restored after an exception');
        }
        // The Wave 50 switches are independent of the earlier Rust toggles.
        self::assertFalse(DatabaseProbe::usesRustFfi());
        self::assertFalse(DiskProbe::usesRustFfi());
        self::assertFalse(NntpProbe::usesRustFfi());
        self::assertFalse(ProbeResult::usesRustFfi());
        self::assertFalse(QueueProbe::usesRustFfi());
    }

    public function test_bindings_resolve_and_accept_a_one_item_batch(): void
    {
        $this->requireBindings();
    }

    public function test_mismatched_columns_are_rejected_on_both_routes(): void
    {
        $calls = [
            DatabaseProbe::class => [[1], [], [null]],
            DiskProbe::class => [['/'], [], 5.0, 1.0],
            NntpProbe::class => [[0], [''], [''], [0], [], false],
            ProbeResult::class => [[true], [1], [], ['x']],
            QueueProbe::class => [['redis'], [0], [0], []],
        ];
        foreach ($calls as $class => $args) {
            foreach ([false, true] as $native) {
                try {
                    $class::withW50RustFfi($native, fn (): array => $this->entry($class)(...$args));
                    self::fail($class.' must reject mismatched columns');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('same length', $e->getMessage());
                }
            }
        }
    }

    public function test_database_sample_parity(): void
    {
        $this->requireBindings();
        $long = str_repeat('x', 117).'   tail';
        $expected = [
            1 | 9 << 5 | 12 << 16,
            4 << 1 | 29 << 5,
            4 << 1 | 1 << 4 | 142 << 5,
            1 | 9 << 5 | -5 << 16,
            1 | 9 << 5 | 18_446_744_073_709 << 16,
        ];
        $args = [[0, 0, 0, 5_000_000, PHP_INT_MIN], [12_500_000, 0, 0, 0, PHP_INT_MAX], [null, 'refused', $long, null, null]];
        self::assertSame($expected, DatabaseProbe::summarizeProbeSamplesPhp(...$args));
        $this->assertParity(DatabaseProbe::class, $args);
        $this->assertParity(DatabaseProbe::class, [[], [], []]);
        $this->assertParity(DatabaseProbe::class, [[0], [1], ["nul\0byte"]]);
        $edge = $this->edgeMessages();
        $count = \count($edge);
        $this->assertParity(DatabaseProbe::class, [array_fill(0, $count, 0), range(0, ($count - 1) * 999_999, 999_999), $edge]);
        $this->assertParity(DatabaseProbe::class, [$this->extremeTimes(0), $this->extremeTimes(1), array_fill(0, 12, null)]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(DatabaseProbe::class, $this->databaseBatch(new Randomizer(new Mt19937(5010 + $i)), $uncommon));
            }
        }
    }

    public function test_disk_mount_parity(): void
    {
        $this->requireBindings();
        $expected = [
            1 | 38 << 2 | 300 << 18,
            2 | 38 << 2 | 50 << 18,
            0 | 41 << 2 | 1_234_500 << 18,
            1,
        ];
        $args = [['/', '/data', '/'], [3 * self::GIB, intdiv(self::GIB, 2), 12_345 * self::GIB], 5.0, 1.0];
        self::assertSame($expected, DiskProbe::evaluateMountBatchPhp(...$args));
        $this->assertParity(DiskProbe::class, $args);
        $this->assertParity(DiskProbe::class, [[], [], 5.0, 1.0]);
        $this->assertParity(DiskProbe::class, [['/', '/tmp'], [-1, self::GIB], 5.0, 1.0]);
        $this->assertParity(DiskProbe::class, [["/\0x"], [self::GIB], 5.0, 1.0]);
        $boundaries = [0, 1, self::GIB - 1, self::GIB, self::GIB + 1, 5 * self::GIB - 1, 5 * self::GIB, 5 * self::GIB + 1, PHP_INT_MAX, PHP_INT_MAX - 1, 2 ** 53 + 1];
        for ($k = 0; $k < 400; $k++) {
            $tie = intdiv((2 * $k + 1) * self::GIB, 200);
            array_push($boundaries, $tie - 1, $tie, $tie + 1);
        }
        $mounts = array_map(static fn (int $i): string => match ($i % 4) {
            0 => '/',
            1 => '/mnt/usenet/data'.$i,
            2 => 'C:\\término '.$i,
            default => str_repeat('/deep', 60),
        }, array_keys($boundaries));
        foreach ([[5.0, 1.0], [0.0, 0.0], [1.0, 5.0], [-1.0, -2.0], [INF, NAN], [NAN, INF], [0.01, 0.005]] as [$warning, $critical]) {
            $this->assertParity(DiskProbe::class, [$mounts, $boundaries, $warning, $critical]);
        }
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(DiskProbe::class, $this->diskBatch(new Randomizer(new Mt19937(5020 + $i)), $uncommon));
            }
        }
    }

    public function test_nntp_run_parity(): void
    {
        $this->requireBindings();
        $expected = [
            1 | 22 << 5 | 7 << 16,
            4 << 1 | 28 << 5,
            4 << 1 | 46 << 5,
            1 | 22 << 5 | 3 << 16,
        ];
        $args = [[0 | 3 << 2, 1 | 3 << 2, 2 | 3 << 2, 0 | 1 << 2], ['', 'timeout', 'ignored', ''], ['', '', '', 'alt down'], [0, 0, 0, 0], [7_000_000, 0, 0, 3_999_999], false];
        self::assertSame($expected, NntpProbe::evaluateProbeBatchPhp(...$args));
        $this->assertParity(NntpProbe::class, $args);
        $args[5] = true;
        self::assertSame([1 | 36 << 5 | 7 << 16, 4 << 1 | 28 << 5, 4 << 1 | 46 << 5, 3 << 1 | 1 << 4 | 31 << 5], NntpProbe::evaluateProbeBatchPhp(...$args));
        $this->assertParity(NntpProbe::class, $args);
        $this->assertParity(NntpProbe::class, [[], [], [], [], [], true]);
        $this->assertParity(NntpProbe::class, [[1], ["a\0b"], [''], [0], [0], false]);
        $outcomes = [...range(0, 15), -1, -16, 256, 257, 1023, PHP_INT_MAX, PHP_INT_MIN, 300];
        $count = \count($outcomes);
        $edge = $this->edgeMessages();
        $primary = array_map(static fn (int $i): string => $edge[$i % \count($edge)], range(0, $count - 1));
        $alternate = array_reverse($primary);
        foreach ([false, true] as $check) {
            $this->assertParity(NntpProbe::class, [$outcomes, $primary, $alternate, array_fill(0, $count, 0), array_slice([...$this->extremeTimes(1), ...$this->extremeTimes(1)], 0, $count), $check]);
            $this->assertParity(NntpProbe::class, [array_slice($outcomes, 0, 12), array_slice($primary, 0, 12), array_slice($alternate, 0, 12), $this->extremeTimes(0), $this->extremeTimes(1), $check]);
        }
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(NntpProbe::class, $this->nntpBatch(new Randomizer(new Mt19937(5030 + $i)), $uncommon));
            }
        }
    }

    public function test_probe_result_summary_parity(): void
    {
        $this->requireBindings();
        $expected = [
            1 | 9 << 5 | 12 << 16,
            3 << 1 | 1 << 4 | 123 << 5,
            7 << 1 | 1 << 5 | 0x7FFF_FFFF_FFFF << 16,
            1 | 1 << 1,
            2 | 3 << 32,
        ];
        $args = [[true, false, false, true], [12, 0, PHP_INT_MAX, -40], [null, 'major', 'severe', 'none'], ['Connected', str_repeat('r', 121), 'x', '']];
        self::assertSame($expected, ProbeResult::summarizeBatchPhp(...$args));
        $this->assertParity(ProbeResult::class, $args);
        $this->assertParity(ProbeResult::class, [[], [], [], []]);
        $this->assertParity(ProbeResult::class, [[true], [1], ["minor\0"], ['x']]);
        $this->assertParity(ProbeResult::class, [[true], [1], ['minor'], ["x\0"]]);
        $edge = $this->edgeMessages();
        $count = \count($edge);
        $impacts = array_map(static fn (int $i): ?string => [null, 'none', 'minor', 'major', 'critical', '', 'CRITICAL', 'critical ', 'severe', "\u{e9}"][$i % 10], range(0, $count - 1));
        $this->assertParity(ProbeResult::class, [array_map(static fn (int $i): bool => $i % 3 === 0, range(0, $count - 1)), array_slice([...$this->extremeTimes(0), ...$this->extremeTimes(1), ...range(-50, 200)], 0, $count), $impacts, $edge]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(ProbeResult::class, $this->resultBatch(new Randomizer(new Mt19937(5040 + $i)), $uncommon));
            }
        }
    }

    public function test_queue_sample_parity(): void
    {
        $this->requireBindings();
        $expected = [
            1 | 1 << 4 | 22 << 5 | 2 << 16,
            1 | 23 << 5 | 2 << 16,
            4 << 1 | 1 << 4 | 24 << 5,
            1 | 18 << 5 | -1 << 16,
        ];
        $args = [['sync', 'redis', 'redis', ''], [0, 0, 0, 1_000_001], [2_000_000, 2_999_999, 0, 0], [null, null, 'down', null]];
        self::assertSame($expected, QueueProbe::evaluateQueueBatchPhp(...$args));
        $this->assertParity(QueueProbe::class, $args);
        $this->assertParity(QueueProbe::class, [[], [], [], []]);
        $this->assertParity(QueueProbe::class, [["re\0dis"], [0], [0], [null]]);
        $edge = $this->edgeMessages();
        $count = \count($edge);
        $names = array_map(static fn (int $i): string => ['sync', 'SYNC', 'sync ', '', 'redis', 'database', 'sqs', 'beanstalkd', 'файлы', str_repeat('q', 300)][$i % 10], range(0, $count - 1));
        $this->assertParity(QueueProbe::class, [$names, array_fill(0, $count, -7_000_000), range(0, ($count - 1) * 1_500_001, 1_500_001), $edge]);
        $this->assertParity(QueueProbe::class, [$names, array_fill(0, $count, 0), array_fill(0, $count, 0), array_fill(0, $count, null)]);
        $this->assertParity(QueueProbe::class, [array_slice($names, 0, 12), $this->extremeTimes(0), $this->extremeTimes(1), array_fill(0, 12, null)]);
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < 8; $i++) {
                $this->assertParity(QueueProbe::class, $this->queueBatch(new Randomizer(new Mt19937(5050 + $i)), $uncommon));
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(self::SEED));
        $summary = $records = [];
        foreach (self::ENTRIES as $class => $entry) {
            $call = $this->entry($class);
            foreach (['common', 'uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->fixture($class, $random, $workload === 'uncommon');
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $args = $fixtures[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW50RustFfi($native, static function () use ($call, $args): array {
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
        $artifact = sys_get_temp_dir().'/w501-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW50.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW50RustFfi'];

        return $uses();
    }

    private function entry(string $class): Closure
    {
        /** @var callable $entry */
        $entry = [$class, self::ENTRIES[$class]];

        return Closure::fromCallable($entry);
    }

    /** @param  list<mixed>  $args */
    private function assertParity(string $class, array $args): void
    {
        $call = $this->entry($class);
        $php = $class::withW50RustFfi(false, static fn (): array => $call(...$args));
        $native = $class::withW50RustFfi(true, static fn (): array => $call(...$args));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
    }

    /** @return list<mixed> */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            DatabaseProbe::class => $this->databaseBatch($random, $uncommon),
            DiskProbe::class => $this->diskBatch($random, $uncommon),
            NntpProbe::class => $this->nntpBatch($random, $uncommon),
            ProbeResult::class => $this->resultBatch($random, $uncommon),
            default => $this->queueBatch($random, $uncommon),
        };
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $one = pack('P', 0);
        $calls = [
            DatabaseProbe::class => ['nntmux_w501_database_probe_summarize_batch', [$one, 8, $one, 8, 'x', 1, '', 0, 1], 1],
            DiskProbe::class => ['nntmux_w501_disk_probe_evaluate_batch', ['/', 1, $one, 8, 1, 5.0, 1.0], 2],
            NntpProbe::class => ['nntmux_w501_nntp_probe_evaluate_batch', ["\x0C", 1, '', 0, '', 0, $one, 8, $one, 8, 1, 0], 1],
            ProbeResult::class => ['nntmux_w501_probe_result_summarize_batch', ["\x01", 1, $one, 8, '', 0, pack('V', 0), 4, 'x', 1, 1], 2],
            QueueProbe::class => ['nntmux_w501_queue_probe_evaluate_batch', ['sync', 4, $one, 8, $one, 8, '', 0, pack('V', 0), 4, 1], 1],
        ];
        foreach ($calls as $class => [$symbol, $args, $words]) {
            $ffi = (new ReflectionMethod($class, 'w50Ffi'))->invoke(null);
            self::assertInstanceOf(FFI::class, $ffi, $class.' must load the status_probes_w50 binding');
            $out = $ffi->new('int64_t[4]');
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$args, $out, 4]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, $out, $words - 1]), $symbol.' short capacity');
        }
    }

    /**
     * Calls an export declared by a class's W50_FFI_CDEF.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @return list<string> */
    private function edgeMessages(): array
    {
        return self::$edgeMessages ??= [
            '', ' ', "\t", 'x', str_repeat('a', 119), str_repeat('a', 120), str_repeat('a', 121), str_repeat('a', 117)." \t\x0B  tail",
            str_repeat('a', 118)."\n\r\x0Bz", str_repeat(' ', 200), str_repeat("\r\n", 70), "\x01\x02\x7F control", str_repeat('b', 1000),
            'é', str_repeat('é', 70), str_repeat('é', 121), str_repeat('漢', 60), str_repeat('漢', 61), str_repeat('a', 119).'é', str_repeat('a', 120).'é',
            "\xC3", "\xFF\xFE bad", str_repeat("\xC3", 130), '😀 emoji', str_repeat('😀', 61), 'Connexion refusée par le serveur',
            'SQLSTATE[HY000] [2002] Connection refused', str_repeat('SQLSTATE[HY000] [2002] Connection refused ', 4),
        ];
    }

    /** @return list<int> 12 start (`$side` 0) or end (`$side` 1) times around overflow and division edges. */
    private function extremeTimes(int $side): array
    {
        $pairs = [
            [0, 0], [0, 999_999], [0, 1_000_000], [0, -999_999], [0, -1_000_000], [PHP_INT_MIN, PHP_INT_MAX],
            [PHP_INT_MAX, PHP_INT_MIN], [-1, PHP_INT_MAX], [1, PHP_INT_MIN], [0, PHP_INT_MAX], [0, 2 ** 53 + 1_000_001], [PHP_INT_MIN, 0],
        ];

        return array_column($pairs, $side);
    }

    /** @param  list<string>  $values */
    private function pick(Randomizer $random, array $values): string
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /** A message drawn from realistic errors, the edge cases or random mutations of either. */
    private function oddMessage(Randomizer $random, string $base): string
    {
        return match ($random->getInt(0, 5)) {
            0 => $this->pick($random, $this->edgeMessages()),
            1 => substr($base, 0, $random->getInt(0, \strlen($base))),
            2 => str_repeat($base.' ', $random->getInt(2, 6)),
            3 => str_pad($base, 120, ' ').'overflow',
            4 => $random->getInt(0, 3) === 0 ? 'Échec: '.$base : $base."\t\n",
            default => $random->getBytesFromString("abcdefghijklmnopqrstuvwxyz :[]()'/\t", $random->getInt(100, 160)),
        };
    }

    /** @return array{0: list<int>, 1: list<int>} */
    private function timings(Randomizer $random, int $count, bool $uncommon): array
    {
        $starts = $ends = [];
        for ($i = 0; $i < $count; $i++) {
            $start = self::BASE_NS + $random->getInt(0, 1 << 40);
            $end = $start + ($random->getInt(0, 9) === 0 ? $random->getInt(40_000_000, 3_000_000_000) : $random->getInt(150_000, 40_000_000));
            if ($uncommon && $random->getInt(0, 3) === 0) {
                [$start, $end] = match ($random->getInt(0, 4)) {
                    0 => [$start, $start + $random->getInt(0, 50) * 1_000_000],
                    1 => [$end, $start],
                    2 => [$random->getInt(PHP_INT_MIN, 0), $random->getInt(0, PHP_INT_MAX)],
                    3 => [$random->getInt(0, PHP_INT_MAX), $random->getInt(PHP_INT_MIN, 0)],
                    default => [0, $random->getInt(0, PHP_INT_MAX)],
                };
            }
            $starts[] = $start;
            $ends[] = $end;
        }

        return [$starts, $ends];
    }

    /** @return array{0: list<int>, 1: list<int>, 2: list<?string>} */
    private function databaseBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        [$starts, $ends] = $this->timings($random, $count, $uncommon);
        $errors = [];
        for ($i = 0; $i < $count; $i++) {
            $failed = $random->getInt(0, 99) < ($uncommon ? 50 : 15);
            $error = $failed ? $this->pick($random, self::DB_ERRORS) : null;
            if ($uncommon && $error !== null && $random->getInt(0, 2) === 0) {
                $error = $this->oddMessage($random, $error);
            }
            $errors[] = $error;
        }

        return [$starts, $ends, $errors];
    }

    /** @return array{0: list<string>, 1: list<int>, 2: float, 3: float} */
    private function diskBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        $mounts = $bytes = [];
        for ($i = 0; $i < $count; $i++) {
            $mounts[] = $this->pick($random, ['/', '/var/lib/mysql', '/app/storage', '/mnt/usenet/data'.$random->getInt(1, 24), '/Volumes/Archive', '/var/lib/manticore']);
            $bytes[] = match ($random->getInt(0, 4)) {
                0 => $random->getInt(0, 6 * self::GIB),
                1 => $random->getInt(20, 900) * self::GIB + $random->getInt(0, self::GIB),
                default => $random->getInt(0, 8 << 40),
            };
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $mounts[$i] = $random->getInt(0, 1) === 0 ? $mounts[$i] : $this->pick($random, ['', 'C:\\', '/Volumes/Données', str_repeat('/n', 200), '/mnt/'.str_repeat('x', 60)]);
                $bytes[$i] = match ($random->getInt(0, 4)) {
                    0 => intdiv($random->getInt(0, 100_000) * 2 + 1, 2) * intdiv(self::GIB, 100) + $random->getInt(-2, 2) + intdiv(self::GIB, 200),
                    1 => ($random->getInt(0, 1) === 0 ? self::GIB : 5 * self::GIB) + $random->getInt(-3, 3),
                    2 => $random->getInt(PHP_INT_MAX - (1 << 20), PHP_INT_MAX),
                    3 => $random->getInt(0, 3),
                    default => $random->getInt(0, PHP_INT_MAX),
                };
                $bytes[$i] = max(0, $bytes[$i]);
            }
        }
        [$warning, $critical] = $uncommon && $random->getInt(0, 1) === 0
            ? [$random->getFloat(0, 200), $random->getFloat(0, 50)]
            : [5.0, 1.0];

        return [$mounts, $bytes, $warning, $critical];
    }

    /** @return array{0: list<int>, 1: list<string>, 2: list<string>, 3: list<int>, 4: list<int>, 5: bool} */
    private function nntpBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        [$starts, $ends] = $this->timings($random, $count, $uncommon);
        $check = $uncommon ? $random->getInt(0, 1) === 1 : $random->getInt(0, 3) !== 0;
        $outcomes = $primary = $alternate = [];
        for ($i = 0; $i < $count; $i++) {
            $primaryKind = $random->getInt(0, 99) < 90 ? 0 : $random->getInt(1, 2);
            $alternateKind = ! $check || $primaryKind !== 0 ? 3 : ($random->getInt(0, 99) < 92 ? 0 : 1);
            $outcome = $primaryKind | $alternateKind << 2;
            $p = $primaryKind === 1 ? $this->pick($random, self::NNTP_ERRORS) : '';
            $a = $alternateKind === 1 ? $this->pick($random, self::NNTP_ERRORS) : '';
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $outcome = $random->getInt(0, 3) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt(0, 15);
                $p = $this->oddMessage($random, $this->pick($random, self::NNTP_ERRORS));
                $a = $this->oddMessage($random, $this->pick($random, self::NNTP_ERRORS));
            }
            $outcomes[] = $outcome;
            $primary[] = $p;
            $alternate[] = $a;
        }

        return [$outcomes, $primary, $alternate, $starts, $ends, $check];
    }

    /** @return array{0: list<bool>, 1: list<int>, 2: list<?string>, 3: list<string>} */
    private function resultBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        $ok = $times = $impacts = $reasons = [];
        for ($i = 0; $i < $count; $i++) {
            $passed = $random->getInt(0, 99) < 85;
            $ok[] = $passed;
            $times[] = $passed ? $random->getInt(0, 450) : 0;
            $impacts[] = $passed ? null : $this->pick($random, ['minor', 'major', 'critical']);
            $reasons[] = $passed
                ? $this->pick($random, array_slice(self::REASONS, 0, 6))
                : $this->pick($random, [...array_slice(self::REASONS, 6), 'Database unreachable: '.$this->pick($random, self::DB_ERRORS)]);
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $times[$i] = $random->getInt(0, 3) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt(-100, 5_000);
                $impacts[$i] = $random->getInt(0, 3) === 0 ? $this->pick($random, ['', 'Critical', 'MAJOR', 'severe', 'minor ', 'none']) : $this->pick($random, self::IMPACTS);
                $reasons[$i] = $this->oddMessage($random, $reasons[$i]);
            }
        }

        return [$ok, $times, $impacts, $reasons];
    }

    /** @return array{0: list<string>, 1: list<int>, 2: list<int>, 3: list<?string>} */
    private function queueBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $uncommon ? $random->getInt(128, 256) : $random->getInt(64, 128);
        [$starts, $ends] = $this->timings($random, $count, $uncommon);
        $connections = $errors = [];
        for ($i = 0; $i < $count; $i++) {
            $connection = $random->getInt(0, 9) < 7 ? 'redis' : $this->pick($random, ['sync', 'database', 'redis-horizon']);
            $error = $random->getInt(0, 99) < ($uncommon ? 50 : 10) ? $this->pick($random, self::QUEUE_ERRORS) : null;
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $connection = $this->pick($random, ['', 'SYNC', 'sync ', ' sync', 'sqs-fifo', 'file-кэш', str_repeat('c', 300), 'sync']);
                $error = $error === null ? null : $this->oddMessage($random, $error);
            }
            $connections[] = $connection;
            $errors[] = $error;
        }

        return [$connections, $starts, $ends, $errors];
    }
}
