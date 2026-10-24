<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AdditionalProcessing\DTO\MappedNzbFile;
use App\Services\AdditionalProcessing\DTO\MediaSourcePlan;
use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Services\ArchiveProcessingService;
use App\Support\Configuration\IngestionConfigurationData;
use App\Support\Data\ImageProcessingResult;
use App\Support\RustPipelineLibrary;
use Closure;
use dariusiii\rarinfo\ArchiveInfo;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;

/**
 * Wave 18 parity and paired benchmark suite for `ArchiveProcessingService`,
 * `MediaSourcePlan`, `DownloadKind`, `ImageProcessingResult` and
 * `IngestionConfigurationData` (`rust/pipeline-ffi/src/additional.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   NNTMUX_ARCHIVE_MEDIA_PLAN_INGESTION_CONFIG_BENCH=1 php artisan test --compact tests/Unit/RustArchiveMediaPlanIngestionConfigParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon); nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x speedup on all 4 metrics
 * (common mean, common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Environment: Apple M5, macOS (Darwin 27.2.0), PHP 8.5.12 NTS CLI, rustc 1.99.1,
 * `cargo build --release`; base commit 5e1a7204f.
 *
 * Paired speedup results (`PHP latency / Rust FFI latency`, mean/p99 per rep, 3 reps):
 * - `normalizeAnalysis`: common 0.36x/0.43x, 0.36x/0.43x, 0.35x/0.50x | uncommon 0.33x/0.34x, 0.33x/0.34x, 0.34x/0.34x
 * - `realSizeOf`:        common 0.16x/0.22x, 0.15x/0.22x, 0.16x/0.22x | uncommon 0.01x/0.01x, 0.01x/0.01x, 0.01x/0.01x
 * - `isPreviewSource`:   common 0.36x/0.66x, 0.35x/0.66x, 0.34x/0.50x | uncommon 0.40x/0.67x, 0.38x/0.50x, 0.40x/0.51x
 * - `imageSuccess`:      common 0.51x/0.60x, 0.50x/0.55x, 0.50x/0.60x | uncommon 0.58x/0.63x, 0.56x/0.58x, 0.57x/0.54x
 * - `imageFailure`:      common 0.52x/0.50x, 0.51x/0.56x, 0.52x/0.56x | uncommon 0.55x/0.50x, 0.55x/0.60x, 0.54x/0.56x
 * - `ingestionDefaults`: common 0.44x/0.43x, 0.44x/0.43x, 0.45x/0.42x | uncommon 0.30x/0.28x, 0.29x/0.31x, 0.30x/0.29x
 * - `ingestionToArray`:  common 0.22x/0.21x, 0.21x/0.10x, 0.21x/0.21x | uncommon 0.22x/0.08x, 0.21x/0.21x, 0.21x/0.22x
 *
 * Numbers above are from the final-code run; an earlier run agreed (0.01x-0.69x). The
 * PHP baselines are 40-800 ns hash lookups, enum identity checks, constructor calls and
 * `get_object_vars()`; the FFI call, argument packing and CData reads cost more than the
 * computation. `realSizeOf` must also pack the whole map per call (0.01x for 120
 * entries). No method reaches 1.5x on any metric, so per `.ai/rules/rust-performance.md`
 * all five classes keep PHP as the production default (`$useRustFfi = false`) while the
 * `*Native` and `*Php` paths stay covered by these parity tests.
 */
final class RustArchiveMediaPlanIngestionConfigParityTest extends TestCase
{
    private const string FULL_CDEF = ArchiveProcessingService::FFI_CDEF."\n"
        .MediaSourcePlan::FFI_CDEF."\n"
        .DownloadKind::FFI_CDEF."\n"
        .ImageProcessingResult::FFI_CDEF."\n"
        .IngestionConfigurationData::FFI_CDEF;

    protected function tearDown(): void
    {
        ArchiveProcessingService::setUseRustFfi(false);
        MediaSourcePlan::setUseRustFfi(false);
        DownloadKind::setUseRustFfi(false);
        ImageProcessingResult::setUseRustFfi(false);
        IngestionConfigurationData::setUseRustFfi(false);
        (new ReflectionProperty(ArchiveProcessingService::class, 'ffi'))->setValue(null, null);
        MediaSourcePlan::additionalFfi(true);
        DownloadKind::additionalFfi(true);
        ImageProcessingResult::additionalFfi(true);
        IngestionConfigurationData::additionalFfi(true);
        parent::tearDown();
    }

