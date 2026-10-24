<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MediaInfo;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Services\AdditionalProcessing\Enums\ProcessingStage;
use App\Services\AdditionalProcessing\Enums\SourcePosition;
use App\Services\ForkingService;
use App\Services\NfoService;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use stdClass;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 21 Slice 21.3:
 * ForkingService, MediaInfo, MappingConfidence, ProcessingStage, and SourcePosition.
 *
 * Benchmarks run only with NNTMUX_FORKING_MEDIAINFO_ENUM_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_FORKING_MEDIAINFO_ENUM_BENCH=1 php artisan test --compact tests/Unit/RustForkingMediaInfoConfidenceStagePositionParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * Apple M5, PHP 8.5.12 NTS, rustc 1.99.1 release profile, base d5acab6d2.
 *
 * | Method                               | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default     |
 * |--------------------------------------|---------------------|-----------------------|---------|-------------|
 * | ForkingService::maxRetriesFor        | 0.24x / 0.26x       | 0.25x / 0.22x         | No      | PHP (false) |
 * | ForkingService::workTypeIndex        | 0.52x / 0.47x       | 0.69x / 0.55x         | No      | PHP (false) |
 * | ForkingService::timingHeader         | 0.32x / 0.32x       | 0.41x / 0.40x         | No      | PHP (false) |
 * | MediaInfo::normalizeUniqueId         | 0.27x / 0.26x       | 0.29x / 0.31x         | No      | PHP (false) |
 * | MappingConfidence::label             | 0.23x / 0.19x       | 0.24x / 0.24x         | No      | PHP (false) |
 * | MappingConfidence::rank              | 0.37x / 0.37x       | 0.39x / 0.37x         | No      | PHP (false) |
 * | MappingConfidence::tryFromNormalized | 0.56x / 0.56x       | 0.69x / 0.62x         | No      | PHP (false) |
 * | ProcessingStage::label               | 0.29x / 0.31x       | 0.34x / 0.29x         | No      | PHP (false) |
 * | ProcessingStage::rank                | 0.46x / 0.49x       | 0.55x / 0.58x         | No      | PHP (false) |
 * | ProcessingStage::tryFromNormalized   | 0.45x / 0.43x       | 0.62x / 0.56x         | No      | PHP (false) |
 * | SourcePosition::label                | 0.21x / 0.18x       | 0.25x / 0.28x         | No      | PHP (false) |
 * | SourcePosition::rank                 | 0.38x / 0.35x       | 0.41x / 0.40x         | No      | PHP (false) |
 * | SourcePosition::tryFromNormalized    | 0.53x / 0.52x       | 0.61x / 0.60x         | No      | PHP (false) |
 *
 * No method qualifies: per-call FFI marshalling exceeds these constant-time PHP matches,
 * `array_search`, `trim`/`strtolower` and string concatenation, so every class keeps PHP as
 * the production default.
 */
