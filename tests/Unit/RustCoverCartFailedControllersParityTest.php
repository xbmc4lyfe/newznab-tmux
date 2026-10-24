<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Api\CategoryData;
use App\Enums\IncidentStatusEnum;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CoverController;
use App\Http\Controllers\FailedReleasesController;
use App\Models\ServiceIncident;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_COVER_CART_FAILED_CONTROLLERS_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib):
 *
 * - Round 1: PHP median = 0.482 ms, Rust FFI median = 0.861 ms (0.56x; per-call FFI marshaling overhead dominates sub-microsecond controller/DTO/model helpers)
 * - Round 2: PHP median = 0.489 ms, Rust FFI median = 0.854 ms (0.57x)
 * - Round 3: PHP median = 0.478 ms, Rust FFI median = 0.834 ms (0.57x)
 * - Overall: PHP stays the production default (`$useRustFfi = false`) across all 5 classes because the 1.5x speedup threshold is not met.
 */
class RustCoverCartFailedControllersParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    ssize_t nntmux_cover_validate_and_expand(
        const char *type_ptr,
        size_t type_len,
        const char *filename_ptr,
        size_t filename_len,
        size_t *out_meta,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_cover_is_allowed_mime(
        const char *mime_ptr,
        size_t mime_len
    );
    ssize_t nntmux_cart_parse_guids_csv(
        const char *csv_ptr,
        size_t csv_len,
        size_t *out_count,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_cart_diff_release_ids(
        const int64_t *release_ids_ptr,
        size_t release_len,
        const int64_t *existing_ids_ptr,
        size_t existing_len,
        int64_t *out_ids,
        size_t out_cap
    );
    ssize_t nntmux_cart_format_added_message(
        int64_t added_count,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_failed_release_dnzb_error_spec(
        int32_t code,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_failed_release_build_location_query(
        const char *guid_ptr,
        size_t guid_len,
        uint32_t has_token,
        const char *token_ptr,
        size_t token_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_category_data_normalize_payload(
        int64_t id,
        const char *title_ptr,
        size_t title_len,
        int64_t *out_id,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_service_incident_is_resolved(
        uint32_t has_status,
        const char *status_ptr,
        size_t status_len
    );
    CDEF;

    protected function tearDown(): void
    {
        CoverController::resetRustFfi();
        CartController::resetRustFfi();
        FailedReleasesController::resetRustFfi();
        CategoryData::resetRustFfi();
        ServiceIncident::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        $classes = [
            CoverController::class,
            CartController::class,
            FailedReleasesController::class,
            CategoryData::class,
            ServiceIncident::class,
        ];

        foreach ($classes as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP implementation");

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function coverSpecProvider(): array
    {
        return [
            'anime_numeric_cover_jpg' => ['anime', '12345-cover.JPG'],
            'anime_uppercase_cover_suffix_does_not_expand_id' => ['anime', '12345-COVER.webp'],
            'anime_zero_cover_returns_placeholder' => ['anime', '0-cover.jpg'],
            'music_zero_double_digit_returns_placeholder' => ['music', '00.webp'],
            'movies_zero_cover_not_numeric_id_type' => ['movies', '0-cover.jpg'],
            'book_zero_png_not_numeric_placeholder_ext' => ['book', '0-cover.png'],
            'preview_prepends_thumb' => ['preview', 'abc_123-def.png'],
            'sample_already_has_thumb' => ['sample', 'abc_123_thumb.gif'],
            'preview_uppercase_thumb_prepends_lowercase_thumb' => ['preview', 'abc_THUMB.jpeg'],
            'invalid_type' => ['unknown', '12345.jpg'],
            'invalid_filename_leading_dash' => ['movies', '-123.jpg'],
            'invalid_filename_double_dot' => ['movies', '123..jpg'],
            'invalid_filename_extension' => ['movies', '123.svg'],
            'invalid_filename_trailing_newline' => ['movies', "123.jpg\n"],
            'empty_filename' => ['movies', ''],
        ];
    }

    #[DataProvider('coverSpecProvider')]
    public function test_cover_controller_validate_and_expand_parity(string $type, string $filename): void
    {
        $this->requireMetadataFfi();

        $php = CoverController::validateAndExpandCoverSpecPhp($type, $filename);
        $native = CoverController::validateAndExpandCoverSpecNative($type, $filename);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            CoverController::setUseRustFfi($useRust);
            $this->assertSame($php, CoverController::validateAndExpandCoverSpec($type, $filename));
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function coverMimeProvider(): array
    {
        return [
            'jpeg' => ['image/jpeg', true],
            'png' => ['image/png', true],
            'gif' => ['image/gif', true],
            'webp' => ['image/webp', true],
            'uppercase_jpeg_rejected' => ['IMAGE/JPEG', false],
            'svg_rejected' => ['image/svg+xml', false],
            'empty_rejected' => ['', false],
        ];
    }

    #[DataProvider('coverMimeProvider')]
    public function test_cover_controller_allowed_mime_parity(string $mime, bool $expected): void
    {
        $this->requireMetadataFfi();

        $php = CoverController::isAllowedCoverMimeTypePhp($mime);
        $native = CoverController::isAllowedCoverMimeTypeNative($mime);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            CoverController::setUseRustFfi($useRust);
            $this->assertSame($php, CoverController::isAllowedCoverMimeType($mime));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cartGuidsCsvProvider(): array
    {
        return [
            'empty_csv' => [''],
            'only_commas_and_zeros' => [' , 0 , ,   '],
            'duplicates_and_whitespace' => ["  guid-a , guid-b , guid-a , \tguid-c\r\n , 0 , 00 "],
            'numeric_strings_preserve_strict_uniqueness' => ['01,1,01,00,2'],
            'embedded_nul_inside_token_falls_back_cleanly' => ["guid\0a,guid-b,guid\0a"],
        ];
    }

    #[DataProvider('cartGuidsCsvProvider')]
    public function test_cart_controller_parse_guids_parity(string $rawCsv): void
    {
        $this->requireMetadataFfi();

        $php = CartController::parseCartGuidsPhp($rawCsv);
        $native = CartController::parseCartGuidsNative($rawCsv);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            CartController::setUseRustFfi($useRust);
            $this->assertSame($php, CartController::parseCartGuids($rawCsv));
        }
    }

    /**
     * @return array<string, array{0: list<int>, 1: list<int>, 2: int}>
     */
    public static function cartDiffAndMessageProvider(): array
    {
        return [
            'empty_releases' => [[], [], 0],
            'all_missing' => [[101, 202, 303], [], 3],
            'partial_overlap' => [[10, 20, 30, 40, 50], [20, 40, 99], 3],
            'all_existing' => [[5, 6], [5, 6, 7], 0],
            'negative_added_count' => [[1], [1], -2],
        ];
    }

    /**
     * @param  list<int>  $releaseIds
     * @param  list<int>  $existingReleaseIds
     */
    #[DataProvider('cartDiffAndMessageProvider')]
    public function test_cart_controller_diff_and_message_parity(
        array $releaseIds,
        array $existingReleaseIds,
        int $addedCount,
    ): void {
        $this->requireMetadataFfi();

        $phpDiff = CartController::diffMissingReleaseIdsPhp($releaseIds, $existingReleaseIds);
        $nativeDiff = CartController::diffMissingReleaseIdsNative($releaseIds, $existingReleaseIds);
        $this->assertSame($phpDiff, $nativeDiff);

        $phpMsg = CartController::formatCartAddedMessagePhp($addedCount);
        $nativeMsg = CartController::formatCartAddedMessageNative($addedCount);
        $this->assertSame($phpMsg, $nativeMsg);

        foreach ([false, true] as $useRust) {
            CartController::setUseRustFfi($useRust);
            $this->assertSame($phpDiff, CartController::diffMissingReleaseIds($releaseIds, $existingReleaseIds));
            $this->assertSame($phpMsg, CartController::formatCartAddedMessage($addedCount));
        }
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: bool, 3: ?string}>
     */
    public static function failedReleasesProvider(): array
    {
        return [
            'status_400_no_token' => [400, 'abc123def456', false, null],
            'status_401_with_token' => [401, 'abc123def456', true, 'rss_token_xyz'],
            'status_404_rfc3986_encoding' => [404, 'guid with spaces & / + ~ - _ .', true, 'tok+en/1=2&3~a'],
            'include_token_true_but_null_value' => [404, 'guid-999', true, null],
            'include_token_true_with_empty_string' => [400, 'guid-000', true, ''],
            'unknown_status_falls_back_to_404' => [500, 'guid-500', false, 'ignored_token'],
        ];
    }

    #[DataProvider('failedReleasesProvider')]
    public function test_failed_releases_controller_error_and_query_parity(
        int $statusCode,
        string $guid,
        bool $includeToken,
        ?string $apiToken,
    ): void {
        $this->requireMetadataFfi();

        $phpErr = FailedReleasesController::buildDnzbErrorSpecPhp($statusCode);
        $nativeErr = FailedReleasesController::buildDnzbErrorSpecNative($statusCode);
        $this->assertSame($phpErr, $nativeErr);

        $phpQuery = FailedReleasesController::buildAlternateLocationQueryPhp($guid, $includeToken, $apiToken);
        $nativeQuery = FailedReleasesController::buildAlternateLocationQueryNative($guid, $includeToken, $apiToken);
        $this->assertSame($phpQuery, $nativeQuery);

        foreach ([false, true] as $useRust) {
            FailedReleasesController::setUseRustFfi($useRust);
            $this->assertSame($phpErr, FailedReleasesController::buildDnzbErrorSpec($statusCode));
            $this->assertSame($phpQuery, FailedReleasesController::buildAlternateLocationQuery($guid, $includeToken, $apiToken));
        }
    }

    /**
     * @return array<string, array{0: int|string|null, 1: ?string, 2: array<int|string, mixed>}>
     */
    public static function categoryDataProvider(): array
    {
        return [
            'movies_category' => [2000, 'Movies', [2010 => 'Foreign', 2020 => 'Other', 2040 => 'HD']],
            'string_id_and_null_title' => ['3000', null, ['3010' => 'MP3', '3040' => null]],
            'utf8_title_and_empty_subcategories' => [5000, 'TV — アニメ', []],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $subcategories
     */
    #[DataProvider('categoryDataProvider')]
    public function test_category_data_normalize_payload_parity(
        int|string|null $id,
        ?string $title,
        array $subcategories,
    ): void {
        $this->requireMetadataFfi();

        $php = CategoryData::normalizeCategoryPayloadPhp($id, $title, $subcategories);
        $native = CategoryData::normalizeCategoryPayloadNative($id, $title, $subcategories);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            CategoryData::setUseRustFfi($useRust);
            $this->assertSame($php, CategoryData::normalizeCategoryPayload($id, $title, $subcategories));
        }
    }

    /**
     * @return array<string, array{0: IncidentStatusEnum|string|null, 1: bool}>
     */
    public static function serviceIncidentProvider(): array
    {
        return [
            'enum_resolved' => [IncidentStatusEnum::Resolved, true],
            'enum_investigating' => [IncidentStatusEnum::Investigating, false],
            'enum_identified' => [IncidentStatusEnum::Identified, false],
            'enum_monitoring' => [IncidentStatusEnum::Monitoring, false],
            'string_resolved' => ['resolved', true],
            'string_uppercase_resolved' => ['RESOLVED', false],
            'null_status' => [null, false],
        ];
    }

    #[DataProvider('serviceIncidentProvider')]
    public function test_service_incident_is_resolved_parity(
        IncidentStatusEnum|string|null $status,
        bool $expected,
    ): void {
        $this->requireMetadataFfi();

        $php = ServiceIncident::evaluateIsResolvedPhp($status);
        $native = ServiceIncident::evaluateIsResolvedNative($status);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            ServiceIncident::setUseRustFfi($useRust);
            $this->assertSame($php, ServiceIncident::evaluateIsResolved($status));
        }
    }

    public function test_all_five_classes_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        CoverController::setFfiForTesting(false);
        CoverController::enableRustFfi();
        $this->assertSame(
            CoverController::validateAndExpandCoverSpecPhp('anime', '123-cover.jpg'),
            CoverController::validateAndExpandCoverSpec('anime', '123-cover.jpg')
        );
        $this->assertTrue(CoverController::isAllowedCoverMimeType('image/png'));

        CartController::setFfiForTesting(false);
        CartController::enableRustFfi();
        $this->assertSame(
            CartController::parseCartGuidsPhp(' a , b , a '),
            CartController::parseCartGuids(' a , b , a ')
        );
        $this->assertSame([1, 3], CartController::diffMissingReleaseIds([1, 2, 3], [2]));
        $this->assertSame('2 item(s) added to cart', CartController::formatCartAddedMessage(2));

        FailedReleasesController::setFfiForTesting(false);
        FailedReleasesController::enableRustFfi();
        $this->assertSame(
            FailedReleasesController::buildDnzbErrorSpecPhp(401),
            FailedReleasesController::buildDnzbErrorSpec(401)
        );
        $this->assertSame(
            FailedReleasesController::buildAlternateLocationQueryPhp('guid-1', true, 'tok-1'),
            FailedReleasesController::buildAlternateLocationQuery('guid-1', true, 'tok-1')
        );

        CategoryData::setFfiForTesting(false);
        CategoryData::enableRustFfi();
        $this->assertSame(
            CategoryData::normalizeCategoryPayloadPhp(2000, 'Movies', [2010 => 'HD']),
            CategoryData::normalizeCategoryPayload(2000, 'Movies', [2010 => 'HD'])
        );

        ServiceIncident::setFfiForTesting(false);
        ServiceIncident::enableRustFfi();
        $this->assertTrue(ServiceIncident::evaluateIsResolved(IncidentStatusEnum::Resolved));
        $this->assertFalse(ServiceIncident::evaluateIsResolved(IncidentStatusEnum::Monitoring));
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_flags_and_undersized_buffers(): void
    {
        $ffi = $this->requireMetadataFfi();

        $raw = $ffi->new('uint8_t[128]');
        $unalignedInt64 = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        $unalignedConstInt64 = $ffi->cast('const int64_t *', FFI::addr($raw[1]));
        $unalignedSizeT = $ffi->cast('size_t *', FFI::addr($raw[1]));

        $smallBuf = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $smallBuf[$i] = 'Z';
        }

        // 1. nntmux_cover_validate_and_expand & nntmux_cover_is_allowed_mime
        $meta4 = $ffi->new('size_t[4]');
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_cover_validate_and_expand',
            'anime',
            5,
            '123-cover.jpg',
            13,
            $unalignedSizeT,
            $smallBuf,
            4
        ));
        $rcCoverSmall = (int) self::callFfi(
            $ffi,
            'nntmux_cover_validate_and_expand',
            'anime',
            5,
            '123-cover.jpg',
            13,
            $meta4,
            $smallBuf,
            4
        );
        $this->assertSame(-27, $rcCoverSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_cover_is_allowed_mime', null, 5));

        // 2. nntmux_cart_parse_guids_csv, diff_release_ids, format_added_message
        $count1 = $ffi->new('size_t[1]');
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_cart_parse_guids_csv', 'a,b', 3, $unalignedSizeT, $smallBuf, 4));
        $rcCartCsvSmall = (int) self::callFfi($ffi, 'nntmux_cart_parse_guids_csv', 'alpha,beta', 10, $count1, $smallBuf, 4);
        $this->assertSame(-10, $rcCartCsvSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $oneI64 = $ffi->new('int64_t[2]');
        $oneI64[0] = 10;
        $oneI64[1] = 20;
        $outI64 = $ffi->new('int64_t[1]');
        $outI64[0] = 999;
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_cart_diff_release_ids', $unalignedConstInt64, 1, $oneI64, 1, $outI64, 1));
        $this->assertSame(-2, (int) self::callFfi($ffi, 'nntmux_cart_diff_release_ids', $oneI64, 2, $oneI64, 0, $outI64, 1));
        $this->assertSame(999, (int) $outI64[0]);

        $rcMsgSmall = (int) self::callFfi($ffi, 'nntmux_cart_format_added_message', 5, $smallBuf, 4);
        $this->assertSame(-23, $rcMsgSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 3. nntmux_failed_release_dnzb_error_spec & build_location_query
        $lens2 = $ffi->new('size_t[2]');
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_failed_release_dnzb_error_spec', 500, $lens2, $smallBuf, 4));
        $this->assertSame(-66, (int) self::callFfi($ffi, 'nntmux_failed_release_dnzb_error_spec', 404, $lens2, $smallBuf, 4));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_failed_release_build_location_query', 'abc', 3, 2, '', 0, $smallBuf, 4));
        $this->assertSame(-6, (int) self::callFfi($ffi, 'nntmux_failed_release_build_location_query', 'abc', 3, 0, '', 0, $smallBuf, 4));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 4. nntmux_category_data_normalize_payload & nntmux_service_incident_is_resolved
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_category_data_normalize_payload', 2000, 'Movies', 6, $unalignedInt64, $smallBuf, 4));
        $this->assertSame(-6, (int) self::callFfi($ffi, 'nntmux_category_data_normalize_payload', 2000, 'Movies', 6, $outI64, $smallBuf, 4));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_service_incident_is_resolved', 2, 'resolved', 8));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_service_incident_is_resolved', 1, null, 8));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_COVER_CART_FAILED_CONTROLLERS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_COVER_CART_FAILED_CONTROLLERS_BENCH=1 to run the paired benchmark.');
        }

        $this->requireMetadataFfi();

        $csvInput = ' guid-001 , guid-002 , 0 , guid-001 , guid-003 , guid-004 ';
        $relIds = [101, 102, 103, 104];
        $exIds = [102, 104];
        $subcats = [2010 => 'Foreign', 2020 => 'Other', 2040 => 'HD'];

        $runBatch = static function (int $iterations) use ($csvInput, $relIds, $exIds, $subcats): void {
            for ($i = 0; $i < $iterations; $i++) {
                CoverController::validateAndExpandCoverSpec('anime', '12345-cover.JPG');
                CoverController::isAllowedCoverMimeType('image/webp');
                CartController::parseCartGuids($csvInput);
                CartController::diffMissingReleaseIds($relIds, $exIds);
                CartController::formatCartAddedMessage(2);
                FailedReleasesController::buildDnzbErrorSpec(404);
                FailedReleasesController::buildAlternateLocationQuery('guid-abc-123', true, 'token_xyz_987');
                CategoryData::normalizeCategoryPayload(2000, 'Movies', $subcats);
                ServiceIncident::evaluateIsResolved(IncidentStatusEnum::Resolved);
            }
        };

        $setAllModes = static function (bool $rust): void {
            CoverController::setUseRustFfi($rust);
            CartController::setUseRustFfi($rust);
            FailedReleasesController::setUseRustFfi($rust);
            CategoryData::setUseRustFfi($rust);
            ServiceIncident::setUseRustFfi($rust);
        };

        for ($w = 0; $w < 8; $w++) {
            $setAllModes(false);
            $runBatch(10);
            $setAllModes(true);
            $runBatch(10);
        }

        $rounds = 3;
        $samples = 41;
        $iterations = 200;
        $summary = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $phpTimes = [];
            $rustTimes = [];

            for ($s = 0; $s < $samples; $s++) {
                $rustFirst = (($round + $s) % 2) === 1;
                if ($rustFirst) {
                    $setAllModes(true);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;

                    $setAllModes(false);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;
                } else {
                    $setAllModes(false);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;

                    $setAllModes(true);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;
                }
            }

            sort($phpTimes);
            sort($rustTimes);
            $phpMedian = $phpTimes[intdiv($samples, 2)];
            $rustMedian = $rustTimes[intdiv($samples, 2)];
            $speedup = $phpMedian / max(0.0001, $rustMedian);
            $summary[] = sprintf(
                'Round %d: PHP median=%.3f ms, Rust median=%.3f ms, speedup=%.2fx',
                $round,
                $phpMedian,
                $rustMedian,
                $speedup
            );
        }

        fwrite(STDERR, "\n[Wave 26.4 Benchmark] ".implode(' | ', $summary)."\n");
        $this->assertCount(3, $summary);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function requireMetadataFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::DIRECT_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('Rust metadata FFI library is not built or available.');
        }

        return $ffi;
    }
}
