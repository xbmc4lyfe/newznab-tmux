<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Genre;
use App\Models\Payment;
use App\Models\RolePromotionStat;
use App\Models\SystemMetric;
use App\Models\UserRoleHistory;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_ROLE_PAYMENT_GENRE_METRIC_MODEL_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib):
 *
 * - Round 1: PHP median = 0.186 ms, Rust FFI median = 0.779 ms (0.24x; per-call FFI marshaling overhead dominates sub-microsecond scalar/array model helpers)
 * - Round 2: PHP median = 0.188 ms, Rust FFI median = 0.783 ms (0.24x)
 * - Round 3: PHP median = 0.187 ms, Rust FFI median = 0.779 ms (0.24x)
 * - Overall: PHP stays the production default (`$useRustFfi = false`) across all 5 model classes because the 1.5x speedup threshold is not met.
 */
class RustRolePaymentGenreMetricModelParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    int32_t nntmux_role_promotion_stat_summarize(
        size_t count,
        const int64_t *user_ids_ptr,
        const int64_t *role_ids_ptr,
        const int64_t *days_added_ptr,
        int64_t *out_summary
    );
    ssize_t nntmux_user_role_history_pack_scalar_fields(
        int64_t user_id,
        int32_t has_old_role_id,
        int64_t old_role_id,
        int64_t new_role_id,
        int32_t is_stacked,
        int32_t has_changed_by,
        int64_t changed_by,
        int32_t has_reason,
        const char *reason_ptr,
        size_t reason_len,
        int64_t *out_scalars,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_payment_normalize_filter_spec(
        uint32_t has_username,
        const char *username_ptr,
        size_t username_len,
        uint32_t has_email,
        const char *email_ptr,
        size_t email_len,
        uint32_t has_payment_status,
        const char *payment_status_ptr,
        size_t payment_status_len,
        uint32_t has_invoice_status,
        const char *invoice_status_ptr,
        size_t invoice_status_len,
        uint32_t has_start_date,
        uint32_t has_end_date,
        size_t *out_meta,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_genre_has_type_filter(
        const char *type_ptr,
        size_t type_len
    );
    ssize_t nntmux_genre_build_active_sql(
        uint32_t has_type,
        int64_t type_int,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_system_metric_period_cutoff_ts(
        int64_t now_ts,
        int64_t hours,
        int64_t *out_ts
    );
    int32_t nntmux_system_metric_cleanup_cutoff_ts(
        int64_t now_ts,
        int64_t days,
        int64_t *out_ts
    );
    ssize_t nntmux_system_metric_normalize_type(
        const char *type_ptr,
        size_t type_len,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    protected function tearDown(): void
    {
        RolePromotionStat::resetRustFfi();
        UserRoleHistory::resetRustFfi();
        Payment::resetRustFfi();
        Genre::resetRustFfi();
        SystemMetric::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        $classes = [
            RolePromotionStat::class,
            UserRoleHistory::class,
            Payment::class,
            Genre::class,
            SystemMetric::class,
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
     * @return array<string, array{0: array<int, array<string, mixed>|object>}>
     */
    public static function rolePromotionStatsProvider(): array
    {
        $t1 = Carbon::parse('2026-01-10 12:00:00');
        $t2 = Carbon::parse('2026-02-15 18:30:00');
        $t3 = Carbon::parse('2026-03-01 09:15:00');

        return [
            'empty_rows' => [[]],
            'single_array_row' => [[
                ['user_id' => 10, 'role_id' => 2, 'days_added' => 30, 'applied_at' => $t1],
            ]],
            'multiple_rows_with_duplicates_and_objects' => [[
                ['user_id' => 10, 'role_id' => 2, 'days_added' => 14, 'applied_at' => $t2],
                (object) ['user_id' => 20, 'role_id' => 3, 'days_added' => 30, 'applied_at' => $t1],
                ['user_id' => 10, 'role_id' => 2, 'days_added' => 7, 'applied_at' => $t3],
                (object) ['user_id' => 30, 'role_id' => 2, 'days_added' => 60, 'applied_at' => $t2],
            ]],
            'rows_with_null_applied_at_and_zero_days' => [[
                ['user_id' => 1, 'role_id' => 1, 'days_added' => 0, 'applied_at' => null],
                ['user_id' => 2, 'role_id' => 1, 'days_added' => -5, 'applied_at' => null],
            ]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>|object>  $rows
     */
    #[DataProvider('rolePromotionStatsProvider')]
    public function test_role_promotion_stat_summarize_parity(array $rows): void
    {
        $this->requireMetadataFfi();

        $php = RolePromotionStat::summarizePromotionStatsPhp($rows);
        $native = RolePromotionStat::summarizePromotionStatsNative($rows);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            RolePromotionStat::setUseRustFfi($useRust);
            $this->assertSame($php, RolePromotionStat::summarizePromotionStats($rows));
        }
    }

    /**
     * @return array<string, array{0: int, 1: ?int, 2: int, 3: ?Carbon, 4: ?Carbon, 5: Carbon, 6: bool, 7: ?string, 8: ?int}>
     */
    public static function userRoleHistoryAttributesProvider(): array
    {
        $oldExp = Carbon::parse('2026-04-01 00:00:00');
        $newExp = Carbon::parse('2027-04-01 00:00:00');
        $eff = Carbon::parse('2026-03-15 10:00:00');

        return [
            'full_stacked_upgrade' => [42, 2, 3, $oldExp, $newExp, $eff, true, 'VIP annual renewal', 1],
            'initial_assignment_nulls' => [105, null, 2, null, $newExp, $eff, false, null, null],
            'empty_reason_string' => [77, 4, 2, $oldExp, null, $eff, false, '', 99],
            'utf8_and_embedded_nul_reason' => [88, 1, 5, $oldExp, $newExp, $eff, true, "Promo — 日本語\0note", 12],
        ];
    }

    #[DataProvider('userRoleHistoryAttributesProvider')]
    public function test_user_role_history_build_attributes_parity(
        int $userId,
        ?int $oldRoleId,
        int $newRoleId,
        ?Carbon $oldExpiryDate,
        ?Carbon $newExpiryDate,
        Carbon $effectiveDate,
        bool $isStacked,
        ?string $changeReason,
        ?int $changedBy,
    ): void {
        $this->requireMetadataFfi();

        $php = UserRoleHistory::buildRoleChangeAttributesPhp(
            $userId,
            $oldRoleId,
            $newRoleId,
            $oldExpiryDate,
            $newExpiryDate,
            $effectiveDate,
            $isStacked,
            $changeReason,
            $changedBy
        );
        $native = UserRoleHistory::buildRoleChangeAttributesNative(
            $userId,
            $oldRoleId,
            $newRoleId,
            $oldExpiryDate,
            $newExpiryDate,
            $effectiveDate,
            $isStacked,
            $changeReason,
            $changedBy
        );
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            UserRoleHistory::setUseRustFfi($useRust);
            $this->assertSame(
                $php,
                UserRoleHistory::buildRoleChangeAttributes(
                    $userId,
                    $oldRoleId,
                    $newRoleId,
                    $oldExpiryDate,
                    $newExpiryDate,
                    $effectiveDate,
                    $isStacked,
                    $changeReason,
                    $changedBy
                )
            );
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function paymentFilterSpecProvider(): array
    {
        return [
            'empty_filters' => [[]],
            'all_filters_pending_invoice' => [[
                'username' => 'alice',
                'email' => 'alice@example.com',
                'payment_status' => Payment::PAYMENT_STATUS_SETTLED,
                'invoice_status' => Payment::INVOICE_STATUS_PENDING,
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
            ]],
            'settled_invoice_exact_mode' => [[
                'username' => '',
                'email' => 'bob@example.org',
                'payment_status' => 'Processing',
                'invoice_status' => Payment::INVOICE_STATUS_SETTLED,
            ]],
            'empty_and_zero_strings_treated_as_empty' => [[
                'username' => '0',
                'email' => '',
                'payment_status' => '0',
                'invoice_status' => '',
                'start_date' => '',
                'end_date' => null,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    #[DataProvider('paymentFilterSpecProvider')]
    public function test_payment_normalize_filter_spec_parity(array $filters): void
    {
        $this->requireMetadataFfi();

        $php = Payment::normalizeFilterSpecPhp($filters);
        $native = Payment::normalizeFilterSpecNative($filters);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            Payment::setUseRustFfi($useRust);
            $this->assertSame($php, Payment::normalizeFilterSpec($filters));
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function genreTypeProvider(): array
    {
        return [
            'empty_type' => ['', false],
            'zero_string_type' => ['0', false],
            'music_type_3000' => ['3000', true],
            'console_type_1000' => ['1000', true],
            'games_type_4050' => ['4050', true],
            'negative_type' => ['-10', true],
            'non_numeric_non_empty_type' => ['music', true],
        ];
    }

    #[DataProvider('genreTypeProvider')]
    public function test_genre_has_type_filter_and_active_sql_parity(string $type, bool $expectedHasType): void
    {
        $this->requireMetadataFfi();

        $phpHas = Genre::hasTypeFilterPhp($type);
        $nativeHas = Genre::hasTypeFilterNative($type);
        $this->assertSame($expectedHasType, $phpHas);
        $this->assertSame($phpHas, $nativeHas);

        $phpSql = Genre::buildActiveGenresSqlPhp($type);
        $nativeSql = Genre::buildActiveGenresSqlNative($type);
        $this->assertSame($phpSql, $nativeSql);

        foreach ([false, true] as $useRust) {
            Genre::setUseRustFfi($useRust);
            $this->assertSame($phpHas, Genre::hasTypeFilter($type));
            $this->assertSame($phpSql, Genre::buildActiveGenresSql($type));
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: string, 4: float}>
     */
    public static function systemMetricProvider(): array
    {
        return [
            'standard_cpu_24h_60d' => [1_700_000_000, 24, 60, 'cpu', 42.5],
            'ram_trimmed_uppercase' => [1_700_500_000, 1, 7, "  RAM \t\n", 78.125],
            'zero_window' => [1_700_000_000, 0, 0, '  Disk_IO ', 0.0],
            'negative_offset' => [1_700_000_000, -2, -5, '', 99.9],
        ];
    }

    #[DataProvider('systemMetricProvider')]
    public function test_system_metric_cutoffs_and_normalize_attributes_parity(
        int $nowTs,
        int $hours,
        int $days,
        string $metricType,
        float $value,
    ): void {
        $this->requireMetadataFfi();

        $phpPeriod = SystemMetric::computePeriodCutoffTimestampPhp($nowTs, $hours);
        $nativePeriod = SystemMetric::computePeriodCutoffTimestampNative($nowTs, $hours);
        $this->assertSame($phpPeriod, $nativePeriod);

        $phpCleanup = SystemMetric::computeCleanupCutoffTimestampPhp($nowTs, $days);
        $nativeCleanup = SystemMetric::computeCleanupCutoffTimestampNative($nowTs, $days);
        $this->assertSame($phpCleanup, $nativeCleanup);

        $recordedAt = Carbon::createFromTimestampUTC($nowTs);
        $phpAttrs = SystemMetric::normalizeMetricAttributesPhp($metricType, $value, $recordedAt);
        $nativeAttrs = SystemMetric::normalizeMetricAttributesNative($metricType, $value, $recordedAt);
        $this->assertSame($phpAttrs, $nativeAttrs);

        foreach ([false, true] as $useRust) {
            SystemMetric::setUseRustFfi($useRust);
            $this->assertSame($phpPeriod, SystemMetric::computePeriodCutoffTimestamp($nowTs, $hours));
            $this->assertSame($phpCleanup, SystemMetric::computeCleanupCutoffTimestamp($nowTs, $days));
            $this->assertSame($phpAttrs, SystemMetric::normalizeMetricAttributes($metricType, $value, $recordedAt));
        }
    }

    public function test_all_five_models_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        RolePromotionStat::setFfiForTesting(false);
        RolePromotionStat::enableRustFfi();
        $rows = [['user_id' => 1, 'role_id' => 2, 'days_added' => 10, 'applied_at' => '2026-01-01']];
        $this->assertSame(
            RolePromotionStat::summarizePromotionStatsPhp($rows),
            RolePromotionStat::summarizePromotionStats($rows)
        );

        UserRoleHistory::setFfiForTesting(false);
        UserRoleHistory::enableRustFfi();
        $eff = Carbon::parse('2026-01-01 00:00:00');
        $this->assertSame(
            UserRoleHistory::buildRoleChangeAttributesPhp(1, 2, 3, null, null, $eff, true, 'test', 9),
            UserRoleHistory::buildRoleChangeAttributes(1, 2, 3, null, null, $eff, true, 'test', 9)
        );

        Payment::setFfiForTesting(false);
        Payment::enableRustFfi();
        $filters = ['username' => 'john', 'invoice_status' => Payment::INVOICE_STATUS_PENDING];
        $this->assertSame(
            Payment::normalizeFilterSpecPhp($filters),
            Payment::normalizeFilterSpec($filters)
        );

        Genre::setFfiForTesting(false);
        Genre::enableRustFfi();
        $this->assertTrue(Genre::hasTypeFilter('3000'));
        $this->assertSame(
            Genre::buildActiveGenresSqlPhp('3000'),
            Genre::buildActiveGenresSql('3000')
        );

        SystemMetric::setFfiForTesting(false);
        SystemMetric::enableRustFfi();
        $this->assertSame(100_000 - 3600, SystemMetric::computePeriodCutoffTimestamp(100_000, 1));
        $this->assertSame(100_000 - 86400, SystemMetric::computeCleanupCutoffTimestamp(100_000, 1));
        $this->assertSame(
            SystemMetric::normalizeMetricAttributesPhp(' CPU ', 55.0, null),
            SystemMetric::normalizeMetricAttributes(' CPU ', 55.0, null)
        );
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

        // 1. nntmux_role_promotion_stat_summarize
        $one = $ffi->new('int64_t[1]');
        $one[0] = 10;
        $summarySlot = $ffi->new('int64_t[4]');
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_role_promotion_stat_summarize', 1, $unalignedConstInt64, $one, $one, $summarySlot));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_role_promotion_stat_summarize', 1, $one, $one, $one, $unalignedInt64));

        // 2. nntmux_user_role_history_pack_scalar_fields
        $scalars8 = $ffi->new('int64_t[8]');
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_user_role_history_pack_scalar_fields',
            1,
            2,
            0,
            2,
            0,
            0,
            0,
            0,
            '',
            0,
            $scalars8,
            $smallBuf,
            4
        ));
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_user_role_history_pack_scalar_fields',
            1,
            0,
            0,
            2,
            0,
            0,
            0,
            0,
            '',
            0,
            $unalignedInt64,
            $smallBuf,
            4
        ));
        $rcHistSmall = (int) self::callFfi(
            $ffi,
            'nntmux_user_role_history_pack_scalar_fields',
            1,
            0,
            0,
            2,
            0,
            0,
            0,
            1,
            'reason-too-long',
            15,
            $scalars8,
            $smallBuf,
            4
        );
        $this->assertSame(-15, $rcHistSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 3. nntmux_payment_normalize_filter_spec
        $meta10 = $ffi->new('size_t[10]');
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_payment_normalize_filter_spec',
            2,
            '',
            0,
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            0,
            $meta10,
            $smallBuf,
            4
        ));
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_payment_normalize_filter_spec',
            1,
            'alice',
            5,
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            0,
            $unalignedSizeT,
            $smallBuf,
            4
        ));
        $rcPaySmall = (int) self::callFfi(
            $ffi,
            'nntmux_payment_normalize_filter_spec',
            1,
            'alice',
            5,
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            0,
            $meta10,
            $smallBuf,
            4
        );
        $this->assertSame(-7, $rcPaySmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 4. nntmux_genre_has_type_filter & nntmux_genre_build_active_sql
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_genre_has_type_filter', null, 3));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_genre_build_active_sql', 2, 0, $smallBuf, 4));
        $rcGenreSmall = (int) self::callFfi($ffi, 'nntmux_genre_build_active_sql', 0, 0, $smallBuf, 4);
        $this->assertLessThan(-1, $rcGenreSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 5. nntmux_system_metric_period_cutoff_ts, cleanup_cutoff_ts, normalize_type
        $outTs = $ffi->new('int64_t[1]');
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_system_metric_period_cutoff_ts', 100, 1, $unalignedInt64));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_system_metric_period_cutoff_ts', 0, PHP_INT_MAX, $outTs));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_system_metric_cleanup_cutoff_ts', 100, 1, $unalignedInt64));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_system_metric_cleanup_cutoff_ts', 0, PHP_INT_MAX, $outTs));
        $rcMetricSmall = (int) self::callFfi($ffi, 'nntmux_system_metric_normalize_type', '  memory_usage  ', 16, $smallBuf, 4);
        $this->assertSame(-12, $rcMetricSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_ROLE_PAYMENT_GENRE_METRIC_MODEL_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_ROLE_PAYMENT_GENRE_METRIC_MODEL_BENCH=1 to run the paired benchmark.');
        }

        $this->requireMetadataFfi();

        $statRows = [
            ['user_id' => 10, 'role_id' => 2, 'days_added' => 14, 'applied_at' => '2026-01-01'],
            ['user_id' => 20, 'role_id' => 3, 'days_added' => 30, 'applied_at' => '2026-01-05'],
            ['user_id' => 10, 'role_id' => 2, 'days_added' => 7, 'applied_at' => '2026-01-10'],
        ];
        $effDate = Carbon::parse('2026-03-01 00:00:00');
        $paymentFilters = [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'payment_status' => Payment::PAYMENT_STATUS_SETTLED,
            'invoice_status' => Payment::INVOICE_STATUS_PENDING,
        ];

        $runBatch = static function (int $iterations) use ($statRows, $effDate, $paymentFilters): void {
            for ($i = 0; $i < $iterations; $i++) {
                RolePromotionStat::summarizePromotionStats($statRows);
                UserRoleHistory::buildRoleChangeAttributes(42, 2, 3, null, null, $effDate, true, 'promo', 1);
                Payment::normalizeFilterSpec($paymentFilters);
                Genre::hasTypeFilter('3000');
                Genre::buildActiveGenresSql('3000');
                SystemMetric::computePeriodCutoffTimestamp(1_700_000_000, 24);
                SystemMetric::computeCleanupCutoffTimestamp(1_700_000_000, 60);
                SystemMetric::normalizeMetricAttributes(' CPU ', 42.5, $effDate);
            }
        };

        $setAllModes = static function (bool $rust): void {
            RolePromotionStat::setUseRustFfi($rust);
            UserRoleHistory::setUseRustFfi($rust);
            Payment::setUseRustFfi($rust);
            Genre::setUseRustFfi($rust);
            SystemMetric::setUseRustFfi($rust);
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

        fwrite(STDERR, "\n[Wave 25.4 Benchmark] ".implode(' | ', $summary)."\n");
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
