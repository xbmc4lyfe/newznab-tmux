<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\AdditionalBatchResult;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\DownloadedArchive;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\DTO\MediaSourcePlan;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustAdditionalBatchResultWorkPlanArchiveHintsPlanW54ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        AdditionalBatchResult::class => 'summarizeRunBatch',
        AdditionalWorkPlan::class => 'summarizePlanBatch',
        DownloadedArchive::class => 'summarizeListingBatch',
        MediaSourceHints::class => 'normalizeHintBatch',
        MediaSourcePlan::class => 'articleCoverageBatch',
    ];

    /** Header words before the per-record rows, and rows per record. */
    private const LAYOUT = [
        AdditionalBatchResult::class => [AdditionalBatchResult::W54_RUN_HEADER, 2],
        AdditionalWorkPlan::class => [AdditionalWorkPlan::W54_PLAN_HEADER, 1],
        DownloadedArchive::class => [DownloadedArchive::W54_LISTING_HEADER, 1],
        MediaSourceHints::class => [MediaSourceHints::W54_HINT_HEADER, 1],
        MediaSourcePlan::class => [MediaSourcePlan::W54_COVERAGE_HEADER, 2],
    ];

    /**
     * Apple M-series/macOS arm64 (shared host, load ~8-9), PHP 8.5.12 CLI without opcache/JIT, release
     * Rust; base 88138ba6a plus this change, seed 546, 50 warmups and 1,000 pairs/workload
     * (w546-benchmark-d03fcbd3f300432e.json in system tmp). Common mean/p99 then uncommon mean/p99
     * speedups (PHP time / Rust time); a default is Rust only when all four reach 1.5x. Across three
     * further 1,000-pair runs every metric stayed at or above 1.87x; p99 ratios on this host carry
     * ~15us of additive scheduling noise on 2-5us native calls while every mean stayed above 2.2x.
     */
    private const QUALIFICATION = [
        AdditionalBatchResult::class => [4.437, 2.422, 5.380, 3.680],
        AdditionalWorkPlan::class => [4.952, 3.028, 5.893, 4.455],
        DownloadedArchive::class => [3.342, 2.822, 2.671, 1.965],
        MediaSourceHints::class => [4.003, 2.373, 2.560, 2.064],
        MediaSourcePlan::class => [4.196, 2.071, 4.938, 3.186],
    ];

    private const VALUE_CAP = 1_099_511_627_775;

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isReadOnly() && $reflection->isFinal(), $class);
            foreach (['usesW54RustFfi', 'withW54RustFfi', 'w54RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w54RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW54RustFfi', 'withW54RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W54_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w546_', (string) $reflection->getConstant('W54_FFI_CDEF'), $class);
            self::assertSame([], $reflection->getProperties(\ReflectionProperty::IS_STATIC), $class);
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        $earlier = static fn (): array => [
            AdditionalBatchResult::usesW47OutcomeTelemetryRustFfi(),
            AdditionalWorkPlan::usesW47TaskScheduleRustFfi(),
            DownloadedArchive::usesW47VolumeVerifyRustFfi(),
            MediaSourceHints::usesW48HintExtractRustFfi(),
            MediaSourcePlan::usesW48SlicePlanRustFfi(),
            MediaSourcePlan::usesRustFfi(),
        ];
        $before = $earlier();
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W54_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW54RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW54RustFfi($enabled, function () use ($class, $enabled, $earlier, $before): bool {
                    $inner = $class::withW54RustFfi(! $enabled, static fn (): bool => $class::usesW54RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W54_RUST_FFI_DEFAULT'), $other::usesW54RustFfi());
                        }
                    }
                    // The Wave 54 switches leave the earlier toggles alone.
                    self::assertSame($before, $earlier());

                    return $class::usesW54RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW54RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW54RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW54RustFfi(), $class);
        }
        self::assertSame($before, $earlier());
    }

    public function test_run_summary_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertRunParity([], [], [], [], [], []);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, -1, -1, -1, 0, 0], \array_slice($empty, 0, 12));
        $out = $this->assertRunParity([10, 0, 4], [8, 0, 6], [6, 0, 7], [3, 0, 9], [2_000_000, 0, 0], [500, 900, 900]);
        self::assertSame([3, 14, 14, 13, 12, 2_000_000, 900, 1, 0, 0, 1, 875_000], \array_slice($out, 0, 12));
        $base = AdditionalBatchResult::W54_RUN_HEADER;
        self::assertSame(4_000, $out[$base]);
        self::assertSame(750_000 | 500_000 << 20 | 250 << 40, $out[$base + 1]);
        self::assertSame(30 << 40, $out[$base + 4]);
        $extreme = [PHP_INT_MAX, PHP_INT_MIN, -1, self::VALUE_CAP + 1, 0];
        $this->assertRunParity($extreme, array_reverse($extreme), $extreme, $extreme, array_reverse($extreme), $extreme);
        // Short and keyed columns pad with 0.
        $this->assertRunParity(['a' => 5, 'b' => 7], [3], ['x' => 2, 'y' => 9], [], [100], [50, 60, 70]);
    }

    public function test_plan_summary_parity(): void
    {
        $this->requireBindings();
        $this->assertPlanParity([], [], [], [], [], [], [], [], [], []);
        $out = $this->assertPlanParity([2, 0, 0], [1, 0, 0], [3, 0, 0], [0, 0, 1], [1, 0, 0], [1, 0, 0], [0, 0, 0], [0, 5, 0], [2, 0, 0], [1, 4, 8]);
        self::assertSame([3, 2, 1, 1, 1, 1, 1, 0, 1, 1, 0, 43, 0, 1], \array_slice($out, 0, 14));
        self::assertSame(0b0100_1111 | 3 << 8 | 39 << 10 | 400_000 << 40, $out[AdditionalWorkPlan::W54_PLAN_HEADER]);
        // The supported count mirrors hasSupportedCandidates().
        foreach ([new AdditionalWorkPlan, new AdditionalWorkPlan(mediaInfoMessageId: 'x'), new AdditionalWorkPlan(sampleMessageIds: ['a', 'b'])] as $plan) {
            $flags = ($plan->mediaInfoMessageId !== '' ? AdditionalWorkPlan::W54_FLAG_MEDIA_INFO : 0) | ($plan->audioInfoMessageId !== '' ? AdditionalWorkPlan::W54_FLAG_AUDIO_INFO : 0);
            $row = $this->assertPlanParity([\count($plan->sampleMessageIds)], [\count($plan->jpgMessageIds)], [\count($plan->archiveCandidates)], [\count($plan->probeCandidates)], [0], [0], [0], [0], [0], [$flags]);
            self::assertSame($plan->hasSupportedCandidates() ? 1 : 0, $row[1]);
        }
        $extreme = [PHP_INT_MAX, PHP_INT_MIN, -1, 1_048_576, 0];
        $this->assertPlanParity($extreme, $extreme, $extreme, $extreme, $extreme, $extreme, $extreme, $extreme, $extreme, $extreme);
        $this->assertPlanParity(['k' => 1, 'j' => 2], [5], [], [1, 1, 1], [], [], [], [3], [], [15]);
    }

    public function test_listing_summary_parity(): void
    {
        $this->requireBindings();
        $this->assertListingParity([], [], []);
        $this->assertListingParity([''], [], []);
        $out = $this->assertListingParity(['d/sub', 'd/sub/a.nfo', 'd\\b.mkv', 'c.rar'], [99, 10, 400, 400], [1, 0, 0, 2]);
        self::assertSame([4, 3, 1, 810, 1, 1, 0, 0, 1, 0, 0, 1, 0, 2, 1, 2], \array_slice($out, 0, 16));
        $names = ['Movie.MKV', 'a.flac', 'cover.JPeG', 'info.nfo', 'x.sfv', 'x.vol01+02.PAR2', 'x.r07', 'x.001', 'x.7z', 'x.rx7', "x.ts\0", 'noext', 'x.', 'dir.v1/file', "a\0b.mkv", 'x.R99', 'x.0a1'];
        $out = $this->assertListingParity($names, [], []);
        $classes = array_map(static fn (int $row): int => $row & 15, \array_slice($out, DownloadedArchive::W54_LISTING_HEADER));
        self::assertSame([0, 1, 2, 3, 4, 5, 6, 6, 6, 7, 7, 7, 7, 7, 0, 6, 7], $classes);
        // Matches fileSummaryPhp() on the same listing.
        $files = [['name' => 'a.mkv', 'size' => 700], ['name' => 'd', 'is_dir' => true, 'size' => 5], ['name' => 'b.rar', 'size' => 300, 'pass' => true]];
        $summary = (new DownloadedArchive('t', '', $files))->fileSummaryPhp();
        $out = $this->assertListingParity(array_column($files, 'name'), array_column($files, 'size'), [0, 1, 2]);
        self::assertSame([$summary['files'], $summary['directories'], $summary['bytes'], $summary['passworded'] ? 1 : 0], \array_slice($out, 1, 4));
        $this->assertListingParity(['a.mkv', 'b.nfo'], [PHP_INT_MAX, PHP_INT_MIN], [PHP_INT_MAX, -1]);
        $this->assertListingParity(['x' => 'a/b/c.nfo', 'y' => str_repeat('/', 300).'z.txt'], ['q' => 5], []);
    }

    public function test_hint_normalize_parity(): void
    {
        $this->requireBindings();
        $this->assertHintParity([], [], []);
        $out = $this->assertHintParity(['head', 'head', 'mid', 'complete', 'HEAD'], [100, 4096, 100, 0, -5], [150, 0, 50, 7, 3]);
        self::assertSame([5, 1, 2, 1, 1, 2, 2, 1, 1, 2, 210, 4096, 1_000_000, 0], \array_slice($out, 0, 14));
        // Rows agree with headPhp() and availableFractionPhp() for head hints.
        foreach ([[null, 10], [0, 10], [1000, 250], [1000, 1000], [1000, 0], [7, 9]] as [$real, $available]) {
            $hint = MediaSourceHints::headPhp($real, $available);
            $row = $this->assertHintParity(['head'], [$real], [$available])[MediaSourceHints::W54_HINT_HEADER];
            self::assertSame($hint->position->value === 'complete' ? 0 : 1, $row & 3);
            $fraction = $hint->availableFractionPhp();
            self::assertSame($fraction === null ? 0 : (int) floor($fraction * 1_000_000) + 1, ($row >> 4) & 0xFFFFF);
        }
        $this->assertHintParity(['mid', "he\0ad", '', 'Complete'], [null, PHP_INT_MAX, PHP_INT_MIN, 5], [PHP_INT_MAX, -1, 3]);
        $this->assertHintParity(['a' => 'head', 'b' => 'mid'], ['z' => 10], []);
    }

    public function test_article_coverage_parity(): void
    {
        $this->requireBindings();
        $this->assertCoverageParity([], [], [], null);
        $out = $this->assertCoverageParity([3, 3, -1, 0], [2_500, 1_000, 0, 7], [2, 2, 4, 9], 1_000);
        self::assertSame([4, 1_000, 3, 1, 2_507, 4, 15, 1, 1, 1, 1, 0, 833_333], \array_slice($out, 0, 13));
        $base = MediaSourcePlan::W54_COVERAGE_HEADER;
        self::assertSame([3 | 1 << 24, 666_666 | 500 << 20], [$out[$base], $out[$base + 1]]);
        foreach ([null, 0, -5, 1, PHP_INT_MAX, 384_000] as $part) {
            $this->assertCoverageParity([1, 2, 1], [10_000_000, 1, PHP_INT_MAX], [14, 0, PHP_INT_MAX], $part);
        }
        $extreme = [PHP_INT_MAX, PHP_INT_MIN, -1, self::VALUE_CAP + 1, 0];
        $this->assertCoverageParity($extreme, $extreme, $extreme, 1);
        $this->assertCoverageParity(['a' => 1, 'b' => 1], [500], ['q' => 1], 100);
    }

    /** @return iterable<string, array{class-string, int, bool, int}> */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            foreach ([[64, false, 1], [256, true, 2], [128, true, 3], [200, false, 4]] as [$count, $uncommon, $seed]) {
                yield $short.' '.$count.($uncommon ? ' uncommon' : ' common').' #'.$seed => [$class, $count, $uncommon, $seed];
            }
        }
    }

    /** @param class-string $class */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $call = $this->benchmarkCall($class, $count, $uncommon, $seed);
        $php = $class::withW54RustFfi(false, $call);
        $rust = $class::withW54RustFfi(true, $call);
        self::assertSame($php, $rust, $class);
        // Realistic fixtures stay on the Rust path rather than falling back to PHP.
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $native = self::WAVE[$class].'Native';
        self::assertSame($php, $class::$native(...$args), $class);
        $method = self::WAVE[$class].'Php';
        self::assertSame($class::$method(...$args), $rust, $class);
        [$header, $perRecord] = self::LAYOUT[$class];
        self::assertCount($header + $perRecord * $count, $rust);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(546));
        $alphabet = "abcdefABCDEF0123456789 ._-/\\\0\x01rR";
        $text = static function (int $max) use ($random, $alphabet): string {
            $length = $random->getInt(0, $max);
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $random->getInt(0, 30) === 0 ? \chr($random->getInt(0, 255)) : $alphabet[$random->getInt(0, \strlen($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 6)) {
            0 => PHP_INT_MIN, 1 => PHP_INT_MAX, 2 => 0, 3 => -1, 4 => 1,
            default => $random->getInt(-2_000_000_000, 2_000_000_000),
        };
        $value = static fn (int $max): int => $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, $max);
        for ($round = 0; $round < 80; $round++) {
            $count = $random->getInt(0, 24);
            $runs = array_fill(0, 6, []);
            $plans = array_fill(0, 10, []);
            $names = $sizes = $flags = $positions = $reals = $available = $sources = $realSizes = $articles = [];
            for ($i = 0; $i < $count; $i++) {
                foreach (array_keys($runs) as $k) {
                    $runs[$k][] = $value($k >= 4 ? 50_000_000 : 300);
                }
                foreach (array_keys($plans) as $k) {
                    $plans[$k][] = $value($k === 9 ? 15 : 40);
                }
                $names[] = $random->getInt(0, 2) === 0 ? $text(14) : ['a.mkv', 'b.NFO', 'c.r01', 'd.002', 'e/f.par2', 'g.txt', ''][$random->getInt(0, 6)];
                $sizes[] = $value(5_000_000_000);
                $flags[] = $value(3);
                $positions[] = $random->getInt(0, 3) === 0 ? $text(9) : ['head', 'mid', 'complete'][$random->getInt(0, 2)];
                $reals[] = $random->getInt(0, 4) === 0 ? null : $value(2_000_000);
                $available[] = $value(2_500_000);
                $sources[] = $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 12);
                $realSizes[] = $value(4_000_000_000);
                $articles[] = $value(6_000);
            }
            $this->assertRunParity(...$runs);
            $this->assertPlanParity(...$plans);
            $this->assertListingParity($names, $sizes, $flags);
            $this->assertHintParity($positions, $reals, $available);
            $this->assertCoverageParity($sources, $realSizes, $articles, $random->getInt(0, 3) === 0 ? $int() : [null, 716_800, 384_000, 1][$random->getInt(0, 3)]);
        }
    }

    /**
     * Protocol (set NNTMUX_W546_BENCH=1): seed 546, 8 fixture variants, 50 warmups and
     * NNTMUX_W546_BENCH_PAIRS (default 1,000, at least 100) randomized-order pairs per
     * class/workload. Common batches hold 64 typical records; uncommon ones 256 padded, mixed-case,
     * empty, invalid or extreme records. Fixtures are built outside timing and parity is asserted on
     * every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W546_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W546_BENCH=1 to run the paired Wave 54.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(100, (int) (getenv('NNTMUX_W546_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(546));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 256 : 64, $uncommon, 546 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW54RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 546, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w546-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW54.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertSame($eligible, $class::usesW54RustFfi(), $class.' must default to Rust exactly when it clears >=1.5x mean/p99 on both workloads');
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
            AdditionalBatchResult::class => $this->runBatch($random, $count, $uncommon),
            AdditionalWorkPlan::class => $this->planBatch($random, $count, $uncommon),
            DownloadedArchive::class => $this->listingBatch($random, $count, $uncommon),
            MediaSourceHints::class => $this->hintBatch($random, $count, $uncommon),
            default => $this->coverageBatch($random, $count, $uncommon),
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
     * Batch runs; uncommon ones are idle, setup failures, inconsistent or extreme.
     *
     * @return list<list<int>>
     */
    private function runBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $columns = array_fill(0, 6, []);
        for ($i = 0; $i < $count; $i++) {
            $claimed = $random->getInt(1, 200);
            $attempted = $random->getInt(1, $claimed);
            $successful = $random->getInt(0, $attempted);
            $row = [$claimed, $attempted, $successful, $random->getInt(0, $successful), $attempted * $random->getInt(200_000, 9_000_000), $random->getInt(64, 900) << 20];
            if ($uncommon) {
                $row = match ($random->getInt(0, 4)) {
                    0 => [$claimed, 0, 0, 0, $random->getInt(0, 5_000), $row[5]],
                    1 => [0, $attempted, $attempted + 3, $attempted + 9, 0, 0],
                    2 => [PHP_INT_MAX, PHP_INT_MIN, -1, self::VALUE_CAP + 1, PHP_INT_MAX, -7],
                    default => $row,
                };
            }
            foreach ($row as $k => $v) {
                $columns[$k][] = $v;
            }
        }

        return $columns;
    }

    /**
     * Per-plan candidate counts; uncommon ones are unsupported, book floods, duplicate-heavy or extreme.
     *
     * @return list<list<int>>
     */
    private function planBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $columns = array_fill(0, 10, []);
        for ($i = 0; $i < $count; $i++) {
            $archives = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 60);
            $row = [
                $random->getInt(0, 2), $random->getInt(0, 4), $archives, $archives === 0 ? $random->getInt(0, 3) : 0,
                $random->getInt(0, 2), $random->getInt(0, 3), $random->getInt(0, 4), $random->getInt(0, 1) * $random->getInt(0, 30),
                $random->getInt(0, 3), $random->getInt(0, 3),
            ];
            if ($uncommon) {
                $row = match ($random->getInt(0, 4)) {
                    0 => [0, 0, 0, 0, $random->getInt(0, 3), 0, 0, 0, 0, 0],
                    1 => [0, 0, 0, 0, 0, 0, 0, $random->getInt(500, 5_000), 0, 4],
                    2 => [$random->getInt(0, 3), $random->getInt(0, 3), 0, 0, 0, 0, 0, 0, $random->getInt(10, 900), 3],
                    3 => [PHP_INT_MAX, PHP_INT_MIN, 1_048_576, -1, PHP_INT_MAX, 7, 9, PHP_INT_MAX, PHP_INT_MAX, -1],
                    default => $row,
                };
            }
            foreach ($row as $k => $v) {
                $columns[$k][] = $v;
            }
        }

        return $columns;
    }

    /**
     * One archive listing; uncommon entries have odd case, volumes, deep paths, NULs or extreme sizes.
     *
     * @return array{0: list<string>, 1: list<int>, 2: list<int>}
     */
    private function listingBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $extensions = ['mkv', 'mp4', 'nfo', 'sfv', 'jpg', 'par2', 'rar', 'srt', 'txt', 'flac'];
        $names = $sizes = $flags = [];
        for ($i = 0; $i < $count; $i++) {
            $stem = 'Show.Name.S01E'.str_pad((string) $random->getInt(1, 24), 2, '0', STR_PAD_LEFT).'.1080p.WEB-DL';
            $name = ($random->getInt(0, 3) === 0 ? 'Season 1/' : '').$stem.'.'.$extensions[$random->getInt(0, 9)];
            $size = $random->getInt(1_000, 4_000_000_000);
            $flag = $random->getInt(0, 15) === 0 ? 1 : 0;
            if ($uncommon) {
                [$name, $size, $flag] = match ($random->getInt(0, 6)) {
                    0 => [strtoupper($name), $size, $flag | 2],
                    1 => [$stem.'.r'.str_pad((string) $random->getInt(0, 99), 2, '0', STR_PAD_LEFT), $size, 0],
                    2 => [$stem.'.'.str_pad((string) $random->getInt(0, 999), 3, '0', STR_PAD_LEFT), $size, 0],
                    3 => [str_repeat('deep/', $random->getInt(5, 40)).'x\\y.Nfo', PHP_INT_MAX, $random->getInt(0, 7)],
                    4 => ["bad\0name.mkv", -1, 0],
                    5 => ['', PHP_INT_MIN, PHP_INT_MAX],
                    default => [$name, $size, $flag],
                };
            }
            $names[] = $name;
            $sizes[] = $size;
            $flags[] = $flag;
        }

        return [$names, $sizes, $flags];
    }

    /**
     * Media hints; uncommon ones have unknown sizes, odd positions, nothing available or extremes.
     *
     * @return array{0: list<string>, 1: list<int|null>, 2: list<int>}
     */
    private function hintBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $positions = $reals = $available = [];
        for ($i = 0; $i < $count; $i++) {
            $real = $random->getInt(50_000_000, 8_000_000_000);
            $position = ['head', 'head', 'head', 'mid', 'complete'][$random->getInt(0, 4)];
            $bytes = $random->getInt(0, 4) === 0 ? $real : $random->getInt(1_000_000, 40_000_000);
            if ($uncommon) {
                [$position, $real, $bytes] = match ($random->getInt(0, 5)) {
                    0 => [$position, null, $bytes],
                    1 => [['HEAD', 'Mid', ' complete', '', "he\0ad"][$random->getInt(0, 4)], $real, $bytes],
                    2 => [$position, $real, 0],
                    3 => [$position, PHP_INT_MAX, PHP_INT_MAX],
                    4 => [$position, PHP_INT_MIN, -1],
                    default => [$position, $real, $bytes],
                };
            }
            $positions[] = $position;
            $reals[] = $real;
            $available[] = $bytes;
        }

        return [$positions, $reals, $available];
    }

    /**
     * Mapped files with posted article counts; uncommon ones repeat or negate source indexes, lack sizes
     * or carry extremes, under an unusual part size.
     *
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: int|null}
     */
    private function coverageBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $part = $uncommon ? [null, 0, 384_000, 1][$random->getInt(0, 3)] : 716_800;
        $sources = $reals = $articles = [];
        for ($i = 0; $i < $count; $i++) {
            $real = $random->getInt(1_000_000, 4_000_000_000);
            $expected = intdiv($real + 716_799, 716_800);
            $posted = $random->getInt(0, 4) === 0 ? $random->getInt(0, $expected) : $expected;
            $source = $i;
            if ($uncommon) {
                [$source, $real, $posted] = match ($random->getInt(0, 5)) {
                    0 => [$random->getInt(0, 20), $real, $posted],
                    1 => [-$random->getInt(1, 5), $real, $posted],
                    2 => [$source, 0, $random->getInt(0, 9)],
                    3 => [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
                    4 => [PHP_INT_MIN, PHP_INT_MIN, -1],
                    default => [$source, $real, $posted],
                };
            }
            $sources[] = $source;
            $reals[] = $real;
            $articles[] = $posted;
        }

        return [$sources, $reals, $articles, $part];
    }

    /**
     * @param  array<array-key, int>  $claimed
     * @param  array<array-key, int>  $attempted
     * @param  array<array-key, int>  $successful
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $elapsed
     * @param  array<array-key, int>  $memory
     * @return list<int>
     */
    private function assertRunParity(array $claimed, array $attempted, array $successful, array $artifacts, array $elapsed, array $memory): array
    {
        $args = [$claimed, $attempted, $successful, $artifacts, $elapsed, $memory];
        $php = AdditionalBatchResult::withW54RustFfi(false, static fn (): array => AdditionalBatchResult::summarizeRunBatch(...$args));
        $rust = AdditionalBatchResult::withW54RustFfi(true, static fn (): array => AdditionalBatchResult::summarizeRunBatch(...$args));
        self::assertSame($php, AdditionalBatchResult::summarizeRunBatchPhp(...$args));
        self::assertSame($php, AdditionalBatchResult::summarizeRunBatchNative(...$args));
        self::assertSame($php, $rust);
        self::assertCount(AdditionalBatchResult::W54_RUN_HEADER + 2 * \count($claimed), $php);

        return $php;
    }

    /**
     * @param  array<array-key, int>  $samples
     * @param  array<array-key, int>  $jpgs
     * @param  array<array-key, int>  $archives
     * @param  array<array-key, int>  $probes
     * @param  array<array-key, int>  $par2
     * @param  array<array-key, int>  $videos
     * @param  array<array-key, int>  $images
     * @param  array<array-key, int>  $books
     * @param  array<array-key, int>  $duplicates
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    private function assertPlanParity(array $samples, array $jpgs, array $archives, array $probes, array $par2, array $videos, array $images, array $books, array $duplicates, array $flags): array
    {
        $args = [$samples, $jpgs, $archives, $probes, $par2, $videos, $images, $books, $duplicates, $flags];
        $php = AdditionalWorkPlan::withW54RustFfi(false, static fn (): array => AdditionalWorkPlan::summarizePlanBatch(...$args));
        $rust = AdditionalWorkPlan::withW54RustFfi(true, static fn (): array => AdditionalWorkPlan::summarizePlanBatch(...$args));
        self::assertSame($php, AdditionalWorkPlan::summarizePlanBatchPhp(...$args));
        self::assertSame($php, AdditionalWorkPlan::summarizePlanBatchNative(...$args));
        self::assertSame($php, $rust);
        self::assertCount(AdditionalWorkPlan::W54_PLAN_HEADER + \count($samples), $php);

        return $php;
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    private function assertListingParity(array $names, array $sizes, array $flags): array
    {
        $php = DownloadedArchive::withW54RustFfi(false, static fn (): array => DownloadedArchive::summarizeListingBatch($names, $sizes, $flags));
        $rust = DownloadedArchive::withW54RustFfi(true, static fn (): array => DownloadedArchive::summarizeListingBatch($names, $sizes, $flags));
        self::assertSame($php, DownloadedArchive::summarizeListingBatchPhp($names, $sizes, $flags));
        self::assertSame($php, DownloadedArchive::summarizeListingBatchNative($names, $sizes, $flags), json_encode(array_map(bin2hex(...), array_values($names)), JSON_THROW_ON_ERROR));
        self::assertSame($php, $rust);
        self::assertCount(DownloadedArchive::W54_LISTING_HEADER + \count($names), $php);

        return $php;
    }

    /**
     * @param  array<array-key, string>  $positions
     * @param  array<array-key, int|null>  $reals
     * @param  array<array-key, int>  $available
     * @return list<int>
     */
    private function assertHintParity(array $positions, array $reals, array $available): array
    {
        $php = MediaSourceHints::withW54RustFfi(false, static fn (): array => MediaSourceHints::normalizeHintBatch($positions, $reals, $available));
        $rust = MediaSourceHints::withW54RustFfi(true, static fn (): array => MediaSourceHints::normalizeHintBatch($positions, $reals, $available));
        self::assertSame($php, MediaSourceHints::normalizeHintBatchPhp($positions, $reals, $available));
        self::assertSame($php, MediaSourceHints::normalizeHintBatchNative($positions, $reals, $available), json_encode(array_map(bin2hex(...), array_values($positions)), JSON_THROW_ON_ERROR));
        self::assertSame($php, $rust);
        self::assertCount(MediaSourceHints::W54_HINT_HEADER + \count($positions), $php);

        return $php;
    }

    /**
     * @param  array<array-key, int>  $sources
     * @param  array<array-key, int>  $reals
     * @param  array<array-key, int>  $articles
     * @return list<int>
     */
    private function assertCoverageParity(array $sources, array $reals, array $articles, ?int $part): array
    {
        $php = MediaSourcePlan::withW54RustFfi(false, static fn (): array => MediaSourcePlan::articleCoverageBatch($sources, $reals, $articles, $part));
        $rust = MediaSourcePlan::withW54RustFfi(true, static fn (): array => MediaSourcePlan::articleCoverageBatch($sources, $reals, $articles, $part));
        self::assertSame($php, MediaSourcePlan::articleCoverageBatchPhp($sources, $reals, $articles, $part));
        self::assertSame($php, MediaSourcePlan::articleCoverageBatchNative($sources, $reals, $articles, $part));
        self::assertSame($php, $rust);
        self::assertCount(MediaSourcePlan::W54_COVERAGE_HEADER + 2 * \count($sources), $php);

        return $php;
    }

    private function requireBindings(): void
    {
        if (AdditionalBatchResult::summarizeRunBatchNative([], [], [], [], [], []) === null
            || AdditionalWorkPlan::summarizePlanBatchNative([], [], [], [], [], [], [], [], [], []) === null
            || DownloadedArchive::summarizeListingBatchNative([], [], []) === null
            || MediaSourceHints::normalizeHintBatchNative([], [], []) === null
            || MediaSourcePlan::articleCoverageBatchNative([], [], [], null) === null
        ) {
            self::markTestSkipped('The Wave 54.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