    private static function ffi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FULL_CDEF);
        self::assertNotNull($ffi, 'Expected libnntmux_pipeline additional domain FFI to be available.');

        return $ffi;
    }

    private static function plan(int ...$indexSizePairs): MediaSourcePlan
    {
        $mapped = [];
        for ($i = 0, $n = count($indexSizePairs); $i < $n; $i += 2) {
            $index = $indexSizePairs[$i];
            $mapped[$index] = new MappedNzbFile($index, new Par2FileEntry("file{$index}.mkv", $indexSizePairs[$i + 1]), MappingConfidence::Verified);
        }

        return new MediaSourcePlan($mapped, 768000);
    }

    public function test_toggles_default_to_php(): void
    {
        $this->assertFalse(ArchiveProcessingService::$useRustFfi);
        $this->assertFalse(MediaSourcePlan::usesRustFfi());
        $this->assertFalse(DownloadKind::usesRustFfi());
        $this->assertFalse(ImageProcessingResult::usesRustFfi());
        $this->assertFalse(IngestionConfigurationData::usesRustFfi());

        // Each class resolves its own header block, so parity runs exercise Rust.
        self::ffi();
        $this->assertNotNull(ArchiveProcessingService::additionalFfi());
        $this->assertNotNull(MediaSourcePlan::additionalFfi());
        $this->assertNotNull(DownloadKind::additionalFfi());
        $this->assertNotNull(ImageProcessingResult::additionalFfi());
        $this->assertNotNull(IngestionConfigurationData::additionalFfi());
    }

    public function test_normalize_analysis_matches_php(): void
    {
        self::ffi();
        $errors = ['', '0', 'Bad data', "nul\0byte", ' ', '00', null, false, 0];
        $summaries = [
            null, [], 'summary', ['main_type' => 1], ['is_encrypted' => 0], ['is_encrypted' => 1], ['is_encrypted' => -7],
            ['is_encrypted' => '1'], ['is_encrypted' => '0'], ['is_encrypted' => 'abc'], ['is_encrypted' => '2abc'],
            ['is_encrypted' => true], ['is_encrypted' => false], ['is_encrypted' => null], ['is_encrypted' => 0.4],
            ['is_encrypted' => 1.5], ['is_encrypted' => []], ['is_encrypted' => ['x']], ['is_encrypted' => PHP_INT_MIN],
        ];
        $cases = 0;
        foreach ([true, false] as $ok) {
            foreach ($errors as $error) {
                foreach ([true, false] as $prop) {
                    foreach ($summaries as $summary) {
                        $expected = ArchiveProcessingService::normalizeAnalysisPhp($ok, $error, $prop, $summary);
                        $this->assertSame($expected, ArchiveProcessingService::normalizeAnalysisNative($ok, $error, $prop, $summary), json_encode([$ok, $error, $prop, $summary]) ?: '');
                        ArchiveProcessingService::setUseRustFfi(true);
                        $this->assertSame($expected, ArchiveProcessingService::normalizeAnalysis($ok, $error, $prop, $summary));
                        ArchiveProcessingService::setUseRustFfi(false);
                        $cases++;
                    }
                }
            }
        }
        $this->assertSame(2 * count($errors) * 2 * count($summaries), $cases);
    }

    public function test_analyze_matches_php_through_archive_info(): void
    {
        self::ffi();
        $scenarios = [
            [true, '', ['main_type' => 1, 'is_encrypted' => 0], false],
            [true, '', ['main_type' => 1, 'is_encrypted' => 1], false],
            [true, '', ['main_type' => 1], true],
            [true, 'Byte range (0-9) is invalid', [], false],
            [false, 'Bad data', [], false],
            [false, '0', [], false],
            [false, '', [], false],
        ];
        $last = [];
        foreach ($scenarios as [$ok, $error, $summary, $encrypted]) {
            $results = [];
            foreach ([false, true] as $native) {
                ArchiveProcessingService::setUseRustFfi($native);
                $calls = 0;
                $archive = self::archiveStub($ok, $error, $summary, $encrypted, static function () use (&$calls): void {
                    $calls++;
                });
                $results[] = (new ArchiveProcessingService($archive))->analyze('BINARY');
                $this->assertSame($ok && $error === '' ? 1 : 0, $calls);
            }
            $this->assertSame($results[0], $results[1]);
            $last = $results[1];
        }
        $this->assertSame(['ok' => false, 'error' => 'Unknown error', 'summary' => null, 'is_encrypted' => false], $last);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function archiveStub(bool $ok, string $error, array $summary, bool $encrypted, Closure $onSummary): ArchiveInfo
    {
        return new class($ok, $error, $summary, $encrypted, $onSummary) extends ArchiveInfo
        {
            /**
             * @param  array<string, mixed>  $summary
             */
            public function __construct(
                private readonly bool $ok,
                private readonly string $nextError,
                private readonly array $summary,
                public bool $isEncrypted,
                private readonly Closure $onSummary,
            ) {
                parent::__construct();
            }

            /**
             * @param  array<int, int>|null  $range
             */
            public function setData(string $data, bool $isFragment = false, ?array $range = null): bool
            {
                $this->error = $this->nextError;

                return $this->ok;
            }

            /**
             * @return array<string, mixed>
             */
            public function getSummary($full = false): array
            {
                ($this->onSummary)();

                return $this->summary;
            }
        };
    }

    public function test_real_size_of_matches_php(): void
    {
        self::ffi();
        $plans = [
            new MediaSourcePlan,
            self::plan(0, 1),
            self::plan(3, 734003200, 7, 52428800, 12, 1),
            self::plan(PHP_INT_MAX, PHP_INT_MAX, -1, 99, PHP_INT_MIN, 5),
            self::plan(4, 9),
        ];
        $indices = [0, 1, 2, 3, 4, 7, 12, 13, -1, PHP_INT_MAX, PHP_INT_MIN];
        foreach ($plans as $p => $plan) {
            foreach ($indices as $index) {
                $expected = $plan->realSizeOfPhp($index);
                $this->assertSame($expected, $plan->realSizeOfNative($index), "plan {$p} index {$index}");
                MediaSourcePlan::setUseRustFfi(true);
                $this->assertSame($expected, $plan->realSizeOf($index));
                MediaSourcePlan::setUseRustFfi(false);
            }
        }
        $this->assertSame(PHP_INT_MAX, self::plan(PHP_INT_MAX, PHP_INT_MAX)->realSizeOfNative(PHP_INT_MAX));
        $this->assertSame(9, $plans[4]->realSizeOfNative(4));
    }

    public function test_download_kind_preview_matches_php(): void
    {
        self::ffi();
        $preview = [];
        foreach (DownloadKind::cases() as $kind) {
            $this->assertSame($kind->isPreviewSourcePhp(), $kind->isPreviewSourceNative(), $kind->value);
            DownloadKind::setUseRustFfi(true);
            $this->assertSame($kind->isPreviewSourcePhp(), $kind->isPreviewSource());
            DownloadKind::setUseRustFfi(false);
            if ($kind->isPreviewSourcePhp()) {
                $preview[] = $kind;
            }
        }
        $this->assertSame([DownloadKind::Jpg, DownloadKind::Par2Index, DownloadKind::MediaSource], $preview);
    }

    public function test_image_processing_result_matches_php(): void
    {
        self::ffi();
        $successes = [
            ['/covers/preview/guid_thumb.webp', 320, 240, 'image/webp'],
            ['', 0, 0, ''],
            ["/tmp/\0nul.jpg", PHP_INT_MAX, PHP_INT_MIN, 'image/jpeg'],
            ['/x', -1, -2, 'application/octet-stream'],
        ];
        foreach ($successes as [$path, $width, $height, $mime]) {
            $expected = ImageProcessingResult::successPhp($path, $width, $height, $mime);
            $this->assertEquals($expected, ImageProcessingResult::successNative($path, $width, $height, $mime));
            $this->assertSame($expected->toArray(), ImageProcessingResult::successNative($path, $width, $height, $mime)->toArray());
            ImageProcessingResult::setUseRustFfi(true);
            $this->assertSame($expected->toArray(), ImageProcessingResult::success($path, $width, $height, $mime)->toArray());
            ImageProcessingResult::setUseRustFfi(false);
        }
        foreach (['Unable to decode or process the image.', '', "multi\nline \0 reason", str_repeat('r', 4096)] as $reason) {
            $expected = ImageProcessingResult::failurePhp($reason);
            $this->assertSame($expected->toArray(), ImageProcessingResult::failureNative($reason)->toArray());
            ImageProcessingResult::setUseRustFfi(true);
            $this->assertSame($expected->toArray(), ImageProcessingResult::failure($reason)->toArray());
            ImageProcessingResult::setUseRustFfi(false);
        }
        $this->assertSame(
            ['success' => false, 'path' => null, 'width' => null, 'height' => null, 'mimeType' => null, 'failureReason' => 'r'],
            ImageProcessingResult::failureNative('r')->toArray(),
        );
    }

    public function test_ingestion_configuration_matches_php(): void
    {
        self::ffi();
        $defaults = IngestionConfigurationData::defaultsPhp();
        $this->assertEquals($defaults, IngestionConfigurationData::defaultsNative());
        $this->assertSame($defaults->toArrayPhp(), IngestionConfigurationData::defaultsNative()->toArrayPhp());
        $this->assertSame($defaults->toArrayPhp(), $defaults->toArrayNative());
        $this->assertSame(IngestionConfigurationData::FIELDS, array_keys($defaults->toArrayPhp()));

        $random = new Randomizer(new Mt19937(20261024));
        $template = $defaults->toArrayPhp();
        for ($i = 0; $i < 200; $i++) {
            $values = [];
            foreach ($template as $name => $value) {
                $values[$name] = match (true) {
                    is_bool($value) => $random->getInt(0, 1) === 1,
                    is_string($value) => $i % 3 === 0 ? '' : sprintf('%04d-%02d-%02d', $random->getInt(1970, 2099), $random->getInt(1, 12), $random->getInt(1, 28)),
                    default => match ($i % 4) {
                        0 => PHP_INT_MAX - $random->getInt(0, 3),
                        1 => PHP_INT_MIN + $random->getInt(0, 3),
                        default => $random->getInt(-1000000, 1000000),
                    },
                };
            }
            $config = new IngestionConfigurationData(...$values);
            $this->assertSame($config->toArrayPhp(), $config->toArrayNative(), "case {$i}");
            IngestionConfigurationData::setUseRustFfi(true);
            $this->assertSame($values, $config->toArray());
            $this->assertEquals(new IngestionConfigurationData(...$config->toArray()), $config);
            $this->assertEquals($defaults, IngestionConfigurationData::defaults());
            IngestionConfigurationData::setUseRustFfi(false);
        }
    }

    public function test_native_paths_fall_back_to_php_without_library(): void
    {
        (new ReflectionProperty(ArchiveProcessingService::class, 'ffi'))->setValue(null, false);
        MediaSourcePlan::additionalFfi(true, false);
        DownloadKind::additionalFfi(true, false);
        ImageProcessingResult::additionalFfi(true, false);
        IngestionConfigurationData::additionalFfi(true, false);

        $this->assertNull(ArchiveProcessingService::additionalFfi());
        $this->assertNull(MediaSourcePlan::additionalFfi());
        $this->assertNull(DownloadKind::additionalFfi());
        $this->assertNull(ImageProcessingResult::additionalFfi());
        $this->assertNull(IngestionConfigurationData::additionalFfi());

        $this->assertSame(
            ['ok' => true, 'error' => null, 'summary' => ['is_encrypted' => 1], 'is_encrypted' => true],
            ArchiveProcessingService::normalizeAnalysisNative(true, '', false, ['is_encrypted' => 1]),
        );
        $this->assertSame(['ok' => false, 'error' => 'Unknown error', 'summary' => null, 'is_encrypted' => false], ArchiveProcessingService::normalizeAnalysisNative(false, '0', false, null));
        $this->assertSame(42, self::plan(5, 42)->realSizeOfNative(5));
        $this->assertNull(self::plan(5, 42)->realSizeOfNative(6));
        $this->assertTrue(DownloadKind::Jpg->isPreviewSourceNative());
        $this->assertFalse(DownloadKind::Audio->isPreviewSourceNative());
        $this->assertSame(ImageProcessingResult::successPhp('/p', 1, 2, 'm')->toArray(), ImageProcessingResult::successNative('/p', 1, 2, 'm')->toArray());
        $this->assertSame(ImageProcessingResult::failurePhp('no')->toArray(), ImageProcessingResult::failureNative('no')->toArray());
        $defaults = IngestionConfigurationData::defaultsPhp();
        $this->assertEquals($defaults, IngestionConfigurationData::defaultsNative());
        $this->assertSame($defaults->toArrayPhp(), $defaults->toArrayNative());

        // Enabling Rust without a library keeps PHP results.
        ArchiveProcessingService::setUseRustFfi(true);
        MediaSourcePlan::setUseRustFfi(true);
        DownloadKind::setUseRustFfi(true);
        ImageProcessingResult::setUseRustFfi(true);
        IngestionConfigurationData::setUseRustFfi(true);
        $this->assertSame(['ok' => false, 'error' => 'Bad', 'summary' => null, 'is_encrypted' => false], ArchiveProcessingService::normalizeAnalysis(false, 'Bad', false, null));
        $this->assertSame(42, self::plan(5, 42)->realSizeOf(5));
        $this->assertTrue(DownloadKind::Par2Index->isPreviewSource());
        $this->assertNull(ImageProcessingResult::failure('x')->width);
        $this->assertSame('2012-06-24', IngestionConfigurationData::defaults()->toArray()['safeBackfillDate']);
    }

    public function test_direct_c_abi_null_overflow_alignment_and_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $bytes = $ffi->new('char[512]');
        $misaligned = $ffi->cast('int64_t *', FFI::addr($bytes[1]));

        // Analysis: null with a non-zero length and lengths above isize::MAX (-1 converts
        // to SIZE_MAX) are rejected; a null empty error is valid.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_aps_analysis_code(1, null, 3, 0, 0, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_aps_analysis_code(1, 'x', -1, 0, 0, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_aps_analysis_code(1, null, 0, 0, 1, PHP_INT_MIN));
        // @phpstan-ignore method.notFound
        $this->assertSame(3, $ffi->nntmux_aps_analysis_code(0, '0', 1, 1, 1, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(2, $ffi->nntmux_aps_analysis_code(1, '0', 1, 0, 0, 0));

        $size = $ffi->new('int64_t[1]');
        $size[0] = 777;
        $packed = pack('P*', 3, 100, -1, PHP_INT_MAX);
        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_msp_real_size_of($packed, 32, -1, $size));
        $this->assertSame(PHP_INT_MAX, $size[0]);
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_msp_real_size_of($packed, 32, 4, $size));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_msp_real_size_of($packed, 31, 3, $size));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_msp_real_size_of($packed, -1, 3, $size));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_msp_real_size_of(null, 16, 3, $size));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_msp_real_size_of($packed, 32, 3, null));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_msp_real_size_of($packed, 32, 3, $misaligned));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_msp_real_size_of(null, 0, 3, $size));
        $this->assertSame(PHP_INT_MAX, $size[0]);

        // @phpstan-ignore method.notFound
        $this->assertSame(1, $ffi->nntmux_dk_is_preview_source('media-source', 12));
        // @phpstan-ignore method.notFound
        $this->assertSame(0, $ffi->nntmux_dk_is_preview_source('audio', 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_dk_is_preview_source('Jpg', 3));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_dk_is_preview_source(null, 3));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_dk_is_preview_source('jpg', -1));

        $pair = $ffi->new('int64_t[2]');
        // @phpstan-ignore method.notFound
        $this->assertSame(31, $ffi->nntmux_ipr_build(1, PHP_INT_MIN, PHP_INT_MAX, $pair, 2));
        $this->assertSame([PHP_INT_MIN, PHP_INT_MAX], [$pair[0], $pair[1]]);
        // @phpstan-ignore method.notFound
        $this->assertSame(32, $ffi->nntmux_ipr_build(0, 5, 5, $pair, 2));
        $this->assertSame([0, 0], [$pair[0], $pair[1]]);
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_ipr_build(2, 5, 5, $pair, 2));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_ipr_build(1, 5, 5, $pair, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_ipr_build(1, 5, 5, null, 2));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_ipr_build(1, 5, 5, $misaligned, 2));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_ipr_build(1, 5, 5, $pair, -1));

        $values = $ffi->new('int64_t[35]');
        $date = $ffi->new('char[32]');
        $small = $ffi->new('char[4]');
        // @phpstan-ignore method.notFound
        $this->assertSame(10, $ffi->nntmux_icd_defaults($values, 35, $date, 32));
        $this->assertSame('2012-06-24', FFI::string($date, 10));
        $this->assertSame([1, 1, 1, 2, 48], [$values[0], $values[1], $values[2], $values[3], $values[4]]);
        // @phpstan-ignore method.notFound
        $this->assertSame(-10, $ffi->nntmux_icd_defaults($values, 35, $small, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_defaults($values, 34, $date, 32));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_defaults(null, 35, $date, 32));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_defaults($misaligned, 35, $date, 32));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_defaults($values, 35, null, 32));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_defaults($values, -1, $date, 32));

        for ($i = 0; $i < 35; $i++) {
            $values[$i] = PHP_INT_MIN + $i;
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_normalize($values, 34, $values, 35));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_normalize($values, 35, $values, 34));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_normalize(null, 35, $values, 35));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_normalize($values, 35, null, 35));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_normalize($misaligned, 35, $values, 35));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_icd_normalize($values, 35, $misaligned, 35));
        $this->assertSame(PHP_INT_MIN, $values[0]);
        // In-place normalization collapses boolean slots to 0/1.
        // @phpstan-ignore method.notFound
        $this->assertSame(35, $ffi->nntmux_icd_normalize($values, 35, $values, 35));
        $this->assertSame([PHP_INT_MIN, 1, PHP_INT_MIN + 8, 1, 1, 1], [$values[0], $values[7], $values[8], $values[23], $values[24], $values[31]]);
    }

    /**
     * Run with:
     * NNTMUX_ARCHIVE_MEDIA_PLAN_INGESTION_CONFIG_BENCH=1 php artisan test --compact tests/Unit/RustArchiveMediaPlanIngestionConfigParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_ARCHIVE_MEDIA_PLAN_INGESTION_CONFIG_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_ARCHIVE_MEDIA_PLAN_INGESTION_CONFIG_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $summary = ['main_type' => 1, 'main_type_name' => 'RAR', 'file_size' => 52428800, 'data_size' => 52428800, 'use_range' => 'all', 'is_encrypted' => 0, 'file_count' => 12];
        $longError = 'Byte range (0-'.str_repeat('9', 18).') is invalid; '.str_repeat('volume header checksum mismatch ', 12);
        $commonPairs = [];
        for ($i = 0; $i < 4; $i++) {
            $commonPairs[] = $i * 3;
            $commonPairs[] = 734003200 + $i;
        }
        $uncommonPairs = [];
        for ($i = 0; $i < 120; $i++) {
            $uncommonPairs[] = $i * 7;
            $uncommonPairs[] = 52428800 + $i * 4096;
        }
        $commonPlan = self::plan(...$commonPairs);
        $uncommonPlan = self::plan(...$uncommonPairs);
        $defaults = IngestionConfigurationData::defaultsPhp();
        $tuned = new IngestionConfigurationData(...array_replace($defaults->toArrayPhp(), [
            'binaryThreads' => 48, 'maxMessages' => PHP_INT_MAX, 'safeBackfillDate' => '1999-12-31', 'grabStatus' => false,
            'partRepair' => false, 'categorizeWebDl' => true, 'deletePasswordedReleases' => true, 'backfillQuantity' => PHP_INT_MIN,
        ]));

        $workloads = [
            'normalizeAnalysis/common' => [
                static fn () => ArchiveProcessingService::normalizeAnalysisPhp(true, '', false, $summary),
                static fn () => ArchiveProcessingService::normalizeAnalysisNative(true, '', false, $summary),
            ],
            'normalizeAnalysis/uncommon' => [
                static fn () => ArchiveProcessingService::normalizeAnalysisPhp(false, $longError, false, null),
                static fn () => ArchiveProcessingService::normalizeAnalysisNative(false, $longError, false, null),
            ],
            'realSizeOf/common' => [
                static fn () => $commonPlan->realSizeOfPhp(6),
                static fn () => $commonPlan->realSizeOfNative(6),
            ],
            'realSizeOf/uncommon' => [
                static fn () => $uncommonPlan->realSizeOfPhp(-5),
                static fn () => $uncommonPlan->realSizeOfNative(-5),
            ],
            'isPreviewSource/common' => [
                static fn () => DownloadKind::MediaSource->isPreviewSourcePhp(),
                static fn () => DownloadKind::MediaSource->isPreviewSourceNative(),
            ],
            'isPreviewSource/uncommon' => [
                static fn () => DownloadKind::Compressed->isPreviewSourcePhp(),
                static fn () => DownloadKind::Compressed->isPreviewSourceNative(),
            ],
            'imageSuccess/common' => [
                static fn () => ImageProcessingResult::successPhp('/covers/preview/guid_thumb.webp', 320, 240, 'image/webp')->toArray(),
                static fn () => ImageProcessingResult::successNative('/covers/preview/guid_thumb.webp', 320, 240, 'image/webp')->toArray(),
            ],
            'imageSuccess/uncommon' => [
                static fn () => ImageProcessingResult::successPhp('/'.str_repeat('nested/', 40).'x.png', PHP_INT_MAX, 1, 'image/png')->toArray(),
                static fn () => ImageProcessingResult::successNative('/'.str_repeat('nested/', 40).'x.png', PHP_INT_MAX, 1, 'image/png')->toArray(),
            ],
            'imageFailure/common' => [
                static fn () => ImageProcessingResult::failurePhp('Unable to decode or process the image.')->toArray(),
                static fn () => ImageProcessingResult::failureNative('Unable to decode or process the image.')->toArray(),
            ],
            'imageFailure/uncommon' => [
                static fn () => ImageProcessingResult::failurePhp($longError)->toArray(),
                static fn () => ImageProcessingResult::failureNative($longError)->toArray(),
            ],
            'ingestionDefaults/common' => [
                static fn () => IngestionConfigurationData::defaultsPhp()->toArrayPhp(),
                static fn () => IngestionConfigurationData::defaultsNative()->toArrayPhp(),
            ],
            'ingestionDefaults/uncommon' => [
                static fn () => IngestionConfigurationData::defaultsPhp()->safeBackfillDate,
                static fn () => IngestionConfigurationData::defaultsNative()->safeBackfillDate,
            ],
            'ingestionToArray/common' => [
                static fn () => $defaults->toArrayPhp(),
                static fn () => $defaults->toArrayNative(),
            ],
            'ingestionToArray/uncommon' => [
                static fn () => $tuned->toArrayPhp(),
                static fn () => $tuned->toArrayNative(),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            $this->assertSame($phpCall(), $rustCall(), $name);
            for ($w = 0; $w < 512; $w++) {
                $phpCall();
                $rustCall();
            }

            $reps = [];
            for ($rep = 0; $rep < 3; $rep++) {
                $samples = [[], []];
                for ($pair = 0; $pair < 4096; $pair++) {
                    $first = $random->getInt(0, 1);
                    foreach ([$first, 1 - $first] as $side) {
                        $fn = $side === 0 ? $phpCall : $rustCall;
                        $t0 = hrtime(true);
                        $fn();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
                $phpMean = array_sum($samples[0]) / count($samples[0]);
                $rustMean = array_sum($samples[1]) / count($samples[1]);
                $reps[] = sprintf(
                    'rep%d=%.2fx/%.2fx (php %.0f/%.0fns rust %.0f/%.0fns)',
                    $rep + 1,
                    $phpMean / $rustMean,
                    $percentile99($samples[0]) / $percentile99($samples[1]),
                    $phpMean,
                    $percentile99($samples[0]),
                    $rustMean,
                    $percentile99($samples[1]),
                );
            }
            fwrite(STDOUT, sprintf("%-30s %s\n", $name, implode('  ', $reps)));
        }
    }
}
