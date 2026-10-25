<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Media\FrameQualityScorer;
use App\Services\AdditionalProcessing\Media\ImageInspector;
use App\Services\AdditionalProcessing\Media\MediaSourceResolver;
use App\Services\AdditionalProcessing\Media\Par2FileMapper;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Process\Process;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

final class RustAdditionalDtoArchivePar2ImageInspectorSourceMapperW44ParityTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private string $directory;

    /**
     * Apple M5/macOS arm64, PHP 8.5.12, optimized release Rust. Seed 446,
     * 2,000 pairs/workload. Common mean/p99, Uncommon mean/p99 speedups.
     * Qualification artifact: w446-benchmark-f83ba92326101188.json in system tmp.
     * Fresh runs preserve their raw samples and source/toolchain identities.
     */
    private const QUALIFICATION = [
        ArchiveCandidate::class => [0.545, 0.526, 0.779, 1.186],
        Par2FileEntry::class => [0.314, 0.404, 0.319, 0.381],
        ImageInspector::class => [0.948, 0.946, 0.976, 0.952],
        MediaSourceResolver::class => [2.164, 2.424, 6.122, 4.221],
        Par2FileMapper::class => [1.461, 2.173, 5.265, 6.326],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/w446-additional-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    private const CLASSES = [ArchiveCandidate::class, Par2FileEntry::class, ImageInspector::class, MediaSourceResolver::class, Par2FileMapper::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if ((new ReflectionClass($class))->hasMethod('useRustFfi')) {
                $class::useRustFfi(null);
            }
        }
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_nullable_independent_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertTrue((new ReflectionClass($class))->hasMethod('useRustFfi'));
            self::assertTrue((new ReflectionClass($class))->hasMethod('usesRustFfi'));
            $class::useRustFfi(false);
            self::assertFalse($class::usesRustFfi());
            $class::useRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            foreach (self::CLASSES as $other) {
                if ($class !== $other) {
                    self::assertSame($other::W44_RUST_FFI_DEFAULT, $other::usesRustFfi());
                }
            }
            $class::useRustFfi(null);
            self::assertSame($class::W44_RUST_FFI_DEFAULT, $class::usesRustFfi());
        }
    }

    public function test_defaults_match_recorded_all_metric_gate(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            $class::useRustFfi(null);
            self::assertSame(min($speedups) >= 1.5, $class::W44_RUST_FFI_DEFAULT);
            self::assertSame($class::W44_RUST_FFI_DEFAULT, $class::usesRustFfi());
        }
    }

    private function requireWaveLibrary(): FFI
    {
        $headers = '';
        foreach (self::CLASSES as $class) {
            $headers .= (new ReflectionClass($class))->getConstant('FFI_CDEF')."\n";
        }
        $ffi = RustPipelineLibrary::resolveDomain('w446_test', $headers);
        self::assertInstanceOf(FFI::class, $ffi, 'Build the release library with Wave 44.6 symbols.');

        return $ffi;
    }

    public function test_archive_subject_and_volume_family_parity(): void
    {
        $this->requireWaveLibrary();
        $subjects = ['', 'Movie.part01.rar', 'Movie.part2.rar', 'movie.r00', 'movie.r999', 'movie.zip', 'movie.zipx', 'movie.zipr01', 'movie.z001', 'movie.7z.001', 'movie.mkv.001', str_repeat('f', 32).'.10', '"First Movie.rar" yEnc (1/9)', '[01/10] - Second Movie.rar yEnc (1/50)', 'prefix [2 of 9] - film.rar - (1/4)', "\0\tMOVIE.RAR\0", "\"movie\n.part01.rar\" yEnc", '"""film.rar"', 'same.rar'."\n", 'movie.7z.0001', 'a..part001.rar', '"  久É.RAR  "', "\xffPART01.RAR"];
        $random = new Randomizer(new Mt19937(446));
        for ($i = 0; $i < 500; $i++) {
            $subjects[] = $random->getBytes($random->getInt(1, 60)).$subjects[$i % 18];
        }
        foreach ($subjects as $subject) {
            ArchiveCandidate::useRustFfi(false);
            $expected = [ArchiveCandidate::fileName($subject), ArchiveCandidate::volumeSetKey($subject)];
            ArchiveCandidate::useRustFfi(true);
            self::assertSame($expected, [ArchiveCandidate::fileName($subject), ArchiveCandidate::volumeSetKey($subject)], bin2hex($subject));
            $supported = false;
            self::assertSame($expected[0], ArchiveCandidate::fileNameNative($subject, supported: $supported));
            self::assertTrue($supported, 'Archive filename must execute Rust.');
            self::assertSame($expected[1], ArchiveCandidate::volumeSetKeyNative($subject, supported: $supported));
            self::assertTrue($supported, 'Archive family must execute Rust.');
            self::assertSame($expected[1], (new ArchiveCandidate($subject, [], true, 2))->volumeSet());
            self::assertSame('explicit', (new ArchiveCandidate($subject, [], false, 2, setKey: 'explicit'))->volumeSet());
        }
    }

    public function test_par2_normalization_and_hash_matching_parity(): void
    {
        $this->requireWaveLibrary();
        $rows = [null, false, 'file', [], ['name' => null, 'size' => 5], ['name' => 42, 'size' => 8], ['name' => 'zero', 'size' => 0], ['name' => 'negative', 'size' => -1], ['name' => '', 'size' => ' +4 ', 'hash_16K' => 'ABCDEF'], ['name' => "\0久\xff.mkv", 'size' => '1e3', 'hash_16K' => "\xffAbC\0"], ['name' => 'float', 'size' => 1.9, 'hash_16K' => 42], ['name' => 'max', 'size' => PHP_INT_MAX, 'hash_16K' => null], ['name' => 'missing']];
        $random = new Randomizer(new Mt19937(446));
        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['name' => $random->getBytes($random->getInt(1, 70)), 'size' => $random->getInt(-100, 100000000), 'hash_16K' => strtoupper(md5((string) $i))];
        }
        foreach ([[], $rows, array_reverse($rows, true)] as $files) {
            Par2FileEntry::useRustFfi(false);
            $expected = Par2FileEntry::listFromPar2Info($files);
            Par2FileEntry::useRustFfi(true);
            self::assertEquals($expected, Par2FileEntry::listFromPar2Info($files));
            $supported = false;
            self::assertEquals($expected, Par2FileEntry::listFromPar2InfoNative($files, $supported));
            self::assertTrue($supported, 'Normalization must execute Rust.');
        }
        foreach ([0, 1, 19, 16384, 20000] as $size) {
            $data = str_repeat('x', $size);
            $file = new Par2FileEntry('movie.mkv', $size, md5(substr($data, 0, min($size, 16384))));
            self::assertTrue($file->matchesStartOf($data));
            self::assertFalse((new Par2FileEntry('movie.mkv', $size))->matchesStartOf($data));
            if ($size > 0 && $size <= 16384) {
                self::assertFalse($file->matchesStartOf(substr($data, 1)));
            }
        }
        foreach (['', 'PAR2', "PAR2\0PKT".str_repeat("\0", 80)] as $data) {
            Par2FileEntry::useRustFfi(false);
            $expected = Par2FileEntry::listFromPar2Data($data);
            Par2FileEntry::useRustFfi(true);
            self::assertEquals($expected, Par2FileEntry::listFromPar2Data($data));
        }
    }

    public function test_image_headers_tails_files_and_usability_parity(): void
    {
        $this->requireWaveLibrary();
        $fixtures = [[IMAGETYPE_JPEG, 16, str_repeat('j', 14)."\xff\xd9"], [IMAGETYPE_PNG, 99, str_repeat('p', 80).'IEND'.str_repeat("\0", 8)], [IMAGETYPE_WEBP, 40, 'RIFF'.pack('V', 32).'WEBP'], [IMAGETYPE_WEBP, 39, 'RIFF'.pack('V', 32)], [IMAGETYPE_WEBP, 400, 'RIFF'.pack('V', 0xFFFFFFFF)], [IMAGETYPE_WEBP, 400, 'RIFF'], [IMAGETYPE_JPEG, 100, "\xff\xd9".str_repeat("\0\r\n ", 400)], [IMAGETYPE_PNG, 9000, 'IEND'.str_repeat('x', 5000)]];
        $random = new Randomizer(new Mt19937(446));
        for ($i = 0; $i < 250; $i++) {
            $fixtures[] = [[IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP][$i % 3], $random->getInt(0, 9000), $random->getBytes($random->getInt(1, 8192))];
        }
        foreach ($fixtures as [$type, $size, $data]) {
            ImageInspector::useRustFfi(false);
            $expected = ImageInspector::isCompleteTail($type, $size, $data);
            ImageInspector::useRustFfi(true);
            self::assertSame($expected, ImageInspector::isCompleteTail($type, $size, $data));
            $supported = false;
            self::assertSame($expected, ImageInspector::isCompleteTailNative($type, $size, $data, $supported));
            self::assertTrue($supported);
        }
        foreach ([-1, 0, 99] as $type) {
            self::assertFalse(ImageInspector::isCompleteTail($type, -1, 'garbage'));
        }
        $inspector = new ImageInspector(new FrameQualityScorer(12, 24, 232), 12);
        foreach ($fixtures as $i => [$type, $size, $data]) {
            $path = $this->directory.'/tail-'.$i;
            file_put_contents($path, $data);
            clearstatcache(true, $path);
            ImageInspector::useRustFfi(false);
            $expected = $inspector->isComplete($path, $type);
            ImageInspector::useRustFfi(true);
            self::assertSame($expected, $inspector->isComplete($path, $type));
            unlink($path);
        }
        foreach ([[100, 200], [200, 200], [4000, 4000]] as [$width, $height]) {
            $image = imagecreatetruecolor($width, $height);
            self::assertNotFalse($image);
            $path = $this->directory.'/image-'.$width.'.png';
            imagepng($image, $path);
            ImageInspector::useRustFfi(false);
            $expected = $inspector->isUsable($path);
            ImageInspector::useRustFfi(true);
            self::assertSame($expected, $inspector->isUsable($path));
        }
        self::assertFalse($inspector->isComplete($this->directory.'/missing', IMAGETYPE_JPEG));
        self::assertFalse($inspector->isUsable($this->directory.'/missing'));
    }

    private function resolver(?string $libraryPath = null, ?string $videoRegex = null): MediaSourceResolver
    {
        return new MediaSourceResolver($this->makeConfig($videoRegex === null ? [] : ['videoFileRegex' => $videoRegex]), $this->createStub(UsenetDownloadService::class), libraryPath: $libraryPath);
    }

    public function test_media_source_ranking_stable_ties_custom_regex_and_binary_parity(): void
    {
        $this->requireWaveLibrary();
        $names = ['film.rar', 'film.part001.rar', 'film.part2.rar', 'film.7z.001', 'film.7z.002', 'film.zip', 'movie.mkv', 'sample/movie.mkv', 'notsample.mkv', 'sample-film.mp4', 'cover.JPG', 'thumb.webp', 'info.nfo', 'SAMPLE.mkv', "film.mkv\n", "film.rar\r\n", 'film.part0.rar', "dir\0sample.mkv", "\xff久.jpeg", 'movie.xyz'];
        $files = [];
        foreach ($names as $i => $name) {
            $files[] = new Par2FileEntry($name, $i % 3);
        }
        $random = new Randomizer(new Mt19937(446));
        for ($i = 0; $i < 200; $i++) {
            $files[] = new Par2FileEntry($random->getBytes($random->getInt(1, 40)).$names[$i % count($names)], $random->getInt(0, 900000));
        }
        foreach ([null, '\\.(?:MKV|xyz)', '\\.mkv'] as $regex) {
            $resolver = $this->resolver(videoRegex: $regex);
            foreach ([[], $files, array_reverse($files), array_slice($files, 0, 17)] as $list) {
                MediaSourceResolver::useRustFfi(false);
                $expected = $resolver->wantedFiles($list);
                MediaSourceResolver::useRustFfi(true);
                self::assertSame($expected, $resolver->wantedFiles($list));
            }
        }
        $supported = false;
        $resolver = $this->resolver();
        $list = [new Par2FileEntry('movie.mkv', 100), new Par2FileEntry('sample.mkv', 5), new Par2FileEntry('sample.mkv', 5), new Par2FileEntry('movie.rar', 3)];
        self::assertSame($resolver->wantedFilesPhp($list), $resolver->wantedFilesNative($list, $supported));
        self::assertTrue($supported, 'Supported ranking must execute Rust.');
        $custom = $this->resolver(videoRegex: '\\.mkv');
        self::assertSame($custom->wantedFilesPhp($list), $custom->wantedFilesNative($list, $supported));
        self::assertFalse($supported, 'Unsupported regex retains PHP.');
    }

    public function test_mapping_ambiguity_verified_entries_segment_counts_and_boundaries(): void
    {
        $this->requireWaveLibrary();
        $mapper = new Par2FileMapper;
        $random = new Randomizer(new Mt19937(446));
        for ($case = 0; $case < 150; $case++) {
            $sources = [null, ['size' => 0, 'segments' => ['x']], ['size' => 100, 'segments' => []], ['size' => 100, 'segments' => ['x'], 'filecount' => 2]];
            $files = [];
            for ($i = 0; $i < 20; $i++) {
                $size = $random->getInt(1, 1000000);
                $sources['source-'.$i] = ['size' => (string) ($i % 2 === 0 ? $size : (int) ceil($size / 0.95)), 'segments' => array_fill(0, $random->getInt(1, 4), 'article')];
                $files[] = new Par2FileEntry('movie-'.$i.'.mkv', $size);
            }
            if ($case % 3 === 0) {
                $files[] = $files[0];
            }
            $verified = $case % 2 === 0 ? [4 => $files[0], 7 => $files[3]] : [];
            $part = [null, 0, -1, 250000][$case % 4];
            Par2FileMapper::useRustFfi(false);
            $expected = $mapper->map($sources, $files, $part, $verified);
            Par2FileMapper::useRustFfi(true);
            $actual = $mapper->map($sources, $files, $part, $verified);
            self::assertEquals($expected, $actual);
            self::assertSame(array_keys($expected), array_keys($actual));
            foreach ($expected as $source => $mapped) {
                self::assertSame($mapped->file, $actual[$source]->file);
                self::assertSame($mapped->confidence, $actual[$source]->confidence);
            }
            $supported = false;
            self::assertEquals($expected, $mapper->mapNative($sources, $files, $part, $verified, $supported));
            self::assertTrue($supported, 'Mapping must execute Rust.');
            foreach (array_slice($files, 0, 3) as $file) {
                self::assertSame($mapper->candidateIndexesPhp($sources, $file, $part), $mapper->candidateIndexesNative($sources, $file, $part, $supported));
                self::assertTrue($supported, 'Candidate indexes must execute Rust.');
            }
        }
        foreach ([0, 1, 900, 1001, 1002, PHP_INT_MAX] as $size) {
            $file = new Par2FileEntry('boundary', $size);
            $sources = [['size' => 1000, 'segments' => ['x']], ['size' => PHP_INT_MAX, 'segments' => ['y']]];
            self::assertSame($mapper->candidateIndexesPhp($sources, $file), $mapper->candidateIndexes($sources, $file));
            self::assertEquals($mapper->mapPhp($sources, [$file]), $mapper->map($sources, [$file]));
        }
    }

    public function test_missing_library_and_missing_symbols_fall_back(): void
    {
        $this->requireWaveLibrary();
        $missing = $this->directory.'/missing.so';
        $supported = true;
        self::assertSame(ArchiveCandidate::fileNamePhp('FILM.RAR'), ArchiveCandidate::fileNameNative('FILM.RAR', $missing, $supported));
        self::assertFalse($supported);
        self::assertSame(ArchiveCandidate::volumeSetKeyPhp('FILM.RAR'), ArchiveCandidate::volumeSetKeyNative('FILM.RAR', $missing, $supported));
        self::assertFalse($supported);
        $files = [new Par2FileEntry('sample.mkv', 100)];
        $sources = [['size' => 105, 'segments' => ['x']]];
        Par2FileMapper::useRustFfi(true);
        $mapper = new Par2FileMapper($missing);
        self::assertEquals($mapper->mapPhp($sources, $files), $mapper->map($sources, $files));
        self::assertSame($mapper->candidateIndexesPhp($sources, $files[0]), $mapper->candidateIndexes($sources, $files[0]));
        MediaSourceResolver::useRustFfi(true);
        $resolver = $this->resolver($missing);
        self::assertSame($resolver->wantedFilesPhp($files), $resolver->wantedFiles($files));

        // Inject a handle with none of the requested exports to exercise call failures.
        $ffi = new ReflectionProperty(ImageInspector::class, 'ffi');
        $resolved = new ReflectionProperty(ImageInspector::class, 'ffiResolved');
        $original = [$ffi->getValue(), $resolved->getValue()];
        try {
            $ffi->setValue(null, FFI::cdef('int abs(int);'));
            $resolved->setValue(null, true);
            ImageInspector::useRustFfi(true);
            self::assertTrue(ImageInspector::isCompleteTail(IMAGETYPE_JPEG, 20, str_repeat('x', 18)."\xff\xd9"));
        } finally {
            $ffi->setValue(null, $original[0]);
            $resolved->setValue(null, $original[1]);
        }
    }

    public function test_all_classes_fall_back_with_ffi_disabled(): void
    {
        // A new process gives readonly DTOs clean static-local binding caches.
        $script = <<<'PHP'
            require getcwd().'/vendor/autoload.php';
            use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
            use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
            use App\Services\AdditionalProcessing\Media\ImageInspector;
            use App\Services\AdditionalProcessing\Media\MediaSourceResolver;
            use App\Services\AdditionalProcessing\Media\Par2FileMapper;
            use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
            use App\Services\AdditionalProcessing\UsenetDownloadService;
            foreach ([ArchiveCandidate::class, Par2FileEntry::class, ImageInspector::class, MediaSourceResolver::class, Par2FileMapper::class] as $class) {
                $class::useRustFfi(true);
            }
            $config = (new ReflectionClass(ProcessingConfiguration::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty($config, 'videoFileRegex'))->setValue($config, '\\.(MKV|MP4)');
            $download = (new ReflectionClass(UsenetDownloadService::class))->newInstanceWithoutConstructor();
            $resolver = new MediaSourceResolver($config, $download);
            $mapper = new Par2FileMapper;
            $files = [new Par2FileEntry('sample.mkv', 100, 'abcdef')];
            $sources = [['size' => 105, 'segments' => ['article']]];
            $supported = true;
            $ok = ArchiveCandidate::fileNameNative('FILM.RAR', supported: $supported) === ArchiveCandidate::fileNamePhp('FILM.RAR') && !$supported;
            $ok = $ok && ArchiveCandidate::volumeSetKey('FILM.RAR') === ArchiveCandidate::volumeSetKeyPhp('FILM.RAR');
            $rows = [['name' => 'sample.mkv', 'size' => 100, 'hash_16K' => 'ABCDEF']];
            $ok = $ok && Par2FileEntry::listFromPar2InfoNative($rows, $supported) == Par2FileEntry::listFromPar2InfoPhp($rows) && !$supported;
            $ok = $ok && ImageInspector::isCompleteTailNative(IMAGETYPE_JPEG, 16, "xxxxxxxxxxxxxx\xff\xd9", $supported) && !$supported;
            $ok = $ok && $resolver->wantedFilesNative($files, $supported) === $resolver->wantedFilesPhp($files) && !$supported;
            $ok = $ok && $mapper->mapNative($sources, $files, supported: $supported) == $mapper->mapPhp($sources, $files) && !$supported;
            $ok = $ok && $mapper->candidateIndexesNative($sources, $files[0], supported: $supported) === $mapper->candidateIndexesPhp($sources, $files[0]) && !$supported;
            echo $ok ? 'fallbacks-ok' : 'fallback-failed';
            PHP;
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=0', '-r', $script], dirname(__DIR__, 2));
        $process->mustRun();
        self::assertSame('fallbacks-ok', $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * Reproduce: cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml
     * php artisan test --compact tests/Unit/RustAdditionalDtoArchivePar2ImageInspectorSourceMapperW44ParityTest.php
     *
     * Frozen seed 446; eight variants; 50 warmups and 2,000 randomized paired
     * observations per class/workload, with parity checked outside each timing.
     * Timings include public dispatch, coercion, packing, FFI, result allocation
     * and image file I/O. Common/Uncommon: ordinary/long archive subjects;
     * normalization of 8/128 entries; JPEG/PNG/WebP files with 4/64 KiB tails;
     * ranking of 8/128 files; mapping 8/128 sources with segment/verified hints.
     * P99 uses nearest rank (20 tail observations), with order-statistic bands.
     * Raw pairs, randomized order, variant IDs, source/library hashes, runtime
     * and toolchain are saved outside the checkout in the printed /tmp artifact.
     * These local CPU/caller results do not establish production throughput.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks_and_defaults(): void
    {
        $this->requireWaveLibrary();
        $random = new Randomizer(new Mt19937(446));
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
            self::assertSame($class::W44_RUST_FFI_DEFAULT, $class::usesRustFfi());
        }
        $hashes = [];
        $root = dirname(__DIR__, 2);
        foreach (self::CLASSES as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            self::assertNotFalse($file);
            $hashes[$file] = hash_file('sha256', $file);
        }
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'tests/Unit/RustAdditionalDtoArchivePar2ImageInspectorSourceMapperW44ParityTest.php'] as $file) {
            $hashes[$file] = hash_file('sha256', $root.'/'.$file);
        }
        $library = $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $metadata = [
            'utc' => gmdate('c'), 'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'base' => '7f1e9cb74957c6a5c2fbcca4120f4c34e42495f4', 'source_sha256' => $hashes,
            'library_sha256' => hash_file('sha256', $library), 'profile' => 'release opt-level=3 lto=true codegen-units=1',
            'php' => PHP_VERSION, 'os' => php_uname(), 'rustc' => trim((string) shell_exec('rustc --version')),
            'cargo' => trim((string) shell_exec('cargo --version')), 'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.memsize' : 'uname -m')),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
            'pairs' => 2000, 'warmups' => 50, 'seed' => 446, 'variants' => 8,
        ];
        $artifact = sys_get_temp_dir().'/w446-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW44.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
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
        $resolver = $this->resolver();
        $mapper = new Par2FileMapper;
        $inspector = new ImageInspector(new FrameQualityScorer(12, 24, 232), 12);
        for ($i = 0; $i < 8; $i++) {
            switch ($class) {
                case ArchiveCandidate::class:
                    $subject = $uncommon
                        ? '[01/99] - '.str_repeat('Long Release Name ', 24).$i.'.part001.rar - (01 of 99) yEnc (1/1234)'
                        : '"Movie.Title.2026.1080p-'.$i.'.part01.rar" yEnc (1/99)';
                    $calls[] = static fn () => [ArchiveCandidate::fileName($subject), ArchiveCandidate::volumeSetKey($subject)];
                    break;
                case Par2FileEntry::class:
                    $files = [];
                    for ($j = 0; $j < ($uncommon ? 128 : 8); $j++) {
                        $files[] = ['name' => ($uncommon ? str_repeat('Release.Group-', 12) : '').'movie-'.$i.'-'.$j.'.mkv', 'size' => (string) (100000 + $j * 123 + $i), 'hash_16K' => strtoupper(md5($i.'-'.$j))];
                    }
                    $calls[] = static fn () => Par2FileEntry::listFromPar2Info($files);
                    break;
                case ImageInspector::class:
                    $type = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP][$i % 3];
                    $length = ($uncommon ? 65536 : 4096) + $i;
                    $data = match ($type) {
                        IMAGETYPE_JPEG => str_repeat('j', $length)."\xff\xd9\0\r\n ",
                        IMAGETYPE_PNG => str_repeat('p', $length).'IEND'.str_repeat("\0", 8),
                        default => 'RIFF'.pack('V', $length + 4).'WEBP'.str_repeat('w', $length),
                    };
                    $path = $this->directory.'/bench-'.($uncommon ? 'uncommon' : 'common').'-'.$i;
                    file_put_contents($path, $data);
                    $calls[] = static fn () => $inspector->isComplete($path, $type);
                    break;
                case MediaSourceResolver::class:
                    $files = [];
                    for ($j = 0; $j < ($uncommon ? 128 : 8); $j++) {
                        $suffix = ['part01.rar', 'part02.rar', 'sample.mkv', 'mkv', 'jpg', 'nfo', 'mp4', '7z.001'][$j % 8];
                        $files[] = new Par2FileEntry(($uncommon ? str_repeat('Release.Name.', 10) : '').'movie-'.$i.'-'.$j.'.'.$suffix, 100000 + ($j * 17 + $i) % 5);
                    }
                    $calls[] = static fn () => $resolver->wantedFiles($files);
                    break;
                case Par2FileMapper::class:
                    $sources = $files = [];
                    for ($j = 0; $j < ($uncommon ? 128 : 8); $j++) {
                        $size = 100000 + $j * 20000 + $i;
                        $sources[] = ['size' => (int) ($size / 0.95), 'segments' => array_fill(0, (int) ceil($size / 100000), 'article-'.$j)];
                        $files[] = new Par2FileEntry('movie-'.$i.'-'.$j.'.mkv', $size);
                    }
                    $part = $uncommon ? 100000 : null;
                    $verified = $uncommon ? [0 => $files[0]] : [];
                    $calls[] = static fn () => [$mapper->map($sources, $files, $part, $verified), $mapper->candidateIndexes($sources, $files[1], $part)];
                    break;
            }
        }

        return $calls;
    }
}
