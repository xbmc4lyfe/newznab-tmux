<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;

/** Coalesce progress callbacks without weakening forced ownership checks at publication. */
final class LeaseHeartbeat
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w20_verification_helpers(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Single-call FFI remains opt-in until every paired mean/p99 gate reaches 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Decline objects/resources/cycles before serialize() could invoke user code. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array{value: mixed}|null An envelope distinguishes a native null from failure.
     */
    public static function computeNative(int $operation, array $arguments): ?array
    {
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w20_verification_helpers($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && $written >= -(64 * 1024 * 1024)) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
                $written = $ffi->nntmux_w20_verification_helpers($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written < 2 || $written > $capacity || $written > 64 * 1024 * 1024) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (\Throwable) {
            return null;
        }
    }

    private ?float $lastRenewal = null;

    private readonly Closure $renew;

    private readonly Closure $clock;

    public function __construct(callable $renew, private readonly float $intervalSeconds, ?Closure $clock = null)
    {
        $this->renew = Closure::fromCallable($renew);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function __invoke(): void
    {
        $now = ($this->clock)();
        if (! self::renewalDue($this->lastRenewal, $now, $this->intervalSeconds)) {
            return;
        }
        ($this->renew)();
        $this->lastRenewal = $now;
    }

    public static function renewalDue(?float $lastRenewal, float $now, float $intervalSeconds): bool
    {
        return self::$useRustFfi ? (self::renewalDueNative($lastRenewal, $now, $intervalSeconds) ?? self::renewalDuePhp($lastRenewal, $now, $intervalSeconds)) : self::renewalDuePhp($lastRenewal, $now, $intervalSeconds);
    }

    public static function renewalDuePhp(?float $lastRenewal, float $now, float $intervalSeconds): bool
    {
        return $lastRenewal === null || ($now - $lastRenewal) >= $intervalSeconds;
    }

    public static function renewalDueNative(?float $lastRenewal, float $now, float $intervalSeconds): ?bool
    {
        $result = self::computeNative(0, [$lastRenewal, $now, $intervalSeconds])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    /** Wave 50.2 paired benchmarks decide this default; see RustVerificationDbLockDirtyFetchedFileLockHeartbeatW50ParityTest. */
    private const W50_RUST_FFI_DEFAULT = true;

    public const string W50_FFI_CDEF = <<<'C'
        intptr_t nntmux_w502_lease_heartbeat_batch(const char *ticks, size_t ticks_len, size_t count, int64_t has_last, double last, double interval, int64_t *out, size_t out_cap);
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
     * Runs $callback with planRenewals() routed to Rust ($enabled) or PHP, then
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
     * Replays __invoke()'s renewal decisions over a run of clock readings. One flag nibble per
     * tick, packed sixteen to a word (tick i at bits 4 * (i % 16) of word i / 16; flags:
     * 1 renewal due and taken, 2 clock moved backwards, 4 non-finite reading, 8 stall: a whole
     * interval passed since the previous reading). The renewal and stall counts, whether a last
     * renewal exists, its IEEE-754 bits (0 for none) and the XXH64 digest of all earlier words
     * follow.
     *
     * @param  list<float>  $ticks
     * @return list<int>
     */
    public static function planRenewals(?float $lastRenewal, array $ticks, float $intervalSeconds): array
    {
        return self::usesW50RustFfi() ? self::planRenewalsNative($lastRenewal, $ticks, $intervalSeconds)
            : self::planRenewalsPhp($lastRenewal, $ticks, $intervalSeconds);
    }

    /**
     * @param  list<float>  $ticks
     * @return list<int>
     */
    public static function planRenewalsPhp(?float $lastRenewal, array $ticks, float $intervalSeconds): array
    {
        $out = $ticks === [] ? [] : array_fill(0, (\count($ticks) + 15) >> 4, 0);
        $last = $lastRenewal;
        $previous = null;
        $count = $stalls = $k = 0;
        foreach ($ticks as $tick) {
            $now = (float) $tick;
            $flags = 0;
            if ($last === null || ($now - $last) >= $intervalSeconds) {
                $flags = 1;
                $last = $now;
                $count++;
            }
            if ($previous !== null) {
                if ($now < $previous) {
                    $flags |= 2;
                }
                if (($now - $previous) >= $intervalSeconds) {
                    $flags |= 8;
                    $stalls++;
                }
            }
            if (! is_finite($now)) {
                $flags |= 4;
            }
            $previous = $now;
            $out[$k >> 4] |= $flags << (($k & 15) << 2);
            $k++;
        }
        $out[] = $count;
        $out[] = $stalls;
        $out[] = (int) ($last !== null);
        /** @var array{1: int} $bits */
        $bits = unpack('P', pack('e', $last ?? 0.0));
        $out[] = $last === null ? 0 : $bits[1];
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or $ticks is not a list.
     *
     * @param  list<float>  $ticks
     * @return list<int>
     */
    public static function planRenewalsNative(?float $lastRenewal, array $ticks, float $intervalSeconds): array
    {
        $count = \count($ticks);
        $ffi = $count === 0 ? null : self::w50RustFfiState('ffi');
        if (! $ffi instanceof FFI || ! array_is_list($ticks)) {
            return self::planRenewalsPhp($lastRenewal, $ticks, $intervalSeconds);
        }
        $words = (($count + 15) >> 4) + 5;
        $values = false;
        try {
            $out = self::w50RustFfiState('out', $words);
            if ($out instanceof CData) {
                $packed = pack('e*', ...$ticks);
                // @phpstan-ignore method.notFound (Declared by W50_FFI_CDEF.)
                $written = $ffi->nntmux_w502_lease_heartbeat_batch($packed, \strlen($packed), $count, (int) ($lastRenewal !== null), $lastRenewal ?? 0.0, $intervalSeconds, $out, self::w50RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (\Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::planRenewalsPhp($lastRenewal, $ticks, $intervalSeconds);
    }
}
