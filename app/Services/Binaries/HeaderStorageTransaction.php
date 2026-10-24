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
}
