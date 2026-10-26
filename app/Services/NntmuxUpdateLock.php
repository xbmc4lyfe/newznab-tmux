<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

#[Singleton]
class NntmuxUpdateLock
{
    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_w336_locks_uptime(uint8_t operation, const char *input, size_t input_len, int64_t first, int64_t second, double value, uint8_t flag, uint8_t *out, size_t out_cap);
CDEF;

    // Single-call FFI must qualify on every common mean and uncommon p99 workload.
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Wave 48.2 production default for evaluateLockBatch() is Rust: the paired benchmark in
     * tests/Unit/RustPredbEntryFactoryBoundedHttpUpdateLockW48ParityTest.php measures 1.97-2.17x mean and 1.97-2.61x p99 on common batches and 2.75-3.05x mean and 2.50-3.24x p99 on uncommon batches.
     * PHP stays the fallback when the library is unavailable or a payload holds a NUL byte.
     */
    public const bool W48_LOCK_RUST_FFI_DEFAULT = true;

    public const string W48_FFI_CDEF = <<<'C'
        ssize_t nntmux_w48_update_lock_batch(const char *blob, size_t blob_len, const char *aux, size_t aux_len, int64_t now, int64_t ttl_seconds, char *out_buf, size_t out_cap);
        C;

    /** Largest timestamp the Wave 48.2 lock evaluator accepts, so every age stays exact. */
    private const int W48_LOCK_MAX_SPAN = 1 << 40;

    /** Lock files may start up to this many seconds in the future (clock skew) before they count as future. */
    private const int W48_LOCK_SKEW = 300;

    /** Longest per-lock ttl override honoured, in seconds. */
    private const int W48_LOCK_MAX_TTL = 604800;

    private const array W48_LOCK_KEYS = ['total', 'invalid_json', 'missing_pid', 'invalid_pid', 'missing_started_at', 'invalid_started_at', 'future',
        'stale', 'own', 'held', 'custom_ttl', 'with_host', 'distinct_hosts', 'max_age', 'min_remaining', 'payload_bytes', 'digest'];

    private static ?bool $w48LockRustFfiOverride = null;

    private static FFI|false|null $w48LockRustFfiHandle = null;

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

    public static function formatWaitingMessage(string $commandName, float $elapsedSeconds): string
    {
        return self::usesRustFfi() ? self::formatWaitingMessageNative($commandName, $elapsedSeconds) : self::formatWaitingMessagePhp($commandName, $elapsedSeconds);
    }

    public static function formatWaitingMessagePhp(string $commandName, float $elapsedSeconds): string
    {
        return sprintf('Waiting for %s (%.1fs elapsed)...', $commandName, $elapsedSeconds);
    }

    public static function formatWaitingMessageNative(string $commandName, float $elapsedSeconds): string
    {
        if (localeconv()['decimal_point'] !== '.') {
            return self::formatWaitingMessagePhp($commandName, $elapsedSeconds);
        }

        return self::additionalResult(5, [$commandName], value: $elapsedSeconds)[0] ?? self::formatWaitingMessagePhp($commandName, $elapsedSeconds);
    }

    /** Force the Wave 48.2 lock evaluator onto Rust (true) or PHP (false); null restores W48_LOCK_RUST_FFI_DEFAULT. */
    public static function useW48LockRustFfi(?bool $enable = true): void
    {
        self::$w48LockRustFfiOverride = $enable;
    }

    public static function usesW48LockRustFfi(): bool
    {
        return self::$w48LockRustFfiOverride ?? self::W48_LOCK_RUST_FFI_DEFAULT;
    }

    public static function resetW48LockRustFfi(): void
    {
        self::$w48LockRustFfiOverride = null;
        self::$w48LockRustFfiHandle = null;
    }

    /**
     * Evaluate a batch of JSON lock-file payloads (json_decode() depth 16) for $currentPid at $now (clamped to 0..2^40)
     * with a default $ttlSeconds (clamped to 1..2^31) that a payload's own ttl of 1-604800 seconds overrides: invalid
     * JSON, missing or invalid pid and started_at, locks started over 300 seconds in the future, stale locks, locks this
     * process owns and locks held by another, distinct hosts, the oldest age and the shortest remaining hold, payload
     * bytes and an FNV-1a digest of every payload's status, pid and age in order.
     *
     * @param  list<string>  $payloads
     * @return array<string, int>
     */
    public static function evaluateLockBatch(array $payloads, int $currentPid, int $now, int $ttlSeconds = 3600): array
    {
        return (self::usesW48LockRustFfi() ? self::evaluateLockBatchNative($payloads, $currentPid, $now, $ttlSeconds) : null)
            ?? self::evaluateLockBatchPhp($payloads, $currentPid, $now, $ttlSeconds);
    }

