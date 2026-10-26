<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Durable reservations count retained files and in-flight growth across each host's workers. */
final class ScratchBudget
{
    // Single-call FFI remains opt-in until every paired mean/p99 gate reaches 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public function __construct(private readonly ?int $entryId = null) {}

    public function forEntry(int $entryId): self
    {
        return new self($entryId);
    }

    /** Bootstrap old physical scratch once, while every verifier and audit is excluded. */
    public function initialize(): bool
    {
        if (! Schema::hasTable('release_verification_scratch_budget')) {
            throw new RuntimeException('Shared scratch budget migration must run before verification workers.');
        }
        $host = ScratchInventory::host();
        $state = DB::table('release_verification_scratch_budget')->where('host', $host)->first();
        if ($state === null && $host !== '') {
            // A joining host creates its row in join(), under a worker slot and the deployment fence.
            return true;
        }
        if ($state === null) {
            throw new RuntimeException('Shared scratch budget state is unavailable.');
        }
        if ($state->initialized) {
            return true;
        }
        $exclusive = new VerificationWorkerLocks;
        if (! $exclusive->tryExclusive()) {
            return false;
        }
        try {
            $root = (string) config('release_verification.storage_path').'/scratch';
            $total = $this->directoryBytes($root);
            DB::transaction(function () use ($total, $root, $host): void {
                $state = DB::table('release_verification_scratch_budget')->where('host', $host)->lockForUpdate()->sole();
                if ($state->initialized) {
                    return;
                }
                foreach (DB::table('release_verification_scratch')->where('host', $host)->orderBy('id')->cursor() as $entry) {
                    $directory = $root.'/'.app(ScratchInventory::class)->directoryName($entry);
                    $bytes = is_link($directory) ? 0 : $this->directoryBytes($directory);
                    DB::table('release_verification_scratch')->where('id', $entry->id)->update(['reserved_bytes' => $bytes]);
                }
                DB::table('release_verification_scratch_budget')->where('host', $host)->update(['initialized' => true, 'reserved_bytes' => $total]);
            });

            return true;
        } finally {
            $exclusive->close();
        }
    }

    public function reserveOwned(int $bytes): void
    {
        if ($this->entryId === null) {
            throw new RuntimeException('Scratch reservation must be bound to its registered inventory.');
        }
        $this->reserve($this->entryId, $bytes);
    }

    public function reserve(int $entryId, int $bytes): void
    {
        if ($bytes < 0) {
            throw new RuntimeException('Scratch reservation cannot be negative.');
        }
        // Measured before any lock; counting retained bytes again in the free-space margin is
        // deliberately conservative after crashes.
        $free = disk_free_space((string) config('release_verification.storage_path'));
        if ($free === false) {
            throw new RuntimeException('Verification is waiting for shared scratch capacity.');
        }
        $limit = self::reservationCap((int) config('release_verification.scratch_bytes'), $free, (int) config('release_verification.free_bytes'));
        DB::transaction(function () use ($entryId, $bytes, $limit): void {
            // Lock the entry, then change the shared budget with one conditional delta as the last
            // lock request, so concurrent workers hold the budget row only until commit.
            $entry = DB::table('release_verification_scratch')->where('id', $entryId)->lockForUpdate()->sole();
            $delta = self::reservationDelta($bytes, (int) $entry->reserved_bytes);
            $changed = DB::table('release_verification_scratch_budget')->where('host', $entry->host)->where('initialized', true)
                ->whereRaw('reserved_bytes + ? <= ?', [$delta, $limit])
                ->update(['reserved_bytes' => DB::raw('reserved_bytes + ('.$delta.')')]);
            if ($changed !== 1) {
                $state = DB::table('release_verification_scratch_budget')->where('host', $entry->host)->first();
                if ($state === null || ! $state->initialized) {
                    throw new RuntimeException('Shared scratch budget is not initialized.');
                }
                // An unchanged total within the limit changes no row.
                if (self::rejectUnchanged($delta, (int) $state->reserved_bytes, $limit)) {
                    throw new RuntimeException('Verification is waiting for shared scratch capacity.');
                }
            }
            DB::table('release_verification_scratch')->where('id', $entryId)->update(['reserved_bytes' => $bytes]);
        }, 5);
    }

    /** A stale lease never authorizes reclaiming a reservation from a still-running process. */
    public function reconcile(int $entryId, VerificationWorkerLocks $locks, ?callable $heartbeat = null): void
    {
        $entry = DB::table('release_verification_scratch')->where('id', $entryId)->sole();
        if ($entry->candidate_id === null || ! $locks->protects((int) $entry->candidate_id)) {
            throw new RuntimeException('Scratch reconciliation requires the stable candidate lock.');
        }
        $bytes = $this->directoryBytes((string) config('release_verification.storage_path').'/scratch/'.app(ScratchInventory::class)->directoryName($entry), $heartbeat);
        DB::transaction(function () use ($entryId, $bytes): void {
            $entry = DB::table('release_verification_scratch')->where('id', $entryId)->lockForUpdate()->sole();
            $this->adjust((string) $entry->host, self::reservationDelta($bytes, (int) $entry->reserved_bytes));
            DB::table('release_verification_scratch')->where('id', $entryId)->update(['reserved_bytes' => $bytes]);
        }, 5);
    }

