<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use LogicException;
use Throwable;

/** Metrics must never leave an unbounded database query running after the exporter exits. */
class BoundedMetricQuery
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_bounded_metric_rewrite_sql(uint32_t is_maria, const char *sql_ptr, size_t sql_len, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use ?? false;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        if ($reset) {
            self::$ffi = $override;
            if ($override !== null) {
                return self::$ffi === false ? null : self::$ffi;
            }
        }
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /** @return list<object> */
    public function get(Builder $query): array
    {
        $connection = $query->getConnection();
        if (! $connection instanceof MySqlConnection) {
            return $query->get()->all();
        }

        // Statement-local limits also cancel server work; a PHP process alarm alone does not.
        $sql = self::rewriteSql($query->toSql(), $connection->isMaria());

        return $connection->select($sql, $query->getBindings());
    }

    public static function rewriteSql(string $sql, bool $isMaria): string
    {
        return self::$useRustFfi
            ? self::rewriteSqlNative($sql, $isMaria)
            : self::rewriteSqlPhp($sql, $isMaria);
    }

    public static function rewriteSqlPhp(string $sql, bool $isMaria): string
    {
        return $isMaria
            ? 'SET STATEMENT max_statement_time=2 FOR '.$sql
            : preg_replace('/\Aselect\b/i', 'select /*+ MAX_EXECUTION_TIME(2000) */', $sql) ?? throw new LogicException('Invalid metrics query.');
    }

    public static function rewriteSqlNative(string $sql, bool $isMaria): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::rewriteSqlPhp($sql, $isMaria);
        }

        $len = \strlen($sql);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 64), $cap);
        if ($scratch === null) {
            return self::rewriteSqlPhp($sql, $isMaria);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_bounded_metric_rewrite_sql($isMaria ? 1 : 0, $sql, $len, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::rewriteSqlPhp($sql, $isMaria);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_bounded_metric_rewrite_sql($isMaria ? 1 : 0, $sql, $len, $scratch, $cap);
            }
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return self::rewriteSqlPhp($sql, $isMaria);
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::rewriteSqlPhp($sql, $isMaria);
        }
    }
}
