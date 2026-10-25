<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\ImageCandidate;
use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\DTO\MediaSourcePlan;
use App\Services\AdditionalProcessing\DTO\PersistenceMetrics;
use App\Services\AdditionalProcessing\Enums\SourcePosition;
use Closure;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

final class RustAdditionalDtoImageMappedHintsPlanPersistenceW48ParityTest extends TestCase
{
    /** Class => [toggle suffix, batch method, default constant]. */
    private const WAVE = [
        ImageCandidate::class => ['ImageScore', 'scoreImageBatch', 'W48_IMAGE_SCORE_RUST_FFI_DEFAULT'],
        MappedNzbFile::class => ['FileMap', 'mapFileBatch', 'W48_FILE_MAP_RUST_FFI_DEFAULT'],
        MediaSourceHints::class => ['HintExtract', 'extractHintBatch', 'W48_HINT_EXTRACT_RUST_FFI_DEFAULT'],
        MediaSourcePlan::class => ['SlicePlan', 'planSliceBatch', 'W48_SLICE_PLAN_RUST_FFI_DEFAULT'],
        PersistenceMetrics::class => ['WriteTelemetry', 'aggregateWriteTelemetryBatch', 'W48_WRITE_TELEMETRY_RUST_FFI_DEFAULT'],
    ];

