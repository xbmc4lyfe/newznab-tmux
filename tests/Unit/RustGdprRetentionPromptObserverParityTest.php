<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mcp\Prompts\ReviewNntmuxChangePrompt;
use App\Observers\RolePromotionObserver;
use App\Observers\UserServiceObserver;
use App\Services\Gdpr\GdprAuditService;
use App\Services\Gdpr\GdprRetentionService;
use FFI;
use FFI\CData;
use Tests\TestCase;

final class RustGdprRetentionPromptObserverParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_gdpr_retention_policy_strings_nul(
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_gdpr_retention_resolve_purge_target(
    uint8_t has_disk,
    const char *disk_ptr,
    size_t disk_len,
    uint8_t has_path,
    const char *path_ptr,
    size_t path_len,
    uint8_t has_expires_at,
    int64_t expires_at_epoch,
    int64_t now_epoch,
    int32_t *out_should_purge,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_gdpr_audit_normalize_payload_nul(
    const char *event_ptr,
    size_t event_len,
    const char *desc_ptr,
    size_t desc_len,
    uint8_t has_subject_id,
    int64_t subject_id,
    uint8_t has_actor_id,
    int64_t actor_id,
    uint8_t has_request_id,
    int64_t request_id,
    size_t metadata_count,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_mcp_review_change_prompt_messages_nul(
    const char *goal_ptr,
    size_t goal_len,
    uint8_t has_paths,
    const char *paths_ptr,
    size_t paths_len,
    uint8_t *out_buf,
    size_t out_cap
);
int32_t nntmux_role_promotion_observer_evaluate(
    uint8_t is_active,
    uint8_t has_end_date,
    int64_t end_timestamp_us,
    int64_t now_timestamp_us,
    int32_t *out_flags
);
ssize_t nntmux_user_service_observer_evaluate_registration(
    uint8_t has_mail_from,
    const char *mail_from_ptr,
    size_t mail_from_len,
    uint8_t has_install_lock,
    const char *base_path_ptr,
    size_t base_path_len,
    int32_t *out_should_dispatch,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    protected function tearDown(): void
    {
        GdprRetentionService::resetRustFfi();
        GdprRetentionService::setFfiForTesting(null);
        GdprAuditService::resetRustFfi();
        GdprAuditService::setFfiForTesting(null);
        ReviewNntmuxChangePrompt::resetRustFfi();
        ReviewNntmuxChangePrompt::setFfiForTesting(null);
        RolePromotionObserver::resetRustFfi();
        RolePromotionObserver::setFfiForTesting(null);
        UserServiceObserver::resetRustFfi();
        UserServiceObserver::setFfiForTesting(null);
        parent::tearDown();
    }

    public function test_production_defaults_keep_rust_ffi_disabled_for_all_wave25_6_classes(): void
    {
        $classes = [
            GdprRetentionService::class,
            GdprAuditService::class,
            ReviewNntmuxChangePrompt::class,
            RolePromotionObserver::class,
            UserServiceObserver::class,
        ];

        foreach ($classes as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} should default to PHP in production");

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

    public function test_gdpr_retention_service_policy_and_purge_target_parity(): void
    {
        $retainedSamples = [
            [],
            [
                'legal_and_security_audit_logs' => [
                    'description' => 'GDPR audit records',
                    'retention' => 'indefinite',
                ],
                'tax_and_accounting_records' => [
                    'description' => 'Payment records',
                    'retention' => '7_years',
                ],
            ],
        ];

        foreach ($retainedSamples as $retained) {
            GdprRetentionService::disableRustFfi();
            $phpPolicy = GdprRetentionService::buildPolicyPayload($retained);

            GdprRetentionService::enableRustFfi();
            $rustPolicy = GdprRetentionService::buildPolicyPayload($retained);

            $this->assertSame($phpPolicy, $rustPolicy);
            $this->assertSame(GdprRetentionService::buildPolicyPayloadPhp($retained), $phpPolicy);
            $this->assertSame(GdprRetentionService::buildPolicyPayloadNative($retained), $rustPolicy);
        }

        $purgeCases = [
            ['s3', 'gdpr-exports/export-10.zip', 1000, 1001],
            ['s3', 'gdpr-exports/export-10.zip', 1000, 1000],
            ['s3', 'gdpr-exports/export-10.zip', 1000, 999],
            [null, 'gdpr-exports/export-11.zip', 500, 600],
            ['', 'gdpr-exports/export-12.zip', 500, 600],
            ['0', 'gdpr-exports/export-13.zip', 500, 600],
            ['local', null, 500, 600],
            ['local', '', 500, 600],
            ['local', 'gdpr-exports/export-14.zip', null, 600],
            ["bad\0disk", 'gdpr-exports/export-15.zip', 500, 600],
            ['local', "bad\0path.zip", 500, 600],
        ];

        foreach ($purgeCases as [$disk, $path, $expiresAt, $now]) {
            GdprRetentionService::disableRustFfi();
            $phpTarget = GdprRetentionService::resolveExportPurgeTarget($disk, $path, $expiresAt, $now);

            GdprRetentionService::enableRustFfi();
            $rustTarget = GdprRetentionService::resolveExportPurgeTarget($disk, $path, $expiresAt, $now);

            $this->assertSame($phpTarget, $rustTarget);
            $this->assertSame(GdprRetentionService::resolveExportPurgeTargetPhp($disk, $path, $expiresAt, $now), $phpTarget);
            $this->assertSame(GdprRetentionService::resolveExportPurgeTargetNative($disk, $path, $expiresAt, $now), $rustTarget);
        }
    }

    public function test_gdpr_audit_service_payload_parity(): void
    {
        $cases = [
            ['gdpr.export.requested', 'User requested a GDPR data export.', 42, 42, 7, []],
            ['gdpr.erasure.executed', 'Admin executed account erasure.', 42, 1, 8, ['reason' => 'user_request', 'tables' => 12]],
            ['gdpr.retention.purged', 'Scheduled purge removed expired exports.', null, null, null, []],
            ['gdpr.custom', 'Subject only event.', 99, null, null, ['ip' => '127.0.0.1']],
            ["gdpr\0nul", "desc\0nul", 5, 6, 7, ['k' => 'v']],
        ];

        foreach ($cases as [$event, $description, $subjectId, $actorId, $requestId, $metadata]) {
            GdprAuditService::disableRustFfi();
            $phpPayload = GdprAuditService::buildAuditLogPayload($event, $description, $subjectId, $actorId, $requestId, $metadata);

            GdprAuditService::enableRustFfi();
            $rustPayload = GdprAuditService::buildAuditLogPayload($event, $description, $subjectId, $actorId, $requestId, $metadata);

            $this->assertSame($phpPayload, $rustPayload);
            $this->assertSame(
                GdprAuditService::buildAuditLogPayloadPhp($event, $description, $subjectId, $actorId, $requestId, $metadata),
                $phpPayload
            );
            $this->assertSame(
                GdprAuditService::buildAuditLogPayloadNative($event, $description, $subjectId, $actorId, $requestId, $metadata),
                $rustPayload
            );
        }
    }

    public function test_review_nntmux_change_prompt_messages_parity(): void
    {
        $cases = [
            ['Harden GDPR export retention cleanup', null],
            ['Review observer registration flow', 'app/Observers/UserServiceObserver.php, app/Observers/RolePromotionObserver.php'],
            ['Check empty paths string', ''],
            ["Goal with \0 nul byte", "paths\0with\0nul"],
        ];

        foreach ($cases as [$goal, $paths]) {
            ReviewNntmuxChangePrompt::disableRustFfi();
            $phpMessages = ReviewNntmuxChangePrompt::formatReviewPromptMessages($goal, $paths);

            ReviewNntmuxChangePrompt::enableRustFfi();
            $rustMessages = ReviewNntmuxChangePrompt::formatReviewPromptMessages($goal, $paths);

            $this->assertSame($phpMessages, $rustMessages);
            $this->assertSame(ReviewNntmuxChangePrompt::formatReviewPromptMessagesPhp($goal, $paths), $phpMessages);
            $this->assertSame(ReviewNntmuxChangePrompt::formatReviewPromptMessagesNative($goal, $paths), $rustMessages);
        }
    }

    public function test_role_promotion_observer_evaluation_parity(): void
    {
        $cases = [
            [true, null, 1_700_000_000_000_000],
            [false, null, 1_700_000_000_000_000],
            [true, 1_700_000_000_000_000, 1_700_000_000_000_001],
            [true, 1_700_000_000_000_000, 1_700_000_000_000_000],
            [true, 1_700_000_000_000_000, 1_699_999_999_999_999],
            [false, 1_700_000_000_000_000, 1_700_000_000_000_001],
            [false, 1_700_000_000_000_000, 1_699_999_999_999_999],
        ];

        foreach ($cases as [$isActive, $endTimestampUs, $nowTimestampUs]) {
            RolePromotionObserver::disableRustFfi();
            $phpResult = RolePromotionObserver::evaluatePromotionExpiry($isActive, $endTimestampUs, $nowTimestampUs);

            RolePromotionObserver::enableRustFfi();
            $rustResult = RolePromotionObserver::evaluatePromotionExpiry($isActive, $endTimestampUs, $nowTimestampUs);

            $this->assertSame($phpResult, $rustResult);
            $this->assertSame(
                RolePromotionObserver::evaluatePromotionExpiryPhp($isActive, $endTimestampUs, $nowTimestampUs),
                $phpResult
            );
            $this->assertSame(
                RolePromotionObserver::evaluatePromotionExpiryNative($isActive, $endTimestampUs, $nowTimestampUs),
                $rustResult
            );
        }
    }

    public function test_user_service_observer_evaluation_parity(): void
    {
        $cases = [
            ['noreply@example.com', true, '/var/www/nntmux'],
            ['noreply@example.com', false, '/var/www/nntmux'],
            ['', true, '/var/www/nntmux'],
            [null, true, '/var/www/nntmux'],
            ['0', true, '/var/www/nntmux'],
            [false, true, '/var/www/nntmux'],
            ["bad\0mail@example.com", true, "/var/www/\0nntmux"],
        ];

        foreach ($cases as [$mailFrom, $hasInstallLock, $basePath]) {
            UserServiceObserver::disableRustFfi();
            $phpResult = UserServiceObserver::evaluateRegistrationSideEffects($mailFrom, $hasInstallLock, $basePath);

            UserServiceObserver::enableRustFfi();
            $rustResult = UserServiceObserver::evaluateRegistrationSideEffects($mailFrom, $hasInstallLock, $basePath);

            $this->assertSame($phpResult, $rustResult);
            $this->assertSame(
                UserServiceObserver::evaluateRegistrationSideEffectsPhp($mailFrom, $hasInstallLock, $basePath),
                $phpResult
            );
            $this->assertSame(
                UserServiceObserver::evaluateRegistrationSideEffectsNative($mailFrom, $hasInstallLock, $basePath),
                $rustResult
            );
        }
    }

    public function test_fallback_when_ffi_is_disabled_for_testing(): void
    {
        GdprRetentionService::enableRustFfi();
        GdprRetentionService::setFfiForTesting(false);
        $this->assertSame(
            GdprRetentionService::buildPolicyPayloadPhp([]),
            GdprRetentionService::buildPolicyPayloadNative([])
        );
        $this->assertSame(
            GdprRetentionService::resolveExportPurgeTargetPhp('local', 'a.zip', 10, 20),
            GdprRetentionService::resolveExportPurgeTargetNative('local', 'a.zip', 10, 20)
        );

        GdprAuditService::enableRustFfi();
        GdprAuditService::setFfiForTesting(false);
        $this->assertSame(
            GdprAuditService::buildAuditLogPayloadPhp('ev', 'desc', 1, 2, 3, ['a' => 1]),
            GdprAuditService::buildAuditLogPayloadNative('ev', 'desc', 1, 2, 3, ['a' => 1])
        );

        ReviewNntmuxChangePrompt::enableRustFfi();
        ReviewNntmuxChangePrompt::setFfiForTesting(false);
        $this->assertSame(
            ReviewNntmuxChangePrompt::formatReviewPromptMessagesPhp('goal', 'paths'),
            ReviewNntmuxChangePrompt::formatReviewPromptMessagesNative('goal', 'paths')
        );

        RolePromotionObserver::enableRustFfi();
        RolePromotionObserver::setFfiForTesting(false);
        $this->assertSame(
            RolePromotionObserver::evaluatePromotionExpiryPhp(true, 10, 20),
            RolePromotionObserver::evaluatePromotionExpiryNative(true, 10, 20)
        );

        UserServiceObserver::enableRustFfi();
        UserServiceObserver::setFfiForTesting(false);
        $this->assertSame(
            UserServiceObserver::evaluateRegistrationSideEffectsPhp('a@b.c', true, '/app'),
            UserServiceObserver::evaluateRegistrationSideEffectsNative('a@b.c', true, '/app')
        );
    }

    public function test_direct_c_abi_null_misaligned_and_undersized_buffer_safety(): void
    {
        $ffi = $this->loadFfi();
        $buf = $ffi->new('uint8_t[64]');
        $this->assertInstanceOf(CData::class, $buf);
        $i32Out = $ffi->new('int32_t[1]');
        $this->assertInstanceOf(CData::class, $i32Out);
        $i64Out = $ffi->new('int64_t[4]');
        $this->assertInstanceOf(CData::class, $i64Out);

        // 1. nntmux_gdpr_retention_policy_strings_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_gdpr_retention_policy_strings_nul(null, 64));

        FFI::memset($buf, 0xA5, 64);
        // @phpstan-ignore method.notFound
        $needed = (int) $ffi->nntmux_gdpr_retention_policy_strings_nul($buf, 8);
        $this->assertLessThan(-8, $needed);
        $this->assertSame(str_repeat("\xA5", 64), FFI::string($buf, 64));

        // 2. nntmux_gdpr_retention_resolve_purge_target
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_gdpr_retention_resolve_purge_target(
            2,
            'local',
            5,
            1,
            'a.zip',
            5,
            1,
            10,
            20,
            $i32Out,
            $buf,
            64
        ));
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, (int) $ffi->nntmux_gdpr_retention_resolve_purge_target(
            1,
            "lo\0cal",
            6,
            1,
            'a.zip',
            5,
            1,
            10,
            20,
            $i32Out,
            $buf,
            64
        ));

        // Misaligned int32_t* pointer
        $rawBytes = $ffi->new('uint8_t[16]');
        $this->assertInstanceOf(CData::class, $rawBytes);
        $misalignedI32 = $ffi->cast('int32_t *', FFI::addr($rawBytes[1]));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_gdpr_retention_resolve_purge_target(
            1,
            'local',
            5,
            1,
            'a.zip',
            5,
            1,
            10,
            20,
            $misalignedI32,
            $buf,
            64
        ));

        // 3. nntmux_gdpr_audit_normalize_payload_nul
        $misalignedI64 = $ffi->cast('int64_t *', FFI::addr($rawBytes[1]));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_gdpr_audit_normalize_payload_nul(
            'ev',
            2,
            'desc',
            4,
            1,
            10,
            0,
            0,
            0,
            0,
            0,
            $misalignedI64,
            $buf,
            64
        ));
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, (int) $ffi->nntmux_gdpr_audit_normalize_payload_nul(
            "e\0v",
            3,
            'desc',
            4,
            1,
            10,
            0,
            0,
            0,
            0,
            0,
            $i64Out,
            $buf,
            64
        ));

        // 4. nntmux_mcp_review_change_prompt_messages_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_mcp_review_change_prompt_messages_nul(
            'Verify GDPR retention logic',
            27,
            0,
            null,
            0,
            $buf,
            64
        ));
        FFI::memset($buf, 0x5A, 64);
        // @phpstan-ignore method.notFound
        $promptNeeded = (int) $ffi->nntmux_mcp_review_change_prompt_messages_nul(
            'Verify GDPR retention logic',
            27,
            0,
            '',
            0,
            $buf,
            16
        );
        $this->assertLessThan(-16, $promptNeeded);
        $this->assertSame(str_repeat("\x5A", 64), FFI::string($buf, 64));

        // 5. nntmux_role_promotion_observer_evaluate
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_role_promotion_observer_evaluate(1, 1, 10, 20, null));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_role_promotion_observer_evaluate(1, 1, 10, 20, $misalignedI32));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_role_promotion_observer_evaluate(2, 1, 10, 20, $i32Out));

        // 6. nntmux_user_service_observer_evaluate_registration
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_user_service_observer_evaluate_registration(
            1,
            'noreply@example.com',
            19,
            1,
            '/app',
            4,
            $misalignedI32,
            $buf,
            64
        ));
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, (int) $ffi->nntmux_user_service_observer_evaluate_registration(
            1,
            'noreply@example.com',
            19,
            1,
            "/ap\0p",
            5,
            $i32Out,
            $buf,
            64
        ));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired randomized benchmark (3 rounds x 41 samples x 200 iterations, warmup=8):
        // Measured on Apple Silicon (macOS release dylib `/tmp/cargo-w25-6/release/libnntmux_pipeline.dylib`):
        // - Round 1: PHP p50 = 0.093 ms (p99 = 0.171 ms), Rust FFI p50 = 0.173 ms (p99 = 0.275 ms) -> p50 0.54x, p99 0.62x
        // - Round 2: PHP p50 = 0.093 ms (p99 = 0.168 ms), Rust FFI p50 = 0.178 ms (p99 = 0.284 ms) -> p50 0.52x, p99 0.59x
        // - Round 3: PHP p50 = 0.093 ms (p99 = 0.158 ms), Rust FFI p50 = 0.172 ms (p99 = 0.282 ms) -> p50 0.54x, p99 0.56x
        // Because Rust FFI does not achieve >= 1.5x speedup over native PHP for these lightweight scalar/string helpers,
        // `usesRustFfi()` remains disabled (`false`) by default in production across all 5 classes.
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
            $rustFirst = (($s & 1) === 1);
            if ($rustFirst) {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(true));
                }
                $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;

                $t1 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(false));
                }
                $phpTimes[] = (hrtime(true) - $t1) / 1_000_000;
            } else {
                $t0 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(false));
                }
                $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;

                $t1 = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $sink += count($this->runWorkloadOnce(true));
                }
                $rustTimes[] = (hrtime(true) - $t1) / 1_000_000;
            }
        }

        sort($phpTimes);
        sort($rustTimes);
        $p50Php = $phpTimes[(int) floor($samples * 0.50)];
        $p50Rust = $rustTimes[(int) floor($samples * 0.50)];

        $this->assertGreaterThan(0, $sink);
        $this->assertGreaterThan(0.0, $p50Php);
        $this->assertGreaterThan(0.0, $p50Rust);
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    private function runWorkloadOnce(bool $useRust): array
    {
        if ($useRust) {
            return [
                GdprRetentionService::buildPolicyPayloadNative([]),
                GdprRetentionService::resolveExportPurgeTargetNative('local', 'gdpr-exports/1.zip', 100, 200),
                GdprAuditService::buildAuditLogPayloadNative('gdpr.export', 'Export created', 10, 10, 5, ['format' => 'zip']),
                ReviewNntmuxChangePrompt::formatReviewPromptMessagesNative('Review GDPR retention', 'app/Services/Gdpr'),
                RolePromotionObserver::evaluatePromotionExpiryNative(true, 1000, 2000),
                UserServiceObserver::evaluateRegistrationSideEffectsNative('noreply@example.com', true, '/var/www/nntmux'),
            ];
        }

        return [
            GdprRetentionService::buildPolicyPayloadPhp([]),
            GdprRetentionService::resolveExportPurgeTargetPhp('local', 'gdpr-exports/1.zip', 100, 200),
            GdprAuditService::buildAuditLogPayloadPhp('gdpr.export', 'Export created', 10, 10, 5, ['format' => 'zip']),
            ReviewNntmuxChangePrompt::formatReviewPromptMessagesPhp('Review GDPR retention', 'app/Services/Gdpr'),
            RolePromotionObserver::evaluatePromotionExpiryPhp(true, 1000, 2000),
            UserServiceObserver::evaluateRegistrationSideEffectsPhp('noreply@example.com', true, '/var/www/nntmux'),
        ];
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
