<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncidentImpactEnum;
use App\Enums\ServiceStatusEnum;
use App\Support\RustPipelineLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $endpoint_url
 * @property string $check_type
 * @property string|null $probe_identifier
 * @property ServiceStatusEnum $status
 * @property Carbon|null $last_checked_at
 * @property string $uptime_percentage
 * @property int|null $response_time_ms
 * @property bool $is_enabled
 * @property int $sort_order
 */
class ServiceStatus extends Model
{
    protected $table = 'service_statuses';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'endpoint_url',
        'check_type',
        'probe_identifier',
        'status',
        'last_checked_at',
        'uptime_percentage',
        'response_time_ms',
        'is_enabled',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceStatusEnum::class,
            'last_checked_at' => 'datetime',
            'is_enabled' => 'boolean',
            'uptime_percentage' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsToMany<ServiceIncident, $this>
     */
    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(ServiceIncident::class, 'service_incident_service_status')
            ->withTimestamps();
    }

    public const string FFI_CDEF = 'ssize_t nntmux_w346_configuration_status(uint8_t operation, const int64_t *input, size_t input_count, int64_t *output, size_t output_capacity);';

    // These small computations retain PHP until caller-inclusive benchmarks qualify Rust.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * @param  list<int>  $input
     * @return list<int>|null
     */
    private static function rustValues(int $operation, array $input, int $count): ?array
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('w346', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $length = count($input);
            $source = $ffi->new("int64_t[{$length}]");
            $output = $ffi->new("int64_t[{$count}]");
            foreach ($input as $index => $value) {
                $source[$index] = $value;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w346_configuration_status($operation, $source, $length, $output, $count);
            if ($written !== $count) {
                return null;
            }
            $values = [];
            for ($index = 0; $index < $count; $index++) {
                $values[] = (int) $output[$index];
            }

            return $values;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A single probe's proposed transition; incident aggregation and persistence stay with the caller.
     *
     * @return array{status: ServiceStatusEnum, changed: bool}
     */
    public static function probeTransition(ServiceStatusEnum $current, bool $ok, ?IncidentImpactEnum $impact): array
    {
        if (self::usesRustFfi()) {
            $statuses = [ServiceStatusEnum::Operational, ServiceStatusEnum::Degraded, ServiceStatusEnum::PartialOutage, ServiceStatusEnum::MajorOutage, ServiceStatusEnum::Maintenance];
            $impactCode = $impact === null ? -1 : array_search($impact, IncidentImpactEnum::cases(), true);
            $currentCode = array_search($current, $statuses, true);
            if ($currentCode !== false && $impactCode !== false && ($values = self::rustValues(4, [$currentCode, (int) $ok, $impactCode], 2)) !== null && isset($statuses[$values[0]])) {
                return ['status' => $statuses[$values[0]], 'changed' => (bool) $values[1]];
            }
        }

        return self::probeTransitionPhp($current, $ok, $impact);
    }

    /** @return array{status: ServiceStatusEnum, changed: bool} */
    public static function probeTransitionPhp(ServiceStatusEnum $current, bool $ok, ?IncidentImpactEnum $impact): array
    {
        $status = $ok ? ServiceStatusEnum::Operational : match ($impact) {
            IncidentImpactEnum::None, IncidentImpactEnum::Minor => ServiceStatusEnum::Degraded,
            null, IncidentImpactEnum::Major => ServiceStatusEnum::PartialOutage,
            IncidentImpactEnum::Critical => ServiceStatusEnum::MajorOutage,
        };

        return ['status' => $status, 'changed' => $current !== $status];
    }
}
