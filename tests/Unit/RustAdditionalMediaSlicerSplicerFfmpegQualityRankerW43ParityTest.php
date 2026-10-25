<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\ArchivedMediaSlicer;
use App\Services\AdditionalProcessing\Media\ContainerSplicer;
use App\Services\AdditionalProcessing\Media\FfmpegFrameGrabber;
use App\Services\AdditionalProcessing\Media\FrameQualityScorer;
use App\Services\AdditionalProcessing\Media\ImageCandidateRanker;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

final class RustAdditionalMediaSlicerSplicerFfmpegQualityRankerW43ParityTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private const CLASSES = [ArchivedMediaSlicer::class, ContainerSplicer::class, FfmpegFrameGrabber::class, FrameQualityScorer::class, ImageCandidateRanker::class];

    /**
     * Qualification run: Apple M5, macOS arm64, PHP 8.5.12, release Rust;
     * 2,000 paired samples/workload, seed 436. Entries are Common mean/p99,
     * Uncommon mean/p99 speedups. Full caller cost fails at least one metric
     * for every class, so all defaults retain PHP. Fresh runs save raw pairs.
     * Artifact: w436-benchmark-26b2ee1090625833.json (outside the checkout).
     */
    private const QUALIFICATION = [
        ArchivedMediaSlicer::class => [0.826, 0.845, 0.909, 0.967],
        ContainerSplicer::class => [0.664, 0.798, 0.688, 0.803],
        FfmpegFrameGrabber::class => [1.114, 1.063, 1.117, 1.397],
        FrameQualityScorer::class => [1.186, 1.166, 1.024, 1.009],
        ImageCandidateRanker::class => [1.435, 1.410, 1.467, 1.471],
    ];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/w436-media-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::useRustFfi(null);
        }
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_independent_nullable_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->hasMethod('useRustFfi'));
            self::assertTrue($reflection->hasMethod('usesRustFfi'));
            $class::useRustFfi(false);
            self::assertFalse($class::usesRustFfi());
            $class::useRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            foreach (self::CLASSES as $other) {
                if ($class !== $other) {
                    self::assertSame($other::W43_RUST_FFI_DEFAULT, $other::usesRustFfi());
                }
            }
            $class::useRustFfi(null);
            self::assertSame($class::W43_RUST_FFI_DEFAULT, $class::usesRustFfi());
        }
    }

    public function test_defaults_match_recorded_threshold_policy(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            $class::useRustFfi(null);
            self::assertSame(min($speedups) >= 1.5, $class::W43_RUST_FFI_DEFAULT);
            self::assertSame($class::W43_RUST_FFI_DEFAULT, $class::usesRustFfi());
        }
    }

    public function test_slicing_range_entry_and_listing_parity(): void
    {
        $supported = false;
        $slicer = new ArchivedMediaSlicer($this->makeConfig(['mediaSampleMaxBytes' => 64, 'mediaMainVideoMinBytes' => 100]));
        $data = str_repeat('x', 9)."\x1a\x45\xdf\xa3".str_repeat('v', 300);
        foreach (['9-312', '', '-', '-1-40', '1--8', '312', '313-400', '500-900', '  +9-020 ', '1e2-300', '2.5-300', 'garbage-22', '9223372036854775808-0'] as $range) {
            foreach ([null, 0, 7, 120, 400] as $next) {
                foreach (['Film.mkv', 'Samples\\clip.mkv', 'sample-film.avi', 'Film.sample.mkv', 'notasample.mp4', "dir/久\0sample.mkv"] as $name) {
                    $file = ['name' => $name, 'size' => 200, 'range' => $range, 'next_offset' => $next];
                    ArchivedMediaSlicer::useRustFfi(false);
                    $bounds = $slicer->range($file);
                    $expected = $slicer->entryFor($data, $file, 3, 5);
                    ArchivedMediaSlicer::useRustFfi(true);
                    self::assertSame($bounds, $slicer->range($file));
                    self::assertEquals($expected, $slicer->entryFor($data, $file, 3, 5));
                }
            }
        }
        foreach (['', '123', str_repeat('x', 400)] as $bytes) {
            foreach ([-5, 0, 1, 200] as $size) {
                $file = ['name' => 'sample.mkv', 'size' => $size, 'range' => '2-100', 'next_offset' => null];
                self::assertEquals($slicer->entryForPhp($bytes, $file, 1, 0), $slicer->entryFor($bytes, $file, 1, 0));
            }
        }
        $valid = ['name' => 'movie.mkv', 'size' => 200, 'range' => '9-312', 'next_offset' => 250];
        $native = $slicer->entryForNative($data, $valid, 3, 5, $supported);
        self::assertTrue($supported, 'The new slicing export must execute.');
        self::assertEquals($slicer->entryForPhp($data, $valid, 3, 5), $native);
        self::assertSame([9, 312], $slicer->rangeNative($valid, $supported));
        self::assertTrue($supported);
        $rows = [null, $valid + ['compressed' => 0], $valid + ['compressed' => 1], $valid + ['compressed' => 0, 'pass' => 1], $valid + ['compressed' => 0, 'source' => 'nested']];
        ArchivedMediaSlicer::useRustFfi(false);
        $expected = $slicer->storedVideos($rows);
        ArchivedMediaSlicer::useRustFfi(true);
        self::assertSame($expected, $slicer->storedVideos($rows));
        self::assertCount(1, $expected);
    }

    public function test_splicing_all_containers_and_marker_alignments(): void
    {
        $supported = false;
        $splicer = new ContainerSplicer;
        $random = new Randomizer(new Mt19937(436));
        foreach (VideoContainer::cases() as $container) {
            for ($i = 0; $i < 90; $i++) {
                $head = $random->getBytes($random->getInt(1, 512));
                $mid = $random->getBytes($random->getInt(1, 1024));
                if ($i % 3 !== 0) {
                    [$head, $mid] = match ($container) {
                        VideoContainer::Matroska => [$head."\x1f\x43\xb6\x75".'header', $mid."\x1f\x43\xb6\x75".'payload'],
                        VideoContainer::Avi => [$head.'LIST'.pack('V', 10).'movi', $mid.'00dc'.'01wb'],
                        VideoContainer::MpegProgram => [$head, $mid."\0\0\x01\xba".'payload'],
                        VideoContainer::MpegTransport => [$head, str_repeat('x', $i % 13).str_repeat("\x47".str_repeat('t', 187), 5)],
                        default => [$head, $mid],
                    };
                }
                ContainerSplicer::useRustFfi(false);
                $expected = $splicer->splice($head, $mid, $container);
                ContainerSplicer::useRustFfi(true);
                self::assertSame($expected, $splicer->splice($head, $mid, $container));
                self::assertSame($expected, $splicer->spliceNative($head, $mid, $container, $supported));
                self::assertTrue($supported, 'Splice parity must execute Rust, including unsupported containers.');
            }
        }
        self::assertSame(null, $splicer->splice('', '', VideoContainer::Avi));
        self::assertSame('header'."\x1f\x43\xb6\x75".'body', $splicer->splice('header'."\x1f\x43\xb6\x75", 'skip'."\x1f\x43\xb6\x75".'body', VideoContainer::Matroska));
    }

    public function test_ffmpeg_clock_parity_and_numeric_edges(): void
    {
        $supported = false;
        $values = [null, false, 17, [], new \stdClass, '', '00:00:00', '00:00:00.000000001', '00:01:02.123456789', '1:99:99', "01:02:03\n", "01:02:03\r\n", '01:02:03.', '-01:02:03', '01:2:03', '01:02:03x', '999999999999999999999999:59:59.999'];
        $random = new Randomizer(new Mt19937(436));
        for ($i = 0; $i < 500; $i++) {
            $values[] = sprintf('%d:%02d:%02d.%09d', $random->getInt(0, 9999), $random->getInt(0, 99), $random->getInt(0, 99), $random->getInt(0, 999999999));
        }
        foreach ($values as $value) {
            FfmpegFrameGrabber::useRustFfi(false);
            $expected = FfmpegFrameGrabber::clockSeconds($value);
            FfmpegFrameGrabber::useRustFfi(true);
            self::assertSame($expected, FfmpegFrameGrabber::clockSeconds($value));
        }
        self::assertSame(3723.5, FfmpegFrameGrabber::clockSecondsNative('01:02:03.5', $supported));
        self::assertTrue($supported);
    }

    public function test_frame_quality_exact_parity_and_best_selection(): void
    {
        $supported = false;
        $scorer = new FrameQualityScorer(12, 24, 232);
        $random = new Randomizer(new Mt19937(436));
        foreach ([[1, 1], [17, 29], [64, 36], [64, 64], [320, 180]] as [$width, $height]) {
            $sample = imagecreatetruecolor($width, $height);
            self::assertNotFalse($sample);
            $rgb = '';
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $pixel = $random->getInt(0, 0xFFFFFF);
                    imagesetpixel($sample, $x, $y, $pixel);
                    $rgb .= chr(($pixel >> 16) & 255).chr(($pixel >> 8) & 255).chr($pixel & 255);
                }
            }
            FrameQualityScorer::useRustFfi(false);
            $phpSample = FrameQualityScorer::measureSamplePhp($sample);
            $phpRgb = FrameQualityScorer::measureRgbPhp($rgb);
            FrameQualityScorer::useRustFfi(true);
            self::assertSame($phpSample, FrameQualityScorer::measureSample($sample, $supported));
            self::assertTrue($supported);
            self::assertSame($phpRgb, FrameQualityScorer::measureRgb($rgb, $supported));
            self::assertTrue($supported);
            $path = $this->directory.'/frame-'.$width.'.png';
            imagepng($sample, $path);
            FrameQualityScorer::useRustFfi(false);
            $expected = $scorer->measure($path);
            FrameQualityScorer::useRustFfi(true);
            self::assertSame($expected, $scorer->measure($path));
        }
        foreach (['', 'x', 'xy', "\0\0\0", str_repeat("\xff", 300)] as $rgb) {
            FrameQualityScorer::useRustFfi(false);
            $expected = FrameQualityScorer::measureRgbPhp($rgb);
            FrameQualityScorer::useRustFfi(true);
            self::assertSame($expected, FrameQualityScorer::measureRgb($rgb));
        }
        $palette = imagecreate(3, 4);
        self::assertNotFalse($palette);
        imagecolorallocate($palette, 127, 50, 20);
        self::assertSame(FrameQualityScorer::measureSamplePhp($palette), FrameQualityScorer::measureSample($palette));
        $blank = imagecreatetruecolor(64, 36);
        self::assertNotFalse($blank);
        imagepng($blank, $this->directory.'/blank.png');
        file_put_contents($this->directory.'/bad.jpg', 'not an image');
        $paths = (glob($this->directory.'/*') ?: []);
        $paths[] = $this->directory.'/missing.png';
        FrameQualityScorer::useRustFfi(false);
        $best = $scorer->best($paths);
        FrameQualityScorer::useRustFfi(true);
        self::assertSame($best, $scorer->best($paths));
        self::assertNotNull($best);
        self::assertNull($scorer->best([$this->directory.'/blank.png', $this->directory.'/bad.jpg', $this->directory.'/missing.png']));
    }

    public function test_rank_and_stable_sort_parity(): void
    {
        $supported = false;
        $names = ['', 'CD1.screens.jpg', 'cover.back.jpg', 'poster.png', 'movie.jpeg', 'proof.jpg', 'contact_sheet.png', 'nosamplehere.jpg', 'folder/keyart.jpg', 'sample/cover.jpg', 'contact--sheet.jpg', 'cd99label.jpg', '""cover"screen.jpg', '"""cover.back.jpg"', "dir\\久\0poster.jpg", " cover.jpg\0\n", 'dir/front.jpg/', '[01/99] "dir\\screen.jpg" yEnc', 'screenshotsé.jpg'];
        $random = new Randomizer(new Mt19937(436));
        for ($i = 0; $i < 500; $i++) {
            $names[] = $random->getBytes($random->getInt(1, 40)).$names[$i % 15];
        }
        foreach ($names as $name) {
            ImageCandidateRanker::useRustFfi(false);
            $expected = ImageCandidateRanker::rank($name);
            ImageCandidateRanker::useRustFfi(true);
            self::assertSame($expected, ImageCandidateRanker::rank($name), bin2hex($name));
        }
        self::assertSame(0, ImageCandidateRanker::rankNative('CD1.screens.jpg', null, $supported));
        self::assertTrue($supported);
        $items = [];
        foreach ($names as $id => $name) {
            $items[] = ['id' => $id, 'name' => $name, 'size' => $id % 4];
        }
        $nameOf = static fn (array $row): string => $row['name'];
        $sizeOf = static fn (array $row): int => $row['size'];
        ImageCandidateRanker::useRustFfi(false);
        $expected = ImageCandidateRanker::sort($items, $nameOf, $sizeOf);
        self::assertSame(ImageCandidateRanker::sortPhp($items, $nameOf, $sizeOf), $expected);
        ImageCandidateRanker::useRustFfi(true);
        self::assertSame($expected, ImageCandidateRanker::sort($items, $nameOf, $sizeOf));
        self::assertSame([], ImageCandidateRanker::sort([], $nameOf, $sizeOf));
    }

    public function test_missing_library_and_rejected_symbols_fall_back(): void
    {
        $supported = false;
        $slicer = new ArchivedMediaSlicer($this->makeConfig(), $this->directory.'/missing.so');
        $file = ['name' => 'movie.mkv', 'size' => 20, 'range' => '2-20', 'next_offset' => null];
        self::assertEquals($slicer->entryForPhp(str_repeat('a', 30), $file, 0, 1), $slicer->entryForNative(str_repeat('a', 30), $file, 0, 1, $supported));
        self::assertFalse($supported);
        self::assertSame(3723.0, FfmpegFrameGrabber::clockSecondsNative('01:02:03', $supported, $this->directory.'/missing.so'));
        self::assertFalse($supported);
        self::assertSame(1, ImageCandidateRanker::rankNative('cover.jpg', $this->directory.'/missing.so', $supported));
        self::assertFalse($supported);
        $image = imagecreatetruecolor(64, 36);
        self::assertNotFalse($image);
        imagepng($image, $this->directory.'/fallback.png');
        foreach (self::CLASSES as $class) {
            $ffi = new ReflectionProperty($class, 'ffi');
            $resolved = new ReflectionProperty($class, 'ffiResolved');
            $original = $ffi->getValue();
            $wasResolved = $resolved->getValue();
            try {
                $ffi->setValue(null, FFI::cdef('int abs(int);'));
                $resolved->setValue(null, true);
                $class::useRustFfi(true);
                self::assertSame($this->fallbackResult($class, false), $this->fallbackResult($class, true));
            } finally {
                $ffi->setValue(null, $original);
                $resolved->setValue(null, $wasResolved);
            }
        }
    }

    private function fallbackResult(string $class, bool $native): mixed
    {
        $class::useRustFfi($native);

        return match ($class) {
            ArchivedMediaSlicer::class => (new ArchivedMediaSlicer($this->makeConfig()))->range(['range' => '2-20']),
            ContainerSplicer::class => (new ContainerSplicer)->splice('header', "x\0\0\x01\xbabody", VideoContainer::MpegProgram),
            FfmpegFrameGrabber::class => FfmpegFrameGrabber::clockSeconds('01:02:03'),
            FrameQualityScorer::class => (new FrameQualityScorer(12, 24, 232))->measure($this->directory.'/fallback.png'),
            ImageCandidateRanker::class => ImageCandidateRanker::rank('cover.jpg'),
            default => null,
        };
    }

    /**
     * Reproduce after cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml:
     * php artisan test --compact tests/Unit/RustAdditionalMediaSlicerSplicerFfmpegQualityRankerW43ParityTest.php
     *
     * Seed 436; eight frozen variants, 50 paired warmups, 2,000 randomized paired
     * observations per class/workload. Timings cover public dispatch, allocation,
     * FFI marshaling, slicing/copying, and (for scoring) JPEG read/decode/downscale.
     * Common/Uncommon: stored Matroska heads of 64/512 KiB; splice payloads
     * of 64/512 KiB; ordinary/fractional long-hour clocks; 320x180/960x540 JPEGs;
     * and stable ranking of 8/128 images with short/long release filenames.
     * All pairs are checked for exact parity outside timings. Each p99 has 20 tail
     * observations; p99 bands are order statistics at p +/- 1.96*sqrt(p*(1-p)/n).
     * Raw paired nanoseconds, order, fixture IDs, metadata, and source/library hashes
     * are preserved in the printed /tmp artifact. These are local CPU/caller results.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks_and_defaults(): void
    {
        $this->requireWaveLibrary();
        $random = new Randomizer(new Mt19937(436));
        $records = [];
        $summary = [];
        foreach (self::CLASSES as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                $calls = $this->benchmarkCalls($class, $workload === 'Uncommon');
                $php = $rust = [];
                for ($i = -50; $i < 2000; $i++) {
                    $variant = $random->getInt(0, count($calls) - 1);
                    $call = $calls[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $elapsed = $results = [];
                    foreach ($order as $native) {
                        $class::useRustFfi($native);
                        $start = hrtime(true);
                        $result = $call();
                        $elapsed[(int) $native] = hrtime(true) - $start;
                        $results[(int) $native] = $result;
                    }
                    self::assertEquals($results[0], $results[1], $class.' '.$workload.' benchmark parity');
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order, $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $n = count($php);
                $low = max(0, (int) floor((0.99 - 1.96 * sqrt(0.99 * 0.01 / $n)) * $n) - 1);
                $high = min($n - 1, (int) ceil((0.99 + 1.96 * sqrt(0.99 * 0.01 / $n)) * $n) - 1);
                $summary[$class][$workload] = [
                    'mean_speedup' => array_sum($php) / array_sum($rust),
                    'p99_speedup' => $php[(int) ceil($n * 0.99) - 1] / $rust[(int) ceil($n * 0.99) - 1],
                    'php_mean_ns' => array_sum($php) / $n, 'rust_mean_ns' => array_sum($rust) / $n,
                    'php_p99_ns' => $php[(int) ceil($n * 0.99) - 1], 'rust_p99_ns' => $rust[(int) ceil($n * 0.99) - 1],
                    'php_p99_band_ns' => [$php[$low], $php[$high]], 'rust_p99_band_ns' => [$rust[$low], $rust[$high]],
                ];
            }
            $class::useRustFfi(null);
            self::assertSame($class::W43_RUST_FFI_DEFAULT, $class::usesRustFfi());
        }
        $hashes = [];
        $root = dirname(__DIR__, 2);
        foreach (self::CLASSES as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            self::assertNotFalse($file);
            $hashes[$file] = hash_file('sha256', $file);
        }
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'tests/Unit/RustAdditionalMediaSlicerSplicerFfmpegQualityRankerW43ParityTest.php'] as $file) {
            $hashes[$file] = hash_file('sha256', $root.'/'.$file);
        }
        $library = $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $metadata = [
            'utc' => gmdate('c'), 'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'base' => '7ec9bf35c3a0b33e1ed9e57d5a031874bf25f0ed', 'source_sha256' => $hashes,
            'library_sha256' => hash_file('sha256', $library), 'profile' => 'release opt-level=3 lto=true codegen-units=1',
            'php' => PHP_VERSION, 'os' => php_uname(), 'rustc' => trim((string) shell_exec('rustc --version')),
            'cargo' => trim((string) shell_exec('cargo --version')), 'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.memsize' : 'uname -m')),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
            'pairs' => 2000, 'warmups' => 50, 'seed' => 436, 'variants' => 8,
        ];
        $artifact = sys_get_temp_dir().'/w436-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW43.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        // A disabled default remains safe on hosts where a fresh run qualifies;
        // enabling it requires all four metrics, rather than one favorable run.
        foreach (self::CLASSES as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertFalse($class::usesRustFfi() && ! $eligible, $class.' requires >= 1.5x mean and p99 on both workloads');
        }
    }

    /** @return list<\Closure(): mixed> */
    private function benchmarkCalls(string $class, bool $uncommon): array
    {
        $calls = [];
        $slicer = new ArchivedMediaSlicer($this->makeConfig());
        $splicer = new ContainerSplicer;
        $scorer = new FrameQualityScorer(12, 24, 232);
        for ($i = 0; $i < 8; $i++) {
            switch ($class) {
                case ArchivedMediaSlicer::class:
                    $data = str_repeat('x', 8 + $i)."\x1a\x45\xdf\xa3".str_repeat('v', $uncommon ? 524288 : 65536);
                    $file = ['name' => ($uncommon ? str_repeat('dir/', 12).'sample-' : '').'movie-'.$i.'.mkv', 'size' => 5000000000, 'range' => (8 + $i).'-999999', 'next_offset' => null];
                    $calls[] = static fn () => $slicer->entryFor($data, $file, $i, 2);
                    break;
                case ContainerSplicer::class:
                    $head = 'header'.str_repeat('x', $uncommon ? 65536 : 4096)."\x1f\x43\xb6\x75";
                    $mid = str_repeat('x', ($uncommon ? 262144 : 32768) + $i)."\x1f\x43\xb6\x75".str_repeat('p', $uncommon ? 262144 : 32768);
                    $calls[] = static fn () => $splicer->splice($head, $mid, VideoContainer::Matroska);
                    break;
                case FfmpegFrameGrabber::class:
                    $clock = $uncommon ? (100000 + $i).':59:59.123456789' : '00:'.sprintf('%02d', $i).':03.125';
                    $calls[] = static fn () => FfmpegFrameGrabber::clockSeconds($clock);
                    break;
                case FrameQualityScorer::class:
                    $path = $this->directory.'/bench-'.($uncommon ? 'large' : 'small').'-'.$i.'.jpg';
                    $image = imagecreatetruecolor($uncommon ? 960 : 320, $uncommon ? 540 : 180);
                    self::assertNotFalse($image);
                    for ($y = 0; $y < imagesy($image); $y++) {
                        for ($x = 0; $x < imagesx($image); $x++) {
                            imagesetpixel($image, $x, $y, (($x * 13 + $y * 7 + $i) % 256) * 0x010101);
                        }
                    }
                    imagejpeg($image, $path, 85);
                    $calls[] = static fn () => $scorer->measure($path);
                    break;
                case ImageCandidateRanker::class:
                    $items = [];
                    for ($j = 0; $j < ($uncommon ? 128 : 8); $j++) {
                        $name = ['screen.jpg', 'cover.jpg', 'disc2.jpg', 'image.jpg'][$j % 4];
                        $items[] = ['name' => ($uncommon ? str_repeat('movie.release.name-', 8) : '').$i.'-'.$j.'-'.$name, 'size' => ($j * 13 + $i) % 7];
                    }
                    $calls[] = static fn () => ImageCandidateRanker::sort($items, static fn (array $row): string => $row['name'], static fn (array $row): int => $row['size']);
                    break;
            }
        }

        return $calls;
    }

    private function requireWaveLibrary(): void
    {
        $headers = '';
        foreach (self::CLASSES as $class) {
            $headers .= (new ReflectionClass($class))->getConstant('FFI_CDEF')."\n";
        }
        $ffi = RustPipelineLibrary::resolveDomain('w436_test', $headers);
        self::assertInstanceOf(FFI::class, $ffi, 'Build the release library with all Wave 43.6 symbols before testing.');
    }
}
