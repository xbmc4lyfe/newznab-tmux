<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Enums\LookupMode;
use App\Enums\QueueType;
use App\Enums\RegistrationStatus;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustEnumsIncidentImpactStatusLookupModeQueueTypeRegistrationW62ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        IncidentImpactEnum::class => 'planIncidentImpactBatch',
        IncidentStatusEnum::class => 'planIncidentStatusBatch',
        LookupMode::class => 'planLookupModeBatch',
        QueueType::class => 'planQueueTypeBatch',
        RegistrationStatus::class => 'planRegistrationStatusBatch',
    ];

    /** Header words before the per-record rows (one word per record). */
    private const HEADER = [
        IncidentImpactEnum::class => IncidentImpactEnum::W62_IMPACT_HEADER,
        IncidentStatusEnum::class => IncidentStatusEnum::W62_STATUS_HEADER,
        LookupMode::class => LookupMode::W62_LOOKUP_HEADER,
        QueueType::class => QueueType::W62_QUEUE_HEADER,
        RegistrationStatus::class => RegistrationStatus::W62_REGISTRATION_HEADER,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base 309185f3c plus this
     * change, seed 626, 50 warmups and 1,000 pairs/workload, four runs (w626-benchmark-*.json in system tmp).
     * The per-metric minimum over the runs of common mean/p99 then uncommon mean/p99 speedups (PHP time / Rust
     * time); a default is Rust only when all four reach 1.5x. All five enums clear 1.5x on every metric; the
     * uncommon p99 (NUL-holding and oversized tokens dominated by marshaling) has the narrowest margin.
     */
    private const QUALIFICATION = [
        IncidentImpactEnum::class => [5.234, 3.158, 3.523, 2.454],
        IncidentStatusEnum::class => [4.644, 4.138, 3.730, 2.920],
        LookupMode::class => [4.813, 3.646, 3.367, 2.352],
        QueueType::class => [6.607, 4.203, 4.313, 3.061],
        RegistrationStatus::class => [4.411, 3.176, 3.276, 2.333],
    ];

    private const IMPACT_ODD = ['None', 'Minor', 'Major', 'Critical', ' major ', 'CRITICAL', 'ok', 'Operational', 'degraded', 'Partial-Outage', 'SEV1', ' p2 ',
        'low', 'high', 'down', 'outage', "3\n", '0', '4', '-1', '', ' ', 'none ', 'catastrophic', 'major outage', "major\0", 'MINOR'];

    private const STATUS_ODD = ['Investigating', 'Identified', 'Monitoring', 'Resolved', ' resolved ', 'OPEN', 'triage', 'Acknowledged', 'diagnosed', 'fixing',
        'Root-Caused', 'watching', 'mitigated', 'recovering', 'closed', 'FIXED', 'done', 'completed', '', ' ', "\t", '0', '1', 'reopened', 'postmortem',
        "resolved\0", 'investigating '];

    private const LOOKUP_ODD = ['Disabled', 'All', 'Renamed', ' all ', 'ALL RELEASES', 'renamed releases only', 'Renamed-Only', 'renamed_only', 'rename', 'off',
        'ON', 'true', 'false', 'yes', 'no', 'none', 'every', 'enabled', 'disable', '3', '-1', '00', ' 1', '', "1\0", 'all-releases', 'disabled'];

    private const QUEUE_ODD = ['NONE', 'SABNZBD', 'NZBGET', 'None', 'SABnzbd', 'NZBGet', ' sabnzbd ', 'SAB', 'sabnzbd+', 'Nzb-Get', 'nzb_get', 'off', 'disabled',
        'no', 'rtorrent', 'qbittorrent', '3', '-1', '01', '', ' ', "2\0", 'nzbget ', 'sabnzbd'];

    private const REGISTRATION_ODD = ['Open', 'Invite', 'Closed', ' open ', 'PUBLIC', 'enabled', 'yes', 'true', 'on', 'invite-only', 'Invite_Only', 'invitation',
        'invites', 'closed', 'disabled', 'off', 'no', 'false', 'private', '3', '-1', '01', '', ' ', "0\0", 'waitlist', 'invite '];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isEnum(), $class);
            foreach (['usesW62RustFfi', 'withW62RustFfi', 'w62RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w62RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW62RustFfi', 'withW62RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W62_RUST_FFI_DEFAULT')?->isPublic() ?? false, $class);
            self::assertIsBool($reflection->getConstant('W62_RUST_FFI_DEFAULT'), $class);
            $cdefs = array_filter($reflection->getReflectionConstants(), static fn ($constant): bool => preg_match('/^W62_\w+_FFI_CDEF$/', $constant->getName()) === 1);
            self::assertCount(1, $cdefs, $class);
            $cdef = array_values($cdefs)[0];
            self::assertTrue($cdef->isPrivate(), $class);
            self::assertStringContainsString('nntmux_w626_', (string) $cdef->getValue(), $class);
            self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertContains($property->getName(), ['name', 'value'], $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W62_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW62RustFfi(), $class);
            if (! $expected) {
                self::assertFalse($class::usesW62RustFfi(), $class.' retains PHP by default');
            }
            foreach ([true, false] as $enabled) {
                $seen = $class::withW62RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW62RustFfi(! $enabled, static fn (): bool => $class::usesW62RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W62_RUST_FFI_DEFAULT'), $other::usesW62RustFfi());
                        }
                    }

                    return $class::usesW62RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW62RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW62RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW62RustFfi(), $class);
        }
    }

    public function test_existing_behavior_is_unchanged(): void
    {
        self::assertSame(['none', 'minor', 'major', 'critical'], IncidentImpactEnum::valuesPhp());
        self::assertSame('Critical', IncidentImpactEnum::Critical->labelPhp());
        self::assertSame(IncidentImpactEnum::Major, IncidentImpactEnum::from('major'));
        self::assertFalse(IncidentImpactEnum::usesRustFfi());
        self::assertSame(['investigating', 'identified', 'monitoring', 'resolved'], IncidentStatusEnum::valuesPhp());
        self::assertSame('Monitoring', IncidentStatusEnum::Monitoring->labelPhp());
        self::assertFalse(IncidentStatusEnum::usesRustFfi());
        self::assertSame(3, \count(LookupMode::cases()));
        self::assertSame('Renamed releases only', LookupMode::Renamed->labelPhp());
        self::assertSame(LookupMode::All, LookupMode::from(1));
        self::assertFalse(LookupMode::usesRustFfi());
        self::assertSame('SABnzbd', QueueType::SABNZBD->labelPhp());
        self::assertSame(QueueType::NZBGET, QueueType::tryFromLabelPhp('NZBGet'));
        self::assertNull(QueueType::tryFromLabelPhp('nzbget'));
        self::assertFalse(QueueType::usesRustFfi());
        self::assertSame('Invite', RegistrationStatus::Invite->labelPhp());
        self::assertSame(RegistrationStatus::Closed, RegistrationStatus::tryFromLabelPhp('Closed'));
        self::assertSame(RegistrationStatus::Open, RegistrationStatus::from(0));
        self::assertFalse(RegistrationStatus::usesRustFfi());
    }

    public function test_incident_impact_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(IncidentImpactEnum::class, [[], []]);
        self::assertSame([0, -1, -1], [$empty[0], $empty[16], $empty[17]]);
        $out = $this->assertParity(IncidentImpactEnum::class, [['none', 'Major', ' SEV1 ', 'minor', '??'], [9_999, 61, 61, -5, 300]]);
        self::assertSame([1, 1, 1, 1, 1, 2, 1, 1, 1, 2, 1, 0, 2], \array_slice($out, 1, 13));
        self::assertSame([3, 3, 1, 3, 10_421], \array_slice($out, 14, 5));
        self::assertSame(2 | 1 << 3 | 1 << 5 | 1 << 7 | 1 << 8 | 60 << 9 | 61 << 18 | 5 << 49, $out[IncidentImpactEnum::W62_IMPACT_HEADER + 1]);
        $ages = [PHP_INT_MIN, -1, 0, 1, 15, 16, 30, 31, 60, 61, 120, 121, 240, 241, 480, 481, 960, 961, 2_147_483_647, 2_147_483_648, PHP_INT_MAX];
        $impacts = [];
        foreach ([...IncidentImpactEnum::valuesPhp(), ...self::IMPACT_ODD] as $impact) {
            foreach ($ages as $age) {
                $impacts[] = [$impact, $age];
            }
        }
        $this->assertParity(IncidentImpactEnum::class, [array_column($impacts, 0), array_column($impacts, 1)]);
        $this->assertParity(IncidentImpactEnum::class, [[0, 1, 2, 3, 4, str_repeat('x', 300), "a\0b\0"], [100]]);
        $this->assertParity(IncidentImpactEnum::class, [['k' => 'major', 'j' => 'critical'], [9 => 30, 3 => 99, 1 => 5]]);
    }

    public function test_incident_status_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(IncidentStatusEnum::class, [[], []]);
        self::assertSame([0, -1], [$empty[0], $empty[20]]);
        $out = $this->assertParity(IncidentStatusEnum::class, [
            ['investigating', 'Resolved', 'resolved', ' closed ', '', 'monitoring'], ['monitoring', 'investigating', 'identified', '', 'identified', 'bogus'],
        ]);
        self::assertSame([1, 0, 1, 3, 1], \array_slice($out, 1, 5));
        self::assertSame([1, 1, 1, 1, 1, 1], \array_slice($out, 10, 6));
        self::assertSame([2, 3, 3, 1, 2], \array_slice($out, 16, 5));
        self::assertSame(3 | 1 << 3 | 0 << 5 | 2 << 10 | 0 << 14 | 1 << 17 | 8 << 18 | 13 << 26, $out[IncidentStatusEnum::W62_STATUS_HEADER + 1]);
        $tokens = [...IncidentStatusEnum::valuesPhp(), ...self::STATUS_ODD];
        $pairs = [];
        foreach ($tokens as $from) {
            foreach ($tokens as $to) {
                $pairs[] = [$from, $to];
            }
        }
        $this->assertParity(IncidentStatusEnum::class, [array_column($pairs, 0), array_column($pairs, 1)]);
        $this->assertParity(IncidentStatusEnum::class, [[1, 'resolved', str_repeat('y', 400), "\0"], ['identified']]);
        $this->assertParity(IncidentStatusEnum::class, [['a' => 'resolved', 'b' => 'monitoring'], [5 => 'investigating', 2 => 'identified', 7 => 'x']]);
    }

    public function test_lookup_mode_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(LookupMode::class, [[], [], [], 0]);
        self::assertSame([0, -1], [$empty[0], $empty[14]]);
        $out = $this->assertParity(LookupMode::class, [['1', 'Renamed', ' OFF ', 'x'], [500, 500, 500, 500], [10, 900, 7, 7], 100]);
        self::assertSame([1, 1, 1, 1], \array_slice($out, 1, 4));
        self::assertSame([2, 1_000, 200, 800, 2, 0], \array_slice($out, 9, 6));
        self::assertSame(1 | 1 << 4 | 1 << 5 | 100 << 6 | 500 << 30 | 1 << 54, $out[LookupMode::W62_LOOKUP_HEADER]);
        $modes = [0, 1, 2, '0', '1', '2', ...self::LOOKUP_ODD];
        $counts = [PHP_INT_MIN, -1, 0, 1, 99, 100, 101, (1 << 24) - 1, 1 << 24, PHP_INT_MAX, 5_000];
        foreach ([PHP_INT_MIN, -1, 0, 1, 100, (1 << 24) - 1, PHP_INT_MAX] as $limit) {
            $rows = [];
            foreach ($modes as $at => $mode) {
                foreach ($counts as $i => $candidates) {
                    $rows[] = [$mode, $candidates, $counts[($i + $at) % \count($counts)]];
                }
            }
            $this->assertParity(LookupMode::class, [array_column($rows, 0), array_column($rows, 1), array_column($rows, 2), $limit]);
        }
        $this->assertParity(LookupMode::class, [['k' => 'All', 'j' => 2, 'i' => 'renamed'], [4 => 50], ['x' => 9, 'y' => 70, 'z' => 1, 'w' => 3], 10]);
    }

    public function test_queue_type_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(QueueType::class, [[], [], []]);
        self::assertSame([0, -1], [$empty[0], $empty[17]]);
        $out = $this->assertParity(QueueType::class, [['SABnzbd', '2', ' nzb-get ', 'rtorrent'], [0, 99, 3, 4], [2, 0, 9_000, 1]]);
        self::assertSame([3, 39, 600, 3, 3], \array_slice($out, 13, 5));
        self::assertSame(1 | 2 << 2 | 1 << 5 | 8 << 7 | 1 << 11 | 4 << 12 | 5 << 18 | 7 << 28, $out[QueueType::W62_QUEUE_HEADER]);
        $types = [0, 1, 2, '0', '1', '2', ...self::QUEUE_ODD];
        $values = [PHP_INT_MIN, -1, 0, 1, 4, 5, 6, 8, 16, 17, 32, 33, 90, 120, 599, 600, 601, 900, 901, PHP_INT_MAX];
        $rows = [];
        foreach ($types as $at => $type) {
            foreach ($values as $i => $workers) {
                $rows[] = [$type, $workers, $values[($i * 7 + $at) % \count($values)]];
            }
        }
        $this->assertParity(QueueType::class, [array_column($rows, 0), array_column($rows, 1), array_column($rows, 2)]);
        $this->assertParity(QueueType::class, [['k' => 'SABnzbd', 'j' => 2], [7 => 40], ['x' => 1, 'y' => 2, 'z' => 3]]);
    }

    public function test_registration_status_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(RegistrationStatus::class, [[], [], -1]);
        self::assertSame([0, -1, -1, -1], [$empty[0], $empty[23], $empty[24], $empty[25]]);
        $out = $this->assertParity(RegistrationStatus::class, [['0', 'Invite', 'invite', '2', ' Open '], [0, 1, 2, 1, 9], 1]);
        self::assertSame([1, 0, 0, 1, 1, 0, 2], \array_slice($out, 14, 7));
        self::assertSame([1, 0, 0, 1, 0], \array_slice($out, 21, 5));
        $unlimited = $this->assertParity(RegistrationStatus::class, [['0', 'Invite', 'invite', '2', ' Open '], [0, 1, 2, 1, 9], -7]);
        self::assertSame([3, 1, 0, 2, -1], \array_slice($unlimited, 21, 5));
        self::assertSame(1 | 1 << 2 | 1 << 4 | 1 << 7 | 1 << 10 | 1 << 11 | 6 << 12, $unlimited[RegistrationStatus::W62_REGISTRATION_HEADER + 1]);
        $statuses = [0, 1, 2, '0', '1', '2', ...self::REGISTRATION_ODD];
        $invites = [PHP_INT_MIN, -1, 0, 1, 2, 3, 4, PHP_INT_MAX, 1, 1, 0];
        $rows = [];
        foreach ($statuses as $at => $status) {
            foreach ($invites as $i => $invite) {
                $rows[] = [$status, $invites[($i + $at) % \count($invites)]];
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 7, 100, (1 << 40) - 1, 1 << 40, PHP_INT_MAX] as $seats) {
            $this->assertParity(RegistrationStatus::class, [array_column($rows, 0), array_column($rows, 1), $seats]);
        }
        $this->assertParity(RegistrationStatus::class, [['k' => 'Invite', 'j' => 'Open'], [3 => 1], 1]);
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 626];
            yield $short.' uncommon' => [$class, 384, true, 627];
            yield $short.' large common' => [$class, 2_048, false, 628];
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $out = $this->assertParity($class, $this->benchmarkArgs($class, $count, $uncommon, $seed));
        self::assertSame($count, $out[0]);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $checked = array_fill_keys(array_keys(self::WAVE), 0);
        for ($round = 0; $round < 40; $round++) {
            foreach (array_keys(self::WAVE) as $class) {
                $count = (new Randomizer(new Mt19937($round)))->getInt(0, 96);
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_620 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set NNTMUX_W626_BENCH=1): seed 626, 8 fixture variants, 50 warmups and
     * NNTMUX_W626_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 aliased, padded, unknown,
     * NUL-holding, oversized or extreme records. Fixtures are built outside timing and parity is asserted on
     * every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99. The summary
     * and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W626_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W626_BENCH=1 to run the paired Wave 62.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W626_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(626));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 626 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $call = $variants[$variant];
                        // The routing scope is set outside the timed region; the public batch call (its own
                        // dispatch, marshaling, FFI and unpacking) is timed inside it.
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW62RustFfi($native, static function () use ($call): array {
                            $start = hrtime(true);
                            $result = $call();

                            return [$result, hrtime(true) - $start];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0] ? 'rust-first' : 'php-first', $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil($pairs * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => intdiv(array_sum($php), $pairs), 'rust_mean_ns' => intdiv(array_sum($rust), $pairs),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 626, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w626-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW62.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW62RustFfi()) {
                self::assertSame($eligible, $class::usesW62RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
            }
        }
    }

    /**
     * Arguments of the class's batch method for one fixture.
     *
     * @param  class-string  $class
     * @return list<mixed>
     */
    private function benchmarkArgs(string $class, int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));

        return match ($class) {
            IncidentImpactEnum::class => $this->impactBatch($random, $count, $uncommon),
            IncidentStatusEnum::class => $this->statusBatch($random, $count, $uncommon),
            LookupMode::class => $this->lookupBatch($random, $count, $uncommon),
            QueueType::class => $this->queueBatch($random, $count, $uncommon),
            default => $this->registrationBatch($random, $count, $uncommon),
        };
    }

    /**
     * @param  class-string  $class
     * @return Closure(): list<int>
     */
    private function benchmarkCall(string $class, int $count, bool $uncommon, int $seed): Closure
    {
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $method = self::WAVE[$class];

        return static fn (): array => $class::$method(...$args);
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

    /**
     * An odd token: listed aliases or unknowns, NUL-holding, random bytes or oversized repeats of one.
     *
     * @param  list<string>  $odd
     */
    private function oddToken(Randomizer $random, array $odd): string
    {
        $token = $this->pick($random, $odd);

        return match ($random->getInt(0, 7)) {
            0 => $token."\0".$token,
            1 => substr($random->getBytes(24), 0, $random->getInt(0, 24)),
            2 => str_repeat($token === '' ? 'x' : $token, $random->getInt(40, 120)),
            3 => ' '.strtoupper($token)."\t",
            default => $token,
        };
    }

    /**
     * Stored incident impacts with ages in minutes; uncommon ones are case names, aliases, unknown,
     * NUL-holding or oversized tokens and extreme ages.
     *
     * @return list<mixed>
     */
    private function impactBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $impacts = $ages = [];
        for ($i = 0; $i < $count; $i++) {
            $impact = $this->pick($random, ['minor', 'minor', 'minor', 'major', 'major', 'critical', 'none']);
            $age = $random->getInt(0, 600);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0, 1, 2 => $impact = $this->oddToken($random, self::IMPACT_ODD),
                    3 => $age = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    4 => $age = $random->getInt(-10, 4_000_000_000),
                    default => null,
                };
            }
            $impacts[] = $impact;
            $ages[] = $age;
        }

        return [$impacts, $ages];
    }

    /**
     * Stored incident statuses with requested next statuses (mostly none, the next stage or a reopen);
     * uncommon ones are case names, aliases, blank, unknown, NUL-holding or oversized tokens.
     *
     * @return list<mixed>
     */
    private function statusBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $values = IncidentStatusEnum::valuesPhp();
        $statuses = $targets = [];
        for ($i = 0; $i < $count; $i++) {
            $at = $random->getInt(0, 3);
            $status = $values[$at];
            $target = match ($random->getInt(0, 4)) {
                0 => '',
                1 => $status,
                2 => $values[$random->getInt(0, 3)],
                default => $values[min($at + 1, 3)],
            };
            if ($uncommon) {
                match ($random->getInt(0, 4)) {
                    0, 1 => $status = $this->oddToken($random, self::STATUS_ODD),
                    2, 3 => $target = $this->oddToken($random, self::STATUS_ODD),
                    default => null,
                };
            }
            $statuses[] = $status;
            $targets[] = $target;
        }

        return [$statuses, $targets];
    }

    /**
     * Stored integer lookup settings with candidate and renamed counts and a run limit; uncommon ones are
     * case names, aliases, unknown, NUL-holding or oversized tokens and extreme counts and limits.
     *
     * @return list<mixed>
     */
    private function lookupBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $modes = $candidates = $renamed = [];
        for ($i = 0; $i < $count; $i++) {
            $mode = $this->pick($random, [1, 1, 1, 2, 2, 0]);
            $all = $random->getInt(0, 5_000);
            $named = $random->getInt(0, $all);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0, 1, 2 => $mode = $this->oddToken($random, self::LOOKUP_ODD),
                    3 => $all = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    4 => $named = $random->getInt(-5, 1 << 26),
                    default => null,
                };
            }
            $modes[] = $mode;
            $candidates[] = $all;
            $renamed[] = $named;
        }
        $limit = $uncommon ? $this->pick($random, [PHP_INT_MIN, -1, 0, 1, 1 << 30, PHP_INT_MAX, 250]) : $this->pick($random, [0, 100, 500, 1_000]);

        return [$modes, $candidates, $renamed, $limit];
    }

    /**
     * Stored integer queue types with requested worker counts and timeouts; uncommon ones are case names,
     * labels, aliases, unknown, NUL-holding or oversized tokens and extreme requests.
     *
     * @return list<mixed>
     */
    private function queueBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $types = $workers = $timeouts = [];
        for ($i = 0; $i < $count; $i++) {
            $type = $this->pick($random, [1, 1, 1, 2, 2, 0]);
            $concurrency = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 20);
            $timeout = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(30, 1_000);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0, 1, 2 => $type = $this->oddToken($random, self::QUEUE_ODD),
                    3 => $concurrency = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    4 => $timeout = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $types[] = $type;
            $workers[] = $concurrency;
            $timeouts[] = $timeout;
        }

        return [$types, $workers, $timeouts];
    }

    /**
     * Signup attempts under stored integer registration statuses with invite states and a seat budget;
     * uncommon ones are case names, aliases, unknown, NUL-holding or oversized tokens, invalid invite states
     * and extreme budgets.
     *
     * @return list<mixed>
     */
    private function registrationBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $statuses = $invites = [];
        for ($i = 0; $i < $count; $i++) {
            $status = $this->pick($random, [0, 0, 0, 1, 1, 2]);
            $invite = $this->pick($random, [0, 0, 1, 1, 1, 2, 3]);
            if ($uncommon) {
                match ($random->getInt(0, 4)) {
                    0, 1, 2 => $status = $this->oddToken($random, self::REGISTRATION_ODD),
                    3 => $invite = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $statuses[] = $status;
            $invites[] = $invite;
        }
        $seats = $uncommon ? $this->pick($random, [PHP_INT_MIN, 0, 1, 1 << 41, PHP_INT_MAX, 50]) : $this->pick($random, [-1, -1, 200, 1_000]);

        return [$statuses, $invites, $seats];
    }

    /**
     * PHP routing, Rust routing, the PHP and native methods agree; returns the PHP result.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function assertParity(string $class, array $args): array
    {
        $method = self::WAVE[$class];
        $php = $class::withW62RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW62RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (IncidentImpactEnum::planIncidentImpactBatchNative([], []) === null
            || IncidentStatusEnum::planIncidentStatusBatchNative([], []) === null
            || LookupMode::planLookupModeBatchNative([], [], []) === null
            || QueueType::planQueueTypeBatchNative([], [], []) === null
            || RegistrationStatus::planRegistrationStatusBatchNative([], []) === null
        ) {
            self::markTestSkipped('The Wave 62.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
