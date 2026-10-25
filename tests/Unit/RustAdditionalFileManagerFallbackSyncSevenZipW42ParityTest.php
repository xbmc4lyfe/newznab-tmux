<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\Config\ProcessingConfiguration;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\SevenZipListingParser;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;

final class RustAdditionalFileManagerFallbackSyncSevenZipW42ParityTest extends TestCase
{
    private const HELPERS = [
        'summarizeFilePersistenceBatch' => ReleaseFileManager::class,
        'summarizeArchiveFallbackBatch' => ReleaseFilesArchiveFallback::class,
        'summarizeSearchSyncBatch' => ReleaseSearchSyncCoordinator::class,
        'summarizeListingFileBatch' => SevenZipListingParser::class,
        'summarizeLimitConfigurationBatch' => ProcessingConfiguration::class,
    ];

    private const CDEF = 'int32_t nntmux_w426_additional_file_manager_fallback_sync_sevenzip(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_cap);';

    protected function tearDown(): void
    {
        foreach (self::HELPERS as $class) {
            if (method_exists($class, 'resetW426RustFfi')) {
                $class::resetW426RustFfi();
            }
        }
        parent::tearDown();
    }

    public function test_literal_reference_contracts(): void
    {
        $this->assertSame(['total' => 5, 'files' => 3, 'total_bytes' => PHP_INT_MAX, 'empty_files' => 1, 'name_bytes' => 7, 'exceeds_limit' => true, 'invalid' => 2],
            ReleaseFileManager::summarizeFilePersistenceBatchPhp([
                ['name' => 'a', 'size' => 0], ['name' => 'é', 'size' => PHP_INT_MAX], ['name' => "b\0cd", 'size' => 1],
                ['name' => '', 'size' => 1], ['name' => 'a', 'size' => '1'],
            ], 2));
        $this->assertSame(['total' => 6, 'images' => 4, 'usable_images' => 3, 'stored_images' => 2, 'total_bytes' => PHP_INT_MAX, 'invalid' => 1],
            ReleaseFilesArchiveFallback::summarizeArchiveFallbackBatchPhp([
                ['name' => 'A.JPEG', 'size' => PHP_INT_MAX, 'pass' => 0, 'stored' => true],
                ['name' => "x.png\n", 'size' => 1, 'pass' => 0, 'stored' => false],
                ['name' => 'x.webp', 'size' => 2, 'pass' => 1, 'stored' => true],
                ['name' => 'x.jpg', 'size' => 3, 'pass' => 0, 'stored' => true],
                ['name' => "x.png\n\n", 'size' => 4, 'pass' => 0, 'stored' => false], null,
            ]));
        $events = [['op' => 0, 'release_id' => PHP_INT_MIN], ['op' => 1, 'release_id' => PHP_INT_MIN], ['op' => 1, 'release_id' => 4], ['op' => 2, 'release_id' => PHP_INT_MIN], ['op' => 1, 'release_id' => PHP_INT_MIN], ['op' => 3, 'release_id' => 0], ['op' => 9, 'release_id' => 0]];
        $this->assertSame(['total' => 7, 'requests' => 3, 'executions' => 2, 'deferred_requests' => 2, 'discarded_pending' => 1, 'pending' => false, 'scoped_release' => null, 'invalid' => 1],
            ReleaseSearchSyncCoordinator::summarizeSearchSyncBatchPhp($events));
        $this->assertSame(['total' => 7, 'requests' => 3, 'executions' => 3, 'deferred_requests' => 0, 'discarded_pending' => 0, 'pending' => false, 'scoped_release' => null, 'invalid' => 1],
            ReleaseSearchSyncCoordinator::summarizeSearchSyncBatchPhp($events, false));
        $this->assertSame(['total' => 6, 'files' => 2, 'total_bytes' => PHP_INT_MAX, 'encrypted' => 1, 'compressed' => 2, 'invalid' => 4],
            SevenZipListingParser::summarizeListingFileBatchPhp([
                ['name' => '久Å', 'size' => PHP_INT_MAX, 'pass' => 1, 'compressed' => 1],
                ['name' => "raw\x85", 'size' => 1, 'pass' => 0, 'compressed' => 1],
                ['name' => "bad\xC2\x85", 'size' => 1, 'pass' => 0, 'compressed' => 0],
                ['name' => "bad\n", 'size' => 1, 'pass' => 0, 'compressed' => 0],
                ['name' => '', 'size' => 1, 'pass' => 0, 'compressed' => 0],
                ['name' => 'x', 'size' => -1, 'pass' => 0, 'compressed' => 0],
            ]));
        $this->assertSame(['total' => 4, 'disabled' => 2, 'max_in_flight' => PHP_INT_MAX, 'max_timeout_seconds' => PHP_INT_MAX, 'total_max_bytes' => PHP_INT_MAX, 'invalid' => 1],
            ProcessingConfiguration::summarizeLimitConfigurationBatchPhp([
                ['batch_size' => PHP_INT_MAX, 'threads' => PHP_INT_MAX, 'timeout_seconds' => PHP_INT_MAX, 'max_bytes' => PHP_INT_MAX],
                ['batch_size' => -1, 'threads' => 0, 'timeout_seconds' => -1, 'max_bytes' => 10],
                ['batch_size' => 0, 'threads' => 5, 'timeout_seconds' => 1, 'max_bytes' => -1], [],
            ]));
    }

