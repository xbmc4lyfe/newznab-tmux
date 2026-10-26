<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbUploadStagingService;
use App\Services\Nzb\NzbXmlElements;
use App\Services\Nzb\Par2CandidateRecovery;
use App\Services\Nzb\Par2CandidateRecoveryResult;
use App\Services\Nzb\ReleaseArticleFingerprintStore;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class RustNzbUploadStagingXmlElementsPar2RecoveryResultFingerprintStoreW60ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        NzbUploadStagingService::class => 'planUploadStagingBatch',
        NzbXmlElements::class => 'summarizeXmlElementBatch',
        Par2CandidateRecovery::class => 'planCandidateRecoveryBatch',
        Par2CandidateRecoveryResult::class => 'summarizeRecoveryResultBatch',
        ReleaseArticleFingerprintStore::class => 'planFingerprintStoreBatch',
    ];

    /** Class => Wave 60.2 export. */
    private const SYMBOLS = [
        NzbUploadStagingService::class => 'nntmux_w602_nzb_upload_staging_plan_batch',
        NzbXmlElements::class => 'nntmux_w602_nzb_xml_element_summary_batch',
        Par2CandidateRecovery::class => 'nntmux_w602_par2_candidate_recovery_plan_batch',
        Par2CandidateRecoveryResult::class => 'nntmux_w602_par2_recovery_result_summary_batch',
        ReleaseArticleFingerprintStore::class => 'nntmux_w602_release_article_fingerprint_store_plan_batch',
    ];

    /** Class => private C declaration constant. */
    private const CDEFS = [
        NzbUploadStagingService::class => 'W60_UPLOAD_STAGING_FFI_CDEF',
        NzbXmlElements::class => 'W60_XML_ELEMENTS_FFI_CDEF',
        Par2CandidateRecovery::class => 'W60_CANDIDATE_RECOVERY_FFI_CDEF',
        Par2CandidateRecoveryResult::class => 'W60_RECOVERY_RESULT_FFI_CDEF',
        ReleaseArticleFingerprintStore::class => 'W60_FINGERPRINT_STORE_FFI_CDEF',
    ];

    /** Class => summary words after the per-row words (the digest included). */
    private const TAIL = [
        NzbUploadStagingService::class => 8,
        NzbXmlElements::class => 15,
        Par2CandidateRecovery::class => 10,
        Par2CandidateRecoveryResult::class => 10,
        ReleaseArticleFingerprintStore::class => 10,
    ];

    /** Class => public tail-size constant. */
    private const TAIL_CONSTANTS = [
        NzbUploadStagingService::class => 'W60_UPLOAD_STAGING_TAIL',
        NzbXmlElements::class => 'W60_XML_ELEMENTS_TAIL',
        Par2CandidateRecovery::class => 'W60_CANDIDATE_RECOVERY_TAIL',
        Par2CandidateRecoveryResult::class => 'W60_RECOVERY_RESULT_TAIL',
        ReleaseArticleFingerprintStore::class => 'W60_FINGERPRINT_STORE_TAIL',
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 60, 60
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 600 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon rows, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 6-7). Timings include dispatch, implode, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        NzbUploadStagingService::class => [4.998, 5.086, 6.158, 6.402],
        NzbXmlElements::class => [10.242, 10.757, 9.612, 9.766],
        Par2CandidateRecovery::class => [6.265, 5.628, 6.437, 6.594],
        Par2CandidateRecoveryResult::class => [7.491, 7.125, 7.324, 7.108],
        ReleaseArticleFingerprintStore::class => [4.359, 4.505, 4.647, 5.132],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 60;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const RELEASES = [
        'Some.Show.S01E02.720p.HDTV.x264-GRP', 'The.Great.Movie.2019.1080p.BluRay.x264-SPARKS', 'Docu.Series.S03E10.1080p.WEB.h264-GOSSIP',
        'Show.Name.S02.1080p.BluRay.x264-ROVERS', 'Artist - Album (2020) [FLAC]', 'Big Game 2022 2160p', 'Night_Show_2023_10_05_720p_WEB_h264-JEBAITED',
    ];

    private const WORDS = ['the', 'great', 'movie', 'show', 'night', 'big', 'game', 'tool', 'pro', 'film', 'series', 'docu', 'saga', 'old', 'new'];

    private const PACKETS = ['main', 'filedesc', 'ifsc', 'recvslice', 'creator'];

    private const OUTCOMES = ['renamed', 'no_match', 'retryable', 'deferred'];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['usesW60RustFfi', 'withW60RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w60RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertTrue($reflection->hasConstant('W60_RUST_FFI_DEFAULT'), $class);
            self::assertIsBool($reflection->getConstant('W60_RUST_FFI_DEFAULT'), $class);
            self::assertTrue($reflection->getReflectionConstant('W60_RUST_FFI_DEFAULT')?->isPublic(), $class);
            self::assertTrue($reflection->getReflectionConstant(self::CDEFS[$class])?->isPrivate(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            self::assertSame(self::TAIL[$class], $reflection->getConstant(self::TAIL_CONSTANTS[$class]), $class);
            foreach (array_keys($reflection->getStaticProperties()) as $property) {
                self::assertStringNotContainsString('w60', strtolower((string) $property), 'Wave 60 state lives in w60RustFfiState().');
            }
        }
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            NzbUploadStagingService::class => ['setUseRustFfi', 'usesRustFfi', 'stage', 'stageLegacyFile', 'validateFilenamePhp', 'validateFilenameNative',
                'hasGzipMagicPhp', 'hasGzipMagicNative', 'hasUtf8BomPhp', 'hasUtf8BomNative', 'xmlPayloadPhp', 'xmlPayloadNative', 'useW422RustFfi',
                'usesW422RustFfi', 'resetW422RustFfi', 'summarizeUploadFilenames', 'summarizeUploadFilenamesPhp', 'summarizeUploadFilenamesNative'],
            NzbXmlElements::class => ['setUseRustFfi', 'validRoot', 'validRootPhp', 'validRootNative', 'validPasswordStructure', 'validPasswordStructurePhp',
                'validPasswordStructureNative', 'isPasswordMetadata', 'isPasswordMetadataPhp', 'isPasswordMetadataNative', 'files', 'groups', 'segments',
                'metadata', 'assertUnambiguousPasswordMetadata', 'useW422RustFfi', 'usesW422RustFfi', 'resetW422RustFfi', 'summarizeMetadataTypes',
                'summarizeMetadataTypesPhp', 'summarizeMetadataTypesNative'],
            Par2CandidateRecovery::class => ['setUseRustFfi', 'usesRustFfi', 'recover', 'validatePartNative', 'validatePartPhp', 'useW422RustFfi',
                'usesW422RustFfi', 'resetW422RustFfi', 'summarizePartOffsets', 'summarizePartOffsetsPhp', 'summarizePartOffsetsNative'],
            Par2CandidateRecoveryResult::class => ['setUseRustFfi', 'usesRustFfi', 'metrics', 'summarizeRecoveryMetrics', 'summarizeRecoveryMetricsPhp',
                'summarizeRecoveryMetricsNative', 'useW432RustFfi', 'usesW432RustFfi', 'resetW432RustFfi', 'evaluateW432Native', 'summarizeRecoveryBatch',
                'summarizeRecoveryBatchPhp', 'summarizeRecoveryBatchNative'],
            ReleaseArticleFingerprintStore::class => ['enableRustFfi', 'disableRustFfi', 'resetRustFfi', 'usesRustFfi', 'columnExists', 'store', 'candidates',
                'updatePayload', 'updatePayloadPhp', 'updatePayloadNative', 'useW432RustFfi', 'usesW432RustFfi', 'resetW432RustFfi',
                'summarizeFingerprintBatch', 'summarizeFingerprintBatchPhp', 'summarizeFingerprintBatchNative'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            self::assertTrue($reflection->isFinal(), $class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        self::assertSame('.staging', NzbUploadStagingService::STAGING_DIRECTORY);
        self::assertSame('Show', NzbUploadStagingService::validateFilenamePhp('Show.nzb', 'nzb'));
        self::assertTrue(NzbUploadStagingService::hasGzipMagicPhp("\x1f\x8bxx"));
        self::assertSame('http://www.newzbin.com/DTD/2003/nzb', NzbXmlElements::CANONICAL_NAMESPACE);
        self::assertTrue(NzbXmlElements::validRootPhp('nzb', ''));
        self::assertSame('article_fingerprint', ReleaseArticleFingerprintStore::COLUMN);
        self::assertSame(['article_fingerprint' => 'abc'], ReleaseArticleFingerprintStore::updatePayloadPhp('abc'));
        self::assertSame(1, Par2CandidateRecoveryResult::summarizeRecoveryMetricsPhp('renamed', 2, 3)['articles_per_probe']);
        self::assertSame(1, Par2CandidateRecovery::summarizePartOffsetsPhp([['begin' => 1, 'end' => 5]], 5)['contiguous']);
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW60RustFfi(), $class);
            if (min($speedups) < 1.5) {
                self::assertFalse($class::usesW60RustFfi(), $class.' retains PHP by default');
            }
        }
    }

    public function test_with_toggle_scopes_and_restores_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW60RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW60RustFfi(! $enabled, static fn (): bool => $class::usesW60RustFfi());

                    return [$class::usesW60RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW60RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW60RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW60RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW60RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW60RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        // The Wave 60 toggles leave the earlier switches untouched.
        $earlier = [
            NzbUploadStagingService::class => ['usesRustFfi', 'usesW422RustFfi'],
            NzbXmlElements::class => ['usesW422RustFfi'],
            Par2CandidateRecovery::class => ['usesRustFfi', 'usesW422RustFfi'],
            Par2CandidateRecoveryResult::class => ['usesRustFfi', 'usesW432RustFfi'],
            ReleaseArticleFingerprintStore::class => ['usesRustFfi', 'usesW432RustFfi'],
        ];
        foreach ($earlier as $class => $methods) {
            $before = array_map(static fn (string $method): bool => $class::$method(), $methods);
            foreach ([true, false] as $enabled) {
                $inside = $class::withW60RustFfi($enabled, static fn (): array => array_map(static fn (string $method): bool => $class::$method(), $methods));
                self::assertSame($before, $inside, $class);
            }
            self::assertSame($before, array_map(static fn (string $method): bool => $class::$method(), $methods), $class);
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(6000));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW60RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $items), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes and keyed or non-sequential columns keep the PHP semantics.
        foreach (array_keys(self::ENTRIES) as $class) {
            $this->assertParity($class, ["Show\0.nzb\t1\t1\t<nzb>", "renamed\t1\t1", '<group>a</group>']);
            $this->assertParity($class, ['k' => "x.nzb\t1\t1\t<nzb>", 'j' => '<segment bytes="1" number="1">a@b</segment>']);
            $this->assertParity($class, [1 => "deferred\t1\t2\t3", 0 => str_repeat('a', 40)."\t\t1\t1"]);
            $this->assertParity($class, ["\0"]);
        }
    }

    public function test_exports_guard_null_short_and_malformed_batches(): void
    {
        $this->requireBindings();
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            $words = 1 + self::TAIL[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 1, null, 32]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 2, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 32]), $class);
            self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, 0, $out, 32]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, 1 << 21, $out, 32]), $class);
        }
    }

    public function test_upload_staging_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Show.nzb\t100\t5\t<?xml version=\"1.0\"?>", "a/b.nzb\t1\t1\t<nzb>", "Notes.NFO\t70000\t0\ttext", "old.nzb\t5\t90000\t<nzb ",
            "x.nzb\t5\t1\t\x1f\x8bzz", ".nzb\t1\t1\t<nzb>", "y.nzb\t2\t1\t\xef\xbb\xbf\r\n<!DocType nzb>", "z.NzB\t3\tx\t<nzb>"];
        $this->assertFields(NzbUploadStagingService::class, $rows, 0x3FFF, [
            1 | 3 << 8, 1 | 1 << 2 | 5 << 8 | 1 << 12, 2 | 2 << 4 | 7 << 8 | 1 << 12, 1 | 1 << 6 | 5 << 8 | 2 << 12, 1 | 1 << 8 | 1 << 12,
            1 | 2 << 2 | 5 << 8 | 1 << 12, 1 | 4 << 8 | 1 << 11, 1 | 1 << 7 | 5 << 8 | 1 << 12,
        ], [2, 5, 1, 7, 1, 102, 2]);
        $word = NzbUploadStagingService::planUploadStagingBatchPhp(['Show.nzb'])[0];
        self::assertSame([4, crc32('show')], [$word >> 14 & 0x3FFFF, $word >> 32 & 0xFFFFFFFF]);
        // Accepted rows agree with the existing filename validator and payload prefix helpers.
        foreach (['Show.nzb', 'Show.NZB', 'x.nfo', ' .nzb', 'a.b.nzb', 'nzb', 'a.nzb.txt', '..', 'a\\b.nzb'] as $name) {
            $status = NzbUploadStagingService::planUploadStagingBatchPhp([$name."\t1\t1\t<nzb>"])[0] >> 2 & 3;
            $kind = str_ends_with(strtolower($name), '.nfo') ? 'nfo' : 'nzb';
            try {
                NzbUploadStagingService::validateFilenamePhp($name, $kind);
                $legacy = 0;
            } catch (\Throwable $e) {
                $legacy = str_contains($e->getMessage(), 'Unsafe') ? 1 : 2;
            }
            self::assertSame($legacy, $status, $name);
        }
        $this->assertRandomParity(NzbUploadStagingService::class, 6010);
    }

    public function test_xml_element_summary_parity(): void
    {
        $this->requireBindings();
        $items = ['<segment bytes="700" number="3">abc@def</segment>', "<file poster='p' date='12' subject='s'/>", '<meta type="password">x</meta>',
            '<group>alt.bin</group >', '<segment bytes="7" number="0">a b</segment>', '<x:meta a="1" a="2" type="t">v</x:meta>', '<file poster="p"', 'nope'];
        $this->assertFields(NzbXmlElements::class, $items, -1, [
            5 | 2 << 11 | 3 << 14 | 700 << 34, 1 | 1 << 9 | 3 << 11, 6 | 1 << 11, 3 | 1 << 8, 5 | 1 << 6 | 1 << 10 | 2 << 11, 6 | 1 << 7 | 3 << 11, 1 << 4, 1 << 4,
        ], [2, 1, 0, 1, 0, 2, 2, 0, 0, 0, 3, 700, 3, 1]);
        $this->assertRandomParity(NzbXmlElements::class, 6020);
    }

    public function test_candidate_recovery_plan_parity(): void
    {
        $this->requireBindings();
        $set = '0123456789ABCDEF0123456789abcdef';
        $rows = ["{$set}\t3\tmain, FileDesc,recvslice,odd\t4\t5\tx.vol01+02.par2", "zz\t3\tmain\t0\t0\tx", "{$set}\t3\tmain,filedesc\t0\t0\tx.par2",
            str_repeat('0', 32)."\t3\tmain,filedesc\t0\t0\tx", "{$set}\t0\tmain,filedesc\t0\t0\tx", "{$set}\t2\tmain,,ifsc\t1\t9\tx", "{$set}\t2\tmain,filedesc\t9\t1\tx"];
        $this->assertFields(Par2CandidateRecovery::class, $rows, 0x7FFFFFFFFF, [
            2 | 11 << 3 | 1 << 9 | 1 << 10 | 1 << 11 | 96 << 15 | 3 << 23, 1 << 3 | 3 << 23, 1 | 3 << 3 | 1 << 10 | 120 << 15 | 3 << 23, 3 << 3 | 1 << 8 | 3 << 23,
            3 << 3, 4 | 5 << 3 | 47 << 15 | 2 << 23, 3 | 3 << 3 | 27 << 15 | 2 << 23,
        ], [3, 1, 1, 1, 1, 1, 14, 15, 120]);
        $this->assertRandomParity(Par2CandidateRecovery::class, 6030);
    }

    public function test_recovery_result_summary_parity(): void
    {
        $this->requireBindings();
        $rows = ["renamed\t2\t10\t80\tabc\tShow.S01E01.Title-GRP", "renamed\t1\t1\t90\tSame\t same ", "retryable\tx\t3\t\t\t", "deferred\t1\t1\t50\t\t",
            "renamed\t1\t1\t60\t\t0123456789ABCDEF", "Renamed\t1\t1\t101\t\tplain", "no_match\t\t\t7"];
        $this->assertFields(Par2CandidateRecoveryResult::class, $rows, 0x7FFFF, [
            1 | 3 << 4 | 3 << 7 | 80 << 12, 1 | 1 << 4 | 3 << 7 | 1 << 9 | 90 << 12, 3 | 1 << 3 | 2 << 9, 4 | 2 << 7 | 3 << 9 | 50 << 12,
            1 | 2 << 4 | 2 << 7 | 60 << 12, 4 << 4 | 4 << 9, 2 | 1 << 3 | 1 << 7 | 4 << 9 | 7 << 12,
        ], [2, 1, 1, 1, 2, 6, 17, 287, 3]);
        $this->assertRandomParity(Par2CandidateRecoveryResult::class, 6040);
    }

    public function test_fingerprint_store_plan_parity(): void
    {
        $this->requireBindings();
        $fp = '0123456789abcdef0123456789abcdef01234567';
        $up = strtoupper($fp);
        $rows = ["{$fp}\t\t1\t0", "{$up}\t{$fp}\t2\t40", "{$fp}\t{$fp}\t9\t1", "\t\t0\t0", "nothex\tx\t1\tz", str_repeat('b', 40)."\t".str_repeat('c', 40)."\t1\t1",
            str_repeat('d', 40)."\t".str_repeat('d', 40)."\t1\t2"];
        $this->assertFields(ReleaseArticleFingerprintStore::class, $rows, 0xFFF, [
            1 | 1 << 9, 2 | 2 << 2 | 1 << 4 | 1 << 6 | 1 << 8 | 3 << 9, 1 | 1 << 2 | 1 << 4 | 1 << 5 | 1 << 8 | 5 << 9, 0, 3 | 3 << 2 | 1 << 7,
            1 | 3 << 2 | 4 << 9, 1 | 1 << 2 | 2 << 9,
        ], [2, 1, 1, 1, 1, 1, 3, 2, 1]);
        // Canonical keys are exactly the Wave 43.2 canonical fingerprints.
        foreach ([$fp, $up, '', 'abc', str_repeat('g', 40), $fp.'0'] as $value) {
            $key = ReleaseArticleFingerprintStore::planFingerprintStoreBatchPhp([$value])[0] & 3;
            self::assertSame(preg_match(ReleaseArticleFingerprintStore::W432_CANONICAL_FINGERPRINT, $value) === 1, $key === 1, $value);
        }
        $this->assertRandomParity(ReleaseArticleFingerprintStore::class, 6050);
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
        // NUL items cannot be framed (the export refuses them and the entry point falls back).
        if ($items !== [] && ! str_contains(implode('', $items), "\0")) {
            self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
        }
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<string>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge and binary' => [$class, [$long, $binary, '', ' ', "\t", "\t\t\t\t\t\t\t", "\x0b", str_repeat("\t", 5000).'1', $long."\t".$long]];
        }
        yield 'upload bounds' => [NzbUploadStagingService::class, [
            "\t\t\t", ".\t1\t1\t<nzb>", "..\t1\t1\t<nzb>", "a.nzb\t0\t0\t<nzb>", "a.nzb\t67108864\t86400\t<nzb>", "a.nzb\t67108865\t86401\t<nzb>",
            "a.nfo\t65535\t1\tx", "a.nfo\t65536\t1\tx", "a.nzb\t999999999999999999\t1\t<nzb>", "a.nzb\t9999999999999999999\t1\t<nzb>", "a.nzb\t-1\t1\t<nzb>",
            "a.nzb\t+1\t1\t<nzb>", "a.nzb\t 1\t1\t<nzb>", "a.nzb\t1\t\t<nzb>", "a.nzb\t1\t1\t<nzb", "a.nzb\t1\t1\t<nzbx>", "a.nzb\t1\t1\t<nzb/>",
            "a.nzb\t1\t1\t<NZB>", "a.nzb\t1\t1\t<?XML", "a.nzb\t1\t1\t<!doctyp", "a.nzb\t1\t1\t<!DOCTYPE", "a.nzb\t1\t1\t\xef\xbb\xbf", "a.nzb\t1\t1\t\xef\xbb",
            "a.nzb\t1\t1\t\x1f", "a.nzb\t1\t1\t\x1f\x8b", "a.nzb\t1\t1\t \t\r\n", "a.nzb\t1\t1\t\x0b<nzb>", "a.nzb\t1\t1\t<nzb>\tmore\ttabs",
            "a.nfo\t1\t1\t\xef\xbb\xbf", "a.nfo\t1\t1\t<", "a.nfo\t1\t1\t\x1f\x8b", "a.\t1\t1\t<nzb>", "a\t1\t1\t<nzb>", " \x0b.nzb\t1\t1\t<nzb>",
            "\xA0.nzb\t1\t1\t<nzb>", "A.NFO\t1\t1\tx", "A.NfO\t1\t1\tx", "a.nzb.\t1\t1\t<nzb>", "a/b\t1\t1\t<nzb>", "a\\b.nzb\t1\t1\t<nzb>", "\xC9.NZB\t1\t1\t<nzb>",
        ]];
        yield 'xml bounds' => [NzbXmlElements::class, [
            '<', '<>', '< a>', '<a', '<a>', '<a/>', '<a/', '<a />', '<a / >', '<a b>', '<a b=>', '<a b="1>', "<a b='1\">", '<a b="1"c="2">', '<a b = "1" >x</a>',
            "<a\tb=\"1\"\r\nc='2'>x</a>", '<:>', '<x:>', '<x:file poster="p" date="1" subject="s"></x:file>', '<a:b:segment bytes="1" number="1">m@x</a:b:segment>',
            '<segment bytes="1" number="1">m@x</segment>', '<segment bytes="1" number="1">m@x</segment >', '<segment bytes="1" number="1">m@x</ segment>',
            '<segment bytes="1" number="1">m@x</SEGMENT>', '<segment bytes="1" number="1">m@x</segment>x', '<segment bytes="1" number="1"> m@x </segment>',
            '<segment bytes="1" number="1">m x</segment>', "<segment bytes=\"1\" number=\"1\">m\x7f</segment>", '<segment bytes="1" number="1"></segment>',
            '<segment bytes="1" number="1"/>', '<segment bytes="" number="1">a</segment>', '<segment bytes="1" number="">a</segment>',
            '<segment bytes="0" number="0">a</segment>', '<segment bytes="999999999999999999" number="999999999999999999">a</segment>',
            '<segment bytes="9999999999999999999" number="1">a</segment>', '<segment bytes="1" number="1048576">a</segment>',
            '<segment bytes="536870912" number="1">a</segment>', '<segment bytes="1" bytes="2" number="1">a</segment>', '<segment number="1">a</segment>',
            '<file poster="" date="" subject="">', '<file poster="" date="1" subject=""/>', '<file poster="" date="1" subject=""/> ', '<file poster="" date="1" subject=""/>x',
            '<meta type="password"/>', '<meta type="Password">x</meta>', '<meta type="password" type="x">x</meta>', '<meta>x</meta>', '<group> a.b </group>',
            '<group>a b</group>', '<group></group>', '<group/>', '<groups>', '<groups></groups>', '<segments>', '<head>', '<nzb xmlns="x">', '<NZB>',
            '<a b="</a>">x</a>', '<a b="1"></a></a>', '<a></a', '<a>x</b>', '<a b="1" c="2" d="3" e="4" f="5" g="6" h="7" i="8">', "<a b=\"\xff\">\xfe</a>",
            '<1:2>', '<-.->', '<a b= \'x\' >', '<a =\"1\">', '<a b="1"/ >', '<a b="1"//>',
        ]];
        yield 'recovery bounds' => [Par2CandidateRecovery::class, [
            str_repeat('a', 32)."\t1\tmain,filedesc\t0\t0\tx", str_repeat('A', 32)."\t32768\tmain,filedesc\t0\t0\tx", str_repeat('a', 32)."\t32769\tmain,filedesc\t0\t0\tx",
            str_repeat('a', 31)."\t1\tmain,filedesc\t0\t0\tx", str_repeat('a', 33)."\t1\tmain,filedesc\t0\t0\tx", str_repeat('g', 32)."\t1\tmain,filedesc\t0\t0\tx",
            str_repeat('0', 32)."\t1\tmain,filedesc\t0\t0\tx", str_repeat('a', 32)."\t0000001\tmain,filedesc\t0\t0\tx", str_repeat('a', 32)."\t000001\tmain,filedesc\t0\t0\tx",
            str_repeat('a', 32)."\t1\tMAIN , FILEDESC ,\t\t0\tx", str_repeat('a', 32)."\t1\tmain,filedesc\t999999999\t999999999\tx",
            str_repeat('a', 32)."\t1\tmain,filedesc\t1000000000\t0\tx", str_repeat('a', 32)."\t1\tmain,filedesc,recvslice\t5\t5\tA.PAR2",
            str_repeat('a', 32)."\t1\tmain,filedesc,recvslice\t6\t5\ta.VOL1+2.par2", str_repeat('a', 32)."\t1\tx,y,z,w,v,u,t,s,r,q,p,o,n,m,l,k,j\t1\t1\t.vol",
            str_repeat('a', 32)."\t1\t,,,,\t1\t1\tx", str_repeat('a', 32)."\t1\t\tmain\t1\t1", str_repeat('a', 32)."\t1\tmain\x0b,filedesc\t0\t0\tx",
            str_repeat('a', 32)."\t1\tmain,filedesc\t0\t0\tx\ty\tz", str_repeat('a', 32)."\t1\tmain,main,filedesc,filedesc,ifsc,creator,recvslice\t1\t2\t.par2",
            str_repeat('a', 32), "\t\t\t\t\t", 'x.par2', str_repeat('a', 32)."\t1\tmain\xA0,filedesc\t0\t0\tx",
        ]];
        yield 'recovery result bounds' => [Par2CandidateRecoveryResult::class, [
            'renamed', "renamed\t0\t0\t0\t\t", "renamed\t0\t0\t39\ta\tb.c.d", "renamed\t0\t0\t40\ta\tb.c.d", "renamed\t0\t0\t74\ta\tb.c.d", "renamed\t0\t0\t75\ta\tb.c.d",
            "renamed\t0\t0\t100\ta\tb.c", "renamed\t0\t0\t0100\ta\tb.c.d", "renamed\t0\t0\t-1\ta\tb.c.d", "renamed\t0\t0\t 50\ta\tb.c.d",
            "renamed\t999999999\t999999999\t50\ta\tabcdef0123456789", "renamed\t1000000000\t1\t50\ta\tabcdef0123456789", "renamed\t1\t1\t50\ta\tabcdef012345678",
            "renamed\t1\t1\t50\ta\tabcdef0123456789g", "renamed\t1\t1\t50\t ABC \tabc", "renamed\t1\t1\t50\tabc\tABC\t", "renamed\t1\t1\t50\tabc\t\x0babc",
            "RENAMED\t1\t1\t50\ta\tb.c.d", " renamed\t1\t1\t50\ta\tb.c.d", "no_match\t1\t1\t100\ta\tb.c.d", "retryable\t1\t1\t1\ta\tb", "deferred\t1\t1\t1\ta\tb",
            "nomatch\t1\t1\t1\ta\tb", "renamed\t1\t1\t50\t\xC9\t\xE9", "renamed\t1\t1\t50\t\t".str_repeat('a', 9000), "\t\t\t\t\t",
        ]];
        yield 'fingerprint bounds' => [ReleaseArticleFingerprintStore::class, [
            str_repeat('a', 40), str_repeat('a', 39)."\t\t1\t1", str_repeat('a', 41)."\t\t1\t1", str_repeat('A', 40)."\t".str_repeat('a', 40)."\t1\t1",
            str_repeat('a', 40)."\t".str_repeat('A', 40)."\t1\t1", str_repeat('a', 40)."\tx\t1\t1", str_repeat('a', 40)."\t\t8\t30",
            str_repeat('a', 40)."\t\t9\t31", str_repeat('a', 40)."\t".str_repeat('a', 40)."\t999999999\t999999", str_repeat('a', 40)."\t\t1000000000\t1000000",
            str_repeat('a', 40)."\t\t0\t0", str_repeat('a', 40)."\t\t01\t031", str_repeat('a', 40)."\t\t\t", str_repeat('a', 40)."\t\t-1\t+1",
            str_repeat('f', 40)."\t".str_repeat('f', 40)."\t1\t65536", str_repeat('F', 40)."\t".str_repeat('f', 40)."\t1\t40", str_repeat('z', 40)."\t\t1\t1",
            "\t".str_repeat('a', 40)."\t1\t1", ' '.str_repeat('a', 39)."\t\t1\t1", str_repeat('a', 40)."\t\t1\t1\textra\tfields", "\xC9".str_repeat('a', 39)."\t\t1\t1",
        ]];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical rows, Uncommon batches 320-640
     * malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every round's
     * samples; per-round medians are reported too. Every pair must agree; raw pairs are written
     * outside the checkout. A class must default to Rust exactly when all four metrics reach 1.5x.
     * Gated behind NNTMUX_W602_BENCH=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('NNTMUX_W602_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W602_BENCH=1 to run the Wave 60.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(60));
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
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW60RustFfi($native, fn (): array => $this->timed($class, $items));
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
        $artifact = sys_get_temp_dir().'/w602-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'seed' => 60, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW60.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW60RustFfi(), $class.' default must follow the measured 1.5x gate');
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
                $cases = [
                    NzbUploadStagingService::class => ["SH\xD6W.NZB\t1\t1\t<nzb>", "INFO.NFO\t1\t1\tI", "\xC9.NFO\t1\t1\t<!DOCTYPE nzb>", "I.NZB\t1\t1\t\xA0<nzb>"],
                    NzbXmlElements::class => ['<FILE POSTER="I" DATE="1" SUBJECT="I"/>', "<segment bytes=\"1\" number=\"1\">\xC9@I</segment>", '<META TYPE="PASSWORD">I</META>'],
                    Par2CandidateRecovery::class => [str_repeat('I', 32)."\t1\tMAIN,FILEDESC\t0\t0\tI.PAR2", str_repeat('E', 32)."\t1\tMA\xCDN,FILEDESC\t0\t0\tI.VOL1"],
                    Par2CandidateRecoveryResult::class => ["renamed\t1\t1\t80\tTITLE\ttitle", "renamed\t1\t1\t80\tI\t\xDD.I.I", "RENAMED\t1\t1\t1\t\t"],
                    ReleaseArticleFingerprintStore::class => [str_repeat('E', 40)."\t".str_repeat('e', 40)."\t1\t1", "\xC9".str_repeat('e', 39)."\t\t1\t1"],
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
        $value = (new ReflectionClass($class))->getConstant('W60_RUST_FFI_DEFAULT');
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
        $php = $class::withW60RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW60RustFfi(true, fn (): array => $this->call($class, '', $items));
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
        $cap = $count + 16;
        $out = $ffi->new('int64_t['.$cap.']');
        $blob = implode("\0", $items);
        $written = $this->invoke($ffi, self::SYMBOLS[$class], [$blob, \strlen($blob), $count, $out, $cap]);
        self::assertIsInt($written);
        self::assertGreaterThan(0, $written, $class);
        /** @var array<int, int> $values */
        $values = unpack('P*', FFI::string($out, $written * 8));

        return array_values($values);
    }

    /** @param  key-of<self::ENTRIES>  $class */
    private function ffi(string $class): FFI
    {
        $ffi = (new ReflectionMethod($class, 'w60RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w60 binding');

        return $ffi;
    }

    /** Each Wave 60.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[32]');
            self::assertSame(1 + self::TAIL[$class], $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 32]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W60 C declaration.
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

    /**
     * @param  key-of<self::ENTRIES>  $class
     * @return list<string>
     */
    private function fixture(string $class, Randomizer $random, bool $uncommon): array
    {
        $items = [];
        $pool = [];
        for ($i = $uncommon ? $random->getInt(320, 640) : 320; $i > 0; $i--) {
            $items[] = match ($class) {
                NzbUploadStagingService::class => $this->uploadRow($random, $uncommon),
                NzbXmlElements::class => $this->xmlElement($random, $uncommon),
                Par2CandidateRecovery::class => $this->recoveryRow($random, $uncommon),
                Par2CandidateRecoveryResult::class => $this->resultRow($random, $uncommon),
                ReleaseArticleFingerprintStore::class => $this->fingerprintRow($random, $uncommon, $pool),
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
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-/:@<>=\"' \t", $length);
    }

    /** Case-flips, pads or corrupts a field the way hand-edited or truncated data does. */
    private function mangle(Randomizer $random, string $value): string
    {
        return match ($random->getInt(0, 6)) {
            0 => strtoupper($value),
            1 => ucfirst(strtolower($value)),
            2 => $this->pick($random, [' ', "\t", "\r\n", "\x0b", '']).$value.$this->pick($random, [' ', "\r\n", "\x0b", '']),
            3 => substr($value, 0, $random->getInt(0, max(0, \strlen($value) - 1))),
            4 => $value.$this->noise($random, 3),
            5 => str_replace(['a', 'e', 'o'], ["\xE1", "\xC9", '0'], $value),
            default => $value,
        };
    }

    /** A random scene-style release name. */
    private function release(Randomizer $random): string
    {
        if ($random->getInt(0, 2) === 0) {
            return $this->pick($random, self::RELEASES);
        }
        $words = [];
        for ($w = $random->getInt(1, 4); $w > 0; $w--) {
            $words[] = ucfirst($this->pick($random, self::WORDS));
        }
        $words[] = \sprintf('S%02dE%02d', $random->getInt(1, 12), $random->getInt(1, 24));
        $words[] = $this->pick($random, ['720p', '1080p', '2160p']);
        $words[] = $this->pick($random, ['BluRay', 'WEB-DL', 'HDTV']);

        return implode($this->pick($random, ['.', '.', '_', ' ']), $words).'-'.$this->pick($random, ['GRP', 'SPARKS', 'FLUX', 'NTb']);
    }

    private function hex(Randomizer $random, int $length): string
    {
        return $random->getBytesFromString('0123456789abcdef', $length);
    }

    private function uploadRow(Randomizer $random, bool $uncommon): string
    {
        $nfo = $random->getInt(0, 4) === 0;
        $name = $this->release($random).($nfo ? '.nfo' : '.nzb');
        $size = (string) ($nfo ? $random->getInt(1, 60000) : $random->getInt(1000, 50000000));
        $age = (string) $random->getInt(0, 80000);
        $head = $nfo ? $this->pick($random, ['Release info', "\xDB\xB2 NFO", "Title: x\r\n"])
            : $this->pick($random, ['<?xml version="1.0" encoding="UTF-8"?>', '<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN">', '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">']);
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $name = $this->pick($random, ['', '.', '..', '../'.$name, 'dir\\'.$name, ' .nzb', '.nzb', 'noext', $name.'.txt']),
                1 => $name = $this->mangle($random, $name),
                2 => $size = $this->pick($random, ['', '0', '-5', '+5', ' 5', 'x', '67108865', '65536', str_repeat('9', $random->getInt(17, 25))]),
                3 => $age = $this->pick($random, ['', '86401', '999999', 'old', '-1', str_repeat('9', 19)]),
                4 => $head = $this->pick($random, ["\x1f\x8b\x08\x01", "\xef\xbb\xbf", "\xef\xbb\xbf<?xml", " \r\n\t<nzb>", '<NZB>', '<nzbx>', '<html>', '<!doctype nzb>', '']),
                5 => $head = $this->noise($random, 40, true),
                6 => $head .= "\t".$this->noise($random, 20),
                7 => $name = $this->noise($random, 30, true),
                default => $head = $this->mangle($random, $head),
            };
        }

        return implode("\t", $random->getInt(0, 30) === 0 && $uncommon ? [$name, $size] : [$name, $size, $age, $head]);
    }

    private function xmlElement(Randomizer $random, bool $uncommon): string
    {
        $number = $random->getInt(1, 400);
        $element = match ($random->getInt(0, 9)) {
            0 => \sprintf('<file poster="%s@example.com (Poster)" date="%d" subject="%s [%d/%d] - &quot;x.rar&quot; yEnc (1/%d)">', $this->pick($random, self::WORDS),
                $random->getInt(1200000000, 1800000000), $this->release($random), $random->getInt(1, 40), 40, $random->getInt(1, 200)),
            1 => '<group>alt.binaries.'.$this->pick($random, ['teevee', 'moovee', 'misc', 'hdtv.x264']).'</group>',
            2 => \sprintf('<meta type="%s">%s</meta>', $this->pick($random, ['name', 'password', 'category', 'tag']), $this->release($random)),
            3 => $this->pick($random, ['<groups>', '<segments>', '<head>', '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">']),
            default => \sprintf('<segment bytes="%d" number="%d">%s%d.%s@news.example.com</segment>', $random->getInt(100000, 800000), $number,
                $this->hex($random, 16), $number, $this->pick($random, self::WORDS)),
        };
        if (! $uncommon) {
            return $element;
        }

        return match ($random->getInt(0, 12)) {
            0 => '',
            1 => $this->noise($random, 40, true),
            2 => $this->noise($random, 30),
            3 => str_replace('"', $this->pick($random, ["'", '', '`']), $element),
            4 => preg_replace('/ (\w+)=/', ' $1="1" $1=', $element, 1) ?? $element,
            5 => preg_replace('/ \w+="[^"]*"/', '', $element, 1) ?? $element,
            6 => preg_replace('/="\d+"/', '="'.$this->pick($random, ['', '0', '-1', 'x', str_repeat('9', 19), '1048577']).'"', $element, 1) ?? $element,
            7 => preg_replace('/^<(\w+)/', '<'.$this->pick($random, ['nzb:', 'x:', ':', 'a:b:']).'$1', $element) ?? $element,
            8 => substr($element, 0, $random->getInt(0, \strlen($element))),
            9 => str_replace(' ', $this->pick($random, ["\t", "\r\n", '  ', '']), $element),
            10 => $element.$this->pick($random, [' ', 'x', '</segment>', "\n"]),
            11 => str_replace(['segment', 'group', 'meta'], ['Segment', 'GROUP', 'metadata'], $element),
            default => str_replace('@', $this->pick($random, [' @', '@<', "\x7f", '']), $element),
        };
    }

    private function recoveryRow(Randomizer $random, bool $uncommon): string
    {
        $set = $this->hex($random, 32);
        $set = $random->getInt(0, 3) === 0 ? strtoupper($set) : $set;
        $files = (string) $random->getInt(1, 200);
        $packets = [];
        foreach (self::PACKETS as $packet) {
            if ($random->getInt(0, 9) < 8) {
                $packets[] = $packet;
            }
        }
        $packets = implode($this->pick($random, [',', ', ']), $packets);
        $needed = (string) $random->getInt(0, 50);
        $available = (string) $random->getInt(0, 80);
        $name = $this->release($random).($random->getInt(0, 1) === 0 ? '.par2' : \sprintf('.vol%02d+%02d.par2', $random->getInt(0, 60), $random->getInt(1, 64)));
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $set = $this->pick($random, ['', str_repeat('0', 32), substr($set, 1), $set.'0', 'g'.substr($set, 1), $this->noise($random, 32)]),
                1 => $files = $this->pick($random, ['', '0', '32769', '0000001', '-1', 'x', str_repeat('9', 7)]),
                2 => $packets = $this->pick($random, ['', ',,', 'MAIN,FILEDESC', ' main , filedesc ,recvslice', 'main,filedesc,unknown,x,y', 'creator', "main\x0b,filedesc"]),
                3 => $needed = $this->pick($random, ['', 'x', '-1', '1000000000', '999999999']),
                4 => $available = $this->pick($random, ['', '1e3', '+1', '0']),
                5 => $name = $this->mangle($random, $name),
                6 => $name = $this->noise($random, 40, true),
                7 => $packets = str_repeat('odd,', $random->getInt(1, 40)).'main,filedesc',
                default => $set = $this->mangle($random, $set),
            };
        }

        return implode("\t", $uncommon && $random->getInt(0, 20) === 0 ? [$set, $files] : [$set, $files, $packets, $needed, $available, $name]);
    }

    private function resultRow(Randomizer $random, bool $uncommon): string
    {
        $outcome = $this->pick($random, self::OUTCOMES);
        $probes = (string) $random->getInt(0, 40);
        $articles = (string) $random->getInt(0, 4000);
        $confidence = (string) $random->getInt(0, 100);
        $original = $random->getInt(0, 1) === 0 ? $this->hex($random, 32) : $this->release($random);
        $renamed = match ($random->getInt(0, 4)) {
            0 => '',
            1 => $original,
            2 => $this->hex($random, $random->getInt(16, 40)),
            default => $this->release($random),
        };
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $outcome = $this->pick($random, ['', 'Renamed', 'NO_MATCH', 'nomatch', ' renamed', 'retry', 'unknown']),
                1 => $probes = $this->pick($random, ['', 'x', '-1', '1000000000', ' 1']),
                2 => $articles = $this->pick($random, ['', '1.5', '+3', str_repeat('9', 10)]),
                3 => $confidence = $this->pick($random, ['', '101', '0100', '-5', '99.5', 'high', '1000']),
                4 => $renamed = $this->mangle($random, $renamed),
                5 => $renamed = $this->noise($random, 40, true),
                6 => $renamed = $this->pick($random, [' ', "\t", "\r\n"]).strtoupper($original).$this->pick($random, [' ', "\t", '']),
                7 => $original = $this->mangle($random, $original),
                default => $renamed .= "\t".$this->noise($random, 10),
            };
        }

        return implode("\t", $uncommon && $random->getInt(0, 20) === 0 ? [$outcome, $probes] : [$outcome, $probes, $articles, $confidence, $original, $renamed]);
    }

    /** @param  list<string>  $pool */
    private function fingerprintRow(Randomizer $random, bool $uncommon, array &$pool): string
    {
        $fingerprint = $pool !== [] && $random->getInt(0, 9) === 0 ? $this->pick($random, $pool) : $this->hex($random, 40);
        $pool[] = $fingerprint;
        $stored = match ($random->getInt(0, 3)) {
            0 => '',
            1 => $this->hex($random, 40),
            default => $fingerprint,
        };
        $count = (string) $random->getInt(1, 3);
        $age = (string) $random->getInt(0, 60);
        if ($uncommon) {
            match ($random->getInt(0, 9)) {
                0 => $fingerprint = strtoupper($fingerprint),
                1 => $fingerprint = $this->pick($random, ['', substr($fingerprint, 1), $fingerprint.'0', 'g'.substr($fingerprint, 1), ' '.substr($fingerprint, 1)]),
                2 => $stored = strtoupper($fingerprint),
                3 => $count = $this->pick($random, ['', '9', '100', '0', 'x', '1000000000', '-1']),
                4 => $age = $this->pick($random, ['', '31', '999999', '1000000', 'old']),
                5 => $fingerprint = $this->noise($random, 50, true),
                6 => $stored = $this->mangle($random, $stored),
                7 => $fingerprint = $this->mangle($random, $fingerprint),
                default => $age .= "\t".$this->noise($random, 8),
            };
        }

        return implode("\t", $uncommon && $random->getInt(0, 20) === 0 ? [$fingerprint] : [$fingerprint, $stored, $count, $age]);
    }
}
