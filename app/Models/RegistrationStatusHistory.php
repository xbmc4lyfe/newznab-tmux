<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class RegistrationStatusHistory extends Model
{
    public const ACTION_MANUAL_STATUS_CHANGED = 'manual_status_changed';

    public const ACTION_PERIOD_CREATED = 'period_created';

    public const ACTION_PERIOD_UPDATED = 'period_updated';

    public const ACTION_PERIOD_TOGGLED = 'period_toggled';

    public const ACTION_PERIOD_COMPLETED = 'period_completed';

    public const ACTION_PERIOD_DELETED = 'period_deleted';

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_registration_history_attributes(const char *action, size_t action_len, const char *description, size_t description_len, const int64_t *ints, uint32_t present_mask, int64_t *out_ints, uint32_t *out_mask, size_t *out_lens, char *out, size_t cap);
        C;

    private const int DECLINE = -2147483648;

    /**
     * The Rust helper stays opt-in: it did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchSyncObserversHistoryParityTest.php).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $table = 'registration_status_history';

    protected $fillable = [
        'action',
        'old_status',
        'new_status',
        'registration_period_id',
        'changed_by',
        'description',
        'metadata',
    ];

    protected $casts = [
        'old_status' => 'integer',
        'new_status' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * @return BelongsTo<RegistrationPeriod, $this>
     */
    public function registrationPeriod(): BelongsTo
    {
        return $this->belongsTo(RegistrationPeriod::class, 'registration_period_id');
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $action,
        string $description,
        ?int $changedBy = null,
        ?int $oldStatus = null,
        ?int $newStatus = null,
        ?int $registrationPeriodId = null,
        array $metadata = []
    ): self {
        return self::create(self::recordAttributes(
            $action,
            $description,
            $changedBy,
            $oldStatus,
            $newStatus,
            $registrationPeriodId,
            $metadata,
        ));
    }

    /**
     * Attributes passed to `create()` by `record()`.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{action: string, description: string, changed_by: ?int, old_status: ?int, new_status: ?int, registration_period_id: ?int, metadata: array<string, mixed>}
     */
    public static function recordAttributes(
        string $action,
        string $description,
        ?int $changedBy = null,
        ?int $oldStatus = null,
        ?int $newStatus = null,
        ?int $registrationPeriodId = null,
        array $metadata = []
    ): array {
        return self::usesRustFfi()
            ? self::recordAttributesNative($action, $description, $changedBy, $oldStatus, $newStatus, $registrationPeriodId, $metadata)
            : self::recordAttributesPhp($action, $description, $changedBy, $oldStatus, $newStatus, $registrationPeriodId, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{action: string, description: string, changed_by: ?int, old_status: ?int, new_status: ?int, registration_period_id: ?int, metadata: array<string, mixed>}
     */
    public static function recordAttributesPhp(
        string $action,
        string $description,
        ?int $changedBy = null,
        ?int $oldStatus = null,
        ?int $newStatus = null,
        ?int $registrationPeriodId = null,
        array $metadata = []
    ): array {
        return [
            'action' => $action,
            'description' => $description,
            'changed_by' => $changedBy,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'registration_period_id' => $registrationPeriodId,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{action: string, description: string, changed_by: ?int, old_status: ?int, new_status: ?int, registration_period_id: ?int, metadata: array<string, mixed>}
     */
    public static function recordAttributesNative(
        string $action,
        string $description,
        ?int $changedBy = null,
        ?int $oldStatus = null,
        ?int $newStatus = null,
        ?int $registrationPeriodId = null,
        array $metadata = []
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::recordAttributesPhp($action, $description, $changedBy, $oldStatus, $newStatus, $registrationPeriodId, $metadata);
        }

        try {
            $values = [$changedBy, $oldStatus, $newStatus, $registrationPeriodId];
            $ints = $ffi->new('int64_t[4]');
            $mask = 0;
            foreach ($values as $slot => $value) {
                if ($value !== null) {
                    $ints[$slot] = $value;
                    $mask |= 1 << $slot;
                }
            }
            $outInts = $ffi->new('int64_t[4]');
            $outMask = $ffi->new('uint32_t[1]');
            $outLens = $ffi->new('size_t[2]');
            $total = \strlen($action) + \strlen($description);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(1, $total), $cap);
            if ($scratch === null) {
                return self::recordAttributesPhp($action, $description, $changedBy, $oldStatus, $newStatus, $registrationPeriodId, $metadata);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_registration_history_attributes(
                $action,
                \strlen($action),
                $description,
                \strlen($description),
                $ints,
                $mask,
                $outInts,
                $outMask,
                $outLens,
                $scratch,
                $cap,
            );
            if ($written === self::DECLINE || $written !== $total || $outMask[0] !== $mask || $outLens[0] !== \strlen($action)) {
                return self::recordAttributesPhp($action, $description, $changedBy, $oldStatus, $newStatus, $registrationPeriodId, $metadata);
            }
            $payload = $total === 0 ? '' : FFI::string($scratch, $total);
            $resolved = [];
            foreach (range(0, 3) as $slot) {
                $resolved[$slot] = ($mask & (1 << $slot)) !== 0 ? (int) $outInts[$slot] : null;
            }

            return [
                'action' => substr($payload, 0, $outLens[0]),
                'description' => substr($payload, $outLens[0]),
                'changed_by' => $resolved[0],
                'old_status' => $resolved[1],
                'new_status' => $resolved[2],
                'registration_period_id' => $resolved[3],
                'metadata' => $metadata,
            ];
        } catch (Throwable) {
            return self::recordAttributesPhp($action, $description, $changedBy, $oldStatus, $newStatus, $registrationPeriodId, $metadata);
        }
    }
}
