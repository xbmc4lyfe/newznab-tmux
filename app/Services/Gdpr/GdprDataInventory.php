<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class GdprDataInventory
{
    public const string FFI_CDEF = 'int32_t nntmux_w23_gdpr_export_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // PHP remains the default until the native candidate meets the performance gates.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function transformNative(int $operation, string $input): ?string
    {
        if (strlen($input) > 67_108_864) {
            return null;
        }

        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave23-gdpr-export-mail', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $ffi = self::$ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
            if ($buffer === null) {
                return null;
            }
            // A PHP empty string supplies a non-null pointer even for zero input length.
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w23_gdpr_export_mail($operation, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $required = -$written;
                if ($required <= $capacity || $required > 67_108_864) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, $required, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w23_gdpr_export_mail($operation, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity || $written > 67_108_864) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tables retained for legal, accounting, security, or accountability reasons.
     * Direct account identifiers are anonymized/minimized during erasure whenever practical.
     *
     * @return array<int, array<string, mixed>>
     */
    public function retainedRecords(): array
    {
        return self::usesRustFfi() ? self::retainedRecordsNative() : self::retainedRecordsPhp();
    }

    /** @return array<int, array<string, mixed>> */
    public static function retainedRecordsNative(): array
    {
        return self::inventoryNative(0, true) ?? self::retainedRecordsPhp();
    }

    /** @return array<int, array<string, mixed>> */
    public static function retainedRecordsPhp(): array
    {
        return [
            [
                'table' => 'payments',
                'reason' => 'Accounting, fraud prevention, dispute handling, and legal/tax obligations; direct account identifiers are anonymized while transaction details are retained.',
                'erasure_action' => 'retained_anonymized',
            ],
            [
                'table' => 'paypal_payments',
                'reason' => 'Legacy payment audit data retained for accounting and dispute handling; linked account identity resolves only to the anonymized/deleted account record.',
                'erasure_action' => 'retained_minimized',
            ],
            [
                'table' => 'user_activities',
                'reason' => 'Security and administrative audit trail.',
                'erasure_action' => 'retained_minimized',
            ],
            [
                'table' => 'gdpr_audit_logs',
                'reason' => 'GDPR accountability record for data subject requests.',
                'erasure_action' => 'retained_minimized',
            ],
            [
                'table' => 'user_role_history',
                'reason' => 'Administrative audit trail for role changes, upgrades, expirations, and account security reviews.',
                'erasure_action' => 'retained_minimized',
            ],
        ];
    }

    /**
     * Tables where records are removed when an account is erased.
     *
     * @return array<string, array<string, mixed>>
     */
    public function erasableTables(): array
    {
        return self::usesRustFfi() ? self::erasableTablesNative() : self::erasableTablesPhp();
    }

    /** @return array<string, array<string, mixed>> */
    public static function erasableTablesNative(): array
    {
        return self::inventoryNative(1, false) ?? self::erasableTablesPhp();
    }

    /** @return array<string, array<string, mixed>> */
    public static function erasableTablesPhp(): array
    {
        return [
            'user_requests' => ['user_column' => 'users_id'],
            'user_downloads' => ['user_column' => 'users_id'],
            'dnzb_failures' => ['user_column' => 'users_id'],
            'users_releases' => ['user_column' => 'users_id'],
            'user_series' => ['user_column' => 'users_id'],
            'user_movies' => ['user_column' => 'users_id'],
            'user_excluded_categories' => ['user_column' => 'users_id'],
            'user_invitations' => ['user_column' => 'user_id'],
            'trusted_devices' => ['user_column' => 'user_id'],
            'password_securities' => ['user_column' => 'user_id'],
            'passkeys' => ['user_column' => 'authenticatable_id', 'extra' => ['authenticatable_type' => 'App\\Models\\User']],
            'model_has_permissions' => ['user_column' => 'model_id', 'extra' => ['model_type' => 'App\\Models\\User']],
            'model_has_roles' => ['user_column' => 'model_id', 'extra' => ['model_type' => 'App\\Models\\User']],
            'role_promotion_stats' => ['user_column' => 'user_id'],
        ];
    }

    /**
     * Tables where public/community records are kept but direct identifiers are minimized.
     *
     * @return array<string, array<string, mixed>>
     */
    public function anonymizedTables(): array
    {
        return self::usesRustFfi() ? self::anonymizedTablesNative() : self::anonymizedTablesPhp();
    }

    /** @return array<string, array<string, mixed>> */
    public static function anonymizedTablesNative(): array
    {
        return self::inventoryNative(2, false) ?? self::anonymizedTablesPhp();
    }

    /** @return array<string, array<string, mixed>> */
    public static function anonymizedTablesPhp(): array
    {
        return [
            'release_comments' => [
                'user_column' => 'users_id',
                'updates' => [
                    'username' => 'Deleted user',
                    'host' => null,
                ],
            ],
            'release_reports' => [
                'user_column' => 'users_id',
                'updates' => [
                    'description' => '[Removed during account erasure]',
                ],
            ],
        ];
    }

    /**
     * Tables exported as part of a data access package when present.
     *
     * @return array<string, array<string, mixed>>
     */
    public function exportTables(): array
    {
        return self::usesRustFfi() ? self::exportTablesNative() : self::exportTablesPhp();
    }

    /** @return array<string, array<string, mixed>> */
    public static function exportTablesNative(): array
    {
        return self::inventoryNative(3, false) ?? self::exportTablesPhp();
    }

    /** @return array<string, array<string, mixed>> */
    public static function exportTablesPhp(): array
    {
        return [
            'user_requests' => ['user_column' => 'users_id', 'order_by' => 'timestamp'],
            'user_downloads' => ['user_column' => 'users_id', 'order_by' => 'timestamp'],
            'dnzb_failures' => ['user_column' => 'users_id'],
            'users_releases' => ['user_column' => 'users_id', 'order_by' => 'created_at'],
            'user_series' => ['user_column' => 'users_id'],
            'user_movies' => ['user_column' => 'users_id'],
            'user_excluded_categories' => ['user_column' => 'users_id'],
            'user_invitations' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
            'release_comments' => ['user_column' => 'users_id', 'order_by' => 'created_at'],
            'release_reports' => ['user_column' => 'users_id', 'order_by' => 'created_at'],
            'invitations_sent' => ['table' => 'invitations', 'user_column' => 'invited_by', 'order_by' => 'created_at'],
            'invitations_used' => ['table' => 'invitations', 'user_column' => 'used_by', 'order_by' => 'used_at'],
            'trusted_devices' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
            'role_history' => ['table' => 'user_role_history', 'user_column' => 'user_id', 'order_by' => 'created_at'],
            'promotion_stats' => ['table' => 'role_promotion_stats', 'user_column' => 'user_id', 'order_by' => 'created_at'],
            'gdpr_requests' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
            'gdpr_consents' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
            'gdpr_audit_logs' => ['user_column' => 'user_id', 'order_by' => 'created_at'],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function essentialCookies(): array
    {
        return self::usesRustFfi() ? self::essentialCookiesNative() : self::essentialCookiesPhp();
    }

    /** @return array<int, array<string, string>> */
    public static function essentialCookiesNative(): array
    {
        return self::inventoryNative(4, true) ?? self::essentialCookiesPhp();
    }

    /** @return array<int, array<string, string>> */
    public static function essentialCookiesPhp(): array
    {
        return [
            ['name' => 'session', 'purpose' => 'Keeps you signed in and stores security/session state.', 'type' => 'essential'],
            ['name' => 'XSRF-TOKEN', 'purpose' => 'Protects forms and authenticated requests from cross-site request forgery.', 'type' => 'essential'],
            ['name' => '2fa_trusted_device', 'purpose' => 'Optional security cookie used only when a user chooses to trust a device for two-factor authentication.', 'type' => 'essential_security'],
            ['name' => 'theme/color local storage', 'purpose' => 'Stores display preferences for guests; authenticated preferences are stored on the account.', 'type' => 'essential_preference'],
        ];
    }

    /** @return array<array-key, array<string, mixed>>|null */
    private static function inventoryNative(int $operation, bool $list): ?array
    {
        $output = self::transformNative($operation, '');
        if ($output === null) {
            return null;
        }
        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || array_is_list($decoded) !== $list) {
                return null;
            }
            foreach ($decoded as $key => $row) {
                if ((! $list && ! is_string($key)) || ! is_array($row)) {
                    return null;
                }
                foreach (array_keys($row) as $field) {
                    if (! is_string($field)) {
                        return null;
                    }
                }
            }

            return $decoded;
        } catch (Throwable) {
            return null;
        }
    }
}
