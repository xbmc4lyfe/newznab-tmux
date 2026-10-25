<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Facades\Search;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchFacadeControllerGuestLayoutParityTest.php. Names carry a `Controller`
     * prefix so they never collide with the Rust toggles declared by subclasses.
     */
    public const bool CONTROLLER_RUST_FFI_DEFAULT = false;

    private const int CONTROLLER_PAGE_MAX_DIGITS = 18;

    private static ?bool $useControllerRustFfi = null;

    public static function useControllerRustFfi(?bool $use = true): void
    {
        self::$useControllerRustFfi = $use;
    }

    public static function usesControllerRustFfi(): bool
    {
        return self::$useControllerRustFfi ?? self::CONTROLLER_RUST_FFI_DEFAULT;
    }

    public static function resetControllerRustFfi(): void
    {
        self::$useControllerRustFfi = null;
    }

    /**
     * Plans a paginated listing from raw request input. Page and per-page values must be 1-18 ASCII
     * digits after trimming; anything else (or zero) uses page 1 / the default size. Per-page is
     * capped at the maximum (at least 1), the default is clamped into 1..maximum, a negative total
     * counts as zero and a page past the last one is clamped (`clamped` also reports a capped size).
     *
     * @return array{page: int, per_page: int, offset: int, last_page: int, clamped: bool}
     */
    public static function planControllerPagination(string $page, string $perPage, int $total, int $defaultPerPage = 50, int $maxPerPage = 100): array
    {
        return self::usesControllerRustFfi()
            ? self::planControllerPaginationNative($page, $perPage, $total, $defaultPerPage, $maxPerPage)
            : self::planControllerPaginationPhp($page, $perPage, $total, $defaultPerPage, $maxPerPage);
    }

    /**
     * @return array{page: int, per_page: int, offset: int, last_page: int, clamped: bool}
     */
    private static function planControllerPaginationNative(string $page, string $perPage, int $total, int $defaultPerPage, int $maxPerPage): array
    {
        $fields = Search::evaluateNative(2, [$page, $perPage, (string) $total, (string) $defaultPerPage, (string) $maxPerPage]);
        if ($fields === null || \count($fields) !== 5) {
            return self::planControllerPaginationPhp($page, $perPage, $total, $defaultPerPage, $maxPerPage);
        }

        return [
            'page' => (int) $fields[0],
            'per_page' => (int) $fields[1],
            'offset' => (int) $fields[2],
            'last_page' => (int) $fields[3],
            'clamped' => $fields[4] === '1',
        ];
    }

    /**
     * @return array{page: int, per_page: int, offset: int, last_page: int, clamped: bool}
     */
    private static function planControllerPaginationPhp(string $page, string $perPage, int $total, int $defaultPerPage, int $maxPerPage): array
    {
        $max = max(1, $maxPerPage);
        $default = min(max(1, $defaultPerPage), $max);
        $requested = self::controllerDigits($perPage);
        $per = $requested === null || $requested === 0 ? $default : min($requested, $max);
        $clamped = $requested !== null && $requested > $max;
        $total = max(0, $total);
        $last = max(1, intdiv($total, $per) + ($total % $per > 0 ? 1 : 0));
        $current = self::controllerDigits($page);
        if ($current === null || $current === 0) {
            $current = 1;
        } elseif ($current > $last) {
            $current = $last;
            $clamped = true;
        }

        return ['page' => $current, 'per_page' => $per, 'offset' => ($current - 1) * $per, 'last_page' => $last, 'clamped' => $clamped];
    }

    private static function controllerDigits(string $value): ?int
    {
        $value = trim($value);
        $length = \strlen($value);

        return $length > 0 && $length <= self::CONTROLLER_PAGE_MAX_DIGITS && strspn($value, '0123456789') === $length ? (int) $value : null;
    }
}
