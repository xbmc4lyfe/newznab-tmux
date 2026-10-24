<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ServiceStatusEnum;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class UpdateServiceHealthRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_service_health_status_values(
        const char *status_ptr,
        size_t status_len,
        size_t status_count,
        size_t *out_count,
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
        return [
            'status' => ['required', 'string', Rule::in(self::statusRuleValues(ServiceStatusEnum::values()))],
        ];
    }

    /**
     * @param  array<array-key, string>  $statusValues
     * @return list<string>
     */
    public static function statusRuleValues(array $statusValues): array
    {
        return self::$useRustFfi
            ? self::statusRuleValuesNative($statusValues)
            : self::statusRuleValuesPhp($statusValues);
    }

    /**
     * @param  array<array-key, string>  $statusValues
     * @return list<string>
     */
    public static function statusRuleValuesNative(array $statusValues): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::statusRuleValuesPhp($statusValues);
        }

        foreach ($statusValues as $value) {
            if (! is_string($value) || str_contains($value, "\0")) {
                return self::statusRuleValuesPhp($statusValues);
            }
        }

        $count = count($statusValues);
        $packed = implode("\0", $statusValues);

        try {
            $countSlot = $ffi->new('size_t[1]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(128, strlen($packed) + 1), $capacity);
            if ($outBuf === null) {
                return self::statusRuleValuesPhp($statusValues);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_service_health_status_values',
                $packed,
                strlen($packed),
                $count,
                $countSlot,
                $outBuf,
                $capacity
            );
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return self::statusRuleValuesPhp($statusValues);
            }

            $cleanCount = (int) $countSlot[0];
            $values = $cleanCount === 0 ? [] : explode("\0", FFI::string($outBuf, $written));
            if (count($values) !== $cleanCount || ($cleanCount === 0 && $written !== 0)) {
                return self::statusRuleValuesPhp($statusValues);
            }

            return $values;
        } catch (Throwable) {
            return self::statusRuleValuesPhp($statusValues);
        }
    }

    /**
     * @param  array<array-key, string>  $statusValues
     * @return list<string>
     */
    public static function statusRuleValuesPhp(array $statusValues): array
    {
        $seen = [];
        $result = [];

        foreach ($statusValues as $value) {
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
