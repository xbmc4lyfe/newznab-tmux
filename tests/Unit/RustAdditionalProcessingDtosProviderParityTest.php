<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Providers\AdditionalProcessingServiceProvider;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\DTO\ImageCandidate;
use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\DTO\PersistenceMetrics;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use TypeError;

/**
 * Wave 35.6 benchmark screen: Apple M5, macOS arm64, PHP 8.5.12 (CLI opcache
 * and JIT disabled), rustc 1.99.1 release/LTO, base e7c0349cb4837dde3fa1c5a74e8874a44f249c6a.
 * Three repetitions of 4096 pairs per regime; ranges below are PHP/Rust latency
 * speedups including dispatch/marshaling. Raw samples and hashes are emitted by
 * the opt-in benchmark below. p99 is a sample estimate; all three repetitions
 * are far below the 1.5x adoption gate, so every RUST_FFI_DEFAULT remains false.
 *
 * Helper                   Common mean / p99       Uncommon mean / p99
 * Transfer efficiency      0.31-0.32 / 0.32-0.33x   0.33-0.34 / 0.33-0.37x
 * Candidate descriptor     0.21-0.21 / 0.21-0.23x   0.20-0.21 / 0.22-0.23x
 * Mapping summary          0.24-0.26 / 0.24-0.32x   0.18-0.19 / 0.18-0.19x
 * Persistence summary      0.26-0.28 / 0.26-0.28x   0.28-0.28 / 0.28-0.31x
 * Query duration           0.20-0.23 / 0.25-0.30x   0.20-0.22 / 0.18-0.25x
 */
final class RustAdditionalProcessingDtosProviderParityTest extends TestCase
{
    private const array CLASSES = [DownloadMetrics::class, ImageCandidate::class, MappedNzbFile::class, PersistenceMetrics::class, AdditionalProcessingServiceProvider::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional', DownloadMetrics::FFI_CDEF);
        self::assertNotNull($ffi, 'Build the release library with the Wave 35.6 export first.');

        return $ffi;
    }

    public function test_normalization_contracts(): void
    {
        self::assertSame([
            'logicalRequests' => 10, 'networkRequests' => 6, 'cacheHits' => 4,
            'bytesDownloaded' => 300, 'bytesReused' => 100, 'mediaBytesDownloaded' => 150,
            'cacheHitRatio' => 0.4, 'byteReuseRatio' => 0.25, 'mediaByteRatio' => 0.5,
        ], DownloadMetrics::summarizeTransferEfficiency(10, 6, 4, 300, 100, 150));
        self::assertSame([
            'title' => 'cover.jpg', 'messageIds' => ['a', 'b'], 'sourceIndex' => 0, 'postedSize' => 0,
        ], ImageCandidate::normalizeCandidateDescriptor(" \tcover.jpg\0", [' a ', '', 'a', 7, null, [], 'b'], -1, -5));
        self::assertSame([
            'sourceIndex' => 0, 'filename' => 'movie.mkv', 'fileSize' => 0,
            'confidence' => 'unique-size', 'confidenceRank' => 1,
        ], MappedNzbFile::summarizeMapping(-1, ' movie.mkv ', -1, ' UNIQUE_SIZE '));
        self::assertSame([
            'databaseStatements' => 4, 'databaseMilliseconds' => 10.0,
            'searchSyncRequests' => 5, 'searchSyncExecutions' => 2,
            'averageStatementMs' => 2.5, 'coalescedSearchSyncRequests' => 3,
        ], PersistenceMetrics::summarizeDatabaseAndSearchLoad(4, 10.0, 5, 2));
        foreach ([null, NAN, INF, -INF, -1.0, -0.0, 0.0] as $time) {
            self::assertSame(0.0, AdditionalProcessingServiceProvider::normalizeQueryDurationMs($time));
        }
        self::assertSame(1.25, AdditionalProcessingServiceProvider::normalizeQueryDurationMs(1.25));
    }

