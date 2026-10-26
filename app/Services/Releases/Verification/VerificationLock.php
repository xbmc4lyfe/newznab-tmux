<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** A held verification lock, with a small state file for its holder's fairness turns. */
final class VerificationLock
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

    /** @return array{name: string, timeout_seconds: int} */
    public static function normalizeLockSpec(string $name, int $timeoutSeconds = 0): array
    {
        return self::usesRustFfi() ? self::normalizeLockSpecNative($name, $timeoutSeconds) : self::normalizeLockSpecPhp($name, $timeoutSeconds);
    }

    /** @return array{name: string, timeout_seconds: int} */
    public static function normalizeLockSpecPhp(string $name, int $timeoutSeconds = 0): array
    {
        return ['name' => $name, 'timeout_seconds' => max(0, $timeoutSeconds)];
    }

    /** @return array{name: string, timeout_seconds: int} */
    public static function normalizeLockSpecNative(string $name, int $timeoutSeconds = 0): array
    {
        $result = self::additionalResult(3, [$name], first: $timeoutSeconds);

        return $result !== null && count($result) === 2 ? ['name' => $result[0], 'timeout_seconds' => (int) $result[1]] : self::normalizeLockSpecPhp($name, $timeoutSeconds);
    }

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

    // ========================================================================
    // WAVE 59.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs cleared every gate (common mean 2.69x, p99 2.20x; uncommon mean 1.74x, p99 1.58x); see RustVerificationLockWorkerLocksVerifiedArticleNzbWriterPublisherW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_FFI_CDEF = <<<'C'
        ssize_t nntmux_w594_lock_state_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per lockStateRowBatch() record. */
    public const int W59_ROW_WORDS = 6;

    /** int64 summary words after the lockStateRowBatch() records (none for an empty batch). */
    public const int W59_TRAILER_WORDS = 8;

    private const int W59_TIME_MAX = 1 << 42;

    private const string W59_KEY_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789:._-';

    private const string W59_HOST_BYTES = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.-';

    private const string W59_HEX = '0123456789abcdef';

    private const string W59_DIGITS = '0123456789';

    /**
     * Lock state rows for [name, owner, token, acquired ms, expires ms, now ms, held fence,
     * current fence] items. A name is valid at 1-191 bytes of `[A-Za-z0-9:._-]` (class 1 empty,
     * 2 too long, 3 invalid bytes) and has a kind: 1 `worker`, 2 `worker-N`, 3 `candidate-N`, 4
     * `publication-forwarder-<12 lowercase hex>-N`, 5 `gc`, `expiry` or `preparation`, else 0 (N
     * is 1-9 digits, -1 without one). An owner is `host:pid` (1-253 bytes of `[A-Za-z0-9.-]`
     * before the last ':', 1-9 digits after; class 1 empty, 2 malformed) and a token 32
     * lowercase hex digits (0), a lowercase UUID (1), empty (2) or other (3). Times clamp to
     * 0..2^42. Lease 0 free (no expiry), 1 held, 2 expired, 3 held but due for renewal (under a
     * third of its window left), 4 invalid window (acquired after expiry); fence 0 current, 1
     * stale, 2 ahead. Outcome 0 keep, 1 renew, 2 reclaim (free or expired), 3 fenced, 4
     * invalid (bad name, owner or token, invalid window or fence ahead).
     *
     * Rows [outcome | name class << 3 | kind << 5 | owner class << 8 | token class << 10 |
     * lease << 12 | fence << 15, name number, owner pid (-1 when malformed), remaining ms (held
     * leases), owner host length, name length], then the trailer [keep, renew, reclaim, fenced,
     * invalid, soonest held index (-1 when none), its remaining ms, FNV-1a digest of
     * `codes ^ remaining << 18`].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function lockStateRowBatch(array $items): array
    {
        return self::usesW59RustFfi() ? self::lockStateRowBatchNative($items) : self::lockStateRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function lockStateRowBatchPhp(array $items): array
    {
        self::w59Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, -1, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as $at => [$name, $owner, $token, $acquired, $expires, $now, $held, $current]) {
            $length = \strlen($name);
            $nameClass = $length === 0 ? 1 : ($length > 191 ? 2 : (strspn($name, self::W59_KEY_BYTES) === $length ? 0 : 3));
            [$kind, $number] = $nameClass === 0 ? self::w59LockKind($name) : [0, -1];
            [$ownerClass, $pid, $hostLength] = self::w59Holder($owner);
            $tokenClass = match (\strlen($token)) {
                0 => 2,
                32 => strspn($token, self::W59_HEX) === 32 ? 0 : 3,
                36 => $token[8] === '-' && $token[13] === '-' && $token[18] === '-' && $token[23] === '-'
                    && strspn($token, self::W59_HEX) === 8 && strspn($token, self::W59_HEX, 9) === 4 && strspn($token, self::W59_HEX, 14) === 4
                    && strspn($token, self::W59_HEX, 19) === 4 && strspn($token, self::W59_HEX, 24) === 12 ? 1 : 3,
                default => 3,
            };
            $acquired = max(0, min($acquired, self::W59_TIME_MAX));
            $expires = max(0, min($expires, self::W59_TIME_MAX));
            $now = max(0, min($now, self::W59_TIME_MAX));
            $lease = match (true) {
                $expires === 0 => 0,
                $acquired > $expires => 4,
                $expires <= $now => 2,
                ($expires - $now) * 3 < $expires - $acquired => 3,
                default => 1,
            };
            $holding = $lease === 1 || $lease === 3;
            $remaining = $holding ? $expires - $now : 0;
            $fence = $held === $current ? 0 : ($held < $current ? 1 : 2);
            $outcome = match (true) {
                $nameClass !== 0 || $ownerClass !== 0 || $tokenClass >= 2 || $lease === 4 || $fence === 2 => 4,
                $fence === 1 => 3,
                $lease === 0 || $lease === 2 => 2,
                $lease === 3 => 1,
                default => 0,
            };
            $codes = $outcome | $nameClass << 3 | $kind << 5 | $ownerClass << 8 | $tokenClass << 10 | $lease << 12 | $fence << 15;
            array_push($out, $codes, $number, $pid, $remaining, $hostLength, $length);
            $summary[$outcome]++;
            if ($holding && ($summary[5] < 0 || $remaining < $summary[6])) {
                $summary[5] = $at;
                $summary[6] = $remaining;
            }
            $value = $codes ^ ($remaining << 18);
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function lockStateRowBatchNative(array $items): array
    {
        self::w59Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w59Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7));

        return self::w59Call($blob, $twin, $ints, $count, $count * self::W59_ROW_WORDS + self::W59_TRAILER_WORDS)
            ?? self::lockStateRowBatchPhp($items);
    }

    /** @return array{0: int, 1: int} [kind, number] */
    private static function w59LockKind(string $name): array
    {
        if ($name === 'worker') {
            return [1, -1];
        }
        if ($name === 'gc' || $name === 'expiry' || $name === 'preparation') {
            return [5, -1];
        }
        if (str_starts_with($name, 'worker-')) {
            [$kind, $at] = [2, 7];
        } elseif (str_starts_with($name, 'candidate-')) {
            [$kind, $at] = [3, 10];
        } elseif (str_starts_with($name, 'publication-forwarder-') && \strlen($name) > 35
            && strspn($name, self::W59_HEX, 22, 12) === 12 && $name[34] === '-') {
            [$kind, $at] = [4, 35];
        } else {
            return [0, -1];
        }
        $digits = \strlen($name) - $at;

        return $digits >= 1 && $digits <= 9 && strspn($name, self::W59_DIGITS, $at) === $digits ? [$kind, (int) substr($name, $at)] : [0, -1];
    }

    /** @return array{0: int, 1: int, 2: int} [class, pid, host length] of `host:pid` */
    private static function w59Holder(string $owner): array
    {
        if ($owner === '') {
            return [1, -1, 0];
        }
        $p = strrpos($owner, ':');
        if ($p === false || $p === 0 || $p > 253 || strspn($owner, self::W59_HOST_BYTES, 0, $p) !== $p) {
            return [2, -1, 0];
        }
        $digits = \strlen($owner) - $p - 1;

        return $digits >= 1 && $digits <= 9 && strspn($owner, self::W59_DIGITS, $p + 1) === $digits ? [0, (int) substr($owner, $p + 1), $p] : [2, -1, 0];
    }

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W59 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('enabled');
        self::w59RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $items */
    private static function w59Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W59 lock state batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 8 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1])
                || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])
                || ! \is_int($item[7])) {
                throw new InvalidArgumentException('W59 lock state batch items must be [string name, string owner, string token, int acquired, int expires, int now, int held fence, int current fence] lists.');
            }
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w59Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w59Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w59RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w59', self::W59_FFI_CDEF) ?? false;
            self::w59RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w59RustFfiState('out', $out);
                self::w59RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W59_FFI_CDEF.)
            $written = $ffi->nntmux_w594_lock_state_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w59RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W59 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }
}
