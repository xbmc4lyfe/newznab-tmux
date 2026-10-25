<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    public const string W49_FFI_CDEF = 'int64_t nntmux_w49_bounded_metric_query_rewrite_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Rust: ~2.4-4.3x mean, >=1.66x p99 in every strict run; see tests/Unit/RustMonitoringBoundedMetricFamilyExporterSnapshotJwtW49ParityTest.php. */
    private const bool W49_RUST_FFI_DEFAULT = true;

    private static bool $useRustFfi = false;

    private static ?bool $w49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

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

    public static function usesW49RustFfi(): bool
    {
        return self::$w49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 49.5 batch helper routed to Rust or PHP, then restores the routing. */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w49RustFfi;
        self::$w49RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w49RustFfi = $previous;
        }
    }

    /**
     * Plans {@see rewriteSqlPhp()} for many metric queries. Per SQL: `[rewritten, rewritten_len,
     * rewritten_xxh64]`, where `rewritten` is 1 when a statement time limit was added.
     *
     * @param  list<string>  $sqls
     * @return list<list<int>>
     */
    public static function rewritePlanBatch(array $sqls, bool $isMaria): array
    {
        return self::usesW49RustFfi() ? self::rewritePlanBatchNative($sqls, $isMaria) : self::rewritePlanBatchPhp($sqls, $isMaria);
    }

    /**
     * @param  list<string>  $sqls
     * @return list<list<int>>
     */
    public static function rewritePlanBatchPhp(array $sqls, bool $isMaria): array
    {
        $rows = [];

        foreach ($sqls as $sql) {
            $rewritten = self::rewriteSqlPhp($sql, $isMaria);
            /** @var array{1: int} $hash */
            $hash = unpack('J', hash('xxh64', $rewritten, true));
            $rows[] = [$rewritten === $sql ? 0 : 1, \strlen($rewritten), $hash[1]];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $sqls
     * @return list<list<int>>
     */
    public static function rewritePlanBatchNative(array $sqls, bool $isMaria): array
    {
        $count = \count($sqls);
        if ($count === 0) {
            return [];
        }

        // NUL-delimited fields; Rust declines a value holding NUL (the field count no longer matches).
        $data = implode("\0", $sqls);
        $words = self::w49Words($count, $isMaria ? 1 : 0, $data, '', 3 * $count);

        return $words === null ? self::rewritePlanBatchPhp($sqls, $isMaria) : array_chunk($words, 3);
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

    /**
     * Exactly $words output words from the Wave 49.5 Rust helper, or null to fall back to PHP.
     *
     * @return array<int, int>|null
     */
    private static function w49Words(int $count, int $flags, string $data, string $ints, int $words): ?array
    {
        if (self::$w49Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w49Ffi = RustPipelineLibrary::resolveDomain('search_w49', self::W49_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w49Out = null;
            self::$w49OutCap = 0;
        }
        $ffi = self::$w49Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w49Out === null || $words > self::$w49OutCap) {
                $cap = max(1024, $words);
                self::$w49Out = $ffi->new('int64_t['.$cap.']');
                self::$w49OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_bounded_metric_query_rewrite_plan($count, $flags, $data, \strlen($data), $ints, \strlen($ints), self::$w49Out, self::$w49OutCap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w49Out, $words * 8));

            return \is_array($values) ? $values : null;
        } catch (Throwable) {
            return null;
        }
    }
}
