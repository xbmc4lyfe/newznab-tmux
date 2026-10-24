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
    // Single-call FFI remains opt-in until every paired mean/p99 gate reaches 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /**
     * Database locks cover every worker host using the shared database; file locks cover only
     * this host's storage and remain for SQLite and single-host installs.
     */
    public static function make(): self
    {
        $driver = (string) config('release_verification.locks', 'auto');
        $driver = self::resolveDriver($driver, $driver === 'auto' ? DB::connection()->getDriverName() : '');

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

    /** Whether every lock is still held; database stores prove them in one round trip. */
    public function holdsAll(VerificationLock ...$locks): bool
    {
        foreach ($locks as $lock) {
            if (! $this->holds($lock)) {
                return false;
            }
        }

        return true;
    }

    /** Whether new shared holders may join and no row of $pauses exists, in one round trip where possible. */
    public function admittingUnpaused(string $group, string $pauses): bool
    {
        return $this->admitting($group) && ! DB::connection($this->connection())->table($pauses)->exists();
    }

    /** The database connection this store's holder works on; null is the default connection. */
    public function connection(): ?string
    {
        return null;
    }

    /** Fairness state for a named lock lives on this host and is written only by its holder. */
    protected function statePath(string $name): string
    {
        return self::stateFilePath($this->directory(true), $name);
    }

    protected function directory(bool $locks): string
    {
        $root = (string) config('release_verification.storage_path');
        foreach ($locks ? [$root, self::lockPath($root, true)] : [$root] as $directory) {
            if (is_link($directory)) {
                throw new RuntimeException('Verification lock directory cannot be a symlink.');
            }
            File::ensureDirectoryExists($directory, 0700);
        }

        return self::lockPath($root, $locks);
    }

    public static function resolveDriver(string $driver, string $databaseDriver): ?string
    {
        return self::$useRustFfi ? (self::resolveDriverNative($driver, $databaseDriver) ?? self::resolveDriverPhp($driver, $databaseDriver)) : self::resolveDriverPhp($driver, $databaseDriver);
    }

    public static function resolveDriverPhp(string $driver, string $databaseDriver): ?string
    {
        return match ($driver) {
            'auto' => in_array($databaseDriver, ['mysql', 'mariadb'], true) ? 'database' : 'file', 'database', 'file' => $driver, default => null
        };
    }

    public static function resolveDriverNative(string $driver, string $databaseDriver): ?string
    {
        $result = LeaseHeartbeat::computeNative(1, [$driver, $databaseDriver])['value'] ?? null;

        return is_string($result) ? $result : null;
    }

    public static function lockPath(string $root, bool $locks): string
    {
        return self::$useRustFfi ? (self::lockPathNative($root, $locks) ?? self::lockPathPhp($root, $locks)) : self::lockPathPhp($root, $locks);
    }

    public static function lockPathPhp(string $root, bool $locks): string
    {
        return $locks ? $root.'/locks' : $root;
    }

    public static function lockPathNative(string $root, bool $locks): ?string
    {
        $result = LeaseHeartbeat::computeNative(2, [$root, $locks])['value'] ?? null;

        return is_string($result) ? $result : null;
    }

    public static function stateFilePath(string $directory, string $name): string
    {
        return self::$useRustFfi ? (self::stateFilePathNative($directory, $name) ?? self::stateFilePathPhp($directory, $name)) : self::stateFilePathPhp($directory, $name);
    }

    public static function stateFilePathPhp(string $directory, string $name): string
    {
        return $directory.'/'.$name.'.state';
    }

    public static function stateFilePathNative(string $directory, string $name): ?string
    {
        $result = LeaseHeartbeat::computeNative(3, [$directory, $name])['value'] ?? null;

        return is_string($result) ? $result : null;
    }
}
