<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use RuntimeException;

/** Stable flock files under this host's verification storage; they never cover another host. */
final class FileVerificationLockStore extends VerificationLockStore
{
    public function exclusive(string $name): ?VerificationLock
    {
        return $this->acquire($name, $this->directory(true).'/'.$name.'.lock', LOCK_EX);
    }

    public function shared(string $group): ?VerificationLock
    {
        return $this->acquire($group, $this->directory(false).'/'.$group.'.lock', LOCK_SH);
    }

    public function exclusiveGroup(string $group): ?VerificationLock
    {
        return $this->acquire($group, $this->directory(false).'/'.$group.'.lock', LOCK_EX);
    }

    /** One host's flock has no gate; local drains already stopped this host's workers. */
    public function drainGroup(string $group, float $seconds): ?VerificationLock
    {
        $deadline = microtime(true) + $seconds;
        while (($lock = $this->exclusiveGroup($group)) === null && microtime(true) < $deadline) {
            usleep(200000);
        }

        return $lock;
    }

    public function admitting(string $group): bool
    {
        return true;
    }

    public function holds(VerificationLock $lock): bool
    {
        return is_resource($lock->handle());
    }

    public function release(VerificationLock $lock): void
    {
        $lock->close();
    }

    private function acquire(string $name, string $path, int $mode): ?VerificationLock
    {
        if (is_link($path)) {
            throw new RuntimeException('Verification lock cannot be a symlink.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Verification lock is unavailable.');
        }
        if (! flock($handle, $mode | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new VerificationLock($name, [$path], handle: $handle);
    }
}
