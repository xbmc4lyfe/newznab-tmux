<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Non-blocking verification locks: every acquisition fails at once rather than waiting, so
 * holders never wait on each other. A group is a reader/writer lock; shared holders exclude an
 * exclusive holder, and an exclusive holder excludes everyone else.
 */
abstract class VerificationLockStore
{
    /**
     * Database locks cover every worker host using the shared database; file locks cover only
     * this host's storage and remain for SQLite and single-host installs.
     */
    public static function make(): self
    {
        $driver = (string) config('release_verification.locks', 'auto');
        if ($driver === 'auto') {
            $driver = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'database' : 'file';
        }

        return match ($driver) {
            'database' => new DatabaseVerificationLockStore,
            'file' => new FileVerificationLockStore,
            default => throw new RuntimeException('Unknown verification lock driver.'),
        };
    }

    /** One holder of a named mutex across every process using this store. */
    abstract public function exclusive(string $name): ?VerificationLock;

    /** A shared holder of a group, refused while the group is held exclusively. */
    abstract public function shared(string $group): ?VerificationLock;

    /** The sole holder of a group, refused while any shared holder remains. */
    abstract public function exclusiveGroup(string $group): ?VerificationLock;

    /**
     * Hold the group's gate so no new shared holder joins, then wait for the current ones to leave.
     * Workers on every host stop claiming once they see the gate held.
     */
    abstract public function drainGroup(string $group, float $seconds): ?VerificationLock;

    /** Whether new shared holders may join the group. */
    abstract public function admitting(string $group): bool;

    /** False once the lock is no longer held, such as after its database session ended. */
    abstract public function holds(VerificationLock $lock): bool;

    abstract public function release(VerificationLock $lock): void;

    /** Fairness state for a named lock lives on this host and is written only by its holder. */
    protected function statePath(string $name): string
    {
        return $this->directory(true).'/'.$name.'.state';
    }

    protected function directory(bool $locks): string
    {
        $root = (string) config('release_verification.storage_path');
        foreach ($locks ? [$root, $root.'/locks'] : [$root] as $directory) {
            if (is_link($directory)) {
                throw new RuntimeException('Verification lock directory cannot be a symlink.');
            }
            File::ensureDirectoryExists($directory, 0700);
        }

        return $locks ? $root.'/locks' : $root;
    }
}
