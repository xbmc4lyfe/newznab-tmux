<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\FileCompletionStatus;
use App\Enums\ImageAssetProfile;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\Binaries\BinariesConfig;
use App\Support\Configuration\PostProcessingConfigurationData;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 19 Slice 19.3:
 * ProcessingOutcome, PostProcessingConfigurationData, FileCompletionStatus, ImageAssetProfile,
 * and BinariesConfig.
 *
 * Benchmarks run only with NNTMUX_POSTPROCESS_CONFIG_OUTCOMES_ASSETS_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_POSTPROCESS_CONFIG_OUTCOMES_ASSETS_BENCH=1 php artisan test --compact tests/Unit/RustPostProcessConfigOutcomesAssetsParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * Apple M5, PHP 8.5.12 NTS, rustc 1.99.1 release profile, base db5ebbf22.
 *
 * | Method                                     | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |--------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | ProcessingOutcome::isSuccessful            | 0.37x / 0.33x       | 0.41x / 0.41x         | No      | PHP (false)  |
 * | ProcessingOutcome::isDeleted               | 0.40x / 0.36x       | 0.40x / 0.41x         | No      | PHP (false)  |
 * | ProcessingOutcome::resolve                 | 0.33x / 0.32x       | 0.39x / 0.38x         | No      | PHP (false)  |
 * | PostProcessingConfigurationData::defaults  | 0.28x / 0.29x       | 0.29x / 0.29x         | No      | PHP (false)  |
 * | PostProcessingConfigurationData::toArray   | 0.22x / 0.22x       | 0.23x / 0.24x         | No      | PHP (false)  |
 * | FileCompletionStatus::description          | 0.28x / 0.26x       | 0.26x / 0.25x         | No      | PHP (false)  |
 * | FileCompletionStatus::isComplete           | 0.34x / 0.31x       | 0.34x / 0.30x         | No      | PHP (false)  |
 * | FileCompletionStatus::resolve              | 0.42x / 0.37x       | 0.39x / 0.33x         | No      | PHP (false)  |
 * | ImageAssetProfile::maxWidth                | 0.27x / 0.23x       | 0.26x / 0.25x         | No      | PHP (false)  |
 * | ImageAssetProfile::maxHeight               | 0.31x / 0.27x       | 0.28x / 0.25x         | No      | PHP (false)  |
 * | ImageAssetProfile::tryFromName             | 0.44x / 0.40x       | 0.43x / 0.42x         | No      | PHP (false)  |
 * | BinariesConfig::obfuscationMinArticles     | 0.45x / 0.42x       | 0.41x / 0.33x         | No      | PHP (false)  |
 * | BinariesConfig::normalizeChunkSettings     | 0.28x / 0.29x       | 0.29x / 0.30x         | No      | PHP (false)  |
 * | BinariesConfig::defaults                   | 0.24x / 0.25x       | 0.24x / 0.24x         | No      | PHP (false)  |
 *
 * No method qualifies: per-call FFI overhead exceeds these constant-time PHP matches and
 * clamps, so every class keeps PHP as the production default.
 */
class RustPostProcessConfigOutcomesAssetsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        ProcessingOutcome::class,
        PostProcessingConfigurationData::class,
        FileCompletionStatus::class,
        ImageAssetProfile::class,
        BinariesConfig::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('additional', ProcessingOutcome::FFI_CDEF) === null) {
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
            $class::setUseRustFfi(null);
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

    // ---------------------------------------------------------------------
    // 1. ProcessingOutcome
    // ---------------------------------------------------------------------

    public function test_processing_outcome_predicates_and_resolution_parity(): void
    {
        $successful = [];
        $deleted = [];
        foreach (ProcessingOutcome::cases() as $case) {
            $this->assertSame($case->isSuccessfulPhp(), $case->isSuccessfulNative(), $case->name);
            $this->assertSame($case->isDeletedPhp(), $case->isDeletedNative(), $case->name);
            $this->assertSame($case, ProcessingOutcome::resolveNative($case->value));
            $this->assertSame($case, ProcessingOutcome::resolvePhp($case->value));
            if ($case->isSuccessfulNative()) {
                $successful[] = $case;
            }
            if ($case->isDeletedNative()) {
                $deleted[] = $case;
            }
        }
        $this->assertSame([ProcessingOutcome::Completed, ProcessingOutcome::NoUsefulArtifacts, ProcessingOutcome::Passworded], $successful);
        $this->assertSame([ProcessingOutcome::DeletedAfterTimeout, ProcessingOutcome::DeletedBrokenNzb, ProcessingOutcome::DeletedPar2Only], $deleted);

        foreach (['', 'Completed', 'COMPLETED', ' completed', 'completed ', "completed\0", "\0", 'deleted', 'timed_out', 'échec', str_repeat('failed', 400)] as $value) {
            $this->assertSame(ProcessingOutcome::resolvePhp($value), ProcessingOutcome::resolveNative($value), var_export($value, true));
            $this->assertNull(ProcessingOutcome::resolveNative($value));
        }

        ProcessingOutcome::setUseRustFfi(true);
        foreach (ProcessingOutcome::cases() as $case) {
            $this->assertSame($case->isSuccessfulPhp(), $case->isSuccessful());
            $this->assertSame($case->isDeletedPhp(), $case->isDeleted());
            $this->assertSame($case, ProcessingOutcome::resolve($case->value));
        }
        $this->assertNull(ProcessingOutcome::resolve('unknown'));
    }

    // ---------------------------------------------------------------------
    // 2. PostProcessingConfigurationData
    // ---------------------------------------------------------------------

    public function test_post_processing_configuration_defaults_and_to_array_parity(): void
    {
        $this->assertEquals(PostProcessingConfigurationData::defaultsPhp(), PostProcessingConfigurationData::defaultsNative());
        $this->assertSame(
            PostProcessingConfigurationData::defaultsPhp()->toArrayPhp(),
            PostProcessingConfigurationData::defaultsNative()->toArrayNative()
        );
        $this->assertSame(PostProcessingConfigurationData::FIELDS, array_keys(PostProcessingConfigurationData::defaultsPhp()->toArrayPhp()));

        mt_srand(1903);
        $extremes = [PHP_INT_MIN, -1, 0, 1, PHP_INT_MAX];
        for ($round = 0; $round < 200; $round++) {
            $config = self::randomPostProcessingConfiguration($round < 40 ? $extremes[$round % 5] : null, $round);
            $this->assertSame($config->toArrayPhp(), $config->toArrayNative(), "round {$round}");
        }

        PostProcessingConfigurationData::setUseRustFfi(true);
        $this->assertEquals(PostProcessingConfigurationData::defaultsPhp(), PostProcessingConfigurationData::defaults());
        $custom = self::randomPostProcessingConfiguration(null, 7);
        $this->assertSame($custom->toArrayPhp(), $custom->toArray());
    }

    private static function randomPostProcessingConfiguration(?int $fixed, int $round): PostProcessingConfigurationData
    {
        $int = static fn (): int => $fixed ?? mt_rand(-1000, 1000) * (mt_rand(0, 9) === 0 ? 1 << 40 : 1);
        $bool = static fn (): bool => mt_rand(0, 1) === 1;
        $blacklists = ['', '/setup.exe|password.url/i', "nul\0byte", 'ünïcödé|'.str_repeat('x', 300), '0'];

        return new PostProcessingConfigurationData(
            $int(), $int(), $int(), $int(), $int(), $int(), $int(), $int(), $int(), $int(), $int(), $int(), $int(),
            $bool(), $int(), $int(), $blacklists[$round % \count($blacklists)], $bool(), $bool(), $bool(), $bool(),
            $int(), $int(), $int(), $int(), $int(), $int(), $bool(), $bool(),
        );
    }

    // ---------------------------------------------------------------------
    // 3. FileCompletionStatus
    // ---------------------------------------------------------------------

    public function test_file_completion_status_parity(): void
    {
        foreach (FileCompletionStatus::cases() as $case) {
            $this->assertSame($case->descriptionPhp(), $case->descriptionNative());
            $this->assertSame($case->isCompletePhp(), $case->isCompleteNative());
            $this->assertSame($case, FileCompletionStatus::resolveNative($case->value));
        }
        foreach ([PHP_INT_MIN, -1, 2, 255, 256, 257, PHP_INT_MAX] as $value) {
            $this->assertSame(FileCompletionStatus::resolvePhp($value), FileCompletionStatus::resolveNative($value), (string) $value);
            $this->assertNull(FileCompletionStatus::resolveNative($value));
        }

        FileCompletionStatus::setUseRustFfi(true);
        $this->assertSame('Complete', FileCompletionStatus::Complete->description());
        $this->assertSame('Incomplete', FileCompletionStatus::Incomplete->description());
        $this->assertTrue(FileCompletionStatus::Complete->isComplete());
        $this->assertFalse(FileCompletionStatus::Incomplete->isComplete());
        $this->assertSame(FileCompletionStatus::Incomplete, FileCompletionStatus::resolve(0));
    }

    // ---------------------------------------------------------------------
    // 4. ImageAssetProfile
    // ---------------------------------------------------------------------

    public function test_image_asset_profile_bounds_and_resolution_parity(): void
    {
        foreach (ImageAssetProfile::cases() as $case) {
            $this->assertSame($case->maxWidthPhp(), $case->maxWidthNative(), $case->name);
            $this->assertSame($case->maxHeightPhp(), $case->maxHeightNative(), $case->name);
            $this->assertSame($case, ImageAssetProfile::tryFromNameNative($case->name));
            $this->assertSame($case, ImageAssetProfile::tryFromNamePhp($case->name));
        }
        $this->assertNull(ImageAssetProfile::Original->maxWidthNative());
        $this->assertNull(ImageAssetProfile::Original->maxHeightNative());

        foreach (['', 'sample', 'SAMPLE', 'Sample ', "Sample\0", 'Cover', 'metadataCover', str_repeat('Preview', 100)] as $name) {
            $this->assertSame(ImageAssetProfile::tryFromNamePhp($name), ImageAssetProfile::tryFromNameNative($name), var_export($name, true));
            $this->assertNull(ImageAssetProfile::tryFromNameNative($name));
        }

        ImageAssetProfile::setUseRustFfi(true);
        $this->assertSame(1920, ImageAssetProfile::Backdrop->maxWidth());
        $this->assertSame(1024, ImageAssetProfile::Backdrop->maxHeight());
        $this->assertSame(ImageAssetProfile::Preview, ImageAssetProfile::tryFromName('Preview'));
    }

    // ---------------------------------------------------------------------
    // 5. BinariesConfig
    // ---------------------------------------------------------------------

    public function test_binaries_config_normalization_parity(): void
    {
        $this->assertEquals(BinariesConfig::defaultsPhp(), BinariesConfig::defaultsNative());
        $this->assertEquals(new BinariesConfig, BinariesConfig::defaultsNative());

        foreach ([PHP_INT_MIN, PHP_INT_MIN + 1, -16, -1, 0, 1, 7, 8, 9, 16, 1000, PHP_INT_MAX] as $configured) {
            $this->assertSame(
                BinariesConfig::obfuscationMinArticlesPhp($configured),
                BinariesConfig::obfuscationMinArticlesNative($configured),
                (string) $configured
            );
        }

        $edges = [PHP_INT_MIN, -1, 0, 1, 49, 50, 51, 499, 500, 501, 999, 1000, 1001, 1999, 2000, 2001, 19999, 20000, 20001, PHP_INT_MAX];
        mt_srand(1931);
        for ($round = 0; $round < 400; $round++) {
            $raw = [];
            for ($i = 0; $i < 7; $i++) {
                $raw[] = $round < 200 ? $edges[mt_rand(0, \count($edges) - 1)] : mt_rand(-50000, 50000);
            }
            $this->assertSame(
                BinariesConfig::normalizeChunkSettingsPhp(...$raw),
                BinariesConfig::normalizeChunkSettingsNative(...$raw),
                json_encode($raw) ?: ''
            );
        }
        $this->assertSame(BinariesConfig::CHUNK_FIELDS, array_keys(BinariesConfig::normalizeChunkSettingsNative(500, 500, 500, 5000, 1000, 300, 16)));

        BinariesConfig::setUseRustFfi(true);
        $this->assertSame(8, BinariesConfig::obfuscationMinArticles(3));
        $this->assertSame(BinariesConfig::normalizeChunkSettingsPhp(1, 2, 3, 4, 5, 6, 7), BinariesConfig::normalizeChunkSettings(1, 2, 3, 4, 5, 6, 7));
        $this->assertEquals(new BinariesConfig, BinariesConfig::defaults());
    }

    public function test_binaries_config_from_settings_applies_chunk_clamps(): void
    {
        config([
            'nntmux.cbp.header_chunk_size' => 1,
            'nntmux.cbp.sql_chunk_size' => 99999,
            'nntmux.cbp.reconcile_batch_size' => '700',
            'nntmux.cbp.nzb_stream_rows' => 0,
            'nntmux.cbp.reconcile_pages_per_pass' => -4,
            'nntmux.cbp.reconcile_pass_seconds' => 12,
            'nntmux.cbp.per_article_obfuscation_min_articles' => 3,
        ]);
        foreach ([false, true] as $toggle) {
            BinariesConfig::setUseRustFfi($toggle);
            $config = BinariesConfig::fromSettings();
            $this->assertSame(
                [50, 1000, 700, 500, 1, 12, 8],
                [$config->headerChunkSize, $config->sqlChunkSize, $config->reconcileBatchSize, $config->nzbStreamRows, $config->reconcilePagesPerPass, $config->reconcilePassSeconds, $config->perArticleObfuscationMinArticles],
                $toggle ? 'rust' : 'php'
            );
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI Contract & Fallback Verification
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_overflow_alignment_and_buffer_contracts(): void
    {
        $outcomeFfi = RustPipelineLibrary::resolveDomain('additional', ProcessingOutcome::FFI_CDEF);
        $this->assertNotNull($outcomeFfi);
        $this->assertSame(0x100, self::ffiCall($outcomeFfi, 'nntmux_pout_classify', 'completed', 9));
        $this->assertSame(0x206, self::ffiCall($outcomeFfi, 'nntmux_pout_classify', 'deleted-after-timeout', 21));
        $this->assertSame(11, self::ffiCall($outcomeFfi, 'nntmux_pout_classify', 'failed', 6));
        $this->assertSame(-1, self::ffiCall($outcomeFfi, 'nntmux_pout_classify', null, 9));
        $this->assertSame(-1, self::ffiCall($outcomeFfi, 'nntmux_pout_classify', null, 0));
        $this->assertSame(-1, self::ffiCall($outcomeFfi, 'nntmux_pout_classify', 'completed', 8));

        $cfgFfi = RustPipelineLibrary::resolveDomain('additional', PostProcessingConfigurationData::FFI_CDEF);
        $this->assertNotNull($cfgFfi);
        $ints = $cfgFfi->new('int64_t[28]');
        $text = $cfgFfi->new('char[64]');
        $small = $cfgFfi->new('char[4]');
        $rawBytes = $cfgFfi->new('uint8_t[256]');
        $unaligned = $cfgFfi->cast('int64_t*', FFI::addr($rawBytes[1]));
        $this->assertSame(25, self::ffiCall($cfgFfi, 'nntmux_ppcd_defaults', $ints, 28, $text, 64));
        $this->assertSame(-25, self::ffiCall($cfgFfi, 'nntmux_ppcd_defaults', $ints, 28, $small, 4));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_defaults', null, 28, $text, 64));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_defaults', $ints, 27, $text, 64));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_defaults', $ints, 28, null, 64));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_defaults', $unaligned, 28, $text, 64));
        $this->assertSame(28, self::ffiCall($cfgFfi, 'nntmux_ppcd_normalize', $ints, 28, $ints, 28));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_normalize', $ints, 29, $ints, 29));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_normalize', $ints, 28, $ints, 27));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_normalize', null, 28, $ints, 28));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_normalize', $unaligned, 28, $ints, 28));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_ppcd_normalize', $ints, 28, $unaligned, 28));

        $fcsFfi = RustPipelineLibrary::resolveDomain('additional', FileCompletionStatus::FFI_CDEF);
        $this->assertNotNull($fcsFfi);
        $buffer = $fcsFfi->new('char[16]');
        $this->assertSame(-1, self::ffiCall($fcsFfi, 'nntmux_fcs_classify', 2));
        $this->assertSame(1, self::ffiCall($fcsFfi, 'nntmux_fcs_classify', 1));
        $this->assertSame(-10, self::ffiCall($fcsFfi, 'nntmux_fcs_description', 0, $buffer, 3));
        $this->assertSame(-1, self::ffiCall($fcsFfi, 'nntmux_fcs_description', 0, null, 16));
        $this->assertSame(-1, self::ffiCall($fcsFfi, 'nntmux_fcs_description', 9, $buffer, 16));

        $iapFfi = RustPipelineLibrary::resolveDomain('additional', ImageAssetProfile::FFI_CDEF);
        $this->assertNotNull($iapFfi);
        $bounds = $iapFfi->new('int64_t[2]');
        $iapBytes = $iapFfi->new('uint8_t[32]');
        $iapUnaligned = $iapFfi->cast('int64_t*', FFI::addr($iapBytes[1]));
        $this->assertSame(4, self::ffiCall($iapFfi, 'nntmux_iap_bounds', 'Sample', 6, $bounds, 2));
        $this->assertSame(-1, self::ffiCall($iapFfi, 'nntmux_iap_bounds', 'Sample', 6, $bounds, 1));
        $this->assertSame(-1, self::ffiCall($iapFfi, 'nntmux_iap_bounds', null, 6, $bounds, 2));
        $this->assertSame(-1, self::ffiCall($iapFfi, 'nntmux_iap_bounds', 'Sample', 6, null, 2));
        $this->assertSame(-1, self::ffiCall($iapFfi, 'nntmux_iap_bounds', 'Sample', 6, $iapUnaligned, 2));

        $bcfgFfi = RustPipelineLibrary::resolveDomain('additional', BinariesConfig::FFI_CDEF);
        $this->assertNotNull($bcfgFfi);
        $chunks = $bcfgFfi->new('int64_t[16]');
        $bcfgBytes = $bcfgFfi->new('uint8_t[160]');
        $bcfgUnaligned = $bcfgFfi->cast('int64_t*', FFI::addr($bcfgBytes[1]));
        $this->assertSame(0, self::ffiCall($bcfgFfi, 'nntmux_bcfg_obfuscation_min_articles', PHP_INT_MIN));
        $this->assertSame(16, self::ffiCall($bcfgFfi, 'nntmux_bcfg_defaults', $chunks, 16));
        $this->assertSame(-1, self::ffiCall($bcfgFfi, 'nntmux_bcfg_defaults', $chunks, 15));
        $this->assertSame(-1, self::ffiCall($bcfgFfi, 'nntmux_bcfg_defaults', null, 16));
        $this->assertSame(-1, self::ffiCall($bcfgFfi, 'nntmux_bcfg_defaults', $bcfgUnaligned, 16));
        $this->assertSame(7, self::ffiCall($bcfgFfi, 'nntmux_bcfg_normalize', $chunks, 7, $chunks, 7));
        $this->assertSame(-1, self::ffiCall($bcfgFfi, 'nntmux_bcfg_normalize', $chunks, 6, $chunks, 7));
        $this->assertSame(-1, self::ffiCall($bcfgFfi, 'nntmux_bcfg_normalize', $chunks, 7, null, 7));
        $this->assertSame(-1, self::ffiCall($bcfgFfi, 'nntmux_bcfg_normalize', $bcfgUnaligned, 7, $chunks, 7));
        $this->assertSame(-1, self::ffiCall($bcfgFfi, 'nntmux_bcfg_normalize', $chunks, 7, $bcfgUnaligned, 7));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        foreach (ProcessingOutcome::cases() as $case) {
            $this->assertSame($case->isSuccessfulPhp(), $case->isSuccessful());
            $this->assertSame($case->isDeletedPhp(), $case->isDeletedNative());
            $this->assertSame($case, ProcessingOutcome::resolveNative($case->value));
        }
        $this->assertNull(ProcessingOutcome::resolveNative('nope'));

        $this->assertEquals(PostProcessingConfigurationData::defaultsPhp(), PostProcessingConfigurationData::defaultsNative());
        $this->assertSame(
            PostProcessingConfigurationData::defaultsPhp()->toArrayPhp(),
            PostProcessingConfigurationData::defaultsPhp()->toArray()
        );

        $this->assertSame('Complete', FileCompletionStatus::Complete->descriptionNative());
        $this->assertTrue(FileCompletionStatus::Complete->isCompleteNative());
        $this->assertNull(FileCompletionStatus::resolveNative(3));

        $this->assertSame(250, ImageAssetProfile::MetadataCover->maxWidthNative());
        $this->assertNull(ImageAssetProfile::Original->maxHeight());
        $this->assertSame(ImageAssetProfile::Sample, ImageAssetProfile::tryFromNameNative('Sample'));

        $this->assertSame(0, BinariesConfig::obfuscationMinArticlesNative(-3));
        $this->assertSame(BinariesConfig::normalizeChunkSettingsPhp(0, 0, 0, 0, 0, 0, 0), BinariesConfig::normalizeChunkSettingsNative(0, 0, 0, 0, 0, 0, 0));
        $this->assertEquals(new BinariesConfig, BinariesConfig::defaultsNative());
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_POSTPROCESS_CONFIG_OUTCOMES_ASSETS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_POSTPROCESS_CONFIG_OUTCOMES_ASSETS_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $cfgDefault = PostProcessingConfigurationData::defaultsPhp();
        $cfgCustom = new PostProcessingConfigurationData(
            8, 4, 2, 6, 3, 300, 900, 10, 250, 12, 3, 50, 5, true, 6, 30, str_repeat('/(?:setup|keygen)\.exe|', 20).'/i',
            true, true, true, true, 0, PHP_INT_MAX, 512, 1 << 40, 1000, 10, false, true,
        );

        $workloads = [
            'ProcessingOutcome::isSuccessful' => [
                ProcessingOutcome::class,
                static fn () => ProcessingOutcome::Completed->isSuccessful(),
                static fn () => ProcessingOutcome::AwaitingLateArticles->isSuccessful(),
            ],
            'ProcessingOutcome::isDeleted' => [
                ProcessingOutcome::class,
                static fn () => ProcessingOutcome::Completed->isDeleted(),
                static fn () => ProcessingOutcome::DeletedPar2Only->isDeleted(),
            ],
            'ProcessingOutcome::resolve' => [
                ProcessingOutcome::class,
                static fn () => ProcessingOutcome::resolve('completed'),
                static fn () => ProcessingOutcome::resolve('temporary-workspace-unavailable-but-unknown'),
            ],
            'PostProcessingConfigurationData::defaults' => [
                PostProcessingConfigurationData::class,
                static fn () => PostProcessingConfigurationData::defaults()->innerFileBlacklist,
                static fn () => PostProcessingConfigurationData::defaults()->maxSizeToProcessNfo,
            ],
            'PostProcessingConfigurationData::toArray' => [
                PostProcessingConfigurationData::class,
                static fn () => $cfgDefault->toArray(),
                static fn () => $cfgCustom->toArray(),
            ],
            'FileCompletionStatus::description' => [
                FileCompletionStatus::class,
                static fn () => FileCompletionStatus::Complete->description(),
                static fn () => FileCompletionStatus::Incomplete->description(),
            ],
            'FileCompletionStatus::isComplete' => [
                FileCompletionStatus::class,
                static fn () => FileCompletionStatus::Complete->isComplete(),
                static fn () => FileCompletionStatus::Incomplete->isComplete(),
            ],
            'FileCompletionStatus::resolve' => [
                FileCompletionStatus::class,
                static fn () => FileCompletionStatus::resolve(1),
                static fn () => FileCompletionStatus::resolve(PHP_INT_MAX),
            ],
            'ImageAssetProfile::maxWidth' => [
                ImageAssetProfile::class,
                static fn () => ImageAssetProfile::MetadataCover->maxWidth(),
                static fn () => ImageAssetProfile::Original->maxWidth(),
            ],
            'ImageAssetProfile::maxHeight' => [
                ImageAssetProfile::class,
                static fn () => ImageAssetProfile::Sample->maxHeight(),
                static fn () => ImageAssetProfile::Backdrop->maxHeight(),
            ],
            'ImageAssetProfile::tryFromName' => [
                ImageAssetProfile::class,
                static fn () => ImageAssetProfile::tryFromName('Preview'),
                static fn () => ImageAssetProfile::tryFromName('UnknownProfileName'),
            ],
            'BinariesConfig::obfuscationMinArticles' => [
                BinariesConfig::class,
                static fn () => BinariesConfig::obfuscationMinArticles(16),
                static fn () => BinariesConfig::obfuscationMinArticles(PHP_INT_MIN),
            ],
            'BinariesConfig::normalizeChunkSettings' => [
                BinariesConfig::class,
                static fn () => BinariesConfig::normalizeChunkSettings(500, 500, 500, 5000, 1000, 300, 16),
                static fn () => BinariesConfig::normalizeChunkSettings(PHP_INT_MAX, -1, 1, PHP_INT_MIN, 0, -300, 3),
            ],
            'BinariesConfig::defaults' => [
                BinariesConfig::class,
                static fn () => BinariesConfig::defaults()->messageBuffer,
                static fn () => BinariesConfig::defaults()->perArticleObfuscationMinArticles,
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::setUseRustFfi(true);
                    $rustOut = self::invoke($fn);
                    $class::setUseRustFfi(false);
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 1903 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-42s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
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
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [true => [], false => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[$toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[true];
        $php = $times[false];
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
