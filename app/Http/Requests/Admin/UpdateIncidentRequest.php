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

class UpdateIncidentRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_update_incident_rule_spec(
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
     * tests/Unit/RustProfileThemeAdminRequestParityTest.php.
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

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $spec = self::incidentRuleSpec(IncidentImpactEnum::values(), IncidentStatusEnum::values());

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
     * @return array{impact_values: list<string>, status_values: list<string>, title_max: int, service_status_ids_min: int}
     */
    public static function incidentRuleSpec(array $impactValues, array $statusValues): array
    {
        return self::$useRustFfi
            ? self::incidentRuleSpecNative($impactValues, $statusValues)
            : self::incidentRuleSpecPhp($impactValues, $statusValues);
    }

    /**
     * @param  array<array-key, string>  $impactValues
     * @param  array<array-key, string>  $statusValues
     * @return array{impact_values: list<string>, status_values: list<string>, title_max: int, service_status_ids_min: int}
     */
    public static function incidentRuleSpecNative(array $impactValues, array $statusValues): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::incidentRuleSpecPhp($impactValues, $statusValues);
        }

        foreach ([$impactValues, $statusValues] as $values) {
            foreach ($values as $value) {
                if (! is_string($value) || str_contains($value, "\0")) {
                    return self::incidentRuleSpecPhp($impactValues, $statusValues);
                }
            }
        }

        $impactCount = count($impactValues);
        $statusCount = count($statusValues);
        $impactPacked = implode("\0", $impactValues);
        $statusPacked = implode("\0", $statusValues);

        try {
            $metaSlot = $ffi->new('size_t[4]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($impactPacked) + strlen($statusPacked) + 2), $capacity);
            if ($outBuf === null) {
                return self::incidentRuleSpecPhp($impactValues, $statusValues);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_update_incident_rule_spec',
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
                return self::incidentRuleSpecPhp($impactValues, $statusValues);
            }

            $cleanImpactCount = (int) $metaSlot[0];
            $cleanStatusCount = (int) $metaSlot[1];
            $total = $cleanImpactCount + $cleanStatusCount;
            $values = $total === 0 ? [] : explode("\0", FFI::string($outBuf, $written));
            if (count($values) !== $total || ($total === 0 && $written !== 0)) {
                return self::incidentRuleSpecPhp($impactValues, $statusValues);
            }

            return [
                'impact_values' => array_slice($values, 0, $cleanImpactCount),
                'status_values' => array_slice($values, $cleanImpactCount),
                'title_max' => (int) $metaSlot[2],
                'service_status_ids_min' => (int) $metaSlot[3],
            ];
        } catch (Throwable) {
            return self::incidentRuleSpecPhp($impactValues, $statusValues);
        }
    }

    /**
     * @param  array<array-key, string>  $impactValues
     * @param  array<array-key, string>  $statusValues
     * @return array{impact_values: list<string>, status_values: list<string>, title_max: int, service_status_ids_min: int}
     */
    public static function incidentRuleSpecPhp(array $impactValues, array $statusValues): array
    {
        return [
            'impact_values' => self::sanitizeEnumValuesPhp($impactValues),
            'status_values' => self::sanitizeEnumValuesPhp($statusValues),
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
