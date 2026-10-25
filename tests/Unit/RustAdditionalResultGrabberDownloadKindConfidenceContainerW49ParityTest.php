<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\ReleaseProcessingResult;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\FfmpegFrameGrabber;
use Closure;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustAdditionalResultGrabberDownloadKindConfidenceContainerW49ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        ReleaseProcessingResult::class => 'summarizeResultBatch',
        FfmpegFrameGrabber::class => 'parseClockBatch',
        DownloadKind::class => 'accountDownloadBatch',
        MappingConfidence::class => 'rankMappingBatch',
        VideoContainer::class => 'classifyContainerBatch',
    ];

    /**
     * Apple M5/macOS arm64 (shared host, load ~9), PHP 8.5.12 CLI without opcache/JIT, release
     * Rust; base df0c038a9 plus this change, seed 496, 50 warmups and 3,000 pairs/workload
     * (w496-benchmark-ac32aae14bbf9d67.json in system tmp; three earlier 1,000-pair runs on the
     * final marshaling stayed at or above 1.69x everywhere). Common mean/p99 then uncommon
     * mean/p99 speedups (PHP time / Rust time); a default is Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ReleaseProcessingResult::class => [3.982, 2.405, 4.133, 3.078],
        FfmpegFrameGrabber::class => [4.829, 2.672, 3.314, 2.260],
        DownloadKind::class => [2.555, 1.655, 2.711, 1.910],
        MappingConfidence::class => [3.003, 2.146, 3.512, 2.317],
        VideoContainer::class => [2.700, 1.938, 9.882, 8.599],
    ];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW49RustFfi', 'withW49RustFfi', 'w49RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            self::assertTrue($reflection->getMethod('w49RustFfiState')->isPrivate(), $class);
            self::assertTrue($reflection->hasConstant('W49_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W49_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w49_', (string) $reflection->getConstant('W49_FFI_CDEF'), $class);
        }
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (self::WAVE as $class => $method) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W49_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW49RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW49RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW49RustFfi(! $enabled, static fn (): bool => $class::usesW49RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (self::WAVE as $other => $otherMethod) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W49_RUST_FFI_DEFAULT'), $other::usesW49RustFfi());
                        }
                    }

                    return $class::usesW49RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW49RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW49RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW49RustFfi(), $class);
        }
        // The Wave 49 switches leave the existing toggles alone.
        self::assertFalse(FfmpegFrameGrabber::usesRustFfi());
        self::assertFalse(DownloadKind::usesRustFfi());
        self::assertFalse(MappingConfidence::usesRustFfi());
    }

    public function test_release_result_summary_parity(): void
    {
        $this->requireBindings();
        $this->assertResultParity([], [], [], [], [], 0);
        $this->assertResultParity([''], [], [], [], [], 1);
        foreach ([[64, false, 1], [128, true, 2], [256, true, 3], [64, false, 4]] as [$count, $uncommon, $seed]) {
            $this->assertResultParity(...$this->resultBatch($count, $uncommon, $seed));
        }
        $values = array_map(static fn (ProcessingOutcome $case): string => $case->value, ProcessingOutcome::cases());
        $outcomes = [...$values, 'COMPLETED', ' completed', "comp\0leted", '0', '12', "\xff", ''];
        $extremes = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1, (1 << 40) - 1, 1 << 40, 999, 1_000_000, 7];
        foreach ([0, 1, -1, 999, PHP_INT_MAX, PHP_INT_MIN] as $slow) {
            $this->assertResultParity($outcomes, $extremes, array_reverse($extremes), $extremes, $extremes, $slow);
            $this->assertResultParity($outcomes, [], $extremes, [5 => 1], ['a' => 9, 'b' => 8], $slow);
        }
        $this->assertResultParity(['b' => 'failed', 'a' => 'completed'], ['x' => 10, 'y' => 20, 'z' => 30], [9 => 5], [], [], 15);
        $this->assertResultParity(array_fill(0, 40, 'completed'), array_fill(0, 40, PHP_INT_MAX), array_fill(0, 40, 0), array_fill(0, 40, PHP_INT_MAX), [], 1);

        $summary = ReleaseProcessingResult::summarizeResultBatchPhp(['completed', 'failed', 'passworded', 'bogus'], [100, 300, 2_000, -7], [50, 400, 1_000, 0], [1, 0, 5, 2], [0, 3, 0, PHP_INT_MAX], 300);
        self::assertSame([4, 1, 0, 1], array_slice($summary, 0, 4));
        self::assertSame([1, 1, 2, 2, 2_400, 1_450, 1_050, 8, 3 + (1 << 40) - 1, 2_000, 2, 100, 2_000, 2_000, 500_000], array_slice($summary, 12, 15));
        self::assertSame([1 << 4 | 50 << 8, 11 | 1 << 5, 2 | 1 << 4 | 1 << 5 | 1_000 << 8, 12], array_slice($summary, ReleaseProcessingResult::W49_RESULT_HEADER));
        // Every ProcessingOutcome case maps to its declaration index and success matches the enum.
        $rows = array_slice(ReleaseProcessingResult::summarizeResultBatchNative($values, [], [], [], []) ?? [], ReleaseProcessingResult::W49_RESULT_HEADER);
        foreach (ProcessingOutcome::cases() as $index => $case) {
            self::assertSame($index | ($case->isSuccessfulPhp() ? 1 << 4 : 0), $rows[$index], $case->value);
        }
    }

    public function test_ffmpeg_clock_batch_parity(): void
    {
        $this->requireBindings();
        $this->assertClockParity([]);
        $this->assertClockParity(['']);
        foreach ([[64, false, 5], [128, true, 6], [256, true, 7]] as [$count, $uncommon, $seed]) {
            $this->assertClockParity($this->clockBatch($count, $uncommon, $seed));
        }
        $this->assertClockParity(['01:02:03.5', '00:00:01.0000000019', '00:00:00.000000000', "0000000000002:00:00\n", '2000000:99:99', '2000001:00:00',
            '12345678:00:00', '0000001234567:00:00', '1:2:03', '01:02:03.', "01:02:03\n\n", ':00:00', '01:02:03 ', ' 01:02:03', '01:02:3a', '01:02:03.5x', "01\0:02:03",
            "00:00:01\0", '٣:00:00', '00:60:61', '99:99:99.999999999999', '0:00:00.000000001', 1, 1.5, true, null, ['01:00:00'], new class
            {
                public function __toString(): string
                {
                    return '01:00:00';
                }
            }, '2000000:00:00', '2000000:00:00', '2000000:00:00']);
        $this->assertClockParity(['b' => '00:00:02', 'a' => '00:00:01', 7 => 'x']);

        $clock = FfmpegFrameGrabber::parseClockBatchPhp(['00:00:02', 'x', '00:00:01.25', 5]);
        self::assertSame([4, 2, 2, 3_250_000_000, 2_000_000_000, 0, 1_250_000_000], array_slice($clock, 0, 7));
        self::assertSame([2_000_000_000, 0, 1_250_000_000, 0], array_slice($clock, FfmpegFrameGrabber::W49_CLOCK_HEADER));
        // The batch agrees with the existing single-value parser to the microsecond.
        foreach (['00:42:17.123456789', '01:00:00', '00:00:00.5', 'bad'] as $value) {
            $nanos = FfmpegFrameGrabber::parseClockBatch([$value])[FfmpegFrameGrabber::W49_CLOCK_HEADER];
            $seconds = FfmpegFrameGrabber::clockSecondsPhp($value);
            self::assertSame($seconds === null ? 0 : (int) round($seconds * 1_000_000), intdiv($nanos + 500, 1_000));
        }
    }

    public function test_download_kind_account_parity(): void
    {
        $this->requireBindings();
        $this->assertDownloadParity([], [], 0);
        $this->assertDownloadParity(['jpg'], [], 0);
        foreach ([[64, false, 8], [128, true, 9], [256, true, 10]] as [$count, $uncommon, $seed]) {
            $this->assertDownloadParity(...$this->downloadBatch($count, $uncommon, $seed));
        }
        $kinds = [...array_map(static fn (DownloadKind $case): string => $case->value, DownloadKind::cases()), 'JPG', ' jpg', "jpg\0", '', '3', 'media_source'];
        $sizes = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1, (1 << 40) - 1, 1 << 40, 100, 50, 25, 10, 5, 1];
        foreach ([0, 1, 50, 100, 151, PHP_INT_MAX, PHP_INT_MIN, -1, 1 << 62, (1 << 62) + 1] as $budget) {
            $this->assertDownloadParity($kinds, $sizes, $budget);
            $this->assertDownloadParity(array_reverse($kinds), [3 => 1], $budget);
        }
        $this->assertDownloadParity(['b' => 'jpg', 'a' => 'audio'], ['x' => 7, 'y' => 8, 'z' => 9], 7);

        $account = DownloadKind::accountDownloadBatchPhp(['jpg', 'sample', 'media-source', 'par2-index', 'Jpg'], [60, 500, 50, 40, -1], 100);
        self::assertSame([5, 1, 0, 0, 1, 0, 1, 1, 1, 500, 0, 0, 60, 0, 40, 50, 0, 3, 100, 1, 50, 2, 0], array_slice($account, 0, 23));
        self::assertSame([3 | 1 << 4 | 1 << 5 | 60 << 8, 1 << 5 | 500 << 8, 6 | 1 << 4 | 50 << 8, 5 | 1 << 4 | 1 << 5 | 40 << 8, 7 | 1 << 5],
            array_slice($account, DownloadKind::W49_DOWNLOAD_HEADER));
        // The preview bit matches the enum's own classification.
        $rows = array_slice(DownloadKind::accountDownloadBatchNative(array_map(static fn (DownloadKind $case): string => $case->value, DownloadKind::cases()), [], 0) ?? [], DownloadKind::W49_DOWNLOAD_HEADER);
        foreach (DownloadKind::cases() as $index => $case) {
            self::assertSame($case->isPreviewSourcePhp(), ($rows[$index] >> 4 & 1) === 1, $case->value);
        }
    }

    public function test_mapping_confidence_rank_parity(): void
    {
        $this->requireBindings();
        $this->assertMappingParity([], []);
        $this->assertMappingParity([''], []);
        foreach ([[64, false, 11], [128, true, 12], [256, true, 13]] as [$count, $uncommon, $seed]) {
            $this->assertMappingParity(...$this->mappingBatch($count, $uncommon, $seed));
        }
        $values = ['verified', 'unique-size', 'VERIFIED', 'Unique_Size', " \t\n\r\0\x0Bverified\x0B\0", 'unique size', 'veri_fied', 'unique--size', 'UNIQUE_SIZE ', "\xa0verified",
            'verifiedx', 'uniquesize', 'ÜNIQUE-SIZE', "verified\0x", '', ' '];
        $this->assertMappingParity($values, [PHP_INT_MAX, PHP_INT_MIN, -1, 0, 1, (1 << 40) - 1, 1 << 40]);
        $this->assertMappingParity(array_fill(0, 30, 'verified'), array_fill(0, 30, PHP_INT_MAX));
        $this->assertMappingParity(['b' => 'verified', 'a' => 'x'], ['q' => 4]);

        $rank = MappingConfidence::rankMappingBatchPhp(['verified', 'unique-size', 'nope'], [10, 20, 30]);
        self::assertSame([3, 1, 1, 1, 10, 20, 30, 2, 0, 0, 500_000], array_slice($rank, 0, 11));
        self::assertSame([2 << 4 | 10 << 8, 1 | 1 << 4 | 20 << 8, 2 | 30 << 8], array_slice($rank, MappingConfidence::W49_MAPPING_HEADER));
        foreach ($values as $value) {
            $case = MappingConfidence::tryFromNormalizedPhp($value);
            $row = MappingConfidence::rankMappingBatch([$value], [])[MappingConfidence::W49_MAPPING_HEADER];
            self::assertSame($case === null ? 2 : array_search($case, MappingConfidence::cases(), true), $row & 0xF, bin2hex($value));
            self::assertSame($case?->rankPhp() ?? 0, $row >> 4 & 0xF);
        }
    }

    public function test_video_container_classify_parity(): void
    {
        $this->requireBindings();
        $this->assertContainerParity([], []);
        $this->assertContainerParity([''], []);
        foreach ([[64, false, 14], [128, true, 15], [256, true, 16]] as [$count, $uncommon, $seed]) {
            $this->assertContainerParity(...$this->containerBatch($count, $uncommon, $seed));
        }
        $names = ['Movie.2024.mkv', '"Show.S01E01.ts" yEnc (1/50)', '[1/9] - "Show.mp4" yEnc', "a.MKV\x0Bb.avi", 'a.nfo b.mkv', 'a.mkvx b.avi', 'a.mk3d"x', 'x.', '.qt', "\t x.Ogm \n",
            'x.ogv""', 'x.webm"', "x.m2ts\"\x0C", 'x.mpeg2', "nul\0.avi", 'X.WMV', 'a.b.c.divx', 'a..mpv', 'a.m2v.', 'a.vob.', "a.ts\xa0", 'a.ts'.str_repeat(' ', 3).'z', ''];
        $heads = [self::matroska(64), 'RIFF\0\0\0\0AVI LIST', "RIFF\0\0\0\0AVI LIST", "\x00\x00\x01\xBA".str_repeat("\0", 20), "\x00\x00\x01\xB3".str_repeat("\0", 8), "\0\0\0\x18ftypmp42",
            "\0\0\0\x18moov\0\0\0\0", "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\0\0", 'OggS'.str_repeat("\0", 8), self::transport(188, 3, 5_000), self::transport(192, 191, 960), self::transport(192, 191, 959),
            self::transport(188, 187, 940), self::transport(188, 188, 2_000), 'short', '', str_repeat("\x47", 2_000), "\x1A\x45\xDF\xA3", "\x1A\x45\xDF\xA3abcdefgh", "RIFF\0\0\0\0AVI ftyp", str_repeat("\0", 5_000)];
        $this->assertContainerParity($names, $heads);
        $this->assertContainerParity(array_reverse($names), array_slice($heads, 3));
        $this->assertContainerParity(['b' => 'x.mkv', 'a' => 'y.avi'], ['q' => self::matroska(16), 'z' => 'OggS', 'r' => 'extra']);

        $classes = VideoContainer::classifyContainerBatchPhp(['a.mkv', 'b.avi', 'c.mp4', 'd'], [self::matroska(16), 'short', 'OggS'.str_repeat("\0", 8), '']);
        self::assertSame([4, 1, 1, 1, 0, 0, 0, 0, 1, 2, 1, 1, 2], array_slice($classes, 0, 13));
        self::assertSame([1 | 1 << 4 | 1 << 8 | 9 << 12, 2 << 4 | 2 << 8 | 10 << 12, 7 | 5 << 4 | 7 << 8 | 5 << 12, 0], array_slice($classes, VideoContainer::W49_CONTAINER_HEADER));
        // The header code agrees with the existing Rust-backed detector and the name code with fromFileName().
        foreach ($heads as $index => $head) {
            $name = $names[$index];
            $row = VideoContainer::classifyContainerBatch([$name], [$head])[VideoContainer::W49_CONTAINER_HEADER];
            $detected = VideoContainer::detect($head);
            $named = VideoContainer::fromFileName($name);
            self::assertSame($detected === null ? 0 : array_search($detected, VideoContainer::cases(), true) + 1, $row & 0xF, (string) $index);
            self::assertSame($named === null ? 0 : array_search($named, VideoContainer::cases(), true) + 1, $row >> 4 & 0xF, $name);
        }
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(4_960));
        $alphabet = ['', 'a', '.', ':', '0', '9', '_', '-', ' ', "\0", "\t", "\x0B", '"', 'mkv', 'ts', 'verified', 'unique', 'size', 'jpg', 'completed', "\xff"];
        $text = static function () use ($random, $alphabet): string {
            $value = '';
            for ($i = $random->getInt(0, 12); $i > 0; $i--) {
                $value .= $alphabet[$random->getInt(0, \count($alphabet) - 1)];
            }

            return $value;
        };
        for ($round = 0; $round < 60; $round++) {
            $count = $random->getInt(0, 40);
            $strings = $ints = [];
            for ($i = 0; $i < $count; $i++) {
                $strings[] = $text();
                $ints[] = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, $random->getInt(0, 1 << 41)][$random->getInt(0, 4)];
            }
            $this->assertResultParity($strings, $ints, array_reverse($ints), $ints, array_slice($ints, 1), $random->getInt(-5, 1 << 41));
            $this->assertClockParity($strings);
            $this->assertDownloadParity($strings, $ints, $random->getInt(-5, 1 << 42));
            $this->assertMappingParity($strings, $ints);
            $this->assertContainerParity($strings, array_map(static fn (string $value): string => str_repeat($value, $random->getInt(0, 200)), $strings));
        }
        // File names exercise the leftmost-extension scan against `fromFileName()`.
        $tokens = ['.', '.', 'mkv', 'MKV', 'ts', 'm2ts', 'WebM', 'qt', 'abcde', 'ab', 'x', '1', '"', '""', ' ', "\t", "\n", "\r", "\x0B", "\x0C", "\0", '!', '#', "\xa0", "\xff", '-'];
        for ($round = 0; $round < 40; $round++) {
            $names = [];
            for ($i = 0; $i < 100; $i++) {
                $name = '';
                for ($j = $random->getInt(0, 24); $j > 0; $j--) {
                    $name .= $tokens[$random->getInt(0, \count($tokens) - 1)];
                }
                $names[] = $name;
            }
            $this->assertContainerParity($names, []);
            $rows = array_slice(VideoContainer::withW49RustFfi(true, static fn (): array => VideoContainer::classifyContainerBatch($names, [])), VideoContainer::W49_CONTAINER_HEADER);
            foreach ($names as $index => $name) {
                $named = VideoContainer::fromFileName($name);
                self::assertSame($named === null ? 0 : array_search($named, VideoContainer::cases(), true) + 1, $rows[$index] >> 4 & 0xF, bin2hex($name));
            }
        }
    }

    /**
     * Protocol (set NNTMUX_W496_BENCH=1): seed 496, 8 fixture variants, 50 warmups and
     * NNTMUX_W496_BENCH_PAIRS (default 1,000, at least 100) randomized-order pairs per
     * class/workload. Common batches hold 64 typical records; uncommon ones 256 long,
     * mixed-case, truncated, invalid or extreme records. Fixtures are built outside timing and
     * parity is asserted on every pair. Timings cover public dispatch, marshaling, FFI and
     * unpacking. Nearest-rank p99. The summary and raw pairs are written to the system temp
     * directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W496_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W496_BENCH=1 to run the paired Wave 49.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(100, (int) (getenv('NNTMUX_W496_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(496));
        $summary = $records = [];
        foreach (self::WAVE as $class => $method) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon, 496 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW49RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 496, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w496-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW49.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (self::WAVE as $class => $method) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertSame($eligible, $class::usesW49RustFfi(), $class.' default must match >=1.5x mean/p99 on both workloads');
        }
    }

    /** @return Closure(): list<int> */
    private function benchmarkCall(string $class, bool $uncommon, int $seed): Closure
    {
        $count = $uncommon ? 256 : 64;

        return match ($class) {
            ReleaseProcessingResult::class => (function () use ($count, $uncommon, $seed): Closure {
                $batch = $this->resultBatch($count, $uncommon, $seed);

                return static fn (): array => ReleaseProcessingResult::summarizeResultBatch(...$batch);
            })(),
            FfmpegFrameGrabber::class => (function () use ($count, $uncommon, $seed): Closure {
                $values = $this->clockBatch($count, $uncommon, $seed);

                return static fn (): array => FfmpegFrameGrabber::parseClockBatch($values);
            })(),
            DownloadKind::class => (function () use ($count, $uncommon, $seed): Closure {
                [$kinds, $bytes, $budget] = $this->downloadBatch($count, $uncommon, $seed);

                return static fn (): array => DownloadKind::accountDownloadBatch($kinds, $bytes, $budget);
            })(),
            MappingConfidence::class => (function () use ($count, $uncommon, $seed): Closure {
                [$values, $sizes] = $this->mappingBatch($count, $uncommon, $seed);

                return static fn (): array => MappingConfidence::rankMappingBatch($values, $sizes);
            })(),
            default => (function () use ($count, $uncommon, $seed): Closure {
                [$names, $heads] = $this->containerBatch($count, $uncommon, $seed);

                return static fn (): array => VideoContainer::classifyContainerBatch($names, $heads);
            })(),
        };
    }

    /** @return array{0: list<string>, 1: list<int>, 2: list<int>, 3: list<int>, 4: list<int>, 5: int} */
    private function resultBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $values = array_map(static fn (ProcessingOutcome $case): string => $case->value, ProcessingOutcome::cases());
        $common = ['completed', 'completed', 'completed', 'no-useful-artifacts', 'passworded', 'failed', 'timed-out', 'not-found'];
        $outcomes = $elapsed = $stage = $files = $duplicates = [];
        for ($i = 0; $i < $count; $i++) {
            if ($uncommon) {
                $outcomes[] = match ($random->getInt(0, 5)) {
                    0 => strtoupper($values[$random->getInt(0, 11)]),
                    1 => "bad\0".$i,
                    2 => 'unknown-'.$i,
                    default => $values[$random->getInt(0, 11)],
                };
                $elapsed[] = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, $random->getInt(0, 1 << 42)][$random->getInt(0, 4)];
                $stage[] = [PHP_INT_MAX, -5, 0, $random->getInt(0, 1 << 42)][$random->getInt(0, 3)];
                $files[] = [PHP_INT_MAX, -1, $random->getInt(0, 500)][$random->getInt(0, 2)];
                $duplicates[] = [PHP_INT_MIN, 0, $random->getInt(0, 1 << 41)][$random->getInt(0, 2)];
            } else {
                $outcomes[] = $common[$random->getInt(0, 7)];
                $total = $random->getInt(200_000, 90_000_000);
                $elapsed[] = $total;
                $stage[] = $total - $random->getInt(0, intdiv($total, 10));
                $files[] = $random->getInt(0, 40);
                $duplicates[] = $random->getInt(0, 9) === 0 ? $random->getInt(1, 20) : 0;
            }
        }

        return [$outcomes, $elapsed, $stage, $files, $duplicates, $uncommon ? $random->getInt(-1, 1 << 41) : 30_000_000];
    }

    /** @return list<mixed> */
    private function clockBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $clock = sprintf('%02d:%02d:%02d', $random->getInt(0, 3), $random->getInt(0, 59), $random->getInt(0, 59));
            if (! $uncommon) {
                $values[] = $clock.'.'.sprintf('%09d', $random->getInt(0, 999_999_999));

                continue;
            }
            $values[] = match ($random->getInt(0, 11)) {
                0 => $clock,
                1 => $clock.'.'.str_repeat((string) $random->getInt(0, 9), $random->getInt(10, 60)),
                2 => str_repeat('0', $random->getInt(1, 20)).$random->getInt(0, 2_000_100).':00:00',
                3 => $clock."\n",
                4 => $clock.' ',
                5 => 'N/A',
                6 => $random->getInt(0, 1) === 0 ? null : $random->getInt(0, 9_999),
                7 => '00:00:00.000000000',
                8 => $clock."\0".$i,
                9 => str_repeat('9', $random->getInt(8, 30)).':59:59.9',
                10 => '',
                default => $clock.'.'.$random->getInt(0, 999),
            };
        }

        return $values;
    }

    /** @return array{0: list<string>, 1: list<int>, 2: int} */
    private function downloadBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $values = array_map(static fn (DownloadKind $case): string => $case->value, DownloadKind::cases());
        $common = ['media-source', 'media-source', 'par2-index', 'jpg', 'sample', 'media-info', 'audio', 'compressed'];
        $kinds = $bytes = [];
        for ($i = 0; $i < $count; $i++) {
            if ($uncommon) {
                $kinds[] = match ($random->getInt(0, 5)) {
                    0 => strtoupper($values[$random->getInt(0, 6)]),
                    1 => "jpg\0".$i,
                    2 => str_replace('-', '_', $values[$random->getInt(0, 6)]),
                    default => $values[$random->getInt(0, 6)],
                };
                $bytes[] = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, $random->getInt(0, 1 << 42)][$random->getInt(0, 4)];
            } else {
                $kinds[] = $common[$random->getInt(0, 7)];
                $bytes[] = $random->getInt(100_000, 3_000_000);
            }
        }

        return [$kinds, $bytes, $uncommon ? [PHP_INT_MAX, PHP_INT_MIN, 0, $random->getInt(0, 1 << 44)][$random->getInt(0, 3)] : 50_000_000];
    }

    /** @return array{0: list<string>, 1: list<int>} */
    private function mappingBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $values = $sizes = [];
        for ($i = 0; $i < $count; $i++) {
            if ($uncommon) {
                $values[] = match ($random->getInt(0, 7)) {
                    0 => '  VERIFIED'."\t",
                    1 => 'Unique_Size',
                    2 => "\0unique-size\x0B",
                    3 => 'verified-'.$i,
                    4 => str_repeat('x', $random->getInt(0, 40)),
                    5 => "UNIQUE\0SIZE",
                    6 => 'unique-size',
                    default => 'verified',
                };
                $sizes[] = [PHP_INT_MAX, PHP_INT_MIN, -1, 0, $random->getInt(0, 1 << 42)][$random->getInt(0, 4)];
            } else {
                $values[] = $random->getInt(0, 3) === 0 ? 'unique-size' : 'verified';
                $sizes[] = $random->getInt(10_000_000, 60_000_000);
            }
        }

        return [$values, $sizes];
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function containerBatch(int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $extensions = ['mkv', 'mp4', 'avi', 'ts', 'm2ts', 'wmv', 'webm', 'mpg'];
        $names = $heads = [];
        for ($i = 0; $i < $count; $i++) {
            $extension = $extensions[$random->getInt(0, $uncommon ? 7 : 2)];
            $base = sprintf('Movie.Name.%d.1080p.WEB-DL.x264-GRP', $random->getInt(1990, 2026));
            if ($uncommon) {
                $names[] = match ($random->getInt(0, 5)) {
                    0 => sprintf('[%02d/%02d] - "%s.%s" yEnc (1/%d)', $i + 1, $count, $base, strtoupper($extension), $random->getInt(1, 9_999)),
                    1 => $base.'.part01.rar',
                    2 => "nul\0".$base.'.'.$extension,
                    3 => ' '.$base.'.'.$extension."\x0B",
                    4 => str_repeat('a.b', $random->getInt(1, 30)).'.'.$extension.'x',
                    default => $base.'.'.$extension,
                };
                $heads[] = match ($random->getInt(0, 5)) {
                    0 => self::transport($random->getInt(0, 1) === 0 ? 188 : 192, $random->getInt(0, 150), $random->getInt(900, 8_192)),
                    1 => $random->getBytes($random->getInt(1_000, 8_192)),
                    2 => str_repeat("\0", $random->getInt(0, 4_096)),
                    3 => "\0\0\0\x20ftypisom".$random->getBytes($random->getInt(1, 4_096)),
                    4 => 'OggS'.$random->getBytes($random->getInt(1, 30)),
                    default => substr(self::matroska(64), 0, $random->getInt(0, 20)),
                };
            } else {
                $names[] = sprintf('[%02d/%02d] - "%s.%s" yEnc (1/%d)', $i + 1, $count, $base, $extension, $random->getInt(100, 9_999));
                $heads[] = match ($extension) {
                    'mkv' => self::matroska($random->getInt(1_024, 4_096)),
                    'mp4' => "\0\0\0\x20ftypisom".$random->getBytes($random->getInt(1_024, 4_096)),
                    default => "RIFF\x10\x00\x00\x00AVI LIST".$random->getBytes($random->getInt(1_024, 4_096)),
                };
            }
        }

        return [$names, $heads];
    }

    private static function matroska(int $length): string
    {
        return str_pad("\x1A\x45\xDF\xA3\x01\x00\x00\x00\x00\x00\x00\x23\x42\x86\x81\x01", $length, "\x42");
    }

    /** A transport stream of `$length` bytes whose packets of `$stride` bytes start at `$offset`. */
    private static function transport(int $stride, int $offset, int $length): string
    {
        $data = str_repeat("\xAB", $length);
        for ($at = $offset; $at < $length; $at += $stride) {
            $data[$at] = "\x47";
        }

        return $data;
    }

    /**
     * @param  array<array-key, string>  $outcomes
     * @param  array<array-key, int>  $elapsed
     * @param  array<array-key, int>  $stage
     * @param  array<array-key, int>  $files
     * @param  array<array-key, int>  $duplicates
     */
    private function assertResultParity(array $outcomes, array $elapsed, array $stage, array $files, array $duplicates, int $slow): void
    {
        $php = ReleaseProcessingResult::withW49RustFfi(false, static fn (): array => ReleaseProcessingResult::summarizeResultBatch($outcomes, $elapsed, $stage, $files, $duplicates, $slow));
        $rust = ReleaseProcessingResult::withW49RustFfi(true, static fn (): array => ReleaseProcessingResult::summarizeResultBatch($outcomes, $elapsed, $stage, $files, $duplicates, $slow));
        self::assertSame($php, ReleaseProcessingResult::summarizeResultBatchNative($outcomes, $elapsed, $stage, $files, $duplicates, $slow));
        self::assertSame($php, $rust);
        self::assertCount(ReleaseProcessingResult::W49_RESULT_HEADER + \count($outcomes), $php);
    }

    /** @param  array<array-key, mixed>  $values */
    private function assertClockParity(array $values): void
    {
        $php = FfmpegFrameGrabber::withW49RustFfi(false, static fn (): array => FfmpegFrameGrabber::parseClockBatch($values));
        $rust = FfmpegFrameGrabber::withW49RustFfi(true, static fn (): array => FfmpegFrameGrabber::parseClockBatch($values));
        self::assertSame($php, FfmpegFrameGrabber::parseClockBatchNative($values));
        self::assertSame($php, $rust);
        self::assertCount(FfmpegFrameGrabber::W49_CLOCK_HEADER + \count($values), $php);
    }

    /**
     * @param  array<array-key, string>  $kinds
     * @param  array<array-key, int>  $bytes
     */
    private function assertDownloadParity(array $kinds, array $bytes, int $budget): void
    {
        $php = DownloadKind::withW49RustFfi(false, static fn (): array => DownloadKind::accountDownloadBatch($kinds, $bytes, $budget));
        $rust = DownloadKind::withW49RustFfi(true, static fn (): array => DownloadKind::accountDownloadBatch($kinds, $bytes, $budget));
        self::assertSame($php, DownloadKind::accountDownloadBatchNative($kinds, $bytes, $budget));
        self::assertSame($php, $rust);
        self::assertCount(DownloadKind::W49_DOWNLOAD_HEADER + \count($kinds), $php);
    }

    /**
     * @param  array<array-key, string>  $values
     * @param  array<array-key, int>  $sizes
     */
    private function assertMappingParity(array $values, array $sizes): void
    {
        $php = MappingConfidence::withW49RustFfi(false, static fn (): array => MappingConfidence::rankMappingBatch($values, $sizes));
        $rust = MappingConfidence::withW49RustFfi(true, static fn (): array => MappingConfidence::rankMappingBatch($values, $sizes));
        self::assertSame($php, MappingConfidence::rankMappingBatchNative($values, $sizes));
        self::assertSame($php, $rust);
        self::assertCount(MappingConfidence::W49_MAPPING_HEADER + \count($values), $php);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $heads
     */
    private function assertContainerParity(array $names, array $heads): void
    {
        $php = VideoContainer::withW49RustFfi(false, static fn (): array => VideoContainer::classifyContainerBatch($names, $heads));
        $rust = VideoContainer::withW49RustFfi(true, static fn (): array => VideoContainer::classifyContainerBatch($names, $heads));
        self::assertSame($php, VideoContainer::classifyContainerBatchNative($names, $heads));
        self::assertSame($php, $rust);
        self::assertCount(VideoContainer::W49_CONTAINER_HEADER + \count($names), $php);
    }

    private function requireBindings(): void
    {
        if (ReleaseProcessingResult::summarizeResultBatchNative([], [], [], [], []) === null
            || FfmpegFrameGrabber::parseClockBatchNative([]) === null
            || DownloadKind::accountDownloadBatchNative([], [], 0) === null
            || MappingConfidence::rankMappingBatchNative([], []) === null
            || VideoContainer::classifyContainerBatchNative([], []) === null
        ) {
            self::markTestSkipped('The Wave 49.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
