<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\MatchPrefiles;
use App\Models\IngestionConfiguration;
use App\Models\MetadataConfiguration;
use App\Models\PostProcessingConfiguration;
use App\Models\ProcessingRuntimeState;
use App\Models\RegistrationConfiguration;
use App\Models\SiteConfiguration;
use App\Models\TmuxConfiguration;
use App\Services\Nzb\NzbArticleFingerprintUnavailable;
use App\Services\Releases\Verification\CandidateCapacityException;
use App\Services\Releases\Verification\PartialPublicationDeferredException;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class RustVerificationCapacityPrefileSingletonParityTest extends TestCase
{
    /** helper => [class, public dispatcher, FFI cache property] */
    private const HELPERS = [
        'fingerprint' => [NzbArticleFingerprintUnavailable::class, 'classifyUnavailability', 'ffi'],
        'capacity' => [CandidateCapacityException::class, 'summarizeCapacity', 'ffi'],
        'partial' => [PartialPublicationDeferredException::class, 'summarizePublicationPolicy', 'ffi'],
        'prefile' => [MatchPrefiles::class, 'normalizeArguments', 'ffi'],
        'singleton' => [SiteConfiguration::class, 'summarizeSingletonIds', 'singletonFfi'],
    ];

    /** @var list<class-string> */
    private const SINGLETON_MODELS = [
        IngestionConfiguration::class,
        MetadataConfiguration::class,
        PostProcessingConfiguration::class,
        ProcessingRuntimeState::class,
        RegistrationConfiguration::class,
        SiteConfiguration::class,
        TmuxConfiguration::class,
    ];

    /** Integers around every clamp, limit and overflow boundary the helpers use. */
    private const INTS = [
        PHP_INT_MIN, PHP_INT_MIN + 1, -4294967296, -1, 0, 1, 2, 3, 8, 9, 10, 11, 49, 50, 99, 100, 101, 1000, 1001,
        299_999, 300_000, 4294967296, 1099511627775, 1099511627776, 1099511627777, PHP_INT_MAX - 1, PHP_INT_MAX,
    ];

    /** Strings that exercise trimming, brackets, keyword matching and binary bytes. */
    private const TEXTS = [
        '', ' ', "\0\x0B\t\r\n", "\x0C", '<>', '< >', '<abc@news.example>', ' <abc@news.example> ', '<<a@b>>', 'abc@news',
        'Fingerprint DISABLED', 'unsupported codec', 'Not Supported', 'article missing', 'NOT FOUND', '430 no such article',
        'read timed out', 'TIMEOUT', 'disabled timeout', 'timeout missing', 'misSING', 'tim eout', 'Ünïcödé', "bad \xff",
        "nul\0byte",
    ];

    /** Limit arguments around PHP's is_numeric() grammar and the "full" keyword. */
    private const LIMITS = [
        'full', 'Full', 'FULL', ' full', 'full ', 'ful', '', ' ', '0', '1', '-1', '+1', '25', ' 25', '25 ', "\t25\n", "\x0B25\x0C",
        "25\0", '1.5', '.5', '5.', '.', '1e3', '1E-3', '1.e3', '+.5e-3', '1e', '1e+', 'e1', '0x1A', '0b1', '1_000', '--1', '+-1',
        '1 2', '١', 'INF', 'NAN', '9223372036854775808', '-9223372036854775809', '00012', '1e5e', '1e5 ', ' .e1',
    ];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            $class === SiteConfiguration::class ? $class::useSingletonRustFfi(null) : $class::useRustFfi(null);
        }
        foreach (self::SINGLETON_MODELS as $model) {
            $model::useSingletonRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach ([NzbArticleFingerprintUnavailable::class, CandidateCapacityException::class, PartialPublicationDeferredException::class, MatchPrefiles::class] as $class) {
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::useRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::useRustFfi(true);
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), $class);
        }
        foreach (self::SINGLETON_MODELS as $model) {
            $this->assertFalse($model::usesSingletonRustFfi(), $model);
            $model::useSingletonRustFfi(true);
            $this->assertTrue($model::usesSingletonRustFfi(), $model);
            // The model's own Rust toggle is untouched by the trait's singleton toggle.
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::resetSingletonRustFfi();
            $this->assertFalse($model::usesSingletonRustFfi(), $model);
        }
        SiteConfiguration::useSingletonRustFfi(true);
        $this->assertFalse(TmuxConfiguration::usesSingletonRustFfi(), 'trait statics are per using class');
    }

    public function test_existing_contracts_are_unchanged(): void
    {
        foreach ([NzbArticleFingerprintUnavailable::class, CandidateCapacityException::class, PartialPublicationDeferredException::class] as $class) {
            $previous = new RuntimeException('cause');
            $exception = new $class('message', 7, $previous);
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame('message', $exception->getMessage());
            $this->assertSame(7, $exception->getCode());
            $this->assertSame($previous, $exception->getPrevious());
        }
        foreach (self::SINGLETON_MODELS as $model) {
            $this->assertSame(1, $model::SINGLETON_ID, $model);
        }

        $this->artisan('match:prefiles', ['limit' => 'abc'])
            ->expectsOutput(MatchPrefiles::INVALID_LIMIT_MESSAGE)
            ->assertExitCode(1);
        $result = MatchPrefiles::normalizeArguments('abc', false);
        $this->assertSame([1, MatchPrefiles::INVALID_LIMIT_MESSAGE], [$result['exit_code'], $result['error']]);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'message_id' => 'abc@x',
            'reason' => 'Article NOT FOUND',
            'kind' => 'missing',
            'articles_probed' => 10,
            'articles_missing' => 4,
            'missing_percent' => 40,
            'fully_missing' => false,
            'retryable' => false,
        ], $this->php('fingerprint', [' <abc@x> ', ' Article NOT FOUND ', 10, 4]));
        $timeout = $this->php('fingerprint', [null, 'timeout', PHP_INT_MAX, PHP_INT_MAX]);
        $this->assertSame([null, 'timeout', 1099511627776, 100, true, true], [$timeout['message_id'], $timeout['kind'], $timeout['articles_probed'],
            $timeout['missing_percent'], $timeout['fully_missing'], $timeout['retryable']]);
        $this->assertSame('disabled', $this->php('fingerprint', [null, 'disabled timeout', 0, 0])['kind']);
        $unknown = $this->php('fingerprint', [null, ' ', -5, 9]);
        $this->assertSame(['unknown', true, null, 0, 0], [$unknown['kind'], $unknown['retryable'], $unknown['reason'], $unknown['articles_probed'],
            $unknown['articles_missing']]);

        $this->assertSame([
            'active_candidates' => 8,
            'max_candidates' => 8,
            'pending_bytes' => 900,
            'max_pending_bytes' => 1000,
            'available_candidates' => 0,
            'available_bytes' => 100,
            'candidates_full' => true,
            'bytes_full' => false,
            'utilization_percent' => 100,
            'reason' => 'candidates',
            'at_capacity' => true,
        ], $this->php('capacity', [8, 8, 900, 1000]));
        $unlimited = $this->php('capacity', [-1, 0, PHP_INT_MAX, 1]);
        $this->assertSame([-1, 'bytes', 1000], [$unlimited['available_candidates'], $unlimited['reason'], $unlimited['utilization_percent']]);
        $this->assertSame('candidates_and_bytes', $this->php('capacity', [1, 1, 1, 1])['reason']);
        $this->assertSame('within_capacity', $this->php('capacity', [1, 4, 1, 4])['reason']);

        $this->assertSame([
            'articles_present' => 95,
            'articles_expected' => 100,
            'min_completion_percent' => 100,
            'deferrals' => 2,
            'max_deferrals' => 5,
            'missing_articles' => 5,
            'completion_percent' => 95,
            'meets_threshold' => false,
            'deferrals_exhausted' => false,
            'action' => 'defer',
            'can_still_publish' => true,
            'retry_delay_ms' => 4000,
        ], $this->php('partial', [95, 100, 100, 2, 5]));
        $publish = $this->php('partial', [200, 100, 0, 0, 0]);
        $this->assertSame(['publish', 1, 10, 0], [$publish['action'], $publish['min_completion_percent'], $publish['max_deferrals'], $publish['retry_delay_ms']]);
        $this->assertSame('reject_empty', $this->php('partial', [0, 0, 50, 0, 1])['action']);
        $this->assertSame('abandon', $this->php('partial', [1, 2, 99, 3, 3])['action']);
        $this->assertSame(300_000, $this->php('partial', [1, 2, 99, 40, PHP_INT_MAX])['retry_delay_ms']);

        $this->assertSame([
            'limit' => 'full',
            'valid' => true,
            'full' => true,
            'numeric' => false,
            'show' => true,
            'argv' => ['match_prefiles.php', 'full', 'show'],
            'exit_code' => 0,
            'error' => null,
        ], $this->php('prefile', ['full', true]));
        $this->assertSame(['match_prefiles.php', ' 25 '], $this->php('prefile', [' 25 ', false])['argv']);
        $invalid = $this->php('prefile', [null, true]);
        $this->assertSame([false, [], 1, MatchPrefiles::INVALID_LIMIT_MESSAGE], [$invalid['valid'], $invalid['argv'], $invalid['exit_code'], $invalid['error']]);

        $this->assertSame([
            'row_count' => 1,
            'has_singleton' => true,
            'singleton_rows' => 1,
            'extra_rows' => 0,
            'min_id' => 1,
            'max_id' => 1,
            'status' => 'ok',
            'needs_repair' => false,
        ], $this->php('singleton', [[1]]));
        $empty = $this->php('singleton', [[]]);
        $this->assertSame(['empty', null, null, true], [$empty['status'], $empty['min_id'], $empty['max_id'], $empty['needs_repair']]);
        $this->assertSame('missing', $this->php('singleton', [[5, -3]])['status']);
        $this->assertSame('duplicated', $this->php('singleton', [[1, 9, 1]])['status']);
        $this->assertSame('extra_rows', $this->php('singleton', [[2, 1]])['status']);
    }

    public function test_edge_case_grids_match_php(): void
    {
        $this->ffiOrSkip();
        $texts = [null, ...self::TEXTS, str_repeat('x', 300).'timeout'];
        foreach ($texts as $id) {
            foreach ($texts as $reason) {
                $this->assertParity('fingerprint', [$id, $reason, 10, 4]);
            }
        }
        foreach (self::INTS as $a) {
            foreach (self::INTS as $b) {
                $this->assertParity('fingerprint', ['<a@b>', 'missing', $a, $b]);
                $this->assertParity('capacity', [$a, $b, 900, 1000]);
                $this->assertParity('capacity', [8, 8, $a, $b]);
                $this->assertParity('partial', [$a, $b, 100, 2, 5]);
                $this->assertParity('partial', [95, 100, $a, $b, 5]);
                $this->assertParity('partial', [95, 100, 99, $a, $b]);
            }
        }
        foreach ([null, ...self::LIMITS, ...self::TEXTS] as $limit) {
            $this->assertParity('prefile', [$limit, false]);
            $this->assertParity('prefile', [$limit, true]);
        }
        foreach ([[], [1], [1, 1], [0], [2, 1], [1, 2, 3], self::INTS, [PHP_INT_MIN], [PHP_INT_MAX, 1], range(-500, 500)] as $ids) {
            $this->assertParity('singleton', [$ids]);
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3702));
        $alphabet = ['a', 'Z', '0', '4', '3', '<', '>', '@', ' ', "\t", "\n", "\0", "\x0B", "\x0C", 'é', "\u{1F3AC}", "\xff", 'disabled', 'MISSING',
            'not found', 'Timed Out', 'timeout', 'unsupported', 'not supported', '430'];
        $numeric = ['0', '1', '9', '.', 'e', 'E', '+', '-', ' ', "\t", "\n", "\r", "\x0B", "\x0C", "\0", 'x', 'full', 'f'];
        $text = static function (array $chars, int $max) use ($random): string {
            $out = '';
            for ($i = $random->getInt(0, $max); $i > 0; $i--) {
                $out .= $chars[$random->getInt(0, \count($chars) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 4)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 1000),
            2 => $random->getInt(0, 400_000),
            3 => $random->getInt(1099511627770, 1099511627780),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        $nullable = static fn (string $value): ?string => $random->getInt(0, 4) === 0 ? null : $value;

        for ($i = 0; $i < 1500; $i++) {
            $this->assertParity('fingerprint', [$nullable($text($alphabet, 8)), $nullable($text($alphabet, 6)), $int(), $int()]);
            $this->assertParity('capacity', [$int(), $int(), $int(), $int()]);
            $expected = $int();
            $this->assertParity('partial', [$random->getInt(0, 1) === 1 ? $expected - $random->getInt(0, 20) : $int(), $expected,
                $random->getInt(-5, 105), $random->getInt(0, 1) === 1 ? $random->getInt(0, 15) : $int(), $random->getInt(0, 1) === 1 ? $random->getInt(-1, 12) : $int()]);
            $this->assertParity('prefile', [$nullable($text($numeric, 7)), $random->getInt(0, 1) === 1]);
            $ids = [];
            for ($n = $random->getInt(0, 12); $n > 0; $n--) {
                $ids[] = $random->getInt(0, 2) === 0 ? $int() : $random->getInt(0, 3);
            }
            $this->assertParity('singleton', [$ids]);
        }
    }

    public function test_native_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $call = fn (mixed ...$args): mixed => $this->callFfi($ffi, 'nntmux_w37_verification_capacity_prefile_singleton', ...$args);
        $input = pack('q4', 8, 8, 900, 1000);
        $out = $ffi->new('char[256]');

        $this->assertSame(88, $call(1, 0, $input, 32, $out, 256));
        $this->assertSame([8, 8, 900, 1000, 0, 100, 1, 0, 100, 1, 1], array_values(unpack('q*', FFI::string($out, 88)) ?: []));

        // A tiny output reports the negated required capacity without writing; the retry succeeds.
        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $this->assertSame(-88, $call(1, 0, $input, 32, $small, 4));
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $retry = $ffi->new('char[88]');
        $this->assertSame(88, $call(1, 0, $input, 32, $retry, 88));
        foreach ([[0, 0, pack('q4', 0, 0, 0, 0), 96], [2, 0, pack('q5', 1, 2, 3, 4, 5), 96], [3, 3, 'full', 40], [4, 0, pack('q2', 1, 2), 64]] as [$op, $flags, $bytes, $needed]) {
            $this->assertSame(-$needed, $call($op, $flags, $bytes, \strlen($bytes), $small, 4), "op {$op}");
            $this->assertSame('ZZZZ', FFI::string($small, 4), "op {$op}");
        }

        // Null pointers, unknown operations, unknown flag bits and malformed layouts decline with -1.
        $this->assertSame(-1, $call(1, 0, null, 0, $out, 256));
        $this->assertSame(-1, $call(1, 0, $input, 32, null, 256));
        $this->assertSame(-1, $call(5, 0, $input, 32, $out, 256));
        $this->assertSame(-1, $call(PHP_INT_MAX & 0xFFFFFFFF, 0, $input, 32, $out, 256));
        $this->assertSame(-1, $call(1, 1, $input, 32, $out, 256));
        $this->assertSame(-1, $call(2, 1, pack('q5', 0, 0, 0, 0, 0), 40, $out, 256));
        $this->assertSame(-1, $call(0, 4, pack('q4', 0, 0, 0, 0), 32, $out, 256));
        $this->assertSame(-1, $call(3, 4, '', 0, $out, 256));
        $this->assertSame(-1, $call(4, 1, '', 0, $out, 256));
        $this->assertSame(-1, $call(1, 0, $input, 31, $out, 256));
        $this->assertSame(-1, $call(4, 0, $input, 31, $out, 256));
        $this->assertSame(-1, $call(0, 0, pack('q4', 1, 0, 0, 0).'x', 33, $out, 256), 'absent id must carry no bytes');
        $this->assertSame(-1, $call(0, 1, pack('q4', 2, 0, 0, 0).'x', 33, $out, 256), 'lengths must cover the tail');
        $this->assertSame(-1, $call(0, 1, pack('q4', -1, 0, 0, 0), 32, $out, 256));
        $this->assertSame(-1, $call(3, 0, 'x', 1, $out, 256), 'absent limit must carry no bytes');

        // Overlapping input and output ranges decline.
        $shared = $ffi->new('char[160]');
        FFI::memcpy($shared, $input, 32);
        $this->assertSame(-1, $call(1, 0, $shared, 32, FFI::addr($shared[8]), 88));

        foreach (self::HELPERS as $kind => [$class, $method]) {
            $this->toggle($kind, true);
            $class::$method(...$this->sampleArgs($kind));
            $this->assertInstanceOf(FFI::class, $this->ffiProperty($kind)->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $saved = [];
        foreach (array_keys(self::HELPERS) as $kind) {
            $saved[$kind] = $this->ffiProperty($kind)->getValue();
            $this->ffiProperty($kind)->setValue(null, false);
            $this->toggle($kind, true);
        }

        try {
            foreach (self::HELPERS as $kind => [$class, $method]) {
                $args = $this->sampleArgs($kind);
                $this->assertNull($this->native($kind, $args), $kind);
                $this->assertSame($this->php($kind, $args), $class::$method(...$args), $kind);
            }
            $this->assertNull(NzbArticleFingerprintUnavailable::evaluateW372(null, 1, 0, pack('q4', 0, 0, 0, 0), 11));
        } finally {
            foreach (array_keys(self::HELPERS) as $kind) {
                $this->ffiProperty($kind)->setValue(null, $saved[$kind]);
            }
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five helpers through their public dispatchers, including the FFI
        // call, packing and decoding (release dylib, 5 warmups, 301 samples x 40 iterations per workload).
        // Common = typical fingerprint, capacity, publication, argument and singleton checks; uncommon = clamped,
        // long, keyword-dense and 5,000-row singleton inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.42x / p99 0.46x, uncommon mean 1.95x / p99 1.93x
        // - Round 2: common mean 0.42x / p99 0.43x, uncommon mean 1.93x / p99 1.88x
        // - Round 3: common mean 0.42x / p99 0.43x, uncommon mean 1.93x / p99 1.90x
        // Reproduce:
        // php vendor/bin/phpunit tests/Unit/RustVerificationCapacityPrefileSingletonParityTest.php --filter benchmark
        // The uncommon gain comes from the 5,000-row singleton scan; on the common path the FFI round trip costs more
        // than the microsecond PHP work, so the common 1.5x bar in .ai/rules/rust-performance.md fails and every
        // helper keeps its Rust default false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3702));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (array_keys(self::HELPERS) as $kind) {
                $this->toggle($kind, null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
                'samples' => $samples,
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustVerificationCapacityPrefileSingleton speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach ([NzbArticleFingerprintUnavailable::class, CandidateCapacityException::class, PartialPublicationDeferredException::class, MatchPrefiles::class] as $class) {
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
            foreach (self::SINGLETON_MODELS as $model) {
                $this->assertFalse($model::usesSingletonRustFfi(), $model.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertParity(string $kind, array $args): void
    {
        [$class, $method] = self::HELPERS[$kind];
        $expected = $this->php($kind, $args);
        $context = $kind.' '.json_encode(array_map(static fn (mixed $arg): mixed => \is_string($arg) ? bin2hex($arg) : $arg, $args));
        $this->assertSame($expected, $this->native($kind, $args), $context.' (native)');
        $this->toggle($kind, true);
        $this->assertSame($expected, $class::$method(...$args), $context.' (rust dispatch)');
        $this->toggle($kind, false);
        $this->assertSame($expected, $class::$method(...$args), $context.' (php dispatch)');
        $this->toggle($kind, null);
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, mixed>
     */
    private function php(string $kind, array $args): array
    {
        $result = $this->invokePrivate($kind, 'Php', $args);
        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, mixed>|null
     */
    private function native(string $kind, array $args): ?array
    {
        $result = $this->invokePrivate($kind, 'Native', $args);
        $this->assertTrue($result === null || \is_array($result));

        return $result;
    }

    /**
     * @param  list<mixed>  $args
     */
    private function invokePrivate(string $kind, string $suffix, array $args): mixed
    {
        [$class, $method] = self::HELPERS[$kind];

        return (new ReflectionMethod($class, $method.$suffix))->invoke(null, ...$args);
    }

    private function toggle(string $kind, ?bool $use): void
    {
        $class = self::HELPERS[$kind][0];
        $kind === 'singleton' ? $class::useSingletonRustFfi($use) : $class::useRustFfi($use);
    }

    private function ffiProperty(string $kind): ReflectionProperty
    {
        return new ReflectionProperty(self::HELPERS[$kind][0], self::HELPERS[$kind][2]);
    }

    /**
     * @return list<mixed>
     */
    private function sampleArgs(string $kind): array
    {
        return match ($kind) {
            'fingerprint' => ['<a@b>', 'timeout', 3, 1],
            'capacity' => [1, 2, 3, 4],
            'partial' => [1, 2, 50, 0, 3],
            'prefile' => ['25', true],
            default => [[1, 2]],
        };
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            ['fingerprint', ['<part1of5.abc$xyz@news.example.com>', 'article not found on provider', 40, 3]],
            ['fingerprint', [null, 'fingerprint read timed out', 40, 0]],
            ['capacity', [6, 8, 48_000_000, 64_000_000]],
            ['capacity', [8, 8, 70_000_000, 64_000_000]],
            ['partial', [96, 100, 100, 1, 10]],
            ['partial', [100, 100, 100, 0, 10]],
            ['prefile', ['full', false]],
            ['prefile', ['250', true]],
            ['singleton', [[1]]],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $ids = range(-2_000, 2_999);
        $ids[] = 1;

        return [
            ['fingerprint', [" \t<".str_repeat('x', 400).'@'.str_repeat('y', 400)."> \n", str_repeat('provider said: ', 40).'unsupported', PHP_INT_MAX, PHP_INT_MIN]],
            ['fingerprint', [str_repeat("\0", 64), str_repeat('noise ', 200), -1, -1]],
            ['capacity', [PHP_INT_MAX, 0, PHP_INT_MIN, 1]],
            ['partial', [PHP_INT_MIN, PHP_INT_MAX, -50, PHP_INT_MAX, 0]],
            ['partial', [1, 3, 101, 40, PHP_INT_MAX]],
            ['prefile', [" \x0B+.5e-300\x0C ", true]],
            ['prefile', [str_repeat('9', 400).'e', false]],
            ['singleton', [$ids]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (array_keys(self::HELPERS) as $kind) {
            $this->toggle($kind, $useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            [$class, $method] = self::HELPERS[$kind];
            $count += \count($class::$method(...$args));
        }

        return $count;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(\count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain(NzbArticleFingerprintUnavailable::FFI_DOMAIN, NzbArticleFingerprintUnavailable::FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with nntmux_w37_verification_capacity_prefile_singleton is not available.');
        }

        return $ffi;
    }
}
