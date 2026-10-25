<?php

declare(strict_types=1);

namespace App\Services;

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
