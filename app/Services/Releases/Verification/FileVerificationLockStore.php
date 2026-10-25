<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