    public function test_toggles_are_independent_and_preserve_existing_toggles(): void
    {
        foreach (self::HELPERS as $class) {
            $class::resetW426RustFfi();
            $this->assertSame($class::W426_RUST_FFI_DEFAULT, $class::usesW426RustFfi());
            $earlier = property_exists($class, 'useRustFfi') ? new ReflectionProperty($class, 'useRustFfi') : null;
            $original = $earlier?->isStatic() ? $earlier->getValue() : null;
            foreach ([false, true] as $enable) {
                $class::useW426RustFfi($enable);
                $this->assertSame($enable, $class::usesW426RustFfi());
                if ($earlier?->isStatic()) {
                    $this->assertSame($original, $earlier->getValue());
                }
                foreach (self::HELPERS as $other) {
                    if ($other !== $class) {
                        $this->assertSame($other::W426_RUST_FFI_DEFAULT, $other::usesW426RustFfi());
                    }
                }
            }
            $class::useW426RustFfi();
            $this->assertTrue($class::usesW426RustFfi());
            $class::resetW426RustFfi();
            $this->assertSame($class::W426_RUST_FFI_DEFAULT, $class::usesW426RustFfi());
        }
    }

    public function test_malformed_rows_fall_back_without_coercion(): void
    {
        $bad = [null, false, 1, 1.5, 'x', new \stdClass, [], ['name' => []], ['size' => '1'], ['size' => -1], ['pass' => true], ['op' => 4, 'release_id' => 1], ['batch_size' => 1.0]];
        foreach (self::HELPERS as $helper => $class) {
            $row = self::fixtures($helper, 1, false)[0];
            foreach ($bad as $invalid) {
                $this->assertParity($helper, [[$row, $invalid, $row]]);
            }
            foreach ($row as $key => $_) {
                foreach ([null, false, true, '0', 0.5, [], new \stdClass] as $value) {
                    $this->assertParity($helper, [[$row, array_replace($row, [$key => $value]), $row]]);
                }
            }
        }
    }

