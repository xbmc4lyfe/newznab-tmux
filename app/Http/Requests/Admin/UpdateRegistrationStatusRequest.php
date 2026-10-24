<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RegistrationStatus;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class UpdateRegistrationStatusRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_update_registration_status_in_rule(
        const int64_t *values_ptr,
        size_t values_len,
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
        return [
            'registerstatus' => [
                'required',
                'integer',
                self::buildAllowedStatusInRule([
                    RegistrationStatus::Open->value,
                    RegistrationStatus::Invite->value,
                    RegistrationStatus::Closed->value,
                ]),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<array-key, int>  $statusValues
     */
    public static function buildAllowedStatusInRule(array $statusValues): string
    {
        return self::$useRustFfi
            ? self::buildAllowedStatusInRuleNative($statusValues)
            : self::buildAllowedStatusInRulePhp($statusValues);
    }

    /**
     * @param  array<array-key, int>  $statusValues
     */
    public static function buildAllowedStatusInRuleNative(array $statusValues): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildAllowedStatusInRulePhp($statusValues);
        }

        $count = count($statusValues);

        try {
            $slotCount = max(1, $count);
            $valuesSlot = $ffi->new("int64_t[{$slotCount}]");
            $index = 0;
            foreach ($statusValues as $val) {
                $valuesSlot[$index] = (int) $val;
                $index++;
            }

            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, max(64, 3 + $count * 24), $capacity);
            if ($outBuf === null) {
                return self::buildAllowedStatusInRulePhp($statusValues);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_update_registration_status_in_rule',
                $valuesSlot,
                $count,
                $outBuf,
                $capacity
            );

            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                return $written === 0 ? '' : FFI::string($outBuf, $written);
            }
        } catch (Throwable) {
        }

        return self::buildAllowedStatusInRulePhp($statusValues);
    }

    /**
     * @param  array<array-key, int>  $statusValues
     */
    public static function buildAllowedStatusInRulePhp(array $statusValues): string
    {
        return 'in:'.implode(',', array_map(static fn (int $val): string => (string) $val, $statusValues));
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
