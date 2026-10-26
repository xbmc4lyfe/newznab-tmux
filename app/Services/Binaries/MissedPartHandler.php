<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Models\MissedPart;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

        return self::packedInts(fn (FFI $ffi, CData $out, int $cap): int => $ffi->nntmux_missed_range_expand($first, $last, self::NATIVE_MAX_RANGE_ITEMS, $out, $cap), ($span + 1) * 8); // @phpstan-ignore method.notFound
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
        $flat = self::packedInts(fn (FFI $ffi, CData $out, int $cap): int => $ffi->nntmux_missed_range_chunks($first, $last, $chunkSize, self::NATIVE_MAX_RANGE_ITEMS, $out, $cap), $count * 16); // @phpstan-ignore method.notFound
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
     * @param  \Closure(FFI, CData, int): int  $call
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

    /** Wave 52.2 paired benchmarks decide this default; see RustBinariesCollectionHeaderMissedPartHandlerW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        intptr_t nntmux_w522_missed_part_plan_batch(const char *ints, size_t ints_len, size_t count, const char *received, size_t received_len, size_t received_count, int64_t max_tries, int64_t *out, size_t out_cap);
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
     * Runs $callback with planRepairBatch() routed to Rust ($enabled) or PHP, then restores the
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
     * Batch part repair plan over queued rows (getMissingParts() order) and the article numbers a
     * repair pass received. One flag byte per row, eight rows per word (row `i` in bits `8 * (i %
     * 8)`). A number <= 0 is invalid (16), a repeated number a duplicate (8); otherwise a received
     * number is repaired (1), a part whose next attempt reaches max(1, $maxTries) exhausted (4) and
     * the rest retryable (2), with 32 marking a retryable part that starts a run of consecutive
     * numbers. Repaired, retryable, exhausted,
     * duplicate, invalid and run counts, the smallest and largest retryable numbers (0 for none)
     * and the XXH64 digest of all earlier words follow.
     *
     * @param  list<int>  $numbers
     * @param  list<int>  $attempts
     * @param  list<int>  $received
     * @return list<int>
     */
    public static function planRepairBatch(array $numbers, array $attempts, array $received, int $maxTries = 3): array
    {
        return self::usesW52RustFfi()
            ? self::planRepairBatchNative($numbers, $attempts, $received, $maxTries)
            : self::planRepairBatchPhp($numbers, $attempts, $received, $maxTries);
    }

    /**
     * @param  list<int>  $numbers
     * @param  list<int>  $attempts
     * @param  list<int>  $received
     * @return list<int>
     */
    public static function planRepairBatchPhp(array $numbers, array $attempts, array $received, int $maxTries = 3): array
    {
        $out = $seen = [];
        $got = array_flip($received);
        $limit = max(1, $maxTries) - 1;
        $repaired = $retryable = $exhausted = $duplicates = $invalid = $runs = $min = $max = 0;
        $prev = null;
        $packed = $shift = 0;
        foreach ($numbers as $i => $number) {
            if ($number <= 0) {
                $invalid++;
                $flags = 16;
            } elseif (isset($seen[$number])) {
                $duplicates++;
                $flags = 8;
            } else {
                $seen[$number] = true;
                if (isset($got[$number])) {
                    $repaired++;
                    $flags = 1;
                } elseif (($attempts[$i] ?? 0) >= $limit) {
                    $exhausted++;
                    $flags = 4;
                } else {
                    $retryable++;
                    $flags = 2;
                    if ($prev === null || $prev === PHP_INT_MAX || $prev + 1 !== $number) {
                        $flags |= 32;
                        $runs++;
                    }
                    $prev = $number;
                    if ($min === 0 || $number < $min) {
                        $min = $number;
                    }
                    $max = max($max, $number);
                }
            }
            $packed |= $flags << $shift;
            $shift += 8;
            if ($shift === 64) {
                $out[] = $packed;
                $packed = $shift = 0;
            }
        }
        if ($shift > 0) {
            $out[] = $packed;
        }
        $out[] = $repaired;
        $out[] = $retryable;
        $out[] = $exhausted;
        $out[] = $duplicates;
        $out[] = $invalid;
        $out[] = $runs;
        $out[] = $min;
        $out[] = $max;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or the columns differ in length
     * or are not lists.
     *
     * @param  list<int>  $numbers
     * @param  list<int>  $attempts
     * @param  list<int>  $received
     * @return list<int>
     */
    public static function planRepairBatchNative(array $numbers, array $attempts, array $received, int $maxTries = 3): array
    {
        $count = \count($numbers);
        $words = intdiv($count + 7, 8) + 9;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w52RustFfiState('out', $words);
        if ($state === null || \count($attempts) !== $count || ! array_is_list($numbers) || ! array_is_list($attempts) || ! array_is_list($received)) {
            return self::planRepairBatchPhp($numbers, $attempts, $received, $maxTries);
        }
        [$ffi, $out, $cap] = $state;
        $ints = pack('P*', ...$numbers, ...$attempts);
        $got = pack('P*', ...$received);
        $receivedCount = \count($received);
        try {
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w522_missed_part_plan_batch($ints, \strlen($ints), $count, $got, \strlen($got), $receivedCount, $maxTries, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planRepairBatchPhp($numbers, $attempts, $received, $maxTries);
    }

    // ---- Wave 66.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 11.99x/10.64x, uncommon 11.29x/9.11x); see RustBinariesCollectionHeaderParserStorageTxMissedPartW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    private const string W66_MISSED_PART_HANDLER_FFI_CDEF = <<<'C'
        intptr_t nntmux_w662_missed_part_handler_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W66_MISSED_PART_HANDLER_TAIL = 15;

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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w66', self::W66_MISSED_PART_HANDLER_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w66RustFfiState('ffi');
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

    /** Routes w66MissedPartHandlerPlanBatch() to Rust until reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Routes w66MissedPartHandlerPlanBatch() to PHP until reset. */
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
     * Runs $callback with w66MissedPartHandlerPlanBatch() routed to Rust ($enabled) or PHP, then restores the
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
    public static function w66MissedPartHandlerPlanBatch(array $rows): array
    {
        return self::usesW66RustFfi() ? self::w66MissedPartHandlerPlanBatchNative($rows) : self::w66MissedPartHandlerPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66MissedPartHandlerPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W66_MISSED_PART_HANDLER_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w66RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w66MissedPartHandlerPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W66_MISSED_PART_HANDLER_FFI_CDEF.)
            $written = $ffi->nntmux_w662_missed_part_handler_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w66MissedPartHandlerPlanBatchPhp($rows);
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

    private const int W66_MISSED_MAX_TRIES = 3;

    /** Retries one batch may schedule (the default part repair limit) and rows per repair batch. */
    private const int W66_MISSED_REPAIR_LIMIT = 15000;

    private const int W66_MISSED_REPAIR_BATCH = 100;

    /** 0 empty or no/0/false, 1 yes/1/true, 2 other (trimmed, case-insensitive). */
    private static function w66FlagState(string $value): int
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'no' || $value === '0' || $value === 'false') {
            return 0;
        }

        return $value === 'yes' || $value === '1' || $value === 'true' ? 1 : 2;
    }

    /**
     * Wave 66.2 missed part repair plan. Per `number\tattempts\treceived` row `action |
     * min(next_attempts, 255) << 3 | final_attempt << 11 | range_start << 12 |
     * min(range, 2^20 - 1) << 13 | min(repair_batch, 1023) << 33 | min(gap, 65535) << 43 |
     * out_of_order << 59`. Number and attempts are w66Int() fields of 12 and 3 digits, received a
     * w66FlagState(). No positive number, unparsable attempts or an unknown received flag is
     * malformed (0); a repeated number is a duplicate (4). Every other row extends the current
     * article range when it follows the previous one by one, else starts a range (after a gap,
     * or out of order). A received part is repaired (1); one with W66_MISSED_MAX_TRIES attempts is
     * pruned (3); otherwise it retries (2) with one more attempt (final when that reaches the
     * limit) in repair batch `retries / W66_MISSED_REPAIR_BATCH`, until W66_MISSED_REPAIR_LIMIT
     * retries defer the rest (5). Then six action counts, ranges, the longest range, the lowest
     * and highest number, repair batches, final attempts, out-of-order rows, total attempts and the
     * XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w66MissedPartHandlerPlanBatchPhp(array $rows): array
    {
        $out = $seen = [];
        $actions = array_fill(0, 6, 0);
        $ranges = $longest = $current = $prev = $minNum = $maxNum = $retries = $exhausted = $oooRows = $totalAttempts = 0;
        foreach ($rows as $row) {
            [$number, $attempts, $received] = explode("\t", (string) $row, 3) + ['', '', ''];
            $number = self::w66Int($number, 12);
            $n = \is_int($number) && $number > 0 ? $number : 0;
            $attempts = self::w66Int($attempts, 3);
            $recv = self::w66FlagState($received);
            $malformed = $n === 0 || $attempts === false || $recv === 2;
            $a = \is_int($attempts) ? $attempts : 0;
            $dup = false;
            if (! $malformed) {
                $dup = isset($seen[$n]);
                $seen[$n] = true;
            }
            $start = $ooo = false;
            $gap = $rangeOrd = $next = $batch = 0;
            if (! $malformed && ! $dup) {
                if ($ranges === 0) {
                    [$start, $ranges, $current, $minNum, $maxNum] = [true, 1, 1, $n, $n];
                } elseif ($n === $prev + 1) {
                    $current++;
                } else {
                    $start = true;
                    $ranges++;
                    $current = 1;
                    if ($n > $prev) {
                        $gap = $n - $prev - 1;
                    } else {
                        $ooo = true;
                    }
                }
                $minNum = min($minNum, $n);
                $maxNum = max($maxNum, $n);
                $longest = max($longest, $current);
                $prev = $n;
                $rangeOrd = $ranges - 1;
                $totalAttempts += $a;
            }
            $last = false;
            if ($malformed) {
                $action = 0;
            } elseif ($dup) {
                $action = 4;
            } elseif ($recv === 1) {
                $action = 1;
            } elseif ($a >= self::W66_MISSED_MAX_TRIES) {
                $action = 3;
            } elseif ($retries < self::W66_MISSED_REPAIR_LIMIT) {
                $next = $a + 1;
                $last = $next >= self::W66_MISSED_MAX_TRIES;
                $batch = intdiv($retries, self::W66_MISSED_REPAIR_BATCH);
                $retries++;
                $action = 2;
            } else {
                $action = 5;
            }
            $actions[$action]++;
            $exhausted += (int) $last;
            $oooRows += (int) $ooo;
            $out[] = $action | min($next, 255) << 3 | (int) $last << 11 | (int) $start << 12 | min($rangeOrd, 0xFFFFF) << 13 | min($batch, 0x3FF) << 33 | min($gap, 0xFFFF) << 43
                | (int) $ooo << 59;
        }
        array_push($out, ...$actions);
        array_push($out, $ranges, $longest, $minNum, $maxNum, intdiv($retries + self::W66_MISSED_REPAIR_BATCH - 1, self::W66_MISSED_REPAIR_BATCH), $exhausted, $oooRows,
            $totalAttempts);
        $out[] = self::w66Digest($out);

        return $out;
    }
}
