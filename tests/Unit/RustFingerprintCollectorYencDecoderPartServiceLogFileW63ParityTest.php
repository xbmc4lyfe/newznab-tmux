<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LogViewer\LogFile;
use App\Services\Releases\Fingerprint\ReleaseFingerprintCollector;
use App\Services\Yenc\DecoderFactory;
use App\Services\Yenc\YencPart;
use App\Services\YencService;
use FFI;
use FFI\CData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

final class RustFingerprintCollectorYencDecoderPartServiceLogFileW63ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        ReleaseFingerprintCollector::class => 'w63CollectorPlanBatch',
        DecoderFactory::class => 'w63DecoderFactoryPlanBatch',
        YencPart::class => 'w63YencPartPlanBatch',
        YencService::class => 'w63YencServicePlanBatch',
        LogFile::class => 'w63LogFilePlanBatch',
    ];

    /** Class => Wave 63.2 export. */
    private const SYMBOLS = [
        ReleaseFingerprintCollector::class => 'nntmux_w632_collector_plan_batch',
        DecoderFactory::class => 'nntmux_w632_decoder_factory_plan_batch',
        YencPart::class => 'nntmux_w632_yenc_part_plan_batch',
        YencService::class => 'nntmux_w632_yenc_service_plan_batch',
        LogFile::class => 'nntmux_w632_log_file_plan_batch',
    ];

    /** Class => class-distinct C declaration constant. */
    private const CDEFS = [
        ReleaseFingerprintCollector::class => 'W63_RELEASE_FINGERPRINT_COLLECTOR_FFI_CDEF',
        DecoderFactory::class => 'W63_DECODER_FACTORY_FFI_CDEF',
        YencPart::class => 'W63_YENC_PART_FFI_CDEF',
        YencService::class => 'W63_YENC_SERVICE_FFI_CDEF',
        LogFile::class => 'W63_LOG_FILE_FFI_CDEF',
    ];

    /** Class => summary words after the per-row words (the digest included). */
    private const TAIL = [
        ReleaseFingerprintCollector::class => 28,
        DecoderFactory::class => 20,
        YencPart::class => 20,
        YencService::class => 19,
        LogFile::class => 31,
    ];

    /** Class => public tail-size constant. */
    private const TAIL_CONSTANTS = [
        ReleaseFingerprintCollector::class => 'W63_RELEASE_FINGERPRINT_COLLECTOR_TAIL',
        DecoderFactory::class => 'W63_DECODER_FACTORY_TAIL',
        YencPart::class => 'W63_YENC_PART_TAIL',
        YencService::class => 'W63_YENC_SERVICE_TAIL',
        LogFile::class => 'W63_LOG_FILE_TAIL',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 63, 40
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 400 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon rows, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 6-8). Timings include dispatch, implode, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        ReleaseFingerprintCollector::class => [9.392, 8.789, 9.602, 8.767],
        DecoderFactory::class => [11.779, 11.536, 10.488, 7.532],
        YencPart::class => [8.659, 9.586, 8.970, 8.993],
        YencService::class => [8.808, 8.877, 6.152, 6.109],
        LogFile::class => [10.133, 10.620, 10.132, 9.036],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 40;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const NOW = 1_700_000_000;

    private const WORDS = ['the', 'great', 'night', 'big', 'movie', 'show', 'pro', 'saga', 'old', 'new', 'dark', 'star', 'wild', 'hunt', 'city', 'last'];

    private const COLLECTOR_EXTENSIONS = ['.par2', '.vol00+01.par2', '.vol03+04.PAR2', '.vol7-8.par2', '.part01.rar', '.rar', '.r00', '.r123', '.7z', '.7z.001', '.001',
        '.zip', '.nfo', '.sfv', '.nzb', '.mkv', '.mp4', '.flac', '.exe', ''];

    private const OUTCOMES = ['', '', '', 'ok', 'ok', 'missing', 'timeout', 'unavailable', 'skipped', 'error', '430'];

    private const LEVELS = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG', 'INFO', 'DEBUG'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $class::resetW63RustFfi();
        }
        parent::tearDown();
    }

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW63RustFfi', 'disableW63RustFfi', 'resetW63RustFfi', 'usesW63RustFfi', 'withW63RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w63RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertSame(['enabled', 'ffi', 'out', 'capacity'], array_keys($state->getStaticVariables()), $class.' keeps Wave 63 state in function-local statics');
            $default = $reflection->getReflectionConstant('W63_RUST_FFI_DEFAULT');
            self::assertInstanceOf(ReflectionClassConstant::class, $default, $class);
            self::assertIsBool($default->getValue(), $class);
            self::assertTrue($default->isPublic(), $class);
            self::assertSame($class, $reflection->getReflectionConstant(self::CDEFS[$class])?->getDeclaringClass()->getName(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            self::assertSame(self::TAIL[$class], $reflection->getConstant(self::TAIL_CONSTANTS[$class]), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('w63', strtolower($property->getName()), $class.' adds no Wave 63 property');
            }
        }
        // Readonly classes cannot hold static properties; the Wave 63 state must not need them.
        foreach ([YencPart::class, LogFile::class] as $class) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isReadOnly() && $reflection->isFinal(), $class);
            self::assertSame([], $reflection->getStaticProperties(), $class);
        }
        self::assertCount(5, array_unique(self::CDEFS));
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            ReleaseFingerprintCollector::class => ['__construct', 'collect', 'setUseRustFfi', 'decodedFileRangeNative', 'matchPar2SourcesToNzbFilesNative',
                'mapIfscPreviewToSourceNative', 'timestampSummaryNative', 'parseOverviewNative', 'useW47CollectorRustFfi', 'usesW47CollectorRustFfi',
                'resetW47CollectorRustFfi', 'aggregateCandidateBatch', 'aggregateCandidateBatchPhp', 'aggregateCandidateBatchNative'],
            DecoderFactory::class => ['setUseRustFfi', '__construct', 'make', 'validMode', 'validModePhp', 'validModeNative', 'cacheKey', 'cacheKeyPhp', 'cacheKeyNative',
                'failureKey', 'failureKeyPhp', 'failureKeyNative', 'selectsPhp', 'selectsPhpPhp', 'selectsPhpNative', 'useW461RustFfi', 'usesW461RustFfi',
                'resetW461RustFfi', 'summarizeModeBatch', 'summarizeModeBatchPhp', 'summarizeModeBatchNative'],
            YencPart::class => ['setUseRustFfi', '__construct', 'offset', 'offsetPhp', 'offsetNative', 'length', 'lengthPhp', 'lengthNative', 'byteLength',
                'validSpan', 'validSpanPhp', 'normalizeCrc32', 'normalizeCrc32Php', 'isComplete', 'isCompletePhp', 'metadata', 'metadataPhp', 'useW461RustFfi',
                'usesW461RustFfi', 'resetW461RustFfi', 'summarizeCrcBatch', 'summarizeCrcBatchPhp', 'summarizeCrcBatchNative'],
            YencService::class => ['__construct', 'decode', 'decodeIgnore', 'decodeWithFrame', 'hasValidatedIntegrity', 'enabled', 'decoderName', 'decoderVersion',
                'isYencEncoded', 'encode', 'extractMetadata', 'useW441RustFfi', 'usesW441RustFfi', 'resetW441RustFfi', 'summarizeArticleMetadataBatch',
                'summarizeArticleMetadataBatchPhp', 'summarizeArticleMetadataBatchNative'],
            LogFile::class => ['__construct', 'isActive', 'toArray', 'useW48LogFileRustFfi', 'usesW48LogFileRustFfi', 'resetW48LogFileRustFfi', 'rotationMetadataBatch',
                'rotationMetadataBatchPhp', 'rotationMetadataBatchNative', 'formatBytes', 'formatBytesNative', 'formatBytesPhp'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        self::assertSame(['W461_RUST_FFI_DEFAULT', 'W461_FFI_CDEF', 'W461_SUMMARY_KEYS'], array_values(array_filter(array_keys((new ReflectionClass(YencPart::class))->getConstants()),
            static fn (string $name): bool => str_starts_with($name, 'W461'))));
        // The earlier-wave helpers answer the same whichever way the Wave 63 toggle points.
        $part = new YencPart('a.bin', 300, 2, 101, 200);
        $earlier = static fn (): array => [
            ReleaseFingerprintCollector::aggregateCandidateBatchPhp([]),
            DecoderFactory::validModePhp('auto'), DecoderFactory::selectsPhpPhp('auto', '', 'cli', 'Linux'), DecoderFactory::cacheKeyPhp(1, 'php', 'x'),
            DecoderFactory::summarizeModeBatchPhp(['auto', 'php', 'bogus'], 'lib.so', 'cli', 'Linux'),
            $part->offsetPhp(), $part->lengthPhp(), $part->isCompletePhp(str_repeat('x', 100)), $part->metadataPhp(), YencPart::normalizeCrc32Php('AbC'),
            YencPart::summarizeCrcBatchPhp(['abc', 'XYZ', '']),
            (new YencService)->encode('hello', 'a.bin'), YencService::summarizeArticleMetadataBatchPhp(['x']),
            LogFile::rotationMetadataBatchPhp([['laravel-2024-01-02.log.1.gz', 2048, 5]]), LogFile::formatBytesPhp(123456),
        ];
        $baseline = $earlier();
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([true, false] as $enabled) {
                self::assertEquals($baseline, $class::withW63RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW63RustFfi(), $class);
        }
    }

    public function test_enable_disable_reset_and_with_toggle_scope_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            $class::enableW63RustFfi();
            self::assertTrue($class::usesW63RustFfi(), $class);
            $class::disableW63RustFfi();
            self::assertFalse($class::usesW63RustFfi(), $class);
            self::assertTrue($class::withW63RustFfi(true, static fn (): bool => $class::usesW63RustFfi()), $class);
            self::assertFalse($class::usesW63RustFfi(), $class.' with restores an explicit override');
            $class::resetW63RustFfi();
            self::assertSame($default, $class::usesW63RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW63RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW63RustFfi(! $enabled, static fn (): bool => $class::usesW63RustFfi());

                    return [$class::usesW63RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW63RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW63RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW63RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW63RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW63RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        $earlier = [
            ReleaseFingerprintCollector::class => ['usesW47CollectorRustFfi'],
            DecoderFactory::class => ['usesW461RustFfi'],
            YencPart::class => ['usesW461RustFfi'],
            YencService::class => ['usesW441RustFfi'],
            LogFile::class => ['usesW48LogFileRustFfi'],
        ];
        foreach ($earlier as $class => $methods) {
            $before = array_map(static fn (string $method): bool => $class::$method(), $methods);
            foreach ([true, false] as $enabled) {
                $inside = $class::withW63RustFfi($enabled, static fn (): array => array_map(static fn (string $method): bool => $class::$method(), $methods));
                self::assertSame($before, $inside, $class);
            }
            $class::resetW63RustFfi();
            self::assertSame($before, array_map(static fn (string $method): bool => $class::$method(), $methods), $class.' reset leaves earlier toggles alone');
        }
        try {
            foreach ([true, false] as $enabled) {
                ReleaseFingerprintCollector::useW47CollectorRustFfi($enabled);
                DecoderFactory::useW461RustFfi($enabled);
                YencPart::useW461RustFfi($enabled);
                YencService::useW441RustFfi($enabled);
                LogFile::useW48LogFileRustFfi($enabled);
                foreach (array_keys(self::ENTRIES) as $class) {
                    self::assertSame($this->default($class), $class::usesW63RustFfi(), $class);
                }
            }
        } finally {
            ReleaseFingerprintCollector::resetW47CollectorRustFfi();
            DecoderFactory::resetW461RustFfi();
            YencPart::resetW461RustFfi();
            YencService::resetW441RustFfi();
            LogFile::resetW48LogFileRustFfi();
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(6300));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW63RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $items), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes and keyed or non-sequential batches keep the PHP semantics.
        foreach (array_keys(self::ENTRIES) as $class) {
            $this->assertParity($class, ["a.par2\0\t1\t1\t1\tok", "auto\t\0lib\tcli\tLinux\t5\t", "decode\t\t\t\t\0\0=", "x-2024-01-01.log\t1\t1\t1\t\t\t[\0"]);
            $this->assertParity($class, ['k' => "a.rar\t10\t2\t\t", 'j' => "native\t/x.so\tcli\tLinux\t9\t1"]);
            $this->assertParity($class, [1 => "a.bin\t10\t1\t1\t5\t\t5", 0 => "encode\t2\t\t\tabc"]);
            $this->assertParity($class, ["\0"]);
        }
    }

    public function test_exports_guard_null_short_overlapping_and_malformed_batches(): void
    {
        $this->requireBindings();
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            $words = 1 + self::TAIL[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 1, null, 64]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 2, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 64]), $class);
            self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, 0, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, 1 << 21, $out, 64]), $class);
            // An output aliasing the input is refused; an unaligned output is written byte-wise.
            $shared = $ffi->new('char[1024]');
            FFI::memcpy($shared, 'a', 1);
            $base = $ffi->cast('char *', FFI::addr($shared));
            self::assertSame(-1, $this->invoke($ffi, $symbol, [$base, 1, 1, $ffi->cast('int64_t *', $base), 64]), $class);
            self::assertSame($words, $this->invoke($ffi, $symbol, [$base, 1, 1, $ffi->cast('int64_t *', $this->offset($ffi, $shared, 512)), 64]), $class);
            $raw = $ffi->new('char['.(8 * $words + 8).']');
            $unaligned = $this->offset($ffi, $raw, 1);
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $ffi->cast('int64_t *', $unaligned), $words]), $class);
            self::assertSame($this->call($class, 'Php', ['a']), array_values((array) unpack('P*', FFI::string($unaligned, 8 * $words))), $class);
        }
    }

    public function test_collector_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Movie.par2\t1000\t1\t500\tok", "Movie.vol00+01.par2\t67108000\t2\t\t", "Movie.part01.rar\t50000000\t40\t700000\t", "movie.PART01.rar\t1\t1\t2000001\t",
            "Movie.nfo\t1\t1\t\tmissing", "\t1\t1\t1\t"];
        $this->assertFields(ReleaseFingerprintCollector::class, $rows, (1 << 39) - 1, [1 | 1 << 4 | 33 << 7, 2 | 6 << 4 | 2 << 7 | 63 << 19,
            3 | 1 << 4 | 20 << 7 | 32 << 13 | 47 << 19, 3 | 2 << 4 | 8 << 7 | 1 << 13, 6 | 3 << 4 | 32 << 7, 0], [1, 1, 1, 2, 0, 0, 1, 0, 0, 1, 2, 1, 1, 0, 0, 1,
                1, 1, 1, 1, 1, 2, 4, 1000, 33, 117109002, 700500]);
        $this->assertRandomParity(ReleaseFingerprintCollector::class, 6310);
    }

    public function test_decoder_factory_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["auto\t/lib/r.so\tcli\tLinux\t10000\t", "auto\t\tcli\tLinux\t0\t128", "native\tr.so\tfpm\tDarwin\t999999999\t300", "AUTO\t\t\t\tx\t0"];
        $this->assertFields(DecoderFactory::class, $rows, (1 << 48) - 1, [1 | 2 << 2 | 47 << 4 | 14 << 11 | 128 << 16 | 79 << 24, 1 | 1 << 2 | 6 << 4 | 12 << 11 | 128 << 16,
            3 | 3 << 2 | 49 << 4 | 26 << 11 | 254 << 16 | 3937008 << 24, 96 << 4 | 12 << 11 | 128 << 16], [1, 2, 0, 1, 1, 1, 1, 1, 2, 2, 2, 1, 1, 3, 1, 3, 1000009999, 26,
                3937087]);
        $this->assertRandomParity(DecoderFactory::class, 6320);
    }

    public function test_yenc_part_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["a.bin\t300\t1\t1\t100\tABCDEF01\t100", "A.bin\t300\t2\t101\t200\t\t99", "a.bin\t300\t4\t250\t300\tzz\t51", "a.bin\t300\t5\t260\t301\t0\t1",
            "single\t5\t\t\t\t000\t5", "x\t1\t1\t\t\t\t", "x\tbad\t\t\t\t\t"];
        $this->assertFields(YencPart::class, $rows, (1 << 55) - 1, [3 | 1 << 5 | 1 << 7 | 100 << 23, 4 | 1 << 3 | 2 << 7 | 100 << 23, 3 | 2 << 3 | 2 << 5 | 4 << 7 | 51 << 23,
            5 | 3 << 5 | 5 << 7, 1 | 3 << 5 | 5 << 23, 6 | 1 << 7, 0], [1, 1, 0, 2, 1, 1, 1, 4, 1, 1, 0, 3, 1, 1, 2, 3, 156, 300, 1]);
        $this->assertRandomParity(YencPart::class, 6330);
    }

    public function test_yenc_service_plan_parity(): void
    {
        $this->requireBindings();
        $data = "hello\0=\xD6\x13".str_repeat("\xE0", 3);
        $rows = ["encode\t3\t\t\tab\xD6cd", "decode\t\t".\strlen($data)."\t".dechex(crc32($data))."\t".self::yencEncode($data, 4),
            "decode\t\t".\strlen($data)."\tdeadbeef\t".self::yencEncode($data, 128), "decode\t\t\t\tab=", "decode\t\tx\t\tab", "noop\t\t\t\tab", "encode\t0\t\t\tab",
            "DECODE\t\t2\t\tab=\r\n", "decode\t\t\t".strtoupper(dechex(crc32('xy')))."\t\xA2\xA3"];
        $this->assertFields(YencService::class, $rows, (1 << 41) - 1, [1 | 1 << 2 | 2 << 5 | 4 << 21 | 200 << 31, 2 | 1 << 2 | 4 << 5 | 6 << 21 | 217 << 31,
            2 | 3 << 2 | 1 << 5 | 17 << 21 | 294 << 31, 2 | 4 << 2 | 1 << 5 | 3 << 21, 2 | 6 << 2 | 1 << 5 | 2 << 21, 0, 1 | 2 << 2, 2 | 4 << 2 | 2 << 5 | 4 << 21,
            2 | 1 << 2 | 1 << 5 | 2 << 21], [1, 2, 6, 1, 3, 1, 1, 2, 0, 1, 5, 8, 52, 32, 11, 17, 1, 4]);
        $this->assertRandomParity(YencService::class, 6340);
    }

    public function test_log_file_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["laravel-2024-02-29.log.3.gz\t2048\t1000\t1200\t70000\t1\t[2024-02-29 10:00:00] local.ERROR: x", "worker.log\t0\t1\t5000\t5\t\tplain",
            "app-2023-02-29.log\tx\t\t\t\t\t[2023-02-30] p.Debug: y"];
        $this->assertFields(LogFile::class, $rows, (1 << 58) - 1, [1 | 3 << 3 | 1 << 11 | 1 << 12 | 4 << 15 | 1 << 19 | 1 << 21 | 1 << 42, 1 << 11 | 1 << 42,
            1 << 11 | 8 << 15 | 1 << 20 | 1 << 42], [2, 1, 0, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 2, 1, 0, 0, 0, 1, 2, 1, 20240229, 20240229, 1, 3, 2048, 1, 1]);
        $this->assertRandomParity(LogFile::class, 6350);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $items): void
    {
        $this->requireBindings();
        $this->assertParity($class, $items);
        if ($items !== [] && ! str_contains(implode('', $items), "\0")) {
            self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
        }
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<string>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        $unicode = ['Ünïcödé.part01.rar', '映画.mkv', 'ファイル.par2', "\u{1F3AC}.vol00+01.par2", 'İ.R00', "\xC9\xE1.7z.001", 'naïve-2024-02-29.log'];
        $digits = ['0', '1', '-1', '+1', ' 1 ', "\x0b7\x0b", '1.5', '1e3', '999', '1000', '999999999', '1000000000', '999999999999', '1000000000000', '0x10', '٣', '１'];
        $shared = ['', ' ', "\t", str_repeat("\t", 12), "\x0b", '-', '.', '=', '[', '[]', '[x]:', "\xC9\xE1", $long, $binary, $long."\t".$long, $long."\t".$binary."\t".$long,
            str_repeat("\t", 5000).'1', ...$unicode];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge, binary and unicode' => [$class, $shared];
        }
        $collector = [];
        foreach (['x.par2', 'x.PAR2', 'x.vol0+1.par2', 'x.vol+1.par2', 'x.vol1-2.par2', 'x.vol.par2', 'x.vol1+.par2', '.vol1+2.par2', 'x.rar', 'x.r0', 'x.r00', 'x.r0000',
            'x.r1x', 'x.00', 'x.000', 'x.0000', 'x.7z.000', 'x.7Z.001', 'x.7z', 'x.zip', 'x.txt', 'x.EPUB', 'x', 'x.', '.', ' x.nfo ', ...$unicode] as $name) {
            $collector[] = "{$name}\t20000\t40\t300000\tok";
        }
        foreach ($digits as $value) {
            array_push($collector, "a.rar\t{$value}\t1\t1\t", "a.rar\t1\t{$value}\t1\t", "a.rar\t1\t1\t{$value}\t");
        }
        foreach (['', 'OK', ' ok ', 'Missing', '430', ' 430', 'TIMEOUT', 'Unavailable', 'SKIPPED', 'error', 'other', "\xC9"] as $text) {
            array_push($collector, "a.mkv\t20000\t1\t2000000\t{$text}", "a.mkv\t20000\t1\t2000001\t{$text}", "a.mkv\t20000\t1\t\t{$text}");
        }
        for ($i = 0; $i < 70; $i++) {
            $collector[] = "set.vol{$i}+1.par2\t1048576\t1\t\t";
        }
        array_push($collector, "a.par2\t67108864\t1\t\t", "b.par2\t0\t1\t\t", "a.rar\t999999999999\t999999999\t999999999\t", "a.rar\t1\t32\t\t", "a.rar\t1\t33\t\t",
            "a.mkv\t16383\t1\t1\t", "a.mkv\t16384\t1\t1\t");
        yield 'collector bounds' => [ReleaseFingerprintCollector::class, $collector];
        $decoder = [];
        foreach (['auto', 'php', 'native', 'AUTO', ' auto', 'auto ', 'Native', 'rapid', ''] as $mode) {
            foreach (['', '/usr/lib/r.so', 'r.so'] as $library) {
                foreach (['cli', 'CLI', 'fpm-fcgi'] as $sapi) {
                    foreach (['Linux', 'linux', 'Darwin'] as $os) {
                        $decoder[] = "{$mode}\t{$library}\t{$sapi}\t{$os}\t1000\t128";
                    }
                }
            }
        }
        foreach ($digits as $value) {
            array_push($decoder, "auto\t\tcli\tLinux\t{$value}\t", "auto\t\tcli\tLinux\t1\t{$value}");
        }
        foreach ([0, 1, 4095, 4096, 4097, 67108863, 67108864, 67108865, 999999999999] as $bytes) {
            $decoder[] = "php\t\tcli\tLinux\t{$bytes}\t254";
        }
        array_push($decoder, "auto\t".$long."\tcli\tLinux\t1\t1", "native\t/".str_repeat('x', 300)."\tcli\tLinux\t1\t255");
        yield 'decoder bounds' => [DecoderFactory::class, $decoder];
        $part = [];
        foreach ($digits as $value) {
            array_push($part, "a\t{$value}\t\t\t\t\t", "a\t100\t{$value}\t1\t10\t\t10", "a\t100\t1\t{$value}\t10\t\t10", "a\t100\t1\t1\t{$value}\t\t1",
                "a\t100\t\t\t\t\t{$value}");
        }
        foreach (['', '0', '00000000', '000000000', 'abcdef01', 'ABCDEF01', 'abcdefg', '1', ' ff ', '123456789', 'xyz', "\xC9", '0x1f'] as $crc) {
            $part[] = "c\t10\t\t\t\t{$crc}\t10";
        }
        foreach ([[1, 10], [11, 20], [21, 30], [25, 40], [50, 60], [60, 60], [0, 5], [5, 4], [61, 70], [90, 101], [1, 100]] as $index => [$begin, $end]) {
            $part[] = "seq\t100\t".($index + 1)."\t{$begin}\t{$end}\t\t".($end - $begin + 1);
        }
        array_push($part, "seq\t100\t\t70\t80\t\t11", "SEQ\t100\t3\t81\t90\t\t10", "a\t100\t1\t\t5\t\t5", "a\t100\t1\t5\t\t\t5", "\t100\t\t\t\t\t", " \t1\t\t\t\t\t1",
            "big\t999999999999\t1\t1\t999999999999\t\t999999999999");
        yield 'yenc part bounds' => [YencPart::class, $part];
        $service = [];
        foreach (['', '0', '1', '2', '128', '254', '255', '999', '-1', 'x', ' 7 '] as $line) {
            array_push($service, "encode\t{$line}\t\t\t".str_repeat("\xD6a\x13", 40), "encode\t{$line}\t\t\t");
        }
        foreach (['=', '==', '===', "=\r\n", "a=\r\nb", "a=\n", '=a=b', "\r\n\r\n", "a\r\nb\r\n", str_repeat('=', 101), str_repeat("=\n", 50)] as $payload) {
            $service[] = "decode\t\t\t\t{$payload}";
        }
        foreach ($digits as $value) {
            array_push($service, "decode\t\t{$value}\t\tab", "decode\t\t\t{$value}\tab");
        }
        foreach (['ENCODE', ' Decode ', 'encoder', 'transcode', ''] as $op) {
            $service[] = "{$op}\t\t\t\tabc";
        }
        $data = "\x00\x01\xFF\xD6\xDF\xE0\xE3\xF6\x04\x13 tabs\tand\nnewlines";
        array_push($service, "decode\t\t".\strlen($data)."\t".hash('crc32b', $data)."\t".self::yencEncode($data, 7),
            "decode\t\t".\strlen($data)."\t".ltrim(hash('crc32b', $data), '0')."\t".self::yencEncode($data, 1),
            "decode\t\t".\strlen($data)."\t".str_pad(hash('crc32b', $data), 9, '0', STR_PAD_LEFT)."\t".self::yencEncode($data, 128),
            "encode\t1\t\t\t".$data, "encode\t254\t\t\t".str_repeat("\xD6", 600));
        yield 'yenc service bounds' => [YencService::class, $service];
        $log = [];
        foreach (['a.log', 'a.LOG', 'a.log.1', 'a.log.1234567890', 'a.log.gz', 'a.log.GZ', 'a.1.gz', 'a.gz.1', 'a.bz2', 'a.xz', 'a.zst', 'a.zip', 'a.tar', 'a-2024-02-29.log',
            'a-2023-02-29.log', 'a-2024-13-01.log', 'a-0000-01-01.log', 'a-2024-1-01.log', 'a--2024-01-01.log', 'a-2024-01-01-2025-01-01.log', 'a-2024-01-01', '-2024-01-01',
            'a-2024-01-0', 'a.log-2024-01-01.log.2.gz', 'a-٢٠٢٤-01-01.log', ...$unicode] as $name) {
            $log[] = "{$name}\t100\t1\t1\t\t\t";
        }
        foreach ($digits as $value) {
            array_push($log, "a\t{$value}\t1\t1\t0\t1\t", "a\t100\t{$value}\t700\t0\t1\t", "a\t100\t1\t{$value}\t0\t1\t", "a\t100\t1\t1\t{$value}\t1\t",
                "a\t9999999\t1\t1\t0\t{$value}\t");
        }
        foreach (['[2024-01-01 00:00:00] production.ERROR: x', '[2024-01-01] x.warning:', '[2024-02-30 x] a.info: y', '[x] a.debug: y', 'x [2024-01-01] a.info: y',
            '[2024-01-01] info', '[2024-01-01]info:', '[2024-01-01] . :', '[2024-01-01] a.b.Critical : x', '[2024-01-01] ALERT:', '[2024-01-01] a.İnfo:', '[', ']'] as $line) {
            $log[] = "a\t1\t1\t1\t\t\t{$line}";
        }
        foreach ([0, 1023, 1024, 1048575, 1048576, 1073741823, 1073741824, 1099511627775, 1099511627776, 999999999999] as $size) {
            $log[] = "a\t{$size}\t1\t1\t{$size}\t1\t";
        }
        array_push($log, "a\t65536\t1\t1\t65537\t1\t", "a\t65536\t1\t1\t65536\t1\t", "a\t0\t1\t601\t\t\t", "a\t0\t2\t601\t\t\t");
        yield 'log file bounds' => [LogFile::class, $log];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical rows, Uncommon batches 320-640
     * malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every round's
     * samples. Every pair must agree; raw pairs are written outside the checkout. A class must
     * default to Rust exactly when all four metrics reach 1.5x. Gated behind
     * RUN_RUST_FFI_BENCHMARKS=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 63.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(63));
        $fixtures = $samples = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[$class][$workload][] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
            }
        }
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach (array_keys(self::ENTRIES) as $class) {
                foreach (['Common', 'Uncommon'] as $workload) {
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $items = $fixtures[$class][$workload][$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW63RustFfi($native, fn (): array => $this->timed($class, $items));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $pairs) {
                $php = array_column($pairs, 0);
                $rust = array_column($pairs, 1);
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w632-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'summary' => $summary, 'records' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW63.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW63RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * Every rule is byte-level ASCII, so an explicit setlocale() changes neither implementation.
     * Declared last: once a script sets a locale, later regexes keep the rebuilt tables.
     */
    public function test_parity_holds_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $cases = [
                    ReleaseFingerprintCollector::class => ["M\xC9.PAR2\t1\t1\t1\tOK", "I.R0İ\t1\t1\t1\tİ", "X.VOL1+2.PAR2\t5\t5\t\tSKİPPED"],
                    DecoderFactory::class => ["AUTO\t\tCLI\tLINUX\t1\t1", "auto\t\xC9\tcli\tLinux\t1\t1"],
                    YencPart::class => ["\xC9\t10\t1\t1\t10\tABCDEF\t10", "İ\t10\t\t\t\tİ\t10"],
                    YencService::class => ["ENCODE\t\t\t\t\xC9\xD6", "DECODE\t\t\tABC\t\xC9="],
                    LogFile::class => ["\xC9-2024-01-01.LOG.GZ\t1\t1\t1\t\t\t[2024-01-01] \xC9.İNFO:", "I.LOG.İ\t1\t1\t1\t\t\t[2024-01-01] a.INFO:"],
                ];
                foreach ($cases as $class => $items) {
                    $this->assertParity($class, $items);
                    self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
                }
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * Documented per-row fields (`word & $mask`) and summary words (without the digest) from both
     * implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $rows
     * @param  list<int>  $fields
     * @param  list<int>  $tail
     */
    private function assertFields(string $class, array $rows, int $mask, array $fields, array $tail): void
    {
        $this->assertParity($class, $rows);
        foreach (['Php', 'Native'] as $suffix) {
            $words = $this->call($class, $suffix, $rows);
            self::assertSame($fields, array_map(static fn (int $word): int => $word & $mask, \array_slice($words, 0, \count($rows))), $class.'::'.$suffix);
            self::assertSame($tail, \array_slice($words, \count($rows), -1), $class.'::'.$suffix);
        }
        self::assertSame($this->call($class, 'Php', $rows), $this->exported($class, $rows), $class);
    }

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $items): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $items);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  list<int>  $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[max(0, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W63_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $items): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix]($items);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items): void
    {
        $php = $class::withW63RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW63RustFfi(true, fn (): array => $this->call($class, '', $items));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $items), $class);
        self::assertSame($php, $this->call($class, 'Native', $items), $class);
        self::assertCount(\count($items) + self::TAIL[$class], $php, $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * PARITY_BATCHES Common and Uncommon batches (at least 320 rows each) must match, and the
     * Rust export must actually produce them (no silent PHP fallback).
     *
     * @param  key-of<self::ENTRIES>  $class
     */
    private function assertRandomParity(string $class, int $seed): void
    {
        $items = 0;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < self::PARITY_BATCHES; $i++) {
                $batch = $this->fixture($class, new Randomizer(new Mt19937($seed + 2 * $i + (int) $uncommon)), $uncommon);
                $this->assertParity($class, $batch);
                self::assertSame($this->call($class, 'Php', $batch), $this->exported($class, $batch), $class);
                $items += \count($batch);
            }
        }
        self::assertGreaterThanOrEqual(320 * 2 * self::PARITY_BATCHES, $items, $class);
    }

    /**
     * Calls the export directly with the entry point's framing.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     * @return list<int>
     */
    private function exported(string $class, array $items): array
    {
        $ffi = $this->ffi($class);
        $count = \count($items);
        $cap = $count + 64;
        $out = $ffi->new('int64_t['.$cap.']');
        $blob = implode("\0", $items);
        $written = $this->invoke($ffi, self::SYMBOLS[$class], [$blob, \strlen($blob), $count, $out, $cap]);
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w63RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w63 binding');

        return $ffi;
    }

    /** Each Wave 63.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            self::assertSame(1 + self::TAIL[$class], $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W63 C declaration.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** A `char *` $bytes past the start of $buffer (pointer arithmetic through a `uintptr_t` view). */
    private function offset(FFI $ffi, CData $buffer, int $bytes): CData
    {
        $pointer = $ffi->new('char *[1]');
        $pointer[0] = $ffi->cast('char *', FFI::addr($buffer));
        $address = $ffi->cast('uintptr_t *', FFI::addr($pointer));
        $address[0] += $bytes;
        $result = $pointer[0];
        self::assertInstanceOf(CData::class, $result);

        return $result;
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /** yEnc body of $data (encodePhp() escapes) with CRLF breaks after $line columns. */
    private static function yencEncode(string $data, int $line): string
    {
        $out = '';
        $column = 0;
        for ($i = 0, $n = \strlen($data); $i < $n; $i++) {
            $byte = (\ord($data[$i]) + 42) & 255;
            $encoded = \in_array($byte, [0, 9, 10, 13, 32, 46, 61], true) ? '='.\chr(($byte + 64) & 255) : \chr($byte);
            if ($column >= $line) {
                $out .= "\r\n";
                $column = 0;
            }
            $out .= $encoded;
            $column += \strlen($encoded);
        }

        return $out;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<string>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        $items = [];
        for ($i = 0, $n = $uncommon ? $random->getInt(320, 640) : 320; $i < $n; $i++) {
            $items[] = match ($class) {
                ReleaseFingerprintCollector::class => $this->collectorRow($random, $uncommon),
                DecoderFactory::class => $this->decoderRow($random, $uncommon),
                YencPart::class => $this->partRow($random, $uncommon, $i),
                YencService::class => $this->serviceRow($random, $uncommon),
                LogFile::class => $this->logRow($random, $uncommon),
            };
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /** Printable noise, or binary noise without NUL bytes. */
    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-[]()<>@/+=:\"' \t", $length);
    }

    /** Case-flips, pads or corrupts a field the way hand-edited or truncated data does. */
    private function mangle(Randomizer $random, string $value): string
    {
        return match ($random->getInt(0, 6)) {
            0 => strtoupper($value),
            1 => ucfirst(strtolower($value)),
            2 => $this->pick($random, [' ', "\r\n", "\x0b", '']).$value.$this->pick($random, [' ', "\r\n", "\x0b", '']),
            3 => substr($value, 0, $random->getInt(0, max(0, \strlen($value) - 1))),
            4 => $value.$this->noise($random, 3),
            5 => str_replace(['a', 'e', 'o', '1'], ["\xE1", 'É', '0', $this->pick($random, ['l', ' ', '-', '11'])], $value),
            default => $value,
        };
    }

    /**
     * Replaces one random field of a tab-joined row with $mutate($field), or drops or adds
     * trailing fields.
     *
     * @param  list<string>  $fields
     * @param  callable(string): string  $mutate
     */
    private function mutateFields(Randomizer $random, array $fields, callable $mutate): string
    {
        $k = $random->getInt(0, \count($fields) - 1);
        match ($random->getInt(0, 5)) {
            0 => $fields = \array_slice($fields, 0, $k),
            1 => $fields[] = $this->noise($random, 10),
            default => $fields[$k] = $mutate($fields[$k]),
        };

        return implode("\t", $fields);
    }

    private function releaseName(Randomizer $random): string
    {
        $words = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $words[] = ucfirst($this->pick($random, self::WORDS));
        }

        return implode($this->pick($random, ['.', '.', '_', ' ']), $words).'.'.$random->getInt(1990, 2025);
    }

    private function collectorRow(Randomizer $random, bool $uncommon): string
    {
        $segments = $random->getInt(1, 400);
        $fields = [$this->releaseName($random).$this->pick($random, self::COLLECTOR_EXTENSIONS), (string) ($segments * $random->getInt(300_000, 800_000)), (string) $segments,
            $this->pick($random, ['', '', (string) $random->getInt(100_000, 800_000), (string) $random->getInt(100_000, 800_000), (string) $random->getInt(1_900_000, 2_100_000)]),
            $this->pick($random, self::OUTCOMES)];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['-1', '9999999999999', 'x', ' 7 ', '1e3', '', '٣'])),
            5 => implode("\t", [$this->pick($random, ['Ünïcödé.part01.rar', '映画.mkv', "\u{1F3AC}.vol00+01.par2", '.par2', 'x.vol.par2', 'a.R00', 'b.7Z.002', '']),
                ...\array_slice($fields, 1)]),
            6 => implode("\t", [...\array_slice($fields, 0, 4), $this->pick($random, ['OK ', ' Missing', 'TIMEOUT', '430 ', 'skipped', 'retry', "\xC9"])]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function decoderRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [$this->pick($random, ['auto', 'auto', 'auto', 'php', 'native']), $this->pick($random, ['', '', '/usr/local/lib/librapidyenc.so', 'librapidyenc.dylib']),
            $this->pick($random, ['cli', 'cli', 'cli', 'fpm-fcgi']), $this->pick($random, ['Linux', 'Linux', 'Darwin']), (string) $random->getInt(0, 900_000),
            $this->pick($random, ['', '', '128', '254', '64'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 60, true),
            1 => $this->noise($random, 40),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => implode("\t", [$this->pick($random, ['AUTO', ' auto', 'Native', 'rapid', '', 'php ']), ...\array_slice($fields, 1)]),
            5 => implode("\t", [...\array_slice($fields, 0, 4), $this->pick($random, ['x', '-1', '9999999999999', '1e6', '70000000', '999999999999']),
                $this->pick($random, ['0', '-1', '1000', '255', 'x', '1'])]),
            6 => implode("\t", [$fields[0], '/'.str_repeat('lib/', $random->getInt(1, 60)).'r.so', ...\array_slice($fields, 2)]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function partRow(Randomizer $random, bool $uncommon, int $index): string
    {
        $chunk = 768_000;
        $name = 'file'.intdiv($index, 20).$this->pick($random, ['.mkv', '.part01.rar', '.bin']);
        $size = 20 * $chunk - $random->getInt(0, $chunk - 1);
        $part = $index % 20 + 1 + ($random->getInt(0, 9) === 0 ? $random->getInt(-2, 2) : 0);
        $begin = max(1, ($part - 1) * $chunk + 1);
        $end = min($size, $part * $chunk);
        $crc = $this->pick($random, [bin2hex($random->getBytes(4)), strtoupper(bin2hex($random->getBytes(4))), '', ltrim(bin2hex($random->getBytes(4)), '0')]);
        $body = $end - $begin + 1 - ($random->getInt(0, 9) === 0 ? 1 : 0);
        $fields = $random->getInt(0, 9) === 0 ? [$name, (string) $size, '', '', '', $crc, (string) $size] : [$name, (string) $size, (string) $part, (string) $begin,
            (string) $end, $crc, (string) $body];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 60, true),
            1 => $this->noise($random, 40),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['0', '-1', 'x', '9999999999999', ' 5 ', '', '١'])),
            5 => implode("\t", [$fields[0], $fields[1], $fields[2], (string) $random->getInt(0, $size + 10), (string) $random->getInt(0, $size + 10), ...\array_slice($fields, 5)]),
            6 => implode("\t", [...\array_slice($fields, 0, 5), $this->pick($random, ['123456789', 'xyz', '0', '00000000', ' ab ', 'ABCDEFG']), $fields[6]]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function serviceRow(Randomizer $random, bool $uncommon): string
    {
        $data = str_replace("\0", "\x01", $random->getBytes($random->getInt(32, 256)));
        if ($random->getInt(0, 1) === 0) {
            $fields = ['encode', $this->pick($random, ['', '128', '64', '254']), '', '', $data];
        } else {
            $line = $this->pick($random, [128, 128, 64, 254]);
            $fields = ['decode', '', (string) \strlen($data), $this->pick($random, [hash('crc32b', $data), strtoupper(hash('crc32b', $data)), '']), self::yencEncode($data, $line)];
        }
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 120, true),
            1 => $this->noise($random, 80),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => implode("\t", [$this->pick($random, ['ENCODE', ' Decode ', 'transcode', 'encoder', 'decode']), $this->pick($random, ['0', '-1', '9999', 'x', '1', '']),
                ...\array_slice($fields, 2)]),
            5 => implode("\t", ['decode', '', $this->pick($random, [(string) (\strlen($data) + 1), 'x', '', '1e3']), $this->pick($random, ['xyz', '123456789', 'deadbeef', '0', '']),
                $fields[4].$this->pick($random, ['=', '==', "=\r\n", "\r\n", ''])]),
            6 => implode("\t", ['decode', '', '', '', str_replace("\0", "\x01", $this->noise($random, 200, true))]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function logRow(Randomizer $random, bool $uncommon): string
    {
        $now = self::NOW + $random->getInt(0, 86400);
        $size = $this->pick($random, [$random->getInt(0, 1023), $random->getInt(0, 1 << 20), $random->getInt(0, 1 << 30), $random->getInt(0, 5_000_000_000)]);
        $level = $this->pick($random, self::LEVELS);
        $fields = [$this->pick($random, ['laravel', 'worker', 'horizon', 'nntmux-tmux', 'scheduler']).$this->pick($random, ['-2024-02-29', '-2023-11-05', '', '', '-2025-06-30'])
            .'.log'.$this->pick($random, ['', '', '.1', '.12', '.gz', '.3.gz', '.bz2', '.zst']), (string) $size, (string) ($now - $random->getInt(0, 3600)), (string) $now,
            (string) $random->getInt(0, $size + 1000), $this->pick($random, ['', '25', '50', '200']),
            $this->pick($random, ["[2024-02-29 10:11:12] production.{$level}: ".$this->noise($random, 60), 'Stack trace:', '#0 /app/vendor/x.php(12)', ''])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 100, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['0', '-1', 'x', '9999999999999', ' 5 ', '', '٣'])),
            5 => implode("\t", [$this->pick($random, ['naïve-2024-02-29.log', 'a-2024-02-30.log.GZ', 'a-0000-00-00.LOG.1', '映画.log.zst', '.log', '-2024-01-01', 'a.log.1234567890']),
                ...\array_slice($fields, 1)]),
            6 => implode("\t", [...\array_slice($fields, 0, 6), $this->pick($random, ['[x] a.info: y', '[2024-01-01] info', '[2024-13-01 00:00] a.ERROR:', '[2024-01-01] a.İnfo:',
                '[2024-01-01] . :', "[2024-01-01] env.WARNING\t: tabbed"])]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }
}
