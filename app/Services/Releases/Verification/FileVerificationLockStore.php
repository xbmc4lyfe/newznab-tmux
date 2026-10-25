<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use RuntimeException;
use Throwable;

/** Stable flock files under this host's verification storage; they never cover another host. */
final class FileVerificationLockStore extends VerificationLockStore
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_w336_locks_uptime(uint8_t operation, const char *input, size_t input_len, int64_t first, int64_t second, double value, uint8_t flag, uint8_t *out, size_t out_cap);
CDEF;

    // Single-call FFI must qualify on every common mean and uncommon p99 workload.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Length-prefixed fields preserve arbitrary PHP string bytes, including NULs.
     *
     * @param  list<string>  $fields
     * @return list<string>|null
     */
    private static function additionalResult(int $operation, array $fields = [], int $first = 0, int $second = 0, float $value = 0.0, bool $flag = false): ?array
    {
        try {
            if (self::$ffi === null) {
                self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
            }
            $ffi = self::$ffi;
            if (! $ffi instanceof FFI) {
                return null;
            }
            $input = '';
            foreach ($fields as $field) {
                if (strlen($field) > 16_777_216) {
                    return null;
                }
                $input .= pack('N', strlen($field)).$field;
            }
            $capacity = strlen($input) + 512;
            $out = $ffi->new('uint8_t['.$capacity.']');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w336_locks_uptime($operation, $input, strlen($input), $first, $second, $value, (int) $flag, $out, $capacity);
            if ($written <= 0 || $written > $capacity) {
                return null;
            }
            $bytes = FFI::string($out, $written);
            $result = [];
            $offset = 0;
            while ($offset < $written) {
                if ($written - $offset < 4) {
                    return null;
                }
                $header = unpack('Nlength', substr($bytes, $offset, 4));
                if ($header === false || ! is_int($header['length'])) {
                    return null;
                }
                $length = $header['length'];
                $offset += 4;
                if ($length > $written - $offset) {
                    return null;
                }
                $result[] = substr($bytes, $offset, $length);
                $offset += $length;
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /** A hashed path for callers that explicitly opt into hashed lock identities. */
    public static function buildLockFilePath(string $directory, string $name): string
    {
        return self::usesRustFfi() ? self::buildLockFilePathNative($directory, $name) : self::buildLockFilePathPhp($directory, $name);
    }

    public static function buildLockFilePathPhp(string $directory, string $name): string
    {
        return $directory.'/'.hash('sha256', $name).'.lock';
    }

    public static function buildLockFilePathNative(string $directory, string $name): string
    {
        return self::additionalResult(1, [$directory, $name])[0] ?? self::buildLockFilePathPhp($directory, $name);
    }

    public static function computePollDeadlineNs(int $nowNs, int $seconds): int
    {
        return self::usesRustFfi() ? self::computePollDeadlineNsNative($nowNs, $seconds) : self::computePollDeadlineNsPhp($nowNs, $seconds);
    }

    public static function computePollDeadlineNsPhp(int $nowNs, int $seconds): int
    {
        return $nowNs + max(0, $seconds) * 1_000_000_000;
    }

    public static function computePollDeadlineNsNative(int $nowNs, int $seconds): int
    {
        $result = self::additionalResult(2, first: $nowNs, second: $seconds);

        return $result !== null && count($result) === 1 ? (int) $result[0] : self::computePollDeadlineNsPhp($nowNs, $seconds);
    }

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

    /** Wave 50.2 paired benchmarks decide this default; see RustVerificationDbLockDirtyFetchedFileLockHeartbeatW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        intptr_t nntmux_w502_file_lock_plan_batch(const char *directory, size_t directory_len, const char *names, size_t names_len, const char *timeouts, size_t timeouts_len, size_t count, int64_t now_ns, int64_t *out, size_t out_cap);
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
     * Runs $callback with planLockFiles() routed to Rust ($enabled) or PHP, then
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

    /** Bytes a lock name may not hold: control characters, the separator and DEL. */
    private const string W50_UNSAFE = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f/\x7f";

    /**
     * Batch flock-file plan under one directory (`directory/name.lock`). Two words per lock:
     * `flags | pathLength << 8 | crc32(path) << 32` (flags: 1 safe single component, 2 name fits
     * NAME_MAX with its suffix, 4 path within 1024 bytes, 8 repeated name, 16 hidden name,
     * 32 usable: 1|2|4 without 8; the length is clamped to 65535), then the poll deadline
     * computePollDeadlineNsPhp() gives, saturated at PHP_INT_MAX. Usable and duplicate counts,
     * the earliest usable deadline (PHP_INT_MAX for none) and the XXH64 digest of all earlier
     * words follow.
     *
     * @param  list<string>  $names
     * @param  list<int>  $timeouts  Seconds per lock.
     * @return list<int>
     */
    public static function planLockFiles(string $directory, array $names, array $timeouts, int $nowNs): array
    {
        return self::usesW50RustFfi() ? self::planLockFilesNative($directory, $names, $timeouts, $nowNs)
            : self::planLockFilesPhp($directory, $names, $timeouts, $nowNs);
    }

    /**
     * @param  list<string>  $names
     * @param  list<int>  $timeouts
     * @return list<int>
     */
    public static function planLockFilesPhp(string $directory, array $names, array $timeouts, int $nowNs): array
    {
        $base = $directory.'/';
        $out = $seen = [];
        $usable = $duplicates = 0;
        $earliest = PHP_INT_MAX;
        foreach ($names as $i => $name) {
            $path = $base.$name.'.lock';
            $length = \strlen($name);
            $flags = (int) ($length > 0 && $name !== '.' && $name !== '..' && strcspn($name, self::W50_UNSAFE) === $length)
                | (int) ($length + 5 <= 255) << 1 | (int) (\strlen($path) <= 1024) << 2;
            if (isset($seen[$name])) {
                $flags |= 8;
                $duplicates++;
            } else {
                $seen[$name] = true;
            }
            if ($length > 0 && $name[0] === '.') {
                $flags |= 16;
            }
            $seconds = max(0, $timeouts[$i] ?? 0);
            $span = $seconds > 9_223_372_036 ? PHP_INT_MAX : $seconds * 1_000_000_000;
            $deadline = $nowNs > PHP_INT_MAX - $span ? PHP_INT_MAX : $nowNs + $span;
            if (($flags & 0xF) === 7) {
                $flags |= 32;
                $usable++;
                $earliest = min($earliest, $deadline);
            }
            $out[] = $flags | min(\strlen($path), 0xFFFF) << 8 | crc32($path) << 32;
            $out[] = $deadline;
        }
        $out[] = $usable;
        $out[] = $duplicates;
        $out[] = $earliest;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * are not lists, or a name holds a NUL byte.
     *
     * @param  list<string>  $names
     * @param  list<int>  $timeouts
     * @return list<int>
     */
    public static function planLockFilesNative(string $directory, array $names, array $timeouts, int $nowNs): array
    {
        $count = \count($names);
        $ffi = $count === 0 ? null : self::w50RustFfiState('ffi');
        if (! $ffi instanceof FFI || \count($timeouts) !== $count || ! array_is_list($names) || ! array_is_list($timeouts)) {
            return self::planLockFilesPhp($directory, $names, $timeouts, $nowNs);
        }
        $joined = implode("\0", $names);
        $words = 2 * $count + 4;
        $values = false;
        // Rust rejects a column whose NUL count differs from $count - 1 (an embedded NUL byte).
        try {
            $out = self::w50RustFfiState('out', $words);
            if ($out instanceof CData) {
                $packed = pack('P*', ...$timeouts);
                // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
                $written = $ffi->nntmux_w502_file_lock_plan_batch($directory, \strlen($directory), $joined, \strlen($joined), $packed, \strlen($packed), $count, $nowNs, $out, self::w50RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::planLockFilesPhp($directory, $names, $timeouts, $nowNs);
    }
}
