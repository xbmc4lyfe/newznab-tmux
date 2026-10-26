<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\CollectionReleasePolicy;
use App\Services\Releases\Verification\Par2ProofIdentity;
use App\Services\Releases\Verification\PayloadIdentityResolver;
use App\Services\Releases\Verification\PreparedCandidateUpdate;
use App\Services\Releases\Verification\VerificationResetService;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`, run with
 * `RUN_RUST_FFI_BENCHMARKS=1`): per helper, 100 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustVerificationPolicyPar2ProofPayloadUpdateResetW63ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        CollectionReleasePolicy::class => 'w63CollectionPolicyPlanBatch',
        Par2ProofIdentity::class => 'w63Par2ProofPlanBatch',
        PayloadIdentityResolver::class => 'w63PayloadIdentityPlanBatch',
        PreparedCandidateUpdate::class => 'w63PreparedUpdatePlanBatch',
        VerificationResetService::class => 'w63VerificationResetPlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        CollectionReleasePolicy::class => ['W63_COLLECTION_POLICY_PLAN_FFI_CDEF', 'nntmux_w635_collection_policy_plan'],
        Par2ProofIdentity::class => ['W63_PAR2_PROOF_PLAN_FFI_CDEF', 'nntmux_w635_par2_proof_plan'],
        PayloadIdentityResolver::class => ['W63_PAYLOAD_IDENTITY_PLAN_FFI_CDEF', 'nntmux_w635_payload_identity_plan'],
        PreparedCandidateUpdate::class => ['W63_PREPARED_UPDATE_PLAN_FFI_CDEF', 'nntmux_w635_prepared_update_plan'],
        VerificationResetService::class => ['W63_VERIFICATION_RESET_PLAN_FFI_CDEF', 'nntmux_w635_verification_reset_plan'],
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        CollectionReleasePolicy::class => 12,
        Par2ProofIdentity::class => 10,
        PayloadIdentityResolver::class => 10,
        PreparedCandidateUpdate::class => 10,
        VerificationResetService::class => 9,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        CollectionReleasePolicy::class => true,
        Par2ProofIdentity::class => true,
        PayloadIdentityResolver::class => true,
        PreparedCandidateUpdate::class => true,
        VerificationResetService::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `RUN_RUST_FFI_BENCHMARKS=1` runs (100 samples per workload, seed 6352, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        CollectionReleasePolicy::class => [6.90, 7.46, 4.16, 3.60],
        Par2ProofIdentity::class => [4.61, 4.27, 5.67, 5.59],
        PayloadIdentityResolver::class => [2.98, 3.08, 4.23, 3.85],
        PreparedCandidateUpdate::class => [5.13, 5.20, 6.67, 6.67],
        VerificationResetService::class => [6.13, 6.33, 6.53, 6.22],
    ];

    /**
     * Properties before Wave 63.5; the wave keeps its state in function-local statics.
     *
     * @var array<class-string, list<string>>
     */
    private const PROPERTIES = [
        CollectionReleasePolicy::class => ['useRustFfi', 'w396UseRustFfi', 'w396Ffi'],
        Par2ProofIdentity::class => ['useW442RustFfi'],
        PayloadIdentityResolver::class => ['useW442RustFfi', 'cleaning'],
        PreparedCandidateUpdate::class => ['useRustFfi', 'w396UseRustFfi', 'macro', 'pdo', 'statement', 'sql', 'bindingKeys', 'executing', 'connection'],
        VerificationResetService::class => ['useRustFfi', 'w396UseRustFfi'],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 100;

    private const BYTES = ['a', 'Z', 'x', 'F', 'f', '0', '1', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '+', '_', '.', ',', ':', '/', '|', "'", "\xc3\xa9", "\xc3", "\xff", "\xa0", "\x85", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", '|', '||', ',', ',,', ' ', '.', '..', '/', '//', '/./', '/../', '-', '_', '0', '1', '9', '-1', '950', '1000', '999999999999999', '1000000000000000', 'import', 'SPOT_CHECK', 'full', '.par2', '.PAR2', 'sample', 'Proof', 'subs', 'yenc|', 'par2|', 'nfo|', 'subject|', 'deadbeef', 'ABCDEF', 'verifying', 'ready', 'retry', 'dirty', 'true', 'FALSE', "\x0c", "\x01", "\xc3\xa9"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            $class::resetW63RustFfi();
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => $method) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method): void
    {
        $reflection = new ReflectionClass($class);
        foreach (['enableW63RustFfi', 'disableW63RustFfi', 'resetW63RustFfi', 'usesW63RustFfi', 'withW63RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w63RustFfiState')->isPrivate(), "{$class} keeps its W63 state private");
        $this->assertTrue((new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->isPrivate(), "{$class} keeps its W63 declaration private");
        $constant = new ReflectionClassConstant($class, 'W63_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), "{$class} routes by its default (PHP when it did not qualify)");

        $class::enableW63RustFfi();
        $this->assertTrue($class::usesW63RustFfi());
        $class::disableW63RustFfi();
        $this->assertFalse($class::usesW63RustFfi());
        $class::resetW63RustFfi();
        $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), "{$class} reset restores the default");

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW63RustFfi($enabled, static fn () => [$class::usesW63RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), "{$class} must restore after the callback");
        }
        $class::disableW63RustFfi();
        $this->assertTrue($class::withW63RustFfi(true, static fn () => $class::usesW63RustFfi()));
        $this->assertFalse($class::usesW63RustFfi(), "{$class} restores an explicit override, not the default");
        $class::resetW63RustFfi();
        $nested = $class::withW63RustFfi(false, static fn () => [$class::withW63RustFfi(true, static fn () => $class::usesW63RustFfi()), $class::usesW63RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW63RustFfi(! $class::usesW63RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), "{$class} must restore after an exception");

        $class::withW63RustFfi(! $class::usesW63RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW63RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w63_state_adds_no_properties(): void
    {
        foreach (self::PROPERTIES as $class => $expected) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w63', $property->getName(), $class);
            }
            $this->assertSame($expected, array_map(
                static fn (ReflectionProperty $property): string => $property->getName(),
                $reflection->getProperties(),
            ), "{$class} gains no properties");
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            CollectionReleasePolicy::usesW396RustFfi(),
            Par2ProofIdentity::usesW442RustFfi(),
            PayloadIdentityResolver::usesW442RustFfi(),
            PreparedCandidateUpdate::usesRustFfi(),
            PreparedCandidateUpdate::usesW396RustFfi(),
            VerificationResetService::usesRustFfi(),
            VerificationResetService::usesW396RustFfi(),
        ];
        $before = $snapshot();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW63RustFfi($enabled, $snapshot), $class);
            }
            $class::enableW63RustFfi();
            $class::resetW63RustFfi();
            $this->assertSame($before, $snapshot(), $class);
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            CollectionReleasePolicy::class => ['setUseRustFfi', 'irrecoverable', 'verifiedRejection', 'onlyPar2', 'onlyPar2Php', 'subjectsHavePayload', 'subjectsHavePayloadPhp', 'onlyPar2Native', 'subjectsHavePayloadNative', 'useW396RustFfi', 'usesW396RustFfi', 'resetW396RustFfi', 'summarizeEligibilityWindow', 'summarizeEligibilityWindowPhp', 'summarizeEligibilityWindowNative', 'w396Slots'],
            Par2ProofIdentity::class => ['useW442RustFfi', 'usesW442RustFfi', 'resetW442RustFfi', 'fromPackets', 'summarizeProofIdentities', 'summarizeProofIdentitiesPhp', 'summarizeProofIdentitiesNative'],
            PayloadIdentityResolver::class => ['__construct', 'useW442RustFfi', 'usesW442RustFfi', 'resetW442RustFfi', 'resolve', 'resolvePhp', 'summarizeIdentityHints', 'summarizeIdentityHintsPhp', 'summarizeIdentityHintsNative'],
            PreparedCandidateUpdate::class => ['__construct', 'setUseRustFfi', 'usesRustFfi', 'classifyUpdateShape', 'classifyUpdateShapePhp', 'classifyUpdateShapeNative', 'update', 'affectingStatement', 'useW396RustFfi', 'usesW396RustFfi', 'resetW396RustFfi', 'validateUpdateTransition', 'validateUpdateTransitionPhp', 'validateUpdateTransitionNative'],
            VerificationResetService::class => ['setUseRustFfi', 'usesRustFfi', 'buildTerminalScratchRecord', 'buildTerminalScratchRecordPhp', 'buildTerminalScratchRecordNative', 'isSafeSubpath', 'isSafeSubpathPhp', 'isSafeSubpathNative', 'run', 'withAdmission', 'useW396RustFfi', 'usesW396RustFfi', 'resetW396RustFfi', 'summarizeResetScopePlan', 'summarizeResetScopePlanPhp', 'summarizeResetScopePlanNative'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        foreach (['W396_RUST_FFI_DEFAULT' => [CollectionReleasePolicy::class, PreparedCandidateUpdate::class, VerificationResetService::class], 'W442_RUST_FFI_DEFAULT' => [Par2ProofIdentity::class, PayloadIdentityResolver::class]] as $constant => $classes) {
            foreach ($classes as $class) {
                $this->assertFalse((new ReflectionClassConstant($class, $constant))->getValue(), "{$class}::{$constant}");
            }
        }

        $earlier = static fn (): array => [
            CollectionReleasePolicy::summarizeEligibilityWindowPhp(10, 9, 100, 0, 0),
            CollectionReleasePolicy::onlyPar2Php(['a.par2', 'b.PAR2']),
            Par2ProofIdentity::summarizeProofIdentitiesPhp([null, 'x', str_repeat('a', 64)]),
            PayloadIdentityResolver::summarizeIdentityHintsPhp([['identity_hints' => [['source' => 'yenc', 'name' => 'a.sample.mkv']]], 'x']),
            PreparedCandidateUpdate::validateUpdateTransitionPhp(1, 2, 0),
            PreparedCandidateUpdate::classifyUpdateShapePhp(true, false),
            VerificationResetService::summarizeResetScopePlanPhp(['dirty', 'ready', 'verifying', 7]),
            VerificationResetService::isSafeSubpathPhp('/root', '/root/a'),
        ];
        $expected = $earlier();
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW63RustFfi($enabled, $earlier), $class);
            }
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $php = $class::{$method.'Php'}($records);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php);
        $this->assertSame($php, $class::withW63RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW63RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method): void
    {
        $this->assertSame([], $class::{$method.'Php'}([]));
        $this->assertSame([], $class::{$method.'Native'}([]));
        $this->assertSame([], $class::{$method}([]));
        // One empty record still yields a full row.
        $this->assertSame($class::{$method.'Php'}(['']), $class::{$method.'Native'}(['']));
        $this->assertCount(self::WIDTHS[$class], $class::{$method}(['']));
    }

    public function test_collection_policy_plan_examples(): void
    {
        $this->requireFfi(CollectionReleasePolicy::class);
        $this->assertBoth(CollectionReleasePolicy::class, [
            "100\t98\t7200\t3600\t86400\t950\t5000000\t1000000\t0\t2\tmovie.mkv|movie.par2|movie.vol00+01.PAR2\theader\tfull",
            '',
            "10\t20\t100\t3600\t\t\t\t\t\t\t\timport\t",
            "4\t1\t90000\t0\t86400\t1000\t99\t100\t0\t1\ta.par2|a.par2||\tnzb\tspot_check\textra",
            "x\t5\t5\t9\t3\t\t5000\t0\t4000\t0\tCaf\xc3\xa9.mkv\t\tbogus",
        ], [
            100, 98, 980, 1, 1, 1, 3, 2, 2, 0, 1, 0,
            0, 0, 0, 1, 0, 0, 0, 0, 2, 5, 0, 2,
            10, 10, 1000, 0, 1, 0, 0, 0, 0, 0, 0, 4,
            4, 1, 250, 2, 0, 2, 1, 1, 1, 1, 0, 50,
            0, 0, 0, 0, 0, 0, 1, 0, 2, 2, 0, 77,
        ]);
    }

    public function test_par2_proof_plan_examples(): void
    {
        $this->requireFfi(Par2ProofIdentity::class);
        [$h, $upper, $g] = [str_repeat('ab', 16), str_repeat('CD', 16), str_repeat('0', 31).'1'];
        $this->assertBoth(Par2ProofIdentity::class, [
            "{$h}\t65536\t1\t{$h}\t\t{$h}\t{$h}\t{$h}\t10,20",
            '',
            " {$upper} \t512\t2\t{$upper},{$g}\t\t{$g},{$h}\t{$g}\t{$h}, nothex\t1,x",
            "{$h}\t0\t1\t{$h}",
            "{$h}\t4\t1\t{$h}\t{$h}\t{$h}\t{$h},{$h}\t{$h}\t",
        ], [
            1, 65536, 1, 1, 1, 1, 1, 30, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 1, 2,
            1, 512, 2, 2, 2, 1, 1, 1, 4, 88,
            1, 0, 1, 1, 0, 0, 0, 0, 1, 2,
            1, 4, 1, 1, 1, 1, 1, 0, 3, 32,
        ]);
    }

    public function test_payload_identity_plan_examples(): void
    {
        $this->requireFfi(PayloadIdentityResolver::class);
        $this->assertBoth(PayloadIdentityResolver::class, [
            "yenc|The.Matrix.1999.1080p.mkv\tpar2|The.Matrix.1999.1080p.par2\tsubject|whatever.nfo",
            '',
            "PAR2 |sample.mkv\tbogus|x\tyenc|\tnfo|a1b2c3d4e5f6g7h8.bin\tsubject|Caf\xc3\xa9 Show.mkv",
            "yenc|Show.S01E01.mkv\tyenc|Other.S01E02.mkv\tnfo|Show.nfo",
            "subject|----.rar\tsubject|deadbeefdeadbeef.rar\tnfo|.hidden",
            implode("\t", array_fill(0, 4097, 'yenc|a.mkv')),
        ], [
            3, 0, 0, 0, 3, 4, 1, 1, 1, 0,
            0, 0, 0, 0, 0, 0, -1, 0, 0, 0,
            5, 2, 1, 1, 1, 1, 4, 1, 1, 14,
            3, 0, 0, 0, 3, 3, 0, 2, 2, 0,
            3, 0, 0, 2, 1, 2, 2, 1, 1, 16,
            4097, 0, 0, 0, 0, 0, -1, 0, 0, 1,
        ]);
    }

    public function test_prepared_update_plan_examples(): void
    {
        $this->requireFfi(PreparedCandidateUpdate::class);
        $this->assertBoth(PreparedCandidateUpdate::class, [
            "verifying\tready\t1\t5\t".str_repeat('a', 64)."\t".str_repeat('b', 64)."\t1000\t1000\t\t",
            '',
            "Waiting_Upload\t1\t5\t5\t".str_repeat('A', 64)."\t\t10\t5\tslow\t1700000000\textra",
            "2\tretry\t0\t\tnot-a-digest\t\t\t9\t\t",
            "published\tbogus\tx\t0\t\t\t5\t10\t".str_repeat('r', 300)."\tsoon",
        ], [
            1, 2, 1, 0, 1, 0, 1, 1, 1, 0,
            -1, -1, 0, 1, 0, 0, 0, 0, 0, 6,
            0, 1, 0, 5, 5, 3, 2, 0, 2, 386,
            2, 3, 1, 0, 0, 0, 3, 0, 0, 72,
            4, -1, 0, 1, 0, 3, 0, 0, 3, 165,
        ]);
    }

    public function test_verification_reset_plan_examples(): void
    {
        $this->requireFfi(VerificationResetService::class);
        $this->assertBoth(VerificationResetService::class, [
            "dirty\t\t\t1\t5\t\t\t\t/scratch/a/b\t/scratch/",
            '',
            "verifying\t100\t200\t5\t5\tfalse\tworker-2\tworker-1\t/scratch/x\t/scratch",
            "verifying\t300\t200\t0\t\t1\t\t\t/scratch/../etc\t/scratch",
            "Ready\t100\t\tx\t0\tmaybe\t host \thost\t/other/x\t/scratch\textra",
        ], [
            0, 0, 1, 4, 1, 0, 1, 2, 0,
            3, 0, 0, 5, 0, 0, 0, 0, 10,
            1, 2, 1, 0, 3, 1, 1, 5, 32,
            1, 1, 1, 5, 1, 0, 0, 1, 64,
            3, 1, 0, 1, 0, 0, 0, 0, 95,
        ]);
    }

    /**
     * Numeric limits: 15 digits parse, 16 digits are invalid, and the largest completion ratio stays exact.
     */
    public function test_boundary_records(): void
    {
        $this->requireFfi(CollectionReleasePolicy::class);
        $max = '999999999999999';
        $this->assertBoth(CollectionReleasePolicy::class, [
            "{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\t{$max}\ta\t\t",
            "1{$max}\t1\t0\t0\t0\t0\t0\t0\t0\t0\ta\t\t",
            "3\t2\t0\t0\t0\t666\t0\t0\t0\t0\ta\t\t",
            "3\t2\t0\t0\t0\t667\t0\t0\t0\t0\ta\t\t",
        ], [
            (int) $max, (int) $max, 1000, 1, 1, 1, 1, 0, 2, 3, 0, 0,
            0, 0, 0, 1, 0, 0, 1, 0, 2, 0, 0, 5,
            3, 2, 666, 1, 1, 1, 1, 0, 2, 0, 1, 0,
            3, 2, 666, 1, 0, 0, 1, 0, 2, 0, 0, 0,
        ]);
        $this->assertBoth(PreparedCandidateUpdate::class, [
            "retry\tverifying\t{$max}\t{$max}\t\t\t\t\t\t",
            "retry\tverifying\t1{$max}\t\t\t\t\t\t\t",
        ], [
            3, 1, 0, 5, (int) $max, 0, 0, 0, 0, 256,
            3, 1, 1, 0, 1, 0, 0, 0, 0, 1,
        ]);
        $this->assertBoth(VerificationResetService::class, [
            "stale\t5\t5\t{$max}\t{$max}\t\t\t\t//\t/",
            "unknown\t6\t5\t0\t1\t\t\t\t/a/b\t///",
        ], [
            0, 2, 1, 0, 2, 0, 0, (int) $max, 64,
            0, 1, 1, 1, 1, 0, 1, 1, 0,
        ]);
    }

    /**
     * Non-ASCII bytes (valid and invalid UTF-8) never count as ASCII letters, digits, separators or whitespace.
     */
    public function test_unicode_records(): void
    {
        $this->requireFfi(PayloadIdentityResolver::class);
        $this->assertBoth(PayloadIdentityResolver::class, [
            "yenc|\xc3\x89t\xc3\xa9.2024.mkv\tpar2|\xc3\xa9t\xc3\xa9 2024.par2",
            "yenc|\xa0sample\xa0.mkv\tnfo|\x85subs\x85\tsubject|SUBS.\xff",
            "\xc3\xa9|x\tyenc\xc2\xa0|y",
        ], null);
        $this->assertBoth(CollectionReleasePolicy::class, ["1\t1\t0\t0\t0\t\t0\t0\t0\t0\t\xc3\xa9.PAR2|\xc3\xa9.par2\tIMPORT\xc2\xa0\tFULL"], null);
        $this->assertBoth(VerificationResetService::class, ["dirty\t\t\t\t\t\tw\xc3\xa9\tW\xc3\x89\t/\xc3\xa9/\xff\t/\xc3\xa9"], null);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $class::{$method.'Native'}($records));
        $handle = self::state($class, 'buffer', 1);
        $this->assertIsArray($handle, "{$class} must resolve the Rust helper");
        $this->assertInstanceOf(FFI::class, $handle[0]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_large_batches_grow_the_output_buffer(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = self::firstRecords($class)[$i % 3].$i;
        }
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $handle = self::state($class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(3000 * self::WIDTHS[$class], $handle[2]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method): void
    {
        self::state($class, 'reset', false);
        $records = self::firstRecords($class);
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $this->assertSame($class::{$method.'Php'}($records), $class::withW63RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertNull(self::state($class, 'buffer', 1), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_nul_bytes_decline_to_php(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $records[1] .= "\0x";
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $records = ["\0", "\t\0\t", self::firstRecords($class)[0]."\0"];
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $ffi = $this->requireFfi($class);
        $function = self::abiFunction($class);
        $parts = self::firstRecords($class);
        $data = implode("\0", $parts);
        $count = \count($parts);
        $needed = 3 * self::WIDTHS[$class];
        $out = $ffi->new('int64_t[64]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call($count, 0, $data, '', $out, 64), $function);
        $this->assertSame(-$needed, $call($count, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call($count, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call($count, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call($count, 1, $data, '', $out, 64), "{$function} flags");
        $this->assertSame(-1, $call($count, -1, $data, '', $out, 64), "{$function} flags");
        $this->assertSame(-1, $call($count + 1, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call($count - 1, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 64), "{$function} record count");
        $this->assertSame(-1, $call($count, 0, $data, pack('P', 1), $out, 64), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    /**
     * Over 400 rounds of 1-16 records each (thousands of structured, fragment-spliced and raw-byte inputs
     * per helper), every class's Rust and PHP plans agree bit for bit; every 50th round also carries NUL
     * bytes, which must decline to PHP.
     *
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $this->requireFfi($class);
        mt_srand(6351 + crc32($class) % 1000);
        $checked = 0;
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $records[] = match (mt_rand(0, 5)) {
                    0 => self::randomBytes(24, $withNul),
                    1 => self::spliced(self::structuredRecord($class), $withNul),
                    default => self::structuredRecord($class),
                };
            }
            if ($withNul) {
                $records[0] .= "\0";
            }
            $this->assertBoth($class, $records, null);
            $checked += \count($records);
        }
        $this->assertGreaterThanOrEqual(300, $checked);
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            $this->markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 63.5 paired benchmark.');
        }
        $this->requireFfi(CollectionReleasePolicy::class);
        mt_srand(6352);
        $workloads = [];
        $floors = [];
        [$h, $g, $upper] = [str_repeat('ab', 16), str_repeat('0', 31).'1', str_repeat('CD', 16)];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $collection = $par2 = $payload = $update = $reset = [];
            for ($i = 0; $i < $count; $i++) {
                $now = 1_700_000_000 + $i * 37;
                $file = substr(md5((string) $i), 0, 8);

                $collection[] = $odd
                    ? match ($i % 5) {
                        0 => " 999999999999999 \t 999999999999998 \t{$now}\t3600\t86400\t1000\t".(5_000_000 + $i)."\t1000000\t4000000\t9\t".implode('|', array_map(static fn (int $n): string => "Big.Release.part{$n}.rar", range(1, 30))).'|Big.Release.par2|Big.Release.vol00+01.PAR2|Big.Release.part1.rar||'."\tIMPORT\tSPOT_CHECK",
                        1 => '',
                        2 => "x{$i}\t-1\t\xc3\xa9\t\t\t\t\t\t\t\t\xc3\xa9t\xc3\xa9.mkv|\xff.par2\tnzb\tbogus\textra",
                        3 => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t".str_repeat('a.par2|', 12)."\theader\tspot_check",
                        default => "0\t0\t0\t0\t0\t0\t0\t0\t0\t0\t\t\t",
                    }
                : (100 + $i % 50)."\t".(95 + $i % 50)."\t".(3600 + $i * 11)."\t3600\t86400\t950\t".(1_000_000 * ($i % 40 + 1))."\t1000000\t0\t2\t{$file}.mkv|{$file}.nfo|{$file}.par2|{$file}.vol00+01.par2\theader\t".($i % 3 === 0 ? 'spot_check' : 'full');

                $par2[] = $odd
                    ? match ($i % 5) {
                        0 => " {$upper} \t65536\t3\t{$upper},{$g},{$h}\t".implode(',', array_fill(0, 6, $g))."\t{$h},{$g},{$upper},{$h}\t{$g},{$h}\t{$h},{$g}, xyz ,\t".str_repeat('1234,', 10).'x',
                        1 => '',
                        2 => "\xc3\xa9{$i}\t-1\t\xff\t,,\t \t{$h}\xc3\xa9\t\t\t",
                        3 => "{$h}\t{$i}\t1\t{$h}\t\t{$h}\t{$h}\t{$h}\t{$i}\textra",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : self::par2Common($i);

                $payload[] = $odd
                    ? match ($i % 5) {
                        0 => implode("\t", array_map(static fn (int $n): string => ['yenc', 'PAR2', 'nfo', 'subject'][$n % 4].'|'.['The.Long.Show.S01E0'.$n.'.1080p.WEB.mkv', 'sample-show.mkv', str_repeat('a1b2', 5).'.rar', 'Show.S01E01.SUBS.srt'][$n % 4], range(0, 23))),
                        1 => '',
                        2 => "y\xc3\xa9nc|\xc3\xa9t\xc3\xa9.mkv\tsubject|\xc3\xa9t\xc3\xa9 \xff.mkv\tnfo|\x85proof\x85\tbogus\t|",
                        3 => "nfo|----.nfo\tnfo|....\tsubject|deadbeefdeadbeef01.bin\tyenc|.hidden\tpar2|x.vol00+01.par2",
                        default => "{$i}|{$i}\t{$i}\t{$i}|",
                    }
                : "yenc|Movie.{$file}.2024.1080p.mkv\tpar2|Movie.{$file}.2024.1080p.par2\tnfo|movie.{$file}.nfo\tsubject|[{$i}/50] \"Movie.{$file}.2024.1080p.mkv\" yEnc";

                $update[] = $odd
                    ? match ($i % 5) {
                        0 => " WAITING_UPLOAD \t VERIFYING \t 999999999999999 \t1\t".str_repeat('A', 64)."\t".str_repeat('a', 64)."\t999999999999999\t1\t".str_repeat('reason ', 50)."\tsoon",
                        1 => '',
                        2 => "\xc3\xa9\t9\t-1\tx\t".str_repeat('g', 64)."\t\xff\t\xc3\xa9\t\t\t\textra",
                        3 => "ready\tpublished\t0\t0\t".str_repeat('f', 63)."\t\t5\t5\t\t{$now}",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['waiting_upload', 'verifying', 'verifying', 'retry'][$i % 4]."\t".['verifying', 'ready', 'retry', 'verifying'][$i % 4]."\t".($i % 6)."\t5\t".hash('sha256', (string) $i)."\t".($i % 3 === 0 ? hash('sha256', (string) ($i + 1)) : '')."\t".(1_000_000 + $i)."\t".(1_000_000 + $i * ($i % 2))."\t".($i % 4 === 2 ? 'segment missing' : '')."\t".($i % 4 === 2 ? (string) ($now + 600) : '');

                $reset[] = $odd
                    ? match ($i % 5) {
                        0 => "verifying\t 999999999999999 \t{$now}\t999999999999999\t1\tTRUE\t worker-{$i} \tworker-0\t/var/scratch/".str_repeat('nested/', 12).'..'."\t/var/scratch///",
                        1 => '',
                        2 => "Dirty\t\xc3\xa9\tx\t-1\t\t maybe \t\xff\t\xff\t/\xc3\xa9/./x\t/\xc3\xa9\textra",
                        3 => "ready\t{$now}\t\t\t\t0\t\t\t//\t/",
                        default => "{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}\t{$i}",
                    }
                : ['dirty', 'stale', 'verifying', 'ready', 'expired', 'unknown'][$i % 6]."\t".($i % 6 === 2 ? (string) ($now + ($i % 4 === 0 ? 60 : -60)) : '')."\t{$now}\t".($i % 7)."\t5\t".($i % 2 === 0 ? 'false' : '')."\tworker-".($i % 3)."\tworker-0\t/var/scratch/candidate-{$i}/payload\t/var/scratch";
            }

            $workloads[CollectionReleasePolicy::class][$name] = static fn (bool $rust) => $rust ? CollectionReleasePolicy::w63CollectionPolicyPlanBatchNative($collection) : CollectionReleasePolicy::w63CollectionPolicyPlanBatchPhp($collection);
            $workloads[Par2ProofIdentity::class][$name] = static fn (bool $rust) => $rust ? Par2ProofIdentity::w63Par2ProofPlanBatchNative($par2) : Par2ProofIdentity::w63Par2ProofPlanBatchPhp($par2);
            $workloads[PayloadIdentityResolver::class][$name] = static fn (bool $rust) => $rust ? PayloadIdentityResolver::w63PayloadIdentityPlanBatchNative($payload) : PayloadIdentityResolver::w63PayloadIdentityPlanBatchPhp($payload);
            $workloads[PreparedCandidateUpdate::class][$name] = static fn (bool $rust) => $rust ? PreparedCandidateUpdate::w63PreparedUpdatePlanBatchNative($update) : PreparedCandidateUpdate::w63PreparedUpdatePlanBatchPhp($update);
            $workloads[VerificationResetService::class][$name] = static fn (bool $rust) => $rust ? VerificationResetService::w63VerificationResetPlanBatchNative($reset) : VerificationResetService::w63VerificationResetPlanBatchPhp($reset);
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
                for ($s = 0; $s < self::BENCH_SAMPLES; $s++) {
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
                "\n[Wave 63.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW63RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW63RustFfi(), $class);
            if ($class::usesW63RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW63RustFfi(), "{$class} retains PHP by default");
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<string>  $records
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $records, ?array $expected): void
    {
        $method = self::METHODS[$class];
        $php = $class::{$method.'Php'}($records);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class} PHP");
        }
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php, $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW63RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW63RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /** A complete or near-complete PAR2 main/descriptor/checksum inventory. */
    private static function par2Common(int $i): string
    {
        $ids = [];
        for ($n = 0, $files = 2 + $i % 6; $n < $files; $n++) {
            $ids[] = md5("{$i}-{$n}");
        }
        $descriptors = $i % 7 === 3 ? \array_slice($ids, 1) : $ids;

        return md5("set-{$i}")."\t".(65536 * (1 + $i % 4))."\t".\count($ids)."\t".implode(',', $ids)."\t\t".implode(',', array_reverse($descriptors))."\t".implode(',', $ids)."\t"
            .implode(',', array_map(static fn (string $id): string => md5($id), $descriptors))."\t".implode(',', array_map(static fn (int $n): string => (string) (100 + $n * 7), array_keys($ids)));
    }

    /**
     * Three representative records per class.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private static function firstRecords(string $class): array
    {
        $h = str_repeat('ab', 16);

        return match ($class) {
            CollectionReleasePolicy::class => ["100\t98\t7200\t3600\t86400\t950\t5000000\t1000000\t0\t2\tmovie.mkv|movie.par2\theader\tfull", "x\t5\t5\t9\t3", ''],
            Par2ProofIdentity::class => ["{$h}\t65536\t1\t{$h}\t\t{$h}\t{$h}\t{$h}\t10", "{$h}\tx\t1\tbad", ''],
            PayloadIdentityResolver::class => ["yenc|Show.S01E01.mkv\tpar2|Show.S01E01.par2", "bogus|x\tnfo|sample.nfo", ''],
            PreparedCandidateUpdate::class => ["verifying\tready\t1\t5\t".str_repeat('a', 64)."\t\t10\t10\t\t", "bogus\tretry\tx", ''],
            VerificationResetService::class => ["dirty\t\t\t1\t5\t\t\t\t/scratch/a\t/scratch", "verifying\t1\t2\tx", ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * One record shaped like the class's input, built from plausible and adversarial pieces.
     *
     * @param  class-string  $class
     */
    private static function structuredRecord(string $class): string
    {
        $pick = static fn (array $items): string => (string) $items[mt_rand(0, \count($items) - 1)];
        $join = static function (string $separator, int $max, callable $part): string {
            $parts = [];
            for ($i = 0, $n = mt_rand(0, $max); $i < $n; $i++) {
                $parts[] = $part();
            }

            return implode($separator, $parts);
        };
        $number = static fn (): string => $pick(['', ' ', '0', '1', '2', '3', '4', '5', '6', '9', '10', ' 5', '5 ', '00', '007', '-1', '+1', '1.5', 'x', '99', '100', '101', '666', '667', '949', '950', '951', '999', '1000', '1001', '3600', '86400', '86401', '1000000', (string) mt_rand(0, 1_000_000_000), (string) mt_rand(1_699_999_990, 1_700_000_010), '999999999999999', '1000000000000000', '9999999999999999999', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);
        $hexIds = [str_repeat('ab', 16), str_repeat('AB', 16), str_repeat('0', 31).'1', str_repeat('0', 31).'2', 'ffffffffffffffffffffffffffffffff', str_repeat('cd', 16)];
        $hex = static fn (): string => $pick([...$hexIds, ...$hexIds, '', ' '.$hexIds[0].' ', substr($hexIds[0], 1), $hexIds[0].'0', str_repeat('g', 32), self::randomBytes(6)]);
        $hexList = static fn (): string => $join($pick([',', ',', ', ']), 4, $hex);
        $filename = static fn (): string => $pick(['', 'movie.mkv', 'movie.par2', 'movie.vol00+01.PAR2', 'Movie.Name.2024.1080p.mkv', 'Show.S01E01.mkv', 'sample.mkv', 'movie-sample.mkv', 'Proof.jpg', 'thumbs.db', 'subs.rar', 'movie.subtitles.zip', "movie\x0bsample.mkv", "sub\xa0.mkv", "Caf\xc3\xa9.mkv", "\xff.par2", '.hidden', '----.rar', 'deadbeefdeadbeef.rar', 'a1b2c3d4e5f6g7h8.bin', '1a2b3c4d5e6f7g8h9i', 'abcdefghijklmnop', 'abc.toolongext', 'abc.', 'x.r00', self::randomBytes(10)]);
        $statuses = ['', ' ', '0', '1', '2', '3', '4', '5', '6', '7', '00', 'waiting_upload', 'WAITING_UPLOAD', 'verifying', ' Verifying ', 'ready', 'retry', 'published', 'rejected', 'expired', 'dirty', 'stale', 'unknown', 'bogus', self::randomBytes(4)];

        return match ($class) {
            CollectionReleasePolicy::class => implode("\t", $cut([
                $number(), $number(), $number(), $number(), $number(), $number(), $number(), $number(), $number(), $number(),
                $join('|', 5, $filename),
                $pick(['', ' ', 'import', 'IMPORT', ' import ', 'header', 'nzb', 'importer', self::randomBytes(4)]),
                $pick(['', ' ', 'full', 'FULL', 'spot_check', ' Spot_Check ', 'spot-check', 'partial', self::randomBytes(4)]),
                'extra',
            ], 13)),
            Par2ProofIdentity::class => implode("\t", $cut([
                $hex(), $number(), $number(), $hexList(), $hexList(), $hexList(), $hexList(), $hexList(),
                $join(',', 4, $number),
                'extra',
            ], 9)),
            PayloadIdentityResolver::class => $join("\t", 6, static fn (): string => mt_rand(0, 9) === 0
                ? self::randomBytes(8)
                : $pick(['yenc', 'YENC', ' par2 ', 'par2', 'nfo', 'subject', 'Subject', 'bogus', '', "y\xc3\xa9nc"]).($pick(['|', '|', '|', '', '||'])).$filename()),
            PreparedCandidateUpdate::class => implode("\t", $cut([
                $pick($statuses), $pick($statuses), $number(), $number(),
                $pick(['', ' ', str_repeat('a', 64), str_repeat('A', 64), str_repeat('aB', 32), str_repeat('a', 63), str_repeat('a', 65), str_repeat('g', 64), self::randomBytes(6)]),
                $pick(['', ' ', str_repeat('a', 64), str_repeat('A', 64), str_repeat('b', 64), 'nope', self::randomBytes(6)]),
                $number(), $number(),
                $pick(['', ' ', 'missing segments', str_repeat('r', 255), str_repeat('r', 256), ' '.str_repeat('r', 255).' ', self::randomBytes(6)]),
                $number(),
                'extra',
            ], 10)),
            VerificationResetService::class => implode("\t", $cut([
                $pick($statuses), $number(), $number(), $number(), $number(),
                $pick(['', ' ', '0', '1', 'true', 'TRUE', 'false', ' False ', 'yes', '2', self::randomBytes(3)]),
                $pick(['', ' ', 'worker-1', ' worker-1 ', 'worker-2', 'Worker-1', self::randomBytes(4)]),
                $pick(['', ' ', 'worker-1', 'worker-2', self::randomBytes(4)]),
                $pick(['', '/scratch/a', '/scratch/a/b', '/scratch/', '/scratch//a', '/scratch/./a', '/scratch/../a', '/scratch/a/..', '/scratch/a/.', '/scratchx/a', '/other/a', 'scratch/a', '//', '/a', self::randomBytes(8)]),
                $pick(['', '/scratch', '/scratch/', '/scratch//', '/', '//', 'scratch', self::randomBytes(4)]),
                'extra',
            ], 10)),
            default => throw new RuntimeException($class),
        };
    }

    /** Splices random fragments and bytes into $record at random offsets. */
    private static function spliced(string $record, bool $withNul): string
    {
        for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
            $insert = mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(4, $withNul);
            $at = mt_rand(0, \strlen($record));
            $record = substr($record, 0, $at).$insert.substr($record, $at);
        }

        return $record;
    }

    /**
     * The C entry point of $class, called by name through the FFI handle.
     *
     * @param  class-string  $class
     */
    private static function abiFunction(string $class): string
    {
        return self::FUNCTIONS[$class][1];
    }

    /**
     * Invokes the class's private `w63RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w63RustFfiState'))->invoke(null, $op, $val);
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $value = '';
        for ($i = 0, $length = mt_rand(0, $maxLength); $i < $length; $i++) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $value .= $byte === "\0" && ! $withNul ? ' ' : $byte;
        }

        return $value;
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
     * Resolves the class's Wave 63.5 FFI handle or skips the test without the library.
     *
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI extension not loaded.');
        }
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $cdef = (new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->getValue();
        $ffi = \is_string($cdef) ? RustPipelineLibrary::resolveDomain('w63_5', $cdef, \is_string($path) && $path !== '' ? $path : null) : null;
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library not built.');
        }

        return $ffi;
    }
}
