<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdminDashboardSnapshotService;
use App\Services\RedisMonitorService;
use App\Services\RegistrationStatusService;
use App\Services\SiteLogoService;
use App\Services\SiteStatusService;
use Closure;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustAdminDashboardRedisRegLogoStatusW51ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        AdminDashboardSnapshotService::class => 'bucketActivityTimestamps',
        RedisMonitorService::class => 'keyspaceKeyCounts',
        RegistrationStatusService::class => 'decideBatch',
        SiteLogoService::class => 'unmanagedPathIndices',
        SiteStatusService::class => 'downtimeSummary',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of three runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 51, 200 pairs
     * per workload after 20 warmups, randomized call order, each side the fastest of three
     * back-to-back calls, release Rust, PHP 8.5.12 CLI without opcache, Apple Silicon macOS
     * (shared host, load ~8-9).
     * Timings include dispatch, packing, the FFI call and unpack('P*'). A class defaults to Rust
     * only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        AdminDashboardSnapshotService::class => [2.235, 2.604, 1.681, 1.829],
        RedisMonitorService::class => [2.733, 2.840, 2.807, 2.227],
        RegistrationStatusService::class => [8.573, 8.570, 8.584, 6.994],
        SiteLogoService::class => [2.113, 2.048, 2.104, 1.551],
        SiteStatusService::class => [8.561, 9.492, 5.512, 5.980],
    ];

    private const PAIRS = 200;

    private const WARMUPS = 20;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 51;

    private const NOW = 1_761_400_000;

    private const DAY = 86_400;

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW51RustFfi', 'withW51RustFfi', 'w51RustFfiState', 'w51Buffer', 'w51Decode', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW51RustFfi', 'withW51RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('w51RustFfiState')->isPrivate(), $class);
            $constant = $reflection->getReflectionConstant('W51_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w511_', (string) $reflection->getConstant('W51_FFI_CDEF'));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w51', $property->getName(), $class.' keeps Wave 51 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
        }
    }

    public function test_with_w51_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW51RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW51RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW51RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        self::assertFalse(AdminDashboardSnapshotService::usesRustFfi());
        self::assertFalse(RedisMonitorService::usesRustFfi());
        self::assertFalse(RegistrationStatusService::usesRustFfi());
        self::assertFalse(SiteLogoService::usesRustFfi());
        self::assertFalse(SiteStatusService::$useRustFfi);
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    public function test_mismatched_columns_are_rejected_on_both_routes(): void
    {
        $calls = [
            RegistrationStatusService::class => [[0, 1], [true]],
            SiteStatusService::class => [0, 10, [1], []],
        ];
        foreach ($calls as $class => $args) {
            foreach ([false, true] as $native) {
                try {
                    $class::withW51RustFfi($native, fn (): array => $this->entry($class)(...$args));
                    self::fail($class.' must reject mismatched columns');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('same length', $e->getMessage());
                }
            }
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: int}>
     */
    public static function invalidActivityWindows(): iterable
    {
        yield 'zero width' => [self::NOW, 0, 24];
        yield 'negative width' => [self::NOW, -60, 24];
        yield 'no buckets' => [self::NOW, 3600, 0];
        yield 'too many buckets' => [self::NOW, 1, 4097];
        yield 'span overflow' => [self::NOW, PHP_INT_MAX, 2];
        yield 'start underflow' => [PHP_INT_MIN + 10, 5, 3];
    }

    #[DataProvider('invalidActivityWindows')]
    public function test_invalid_activity_windows_are_rejected_on_both_routes(int $now, int $bucketSeconds, int $bucketCount): void
    {
        foreach ([false, true] as $native) {
            try {
                AdminDashboardSnapshotService::withW51RustFfi($native, static fn (): array => AdminDashboardSnapshotService::bucketActivityTimestamps([1, 2], $now, $bucketSeconds, $bucketCount));
                self::fail('Invalid activity window must be rejected');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Activity bucket', $e->getMessage());
            }
        }
    }

    public function test_activity_bucket_parity(): void
    {
        $this->requireBindings();
        $args = [[100, 99, 96, 95, 91, 90, 101, 0, PHP_INT_MIN, PHP_INT_MAX], 100, 5, 2];
        self::assertSame([5, 0, 3, 1, 2], AdminDashboardSnapshotService::bucketActivityTimestampsPhp(...$args));
        self::assertSame([0, 1, 1, 5, 2, 8, 1, 9, 1], AdminDashboardSnapshotService::bucketActivityTimestampsPhp([5, 1, 9, 5, 2], 10, 1, 10));
        $this->assertParity(AdminDashboardSnapshotService::class, $args);
        $this->assertParity(AdminDashboardSnapshotService::class, [[], self::NOW, 3600, 24]);
        $this->assertParity(AdminDashboardSnapshotService::class, [[PHP_INT_MAX, 0, PHP_INT_MIN + 1], PHP_INT_MAX, PHP_INT_MAX, 1]);
        $this->assertParity(AdminDashboardSnapshotService::class, [[PHP_INT_MIN, PHP_INT_MIN + 1, -1], -1, 1, 4096]);
        $this->assertParity(AdminDashboardSnapshotService::class, [[self::NOW], self::NOW, 1, 1]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(AdminDashboardSnapshotService::class, $this->activityBatch(new Randomizer(new Mt19937(5100 + $i)), $uncommon));
            }
        }
    }

    public function test_redis_keyspace_parity(): void
    {
        $this->requireBindings();
        $specs = [
            'keys=123,expires=0,avg_ttl=0', 'keys=,keys=7', 'subkeys=5', 'keys=0007x', 'kkeys=keys=9', 'keys=99999999999999999999',
            'keys=9223372036854775807', 'keys=9223372036854775808', 'keys=', 'KEYS=1', '', 'keys=٣', "keys=\xFF12", 'expires=3,keys=4',
            'keys=-5', 'keys= 5', str_repeat('keys=x', 100).'keys=42',
        ];
        $expected = [123, 7, 5, 7, 9, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, -1, -1, -1, -1, -1, 4, -1, -1, 42];
        self::assertSame($expected, RedisMonitorService::keyspaceKeyCountsPhp($specs));
        $this->assertParity(RedisMonitorService::class, [$specs]);
        $this->assertParity(RedisMonitorService::class, [[]]);
        $this->assertParity(RedisMonitorService::class, [['']]);
        $this->assertParity(RedisMonitorService::class, [["keys=1\0keys=2", 'keys=3']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(RedisMonitorService::class, $this->keyspaceBatch(new Randomizer(new Mt19937(5200 + $i)), $uncommon));
            }
        }
    }

    public function test_registration_decision_parity(): void
    {
        $this->requireBindings();
        $statuses = [0, 1, 2, 0, 1, 2, 3, -1, PHP_INT_MAX, PHP_INT_MIN];
        $active = [false, false, false, true, true, true, false, true, false, true];
        $open = 1 << 3 | 1 << 4;
        $expected = [$open | 1 << 8, 1 | 1 << 3 | 1 << 5 | 2 << 8, 2 | 1 << 6 | 3 << 8, $open | 1 << 8, $open | 1 << 2, $open | 1 << 2, $open | 1 << 8, $open | 1 << 8, $open | 1 << 8, $open | 1 << 8];
        self::assertSame($expected, RegistrationStatusService::decideBatchPhp($statuses, $active));
        foreach ($statuses as $i => $status) {
            $decision = RegistrationStatusService::decidePhp(RegistrationStatusService::normalizeManualStatusPhp($status), $active[$i]);
            self::assertSame($decision['effective_status'], $expected[$i] & 3);
            self::assertSame(RegistrationStatusService::REASONS[$expected[$i] >> 8], $decision['reason']);
        }
        $this->assertParity(RegistrationStatusService::class, [$statuses, $active]);
        $this->assertParity(RegistrationStatusService::class, [[], []]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(RegistrationStatusService::class, $this->registrationBatch(new Randomizer(new Mt19937(5300 + $i)), $uncommon));
            }
        }
    }

    public function test_site_logo_path_parity(): void
    {
        $this->requireBindings();
        $paths = [
            'site-logos/a.png', 'site-logos/', 'site-logos/../x', 'site-logos', '', '/site-logos/a', 'site-logos/a..png', 'site-logos/.hidden',
            'SITE-LOGOS/a.png', 'site-logos//a', 'site-logos/é.png', 'site-logos/'.str_repeat('a', 500), 'x/site-logos/a', '..', 'site-logos/.',
        ];
        $expected = [2, 3, 4, 5, 6, 8, 12, 13];
        self::assertSame($expected, SiteLogoService::unmanagedPathIndicesPhp($paths));
        foreach ($paths as $i => $path) {
            self::assertSame(! \in_array($i, $expected, true), SiteLogoService::isManagedPathPhp($path), $path);
        }
        $this->assertParity(SiteLogoService::class, [$paths]);
        $this->assertParity(SiteLogoService::class, [[]]);
        $this->assertParity(SiteLogoService::class, [['']]);
        $this->assertParity(SiteLogoService::class, [['site-logos/a.png', 'site-logos/b.png']]);
        $this->assertParity(SiteLogoService::class, [["site-logos/a\0b", 'site-logos/c']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(SiteLogoService::class, $this->logoBatch(new Randomizer(new Mt19937(5400 + $i)), $uncommon));
            }
        }
    }

    public function test_site_status_downtime_parity(): void
    {
        $this->requireBindings();
        $args = [2, 60, [5, 0, 30, 12, 50, 61, 3], [10, 8, null, 20, 70, null, 3]];
        self::assertSame([46, 2, 10, 12, 20, 30, 60], SiteStatusService::downtimeSummaryPhp(...$args));
        $this->assertParity(SiteStatusService::class, $args);
        $this->assertParity(SiteStatusService::class, [0, 10, [], []]);
        $this->assertParity(SiteStatusService::class, [10, 0, [1, 5], [null, 20]]);
        $this->assertParity(SiteStatusService::class, [0, 100, [10, 10, 10], [20, 40, 15]]);
        $this->assertParity(SiteStatusService::class, [0, 100, [10, 20], [20, 30]]);
        $this->assertParity(SiteStatusService::class, [PHP_INT_MIN, -1, [PHP_INT_MIN, -5], [PHP_INT_MIN + 1, null]]);
        $this->assertParity(SiteStatusService::class, [0, PHP_INT_MAX, [0], [null]]);
        foreach ([false, true] as $native) {
            try {
                SiteStatusService::withW51RustFfi($native, static fn (): array => SiteStatusService::downtimeSummary(PHP_INT_MIN, PHP_INT_MAX, [PHP_INT_MIN], [null]));
                self::fail('An overflowing downtime sum must be rejected');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('overflows', $e->getMessage());
            }
        }
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(SiteStatusService::class, $this->downtimeBatch(new Randomizer(new Mt19937(5500 + $i)), $uncommon));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW51RustFfi($native, static function () use ($call, $args): array {
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
        $artifact = sys_get_temp_dir().'/w511-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW51.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW51RustFfi'];

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
        $php = $class::withW51RustFfi(false, static fn (): array => $call(...$args));
        $native = $class::withW51RustFfi(true, static fn (): array => $call(...$args));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
    }

    /** @return list<mixed> */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            AdminDashboardSnapshotService::class => $this->activityBatch($random, $uncommon),
            RedisMonitorService::class => $this->keyspaceBatch($random, $uncommon),
            RegistrationStatusService::class => $this->registrationBatch($random, $uncommon),
            SiteLogoService::class => $this->logoBatch($random, $uncommon),
            default => $this->downtimeBatch($random, $uncommon),
        };
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $one = pack('P', 0);
        $calls = [
            AdminDashboardSnapshotService::class => ['nntmux_w511_admin_dashboard_activity_buckets_batch', [pack('P', 8), 8, 1, 10, 5, 2], 3],
            RedisMonitorService::class => ['nntmux_w511_redis_keyspace_keys_batch', ['keys=1', 6, 1], 1],
            RegistrationStatusService::class => ['nntmux_w511_registration_status_decide_batch', [$one, 8, "\x01", 1, 1], 1],
            SiteLogoService::class => ['nntmux_w511_site_logo_unmanaged_batch', ['..', 2, 1], 1],
            SiteStatusService::class => ['nntmux_w511_site_status_downtime_batch', [$one, 8, $one, 8, pack('V', 0), 4, 1, 0, 10], 3],
        ];
        foreach ($calls as $class => [$symbol, $args, $words]) {
            $buffer = (new ReflectionMethod($class, 'w51Buffer'))->invoke(null, 4);
            self::assertIsArray($buffer, $class.' must load the admin_status_w51 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[4]');
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$args, $out, 4]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, $out, $words - 1]), $symbol.' short capacity');
        }
    }

    /**
     * Calls an export declared by a class's W51_FFI_CDEF.
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

    /** @return array{0: list<int>, 1: int, 2: int, 3: int} */
    private function activityBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        if (! $uncommon) {
            $timestamps = [];
            for ($i = 0; $i < $count; $i++) {
                $timestamps[] = self::NOW - $random->getInt(0, self::DAY - 1);
            }

            return [$timestamps, self::NOW, 3600, 24];
        }
        [$width, $buckets] = [[60, 60], [3600, 168], [1, 4096], [86_400, 30], [7, 13]][$random->getInt(0, 4)];
        $span = $width * $buckets;
        $timestamps = [];
        for ($i = 0; $i < $count; $i++) {
            $timestamps[] = match ($random->getInt(0, 6)) {
                0 => self::NOW + $random->getInt(1, 10_000),
                1 => self::NOW - $span - $random->getInt(0, 10_000),
                2 => self::NOW - $span,
                3 => self::NOW,
                4 => $random->getInt(0, 1) === 0 ? PHP_INT_MIN : PHP_INT_MAX,
                default => self::NOW - $random->getInt(0, $span - 1),
            };
        }

        return [$timestamps, self::NOW, $width, $buckets];
    }

    /** @return array{0: list<string>} */
    private function keyspaceBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $specs = [];
        for ($i = 0; $i < $count; $i++) {
            $common = 'keys='.$random->getInt(0, 50_000_000).',expires='.$random->getInt(0, 1_000_000).',avg_ttl='.$random->getInt(0, 86_400_000).',subexpiry=0';
            $specs[] = ! $uncommon ? $common : match ($random->getInt(0, 7)) {
                0 => 'expires=0,avg_ttl=0',
                1 => 'keys=,keys='.$random->getInt(0, 999),
                2 => 'keys='.str_repeat('9', $random->getInt(19, 40)),
                3 => str_repeat('keys=x,', $random->getInt(10, 60)).'keys='.$random->getInt(0, 99),
                4 => $random->getBytes($random->getInt(1, 48)),
                5 => 'db0:keys='.$random->getInt(0, 9).'é,expires=1',
                6 => '',
                default => $common,
            };
        }

        return [array_map(static fn (string $spec): string => str_replace("\0", '0', $spec), $specs)];
    }

    /** @return array{0: list<int>, 1: list<bool>} */
    private function registrationBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $statuses = $active = [];
        for ($i = 0; $i < $count; $i++) {
            $statuses[] = ! $uncommon ? $random->getInt(0, 2) : match ($random->getInt(0, 4)) {
                0 => $random->getInt(3, 100),
                1 => $random->getInt(-100, -1),
                2 => $random->getInt(0, 1) === 0 ? PHP_INT_MIN : PHP_INT_MAX,
                default => $random->getInt(0, 2),
            };
            $active[] = $random->getInt(0, $uncommon ? 1 : 9) === 0;
        }

        return [$statuses, $active];
    }

    /** @return array{0: list<string>} */
    private function logoBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $common = 'site-logos/'.bin2hex($random->getBytes(20)).'.'.$this->pick($random, ['png', 'jpg', 'svg', 'webp']);
            $paths[] = ! $uncommon ? $common : match ($random->getInt(0, 7)) {
                0 => 'site-logos/../'.bin2hex($random->getBytes(8)),
                1 => '',
                2 => 'site-logos/'.str_repeat('a', $random->getInt(200, 600)).'..',
                3 => $this->pick($random, ['site-logos', '/site-logos/a.png', 'SITE-LOGOS/a.png', 'logos/a.png', '..']),
                4 => 'site-logos/'.$this->pick($random, ['é', '漢字', 'a b', '.hidden']).'.png',
                5 => 'site-logos//'.bin2hex($random->getBytes(4)),
                default => $common,
            };
        }

        return [$paths];
    }

    /** @return array{0: int, 1: int, 2: list<int>, 3: list<int|null>} */
    private function downtimeBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $windowStart = self::NOW - 30 * self::DAY;
        $starts = $ends = [];
        $cursor = $windowStart - self::DAY;
        for ($i = 0; $i < $count; $i++) {
            if (! $uncommon) {
                $cursor += $random->getInt(60, 3 * 3600);
                $starts[] = $cursor;
                $ends[] = $random->getInt(0, 30) === 0 ? null : $cursor + $random->getInt(60, 4 * 3600);

                continue;
            }
            $start = match ($random->getInt(0, 4)) {
                0 => $windowStart - $random->getInt(0, 40 * self::DAY),
                1 => self::NOW + $random->getInt(0, self::DAY),
                2 => $windowStart + 3600 * $random->getInt(0, 10),
                default => $random->getInt($windowStart, self::NOW),
            };
            $starts[] = $start;
            $ends[] = match ($random->getInt(0, 4)) {
                0 => null,
                1 => $start - $random->getInt(0, 3600),
                2 => $start + $random->getInt(0, 20 * self::DAY),
                default => $start + $random->getInt(0, 6 * 3600),
            };
        }

        return [$windowStart, self::NOW, $starts, $ends];
    }
}
