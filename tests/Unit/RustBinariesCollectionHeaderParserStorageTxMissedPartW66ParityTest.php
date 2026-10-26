<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderParser;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\Binaries\MissedPartHandler;
use FFI;
use FFI\CData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use RuntimeException;

final class RustBinariesCollectionHeaderParserStorageTxMissedPartW66ParityTest extends TestCase
{
    /** Class => batch entry point; each also has `*Php()` and `*Native()` variants. */
    private const ENTRIES = [
        CollectionHandler::class => 'w66CollectionHandlerPlanBatch',
        HeaderParser::class => 'w66HeaderParserPlanBatch',
        HeaderStorageService::class => 'w66HeaderStoragePlanBatch',
        HeaderStorageTransaction::class => 'w66HeaderStorageTxPlanBatch',
        MissedPartHandler::class => 'w66MissedPartHandlerPlanBatch',
    ];

    /** Class => Wave 66.2 export. */
    private const SYMBOLS = [
        CollectionHandler::class => 'nntmux_w662_collection_handler_plan_batch',
        HeaderParser::class => 'nntmux_w662_header_parser_plan_batch',
        HeaderStorageService::class => 'nntmux_w662_header_storage_plan_batch',
        HeaderStorageTransaction::class => 'nntmux_w662_header_storage_tx_plan_batch',
        MissedPartHandler::class => 'nntmux_w662_missed_part_handler_plan_batch',
    ];

    /** Class => class-distinct C declaration constant. */
    private const CDEFS = [
        CollectionHandler::class => 'W66_COLLECTION_HANDLER_FFI_CDEF',
        HeaderParser::class => 'W66_HEADER_PARSER_FFI_CDEF',
        HeaderStorageService::class => 'W66_HEADER_STORAGE_FFI_CDEF',
        HeaderStorageTransaction::class => 'W66_HEADER_STORAGE_TX_FFI_CDEF',
        MissedPartHandler::class => 'W66_MISSED_PART_HANDLER_FFI_CDEF',
    ];

    /** Class => summary words after the per-row words (the digest included). */
    private const TAIL = [
        CollectionHandler::class => 24,
        HeaderParser::class => 22,
        HeaderStorageService::class => 15,
        HeaderStorageTransaction::class => 24,
        MissedPartHandler::class => 15,
    ];

    /** Class => public tail-size constant. */
    private const TAIL_CONSTANTS = [
        CollectionHandler::class => 'W66_COLLECTION_HANDLER_TAIL',
        HeaderParser::class => 'W66_HEADER_PARSER_TAIL',
        HeaderStorageService::class => 'W66_HEADER_STORAGE_TAIL',
        HeaderStorageTransaction::class => 'W66_HEADER_STORAGE_TX_TAIL',
        MissedPartHandler::class => 'W66_MISSED_PART_HANDLER_TAIL',
    ];

    /** Class => static properties declared before Wave 66 (the wave adds none). */
    private const STATIC_PROPERTIES = [
        CollectionHandler::class => ['useRustFfi', 'ffi', 'w401UseRustFfi', 'w401Ffi'],
        HeaderParser::class => ['w411UseRustFfi', 'w411Ffi'],
        HeaderStorageService::class => ['ffi', 'useRustFfi', 'w411UseRustFfi', 'w411Ffi'],
        HeaderStorageTransaction::class => ['useRustFfi', 'ffi', 'w421UseRustFfi', 'w421Ffi'],
        MissedPartHandler::class => ['useRustFfi', 'ffi', 'w421UseRustFfi', 'w421Ffi'],
    ];

    /** Class => earlier-wave routing probes. */
    private const EARLIER_TOGGLES = [
        CollectionHandler::class => ['usesRustFfi', 'usesW401RustFfi', 'usesW52RustFfi'],
        HeaderParser::class => ['usesW411RustFfi', 'usesW52RustFfi'],
        HeaderStorageService::class => ['usesRustFfi', 'usesW411RustFfi', 'usesW52RustFfi'],
        HeaderStorageTransaction::class => ['usesRustFfi', 'usesW421RustFfi', 'usesW54RustFfi'],
        MissedPartHandler::class => ['usesRustFfi', 'usesW421RustFfi', 'usesW52RustFfi'],
    ];

    /**
     * Common mean/p99 then Uncommon mean/p99 speedups (PHP ns / Rust ns), each the minimum of
     * three runs of test_paired_randomized_common_and_uncommon_benchmarks (seed 66, 40
     * interleaved rounds of 10 pairs per class and workload after 2 warmups, 400 pooled samples
     * per metric, each side the fastest of 3 back-to-back calls, batches of 320 Common or
     * 320-640 Uncommon rows, release Rust, PHP 8.5.12 CLI, Apple Silicon macOS on a shared host
     * at load average 7-8). Timings include dispatch, implode, the FFI call and unpack('P*'). A
     * class defaults to Rust only when all four reach 1.5x.
     */
    private const QUALIFICATION = [
        CollectionHandler::class => [8.795, 7.414, 9.697, 7.521],
        HeaderParser::class => [8.551, 8.100, 8.918, 8.092],
        HeaderStorageService::class => [5.550, 5.247, 5.907, 4.966],
        HeaderStorageTransaction::class => [3.637, 3.438, 3.264, 2.742],
        MissedPartHandler::class => [11.993, 10.637, 11.291, 9.114],
    ];

    /** Interleaved benchmark rounds; each times PAIRS pairs per class and workload. */
    private const ROUNDS = 40;

    private const PAIRS = 10;

    private const WARMUPS = 2;

    /** Back-to-back calls per side of a pair; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    /** Batches per class and workload in the randomized parity sweep. */
    private const PARITY_BATCHES = 6;

    private const WORDS = ['the', 'great', 'night', 'big', 'movie', 'show', 'pro', 'saga', 'old', 'new', 'dark', 'star', 'wild', 'hunt', 'city', 'last'];

    private const POSTERS = ['poster@example.com (Poster)', 'Anonymous <anon@news.example>', 'yEncBin@Poster.com (yEncBin)', 'abc@def.ghi', 'Bob <bob@x.y>', ''];

    private const ZONES = ['+0000', '-0500', '+0130', 'GMT', 'UTC', 'Z', '-1200', '+1400'];

    private const SQL_ERRORS = ['', '', '', '', 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
        'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded', 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',
        'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', "Got a packet bigger than 'max_allowed_packet' bytes",
        'SQLSTATE[23000]: 1452 Cannot add or update a child row: a foreign key constraint fails', 'Unknown column', 'Lost connection to MySQL server during query'];