    /**
     * @param  list<string>  $payloads
     * @return array<string, int>
     */
    public static function evaluateLockBatchPhp(array $payloads, int $currentPid, int $now, int $ttlSeconds = 3600): array
    {
        $now = max(0, min(self::W48_LOCK_MAX_SPAN, $now));
        $ttl = max(1, min(1 << 31, $ttlSeconds));
        $c = array_fill(0, \count(self::W48_LOCK_KEYS), 0);
        $c[13] = $c[14] = -1;
        $hosts = [];
        $stream = '';
        foreach ($payloads as $payload) {
            $c[0]++;
            $c[15] += \strlen($payload);
            $decoded = json_decode($payload, true, 16);
            if (! \is_array($decoded)) {
                $c[1]++;
                $stream .= pack('Cx16', 0);

                continue;
            }
            $pid = $decoded['pid'] ?? null;
            $started = $decoded['started_at'] ?? null;
            $pidValue = $age = 0;
            if ($pid === null) {
                $status = 1;
            } elseif (! \is_int($pid) || $pid < 1) {
                $status = 2;
            } elseif ($started === null) {
                $status = 3;
                $pidValue = $pid;
            } elseif (! \is_int($started) || $started < 1 || $started > self::W48_LOCK_MAX_SPAN) {
                $status = 4;
                $pidValue = $pid;
            } else {
                $pidValue = $pid;
                $effective = $ttl;
                $custom = $decoded['ttl'] ?? null;
                if (\is_int($custom) && $custom >= 1 && $custom <= self::W48_LOCK_MAX_TTL) {
                    $c[10]++;
                    $effective = $custom;
                }
                if ($started > $now + self::W48_LOCK_SKEW) {
                    $status = 5;
                } else {
                    $age = $now - $started;
                    $c[13] = max($c[13], $age);
                    if ($age > $effective) {
                        $status = 6;
                    } elseif ($pid === $currentPid) {
                        $status = 7;
                    } else {
                        $remaining = $effective - $age;
                        $c[14] = $c[14] < 0 ? $remaining : min($c[14], $remaining);
                        $status = 8;
                    }
                }
            }
            $c[$status + 1]++;
            $host = $decoded['host'] ?? null;
            if (\is_string($host) && ($host = trim($host)) !== '') {
                $c[11]++;
                $hosts[$host] = true;
            }
            $stream .= pack('CPP', $status, $pidValue, $age);
        }
        $c[12] = \count($hosts);
        $c[16] = PredbFeedEntry::w48Fnv($stream);

        return array_combine(self::W48_LOCK_KEYS, $c);
    }

    /**
     * Null when the library is missing, a payload holds a NUL byte or the batch exceeds the native limits.
     *
     * @param  list<string>  $payloads
     * @return array<string, int>|null
     */
    public static function evaluateLockBatchNative(array $payloads, int $currentPid, int $now, int $ttlSeconds = 3600): ?array
    {
        $count = \count($payloads);
        $ffi = self::w48LockRustFfi();
        if ($ffi === null || $count > PredbFeedEntry::W48_MAX_ITEMS) {
            return null;
        }
        $values = PredbFeedEntry::callW48Native($ffi, 'nntmux_w48_update_lock_batch', implode("\0", $payloads), pack('q2', $currentPid, $count),
            $now, $ttlSeconds, \count(self::W48_LOCK_KEYS));
        if ($values === null || $values[0] !== $count || array_sum(\array_slice($values, 1, 9)) !== $count) {
            return null;
        }

        return array_combine(self::W48_LOCK_KEYS, $values);
    }

    private static function w48LockRustFfi(): ?FFI
    {
        self::$w48LockRustFfiHandle ??= RustPipelineLibrary::resolveDomain(PredbFeedEntry::W48_FFI_DOMAIN, self::W48_FFI_CDEF) ?? false;

        return self::$w48LockRustFfiHandle === false ? null : self::$w48LockRustFfiHandle;
    }

    /** @var resource|null */
    private $lock = null;

