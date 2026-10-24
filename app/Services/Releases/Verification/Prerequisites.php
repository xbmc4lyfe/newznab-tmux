<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

final class Prerequisites
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w19_verification_prereqs(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Enable only after paired common/uncommon mean and p99 all reach 1.5x.
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
    private static function computeNative(int $operation, array $arguments): ?array
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
            $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            if (is_int($written) && $written < -1 && $written >= -(64 * 1024 * 1024)) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
                $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            }
            if (! is_int($written) || $written < 2 || $written > $capacity || $written > 64 * 1024 * 1024) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (\Throwable) {
            return null;
        }
    }

    public function check(bool $required, string $par2Binary): void
    {
        if (! $required) {
            return;
        }
        $available = self::isExplicitPath($par2Binary)
            ? is_file($par2Binary) && is_executable($par2Binary)
            : (new ExecutableFinder)->find($par2Binary) !== null;
        if (! $available) {
            throw new RuntimeException('Release verification requires an executable PAR2 binary. Install par2 or configure RELEASE_VERIFICATION_PAR2_BINARY.');
        }
    }

    public static function isExplicitPath(string $binary): bool
    {
        return self::$useRustFfi ? (self::isExplicitPathNative($binary) ?? self::isExplicitPathPhp($binary)) : self::isExplicitPathPhp($binary);
    }

    public static function isExplicitPathPhp(string $binary): bool
    {
        return str_contains($binary, '/') || str_contains($binary, '\\');
    }

    public static function isExplicitPathNative(string $binary): ?bool
    {
        $result = self::computeNative(20, [$binary])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }
}
