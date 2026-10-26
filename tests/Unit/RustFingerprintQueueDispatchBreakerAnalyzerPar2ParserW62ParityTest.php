<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Fingerprint\DatabaseFingerprintQueue;
use App\Services\Releases\Fingerprint\FingerprintDispatch;
use App\Services\Releases\Fingerprint\FingerprintProviderBreaker;
use App\Services\Releases\Fingerprint\NzbFingerprintAnalyzer;
use App\Services\Releases\Fingerprint\Par2MetadataParser;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

final class RustFingerprintQueueDispatchBreakerAnalyzerPar2ParserW62ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        DatabaseFingerprintQueue::class => 'w62QueueLeasePlanBatch',
        FingerprintDispatch::class => 'w62DispatchPlanBatch',
        FingerprintProviderBreaker::class => 'w62BreakerPlanBatch',
        NzbFingerprintAnalyzer::class => 'w62NzbAnalysisPlanBatch',
        Par2MetadataParser::class => 'w62Par2PacketPlanBatch',
    ];

    /** Class => Wave 62.2 export. */
    private const SYMBOLS = [
        DatabaseFingerprintQueue::class => 'nntmux_w622_queue_lease_plan_batch',
        FingerprintDispatch::class => 'nntmux_w622_dispatch_plan_batch',
        FingerprintProviderBreaker::class => 'nntmux_w622_breaker_plan_batch',
        NzbFingerprintAnalyzer::class => 'nntmux_w622_nzb_analysis_plan_batch',
        Par2MetadataParser::class => 'nntmux_w622_par2_packet_plan_batch',
    ];

    /** Class => class-distinct C declaration constant. */
    private const CDEFS = [
        DatabaseFingerprintQueue::class => 'W62_DATABASE_FINGERPRINT_QUEUE_FFI_CDEF',
        FingerprintDispatch::class => 'W62_FINGERPRINT_DISPATCH_FFI_CDEF',
        FingerprintProviderBreaker::class => 'W62_FINGERPRINT_PROVIDER_BREAKER_FFI_CDEF',
        NzbFingerprintAnalyzer::class => 'W62_NZB_FINGERPRINT_ANALYZER_FFI_CDEF',
        Par2MetadataParser::class => 'W62_PAR2_METADATA_PARSER_FFI_CDEF',
    ];

    /** Class => summary words after the per-row words (the digest included). */
    private const TAIL = [
        DatabaseFingerprintQueue::class => 23,
        FingerprintDispatch::class => 20,
        FingerprintProviderBreaker::class => 16,
        NzbFingerprintAnalyzer::class => 30,
        Par2MetadataParser::class => 36,
    ];

    /** Class => public tail-size constant. */
    private const TAIL_CONSTANTS = [
        DatabaseFingerprintQueue::class => 'W62_DATABASE_FINGERPRINT_QUEUE_TAIL',
        FingerprintDispatch::class => 'W62_FINGERPRINT_DISPATCH_TAIL',
        FingerprintProviderBreaker::class => 'W62_FINGERPRINT_PROVIDER_BREAKER_TAIL',
        NzbFingerprintAnalyzer::class => 'W62_NZB_FINGERPRINT_ANALYZER_TAIL',
        Par2MetadataParser::class => 'W62_PAR2_METADATA_PARSER_TAIL',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 62, 60
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 600 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon rows, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 7-10). Timings include
     * dispatch, implode/pack, the FFI call and unpack('P*'). A class defaults to Rust only when
     * all four reach 1.5x.
     */
    private const QUALIFICATION = [
        DatabaseFingerprintQueue::class => [5.111, 5.007, 6.210, 6.150],
        FingerprintDispatch::class => [11.001, 8.558, 7.814, 6.678],
        FingerprintProviderBreaker::class => [7.758, 6.829, 8.521, 7.712],
        NzbFingerprintAnalyzer::class => [7.372, 7.167, 7.114, 6.839],
        Par2MetadataParser::class => [2.705, 2.769, 2.530, 2.505],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 60;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const HEX = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const NOW = 1_700_000_000;

    private const WORDS = ['the', 'great', 'night', 'big', 'movie', 'show', 'pro', 'saga', 'old', 'new', 'dark', 'star', 'wild', 'hunt', 'city', 'last'];

    private const EXTENSIONS = ['.par2', '.vol00+01.par2', '.vol003+04.PAR2', '.vol07-08.par2', '.part01.rar', '.part1.rar', '.part12.rar', '.rar', '.r00', '.r12',
        '.001', '.014', '.7z', '.zip', '.nfo', '.sfv', '.nzb', '.srr', '.jpg', '.mkv', '.mp4', '.iso', '.flac', '.exe', '.Sample.mkv', ''];

    private const TYPES = ['Main' => "PAR 2.0\0Main\0\0\0\0", 'FileDesc' => "PAR 2.0\0FileDesc", 'IFSC' => "PAR 2.0\0IFSC\0\0\0\0", 'RecvSlic' => "PAR 2.0\0RecvSlic",
        'Creator' => "PAR 2.0\0Creator\0", 'Other' => "PAR 2.0\0Unknown\0", 'Foreign' => "PAR 3.0\0FileDesc"];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW62RustFfi', 'withW62RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w62RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame($class, $state->getDeclaringClass()->getName(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertSame(['enabled', 'ffi', 'out', 'capacity'], array_keys($state->getStaticVariables()), $class.' keeps Wave 62 state in function-local statics');
            $default = $reflection->getReflectionConstant('W62_RUST_FFI_DEFAULT');
            self::assertInstanceOf(ReflectionClassConstant::class, $default, $class);
            self::assertIsBool($default->getValue(), $class);
            self::assertTrue($default->isPublic(), $class);
            self::assertSame($class, $default->getDeclaringClass()->getName(), $class);
            self::assertSame($class, $reflection->getReflectionConstant(self::CDEFS[$class])?->getDeclaringClass()->getName(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            self::assertSame(self::TAIL[$class], $reflection->getConstant(self::TAIL_CONSTANTS[$class]), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w62', strtolower((string) $property), 'Wave 62 state lives in w62RustFfiState().');
            }
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('w62', strtolower($property->getName()), $class.' adds no Wave 62 property');
            }
        }
        // The CDEF names are class-distinct, so no class shadows another's declaration.
        self::assertCount(5, array_unique(self::CDEFS));
        self::assertSame(1800, FingerprintProviderBreaker::W62_BREAKER_PROBE_SECONDS);
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            DatabaseFingerprintQueue::class => ['enableRustFfi', 'disableRustFfi', 'resetRustFfi', 'usesRustFfi', 'enqueue', 'enqueueLocked', 'shouldSkip', 'shouldSkipPhp',
                'shouldSkipNative', 'useW432RustFfi', 'usesW432RustFfi', 'resetW432RustFfi', 'summarizeEnqueueDecisions', 'summarizeEnqueueDecisionsPhp',
                'summarizeEnqueueDecisionsNative'],
            FingerprintDispatch::class => ['enqueue', 'useRustFfi', 'usesRustFfi', 'resetRustFfi', 'planEnqueue', 'useW47DispatchRustFfi', 'usesW47DispatchRustFfi',
                'resetW47DispatchRustFfi', 'planEnqueueBatch', 'planEnqueueBatchPhp', 'planEnqueueBatchNative', 'callW47Native', 'w47Fnv'],
            FingerprintProviderBreaker::class => ['setUseRustFfi', 'usesRustFfi', 'skipReason', 'recordUnavailable', 'recordReachable', 'releaseProbe', 'suspectSecondsPhp',
                'suspectSecondsNative', 'keyPhp', 'keyNative', 'useW47BreakerRustFfi', 'usesW47BreakerRustFfi', 'resetW47BreakerRustFfi', 'simulateBatch',
                'simulateBatchPhp', 'simulateBatchNative'],
            NzbFingerprintAnalyzer::class => ['__construct', 'setUseRustFfi', 'analyzeManifest', 'selectArticleSamples', 'analyzeArticle', 'analyzeArticleAndDecodedBytes',
                'fileKind', 'fileKindNative', 'formatHint', 'formatHintNative', 'unfoldHeadersNative', 'selectArticleSamplesNative', 'useW47AnalyzerRustFfi',
                'usesW47AnalyzerRustFfi', 'resetW47AnalyzerRustFfi', 'summarizeSegmentBatch', 'summarizeSegmentBatchPhp', 'summarizeSegmentBatchNative'],
            Par2MetadataParser::class => ['findCrossVolumeRecoveryConflicts', 'validateCrossVolumeIfscRelationships', 'relateNzbFilePrefixesToSources',
                'declaredYencCrc32Valid', 'bodyResponseIdentityVerified', 'sourceDefinitionConflictKeys', 'validatedRecoveryExponents', 'mapPacketArticleSpans', 'parse',
                'useW442RustFfi', 'usesW442RustFfi', 'resetW442RustFfi', 'summarizeConflictKeyBatch', 'summarizeConflictKeyBatchPhp', 'summarizeConflictKeyBatchNative'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        // The earlier-wave helpers answer the same whichever way the Wave 62 toggle points.
        $earlier = static fn (): array => [
            DatabaseFingerprintQueue::summarizeEnqueueDecisionsPhp([['job' => null, 'digest' => 'a', 'force' => false], ['job' => ['source_digest' => 'a', 'status' => 'pending'],
                'digest' => 'a', 'force' => true]]),
            DatabaseFingerprintQueue::shouldSkipPhp((object) ['source_digest' => 'a', 'status' => 'pending'], 'a', false),
            FingerprintDispatch::planEnqueueBatchPhp([1, 2, 2], ['', 'g', 'g'], [false, true, false]),
            FingerprintProviderBreaker::suspectSecondsPhp(900), FingerprintProviderBreaker::suspectSecondsPhp(900, 120),
            FingerprintProviderBreaker::keyPhp(['name' => 'News', 'port' => '563', 'tls' => true]),
            NzbFingerprintAnalyzer::fileKind('Movie.vol00+01.par2'), NzbFingerprintAnalyzer::formatHint('a.MKV (1/2)'),
            NzbFingerprintAnalyzer::summarizeSegmentBatchPhp(['<a@b>', '<a@b>'], [100, 200]),
            Par2MetadataParser::summarizeConflictKeyBatchPhp([['recovery_set_id' => 'a', 'file_id' => 'b']]),
        ];
        $baseline = $earlier();
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([true, false] as $enabled) {
                self::assertEquals($baseline, $class::withW62RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW62RustFfi(), $class);
            if (min($speedups) < 1.5) {
                self::assertFalse($class::usesW62RustFfi(), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW62RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW62RustFfi(! $enabled, static fn (): bool => $class::usesW62RustFfi());

                    return [$class::usesW62RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW62RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW62RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW62RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW62RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW62RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        $earlier = [
            DatabaseFingerprintQueue::class => ['usesRustFfi', 'usesW432RustFfi'],
            FingerprintDispatch::class => ['usesRustFfi', 'usesW47DispatchRustFfi'],
            FingerprintProviderBreaker::class => ['usesW47BreakerRustFfi'],
            NzbFingerprintAnalyzer::class => ['usesW47AnalyzerRustFfi'],
            Par2MetadataParser::class => ['usesW442RustFfi'],
        ];
        foreach ($earlier as $class => $methods) {
            $before = array_map(static fn (string $method): bool => $class::$method(), $methods);
            foreach ([true, false] as $enabled) {
                $inside = $class::withW62RustFfi($enabled, static fn (): array => array_map(static fn (string $method): bool => $class::$method(), $methods));
                self::assertSame($before, $inside, $class);
            }
            self::assertSame($before, array_map(static fn (string $method): bool => $class::$method(), $methods), $class);
        }
        // Earlier switches leave the Wave 62 toggles alone.
        try {
            foreach ([true, false] as $enabled) {
                DatabaseFingerprintQueue::useW432RustFfi($enabled);
                FingerprintDispatch::useW47DispatchRustFfi($enabled);
                Par2MetadataParser::useW442RustFfi($enabled);
                foreach (array_keys(self::ENTRIES) as $class) {
                    self::assertSame($this->default($class), $class::usesW62RustFfi(), $class);
                }
            }
        } finally {
            DatabaseFingerprintQueue::resetW432RustFfi();
            FingerprintDispatch::resetW47DispatchRustFfi();
            Par2MetadataParser::resetW442RustFfi();
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(6200));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW62RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $items), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes (string columns) and keyed or non-sequential batches keep the PHP semantics.
        foreach (array_keys(self::ENTRIES) as $class) {
            $this->assertParity($class, ["pending\0\t1\t\t\t".self::NOW."\t\t".self::HEX, "17\tgen\t1\trust\t3", "news\t563\t1\t2\t\t\t\t".self::NOW]);
            $this->assertParity($class, ['k' => "\"a.par2\"\tp@x\t1\t10\t<a@b>", 'j' => self::packet('Creator', 'abcd', str_repeat('s', 16))]);
            $this->assertParity($class, [1 => "processing\t2\t\t1\t2\tw\t".self::HEX, 0 => str_repeat('a', 70)]);
            $this->assertParity($class, ["\0"]);
        }
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            $words = 1 + self::TAIL[$class];
            if ($class === Par2MetadataParser::class) {
                $one = pack('P', 1);
                self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, $one, 8, 1, $out, 64]), $class);
                self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, $one, 8, 1, null, 64]), $class);
                self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, $one, 8, 1, $out, $words - 1]), $class);
                self::assertSame(-1, $this->invoke($ffi, $symbol, ['ab', 2, $one, 8, 1, $out, 64]), $class);
                self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, $one, 7, 1, $out, 64]), $class);
                self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, pack('P', -1), 8, 1, $out, 64]), $class);
                self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, pack('P', 2), 8, 1, $out, 64]), $class);
                self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, $one, 8, 1, $out, 64]), $class);
                self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, null, 8, 1, $out, 64]), $class);
                self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, null, 0, 0, $out, 64]), $class);
                self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, '', 0, 1 << 21, $out, 64]), $class);
                // NUL bytes are payload here, not separators.
                self::assertSame($words + 1, $this->invoke($ffi, $symbol, ["a\0b", 3, pack('P2', 1, 2), 16, 2, $out, 64]), $class);

                continue;
            }
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 1, null, 64]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 2, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 64]), $class);
            self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, 0, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, 1 << 21, $out, 64]), $class);
        }
    }

    public function test_queue_lease_plan_parity(): void
    {
        $this->requireBindings();
        $h = self::HEX;
        $rows = ["pending\t0\t\t\t100\t\t{$h}\t", "pending\t1\t160\t\t100\t\t{$h}\t", "processing\t4\t\t90\t100\tw1\t{$h}\terr", "processing\t0\t\t150\t100\tW1\t{$h}\t",
            "failed\t5\t\t\t100\t\t{$h}\t", "bogus\t0\t\t\t100\t\t{$h}\t", " FAILED \t2\t90\t\t100\t\t".strtoupper($h)."\t x ", "processing\t1\t\t\t100\tw2\t{$h}",
            "completed\t1\t\t\t100\t\t{$h}", "stale\t\t\t\t100\t\t{$h}", "pending\t0\t\t\t\t\t{$h}", "pending\tx\t\t\t100\t\t{$h}", "pending\t0\t\t\t100\t\tabc"];
        $this->assertFields(DatabaseFingerprintQueue::class, $rows, (1 << 42) - 1, [
            1 | 2 << 4, 2 | 18 << 4 | 1 << 10 | 60 << 18, 4 | 47 << 4 | 4 << 10, 5 | 35 << 4 | 50 << 18, 6 | 2 << 4 | 5 << 10, 2 << 4, 7 | 26 << 4 | 2 << 10,
            3 | 3 << 4 | 1 << 10, 8 | 2 << 4 | 1 << 10, 9 | 2 << 4, 2 << 4, 2 << 4, 0,
        ], [4, 1, 1, 1, 1, 1, 1, 1, 1, 1, 3, 12, 1, 2, 2, 2, 1, 4, 4, 60, 50, 14]);
        $word = DatabaseFingerprintQueue::w62QueueLeasePlanBatchPhp(["processing\t9\t\t1\t2\tRust-1\t{$h}"])[0];
        self::assertSame([4, 3600, crc32('rust-1') & 0x1FF], [$word & 15, $word >> 42 & 0xFFF, $word >> 54 & 0x1FF]);
        $this->assertRandomParity(DatabaseFingerprintQueue::class, 6210);
    }

    public function test_dispatch_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["17\t\t1\trust\t", "18\tgen\t0\tlegacy\t2", "17\t\t\t\t", "0\t\t\t\t", "33\t g2 \tYES\t rust \t0", "49\t\ttrue\tphp\t9", "abc\t\t\t\t", "65\tgen\t\tx\t1234",
            '81'];
        $this->assertFields(FingerprintDispatch::class, $rows, (1 << 62) - 1, [
            1 << 2 | 1 << 5 | 1 << 8 | 1 << 9 | 1 << 10 | 17 << 30, 1 | 2 << 2 | 2 << 5 | 2 << 10 | 3 << 22 | 18 << 30, 3 << 5 | 1 << 7 | 1 << 9 | 1 << 10 | 17 << 30,
            2 | 4 << 2 | 1 << 9, 1 << 2 | 1 << 5 | 1 << 8 | 1 << 10 | 2 << 22 | 33 << 30, 1 | 3 << 2 | 3 << 5 | 1 << 10 | 49 << 30, 2 | 4 << 2 | 1 << 9,
            1 | 2 << 2 | 3 << 5 | 1 << 9 | 1 << 10 | 3 << 22 | 65 << 30, 3 << 5 | 1 << 9 | 1 << 10 | 81 << 30,
        ], [4, 3, 2, 2, 2, 2, 1, 2, 2, 2, 1, 4, 1, 1, 5, 1, 1, 1, 81]);
        $this->assertRandomParity(FingerprintDispatch::class, 6220);
    }

    public function test_breaker_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["News\t563\t1\t1\t\t1000\t\t1100\t900\t", "news\t0563\tyes\t1\t\t1000\t\t2000\t900\t", "news\t563\ttrue\t1\t\t1000\t\t2000\t900\t",
            "alt\t119\t0\t3\t950\t\t\t1000\t\t", "alt\t119\t0\t1\t950\t\t\t1000\t\t", "x\t0\t\t\t\t\t\t1\t\t", "b\t443\t\t\t\t1000\t1900\t2000\t900", "c\t443\t\t\t\t1000\t\t9000\t900",
            "d\t443\t\t5\t2000\t\t\t1000\t0", "e\t443\t\t\t\t\t\t\t", "f\t443\t\t1\t100\t\t\t1000\t\t", "g\t443\t\t2\t950\t\t\t1000\t60\t2"];
        $this->assertFields(FingerprintProviderBreaker::class, $rows, (1 << 34) - 1, [
            4 | 1 << 3 | 1 << 6 | 800 << 14, 6 | 1 << 3 | 1 << 5 | 1 << 6, 5 | 1 << 3 | 1 << 5 | 1 << 6, 3 | 3 << 6 | 850 << 14, 2 | 1 << 5 | 1 << 6 | 850 << 14, 0,
            5 | 1700 << 14, 7, 1 | 1 << 4 | 5 << 6, 0, 1 | 1 << 6, 3 | 2 << 6 | 10 << 14,
        ], [2, 2, 1, 2, 1, 2, 1, 1, 7, 3, 7, 2, 1, 3, 5]);
        $word = FingerprintProviderBreaker::w62BreakerPlanBatchPhp(["Eu.Host\t119\tON\t1\t\t\t\t5"])[0];
        self::assertSame([1, 119, crc32('eu.host:119:1') & 0x1FFF], [$word & 7, $word >> 34 & 0xFFFF, $word >> 50 & 0x1FFF]);
        $this->assertRandomParity(FingerprintProviderBreaker::class, 6230);
    }

    public function test_nzb_analysis_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["[1/3] - \"Movie.vol00+01.par2\" yEnc (1/2)\tposter@x\t2\t2048\t<a@b.c>", "[2/3] - \"Movie.par2\" yEnc (1/1)\tp\t1\t500\ta@b.c",
            "Movie.part01.rar (1/50)\t\t49\t1x\tbad", "Movie.r07\t\t\t\t", "\"x.PART3.RAR\" (2/2)\t\t2\t4096\t<q@B.C>", "\"a.7z\"\t\t\t\t", '"one quote.mkv',
            "\"Clip.Sample.mkv\" (1/1) (3/9)\t\t9\t9437184\t", "\"x.vol1+.par2\"\t\t\t\t", "\"a.001\"\t\t\t\t<a@b@c>", "\"a.r123\"\t\t\t\t<>", "readme.NFO\t\t\t\t<x@y>", ''];
        $this->assertFields(NzbFingerprintAnalyzer::class, $rows, (1 << 59) - 1, [
            2 | 1 << 4 | 47 << 7 | 2 << 29 | 1 << 45, 1 | 45 << 7 | 1 << 29, 3 | 2 << 4 | 20 << 7 | 1 << 13 | 49 << 29, 5 | 3 << 4 | 7 << 13,
            3 | 3 << 4 | 45 << 7 | 3 << 13 | 2 << 29 | 2 << 45, 6 | 4 << 4 | 32 << 7, 8 | 5 << 4, 8 | 5 << 4 | 44 << 7 | 9 << 29 | 1024 << 45, 1 | 32 << 7,
            5 | 3 << 4 | 32 << 7 | 1 << 13, 5 | 3 << 4 | 32 << 7 | 123 << 13, 7 | 6 << 4 | 1 << 7, 7 << 4,
        ], [1, 2, 1, 2, 0, 3, 1, 1, 2, 0, 2, 1, 1, 4, 1, 2, 1, 1, 4, 1, 5, 4, 1, 8, 12, 2, 8, 50, 9443828]);
        $word = NzbFingerprintAnalyzer::w62NzbAnalysisPlanBatchPhp(["\"Big.vol127+128.PAR2\"\tp\t100\t75000000\t<m@h>"])[0];
        self::assertSame([2, 127, 732, crc32('big.vol127+128.par2') & 0xF], [$word & 15, $word >> 13 & 0xFFFF, $word >> 45 & 0x3FFF, $word >> 59 & 0xF]);
        $this->assertRandomParity(NzbFingerprintAnalyzer::class, 6240);
    }

    public function test_par2_packet_plan_parity(): void
    {
        $this->requireBindings();
        $set = str_repeat("\x09", 16);
        $desc = self::packet('FileDesc', self::fileDesc(str_repeat("\x07", 16), str_repeat("\x01", 16), str_repeat("\x01", 16), 100, 'Movie.PAR2'), $set);
        $flipped = $desc;
        $flipped[70] = $flipped[70] ^ "\x01";
        $big = self::packet('FileDesc', self::fileDesc(str_repeat("\x05", 16), str_repeat("\x02", 16), str_repeat("\x03", 16), 5 << 20, 'a.r01'), $set);
        $zero = self::packet('FileDesc', self::fileDesc(str_repeat("\x06", 16), str_repeat("\x02", 16), str_repeat("\0", 16), 10, 'dir/x.mkv'), $set);
        $negative = self::packet('FileDesc', self::fileDesc(str_repeat("\x08", 16), str_repeat("\x02", 16), str_repeat("\x02", 16), -1, "bad\x01name"), $set);
        $shortDesc = self::packet('FileDesc', str_repeat("\x01", 52), $set);
        $badLength = substr_replace($desc, pack('P', 130), 8, 8);
        $rows = [$desc, self::packet('Creator', 'abcd', str_repeat("\x08", 16)), $flipped, 'short', substr($desc, 0, 70), 'XAR2'.substr($desc, 4), $badLength,
            $big, $zero, $negative, $shortDesc, self::packet('Main', str_repeat("\0", 12), $set), self::packet('Other', '', $set), self::packet('Foreign', '', $set)];
        $this->assertFields(Par2MetadataParser::class, $rows, (1 << 21) - 1, [
            6 | 2 << 3 | 4 << 7 | 2 << 10 | 10 << 13, 6 | 5 << 3 | 1 << 6, 5 | 2 << 3, 1, 4 | 2 << 3, 2, 3 | 2 << 3, 6 | 2 << 3 | 5 << 7 | 5 << 10 | 5 << 13,
            6 | 2 << 3 | 2 << 7 | 1 << 10 | 9 << 13, 6 | 2 << 3 | 3 << 7 | 6 << 10 | 8 << 13, 6 | 2 << 3, 6 | 1 << 3, 6 | 6 << 3, 6 | 7 << 3,
        ], [0, 1, 1, 1, 1, 1, 9, 2, 1, 8, 0, 0, 1, 1, 1, 10, 0, 1, 1, 1, 1, 0, 0, 10, 1, 1, 0, 0, 1, 1, 2, 1, 4, 908, 10]);
        self::assertSame(5, $this->call(Par2MetadataParser::class, 'Php', [$big])[0] >> 37 & 0x3FFFFF);
        $this->assertRandomParity(Par2MetadataParser::class, 6250);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     */
    #[DataProvider('edgeCases')]
    public function test_edge_case_parity(string $class, array $items): void
    {
        $this->requireBindings();
        $this->assertParity($class, $items);
        // NUL items cannot be framed in a string column (the export refuses them and the entry point falls back).
        if ($items !== [] && ($class === Par2MetadataParser::class || ! str_contains(implode('', $items), "\0"))) {
            self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
        }
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<string>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        $digits = ['0', '1', '-1', '+1', ' 1 ', "\x0b7\x0b", '1.5', '1e3', '99999', '100000', '999999999', '1000000000', '999999999999', '1000000000000',
            '999999999999999999', '1000000000000000000', '9223372036854775807', '0x10', '٣'];
        $shared = ['', ' ', "\t", "\t\t\t\t", str_repeat("\t", 12), "\x0b", '-', '.', '"', '""', '"x"', '(', '(1/2)', '<', '>', '<>', '@', str_repeat("\t", 5000).'1',
            "\xC9\xE1", $long, $binary, $long."\t".$long, $long."\t".$binary."\t".$long];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge and binary' => [$class, $shared];
        }
        $h = self::HEX;
        $queue = [];
        foreach (['pending', 'PROCESSING', 'failed', 'completed', 'stale', ' stale ', 'Pending', 'pend', ''] as $status) {
            foreach (['', '0', '4', '5', '255', '256', '999999999', '1000000000', 'x'] as $attempts) {
                $queue[] = "{$status}\t{$attempts}\t\t\t100\tw\t{$h}";
            }
        }
        foreach ($digits as $value) {
            array_push($queue, "pending\t{$value}\t\t\t100\t\t{$h}", "pending\t0\t{$value}\t\t100\t\t{$h}", "processing\t0\t\t{$value}\t100\tw\t{$h}",
                "failed\t0\t{$value}\t\t500\t\t{$h}", "pending\t0\t\t\t{$value}\t\t{$h}");
        }
        array_push($queue, "pending\t0\t\t\t100\t\t".substr($h, 1), "pending\t0\t\t\t100\t\t{$h}0", "pending\t0\t\t\t100\t\t".str_replace('a', 'g', $h),
            "pending\t0\t\t\t100\t\t {$h} ", "pending\t0\t\t\t100\t\t{$h}\t\t\t", "processing\t0\t\t100\t100\tw\t{$h}", "processing\t0\t\t101\t100\t \t{$h}",
            "pending\t0\t101\t\t100\t\t{$h}", "pending\t0\t100\t\t100\t\t{$h}", "processing\t0\t\t99999999\t0\tw\t{$h}", "failed\t4\t\t\t1\t\t{$h}\t\x0b",
            "processing\t3\t\t5\t9\t\xC9\t{$h}\terror\twith\ttabs");
        yield 'queue bounds' => [DatabaseFingerprintQueue::class, $queue];
        $dispatch = [];
        foreach ($digits as $value) {
            array_push($dispatch, "{$value}\tg\t\trust\t", "5\tg\t\tjob\t{$value}");
        }
        foreach (['1', 'true', 'TRUE', ' yes ', 'On', 'off', '0', 'no', 'y', ''] as $force) {
            foreach (['', 'rust', 'Rust', ' rust ', 'rusty', 'php'] as $worker) {
                $dispatch[] = "7\t gen \t{$force}\t{$worker}\t3";
            }
        }
        for ($i = 0; $i < 140; $i++) {
            $dispatch[] = ($i % 70 + 1)."\t".($i % 3 === 0 ? '' : 'gen'.($i % 5))."\t".($i % 2)."\t".($i % 4 === 0 ? 'job' : 'rust')."\t".($i % 7);
        }
        array_push($dispatch, "4294967295\tg\t\t\t", "4294967296\tg\t\t\t", "999999999999999999\t".str_repeat('g', 300)."\t\tjob\t");
        yield 'dispatch bounds' => [FingerprintDispatch::class, $dispatch];
        $breaker = [];
        foreach ($digits as $value) {
            array_push($breaker, "n\t{$value}\t\t\t\t\t\t100", "n\t563\t\t{$value}\t90\t\t\t100", "n\t563\t\t1\t{$value}\t\t\t100", "n\t563\t\t\t\t{$value}\t\t5000",
                "n\t563\t\t\t\t4000\t{$value}\t5000", "n\t563\t\t\t\t\t\t{$value}", "n\t563\t\t1\t90\t\t\t100\t{$value}", "n\t563\t\t1\t90\t\t\t100\t\t{$value}");
        }
        foreach ([0, 899, 900, 3599, 3600, 3601, 9999] as $age) {
            foreach (['', '1799', '1800', 'x'] as $probeAge) {
                $probe = $probeAge === '' || $probeAge === 'x' ? $probeAge : (string) (10000 - (int) $probeAge);
                $breaker[] = "p{$age}\t119\t1\t1\t\t".(10000 - $age)."\t{$probe}\t10000\t900\t";
                $breaker[] = "p{$age}\t119\t1\t1\t\t".(10000 - $age)."\t{$probe}\t10000\t900\t";
            }
        }
        array_push($breaker, "n\t65535\t\t\t\t\t\t1", "n\t65536\t\t\t\t\t\t1", " \t563\t\t\t\t\t\t1", "N\t563\tTrue\t9999999\t9\t\t\t10\t9999999\t9999",
            "n\t563\t\t1\t10\t\t\t10\t1\t", "n\t563\t\t1\t9\t\t\t10\t1\t", "n\t563\t\t3\t11\t\t\t10\t\t", "n\t563\t\t3\t1\t1\t1\t10\t0\t");
        yield 'breaker bounds' => [FingerprintProviderBreaker::class, $breaker];
        $nzb = [];
        foreach (['x.par2', 'x.PAR2', 'x.par2x', 'x.par2_', 'x.vol0+1.par2', 'x.vol+1.par2', 'x.vol1+.par2', 'x.vol1x2.par2', 'x.vol1234567890+1.par2',
            'x.vol123456789-123456789.par2', 'x.vol1+2.vol3+4.par2', '.vol1+2.par2', 'x.part.rar', 'x.part0.rar', 'x.part12345.rar', 'x.part1.part2.rar', 'x.PART01.RAR',
            'x.rar', 'x.r0', 'x.r00', 'x.r0000', 'x.r1x', 'x.00', 'x.000', 'x.0000', 'x.abcdefgh', 'x.abcdefghi', 'x.', 'x', 'x.tar.gz', 'x.BZ2', 'x.ts', 'x.epub',
            'x.mkv ', 'x .mkv', 'x.m-kv', "x.\xC9mkv", 'x.mkv"'] as $file) {
            array_push($nzb, "\"{$file}\" yEnc (1/1)\t\t1\t1\t<a@b>", "{$file}\t\t\t\t");
        }
        foreach (['(1/2)', '(1/2) (3/4)', '(1/2(3/4)', '(0/0)', '(1234567890/2)', '(1/1234567890)', '(123456789/123456789)', '(/1)', '(1/)', '[1/2]', '( 1/2)', '(1/2'] as $counter) {
            array_push($nzb, "\"a.rar\" {$counter}\t\t3\t30\t", "a.rar {$counter}\t\t0\t0\t");
        }
        foreach (['', 'a@b', '<a@b>', ' <a@b> ', '<a@b', 'a@b>', '<<a@b>>', '@b', 'a@', 'a@@b', 'a@b@c', 'a b@c', "a\t@b", 'a@b<c', '<>', '<@>', "\xC9@\xE1"] as $mid) {
            $nzb[] = "\"a.nfo\"\tposter\t1\t1\t{$mid}";
        }
        foreach ($digits as $value) {
            array_push($nzb, "\"a.par2\" (1/2)\t\t{$value}\t1000\t", "\"a.par2\" (1/2)\t\t2\t{$value}\t");
        }
        array_push($nzb, "\"a.par2\"\t\t3\t1000", "\"b.par2\"\t\t3\t999", "\"c.par2\"\t\t3\t999", "\"z.zip\"\t\t1\t0", "\"\"\t\t\t\t", "\"\"x.par2\"\t\t\t\t");
        yield 'nzb bounds' => [NzbFingerprintAnalyzer::class, $nzb];
        yield 'par2 bounds' => [Par2MetadataParser::class, self::par2Bounds()];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical rows, Uncommon batches 320-640
     * malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every round's
     * samples; per-round medians are reported too. Every pair must agree; raw pairs are written
     * outside the checkout. A class must default to Rust exactly when all four metrics reach 1.5x.
     * Gated behind NNTMUX_W622_BENCH=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W622_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W622_BENCH=1 to run the Wave 62.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(62));
        $fixtures = $samples = $records = [];
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach (['Common', 'Uncommon'] as $workload) {
                for ($v = 0; $v < 8; $v++) {
                    $fixtures[$class][$workload][] = $this->fixture($class, $random, $workload === 'Uncommon');
                }
            }
        }
        for ($round = 0; $round < self::ROUNDS; $round++) {
            foreach (array_keys(self::ENTRIES) as $class) {
                foreach (['Common', 'Uncommon'] as $workload) {
                    for ($i = -self::WARMUPS; $i < self::PAIRS; $i++) {
                        $variant = $random->getInt(0, 7);
                        $order = ($i & 1) === 0 ? [false, true] : [true, false];
                        $results = $elapsed = [];
                        foreach ($order as $native) {
                            $items = $fixtures[$class][$workload][$variant];
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW62RustFfi($native, fn (): array => $this->timed($class, $items));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][$round][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $rounds) {
                $php = $rust = $roundMeans = $roundP99s = [];
                foreach ($rounds as $pairs) {
                    $roundPhp = array_column($pairs, 0);
                    $roundRust = array_column($pairs, 1);
                    $roundMeans[] = array_sum($roundPhp) / array_sum($roundRust);
                    $roundP99s[] = $this->p99($roundPhp) / $this->p99($roundRust);
                    array_push($php, ...$roundPhp);
                    array_push($rust, ...$roundRust);
                }
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'round_median_mean_speedup' => round($this->median($roundMeans), 3), 'round_median_p99_speedup' => round($this->median($roundP99s), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w622-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'summary' => $summary, 'records' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW62.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW62RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * Every rule is byte-level ASCII, so an explicit setlocale() changes neither implementation
     * and the exports keep running. Declared last: once a script sets a locale, later regexes
     * keep the rebuilt tables.
     */
    public function test_parity_holds_under_locale_tables(): void
    {
        $this->requireBindings();
        $locale = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C.UTF-8', 'en_US.ISO8859-1', 'tr_TR.ISO8859-9'] as $candidate) {
                if (setlocale(LC_CTYPE, $candidate) === false) {
                    continue;
                }
                $h = self::HEX;
                $cases = [
                    DatabaseFingerprintQueue::class => ["PEND\xC9NG\t0\t\t\t1\t\t{$h}", "PROCESSING\t1\t\t9\t1\tW\xDD\t".strtoupper($h), "I\tİ\t\t\t1\t\t{$h}"],
                    FingerprintDispatch::class => ["7\tG\xC9N\tYES\tRUST\t1", "8\tg\tİ\tRUSTİ\t2"],
                    FingerprintProviderBreaker::class => ["N\xC9WS\t563\tTRUE\t1\t\t1\t\t5\t", "I\t119\tON\t2\t4\t\t\t5\t\t1"],
                    NzbFingerprintAnalyzer::class => ["\"M\xC9.PAR2\" (1/2)\tA@B\t2\t9\t<I@\xC9>", "\"X.VOL1+2.PAR2\"\t\t\t\t", "\"X.PART1.RAR\xC9\"\t\t\t\t"],
                    Par2MetadataParser::class => [self::packet('FileDesc', self::fileDesc(str_repeat('i', 16), str_repeat('a', 16), str_repeat('a', 16), 9, "\xC9.PAR2"),
                        str_repeat('s', 16)), self::packet('FileDesc', self::fileDesc(str_repeat('i', 16), str_repeat('a', 16), str_repeat('b', 16), 9, 'I.R0İ'),
                            str_repeat('s', 16))],
                ];
                foreach ($cases as $class => $items) {
                    $this->assertParity($class, $items);
                    self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
                }
            }
        } finally {
            setlocale(LC_CTYPE, (string) $locale);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * Documented per-row fields (`word & $mask`) and summary words (without the digest) from both
     * implementations.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $rows
     * @param  list<int>  $fields
     * @param  list<int>  $tail
     */
    private function assertFields(string $class, array $rows, int $mask, array $fields, array $tail): void
    {
        $this->assertParity($class, $rows);
        foreach (['Php', 'Native'] as $suffix) {
            $words = $this->call($class, $suffix, $rows);
            self::assertSame($fields, array_map(static fn (int $word): int => $word & $mask, \array_slice($words, 0, \count($rows))), $class.'::'.$suffix);
            self::assertSame($tail, \array_slice($words, \count($rows), -1), $class.'::'.$suffix);
        }
        self::assertSame($this->call($class, 'Php', $rows), $this->exported($class, $rows), $class);
    }

    /**
     * The fastest of REPEATS back-to-back calls of the class's batch entry point.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return array{list<int>, int}
     */
    private function timed(string $class, array $items): array
    {
        $best = PHP_INT_MAX;
        $result = [];
        for ($r = 0; $r < self::REPEATS; $r++) {
            $start = hrtime(true);
            $result = $this->call($class, '', $items);
            $best = min($best, hrtime(true) - $start);
        }

        return [$result, $best];
    }

    /** @param  list<int>  $values */
    private function p99(array $values): int
    {
        sort($values);

        return $values[max(0, (int) ceil(\count($values) * 0.99) - 1)];
    }

    /** @param  array<int|float>  $values */
    private function median(array $values): int|float
    {
        sort($values);

        return $values[intdiv(\count($values), 2)] ?? 0;
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W62_RUST_FFI_DEFAULT');
        self::assertIsBool($value);

        return $value;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    private function call(string $class, string $suffix, array $items): array
    {
        /** @var list<int> */
        return [$class, self::ENTRIES[$class].$suffix]($items);
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @param  array<array-key, string>  $items
     */
    private function assertParity(string $class, array $items): void
    {
        $php = $class::withW62RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW62RustFfi(true, fn (): array => $this->call($class, '', $items));
        self::assertSame($php, $rust, $class);
        self::assertSame($php, $this->call($class, 'Php', $items), $class);
        self::assertSame($php, $this->call($class, 'Native', $items), $class);
        self::assertCount(\count($items) + self::TAIL[$class], $php, $class);
        self::assertSame($this->digest(\array_slice($php, 0, -1)), $php[\count($php) - 1], $class);
    }

    /**
     * PARITY_BATCHES Common and Uncommon batches (at least 320 rows each) must match, and the
     * Rust export must actually produce them (no silent PHP fallback).
     *
     * @param  key-of<self::ENTRIES>  $class
     */
    private function assertRandomParity(string $class, int $seed): void
    {
        $items = 0;
        foreach ([false, true] as $uncommon) {
            for ($i = 0; $i < self::PARITY_BATCHES; $i++) {
                $batch = $this->fixture($class, new Randomizer(new Mt19937($seed + 2 * $i + (int) $uncommon)), $uncommon);
                $this->assertParity($class, $batch);
                self::assertSame($this->call($class, 'Php', $batch), $this->exported($class, $batch), $class);
                $items += \count($batch);
            }
        }
        self::assertGreaterThanOrEqual(300 * 2 * self::PARITY_BATCHES, $items, $class);
    }

    /**
     * Calls the export directly with the entry point's framing.
     *
     * @param  key-of<self::ENTRIES>  $class
     * @param  list<string>  $items
     * @return list<int>
     */
    private function exported(string $class, array $items): array
    {
        $ffi = $this->ffi($class);
        $count = \count($items);
        $cap = $count + 64;
        $out = $ffi->new('int64_t['.$cap.']');
        if ($class === Par2MetadataParser::class) {
            $blob = implode('', $items);
            $lengths = pack('P*', ...array_map(strlen(...), $items));
            $written = $this->invoke($ffi, self::SYMBOLS[$class], [$blob, \strlen($blob), $lengths, \strlen($lengths), $count, $out, $cap]);
        } else {
            $blob = implode("\0", $items);
            $written = $this->invoke($ffi, self::SYMBOLS[$class], [$blob, \strlen($blob), $count, $out, $cap]);
        }
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w62RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w62 binding');

        return $ffi;
    }

    /** Each Wave 62.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            $args = $class === Par2MetadataParser::class ? ['a', 1, pack('P', 1), 8, 1, $out, 64] : ['a', 1, 1, $out, 64];
            self::assertSame(1 + self::TAIL[$class], $this->invoke($ffi, $symbol, $args), $class);
        }
    }

    /**
     * Calls an export declared by a class's W62 C declaration.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** @param  list<int>  $words */
    private function digest(array $words): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));

        return $digest[1];
    }

    /** A PAR2 packet of $type (a TYPES key) with a correct header MD5. */
    private static function packet(string $type, string $body, string $setId): string
    {
        $tail = $setId.self::TYPES[$type].$body;

        return "PAR2\0PKT".pack('P', 32 + \strlen($tail)).md5($tail, true).$tail;
    }

    /** A FileDesc body with the name NUL-padded to a multiple of four bytes. */
    private static function fileDesc(string $fileId, string $md5, string $md516k, int $length, string $name): string
    {
        return $fileId.$md5.$md516k.pack('P', $length).str_pad($name, (int) ceil(\strlen($name) / 4) * 4, "\0");
    }

    /** @return list<string> */
    private static function par2Bounds(): array
    {
        $set = str_repeat('S', 16);
        $id = str_repeat('I', 16);
        $hash = str_repeat('h', 16);
        $rows = [];
        foreach (['', 'a', '.hidden', 'a/b', 'a\\b', "a\x1Fb", "a\x7Fb", "a\0b", 'a.par2', 'a.PAR2', 'a.rar', 'a.r00', 'a.r0', 'a.r000', 'a.rxx', 'a.', 'a.b.c', "a\xC9.mkv",
            str_repeat('n', 300)] as $name) {
            $rows[] = self::packet('FileDesc', self::fileDesc($id, $hash, $hash, 100, $name), $set);
        }
        foreach ([0, 1, 16383, 16384, 16385, 1 << 20, (1 << 20) - 1, PHP_INT_MAX, -1, PHP_INT_MIN] as $length) {
            foreach ([$hash, str_repeat("\0", 16), str_repeat('x', 16)] as $hash16k) {
                $rows[] = self::packet('FileDesc', self::fileDesc($id, $hash, $hash16k, $length, 'f.bin'), $set);
            }
        }
        $valid = self::packet('Main', str_repeat("\0", 12), $set);
        foreach ([0, 4, 60, 63, 64, 65, 66, 67, 68, 72, 76, 77, 80, 1 << 40, -64, PHP_INT_MIN] as $declared) {
            $rows[] = substr_replace($valid, pack('P', $declared), 8, 8);
        }
        foreach ([0, 7, 8, 15, 16, 31, 32, 47, 48, 63, 64, 75] as $cut) {
            $rows[] = substr($valid, 0, $cut);
        }
        foreach ([0, 4, 8, 16, 31, 32, 47, 48, 63] as $flip) {
            $mutated = $valid;
            $mutated[$flip] = $mutated[$flip] ^ "\x20";
            $rows[] = $mutated;
        }
        foreach (array_keys(self::TYPES) as $type) {
            array_push($rows, self::packet($type, '', $set), self::packet($type, str_repeat("\0", 56), str_repeat('T', 16)));
        }
        $rows[] = self::packet('FileDesc', str_repeat('z', 52), $set);
        $rows[] = self::packet('FileDesc', str_repeat('z', 56), $set);
        $rows[] = $valid.'trailing bytes past the declared length';
        $rows[] = str_repeat("\0", 64);
        $rows[] = str_repeat("\xFF", 200);

        return $rows;
    }

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<string>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        $items = [];
        for ($i = $uncommon ? $random->getInt(320, 640) : 320; $i > 0; $i--) {
            $items[] = match ($class) {
                DatabaseFingerprintQueue::class => $this->queueRow($random, $uncommon),
                FingerprintDispatch::class => $this->dispatchRow($random, $uncommon),
                FingerprintProviderBreaker::class => $this->breakerRow($random, $uncommon),
                NzbFingerprintAnalyzer::class => $this->nzbRow($random, $uncommon),
                Par2MetadataParser::class => $this->par2Row($random, $uncommon),
            };
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /** Printable noise, or binary noise without NUL bytes. */
    private function noise(Randomizer $random, int $max, bool $binary = false): string
    {
        $length = $random->getInt(0, $max);
        if ($length === 0) {
            return '';
        }

        return $binary ? str_replace("\0", "\1", $random->getBytes($length))
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-[]()<>@/+\"' \t", $length);
    }

    /** $length random bytes (none for zero). */
    private function bytes(Randomizer $random, int $length): string
    {
        return $length > 0 ? $random->getBytes($length) : '';
    }

    /** Case-flips, pads or corrupts a field the way hand-edited or truncated data does. */
    private function mangle(Randomizer $random, string $value): string
    {
        return match ($random->getInt(0, 6)) {
            0 => strtoupper($value),
            1 => ucfirst(strtolower($value)),
            2 => $this->pick($random, [' ', "\r\n", "\x0b", '']).$value.$this->pick($random, [' ', "\r\n", "\x0b", '']),
            3 => substr($value, 0, $random->getInt(0, max(0, \strlen($value) - 1))),
            4 => $value.$this->noise($random, 3),
            5 => str_replace(['a', 'e', 'o', '1'], ["\xE1", "\xC9", '0', $this->pick($random, ['l', ' ', '-', '11'])], $value),
            default => $value,
        };
    }

    /**
     * Replaces one random field of a tab-joined row with $mutate($field), or drops or adds
     * trailing fields.
     *
     * @param  list<string>  $fields
     * @param  callable(string): string  $mutate
     */
    private function mutateFields(Randomizer $random, array $fields, callable $mutate): string
    {
        $k = $random->getInt(0, \count($fields) - 1);
        match ($random->getInt(0, 5)) {
            0 => $fields = \array_slice($fields, 0, $k),
            1 => $fields[] = $this->noise($random, 10),
            default => $fields[$k] = $mutate($fields[$k]),
        };

        return implode("\t", $fields);
    }

    private function queueRow(Randomizer $random, bool $uncommon): string
    {
        $now = self::NOW + $random->getInt(0, 86400);
        $status = $this->pick($random, ['pending', 'pending', 'pending', 'processing', 'processing', 'failed', 'completed', 'stale']);
        $attempts = (string) $this->pick($random, [0, 0, 1, 1, 2, 3, 4, 5, 6]);
        $available = $this->pick($random, ['', '', (string) ($now + $random->getInt(-900, 900))]);
        $lease = $status === 'processing' ? $this->pick($random, ['', (string) ($now + $random->getInt(-900, 900)), (string) ($now + $random->getInt(1, 1800))]) : '';
        $worker = $status === 'processing' ? $this->pick($random, ['rust-1', 'rust-2', 'RUST-1', 'host-a:4242', 'host-b:17', '']) : $this->pick($random, ['', '', 'rust-1']);
        $error = $this->pick($random, ['', '', '', 'timeout', 'NNTP 430 no such article', 'provider unavailable']);
        $fields = [$status, $attempts, $available, $lease, (string) $now, $worker, bin2hex($random->getBytes(32)), $error];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 80),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['-1', '99999999999999', 'x', ' 7 ', '1e3', ''])),
            5 => implode("\t", [$this->pick($random, ['PENDING', ' processing ', 'Failed', 'queued', 'done']), ...\array_slice($fields, 1)]),
            6 => implode("\t", [...\array_slice($fields, 0, 6), $this->pick($random, [strtoupper($fields[6]), substr($fields[6], 1), $fields[6].'0', 'zz'.substr($fields[6], 2)]),
                $error]),
            7 => implode("\t", [...\array_slice($fields, 0, 4), (string) $random->getInt(0, 99), ...\array_slice($fields, 5)]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function dispatchRow(Randomizer $random, bool $uncommon): string
    {
        $id = (string) ($random->getInt(0, 3) === 0 ? $random->getInt(1, 200) : $random->getInt(1, 50_000_000));
        $fields = [$id, $this->pick($random, ['', '', 'gen-'.$random->getInt(1, 40), bin2hex($random->getBytes(8))]), $this->pick($random, ['', '0', '1', 'true', 'false']),
            $this->pick($random, ['rust', 'rust', 'rust', '', 'legacy']), $this->pick($random, ['', '', '1', '3', '5', '8'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 60, true),
            1 => $this->noise($random, 40),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => implode("\t", [$this->pick($random, ['0', '-1', 'abc', '99999999999999999999', '4294967296', ' 12 ', '']), ...\array_slice($fields, 1)]),
            5 => implode("\t", [$fields[0], $fields[1], $this->pick($random, ['YES', ' On ', 'off', 'y', 'TRUE']), $this->pick($random, ['Rust', ' rust ', 'rusty', 'php']),
                $this->pick($random, ['1000', 'x', '-1', '255'])]),
            6 => implode("\t", [$fields[0], str_repeat('g', $random->getInt(200, 400)), ...\array_slice($fields, 2)]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function breakerRow(Randomizer $random, bool $uncommon): string
    {
        $now = self::NOW + $random->getInt(0, 86400);
        $opened = $random->getInt(0, 2) === 0 ? (string) ($now - $random->getInt(0, 6000)) : '';
        $fields = [$this->pick($random, ['news.example.com', 'NEWS.example.com', 'eu.provider.net', 'backup.host', 'alt.host']), $this->pick($random, ['563', '119', '443']),
            $this->pick($random, ['1', '0', 'true', '']), (string) $random->getInt(0, 8), $this->pick($random, ['', (string) ($now - $random->getInt(0, 2000))]), $opened,
            $opened === '' ? '' : $this->pick($random, ['', '', (string) ($now - $random->getInt(0, 3000))]), (string) $now, $this->pick($random, ['', '', '900', '300', '60']),
            $this->pick($random, ['', '', '1', '3', '5'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, ['0', '-1', '70000', 'x', '99999999999999', ' 5 '])),
            5 => implode("\t", [...\array_slice($fields, 0, 4), (string) ($now + $random->getInt(1, 500)), (string) ($now + $random->getInt(-500, 500)),
                (string) ($now + $random->getInt(-2000, 500)), ...\array_slice($fields, 7)]),
            6 => implode("\t", [...\array_slice($fields, 0, 8), $this->pick($random, ['0', '9999999', '1']), $this->pick($random, ['0', '9999', '2'])]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function nzbRow(Randomizer $random, bool $uncommon): string
    {
        $words = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $words[] = ucfirst($this->pick($random, self::WORDS));
        }
        $total = $random->getInt(1, 60);
        $file = implode($this->pick($random, ['.', '.', '_', ' ']), $words).'.'.$random->getInt(1990, 2025).$this->pick($random, self::EXTENSIONS);
        $segments = $random->getInt(1, 200);
        $subject = \sprintf('[%d/%d] - "%s" yEnc (%d/%d)', $random->getInt(1, $total), $total, $file, $random->getInt(1, $segments), $segments);
        $fields = [$subject, $this->pick($random, ['poster@example.com', 'Uploader <up@news.host>', 'anon']),
            (string) ($random->getInt(0, 4) === 0 ? $segments - $random->getInt(1, $segments) : $segments), (string) ($segments * $random->getInt(300_000, 800_000)),
            '<'.bin2hex($random->getBytes(8)).'@'.$this->pick($random, ['news.example.com', 'POWERPOST2000', 'ngPost.local', 'Host.Name']).'>'];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 120, true),
            1 => $this->noise($random, 80),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => implode("\t", [str_replace('"', $this->pick($random, ['', "'", '""']), $subject), ...\array_slice($fields, 1)]),
            5 => implode("\t", [$subject.' ('.$this->pick($random, ['1/', '1234567890/3', '2/2', '0/0', '/']).')'.$this->noise($random, 8), ...\array_slice($fields, 1)]),
            6 => implode("\t", [...\array_slice($fields, 0, 4), $this->pick($random, ['a@b@c', '<>', '@x', 'x@', 'with space@x', '<a@b', 'a@b>', '<<a@b>>'])]),
            7 => implode("\t", [$fields[0], $fields[1], $this->pick($random, ['x', '-1', '9999999999']), $this->pick($random, ['1e9', '9999999999999', ''])]),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 16, true)),
        };
    }

    private function par2Row(Randomizer $random, bool $uncommon): string
    {
        $set = $this->pick($random, [str_repeat("\x11", 16), str_repeat("\x11", 16), str_repeat("\x11", 16), str_repeat("\x22", 16)]);
        $type = $this->pick($random, ['FileDesc', 'FileDesc', 'FileDesc', 'IFSC', 'Main', 'RecvSlic', 'Creator']);
        $md5 = $random->getBytes(16);
        $length = $this->pick($random, [$random->getInt(0, 16384), $random->getInt(16385, 1 << 34)]);
        $name = implode('.', [ucfirst($this->pick($random, self::WORDS)), ucfirst($this->pick($random, self::WORDS))]).$this->pick($random, ['.mkv', '.part01.rar', '.r00',
            '.par2', '.nfo', '']);
        $body = match ($type) {
            'FileDesc' => self::fileDesc($random->getBytes(16), $md5, $length <= 16384 ? $md5 : $random->getBytes(16), $length, $name),
            'IFSC' => $random->getBytes(16).$random->getBytes(20 * $random->getInt(1, 8)),
            'Main' => pack('PV', 1 << 20, $random->getInt(1, 4)).$random->getBytes(16 * $random->getInt(1, 4)),
            'RecvSlic' => pack('V', $random->getInt(0, 65535)).$random->getBytes(4 * $random->getInt(16, 64)),
            default => str_pad('Created by par2cmdline version 0.8.1', 36, "\0"),
        };
        $packet = self::packet($type, $body, $set);
        if (! $uncommon) {
            return $packet;
        }

        return match ($random->getInt(0, 9)) {
            0 => $this->bytes($random, $random->getInt(0, 200)),
            1 => substr($packet, 0, $random->getInt(0, \strlen($packet) - 1)),
            2 => substr_replace($packet, $random->getBytes(1), $random->getInt(0, \strlen($packet) - 1), 1),
            3 => substr_replace($packet, pack('P', $this->pick($random, [0, 63, 66, \strlen($packet) + 4, -8, PHP_INT_MAX])), 8, 8),
            4 => self::packet('FileDesc', self::fileDesc($random->getBytes(16), $md5, $this->pick($random, [str_repeat("\0", 16), $md5, $random->getBytes(16)]),
                $this->pick($random, [-1, PHP_INT_MIN, 0, 16384, 16385]), $this->pick($random, ['', '../etc/passwd', '.hidden', "a\x01b", 'C:\\x.rar', "\xC9\xE1.R12"])), $set),
            5 => self::packet($this->pick($random, ['Other', 'Foreign']), $this->bytes($random, 4 * $random->getInt(0, 16)), $random->getBytes(16)),
            6 => $packet.$random->getBytes($random->getInt(1, 40)),
            7 => self::packet('FileDesc', $this->bytes($random, 4 * $random->getInt(0, 13)), $set),
            default => "PAR2\0PKX".substr($packet, 8),
        };
    }
}