    /**
     * Apple M5/macOS arm64 (shared host, load ~9), PHP 8.5.12 CLI without opcache, release
     * Rust; seed 486, 50 warmups and 1,000 pairs/workload on the final code
     * (w486-benchmark-08e1c89dde7ac727.json in system tmp; two earlier runs at load ~6 stayed
     * above 2.6x everywhere). Common mean/p99 then Uncommon mean/p99 speedups (PHP time / Rust
     * time); a default is Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ImageCandidate::class => [6.245, 5.129, 3.340, 3.518],
        MappedNzbFile::class => [3.222, 2.439, 2.530, 1.949],
        MediaSourceHints::class => [11.533, 6.993, 12.519, 9.949],
        MediaSourcePlan::class => [7.702, 4.947, 8.344, 4.978],
        PersistenceMetrics::class => [5.152, 3.129, 6.756, 4.195],
    ];

    protected function tearDown(): void
    {
        foreach (self::WAVE as $class => [$suffix]) {
            $class::{'resetW48'.$suffix.'RustFfi'}();
        }
        parent::tearDown();
    }

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => [$suffix, $method, $default]) {
            $reflection = new ReflectionClass($class);
            foreach (['useW48'.$suffix.'RustFfi', 'usesW48'.$suffix.'RustFfi', 'resetW48'.$suffix.'RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue((new ReflectionClass($class))->hasMethod($name), $class.'::'.$name);
            }
            self::assertTrue($reflection->hasConstant($default), $class.'::'.$default);
            self::assertTrue($reflection->hasConstant('W48_FFI_CDEF'), $class.'::W48_FFI_CDEF');
            self::assertStringContainsString('nntmux_w48_', (string) $reflection->getConstant('W48_FFI_CDEF'));
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_route(): void
    {
        foreach (self::WAVE as $class => [$suffix, , $default]) {
            $expected = (bool) (new ReflectionClass($class))->getConstant($default);
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $this->uses($class, $suffix), $class);
            $class::{'useW48'.$suffix.'RustFfi'}(! $expected);
            self::assertSame(! $expected, $this->uses($class, $suffix));
            foreach (self::WAVE as $other => [$otherSuffix, , $otherDefault]) {
                if ($other !== $class) {
                    self::assertSame((bool) (new ReflectionClass($other))->getConstant($otherDefault), $this->uses($other, $otherSuffix));
                }
            }
            $class::{'useW48'.$suffix.'RustFfi'}(null);
            self::assertSame($expected, $this->uses($class, $suffix));
            $class::{'useW48'.$suffix.'RustFfi'}(! $expected);
            $class::{'resetW48'.$suffix.'RustFfi'}();
            self::assertSame($expected, $this->uses($class, $suffix));
        }
        // The Wave 48 switches leave the existing toggles alone.
        self::assertFalse(ImageCandidate::usesRustFfi());
        self::assertFalse(MappedNzbFile::usesRustFfi());
        self::assertFalse(MediaSourcePlan::usesRustFfi());
        self::assertFalse(PersistenceMetrics::usesRustFfi());
        self::assertFalse((new MediaSourceHints(SourcePosition::Head))->useRustFfi);
    }

    public function test_image_score_parity(): void
    {
        $this->requireBindings();
        $this->assertImageParity([], [], []);
        $this->assertImageParity(['cover.jpg'], [], []);
        $this->assertImageParity(...$this->imageBatch(64, false, 1));
        $this->assertImageParity(...$this->imageBatch(128, true, 2));
        $names = ['Front.COVER.jpg', 'screen01.png', 'x.gif', 'a.webp', 'b.webp', 'c.webp', 'd.bmp', 'e.bmp', 'trunc.jpg', 'noext', '', "a\0b.jpg", "\xff.JPEG", 'poster.bmp', 'sample-back.jpg', 'f.png'];
        $heads = [self::jpeg(600, 900), self::png(1920, 1080), self::gif(80, 60), self::webpX(16_777_216, 2), self::webpLossy(0x7FFF, 300), self::webpLossless(4000, 3000),
            self::bmp(800, -600), self::bmp(-2_147_483_648, 5), "\xFF\xD8\xFF\xE0\x00\x01", '', "\xFF\xD8\xFF\xC0\x00\x11\x08\x01", "\x89PNG\r\n\x1A\n\0\0\0\rIHDR\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF",
            str_repeat("\0", 5000), "RIFF\0\0\0\0WEBPVP8Z", "\xFF\xD8\xFF\xFF\xFF\xD0\xFF\xC2\x00\x11\x08\x00\x10\x00\x20", 'BM'];
        foreach ([[1, PHP_INT_MAX, PHP_INT_MIN, 0, -1, 1 << 40], [], [5 => 7]] as $sizes) {
            $this->assertImageParity($names, $heads, $sizes);
        }
        $this->assertImageParity(['k' => 'x.jpg', 'y.png'], ['z' => self::png(10, 10), 'q' => 'x', 'r' => 'y'], [9 => 2048]);
        $scored = ImageCandidate::scoreImageBatchPhp(['Movie.cover.jpg', 'screen1.jpg', 'unknown.bin'], [self::jpeg(600, 900), self::jpeg(1280, 720), 'GARBAGE'], [204_800, 102_400, 0]);
        self::assertSame([3, 1, 2, 0, 0, 0, 0, 2, 1, 1, 0], array_slice($scored, 0, 11));
        self::assertSame([1 | 1 << 4 | 2 << 8 | 1 << 12, 600, 900, 666], array_slice($scored, ImageCandidate::W48_IMAGE_HEADER, 4));
        self::assertSame([0, 2, 1], array_slice($scored, ImageCandidate::W48_IMAGE_HEADER + 3 * ImageCandidate::W48_IMAGE_WIDTH));
    }

    public function test_file_map_parity(): void
    {
        $this->requireBindings();
        $this->assertMapParity([], [], [], [], [], []);
        $this->assertMapParity(['only'], [], [], [], [], []);
        $this->assertMapParity(...$this->mapBatch(64, false, 3));
        $this->assertMapParity(...$this->mapBatch(128, true, 4));
        $subjects = ['[01/05] - "Show.part01.rar" yEnc (1/50)', '[02/05] - "show.PART02.rar" yEnc (3/50)', '[3/5] "abc" yEnc (1/9999999)', '[x/5] - "dup.bin" (1/2)', 'no quotes here (7/8)',
            ' "" [1/2] (a/b) ', "[05/05] - \"nul\0name.bin\" yEnc (1/1)", '[1234567/9] "123" (1/0)', '"Show.part01.rar"', '[04/05] - "unique.bin" yEnc (2/3)', '(((/)))[[[/]]]', ''];
        $par2Names = ['Show.part01.rar', 'Show.part02.rar', 'dup.bin', 'dup.bin', '123', 'real.mkv', 'SHOW.PART01.RAR', "nul\0name.bin"];
        $par2Sizes = [1000, 2000, 3000, 3000, 7, 4242, 1000, PHP_INT_MAX];
        $sizes = [1100, 2100, 3000, 3000, 4242, 0, PHP_INT_MIN, 7, 1100, 4242, -1, PHP_INT_MAX];
        $this->assertMapParity($subjects, $sizes, [50, 2, 5, PHP_INT_MAX, -3, 0, 1, 1], [50, 0, -1, 2, PHP_INT_MAX, 0, 1], $par2Names, $par2Sizes);
        $this->assertMapParity($subjects, $sizes, [], [], $par2Names, []);
        $this->assertMapParity(['b' => '"x"', 'a' => '"y"'], [5 => 10], ['q' => 1], [], ['k' => 'X', 'y'], ['z' => 10, 'w' => 20, 'v' => 30]);
        $map = MappedNzbFile::mapFileBatchPhp(['[2/3] - "b.rar" yEnc (1/4)', '[1/3] - "A.RAR" yEnc (1/4)', '[3/3] - "x.bin" yEnc (1/4)'], [500, 400, 300], [4, 3, 0], [0, 0, 0], ['b.rar', 'a.rar', 'real.mkv'], [450, 350, 250]);
        self::assertSame([3, 1, 1, 0, 1, 1, 2, 0, 1200, 800, 1100], array_slice($map, 0, 11));
        self::assertSame([0, 3 | 2 << 4, 2 << 32 | 3, 1_000_000, 350, 50], array_slice($map, MappedNzbFile::W48_MAP_HEADER, MappedNzbFile::W48_MAP_WIDTH));
        self::assertSame([1, 0, 2], array_slice($map, MappedNzbFile::W48_MAP_HEADER + 3 * MappedNzbFile::W48_MAP_WIDTH));
    }

    public function test_hint_extract_parity(): void
    {
        $this->requireBindings();
        $this->assertHintParity([]);
        $this->assertHintParity(['']);
        $this->assertHintParity($this->hintBatch(64, false, 5));
        $this->assertHintParity($this->hintBatch(128, true, 6));
        $this->assertHintParity(['Movie.2024.2160p.UHD.BluRay.REMUX.HDR10.DV.TrueHD.7.1.Atmos-GRP.mkv', 'Show.S01E01.1080i.HDTV.H.264.DD5.1.ts', 'x.4K.WEB-DL.DDP5.1.H.265-SAMPLE', 'a.dts.hd.ma.5.1.AVI',
            'b.9999p.240p.4320i.239p.4321p.0720p.xvid.dovi.repack.MP4', "nul\0x265.mkv", "\xff\xfeHEVC\x80aac2.0", '...', 'h.264.h.265', 'MPEG2.mpeg', 'web.dl.webrip.dvdrip', 'opus.mp3.flac.eac3.ac3', 'proper.hdr10plus.3.1.0.1', 'x.']);
        $this->assertHintParity(['b' => 'x.1080p.mkv', 'a' => 'y.720p.mp4']);
        $hints = MediaSourceHints::extractHintBatchPhp(['Movie.2024.1080p.BluRay.x264.DTS-HD.MA.5.1-GRP.mkv', 'Movie.2024.2160p.WEB-DL.DDP5.1.HDR.H.265-GRP-sample.mp4']);
        self::assertSame([2, 0, 0, 0, 1, 1, 1, 1, 1, 1, 25], array_slice($hints, 0, 11));
        self::assertSame([2 | 1 << 8 | 7 << 16 | 5 << 24, 1080, 6, 10_800 + 300 + 280 + 500 + 50 + 30], array_slice($hints, MediaSourceHints::W48_HINT_HEADER, MediaSourceHints::W48_HINT_WIDTH));
        self::assertSame([1, 0], array_slice($hints, MediaSourceHints::W48_HINT_HEADER + 2 * MediaSourceHints::W48_HINT_WIDTH));
    }

    public function test_slice_plan_parity(): void
    {
        $this->requireBindings();
        $this->assertPlanParity([], [], [], 0, 0);
        $this->assertPlanParity([0], [], [], 0, 0);
        $this->assertPlanParity(...$this->planBatch(64, false, 7));
        $this->assertPlanParity(...$this->planBatch(128, true, 8));
        $reals = [0, -1, 1, 1_000_000, 10_000_000, PHP_INT_MAX, PHP_INT_MIN, 1 << 40, 5_000_001, 768_000, 999];
        $available = [0, 5, 1, 2_000_000, 1_000_000, PHP_INT_MAX, -1, 1 << 39, 768_000, 768_000];
        $parts = [0, -5, 1, 768_000, PHP_INT_MAX, 3, 1 << 31, 750_000];
        foreach ([[0, 0], [1, 1], [PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MIN, PHP_INT_MIN], [5_000_000, 2_000_000], [1 << 30, 1 << 40], [-1, 4096]] as [$budget, $probe]) {
            $this->assertPlanParity($reals, $available, $parts, $budget, $probe);
        }
        $this->assertPlanParity(['b' => 5_000_000, 'a' => 0], [9 => 100], ['z' => 1024], 1 << 22, 1 << 20);
        $plan = MediaSourcePlan::planSliceBatchPhp([4_000_000, 0, 10_000_000], [4_000_000, 100, 1_000_000], [1_000_000, 0, 1_000_000], 2_500_000, 2_000_000);
        self::assertSame([3, 1, 1, 0, 0, 1, 1_000_000, 1_500_000, 6_000_000, 1, 2], [...array_slice($plan, 0, 10), $plan[11]]);
        self::assertSame([0 | 4 << 8, 0, 4_000_000, 0, 3_000_003], array_slice($plan, MediaSourcePlan::W48_SLICE_HEADER, MediaSourcePlan::W48_SLICE_WIDTH));
        self::assertSame([0, 2, 1], array_slice($plan, MediaSourcePlan::W48_SLICE_HEADER + 3 * MediaSourcePlan::W48_SLICE_WIDTH));
    }

    public function test_write_telemetry_parity(): void
    {
        $this->requireBindings();
        $this->assertWriteParity([], [], [], [], 0, 0);
        $this->assertWriteParity([5], [], [], [], 1, 1);
        $this->assertWriteParity(...$this->writeBatch(64, false, 9));
        $this->assertWriteParity(...$this->writeBatch(128, true, 10));
        $extremes = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1 << 40, (1 << 40) - 1, 7, 999, 1_000, 999_999, 1_000_000];
        foreach ([[0, 0], [1, 1], [-5, -5], [PHP_INT_MAX, PHP_INT_MAX], [1, PHP_INT_MAX], [PHP_INT_MAX, 1]] as [$window, $slow]) {
            $this->assertWriteParity($extremes, $extremes, $extremes, $extremes, $window, $slow);
            $this->assertWriteParity($extremes, array_reverse($extremes), [0, 1, 300], [], $window, $slow);
        }
        $this->assertWriteParity(['a' => 1, 'b' => 2, 'c' => 3], [9 => 100], [5 => 0, 6 => 1], ['q' => 10], 1_000, 10);
        $stats = PersistenceMetrics::aggregateWriteTelemetryBatchPhp([100, 300, 2_000, -7], [900, 50, 500_000, 20], [2, 0, 5, 1], [4096, 0, 1 << 20, 10], 1_000_000, 1_000);
        self::assertCount(PersistenceMetrics::W48_WRITE_FIELDS, $stats);
        self::assertSame([4, 2_400, 0, 2_000, 600, 100, 2_000, 2_000, 500_970, 20, 500_000, 125_242, 50, 500_000, 500_000, 8, 1_052_682, 1_052_682, 8, 131_585, 1, 1, 2, 1, 0, 1, 0, 2, 4_767, 2, 1],
            array_slice($stats, 0, 31));
    }

    /**
     * Protocol (set NNTMUX_W486_BENCH=1): seed 486, 8 fixture variants, 50 warmups and
     * NNTMUX_W486_BENCH_PAIRS (default 1,000, at least 60) randomized-order pairs per
     * class/workload. Common batches hold 64 typical items, Uncommon ones 128 long, mixed-case,
     * truncated, unusual or extreme items. Fixtures are built outside timing; parity is asserted on
     * every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W486_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W486_BENCH=1 to run the paired Wave 48.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W486_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(486));
        $summary = $records = [];
        foreach (self::WAVE as $class => [$suffix]) {
            foreach (['Common' => false, 'Uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon, 486 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $class::{'useW48'.$suffix.'RustFfi'}($native);
                        $start = hrtime(true);
                        $results[(int) $native] = $variants[$variant]();
                        $elapsed[(int) $native] = hrtime(true) - $start;
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
                    'php_mean_ns' => (int) (array_sum($php) / $pairs), 'rust_mean_ns' => (int) (array_sum($rust) / $pairs),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
            $class::{'resetW48'.$suffix.'RustFfi'}();
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 486, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w486-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW48.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (self::WAVE as $class => [$suffix, , $default]) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertFalse((bool) (new ReflectionClass($class))->getConstant($default) && ! $eligible, $class.' needs >=1.5x mean/p99 on both workloads');
        }
    }

    /** @return Closure(): list<int> */
    private function benchmarkCall(string $class, bool $uncommon, int $seed): Closure
    {
        $count = $uncommon ? 128 : 64;

        return match ($class) {
            ImageCandidate::class => (function () use ($count, $uncommon, $seed): Closure {
                [$names, $heads, $sizes] = $this->imageBatch($count, $uncommon, $seed);

                return static fn (): array => ImageCandidate::scoreImageBatch($names, $heads, $sizes);
            })(),
            MappedNzbFile::class => (function () use ($count, $uncommon, $seed): Closure {
                [$subjects, $sizes, $present, $totals, $par2Names, $par2Sizes] = $this->mapBatch($count, $uncommon, $seed);

                return static fn (): array => MappedNzbFile::mapFileBatch($subjects, $sizes, $present, $totals, $par2Names, $par2Sizes);
            })(),
            MediaSourceHints::class => (function () use ($count, $uncommon, $seed): Closure {
                $names = $this->hintBatch($count, $uncommon, $seed);

                return static fn (): array => MediaSourceHints::extractHintBatch($names);
            })(),
            MediaSourcePlan::class => (function () use ($count, $uncommon, $seed): Closure {
                [$reals, $available, $parts, $budget, $probe] = $this->planBatch($count, $uncommon, $seed);

                return static fn (): array => MediaSourcePlan::planSliceBatch($reals, $available, $parts, $budget, $probe);
            })(),
            default => (function () use ($count, $uncommon, $seed): Closure {
                [$database, $disk, $artifacts, $bytes, $window, $slow] = $this->writeBatch($count, $uncommon, $seed);

                return static fn (): array => PersistenceMetrics::aggregateWriteTelemetryBatch($database, $disk, $artifacts, $bytes, $window, $slow);
            })(),
        };
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<int>} */
    private function imageBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $common = ['cover.jpg', 'Front.jpg', 'poster.jpg', 'screen%02d.jpg', 'thumb%02d.png', 'proof.jpg', 'image%02d.jpg', 'sample%02d.png'];
        $odd = ['Фото/COVER.%d.JPEG', "weird\0name%d.webp", 'BACK.%d.BMP', 'folder%d.gif', 'noext%d', "\xff\xfe%d.png", str_repeat('Long.', 20).'%d.jpg', '%d.png.jpg'];
        $names = $heads = $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            if ($uncommon) {
                $names[] = sprintf($odd[$random->getInt(0, 7)], $i);
                $width = [0, 1, 99, $random->getInt(1, 20_000), 0xFFFF][$random->getInt(0, 4)];
                $height = [0, 1, 99, $random->getInt(1, 20_000), 0xFFFF][$random->getInt(0, 4)];
                $head = match ($random->getInt(0, 9)) {
                    0 => self::jpeg($width, $height, $random->getInt(0, 6)),
                    1 => self::png($random->getInt(0, 1) === 0 ? $width : 0xFFFFFFFF, $height),
                    2 => self::gif($width, $height),
                    3 => self::webpX($random->getInt(1, 1 << 24), $random->getInt(1, 1 << 24)),
                    4 => self::webpLossy($width, $height),
                    5 => self::webpLossless(min($width, 16_384) ?: 1, min($height, 16_384) ?: 1),
                    6 => self::bmp($width, -$height),
                    7 => substr(self::jpeg($width, $height, 3), 0, $random->getInt(0, 40)),
                    8 => str_repeat("\0", $random->getInt(64, 600)),
                    default => $random->getBytes($random->getInt(1, 200)),
                };
                $heads[] = $head.$random->getBytes($random->getInt(0, 1) === 0 ? $random->getInt(1, 64) : $random->getInt(3000, 5000));
                $sizes[] = [PHP_INT_MAX, -1, 0, $random->getInt(0, 1 << 42)][$random->getInt(0, 3)];
            } else {
                $names[] = 'Movie.Name.2024.1080p-GRP.'.sprintf($common[$random->getInt(0, 7)], $i);
                $head = $random->getInt(0, 3) === 0
                    ? self::png($random->getInt(320, 1920), $random->getInt(240, 1080))
                    : self::jpeg($random->getInt(320, 1920), $random->getInt(240, 1600), $random->getInt(0, 3));
                $heads[] = $head.$random->getBytes($random->getInt(200, 600));
                $sizes[] = $random->getInt(20_000, 4_000_000);
            }
        }

        return [$names, $heads, $sizes];
    }

    /** @return array{0: list<string>, 1: list<int>, 2: list<int>, 3: list<int>, 4: list<string>, 5: list<int>} */
    private function mapBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $subjects = $sizes = $present = $totals = $par2Names = $par2Sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $real = sprintf('Show.Name.S01E%02d.1080p.WEB-DL-GRP.part%03d.rar', intdiv($i, 20) + 1, $i + 1);
            $size = $random->getInt(10_000_000, 60_000_000);
            $segments = intdiv($size, 716_800) + 1;
            $variant = $random->getInt(0, 9);
            if ($uncommon) {
                $name = match ($variant % 5) {
                    0 => strtoupper($real),
                    1 => bin2hex($random->getBytes(12)),
                    2 => "nul\0".$i,
                    3 => 'Сериал.'.$real,
                    default => $real,
                };
                $subjects[] = match ($random->getInt(0, 4)) {
                    0 => sprintf('[%d/%d] - "%s" yEnc (%d/%d)', $i + 1, $count, $name, 1, $segments),
                    1 => $name.' yEnc',
                    2 => sprintf('Re: [%d/%d] "%s" (%d/x)', $random->getInt(1_000_000, 9_999_999), $count, $name, $i),
                    3 => sprintf('  [%s/%d] %s ((%d/%d)) ', 'x', $count, $name, $segments, $segments),
                    default => sprintf('"%s" [%d/%d] - (%d/%d) "%s"', $name, $i + 1, $count, $segments, $segments, str_repeat('pad', $random->getInt(0, 30))),
                };
                $sizes[] = [PHP_INT_MAX, -1, 0, $size, $size, 4242][$random->getInt(0, 5)];
                $present[] = [PHP_INT_MIN, $segments, $random->getInt(0, $segments), PHP_INT_MAX][$random->getInt(0, 3)];
                $totals[] = [0, -1, $segments, PHP_INT_MAX][$random->getInt(0, 3)];
                $par2Names[] = $random->getInt(0, 3) === 0 ? 'dup.bin' : $real;
                $par2Sizes[] = [$size, 4242, PHP_INT_MAX, -1][$random->getInt(0, 3)];
            } else {
                $name = $variant < 6 ? $real : ($variant < 8 ? strtolower($real) : bin2hex($random->getBytes(8)).'.bin');
                $subjects[] = sprintf('[%02d/%02d] - "%s" yEnc (1/%d)', $i + 1, $count, $name, $segments);
                $sizes[] = $variant < 8 ? $size + intdiv($size, 50) : $size;
                $present[] = $random->getInt(0, 9) === 0 ? $segments - $random->getInt(1, 5) : $segments;
                $totals[] = $random->getInt(0, 1) === 0 ? $segments : 0;
                $par2Names[] = $real;
                $par2Sizes[] = $size;
            }
        }
        $order = range(0, $count - 1);
        $random->shuffleArray($order);

        return [$subjects, $sizes, $present, $totals, array_map(static fn (int $i): string => $par2Names[$i], $order), array_map(static fn (int $i): int => $par2Sizes[$i], $order)];
    }

    /** @return list<string> */
    private function hintBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $resolutions = ['2160p', '1080p', '720p', '480p', '1080i', '4K', 'UHD', '576p'];
        $sources = ['BluRay', 'WEB-DL', 'WEBRip', 'HDTV', 'BDRip', 'REMUX', 'DVDRip', 'WEB'];
        $codecs = ['x264', 'x265', 'H.264', 'H.265', 'HEVC', 'AV1', 'XviD', 'VP9'];
        $audio = ['DTS-HD.MA.5.1', 'DDP5.1', 'AAC2.0', 'TrueHD.7.1.Atmos', 'AC3', 'FLAC', 'Opus', 'DTS'];
        $extensions = ['mkv', 'mp4', 'avi', 'ts', 'm2ts', 'nfo', 'srt', 'webm'];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $parts = ['Movie.Name', (string) $random->getInt(1980, 2026), $resolutions[$random->getInt(0, 7)], $sources[$random->getInt(0, 7)], $audio[$random->getInt(0, 7)], $codecs[$random->getInt(0, 7)]];
            if ($uncommon) {
                $parts[] = ['HDR10', 'DV', 'HDR10Plus', 'PROPER', 'REPACK', 'Sample', 'Атмос', "\xff\xfe"][$random->getInt(0, 7)];
                $parts[] = str_repeat('Extra.Words.', $random->getInt(1, 10));
                $parts[] = $codecs[$random->getInt(0, 7)].'-'.strtoupper($resolutions[$random->getInt(0, 7)]);
                $random->shuffleArray($parts);
                $names[] = implode(['.', ' ', '_', '-', '[', '+'][$random->getInt(0, 5)], $parts).'-GRP.'.strtoupper($extensions[$random->getInt(0, 7)]);
            } else {
                $names[] = implode('.', $parts).'-GRP.'.$extensions[$random->getInt(0, 4)];
            }
        }

        return $names;
    }

    /** @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: int, 4: int} */
    private function planBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $reals = $available = $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($uncommon) {
                $reals[] = [0, -1, PHP_INT_MAX, $random->getInt(1, 1 << 42), $random->getInt(1, 5_000_000)][$random->getInt(0, 4)];
                $available[] = [PHP_INT_MIN, 0, PHP_INT_MAX, $random->getInt(0, 1 << 41)][$random->getInt(0, 3)];
                $parts[] = [0, -1, 1, PHP_INT_MAX, $random->getInt(1, 4_000_000)][$random->getInt(0, 4)];
            } else {
                $real = $random->getInt(50_000_000, 8_000_000_000);
                $reals[] = $random->getInt(0, 9) === 0 ? 0 : $real;
                $available[] = $random->getInt(0, 4) === 0 ? $real : $random->getInt(0, 30) * 768_000;
                $parts[] = [768_000, 716_800, 750_000, 0][$random->getInt(0, 3)];
            }
        }

        return $uncommon
            ? [$reals, $available, $parts, [PHP_INT_MIN, 0, PHP_INT_MAX, $random->getInt(0, 1 << 40)][$random->getInt(0, 3)], [PHP_INT_MIN, 1, PHP_INT_MAX, $random->getInt(1, 1 << 36)][$random->getInt(0, 3)]]
            : [$reals, $available, $parts, 400_000_000, 20_000_000];
    }

    /** @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>, 4: int, 5: int} */
    private function writeBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $database = $disk = $artifacts = $bytes = [];
        for ($i = 0; $i < $count; $i++) {
            if ($uncommon) {
                $database[] = [PHP_INT_MAX, -1, $random->getInt(0, 1 << 41), 0][$random->getInt(0, 3)];
                $disk[] = [PHP_INT_MIN, 1 << 41, $random->getInt(0, 5_000_000)][$random->getInt(0, 2)];
                $artifacts[] = $random->getInt(-3, 400);
                $bytes[] = [PHP_INT_MIN, PHP_INT_MAX, $random->getInt(0, 1 << 42)][$random->getInt(0, 2)];
            } else {
                $database[] = $random->getInt(200, 40_000);
                $disk[] = $random->getInt(500, 250_000);
                $artifacts[] = $random->getInt(0, 9);
                $bytes[] = $random->getInt(0, 40_000_000);
            }
        }

        return $uncommon
            ? [$database, $disk, $artifacts, $bytes, $random->getInt(-5, 1 << 40), $random->getInt(-5, 1 << 30)]
            : [$database, $disk, $artifacts, $bytes, 60_000_000, 100_000];
    }

    private static function jpeg(int $width, int $height, int $extraSegments = 1): string
    {
        $head = "\xFF\xD8\xFF\xE0".pack('n', 16)."JFIF\0\x01\x01\0\0\x01\0\x01\0\0";
        for ($i = 0; $i < $extraSegments; $i++) {
            $head .= ($i % 2 === 0 ? "\xFF\xDB" : "\xFF\xC4").pack('n', 67).str_repeat(\chr(0x10 + $i), 65);
        }

        return $head."\xFF\xC0".pack('n', 17)."\x08".pack('n2', $height & 0xFFFF, $width & 0xFFFF)."\x03\x01\x22\0\x02\x11\x01\x03\x11\x01";
    }

    private static function png(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.pack('N2', $width & 0xFFFFFFFF, $height & 0xFFFFFFFF)."\x08\x02\0\0\0".pack('N', 0x12345678);
    }

    private static function gif(int $width, int $height): string
    {
        return 'GIF89a'.pack('v2', $width & 0xFFFF, $height & 0xFFFF)."\xF7\0\0";
    }

    private static function webpX(int $width, int $height): string
    {
        $w = ($width - 1) & 0xFFFFFF;
        $h = ($height - 1) & 0xFFFFFF;

        return 'RIFF'.pack('V', 30).'WEBPVP8X'.pack('V', 10)."\x10\0\0\0".substr(pack('V', $w), 0, 3).substr(pack('V', $h), 0, 3);
    }

    private static function webpLossy(int $width, int $height): string
    {
        return 'RIFF'.pack('V', 30).'WEBPVP8 '.pack('V', 10)."\x30\x01\x00\x9D\x01\x2A".pack('v2', $width & 0xFFFF, $height & 0xFFFF);
    }

    private static function webpLossless(int $width, int $height): string
    {
        $bits = ($width - 1) | ($height - 1) << 14;

        return 'RIFF'.pack('V', 25).'WEBPVP8L'.pack('V', 5)."\x2F".pack('V', $bits);
    }

    private static function bmp(int $width, int $height): string
    {
        return 'BM'.pack('V3', 54, 0, 54).pack('V', 40).pack('V2', $width & 0xFFFFFFFF, $height & 0xFFFFFFFF).pack('v2', 1, 24);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     * @param  array<array-key, int>  $sizes
     */
    private function assertImageParity(array $names, array $heads, array $sizes): void
    {
        $expected = ImageCandidate::scoreImageBatchPhp($names, $heads, $sizes);
        self::assertSame($expected, ImageCandidate::scoreImageBatchNative($names, $heads, $sizes));
        foreach ([false, true] as $native) {
            ImageCandidate::useW48ImageScoreRustFfi($native);
            self::assertSame($expected, ImageCandidate::scoreImageBatch($names, $heads, $sizes));
        }
    }

    /**
     * @param  array<array-key, string>  $subjects
     * @param  array<array-key, int>  $sizes
     * @param  array<array-key, int>  $present
     * @param  array<array-key, int>  $totals
     * @param  array<array-key, string>  $par2Names
     * @param  array<array-key, int>  $par2Sizes
     */
    private function assertMapParity(array $subjects, array $sizes, array $present, array $totals, array $par2Names, array $par2Sizes): void
    {
        $expected = MappedNzbFile::mapFileBatchPhp($subjects, $sizes, $present, $totals, $par2Names, $par2Sizes);
        self::assertSame($expected, MappedNzbFile::mapFileBatchNative($subjects, $sizes, $present, $totals, $par2Names, $par2Sizes));
        foreach ([false, true] as $native) {
            MappedNzbFile::useW48FileMapRustFfi($native);
            self::assertSame($expected, MappedNzbFile::mapFileBatch($subjects, $sizes, $present, $totals, $par2Names, $par2Sizes));
        }
    }

    /** @param  array<array-key, string>  $names */
    private function assertHintParity(array $names): void
    {
        $expected = MediaSourceHints::extractHintBatchPhp($names);
        self::assertSame($expected, MediaSourceHints::extractHintBatchNative($names));
        foreach ([false, true] as $native) {
            MediaSourceHints::useW48HintExtractRustFfi($native);
            self::assertSame($expected, MediaSourceHints::extractHintBatch($names));
        }
    }

    /**
     * @param  array<array-key, int>  $reals
     * @param  array<array-key, int>  $available
     * @param  array<array-key, int>  $parts
     */
    private function assertPlanParity(array $reals, array $available, array $parts, int $budget, int $probe): void
    {
        $expected = MediaSourcePlan::planSliceBatchPhp($reals, $available, $parts, $budget, $probe);
        self::assertSame($expected, MediaSourcePlan::planSliceBatchNative($reals, $available, $parts, $budget, $probe));
        foreach ([false, true] as $native) {
            MediaSourcePlan::useW48SlicePlanRustFfi($native);
            self::assertSame($expected, MediaSourcePlan::planSliceBatch($reals, $available, $parts, $budget, $probe));
        }
    }

    /**
     * @param  array<array-key, int>  $database
     * @param  array<array-key, int>  $disk
     * @param  array<array-key, int>  $artifacts
     * @param  array<array-key, int>  $bytes
     */
    private function assertWriteParity(array $database, array $disk, array $artifacts, array $bytes, int $window, int $slow): void
    {
        $expected = PersistenceMetrics::aggregateWriteTelemetryBatchPhp($database, $disk, $artifacts, $bytes, $window, $slow);
        self::assertSame($expected, PersistenceMetrics::aggregateWriteTelemetryBatchNative($database, $disk, $artifacts, $bytes, $window, $slow));
        foreach ([false, true] as $native) {
            PersistenceMetrics::useW48WriteTelemetryRustFfi($native);
            self::assertSame($expected, PersistenceMetrics::aggregateWriteTelemetryBatch($database, $disk, $artifacts, $bytes, $window, $slow));
        }
    }

    /**
     * The toggle is mutable static state, so each read is a fresh call.
     *
     * @phpstan-impure
     */
    private function uses(string $class, string $suffix): bool
    {
        return (bool) $class::{'usesW48'.$suffix.'RustFfi'}();
    }

    private function requireBindings(): void
    {
        if (! extension_loaded('FFI') || PersistenceMetrics::aggregateWriteTelemetryBatchNative([1], [1], [1], [1], 1, 1) === null) {
            self::markTestSkipped('libnntmux_pipeline with the Wave 48.6 exports is not available.');
        }
    }
}
