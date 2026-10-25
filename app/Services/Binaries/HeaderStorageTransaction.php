<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\RustPipelineLibrary;
use App\Support\SqlError;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles header storage transactions and rollback cleanup.
 */
final class HeaderStorageTransaction
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_header_batch_noise_hex(const char *bytes, size_t len, char *out, size_t cap);
        int32_t nntmux_header_cleanup_strategy(size_t inserted, size_t all, size_t hashes);
        C;

    /** Rollback cleanup selectors, in priority order. */
    public const array CLEANUP_STRATEGIES = ['inserted', 'all', 'hashes', 'noise'];

    private const MAX_SQL_ROWS_PER_STATEMENT = 500;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private CollectionHandler $collectionHandler;

    private BinaryHandler $binaryHandler;

    private string $batchNoise;

    private bool $hadErrors = false;

    private ?\Throwable $lastException = null;

    public function __construct(
        CollectionHandler $collectionHandler,
        BinaryHandler $binaryHandler
    ) {
        $this->collectionHandler = $collectionHandler;
        $this->binaryHandler = $binaryHandler;
        $this->batchNoise = self::encodeBatchNoise(random_bytes(8));
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Hex marker stamped on every collection row this transaction writes.
     */
    public static function encodeBatchNoise(string $bytes): string
    {
        return (self::$useRustFfi ? self::encodeBatchNoiseNative($bytes) : null) ?? self::encodeBatchNoisePhp($bytes);
    }

    public static function encodeBatchNoisePhp(string $bytes): string
    {
        return bin2hex($bytes);
    }

    public static function encodeBatchNoiseNative(string $bytes): ?string
    {
        if ($bytes === '') {
            return '';
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, \strlen($bytes) * 2, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_header_batch_noise_hex($bytes, \strlen($bytes), $scratch, $cap);

            return $written > 0 ? FFI::string($scratch, $written) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Which rows a SQLite rollback cleanup deletes: inserted ids win, then every batch id,
     * then collection hashes, and finally the batch noise marker.
     *
     * @return 'inserted'|'all'|'hashes'|'noise'
     */
    public static function cleanupStrategy(int $insertedCount, int $allCount, int $hashCount): string
    {
        return (self::$useRustFfi ? self::cleanupStrategyNative($insertedCount, $allCount, $hashCount) : null)
            ?? self::cleanupStrategyPhp($insertedCount, $allCount, $hashCount);
    }

    /**
     * @return 'inserted'|'all'|'hashes'|'noise'
     */
    public static function cleanupStrategyPhp(int $insertedCount, int $allCount, int $hashCount): string
    {
        return match (true) {
            $insertedCount > 0 => 'inserted',
            $allCount > 0 => 'all',
            $hashCount > 0 => 'hashes',
            default => 'noise',
        };
    }

    /**
     * @return 'inserted'|'all'|'hashes'|'noise'|null
     */
    public static function cleanupStrategyNative(int $insertedCount, int $allCount, int $hashCount): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_header_cleanup_strategy(max(0, $insertedCount), max(0, $allCount), max(0, $hashCount));
        } catch (\Throwable) {
            return null;
        }

        return self::CLEANUP_STRATEGIES[$code] ?? null;
    }

    /**
     * Get the batch noise marker for this transaction.
     */
    public function getBatchNoise(): string
    {
        return $this->batchNoise;
    }

    /**
     * Start a new database transaction.
     */
    public function begin(): void
    {
        // The aggregate refreshes read parts and binaries with plain SELECTs so they take
        // no locks; under REPEATABLE READ those reads would come from a snapshot that
        // misses rows other header workers committed meanwhile, so use READ COMMITTED.
        if (DB::transactionLevel() === 0 && \in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        DB::beginTransaction();
        $this->hadErrors = false;
        $this->lastException = null;
    }

    /**
     * Mark that an error occurred.
     */
    public function markError(): void
    {
        $this->hadErrors = true;
    }

    /**
     * Check if errors occurred.
     */
    public function hasErrors(): bool
    {
        return $this->hadErrors;
    }

    /**
     * Commit the transaction if no errors, rollback otherwise.
     */
    public function finish(): bool
    {
        if ($this->hadErrors) {
            $this->rollbackAndCleanup();

            return false;
        }

        try {
            DB::commit();

            return true;
        } catch (\Throwable $e) {
            $this->lastException = $e;
            $this->rollbackAndCleanup();

            if (config('app.debug') === true) {
                SqlError::logFailure('HeaderStorageTransaction commit failed', $e);
            }

            return false;
        }
    }

    public function getLastException(): ?\Throwable
    {
        return $this->lastException;
    }

    /**
     * Perform rollback and cleanup any orphaned data.
     */
    private function rollbackAndCleanup(): void
    {
        try {
            DB::rollBack();
        } catch (\Throwable $e) {
            // Already rolled back
        }

        // InnoDB rollback already reverts all rows written by this transaction.
        // Manual cleanup is retained only for SQLite test/in-memory scenarios.
        if (DB::getDriverName() === 'sqlite') {
            $this->cleanup();
        }
    }

    /**
     * Cleanup rows that may have been inserted before rollback.
     */
    private function cleanup(): void
    {
        try {
            $this->cleanupParts();
            $this->cleanupBinaries();
            $this->cleanupCollections();
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::warning('Post-rollback cleanup failed: '.$e->getMessage());
            }
        }
    }

    private function cleanupParts(): void
    {
        $ids = $this->binaryHandler->getInsertedIds();
        foreach (array_chunk($ids, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));
            DB::statement("DELETE FROM parts WHERE binaries_id IN ({$placeholders})", $chunk);
        }
    }

    private function cleanupBinaries(): void
    {
        $ids = $this->binaryHandler->getInsertedIds();
        foreach (array_chunk($ids, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
            $placeholders = implode(',', array_fill(0, \count($chunk), '?'));
            DB::statement("DELETE FROM binaries WHERE id IN ({$placeholders})", $chunk);
        }
    }

    private function cleanupCollections(): void
    {
        $insertedIds = $this->collectionHandler->getInsertedIds();
        $allIds = $this->collectionHandler->getAllIds();
        $hashes = $this->collectionHandler->getBatchHashes();

        $strategy = self::cleanupStrategy(\count($insertedIds), \count($allIds), \count($hashes));
        $ids = $strategy === 'inserted' ? $insertedIds : $allIds;

        if ($strategy === 'inserted' || $strategy === 'all') {
            foreach (array_chunk($ids, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
                $placeholders = implode(',', array_fill(0, \count($chunk), '?'));

                DB::statement(
                    "DELETE FROM parts WHERE binaries_id IN (SELECT id FROM binaries WHERE collections_id IN ({$placeholders}))",
                    $chunk
                );
                DB::statement("DELETE FROM binaries WHERE collections_id IN ({$placeholders})", $chunk);
                DB::statement("DELETE FROM collections WHERE id IN ({$placeholders})", $chunk);
            }
        } elseif ($strategy === 'hashes') {
            foreach (array_chunk($hashes, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
                $placeholders = implode(',', array_fill(0, \count($chunk), '?'));

                DB::statement(
                    "DELETE FROM parts WHERE binaries_id IN (SELECT id FROM binaries WHERE collections_id IN (SELECT id FROM collections WHERE collectionhash IN ({$placeholders})))",
                    $chunk
                );
                DB::statement(
                    "DELETE FROM binaries WHERE collections_id IN (SELECT id FROM collections WHERE collectionhash IN ({$placeholders}))",
                    $chunk
                );
                DB::statement("DELETE FROM collections WHERE collectionhash IN ({$placeholders})", $chunk);
            }
        } else {
            // Fallback by noise marker
            DB::statement(
                'DELETE FROM parts WHERE binaries_id IN (SELECT b.id FROM binaries b WHERE b.collections_id IN (SELECT c.id FROM collections c WHERE c.noise = ?))',
                [$this->batchNoise]
            );
            DB::statement(
                'DELETE FROM binaries WHERE collections_id IN (SELECT id FROM collections WHERE noise = ?)',
                [$this->batchNoise]
            );
            DB::statement('DELETE FROM collections WHERE noise = ?', [$this->batchNoise]);
        }
    }

    public const string W421_FFI_CDEF = <<<'C'
        ssize_t nntmux_w421_binaries_transaction_missed_part_backfill(uint32_t operation, uint32_t flags, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * Rust is the default for summarizeCleanupPlanBatch(): the paired benchmark in
     * tests/Unit/RustBinariesTransactionMissedPartBackfillW42ParityTest.php clears 1.5x on every workload.
     */
    public const bool W421_RUST_FFI_DEFAULT = true;

    private const int W421_RUST_MAX_INPUT = 16_777_216;

    private const array W421_CLEANUP_KEYS = ['batches', 'invalid_rows', 'bad_counts', 'inserted', 'all', 'hashes', 'noise', 'statements'];

    private static ?bool $w421UseRustFfi = null;

    private static FFI|false|null $w421Ffi = null;

    /**
     * Summarizes rollback cleanup plans (`binaries`, `inserted`, `all`, `hashes` id counts): a row with a count that
     * is not a non-negative int (or 1-18 digit string) has bad counts; otherwise cleanupStrategyPhp() picks its
     * strategy and the plan issues two DELETEs per `max(1, $maxRows)` binary ids plus three per chunk of the chosen
     * collection ids (three for the noise marker), totalled with saturation at PHP_INT_MAX. Non-array rows are invalid.
     *
     * @param  array<array-key, mixed>  $batches
     * @return array<string, int>
     */
    public static function summarizeCleanupPlanBatch(array $batches, int $maxRows = self::MAX_SQL_ROWS_PER_STATEMENT): array
    {
        return (self::usesW421RustFfi() ? self::summarizeCleanupPlanBatchNative($batches, $maxRows) : null)
            ?? self::summarizeCleanupPlanBatchPhp($batches, $maxRows);
    }

    /**
     * @param  array<array-key, mixed>  $batches
     * @return array<string, int>
     */
    public static function summarizeCleanupPlanBatchPhp(array $batches, int $maxRows = self::MAX_SQL_ROWS_PER_STATEMENT): array
    {
        $summary = array_fill_keys(self::W421_CLEANUP_KEYS, 0);
        $summary['batches'] = \count($batches);
        $size = max(1, $maxRows);
        foreach ($batches as $batch) {
            if (! \is_array($batch)) {
                $summary['invalid_rows']++;

                continue;
            }
            $binaries = BinariesService::w411Int($batch['binaries'] ?? null);
            $inserted = BinariesService::w411Int($batch['inserted'] ?? null);
            $all = BinariesService::w411Int($batch['all'] ?? null);
            $hashes = BinariesService::w411Int($batch['hashes'] ?? null);
            if ($binaries === null || $inserted === null || $all === null || $hashes === null || min($binaries, $inserted, $all, $hashes) < 0) {
                $summary['bad_counts']++;

                continue;
            }
            $strategy = self::cleanupStrategyPhp($inserted, $all, $hashes);
            $summary[$strategy]++;
            $collections = match ($strategy) {
                'inserted' => self::w421Chunks($inserted, $size),
                'all' => self::w421Chunks($all, $size),
                'hashes' => self::w421Chunks($hashes, $size),
                default => 1,
            };
            $statements = self::w421SaturatingAdd($summary['statements'], self::w421SaturatingMul(self::w421Chunks($binaries, $size), 2));
            $summary['statements'] = self::w421SaturatingAdd($statements, self::w421SaturatingMul($collections, 3));
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $batches
     * @return array<string, int>|null
     */
    public static function summarizeCleanupPlanBatchNative(array $batches, int $maxRows = self::MAX_SQL_ROWS_PER_STATEMENT): ?array
    {
        $words = [$maxRows, \count($batches)];
        $blob = [];
        foreach ($batches as $batch) {
            if (\is_array($batch)) {
                BinariesService::w411PackRow($words, $blob, [$batch['binaries'] ?? null, $batch['inserted'] ?? null, $batch['all'] ?? null, $batch['hashes'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = self::w421Invoke(self::$w421Ffi, 1, $words, $blob);

        return $values !== null && \count($values) === \count(self::W421_CLEANUP_KEYS) ? array_combine(self::W421_CLEANUP_KEYS, $values) : null;
    }

    public static function useW421RustFfi(bool $enable = true): void
    {
        self::$w421UseRustFfi = $enable;
    }

    public static function usesW421RustFfi(): bool
    {
        return self::$w421UseRustFfi ?? self::W421_RUST_FFI_DEFAULT;
    }

    public static function resetW421RustFfi(): void
    {
        self::$w421UseRustFfi = null;
        self::$w421Ffi = null;
    }

    /** ceil($count / $size) for a non-negative count and a positive size. */
    public static function w421Chunks(int $count, int $size): int
    {
        return intdiv($count, $size) + (int) ($count % $size !== 0);
    }

    /** Adds two non-negative ints, saturating at PHP_INT_MAX. */
    public static function w421SaturatingAdd(int $total, int $value): int
    {
        return $value > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $value;
    }

    /** Multiplies a non-negative int by a positive factor, saturating at PHP_INT_MAX. */
    private static function w421SaturatingMul(int $value, int $factor): int
    {
        return $value > intdiv(PHP_INT_MAX, $factor) ? PHP_INT_MAX : $value * $factor;
    }

    /**
     * Calls the Wave 42.1 export with a Wave 41.1-style frame (see BinariesService::w411PackRow()), retrying once
     * with a larger scratch buffer when it reports `-required`; `$ffi` is the caller's cached library handle.
     * Returns the decoded ints or null to fall back.
     *
     * @param  list<int>  $words
     * @param  list<string>  $blob
     * @return list<int>|null
     */
    public static function w421Invoke(FFI|false|null &$ffi, int $operation, array $words, array $blob): ?array
    {
        $input = pack('q', \count($words)).pack('q*', ...$words).implode('', $blob);
        if (\strlen($input) > self::W421_RUST_MAX_INPUT) {
            return null;
        }
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w421', self::W421_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w421_binaries_transaction_missed_part_backfill($operation, 0, $input, \strlen($input), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                if (-$written <= $capacity || -$written > self::W421_RUST_MAX_INPUT) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w421_binaries_transaction_missed_part_backfill($operation, 0, $input, \strlen($input), $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity || $written % 8 !== 0) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $written));

            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }
}
