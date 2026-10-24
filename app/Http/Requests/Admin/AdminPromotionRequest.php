<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class AdminPromotionRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_promotion_rules(
        size_t *out_pair_count,
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::promotionRules();
    }

    /**
     * @return array<string, string>
     */
    public static function promotionRules(): array
    {
        return self::$useRustFfi ? self::promotionRulesNative() : self::promotionRulesPhp();
    }

    /**
     * @return array<string, string>
     */
    public static function promotionRulesNative(): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::promotionRulesPhp();
        }

        try {
            $pairCountSlot = $ffi->new('size_t[1]');
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 512, $capacity);
            if ($outBuf === null) {
                return self::promotionRulesPhp();
            }

            $written = self::callFfi($ffi, 'nntmux_admin_promotion_rules', $pairCountSlot, $outBuf, $capacity);
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return self::promotionRulesPhp();
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            $pairCount = (int) $pairCountSlot[0];
            if ($pairCount <= 0 || count($parts) !== $pairCount * 2) {
                return self::promotionRulesPhp();
            }

            $rules = [];
            for ($i = 0; $i < $pairCount; $i++) {
                $rules[$parts[$i * 2]] = $parts[$i * 2 + 1];
            }

            return count($rules) === $pairCount ? $rules : self::promotionRulesPhp();
        } catch (Throwable) {
            return self::promotionRulesPhp();
        }
    }

    /**
     * @return array<string, string>
     */
    public static function promotionRulesPhp(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'applicable_roles' => 'nullable|array',
            'applicable_roles.*' => 'exists:roles,id',
            'additional_days' => 'required|integer|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'boolean',
        ];
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
