<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
}
