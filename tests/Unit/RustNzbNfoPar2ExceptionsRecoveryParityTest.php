<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Par2RenameOutcome;
use App\Exceptions\NfoPayloadRejected;
use App\Exceptions\NzbCustodyCollision;
use App\Exceptions\NzbPasswordMetadataRejected;
use App\Exceptions\NzbUploadException;
use App\Services\Nzb\Par2CandidateRecoveryResult;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNzbNfoPar2ExceptionsRecoveryParityTest extends TestCase
{
    private const array CLASSES = [NfoPayloadRejected::class, NzbCustodyCollision::class, NzbPasswordMetadataRejected::class, NzbUploadException::class,
        Par2CandidateRecoveryResult::class];

    private const string CAPTURED = 'Captured companion is empty or exceeds the NFO size limit';

    private const string STRUCTURE = 'Invalid NZB password metadata structure.';

    private const string LIMIT = 'NZB password metadata exceeds the supported limit.';

    private const int SEED = 35022026;

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_defaults_nullable_toggles_are_independent(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $this->assertSame(NfoPayloadRejected::FFI_CDEF, $class::FFI_CDEF);
        }
        foreach (self::CLASSES as $selected) {
            $selected::setUseRustFfi(true);
            foreach (self::CLASSES as $class) {
                $this->assertSame($selected === $class, $class::usesRustFfi());
            }
            $selected::setUseRustFfi(false);
            $this->assertFalse($selected::usesRustFfi());
            $selected::setUseRustFfi(null);
            $this->assertFalse($selected::usesRustFfi());
        }
        $result = new ReflectionClass(Par2CandidateRecoveryResult::class);
        $this->assertFalse($result->isReadOnly());
        foreach (['outcome', 'probes', 'articles'] as $property) {
            $this->assertTrue($result->getProperty($property)->isReadOnly(), $property);
        }
    }

    public function test_php_helpers_classify_the_thrown_messages(): void
    {
        $this->assertSame(['kind' => 'empty', 'failure_kind' => 'invalid_payload', 'payload_bytes' => 0, 'within_limit' => false, 'terminal' => true],
            (new NfoPayloadRejected(self::CAPTURED))->classification());
        $this->assertSame('oversize', (new NfoPayloadRejected(self::CAPTURED))->classification(65536)['kind']);
        $this->assertSame('oversize', NfoPayloadRejected::classifyRejectionReasonPhp(self::CAPTURED, 40)['kind']);
        $this->assertSame(['encoding', true], array_values(array_intersect_key(NfoPayloadRejected::classifyRejectionReasonPhp('Bad ENCODING', 65535),
            ['kind' => 0, 'within_limit' => 0])));
        $this->assertSame('invalid', NfoPayloadRejected::classifyRejectionReasonPhp('nope', -4)['kind']);

        $this->assertSame(['kind' => 'structure', 'value_length' => 0, 'over_limit' => false, 'retryable' => false],
            (new NzbPasswordMetadataRejected(self::STRUCTURE))->classification());
        $this->assertSame(['kind' => 'limit', 'value_length' => 4097, 'over_limit' => true, 'retryable' => false],
            (new NzbPasswordMetadataRejected(self::LIMIT))->classification(4097));
        $this->assertSame('limit', NzbPasswordMetadataRejected::classifyPasswordRejectionPhp('other', 5000)['kind']);
        $this->assertSame('unknown', NzbPasswordMetadataRejected::classifyPasswordRejectionPhp('other', 4096)['kind']);

        $this->assertSame(['status' => 409, 'category' => 'conflict', 'retryable' => false, 'message' => 'A staged file named a.nzb already exists'],
            (new NzbUploadException('A staged file named a.nzb already exists', 409))->failure());
        $this->assertSame(['status' => 400, 'category' => 'client', 'retryable' => false, 'message' => 'Invalid NZB payload'],
            NzbUploadException::normalizeUploadFailurePhp(" Invalid NZB payload\n", 400));
        $this->assertSame(['status' => 500, 'category' => 'server', 'retryable' => true, 'message' => NzbUploadException::DEFAULT_MESSAGE],
            NzbUploadException::normalizeUploadFailurePhp("\0 \t", 200));

        $collision = new NzbCustodyCollision(' 01J9ATTEMPT ', ' rescued/a.nzb ', '/var/private/custody-1/');
        $this->assertSame('A replacement input entered private custody; retained for explicit restaging.', $collision->getMessage());
        $this->assertSame(['attempt' => '01J9ATTEMPT', 'recovered_filename' => 'a.nzb', 'private_directory' => 'custody-1', 'recoverable' => true,
            'restage_required' => true], $collision->custodyRecord());
        $this->assertSame(['attempt' => 'x', 'recovered_filename' => null, 'private_directory' => '', 'recoverable' => false, 'restage_required' => true],
            NzbCustodyCollision::normalizeCustodyRecordPhp('x', 'dir/', '///'));

        $result = new Par2CandidateRecoveryResult(Par2RenameOutcome::Retryable, 3, 10);
        $this->assertSame(['outcome' => 'retryable', 'terminal' => false, 'retry' => true, 'probes' => 3, 'articles' => 10, 'total_reads' => 13,
            'articles_per_probe' => 3], $result->metrics());
        $this->assertSame(['outcome' => null, 'terminal' => false, 'retry' => false, 'probes' => PHP_INT_MAX, 'articles' => PHP_INT_MAX,
            'total_reads' => PHP_INT_MAX, 'articles_per_probe' => 1], Par2CandidateRecoveryResult::summarizeRecoveryMetricsPhp('Renamed', PHP_INT_MAX, PHP_INT_MAX));
    }

    public function test_instance_helpers_dispatch_identically_with_rust_forced(): void
    {
        $this->nativeFfi();
        $instances = [
            [NfoPayloadRejected::class, static fn (): array => (new NfoPayloadRejected(self::CAPTURED))->classification(70000)],
            [NzbPasswordMetadataRejected::class, static fn (): array => (new NzbPasswordMetadataRejected(self::LIMIT))->classification(9)],
            [NzbUploadException::class, static fn (): array => (new NzbUploadException(' Failed to publish staged upload ', 500))->failure()],
            [NzbCustodyCollision::class, static fn (): array => (new NzbCustodyCollision("a\n", null, 'x/custody'))->custodyRecord()],
            [Par2CandidateRecoveryResult::class, static fn (): array => (new Par2CandidateRecoveryResult(Par2RenameOutcome::NoMatch, 0, 4))->metrics()],
        ];
        foreach ($instances as [$class, $call]) {
            $expected = $call();
            $class::setUseRustFfi(true);
            $this->assertSame($expected, $call(), $class);
            $class::setUseRustFfi(null);
        }
    }

    public function test_native_helpers_and_public_dispatch_have_exact_parity(): void
    {
        $this->nativeFfi();
        foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
            $php = $method.'Php';
            $native = $method.'Native';
            foreach ([...$common, ...$uncommon] as $args) {
                $expected = serialize($class::$php(...$args));
                $this->assertSame($expected, serialize($class::$native(...$args)), $class.'::'.$native.' '.bin2hex(serialize($args)));
                $class::setUseRustFfi(true);
                $forced = serialize($class::$method(...$args));
                $class::setUseRustFfi(false);
                $default = serialize($class::$method(...$args));
                $this->assertSame([$expected, $expected], [$forced, $default], $class.'::'.$method);
            }
        }
    }

    public function test_seeded_random_inputs_match_php_and_rust_wire_answers_directly(): void
    {
        $this->nativeFfi();
        $random = new Randomizer(new Mt19937(self::SEED + 1));
        $kinds = ['empty', 'oversize', 'encoding', 'invalid'];
        $passwordKinds = ['structure', 'limit', 'unknown'];
        $categories = ['client', 'conflict', 'server'];
        for ($i = 0; $i < 3000; $i++) {
            [$reason, $bytes] = self::nfoArgs($random);
            $expected = NfoPayloadRejected::classifyRejectionReasonPhp($reason, $bytes);
            $this->assertSame([array_search($expected['kind'], $kinds, true), $expected['payload_bytes'], (int) $expected['within_limit']],
                self::raw(0, pack('q', $bytes).$reason, 3), bin2hex($reason));

            [$attempt, $recovered, $directory] = self::custodyArgs($random);
            $expected = NzbCustodyCollision::normalizeCustodyRecordPhp($attempt, $recovered, $directory);
            $this->assertSame($expected, NzbCustodyCollision::normalizeCustodyRecordNative($attempt, $recovered, $directory),
                bin2hex(serialize([$attempt, $recovered, $directory])));
            $slots = self::raw(1, pack('q4', \strlen($attempt), (int) ($recovered !== null), \strlen($recovered ?? ''), \strlen($directory))
                .$attempt.($recovered ?? '').$directory, 8);
            $this->assertSame([$expected['attempt'], $expected['recovered_filename'] !== null, $expected['private_directory'], (int) $expected['recoverable']],
                [substr($attempt, $slots[0], $slots[1]), $slots[2] === 1, substr($directory, $slots[5], $slots[6]), $slots[7]]);

            [$reason, $length] = self::passwordArgs($random);
            $expected = NzbPasswordMetadataRejected::classifyPasswordRejectionPhp($reason, $length);
            $this->assertSame([array_search($expected['kind'], $passwordKinds, true), $expected['value_length'], (int) $expected['over_limit']],
                self::raw(2, pack('q', $length).$reason, 3));

            [$message, $status] = self::uploadArgs($random);
            $expected = NzbUploadException::normalizeUploadFailurePhp($message, $status);
            $slots = self::raw(3, pack('q', $status).$message, 5);
            $this->assertSame([$expected['status'], array_search($expected['category'], $categories, true), (int) $expected['retryable']], \array_slice($slots, 0, 3));
            $this->assertSame($expected['message'], $slots[4] === 0 ? NzbUploadException::DEFAULT_MESSAGE : substr($message, $slots[3], $slots[4]));

            $recovery = self::recoveryArgs($random);
            $this->assertSame(Par2CandidateRecoveryResult::summarizeRecoveryMetricsPhp(...$recovery),
                Par2CandidateRecoveryResult::summarizeRecoveryMetricsNative(...$recovery), serialize($recovery));
        }
    }

    public function test_malformed_inputs_are_declined_and_fall_back_to_php(): void
    {
        $this->nativeFfi();
        foreach ([[5, pack('q2', 1, 1)], [-1, pack('q', 1)], [99, pack('q', 1)], [0, ''], [0, 'short'], [2, pack('l', 1)], [3, "\x01\x02"],
            [1, pack('q3', 0, 0, 0)], [1, pack('q4', 0, 2, 0, 0)], [1, pack('q4', 0, 0, 1, 0).'x'], [1, pack('q4', -1, 0, 0, 0)],
            [1, pack('q4', 1, 0, 0, 0).'ab'], [1, pack('q4', 3, 0, 0, 0).'ab'], [1, pack('q4', PHP_INT_MAX, 1, PHP_INT_MAX, 0)], [4, pack('q', 1)],
            [4, 'abcdefghijklmno']] as [$op, $input]) {
            $this->assertNull(NfoPayloadRejected::evaluateNative($op, $input), $op.' '.bin2hex($input));
        }
        $this->assertNull(NfoPayloadRejected::evaluateNative(0, str_repeat('x', 16777217)));
        $this->assertNull(NfoPayloadRejected::nativeSlots(null, 3));
        $this->assertNull(NfoPayloadRejected::nativeSlots(pack('q2', 1, 2), 3));
        // Inputs past the native byte limit keep the PHP answer through every public entry point.
        $huge = str_repeat('a', 16777216);
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        $this->assertSame(NfoPayloadRejected::classifyRejectionReasonPhp($huge, 1), NfoPayloadRejected::classifyRejectionReason($huge, 1));
        $this->assertSame(NzbPasswordMetadataRejected::classifyPasswordRejectionPhp($huge, 1), NzbPasswordMetadataRejected::classifyPasswordRejection($huge, 1));
        $this->assertSame(NzbUploadException::normalizeUploadFailurePhp(" {$huge} ", 409), NzbUploadException::normalizeUploadFailure(" {$huge} ", 409));
        $this->assertSame(NzbCustodyCollision::normalizeCustodyRecordPhp($huge, $huge, 'd'), NzbCustodyCollision::normalizeCustodyRecord($huge, $huge, 'd'));
        $this->assertSame(Par2CandidateRecoveryResult::summarizeRecoveryMetricsPhp($huge, 1, 1), Par2CandidateRecoveryResult::summarizeRecoveryMetrics($huge, 1, 1));
    }

    public function test_abi_capacity_and_bad_ranges_preserve_sentinels(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, pack('q', 12).self::CAPTURED], [1, pack('q4', 2, 1, 7, 6).'a x/b.nzb/p/q/ '], [2, pack('q', 4097).self::LIMIT],
            [3, pack('q', 409).' dup '], [4, pack('q2', 3, 10).'retryable']];
        foreach ($vectors as [$op, $input]) {
            $buffer = $ffi->new('char[1024]');
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $length = $ffi->nntmux_w35_nzb_nfo_par2_exceptions_recovery($op, $input, \strlen($input), $buffer, 1024);
            $this->assertGreaterThan(0, $length);
            $wire = FFI::string($buffer, $length);
            $this->assertSame(NfoPayloadRejected::evaluateNative($op, $input), $wire);
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w35_nzb_nfo_par2_exceptions_recovery($op, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2));
            }
        }
        $this->assertSame([0, 1, 1, 2, 5, 3, 1, 1], self::raw(1, $vectors[1][1], 8));
        $buffer = $ffi->new('char[1024]');
        $input = pack('q2', 1, 1).'renamed';
        foreach ([[null, 0, $buffer, 1024], [null, 23, $buffer, 1024], [$input, 23, null, 0], [$input, 23, null, 64], [$input, 16777217, $buffer, 1024],
            [$input, 23, $buffer, 16777217], [$buffer, 16, $buffer, 1024], [FFI::addr($buffer[8]), 16, $buffer, 1024], [$buffer, 16, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 16);
            FFI::memset(FFI::addr($buffer[16]), 0x5A, 1008);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w35_nzb_nfo_par2_exceptions_recovery(4, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 16).str_repeat('Z', 1008), FFI::string($buffer, 1024));
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w35_nzb_nfo_par2_exceptions_recovery(99, $input, 23, $buffer, 1024));
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $vectors = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Exceptions\NfoPayloadRejected::evaluateNative(4, pack('q2', 1, 1).'renamed') !== null) {
                throw new RuntimeException('Missing explicit library unexpectedly loaded.');
            }
            foreach ($vectors as [$class, $method, $common, $uncommon]) {
                $php = $method.'Php';
                $native = $method.'Native';
                $class::setUseRustFfi(true);
                foreach ([...$common, ...$uncommon] as $args) {
                    $expected = serialize($class::$php(...$args));
                    if ($expected !== serialize($class::$native(...$args)) || $expected !== serialize($class::$method(...$args))) {
                        throw new RuntimeException($class.'::'.$method.' fallback mismatch');
                    }
                }
            }
            echo 'fallback-ok';
            CHILD;
        $missing = '/tmp/w35-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize(self::workloads()))], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave35-2-nzb-nfo-par2-exceptions-recovery-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'CARGO_TARGET_DIR=/tmp/cargo-w35-2 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w35-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustNzbNfoPar2ExceptionsRecoveryParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Exceptions/NfoPayloadRejected.php', 'app/Exceptions/NzbCustodyCollision.php',
            'app/Exceptions/NzbPasswordMetadataRejected.php', 'app/Exceptions/NzbUploadException.php', 'app/Services/Nzb/Par2CandidateRecoveryResult.php',
            'tests/Unit/RustNzbNfoPar2ExceptionsRecoveryParityTest.php'] as $file) {
            $report['source_sha256'][$file] = hash_file('sha256', base_path($file));
        }
        $library = getenv('NNTMUX_PIPELINE_FFI_LIB') ?: base_path('rust/pipeline-ffi/target/release/libnntmux_pipeline.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'));
        $report['library'] = ['path' => realpath($library), 'sha256' => hash_file('sha256', $library)];
        $random = new Randomizer(new Mt19937(self::SEED));
        try {
            foreach (self::workloads() as [$class, $method, $common, $uncommon]) {
                $name = $class.'::'.$method;
                $php = $method.'Php';
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $inputs) {
                    $expected = [];
                    foreach ($inputs as $args) {
                        $expected[] = serialize($class::$php(...$args));
                        $class::setUseRustFfi(true);
                        $this->assertSame(end($expected), serialize($class::$method(...$args)), $name.' preflight');
                    }
                    $cell = ['input_sha256' => hash('sha256', serialize($inputs)), 'rounds' => []];
                    for ($round = 0; $round < $rounds; $round++) {
                        foreach ([false, true] as $enabled) {
                            $class::setUseRustFfi($enabled);
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
                                $class::setUseRustFfi($side === 'native');
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
                $class::setUseRustFfi(null);
                if (! $qualifies) {
                    $this->assertFalse($class::usesRustFfi(), $name.' must keep PHP as the default below 1.5x');
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
            fwrite(STDERR, "\nWave 35.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', NfoPayloadRejected::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 7));
        $common = static fn (callable $make, int $count): array => array_map(static fn (): array => $make(), range(1, $count));
        $long = str_repeat('é/ ', 4000);

        return [
            [NfoPayloadRejected::class, 'classifyRejectionReason',
                [[self::CAPTURED, 0], [self::CAPTURED, 70000], [self::CAPTURED, 512], ['Captured companion encoding is invalid', 900]],
                [[$long.'EMPTY', 0], ['', PHP_INT_MIN], ['', PHP_INT_MAX], ["\0\xff SIZE LIMIT", 65535], ...$common(static fn (): array => self::nfoArgs($random), 6)]],
            [NzbCustodyCollision::class, 'normalizeCustodyRecord',
                [['01J9ZK6S3W7Q', 'release.nzb', 'custody-01J9ZK6S3W7Q'], ['01J9ZK6S3W7Q', null, 'custody-a'], ['a1', 'x.nzb', 'p']],
                [[" \t", '/', '/'], [$long, $long, $long], ["\0a\0", ' dir/sub/file.nzb ', " /x/y// \n"], ...$common(static fn (): array => self::custodyArgs($random), 6)]],
            [NzbPasswordMetadataRejected::class, 'classifyPasswordRejection',
                [[self::STRUCTURE, 0], [self::LIMIT, 4097], [self::LIMIT, 12]],
                [[$long.'STRUCTURE', 0], ['', PHP_INT_MIN], ['other', PHP_INT_MAX], ...$common(static fn (): array => self::passwordArgs($random), 6)]],
            [NzbUploadException::class, 'normalizeUploadFailure',
                [['Invalid NZB payload', 400], ['A staged file named a.nzb already exists', 409], ['Failed to publish staged upload', 500], ['Unsafe nzb filename', 400]],
                [[" \0\t\n", 200], [$long, PHP_INT_MIN], [' x ', PHP_INT_MAX], ["\xff", 599], ...$common(static fn (): array => self::uploadArgs($random), 6)]],
            [Par2CandidateRecoveryResult::class, 'summarizeRecoveryMetrics',
                [['renamed', 2, 9], ['deferred', 1, 0], ['retryable', 3, 30], ['no_match', 4, 4]],
                [['', 0, 0], ['Renamed', -1, -1], ['deferred', PHP_INT_MAX, PHP_INT_MAX], [$long, 1, PHP_INT_MIN], ...$common(static fn (): array => self::recoveryArgs($random), 6)]],
        ];
    }

    private static function text(Randomizer $random): string
    {
        $pieces = ['empty', 'EMPTY', 'exceeds', 'size limit', 'Size Limit', 'encoding', 'structure', 'limit', 'Limit', '/', '//', ' ', "\t", "\n", "\0", "\x0b",
            "\x0c", 'a', 'é', "\xff", 'x.nzb', '01J9', 'custody'];
        $out = '';
        for ($i = $random->getInt(0, 8); $i > 0; $i--) {
            $out .= $pieces[$random->getInt(0, \count($pieces) - 1)];
        }

        return $out;
    }

    private static function int(Randomizer $random): int
    {
        return match ($random->getInt(0, 5)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => $random->getInt(-3, 3),
            2 => $random->getInt(65530, 65540),
            3 => $random->getInt(4090, 4100),
            4 => $random->getInt(395, 605),
            default => $random->getInt(0, 100000),
        };
    }

    /** @return array{string, int} */
    private static function nfoArgs(Randomizer $random): array
    {
        return [self::text($random), self::int($random)];
    }

    /** @return array{string, ?string, string} */
    private static function custodyArgs(Randomizer $random): array
    {
        return [self::text($random), $random->getInt(0, 3) === 0 ? null : self::text($random), self::text($random)];
    }

    /** @return array{string, int} */
    private static function passwordArgs(Randomizer $random): array
    {
        return [self::text($random), self::int($random)];
    }

    /** @return array{string, int} */
    private static function uploadArgs(Randomizer $random): array
    {
        return [self::text($random), self::int($random)];
    }

    /** @return array{string, int, int} */
    private static function recoveryArgs(Randomizer $random): array
    {
        $outcomes = ['renamed', 'no_match', 'retryable', 'deferred', '', 'RENAMED', 'renamed ', self::text($random)];

        return [$outcomes[$random->getInt(0, \count($outcomes) - 1)], self::int($random), self::int($random)];
    }

    /** @return list<int> */
    private static function raw(int $operation, string $input, int $slots): array
    {
        $values = NfoPayloadRejected::nativeSlots(NfoPayloadRejected::evaluateNative($operation, $input), $slots);
        if ($values === null) {
            throw new RuntimeException('Rust declined operation '.$operation.': '.bin2hex($input));
        }

        return $values;
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
