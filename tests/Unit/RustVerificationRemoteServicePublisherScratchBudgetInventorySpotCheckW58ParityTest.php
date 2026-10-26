<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\RemotePublicationService;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\ScratchBudget;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\SpotCheckPlanner;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`, run with
 * `NNTMUX_W585_BENCH=1`): per helper, 200 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustVerificationRemoteServicePublisherScratchBudgetInventorySpotCheckW58ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        RemotePublicationService::class => 'publicationPlanBatch',
        RemoteReleasePublisher::class => 'publisherPlanBatch',
        ScratchBudget::class => 'budgetPlanBatch',
        ScratchInventory::class => 'inventoryPlanBatch',
        SpotCheckPlanner::class => 'spotCheckPlanBatch',
    ];

    /**
     * C entry point per class.
     *
     * @var array<class-string, string>
     */
    private const FUNCTIONS = [
        RemotePublicationService::class => 'nntmux_w585_publication_plan',
        RemoteReleasePublisher::class => 'nntmux_w585_publisher_plan',
        ScratchBudget::class => 'nntmux_w585_budget_plan',
        ScratchInventory::class => 'nntmux_w585_inventory_plan',
        SpotCheckPlanner::class => 'nntmux_w585_spot_check_plan',
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        RemotePublicationService::class => 5,
        RemoteReleasePublisher::class => 4,
        ScratchBudget::class => 5,
        ScratchInventory::class => 6,
        SpotCheckPlanner::class => 8,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        RemotePublicationService::class => true,
        RemoteReleasePublisher::class => true,
        ScratchBudget::class => true,
        ScratchInventory::class => true,
        SpotCheckPlanner::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W585_BENCH=1` runs (200 samples per workload, seed 5852, release Rust, PHP 8.5 CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        RemotePublicationService::class => [2.58, 2.50, 2.81, 2.62],
        RemoteReleasePublisher::class => [2.93, 2.81, 3.87, 4.08],
        ScratchBudget::class => [4.86, 3.03, 5.20, 5.62],
        ScratchInventory::class => [6.19, 7.43, 7.04, 6.95],
        SpotCheckPlanner::class => [5.11, 5.61, 6.11, 7.17],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 200;

    private const HASH = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const BYTES = ['a', 'Z', 'f', '0', '1', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', '=', ':', '/', '\\', "\xc3\xa9", "\xc3", "\xff", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\n", ',', '-', 'audit-', '', ' ', '0', '7', '200', '413', '422', '1234567890123', '1234567890123456789', '999999999999999999', 'x', self::HASH, 'sampled', 'published', 'transport', '12-3'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::state($class, 'reset', null);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => $method) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method): void
    {
        $reflection = new ReflectionClass($class);
        foreach (['usesW58RustFfi', 'withW58RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w58RustFfiState')->isPrivate(), "{$class} keeps its W58 state private");
        $constant = new ReflectionClassConstant($class, 'W58_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW58RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW58RustFfi($enabled, static fn () => [$class::usesW58RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW58RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW58RustFfi(false, static fn () => [$class::withW58RustFfi(true, static fn () => $class::usesW58RustFfi()), $class::usesW58RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW58RustFfi(! $class::usesW58RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW58RustFfi(), "{$class} must restore after an exception");

        $class::withW58RustFfi(! $class::usesW58RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW58RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w58_state_adds_no_static_properties(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w58', $property->getName(), $class);
            }
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => array_map(
            static fn (string $class): mixed => (new ReflectionProperty($class, 'useRustFfi'))->getValue(),
            array_keys(self::METHODS),
        );
        $before = $snapshot();
        $this->assertSame([false, false, false, false, true], $before);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW58RustFfi($enabled, $snapshot), $class);
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_spot_check_planner_selection_api_is_preserved(): void
    {
        $reflection = new ReflectionClass(SpotCheckPlanner::class);
        foreach (['select', 'selectPackedNative', 'selectPhp', 'setUseRustFfi'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), $name);
        }
        $this->assertTrue($reflection->getConstructor()?->getParameters()[0]->allowsNull());
        $manifest = ['identity' => 'w58', 'files' => [
            ['key' => 'a', 'totalparts' => 3, 'segments' => [
                ['messageid' => 'a1@x', 'number' => 1, 'bytes' => 100],
                ['messageid' => 'a2@x', 'number' => 2, 'bytes' => 100],
                ['messageid' => 'a3@x', 'number' => 3, 'bytes' => 100],
            ]],
            ['key' => 'b', 'totalparts' => 2, 'segments' => [
                ['messageid' => 'b1@x', 'number' => 1, 'bytes' => 100],
                ['messageid' => 'b2@x', 'number' => 2, 'bytes' => 100],
            ]],
        ]];
        // Above 16 articles select() bypasses NativeSpotCheckPlanner and must agree with the PHP planner.
        $php = SpotCheckPlanner::selectPhp($manifest, 32, 1_000_000);
        $this->assertCount(5, $php);
        $this->assertSame($php, (new SpotCheckPlanner)->select($manifest, 32, 1_000_000));
        foreach ([true, false] as $enabled) {
            $this->assertSame($php, SpotCheckPlanner::withW58RustFfi($enabled, static fn () => (new SpotCheckPlanner)->select($manifest, 32, 1_000_000)));
        }
        $this->assertSame([], (new SpotCheckPlanner)->select(['files' => 'x'], 4, 100));
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $php = $class::{$method.'Php'}($records);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php);
        $this->assertSame($php, $class::withW58RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW58RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method): void
    {
        $this->assertSame([], $class::{$method.'Php'}([]));
        $this->assertSame([], $class::{$method.'Native'}([]));
        $this->assertSame([], $class::{$method}([]));
        // One empty record still yields a full row.
        $this->assertSame($class::{$method.'Php'}(['']), $class::{$method.'Native'}(['']));
        $this->assertCount(self::WIDTHS[$class], $class::{$method}(['']));
    }

    public function test_publication_plan_examples(): void
    {
        $this->requireFfi(RemotePublicationService::class);
        $this->assertBoth(RemotePublicationService::class, [
            "12\t3\t".self::HASH."\ttoken\tpublished",
            '',
            "0\tx\tABC\t\t429",
            "7\t0\tzz\tt\t404\textra",
            "1234567890123456789\t1\tzz\tt\ttransport",
            "5\t5\tzz\tt\tbogus",
            "1\t1\t".self::HASH."\t".str_repeat('t', 65)."\t200",
            "2\t2\t".self::HASH."\tt\tno_release",
            "3\t3\t".strtoupper(self::HASH)."\tt\tstale",
        ], [
            12, 3, 0, 1, 3,
            0, 0, 31, 0, 0,
            0, 0, 15, 429, 1,
            7, 0, 20, 404, 2,
            0, 1, 5, 9, 1,
            5, 5, 4, 0, 0,
            1, 1, 8, 200, 3,
            2, 2, 0, 11, 3,
            3, 3, 4, 7, 0,
        ]);
        // Fallback statuses agree with RemoteReleasePublisher::batchFallbackPhp() for unconditional codes.
        foreach ([200, 404, 405, 408, 413, 422, 429, 500, 503] as $status) {
            $class = RemotePublicationService::publicationPlanBatchNative(["1\t1\t".self::HASH."\tt\t".$status])[4];
            $this->assertSame(RemoteReleasePublisher::batchFallbackPhp($status, false, false), $class === 2, (string) $status);
        }
    }

    public function test_publisher_plan_examples(): void
    {
        $this->requireFfi(RemoteReleasePublisher::class);
        $key = static fn (string $text): int => (int) hexdec(hash('fnv1a32', $text));
        $this->assertBoth(RemoteReleasePublisher::class, [
            "1\tsampled\t12\t3\t".self::HASH."\t",
            '',
            "1\tfull\t0\tx\tzz\t413",
            "yes\tsampled\t1\t1\tzz\t422\textra",
            "0\tsampled\t1\t1\tzz\tHTTP",
            "1\tsampled\t9\t9\t".self::HASH."\t404",
        ], [
            1, $key('12:3:'.self::HASH), 0, 0,
            0, $key('::'), 0, 47,
            2, $key('0:x:zz'), 1, 14,
            0, $key('1:1:zz'), 2, 41,
            0, $key('1:1:zz'), 0, 24,
            1, $key('9:9:'.self::HASH), 1, 0,
        ]);
        foreach ([[true, 'sampled'], [true, 'full'], [false, 'sampled'], [true, '']] as [$passed, $verdict]) {
            $words = RemoteReleasePublisher::publisherPlanBatchNative([($passed ? '1' : '0')."\t{$verdict}\t1\t1\t".self::HASH."\t"]);
            $this->assertSame(RemoteReleasePublisher::publicationGuardPhp($passed, $verdict), $words[0], $verdict);
        }
    }

    public function test_budget_plan_examples(): void
    {
        $this->requireFfi(ScratchBudget::class);
        $this->assertBoth(ScratchBudget::class, [
            '1000,5000,100,200,50,150',
            '',
            ' 1000 ,500,600,0,0,10',
            '100,1000,0,90,50,0,extra',
            '100,1000,0,95,0,10',
            'x,1000,-1,0,0,0',
            '999999999999999999,999999999999999999,0,999999999999999999,0,999999999999999999',
        ], [
            1000, 100, 1, 300, 0,
            0, 0, 1, 0, 2,
            -100, 10, 0, 0, 4,
            100, -50, 1, 40, 10,
            100, 10, 0, 95, 0,
            0, 0, 1, 0, 1,
            999999999999999999, 999999999999999999, 0, 999999999999999999, 0,
        ]);
        // Cap, delta and the admitted total agree with the scalar helpers.
        foreach ([[1000, 5000, 100, 200, 50, 150], [100, 1000, 0, 90, 50, 0], [10, 5, 9, 0, 3, 1]] as [$scratch, $free, $reserve, $reserved, $entry, $request]) {
            [$cap, $delta, $admitted, $after] = ScratchBudget::budgetPlanBatchNative([implode(',', [$scratch, $free, $reserve, $reserved, $entry, $request])]);
            $this->assertSame(ScratchBudget::reservationCapPhp($scratch, (float) $free, $reserve), $cap);
            $this->assertSame(ScratchBudget::reservationDeltaPhp($request, $entry), $delta);
            $this->assertSame($admitted === 1 ? ScratchBudget::adjustedBudgetPhp($reserved, $delta) : $reserved, $after);
        }
    }

    public function test_inventory_plan_examples(): void
    {
        $this->requireFfi(ScratchInventory::class);
        $this->assertBoth(ScratchInventory::class, [
            "1000\t100\n12-3\t950\t10\naudit-4-5\t800\t20\nlost+found\t10\t5\n\n",
            '',
            "x\t1\n12-\t1\t1\naudit-1-2-3\t1\t1\n1-2\tx\t1\nbad\n-1\t1\t2",
            "0\t0\t0".str_repeat("\n1-1\t0\t999999999999999999", 10),
            "500\t0\n1-1\t500\t1\n2-2\t499\t1\naudit-3-3\t0\t1\n\n\n",
        ], [
            1, 1, 1, 2, 35, 4,
            0, 0, 0, 0, 0, 1,
            0, 0, 3, 0, 4, 3,
            10, 0, 0, 0, PHP_INT_MAX, 1,
            2, 1, 0, 2, 3, 0,
        ]);
        // Entry classes agree with filenameClassPhp() and scratchNamePhp().
        foreach ([ScratchInventory::scratchNamePhp(null, 12, 3, 0), ScratchInventory::scratchNamePhp(7, 0, 0, 9), 'audit-', '1-', 'x'] as $name) {
            $words = ScratchInventory::inventoryPlanBatchNative(["0\t0\n{$name}\t0\t0"]);
            $this->assertSame(['candidate' => [1, 0, 0], 'audit' => [0, 1, 0], 'unknown' => [0, 0, 1]][ScratchInventory::filenameClassPhp($name)], \array_slice($words, 0, 3), $name);
        }
    }

    public function test_spot_check_plan_examples(): void
    {
        $this->requireFfi(SpotCheckPlanner::class);
        $sum = 0;
        for ($i = 0; $i < 64; $i++) {
            $sum += 1 + $i * 15;
        }
        $this->assertBoth(SpotCheckPlanner::class, [
            '100,4',
            '',
            ' 7 , 1 ',
            '3,10',
            '1000,500',
            '1234567890123,2,9',
            '10,x',
            '999999999999,64',
            '1,1',
        ], [
            4, 33, 1, 50, 100, 202, 1000, 0,
            0, 0, 0, 0, 0, 0, 0, 11,
            1, 0, 1, 4, 1, 1, 142, 0,
            3, 1, 1, 2, 3, 6, 1000, 0,
            64, 15, 1, 500, 946, $sum, 946, 4,
            0, 0, 0, 0, 0, 0, 0, 9,
            0, 0, 0, 0, 0, 0, 0, 2,
            64, 15873015872, 1, 500000000000, 999999999937, 31999999998016, 999, 0,
            1, 0, 1, 1, 1, 1, 1000, 0,
        ]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $class::{$method.'Native'}($records));
        $handle = self::state($class, 'buffer', 1);
        $this->assertIsArray($handle, "{$class} must resolve the Rust helper");
        $this->assertInstanceOf(FFI::class, $handle[0]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_large_batches_grow_the_output_buffer(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = self::firstRecords($class)[$i % 3].$i;
        }
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $handle = self::state($class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(3000 * self::WIDTHS[$class], $handle[2]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method): void
    {
        self::state($class, 'reset', false);
        $records = self::firstRecords($class);
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $this->assertSame($class::{$method.'Php'}($records), $class::withW58RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertNull(self::state($class, 'buffer', 1), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_nul_bytes_decline_to_php(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $records[1] .= "\0x";
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $ffi = $this->requireFfi($class);
        $function = self::abiFunction($class);
        $data = implode("\0", self::firstRecords($class));
        $needed = 3 * self::WIDTHS[$class];
        $out = $ffi->new('int64_t[32]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call(3, 0, $data, '', $out, 32), $function);
        $this->assertSame(-$needed, $call(3, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call(3, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call(3, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call(3, 1, $data, '', $out, 32), "{$function} flags");
        $this->assertSame(-1, $call(4, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call(2, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call(3, 0, $data, pack('P', 1), $out, 32), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    /**
     * Over 400 rounds of 1-16 records each (thousands of structured, fragment-spliced and raw-byte inputs
     * per helper), every class's Rust and PHP plans agree bit for bit; every 50th round also carries NUL
     * bytes, which must decline to PHP.
     *
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $this->requireFfi($class);
        mt_srand(5851 + crc32($class) % 1000);
        $checked = 0;
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $records[] = match (mt_rand(0, 5)) {
                    0 => self::randomBytes(24, $withNul),
                    1 => self::spliced(self::structuredRecord($class), $withNul),
                    default => self::structuredRecord($class),
                };
            }
            if ($withNul) {
                $records[0] .= "\0";
            }
            $this->assertBoth($class, $records, null);
            $checked += \count($records);
        }
        $this->assertGreaterThanOrEqual(300, $checked);
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        if (getenv('NNTMUX_W585_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W585_BENCH=1 to run the Wave 58.5 paired benchmark.');
        }
        $this->requireFfi(RemotePublicationService::class);
        mt_srand(5852);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $publications = $publishers = $budgets = $inventories = $spots = [];
            for ($i = 0; $i < $count; $i++) {
                $hash = hash('sha256', (string) $i);
                $token = substr(hash('sha1', 't'.$i), 0, 32);
                $publications[] = $odd
                    ? match ($i % 5) {
                        0 => strtoupper($hash)."\tx\t".$hash."\t".str_repeat('t', 80)."\t503\textra",
                        1 => '',
                        2 => "1234567890123456789\t".$i."\tshort\t\tno_release",
                        3 => '0'.$i."\t0\t".$hash."\t".$token."\tUNKNOWN_STATUS_LABEL",
                        default => (1000 + $i)."\t".$i."\t".$hash."\t".$token."\t".['405', 'stale', 'invalid', '099'][$i % 4],
                    }
                : (100000 + $i)."\t".($i % 9)."\t".$hash."\t".$token."\t".['published', 'filtered', '200', 'retry', '429', 'metadata_review'][$i % 6];

                $publishers[] = $odd
                    ? match ($i % 5) {
                        0 => "yes\tsampled\t0\tx\t".strtoupper($hash)."\tHTTP\textra",
                        1 => '',
                        2 => "1\tfull\t".$i."\t".$i."\tshort\t422",
                        3 => "0\t\t1234567890123456789\t1\t".$hash."\t413",
                        default => "1\tsampled\t".(1000 + $i)."\t".$i."\t".$hash."\t".['', '404', '500'][$i % 3],
                    }
                : "1\tsampled\t".(100000 + $i)."\t".($i % 9)."\t".$hash."\t".($i % 7 === 0 ? '413' : '');

                $budgets[] = $odd
                    ? match ($i % 5) {
                        0 => ' 10737418240 , 500 , 1073741824 ,0,0, 99 ,extra',
                        1 => '',
                        2 => 'x,-1,1.5,,,',
                        3 => '999999999999999999,999999999999999999,0,999999999999999999,0,999999999999999999',
                        default => "\t".(1000 + $i)."\n,".(5000 + $i).',100,'.(200 + $i).','.(4000 + $i).',0',
                    }
                : implode(',', [10737418240, 50000000000 + $i * 4096, 1073741824, $i * 1000, $i * 10, $i * 20 + 4096]);

                $now = 1_800_000_000 + $i;
                $inventories[] = $odd
                    ? match ($i % 5) {
                        0 => "x\t-1\n".implode("\n", array_map(static fn (int $k): string => "lost+found{$k}\t{$k}\t".(1000 * $k), range(1, 12))),
                        1 => '',
                        2 => "{$now}\t86400\n12-\t1\t1\naudit-1-2-3\t1\t1\n1-2\tx\t1\nbad\n\n\n-1\t1\t2",
                        3 => "{$now}\t0".str_repeat("\n1-1\t0\t999999999999999999", 12),
                        default => "{$now}\t3600\n".implode("\n", array_map(static fn (int $k): string => ($k % 2 === 0 ? 'audit-' : '').(500 + $k).'-'.$k."\t".($now - 600 * $k)."\t".(1048576 * $k), range(1, 30))),
                    }
                : "{$now}\t86400\n".implode("\n", array_map(static fn (int $k): string => ($k % 4 === 0 ? 'audit-'.$k.'-'.($i + $k) : ($i + $k).'-'.$k)."\t".($now - 7200 * $k)."\t".(52428800 + $k), range(1, 8)));

                $spots[] = $odd
                    ? match ($i % 5) {
                        0 => ' 999999999999 , 999 ',
                        1 => '',
                        2 => '1234567890123,2,9',
                        3 => (5000 + $i).',64',
                        default => 'x,'.$i,
                    }
                : (40 + $i % 400).','.(8 + $i % 9);
            }

            $workloads[RemotePublicationService::class][$name] = static fn (bool $rust) => $rust ? RemotePublicationService::publicationPlanBatchNative($publications) : RemotePublicationService::publicationPlanBatchPhp($publications);
            $workloads[RemoteReleasePublisher::class][$name] = static fn (bool $rust) => $rust ? RemoteReleasePublisher::publisherPlanBatchNative($publishers) : RemoteReleasePublisher::publisherPlanBatchPhp($publishers);
            $workloads[ScratchBudget::class][$name] = static fn (bool $rust) => $rust ? ScratchBudget::budgetPlanBatchNative($budgets) : ScratchBudget::budgetPlanBatchPhp($budgets);
            $workloads[ScratchInventory::class][$name] = static fn (bool $rust) => $rust ? ScratchInventory::inventoryPlanBatchNative($inventories) : ScratchInventory::inventoryPlanBatchPhp($inventories);
            $workloads[SpotCheckPlanner::class][$name] = static fn (bool $rust) => $rust ? SpotCheckPlanner::spotCheckPlanBatchNative($spots) : SpotCheckPlanner::spotCheckPlanBatchPhp($spots);
        }

        foreach ($workloads as $class => $byName) {
            $results = [];
            foreach ($byName as $name => $workload) {
                $this->assertSame($workload(false), $workload(true), "{$class} {$name} benchmark workload must agree");
                for ($w = 0; $w < 20; $w++) {
                    $workload(false);
                    $workload(true);
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < self::BENCH_SAMPLES; $s++) {
                    foreach (mt_rand(0, 1) === 1 ? [true, false] : [false, true] as $rust) {
                        $best = PHP_INT_MAX;
                        for ($r = 0; $r < self::REPEATS; $r++) {
                            $t0 = hrtime(true);
                            $workload($rust);
                            $best = min($best, hrtime(true) - $t0);
                        }
                        $times[$rust ? 'rust' : 'php'][] = $best / 1000;
                    }
                }

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, \sprintf(
                "\n[Wave 58.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            $floors[$class] = min($results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']);
        }
        fwrite(STDERR, "\n");

        // Every helper is reported before the gate is asserted; Rust defaults must still clear 1.5x.
        foreach ($floors as $class => $floor) {
            $this->assertSame(self::DEFAULTS[$class], $class::usesW58RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW58RustFfi(), $class);
            if ($class::usesW58RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW58RustFfi(), "{$class} retains PHP by default");
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<string>  $records
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $records, ?array $expected): void
    {
        $method = self::METHODS[$class];
        $php = $class::{$method.'Php'}($records);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class} PHP");
        }
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW58RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW58RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /**
     * Three representative records per class.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private static function firstRecords(string $class): array
    {
        return match ($class) {
            RemotePublicationService::class => ["1\t2\t".self::HASH."\ttoken\tpublished", "x\t\tzz\tt\t429", ''],
            RemoteReleasePublisher::class => ["1\tsampled\t1\t2\t".self::HASH."\t", "0\tfull\tx\t1\tzz\t413", ''],
            ScratchBudget::class => ['1000,5000,100,200,50,150', 'x,1,2', ''],
            ScratchInventory::class => ["1000\t100\n12-3\t950\t10", "x\t1\naudit-4-5\t1\t2", ''],
            SpotCheckPlanner::class => ['100,4', '7,x', ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * One record shaped like the class's input, built from plausible and adversarial pieces.
     *
     * @param  class-string  $class
     */
    private static function structuredRecord(string $class): string
    {
        $pick = static fn (array $items): string => (string) $items[mt_rand(0, \count($items) - 1)];
        $join = static function (string $separator, int $max, callable $part): string {
            $parts = [];
            for ($i = 0, $n = mt_rand(0, $max); $i < $n; $i++) {
                $parts[] = $part();
            }

            return implode($separator, $parts);
        };
        $number = static fn (): string => $pick(['', '0', '1', '7', '12', '00', '007', '-1', '1.5', ' 5', '5 ', 'x', '4096', (string) mt_rand(0, 1_000_000_000), '999999999999', '1234567890123', '999999999999999999', '1234567890123456789', self::randomBytes(3)]);

        return match ($class) {
            RemotePublicationService::class => implode("\t", array_slice([
                $number(), $number(),
                $pick([self::HASH, strtoupper(self::HASH), substr(self::HASH, 1), self::HASH.'0', '', 'zz', str_repeat('g', 64)]),
                $pick(['', 't', 'token', str_repeat('t', 64), str_repeat('t', 65), self::randomBytes(6)]),
                $pick(['published', 'filtered', 'metadata_review', 'deferred', 'paused', 'retry', 'stale', 'error', 'transport', 'invalid', 'no_release', 'unresolved', 'PUBLISHED', '', '200', '299', '404', '405', '408', '413', '422', '429', '500', '503', '504', '099', '0200', ' 200', self::randomBytes(4)]),
                $pick(['extra', '', 'x']),
            ], 0, mt_rand(0, 3) === 0 ? mt_rand(0, 6) : 5)),
            RemoteReleasePublisher::class => implode("\t", array_slice([
                $pick(['1', '0', '', 'yes', 'true', ' 1', '01']),
                $pick(['sampled', 'full', '', 'Sampled', 'sampled ', self::randomBytes(5)]),
                $number(), $number(),
                $pick([self::HASH, strtoupper(self::HASH), substr(self::HASH, 1), '', 'zz', self::randomBytes(8)]),
                $pick(['', '200', '404', '405', '413', '422', '429', '500', '099', '0404', 'HTTP', ' 413', self::randomBytes(3)]),
                $pick(['extra', '']),
            ], 0, mt_rand(0, 3) === 0 ? mt_rand(0, 7) : 6)),
            ScratchBudget::class => $join(',', 8, static fn (): string => $pick(['', '0', '1', ' 12 ', "\t40\n", '4096', '10737418240', (string) mt_rand(0, PHP_INT_MAX >> 4), '999999999999999999', '1234567890123456789', '-1', '1.5', 'x', self::randomBytes(4)])),
            ScratchInventory::class => implode("\t", [$number(), $number()]).(mt_rand(0, 4) === 0 ? "\t".$number() : '')
                .$join('', 10, static fn (): string => "\n".(mt_rand(0, 6) === 0 ? '' : implode("\t", array_slice([
                    $pick(['12-3', 'audit-4-5', '0-0', '1-', '-1', 'audit-', 'audit-1', 'audit-1-2-3', 'lost+found', '', 'Audit-1-2', ' 1-2', '1-2 ', '12345678901234567890-1', self::randomBytes(6)]),
                    $number(), $number(), 'extra',
                ], 0, mt_rand(0, 4) === 0 ? mt_rand(0, 4) : 3)))),
            SpotCheckPlanner::class => implode(',', array_slice([
                $pick(['', '0', '1', '2', '3', '10', '100', ' 77 ', "\t1000\n", (string) mt_rand(1, 100000), '999999999999', '1234567890123', '-1', 'x', self::randomBytes(3)]),
                $pick(['', '0', '1', '2', '4', '16', '63', '64', '65', '1000', (string) mt_rand(1, 200), '999999999999', '1234567890123', 'x', self::randomBytes(3)]),
                $pick(['9', '']),
            ], 0, mt_rand(0, 3) === 0 ? mt_rand(0, 3) : 2)),
            default => throw new RuntimeException($class),
        };
    }

    /** Splices random fragments and bytes into $record at random offsets. */
    private static function spliced(string $record, bool $withNul): string
    {
        for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
            $insert = mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(4, $withNul);
            $at = mt_rand(0, \strlen($record));
            $record = substr($record, 0, $at).$insert.substr($record, $at);
        }

        return $record;
    }

    /**
     * The C entry point of $class, called by name through the FFI handle.
     *
     * @param  class-string  $class
     */
    private static function abiFunction(string $class): string
    {
        return self::FUNCTIONS[$class];
    }

    /**
     * Invokes the class's private `w58RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w58RustFfiState'))->invoke(null, $op, $val);
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $value = '';
        for ($i = 0, $length = mt_rand(0, $maxLength); $i < $length; $i++) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $value .= $byte === "\0" && ! $withNul ? ' ' : $byte;
        }

        return $value;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    /**
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        /** @var string $cdef */
        $cdef = (new ReflectionClassConstant($class, 'W58_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('w58_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust W58.5 FFI library is not built or available.');
        }

        return $ffi;
    }
}
