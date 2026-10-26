<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Monitoring\Prometheus\TextFormatter;
use App\Services\Monitoring\TmuxMetricsSnapshot;
use App\Services\StatusProbes\ProbeResult;
use App\Services\StatusProbes\RedisProbe;
use App\Services\StatusProbes\ServiceProbeRegistry;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustPrometheusTmuxSnapshotProbeResultRedisRegistryW56ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        TextFormatter::class => 'expositionTokenCodes',
        TmuxMetricsSnapshot::class => 'snapshotLagSeconds',
        ProbeResult::class => 'detailCodes',
        RedisProbe::class => 'infoWords',
        ServiceProbeRegistry::class => 'identifierCodes',
    ];

    /** Output words per row. */
    private const WORDS_PER_ROW = [RedisProbe::class => 9];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns) from
     * test_paired_randomized_common_and_uncommon_benchmarks (NNTMUX_W561_BENCH=1): seed 56,
     * ROUNDS interleaved pairs per workload after WARMUPS, ROWS rows per batch, randomized call
     * order, each side the fastest of REPEATS back-to-back calls, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS. Timings include dispatch, implode(), the FFI call and
     * unpack('P*'). Per-metric minimum of three runs. A class defaults to Rust only when all four
     * reach 1.5x.
     */
    private const QUALIFICATION = [
        TextFormatter::class => [2.987, 2.803, 2.861, 2.58],
        TmuxMetricsSnapshot::class => [15.627, 19.593, 6.922, 4.546],
        ProbeResult::class => [4.587, 4.465, 2.638, 2.484],
        RedisProbe::class => [4.365, 3.336, 2.838, 3.355],
        ServiceProbeRegistry::class => [2.521, 2.289, 2.631, 2.965],
    ];

    private const ROUNDS = 60;

    private const WARMUPS = 8;

    private const ROWS = 320;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 56;

    /** Fixed "now" for snapshot lag fixtures. */
    private const NOW = 1_793_000_000;

    private const WORDS = ['alpha', 'Bravo', 'charlie', 'DELTA', 'echo', 'Foxtrot', 'golf', 'hotel', 'India', 'juliet', 'kilo', 'Lima', 'mike', 'November'];

    private const REASONS = ['Connected', 'Redis unreachable: Connection refused', 'Queue lagging behind', 'Disk usage warning', 'Search timed out after 5s', 'Unknown probe identifier "x"',
        'Skipping: cache/session/queue do not use Redis', 'NNTP authentication failed', 'Database error: SQLSTATE[HY000]', 'Manticore slow response', 'Degraded performance', 'All good'];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW56RustFfi', 'withW56RustFfi', 'w56RustFfiState', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW56RustFfi', 'withW56RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('w56RustFfiState')->isPrivate(), $class);
            $constant = $reflection->getReflectionConstant('W56_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w561_', (string) $reflection->getConstant('W56_FFI_CDEF'));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w56', $property->getName(), $class.' keeps Wave 56 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
            if (min($speedups) < 1.5) {
                self::assertFalse($this->uses($class), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_w56_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW56RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW56RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW56RustFfi(! $default, static function (): never {
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
            $args = $this->args($class, ['redis', 'x']);
            $call = $this->entry($class);
            $php = $class::{self::ENTRIES[$class].'Php'}(...$args);
            self::assertSame($php, $class::withW56RustFfi(true, static fn (): mixed => $call(...$args)), $class);
            self::assertSame($php, $class::withW56RustFfi(false, static fn (): mixed => $call(...$args)), $class);
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        self::assertFalse(TextFormatter::usesW445RustFfi());
        self::assertFalse(TmuxMetricsSnapshot::usesW49RustFfi());
        self::assertFalse(TmuxMetricsSnapshot::$useRustFfi);
        self::assertTrue(ProbeResult::usesW50RustFfi());
        self::assertFalse(ProbeResult::usesRustFfi());
        self::assertFalse(RedisProbe::usesW50RustFfi());
        self::assertFalse(RedisProbe::usesRustFfi());
        self::assertFalse(ServiceProbeRegistry::usesW50RustFfi());
        self::assertFalse(ServiceProbeRegistry::usesRustFfi());
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: list<int>}>
     */
    public static function knownCodes(): iterable
    {
        $token = static fn (int $flags, int $length): array => [$flags | $length << TextFormatter::W56_TOKEN_LENGTH_SHIFT];
        yield 'token metric name' => [TextFormatter::class, 'nntmux_up', $token(TextFormatter::W56_TOKEN_METRIC_NAME | TextFormatter::W56_TOKEN_LABEL_NAME, 9)];
        yield 'token colon' => [TextFormatter::class, 'a:b', $token(TextFormatter::W56_TOKEN_METRIC_NAME, 3)];
        yield 'token reserved' => [TextFormatter::class, '__name__', $token(TextFormatter::W56_TOKEN_METRIC_NAME | TextFormatter::W56_TOKEN_LABEL_NAME | TextFormatter::W56_TOKEN_RESERVED_LABEL, 8)];
        yield 'token final LF' => [TextFormatter::class, "ab\n", $token(TextFormatter::W56_TOKEN_METRIC_NAME | TextFormatter::W56_TOKEN_LABEL_NAME | TextFormatter::W56_TOKEN_LABEL_ESCAPE | TextFormatter::W56_TOKEN_HELP_ESCAPE, 4)];
        yield 'token quote' => [TextFormatter::class, 'a"b', $token(TextFormatter::W56_TOKEN_LABEL_ESCAPE, 4)];
        yield 'token backslash' => [TextFormatter::class, 'a\\', $token(TextFormatter::W56_TOKEN_LABEL_ESCAPE | TextFormatter::W56_TOKEN_HELP_ESCAPE, 3)];
        yield 'token digit start' => [TextFormatter::class, '9a', $token(0, 2)];
        yield 'token empty' => [TextFormatter::class, '', [0]];

        yield 'lag digits' => [TmuxMetricsSnapshot::class, (string) (self::NOW - 30), [30]];
        yield 'lag future' => [TmuxMetricsSnapshot::class, (string) (self::NOW + 30), [0]];
        yield 'lag epoch datetime' => [TmuxMetricsSnapshot::class, '1970-01-01 00:00:00', [self::NOW]];
        yield 'lag T datetime' => [TmuxMetricsSnapshot::class, '1970-01-01T00:01:00', [self::NOW - 60]];
        yield 'lag leap day' => [TmuxMetricsSnapshot::class, '2024-02-29 12:00:00', [self::NOW - 1_709_208_000]];
        yield 'lag invalid leap day' => [TmuxMetricsSnapshot::class, '2023-02-29 12:00:00', [-1]];
        yield 'lag hour 24' => [TmuxMetricsSnapshot::class, '2023-01-01 24:00:00', [-1]];
        yield 'lag year zero' => [TmuxMetricsSnapshot::class, '0000-01-01 00:00:00', [-1]];
        yield 'lag thirteen digits' => [TmuxMetricsSnapshot::class, '1234567890123', [-1]];
        yield 'lag empty' => [TmuxMetricsSnapshot::class, '', [-1]];

        $detail = static fn (int $flags, int $length): array => [$flags | $length << ProbeResult::W56_DETAIL_LENGTH_SHIFT];
        yield 'detail connected' => [ProbeResult::class, '  Connected ', $detail(ProbeResult::W56_DETAIL_HEALTHY, 9)];
        yield 'detail refused' => [ProbeResult::class, "Connection\t\nREFUSED", $detail(ProbeResult::W56_DETAIL_UNHEALTHY, 18)];
        yield 'detail timed out' => [ProbeResult::class, 'timed  out', $detail(ProbeResult::W56_DETAIL_UNHEALTHY, 9)];
        yield 'detail form feed' => [ProbeResult::class, "\x0cslow", $detail(ProbeResult::W56_DETAIL_DEGRADED, 5)];
        yield 'detail non ascii' => [ProbeResult::class, "caf\u{e9}", $detail(ProbeResult::W56_DETAIL_NON_ASCII, 5)];
        yield 'detail long' => [ProbeResult::class, str_repeat('a', 121), $detail(ProbeResult::W56_DETAIL_LONG, 121)];

        yield 'info replica' => [RedisProbe::class, "# Memory\r\nused_memory:900\r\nmaxmemory:1000\r\nconnected_clients:3\r\nrole:slave\r\nmaster_link_status:up\r\nkeyspace_hits:3\r\nkeyspace_misses:1\r\n", [900, 1000, 3, -1, 3, 1, 2 | 1 << 2 | 1 << 4, 750, 900]];
        yield 'info final CR kept' => [RedisProbe::class, "used_memory:5\nconnected_clients:1\r", [5, -1, -1, -1, -1, -1, 2 << 4, -1, -1]];
        yield 'info duplicate key' => [RedisProbe::class, "used_memory:5\nused_memory:7\nconnected_clients:1\nrole:master", [7, -1, 1, -1, -1, -1, 1, -1, -1]];
        yield 'info empty' => [RedisProbe::class, '', [-1, -1, -1, -1, -1, -1, 2 << 4, -1, -1]];

        $id = static fn (int $flags, int $known, int $length): array => [$flags | $known << ServiceProbeRegistry::W56_IDENTIFIER_KNOWN_SHIFT | $length << ServiceProbeRegistry::W56_IDENTIFIER_LENGTH_SHIFT];
        yield 'identifier redis' => [ServiceProbeRegistry::class, 'redis', $id(ServiceProbeRegistry::W56_IDENTIFIER_VALID, 2, 5)];
        yield 'identifier padded' => [ServiceProbeRegistry::class, " Disk\n", $id(ServiceProbeRegistry::W56_IDENTIFIER_VALID | ServiceProbeRegistry::W56_IDENTIFIER_NORMALIZED, 6, 4)];
        yield 'identifier digit' => [ServiceProbeRegistry::class, '9x', $id(0, 0, 2)];
        yield 'identifier long' => [ServiceProbeRegistry::class, str_repeat('a', 65), $id(0, 0, 65)];
        yield 'identifier form feed' => [ServiceProbeRegistry::class, "\x0cdisk", $id(0, 0, 5)];
    }

    /**
     * @param  class-string  $class
     * @param  list<int>  $expected
     */
    #[DataProvider('knownCodes')]
    public function test_known_codes(string $class, string $row, array $expected): void
    {
        self::assertSame($expected, $this->assertParity($class, $this->args($class, [$row])));
    }

    /**
     * @return iterable<string, array{0: class-string, 1: array<array-key, string>}>
     */
    public static function edgeBatches(): iterable
    {
        $rows = ['', ' ', "\t\n", "\n", "\r\n", '#', ':', 'a:', ':b', '__', '_', 'a_b:c', "a\nb", "ab\n\n", '"', '\\', '\\n', "caf\u{e9}", "\xc3\x28", "\xff", "\u{a0}x", "\x0b\x0c",
            'database', 'DATABASE', ' redis ', "queue\x0b", 'search-1', 'nntp_', str_repeat('z', 64), str_repeat('z', 65), 'Connection REFUSED', "timed\tout", 'slowly', 'ERRORS',
            '1970-01-01 00:00:00', '9999-12-31 23:59:59', '0001-01-01T00:00:00', '2100-02-29 00:00:00', '2000-02-29 00:00:00', '2026-10-26 23:59:60', '2026-1-26 00:00:00', '2026-10-26  00:00:00',
            '000000000000', '999999999999', '1793000000', '-5', '+5', ' 5', '5 ', '0',
            "used_memory:100\nconnected_clients:1", "role:slave\nmaster_link_status:down\nused_memory:1\nconnected_clients:1", "used_memory:999999999999999\nmaxmemory:1\nconnected_clients:0",
            "used_memory:1000000000000000\nconnected_clients:1", "keyspace_hits:0\nkeyspace_misses:0\nused_memory:1\nconnected_clients:1", "used_memory: 1\nconnected_clients:1", "#used_memory:1\n used_memory:2"];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' mixed edges' => [$class, $rows];
            yield $class.' single empty row' => [$class, ['']];
            yield $class.' empty batch' => [$class, []];
            yield $class.' keyed rows' => [$class, ['k' => 'redis', 7 => 'a b', 'z' => "used_memory:1\nconnected_clients:2"]];
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
        self::assertCount(\count($rows) * (self::WORDS_PER_ROW[$class] ?? 1), $result, $class);
    }

    public function test_rows_holding_nul_fall_back_to_php(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $rows = ["a\0b", 'redis', "\0", "used_memory:1\nconnected_clients:2"];
            self::assertCount(4 * (self::WORDS_PER_ROW[$class] ?? 1), $this->assertParity($class, $this->args($class, $rows)), $class);
        }
    }

    public function test_snapshot_lag_now_clamping_parity(): void
    {
        $rows = ['0', '1', '999999999999', '0001-01-01 00:00:00', '9999-12-31 23:59:59', 'x'];
        foreach ([PHP_INT_MIN, -1, 0, 1, self::NOW, TmuxMetricsSnapshot::W56_MAX_NOW - 1, TmuxMetricsSnapshot::W56_MAX_NOW, PHP_INT_MAX] as $now) {
            $this->assertParity(TmuxMetricsSnapshot::class, [$rows, $now]);
        }
    }

    /** At least 300 randomized rows per class and workload, checked as batches and one row at a time. */
    public function test_randomized_dataset_parity(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $rows = $this->rows($class, $random, $uncommon, 320);
                $this->assertParity($class, $this->args($class, $rows));
                foreach (array_slice($rows, 0, 40) as $row) {
                    $this->assertParity($class, $this->args($class, [$row]));
                }
            }
        }
    }

    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W561_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W561_BENCH=1 to run the Wave 56.1 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(self::SEED));
        $summary = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            $call = $this->entry($class);
            foreach (['common', 'uncommon'] as $workload) {
                $fixtures = [];
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[] = $this->args($class, $this->rows($class, $random, $workload === 'uncommon', self::ROWS));
                }
                $php = $rust = [];
                for ($i = -self::WARMUPS; $i < self::ROUNDS; $i++) {
                    $variant = $random->getInt(0, 7);
                    $args = $fixtures[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW56RustFfi($native, static function () use ($call, $args): array {
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
        $artifact = sys_get_temp_dir().'/w561-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'warmups' => self::WARMUPS, 'rows' => self::ROWS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW56.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW56RustFfi'];

        return $uses();
    }

    private function entry(string $class): Closure
    {
        /** @var callable $entry */
        $entry = [$class, self::ENTRIES[$class]];

        return Closure::fromCallable($entry);
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<mixed>
     */
    private function args(string $class, array $rows): array
    {
        return $class === TmuxMetricsSnapshot::class ? [$rows, self::NOW] : [$rows];
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, array $args): mixed
    {
        $call = $this->entry($class);
        $php = $class::withW56RustFfi(false, static fn (): mixed => $call(...$args));
        $native = $class::withW56RustFfi(true, static fn (): mixed => $call(...$args));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);

        return $php;
    }

    /** @return list<string> */
    private function rows(string $class, Randomizer $random, bool $uncommon, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = match ($class) {
                TextFormatter::class => $this->token($random, $uncommon),
                TmuxMetricsSnapshot::class => $this->stamp($random, $uncommon),
                ProbeResult::class => $this->reason($random, $uncommon),
                RedisProbe::class => $this->info($random, $uncommon),
                default => $this->identifier($random, $uncommon),
            };
        }

        return $rows;
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            TextFormatter::class => ['nntmux_w561_prometheus_token_code_batch', ["a\0b", 3, 2], 1],
            TmuxMetricsSnapshot::class => ['nntmux_w561_tmux_snapshot_lag_batch', ["1\0x", 3, 2, 5], 1],
            ProbeResult::class => ['nntmux_w561_probe_detail_code_batch', ["ok\0slow", 7, 2], 1],
            RedisProbe::class => ['nntmux_w561_redis_info_batch', ["role:master\0x", 13, 2], 9],
            ServiceProbeRegistry::class => ['nntmux_w561_probe_identifier_code_batch', ["redis\0x", 7, 2], 1],
        ];
        foreach ($calls as $class => [$symbol, $args, $perRow]) {
            $buffer = (new ReflectionMethod($class, 'w56RustFfiState'))->invoke(null, 'out', 4);
            self::assertIsArray($buffer, $class.' must load its Wave 56 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame(2, $this->invoke($ffi, $symbol, [...$args, $out, 32]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, $out, 2 * $perRow - 1]), $symbol.' short capacity');
            $miscounted = $args;
            $miscounted[2] = 3;
            self::assertSame(-1, $this->invoke($ffi, $symbol, [...$miscounted, $out, 32]), $symbol.' row count mismatch');
        }
    }

    /**
     * Calls an export declared by a class's W56_FFI_CDEF.
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

    private function token(Randomizer $random, bool $uncommon): string
    {
        if (! $uncommon) {
            return match ($random->getInt(0, 4)) {
                0 => 'nntmux_'.strtolower($this->words($random, 2, 4)).'_total',
                1 => strtolower($this->words($random, 1, 2)),
                2 => 'alt.binaries.'.strtolower($this->words($random, 1, 3, '.')),
                3 => (string) $random->getInt(0, 999999),
                default => $this->words($random, 2, 6, ' ').' processed by the tmux monitor',
            };
        }

        return match ($random->getInt(0, 5)) {
            0 => $this->words($random, 4, 12, ' ')."\n".'C:\\path\\'.$this->words($random, 1, 3, '\\'),
            1 => '"'.$this->words($random, 3, 10, '" "').'"',
            2 => '__'.$this->words($random, 5, 15, ':'),
            3 => "caf\u{e9} ".$this->words($random, 5, 20, " \u{2014} "),
            4 => $this->words($random, 2, 4, '_')."\n",
            default => str_repeat('\\"', $random->getInt(1, 30)).$this->words($random, 1, 5),
        };
    }

    private function stamp(Randomizer $random, bool $uncommon): string
    {
        $ts = self::NOW - $random->getInt(-60, 86_400 * 3);
        if (! $uncommon) {
            return match ($random->getInt(0, 2)) {
                0 => (string) $ts,
                1 => gmdate('Y-m-d H:i:s', $ts),
                default => gmdate('Y-m-d\TH:i:s', $ts),
            };
        }

        return match ($random->getInt(0, 6)) {
            0 => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $random->getInt(0, 9999), $random->getInt(0, 13), $random->getInt(0, 32), $random->getInt(0, 25), $random->getInt(0, 61), $random->getInt(0, 61)),
            1 => sprintf('%04d-02-29 12:00:00', $random->getInt(1, 9999)),
            2 => (string) $random->getInt(0, 999_999_999_999),
            3 => (string) $random->getInt(1_000_000_000_000, PHP_INT_MAX),
            4 => gmdate('Y-m-d H:i', $ts),
            5 => ' '.$ts,
            default => gmdate('D, d M Y H:i:s', $ts).' GMT',
        };
    }

    private function reason(Randomizer $random, bool $uncommon): string
    {
        if (! $uncommon) {
            return $this->pick($random, self::REASONS).($random->getInt(0, 2) === 0 ? ' ('.$random->getInt(1, 5000).' ms)' : '');
        }

        return match ($random->getInt(0, 4)) {
            0 => "  \t".$this->pick($random, self::REASONS)."\r\n\x0b ".str_repeat($this->words($random, 3, 8, "  \t "), $random->getInt(1, 4))."\n",
            1 => 'Redis unreachable: '.str_repeat($this->words($random, 5, 12, ' ').' ', $random->getInt(2, 6)),
            2 => strtoupper($this->pick($random, self::REASONS))." caf\u{e9} \u{2014} ".$this->words($random, 3, 10, ' '),
            3 => "\x0c".$this->words($random, 10, 30, "\x0c\x0c").' TIMED   OUT',
            default => $this->words($random, 20, 40, ' ').' '.$this->pick($random, ['ok', 'slow', 'error', 'BEHIND']),
        };
    }

    private function info(Randomizer $random, bool $uncommon): string
    {
        $eol = $random->getInt(0, 3) === 0 ? "\n" : "\r\n";
        $role = $random->getInt(0, 2) === 0 ? 'slave' : 'master';
        $lines = ['# Server', 'redis_version:7.2.'.$random->getInt(0, 9), 'redis_mode:standalone', 'os:Linux 6.1.0 x86_64', 'uptime_in_seconds:'.$random->getInt(0, 9_999_999), '',
            '# Clients', 'connected_clients:'.$random->getInt(0, 5000), 'blocked_clients:'.$random->getInt(0, 9), '',
            '# Memory', 'used_memory:'.$random->getInt(0, 8_000_000_000), 'used_memory_human:'.$random->getInt(1, 999).'.00M', 'maxmemory:'.$random->getInt(0, 8_000_000_000), 'maxmemory_policy:allkeys-lru', '',
            '# Stats', 'total_connections_received:'.$random->getInt(0, 9_999_999), 'instantaneous_ops_per_sec:'.$random->getInt(0, 200_000),
            'keyspace_hits:'.$random->getInt(0, 999_999_999), 'keyspace_misses:'.$random->getInt(0, 999_999_999), '',
            '# Replication', 'role:'.$role];
        if ($role === 'slave') {
            $lines[] = 'master_link_status:'.$this->pick($random, ['up', 'up', 'down']);
        }
        $lines[] = 'connected_slaves:'.$random->getInt(0, 3);
        if (! $uncommon) {
            return implode($eol, $lines).$eol;
        }
        $extra = [];
        for ($i = $random->getInt(20, 80); $i > 0; $i--) {
            $extra[] = strtolower($this->words($random, 2, 5)).':'.$random->getInt(0, PHP_INT_MAX);
        }
        $lines = array_merge($lines, $extra);
        $index = $random->getInt(0, \count($lines) - 1);
        $lines[$index] = match ($random->getInt(0, 5)) {
            0 => 'used_memory:'.$random->getInt(1_000_000_000_000_000, PHP_INT_MAX),
            1 => 'connected_clients: '.$random->getInt(0, 9),
            2 => 'role:'.$this->pick($random, ['replica', 'sentinel', '']),
            3 => 'master_link_status:'.$this->pick($random, ['connecting', 'UP', 'down']),
            4 => '#connected_clients:1',
            default => 'keyspace_hits:'.$random->getInt(0, 9)."\r",
        };

        return implode($eol, $lines).($random->getInt(0, 1) === 0 ? "\r" : '');
    }

    private function identifier(Randomizer $random, bool $uncommon): string
    {
        if (! $uncommon) {
            return $random->getInt(0, 3) === 0 ? strtolower($this->words($random, 1, 2, '-')) : $this->pick($random, ServiceProbeRegistry::PROBE_IDENTIFIERS);
        }

        return match ($random->getInt(0, 5)) {
            0 => "  \t".strtoupper($this->pick($random, ServiceProbeRegistry::PROBE_IDENTIFIERS))."\r\n",
            1 => str_repeat(strtolower($this->words($random, 3, 6, '_')), $random->getInt(1, 4)),
            2 => $random->getInt(0, 9).$this->words($random, 1, 3, '-'),
            3 => "caf\u{e9}-".$this->pick($random, ServiceProbeRegistry::PROBE_IDENTIFIERS),
            4 => "\x0c".$this->pick($random, ServiceProbeRegistry::PROBE_IDENTIFIERS),
            default => $this->words($random, 2, 8, ' '),
        };
    }
}
