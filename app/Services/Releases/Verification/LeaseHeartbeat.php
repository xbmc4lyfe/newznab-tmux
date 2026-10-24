<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;

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
}
