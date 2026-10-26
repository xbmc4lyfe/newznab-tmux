<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Stable locks outlive database leases and manifest generations. */
final class VerificationWorkerLocks
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_verification_forwarder_lock_name(const char *host, size_t host_len, int64_t number, char *out, size_t cap);
        int64_t nntmux_verification_slots(int64_t configured);
        int64_t nntmux_verification_first_slot(int64_t slots, int64_t offset);
        int64_t nntmux_verification_last_slot(int64_t first_slot, int64_t slots);
        int nntmux_verification_protects(int exclusive, int global_present, int candidate_present, int held);
        C;

    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF) !== null;
    }

    private static function nativeInteger(string $function, int ...$arguments): ?int
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi !== null) {
                $result = $ffi->{$function}(...$arguments);

                return is_int($result) ? $result : null;
            }
        } catch (Throwable) {
            // The explicit native candidate retains the PHP result when the ABI is unavailable.
        }

        return null;
    }

    /** Ceiling on scheduled candidate worker slots per host, whatever the configured count. */
    public const int MAX_SLOTS = 64;

    /** Highest slot number across every worker host; each host schedules its own range. */
    public const int MAX_SLOT_NUMBER = 256;

    private ?VerificationLockStore $store;

    private ?VerificationLock $global = null;

    private ?VerificationLock $slot = null;

    /** @var array<int, VerificationLock> */
    private array $candidates = [];

    private ?VerificationLock $gc = null;

    private ?VerificationLock $expiry = null;

    private bool $exclusive = false;

    public function __construct(?VerificationLockStore $store = null)
    {
        $this->store = $store;
    }

    public function start(int $slot = 0): bool
    {
        $this->close();
        $this->global = $this->store()->shared('worker');
        if ($this->global === null || self::paused($this->store()->connection())) {
            $this->close();

            return false;
        }
        foreach ($slot > 0 ? [$slot] : range(self::firstSlot(), self::lastSlot()) as $number) {
            if ($number < self::firstSlot() || $number > self::lastSlot()) {
                break;
            }
            $this->slot = $this->store()->exclusive('worker-'.$number);
            if ($this->slot !== null) {
                return true;
            }
        }
        $this->close();

        return false;
    }

    /**
     * A publication forwarder holds one exclusive lock per forwarder number and host for its whole
     * run; that lock fences nothing but a second copy of itself. It joins the shared worker group
     * only while it writes (see {@see joinWorkers()}), so an idle forwarder never delays a barrier.
     */
    public function startForwarder(int $number): bool
    {
        $this->close();
        $this->slot = $this->store()->exclusive(self::forwarderLockName($number));
        if ($this->slot !== null) {
            return true;
        }
        $this->close();

        return false;
    }

    private static function forwarderLockName(int $number): string
    {
        return self::$useRustFfi ? self::forwarderLockNameNative($number) : self::forwarderLockNamePhp($number);
    }

    public static function forwarderLockNameNative(int $number): string
    {
        $host = ScratchInventory::host();
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi !== null) {
                $capacity = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
                if ($scratch !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_verification_forwarder_lock_name($host, strlen($host), $number, $scratch, $capacity);
                    if (is_int($written) && $written >= 0 && $written <= $capacity) {
                        return $written === 0 ? '' : FFI::string($scratch, $written);
                    }
                }
            }
        } catch (Throwable) {
            // Compute the same host name below without repeating the host lookup.
        }

        return 'publication-forwarder-'.substr(sha1($host), 0, 12).'-'.$number;
    }

    public static function forwarderLockNamePhp(int $number): string
    {
        return 'publication-forwarder-'.substr(sha1(ScratchInventory::host()), 0, 12).'-'.$number;
    }

    /**
     * A running forwarder becomes a writer like a worker: it joins the shared worker group, which a
     * drain, an exclusive holder or a durable pause refuses. Join before writing, leave when done.
     */
    public function joinWorkers(): bool
    {
        if ($this->slot === null) {
            throw new RuntimeException('Joining the worker group requires a forwarder lock.');
        }
        if ($this->global !== null) {
            return true;
        }
        $this->global = $this->store()->shared('worker');
        if ($this->global !== null && self::paused($this->store()->connection())) {
            $this->leaveWorkers();
        }

        return $this->global !== null;
    }

    public function leaveWorkers(): void
    {
        $this->release($this->global);
    }

    /** Whether the forwarder still holds its own lock; a database lock ends with its session. */
    public function forwarderHeld(): bool
    {
        return $this->slot !== null && $this->store()->holds($this->slot);
    }

    /**
     * Run $callback while holding forwarder number $number's lock, so no forwarder of that number is
     * running. False when another process holds it.
     *
     * @param  callable(): void  $callback
     */
    public function withForwarderNumber(int $number, callable $callback): bool
    {
        $lock = $this->store()->exclusive(self::forwarderLockName($number));
        if ($lock === null) {
            return false;
        }
        try {
            $callback();
        } finally {
            $this->store()->release($lock);
        }

        return true;
    }

    /** Configured candidate worker slots on this host, from 1 to MAX_SLOTS. */
    public static function slots(): int
    {
        return self::$useRustFfi ? self::slotsNative() : self::slotsPhp();
    }

    public static function slotsNative(): int
    {
        $configured = (int) config('release_verification.candidate_workers', 2);

        return self::nativeInteger('nntmux_verification_slots', $configured)
            ?? max(1, min(self::MAX_SLOTS, $configured));
    }

    public static function slotsPhp(): int
    {
        return max(1, min(self::MAX_SLOTS, (int) config('release_verification.candidate_workers', 2)));
    }

    /** This host's first slot number; hosts sharing a database configure disjoint ranges. */
    public static function firstSlot(): int
    {
        return self::$useRustFfi ? self::firstSlotNative() : self::firstSlotPhp();
    }

    public static function firstSlotNative(): int
    {
        $slots = self::slotsNative();
        $offset = (int) config('release_verification.slot_offset', 0);

        return self::nativeInteger('nntmux_verification_first_slot', $slots, $offset)
            ?? max(0, min(self::MAX_SLOT_NUMBER - $slots, $offset)) + 1;
    }

    public static function firstSlotPhp(): int
    {
        return max(0, min(self::MAX_SLOT_NUMBER - self::slotsPhp(), (int) config('release_verification.slot_offset', 0))) + 1;
    }

    public static function lastSlot(): int
    {
        return self::$useRustFfi ? self::lastSlotNative() : self::lastSlotPhp();
    }

    public static function lastSlotNative(): int
    {
        $first = self::firstSlotNative();
        $slots = self::slotsNative();

        return self::nativeInteger('nntmux_verification_last_slot', $first, $slots) ?? $first + $slots - 1;
    }

    public static function lastSlotPhp(): int
    {
        return self::firstSlotPhp() + self::slotsPhp() - 1;
    }

    public function startPreparation(): bool
    {
        $this->close();
        $this->global = $this->store()->shared('worker');
        if ($this->global !== null && ! self::paused($this->store()->connection())) {
            $this->slot = $this->store()->exclusive('preparation');
            if ($this->slot !== null) {
                return true;
            }
        }
        $this->close();

        return false;
    }

    /** Persist claim-selection fairness in the already exclusively held worker slot. */
    public function preferReady(): bool
    {
        return $this->preferPrimaryQueue(true);
    }

    public function preferPending(bool $advance): bool
    {
        return $this->preferPrimaryQueue($advance);
    }

    private function preferPrimaryQueue(bool $advance): bool
    {
        if ($this->slot === null || $this->global === null) {
            throw new RuntimeException('Queue selection requires an active slot.');
        }
        $state = $this->slot->state();
        rewind($state);
        $stored = stream_get_contents($state, 16);
        $phase = is_string($stored) && preg_match('/\A[0-4]\z/D', $stored) === 1 ? (int) $stored : 0;
        $next = ($phase + 1) % 5;
        if ($advance) {
            rewind($state);
            if (fwrite($state, (string) $next) !== 1 || ! ftruncate($state, 1) || ! fflush($state)) {
                throw new RuntimeException('Cannot persist verification queue selection.');
            }
        }

        return $next !== 0;
    }

    /** Audit and bootstrap exclusion; a durable deployment pause refuses it as it refuses workers. */
    public function tryExclusive(): bool
    {
        $this->close();
        $this->global = $this->store()->exclusiveGroup('worker');
        // Checked while holding the gate: a pause written before it is seen, and a barrier
        // cannot start draining until this holder releases it.
        if ($this->global !== null && self::paused($this->store()->connection())) {
            $this->close();
        }
        $this->exclusive = $this->global !== null;

        return $this->exclusive;
    }

    /** Stop every host's workers from claiming and wait up to $seconds for them to finish. */
    public function drain(float $seconds): bool
    {
        $this->close();
        $this->global = $this->store()->drainGroup('worker', $seconds);
        $this->exclusive = $this->global !== null;

        return $this->exclusive;
    }

    /**
     * False while a drain holds the worker gate or a durable pause exists; a running worker stops claiming.
     *
     * @phpstan-impure
     */
    public function admitting(): bool
    {
        return self::pausesExist($this->store()->connection())
            ? $this->store()->admittingUnpaused('worker', 'release_verification_pauses') : $this->store()->admitting('worker');
    }

    /**
     * A durable pause keeps every host's workers out even after the session that took it ends.
     * It needs its migration; before that, only the session-scoped gate applies.
     */
    /** A pause when its table already exists; callers that may precede the migration use this. */
    public static function pauseIfAvailable(string $token, string $reason): ?string
    {
        if (! self::pausesExist(null)) {
            return null;
        }
        self::pause($token, $reason);

        return $token;
    }

    public static function pause(string $token, string $reason): void
    {
        self::ensurePauseTable();
        DB::table('release_verification_pauses')->insertOrIgnore(['token' => $token, 'reason' => $reason]);
    }

    /**
     * The barrier creates the pause table itself when it runs before the migration that adds it,
     * so even the first upgrade has a durable fence. The migration then finds it in place.
     */
    public static function ensurePauseTable(): void
    {
        if (self::pauseTableCreated()) {
            return;
        }
        try {
            DB::connection()->getSchemaBuilder()->create('release_verification_pauses', static function (Blueprint $table): void {
                $table->string('token', 64)->primary();
                $table->string('reason', 64);
                $table->timestamp('created_at')->useCurrent();
            });
        } catch (Throwable $error) {
            // Another process may have created it first.
            if (! self::pauseTableCreated()) {
                throw $error;
            }
        }
    }

    /** @phpstan-impure */
    private static function pauseTableCreated(): bool
    {
        return DB::connection()->getSchemaBuilder()->hasTable('release_verification_pauses');
    }

    /**
     * Release pauses: one token, every pause with the given reason (an earlier failed attempt's
     * too), or with neither, every pause (operator recovery).
     */
    public static function unpause(?string $token, ?string $reason = null): void
    {
        if (! self::pausesExist(null)) {
            return;
        }
        $query = DB::table('release_verification_pauses');
        if ($token !== null) {
            $query->where('token', $token);
        } elseif ($reason !== null) {
            $query->where('reason', $reason);
        }
        $query->delete();
    }

    /** Checked on the holder's own connection, which may not be the default one. */
    /** A current (locking) read sees a pause committed after an enclosing transaction's snapshot. */
    public static function paused(?string $connection = null, ?string $reason = null, bool $current = false): bool
    {
        return self::pausesExist($connection) && DB::connection($connection)->table('release_verification_pauses')
            ->when($reason !== null, static fn ($query) => $query->where('reason', $reason))
            ->when($current, static fn ($query) => $query->sharedLock())->exists();
    }

    /** @var \WeakMap<object, true>|null connections known to have the pause table */
    private static ?\WeakMap $pauseTables = null;

    private static function pausesExist(?string $name): bool
    {
        $connection = DB::connection($name);
        self::$pauseTables ??= new \WeakMap;
        if (isset(self::$pauseTables[$connection])) {
            return true;
        }
        if (! $connection->getSchemaBuilder()->hasTable('release_verification_pauses')) {
            return false;
        }
        self::$pauseTables[$connection] = true;

        return true;
    }

    public function tryCandidate(int $id): bool
    {
        if (isset($this->candidates[$id])) {
            return $this->store()->holds($this->candidates[$id]);
        }
        if ($this->global === null || count($this->candidates) >= 16) {
            throw new RuntimeException('Candidate lock requires an active worker and a bounded batch.');
        }
        $lock = $this->store()->exclusive('candidate-'.$id);
        if ($lock === null) {
            return false;
        }
        $this->candidates[$id] = $lock;

        return true;
    }

    /** A database lock is lost with its session, so protection is proved at each use. */
    public function protects(int $id): bool
    {
        return self::$useRustFfi ? $this->protectsNative($id) : $this->protectsPhp($id);
    }

    public function protectsNative(int $id): bool
    {
        $lock = $this->exclusive ? $this->global : ($this->candidates[$id] ?? null);
        $held = $lock !== null && $this->store()->holds($lock);
        $result = self::nativeInteger('nntmux_verification_protects', (int) $this->exclusive,
            (int) ($this->global !== null), (int) isset($this->candidates[$id]), (int) $held);

        return $result !== null ? $result !== 0 : $held;
    }

    public function protectsPhp(int $id): bool
    {
        if ($this->exclusive) {
            return $this->global !== null && $this->store()->holds($this->global);
        }

        return isset($this->candidates[$id]) && $this->store()->holds($this->candidates[$id]);
    }

    /** Whether this worker still holds its group and slot locks. */
    public function held(): bool
    {
        return $this->global !== null && $this->slot !== null && $this->store()->holdsAll($this->global, $this->slot);
    }

    /** Maintenance of this host's scratch; each worker host has its own turn. */
    public function tryGc(): bool
    {
        $host = ScratchInventory::host();
        $this->gc = $this->store()->exclusive($host === '' ? 'gc' : 'gc-'.substr(sha1($host), 0, 12));

        return $this->gc !== null;
    }

    /** Candidate expiry is shared by every host; a maintenance holder that misses it skips it. */
    public function tryExpiry(): bool
    {
        if ($this->gc === null) {
            throw new RuntimeException('Expiry requires this host\'s maintenance lock.');
        }
        $this->expiry ??= $this->store()->exclusive('expiry');

        return $this->expiry !== null;
    }

    /** Alternate the first maintenance task across exclusively held GC turns. */
    public function preferExpiry(): bool
    {
        if ($this->gc === null || $this->slot === null || $this->global === null) {
            throw new RuntimeException('Maintenance selection requires an active worker and GC lock.');
        }
        $state = $this->gc->state();
        rewind($state);
        $expiryFirst = stream_get_contents($state, 16) === '1';
        rewind($state);
        if (fwrite($state, $expiryFirst ? '0' : '1') !== 1 || ! ftruncate($state, 1) || ! fflush($state)) {
            throw new RuntimeException('Cannot persist verification maintenance selection.');
        }

        return $expiryFirst;
    }

    public function releaseCandidate(?int $id = null): void
    {
        foreach ($id === null ? array_keys($this->candidates) : [$id] as $key) {
            if (isset($this->candidates[$key])) {
                $this->store()->release($this->candidates[$key]);
                unset($this->candidates[$key]);
            }
        }
    }

    public function releaseGc(): void
    {
        $this->release($this->expiry);
        $this->release($this->gc);
    }

    public function close(): void
    {
        $this->releaseCandidate();
        $this->releaseGc();
        $this->release($this->slot);
        $this->release($this->global);
        $this->exclusive = false;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Chosen at first use, so constructing the locks needs no application configuration. */
    private function store(): VerificationLockStore
    {
        return $this->store ??= VerificationLockStore::make();
    }

    /** @param-out null $lock */
    private function release(?VerificationLock &$lock): void
    {
        if ($lock !== null) {
            $this->store()->release($lock);
        }
        $lock = null;
    }

    // ========================================================================
    // WAVE 59.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs cleared every gate (common mean 3.12x, p99 1.71x; uncommon mean 2.21x, p99 2.01x); see RustVerificationLockWorkerLocksVerifiedArticleNzbWriterPublisherW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_FFI_CDEF = <<<'C'
        ssize_t nntmux_w594_worker_lock_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per workerLockRowBatch() record. */
    public const int W59_ROW_WORDS = 6;

    /** int64 summary words after the workerLockRowBatch() records (none for an empty batch). */
    public const int W59_TRAILER_WORDS = 7;

    private const int W59_TIME_MAX = 1 << 42;

    private const string W59_HOST_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.-';

    private const string W59_HEX = '0123456789abcdef';

    private const string W59_DIGITS = '0123456789';

    /** Base inventory score per lock kind (a slot lock outside this host's range scores 20). */
    private const array W59_KIND_SCORE = [0, 50, 100, 40, 60, 30];

    /**
     * Worker lock inventory rows for [lock name, holder, configured slots, slot offset,
     * heartbeat ms, now ms, stale after ms] items. The name kind is 1 `worker` (shared group), 2
     * `worker-N` (candidate slot), 3 `candidate-N`, 4 `publication-forwarder-<12 lowercase
     * hex>-N`, 5 `gc`, `expiry` or `preparation`, else 0 (N is 1-9 digits, -1 without one); a
     * holder is `host:pid` (1-253 bytes of `[A-Za-z0-9.-]` before the last ':', 1-9 digits
     * after). Slots clamp to 1..MAX_SLOTS and the range starts at max(0, min(MAX_SLOT_NUMBER -
     * slots, offset)) + 1, as in slotsPhp()/firstSlotPhp()/lastSlotPhp(). Times clamp to
     * 0..2^42 and the stale window to 1..2^32. Heartbeat 0 fresh, 1 stale (older than the
     * window), 2 never, 3 in the future. A repeated slot number in the batch is a duplicate.
     *
     * The score starts at 0 for a malformed holder or unknown kind, else 50 shared, 100 slot in
     * range (20 outside), 40 candidate, 60 forwarder, 30 maintenance; a stale heartbeat loses
     * one point per tenth of the window past it, a missing one halves it, a future one loses
     * 10, and a duplicate keeps a quarter. Rows [kind | heartbeat << 3 | in range << 5 | holder
     * valid << 6 | duplicate << 7 | reclaimable (stale and known kind) << 8, name number, holder
     * pid, heartbeat age, first slot << 16 | last slot, score], then the trailer [slot locks, in
     * range, stale, duplicates, forwarders, reclaimable, total score].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function workerLockRowBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::workerLockRowBatchNative($items) : self::workerLockRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function workerLockRowBatchPhp(array $items): array
    {
        self::w59Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0];
        $seen = [];
        foreach ($items as [$name, $holder, $configured, $offset, $heartbeat, $now, $staleAfter]) {
            [$kind, $number] = self::w59LockKind($name);
            [$holderClass, $pid] = self::w59Holder($holder);
            $holderOk = $holderClass === 0;
            $slots = max(1, min(self::MAX_SLOTS, $configured));
            $first = max(0, min(self::MAX_SLOT_NUMBER - $slots, $offset)) + 1;
            $last = $first + $slots - 1;
            $inRange = $kind === 2 && $number >= $first && $number <= $last;
            $heartbeat = max(0, min($heartbeat, self::W59_TIME_MAX));
            $now = max(0, min($now, self::W59_TIME_MAX));
            $stale = max(1, min($staleAfter, 1 << 32));
            if ($heartbeat === 0) {
                [$state, $age] = [2, 0];
            } elseif ($heartbeat > $now) {
                [$state, $age] = [3, 0];
            } else {
                $age = $now - $heartbeat;
                $state = $age > $stale ? 1 : 0;
            }
            $duplicate = false;
            if ($kind === 2) {
                $duplicate = isset($seen[$number]);
                $seen[$number] = true;
            }
            $score = ! $holderOk || $kind === 0 ? 0 : ($kind === 2 && ! $inRange ? 20 : self::W59_KIND_SCORE[$kind]);
            $score = match ($state) {
                0 => $score,
                1 => $score - min($score, intdiv($age - $stale, max(1, intdiv($stale, 10)))),
                2 => intdiv($score, 2),
                default => max(0, $score - 10),
            };
            if ($duplicate) {
                $score = intdiv($score, 4);
            }
            $reclaimable = $state === 1 && $kind !== 0;
            array_push($out, $kind | $state << 3 | ($inRange ? 32 : 0) | ($holderOk ? 64 : 0) | ($duplicate ? 128 : 0) | ($reclaimable ? 256 : 0),
                $number, $pid, $age, $first << 16 | $last, $score);
            $summary[0] += $kind === 2 ? 1 : 0;
            $summary[1] += $inRange ? 1 : 0;
            $summary[2] += $state === 1 ? 1 : 0;
            $summary[3] += $duplicate ? 1 : 0;
            $summary[4] += $kind === 4 ? 1 : 0;
            $summary[5] += $reclaimable ? 1 : 0;
            $summary[6] += $score;
        }

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function workerLockRowBatchNative(array $items): array
    {
        self::w59Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w59Join([array_column($items, 0), array_column($items, 1)], $count);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6));

        return self::w59Call($blob, $twin, $ints, $count, $count * self::W59_ROW_WORDS + self::W59_TRAILER_WORDS)
            ?? self::workerLockRowBatchPhp($items);
    }

    /** @return array{0: int, 1: int} [kind, number] */
    private static function w59LockKind(string $name): array
    {
        if ($name === 'worker') {
            return [1, -1];
        }
        if ($name === 'gc' || $name === 'expiry' || $name === 'preparation') {
            return [5, -1];
        }
        if (str_starts_with($name, 'worker-')) {
            [$kind, $at] = [2, 7];
        } elseif (str_starts_with($name, 'candidate-')) {
            [$kind, $at] = [3, 10];
        } elseif (str_starts_with($name, 'publication-forwarder-') && \strlen($name) > 35
            && strspn($name, self::W59_HEX, 22, 12) === 12 && $name[34] === '-') {
            [$kind, $at] = [4, 35];
        } else {
            return [0, -1];
        }
        $digits = \strlen($name) - $at;

        return $digits >= 1 && $digits <= 9 && strspn($name, self::W59_DIGITS, $at) === $digits ? [$kind, (int) substr($name, $at)] : [0, -1];
    }

    /** @return array{0: int, 1: int, 2: int} [class, pid, host length] of `host:pid` */
    private static function w59Holder(string $owner): array
    {
        if ($owner === '') {
            return [1, -1, 0];
        }
        $p = strrpos($owner, ':');
        if ($p === false || $p === 0 || $p > 253 || strspn($owner, self::W59_HOST_BYTES, 0, $p) !== $p) {
            return [2, -1, 0];
        }
        $digits = \strlen($owner) - $p - 1;

        return $digits >= 1 && $digits <= 9 && strspn($owner, self::W59_DIGITS, $p + 1) === $digits ? [0, (int) substr($owner, $p + 1), $p] : [2, -1, 0];
    }

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W59 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('enabled');
        self::w59RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $items */
    private static function w59Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W59 worker lock batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 7 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1])
                || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])) {
                throw new InvalidArgumentException('W59 worker lock batch items must be [string name, string holder, int configured slots, int slot offset, int heartbeat, int now, int stale after] lists.');
            }
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w59Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w59Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w59RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w59', self::W59_FFI_CDEF) ?? false;
            self::w59RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w59RustFfiState('out', $out);
                self::w59RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W59_FFI_CDEF.)
            $written = $ffi->nntmux_w594_worker_lock_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w59RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W59 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
