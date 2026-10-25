<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * A publication writes as a worker does, so a reset, audit or deployment barrier that holds the
 * worker group excludes it even after the sending worker has gone. One request takes the shared
 * lock for each publication; a batch keeps one acquisition for all of its items and proves, before
 * each, that the lock is still held and no pause has been written. Once a retained gate is
 * refused, it stays refused for the rest of its life.
 */
final class PublicationWorkerGate
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

    /** @return array{joined: bool, join_depth: int, acquire: bool} */
    public static function classifyJoinTransition(int $joinDepth, bool $lockAcquired): array
    {
        return self::usesRustFfi() ? self::classifyJoinTransitionNative($joinDepth, $lockAcquired) : self::classifyJoinTransitionPhp($joinDepth, $lockAcquired);
    }

    /** @return array{joined: bool, join_depth: int, acquire: bool} */
    public static function classifyJoinTransitionPhp(int $joinDepth, bool $lockAcquired): array
    {
        $depth = max(0, $joinDepth);
        $joined = $depth > 0 || $lockAcquired;

        return ['joined' => $joined, 'join_depth' => $joined ? $depth + 1 : 0, 'acquire' => $depth === 0];
    }

    /** @return array{joined: bool, join_depth: int, acquire: bool} */
    public static function classifyJoinTransitionNative(int $joinDepth, bool $lockAcquired): array
    {
        $result = self::additionalResult(4, first: $joinDepth, flag: $lockAcquired);

        return $result !== null && count($result) === 3 ? ['joined' => $result[0] === '1', 'join_depth' => (int) $result[1], 'acquire' => $result[2] === '1'] : self::classifyJoinTransitionPhp($joinDepth, $lockAcquired);
    }

    private ?VerificationLockStore $store = null;

    private ?VerificationLock $lock = null;

    private bool $refused = false;

    public function __construct(private readonly bool $retain = false) {}

    /** Whether the caller may write now; false means retry later. */
    public function enter(): bool
    {
        if ($this->refused) {
            return false;
        }
        $this->store ??= VerificationLockStore::make();
        if ($this->lock !== null && ! $this->store->holds($this->lock)) {
            // A database lock ends with its session; protection must be proved, not assumed.
            // Dropping it clears the store's bookkeeping before the group is joined afresh.
            $lost = $this->lock;
            $this->lock = null;
            $this->store->release($lost);
        }
        $this->lock ??= $this->store->shared('worker');
        if ($this->lock !== null && VerificationWorkerLocks::paused()) {
            $this->store->release($this->lock);
            $this->lock = null;
        }
        if (! self::classifyJoinTransition(0, $this->lock !== null)['joined']) {
            $this->refused = $this->retain;

            return false;
        }

        return true;
    }

    /** After one publication: a single request lets go; a batch keeps its acquisition. */
    public function leave(): void
    {
        if (! $this->retain) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->lock !== null && $this->store !== null) {
            $this->store->release($this->lock);
        }
        $this->lock = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
