<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
}
