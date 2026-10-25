<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\DTO\SearchCursor;
use App\Services\Search\SearchCursorCodec;
use App\Services\Search\Support\SearchFailureTracker;
use App\Services\StatusProbes\RedisProbe;
use App\Services\StatusProbes\SearchProbe;
use App\Services\StatusProbes\ServiceProbeRegistry;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`): per helper, 300
 * samples per workload (1000 with `NNTMUX_W50_BENCH=1`), 20 warmup rounds, per-sample random PHP/Rust
 * order, each side the fastest of three back-to-back calls, release dylib. Common workloads hold 64
 * records and uncommon ones 192 records with edge cases. Timings include PHP-side packing, the FFI call,
 * output decoding and PHP patches; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each run asserts that the Rust defaults still clear 1.5x.
 */
class RustProbesRedisSearchRegistryCursorFailureW50ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        RedisProbe::class => 'probeOutcomeBatch',
        SearchProbe::class => 'availabilityOutcomeBatch',
        ServiceProbeRegistry::class => 'resolveProbeBatch',
        SearchCursorCodec::class => 'decodePayloadBatch',
        SearchFailureTracker::class => 'failureWindowBatch',
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        RedisProbe::class => false,
        SearchProbe::class => true,
        ServiceProbeRegistry::class => false,
        SearchCursorCodec::class => true,
        SearchFailureTracker::class => false,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W50_BENCH=1` runs (1000 samples per workload, seed 5052, release Rust, PHP 8.5.12 CLI without
     * opcache, Apple Silicon macOS on a shared host). Redis, registry and failure-window rows do tens of
     * nanoseconds of PHP work per record, which packing and `unpack('P*')` of the output alone match.
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        RedisProbe::class => [0.90, 1.03, 2.00, 1.92],
        SearchProbe::class => [2.35, 2.59, 3.70, 3.36],
        ServiceProbeRegistry::class => [1.11, 1.17, 2.14, 2.34],
        SearchCursorCodec::class => [3.78, 3.46, 2.45, 2.13],
        SearchFailureTracker::class => [1.45, 1.40, 2.04, 1.24],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BYTES = ['a', 'Z', '0', ' ', "\t", "\n", "\r", "\x0b", '"', '\\', '/', '%', '.', ':', "\x01", "\x7f", "\0", "\xc3\xa9", "\xe2\x82\xac", "\xe3\x81\x82", "\xf0\x9f\x98\x80", "\xc3", "\xff"];

    private const PONGS = [true, false, null, 'PONG', 'pong', 'Pong', '+PONG', '+pong', 'PONG ', ' PONG', '', 'OK', 'PONG!', "P\xc3\x96NG", '1', 'QUEUED'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::setFfi($class, null);
        }

        parent::tearDown();
    }

    public function test_methods_defaults_and_scoped_toggles(): void
    {
        foreach (self::METHODS as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW50RustFfi', 'withW50RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
                $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
                $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
            $constant = new ReflectionClassConstant($class, 'W50_RUST_FFI_DEFAULT');
            $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
            $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
            $this->assertSame(self::DEFAULTS[$class], $class::usesW50RustFfi());

            foreach ([true, false] as $enabled) {
                $this->assertSame([$enabled, 'ok'], $class::withW50RustFfi($enabled, static fn () => [$class::usesW50RustFfi(), 'ok']));
                $this->assertSame(self::DEFAULTS[$class], $class::usesW50RustFfi(), "{$class} must restore after the callback");
            }
            $nested = $class::withW50RustFfi(false, static fn () => [$class::withW50RustFfi(true, static fn () => $class::usesW50RustFfi()), $class::usesW50RustFfi()]);
            $this->assertSame([true, false], $nested);
            $caught = null;
            try {
                $class::withW50RustFfi(! self::DEFAULTS[$class], static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $exception) {
                $caught = $exception->getMessage();
            }
            $this->assertSame('boom', $caught, 'The callback exception must propagate.');
            $this->assertSame(self::DEFAULTS[$class], $class::usesW50RustFfi(), "{$class} must restore after an exception");

            $class::withW50RustFfi(! self::DEFAULTS[$class], function () use ($class): void {
                foreach (array_keys(self::METHODS) as $other) {
                    if ($other !== $class) {
                        $this->assertSame(self::DEFAULTS[$other], $other::usesW50RustFfi(), "{$class} leaked into {$other}");
                    }
                }
            });
        }

        // The readonly codec keeps its state outside static properties.
        $this->assertTrue((new ReflectionClass(SearchCursorCodec::class))->isReadOnly());
        $this->assertSame([], (new ReflectionClass(SearchCursorCodec::class))->getStaticProperties());

        // The W50 toggles leave the earlier per-call switches alone.
        $legacy = RedisProbe::usesRustFfi();
        RedisProbe::withW50RustFfi(! $legacy, fn () => $this->assertSame($legacy, RedisProbe::usesRustFfi()));
        $legacy = SearchCursorCodec::usesW415RustFfi();
        SearchCursorCodec::withW50RustFfi(! $legacy, fn () => $this->assertSame($legacy, SearchCursorCodec::usesW415RustFfi()));
    }

    public function test_entry_points_route_by_toggle(): void
    {
        $this->requireFfi(RedisProbe::class);
        foreach (array_keys(self::METHODS) as $class) {
            $method = self::METHODS[$class];
            $arguments = self::firstArguments($class);
            $plan = static fn () => $class::{$method}(...$arguments);
            $php = $class::withW50RustFfi(false, $plan);
            $this->assertSame($class::{$method.'Php'}(...$arguments), $php, $class);
            $this->assertSame($php, $class::withW50RustFfi(true, $plan), $class);
            $this->assertSame($php, $plan(), $class);
        }
    }

    public function test_redis_probe_examples(): void
    {
        $connected = [0, 'Connected'];
        $unexpected = [1, 'Redis ping returned unexpected response'];
        $long = str_repeat('x', 117)."  \t\nrest of the message";
        $this->assertBoth(RedisProbe::class, [
            [true, '+PONG', 'pong', 'PONG', '+pong', false, null, 'OK', 'PONG', 'PONG', 'PONG', 'PONG'],
            [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 10_000_000, PHP_INT_MIN],
            [1_000_000, 2_500_000, 999_999, 7_000_000, 0, 1, 2, 3, 0, 0, 0, PHP_INT_MAX],
            [8 => 'Connection refused [tcp://redis:6379]', 9 => $long],
        ], array_merge(
            self::reasonWords($connected, 1),
            self::reasonWords($connected, 2),
            self::reasonWords($connected, 0),
            self::reasonWords($connected, 7),
            self::reasonWords($unexpected, 0),
            self::reasonWords($unexpected, 0),
            self::reasonWords($unexpected, 0),
            self::reasonWords($unexpected, 0),
            self::reasonWords([2, 'Redis unreachable: Connection refused [tcp://redis:6379]'], 0),
            self::reasonWords([2, 'Redis unreachable: '.str_repeat('x', 117).'...'], 0),
            self::reasonWords($connected, -10),
            self::reasonWords($connected, (int) (((float) PHP_INT_MAX - (float) PHP_INT_MIN) / 1_000_000)),
        ));

        // Non-ASCII errors defer to mb_strwidth(); each word set matches the single-probe helpers.
        $pongs = ['PONG', 'nope', 'PONG', 'PONG'];
        $errors = [2 => str_repeat("\u{3042}", 70), 3 => 'caf'."\u{e9}"];
        $words = RedisProbe::probeOutcomeBatchNative($pongs, [0, 0, 0, 0], [5_000_000, 9_000_000, 0, 0], $errors);
        $this->assertSame(RedisProbe::probeOutcomeBatchPhp($pongs, [0, 0, 0, 0], [5_000_000, 9_000_000, 0, 0], $errors), $words);
        foreach ([0 => RedisProbe::evaluatePingResponsePhp('PONG', 0, 5_000_000), 1 => RedisProbe::evaluatePingResponsePhp('nope', 0, 9_000_000), 2 => RedisProbe::formatUnreachableResultPhp($errors[2])] as $i => $result) {
            $this->assertSame([$result->responseTimeMs, \strlen($result->reason), self::xxh64($result->reason)], \array_slice($words, 4 * $i + 1, 3));
        }
        $this->assertSame([], RedisProbe::probeOutcomeBatchNative([], [], []));
        // A NUL byte or an out-of-range error index falls back to PHP as a whole.
        $this->assertBoth(RedisProbe::class, [["PO\0NG", 'PONG'], [0, 0], [0, 0], []], array_merge(self::reasonWords($unexpected, 0), self::reasonWords($connected, 0)));
        $this->assertBoth(RedisProbe::class, [['PONG'], [0], [0], [5 => 'ignored']], self::reasonWords($connected, 0));
    }

    public function test_search_probe_examples(): void
    {
        $this->assertBoth(SearchProbe::class, [
            [true, false, true, false],
            ['manticore', 'elasticsearch', '', 'x'],
            [0, 0, 100, 0],
            [12_345_678, 1_000_000, 0, 0],
            [3 => str_repeat('timeout ', 20)],
        ], array_merge(
            self::reasonWords([0, 'Search driver "manticore" available'], 12),
            self::reasonWords([2, 'Search driver "elasticsearch" unavailable'], 1),
            self::reasonWords([0, 'Search driver "" available'], 0),
            self::reasonWords([3, 'Search check failed: '.rtrim(substr(str_repeat('timeout ', 20), 0, 120)).'...'], 0),
        ));
        $result = SearchProbe::formatFailureResultPhp("\u{e9}chec");
        $this->assertBoth(SearchProbe::class, [[true], ['m'], [0], [0], ["\u{e9}chec"]], self::reasonWords([3, $result->reason], 0));
        $this->assertBoth(SearchProbe::class, [[true], ["m\0"], [0], [0], []], self::reasonWords([0, "Search driver \"m\0\" available"], 0));
    }

    public function test_service_probe_registry_examples(): void
    {
        $unknown = static fn (string $id): array => [-1, \strlen('Unknown probe identifier "'.$id.'"'), self::xxh64('Unknown probe identifier "'.$id.'"')];
        $this->assertBoth(ServiceProbeRegistry::class, [['redis', 'disk', 'nope', '', 'Redis', 'database', 'disk ']], array_merge(
            [1, 0, 0, 5, 0, 0],
            $unknown('nope'),
            $unknown(''),
            $unknown('Redis'),
            [0, 0, 0],
            $unknown('disk '),
        ));
        // Duplicate known identifiers resolve to the first; numeric strings stay exact.
        $this->assertBoth(ServiceProbeRegistry::class, [['a', '1', '01', 'b'], ['b', 'a', '1', 'a', 'b']], array_merge([1, 0, 0, 2, 0, 0], $unknown('01'), [0, 0, 0]));
        $this->assertBoth(ServiceProbeRegistry::class, [['a'], []], $unknown('a'));
        $this->assertBoth(ServiceProbeRegistry::class, [["a\0b"], ['a']], $unknown("a\0b"));
        $this->assertSame(ServiceProbeRegistry::formatUnknownProbeReasonPhp('x'), 'Unknown probe identifier "x"');
    }

    public function test_search_cursor_codec_examples(): void
    {
        $now = 1_761_400_000;
        $digest = static fn (string $record): int => self::xxh64($record);
        $payload = SearchCursorCodec::encodePayloadPhp(new SearchCursor([4, 'b'], 40, 'q-hash', 'manticore', 'gen-7', $now + 60));
        $this->assertBoth(SearchCursorCodec::class, [[
            $payload,
            '{"sort":[3,"aé\n",null,true,false],"total":"9","query":7,"driver":true,"expires":'.($now + 1).'}',
            '{"sort":[1.5,[1,2]],"total":2.9,"generation":false,"expires":'.$now.'}',
            '{"sort":[],"expires":'.($now - 1).'}',
            '{"sort":"x","expires":'.($now + 1).'}',
            '[1,2]',
            '"text"',
            'not json',
            '',
            '{"sort":[],"query":{"a":1},"expires":'.($now + 1).'}',
            '{"sort":{"b":1,"a":2},"expires":'.($now + 1).'}',
            '{"sort":[1],"sort":[2,3],"expires":'.($now + 1).'}',
            '{"sort":["😀"],"expires":'.($now + 1).',"total":-9223372036854775808}',
            '{"sort":["\ud800"],"expires":'.($now + 1).'}',
        ], $now], [
            0, 40, $now + 60, 2, $digest("40\n".($now + 60)."\n6\nq-hash9\nmanticore5\ngen-7i4\ns1\nb"),
            0, 9, $now + 1, 5, $digest("9\n".($now + 1)."\n1\n71\n10\ni3\ns4\na\u{e9}\nxNULL\nxtrue\nxfalse\n"),
            0, 2, $now, 2, $digest("2\n{$now}\n0\n0\n0\nx1.5\nx".var_export([1, 2], true)."\n"),
            2, 0, 0, 0, 0,
            2, 0, 0, 0, 0,
            2, 0, 0, 0, 0,
            2, 0, 0, 0, 0,
            1, 0, 0, 0, 0,
            1, 0, 0, 0, 0,
            3, 0, 0, 0, 0,
            0, 0, $now + 1, 2, $digest("0\n".($now + 1)."\n0\n0\n0\ni1\ni2\n"),
            0, 0, $now + 1, 2, $digest("0\n".($now + 1)."\n0\n0\n0\ni2\ni3\n"),
            0, PHP_INT_MIN, $now + 1, 1, $digest(PHP_INT_MIN."\n".($now + 1)."\n0\n0\n0\ns4\n\u{1f600}"),
            1, 0, 0, 0, 0,
        ]);

        // Accepted payloads agree with decodePayloadPhp() at the current time.
        $live = SearchCursorCodec::encodePayloadPhp(new SearchCursor([4, 'b'], 40, 'q-hash', 'manticore', 'gen-7', time() + 3600));
        $cursor = SearchCursorCodec::decodePayloadPhp($live);
        $words = SearchCursorCodec::decodePayloadBatchNative([$live], time());
        $this->assertSame([0, $cursor->total, $cursor->expiresAt, \count($cursor->sortValues)], \array_slice($words, 0, 4));
        // A NUL byte falls back to PHP for the whole batch.
        $this->assertBoth(SearchCursorCodec::class, [["{\"sort\":[]}\0", '{"sort":[],"expires":9}'], 0], [1, 0, 0, 0, 0, 0, 0, 9, 0, $digest("0\n9\n0\n0\n0\n")]);
    }

    public function test_search_failure_tracker_examples(): void
    {
        $now = 1_000;
        $expected = [];
        $counts = [0, 2, 3, 5, -4, 7, 3, 3];
        $baselines = [0, 2, 0, 5, 0, 1, 3, 3];
        $opened = [0, 0, 970, 900, PHP_INT_MIN, 2_000, PHP_INT_MAX, -5];
        foreach ($counts as $i => $count) {
            $tracker = new SearchFailureTracker;
            for ($n = 0; $n < max(0, $count); $n++) {
                $tracker->recordPhp();
            }
            $window = SearchFailureTracker::evaluateCircuitStateWindow($count, 3, $opened[$i], $now, 60);
            $flags = ($count !== $baselines[$i] ? 1 : 0)
                | ($count >= 0 && $tracker->reachedThresholdPhp(3) ? 2 : 0)
                | ['closed' => 0, 'open' => 1, 'half_open' => 2][$window['state']] << 2
                | (int) $window['allow_probe'] << 4
                | (int) $window['trip_on_next_failure'] << 5;
            if ($count >= 0) {
                $this->assertSame($count !== $baselines[$i], $tracker->failedSincePhp($baselines[$i]));
            }
            array_push($expected, $flags, $window['remaining_cooldown_seconds']);
        }
        $this->assertBoth(SearchFailureTracker::class, [$counts, $baselines, $opened, 3, $now, 60], $expected);
        $this->assertBoth(SearchFailureTracker::class, [[1, 0], [1, 1], [0, 0], 0, 10, -5], [2 << 2 | 1 << 4 | 1 << 5, 0, 1 | 1 << 5, 0]);
        $this->assertBoth(SearchFailureTracker::class, [[], [], [], 3, 0], []);
    }

    public function test_every_helper_is_served_by_rust(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
            $method = self::METHODS[$class];
            $class::{$method.'Native'}(...self::firstArguments($class));
            $this->assertInstanceOf(FFI::class, self::ffiOf($class), "{$class} must resolve the Rust helper");
        }
        // A large batch grows the shared output buffer.
        $counts = range(0, 4999);
        $this->assertSame(
            SearchFailureTracker::failureWindowBatchPhp($counts, $counts, $counts, 5, 3_000, 60),
            SearchFailureTracker::failureWindowBatchNative($counts, $counts, $counts, 5, 3_000, 60),
        );
        $this->assertSame(10000, (new ReflectionClass(SearchFailureTracker::class))->getStaticPropertyValue('w50OutCap'));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::setFfi($class, false);
            $arguments = self::firstArguments($class);
            $method = self::METHODS[$class];
            $this->assertSame($class::{$method.'Php'}(...$arguments), $class::{$method.'Native'}(...$arguments), $class);
            $this->assertFalse(self::ffiOf($class), "{$class} must keep the disabled handle");
        }
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        foreach (self::abiPackets() as $class => [$function, $data, $ints, $words]) {
            $ffi = $this->requireFfi($class);
            $out = $ffi->new('int64_t[16]');
            $small = $ffi->new('int64_t[1]');
            $small[0] = 7;
            $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

            $this->assertSame($words, $call(1, 0, $data, $ints, $out, 16), $function);
            $this->assertSame(-$words, $call(1, 0, $data, $ints, $small, 1), $function);
            $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
            $this->assertSame(-$words, $call(1, 0, $data, $ints, null, 0), $function);
            $this->assertSame(-1, $call(1, 0, $data, $ints, null, 4), $function);
            $this->assertSame(-1, $call(1, 1, $data, $ints, $out, 16), "{$function} flags");
            $this->assertSame(-1, $call(2, 0, $data, $ints, $out, 16), "{$function} record count");
            $this->assertSame(-1, $call(1, 0, $data."\0", $ints, $out, 16), "{$function} trailing field");
            $this->assertSame(-1, $call(1, 0, $data, $ints.'x', $out, 16), "{$function} ragged int table");
        }
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi(RedisProbe::class);
        mt_srand(5051);
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $count = mt_rand(1, 24);
            $withNul = $round % 50 === 49;

            $pongs = $starts = $ends = $errors = $available = $drivers = [];
            for ($i = 0; $i < $count; $i++) {
                $pongs[] = mt_rand(0, 5) === 0 ? self::randomBytes(6, $withNul) : self::PONGS[mt_rand(0, \count(self::PONGS) - 1)];
                [$starts[], $ends[]] = self::randomTiming();
                $available[] = mt_rand(0, 1) === 1;
                $drivers[] = mt_rand(0, 3) === 0 ? self::randomBytes(12, $withNul) : ['manticore', 'elasticsearch', 'meilisearch'][mt_rand(0, 2)];
                if (mt_rand(0, 3) === 0) {
                    $errors[$i] = self::randomError($withNul);
                }
            }
            if (mt_rand(0, 40) === 0) {
                $errors[$count + mt_rand(0, 3)] = 'out of range';
            }
            $this->assertBoth(RedisProbe::class, [$pongs, $starts, $ends, $errors], null);
            $this->assertBoth(SearchProbe::class, [$available, $drivers, $starts, $ends, $errors], null);

            $known = mt_rand(0, 3) === 0 ? array_map(static fn (): string => self::randomBytes(3), range(0, mt_rand(0, 6))) : ServiceProbeRegistry::PROBE_IDENTIFIERS;
            $identifiers = [];
            for ($i = 0; $i < $count; $i++) {
                $identifiers[] = mt_rand(0, 1) === 0 && $known !== [] ? $known[mt_rand(0, \count($known) - 1)] : self::randomBytes(4, $withNul);
            }
            $this->assertBoth(ServiceProbeRegistry::class, [$identifiers, $known], null);

            $now = mt_rand(0, 1) === 0 ? 1_761_400_000 : mt_rand(-5, 5);
            $payloads = [];
            for ($i = 0; $i < $count; $i++) {
                $payloads[] = self::randomPayload($now, $withNul);
            }
            $this->assertBoth(SearchCursorCodec::class, [$payloads, $now], null);

            $counts = $baselines = $opened = [];
            for ($i = 0; $i < $count; $i++) {
                $counts[] = self::edgeInt(12);
                $baselines[] = mt_rand(0, 2) === 0 ? $counts[$i] : self::edgeInt(12);
                $opened[] = self::edgeInt(2_000);
            }
            $this->assertBoth(SearchFailureTracker::class, [$counts, $baselines, $opened, self::edgeInt(8), self::edgeInt(2_000), self::edgeInt(120)], null);
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi(RedisProbe::class);
        mt_srand(5052);
        $strict = getenv('NNTMUX_W50_BENCH') === '1';
        $samples = $strict ? 1000 : 300;
        $workloads = [];
        $floors = [];
        $now = 1_761_400_000;

        foreach (['common' => 64, 'uncommon' => 192] as $name => $count) {
            $odd = $name === 'uncommon';
            $pongs = $starts = $ends = $redisErrors = $available = $drivers = $searchErrors = $identifiers = $payloads = $counts = $baselines = $opened = [];
            $base = 8_000_000_000_000;
            for ($i = 0; $i < $count; $i++) {
                $starts[] = $start = $base + $i * 50_000_000;
                $ends[] = $start + mt_rand(80_000, $odd ? 900_000_000 : 4_000_000);
                $pongs[] = $odd ? self::PONGS[mt_rand(0, \count(self::PONGS) - 1)] : ($i % 2 === 0 ? true : 'PONG');
                $available[] = ! $odd || $i % 3 !== 0;
                $drivers[] = $odd ? ['manticore', 'elasticsearch', 'search-cluster-'.$i.'.internal'][$i % 3] : 'manticore';
                if ($odd && $i % 4 === 1) {
                    $message = $i % 24 === 1
                        ? "Verbindung fehlgeschlagen: Zeit\u{fc}berschreitung nach {$i}s"
                        : 'Connection refused [tcp://redis-'.$i.'.cluster.internal:6379] after '.str_repeat('retrying; ', mt_rand(1, 18));
                    $redisErrors[$i] = $message;
                    $searchErrors[$i] = 'cURL error 28: Operation timed out after '.mt_rand(1000, 30000).' milliseconds '.str_repeat('with 0 bytes received ', $i % 9);
                }

                $identifiers[] = $odd
                    ? ($i % 3 === 0 ? 'custom-probe-'.$i.'-'.str_repeat('x', $i % 17) : ServiceProbeRegistry::PROBE_IDENTIFIERS[$i % 6])
                    : ($i % 16 === 15 ? 'unknown' : ServiceProbeRegistry::PROBE_IDENTIFIERS[$i % 6]);

                $payloads[] = $odd
                    ? match ($i % 8) {
                        0 => '{"sort":[1.25,'.$i.'],"total":'.$i.',"query":"q","driver":"manticore","generation":"g","expires":'.($now + 60).'}',
                        1 => json_encode(['sort' => [mt_rand(), "t\u{e9}l\u{e9}chargement \u{1f600} ".$i, null], 'total' => mt_rand(0, 99999), 'query' => hash('sha1', (string) $i), 'driver' => 'elasticsearch', 'generation' => 'gen-'.$i, 'expires' => $now + $i], JSON_THROW_ON_ERROR),
                        2 => '{"sort":['.$i.'],"expires":'.($now - 5).'}',
                        3 => '{"sort":["'.str_repeat('a\\"b\\\\', $i % 7).'",'.$i.',"x"],"total":"'.$i.'","query":'.$i.',"expires":'.($now + 9).'}',
                        default => json_encode(['sort' => array_map(static fn (int $n): int => $n * 7919, range(0, $i % 6)), 'total' => $i * 31, 'query' => hash('sha1', 'q'.$i), 'driver' => 'manticore', 'generation' => (string) ($i * 13), 'expires' => $now + 600], JSON_THROW_ON_ERROR),
                    }
                : json_encode(['sort' => [mt_rand(0, 100000), 1_000_000 + $i], 'total' => 5000 + $i, 'query' => hash('sha1', 'query'.$i), 'driver' => 'manticore', 'generation' => 'gen-42', 'expires' => $now + 900], JSON_THROW_ON_ERROR);

                $counts[] = $odd ? mt_rand(-1, 9) : mt_rand(0, 4);
                $baselines[] = $odd ? mt_rand(0, 9) : $counts[$i] - mt_rand(0, 1);
                $opened[] = $odd ? ($i % 11 === 0 ? PHP_INT_MIN + $i : $now - mt_rand(0, 120)) : $now - mt_rand(0, 30);
            }

            $workloads[RedisProbe::class][$name] = static fn (bool $rust) => $rust ? RedisProbe::probeOutcomeBatchNative($pongs, $starts, $ends, $redisErrors) : RedisProbe::probeOutcomeBatchPhp($pongs, $starts, $ends, $redisErrors);
            $workloads[SearchProbe::class][$name] = static fn (bool $rust) => $rust ? SearchProbe::availabilityOutcomeBatchNative($available, $drivers, $starts, $ends, $searchErrors) : SearchProbe::availabilityOutcomeBatchPhp($available, $drivers, $starts, $ends, $searchErrors);
            $workloads[ServiceProbeRegistry::class][$name] = static fn (bool $rust) => $rust ? ServiceProbeRegistry::resolveProbeBatchNative($identifiers) : ServiceProbeRegistry::resolveProbeBatchPhp($identifiers);
            $workloads[SearchCursorCodec::class][$name] = static fn (bool $rust) => $rust ? SearchCursorCodec::decodePayloadBatchNative($payloads, $now) : SearchCursorCodec::decodePayloadBatchPhp($payloads, $now);
            $workloads[SearchFailureTracker::class][$name] = static fn (bool $rust) => $rust ? SearchFailureTracker::failureWindowBatchNative($counts, $baselines, $opened, 5, $now, 60) : SearchFailureTracker::failureWindowBatchPhp($counts, $baselines, $opened, 5, $now, 60);
        }

        foreach ($workloads as $class => $byName) {
            $results = [];
            foreach ($byName as $name => $workload) {
                $this->assertSame($workload(false), $workload(true), "{$class} {$name} benchmark workload must agree");
                for ($w = 0; $w < 20; $w++) {
                    $workload(false);
                    $workload(true);
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach (mt_rand(0, 1) === 1 ? [true, false] : [false, true] as $rust) {
                        $best = PHP_INT_MAX;
                        for ($r = 0; $r < self::REPEATS; $r++) {
                            $t0 = hrtime(true);
                            $workload($rust);
                            $best = min($best, hrtime(true) - $t0);
                        }
                        $times[$rust ? 'rust' : 'php'][] = $best / 1000;
                    }
                }

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, \sprintf(
                "\n[Wave 50.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            $floors[$class] = min($results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']);
        }
        fwrite(STDERR, "\n");

        // Every helper is reported before the gate is asserted; Rust defaults must still clear 1.5x.
        foreach ($floors as $class => $floor) {
            $this->assertSame(self::DEFAULTS[$class], $class::usesW50RustFfi(), $class);
            if (self::DEFAULTS[$class]) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $arguments
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $arguments, ?array $expected): void
    {
        $method = self::METHODS[$class];
        $php = $class::{$method.'Php'}(...$arguments);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class}::{$method}Php");
        }
        $this->assertTrue(array_is_list($php));
        $this->assertSame($php, $class::{$method.'Native'}(...$arguments), "{$class}::{$method}Native ".json_encode($arguments, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $this->assertSame($php, $class::withW50RustFfi(true, static fn () => $class::{$method}(...$arguments)), "{$class}::{$method} (Rust)");
        $this->assertSame($php, $class::withW50RustFfi(false, static fn () => $class::{$method}(...$arguments)), "{$class}::{$method} (PHP)");
    }

    /**
     * @param  array{int, string}  $outcome
     * @return list<int>
     */
    private static function reasonWords(array $outcome, int $elapsed): array
    {
        return [$outcome[0], $elapsed, \strlen($outcome[1]), self::xxh64($outcome[1])];
    }

    /**
     * Minimal valid packet per entry point: `[function, data, ints, words]` for one record; the probe and
     * cursor helpers lead with a count of rows PHP must compute.
     *
     * @return array<class-string, array{string, string, string, int}>
     */
    private static function abiPackets(): array
    {
        return [
            RedisProbe::class => ['nntmux_w505_redis_probe_outcome', 'PONG', pack('P*', 0, 0, 0, 1), 5],
            SearchProbe::class => ['nntmux_w505_search_probe_outcome', 'm', pack('P*', 0, 1, 0, 1), 5],
            ServiceProbeRegistry::class => ['nntmux_w505_service_probe_registry_resolve', 'redis', pack('P', 0), 3],
            SearchCursorCodec::class => ['nntmux_w505_search_cursor_decode', '{"sort":[]}', pack('P', 0), 6],
            SearchFailureTracker::class => ['nntmux_w505_search_failure_window', '', pack('P*', 1, 0, 0, 0, 0, 0), 2],
        ];
    }

    /**
     * @param  class-string  $class
     * @return list<mixed>
     */
    private static function firstArguments(string $class): array
    {
        return match ($class) {
            RedisProbe::class => [[true, 'PONG', 'x', 'PONG'], [0, 0, 0, 0], [1_000_000, 2_000_000, 3_000_000, 0], [3 => 'refused']],
            SearchProbe::class => [[true, false, true], ['manticore', 'es', 'm'], [0, 0, 0], [5_000_000, 0, 0], [2 => 'boom']],
            ServiceProbeRegistry::class => [['redis', 'nope', 'disk']],
            SearchCursorCodec::class => [['{"sort":[1,"a"],"total":3,"expires":99}', 'bad', '{"sort":[],"expires":1}'], 50],
            default => [[0, 3, 6], [0, 2, 6], [0, 990, 900], 3, 1_000, 60],
        };
    }

    /**
     * @param  class-string  $class
     */
    private static function setFfi(string $class, FFI|false|null $ffi): void
    {
        if ($class === SearchCursorCodec::class) {
            (new ReflectionMethod($class, 'w50RustFfiState'))->invoke(null, 'setFfi', $ffi);

            return;
        }
        (new ReflectionClass($class))->setStaticPropertyValue('w50Ffi', $ffi);
    }

    /**
     * @param  class-string  $class
     */
    private static function ffiOf(string $class): mixed
    {
        if ($class === SearchCursorCodec::class) {
            return (new ReflectionMethod($class, 'w50RustFfiState'))->invoke(null, 'ffi');
        }

        return (new ReflectionClass($class))->getStaticPropertyValue('w50Ffi');
    }

    private static function xxh64(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $value, true));

        return $unpacked[1];
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $out .= $byte === "\0" && ! $withNul ? 'n' : $byte;
        }

        return $out;
    }

    private static function randomError(bool $withNul): string
    {
        return match (mt_rand(0, 4)) {
            0 => self::randomBytes(40, $withNul),
            1 => str_repeat('a', mt_rand(110, 125)).str_repeat(' ', mt_rand(0, 6)).str_repeat("\t", mt_rand(0, 2)).'tail',
            2 => str_repeat('x', 119).self::BYTES[mt_rand(0, 12)].'z',
            3 => '',
            default => 'Connection refused '.str_repeat('retry ', mt_rand(0, 30)),
        };
    }

    /**
     * @return array{int, int}
     */
    private static function randomTiming(): array
    {
        return match (mt_rand(0, 5)) {
            0 => [PHP_INT_MIN + mt_rand(0, 10), PHP_INT_MAX - mt_rand(0, 10)],
            1 => [mt_rand(), mt_rand()],
            2 => [0, mt_rand(0, 20) * 1_000_000],
            3 => [mt_rand(-5, 5) * 999_999, mt_rand(-5, 5) * 1_000_001],
            default => [$start = mt_rand(0, PHP_INT_MAX >> 2), $start + mt_rand(0, 900_000_000)],
        };
    }

    private static function edgeInt(int $span): int
    {
        return match (mt_rand(0, 9)) {
            0 => PHP_INT_MIN + mt_rand(0, 3),
            1 => PHP_INT_MAX - mt_rand(0, 3),
            2 => -mt_rand(0, $span),
            default => mt_rand(0, $span),
        };
    }

    /**
     * A JSON-like cursor payload: mostly valid objects with mixed member types, sometimes corrupted.
     */
    private static function randomPayload(int $now, bool $withNul): string
    {
        $scalar = static function (bool $castable = false) use ($withNul): string {
            return match (mt_rand(0, 11)) {
                0 => 'null',
                1 => 'true',
                2 => 'false',
                3 => (string) self::edgeInt(1_000),
                // Integer casts of out-of-range floats warn, so cast fields only see representable ones.
                4 => ['1.5', '-0', '0', '1e2', '-0.0', '-9223372036854775808', '9223372036854775808', '-9223372036854775809'][mt_rand(0, $castable ? 5 : 7)],
                5 => '"'.['é', '😀', '\ud800', '\n', '\"', '\\\\', '\/', '\u0000', '\x', "\t", "\xff", "\xc3\xa9"][mt_rand(0, 11)].'"',
                default => json_encode(self::randomBytes(6, $withNul), JSON_INVALID_UTF8_SUBSTITUTE) ?: '""',
            };
        };
        $value = static function (int $depth) use ($scalar, &$value): string {
            if ($depth < 2 && mt_rand(0, 5) === 0) {
                $items = array_map(static fn (): string => $value($depth + 1), range(0, mt_rand(0, 3)));

                return mt_rand(0, 2) === 0
                    ? '{'.implode(',', array_map(static fn (int $k, string $v): string => '"k'.$k.'":'.$v, array_keys($items), $items)).'}'
                    : '['.implode(',', $items).']';
            }

            return $scalar();
        };

        $members = [];
        foreach (['sort', 'total', 'query', 'driver', 'generation', 'expires', 'extra'] as $key) {
            if (mt_rand(0, 5) === 0) {
                continue;
            }
            $member = match ($key) {
                'sort' => mt_rand(0, 6) === 0 ? $value(1) : '['.implode(',', array_map(static fn (): string => $value(2), range(0, mt_rand(-1, 4)))).']',
                'expires' => mt_rand(0, 4) === 0 ? $scalar(true) : (string) ($now + mt_rand(-3, 3)),
                'total' => mt_rand(0, 2) === 0 ? $scalar(true) : (string) self::edgeInt(500),
                'query', 'driver', 'generation' => mt_rand(0, 4) === 0 ? $value(1) : json_encode(self::randomBytes(10), JSON_INVALID_UTF8_SUBSTITUTE),
                default => $value(1),
            };
            $name = mt_rand(0, 12) === 0 ? json_encode($key, JSON_HEX_TAG) : '"'.$key.'"';
            if ($key !== 'sort' && $name === '"'.$key.'"' && mt_rand(0, 15) === 0) {
                $name = '"\u00'.bin2hex($key[0]).substr($key, 1).'"';
            }
            $members[] = $name.(mt_rand(0, 3) === 0 ? ' : ' : ':').$member;
            if (mt_rand(0, 10) === 0) {
                $members[] = $name.':'.(\in_array($key, ['expires', 'total'], true) ? $scalar(true) : $value(1));
            }
        }
        shuffle($members);
        $json = (mt_rand(0, 4) === 0 ? " \n" : '').'{'.implode(',', $members).'}';

        return match (mt_rand(0, 14)) {
            0 => substr($json, 0, mt_rand(0, \strlen($json))),
            1 => $json.self::randomBytes(2),
            2 => $value(0),
            3 => '['.$json.']',
            default => $json,
        };
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    /**
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        /** @var string $cdef */
        $cdef = (new ReflectionClassConstant($class, 'W50_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('search_w50', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
