<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Gdpr\GdprAuditService;
use App\Services\Gdpr\GdprDataInventory;
use App\Services\Gdpr\GdprErasureService;
use App\Services\Gdpr\GdprExportService;
use App\Services\Gdpr\GdprRetentionService;
use App\Support\RustPipelineLibrary;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 60.4 batch helpers: GdprAuditService audit event rows (event/actor/address validation,
 * address masking and retention scoring), GdprDataInventory plan rows (table/column
 * normalization, personal-data category, sensitivity, legal basis and risk),
 * GdprErasureService plan rows (action, cascade rank, legal hold and scope checks),
 * GdprExportService manifest rows (redaction, JSON-encoded size and offset accounting) and
 * GdprRetentionService plan rows (cutoff, grace period, purge status and purge chunking). Each
 * export takes a field-major blob plus packed int64 words; Rust writes fixed-width int64 rows
 * and a batch trailer that PHP decodes with one unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call
 * count in the function static of w60RustFfiState().
 *
 * The paired benchmark (NNTMUX_W604_BENCH=1) times the PHP and native batch methods, including
 * validation, packing and decoding, over interleaved rounds of 320 records in random order per
 * round (fixed seed). Rounds per workload: NNTMUX_W604_BENCH_ROUNDS (default 600, minimum 60;
 * at 200 rounds p99 is the second-slowest sample and one stray run dipped auditEventRowBatch's
 * uncommon p99 to 1.35x). It requires every default-enabled helper to clear 1.5x on common and
 * uncommon mean and p99, asserts that helpers below 1.5x retain PHP by default, and skips
 * without the native library.
 *
 * Four runs at 066cacde5 + this change (Apple M5, PHP 8.5.12 CLI, rustc 1.99.1 release build,
 * 600 rounds per workload after 10 warmups, load average 6-7.5), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): auditEventRowBatch 2.44/2.24, 1.80/1.61;
 * inventoryPlanRowBatch 2.22/1.94, 1.83/1.67; erasurePlanRowBatch 1.61/1.39, 1.36/1.43;
 * exportManifestRowBatch 1.37/1.52, 1.23/1.19; retentionPlanRowBatch 1.86/1.58, 1.60/1.33.
 * Common batches hold realistic events, actors, addresses, schema names, erasure targets,
 * export fields and retention records; uncommon batches mix mutated, truncated, random-byte
 * (often NUL-bearing, so the 0x01 twin is sent) and empty strings with extreme ints. The
 * audit and inventory helpers default to Rust; erasure (cheap fixed-table lookups), export
 * (PHP's json_encode() already sizes values in C) and retention (cheap integer arithmetic)
 * default to PHP.
 */
final class RustGdprAuditInventoryErasureExportRetentionServiceW60ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words]. */
    private const array HELPERS = [
        'audit' => [GdprAuditService::class, 'auditEventRowBatch', 6, 8],
        'inventory' => [GdprDataInventory::class, 'inventoryPlanRowBatch', 5, 7],
        'erasure' => [GdprErasureService::class, 'erasurePlanRowBatch', 5, 8],
        'export' => [GdprExportService::class, 'exportManifestRowBatch', 5, 7],
        'retention' => [GdprRetentionService::class, 'retentionPlanRowBatch', 5, 7],
    ];

    /** W60_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        GdprAuditService::class => true,
        GdprDataInventory::class => true,
        GdprErasureService::class => false,
        GdprExportService::class => false,
        GdprRetentionService::class => false,
    ];

    private const int SEED = 6042026;

    private const int NOW = 1_793_000_000;

    private const int DAY = 86_400;

    private const array SPICE = ['é', 'ß', '日本', '😀', "\t", "\n", "\r", "\0", "\x01", "\x0b", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', ':', '::', '.', '-', '_', '/', '\\', '"', '`', '@', 'A', 'Z', 'z', '0', '9', 'f', 'F'];

    private const array EVENTS = ['request_submitted', 'request_rejected', 'export_generated', 'erasure_soft_delete', 'erasure_force_delete',
        'consent_given', 'consent_withdrawn', 'data_rectified', 'restriction_applied'];

    private const array DESCRIPTIONS = ['GDPR data export generated.', 'GDPR erasure workflow completed; retained payment and audit records were anonymized/minimized.',
        'Request submitted by the data subject.', 'Rejected: identity could not be verified for user@example.com', 'Consent withdrawn via the privacy page.',
        'Données rectifiées à la demande de l’utilisateur.', ''];

    private const array TABLES = ['user_requests', 'user_downloads', 'dnzb_failures', 'users_releases', 'user_series', 'user_movies', 'user_excluded_categories',
        'user_invitations', 'trusted_devices', 'password_securities', 'passkeys', 'model_has_permissions', 'model_has_roles', 'role_promotion_stats',
        'release_comments', 'release_reports', 'payments', 'paypal_payments', 'user_activities', 'gdpr_audit_logs', 'user_role_history', 'invitations',
        'gdpr_requests', 'gdpr_consents', 'users', 'releases', 'settings', 'sessions'];

    private const array COLUMNS = ['email', 'email_address', 'ip_address', 'host', 'last_ip', 'username', 'name', 'firstname', 'lastname', 'password',
        'api_token', 'remember_token', 'sabapikey', 'cp_api', 'oauth_secret', 'backup_email', 'login_ip', 'users_id', 'user_id', 'id', 'model_id',
        'created_at', 'updated_at', 'timestamp', 'notes', 'description', 'grabs', 'style', 'authenticatable_id', 'used_by', 'invited_by'];

    private const array BASES = ['contract', 'legal_obligation', 'legitimate_interest', 'consent', '', 'unknown'];

    private const array SECTIONS = ['account', 'subject', 'passkeys', 'retained_audit_records', 'retained_payment_records', 'user_requests', 'user_downloads',
        'dnzb_failures', 'users_releases', 'user_series', 'user_movies', 'user_excluded_categories', 'user_invitations', 'release_comments',
        'release_reports', 'invitations_sent', 'invitations_used', 'trusted_devices', 'role_history', 'promotion_stats', 'gdpr_requests',
        'gdpr_consents', 'gdpr_audit_logs', 'retention_notice', 'essential_cookies'];

    private const array FIELDS = ['id', 'username', 'email', 'password', 'remember_token', 'session_token', 'api_token', 'resetguid', 'verification_token',
        'sabapikey', 'nzbgetpassword', 'nzbvortex_api_key', 'cp_api', 'host', 'created_at', 'updated_at', 'credential_id', 'data', 'name', 'notes',
        'releases_id', 'timestamp', 'style', 'grabs'];

    private const array VALUES = ['alice', 'alice@example.com', '2026-10-26T04:42:59+00:00', '192.168.1.20', 'https://example.com/a/b?c=d',
        '$2y$12$abcdefghijklmnopqrstuv', 'Ünïcødé ✓ 日本語', 'emoji 😀 test', "multi\nline\ttext", 'quote "inside" \\ backslash', '', '0', '12345',
        'deleted_user_42', '[Removed during account erasure]'];

    private const array RECORD_TYPES = ['export_file', 'gdpr_request', 'payment', 'audit_log', 'user_activity', 'session'];

    private const array DISKS = ['local', 's3', '', '0', 'backup-1', 'exports_eu', 'local '];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => [$class, $method, $words, $trailer]) {
            $cases[$name] = [$class, $method, $words, $trailer, $name];
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w60RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $cdef = (new ReflectionClass($class))->getConstant('W60_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w60', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW60RustFfi($native, static fn (): array => $class::{$method}($items));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $items */
    private static function assertParity(string $class, string $method, int $words, int $trailer, array $items, string $label): void
    {
        $php = $class::{$method.'Php'}($items);
        self::assertSame($php, self::dispatch($class, $method, false, $items), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $items), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}($items), $label.': native method');
        self::assertCount($items === [] ? 0 : \count($items) * $words + $trailer, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private static function bytes(Randomizer $random, int $length, ?string $alphabet = null): string
    {
        if ($length <= 0) {
            return '';
        }

        return $alphabet === null ? $random->getBytes($length) : $random->getBytesFromString($alphabet, $length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 2, 7, 30, 99, 100, 101, 365, 366, 999, 1000, 1001, 86_399, 86_400, 86_401, self::NOW, self::NOW + 1,
            self::NOW - 1, (1 << 40) - 1, 1 << 40, (1 << 40) + 1, (1 << 48) + 1, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1,
            $random->getInt(PHP_INT_MIN, PHP_INT_MAX), $random->getInt(-1000, 1000), $random->getInt(0, 1 << 41)]);
    }

    /** Insert, replace, delete or truncate a few bytes. */
    private static function mutate(Randomizer $random, string $value): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $length = \strlen($value);
            $at = $length === 0 ? 0 : $random->getInt(0, $length - 1);
            $value = match ($random->getInt(0, 3)) {
                0 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at),
                1 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at + 1),
                2 => substr($value, 0, $at).substr($value, $at + 1),
                default => substr($value, 0, $at),
            };
        }

        return $value;
    }

    /** A realistic string, sometimes mutated, random or empty in uncommon batches. */
    private static function spoil(Randomizer $random, string $value, bool $uncommon): string
    {
        if (! $uncommon) {
            return $value;
        }

        return match ($random->getInt(0, 5)) {
            0, 1 => self::mutate($random, $value),
            2 => self::bytes($random, $random->getInt(0, 40)),
            3 => '',
            default => $value,
        };
    }

    private static function int(Randomizer $random, int $value, bool $uncommon): int
    {
        return $uncommon && $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $value;
    }

    private static function ip(Randomizer $random): string
    {
        return match ($random->getInt(0, 4)) {
            0, 1, 2 => implode('.', [$random->getInt(1, 255), $random->getInt(0, 255), $random->getInt(0, 255), $random->getInt(0, 255)]),
            3 => \sprintf('2001:db8:%x::%x', $random->getInt(0, 0xFFFF), $random->getInt(1, 0xFFFF)),
            default => '',
        };
    }

    private static function actor(Randomizer $random): string
    {
        return match ($random->getInt(0, 4)) {
            0, 1 => 'user:'.$random->getInt(1, 2_000_000),
            2 => 'admin:'.$random->getInt(1, 50),
            3 => 'system',
            default => '',
        };
    }

    /** @return list<mixed> */
    private static function auditItem(Randomizer $random, bool $uncommon): array
    {
        $subject = $random->getInt(0, 4) === 0 ? 0 : $random->getInt(1, 2_000_000);
        $actor = self::actor($random);
        $actorId = str_contains($actor, ':') ? (int) substr($actor, strpos($actor, ':') + 1) : 0;
        if ($random->getInt(0, 5) === 0) {
            $actor = 'user:'.$subject;
            $actorId = $subject;
        }
        $now = self::NOW + $random->getInt(-self::DAY, self::DAY);

        return [
            self::spoil($random, self::pick($random, self::EVENTS), $uncommon),
            self::spoil($random, $actor, $uncommon),
            self::spoil($random, self::ip($random), $uncommon),
            self::spoil($random, self::pick($random, self::DESCRIPTIONS), $uncommon),
            self::int($random, $subject, $uncommon),
            self::int($random, $actorId, $uncommon),
            self::int($random, $random->getInt(0, 1) * $random->getInt(1, 90_000), $uncommon),
            self::int($random, $now - $random->getInt(0, 2400) * self::DAY - $random->getInt(0, self::DAY), $uncommon),
            self::int($random, $now, $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function inventoryItem(Randomizer $random, bool $uncommon): array
    {
        $table = self::pick($random, self::TABLES);
        $table = match ($random->getInt(0, 6)) {
            0 => 'nntmux.'.$table,
            1 => '`'.$table.'`',
            2 => ' '.strtoupper($table).' ',
            3 => '"nntmux"."'.$table.'"',
            default => $table,
        };
        $column = self::pick($random, self::COLUMNS);
        $column = $random->getInt(0, 5) === 0 ? '`'.strtoupper($column).'`' : $column;

        return [
            self::spoil($random, $table, $uncommon),
            self::spoil($random, $column, $uncommon),
            self::spoil($random, self::pick($random, self::BASES), $uncommon),
            self::int($random, $random->getInt(0, 5_000_000), $uncommon),
            self::int($random, $random->getInt(0, 3), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function erasureItem(Randomizer $random, bool $uncommon): array
    {
        $erasable = GdprDataInventory::erasableTablesPhp();
        $anonymized = GdprDataInventory::anonymizedTablesPhp();
        $table = self::pick($random, self::TABLES);
        $column = ($erasable[$table] ?? $anonymized[$table] ?? null)['user_column'] ?? self::pick($random, ['user_id', 'users_id', 'email']);
        if ($random->getInt(0, 7) === 0) {
            $column = self::pick($random, ['user_id', 'users_id', 'model_id', 'email']);
        }
        $now = self::NOW + $random->getInt(-self::DAY, self::DAY);

        return [
            self::spoil($random, $table, $uncommon),
            self::spoil($random, $column, $uncommon),
            self::spoil($random, self::pick($random, ['erase', 'anonymize', 'retain', 'erase', '']), $uncommon),
            self::int($random, $random->getInt(1, 20_000_000), $uncommon),
            self::int($random, $random->getInt(0, 100_000), $uncommon),
            self::int($random, $random->getInt(0, 6) === 0 ? $now + $random->getInt(-30, 400) * self::DAY : 0, $uncommon),
            self::int($random, $now, $uncommon),
            self::int($random, $random->getInt(0, 3), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function exportItem(Randomizer $random, bool $uncommon): array
    {
        $value = self::pick($random, self::VALUES);
        if ($random->getInt(0, 3) === 0) {
            $value = str_repeat($value, $random->getInt(2, 12));
        }

        return [
            self::spoil($random, self::pick($random, self::SECTIONS), $uncommon),
            self::spoil($random, self::pick($random, self::FIELDS), $uncommon),
            self::spoil($random, $value, $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? 0 : $random->getInt(1024, 65_536), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function retentionItem(Randomizer $random, bool $uncommon): array
    {
        $now = self::NOW + $random->getInt(-self::DAY, self::DAY);
        $created = $now - $random->getInt(0, 4000) * self::DAY - $random->getInt(0, self::DAY);

        return [
            self::spoil($random, self::pick($random, self::RECORD_TYPES), $uncommon),
            self::spoil($random, self::pick($random, self::DISKS), $uncommon),
            self::int($random, $created, $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? $created + 7 * self::DAY : 0, $uncommon),
            self::int($random, $now, $uncommon),
            self::int($random, $random->getInt(0, 30), $uncommon),
            self::int($random, self::pick($random, [0, 25, 50, 100, 200]), $uncommon),
        ];
    }

    /** @return list<list<mixed>> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'audit' => self::auditItem($random, $uncommon),
                'inventory' => self::inventoryItem($random, $uncommon),
                'erasure' => self::erasureItem($random, $uncommon),
                'export' => self::exportItem($random, $uncommon),
                default => self::retentionItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function edgeItems(string $key): array
    {
        $now = self::NOW;
        $nul = "a\0b";

        return match ($key) {
            'audit' => [
                ['request_submitted', 'user:5', '10.0.0.1', 'ok', 5, 5, 1, $now, $now],
                ['export_generated', 'admin:1', '255.255.255.255', 'x', 5, 2, 0, $now - 30 * self::DAY, $now],
                ['erasure_force_delete', 'system', '0.0.0.0', '', 0, 0, 0, $now - 1971 * self::DAY, $now],
                ['erasure_soft_delete', 'user:0', '01.2.3.4', 'a@b', 1, 0, 0, $now + 1, $now],
                ['abc', 'user:123456789012345678', '1.2.3', "\x7f", 0, 0, 0, 0, 0],
                ['ab', 'user:1234567890123456789', '1.2.3.4.5', "\xff", 0, 0, 0, PHP_INT_MIN, PHP_INT_MAX],
                [str_repeat('a', 64), 'admin:', '::', "\xed\xa0\x80", -1, -1, -1, PHP_INT_MAX, PHP_INT_MIN],
                [str_repeat('a', 65), 'user', ':::', 'é', PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, 1, 1],
                ['Request_submitted', 'user:1:2', '1::2::3', $nul, 1, 1, 0, $now - 1095 * self::DAY, $now],
                ['_request', 'USER:1', '2001:0db8:0000:0000:0000:ff00:0042:8329', "line\nbreak", 3, 4, 0, $now - 985 * self::DAY, $now],
                ['req-uest', 'user: 1', '2001:0db8:0000:0000:0000:ff00:0042:83290', "\0", 0, 0, 0, $now - 986 * self::DAY, $now],
                ['', '', 'g::1', '', 0, 0, 0, 0, 0],
                [$nul, $nul, $nul, $nul, 0, 0, 0, 0, 0],
                ['consent_given', 'admin:9', '256.0.0.1', '😀', 9, 9, 9, (1 << 40) + 5, (1 << 40) + 5],
                ['export_generated', 'user:7', '1.2.3.04', '@', 7, 8, 0, $now - 27 * self::DAY, $now],
            ],
            'inventory' => [
                ['users', 'email', 'contract', 10, 0],
                ['nntmux.`users`', '`API_TOKEN`', '', 10, 1],
                ['"db"."payments"', 'email', 'legal_obligation', 5, 2],
                [" \t\nGDPR_AUDIT_LOGS\0", 'user_id', 'consent', 1 << 50, 3],
                ['a.b.c.', 'id', 'contract', -5, -1],
                ['`', '"', '', 0, 0],
                [str_repeat('a', 64), str_repeat('b', 64), 'contract', 1, 0],
                [str_repeat('a', 65), str_repeat('b', 65), 'contract', 1, 0],
                ['user-requests', 'e-mail', 'consent', 1, 0],
                ['user_requests', 'secret_answer', 'contract', PHP_INT_MAX, 1],
                ['user_requests', 'last_ip', '', PHP_INT_MAX, 0],
                ['release_comments', 'host', '', PHP_INT_MIN, 0],
                ['passkeys', 'credential_token_id', 'contract', 7, 2],
                ['invitations', 'used_at', 'consent', 3, 4],
                [$nul, $nul, $nul, 0, 0],
                ['Ünïcødé', 'naïve', 'contract', 1, 0],
                ['', '', '', 0, 0],
            ],
            'erasure' => [
                ['user_requests', 'users_id', 'erase', 42, 3, 0, $now, 0],
                ['passkeys', 'authenticatable_id', 'anonymize', 42, 3, 0, $now, 3],
                ['release_comments', 'users_id', 'anonymize', 42, 9, 0, $now, 1],
                ['release_reports', 'user_id', 'erase', 42, 9, 0, $now, 2],
                ['payments', 'email', 'retain', 42, PHP_INT_MAX, 0, $now, 0],
                ['user_role_history', 'user_id', 'erase', PHP_INT_MAX, 1, 0, $now, 0],
                ['user_requests', 'users_id', 'erase', 0, 3, 0, $now, 0],
                ['user_requests', 'users_id', 'erase', PHP_INT_MIN, 3, 0, $now, 0],
                ['user_requests', 'users_id', 'erase', 1, 3, $now + 1, $now, 0],
                ['user_requests', 'users_id', 'erase', 1, 3, $now, $now, 0],
                ['user_requests', 'users_id', 'erase', 1, -3, PHP_INT_MAX, PHP_INT_MIN, 0],
                ['users', 'id', 'erase', 1, 3, 0, $now, 0],
                ['USER_REQUESTS', 'users_id', 'ERASE', 1, 3, 0, $now, PHP_INT_MIN],
                [$nul, $nul, $nul, 1, 1, 1, 1, 1],
                ['', '', '', 1, 1, 0, 0, -1],
            ],
            'export' => [
                ['account', 'password', 'secret', 0],
                ['account', 'email', 'a@b.c', 10],
                ['passkeys', 'credential_id', "\xff", 0],
                ['passkeys', 'data', 'blob', 1],
                ['subject', 'username', "\xff\xfe", 100],
                ['user_requests', "\xc3", 'x', 0],
                ['gdpr_audit_logs', 'metadata', '{"subject":"deleted_user_1","path":"a/b"}', -1],
                ['retained_audit_records', 'description', "\0\x01\x1f\x7f\"\\/\x08\x0c\n\r\t", PHP_INT_MAX],
                ['retained_payment_records', 'amount', '😀𝄞日本é', PHP_INT_MIN],
                ['unknown', '', '', 0],
                ['account', 'remember_token', "\xff", 0],
                ['Account', 'password', 'x', 0],
                [$nul, $nul, $nul, 0],
                ['user_downloads', 'name', "\xed\xa0\x80", 0],
                ['user_downloads', 'name', "\xf4\x90\x80\x80", 0],
                ['user_downloads', 'name', "\xc0\xaf", 0],
                ['user_downloads', 'name', "\xef\xbf\xbf\u{2028}", 0],
            ],
            default => [
                ['export_file', 'local', $now - 8 * self::DAY, 0, $now, 0, 0],
                ['export_file', '', $now - 7 * self::DAY, 0, $now, 0, 1],
                ['export_file', '0', $now - 7 * self::DAY + 1, 0, $now, 0, 1],
                ['export_file', 's3', $now - 8 * self::DAY, 0, $now, 3, 2],
                ['gdpr_request', 'local', $now - self::DAY, 0, $now, 0, 0],
                ['gdpr_request', 'local', $now - self::DAY, $now - 1, $now, 0, 0],
                ['payment', 'backup-1', $now - 3650 * self::DAY, 0, $now, 0, 5000],
                ['audit_log', 'bad disk', $now - 3000 * self::DAY, 0, $now, 400, -5],
                ['session', str_repeat('d', 64), 0, 0, $now, 0, 0],
                ['session', str_repeat('d', 65), $now + self::DAY, 0, $now, 0, 0],
                ['session', 'local', $now + self::DAY + 1, 0, $now, 0, 0],
                ['user_activity', 'local', PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                ['user_activity', 'local', PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
                ['unknown', 'local', $now, 0, $now, 0, 0],
                [$nul, $nul, 1, 1, 1, 1, 1],
                ['', '', 0, 0, 0, 0, 0],
                ['Export_file', 'LOCAL', $now - 30 * self::DAY, 0, $now, 0, 1],
            ],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w60_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertNotSame('', $key);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W60_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        $cdef = $reflection->getReflectionConstant('W60_FFI_CDEF');
        self::assertNotFalse($cdef);
        self::assertTrue($cdef->isPrivate());
        self::assertSame($words, $reflection->getConstant('W60_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W60_TRAILER_WORDS'));
        foreach (['usesW60RustFfi', 'withW60RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w60RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW60RustFfi());
        self::assertFalse($class::withW60RustFfi(false, static fn (): bool => $class::usesW60RustFfi()));
        self::assertTrue($class::withW60RustFfi(true, static fn (): bool => $class::usesW60RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW60RustFfi(), 'withW60RustFfi restores the default');

        $nested = $class::withW60RustFfi(false, static fn (): array => [
            $class::usesW60RustFfi(),
            $class::withW60RustFfi(true, static fn (): bool => $class::usesW60RustFfi()),
            $class::usesW60RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW60RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW60RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w60', $property->getName(), $class);
            }
            self::state($class, 'enabled', true);
            self::assertTrue($class::usesW60RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        GdprAuditService::withW60RustFfi(true, static function (): void {
            self::assertTrue(GdprAuditService::usesW60RustFfi());
            foreach ([GdprDataInventory::class, GdprErasureService::class, GdprExportService::class, GdprRetentionService::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W60_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW60RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $before = [GdprAuditService::usesRustFfi(), GdprAuditService::usesW49RustFfi(), GdprDataInventory::usesRustFfi(), GdprDataInventory::usesW49RustFfi(),
            GdprErasureService::usesRustFfi(), GdprErasureService::usesW49RustFfi(), GdprExportService::usesRustFfi(), GdprExportService::usesW49RustFfi(),
            GdprRetentionService::usesRustFfi(), GdprRetentionService::usesW49RustFfi()];
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW60RustFfi($enabled, static function () use ($before): void {
                    self::assertSame($before, [GdprAuditService::usesRustFfi(), GdprAuditService::usesW49RustFfi(), GdprDataInventory::usesRustFfi(),
                        GdprDataInventory::usesW49RustFfi(), GdprErasureService::usesRustFfi(), GdprErasureService::usesW49RustFfi(),
                        GdprExportService::usesRustFfi(), GdprExportService::usesW49RustFfi(), GdprRetentionService::usesRustFfi(),
                        GdprRetentionService::usesW49RustFfi()]);
                    self::assertSame('erasure_force_delete', GdprErasureService::resolveErasureEventNamePhp(true));
                    self::assertSame('gdpr/exports/user-7-20261026000000-abcdefghijkl.json', GdprExportService::formatExportPathPhp(7, '20261026000000', 'abcdefghijkl'));
                    self::assertSame(['should_purge' => true, 'disk' => 'local', 'path' => 'p'], GdprRetentionService::resolveExportPurgeTargetPhp('0', 'p', 1, 2));
                });
            }
        }
    }

    public function test_native_library_exports_every_symbol(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            self::assertTrue(self::native($class), $class);
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertGreaterThan(0, $words + $trailer);
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, []));
        }
        $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 3, false);
        $first = $items[0];
        $bad = [
            array_combine(['a', 'b', 'c'], $items),
            [1 => $first],
            [$first, 'not a record'],
            [\array_slice($first, 1)],
            [[...$first, 0]],
            [array_combine(range(1, \count($first)), $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (string) $v : 1.5, $first)],
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W60', $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_randomized_and_edge_parity(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($key)));
        $records = 0;
        foreach ([false, true] as $uncommon) {
            foreach ([1, 2, 7, 64, 320] as $size) {
                $items = self::batch($key, $random, $size, $uncommon);
                self::assertParity($class, $method, $words, $trailer, $items, $key.' batch '.$size.($uncommon ? ' uncommon' : ''));
                $records += $size;
            }
        }
        $edges = self::edgeItems($key);
        foreach ($edges as $i => $item) {
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i.' '.json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': every native call must succeed');
        }
    }

    public function test_hand_checked_rows(): void
    {
        $now = self::NOW;
        $audit = GdprAuditService::auditEventRowBatchPhp([
            ['export_generated', 'user:5', '10.1.2.3', 'mail a@b', 5, 5, 9, $now - 28 * self::DAY, $now],
            ['bad event!', 'user:x', 'nope', "\x01", 0, 0, 0, $now + 10, $now],
        ]);
        self::assertSame([2 | 2 << 4 | 1 << 8 | 1 << 12 | (1 | 2 | 4 | 8 | 64) << 16, 5, 10 << 16 | 1 << 8 | 2, 28, 30, 8], \array_slice($audit, 0, 6));
        self::assertSame([15 | 4 << 4 | 3 << 8 | 3 << 12 | 16 << 16, -1, -1, 0, 90, 1], \array_slice($audit, 6, 6));
        self::assertSame([1, 1, 0, 1, 1, 1, 28], \array_slice($audit, 12, 7));

        $inventory = GdprDataInventory::inventoryPlanRowBatchPhp([
            ['nntmux.`GDPR_AUDIT_LOGS`', 'api_token', 'contract', 10, 0],
            ['users', 'email', '', 4, 2],
            ['bad table', 'email', 'consent', 4, 0],
        ]);
        self::assertSame([12 | 4 << 4 | 4 << 8 | 1 << 12 | 2 << 16, 15, 9, 80, 10], \array_slice($inventory, 0, 5));
        self::assertSame([1 << 4 | 3 << 8 | 1 << 16 | 2 << 20, 5, 5, 12, 4], \array_slice($inventory, 5, 5));
        self::assertSame([0 | 1 << 4 | 3 << 8 | 4 << 12 | 3 << 16, 9, 5, 0, 4], \array_slice($inventory, 10, 5));
        self::assertSame([2, 1, 1, 1, 1, 92], \array_slice($inventory, 15, 6));

        $erasure = GdprErasureService::erasurePlanRowBatchPhp([
            ['model_has_roles', 'model_id', 'anonymize', 42, 3, 0, $now, 1],
            ['payments', 'email', 'retain', 42, 9, $now + 60, $now, 0],
        ]);
        self::assertSame([1 | 2 << 4 | (1 | 2 | 8) << 8, 13, 3, 15 | 31 << 16, 0], \array_slice($erasure, 0, 5));
        self::assertSame([4 | 3 << 4, 200, 0, 15 | 31 << 16, 60], \array_slice($erasure, 5, 5));
        self::assertSame([1, 0, 0, 1, 0, 0, 3], \array_slice($erasure, 10, 7));

        $export = GdprExportService::exportManifestRowBatchPhp([
            ['account', 'password', 'hunter2', 0],
            ['account', 'email', 'a/b', 10],
            ['passkeys', 'data', 'blob', 0],
        ]);
        self::assertSame([100 | 1 << 8, 8, 7, 0, 0], \array_slice($export, 0, 5));
        self::assertSame([100 | 16 << 8, 5, 3, 5, 0], \array_slice($export, 5, 5));
        self::assertSame([104 | 2 << 8, 4, 4, 12, 13], \array_slice($export, 10, 5));
        self::assertSame([1, 2, 0, 2, 13 + 6 + 12 + 1, 1], \array_slice($export, 15, 6));

        $retention = GdprRetentionService::retentionPlanRowBatchPhp([
            ['payment', 's3', $now - 100 * self::DAY, 0, $now, 0, 0],
            ['export_file', '0', $now - 8 * self::DAY, 0, $now, 2, 1],
            ['gdpr_request', 'bad disk', $now - 8 * self::DAY, 0, $now, 2, 1],
        ]);
        self::assertSame([0 | 3 << 4 | 1 << 8, $now + 3550 * self::DAY, $now + 3550 * self::DAY, 3550 * self::DAY, 100], \array_slice($retention, 0, 5));
        self::assertSame([1 | 1 << 4 | 1 << 10, $now - self::DAY, $now + self::DAY, self::DAY, 8], \array_slice($retention, 5, 5));
        self::assertSame([3 | 2 << 4 | 3 << 8, -1, -1, -1, 8], \array_slice($retention, 10, 5));
        self::assertSame([1, 1, 0, 1, 0, 0], \array_slice($retention, 15, 6));
    }

    public function test_retention_purge_chunks_follow_row_batch_sizes(): void
    {
        $now = self::NOW;
        $due = static fn (int $size): array => ['session', 'local', $now - 40 * self::DAY, 0, $now, 0, $size];
        $chunks = static fn (array $items): int => GdprRetentionService::retentionPlanRowBatchPhp($items)[\count($items) * 5 + 5];
        self::assertSame(1, $chunks([$due(2), $due(2)]));
        self::assertSame(2, $chunks([$due(2), $due(2), $due(2)]));
        self::assertSame(3, $chunks([$due(1), $due(1), $due(5)]));
        self::assertSame(1, $chunks(array_fill(0, 100, $due(0))));
        self::assertSame(2, $chunks(array_fill(0, 101, $due(-3))));
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('NNTMUX_W604_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W604_BENCH=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('NNTMUX_W604_BENCH_ROUNDS') ?: 600));
        $random = new Randomizer(new Mt19937(self::SEED));
        $report = [];
        $failures = [];
        foreach (self::HELPERS as $key => [$class, $method]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            $ratios = [];
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $php = [];
                $rust = [];
                for ($round = -10; $round < $rounds; $round++) {
                    $items = self::batch($key, $random, 320, $uncommon);
                    $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                    $times = [];
                    foreach ($order as $suffix) {
                        $start = hrtime(true);
                        $class::{$method.$suffix}($items);
                        $times[$suffix] = hrtime(true) - $start;
                    }
                    if ($round >= 0) {
                        $php[] = $times['Php'];
                        $rust[] = $times['Native'];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(0.99 * $rounds) - 1;
                $ratios[$workload.' mean'] = array_sum($php) / max(1, array_sum($rust));
                $ratios[$workload.' p99'] = $php[$p99] / max(1, $rust[$p99]);
            }
            $report[] = \sprintf('%s::%s %s', $key, $method, implode(', ', array_map(
                static fn (string $label, float $ratio): string => \sprintf('%s %.2fx', $label, $ratio), array_keys($ratios), $ratios)));
            if ($class::W60_RUST_FFI_DEFAULT && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2);
            }
            if (min($ratios) < 1.5) {
                self::assertFalse($class::usesW60RustFfi(), $key.' must retain PHP by default below 1.5x');
            }
        }
        fwrite(STDERR, "\nW60.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
