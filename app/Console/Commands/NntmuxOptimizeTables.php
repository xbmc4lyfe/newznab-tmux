<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class NntmuxOptimizeTables extends Command
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_w32_table_account_gdpr_cleanup_commands(
    const char *input,
    size_t len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /** Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nntmux:optimize-tables
    {--t|table=* : Table to optimize or all tables.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks and optimizes tables.';

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Checking and optimizing tables.');
        $table = $this->option('table');
        $request = self::normalizeOptimizeRequest(false, is_array($table) ? $table : (empty($table) ? [] : [$table]));
        if ($request['optimize_all']) {
            $this->optimizeAllTables();
        } else {
            $this->optimizeTable($request['tables']);
        }
    }

    /**
     * Resolves the `--table` arguments: no tables, or `all` first, optimizes every table.
     *
     * @param  array<array-key, mixed>  $tableArguments
     * @return array{optimize_all: bool, tables: list<mixed>, force: bool, free_threshold_percent: int}
     */
    public static function normalizeOptimizeRequest(bool $force, array $tableArguments, int $freeThresholdPercent = 5): array
    {
        return self::usesRustFfi()
            ? self::normalizeOptimizeRequestNative($force, $tableArguments, $freeThresholdPercent)
            : self::normalizeOptimizeRequestPhp($force, $tableArguments, $freeThresholdPercent);
    }

    /**
     * @param  array<array-key, mixed>  $tableArguments
     * @return array{optimize_all: bool, tables: list<mixed>, force: bool, free_threshold_percent: int}
     */
    public static function normalizeOptimizeRequestPhp(bool $force, array $tableArguments, int $freeThresholdPercent = 5): array
    {
        $optimizeAll = empty($tableArguments) || ($tableArguments[0] ?? null) === 'all';

        return [
            'optimize_all' => $optimizeAll,
            'tables' => $optimizeAll ? [] : array_values($tableArguments),
            'force' => $force,
            'free_threshold_percent' => max(0, min(100, $freeThresholdPercent)),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $tableArguments
     * @return array{optimize_all: bool, tables: list<mixed>, force: bool, free_threshold_percent: int}
     */
    public static function normalizeOptimizeRequestNative(bool $force, array $tableArguments, int $freeThresholdPercent = 5): array
    {
        if (array_is_list($tableArguments)
            && self::callNative(['optimize_tables.normalize_request', $force, $tableArguments, $freeThresholdPercent], $result)
            && is_array($result)
            && array_keys($result) === ['optimize_all', 'tables', 'force', 'free_threshold_percent']
            && is_bool($result['optimize_all'])
            && is_array($result['tables'])
            && array_is_list($result['tables'])
            && is_bool($result['force'])
            && is_int($result['free_threshold_percent'])) {
            /** @var array{optimize_all: bool, tables: list<mixed>, force: bool, free_threshold_percent: int} $result */
            return $result;
        }

        return self::normalizeOptimizeRequestPhp($force, $tableArguments, $freeThresholdPercent);
    }

    public static function buildOptimizeTableSql(string $escapedTableName): string
    {
        return self::usesRustFfi() ? self::buildOptimizeTableSqlNative($escapedTableName) : self::buildOptimizeTableSqlPhp($escapedTableName);
    }

    public static function buildOptimizeTableSqlPhp(string $escapedTableName): string
    {
        return 'OPTIMIZE TABLE '.$escapedTableName;
    }

    public static function buildOptimizeTableSqlNative(string $escapedTableName): string
    {
        if (self::callNative(['optimize_tables.build_optimize_sql', $escapedTableName], $result) && is_string($result)) {
            return $result;
        }

        return self::buildOptimizeTableSqlPhp($escapedTableName);
    }

    /**
     * Optimize all tables.
     */
    private function optimizeAllTables(): void
    {
        $tables = DB::select('SHOW TABLES');
        foreach ($tables as $table) {
            $this->optimizeTable($table->Tables_in_nntmux);
        }
    }

    /**
     * Optimize a single table.
     *
     * @param  array<array-key, mixed>  $tables
     */
    private function optimizeTable(array|string $tables): void
    {
        if (is_array($tables)) {
            foreach ($tables as $table) {
                $this->tableCheck($table);
            }
        } else {
            $this->tableCheck($tables);
        }
    }

    private function tableCheck(mixed $table): void
    {
        $this->info('Checking table: '.$table);
        $tableCheck = DB::select('CHECK TABLE '.$table);
        if ($tableCheck[0]->Msg_text !== 'OK') {
            $this->error('Table '.$table.' is corrupted. Please repair it.');
            $this->info('Optimizing table: '.$table);
            DB::select(self::buildOptimizeTableSql((string) $table));
        } else {
            $this->info('Table '.$table.' is ok. Optimization is not needed.');
        }
    }

    /**
     * Evaluates `[operation, ...arguments]` in Rust; false means the caller keeps its PHP result.
     *
     * @param  list<mixed>  $request
     *
     * @param-out mixed $result
     */
    private static function callNative(array $request, mixed &$result = null): bool
    {
        $ffi = self::nativeFfi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $capacity = max(256, strlen($json) * 2 + 256);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $buffer = $ffi->new("uint8_t[{$capacity}]");
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w32_table_account_gdpr_cleanup_commands($json, strlen($json), $buffer, $capacity);
                if (! is_int($written) || $written === -1 || $written === 0) {
                    return false;
                }
                if ($written < 0) {
                    $capacity = -$written;

                    continue;
                }
                $decoded = json_decode(FFI::string($buffer, $written), true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || count($decoded) !== 1 || ! array_key_exists(0, $decoded)) {
                    return false;
                }
                $result = $decoded[0];

                return true;
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    private static function nativeFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }
        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
