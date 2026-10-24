<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\DatabaseNzbStore;
use App\Services\Releases\Verification\RemotePublicationService;
use App\Services\Releases\Verification\RemoteReleasePublisher;
use App\Services\Releases\Verification\ScratchInventory;
use App\Services\Releases\Verification\VerifiedReleasePublisher;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

final class RustVerificationPublishNzbStoreParityTest extends TestCase
{
    private const CLASSES = [DatabaseNzbStore::class, RemoteReleasePublisher::class, RemotePublicationService::class, VerifiedReleasePublisher::class, ScratchInventory::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'setUseRustFfi')) {
                $class::setUseRustFfi(false);
            }
        }
        parent::tearDown();
    }

    public function test_candidates_preserve_literal_edge_contracts(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertTrue(method_exists($class, 'setUseRustFfi'), $class.' must expose a controlled candidate path');
            $class::setUseRustFfi(true);
            self::assertNotNull(RustPipelineLibrary::resolveDomain('nfo', $class::FFI_CDEF));
        }
        foreach ($this->cases() as [$class, $method, $args, $expected]) {
            self::assertSame($expected, $class::{$method.'Php'}(...$args), $class.'::'.$method.'Php');
            self::assertSame($expected, $class::{$method.'Native'}(...$args), $class.'::'.$method.'Native');
            self::assertSame($expected, $class::$method(...$args), $class.'::'.$method);
        }
    }

    /** @return list<array{class-string, string, array<mixed>, mixed}> */
    private function cases(): array
    {
        return [
            [DatabaseNzbStore::class, 'payloadMetadata', ['abc'], ['source_digest' => hash('sha256', "nntmux:nzb-archive-naming:1\0abc"), 'decoded_bytes' => 3]],
            [DatabaseNzbStore::class, 'gzipHeader', ["\x1f\x8b\0"], true],
            [DatabaseNzbStore::class, 'gzipHeader', ["\x1f"], false],
            [DatabaseNzbStore::class, 'chunkFits', [4, 6, 10], true],
            [DatabaseNzbStore::class, 'chunkFits', [PHP_INT_MAX, 1, PHP_INT_MAX], false],
            [RemoteReleasePublisher::class, 'publicationGuard', [true, 'sampled'], 1],
            [RemoteReleasePublisher::class, 'publicationGuard', [false, 'sampled'], 0],
            [RemoteReleasePublisher::class, 'publicationGuard', [true, 'verified'], 2],
            [RemoteReleasePublisher::class, 'endpointUrl', ['https://host/path/'], 'https://host/path/'],
            [RemoteReleasePublisher::class, 'batchFallback', [404, false, false], true],
            [RemoteReleasePublisher::class, 'batchFallback', [422, false, false], false],
            [RemoteReleasePublisher::class, 'batchFallback', [422, true, false], true],
            [RemoteReleasePublisher::class, 'errorMessage', [str_repeat('é', 251)], str_repeat('é', 250)],
            [RemotePublicationService::class, 'batchPartition', [[3 => 35, 7 => 50, 9 => 70, 11 => 35], 25, 60], [
                ['batch' => true, 'positions' => [3]], ['batch' => true, 'positions' => [7]],
                ['batch' => false, 'positions' => [9]], ['batch' => true, 'positions' => [11]],
            ]],
            [RemotePublicationService::class, 'settledOutcome', [12, 'filtered', true], ['status' => 'published', 'release_id' => 12]],
            [RemotePublicationService::class, 'settledOutcome', [null, 'filtered', false], ['status' => null, 'release_id' => null]],
            [RemotePublicationService::class, 'ledgerAdjustment', [32, false], -32],
            [RemotePublicationService::class, 'ledgerAdjustment', [32, true], 0],
            [VerifiedReleasePublisher::class, 'releaseAttributes', [['size' => 1], ['size' => 5, 'guid' => 'x']], ['size' => 1, 'guid' => 'x']],
            [VerifiedReleasePublisher::class, 'completionPercentage', [1, 4], 25.0],
            [VerifiedReleasePublisher::class, 'completionPercentage', [5, 4], 100.0],
            [VerifiedReleasePublisher::class, 'completionPercentage', [5, 0], 0.0],
            [VerifiedReleasePublisher::class, 'publicationGuard', [['version' => 1, 'manifest_hash' => 'h', 'lease_token' => 't', 'lease_until' => '2026-10-24 10:00:00'], ['version' => 1, 'manifest_hash' => 'h', 'lease_token' => 't'], '2026-10-24 10:00:00'], true],
            [VerifiedReleasePublisher::class, 'publicationGuard', [['version' => '1', 'manifest_hash' => 'h', 'lease_token' => 't', 'lease_until' => null], ['version' => 1, 'manifest_hash' => 'h', 'lease_token' => 't'], '2026-10-24 10:00:00'], false],
            [ScratchInventory::class, 'scratchName', [null, 42, 9, 4], '42-9'],
            [ScratchInventory::class, 'scratchName', [12, 42, 9, 4], 'audit-12-4'],
            [ScratchInventory::class, 'filenameClass', ['audit-12-4'], 'audit'],
            [ScratchInventory::class, 'filenameClass', ["12-4\n"], 'unknown'],
            [ScratchInventory::class, 'filenameClass', ['../12-4'], 'unknown'],
            [ScratchInventory::class, 'expired', [90, 100, 10], false],
            [ScratchInventory::class, 'expired', [89, 100, 10], true],
            [ScratchInventory::class, 'expired', [101, 100, 0], false],
            [ScratchInventory::class, 'summary', [[['audit_release_id' => null, 'terminal' => false], ['audit_release_id' => 1, 'terminal' => true]]], ['total' => 2, 'candidate' => 1, 'audit' => 1, 'terminal' => 1]],
            [DatabaseNzbStore::class, 'validPayload', ['abc', hash('sha256', "nntmux:nzb-archive-naming:1\0abc"), 3], true],
            [DatabaseNzbStore::class, 'validPayload', ['abc', hash('sha256', "nntmux:nzb-archive-naming:1\0abc"), 2], false],
            [DatabaseNzbStore::class, 'validPayload', ['abc', '', 3], false],
            [RemoteReleasePublisher::class, 'endpointUrl', [" \thttps://host/path/?a=%20#x\r\n"], 'https://host/path/?a=%20#x'],
            [ScratchInventory::class, 'scratchName', [null, '0042', '009', 0], '0042-009'],
        ];
    }

    public function test_disabled_and_unavailable_library_fall_back(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertFalse((new ReflectionClass($class))->getDefaultProperties()['useRustFfi']);
            $class::setUseRustFfi(false);
        }
        foreach ($this->cases() as [$class, $method, $args, $expected]) {
            self::assertNull($class::{$method.'Native'}(...$args));
            self::assertSame($expected, $class::$method(...$args));
        }
        $property = new \ReflectionProperty(RustPipelineLibrary::class, 'domainLibraries');
        $saved = $property->getValue();
        try {
            $cache = $saved;
            foreach (self::CLASSES as $class) {
                $cache['nfo|default|'.hash('xxh3', $class::FFI_CDEF)] = false;
                $class::setUseRustFfi(true);
            }
            $property->setValue(null, $cache);
            foreach ($this->cases() as [$class, $method, $args, $expected]) {
                self::assertNull($class::{$method.'Native'}(...$args));
                self::assertSame($expected, $class::$method(...$args));
            }
        } finally {
            $property->setValue(null, $saved);
        }
    }

    public function test_c_abi_rejects_bad_ranges_and_does_not_write_short_buffers(): void
    {
        foreach (self::CLASSES as $class) {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', $class::FFI_CDEF);
            self::assertNotNull($ffi);
            preg_match('/ssize_t (\w+)/', $class::FFI_CDEF, $match);
            $symbol = $match[1];
            $out = $ffi->new('char[64]');
            FFI::memset($out, 88, 64);
            self::assertSame(-1, $ffi->$symbol(0, null, 1, $out, 64));
            self::assertSame(-1, $ffi->$symbol(0, 'N;', 2, null, 1));
            self::assertSame(-1, $ffi->$symbol(999, 'N;', 2, $out, 64));
            self::assertSame(-1, $ffi->$symbol(0, $out, 2, $out, 64));
            self::assertSame(-1, $ffi->$symbol(0, 'N;', PHP_INT_MAX, $out, 64));
            self::assertSame(str_repeat('X', 64), FFI::string($out, 64));
        }
        $ffi = RustPipelineLibrary::resolveDomain('nfo', DatabaseNzbStore::FFI_CDEF);
        $symbol = 'nntmux_w16_nzb';
        $input = serialize(['abc']);
        // @phpstan-ignore method.notFound
        $needed = $ffi->$symbol(0, $input, strlen($input), null, 0);
        self::assertLessThan(-1, $needed);
        $out = $ffi->new('char['.(-$needed).']');
        FFI::memset($out, 88, -$needed);
        // @phpstan-ignore method.notFound
        self::assertSame($needed, $ffi->$symbol(0, $input, strlen($input), $out, -$needed - 1));
        self::assertSame(str_repeat('X', -$needed), FFI::string($out, -$needed));
        // @phpstan-ignore method.notFound
        self::assertSame(-$needed, $ffi->$symbol(0, $input, strlen($input), $out, -$needed));
    }

    public function test_real_decode_and_inventory_callers_preserve_boundaries(): void
    {
        foreach ([false, true] as $enabled) {
            DatabaseNzbStore::setUseRustFfi($enabled);
            ScratchInventory::setUseRustFfi($enabled);
            $body = gzencode(str_repeat('<nzb/>', 1000));
            self::assertSame(str_repeat('<nzb/>', 1000), DatabaseNzbStore::decode($body, 6000));
            self::assertFalse(DatabaseNzbStore::decode($body, 5999));
            self::assertFalse(DatabaseNzbStore::decode($body.$body, 12000));
            self::assertFalse(DatabaseNzbStore::decode(substr($body, 0, -2), 6000));
            self::assertSame('audit-3-8', (new ScratchInventory)->directoryName((object) ['audit_release_id' => 3, 'id' => 8]));
        }
    }

    public function test_randomized_binary_and_numeric_parity_and_declined_inputs(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        $random = new Randomizer(new Mt19937(161024));
        for ($i = 0; $i < 500; $i++) {
            $xml = $random->getBytes($random->getInt(1, 2048));
            $digest = DatabaseNzbStore::payloadMetadataPhp($xml)['source_digest'];
            self::assertSame(DatabaseNzbStore::payloadMetadataPhp($xml), DatabaseNzbStore::payloadMetadataNative($xml));
            self::assertTrue(DatabaseNzbStore::validPayloadNative($xml, $digest, strlen($xml)));
            self::assertFalse(DatabaseNzbStore::validPayloadNative($xml, $digest, strlen($xml) - 1));
            $found = $random->getInt(-100, 10000);
            $expected = $random->getInt(-1, 10000);
            self::assertSame(VerifiedReleasePublisher::completionPercentagePhp($found, $expected), VerifiedReleasePublisher::completionPercentageNative($found, $expected));
            $sizes = [];
            for ($n = 0; $n < 32; $n++) {
                $sizes[$n * 3] = $random->getInt(25, 5000);
            }
            $limit = $random->getInt(25, 10000);
            self::assertSame(RemotePublicationService::batchPartitionPhp($sizes, 25, $limit), RemotePublicationService::batchPartitionNative($sizes, 25, $limit));
            $metadata = [0 => $xml, 'nested' => [$i, null, false], 'x' => INF];
            $defaults = [0 => 'ignored', 1 => true, 'tail' => $found];
            self::assertSame(VerifiedReleasePublisher::releaseAttributesPhp($metadata, $defaults), VerifiedReleasePublisher::releaseAttributesNative($metadata, $defaults));
            self::assertSame(RemoteReleasePublisher::endpointUrlPhp($xml), RemoteReleasePublisher::endpointUrlNative($xml));
        }
        self::assertNull(RemoteReleasePublisher::errorMessageNative("bad\xfftext"));
        self::assertSame(RemoteReleasePublisher::errorMessagePhp("bad\xfftext"), RemoteReleasePublisher::errorMessage("bad\xfftext"));
        self::assertNull(ScratchInventory::expiredNative(PHP_INT_MIN, PHP_INT_MAX, 1));
        self::assertTrue(ScratchInventory::expired(PHP_INT_MIN, PHP_INT_MAX, 1));
        $value = new class
        {
            public function __serialize(): array
            {
                throw new \LogicException('The native candidate must not invoke object serialization.');
            }
        };
        self::assertNull(VerifiedReleasePublisher::releaseAttributesNative(['object' => $value], []));
        self::assertSame(['object' => $value], VerifiedReleasePublisher::releaseAttributes(['object' => $value], []));
        $nested = [];
        for ($i = 0; $i < 40; $i++) {
            $nested = [$nested];
        }
        self::assertNull(VerifiedReleasePublisher::releaseAttributesNative($nested, []));
        self::assertSame($nested, VerifiedReleasePublisher::releaseAttributes($nested, []));
    }

    public function test_batch_groups_match_the_original_json_body_accounting(): void
    {
        $remote = new RemoteReleasePublisher;
        (new \ReflectionProperty($remote, 'advertisedMaxBytes'))->setValue($remote, 100);
        $groups = new \ReflectionMethod($remote, 'groups');
        $reference = new \ReflectionMethod($remote, 'groupsPhp');
        $random = new Randomizer(new Mt19937(161024));
        for ($i = 0; $i < 100; $i++) {
            $payloads = [];
            for ($j = 0; $j < 16; $j++) {
                $payloads[$j * 2] = ['claim' => ['id' => $j], 'reason' => str_repeat('é', $random->getInt(0, 50))];
            }
            if ($i % 2 === 0) {
                $payloads[7] = ['reason' => "bad\xfftext"];
            }
            foreach ([false, true] as $enabled) {
                RemotePublicationService::setUseRustFfi($enabled);
                self::assertSame($reference->invoke($remote, $payloads), $groups->invoke($remote, $payloads));
            }
        }
    }

    public function test_opt_in_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_VERIFICATION_PUBLISH_NZB_BENCH') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_VERIFICATION_PUBLISH_NZB_BENCH=1.');
        }
        $random = new Randomizer(new Mt19937(161024));
        $raw = $summary = [];
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        foreach (['common', 'uncommon'] as $workload) {
            $jobs = $this->cases();
            // Include the caller's serialization, resolution, allocation and decoding.
            if ($workload === 'uncommon') {
                foreach ($jobs as &$job) {
                    [$class, $method] = $job;
                    $job[2] = match ($class.'::'.$method) {
                        DatabaseNzbStore::class.'::payloadMetadata' => [str_repeat("large\0é", 16000)],
                        DatabaseNzbStore::class.'::gzipHeader' => ["\x1f\x8b".str_repeat('x', 100000)],
                        DatabaseNzbStore::class.'::chunkFits' => [PHP_INT_MAX - 5, 5, PHP_INT_MAX],
                        DatabaseNzbStore::class.'::validPayload' => [str_repeat('x', 100000), hash('sha256', "nntmux:nzb-archive-naming:1\0".str_repeat('x', 100000)), 100000],
                        RemoteReleasePublisher::class.'::publicationGuard' => [true, str_repeat('x', 2000)],
                        RemoteReleasePublisher::class.'::endpointUrl' => ["\t https://host/".str_repeat('path/%20/', 2000)."\r\n"],
                        RemoteReleasePublisher::class.'::batchFallback' => [422, false, true],
                        RemoteReleasePublisher::class.'::errorMessage' => [str_repeat('é', 16000)],
                        RemotePublicationService::class.'::batchPartition' => [array_fill(0, 64, 35), 25, 100],
                        RemotePublicationService::class.'::settledOutcome' => [null, 'metadata_review', true],
                        RemotePublicationService::class.'::ledgerAdjustment' => [PHP_INT_MAX, false],
                        VerifiedReleasePublisher::class.'::releaseAttributes' => [array_fill_keys(range('a', 'z'), str_repeat('x', 1000)), ['size' => 999, 'tail' => [true, null, 3]]],
                        VerifiedReleasePublisher::class.'::completionPercentage' => [PHP_INT_MAX - 3, PHP_INT_MAX],
                        VerifiedReleasePublisher::class.'::publicationGuard' => [['version' => PHP_INT_MAX, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => str_repeat('b', 64), 'lease_until' => '2026-10-24 10:00:00'], ['version' => PHP_INT_MAX, 'manifest_hash' => str_repeat('a', 64), 'lease_token' => str_repeat('b', 64)], '2026-10-24 09:59:59'],
                        ScratchInventory::class.'::scratchName' => [PHP_INT_MAX, 0, 0, PHP_INT_MAX],
                        ScratchInventory::class.'::filenameClass' => ['audit-'.str_repeat('9', 4096).'-9'],
                        ScratchInventory::class.'::expired' => [PHP_INT_MAX - 31, PHP_INT_MAX, 30],
                        ScratchInventory::class.'::summary' => [array_fill(0, 64, ['audit_release_id' => 1, 'terminal' => true])],
                        default => $job[2],
                    };
                }
                unset($job);
            }
            foreach ($jobs as [$class, $method, $args]) {
                $key = $class.'::'.$method;
                if (isset($raw[$workload][$key])) {
                    continue;
                }
                $samples = ['php' => [], 'native' => []];
                for ($i = -200; $i < 10000; $i++) {
                    $order = $random->getInt(0, 1) ? ['php', 'native'] : ['native', 'php'];
                    $results = [];
                    foreach ($order as $path) {
                        $call = $method.($path === 'php' ? 'Php' : 'Native');
                        $start = hrtime(true);
                        $results[$path] = $class::$call(...$args);
                        $elapsed = hrtime(true) - $start;
                        if ($i >= 0) {
                            $samples[$path][] = $elapsed;
                        }
                    }
                    self::assertSame($results['php'], $results['native'], $key);
                }
                $raw[$workload][$key] = $samples;
                foreach ($samples as $path => $values) {
                    sort($values);
                    $summary[$workload][$key][$path] = ['mean_ns' => array_sum($values) / count($values), 'p99_ns' => $values[(int) ceil(count($values) * .99) - 1]];
                }
                foreach (['mean_ns', 'p99_ns'] as $metric) {
                    $summary[$workload][$key]['speedup'][$metric] = $summary[$workload][$key]['php'][$metric] / $summary[$workload][$key]['native'][$metric];
                }
            }
        }
        $meta = ['seed' => 161024, 'pairs' => 10000, 'warmup' => 200, 'php' => PHP_VERSION, 'platform' => php_uname(), 'head' => trim((string) shell_exec('git rev-parse HEAD')), 'rustc' => trim((string) shell_exec('rustc -V')), 'cargo' => trim((string) shell_exec('cargo -V')), 'profile' => 'release opt-level=3 lto=true codegen-units=1', 'resource_limits' => 'host defaults; single process', 'failures' => [], 'source' => []];
        foreach (array_merge(array_map(fn ($class) => (new ReflectionClass($class))->getFileName(), self::CLASSES), [__FILE__, dirname(__DIR__, 2).'/rust/pipeline-ffi/src/nfo.rs', dirname(__DIR__, 2).'/composer.lock', dirname(__DIR__, 2).'/vendor/composer/installed.json', dirname(__DIR__, 2).'/rust/pipeline-ffi/Cargo.lock', dirname(__DIR__, 2).'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so')]) as $file) {
            $meta['source'][$file] = hash_file('sha256', $file);
        }
        file_put_contents('/tmp/wave16-worker2-benchmark.raw.json', json_encode(['meta' => $meta, 'samples' => $raw], JSON_THROW_ON_ERROR));
        file_put_contents('/tmp/wave16-worker2-benchmark.summary.json', json_encode(['meta' => $meta, 'summary' => $summary], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nBenchmark samples: /tmp/wave16-worker2-benchmark.{raw,summary}.json\n");
    }
}
