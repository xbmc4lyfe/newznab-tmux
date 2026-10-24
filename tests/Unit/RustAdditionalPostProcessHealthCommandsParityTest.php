<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\AdditionalProcessingDiagnose;
use App\Console\Commands\CheckServiceHealth;
use App\Console\Commands\FetchSamples;
use App\Console\Commands\PostProcessGuid;
use App\Console\Commands\ProcessAdditionalGuid;
use FFI;
use FFI\CData;
use Tests\TestCase;

final class RustAdditionalPostProcessHealthCommandsParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_additional_diagnose_backlog_rows(
    int64_t total,
    int64_t available,
    int64_t active_claims,
    int64_t stale_claims,
    uint8_t *out_buf,
    size_t out_cap,
    int64_t *out_lens
);
ssize_t nntmux_process_additional_validate_identifier(
    uint8_t has_guid,
    const char *guid_ptr,
    size_t guid_len,
    uint8_t has_id,
    const char *id_ptr,
    size_t id_len,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_process_additional_reset_flags(int64_t *out_scalars);
int32_t nntmux_postprocess_guid_is_valid_hex_char(const char *value_ptr, size_t value_len);
int64_t nntmux_postprocess_guid_effective_batch_timeout(int64_t child_timeout);
ssize_t nntmux_postprocess_guid_normalize_release_ids(
    const int64_t *ids_ptr,
    size_t ids_count,
    int64_t *out_ids,
    size_t out_cap
);
ssize_t nntmux_fetch_samples_parse_category_ids(
    uint8_t has_option,
    const char *option_ptr,
    size_t option_len,
    int64_t *out_ids,
    size_t out_cap
);
ssize_t nntmux_fetch_samples_format_completion_summary(
    int64_t succeeded,
    int64_t failed,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_check_service_health_is_valid_app_url(const char *url_ptr, size_t url_len);
ssize_t nntmux_check_service_health_summary_row(
    const char *name_ptr,
    size_t name_len,
    const char *label_ptr,
    size_t label_len,
    double uptime_percentage,
    uint8_t has_last_checked,
    const char *checked_ptr,
    size_t checked_len,
    uint8_t *out_buf,
    size_t out_cap,
    int64_t *out_lens
);
CDEF;

    /**
     * @var list<class-string>
     */
    private const COMMANDS = [
        AdditionalProcessingDiagnose::class,
        ProcessAdditionalGuid::class,
        PostProcessGuid::class,
        FetchSamples::class,
        CheckServiceHealth::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }
        parent::tearDown();
    }

    public function test_production_defaults_keep_rust_ffi_disabled_for_all_wave30_6_commands(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to PHP in production");
            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_additional_processing_diagnose_helpers_parity(): void
    {
        $this->loadFfi();

        $backlogs = [
            ['total' => 0, 'available' => 0, 'active_claims' => 0, 'stale_claims' => 0],
            ['total' => 1500, 'available' => 900, 'active_claims' => 12, 'stale_claims' => 3, 'buckets' => []],
            ['total' => PHP_INT_MAX, 'available' => PHP_INT_MIN, 'active_claims' => -1, 'stale_claims' => 7],
            ['total' => '42', 'available' => 1.5, 'active_claims' => true, 'stale_claims' => null],
        ];
        foreach ($backlogs as $backlog) {
            AdditionalProcessingDiagnose::disableRustFfi();
            $phpRows = AdditionalProcessingDiagnose::projectBacklogRows($backlog);
            AdditionalProcessingDiagnose::setUseRustFfi(true);
            $rustRows = AdditionalProcessingDiagnose::projectBacklogRows($backlog);
            $this->assertSame($phpRows, $rustRows);
            $this->assertSame(AdditionalProcessingDiagnose::projectBacklogRowsPhp($backlog), $phpRows);
            $this->assertSame(AdditionalProcessingDiagnose::projectBacklogRowsNative($backlog), $rustRows);
        }
        $this->assertSame(
            [['Total', '1500'], ['Available', '900'], ['Active claims', '12'], ['Stale claims', '3']],
            AdditionalProcessingDiagnose::projectBacklogRowsNative($backlogs[1])
        );
        $this->assertRustLibraryLoaded(AdditionalProcessingDiagnose::class);

        $reports = [
            [
                'database_driver' => 'mysql',
                'indexes' => ['claim_queue_index' => 'ix_releases_additional_claim_queue'],
                'temp_path' => ['path' => '/var/tmp/nntmux', 'exists' => true, 'writable' => true],
            ],
            [
                'database_driver' => 'sqlite',
                'indexes' => ['claim_queue_index' => null],
                'temp_path' => ['path' => '', 'exists' => false, 'writable' => false],
            ],
            [
                'database_driver' => "my\0sql",
                'indexes' => ['claim_queue_index' => ''],
                'temp_path' => ['path' => "/tmp/ünïcødé\0dir", 'writable' => false],
            ],
            [
                'database_driver' => 'mariadb',
                'indexes' => [],
                'temp_path' => ['path' => '/tmp', 'writable' => 1],
            ],
        ];
        foreach ($reports as $report) {
            AdditionalProcessingDiagnose::disableRustFfi();
            $phpRows = AdditionalProcessingDiagnose::projectPreflightRows($report);
            AdditionalProcessingDiagnose::setUseRustFfi(true);
            $rustRows = AdditionalProcessingDiagnose::projectPreflightRows($report);
            $this->assertSame($phpRows, $rustRows);
            $this->assertSame(AdditionalProcessingDiagnose::projectPreflightRowsPhp($report), $phpRows);
            $this->assertSame(AdditionalProcessingDiagnose::projectPreflightRowsNative($report), $rustRows);
        }
        $this->assertSame(
            ['Claim queue index', 'missing'],
            AdditionalProcessingDiagnose::projectPreflightRowsNative($reports[1])[1]
        );

        $warnings = [
            ['stale_claims', '3 additional-processing claim(s) are stale and eligible for recovery.'],
            ['', ''],
            ["co\0de", "méssage\0with nul"],
        ];
        foreach ($warnings as [$code, $message]) {
            AdditionalProcessingDiagnose::disableRustFfi();
            $phpLine = AdditionalProcessingDiagnose::formatWarningLine($code, $message);
            AdditionalProcessingDiagnose::setUseRustFfi(true);
            $rustLine = AdditionalProcessingDiagnose::formatWarningLine($code, $message);
            $this->assertSame($phpLine, $rustLine);
            $this->assertSame(AdditionalProcessingDiagnose::formatWarningLinePhp($code, $message), $phpLine);
            $this->assertSame(AdditionalProcessingDiagnose::formatWarningLineNative($code, $message), $rustLine);
        }
    }

    public function test_process_additional_guid_helpers_parity(): void
    {
        $this->loadFfi();

        $cases = [
            [null, null],
            ['', ''],
            ["  \t\0\x0B", null],
            ['abc123', null],
            ["  abc123\n", ''],
            ["gu\0id", null],
            ['abc123', '42'],
            [null, '42'],
            [null, '0'],
            [null, '007'],
            [null, '99999999999999999999'],
            [null, '-5'],
            [null, '4.2'],
            [null, ' 42'],
            [null, "4\0"],
            [null, '٤٢'],
            ['', 'abc'],
        ];
        foreach ($cases as [$guid, $id]) {
            ProcessAdditionalGuid::disableRustFfi();
            $phpResult = ProcessAdditionalGuid::validateIdentifierInput($guid, $id);
            ProcessAdditionalGuid::setUseRustFfi(true);
            $rustResult = ProcessAdditionalGuid::validateIdentifierInput($guid, $id);
            $this->assertSame($phpResult, $rustResult);
            $this->assertSame(ProcessAdditionalGuid::validateIdentifierInputPhp($guid, $id), $phpResult);
            $this->assertSame(ProcessAdditionalGuid::validateIdentifierInputNative($guid, $id), $rustResult);
        }

        $this->assertSame(1, ProcessAdditionalGuid::validateIdentifierInputNative(null, '')['exit_code']);
        $this->assertSame(4, ProcessAdditionalGuid::validateIdentifierInputNative('abc', '1')['exit_code']);
        $this->assertSame(5, ProcessAdditionalGuid::validateIdentifierInputNative(null, '1a')['exit_code']);
        $this->assertSame(
            ['valid' => true, 'mode' => 'id', 'id' => PHP_INT_MAX, 'guid' => null, 'exit_code' => 0, 'error' => null],
            ProcessAdditionalGuid::validateIdentifierInputNative(null, '99999999999999999999')
        );
        $this->assertSame(
            ['valid' => true, 'mode' => 'guid', 'id' => null, 'guid' => 'abc', 'exit_code' => 0, 'error' => null],
            ProcessAdditionalGuid::validateIdentifierInputNative(" abc\t", null)
        );

        ProcessAdditionalGuid::disableRustFfi();
        $phpFlags = ProcessAdditionalGuid::buildResetFlagsPayload();
        ProcessAdditionalGuid::setUseRustFfi(true);
        $rustFlags = ProcessAdditionalGuid::buildResetFlagsPayload();
        $this->assertSame($phpFlags, $rustFlags);
        $this->assertSame(
            ['passwordstatus' => -1, 'haspreview' => -1, 'archive_retry_at' => null, 'jpgstatus' => 0, 'videostatus' => 0, 'nfostatus' => -1],
            ProcessAdditionalGuid::buildResetFlagsPayloadNative()
        );
        $this->assertRustLibraryLoaded(ProcessAdditionalGuid::class);
    }

    public function test_post_process_guid_helpers_parity(): void
    {
        $this->loadFfi();

        foreach (['a', 'f', '0', '9', 'g', 'A', 'F', 'ab', '', ' a', "a\0", "\0", 'é', '-1'] as $char) {
            PostProcessGuid::disableRustFfi();
            $php = PostProcessGuid::isValidHexChar($char);
            PostProcessGuid::setUseRustFfi(true);
            $rust = PostProcessGuid::isValidHexChar($char);
            $this->assertSame($php, $rust, 'hex char '.bin2hex($char));
            $this->assertSame(PostProcessGuid::isValidHexCharPhp($char), PostProcessGuid::isValidHexCharNative($char));
        }

        $types = ['additional', 'nfo', 'movie', 'tv', 'anime', 'books', 'music', 'console', 'games', 'Games', 'tv ', '', "nfo\0", 'xxx', 'mövie'];
        foreach ($types as $type) {
            PostProcessGuid::disableRustFfi();
            $php = PostProcessGuid::isValidPostProcessType($type);
            PostProcessGuid::setUseRustFfi(true);
            $rust = PostProcessGuid::isValidPostProcessType($type);
            $this->assertSame($php, $rust, 'type '.bin2hex($type));
            $this->assertSame(PostProcessGuid::isValidPostProcessTypePhp($type), PostProcessGuid::isValidPostProcessTypeNative($type));
        }

        foreach ([PHP_INT_MIN, -100, 0, 1, 30, 31, 32, 1800, PHP_INT_MAX] as $timeout) {
            PostProcessGuid::disableRustFfi();
            $php = PostProcessGuid::computeEffectiveBatchTimeout($timeout);
            PostProcessGuid::setUseRustFfi(true);
            $rust = PostProcessGuid::computeEffectiveBatchTimeout($timeout);
            $this->assertSame($php, $rust, "timeout {$timeout}");
            $this->assertSame(PostProcessGuid::computeEffectiveBatchTimeoutPhp($timeout), PostProcessGuid::computeEffectiveBatchTimeoutNative($timeout));
        }

        $idSets = [
            [],
            [5, 3, 5, 7, 3],
            [0, -1, PHP_INT_MIN, PHP_INT_MAX, 1, PHP_INT_MAX],
            ['12', '12abc', 4.9, true, null, 12],
            [10 => 4, 'x' => 4, 2 => 8],
        ];
        foreach ($idSets as $ids) {
            PostProcessGuid::disableRustFfi();
            $php = PostProcessGuid::normalizeWorkerReleaseIds($ids);
            PostProcessGuid::setUseRustFfi(true);
            $rust = PostProcessGuid::normalizeWorkerReleaseIds($ids);
            $this->assertSame($php, $rust);
            $this->assertSame(PostProcessGuid::normalizeWorkerReleaseIdsPhp($ids), PostProcessGuid::normalizeWorkerReleaseIdsNative($ids));
        }
        $this->assertSame([5, 3, 7], PostProcessGuid::normalizeWorkerReleaseIdsNative([5, 3, 5, 7, 3]));
        $this->assertRustLibraryLoaded(PostProcessGuid::class);
    }

    public function test_fetch_samples_helpers_parity(): void
    {
        $this->loadFfi();

        $options = [
            null,
            '',
            '   ',
            '2010',
            '2010,2020',
            ' 2010 , 2020,,2030 ',
            "2010\t2020\n2030\r2040\x0B2050\x0C2060",
            "\0 2010\0,2020",
            '2010,abc,2020x,-5,4.5,2010',
            '0007,7,0',
            '99999999999999999999,9223372036854775807',
            "2010\xA02020",
            '٢٠١٠,2020',
            ',,,',
        ];
        foreach ($options as $option) {
            FetchSamples::disableRustFfi();
            $php = FetchSamples::parseCategoryIdsOption($option);
            FetchSamples::setUseRustFfi(true);
            $rust = FetchSamples::parseCategoryIdsOption($option);
            $this->assertSame($php, $rust, 'option '.bin2hex((string) $option));
            $this->assertSame(FetchSamples::parseCategoryIdsOptionPhp($option), FetchSamples::parseCategoryIdsOptionNative($option));
        }
        $this->assertSame([7, 0], FetchSamples::parseCategoryIdsOptionNative('0007,7,0'));
        $this->assertRustLibraryLoaded(FetchSamples::class);

        $totals = [[0, 0], [0, 10], [5, 10], [10, 5], [-1, 10], [PHP_INT_MAX, 3], [PHP_INT_MIN, 3], [3, -1]];
        foreach ($totals as [$limit, $totalAll]) {
            FetchSamples::disableRustFfi();
            $php = FetchSamples::computeEffectiveTotal($limit, $totalAll);
            FetchSamples::setUseRustFfi(true);
            $rust = FetchSamples::computeEffectiveTotal($limit, $totalAll);
            $this->assertSame($php, $rust);
            $this->assertSame(FetchSamples::computeEffectiveTotalPhp($limit, $totalAll), FetchSamples::computeEffectiveTotalNative($limit, $totalAll));
        }

        foreach ([[0, 0], [12, 3], [-1, PHP_INT_MIN], [PHP_INT_MAX, 0]] as [$succeeded, $failed]) {
            FetchSamples::disableRustFfi();
            $php = FetchSamples::formatCompletionSummary($succeeded, $failed);
            FetchSamples::setUseRustFfi(true);
            $rust = FetchSamples::formatCompletionSummary($succeeded, $failed);
            $this->assertSame($php, $rust);
            $this->assertSame(FetchSamples::formatCompletionSummaryPhp($succeeded, $failed), FetchSamples::formatCompletionSummaryNative($succeeded, $failed));
        }
    }

    public function test_check_service_health_helpers_parity(): void
    {
        $this->loadFfi();

        $urls = ['', '/', '///', 'http', 'https://example.test', 'https://example.test///', 'HTTP://example.test', 'ftp://x', ' http://x', "http\0://x", 'httpü'];
        foreach ($urls as $url) {
            CheckServiceHealth::disableRustFfi();
            $php = CheckServiceHealth::isValidAppUrl($url);
            CheckServiceHealth::setUseRustFfi(true);
            $rust = CheckServiceHealth::isValidAppUrl($url);
            $this->assertSame($php, $rust, 'url '.bin2hex($url));
            $this->assertSame(CheckServiceHealth::isValidAppUrlPhp($url), CheckServiceHealth::isValidAppUrlNative($url));
        }

        $results = [
            ['API', 'http', ['ok' => true, 'status_code' => 200, 'response_time_ms' => 125, 'impact' => null, 'reason' => 'OK']],
            ['Probe', 'probe', ['ok' => false, 'status_code' => 0, 'response_time_ms' => 0, 'impact' => null, 'reason' => 'Probe identifier not configured']],
            ['Web', 'http', ['ok' => false, 'status_code' => 503, 'response_time_ms' => -4, 'reason' => '']],
            ["Ünï\0code", 'hTTp-ß', ['ok' => false, 'status_code' => -1, 'response_time_ms' => PHP_INT_MAX, 'reason' => "dówn\0now"]],
            ['Mixed', 'http', ['ok' => 1, 'status_code' => '200', 'response_time_ms' => 1.5, 'reason' => null]],
            ['Null reason', 'http', ['ok' => false, 'status_code' => 0, 'response_time_ms' => 0, 'reason' => null]],
        ];
        foreach ($results as [$name, $type, $result]) {
            CheckServiceHealth::disableRustFfi();
            $php = CheckServiceHealth::projectHealthResultRow($name, $type, $result);
            CheckServiceHealth::setUseRustFfi(true);
            $rust = CheckServiceHealth::projectHealthResultRow($name, $type, $result);
            $this->assertSame($php, $rust, "row {$name}");
            $this->assertSame(CheckServiceHealth::projectHealthResultRowPhp($name, $type, $result), CheckServiceHealth::projectHealthResultRowNative($name, $type, $result));
        }
        $this->assertSame(
            ['Probe', 'PROBE', '—', '—', 'Probe identifier not configured'],
            CheckServiceHealth::projectHealthResultRowNative('Probe', 'probe', $results[1][2])
        );

        $uptimes = [0.0, 100.0, 99.95, 99.999, 99.994, 0.125, 1.005, -0.004, -12.346, 1234567.891, 1e15, -1e15, NAN, INF, -INF, 0.1 + 0.2];
        foreach ($uptimes as $uptime) {
            foreach ([null, '5 minutes ago', "ünï\0code"] as $checked) {
                CheckServiceHealth::disableRustFfi();
                $php = CheckServiceHealth::projectServiceSummaryRow('API', 'Operational', $uptime, $checked);
                CheckServiceHealth::setUseRustFfi(true);
                $rust = CheckServiceHealth::projectServiceSummaryRow('API', 'Operational', $uptime, $checked);
                $this->assertSame($php, $rust, 'uptime '.var_export($uptime, true));
                $this->assertSame(
                    CheckServiceHealth::projectServiceSummaryRowPhp('API', 'Operational', $uptime, $checked),
                    CheckServiceHealth::projectServiceSummaryRowNative('API', 'Operational', $uptime, $checked)
                );
            }
        }
        $this->assertSame(
            ['API', 'Degraded', '1,234,567.89%', 'never'],
            CheckServiceHealth::projectServiceSummaryRowNative('API', 'Degraded', 1234567.891, null)
        );
        $this->assertRustLibraryLoaded(CheckServiceHealth::class);
    }

    public function test_fallback_when_ffi_is_disabled_for_testing(): void
    {
        foreach (self::COMMANDS as $class) {
            $class::setUseRustFfi(true);
            $class::setFfiForTesting(false);
        }

        $backlog = ['total' => 1, 'available' => 2, 'active_claims' => 3, 'stale_claims' => 4];
        $this->assertSame(AdditionalProcessingDiagnose::projectBacklogRowsPhp($backlog), AdditionalProcessingDiagnose::projectBacklogRows($backlog));
        $this->assertSame(AdditionalProcessingDiagnose::formatWarningLinePhp('c', 'm'), AdditionalProcessingDiagnose::formatWarningLine('c', 'm'));
        $this->assertSame(ProcessAdditionalGuid::validateIdentifierInputPhp(null, '42'), ProcessAdditionalGuid::validateIdentifierInput(null, '42'));
        $this->assertSame(ProcessAdditionalGuid::buildResetFlagsPayloadPhp(), ProcessAdditionalGuid::buildResetFlagsPayload());
        $this->assertSame(PostProcessGuid::isValidHexCharPhp('a'), PostProcessGuid::isValidHexChar('a'));
        $this->assertSame(PostProcessGuid::normalizeWorkerReleaseIdsPhp([3, 3, 1]), PostProcessGuid::normalizeWorkerReleaseIds([3, 3, 1]));
        $this->assertSame(FetchSamples::parseCategoryIdsOptionPhp('1,2'), FetchSamples::parseCategoryIdsOption('1,2'));
        $this->assertSame(CheckServiceHealth::isValidAppUrlPhp('http://x'), CheckServiceHealth::isValidAppUrl('http://x'));
        $this->assertSame(
            CheckServiceHealth::projectServiceSummaryRowPhp('API', 'Up', 99.5, null),
            CheckServiceHealth::projectServiceSummaryRow('API', 'Up', 99.5, null)
        );
    }

    public function test_direct_c_abi_results_and_buffer_safety(): void
    {
        $ffi = $this->loadFfi();
        $buf = $ffi->new('uint8_t[128]');
        $lens = $ffi->new('int64_t[8]');
        $this->assertInstanceOf(CData::class, $buf);
        $this->assertInstanceOf(CData::class, $lens);

        $rawBytes = $ffi->new('uint8_t[64]');
        $this->assertInstanceOf(CData::class, $rawBytes);
        $misalignedI64 = $ffi->cast('int64_t *', FFI::addr($rawBytes[1]));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_additional_diagnose_backlog_rows(1, 2, 3, 4, $buf, 128, $misalignedI64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_process_additional_reset_flags($misalignedI64));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_process_additional_validate_identifier(1, 'abc', 3, 0, '', 0, $misalignedI64, $buf, 128));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_fetch_samples_parse_category_ids(1, '1,2', 3, $misalignedI64, 2));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_postprocess_guid_is_valid_hex_char(null, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_check_service_health_is_valid_app_url(null, 0));

        FFI::memset($buf, 0xA5, 128);
        // @phpstan-ignore method.notFound
        $needed = (int) $ffi->nntmux_fetch_samples_format_completion_summary(1, 2, $buf, 4);
        $this->assertLessThan(-4, $needed);
        $this->assertSame(str_repeat("\xA5", 128), FFI::string($buf, 128));

        $ids = $ffi->new('int64_t[3]');
        $out = $ffi->new('int64_t[2]');
        $ids[0] = 1;
        $ids[1] = 2;
        $ids[2] = 3;
        // @phpstan-ignore method.notFound
        $this->assertSame(-3, (int) $ffi->nntmux_postprocess_guid_normalize_release_ids($ids, 3, $out, 2));

        // @phpstan-ignore method.notFound
        $this->assertSame(1, (int) $ffi->nntmux_postprocess_guid_effective_batch_timeout(PHP_INT_MIN));

        // Near-tie and non-finite uptimes are left to PHP's number_format().
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, (int) $ffi->nntmux_check_service_health_summary_row('a', 1, 'b', 1, 0.125, 0, '', 0, $buf, 128, $lens));
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, (int) $ffi->nntmux_check_service_health_summary_row('a', 1, 'b', 1, NAN, 0, '', 0, $buf, 128, $lens));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired benchmark (3 rounds x 41 samples x 200 iterations, warmup=5, alternating order) of
        // the full helper workload below, PHP 8.5.12 on Apple Silicon macOS with the release dylib
        // (`NNTMUX_PRINT_BENCHMARK=1` prints the numbers):
        // - Round 1: PHP p50 = 0.314 ms (p99 = 0.479 ms), Rust FFI p50 = 1.509 ms (p99 = 1.738 ms) -> p50 0.21x, p99 0.28x
        // - Round 2: PHP p50 = 0.310 ms (p99 = 0.391 ms), Rust FFI p50 = 1.450 ms (p99 = 1.814 ms) -> p50 0.21x, p99 0.22x
        // - Round 3: PHP p50 = 0.311 ms (p99 = 0.420 ms), Rust FFI p50 = 1.463 ms (p99 = 1.760 ms) -> p50 0.21x, p99 0.24x
        // FFI marshaling overhead exceeds the sub-microsecond PHP work (< 1.5x threshold in
        // `.ai/rules/rust-performance.md`), so `usesRustFfi()` stays `false` by default for all
        // 5 commands.
        $this->loadFfi();
        $warmup = 5;
        $samples = 41;
        $iterations = 200;

        $sink = 0;
        for ($w = 0; $w < $warmup; $w++) {
            $sink += count($this->runWorkloadOnce(false));
            $sink += count($this->runWorkloadOnce(true));
        }

        $phpTimes = [];
        $rustTimes = [];
        for ($s = 0; $s < $samples; $s++) {
            foreach ((($s & 1) === 1) ? [true, false] : [false, true] as $useRust) {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce($useRust));
                }
                $elapsed = (hrtime(true) - $t0) / 1_000_000;
                if ($useRust) {
                    $rustTimes[] = $elapsed;
                } else {
                    $phpTimes[] = $elapsed;
                }
            }
        }

        sort($phpTimes);
        sort($rustTimes);
        $p50Php = $phpTimes[(int) floor($samples * 0.50)];
        $p50Rust = $rustTimes[(int) floor($samples * 0.50)];
        $p99Php = $phpTimes[(int) min($samples - 1, floor($samples * 0.99))];
        $p99Rust = $rustTimes[(int) min($samples - 1, floor($samples * 0.99))];

        if (getenv('NNTMUX_PRINT_BENCHMARK') === '1') {
            fwrite(STDERR, sprintf(
                "\nwave30.6 PHP p50=%.3fms p99=%.3fms | Rust p50=%.3fms p99=%.3fms | p50 %.2fx p99 %.2fx\n",
                $p50Php,
                $p99Php,
                $p50Rust,
                $p99Rust,
                $p50Php / $p50Rust,
                $p99Php / $p99Rust
            ));
        }

        $this->assertGreaterThan(0, $sink);
        $this->assertGreaterThan(0.0, $p50Php);
        $this->assertGreaterThan(0.0, $p50Rust);
    }

    /**
     * @return array<int, mixed>
     */
    private function runWorkloadOnce(bool $useRust): array
    {
        $backlog = ['total' => 1500, 'available' => 900, 'active_claims' => 12, 'stale_claims' => 3];
        $report = [
            'database_driver' => 'mysql',
            'indexes' => ['claim_queue_index' => 'ix_releases_additional_claim_queue'],
            'temp_path' => ['path' => '/var/tmp/nntmux', 'writable' => true],
        ];
        $health = ['ok' => true, 'status_code' => 200, 'response_time_ms' => 125, 'reason' => 'OK'];

        if ($useRust) {
            return [
                AdditionalProcessingDiagnose::projectBacklogRowsNative($backlog),
                AdditionalProcessingDiagnose::projectPreflightRowsNative($report),
                AdditionalProcessingDiagnose::formatWarningLineNative('stale_claims', '3 claims are stale.'),
                ProcessAdditionalGuid::validateIdentifierInputNative('0123456789abcdef', null),
                ProcessAdditionalGuid::buildResetFlagsPayloadNative(),
                PostProcessGuid::isValidHexCharNative('a'),
                PostProcessGuid::isValidPostProcessTypeNative('additional'),
                PostProcessGuid::computeEffectiveBatchTimeoutNative(1800),
                PostProcessGuid::normalizeWorkerReleaseIdsNative([101, 102, 103, 101, 104]),
                FetchSamples::parseCategoryIdsOptionNative('2010, 2020, 2030'),
                FetchSamples::computeEffectiveTotalNative(100, 2500),
                FetchSamples::formatCompletionSummaryNative(98, 2),
                CheckServiceHealth::isValidAppUrlNative('https://example.test/'),
                CheckServiceHealth::projectHealthResultRowNative('API', 'http', $health),
                CheckServiceHealth::projectServiceSummaryRowNative('API', 'Operational', 99.95, '5 minutes ago'),
            ];
        }

        return [
            AdditionalProcessingDiagnose::projectBacklogRowsPhp($backlog),
            AdditionalProcessingDiagnose::projectPreflightRowsPhp($report),
            AdditionalProcessingDiagnose::formatWarningLinePhp('stale_claims', '3 claims are stale.'),
            ProcessAdditionalGuid::validateIdentifierInputPhp('0123456789abcdef', null),
            ProcessAdditionalGuid::buildResetFlagsPayloadPhp(),
            PostProcessGuid::isValidHexCharPhp('a'),
            PostProcessGuid::isValidPostProcessTypePhp('additional'),
            PostProcessGuid::computeEffectiveBatchTimeoutPhp(1800),
            PostProcessGuid::normalizeWorkerReleaseIdsPhp([101, 102, 103, 101, 104]),
            FetchSamples::parseCategoryIdsOptionPhp('2010, 2020, 2030'),
            FetchSamples::computeEffectiveTotalPhp(100, 2500),
            FetchSamples::formatCompletionSummaryPhp(98, 2),
            CheckServiceHealth::isValidAppUrlPhp('https://example.test/'),
            CheckServiceHealth::projectHealthResultRowPhp('API', 'http', $health),
            CheckServiceHealth::projectServiceSummaryRowPhp('API', 'Operational', 99.95, '5 minutes ago'),
        ];
    }

    /**
     * Guards against parity passing only because the native path silently fell back to PHP.
     *
     * @param  class-string  $class
     */
    private function assertRustLibraryLoaded(string $class): void
    {
        $property = new \ReflectionProperty($class, 'ffi');
        $this->assertInstanceOf(FFI::class, $property->getValue(), "{$class} did not load the Rust library");
    }

    private function loadFfi(): FFI
    {
        $candidates = [];
        $envPath = getenv('NNTMUX_PIPELINE_FFI_LIB');
        if (is_string($envPath) && $envPath !== '') {
            $candidates[] = $envPath;
        }

        $base = base_path();
        $ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $candidates[] = $base."/storage/app/nntmux-native/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/release/libnntmux_pipeline.{$ext}";
        $candidates[] = $base."/rust/pipeline-ffi/target/debug/libnntmux_pipeline.{$ext}";

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return FFI::cdef(self::FFI_CDEF, $path);
            }
        }

        $this->markTestSkipped('libnntmux_pipeline shared library not found.');
    }
}