    public function test_edges_and_binary_names(): void
    {
        $this->ffiOrFail();
        foreach (['', 'a', "a\0b", "x\n", "x\r", "\x7F", "\xC2\x9F", "raw\x85", '久Å', "\xff", "x.JPEG\n", "x.png\r\n", "x.webp\n\n"] as $name) {
            foreach ([0, 1, PHP_INT_MAX] as $size) {
                $this->assertParity('summarizeFilePersistenceBatch', [[['name' => $name, 'size' => $size]], -1]);
                foreach ([0, 1] as $pass) {
                    $this->assertParity('summarizeArchiveFallbackBatch', [[['name' => $name, 'size' => $size, 'pass' => $pass, 'stored' => true]]]);
                    $this->assertParity('summarizeListingFileBatch', [[['name' => $name, 'size' => $size, 'pass' => $pass, 'compressed' => 1]]]);
                }
            }
        }
        foreach (self::HELPERS as $helper => $_) {
            $this->assertParity($helper, [[]]);
            $row = self::fixtures($helper, 1, false)[0];
            $this->assertParity($helper, [['later' => $row + ['extra' => new \stdClass], 8 => $row, 'earlier' => $row]]);
        }
        foreach ([PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX] as $edge) {
            $this->assertParity('summarizeLimitConfigurationBatch', [[['batch_size' => $edge, 'threads' => PHP_INT_MAX, 'timeout_seconds' => $edge, 'max_bytes' => $edge], ['batch_size' => 1, 'threads' => 1, 'timeout_seconds' => 0, 'max_bytes' => 1]]]);
            foreach ([false, true] as $coalesce) {
                $this->assertParity('summarizeSearchSyncBatch', [[['op' => 0, 'release_id' => $edge], ['op' => 1, 'release_id' => $edge]], $coalesce]);
                $this->assertParity('summarizeSearchSyncBatch', [[['op' => 0, 'release_id' => $edge], ['op' => 1, 'release_id' => $edge], ['op' => 3, 'release_id' => 0]], $coalesce]);
            }
        }
    }

    public function test_unavailable_ffi_and_native_errors_fall_back(): void
    {
        foreach (self::HELPERS as $helper => $class) {
            $stateMethod = new ReflectionMethod($class, 'w426State');
            $state = $stateMethod->invoke(null);
            $this->assertNull($state['ffi']);
            $accessor = $stateMethod->getClosure();
            $state = &$accessor();
            // A cached FFI handle lacking this export must be treated as unavailable.
            $state['ffi'] = FFI::cdef('int abs(int);');
            $rows = self::fixtures($helper, 2, false);
            $this->assertSame($class::{$helper.'Php'}($rows), $class::{$helper.'Native'}($rows));
            $class::resetW426RustFfi();
            // Exercise the actual loader failure in a fresh process with FFI disabled.
            $script = 'require '.var_export(dirname(__DIR__).'/bootstrap.php', true).'; $class = '.var_export($class, true).'; $class::useW426RustFfi(); $args = '.var_export([self::fixtures($helper, 2, false)], true).'; echo serialize($class::'.$helper.'Native(...$args));';
            $command = escapeshellarg(PHP_BINARY).' -d ffi.enable=false -r '.escapeshellarg($script);
            $lines = [];
            exec($command, $lines, $status);
            $this->assertSame(0, $status);
            $this->assertSame(serialize($class::{$helper.'Php'}(self::fixtures($helper, 2, false))), implode("\n", $lines));
        }
    }

    public function test_oversized_batches_and_frames_fall_back(): void
    {
        foreach (self::HELPERS as $helper => $_) {
            $rows = array_fill(0, 100_001, self::fixtures($helper, 1, false)[0]);
            $this->assertParity($helper, [$rows]);
        }
        foreach (['summarizeFilePersistenceBatch', 'summarizeArchiveFallbackBatch', 'summarizeListingFileBatch'] as $helper) {
            $row = self::fixtures($helper, 1, false)[0];
            $row['name'] = str_repeat('x', 16_777_216);
            $this->assertParity($helper, [[$row]]);
        }
    }

