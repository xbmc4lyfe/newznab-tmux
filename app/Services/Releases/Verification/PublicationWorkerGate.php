<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/**
 * A publication writes as a worker does, so a reset, audit or deployment barrier that holds the
 * worker group excludes it even after the sending worker has gone. One request takes the shared
 * lock for each publication; a batch keeps one acquisition for all of its items and proves, before
 * each, that the lock is still held and no pause has been written. Once a retained gate is
 * refused, it stays refused for the rest of its life.
 */
final class PublicationWorkerGate
{
    private ?VerificationLockStore $store = null;

    private ?VerificationLock $lock = null;

    private bool $refused = false;

    public function __construct(private readonly bool $retain = false) {}

    /** Whether the caller may write now; false means retry later. */
    public function enter(): bool
    {
        if ($this->refused) {
            return false;
        }
        $this->store ??= VerificationLockStore::make();
        if ($this->lock !== null && ! $this->store->holds($this->lock)) {
            // A database lock ends with its session; protection must be proved, not assumed.
            $this->lock = null;
        }
        $this->lock ??= $this->store->shared('worker');
        if ($this->lock !== null && VerificationWorkerLocks::paused()) {
            $this->store->release($this->lock);
            $this->lock = null;
        }
        if ($this->lock === null) {
            $this->refused = $this->retain;

            return false;
        }

        return true;
    }

    /** After one publication: a single request lets go; a batch keeps its acquisition. */
    public function leave(): void
    {
        if (! $this->retain) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->lock !== null && $this->store !== null) {
            $this->store->release($this->lock);
        }
        $this->lock = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
