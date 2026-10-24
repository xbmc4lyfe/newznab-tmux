<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\AdditionalProcessing\DTO\MediaSourceHints;
use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageTransaction;
use App\Services\Binaries\MissedPartHandler;
use App\Support\Par2RenameProgress;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use JsonException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wave 15 Slice 4 parity and paired randomized benchmark suite for CollectionHandler,
 * HeaderStorageTransaction, MissedPartHandler, AdditionalWorkPlan, MediaSourceHints,
 * ConsoleOutputService and Par2RenameProgress.
 *
 * Paired randomized benchmark evidence (10,000 paired observations across 2 repetitions per
 * workload regime, Apple M5, PHP 8.5.12 CLI with opcache, rustc 1.99.1 release + LTO, base
 * 950ac7d53; `BINARIES_WORKPLAN_BENCHMARK=1`, optional `_REPS` and `_PAIRS`). Speedup is
 * PHP / Rust latency including FFI marshaling:
 *
 * | Method | Common Mean | Common p99 | Uncommon Mean | Uncommon p99 |
 * | --- | --- | --- | --- | --- |
 * | `CollectionHandler::getAllIds` | 0.905x (1117 / 1235 ns) | 0.895x | 1.241x (48741 / 39280 ns) | 1.234x |
 * | `CollectionHandler::getBatchHashes` | 0.077x (125 / 1626 ns) | 0.082x | 0.069x (4529 / 65159 ns) | 0.081x |
 * | `HeaderStorageTransaction::encodeBatchNoise` | 0.216x (41 / 191 ns) | 0.402x | 0.330x (122 / 368 ns) | 0.453x |
 * | `HeaderStorageTransaction::cleanupStrategy` | 0.268x (28 / 103 ns) | 0.253x | 0.281x (30 / 105 ns) | 0.336x |
 * | `MissedPartHandler::expandRange` | 0.146x (91 / 626 ns) | 0.210x | 0.134x (15394 / 114980 ns) | 0.150x |
 * | `MissedPartHandler::rangeChunks` | 0.212x (92 / 436 ns) | 0.231x | 0.822x (481555 / 585551 ns) | 0.998x |
 * | `AdditionalWorkPlan::setKeyOf` | 0.178x (42 / 235 ns) | 0.288x | 0.253x (69 / 274 ns) | 0.251x |
 * | `AdditionalWorkPlan::archiveSetCount` | 0.651x (432 / 663 ns) | 0.651x | 0.840x (16728 / 19919 ns) | 0.976x |
 * | `AdditionalWorkPlan::orderedArchiveCandidates` | 0.127x (64 / 500 ns) | 0.134x | 0.084x (819 / 9710 ns) | 0.084x |
 * | `AdditionalWorkPlan::prioritizedArchiveCandidates` | 0.307x (155 / 503 ns) | 0.400x | 0.445x (4431 / 9964 ns) | 0.401x |
 * | `MediaSourceHints::availableFraction` | 0.241x (48 / 197 ns) | 0.336x | 0.243x (49 / 199 ns) | 0.336x |
 * | `MediaSourceHints::head` | 0.476x (140 / 294 ns) | 0.623x | 0.478x (144 / 302 ns) | 0.455x |
 * | `ConsoleOutputService::formatStatus` | 0.684x (272 / 399 ns) | 0.668x | 0.355x (102 / 288 ns) | 0.333x |
 * | `Par2RenameProgress::normalizeRetryIndexes` | 0.692x (230 / 332 ns) | 0.700x | 2.252x (39309 / 17454 ns) | 2.075x |
 * | `Par2RenameProgress::encodeRetryIndexes` | 0.254x (71 / 281 ns) | 0.224x | 0.417x (9288 / 22275 ns) | 0.447x |
 *
 * Production defaults: `$useRustFfi = false` for every class. No method clears 1.5x on all
 * four metrics; `normalizeRetryIndexes` wins only on 1,300-entry payloads while the common
 * three-entry payload loses to `json_decode`, and the rest are dominated by FFI marshaling.
 */
final class RustBinariesCollectionAndWorkPlanParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array STATIC_TOGGLE_CLASSES = [
        CollectionHandler::class,
        HeaderStorageTransaction::class,
        MissedPartHandler::class,
        ConsoleOutputService::class,
        Par2RenameProgress::class,
    ];

    private const string FULL_CDEF = CollectionHandler::FFI_CDEF
        .HeaderStorageTransaction::FFI_CDEF
        .MissedPartHandler::FFI_CDEF
        .AdditionalWorkPlan::FFI_CDEF
        .MediaSourceHints::FFI_CDEF
        .ConsoleOutputService::FFI_CDEF
        .Par2RenameProgress::FFI_CDEF;

    private FFI $ffi;

    private Randomizer $random;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Rust pipeline FFI library must be built with Wave 15 Slice 4 exports.');
        $this->ffi = $ffi;
        $this->random = new Randomizer(new Mt19937(20261024));
    }

    protected function tearDown(): void
    {
        foreach (self::STATIC_TOGGLE_CLASSES as $class) {
            $class::setUseRustFfi(false);
        }
        parent::tearDown();
    }

    public function test_default_use_rust_ffi_flags_match_benchmark_threshold(): void
    {
        foreach (self::STATIC_TOGGLE_CLASSES as $class) {
            $default = (new ReflectionClass($class))->getDefaultProperties()['useRustFfi'] ?? null;
            self::assertFalse($default, "{$class} should default to useRustFfi = false");
            self::assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            self::assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
        }
        self::assertFalse((new AdditionalWorkPlan)->useRustFfi);
        self::assertFalse(MediaSourceHints::complete()->useRustFfi);
        self::assertFalse(MediaSourceHints::head(10, 5)->useRustFfi);
        $mapped = (new AdditionalWorkPlan(useRustFfi: true))->withMappedNames(new AdditionalWorkPlan(sampleMessageIds: ['a']));
        self::assertTrue($mapped->useRustFfi);
    }

    public function test_c_abi_null_pointer_and_short_buffer_contracts(): void
    {
        $ffi = $this->ffi;
        $tiny = $ffi->new('char[2]');
        $status = $ffi->new('int32_t');
        $packed = pack('q*', 1, 2, 3);

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_collection_unique_ids(null, 8, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_collection_unique_ids(null, 0, null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-24, $ffi->nntmux_collection_unique_ids($packed, 24, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_collection_unique_ids($packed, 23, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_collection_unique_hashes($packed, 24, 0, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_collection_unique_hashes($packed, 24, 20, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-16, $ffi->nntmux_header_batch_noise_hex('12345678', 8, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_header_batch_noise_hex(null, 8, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_missed_range_expand(1, 10, 5, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-80, $ffi->nntmux_missed_range_expand(1, 10, 50, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_missed_range_chunks(1, 10, 0, 50, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_workplan_set_key(null, 3, 1, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-5, $ffi->nntmux_workplan_set_key('', 0, 7, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_workplan_archive_set_count('ab', 2, pack('V', 3), 4, pack('q', 0), 8, 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_workplan_candidate_order("\0\1", 2, 9, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertNan($ffi->nntmux_media_available_fraction(1, 1, 10, 5, null));
        // @phpstan-ignore method.notFound
        $ffi->nntmux_media_available_fraction(1, 1, 0, 5, FFI::addr($status));
        // @phpstan-ignore property.notFound
        self::assertSame(-1, $status->cdata);
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_media_head_hint(1, 10, 5, null));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_console_status_format(99, 0, 0, '', 0, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_console_status_format(3, 0, 0, null, 4, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_par2_progress_normalize_retry('[1.5]', 5, 10, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-8, $ffi->nntmux_par2_progress_normalize_retry('[1]', 3, 10, $tiny, 2));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_par2_progress_encode_retry('abc', 3, $tiny, 2));
    }

    public function test_collection_handler_parity(): void
    {
        $empty = $this->makeCollectionHandler([], []);
        self::assertSame([], $empty->getAllIdsNative());
        self::assertSame([], $empty->getBatchHashesNative());

        for ($i = 0; $i < 200; $i++) {
            $ids = [];
            $hashes = [];
            $size = $this->random->getInt(0, 600);
            for ($j = 0; $j < $size; $j++) {
                $ids['key'.$j] = $this->random->getInt(-3, 120);
                $hashes[sha1('h'.$this->random->getInt(0, 400), true)] = true;
            }
            $handler = $this->makeCollectionHandler($ids, $hashes);
            self::assertSame($handler->getAllIdsPhp(), $handler->getAllIdsNative());
            self::assertSame($handler->getBatchHashesPhp(), $handler->getBatchHashesNative());
            CollectionHandler::setUseRustFfi(true);
            self::assertSame($handler->getAllIdsPhp(), $handler->getAllIds());
            self::assertSame($handler->getBatchHashesPhp(), $handler->getBatchHashes());
            CollectionHandler::setUseRustFfi(false);
        }

        $legacy = $this->makeCollectionHandler(['a' => 1], ['not-a-raw-sha1' => true, 42 => true]);
        self::assertNull($legacy->getBatchHashesNative());
        CollectionHandler::setUseRustFfi(true);
        self::assertSame(['not-a-raw-sha1', 42], $legacy->getBatchHashes());
    }

    public function test_header_storage_transaction_parity(): void
    {
        foreach (['', "\0", random_bytes(8), str_repeat("\xff", 64)] as $bytes) {
            self::assertSame(HeaderStorageTransaction::encodeBatchNoisePhp($bytes), HeaderStorageTransaction::encodeBatchNoiseNative($bytes));
        }
        for ($i = 0; $i < 200; $i++) {
            $bytes = $this->random->getBytes($this->random->getInt(1, 32));
            self::assertSame(bin2hex($bytes), HeaderStorageTransaction::encodeBatchNoiseNative($bytes));
        }
        foreach ([0, 1, 7] as $inserted) {
            foreach ([0, 1, 9] as $all) {
                foreach ([0, 1, 500] as $hashes) {
                    self::assertSame(
                        HeaderStorageTransaction::cleanupStrategyPhp($inserted, $all, $hashes),
                        HeaderStorageTransaction::cleanupStrategyNative($inserted, $all, $hashes),
                    );
                }
            }
        }
        HeaderStorageTransaction::setUseRustFfi(true);
        self::assertSame('noise', HeaderStorageTransaction::cleanupStrategy(0, 0, 0));
        self::assertSame('0aff', HeaderStorageTransaction::encodeBatchNoise("\x0a\xff"));
    }

    public function test_missed_part_handler_parity(): void
    {
        $cases = [[5, 4], [1, 1], [1001, 1052], [0, 999], [PHP_INT_MAX - 3, PHP_INT_MAX], [PHP_INT_MIN, PHP_INT_MIN + 2], [-10, 10]];
        for ($i = 0; $i < 200; $i++) {
            $first = $this->random->getInt(0, 5_000_000);
            $cases[] = [$first, $first + $this->random->getInt(-5, 25_000)];
        }
        foreach ($cases as [$first, $last]) {
            self::assertSame(MissedPartHandler::expandRangePhp($first, $last), MissedPartHandler::expandRangeNative($first, $last), "expand {$first}..{$last}");
            foreach ([1, 50, 500, 1000] as $chunk) {
                self::assertSame(
                    MissedPartHandler::rangeChunksPhp($first, $last, $chunk),
                    MissedPartHandler::rangeChunksNative($first, $last, $chunk),
                    "chunks {$first}..{$last}/{$chunk}",
                );
            }
        }
        self::assertSame([[1001, 1050], [1051, 1052]], MissedPartHandler::rangeChunksPhp(1001, 1052, 50));
        self::assertNull(MissedPartHandler::expandRangeNative(0, 2_000_000));
        self::assertNull(MissedPartHandler::rangeChunksNative(PHP_INT_MIN, PHP_INT_MAX, 1000));
        MissedPartHandler::setUseRustFfi(true);
        self::assertSame(range(1, 3), MissedPartHandler::expandRange(1, 3));
        self::assertSame([[1, 2], [3, 3]], MissedPartHandler::rangeChunks(1, 3, 2));
    }

    public function test_additional_work_plan_parity(): void
    {
        self::assertSame(0, (new AdditionalWorkPlan)->archiveSetCountNative());
        self::assertSame([], (new AdditionalWorkPlan)->prioritizedArchiveCandidatesNative());
        $keys = ['', '', 'rar:movie', 'rar:show.s01e01', 'src:3', '7z:pack', "bin\0key", 'split:movie.mkv'];

        for ($i = 0; $i < 200; $i++) {
            $candidates = [];
            $size = $this->random->getInt(0, 120);
            for ($j = 0; $j < $size; $j++) {
                $candidates[] = new ArchiveCandidate(
                    title: 'file'.$j.'.rar',
                    messageIds: ['<m'.$j.'@x>'],
                    likelyFirstVolume: $this->random->getInt(0, 3) === 0,
                    sourceIndex: $this->random->getInt(-2, 10),
                    setKey: $keys[$this->random->getInt(0, \count($keys) - 1)],
                );
            }
            $plan = new AdditionalWorkPlan(archiveCandidates: $candidates);
            self::assertSame($plan->archiveSetCountPhp(), $plan->archiveSetCountNative());
            self::assertSame($plan->orderedArchiveCandidatesPhp(), $plan->orderedArchiveCandidatesNative());
            self::assertSame($plan->orderedArchiveCandidatesPhp(true), $plan->orderedArchiveCandidatesNative(true));
            self::assertSame($plan->prioritizedArchiveCandidatesPhp(), $plan->prioritizedArchiveCandidatesNative());
            foreach ($candidates as $candidate) {
                self::assertSame($plan->setKeyOfPhp($candidate), $plan->setKeyOfNative($candidate));
            }
            $native = new AdditionalWorkPlan(archiveCandidates: $candidates, useRustFfi: true);
            self::assertSame($plan->archiveSetCount(), $native->archiveSetCount());
            self::assertSame($plan->prioritizedArchiveCandidates(), $native->prioritizedArchiveCandidates());
        }
    }

    public function test_media_source_hints_parity(): void
    {
        $sizes = [null, 0, 1, 3, 7, 100, -4, PHP_INT_MAX, PHP_INT_MIN, 1 << 53, (1 << 53) + 1];
        $available = [0, 1, 2, 3, 50, 100, 101, -5, PHP_INT_MAX, PHP_INT_MIN, 1 << 54, (1 << 54) + 3];
        for ($i = 0; $i < 100; $i++) {
            $sizes[] = $this->random->getInt(-10, 10_000_000_000);
            $available[] = $this->random->getInt(-10, 10_000_000_000);
        }
        foreach ($sizes as $realSize) {
            foreach ($available as $bytes) {
                self::assertEquals(MediaSourceHints::headPhp($realSize, $bytes), MediaSourceHints::headNative($realSize, $bytes));
                foreach (SourcePosition::cases() as $position) {
                    $hint = new MediaSourceHints($position, $realSize, $bytes);
                    $native = $hint->availableFractionNative();
                    if ($native === false) {
                        self::assertSame(0, $realSize, 'Only a zero real size defers to PHP.');

                        continue;
                    }
                    self::assertSame($hint->availableFractionPhp(), $native, "{$position->value} {$realSize} {$bytes}");
                    self::assertSame($native, (new MediaSourceHints($position, $realSize, $bytes, true))->availableFraction());
                }
            }
        }
        $this->expectException(\DivisionByZeroError::class);
        (new MediaSourceHints(SourcePosition::Head, 0, 5, true))->availableFraction();
    }

    public function test_console_output_service_parity(): void
    {
        $sizes = [0, 1, 9, 10, 99, 100, 999, 1000, 1023, 1024, 1536, 1048576, 734003200, 4_700_000_000, -1, -1000, -1_000_000, PHP_INT_MAX, PHP_INT_MIN];
        for ($i = 0; $i < 2000; $i++) {
            $sizes[] = $this->random->getInt(0, 10 ** $this->random->getInt(1, 18));
        }
        foreach ($sizes as $size) {
            self::assertSame(
                ConsoleOutputService::formatStatusPhp(ConsoleOutputService::STATUS_RELEASE_START, 42, $size),
                ConsoleOutputService::formatStatusNative(ConsoleOutputService::STATUS_RELEASE_START, 42, $size),
                "size {$size}",
            );
        }
        foreach ([ConsoleOutputService::STATUS_COMPRESSED_FAILURE, ConsoleOutputService::STATUS_PAR2_MAPPED, ConsoleOutputService::STATUS_MEDIA_SOURCE, ConsoleOutputService::STATUS_RELEASE_TIMEOUT, ConsoleOutputService::STATUS_RELEASE_TIMEOUT_DELETED, ConsoleOutputService::STATUS_RELEASE_DELETED] as $kind) {
            foreach ([[0, 0, ''], [7, 30, 'head'], [PHP_INT_MIN, PHP_INT_MAX, "mid\0x"], [-1, -2, str_repeat('k', 500)]] as [$a, $b, $text]) {
                self::assertSame(ConsoleOutputService::formatStatusPhp($kind, $a, $b, $text), ConsoleOutputService::formatStatusNative($kind, $a, $b, $text));
            }
        }
        self::assertNull(ConsoleOutputService::formatStatusNative(99));
        ConsoleOutputService::setUseRustFfi(true);
        self::assertSame(' Release 5 timed out after 9s', ConsoleOutputService::formatStatus(ConsoleOutputService::STATUS_RELEASE_TIMEOUT, 5, 9));
        $this->expectException(\InvalidArgumentException::class);
        ConsoleOutputService::formatStatus(99);
    }

    public function test_par2_rename_progress_parity(): void
    {
        $payloads = ['[]', '[ ]', '[1,2,3]', " [ -0 , 7\n,\t9 ] ", 'null', 'true', 'false', '0', '-12', '[1,"2",3.0,true,null,1e2,[4],{"a":5},6]',
            '{"a":1,"b":"x","c":3}', '"text"', '1.5', '[99999999999999999999, 5]', '['.PHP_INT_MAX.','.PHP_INT_MIN.']'];
        for ($i = 0; $i < 300; $i++) {
            $values = [];
            $size = $this->random->getInt(0, 200);
            for ($j = 0; $j < $size; $j++) {
                $values[] = $this->random->getInt(-5, 1_000_000);
            }
            $payloads[] = json_encode($values, JSON_THROW_ON_ERROR);
            self::assertSame(Par2RenameProgress::encodeRetryIndexesPhp($values), Par2RenameProgress::encodeRetryIndexesNative($values));
        }
        foreach ($payloads as $payload) {
            $native = Par2RenameProgress::normalizeRetryIndexesNative($payload);
            if ($native !== null) {
                self::assertSame(Par2RenameProgress::normalizeRetryIndexesPhp($payload), $native, $payload);
            }
            Par2RenameProgress::setUseRustFfi(true);
            self::assertSame(Par2RenameProgress::normalizeRetryIndexesPhp($payload), Par2RenameProgress::normalizeRetryIndexes($payload), $payload);
            Par2RenameProgress::setUseRustFfi(false);
        }
        self::assertSame([1, 2, 3], Par2RenameProgress::normalizeRetryIndexesNative('[1,2,3]'));
        self::assertSame([], Par2RenameProgress::normalizeRetryIndexesNative('null'));
        self::assertNull(Par2RenameProgress::normalizeRetryIndexesNative('[1,"2"]'));
        self::assertNull(Par2RenameProgress::encodeRetryIndexesNative([1 => 5]));
        self::assertNull(Par2RenameProgress::encodeRetryIndexesNative(['5']));
        self::assertSame('[]', Par2RenameProgress::encodeRetryIndexesNative([]));

        Par2RenameProgress::setUseRustFfi(true);
        $this->expectException(JsonException::class);
        Par2RenameProgress::normalizeRetryIndexes('[1,');
    }

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('BINARIES_WORKPLAN_BENCHMARK') !== '1') {
            self::markTestSkipped('Set BINARIES_WORKPLAN_BENCHMARK=1 to run paired randomized benchmarks.');
        }

        $repetitions = max(2, (int) (getenv('BINARIES_WORKPLAN_BENCHMARK_REPS') ?: 2));
        $pairsPerRep = max(2000, (int) (getenv('BINARIES_WORKPLAN_BENCHMARK_PAIRS') ?: 5000));
        $summary = [];

        foreach ($this->buildBenchmarkWorkloads() as $methodName => $regimes) {
            foreach (['common', 'uncommon'] as $regime) {
                [$phpCall, $rustCall] = $regimes[$regime];
                self::assertEquals($phpCall(), $rustCall(), "Benchmark pre-check failed for {$methodName} ({$regime})");

                for ($w = 0; $w < 200; $w++) {
                    $phpCall();
                    $rustCall();
                }

                $phpAll = [];
                $rustAll = [];
                $repStats = [];
                for ($rep = 0; $rep < $repetitions; $rep++) {
                    $phpRep = [];
                    $rustRep = [];
                    for ($i = 0; $i < $pairsPerRep; $i++) {
                        if ($this->random->getInt(0, 1) === 1) {
                            $t0 = hrtime(true);
                            $rustCall();
                            $rustNs = hrtime(true) - $t0;
                            $t1 = hrtime(true);
                            $phpCall();
                            $phpNs = hrtime(true) - $t1;
                        } else {
                            $t0 = hrtime(true);
                            $phpCall();
                            $phpNs = hrtime(true) - $t0;
                            $t1 = hrtime(true);
                            $rustCall();
                            $rustNs = hrtime(true) - $t1;
                        }
                        $phpRep[] = $phpNs;
                        $rustRep[] = $rustNs;
                    }
                    array_push($phpAll, ...$phpRep);
                    array_push($rustAll, ...$rustRep);
                    sort($phpRep);
                    sort($rustRep);
                    $rank = (int) ceil($pairsPerRep * 0.99) - 1;
                    $repStats[] = [
                        'mean_speedup' => round((array_sum($phpRep) / $pairsPerRep) / max(1.0, array_sum($rustRep) / $pairsPerRep), 3),
                        'p99_speedup' => round($phpRep[$rank] / max(1, $rustRep[$rank]), 3),
                    ];
                }

                sort($phpAll);
                sort($rustAll);
                $total = \count($phpAll);
                $p99 = (int) ceil($total * 0.99) - 1;
                $phpMean = array_sum($phpAll) / $total;
                $rustMean = array_sum($rustAll) / $total;
                $summary[$methodName][$regime] = [
                    'samples' => $total,
                    'php_mean_ns' => round($phpMean, 1),
                    'rust_mean_ns' => round($rustMean, 1),
                    'php_p99_ns' => $phpAll[$p99],
                    'rust_p99_ns' => $rustAll[$p99],
                    'mean_speedup' => round($phpMean / max(1.0, $rustMean), 3),
                    'p99_speedup' => round($phpAll[$p99] / max(1, $rustAll[$p99]), 3),
                    'reps' => $repStats,
                ];
            }
        }

        fwrite(STDERR, "\nWave 15 Slice 4 Benchmark Summary:\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        self::assertNotEmpty($summary);
    }

    /**
     * @return array<string, array{common: array{Closure, Closure}, uncommon: array{Closure, Closure}}>
     */
    private function buildBenchmarkWorkloads(): array
    {
        $commonIds = [];
        $uncommonIds = [];
        $commonHashes = [];
        $uncommonHashes = [];
        for ($i = 0; $i < 40; $i++) {
            $commonIds['c'.$i] = 1000 + intdiv($i, 2);
            $commonHashes[sha1('c'.$i, true)] = true;
        }
        for ($i = 0; $i < 2000; $i++) {
            $uncommonIds['u'.$i] = 5000 + $i % 700;
            $uncommonHashes[sha1('u'.$i, true)] = true;
        }
        $commonCollections = $this->makeCollectionHandler($commonIds, $commonHashes);
        $uncommonCollections = $this->makeCollectionHandler($uncommonIds, $uncommonHashes);
        $commonNoise = random_bytes(8);
        $uncommonNoise = random_bytes(256);

        $commonPlan = new AdditionalWorkPlan(archiveCandidates: $this->makeCandidates(8, 1));
        $uncommonPlan = new AdditionalWorkPlan(archiveCandidates: $this->makeCandidates(400, 40));
        $commonCandidate = $commonPlan->archiveCandidates[0];
        $uncommonCandidate = new ArchiveCandidate('x.bin', ['<x@y>'], false, PHP_INT_MAX);

        $commonHint = new MediaSourceHints(SourcePosition::Head, 734_003_200, 20_000_000);
        $uncommonHint = new MediaSourceHints(SourcePosition::Mid, 3, (1 << 54) + 1);

        $commonRetry = '[3,17,42]';
        $uncommonRetry = json_encode(range(0, 4000, 3), JSON_THROW_ON_ERROR);
        $commonRetryList = [3, 17, 42];
        $uncommonRetryList = range(0, 4000, 3);

        return [
            'CollectionHandler::getAllIds' => [
                'common' => [static fn (): array => $commonCollections->getAllIdsPhp(), static fn (): ?array => $commonCollections->getAllIdsNative()],
                'uncommon' => [static fn (): array => $uncommonCollections->getAllIdsPhp(), static fn (): ?array => $uncommonCollections->getAllIdsNative()],
            ],
            'CollectionHandler::getBatchHashes' => [
                'common' => [static fn (): array => $commonCollections->getBatchHashesPhp(), static fn (): ?array => $commonCollections->getBatchHashesNative()],
                'uncommon' => [static fn (): array => $uncommonCollections->getBatchHashesPhp(), static fn (): ?array => $uncommonCollections->getBatchHashesNative()],
            ],
            'HeaderStorageTransaction::encodeBatchNoise' => [
                'common' => [static fn (): string => HeaderStorageTransaction::encodeBatchNoisePhp($commonNoise), static fn (): ?string => HeaderStorageTransaction::encodeBatchNoiseNative($commonNoise)],
                'uncommon' => [static fn (): string => HeaderStorageTransaction::encodeBatchNoisePhp($uncommonNoise), static fn (): ?string => HeaderStorageTransaction::encodeBatchNoiseNative($uncommonNoise)],
            ],
            'HeaderStorageTransaction::cleanupStrategy' => [
                'common' => [static fn (): string => HeaderStorageTransaction::cleanupStrategyPhp(12, 12, 12), static fn (): ?string => HeaderStorageTransaction::cleanupStrategyNative(12, 12, 12)],
                'uncommon' => [static fn (): string => HeaderStorageTransaction::cleanupStrategyPhp(0, 0, 0), static fn (): ?string => HeaderStorageTransaction::cleanupStrategyNative(0, 0, 0)],
            ],
            'MissedPartHandler::expandRange' => [
                'common' => [static fn (): array => MissedPartHandler::expandRangePhp(4_000_000, 4_000_049), static fn (): ?array => MissedPartHandler::expandRangeNative(4_000_000, 4_000_049)],
                'uncommon' => [static fn (): array => MissedPartHandler::expandRangePhp(4_000_000, 4_019_999), static fn (): ?array => MissedPartHandler::expandRangeNative(4_000_000, 4_019_999)],
            ],
            'MissedPartHandler::rangeChunks' => [
                'common' => [static fn (): array => MissedPartHandler::rangeChunksPhp(4_000_000, 4_000_999, 500), static fn (): ?array => MissedPartHandler::rangeChunksNative(4_000_000, 4_000_999, 500)],
                'uncommon' => [static fn (): array => MissedPartHandler::rangeChunksPhp(4_000_000, 4_999_999, 50), static fn (): ?array => MissedPartHandler::rangeChunksNative(4_000_000, 4_999_999, 50)],
            ],
            'AdditionalWorkPlan::setKeyOf' => [
                'common' => [static fn (): string => $commonPlan->setKeyOfPhp($commonCandidate), static fn (): ?string => $commonPlan->setKeyOfNative($commonCandidate)],
                'uncommon' => [static fn (): string => $commonPlan->setKeyOfPhp($uncommonCandidate), static fn (): ?string => $commonPlan->setKeyOfNative($uncommonCandidate)],
            ],
            'AdditionalWorkPlan::archiveSetCount' => [
                'common' => [static fn (): int => $commonPlan->archiveSetCountPhp(), static fn (): ?int => $commonPlan->archiveSetCountNative()],
                'uncommon' => [static fn (): int => $uncommonPlan->archiveSetCountPhp(), static fn (): ?int => $uncommonPlan->archiveSetCountNative()],
            ],
            'AdditionalWorkPlan::orderedArchiveCandidates' => [
                'common' => [static fn (): array => $commonPlan->orderedArchiveCandidatesPhp(true), static fn (): ?array => $commonPlan->orderedArchiveCandidatesNative(true)],
                'uncommon' => [static fn (): array => $uncommonPlan->orderedArchiveCandidatesPhp(true), static fn (): ?array => $uncommonPlan->orderedArchiveCandidatesNative(true)],
            ],
            'AdditionalWorkPlan::prioritizedArchiveCandidates' => [
                'common' => [static fn (): array => $commonPlan->prioritizedArchiveCandidatesPhp(), static fn (): ?array => $commonPlan->prioritizedArchiveCandidatesNative()],
                'uncommon' => [static fn (): array => $uncommonPlan->prioritizedArchiveCandidatesPhp(), static fn (): ?array => $uncommonPlan->prioritizedArchiveCandidatesNative()],
            ],
            'MediaSourceHints::availableFraction' => [
                'common' => [static fn (): ?float => $commonHint->availableFractionPhp(), static fn (): float|false|null => $commonHint->availableFractionNative()],
                'uncommon' => [static fn (): ?float => $uncommonHint->availableFractionPhp(), static fn (): float|false|null => $uncommonHint->availableFractionNative()],
            ],
            'MediaSourceHints::head' => [
                'common' => [static fn (): MediaSourceHints => MediaSourceHints::headPhp(734_003_200, 20_000_000), static fn (): ?MediaSourceHints => MediaSourceHints::headNative(734_003_200, 20_000_000)],
                'uncommon' => [static fn (): MediaSourceHints => MediaSourceHints::headPhp(null, PHP_INT_MAX), static fn (): ?MediaSourceHints => MediaSourceHints::headNative(null, PHP_INT_MAX)],
            ],
            'ConsoleOutputService::formatStatus' => [
                'common' => [
                    static fn (): string => ConsoleOutputService::formatStatusPhp(ConsoleOutputService::STATUS_RELEASE_START, 1234567, 734_003_200),
                    static fn (): ?string => ConsoleOutputService::formatStatusNative(ConsoleOutputService::STATUS_RELEASE_START, 1234567, 734_003_200),
                ],
                'uncommon' => [
                    static fn (): string => ConsoleOutputService::formatStatusPhp(ConsoleOutputService::STATUS_RELEASE_TIMEOUT_DELETED, PHP_INT_MAX, PHP_INT_MIN),
                    static fn (): ?string => ConsoleOutputService::formatStatusNative(ConsoleOutputService::STATUS_RELEASE_TIMEOUT_DELETED, PHP_INT_MAX, PHP_INT_MIN),
                ],
            ],
            'Par2RenameProgress::normalizeRetryIndexes' => [
                'common' => [static fn (): array => Par2RenameProgress::normalizeRetryIndexesPhp($commonRetry), static fn (): ?array => Par2RenameProgress::normalizeRetryIndexesNative($commonRetry)],
                'uncommon' => [static fn (): array => Par2RenameProgress::normalizeRetryIndexesPhp($uncommonRetry), static fn (): ?array => Par2RenameProgress::normalizeRetryIndexesNative($uncommonRetry)],
            ],
            'Par2RenameProgress::encodeRetryIndexes' => [
                'common' => [static fn (): string => Par2RenameProgress::encodeRetryIndexesPhp($commonRetryList), static fn (): ?string => Par2RenameProgress::encodeRetryIndexesNative($commonRetryList)],
                'uncommon' => [static fn (): string => Par2RenameProgress::encodeRetryIndexesPhp($uncommonRetryList), static fn (): ?string => Par2RenameProgress::encodeRetryIndexesNative($uncommonRetryList)],
            ],
        ];
    }

    /**
     * @param  array<string, int>  $collectionIds
     * @param  array<array-key, true>  $hashes
     */
    private function makeCollectionHandler(array $collectionIds, array $hashes): CollectionHandler
    {
        $reflection = new ReflectionClass(CollectionHandler::class);
        $handler = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('collectionIds')->setValue($handler, $collectionIds);
        $reflection->getProperty('batchCollectionHashes')->setValue($handler, $hashes);

        return $handler;
    }

    /**
     * @return list<ArchiveCandidate>
     */
    private function makeCandidates(int $count, int $sets): array
    {
        $candidates = [];
        for ($i = 0; $i < $count; $i++) {
            $set = $i % $sets;
            $candidates[] = new ArchiveCandidate(
                title: "show.s01e{$set}.part{$i}.rar",
                messageIds: ["<p{$i}@x>"],
                likelyFirstVolume: $i < $sets,
                sourceIndex: $i,
                setKey: $i % 5 === 4 ? '' : "rar:show.s01e{$set}",
            );
        }

        return $candidates;
    }
}
