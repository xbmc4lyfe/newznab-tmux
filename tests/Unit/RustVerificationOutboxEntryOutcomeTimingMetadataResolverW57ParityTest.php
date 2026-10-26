<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\PublicationOutbox;
use App\Services\Releases\Verification\PublicationOutboxEntry;
use App\Services\Releases\Verification\PublicationOutcome;
use App\Services\Releases\Verification\PublicationTiming;
use App\Services\Releases\Verification\ReleaseMetadataResolver;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustVerificationOutboxEntryOutcomeTimingMetadataResolverW57ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        PublicationOutbox::class => 'classifyEntryWindowBatch',
        PublicationOutboxEntry::class => 'summarizeEntryBatch',
        PublicationOutcome::class => 'tallyOutcomeBatch',
        PublicationTiming::class => 'summarizeTimingBatch',
        ReleaseMetadataResolver::class => 'scoreMetadataBatch',
    ];

    /** Header words before the per-record rows. */
    private const HEADER = [
        PublicationOutbox::class => PublicationOutbox::W57_WINDOW_HEADER,
        PublicationOutboxEntry::class => PublicationOutboxEntry::W57_ENTRY_HEADER,
        PublicationOutcome::class => PublicationOutcome::W57_OUTCOME_HEADER,
        PublicationTiming::class => PublicationTiming::W57_TIMING_HEADER,
        ReleaseMetadataResolver::class => ReleaseMetadataResolver::W57_METADATA_HEADER,
    ];

    /** Output words per record. */
    private const WIDTH = [
        PublicationOutbox::class => PublicationOutbox::W57_WINDOW_WIDTH,
        PublicationOutboxEntry::class => 1,
        PublicationOutcome::class => 1,
        PublicationTiming::class => 1,
        ReleaseMetadataResolver::class => 1,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base 6231e7805 plus
     * this change, seed 576, 50 warmups and 1,000 pairs/workload (w576-benchmark-9d1167f90c859266.json in
     * system tmp). Common mean/p99 then uncommon mean/p99 speedups (PHP time / Rust time); a default is
     * Rust only when all four reach 1.5x. All five classes exceed 1.5x on every metric.
     */
    private const QUALIFICATION = [
        PublicationOutbox::class => [5.067, 3.709, 5.033, 3.483],
        PublicationOutboxEntry::class => [2.711, 2.472, 2.251, 2.016],
        PublicationOutcome::class => [3.156, 2.460, 2.637, 2.074],
        PublicationTiming::class => [4.870, 3.041, 5.727, 3.510],
        ReleaseMetadataResolver::class => [3.613, 3.116, 3.057, 2.718],
    ];

    private const STATUSES = [
        PublicationOutcome::PUBLISHED, PublicationOutcome::FILTERED, PublicationOutcome::METADATA_REVIEW, PublicationOutcome::DEFERRED,
        PublicationOutcome::STALE, PublicationOutcome::PAUSED, PublicationOutcome::RETRY, PublicationOutcome::INVALID,
        PublicationOutcome::ERROR, PublicationOutcome::UNRESOLVED, PublicationOutcome::TRANSPORT, PublicationOutcome::NO_RELEASE,
    ];

    private const CATEGORIES = [2030, 2040, 2045, 2060, 5030, 5040, 5045, 3010, 4050, 6010, 7020, 1000, 10, 20];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW57RustFfi', 'withW57RustFfi', 'w57RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w57RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW57RustFfi', 'withW57RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W57_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w576_', (string) $reflection->getConstant('W57_FFI_CDEF'), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('W57', $property->getName(), $class);
            }
        }
        self::assertTrue((new ReflectionClass(PublicationOutboxEntry::class))->isReadOnly());
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W57_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW57RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW57RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW57RustFfi(! $enabled, static fn (): bool => $class::usesW57RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W57_RUST_FFI_DEFAULT'), $other::usesW57RustFfi());
                        }
                    }

                    return $class::usesW57RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW57RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW57RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW57RustFfi(), $class);
        }
    }

    public function test_existing_behavior_is_unchanged(): void
    {
        self::assertSame('5-2-'.substr(sha1('lease'), 0, 12).'.json', PublicationOutboxEntry::nameFor(5, 2, 'lease'));
        $outcome = new PublicationOutcome(PublicationOutcome::PUBLISHED, 9);
        self::assertTrue($outcome->published());
        self::assertSame(['status' => 'published', 'release_id' => 9, 'error' => null], $outcome->toArray());
        self::assertFalse(PublicationOutcome::isValidStatus('bogus'));
        self::assertSame('a.b', PublicationTiming::phaseKey('b', 'a'));
        self::assertSame(['total_ns' => 6, 'callback_after_state_lock_ns' => 5], PublicationTiming::attemptSummary(['state_lock_acquisition' => 1, 'x' => 5]));
        self::assertSame('raw', ReleaseMetadataResolver::searchName('', 'raw', false));
    }

    public function test_outbox_window_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(PublicationOutbox::class, [[], [], [], [], 100.0, 60.0]);
        self::assertSame([0, -1, -1, -1, -1, 0], [$empty[0], $empty[7], $empty[8], $empty[9], $empty[10], $empty[11]]);
        $out = $this->assertParity(PublicationOutbox::class, [[100.0, 101.0, 102.0, 10.0, 103.0], [0.0, 0.0, 150.0, 0.0, 0.0], [0, 2, 0, 0, 0], [0, 1, 0, 0, 0], 120.0, 60.0, 5.0, 30.0, 2]);
        self::assertSame([5, 4, 1, 1, 2, 1, 3, 3, 1, 0, 2, 30_000, 3], \array_slice($out, 0, 13));
        $base = PublicationOutbox::W57_WINDOW_HEADER;
        self::assertSame(1 | 1 << 3 | 1 << 5 | 2_000 << 26, $out[$base]);
        self::assertSame(35_000, $out[$base + 1]);
        self::assertSame(1 | 4 | 3 << 3 | 1 << 5 | 3 << 6 | 16_000 << 26, $out[$base + 2]);
        $this->assertParity(PublicationOutbox::class, [[NAN, INF, -INF, 0.0], [NAN, -INF, INF, 1e300], [PHP_INT_MAX, -5, 0, 3_000], [PHP_INT_MIN, 0, 7, 1_200], 1e18, -1.0, NAN, INF, 0]);
        $this->assertParity(PublicationOutbox::class, [['a' => 5.0, 'b' => 6.5], [9 => 0.0, 3 => 7.0], [1, 0], [0, 0], 6.75, 1.0, 0.25, 0.5, PHP_INT_MIN]);
        // Mismatched columns decline the native path; the public call still returns PHP's answer.
        self::assertNull(PublicationOutbox::classifyEntryWindowBatchNative([1.0], [], [], [], 1.0, 1.0));
    }

    public function test_outbox_entry_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(PublicationOutboxEntry::class, [[], [], [], [], [], [], []]);
        self::assertSame([0, -1], [$empty[0], $empty[11]]);
        $hash = str_repeat('a', 64);
        $out = $this->assertParity(PublicationOutboxEntry::class, [
            ['12-3-0123456789ab.json', '12-4-0123456789ab.json', 'bad.json', PublicationOutboxEntry::nameFor(1, 2, 'x'), '007-0-ffffffffffff.json'],
            [12, 12, 5, 1, 7], [3, 3, -1, 2, 0],
            [$hash, strtoupper($hash), 'xyz', str_repeat('0', 40), $hash],
            ['token-1', '', 'tok en', "nul\0token", str_repeat('t', 255)],
            [0, 2, -4, 0, PHP_INT_MAX], [1, 0, 0, 0, 0],
        ]);
        self::assertSame([5, 2, 1, 1, 1, 1, 3, 1, 2], \array_slice($out, 0, 9));
        self::assertSame(0xFF | 1 << 9 | 1 << 10 | 7 << 12 | 1 << 20 | 22 << 40, $out[PublicationOutboxEntry::W57_ENTRY_HEADER]);
        self::assertSame(0b10_1101_0110, $out[PublicationOutboxEntry::W57_ENTRY_HEADER + 1] & 0x3FF);
    }

    public function test_outcome_tally_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(PublicationOutcome::class, [[], []]);
        self::assertSame([0, -1, -1, 0, -1], [$empty[0], $empty[23], $empty[24], $empty[25], $empty[26]]);
        $out = $this->assertParity(PublicationOutcome::class, [['published', 'published', 'transport', 'stale', 'bogus', 'no_release'], [42, null, null, 3, null, null]]);
        self::assertSame([1, 4, 1, 2, 1, 1, 1, 1, 1, 4, 2, 166_666, 42], \array_slice($out, 14, 13));
        self::assertSame(1 << 4 | 1 << 5 | 1 << 10 | 9 << 11 | 42 << 27, $out[PublicationOutcome::W57_OUTCOME_HEADER]);
        $this->assertParity(PublicationOutcome::class, [['PUBLISHED', '', "pub\0lished", '0', 'published', str_repeat('x', 70_000)], [1, -9, PHP_INT_MAX, 0, PHP_INT_MIN, 5]]);
    }

    public function test_timing_summary_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(PublicationTiming::class, [[], 0]);
        self::assertSame([0, -1], [$empty[0], $empty[11]]);
        $out = $this->assertParity(PublicationTiming::class, [[500, 5_000_000, -3, 2_000_000_000], 1_000_000]);
        self::assertSame([4, 2, 1, 2_005_000_500, 501_250_125, 0, 2_000_000_000, 500, 2_000_000_000, 2_000_000_000, 500_000, 1], \array_slice($out, 0, 12));
        self::assertSame([2, 0, 0, 0, 1, 0, 0, 1], \array_slice($out, 12, 8));
        $this->assertParity(PublicationTiming::class, [[PHP_INT_MAX, PHP_INT_MIN, 0, 999, 1_000, 999_999_999, 1_000_000_000], -1]);
        $this->assertParity(PublicationTiming::class, [array_fill(0, 4_000, PHP_INT_MAX), PHP_INT_MAX]);
    }

    public function test_metadata_score_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ReleaseMetadataResolver::class, [[], [], [], [], [], []]);
        self::assertSame([0, 0, -1], [$empty[0], $empty[12], $empty[14]]);
        $out = $this->assertParity(ReleaseMetadataResolver::class, [
            [str_repeat('a', 40), 'nope'], [' Some.Release.1080p ', ''], [5040, 10], [1_000, 0], [50, 0], ['2024-02-30 23:59:59', '2024-13-01 00:00:00'],
        ]);
        self::assertSame([2, 1, 1, 0, 1, 1, 2, 1, 1, 1, 1, 7, 583_333, 1_000, 1], \array_slice($out, 0, 15));
        $this->assertParity(ReleaseMetadataResolver::class, [
            [strtoupper(sha1('x')), "\0".str_repeat('a', 39), sha1('y'), ''],
            ["\t\n\0 x \x0B", "a\0b", str_repeat('.', 300), '   '],
            [999, 10_000, -1, PHP_INT_MAX],
            [PHP_INT_MAX, PHP_INT_MAX, -1, 1],
            [1, -1, PHP_INT_MIN, 0],
            ['2024-01-01 24:00:00', '9999-12-31 23:59:59', '0000-01-01 00:00:00', "2024-01-01 00:00:0\0"],
        ]);
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 576];
            yield $short.' uncommon' => [$class, 384, true, 577];
            yield $short.' large common' => [$class, 2_048, false, 578];
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $out = $this->assertParity($class, $this->benchmarkArgs($class, $count, $uncommon, $seed));
        self::assertSame($count, $out[0]);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $checked = array_fill_keys(array_keys(self::WAVE), 0);
        for ($round = 0; $round < 40; $round++) {
            foreach (array_keys(self::WAVE) as $class) {
                $count = (new Randomizer(new Mt19937($round)))->getInt(0, 96);
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_000 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set NNTMUX_W576_BENCH=1): seed 576, 8 fixture variants, 50 warmups and
     * NNTMUX_W576_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 padded, mixed-case,
     * empty, invalid or extreme records. Fixtures are built outside timing and parity is asserted on
     * every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W576_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W576_BENCH=1 to run the paired Wave 57.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W576_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(576));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 576 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $call = $variants[$variant];
                        // The routing scope is set outside the timed region; the public batch call (its own
                        // dispatch, marshaling, FFI and unpacking) is timed inside it.
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW57RustFfi($native, static function () use ($call): array {
                            $start = hrtime(true);
                            $result = $call();

                            return [$result, hrtime(true) - $start];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0] ? 'rust-first' : 'php-first', $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil($pairs * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => intdiv(array_sum($php), $pairs), 'rust_mean_ns' => intdiv(array_sum($rust), $pairs),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 576, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w576-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW57.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW57RustFfi()) {
                self::assertSame($eligible, $class::usesW57RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
            }
        }
    }

    /**
     * Arguments of the class's batch method for one fixture.
     *
     * @param  class-string  $class
     * @return list<mixed>
     */
    private function benchmarkArgs(string $class, int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));

        return match ($class) {
            PublicationOutbox::class => $this->windowBatch($random, $count, $uncommon),
            PublicationOutboxEntry::class => $this->entryBatch($random, $count, $uncommon),
            PublicationOutcome::class => $this->outcomeBatch($random, $count, $uncommon),
            PublicationTiming::class => $this->timingBatch($random, $count, $uncommon),
            default => $this->metadataBatch($random, $count, $uncommon),
        };
    }

    /**
     * @param  class-string  $class
     * @return Closure(): list<int>
     */
    private function benchmarkCall(string $class, int $count, bool $uncommon, int $seed): Closure
    {
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $method = self::WAVE[$class];

        return static fn (): array => $class::$method(...$args);
    }

    /**
     * Pending entries around a forwarder's clock; uncommon ones are expired, backed off far ahead,
     * heavily retried, negative, NaN or infinite, with a small cap.
     *
     * @return list<mixed>
     */
    private function windowBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $now = 1_760_000_000.0 + $random->getFloat(0, 1_000_000);
        $enqueued = $notBefore = $attempts = $deferrals = [];
        for ($i = 0; $i < $count; $i++) {
            $pick = $uncommon ? $random->getInt(0, 9) : 9;
            $enqueued[] = match ($pick) {
                0 => $now - $random->getFloat(3_600, 90_000),
                1 => NAN,
                2 => $random->getInt(0, 1) === 0 ? INF : -INF,
                default => $now - $random->getFloat(0, 900),
            };
            $notBefore[] = match (true) {
                $pick === 3 => $now + $random->getFloat(0, 1e7),
                $pick === 4 => -$random->getFloat(0, 1e300),
                $random->getInt(0, 4) === 0 => $now + $random->getFloat(0, 30),
                default => 0.0,
            };
            $attempts[] = match ($pick) {
                5 => $random->getInt(PHP_INT_MIN, -1),
                6 => $random->getInt(2_147_483_000, PHP_INT_MAX),
                default => $random->getInt(0, 5) === 0 ? $random->getInt(1, 4) : 0,
            };
            $deferrals[] = $pick === 7 ? $random->getInt(1, 5_000) : ($random->getInt(0, 7) === 0 ? 1 : 0);
        }

        return [$enqueued, $notBefore, $attempts, $deferrals, $now, $uncommon ? $random->getFloat(-10, 7_200) : 3_600.0,
            $uncommon ? $random->getFloat(-5, 60) : 5.0, $uncommon ? $random->getFloat(0, 600) : 30.0, $uncommon ? $random->getInt(0, 3) : 50];
    }

    /**
     * Listed outbox entries; uncommon ones have foreign or malformed names, uppercase or short hashes,
     * empty, spaced, NUL-holding or oversized tokens and clamped counts.
     *
     * @return list<list<mixed>>
     */
    private function entryBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = $ids = $versions = $hashes = $tokens = $attempts = $deferrals = [];
        for ($i = 0; $i < $count; $i++) {
            $pick = $uncommon ? $random->getInt(0, 11) : 11;
            $id = $random->getInt(1, 50_000_000);
            $version = $random->getInt(0, 40);
            $token = bin2hex($random->getBytes(16));
            $hash = bin2hex($random->getBytes(32));
            $name = PublicationOutboxEntry::nameFor($id, $version, $token);
            match ($pick) {
                0 => $name = strtoupper($name),
                1 => $name = '0'.$name,
                2 => $name = $id.'-'.($version + 1).'-'.substr(sha1($token), 0, 12).'.json',
                3 => $hash = strtoupper($hash),
                4 => $hash = substr($hash, 0, 40),
                5 => $token = $random->getInt(0, 1) === 0 ? '' : 'a b',
                6 => $token = "lease\0".$token,
                7 => $token = str_repeat('z', $random->getInt(256, 400)),
                8 => $id = -$id,
                9 => $name = substr($random->getBytes(41), 0, $random->getInt(0, 40)),
                default => null,
            };
            $names[] = $name;
            $ids[] = $id;
            $versions[] = $pick === 10 ? -1 : $version;
            $hashes[] = $hash;
            $tokens[] = $token;
            $attempts[] = $pick === 10 ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : ($random->getInt(0, 4) === 0 ? $random->getInt(1, 4) : 0);
            $deferrals[] = $random->getInt(0, 6) === 0 ? $random->getInt(1, 3) : 0;
        }

        return [$names, $ids, $versions, $hashes, $tokens, $attempts, $deferrals];
    }

    /**
     * Batch endpoint outcomes; uncommon ones are mixed-case, unknown, empty, NUL-holding or oversized,
     * with absent, negative or extreme release ids.
     *
     * @return list<list<mixed>>
     */
    private function outcomeBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $statuses = $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $status = self::STATUSES[$random->getInt(0, 3) === 0 ? $random->getInt(0, 11) : [0, 0, 0, 1, 4, 10][$random->getInt(0, 5)]];
            if ($uncommon) {
                $status = match ($random->getInt(0, 7)) {
                    0 => strtoupper($status),
                    1 => ' '.$status,
                    2 => '',
                    3 => $status."\0",
                    4 => bin2hex(substr($random->getBytes(21), 0, $random->getInt(0, 20))),
                    default => $status,
                };
            }
            $ids[] = match (true) {
                $uncommon && $random->getInt(0, 4) === 0 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                $status === PublicationOutcome::PUBLISHED && $random->getInt(0, 9) > 0 => $random->getInt(1, 90_000_000),
                default => null,
            };
            $statuses[] = $status;
        }

        return [$statuses, $ids];
    }

    /**
     * Publication phase durations in ns; uncommon ones are negative, zero, saturated or extreme.
     *
     * @return list<mixed>
     */
    private function timingBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $durations = [];
        for ($i = 0; $i < $count; $i++) {
            $durations[] = match ($uncommon ? $random->getInt(0, 5) : 5) {
                0 => $random->getInt(PHP_INT_MIN, -1),
                1 => 0,
                2 => $random->getInt(1 << 49, PHP_INT_MAX),
                3 => $random->getInt(1_000_000_000, 1 << 45),
                default => $random->getInt(0, 3) === 0 ? $random->getInt(1_000_000, 80_000_000) : $random->getInt(2_000, 900_000),
            };
        }

        return [$durations, $uncommon ? $random->getInt(-1, 1 << 40) : 10_000_000];
    }

    /**
     * Release rows; uncommon ones have uppercase, short or NUL-holding guids, padded or empty names,
     * out-of-range categories, sizes and parts, and malformed postdates.
     *
     * @return list<list<mixed>>
     */
    private function metadataBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $guids = $names = $categories = $sizes = $parts = $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $pick = $uncommon ? $random->getInt(0, 11) : 11;
            $guid = bin2hex($random->getBytes(20));
            $name = 'Some.Show.S'.$random->getInt(1, 20).'E'.$random->getInt(1, 24).'.1080p.WEB.h264-GRP'.$random->getInt(1, 99);
            $date = gmdate('Y-m-d H:i:s', $random->getInt(1_000_000_000, 1_760_000_000));
            $category = self::CATEGORIES[$random->getInt(0, \count(self::CATEGORIES) - 1)];
            $size = $random->getInt(1_000_000, 80_000_000_000);
            $part = $random->getInt(1, 9_000);
            match ($pick) {
                0 => $guid = strtoupper($guid),
                1 => $guid = substr($guid, 0, $random->getInt(0, 39)),
                2 => $guid = "\0".substr($guid, 1),
                3 => $name = " \t".$name."\n",
                4 => $name = $random->getInt(0, 1) === 0 ? '' : " \0 ",
                5 => $category = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                6 => $size = $random->getInt(PHP_INT_MIN, 0),
                7 => $part = $random->getInt(-5, 0),
                8 => $date = ['', '2024-13-01 00:00:00', '2024-01-01T00:00:00', '0000-00-00 00:00:00', '2024-1-1 0:0:0'][$random->getInt(0, 4)],
                9 => $size = $random->getInt(1 << 50, PHP_INT_MAX),
                default => null,
            };
            $guids[] = $guid;
            $names[] = $name;
            $categories[] = $category;
            $sizes[] = $size;
            $parts[] = $part;
            $dates[] = $date;
        }

        return [$guids, $names, $categories, $sizes, $parts, $dates];
    }

    /**
     * PHP routing, Rust routing, the PHP and native methods agree; returns the PHP result.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function assertParity(string $class, array $args): array
    {
        $method = self::WAVE[$class];
        $php = $class::withW57RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW57RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + self::WIDTH[$class] * $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (PublicationOutbox::classifyEntryWindowBatchNative([], [], [], [], 0.0, 0.0) === null
            || PublicationOutboxEntry::summarizeEntryBatchNative([], [], [], [], [], [], []) === null
            || PublicationOutcome::tallyOutcomeBatchNative([], []) === null
            || PublicationTiming::summarizeTimingBatchNative([], 0) === null
            || ReleaseMetadataResolver::scoreMetadataBatchNative([], [], [], [], [], []) === null
        ) {
            self::markTestSkipped('The Wave 57.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
