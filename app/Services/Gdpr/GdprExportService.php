<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

use App\Models\GdprRequest;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserActivity;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GdprExportService
{
    private const EXPORT_DISK = 'local';

    /**
     * Secrets and operational tokens are not included in data portability exports.
     *
     * @var list<string>
     */
    private const REDACTED_USER_COLUMNS = [
        'password',
        'remember_token',
        'session_token',
        'api_token',
        'resetguid',
        'verification_token',
        'sabapikey',
        'nzbgetpassword',
        'nzbvortex_api_key',
        'cp_api',
    ];

    public function __construct(
        private readonly GdprDataInventory $inventory = new GdprDataInventory,
        private readonly GdprAuditService $audit = new GdprAuditService,
        private readonly GdprNotificationService $notifications = new GdprNotificationService,
    ) {}

    /**
     * Generate and persist a JSON data export for a GDPR access/data portability request.
     *
     * @return array{disk: string, path: string, expires_at: Carbon}
     */
    public function generate(User $user, ?GdprRequest $request = null, ?User $actor = null): array
    {
        $payload = $this->buildPayload($user);
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = self::formatExportPath((int) $user->id, now()->format('YmdHis'), Str::random(12));

        Storage::disk(self::EXPORT_DISK)->put($path, $encoded);

        $expiresAt = now()->addDays(7);

        if ($request !== null) {
            $request->update([
                'status' => GdprRequest::STATUS_COMPLETED,
                'export_disk' => self::EXPORT_DISK,
                'export_path' => $path,
                'export_expires_at' => $expiresAt,
                'completed_at' => now(),
                'response_payload' => [
                    'format' => 'json',
                    'retained_records' => $this->inventory->retainedRecords(),
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ]);
        }

        $this->audit->record(
            event: 'export_generated',
            description: 'GDPR data export generated.',
            subject: $user,
            actor: $actor,
            request: $request,
            metadata: ['path' => $path, 'expires_at' => $expiresAt->toIso8601String()]
        );

        if ($request !== null) {
            $this->notifications->exportReady($request->fresh() ?? $request);
        }

        return [
            'disk' => self::EXPORT_DISK,
            'path' => $path,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(User $user): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'format_version' => 1,
            'subject' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
            ],
            'account' => $this->exportAccount($user),
            'related_records' => $this->exportRelatedTables($user),
            'retained_audit_records' => $this->exportAuditRecords($user),
            'retained_payment_records' => $this->exportPaymentRecords($user),
            'retention_notice' => [
                'summary' => 'Payment and audit records may be retained after erasure where required for legal, accounting, security, dispute, or GDPR accountability purposes, with direct account identifiers anonymized or minimized where practical.',
                'records' => $this->inventory->retainedRecords(),
            ],
            'essential_cookies' => $this->inventory->essentialCookies(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportAccount(User $user): array
    {
        $attributes = self::redactAccountAttributes($user->getAttributes());
        $attributes['roles'] = $user->roles()->pluck('name')->all();

        if (Schema::hasTable('passkeys')) {
            $attributes['passkeys'] = $this->tableRows('passkeys', 'authenticatable_id', (int) $user->id, redactedColumns: ['credential_id', 'data']);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportRelatedTables(User $user): array
    {
        $records = [];

        foreach ($this->inventory->exportTables() as $key => $definition) {
            $table = $definition['table'] ?? $key;
            $userColumn = $definition['user_column'];
            $orderBy = $definition['order_by'] ?? null;

            $records[$key] = $this->tableRows($table, $userColumn, (int) $user->id, $orderBy);
        }

        return $records;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportAuditRecords(User $user): array
    {
        if (! Schema::hasTable('user_activities')) {
            return [];
        }

        return UserActivity::query()
            ->where('user_id', $user->id)
            ->orWhere('username', $user->username)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserActivity $activity): array => $this->modelToArray($activity))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportPaymentRecords(User $user): array
    {
        if (! Schema::hasTable('payments')) {
            return [];
        }

        return Payment::query()
            ->where('email', $user->email)
            ->orWhere('username', $user->username)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Payment $payment): array => $this->modelToArray($payment))
            ->all();
    }

    /**
     * @param  list<string>  $redactedColumns
     * @return array<int, array<string, mixed>>
     */
    private function tableRows(string $table, string $userColumn, int $userId, ?string $orderBy = null, array $redactedColumns = []): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $userColumn)) {
            return [];
        }

        $query = DB::table($table)->where($userColumn, $userId);

        if ($orderBy !== null && Schema::hasColumn($table, $orderBy)) {
            $query->orderByDesc($orderBy);
        }

        return $query->get()
            ->map(fn (object $row): array => $this->rowToArray($row, $redactedColumns))
            ->all();
    }

    /**
     * @param  list<string>  $redactedColumns
     * @return array<string, mixed>
     */
    private function rowToArray(object $row, array $redactedColumns = []): array
    {
        $data = json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return self::redactRowData($data, $redactedColumns);
    }

    /**
     * @return array<string, mixed>
     */
    private function modelToArray(object $model): array
    {
        return json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public const string FFI_CDEF = 'int32_t nntmux_w23_gdpr_export_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Retained until every helper qualifies at >=1.5x mean and p99 (see parity benchmark).
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

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

    /** @param list<?string> $frames */
    private static function computeNative(int $operation, array $frames): ?string
    {
        try {
            $input = '';
            foreach ($frames as $frame) {
                $input .= $frame === null ? pack('V', 0xFFFFFFFF) : pack('V', strlen($frame)).$frame;
            }
            $length = strlen($input);
            if ($length > 64 * 1024 * 1024) {
                return null;
            }
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave23-gdpr-export-mail', self::FFI_CDEF) ?? false;
            $ffi = self::$ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = max(256, $length + 256);
            $output = $ffi->new('char['.$capacity.']');
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w23_gdpr_export_mail($operation, $input, $length, $output, $capacity);
            if (is_int($written) && $written < -1 && -$written <= 64 * 1024 * 1024) {
                $capacity = -$written;
                $output = $ffi->new('char['.$capacity.']');
                // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
                $written = $ffi->nntmux_w23_gdpr_export_mail($operation, $input, $length, $output, $capacity);
            }

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? FFI::string($output, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function formatExportPath(int $userId, string $timestamp, string $random): string
    {
        return self::$useRustFfi ? self::formatExportPathNative($userId, $timestamp, $random) : self::formatExportPathPhp($userId, $timestamp, $random);
    }

    public static function formatExportPathNative(int $userId, string $timestamp, string $random): string
    {
        return self::computeNative(5, [(string) $userId, $timestamp, $random]) ?? self::formatExportPathPhp($userId, $timestamp, $random);
    }

    public static function formatExportPathPhp(int $userId, string $timestamp, string $random): string
    {
        return 'gdpr/exports/user-'.$userId.'-'.$timestamp.'-'.$random.'.json';
    }

    /** @param array<array-key, mixed> $attributes
     * @return array<array-key, mixed>
     */
    public static function redactAccountAttributes(array $attributes): array
    {
        return self::$useRustFfi ? self::redactAccountAttributesNative($attributes) : self::redactAccountAttributesPhp($attributes);
    }

    /** @param array<array-key, mixed> $attributes
     * @return array<array-key, mixed>
     */
    public static function redactAccountAttributesNative(array $attributes): array
    {
        $mask = self::computeNative(6, array_map(strval(...), array_keys($attributes)));
        if ($mask === null || strlen($mask) !== count($attributes) || strspn($mask, '01') !== strlen($mask)) {
            return self::redactAccountAttributesPhp($attributes);
        }
        foreach (array_keys($attributes) as $index => $key) {
            if ($mask[$index] === '1') {
                unset($attributes[$key]);
            }
        }
        $attributes['redacted_columns'] = self::REDACTED_USER_COLUMNS;

        return $attributes;
    }

    /** @param array<array-key, mixed> $attributes
     * @return array<array-key, mixed>
     */
    public static function redactAccountAttributesPhp(array $attributes): array
    {
        foreach (self::REDACTED_USER_COLUMNS as $column) {
            unset($attributes[$column]);
        }
        $attributes['redacted_columns'] = self::REDACTED_USER_COLUMNS;

        return $attributes;
    }

    /** @param array<array-key, mixed> $data
     * @param  list<string>  $redactedColumns
     * @return array<array-key, mixed>
     */
    public static function redactRowData(array $data, array $redactedColumns): array
    {
        return self::$useRustFfi ? self::redactRowDataNative($data, $redactedColumns) : self::redactRowDataPhp($data, $redactedColumns);
    }

    /** @param array<array-key, mixed> $data
     * @param  list<string>  $redactedColumns
     * @return array<array-key, mixed>
     */
    public static function redactRowDataNative(array $data, array $redactedColumns): array
    {
        $frames = [(string) count($data), ...array_map(strval(...), array_keys($data)), ...$redactedColumns];
        $mask = self::computeNative(7, $frames);
        if ($mask === null || strlen($mask) !== count($data) || strspn($mask, '01') !== strlen($mask)) {
            return self::redactRowDataPhp($data, $redactedColumns);
        }
        foreach (array_keys($data) as $index => $key) {
            if ($mask[$index] === '1') {
                $data[$key] = '[redacted]';
            }
        }

        return $data;
    }

    /** @param array<array-key, mixed> $data
     * @param  list<string>  $redactedColumns
     * @return array<array-key, mixed>
     */
    public static function redactRowDataPhp(array $data, array $redactedColumns): array
    {
        foreach ($redactedColumns as $column) {
            if (array_key_exists($column, $data)) {
                $data[$column] = '[redacted]';
            }
        }

        return $data;
    }
}
