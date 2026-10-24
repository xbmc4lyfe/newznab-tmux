<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\IncidentImpactEnum;
use App\Enums\IncidentStatusEnum;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class StoreIncidentRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_store_incident_validation_spec(
        const char *impact_ptr,
        size_t impact_len,
        size_t impact_count,
        const char *status_ptr,
        size_t status_len,
        size_t status_count,
        size_t *out_meta,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustAdminIncidentContentRequestParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
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

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $spec = self::incidentValidationSpec(IncidentImpactEnum::values(), IncidentStatusEnum::values());

        return [
            'title' => ['required', 'string', 'max:'.$spec['title_max']],
            'description' => ['required', 'string'],
            'service_status_ids' => ['required', 'array', 'min:'.$spec['service_status_ids_min']],
            'service_status_ids.*' => ['integer', 'distinct', 'exists:service_statuses,id'],
            'impact' => ['required', 'string', Rule::in($spec['impact_values'])],
            'status' => ['required', 'string', Rule::in($spec['status_values'])],
            'started_at' => ['required', 'date'],
            'resolved_at' => ['nullable', 'date', 'after_or_equal:started_at'],
        ];
    }

    /**
     * @param  array<array-key, string>  $impactValues
     * @param  array<array-key, string>  $statusValues
     * @return array{
     *     impact_values: list<string>,
     *     status_values: list<string>,
     *     impact_in_rule: string,
     *     status_in_rule: string,
     *     title_max: int,
     *     service_status_ids_min: int
     * }
     */
    public static function incidentValidationSpec(array $impactValues, array $statusValues): array
    {
        return self::$useRustFfi
            ? self::incidentValidationSpecNative($impactValues, $statusValues)
            : self::incidentValidationSpecPhp($impactValues, $statusValues);
    }

    /**
     * @param  array<array-key, string>  $impactValues
     * @param  array<array-key, string>  $statusValues
     * @return array{
     *     impact_values: list<string>,
     *     status_values: list<string>,
     *     impact_in_rule: string,
     *     status_in_rule: string,
     *     title_max: int,
     *     service_status_ids_min: int
     * }
     */
    public static function incidentValidationSpecNative(array $impactValues, array $statusValues): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::incidentValidationSpecPhp($impactValues, $statusValues);
        }

        foreach ($impactValues as $val) {
            if (! is_string($val) || str_contains($val, "\0")) {
                return self::incidentValidationSpecPhp($impactValues, $statusValues);
            }
        }
        foreach ($statusValues as $val) {
            if (! is_string($val) || str_contains($val, "\0")) {
                return self::incidentValidationSpecPhp($impactValues, $statusValues);
            }
        }

        $impactCount = count($impactValues);
        $statusCount = count($statusValues);
        $impactPacked = $impactCount === 0 ? '' : implode("\0", array_values($impactValues));
        $statusPacked = $statusCount === 0 ? '' : implode("\0", array_values($statusValues));

        try {
            $metaSlot = $ffi->new('size_t[8]');
            $capacity = 0;
            $requiredCap = max(256, (strlen($impactPacked) + strlen($statusPacked)) * 2 + 64);
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, $requiredCap, $capacity);
            if ($outBuf === null) {
                return self::incidentValidationSpecPhp($impactValues, $statusValues);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_store_incident_validation_spec',
                $impactPacked,
                strlen($impactPacked),
                $impactCount,
                $statusPacked,
                strlen($statusPacked),
                $statusCount,
                $metaSlot,
                $outBuf,
                $capacity
            );

            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return self::incidentValidationSpecPhp($impactValues, $statusValues);
            }

            $cleanImpactCount = (int) $metaSlot[0];
            $cleanStatusCount = (int) $metaSlot[1];
            $impactValsLen = (int) $metaSlot[2];
            $statusValsLen = (int) $metaSlot[3];
            $impactRuleLen = (int) $metaSlot[4];
            $statusRuleLen = (int) $metaSlot[5];
            $titleMax = (int) $metaSlot[6];
            $serviceStatusIdsMin = (int) $metaSlot[7];

            if ($impactValsLen + $statusValsLen + $impactRuleLen + $statusRuleLen !== $written) {
                return self::incidentValidationSpecPhp($impactValues, $statusValues);
            }

            $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
            $pos = 0;

            $impactSlice = $impactValsLen === 0 ? '' : substr($payload, $pos, $impactValsLen);
            $pos += $impactValsLen;
            $statusSlice = $statusValsLen === 0 ? '' : substr($payload, $pos, $statusValsLen);
            $pos += $statusValsLen;
            $impactRule = substr($payload, $pos, $impactRuleLen);
            $pos += $impactRuleLen;
            $statusRule = substr($payload, $pos, $statusRuleLen);

            $cleanImpact = $cleanImpactCount === 0 ? [] : explode("\0", $impactSlice);
            $cleanStatus = $cleanStatusCount === 0 ? [] : explode("\0", $statusSlice);

            if (count($cleanImpact) !== $cleanImpactCount || count($cleanStatus) !== $cleanStatusCount) {
                return self::incidentValidationSpecPhp($impactValues, $statusValues);
            }

            return [
                'impact_values' => $cleanImpact,
                'status_values' => $cleanStatus,
                'impact_in_rule' => $impactRule,
                'status_in_rule' => $statusRule,
                'title_max' => $titleMax,
                'service_status_ids_min' => $serviceStatusIdsMin,
            ];
        } catch (Throwable) {
            return self::incidentValidationSpecPhp($impactValues, $statusValues);
        }
    }

    /**
     * @param  array<array-key, string>  $impactValues
     * @param  array<array-key, string>  $statusValues
     * @return array{
     *     impact_values: list<string>,
     *     status_values: list<string>,
     *     impact_in_rule: string,
     *     status_in_rule: string,
     *     title_max: int,
     *     service_status_ids_min: int
     * }
     */
    public static function incidentValidationSpecPhp(array $impactValues, array $statusValues): array
    {
        $cleanImpact = self::sanitizeEnumValuesPhp($impactValues);
        $cleanStatus = self::sanitizeEnumValuesPhp($statusValues);

        return [
            'impact_values' => $cleanImpact,
            'status_values' => $cleanStatus,
            'impact_in_rule' => 'in:'.implode(',', $cleanImpact),
            'status_in_rule' => 'in:'.implode(',', $cleanStatus),
            'title_max' => 255,
            'service_status_ids_min' => 1,
        ];
    }

    /**
     * @param  array<array-key, string>  $values
     * @return list<string>
     */
    private static function sanitizeEnumValuesPhp(array $values): array
    {
        $seen = [];
        $result = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '' || isset($seen[$trimmed])) {
                continue;
            }
            $seen[$trimmed] = true;
            $result[] = $trimmed;
        }

        return $result;
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

        $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
