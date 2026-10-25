<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Backfill\BackfillConfig;
use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\PartHandler;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;

final class RustBackfillBinariesConfigServiceHeaderStorageW52ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        BackfillConfig::class => 'safeBackfillDateDays',
        BackfillService::class => 'articleDateTimestamps',
        BinariesConfig::class => 'scanRangeEnds',
        BinariesService::class => 'nearestOverviewIndex',
        HeaderStorageService::class => 'headerValidationCodes',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), the per-metric minimum
     * of three runs of test_paired_randomized_common_and_uncommon_benchmarks: seed 52, 200 pairs
     * per workload after 20 warmups, randomized call order, each side the fastest of three
     * back-to-back calls, release Rust, PHP 8.5.12 CLI without opcache, Apple Silicon macOS
     * (shared host, load ~7-8).
     * Timings include dispatch, packing, the FFI call and unpack('P*'). A class defaults to Rust
     * only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        BackfillConfig::class => [7.568, 5.566, 4.294, 4.450],
        BackfillService::class => [17.448, 11.398, 2.747, 2.216],
        BinariesConfig::class => [2.722, 3.278, 2.831, 3.094],
        BinariesService::class => [2.140, 2.338, 1.880, 2.258],
        HeaderStorageService::class => [2.448, 1.964, 1.076, 1.060],
    ];

    private const PAIRS = 200;

    private const WARMUPS = 20;

    /** Back-to-back calls per side of a pair; the fastest is the sample (drops preemption spikes). */
    private const REPEATS = 3;

    private const SEED = 52;

    private const NOW = 1_761_400_000;

    private const DAY = 86_400;

    private const ZONES = ['+0000', '+0100', '+0200', '-0500', '-0800', '+0530', '+1000', '-0330'];

    public function test_wave_members_exist_and_defaults_match_qualification(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW52RustFfi', 'withW52RustFfi', 'w52RustFfiState', 'w52Buffer', 'w52Decode', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            foreach (['usesW52RustFfi', 'withW52RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->getMethod($method)->isStatic() && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
            self::assertTrue($reflection->getMethod('w52RustFfiState')->isPrivate(), $class);
            $constant = $reflection->getReflectionConstant('W52_RUST_FFI_DEFAULT');
            self::assertNotFalse($constant, $class);
            self::assertTrue($constant->isPrivate(), $class);
            self::assertIsBool($constant->getValue(), $class);
            self::assertSame($constant->getValue(), $this->uses($class), $class);
            self::assertStringContainsString('nntmux_w521_', (string) $reflection->getConstant('W52_FFI_CDEF'));
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w52', $property->getName(), $class.' keeps Wave 52 state in function-local statics');
            }

            $speedups = self::QUALIFICATION[$class];
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->uses($class), $class.' default must follow its recorded qualification');
        }
        self::assertTrue((new ReflectionClass(BackfillConfig::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(BinariesConfig::class))->isReadOnly());
    }

    public function test_with_w52_rust_ffi_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->uses($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW52RustFfi($enabled, fn (): array => [
                    $this->uses($class),
                    $class::withW52RustFfi(! $enabled, fn (): bool => $this->uses($class)),
                    $this->uses($class),
                ]);
                self::assertSame([$enabled, ! $enabled, $enabled], $seen, $class);
                self::assertSame($default, $this->uses($class), $class);
            }
            try {
                $class::withW52RustFfi(! $default, static function (): never {
                    throw new InvalidArgumentException('boom');
                });
            } catch (InvalidArgumentException) {
            }
            self::assertSame($default, $this->uses($class), $class.' restores routing after an exception');
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        self::assertFalse(BackfillConfig::usesW421RustFfi());
        self::assertFalse(BackfillService::usesW421RustFfi());
        self::assertFalse(BackfillService::usesRustFfi());
        self::assertFalse(BinariesConfig::usesW411RustFfi());
        self::assertFalse(BinariesConfig::usesRustFfi());
        self::assertFalse(BinariesService::usesW411RustFfi());
        self::assertFalse(BinariesService::usesRustFfi());
        self::assertFalse(HeaderStorageService::usesW411RustFfi());
        self::assertTrue(HeaderStorageService::usesRustFfi());
    }

    public function test_bindings_resolve_and_guard_capacity(): void
    {
        $this->requireBindings();
    }

    public function test_mismatched_header_columns_are_rejected_on_both_routes(): void
    {
        foreach ([false, true] as $native) {
            try {
                HeaderStorageService::withW52RustFfi($native, static fn (): array => HeaderStorageService::headerValidationCodes([1, 2], ['<a@b>']));
                self::fail('Mismatched header columns must be rejected');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('same length', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{0: list<int>, 1: list<int>, 2: int, 3: int, 4: string}>
     */
    public static function invalidScanRanges(): iterable
    {
        yield 'mismatched columns' => [[1, 2], [3], 20, 0, 'same length'];
        yield 'zero buffer' => [[1], [5], 0, 0, 'buffer'];
        yield 'oversized buffer' => [[1], [5], BinariesConfig::W52_MAX_BUFFER + 1, 0, 'buffer'];
        yield 'negative limit' => [[1], [5], 20, -1, 'limit'];
        yield 'oversized limit' => [[1], [5], 20, BinariesConfig::W52_MAX_ARTICLE + 1, 'limit'];
        yield 'negative first' => [[5, -1], [9, 9], 20, 0, 'articles'];
        yield 'oversized newest' => [[5], [BinariesConfig::W52_MAX_ARTICLE + 1], 20, 0, 'articles'];
        yield 'extreme newest' => [[0], [PHP_INT_MAX], 20, 0, 'articles'];
    }

    /**
     * @param  list<int>  $firsts
     * @param  list<int>  $newests
     */
    #[DataProvider('invalidScanRanges')]
    public function test_invalid_scan_ranges_are_rejected_on_both_routes(array $firsts, array $newests, int $buffer, int $limit, string $message): void
    {
        foreach ([false, true] as $native) {
            try {
                BinariesConfig::withW52RustFfi($native, static fn (): array => BinariesConfig::scanRangeEnds($firsts, $newests, $buffer, $limit));
                self::fail('An invalid scan range batch must be rejected');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_safe_backfill_date_parity(): void
    {
        $this->requireBindings();
        $dates = [
            '1970-01-01', '2012-08-14', '2024-02-29', '2023-02-29', '0000-01-01', '0001-01-01', '9999-12-31', '2012-13-01', '2012-00-10',
            '2012-8-14', '2012-08-14 ', ' 2012-08-14', "2012-08-14\n", '2012/08/14', '', '20120814xx', '1900-02-29', '2000-02-29', '٢٠١٢-08-14',
            '1969-12-31', '2012-08-32', '2012-04-31',
        ];
        $expected = [0, 15_566, 19_782, null, null, -719_162, 2_932_896, null, null, null, null, null, null, null, null, null, null, 11_016, null, -1, null, null];
        self::assertSame($expected, BackfillConfig::safeBackfillDateDaysPhp($dates));
        foreach ($dates as $i => $date) {
            $summary = BackfillConfig::summarizeBackfillSettingsBatchPhp([['safeBackFillDate' => $date]]);
            self::assertSame($expected[$i] === null ? 1 : 0, $summary['bad_date'], $date);
            if ($expected[$i] !== null) {
                $midnight = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
                self::assertNotFalse($midnight, $date);
                self::assertSame($expected[$i], intdiv($midnight->getTimestamp(), self::DAY), $date);
            }
        }
        $this->assertParity(BackfillConfig::class, [$dates]);
        $this->assertParity(BackfillConfig::class, [[]]);
        $this->assertParity(BackfillConfig::class, [['']]);
        $this->assertParity(BackfillConfig::class, [["2012-08-14\0", '2012-08-15']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(BackfillConfig::class, $this->safeDateBatch(new Randomizer(new Mt19937(5200 + $i)), $uncommon));
            }
        }
    }

    public function test_article_date_parity(): void
    {
        $this->requireBindings();
        $dates = [
            'Sun, 25 Oct 2026 22:30:33 +0100', 'Mon, 25 Oct 2026 22:30:33 +0100', '25 Oct 2026 22:30:33 +0100', '5 Oct 2026 22:30:33 GMT',
            '05 Oct 2026 22:30:33 UTC', 'Sun, 25 Oct 2026 22:30:33 +0000 (UTC)', 'Sun, 25 Oct 2026 22:30:33 +0100 (EST)', '31 Feb 2026 00:00:00 +0000',
            '29 Feb 2024 23:59:59 -1400', 'Wed, 01 Jan 1000 00:00:00 +0000', '1 jan 2026 00:00:00 +0000', '1 Jan 2026 24:00:00 +0000',
            '1 Jan 2026 00:00:60 +0000', '1 Jan 2026 00:00:00 +1500', '1 Jan 2026 00:00:00 +0099', 'Sun,  25 Oct 2026 22:30:33 +0100',
            '1 Jan 2026 00:00:00 Z', '2026-10-25 22:30:33', '', 'not a date', 'Fri, 31 Dec 9999 23:59:59 -1400', '25 Oct 26 22:30:33 +0100',
        ];
        $expected = [1_792_963_833, 1_793_050_233, 1_792_963_833, 1_791_239_433, 1_791_239_433, 1_792_967_433];
        self::assertSame($expected, \array_slice(BackfillService::articleDateTimestampsPhp($dates), 0, 6));
        $zone = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/New_York', 'Asia/Kolkata'] as $tz) {
                date_default_timezone_set($tz);
                $this->assertParity(BackfillService::class, [$dates]);
            }
        } finally {
            date_default_timezone_set($zone);
        }
        $this->assertParity(BackfillService::class, [[]]);
        $this->assertParity(BackfillService::class, [['']]);
        $this->assertParity(BackfillService::class, [["5 Oct 2026 22:30:33 GMT\0", '5 Oct 2026 22:30:33 GMT']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(BackfillService::class, $this->articleDateBatch(new Randomizer(new Mt19937(5300 + $i)), $uncommon));
            }
        }
    }

    public function test_scan_range_end_parity(): void
    {
        $this->requireBindings();
        $firsts = [100, 100, 100, 100, 200, 5, 0, 0, BinariesConfig::W52_MAX_ARTICLE, 0, 1_000_000];
        $newests = [200, 130, 131, 200, 100, 5, 0, BinariesConfig::W52_MAX_ARTICLE, 0, 3, 1_000_000 + 40_001];
        self::assertSame([180, 115, 116, 180, 200, 5, 0, BinariesConfig::W52_MAX_ARTICLE - 32, BinariesConfig::W52_MAX_ARTICLE, 2, 1_039_980],
            BinariesConfig::scanRangeEndsPhp($firsts, $newests, 20, 0));
        foreach ([[20, 0], [20, 5], [1, 0], [7, 3], [BinariesConfig::W52_MAX_BUFFER, BinariesConfig::W52_MAX_ARTICLE], [20_000, 100_000]] as [$buffer, $limit]) {
            $ends = BinariesConfig::scanRangeEndsPhp($firsts, $newests, $buffer, $limit);
            foreach ($firsts as $i => $first) {
                self::assertSame(BinariesService::existingHeaderRangePhp($first, $newests[$i], $buffer, $limit)['last'], $ends[$i], "{$first}/{$newests[$i]}/{$buffer}/{$limit}");
            }
            $this->assertParity(BinariesConfig::class, [$firsts, $newests, $buffer, $limit]);
        }
        $this->assertParity(BinariesConfig::class, [[], [], 20, 0]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $args = $this->scanRangeBatch(new Randomizer(new Mt19937(5400 + $i)), $uncommon);
                $this->assertParity(BinariesConfig::class, $args);
                $ends = BinariesConfig::scanRangeEndsPhp(...$args);
                foreach ($args[0] as $row => $first) {
                    self::assertSame(BinariesService::existingHeaderRangePhp($first, $args[1][$row], $args[2], $args[3])['last'], $ends[$row]);
                }
            }
        }
    }

    public function test_nearest_overview_parity(): void
    {
        $this->requireBindings();
        $numbers = [
            '0', '+0', '-0', " \t42\r\n", "\x0B-7\x0B", '9223372036854775807', '-9223372036854775808', '9223372036854775808', '-9223372036854775809',
            '00', '007', '+07', '', ' ', '-', '+', '1e3', '12 3', "\x0C1", '0x1A', '10000000000000000000', '--1', ' 0 ', '٣', '1.0', '12345678901',
            42, -1, 0, PHP_INT_MAX, PHP_INT_MIN, "1\xA0",
        ];
        foreach ($numbers as $number) {
            $valid = filter_var($number, FILTER_VALIDATE_INT);
            self::assertSame($valid === false ? false : 0, BinariesService::nearestOverviewIndexPhp([$number], PHP_INT_MIN, PHP_INT_MAX, true), var_export($number, true));
            $this->assertParity(BinariesService::class, [[$number], PHP_INT_MIN, PHP_INT_MAX, true]);
            $this->assertParity(BinariesService::class, [['5', $number], PHP_INT_MIN, PHP_INT_MAX, false]);
        }
        self::assertSame(1, BinariesService::nearestOverviewIndexPhp(['15', '12', ' 12', '20'], 10, 20, true));
        self::assertSame(1, BinariesService::nearestOverviewIndexPhp(['15', '20', '+20'], 10, 20, false));
        self::assertNull(BinariesService::nearestOverviewIndexPhp([], 10, 20, true));
        self::assertFalse(BinariesService::nearestOverviewIndexPhp(['15', '21'], 10, 20, true));
        $cases = [
            [['15', '12', ' 12', '20'], 10, 20, true], [['15', '20', '+20'], 10, 20, false], [[], 10, 20, true], [['15', '21'], 10, 20, true],
            [['15', '9'], 10, 20, false], [[10, 20, 15], 10, 20, true], [[10, 20, 15], 20, 10, true], [["1\0", '2'], 0, 9, true],
            [['5'], 5, 5, false], [[PHP_INT_MAX, PHP_INT_MIN], PHP_INT_MIN, PHP_INT_MAX, true],
        ];
        foreach ($cases as $args) {
            $this->assertParity(BinariesService::class, $args);
        }
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(BinariesService::class, $this->overviewNumberBatch(new Randomizer(new Mt19937(5500 + $i)), $uncommon));
            }
        }
        $random = new Randomizer(new Mt19937(5599));
        for ($batch = 0; $batch < 400; $batch++) {
            $fuzz = [];
            for ($i = 0, $n = $random->getInt(1, 8); $i < $n; $i++) {
                $fuzz[] = implode('', array_map(fn (): string => $this->pick($random, ['0', '1', '9', '-', '+', ' ', "\t", "\n", "\r", "\x0B", "\x0C", 'x', '5', '00']), range(0, $random->getInt(0, 6))));
            }
            $this->assertParity(BinariesService::class, [$fuzz, -1_000_000, 1_000_000_000_000, $random->getInt(0, 1) === 1]);
        }
    }

    public function test_header_validation_parity(): void
    {
        $this->requireBindings();
        $parts = [1, 0, -5, 1, 1, 1, 1, 1, 1, 1, 1, 1, PHP_INT_MAX, PHP_INT_MIN, 3];
        $ids = [
            '<a@b>', '<a@b>', '', " \t<a@b>\r\n", "  \x0B ", '', "<a\x7F@b>", '<é@b>', str_repeat('a', 255), str_repeat('a', 256),
            "<a\tb@c>", ' '.str_repeat('a', 255).' ', '<x@y>', '<x@y>', '<a b@c>',
        ];
        $expected = [0, 1, 1, 0, 2, 2, 2, 2, 0, 2, 2, 0, 0, 1, 0];
        self::assertSame($expected, HeaderStorageService::headerValidationCodesPhp($parts, $ids));
        foreach ($parts as $i => $part) {
            $reason = PartHandler::validationErrorPhp(['matches' => [2 => $part], 'Message-ID' => $ids[$i]]);
            self::assertSame(HeaderStorageService::W52_VALIDATION_ERRORS[$expected[$i]], $reason, (string) $i);
        }
        $this->assertParity(HeaderStorageService::class, [$parts, $ids]);
        $this->assertParity(HeaderStorageService::class, [[], []]);
        $this->assertParity(HeaderStorageService::class, [[1], ['']]);
        $this->assertParity(HeaderStorageService::class, [[1, 1], ["<a@b>\0", '<c@d>']]);
        for ($i = 0; $i < 12; $i++) {
            foreach ([false, true] as $uncommon) {
                $this->assertParity(HeaderStorageService::class, $this->validationBatch(new Randomizer(new Mt19937(5600 + $i)), $uncommon));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW52RustFfi($native, static function () use ($call, $args): array {
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
        $artifact = sys_get_temp_dir().'/w521-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS, 'repeats' => self::REPEATS, 'seed' => self::SEED, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW52.1 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach ($summary as $class => $workloads) {
            $metrics = [$workloads['common']['mean_speedup'], $workloads['common']['p99_speedup'], $workloads['uncommon']['mean_speedup'], $workloads['uncommon']['p99_speedup']];
            self::assertSame(min($metrics) >= 1.5, $this->uses($class), $class.' default must match the measured qualification '.json_encode($metrics));
        }
        self::assertCount(5, $summary);
    }

    private function uses(string $class): bool
    {
        /** @var callable(): bool $uses */
        $uses = [$class, 'usesW52RustFfi'];

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
        $php = $class::withW52RustFfi(false, static fn (): mixed => $call(...$args));
        $native = $class::withW52RustFfi(true, static fn (): mixed => $call(...$args));
        self::assertSame($php, $native, $class.' Rust batch must match PHP');
        $method = self::ENTRIES[$class];
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
    }

    /** @return list<mixed> */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        return match ($class) {
            BackfillConfig::class => $this->safeDateBatch($random, $uncommon),
            BackfillService::class => $this->articleDateBatch($random, $uncommon),
            BinariesConfig::class => $this->scanRangeBatch($random, $uncommon),
            BinariesService::class => $this->overviewNumberBatch($random, $uncommon),
            default => $this->validationBatch($random, $uncommon),
        };
    }

    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        $calls = [
            BackfillConfig::class => ['nntmux_w521_backfill_config_date_days_batch', ['2012-08-14', 10, 1], 1, 2],
            BackfillService::class => ['nntmux_w521_backfill_article_date_batch', ['bad', 3, 1], 2, 2],
            BinariesConfig::class => ['nntmux_w521_binaries_config_scan_range_end_batch', [pack('P', 100), 8, pack('P', 200), 8, 1, 20, 0], 1, 1],
            BinariesService::class => ['nntmux_w521_binaries_overview_nearest_batch', ['x', 1, 1, 0, 9, 1], 1, 1],
            HeaderStorageService::class => ['nntmux_w521_header_storage_validation_batch', [pack('P', 0), 8, '<a@b>', 5, 1], 2, 2],
        ];
        foreach ($calls as $class => [$symbol, $args, $words, $worst]) {
            $buffer = (new ReflectionMethod($class, 'w52Buffer'))->invoke(null, 4);
            self::assertIsArray($buffer, $class.' must load the backfill_binaries_w52 binding');
            $ffi = $buffer[0];
            self::assertInstanceOf(FFI::class, $ffi, $class);
            $out = $ffi->new('int64_t[4]');
            self::assertSame($words, $this->invoke($ffi, $symbol, [...$args, $out, 4]), $symbol);
            self::assertSame(-2, $this->invoke($ffi, $symbol, [...$args, $out, $worst - 1]), $symbol.' short capacity');
        }
    }

    /**
     * Calls an export declared by a class's W52_FFI_CDEF.
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

    /** @return array{0: list<string>} */
    private function safeDateBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $common = gmdate('Y-m-d', $random->getInt(946_684_800, 1_893_456_000));
            $dates[] = ! $uncommon ? $common : match ($random->getInt(0, 7)) {
                0 => \sprintf('%04d-02-29', $random->getInt(1, 9999)),
                1 => \sprintf('%04d-%02d-%02d', $random->getInt(0, 9999), $random->getInt(0, 13), $random->getInt(0, 32)),
                2 => $this->pick($random, ['', '2012-8-14', ' 2012-08-14', "2012-08-14\n", '2012/08/14', '0000-01-01', '9999-12-31', '0001-01-01']),
                3 => str_replace("\0", '-', $random->getBytes($random->getInt(1, 12))),
                4 => $common.'T00:00:00',
                default => $common,
            };
        }

        return [$dates];
    }

    /** @return array{0: list<string>} */
    private function articleDateBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $stamp = self::NOW - $random->getInt(0, 3000 * self::DAY);
            $moment = (new DateTimeImmutable('@'.$stamp))->setTimezone(new DateTimeZone($this->pick($random, self::ZONES)));
            $common = $moment->format('D, d M Y H:i:s O');
            $dates[] = ! $uncommon ? $common : match ($random->getInt(0, 9)) {
                0 => $moment->format('j M Y H:i:s O'),
                1 => $moment->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s').' '.$this->pick($random, ['GMT', 'UTC']),
                2 => $common.' '.$this->pick($random, ['(UTC)', '(GMT)']),
                3 => (new DateTimeImmutable('@'.$random->getInt(-30_000_000_000, 250_000_000_000)))->format('D, d M Y H:i:s ').$this->pick($random, ['+1400', '-1400', '-1459', '+0000']),
                4 => $this->pick($random, ['Mon', 'Fri']).substr($common, 3),
                5 => $moment->format('Y-m-d H:i:s'),
                6 => $this->pick($random, ['', 'not a date', '31 Feb 2026 00:00:00 +0000', '1 jan 2026 00:00:00 +0000', '25 Oct 26 22:30:33 +0100']),
                default => $common,
            };
        }

        return [$dates];
    }

    /** @return array{0: list<int>, 1: list<int>, 2: int, 3: int} */
    private function scanRangeBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $firsts = $newests = [];
        for ($i = 0; $i < $count; $i++) {
            $first = $random->getInt(1_000_000, 9_000_000_000);
            $firsts[] = $first;
            $newests[] = ! $uncommon ? $first + $random->getInt(0, 200_000) : match ($random->getInt(0, 5)) {
                0 => max(0, $first - $random->getInt(0, 1_000)),
                1 => $first,
                2 => $first + $random->getInt(1, 3),
                3 => BinariesConfig::W52_MAX_ARTICLE - $random->getInt(0, 10),
                default => $first + $random->getInt(0, 200_000),
            };
        }
        [$buffer, $limit] = ! $uncommon ? [20_000, $random->getInt(0, 1) * 100_000] : [[1, 0], [7, 3], [20_000, 1], [BinariesConfig::W52_MAX_BUFFER, 0], [500, BinariesConfig::W52_MAX_ARTICLE]][$random->getInt(0, 4)];

        return [$firsts, $newests, $buffer, (int) $limit];
    }

    /** @return array{0: list<int|string>, 1: int, 2: int, 3: bool} */
    private function overviewNumberBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $low = $random->getInt(1_000_000, 9_000_000_000);
        $high = $low + $count + $random->getInt(0, 100);
        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $number = $random->getInt($low, $high);
            $common = (string) $number;
            $numbers[] = ! $uncommon ? $common : match ($random->getInt(0, 7)) {
                0 => $this->pick($random, [' ', "\t", "\r\n", "\x0B"]).$common.$this->pick($random, [' ', "\n", '']),
                1 => '+'.$common,
                2 => $number,
                3 => (string) $this->pick($random, [(string) $low, (string) $high]),
                default => $common,
            };
        }
        if ($uncommon && $random->getInt(0, 3) === 0) {
            $numbers[$random->getInt(0, $count - 1)] = $this->pick($random, ['0'.$low, (string) ($high + 1), (string) ($low - 1), '', '1e3', '99999999999999999999', ' - 1']);
        }

        return [$numbers, $low, $high, $random->getInt(0, 1) === 1];
    }

    /** @return array{0: list<int>, 1: list<string>} */
    private function validationBatch(Randomizer $random, bool $uncommon): array
    {
        $count = $random->getInt(64, 256);
        $parts = $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $common = '<'.bin2hex($random->getBytes($random->getInt(8, 24))).'$'.$random->getInt(1, 999).'@'.$this->pick($random, ['news.example.com', 'powerpost2000AA.local', 'ngPost', 'JBinUp.local']).'>';
            $parts[] = ! $uncommon ? $random->getInt(1, 500) : match ($random->getInt(0, 5)) {
                0 => 0,
                1 => $random->getInt(-100, -1),
                2 => PHP_INT_MAX,
                default => $random->getInt(1, 500),
            };
            $ids[] = ! $uncommon ? $common : match ($random->getInt(0, 9)) {
                0 => '',
                1 => $this->pick($random, [" \t", "\r\n", "\x0B", ' ']).$common.$this->pick($random, ["\r\n", ' ', '']),
                2 => '<'.str_repeat('a', $random->getInt(250, 300)).'>',
                3 => '<'.$this->pick($random, ['é', "\x7F", "\t", "\x01", '漢字']).'@x>',
                4 => str_replace("\0", '.', $random->getBytes($random->getInt(1, 40))),
                default => $common,
            };
        }

        return [$parts, $ids];
    }
}