    public function test_randomized_fuzz_parity_at_least_twelve_thousand_assertions(): void
    {
        $this->ffiOrFail();
        $random = new Randomizer(new Mt19937(426));
        $edges = [PHP_INT_MIN, -1, 0, 1, 11, PHP_INT_MAX - 1, PHP_INT_MAX];
        for ($iteration = 0; $iteration < 850; $iteration++) {
            foreach (self::HELPERS as $helper => $_) {
                $rows = self::fixtures($helper, $random->getInt(0, 18), true, $iteration);
                foreach ($rows as &$row) {
                    if ($helper === 'summarizeSearchSyncBatch') {
                        $row['op'] = $random->getInt(0, 3);
                        $row['release_id'] = $edges[$random->getInt(0, count($edges) - 1)];
                    }
                    if (isset($row['name']) && $iteration % 5 === 0) {
                        $names = ['', "a\0b", "bad\xC2\x80", "raw\x85", "x.JPEG\n", "x.png\n\n", '久Å'];
                        $row['name'] = $names[$random->getInt(0, count($names) - 1)];
                    }
                }
                unset($row);
                if ($iteration % 7 === 0 && $rows !== []) {
                    $rows[$random->getInt(0, count($rows) - 1)] = null;
                }
                $args = [$rows];
                if ($helper === 'summarizeFilePersistenceBatch') {
                    $args[] = $edges[$random->getInt(0, count($edges) - 1)];
                } elseif ($helper === 'summarizeSearchSyncBatch') {
                    $args[] = (bool) $random->getInt(0, 1);
                }
                $this->assertParity($helper, $args);
            }
        }
    }

    public function test_raw_ffi_buffer_contract_and_malformed_frames(): void
    {
        $ffi = $this->ffiOrFail();
        $frames = [
            1 => pack('qVqV', 1, 1, 2, 1).'a',
            2 => pack('qVqCCV', 0, 1, 2, 0, 1, 5).'x.png',
            3 => pack('qVqqqq', 1, 2, 0, PHP_INT_MIN, 1, PHP_INT_MIN),
            4 => pack('qVqCCV', 0, 1, 2, 1, 0, 1).'a',
            5 => pack('qVqqqq', 0, 1, 2, 3, 4, 5),
        ];
        $expectedSlots = [
            1 => [1, 1, 2, 0, 1, 0, 0],
            2 => [1, 1, 1, 1, 2, 0],
            3 => [2, 1, 0, 1, 0, 1, 1, PHP_INT_MIN, 0, 0],
            4 => [1, 1, 2, 1, 0, 0],
            5 => [1, 0, 6, 4, 5, 0],
        ];
        foreach ($frames as $op => $frame) {
            $class = array_values(self::HELPERS)[$op - 1];
            $bridge = new ReflectionMethod($class, 'w426Slots');
            $this->assertSame($expectedSlots[$op], $bridge->invoke(null, $op, $frame, count($expectedSlots[$op])));
            $this->assertNull($bridge->invoke(null, $op, $frame.'x', count($expectedSlots[$op])));
            $bytes = match ($op) {
                1 => 56, 3 => 80, default => 48
            };
            foreach ([0, 1, $bytes - 1, $bytes] as $capacity) {
                $out = $ffi->new('char[80]');
                FFI::memset($out, 90, 80);
                // @phpstan-ignore method.notFound (Dynamically declared C ABI symbol.)
                $written = $ffi->nntmux_w426_additional_file_manager_fallback_sync_sevenzip($op, $frame, strlen($frame), $out, $capacity);
                $this->assertSame($capacity < $bytes ? -$bytes : $bytes, $written);
                if ($capacity < $bytes) {
                    $this->assertSame(str_repeat('Z', 80), FFI::string($out, 80));
                }
            }
            $badFrames = [$frame.'x', substr_replace($frame, pack('V', 100_001), 8, 4)];
            for ($i = 0; $i < strlen($frame); $i++) {
                $badFrames[] = substr($frame, 0, $i);
            }
            foreach ($badFrames as $bad) {
                $out = $ffi->new('char[80]');
                FFI::memset($out, 90, 80);
                // @phpstan-ignore method.notFound (Dynamically declared C ABI symbol.)
                $this->assertSame(-1, $ffi->nntmux_w426_additional_file_manager_fallback_sync_sevenzip($op, $bad, strlen($bad), $out, 80));
                $this->assertSame(str_repeat('Z', 80), FFI::string($out, 80));
            }
            $overlap = $ffi->new('char[160]');
            FFI::memcpy($overlap, $frame, strlen($frame));
            $before = FFI::string($overlap, 160);
            // @phpstan-ignore method.notFound (Dynamically declared C ABI symbol.)
            $this->assertSame(-1, $ffi->nntmux_w426_additional_file_manager_fallback_sync_sevenzip($op, $overlap, strlen($frame), $overlap, 80));
            $this->assertSame($before, FFI::string($overlap, 160));
        }
    }

