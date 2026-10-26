<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\SpotCheckVerifier;
use App\Services\Releases\Verification\VerificationLedger;
use App\Services\Releases\Verification\VerificationLockStore;
use App\Services\Releases\Verification\VerificationResult;
use App\Services\Releases\Verification\VerificationRunDiagnostics;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustVerificationSpotCheckVerifierLedgerLockStoreResultDiagnosticsW58ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        SpotCheckVerifier::class => 'classifyResponseBatch',
        VerificationLedger::class => 'summarizeLedgerBatch',
        VerificationLockStore::class => 'classifyLockBatch',
        VerificationResult::class => 'summarizeResultBatch',
        VerificationRunDiagnostics::class => 'summarizeRunBatch',
    ];

    /** Header words before the per-record rows. */
    private const HEADER = [
        SpotCheckVerifier::class => SpotCheckVerifier::W58_SPOT_HEADER,
        VerificationLedger::class => VerificationLedger::W58_LEDGER_HEADER,
        VerificationLockStore::class => VerificationLockStore::W58_LOCK_HEADER,
        VerificationResult::class => VerificationResult::W58_RESULT_HEADER,
        VerificationRunDiagnostics::class => VerificationRunDiagnostics::W58_RUN_HEADER,
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base b58c7f325 plus
     * this change, seed 586, 50 warmups and 1,000 pairs/workload (w586-benchmark-d5f5d280037e1330.json in
     * system tmp, shared host under load). Common mean/p99 then uncommon mean/p99 speedups (PHP time /
     * Rust time); a default is Rust only when all four reach 1.5x. All five classes exceed 1.5x on every
     * metric.
     */
    private const QUALIFICATION = [
        SpotCheckVerifier::class => [4.535, 3.297, 1.794, 2.612],
        VerificationLedger::class => [5.176, 3.831, 3.896, 2.618],
        VerificationLockStore::class => [3.946, 2.610, 3.364, 2.861],
        VerificationResult::class => [2.704, 2.627, 6.926, 5.600],
        VerificationRunDiagnostics::class => [4.898, 4.124, 4.948, 3.550],
    ];

    private const LEDGER_STATES = ['waiting_upload', 'ready', 'verifying', 'verified', 'published', 'dirty', 'expired', 'stale'];

    /** Common ledger transitions [from, to]. */
    private const LEDGER_FLOWS = [
        ['waiting_upload', 'ready'], ['ready', 'verifying'], ['verifying', 'verified'], ['verified', 'published'],
        ['verifying', 'ready'], ['ready', 'stale'], ['stale', 'ready'], ['dirty', 'ready'], ['waiting_upload', 'expired'],
    ];

    private const VERDICTS = ['complete', 'repaired', 'sampled', 'unknown', 'unrepairable'];

    private const REASONS = [
        'Sampled article checks passed; the full payload was not downloaded.',
        'Sampled article checks passed on an incomplete upload: 9 of 10 articles found.',
        'Spot-check transfer budget is exhausted.',
        'Spot-check article identity differs from its header.',
        'Spot-check filenames collide on the storage filesystem.',
        'Spot-check articles expose a missing or overlapping byte range.',
        'Spot-check article was empty; retry verification later.',
        'Inferred article total has no body total or terminal endpoint proof.',
        'Candidate has no positive declared size.',
        '',
    ];

    private const STOP_REASONS = ['limit', 'deadline', 'no_claim', 'draining', 'worker_unavailable', 'initialization_unavailable',
        'initialization_error', 'unsafe_scratch_root', 'error'];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW58RustFfi', 'withW58RustFfi', 'w58RustFfiState', $method, $method.'Php', $method.'Native'] as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w58RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            foreach ([$method, $method.'Php', $method.'Native', 'usesW58RustFfi', 'withW58RustFfi'] as $name) {
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            self::assertTrue($reflection->getReflectionConstant('W58_RUST_FFI_DEFAULT')?->isPrivate() ?? false, $class);
            self::assertStringContainsString('nntmux_w586_', (string) $reflection->getConstant('W58_FFI_CDEF'), $class);
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('W58', $property->getName(), $class);
                self::assertStringNotContainsString('w58', $property->getName(), $class);
            }
        }
        self::assertTrue((new ReflectionClass(VerificationResult::class))->isReadOnly());
        self::assertTrue((new ReflectionClass(VerificationLockStore::class))->isAbstract());
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $expected = (bool) (new ReflectionClass($class))->getConstant('W58_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW58RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW58RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW58RustFfi(! $enabled, static fn (): bool => $class::usesW58RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W58_RUST_FFI_DEFAULT'), $other::usesW58RustFfi());
                        }
                    }

                    return $class::usesW58RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW58RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW58RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW58RustFfi(), $class);
        }
    }

    public function test_existing_behavior_is_unchanged(): void
    {
        $result = new VerificationResult('sampled', 'ok', ['a' => 1], deterministic: true);
        self::assertTrue($result->passedPhp());
        self::assertFalse((new VerificationResult('unknown', 'x'))->passedPhp());
        self::assertSame(['a' => 1], $result->manifest);
        self::assertTrue($result->deterministic);
        self::assertSame('publication:'.(crc32('size:5') % 1024), VerificationLedger::publicationStripePhp('size:5'));
        self::assertSame(['predb:3', 'name:abc', 'size:9'], VerificationLedger::publicationKeysPhp(9, null, 'A.b-C', '', 3));
        self::assertSame('database', VerificationLockStore::resolveDriverPhp('auto', 'mariadb'));
        self::assertSame('file', VerificationLockStore::resolveDriverPhp('auto', 'sqlite'));
        self::assertNull(VerificationLockStore::resolveDriverPhp('bogus', ''));
        self::assertSame('/r/locks', VerificationLockStore::lockPathPhp('/r', true));
        self::assertSame('/d/n.state', VerificationLockStore::stateFilePathPhp('/d', 'n'));
        $diagnostics = new VerificationRunDiagnostics(0, '2026-01-01T00:00:00Z', 2, 10, 1);
        $diagnostics->loopStarted(0);
        $diagnostics->claimed();
        $diagnostics->claimed();
        $diagnostics->stopPhp('bogus', 11_000_000_000);
        $report = $diagnostics->reportPhp(12_000_000_000);
        self::assertSame(['error', true, true], [$report['stop_reason'], $report['limit_reached'], $report['deadline_reached_at_stop']]);
        $thrown = false;
        try {
            SpotCheckVerifier::evidence([]);
        } catch (RuntimeException) {
            $thrown = true;
        }
        self::assertTrue($thrown);
    }

    public function test_spot_response_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(SpotCheckVerifier::class, [[], []]);
        self::assertSame([0, -1, -1, 0, 3], [$empty[0], $empty[9], $empty[10], $empty[11], $empty[12]]);
        $out = $this->assertParity(SpotCheckVerifier::class, [
            ["223 0 <a@b>\r\n", '220 1 <x@y>', '430 no such article', '480 auth', '502', '22x'], [3, 1, 0, 3, 2, 9], 200_000,
        ]);
        self::assertSame([6, 1, 1, 1, 1, 1, 1, 2, 1, 2, 3, 166_666, 2], \array_slice($out, 0, 13));
        self::assertSame(3 << 3 | 1 << 6 | 1 << 7 | 224 << 8 | 13 << 18, $out[SpotCheckVerifier::W58_SPOT_HEADER]);
        self::assertSame(1, $this->assertParity(SpotCheckVerifier::class, [['430'], [3]])[12]);
        self::assertSame(0, $this->assertParity(SpotCheckVerifier::class, [['223 1 <m>', "222\t", '221'], [3, 2, 1], 0])[12]);
        $this->assertParity(SpotCheckVerifier::class, [
            ["223\0<x>", "\0", '', '2', '22', '423', '9999', '-23 x', ' 223', "223\n", str_repeat('2', 70_000), "> <\0", '<>'],
            [PHP_INT_MIN, -1, 4, PHP_INT_MAX, 0, 3, 3, 1, 2, 3, 0, 1, 2], PHP_INT_MIN,
        ]);
        $this->assertParity(SpotCheckVerifier::class, [['a' => '223 0 <z>', 'b' => '430'], [7 => 3, 2 => 3], PHP_INT_MAX]);
        self::assertNull(SpotCheckVerifier::classifyResponseBatchNative(['223'], []));
    }

    public function test_ledger_summary_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(VerificationLedger::class, [[], [], [], [], [], [], 0, 0, 0, 0]);
        self::assertSame([0, 0, -1, -1, -1], [$empty[0], $empty[15], $empty[16], $empty[17], $empty[22]]);
        $out = $this->assertParity(VerificationLedger::class, [
            ['ready', 'ready', 'verified', 'bogus'], ['verifying', 'ready', 'published', 'verified'],
            [3, 0, 9, 1], [5, 0, 1, 0], [100, 0, 50, 7], [40, 30, 20, 0], 100, 50, 10, 3,
        ]);
        self::assertSame([4, 2, 1, 0, 1], \array_slice($out, 0, 5));
        self::assertSame([1, 50, 3, 2, 2, 13, 4, 93, 3, 1, 0], \array_slice($out, 14, 11));
        $this->assertParity(VerificationLedger::class, [
            ['ready', "ready\0", 'READY', '', 'published', 'expired', 'dirty', 'verifying'],
            ['verifying', 'ready', 'ready', 'stale', 'ready', 'ready', 'waiting_upload', 'ready'],
            [PHP_INT_MAX, PHP_INT_MIN, -1, 0, 2_147_483_648, 5, 1, 1],
            [PHP_INT_MAX, 3, PHP_INT_MIN, 0, 9, -2, 1, 1],
            [PHP_INT_MIN, PHP_INT_MAX, 1 << 41, 1, 0, -1, 1 << 40, 99],
            [PHP_INT_MAX, PHP_INT_MIN, 1 << 51, -(1 << 51), -5, 5, PHP_INT_MAX, PHP_INT_MIN],
            PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN,
        ]);
        $this->assertParity(VerificationLedger::class, [['x' => 'ready'], [4 => 'verifying'], [1], [1], [1], [1], PHP_INT_MIN, -1, -1, PHP_INT_MAX]);
        self::assertNull(VerificationLedger::summarizeLedgerBatchNative(['ready'], [], [], [], [], [], 0, 0, 0, 1));
    }

    public function test_lock_status_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(VerificationLockStore::class, [[], [], [], [], [], 0, 0]);
        self::assertSame([0, 0, -1, -1, -1, 0], [$empty[0], $empty[12], $empty[14], $empty[15], $empty[16], $empty[17]]);
        $token = str_repeat('f', 32);
        $out = $this->assertParity(VerificationLockStore::class, [['a', 'g'], [$token, $token], [0, 3], [5_000, 0], [0, 2], 1_000, 60_000]);
        self::assertSame([2, 0, 1, 0, 0, 1], \array_slice($out, 0, 6));
        self::assertSame([0, 0, 4_000], [$out[14], $out[16], $out[17]]);
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $out = $this->assertParity(VerificationLockStore::class, [
            ['publication:12', 'publication', 'worker-3', 'group.a', str_repeat('k', 192), 'a b', '', "n\0ul", 'x'],
            [$uuid, $token, strtoupper($token), $token, $token, $token, $token, $token, '123e4567-e89b-12d3-a456-42661417400-'],
            [1, 2, 0, 3, 0, 1, 2, PHP_INT_MAX, 0],
            [500, 0, 0, 2_000, 0, 0, 0, PHP_INT_MIN, PHP_INT_MAX],
            [0, 0, 0, 0, 0, 0, 0, PHP_INT_MAX, PHP_INT_MIN],
            1_000, 60_000,
        ]);
        self::assertSame([9, 1, 1, 1, 6, 0], \array_slice($out, 0, 6));
        $this->assertParity(VerificationLockStore::class, [['a'], [$token], [0], [1], [1], PHP_INT_MAX, PHP_INT_MIN]);
        $this->assertParity(VerificationLockStore::class, [['k' => 'a', 'l' => 'b'], [9 => $uuid, 3 => ''], [0, 2], [PHP_INT_MAX, 7], [0, 1], -5, 86_400_000]);
        self::assertNull(VerificationLockStore::classifyLockBatchNative(['a'], ['b'], [0], [0], [], 0, 1_000));
    }

    public function test_result_summary_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(VerificationResult::class, [[], [], [], []]);
        self::assertSame([0, -1, 0], [$empty[0], $empty[22], $empty[23]]);
        $out = $this->assertParity(VerificationResult::class, [['sampled', 'unknown'], ['', 'Spot-check article identity differs from its header.'], [3, 0], [0, 4]]);
        self::assertSame([2, 0, 0, 1, 1, 0, 0, 1, 1], \array_slice($out, 0, 9));
        self::assertSame([1, 1, 1, 500_000], [$out[10], $out[16], $out[22], $out[23]]);
        $this->assertParity(VerificationResult::class, [
            ['Complete', "sampled\0", '', 'complete', 'repaired', 'unrepairable', 'unknown', 'unknown', '0'],
            ["retry\0header", 'budget', 'BUDGET', 'collide', 'byte range', str_repeat('h', 70_000).'header', 'x', 'overlapping', "\0"],
            [PHP_INT_MAX, PHP_INT_MIN, 1, 2, 4, 5, 16, 17, -1],
            [PHP_INT_MAX, PHP_INT_MIN, -1, 7, 1, 2, 3, 4, 8],
        ]);
        $this->assertParity(VerificationResult::class, [['a' => 'complete'], [5 => 'ok'], ['q' => 1], [2 => 0]]);
        // An empty first column value next to a NUL-holding one keeps the column boundary.
        $this->assertParity(VerificationResult::class, [[''], ["retry\0"], [1], [0]]);
        $this->assertParity(VerificationResult::class, [['', "\0", ''], ["\0", '', "a\0\0b"], [1, 2, 3], [0, 1, 2]]);
        $this->assertParity(VerificationLedger::class, [[''], ["\0ready"], [1], [1], [1], [1], 9, 9, 9, 9]);
        self::assertNull(VerificationResult::summarizeResultBatchNative(['complete'], ['x'], [1], []));
    }

    public function test_run_diagnostics_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(VerificationRunDiagnostics::class, [[], [], [], [], [], [], []]);
        self::assertSame([0, -1, 0, 9, 0], [$empty[0], $empty[38], $empty[39], $empty[40], $empty[41]]);
        $out = $this->assertParity(VerificationRunDiagnostics::class, [
            ['limit', 'bogus'], [5, 0], [10, 0], [20, 4], [2_000_000_000, -1], [1, 0], [[0, 7, 0, 0, 0, 0, 0, 0, 0], array_fill(0, 9, 0)],
        ]);
        self::assertSame([1, 1, 1, 1, 1, 1, 1], [$out[1], $out[9], $out[10], $out[11], $out[12], $out[13], $out[14]]);
        self::assertSame([1, 1, 0, 2_000_000_000, 1, 125_000], [$out[16], $out[24], $out[38], $out[39], $out[40], $out[41]]);
        $max = array_fill(0, 9, PHP_INT_MAX);
        $this->assertParity(VerificationRunDiagnostics::class, [
            ['LIMIT', "limit\0", '', 'error', 'unsafe_scratch_root'],
            [PHP_INT_MAX, PHP_INT_MIN, 0, 3, 1],
            [PHP_INT_MIN, PHP_INT_MAX, 0, 2, 1],
            [PHP_INT_MAX, 1, 0, PHP_INT_MIN, 3],
            [PHP_INT_MAX, PHP_INT_MIN, 0, 1 << 51, 5],
            [PHP_INT_MAX, 1, -1, 1, 0],
            [$max, array_fill(0, 9, PHP_INT_MIN), [1, 1, 1, 1, 1, 1, 1, 1, 1], ['a' => 1, 'b' => 2, 3, 4, 5, 6, 7, 8, 9], [9, 8, 7, 6, 5, 4, 3, 2, 9]],
        ]);
        $this->assertParity(VerificationRunDiagnostics::class, [array_fill(0, 3_000, 'deadline'), array_fill(0, 3_000, 1), array_fill(0, 3_000, 1),
            array_fill(0, 3_000, 1), array_fill(0, 3_000, PHP_INT_MAX), array_fill(0, 3_000, 1), array_fill(0, 3_000, $max)]);
        self::assertNull(VerificationRunDiagnostics::summarizeRunBatchNative(['limit'], [1], [1], [1], [1], [1], [[1, 2]]));
        // The public call still answers when a short phase row declines the native path.
        self::assertSame(
            VerificationRunDiagnostics::summarizeRunBatchPhp(['limit'], [1], [1], [1], [1], [1], [[1, 2]]),
            VerificationRunDiagnostics::withW58RustFfi(true, static fn (): array => VerificationRunDiagnostics::summarizeRunBatch(['limit'], [1], [1], [1], [1], [1], [[1, 2]])),
        );
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 586];
            yield $short.' uncommon' => [$class, 384, true, 587];
            yield $short.' large common' => [$class, 2_048, false, 588];
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
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_100 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set NNTMUX_W586_BENCH=1): seed 586, 8 fixture variants, 50 warmups and
     * NNTMUX_W586_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 malformed, unknown,
     * NUL-holding, oversized or extreme records. Fixtures are built outside timing and parity is
     * asserted on every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank
     * p99. The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W586_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W586_BENCH=1 to run the paired Wave 58.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W586_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(586));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 586 + $v * 31 + ($uncommon ? 7 : 0));
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
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW58RustFfi($native, static function () use ($call): array {
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
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 586, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w586-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW58.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW58RustFfi()) {
                self::assertSame($eligible, $class::usesW58RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
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
            SpotCheckVerifier::class => $this->spotBatch($random, $count, $uncommon),
            VerificationLedger::class => $this->ledgerBatch($random, $count, $uncommon),
            VerificationLockStore::class => $this->lockBatch($random, $count, $uncommon),
            VerificationResult::class => $this->resultBatch($random, $count, $uncommon),
            default => $this->runBatch($random, $count, $uncommon),
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
     * STAT/HEAD/ARTICLE/BODY responses, mostly present with a few 430s; uncommon ones are malformed,
     * transient, server errors, NUL-holding, empty, oversized or for unknown commands.
     *
     * @return list<mixed>
     */
    private function spotBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $responses = $commands = [];
        for ($i = 0; $i < $count; $i++) {
            $command = $random->getInt(0, 4) === 0 ? $random->getInt(0, 2) : 3;
            $id = '<part'.$random->getInt(1, 9_999).'of'.$random->getInt(1, 9_999).'.'.bin2hex($random->getBytes(6)).'@news.example>';
            $response = match (true) {
                $random->getInt(0, 24) === 0 => ['430 No Such Article Found', '423 No article with that number'][$random->getInt(0, 1)],
                default => (220 + $command).' 0 '.$id.($random->getInt(0, 1) === 0 ? "\r\n" : ''),
            };
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $response = '480 Authentication required',
                    1 => $response = '502 Service unavailable',
                    2 => $response = substr($response, 0, $random->getInt(0, 3)),
                    3 => $response = "223\0".$id,
                    4 => $response = ' '.$response,
                    5 => $response = str_repeat('2', $random->getInt(3, 900)),
                    6 => $command = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $response = '400 '.str_repeat('x', $random->getInt(0, 2_000)),
                    8 => $response = substr($random->getBytes(32), 0, $random->getInt(0, 32)),
                    default => null,
                };
            }
            $responses[] = $response;
            $commands[] = $command;
        }

        return [$responses, $commands, $uncommon ? $random->getInt(-1, 1_000_000) : 50_000];
    }

    /**
     * Candidate ledger transitions with capacity deltas; uncommon ones are forbidden, unknown,
     * NUL-holding or mixed-case states with extreme counts, timestamps and deltas.
     *
     * @return list<mixed>
     */
    private function ledgerBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $now = 1_760_000_000 + $random->getInt(0, 1_000_000);
        $from = $to = $attempts = $failures = $updated = $deltas = [];
        for ($i = 0; $i < $count; $i++) {
            [$source, $target] = self::LEDGER_FLOWS[$random->getInt(0, \count(self::LEDGER_FLOWS) - 1)];
            $tries = $random->getInt(0, 3) === 0 ? $random->getInt(1, 6) : 0;
            $failed = $tries > 0 ? $random->getInt(0, $tries) : 0;
            $stamp = $now - $random->getInt(0, 86_400);
            $delta = $random->getInt(0, 1) === 0 ? $random->getInt(1_000_000, 8_000_000_000) : -$random->getInt(0, 8_000_000_000);
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $target = self::LEDGER_STATES[$random->getInt(0, 7)],
                    1 => $source = strtoupper($source),
                    2 => $target = $target."\0",
                    3 => $source = '',
                    4 => $tries = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    5 => $failed = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    6 => $stamp = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $stamp = 0,
                    8 => $delta = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    9 => $stamp = $now + $random->getInt(1, 1_000_000),
                    default => null,
                };
            }
            $from[] = $source;
            $to[] = $target;
            $attempts[] = $tries;
            $failures[] = $failed;
            $updated[] = $stamp;
            $deltas[] = $delta;
        }

        return [$from, $to, $attempts, $failures, $updated, $deltas, $uncommon ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $now,
            $uncommon ? $random->getInt(-1, 1 << 45) : 500_000_000_000, $uncommon ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : 100_000_000_000,
            $uncommon ? $random->getInt(PHP_INT_MIN, 10) : 5];
    }

    /**
     * Publication stripes, worker mutexes and groups with hex or UUID tokens and live, expiring or
     * expired leases; uncommon ones have invalid, empty, oversized or NUL-holding keys and tokens,
     * unknown modes and extreme expiries and holders.
     *
     * @return list<mixed>
     */
    private function lockBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $now = 1_760_000_000_000 + $random->getInt(0, 1_000_000_000);
        $names = $tokens = $modes = $expires = $holders = [];
        for ($i = 0; $i < $count; $i++) {
            $mode = [0, 1, 1, 1, 2, 3][$random->getInt(0, 5)];
            $name = match ($mode) {
                0 => 'publication:'.$random->getInt(0, 1_023),
                3 => 'verification-drain',
                default => 'verification.worker-'.$random->getInt(0, 64),
            };
            $hex = bin2hex($random->getBytes(16));
            $token = $random->getInt(0, 3) === 0 ? substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12) : $hex;
            $expiry = match ($random->getInt(0, 3)) {
                0 => 0,
                1 => $now - $random->getInt(0, 120_000),
                default => $now + $random->getInt(1, 120_000),
            };
            $holding = $mode === 0 ? 0 : $random->getInt(0, 6);
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $name = '',
                    1 => $name = str_repeat('k', $random->getInt(192, 400)),
                    2 => $name = "lock\0".$name,
                    3 => $name = 'lock name/'.$i,
                    4 => $token = strtoupper($token),
                    5 => $token = '',
                    6 => $token = substr($token, 0, $random->getInt(1, 31)),
                    7 => $mode = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    8 => $expiry = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    9 => $holding = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $names[] = $name;
            $tokens[] = $token;
            $modes[] = $mode;
            $expires[] = $expiry;
            $holders[] = $holding;
        }

        return [$names, $tokens, $modes, $expires, $holders, $uncommon ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) : $now,
            $uncommon ? $random->getInt(-1_000, 100_000_000) : 300_000];
    }

    /**
     * Verifier results with their real reasons; uncommon ones have mixed-case, unknown or NUL-holding
     * verdicts, empty or oversized reasons and extreme article counts and flags.
     *
     * @return list<mixed>
     */
    private function resultBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $verdicts = $reasons = $articles = $flags = [];
        for ($i = 0; $i < $count; $i++) {
            $passed = $random->getInt(0, 3) > 0;
            $verdict = $passed ? ['sampled', 'sampled', 'complete', 'repaired'][$random->getInt(0, 3)] : ['unknown', 'unknown', 'unrepairable'][$random->getInt(0, 2)];
            $reason = $passed ? self::REASONS[$random->getInt(0, 1)] : self::REASONS[$random->getInt(2, 9)];
            $sampled = $random->getInt(1, 16);
            $flag = $passed ? 0 : [0, 1, 2, 4, 4][$random->getInt(0, 4)];
            if ($uncommon) {
                match ($random->getInt(0, 9)) {
                    0 => $verdict = strtoupper($verdict),
                    1 => $verdict = $verdict."\0",
                    2 => $verdict = self::VERDICTS[$random->getInt(0, 4)].'x',
                    3 => $reason = '',
                    4 => $reason = str_repeat('r', $random->getInt(1_000, 70_000)).' header',
                    5 => $sampled = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    6 => $flag = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $reason = "retry\0".$reason,
                    default => null,
                };
            }
            $verdicts[] = $verdict;
            $reasons[] = $reason;
            $articles[] = $sampled;
            $flags[] = $flag;
        }

        return [$verdicts, $reasons, $articles, $flags];
    }

    /**
     * Command runs with their stop reason, counters and phase durations; uncommon ones have unknown,
     * mixed-case or NUL-holding reasons, unstarted loops and extreme counters and durations.
     *
     * @return list<mixed>
     */
    private function runBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $reasons = $claims = $limits = $calls = $loops = $seconds = $phases = [];
        for ($i = 0; $i < $count; $i++) {
            $reason = self::STOP_REASONS[$random->getInt(0, 3) === 0 ? $random->getInt(0, 8) : $random->getInt(0, 2)];
            $limit = [50, 100, 200][$random->getInt(0, 2)];
            $claimed = $random->getInt(0, $limit);
            $called = $claimed + $random->getInt(0, 20);
            $phase = [];
            for ($p = 0; $p < 9; $p++) {
                $phase[] = $random->getInt(0, 2) === 0 ? 0 : $random->getInt(100_000, 9_000_000_000);
            }
            $loop = array_sum($phase) + $random->getInt(0, 5_000_000_000);
            $maximum = [0, 300, 600, 3_600][$random->getInt(0, 3)];
            if ($uncommon) {
                match ($random->getInt(0, 11)) {
                    0 => $reason = strtoupper($reason),
                    1 => $reason = 'unknown_reason',
                    2 => $reason = $reason."\0",
                    3 => $loop = -1,
                    4 => $loop = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    5 => $claimed = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    6 => $called = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    7 => $phase[$random->getInt(0, 8)] = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    8 => $maximum = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    9 => $limit = $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    default => null,
                };
            }
            $reasons[] = $reason;
            $claims[] = $claimed;
            $limits[] = $limit;
            $calls[] = $called;
            $loops[] = $loop;
            $seconds[] = $maximum;
            $phases[] = $phase;
        }

        return [$reasons, $claims, $limits, $calls, $loops, $seconds, $phases];
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
        $php = $class::withW58RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW58RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (SpotCheckVerifier::classifyResponseBatchNative([], []) === null
            || VerificationLedger::summarizeLedgerBatchNative([], [], [], [], [], [], 0, 0, 0, 1) === null
            || VerificationLockStore::classifyLockBatchNative([], [], [], [], [], 0, 0) === null
            || VerificationResult::summarizeResultBatchNative([], [], [], []) === null
            || VerificationRunDiagnostics::summarizeRunBatchNative([], [], [], [], [], [], []) === null
        ) {
            self::markTestSkipped('The Wave 58.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
