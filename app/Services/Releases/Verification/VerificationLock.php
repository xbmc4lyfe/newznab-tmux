<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
