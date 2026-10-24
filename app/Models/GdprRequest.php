<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Throwable;

class GdprRequest extends Model
{
    public const string FFI_CDEF = 'int32_t nntmux_w23_gdpr_export_mail(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

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

    /** @param list<string|null> $fields */
    private static function callNative(int $operation, array $fields): ?string
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('wave23-gdpr-export-mail', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }

            $input = '';
            foreach ($fields as $field) {
                if ($field !== null && strlen($field) >= 0xFFFFFFFF) {
                    return null;
                }
                $input .= pack('V', $field === null ? 0xFFFFFFFF : strlen($field)).($field ?? '');
            }

            $capacity = min(67108864, max(64, strlen($input)));
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = self::$ffi->new("char[$capacity]");
                // @phpstan-ignore method.notFound
                $written = self::$ffi->nntmux_w23_gdpr_export_mail($operation, $input, strlen($input), $buffer, $capacity);
                if (! is_int($written) || $written === -1) {
                    return null;
                }
                if ($written >= 0) {
                    return $written <= $capacity ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
                }
                $required = -$written;
                if ($required <= $capacity || $required > 67108864) {
                    return null;
                }
                $capacity = $required;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public const TYPE_EXPORT = 'export';

    public const TYPE_ERASURE = 'erasure';

    public const TYPE_RECTIFICATION = 'rectification';

    public const TYPE_RESTRICTION = 'restriction';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'requester_username',
        'requester_email',
        'type',
        'status',
        'request_payload',
        'response_payload',
        'notes',
        'admin_notes',
        'export_disk',
        'export_path',
        'export_expires_at',
        'processed_by',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'export_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by')->withTrashed();
    }

    /**
     * @return HasMany<GdprAuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(GdprAuditLog::class)->latest();
    }

    /**
     * @param  Builder<GdprRequest>  $query
     * @return Builder<GdprRequest>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public static function evaluateDownloadableExport(string $type, string $status, ?string $exportPath, ?int $expiresAtTimestamp, int $nowTimestamp): bool
    {
        return self::$useRustFfi
            ? self::evaluateDownloadableExportNative($type, $status, $exportPath, $expiresAtTimestamp, $nowTimestamp)
            : self::evaluateDownloadableExportPhp($type, $status, $exportPath, $expiresAtTimestamp, $nowTimestamp);
    }

    public static function evaluateDownloadableExportPhp(string $type, string $status, ?string $exportPath, ?int $expiresAtTimestamp, int $nowTimestamp): bool
    {
        return $type === self::TYPE_EXPORT
            && $status === self::STATUS_COMPLETED
            && $exportPath !== null
            && ($expiresAtTimestamp === null || $expiresAtTimestamp > $nowTimestamp);
    }

    public static function evaluateDownloadableExportNative(string $type, string $status, ?string $exportPath, ?int $expiresAtTimestamp, int $nowTimestamp): bool
    {
        $result = self::callNative(10, [$type, $status, $exportPath, $expiresAtTimestamp === null ? null : (string) $expiresAtTimestamp, (string) $nowTimestamp]);

        return match ($result) {
            '1' => true,
            '0' => false,
            default => self::evaluateDownloadableExportPhp($type, $status, $exportPath, $expiresAtTimestamp, $nowTimestamp),
        };
    }

    public function isDownloadableExport(): bool
    {
        $expiresAt = $this->export_expires_at;
        $expiration = $expiresAt === null ? null : Carbon::parse($expiresAt);
        $nowTimestamp = Carbon::now()->getTimestamp();
        $expiresAtTimestamp = $expiration?->getTimestamp();
        $type = (string) $this->type;
        $status = (string) $this->status;
        $exportPath = $this->export_path;

        // Timestamp framing has second precision; retain Carbon's microsecond comparison.
        if ($expiresAtTimestamp !== null && $expiresAtTimestamp === $nowTimestamp) {
            return self::evaluateDownloadableExport($type, $status, $exportPath, null, $nowTimestamp)
                && $expiration->isFuture();
        }

        return self::evaluateDownloadableExport($type, $status, $exportPath, $expiresAtTimestamp, $nowTimestamp);
    }
}
