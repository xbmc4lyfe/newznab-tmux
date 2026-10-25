<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W49 parity test). */
    private const W49_RUST_FFI_DEFAULT = true;

    private const string W49_FFI_CDEF = 'ssize_t nntmux_w49_gdpr_inventory_classify_batch(const char *names, size_t names_len, size_t count, int64_t *out, size_t out_cap);';

    private static ?bool $useW49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$useW49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49 batch routed to Rust or PHP, then restores the routing. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW49RustFfi;
        self::$useW49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW49RustFfi = $previous;
        }
    }

    /** `user_column` codes (bits 24-27 of a table word); any other column is 15. */
    public const array W49_USER_COLUMNS = ['users_id' => 1, 'user_id' => 2, 'authenticatable_id' => 3, 'model_id' => 4, 'invited_by' => 5, 'used_by' => 6];

    /** Export `order_by` codes (bits 32-35); any other column is 15. */
    public const array W49_ORDER_COLUMNS = ['timestamp' => 1, 'created_at' => 2, 'used_at' => 3];

    /** Retained-record `erasure_action` codes (bits 36-37); any other action is 3. */
    public const array W49_ERASURE_ACTIONS = ['retained_anonymized' => 1, 'retained_minimized' => 2];

    /** @var array<string, int>|null */
    private static ?array $w49TableWords = null;

    /**
     * Batch classification of raw table names (as listed by the schema) against the inventory.
     * Names are lowercased and trimmed, reduced to the part after the last `.` and stripped of
     * backticks/double quotes. One word per name: `erasable | anonymized << 1 | exported << 2 |
     * retained << 3 | (erasableIndex + 1) << 8 | (firstExportIndex + 1) << 16 | exportMatches << 21
     * | userColumn << 24 | hasExtra << 28 | orderBy << 32 | erasureAction << 36 |
     * normalizedLength << 40 | changedByNormalization << 48`.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function classifyTables(array $names): array
    {
        return self::usesW49RustFfi() ? self::classifyTablesNative($names) : self::classifyTablesPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function classifyTablesPhp(array $names): array
    {
        $words = self::w49TableWords();
        $out = [];
        foreach ($names as $raw) {
            $name = strtolower(trim($raw));
            $dot = strrpos($name, '.');
            if ($dot !== false) {
                $name = substr($name, $dot + 1);
            }
            $name = trim($name, '`"');
            $out[] = ($words[$name] ?? 0) | min(255, \strlen($name)) << 40 | (int) ($name !== $raw) << 48;
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or a name holds a NUL byte.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function classifyTablesNative(array $names): array
    {
        $count = \count($names);
        $ffi = $count === 0 ? null : self::w49Ffi();
        $packed = implode("\0", $names);
        if ($ffi === null) {
            return self::classifyTablesPhp($names);
        }
        try {
            $words = self::w49Words($ffi, $count, static fn (CData $out, int $cap): mixed => $ffi->nntmux_w49_gdpr_inventory_classify_batch( // @phpstan-ignore method.notFound
                $packed, \strlen($packed), $count, $out, $cap));
        } catch (Throwable) {
            $words = null;
        }

        return $words ?? self::classifyTablesPhp($names);
    }

    /**
     * Table word bits 0-37 for every table the PHP inventories name.
     *
     * @return array<string, int>
     */
    public static function w49TableWords(): array
    {
        if (self::$w49TableWords !== null) {
            return self::$w49TableWords;
        }
        $erasable = self::erasableTablesPhp();
        $anonymized = self::anonymizedTablesPhp();
        $export = self::exportTablesPhp();
        $retained = array_column(self::retainedRecordsPhp(), 'erasure_action', 'table');
        $names = [...array_keys($erasable), ...array_keys($anonymized), ...array_keys($export), ...array_keys($retained)];
        foreach ($export as $key => $definition) {
            $names[] = $definition['table'] ?? $key;
        }
        $erasableIndex = array_flip(array_keys($erasable));
        $words = [];
        foreach ($names as $name) {
            $name = (string) $name;
            $word = $column = 0;
            if (isset($erasable[$name])) {
                $word |= 1 | ($erasableIndex[$name] + 1) << 8 | (int) isset($erasable[$name]['extra']) << 28;
                $column = self::W49_USER_COLUMNS[$erasable[$name]['user_column']] ?? 15;
            }
            if (isset($anonymized[$name])) {
                $word |= 2;
                $column = $column ?: self::W49_USER_COLUMNS[$anonymized[$name]['user_column']] ?? 15;
            }
            $exports = $order = $index = 0;
            foreach ($export as $key => $definition) {
                $index++;
                if ($key !== $name && ($definition['table'] ?? $key) !== $name) {
                    continue;
                }
                if ($exports++ === 0) {
                    $word |= $index << 16;
                    $column = $column ?: self::W49_USER_COLUMNS[$definition['user_column']] ?? 15;
                    $order = isset($definition['order_by']) ? self::W49_ORDER_COLUMNS[$definition['order_by']] ?? 15 : 0;
                }
            }
            if ($exports > 0) {
                $word |= 4 | min(7, $exports) << 21;
            }
            if (isset($retained[$name])) {
                $word |= 8 | (self::W49_ERASURE_ACTIONS[$retained[$name]] ?? 3) << 36;
            }
            $words[$name] = $word | $column << 24 | $order << 32;
        }

        return self::$w49TableWords = $words;
    }

    private static function w49Ffi(): ?FFI
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('gdpr_w49', self::W49_FFI_CDEF) ?? false;

        return self::$w49Ffi === false ? null : self::$w49Ffi;
    }

    /**
     * Copies `$words` output words out of a reusable `int64_t` buffer after `$call` filled it.
     *
     * @param  callable(CData, int): mixed  $call  Receives the buffer and its capacity in words.
     * @return list<int>|null
     */
    private static function w49Words(FFI $ffi, int $words, callable $call): ?array
    {
        $buffer = self::$w49Out;
        if ($buffer === null || self::$w49OutCap < $words) {
            $cap = max(512, $words * 2);
            $buffer = self::$w49Out = $ffi->new("int64_t[{$cap}]");
            self::$w49OutCap = $cap;
        }
        if ($call($buffer, self::$w49OutCap) !== $words) {
            return null;
        }
        $decoded = unpack('P*', FFI::string($buffer, $words * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }
}
