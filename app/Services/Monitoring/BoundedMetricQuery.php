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

    /**
     * Enabled: the paired benchmark in the W60 parity test measures 4.059x common mean, 4.517x
     * common p99, 3.044x uncommon mean and 2.848x uncommon p99 (per-metric minimum of four runs),
     * above the 1.5x rule.
     */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_BOUNDED_METRIC_QUERY_FFI_CDEF = 'ssize_t nntmux_w601_bounded_metric_query_plan_batch(const char *rows, size_t rows_len, size_t count, int64_t *out, size_t out_cap);';

    public const int W60_PLAN_WORDS = 7;

    public const int W60_SQL_READ_ONLY = 16;

    public const int W60_SQL_MULTI = 32;

    public const int W60_SQL_HAS_LIMIT = 64;

    public const int W60_SQL_HINTED = 128;

    public const int W60_WINDOW_CLAMPED = 256;

    public const int W60_TIMEOUT_DEFAULTED = 512;

    public const int W60_TIMEOUT_CLAMPED = 1024;

    public const int W60_LIMIT_DEFAULTED = 2048;

    public const int W60_LIMIT_CLAMPED = 4096;

    public const int W60_CACHE_DISABLED = 8192;

    public const int W60_STALE = 16384;

    public const int W60_FUTURE_AGE = 32768;

    public const int W60_MALFORMED = 65536;

    public const int W60_RUNNABLE = 131072;

    private const array W60_SQL_KINDS = ['select' => 1, 'with' => 2, 'show' => 3, 'explain' => 4];

    private const array W60_SQL_WRITES = ['insert' => 0, 'update' => 0, 'delete' => 0, 'drop' => 0, 'alter' => 0, 'truncate' => 0, 'replace' => 0, 'create' => 0, 'grant' => 0];

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 60 batch routed to Rust or PHP, then restores the routing. */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function boundedQueryPlanBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::boundedQueryPlanBatchNative($rows) : self::boundedQueryPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or Rust declines the batch (a
     * row holding a NUL byte).
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function boundedQueryPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count * self::W60_PLAN_WORDS;
        /** @var array{0: FFI, 1: CData, 2: int}|null $buffer */
        $buffer = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($buffer === null) {
            return self::boundedQueryPlanBatchPhp($rows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $rows);
        try {
            // @phpstan-ignore method.notFound (Declared by W60_BOUNDED_METRIC_QUERY_FFI_CDEF.)
            $written = $ffi->nntmux_w601_bounded_metric_query_plan_batch($joined, \strlen($joined), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        $result = $written === $count ? unpack('q*', FFI::string($out, $words * 8)) : false;
        if (! \is_array($result)) {
            return self::boundedQueryPlanBatchPhp($rows);
        }

        /** @var list<int> */
        return array_values($result);
    }

    /**
     * Seven words per `sql\twindow_seconds\ttimeout_ms\trow_limit\tage_seconds\tcache_ttl` row:
     * the statement kind (0 empty, 1 select, 2 with, 3 show, 4 explain, 5 other; the leading
     * letters after ltrim()) with the read-only (no insert/update/delete/drop/alter/truncate/
     * replace/create/grant word), multi-statement (a `;` before the trailing `;`/whitespace),
     * LIMIT word, existing time-limit hint, window/timeout/limit defaulted or clamped, cache
     * disabled (ttl <= 0), stale (age < 0 or >= ttl), future age and runnable (read-only single
     * statement) flags; then the window (60-86400 s), step (window / 240, at least 15 s),
     * timeout (default 2000, at most 30000 ms), row limit (default 1000, at most 100000), fresh
     * seconds left and the `[A-Za-z0-9_]+` word count. A malformed row yields
     * `[W60_MALFORMED, 0, 0, 0, 0, 0, 0]`.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function boundedQueryPlanBatchPhp(array $rows): array
    {
        $words = [];
        foreach ($rows as $row) {
            $f = explode("\t", $row);
            $ints = [];
            if (\count($f) === 6) {
                for ($i = 1; $i <= 5 && preg_match('/\A-?[0-9]{1,18}\z/', $f[$i]) === 1; $i++) {
                    $ints[] = (int) $f[$i];
                }
            }
            if (\count($ints) !== 5) {
                array_push($words, self::W60_MALFORMED, 0, 0, 0, 0, 0, 0);

                continue;
            }
            [$window, $timeout, $limit, $age, $ttl] = $ints;
            $sql = $f[0];
            $lead = ltrim($sql);
            $kind = 0;
            if ($lead !== '') {
                preg_match('/\A[A-Za-z]*/', $lead, $m);
                $kind = self::W60_SQL_KINDS[strtolower($m[0] ?? '')] ?? 5;
            }
            $lower = strtolower($sql);
            $tokens = (int) preg_match_all('/[a-z0-9_]+/', $lower, $m);
            $seen = array_flip($m[0]);
            $readOnly = $kind >= 1 && $kind <= 4 && array_intersect_key(self::W60_SQL_WRITES, $seen) === [];
            $multi = str_contains(rtrim($sql, " \t\n\r\0\x0B;"), ';');
            $flags = $kind
                | ($readOnly ? self::W60_SQL_READ_ONLY : 0)
                | ($multi ? self::W60_SQL_MULTI : 0)
                | (isset($seen['limit']) ? self::W60_SQL_HAS_LIMIT : 0)
                | (str_contains($lower, 'max_execution_time') || str_contains($lower, 'max_statement_time') ? self::W60_SQL_HINTED : 0)
                | ($readOnly && ! $multi ? self::W60_RUNNABLE : 0);
            $clamped = max(60, min(86400, $window));
            $flags |= $clamped !== $window ? self::W60_WINDOW_CLAMPED : 0;
            if ($timeout <= 0) {
                $timeout = 2000;
                $flags |= self::W60_TIMEOUT_DEFAULTED;
            } elseif ($timeout > 30000) {
                $timeout = 30000;
                $flags |= self::W60_TIMEOUT_CLAMPED;
            }
            if ($limit < 1) {
                $limit = 1000;
                $flags |= self::W60_LIMIT_DEFAULTED;
            } elseif ($limit > 100000) {
                $limit = 100000;
                $flags |= self::W60_LIMIT_CLAMPED;
            }
            $flags |= $age < 0 ? self::W60_FUTURE_AGE : 0;
            $fresh = 0;
            if ($ttl <= 0) {
                $flags |= self::W60_CACHE_DISABLED;
            } elseif ($age < 0 || $age >= $ttl) {
                $flags |= self::W60_STALE;
            } else {
                $fresh = $ttl - $age;
            }
            array_push($words, $flags, $clamped, max(15, intdiv($clamped, 240)), $timeout, $limit, $fresh, $tokens);
        }

        return $words;
    }

    /**
     * Wave 60 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics so the
     * class gains no Wave 60 static properties.
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('bounded_metric_query_w60', self::W60_BOUNDED_METRIC_QUERY_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(1024, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
