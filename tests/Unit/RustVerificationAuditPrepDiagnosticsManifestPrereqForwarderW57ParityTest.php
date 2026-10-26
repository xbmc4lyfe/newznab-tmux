<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\AuditReleaseDeletion;
use App\Services\Releases\Verification\PreparationRunDiagnostics;
use App\Services\Releases\Verification\PreparedManifest;
use App\Services\Releases\Verification\Prerequisites;
use App\Services\Releases\Verification\PublicationForwarderDiagnostics;
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
 * `NNTMUX_W575_BENCH=1`): per helper, 200 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustVerificationAuditPrepDiagnosticsManifestPrereqForwarderW57ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        AuditReleaseDeletion::class => 'auditRecordPlanBatch',
        PreparationRunDiagnostics::class => 'runDiagnosticsPlanBatch',
        PreparedManifest::class => 'manifestEntryPlanBatch',
        Prerequisites::class => 'readinessPlanBatch',
        PublicationForwarderDiagnostics::class => 'forwarderPlanBatch',
    ];

    /**
     * C entry point per class.
     *
     * @var array<class-string, string>
     */
    private const FUNCTIONS = [
        AuditReleaseDeletion::class => 'nntmux_w575_audit_record_plan',
        PreparationRunDiagnostics::class => 'nntmux_w575_run_diagnostics_plan',
        PreparedManifest::class => 'nntmux_w575_manifest_entry_plan',
        Prerequisites::class => 'nntmux_w575_readiness_plan',
        PublicationForwarderDiagnostics::class => 'nntmux_w575_forwarder_plan',
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        AuditReleaseDeletion::class => 4,
        PreparationRunDiagnostics::class => 4,
        PreparedManifest::class => 5,
        Prerequisites::class => 4,
        PublicationForwarderDiagnostics::class => 5,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        AuditReleaseDeletion::class => true,
        PreparationRunDiagnostics::class => true,
        PreparedManifest::class => true,
        Prerequisites::class => true,
        PublicationForwarderDiagnostics::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W575_BENCH=1` runs (200 samples per workload, seed 5752, release Rust, PHP 8.5 CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        AuditReleaseDeletion::class => [2.25, 2.48, 2.56, 2.71],
        PreparationRunDiagnostics::class => [8.52, 8.93, 8.74, 9.15],
        PreparedManifest::class => [2.72, 2.99, 3.83, 4.51],
        Prerequisites::class => [9.11, 9.94, 8.68, 9.09],
        PublicationForwarderDiagnostics::class => [5.51, 6.10, 7.76, 8.35],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 200;

    private const GUID = '0123456789abcdef0123456789abcdef01234567';

    private const BYTES = ['a', 'Z', 'f', '0', '1', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '_', '.', ',', '=', ':', '/', '\\', "\xc3\xa9", "\xc3", "\xff", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\n", ',', '=', '/', '..', '/../', '.', '', ' ', '0', '7', '200', '429', '1234567890123456789', '999999999999999999', 'x', self::GUID, 'unrepairable', 'limit', 'par2', 'true', 'error', 'published'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::state($class, 'reset', null);
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
        foreach (['usesW57RustFfi', 'withW57RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w57RustFfiState')->isPrivate(), "{$class} keeps its W57 state private");
        $constant = new ReflectionClassConstant($class, 'W57_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPrivate(), "{$class} default must be private");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW57RustFfi());

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW57RustFfi($enabled, static fn () => [$class::usesW57RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW57RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW57RustFfi(false, static fn () => [$class::withW57RustFfi(true, static fn () => $class::usesW57RustFfi()), $class::usesW57RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW57RustFfi(! $class::usesW57RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW57RustFfi(), "{$class} must restore after an exception");

        $class::withW57RustFfi(! $class::usesW57RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW57RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w57_state_adds_no_static_properties(): void
    {
        $manifest = new ReflectionClass(PreparedManifest::class);
        $this->assertTrue($manifest->isReadOnly());
        $this->assertTrue($manifest->isFinal());
        $this->assertSame([], $manifest->getStaticProperties());
        foreach (array_keys(self::METHODS) as $class) {
            foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w57', $property->getName(), $class);
            }
        }
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            (new ReflectionProperty(AuditReleaseDeletion::class, 'useRustFfi'))->getValue(),
            (new ReflectionProperty(PreparationRunDiagnostics::class, 'useRustFfi'))->getValue(),
            (new ReflectionMethod(PreparedManifest::class, 'useRustFfi'))->invoke(null),
            (new ReflectionProperty(Prerequisites::class, 'useRustFfi'))->getValue(),
            PublicationForwarderDiagnostics::usesRustFfi(),
        ];
        $before = $snapshot();
        $this->assertSame([false, false, true, false, false], $before);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW57RustFfi($enabled, $snapshot), $class);
            }
        }
        $this->assertSame($before, $snapshot());
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
        $this->assertSame($php, $class::withW57RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW57RustFfi(true, static fn () => $class::{$method}($records)), $class);
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

    public function test_audit_record_plan_examples(): void
    {
        $this->requireFfi(AuditReleaseDeletion::class);
        $nine = self::GUID."\tunrepairable\tcommit\t/n/0/".self::GUID.'.nzb.gz'
            ."\t/c/1\t/c/2\t/c/3\t/c/4\t/c/5\t/c/6\t/c/7\t/c/8";
        $this->assertBoth(AuditReleaseDeletion::class, [
            $nine,
            '',
            self::GUID,
            "XYZ\trepairable\tcleanup\trel/../x\t\t/a\x01",
            self::GUID."\tinconclusive\taudit\t/a\t/a",
            "g\tother\twho\t/g",
            strtoupper(self::GUID)."\tunrepairable\t\t/n/".self::GUID.'..',
            self::GUID."\t\t\t/a\t/a/\t/a/.\t/x..y\t/../",
        ], [
            1, 1, 9, 0,
            0, 0, 0, 65,
            0, 0, 0, 64,
            2, 2, 3, 239,
            3, 3, 2, 112,
            4, 4, 1, 65,
            1, 0, 1, 97,
            0, 0, 5, 104,
        ]);
        // An inventory the class assembles itself passes every integrity check.
        $paths = ['/n/0/'.self::GUID.'.nzb.gz', ...array_map(static fn (int $i): string => '/c/'.$i, range(1, 8))];
        $inventory = AuditReleaseDeletion::inventoryPayloadPhp(['guid' => self::GUID, 'path' => $paths[0]], $paths);
        $this->assertNull(AuditReleaseDeletion::inventoryReasonPhp($inventory, self::GUID));
        $this->assertSame([1, 1, 9, 0], AuditReleaseDeletion::auditRecordPlanBatchNative([implode("\t", [self::GUID, 'unrepairable', 'commit', ...$paths])]));
    }

    public function test_run_diagnostics_plan_examples(): void
    {
        $this->requireFfi(PreparationRunDiagnostics::class);
        $this->assertBoth(PreparationRunDiagnostics::class, [
            '100,100,60,20,10,5,0,30,900,40,limit',
            '',
            '10, 20 ,5,5,5,10,2,7,7,7, deadline ',
            '5,1,x,,,,,,,0,limit,extra',
            '50,40,1,1,1,1,0,0,0,0,bogus',
            '0,0,0,0,0,0,0,5,5,9,exhausted',
            '9,9,1234567890123456789,0,0,0,0,0,0,0,limit_and_deadline',
            '10,1,0,0,0,0,0,1,0,0,error',
        ], [
            3, 2, 95, 0,
            0, 0, 0, 2,
            4, 1, 25, 56,
            3, 0, 0, 133,
            6, 0, 4, 64,
            5, 3, 0, 0,
            2, 0, 0, 1,
            1, 1, 0, 0,
        ]);
        // The stop reasons projectReport() emits classify as known reasons.
        foreach (['error' => 1, 'limit_and_deadline' => 2, 'limit' => 3, 'deadline' => 4, 'exhausted' => 5] as $reason => $code) {
            $this->assertSame($code, PreparationRunDiagnostics::runDiagnosticsPlanBatchNative(['1,1,0,0,0,0,0,0,0,0,'.$reason])[0]);
        }
    }

    public function test_manifest_entry_plan_examples(): void
    {
        $this->requireFfi(PreparedManifest::class);
        $records = [
            "a.rar\t10\t1000\nb.par2\t1\t20\n",
            '',
            "..\t1\t1\nx/y\t1\t1\nz\t0\t1\nw\t1\nv\t1\t1\t1\nu\t1\t1",
            "a\t999999999999999999\t999999999999999999\nb\t999999999999999999\t999999999999999999",
            "\n\n.\t1\t1\r\nfile\t007\t0",
        ];
        $digests = array_map(static fn (string $record): int => (int) hexdec(hash('fnv1a32', $record)), $records);
        $this->assertSame(0x811C9DC5, $digests[1]);
        $this->assertBoth(PreparedManifest::class, $records, [
            2, 0, 11, 1020, $digests[0],
            0, 0, 0, 0, $digests[1],
            1, 5, 1, 1, $digests[2],
            2, 0, 1999999999999999998, 1999999999999999998, $digests[3],
            1, 1, 7, 0, $digests[4],
        ]);
        // Totals saturate instead of promoting to float.
        $many = implode("\n", array_fill(0, 12, "f\t999999999999999999\t999999999999999999"));
        $this->assertSame([12, 0, PHP_INT_MAX, PHP_INT_MAX], \array_slice(PreparedManifest::manifestEntryPlanBatchNative([$many]), 0, 4));
    }

    public function test_readiness_plan_examples(): void
    {
        $this->requireFfi(Prerequisites::class);
        $this->assertBoth(Prerequisites::class, [
            'par2=/usr/bin/par2,nntp=1,database=true,storage=YES,queue=on,scratch=1',
            '',
            ' PAR2 = par2 , nntp=0, bogus=1, noeq ,required=no',
            'par2=,par2=C:\\par2.exe,nntp=1,nntp=0',
            'par2=par2,nntp=1,database=1,storage=1,queue=1,scratch=1,required=TRUE',
            '=,==,required=',
        ], [
            0, 6, 1, 1,
            63, 0, 0, 0,
            62, 1, 26, 1,
            62, 1, 5, 0,
            0, 6, 0, 1,
            63, 0, 18, 1,
        ]);
        // The explicit-path flag agrees with isExplicitPathPhp().
        foreach (['par2', '/usr/bin/par2', 'bin\\par2', './par2', ''] as $binary) {
            $this->assertSame(Prerequisites::isExplicitPathPhp($binary) ? 1 : 0, Prerequisites::readinessPlanBatchNative(['par2='.$binary])[2] & 1, $binary);
        }
    }

    public function test_forwarder_plan_examples(): void
    {
        $this->requireFfi(PublicationForwarderDiagnostics::class);
        $this->assertBoth(PublicationForwarderDiagnostics::class, [
            '200,201,published,filtered',
            '',
            '200,429,retry,deferred',
            '500, 404 ,transport,'.implode(',', array_fill(0, 18, '200')),
            'error,invalid,301,099,0200,other',
            'metadata_review,stale,paused,no_release,408,503,504,600,PUBLISHED',
            ' , ,',
        ], [
            4, 4, 0, 0, 1,
            0, 0, 0, 0, 0,
            4, 1, 3, 0, 2,
            21, 18, 0, 3, 3,
            6, 0, 0, 2, 3,
            9, 1, 5, 0, 2,
            0, 0, 0, 0, 0,
        ]);
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
        $this->assertSame($class::{$method.'Php'}($records), $class::withW57RustFfi(true, static fn () => $class::{$method}($records)), $class);
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
        $data = implode("\0", self::firstRecords($class));
        $needed = 3 * self::WIDTHS[$class];
        $out = $ffi->new('int64_t[16]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call(3, 0, $data, '', $out, 16), $function);
        $this->assertSame(-$needed, $call(3, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call(3, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call(3, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call(3, 1, $data, '', $out, 16), "{$function} flags");
        $this->assertSame(-1, $call(4, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(2, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 16), "{$function} record count");
        $this->assertSame(-1, $call(3, 0, $data, pack('P', 1), $out, 16), "{$function} int table");
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
        mt_srand(5751 + crc32($class) % 1000);
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
        if (getenv('NNTMUX_W575_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W575_BENCH=1 to run the Wave 57.5 paired benchmark.');
        }
        $this->requireFfi(AuditReleaseDeletion::class);
        mt_srand(5752);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $audits = $runs = $manifests = $prereqs = $forwards = [];
            for ($i = 0; $i < $count; $i++) {
                $guid = substr(hash('sha1', (string) $i), 0, 40);
                $audits[] = $odd
                    ? match ($i % 5) {
                        0 => strtoupper($guid)."\trepairable\tcleanup\trel/../".$guid."\t\t/a\x01\t/a\t/a",
                        1 => '',
                        2 => $guid."\tunrepairable\tcommit\t".implode("\t", array_map(static fn (int $k): string => '/var/lib/nntmux/covers/'.$k.'/'.$guid.'.jpg', range(1, 30))),
                        3 => $guid."\tbogus\twho",
                        default => $guid."\tunrepairable\taudit\t/nzb/".$guid[0].'/'.$guid.'.nzb.gz'.str_repeat("\t/nzb/x/../y", 8),
                    }
                : $guid."\tunrepairable\tcommit\t/var/lib/nntmux/nzb/".$guid[0].'/'.$guid.'.nzb.gz'
                    .implode('', array_map(static fn (string $kind): string => "\t/var/lib/nntmux/covers/".$kind.'/'.$guid.'.jpg', ['preview', 'sample', 'video', 'audio', 'nfo', 'thumb', 'large', 'small']));

                $runs[] = $odd
                    ? match ($i % 5) {
                        0 => ' 500 , 501 ,x,1234567890123456789,,,9,  ,77,1,bogus,extra,more',
                        1 => '',
                        2 => '100,40,10,10,10,10,0,5,5,5,limit',
                        3 => str_repeat('7,', 30),
                        default => "\t10\t,\n10\n,1,1,1,1,1,0,0,0, error ",
                    }
                : implode(',', [500, 480 + $i % 20, 300, 100, 50, 30, $i % 3, 1200 + $i, 45000 + 7 * $i, 3000, ['limit', 'deadline', 'exhausted'][$i % 3]]);

                $manifests[] = $odd
                    ? match ($i % 5) {
                        0 => implode("\n", array_map(static fn (int $k): string => "../evil{$k}\t1\t1", range(1, 12))),
                        1 => '',
                        2 => str_repeat("f\t999999999999999999\t999999999999999999\n", 6),
                        3 => "a\tb\tc\nd\t\t\n\t1\t1\nx/y\t1\t1\n\n",
                        default => implode("\n", array_map(static fn (int $k): string => "part{$k}.rar\t".(100 + $k)."\t".(50000000 + $k), range(1, 60))),
                    }
                : implode("\n", array_map(static fn (int $k): string => "Release.Name.{$i}.part".\sprintf('%02d', $k).".rar\t".(130 + $k)."\t".(97000000 + 13 * $k), range(1, 12)))."\nRelease.Name.{$i}.par2\t1\t42000\n";

                $prereqs[] = $odd
                    ? match ($i % 5) {
                        0 => ' PAR2 = C:\\Tools\\par2.exe , NNTP = YES ,database=0, bogus=1, noeq, required=off ',
                        1 => '',
                        2 => 'par2=,par2=par2,nntp=1,nntp=0,nntp=on,storage=true,storage=no',
                        3 => str_repeat('queue=1,', 20),
                        default => '=,==,required=,scratch=maybe',
                    }
                : 'par2=/usr/bin/par2,nntp=1,database=true,storage=1,queue=on,scratch='.($i % 7 === 0 ? '0' : '1').',required=1';

                $forwards[] = $odd
                    ? match ($i % 5) {
                        0 => implode(',', array_map(static fn (int $k): string => ['500', ' 404 ', 'transport', '429', 'retry', '200'][$k % 6], range(1, 80))),
                        1 => '',
                        2 => ' , ,published,PUBLISHED,0200,099,600',
                        3 => str_repeat('metadata_review,stale,', 20),
                        default => 'error,invalid,301,no_release,'.str_repeat('503,', 10),
                    }
                : implode(',', array_map(static fn (int $k): string => $k % 11 === 0 ? 'filtered' : ($k % 17 === 0 ? 'deferred' : 'published'), range(1, 24)));
            }

            $workloads[AuditReleaseDeletion::class][$name] = static fn (bool $rust) => $rust ? AuditReleaseDeletion::auditRecordPlanBatchNative($audits) : AuditReleaseDeletion::auditRecordPlanBatchPhp($audits);
            $workloads[PreparationRunDiagnostics::class][$name] = static fn (bool $rust) => $rust ? PreparationRunDiagnostics::runDiagnosticsPlanBatchNative($runs) : PreparationRunDiagnostics::runDiagnosticsPlanBatchPhp($runs);
            $workloads[PreparedManifest::class][$name] = static fn (bool $rust) => $rust ? PreparedManifest::manifestEntryPlanBatchNative($manifests) : PreparedManifest::manifestEntryPlanBatchPhp($manifests);
            $workloads[Prerequisites::class][$name] = static fn (bool $rust) => $rust ? Prerequisites::readinessPlanBatchNative($prereqs) : Prerequisites::readinessPlanBatchPhp($prereqs);
            $workloads[PublicationForwarderDiagnostics::class][$name] = static fn (bool $rust) => $rust ? PublicationForwarderDiagnostics::forwarderPlanBatchNative($forwards) : PublicationForwarderDiagnostics::forwarderPlanBatchPhp($forwards);
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
                "\n[Wave 57.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
            $this->assertSame(self::DEFAULTS[$class], $class::usesW57RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW57RustFfi(), $class);
            if ($class::usesW57RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW57RustFfi(), "{$class} retains PHP by default");
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
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW57RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW57RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /**
     * Three representative records per class.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private static function firstRecords(string $class): array
    {
        return match ($class) {
            AuditReleaseDeletion::class => [self::GUID."\tunrepairable\tcommit\t/n/".self::GUID."\t/c/1", "x\tr\tc\trel", ''],
            PreparationRunDiagnostics::class => ['10,5,1,1,1,1,0,3,9,4,limit', '1,x,,limit', ''],
            PreparedManifest::class => ["a\t1\t2\nb\t3\t4", "x/y\t1\t1", ''],
            Prerequisites::class => ['par2=/bin/par2,nntp=1', 'required=no,x=1', ''],
            PublicationForwarderDiagnostics::class => ['200,published,429', 'error,500', ''],
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

        return match ($class) {
            AuditReleaseDeletion::class => implode("\t", [
                $pick([self::GUID, strtoupper(self::GUID), substr(self::GUID, 1), '', 'g', self::GUID.'0', self::randomBytes(40)]),
                $pick(['unrepairable', 'repairable', 'inconclusive', '', 'Unrepairable', 'x']),
                $pick(['commit', 'cleanup', 'audit', '', 'other']),
            ]).(mt_rand(0, 3) === 0 ? '' : "\t").$join("\t", 12, static fn (): string => $pick(['/n/'.self::GUID.'.nzb.gz', '/c/1', '/c/1', '', 'rel/x', '/a/../b', '/..', '..', '/a/..b', "/a\x01", "/a\x7f", '/'.self::GUID, self::randomBytes(8)])),
            PreparationRunDiagnostics::class => $join(',', 13, static fn (): string => $pick(['', '0', '1', '12', ' 7 ', "\t40\n", '500', 'x', '-1', '1.5', '1234567890123456789', '999999999999999999', 'limit', 'deadline', 'exhausted', 'error', 'limit_and_deadline', ' limit ', self::randomBytes(4)])),
            PreparedManifest::class => $join("\n", 8, static fn (): string => $join("\t", 4, static fn (): string => $pick(['a.rar', 'b', '', '.', '..', 'x/y', '.hidden', '0', '1', '10', '007', '999999999999999999', '1234567890123456789', "1\r", '-1', ' 5', self::randomBytes(5)]))),
            Prerequisites::class => $join(',', 10, static fn (): string => $pick(['par2', 'PAR2', ' nntp ', 'database', 'Storage', 'queue', 'scratch', 'required', 'bogus', '', 'noeq'])
                .$pick(['=', '=', '=', '', '==', ' = '])
                .$pick(['1', '0', 'true', 'TRUE', 'yes', 'on', 'off', 'no', '', '/usr/bin/par2', 'C:\\par2.exe', 'par2', ' 1 ', 'maybe', self::randomBytes(4)])),
            PublicationForwarderDiagnostics::class => $join(',', 24, static fn (): string => $pick(['200', '201', '299', '300', '408', '429', '400', '404', '500', '503', '504', '599', '600', '099', '0200', ' 200 ', 'published', 'filtered', 'metadata_review', 'deferred', 'paused', 'retry', 'stale', 'error', 'transport', 'invalid', 'no_release', 'PUBLISHED', '', self::randomBytes(4)])),
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
        return self::FUNCTIONS[$class];
    }

    /**
     * Invokes the class's private `w57RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w57RustFfiState'))->invoke(null, $op, $val);
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
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        /** @var string $cdef */
        $cdef = (new ReflectionClassConstant($class, 'W57_FFI_CDEF'))->getValue();
        $ffi = RustPipelineLibrary::resolveDomain('w57_5', $cdef, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust W57.5 FFI library is not built or available.');
        }

        return $ffi;
    }
}