    public function test_toggles_default_to_php_and_constructor_properties_remain_readonly(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            self::assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(null);
            self::assertFalse($class::usesRustFfi());
        }
        $instances = [new DownloadMetrics(-1), new ImageCandidate(' raw ', [' a '], -1), new MappedNzbFile(-1, new Par2FileEntry(' raw ', -1), MappingConfidence::Verified), new PersistenceMetrics(-1)];
        foreach ($instances as $instance) {
            foreach ((new \ReflectionClass($instance))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if (! $property->isStatic()) {
                    self::assertTrue($property->isReadOnly());
                }
            }
        }
        self::assertSame(-1, $instances[0]->logicalRequests);
        self::assertSame(' raw ', $instances[1]->title);
    }

    public function test_native_parity_for_boundary_and_randomized_inputs(): void
    {
        self::ffi();
        $random = new Randomizer(new Mt19937(3562026));
        $ints = [PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX];
        $times = [NAN, INF, -INF, -0.0, 0.0, -1.0, 1.25, PHP_FLOAT_MAX, PHP_FLOAT_MIN, 5.0e-324];
        $strings = ['', " \t\r\n\0\x0b", '0', 'é.jpg', "\xffa\0b\xfe", "\x0c\xa0a\xa0\x0c", str_repeat(' a ', 2048)];
        for ($i = 0; $i < 350; $i++) {
            $n = array_map(static fn (): int => $i < 5 ? $ints[$i] : $random->getInt(PHP_INT_MIN, PHP_INT_MAX), range(1, 6));
            // Include mixed signs and inconsistent counters as well as uniform extremes.
            if ($i > 10) {
                $n[2] = $random->getInt(-100, 100);
            }
            $time = $times[$i % count($times)];
            $title = $strings[$i % count($strings)];
            $confidence = [' VERIFIED ', 'UNIQUE_SIZE', 'unique-size', 'bad', '', "verified\0x"][$i % 6];
            $ids = [' a ', 'a', '', '0', '00', '0', $title, "a\0b", "\xff", 7, false, null, [], new \stdClass];
            $cases = [
                [DownloadMetrics::class, 'summarizeTransferEfficiency', $n],
                [ImageCandidate::class, 'normalizeCandidateDescriptor', [$title, $ids, $n[0], $n[1]]],
                [MappedNzbFile::class, 'summarizeMapping', [$n[0], $title, $n[1], $confidence]],
                [PersistenceMetrics::class, 'summarizeDatabaseAndSearchLoad', [$n[0], $time, $n[1], $n[2]]],
                [AdditionalProcessingServiceProvider::class, 'normalizeQueryDurationMs', [$time]],
            ];
            foreach ($cases as [$class, $method, $args]) {
                $expected = $class::{$method.'Php'}(...$args);
                self::assertSame($expected, $class::{$method.'Native'}(...$args), $class.'::'.$method);
                foreach ([false, true] as $enabled) {
                    $class::setUseRustFfi($enabled);
                    self::assertSame($expected, $class::$method(...$args));
                }
            }
        }
        self::assertSame(0.0, AdditionalProcessingServiceProvider::normalizeQueryDurationMsNative(null));
        self::assertSame(['title' => '', 'messageIds' => [], 'sourceIndex' => 0, 'postedSize' => 0], ImageCandidate::normalizeCandidateDescriptorNative('', [], 0));
    }

    /** @return array<int, array{string, int}> */
    private static function abiInputs(): array
    {
        return [
            1 => [pack('q*', 10, 6, 4, 300, 100, 150), 72],
            2 => [pack('q2V', 0, 0, 3).' x '.pack('V2', 1, 3).' a ', 30],
            3 => [pack('q2V', 0, 0, 1).'f'.pack('V', 8).'verified', 41],
            4 => [pack('qdq2', 4, 10.0, 5, 2), 48],
            5 => [pack('d', 1.25), 8],
        ];
    }

    public function test_abi_errors_leave_output_unchanged_and_report_required_capacity(): void
    {
        $ffi = self::ffi();
        $output = $ffi->new('char[128]');
        $wraps = $ffi->cast('char *', -1);
        foreach (self::abiInputs() as $operation => [$input, $needed]) {
            FFI::memset($output, 90, 128);
            // @phpstan-ignore method.notFound
            $call = static fn ($ptr, int $len, $out, int $cap): int => $ffi->nntmux_w356_dto_provider($operation, $ptr, $len, $out, $cap);
            self::assertSame(-1, $call(null, strlen($input), $output, 128));
            self::assertSame(-1, $call($input, -1, $output, 128));
            self::assertSame(-1, $call($input, strlen($input), null, 128));
            self::assertSame(-1, $call($input, strlen($input) - 1, $output, 128));
            self::assertSame(-1, $call($input, 16_777_217, $output, 128));
            self::assertSame(-1, $call($input, strlen($input), $output, 16_777_217));
            self::assertSame(-1, $call($input, strlen($input), $output, -1));
            self::assertSame(-1, $call($wraps, strlen($input), $output, 128));
            self::assertSame(-1, $call($input, strlen($input), $wraps, 128));
            self::assertSame(-1, $call($input.'x', strlen($input) + 1, $output, 128));
            self::assertSame(-$needed, $call($input, strlen($input), $output, 0));
            self::assertSame(-$needed, $call($input, strlen($input), $output, $needed - 1));
            self::assertSame(str_repeat('Z', 128), FFI::string($output, 128));
            FFI::memcpy($output, $input, strlen($input));
            self::assertSame(-1, $call($output, strlen($input), $output, 128));
            self::assertSame($input, FFI::string($output, strlen($input)));
            FFI::memset($output, 90, 128);
            self::assertSame($needed, $call($input, strlen($input), $output, 128));
            self::assertSame(str_repeat('Z', 128 - $needed), FFI::string(FFI::addr($output[$needed]), 128 - $needed));
        }
        foreach ([0, 6, 255] as $operation) {
            FFI::memset($output, 90, 128);
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w356_dto_provider($operation, '', 0, $output, 128));
            self::assertSame(str_repeat('Z', 128), FFI::string($output, 128));
        }
        foreach ([pack('q2V', 0, 0, 0).pack('V', 0xFFFFFFFF), pack('q2V', 0, 0, 0xFFFFFFFF)] as $input) {
            // @phpstan-ignore method.notFound
            self::assertSame(-1, $ffi->nntmux_w356_dto_provider(2, $input, strlen($input), $output, 128));
        }
    }

    public function test_missing_library_and_symbol_errors_fall_back_to_php(): void
    {
        $missing = RustPipelineLibrary::resolveDomain('additional', DownloadMetrics::FFI_CDEF, '/missing/wave356/libnntmux_pipeline.dylib');
        self::assertNull($missing);
        foreach ([false, FFI::cdef('int abs(int);')] as $unavailable) {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(true);
                (new ReflectionProperty($class, 'ffi'))->setValue(null, $unavailable);
            }
            $this->test_normalization_contracts();
        }
    }

    public function test_oversized_byte_strings_use_the_php_fallback(): void
    {
        $title = str_repeat('x', 16_777_217);
        ImageCandidate::setUseRustFfi(true);
        MappedNzbFile::setUseRustFfi(true);
        self::assertNull(ImageCandidate::normalizeCandidateDescriptorNative($title, [], 0));
        self::assertSame(ImageCandidate::normalizeCandidateDescriptorPhp($title, [], 0), ImageCandidate::normalizeCandidateDescriptor($title, [], 0));
        self::assertNull(MappedNzbFile::summarizeMappingNative(0, $title, 0, 'verified'));
        self::assertSame(MappedNzbFile::summarizeMappingPhp(0, $title, 0, 'verified'), MappedNzbFile::summarizeMapping(0, $title, 0, 'verified'));
    }

    public function test_strict_scalar_types_are_identical_with_both_toggles(): void
    {
        foreach ([false, true] as $enabled) {
            ImageCandidate::setUseRustFfi($enabled);
            try {
                // @phpstan-ignore argument.type
                ImageCandidate::normalizeCandidateDescriptor('x', [], 'bad');
                self::fail('Expected strict scalar type checking before FFI.');
            } catch (TypeError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_provider_query_listener_records_normalized_durations(): void
    {
        $app = $this->createStub(Application::class);
        $connection = $this->createStub(Connection::class);
        $collector = new PersistenceMetricsCollector;
        $oldRoot = DB::getFacadeRoot();
        $database = new class
        {
            public ?\Closure $listener = null;

            public function listen(\Closure $listener): void
            {
                $this->listener = $listener;
            }
        };
        try {
            DB::swap($database);
            (new AdditionalProcessingServiceProvider($app))->boot($collector);
            self::assertNotNull($database->listener);
            foreach ([false, true] as $enabled) {
                AdditionalProcessingServiceProvider::setUseRustFfi($enabled);
                $collector->beginReleaseScope(356);
                foreach ([null, NAN, INF, -1.0, 1.25, 2.5] as $time) {
                    ($database->listener)(new QueryExecuted('select 1', [], $time, $connection));
                }
                $metrics = $collector->finishReleaseScope();
                self::assertSame(6, $metrics->databaseStatements);
                self::assertSame(3.75, $metrics->databaseMilliseconds);
            }
        } finally {
            DB::swap($oldRoot);
        }
    }

    /**
     * Reproduce: NNTMUX_W356_BENCH=1 NNTMUX_W356_BENCH_OUTPUT=/tmp/w35-6-benchmark.json
     * php artisan test --compact tests/Unit/RustAdditionalProcessingDtosProviderParityTest.php
     * --filter=test_paired_randomized_order_benchmarks
     *
     * Frozen protocol: seed 3562026, 256 warmup pairs, 3 repetitions of 4096
     * single-call pairs per workload, random PHP/Rust order, nearest-rank p99.
     * Timing includes the public dispatcher, packing, FFI, allocation and decoding.
     * Raw samples, source/library hashes and environment are retained in the output.
     * Require >=1.5x mean and p99 in both regimes before changing a production default.
     */
    public function test_paired_randomized_order_benchmarks(): void
    {
        if (getenv('NNTMUX_W356_BENCH') !== '1') {
            $this->markTestSkipped('Opt-in paired benchmark; set NNTMUX_W356_BENCH=1.');
        }
        self::ffi();
        $random = new Randomizer(new Mt19937(3562026));
        $manyIds = array_merge(array_map(static fn (int $i): string => ' id-'.$i.' ', range(0, 511)), ['id-0', '', 'id-511']);
        $workloads = [
            [DownloadMetrics::class, 'summarizeTransferEfficiency', [40, 30, 10, 8192, 4096, 2048], [PHP_INT_MAX, 1, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX]],
            [ImageCandidate::class, 'normalizeCandidateDescriptor', [' cover.jpg ', [' a ', 'b', 'a'], 1, 2048], [str_repeat(' ü ', 1024), $manyIds, PHP_INT_MAX, PHP_INT_MAX]],
            [MappedNzbFile::class, 'summarizeMapping', [2, ' movie.mkv ', 8192, ' VERIFIED '], [PHP_INT_MAX, str_repeat(" \xff\0name ", 2048), PHP_INT_MAX, ' UNIQUE_SIZE ']],
            [PersistenceMetrics::class, 'summarizeDatabaseAndSearchLoad', [4, 1.25, 5, 2], [PHP_INT_MAX, PHP_FLOAT_MAX, PHP_INT_MAX, 0]],
            [AdditionalProcessingServiceProvider::class, 'normalizeQueryDurationMs', [1.25], [NAN]],
        ];
        $root = dirname(__DIR__, 2);
        $sourceHashes = [];
        foreach (self::CLASSES as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            self::assertIsString($file);
            $sourceHashes[substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
        }
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock', 'tests/Unit/RustAdditionalProcessingDtosProviderParityTest.php'] as $file) {
            $sourceHashes[$file] = hash_file('sha256', $root.'/'.$file);
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $report = [
            'protocol' => ['seed' => 3562026, 'warmupPairs' => 256, 'pairsPerRepetition' => 4096, 'repetitions' => 3, 'p99' => 'nearest-rank'],
            'environment' => ['php' => PHP_VERSION, 'os' => php_uname(), 'rustc' => trim((string) shell_exec('rustc -V')), 'base' => trim((string) shell_exec('git rev-parse HEAD')), 'profile' => 'release, opt-level=3, LTO, codegen-units=1', 'opcache' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'librarySha256' => hash_file('sha256', $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix)],
            'sourceSha256' => $sourceHashes,
            'results' => [],
        ];
        foreach ($workloads as [$class, $method, $common, $uncommon]) {
            foreach (['common' => $common, 'uncommon' => $uncommon] as $regime => $args) {
                $expected = $class::{$method.'Php'}(...$args);
                // A missing/declined Rust implementation cannot qualify via fallback.
                self::assertSame($expected, $class::{$method.'Native'}(...$args));
                for ($warmup = 0; $warmup < 256; $warmup++) {
                    foreach ([false, true] as $enabled) {
                        $class::setUseRustFfi($enabled);
                        self::assertSame($expected, $class::$method(...$args));
                    }
                }
                for ($rep = 0; $rep < 3; $rep++) {
                    $samples = ['php' => [], 'rust' => []];
                    for ($pair = 0; $pair < 4096; $pair++) {
                        $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                        foreach ($order as $enabled) {
                            $class::setUseRustFfi($enabled);
                            $start = hrtime(true);
                            $actual = $class::$method(...$args);
                            $elapsed = hrtime(true) - $start;
                            self::assertSame($expected, $actual);
                            $samples[$enabled ? 'rust' : 'php'][] = $elapsed;
                        }
                    }
                    $stats = [];
                    foreach ($samples as $path => $values) {
                        sort($values);
                        $stats[$path] = ['meanNs' => array_sum($values) / count($values), 'p99Ns' => $values[(int) ceil(count($values) * 0.99) - 1]];
                    }
                    $speedup = ['mean' => $stats['php']['meanNs'] / max(1, $stats['rust']['meanNs']), 'p99' => $stats['php']['p99Ns'] / max(1, $stats['rust']['p99Ns'])];
                    $report['results'][] = ['method' => $method, 'regime' => $regime, 'repetition' => $rep, 'stats' => $stats, 'speedup' => $speedup, 'rawNs' => $samples];
                    fwrite(STDERR, json_encode(['method' => $method, 'regime' => $regime, 'rep' => $rep, 'speedup' => $speedup], JSON_THROW_ON_ERROR)."\n");
                }
            }
        }
        $output = getenv('NNTMUX_W356_BENCH_OUTPUT');
        if (is_string($output) && $output !== '') {
            self::assertNotFalse(file_put_contents($output, json_encode($report, JSON_THROW_ON_ERROR)));
        }
    }
}