    protected function tearDown(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $class::resetW66RustFfi();
        }
        parent::tearDown();
    }

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::ENTRIES as $class => $entry) {
            $reflection = new ReflectionClass($class);
            foreach (['enableW66RustFfi', 'disableW66RustFfi', 'resetW66RustFfi', 'usesW66RustFfi', 'withW66RustFfi', $entry, $entry.'Php', $entry.'Native'] as $method) {
                self::assertTrue($reflection->hasMethod($method), $class.'::'.$method);
                self::assertTrue($reflection->getMethod($method)->isPublic() && $reflection->getMethod($method)->isStatic(), $class.'::'.$method);
                self::assertSame($class, $reflection->getMethod($method)->getDeclaringClass()->getName(), $class.'::'.$method);
            }
            $state = $reflection->getMethod('w66RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(2, $state->getNumberOfParameters(), $class);
            self::assertSame(['enabled', 'ffi', 'out', 'capacity'], array_keys($state->getStaticVariables()), $class.' keeps Wave 66 state in function-local statics');
            $default = $reflection->getReflectionConstant('W66_RUST_FFI_DEFAULT');
            self::assertInstanceOf(ReflectionClassConstant::class, $default, $class);
            self::assertIsBool($default->getValue(), $class);
            self::assertTrue($default->isPublic(), $class);
            self::assertSame($class, $reflection->getReflectionConstant(self::CDEFS[$class])?->getDeclaringClass()->getName(), $class);
            self::assertStringContainsString(self::SYMBOLS[$class], (string) $reflection->getConstant(self::CDEFS[$class]), $class);
            self::assertSame(self::TAIL[$class], $reflection->getConstant(self::TAIL_CONSTANTS[$class]), $class);
            self::assertSame(self::STATIC_PROPERTIES[$class], array_keys($reflection->getStaticProperties()), $class.' adds no static property');
            foreach ($reflection->getProperties() as $property) {
                self::assertStringNotContainsString('w66', strtolower($property->getName()), $class.' adds no Wave 66 property');
            }
            self::assertTrue($reflection->isFinal(), $class);
        }
        self::assertCount(5, array_unique(self::CDEFS));
    }

    public function test_existing_public_api_is_preserved(): void
    {
        $expected = [
            CollectionHandler::class => ['setUseRustFfi', 'usesRustFfi', '__construct', 'reset', 'getOrCreateCollection', 'getOrCreateCollections', 'refreshAggregates',
                'getInsertedIds', 'getLastException', 'getAllIds', 'getAllIdsPhp', 'getAllIdsNative', 'getBatchHashes', 'getBatchHashesPhp', 'getBatchHashesNative',
                'summarizeCollectionUpsertBatch', 'useW401RustFfi', 'usesW401RustFfi', 'resetW401RustFfi', 'usesW52RustFfi', 'withW52RustFfi', 'planCollectionKeys',
                'planCollectionKeysPhp', 'planCollectionKeysNative'],
            HeaderParser::class => ['__construct', 'reset', 'markPerArticleObfuscated', 'perArticleObfuscatedNumbers', 'parseSubject', 'parse', 'flushBlacklistUpdates',
                'getNotYEncCount', 'getBlacklistedCount', 'getArticleRange', 'summarizeHeaderBatch', 'summarizeHeaderBatchPhp', 'summarizeHeaderBatchNative',
                'useW411RustFfi', 'usesW411RustFfi', 'resetW411RustFfi', 'usesW52RustFfi', 'withW52RustFfi', 'planSubjects', 'planSubjectsPhp', 'planSubjectsNative'],
            HeaderStorageService::class => ['setUseRustFfi', 'usesRustFfi', '__construct', 'store', 'extractFileNumberAndTotal', 'extractFileNumberAndTotalPhp',
                'extractFileNumberAndTotalNative', 'extractFileNumbersAndTotalsBatch', 'extractFileNumbersAndTotalsBatchPhp', 'extractFileNumbersAndTotalsBatchNative',
                'getFileCount', 'getFileCountPhp', 'getFileCountNative', 'summarizeStorageChunkBatch', 'summarizeStorageChunkBatchPhp', 'summarizeStorageChunkBatchNative',
                'useW411RustFfi', 'usesW411RustFfi', 'resetW411RustFfi', 'usesW52RustFfi', 'withW52RustFfi', 'headerValidationCodes', 'headerValidationCodesPhp',
                'headerValidationCodesNative'],
            HeaderStorageTransaction::class => ['__construct', 'setUseRustFfi', 'usesRustFfi', 'encodeBatchNoise', 'encodeBatchNoisePhp', 'encodeBatchNoiseNative',
                'cleanupStrategy', 'cleanupStrategyPhp', 'cleanupStrategyNative', 'getBatchNoise', 'begin', 'markError', 'hasErrors', 'finish', 'getLastException',
                'summarizeCleanupPlanBatch', 'summarizeCleanupPlanBatchPhp', 'summarizeCleanupPlanBatchNative', 'useW421RustFfi', 'usesW421RustFfi', 'resetW421RustFfi',
                'w421Chunks', 'w421SaturatingAdd', 'w421Invoke', 'usesW54RustFfi', 'withW54RustFfi', 'summarizeCleanupHashes', 'summarizeCleanupHashesPhp',
                'summarizeCleanupHashesNative'],
            MissedPartHandler::class => ['__construct', 'setUseRustFfi', 'usesRustFfi', 'expandRange', 'expandRangePhp', 'expandRangeNative', 'rangeChunks', 'rangeChunksPhp',
                'rangeChunksNative', 'addMissingParts', 'addMissingRange', 'deferRange', 'removeRepairedParts', 'getMissingParts', 'incrementAttempts',
                'incrementRangeAttempts', 'getCount', 'cleanupExhaustedParts', 'summarizeMissedPartBatch', 'summarizeMissedPartBatchPhp', 'summarizeMissedPartBatchNative',
                'useW421RustFfi', 'usesW421RustFfi', 'resetW421RustFfi', 'usesW52RustFfi', 'withW52RustFfi', 'planRepairBatch', 'planRepairBatchPhp', 'planRepairBatchNative'],
        ];
        foreach ($expected as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $method) {
                self::assertTrue($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic(), $class.'::'.$method);
            }
        }
        self::assertSame(['inserted', 'all', 'hashes', 'noise'], HeaderStorageTransaction::CLEANUP_STRATEGIES);
        // The earlier-wave helpers answer the same whichever way the Wave 66 toggle points.
        $earlier = static fn (): array => [
            CollectionHandler::planCollectionKeysPhp(['Movie.Name', 'other'], [10, 2]),
            HeaderParser::planSubjectsPhp(['"a.rar" yEnc (1/2)', 'x']),
            HeaderStorageService::headerValidationCodesPhp([1, 0], ['<a@b>', '']),
            HeaderStorageTransaction::cleanupStrategyPhp(1, 2, 3), HeaderStorageTransaction::encodeBatchNoisePhp('ab'),
            MissedPartHandler::expandRangePhp(1, 3), MissedPartHandler::rangeChunksPhp(1, 10, 3),
        ];
        $baseline = $earlier();
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([true, false] as $enabled) {
                self::assertEquals($baseline, $class::withW66RustFfi($enabled, $earlier), $class);
            }
        }
    }

    public function test_defaults_match_recorded_qualification(): void
    {
        foreach (self::QUALIFICATION as $class => $speedups) {
            self::assertCount(4, $speedups, $class);
            self::assertSame(min($speedups) >= 1.5, $this->default($class), $class);
            self::assertSame($this->default($class), $class::usesW66RustFfi(), $class);
        }
    }

    public function test_enable_disable_reset_and_with_toggle_scope_routing(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $default = $this->default($class);
            $class::enableW66RustFfi();
            self::assertTrue($class::usesW66RustFfi(), $class);
            $class::disableW66RustFfi();
            self::assertFalse($class::usesW66RustFfi(), $class);
            self::assertTrue($class::withW66RustFfi(true, static fn (): bool => $class::usesW66RustFfi()), $class);
            self::assertFalse($class::usesW66RustFfi(), $class.' with restores an explicit override');
            $class::resetW66RustFfi();
            self::assertSame($default, $class::usesW66RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW66RustFfi($enabled, static function () use ($class, $enabled): array {
                    $inner = $class::withW66RustFfi(! $enabled, static fn (): bool => $class::usesW66RustFfi());

                    return [$class::usesW66RustFfi(), $inner];
                });
                self::assertSame([$enabled, ! $enabled], $seen, $class);
                self::assertSame($default, $class::usesW66RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW66RustFfi(! $default, static fn () => throw new RuntimeException('boom'));
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown, 'The callback exception must propagate.');
            self::assertSame($default, $class::usesW66RustFfi(), $class);
        }
    }

    public function test_toggles_stay_independent_of_each_other_and_earlier_switches(): void
    {
        foreach (array_keys(self::ENTRIES) as $class) {
            $seen = $class::withW66RustFfi(! $this->default($class), static fn (): array => array_map(
                static fn (string $other): bool => $other::usesW66RustFfi(),
                array_keys(self::ENTRIES),
            ));
            foreach (array_keys(self::ENTRIES) as $i => $other) {
                self::assertSame($other === $class ? ! $this->default($other) : $this->default($other), $seen[$i], $class.' vs '.$other);
            }
        }
        foreach (self::EARLIER_TOGGLES as $class => $methods) {
            $before = array_map(static fn (string $method): bool => $class::$method(), $methods);
            foreach ([true, false] as $enabled) {
                $inside = $class::withW66RustFfi($enabled, static fn (): array => array_map(static fn (string $method): bool => $class::$method(), $methods));
                self::assertSame($before, $inside, $class);
            }
            $class::resetW66RustFfi();
            self::assertSame($before, array_map(static fn (string $method): bool => $class::$method(), $methods), $class.' reset leaves earlier toggles alone');
        }
        try {
            foreach ([true, false] as $enabled) {
                CollectionHandler::useW401RustFfi($enabled);
                HeaderParser::useW411RustFfi($enabled);
                HeaderStorageService::useW411RustFfi($enabled);
                HeaderStorageTransaction::useW421RustFfi($enabled);
                MissedPartHandler::useW421RustFfi($enabled);
                foreach (array_keys(self::ENTRIES) as $class) {
                    self::assertSame($this->default($class), $class::usesW66RustFfi(), $class);
                }
                foreach ([CollectionHandler::class, HeaderParser::class, HeaderStorageService::class, MissedPartHandler::class] as $class) {
                    self::assertSame($this->default($class), $class::withW52RustFfi($enabled, static fn (): bool => $class::usesW66RustFfi()), $class);
                }
                self::assertSame($this->default(HeaderStorageTransaction::class), HeaderStorageTransaction::withW54RustFfi($enabled,
                    static fn (): bool => HeaderStorageTransaction::usesW66RustFfi()));
            }
        } finally {
            CollectionHandler::resetW401RustFfi();
            HeaderParser::resetW411RustFfi();
            HeaderStorageService::resetW411RustFfi();
            HeaderStorageTransaction::resetW421RustFfi();
            MissedPartHandler::resetW421RustFfi();
        }
    }

    public function test_entry_points_route_through_toggle(): void
    {
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(6600));
        foreach (array_keys(self::ENTRIES) as $class) {
            foreach ([false, true] as $uncommon) {
                $items = $this->fixture($class, $random, $uncommon);
                $php = $this->call($class, 'Php', $items);
                foreach ([true, false] as $native) {
                    self::assertSame($php, $class::withW66RustFfi($native, fn (): array => $this->call($class, '', $items)), $class);
                }
                self::assertSame($php, $this->call($class, 'Native', $items), $class);
            }
        }
    }

    public function test_native_helpers_fall_back_to_php_for_unsupported_input(): void
    {
        $this->requireBindings();
        // NUL bytes and keyed or non-sequential batches keep the PHP semantics.
        foreach (array_keys(self::ENTRIES) as $class) {
            $this->assertParity($class, ["a\0b\tp\t1\t2\t2\t0", "1\ts\0 (1/2)\tf\t\t<a@\0b>\t\t1\t1\t", "c\tb\0h\t1\t<m>\t1", "1\tdead\0lock\t0\t1", "5\t0\t\0yes"]);
            $this->assertParity($class, ['k' => "name\tp\t1\t2\t2\t0", 'j' => "10\t1\t"]);
            $this->assertParity($class, [1 => "1\tdeadlock\t0\t1", 0 => "c\tb\t1\t<m>\t1"]);
            $this->assertParity($class, ["\0"]);
        }
    }

    public function test_exports_guard_null_short_overlapping_and_malformed_batches(): void
    {
        $this->requireBindings();
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            $words = 1 + self::TAIL[$class];
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 1, null, 64]), $class);
            self::assertSame(-$words, $this->invoke($ffi, $symbol, ['a', 1, 1, $out, $words - 1]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ["a\0b", 3, 1, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['a', 1, 2, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, [null, 1, 1, $out, 64]), $class);
            self::assertSame(self::TAIL[$class], $this->invoke($ffi, $symbol, [null, 0, 0, $out, 64]), $class);
            self::assertSame(-1, $this->invoke($ffi, $symbol, ['', 0, 1 << 21, $out, 64]), $class);
            // An output aliasing the input is refused; an unaligned output is written byte-wise.
            $shared = $ffi->new('char[1024]');
            FFI::memcpy($shared, 'a', 1);
            $base = $ffi->cast('char *', FFI::addr($shared));
            self::assertSame(-1, $this->invoke($ffi, $symbol, [$base, 1, 1, $ffi->cast('int64_t *', $base), 64]), $class);
            self::assertSame($words, $this->invoke($ffi, $symbol, [$base, 1, 1, $ffi->cast('int64_t *', $this->offset($ffi, $shared, 512)), 64]), $class);
            $raw = $ffi->new('char['.(8 * $words + 8).']');
            $unaligned = $this->offset($ffi, $raw, 1);
            self::assertSame($words, $this->invoke($ffi, $symbol, ['a', 1, 1, $ffi->cast('int64_t *', $unaligned), $words]), $class);
            self::assertSame($this->call($class, 'Php', ['a']), array_values((array) unpack('P*', FFI::string($unaligned, 8 * $words))), $class);
        }
    }

    public function test_collection_handler_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["Movie Name\tposter@x\t5\t10\t10\t0", "movie name\tPoster@X\t5\t10\t3\t\t", "abcdef0123456789abcdef\t\t1\t0\t0\t", "xzqrtplkmnbv\tp\t1\t5\t6\t15",
            "bad\tp\t0\t1\t1\t1", "x\tp\t1\t1\t1\t7", "y\tp\t2\t3\t1\t4"];
        $this->assertFields(CollectionHandler::class, $rows, (1 << 39) - 1, [1 | 1 << 2 | 1 << 7 | 100 << 12, 2 | 30 << 12, 1 | 16 << 2 | 1 << 7 | 1 << 9 | 1 << 10 | 2 << 19,
            1 | 1 << 2 | 1 << 7 | 1 << 8 | 1 << 11 | 100 << 12 | 3 << 19, 0, 0, 3 | 4 << 2 | 33 << 12],
            [2, 3, 1, 1, 1, 2, 0, 0, 1, 0, 0, 1, 1, 1, 1, 1, 3, 2, 3, 2, 3, 20, 15]);
        $words = CollectionHandler::w66CollectionHandlerPlanBatchPhp($rows);
        self::assertSame(crc32('movie name:10') & 0x7FFFFF, $words[0] >> 39 & 0x7FFFFF);
        self::assertSame($words[0] >> 39, $words[1] >> 39);
        self::assertSame(1, $words[2] >> 62 & 1);
        $this->assertRandomParity(CollectionHandler::class, 6610);
    }

    public function test_header_parser_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["100\tMovie [1/5] - \"a.rar\" yEnc (3/40)\tposter <a@b>\tSat, 01 Jan 2022 10:20:30 +0100\t<abc@def>\t\t123456\t1000\tnews a.b.c:100 a.b.d:200",
            "101\tx\t\t1700000000\t<abc@def>\t\t5\t1\t", "0\t\t\tbad\tnope\t\tx\t\t", "102\ts (1/2)\tf\t1 jan 1970 00:00\t<a@b>\t\t\t\t"];
        $this->assertFields(HeaderParser::class, $rows, (1 << 51) - 1, [1 | 2 << 3 | 1 << 5 | 1 << 6 | 3 << 7 | 40 << 19 | 3 << 31 | 33 << 36 | 2 << 47,
            1 | 1 << 3 | 1 << 31 | 1 << 34 | 1 << 36 | 1 << 46, 2 << 1 | 3 << 3 | 1 << 46, 1 | 2 << 3 | 1 << 6 | 1 << 7 | 2 << 19 | 7 << 36],
            [3, 1, 3, 0, 1, 0, 0, 1, 2, 1, 1, 2, 1, 0, 100, 102, 123461, 1001, 0, 1700000000, 2]);
        self::assertSame(crc32('<abc@def>') & 0xFFF, HeaderParser::w66HeaderParserPlanBatchPhp($rows)[0] >> 51);
        // RFC 2822 dates agree with strtotime().
        foreach (['Sat, 01 Jan 2022 10:20:30 +0100', 'Tue, 29 Feb 2000 23:59:59 -0530', '31 Dec 2099 00:00:00 GMT', 'Mon, 1 Mar 2021 12:00 UTC', '01 JAN 1970 00:00:00 Z',
            'Tue,  14 Nov 2023 22:13:20 +0000', 'Wed, 15 Jul 2015 08:09:10 +1400'] as $date) {
            $words = HeaderParser::w66HeaderParserPlanBatchPhp(["1\ts\tf\t{$date}\t<a@b>\t\t1\t1\t"]);
            self::assertSame(2, $words[0] >> 3 & 3, $date);
            self::assertSame(strtotime($date), $words[1 + 18], $date);
            $this->assertParity(HeaderParser::class, ["1\ts\tf\t{$date}\t<a@b>\t\t1\t1\t"]);
        }
        $this->assertRandomParity(HeaderParser::class, 6620);
    }

    public function test_header_storage_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["c1\tABC\t1\t<m1>\t100", "c1\tabc\t1\t<m2>\t100", "c1\tabc\t2\t<m1>\t1", "c2\tdef\t1\t<m3>\t9000000", "\tx\t1\t<m4>\t1"];
        $this->assertFields(HeaderStorageService::class, $rows, -1, [1 | 1 << 2 | 1 << 3 | 1 << 51, 3 | 1 << 51, 2 | 2 << 51, 1 | 1 << 2 | 1 << 3 | 1 << 4 | 2 << 5 | 1 << 7 | 1 << 23 | 1 << 51,
            1 << 39 | 1 << 51], [1, 2, 1, 1, 2, 2, 1, 0, 0, 0, 2, 9000100, 1, 3]);
        // 500 stored parts close a chunk by count; 100 new collections close one by collections.
        $words = HeaderStorageService::w66HeaderStoragePlanBatchPhp(array_map(static fn (int $i): string => "c\tb\t{$i}\t<m{$i}>\t1", range(1, 501)));
        self::assertSame([1, 1], [$words[499] >> 4 & 1, $words[499] >> 5 & 3]);
        self::assertSame(1, $words[500] >> 39 & 0xFFF);
        $words = HeaderStorageService::w66HeaderStoragePlanBatchPhp(array_map(static fn (int $i): string => "c{$i}\tb\t1\t<m{$i}>\t1", range(1, 100)));
        self::assertSame([1, 3], [$words[99] >> 4 & 1, $words[99] >> 5 & 3]);
        $this->assertRandomParity(HeaderStorageService::class, 6630);
    }

    public function test_header_storage_tx_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["500\t\t0\t10", "500\tSQLSTATE[40001]: Deadlock found\t2\t100", "1000\tGot a packet bigger than 'max_allowed_packet'\t0\t5", "1\tdeadlock\t10\t1",
            "3\tDuplicate entry\t0\t40000", "x\t\t\t"];
        $this->assertFields(HeaderStorageTransaction::class, $rows, -1, [0, 1 | 1 << 3 | 1 << 6 | 2 << 7 | 200 << 15, 2 | 5 << 3 | 500 << 31 | 2 << 41,
            3 | 1 << 3 | 1 << 6 | 10 << 7, 4 | 4 << 3 | 1 << 61, 5], [1, 1, 1, 1, 1, 1, 2, 2, 0, 0, 1, 1, 0, 0, 503, 500, 2, 200, 10, 40116, 40000, 1, 1]);
        $this->assertRandomParity(HeaderStorageTransaction::class, 6640);
    }

    public function test_missed_part_handler_plan_parity(): void
    {
        $this->requireBindings();
        $rows = ["100\t0\t", "101\t1\tno", "102\t3\t", "105\t2\tyes", "103\t0\t", "103\t0\t", "x\t\t", "7\t0\tmaybe"];
        $this->assertFields(MissedPartHandler::class, $rows, -1, [2 | 1 << 3 | 1 << 12, 2 | 2 << 3, 3, 1 | 1 << 12 | 1 << 13 | 2 << 43, 2 | 1 << 3 | 1 << 12 | 2 << 13 | 1 << 59, 4, 0, 0],
            [2, 1, 3, 1, 1, 0, 3, 3, 100, 105, 1, 0, 1, 6]);
        // Retries past the repair limit are deferred, in batches of 100.
        $words = MissedPartHandler::w66MissedPartHandlerPlanBatchPhp(array_map(static fn (int $i): string => "{$i}\t2\t", range(1, 15002)));
        self::assertSame([2, 1, 149, 5, 5], [$words[14999] & 7, $words[14999] >> 11 & 1, $words[14999] >> 33 & 0x3FF, $words[15000] & 7, $words[15001] & 7]);
        self::assertSame([0, 0, 15000, 0, 0, 2, 1, 15002, 1, 15002, 150, 15000, 0, 30004], \array_slice($words, 15002, 14));
        $this->assertParity(MissedPartHandler::class, array_map(static fn (int $i): string => "{$i}\t2\t", range(1, 15002)));
        $this->assertRandomParity(MissedPartHandler::class, 6650);
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
        if ($items !== [] && ! str_contains(implode('', $items), "\0")) {
            self::assertSame($this->call($class, 'Php', $items), $this->exported($class, $items), $class);
        }
    }

    /** @return iterable<string, array{key-of<self::ENTRIES>, list<string>}> */
    public static function edgeCases(): iterable
    {
        $long = str_repeat('Ab.', 25000);
        $binary = "\xff\xfe\x01".str_repeat("\xDB\xB2\x85", 30);
        $unicode = ['Ünïcödé.part01.rar', '映画.mkv', 'ファイル.7z', "\u{1F3AC}.r00", 'İ.NFO', "\xC9\xE1.jpg", 'naïve.PART02.RAR', 'vidéo/cövér.JPG'];
        $digits = ['0', '1', '-1', '+1', ' 1 ', "\x0b7\x0b", '1.5', '1e3', '9', '10', '99', '100', '999', '1000', '99999', '100000', '999999', '1000000', '999999999',
            '1000000000', '999999999999', '1000000000000', '0x10', '٣', '１'];
        $shared = ['', ' ', "\t", str_repeat("\t", 12), "\x0b", '-', '.', ',', ':', '<', '>', '<>', '<@>', '@', '(', '(1/2)', "\xC9\xE1", $long, $binary, $long."\t".$long,
            $long."\t".$binary."\t".$long, str_repeat("\t", 5000).'1', ...$unicode];
        foreach (array_keys(self::ENTRIES) as $class) {
            yield $class.' empty' => [$class, []];
            yield $class.' huge, binary and unicode' => [$class, $shared];
        }
        $collection = [];
        foreach (['Movie', 'MOVIE', ' movie ', 'abcdef0123456789', 'ABCDEF0123456789', 'abcdef012345678', 'abcdef0123456789g', 'bcdfghjk', 'bcdfghjka', 'bcdfghjklmnpqrstva',
            'bcdfghjklmnpqrsta', 'a:1', 'a', ...$unicode] as $name) {
            array_push($collection, "{$name}\tp\t1\t5\t5\t0", "{$name}\tp\t1\t5\t1\t15");
        }
        foreach ($digits as $value) {
            array_push($collection, "n\tp\t{$value}\t1\t1\t0", "n\tp\t1\t{$value}\t1\t0", "n\tp\t1\t1\t{$value}\t0", "n\tp\t1\t1\t1\t{$value}");
        }
        foreach ([0, 1, 2, 3, 4, 5, 6, 14, 15, 16, 17, 99] as $status) {
            foreach (['0', '3', '5', '7'] as $seen) {
                $collection[] = "s{$status}\tP\t2\t5\t{$seen}\t{$status}";
            }
            $collection[] = "z{$status}\tP\t2\t0\t3\t{$status}";
        }
        yield 'collection handler bounds' => [CollectionHandler::class, $collection];
        $parser = [];
        foreach (['a (1/2)', 'a (12/3)', 'a(1/2', 'a (/2)', 'a (1/)', 'a (1/2) (3/4)', 'a (1234567/2)', 'a (1/1234567)', 'a (x/2)', 'a (1/2 )', 'a ) (1/2',
            'a (1/2/3)', 'yEnc', 'YENC', 'yen c', 'x yenc (9999/9999)', ...$unicode] as $subject) {
            $parser[] = "1\t{$subject}\tf\t\t<a@b>\t\t1\t1\t";
        }
        foreach (['', '0', '1700000000', '9999999999', '99999999999', 'Sat, 01 Jan 2022 10:20:30 +0100', 'Saturday, 01 Jan 2022 10:20:30 +0100', '01 Jan 2022 10:20:30',
            '01 Jan 2022 10:20', '1 jan 2022 1:20', '01 Jan 2022 10:20:30 +0100 (UTC)', '00 Jan 2022 10:20:30', '32 Jan 2022 10:20:30', '001 Jan 2022 10:20:30',
            '01 Jen 2022 10:20:30', '01 January 2022 10:20:30', '01 Jan 22 10:20:30', '01 Jan 1969 23:59:59', '01 Jan 2100 00:00:00', '01 Jan 2022 24:00:00',
            '01 Jan 2022 23:60:00', '01 Jan 2022 23:59:60', '01 Jan 2022 23-59-59', '01 Jan 2022 10:20:30 +2400', '01 Jan 2022 10:20:30 +0160', '01 Jan 2022 10:20:30 EST',
            '01 Jan 2022 10:20:30 gmt', '01 Jan 2022 10:20:30 ut', '01 Jan 2022 10:20:30 0100', '01 Jan 1970 00:00:00 +0100', ',01 Jan 2022 10:20:30', 'Sat,01 Jan 2022 10:20:30',
            ' Sat, 01 Jan 2022 10:20:30 ', 'Sat, 01  Jan  2022  10:20:30', "Sat,\x0b01 Jan 2022 10:20:30"] as $date) {
            $parser[] = "2\ts\tf\t{$date}\t<a@b>\t\t1\t1\t";
        }
        foreach (['', '<', '<>', '<@>', '<a@>', '<@b>', '<a@b>', '<a@b@c>', 'a@b', '<a@b', 'a@b>', '<a b@c>', "<a\x7f@b>", '<a<@b>', '<a>@b>', '<<a@b>>', ' <a@b> ',
            "<\xC9@b>"] as $mid) {
            $parser[] = "3\ts\tf\t\t{$mid}\t\t1\t1\t";
        }
        foreach ($digits as $value) {
            array_push($parser, "{$value}\ts\tf\t\t<a{$value}@b>\t\t1\t1\t", "4\ts\tf\t\t<b@c>\t\t{$value}\t1\t", "4\ts\tf\t\t<c@d>\t\t1\t{$value}\t");
        }
        foreach (['', 'host', 'host a:1', 'host a:1 b:2 c:3', ' a:1  b:2 ', str_repeat('g:1 ', 30), "host a:1\tb:2"] as $xref) {
            $parser[] = "5\ts\tf\t\t<x@y>\t\t1\t1\t{$xref}";
        }
        yield 'header parser bounds' => [HeaderParser::class, $parser];
        $storage = [];
        foreach ($digits as $value) {
            array_push($storage, "c\tb\t{$value}\t<p{$value}>\t1", "c\tb\t1\t<b{$value}>\t{$value}");
        }
        foreach (['c', 'C', ' c ', '1', '01', 'c:', ':c', ...$unicode] as $ck) {
            foreach (['b', 'B', ' b ', 'b:1', ':', ...$unicode] as $bh) {
                $storage[] = "{$ck}\t{$bh}\t1\t<{$ck}{$bh}>\t1";
            }
        }
        for ($i = 0; $i < 620; $i++) {
            $storage[] = 'k'.($i % 130)."\tb".($i % 7)."\t".($i % 40 + 1)."\t<s{$i}>\t".($i % 9 === 0 ? 4000000 : $i);
        }
        yield 'header storage bounds' => [HeaderStorageService::class, $storage];
        $tx = [];
        foreach ($digits as $value) {
            array_push($tx, "{$value}\tdeadlock\t0\t1", "2\tdeadlock\t{$value}\t1", "2\t\t0\t{$value}");
        }
        foreach ([...self::SQL_ERRORS, 'DEADLOCK', '40001', 'lock wait timeout', 'LOCK WAIT TIMEOUT EXCEEDED', 'gone away', '2013', 'duplicate entry', 'too large', '1153',
            'foreign key', '1452', 'deadlock duplicate entry', 'Duplicate entry deadlock', ' ', "\xC9 deadlock", 'dead lock'] as $error) {
            foreach (['1', '2', '1001'] as $count) {
                foreach (['0', '9', '10', '11', '255', '999'] as $attempt) {
                    $tx[] = "{$count}\t{$error}\t{$attempt}\t30001";
                }
            }
        }
        foreach ([1, 2, 3, 999, 1000, 1001, 999999999] as $count) {
            $tx[] = "{$count}\ttoo large\t0\t0";
        }
        yield 'header storage tx bounds' => [HeaderStorageTransaction::class, $tx];
        $missed = [];
        foreach ($digits as $value) {
            array_push($missed, "{$value}\t0\t", "50\t{$value}\t");
        }
        foreach (['', 'yes', 'YES', ' true ', '1', 'no', 'False', '0', 'maybe', "\xC9", 'y'] as $flag) {
            $missed[] = '6'.\strlen($flag)."\t0\t{$flag}";
        }
        foreach ([10, 11, 12, 12, 11, 20, 21, 1, 100000, 100001, 999999999999, 1] as $n) {
            $missed[] = "{$n}\t".($n % 4)."\t";
        }
        yield 'missed part bounds' => [MissedPartHandler::class, $missed];
    }

    /**
     * Paired randomized benchmark: ROUNDS interleaved rounds visit every class and workload in
     * turn; each round times PAIRS pairs (after WARMUPS) of the PHP and Rust batch entry point on
     * one randomly chosen fixture, alternating which runs first, each side keeping the fastest of
     * REPEATS back-to-back calls. Common batches hold 320 typical rows, Uncommon batches 320-640
     * malformed or adversarial ones. Mean and nearest-rank p99 speedups pool every round's
     * samples. Every pair must agree; raw pairs are written outside the checkout. A class must
     * default to Rust exactly when all four metrics reach 1.5x. Gated behind
     * RUN_RUST_FFI_BENCHMARKS=1.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the Wave 66.2 paired benchmark.');
        }
        $this->requireBindings();
        $random = new Randomizer(new Mt19937(66));
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
                            [$results[(int) $native], $elapsed[(int) $native]] = $class::withW66RustFfi($native, fn (): array => $this->timed($class, $items));
                        }
                        self::assertSame($results[0], $results[1], $class.' '.$workload);
                        if ($i >= 0) {
                            $samples[$class][$workload][] = [$elapsed[0], $elapsed[1]];
                            $records[] = [$class, $workload, $round, $variant, $order[0], $elapsed[0], $elapsed[1]];
                        }
                    }
                }
            }
        }
        $summary = [];
        foreach ($samples as $class => $workloads) {
            $metrics = [];
            foreach ($workloads as $workload => $pairs) {
                $php = array_column($pairs, 0);
                $rust = array_column($pairs, 1);
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3), 'p99_speedup' => round($this->p99($php) / $this->p99($rust), 3),
                    'php_mean_ns' => (int) (array_sum($php) / \count($php)), 'rust_mean_ns' => (int) (array_sum($rust) / \count($rust)),
                    'php_p99_ns' => $this->p99($php), 'rust_p99_ns' => $this->p99($rust), 'samples' => \count($php),
                ];
                $metrics[] = $summary[$class][$workload]['mean_speedup'];
                $metrics[] = $summary[$class][$workload]['p99_speedup'];
            }
            $summary[$class]['qualifies'] = min($metrics) >= 1.5;
        }
        $artifact = sys_get_temp_dir().'/w662-benchmark-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($artifact, json_encode(['php' => PHP_VERSION, 'os' => php_uname(), 'rounds' => self::ROUNDS, 'pairs' => self::PAIRS, 'warmups' => self::WARMUPS,
            'repeats' => self::REPEATS, 'summary' => $summary, 'records' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW66.2 paired benchmark artifact: {$artifact}\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::ENTRIES) as $class) {
            self::assertSame($summary[$class]['qualifies'], $class::usesW66RustFfi(), $class.' default must follow the measured 1.5x gate');
        }
    }

    /**
     * Every rule is byte-level ASCII, so an explicit setlocale() changes neither implementation.
     * Declared last: once a script sets a locale, later regexes keep the rebuilt tables.
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
                    CollectionHandler::class => ["\xC9TOİLE\tPOSTER\t1\t2\t2\t0", "ÉTOİLE\tposter\t1\t2\t2\t0", "BCDFGHJKLİ\tİ\t1\t1\t1\t15", "ABCDEF0123456789\tI\t1\t0\t0\t"],
                    HeaderParser::class => ["1\tS YENC (1/2)\tİ\tSAT, 01 JAN 2022 10:20:30 GMT\t<A@B>\t\t1\t1\tH A:1", "2\t\xC9 yEnç\t\t01 İAN 2022 10:20:30\t<\xC9@B>\t\t1\t1\t"],
                    HeaderStorageService::class => ["C\tABCİ\t1\t<M>\t1", "C\tabcİ\t1\t<N>\t1", "C\tABC\xC9\t1\t<O>\t1", "C\tabc\xE9\t1\t<P>\t1"],
                    HeaderStorageTransaction::class => ["2\tDEADLOCK İ\t0\t1", "2\tDUPLİCATE ENTRY\t0\t1", "2\tLOCK WAIT TIMEOUT\t10\t1", "2\t\xC9FOREIGN KEY\t0\t1"],
                    MissedPartHandler::class => ["1\t0\tYES", "2\t0\tTRUE", "3\t0\tİ", "4\t0\tFALSE", "5\t0\tNO"],
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

    /** @param  key-of<self::ENTRIES>  $class */
    private function default(string $class): bool
    {
        $value = (new ReflectionClass($class))->getConstant('W66_RUST_FFI_DEFAULT');
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
        $php = $class::withW66RustFfi(false, fn (): array => $this->call($class, '', $items));
        $rust = $class::withW66RustFfi(true, fn (): array => $this->call($class, '', $items));
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
        self::assertGreaterThanOrEqual(320 * 2 * self::PARITY_BATCHES, $items, $class);
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
        $ffi = (new ReflectionMethod($class, 'w66RustFfiState'))->invoke(null, 'ffi');
        self::assertInstanceOf(FFI::class, $ffi, $class.' must load the nfo_w66 binding');

        return $ffi;
    }

    /** Each Wave 66.2 export must resolve and accept a one-item batch, so parity runs Rust. */
    private function requireBindings(): void
    {
        self::assertTrue(\extension_loaded('FFI'), 'FFI extension required');
        foreach (self::SYMBOLS as $class => $symbol) {
            $ffi = $this->ffi($class);
            $out = $ffi->new('int64_t[64]');
            self::assertSame(1 + self::TAIL[$class], $this->invoke($ffi, $symbol, ['a', 1, 1, $out, 64]), $class);
        }
    }

    /**
     * Calls an export declared by a class's W66 C declaration.
     *
     * @param  list<mixed>  $args
     */
    private function invoke(FFI $ffi, string $symbol, array $args): mixed
    {
        return $ffi->{$symbol}(...$args);
    }

    /** A `char *` $bytes past the start of $buffer (pointer arithmetic through a `uintptr_t` view). */
    private function offset(FFI $ffi, CData $buffer, int $bytes): CData
    {
        $pointer = $ffi->new('char *[1]');
        $pointer[0] = $ffi->cast('char *', FFI::addr($buffer));
        $address = $ffi->cast('uintptr_t *', FFI::addr($pointer));
        $address[0] += $bytes;
        $result = $pointer[0];
        self::assertInstanceOf(CData::class, $result);

        return $result;
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
        $base = $random->getInt(1, 5_000_000);
        for ($i = 0, $n = $uncommon ? $random->getInt(320, 640) : 320; $i < $n; $i++) {
            $items[] = match ($class) {
                CollectionHandler::class => $this->collectionRow($random, $uncommon),
                HeaderParser::class => $this->headerRow($random, $uncommon, $base + $i),
                HeaderStorageService::class => $this->storageRow($random, $uncommon),
                HeaderStorageTransaction::class => $this->txRow($random, $uncommon),
                MissedPartHandler::class => $this->missedRow($random, $uncommon, $base + $i),
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
            : $random->getBytesFromString("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-[]()<>@/\\+=:,\"' \t", $length);
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
            5 => str_replace(['a', 'e', 'o', '1'], ["\xE1", 'É', '0', $this->pick($random, ['l', ' ', '-', '11'])], $value),
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

    /**
     * A random row from one of the shared uncommon shapes, or $special() for the class-specific
     * ones.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $values
     * @param  callable(): string  $special
     */
    private function uncommon(Randomizer $random, array $fields, array $values, callable $special): string
    {
        return match ($random->getInt(0, 9)) {
            0 => $this->noise($random, 80, true),
            1 => $this->noise($random, 60),
            2 => '',
            3 => $this->mutateFields($random, $fields, fn (string $field): string => $this->mangle($random, $field)),
            4 => $this->mutateFields($random, $fields, fn (string $field): string => $this->pick($random, $values)),
            5, 6 => $special(),
            default => $this->mutateFields($random, $fields, fn (string $field): string => $this->noise($random, 12, true)),
        };
    }

    private function releaseName(Randomizer $random, int $words = 4): string
    {
        $parts = [];
        for ($w = $random->getInt(1, $words); $w > 0; $w--) {
            $parts[] = ucfirst($this->pick($random, self::WORDS));
        }

        return implode($this->pick($random, ['.', '.', '_', ' ']), $parts);
    }

    private function collectionRow(Randomizer $random, bool $uncommon): string
    {
        $name = match ($random->getInt(0, 11)) {
            0 => bin2hex($random->getBytes($random->getInt(8, 16))),
            1 => $random->getBytesFromString('bcdfghjklmnpqrstvwxz', $random->getInt(8, 24)),
            default => $this->releaseName($random, 2).$this->pick($random, ['', ' - [01/40]', ' yEnc', '.mkv']),
        };
        $total = $random->getInt(0, 19) === 0 ? 0 : $random->getInt(1, 60);
        $seen = $random->getInt(0, 3) === 0 ? $total : $random->getInt(0, $total + 2);
        $fields = [$name, $this->pick($random, self::POSTERS), (string) $random->getInt(1, 30), (string) $total, (string) $seen,
            $this->pick($random, ['0', '0', '0', '0', '', '15', '1', '2', '3', '4', '5', '16'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '999999', '0', 'x', ' 3 ', '', '٣', '7', '99'], fn (): string => implode("\t", [
            $this->pick($random, ['Ünïcödé Movie', 'ABCDEF0123456789ABCDEF', 'BCDFGHJKLMNP', '映画', '', ' ', $this->noise($random, 300)]), $fields[1], $fields[2], $fields[3],
            $fields[4], $this->pick($random, ['6', '14', '17', '-0', '00', '015'])]));
    }

    private function headerRow(Randomizer $random, bool $uncommon, int $number): string
    {
        $total = $random->getInt(1, 400);
        $part = $random->getInt(1, $total);
        $name = $this->releaseName($random).'.'.$random->getInt(1990, 2025).$this->pick($random, ['.part01.rar', '.r00', '.mkv', '.par2', '.nfo', '.vol01+02.par2']);
        $subject = $random->getInt(0, 4) === 0 ? $name.' yEnc ('.$part.'/'.$total.')' : '['.$random->getInt(1, 40).'/40] - "'.$name.'" yEnc ('.$part.'/'.$total.')';
        $ts = $random->getInt(1_000_000_000, 2_000_000_000);
        $date = match ($random->getInt(0, 5)) {
            0 => (string) $ts,
            1 => gmdate('d M Y H:i:s', $ts).' '.$this->pick($random, self::ZONES),
            default => gmdate('D, d M Y H:i:s', $ts).' '.$this->pick($random, self::ZONES),
        };
        $mid = $random->getInt(0, 9) === 0 ? '<dup@news.example>' : '<part'.$part.'of'.$total.'.'.bin2hex($random->getBytes(6)).'@news.example>';
        $bytes = $random->getInt(300_000, 800_000);
        $fields = [(string) ($random->getInt(0, 19) === 0 ? $number - 1 : $number), $subject, $this->pick($random, self::POSTERS), $date, $mid, '', (string) $bytes,
            (string) intdiv($bytes, 128), 'news.example.com alt.binaries.'.$this->pick($random, self::WORDS).':'.$number.($random->getInt(0, 2) === 0 ? ' alt.binaries.x:1' : '')];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999999', '0', 'x', ' 3 ', '', '٣', '<>', '<a@b@c>', 'Sat, 32 Jan 2022 10:20:30 +0000'], fn (): string => implode("\t", [
            $fields[0], $this->pick($random, ['Ünïcödé (1/2)', '映画 (1/1234567)', '"" (1/2)', '(x/2)', 'a (1/2) (3/4)', '(/)', $this->noise($random, 120, true).' (1/2)']),
            $this->pick($random, ['', 'İ', "\xC9"]), $this->pick($random, ['Sat, 01 Jan 2022 10:20:30 +0100 (UTC)', '01 Jan 2022 10:20', 'Sat,01 jan 2022 23:59:59 z',
                '01 Jen 2022 10:20:30', '1 Jan 1970 00:00:00 +0100', $this->noise($random, 30)]), $this->pick($random, ['<a b@c>', 'a@b', "<a\x7f@b>", '<@b>', $mid]),
            $this->noise($random, 40), $fields[6], $fields[7], $this->noise($random, 60)]));
    }

    private function storageRow(Randomizer $random, bool $uncommon): string
    {
        $collection = sha1('c'.$random->getInt(0, 40));
        $fields = [$collection, substr(md5($collection.$random->getInt(0, 6)), 0, $random->getInt(0, 3) === 0 ? 32 : 16), (string) $random->getInt(1, 120),
            '<'.($random->getInt(0, 29) === 0 ? 'dup' : bin2hex($random->getBytes(8))).'@news.example>', (string) ($random->getInt(0, 9) === 0 ? 0 : $random->getInt(0, 800_000))];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999999', '0', 'x', ' 3 ', '', '٣', '1000000'], fn (): string => implode("\t", [
            $this->pick($random, [strtoupper($collection), ' '.$collection.' ', 'Ünïcödé', '映画']), $this->pick($random, [strtoupper($fields[1]), 'ABCİ', "\xC9", '']),
            $fields[2], $this->pick($random, [$fields[3], '<dup@news.example>', ' <x@y> ']), $this->pick($random, ['8388608', '4194304', $fields[4]])]));
    }

    private function txRow(Randomizer $random, bool $uncommon): string
    {
        $fields = [(string) $random->getInt(1, 2000), $this->pick($random, self::SQL_ERRORS), (string) ($random->getInt(0, 3) === 0 ? $random->getInt(0, 14) : $random->getInt(0, 3)),
            (string) $random->getInt(0, 60_000)];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999999', '0', 'x', ' 3 ', '', '٣', '1000'], fn (): string => implode("\t", [
            $this->pick($random, ['1', '2', '999999999', $fields[0]]), $this->pick($random, ['DEADLOCK', 'Dead lock', 'İ deadlock', "\xC9 1062", str_repeat('error ', 80).'2013',
                $this->noise($random, 120, true)]), $fields[2], $fields[3]]));
    }

    private function missedRow(Randomizer $random, bool $uncommon, int $number): string
    {
        $n = match ($random->getInt(0, 14)) {
            0 => $number + $random->getInt(2, 500),
            1 => $number - $random->getInt(1, 50),
            default => $number,
        };
        $fields = [(string) $n, (string) $this->pick($random, [0, 0, 0, 1, 1, 2, 3, 4]), $this->pick($random, ['', '', '', '', 'no', 'yes', '0', '1'])];
        if (! $uncommon) {
            return implode("\t", $fields);
        }

        return $this->uncommon($random, $fields, ['-1', '9999999999999', '0', 'x', ' 3 ', '', '٣', '999'], fn (): string => implode("\t", [
            $fields[0], $this->pick($random, ['255', '1000', ' 2 ', '00']), $this->pick($random, ['YES', 'True', 'maybe', "\xC9", ' no '])]));
    }
}
