<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\BackfillReleaseFingerprintMetadata;
use App\Console\Commands\ImportVerificationManifests;
use App\Console\Commands\PrepareReleaseCandidates;
use App\Console\Commands\TransferFingerprintQueue;
use App\Console\Commands\VerifyReleaseCandidates;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustFingerprintBackfillTransferCandidateW39ParityTest extends TestCase
{
    /** class => helper method; every class exposes the same Wave 39.2 toggle trio. */
    private const array HELPERS = [
        BackfillReleaseFingerprintMetadata::class => 'summarizeBackfillProgressWindow',
        TransferFingerprintQueue::class => 'planQueueTransferWindow',
        ImportVerificationManifests::class => 'summarizeManifestImportRecords',
        PrepareReleaseCandidates::class => 'summarizeCandidatePreparationBatch',
        VerifyReleaseCandidates::class => 'summarizeCandidateVerificationOutcomes',
    ];

    private const string GUID_A = '0123456789abcdef0123456789abcdef01234567';

    private const string GUID_B = 'fedcba9876543210fedcba9876543210fedcba98';

    private const int SEED = 39022026;

    private const int RANDOMIZED = 1200;

    protected function tearDown(): void
    {
        foreach (array_keys(self::HELPERS) as $class) {
            $class::resetW392RustFfi();
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_defaults_and_toggles_are_independent(): void
    {
        foreach (array_keys(self::HELPERS) as $class) {
            $this->assertFalse(\constant($class.'::W392_RUST_FFI_DEFAULT'), $class);
            $this->assertFalse($class::usesW392RustFfi(), $class);
        }
        foreach (array_keys(self::HELPERS) as $selected) {
            $selected::useW392RustFfi(true);
            foreach (array_keys(self::HELPERS) as $class) {
                $this->assertSame($selected === $class, $class::usesW392RustFfi(), $selected.' → '.$class);
                $this->assertFalse($class::usesRustFfi(), $selected.' leaves '.$class.' earlier toggle alone');
            }
            $selected::useW392RustFfi(false);
            $this->assertFalse($selected::usesW392RustFfi());
            $selected::useW392RustFfi(true);
            $selected::resetW392RustFfi();
            $this->assertFalse($selected::usesW392RustFfi());
            // The earlier command toggle leaves the Wave 39.2 toggle alone, and vice versa.
            $selected::setUseRustFfi(true);
            $this->assertFalse($selected::usesW392RustFfi());
            $selected::useW392RustFfi(true);
            $selected::resetW392RustFfi();
            $this->assertTrue($selected::usesRustFfi());
            $selected::setUseRustFfi(null);
        }
    }

    public function test_php_helpers_answer_known_vectors(): void
    {
        $this->assertSame(['chunks' => 2, 'invalid_chunks' => 1, 'total_scanned' => 1100, 'total_updated' => 1000, 'skipped_missing_nzb' => 3, 'corrupt_xml' => 3,
            'max_chunk_duration_ms' => 340, 'target_rows' => 1000, 'remaining_rows' => 0, 'target_reached' => true],
            BackfillReleaseFingerprintMetadata::summarizeBackfillProgressWindow([
                ['scanned' => 500, 'updated' => 400, 'missing_nzb' => 3, 'corrupt_xml' => 1, 'duration_ms' => 120],
                'not-a-chunk',
                ['scanned' => 600, 'updated' => 600, 'corrupt_xml' => 2, 'duration_ms' => 340],
            ]));
        $this->assertSame([0, 0, 1, 1, false], self::pick(BackfillReleaseFingerprintMetadata::summarizeBackfillProgressWindow(
            [['scanned' => -5, 'updated' => '7', 'duration_ms' => 9.5]], 0), ['total_scanned', 'total_updated', 'target_rows', 'remaining_rows', 'target_reached']));
        $this->assertSame([PHP_INT_MAX, PHP_INT_MAX], self::pick(BackfillReleaseFingerprintMetadata::summarizeBackfillProgressWindow(
            [['scanned' => PHP_INT_MAX, 'updated' => PHP_INT_MAX], ['scanned' => 1, 'updated' => 1]], PHP_INT_MAX), ['total_scanned', 'total_updated']));

        $this->assertSame(['pending' => 5000, 'available_capacity' => 10000, 'batch_size' => 1000, 'planned_batches' => 3, 'planned_items' => 3000, 'remaining' => 2000,
            'throttled' => false, 'drain_complete' => false], TransferFingerprintQueue::planQueueTransferWindow(5000, 1000, 3, 0, 10000));
        $this->assertSame([3, 2500, true], self::pick(TransferFingerprintQueue::planQueueTransferWindow(2500, 1000, 10, 0, 10000),
            ['planned_batches', 'planned_items', 'drain_complete']));
        $this->assertSame([10, 10, 1, 10, true], self::pick(TransferFingerprintQueue::planQueueTransferWindow(100, 50, 10, 90, 100),
            ['available_capacity', 'batch_size', 'planned_batches', 'planned_items', 'throttled']));
        $this->assertSame([0, 0, 0, 100], self::pick(TransferFingerprintQueue::planQueueTransferWindow(100, 50, 10, 100, 100),
            ['available_capacity', 'batch_size', 'planned_items', 'remaining']));
        $this->assertSame([10000, PHP_INT_MAX, 0, true], self::pick(TransferFingerprintQueue::planQueueTransferWindow(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX),
            ['batch_size', 'planned_items', 'remaining', 'drain_complete']));
        $this->assertSame([0, true, false], self::pick(TransferFingerprintQueue::planQueueTransferWindow(0, 0, 0, 0, 0), ['pending', 'drain_complete', 'throttled']));

        $this->assertSame(['records' => 7, 'valid' => 1, 'invalid_guid' => 4, 'below_min_segments' => 1, 'duplicate_guid' => 1, 'total_segments' => 5],
            ImportVerificationManifests::summarizeManifestImportRecords([
                ['guid' => self::GUID_A, 'segments' => 5], ['guid' => self::GUID_A, 'segments' => 9], ['guid' => self::GUID_B, 'segments' => 1],
                ['guid' => strtoupper(self::GUID_A), 'segments' => 7], ['guid' => 'abc', 'segments' => 9], ['segments' => 3], 42,
            ], 2));
        $this->assertSame([0, 1], self::pick(ImportVerificationManifests::summarizeManifestImportRecords([['guid' => self::GUID_A, 'segments' => '4']]),
            ['valid', 'below_min_segments']));
        $this->assertSame([1, 0], self::pick(ImportVerificationManifests::summarizeManifestImportRecords([['guid' => self::GUID_A]], -3), ['valid', 'total_segments']));

        $this->assertSame(['candidates' => 7, 'ready' => 2, 'empty_parts' => 2, 'oversized' => 1, 'invalid_size' => 2, 'eligible_bytes' => 50],
            PrepareReleaseCandidates::summarizeCandidatePreparationBatch([
                ['parts' => 3, 'size' => 50], ['parts' => 0, 'size' => 10], ['parts' => 2, 'size' => 101], ['parts' => 1, 'size' => -1],
                ['parts' => 4, 'size' => 0], ['parts' => '7', 'size' => 100], ['parts' => 1],
            ], 100));
        $this->assertSame([1, 0], self::pick(PrepareReleaseCandidates::summarizeCandidatePreparationBatch([['parts' => 1, 'size' => 107374182400],
            ['parts' => 1, 'size' => 107374182401]]), ['ready', 'invalid_size']));
        $this->assertSame(PHP_INT_MAX, PrepareReleaseCandidates::summarizeCandidatePreparationBatch([['parts' => 1, 'size' => PHP_INT_MAX], ['parts' => 1, 'size' => 1]],
            PHP_INT_MAX)['eligible_bytes']);

        $this->assertSame(['outcomes' => 10, 'verified' => 3, 'retryable' => 1, 'exhausted' => 1, 'permanent_failure' => 2, 'deferred' => 1, 'unrecognized' => 2],
            VerifyReleaseCandidates::summarizeCandidateVerificationOutcomes([
                ['code' => 'complete'], ['code' => 'repaired', 'attempts' => 2], ['code' => 'sampled'], ['code' => 'unknown', 'attempts' => 2],
                ['code' => 'unknown', 'attempts' => 3], ['code' => 'unrepairable'], ['code' => 'failed'], ['code' => 'deferred'], ['code' => 'Unknown'], null,
            ]));
        $this->assertSame([1, 0], self::pick(VerifyReleaseCandidates::summarizeCandidateVerificationOutcomes([['code' => 'unknown', 'attempts' => '9']], 1),
            ['retryable', 'exhausted']));
        $this->assertSame([0, 1], self::pick(VerifyReleaseCandidates::summarizeCandidateVerificationOutcomes([['code' => 'unknown', 'attempts' => -1]], -3),
            ['exhausted', 'retryable']));
    }

    public function test_native_matches_php_on_edge_cases_and_randomized_inputs(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $class => $method) {
            $cases = [...self::edgeCases($class), ...array_map(static fn (): array => self::randomArgs($class, $random), range(1, self::RANDOMIZED))];
            $this->assertGreaterThanOrEqual(1000, \count($cases));
            foreach ($cases as $args) {
                $expected = self::invoke($class, $method.'Php', $args);
                $native = self::invoke($class, $method.'Native', $args);
                $this->assertNotNull($native, $class.'::'.$method.' declined '.self::describe($args));
                $this->assertSame($expected, $native, $class.'::'.$method.' '.self::describe($args));
                $class::useW392RustFfi(true);
                $viaRust = $class::$method(...$args);
                $class::useW392RustFfi(false);
                $viaPhp = $class::$method(...$args);
                $this->assertSame([$expected, $expected], [$viaRust, $viaPhp], $class.'::'.$method.' dispatch');
            }
        }
    }

    public function test_out_of_contract_inputs_fall_back_to_php(): void
    {
        $this->nativeFfi();
        $guid = self::GUID_A;
        foreach ([[5, pack('q', 0)], [-1, pack('q5', 1, 1, 1, 1, 1)], [0, ''], [0, 'short'], [0, pack('q', 1)], [0, pack('q2', 1, -1)], [0, pack('q3', 1, 0, 1)],
            [1, pack('q4', 1, 1, 1, 1)], [1, pack('q6', 1, 1, 1, 1, 1, 1)], [2, pack('q', 1)], [2, pack('q2', 1, -1)], [2, pack('q4', 1, 1, 40, 1)],
            [2, pack('q4', 1, 1, -2, 1)], [2, pack('q4', 1, 1, 0, 1).'x'], [2, pack('q4', 1, 2, 40, 1).$guid], [3, pack('q2', 1, 1)], [3, pack('q', 1).'x'],
            [4, pack('q4', 1, 1, 7, 0).'unknow'], [4, pack('q2', 0, 0).'x'], [1, str_repeat("\0", 16777224)]] as [$op, $input]) {
            $this->assertNull(BackfillReleaseFingerprintMetadata::evaluateW392Native($op, $input, 8), $op.' '.bin2hex(substr($input, 0, 48)));
        }
        $this->assertNull(BackfillReleaseFingerprintMetadata::evaluateW392Native(1, pack('q5', 1, 1, 1, 1, 1), 0));
        // A wrong expected slot count is a contract violation, not a partial answer.
        $this->assertNull(BackfillReleaseFingerprintMetadata::evaluateW392Native(1, pack('q5', 1, 1, 1, 1, 1), 7));
        $this->assertSame([1, 0, 0, 0, 0, 1, 1, 0], BackfillReleaseFingerprintMetadata::evaluateW392Native(1, pack('q5', 1, 1, 1, 1, 1), 8));

        // Inputs past the native byte limit keep the PHP answer through every public entry point.
        foreach (array_keys(self::HELPERS) as $class) {
            $class::useW392RustFfi(true);
        }
        $chunks = array_fill(0, 419431, ['scanned' => 1, 'updated' => 1]);
        $this->assertNull(self::invoke(BackfillReleaseFingerprintMetadata::class, 'summarizeBackfillProgressWindowNative', [$chunks, 10]));
        $this->assertSame(self::invoke(BackfillReleaseFingerprintMetadata::class, 'summarizeBackfillProgressWindowPhp', [$chunks, 10]),
            BackfillReleaseFingerprintMetadata::summarizeBackfillProgressWindow($chunks, 10));
        $records = array_fill(0, 400000, ['guid' => $guid, 'segments' => 1]);
        $this->assertNull(self::invoke(ImportVerificationManifests::class, 'summarizeManifestImportRecordsNative', [$records, 1]));
        $this->assertSame(self::invoke(ImportVerificationManifests::class, 'summarizeManifestImportRecordsPhp', [$records, 1]),
            ImportVerificationManifests::summarizeManifestImportRecords($records, 1));
        $candidates = array_fill(0, 1048576, ['parts' => 1, 'size' => 1]);
        $this->assertNull(self::invoke(PrepareReleaseCandidates::class, 'summarizeCandidatePreparationBatchNative', [$candidates, 10]));
        $this->assertSame(self::invoke(PrepareReleaseCandidates::class, 'summarizeCandidatePreparationBatchPhp', [$candidates, 10]),
            PrepareReleaseCandidates::summarizeCandidatePreparationBatch($candidates, 10));
        $outcomes = [['code' => str_repeat('u', 16777216)]];
        $this->assertNull(self::invoke(VerifyReleaseCandidates::class, 'summarizeCandidateVerificationOutcomesNative', [$outcomes, 3]));
        $this->assertSame(self::invoke(VerifyReleaseCandidates::class, 'summarizeCandidateVerificationOutcomesPhp', [$outcomes, 3]),
            VerifyReleaseCandidates::summarizeCandidateVerificationOutcomes($outcomes, 3));
    }

    public function test_direct_ffi_export_preserves_sentinels_and_capacity_contract(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, pack('q12', 1000, 0, 500, 400, 3, 1, 120, 600, 600, 0, 2, 340), [2, 0, 1100, 1000, 3, 3, 340, 1000, 0, 1]],
            [1, pack('q5', 100, 50, 10, 90, 100), [100, 10, 10, 1, 10, 90, 1, 0]],
            [2, pack('q6', 2, 2, 40, 5, 40, 1).self::GUID_A.self::GUID_B, [2, 1, 0, 1, 0, 5]],
            [3, pack('q5', 100, 3, 50, 0, 10), [2, 1, 1, 0, 0, 50]],
            [4, pack('q6', 3, 2, 7, 3, 8, 0).'unknowncomplete', [2, 1, 0, 1, 0, 0, 0]]];
        foreach ($vectors as [$op, $input, $expected]) {
            $length = \count($expected) * 8;
            $wire = pack('q*', ...$expected);
            $this->assertSame($expected, BackfillReleaseFingerprintMetadata::evaluateW392Native($op, $input, \count($expected)));
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w39_fingerprint_backfill_transfer_candidate($op, 0, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2), $op.' capacity '.$capacity);
                if ($written < 0) {
                    // Retry with exactly the reported capacity.
                    $retry = $ffi->new('char['.(-$written).']');
                    // @phpstan-ignore method.notFound
                    $this->assertSame(-$written, $ffi->nntmux_w39_fingerprint_backfill_transfer_candidate($op, 0, $input, \strlen($input), $retry, -$written));
                    $this->assertSame($wire, FFI::string($retry, -$written));
                }
            }
            // Every flag bit is rejected for every operation.
            foreach ([1, 64, 4294967295] as $flags) {
                // @phpstan-ignore method.notFound
                $this->assertSame(-1, $ffi->nntmux_w39_fingerprint_backfill_transfer_candidate($op, $flags, $input, \strlen($input), $small, $length + 2));
            }
        }
        $buffer = $ffi->new('char[1024]');
        $input = pack('q5', 1, 1, 1, 1, 1);
        foreach ([[null, 0, $buffer, 1024], [null, 40, $buffer, 1024], [$input, 40, null, 0], [$input, 40, null, 64], [$input, 16777217, $buffer, 1024],
            [$buffer, 40, $buffer, 1024], [FFI::addr($buffer[8]), 40, $buffer, 1024], [$buffer, 40, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 40);
            FFI::memset(FFI::addr($buffer[40]), 0x5A, 984);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w39_fingerprint_backfill_transfer_candidate(1, 0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 40).str_repeat('Z', 984), FFI::string($buffer, 1024));
        }
        foreach ([5, 99, 4294967295] as $op) {
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w39_fingerprint_backfill_transfer_candidate($op, 0, $input, 40, $buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $helpers = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Console\Commands\BackfillReleaseFingerprintMetadata::evaluateW392Native(1, pack('q5', 1, 1, 1, 1, 1), 8) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($helpers as [$class, $method, $cases]) {
                foreach ($cases as $args) {
                    $class::useW392RustFfi(false);
                    $expected = serialize($class::$method(...$args));
                    $class::useW392RustFfi(true);
                    if ($expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $helpers = [];
        foreach (self::HELPERS as $class => $method) {
            $helpers[] = [$class, $method, self::edgeCases($class)];
        }
        $missing = '/tmp/w39-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize($helpers))], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave39-2-fingerprint-backfill-transfer-candidate-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including normalization, packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'CARGO_TARGET_DIR=/tmp/cargo-w39-2 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w39-2/release/libnntmux_pipeline.dylib php vendor/bin/phpunit tests/Unit/RustFingerprintBackfillTransferCandidateW39ParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Console/Commands/BackfillReleaseFingerprintMetadata.php',
            'app/Console/Commands/TransferFingerprintQueue.php', 'app/Console/Commands/ImportVerificationManifests.php', 'app/Console/Commands/PrepareReleaseCandidates.php',
            'app/Console/Commands/VerifyReleaseCandidates.php', 'tests/Unit/RustFingerprintBackfillTransferCandidateW39ParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        try {
            foreach (self::workloads() as [$class, $common, $uncommon]) {
                $method = self::HELPERS[$class];
                $name = $class.'::'.$method;
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize(self::invoke($class, $method.'Php', $args));
                        $this->assertSame(end($expected), serialize(self::invoke($class, $method.'Native', $args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'inputs' => \count($inputs), 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::useW392RustFfi($enabled);
                            for ($i = 0; $i < 64; $i++) {
                                $class::$method(...$inputs[$i % \count($inputs)]);
                            }
                        }
                        $raw = ['php' => [], 'native' => []];
                        for ($pair = 0; $pair < $pairs; $pair++) {
                            $indices = [];
                            for ($i = 0; $i < $calls; $i++) {
                                $indices[] = $random->getInt(0, \count($inputs) - 1);
                            }
                            $order = $random->getInt(0, 1) === 0 ? ['php', 'native'] : ['native', 'php'];
                            foreach ($order as $side) {
                                $class::useW392RustFfi($side === 'native');
                                foreach ($indices as $index) {
                                    $args = $inputs[$index];
                                    $start = hrtime(true);
                                    $result = $class::$method(...$args);
                                    $elapsed = hrtime(true) - $start;
                                    if (serialize($result) !== $expected[$index]) {
                                        throw new RuntimeException($name.'/'.$kind.' mismatch during timing');
                                    }
                                    $raw[$side][] = $elapsed;
                                }
                            }
                        }
                        $phpStats = self::statistics($raw['php']);
                        $nativeStats = self::statistics($raw['native']);
                        $cell['rounds'][] = ['raw_ns' => $raw, 'php' => $phpStats, 'native' => $nativeStats,
                            'mean_speedup' => $phpStats['mean_ns'] / max(1.0, $nativeStats['mean_ns']), 'p99_speedup' => $phpStats['p99_ns'] / max(1, $nativeStats['p99_ns'])];
                    }
                    $cell['qualifies'] = true;
                    foreach (['mean_speedup', 'p99_speedup'] as $metric) {
                        $values = array_column($cell['rounds'], $metric);
                        $cell['round_range'][$metric] = ['min' => min($values), 'max' => max($values)];
                        $cell['qualifies'] = $cell['qualifies'] && min($values) >= 1.5;
                    }
                    $report['results'][$name][$kind] = $cell;
                }
                $qualifies = $report['results'][$name]['common']['qualifies'] && $report['results'][$name]['uncommon']['qualifies'];
                $report['results'][$name]['qualifies'] = $qualifies;
                $class::resetW392RustFfi();
                if (! $qualifies) {
                    $this->assertFalse(\constant($class.'::W392_RUST_FFI_DEFAULT'), $name.' must keep PHP as the default below 1.5x');
                }
            }
        } catch (Throwable $error) {
            $report['failures'][] = ['type' => $error::class, 'message' => $error->getMessage()];
            throw $error;
        } finally {
            $report['finished_at_utc'] = gmdate(DATE_ATOM);
            if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) === false) {
                throw new RuntimeException('Cannot preserve benchmark evidence at '.$path);
            }
            $summary = [];
            foreach ($report['results'] as $helper => $cells) {
                $summary[] = sprintf('%s common mean %.2fx p99 %.2fx; uncommon mean %.2fx p99 %.2fx', $helper,
                    $cells['common']['round_range']['mean_speedup']['min'], $cells['common']['round_range']['p99_speedup']['min'],
                    $cells['uncommon']['round_range']['mean_speedup']['min'], $cells['uncommon']['round_range']['p99_speedup']['min']);
            }
            fwrite(STDERR, "\nWave 39.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(BackfillReleaseFingerprintMetadata::W392_FFI_DOMAIN, BackfillReleaseFingerprintMetadata::W392_FFI_CDEF,
            getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function invoke(string $class, string $method, array $args): mixed
    {
        return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private static function pick(array $result, array $keys): array
    {
        return array_map(static fn (string $key): mixed => $result[$key], $keys);
    }

    /** @param list<mixed> $args */
    private static function describe(array $args): string
    {
        return substr(bin2hex(serialize($args)), 0, 400);
    }

    /** @return list<list<mixed>> */
    private static function edgeCases(string $class): array
    {
        $a = self::GUID_A;
        $b = self::GUID_B;

        return match ($class) {
            BackfillReleaseFingerprintMetadata::class => [[[], 1000], [[], PHP_INT_MIN], [['x', null, 5], 1], [[[]], 0],
                [[['scanned' => 500, 'updated' => 400, 'missing_nzb' => 3, 'corrupt_xml' => 1, 'duration_ms' => 120], ['updated' => 600, 'duration_ms' => 340]], 1000],
                [[['scanned' => PHP_INT_MAX, 'updated' => PHP_INT_MAX, 'missing_nzb' => PHP_INT_MAX, 'corrupt_xml' => PHP_INT_MAX, 'duration_ms' => PHP_INT_MAX],
                    ['scanned' => 1, 'updated' => 1, 'missing_nzb' => 1, 'corrupt_xml' => 1]], PHP_INT_MAX],
                [[['scanned' => PHP_INT_MIN, 'updated' => -1, 'duration_ms' => PHP_INT_MIN], ['scanned' => '5', 'updated' => 5.0, 'missing_nzb' => true]], 5],
                [array_fill(0, 2000, ['scanned' => 1000, 'updated' => 997, 'missing_nzb' => 2, 'corrupt_xml' => 1, 'duration_ms' => 85]), 1000000]],
            TransferFingerprintQueue::class => [[5000, 1000, 3, 0, 10000], [2500, 1000, 10, 0, 10000], [100, 50, 10, 90, 100], [100, 50, 10, 100, 100], [0, 0, 0, 0, 0],
                [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MAX, 7, 2, 0, PHP_INT_MAX], [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                [10, 3, 4, 0, 10], [10, 3, 3, 0, 10], [10, 10001, 1, -5, 20000], [1, 1, 0, 0, 1]],
            ImportVerificationManifests::class => [[[], 1], [[], PHP_INT_MIN], [[1, null, 'x'], 0], [[['guid' => $a, 'segments' => 5], ['guid' => $a, 'segments' => 9],
                ['guid' => $b, 'segments' => 1], ['guid' => strtoupper($a), 'segments' => 7], ['guid' => 'abc'], ['segments' => 3], 42], 2],
                [[['guid' => $a, 'segments' => PHP_INT_MAX], ['guid' => $b, 'segments' => PHP_INT_MAX]], 0], [[['guid' => $a, 'segments' => PHP_INT_MIN]], PHP_INT_MIN],
                [[['guid' => $a, 'segments' => 1]], PHP_INT_MAX], [[['guid' => str_repeat('1', 40), 'segments' => 1], ['guid' => str_repeat('1', 40), 'segments' => 2]], 1],
                [[['guid' => $a."\0", 'segments' => 1], ['guid' => '', 'segments' => 1], ['guid' => 12345, 'segments' => 1]], 1]],
            PrepareReleaseCandidates::class => [[[], 107374182400], [[1, 'x', null], 0], [[['parts' => 3, 'size' => 50], ['parts' => 0, 'size' => 10], ['parts' => 2, 'size' => 101],
                ['parts' => 1, 'size' => -1], ['parts' => 4, 'size' => 0], ['parts' => '7', 'size' => 100], ['parts' => 1]], 100],
                [[['parts' => 1, 'size' => PHP_INT_MAX], ['parts' => 1, 'size' => 1]], PHP_INT_MAX], [[['parts' => PHP_INT_MIN, 'size' => PHP_INT_MIN]], PHP_INT_MIN],
                [[['parts' => 1, 'size' => 0]], PHP_INT_MIN], [[['parts' => 1, 'size' => 107374182400], ['parts' => 1, 'size' => 107374182401]], 107374182400]],
            VerifyReleaseCandidates::class => [[[], 3], [[null, 1, 'complete'], 3], [[['code' => 'complete'], ['code' => 'repaired', 'attempts' => 2], ['code' => 'sampled'],
                ['code' => 'unknown', 'attempts' => 2], ['code' => 'unknown', 'attempts' => 3], ['code' => 'unrepairable'], ['code' => 'failed'], ['code' => 'deferred'],
                ['code' => 'Unknown'], ['code' => ''], ['code' => ' unknown'], ['code' => "unknown\0"]], 3],
                [[['code' => 'unknown', 'attempts' => PHP_INT_MIN], ['code' => 'unknown', 'attempts' => PHP_INT_MAX]], PHP_INT_MAX],
                [[['code' => 'unknown', 'attempts' => -1]], PHP_INT_MIN], [[['code' => 'unknown', 'attempts' => '1']], 1]],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<mixed> */
    private static function randomArgs(string $class, Randomizer $random): array
    {
        $rows = static function (callable $row) use ($random): array {
            $out = [];
            for ($i = $random->getInt(0, 12); $i > 0; $i--) {
                $out[] = $random->getInt(0, 9) === 0 ? [null, 'row', 7, 1.5, true][$random->getInt(0, 4)] : $row();
            }

            return $out;
        };
        $value = static fn (): mixed => $random->getInt(0, 7) === 0 ? ['5', 5.0, null, true, []][$random->getInt(0, 4)] : self::int($random);
        $fields = static function (array $keys) use ($random, $value): array {
            $out = [];
            foreach ($keys as $key => $make) {
                if ($random->getInt(0, 9) !== 0) {
                    $out[$key] = $make === null ? $value() : $make();
                }
            }

            return $out;
        };

        return match ($class) {
            BackfillReleaseFingerprintMetadata::class => [$rows(static fn (): array => $fields(['scanned' => null, 'updated' => null, 'missing_nzb' => null,
                'corrupt_xml' => null, 'duration_ms' => null])), self::int($random)],
            TransferFingerprintQueue::class => [self::int($random), self::int($random), self::int($random), self::int($random), self::int($random)],
            ImportVerificationManifests::class => [$rows(static fn (): array => $fields([
                'guid' => static fn (): mixed => [self::GUID_A, self::GUID_B, str_repeat('0', 40), strtoupper(self::GUID_A), substr(self::GUID_A, 1), self::GUID_A.'0', '',
                    "\xff".substr(self::GUID_B, 1), 12345, null, bin2hex($random->getBytes(20))][$random->getInt(0, 10)],
                'segments' => null])), self::int($random)],
            PrepareReleaseCandidates::class => [$rows(static fn (): array => $fields(['parts' => null, 'size' => null])),
                [107374182400, self::int($random), $random->getInt(-5, 2000000)][$random->getInt(0, 2)]],
            VerifyReleaseCandidates::class => [$rows(static fn (): array => $fields([
                'code' => static fn (): mixed => ['complete', 'repaired', 'sampled', 'unknown', 'unrepairable', 'failed', 'deferred', 'Unknown', '', ' sampled',
                    'pending', 7, null][$random->getInt(0, 12)],
                'attempts' => static fn (): mixed => $random->getInt(0, 4) === 0 ? self::int($random) : [$random->getInt(-2, 6), '2', null][$random->getInt(0, 2)]])),
                [3, $random->getInt(-2, 8), self::int($random)][$random->getInt(0, 2)]],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    private static function int(Randomizer $random): int
    {
        return match ($random->getInt(0, 5)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => $random->getInt(-3, 12),
            2 => $random->getInt(PHP_INT_MAX - 5, PHP_INT_MAX),
            3 => $random->getInt(PHP_INT_MIN, PHP_INT_MIN + 5),
            4 => $random->getInt(990, 1010),
            default => $random->getInt(0, 1000000),
        };
    }

    /** @return list<array{class-string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 7));
        $many = static fn (string $class, int $count): array => array_map(static fn (): array => self::randomArgs($class, $random), range(1, $count));
        $chunk = static fn (int $i): array => ['scanned' => 1000, 'updated' => 990 - $i % 7, 'missing_nzb' => $i % 5, 'corrupt_xml' => $i % 3, 'duration_ms' => 80 + $i % 40];
        $guid = static fn (int $i): string => sha1('release-'.$i);
        $records = static fn (int $n, int $offset = 0): array => array_map(static fn (int $i): array => ['guid' => $guid($i % max(1, $n - 3)), 'segments' => 1 + $i % 60],
            range($offset, $offset + $n - 1));
        $candidates = static fn (int $n): array => array_map(static fn (int $i): array => ['parts' => $i % 11, 'size' => [$i * 7919, -1, 107374182401][$i % 13 === 0 ? 1 : ($i % 17 === 0 ? 2 : 0)]],
            range(1, $n));
        $codes = ['complete', 'sampled', 'unknown', 'repaired', 'unknown', 'deferred', 'unrepairable', 'failed'];
        $outcomes = static fn (int $n): array => array_map(static fn (int $i): array => ['code' => $codes[$i % 8], 'attempts' => $i % 5], range(1, $n));

        return [
            [BackfillReleaseFingerprintMetadata::class, [[array_map($chunk, range(1, 10)), 1000], [array_map($chunk, range(1, 4)), 10000], [[], 1000], [array_map($chunk, range(1, 25)), 5000]],
                [[array_map($chunk, range(1, 1000)), 1000000], [array_fill(0, 200, ['scanned' => PHP_INT_MAX, 'updated' => '1', 'duration_ms' => PHP_INT_MIN]), PHP_INT_MIN],
                    ...$many(BackfillReleaseFingerprintMetadata::class, 12)]],
            [TransferFingerprintQueue::class, [[5000, 1000, 5, 0, 10000], [120, 1000, 1, 40, 500], [0, 1000, 5, 0, 10000], [250000, 1000, 10, 2000, 10000]],
                [[PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX], [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN], [10, 3, 0, 0, 10],
                    ...$many(TransferFingerprintQueue::class, 12)]],
            [ImportVerificationManifests::class, [[$records(100), 1], [$records(250, 1000), 2], [$records(20, 5), 1], [$records(500, 9), 1]],
                [[$records(2000), 30], [array_fill(0, 300, ['guid' => 'NOT-A-GUID', 'segments' => 'x']), PHP_INT_MIN], ...$many(ImportVerificationManifests::class, 12)]],
            [PrepareReleaseCandidates::class, [[$candidates(200), 107374182400], [$candidates(50), 107374182400], [$candidates(500), 1000000], [$candidates(10), 107374182400]],
                [[$candidates(3000), PHP_INT_MAX], [array_fill(0, 300, ['parts' => '1', 'size' => 1.5]), PHP_INT_MIN], ...$many(PrepareReleaseCandidates::class, 12)]],
            [VerifyReleaseCandidates::class, [[$outcomes(50), 3], [$outcomes(10), 3], [$outcomes(200), 5], [$outcomes(1), 3]],
                [[$outcomes(3000), PHP_INT_MAX], [array_fill(0, 300, ['code' => str_repeat('unknown', 50), 'attempts' => PHP_INT_MIN]), PHP_INT_MIN],
                    ...$many(VerifyReleaseCandidates::class, 12)]],
        ];
    }

    /**
     * @param  list<int>  $values
     * @return array{count: int, mean_ns: float, p99_ns: int, min_ns: int, max_ns: int}
     */
    private static function statistics(array $values): array
    {
        sort($values, SORT_NUMERIC);
        $count = \count($values);

        return ['count' => $count, 'mean_ns' => array_sum($values) / $count, 'p99_ns' => $values[(int) ceil($count * 0.99) - 1],
            'min_ns' => $values[0], 'max_ns' => $values[$count - 1]];
    }

    /** @param list<string> $args */
    private static function command(array $args): string
    {
        $process = new Process($args, base_path());
        $process->mustRun();

        return trim($process->getOutput());
    }
}
