<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\IncidentImpactEnum;
use App\Services\Monitoring\Collectors\ApplicationCollector;
use App\Services\Monitoring\Collectors\DatabaseFingerprintCollector;
use App\Services\Monitoring\Collectors\HorizonCollector;
use App\Services\StatusProbes\DatabaseProbe;
use App\Services\StatusProbes\DiskProbe;
use App\Services\StatusProbes\NntpProbe;
use App\Services\StatusProbes\QueueProbe;
use App\Services\StatusProbes\RedisProbe;
use App\Services\StatusProbes\SearchProbe;
use App\Services\StatusProbes\ServiceProbeRegistry;
use App\Support\RustPipelineLibrary;
use App\View\Composers\GlobalDataComposer;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_MONITORING_PROBE_COMPOSER_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib):
 *
 * - Round 1: PHP median = 0.089 ms, Rust FFI median = 0.998 ms (0.09x; per-call FFI marshaling overhead dominates sub-microsecond scalar/array helpers)
 * - Round 2: PHP median = 0.098 ms, Rust FFI median = 0.971 ms (0.10x)
 * - Round 3: PHP median = 0.092 ms, Rust FFI median = 0.975 ms (0.09x)
 * - Overall: PHP stays the production default (`$useRustFfi = false`) across all 5 classes because the 1.5x speedup threshold is not met.
 */
class RustMonitoringProbeComposerParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    ssize_t nntmux_db_fingerprint_job_statuses(
        size_t *out_count,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_db_fingerprint_connection_providers(
        size_t *out_count,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_horizon_should_skip_queue(
        const char *queue_ptr,
        size_t queue_len,
        const char *worker_ptr,
        size_t worker_len
    );
    int32_t nntmux_horizon_any_master_running(
        const char *statuses_ptr,
        size_t statuses_len,
        size_t count
    );
    ssize_t nntmux_application_collector_build_version_labels(
        const char *php_ptr,
        size_t php_len,
        const char *laravel_ptr,
        size_t laravel_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_service_probe_format_unknown_reason(
        const char *id_ptr,
        size_t id_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_global_composer_format_cache_keys(
        int64_t user_id,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_global_composer_should_refresh_last_login(
        int64_t last_login_ts,
        int64_t now_ts
    );
    ssize_t nntmux_global_composer_resolve_display_prefs(
        uint32_t has_theme,
        const char *theme_ptr,
        size_t theme_len,
        uint32_t has_color,
        const char *color_ptr,
        size_t color_len,
        uint32_t logged_in,
        uint32_t is_admin,
        uint32_t is_mod,
        uint32_t *out_flags,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    protected function tearDown(): void
    {
        DatabaseFingerprintCollector::resetRustFfi();
        HorizonCollector::resetRustFfi();
        ApplicationCollector::resetRustFfi();
        ServiceProbeRegistry::resetRustFfi();
        GlobalDataComposer::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        $classes = [
            DatabaseFingerprintCollector::class,
            HorizonCollector::class,
            ApplicationCollector::class,
            ServiceProbeRegistry::class,
            GlobalDataComposer::class,
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

    public function test_database_fingerprint_collector_statuses_and_providers_parity(): void
    {
        $this->requireMetadataFfi();

        $expectedStatuses = ['pending', 'processing', 'complete', 'failed'];
        $phpStatuses = DatabaseFingerprintCollector::fingerprintJobStatusesPhp();
        $nativeStatuses = DatabaseFingerprintCollector::fingerprintJobStatusesNative();
        $this->assertSame($expectedStatuses, $phpStatuses);
        $this->assertSame($phpStatuses, $nativeStatuses);

        $expectedProviders = ['primary', 'alternate'];
        $phpProviders = DatabaseFingerprintCollector::fingerprintConnectionProvidersPhp();
        $nativeProviders = DatabaseFingerprintCollector::fingerprintConnectionProvidersNative();
        $this->assertSame($expectedProviders, $phpProviders);
        $this->assertSame($phpProviders, $nativeProviders);

        foreach ([false, true] as $useRust) {
            DatabaseFingerprintCollector::setUseRustFfi($useRust);
            $this->assertSame($phpStatuses, DatabaseFingerprintCollector::fingerprintJobStatuses());
            $this->assertSame($phpProviders, DatabaseFingerprintCollector::fingerprintConnectionProviders());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function horizonShouldSkipQueueProvider(): array
    {
        return [
            'release_fingerprint_rust' => ['release-fingerprint', 'rust', true],
            'release_fingerprint_php' => ['release-fingerprint', 'php', false],
            'release_fingerprint_horizon' => ['release-fingerprint', 'horizon', false],
            'release_fingerprint_case_mismatch' => ['release-fingerprint', 'RUST', false],
            'default_queue_rust' => ['default', 'rust', false],
            'notifications_queue_rust' => ['notifications', 'rust', false],
            'empty_queue_rust' => ['', 'rust', false],
            'release_fingerprint_empty_worker' => ['release-fingerprint', '', false],
        ];
    }

    #[DataProvider('horizonShouldSkipQueueProvider')]
    public function test_horizon_should_skip_queue_parity(string $queueName, string $fingerprintWorker, bool $expected): void
    {
        $this->requireMetadataFfi();

        $php = HorizonCollector::shouldSkipHorizonQueuePhp($queueName, $fingerprintWorker);
        $native = HorizonCollector::shouldSkipHorizonQueueNative($queueName, $fingerprintWorker);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            HorizonCollector::setUseRustFfi($useRust);
            $this->assertSame($php, HorizonCollector::shouldSkipHorizonQueue($queueName, $fingerprintWorker));
        }
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: bool}>
     */
    public static function horizonMasterRunningProvider(): array
    {
        return [
            'empty_list' => [[], false],
            'single_running_string' => [['running'], true],
            'single_paused_string' => [['paused'], false],
            'mixed_strings_with_running' => [['paused', 'inactive', 'running', 'paused'], true],
            'mixed_strings_without_running' => [['paused', 'inactive', 'terminating'], false],
            'master_objects_with_running' => [[
                (object) ['name' => 'master-1', 'status' => 'paused'],
                (object) ['name' => 'master-2', 'status' => 'running'],
            ], true],
            'master_objects_without_running' => [[
                (object) ['name' => 'master-1', 'status' => 'paused'],
                (object) ['name' => 'master-2'],
            ], false],
            'null_int_bool_and_embedded_nul_elements' => [[
                null,
                1,
                true,
                "running\0extra",
                'RUNNING',
            ], false],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $statuses
     */
    #[DataProvider('horizonMasterRunningProvider')]
    public function test_horizon_any_master_supervisor_running_parity(array $statuses, bool $expected): void
    {
        $this->requireMetadataFfi();

        $php = HorizonCollector::anyMasterSupervisorRunningPhp($statuses);
        $native = HorizonCollector::anyMasterSupervisorRunningNative($statuses);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            HorizonCollector::setUseRustFfi($useRust);
            $this->assertSame($php, HorizonCollector::anyMasterSupervisorRunning($statuses));
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function applicationVersionLabelsProvider(): array
    {
        return [
            'standard_versions' => ['8.5.12', '13.4.1'],
            'prerelease_versions' => ['8.4.0-RC1', '13.0.0-beta.2'],
            'empty_strings' => ['', ''],
            'embedded_nul_bytes' => ["8.5\0custom", "13.0\0custom"],
        ];
    }

    #[DataProvider('applicationVersionLabelsProvider')]
    public function test_application_collector_build_runtime_version_labels_parity(string $phpVersion, string $laravelVersion): void
    {
        $this->requireMetadataFfi();

        $php = ApplicationCollector::buildRuntimeVersionLabelsPhp($phpVersion, $laravelVersion);
        $native = ApplicationCollector::buildRuntimeVersionLabelsNative($phpVersion, $laravelVersion);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            ApplicationCollector::setUseRustFfi($useRust);
            $this->assertSame($php, ApplicationCollector::buildRuntimeVersionLabels($phpVersion, $laravelVersion));
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function serviceProbeReasonProvider(): array
    {
        return [
            'simple_identifier' => ['manticore', 'Unknown probe identifier "manticore"'],
            'hyphenated_identifier' => ['nntp-primary', 'Unknown probe identifier "nntp-primary"'],
            'empty_identifier' => ['', 'Unknown probe identifier ""'],
            'utf8_identifier' => ['प्रोब-1', 'Unknown probe identifier "प्रोब-1"'],
            'embedded_quotes_and_nul' => ["bad\"id\0tail", "Unknown probe identifier \"bad\"id\0tail\""],
        ];
    }

    #[DataProvider('serviceProbeReasonProvider')]
    public function test_service_probe_registry_unknown_reason_and_run_parity(string $identifier, string $expected): void
    {
        $this->requireMetadataFfi();

        $php = ServiceProbeRegistry::formatUnknownProbeReasonPhp($identifier);
        $native = ServiceProbeRegistry::formatUnknownProbeReasonNative($identifier);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        $registry = $this->makeServiceProbeRegistry();

        foreach ([false, true] as $useRust) {
            ServiceProbeRegistry::setUseRustFfi($useRust);
            $this->assertSame($php, ServiceProbeRegistry::formatUnknownProbeReason($identifier));

            $result = $registry->run($identifier);
            $this->assertFalse($result->ok);
            $this->assertSame(0, $result->responseTimeMs);
            $this->assertSame(IncidentImpactEnum::Major, $result->impact);
            $this->assertSame($expected, $result->reason);
        }
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function globalComposerUserIdProvider(): array
    {
        return [
            'zero' => [0],
            'positive_id' => [42],
            'large_id' => [9876543210],
            'negative_id' => [-15],
        ];
    }

    #[DataProvider('globalComposerUserIdProvider')]
    public function test_global_data_composer_format_user_cache_keys_parity(int $userId): void
    {
        $this->requireMetadataFfi();

        $php = GlobalDataComposer::formatUserCacheKeysPhp($userId);
        $native = GlobalDataComposer::formatUserCacheKeysNative($userId);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            GlobalDataComposer::setUseRustFfi($useRust);
            $this->assertSame($php, GlobalDataComposer::formatUserCacheKeys($userId));
        }
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: bool}>
     */
    public static function globalComposerLastLoginProvider(): array
    {
        return [
            'more_than_three_hours_ago' => [1_700_000_000, 1_700_010_801, true],
            'exact_three_hours_boundary' => [1_700_000_000, 1_700_010_800, false],
            'less_than_three_hours_ago' => [1_700_005_000, 1_700_010_800, false],
            'future_last_login' => [1_700_020_000, 1_700_010_800, false],
            'zero_last_login' => [0, 10_801, true],
            'zero_last_login_within_window' => [0, 10_800, false],
        ];
    }

    #[DataProvider('globalComposerLastLoginProvider')]
    public function test_global_data_composer_should_refresh_last_login_parity(int $lastLoginTs, int $nowTs, bool $expected): void
    {
        $this->requireMetadataFfi();

        $php = GlobalDataComposer::shouldRefreshLastLoginPhp($lastLoginTs, $nowTs);
        $native = GlobalDataComposer::shouldRefreshLastLoginNative($lastLoginTs, $nowTs);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            GlobalDataComposer::setUseRustFfi($useRust);
            $this->assertSame($php, GlobalDataComposer::shouldRefreshLastLogin($lastLoginTs, $nowTs));
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: bool, 3: bool, 4: bool}>
     */
    public static function globalComposerDisplayPreferencesProvider(): array
    {
        return [
            'guest_defaults' => [null, null, false, false, false],
            'guest_ignores_admin_mod_and_custom_theme' => ['dark', 'emerald', false, true, true],
            'logged_in_null_preferences_use_defaults' => [null, null, true, false, false],
            'logged_in_custom_preferences_admin' => ['dark', 'emerald', true, true, false],
            'logged_in_custom_preferences_moderator' => ['system', 'indigo', true, false, true],
            'logged_in_admin_and_moderator' => ['oled', 'rose', true, true, true],
            'logged_in_empty_string_preferences_preserved' => ['', '', true, false, false],
        ];
    }

    #[DataProvider('globalComposerDisplayPreferencesProvider')]
    public function test_global_data_composer_resolve_user_display_preferences_parity(
        ?string $theme,
        ?string $colorScheme,
        bool $loggedIn,
        bool $isAdmin,
        bool $isMod,
    ): void {
        $this->requireMetadataFfi();

        $php = GlobalDataComposer::resolveUserDisplayPreferencesPhp($theme, $colorScheme, $loggedIn, $isAdmin, $isMod);
        $native = GlobalDataComposer::resolveUserDisplayPreferencesNative($theme, $colorScheme, $loggedIn, $isAdmin, $isMod);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            GlobalDataComposer::setUseRustFfi($useRust);
            $this->assertSame(
                $php,
                GlobalDataComposer::resolveUserDisplayPreferences($theme, $colorScheme, $loggedIn, $isAdmin, $isMod)
            );
        }
    }

    public function test_all_five_classes_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        DatabaseFingerprintCollector::setFfiForTesting(false);
        DatabaseFingerprintCollector::enableRustFfi();
        $this->assertSame(['pending', 'processing', 'complete', 'failed'], DatabaseFingerprintCollector::fingerprintJobStatuses());
        $this->assertSame(['primary', 'alternate'], DatabaseFingerprintCollector::fingerprintConnectionProviders());

        HorizonCollector::setFfiForTesting(false);
        HorizonCollector::enableRustFfi();
        $this->assertTrue(HorizonCollector::shouldSkipHorizonQueue('release-fingerprint', 'rust'));
        $this->assertTrue(HorizonCollector::anyMasterSupervisorRunning(['paused', 'running']));

        ApplicationCollector::setFfiForTesting(false);
        ApplicationCollector::enableRustFfi();
        $this->assertSame(
            ['php_version' => '8.5.12', 'laravel_version' => '13.0.0'],
            ApplicationCollector::buildRuntimeVersionLabels('8.5.12', '13.0.0')
        );

        ServiceProbeRegistry::setFfiForTesting(false);
        ServiceProbeRegistry::enableRustFfi();
        $this->assertSame('Unknown probe identifier "redis-alt"', ServiceProbeRegistry::formatUnknownProbeReason('redis-alt'));

        GlobalDataComposer::setFfiForTesting(false);
        GlobalDataComposer::enableRustFfi();
        $this->assertSame(
            GlobalDataComposer::formatUserCacheKeysPhp(99),
            GlobalDataComposer::formatUserCacheKeys(99)
        );
        $this->assertTrue(GlobalDataComposer::shouldRefreshLastLogin(100, 20_000));
        $this->assertSame(
            GlobalDataComposer::resolveUserDisplayPreferencesPhp('dark', 'slate', true, true, false),
            GlobalDataComposer::resolveUserDisplayPreferences('dark', 'slate', true, true, false)
        );
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_flags_and_undersized_buffers(): void
    {
        $ffi = $this->requireMetadataFfi();

        $raw = $ffi->new('uint8_t[128]');
        $unalignedSizeT = $ffi->cast('size_t *', FFI::addr($raw[1]));
        $unalignedUint32 = $ffi->cast('uint32_t *', FFI::addr($raw[1]));

        $smallBuf = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $smallBuf[$i] = 'Z';
        }
        $countSlot = $ffi->new('size_t[1]');
        $countSlot[0] = 777;
        $lens4 = $ffi->new('size_t[4]');
        $lens3 = $ffi->new('size_t[3]');
        $lens2 = $ffi->new('size_t[2]');
        $flags3 = $ffi->new('uint32_t[3]');

        // 1. nntmux_db_fingerprint_job_statuses & nntmux_db_fingerprint_connection_providers
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_db_fingerprint_job_statuses', $unalignedSizeT, $smallBuf, 4));
        $rcStatusesSmall = (int) self::callFfi($ffi, 'nntmux_db_fingerprint_job_statuses', $countSlot, $smallBuf, 4);
        $this->assertSame(-34, $rcStatusesSmall);
        $this->assertSame(777, (int) $countSlot[0]);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_db_fingerprint_connection_providers', $unalignedSizeT, $smallBuf, 4));
        $rcProvidersSmall = (int) self::callFfi($ffi, 'nntmux_db_fingerprint_connection_providers', $countSlot, $smallBuf, 4);
        $this->assertSame(-17, $rcProvidersSmall);
        $this->assertSame(777, (int) $countSlot[0]);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 2. nntmux_horizon_should_skip_queue & nntmux_horizon_any_master_running
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_horizon_should_skip_queue', null, 4, 'rust', 4));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_horizon_any_master_running', null, 7, 1));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_horizon_any_master_running', 'running', 7, 2));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_horizon_any_master_running', 'running', 7, 0));

        // 3. nntmux_application_collector_build_version_labels
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_application_collector_build_version_labels',
            '8.5',
            3,
            '13.0',
            4,
            $unalignedSizeT,
            $smallBuf,
            4
        ));
        $rcLabelsSmall = (int) self::callFfi(
            $ffi,
            'nntmux_application_collector_build_version_labels',
            '8.5',
            3,
            '13.0',
            4,
            $lens4,
            $smallBuf,
            4
        );
        $this->assertLessThan(-1, $rcLabelsSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 4. nntmux_service_probe_format_unknown_reason
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_service_probe_format_unknown_reason', null, 3, $smallBuf, 4));
        $rcReasonSmall = (int) self::callFfi($ffi, 'nntmux_service_probe_format_unknown_reason', 'db', 2, $smallBuf, 4);
        $this->assertLessThan(-1, $rcReasonSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 5. nntmux_global_composer_format_cache_keys, should_refresh_last_login, resolve_display_prefs
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_global_composer_format_cache_keys', 42, $unalignedSizeT, $smallBuf, 4));
        $rcCacheSmall = (int) self::callFfi($ffi, 'nntmux_global_composer_format_cache_keys', 42, $lens3, $smallBuf, 4);
        $this->assertLessThan(-1, $rcCacheSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_global_composer_should_refresh_last_login', 0, PHP_INT_MIN));

        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_global_composer_resolve_display_prefs',
            2,
            '',
            0,
            0,
            '',
            0,
            0,
            0,
            0,
            $flags3,
            $lens2,
            $smallBuf,
            4
        ));
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_global_composer_resolve_display_prefs',
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            0,
            0,
            $unalignedUint32,
            $lens2,
            $smallBuf,
            4
        ));
        $rcPrefsSmall = (int) self::callFfi(
            $ffi,
            'nntmux_global_composer_resolve_display_prefs',
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            0,
            0,
            $flags3,
            $lens2,
            $smallBuf,
            4
        );
        $this->assertSame(-9, $rcPrefsSmall);
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_MONITORING_PROBE_COMPOSER_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_MONITORING_PROBE_COMPOSER_BENCH=1 to run the paired benchmark.');
        }

        $this->requireMetadataFfi();

        $runBatch = static function (int $iterations): void {
            for ($i = 0; $i < $iterations; $i++) {
                DatabaseFingerprintCollector::fingerprintJobStatuses();
                DatabaseFingerprintCollector::fingerprintConnectionProviders();
                HorizonCollector::shouldSkipHorizonQueue('release-fingerprint', 'rust');
                HorizonCollector::anyMasterSupervisorRunning(['paused', 'running']);
                ApplicationCollector::buildRuntimeVersionLabels('8.5.12', '13.4.1');
                ServiceProbeRegistry::formatUnknownProbeReason('manticore');
                GlobalDataComposer::formatUserCacheKeys(42);
                GlobalDataComposer::shouldRefreshLastLogin(1_700_000_000, 1_700_020_000);
                GlobalDataComposer::resolveUserDisplayPreferences('dark', 'emerald', true, true, false);
            }
        };

        $setAllModes = static function (bool $rust): void {
            DatabaseFingerprintCollector::setUseRustFfi($rust);
            HorizonCollector::setUseRustFfi($rust);
            ApplicationCollector::setUseRustFfi($rust);
            ServiceProbeRegistry::setUseRustFfi($rust);
            GlobalDataComposer::setUseRustFfi($rust);
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

        fwrite(STDERR, "\n[Wave 24.5 Benchmark] ".implode(' | ', $summary)."\n");
        $this->assertCount(3, $summary);
    }

    private function makeServiceProbeRegistry(): ServiceProbeRegistry
    {
        $db = $this->createMock(DatabaseProbe::class);
        $db->method('identifier')->willReturn('database');

        $redis = $this->createMock(RedisProbe::class);
        $redis->method('identifier')->willReturn('redis');

        $search = $this->createMock(SearchProbe::class);
        $search->method('identifier')->willReturn('search');

        $nntp = $this->createMock(NntpProbe::class);
        $nntp->method('identifier')->willReturn('nntp');

        $queue = $this->createMock(QueueProbe::class);
        $queue->method('identifier')->willReturn('queue');

        $disk = $this->createMock(DiskProbe::class);
        $disk->method('identifier')->willReturn('disk');

        return new ServiceProbeRegistry($db, $redis, $search, $nntp, $queue, $disk);
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
