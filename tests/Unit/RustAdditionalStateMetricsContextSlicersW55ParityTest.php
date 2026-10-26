<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Media\ArchivedMediaSlicer;
use App\Services\AdditionalProcessing\Media\ContainerSplicer;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ProcessingMetrics;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustAdditionalStateMetricsContextSlicersW55ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        PersistenceMetricsCollector::class => 'foldScopedEventBatch',
        ProcessingMetrics::class => 'summarizeStageBatch',
        ReleaseProcessingContext::class => 'summarizeContextBatch',
        ArchivedMediaSlicer::class => 'classifyStoredVideoBatch',
        ContainerSplicer::class => 'probeResumeBatch',
    ];

    /** Header words before the per-record rows, and rows per record. */
    private const LAYOUT = [
        PersistenceMetricsCollector::class => [PersistenceMetricsCollector::W55_EVENT_HEADER, 1],
        ProcessingMetrics::class => [ProcessingMetrics::W55_STAGE_HEADER, 1],
        ReleaseProcessingContext::class => [ReleaseProcessingContext::W55_CONTEXT_HEADER, 1],
        ArchivedMediaSlicer::class => [ArchivedMediaSlicer::W55_STORED_HEADER, 1],
        ContainerSplicer::class => [ContainerSplicer::W55_PROBE_HEADER, 2],
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release
     * Rust; base 4fd425d91 plus this change, seed 556, 50 warmups and 1,000 pairs/workload
     * (w556-benchmark-300ea7f40ce0e69e.json in system tmp). Common mean/p99 then uncommon mean/p99
     * speedups (PHP time / Rust time); a default is Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        PersistenceMetricsCollector::class => [2.773, 3.041, 3.811, 3.567],
        ProcessingMetrics::class => [2.610, 2.509, 2.500, 2.157],
        ReleaseProcessingContext::class => [4.635, 3.116, 6.122, 4.619],
        ArchivedMediaSlicer::class => [2.887, 3.837, 2.846, 2.555],
        ContainerSplicer::class => [6.293, 5.959, 5.262, 5.223],
    ];

    private const STAGES = [
        'workspace-preparation', 'nzb-parsing', 'release-initialization', 'message-id-selection', 'media-source-planning',
        'direct-downloads', 'archive-downloads', 'extracted-files', 'media-sources', 'archive-fallbacks', 'timeout-handling',
        'finalization', 'workspace-cleanup',
    ];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW55RustFfi', 'withW55RustFfi', 'w55RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w55RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW55RustFfi', 'withW55RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W55_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w556_', (string) $reflection->getConstant('W55_FFI_CDEF'), $class);
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        $earlier = static fn (): array => [
            PersistenceMetricsCollector::usesW466RustFfi(),
            ProcessingMetrics::usesW466RustFfi(),
            ProcessingMetrics::usesRustFfi(),
            ReleaseProcessingContext::usesW466RustFfi(),
            ArchivedMediaSlicer::usesRustFfi(),
            ArchivedMediaSlicer::usesW50RustFfi(),
            ContainerSplicer::usesRustFfi(),
            ContainerSplicer::usesW50RustFfi(),
        ];
        $before = $earlier();
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W55_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW55RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW55RustFfi($enabled, function () use ($class, $enabled, $earlier, $before): bool {
                    $inner = $class::withW55RustFfi(! $enabled, static fn (): bool => $class::usesW55RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W55_RUST_FFI_DEFAULT'), $other::usesW55RustFfi());
                        }
                    }
                    // The Wave 55 switches leave the earlier toggles alone.
                    self::assertSame($before, $earlier());

                    return $class::usesW55RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW55RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW55RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW55RustFfi(), $class);
        }
        self::assertSame($before, $earlier());
    }

    public function test_scoped_event_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertEventParity([], [], [], []);
        self::assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, -1, 0], \array_slice($empty, 0, 11));
        // No scope, then scope 7 (statement, request, foreign execution), then scope 9 with an unknown kind.
        $out = $this->assertEventParity([0, 0, 1, 2, 1, 9], [-1, 7, 7, 7, 9, 9], [0, 0, 7, 8, 9, 0], [50, 300, 0, 0, 0, 0]);
        self::assertSame([6, 1, 300, 2, 0, 2, 1, 2, 300, 1, 2], \array_slice($out, 0, 11));
        // Missing columns, keyed input, extreme values and saturation.
        $this->assertEventParity([0, 1, 2], [5], [], []);
        $this->assertEventParity(['a' => 0, 'b' => 0], ['x' => 3, 'y' => 3], [3, 3], [PHP_INT_MAX, PHP_INT_MIN]);
        $this->assertEventParity([PHP_INT_MIN, PHP_INT_MAX, -1, 3], [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, 0], [PHP_INT_MAX, 0, 0, 0], [1, 2, 3, 4]);
        $many = 70_000;
        $out = $this->assertEventParity(array_fill(0, $many, 1), array_fill(0, $many, 4), array_fill(0, $many, 4), array_fill(0, $many, 1 << 30));
        self::assertSame(65_535, ($out[PersistenceMetricsCollector::W55_EVENT_HEADER + $many - 1] >> 25) & 0xFFFF);
    }

    public function test_stage_summary_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertStageParity([], []);
        self::assertSame(-1, $empty[16]);
        $out = $this->assertStageParity(['nzb-parsing', 'finalization', 'NZB-PARSING', 'nzb-parsing'], [3_000_000, 1_000_000, -5, 4_000_000]);
        self::assertSame([4, 8_000_000, 0, 7_000_000], \array_slice($out, 0, 4));
        self::assertSame([3, 1, 2, 1 << 1 | 1 << 11], \array_slice($out, 16, 4));
        $this->assertStageParity(self::STAGES, array_map(static fn (int $i): int => $i * 1_000_003, range(1, 13)));
        $this->assertStageParity(["nzb\0parsing", '', 'finalization', "\0"], [PHP_INT_MAX, PHP_INT_MIN, 0]);
        $this->assertStageParity(['x' => 'media-sources', 'y' => 'media-sources'], [9 => 0, 4 => 0]);
        $this->assertStageParity(['1', '0', 'finalization '], [1, 2, 3]);
    }

    public function test_context_summary_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertContextParity([], [], [], [], []);
        self::assertSame(-1, $empty[10]);
        $out = $this->assertContextParity([0x3F | 1 << 7, 0, 1 << 9], [5_000_000_001, 1, 3_000_000_000], [5, 0, 3], [2, 0, 9], [4, 0, 3]);
        self::assertSame([3, 2, 2, 1, 1, 1, 0, 1, 11, 7, 0, 5, 15], \array_slice($out, 0, 13));
        $this->assertContextParity([PHP_INT_MAX, PHP_INT_MIN, -1, 0xFFF], [PHP_INT_MAX, PHP_INT_MIN, 0, 1], [PHP_INT_MAX, -9, 1, 0], [PHP_INT_MAX, -1, 5, 0], [PHP_INT_MAX, 1, 0, 0]);
        $this->assertContextParity([1, 2, 4], [10], [], [3], []);
        $this->assertContextParity(['a' => 0x7F, 'b' => 0], ['x' => 1_000_000_000, 'y' => 1_000_000_000], [1, 1], [1, 1], [1, 1]);
    }

    public function test_stored_video_parity(): void
    {
        $this->requireBindings();
        $this->assertStoredParity([], [], [], 0);
        $out = $this->assertStoredParity(['a.mkv', 'sample.mkv', 'b.avi', 'c.rar'], [900, 50, 900, 10], [0, 0, 4, 0], 100);
        self::assertSame([4, 2, 2, 2, 0, 0, 0, 0, 0, 0, 1, 1, 0, 900, 950], \array_slice($out, 0, 15));
        $names = [
            'Movie.MKV', '  x.avi'."\n", 'x.mkv1x', 'x.mkv1x.ts', 'a.mpeg"', 'a.mpeg" tail', 'a.mpeg"x', 'a.mp4 b.mkv', 'a.zip b.mkv',
            "a.qt\x0C", '.ogm', 'a.x', 'noext', '', 'Sample/x.mkv', 'x-SAMPLE.mkv', 'x.sample', "x.sample\n", "x.sample\n\n.mkv",
            'xsample.mkv', 'x.samples.mkv', 'dir\\sample\\a.WMV', "a\0.mkv", "\0a.m2ts\0", "\xff\xfe.mkv", 'a.MK3D', 'a.divx ',
            'b.Mpv', 'c.mts"', 'd.asf', 'e.ogv', 'f.QT', 'g.m4v', 'h.vob', 'i.m2v', 'j.xvid', 'k.webm', 'l.mpg.nfo', "m.mkv\t",
            "n.mkv\v", "o.mkv\r\n", 'p.mkv.001', 'q..mkv', 'r.mkv .', 's.SAMPLE-', 't sample', '-sample-',
        ];
        $count = \count($names);
        $sizes = array_map(static fn (int $i): int => match ($i % 5) {
            0 => 0, 1 => -1, 2 => 50, 3 => PHP_INT_MAX, default => 1_000_000 * $i
        }, range(0, $count - 1));
        $flags = array_map(static fn (int $i): int => $i % 3 === 0 ? 0 : 1 << ($i % 6), range(0, $count - 1));
        $this->assertStoredParity($names, $sizes, $flags, 100);
        $this->assertStoredParity($names, array_fill(0, $count, 5_000), array_fill(0, $count, 0), 0);
        $this->assertStoredParity($names, array_fill(0, $count, 5_000), array_fill(0, $count, PHP_INT_MIN), PHP_INT_MAX);
        $this->assertStoredParity(['k' => 'a.mkv', 'j' => 'b.mkv'], [], [7 => 0], PHP_INT_MIN);
    }

    public function test_resume_probe_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertProbeParity([], 0);
        self::assertSame([0, ContainerSplicer::W55_DEFAULT_WINDOW], \array_slice($empty, 0, 2));
        $ts = str_repeat("\0", 3);
        for ($packet = 0; $packet < 5; $packet++) {
            $ts .= "\x47".str_repeat("\0", 187);
        }
        $ts192 = '';
        for ($packet = 0; $packet < 5; $packet++) {
            $ts192 .= "\x47".str_repeat("\x47\x00", 95)."\x00";
        }
        $mixed = "xx\x1F\x43\xB6\x75..00db\x00\x00\x01\xBA";
        $out = $this->assertProbeParity([$ts, $mixed, ''], ContainerSplicer::W55_DEFAULT_WINDOW);
        self::assertSame([3, 65_536, 1, 1, 1, 1, 1], \array_slice($out, 0, 7));
        $edge = [
            $ts192, "\x1F\x43\xB6", "\x1F\x43\xB6\x75", "\x1F\x1F\x43\xB6\x75", "\x00\x00\x00\x01\xBA", '9dc 99dc', '0wb', '12wb',
            str_repeat("\x47", 1_000), str_repeat("\x47", 751), str_repeat("\x47", 753), 'abc', str_repeat('x', 70_000)."\x1F\x43\xB6\x75",
            "\xD9\xB0\xD9\xB1dc", '00DC 00Dc 00dc',
        ];
        foreach ([1, 3, 4, 6, 100, 752, 753, 65_536, 0, -5, PHP_INT_MAX] as $window) {
            $this->assertProbeParity($edge, $window);
        }
        $this->assertProbeParity(['k' => $mixed, 'j' => $ts], 12);
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 64, false, 556];
            yield $short.' uncommon' => [$class, 256, true, 557];
            yield $short.' large common' => [$class, 1_024, false, 558];
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $method = self::WAVE[$class];
        $php = $class::withW55RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW55RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $rust);
        self::assertSame($php, $class::{$method.'Php'}(...$args));
        self::assertSame($php, $class::{$method.'Native'}(...$args));
        [$header, $rows] = self::LAYOUT[$class];
        self::assertCount($header + $rows * $count, $php);
        self::assertSame($count, $php[0]);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        for ($round = 0; $round < 40; $round++) {
            foreach (array_keys(self::WAVE) as $class) {
                $count = (new Randomizer(new Mt19937($round)))->getInt(0, 96);
                $args = $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_000 + $round);
                $method = self::WAVE[$class];
                $php = $class::withW55RustFfi(false, static fn (): array => $class::$method(...$args));
                $rust = $class::withW55RustFfi(true, static fn (): array => $class::$method(...$args));
                self::assertSame($php, $rust, $class.' round '.$round);
            }
        }
    }

    /**
     * Protocol (set NNTMUX_W556_BENCH=1): seed 556, 8 fixture variants, 50 warmups and
     * NNTMUX_W556_BENCH_PAIRS (default 1,000, at least 100) randomized-order pairs per
     * class/workload. Common batches hold 64 typical records; uncommon ones 256 padded, mixed-case,
     * empty, invalid or extreme records. Fixtures are built outside timing and parity is asserted on
     * every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W556_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W556_BENCH=1 to run the paired Wave 55.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(100, (int) (getenv('NNTMUX_W556_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(556));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 256 : 64, $uncommon, 556 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW55RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 556, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w556-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW55.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertSame($eligible, $class::usesW55RustFfi(), $class.' must default to Rust exactly when it clears >=1.5x mean/p99 on both workloads');
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
            PersistenceMetricsCollector::class => $this->eventBatch($random, $count, $uncommon),
            ProcessingMetrics::class => $this->stageBatch($random, $count, $uncommon),
            ReleaseProcessingContext::class => $this->contextBatch($random, $count, $uncommon),
            ArchivedMediaSlicer::class => $this->storedBatch($random, $count, $uncommon),
            default => $this->probeBatch($random, $count, $uncommon),
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
     * Collector events grouped by release scope; uncommon ones mix unscoped, unknown, foreign and extreme events.
     *
     * @return list<list<int>>
     */
    private function eventBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $kinds = $scopes = $ids = $micros = [];
        $scope = 10_000;
        for ($i = 0; $i < $count; $i++) {
            if ($random->getInt(0, 7) === 0) {
                $scope += $random->getInt(1, 3);
            }
            $kind = $random->getInt(0, 9) < 7 ? 0 : $random->getInt(1, 2);
            $id = $scope;
            $us = $random->getInt(20, 20_000);
            $current = $scope;
            if ($uncommon) {
                $roll = $random->getInt(0, 9);
                $kind = match ($roll) {
                    0 => $random->getInt(3, 99), 1 => -1, 2 => PHP_INT_MIN, default => $kind
                };
                $current = $roll === 3 ? -1 : ($roll === 4 ? PHP_INT_MAX : $scope);
                $id = $roll === 5 ? $scope + 1 : ($roll === 6 ? -$scope : $id);
                $us = match ($roll) {
                    7 => -$us, 8 => PHP_INT_MAX, 9 => 1 << 41, default => $us
                };
            }
            $kinds[] = $kind;
            $scopes[] = $current;
            $ids[] = $id;
            $micros[] = $us;
        }

        return [$kinds, $scopes, $ids, $micros];
    }

    /**
     * Stage timings; uncommon ones mix unknown, mixed-case, empty, NUL-holding and extreme records.
     *
     * @return list<list<int|string>>
     */
    private function stageBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $stages = $nanoseconds = [];
        for ($i = 0; $i < $count; $i++) {
            $stage = self::STAGES[$random->getInt(0, 12)];
            $ns = $random->getInt(10_000, 5_000_000_000);
            if ($uncommon) {
                $roll = $random->getInt(0, 9);
                $stage = match ($roll) {
                    0 => strtoupper($stage), 1 => '', 2 => $stage.' ', 3 => 'unknown-'.$random->getInt(0, 99), default => $stage
                };
                $ns = match ($roll) {
                    4 => -$ns, 5 => PHP_INT_MAX, 6 => 0, 7 => 1 << 45, default => $ns
                };
            }
            $stages[] = $stage;
            $nanoseconds[] = $ns;
        }
        if ($uncommon && $count > 0) {
            $stages[$count - 1] = "nzb\0parsing";
        }

        return [$stages, $nanoseconds];
    }

    /**
     * Context states; uncommon ones are timed out, passworded, deferring, inconsistent or extreme.
     *
     * @return list<list<int>>
     */
    private function contextBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $flags = $elapsed = $timeouts = $added = $total = [];
        for ($i = 0; $i < $count; $i++) {
            $f = $random->getInt(0, 3) === 0 ? $random->getInt(0, 0x7F) : 0x3F | ($random->getInt(0, 1) << 6);
            $ns = $random->getInt(1_000_000, 120_000_000_000);
            $timeout = $random->getInt(0, 3) === 0 ? 0 : $random->getInt(30, 600);
            $files = $random->getInt(0, 40);
            $add = $random->getInt(0, $files);
            if ($uncommon) {
                $roll = $random->getInt(0, 9);
                $f = match ($roll) {
                    0 => $random->getInt(0, 0xFFFF), 1 => -1, 2 => PHP_INT_MIN, 3 => $f | 0xF80, default => $f
                };
                $ns = match ($roll) {
                    4 => -$ns, 5 => PHP_INT_MAX, 6 => $timeout * 1_000_000_000, default => $ns * 10
                };
                $timeout = $roll === 7 ? PHP_INT_MAX : ($roll === 8 ? -5 : $timeout);
                $add = $roll === 9 ? $files + 5 : ($roll === 1 ? PHP_INT_MAX : $add);
                $files = $roll === 2 ? -$files : $files;
            }
            $flags[] = $f;
            $elapsed[] = $ns;
            $timeouts[] = $timeout;
            $added[] = $add;
            $total[] = $files;
        }

        return [$flags, $elapsed, $timeouts, $added, $total];
    }

    /**
     * Archive listings of scene releases; uncommon ones hold padded, quoted, mixed-case, NUL-holding,
     * nested, encrypted or extreme entries.
     *
     * @return list<mixed>
     */
    private function storedBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $extensions = ['mkv', 'mkv', 'mkv', 'avi', 'mp4', 'ts', 'm2ts', 'nfo', 'sfv', 'srt', 'jpg', 'wmv', 'mpg', 'vob', 'ogm'];
        $names = $sizes = $flags = [];
        for ($i = 0; $i < $count; $i++) {
            $title = 'Some.Show.S0'.$random->getInt(1, 9).'E'.str_pad((string) $random->getInt(1, 24), 2, '0', STR_PAD_LEFT).'.1080p.WEB.H264-GRP';
            $ext = $extensions[$random->getInt(0, \count($extensions) - 1)];
            $name = $random->getInt(0, 5) === 0 ? 'Sample/'.strtolower($title).'-sample.'.$ext : $title.'.'.$ext;
            $size = $random->getInt(0, 4) === 0 ? $random->getInt(1_000, 60_000_000) : $random->getInt(200_000_000, 4_000_000_000);
            $flag = $random->getInt(0, 9) === 0 ? 4 : 0;
            if ($uncommon) {
                $roll = $random->getInt(0, 11);
                $name = match ($roll) {
                    0 => '  '.$name.'"  ', 1 => strtoupper($name), 2 => $name."\n", 3 => 'dir\\sub\\'.$name, 4 => $name."\0", 5 => '',
                    6 => $title.'.'.$ext.'1x', 7 => "\xff\xfe".$name, 8 => $title.'.SAMPLE.'.$ext, default => $name
                };
                $size = match ($roll) {
                    9 => -$size, 10 => PHP_INT_MAX, 11 => 0, default => $size
                };
                $flag = $roll === 3 ? 8 : ($roll === 7 ? $random->getInt(0, 0xFFFF) : $flag);
            }
            $names[] = $name;
            $sizes[] = $size;
            $flags[] = $flag;
        }

        return [$names, $sizes, $flags, 104_857_600];
    }

    /**
     * Mid-file chunks of the spliceable containers; uncommon ones are empty, tiny, marker-less,
     * late-marker or longer than the scan window.
     *
     * @return list<mixed>
     */
    private function probeBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $mids = [];
        for ($i = 0; $i < $count; $i++) {
            $length = $random->getInt(512, 4_096);
            $kind = $random->getInt(0, 3);
            $noise = $random->getBytes($length);
            $at = $random->getInt(0, min(180, $length - 4));
            $mid = match ($kind) {
                0 => substr_replace($noise, "\x1F\x43\xB6\x75", $at, 4),
                1 => substr_replace($noise, "\x00\x00\x01\xBA", $at, 4),
                2 => $this->transportChunk($random, $length, $at),
                default => substr_replace($noise, sprintf('%02d', $random->getInt(0, 99)).['dc', 'db', 'wb'][$random->getInt(0, 2)], $at, 4),
            };
            if ($uncommon) {
                $roll = $random->getInt(0, 9);
                $mid = match ($roll) {
                    0 => '', 1 => substr($noise, 0, 3), 2 => str_repeat("\xAA", $length), 3 => $noise.$mid,
                    4 => str_repeat("\x47", $random->getInt(700, 800)), 5 => strtoupper($noise), default => $mid
                };
            }
            $mids[] = $mid;
        }
        if ($uncommon && $count > 0) {
            $mids[$count - 1] = $random->getBytes(70_000)."\x1F\x43\xB6\x75";
        }

        return [$mids, ContainerSplicer::W55_DEFAULT_WINDOW];
    }

    private function transportChunk(Randomizer $random, int $length, int $at): string
    {
        $stride = $random->getInt(0, 1) === 0 ? 188 : 192;
        $chunk = $at > 0 ? $random->getBytes($at) : '';
        while (\strlen($chunk) < $length) {
            $chunk .= "\x47".$random->getBytes($stride - 1);
        }

        return $chunk;
    }

    /**
     * @param  array<array-key, int>  $kinds
     * @param  array<array-key, int>  $scopes
     * @param  array<array-key, int>  $ids
     * @param  array<array-key, int>  $micros
     * @return list<int>
     */
    private function assertEventParity(array $kinds, array $scopes, array $ids, array $micros): array
    {
        $php = PersistenceMetricsCollector::withW55RustFfi(false, static fn (): array => PersistenceMetricsCollector::foldScopedEventBatch($kinds, $scopes, $ids, $micros));
        $rust = PersistenceMetricsCollector::withW55RustFfi(true, static fn (): array => PersistenceMetricsCollector::foldScopedEventBatch($kinds, $scopes, $ids, $micros));
        self::assertSame($php, PersistenceMetricsCollector::foldScopedEventBatchPhp($kinds, $scopes, $ids, $micros));
        self::assertSame($php, PersistenceMetricsCollector::foldScopedEventBatchNative($kinds, $scopes, $ids, $micros));
        self::assertSame($php, $rust);
        self::assertCount(PersistenceMetricsCollector::W55_EVENT_HEADER + \count($kinds), $php);

        return $php;
    }

    /**
     * @param  array<array-key, string>  $stages
     * @param  array<array-key, int>  $nanoseconds
     * @return list<int>
     */
    private function assertStageParity(array $stages, array $nanoseconds): array
    {
        $php = ProcessingMetrics::withW55RustFfi(false, static fn (): array => ProcessingMetrics::summarizeStageBatch($stages, $nanoseconds));
        $rust = ProcessingMetrics::withW55RustFfi(true, static fn (): array => ProcessingMetrics::summarizeStageBatch($stages, $nanoseconds));
        self::assertSame($php, ProcessingMetrics::summarizeStageBatchPhp($stages, $nanoseconds));
        self::assertSame($php, ProcessingMetrics::summarizeStageBatchNative($stages, $nanoseconds));
        self::assertSame($php, $rust);
        self::assertCount(ProcessingMetrics::W55_STAGE_HEADER + \count($stages), $php);

        return $php;
    }

    /**
     * @param  array<array-key, int>  $flags
     * @param  array<array-key, int>  $elapsed
     * @param  array<array-key, int>  $timeouts
     * @param  array<array-key, int>  $added
     * @param  array<array-key, int>  $total
     * @return list<int>
     */
    private function assertContextParity(array $flags, array $elapsed, array $timeouts, array $added, array $total): array
    {
        $call = static fn (): array => ReleaseProcessingContext::summarizeContextBatch($flags, $elapsed, $timeouts, $added, $total);
        $php = ReleaseProcessingContext::withW55RustFfi(false, $call);
        $rust = ReleaseProcessingContext::withW55RustFfi(true, $call);
        self::assertSame($php, ReleaseProcessingContext::summarizeContextBatchPhp($flags, $elapsed, $timeouts, $added, $total));
        self::assertSame($php, ReleaseProcessingContext::summarizeContextBatchNative($flags, $elapsed, $timeouts, $added, $total));
        self::assertSame($php, $rust);
        self::assertCount(ReleaseProcessingContext::W55_CONTEXT_HEADER + \count($flags), $php);

        return $php;
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $flags
     * @return list<int>
     */
    private function assertStoredParity(array $names, array $sizes, array $flags, int $minMain): array
    {
        $call = static fn (): array => ArchivedMediaSlicer::classifyStoredVideoBatch($names, $sizes, $flags, $minMain);
        $php = ArchivedMediaSlicer::withW55RustFfi(false, $call);
        $rust = ArchivedMediaSlicer::withW55RustFfi(true, $call);
        self::assertSame($php, ArchivedMediaSlicer::classifyStoredVideoBatchPhp($names, $sizes, $flags, $minMain));
        self::assertSame($php, ArchivedMediaSlicer::classifyStoredVideoBatchNative($names, $sizes, $flags, $minMain));
        self::assertSame($php, $rust);
        self::assertCount(ArchivedMediaSlicer::W55_STORED_HEADER + \count($names), $php);

        return $php;
    }

    /**
     * @param  array<array-key, string>  $mids
     * @return list<int>
     */
    private function assertProbeParity(array $mids, int $window): array
    {
        $php = ContainerSplicer::withW55RustFfi(false, static fn (): array => ContainerSplicer::probeResumeBatch($mids, $window));
        $rust = ContainerSplicer::withW55RustFfi(true, static fn (): array => ContainerSplicer::probeResumeBatch($mids, $window));
        self::assertSame($php, ContainerSplicer::probeResumeBatchPhp($mids, $window));
        self::assertSame($php, ContainerSplicer::probeResumeBatchNative($mids, $window));
        self::assertSame($php, $rust);
        self::assertCount(ContainerSplicer::W55_PROBE_HEADER + 2 * \count($mids), $php);

        return $php;
    }

    private function requireBindings(): void
    {
        if (PersistenceMetricsCollector::foldScopedEventBatchNative([], [], [], []) === null
            || ProcessingMetrics::summarizeStageBatchNative([], []) === null
            || ReleaseProcessingContext::summarizeContextBatchNative([], [], [], [], []) === null
            || ArchivedMediaSlicer::classifyStoredVideoBatchNative([], [], [], 0) === null
            || ContainerSplicer::probeResumeBatchNative([]) === null
        ) {
            self::markTestSkipped('The Wave 55.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
