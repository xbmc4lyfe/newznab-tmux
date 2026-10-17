<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use RuntimeException;

/** Stable locks outlive database leases and manifest generations. */
final class VerificationWorkerLocks
{
    /** Ceiling on scheduled candidate worker slots per host, whatever the configured count. */
    public const int MAX_SLOTS = 64;

    /** Highest slot number across every worker host; each host schedules its own range. */
    public const int MAX_SLOT_NUMBER = 256;

    private ?VerificationLockStore $store;

    private ?VerificationLock $global = null;

    private ?VerificationLock $slot = null;

    private ?VerificationLock $candidate = null;

    private ?VerificationLock $gc = null;

    private ?VerificationLock $expiry = null;

    private ?int $candidateId = null;

    private bool $exclusive = false;

    public function __construct(?VerificationLockStore $store = null)
    {
        $this->store = $store;
    }

    public function start(int $slot = 0): bool
    {
        $this->close();
        $this->global = $this->store()->shared('worker');
        if ($this->global === null) {
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

    /** Configured candidate worker slots on this host, from 1 to MAX_SLOTS. */
    public static function slots(): int
    {
        return max(1, min(self::MAX_SLOTS, (int) config('release_verification.candidate_workers', 2)));
    }

    /** This host's first slot number; hosts sharing a database configure disjoint ranges. */
    public static function firstSlot(): int
    {
        return max(0, min(self::MAX_SLOT_NUMBER - self::slots(), (int) config('release_verification.slot_offset', 0))) + 1;
    }

    public static function lastSlot(): int
    {
        return self::firstSlot() + self::slots() - 1;
    }

    public function startPreparation(): bool
    {
        $this->close();
        $this->global = $this->store()->shared('worker');
        if ($this->global !== null) {
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

    public function tryExclusive(): bool
    {
        $this->close();
        $this->global = $this->store()->exclusiveGroup('worker');
        $this->exclusive = $this->global !== null;

        return $this->exclusive;
    }

    public function tryCandidate(int $id): bool
    {
        if ($this->candidateId === $id && $this->candidate !== null) {
            return true;
        }
        if ($this->candidate !== null || $this->global === null) {
            throw new RuntimeException('Candidate lock requires an active worker and no other candidate lock.');
        }
        $this->candidate = $this->store()->exclusive('candidate-'.$id);
        if ($this->candidate === null) {
            return false;
        }
        $this->candidateId = $id;

        return true;
    }

    /** A database lock is lost with its session, so protection is proved at each use. */
    public function protects(int $id): bool
    {
        if ($this->exclusive) {
            return $this->global !== null && $this->store()->holds($this->global);
        }

        return $this->candidate !== null && $this->candidateId === $id && $this->store()->holds($this->candidate);
    }

    /** Whether this worker still holds its group and slot locks. */
    public function held(): bool
    {
        return $this->global !== null && $this->slot !== null
            && $this->store()->holds($this->global) && $this->store()->holds($this->slot);
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

    public function releaseCandidate(): void
    {
        $this->release($this->candidate);
        $this->candidateId = null;
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
