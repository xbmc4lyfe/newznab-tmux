<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\AdditionalBatchResult;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\DTO\DownloadedArchive;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use Closure;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

final class RustAdditionalDtoBatchPlanArchivedMetricsArchiveW47ParityTest extends TestCase
{
    /** Class => [toggle suffix, batch method, default constant]. */
    private const WAVE = [
        AdditionalBatchResult::class => ['OutcomeTelemetry', 'summarizeOutcomeTelemetry', 'W47_OUTCOME_TELEMETRY_RUST_FFI_DEFAULT'],
        AdditionalWorkPlan::class => ['TaskSchedule', 'scheduleTaskBatch', 'W47_TASK_SCHEDULE_RUST_FFI_DEFAULT'],
        ArchivedMediaEntry::class => ['EntryRank', 'rankEntryBatch', 'W47_ENTRY_RANK_RUST_FFI_DEFAULT'],
        DownloadMetrics::class => ['ArticleStats', 'articleStatsBatch', 'W47_ARTICLE_STATS_RUST_FFI_DEFAULT'],
        DownloadedArchive::class => ['VolumeVerify', 'verifyVolumeBatch', 'W47_VOLUME_VERIFY_RUST_FFI_DEFAULT'],
    ];

    /**
     * Apple M5/macOS arm64 (shared host, load ~10), PHP 8.5.12 CLI without opcache, release
     * Rust; seed 476, 50 warmups and 2,000 pairs/workload (w476-benchmark-ffc1299e94374466.json
     * in system tmp). Common mean/p99 then Uncommon mean/p99 speedups (PHP time / Rust time);
     * a default is Rust only when all four reach 1.5x. DownloadMetrics misses on Uncommon p99:
     * its PHP fold is cheap arithmetic, so packing three int64 columns dominates the tail.
     */
    private const QUALIFICATION = [
        AdditionalBatchResult::class => [3.209, 2.121, 3.070, 2.516],
        AdditionalWorkPlan::class => [3.232, 2.039, 2.783, 2.382],
        ArchivedMediaEntry::class => [3.447, 2.683, 4.008, 3.350],
        DownloadMetrics::class => [3.802, 2.442, 2.006, 1.408],
        DownloadedArchive::class => [5.331, 3.765, 3.180, 2.424],
    ];

    private const RAR5 = "Rar!\x1A\x07\x01\x00";