    /** Called only after indexed cleanup has proved the exact directory absent. */
    public function forget(int $entryId): void
    {
        DB::transaction(function () use ($entryId): void {
            $entry = DB::table('release_verification_scratch')->where('id', $entryId)->lockForUpdate()->first();
            if ($entry === null) {
                return;
            }
            $this->adjust((string) $entry->host, -(int) $entry->reserved_bytes);
            DB::table('release_verification_scratch')->where('id', $entryId)->delete();
        }, 5);
    }

    /**
     * Apply an unconditional delta to the entry host's total, floored at zero, as the transaction's
     * last lock request. Runtime paths lock their entry row first, so they cannot deadlock on the pair.
     */
    private function adjust(string $host, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        DB::table('release_verification_scratch_budget')->where('host', $host)->update(['reserved_bytes' => DB::raw(
            'CASE WHEN reserved_bytes + ('.$delta.') > 0 THEN reserved_bytes + ('.$delta.') ELSE 0 END')]);
    }

    /** Create this worker host's budget row while its worker slot is held. */
    public function join(VerificationWorkerLocks $worker): void
    {
        $host = ScratchInventory::host();
        if ($host === '' || DB::table('release_verification_scratch_budget')->where('host', $host)->exists()) {
            return;
        }
        if (! $worker->held()) {
            throw new RuntimeException('A worker host joins the scratch budget only while its worker slot is held.');
        }
        if ($this->createHostBudget($host) === null) {
            throw new RuntimeException('Shared scratch budget state is unavailable.');
        }
    }

    /**
     * A joining host owns no inventory yet, so its scratch needs no exclusive bootstrap; any
     * unregistered directories already there are counted conservatively.
     */
    private function createHostBudget(string $host): ?object
    {
        $owned = DB::table('release_verification_scratch')->where('host', $host)->exists();
        $values = ['host' => $host, 'initialized' => ! $owned,
            'reserved_bytes' => $owned ? 0 : $this->directoryBytes((string) config('release_verification.storage_path').'/scratch')];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = (int) DB::table('release_verification_scratch_budget')->max('id') + 1;
            DB::table('release_verification_scratch_budget')->insertOrIgnore($values + ['id' => $id]);
            $state = DB::table('release_verification_scratch_budget')->where('host', $host)->first();
            if ($state !== null) {
                return $state;
            }
        }

