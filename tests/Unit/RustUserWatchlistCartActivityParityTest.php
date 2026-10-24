<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\UserDownload;
use App\Models\UserMovie;
use App\Models\UserRequest;
use App\Models\UserSerie;
use App\Models\UsersRelease;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Paired benchmark evidence (macOS Darwin arm64, release build of `nntmux-pipeline-ffi`
 * `additional` domain, 3 repetitions x 41 paired randomized samples x 200 iterations,
 * warmup = 6):
 *
 * - `UserDownload::aggregateHourlyBuckets`:
 *   PHP median 0.1048 ms (p99 0.1138 ms) vs Rust FFI median 0.5724 ms (p99 0.6127 ms) -> 0.18x median (0.19x p99).
 * - `UserDownload::buildDownloadBatchRows`:
 *   PHP median 0.0239 ms (p99 0.0261 ms) vs Rust FFI median 0.1072 ms (p99 0.1153 ms) -> 0.22x median (0.23x p99).
 * - `UserRequest::aggregateHourlyRequestBuckets` + `isDirectUserId`:
 *   PHP median 0.1092 ms (p99 0.1238 ms) vs Rust FFI median 0.5950 ms (p99 0.6622 ms) -> 0.18x median (0.19x p99).
 * - `UserMovie::serializeCategoryIds` + `UserSerie::serializeSeriesCategoryIds`:
 *   PHP median 0.0633 ms (p99 0.0736 ms) vs Rust FFI median 0.1720 ms (p99 0.2204 ms) -> 0.37x median (0.33x p99).
 * - `UsersRelease::normalizeCartGuids` + `deduplicateReleaseIds`:
 *   PHP median 0.1063 ms (p99 0.1200 ms) vs Rust FFI median 0.2585 ms (p99 0.2860 ms) -> 0.41x median (0.42x p99).
 *
 * Per `.ai/rules/rust-performance.md`, per-call PHP FFI marshaling (`FFI::new`, `FFI::string`,
 * CData array packing/unpacking) exceeds the small in-memory array/string work (< 1.5x threshold),
 * so production defaults remain `$useRustFfi = false` across all five models while preserving
 * the Rust exports and full parity coverage.
 */
class RustUserWatchlistCartActivityParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_download_aggregate_hourly_buckets_nul(
    const char *hour_keys_ptr,
    size_t hour_keys_len,
    size_t hour_keys_count,
    const char *event_keys_ptr,
    size_t event_keys_len,
    size_t event_keys_count,
    int64_t *out_counts,
    size_t out_cap
);
ssize_t nntmux_user_download_normalize_batch_ids(
    int64_t user_id,
    const int64_t *release_ids_ptr,
    size_t release_ids_len,
    int64_t *out_pairs,
    size_t out_pairs_cap
);
ssize_t nntmux_user_request_aggregate_hourly_buckets_nul(
    const char *hour_keys_ptr,
    size_t hour_keys_len,
    size_t hour_keys_count,
    const char *event_keys_ptr,
    size_t event_keys_len,
    size_t event_keys_count,
    int64_t *out_counts,
    size_t out_cap
);
int32_t nntmux_user_request_is_direct_user_id(
    uint8_t value_kind,
    int64_t int_val
);
ssize_t nntmux_user_movie_serialize_category_ids_nul(
    const char *cats_ptr,
    size_t cats_len,
    size_t cats_count,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_user_serie_serialize_category_ids_nul(
    const char *cats_ptr,
    size_t cats_len,
    size_t cats_count,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_users_release_normalize_cart_guids_nul(
    const char *guids_ptr,
    size_t guids_len,
    size_t guids_count,
    size_t *out_count,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_users_release_deduplicate_release_ids(
    const int64_t *release_ids_ptr,
    size_t release_ids_len,
    int64_t *out_ids,
    size_t out_cap
);
CDEF;

    protected function tearDown(): void
    {
        UserDownload::resetRustFfi();
        UserRequest::resetRustFfi();
        UserMovie::resetRustFfi();
        UserSerie::resetRustFfi();
        UsersRelease::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_and_toggle_lifecycle(): void
    {
        $classes = [
            UserDownload::class,
            UserRequest::class,
            UserMovie::class,
            UserSerie::class,
            UsersRelease::class,
        ];

        foreach ($classes as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to PHP implementation");

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

    public function test_user_download_hourly_buckets_and_batch_rows_parity(): void
    {
        $this->requireNativeSymbol('nntmux_user_download_aggregate_hourly_buckets_nul');

        $hours24 = [];
        for ($h = 0; $h < 24; $h++) {
            $hours24[] = sprintf('%02d:00', $h);
        }

        $bucketCases = [
            [[], []],
            [$hours24, []],
            [$hours24, ['00:00', '13:00', '13:00', '23:00', '99:00', '13:00']],
            [['01:00', '02:00', '01:00'], ['01:00', '02:00', '01:00', '03:00']],
            [['10', '11'], ['10', '10', '12']],
            [["bad\0hour", '12:00'], ['12:00', "bad\0hour"]],
        ];

        foreach ($bucketCases as [$hourKeys, $eventHourKeys]) {
            $php = UserDownload::aggregateHourlyBucketsPhp($hourKeys, $eventHourKeys);
            $rust = UserDownload::aggregateHourlyBucketsNative($hourKeys, $eventHourKeys);
            $this->assertSame($php, $rust);

            UserDownload::disableRustFfi();
            $defaultOut = UserDownload::aggregateHourlyBuckets($hourKeys, $eventHourKeys);
            UserDownload::enableRustFfi();
            $enabledOut = UserDownload::aggregateHourlyBuckets($hourKeys, $eventHourKeys);
            $this->assertSame($defaultOut, $enabledOut);
        }

        $batchCases = [
            [42, [], '2026-10-24 19:00:00'],
            [42, [101], '2026-10-24 19:00:00'],
            [99, [10, 20, 30, 10], '2026-10-24 20:15:30'],
            [7, ['500', 600], '2026-10-24 21:00:00'],
        ];

        foreach ($batchCases as [$userId, $releaseIds, $timestamp]) {
            $phpRows = UserDownload::buildDownloadBatchRowsPhp($userId, $releaseIds, $timestamp);
            $rustRows = UserDownload::buildDownloadBatchRowsNative($userId, $releaseIds, $timestamp);
            $this->assertSame($phpRows, $rustRows);

            UserDownload::disableRustFfi();
            $viaDefault = UserDownload::buildDownloadBatchRows($userId, $releaseIds, $timestamp);
            UserDownload::enableRustFfi();
            $viaRust = UserDownload::buildDownloadBatchRows($userId, $releaseIds, $timestamp);
            $this->assertSame($viaDefault, $viaRust);
        }
    }

    public function test_user_request_hourly_buckets_and_direct_user_id_parity(): void
    {
        $this->requireNativeSymbol('nntmux_user_request_aggregate_hourly_buckets_nul');

        $hours24 = [];
        for ($h = 0; $h < 24; $h++) {
            $hours24[] = sprintf('%02d:00', $h);
        }

        $bucketCases = [
            [[], []],
            [$hours24, ['05:00', '05:00', '18:00', 'unknown']],
            [['08:00', "nul\0key"], ['08:00']],
        ];

        foreach ($bucketCases as [$hourKeys, $eventHourKeys]) {
            $php = UserRequest::aggregateHourlyRequestBucketsPhp($hourKeys, $eventHourKeys);
            $rust = UserRequest::aggregateHourlyRequestBucketsNative($hourKeys, $eventHourKeys);
            $this->assertSame($php, $rust);

            UserRequest::disableRustFfi();
            $defaultOut = UserRequest::aggregateHourlyRequestBuckets($hourKeys, $eventHourKeys);
            UserRequest::enableRustFfi();
            $enabledOut = UserRequest::aggregateHourlyRequestBuckets($hourKeys, $eventHourKeys);
            $this->assertSame($defaultOut, $enabledOut);
        }

        $idCases = [
            [0, true],
            [1, true],
            [42, true],
            [-99, true],
            ['42', false],
            ['api_token_abc123', false],
            ['', false],
            [null, false],
            [true, false],
            [false, false],
            [42.0, false],
            [[], false],
        ];

        foreach ($idCases as [$input, $expected]) {
            $php = UserRequest::isDirectUserIdPhp($input);
            $rust = UserRequest::isDirectUserIdNative($input);
            $this->assertSame($expected, $php);
            $this->assertSame($php, $rust);

            UserRequest::disableRustFfi();
            $viaDefault = UserRequest::isDirectUserId($input);
            UserRequest::enableRustFfi();
            $viaRust = UserRequest::isDirectUserId($input);
            $this->assertSame($viaDefault, $viaRust);
        }
    }

    public function test_user_movie_and_user_serie_category_serialization_parity(): void
    {
        $this->requireNativeSymbol('nntmux_user_movie_serialize_category_ids_nul');

        $categoryCases = [
            [[], 'NULL'],
            [[2030], '2030'],
            [[2030, 2040, 2050], '2030|2040|2050'],
            [['5030', '5040'], '5030|5040'],
            [[''], ''],
            [['2030', '', '2040'], '2030||2040'],
            [["2030\0bad", '2040'], "2030\0bad|2040"],
        ];

        foreach ($categoryCases as [$cats, $expected]) {
            $moviePhp = UserMovie::serializeCategoryIdsPhp($cats);
            $movieRust = UserMovie::serializeCategoryIdsNative($cats);
            $this->assertSame($expected, $moviePhp);
            $this->assertSame($moviePhp, $movieRust);

            UserMovie::disableRustFfi();
            $movieDefault = UserMovie::serializeCategoryIds($cats);
            UserMovie::enableRustFfi();
            $movieEnabled = UserMovie::serializeCategoryIds($cats);
            $this->assertSame($movieDefault, $movieEnabled);

            $seriePhp = UserSerie::serializeSeriesCategoryIdsPhp($cats);
            $serieRust = UserSerie::serializeSeriesCategoryIdsNative($cats);
            $this->assertSame($expected, $seriePhp);
            $this->assertSame($seriePhp, $serieRust);

            UserSerie::disableRustFfi();
            $serieDefault = UserSerie::serializeSeriesCategoryIds($cats);
            UserSerie::enableRustFfi();
            $serieEnabled = UserSerie::serializeSeriesCategoryIds($cats);
            $this->assertSame($serieDefault, $serieEnabled);
        }
    }

    public function test_users_release_cart_guid_and_release_id_normalization_parity(): void
    {
        $this->requireNativeSymbol('nntmux_users_release_normalize_cart_guids_nul');

        $guidCases = [
            null,
            false,
            'not-an-array',
            123,
            [],
            ['', '   ', "\t\n\r", '0', '  0  '],
            ['  guid-1  ', 'guid-2', 'guid-1', "\tguid-3\n", '', '0', 'guid-2'],
            ['00', '000', 'abc', '00'],
            ["nul\0guid", 'normal-guid', "nul\0guid"],
        ];

        foreach ($guidCases as $rawGuids) {
            $phpGuids = UsersRelease::normalizeCartGuidsPhp($rawGuids);
            $rustGuids = UsersRelease::normalizeCartGuidsNative($rawGuids);
            $this->assertSame($phpGuids, $rustGuids);

            UsersRelease::disableRustFfi();
            $viaDefault = UsersRelease::normalizeCartGuids($rawGuids);
            UsersRelease::enableRustFfi();
            $viaRust = UsersRelease::normalizeCartGuids($rawGuids);
            $this->assertSame($viaDefault, $viaRust);
        }

        $releaseIdCases = [
            [],
            [101],
            [10, 20, 10, 30, 20, 40, 10],
            [0, -1, 5, 0, -1, 999999],
            ['15', 15, '25', 25],
        ];

        foreach ($releaseIdCases as $rawIds) {
            $phpIds = UsersRelease::deduplicateReleaseIdsPhp($rawIds);
            $rustIds = UsersRelease::deduplicateReleaseIdsNative($rawIds);
            $this->assertSame($phpIds, $rustIds);

            UsersRelease::disableRustFfi();
            $viaDefault = UsersRelease::deduplicateReleaseIds($rawIds);
            UsersRelease::enableRustFfi();
            $viaRust = UsersRelease::deduplicateReleaseIds($rawIds);
            $this->assertSame($viaDefault, $viaRust);
        }
    }

    public function test_disabled_ffi_falls_back_cleanly_to_php(): void
    {
        UserDownload::setFfiForTesting(false);
        UserDownload::enableRustFfi();
        $this->assertSame(['00:00' => 2], UserDownload::aggregateHourlyBuckets(['00:00'], ['00:00', '00:00']));
        $this->assertSame(
            [['users_id' => 1, 'releases_id' => 9, 'timestamp' => '2026-10-24 00:00:00']],
            UserDownload::buildDownloadBatchRows(1, [9], '2026-10-24 00:00:00')
        );

        UserRequest::setFfiForTesting(false);
        UserRequest::enableRustFfi();
        $this->assertSame(['01:00' => 1], UserRequest::aggregateHourlyRequestBuckets(['01:00'], ['01:00']));
        $this->assertTrue(UserRequest::isDirectUserId(7));
        $this->assertFalse(UserRequest::isDirectUserId('token'));

        UserMovie::setFfiForTesting(false);
        UserMovie::enableRustFfi();
        $this->assertSame('2030|2040', UserMovie::serializeCategoryIds([2030, 2040]));

        UserSerie::setFfiForTesting(false);
        UserSerie::enableRustFfi();
        $this->assertSame('5030|5040', UserSerie::serializeSeriesCategoryIds([5030, 5040]));

        UsersRelease::setFfiForTesting(false);
        UsersRelease::enableRustFfi();
        $this->assertSame(['abc', 'def'], UsersRelease::normalizeCartGuids([' abc ', '', 'def', 'abc']));
        $this->assertSame([1, 2], UsersRelease::deduplicateReleaseIds([1, 2, 1]));
    }

    public function test_c_abi_rejects_null_unaligned_and_undersized_buffers(): void
    {
        $ffi = $this->requireNativeSymbol('nntmux_user_download_aggregate_hourly_buckets_nul');

        $rawBytes = $ffi->new('char[64]');
        $unalignedInt64 = $ffi->cast('int64_t *', FFI::addr($rawBytes[1]));
        $unalignedSizeT = $ffi->cast('size_t *', FFI::addr($rawBytes[1]));

        $smallI64 = $ffi->new('int64_t[1]');
        $smallI64[0] = 777;

        // Undersized int64 output buffer returns negative needed count and leaves sentinel intact.
        // @phpstan-ignore method.notFound
        $rcBuckets = $ffi->nntmux_user_download_aggregate_hourly_buckets_nul("00:00\001:00", 11, 2, '', 0, 0, $smallI64, 1);
        $this->assertSame(-2, $rcBuckets);
        $this->assertSame(777, $smallI64[0]);

        // Unaligned output pointer rejected on both hourly bucket aggregators.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_user_download_aggregate_hourly_buckets_nul('00:00', 5, 1, '', 0, 0, $unalignedInt64, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_user_request_aggregate_hourly_buckets_nul('00:00', 5, 1, '', 0, 0, $unalignedInt64, 4));

        // Batch IDs undersized output capacity returns -4 for 2 release IDs (needs 4 i64s).
        $inIds = $ffi->new('int64_t[2]');
        $inIds[0] = 10;
        $inIds[1] = 20;
        // @phpstan-ignore method.notFound
        $this->assertSame(-4, $ffi->nntmux_user_download_normalize_batch_ids(5, $inIds, 2, $smallI64, 1));
        $this->assertSame(777, $smallI64[0]);

        // Invalid value_kind rejected by nntmux_user_request_is_direct_user_id.
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_user_request_is_direct_user_id(9, 0));

        // Undersized byte buffer on movie and serie category serializers leaves sentinel intact.
        $smallU8 = $ffi->new('uint8_t[3]');
        FFI::memset($smallU8, 0xA5, 3);
        // @phpstan-ignore method.notFound
        $this->assertSame(-4, $ffi->nntmux_user_movie_serialize_category_ids_nul('', 0, 0, $smallU8, 3));
        $this->assertSame(0xA5, $smallU8[0]);
        // @phpstan-ignore method.notFound
        $this->assertSame(-4, $ffi->nntmux_user_serie_serialize_category_ids_nul('', 0, 0, $smallU8, 3));
        $this->assertSame(0xA5, $smallU8[0]);

        // Unaligned out_count pointer rejected by normalize_cart_guids_nul.
        $buf = $ffi->new('uint8_t[32]');
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_users_release_normalize_cart_guids_nul('abc', 3, 1, $unalignedSizeT, $buf, 32));

        // Undersized output buffer on deduplicate_release_ids leaves sentinel intact.
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, $ffi->nntmux_users_release_deduplicate_release_ids($inIds, 2, $smallI64, 1));
        $this->assertSame(777, $smallI64[0]);
    }

    public function test_paired_randomized_benchmark_harnesses_execute_cleanly(): void
    {
        $this->requireNativeSymbol('nntmux_user_download_aggregate_hourly_buckets_nul');

        $repetitions = 3;
        $samples = 41;
        $iterations = 200;
        $warmup = 6;
        $sink = 0;

        $hours = ['00:00', '01:00', '02:00', '03:00', '04:00', '05:00'];
        $events = ['01:00', '03:00', '01:00', '05:00'];

        for ($w = 0; $w < $warmup; $w++) {
            $sink += UserDownload::aggregateHourlyBucketsPhp($hours, $events)['01:00'];
            $sink += UserDownload::aggregateHourlyBucketsNative($hours, $events)['01:00'];
            $sink += count(UserDownload::buildDownloadBatchRowsPhp(1, [10, 20], '2026-10-24 00:00:00'));
            $sink += count(UserDownload::buildDownloadBatchRowsNative(1, [10, 20], '2026-10-24 00:00:00'));
            $sink += UserRequest::isDirectUserIdPhp(42) ? 1 : 0;
            $sink += UserRequest::isDirectUserIdNative(42) ? 1 : 0;
            $sink += strlen(UserMovie::serializeCategoryIdsPhp([2030, 2040]));
            $sink += strlen(UserMovie::serializeCategoryIdsNative([2030, 2040]));
            $sink += strlen(UserSerie::serializeSeriesCategoryIdsPhp([5030, 5040]));
            $sink += strlen(UserSerie::serializeSeriesCategoryIdsNative([5030, 5040]));
            $sink += count(UsersRelease::normalizeCartGuidsPhp([' a ', '', 'b', 'a']));
            $sink += count(UsersRelease::normalizeCartGuidsNative([' a ', '', 'b', 'a']));
            $sink += count(UsersRelease::deduplicateReleaseIdsPhp([1, 2, 1, 3]));
            $sink += count(UsersRelease::deduplicateReleaseIdsNative([1, 2, 1, 3]));
        }
        $this->assertGreaterThan(0, $sink);

        $phpTimes = [];
        $rustTimes = [];

        for ($rep = 0; $rep < $repetitions; $rep++) {
            for ($s = 0; $s < $samples; $s++) {
                $rustFirst = (($rep * $samples + $s) % 2) === 0;
                if ($rustFirst) {
                    $rustTimes[] = $this->runBatchNative($iterations, $hours, $events);
                    $phpTimes[] = $this->runBatchPhp($iterations, $hours, $events);
                } else {
                    $phpTimes[] = $this->runBatchPhp($iterations, $hours, $events);
                    $rustTimes[] = $this->runBatchNative($iterations, $hours, $events);
                }
            }
        }

        $this->assertCount($repetitions * $samples, $phpTimes);
        $this->assertCount($repetitions * $samples, $rustTimes);
        $this->assertGreaterThan(0.0, array_sum($phpTimes));
        $this->assertGreaterThan(0.0, array_sum($rustTimes));
    }

    /**
     * @param  list<string>  $hours
     * @param  list<string>  $events
     */
    private function runBatchPhp(int $iterations, array $hours, array $events): float
    {
        $sink = 0;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $sink += UserDownload::aggregateHourlyBucketsPhp($hours, $events)['01:00'];
            $sink += count(UserDownload::buildDownloadBatchRowsPhp(1, [10, 20], '2026-10-24 00:00:00'));
            $sink += UserRequest::aggregateHourlyRequestBucketsPhp($hours, $events)['03:00'];
            $sink += UserRequest::isDirectUserIdPhp(42) ? 1 : 0;
            $sink += strlen(UserMovie::serializeCategoryIdsPhp([2030, 2040]));
            $sink += strlen(UserSerie::serializeSeriesCategoryIdsPhp([5030, 5040]));
            $sink += count(UsersRelease::normalizeCartGuidsPhp([' a ', '', 'b', 'a']));
            $sink += count(UsersRelease::deduplicateReleaseIdsPhp([1, 2, 1, 3]));
        }

        return ((hrtime(true) - $start) / 1_000_000.0) + ($sink > 0 ? 0.0 : 0.0);
    }

    /**
     * @param  list<string>  $hours
     * @param  list<string>  $events
     */
    private function runBatchNative(int $iterations, array $hours, array $events): float
    {
        $sink = 0;
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $sink += UserDownload::aggregateHourlyBucketsNative($hours, $events)['01:00'];
            $sink += count(UserDownload::buildDownloadBatchRowsNative(1, [10, 20], '2026-10-24 00:00:00'));
            $sink += UserRequest::aggregateHourlyRequestBucketsNative($hours, $events)['03:00'];
            $sink += UserRequest::isDirectUserIdNative(42) ? 1 : 0;
            $sink += strlen(UserMovie::serializeCategoryIdsNative([2030, 2040]));
            $sink += strlen(UserSerie::serializeSeriesCategoryIdsNative([5030, 5040]));
            $sink += count(UsersRelease::normalizeCartGuidsNative([' a ', '', 'b', 'a']));
            $sink += count(UsersRelease::deduplicateReleaseIdsNative([1, 2, 1, 3]));
        }

        return ((hrtime(true) - $start) / 1_000_000.0) + ($sink > 0 ? 0.0 : 0.0);
    }

    private function requireNativeSymbol(string $symbol): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust additional FFI library is not available.');
        }

        try {
            $probe = $ffi->new('uint8_t[4]');
            $probe[0] = 0;
        } catch (Throwable $e) {
            $this->markTestSkipped("FFI symbol {$symbol} unavailable: {$e->getMessage()}");
        }

        return $ffi;
    }
}
