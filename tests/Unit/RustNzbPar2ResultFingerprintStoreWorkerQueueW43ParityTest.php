<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Par2RenameOutcome;
use App\Services\Nzb\Par2CandidateRecoveryResult;
use App\Services\Nzb\ReleaseArticleFingerprintStore;
use App\Services\Nzb\RustArticleFingerprintWorker;
use App\Services\Releases\Fingerprint\CompactFingerprintCollector;
use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use App\Support\RustPipelineLibrary;
use ArrayObject;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNzbPar2ResultFingerprintStoreWorkerQueueW43ParityTest extends TestCase
{
    /** class => helper method; every class exposes the same Wave 43.2 toggle trio. */
    private const array HELPERS = [
        Par2CandidateRecoveryResult::class => 'summarizeRecoveryBatch',
        ReleaseArticleFingerprintStore::class => 'summarizeFingerprintBatch',
        RustArticleFingerprintWorker::class => 'summarizeAddFrames',
        CompactFingerprintCollector::class => 'summarizeIdentityStatuses',
        DatabaseFingerprintQueue::class => 'summarizeEnqueueDecisions',
    ];

    /** class => W432_RUST_FFI_DEFAULT; only helpers clearing every 1.5x gate default to Rust. */
    private const array DEFAULTS = [
        Par2CandidateRecoveryResult::class => true,
        ReleaseArticleFingerprintStore::class => false,
        RustArticleFingerprintWorker::class => false,
        CompactFingerprintCollector::class => false,
        DatabaseFingerprintQueue::class => false,
    ];

    private const array OUTCOMES = ['renamed', 'no_match', 'retryable', 'deferred', 'Renamed', 'NO_MATCH', '', 'renamed ', 'other'];

    private const array STATUSES = ['pending', 'processing', 'done', 'failed', 'Pending', '', 'processing ', 'stale'];

    private const int SEED = 43022026;

    private const int RANDOMIZED = 1300;

    private const int OVERSIZED = 16 * 1024 * 1024 + 1;

    protected function tearDown(): void
    {
        foreach (array_keys(self::HELPERS) as $class) {
            $class::resetW432RustFfi();
        }
        parent::tearDown();
    }

    public function test_defaults_and_toggles_are_independent(): void
    {
        $earlier = self::earlierToggles();
        $this->assertCount(4, $earlier);
        foreach (self::DEFAULTS as $class => $default) {
            $this->assertSame($default, \constant($class.'::W432_RUST_FFI_DEFAULT'), $class);
            $this->assertSame($default, $class::usesW432RustFfi(), $class);
            $reflection = new ReflectionClass($class);
            foreach (['useW432RustFfi', 'usesW432RustFfi', 'resetW432RustFfi', self::HELPERS[$class], self::HELPERS[$class].'Php', self::HELPERS[$class].'Native'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), $class.'::'.$method);
            }
        }
        foreach (array_keys(self::HELPERS) as $selected) {
            foreach (array_keys(self::HELPERS) as $class) {
                $class::useW432RustFfi(false);
            }
            $selected::useW432RustFfi();
            foreach (array_keys(self::HELPERS) as $class) {
                $this->assertSame($selected === $class, $class::usesW432RustFfi(), $selected.' → '.$class);
            }
            $this->assertSame($earlier, self::earlierToggles(), $selected.' leaves the earlier toggles alone');
            $selected::useW432RustFfi(null);
            $this->assertSame(self::DEFAULTS[$selected], $selected::usesW432RustFfi());
            $selected::useW432RustFfi(true);
            $this->assertTrue($selected::usesW432RustFfi());
            foreach (array_keys(self::HELPERS) as $class) {
                $class::resetW432RustFfi();
                $this->assertSame(self::DEFAULTS[$class], $class::usesW432RustFfi(), $class);
            }
        }
        // The earlier toggles leave the Wave 43.2 toggles alone.
        Par2CandidateRecoveryResult::setUseRustFfi(! Par2CandidateRecoveryResult::usesRustFfi());
        ReleaseArticleFingerprintStore::enableRustFfi();
        CompactFingerprintCollector::setUseRustFfi(false);
        DatabaseFingerprintQueue::enableRustFfi();
        try {
            foreach (self::DEFAULTS as $class => $default) {
                $this->assertSame($default, $class::usesW432RustFfi(), $class);
            }
        } finally {
            foreach ($earlier as $class => $value) {
                (new ReflectionClass($class))->getProperty('useRustFfi')->setValue(null, $value);
            }
        }
        $this->assertSame($earlier, self::earlierToggles());
    }

    public function test_php_helpers_answer_known_vectors(): void
    {
        $this->assertSame(['attempts' => 9, 'invalid' => 1, 'unknown' => 2, 'renamed' => 3, 'no_match' => 1, 'retryable' => 1, 'deferred' => 1,
            'terminal' => 4, 'retry' => 1, 'probes' => PHP_INT_MAX, 'articles' => PHP_INT_MAX, 'total_reads' => PHP_INT_MAX, 'max_articles_per_probe' => 3],
            Par2CandidateRecoveryResult::summarizeRecoveryBatchPhp([
                ['outcome' => 'renamed', 'probes' => 2, 'articles' => 7], ['outcome' => 'no_match', 'probes' => -3, 'articles' => 5],
                ['outcome' => 'retryable', 'probes' => 4, 'articles' => -1], ['outcome' => Par2RenameOutcome::Deferred, 'probes' => 0, 'articles' => 9],
                ['outcome' => 'Renamed', 'probes' => 1, 'articles' => 1], ['outcome' => '', 'probes' => 1, 'articles' => 0], ['outcome' => 'renamed', 'probes' => '1', 'articles' => 1],
                ['outcome' => 'renamed', 'probes' => PHP_INT_MAX, 'articles' => PHP_INT_MAX], new Par2CandidateRecoveryResult(Par2RenameOutcome::Renamed, 3, 3),
            ]));
        $canonical = sha1('a');
        $this->assertSame(['fingerprints' => 8, 'absent' => 1, 'invalid' => 2, 'stored' => 5, 'canonical' => 2, 'noncanonical_hex' => 1, 'empty' => 1,
            'other' => 1, 'distinct' => 4, 'payload_bytes' => 123],
            ReleaseArticleFingerprintStore::summarizeFingerprintBatchPhp([$canonical, $canonical, strtoupper($canonical), null, 5, '', 'xyz', [$canonical]]));
        $this->assertSame(['message_ids' => 7, 'invalid' => 1, 'framed' => 3, 'empty' => 1, 'oversized' => 2, 'dropped' => 1, 'unsupported' => true,
            'frame_bytes' => 15 + 5 + 5 + 16777216, 'full_buffers' => intdiv(25 + 16777216, 65536)],
            RustArticleFingerprintWorker::summarizeAddFramesPhp([str_repeat('a', 10), '', null, str_repeat('m', 16777216), self::oversized(), 'abc', self::oversized()]));
        $this->assertSame(['rows' => 9, 'invalid' => 1, 'confirmed' => 4, 'unconfirmed' => 2, 'header_mismatch' => 1, 'status_mismatch' => 1],
            CompactFingerprintCollector::summarizeIdentityStatusesPhp([
                ['requested' => 'a@b', 'header' => " <a@b>\n", 'response_text' => '220 0 <a@b> article'], ['requested' => '<<a@b>>', 'header' => '<a@b>'],
                ['requested' => 'a@b', 'header' => '<A@b>'], ['requested' => 'a@b', 'header' => " \t"], ['requested' => 'a@b', 'response_text' => '220 <a@b>'],
                ['requested' => 'a@b', 'header' => '<a@b>', 'response_text' => '220 <> <<x>> <a@b>'], ['requested' => 'a@b', 'header' => '<a@b>', 'response_text' => '220 <> no id'],
                ['requested' => '', 'header' => '<>'], ['header' => '<a@b>'],
            ]));
        $job = static fn (string $digest, string $status): stdClass => (object) ['source_digest' => $digest, 'status' => $status];
        $this->assertSame(['rows' => 8, 'invalid' => 2, 'missing_job' => 1, 'digest_changed' => 1, 'skipped' => 3, 'forced' => 1, 'skipped_while_forced' => 2],
            DatabaseFingerprintQueue::summarizeEnqueueDecisionsPhp([
                ['job' => $job('a', 'pending'), 'digest' => 'a', 'force' => false], ['job' => $job('a', 'pending'), 'digest' => 'a', 'force' => true],
                ['job' => ['source_digest' => 'a', 'status' => 'done'], 'digest' => 'a', 'force' => true], ['job' => $job('a', 'pending'), 'digest' => 'b', 'force' => false],
                ['job' => null, 'digest' => 'a', 'force' => true], ['job' => $job('a', 'pending'), 'digest' => 'a', 'force' => 1],
                ['job' => $job('a', 'processing'), 'digest' => 'a', 'force' => true], ['job' => new ArrayObject(['source_digest' => 'a', 'status' => 'done']), 'digest' => 'a', 'force' => true],
            ]));
    }

    public function test_batch_helpers_agree_with_single_value_helpers(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + 3));
        for ($i = 0; $i < 300; $i++) {
            $outcome = self::OUTCOMES[$random->getInt(0, \count(self::OUTCOMES) - 1)];
            [$probes, $articles] = [self::int($random), self::int($random)];
            $metrics = Par2CandidateRecoveryResult::summarizeRecoveryMetricsPhp($outcome, $probes, $articles);
            $batch = Par2CandidateRecoveryResult::summarizeRecoveryBatchPhp([['outcome' => $outcome, 'probes' => $probes, 'articles' => $articles]]);
            $this->assertSame([$metrics['probes'], $metrics['articles'], $metrics['total_reads'], $metrics['articles_per_probe'], (int) $metrics['terminal'], (int) $metrics['retry']],
                [$batch['probes'], $batch['articles'], $batch['total_reads'], $batch['max_articles_per_probe'], $batch['terminal'], $batch['retry']]);
            $this->assertSame(1, $batch[$metrics['outcome'] ?? 'unknown']);

            $fingerprint = [null, sha1((string) $i), '', bin2hex($random->getBytes(3))][$i % 4];
            $this->assertSame(ReleaseArticleFingerprintStore::updatePayloadPhp($fingerprint) === [] ? 0 : 1,
                ReleaseArticleFingerprintStore::summarizeFingerprintBatchPhp([$fingerprint])['stored']);

            $job = $random->getInt(0, 3) === 0 ? null : (object) ['source_digest' => ['a', 'b'][$random->getInt(0, 1)], 'status' => self::STATUSES[$random->getInt(0, 7)]];
            $force = $random->getInt(0, 1) === 1;
            $digest = ['a', 'b'][$random->getInt(0, 1)];
            $this->assertSame((int) DatabaseFingerprintQueue::shouldSkipPhp($job, $digest, $force),
                DatabaseFingerprintQueue::summarizeEnqueueDecisionsPhp([['job' => $job, 'digest' => $digest, 'force' => $force]])['skipped']);
        }
    }

    public function test_native_matches_php_on_edge_cases_and_randomized_inputs(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::HELPERS as $class => $method) {
            $cases = [...self::edgeCases($class), ...array_map(static fn (): array => self::randomArgs($class, $random), range(1, self::RANDOMIZED))];
            $this->assertGreaterThanOrEqual(self::RANDOMIZED, \count($cases));
            foreach ($cases as $args) {
                $expected = $class::{$method.'Php'}(...$args);
                $native = $class::{$method.'Native'}(...$args);
                $class::useW432RustFfi(true);
                $viaRust = $class::$method(...$args);
                $class::useW432RustFfi(false);
                $viaPhp = $class::$method(...$args);
                if ($native !== $expected || $viaRust !== $expected || $viaPhp !== $expected) {
                    $this->fail($class.'::'.$method.' diverged for '.self::describe($args).': '.json_encode([$expected, $native, $viaRust, $viaPhp]));
                }
                $this->assertSame($expected, $native);
            }
        }
    }

    public function test_benchmark_qualified_default_dispatches_without_overrides(): void
    {
        $this->nativeFfi();
        foreach (self::HELPERS as $class => $method) {
            $class::resetW432RustFfi();
            $this->assertSame(self::DEFAULTS[$class], $class::usesW432RustFfi(), $class);
            foreach (self::edgeCases($class) as $args) {
                $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
            }
        }
        $this->assertNotNull(Par2CandidateRecoveryResult::summarizeRecoveryBatchNative([new Par2CandidateRecoveryResult(Par2RenameOutcome::Renamed, 1, 2)]));
    }

    public function test_out_of_contract_inputs_fall_back_to_php(): void
    {
        $this->nativeFfi();
        $valid = [2, 1, [3], '', 9];
        foreach ([[5, 0, [], '', 1], [-1, ...\array_slice($valid, 1)], [4294967296, ...\array_slice($valid, 1)], [2, -1, [], '', 9], [2, 2, [3], '', 9],
            [2, 1, [3], 'x', 9], [2, 1, [-2], '', 9], [0, 1, [3, 0, 0], 'ab', 13], [0, 1, [-2, 0, 0], '', 13], [1, 1, [-1, 0], '', 10],
            [1, 1, [1, 1], 'a', 10], [1, 1, [1, 3], 'a', 10], [3, 1, [-1, 1, -1], 'x', 6], [4, 1, [-1, -1, 1, 2], 'a', 7], [4, 1, [1, 1, 1, 0], 'abc', 7],
            [4, 1, [-1, -1, -1, 0], '', 7], [4, 1, [-1, -1, 1, 8], 'a', 7], [4, 1, [-1, -1, 1, 4], 'a', 7], [2, 0, [], str_repeat("\0", 16777201), 9]] as $vector) {
            $this->assertNull(Par2CandidateRecoveryResult::evaluateW432Native(...$vector), json_encode(\array_slice($vector, 0, 3)) ?: '');
        }
        $this->assertNull(Par2CandidateRecoveryResult::evaluateW432Native(2, 1, [3], '', 0));
        // A wrong expected slot count is a contract violation, not a partial answer.
        $this->assertNull(Par2CandidateRecoveryResult::evaluateW432Native(2, 1, [3], '', 8));
        $this->assertNull(Par2CandidateRecoveryResult::evaluateW432Native(2, 1, [3], '', 10));
        $this->assertSame([1, 0, 1, 0, 0, 0, 0, 8, 0], Par2CandidateRecoveryResult::evaluateW432Native(...$valid));
        $huge = str_repeat('n', 16777200);
        $oversized = [
            Par2CandidateRecoveryResult::class => [[['outcome' => $huge, 'probes' => 1, 'articles' => 1]]],
            ReleaseArticleFingerprintStore::class => [[$huge]],
            RustArticleFingerprintWorker::class => [array_fill(0, 2097151, 'x')],
            CompactFingerprintCollector::class => [[['requested' => $huge, 'header' => '<x>']]],
            DatabaseFingerprintQueue::class => [[['job' => null, 'digest' => $huge, 'force' => false]]],
        ];
        foreach ($oversized as $class => $args) {
            $method = self::HELPERS[$class];
            $this->assertNull($class::{$method.'Native'}(...$args), $class);
            $class::useW432RustFfi(true);
            $this->assertSame($class::{$method.'Php'}(...$args), $class::$method(...$args), $class);
        }
    }

    public function test_direct_ffi_export_preserves_sentinels_and_capacity_contract(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, pack('q5', 0, 1, 7, 2, 7).'renamed', [1, 0, 0, 1, 0, 0, 0, 1, 0, 2, 7, 9, 3]],
            [1, pack('q4', 0, 1, 3, 0).'abc', [1, 0, 0, 1, 0, 0, 0, 1, 1, 3]],
            [2, pack('q3', 0, 1, 3), [1, 0, 1, 0, 0, 0, 0, 8, 0]],
            [3, pack('q5', 0, 1, 1, 3, -1).'a<a>', [1, 0, 1, 0, 0, 0]],
            [4, pack('q6', 0, 1, 1, 7, 1, 2).'apendinga', [1, 0, 0, 0, 1, 0, 0]]];
        foreach ($vectors as [$op, $input, $expected]) {
            $length = \count($expected) * 8;
            $wire = pack('q*', ...$expected);
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w432_nzb_par2_result_fingerprint_store_worker_queue($op, 0, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2), $op.' capacity '.$capacity);
                if ($written < 0) {
                    // Retry with exactly the reported capacity.
                    $retry = $ffi->new('char['.(-$written).']');
                    // @phpstan-ignore method.notFound
                    $this->assertSame(-$written, $ffi->nntmux_w432_nzb_par2_result_fingerprint_store_worker_queue($op, 0, $input, \strlen($input), $retry, -$written));
                    $this->assertSame($wire, FFI::string($retry, -$written));
                }
            }
            // Every flag bit is rejected for every operation.
            foreach ([1, 64, 4294967295] as $flags) {
                // @phpstan-ignore method.notFound
                $this->assertSame(-1, $ffi->nntmux_w432_nzb_par2_result_fingerprint_store_worker_queue($op, $flags, $input, \strlen($input), $small, $length + 2));
            }
        }
        $buffer = $ffi->new('char[1024]');
        $input = pack('q3', 0, 1, 3);
        foreach ([[null, 0, $buffer, 1024], [null, 24, $buffer, 1024], [$input, 24, null, 0], [$input, 24, null, 72], [$input, 16777217, $buffer, 1024],
            [$buffer, 24, $buffer, 1024], [FFI::addr($buffer[8]), 24, $buffer, 1024], [$buffer, 24, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 32);
            FFI::memset(FFI::addr($buffer[32]), 0x5A, 992);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w432_nzb_par2_result_fingerprint_store_worker_queue(2, 0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 32).str_repeat('Z', 992), FFI::string($buffer, 1024));
        }
        foreach ([5, 99, 4294967295] as $op) {
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w432_nzb_par2_result_fingerprint_store_worker_queue($op, 0, $input, 24, $buffer, 1024));
        }
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $helpers = unserialize((string) stream_get_contents(STDIN));
            if (App\Services\Nzb\Par2CandidateRecoveryResult::evaluateW432Native(2, 1, [3], '', 9) !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($helpers as [$class, $method, $cases]) {
                foreach ($cases as $args) {
                    if ($class::{$method.'Native'}(...$args) !== null) {
                        throw new RuntimeException($class.'::'.$method.' answered without a library');
                    }
                    $class::useW432RustFfi(false);
                    $expected = serialize($class::$method(...$args));
                    $class::useW432RustFfi(true);
                    if ($expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $helpers = [];
        foreach (self::HELPERS as $class => $method) {
            $helpers[] = [$class, $method, self::edgeCases($class, false)];
        }
        $missing = '/tmp/w43-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        // The cases travel on stdin: the large edge cases exceed the argument list limit.
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-d', 'memory_limit=1G', '-r', $code, base_path()], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing], serialize($helpers));
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    /**
     * Gate: for every helper, the per-round minimum of common mean, common p99, uncommon mean and uncommon p99
     * speedup (PHP latency / Rust latency, including packing, FFI and unpacking) must be >= 1.5x before its
     * W432_RUST_FFI_DEFAULT may be true. Per-round ranges over 6 runs of 3 rounds (PHP 8.5.12 NTS, opcache CLI off,
     * Apple M5), common mean/p99 then uncommon mean/p99: recovery batch 1.85-2.33x/1.55-2.76x, 2.83-3.12x/2.89-3.63x;
     * fingerprint batch 0.72-0.81x/0.77-1.00x, 0.83-0.89x/0.88-1.03x; worker frames 1.10-1.20x/0.92-1.28x,
     * 1.09-1.21x/1.17-1.39x; identity statuses 0.88-1.11x/0.68-1.64x, 2.24-2.45x/2.97-3.52x; enqueue decisions
     * 0.86-0.96x/0.84-1.00x, 0.89-0.95x/0.89-1.02x. Only Par2CandidateRecoveryResult clears every gate, so it alone
     * defaults to Rust; small batches lose to the FFI round trip and per-row packing elsewhere.
     */
    public function test_paired_randomized_benchmarks_gate_rust_defaults(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave43-2-nzb-par2-result-fingerprint-store-worker-queue-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including normalization, packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && php artisan test --compact tests/Unit/RustNzbPar2ResultFingerprintStoreWorkerQueueW43ParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'cargo --release (rust/pipeline-ffi/Cargo.toml profile)', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/Nzb/Par2CandidateRecoveryResult.php',
            'app/Services/Nzb/ReleaseArticleFingerprintStore.php', 'app/Services/Nzb/RustArticleFingerprintWorker.php',
            'app/Services/Releases/Fingerprint/CompactFingerprintCollector.php', 'app/Services/Releases/Fingerprint/DatabaseFingerprintQueue.php',
            'tests/Unit/RustNzbPar2ResultFingerprintStoreWorkerQueueW43ParityTest.php'] as $file) {
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
                        $expected[] = serialize($class::{$method.'Php'}(...$args));
                        $this->assertSame(end($expected), serialize($class::{$method.'Native'}(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'inputs' => \count($inputs), 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::useW432RustFfi($enabled);
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
                                $class::useW432RustFfi($side === 'native');
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
                $class::resetW432RustFfi();
                $this->assertSame(self::DEFAULTS[$class], \constant($class.'::W432_RUST_FFI_DEFAULT'), $name);
                $this->assertSame(self::DEFAULTS[$class], $class::usesW432RustFfi(), $name);
                if (! $qualifies) {
                    $this->assertFalse(\constant($class.'::W432_RUST_FFI_DEFAULT'), $name.' must keep PHP as the default below 1.5x');
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
                if (! isset($cells['common']['round_range'], $cells['uncommon']['round_range'])) {
                    continue;
                }
                $summary[] = sprintf('%s common mean %.2f-%.2fx p99 %.2f-%.2fx; uncommon mean %.2f-%.2fx p99 %.2f-%.2fx', $helper,
                    $cells['common']['round_range']['mean_speedup']['min'], $cells['common']['round_range']['mean_speedup']['max'],
                    $cells['common']['round_range']['p99_speedup']['min'], $cells['common']['round_range']['p99_speedup']['max'],
                    $cells['uncommon']['round_range']['mean_speedup']['min'], $cells['uncommon']['round_range']['mean_speedup']['max'],
                    $cells['uncommon']['round_range']['p99_speedup']['min'], $cells['uncommon']['round_range']['p99_speedup']['max']);
            }
            fwrite(STDERR, "\nWave 43.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(Par2CandidateRecoveryResult::W432_FFI_DOMAIN, Par2CandidateRecoveryResult::W432_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** @return array<class-string, mixed> the earlier-wave `useRustFfi` toggle of every helper class */
    private static function earlierToggles(): array
    {
        $toggles = [];
        foreach (array_keys(self::HELPERS) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->hasProperty('useRustFfi')) {
                $toggles[$class] = $reflection->getProperty('useRustFfi')->getValue();
            }
        }

        return $toggles;
    }

    /** @param list<mixed> $args */
    private static function describe(array $args): string
    {
        return substr(bin2hex(serialize($args)), 0, 400);
    }

    private static function oversized(): string
    {
        static $value = null;

        return $value ??= str_repeat('o', self::OVERSIZED);
    }

    /** @return list<list<mixed>> */
    private static function edgeCases(string $class, bool $withHuge = true): array
    {
        $attempt = static fn (mixed $outcome, mixed $probes, mixed $articles): array => ['outcome' => $outcome, 'probes' => $probes, 'articles' => $articles];
        $identity = static fn (mixed $requested, mixed $header = null, mixed $text = null): array => ['requested' => $requested, 'header' => $header, 'response_text' => $text];
        $job = static fn (string $digest, string $status): stdClass => (object) ['source_digest' => $digest, 'status' => $status];
        $decision = static fn (mixed $job, mixed $digest, mixed $force): array => ['job' => $job, 'digest' => $digest, 'force' => $force];
        $odd = [null, 5, 1.5, true, 'x', [], new stdClass];
        $sha = sha1('fingerprint');
        $huge = $withHuge ? [self::oversized(), 'after', self::oversized(), 'tail'] : [];

        return match ($class) {
            Par2CandidateRecoveryResult::class => [[[]], [$odd],
                [array_map(static fn (string $outcome): array => $attempt($outcome, 3, 10), self::OUTCOMES)],
                [[$attempt('renamed', PHP_INT_MAX, PHP_INT_MAX), $attempt('renamed', PHP_INT_MIN, PHP_INT_MIN), $attempt('no_match', 1, PHP_INT_MAX),
                    $attempt('retryable', PHP_INT_MAX, 1), $attempt(Par2RenameOutcome::Deferred, 0, 0), $attempt('deferred', '1', 1), $attempt(null, 1, 1),
                    $attempt('renamed', 1.0, 1), ['outcome' => 'renamed'], new Par2CandidateRecoveryResult(Par2RenameOutcome::NoMatch, 2, 9)]],
                [['a' => $attempt('renamed', 1, 2), 'b' => new Par2CandidateRecoveryResult(Par2RenameOutcome::Retryable, -1, 4)]],
                [[$attempt("renamed\0", 1, 1), $attempt('ré', 1, 1), $attempt(str_repeat('x', 4096), 7, 70)]]],
            ReleaseArticleFingerprintStore::class => [[[]], [$odd], [[$sha, $sha, strtoupper($sha), substr($sha, 1), $sha.'0', '', '0', 0, null, '00', 'G'.substr($sha, 1)]],
                [['a' => $sha, 'b' => $sha, 'c' => null, 'd' => '1', 'e' => '01']], [[str_repeat('a', 40), str_repeat('F', 40), str_repeat('g', 40), "\0".substr($sha, 1)]],
                [array_map(static fn (int $i): ?string => $i % 9 === 0 ? null : sha1((string) ($i % 17)), range(1, 200))]],
            RustArticleFingerprintWorker::class => [[[]], [$odd], [['', 'a', 'part1of2.abc@news.example', '<x@y>', null, 7]],
                [array_fill(0, 13108, 'abcdefgh')], [['a' => 'x@y', 'b' => '']], ...($withHuge ? [[[str_repeat('m', 16777216)]], [$huge], [['ok', ...$huge, null]]] : [])],
            CompactFingerprintCollector::class => [[[]], [$odd], [[$identity('a@b', ' <a@b> ', '220 0 <a@b> article'), $identity('<<a@b>>', '<a@b>'), $identity('a@b', '<A@b>'),
                $identity('a@b', " \t\0\x0B"), $identity('a@b'), $identity('a@b', '<a@b>', '220 <> <<x>> <a@b>'), $identity('a@b', '<a@b>', '220 <> no id'),
                $identity('', '<>'), ['header' => '<a@b>'], $identity(5, '<5>'), $identity('a@b', 5), $identity('a@b', '<a@b>', 5), $identity('a@b', '<a@b>', '<a@b'),
                $identity('a@b', '<a@b>', "220 <a\n@b>"), $identity('a@b', '<a@b>', '<a@b>>'), $identity('>a@b<', '<a@b>'), $identity('a@b', '<a@b>', '<<a@b>')]],
                [['k' => $identity('x@y', '<x@y>'), 'l' => $identity('x@y', '<y@x>')]], [[$identity('ä@b', '<ä@b>', '220 <ä@b>'), $identity("a\0b", "<a\0b>")]]],
            DatabaseFingerprintQueue::class => [[[]], [$odd], [[$decision($job('a', 'pending'), 'a', false), $decision($job('a', 'pending'), 'a', true),
                $decision(['source_digest' => 'a', 'status' => 'done'], 'a', true), $decision($job('a', 'pending'), 'b', false), $decision(null, 'a', true),
                $decision($job('a', 'pending'), 'a', 1), $decision($job('a', 'processing'), 'a', true), $decision(new ArrayObject(['source_digest' => 'a', 'status' => 'x']), 'a', true),
                $decision(['source_digest' => 5, 'status' => 'done'], 'a', true), $decision(['source_digest' => 'a'], 'a', true), $decision($job('', ''), '', true),
                $decision($job('A', 'pending'), 'a', false), $decision($job('a', 'Processing'), 'a', true), $decision(null, 5, true), ['digest' => 'a', 'force' => false]]],
                [['x' => $decision(null, 'a', false), 'y' => $decision($job('a', 'done'), 'a', false)]]],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<mixed> */
    private static function randomArgs(string $class, Randomizer $random): array
    {
        $rows = static function (callable $row, int $max = 16) use ($random): array {
            $out = [];
            for ($i = $random->getInt(0, $max); $i > 0; $i--) {
                $out[] = $random->getInt(0, 11) === 0 ? [null, 7, 1.5, true, [], 'x'][$random->getInt(0, 5)] : $row();
            }

            return $out;
        };
        $odd = static fn (): mixed => ['5', 5.0, null, true, [], 7][$random->getInt(0, 5)];
        $pick = static fn (array $values): mixed => $values[$random->getInt(0, \count($values) - 1)];
        $messageId = static fn (): string => match ($random->getInt(0, 5)) {
            0 => '', 1 => self::bytes($random, $random->getInt(1, 12)),
            default => 'part'.$random->getInt(1, 99).'of99.'.bin2hex($random->getBytes(6)).'@news.'.$pick(['example', 'test', 'invalid']),
        };

        return match ($class) {
            Par2CandidateRecoveryResult::class => [$rows(static function () use ($random, $odd, $pick): mixed {
                if ($random->getInt(0, 4) === 0) {
                    return new Par2CandidateRecoveryResult($pick(Par2RenameOutcome::cases()), self::int($random), self::int($random));
                }
                $attempt = ['outcome' => $random->getInt(0, 9) === 0 ? $pick(Par2RenameOutcome::cases()) : ($random->getInt(0, 7) === 0 ? self::bytes($random, $random->getInt(0, 9)) : $pick(self::OUTCOMES)),
                    'probes' => self::int($random), 'articles' => self::int($random)];
                if ($random->getInt(0, 15) === 0) {
                    $attempt[$pick(['outcome', 'probes', 'articles'])] = $odd();
                }

                return $attempt;
            })],
            ReleaseArticleFingerprintStore::class => [$rows(static fn (): mixed => match ($random->getInt(0, 9)) {
                0 => $odd(), 1 => null, 2 => strtoupper(sha1((string) $random->getInt(0, 9))), 3 => self::bytes($random, $random->getInt(0, 41)),
                4 => '', 5 => substr(sha1((string) $random->getInt(0, 9)), 0, $random->getInt(0, 40)),
                default => sha1((string) $random->getInt(0, 20)),
            }, 24)],
            RustArticleFingerprintWorker::class => [$rows(static fn (): mixed => match ($random->getInt(0, 59)) {
                0 => self::oversized(), 1, 2 => $odd(), default => $messageId(),
            }, 24)],
            CompactFingerprintCollector::class => [$rows(static function () use ($random, $odd, $pick, $messageId): mixed {
                $id = $messageId();
                $wrapped = '<'.trim($id, '<>').'>';
                $requested = $pick([$id, $id, '<'.$id.'>', '<<'.$id, $id.'>']);
                $header = match ($random->getInt(0, 7)) {
                    0 => $odd(), 1 => $pick(['', ' ', "\t\n"]), 2 => '<'.$messageId().'>', 3 => strtoupper($wrapped),
                    4 => $pick([' ', "\t", "\0", "\x0B"]).$wrapped.$pick([' ', "\r\n", '']), default => $wrapped,
                };
                $text = match ($random->getInt(0, 6)) {
                    0 => $odd(), 1 => '220 0 '.$wrapped.' article', 2 => '220 0 <'.$messageId().'> article', 3 => '220 0 article',
                    4 => '220 <> '.$pick(['', $wrapped, '<<'.$id.'>']), 5 => self::bytes($random, $random->getInt(0, 20)),
                    default => '220 '.$random->getInt(0, 999999).' '.$wrapped,
                };
                $row = ['requested' => $requested, 'header' => $header, 'response_text' => $text];
                $mutation = $random->getInt(0, 15);
                if ($mutation === 0) {
                    $row['requested'] = $odd();
                } elseif ($mutation === 1) {
                    unset($row[$pick(['header', 'response_text'])]);
                }

                return $row;
            })],
            DatabaseFingerprintQueue::class => [$rows(static function () use ($random, $odd, $pick): mixed {
                $digest = hash('sha256', (string) $random->getInt(0, 3));
                $status = $pick(self::STATUSES);
                $jobDigest = $random->getInt(0, 2) === 0 ? hash('sha256', (string) $random->getInt(0, 3)) : $digest;
                $job = match ($random->getInt(0, 7)) {
                    0 => null, 1 => ['source_digest' => $jobDigest, 'status' => $status], 2 => new ArrayObject(['source_digest' => $jobDigest, 'status' => $status]),
                    3 => (object) ['source_digest' => $random->getInt(0, 1) === 0 ? $odd() : $jobDigest, 'status' => $random->getInt(0, 1) === 0 ? $odd() : $status],
                    default => (object) ['source_digest' => $jobDigest, 'status' => $status],
                };
                $row = ['job' => $job, 'digest' => $digest, 'force' => $random->getInt(0, 1) === 1];
                if ($random->getInt(0, 15) === 0) {
                    $row[$pick(['digest', 'force'])] = $odd();
                }

                return $row;
            })],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    private static function bytes(Randomizer $random, int $length): string
    {
        return $length < 1 ? '' : $random->getBytes($length);
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
        $attempts = static fn (int $n): array => array_map(static fn (int $i): Par2CandidateRecoveryResult => new Par2CandidateRecoveryResult(
            Par2RenameOutcome::cases()[$i % 4], 1 + $i % 3, 4 + $i % 20), range(1, $n));
        $fingerprints = static fn (int $n): array => array_map(static fn (int $i): ?string => $i % 13 === 0 ? null : sha1('release-'.$i), range(1, $n));
        $ids = static fn (int $n): array => array_map(static fn (int $i): string => 'part'.$i.'of'.$n.'.'.substr(md5((string) $i), 0, 20).'@powerpost2000AA.local', range(1, $n));
        $identities = static fn (int $n): array => array_map(static function (int $i): array {
            $id = 'part'.$i.'of99.'.substr(sha1((string) $i), 0, 16).'@news.example';

            return ['requested' => $id, 'header' => '<'.$id.'>', 'response_text' => '220 '.$i.' <'.($i % 11 === 0 ? 'other@x' : $id).'> article'];
        }, range(1, $n));
        $decisions = static fn (int $n): array => array_map(static fn (int $i): array => ['job' => $i % 5 === 0 ? null
            : (object) ['source_digest' => hash('sha256', (string) ($i % 7 === 0 ? -$i : $i)), 'status' => ['pending', 'processing', 'done', 'failed'][$i % 4]],
            'digest' => hash('sha256', (string) $i), 'force' => $i % 3 === 0], range(1, $n));

        return [
            [Par2CandidateRecoveryResult::class, [[$attempts(12)], [$attempts(4)], [$attempts(50)], [$attempts(1)]],
                [[$attempts(2000)], [array_fill(0, 300, ['outcome' => str_repeat('r', 300), 'probes' => PHP_INT_MAX, 'articles' => PHP_INT_MAX])], ...$many(Par2CandidateRecoveryResult::class, 12)]],
            [ReleaseArticleFingerprintStore::class, [[$fingerprints(100)], [$fingerprints(10)], [$fingerprints(500)], [$fingerprints(1)]],
                [[$fingerprints(5000)], [array_fill(0, 300, str_repeat('Z', 300))], ...$many(ReleaseArticleFingerprintStore::class, 12)]],
            [RustArticleFingerprintWorker::class, [[$ids(200)], [$ids(50)], [$ids(1000)], [$ids(12)]],
                [[$ids(5000)], [[...$ids(100), self::oversized(), ...$ids(100)]], ...$many(RustArticleFingerprintWorker::class, 12)]],
            [CompactFingerprintCollector::class, [[$identities(12)], [$identities(4)], [$identities(24)], [$identities(1)]],
                [[$identities(500)], [array_fill(0, 200, ['requested' => str_repeat('<', 100).'x', 'header' => str_repeat(' ', 100).'<x>', 'response_text' => str_repeat('<>', 200).'<x>'])],
                    ...$many(CompactFingerprintCollector::class, 12)]],
            [DatabaseFingerprintQueue::class, [[$decisions(100)], [$decisions(10)], [$decisions(500)], [$decisions(1)]],
                [[$decisions(3000)], [array_fill(0, 300, ['job' => ['source_digest' => str_repeat('d', 64), 'status' => 'processing'], 'digest' => str_repeat('d', 64), 'force' => true])],
                    ...$many(DatabaseFingerprintQueue::class, 12)]],
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