        return null;
    }

    private function directoryBytes(string $directory, ?callable $heartbeat = null): int
    {
        if (is_link($directory)) {
            throw new RuntimeException('Scratch budget cannot measure linked directories.');
        }
        if (! file_exists($directory)) {
            $parent = dirname($directory);
            if (is_dir($parent) && (! is_readable($parent) || ! is_executable($parent))) {
                throw new RuntimeException('Scratch budget cannot prove an inaccessible path absent.');
            }

            return 0;
        }
        if (! is_dir($directory) || ! is_readable($directory) || ! is_executable($directory)) {
            throw new RuntimeException('Scratch budget directory is inaccessible.');
        }
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            if ($entry->isLink()) {
                continue;
            }
            if ($entry->isFile()) {
                $bytes += $entry->getSize();
            }
        }

        return $bytes;
    }

    public static function reservationCap(int $scratchBytes, float $freeBytes, int $freeReserveBytes): int|float
    {
        return self::$useRustFfi ? (self::reservationCapNative($scratchBytes, $freeBytes, $freeReserveBytes) ?? self::reservationCapPhp($scratchBytes, $freeBytes, $freeReserveBytes)) : self::reservationCapPhp($scratchBytes, $freeBytes, $freeReserveBytes);
    }

    // @phpstan-ignore return.unusedType (PHP promotes integer overflow to float; exercised by parity tests.)
    public static function reservationCapPhp(int $scratchBytes, float $freeBytes, int $freeReserveBytes): int|float
    {
        return min($scratchBytes, (int) floor($freeBytes) - $freeReserveBytes);
    }

    public static function reservationCapNative(int $scratchBytes, float $freeBytes, int $freeReserveBytes): int|float|null
    {
        $result = LeaseHeartbeat::computeNative(4, [$scratchBytes, $freeBytes, $freeReserveBytes])['value'] ?? null;

        return is_int($result) || is_float($result) ? $result : null;
    }

    public static function reservationDelta(int $bytes, int $reservedBytes): int|float
    {
        return self::$useRustFfi ? (self::reservationDeltaNative($bytes, $reservedBytes) ?? self::reservationDeltaPhp($bytes, $reservedBytes)) : self::reservationDeltaPhp($bytes, $reservedBytes);
    }

    // @phpstan-ignore return.unusedType (PHP promotes integer overflow to float; exercised by parity tests.)
    public static function reservationDeltaPhp(int $bytes, int $reservedBytes): int|float
    {
        return $bytes - $reservedBytes;
    }

    public static function reservationDeltaNative(int $bytes, int $reservedBytes): int|float|null
    {
        $result = LeaseHeartbeat::computeNative(5, [$bytes, $reservedBytes])['value'] ?? null;

        return is_int($result) || is_float($result) ? $result : null;
    }

    public static function adjustedBudget(int $reservedBytes, int $delta): int|float
    {
        return self::$useRustFfi ? (self::adjustedBudgetNative($reservedBytes, $delta) ?? self::adjustedBudgetPhp($reservedBytes, $delta)) : self::adjustedBudgetPhp($reservedBytes, $delta);
    }

    // @phpstan-ignore return.unusedType (PHP promotes integer overflow to float; exercised by parity tests.)
    public static function adjustedBudgetPhp(int $reservedBytes, int $delta): int|float
    {
        return max(0, $reservedBytes + $delta);
    }

    public static function adjustedBudgetNative(int $reservedBytes, int $delta): int|float|null
    {
        $result = LeaseHeartbeat::computeNative(6, [$reservedBytes, $delta])['value'] ?? null;

        return is_int($result) || is_float($result) ? $result : null;
    }

    public static function rejectUnchanged(int|float $delta, int $reservedBytes, int|float $limit): bool
    {
        return self::$useRustFfi ? (self::rejectUnchangedNative($delta, $reservedBytes, $limit) ?? self::rejectUnchangedPhp($delta, $reservedBytes, $limit)) : self::rejectUnchangedPhp($delta, $reservedBytes, $limit);
    }

    public static function rejectUnchangedPhp(int|float $delta, int $reservedBytes, int|float $limit): bool
    {
        return $delta !== 0 || $reservedBytes > $limit;
    }

    public static function rejectUnchangedNative(int|float $delta, int $reservedBytes, int|float $limit): ?bool
    {
        $result = LeaseHeartbeat::computeNative(7, [$delta, $reservedBytes, $limit])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    public const string W58_FFI_CDEF = 'int64_t nntmux_w585_budget_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W58.5 parity test's QUALIFICATION). */
    private const bool W58_RUST_FFI_DEFAULT = true;

    /**
     * Wave 58.5 routing and FFI cache, kept in function-local statics (readonly verification classes cannot declare
     * static properties, so every W58 class follows the same shape). Without $op it reports the routing; 'set' swaps
     * the override for $val and returns the previous one; 'reset' replaces the cached handle with $val (null
     * re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]` with room for at least $val words,
     * or null without the library.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
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
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w58_5', self::W58_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W58_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        return self::w58RustFfiState() === true;
    }

    /**
     * Runs $callback with budgetPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 58.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w58Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w58RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w585_budget_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch admission plan over six comma-separated, trimmed fields (`scratch_bytes, free_bytes, free_reserve,
     * reserved_total, entry_reserved, request_bytes`; empty reads 0). Five words per record: `[cap, delta, admitted,
     * after, flags]`. Cap is the reservation cap, delta the reservation delta; admitted is 1 when the host total plus
     * delta fits the cap, and after is the admitted total floored at zero or the unchanged total. Flags: 1 field that
     * is not 1-18 digits (read as 0), 2 not exactly six fields, 4 negative cap (free space below its reserve), 8
     * reclaiming delta.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function budgetPlanBatch(array $records): array
    {
        return self::usesW58RustFfi() ? self::budgetPlanBatchNative($records) : self::budgetPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function budgetPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode(',', $record);
            $flags = \count($fields) === 6 ? 0 : 2;
            $values = [0, 0, 0, 0, 0, 0];
            for ($i = 0; $i < 6; $i++) {
                $field = trim($fields[$i] ?? '');
                if ($field === '') {
                    continue;
                }
                if (\strlen($field) <= 18 && ctype_digit($field)) {
                    $values[$i] = (int) $field;
                } else {
                    $flags |= 1;
                }
            }
            [$scratch, $free, $reserve, $reserved, $entry, $request] = $values;
            $cap = min($scratch, $free - $reserve);
            if ($cap < 0) {
                $flags |= 4;
            }
            $delta = $request - $entry;
            if ($delta < 0) {
                $flags |= 8;
            }
            $admitted = $reserved + $delta <= $cap;
            array_push($out, $cap, $delta, (int) $admitted, $admitted ? max(0, $reserved + $delta) : $reserved, $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function budgetPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w58Words($count, implode("\0", $records), 5 * $count) ?? self::budgetPlanBatchPhp($records);
    }
}
