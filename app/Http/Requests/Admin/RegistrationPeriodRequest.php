<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\RegistrationPeriod;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

class RegistrationPeriodRequest extends FormRequest
{
    private const FFI_CDEF = <<<'CDEF'
    int32_t nntmux_registration_period_resolve_route_id(
        int32_t kind,
        int64_t int_val,
        const char *str_ptr,
        size_t str_len,
        int32_t *out_has_id,
        int64_t *out_id
    );
    int32_t nntmux_registration_period_intervals_overlap(
        int64_t start_a,
        int64_t end_a,
        int64_t start_b,
        int64_t end_b
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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_enabled' => $this->boolean('is_enabled'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_enabled' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->boolean('is_enabled')) {
                    return;
                }

                $startsAt = $this->date('starts_at');
                $endsAt = $this->date('ends_at');

                if ($startsAt === null || $endsAt === null) {
                    return;
                }

                $periodId = self::resolveRoutePeriodId($this->route('period'));

                $overlaps = RegistrationPeriod::query()
                    ->where('is_enabled', true)
                    ->when($periodId !== null, fn ($query) => $query->where('id', '!=', $periodId))
                    ->where(function ($query) use ($startsAt, $endsAt) {
                        $query->whereBetween('starts_at', [$startsAt, $endsAt])
                            ->orWhereBetween('ends_at', [$startsAt, $endsAt])
                            ->orWhere(function ($query) use ($startsAt, $endsAt) {
                                $query->where('starts_at', '<=', $startsAt)
                                    ->where('ends_at', '>=', $endsAt);
                            });
                    })
                    ->exists();

                if ($overlaps) {
                    $validator->errors()->add('starts_at', 'Enabled registration periods cannot overlap.');
                }
            },
        ];
    }

    public static function resolveRoutePeriodId(mixed $period): ?int
    {
        return self::$useRustFfi
            ? self::resolveRoutePeriodIdNative($period)
            : self::resolveRoutePeriodIdPhp($period);
    }

    public static function resolveRoutePeriodIdNative(mixed $period): ?int
    {
        if ($period instanceof RegistrationPeriod) {
            $id = $period->id;
            if ($id === null) {
                return null;
            }
            if (is_int($id)) {
                return self::resolveRoutePeriodIdNative($id);
            }

            return (int) $id;
        }

        if (is_float($period)) {
            return self::resolveRoutePeriodIdPhp($period);
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return self::resolveRoutePeriodIdPhp($period);
        }

        $kind = 0;
        $intVal = 0;
        $strVal = '';

        if (is_int($period)) {
            $kind = 1;
            $intVal = $period;
        } elseif (is_string($period)) {
            $kind = 2;
            $strVal = $period;
        }

        try {
            $outHasId = $ffi->new('int32_t[1]');
            $outId = $ffi->new('int64_t[1]');
            $rc = self::callFfi(
                $ffi,
                'nntmux_registration_period_resolve_route_id',
                $kind,
                $intVal,
                $strVal,
                strlen($strVal),
                $outHasId,
                $outId
            );

            if ($rc === 1) {
                return ((int) $outHasId[0]) === 1 ? (int) $outId[0] : null;
            }
        } catch (Throwable) {
        }

        return self::resolveRoutePeriodIdPhp($period);
    }

    public static function resolveRoutePeriodIdPhp(mixed $period): ?int
    {
        return $period instanceof RegistrationPeriod
            ? ($period->id !== null ? (int) $period->id : null)
            : (is_numeric($period) ? (int) $period : null);
    }

    public static function intervalsOverlap(int $startA, int $endA, int $startB, int $endB): bool
    {
        return self::$useRustFfi
            ? self::intervalsOverlapNative($startA, $endA, $startB, $endB)
            : self::intervalsOverlapPhp($startA, $endA, $startB, $endB);
    }

    public static function intervalsOverlapNative(int $startA, int $endA, int $startB, int $endB): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::intervalsOverlapPhp($startA, $endA, $startB, $endB);
        }

        try {
            $rc = self::callFfi($ffi, 'nntmux_registration_period_intervals_overlap', $startA, $endA, $startB, $endB);
            if ($rc === 1) {
                return true;
            }
            if ($rc === 0) {
                return false;
            }
        } catch (Throwable) {
        }

        return self::intervalsOverlapPhp($startA, $endA, $startB, $endB);
    }

    public static function intervalsOverlapPhp(int $startA, int $endA, int $startB, int $endB): bool
    {
        return ($startA >= $startB && $startA <= $endB)
            || ($endA >= $startB && $endA <= $endB)
            || ($startA <= $startB && $endA >= $endB);
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
