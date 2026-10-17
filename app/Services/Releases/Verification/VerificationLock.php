<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use RuntimeException;

/** A held verification lock, with a small state file for its holder's fairness turns. */
final class VerificationLock
{
    /** @var resource|null */
    private $state = null;

    /**
     * @param  list<string>  $keys  Store-specific identities of what is held.
     * @param  resource|null  $handle  The locked file, which also holds the state, for file locks.
     */
    public function __construct(public readonly string $name, public readonly array $keys,
        private readonly ?string $statePath = null, private $handle = null) {}

    /** @return resource|null */
    public function handle()
    {
        return $this->handle;
    }

    /**
     * State is written only while the lock is held, so a separate state file needs no lock.
     *
     * @return resource
     */
    public function state()
    {
        if (is_resource($this->handle)) {
            return $this->handle;
        }
        if (! is_resource($this->state)) {
            if ($this->statePath === null || is_link($this->statePath)) {
                throw new RuntimeException('Verification lock state is unavailable.');
            }
            $state = fopen($this->statePath, 'c+');
            if ($state === false) {
                throw new RuntimeException('Verification lock state is unavailable.');
            }
            $this->state = $state;
        }

        return $this->state;
    }

    public function close(): void
    {
        if (is_resource($this->state)) {
            fclose($this->state);
        }
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->state = null;
        $this->handle = null;
    }
}
