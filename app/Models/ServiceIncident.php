<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @property int $id
 * @property string $title
 * @property string $description
 * @property IncidentStatusEnum $status
 * @property IncidentImpactEnum $impact
 * @property Carbon $started_at
 * @property Carbon|null $resolved_at
 * @property int|null $created_by
 * @property bool $is_auto
 */
class ServiceIncident extends Model
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_service_incident_is_resolved(
        uint32_t has_status,
        const char *status_ptr,
        size_t status_len
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustCoverCartFailedControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
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

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'status',
        'impact',
        'started_at',
        'resolved_at',
        'created_by',
        'is_auto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IncidentStatusEnum::class,
            'impact' => IncidentImpactEnum::class,
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'is_auto' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<ServiceStatus, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(ServiceStatus::class, 'service_incident_service_status')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isResolved(): bool
    {
        return self::evaluateIsResolved($this->status);
    }

    public static function evaluateIsResolved(IncidentStatusEnum|string|null $status): bool
    {
        if (self::$useRustFfi) {
            return self::evaluateIsResolvedNative($status);
        }

        return self::evaluateIsResolvedPhp($status);
    }

    public static function evaluateIsResolvedPhp(IncidentStatusEnum|string|null $status): bool
    {
        if ($status instanceof IncidentStatusEnum) {
            return $status === IncidentStatusEnum::Resolved;
        }

        return $status === IncidentStatusEnum::Resolved->value;
    }

    public static function evaluateIsResolvedNative(IncidentStatusEnum|string|null $status): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::evaluateIsResolvedPhp($status);
        }

        try {
            $value = $status instanceof IncidentStatusEnum ? $status->value : $status;
            $hasStatus = $value !== null ? 1 : 0;
            $str = $value ?? '';
            $rc = (int) self::callFfi($ffi, 'nntmux_service_incident_is_resolved', $hasStatus, $str, strlen($str));
            if ($rc < 0) {
                return self::evaluateIsResolvedPhp($status);
            }

            return $rc === 1;
        } catch (Throwable) {
            return self::evaluateIsResolvedPhp($status);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
