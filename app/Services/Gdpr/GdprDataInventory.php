<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
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

    // ========================================================================
    // WAVE 60.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs reached 2.22x common mean, 1.94x common p99, 1.83x uncommon mean and 1.67x uncommon p99; see RustGdprAuditInventoryErasureExportRetentionServiceW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_FFI_CDEF = 'ssize_t nntmux_w604_gdpr_inventory_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per inventoryPlanRowBatch() record. */
    public const int W60_ROW_WORDS = 5;

    /** int64 summary words after the inventoryPlanRowBatch() records (none for an empty batch). */
    public const int W60_TRAILER_WORDS = 7;

    /** Upper clamp for row counts. */
    private const int W60_ROWS_MAX = 1 << 48;

    private const string W60_IDENT = 'abcdefghijklmnopqrstuvwxyz0123456789_';

    /** Exact column names => personal-data category (1 contact, 2 network, 3 identity, 4 secret). */
    private const array W60_COLUMN_CATEGORIES = [
        'email' => 1, 'email_address' => 1,
        'ip' => 2, 'ip_address' => 2, 'host' => 2, 'last_ip' => 2,
        'username' => 3, 'name' => 3, 'firstname' => 3, 'lastname' => 3,
        'password' => 4, 'remember_token' => 4, 'session_token' => 4, 'api_token' => 4, 'resetguid' => 4, 'verification_token' => 4,
        'sabapikey' => 4, 'nzbgetpassword' => 4, 'nzbvortex_api_key' => 4, 'cp_api' => 4,
    ];

    /** Sensitivity per category. */
    private const array W60_SENSITIVITY = [0, 3, 2, 2, 4, 1, 0];

    private const array W60_LEGAL_BASES = ['contract' => 1, 'legal_obligation' => 2, 'legitimate_interest' => 3, 'consent' => 4];

    /**
     * Data inventory plan rows for [table, column, legal basis, row count, flags (1 exported,
     * 2 encrypted)] items. Tables are normalized as classifyTablesPhp() does and columns are
     * lowercased, trimmed and stripped of backticks/double quotes; both must then be 1-64 bytes
     * of `[a-z0-9_]`. Membership is `w49TableWords() & 15` (1 erasable, 2 anonymized, 4
     * exported, 8 retained), and an exported table counts as exported.
     *
     * Column categories: the exact W60_COLUMN_CATEGORIES names, else 4 for a name holding
     * `password`, `secret` or `token`, 1 holding `email`, 2 ending `_ip`, 5 `id` or ending
     * `_id`, 6 ending `_at` or `timestamp`, else 0. Legal bases: 1 contract, 2 legal
     * obligation, 3 legitimate interest, 4 consent, 0 unknown. Status: 3 invalid table, 4
     * invalid column, 2 secret exported unencrypted, 1 personal data (category 1-4) without a
     * basis, else 0. Risk is rows (clamped to 0..2^48) * sensitivity, doubled when unencrypted,
     * and 0 for invalid rows.
     *
     * Rows [membership | category << 4 | sensitivity << 8 | basis << 12 | status << 16 | flags
     * << 20, table bytes, column bytes, risk, rows], then the trailer [personal, secrets, missing
     * basis, exported secrets, invalid, total risk (saturating), FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function inventoryPlanRowBatch(array $items): array
    {
        return self::usesW60RustFfi() ? self::inventoryPlanRowBatchNative($items) : self::inventoryPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function inventoryPlanRowBatchPhp(array $items): array
    {
        self::w60Items($items);
        if ($items === []) {
            return [];
        }
        $tables = self::w49TableWords();
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$table, $column, $basis, $rows, $flags]) {
            $name = strtolower(trim($table));
            $dot = strrpos($name, '.');
            if ($dot !== false) {
                $name = substr($name, $dot + 1);
            }
            $name = trim($name, '`"');
            $column = trim(strtolower(trim($column)), '`"');
            $tableLength = \strlen($name);
            $columnLength = \strlen($column);
            $tableOk = $tableLength >= 1 && $tableLength <= 64 && strspn($name, self::W60_IDENT) === $tableLength;
            $columnOk = $columnLength >= 1 && $columnLength <= 64 && strspn($column, self::W60_IDENT) === $columnLength;
            $membership = ($tables[$name] ?? 0) & 15;
            $category = ! $columnOk ? 0 : (self::W60_COLUMN_CATEGORIES[$column] ?? match (true) {
                str_contains($column, 'password') || str_contains($column, 'secret') || str_contains($column, 'token') => 4,
                str_contains($column, 'email') => 1,
                str_ends_with($column, '_ip') => 2,
                $column === 'id' || str_ends_with($column, '_id') => 5,
                $column === 'timestamp' || str_ends_with($column, '_at') => 6,
                default => 0,
            });
            $sensitivity = self::W60_SENSITIVITY[$category];
            $basis = self::W60_LEGAL_BASES[$basis] ?? 0;
            $flags &= 3;
            $exported = ($flags & 1) !== 0 || ($membership & 4) !== 0;
            $encrypted = ($flags & 2) !== 0;
            $personal = $category >= 1 && $category <= 4;
            $status = match (true) {
                ! $tableOk => 3,
                ! $columnOk => 4,
                $category === 4 && $exported && ! $encrypted => 2,
                $personal && $basis === 0 => 1,
                default => 0,
            };
            $rows = max(0, min(self::W60_ROWS_MAX, $rows));
            $risk = $status < 3 ? $rows * $sensitivity * ($encrypted ? 1 : 2) : 0;
            $word = $membership | $category << 4 | $sensitivity << 8 | $basis << 12 | $status << 16 | $flags << 20;
            array_push($out, $word, $tableLength, $columnLength, $risk, $rows);
            if ($status < 3) {
                $summary[0] += $personal ? 1 : 0;
                $summary[1] += $category === 4 ? 1 : 0;
            }
            if ($status === 1) {
                $summary[2]++;
            } elseif ($status === 2) {
                $summary[3]++;
            } elseif ($status >= 3) {
                $summary[4]++;
            }
            $summary[5] = $risk > PHP_INT_MAX - $summary[5] ? PHP_INT_MAX : $summary[5] + $risk;
            $value = $word ^ $risk;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[6] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int}>  $items
     * @return list<int>
     */
    public static function inventoryPlanRowBatchNative(array $items): array
    {
        self::w60Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w60Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4));

        return self::w60Call($blob, $twin, $ints, $count, $count * self::W60_ROW_WORDS + self::W60_TRAILER_WORDS)
            ?? self::inventoryPlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w60Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W60 inventory plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 5 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1])
                || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4])) {
                throw new InvalidArgumentException('W60 inventory plan batch items must be [string table, string column, string legal basis, int row count, int flags] lists.');
            }
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W60 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('enabled');
        self::w60RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w60Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w60Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w60RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w60', self::W60_FFI_CDEF) ?? false;
            self::w60RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w60RustFfiState('out', $out);
                self::w60RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W60_FFI_CDEF.)
            $written = $ffi->nntmux_w604_gdpr_inventory_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w60RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W60 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
