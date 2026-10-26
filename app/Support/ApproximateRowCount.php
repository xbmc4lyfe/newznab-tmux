<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use FFI\CData;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

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
        } catch (Throwable) {
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

    // Rust is the default only while the paired W59.6 benchmark clears 1.5x on all four metrics.
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_FFI_CDEF = <<<'C'
        int32_t nntmux_w596_row_count_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, size_t count, int64_t min_approx, int64_t max_exact, int64_t *out, size_t out_cap);
        C;

    private const int W59_MAX_ITEMS = 1_048_576;

    public const int W59_ROWS_HEADER = 16;

    private const int W59_ROWS_CAP = (1 << 40) - 1;

    private const int W59_ROWS_SUM_CAP = 1 << 62;

    /** Driver classes; any other driver is 5. */
    private const array W59_DRIVERS = ['mysql' => 0, 'mariadb' => 1, 'sqlite' => 2, 'pgsql' => 3, 'sqlsrv' => 4];

    /**
     * Row-count plans for raw `information_schema.TABLES.TABLE_ROWS` or `EXPLAIN` row estimates and their
     * drivers. Estimate class after `trim()`: 0 missing (empty or `NULL`), 1 positive, 2 zero, 3 negative,
     * 4 not an optionally signed digit string, 5 more than 18 significant digits. Decision 0 approximate,
     * 1 exact (no usable estimate), 2 exact (driver without `information_schema`), 3 exact (estimate below
     * `$minApprox`); expensive marks an exact count of a positive estimate above `$maxExact` rows.
     *
     * Layout: `[0]` count, `[1..4]` per decision, `[5..10]` per estimate class, `[11]` approximate row sum
     * (capped at 2^62), `[12]` largest positive estimate, `[13]` first exact index (-1), `[14]` expensive,
     * `[15]` FNV-1a digest; then per table `decision | driver << 2 | class << 5 | min(estimate, 2^40-1) << 8
     * | expensive << 48 | min(bytes, 255) << 49`.
     *
     * @param  array<array-key, string>  $estimates
     * @param  array<array-key, string>  $drivers  Parallel to $estimates.
     * @return list<int>
     */
    public static function planRowCountBatch(array $estimates, array $drivers, int $minApprox = 1, int $maxExact = 1_000_000): array
    {
        return (self::usesW59RustFfi() ? self::planRowCountBatchNative($estimates, $drivers, $minApprox, $maxExact) : null)
            ?? self::planRowCountBatchPhp($estimates, $drivers, $minApprox, $maxExact);
    }

    /**
     * @param  array<array-key, string>  $estimates
     * @param  array<array-key, string>  $drivers
     * @return list<int>
     */
    public static function planRowCountBatchPhp(array $estimates, array $drivers, int $minApprox = 1, int $maxExact = 1_000_000): array
    {
        $estimates = array_values($estimates);
        $drivers = array_values($drivers);
        $out = array_fill(0, self::W59_ROWS_HEADER, 0);
        $out[0] = \count($estimates);
        $out[13] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($estimates as $at => $raw) {
            $raw = (string) $raw;
            $value = trim($raw);
            $class = 1;
            $rowsEstimate = 0;
            if ($value === '' || strtolower($value) === 'null') {
                $class = 0;
            } else {
                $negative = $value[0] === '-';
                $digits = $negative || $value[0] === '+' ? substr($value, 1) : $value;
                if ($digits === '' || strspn($digits, '0123456789') !== \strlen($digits)) {
                    $class = 4;
                } else {
                    $significant = ltrim($digits, '0');
                    if ($negative && $significant !== '') {
                        $class = 3;
                    } elseif ($significant === '') {
                        $class = 2;
                    } elseif (\strlen($significant) > 18) {
                        $class = 5;
                    } else {
                        $rowsEstimate = (int) $significant;
                    }
                }
            }
            $driver = self::W59_DRIVERS[(string) ($drivers[$at] ?? '')] ?? 5;
            $decision = match (true) {
                $driver > 1 => 2,
                $class !== 1 => 1,
                $rowsEstimate < $minApprox => 3,
                default => 0,
            };
            $expensive = $decision !== 0 && $class === 1 && $rowsEstimate > $maxExact;
            $out[1 + $decision]++;
            $out[5 + $class]++;
            if ($decision === 0) {
                $out[11] = min($out[11] + $rowsEstimate, self::W59_ROWS_SUM_CAP);
            } elseif ($out[13] < 0) {
                $out[13] = $at;
            }
            $out[12] = max($out[12], $rowsEstimate);
            $out[14] += (int) $expensive;
            $row = $decision | $driver << 2 | $class << 5 | min($rowsEstimate, self::W59_ROWS_CAP) << 8 | (int) $expensive << 48
                | min(\strlen($raw), 255) << 49;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[15] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust plans, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $estimates
     * @param  array<array-key, string>  $drivers
     * @return list<int>|null
     */
    public static function planRowCountBatchNative(array $estimates, array $drivers, int $minApprox = 1, int $maxExact = 1_000_000): ?array
    {
        $count = \count($estimates);
        if ($count > self::W59_MAX_ITEMS || \count($drivers) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w59Strings(array_values($estimates), array_values($drivers));
        $fields = self::W59_ROWS_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w59Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w596_row_count_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $minApprox, $maxExact, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 59.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    public static function usesW59RustFfi(): bool
    {
        $forced = self::w59RustFfiState();

        return \is_bool($forced) ? $forced : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 59.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w59` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w59', self::W59_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 59.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w59Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w59RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w59Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
