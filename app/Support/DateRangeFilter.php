<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DateRangeFilter
{
    /**
     * Rust candidates for window resolution and strict date parsing. They stay opt-in:
     * neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchApiThrottleNoCacheFiltersParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_date_range_plan(int32_t has_start, int32_t has_end, int32_t has_period, const char *period, size_t period_len, int32_t period_is_string, int64_t default_days, int64_t *out, size_t out_cap);
        int32_t nntmux_date_range_parse_ymd(const char *value, size_t value_len, int64_t *out, size_t out_cap);
        C;

    /** Both explicit `start_date` and `end_date` bounds are parsed. */
    public const int MODE_EXPLICIT = 0;

    /** The window starts `days` days ago. */
    public const int MODE_DAYS = 1;

    /** The window starts one year ago. */
    public const int MODE_YEAR = 2;

    /** The window has no start bound. */
    public const int MODE_ALL = 3;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Parse a date range from request parameters.
     * Supports explicit start_date/end_date or a period preset.
     *
     * @return array{0: ?Carbon, 1: Carbon} [$startDate, $endDate]
     */
    public static function fromRequest(Request $request, int $defaultDays = 30): array
    {
        $plan = self::resolvePlan(
            $request->has('start_date'),
            $request->has('end_date'),
            $request->has('period'),
            $request->input('period'),
            $defaultDays,
        );

        if ($plan['mode'] === self::MODE_EXPLICIT) {
            return [self::parseDate($request->input('start_date')), self::parseDate($request->input('end_date'))];
        }

        $endDate = Carbon::now();
        $startDate = match ($plan['mode']) {
            self::MODE_YEAR => Carbon::now()->subYear(),
            self::MODE_ALL => null,
            default => Carbon::now()->subDays($plan['days']),
        };

        return [$startDate, $endDate];
    }

    /**
     * Resolve which window applies: explicit bounds win, then the `period` preset
     * (strictly matched), else the default window.
     *
     * @return array{mode: int, days: int}
     */
    public static function resolvePlan(bool $hasStart, bool $hasEnd, bool $hasPeriod, mixed $period, int $defaultDays): array
    {
        return self::$useRustFfi
            ? self::resolvePlanNative($hasStart, $hasEnd, $hasPeriod, $period, $defaultDays)
            : self::resolvePlanPhp($hasStart, $hasEnd, $hasPeriod, $period, $defaultDays);
    }

    /**
     * @return array{mode: int, days: int}
     */
    public static function resolvePlanPhp(bool $hasStart, bool $hasEnd, bool $hasPeriod, mixed $period, int $defaultDays): array
    {
        if ($hasStart && $hasEnd) {
            return ['mode' => self::MODE_EXPLICIT, 'days' => 0];
        }

        if (! $hasPeriod) {
            return ['mode' => self::MODE_DAYS, 'days' => $defaultDays];
        }

        return match ($period) {
            '7days' => ['mode' => self::MODE_DAYS, 'days' => 7],
            '30days' => ['mode' => self::MODE_DAYS, 'days' => 30],
            '90days' => ['mode' => self::MODE_DAYS, 'days' => 90],
            'year' => ['mode' => self::MODE_YEAR, 'days' => 0],
            'all' => ['mode' => self::MODE_ALL, 'days' => 0],
            default => ['mode' => self::MODE_DAYS, 'days' => $defaultDays],
        };
    }

    /**
     * @return array{mode: int, days: int}
     */
    public static function resolvePlanNative(bool $hasStart, bool $hasEnd, bool $hasPeriod, mixed $period, int $defaultDays): array
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::resolvePlanPhp($hasStart, $hasEnd, $hasPeriod, $period, $defaultDays);
        }

        $periodBytes = \is_string($period) ? $period : '';
        $out = $ffi->new('int64_t[2]');
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_date_range_plan(
            $hasStart ? 1 : 0,
            $hasEnd ? 1 : 0,
            $hasPeriod ? 1 : 0,
            $periodBytes,
            \strlen($periodBytes),
            \is_string($period) ? 1 : 0,
            $defaultDays,
            $out,
            2,
        );
        if ($status !== 0) {
            return self::resolvePlanPhp($hasStart, $hasEnd, $hasPeriod, $period, $defaultDays);
        }

        return ['mode' => (int) $out[0], 'days' => (int) $out[1]];
    }

    /**
     * `Carbon::parse()` for a request bound.
     */
    public static function parseDate(mixed $value): Carbon
    {
        return self::$useRustFfi ? self::parseDateNative($value) : self::parseDatePhp($value);
    }

    public static function parseDatePhp(mixed $value): Carbon
    {
        return Carbon::parse($value);
    }

    /**
     * Strict `Y-m-d` / `Y-m-d H:i:s` values are split in Rust and built with
     * `Carbon::create()`; any other value (relative formats, overflowing dates,
     * offsets) keeps `Carbon::parse()`.
     */
    public static function parseDateNative(mixed $value): Carbon
    {
        $ffi = self::searchFfi();
        if ($ffi === null || ! \is_string($value)) {
            return self::parseDatePhp($value);
        }

        $parts = $ffi->new('int64_t[6]');
        // @phpstan-ignore method.notFound
        $status = $ffi->nntmux_date_range_parse_ymd($value, \strlen($value), $parts, 6);
        if ($status !== 1) {
            return self::parseDatePhp($value);
        }

        $date = Carbon::create((int) $parts[0], (int) $parts[1], (int) $parts[2], (int) $parts[3], (int) $parts[4], (int) $parts[5]);

        return $date ?? self::parseDatePhp($value);
    }
}
