<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Backfill\BackfillService;
use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinariesService;
use App\Services\CollectionCleanupService;
use App\Services\ReleaseProcessingService;
use App\Support\Data\ReleaseDeleteStats;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Pure helper extraction: no NNTP, database, deployment or production configuration.
 * NNTMUX_BINARIES_BACKFILL_CLEANUP_BENCH=1 runs two repetitions of 5,000 randomized
 * pairs per helper/regime after 256 warmups. Raw paired nanoseconds and build/runtime
 * identity are retained in a unique temporary JSON artifact, whose path is printed.
 * Each timed observation calls the production dispatcher, including FFI allocation,
 * marshaling, formatting and PHP result reconstruction. Clock/network/DB work stays
 * in PHP and is excluded identically. Defaults require >=1.5x in all four metrics.
 */
final class RustBinariesBackfillCleanupParityTest extends TestCase
{
    private const array CLASSES = [BinariesService::class, BackfillService::class, ReleaseProcessingService::class, CollectionCleanupService::class, ReleaseDeleteStats::class];

    private FFI $ffi;

    protected function setUp(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', implode("\n", array_map(static fn (string $class): string => $class::FFI_CDEF, self::CLASSES)));
        self::assertNotNull($ffi, 'Build rust/pipeline-ffi --release before running this suite.');
        $this->ffi = $ffi;
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
        }
    }

    public function test_header_range_watermarks_chunk_edges_and_diagnostics(): void
    {
        self::assertSame(['first' => 100, 'last' => 350, 'groupLast' => 350, 'total' => 250, 'realTotal' => 900, 'leaveOver' => 100, 'isNew' => false], BinariesService::existingHeaderRangeNative(100, 1000, 100, 250));
        self::assertSame(101, BinariesService::existingHeaderRangeNative(100, 101, 100, 0)['last']);
        self::assertSame(100, BinariesService::existingHeaderRangeNative(100, 99, 100, 0)['last']);
        self::assertSame(0, BinariesService::existingHeaderRangeNative(100, 101, 100, 0)['leaveOver']);
        self::assertSame([101, 200], BinariesService::scanChunkNative(100, 900, 900, 800, 100));
        self::assertSame([801, 850], BinariesService::scanChunkNative(800, 850, 850, 50, 100));
        self::assertSame([101, 100], BinariesService::scanChunkNative(100, 100, 100, 0, 100));
        self::assertSame(100, BinariesService::newHeaderRangeNative(100, 110, 500, 100)['last']);
        self::assertSame(123, BinariesService::newHeaderRangeNative(100, 1000, 500, 100, 123)['first']);
        self::assertSame("\nGetting 1,000 articles (1 to 1,000) from alt.binaries.test - (500 articles in queue).", BinariesService::scanDiagnosticNative(1, 1000, 1500, 'alt.binaries.test'));
        foreach (['', "alt.\0binaries.\xff", str_repeat('x', 4096)] as $name) {
            self::assertSame(BinariesService::scanDiagnosticPhp(-1, 10000, 9000, $name), BinariesService::scanDiagnosticNative(-1, 10000, 9000, $name));
        }
        // The private production range caller still applies the max-header cap to new groups.
        $service = (new ReflectionClass(BinariesService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BinariesService::class, 'config'))->setValue($service, new BinariesConfig(messageBuffer: 100, newGroupMessagesToScan: 500));
        foreach ([false, true] as $use) {
            BinariesService::setUseRustFfi($use);
            $range = (new ReflectionMethod($service, 'calculateArticleRange'))->invoke($service, ['last_record' => 0], ['first' => 1, 'last' => 1000], 50);
            self::assertSame(['first' => 400, 'last' => 450, 'groupLast' => 450, 'total' => 50, 'realTotal' => 600, 'leaveOver' => 100, 'isNew' => true], $range);
        }
    }

    public function test_date_search_steps_and_backfill_boundaries(): void
    {
        foreach ([[1, 1, 1], [1, 2, 2], [1, 3, 2], [1, PHP_INT_MAX, 4611686018427387904], [PHP_INT_MAX - 2, PHP_INT_MAX, PHP_INT_MAX - 1]] as [$min, $max, $expected]) {
            self::assertSame($expected, BinariesService::articleMidpointNative($min, $max));
            self::assertSame($expected, BinariesService::articleMidpointPhp($min, $max));
        }
        self::assertNull(BinariesService::postdateProbeNative(1, 1, 1, 1005));
        self::assertSame(201, BinariesService::postdateProbeNative(200, 100, 1000, 1005));
        self::assertSame(100, BackfillService::targetArticleNative(500, 1000, 100));
        self::assertSame(600, BackfillService::targetArticleNative(500, -100, 100));
        self::assertSame(100, BackfillService::clampDatePostNative(0, 100));
        self::assertSame(2000, BackfillService::clampDatePostNative(2000, 100));
        self::assertSame([400, 499], BackfillService::backfillChunkNative(500, 100, 100));
        self::assertSame([100, 149], BackfillService::backfillChunkNative(150, 100, 100));
        self::assertSame([100, 100], BackfillService::backfillChunkNative(101, 100, 100));
        $service = (new ReflectionClass(BackfillService::class))->newInstanceWithoutConstructor();
        foreach ([false, true] as $use) {
            BackfillService::setUseRustFfi($use);
            self::assertSame(100, (new ReflectionMethod($service, 'calculateTargetPost'))->invoke($service, ['first_record' => '500'], '900', ['first' => '100']));
        }
    }

    public function test_cutoffs_preserve_dst_timezone_microseconds_and_bounds(): void
    {
        foreach (['UTC', 'Europe/Madrid', 'America/New_York'] as $zone) {
            foreach (['2026-03-29 04:30:00.123456', '2026-10-25 03:30:00.654321'] as $date) {
                $now = Carbon::parse($date, $zone);
                foreach ([-1, 0, 1, 2, 48] as $hours) {
                    foreach ([false, true] as $use) {
                        CollectionCleanupService::setUseRustFfi($use);
                        self::assertSame($now->copy()->subHours($hours)->format('c.u e'), CollectionCleanupService::cutoffDate($now, $hours)->format('c.u e'));
                        self::assertSame($date, $now->format('Y-m-d H:i:s.u'));
                    }
                }
            }
        }
        self::assertSame(-3600, CollectionCleanupService::cutoffTimestampNative(0, 1));
        self::assertSame(1000, CollectionCleanupService::batchEndNative(900, 500, 1000));
        self::assertSame(120, CollectionCleanupService::batchBoundNative(999, 0, 120));
        self::assertSame(0, CollectionCleanupService::batchBoundNative(-1, 0, 120));
        self::assertSame(0, CollectionCleanupService::cleanupTotalNative([]));
        self::assertSame(12, CollectionCleanupService::cleanupTotalNative([3, 4, 5]));
    }

    public function test_processing_timing_and_count_reports(): void
    {
        foreach ([[0, '0ms'], [0.999, '999ms'], [1, '1.0s'], [1.15, '1.1s'], [1.25, '1.2s'], [1.35, '1.4s'], [59.95, '60.0s'], [60, '1m 0s'], [61.9, '1m 1s'], [3599, '59m 59s'], [3600, '1h 0m'], [90061, '25h 1m'], [-0.1, '-100ms']] as [$seconds, $expected]) {
            self::assertSame($expected, ReleaseProcessingService::formatElapsedTimeNative($seconds));
            self::assertSame($expected, ReleaseProcessingService::formatElapsedTimePhp($seconds));
        }
        $totals = ['releases' => 1, 'nzbs' => 2, 'dupes' => 3, 'iterations' => 4];
        self::assertSame(['releases' => 11, 'nzbs' => 22, 'dupes' => 33, 'iterations' => 5], ReleaseProcessingService::aggregateCountsNative($totals, 10, 20, 30));
        self::assertSame(6, ReleaseProcessingService::processingTotalNative([1, 2, 3]));
        self::assertSame(['  Releases added: 1,000', '  NZBs created: 999', '', '  Processing cycles: 2'], ReleaseProcessingService::resultSummaryNative(1000, 999, 0, 2));
        self::assertSame('  Duplicates skipped: 12', ReleaseProcessingService::resultSummaryNative(1, 2, 12, 1)[2]);
    }

    public function test_delete_stats_are_immutable_ordered_and_complete(): void
    {
        foreach ([false, true] as $use) {
            ReleaseDeleteStats::setUseRustFfi($use);
            $zero = new ReleaseDeleteStats;
            self::assertFalse($zero->hasDeletions());
            self::assertSame('No releases deleted.', $zero->toSummaryString());
            $left = new ReleaseDeleteStats(retention: 1, duplicate: 2, miscHashed: 3);
            $right = new ReleaseDeleteStats(password: 4, duplicate: 5);
            $sum = $left->add($right);
            self::assertSame(15, $sum->total());
            self::assertTrue($sum->hasDeletions());
            self::assertSame(6, $left->total());
            self::assertSame(9, $right->total());
            self::assertSame('retention: 1, password: 4, duplicate: 7, miscHashed: 3', $sum->toSummaryString());
            self::assertSame(['retention' => 1, 'password' => 4, 'duplicate' => 7, 'completion' => 0, 'disabledCategory' => 0, 'categoryMinSize' => 0, 'disabledGenre' => 0, 'miscOther' => 0, 'miscHashed' => 3, 'total' => 15], $sum->toLogContext());
            $left->retention = 10;
            self::assertSame(15, $left->total());
            self::assertSame(16, $sum->increment('completion')->total());
            self::assertSame($sum->toLogContext(), $sum->increment('invalid')->toLogContext());
        }
    }

    public function test_deterministic_randomized_parity_through_dispatchers(): void
    {
        foreach (['common', 'uncommon'] as $regime) {
            foreach ($this->workloads($regime) as [$target, $method, $fixtures]) {
                foreach ($fixtures as $args) {
                    self::assertSame($this->invoke($target, $method, $args, false), $this->invoke($target, $method, $args, true), $method);
                }
            }
        }
        // PHP's half-up correction around floating ties must not cause a different NNTP probe.
        for ($factor = 1005; $factor <= 1012; $factor++) {
            for ($current = 1; $current <= 2000; $current++) {
                self::assertSame(BinariesService::postdateProbePhp($current, 1, 4000, $factor), BinariesService::postdateProbeNative($current, 1, 4000, $factor));
            }
        }
    }

    public function test_native_paths_fall_back_on_unavailable_libraries_and_missing_symbols(): void
    {
        foreach (self::CLASSES as $class) {
            $property = new ReflectionProperty($class, 'ffi');
            $saved = $property->getValue();
            try {
                // Cached unavailable result, and a loaded ABI with the requested symbol absent.
                foreach ([false, FFI::cdef('int abs(int value);')] as $unavailable) {
                    $property->setValue(null, $unavailable);
                    foreach ($this->workloads('common') as [$target, $method, $fixtures]) {
                        if ((is_string($target) ? $target : $target::class) === $class) {
                            self::assertSame($this->invoke($target, $method, $fixtures[0], false), $this->invoke($target, $method, $fixtures[0], true), $class.'::'.$method);
                        }
                    }
                }
            } finally {
                $property->setValue(null, $saved);
            }
        }
        // Unsupported exact-double range and oversized inputs are intentionally declined.
        self::assertSame(BackfillService::targetArticlePhp(9_007_199_254_740_995, 1, 1), BackfillService::targetArticleNative(9_007_199_254_740_995, 1, 1));
        self::assertSame(BinariesService::scanDiagnosticPhp(PHP_INT_MAX - 2, PHP_INT_MAX, PHP_INT_MAX, 'x'), BinariesService::scanDiagnosticNative(PHP_INT_MAX - 2, PHP_INT_MAX, PHP_INT_MAX, 'x'));
        self::assertSame(1025, CollectionCleanupService::cleanupTotalNative(array_fill(0, 1025, 1)));
        self::assertSame(ReleaseProcessingService::resultSummaryPhp(PHP_INT_MAX, 1, 1, 1), ReleaseProcessingService::resultSummaryNative(PHP_INT_MAX, 1, 1, 1));
    }

    private function cAbi(string $symbol, mixed ...$args): int
    {
        $result = $this->ffi->$symbol(...$args);
        self::assertIsInt($result);

        return $result;
    }

    public function test_c_abi_math_validates_pointers_lengths_capacities_and_overflow_atomically(): void
    {
        foreach ([['nntmux_binaries_calculate', 1, [100, 1000, 500, 100, 0, 0], [400, 900, 900, 500, 600, 100]], ['nntmux_binaries_calculate', 2, [100, 900, 900, 800, 100], [101, 200]], ['nntmux_binaries_calculate', 3, [1, 10], [6]], ['nntmux_binaries_calculate', 4, [200, 100, 1000, 1005], [201]], ['nntmux_backfill_calculate', 0, [500, 1000, 100], [100]], ['nntmux_backfill_calculate', 1, [0, 100], [100]], ['nntmux_cleanup_calculate', 1, [900, 500, 1000], [1000]], ['nntmux_cleanup_calculate', 2, [999, 0, 120], [120]], ['nntmux_cleanup_calculate', 3, [1, 2, 3], [6]], ['nntmux_release_counts', 1, [1, 2, 3], [6]], ['nntmux_release_delete_stats', 0, array_fill(0, 18, 1), array_fill(0, 9, 2)], ['nntmux_release_delete_stats', 1, range(1, 9), [45]], ['nntmux_release_delete_stats', 3, range(1, 9), [1]], ['nntmux_binaries_calculate', 0, [100, 1000, 100, 250], [100, 350, 350, 250, 900, 100]], ['nntmux_backfill_calculate', 2, [500, 100, 100], [400, 499]], ['nntmux_cleanup_calculate', 0, [100000, 2], [92800]], ['nntmux_release_counts', 0, [1, 2, 3, 4, 10, 20, 30, 1], [11, 22, 33, 5]], ['nntmux_release_delete_stats', 2, range(1, 9), [...range(1, 9), 45]]] as [$symbol, $mode, $values, $expected]) {
            $input = $this->ffi->new('int64_t['.count($values).']');
            foreach ($values as $i => $value) {
                $input[$i] = $value;
            }
            $out = $this->ffi->new('int64_t[32]');
            $out[0] = 777;
            self::assertSame(-1, $this->cAbi($symbol, $mode, null, count($values), $out, 32));
            self::assertSame(-1, $this->cAbi($symbol, $mode, $input, PHP_INT_MAX, $out, 32));
            self::assertSame(-1, $this->cAbi($symbol, $mode, $input, count($values), $out, -1));
            self::assertSame(-1, $this->cAbi($symbol, 99, $input, count($values), $out, 32));
            self::assertSame(-1, $this->cAbi($symbol, $mode, $input, count($values), null, 1));
            self::assertSame(-2, $this->cAbi($symbol, $mode, $input, count($values), null, 0));
            self::assertSame(-2, $this->cAbi($symbol, $mode, $input, count($values), $out, count($expected) - 1));
            self::assertSame(777, $out[0]);
            self::assertSame(count($expected), $this->cAbi($symbol, $mode, $input, count($values), $out, count($expected)));
            foreach ($expected as $i => $value) {
                self::assertSame($value, $out[$i]);
            }
            $bytes = $this->ffi->new('char[256]');
            $misaligned = $this->ffi->cast('int64_t *', FFI::addr($bytes[1]));
            self::assertSame(-1, $this->cAbi($symbol, $mode, $misaligned, count($values), $out, 32));
        }
        $overflow = $this->ffi->new('int64_t[18]');
        for ($i = 0; $i < 18; $i++) {
            $overflow[$i] = PHP_INT_MAX;
        }
        $out = $this->ffi->new('int64_t[10]');
        $out[0] = 777;
        self::assertSame(-3, $this->cAbi('nntmux_release_delete_stats', 0, $overflow, 18, $out, 9));
        self::assertSame(777, $out[0]);
        $overflow[0] = PHP_INT_MIN;
        $overflow[1] = 1;
        self::assertSame(-3, $this->cAbi('nntmux_cleanup_calculate', 0, $overflow, 2, $out, 1));
        self::assertSame(777, $out[0]);
    }

    public function test_c_abi_text_error_and_exact_buffer_contracts(): void
    {
        $out = $this->ffi->new('char[512]');
        FFI::memset($out, 42, 512);
        self::assertSame(-1, $this->cAbi('nntmux_scan_diagnostic', 1, 2, 3, null, 1, $out, 512));
        self::assertSame(-1, $this->cAbi('nntmux_scan_diagnostic', 1, 2, 3, 'x', PHP_INT_MAX, $out, 512));
        self::assertSame(-1, $this->cAbi('nntmux_scan_diagnostic', 1, 2, 3, 'x', 1, $out, -1));
        self::assertSame(-1, $this->cAbi('nntmux_scan_diagnostic', 1, 2, 3, 'x', 1, null, 1));
        self::assertSame(-2, $this->cAbi('nntmux_scan_diagnostic', 1, 2, 3, 'x', 1, null, 0));
        self::assertSame(-2, $this->cAbi('nntmux_scan_diagnostic', 1, 2, 3, 'x', 1, $out, 1));
        self::assertSame(str_repeat('*', 512), FFI::string($out, 512));
        $expected = BinariesService::scanDiagnosticPhp(1, 2, 3, 'x');
        self::assertSame(strlen($expected), $this->cAbi('nntmux_scan_diagnostic', 1, 2, 3, 'x', 1, $out, strlen($expected)));
        self::assertSame($expected, FFI::string($out, strlen($expected)));
        self::assertSame('*', FFI::string(FFI::addr($out[strlen($expected)]), 1));
        self::assertSame(-3, $this->cAbi('nntmux_scan_diagnostic', PHP_INT_MIN, PHP_INT_MAX, 1, null, 0, $out, 512));
        self::assertSame(-3, $this->cAbi('nntmux_release_elapsed', INF, $out, 512));
        self::assertSame(-3, $this->cAbi('nntmux_release_elapsed', NAN, $out, 512));
        self::assertSame(-2, $this->cAbi('nntmux_release_elapsed', 60, $out, 1));
        self::assertSame(-1, $this->cAbi('nntmux_release_elapsed', 1, null, 1));
        self::assertSame(-1, $this->cAbi('nntmux_release_elapsed', 1, $out, -1));
        self::assertSame(5, $this->cAbi('nntmux_release_elapsed', 60, $out, 5));
        self::assertSame('1m 0s', FFI::string($out, 5));
        $input = $this->ffi->new('int64_t[9]');
        foreach ([0 => 4, 1 => 9] as $mode => $length) {
            self::assertSame(-1, $this->cAbi('nntmux_release_summary', $mode, null, $length, $out, 512));
            self::assertSame(-1, $this->cAbi('nntmux_release_summary', $mode, $input, PHP_INT_MAX, $out, 512));
            self::assertSame(-1, $this->cAbi('nntmux_release_summary', $mode, $input, $length, $out, -1));
            self::assertSame(-1, $this->cAbi('nntmux_release_summary', 99, $input, $length, $out, 512));
            self::assertSame(-2, $this->cAbi('nntmux_release_summary', $mode, $input, $length, null, 0));
            FFI::memset($out, 42, 512);
            self::assertSame(-2, $this->cAbi('nntmux_release_summary', $mode, $input, $length, $out, 1));
            self::assertSame(str_repeat('*', 512), FFI::string($out, 512));
            $expected = $mode === 0 ? implode("\0", ReleaseProcessingService::resultSummaryPhp(0, 0, 0, 0)) : 'No releases deleted.';
            self::assertSame(strlen($expected), $this->cAbi('nntmux_release_summary', $mode, $input, $length, $out, strlen($expected)));
            self::assertSame($expected, FFI::string($out, strlen($expected)));
        }
    }

    /** @return list<array{class-string|object, string, list<array<mixed>>}> */
    private function workloads(string $regime): array
    {
        $random = new Randomizer(new Mt19937($regime === 'common' ? 161001 : 161002));
        $cases = [];
        $register = static function (string|object $target, string $method, array $args) use (&$cases): void {
            $key = (is_string($target) ? $target : $target::class).'::'.$method;
            $cases[$key] ??= [$target, $method, []];
            $cases[$key][2][] = $args;
        };
        for ($i = 0; $i < 64; $i++) {
            $large = $regime === 'uncommon';
            $first = $random->getInt(1, $large ? 1_000_000_000_000 : 1_000_000);
            $last = $first + $random->getInt(0, $large ? 100_000_000 : 500_000);
            $buffer = $random->getInt(1, $large ? 500_000 : 20000);
            $register(BinariesService::class, 'existingHeaderRange', [$first, $last, $buffer, $i % 2 ? 100000 : 0]);
            $register(BinariesService::class, 'newHeaderRange', [$first, $last, 50000, $buffer, $i % 3 ? null : $first + 5]);
            $register(BinariesService::class, 'scanChunk', [$first, $last, $last, $last - $first, $buffer]);
            $register(BinariesService::class, 'scanDiagnostic', [$first, $last, $last + 500, $large ? str_repeat('alt.binaries.\xff', 180) : 'alt.binaries.test']);
            $register(BinariesService::class, 'articleMidpoint', [$first, $last]);
            $register(BinariesService::class, 'postdateProbe', [$first + intdiv($last - $first, 2), $first, $last, $random->getInt(1005, 1012)]);
            $register(BackfillService::class, 'targetArticle', [$last, $random->getInt(0, $buffer * 4), $first]);
            $register(BackfillService::class, 'clampDatePost', [$i % 2 ? $first - 1 : $last, $first]);
            $register(BackfillService::class, 'backfillChunk', [$last, $first, $buffer]);
            $register(CollectionCleanupService::class, 'cutoffTimestamp', [$large ? -2_000_000_000 : 1_790_000_000, $random->getInt($large ? -100000 : 0, $large ? 100000 : 72)]);
            $register(CollectionCleanupService::class, 'batchEnd', [$first, $buffer, $last]);
            $register(CollectionCleanupService::class, 'batchBound', [$random->getInt(-10, 1000), 0, 120]);
            $counts = array_map(static fn (): int => $random->getInt(0, 50000), range(1, $large ? 1000 : 3));
            $register(CollectionCleanupService::class, 'cleanupTotal', [$counts]);
            $register(ReleaseProcessingService::class, 'processingTotal', [$counts]);
            $register(ReleaseProcessingService::class, 'formatElapsedTime', [$random->getInt(0, $large ? 1000000000 : 90000) / 1000]);
            $totals = ['releases' => $first, 'nzbs' => $last, 'dupes' => $i, 'iterations' => 4];
            $register(ReleaseProcessingService::class, 'aggregateCounts', [$totals, $i, 20, 30, 1]);
            $register(ReleaseProcessingService::class, 'resultSummary', [$first, $last, $i % 2 ? 0 : 100, $i]);
        }
        // Same receiver is used by both implementations; field mutation remains supported.
        $stats = new ReleaseDeleteStats(...($regime === 'common' ? [1, 0, 2, 0, 0, 0, 0, 0, 0] : array_fill(0, 9, 1_000_000_000_000)));
        foreach (['add', 'total', 'hasDeletions', 'toSummaryString', 'toLogContext'] as $method) {
            $register($stats, $method, $method === 'add' ? [new ReleaseDeleteStats(retention: 2, miscOther: 3)] : []);
        }

        return array_values($cases);
    }

    /** @param class-string|object $target
     * @param  array<mixed>  $args
     */
    private function invoke(string|object $target, string $method, array $args, bool $rust): mixed
    {
        $class = is_string($target) ? $target : $target::class;
        $class::setUseRustFfi($rust);
        if ($method === 'articleMidpoint') {
            $service = (new ReflectionClass(BinariesService::class))->newInstanceWithoutConstructor();

            return (new ReflectionMethod($service, $method))->invokeArgs($service, $args);
        }
        $result = is_string($target) ? $target::$method(...$args) : $target->$method(...$args);

        return $result instanceof ReleaseDeleteStats ? $result->toLogContextPhp() : $result;
    }

    public function test_opt_in_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_BINARIES_BACKFILL_CLEANUP_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_BINARIES_BACKFILL_CLEANUP_BENCH=1 for paired benchmarks.');
        }
        $random = new Randomizer(new Mt19937(161099));
        $root = dirname(__DIR__, 2);
        $artifact = tempnam(sys_get_temp_dir(), 'nntmux-wave16-bench-');
        self::assertNotFalse($artifact);
        $report = [
            'protocol' => ['seed' => 161099, 'repetitions' => 2, 'pairs_per_repetition' => 5000, 'warmups' => 256, 'unit' => 'ns', 'p99' => 'nearest rank', 'speedup' => 'PHP latency / Rust latency', 'required' => 1.5, 'uncertainty' => 'paired delta-method mean ratio CI and normal-approximate p99 order-statistic bounds; descriptive local microbenchmarks, no production throughput claim'],
            'identity' => ['head' => trim((string) shell_exec('git rev-parse HEAD')), 'test_sha256' => hash_file('sha256', __FILE__), 'source_sha256' => array_combine(self::CLASSES, array_map(static fn (string $class): string => hash_file('sha256', (new ReflectionClass($class))->getFileName()), self::CLASSES)), 'rust_source_sha256' => hash_file('sha256', $root.'/rust/pipeline-ffi/src/cleaning.rs'), 'diff_sha256' => hash('sha256', (string) shell_exec('git diff --binary')), 'composer_lock_sha256' => hash_file('sha256', $root.'/composer.lock'), 'vendor_lock_sha256' => hash_file('sha256', $root.'/vendor/composer/installed.json'), 'library_sha256' => hash_file('sha256', $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')), 'php' => PHP_VERSION, 'opcache' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'rustc' => trim((string) shell_exec('rustc -Vv')), 'hardware' => php_uname(), 'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')), 'profile' => 'release opt-level=3 lto=true codegen-units=1', 'limits' => trim((string) shell_exec('ulimit -a')), 'load_average' => sys_getloadavg(), 'affinity' => 'unbound host process; no enforced CPU quota', 'php_memory_limit' => ini_get('memory_limit')],
            'results' => [], 'failures' => [],
        ];
        try {
            foreach (['common', 'uncommon'] as $regime) {
                foreach ($this->workloads($regime) as [$target, $method, $fixtures]) {
                    $class = is_string($target) ? $target : $target::class;
                    $key = $class.'::'.$method;
                    // Preflight ensures each fixture agrees before any measurements qualify.
                    foreach ($fixtures as $args) {
                        self::assertSame($this->invoke($target, $method, $args, false), $this->invoke($target, $method, $args, true), $key);
                    }
                    $call = $method === 'articleMidpoint'
                        ? (new ReflectionMethod(BinariesService::class, $method))->getClosure((new ReflectionClass(BinariesService::class))->newInstanceWithoutConstructor())
                        : Closure::fromCallable([$target, $method]);
                    self::assertNotNull($call);
                    $samples = [[], []];
                    $pairs = [];
                    for ($warmup = 0; $warmup < 256; $warmup++) {
                        $class::setUseRustFfi((bool) ($warmup % 2));
                        $call(...$fixtures[$warmup % count($fixtures)]);
                    }
                    for ($rep = 0; $rep < 2; $rep++) {
                        for ($pair = 0; $pair < 5000; $pair++) {
                            $index = $random->getInt(0, count($fixtures) - 1);
                            $args = $fixtures[$index];
                            $order = $random->getInt(0, 1) === 0 ? [0, 1] : [1, 0];
                            $values = [];
                            $latencies = [];
                            foreach ($order as $path) {
                                $class::setUseRustFfi((bool) $path);
                                $start = hrtime(true);
                                $value = $call(...$args);
                                $elapsed = hrtime(true) - $start;
                                $values[$path] = $value instanceof ReleaseDeleteStats ? $value->toLogContextPhp() : $value;
                                $latencies[$path] = $elapsed;
                                $samples[$path][] = $elapsed;
                            }
                            self::assertSame($values[0], $values[1], $key);
                            $pairs[] = [$rep, $index, $order[0], $latencies[0], $latencies[1]];
                        }
                    }
                    $metrics = [];
                    foreach ($samples as $path => $observations) {
                        sort($observations, SORT_NUMERIC);
                        $n = count($observations);
                        $rankMargin = 2.24 * sqrt($n * 0.99 * 0.01);
                        $metrics[$path] = ['mean' => array_sum($observations) / $n, 'p99' => $observations[(int) ceil($n * 0.99) - 1], 'samples' => $n, 'p99_rank_bounds' => [$observations[max(0, (int) floor($n * 0.99 - $rankMargin) - 1)], $observations[min($n - 1, (int) ceil($n * 0.99 + $rankMargin) - 1)]]];
                    }
                    $speedups = ['mean' => $metrics[0]['mean'] / $metrics[1]['mean'], 'p99' => $metrics[0]['p99'] / $metrics[1]['p99']];
                    $variance = 0.0;
                    foreach ($pairs as $pair) {
                        $residual = $pair[3] - $speedups['mean'] * $pair[4];
                        $variance += $residual * $residual;
                    }
                    $n = count($pairs);
                    $margin = 1.96 * sqrt($variance / ($n * ($n - 1))) / $metrics[1]['mean'];
                    $uncertainty = ['mean_ratio_approx_95_interval' => [$speedups['mean'] - $margin, $speedups['mean'] + $margin], 'p99_ratio_approx_95_bounds' => [$metrics[0]['p99_rank_bounds'][0] / $metrics[1]['p99_rank_bounds'][1], $metrics[0]['p99_rank_bounds'][1] / $metrics[1]['p99_rank_bounds'][0]]];
                    $report['results'][$key][$regime] = ['metrics' => $metrics, 'speedups' => $speedups, 'uncertainty' => $uncertainty, 'raw_pairs' => $pairs, 'fixtures_serialized_base64' => base64_encode(serialize(array_map(static fn (array $args): array => array_map(static fn (mixed $arg): mixed => $arg instanceof ReleaseDeleteStats ? ['release_delete_stats' => $arg->toLogContextPhp()] : $arg, $args), $fixtures))), 'receiver' => $target instanceof ReleaseDeleteStats ? $target->toLogContextPhp() : null];
                    fwrite(STDERR, sprintf("\n%s %s mean %.3fx p99 %.3fx", $key, $regime, $speedups['mean'], $speedups['p99']));
                }
            }
        } catch (Throwable $failure) {
            $report['failures'][] = $failure::class.': '.$failure->getMessage();
            throw $failure;
        } finally {
            file_put_contents($artifact, json_encode($report, JSON_THROW_ON_ERROR));
            fwrite(STDERR, "\nWave 16 benchmark artifact: {$artifact}\n");
        }
        foreach (self::CLASSES as $class) {
            self::assertFalse((new ReflectionClass($class))->getDefaultProperties()['useRustFfi'], 'Default must stay PHP until all four metrics qualify.');
        }
    }
}
