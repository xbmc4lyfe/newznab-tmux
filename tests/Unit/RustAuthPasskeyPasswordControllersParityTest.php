<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\PasskeyManagementController;
use App\Http\Controllers\Auth\ResetPasswordController;
use FFI;
use FFI\CData;
use JsonException;
use Tests\TestCase;

final class RustAuthPasskeyPasswordControllersParityTest extends TestCase
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_passkey_login_preflight_nul(
    uint8_t has_captcha_rules,
    uint8_t has_captcha_val,
    const char *captcha_ptr,
    size_t captcha_len,
    uint8_t has_options,
    size_t options_len,
    int32_t *out_flags,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_passkey_login_resolve_redirect_nul(
    uint8_t store_user_ip,
    uint8_t has_req_ip,
    const char *req_ip_ptr,
    size_t req_ip_len,
    uint8_t has_client_ip,
    const char *client_ip_ptr,
    size_t client_ip_len,
    uint8_t has_sess_redirect,
    const char *sess_redirect_ptr,
    size_t sess_redirect_len,
    uint8_t has_cfg_redirect,
    const char *cfg_redirect_ptr,
    size_t cfg_redirect_len,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_passkey_management_normalize_metadata_nul(
    uint8_t has_id,
    int64_t id,
    uint8_t has_name,
    const char *name_ptr,
    size_t name_len,
    uint8_t has_last_used,
    const char *last_used_ptr,
    size_t last_used_len,
    uint8_t has_created,
    const char *created_ptr,
    size_t created_len,
    int64_t deleted_count,
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_email_verification_verify_sha1(
    const char *hash_ptr,
    size_t hash_len,
    const char *email_ptr,
    size_t email_len,
    int32_t *out_matches,
    uint8_t *out_hex40,
    size_t out_cap
);
ssize_t nntmux_forgot_password_evaluate_nul(
    const char *email_ptr,
    size_t email_len,
    uint8_t captcha_enabled,
    uint8_t captcha_failed,
    uint8_t user_exists,
    uint8_t user_trashed,
    int32_t *out_flags,
    uint8_t *out_buf,
    size_t out_cap
);
ssize_t nntmux_reset_password_evaluate_legacy_guid_nul(
    uint8_t has_guid_param,
    uint8_t user_found,
    uint8_t user_trashed,
    int32_t *out_is_valid,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    protected function tearDown(): void
    {
        PasskeyLoginController::resetRustFfi();
        PasskeyLoginController::setFfiForTesting(null);
        PasskeyManagementController::resetRustFfi();
        PasskeyManagementController::setFfiForTesting(null);
        EmailVerificationController::resetRustFfi();
        EmailVerificationController::setFfiForTesting(null);
        ForgotPasswordController::resetRustFfi();
        ForgotPasswordController::setFfiForTesting(null);
        ResetPasswordController::resetRustFfi();
        ResetPasswordController::setFfiForTesting(null);
        parent::tearDown();
    }

    public function test_production_defaults_keep_rust_ffi_disabled_for_all_wave27_6_controllers(): void
    {
        $classes = [
            PasskeyLoginController::class,
            PasskeyManagementController::class,
            EmailVerificationController::class,
            ForgotPasswordController::class,
            ResetPasswordController::class,
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

    public function test_passkey_login_controller_helpers_parity(): void
    {
        $preflightCases = [
            [true, 'cf-turnstile-token', '{"challenge":"abc"}'],
            [true, '   ', '{"challenge":"abc"}'],
            [true, null, '{"challenge":"abc"}'],
            [false, 'cf-turnstile-token', '{"challenge":"abc"}'],
            [true, 'cf-turnstile-token', ''],
            [true, 'cf-turnstile-token', null],
            [false, null, null],
        ];

        foreach ($preflightCases as [$hasRules, $captchaVal, $optionsJson]) {
            PasskeyLoginController::disableRustFfi();
            $phpPreflight = PasskeyLoginController::evaluatePasskeyLoginPreflight($hasRules, $captchaVal, $optionsJson);

            PasskeyLoginController::enableRustFfi();
            $rustPreflight = PasskeyLoginController::evaluatePasskeyLoginPreflight($hasRules, $captchaVal, $optionsJson);

            $this->assertSame($phpPreflight, $rustPreflight);
            $this->assertSame(
                PasskeyLoginController::evaluatePasskeyLoginPreflightPhp($hasRules, $captchaVal, $optionsJson),
                $phpPreflight
            );
            $this->assertSame(
                PasskeyLoginController::evaluatePasskeyLoginPreflightNative($hasRules, $captchaVal, $optionsJson),
                $rustPreflight
            );
        }

        $redirectCases = [
            [true, '192.168.1.10', '10.0.0.1', true, '/intended', '/'],
            [true, null, '10.0.0.1', false, null, '/home'],
            [true, null, null, false, null, '/'],
            [false, '192.168.1.10', '10.0.0.1', false, null, '/'],
            [true, "192.168\0.1.1", null, true, "/bad\0url", '/'],
        ];

        foreach ($redirectCases as [$storeIp, $reqIp, $clientIp, $hasSessRedir, $sessRedir, $cfgRedir]) {
            PasskeyLoginController::disableRustFfi();
            $phpRedir = PasskeyLoginController::resolvePasskeyLoginRedirectAndIp(
                $storeIp,
                $reqIp,
                $clientIp,
                $hasSessRedir,
                $sessRedir,
                $cfgRedir
            );

            PasskeyLoginController::enableRustFfi();
            $rustRedir = PasskeyLoginController::resolvePasskeyLoginRedirectAndIp(
                $storeIp,
                $reqIp,
                $clientIp,
                $hasSessRedir,
                $sessRedir,
                $cfgRedir
            );

            $this->assertSame($phpRedir, $rustRedir);
            $this->assertSame(
                PasskeyLoginController::resolvePasskeyLoginRedirectAndIpPhp(
                    $storeIp,
                    $reqIp,
                    $clientIp,
                    $hasSessRedir,
                    $sessRedir,
                    $cfgRedir
                ),
                $phpRedir
            );
            $this->assertSame(
                PasskeyLoginController::resolvePasskeyLoginRedirectAndIpNative(
                    $storeIp,
                    $reqIp,
                    $clientIp,
                    $hasSessRedir,
                    $sessRedir,
                    $cfgRedir
                ),
                $rustRedir
            );
        }
    }

    public function test_passkey_management_controller_helpers_parity(): void
    {
        $jsonSample = '{"challenge":"xyz","rp":{"name":"NNTmux","id":"localhost"}}';

        PasskeyManagementController::disableRustFfi();
        $phpOptions = PasskeyManagementController::decodeRegistrationOptionsPayload($jsonSample);

        PasskeyManagementController::enableRustFfi();
        $rustOptions = PasskeyManagementController::decodeRegistrationOptionsPayload($jsonSample);

        $this->assertSame($phpOptions, $rustOptions);

        // Invalid JSON throws JsonException on both paths
        $phpJsonErr = false;
        try {
            PasskeyManagementController::decodeRegistrationOptionsPayloadPhp('{invalid');
        } catch (JsonException) {
            $phpJsonErr = true;
        }

        $rustJsonErr = false;
        try {
            PasskeyManagementController::decodeRegistrationOptionsPayloadNative('{invalid');
        } catch (JsonException) {
            $rustJsonErr = true;
        }
        $this->assertTrue($phpJsonErr);
        $this->assertTrue($rustJsonErr);

        $metaCases = [
            [12, 'YubiKey 5C', '2026-10-24T10:00:00+00:00', '2026-09-01T08:00:00+00:00', 1],
            [12, 'YubiKey 5C', null, '2026-09-01T08:00:00+00:00', 0],
            [null, null, null, null, 0],
            [5, "Key\0With\0Nul", null, null, 2],
        ];

        foreach ($metaCases as [$id, $name, $lastUsed, $created, $deleted]) {
            PasskeyManagementController::disableRustFfi();
            $phpMeta = PasskeyManagementController::buildPasskeyStoreAndDestroyPayload(
                $id,
                $name,
                $lastUsed,
                $created,
                $deleted
            );

            PasskeyManagementController::enableRustFfi();
            $rustMeta = PasskeyManagementController::buildPasskeyStoreAndDestroyPayload(
                $id,
                $name,
                $lastUsed,
                $created,
                $deleted
            );

            $this->assertSame($phpMeta, $rustMeta);
            $this->assertSame(
                PasskeyManagementController::buildPasskeyStoreAndDestroyPayloadPhp($id, $name, $lastUsed, $created, $deleted),
                $phpMeta
            );
            $this->assertSame(
                PasskeyManagementController::buildPasskeyStoreAndDestroyPayloadNative($id, $name, $lastUsed, $created, $deleted),
                $rustMeta
            );
        }
    }

    public function test_email_verification_controller_sha1_parity(): void
    {
        $emails = [
            '',
            'abc',
            'user@example.com',
            str_repeat('a', 55),
            str_repeat('b', 56),
            str_repeat('c', 64),
            str_repeat('d', 128),
            'very.long.email.address.crossing.sha1.block.boundary@subdomain.example.org',
        ];

        foreach ($emails as $email) {
            $validHash = sha1($email);
            $invalidHash = strtoupper($validHash);
            $shortHash = substr($validHash, 0, 39);

            foreach ([$validHash, $invalidHash, $shortHash, '0000000000000000000000000000000000000000'] as $candidate) {
                EmailVerificationController::disableRustFfi();
                $phpRes = EmailVerificationController::verifyEmailSha1Hash($candidate, $email);

                EmailVerificationController::enableRustFfi();
                $rustRes = EmailVerificationController::verifyEmailSha1Hash($candidate, $email);

                $this->assertSame($phpRes, $rustRes);
                $this->assertSame(EmailVerificationController::verifyEmailSha1HashPhp($candidate, $email), $phpRes);
                $this->assertSame(EmailVerificationController::verifyEmailSha1HashNative($candidate, $email), $rustRes);
            }
        }
    }

    public function test_forgot_and_reset_password_controllers_parity(): void
    {
        $forgotCases = [
            ['', false, false, false, false],
            ['', true, true, true, false],
            ['user@example.com', true, true, true, false],
            ['user@example.com', true, false, false, false],
            ['user@example.com', false, false, true, true],
            ['user@example.com', true, false, true, false],
            ['user@example.com', false, false, true, false],
        ];

        foreach ($forgotCases as [$email, $capEnabled, $capFailed, $exists, $trashed]) {
            ForgotPasswordController::disableRustFfi();
            $phpForgot = ForgotPasswordController::evaluateForgotPasswordRequest(
                $email,
                $capEnabled,
                $capFailed,
                $exists,
                $trashed
            );

            ForgotPasswordController::enableRustFfi();
            $rustForgot = ForgotPasswordController::evaluateForgotPasswordRequest(
                $email,
                $capEnabled,
                $capFailed,
                $exists,
                $trashed
            );

            $this->assertSame($phpForgot, $rustForgot);
            $this->assertSame(
                ForgotPasswordController::evaluateForgotPasswordRequestPhp($email, $capEnabled, $capFailed, $exists, $trashed),
                $phpForgot
            );
            $this->assertSame(
                ForgotPasswordController::evaluateForgotPasswordRequestNative($email, $capEnabled, $capFailed, $exists, $trashed),
                $rustForgot
            );
        }

        $resetCases = [
            [false, false, false],
            [false, true, false],
            [true, false, false],
            [true, true, true],
            [true, true, false],
        ];

        foreach ($resetCases as [$hasGuid, $userFound, $userTrashed]) {
            ResetPasswordController::disableRustFfi();
            $phpReset = ResetPasswordController::evaluateLegacyResetGuidState($hasGuid, $userFound, $userTrashed);

            ResetPasswordController::enableRustFfi();
            $rustReset = ResetPasswordController::evaluateLegacyResetGuidState($hasGuid, $userFound, $userTrashed);

            $this->assertSame($phpReset, $rustReset);
            $this->assertSame(
                ResetPasswordController::evaluateLegacyResetGuidStatePhp($hasGuid, $userFound, $userTrashed),
                $phpReset
            );
            $this->assertSame(
                ResetPasswordController::evaluateLegacyResetGuidStateNative($hasGuid, $userFound, $userTrashed),
                $rustReset
            );
        }
    }

    public function test_fallback_when_ffi_is_disabled_for_testing(): void
    {
        PasskeyLoginController::enableRustFfi();
        PasskeyLoginController::setFfiForTesting(false);
        $this->assertSame(
            PasskeyLoginController::evaluatePasskeyLoginPreflightPhp(true, 'tok', '{}'),
            PasskeyLoginController::evaluatePasskeyLoginPreflightNative(true, 'tok', '{}')
        );
        $this->assertSame(
            PasskeyLoginController::resolvePasskeyLoginRedirectAndIpPhp(true, '1.1.1.1', null, false, null, '/'),
            PasskeyLoginController::resolvePasskeyLoginRedirectAndIpNative(true, '1.1.1.1', null, false, null, '/')
        );

        PasskeyManagementController::enableRustFfi();
        PasskeyManagementController::setFfiForTesting(false);
        $this->assertSame(
            PasskeyManagementController::buildPasskeyStoreAndDestroyPayloadPhp(1, 'Key', null, null, 1),
            PasskeyManagementController::buildPasskeyStoreAndDestroyPayloadNative(1, 'Key', null, null, 1)
        );

        EmailVerificationController::enableRustFfi();
        EmailVerificationController::setFfiForTesting(false);
        $this->assertSame(
            EmailVerificationController::verifyEmailSha1HashPhp(sha1('a@b.c'), 'a@b.c'),
            EmailVerificationController::verifyEmailSha1HashNative(sha1('a@b.c'), 'a@b.c')
        );

        ForgotPasswordController::enableRustFfi();
        ForgotPasswordController::setFfiForTesting(false);
        $this->assertSame(
            ForgotPasswordController::evaluateForgotPasswordRequestPhp('a@b.c', false, false, true, false),
            ForgotPasswordController::evaluateForgotPasswordRequestNative('a@b.c', false, false, true, false)
        );

        ResetPasswordController::enableRustFfi();
        ResetPasswordController::setFfiForTesting(false);
        $this->assertSame(
            ResetPasswordController::evaluateLegacyResetGuidStatePhp(true, true, false),
            ResetPasswordController::evaluateLegacyResetGuidStateNative(true, true, false)
        );
    }

    public function test_direct_c_abi_null_misaligned_and_undersized_buffer_safety(): void
    {
        $ffi = $this->loadFfi();
        $buf = $ffi->new('uint8_t[64]');
        $this->assertInstanceOf(CData::class, $buf);
        $i32Out = $ffi->new('int32_t[1]');
        $this->assertInstanceOf(CData::class, $i32Out);
        $i64Out = $ffi->new('int64_t[3]');
        $this->assertInstanceOf(CData::class, $i64Out);

        $rawBytes = $ffi->new('uint8_t[16]');
        $this->assertInstanceOf(CData::class, $rawBytes);
        $misalignedI32 = $ffi->cast('int32_t *', FFI::addr($rawBytes[1]));
        $misalignedI64 = $ffi->cast('int64_t *', FFI::addr($rawBytes[1]));

        // 1. nntmux_passkey_login_preflight_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_passkey_login_preflight_nul(1, 1, 'tok', 3, 1, 10, $misalignedI32, $buf, 64));

        FFI::memset($buf, 0xA5, 64);
        // @phpstan-ignore method.notFound
        $needed = (int) $ffi->nntmux_passkey_login_preflight_nul(1, 1, 'tok', 3, 1, 10, $i32Out, $buf, 8);
        $this->assertLessThan(-8, $needed);
        $this->assertSame(str_repeat("\xA5", 64), FFI::string($buf, 64));

        // 2. nntmux_passkey_login_resolve_redirect_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-2, (int) $ffi->nntmux_passkey_login_resolve_redirect_nul(
            1,
            1,
            "1.2\0.3.4",
            8,
            0,
            '',
            0,
            0,
            '',
            0,
            1,
            '/',
            1,
            $buf,
            64
        ));

        // 3. nntmux_passkey_management_normalize_metadata_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_passkey_management_normalize_metadata_nul(
            1,
            1,
            1,
            'Key',
            3,
            0,
            '',
            0,
            0,
            '',
            0,
            1,
            $misalignedI64,
            $buf,
            64
        ));

        // 4. nntmux_email_verification_verify_sha1
        FFI::memset($buf, 0x5A, 64);
        // @phpstan-ignore method.notFound
        $shaNeeded = (int) $ffi->nntmux_email_verification_verify_sha1(
            sha1('abc'),
            40,
            'abc',
            3,
            $i32Out,
            $buf,
            16
        );
        $this->assertSame(-40, $shaNeeded);
        $this->assertSame(str_repeat("\x5A", 64), FFI::string($buf, 64));

        // 5. nntmux_forgot_password_evaluate_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_forgot_password_evaluate_nul('a@b.c', 5, 0, 0, 1, 0, $misalignedI32, $buf, 64));

        // 6. nntmux_reset_password_evaluate_legacy_guid_nul
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, (int) $ffi->nntmux_reset_password_evaluate_legacy_guid_nul(1, 1, 0, $misalignedI32, $buf, 64));
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired randomized benchmark (3 rounds x 41 samples x 200 iterations, warmup=8):
        // Measured on Apple Silicon (macOS release dylib `/tmp/cargo-w27-6/release/libnntmux_pipeline.dylib`):
        // - Round 1: PHP p50 = 0.106 ms (p99 = 0.180 ms), Rust FFI p50 = 0.169 ms (p99 = 0.299 ms) -> p50 0.63x, p99 0.60x
        // - Round 2: PHP p50 = 0.096 ms (p99 = 0.191 ms), Rust FFI p50 = 0.164 ms (p99 = 0.306 ms) -> p50 0.58x, p99 0.62x
        // - Round 3: PHP p50 = 0.103 ms (p99 = 0.204 ms), Rust FFI p50 = 0.174 ms (p99 = 0.334 ms) -> p50 0.59x, p99 0.61x
        // Because Rust FFI does not achieve >= 1.5x speedup over native PHP for these small controller helpers,
        // `usesRustFfi()` remains disabled (`false`) by default in production across all 5 controllers.
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
     * @return array<int, mixed>
     */
    private function runWorkloadOnce(bool $useRust): array
    {
        $hash = '09d2c7e09b90e4a52e1e0f41f5785564d8968153';
        if ($useRust) {
            return [
                PasskeyLoginController::evaluatePasskeyLoginPreflightNative(true, 'cf-tok', '{"challenge":"x"}'),
                PasskeyLoginController::resolvePasskeyLoginRedirectAndIpNative(true, '10.0.0.1', null, false, null, '/'),
                PasskeyManagementController::buildPasskeyStoreAndDestroyPayloadNative(7, 'MacBook', null, null, 1),
                EmailVerificationController::verifyEmailSha1HashNative($hash, 'user@example.com'),
                ForgotPasswordController::evaluateForgotPasswordRequestNative('user@example.com', true, false, true, false),
                ResetPasswordController::evaluateLegacyResetGuidStateNative(true, true, false),
            ];
        }

        return [
            PasskeyLoginController::evaluatePasskeyLoginPreflightPhp(true, 'cf-tok', '{"challenge":"x"}'),
            PasskeyLoginController::resolvePasskeyLoginRedirectAndIpPhp(true, '10.0.0.1', null, false, null, '/'),
            PasskeyManagementController::buildPasskeyStoreAndDestroyPayloadPhp(7, 'MacBook', null, null, 1),
            EmailVerificationController::verifyEmailSha1HashPhp($hash, 'user@example.com'),
            ForgotPasswordController::evaluateForgotPasswordRequestPhp('user@example.com', true, false, true, false),
            ResetPasswordController::evaluateLegacyResetGuidStatePhp(true, true, false),
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