    /** @param list<mixed> $args */
    private function assertParity(string $helper, array $args): void
    {
        $class = self::HELPERS[$helper];
        $expected = $class::{$helper.'Php'}(...$args);
        $this->assertSame($expected, $class::{$helper.'Native'}(...$args), $helper.' native');
        $class::useW426RustFfi(false);
        $this->assertSame($expected, $class::$helper(...$args), $helper.' disabled');
        $class::useW426RustFfi();
        $this->assertSame($expected, $class::$helper(...$args), $helper.' enabled');
        $class::resetW426RustFfi();
    }

    private function ffiOrFail(): FFI
    {
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $ffi = RustPipelineLibrary::resolveDomain('additional', self::CDEF, dirname(__DIR__, 2).'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix);
        $this->assertInstanceOf(FFI::class, $ffi, 'Build the release library with Wave 42.6 before running parity tests.');

        return $ffi;
    }

    /**
     * Reproduce from the repository root (after the release build):
     * php -r 'require "tests/bootstrap.php"; require "tests/Unit/RustAdditionalFileManagerFallbackSyncSevenZipW42ParityTest.php"; Tests\Unit\RustAdditionalFileManagerFallbackSyncSevenZipW42ParityTest::benchmark("/tmp/w426-benchmark");'
     *
     * Pure CPU experiment: 16 frozen fixture variants, 100 paired warmups and 5,000
     * paired observations per helper/workload, seeded randomized order. Timings cover
     * each public *Php/*Native call, including validation, packing, FFI and decoding.
     * Input generation, parity validation and artifact writes are outside timings.
     * Common batches have 64 rows; uncommon batches have 1,024 rows with binary-safe
     * UTF-8 names, terminal LF image suffixes, signed IDs and saturating integers.
     * The p99 band uses order statistics at p +/- 1.96*sqrt(p*(1-p)/n), clipped to [0,1].
     */
    public static function benchmark(string $directory): void
    {
        if (file_exists($directory)) {
            throw new \RuntimeException('Use a new artifact directory.');
        }
        if (! mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot create artifact directory.');
        }
        $source = [];
        foreach (array_merge(array_values(self::HELPERS), [self::class]) as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $source[$file] = hash_file('sha256', $file);
        }
        $root = dirname(__DIR__, 2);
        foreach (['rust/pipeline-ffi/src/additional.rs', 'rust/pipeline-ffi/Cargo.lock', 'composer.lock'] as $file) {
            $source[$root.'/'.$file] = hash_file('sha256', $root.'/'.$file);
        }
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $library = $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix;
        $pinnedFfi = RustPipelineLibrary::resolveDomain('additional', self::CDEF, $library);
        if ($pinnedFfi === null) {
            throw new \RuntimeException('The release library does not expose Wave 42.6.');
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'int_size' => PHP_INT_SIZE,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'memory_limit' => ini_get('memory_limit'),
            'head' => trim((string) shell_exec('git rev-parse HEAD')), 'rustc' => trim((string) shell_exec('rustc --version')),
            'cargo' => trim((string) shell_exec('cargo --version')), 'hardware' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string hw.memsize' : 'uname -m')),
            'library_path' => realpath($library), 'library_resolution' => 'explicit release handle pinned before warmups', 'library_sha256' => hash_file('sha256', $library), 'source_sha256' => $source, 'seed' => 426,
            'profile' => 'cargo build --release', 'pairs' => 5000, 'warmups' => 100, 'variants' => 16, 'common_rows' => 64, 'uncommon_rows' => 1024];
        file_put_contents($directory.'/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $raw = fopen($directory.'/raw.jsonl', 'xb');
        if ($raw === false) {
            throw new \RuntimeException('Cannot create raw timing artifact.');
        }
        $random = new Randomizer(new Mt19937(426));
        $summary = [];
        try {
            foreach (self::HELPERS as $helper => $class) {
                $accessor = (new ReflectionMethod($class, 'w426State'))->getClosure();
                $state = &$accessor();
                $state['ffi'] = $pinnedFfi;
                unset($state);
                foreach (['common' => 64, 'uncommon' => 1024] as $workload => $count) {
                    $fixtures = [];
                    for ($variant = 0; $variant < 16; $variant++) {
                        $args = [self::fixtures($helper, $count, $workload === 'uncommon', $variant)];
                        if ($helper === 'summarizeSearchSyncBatch') {
                            $args[] = $variant % 2 === 0;
                        } elseif ($helper === 'summarizeFilePersistenceBatch') {
                            $args[] = $variant % 2 === 0 ? 11 : PHP_INT_MAX;
                        }
                        $expected = $class::{$helper.'Php'}(...$args);
                        [$operation, $frame, $slots] = self::benchmarkFrame($helper, $args, $expected);
                        $accepted = (new ReflectionMethod($class, 'w426Slots'))->invoke(null, $operation, $frame, count($slots));
                        if ($accepted !== $slots) {
                            throw new \RuntimeException($helper.' fixture was not accepted by Rust');
                        }
                        if ($expected !== $class::{$helper.'Native'}(...$args)) {
                            throw new \RuntimeException($helper.' parity failed before timing');
                        }
                        $fixtures[] = $args;
                    }
                    $state = (new ReflectionMethod($class, 'w426State'))->invoke(null);
                    if (! $state['ffi'] instanceof FFI) {
                        throw new \RuntimeException($helper.' did not resolve native FFI');
                    }
                    for ($warmup = 0; $warmup < 100; $warmup++) {
                        $args = $fixtures[$warmup % 16];
                        $class::{$helper.'Php'}(...$args);
                        $class::{$helper.'Native'}(...$args);
                    }
                    $php = $native = [];
                    for ($pair = 0; $pair < 5000; $pair++) {
                        $variant = $random->getInt(0, 15);
                        $args = $fixtures[$variant];
                        $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                        $timings = [];
                        foreach ($order as $implementation) {
                            $start = hrtime(true);
                            $value = $class::{$helper.$implementation}(...$args);
                            $timings[$implementation] = hrtime(true) - $start;
                        }
                        $php[] = $timings['Php'];
                        $native[] = $timings['Native'];
                        fwrite($raw, json_encode(['helper' => $helper, 'workload' => $workload, 'pair' => $pair, 'variant' => $variant, 'order' => $order, 'ns' => $timings], JSON_THROW_ON_ERROR)."\n");
                    }
                    sort($php, SORT_NUMERIC);
                    sort($native, SORT_NUMERIC);
                    $meanPhp = array_sum($php) / count($php);
                    $meanNative = array_sum($native) / count($native);
                    $rank = (int) ceil(0.99 * count($php)) - 1;
                    $band = 1.96 * sqrt(0.99 * 0.01 / count($php));
                    $low = (int) ceil((0.99 - $band) * count($php)) - 1;
                    $high = (int) ceil((0.99 + $band) * count($php)) - 1;
                    $summary[$helper][$workload] = ['pairs' => 5000, 'php_mean_ns' => $meanPhp, 'native_mean_ns' => $meanNative,
                        'php_p99_ns' => $php[$rank], 'native_p99_ns' => $native[$rank],
                        'mean_speedup' => $meanPhp / $meanNative, 'p99_speedup' => $php[$rank] / $native[$rank],
                        'php_p99_order_band_ns' => [$php[$low], $php[$high]], 'native_p99_order_band_ns' => [$native[$low], $native[$high]]];
                    echo $helper.' '.$workload.' '.json_encode($summary[$helper][$workload], JSON_THROW_ON_ERROR)."\n";
                }
                $summary[$helper]['qualified'] = min($summary[$helper]['common']['mean_speedup'], $summary[$helper]['common']['p99_speedup'], $summary[$helper]['uncommon']['mean_speedup'], $summary[$helper]['uncommon']['p99_speedup']) >= 1.5;
                $class::resetW426RustFfi();
            }
            foreach ($source as $file => $digest) {
                if (hash_file('sha256', $file) !== $digest) {
                    throw new \RuntimeException('Source changed during benchmark: '.$file);
                }
            }
            if (hash_file('sha256', $library) !== $metadata['library_sha256']) {
                throw new \RuntimeException('Release library changed during benchmark.');
            }
            file_put_contents($directory.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            file_put_contents($directory.'/failure.txt', (string) $error);
            throw $error;
        } finally {
            fclose($raw);
        }
    }

    /**
     * Independently encode the published ABI and verify its result before timing.
     *
     * @param  list<mixed>  $args
     * @param  array<string, int|bool|null>  $expected
     * @return array{int, string, list<int>}
     */
    private static function benchmarkFrame(string $helper, array $args, array $expected): array
    {
        $operation = array_search($helper, array_keys(self::HELPERS), true) + 1;
        $parameter = match ($operation) {
            1 => $args[1] ?? 11, 3 => (int) ($args[1] ?? true), default => 0
        };
        $frame = pack('qV', $parameter, count($args[0]));
        foreach ($args[0] as $row) {
            $frame .= match ($operation) {
                1 => pack('qV', $row['size'], strlen($row['name'])).$row['name'],
                2 => pack('qCCV', $row['size'], $row['pass'], (int) $row['stored'], strlen($row['name'])).$row['name'],
                3 => pack('qq', $row['op'], $row['release_id']),
                4 => pack('qCCV', $row['size'], $row['pass'], $row['compressed'], strlen($row['name'])).$row['name'],
                5 => pack('qqqq', $row['batch_size'], $row['threads'], $row['timeout_seconds'], $row['max_bytes']),
                default => throw new \InvalidArgumentException('Unknown helper '.$helper),
            };
        }
        $slots = $operation === 3
            ? [$expected['total'], $expected['requests'], $expected['executions'], $expected['deferred_requests'], $expected['discarded_pending'], (int) $expected['pending'], (int) ($expected['scoped_release'] !== null), $expected['scoped_release'] ?? 0, 0, 0]
            : array_map(static fn (int|bool|null $value): int => (int) $value, array_values($expected));

        return [$operation, $frame, $slots];
    }

    /** @return list<array<string, mixed>> */
    private static function fixtures(string $helper, int $count, bool $uncommon, int $variant = 0): array
    {
        $rows = [];
        $random = new Randomizer(new Mt19937(426 + $variant));
        $extremes = [0, 1, 11, PHP_INT_MAX, -1, PHP_INT_MIN];
        for ($i = 0; $i < $count; $i++) {
            $n = $uncommon ? $extremes[$random->getInt(0, 5)] : $i + 1;
            $name = $uncommon ? str_repeat('久Å', ($i + $variant) % 40 + 1).['.JPEG', '.mkv', ".png\n", '.webp'][$random->getInt(0, 3)] : 'file-'.$i.'.png';
            if ($helper === 'summarizeListingFileBatch') {
                // A valid uncommon listing path; LF rejection is tested separately.
                $name = str_replace("\n", '', $name);
            }
            $rows[] = match ($helper) {
                'summarizeFilePersistenceBatch' => ['name' => $name, 'size' => max(0, $n)],
                'summarizeArchiveFallbackBatch' => ['name' => $name, 'size' => max(0, $n), 'pass' => $i % 2, 'stored' => $i % 3 === 0],
                'summarizeSearchSyncBatch' => ['op' => $i % 4, 'release_id' => $uncommon ? $n : intdiv($i, 4)],
                'summarizeListingFileBatch' => ['name' => $name, 'size' => max(0, $n), 'pass' => $i % 2, 'compressed' => $i % 3 === 0 ? 0 : 1],
                'summarizeLimitConfigurationBatch' => ['batch_size' => $n, 'threads' => $uncommon ? $extremes[$random->getInt(0, 5)] : 4, 'timeout_seconds' => $n, 'max_bytes' => $n],
                default => throw new \InvalidArgumentException('Unknown helper '.$helper),
            };
        }

        return $rows;
    }
}
