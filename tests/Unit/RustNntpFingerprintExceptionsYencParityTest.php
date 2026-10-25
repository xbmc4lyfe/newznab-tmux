<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Facades\Yenc;
use App\Services\NNTP\NntpArticleMissingException;
use App\Services\NNTP\NntpProviderUnavailableException;
use App\Services\NNTP\VerificationPipelineRetryException;
use App\Services\Nzb\NzbArticleFingerprintTransportFailure;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

final class RustNntpFingerprintExceptionsYencParityTest extends TestCase
{
    private const array CLASSES = [NntpArticleMissingException::class, NntpProviderUnavailableException::class, VerificationPipelineRetryException::class,
        NzbArticleFingerprintTransportFailure::class, Yenc::class];

    private const int SEED = 36022026;

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
            $this->assertSame(NntpArticleMissingException::FFI_CDEF, $class::FFI_CDEF);
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
    }

    public function test_php_helpers_classify_known_inputs(): void
    {
        $this->assertSame(['message_id' => 'abc@x', 'status' => 430, 'reason' => 'no_such_article', 'confirmed_missing' => true, 'attempts' => 0, 'retryable' => true],
            NntpArticleMissingException::classifyMissingArticlePhp(" <abc@x>\n", 430, -2));
        $this->assertSame(['message_id' => null, 'status' => 423, 'reason' => 'no_such_article_number', 'confirmed_missing' => true, 'attempts' => 3,
            'retryable' => false], NntpArticleMissingException::classifyMissingArticlePhp('<>', 423, 3));
        $this->assertSame(['<', 'unknown', false], array_values(array_intersect_key(NntpArticleMissingException::classifyMissingArticlePhp('<', 500, 0),
            ['message_id' => 0, 'reason' => 0, 'confirmed_missing' => 0])));

        $this->assertSame(['host' => 'news.example.com', 'status' => 0, 'kind' => 'connect', 'tls' => true, 'default_port' => 563, 'consecutive_failures' => 0,
            'circuit_open' => false, 'retryable' => true, 'backoff_seconds' => 5],
            NntpProviderUnavailableException::classifyProviderFailurePhp(' News.Example.COM.. ', 0, true, -1));
        $this->assertSame(['host' => null, 'status' => 481, 'kind' => 'auth', 'tls' => false, 'default_port' => 119, 'consecutive_failures' => 1,
            'circuit_open' => true, 'retryable' => false, 'backoff_seconds' => 0], NntpProviderUnavailableException::classifyProviderFailurePhp('...', 481, false, 1));
        $this->assertSame(['unavailable', true, 300], array_values(array_intersect_key(NntpProviderUnavailableException::classifyProviderFailurePhp(null, 503, false, 9),
            ['kind' => 0, 'circuit_open' => 0, 'backoff_seconds' => 0])));
        $this->assertSame('denied', NntpProviderUnavailableException::classifyProviderFailurePhp('h', 502, false, 0)['kind']);
        $this->assertSame('protocol', NntpProviderUnavailableException::classifyProviderFailurePhp('h', 200, false, 0)['kind']);

        $this->assertSame(['attempt' => 1, 'max_attempts' => 3, 'base_backoff_ms' => 250, 'remaining' => 2, 'exhausted' => false, 'backoff_ms' => 500,
            'reason' => 'Verification pipeline closed before this response was read.', 'reason_kind' => 'closed'],
            VerificationPipelineRetryException::summarizeRetryPlanPhp(1, 3, 250, ' Verification pipeline closed before this response was read. '));
        $this->assertSame(['attempt' => 5, 'max_attempts' => 1, 'base_backoff_ms' => 0, 'remaining' => 0, 'exhausted' => true, 'backoff_ms' => 0,
            'reason' => VerificationPipelineRetryException::DEFAULT_REASON, 'reason_kind' => 'unknown'], VerificationPipelineRetryException::summarizeRetryPlanPhp(5, 0, -4, null));
        $this->assertSame(300000, VerificationPipelineRetryException::summarizeRetryPlanPhp(30, 99, PHP_INT_MAX, 'x')['backoff_ms']);
        $this->assertSame('unavailable', VerificationPipelineRetryException::summarizeRetryPlanPhp(0, 1, 1, 'Primary verification connection is unavailable.')['reason_kind']);

        $this->assertSame(['message_id' => 'a@b', 'reason' => 'Fingerprint worker stopped during accumulation.', 'kind' => 'worker_stopped', 'bytes_read' => 10,
            'partial' => true, 'retryable' => true],
            NzbArticleFingerprintTransportFailure::classifyTransportFailurePhp('<a@b>', 'Fingerprint worker stopped during accumulation.', 10));
        $this->assertSame('incomplete', NzbArticleFingerprintTransportFailure::classifyTransportFailurePhp(null, 'Fingerprint worker returned an incomplete or invalid response.', 0)['kind']);
        $this->assertSame([null, null, 'io', 0, false], array_values(array_intersect_key(NzbArticleFingerprintTransportFailure::classifyTransportFailurePhp(" \t", '', -9),
            ['message_id' => 0, 'reason' => 0, 'kind' => 0, 'bytes_read' => 0, 'partial' => 0])));
        $this->assertFalse(NzbArticleFingerprintTransportFailure::classifyTransportFailurePhp(null, 'INVALID digest', 0)['retryable']);
        $this->assertSame('timeout', NzbArticleFingerprintTransportFailure::classifyTransportFailurePhp(null, 'read Timed Out', 0)['kind']);

        $this->assertSame(['driver' => 'RapidYenc', 'native' => true, 'known_driver' => true, 'max_bytes' => 16777216, 'payload_bytes' => 1000, 'action' => 'decode',
            'encoded_estimate' => 1036], Yenc::normalizeCodecCallPolicyPhp(' rapidYenc ', 1000));
        $this->assertSame(['driver' => 'PHP', 'native' => false, 'known_driver' => false, 'max_bytes' => 10, 'payload_bytes' => 11, 'action' => 'reject',
            'encoded_estimate' => 0], Yenc::normalizeCodecCallPolicyPhp(null, 11, 10));
        $this->assertSame([true, 16777216, 'skip'], array_values(array_intersect_key(Yenc::normalizeCodecCallPolicyPhp('PHP', -1, 0),
            ['known_driver' => 0, 'max_bytes' => 0, 'action' => 0])));
        $this->assertSame([1099511627776, 'reject'], array_values(array_intersect_key(Yenc::normalizeCodecCallPolicyPhp('x', PHP_INT_MAX, PHP_INT_MAX),
            ['max_bytes' => 0, 'action' => 0])));
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
        for ($i = 0; $i < 3000; $i++) {
            [$id, $status, $attempts] = self::missingArgs($random);
            $expected = NntpArticleMissingException::classifyMissingArticlePhp($id, $status, $attempts);
            $slots = self::raw(0, pack('q4', (int) ($id !== null), \strlen($id ?? ''), $status, $attempts).($id ?? ''), 7);
            $this->assertSame([$expected['message_id'], (int) $expected['confirmed_missing'], $expected['attempts'], (int) $expected['retryable']],
                [NntpArticleMissingException::nativeRange($id, $slots[0], $slots[1], $slots[2]), $slots[4], $slots[5], $slots[6]], bin2hex((string) $id));

            [$host, $status, $tls, $failures] = self::providerArgs($random);
            $this->assertSame(NntpProviderUnavailableException::classifyProviderFailurePhp($host, $status, $tls, $failures),
                NntpProviderUnavailableException::classifyProviderFailureNative($host, $status, $tls, $failures), bin2hex((string) $host));
            $this->assertCount(9, self::raw(1, pack('q5', (int) ($host !== null), \strlen($host ?? ''), $status, (int) $tls, $failures).($host ?? ''), 9));

            $retry = self::retryArgs($random);
            $this->assertSame(VerificationPipelineRetryException::summarizeRetryPlanPhp(...$retry), VerificationPipelineRetryException::summarizeRetryPlanNative(...$retry),
                serialize($retry));

            [$id, $reason, $bytes] = self::transportArgs($random);
            $expected = NzbArticleFingerprintTransportFailure::classifyTransportFailurePhp($id, $reason, $bytes);
            $slots = self::raw(3, pack('q5', (int) ($id !== null), \strlen($id ?? ''), (int) ($reason !== null), \strlen($reason ?? ''), $bytes).($id ?? '').($reason ?? ''), 10);
            $this->assertSame([$expected['message_id'], $expected['reason'], $expected['bytes_read'], (int) $expected['retryable']],
                [NntpArticleMissingException::nativeRange($id, $slots[0], $slots[1], $slots[2]), NntpArticleMissingException::nativeRange($reason, $slots[3], $slots[4], $slots[5]),
                    $slots[7], $slots[9]]);

            $codec = self::codecArgs($random);
            $this->assertSame(Yenc::normalizeCodecCallPolicyPhp(...$codec), Yenc::normalizeCodecCallPolicyNative(...$codec), serialize($codec));
        }
    }

    public function test_malformed_inputs_are_declined_and_fall_back_to_php(): void
    {
        $this->nativeFfi();
        foreach ([[5, pack('q5', 0, 0, 0, 0, 0)], [-1, pack('q4', 0, 0, 0, 0)], [99, pack('q4', 0, 0, 0, 0)], [0, ''], [0, pack('q3', 0, 0, 0)], [1, pack('q4', 0, 0, 0, 0)],
            [2, 'short'], [3, pack('q4', 0, 0, 0, 0)], [4, pack('q3', 0, 0, 0)], [0, pack('q4', 0, 1, 430, 0).'x'], [0, pack('q4', 2, 0, 430, 0)],
            [0, pack('q4', 1, 2, 430, 0).'x'], [0, pack('q4', 1, 1, 430, 0).'xy'], [0, pack('q4', 1, -1, 430, 0)], [0, pack('q4', 1, PHP_INT_MAX, 430, 0).'x'],
            [1, pack('q5', 0, 0, 0, 2, 0)], [1, pack('q5', -1, 0, 0, 0, 0)], [2, pack('q5', 0, 0, 0, 0, 1).'x'], [3, pack('q5', 0, 0, 1, 2, 0).'x'],
            [3, pack('q5', 1, 1, 1, 1, 0).'x'], [4, pack('q4', 1, 3, 0, 0).'ab']] as [$op, $input]) {
            $this->assertNull(NntpArticleMissingException::evaluateNative($op, $input), $op.' '.bin2hex($input));
        }
        $this->assertNull(NntpArticleMissingException::evaluateNative(0, str_repeat('x', 16777217)));
        $this->assertNull(NntpArticleMissingException::nativeSlots(null, 3));
        $this->assertNull(NntpArticleMissingException::nativeSlots(pack('q2', 1, 2), 3));
        $this->assertNull(NntpArticleMissingException::nativeRange('abc', 0, 0, 0));
        $this->assertSame('bc', NntpArticleMissingException::nativeRange('abc', 1, 1, 2));
        foreach ([[null, 1, 0, 1], ['abc', 1, 2, 2], ['abc', 1, -1, 1], ['abc', 1, 0, 0], ['abc', 0, 1, 0], ['abc', 2, 0, 1], ['abc', 1, PHP_INT_MAX, 1]] as $args) {
            $this->assertFalse(NntpArticleMissingException::nativeRange(...$args), serialize($args));
        }
        // Inputs past the native byte limit keep the PHP answer through every public entry point.
        $huge = str_repeat('a', 16777216);
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        $this->assertSame(NntpArticleMissingException::classifyMissingArticlePhp(" <{$huge}> ", 430, 1), NntpArticleMissingException::classifyMissingArticle(" <{$huge}> ", 430, 1));
        $this->assertSame(NntpProviderUnavailableException::classifyProviderFailurePhp("{$huge}.", 503, true, 4),
            NntpProviderUnavailableException::classifyProviderFailure("{$huge}.", 503, true, 4));
        $this->assertSame(VerificationPipelineRetryException::summarizeRetryPlanPhp(1, 2, 3, " {$huge}"), VerificationPipelineRetryException::summarizeRetryPlan(1, 2, 3, " {$huge}"));
        $this->assertSame(NzbArticleFingerprintTransportFailure::classifyTransportFailurePhp($huge, 'x', 1), NzbArticleFingerprintTransportFailure::classifyTransportFailure($huge, 'x', 1));
        $this->assertSame(Yenc::normalizeCodecCallPolicyPhp($huge, 1, 1), Yenc::normalizeCodecCallPolicy($huge, 1, 1));
    }

    public function test_abi_capacity_and_bad_ranges_preserve_sentinels(): void
    {
        $ffi = $this->nativeFfi();
        $vectors = [[0, pack('q4', 1, 9, 430, 1).' <a@b.c> '], [1, pack('q5', 1, 8, 481, 1, 2).'Host.Ex.'], [2, pack('q5', 2, 4, 100, 1, 6).'closed'],
            [3, pack('q5', 1, 3, 1, 7, 64).'<x>stopped'], [4, pack('q4', 1, 9, 4096, 0).'RapidYenc']];
        foreach ($vectors as [$op, $input]) {
            $buffer = $ffi->new('char[1024]');
            FFI::memset($buffer, 0x5A, 1024);
            // @phpstan-ignore method.notFound
            $length = $ffi->nntmux_w36_nntp_fingerprint_exceptions_yenc($op, $input, \strlen($input), $buffer, 1024);
            $this->assertGreaterThan(0, $length);
            $wire = FFI::string($buffer, $length);
            $this->assertSame(NntpArticleMissingException::evaluateNative($op, $input), $wire);
            $small = $ffi->new('char['.($length + 2).']');
            foreach (array_unique([0, 1, $length - 1, $length, $length + 1]) as $capacity) {
                FFI::memset($small, 0x5A, $length + 2);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w36_nntp_fingerprint_exceptions_yenc($op, $input, \strlen($input), $small, $capacity);
                $this->assertSame($capacity < $length ? -$length : $length, $written, $op.' capacity '.$capacity);
                $this->assertSame($capacity < $length ? str_repeat('Z', $length + 2) : $wire.'ZZ', FFI::string($small, $length + 2));
            }
        }
        $this->assertSame([1, 2, 5, 0, 1, 1, 1], self::raw(0, $vectors[0][1], 7));
        $this->assertSame([1, 1, 16777216, 4096, 1, 4096 + 81 + 64], self::raw(4, $vectors[4][1], 6));
        $buffer = $ffi->new('char[1024]');
        $input = pack('q4', 0, 0, 430, 1);
        foreach ([[null, 0, $buffer, 1024], [null, 32, $buffer, 1024], [$input, 32, null, 0], [$input, 32, null, 64], [$input, 16777217, $buffer, 1024],
            [$input, 32, $buffer, 16777217], [$buffer, 32, $buffer, 1024], [FFI::addr($buffer[8]), 32, $buffer, 1024], [$buffer, 32, FFI::addr($buffer[8]), 1016]] as [$in, $length, $out, $capacity]) {
            FFI::memset($buffer, 0, 32);
            FFI::memset(FFI::addr($buffer[32]), 0x5A, 992);
            // @phpstan-ignore method.notFound
            $this->assertSame(-1, $ffi->nntmux_w36_nntp_fingerprint_exceptions_yenc(0, $in, $length, $out, $capacity));
            $this->assertSame(str_repeat("\0", 32).str_repeat('Z', 992), FFI::string($buffer, 1024));
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_w36_nntp_fingerprint_exceptions_yenc(99, $input, 32, $buffer, 1024));
    }

    public function test_missing_library_falls_back_in_fresh_process(): void
    {
        $this->nativeFfi();
        $code = <<<'CHILD'
            require $argv[1].'/vendor/autoload.php';
            $vectors = unserialize(base64_decode($argv[2]), ['allowed_classes' => false]);
            if (App\Services\NNTP\NntpArticleMissingException::evaluateNative(0, pack('q4', 0, 0, 430, 1)) !== null) {
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
        $missing = '/tmp/w36-2-missing-'.getmypid().'.dylib';
        $this->assertFileDoesNotExist($missing);
        $process = new Process([PHP_BINARY, '-d', 'ffi.enable=true', '-r', $code, base_path(), base64_encode(serialize(self::workloads()))], base_path(),
            ['NNTMUX_PIPELINE_FFI_LIB' => $missing]);
        $process->mustRun();
        $this->assertSame('fallback-ok', $process->getOutput());
    }

    public function test_paired_randomized_benchmarks_keep_php_default_below_threshold(): void
    {
        $this->nativeFfi();
        $path = '/tmp/wave36-2-nntp-fingerprint-exceptions-yenc-'.gmdate('Ymd-His').'-'.getmypid().'.json';
        $rounds = 3;
        $pairs = 21;
        $calls = 100;
        $report = [
            'protocol' => ['seed' => self::SEED, 'rounds' => $rounds, 'pairs_per_round' => $pairs, 'calls_per_side_per_pair' => $calls,
                'warmup_per_side_per_round' => 64, 'clock' => 'hrtime(true), individual ns', 'p99' => 'nearest rank',
                'order' => 'Random side order, identical randomized input indices per pair; toggle and parity outside timing.',
                'gate' => 'Each common/uncommon mean AND p99 speedup >=1.5 in every round; no averaging across helpers.',
                'scope' => 'Public helper dispatch including packing, FFI, allocation and unpacking. Excludes app boot and I/O.',
                'reproduce' => 'CARGO_TARGET_DIR=/tmp/cargo-w36-2 cargo build --release --manifest-path rust/pipeline-ffi/Cargo.toml && NNTMUX_PIPELINE_FFI_LIB=/tmp/cargo-w36-2/release/libnntmux_pipeline.dylib php artisan test --compact tests/Unit/RustNntpFingerprintExceptionsYencParityTest.php'],
            'started_at_utc' => gmdate(DATE_ATOM),
            'head' => self::command(['git', 'rev-parse', 'HEAD']), 'status' => self::command(['git', 'status', '--short']),
            'host' => php_uname(), 'hardware' => self::command(PHP_OS_FAMILY === 'Darwin' ? ['sysctl', '-n', 'machdep.cpu.brand_string', 'hw.model', 'hw.ncpu', 'hw.memsize'] : ['lscpu']),
            'php' => ['version' => PHP_VERSION, 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')],
            'profile' => 'opt-level=3, lto=true, codegen-units=1', 'source_sha256' => [], 'results' => [], 'failures' => [],
        ];
        foreach (['rust/pipeline-ffi/src/nfo.rs', 'app/Support/RustPipelineLibrary.php', 'app/Services/NNTP/NntpArticleMissingException.php',
            'app/Services/NNTP/NntpProviderUnavailableException.php', 'app/Services/NNTP/VerificationPipelineRetryException.php',
            'app/Services/Nzb/NzbArticleFingerprintTransportFailure.php', 'app/Facades/Yenc.php', 'tests/Unit/RustNntpFingerprintExceptionsYencParityTest.php'] as $file) {
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
            fwrite(STDERR, "\nWave 36.2 raw benchmark evidence: {$path}\n".implode("\n", $summary)."\n");
        }
        $this->assertFileExists($path);
    }

    private function nativeFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('nfo', NntpArticleMissingException::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
        $this->assertNotNull($ffi, 'The optimized library must load; native tests must not silently skip.');

        return $ffi;
    }

    /** @return list<array{class-string, string, list<list<mixed>>, list<list<mixed>>}> */
    private static function workloads(): array
    {
        $random = new Randomizer(new Mt19937(self::SEED + 7));
        $common = static fn (callable $make, int $count): array => array_map(static fn (): array => $make(), range(1, $count));
        $long = str_repeat('é<> .', 4000);

        return [
            [NntpArticleMissingException::class, 'classifyMissingArticle',
                [['<part1of10.abc@news.example>', 430, 0], ['part2of10.abc@news.example', 430, 1], ['<x@y>', 423, 2], [null, 430, 0]],
                [[" <{$long}> ", 430, 1], ['<>', PHP_INT_MIN, PHP_INT_MIN], ["\0\t", PHP_INT_MAX, PHP_INT_MAX], ['<', 500, 3],
                    ...$common(static fn (): array => self::missingArgs($random), 6)]],
            [NntpProviderUnavailableException::class, 'classifyProviderFailure',
                [['news.example.com', 0, true, 0], ['news.example.com', 481, true, 1], ['eu.news.example.com', 503, false, 2], [null, 0, false, 0]],
                [[" {$long}. ", 502, true, PHP_INT_MAX], ['...', PHP_INT_MIN, false, PHP_INT_MIN], ['H', 200, true, 7], ["\xff.", 400, false, 3],
                    ...$common(static fn (): array => self::providerArgs($random), 6)]],
            [VerificationPipelineRetryException::class, 'summarizeRetryPlan',
                [[0, 2, 250, 'Primary verification connection is unavailable.'], [1, 2, 250, 'Verification pipeline closed before this response was read.'],
                    [0, 1, 100, 'Raw verification connection is unavailable.'], [2, 2, 250, null]],
                [[PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, $long.'ENDED'], [PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, ''], [30, 99, 60000, " \n"], [1, 3, 7, "\xffInterrupted"],
                    ...$common(static fn (): array => self::retryArgs($random), 6)]],
            [NzbArticleFingerprintTransportFailure::class, 'classifyTransportFailure',
                [['<a@b>', 'Fingerprint worker stopped during accumulation.', 0], [null, 'Fingerprint worker request failed.', 0],
                    ['x@y', 'Fingerprint worker returned an incomplete or invalid response.', 512], [null, 'Fingerprint worker returned an incomplete digest.', 4096]],
                [[" <{$long}> ", $long.'TIMEOUT', PHP_INT_MAX], [null, null, PHP_INT_MIN], ['<>', " \t", 0], ["\0", 'Invalid', 1],
                    ...$common(static fn (): array => self::transportArgs($random), 6)]],
            [Yenc::class, 'normalizeCodecCallPolicy',
                [['RapidYenc', 768000, 16777216], ['PHP', 768000, 16777216], ['RapidYenc', 384000, 16777216], [null, 4096, 16777216]],
                [[" {$long} ", PHP_INT_MAX, PHP_INT_MAX], ['', PHP_INT_MIN, PHP_INT_MIN], [' NATIVE ', 1099511627776, PHP_INT_MAX], ['rapid_yenc', 17000000, 0],
                    ...$common(static fn (): array => self::codecArgs($random), 6)]],
        ];
    }

    private static function text(Randomizer $random): string
    {
        $pieces = ['<', '>', '@', '.', '..', ' ', "\t", "\n", "\0", "\x0b", "\x0c", 'a', 'é', "\xff", 'news', 'Example', 'unavailable', 'CLOSED', 'interrupted',
            'ended', 'stopped', 'Incomplete', 'invalid', 'request failed', 'timed out', 'TimeOut', 'rapidyenc', 'RapidYenc', 'rapid_yenc', 'native', 'php', 'PHP'];
        $out = '';
        for ($i = $random->getInt(0, 6); $i > 0; $i--) {
            $out .= $pieces[$random->getInt(0, \count($pieces) - 1)];
        }

        return $out;
    }

    private static function optionalText(Randomizer $random): ?string
    {
        return $random->getInt(0, 4) === 0 ? null : self::text($random);
    }

    private static function int(Randomizer $random): int
    {
        return match ($random->getInt(0, 6)) {
            0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            1 => $random->getInt(-3, 25),
            2 => $random->getInt(395, 505),
            3 => $random->getInt(1099511627770, 1099511627780),
            4 => $random->getInt(16777210, 16777220),
            5 => $random->getInt(59990, 60010),
            default => $random->getInt(0, 1000000),
        };
    }

    /** @return array{?string, int, int} */
    private static function missingArgs(Randomizer $random): array
    {
        return [self::optionalText($random), [430, 423, self::int($random)][$random->getInt(0, 2)], self::int($random)];
    }

    /** @return array{?string, int, bool, int} */
    private static function providerArgs(Randomizer $random): array
    {
        return [self::optionalText($random), [0, 480, 481, 482, 502, 503, 400, self::int($random)][$random->getInt(0, 7)], $random->getInt(0, 1) === 1, self::int($random)];
    }

    /** @return array{int, int, int, ?string} */
    private static function retryArgs(Randomizer $random): array
    {
        return [self::int($random), self::int($random), self::int($random), self::optionalText($random)];
    }

    /** @return array{?string, ?string, int} */
    private static function transportArgs(Randomizer $random): array
    {
        return [self::optionalText($random), self::optionalText($random), self::int($random)];
    }

    /** @return array{?string, int, int} */
    private static function codecArgs(Randomizer $random): array
    {
        return [self::optionalText($random), self::int($random), self::int($random)];
    }

    /** @return list<int> */
    private static function raw(int $operation, string $input, int $slots): array
    {
        $values = NntpArticleMissingException::nativeSlots(NntpArticleMissingException::evaluateNative($operation, $input), $slots);
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
