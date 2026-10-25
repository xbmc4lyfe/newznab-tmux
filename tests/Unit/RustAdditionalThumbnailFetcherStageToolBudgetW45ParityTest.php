<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Release;
use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Services\AdditionalProcessing\Enums\VideoContainer;
use App\Services\AdditionalProcessing\Media\ArchivedMediaSlicer;
use App\Services\AdditionalProcessing\Media\FrameQualityScorer;
use App\Services\AdditionalProcessing\Media\MediaArticleFetcher;
use App\Services\AdditionalProcessing\Media\MediaSourceStage;
use App\Services\AdditionalProcessing\Media\MediaToolFactory;
use App\Services\AdditionalProcessing\Media\ThumbnailFrameSelector;
use App\Services\AdditionalProcessing\Media\VideoFrameGrabber;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\State\MediaByteBudget;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\Yenc\YencPart;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;
use Throwable;

final class RustAdditionalThumbnailFetcherStageToolBudgetW45ParityTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private const CLASSES = [ThumbnailFrameSelector::class, MediaArticleFetcher::class, MediaSourceStage::class, MediaToolFactory::class, MediaByteBudget::class];

    /**
     * Apple M5/macOS arm64, PHP 8.5.12, optimized Rust; seed 456, 2,000
     * pairs/workload. Common mean/p99 then Uncommon mean/p99 speedups.
     * Raw qualification: w456-benchmark-eb6f0c56db802f71.json in system tmp.
     * Every class fails at least one metric; PHP remains the default.
     */
    private const QUALIFICATION = [
        ThumbnailFrameSelector::class => [0.654, 0.688, 0.865, 0.609],
        MediaArticleFetcher::class => [0.615, 0.625, 1.119, 1.230],
        MediaSourceStage::class => [0.989, 3.037, 2.260, 1.917],
        MediaToolFactory::class => [0.490, 0.557, 0.520, 0.500],
        MediaByteBudget::class => [0.241, 0.271, 0.244, 0.270],
    ];

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            $class::useW45RustFfi(null);
            self::assertSame(min($speedups) >= 1.5, $class::W45_RUST_FFI_DEFAULT);
            self::assertSame($class::W45_RUST_FFI_DEFAULT, $class::usesW45RustFfi());
        }
    }

    private string $directory;

    private string|false $locale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locale = setlocale(LC_CTYPE, '0');
        self::assertNotFalse(setlocale(LC_CTYPE, 'C'));
        $this->directory = sys_get_temp_dir().'/w456-media-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::useW45RustFfi(null);
        }
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        if ($this->locale !== false) {
            setlocale(LC_CTYPE, $this->locale);
        }
        parent::tearDown();
    }

    public function test_independent_nullable_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertTrue((new ReflectionClass($class))->hasMethod('useW45RustFfi'));
            $class::useW45RustFfi(false);
            self::assertFalse($class::usesW45RustFfi());
            $class::useW45RustFfi(true);
            self::assertTrue($class::usesW45RustFfi());
            foreach (self::CLASSES as $other) {
                if ($class !== $other) {
                    self::assertSame($other::W45_RUST_FFI_DEFAULT, $other::usesW45RustFfi());
                }
            }
            $class::useW45RustFfi(null);
            self::assertSame($class::W45_RUST_FFI_DEFAULT, $class::usesW45RustFfi());
        }
    }

    public function test_thumbnail_windows_text_and_actual_selection_parity(): void
    {
        $path = $this->directory.'/video';
        file_put_contents($path, str_repeat('x', 1000));
        foreach ([null, 0.0, 0.002, 1.234, 100.0, 14400.123, -0.002] as $duration) {
            foreach ([null, 8000, 1234567] as $bitRate) {
                foreach ([null, MediaSourceHints::complete(), MediaSourceHints::head(10000, 1000), MediaSourceHints::head(null, 1000), MediaSourceHints::mid(), MediaSourceHints::head(PHP_INT_MAX, PHP_INT_MAX - 1)] as $hints) {
                    $selector = $this->selector($duration, $bitRate);
                    ThumbnailFrameSelector::useW45RustFfi(false);
                    $expected = $selector->window($path, null, $hints);
                    ThumbnailFrameSelector::useW45RustFfi(true);
                    self::assertSame($expected, $selector->window($path, null, $hints));
                    $supported = false;
                    self::assertSame($expected, $selector->windowNative($path, null, $hints, $supported));
                    self::assertTrue($supported, 'New window export must run.');
                }
            }
        }
        $random = new Randomizer(new Mt19937(456));
        for ($i = 0; $i < 500; $i++) {
            $duration = $random->getInt(0, 86400000) / 1000;
            $available = $random->getInt(1, 100000000);
            $hints = MediaSourceHints::head($random->getInt(1, 200000000), $available);
            $selector = new ThumbnailFrameSelector($this->makeConfig(['thumbnailFrameAttempts' => $random->getInt(1, 32)]), $this->grabber($duration, $random->getInt(1, 100000000)), new FrameQualityScorer(12, 24, 232));
            $supported = false;
            self::assertSame($selector->windowPhp($path, null, $hints), $selector->windowNative($path, null, $hints, $supported));
            self::assertTrue($supported);
        }
        foreach (['', '#EXTM3U', "\xef\xbb\xbf \r\n#extm3u\n", 'FfCoNcAt version 1.0', "\0#EXTM3U", "\v#EXTM3U", str_repeat(' ', 4096).'ffconcat', "\xffvideo"] as $head) {
            $supported = false;
            self::assertSame(ThumbnailFrameSelector::looksLikeTextPhp($head), ThumbnailFrameSelector::looksLikeTextNative($head, $supported));
            self::assertTrue($supported);
            foreach ([false, true] as $native) {
                ThumbnailFrameSelector::useW45RustFfi($native);
                self::assertSame(ThumbnailFrameSelector::looksLikeTextPhp($head), ThumbnailFrameSelector::looksLikeText($head));
            }
        }
        foreach ([false, true] as $native) {
            ThumbnailFrameSelector::useW45RustFfi($native);
            $grabber = $this->grabber(100.0, 8000);
            $selector = new ThumbnailFrameSelector($this->makeConfig(), $grabber, new FrameQualityScorer(12, 24, 232));
            file_put_contents($path, "#EXTM3U\nfile:///etc/passwd");
            self::assertNull($selector->select($path, $this->directory));
            self::assertSame([], $grabber->grabs);
            file_put_contents($path, "\x1a\x45\xdf\xa3".str_repeat('x', 1000));
            self::assertNull($selector->select($path, $this->directory, MediaSourceHints::complete()));
            self::assertSame([[25.0, 18.75], [0.0, 0.0]], $grabber->grabs);
        }
        foreach ([new MediaSourceHints(SourcePosition::Head, -1, 10), new MediaSourceHints(SourcePosition::Head, 0, 10)] as $hint) {
            $selector = $this->selector(100.0, 8000);
            ThumbnailFrameSelector::useW45RustFfi(false);
            $expected = $this->outcome(static fn () => $selector->window($path, null, $hint));
            ThumbnailFrameSelector::useW45RustFfi(true);
            self::assertSame($expected, $this->outcome(static fn () => $selector->window($path, null, $hint)));
        }
        $wide = new ThumbnailFrameSelector($this->makeConfig(['thumbnailFrameAttempts' => 4294967300]), $this->grabber(100.0, null), new FrameQualityScorer(12, 24, 232));
        self::assertSame($wide->windowPhp($path, null, null), $wide->window($path, null, null));
        // Unsupported attempts preserve PHP's exception rather than silently changing it.
        $zero = new ThumbnailFrameSelector($this->makeConfig(['thumbnailFrameAttempts' => 0]), $this->grabber(1.0, null), new FrameQualityScorer(12, 24, 232));
        foreach ([false, true] as $native) {
            ThumbnailFrameSelector::useW45RustFfi($native);
            self::assertSame(\DivisionByZeroError::class, $this->outcome(static fn () => $zero->window($path, null, null)));
        }
    }

    public function test_fetcher_segment_chunk_witness_and_real_append_parity(): void
    {
        foreach ([0, 1, 100, PHP_INT_MAX] as $count) {
            foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $from) {
                foreach ([-1, 0, 1, PHP_INT_MAX] as $bytes) {
                    $expected = MediaArticleFetcher::segmentStartPhp($count, $from, $bytes);
                    self::assertSame($expected, MediaArticleFetcher::segmentStartNative($count, $from, $bytes));
                    MediaArticleFetcher::useW45RustFfi(true);
                    self::assertSame($expected, MediaArticleFetcher::segmentStart($count, $from, $bytes));
                }
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 4, PHP_INT_MAX] as $length) {
            foreach ([PHP_INT_MIN, -5, 0, 2, PHP_INT_MAX] as $requested) {
                self::assertSame($length < 0 ? null : MediaArticleFetcher::chunkLengthPhp($length, $requested), MediaArticleFetcher::chunkLengthNative($length, $requested));
                self::assertSame(MediaArticleFetcher::chunkLengthPhp($length, $requested), MediaArticleFetcher::chunkLength($length, $requested));
            }
        }
        foreach (['movie.mkv', '久.mkv', '', '.mkv', '-video', 'a/b', 'a\\b', "bad\0.mkv", "\xff.mkv", str_repeat('x', 4096), str_repeat('x', 4097)] as $name) {
            self::assertSame(MediaArticleFetcher::isSafeWitnessNamePhp($name), MediaArticleFetcher::isSafeWitnessNameNative($name));
        }
        // Real append: truncation spends full offered article bytes; gaps stop after charging.
        foreach ($this->appendCases() as [$maximum, $limit, $expired, $gap, $expected, $spent]) {
            foreach ([false, true] as $native) {
                MediaArticleFetcher::useW45RustFfi($native);
                MediaByteBudget::useW45RustFfi($native);
                $path = $this->directory.'/append';
                @unlink($path);
                $download = $this->createMock(UsenetDownloadService::class);
                $download->method('downloadArticle')->willReturnCallback(static fn ($kind, string $id): array => [
                    'success' => true, 'data' => $id === 'a' ? 'AAAA' : 'BBBB',
                    'part' => new YencPart('movie.mkv', 8, null, $id === 'a' ? 1 : ($gap ? 9 : 5), $id === 'a' ? 4 : ($gap ? 12 : 8)),
                    'groupUnavailable' => false, 'error' => null,
                ]);
                $budget = new MediaByteBudget($limit);
                $written = (new MediaArticleFetcher($download))->append($this->context(), ['a', 'b'], -1, 0, $maximum, $path, $budget, hrtime(true) / 1e9 + ($expired ? -1 : 60), 4);
                self::assertSame(strlen($expected), $written);
                self::assertSame($expected, is_file($path) ? file_get_contents($path) : '');
                self::assertSame($spent, $budget->spent());
            }
        }
    }

    public function test_source_stage_inspection_and_order_parity(): void
    {
        foreach ([false, true] as $enabled) {
            foreach ([-1, 0, 30] as $seconds) {
                foreach (range(0, 63) as $flags) {
                    $config = $this->makeConfig(['processThumbnails' => $enabled, 'mediaStageMaxSeconds' => $seconds, 'mediaMainVideoFallback' => (bool) ($flags & 32)]);
                    $stage = $this->stage($config);
                    $context = $this->context();
                    $context->foundSample = (bool) ($flags & 1);
                    $context->releaseHasPassword = (bool) ($flags & 2);
                    $context->groupUnavailable = (bool) ($flags & 4);
                    $context->workPlan = new AdditionalWorkPlan(mainVideoSourceIndexes: ($flags & 8) ? [1] : []);
                    if ($flags & 16) {
                        $context->archivedMedia = [new ArchivedMediaEntry('movie.mkv', 1000, 0, 'head', 0, 3, 4, 1, false, null)];
                    }
                    $expected = $stage->hasWorkPhp($context);
                    MediaSourceStage::useW45RustFfi(true);
                    self::assertSame($expected, $stage->hasWorkNative($context));
                    self::assertSame($expected, $stage->hasWork($context));
                }
            }
        }
        $locale = setlocale(LC_CTYPE, '0');
        try {
            setlocale(LC_CTYPE, 'C');
            foreach ([[], ['part10', 'part2', 'Part01'], ['same', 'SAME', 'same'], ["a\0b", 'a'], ['久1', '久2', "\xff1"], ['part0001', 'part1', 'part01']] as $titles) {
                self::assertSame(MediaSourceStage::middleVolumeIndexPhp($titles), MediaSourceStage::middleVolumeIndex($titles));
            }
        } finally {
            if ($locale !== false) {
                setlocale(LC_CTYPE, $locale);
            }
        }
    }

    public function test_tool_configuration_and_budget_state_error_parity(): void
    {
        foreach ([false, '', '0', '/opt/bin/ffmpeg', '久 binary', "bin\0path"] as $mpeg) {
            foreach ([false, '', '0', '/opt/bin/ffprobe'] as $probe) {
                foreach ([-1, 0, 30, PHP_INT_MAX] as $timeout) {
                    $factory = new MediaToolFactory($this->makeConfig(['ffmpegPath' => $mpeg, 'ffprobePath' => $probe, 'mediaFfmpegTimeout' => $timeout]));
                    $expected = MediaToolFactory::driverConfigurationPhp($timeout, $mpeg, $probe);
                    self::assertSame($expected, MediaToolFactory::driverConfigurationNative($timeout, $mpeg, $probe));
                    MediaToolFactory::useW45RustFfi(true);
                    self::assertSame($expected, $factory->driverConfiguration());
                }
            }
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, 1024, PHP_INT_MAX] as $limit) {
            $observations = [];
            foreach ([false, true] as $native) {
                MediaByteBudget::useW45RustFfi($native);
                $budget = new MediaByteBudget($limit);
                $rows = [];
                foreach ([-1, 0, 1, 1024, PHP_INT_MAX] as $bytes) {
                    $rows[] = [$budget->spent(), $budget->allows($bytes), $budget->exhausted(), $this->outcome($budget->remaining(...)), $this->outcome(static fn () => $budget->clampRequestBytes($bytes)), $budget->utilization()];
                    $rows[] = $this->outcome(static fn () => $budget->spend($bytes));
                }
                $observations[] = $rows;
            }
            self::assertSame($observations[0], $observations[1]);
        }
        $budget = new MediaByteBudget(100);
        self::assertSame(100, $budget->remainingNative());
        self::assertSame(0.0, $budget->utilizationNative());
        self::assertSame(5, $budget->spentAfterNative(5));
    }

    public function test_missing_binding_falls_back_in_public_callers(): void
    {
        foreach (self::CLASSES as $class) {
            $ffi = new ReflectionProperty($class, 'ffi');
            $saved = $ffi->getValue();
            $resolved = (new ReflectionClass($class))->hasProperty('ffiResolved') ? new ReflectionProperty($class, 'ffiResolved') : null;
            $wasResolved = $resolved?->getValue();
            try {
                $class::useW45RustFfi(true);
                $resolved?->setValue(null, true);
                $ffi->setValue(null, $resolved !== null ? null : false);
                $call = $this->benchmarkCalls($class, false)[0];
                $class::useW45RustFfi(false);
                $expected = $call();
                $class::useW45RustFfi(true);
                self::assertSame($expected, $call());
            } finally {
                $ffi->setValue(null, $saved);
                $resolved?->setValue(null, $wasResolved);
                $class::useW45RustFfi(null);
            }
        }
    }

    /**
     * Protocol: seed 456, eight variants, 50 warmups and 2,000 pairs/workload.
     * Randomized paired order, identical fixtures, parity outside timing. Common:
     * 4 KiB media head/window, normal segment math/witness, eight source titles,
     * configured tools and a normal budget lifecycle. Uncommon: long leading
     * playlist whitespace, 4 KiB witness names/large segments, 128 titles, empty
     * tool paths and near-limit budgets. Includes public dispatch, allocation,
     * marshaling and file stat cost. Probe/NNTP/ffmpeg I/O is replaced by fixed
     * fixtures: these local CPU results do not establish production throughput.
     * Nearest-rank p99 (20 tail samples), approximate 95% order-statistic bands.
     * Raw pairs, hashes, runtime and toolchain are saved outside the checkout.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks_and_defaults(): void
    {
        $this->requireWaveBindings();
        $random = new Randomizer(new Mt19937(456));
        $records = $summary = [];
        foreach (self::CLASSES as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                $calls = $this->benchmarkCalls($class, $workload === 'Uncommon');
                $php = $rust = [];
                for ($i = -50; $i < 2000; $i++) {
                    $variant = $random->getInt(0, count($calls) - 1);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $class::useW45RustFfi($native);
                        $start = hrtime(true);
                        $results[(int) $native] = $calls[$variant]();
                        $elapsed[(int) $native] = hrtime(true) - $start;
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order, $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $n = count($php);
                $low = (int) floor((0.99 - 1.96 * sqrt(0.99 * 0.01 / $n)) * $n) - 1;
                $high = min($n - 1, (int) ceil((0.99 + 1.96 * sqrt(0.99 * 0.01 / $n)) * $n) - 1);
                $summary[$class][$workload] = [
                    'mean_speedup' => array_sum($php) / array_sum($rust),
                    'p99_speedup' => $php[(int) ceil($n * 0.99) - 1] / $rust[(int) ceil($n * 0.99) - 1],
                    'php_mean_ns' => array_sum($php) / $n, 'rust_mean_ns' => array_sum($rust) / $n,
                    'php_p99_ns' => $php[(int) ceil($n * 0.99) - 1], 'rust_p99_ns' => $rust[(int) ceil($n * 0.99) - 1],
                    'php_p99_band_ns' => [$php[$low], $php[$high]], 'rust_p99_band_ns' => [$rust[$low], $rust[$high]],
                ];
            }
            $class::useW45RustFfi(null);
            self::assertSame($class::W45_RUST_FFI_DEFAULT, $class::usesW45RustFfi());
        }
        $hashes = [];
        $root = dirname(__DIR__, 2);
        foreach (self::CLASSES as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            self::assertNotFalse($file);
            $hashes[$file] = hash_file('sha256', $file);
        }
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'vendor/composer/installed.json', 'tests/Unit/RustAdditionalThumbnailFetcherStageToolBudgetW45ParityTest.php'] as $file) {
            $hashes[$file] = hash_file('sha256', $root.'/'.$file);
        }
        $library = $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $metadata = [
            'utc' => gmdate('c'), 'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'base' => '4d202c888e0fc255db09ed049d1171cec87fadf9', 'source_sha256' => $hashes,
            'library_sha256' => hash_file('sha256', $library), 'profile' => 'release opt-level=3 lto=true codegen-units=1',
            'php' => PHP_VERSION, 'os' => php_uname(), 'rustc' => trim((string) shell_exec('rustc --version')),
            'cargo' => trim((string) shell_exec('cargo --version')), 'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.memsize' : 'uname -m')),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
            'locale' => setlocale(LC_CTYPE, '0'), 'pairs' => 2000, 'warmups' => 50, 'seed' => 456, 'variants' => 8,
        ];
        $artifact = sys_get_temp_dir().'/w456-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW45.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (self::CLASSES as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertFalse($class::usesW45RustFfi() && ! $eligible, $class.' needs >=1.5x mean/p99 on both workloads');
        }
    }

    /** @return list<\Closure(): mixed> */
    private function benchmarkCalls(string $class, bool $uncommon): array
    {
        $calls = [];
        for ($i = 0; $i < 8; $i++) {
            switch ($class) {
                case ThumbnailFrameSelector::class:
                    $path = $this->directory.'/bench-'.$i;
                    if (! is_file($path)) {
                        file_put_contents($path, str_repeat('v', 4096));
                    }
                    $head = $uncommon ? str_repeat(' ', 4096 + $i).'#EXTM3U' : "\x1a\x45\xdf\xa3".str_repeat('v', 4092);
                    $selector = $this->selector(100.0 + $i, 8000);
                    $hints = $uncommon ? MediaSourceHints::head(null, 0) : MediaSourceHints::head(100000, 1000 + $i);
                    $calls[] = static fn () => [$selector->window($path, null, $hints), ThumbnailFrameSelector::looksLikeText($head)];
                    break;
                case MediaArticleFetcher::class:
                    $name = ($uncommon ? str_repeat('movie-', 670) : 'Movie.Release.').$i.'.mkv';
                    $count = $uncommon ? 100000 + $i : 100 + $i;
                    $from = $uncommon ? -$i : $i;
                    $max = $uncommon ? PHP_INT_MAX - $i : 1048576;
                    $calls[] = static fn () => [MediaArticleFetcher::segmentStart($count, $from, $max), MediaArticleFetcher::chunkLength($max, 4096 + $i), MediaArticleFetcher::isSafeWitnessName($name)];
                    break;
                case MediaSourceStage::class:
                    $stage = $this->stage($this->makeConfig(['processThumbnails' => true, 'mediaMainVideoFallback' => true]));
                    $context = $this->context();
                    $context->workPlan = new AdditionalWorkPlan(mainVideoSourceIndexes: [1]);
                    $titles = [];
                    for ($j = 0; $j < ($uncommon ? 128 : 8); $j++) {
                        $titles[] = 'Movie.'.($i + 1).'.part'.(($j * 13) % 139).'.rar';
                    }
                    $calls[] = static fn () => [$stage->hasWork($context), MediaSourceStage::middleVolumeIndex($titles)];
                    break;
                case MediaToolFactory::class:
                    $factory = new MediaToolFactory($this->makeConfig(['ffmpegPath' => $uncommon ? ['', false][$i % 2] : '/usr/local/bin/ffmpeg', 'ffprobePath' => $uncommon ? false : '/usr/local/bin/ffprobe', 'mediaFfmpegTimeout' => 30 + $i]));
                    $calls[] = $factory->driverConfiguration(...);
                    break;
                case MediaByteBudget::class:
                    $limit = $uncommon ? PHP_INT_MAX - $i : 16777216 + $i;
                    $calls[] = static function () use ($limit, $i): array {
                        $budget = new MediaByteBudget($limit);
                        $allowed = $budget->allows(1048576 + $i);
                        $budget->spend(1048576 + $i);

                        return [$allowed, $budget->spent(), $budget->remaining(), $budget->exhausted(), $budget->clampRequestBytes(8388608), $budget->utilization()];
                    };
                    break;
            }
        }

        return $calls;
    }

    /** @return list<array{int, int, bool, bool, string, int}> */
    private function appendCases(): array
    {
        return [[6, 100, false, false, 'AAAABB', 8], [8, 4, false, false, 'AAAA', 4], [8, 100, true, false, '', 0], [8, 100, false, true, 'AAAA', 8], [0, 100, false, false, '', 0]];
    }

    private function requireWaveBindings(): void
    {
        self::assertTrue(extension_loaded('FFI'));
        $supported = false;
        ThumbnailFrameSelector::looksLikeTextNative('#EXTM3U', $supported);
        self::assertTrue($supported);
        self::assertSame(1, MediaArticleFetcher::segmentStartNative(4, 1, 100));
        self::assertSame(4, MediaArticleFetcher::chunkLengthNative(8, 4));
        MediaSourceStage::useW45RustFfi(true);
        self::assertTrue($this->stage($this->makeConfig(['processThumbnails' => true, 'mediaMainVideoFallback' => true]))->hasWorkNative($this->sourceContext()));
        $supported = false;
        self::assertSame(1, MediaSourceStage::middleVolumeIndexNative(['part1', 'part2'], $supported));
        self::assertTrue($supported, 'Benchmark sorting must execute Rust.');
        self::assertNotNull(MediaToolFactory::additionalFfi());
        self::assertSame(100, (new MediaByteBudget(100))->remainingNative());
        MediaSourceStage::useW45RustFfi(null);
    }

    private function sourceContext(): ReleaseProcessingContext
    {
        $context = $this->context();
        $context->workPlan = new AdditionalWorkPlan(mainVideoSourceIndexes: [1]);

        return $context;
    }

    private function selector(?float $duration, ?int $bitRate): ThumbnailFrameSelector
    {
        return new ThumbnailFrameSelector($this->makeConfig(), $this->grabber($duration, $bitRate), new FrameQualityScorer(12, 24, 232));
    }

    private function grabber(?float $duration, ?int $bitRate): W456FrameGrabber
    {
        return new W456FrameGrabber($duration, $bitRate);
    }

    private function stage(ProcessingConfiguration $config): MediaSourceStage
    {
        return new MediaSourceStage($config, $this->createStub(MediaExtractionService::class), new MediaArticleFetcher($this->createStub(UsenetDownloadService::class)), new ArchivedMediaSlicer($config), $this->createStub(ConsoleOutputService::class));
    }

    private function context(): ReleaseProcessingContext
    {
        return new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'wave45']));
    }

    private function outcome(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $error) {
            return $error::class;
        }
    }
}

final class W456FrameGrabber implements VideoFrameGrabber
{
    /** @var list<array{0: float, 1: float}> */
    public array $grabs = [];

    public function __construct(private readonly ?float $duration, private readonly ?int $bitRate) {}

    public function grab(string $videoPath, ?VideoContainer $container, float $from, float $spacing, int $count, string $outputDirectory): array
    {
        $this->grabs[] = [$from, $spacing];

        return [];
    }

    public function probe(string $videoPath, ?VideoContainer $container): array
    {
        return ['duration' => $this->duration, 'bitRate' => $this->bitRate];
    }
}
