<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\ArchivedMediaSlicer;
use App\Services\AdditionalProcessing\Media\ContainerSplicer;
use Closure;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustAdditionalOutcomeStagePositionSlicerContainerW50ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        ProcessingOutcome::class => 'classifyOutcomeBatch',
        ProcessingStage::class => 'rankStageBatch',
        SourcePosition::class => 'rankPositionBatch',
        ArchivedMediaSlicer::class => 'planSliceBatch',
        ContainerSplicer::class => 'planSpliceBatch',
    ];

    /**
     * Apple M5/macOS arm64 (shared host, load ~5-8), PHP 8.5.12 CLI without opcache/JIT, release
     * Rust; base d6909c009 plus this change, seed 506, 50 warmups and 3,000 pairs/workload
     * (w506-benchmark-5662cbad31187ef4.json in system tmp). Common mean/p99 then uncommon mean/p99
     * speedups (PHP time / Rust time); a default is Rust only when all four reach 1.5x. Across four
     * 3,000-pair runs of the final code, ProcessingOutcome's p99 fell to 1.305-1.47x (its native
     * floor is ~1.4us per 64 values against ~3.3us of PHP hash lookups) and ContainerSplicer's
     * uncommon p99 ranged 1.225-1.624x (copying ~1.5MB of heads and mids is half its native time),
     * so both stay on PHP; the other three cleared 1.5x on every metric of every run.
     */
    private const QUALIFICATION = [
        ProcessingOutcome::class => [1.914, 1.470, 1.915, 1.334],
        ProcessingStage::class => [7.921, 3.743, 5.450, 3.765],
        SourcePosition::class => [6.277, 3.203, 5.397, 3.240],
        ArchivedMediaSlicer::class => [1.911, 1.412, 1.810, 1.618],
        ContainerSplicer::class => [2.496, 2.186, 1.552, 1.225],
    ];

    private const string CLUSTER = "\x1F\x43\xB6\x75";

    private const string PACK_START = "\x00\x00\x01\xBA";

    private const string SAMPLE_PATTERN = '/(?:^|[\\\\\/._ -])sample(?:[\\\\\/._ -]|$)/i';

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW50RustFfi', 'withW50RustFfi', 'w50RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertTrue($reflection->getMethod('w50RustFfiState')->isPrivate(), $class);
            self::assertTrue($reflection->getMethod($method)->isStatic(), $class);
            self::assertTrue($reflection->hasConstant('W50_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W50_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w506_', (string) $reflection->getConstant('W50_FFI_CDEF'), $class);
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (self::WAVE as $class => $method) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W50_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW50RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW50RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW50RustFfi(! $enabled, static fn (): bool => $class::usesW50RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (self::WAVE as $other => $otherMethod) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W50_RUST_FFI_DEFAULT'), $other::usesW50RustFfi());
                        }
                    }

                    return $class::usesW50RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW50RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW50RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW50RustFfi(), $class);
        }
        // The Wave 50 switches leave the existing toggles alone.
        self::assertFalse(ProcessingOutcome::usesRustFfi());
        self::assertFalse(ProcessingStage::usesRustFfi());
        self::assertFalse(SourcePosition::usesRustFfi());
        self::assertFalse(ArchivedMediaSlicer::usesRustFfi());
        self::assertFalse(ContainerSplicer::usesRustFfi());
    }

    public function test_processing_outcome_classify_parity(): void
    {
        $this->requireBindings();
        $this->assertOutcomeParity([]);
        $this->assertOutcomeParity(['']);
        $values = array_map(static fn (ProcessingOutcome $case): string => $case->value, ProcessingOutcome::cases());
        $this->assertOutcomeParity([...$values, 'COMPLETED', ' completed', "comp\0leted", '0', '12', "\xff", '', "failed\n", 'deleted_broken_nzb']);
        $this->assertOutcomeParity(['b' => 'failed', 'a' => 'deleted-after-timeout', 9 => 'bogus']);
        foreach ([[64, false, 1], [256, true, 2], [128, true, 3]] as [$count, $uncommon, $seed]) {
            $this->assertOutcomeParity($this->outcomeBatch($count, $uncommon, $seed));
        }

        $summary = ProcessingOutcome::classifyOutcomeBatchPhp(['completed', 'deleted-par2-only', 'bogus', 'failed']);
        self::assertSame([1, 1, 2, 1, 250_000], \array_slice($summary, 14, 5));
        self::assertSame([1 << 4, 8 | 1 << 5, 12, 11], \array_slice($summary, ProcessingOutcome::W50_OUTCOME_HEADER));
        // Every case maps to its declaration index with the enum's own success and deletion flags.
        $rows = \array_slice(ProcessingOutcome::classifyOutcomeBatchNative($values) ?? [], ProcessingOutcome::W50_OUTCOME_HEADER);
        foreach (ProcessingOutcome::cases() as $index => $case) {
            self::assertSame(ProcessingOutcome::resolvePhp($case->value), $case);
            self::assertSame($index | ($case->isSuccessfulPhp() ? 1 << 4 : 0) | ($case->isDeletedPhp() ? 1 << 5 : 0), $rows[$index], $case->value);
        }
    }

    public function test_processing_stage_rank_parity(): void
    {
        $this->requireBindings();
        $this->assertStageParity([]);
        $this->assertStageParity(['', ' ', "\0"]);
        $values = array_map(static fn (ProcessingStage $case): string => $case->value, ProcessingStage::cases());
        $this->assertStageParity($values);
        $this->assertStageParity(array_reverse($values));
        $this->assertStageParity([" NZB_Parsing\n", "\tFINALIZATION\0", 'media sources', 'media_sources', "\x0Bworkspace-cleanup\r", "nzb-pars\0ing", "\xffnzb-parsing", 'nzb--parsing']);
        foreach ([[64, false, 11], [256, true, 12], [100, true, 13]] as [$count, $uncommon, $seed]) {
            $this->assertStageParity($this->stageBatch($count, $uncommon, $seed));
        }

        $rows = \array_slice(ProcessingStage::rankStageBatchNative($values) ?? [], ProcessingStage::W50_STAGE_HEADER);
        foreach (ProcessingStage::cases() as $index => $case) {
            self::assertSame($index | ($case->rankPhp() + 1) << 4 | \strlen($case->labelPhp()) << 8, $rows[$index], $case->value);
        }
        $summary = ProcessingStage::rankStageBatchPhp([' NZB_Parsing', 'finalization', 'nope', 'workspace-preparation']);
        self::assertSame([11, 1, 0, 11 + 12 + 21], \array_slice($summary, 15, 4));
    }

    public function test_source_position_rank_parity(): void
    {
        $this->requireBindings();
        $this->assertPositionParity([], []);
        $this->assertPositionParity([''], []);
        $this->assertPositionParity(['HEAD', ' mid ', 'Complete', 'complete', "he\0ad", 'x'], [PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1 << 40, 7]);
        $this->assertPositionParity(['b' => 'head', 'a' => 'head', 'c' => 'mid'], ['x' => 10, 'y' => 30]);
        $this->assertPositionParity(['mid', 'mid', 'head'], [5, 9, 1, 99, 100]);
        foreach ([[64, false, 21], [256, true, 22], [90, true, 23]] as [$count, $uncommon, $seed]) {
            $this->assertPositionParity(...$this->positionBatch($count, $uncommon, $seed));
        }

        $summary = SourcePosition::rankPositionBatchPhp(['HEAD', 'mid', 'x', 'head'], [50, 900, 7, 80]);
        self::assertSame([4, 0, 2, 1, 1, 0, 130, 900, 3, 1, 80, 0], \array_slice($summary, 0, 12));
        foreach (SourcePosition::cases() as $index => $case) {
            $rows = \array_slice(SourcePosition::rankPositionBatchNative([$case->value], [3]) ?? [], SourcePosition::W50_POSITION_HEADER);
            self::assertSame([$index | ($case->rankPhp() + 1) << 4 | 3 << 8], $rows, $case->value);
        }
    }

    public function test_archived_media_slice_parity(): void
    {
        $this->requireBindings();
        $this->assertSliceParity([], 0, 0, 0);
        $this->assertSliceParity([], -1, 10, 10);
        $edge = [
            $this->file('Movie.2024.1080p.mkv', 900_000, '0-899999', null),
            $this->file('Sample/clip.mkv', 1_000, '120-1119', 600),
            $this->file('sample.mkv', 50, '10', null),
            $this->file('x.SAMPLE', 50, ' 12- 99', 10),
            $this->file('Movie.sample', 0, '+5-', null),
            $this->file("a\0b.mkv", -40, "\t7-x", null),
            $this->file('far.mkv', 10, '99999999-100000008', null),
            $this->file('', 1, '', 0),
            $this->file('sampler.mkv', 2_000, '-5', null),
            $this->file("movie-sample\n", 5_000, '0x10-1', 3),
        ];
        foreach ([[0, 0, 0], [1, 1, 1], [100, 64, 1_000], [2_000, -5, 100], [1_000_000, 524_288, 50_000_000], [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN]] as [$dataLen, $max, $min]) {
            $this->assertSliceParity($edge, $dataLen, $max, $min);
        }
        // Float conversions and overflowing offsets stay in PHP.
        foreach ([[$this->file('a.mkv', 1, '1e3', null)], [$this->file('a.mkv', 1, '1.5-2', null)], [$this->file('a.mkv', PHP_INT_MAX, '5-9', null)], [$this->file('a.mkv', 1, '99999999999999999999', null)]] as $files) {
            self::assertNull(ArchivedMediaSlicer::planSliceBatchNative($files, 100, 10, 1));
            $this->assertSliceParity($files, 100, 10, 1);
        }
        foreach ([[64, false, 31], [256, true, 32], [77, true, 33]] as [$count, $uncommon, $seed]) {
            $this->assertSliceParity(...$this->sliceBatch($count, $uncommon, $seed));
        }

        // Each supported entry matches entryForPhp() on real volume bytes.
        $data = str_repeat('0123456789', 300);
        foreach ([[64, 1_000], [5_000, 1], [0, 2_000], [-7, 100]] as [$max, $min]) {
            $slicer = new ArchivedMediaSlicer($this->configWith($max, $min));
            $plan = ArchivedMediaSlicer::planSliceBatchNative($edge, \strlen($data), $max, $min);
            self::assertNotNull($plan);
            foreach ($edge as $index => $file) {
                $row = \array_slice($plan, ArchivedMediaSlicer::W50_SLICE_HEADER + ArchivedMediaSlicer::W50_SLICE_ROW * $index, ArchivedMediaSlicer::W50_SLICE_ROW);
                self::assertSame($slicer->rangePhp($file), [$row[0], $row[1]], $file['range']);
                $entry = $slicer->entryForPhp($data, $file, 0, 1);
                if ($entry === null) {
                    self::assertSame(0, $row[4] & 1, $file['range']);

                    continue;
                }
                self::assertSame([$entry->rangeStart, $entry->inVolumeEnd, \strlen($entry->headBytes), $entry->isSample], [$row[0], $row[2], $row[3], ($row[4] & 2) !== 0], $file['range']);
            }
        }
    }

    public function test_container_splice_parity(): void
    {
        $this->requireBindings();
        $this->assertSpliceParity([], [], []);
        $this->assertSpliceParity([], [], [null, VideoContainer::Matroska]);
        $transport = self::transport(188, 37, 1_200);
        $heads = [
            "\x1A\x45\xDF\xA3hdr".self::CLUSTER.'rest', 'RIFFxxxxAVI LISTxxxxhdrlLISTxxxxmovi', '', 'RIFFxxxxAVI movi', "\x1A\x45\xDF\xA3", 'LISTxxxxmovi', '', '', 'x', 'y', '',
        ];
        $mids = [
            'junk'.self::CLUSTER.'frame', 'xx00dcdata', "\0\0\x01\xBAzz", '01wb', self::CLUSTER, '12wc34db', $transport, self::transport(192, 0, 1_000), 'z', '', str_repeat("\x47", 50),
        ];
        $containers = [
            VideoContainer::Matroska, VideoContainer::Avi, VideoContainer::MpegProgram, VideoContainer::Avi, VideoContainer::Matroska, VideoContainer::Avi,
            VideoContainer::MpegTransport, VideoContainer::MpegTransport, VideoContainer::Mp4, null, VideoContainer::MpegTransport, VideoContainer::Ogg, VideoContainer::Asf,
        ];
        $this->assertSpliceParity($heads, $mids, $containers);
        $this->assertSpliceParity(['k' => $heads[0]], [5 => $mids[0], 6 => $mids[1]], [VideoContainer::Matroska, VideoContainer::Matroska]);
        foreach ([[64, false, 41], [256, true, 42], [50, true, 43]] as [$count, $uncommon, $seed]) {
            $this->assertSpliceParity(...$this->spliceBatch($count, $uncommon, $seed));
        }

        // Spliced rows rebuild exactly what splicePhp() returns.
        [$heads, $mids, $containers] = $this->spliceBatch(96, true, 44);
        $plan = ContainerSplicer::planSpliceBatchNative($heads, $mids, $containers);
        self::assertNotNull($plan);
        $splicer = new ContainerSplicer;
        foreach ($containers as $index => $container) {
            [$word, $headEnd, $midStart] = \array_slice($plan, ContainerSplicer::W50_SPLICE_HEADER + ContainerSplicer::W50_SPLICE_ROW * $index, ContainerSplicer::W50_SPLICE_ROW);
            $expected = $container === null ? null : $splicer->splicePhp($heads[$index], $mids[$index], $container);
            self::assertSame($expected, ($word & 0xF) === 1 ? substr($heads[$index], 0, $headEnd).substr($mids[$index], $midStart) : null, (string) $index);
            self::assertSame($container === null ? 0 : array_search($container, VideoContainer::cases(), true) + 1, $word >> 4, (string) $index);
        }
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(5_060));
        $alphabet = ['', 'a', '.', '-', '_', ' ', "\0", "\t", "\x0B", "\n", '+', '0', '7', '9', 'e', 'x', 'sample', 'mkv', 'head', 'mid', 'complete', 'completed', 'failed', 'nzb', 'parsing', 'NZB_PARSING', "\xff"];
        $text = static function () use ($random, $alphabet): string {
            $value = '';
            for ($i = $random->getInt(0, 10); $i > 0; $i--) {
                $value .= $alphabet[$random->getInt(0, \count($alphabet) - 1)];
            }

            return $value;
        };
        $bytes = ["\x1F", "\x43", "\xB6", "\x75", "\x00", "\x01", "\xBA", "\x47", '0', '1', 'd', 'c', 'w', 'b', 'movi', 'LIST', self::CLUSTER, self::PACK_START, 'x'];
        $blob = static function (int $parts) use ($random, $bytes): string {
            $value = '';
            for ($i = $random->getInt(0, $parts); $i > 0; $i--) {
                $value .= $bytes[$random->getInt(0, \count($bytes) - 1)];
            }

            return $value;
        };
        $containers = [null, ...VideoContainer::cases()];
        for ($round = 0; $round < 60; $round++) {
            $count = $random->getInt(0, 40);
            $strings = $ints = $files = $heads = $mids = $kinds = [];
            for ($i = 0; $i < $count; $i++) {
                $strings[] = $text();
                $ints[] = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, $random->getInt(0, 1 << 41)][$random->getInt(0, 4)];
                $files[] = $this->file($text(), $random->getInt(-10, 1 << 20), $text().'-'.$text(), $random->getInt(0, 2) === 0 ? null : $random->getInt(-10, 1 << 20));
                $heads[] = $blob(40);
                $mids[] = $random->getInt(0, 4) === 0 ? self::transport([188, 192][$random->getInt(0, 1)], $random->getInt(0, 200), $random->getInt(0, 1_200)) : $blob(60);
                $kinds[] = $containers[$random->getInt(0, \count($containers) - 1)];
            }
            $this->assertOutcomeParity($strings);
            $this->assertStageParity($strings);
            $this->assertPositionParity($strings, $ints);
            $this->assertSliceParity($files, $random->getInt(-2, 1 << 21), $random->getInt(-50, 1 << 20), $random->getInt(-5, 1 << 20));
            $this->assertSpliceParity($heads, $mids, $kinds);
        }
    }

    /**
     * Protocol (set NNTMUX_W506_BENCH=1): seed 506, 8 fixture variants, 50 warmups and
     * NNTMUX_W506_BENCH_PAIRS (default 1,000, at least 100) randomized-order pairs per
     * class/workload. Common batches hold 64 typical records; uncommon ones 256 long,
     * mixed-case, truncated, invalid or extreme records. Fixtures are built outside timing and
     * parity is asserted on every pair. Timings cover public dispatch, marshaling, FFI and
     * unpacking. Nearest-rank p99. The summary and raw pairs are written to the system temp
     * directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W506_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W506_BENCH=1 to run the paired Wave 50.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(100, (int) (getenv('NNTMUX_W506_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(506));
        $summary = $records = [];
        foreach (self::WAVE as $class => $method) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon, 506 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW50RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 506, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w506-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW50.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (self::WAVE as $class => $method) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            if ($class::usesW50RustFfi()) {
                self::assertTrue($eligible, $class.' defaults to Rust and must clear >=1.5x mean/p99 on both workloads');
            } else {
                self::assertFalse($class::usesW50RustFfi(), $class.' retains PHP by default');
            }
        }
    }

    /** @return Closure(): list<int|float> */
    private function benchmarkCall(string $class, bool $uncommon, int $seed): Closure
    {
        $count = $uncommon ? 256 : 64;

        return match ($class) {
            ProcessingOutcome::class => (function () use ($count, $uncommon, $seed): Closure {
                $values = $this->outcomeBatch($count, $uncommon, $seed);

                return static fn (): array => ProcessingOutcome::classifyOutcomeBatch($values);
            })(),
            ProcessingStage::class => (function () use ($count, $uncommon, $seed): Closure {
                $values = $this->stageBatch($count, $uncommon, $seed);

                return static fn (): array => ProcessingStage::rankStageBatch($values);
            })(),
            SourcePosition::class => (function () use ($count, $uncommon, $seed): Closure {
                [$values, $sizes] = $this->positionBatch($count, $uncommon, $seed);

                return static fn (): array => SourcePosition::rankPositionBatch($values, $sizes);
            })(),
            ArchivedMediaSlicer::class => (function () use ($count, $uncommon, $seed): Closure {
                [$files, $dataLen, $max, $min] = $this->sliceBatch($count, $uncommon, $seed);

                return static fn (): array => ArchivedMediaSlicer::planSliceBatch($files, $dataLen, $max, $min);
            })(),
            default => (function () use ($count, $uncommon, $seed): Closure {
                [$heads, $mids, $containers] = $this->spliceBatch($count, $uncommon, $seed);

                return static fn (): array => ContainerSplicer::planSpliceBatch($heads, $mids, $containers);
            })(),
        };
    }

    /** @return list<string> */
    private function outcomeBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $values = array_map(static fn (ProcessingOutcome $case): string => $case->value, ProcessingOutcome::cases());
        $common = ['completed', 'completed', 'completed', 'no-useful-artifacts', 'passworded', 'failed', 'timed-out', 'not-found', 'deleted-par2-only', 'awaiting-late-articles'];
        $odd = ['COMPLETED', ' failed', "timed-out\n", 'deleted_broken_nzb', "pass\0worded", str_repeat('x', 120), '', 'unknown-outcome-from-a-future-release'];
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = $uncommon
                ? ($random->getInt(0, 2) === 0 ? $odd[$random->getInt(0, \count($odd) - 1)] : $values[$random->getInt(0, \count($values) - 1)])
                : $common[$random->getInt(0, \count($common) - 1)];
        }

        return $batch;
    }

    /** @return list<string> */
    private function stageBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $values = array_map(static fn (ProcessingStage $case): string => $case->value, ProcessingStage::cases());
        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $value = $values[$uncommon ? $random->getInt(0, \count($values) - 1) : min(12, intdiv($i * 13, max(1, $count)))];
            if ($uncommon) {
                $value = match ($random->getInt(0, 5)) {
                    0 => ' '.strtoupper(str_replace('-', '_', $value))."\n",
                    1 => "\t".ucwords($value, '-')."\0",
                    2 => substr($value, 0, $random->getInt(0, \strlen($value))),
                    3 => $value.'-'.str_repeat('z', $random->getInt(1, 80)),
                    default => $value,
                };
            }
            $batch[] = $value;
        }

        return $batch;
    }

    /** @return array{0: list<string>, 1: list<int>} */
    private function positionBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $values = $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $value = ['complete', 'head', 'head', 'mid', 'mid', 'mid'][$random->getInt(0, 5)];
            if ($uncommon) {
                $value = match ($random->getInt(0, 4)) {
                    0 => ' '.strtoupper($value)."\r\n",
                    1 => "\0".ucfirst($value),
                    2 => $value.'-file',
                    3 => str_repeat('?', $random->getInt(0, 40)),
                    default => $value,
                };
            }
            $values[] = $value;
            $sizes[] = $uncommon ? [PHP_INT_MAX, PHP_INT_MIN, -1, 0, $random->getInt(0, 1 << 42)][$random->getInt(0, 4)] : $random->getInt(1 << 18, 1 << 26);
        }

        return [$values, $sizes];
    }

    /** @return array{0: list<array{name: string, size: int, range: string, next_offset: int|null}>, 1: int, 2: int, 3: int} */
    private function sliceBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $dataLen = $uncommon ? $random->getInt(0, 60_000_000) : 50_000_000;
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $size = $random->getInt(1 << 20, 1 << 32);
            $start = $random->getInt(0, $uncommon ? 80_000_000 : 4_096);
            $name = 'Some.Release.Name.S0'.$random->getInt(1, 9).'E'.$random->getInt(10, 24).'.1080p.WEB.h264-GRP'.($random->getInt(0, 7) === 0 ? '-sample' : '').'.mkv';
            $range = $start.'-'.($start + $size - 1);
            $next = $random->getInt(0, 1) === 0 ? null : $start + $random->getInt(1, 50_000_000);
            if ($uncommon) {
                $name = match ($random->getInt(0, 4)) {
                    0 => 'Sample/'.strtoupper($name),
                    1 => str_replace('.', ' ', $name),
                    2 => 'dir\\'.$name."\n",
                    default => $name,
                };
                $range = match ($random->getInt(0, 4)) {
                    0 => " \t".$start.' - '.($start + $size - 1).' ',
                    1 => (string) $start,
                    2 => '+'.$start.'-',
                    3 => $start.'-'.$random->getInt(-100, 100).'x',
                    default => $range,
                };
                $size = [$size, 0, -$random->getInt(1, 1_000), $random->getInt(1, 1_000)][$random->getInt(0, 3)];
                $next = [$next, 0, $start, $start - 1][$random->getInt(0, 3)];
            }
            $files[] = $this->file($name, $size, $range, $next);
        }

        return [$files, $dataLen, $uncommon ? [524_288, 0, -64, 1 << 30][$random->getInt(0, 3)] : 524_288, 50_000_000];
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<VideoContainer|null>} */
    private function spliceBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $noise = static fn (int $length): string => $length <= 0 ? '' : str_replace([self::CLUSTER, self::PACK_START, 'movi', "\x47"], 'abcd', $random->getBytesFromString('abcdefghijklmnopqrstuvwxyz0123456789 ', $length));
        $heads = $mids = $containers = [];
        for ($i = 0; $i < $count; $i++) {
            $container = [VideoContainer::Matroska, VideoContainer::Matroska, VideoContainer::Avi, VideoContainer::MpegProgram, VideoContainer::MpegTransport][$random->getInt(0, 4)];
            $headLen = $uncommon ? $random->getInt(0, 4_096) : $random->getInt(256, 1_024);
            $midLen = $uncommon ? $random->getInt(0, 8_192) : $random->getInt(1_024, 2_048);
            $at = $random->getInt(0, $uncommon ? $midLen : intdiv($midLen, 4));
            [$head, $mid] = match ($container) {
                VideoContainer::Matroska => ["\x1A\x45\xDF\xA3".$noise($headLen).self::CLUSTER.$noise(64), $noise($at).self::CLUSTER.$noise($midLen - $at)],
                VideoContainer::Avi => ['RIFF'.$noise(4).'AVI LIST'.$noise($headLen).'LIST'.$noise(4).'movi', $noise($at).'00dc'.$noise($midLen - $at)],
                VideoContainer::MpegProgram => [self::PACK_START.$noise($headLen), $noise($at).self::PACK_START.$noise($midLen - $at)],
                default => ['', self::transport([188, 192][$random->getInt(0, 1)], $random->getInt(0, 180), $midLen + 1_000)],
            };
            if ($uncommon) {
                switch ($random->getInt(0, 5)) {
                    case 0:
                        $mid = $noise($midLen);
                        break;
                    case 1:
                        $head = $noise($headLen);
                        break;
                    case 2:
                        $container = [null, VideoContainer::Mp4, VideoContainer::Asf, VideoContainer::Ogg][$random->getInt(0, 3)];
                        break;
                }
            }
            $heads[] = $head;
            $mids[] = $mid;
            $containers[] = $container;
        }

        return [$heads, $mids, $containers];
    }

    private static function transport(int $stride, int $offset, int $length): string
    {
        $data = str_repeat("\x10", $offset);
        while (\strlen($data) < $length) {
            $data .= "\x47".str_repeat("\x11", $stride - 1);
        }

        return substr($data, 0, $length);
    }

    /** @return array{name: string, size: int, range: string, next_offset: int|null} */
    private function file(string $name, int $size, string $range, ?int $next): array
    {
        return ['name' => $name, 'size' => $size, 'range' => $range, 'next_offset' => $next];
    }

    private function configWith(int $maxSample, int $minMain): ProcessingConfiguration
    {
        $reflection = new ReflectionClass(ProcessingConfiguration::class);
        $config = $reflection->newInstanceWithoutConstructor();
        foreach (['mediaSampleMaxBytes' => $maxSample, 'mediaMainVideoMinBytes' => $minMain] as $property => $value) {
            $reflection->getProperty($property)->setValue($config, $value);
        }

        return $config;
    }

    /** @param  array<array-key, string>  $values */
    private function assertOutcomeParity(array $values): void
    {
        $php = ProcessingOutcome::withW50RustFfi(false, static fn (): array => ProcessingOutcome::classifyOutcomeBatch($values));
        $rust = ProcessingOutcome::withW50RustFfi(true, static fn (): array => ProcessingOutcome::classifyOutcomeBatch($values));
        self::assertSame($php, ProcessingOutcome::classifyOutcomeBatchNative($values));
        self::assertSame($php, $rust);
        self::assertCount(ProcessingOutcome::W50_OUTCOME_HEADER + \count($values), $php);
        foreach (array_values($values) as $index => $value) {
            self::assertSame(ProcessingOutcome::resolvePhp($value)?->value, ($php[ProcessingOutcome::W50_OUTCOME_HEADER + $index] & 0xF) === 12 ? null : ProcessingOutcome::cases()[$php[ProcessingOutcome::W50_OUTCOME_HEADER + $index] & 0xF]->value);
        }
    }

    /** @param  array<array-key, string>  $values */
    private function assertStageParity(array $values): void
    {
        $php = ProcessingStage::withW50RustFfi(false, static fn (): array => ProcessingStage::rankStageBatch($values));
        $rust = ProcessingStage::withW50RustFfi(true, static fn (): array => ProcessingStage::rankStageBatch($values));
        self::assertSame($php, ProcessingStage::rankStageBatchNative($values));
        self::assertSame($php, $rust);
        self::assertCount(ProcessingStage::W50_STAGE_HEADER + \count($values), $php);
    }

    /**
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $sizes
     */
    private function assertPositionParity(array $values, array $sizes): void
    {
        $php = SourcePosition::withW50RustFfi(false, static fn (): array => SourcePosition::rankPositionBatch($values, $sizes));
        $rust = SourcePosition::withW50RustFfi(true, static fn (): array => SourcePosition::rankPositionBatch($values, $sizes));
        self::assertSame($php, SourcePosition::rankPositionBatchNative($values, $sizes));
        self::assertSame($php, $rust);
        self::assertCount(SourcePosition::W50_POSITION_HEADER + \count($values), $php);
    }

    /** @param  array<array-key, array{name: string, size: int, range: string, next_offset: int|null}>  $files */
    private function assertSliceParity(array $files, int $dataLen, int $max, int $min): void
    {
        $php = ArchivedMediaSlicer::withW50RustFfi(false, static fn (): array => ArchivedMediaSlicer::planSliceBatch($files, $dataLen, $max, $min));
        $rust = ArchivedMediaSlicer::withW50RustFfi(true, static fn (): array => ArchivedMediaSlicer::planSliceBatch($files, $dataLen, $max, $min));
        $native = ArchivedMediaSlicer::planSliceBatchNative($files, $dataLen, $max, $min);
        if ($native !== null) {
            self::assertSame($php, $native);
        }
        self::assertSame($php, $rust);
        self::assertCount(ArchivedMediaSlicer::W50_SLICE_HEADER + ArchivedMediaSlicer::W50_SLICE_ROW * \count($files), $php);
        foreach (array_values($files) as $index => $file) {
            $sample = preg_match(self::SAMPLE_PATTERN, $file['name']) === 1 || $file['size'] < $min;
            self::assertSame($sample, ($php[ArchivedMediaSlicer::W50_SLICE_HEADER + ArchivedMediaSlicer::W50_SLICE_ROW * $index + 4] & 2) !== 0);
        }
    }

    /**
     * @param  array<array-key, string>  $heads
     * @param  array<array-key, string>  $mids
     * @param  array<array-key, VideoContainer|null>  $containers
     */
    private function assertSpliceParity(array $heads, array $mids, array $containers): void
    {
        $php = ContainerSplicer::withW50RustFfi(false, static fn (): array => ContainerSplicer::planSpliceBatch($heads, $mids, $containers));
        $rust = ContainerSplicer::withW50RustFfi(true, static fn (): array => ContainerSplicer::planSpliceBatch($heads, $mids, $containers));
        self::assertSame($php, ContainerSplicer::planSpliceBatchNative($heads, $mids, $containers));
        self::assertSame($php, $rust);
        self::assertCount(ContainerSplicer::W50_SPLICE_HEADER + ContainerSplicer::W50_SPLICE_ROW * \count($containers), $php);
    }

    private function requireBindings(): void
    {
        if (ProcessingOutcome::classifyOutcomeBatchNative([]) === null
            || ProcessingStage::rankStageBatchNative([]) === null
            || SourcePosition::rankPositionBatchNative([], []) === null
            || ArchivedMediaSlicer::planSliceBatchNative([], 0, 0, 0) === null
            || ContainerSplicer::planSpliceBatchNative([], [], []) === null
        ) {
            self::markTestSkipped('The Wave 50.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
