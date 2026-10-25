<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\CollectionReleasePolicy;
use App\Services\Releases\Verification\ManifestStore;
use App\Services\Releases\Verification\PreparedCandidateUpdate;
use App\Services\Releases\Verification\PublicationPrelock;
use App\Services\Releases\Verification\VerificationResetService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class RustVerificationStorePrelockManifestResetW39ParityTest extends TestCase
{
    /** Helper name => owning class. Each class owns its separate Wave 39.6 toggle. */
    private const HELPERS = [
        'summarizeEligibilityWindow' => CollectionReleasePolicy::class,
        'summarizeManifestStorageBatch' => ManifestStore::class,
        'validateUpdateTransition' => PreparedCandidateUpdate::class,
        'planPrelockLeaseWindow' => PublicationPrelock::class,
        'summarizeResetScopePlan' => VerificationResetService::class,
    ];

    private const RANDOM_CASES = 1_500;

    private const EDGE_INTS = [PHP_INT_MIN, PHP_INT_MIN + 1, -1_000, -1, 0, 1, 2, 5, 999, 1000, 1001, PHP_INT_MAX - 1, PHP_INT_MAX];

    private const STATES = ['dirty', 'stale', 'expired', 'unknown', 'verifying', 'ready', 'waiting_upload', 'metadata_review', 'READY', ' dirty', '', "ready\0", 'é', null, 7, 1.5, true, ['x']];

    /** @var array<string, Closure> */
    private array $closures = [];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as $class) {
            $class::resetW396RustFfi();
        }
        parent::tearDown();
    }

    public function test_rust_ffi_defaults_follow_the_benchmark_and_toggle_independently(): void
    {
        foreach (self::HELPERS as $class) {
            // None of the five helpers clears 1.5x on every required workload (see the benchmark test).
            $this->assertFalse($this->defaultFor($class), $class);
            $this->assertFalse($class::usesW396RustFfi(), $class);
        }
        foreach (self::HELPERS as $class) {
            $class::useW396RustFfi(true);
            $this->assertTrue($class::usesW396RustFfi(), $class);
            foreach (self::HELPERS as $other) {
                if ($other !== $class) {
                    $this->assertFalse($other::usesW396RustFfi(), $other.' must not follow '.$class);
                }
            }
            $class::useW396RustFfi(false);
            $this->assertFalse($class::usesW396RustFfi(), $class);
            $class::useW396RustFfi(true);
            $class::resetW396RustFfi();
            $this->assertFalse($class::usesW396RustFfi(), $class);
        }
        $this->assertNull((new ReflectionProperty(CollectionReleasePolicy::class, 'w396Ffi'))->getValue());

        // The Wave 39.6 toggles are separate from the earlier helpers' toggles on these classes.
        PreparedCandidateUpdate::useW396RustFfi(true);
        PublicationPrelock::useW396RustFfi(true);
        VerificationResetService::useW396RustFfi(true);
        $this->assertFalse(PreparedCandidateUpdate::usesRustFfi());
        $this->assertFalse(PublicationPrelock::usesRustFfi());
        $this->assertFalse(VerificationResetService::usesRustFfi());
        PreparedCandidateUpdate::setUseRustFfi(true);
        PreparedCandidateUpdate::resetW396RustFfi();
        $this->assertTrue(PreparedCandidateUpdate::usesRustFfi());
        PreparedCandidateUpdate::setUseRustFfi(null);
        $this->assertTrue((new ReflectionProperty(CollectionReleasePolicy::class, 'useRustFfi'))->getValue());
        $this->assertTrue((new ReflectionProperty(ManifestStore::class, 'useRustFfi'))->getValue());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(['total_parts' => 200, 'available_parts' => 191, 'completion_permille' => 955, 'age_state' => 'eligible', 'completion_met' => true, 'action' => 'release'],
            CollectionReleasePolicy::summarizeEligibilityWindow(200, 191, 3600, 600, 86_400));
        $this->assertSame(['total_parts' => 200, 'available_parts' => 189, 'completion_permille' => 945, 'age_state' => 'eligible', 'completion_met' => false, 'action' => 'wait'],
            CollectionReleasePolicy::summarizeEligibilityWindow(200, 189, 3600, 600, 86_400));
        $this->assertSame(['too_young', 'wait'], array_values(array_intersect_key(CollectionReleasePolicy::summarizeEligibilityWindow(10, 10, 5, 600, 0), ['age_state' => 1, 'action' => 1])));
        $this->assertSame(['expired', 'discard'], array_values(array_intersect_key(CollectionReleasePolicy::summarizeEligibilityWindow(10, 2, 100, 0, 50, 900), ['age_state' => 1, 'action' => 1])));
        $this->assertSame('release', CollectionReleasePolicy::summarizeEligibilityWindow(10, 10, 100, 0, 50)['action']);
        $this->assertSame(['total_parts' => 0, 'available_parts' => 0, 'completion_permille' => 0, 'age_state' => 'eligible', 'completion_met' => false, 'action' => 'wait'],
            CollectionReleasePolicy::summarizeEligibilityWindow(-4, 5, 0, -9, 0, 0));
        // Exact permille past PHP_INT_MAX / 1000, where the product would overflow.
        $this->assertSame(999, CollectionReleasePolicy::summarizeEligibilityWindow(PHP_INT_MAX, PHP_INT_MAX - 1, 0, 0, 0)['completion_permille']);
        $this->assertSame(1000, CollectionReleasePolicy::summarizeEligibilityWindow(PHP_INT_MAX, PHP_INT_MAX, 0, 0, 0)['completion_permille']);
        $this->assertSame(500, CollectionReleasePolicy::summarizeEligibilityWindow(2 * intdiv(PHP_INT_MAX, 2), intdiv(PHP_INT_MAX, 2), 0, 0, 0)['completion_permille']);

        $this->assertSame(['items' => 7, 'non_empty' => 4, 'empty' => 3, 'accepted_bytes' => 90, 'exceeded_index' => 5, 'accepted' => 2],
            ManifestStore::summarizeManifestStorageBatch([40, 0, 50, '9', -3, 20, 1], 100));
        $this->assertSame(['items' => 0, 'non_empty' => 0, 'empty' => 0, 'accepted_bytes' => 0, 'exceeded_index' => -1, 'accepted' => 0],
            ManifestStore::summarizeManifestStorageBatch([]));
        $this->assertSame(['items' => 2, 'non_empty' => 2, 'empty' => 0, 'accepted_bytes' => PHP_INT_MAX, 'exceeded_index' => 1, 'accepted' => 1],
            ManifestStore::summarizeManifestStorageBatch(['a' => PHP_INT_MAX, 'b' => 1], PHP_INT_MAX));
        $this->assertSame(0, ManifestStore::summarizeManifestStorageBatch([1], PHP_INT_MIN)['exceeded_index']);

        $this->assertSame(['allowed' => true, 'terminal_target' => false, 'attempts_exhausted' => false, 'next_attempt_count' => 3, 'reason' => 'none'],
            PreparedCandidateUpdate::validateUpdateTransition(0, 1, 2));
        $this->assertSame(['allowed' => true, 'terminal_target' => true, 'attempts_exhausted' => false, 'next_attempt_count' => 0, 'reason' => 'none'],
            PreparedCandidateUpdate::validateUpdateTransition(2, 4, -9, 0));
        $reasons = [];
        foreach ([[7, 1, 0], [4, 5, 0], [1, 1, 0], [0, 2, 0], [3, 1, 5], [1, 3, 5], [1, 5, 5]] as [$current, $target, $attempts]) {
            $reasons[] = PreparedCandidateUpdate::validateUpdateTransition($current, $target, $attempts)['reason'];
        }
        $this->assertSame(['unknown_status', 'terminal_current', 'unchanged', 'not_permitted', 'attempts_exhausted', 'attempts_exhausted', 'none'], $reasons);
        $allowed = [];
        foreach (array_keys(PreparedCandidateUpdate::W396_STATUSES) as $current) {
            foreach (array_keys(PreparedCandidateUpdate::W396_STATUSES) as $target) {
                if (PreparedCandidateUpdate::validateUpdateTransition($current, $target, 0)['allowed']) {
                    $allowed[] = "{$current}>{$target}";
                }
            }
        }
        $this->assertSame(['0>1', '0>6', '1>2', '1>3', '1>5', '1>6', '2>3', '2>4', '2>5', '3>1', '3>5', '3>6'], $allowed);

        $this->assertSame(['expires_at_ms' => 31_000, 'remaining_ms' => 4_000, 'expired' => false, 'needs_renewal' => true, 'valid' => true],
            PublicationPrelock::planPrelockLeaseWindow(1_000, 30_000, 27_000));
        $this->assertSame(['expires_at_ms' => 31_000, 'remaining_ms' => 0, 'expired' => true, 'needs_renewal' => false, 'valid' => false],
            PublicationPrelock::planPrelockLeaseWindow(1_000, 30_000, 31_000));
        $this->assertSame(['expires_at_ms' => 15, 'remaining_ms' => 15, 'expired' => false, 'needs_renewal' => false, 'valid' => false],
            PublicationPrelock::planPrelockLeaseWindow(10, 5, 0, 0));
        $this->assertSame(['expires_at_ms' => PHP_INT_MAX, 'remaining_ms' => PHP_INT_MAX, 'expired' => false, 'needs_renewal' => false, 'valid' => false],
            PublicationPrelock::planPrelockLeaseWindow(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, 0));
        $this->assertTrue(PublicationPrelock::planPrelockLeaseWindow(0, -5, 0, -5)['expired']);

        $this->assertSame(['inspected' => 6, 'eligible' => 2, 'skipped_in_flight' => 1, 'skipped_verified' => 1, 'skipped_other' => 2, 'chunks' => 1],
            VerificationResetService::summarizeResetScopePlan(['dirty', 'verifying', 'ready', 'waiting_upload', 'stale', null]));
        $this->assertSame(['inspected' => 3, 'eligible' => 3, 'skipped_in_flight' => 0, 'skipped_verified' => 0, 'skipped_other' => 0, 'chunks' => 2],
            VerificationResetService::summarizeResetScopePlan(['x' => 'verifying', 'expired', 'unknown'], true, 2));
        $this->assertSame(1, VerificationResetService::summarizeResetScopePlan(['dirty'], false, PHP_INT_MAX)['chunks']);
        $this->assertSame(3, VerificationResetService::summarizeResetScopePlan(['dirty', 'dirty', 'dirty'], false, PHP_INT_MIN)['chunks']);
        $this->assertSame(0, VerificationResetService::summarizeResetScopePlan(['ready', 'READY'], true, 1)['chunks']);
    }

    public function test_edge_case_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $ints = self::EDGE_INTS;
        $count = count($ints);
        foreach ($ints as $i => $a) {
            foreach ($ints as $j => $b) {
                $c = $ints[($i + $j) % $count];
                $d = $ints[($i * 3 + $j) % $count];
                $e = $ints[($i + 2 * $j) % $count];
                $f = $ints[($i * 5 + $j * 7) % $count];
                $this->assertHelperParity('summarizeEligibilityWindow', [$a, $b, $c, $d, $e, $f]);
                $this->assertHelperParity('planPrelockLeaseWindow', [$a, $b, $c, $d]);
                $this->assertHelperParity('summarizeManifestStorageBatch', [[$a, $b, $c, 'x', null, 1.0], $d]);
            }
        }
        foreach ([-1, 0, 1, 2, 3, 4, 5, 6, 7, PHP_INT_MIN, PHP_INT_MAX] as $current) {
            foreach ([-1, 0, 1, 2, 3, 4, 5, 6, 7, PHP_INT_MIN, PHP_INT_MAX] as $target) {
                foreach ([[0, 5], [4, 5], [5, 5], [PHP_INT_MAX - 1, PHP_INT_MAX], [PHP_INT_MAX, PHP_INT_MIN], [-3, 0]] as [$attempts, $max]) {
                    $this->assertHelperParity('validateUpdateTransition', [$current, $target, $attempts, $max]);
                }
            }
        }
        $this->assertHelperParity('summarizeManifestStorageBatch', [[], 0]);
        $this->assertHelperParity('summarizeManifestStorageBatch', [['k' => 5, 9 => PHP_INT_MAX], PHP_INT_MAX]);
        foreach ([[], self::STATES, array_fill(0, 1001, 'dirty'), ['verifying', 'verifying']] as $states) {
            foreach ([PHP_INT_MIN, -1, 0, 1, 2, 500, PHP_INT_MAX] as $chunk) {
                $this->assertHelperParity('summarizeResetScopePlan', [$states, false, $chunk]);
                $this->assertHelperParity('summarizeResetScopePlan', [$states, true, $chunk]);
            }
        }
    }

    public function test_randomized_parity_between_php_and_rust(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3961));
        $int = fn (): int => match ($random->getInt(0, 3)) {
            0 => self::EDGE_INTS[$random->getInt(0, count(self::EDGE_INTS) - 1)],
            1 => $random->getInt(-10, 1_100),
            2 => $random->getInt(0, 1 << 40),
            default => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
        };
        for ($i = 0; $i < self::RANDOM_CASES; $i++) {
            $total = $int();
            $this->assertHelperParity('summarizeEligibilityWindow', [$total, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, max(0, $total)), $int(), $int(), $int(), $int()]);
            $now = $int();
            $this->assertHelperParity('planPrelockLeaseWindow', [$random->getInt(0, 1) === 0 || $now < PHP_INT_MIN + 60_000 ? $int() : $now - $random->getInt(0, 60_000), $int(), $now, $int()]);
            $this->assertHelperParity('validateUpdateTransition', [$random->getInt(-1, 7), $random->getInt(-1, 7), $random->getInt(0, 2) === 0 ? $int() : $random->getInt(0, 8),
                $random->getInt(0, 2) === 0 ? $int() : $random->getInt(0, 8)]);

            $sizes = [];
            for ($n = $random->getInt(0, 40); $n > 0; $n--) {
                $sizes[] = match ($random->getInt(0, 9)) {
                    0 => [null, '12', 3.0, false, []][$random->getInt(0, 4)],
                    1 => $int(),
                    2 => 0,
                    default => $random->getInt(1, 4_000_000),
                };
            }
            $this->assertHelperParity('summarizeManifestStorageBatch', [$sizes, $random->getInt(0, 3) === 0 ? $int() : $random->getInt(0, 67_108_864)]);

            $states = [];
            for ($n = $random->getInt(0, 60); $n > 0; $n--) {
                $states[] = $random->getInt(0, 9) === 0 ? $random->getBytes($random->getInt(1, 9)) : self::STATES[$random->getInt(0, count(self::STATES) - 1)];
            }
            $this->assertHelperParity('summarizeResetScopePlan', [$states, (bool) $random->getInt(0, 1), $random->getInt(0, 3) === 0 ? $int() : $random->getInt(1, 64)]);
        }
    }

    public function test_direct_ffi_export_validates_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();
        $out = $ffi->new('char[64]');
        FFI::memset($out, ord('Z'), 64);
        $input = pack('q4', 0, 1, 2, 5);

        $this->assertSame(-40, $this->callFfi($ffi, 3, 0, $input, 32, $out, 39));
        $this->assertSame(-40, $this->callFfi($ffi, 3, 0, $input, 32, $out, 0));
        foreach ([[0, 0], [6, 0], [3, 1], [1, 1], [5, 2], [255, 0]] as [$operation, $flags]) {
            $this->assertSame(-1, $this->callFfi($ffi, $operation, $flags, $input, 32, $out, 64), "op {$operation} flags {$flags}");
        }
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, null, 32, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 32, null, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 31, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input.'x', 33, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 16_777_217, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 3, 0, $input, 32, $out, 16_777_217));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q2', 0, -1), 16, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 2, 0, pack('q3', 0, 2, 1), 24, $out, 64));
        $this->assertSame(-1, $this->callFfi($ffi, 5, 0, pack('qV', 1, 0xFFFFFFFF), 12, $out, 64));
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));

        $this->assertSame(40, $this->callFfi($ffi, 3, 0, $input, 32, $out, 64));
        $this->assertSame(pack('q5', 1, 0, 0, 3, 0).str_repeat('Z', 24), FFI::string($out, 64));

        // A short buffer reports the exact size; retrying with that capacity succeeds without overrunning it.
        $reset = pack('qV', 2, 3).pack('V', 5).'dirty'.pack('V', 9).'verifying'.pack('V', 0);
        FFI::memset($out, ord('Z'), 64);
        $required = $this->callFfi($ffi, 5, 1, $reset, strlen($reset), $out, 8);
        $this->assertSame(-48, $required);
        $this->assertSame(str_repeat('Z', 64), FFI::string($out, 64));
        $this->assertSame(48, $this->callFfi($ffi, 5, 1, $reset, strlen($reset), $out, -$required));
        $this->assertSame(pack('q6', 3, 2, 0, 0, 1, 1).str_repeat('Z', 16), FFI::string($out, 64));

        foreach (self::HELPERS as $class) {
            $class::useW396RustFfi(true);
        }
        CollectionReleasePolicy::summarizeEligibilityWindow(1, 1, 1, 0, 0);
        $this->assertInstanceOf(FFI::class, (new ReflectionProperty(CollectionReleasePolicy::class, 'w396Ffi'))->getValue());
        $this->assertNotNull(ManifestStore::summarizeManifestStorageBatchNative([1]));
        $this->assertNotNull(PreparedCandidateUpdate::validateUpdateTransitionNative(0, 1, 0));
        $this->assertNotNull(PublicationPrelock::planPrelockLeaseWindowNative(0, 1, 0));
        $this->assertNotNull(VerificationResetService::summarizeResetScopePlanNative(['dirty']));
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $cases = [
            'summarizeEligibilityWindow' => [100, 96, 700, 600, 0],
            'summarizeManifestStorageBatch' => [[10, 0, 'x', 20], 25],
            'validateUpdateTransition' => [1, 2, 0],
            'planPrelockLeaseWindow' => [0, 10_000, 6_000],
            'summarizeResetScopePlan' => [['dirty', 'verifying', 'ready'], true, 1],
        ];
        foreach (self::HELPERS as $class) {
            $class::useW396RustFfi(true);
        }
        (new ReflectionProperty(CollectionReleasePolicy::class, 'w396Ffi'))->setValue(null, false);
        foreach ($cases as $helper => $args) {
            $class = self::HELPERS[$helper];
            $this->assertNull($this->helper($class, $helper.'Native')(...$args), $helper);
            $this->assertSame($this->helper($class, $helper.'Php')(...$args), $class::$helper(...$args), $helper);
        }
        $this->assertNull(CollectionReleasePolicy::w396Slots(1, 0, str_repeat("\0", 16_777_217), 6));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of each helper through its public dispatcher, including
        // frame packing, the FFI call and decoding (release dylib, 5 warmups, 201 samples x 20
        // iterations). Common = one eligibility window, a 16-manifest batch, one transition, one lease
        // and a 64-status reset scope; uncommon = overflow-range eligibility, a 2,000-manifest batch with
        // non-integer and non-positive sizes, an exhausted transition, a saturating lease and a
        // 5,000-status reset scope with non-strings and unknown statuses.
        // Speedup = PHP latency / Rust latency per workload pass, common mean/p99 then uncommon mean/p99
        // (PHP 8.5.12 NTS, Apple M5, release dylib, 3 rounds):
        // - Round 1: eligibility 0.38x/0.35x, 0.58x/0.54x; manifest 0.41x/0.58x, 0.88x/1.16x;
        //   transition 0.38x/0.29x, 0.37x/0.34x; lease 0.28x/0.14x, 0.29x/0.33x; reset 0.43x/0.36x, 0.57x/0.62x
        // - Round 2: eligibility 0.39x/0.38x, 0.62x/0.70x; manifest 0.41x/0.33x, 0.90x/0.88x;
        //   transition 0.34x/0.31x, 0.34x/0.49x; lease 0.30x/0.52x, 0.34x/0.62x; reset 0.45x/0.54x, 0.57x/0.62x
        // - Round 3: eligibility 0.40x/0.63x, 0.60x/0.65x; manifest 0.39x/0.44x, 0.86x/0.92x;
        //   transition 0.33x/0.27x, 0.34x/0.44x; lease 0.30x/0.47x, 0.32x/0.64x; reset 0.44x/0.44x, 0.58x/0.54x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustVerificationStorePrelockManifestResetW39ParityTest.php --filter benchmark
        // These scalar plans do too little work to repay frame packing and the FFI round trip, so no
        // helper clears the 1.5x bar in .ai/rules/rust-performance.md and every W396_RUST_FFI_DEFAULT stays false.
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3962));
        $workloads = ['common' => $this->commonWorkload($random), 'uncommon' => $this->uncommonWorkload($random)];
        $warmup = 5;
        $samples = 201;
        $iterations = 20;
        $sink = 0;

        $speedups = [];
        foreach (self::HELPERS as $helper => $class) {
            foreach ($workloads as $name => $workload) {
                $args = $workload[$helper];
                $this->assertHelperParity($helper, $args);
                for ($w = 0; $w < $warmup; $w++) {
                    foreach ([false, true] as $useRust) {
                        $class::useW396RustFfi($useRust);
                        $sink += count($class::$helper(...$args));
                    }
                }
                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < $samples; $s++) {
                    foreach ($random->getInt(0, 1) === 1 ? [true, false] : [false, true] as $useRust) {
                        $class::useW396RustFfi($useRust);
                        $start = hrtime(true);
                        for ($i = 0; $i < $iterations; $i++) {
                            $sink += count($class::$helper(...$args));
                        }
                        $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                    }
                }
                $class::useW396RustFfi(null);
                $speedups[$helper][$name] = [
                    'mean' => round($this->mean($times['php']) / $this->mean($times['rust']), 3),
                    'p99' => round($this->p99($times['php']) / $this->p99($times['rust']), 3),
                    'samples' => $samples,
                ];
            }
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustVerificationStorePrelockManifestResetW39 speedup: {$summary}\n");
        foreach (self::HELPERS as $helper => $class) {
            $speedup = $speedups[$helper];
            if (min($speedup['common']['mean'], $speedup['common']['p99'], $speedup['uncommon']['mean'], $speedup['uncommon']['p99']) < 1.5) {
                $this->assertFalse($this->defaultFor($class), $class.' must keep PHP below 1.5x: '.$summary);
            }
            foreach ($speedup as $name => $values) {
                $this->assertGreaterThan(0.0, $values['mean'], $helper.' '.$name);
                $this->assertGreaterThan(0.0, $values['p99'], $helper.' '.$name);
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     */
    private function assertHelperParity(string $helper, array $args): void
    {
        $class = self::HELPERS[$helper];
        $expected = $this->helper($class, $helper.'Php')(...$args);
        $context = $helper.' '.substr(bin2hex(serialize($args)), 0, 400);
        $this->assertSame($expected, $this->helper($class, $helper.'Native')(...$args), $context);
        foreach ([true, false] as $useRust) {
            $class::useW396RustFfi($useRust);
            $this->assertSame($expected, $class::$helper(...$args), $context);
        }
        $class::useW396RustFfi(null);
    }

    private function defaultFor(string $class): bool
    {
        return (bool) constant($class.'::W396_RUST_FFI_DEFAULT');
    }

    private function helper(string $class, string $method): Closure
    {
        return $this->closures[$class.'::'.$method] ??= (new ReflectionMethod($class, $method))->getClosure(null);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function commonWorkload(Randomizer $random): array
    {
        $sizes = [];
        for ($i = 0; $i < 16; $i++) {
            $sizes[] = $random->getInt(2_000, 400_000);
        }
        $states = [];
        for ($i = 0; $i < 64; $i++) {
            $states[] = ['dirty', 'stale', 'ready', 'verifying', 'waiting_upload', 'expired'][$random->getInt(0, 5)];
        }

        return [
            'summarizeEligibilityWindow' => [240, 236, 5_400, 600, 86_400, 950],
            'summarizeManifestStorageBatch' => [$sizes, 67_108_864],
            'validateUpdateTransition' => [1, 2, 1, 5],
            'planPrelockLeaseWindow' => [1_761_400_000_000, 30_000, 1_761_400_026_500, 5_000],
            'summarizeResetScopePlan' => [$states, false, 500],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function uncommonWorkload(Randomizer $random): array
    {
        $sizes = [];
        for ($i = 0; $i < 2_000; $i++) {
            $sizes[] = match ($i % 10) {
                0 => null,
                1 => -$i,
                2 => '4096',
                default => $random->getInt(1, 80_000),
            };
        }
        $states = [];
        for ($i = 0; $i < 5_000; $i++) {
            $states[] = self::STATES[$random->getInt(0, count(self::STATES) - 1)];
        }

        return [
            'summarizeEligibilityWindow' => [PHP_INT_MAX, PHP_INT_MAX - 12_345, PHP_INT_MAX, PHP_INT_MIN, 1, PHP_INT_MAX],
            'summarizeManifestStorageBatch' => [$sizes, 33_554_432],
            'validateUpdateTransition' => [3, 1, PHP_INT_MAX, PHP_INT_MIN],
            'planPrelockLeaseWindow' => [PHP_INT_MAX - 5, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX],
            'summarizeResetScopePlan' => [$states, true, 7],
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, mixed ...$args): mixed
    {
        // @phpstan-ignore method.notFound
        return $ffi->nntmux_w396_verification_store_prelock_manifest_reset(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('verification_w396', CollectionReleasePolicy::W396_FFI_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the Wave 39.6 verification export is not available.');
        }

        return $ffi;
    }
}
