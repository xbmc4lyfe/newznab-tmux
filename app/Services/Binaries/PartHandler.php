<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Support\RustPipelineLibrary;
use App\Support\SqlError;
use FFI;
use FFI\CData;
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

    /** Wave 52.2 paired benchmarks decide this default; see RustBinariesCollectionHeaderMissedPartHandlerW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        intptr_t nntmux_w522_part_admission_plan_batch(const char *ids, size_t ids_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 52 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w52', self::W52_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w52RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planPartAdmission() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * Batch admission plan, as addPart() validates and deduplicateParts() keeps one row per
     * (binary id, part number). One word per part: `flags | winner << 8 | crc32(trim(message id))
     * << 32`. Flags: 1 part number <= 0, 2 invalid message id (checked only for a valid part
     * number), 4 the row survives deduplication, 8 the key repeats an earlier valid row. A valid
     * row's winner is its key's survivor under shouldPreferPhp() (larger size, then smaller article
     * number); an invalid row's winner is itself. Valid, bad part number, bad message id, surviving
     * and replaced-or-dropped counts and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $messageIds
     * @param  list<int>  $binaryIds
     * @param  list<int>  $partNumbers
     * @param  list<int>  $sizes
     * @param  list<int>  $numbers
     * @return list<int>
     */
    public static function planPartAdmission(array $messageIds, array $binaryIds, array $partNumbers, array $sizes, array $numbers): array
    {
        return self::usesW52RustFfi()
            ? self::planPartAdmissionNative($messageIds, $binaryIds, $partNumbers, $sizes, $numbers)
            : self::planPartAdmissionPhp($messageIds, $binaryIds, $partNumbers, $sizes, $numbers);
    }

    /**
     * @param  list<string>  $messageIds
     * @param  list<int>  $binaryIds
     * @param  list<int>  $partNumbers
     * @param  list<int>  $sizes
     * @param  list<int>  $numbers
     * @return list<int>
     */
    public static function planPartAdmissionPhp(array $messageIds, array $binaryIds, array $partNumbers, array $sizes, array $numbers): array
    {
        $out = $keys = $winners = [];
        $valid = $badPart = $badId = 0;
        foreach ($messageIds as $i => $raw) {
            $id = trim($raw);
            $part = $partNumbers[$i] ?? 0;
            $row = \count($out);
            $tag = crc32($id) << 32;
            if ($part <= 0) {
                $badPart++;
                $out[] = 1 | $row << 8 | $tag;

                continue;
            }
            if ($id === '' || \strlen($id) > 255 || preg_match('/^[\x20-\x7E]+$/D', $id) !== 1) {
                $badId++;
                $out[] = 2 | $row << 8 | $tag;

                continue;
            }
            $valid++;
            $key = ($binaryIds[$i] ?? 0).':'.$part;
            if (isset($winners[$key])) {
                [$w, $wSize, $wNumber] = $winners[$key];
                $size = $sizes[$i] ?? 0;
                $number = $numbers[$i] ?? 0;
                if ($size > $wSize || ($size === $wSize && $number < $wNumber)) {
                    $winners[$key] = [$row, $size, $number];
                }
                $out[] = 8 | $tag;
            } else {
                $winners[$key] = [$row, $sizes[$i] ?? 0, $numbers[$i] ?? 0];
                $out[] = $tag;
            }
            $keys[$row] = $key;
        }
        foreach ($keys as $row => $key) {
            $w = $winners[$key][0];
            $out[$row] |= $w << 8 | ($w === $row ? 4 : 0);
        }
        $survivors = \count($winners);
        $out[] = $valid;
        $out[] = $badPart;
        $out[] = $badId;
        $out[] = $survivors;
        $out[] = $valid - $survivors;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the columns differ in length or
     * are not lists, or a message id holds a NUL byte.
     *
     * @param  list<string>  $messageIds
     * @param  list<int>  $binaryIds
     * @param  list<int>  $partNumbers
     * @param  list<int>  $sizes
     * @param  list<int>  $numbers
     * @return list<int>
     */
    public static function planPartAdmissionNative(array $messageIds, array $binaryIds, array $partNumbers, array $sizes, array $numbers): array
    {
        $count = \count($messageIds);
        $words = $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w52RustFfiState('out', $words);
        if ($state === null || \count($binaryIds) !== $count || \count($partNumbers) !== $count || \count($sizes) !== $count || \count($numbers) !== $count
            || ! array_is_list($messageIds) || ! array_is_list($binaryIds) || ! array_is_list($partNumbers) || ! array_is_list($sizes) || ! array_is_list($numbers)) {
            return self::planPartAdmissionPhp($messageIds, $binaryIds, $partNumbers, $sizes, $numbers);
        }
        [$ffi, $out, $cap] = $state;
        $joined = implode("\0", $messageIds);
        $ints = pack('P*', ...$binaryIds, ...$partNumbers, ...$sizes, ...$numbers);
        try {
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w522_part_admission_plan_batch($joined, \strlen($joined), $ints, \strlen($ints), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planPartAdmissionPhp($messageIds, $binaryIds, $partNumbers, $sizes, $numbers);
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 8.71x, p99 7.03x; uncommon mean 6.47x, p99 4.40x); see RustBinariesObfuscationPartHandlerGateBookQueryServiceW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    public const string W66_PART_HANDLER_FFI_CDEF = <<<'C'
        ssize_t nntmux_w663_part_handler_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 66.3 batch. */
    public const int W66_PART_HANDLER_TRAILER = 16;

    /** Cap for the Wave 66.3 running totals. */
    private const int W66_TOTAL_CAP = 4_611_686_018_427_387_904;

    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores W66_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    public static function usesW66RustFfi(): bool
    {
        $override = self::w66RustFfiState();

        return \is_bool($override) ? $override : self::W66_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the PartHandler Wave 66 Rust path forced on or off, then restores the previous setting.
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
     * Wave 66.3 part buffering and repair plan with addPart(), validationErrorPhp() and flush()'s rules. Each item is
     * `binaryId|partNumber|totalParts|messageId|bytes`; binary (up to 15 trimmed digits), part and total (up to 9) and
     * bytes (up to 12) are 0 empty, 1 digits or 2 other. The error is 1 invalid part number (not digits or 0), 2 invalid
     * message ID (trimmed, empty, over 255 bytes or outside printable ASCII), 3 invalid binary (not digits or 0), 4
     * malformed bytes or total, else 0. Valid parts are buffered by (binary, part): action 0 new, 1 replaces a smaller
     * buffered duplicate or 2 keeps the buffered one. Per binary the buffer tracks the last part: out-of-order when the
     * part is lower, gap when it skips one. Final marks part == total and overflow part > total (known total). When
     * W66_PART_FLUSH_THRESHOLD distinct parts are buffered the buffer flushes and clears. Per item `error | binaryState
     * << 3 | partState << 5 | totalState << 7 | bytesState << 9 | action << 11 | outOfOrder << 13 | gap << 14 | final
     * << 15 | overflow << 16 | flush << 17 | bufferedAfter (cap 1023) << 18 | part (cap 2^20 - 1) << 28 | KiB (cap
     * 2^15 - 1) << 48`. Then the five error and three action counts, the out-of-order, gap and flush counts, the parts
     * still buffered, the valid bytes total (capped at 2^62), the distinct valid binaries, and the binaries with a known
     * total that need repair (fewer distinct parts than the total) or are complete.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66PartHandlerPlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66PartHandlerPlanBatchNative($items) : self::w66PartHandlerPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66PartHandlerPlanBatchNative(array $items): array
    {
        return self::w66Invoke($items, \count($items) + self::W66_PART_HANDLER_TRAILER) ?? self::w66PartHandlerPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66PartHandlerPlanBatchPhp(array $items): array
    {
        $out = [];
        $errors = [0, 0, 0, 0, 0];
        $actions = [0, 0, 0];
        $outOfOrderCount = $gapCount = $flushes = $bytesSum = 0;
        $buffer = $lastPart = $seenParts = $binaries = [];
        foreach ($items as $item) {
            [$rawBinary, $rawPart, $rawTotal, $rawMid, $rawBytes] = explode('|', (string) $item, 5) + ['', '', '', '', ''];
            [$binaryState, $binary] = self::w66Count($rawBinary, 15);
            [$partState, $part] = self::w66Count($rawPart, 9);
            [$totalState, $total] = self::w66Count($rawTotal, 9);
            [$bytesState, $bytes] = self::w66Count($rawBytes, 12);
            $mid = trim($rawMid);
            if ($partState !== 1 || $part === 0) {
                $error = 1;
            } elseif ($mid === '' || \strlen($mid) > 255 || preg_match('/^[\x20-\x7E]+$/D', $mid) !== 1) {
                $error = 2;
            } elseif ($binaryState !== 1 || $binary === 0) {
                $error = 3;
            } elseif ($bytesState === 2 || $totalState === 2) {
                $error = 4;
            } else {
                $error = 0;
            }
            $errors[$error]++;
            $action = 0;
            $outOfOrder = $gap = $final = $overflow = $flush = false;
            if ($error === 0) {
                $key = $binary.':'.$part;
                if (isset($buffer[$key])) {
                    if ($bytes > $buffer[$key]) {
                        $buffer[$key] = $bytes;
                        $action = 1;
                    } else {
                        $action = 2;
                    }
                } else {
                    $buffer[$key] = $bytes;
                }
                $actions[$action]++;
                if (isset($lastPart[$binary])) {
                    $outOfOrder = $part < $lastPart[$binary];
                    $gap = $part > $lastPart[$binary] + 1;
                }
                $lastPart[$binary] = $part;
                $final = $totalState === 1 && $total > 0 && $part === $total;
                $overflow = $totalState === 1 && $total > 0 && $part > $total;
                $outOfOrderCount += $outOfOrder ? 1 : 0;
                $gapCount += $gap ? 1 : 0;
                $bytesSum = min($bytesSum + $bytes, self::W66_TOTAL_CAP);
                $binaries[$binary] ??= [0, 0];
                if (! isset($seenParts[$key])) {
                    $seenParts[$key] = true;
                    $binaries[$binary][0]++;
                }
                if ($totalState === 1 && $total > $binaries[$binary][1]) {
                    $binaries[$binary][1] = $total;
                }
                if (\count($buffer) >= self::W66_PART_FLUSH_THRESHOLD) {
                    $flush = true;
                    $flushes++;
                    $buffer = $lastPart = [];
                }
            }
            $out[] = $error | $binaryState << 3 | $partState << 5 | $totalState << 7 | $bytesState << 9 | $action << 11 | ($outOfOrder ? 1 : 0) << 13
                | ($gap ? 1 : 0) << 14 | ($final ? 1 : 0) << 15 | ($overflow ? 1 : 0) << 16 | ($flush ? 1 : 0) << 17 | min(\count($buffer), 1023) << 18
                | min($part, 1_048_575) << 28 | min($bytes >> 10, 32_767) << 48;
        }
        $repair = $complete = 0;
        foreach ($binaries as [$parts, $total]) {
            if ($total > 0) {
                if ($parts < $total) {
                    $repair++;
                } else {
                    $complete++;
                }
            }
        }
        array_push($out, ...$errors);
        array_push($out, ...$actions);
        array_push($out, $outOfOrderCount, $gapCount, $flushes, \count($buffer), $bytesSum, \count($binaries), $repair, $complete);

        return $out;
    }

    /** Buffered distinct parts that trigger a Wave 66.3 plan flush. */
    public const int W66_PART_FLUSH_THRESHOLD = 100;

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w66Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * Wave 66 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('binaries_w663', self::W66_PART_HANDLER_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w66RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 66.3 PartHandler export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w66Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w66RustFfiState('ffi');
            $out = self::w66RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w66RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W66_PART_HANDLER_FFI_CDEF.)
            $written = $ffi->nntmux_w663_part_handler_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
