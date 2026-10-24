<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Returns approximate row counts for large tables using
 * information_schema.TABLES.TABLE_ROWS so we avoid COUNT(*) full scans
 * on the hottest dashboard tiles.
 *
 * Falls back to an exact COUNT(*) when:
 *  - the connection isn't MySQL/MariaDB, or
 *  - the approximation is unavailable / reports 0 (common for tiny tables).
 */
final class ApproximateRowCount
{
    /**
     * Rust candidates for the pure helpers below. They stay opt-in: none beat PHP by
     * 1.5x in the paired benchmark (tests/Unit/RustSearchMediaApiCapabilitiesParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_db_supports_information_schema(const char *driver, size_t len);
        int32_t nntmux_db_normalize_approx_rows(int32_t tag, int64_t ival, const char *input, size_t len, int64_t *out);
        C;

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
     * Approximate count for a single table.
     */
    public static function for(string $table, ?string $connection = null): int
    {
        $conn = DB::connection($connection);

        if (! self::supportsInformationSchema($conn)) {
            return (int) $conn->table($table)->count();
        }

        try {
            $row = $conn->selectOne(
                'SELECT TABLE_ROWS AS approx FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                [$table]
            );
        } catch (\Throwable) {
            $row = null;
        }

        // Fall back to exact count on tables InnoDB hasn't analyzed yet
        // or that are small enough that the approximation is meaningless.
        return self::normalizeApproximateRows($row?->approx ?? null) ?? (int) $conn->table($table)->count();
    }

    private static function supportsInformationSchema(ConnectionInterface $conn): bool
    {
        $driver = method_exists($conn, 'getDriverName') ? $conn->getDriverName() : null;

        return self::driverSupportsInformationSchema($driver);
    }

    public static function driverSupportsInformationSchema(mixed $driver): bool
    {
        return self::$useRustFfi
            ? self::driverSupportsInformationSchemaNative($driver)
            : self::driverSupportsInformationSchemaPhp($driver);
    }

    public static function driverSupportsInformationSchemaPhp(mixed $driver): bool
    {
        return in_array($driver, ['mysql', 'mariadb'], true);
    }

    public static function driverSupportsInformationSchemaNative(mixed $driver): bool
    {
        $ffi = \is_string($driver) ? self::searchFfi() : null;
        if ($ffi === null || ! \is_string($driver)) {
            return self::driverSupportsInformationSchemaPhp($driver);
        }

        // @phpstan-ignore method.notFound
        $supported = $ffi->nntmux_db_supports_information_schema($driver, \strlen($driver));

        return \is_int($supported) && $supported >= 0
            ? $supported === 1
            : self::driverSupportsInformationSchemaPhp($driver);
    }

    /**
     * The approximate row count, or null when the caller must count exactly
     * (no approximation, or one that is not positive).
     */
    public static function normalizeApproximateRows(mixed $approx): ?int
    {
        return self::$useRustFfi
            ? self::normalizeApproximateRowsNative($approx)
            : self::normalizeApproximateRowsPhp($approx);
    }

    public static function normalizeApproximateRowsPhp(mixed $approx): ?int
    {
        if ($approx === null || (int) $approx <= 0) {
            return null;
        }

        return (int) $approx;
    }

    public static function normalizeApproximateRowsNative(mixed $approx): ?int
    {
        [$tag, $int, $bytes] = match (true) {
            $approx === null => [0, 0, ''],
            \is_bool($approx) => [1, (int) $approx, ''],
            \is_int($approx) => [2, $approx, ''],
            \is_string($approx) => [3, 0, $approx],
            default => [null, 0, ''],
        };
        $ffi = $tag === null ? null : self::searchFfi();
        if ($ffi === null || $tag === null) {
            return self::normalizeApproximateRowsPhp($approx);
        }

        $out = $ffi->new('int64_t[1]');
        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_db_normalize_approx_rows($tag, $int, $bytes, \strlen($bytes), $out);

        return match ($rc) {
            1 => (int) $out[0],
            0 => null,
            default => self::normalizeApproximateRowsPhp($approx),
        };
    }
}