class RustForkingMediaInfoConfidenceStagePositionParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        ForkingService::class,
        MediaInfo::class,
        MappingConfidence::class,
        ProcessingStage::class,
        SourcePosition::class,
    ];

    /** @var list<class-string<MappingConfidence|ProcessingStage|SourcePosition>> */
    private const array ENUMS = [
        MappingConfidence::class,
        ProcessingStage::class,
        SourcePosition::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('additional', ForkingService::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api_round_trips(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::enableRustFfi();
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    // ---------------------------------------------------------------------
    // 1. ForkingService
    // ---------------------------------------------------------------------

    public function test_forking_max_retries_parity(): void
    {
        $inputs = [0, 1, 2, 6, 7, 8, 100, PHP_INT_MAX, PHP_INT_MAX - 1, -1, -2, -100, PHP_INT_MIN];
        mt_srand(2103);
        for ($i = 0; $i < 200; $i++) {
            $inputs[] = mt_rand(-20, 20);
        }
        foreach ($inputs as $input) {
            $expected = $input >= 0 ? max(-($input + 1), -8) : max(NfoService::NFO_UNPROC, -8);
            $this->assertSame((int) $expected, ForkingService::maxRetriesForPhp($input), "php {$input}");
            $this->assertSame(ForkingService::maxRetriesForPhp($input), ForkingService::maxRetriesForNative($input), "native {$input}");
        }

        ForkingService::enableRustFfi();
        $this->assertSame(-4, ForkingService::maxRetriesFor(3));
        $this->assertSame(-1, ForkingService::maxRetriesFor(-3));
        $this->assertSame(-8, ForkingService::maxRetriesFor(PHP_INT_MAX));
    }

    public function test_forking_work_type_index_parity(): void
    {
        foreach (ForkingService::WORK_TYPES as $index => $type) {
            $this->assertSame($index, ForkingService::workTypeIndexPhp($type));
            $this->assertSame($index, ForkingService::workTypeIndexNative($type));
        }
        $unknown = ['', 'Backfill', 'BACKFILL', ' backfill', 'backfill ', "backfill\0", 'postProcess_', 'postprocess_tv', 'fixRelNames', 'fixRelNames_', 'é', str_repeat('releases', 500)];
        foreach ($unknown as $type) {
            $this->assertNull(ForkingService::workTypeIndexPhp($type), var_export($type, true));
            $this->assertNull(ForkingService::workTypeIndexNative($type), var_export($type, true));
        }

        ForkingService::enableRustFfi();
        $this->assertSame(14, ForkingService::workTypeIndex('postProcess_tv'));
        $this->assertNull(ForkingService::workTypeIndex('nope'));
    }

    public function test_forking_timing_header_parity(): void
    {
        $tasks = ['backfill', 'fixRelNames_standard', 'postProcess_tv', '', "with\0nul", 'Café', str_repeat('task', 300)];
        $elapsed = [0, 1, 59, 3600, -1, PHP_INT_MAX, PHP_INT_MIN];
        $dates = ['Sat, 24 Oct 2026 19:06:17 +0200', '', 'Thu, 01 Jan 1970 00:00:00 +0000', str_repeat('d', 2000)];
        foreach ($tasks as $task) {
            foreach ($elapsed as $seconds) {
                foreach ($dates as $date) {
                    $expected = "Multi-processing for {$task} finished in ".$seconds.' seconds at '.$date.'.'.PHP_EOL;
                    $this->assertSame($expected, ForkingService::timingHeaderPhp($task, $seconds, $date));
                    $this->assertSame($expected, ForkingService::timingHeaderNative($task, $seconds, $date));
                }
            }
        }

        ForkingService::enableRustFfi();
        $this->assertSame(
            "Multi-processing for releases finished in 12 seconds at now.\n",
            ForkingService::timingHeader('releases', 12, 'now')
        );
    }

    // ---------------------------------------------------------------------
    // 2. MediaInfo
    // ---------------------------------------------------------------------

    public function test_media_info_unique_id_parity(): void
    {
        $object = new stdClass;
        $inputs = [
            null, 0, '0', '0x0', 1, -1, PHP_INT_MAX, PHP_INT_MIN, '', ' 0', '0 ', '00', '0X0', '0x00', "0\0", '0.0',
            '123456789012345678901234567890', '0x1A2B', 0.0, 0.5, false, true, [], ['0'], $object,
        ];
        foreach ($inputs as $input) {
            $label = var_export($input, true);
            $this->assertSame(MediaInfo::normalizeUniqueIdPhp($input), MediaInfo::normalizeUniqueIdNative($input), $label);
        }
        $this->assertNull(MediaInfo::normalizeUniqueIdNative('0x0'));
        $this->assertNull(MediaInfo::normalizeUniqueIdNative(0));
        $this->assertSame(0.0, MediaInfo::normalizeUniqueIdNative(0.0));
        $this->assertSame($object, MediaInfo::normalizeUniqueIdNative($object));

        MediaInfo::enableRustFfi();
        $this->assertNull(MediaInfo::normalizeUniqueId('0'));
        $this->assertSame('0x1', MediaInfo::normalizeUniqueId('0x1'));
    }

    // ---------------------------------------------------------------------
    // 3. MappingConfidence, ProcessingStage, SourcePosition
    // ---------------------------------------------------------------------

    public function test_enum_label_rank_and_normalized_lookup_parity(): void
    {
        $this->assertCount(2, MappingConfidence::cases());
        $this->assertCount(13, ProcessingStage::cases());
        $this->assertCount(3, SourcePosition::cases());

        foreach (self::ENUMS as $enum) {
            foreach ($enum::cases() as $index => $case) {
                $this->assertSame($case->labelPhp(), $case->labelNative(), $case->name);
                $this->assertSame($case->rankPhp(), $case->rankNative(), $case->name);
                $variants = [
                    $case->value,
                    strtoupper($case->value),
                    str_replace('-', '_', $case->value),
                    " \t\n\r\0\x0B".ucfirst($case->value)."\x0B\0 ",
                ];
                foreach ($variants as $variant) {
                    $this->assertSame($case, $enum::tryFromNormalizedPhp($variant), var_export($variant, true));
                    $this->assertSame($case, $enum::tryFromNormalizedNative($variant), var_export($variant, true));
                }
            }

            $misses = ['', ' ', "\f".$enum::cases()[0]->value, 'x'.$enum::cases()[0]->value, $enum::cases()[0]->value.'-', 'hé', "\xff", str_repeat('mid', 400)];
            foreach ($misses as $miss) {
                $this->assertNull($enum::tryFromNormalizedPhp($miss), var_export($miss, true));
                $this->assertNull($enum::tryFromNormalizedNative($miss), var_export($miss, true));
            }
        }

        mt_srand(2111);
        $alphabet = "abcdefghijklmnopqrstuvwxyz-_ \t\0ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        for ($i = 0; $i < 300; $i++) {
            foreach (self::ENUMS as $enum) {
                $cases = $enum::cases();
                $value = $cases[mt_rand(0, \count($cases) - 1)]->value;
                $chars = str_split($value);
                foreach ($chars as $position => $char) {
                    if (mt_rand(0, 3) === 0) {
                        $chars[$position] = $alphabet[mt_rand(0, \strlen($alphabet) - 1)];
                    }
                }
                $candidate = str_repeat(' ', mt_rand(0, 2)).implode('', $chars).str_repeat("\t", mt_rand(0, 2));
                $this->assertSame($enum::tryFromNormalizedPhp($candidate), $enum::tryFromNormalizedNative($candidate), var_export($candidate, true));
            }
        }

        $this->assertSame('Unique size', MappingConfidence::UniqueSize->labelNative());
        $this->assertGreaterThan(MappingConfidence::UniqueSize->rankNative(), MappingConfidence::Verified->rankNative());
        $this->assertSame('Message-ID selection', ProcessingStage::MessageIdSelection->labelNative());
        $this->assertSame(range(0, 12), array_map(static fn (ProcessingStage $stage): int => $stage->rankNative(), ProcessingStage::cases()));
        $this->assertSame([2, 1, 0], array_map(static fn (SourcePosition $position): int => $position->rankNative(), SourcePosition::cases()));

        foreach (self::ENUMS as $enum) {
            $enum::enableRustFfi();
            foreach ($enum::cases() as $case) {
                $this->assertSame($case->labelPhp(), $case->label());
                $this->assertSame($case->rankPhp(), $case->rank());
                $this->assertSame($case, $enum::tryFromNormalized(strtoupper($case->value)));
            }
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI contracts and fallback
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_overflow_alignment_and_buffer_contracts(): void
    {
        $fork = RustPipelineLibrary::resolveDomain('additional', ForkingService::FFI_CDEF);
        $this->assertNotNull($fork);
        $out = $fork->new('int64_t[1]');
        $bytes = $fork->new('uint8_t[32]');
        $unaligned = $fork->cast('int64_t*', FFI::addr($bytes[1]));
        $this->assertSame(0, self::ffiCall($fork, 'nntmux_fork_max_retries', 2, $out));
        $this->assertSame(-3, $out[0]);
        $this->assertSame(-1, self::ffiCall($fork, 'nntmux_fork_max_retries', 2, null));
        $this->assertSame(-1, self::ffiCall($fork, 'nntmux_fork_max_retries', 2, $unaligned));
        $this->assertSame(1, self::ffiCall($fork, 'nntmux_fork_work_type', 'binaries', 8));
        $this->assertSame(-2, self::ffiCall($fork, 'nntmux_fork_work_type', 'binaries', 7));
        $this->assertSame(-2, self::ffiCall($fork, 'nntmux_fork_work_type', null, 0));
        $this->assertSame(-1, self::ffiCall($fork, 'nntmux_fork_work_type', null, 8));

        $text = $fork->new('char[128]');
        $small = $fork->new('char[8]');
        FFI::memset($small, 0x2A, 8);
        $expected = "Multi-processing for tv finished in 5 seconds at now.\n";
        $this->assertSame(\strlen($expected), self::ffiCall($fork, 'nntmux_fork_timing_header', 'tv', 2, 5, 'now', 3, $text, 128));
        $this->assertSame($expected, FFI::string($text, \strlen($expected)));
        $this->assertSame(-\strlen($expected), self::ffiCall($fork, 'nntmux_fork_timing_header', 'tv', 2, 5, 'now', 3, $small, 8));
        $this->assertSame(str_repeat('*', 8), FFI::string($small, 8));
        $this->assertSame(-1, self::ffiCall($fork, 'nntmux_fork_timing_header', null, 2, 5, 'now', 3, $text, 128));
        $this->assertSame(-1, self::ffiCall($fork, 'nntmux_fork_timing_header', 'tv', 2, 5, null, 3, $text, 128));
        $this->assertSame(-1, self::ffiCall($fork, 'nntmux_fork_timing_header', 'tv', 2, 5, 'now', 3, null, 128));

        $media = RustPipelineLibrary::resolveDomain('additional', MediaInfo::FFI_CDEF);
        $this->assertNotNull($media);
        $this->assertSame(1, self::ffiCall($media, 'nntmux_mi_unique_id_placeholder', 0, 0, null, 0));
        $this->assertSame(1, self::ffiCall($media, 'nntmux_mi_unique_id_placeholder', 1, 0, null, 0));
        $this->assertSame(0, self::ffiCall($media, 'nntmux_mi_unique_id_placeholder', 1, 7, null, 0));
        $this->assertSame(1, self::ffiCall($media, 'nntmux_mi_unique_id_placeholder', 2, 0, '0x0', 3));
        $this->assertSame(0, self::ffiCall($media, 'nntmux_mi_unique_id_placeholder', 2, 0, '0x0', 2));
        $this->assertSame(0, self::ffiCall($media, 'nntmux_mi_unique_id_placeholder', 3, 0, null, 0));
        $this->assertSame(-1, self::ffiCall($media, 'nntmux_mi_unique_id_placeholder', 9, 0, null, 0));
        $this->assertSame(-1, self::ffiCall($media, 'nntmux_mi_unique_id_placeholder', 2, 0, null, 3));

        $enum = RustPipelineLibrary::resolveDomain('additional', SourcePosition::FFI_CDEF);
        $this->assertNotNull($enum);
        $label = $enum->new('char[64]');
        $tiny = $enum->new('char[2]');
        FFI::memset($tiny, 0x2A, 2);
        $this->assertSame(2, self::ffiCall($enum, 'nntmux_aenum_resolve', 2, ' MID ', 5));
        $this->assertSame(-2, self::ffiCall($enum, 'nntmux_aenum_resolve', 2, 'midx', 4));
        $this->assertSame(-1, self::ffiCall($enum, 'nntmux_aenum_resolve', 3, 'mid', 3));
        $this->assertSame(-1, self::ffiCall($enum, 'nntmux_aenum_resolve', 2, null, 3));
        $this->assertSame(8, self::ffiCall($enum, 'nntmux_aenum_label', 2, 2, $label, 64));
        $this->assertSame('Mid-file', FFI::string($label, 8));
        $this->assertSame(-8, self::ffiCall($enum, 'nntmux_aenum_label', 2, 2, $tiny, 2));
        $this->assertSame('**', FFI::string($tiny, 2));
        $this->assertSame(-1, self::ffiCall($enum, 'nntmux_aenum_label', 2, 3, $label, 64));
        $this->assertSame(-1, self::ffiCall($enum, 'nntmux_aenum_label', 2, -1, $label, 64));
        $this->assertSame(-1, self::ffiCall($enum, 'nntmux_aenum_label', 2, 0, null, 64));
        $this->assertSame(12, self::ffiCall($enum, 'nntmux_aenum_rank', 1, 12));
        $this->assertSame(-1, self::ffiCall($enum, 'nntmux_aenum_rank', 1, 13));
        $this->assertSame(-1, self::ffiCall($enum, 'nntmux_aenum_rank', 7, 0));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertSame(-4, ForkingService::maxRetriesForNative(3));
        $this->assertSame(3, ForkingService::workTypeIndex('fixRelNames_predbft'));
        $this->assertNull(ForkingService::workTypeIndexNative('nope'));
        $this->assertSame("Multi-processing for a finished in 1 seconds at b.\n", ForkingService::timingHeader('a', 1, 'b'));
        $this->assertNull(MediaInfo::normalizeUniqueIdNative('0x0'));
        $this->assertSame('abc', MediaInfo::normalizeUniqueId('abc'));

        foreach (self::ENUMS as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertSame($case->labelPhp(), $case->labelNative());
                $this->assertSame($case->rankPhp(), $case->rank());
                $this->assertSame($case, $enum::tryFromNormalizedNative(' '.strtoupper($case->value)));
            }
            $this->assertNull($enum::tryFromNormalized('nope'));
        }
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_FORKING_MEDIAINFO_ENUM_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_FORKING_MEDIAINFO_ENUM_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $longTask = str_repeat('postProcess_', 40);
        $longDate = str_repeat('Sat, 24 Oct 2026 19:06:17 +0200 ', 30);
        $longUniqueId = str_repeat('0x1F2E3D4C5B6A', 60);
        $longStage = '  '.strtoupper(str_replace('-', '_', ProcessingStage::WorkspaceCleanup->value)).str_repeat(' ', 64);

        $workloads = [
            'ForkingService::maxRetriesFor' => [
                ForkingService::class,
                static fn () => ForkingService::maxRetriesFor(3),
                static fn () => ForkingService::maxRetriesFor(PHP_INT_MAX),
            ],
            'ForkingService::workTypeIndex' => [
                ForkingService::class,
                static fn () => ForkingService::workTypeIndex('postProcess_add'),
                static fn () => ForkingService::workTypeIndex('update_per_group_unknown_type'),
            ],
            'ForkingService::timingHeader' => [
                ForkingService::class,
                static fn () => ForkingService::timingHeader('postProcess_tv', 12, 'Sat, 24 Oct 2026 19:06:17 +0200'),
                static fn () => ForkingService::timingHeader($longTask, PHP_INT_MIN, $longDate),
            ],
            'MediaInfo::normalizeUniqueId' => [
                MediaInfo::class,
                static fn () => MediaInfo::normalizeUniqueId('0x0'),
                static fn () => MediaInfo::normalizeUniqueId($longUniqueId),
            ],
            'MappingConfidence::label' => [
                MappingConfidence::class,
                static fn () => MappingConfidence::Verified->label(),
                static fn () => MappingConfidence::UniqueSize->label(),
            ],
            'MappingConfidence::rank' => [
                MappingConfidence::class,
                static fn () => MappingConfidence::Verified->rank(),
                static fn () => MappingConfidence::UniqueSize->rank(),
            ],
            'MappingConfidence::tryFromNormalized' => [
                MappingConfidence::class,
                static fn () => MappingConfidence::tryFromNormalized('verified'),
                static fn () => MappingConfidence::tryFromNormalized(" \tUNIQUE_SIZE\n"),
            ],
            'ProcessingStage::label' => [
                ProcessingStage::class,
                static fn () => ProcessingStage::DirectDownloads->label(),
                static fn () => ProcessingStage::WorkspaceCleanup->label(),
            ],
            'ProcessingStage::rank' => [
                ProcessingStage::class,
                static fn () => ProcessingStage::DirectDownloads->rank(),
                static fn () => ProcessingStage::WorkspaceCleanup->rank(),
            ],
            'ProcessingStage::tryFromNormalized' => [
                ProcessingStage::class,
                static fn () => ProcessingStage::tryFromNormalized('media-sources'),
                static fn () => ProcessingStage::tryFromNormalized($longStage),
            ],
            'SourcePosition::label' => [
                SourcePosition::class,
                static fn () => SourcePosition::Complete->label(),
                static fn () => SourcePosition::Mid->label(),
            ],
            'SourcePosition::rank' => [
                SourcePosition::class,
                static fn () => SourcePosition::Head->rank(),
                static fn () => SourcePosition::Mid->rank(),
            ],
            'SourcePosition::tryFromNormalized' => [
                SourcePosition::class,
                static fn () => SourcePosition::tryFromNormalized('head'),
                static fn () => SourcePosition::tryFromNormalized("\0\x0B COMPLETE \r\n"),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::enableRustFfi();
                    $rustOut = self::invoke($fn);
                    $class::disableRustFfi();
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2103 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::resetRustFfi();
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-38s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-11s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $toggle) {
            $toggle ? $class::enableRustFfi() : $class::disableRustFfi();
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $toggle ? $class::enableRustFfi() : $class::disableRustFfi();
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[1];
        $php = $times[0];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
