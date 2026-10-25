<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_verification_lock_key(const char *scope, size_t scope_len, const char *name, size_t name_len, char *out, size_t cap);
        int64_t nntmux_verification_members(const char *group, size_t len);
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
        return self::$useRustFfi ? $this->keyNative($name) : $this->keyPhp($name);
    }

    public function keyNative(string $name): string
    {
        $scope = $this->db()->getDatabaseName().'/'.$this->db()->getTablePrefix();
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi !== null) {
                $capacity = 0;
                $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
                if ($scratch !== null) {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_verification_lock_key($scope, strlen($scope), $name, strlen($name), $scratch, $capacity);
                    if (is_int($written) && $written >= 0 && $written <= 64 && $written <= $capacity) {
                        return $written === 0 ? '' : FFI::string($scratch, $written);
                    }
                }
            }
        } catch (Throwable) {
            // Preserve the PHP result, including its length error, when the ABI is unavailable.
        }

        return self::keyForScopePhp($scope, $name);
    }

    public function keyPhp(string $name): string
    {
        return self::keyForScopePhp($this->db()->getDatabaseName().'/'.$this->db()->getTablePrefix(), $name);
    }

    private static function keyForScopePhp(string $scope, string $name): string
    {
        $key = 'nnv:'.substr(sha1($scope), 0, 12).':'.$name;
        if (strlen($key) > 64) {
            throw new RuntimeException('Verification lock name is too long.');
        }

        return $key;
    }

    private function members(string $group): int
    {
        return self::$useRustFfi ? $this->membersNative($group) : $this->membersPhp($group);
    }

    public function membersNative(string $group): int
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi !== null) {
                // @phpstan-ignore method.notFound
                $count = $ffi->nntmux_verification_members($group, strlen($group));
                if (is_int($count) && $count > 0) {
                    return $count;
                }
            }
        } catch (Throwable) {
            // Fall through to the original unknown-group error.
        }

        return $this->membersPhp($group);
    }

    public function membersPhp(string $group): int
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

    /** Wave 50.2 paired benchmarks decide this default; see RustVerificationDbLockDirtyFetchedFileLockHeartbeatW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        intptr_t nntmux_w502_verification_lock_keys_batch(const char *prefix, size_t prefix_len, const char *names, size_t names_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 50 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns a buffer of at least $val words (its capacity
     * through 'cap').
     */
    private static function w50RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w50', self::W50_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w50RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return $out;
            case 'cap':
                return $capacity;
            default:
                return $enabled;
        }
    }

    public static function usesW50RustFfi(): bool
    {
        $enabled = self::w50RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W50_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planLockKeys() routed to Rust ($enabled) or PHP, then
     * restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW50RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w50RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w50RustFfiState('set', $previous);
        }
    }

    /**
     * Batch lock-key plan for one database scope. Two words per name: `flags | keyLength << 8 |
     * extra << 24` (flags: 1 key fits 64 bytes, 2 known group with extra = its member count,
     * 4 in-range stripe `group.N` with extra = N, 8 repeated name, 16 empty name; the length is
     * clamped to 65535), then `crc32(key)`. Fitting, duplicate and stripe counts and the XXH64
     * digest of all earlier words follow.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planLockKeys(string $scope, array $names): array
    {
        return self::usesW50RustFfi() ? self::planLockKeysNative($scope, $names) : self::planLockKeysPhp($scope, $names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planLockKeysPhp(string $scope, array $names): array
    {
        $prefix = 'nnv:'.substr(sha1($scope), 0, 12).':';
        $out = $seen = [];
        $fits = $duplicates = $members = 0;
        foreach ($names as $name) {
            $key = $prefix.$name;
            $length = \strlen($key);
            $flags = $extra = 0;
            if ($length <= 64) {
                $flags = 1;
                $fits++;
            }
            $group = self::MEMBERS[$name] ?? 0;
            if ($group > 0) {
                $flags |= 2;
                $extra = $group;
            } elseif (($dot = strrpos($name, '.')) !== false) {
                $count = self::MEMBERS[substr($name, 0, $dot)] ?? 0;
                $digits = substr($name, $dot + 1);
                $width = \strlen($digits);
                if ($count > 0 && $width > 0 && $width <= 4 && strspn($digits, '0123456789') === $width
                    && ($width === 1 || $digits[0] !== '0') && (int) $digits < $count) {
                    $flags |= 4;
                    $extra = (int) $digits;
                    $members++;
                }
            }
            if (isset($seen[$name])) {
                $flags |= 8;
                $duplicates++;
            } else {
                $seen[$name] = true;
            }
            if ($name === '') {
                $flags |= 16;
            }
            $out[] = $flags | min($length, 0xFFFF) << 8 | $extra << 24;
            $out[] = crc32($key);
        }
        $out[] = $fits;
        $out[] = $duplicates;
        $out[] = $members;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, $names is not a list or a name
     * holds a NUL byte.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function planLockKeysNative(string $scope, array $names): array
    {
        $count = \count($names);
        $ffi = $count === 0 ? null : self::w50RustFfiState('ffi');
        if (! $ffi instanceof FFI || ! array_is_list($names)) {
            return self::planLockKeysPhp($scope, $names);
        }
        $prefix = 'nnv:'.substr(sha1($scope), 0, 12).':';
        $joined = implode("\0", $names);
        $words = 2 * $count + 4;
        $values = false;
        // Rust rejects a column whose NUL count differs from $count - 1 (an embedded NUL byte).
        try {
            $out = self::w50RustFfiState('out', $words);
            if ($out instanceof CData) {
                // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
                $written = $ffi->nntmux_w502_verification_lock_keys_batch($prefix, \strlen($prefix), $joined, \strlen($joined), $count, $out, self::w50RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::planLockKeysPhp($scope, $names);
    }
}
