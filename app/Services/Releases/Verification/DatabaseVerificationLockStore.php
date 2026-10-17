<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * MariaDB user-level locks shared by every worker host on the database. Like flock, they belong
 * to a session and end with it, so a crashed or disconnected worker releases everything it held.
 * They are taken on the worker's own connection and need no extra connection per worker.
 */
final class DatabaseVerificationLockStore extends VerificationLockStore
{
    /** Shared-holder stripes per group; an exclusive holder proves every stripe free. */
    private const array MEMBERS = ['worker' => 512, 'reset' => 64];

    /** @var array<string, array<string, true>> keys this process holds, per connection */
    private static array $held = [];

    public function __construct(private readonly ?string $connection = null) {}

    public function exclusive(string $name): ?VerificationLock
    {
        $key = $this->key($name);

        return $this->take($key) ? new VerificationLock($name, [$key], $this->statePath($name)) : null;
    }

    public function shared(string $group): ?VerificationLock
    {
        $count = $this->members($group);
        $start = random_int(0, $count - 1);
        for ($offset = 0; $offset < $count; $offset++) {
            $member = $this->key($group.'.'.(($start + $offset) % $count));
            if (! $this->take($member)) {
                continue;
            }
            // An exclusive holder takes the gate before proving every member free, so it either
            // sees this member or this check sees its gate.
            if ($this->value('SELECT IS_FREE_LOCK(?) AS v', [$this->key($group)]) !== 1) {
                $this->drop($member);

                return null;
            }

            return new VerificationLock($group, [$member]);
        }

        return null;
    }

    public function exclusiveGroup(string $group): ?VerificationLock
    {
        $gate = $this->key($group);
        if (! $this->take($gate)) {
            return null;
        }
        $members = array_map(fn (int $number): string => $this->key($group.'.'.$number), range(0, $this->members($group) - 1));
        try {
            $holder = $this->value('SELECT COALESCE('.implode(', ', array_fill(0, count($members), 'IS_USED_LOCK(?)')).') AS v', $members);
        } catch (Throwable $error) {
            $this->drop($gate);

            throw $error;
        }
        if ($holder !== null) {
            $this->drop($gate);

            return null;
        }

        return new VerificationLock($group, [$gate]);
    }

    public function holds(VerificationLock $lock): bool
    {
        try {
            foreach ($lock->keys as $key) {
                if (! isset(self::$held[$this->connectionName()][$key])
                    || $this->value('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS v', [$key]) !== 1) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function release(VerificationLock $lock): void
    {
        try {
            foreach ($lock->keys as $key) {
                $this->drop($key);
            }
        } finally {
            $lock->close();
        }
    }

    private function take(string $key): bool
    {
        // GET_LOCK is re-entrant within a session; refuse a second holder in this process as a
        // second descriptor's flock is refused.
        if (isset(self::$held[$this->connectionName()][$key])) {
            return false;
        }
        if ($this->value('SELECT GET_LOCK(?, 0) AS v', [$key]) !== 1) {
            return false;
        }
        self::$held[$this->connectionName()][$key] = true;

        return true;
    }

    private function drop(string $key): void
    {
        $connection = $this->connectionName();
        if (! isset(self::$held[$connection][$key])) {
            return;
        }
        unset(self::$held[$connection][$key]);
        try {
            $this->db()->selectOne('SELECT RELEASE_LOCK(?) AS v', [$key]);
        } catch (Throwable) {
            // A lost session has already released every lock it held.
        }
    }

    /** Names are scoped to the database, so other databases on the server never collide. */
    private function key(string $name): string
    {
        $key = 'nnv:'.substr(sha1($this->db()->getDatabaseName().'/'.$this->db()->getTablePrefix()), 0, 12).':'.$name;
        if (strlen($key) > 64) {
            throw new RuntimeException('Verification lock name is too long.');
        }

        return $key;
    }

    private function members(string $group): int
    {
        return self::MEMBERS[$group] ?? throw new RuntimeException('Unknown verification lock group.');
    }

    /** @param list<string> $bindings */
    private function value(string $sql, array $bindings): ?int
    {
        $value = $this->db()->selectOne($sql, $bindings)?->v;

        return $value === null ? null : (int) $value;
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }

    private function connectionName(): string
    {
        return $this->connection ?? (string) config('database.default');
    }
}
