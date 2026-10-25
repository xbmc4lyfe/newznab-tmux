<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\RustPipelineLibrary;
use App\Support\SqlError;
use FFI;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Handles part record creation during header storage.
 *
 * @phpstan-type PartRow array{binaries_id: int, number: int|string, messageid: string, partnumber: int, size: int|string}
 */
final class PartHandler
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_part_validation_error(int64_t part_number, const char *msg_id_ptr, size_t msg_id_len);
        int32_t nntmux_part_should_prefer(const char *cand_msg_ptr, size_t cand_msg_len, int64_t cand_size, int64_t cand_num, const char *curr_msg_ptr, size_t curr_msg_len, int64_t curr_size, int64_t curr_num);
        ssize_t nntmux_part_deduplicate(const char *input_ptr, size_t input_len, size_t count, uint32_t sort_by_pk, char *out_ptr, size_t out_cap);
        ssize_t nntmux_part_sort_primary_key(const char *input_ptr, size_t input_len, size_t count, char *out_ptr, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /** @var list<PartRow> Pending parts to insert */
    private array $parts = [];

    /** @var list<int|string> Part numbers successfully inserted */
    private array $insertedPartNumbers = [];

    /** @var list<int|string> Part numbers that failed to insert */
    private array $failedPartNumbers = [];

    /** @var array<int, true> Binary ids whose stored parts must be re-aggregated */
    private array $touchedBinaryIds = [];

    private int $chunkSize;

    private ?Throwable $lastException = null;

    /** @phpstan-ignore property.onlyWritten */
    private bool $addToPartRepair;

    public function __construct(int $chunkSize = 500, bool $addToPartRepair = true)
    {
        $this->chunkSize = max(1, $chunkSize);
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
        $this->touchedBinaryIds = [];
        $this->lastException = null;
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
        $partNumber = (int) ($header['matches'][2] ?? 0);
        $messageId = trim((string) ($header['Message-ID'] ?? ''));
        if (self::validationError($header) !== null) {
            if (isset($header['Number'])) {
                $this->failedPartNumbers[] = $header['Number'];
            }

            return false;
        }

        $this->parts[] = [
            'binaries_id' => $binaryId,
            'number' => $header['Number'],
            'messageid' => $messageId,
            'partnumber' => $partNumber,
            'size' => $header['Bytes'],
        ];

        // Auto-flush when chunk size reached
        if (\count($this->parts) >= $this->chunkSize) {
            return $this->flush();
        }

        return true;
    }

    /** @param array<string, mixed> $header */
    public static function validationError(array $header): ?string
    {
        return self::validationErrorPhp($header);
    }

    /** @param array<string, mixed> $header */
    public static function validationErrorPhp(array $header): ?string
    {
        if ((int) ($header['matches'][2] ?? 0) <= 0) {
            return 'Invalid part number';
        }

        $messageId = trim((string) ($header['Message-ID'] ?? ''));
        if ($messageId === '' || strlen($messageId) > 255 || preg_match('/^[\x20-\x7E]+$/D', $messageId) !== 1) {
            return 'Invalid message ID';
        }

        return null;
    }

    /** @param array<string, mixed> $header */
    public static function validationErrorNative(array $header, bool &$supported = false): ?string
    {
        $supported = false;
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $partNumber = (int) ($header['matches'][2] ?? 0);
        $rawMessageId = (string) ($header['Message-ID'] ?? '');

        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_part_validation_error($partNumber, $rawMessageId, \strlen($rawMessageId));
            if (! \is_int($code) || $code < 0) {
                return null;
            }
            $supported = true;

            return match ($code) {
                1 => 'Invalid part number',
                2 => 'Invalid message ID',
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Flush pending parts to database.
     */
    public function flush(): bool
    {
        if (empty($this->parts)) {
            return true;
        }

        $pendingParts = $this->parts;
        $parts = $this->deduplicateParts($pendingParts);
        foreach ($parts as $part) {
            $this->touchedBinaryIds[(int) $part['binaries_id']] = true;
        }

        $insertedCount = $this->insertChunk($parts);

        if ($insertedCount === null) {
            foreach ($pendingParts as $part) {
                $this->failedPartNumbers[] = $part['number'];
            }

            $this->parts = [];

            return false;
        }

        if ($insertedCount === \count($parts)) {
            foreach ($parts as $part) {
                $this->insertedPartNumbers[] = $part['number'];
            }

            $this->parts = [];

            return true;
        }

        $existingKeys = $this->existingPartKeys($parts);
        foreach ($parts as $part) {
            $key = $this->partKey((int) $part['binaries_id'], (int) $part['partnumber']);
            if (! isset($existingKeys[$key])) {
                $this->failedPartNumbers[] = $part['number'];
            }
        }

        $this->parts = [];

        return empty($this->failedPartNumbers);
    }

    /**
     * @param  list<PartRow>  $parts
     */
    private function insertChunk(array $parts): ?int
    {
        $driver = DB::getDriverName();
        $totalInserted = 0;
        // Primary-key order (binaries_id, partnumber), so concurrent workers lock shared rows in the same sequence.
        $parts = $this->sortPartsByPrimaryKey($parts);

        try {
            foreach (array_chunk($parts, $this->chunkSize) as $chunk) {
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
        } catch (Throwable $e) {
            $this->lastException = $e;
            if (config('app.debug') === true) {
                SqlError::logFailure('Parts chunk insert failed', $e);
            }

            return null;
        }
    }

    /**
     * @param  list<PartRow>  $parts
     * @return array<string, true>
     */
    private function existingPartKeys(array $parts): array
    {
        if (empty($parts)) {
            return [];
        }

        // Deduplicate (binaries_id, partnumber) pairs so we don't bind the same
        // tuple twice when a chunk contains repeats.
        $uniquePairs = [];
        foreach ($parts as $part) {
            $bid = (int) $part['binaries_id'];
            $partNumber = (int) $part['partnumber'];
            $uniquePairs[$bid.':'.$partNumber] = [$bid, $partNumber];
        }

        $keys = [];
        // Single tuple-IN per sub-chunk: (binaries_id, partnumber) IN ((?,?),...).
        // Both MySQL and SQLite (3.15+) support this row-constructor form,
        // which lets one SELECT replace the previous "one SELECT per binary".
        foreach (array_chunk(array_values($uniquePairs), $this->chunkSize) as $chunk) {
            $tuples = implode(',', array_fill(0, \count($chunk), '(?,?)'));
            $bindings = [];
            foreach ($chunk as [$bid, $partNumber]) {
                $bindings[] = $bid;
                $bindings[] = $partNumber;
            }

            $rows = DB::select(
                "SELECT binaries_id, partnumber FROM parts WHERE (binaries_id, partnumber) IN ({$tuples})",
                $bindings
            );

            foreach ($rows as $row) {
                $keys[$this->partKey((int) $row->binaries_id, (int) $row->partnumber)] = true;
            }
        }

        return $keys;
    }

    private function partKey(int $binaryId, int $partNumber): string
    {
        return $binaryId.':'.$partNumber;
    }

    /**
     * Sort parts in primary-key order (binaries_id ASC, partnumber ASC).
     *
     * @param  list<PartRow>  $parts
     * @return list<PartRow>
     */
    public function sortPartsByPrimaryKey(array $parts): array
    {
        if (self::$useRustFfi) {
            $native = $this->sortPartsByPrimaryKeyNative($parts);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->sortPartsByPrimaryKeyPhp($parts);
    }

    /**
     * @param  list<PartRow>  $parts
     * @return list<PartRow>
     */
    public function sortPartsByPrimaryKeyPhp(array $parts): array
    {
        usort($parts, static fn (array $a, array $b): int => [(int) $a['binaries_id'], (int) $a['partnumber']] <=> [(int) $b['binaries_id'], (int) $b['partnumber']]);

        return $parts;
    }

    /**
     * @param  list<PartRow>  $parts
     * @return list<PartRow>|null
     */
    public function sortPartsByPrimaryKeyNative(array $parts): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $count = \count($parts);
        if ($count <= 1) {
            return array_values($parts);
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $reindexed = array_values($parts);
        $buf = '';
        foreach ($reindexed as $part) {
            $buf .= \pack('q2', (int) $part['binaries_id'], (int) $part['partnumber']);
        }

        $needed = $count * 4;
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $needed), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_part_sort_primary_key($buf, \strlen($buf), $count, $scratch, $cap);
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_part_sort_primary_key($buf, \strlen($buf), $count, $scratch, $cap);
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
            }
            if ($written !== $needed) {
                return null;
            }

            /** @var array<int, int>|false $indices */
            $indices = \unpack('L*', FFI::string($scratch, $written));
            if ($indices === false) {
                return null;
            }

            $sorted = [];
            foreach ($indices as $idx) {
                $sorted[] = $reindexed[$idx];
            }

            return $sorted;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Choose one deterministic segment for each (binary, part number).
     * Prefer a non-empty message id, then the larger payload, then the lower
     * article number. This matches the storage migration's duplicate policy.
     *
     * @param  list<PartRow>  $parts
     * @return list<PartRow>
     */
    public function deduplicateParts(array $parts): array
    {
        return $this->deduplicatePartsPhp($parts);
    }

    /**
     * @param  list<PartRow>  $parts
     * @return list<PartRow>
     */
    public function deduplicatePartsPhp(array $parts): array
    {
        $deduplicated = [];
        foreach ($parts as $part) {
            $key = $this->partKey((int) $part['binaries_id'], (int) $part['partnumber']);
            if (! isset($deduplicated[$key]) || $this->shouldPreferPhp($part, $deduplicated[$key])) {
                $deduplicated[$key] = $part;
            }
        }

        return array_values($deduplicated);
    }

    /**
     * @param  list<PartRow>  $parts
     * @return list<PartRow>|null
     */
    public function deduplicatePartsNative(array $parts, bool $sortByPrimaryKey = false): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $count = \count($parts);
        if ($count === 0) {
            return [];
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $reindexed = array_values($parts);
        $buf = '';
        foreach ($reindexed as $part) {
            $msg = (string) $part['messageid'];
            $buf .= \pack(
                'q4L',
                (int) $part['binaries_id'],
                (int) $part['partnumber'],
                (int) $part['size'],
                (int) $part['number'],
                \strlen($msg)
            ).$msg;
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $count * 4), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_part_deduplicate($buf, \strlen($buf), $count, $sortByPrimaryKey ? 1 : 0, $scratch, $cap);
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_part_deduplicate($buf, \strlen($buf), $count, $sortByPrimaryKey ? 1 : 0, $scratch, $cap);
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
            }
            if ($written === 0) {
                return [];
            }

            /** @var array<int, int>|false $indices */
            $indices = \unpack('L*', FFI::string($scratch, $written));
            if ($indices === false) {
                return null;
            }

            $out = [];
            foreach ($indices as $idx) {
                $out[] = $reindexed[$idx];
            }

            return $out;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  PartRow  $candidate
     * @param  PartRow  $current
     */
    public function shouldPrefer(array $candidate, array $current): bool
    {
        return $this->shouldPreferPhp($candidate, $current);
    }

    /**
     * @param  PartRow  $candidate
     * @param  PartRow  $current
     */
    public function shouldPreferPhp(array $candidate, array $current): bool
    {
        $candidateHasMessageId = trim((string) $candidate['messageid']) !== '';
        $currentHasMessageId = trim((string) $current['messageid']) !== '';
        if ($candidateHasMessageId !== $currentHasMessageId) {
            return $candidateHasMessageId;
        }

        if ((int) $candidate['size'] !== (int) $current['size']) {
            return (int) $candidate['size'] > (int) $current['size'];
        }

        return (int) $candidate['number'] < (int) $current['number'];
    }

    /**
     * @param  PartRow  $candidate
     * @param  PartRow  $current
     */
    public function shouldPreferNative(array $candidate, array $current): ?bool
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $candMsg = (string) $candidate['messageid'];
        $currMsg = (string) $current['messageid'];

        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_part_should_prefer(
                $candMsg,
                \strlen($candMsg),
                (int) $candidate['size'],
                (int) $candidate['number'],
                $currMsg,
                \strlen($currMsg),
                (int) $current['size'],
                (int) $current['number']
            );
            if (! \is_int($res) || $res < 0) {
                return null;
            }

            return $res === 1;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<int> */
    public function getTouchedBinaryIds(): array
    {
        return array_keys($this->touchedBinaryIds);
    }

    /**
     * Get numbers of successfully inserted parts.
     *
     * @return list<int|string>
     */
    public function getInsertedNumbers(): array
    {
        return $this->insertedPartNumbers;
    }

    /**
     * Get numbers of failed part inserts.
     *
     * @return list<int|string>
     */
    public function getFailedNumbers(): array
    {
        return $this->failedPartNumbers;
    }

    public function getLastException(): ?Throwable
    {
        return $this->lastException;
    }

    /**
     * Check if there are pending parts waiting to be flushed.
     */
    public function hasPending(): bool
    {
        return ! empty($this->parts);
    }

    /**
     * PHP stays the default for summarizePartRowBatch(): the paired benchmark in
     * tests/Unit/RustBinariesTransactionMissedPartBackfillW42ParityTest.php does not clear 1.5x.
     */
    public const bool W421_RUST_FFI_DEFAULT = false;

    private const array W421_PART_KEYS = ['parts', 'invalid_rows', 'bad_binary', 'bad_part_number', 'bad_message_id', 'bad_size', 'duplicate_keys',
        'unsorted', 'total_size'];

    private static ?bool $w421UseRustFfi = null;

    private static FFI|false|null $w421Ffi = null;

    /**
     * Summarizes pending part rows (`binaries_id`, `partnumber`, `messageid`, `size`): a binary id or part number that
     * is not a positive int (or 1-18 digit string) is bad, a message id fails like validationErrorPhp() (not a string,
     * or trim()med to '', over 255 bytes or not printable ASCII), and a size that is not a non-negative int is bad
     * while other sizes add to the total (saturating at PHP_INT_MAX). Among rows with a valid (binary, part) key,
     * repeated keys are duplicates and a key below the previous one is unsorted. Non-array rows are invalid.
     *
     * @param  array<array-key, mixed>  $parts
     * @return array<string, int>
     */
    public static function summarizePartRowBatch(array $parts): array
    {
        return (self::usesW421RustFfi() ? self::summarizePartRowBatchNative($parts) : null)
            ?? self::summarizePartRowBatchPhp($parts);
    }

    /**
     * @param  array<array-key, mixed>  $parts
     * @return array<string, int>
     */
    public static function summarizePartRowBatchPhp(array $parts): array
    {
        $summary = array_fill_keys(self::W421_PART_KEYS, 0);
        $summary['parts'] = \count($parts);
        $seen = [];
        $previous = null;
        foreach ($parts as $part) {
            if (! \is_array($part)) {
                $summary['invalid_rows']++;

                continue;
            }
            $binary = BinariesService::w411Int($part['binaries_id'] ?? null);
            $binary = $binary !== null && $binary > 0 ? $binary : null;
            $partNumber = BinariesService::w411Int($part['partnumber'] ?? null);
            $partNumber = $partNumber !== null && $partNumber > 0 ? $partNumber : null;
            $summary['bad_binary'] += (int) ($binary === null);
            $summary['bad_part_number'] += (int) ($partNumber === null);
            $messageId = $part['messageid'] ?? null;
            $messageId = \is_string($messageId) ? trim($messageId) : '';
            $summary['bad_message_id'] += (int) ($messageId === '' || \strlen($messageId) > 255 || preg_match('/^[\x20-\x7E]+$/D', $messageId) !== 1);
            $size = BinariesService::w411Int($part['size'] ?? null);
            if ($size === null || $size < 0) {
                $summary['bad_size']++;
            } else {
                $summary['total_size'] = HeaderStorageTransaction::w421SaturatingAdd($summary['total_size'], $size);
            }
            if ($binary === null || $partNumber === null) {
                continue;
            }
            $key = $binary.':'.$partNumber;
            $summary['duplicate_keys'] += (int) isset($seen[$key]);
            $seen[$key] = true;
            if ($previous !== null && ($binary < $previous[0] || ($binary === $previous[0] && $partNumber < $previous[1]))) {
                $summary['unsorted']++;
            }
            $previous = [$binary, $partNumber];
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $parts
     * @return array<string, int>|null
     */
    public static function summarizePartRowBatchNative(array $parts): ?array
    {
        $words = [\count($parts)];
        $blob = [];
        foreach ($parts as $part) {
            if (\is_array($part)) {
                BinariesService::w411PackRow($words, $blob, [$part['binaries_id'] ?? null, $part['partnumber'] ?? null, $part['messageid'] ?? null,
                    $part['size'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = HeaderStorageTransaction::w421Invoke(self::$w421Ffi, 3, $words, $blob);

        return $values !== null && \count($values) === \count(self::W421_PART_KEYS) ? array_combine(self::W421_PART_KEYS, $values) : null;
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
}
