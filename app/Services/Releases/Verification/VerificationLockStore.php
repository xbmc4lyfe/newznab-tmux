<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

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

    // Rust is the default only while the paired W58.6 benchmark clears 1.5x on all four metrics.
    private const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_FFI_CDEF = <<<'C'
        int32_t nntmux_w586_lock_status_batch(const char *strings, size_t strings_len, const char *string_twin, size_t string_twin_len, const char *ints, size_t ints_len, size_t count, int64_t now, int64_t ttl, int64_t *out, size_t out_cap);
        C;

    private const int W58_MAX_ITEMS = 1_048_576;

    public const int W58_LOCK_HEADER = 19;

    private const string W58_KEY_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789:._-';

    private const string W58_HEX = '0123456789abcdef';

    /**
     * Classify lock requests against their current lease. Modes: 0 exclusive, 1 shared, 2 exclusive
     * group, 3 drain (other 4). A key is valid at 1-191 bytes of `[A-Za-z0-9:._-]` (class 1 empty, 2
     * too long, 3 invalid bytes); a token is 32 lowercase hex digits (0) or a lowercase UUID (1), 2
     * empty, 3 other. Expiry (ms, 0 none) and `$nowMs` clamp to 0..2^42, holders to 0..2^20-1 and
     * `$ttlMs` to 0..2^40; a TTL is valid from 1 s to 1 day. Lease 0 free, 1 held, 2 expired, 3 held
     * but expiring (remaining below a quarter TTL). Outcome 0 granted, 1 contended, 2 reclaim an expired
     * lease, 3 invalid request, 4 draining (shared holders remain).
     *
     * Layout: `[0]` count, `[1..5]` per outcome, `[6..9]` per lease, `[10]` invalid keys, `[11]` invalid
     * tokens, `[12]` TTL valid, `[13]` shared holders, `[14]` first contended and `[15]` first reclaim
     * index, `[16]` soonest expiring held index (-1 when none) and `[17]` its remaining ms, `[18]` FNV-1a
     * digest; then per request `outcome | key << 3 | token << 5 | lease << 7 | mode << 9 |
     * min(holders, 1023) << 12 | min(name bytes, 255) << 22 | min(remaining, 2^32-1) << 30`.
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $tokens
     * @param  array<array-key, int>  $modes
     * @param  array<array-key, int>  $expiresAt
     * @param  array<array-key, int>  $holders
     * @return list<int>
     */
    public static function classifyLockBatch(array $names, array $tokens, array $modes, array $expiresAt, array $holders, int $nowMs, int $ttlMs): array
    {
        return (self::usesW58RustFfi() ? self::classifyLockBatchNative($names, $tokens, $modes, $expiresAt, $holders, $nowMs, $ttlMs) : null)
            ?? self::classifyLockBatchPhp($names, $tokens, $modes, $expiresAt, $holders, $nowMs, $ttlMs);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $tokens
     * @param  array<array-key, int>  $modes
     * @param  array<array-key, int>  $expiresAt
     * @param  array<array-key, int>  $holders
     * @return list<int>
     */
    public static function classifyLockBatchPhp(array $names, array $tokens, array $modes, array $expiresAt, array $holders, int $nowMs, int $ttlMs): array
    {
        $names = array_values($names);
        $tokens = array_values($tokens);
        $modes = array_values($modes);
        $expiresAt = array_values($expiresAt);
        $holders = array_values($holders);
        $now = max(0, min($nowMs, 1 << 42));
        $ttl = max(0, min($ttlMs, 1 << 40));
        $ttlValid = $ttl >= 1_000 && $ttl <= 86_400_000;
        $count = \count($names);
        $out = array_fill(0, self::W58_LOCK_HEADER, 0);
        $out[0] = $count;
        $out[12] = (int) $ttlValid;
        $out[14] = $out[15] = $out[16] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($names as $at => $name) {
            $name = (string) $name;
            $length = \strlen($name);
            $key = $length === 0 ? 1 : ($length > 191 ? 2 : (strspn($name, self::W58_KEY_BYTES) === $length ? 0 : 3));
            $token = (string) ($tokens[$at] ?? '');
            $token = match (\strlen($token)) {
                0 => 2,
                32 => strspn($token, self::W58_HEX) === 32 ? 0 : 3,
                36 => $token[8] === '-' && $token[13] === '-' && $token[18] === '-' && $token[23] === '-'
                    && strspn(str_replace('-', '', $token), self::W58_HEX) === 32 ? 1 : 3,
                default => 3,
            };
            $mode = (int) ($modes[$at] ?? 0);
            if ($mode < 0 || $mode > 3) {
                $mode = 4;
            }
            $expires = max(0, min((int) ($expiresAt[$at] ?? 0), 1 << 42));
            $holding = max(0, min((int) ($holders[$at] ?? 0), (1 << 20) - 1));
            $lease = match (true) {
                $expires === 0 => 0,
                $expires <= $now => 2,
                ($expires - $now) * 4 < $ttl => 3,
                default => 1,
            };
            $held = $lease === 1 || $lease === 3;
            $remaining = $held ? $expires - $now : 0;
            $outcome = match (true) {
                $key !== 0 || $token >= 2 || $mode === 4 || ! $ttlValid => 3,
                $held => 1,
                $mode === 2 && $holding > 0 => 1,
                $mode === 3 && $holding > 0 => 4,
                $lease === 2 => 2,
                default => 0,
            };
            $out[1 + $outcome]++;
            $out[6 + $lease]++;
            $out[10] += (int) ($key !== 0);
            $out[11] += (int) ($token >= 2);
            $out[13] += $holding;
            if ($outcome === 1 && $out[14] < 0) {
                $out[14] = $at;
            }
            if ($outcome === 2 && $out[15] < 0) {
                $out[15] = $at;
            }
            if ($held && ($out[16] < 0 || $remaining < $out[17])) {
                $out[16] = $at;
                $out[17] = $remaining;
            }
            $row = $outcome | $key << 3 | $token << 5 | $lease << 7 | $mode << 9 | min($holding, 1023) << 12 | min($length, 255) << 22
                | min($remaining, 4_294_967_295) << 30;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[18] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust classification, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, string>  $tokens
     * @param  array<array-key, int>  $modes
     * @param  array<array-key, int>  $expiresAt
     * @param  array<array-key, int>  $holders
     * @return list<int>|null
     */
    public static function classifyLockBatchNative(array $names, array $tokens, array $modes, array $expiresAt, array $holders, int $nowMs, int $ttlMs): ?array
    {
        $count = \count($names);
        if ($count > self::W58_MAX_ITEMS || \count($tokens) !== $count || \count($modes) !== $count
            || \count($expiresAt) !== $count || \count($holders) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w58Strings(array_values($names), array_values($tokens));
        $ints = pack('q*', ...array_values($modes), ...array_values($expiresAt), ...array_values($holders));
        $fields = self::W58_LOCK_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w58Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w586_lock_status_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $nowMs, $ttlMs, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 58.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $forced = self::w58RustFfiState();

        return \is_bool($forced) ? $forced : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 58.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w58` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w58', self::W58_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 58.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w58Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w58RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w58Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
