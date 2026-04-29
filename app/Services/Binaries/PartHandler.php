<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles part record creation during header storage.
 */
final class PartHandler
{
    /**
     * Hard upper bound on rows packed into a single SQL statement
     * (multi-row INSERT, OR-clause SELECT). The public chunkSize controls
     * when we flush; this constant guarantees the actual SQL we emit is
     * never large enough to blow up PHP/MySQL memory.
     */
    private const MAX_SQL_ROWS_PER_STATEMENT = 500;

    /** @var array<string, mixed> Pending parts to insert */
    private array $parts = [];

    /** @var array<string, mixed> Part numbers successfully inserted */
    private array $insertedPartNumbers = [];

    /** @var array<string, mixed> Part numbers that failed to insert */
    private array $failedPartNumbers = [];

    private int $chunkSize;

    /** @phpstan-ignore property.onlyWritten */
    private bool $addToPartRepair;

    public function __construct(int $chunkSize = 5000, bool $addToPartRepair = true)
    {
        $this->chunkSize = max(100, $chunkSize);
        $this->addToPartRepair = $addToPartRepair;
    }

    /**
     * Reset state for a new batch.
     */
    public function reset(): void
    {
        $this->parts = [];
        $this->insertedPartNumbers = [];
        $this->failedPartNumbers = [];
    }

    /**
     * Set whether to add failed parts to repair queue.
     */
    public function setAddToPartRepair(bool $value): void
    {
        $this->addToPartRepair = $value;
    }

    /**
     * Add a part to the pending insert queue.
     *
     * @param  array<string, mixed>  $header
     * @return bool True if chunk was flushed successfully (or not needed), false on flush failure
     */
    public function addPart(int $binaryId, array $header): bool
    {
        $this->parts[] = [
            'binaries_id' => $binaryId,
            'number' => $header['Number'],
            'messageid' => $header['Message-ID'],
            'partnumber' => $header['matches'][2],
            'size' => $header['Bytes'],
        ];

        // Auto-flush when chunk size reached
        if (\count($this->parts) >= $this->chunkSize) {
            return $this->flush();
        }

        return true;
    }

    /**
     * Flush pending parts to database.
     */
    public function flush(): bool
    {
        if (empty($this->parts)) {
            return true;
        }

        $insertedCount = $this->insertChunk($this->parts);

        if ($insertedCount === null) {
            foreach ($this->parts as $part) {
                $this->failedPartNumbers[] = $part['number'];
            }

            $this->parts = [];

            return false;
        }

        if ($insertedCount === \count($this->parts)) {
            foreach ($this->parts as $part) {
                $this->insertedPartNumbers[] = $part['number'];
            }

            $this->parts = [];

            return true;
        }

        $existingKeys = $this->existingPartKeys($this->parts);
        foreach ($this->parts as $part) {
            $key = $this->partKey((int) $part['binaries_id'], (int) $part['number']);
            if (! isset($existingKeys[$key])) {
                $this->failedPartNumbers[] = $part['number'];
            }
        }

        $this->parts = [];

        return empty($this->failedPartNumbers);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function insertChunk(array $parts): ?int
    {
        $driver = DB::getDriverName();
        $totalInserted = 0;

        try {
            foreach (array_chunk($parts, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
                $placeholders = [];
                $bindings = [];

                foreach ($chunk as $row) {
                    $placeholders[] = '(?,?,?,?,?)';
                    $bindings[] = $row['binaries_id'];
                    $bindings[] = $row['number'];
                    $bindings[] = $row['messageid'];
                    $bindings[] = $row['partnumber'];
                    $bindings[] = $row['size'];
                }

                $sql = $driver === 'sqlite'
                    ? 'INSERT OR IGNORE INTO parts (binaries_id, number, messageid, partnumber, size) VALUES '.implode(',', $placeholders)
                    : 'INSERT IGNORE INTO parts (binaries_id, number, messageid, partnumber, size) VALUES '.implode(',', $placeholders);

                $totalInserted += (int) DB::affectingStatement($sql, $bindings);
            }

            return $totalInserted;
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::error('Parts chunk insert failed: '.$e->getMessage());
            }

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $parts
     * @return array<string, true>
     */
    private function existingPartKeys(array $parts): array
    {
        if (empty($parts)) {
            return [];
        }

        $keys = [];
        // Group by binaries_id and use IN(...) per binary so the SELECT does
        // not turn into thousands of OR clauses with two bindings each.
        $numbersByBinary = [];
        foreach ($parts as $part) {
            $numbersByBinary[(int) $part['binaries_id']][] = $part['number'];
        }

        foreach ($numbersByBinary as $binaryId => $numbers) {
            $numbers = array_values(array_unique($numbers));
            foreach (array_chunk($numbers, self::MAX_SQL_ROWS_PER_STATEMENT) as $chunk) {
                $placeholders = implode(',', array_fill(0, \count($chunk), '?'));
                $bindings = $chunk;
                $bindings[] = $binaryId;

                $rows = DB::select(
                    "SELECT binaries_id, number FROM parts WHERE number IN ({$placeholders}) AND binaries_id = ?",
                    $bindings
                );

                foreach ($rows as $row) {
                    $keys[$this->partKey((int) $row->binaries_id, (int) $row->number)] = true;
                }
            }
        }

        return $keys;
    }

    private function partKey(int $binaryId, int $number): string
    {
        return $binaryId.':'.$number;
    }

    /**
     * Get numbers of successfully inserted parts.
     *
     * @return array<string, mixed>
     */
    public function getInsertedNumbers(): array
    {
        return $this->insertedPartNumbers;
    }

    /**
     * Get numbers of failed part inserts.
     *
     * @return array<string, mixed>
     */
    public function getFailedNumbers(): array
    {
        return $this->failedPartNumbers;
    }

    /**
     * Check if there are pending parts waiting to be flushed.
     */
    public function hasPending(): bool
    {
        return ! empty($this->parts);
    }
}
