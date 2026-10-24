<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\NzbPasswordMetadataRejected;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbXmlElements;
use App\Services\Releases\Verification\CandidateSampleBatch;
use App\Services\Releases\Verification\PublicationTiming;
use App\Support\Data\NzbCreationResult;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class RustNzbXmlVerificationTimingParityTest extends TestCase
{
    private const CLASSES = [NzbXmlElements::class, NfoImportService::class, CandidateSampleBatch::class, PublicationTiming::class, NzbCreationResult::class];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            if (method_exists($class, 'setUseRustFfi')) {
                $class::setUseRustFfi(false);
            }
        }
        parent::tearDown();
    }

    /** @return list<array{class-string, string, list<mixed>, mixed}> */
    private static function cases(): array
    {
        return [
            [NzbXmlElements::class, 'validRoot', ['nzb', ''], true],
            [NzbXmlElements::class, 'validRoot', ['nzb', NzbXmlElements::CANONICAL_NAMESPACE], true],
            [NzbXmlElements::class, 'validRoot', ['NZB', ''], false],
            [NzbXmlElements::class, 'validRoot', ['nzb', 'urn:foreign'], false],
            [NzbXmlElements::class, 'validPasswordStructure', [1, true, false], true],
            [NzbXmlElements::class, 'validPasswordStructure', [2, true, false], false],
            [NzbXmlElements::class, 'validPasswordStructure', [2, false, false], true],
            [NzbXmlElements::class, 'validPasswordStructure', [0, false, true], false],
            [NzbXmlElements::class, 'isPasswordMetadata', ['password'], true],
            [NzbXmlElements::class, 'isPasswordMetadata', ["password\0"], false],
            [NfoImportService::class, 'normalizeManifestEntry', [['release_id' => 1, 'release_guid' => 'legacy-guid', 'other' => 'kept outside identity']], ['release_id' => 1, 'release_guid' => 'legacy-guid']],
            [NfoImportService::class, 'normalizeManifestEntry', [['release_id' => '1', 'release_guid' => 'x']], null],
            [NfoImportService::class, 'normalizeManifestEntry', [['release_id' => 0, 'release_guid' => 'x']], null],
            [NfoImportService::class, 'normalizeManifestEntry', [[]], null],
            [NfoImportService::class, 'validGuid', [' '], true],
            [NfoImportService::class, 'validGuid', ["\0"], true],
            [NfoImportService::class, 'validGuid', [''], false],
            [NfoImportService::class, 'validGuid', [17], false],
            [NfoImportService::class, 'importSummary', [[['status' => 'imported'], ['status' => 'failed'], ['status' => 'skipped'], ['status' => 'unknown'], []]], ['imported' => 1, 'failed' => 1, 'skipped' => 1]],
            [NfoImportService::class, 'importSummary', [[]], ['imported' => 0, 'failed' => 0, 'skipped' => 0]],
            [CandidateSampleBatch::class, 'pendingCount', [0], false],
            [CandidateSampleBatch::class, 'pendingCount', [3], true],
            [CandidateSampleBatch::class, 'claimsLimit', [0, -1], true],
            [CandidateSampleBatch::class, 'claimsLimit', [4, 5], false],
            [CandidateSampleBatch::class, 'verifiedRate', [100, 12.0, 10.0], 50.0],
            [CandidateSampleBatch::class, 'verifiedRate', [100, 10.0, 12.0], 100000.0],
            [CandidateSampleBatch::class, 'claimBudget', [10, 3, 20, 5], 5],
            [CandidateSampleBatch::class, 'claimBudget', [10, 10, 16, 8], 0],
            [CandidateSampleBatch::class, 'claimBudget', [PHP_INT_MAX, PHP_INT_MIN, 99, 99], 16],
            [CandidateSampleBatch::class, 'claimBudget', [2, 0, 0, -1], 1],
            [PublicationTiming::class, 'accumulateDuration', [15, 100, 90], 25],
            [PublicationTiming::class, 'accumulateDuration', [0, 80, 90], -10],
            [PublicationTiming::class, 'attemptSummary', [['state_lock_acquisition' => 7, 'write' => 11, 'commit' => 3]], ['total_ns' => 21, 'callback_after_state_lock_ns' => 14]],
            [PublicationTiming::class, 'attemptSummary', [[]], ['total_ns' => 0, 'callback_after_state_lock_ns' => 0]],
            [PublicationTiming::class, 'phaseKey', ['write', null], 'write'],
            [PublicationTiming::class, 'phaseKey', ['write', 'prepare'], 'prepare.write'],
            [PublicationTiming::class, 'phaseKey', ["a\0b", ''], "a\0b"],
            [NzbCreationResult::class, 'classifyOutcome', [true, 'none'], 'success'],
            [NzbCreationResult::class, 'classifyOutcome', [false, 'deferred'], 'deferred'],
            [NzbCreationResult::class, 'classifyOutcome', [false, 'unknown'], 'unknown'],
            [NzbCreationResult::class, 'failureMatches', ['deferred', 'deferred'], true],
            [NzbCreationResult::class, 'failureMatches', ['transient', 'deterministic'], false],
        ];
    }

    public function test_pure_helpers_preserve_literal_edge_contracts(): void
    {
        foreach (self::CLASSES as $class) {
            self::assertTrue(method_exists($class, 'setUseRustFfi'), $class.' candidate toggle is missing');
            $class::setUseRustFfi(true);
            self::assertNotNull(RustPipelineLibrary::resolveDomain('nfo', $class::FFI_CDEF));
        }
        foreach (self::cases() as [$class, $method, $args, $expected]) {
            self::assertSame($expected, $class::{$method.'Php'}(...$args), $method.' PHP');
            self::assertSame($expected, $class::{$method.'Native'}(...$args), $method.' native');
            self::assertSame($expected, $class::$method(...$args), $method.' dispatched');
        }
    }

    public function test_xml_callers_preserve_namespaces_entities_and_first_head_traversal(): void
    {
        foreach ([false, true] as $native) {
            NzbXmlElements::setUseRustFfi($native);
            foreach (['', ' xmlns="'.NzbXmlElements::CANONICAL_NAMESPACE.'"'] as $namespace) {
                $xml = simplexml_load_string('<nzb'.$namespace.'><head><meta type="password"> secret </meta></head><file subject="one"><groups><group>g</group></groups><segments><segment>id</segment></segments></file></nzb>');
                self::assertNotFalse($xml);
                $elements = new NzbXmlElements($xml);
                $elements->assertUnambiguousPasswordMetadata();
                self::assertSame(' secret ', (string) iterator_to_array((function () use ($elements) {
                    yield from $elements->metadata();
                })(), false)[0]);
                $files = iterator_to_array((function () use ($elements) {
                    yield from $elements->files();
                })(), false);
                self::assertCount(1, $files);
                self::assertSame('g', (string) iterator_to_array((function () use ($elements, $files) {
                    yield from $elements->groups($files[0]);
                })(), false)[0]);
                self::assertSame('id', (string) iterator_to_array((function () use ($elements, $files) {
                    yield from $elements->segments($files[0]);
                })(), false)[0]);
            }
            foreach ([
                '<nzb><head/><head><meta type="password">p</meta></head></nzb>',
                '<!DOCTYPE nzb [<!ENTITY hidden "p">]><nzb>&hidden;</nzb>',
                '<!DOCTYPE nzb [<!ENTITY hidden "p">]><nzb><head>&hidden;</head></nzb>',
            ] as $xml) {
                $elements = new NzbXmlElements(new \SimpleXMLElement($xml));
                try {
                    $elements->assertUnambiguousPasswordMetadata();
                    self::fail('Ambiguous/entity password metadata was accepted');
                } catch (NzbPasswordMetadataRejected $exception) {
                    self::assertSame('Invalid NZB password metadata structure.', $exception->getMessage());
                }
            }
            foreach ([
                '<nzb xmlns:f="urn:foreign"><head/><head><f:meta type="password"/></head></nzb>',
                '<nzb xmlns:f="urn:foreign"><f:head><f:meta type="password"/></f:head><head/></nzb>',
                '<nzb><head><meta type="Password"/></head><head/></nzb>',
            ] as $xml) {
                (new NzbXmlElements(new \SimpleXMLElement($xml)))->assertUnambiguousPasswordMetadata();
            }
            try {
                new NzbXmlElements(new \SimpleXMLElement('<nzb xmlns="urn:foreign"/>'));
                self::fail('Foreign root accepted');
            } catch (\RuntimeException $exception) {
                self::assertSame('Invalid NZB.', $exception->getMessage());
            }
        }
    }

    public function test_batch_timing_and_readonly_result_callers_use_the_same_state(): void
    {
        foreach ([false, true] as $native) {
            CandidateSampleBatch::setUseRustFfi($native);
            PublicationTiming::setUseRustFfi($native);
            NzbCreationResult::setUseRustFfi($native);
            $reflection = new \ReflectionClass(CandidateSampleBatch::class);
            $batch = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('clock')->setValue($batch, static fn (): float => 12.0);
            self::assertFalse($batch->hasPending());
            self::assertTrue($batch->limitReached(0));
            self::assertSame(50.0, $batch->verifiedBytesPerSecond(100, 10.0));
            $reflection->getProperty('prefetchStartedAt')->setValue($batch, 10.0);
            self::assertSame(50.0, $batch->verifiedBytesPerSecond(100, 0.0));
            self::assertSame(75.0, $batch->verifiedBytesPerSecond(50, 0.0));
            $reflection->getProperty('pending')->setValue($batch, [1 => (object) ['id' => 1]]);
            self::assertTrue($batch->hasPending());
            $timing = PublicationTiming::start();
            $timing->beginAttempt();
            $timingReflection = new \ReflectionClass($timing);
            $timingReflection->getProperty('boundary')->setValue($timing, 10);
            $timingReflection->getMethod('recordPhase')->invoke($timing, 25);
            $timingReflection->getProperty('phase')->setValue($timing, 'write');
            $timingReflection->getMethod('recordPhase')->invoke($timing, 40);
            $timingReflection->getMethod('recordPhase')->invoke($timing, 45);
            $attempt = $timingReflection->getProperty('attempts')->getValue($timing)[0];
            self::assertSame(['state_lock_acquisition' => 15, 'write' => 20], $attempt['phases_ns']);
            $timing->endAttempt(true);
            $attempt = $timingReflection->getProperty('attempts')->getValue($timing)[0];
            self::assertSame($attempt['phases_ns']['write'], $attempt['callback_after_state_lock_ns']);
            self::assertSame('returned', $attempt['callback_status']);
            foreach ([NzbCreationResult::success('/a', [1]), NzbCreationResult::deferred('d'), NzbCreationResult::deterministic('d'), NzbCreationResult::transient('t')] as $index => $result) {
                self::assertSame($index === 1, $result->isDeferred());
                self::assertSame($index === 2, $result->isDeterministicFailure());
                self::assertSame($index === 3, $result->isTransientFailure());
                self::assertSame(['success', 'deferred', 'deterministic', 'transient'][$index], $result->outcome());
            }
            self::assertTrue((new \ReflectionClass(NzbCreationResult::class))->isReadOnly());
        }
    }

    public function test_disabled_missing_library_and_declined_values_fall_back_without_serializing_objects(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(false);
        }
        foreach (self::cases() as [$class, $method, $args, $expected]) {
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
            foreach (self::cases() as [$class, $method, $args, $expected]) {
                self::assertNull($class::{$method.'Native'}(...$args));
                self::assertSame($expected, $class::$method(...$args));
            }
        } finally {
            $property->setValue(null, $saved);
        }
        $object = new class
        {
            public function __serialize(): array
            {
                throw new \RuntimeException('Must never serialize');
            }
        };
        self::assertNull(NfoImportService::validGuidNative($object));
        self::assertFalse(NfoImportService::validGuid($object));
        $cycle = [];
        $cycle['self'] = &$cycle;
        self::assertNull(NfoImportService::normalizeManifestEntryNative($cycle));
        self::assertNull(NfoImportService::normalizeManifestEntry($cycle));
        foreach ([[PHP_INT_MAX, 1, 0], [PHP_INT_MIN, -1, 0], [PHP_INT_MAX, 0, -1], [PHP_INT_MIN, 0, 1]] as $args) {
            self::assertNull(PublicationTiming::accumulateDurationNative(...$args));
            self::assertSame($args[0] + $args[1] - $args[2], PublicationTiming::accumulateDuration(...$args));
        }
        $phases = ['state_lock_acquisition' => 1, 'overflow' => PHP_INT_MAX];
        self::assertNull(PublicationTiming::attemptSummaryNative($phases));
        self::assertSame(PublicationTiming::attemptSummaryPhp($phases), PublicationTiming::attemptSummary($phases));
        self::assertNull(CandidateSampleBatch::verifiedRateNative(1, INF, 0.0));
        self::assertSame(0.0, CandidateSampleBatch::verifiedRate(1, INF, 0.0));
    }

    public function test_c_abi_validates_ranges_wire_capacity_and_overflow_without_partial_writes(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', NzbXmlElements::FFI_CDEF);
        self::assertNotNull($ffi);
        $input = serialize(['nzb', '']);
        $out = $ffi->new('char[128]');
        FFI::memset($out, 88, 128);
        // PHP 8.5 FFI first-class callables crash; invoke through a closure.
        // @phpstan-ignore method.notFound (The symbol is declared by FFI_CDEF.)
        $call = static fn (int $op, mixed $input, int $length, mixed $output, int $capacity): int => $ffi->nntmux_w17_nzb_xml_verification_timing($op, $input, $length, $output, $capacity);
        self::assertSame(-1, $call(0, null, 1, $out, 128));
        self::assertSame(-1, $call(0, $input, PHP_INT_MAX, $out, 128));
        self::assertSame(-1, $call(0, $input, strlen($input), $out, -1));
        self::assertSame(-1, $call(0, $input, strlen($input), null, 128));
        self::assertSame(-1, $call(0, $out, 8, $out, 128));
        self::assertSame(-1, $call(0, $ffi->cast('char *', -1), 1, $out, 128));
        self::assertSame(-1, $call(0, $input, strlen($input), $ffi->cast('char *', -1), 1));
        $required = -$call(0, $input, strlen($input), null, 0);
        self::assertGreaterThan(1, $required);
        self::assertSame(-$required, $call(0, $input, strlen($input), $out, $required - 1));
        self::assertSame(str_repeat('X', 128), FFI::string($out, 128));
        self::assertSame($required, $call(0, $input, strlen($input), $out, $required));
        self::assertTrue(unserialize(FFI::string($out, $required), ['allowed_classes' => false]));
        self::assertSame(str_repeat('X', 128 - $required), FFI::string(FFI::addr($out[$required]), 128 - $required));
        foreach (['N;', 'a:0:{}junk', 'a:1:{i:0;R:1;}', 'a:999999999999999999999999:{}', serialize(['nzb']), serialize(['nzb', '', 'extra'])] as $malformed) {
            self::assertSame(-1, $call(0, $malformed, strlen($malformed), $out, 128));
        }
        $overflow = serialize([PHP_INT_MAX, 1, 0]);
        self::assertSame(-1, $call(10, $overflow, strlen($overflow), $out, 128));
        self::assertSame(-1, $call(999, $input, strlen($input), $out, 128));
    }

    /** @return array<string, array{class-string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $phases = ['state_lock_acquisition' => 100];
        for ($i = 0; $i < 512; $i++) {
            $phases['nested.phase.'.$i] = $i * 37;
        }

        return [
            'validRoot' => [NzbXmlElements::class, [['nzb', ''], ['nzb', NzbXmlElements::CANONICAL_NAMESPACE]], [['nzb', str_repeat('foreign', 1024)], ['other', '']]],
            'validPasswordStructure' => [NzbXmlElements::class, [[1, true, false], [0, false, false]], [[PHP_INT_MAX, true, false], [4, false, true]]],
            'isPasswordMetadata' => [NzbXmlElements::class, [['password'], ['title']], [[str_repeat('password', 1024)], ["password\0"]]],
            'normalizeManifestEntry' => [NfoImportService::class, [[['release_id' => 1, 'release_guid' => 'legacy-guid']]], [[['release_id' => PHP_INT_MAX, 'release_guid' => str_repeat('g', 8192), 'extra' => array_fill(0, 128, 'unrelated')]]]],
            'validGuid' => [NfoImportService::class, [['legacy-guid'], ['']], [[str_repeat('g', 8192)], [null]]],
            'importSummary' => [NfoImportService::class, [[[['status' => 'imported'], ['status' => 'skipped']]]], [[array_merge(array_fill(0, 256, ['status' => 'imported']), array_fill(0, 256, ['status' => 'failed']), [['status' => 'unknown']])]]],
            'pendingCount' => [CandidateSampleBatch::class, [[0], [1]], [[PHP_INT_MAX], [16]]],
            'claimsLimit' => [CandidateSampleBatch::class, [[1, 10], [10, 10]], [[PHP_INT_MAX, PHP_INT_MIN], [0, -1]]],
            'verifiedRate' => [CandidateSampleBatch::class, [[1000, 12.0, 10.0], [4000, 14.0, 10.0]], [[PHP_INT_MAX, 10.0, 12.0], [0, 10000000.001, 10000000.0]]],
            'claimBudget' => [CandidateSampleBatch::class, [[10, 2, 4, 8], [10, 10, 16, 16]], [[PHP_INT_MAX, PHP_INT_MIN, 99, 99], [PHP_INT_MIN, PHP_INT_MAX, -1, -1]]],
            'accumulateDuration' => [PublicationTiming::class, [[0, 100, 50], [50, 120, 100]], [[PHP_INT_MAX - 100, 50, 1], [0, PHP_INT_MIN, -1]]],
            'attemptSummary' => [PublicationTiming::class, [[['state_lock_acquisition' => 100, 'write' => 250]], [[]]], [[$phases]]],
            'phaseKey' => [PublicationTiming::class, [['write', null], ['write', 'prepare']], [[str_repeat('phase', 1024), str_repeat('nested', 1024)], ["a\0b", '']]],
            'classifyOutcome' => [NzbCreationResult::class, [[true, 'none'], [false, 'deferred']], [[false, str_repeat('unknown', 1024)], [false, 'transient']]],
            'failureMatches' => [NzbCreationResult::class, [['deferred', 'deferred'], ['transient', 'deferred']], [[str_repeat('unknown', 1024), str_repeat('unknown', 1024)], ["deferred\0", 'deferred']]],
        ];
    }

    public function test_paired_randomized_benchmark(): void
    {
        if (getenv('NNTMUX_NZB_XML_VERIFICATION_TIMING_BENCH') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_NZB_XML_VERIFICATION_TIMING_BENCH=1');
        }
        // Protocol: fixed seed, 64 warmup pairs, 3000 individually timed pairs
        // per method/workload, identical fixtures and randomized execution order.
        // Measure public dispatch including serialization, allocation and fallback.
        $samples = 3000;
        $random = new Randomizer(new Mt19937(1702));
        $destination = sys_get_temp_dir().'/nntmux-wave17-nzb-xml-verification-timing-benchmark.json';
        $root = dirname(__DIR__, 2);
        $paths = [
            'rust/pipeline-ffi/src/nfo.rs', 'app/Services/Nzb/NzbXmlElements.php',
            'app/Services/Nzb/NfoImportService.php', 'app/Services/Releases/Verification/CandidateSampleBatch.php',
            'app/Services/Releases/Verification/PublicationTiming.php', 'app/Support/Data/NzbCreationResult.php',
            'tests/Unit/RustNzbXmlVerificationTimingParityTest.php', 'composer.lock',
            'rust/pipeline-ffi/Cargo.lock', 'vendor/composer/installed.php',
        ];
        $library = $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        $metadata = [
            'utc' => gmdate(DATE_ATOM), 'head' => trim((string) shell_exec('git rev-parse HEAD')),
            'php' => PHP_VERSION, 'os' => php_uname(), 'seed' => 1702, 'samples' => $samples, 'warmup_pairs' => 64,
            'cpu' => trim((string) shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n machdep.cpu.brand_string' : 'uname -m')),
            'limits' => (string) shell_exec('ulimit -a'), 'memory_limit' => ini_get('memory_limit'),
            'rustc' => trim((string) shell_exec('rustc --version')), 'profile' => 'release opt-level=3 lto=true codegen-units=1',
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'),
            'source_sha256' => array_combine($paths, array_map(static fn (string $p): string|false => hash_file('sha256', $root.'/'.$p), $paths)),
            'library_sha256' => hash_file('sha256', $library), 'inputs_base64_php_serialized' => base64_encode(serialize(self::workloads())),
            'protocol' => 'Public dispatch; toggle set outside timer; parity outside timer; caches warm; first observations recorded separately; p99 nearest rank, rank interval approximate 95%; no production I/O.',
        ];
        file_put_contents($destination.'.jsonl', json_encode(['event' => 'start', 'metadata' => $metadata], JSON_THROW_ON_ERROR)."\n");
        $results = [];
        try {
            foreach (self::workloads() as $method => [$class, $common, $uncommon]) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $workload => $fixtures) {
                    $first = [];
                    foreach ($fixtures as $args) {
                        $expected = $class::{$method.'Php'}(...$args);
                        $class::setUseRustFfi(true);
                        self::assertSame($expected, $class::{$method.'Native'}(...$args), $method.' must execute Rust');
                        foreach ([false, true] as $native) {
                            $class::setUseRustFfi($native);
                            $start = hrtime(true);
                            $observed = $class::$method(...$args);
                            $first[] = [$native, hrtime(true) - $start];
                            self::assertSame($expected, $observed);
                        }
                    }
                    for ($i = 0; $i < 64; $i++) {
                        foreach ([false, true] as $native) {
                            $class::setUseRustFfi($native);
                            $class::$method(...$fixtures[$i % count($fixtures)]);
                        }
                    }
                    $raw = [];
                    for ($i = 0; $i < $samples; $i++) {
                        $fixture = $random->getInt(0, count($fixtures) - 1);
                        $args = $fixtures[$fixture];
                        $order = $random->getInt(0, 1);
                        $times = [];
                        $observed = [];
                        foreach ($order === 0 ? [0, 1] : [1, 0] as $which) {
                            $class::setUseRustFfi($which === 1);
                            $start = hrtime(true);
                            $observed[$which] = $class::$method(...$args);
                            $times[$which] = hrtime(true) - $start;
                        }
                        self::assertSame($observed[0], $observed[1]);
                        $raw[] = [$fixture, $order, $times[0], $times[1]];
                    }
                    $php = array_column($raw, 2);
                    $rust = array_column($raw, 3);
                    sort($php);
                    sort($rust);
                    $index = (int) ceil($samples * 0.99) - 1;
                    $radius = (int) ceil(1.96 * sqrt($samples * 0.99 * 0.01));
                    $lower = max(0, $index - $radius);
                    $upper = min($samples - 1, $index + $radius);
                    $mean = (array_sum($php) / $samples) / (array_sum($rust) / $samples);
                    $p99 = $php[$index] / $rust[$index];
                    $results[$method][$workload] = [
                        'mean_speedup' => $mean, 'p99_speedup' => $p99,
                        'php_mean_ns' => array_sum($php) / $samples, 'rust_mean_ns' => array_sum($rust) / $samples,
                        'php_p99_ns' => $php[$index], 'rust_p99_ns' => $rust[$index],
                        'p99_speedup_rank_interval' => [$php[$lower] / $rust[$upper], $php[$upper] / $rust[$lower]],
                        'first_observations' => $first, 'raw_fixture_order_php_rust_ns' => $raw,
                    ];
                    file_put_contents($destination.'.jsonl', json_encode(['event' => 'measurement', 'method' => $method, 'workload' => $workload, 'result' => $results[$method][$workload]], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
                    fwrite(STDOUT, sprintf("%s %-24s mean %.3fx p99 %.3fx\n", $workload, $method, $mean, $p99));
                }
            }
            foreach ($results as $method => $workloads) {
                $qualified = true;
                foreach ($workloads as $metrics) {
                    $qualified = $qualified && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
                }
                $metadata['qualified'][$method] = $qualified;
            }
            file_put_contents($destination, json_encode($metadata + ['results' => $results], JSON_THROW_ON_ERROR));
            fwrite(STDOUT, 'Benchmark evidence: '.$destination."\n");
        } catch (\Throwable $failure) {
            file_put_contents($destination.'.jsonl', json_encode(['event' => 'invalid', 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
            throw $failure;
        }
    }
}
