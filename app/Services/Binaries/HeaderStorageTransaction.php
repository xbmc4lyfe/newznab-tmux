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

    /**
     * Enabled: the paired benchmark in the W54 parity test measures 3.04x common mean, 2.04x common
     * p99, 2.26x uncommon mean and 1.82x uncommon p99 (per-metric minimum of three runs), above the
     * 1.5x rule.
     */
    private const bool W54_RUST_FFI_DEFAULT = true;

    private const string W54_FFI_CDEF = 'ssize_t nntmux_w541_cleanup_hash_summary_batch(const char *hashes, size_t hashes_len, size_t count, int64_t max_rows, int64_t *out, size_t out_cap);';

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 54 batch routed to Rust or PHP, then restores the routing. */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /** {@see summarizeCleanupHashes()} keys, in the order the Rust export writes them. */
    private const array W54_HASH_SUMMARY_KEYS = ['hashes', 'sha1', 'md5', 'mixed_case', 'invalid', 'distinct', 'statements'];

    /**
     * Summarizes rollback cleanup collection hashes: lowercase 40-hex (SHA-1) and 32-hex (MD5)
     * hashes, 32/40-hex hashes holding uppercase digits (mixed case), everything else (invalid),
     * distinct lowercase hashes and the DELETE statements the `hashes` strategy issues for them
     * (three per `max(1, $maxRows)` distinct hashes).
     *
     * @param  array<array-key, string>  $hashes
     * @return array{hashes: int, sha1: int, md5: int, mixed_case: int, invalid: int, distinct: int, statements: int}
     */
    public static function summarizeCleanupHashes(array $hashes, int $maxRows = self::MAX_SQL_ROWS_PER_STATEMENT): array
    {
        return self::usesW54RustFfi() ? self::summarizeCleanupHashesNative($hashes, $maxRows) : self::summarizeCleanupHashesPhp($hashes, $maxRows);
    }

    /**
     * @param  array<array-key, string>  $hashes
     * @return array{hashes: int, sha1: int, md5: int, mixed_case: int, invalid: int, distinct: int, statements: int}
     */
    public static function summarizeCleanupHashesPhp(array $hashes, int $maxRows = self::MAX_SQL_ROWS_PER_STATEMENT): array
    {
        $sha1 = $md5 = $mixed = $invalid = 0;
        $seen = [];
        foreach ($hashes as $hash) {
            $length = \strlen($hash);
            if (($length !== 32 && $length !== 40) || ! ctype_xdigit($hash)) {
                $invalid++;

                continue;
            }
            if (strtolower($hash) !== $hash) {
                $mixed++;

                continue;
            }
            if ($length === 40) {
                $sha1++;
            } else {
                $md5++;
            }
            $seen[$hash] = true;
        }
        $distinct = \count($seen);

        return ['hashes' => \count($hashes), 'sha1' => $sha1, 'md5' => $md5, 'mixed_case' => $mixed, 'invalid' => $invalid, 'distinct' => $distinct, 'statements' => self::w421Chunks($distinct, max(1, $maxRows)) * 3];
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a hash
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $hashes
     * @return array{hashes: int, sha1: int, md5: int, mixed_case: int, invalid: int, distinct: int, statements: int}
     */
    public static function summarizeCleanupHashesNative(array $hashes, int $maxRows = self::MAX_SQL_ROWS_PER_STATEMENT): array
    {
        $count = \count($hashes);
        $buffer = $count === 0 ? null : self::w54Buffer(7);
        if ($buffer === null) {
            return self::summarizeCleanupHashesPhp($hashes, $maxRows);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $hashes);
        try {
            // @phpstan-ignore method.notFound
            $words = self::w54Decode($out, $ffi->nntmux_w541_cleanup_hash_summary_batch($joined, \strlen($joined), $count, $maxRows, $out, $cap), 7);
        } catch (\Throwable) {
            $words = null;
        }
        if ($words === null || \count($words) !== 7) {
            return self::summarizeCleanupHashesPhp($hashes, $maxRows);
        }

        /** @var array{hashes: int, sha1: int, md5: int, mixed_case: int, invalid: int, distinct: int, statements: int} */
        return array_combine(self::W54_HASH_SUMMARY_KEYS, $words);
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: FFI\CData, 2: int}|null
     */
    private static function w54Buffer(int $words): ?array
    {
        $buffer = self::w54RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w54Decode(FFI\CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 54 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('header_storage_w54', self::W54_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof FFI\CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    // ---- Wave 66.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 3.64x/3.44x, uncommon 3.26x/2.74x); see RustBinariesCollectionHeaderParserStorageTxMissedPartW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    private const string W66_HEADER_STORAGE_TX_FFI_CDEF = <<<'C'
        intptr_t nntmux_w662_header_storage_tx_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W66_HEADER_STORAGE_TX_TAIL = 24;

    /**
     * Wave 66 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w66', self::W66_HEADER_STORAGE_TX_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof FFI\CData || $capacity < $words) {
                    $resolved = self::w66RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof FFI\CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w66HeaderStorageTxPlanBatch() to Rust until reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Routes w66HeaderStorageTxPlanBatch() to PHP until reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores W66_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    public static function usesW66RustFfi(): bool
    {
        $enabled = self::w66RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W66_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w66HeaderStorageTxPlanBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderStorageTxPlanBatch(array $rows): array
    {
        return self::usesW66RustFfi() ? self::w66HeaderStorageTxPlanBatchNative($rows) : self::w66HeaderStorageTxPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderStorageTxPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W66_HEADER_STORAGE_TX_TAIL;
        /** @var array{FFI, FFI\CData, int}|null $state */
        $state = $count === 0 ? null : self::w66RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w66HeaderStorageTxPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W66_HEADER_STORAGE_TX_FFI_CDEF.)
            $written = $ffi->nntmux_w662_header_storage_tx_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w66HeaderStorageTxPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w66Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w66Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /** Lowercased error message needles per class, matched in order (7 other). */
    private const array W66_SQL_ERROR_NEEDLES = [
        1 => ['deadlock', '1213', '40001'],
        2 => ['lock wait timeout', '1205'],
        3 => ['gone away', 'lost connection', '2006', '2013'],
        4 => ['duplicate entry', '1062'],
        5 => ['max_allowed_packet', 'too large', '1153'],
        6 => ['foreign key', '1452'],
    ];

    /** Retry backoff base in milliseconds per retryable class. */
    private const array W66_TX_BACKOFF_BASE = [0, 50, 200, 500];

    private const int W66_TX_MAX_RETRIES = 10;

    private const int W66_TX_MAX_CHUNK = 500;

    private const int W66_TX_MAX_BACKOFF_MS = 5000;

    private const int W66_TX_SLOW_MS = 30000;

    /** 0 none, 1 deadlock, 2 lock wait timeout, 3 connection lost, 4 duplicate, 5 too large, 6 foreign key, 7 other. */
    private static function w66SqlErrorClass(string $error): int
    {
        if ($error === '') {
            return 0;
        }
        $lower = strtolower($error);
        foreach (self::W66_SQL_ERROR_NEEDLES as $class => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    return $class;
                }
            }
        }

        return 7;
    }

    /**
     * Wave 66.2 transaction outcome plan. Per `rows\terror\tattempt\telapsed_ms` row `outcome |
     * error_class << 3 | retryable << 6 | min(attempt, 255) << 7 | backoff_ms << 15 | chunk << 31
     * | min(chunks, 2^20 - 1) << 41 | slow << 61`. Rows, attempt and elapsed are w66Int() fields of
     * 9, 3 and 9 digits; the trimmed error is a w66SqlErrorClass() (deadlock, lock wait and lost
     * connection are retryable). No positive row count or an unparsable attempt or elapsed time
     * is malformed (5). Otherwise: no error commits (0), a duplicate commits skipping duplicates
     * (4), a retryable error under W66_TX_MAX_RETRIES attempts retries (1) after
     * `min(base << min(attempt, 6), W66_TX_MAX_BACKOFF_MS)` ms, a too-large or exhausted retryable
     * statement of more than one row splits (2) into chunks of `min(W66_TX_MAX_CHUNK, ceil(rows /
     * 2))` rows, and anything else fails (3). A slow row took more than W66_TX_SLOW_MS ms. Then six
     * outcome and eight class counts, committed rows, retried rows, split chunks, total backoff,
     * the highest attempt, total and longest elapsed time (well-formed rows), slow rows, the
     * longest run of consecutive deadlocks and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66HeaderStorageTxPlanBatchPhp(array $rows): array
    {
        $out = [];
        $outcomes = array_fill(0, 6, 0);
        $classes = array_fill(0, 8, 0);
        $committed = $retried = $splitChunks = $totalBackoff = $maxAttempt = $totalElapsed = $maxElapsed = $slowRows = $streak = $maxStreak = 0;
        foreach ($rows as $row) {
            [$count, $error, $attempt, $elapsed] = explode("\t", (string) $row, 4) + ['', '', '', ''];
            $count = self::w66Int($count, 9);
            $attempt = self::w66Int($attempt, 3);
            $elapsed = self::w66Int($elapsed, 9);
            $class = self::w66SqlErrorClass(trim($error));
            $n = \is_int($count) ? $count : 0;
            $malformed = $count === false || $n === 0 || $attempt === false || $elapsed === false;
            $a = \is_int($attempt) ? $attempt : 0;
            $ms = \is_int($elapsed) ? $elapsed : 0;
            $retryable = $class >= 1 && $class <= 3;
            $outcome = match (true) {
                $malformed => 5,
                $class === 0 => 0,
                $class === 4 => 4,
                $retryable && $a < self::W66_TX_MAX_RETRIES => 1,
                ($class === 5 || $retryable) && $n > 1 => 2,
                default => 3,
            };
            $backoff = $outcome === 1 ? min(self::W66_TX_BACKOFF_BASE[$class] << min($a, 6), self::W66_TX_MAX_BACKOFF_MS) : 0;
            $chunk = $chunks = 0;
            if ($outcome === 2) {
                $chunk = min(self::W66_TX_MAX_CHUNK, ($n + 1) >> 1);
                $chunks = intdiv($n + $chunk - 1, $chunk);
            }
            $slow = $ms > self::W66_TX_SLOW_MS;
            $streak = $class === 1 ? $streak + 1 : 0;
            $maxStreak = max($maxStreak, $streak);
            if ($outcome === 0 || $outcome === 4) {
                $committed += $n;
            } elseif ($outcome === 1) {
                $retried += $n;
            }
            if (! $malformed) {
                $maxAttempt = max($maxAttempt, $a);
                $totalElapsed += $ms;
                $maxElapsed = max($maxElapsed, $ms);
            }
            $outcomes[$outcome]++;
            $classes[$class]++;
            $splitChunks += $chunks;
            $totalBackoff += $backoff;
            $slowRows += (int) $slow;
            $out[] = $outcome | $class << 3 | (int) $retryable << 6 | min($a, 255) << 7 | $backoff << 15 | $chunk << 31 | min($chunks, 0xFFFFF) << 41 | (int) $slow << 61;
        }
        array_push($out, ...$outcomes, ...$classes);
        array_push($out, $committed, $retried, $splitChunks, $totalBackoff, $maxAttempt, $totalElapsed, $maxElapsed, $slowRows, $maxStreak);
        $out[] = self::w66Digest($out);

        return $out;
    }
}
