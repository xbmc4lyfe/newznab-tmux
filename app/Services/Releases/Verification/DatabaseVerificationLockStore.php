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
 * They are taken on the worker's own connection and need no extra connection per worker. While
 * any is held, that connection refuses to reconnect: protected work on a new session would run
 * without the locks, so it fails instead.
 */
final class DatabaseVerificationLockStore extends VerificationLockStore
{
    /** Shared-holder stripes per group; an exclusive holder proves every stripe free. */
    private const array MEMBERS = ['worker' => 512, 'reset' => 64];

    /** @var array<string, array<string, true>> keys this process holds, per connection */
    private static array $held = [];

    /** @var array<string, array{session: int, connection: \WeakReference<Connection>, reconnector: mixed}> */
    private static array $sessions = [];

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
            try {
                $free = $this->value('SELECT IS_FREE_LOCK(?) AS v, CONNECTION_ID() AS session', [$this->key($group)]) === 1;
            } catch (Throwable $error) {
                $this->drop($member);

                throw $error;
            }
            if (! $free) {
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
        try {
            $free = $this->membersFree($group);
        } catch (Throwable $error) {
            $this->drop($gate);

            throw $error;
        }
        if (! $free) {
            $this->drop($gate);

            return null;
        }

        return new VerificationLock($group, [$gate]);
    }

    public function drainGroup(string $group, float $seconds): ?VerificationLock
    {
        $deadline = microtime(true) + $seconds;
        $gate = $this->key($group);
        while (! $this->take($gate)) {
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(200000);
        }
        try {
            while (! $this->membersFree($group)) {
                if (microtime(true) >= $deadline) {
                    $this->drop($gate);

                    return null;
                }
                usleep(200000);
            }
        } catch (Throwable $error) {
            $this->drop($gate);

            throw $error;
        }

        return new VerificationLock($group, [$gate]);
    }

    public function holdsAll(VerificationLock ...$locks): bool
    {
        $keys = array_merge(...array_map(static fn (VerificationLock $lock): array => $lock->keys, $locks));
        foreach ($keys as $key) {
            if (! isset(self::$held[$this->connectionName()][$key])) {
                return false;
            }
        }
        if ($keys === []) {
            return true;
        }
        try {
            return $this->value('SELECT ('.implode(' AND ', array_fill(0, count($keys), 'IS_USED_LOCK(?) <=> CONNECTION_ID()')).') AS v, CONNECTION_ID() AS session', $keys) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public function admittingUnpaused(string $group, string $pauses): bool
    {
        $table = $this->db()->getQueryGrammar()->wrapTable($pauses);

        return $this->value('SELECT IS_FREE_LOCK(?) AND NOT EXISTS (SELECT 1 FROM '.$table.') AS v, CONNECTION_ID() AS session', [$this->key($group)]) === 1;
    }

    public function admitting(string $group): bool
    {
        return $this->value('SELECT IS_FREE_LOCK(?) AS v, CONNECTION_ID() AS session', [$this->key($group)]) === 1;
    }

    private function membersFree(string $group): bool
    {
        $members = array_map(fn (int $number): string => $this->key($group.'.'.$number), range(0, $this->members($group) - 1));

        return $this->value('SELECT COALESCE('.implode(', ', array_fill(0, count($members), 'IS_USED_LOCK(?)')).') AS v, CONNECTION_ID() AS session', $members) === null;
    }

    public function holds(VerificationLock $lock): bool
    {
        try {
            foreach ($lock->keys as $key) {
                if (! isset(self::$held[$this->connectionName()][$key])
                    || $this->value('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS v, CONNECTION_ID() AS session', [$key]) !== 1) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function connection(): ?string
    {
        return $this->connection;
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
        $connection = $this->connectionName();
        $pinned = (self::$held[$connection] ?? []) !== [];
        $row = $this->db()->selectOne('SELECT GET_LOCK(?, 0) AS v, CONNECTION_ID() AS session', [$key]);
        if ($pinned) {
            try {
                $this->assertSession((int) $row->session);
            } catch (RuntimeException $error) {
                if ((int) $row->v === 1) {
                    // Not ours to keep: this session holds none of the locks it must accompany.
                    $this->db()->selectOne('SELECT RELEASE_LOCK(?) AS v', [$key]);
                }

                throw $error;
            }
        }
        if ((int) $row->v !== 1) {
            return false;
        }
        self::$held[$connection][$key] = true;
        if (! $pinned) {
            $this->pin((int) $row->session);
        }

        return true;
    }

    /**
     * Pin the session that took this connection's first lock and forbid reconnecting it until the
     * last one is released.
     */
    private function pin(int $session): void
    {
        $db = $this->db();
        $reconnector = (fn () => $this->reconnector)->call($db);
        $db->setReconnector(static function (): never {
            throw new RuntimeException('Verification lock session was lost; refusing to continue on a new session.');
        });
        self::$sessions[$this->connectionName()] = ['session' => $session, 'connection' => \WeakReference::create($db),
            'reconnector' => $reconnector];
    }

    private function unpin(): void
    {
        $pinned = self::$sessions[$this->connectionName()] ?? null;
        unset(self::$sessions[$this->connectionName()]);
        $db = $pinned['connection']->get() ?? null;
        if ($db !== null && is_callable($pinned['reconnector'])) {
            $db->setReconnector($pinned['reconnector']);
        }
    }

    /** Every held lock is gone once its statements run on another session or connection. */
    private function assertSession(int $session): void
    {
        $pinned = self::$sessions[$this->connectionName()] ?? null;
        if ($pinned === null || $pinned['session'] !== $session || $pinned['connection']->get() !== $this->db()) {
            throw new RuntimeException('Verification lock session was lost; refusing to continue on a new session.');
        }
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
        } finally {
            if ((self::$held[$connection] ?? []) === []) {
                unset(self::$held[$connection]);
                $this->unpin();
            }
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

    /**
     * Read a lock function result, proving it ran on the session holding this process's locks.
     *
     * @param  list<string>  $bindings
     */
    private function value(string $sql, array $bindings): ?int
    {
        $row = $this->db()->selectOne($sql, $bindings);
        if ((self::$held[$this->connectionName()] ?? []) !== []) {
            $this->assertSession((int) $row->session);
        }

        return $row->v === null ? null : (int) $row->v;
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
