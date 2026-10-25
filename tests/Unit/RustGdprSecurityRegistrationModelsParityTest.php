<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RegistrationStatus;
use App\Models\GdprAuditLog;
use App\Models\GdprConsent;
use App\Models\PasswordSecurity;
use App\Models\RegistrationConfiguration;
use App\Models\RoleExpirationEmail;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustGdprSecurityRegistrationModelsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const MODELS = [
        GdprAuditLog::class,
        GdprConsent::class,
        PasswordSecurity::class,
        RoleExpirationEmail::class,
        RegistrationConfiguration::class,
    ];

    /** Strings that exercise trimming, slugging, base32, IP, status parsing, UTF-8 and wire-marker fallbacks. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        'granted',
        ' WITHDRAWN ',
        'Granted!',
        'essential_cookies',
        ' Essential Cookies ',
        'gdpr.export.requested',
        '__Export__Requested__',
        'Data export completed for user 42.',
        'v2026.10',
        '  policy  v1  ',
        '10.0.0.1',
        ' 2001:DB8::1 ',
        '::ffff:192.168.1.1',
        'host.example',
        'JBSWY3DPEHPK3PXP',
        'jbsw y3dp-ehpk 3pxp',
        'JBSWY3DPEHPK3PXP====',
        'JBSWY3DP=EHPK3PXP',
        'JBSWY3DPEHPK3PX1',
        'SHORTSECRET',
        '0',
        '1',
        ' 2 ',
        '3',
        '002',
        '000',
        '-1',
        '+1',
        '99999999999999999999',
        'open',
        ' INVITE ',
        'Closed',
        'Ünïcödé  Pölicy',
        "\u{00A0}nbsp\u{00A0}",
        '日本',
        "emoji \u{1F3AC} event",
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "null\x1Emark",
        "\x1E",
        "nul\0byte",
    ];

    private const INTS = [PHP_INT_MIN, -1, 0, 1, 7, 86400, 604800, 2592000, 1700000000, 253402300799, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::setUseRustFfi(true);
            $this->assertTrue($model::usesRustFfi(), $model);
            $model::setUseRustFfi(false);
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::setUseRustFfi(null);
            $this->assertFalse($model::usesRustFfi(), $model);
        }
    }

    public function test_eloquent_model_configuration_is_unchanged(): void
    {
        $audit = new GdprAuditLog;
        $this->assertSame('gdpr_audit_logs', $audit->getTable());
        $this->assertNull($audit->getUpdatedAtColumn());
        $this->assertSame(['gdpr_request_id', 'user_id', 'actor_id', 'event', 'description', 'metadata'], $audit->getFillable());

        $consent = new GdprConsent;
        $this->assertSame('gdpr_consents', $consent->getTable());
        $this->assertContains('user_agent_hash', $consent->getFillable());
        $this->assertSame('array', $consent->getCasts()['metadata']);

        $security = new PasswordSecurity;
        $this->assertSame('password_securities', $security->getTable());
        $this->assertSame([], $security->getGuarded());
        $this->assertSame('boolean', $security->getCasts()['google2fa_enable']);

        $this->assertSame('role_expiration_emails', (new RoleExpirationEmail)->getTable());

        $registration = new RegistrationConfiguration;
        $this->assertSame('registration_configurations', $registration->getTable());
        $this->assertSame(['id'], $registration->getGuarded());
        $this->assertSame(RegistrationStatus::class, $registration->getCasts()['status']);
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'gdpr_request_id' => 3,
            'user_id' => 9,
            'actor_id' => 9,
            'event' => 'export_requested',
            'description' => 'done',
            'is_self_service' => true,
            'is_valid' => true,
        ], GdprAuditLog::normalizeAuditRecordPhp('  Export Requested!! ', "\tdone \n", 3, 9, 9));
        $this->assertSame([
            'gdpr_request_id' => null,
            'user_id' => null,
            'actor_id' => null,
            'event' => 'a.b',
            'description' => '',
            'is_self_service' => false,
            'is_valid' => false,
        ], GdprAuditLog::normalizeAuditRecordPhp('__a.b__', ' ', 0, -1));

        $this->assertSame([
            'consent_type' => 'essential_cookies',
            'status' => GdprConsent::STATUS_GRANTED,
            'policy_version' => 'v2',
            'ip_address' => '2001:db8::1',
            'is_active' => true,
            'is_valid' => true,
        ], GdprConsent::normalizeConsentStatePhp(' Essential Cookies ', ' GRANTED ', ' v2 ', ' 2001:DB8::1 ', 10, null, 20));
        $this->assertFalse(GdprConsent::normalizeConsentStatePhp('t', 'granted', null, null, 30, null, 20)['is_active']);
        $this->assertTrue(GdprConsent::normalizeConsentStatePhp('t', 'granted', null, null, 30, 5, 40)['is_active']);
        $this->assertSame(GdprConsent::STATUS_WITHDRAWN, GdprConsent::normalizeConsentStatePhp('t', '?', null, null, 1, 1, 4)['status']);
        $this->assertSame(GdprConsent::STATUS_GRANTED, GdprConsent::normalizeConsentStatePhp('t', '?', null, null, 2, 1, 4)['status']);
        $this->assertFalse(GdprConsent::normalizeConsentStatePhp('t', 'withdrawn', null, null, 1, null, 4)['is_valid']);
        $this->assertNull(GdprConsent::normalizeConsentStatePhp('t', 'x', '  ', 'host.example', null, null, 0)['policy_version']);
        $this->assertNull(GdprConsent::normalizeConsentStatePhp('t', 'x', null, 'host.example', null, null, 0)['ip_address']);

        $this->assertSame([
            'google2fa_secret' => 'JBSWY3DPEHPK3PXP',
            'google2fa_enable' => true,
            'is_configured' => true,
            'needs_verification' => false,
        ], PasswordSecurity::normalizeTwoFactorStatePhp(' jbsw y3dp-ehpk 3pxp== ', true));
        $this->assertSame([
            'google2fa_secret' => null,
            'google2fa_enable' => false,
            'is_configured' => false,
            'needs_verification' => false,
        ], PasswordSecurity::normalizeTwoFactorStatePhp('JBSWY3DP=EHPK3PXP', true));
        $this->assertTrue(PasswordSecurity::normalizeTwoFactorStatePhp('JBSWY3DPEHPK3PXP', false)['needs_verification']);

        $this->assertSame([
            'seconds_remaining' => 86400,
            'is_expired' => false,
            'due_period' => 'day',
            'day' => true,
            'week' => false,
            'month' => false,
        ], RoleExpirationEmail::expiryWarningPlanPhp(86400, 0));
        $this->assertNull(RoleExpirationEmail::expiryWarningPlanPhp(86400, 0, daySent: true)['due_period']);
        $this->assertSame('week', RoleExpirationEmail::expiryWarningPlanPhp(604800, 0)['due_period']);
        $this->assertSame('month', RoleExpirationEmail::expiryWarningPlanPhp(2592000, 0)['due_period']);
        $this->assertNull(RoleExpirationEmail::expiryWarningPlanPhp(2592001, 0)['due_period']);
        $this->assertTrue(RoleExpirationEmail::expiryWarningPlanPhp(5, 5)['is_expired']);
        $this->assertSame(253402300799, RoleExpirationEmail::expiryWarningPlanPhp(PHP_INT_MAX, PHP_INT_MIN)['seconds_remaining']);

        $this->assertSame([
            'status' => RegistrationStatus::Invite,
            'label' => 'Invite',
            'is_valid' => true,
            'allows_registration' => true,
            'requires_invite' => true,
            'can_register' => true,
        ], RegistrationConfiguration::normalizeRegistrationStatusPhp(' 1 ', true));
        $this->assertFalse(RegistrationConfiguration::normalizeRegistrationStatusPhp('invite')['can_register']);
        $this->assertSame(RegistrationStatus::Open, RegistrationConfiguration::normalizeRegistrationStatusPhp('000')['status']);
        $this->assertSame([
            'status' => RegistrationStatus::Closed,
            'label' => 'Closed',
            'is_valid' => false,
            'allows_registration' => false,
            'requires_invite' => false,
            'can_register' => false,
        ], RegistrationConfiguration::normalizeRegistrationStatusPhp('99999999999999999999', true));
    }

    public function test_gdpr_audit_log_native_and_dispatch_match_php(): void
    {
        $ids = [null, PHP_INT_MIN, -1, 0, 1, 42, PHP_INT_MAX];
        foreach (self::TEXTS as $event) {
            foreach (self::TEXTS as $description) {
                $this->assertAuditParity($event, $description, 7, 42, 42);
            }
            foreach ($ids as $id) {
                $this->assertAuditParity($event, 'description', $id, $id, 42);
            }
        }
        $this->assertAuditParity(str_repeat('ab.', 40), str_repeat("é\x0B", 4000), null, null, null);
    }

    public function test_gdpr_consent_native_and_dispatch_match_php(): void
    {
        $times = [null, PHP_INT_MIN, 0, 10, 20, PHP_INT_MAX];
        foreach (self::TEXTS as $text) {
            $this->assertConsentParity($text, $text, $text, $text, 10, null, 20);
            $this->assertConsentParity('essential_cookies', $text, null, null, 10, 15, 20);
            $this->assertConsentParity('essential_cookies', 'granted', $text, null, 10, null, 20);
            $this->assertConsentParity('essential_cookies', 'granted', null, $text, 10, null, 20);
        }
        foreach ($times as $consented) {
            foreach ($times as $withdrawn) {
                foreach (['granted', 'withdrawn', 'unknown'] as $status) {
                    $this->assertConsentParity('t', $status, 'v1', '10.0.0.1', $consented, $withdrawn, 15);
                }
            }
        }
        $this->assertConsentParity(str_repeat('Type ', 30), 'x', str_repeat('é', 80), str_repeat('a', 46), 1, 2, 3);
    }

    public function test_password_security_native_and_dispatch_match_php(): void
    {
        foreach ([...self::TEXTS, null, str_repeat('A', 128), str_repeat('A', 129), str_repeat('ab-', 50)] as $secret) {
            $this->assertTwoFactorParity($secret, true);
            $this->assertTwoFactorParity($secret, false);
        }
    }

    public function test_role_expiration_email_native_and_dispatch_match_php(): void
    {
        foreach (self::INTS as $expires) {
            foreach (self::INTS as $now) {
                for ($flags = 0; $flags < 8; $flags++) {
                    $this->assertExpiryParity($expires, $now, ($flags & 1) === 1, ($flags & 2) === 2, ($flags & 4) === 4);
                }
            }
        }
    }

    public function test_registration_configuration_native_and_dispatch_match_php(): void
    {
        foreach (self::TEXTS as $raw) {
            $this->assertRegistrationParity($raw, true);
            $this->assertRegistrationParity($raw, false);
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3403));
        $alphabet = ['a', 'Z', 'f', 'F', 'o', 'p', 'e', 'n', '0', '1', '2', '7', '9', '.', '_', '=', ' ', "\t", "\n", "\0", "\x0B", "\x0C", '-', ':', 'é', 'ß', '日', "\u{1F3AC}", "\u{00A0}", "\x1F", "\x1E", "\xff"];
        $text = static function (int $max = 40) use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, $max);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $base32 = static function () use ($random, $text): string {
            if ($random->getInt(0, 2) === 0) {
                return $text(40);
            }
            $out = '';
            $length = $random->getInt(10, 40);
            for ($i = 0; $i < $length; $i++) {
                $out .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567abc -='[$random->getInt(0, 37)];
            }

            return $out;
        };
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 3_000_000),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(253402300790, 253402300810),
        };
        $nullableInt = static fn (): ?int => $random->getInt(0, 3) === 0 ? null : $int();
        $nullableText = static fn (int $max = 40): ?string => $random->getInt(0, 3) === 0 ? null : $text($max);

        for ($i = 0; $i < 2000; $i++) {
            $this->assertAuditParity($text(), $text(), $nullableInt(), $nullableInt(), $nullableInt());
            $this->assertConsentParity($text(), $text(12), $nullableText(), $nullableText(20), $nullableInt(), $nullableInt(), $int());
            $this->assertTwoFactorParity($random->getInt(0, 4) === 0 ? null : $base32(), $random->getInt(0, 1) === 1);
            $now = $random->getInt(0, 1_000_000);
            $this->assertExpiryParity($random->getInt(0, 1) === 1 ? $now + $random->getInt(-100, 3_000_000) : $int(), $now, $random->getInt(0, 1) === 1, $random->getInt(0, 1) === 1, $random->getInt(0, 1) === 1);
            $this->assertRegistrationParity($text(8), $random->getInt(0, 1) === 1);
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $needed = $this->callFfi($ffi, 'nntmux_registration_configuration_normalize_status', 0, 'open', 4, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $buffer = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_registration_configuration_normalize_status', 0, 'open', 4, $buffer, -$needed));
        $this->assertSame("0\x1FOpen\x1F1\x1F1\x1F0\x1F1", FFI::string($buffer, -$needed));

        $needed = $this->callFfi($ffi, 'nntmux_role_expiration_email_warning_plan', 0, 1000, 0, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $out = $ffi->new('char[256]');
        $written = $this->callFfi($ffi, 'nntmux_gdpr_audit_log_normalize_record', 3, 4, 5, 6, "Export\x1F done ", 13, $out, 256);
        $this->assertSame("4\x1F5\x1F\x1E\x1Fexport\x1Fdone\x1F0\x1F1", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_gdpr_consent_normalize_state', 4, 10, 0, 20, "essential_cookies\x1Fgranted\x1F\x1F", 27, $out, 256);
        $this->assertSame("essential_cookies\x1Fgranted\x1F\x1E\x1F\x1E\x1F1\x1F1", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_password_security_normalize_two_factor', 0, 1, '', 0, $out, 256);
        $this->assertSame("\x1E\x1F0\x1F0\x1F0", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_role_expiration_email_warning_plan', 1, 1000, 0, $out, 256);
        $this->assertSame("1000\x1F0\x1F\x1E\x1F1\x1F0\x1F0", FFI::string($out, $written));

        // Malformed wire input, unknown flag bits and non-UTF-8 policy versions decline with -1.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_audit_log_normalize_record', 0, 0, 0, 0, "a\x1Fb\x1Fc", 5, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_audit_log_normalize_record', 0, 0, 0, 0, 'only', 4, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_audit_log_normalize_record', 0, 0, 0, 0, "a\x1Eb\x1Fc", 5, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_audit_log_normalize_record', 8, 0, 0, 0, "a\x1Fb", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_consent_normalize_state', 0, 0, 0, 0, "t\x1Fs\x1Fv1\x1F", 7, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_consent_normalize_state', 0, 0, 0, 0, "t\x1Fs\x1F\x1F1.2", 8, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_consent_normalize_state', 1, 0, 0, 0, "t\x1Fs\x1F\xff\x1F", 6, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_consent_normalize_state', 16, 0, 0, 0, "t\x1Fs\x1F\x1F", 5, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_consent_normalize_state', 0, 0, 0, 0, "t\x1Fs\x1F", 4, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_password_security_normalize_two_factor', 0, 0, 'ABCDEFGHIJKLMNOP', 16, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_password_security_normalize_two_factor', 2, 0, '', 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_password_security_normalize_two_factor', 1, 2, 'A', 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_role_expiration_email_warning_plan', 8, 0, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_registration_configuration_normalize_status', 2, 'open', 4, $out, 256));

        // Null pointers decline instead of crashing.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_audit_log_normalize_record', 0, 0, 0, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_gdpr_consent_normalize_state', 0, 0, 0, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_password_security_normalize_two_factor', 0, 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_registration_configuration_normalize_status', 0, null, 0, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_role_expiration_email_warning_plan', 0, 0, 0, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_registration_configuration_normalize_status', 0, 'open', 4, null, 256));

        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(true);
        }
        GdprAuditLog::normalizeAuditRecord('export', 'done');
        GdprConsent::normalizeConsentState('essential_cookies', 'granted', 'v1', '10.0.0.1', 1, null, 2);
        PasswordSecurity::normalizeTwoFactorState('JBSWY3DPEHPK3PXP', true);
        RoleExpirationEmail::expiryWarningPlan(100, 0);
        RegistrationConfiguration::normalizeRegistrationStatus('open');
        foreach (self::MODELS as $model) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($model, 'ffi'))->getValue(), $model.' must resolve its native exports');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $property = static fn (string $model): ReflectionProperty => new ReflectionProperty($model, 'ffi');
        $saved = [];
        foreach (self::MODELS as $model) {
            $saved[$model] = $property($model)->getValue();
            $property($model)->setValue(null, false);
            $model::setUseRustFfi(true);
        }

        try {
            $this->assertSame(
                GdprAuditLog::normalizeAuditRecordPhp(' Export ', ' done ', 1, 2, 2),
                GdprAuditLog::normalizeAuditRecord(' Export ', ' done ', 1, 2, 2),
            );
            $this->assertSame(
                GdprConsent::normalizeConsentStatePhp('t', 'granted', 'v1', '::1', 1, null, 2),
                GdprConsent::normalizeConsentState('t', 'granted', 'v1', '::1', 1, null, 2),
            );
            $this->assertSame(
                PasswordSecurity::normalizeTwoFactorStatePhp('jbsw y3dp ehpk 3pxp', true),
                PasswordSecurity::normalizeTwoFactorState('jbsw y3dp ehpk 3pxp', true),
            );
            $this->assertSame(
                RoleExpirationEmail::expiryWarningPlanPhp(604800, 0, true),
                RoleExpirationEmail::expiryWarningPlan(604800, 0, true),
            );
            $this->assertSame(
                RegistrationConfiguration::normalizeRegistrationStatusPhp('invite', true),
                RegistrationConfiguration::normalizeRegistrationStatus('invite', true),
            );
        } finally {
            foreach (self::MODELS as $model) {
                $property($model)->setValue(null, $saved[$model]);
            }
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five model helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical audit/consent/2FA/expiry/registration rows; uncommon = long, Unicode, padded,
        // out-of-range and fallback inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 4 rounds):
        // - Round 1: common mean 0.30x / p99 0.30x, uncommon mean 0.37x / p99 0.40x
        // - Round 2: common mean 0.30x / p99 0.31x, uncommon mean 0.38x / p99 0.39x
        // - Round 3: common mean 0.30x / p99 0.35x, uncommon mean 0.38x / p99 0.39x
        // - Round 4: common mean 0.30x / p99 0.32x, uncommon mean 0.37x / p99 0.29x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustGdprSecurityRegistrationModelsParityTest.php --filter benchmark
        // Microsecond PHP string/int work is cheaper than the FFI round trip, so every model stays below the
        // 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3403));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (self::MODELS as $model) {
                $model::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustGdprSecurityRegistrationModels speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::MODELS as $model) {
                $this->assertFalse($model::usesRustFfi(), $model.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertAuditParity(string $event, string $description, ?int $requestId, ?int $userId, ?int $actorId): void
    {
        $expected = GdprAuditLog::normalizeAuditRecordPhp($event, $description, $requestId, $userId, $actorId);
        $context = json_encode([bin2hex($event), bin2hex($description), $requestId, $userId, $actorId]) ?: '';
        $this->assertSameAttributes($expected, GdprAuditLog::normalizeAuditRecordNative($event, $description, $requestId, $userId, $actorId), $context);
        GdprAuditLog::setUseRustFfi(true);
        $this->assertSameAttributes($expected, GdprAuditLog::normalizeAuditRecord($event, $description, $requestId, $userId, $actorId), $context);
        GdprAuditLog::setUseRustFfi(false);
        $this->assertSameAttributes($expected, GdprAuditLog::normalizeAuditRecord($event, $description, $requestId, $userId, $actorId), $context);
        GdprAuditLog::setUseRustFfi(null);
    }

    private function assertConsentParity(string $type, string $status, ?string $policy, ?string $ip, ?int $consentedAt, ?int $withdrawnAt, int $now): void
    {
        $args = [$type, $status, $policy, $ip, $consentedAt, $withdrawnAt, $now];
        $expected = GdprConsent::normalizeConsentStatePhp(...$args);
        $context = json_encode([bin2hex($type), bin2hex($status), $policy === null ? null : bin2hex($policy), $ip === null ? null : bin2hex($ip), $consentedAt, $withdrawnAt, $now]) ?: '';
        $this->assertSameAttributes($expected, GdprConsent::normalizeConsentStateNative(...$args), $context);
        GdprConsent::setUseRustFfi(true);
        $this->assertSameAttributes($expected, GdprConsent::normalizeConsentState(...$args), $context);
        GdprConsent::setUseRustFfi(false);
        $this->assertSameAttributes($expected, GdprConsent::normalizeConsentState(...$args), $context);
        GdprConsent::setUseRustFfi(null);
    }

    private function assertTwoFactorParity(?string $secret, bool $enabled): void
    {
        $expected = PasswordSecurity::normalizeTwoFactorStatePhp($secret, $enabled);
        $context = json_encode([$secret === null ? null : bin2hex($secret), $enabled]) ?: '';
        $this->assertSameAttributes($expected, PasswordSecurity::normalizeTwoFactorStateNative($secret, $enabled), $context);
        PasswordSecurity::setUseRustFfi(true);
        $this->assertSameAttributes($expected, PasswordSecurity::normalizeTwoFactorState($secret, $enabled), $context);
        PasswordSecurity::setUseRustFfi(false);
        $this->assertSameAttributes($expected, PasswordSecurity::normalizeTwoFactorState($secret, $enabled), $context);
        PasswordSecurity::setUseRustFfi(null);
    }

    private function assertExpiryParity(int $expiresAt, int $now, bool $day, bool $week, bool $month): void
    {
        $expected = RoleExpirationEmail::expiryWarningPlanPhp($expiresAt, $now, $day, $week, $month);
        $context = json_encode([$expiresAt, $now, $day, $week, $month]) ?: '';
        $this->assertSameAttributes($expected, RoleExpirationEmail::expiryWarningPlanNative($expiresAt, $now, $day, $week, $month), $context);
        RoleExpirationEmail::setUseRustFfi(true);
        $this->assertSameAttributes($expected, RoleExpirationEmail::expiryWarningPlan($expiresAt, $now, $day, $week, $month), $context);
        RoleExpirationEmail::setUseRustFfi(false);
        $this->assertSameAttributes($expected, RoleExpirationEmail::expiryWarningPlan($expiresAt, $now, $day, $week, $month), $context);
        RoleExpirationEmail::setUseRustFfi(null);
    }

    private function assertRegistrationParity(string $raw, bool $hasValidInvite): void
    {
        $expected = RegistrationConfiguration::normalizeRegistrationStatusPhp($raw, $hasValidInvite);
        $context = json_encode([bin2hex($raw), $hasValidInvite]) ?: '';
        $this->assertSameAttributes($expected, RegistrationConfiguration::normalizeRegistrationStatusNative($raw, $hasValidInvite), $context);
        RegistrationConfiguration::setUseRustFfi(true);
        $this->assertSameAttributes($expected, RegistrationConfiguration::normalizeRegistrationStatus($raw, $hasValidInvite), $context);
        RegistrationConfiguration::setUseRustFfi(false);
        $this->assertSameAttributes($expected, RegistrationConfiguration::normalizeRegistrationStatus($raw, $hasValidInvite), $context);
        RegistrationConfiguration::setUseRustFfi(null);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameAttributes(array $expected, array $actual, string $context): void
    {
        $this->assertSame($expected, $actual, $context);
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            ['audit', ['gdpr.export.requested', 'User requested a data export.', 12, 42, 42]],
            ['audit', ['gdpr.erasure.completed', 'Account erased by admin.', 13, 43, 1]],
            ['consent', ['essential_cookies', 'granted', 'v2026.10', '192.168.1.10', 1700000000, null, 1700000500]],
            ['consent', ['essential_cookies', 'withdrawn', 'v2026.10', '2001:db8::1', 1700000000, 1700000400, 1700000500]],
            ['2fa', ['JBSWY3DPEHPK3PXP', true]],
            ['2fa', ['KRSXG5CTMVRXEZLU', false]],
            ['expiry', [1700604800, 1700000000, false, false, true]],
            ['expiry', [1700086400, 1700000000, false, true, true]],
            ['registration', ['0', false]],
            ['registration', ['1', true]],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $long = str_repeat('Ünïcödé  Dëscriptïön  ', 40);

        return [
            ['audit', ['  '.str_repeat('Very Long Event Name ', 8).'  ', $long, PHP_INT_MIN, null, PHP_INT_MAX]],
            ['audit', ["event\x1Fwith marker", "bad \xff utf8", null, null, null]],
            ['consent', [' Essential  Cookies!! ', ' UNKNOWN ', str_repeat('é', 80), str_repeat('f', 46), PHP_INT_MIN, PHP_INT_MAX, 0]],
            ['consent', ['t', 'granted', "bad \xff policy", 'host.example', null, 5, 10]],
            ['2fa', [' jbsw-y3dp ehpk-3pxp '.str_repeat('a', 100).'==== ', true]],
            ['2fa', [str_repeat("\xff", 40), true]],
            ['expiry', [PHP_INT_MAX, PHP_INT_MIN, true, true, true]],
            ['expiry', [0, 253402300799, false, false, false]],
            ['registration', [" \t000002\n", true]],
            ['registration', ['99999999999999999999', true]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            $result = match ($kind) {
                'audit' => GdprAuditLog::normalizeAuditRecord(...$args),
                'consent' => GdprConsent::normalizeConsentState(...$args),
                '2fa' => PasswordSecurity::normalizeTwoFactorState(...$args),
                'expiry' => RoleExpirationEmail::expiryWarningPlan(...$args),
                default => RegistrationConfiguration::normalizeRegistrationStatus(...$args),
            };
            $count += count($result);
        }

        return $count;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", [
            GdprAuditLog::FFI_CDEF,
            GdprConsent::FFI_CDEF,
            PasswordSecurity::FFI_CDEF,
            RoleExpirationEmail::FFI_CDEF,
            RegistrationConfiguration::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('categorize', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the GDPR/security/registration model exports is not available.');
        }

        return $ffi;
    }
}