    protected function tearDown(): void
    {
        foreach (self::WAVE as $class => [$suffix]) {
            $class::{'resetW47'.$suffix.'RustFfi'}();
        }
        parent::tearDown();
    }

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => [$suffix, $method, $default]) {
            $reflection = new ReflectionClass($class);
            foreach (['useW47'.$suffix.'RustFfi', 'usesW47'.$suffix.'RustFfi', 'resetW47'.$suffix.'RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertTrue($reflection->hasConstant($default), $class.'::'.$default);
            self::assertTrue($reflection->hasConstant('W47_FFI_CDEF'), $class.'::W47_FFI_CDEF');
            self::assertStringContainsString('nntmux_w47_', (string) $reflection->getConstant('W47_FFI_CDEF'));
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_route(): void
    {
        foreach (self::WAVE as $class => [$suffix, , $default]) {
            $expected = (bool) (new ReflectionClass($class))->getConstant($default);
            self::assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $expected, $class);
            self::assertSame($expected, $this->uses($class, $suffix), $class);
            $class::{'useW47'.$suffix.'RustFfi'}(! $expected);
            self::assertSame(! $expected, $this->uses($class, $suffix));
            foreach (self::WAVE as $other => [$otherSuffix, , $otherDefault]) {
                if ($other !== $class) {
                    self::assertSame((bool) (new ReflectionClass($other))->getConstant($otherDefault), $this->uses($other, $otherSuffix));
                }
            }
            $class::{'useW47'.$suffix.'RustFfi'}(null);
            self::assertSame($expected, $this->uses($class, $suffix));
            $class::{'useW47'.$suffix.'RustFfi'}(! $expected);
            $class::{'resetW47'.$suffix.'RustFfi'}();
            self::assertSame($expected, $this->uses($class, $suffix));
        }
        // The Wave 47 switches leave the existing toggles alone.
        self::assertFalse((new AdditionalWorkPlan)->useRustFfi);
        self::assertFalse(DownloadMetrics::usesRustFfi());
    }

    public function test_outcome_telemetry_parity(): void
    {
        $this->requireBindings();
        $this->assertOutcomeParity([], [], [], []);
        $this->assertOutcomeParity(['completed'], [], [], []);
        $this->assertOutcomeParity(...$this->outcomeBatch(64, false, 1));
        $this->assertOutcomeParity(...$this->outcomeBatch(128, true, 2));
        $this->assertOutcomeParity(
            ['completed', 'bogus', '', "timed-out\0", 'TIMED-OUT', '0', 'failed', 'awaiting-late-articles'],
            ["Connection RESET by peer\0x", "\xff\xfe", 'PASSWORD required and timeout', '430', 'temporarily unavailable', str_repeat('a', 5000).'crc'],
            [PHP_INT_MAX, PHP_INT_MIN, -1, 0, 99_999, 100_000, 59_999_999, 60_000_000, 7],
            [PHP_INT_MIN, PHP_INT_MAX, 2, 3, -1],
        );
        $this->assertOutcomeParity(['a' => 'completed', 7 => 'failed'], ['x' => 'missing'], [3 => 5, 9 => 1_000_000], ['z' => 4]);
        $summary = AdditionalBatchResult::summarizeOutcomeTelemetryPhp(['completed', 'timed-out', 'failed'], ['', 'Read timeout', 'CRC error'], [10, 2_000_000, 5], [0, 1, 0]);
        self::assertCount(AdditionalBatchResult::W47_OUTCOME_FIELDS, $summary);
        self::assertSame([3, 1, 0, 0, 0, 0, 1], array_slice($summary, 0, 7));
        self::assertSame([1, 1, 0, 1, 0, 0], array_slice($summary, 16, 6));
        self::assertSame([1, 0, 2_000_015, 2_000_000], array_slice($summary, 22, 4));
        self::assertSame(1, $summary[31]);
    }

    public function test_task_schedule_parity(): void
    {
        $this->requireBindings();
        $this->assertScheduleParity([], [], 0);
        $this->assertScheduleParity(['only.nfo'], [], -1);
        $this->assertScheduleParity(...$this->scheduleBatch(64, false, 3));
        $this->assertScheduleParity(...$this->scheduleBatch(128, true, 4));
        $names = ['x.part01.rar', 'x.part1.rar', 'x.part02.rar', 'x.part.rar', 'x.PART0001.RAR', 'x.r00', 'x.r000', 'x.r0a', 'x.001', 'x.002', 'x.zip', 'x.7Z',
            'x.vol01+02.par2', 'x.par2', 'x.nfo', 'x-SAMPLE.mkv', 'x.mkv', 'x.jpg', 'noext', '', '.', "a\0b.rar", "\xff.mp3", 'x.part9999999999999999999.rar'];
        $sizes = [1, PHP_INT_MAX, PHP_INT_MIN, 0, -1, 1 << 40, 1024, 1023, 2048, 5];
        foreach ([0, 1, 4096, PHP_INT_MAX, PHP_INT_MIN] as $budget) {
            $this->assertScheduleParity($names, $sizes, $budget);
        }
        $this->assertScheduleParity(['k' => 'a.rar', 'b.nfo'], ['z' => 10, 'y' => 20, 'x' => 30], 15);
        $plan = AdditionalWorkPlan::scheduleTaskBatchPhp(['Movie.MKV', 'movie.nfo', 'movie.part01.rar', 'movie.part02.rar'], [4096, 2048, 1024, -1], 3000);
        self::assertSame([4, 1, 1, 1, 0, 1, 0, 0, 0, 0, 2, 1024], array_slice($plan, 0, AdditionalWorkPlan::W47_TASK_HEADER));
        self::assertSame([2, 1, 0, 3], array_map(static fn (int $value): int => $value & 0xFFFFFFFF, [$plan[12], $plan[14], $plan[16], $plan[18]]));
    }

    public function test_entry_rank_parity(): void
    {
        $this->requireBindings();
        $this->assertRankParity([], []);
        $this->assertRankParity([''], []);
        $this->assertRankParity(...$this->rankBatch(64, false, 5));
        $this->assertRankParity(...$this->rankBatch(128, true, 6));
        $paths = ['Movie/Sample/movie-sample.mkv', 'C:\\Movie\\..\\Movie.x265.MKV', 'Movie//./Movie.1080p.x264.mkv', 'Movie/cover.jpg', "\x01../", '/etc/passwd', 'c:', '1:x.mkv',
            '.hidden.mkv', "a\0b.mkv", "dir\x7f/x.avi", "\xff\xfe.mp4", 'x.AV1.webm', 'XviD.avi', 'x.srt', 'x.flac', 'a/b/c/d/e/f.ts', './x.vob', '..', '\\\\server\\share\\x.wmv'];
        $sizes = [100, 900, 800, 50, 1, PHP_INT_MAX, PHP_INT_MIN, 0, -5, 800, 800];
        $this->assertRankParity($paths, $sizes);
        $this->assertRankParity(['b' => 'x.mkv', 'a' => 'y.mkv'], [9 => 1]);
        $rank = ArchivedMediaEntry::rankEntryBatchPhp(array_slice($paths, 0, 5), array_slice($sizes, 0, 5));
        self::assertSame([5, 2, 1, 2, 2], array_slice($rank, 0, ArchivedMediaEntry::W47_ENTRY_HEADER));
        self::assertSame([1, 3, 0, 2, 4], array_map(static fn (int $index): int => $rank[5 + 5 * $index + 4], range(0, 4)));
        self::assertSame(crc32('Movie/Movie.1080p.x264.mkv'), $rank[5 + 5 * 2 + 3]);
    }

    public function test_article_stats_parity(): void
    {
        $this->requireBindings();
        $this->assertArticleParity([], [], [], 0, 0);
        $this->assertArticleParity([5], [], [], 1, 1);
        $this->assertArticleParity(...$this->articleBatch(64, false, 7));
        $this->assertArticleParity(...$this->articleBatch(128, true, 8));
        $extremes = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1 << 40, (1 << 40) - 1, 7];
        foreach ([[0, 0], [1, 1], [-5, -5], [PHP_INT_MAX, PHP_INT_MAX], [1, PHP_INT_MAX], [PHP_INT_MAX, 1]] as [$window, $link]) {
            $this->assertArticleParity($extremes, $extremes, [0, 0, 1, 2, 3, 4, -1], $window, $link);
            $this->assertArticleParity($extremes, array_reverse($extremes), [0, 0, 0, 0, 0, 0, 0], $window, $link);
        }
        $this->assertArticleParity(['a' => 1, 'b' => 2, 'c' => 3], [9 => 100], [5 => 0, 6 => 1], 1_000, 10);
        $stats = DownloadMetrics::articleStatsBatchPhp([100, 300, 200, 999, 400, -7], [1000, 2000, 3000, 9, 4000, 5000], [0, 0, 1, 2, 0, 9], 1_000_000, 20_000);
        self::assertCount(DownloadMetrics::W47_ARTICLE_FIELDS, $stats);
        self::assertSame([6, 3, 1, 1, 0, 1, 7000, 800, 100, 400, 266, 300, 400, 400, 150, 7000, 166_666, 500_000, 350_000, 2], $stats);
    }

    public function test_volume_verify_parity(): void
    {
        $this->requireBindings();
        $this->assertVolumeParity([], []);
        $this->assertVolumeParity(['x.rar'], []);
        $this->assertVolumeParity(...$this->volumeBatch(64, false, 9));
        $this->assertVolumeParity(...$this->volumeBatch(128, true, 10));
        $names = ['show.part02.rar', 'show.part01.rar', 'show.part04.rar', 'show.par2', 'show.vol00+01.par2', 'archive.7z.002', 'readme', 'a.r07', 'A.Z01', 'a.ZIP', 'a.mkv.001',
            'a.mkv.001', 'a.part1234567.rar', 'b.7z.001', 'c.gz', "d\0.rar", '', '.', 'e.part00.rar', "\xff.r99", 'show.part02.rar'];
        $heads = [self::RAR5, self::RAR5, 'junk', "PAR2\0PKTx", "PAR2\0PKTy", '', 'x', "Rar!\x1A\x07\x00", "PK\x07\x08", "PK\x03\x04", "\0\0", '', self::RAR5,
            "7z\xBC\xAF\x27\x1C", "\x1F\x8B\x08", self::RAR5, '', '', self::RAR5, str_repeat("\0", 200), self::RAR5];
        $this->assertVolumeParity($names, $heads);
        $this->assertVolumeParity(['b' => 'x.r00', 'a' => 'x.rar'], [5 => self::RAR5]);
        $verified = DownloadedArchive::verifyVolumeBatchPhp(array_slice($names, 0, 7), array_slice($heads, 0, 7));
        self::assertSame([7, 4, 4, 1, 1, 1, 2, 0], array_slice($verified, 0, 8));
        self::assertSame([2 << 4 | 2 << 8, 1, 0, 1], array_slice($verified, DownloadedArchive::W47_VOLUME_HEADER, DownloadedArchive::W47_VOLUME_WIDTH));
    }

    /**
     * Protocol (set NNTMUX_W476_BENCH=1): seed 476, 8 fixture variants, 50 warmups and
     * NNTMUX_W476_BENCH_PAIRS (default 1,000) randomized-order pairs per class/workload. Common
     * batches hold 64 typical items, Uncommon ones 128 long, mixed-case, unusual or extreme items.
     * Fixtures are built outside timing; parity is asserted on every pair. Timings cover public
     * dispatch, marshaling, FFI and unpacking. Nearest-rank p99. The summary and raw pairs are
     * written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W476_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W476_BENCH=1 to run the paired Wave 47.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W476_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(476));
        $summary = $records = [];
        foreach (self::WAVE as $class => [$suffix]) {
            foreach (['Common' => false, 'Uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon, 476 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $class::{'useW47'.$suffix.'RustFfi'}($native);
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
            $class::{'resetW47'.$suffix.'RustFfi'}();
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 476, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w476-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW47.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
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
            AdditionalBatchResult::class => (function () use ($count, $uncommon, $seed): Closure {
                [$outcomes, $errors, $elapsed, $attempts] = $this->outcomeBatch($count, $uncommon, $seed);

                return static fn (): array => AdditionalBatchResult::summarizeOutcomeTelemetry($outcomes, $errors, $elapsed, $attempts);
            })(),
            AdditionalWorkPlan::class => (function () use ($count, $uncommon, $seed): Closure {
                [$names, $sizes, $budget] = $this->scheduleBatch($count, $uncommon, $seed);

                return static fn (): array => AdditionalWorkPlan::scheduleTaskBatch($names, $sizes, $budget);
            })(),
            ArchivedMediaEntry::class => (function () use ($count, $uncommon, $seed): Closure {
                [$paths, $sizes] = $this->rankBatch($count, $uncommon, $seed);

                return static fn (): array => ArchivedMediaEntry::rankEntryBatch($paths, $sizes);
            })(),
            DownloadMetrics::class => (function () use ($count, $uncommon, $seed): Closure {
                [$latencies, $bytes, $statuses, $window, $link] = $this->articleBatch($count, $uncommon, $seed);

                return static fn (): array => DownloadMetrics::articleStatsBatch($latencies, $bytes, $statuses, $window, $link);
            })(),
            default => (function () use ($count, $uncommon, $seed): Closure {
                [$names, $heads] = $this->volumeBatch($count, $uncommon, $seed);

                return static fn (): array => DownloadedArchive::verifyVolumeBatch($names, $heads);
            })(),
        };
    }

    /** @return array{0: list<string>, 1: list<string>, 2: list<int>, 3: list<int>} */
    private function outcomeBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $common = ['completed', 'completed', 'completed', 'no-useful-artifacts', 'passworded', 'timed-out', 'failed', 'group-unavailable'];
        $rare = ['deleted-after-timeout', 'deleted-broken-nzb', 'deleted-par2-only', 'not-found', 'awaiting-late-articles', 'temporary-workspace-unavailable', 'Completed', 'mystery'];
        $messages = ['', '', '', 'Read timeout after 30s', '430 No Such Article', 'Archive is password protected', 'CRC mismatch in volume 3', 'Connection reset by peer'];
        $odd = ['Unexpected EOF while reading NNTP response from news.example.com: SOCKET CLOSED BY REMOTE HOST', "Bad\0data in segment", "\xff\xfe broken UTF-8 message", 'Segment 17 of 942 INCOMPLETE; retry scheduled'];
        $outcomes = $errors = $elapsed = $attempts = [];
        for ($i = 0; $i < $count; $i++) {
            $outcomes[] = $uncommon && $random->getInt(0, 1) === 1 ? $rare[$random->getInt(0, 7)] : $common[$random->getInt(0, 7)];
            $errors[] = $uncommon ? $odd[$random->getInt(0, 3)].str_repeat(' detail', $random->getInt(0, 20)) : $messages[$random->getInt(0, 7)];
            $elapsed[] = $uncommon ? [PHP_INT_MAX, -1, $random->getInt(0, 1 << 45), 0][$random->getInt(0, 3)] : $random->getInt(20_000, 30_000_000);
            $attempts[] = $uncommon ? $random->getInt(-2, 40) : $random->getInt(0, 3);
        }

        return [$outcomes, $errors, $elapsed, $attempts];
    }

    /** @return array{0: list<string>, 1: list<int>, 2: int} */
    private function scheduleBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $common = ['.part%02d.rar', '.r%02d', '.vol%02d+01.par2', '.par2', '.nfo', '-sample.mkv', '.mkv', '.jpg', '.%03d', '.sfv'];
        $names = $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $suffix = sprintf($common[$random->getInt(0, 9)], $i + 1);
            $names[] = $uncommon
                ? 'Сериал.Show.Name.S01.COMPLETE.2160p.WEB-DL.DDP5.1.Atmos.HDR.H.265-GROUP/'.str_repeat('Long.', $random->getInt(1, 12)).'Episode.'.$i.strtoupper($suffix)
                : 'Movie.Name.2024.1080p.BluRay.x264-GRP'.$suffix;
            $sizes[] = $uncommon ? [PHP_INT_MAX, -1, 0, $random->getInt(0, 1 << 42)][$random->getInt(0, 3)] : $random->getInt(10_000, 100_000_000);
        }

        return [$names, $sizes, $uncommon ? $random->getInt(-1, 1 << 44) : 1_500_000_000];
    }

    /** @return array{0: list<string>, 1: list<int>} */
    private function rankBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $common = ['Movie.2024.1080p.x264-GRP.mkv', 'Sample/movie-sample.mkv', 'movie.nfo', 'Subs/English.srt', 'cover.jpg', 'Movie.2024.HEVC.mp4', 'extras/featurette.avi', 'proof.png'];
        $odd = ['C:\\Rips\\..\\Movie.X265.MKV', "evil/\x01../../etc/passwd", '/abs/path/Movie.Sample.TS', '\\\\nas\\share\\.hidden.m2ts', 'Фильм/Фильм.2160p.HDR.AV1.webm', './././deep/'.str_repeat('nested/', 12).'file.VOB'];
        $paths = $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            $paths[] = $uncommon ? $odd[$random->getInt(0, 5)].$i : 'Movie.2024.1080p/'.$common[$random->getInt(0, 7)];
            $sizes[] = $uncommon ? [PHP_INT_MAX, -9, 0, $random->getInt(0, 1 << 42)][$random->getInt(0, 3)] : $random->getInt(1_000, 8_000_000_000);
        }

        return [$paths, $sizes];
    }

    /** @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: int, 4: int} */
    private function articleBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $latencies = $bytes = $statuses = [];
        for ($i = 0; $i < $count; $i++) {
            $latencies[] = $uncommon ? [PHP_INT_MAX, -1, $random->getInt(0, 1 << 41), 0][$random->getInt(0, 3)] : $random->getInt(2_000, 400_000);
            $bytes[] = $uncommon ? [PHP_INT_MIN, 1 << 41, $random->getInt(0, 800_000)][$random->getInt(0, 2)] : $random->getInt(700_000, 800_000);
            $statuses[] = $uncommon ? $random->getInt(-1, 6) : ($random->getInt(0, 19) === 0 ? $random->getInt(1, 3) : 0);
        }

        return [$latencies, $bytes, $statuses, $uncommon ? $random->getInt(-5, 1 << 40) : 2_000_000, $uncommon ? $random->getInt(-5, 1 << 50) : 125_000_000];
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function volumeBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $signatures = [self::RAR5, "Rar!\x1A\x07\x00", "PK\x03\x04", "7z\xBC\xAF\x27\x1C", "PAR2\0PKT", 'garbage!'];
        $names = $heads = [];
        for ($i = 0; $i < $count; $i++) {
            if ($uncommon) {
                $names[] = ['Show.S01E'.($i % 9).'.PART'.($i % 40 + 1).'.RAR', 'pack.7Z.'.sprintf('%03d', $i % 50 + 1), 'Split.MKV.'.sprintf('%03d', $i + 1), 'set'.($i % 5).'.Z'.sprintf('%02d', $i % 30),
                    "odd\0name.r".sprintf('%02d', $i % 99), 'no_extension_'.$i, 'x.vol'.$i.'+2.PAR2'][$random->getInt(0, 6)];
                $heads[] = $signatures[$random->getInt(0, 5)].$random->getBytes($random->getInt(1, 120));
            } else {
                $names[] = 'Movie.Name.2024.1080p.BluRay.x264-GRP.part'.sprintf('%02d', $i + 1).'.rar';
                $heads[] = self::RAR5.$random->getBytes(56);
            }
        }

        return [$names, $heads];
    }

    /**
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, string>  $errors
     * @param  array<array-key, int>  $elapsed
     * @param  array<array-key, int>  $attempts
     */
    private function assertOutcomeParity(array $outcomes, array $errors, array $elapsed, array $attempts): void
    {
        $expected = AdditionalBatchResult::summarizeOutcomeTelemetryPhp($outcomes, $errors, $elapsed, $attempts);
        self::assertSame($expected, AdditionalBatchResult::summarizeOutcomeTelemetryNative($outcomes, $errors, $elapsed, $attempts));
        foreach ([false, true] as $native) {
            AdditionalBatchResult::useW47OutcomeTelemetryRustFfi($native);
            self::assertSame($expected, AdditionalBatchResult::summarizeOutcomeTelemetry($outcomes, $errors, $elapsed, $attempts));
        }
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $sizes
     */
    private function assertScheduleParity(array $names, array $sizes, int $budget): void
    {
        $expected = AdditionalWorkPlan::scheduleTaskBatchPhp($names, $sizes, $budget);
        self::assertSame($expected, AdditionalWorkPlan::scheduleTaskBatchNative($names, $sizes, $budget));
        foreach ([false, true] as $native) {
            AdditionalWorkPlan::useW47TaskScheduleRustFfi($native);
            self::assertSame($expected, AdditionalWorkPlan::scheduleTaskBatch($names, $sizes, $budget));
        }
    }

    /**
     * @param  array<array-key, string>  $paths
     * @param  array<array-key, int>  $sizes
     */
    private function assertRankParity(array $paths, array $sizes): void
    {
        $expected = ArchivedMediaEntry::rankEntryBatchPhp($paths, $sizes);
        self::assertSame($expected, ArchivedMediaEntry::rankEntryBatchNative($paths, $sizes));
        foreach ([false, true] as $native) {
            ArchivedMediaEntry::useW47EntryRankRustFfi($native);
            self::assertSame($expected, ArchivedMediaEntry::rankEntryBatch($paths, $sizes));
        }
    }

    /**
     * @param  array<array-key, int>  $latencies
     * @param  array<array-key, int>  $bytes
     * @param  array<array-key, int>  $statuses
     */
    private function assertArticleParity(array $latencies, array $bytes, array $statuses, int $window, int $link): void
    {
        $expected = DownloadMetrics::articleStatsBatchPhp($latencies, $bytes, $statuses, $window, $link);
        self::assertSame($expected, DownloadMetrics::articleStatsBatchNative($latencies, $bytes, $statuses, $window, $link));
        foreach ([false, true] as $native) {
            DownloadMetrics::useW47ArticleStatsRustFfi($native);
            self::assertSame($expected, DownloadMetrics::articleStatsBatch($latencies, $bytes, $statuses, $window, $link));
        }
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     */
    private function assertVolumeParity(array $names, array $heads): void
    {
        $expected = DownloadedArchive::verifyVolumeBatchPhp($names, $heads);
        self::assertSame($expected, DownloadedArchive::verifyVolumeBatchNative($names, $heads));
        foreach ([false, true] as $native) {
            DownloadedArchive::useW47VolumeVerifyRustFfi($native);
            self::assertSame($expected, DownloadedArchive::verifyVolumeBatch($names, $heads));
        }
    }

    /**
     * The toggle is mutable static state, so each read is a fresh call.
     *
     * @phpstan-impure
     */
    private function uses(string $class, string $suffix): bool
    {
        return (bool) $class::{'usesW47'.$suffix.'RustFfi'}();
    }

    private function requireBindings(): void
    {
        if (! extension_loaded('FFI') || AdditionalBatchResult::summarizeOutcomeTelemetryNative(['completed']) === null) {
            self::markTestSkipped('libnntmux_pipeline with the Wave 47.6 exports is not available.');
        }
    }
}
