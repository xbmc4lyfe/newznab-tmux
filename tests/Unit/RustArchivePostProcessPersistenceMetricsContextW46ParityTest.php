<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Release;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ProcessingMetrics;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\ArchiveProcessingService;
use App\Services\PostProcessService;
use Closure;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

final class RustArchivePostProcessPersistenceMetricsContextW46ParityTest extends TestCase
{
    private const CLASSES = [ArchiveProcessingService::class, PostProcessService::class, PersistenceMetricsCollector::class, ProcessingMetrics::class, ReleaseProcessingContext::class];

    /**
     * Apple M5/macOS arm64 (shared host, load ~6), PHP 8.5.12 CLI without
     * opcache, release Rust; seed 466, 2,000 pairs/workload. Common mean/p99
     * then Uncommon mean/p99 speedups from w466-benchmark-ed974297d5cd0a3a.json
     * in system tmp. Uncommon batches reach ~2x, but fixed FFI and marshaling
     * cost keeps every Common path below 1.5x on at least one metric; the
     * persistence and stage-summary Common p99 ratios also crossed the gate in
     * both directions across repeated runs. PHP remains every default.
     */
    private const QUALIFICATION = [
        ArchiveProcessingService::class => [1.308, 0.991, 2.270, 1.797],
        PostProcessService::class => [1.007, 1.154, 2.100, 1.537],
        PersistenceMetricsCollector::class => [1.579, 1.233, 2.293, 1.825],
        ProcessingMetrics::class => [1.520, 1.293, 2.105, 1.521],
        ReleaseProcessingContext::class => [0.849, 0.817, 2.020, 1.897],
    ];