    /**
     * Hold the installation lock across all update steps, including nested Git updates.
     *
     * @param  Closure(): int  $update
     */
    public function run(Closure $update, bool $checkGit = true, int $gitLockTimeout = 10): int
    {
        if (is_resource($this->lock)) {
            if ($checkGit) {
                $this->ensureGitAvailable($gitLockTimeout);
            }

            return $update();
        }

        File::ensureDirectoryExists(storage_path('framework'));
        $lock = fopen(storage_path('framework/nntmux-update.lock'), 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open the NNTmux update lock.');
        }

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another NNTmux update is already running. Retry after it finishes.');
            }

            $this->lock = $lock;

            if ($checkGit) {
                $this->ensureGitAvailable($gitLockTimeout);
            }

            return $update();
        } finally {
            if ($this->lock === $lock) {
                flock($lock, LOCK_UN);
            }
            $this->lock = null;
            fclose($lock);
        }
    }

    public function ensureGitAvailable(int $timeout): void
    {
        $result = Process::path(base_path())->run(['git', 'rev-parse', '--path-format=absolute', '--git-path', 'index.lock']);
        $path = trim($result->output());

        if (! $result->successful() || $path === '') {
            throw new RuntimeException('Unable to locate the Git index lock: '.$result->errorOutput());
        }

        $deadline = hrtime(true) + max(0, $timeout) * 1_000_000_000;
        while (true) {
            clearstatcache(true, $path);
            if (! File::exists($path)) {
                return;
            }

            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Git index is locked at '.$path.'. Wait for the active Git process to finish. If a process crashed, verify no Git process is using this repository before manually removing the stale lock and retrying.');
            }

            usleep(100_000);
        }
    }

    // ========================================================================
    // WAVE 61.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of four paired runs reached 2.25x common mean, 2.07x common p99, 1.92x uncommon mean and 1.83x uncommon p99; see RustUpdateLockPasswordBreachAniListRecaptchaRegistrationLogW61ParityTest. */
    public const bool W61_RUST_FFI_DEFAULT = true;

    private const string W61_UPDATE_LOCK_FFI_CDEF = 'ssize_t nntmux_w614_update_lock_plan_row_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per updateLockPlanRowBatch() record. */
    public const int W61_ROW_WORDS = 6;

    /** int64 summary words after the updateLockPlanRowBatch() records (none for an empty batch). */
    public const int W61_TRAILER_WORDS = 8;

    /** Upper clamp for epoch-second timestamps. */
    private const int W61_TIME_MAX = 1 << 40;

    private const int W61_MAX_TTL = 604_800;

    private const int W61_DEFAULT_TTL = 3600;

    /** Lock files may start up to this many seconds in the future (clock skew). */
    private const int W61_SKEW = 300;

    private const int W61_PID_MAX = 4_194_304;

    private const array W61_COMMANDS = ['nntmux:all' => 1, 'nntmux:git' => 2, 'migrate' => 3, 'optimize:clear' => 4];

    private const string W61_LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const string W61_COMMAND_BYTES = 'abcdefghijklmnopqrstuvwxyz0123456789:_-';

    private const string W61_HOST_BYTES = 'abcdefghijklmnopqrstuvwxyz0123456789.-';

    /**
     * Update lock plan rows for [command, hostname, pid, started at, ttl, now, current pid]
     * items (times are epoch seconds; now clamps to 0..2^40).
     *
     * The command name ends at the first space: 0 empty, 1 `nntmux:all`, 2 `nntmux:git`, 3
     * `migrate`, 4 `optimize:clear`, 14 another `[a-z][a-z0-9:_-]{0,63}` name, 15 otherwise;
     * options count ` --` occurrences. Hostnames are trimmed and lowercased: 0 empty, 1 a
     * 1-253 byte `[a-z0-9.-]` name without a leading or trailing '.'/'-' or '..', 2 otherwise,
     * hashed with FNV-1a 32 (0 when empty). A ttl of 1-604800 seconds overrides the 3600
     * default. Status: 1 pid outside 1..4194304, 2 started at outside 1..2^40, 3 started over
     * 300 seconds in the future, 4 stale (age > ttl), 5 held by the current pid, 6 contended.
     *
     * Rows [status | command << 4 | host << 8 | flags << 12 (1 custom ttl, 2 options, 4 within
     * the last tenth of the ttl, 8 skewed start) | options << 16 | name bytes << 24, pid (-1),
     * age, remaining (-1), expires at (-1), host hash], then the trailer [invalid, future,
     * stale, own, contended, max age, min contended remaining (-1), FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function updateLockPlanRowBatch(array $items): array
    {
        return self::usesW61RustFfi() ? self::updateLockPlanRowBatchNative($items) : self::updateLockPlanRowBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function updateLockPlanRowBatchPhp(array $items): array
    {
        self::w61Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, -1, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$command, $hostname, $pid, $started, $ttl, $now, $current]) {
            if ($command === '') {
                $commandClass = $nameLength = 0;
            } else {
                $nameLength = strcspn($command, ' ');
                $name = substr($command, 0, $nameLength);
                $commandClass = self::W61_COMMANDS[$name] ?? ($nameLength >= 1 && $nameLength <= 64 && strspn($name, self::W61_LOWER, 0, 1) === 1
                    && strspn($name, self::W61_COMMAND_BYTES) === $nameLength ? 14 : 15);
            }
            $options = substr_count($command, ' --');
            $host = strtolower(trim($hostname));
            $hostLength = \strlen($host);
            if ($hostLength === 0) {
                $hostClass = $hostHash = 0;
            } else {
                $hostClass = $hostLength <= 253 && strspn($host, self::W61_HOST_BYTES) === $hostLength && $host[0] !== '.' && $host[0] !== '-'
                    && $host[$hostLength - 1] !== '.' && $host[$hostLength - 1] !== '-' && ! str_contains($host, '..') ? 1 : 2;
                /** @var array{1: int} $hash */
                $hash = unpack('N', hash('fnv1a32', $host, true));
                $hostHash = $hash[1];
            }
            $now = max(0, min(self::W61_TIME_MAX, $now));
            $custom = $ttl >= 1 && $ttl <= self::W61_MAX_TTL;
            $effective = $custom ? $ttl : self::W61_DEFAULT_TTL;
            $flags = ($custom ? 1 : 0) | ($options > 0 ? 2 : 0);
            $pidOk = $pid >= 1 && $pid <= self::W61_PID_MAX;
            $age = 0;
            $remaining = $expires = -1;
            if (! $pidOk) {
                $status = 1;
            } elseif ($started < 1 || $started > self::W61_TIME_MAX) {
                $status = 2;
            } elseif ($started > $now + self::W61_SKEW) {
                $status = 3;
            } else {
                if ($started > $now) {
                    $flags |= 8;
                }
                $age = max(0, $now - $started);
                $expires = $started + $effective;
                if ($age > $effective) {
                    $status = 4;
                } else {
                    $remaining = $effective - $age;
                    if ($remaining * 10 <= $effective) {
                        $flags |= 4;
                    }
                    $status = $pid === $current ? 5 : 6;
                }
            }
            $word = $status | $commandClass << 4 | $hostClass << 8 | $flags << 12 | min(255, $options) << 16 | min(255, $nameLength) << 24;
            array_push($out, $word, $pidOk ? $pid : -1, $age, $remaining, $expires, $hostHash);
            if ($status <= 2) {
                $summary[0]++;
            } elseif ($status === 6) {
                $summary[4]++;
                $summary[6] = $summary[6] < 0 ? $remaining : min($summary[6], $remaining);
            } else {
                $summary[$status - 2]++;
            }
            $summary[5] = max($summary[5], $age);
            $value = $word ^ $age << 20 ^ $remaining;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[7] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int, 6: int}>  $items
     * @return list<int>
     */
    public static function updateLockPlanRowBatchNative(array $items): array
    {
        self::w61Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w61Join([array_column($items, 0), array_column($items, 1)], $count);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6));

        return self::w61Call($blob, $twin, $ints, $count, $count * self::W61_ROW_WORDS + self::W61_TRAILER_WORDS)
            ?? self::updateLockPlanRowBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w61Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W61 update lock plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 7 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6])) {
                throw new InvalidArgumentException('W61 update lock plan batch items must be [string command, string hostname, int pid, int started at, int ttl, int now, int current pid] lists.');
            }
        }
    }

    public static function usesW61RustFfi(): bool
    {
        $enabled = self::w61RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W61_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W61 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('enabled');
        self::w61RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w61RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w61Join(array $columns, int $count): array
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
    private static function w61Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w61RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w61', self::W61_UPDATE_LOCK_FFI_CDEF) ?? false;
            self::w61RustFfiState('ffi', $ffi);
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
                self::w61RustFfiState('out', $out);
                self::w61RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W61_UPDATE_LOCK_FFI_CDEF.)
            $written = $ffi->nntmux_w614_update_lock_plan_row_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w61RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W61 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static: no arguments returns the state, one argument reads a key ('reset'
     * clears everything) and two arguments write a key.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
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
