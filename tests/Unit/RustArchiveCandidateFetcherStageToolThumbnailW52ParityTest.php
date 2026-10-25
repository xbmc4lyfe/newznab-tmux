<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\Media\MediaArticleFetcher;
use App\Services\AdditionalProcessing\Media\MediaSourceStage;
use App\Services\AdditionalProcessing\Media\MediaToolFactory;
use App\Services\AdditionalProcessing\Media\ThumbnailFrameSelector;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustArchiveCandidateFetcherStageToolThumbnailW52ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        ArchiveCandidate::class => 'volumeSetGroupBatch',
        MediaArticleFetcher::class => 'planAppendBatch',
        MediaSourceStage::class => 'middleVolumeBatch',
        MediaToolFactory::class => 'driverConfigurationBatch',
        ThumbnailFrameSelector::class => 'windowBatch',
    ];

    /** Header words before the per-record rows, and rows per record. */
    private const LAYOUT = [
        ArchiveCandidate::class => [ArchiveCandidate::W52_ARCHIVE_HEADER, 1],
        MediaArticleFetcher::class => [MediaArticleFetcher::W52_APPEND_HEADER, 1],
        MediaSourceStage::class => [MediaSourceStage::W52_MIDDLE_HEADER, 1],
        MediaToolFactory::class => [MediaToolFactory::W52_DRIVER_HEADER, 1],
        ThumbnailFrameSelector::class => [ThumbnailFrameSelector::W52_WINDOW_HEADER, 2],
    ];

    /**
     * Apple M5/macOS arm64 (shared host, load ~8-9), PHP 8.5.12 CLI without opcache/JIT, release
     * Rust; base 746d186da plus this change, seed 526, 50 warmups and 1,000 pairs/workload
     * (w526-benchmark-157804fbbe94583f.json in system tmp). Common mean/p99 then uncommon mean/p99
     * speedups (PHP time / Rust time); a default is Rust only when all four reach 1.5x. Across two
     * further 1,000-pair runs of the final code, the lowest values were MediaToolFactory's common
     * p99 at 1.635x and uncommon p99 at 1.846x (host-contention tails on ~5us native calls); every
     * mean stayed above 2.2x.
     */
    private const QUALIFICATION = [
        ArchiveCandidate::class => [2.942, 2.355, 2.771, 2.059],
        MediaArticleFetcher::class => [3.865, 3.632, 3.363, 1.766],
        MediaSourceStage::class => [4.187, 3.653, 3.013, 3.027],
        MediaToolFactory::class => [2.813, 2.109, 2.275, 1.893],
        ThumbnailFrameSelector::class => [3.330, 2.222, 3.555, 4.645],
    ];

    private const ARTICLE = 716_800;

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            $state = $class === ArchiveCandidate::class ? 'w52RustFfiState' : 'w52Binding';
            foreach (['usesW52RustFfi', 'withW52RustFfi', $state, $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertTrue($reflection->getMethod($state)->isPrivate(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW52RustFfi', 'withW52RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W52_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w526_', (string) $reflection->getConstant('W52_FFI_CDEF'), $class);
        }
        // A readonly class cannot declare static properties; its state lives in function statics.
        $archive = new ReflectionClass(ArchiveCandidate::class);
        self::assertTrue($archive->isReadOnly());
        self::assertSame([], $archive->getProperties(\ReflectionProperty::IS_STATIC));
        self::assertSame(2, $archive->getMethod('w52RustFfiState')->getNumberOfParameters());
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W52_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW52RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW52RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW52RustFfi(! $enabled, static fn (): bool => $class::usesW52RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W52_RUST_FFI_DEFAULT'), $other::usesW52RustFfi());
                        }
                    }

                    return $class::usesW52RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW52RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW52RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW52RustFfi(), $class);
        }
        // The Wave 52 switches leave the earlier toggles alone.
        self::assertFalse(ArchiveCandidate::usesRustFfi());
        self::assertFalse(MediaArticleFetcher::usesW45RustFfi());
        self::assertFalse(MediaSourceStage::usesW45RustFfi());
        self::assertFalse(MediaToolFactory::usesW45RustFfi());
        self::assertFalse(ThumbnailFrameSelector::usesW45RustFfi());
    }

    public function test_archive_volume_set_parity(): void
    {
        $this->requireBindings();
        $this->assertArchiveParity([]);
        $this->assertArchiveParity(['']);
        $subjects = [
            'a' => '[01/10] - "Movie.Title.part01.rar" yEnc (1/50)',
            'b' => 'Movie.Title.part02.rar yEnc (1/50)',
            7 => '"movie.title.r00" yEnc',
            '"Movie.Title.zip" yEnc (1/2)',
            '"0123456789abcdef0123456789abcdef.12"',
            '"0123456789ABCDEF0123456789ABCDEF.12"',
            'First Movie.rar yEnc',
            'Second Movie.rar yEnc',
            '"movie.7z.001"',
            '"movie.7z"',
            '"movie.mkv.001" yEnc',
            '"movie.mkv.002" yEnc',
            "\"bad\r\nname.rar\" movie.rar",
            "nul\0name.rar",
            "-\x0b\t",
            '- [01/10] - movie.z01 - (1/3)',
            '"  Padded.ZIPX  "',
            '""',
            'yEnc',
            '.rar',
        ];
        $this->assertArchiveParity($subjects);
        // PCRE's \s matches a vertical tab, which the shared Rust name parser does not, so PHP groups it.
        self::assertNull(ArchiveCandidate::volumeSetGroupBatchNative($subjects));
        self::assertNotNull(ArchiveCandidate::volumeSetGroupBatchNative(array_diff($subjects, ["-\x0b\t"])));
        $out = ArchiveCandidate::volumeSetGroupBatchPhp($subjects);
        $header = ArchiveCandidate::W52_ARCHIVE_HEADER;
        // Each row carries volumeSetKeyPhp()'s grouping.
        foreach (array_values($subjects) as $index => $subject) {
            $leader = $out[$header + $index] >> 3;
            self::assertSame(ArchiveCandidate::volumeSetKeyPhp($subject), ArchiveCandidate::volumeSetKeyPhp(array_values($subjects)[$leader]));
        }
        self::assertSame(\count(array_unique(array_map(ArchiveCandidate::volumeSetKeyPhp(...), $subjects))), $out[1]);
        self::assertSame(0, $out[$header + 1] >> 3);
        self::assertSame(0, $out[$header + 2] & 7);
    }

    public function test_article_append_plan_parity(): void
    {
        $this->requireBindings();
        $names = ['video.mkv', '.hidden', 'a/b', "caf\xc3\xa9.mp4", "bad\xff.mkv", "tab\t.mkv", '-x.mkv', "nul\0.mkv", 'a\\b', ''];
        $lengths = [100, 100, 100, 100, 100, 100, 100, 100, 100, 100];
        $contiguous = [0, 100, 200, 300, 400, 500, 600, 700, 800, 900];
        $this->assertAppendParity([], [], [], 0, 0, 100);
        $this->assertAppendParity([], [], [], -4, null, 0);
        foreach ([[0, 0, 250], [0, 0, 1000], [0, 0, 1], [0, 0, 0], [0, 0, -5], [3, null, 1000], [3, 300, 1000], [3, 299, 1000], [-2, 0, PHP_INT_MAX], [PHP_INT_MAX, 0, 10], [0, -7, 300], [0, PHP_INT_MAX, 300]] as [$from, $expected, $max]) {
            $this->assertAppendParity($lengths, $contiguous, $names, $from, $expected, $max);
        }
        $cached = [-1, -1, 200, 300, 400, 500, 600, 700, 800, 900];
        $this->assertAppendParity($lengths, $cached, $names, 0, 0, 1000);
        $this->assertAppendParity($lengths, $cached, $names, 0, null, 1000);
        $this->assertAppendParity($lengths, [0, 150, 200], $names, 0, 0, 1000);
        $this->assertAppendParity([PHP_INT_MAX, -5, PHP_INT_MIN], [PHP_INT_MAX, PHP_INT_MIN, 0], ['x'], 0, null, PHP_INT_MAX);
        $this->assertAppendParity(['k' => 10, 'l' => 20], ['m' => 0, 'n' => 10], ['o' => 'video.mkv', 'p' => 'video.mkv'], 0, 0, 25);

        $out = MediaArticleFetcher::planAppendBatchPhp($lengths, $contiguous, $names, 0, 0, 250);
        self::assertSame([10, 0, 3, 250, 1, 300, 2, 1], \array_slice($out, 0, 8));
        self::assertSame(1 | 2 | 4 | 100 << 3, $out[MediaArticleFetcher::W52_APPEND_HEADER]);
        self::assertSame(1 | 50 << 3, $out[MediaArticleFetcher::W52_APPEND_HEADER + 2]);
        // Safe names follow isSafeWitnessNamePhp() exactly.
        foreach ($names as $index => $name) {
            self::assertSame(MediaArticleFetcher::isSafeWitnessNamePhp($name), ($out[MediaArticleFetcher::W52_APPEND_HEADER + $index] & 2) !== 0, $name);
        }
    }

    public function test_middle_volume_parity(): void
    {
        $this->requireBindings();
        $this->assertMiddleParity([], [], 0);
        $this->assertMiddleParity(['only.rar'], [3], 3);
        $this->assertMiddleParity(['only.rar'], [3], 4);
        $titles = ['set.part10.rar', 'set.part2.rar', 'SET.part1.rar', 'head.rar', 'set.part3.rar', 'set.part02.rar', 'set.part 3.rar', 'Set.Part3.rar', "nul\0.rar", ''];
        foreach ([1, 7, -1, 99] as $head) {
            $this->assertMiddleParity($titles, [7, 8, 9, 1, 10, 11, 12, 13, 14, 15], $head);
            $this->assertMiddleParity($titles, [7, 8], $head);
        }
        $this->assertMiddleParity(['x' => 'b.r01', 'y' => 'a.r00', 'z' => 'c.r02'], ['p' => 0, 'q' => 1, 'r' => 2], 5);

        $out = MediaSourceStage::middleVolumeBatchPhp(\array_slice($titles, 0, 5), [7, 8, 9, 1, 10], 1);
        self::assertSame([5, 4, 4], \array_slice($out, 0, 3));
        self::assertSame([4, 2, 1, 0, 3], \array_slice($out, MediaSourceStage::W52_MIDDLE_HEADER));
        // The chosen candidate is the one middleVolumeIndexPhp() picks among the kept titles.
        self::assertSame(3, MediaSourceStage::middleVolumeIndexPhp(['set.part10.rar', 'set.part2.rar', 'SET.part1.rar', 'set.part3.rar']));
    }

    public function test_tool_driver_configuration_parity(): void
    {
        $this->requireBindings();
        $this->assertDriverParity([], [], []);
        $this->assertDriverParity([60], [false], [false]);
        $rows = [[60, '/usr/bin/ffmpeg', '/usr/bin/ffprobe'], [60, false, '/usr/bin/ffprobe'], [60, '', ''], [60, '/usr/bin/ffmpeg', '/usr/bin/ffprobe'],
            [30, '/usr/bin/ffmpeg', '/usr/bin/ffprobe'], [60, '/usr/bin/ffmpeg', false], [60, '0', '0'], [60, "nul\0bin", 'p'], [60, 'nul', "\0binp"],
            [PHP_INT_MIN, 'a', 'b'], [PHP_INT_MAX, 'ab', ''], [PHP_INT_MAX, 'a', 'b'], [60, '', '/usr/bin/ffprobe']];
        $timeouts = array_column($rows, 0);
        $ffmpeg = array_column($rows, 1);
        $ffprobe = array_column($rows, 2);
        $this->assertDriverParity($timeouts, $ffmpeg, $ffprobe);
        $this->assertDriverParity($timeouts, \array_slice($ffmpeg, 0, 4), []);
        $this->assertDriverParity(['a' => 1, 'b' => 1], ['c' => 'x', 'd' => 'x'], ['e' => false, 'f' => '']);

        $out = MediaToolFactory::driverConfigurationBatchPhp($timeouts, $ffmpeg, $ffprobe);
        $header = MediaToolFactory::W52_DRIVER_HEADER;
        // Each mask is the set of keys driverConfigurationPhp() fills.
        foreach ($rows as $index => [$timeout, $binary, $probe]) {
            $configuration = MediaToolFactory::driverConfigurationPhp($timeout, $binary, $probe);
            $mask = (isset($configuration['ffmpeg.binaries']) ? 1 : 0) | (isset($configuration['ffprobe.binaries']) ? 2 : 0);
            self::assertSame($mask, $out[$header + $index] & 3, (string) $index);
            $leader = $out[$header + $index] >> 2;
            self::assertSame($configuration, MediaToolFactory::driverConfigurationPhp(...$rows[$leader]), (string) $index);
        }
        // "nul\0bin" + "p" and "nul" + "\0binp" concatenate alike but differ.
        self::assertSame(8, $out[$header + 8] >> 2);
        self::assertSame(0, $out[$header + 3] >> 2);
    }

    public function test_thumbnail_window_parity(): void
    {
        $this->requireBindings();
        $this->assertWindowParity([], [], [], [], [], [], 3);
        $positions = [1, 2, 3, 0, 7, 1, 1, 3, 1, 0, 1, -1];
        $durations = [100.0, 50.0, -1.0, 40.0, NAN, 7200.25, INF, 0.0, 1e9, 1e9 + 1, -0.0, 0.003];
        $bitRates = [0, 0, 1_000_000, 0, 5, 8_000_000, 4_000_000, 0, PHP_INT_MAX, 3, PHP_INT_MIN, 1];
        $hinted = [500, 0, 0, 0, 9, 2_000_000_000, 700_000_000, 5, 1, 0, 1, 1];
        $real = [1000, 0, 0, 0, 1, 4_000_000_000, 0, 0, 3, 0, 1, 1];
        $files = [0, 0, 2_000_000, 9, 1, 0, 0, 0, PHP_INT_MAX, 7, 0, 0];
        foreach ([3, 1, 0, -5, 7, PHP_INT_MAX] as $attempts) {
            $this->assertWindowParity($positions, $durations, $bitRates, $hinted, $real, $files, $attempts);
        }
        $this->assertWindowParity($positions, [], [], [], [], [], 3);

        // Rows carry windowPhp()'s rounded window, in milliseconds.
        $out = ThumbnailFrameSelector::windowBatchPhp([1, 3], [100.0, 40.0], [0, 0], [500, 0], [1000, 0], [0, 0], 3);
        $rows = \array_slice($out, ThumbnailFrameSelector::W52_WINDOW_HEADER);
        self::assertSame([10_625 << 2 | 1, 10_625, 10_000 << 2 | 1, 10_000], $rows);
        self::assertSame([2, 0, 2, 0, 10_625], \array_slice($out, 0, 5));
    }

    /** @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}> */
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
        $php = $class::withW52RustFfi(false, $call);
        $rust = $class::withW52RustFfi(true, $call);
        self::assertSame($php, $rust, $class);
        // Realistic fixtures stay on the Rust path rather than falling back to PHP.
        $native = self::WAVE[$class].'Native';
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        self::assertSame($php, $class::$native(...$args), $class);
        $php = self::WAVE[$class].'Php';
        self::assertSame($class::$php(...$args), $rust, $class);
        [$header, $perRecord] = self::LAYOUT[$class];
        self::assertCount($header + $perRecord * $count, $rust);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(526));
        $alphabet = 'abcdefABCDEF0123456789 ._-/\\"[]()partrz7ipyEncof';
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
        for ($round = 0; $round < 80; $round++) {
            $count = $random->getInt(0, 24);
            $subjects = $names = $lengths = $offsets = $titles = $indexes = $timeouts = $ffmpeg = $ffprobe = [];
            $positions = $durations = $bitRates = $hinted = $real = $files = [];
            $offset = 0;
            for ($i = 0; $i < $count; $i++) {
                $subjects[] = $random->getInt(0, 3) === 0 ? '"'.$text(30).'" yEnc ('.$random->getInt(1, 9).'/9)' : $text(50);
                $names[] = $text(20);
                $lengths[] = $random->getInt(0, 9) === 0 ? $int() : $random->getInt(0, 1000);
                $offsets[] = match ($random->getInt(0, 6)) {
                    0 => -1,
                    1 => $int(),
                    default => $offset,
                };
                $offset += min(max(0, $lengths[$i]), (1 << 40) - 1);
                $titles[] = $text(20);
                $indexes[] = $random->getInt(0, 4);
                $timeouts[] = $random->getInt(0, 3) === 0 ? $int() : [30, 60][$random->getInt(0, 1)];
                $ffmpeg[] = $random->getInt(0, 3) === 0 ? false : ['', '/usr/bin/ffmpeg', $text(6)][$random->getInt(0, 2)];
                $ffprobe[] = $random->getInt(0, 3) === 0 ? false : ['', '/usr/bin/ffprobe', $text(6)][$random->getInt(0, 2)];
                $positions[] = $random->getInt(-1, 4);
                $durations[] = match ($random->getInt(0, 5)) {
                    0 => -1.0, 1 => NAN, 2 => (float) $random->getInt(0, 20_000) / 7.0,
                    default => $random->getFloat(0.0, 10_000.0),
                };
                $bitRates[] = $random->getInt(0, 4) === 0 ? $int() : $random->getInt(0, 20_000_000);
                $hinted[] = $random->getInt(0, 4) === 0 ? $int() : $random->getInt(0, 50_000_000);
                $real[] = $random->getInt(0, 4) === 0 ? $int() : $random->getInt(0, 90_000_000);
                $files[] = $random->getInt(0, 4) === 0 ? $int() : $random->getInt(0, 50_000_000);
            }
            $this->assertArchiveParity($subjects);
            $this->assertAppendParity($lengths, $offsets, $names, $random->getInt(-2, 6), $random->getInt(0, 3) === 0 ? null : [0, 0, $int()][$random->getInt(0, 2)], $random->getInt(0, 4) === 0 ? $int() : $random->getInt(0, 5000));
            $this->assertMiddleParity($titles, $indexes, $random->getInt(-1, 4));
            $this->assertDriverParity($timeouts, $ffmpeg, $ffprobe);
            $this->assertWindowParity($positions, $durations, $bitRates, $hinted, $real, $files, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(1, 12));
        }
    }

    /**
     * Protocol (set NNTMUX_W526_BENCH=1): seed 526, 8 fixture variants, 50 warmups and
     * NNTMUX_W526_BENCH_PAIRS (default 1,000, at least 100) randomized-order pairs per
     * class/workload. Common batches hold 64 typical records; uncommon ones 256 long, mixed-case,
     * truncated, invalid or extreme records. Fixtures are built outside timing and parity is
     * asserted on every pair. Timings cover public dispatch, marshaling, FFI and unpacking.
     * Nearest-rank p99. The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W526_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W526_BENCH=1 to run the paired Wave 52.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(100, (int) (getenv('NNTMUX_W526_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(526));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 256 : 64, $uncommon, 526 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW52RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 526, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w526-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW52.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertSame($eligible, $class::usesW52RustFfi(), $class.' must default to Rust exactly when it clears >=1.5x mean/p99 on both workloads');
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
            ArchiveCandidate::class => [$this->subjectBatch($random, $count, $uncommon)],
            MediaArticleFetcher::class => $this->articleBatch($random, $count, $uncommon),
            MediaSourceStage::class => $this->volumeBatch($random, $count, $uncommon),
            MediaToolFactory::class => $this->driverBatch($random, $count, $uncommon),
            default => $this->windowBatch($random, $count, $uncommon),
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
     * NZB subjects of a few releases' archive volumes, par2 and nfo files; uncommon ones are long,
     * mixed-case, unquoted with poster prefixes, split, hashed or malformed.
     *
     * @return list<string>
     */
    private function subjectBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $titles = ['Movie.Title.2024.1080p.BluRay.x264-GRP', 'Some_Show_S01E02_720p', 'Album - Artist (2019) [FLAC]', 'Game.of.Things.2160p', 'Doc.Series'];
        $subjects = [];
        for ($i = 0; $i < $count; $i++) {
            $title = $titles[$random->getInt(0, \count($titles) - 1)];
            $volume = $random->getInt(1, 60);
            $file = match ($random->getInt(0, $uncommon ? 9 : 5)) {
                0, 1 => sprintf('%s.part%02d.rar', $title, $volume),
                2 => sprintf('%s.r%02d', $title, $volume),
                3 => $title.'.rar',
                4 => $title.($random->getInt(0, 1) === 0 ? '.par2' : '.vol'.$volume.'+'.$volume.'.par2'),
                5 => $title.'.nfo',
                6 => sprintf('%s.7z.%03d', strtoupper($title), $volume),
                7 => sprintf('%s.mkv.%03d', $title, $volume),
                8 => bin2hex($random->getBytes(16)).'.'.$random->getInt(10, 99),
                default => sprintf('%s.Z%02d', str_repeat($title.'.', $random->getInt(2, 6)), $volume),
            };
            $subjects[] = match ($uncommon ? $random->getInt(0, 5) : $random->getInt(0, 1)) {
                0 => sprintf('[%02d/%02d] - "%s" yEnc (%d/%d)', $i % 99 + 1, $count, $file, $random->getInt(1, 50), 50),
                1 => sprintf('"%s" yEnc (1/%d)', $file, $random->getInt(1, 90)),
                2 => sprintf('Poster Name - [%02d/%02d] - %s yEnc (%d/50)', $i % 99 + 1, $count, $file, $random->getInt(1, 50)),
                3 => sprintf('%s - (%d of %d) - yEnc', $file, $random->getInt(1, 9), 9),
                4 => sprintf("\"%s\r\n\" %s yEnc", $title, $file),
                default => $random->getInt(0, 4) === 0 ? '' : sprintf('- %s -', $file),
            };
        }

        return $subjects;
    }

    /**
     * Articles of a posted video: common batches are contiguous with a 20 MiB byte request;
     * uncommon ones start mid-file and hold cached copies, gaps and odd names.
     *
     * @return array{0: list<int>, 1: list<int>, 2: list<string>, 3: int, 4: int|null, 5: int}
     */
    private function articleBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $name = ['Movie.Title.2024.1080p.mkv', 'show.s01e02.mp4', 'Clip.avi'][$random->getInt(0, 2)];
        $lengths = $offsets = $names = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = $i === $count - 1 ? $random->getInt(1, self::ARTICLE) : self::ARTICLE;
            $lengths[] = $length;
            $offsets[] = $uncommon && $random->getInt(0, 40) === 0 ? [-1, $offset + 1, 0][$random->getInt(0, 2)] : $offset;
            $offset += $length;
            $names[] = $uncommon && $random->getInt(0, 7) === 0
                ? ['', '.'.$name, 'dir/'.$name, "bad\xff".$name, "tab\t".$name, '-'.$name, str_repeat('Long.Name.', 40).$name][$random->getInt(0, 6)]
                : $name;
        }
        if ($uncommon) {
            return [$lengths, $offsets, $names, $random->getInt(-1, 3), [null, 0, $offsets[0] ?? 0][$random->getInt(0, 2)], $random->getInt(1, 400) * self::ARTICLE];
        }

        return [$lengths, $offsets, $names, 0, 0, 20 * 1_048_576];
    }

    /**
     * Volume titles of one archive set, shuffled, with the head volume's source among them;
     * uncommon ones mix naming schemes, case, padding and spacing.
     *
     * @return array{0: list<string>, 1: list<int>, 2: int}
     */
    private function volumeBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $title = ['Movie.Title.2024.1080p.BluRay.x264-GRP', 'Some_Show_S01E02_720p', 'Game.of.Things.2160p'][$random->getInt(0, 2)];
        $titles = $indexes = [];
        foreach ($random->shuffleArray(range(0, $count - 1)) as $volume) {
            $titles[] = match ($uncommon ? $random->getInt(0, 6) : 0) {
                0 => sprintf('%s.part%03d.rar', $title, $volume + 1),
                1 => sprintf('%s.r%02d', strtoupper($title), $volume),
                2 => sprintf('%s.part%d.rar', $title, $volume + 1),
                3 => sprintf('[%d/%d] - "%s.part%03d.rar" yEnc', $volume + 1, $count, $title, $volume + 1),
                4 => sprintf('%s part %d.rar', strtolower($title), $volume + 1),
                5 => sprintf('%s.%03d', $title, $volume + 1),
                default => sprintf('%s.Part%04d.RAR', $title, $volume * 7),
            };
            $indexes[] = $volume + 10;
        }

        return [$titles, $indexes, 10 + $random->getInt(0, max(0, $count - 1))];
    }

    /**
     * Media tool configurations of a worker fleet: common batches share a few configured
     * binaries; uncommon ones mix unset, empty, long, NUL-holding and many distinct paths.
     *
     * @return array{0: list<int>, 1: list<string|false>, 2: list<string|false>}
     */
    private function driverBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $binaries = [['/usr/bin/ffmpeg', '/usr/bin/ffprobe'], ['/usr/local/bin/ffmpeg', '/usr/local/bin/ffprobe'], ['/opt/homebrew/bin/ffmpeg', '/opt/homebrew/bin/ffprobe'], [false, false]];
        $timeouts = $ffmpeg = $ffprobe = [];
        for ($i = 0; $i < $count; $i++) {
            [$binary, $probe] = $binaries[$random->getInt(0, \count($binaries) - 1)];
            if ($uncommon && $random->getInt(0, 2) === 0) {
                $binary = ['', false, '/srv/'.bin2hex($random->getBytes(8)).'/ffmpeg', "/tmp/nul\0ffmpeg", str_repeat('/very/long/path', 20).'/ffmpeg'][$random->getInt(0, 4)];
                $probe = ['', false, '/srv/'.bin2hex($random->getBytes(8)).'/ffprobe', $binary][$random->getInt(0, 3)];
            }
            $timeouts[] = $uncommon ? [60, 120, 1800, 0, -1, PHP_INT_MAX][$random->getInt(0, 5)] : [60, 120][$random->getInt(0, 1)];
            $ffmpeg[] = $binary;
            $ffprobe[] = $probe;
        }

        return [$timeouts, $ffmpeg, $ffprobe];
    }

    /**
     * Probed partial videos: common batches are heads with known durations and real sizes;
     * uncommon ones mix positions, unknown durations, bit-rate estimates and extreme sizes.
     *
     * @return array{0: list<int>, 1: list<float>, 2: list<int>, 3: list<int>, 4: list<int>, 5: list<int>, 6: int}
     */
    private function windowBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $positions = $durations = $bitRates = $hinted = $real = $files = [];
        for ($i = 0; $i < $count; $i++) {
            $realSize = $random->getInt(200_000_000, 9_000_000_000);
            $available = $random->getInt(1_000_000, 40_000_000);
            $positions[] = $uncommon ? $random->getInt(0, 3) : ($random->getInt(0, 9) === 0 ? 3 : 1);
            $durations[] = $uncommon && $random->getInt(0, 2) === 0 ? [-1.0, NAN, INF, 0.0, 2e9][$random->getInt(0, 4)] : $random->getInt(60_000, 10_800_000) / 1000.0;
            $bitRates[] = $uncommon && $random->getInt(0, 3) === 0 ? [0, -1, PHP_INT_MAX, 1][$random->getInt(0, 3)] : $random->getInt(500_000, 40_000_000);
            $hinted[] = $uncommon && $random->getInt(0, 4) === 0 ? [0, -5, PHP_INT_MAX][$random->getInt(0, 2)] : $available;
            $real[] = $uncommon && $random->getInt(0, 4) === 0 ? [0, -1, 1, $available][$random->getInt(0, 3)] : $realSize;
            $files[] = $uncommon && $random->getInt(0, 4) === 0 ? $random->getInt(0, PHP_INT_MAX) : $available;
        }

        return [$positions, $durations, $bitRates, $hinted, $real, $files, $uncommon ? $random->getInt(-1, 40) : 10];
    }

    /** @param array<array-key, string> $subjects */
    private function assertArchiveParity(array $subjects): void
    {
        $php = ArchiveCandidate::withW52RustFfi(false, static fn (): array => ArchiveCandidate::volumeSetGroupBatch($subjects));
        $rust = ArchiveCandidate::withW52RustFfi(true, static fn (): array => ArchiveCandidate::volumeSetGroupBatch($subjects));
        self::assertSame($php, ArchiveCandidate::volumeSetGroupBatchPhp($subjects));
        $native = ArchiveCandidate::volumeSetGroupBatchNative($subjects);
        if ($native !== null) {
            self::assertSame($php, $native, json_encode(array_map(bin2hex(...), array_values($subjects)), JSON_THROW_ON_ERROR));
        }
        self::assertSame($php, $rust);
        self::assertCount(ArchiveCandidate::W52_ARCHIVE_HEADER + \count($subjects), $php);
    }

    /**
     * @param  array<array-key, int>  $lengths
     * @param  array<array-key, int>  $offsets
     * @param  array<array-key, string>  $names
     */
    private function assertAppendParity(array $lengths, array $offsets, array $names, int $from, ?int $expected, int $maxBytes): void
    {
        $php = MediaArticleFetcher::withW52RustFfi(false, static fn (): array => MediaArticleFetcher::planAppendBatch($lengths, $offsets, $names, $from, $expected, $maxBytes));
        $rust = MediaArticleFetcher::withW52RustFfi(true, static fn (): array => MediaArticleFetcher::planAppendBatch($lengths, $offsets, $names, $from, $expected, $maxBytes));
        self::assertSame($php, MediaArticleFetcher::planAppendBatchPhp($lengths, $offsets, $names, $from, $expected, $maxBytes));
        self::assertSame($php, MediaArticleFetcher::planAppendBatchNative($lengths, $offsets, $names, $from, $expected, $maxBytes));
        self::assertSame($php, $rust);
        self::assertCount(MediaArticleFetcher::W52_APPEND_HEADER + \count($lengths), $php);
    }

    /**
     * @param  array<array-key, string>  $titles
     * @param  array<array-key, int>  $indexes
     */
    private function assertMiddleParity(array $titles, array $indexes, int $head): void
    {
        $php = MediaSourceStage::withW52RustFfi(false, static fn (): array => MediaSourceStage::middleVolumeBatch($titles, $indexes, $head));
        $rust = MediaSourceStage::withW52RustFfi(true, static fn (): array => MediaSourceStage::middleVolumeBatch($titles, $indexes, $head));
        self::assertSame($php, MediaSourceStage::middleVolumeBatchPhp($titles, $indexes, $head));
        $native = MediaSourceStage::middleVolumeBatchNative($titles, $indexes, $head);
        if ($native !== null) {
            self::assertSame($php, $native, json_encode(array_map(bin2hex(...), array_values($titles)), JSON_THROW_ON_ERROR));
        }
        self::assertSame($php, $rust);
        self::assertCount(MediaSourceStage::W52_MIDDLE_HEADER + \count($titles), $php);
    }

    /**
     * @param  array<array-key, int>  $timeouts
     * @param  array<array-key, string|false>  $ffmpeg
     * @param  array<array-key, string|false>  $ffprobe
     */
    private function assertDriverParity(array $timeouts, array $ffmpeg, array $ffprobe): void
    {
        $php = MediaToolFactory::withW52RustFfi(false, static fn (): array => MediaToolFactory::driverConfigurationBatch($timeouts, $ffmpeg, $ffprobe));
        $rust = MediaToolFactory::withW52RustFfi(true, static fn (): array => MediaToolFactory::driverConfigurationBatch($timeouts, $ffmpeg, $ffprobe));
        self::assertSame($php, MediaToolFactory::driverConfigurationBatchPhp($timeouts, $ffmpeg, $ffprobe));
        self::assertSame($php, MediaToolFactory::driverConfigurationBatchNative($timeouts, $ffmpeg, $ffprobe));
        self::assertSame($php, $rust);
        self::assertCount(MediaToolFactory::W52_DRIVER_HEADER + \count($timeouts), $php);
    }

    /**
     * @param  array<array-key, int>  $positions
     * @param  array<array-key, float>  $durations
     * @param  array<array-key, int>  $bitRates
     * @param  array<array-key, int>  $hinted
     * @param  array<array-key, int>  $real
     * @param  array<array-key, int>  $files
     */
    private function assertWindowParity(array $positions, array $durations, array $bitRates, array $hinted, array $real, array $files, int $attempts): void
    {
        $args = [$positions, $durations, $bitRates, $hinted, $real, $files, $attempts];
        $php = ThumbnailFrameSelector::withW52RustFfi(false, static fn (): array => ThumbnailFrameSelector::windowBatch(...$args));
        $rust = ThumbnailFrameSelector::withW52RustFfi(true, static fn (): array => ThumbnailFrameSelector::windowBatch(...$args));
        self::assertSame($php, ThumbnailFrameSelector::windowBatchPhp(...$args));
        self::assertSame($php, ThumbnailFrameSelector::windowBatchNative(...$args));
        self::assertSame($php, $rust);
        self::assertCount(ThumbnailFrameSelector::W52_WINDOW_HEADER + 2 * \count($positions), $php);
    }

    private function requireBindings(): void
    {
        if (ArchiveCandidate::volumeSetGroupBatchNative([]) === null
            || MediaArticleFetcher::planAppendBatchNative([], [], [], 0, null, 0) === null
            || MediaSourceStage::middleVolumeBatchNative([], [], 0) === null
            || MediaToolFactory::driverConfigurationBatchNative([], [], []) === null
            || ThumbnailFrameSelector::windowBatchNative([], [], [], [], [], [], 1) === null
        ) {
            self::markTestSkipped('The Wave 52.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
