<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Models\MissedPart;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles missed parts tracking and repair during header processing.
 */
final class MissedPartHandler
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_missed_range_expand(int64_t first, int64_t last, size_t max_items, char *out, size_t cap);
        ssize_t nntmux_missed_range_chunks(int64_t first, int64_t last, int64_t chunk, size_t max_chunks, char *out, size_t cap);
        C;

    /** Larger ranges stay on the PHP path rather than allocating an equally large FFI buffer. */
    private const int NATIVE_MAX_RANGE_ITEMS = 1000000;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private int $partRepairLimit;

    private int $partRepairMaxTries;

    private int $chunkSize;

    public function __construct(int $partRepairLimit = 15000, int $partRepairMaxTries = 3, int $chunkSize = 500)
    {
        $this->partRepairLimit = $partRepairLimit;
        $this->partRepairMaxTries = $partRepairMaxTries;
        $this->chunkSize = max(50, min(1000, $chunkSize));
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
     * Every article number in an inclusive range; empty when the bounds are reversed.
     *
     * @return list<int>
     */
    public static function expandRange(int $first, int $last): array
    {
        return (self::$useRustFfi ? self::expandRangeNative($first, $last) : null) ?? self::expandRangePhp($first, $last);
    }

    /**
     * @return list<int>
     */
    public static function expandRangePhp(int $first, int $last): array
    {
        return $last < $first ? [] : range($first, $last);
    }

    /**
     * @return list<int>|null Null when Rust is unavailable or the range is too large.
     */
    public static function expandRangeNative(int $first, int $last): ?array
    {
        if ($last < $first) {
            return [];
        }
        if (self::spanOverflows($first, $last)) {
            return null;
        }
        $span = $last - $first;
        if ($span >= self::NATIVE_MAX_RANGE_ITEMS) {
            return null;
        }

        return self::packedInts(fn (FFI $ffi, FFI\CData $out, int $cap): int => $ffi->nntmux_missed_range_expand($first, $last, self::NATIVE_MAX_RANGE_ITEMS, $out, $cap), ($span + 1) * 8); // @phpstan-ignore method.notFound
    }

    /**
     * Inclusive [start, end] bounds of each statement that covers a range in chunks.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function rangeChunks(int $first, int $last, int $chunkSize): array
    {
        return (self::$useRustFfi ? self::rangeChunksNative($first, $last, $chunkSize) : null) ?? self::rangeChunksPhp($first, $last, $chunkSize);
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function rangeChunksPhp(int $first, int $last, int $chunkSize): array
    {
        if ($last < $first) {
            return [];
        }
        $chunkSize = max(1, $chunkSize);
        $chunks = [];
        $start = $first;
        while (true) {
            // Stepping from the end keeps PHP_INT_MAX bounds from overflowing.
            $end = $last - $start < $chunkSize ? $last : $start + $chunkSize - 1;
            $chunks[] = [$start, $end];
            if ($end === $last) {
                return $chunks;
            }
            $start = $end + 1;
        }
    }

    /**
     * @return list<array{0: int, 1: int}>|null
     */
    public static function rangeChunksNative(int $first, int $last, int $chunkSize): ?array
    {
        if ($last < $first) {
            return [];
        }
        $chunkSize = max(1, $chunkSize);
        if (self::spanOverflows($first, $last)) {
            return null;
        }
        $span = $last - $first;
        if (intdiv($span, $chunkSize) >= self::NATIVE_MAX_RANGE_ITEMS) {
            return null;
        }
        $count = intdiv($span, $chunkSize) + 1;
        $flat = self::packedInts(fn (FFI $ffi, FFI\CData $out, int $cap): int => $ffi->nntmux_missed_range_chunks($first, $last, $chunkSize, self::NATIVE_MAX_RANGE_ITEMS, $out, $cap), $count * 16); // @phpstan-ignore method.notFound
        if ($flat === null || \count($flat) % 2 !== 0) {
            return null;
        }

        /** @var list<array{0: int, 1: int}> */
        return array_chunk($flat, 2);
    }

    /** Whether `$last - $first` exceeds PHP_INT_MAX for ordered bounds. */
    private static function spanOverflows(int $first, int $last): bool
    {
        return $first < 0 && $last > PHP_INT_MAX + $first;
    }

    /**
     * @param  \Closure(FFI, FFI\CData, int): int  $call
     * @return list<int>|null
     */
    private static function packedInts(\Closure $call, int $bytes): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(16, $bytes), $cap);
            if ($scratch === null) {
                return null;
            }
            $written = $call($ffi, $scratch, $cap);
            if ($written < 0 || $written % 8 !== 0) {
                return null;
            }
            if ($written === 0) {
                return [];
            }
            $values = unpack('q*', FFI::string($scratch, $written));

            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Add missing article numbers to the repair queue.
     *
     * @param  array<int, int|string>  $numbers
     */
    public function addMissingParts(array $numbers, int $groupId): void
    {
        if (empty($numbers)) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->addMissingPartsSqlite($numbers, $groupId);

            return;
        }

        $this->addMissingPartsMysql($numbers, $groupId);
    }

    /**
     * Queue every article number in an inclusive range for part repair.
     *
     * Used when a worker could not fetch its range at all (for example the provider refused its login):
     * sibling ranges move the group's cursor past it, so without this the headers would never be fetched.
     */
    public function addMissingRange(int $first, int $last, int $groupId): void
    {
        if ($last < $first) {
            return;
        }

        $this->addMissingParts(self::expandRange($first, $last), $groupId);
    }

    /** Preserve never-attempted work without consuming retries on an existing obligation. */
    public function deferRange(int $first, int $last, int $groupId): void
    {
        if ($last < $first) {
            return;
        }
        $conflict = DB::getDriverName() === 'sqlite'
            ? ' ON CONFLICT(numberid, groups_id) DO NOTHING'
            : ' ON DUPLICATE KEY UPDATE numberid = VALUES(numberid)';
        foreach (self::rangeChunks($first, $last, $this->chunkSize) as [$start, $end]) {
            $rows = [];
            $bindings = [];
            for ($number = $start; ; $number++) {
                $rows[] = '(?, ?, 0)';
                array_push($bindings, $number, $groupId);
                if ($number === $end) {
                    break;
                }
            }
            DB::statement('INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES '.implode(',', $rows).$conflict, $bindings);
        }
    }

    /**
     * @param  array<int, int|string>  $numbers
     */
    private function addMissingPartsSqlite(array $numbers, int $groupId): void
    {
        foreach (array_chunk(array_unique($numbers), $this->chunkSize) as $chunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $number) {
                $placeholders[] = '(?, ?, 1)';
                $bindings[] = $number;
                $bindings[] = $groupId;
            }

            DB::statement(
                'INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES '.implode(',', $placeholders).' ON CONFLICT(numberid, groups_id) DO UPDATE SET attempts = attempts + 1',
                $bindings
            );
        }
    }

    /**
     * @param  array<int, int|string>  $numbers
     */
    private function addMissingPartsMysql(array $numbers, int $groupId): void
    {
        foreach (array_chunk(array_unique($numbers), $this->chunkSize) as $chunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $number) {
                $placeholders[] = '(?, ?, 1)';
                $bindings[] = $number;
                $bindings[] = $groupId;
            }

            DB::insert(
                'INSERT INTO missed_parts (numberid, groups_id, attempts) VALUES '.implode(',', $placeholders).' ON DUPLICATE KEY UPDATE attempts = attempts + 1',
                $bindings
            );
        }
    }

    /**
     * Remove successfully repaired parts from the queue.
     *
     * @param  array<int, int|string>  $numbers
     */
    public function removeRepairedParts(array $numbers, int $groupId): void
    {
        if (empty($numbers)) {
            return;
        }

        try {
            // Single DELETE — InnoDB autocommits one statement atomically, so
            // an explicit transaction here would just add round-trips.
            DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->whereIn('numberid', $numbers)
                ->delete();
        } catch (\Throwable $e) {
            if (config('app.debug') === true) {
                Log::warning('removeRepairedParts failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Get parts that need repair for a group.
     *
     * @return array<int, \stdClass> Array of missed parts
     */
    public function getMissingParts(int $groupId): array
    {
        try {
            return DB::table('missed_parts')
                ->where('groups_id', $groupId)
                ->where('attempts', '<', $this->partRepairMaxTries)
                ->orderBy('numberid')
                ->limit($this->partRepairLimit)
                ->get()
                ->all();
        } catch (\PDOException $e) {
            if ($e->getMessage() === 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction') {
                Log::notice('Deadlock occurred while fetching missed parts');
            }

            return [];
        }
    }

    /**
     * Increment attempts for parts that weren't repaired.
     */
    public function incrementAttempts(int $groupId, int $maxNumberId): void
    {
        DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('numberid', '<=', $maxNumberId)
            ->increment('attempts');
    }

    /**
     * Increment attempts for specific article range (part repair NNTP failures).
     */
    public function incrementRangeAttempts(int $groupId, int $first, int $last): void
    {
        if ($first === $last) {
            MissedPart::query()
                ->where('groups_id', $groupId)
                ->where('numberid', $first)
                ->increment('attempts');
        } else {
            MissedPart::query()
                ->where('groups_id', $groupId)
                ->whereIn('numberid', range($first, $last))
                ->increment('attempts');
        }
    }

    /**
     * Get count of remaining missed parts.
     */
    public function getCount(int $groupId, int $maxNumberId): int
    {
        return DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('numberid', '<=', $maxNumberId)
            ->count('id');
    }

    /**
     * Remove parts that exceeded max tries.
     */
    public function cleanupExhaustedParts(int $groupId): void
    {
        // Single DELETE — InnoDB autocommits atomically; the explicit
        // transaction wrapper would just add round-trips.
        DB::table('missed_parts')
            ->where('groups_id', $groupId)
            ->where('attempts', '>=', $this->partRepairMaxTries)
            ->delete();
    }

    /**
     * PHP stays the default for summarizeMissedPartBatch(): the paired benchmark in
     * tests/Unit/RustBinariesTransactionMissedPartBackfillW42ParityTest.php does not clear 1.5x.
     */
    public const bool W421_RUST_FFI_DEFAULT = false;

    private const array W421_MISSED_KEYS = ['parts', 'invalid_rows', 'bad_number', 'bad_group', 'bad_attempts', 'exhausted', 'retryable', 'duplicates',
        'min_number', 'max_number'];

    private static ?bool $w421UseRustFfi = null;

    private static FFI|false|null $w421Ffi = null;

    /**
     * Summarizes part repair queue rows (`numberid`, `groups_id`, `attempts`): an article number or group id that is not
     * a positive int (or 1-18 digit string) is bad, attempts that are not a non-negative int are bad, attempts at or
     * above `$maxTries` are exhausted and the rest retryable. Rows repeating a valid (group, number) pair are
     * duplicates, and the smallest and largest valid numbers are reported (0 when none). Non-array rows are invalid.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeMissedPartBatch(array $rows, int $maxTries = 3): array
    {
        return (self::usesW421RustFfi() ? self::summarizeMissedPartBatchNative($rows, $maxTries) : null)
            ?? self::summarizeMissedPartBatchPhp($rows, $maxTries);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeMissedPartBatchPhp(array $rows, int $maxTries = 3): array
    {
        $summary = array_fill_keys(self::W421_MISSED_KEYS, 0);
        $summary['parts'] = \count($rows);
        $seen = [];
        $any = false;
        foreach ($rows as $row) {
            if (! \is_array($row)) {
                $summary['invalid_rows']++;

                continue;
            }
            $number = BinariesService::w411Int($row['numberid'] ?? null);
            $number = $number !== null && $number > 0 ? $number : null;
            $group = BinariesService::w411Int($row['groups_id'] ?? null);
            $group = $group !== null && $group > 0 ? $group : null;
            $attempts = BinariesService::w411Int($row['attempts'] ?? null);
            $summary['bad_number'] += (int) ($number === null);
            $summary['bad_group'] += (int) ($group === null);
            if ($attempts === null || $attempts < 0) {
                $summary['bad_attempts']++;
            } elseif ($attempts >= $maxTries) {
                $summary['exhausted']++;
            } else {
                $summary['retryable']++;
            }
            if ($number === null) {
                continue;
            }
            if ($any) {
                $summary['min_number'] = min($summary['min_number'], $number);
                $summary['max_number'] = max($summary['max_number'], $number);
            } else {
                [$summary['min_number'], $summary['max_number'], $any] = [$number, $number, true];
            }
            if ($group !== null) {
                $key = $group.':'.$number;
                $summary['duplicates'] += (int) isset($seen[$key]);
                $seen[$key] = true;
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>|null
     */
    public static function summarizeMissedPartBatchNative(array $rows, int $maxTries = 3): ?array
    {
        $words = [$maxTries, \count($rows)];
        $blob = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                BinariesService::w411PackRow($words, $blob, [$row['numberid'] ?? null, $row['groups_id'] ?? null, $row['attempts'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = HeaderStorageTransaction::w421Invoke(self::$w421Ffi, 2, $words, $blob);

        return $values !== null && \count($values) === \count(self::W421_MISSED_KEYS) ? array_combine(self::W421_MISSED_KEYS, $values) : null;
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