    /** @var array<class-string, bool> */
    private array $legacyToggles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->legacyToggles = [
            ArchiveProcessingService::class => ArchiveProcessingService::$useRustFfi,
            PostProcessService::class => PostProcessService::usesRustFfi(),
            PersistenceMetricsCollector::class => PersistenceMetricsCollector::$useRustFfi,
            ProcessingMetrics::class => ProcessingMetrics::usesRustFfi(),
            ReleaseProcessingContext::class => (bool) (new ReflectionProperty(ReleaseProcessingContext::class, 'useRustFfi'))->getValue(),
        ];
    }

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW466RustFfi();
        }
        parent::tearDown();
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            $class::resetW466RustFfi();
            self::assertSame(min($speedups) >= 1.5, $class::W466_RUST_FFI_DEFAULT, $class);
            self::assertSame($class::W466_RUST_FFI_DEFAULT, $class::usesW466RustFfi());
        }
    }

    public function test_independent_nullable_toggles_and_reset(): void
    {
        foreach (self::CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            foreach (['useW466RustFfi', 'usesW466RustFfi', 'resetW466RustFfi'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
            $class::useW466RustFfi(false);
            self::assertFalse($class::usesW466RustFfi());
            $class::useW466RustFfi(true);
            self::assertTrue($class::usesW466RustFfi());
            foreach (self::CLASSES as $other) {
                if ($other !== $class) {
                    self::assertSame($other::W466_RUST_FFI_DEFAULT, $other::usesW466RustFfi());
                }
            }
            $class::useW466RustFfi(null);
            self::assertSame($class::W466_RUST_FFI_DEFAULT, $class::usesW466RustFfi());
            $class::useW466RustFfi(true);
            $class::resetW466RustFfi();
            self::assertNull((new ReflectionProperty($class, 'w466RustFfi'))->getValue());
            self::assertNull((new ReflectionProperty($class, 'w466Ffi'))->getValue());
        }
        // Existing toggles stay untouched by the Wave 46.6 switches.
        self::assertSame($this->legacyToggles[ArchiveProcessingService::class], ArchiveProcessingService::$useRustFfi);
        self::assertSame($this->legacyToggles[PostProcessService::class], PostProcessService::usesRustFfi());
        self::assertSame($this->legacyToggles[PersistenceMetricsCollector::class], PersistenceMetricsCollector::$useRustFfi);
        self::assertSame($this->legacyToggles[ProcessingMetrics::class], ProcessingMetrics::usesRustFfi());
        self::assertSame($this->legacyToggles[ReleaseProcessingContext::class], (new ReflectionProperty(ReleaseProcessingContext::class, 'useRustFfi'))->getValue());
    }

    public function test_archive_file_summary_parity(): void
    {
        $this->requireWaveBindings();
        $names = ['', 'noext', 'a.', '.mkv', 'Movie.2024.MKV', 'x.R01', 'x.r1', 'x.r001', 'x.001', 'x.0001', 'x.r0a', 'dir.v1/file', "x.mkv\n", 'файл.Mp4', "\xff.jpg",
            'x.7Z', 'x.Par2', 'a.tar.gz', 'sample.m2ts', 'cover.JPEG', 'x.webm', 'x.ts', 'x.flac', 'x.ape', 'info.NFO', 'x.diz', 'x.sfv', 'x.txt', 'x.zip', 'x.wma', 'x.bmp', 'x.gif',
            'x.png', 'x.webp', 'x.ogg', 'x.wav', 'x.aac', 'x.m4a', 'x.mp3', 'x.vob', 'x.mpeg', 'x.mpg', 'x.mov', 'x.wmv', 'x.m4v', 'x.avi', 'x.mkv.', 'x.MKVX', 'R00', '123', 'x.R99', 'x.999'];
        foreach ($names as $name) {
            $files = [['name' => $name, 'size' => 1, 'pass' => 0, 'compressed' => 1]];
            $this->assertArchiveParity($files);
            self::assertSame(ArchiveProcessingService::fileNameClassPhp($name), array_search(1, array_slice(ArchiveProcessingService::summarizeFileListNative(...$files) ?? [], 4), true), $name);
        }
        $lists = [
            [],
            [[]],
            [['name' => null, 'size' => null, 'pass' => null, 'compressed' => null]],
            [['name' => 42, 'size' => '12abc'], ['name' => 1.5, 'size' => 1.9], ['name' => true, 'size' => true], ['size' => -5]],
            [['name' => 'a.rar', 'size' => PHP_INT_MAX], ['name' => 'b.r00', 'size' => PHP_INT_MIN]],
            [['pass' => '0'], ['pass' => ''], ['pass' => 'abc'], ['pass' => []], ['pass' => [0]], ['pass' => 0.0], ['pass' => 0.1], ['pass' => false], ['pass' => true], ['compressed' => '']],
            ['a' => ['name' => 'named.mkv', 'size' => 3], 'b' => ['name' => 'x.nfo']],
            [['NAME' => 'case.mkv', 'Size' => 3]],
        ];
        foreach ($lists as $files) {
            $this->assertArchiveParity($files);
        }
        $random = new Randomizer(new Mt19937(466));
        $extensions = ['rar', 'r00', 'R12', '001', 'mkv', 'MP4', 'nfo', 'jpg', 'sfv', 'par2', 'mp3', 'txt', 'bin', '', '7z'];
        for ($i = 0; $i < 300; $i++) {
            $files = [];
            for ($j = 0, $n = $random->getInt(0, 64); $j < $n; $j++) {
                $file = ['name' => 'Release.'.bin2hex($random->getBytes($random->getInt(1, 6))).'.'.$extensions[$random->getInt(0, count($extensions) - 1)], 'size' => $random->getInt(-1000, 1 << 40), 'pass' => $random->getInt(0, 3) === 0 ? 1 : 0, 'compressed' => $random->getInt(0, 2) === 0 ? '' : $random->getInt(0, 1)];
                if ($random->getInt(0, 9) === 0) {
                    unset($file[['name', 'size', 'pass', 'compressed'][$random->getInt(0, 3)]]);
                }
                $files[] = $file;
            }
            $this->assertArchiveParity($files);
        }
        // Overflowing totals widen to float in PHP; NUL bytes break the joined layout.
        foreach ([[['size' => PHP_INT_MAX], ['size' => 1]], [['size' => PHP_INT_MIN], ['size' => -1]], [['name' => "a\0b.mkv"]], [['name' => "x.mkv\0"], ['name' => 'y']]] as $files) {
            self::assertNull(ArchiveProcessingService::summarizeFileListNative(...$files));
            ArchiveProcessingService::useW466RustFfi(true);
            self::assertSame(ArchiveProcessingService::summarizeFileListPhp(...$files), ArchiveProcessingService::summarizeFileList(...$files));
        }
        self::assertIsFloat(ArchiveProcessingService::summarizeFileList(['size' => PHP_INT_MAX], ['size' => 1])['bytes']);
    }

    public function test_tv_lookup_mode_summary_parity(): void
    {
        $this->requireWaveBindings();
        $values = ['0', '1', '2', '3', '-1', ' 2', '2 ', "\t2\n", "\v2\f", '2.0', '2.5', '2.9', '1e0', '2e0', '.2e1', '2E+0', '20e-1', '0x2', 'abc', '', ' ', '+2', '++2', '-+2', '2e', 'e2', '2.', '.', '-.5', '1_000',
            '9223372036854775807', '9223372036854775808', '-9223372036854775808', '-9223372036854775809', '1e1000', '-1e1000', '1e18', '1e19', '-1e19', '0002', '00', '-0', '2e-400', "2\u{a0}", '٢', null, 0, 1, 2, 3, -2, PHP_INT_MAX, PHP_INT_MIN];
        foreach ([0, 1, 2, 5, -1, PHP_INT_MIN] as $default) {
            foreach ($values as $value) {
                $this->assertTvParity($default, [$value]);
            }
            $this->assertTvParity($default, $values);
            $this->assertTvParity($default, []);
        }
        $this->assertTvParity(1, ['x' => '2', 'y' => null]);
        $random = new Randomizer(new Mt19937(466));
        $alphabet = '0123456789 +-.eE'."\t".'x';
        for ($i = 0; $i < 500; $i++) {
            $batch = [];
            for ($j = 0, $n = $random->getInt(0, 32); $j < $n; $j++) {
                $length = $random->getInt(0, 8);
                $text = '';
                for ($k = 0; $k < $length; $k++) {
                    $text .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
                }
                $batch[] = $random->getInt(0, 7) === 0 ? $random->getInt(-3, 4) : $text;
            }
            $this->assertTvParity($random->getInt(-1, 3), $batch);
        }
        self::assertNull(PostProcessService::summarizeTvLookupModesNative(1, "2\0", '1'));
        PostProcessService::useW466RustFfi(true);
        self::assertSame(['skip' => 0, 'all' => 2, 'renamed' => 0, 'other' => 0], PostProcessService::summarizeTvLookupModes(1, "2\0", '1'));
    }

    public function test_persistence_event_batch_parity(): void
    {
        $this->requireWaveBindings();
        $rows = [[0, 0, 1.25], [1, 7, 0.0], [1, 8, 0.0], [2, 7, 0.0], [2, -7, 0.0], [0, 99, -0.5], [0, 7, INF], ['1', '7', '2.5'], [2, 7.9, 0], [0, 0, 1e308], [0, 0, 1e308], [0, 0, -0.0], [1, 7, 0, 'extra']];
        foreach ([null, 7, 0, -7] as $scope) {
            foreach ([[0, 0.0, 0, 0], [5, 1.5, 3, 4], [PHP_INT_MAX - 3, -0.0, PHP_INT_MAX - 3, PHP_INT_MAX - 3]] as $counters) {
                $this->assertEventParity($scope, $counters, $rows);
                foreach ($rows as $row) {
                    $this->assertEventParity($scope, $counters, [$row]);
                }
                $this->assertEventParity($scope, $counters, []);
            }
        }
        $this->assertEventParity(7, [0, 0.0, 0, 0], ['a' => [0, 7, 1.0], 'b' => [1, 7, 0.0]]);
        $random = new Randomizer(new Mt19937(466));
        for ($i = 0; $i < 500; $i++) {
            $batch = [];
            for ($j = 0, $n = $random->getInt(0, 64); $j < $n; $j++) {
                $batch[] = [$random->getInt(0, 2), $random->getInt(5, 9), $random->getInt(0, 1000000) / 997];
            }
            $this->assertEventParity($random->getInt(0, 1) === 0 ? null : 7, [$random->getInt(0, 100), $random->getInt(0, 1000) / 7.0, $random->getInt(0, 100), $random->getInt(0, 100)], $batch);
        }
        // Overflow, unknown events, int milliseconds totals and short rows stay in PHP.
        self::assertNull(PersistenceMetricsCollector::applyEventsNative(7, [PHP_INT_MAX, 0.0, 0, 0], [0, 7, 1.0]));
        self::assertNull(PersistenceMetricsCollector::applyEventsNative(7, [0, 0.0, 0, PHP_INT_MAX], [2, 7, 0.0]));
        self::assertNull(PersistenceMetricsCollector::applyEventsNative(7, [0, 0.0, 0, 0], [0, 7, 1.0], [3, 7, 0.0]));
        self::assertNull(PersistenceMetricsCollector::applyEventsNative(7, [0, 0.0, 0, 0], [4294967296, 7, 0.0]));
        self::assertNull(PersistenceMetricsCollector::applyEventsNative(7, [0, 0, 0, 0], [0, 7, 1.0]));
        self::assertNull(PersistenceMetricsCollector::applyEventsNative(7, [0, 0.0, 0, 0], [0, 7]));
        self::assertNull(PersistenceMetricsCollector::applyEventsNative(7, [0, 0.0, 0, 0], ['event' => 0, 'id' => 7, 'ms' => 1.0]));
        self::assertIsFloat(PersistenceMetricsCollector::applyEvents(7, [PHP_INT_MAX, 0.0, 0, 0], [0, 7, 1.0])[0]);
        foreach ([false, true] as $native) {
            PersistenceMetricsCollector::useW466RustFfi($native);
            self::assertSame(\InvalidArgumentException::class, $this->outcome(static fn () => PersistenceMetricsCollector::applyEvents(7, [0, 0.0, 0, 0], [0, 7, 1.0], [3, 7, 0.0])));
            self::assertSame([0, 0, 0, 0], PersistenceMetricsCollector::applyEvents(7, [0, 0, 0, 0], [1, 8, 0.0]));
        }
    }

    public function test_stage_summary_parity(): void
    {
        $this->requireWaveBindings();
        $values = [0, 1, -1, 999999999, 1000000000, 2000000000, -2000000000, 1500000, 123456789012, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 999999999, 9007199254740993000, 4999999, 5000000, 500, -500];
        $cases = [[], ['' => 0], [0 => 5, 7 => 9], ['stage=one' => 1, 'two words' => 2], ['久' => 3]];
        foreach ($values as $value) {
            $cases[] = ['stage' => $value];
        }
        $cases[] = array_combine(array_map(static fn (int $i): string => 'stage_'.$i, array_keys($values)), $values);
        foreach (ProcessingStage::cases() as $index => $stage) {
            $cases[count($cases) - 1][$stage->value] = $index * 123456789;
        }
        foreach ($cases as $nanoseconds) {
            $this->assertStageParity($nanoseconds);
        }
        $random = new Randomizer(new Mt19937(466));
        for ($i = 0; $i < 500; $i++) {
            $nanoseconds = [];
            for ($j = 0, $n = $random->getInt(0, 20); $j < $n; $j++) {
                $nanoseconds['s'.$random->getInt(0, 30)] = $random->getInt(0, 3) === 0 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $random->getInt(0, 100) * 1000000000 + $random->getInt(-1, 1) * $random->getInt(0, 999999999);
            }
            $this->assertStageParity($nanoseconds);
        }
        self::assertNull(ProcessingMetrics::summaryFromNanosecondsNative(["a\0b" => 1]));
        self::assertNull(ProcessingMetrics::summaryFromNanosecondsNative(['a' => 1.5]));
        // Non-integer totals keep PHP's caller-dependent int conversion, so Rust never sees one.
        foreach ([['a' => '5'], ['a' => true], ['a' => 2.0], ['a' => null], ['a' => [1]]] as $invalid) {
            self::assertNull(ProcessingMetrics::summaryFromNanosecondsNative($invalid));
            $outcomes = [];
            foreach ([false, true] as $native) {
                ProcessingMetrics::useW466RustFfi($native);
                /** @phpstan-ignore argument.type */
                $outcomes[] = $this->outcome(static fn () => ProcessingMetrics::summaryFromNanoseconds($invalid));
            }
            self::assertSame($outcomes[0], $outcomes[1]);
        }
        // The instance summary line follows the Wave 46.6 switch on real stage totals.
        $metrics = new ProcessingMetrics;
        (new ReflectionProperty(ProcessingMetrics::class, 'stageNanoseconds'))->setValue($metrics, ['download' => 2000000000, 'media' => 1234567, 'par2' => -1]);
        ProcessingMetrics::useW466RustFfi(false);
        $expected = $metrics->summaryLine();
        self::assertSame('download=2.000000s media=0.001235s par2=-0.000000s', $expected);
        ProcessingMetrics::useW466RustFfi(true);
        self::assertSame($expected, $metrics->summaryLine());
        $metrics->measure(ProcessingStage::cases()[0], static fn (): int => 1);
        ProcessingMetrics::useW466RustFfi(false);
        $expected = $metrics->summaryLine();
        ProcessingMetrics::useW466RustFfi(true);
        self::assertSame($expected, $metrics->summaryLine());
    }

    public function test_naming_witness_batch_parity(): void
    {
        $this->requireWaveBindings();
        $long = str_repeat('a', 4096);
        $batches = [
            [],
            [['name' => 'Show.S01E01.mkv'], ['name' => 'Show.S01E02.mkv'], ['name' => 'Show.S01E01.mkv']],
            [['name' => 'ok.mkv'], ['name' => "bad\xff.mkv"], ['name' => 'after.mkv']],
            [['name' => 'ok.mkv'], ['name' => ''], ['name' => 'after.mkv']],
            [['name' => $long], ['name' => $long.'b']],
            [['name' => '123'], ['name' => '0'], ['name' => '-5'], ['name' => '123']],
            [['name' => '久しぶり.mkv'], ['name' => "\u{1F600}.mkv"], ['name' => "\xed\xa0\x80.mkv"]],
            array_map(static fn (int $i): array => ['name' => 'n'.$i], range(0, 300)),
            array_map(static fn (int $i): array => ['name' => str_repeat(chr(97 + $i % 26), 1000).$i], range(0, 40)),
            ['x' => ['name' => 'keyed.mkv', 'size' => 1], 'y' => ['name' => 'keyed.mkv']],
        ];
        foreach ($batches as $files) {
            $this->assertWitnessParity(static fn (ReleaseProcessingContext $context): null => null, [$files, $files]);
            $this->assertWitnessParity(static function (ReleaseProcessingContext $context): null {
                foreach (['Show.S01E01.mkv', '123', 'n5'] as $name) {
                    $context->archiveNamingWitnesses[$name] = true;
                }
                $context->archiveFileNames = ['n5', 'listed-only.mkv'];
                (new ReflectionProperty(ReleaseProcessingContext::class, 'namingWitnessBytes'))->setValue($context, 32000);

                return null;
            }, [$files]);
            $this->assertWitnessParity(static function (ReleaseProcessingContext $context): null {
                $context->namingWitnessesOmitted = true;

                return null;
            }, [$files]);
        }
        $random = new Randomizer(new Mt19937(466));
        $pool = ['Show.S01E01.mkv', 'Show.S01E02.mkv', 'sample.mkv', '42', 'cover.jpg', "bad\xc3", '', '久.mkv', str_repeat('z', 3000)];
        for ($i = 0; $i < 300; $i++) {
            $calls = [];
            for ($c = 0, $calls_n = $random->getInt(1, 3); $c < $calls_n; $c++) {
                $files = [];
                for ($j = 0, $n = $random->getInt(0, 40); $j < $n; $j++) {
                    $files[] = ['name' => $random->getInt(0, 3) === 0 ? $pool[$random->getInt(0, count($pool) - 1)] : 'Release.'.$random->getInt(0, 60).'.rar'];
                }
                $calls[] = $files;
            }
            $this->assertWitnessParity(static fn (ReleaseProcessingContext $context): null => null, $calls);
        }
        // Non-string or missing names and NUL bytes use the PHP admission loop.
        foreach ([[['name' => 5]], [['size' => 1]], [['name' => "a\0b"]], [['name' => 'a'], 'scalar']] as $files) {
            $context = $this->context();
            self::assertFalse($context->rememberArchiveFileNamesNative($files));
            self::assertSame([], $context->archiveNamingWitnesses);
        }
        $this->assertWitnessParity(static fn (ReleaseProcessingContext $context): null => null, [[['name' => 5], ['name' => 'x']], [['name' => "a\0b"]]]);
    }

    public function test_missing_binding_falls_back_in_public_callers(): void
    {
        foreach (self::CLASSES as $class) {
            $property = new ReflectionProperty($class, 'w466Ffi');
            $class::useW466RustFfi(true);
            $property->setValue(null, false);
            try {
                switch ($class) {
                    case ArchiveProcessingService::class:
                        self::assertNull(ArchiveProcessingService::summarizeFileListNative(['name' => 'a.mkv']));
                        self::assertSame(ArchiveProcessingService::summarizeFileListPhp(['name' => 'a.mkv']), ArchiveProcessingService::summarizeFileList(['name' => 'a.mkv']));
                        break;
                    case PostProcessService::class:
                        self::assertNull(PostProcessService::summarizeTvLookupModesNative(1, '2'));
                        self::assertSame(['skip' => 0, 'all' => 0, 'renamed' => 1, 'other' => 0], PostProcessService::summarizeTvLookupModes(1, '2'));
                        break;
                    case PersistenceMetricsCollector::class:
                        self::assertNull(PersistenceMetricsCollector::applyEventsNative(7, [0, 0.0, 0, 0], [0, 7, 1.0]));
                        self::assertSame([1, 1.0, 0, 0], PersistenceMetricsCollector::applyEvents(7, [0, 0.0, 0, 0], [0, 7, 1.0]));
                        break;
                    case ProcessingMetrics::class:
                        self::assertNull(ProcessingMetrics::summaryFromNanosecondsNative(['a' => 1]));
                        self::assertSame('a=0.000000s', ProcessingMetrics::summaryFromNanoseconds(['a' => 1]));
                        break;
                    case ReleaseProcessingContext::class:
                        $context = $this->context();
                        self::assertFalse($context->rememberArchiveFileNamesNative([['name' => 'a.mkv']]));
                        $context->rememberArchiveFileNames([['name' => 'a.mkv']]);
                        self::assertSame(['a.mkv'], $context->archiveFileNames);
                        break;
                }
            } finally {
                $class::resetW466RustFfi();
            }
        }
    }

    /**
     * Protocol: seed 466, eight variants, 50 warmups and 2,000 pairs/workload.
     * Randomized paired order, identical fixtures built outside timing, parity
     * asserted on every pair. Common: a 12-entry RAR listing, 8 CLI lookup
     * values, 24 scoped persistence events, the 13 processing stages and 16
     * archive names admitted twice into a fresh context. Uncommon: 240-entry
     * season-pack listings, 128 whitespace/exponent/invalid lookup values, 512
     * mixed-scope events, 64 long stage keys with extreme totals and 300 names
     * hitting the witness count/byte caps. Timings include public dispatch,
     * marshaling, FFI and result decoding. Nearest-rank p99 (20 tail samples),
     * approximate 95% order-statistic bands. Raw pairs and metadata are saved
     * outside the checkout.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks_and_defaults(): void
    {
        $this->requireWaveBindings();
        $random = new Randomizer(new Mt19937(466));
        $records = $summary = [];
        foreach (self::CLASSES as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                $variants = $this->benchmarkVariants($class, $workload === 'Uncommon');
                $php = $rust = [];
                for ($i = -50; $i < 2000; $i++) {
                    $variant = $random->getInt(0, count($variants) - 1);
                    [$prepare, $run] = $variants[$variant];
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $fixtures = [$prepare(), $prepare()];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $class::useW466RustFfi($native);
                        $fixture = $fixtures[(int) $native];
                        $start = hrtime(true);
                        $results[(int) $native] = $run($fixture);
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
                $p99 = (int) ceil($n * 0.99) - 1;
                $low = (int) floor((0.99 - 1.96 * sqrt(0.99 * 0.01 / $n)) * $n) - 1;
                $high = min($n - 1, (int) ceil((0.99 + 1.96 * sqrt(0.99 * 0.01 / $n)) * $n) - 1);
                $summary[$class][$workload] = [
                    'mean_speedup' => array_sum($php) / array_sum($rust),
                    'p99_speedup' => $php[$p99] / $rust[$p99],
                    'php_mean_ns' => array_sum($php) / $n, 'rust_mean_ns' => array_sum($rust) / $n,
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                    'php_p99_band_ns' => [$php[$low], $php[$high]], 'rust_p99_band_ns' => [$rust[$low], $rust[$high]],
                ];
            }
            $class::resetW466RustFfi();
            self::assertSame($class::W466_RUST_FFI_DEFAULT, $class::usesW466RustFfi());
        }
        $hashes = [];
        $root = dirname(__DIR__, 2);
        foreach (self::CLASSES as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            self::assertNotFalse($file);
            $hashes[$file] = hash_file('sha256', $file);
        }
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'tests/Unit/RustArchivePostProcessPersistenceMetricsContextW46ParityTest.php'] as $file) {
            $hashes[$file] = hash_file('sha256', $root.'/'.$file);
        }
        $library = $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $metadata = [
            'utc' => gmdate('c'), 'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'base' => 'ca7655924d262bd0a4de249f6769df9d23643207', 'source_sha256' => $hashes,
            'library_sha256' => is_file($library) ? hash_file('sha256', $library) : null, 'profile' => 'release opt-level=3 lto=true codegen-units=1',
            'php' => PHP_VERSION, 'os' => php_uname(), 'rustc' => trim((string) shell_exec('rustc --version')),
            'cargo' => trim((string) shell_exec('cargo --version')), 'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.memsize' : 'uname -m')),
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
            'pairs' => 2000, 'warmups' => 50, 'seed' => 466, 'variants' => 8,
        ];
        $artifact = sys_get_temp_dir().'/w466-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW46.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (self::CLASSES as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            self::assertFalse($class::usesW466RustFfi() && ! $eligible, $class.' needs >=1.5x mean/p99 on both workloads');
        }
    }

    /** @return list<array{0: Closure(): mixed, 1: Closure(mixed): mixed}> */
    private function benchmarkVariants(string $class, bool $uncommon): array
    {
        $variants = [];
        $none = static fn (): null => null;
        $extensions = ['rar', 'r00', 'r01', 'mkv', 'nfo', 'jpg', 'sfv', 'par2', 'mp3', 'txt', '001', 'srt'];
        for ($i = 0; $i < 8; $i++) {
            switch ($class) {
                case ArchiveProcessingService::class:
                    $files = [];
                    for ($j = 0, $n = $uncommon ? 240 + $i : 12 + $i % 3; $j < $n; $j++) {
                        $files[] = ['name' => ($uncommon ? 'Сериал.Show.Name.S0'.($i + 1).'.Complete.2160p.WEB-DL/Episode.'.$j : 'Movie.Name.2024.1080p.BluRay.x264-GRP.part'.$j).'.'.$extensions[($j + $i) % 12],
                            'size' => $uncommon ? 1073741824 + $j : 50000000 + $j, 'date' => 1700000000, 'pass' => (int) ($j % 9 === 0), 'compressed' => $j % 3 === 0 ? '' : $j % 2, 'source' => 'main'];
                    }
                    $variants[] = [$none, static fn (): array => ArchiveProcessingService::summarizeFileList(...$files)];
                    break;
                case PostProcessService::class:
                    $pool = $uncommon ? [' 2', "\t1\n", '2.0', '1e0', '.2e1', 'abc', '', '3', '-1', '9223372036854775808', '1e1000', '+0'] : ['', '0', '1', '2', '1', '2', '', '1'];
                    $values = [];
                    for ($j = 0, $n = $uncommon ? 128 : 8; $j < $n; $j++) {
                        $values[] = $pool[($j * 7 + $i) % count($pool)];
                    }
                    $default = $i % 3;
                    $variants[] = [$none, static fn (): array => PostProcessService::summarizeTvLookupModes($default, ...$values)];
                    break;
                case PersistenceMetricsCollector::class:
                    $events = [];
                    for ($j = 0, $n = $uncommon ? 512 : 24; $j < $n; $j++) {
                        $events[] = [$j % 3, $uncommon ? 7 + $j % 4 : 7, ($j * 37 % 1000) / 13];
                    }
                    $scope = $uncommon && $i % 2 === 1 ? null : 7;
                    $variants[] = [$none, static fn (): array => PersistenceMetricsCollector::applyEvents($scope, [$i, 0.5 * $i, 0, 0], ...$events)];
                    break;
                case ProcessingMetrics::class:
                    $nanoseconds = [];
                    if ($uncommon) {
                        for ($j = 0; $j < 64; $j++) {
                            $nanoseconds['stage_with_a_long_descriptive_name_'.$j.'_'.$i] = [PHP_INT_MAX - $j, PHP_INT_MIN + $j, $j * 1000000000, -$j * 999999937][$j % 4];
                        }
                    } else {
                        foreach (ProcessingStage::cases() as $index => $stage) {
                            $nanoseconds[$stage->value] = ($index + 1) * 123456789 + $i * 1000003;
                        }
                    }
                    $variants[] = [$none, static fn (): string => ProcessingMetrics::summaryFromNanoseconds($nanoseconds)];
                    break;
                case ReleaseProcessingContext::class:
                    $files = [];
                    for ($j = 0, $n = $uncommon ? 300 : 16; $j < $n; $j++) {
                        $files[] = ['name' => $uncommon ? 'Сериал.Show.S01E'.sprintf('%03d', $j).'.'.str_repeat('Long.Name.', 10 + $i).'mkv' : 'Show.Name.S01E'.sprintf('%02d', ($j + $i) % 12).'.1080p.WEB.H264-GRP.mkv', 'size' => $j];
                    }
                    $variants[] = [
                        fn (): ReleaseProcessingContext => $this->context(),
                        static function (mixed $context) use ($files): array {
                            self::assertInstanceOf(ReleaseProcessingContext::class, $context);
                            $context->rememberArchiveFileNames($files);
                            $context->rememberArchiveFileNames($files);

                            return [$context->archiveFileNames, $context->archiveNamingWitnesses, $context->namingWitnessesOmitted, $context->archiveNameEvidenceIncomplete];
                        },
                    ];
                    break;
            }
        }

        return $variants;
    }

    /** @param  array<array-key, array<array-key, mixed>>  $files */
    private function assertArchiveParity(array $files): void
    {
        $expected = ArchiveProcessingService::summarizeFileListPhp(...$files);
        self::assertSame($expected, ArchiveProcessingService::summarizeFileListNative(...$files), json_encode($files, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        foreach ([false, true] as $native) {
            ArchiveProcessingService::useW466RustFfi($native);
            self::assertSame($expected, ArchiveProcessingService::summarizeFileList(...$files));
        }
    }

    /** @param  array<array-key, int|string|null>  $values */
    private function assertTvParity(int $default, array $values): void
    {
        $expected = PostProcessService::summarizeTvLookupModesPhp($default, ...$values);
        self::assertSame($expected, PostProcessService::summarizeTvLookupModesNative($default, ...$values), var_export($values, true));
        foreach ([false, true] as $native) {
            PostProcessService::useW466RustFfi($native);
            self::assertSame($expected, PostProcessService::summarizeTvLookupModes($default, ...$values));
        }
    }

    /**
     * @param  array{0: int, 1: float, 2: int, 3: int}  $counters
     * @param  array<array-key, array<array-key, mixed>>  $rows
     */
    private function assertEventParity(?int $scope, array $counters, array $rows): void
    {
        $expected = PersistenceMetricsCollector::applyEventsPhp($scope, $counters, ...$rows);
        $native = PersistenceMetricsCollector::applyEventsNative($scope, $counters, ...$rows);
        if (is_int($expected[0]) && is_int($expected[2]) && is_int($expected[3])) {
            self::assertSame($expected, $native);
        } else {
            self::assertNull($native);
        }
        foreach ([false, true] as $enabled) {
            PersistenceMetricsCollector::useW466RustFfi($enabled);
            self::assertSame($expected, PersistenceMetricsCollector::applyEvents($scope, $counters, ...$rows));
        }
    }

    /** @param  array<array-key, int>  $nanoseconds */
    private function assertStageParity(array $nanoseconds): void
    {
        $expected = ProcessingMetrics::summaryFromNanosecondsPhp($nanoseconds);
        self::assertSame(ProcessingMetrics::formatStageSummaryPhp(ProcessingMetrics::secondsFromNanosecondsPhp($nanoseconds)), $expected);
        self::assertSame($expected, ProcessingMetrics::summaryFromNanosecondsNative($nanoseconds));
        foreach ([false, true] as $native) {
            ProcessingMetrics::useW466RustFfi($native);
            self::assertSame($expected, ProcessingMetrics::summaryFromNanoseconds($nanoseconds));
        }
    }

    /**
     * @param  Closure(ReleaseProcessingContext): null  $prepare
     * @param  list<array<array-key, mixed>>  $calls
     */
    private function assertWitnessParity(Closure $prepare, array $calls): void
    {
        $states = [];
        foreach ([false, true] as $native) {
            ReleaseProcessingContext::useW466RustFfi($native);
            $context = $this->context();
            $prepare($context);
            foreach ($calls as $files) {
                $context->rememberArchiveFileNames($files);
            }
            $states[] = [$context->archiveFileNames, $context->archiveNamingWitnesses, (new ReflectionProperty(ReleaseProcessingContext::class, 'namingWitnessBytes'))->getValue($context),
                $context->namingWitnessesOmitted, $context->archiveNameEvidenceIncomplete, $context->defersArchiveNameChecks()];
        }
        self::assertSame($states[0], $states[1]);
    }

    private function requireWaveBindings(): void
    {
        self::assertTrue(extension_loaded('FFI'));
        self::assertNotNull(ArchiveProcessingService::summarizeFileListNative(['name' => 'a.mkv', 'size' => 1]), 'Archive summary export must run.');
        self::assertNotNull(PostProcessService::summarizeTvLookupModesNative(1, '2'), 'TV summary export must run.');
        self::assertNotNull(PersistenceMetricsCollector::applyEventsNative(7, [0, 0.0, 0, 0], [0, 7, 1.0]), 'Event batch export must run.');
        self::assertNotNull(ProcessingMetrics::summaryFromNanosecondsNative(['a' => 1]), 'Stage summary export must run.');
        self::assertTrue($this->context()->rememberArchiveFileNamesNative([['name' => 'a.mkv']]), 'Witness export must run.');
    }

    private function context(): ReleaseProcessingContext
    {
        return new ReleaseProcessingContext(new Release(['id' => 1, 'guid' => 'wave46']));
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
