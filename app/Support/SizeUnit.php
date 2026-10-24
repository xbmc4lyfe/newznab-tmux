<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use InvalidArgumentException;
use Throwable;

/**
 * Converts release-size settings between bytes (storage format) and the
 * human-selectable units (MB / GB) shown on the admin settings pages.
 */
class SizeUnit
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_size_unit_convert(const char *value_str, size_t value_len, const char *unit_str, size_t unit_len, uint32_t mode, char *out, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?FFI\CData $scratch = null;

    private static int $scratchCap = 0;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function scratch(FFI $ffi, int $minBytes): ?FFI\CData
    {
        if (self::$scratch === null || self::$scratchCap < $minBytes) {
            self::$scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, self::$scratchCap);
        }

        return self::$scratch;
    }

    public const int MB = 1048576;

    public const int GB = 1073741824;

    public const array UNITS = ['MB', 'GB'];

    /**
     * Site settings whose values are release sizes stored in bytes.
     *
     * @var list<string>
     */
    public const array SITE_SIZE_SETTINGS = [
        'minsizetoformrelease',
        'maxsizetoformrelease',
        'minsizetopostprocess',
        'maxsizetopostprocess',
        'minsizetoprocessnfo',
        'maxsizetoprocessnfo',
    ];

    /**
     * Convert a value expressed in the given unit to bytes.
     *
     * Empty and non-positive values map to 0 ("disabled" semantics).
     */
    public static function toBytes(int|float|string|null $value, string $unit): int
    {
        return (self::$useRustFfi ? self::toBytesNative($value, $unit) : null)
            ?? self::toBytesPhp($value, $unit);
    }

    public static function toBytesPhp(int|float|string|null $value, string $unit): int
    {
        $multiplier = match (strtoupper($unit)) {
            'MB' => self::MB,
            'GB' => self::GB,
            default => throw new InvalidArgumentException("Unsupported size unit [$unit]."),
        };

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return 0;
        }

        return max(0, (int) round((float) $value * $multiplier));
    }

    public static function toBytesNative(int|float|string|null $value, string $unit, bool $allowExtendedUnits = false): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $valueStr = match (true) {
            $value === null || $value === '' => '',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? sprintf('%.17g', $value) : 'NaN',
            default => $value,
        };
        try {
            $scratch = self::scratch($ffi, 64);
            if ($scratch === null) {
                return null;
            }
            $cap = self::$scratchCap;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_size_unit_convert(
                $valueStr,
                strlen($valueStr),
                $unit,
                strlen($unit),
                $allowExtendedUnits ? 2 : 0,
                $scratch,
                $cap
            );
            if ($written === -2) {
                throw new InvalidArgumentException("Unsupported size unit [$unit].");
            }
            if (! is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }

            return (int) FFI::string($scratch, $written);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Split a byte count into a value + unit pair for display.
     *
     * GB is preferred when the value divides evenly into gibibytes, otherwise
     * MB is used (rounded to two decimals when not evenly divisible).
     *
     * @return array{value: int|float, unit: string}
     */
    public static function fromBytes(int|float|string|null $bytes): array
    {
        return (self::$useRustFfi ? self::fromBytesNative($bytes) : null)
            ?? self::fromBytesPhp($bytes);
    }

    /**
     * @return array{value: int|float, unit: string}
     */
    public static function fromBytesPhp(int|float|string|null $bytes): array
    {
        $bytes = is_numeric($bytes) ? (int) $bytes : 0;

        if ($bytes <= 0) {
            return ['value' => 0, 'unit' => 'MB'];
        }

        if ($bytes % self::GB === 0) {
            return ['value' => intdiv($bytes, self::GB), 'unit' => 'GB'];
        }

        if ($bytes % self::MB === 0) {
            return ['value' => intdiv($bytes, self::MB), 'unit' => 'MB'];
        }

        return ['value' => round($bytes / self::MB, 2), 'unit' => 'MB'];
    }

    /**
     * @return array{value: int|float, unit: string}|null
     */
    public static function fromBytesNative(int|float|string|null $bytes): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $valueStr = match (true) {
            $bytes === null || $bytes === '' => '',
            is_int($bytes) => (string) $bytes,
            is_float($bytes) => is_finite($bytes) ? (string) (int) $bytes : 'NaN',
            default => $bytes,
        };
        try {
            $scratch = self::scratch($ffi, 64);
            if ($scratch === null) {
                return null;
            }
            $cap = self::$scratchCap;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_size_unit_convert(
                $valueStr,
                strlen($valueStr),
                '',
                0,
                1,
                $scratch,
                $cap
            );
            if (! is_int($written) || $written < 6 || $written > $cap) {
                return null;
            }
            $raw = FFI::string($scratch, $written);
            if (str_starts_with($raw, 'F:') && $written === 13) {
                /** @var array{1: float}|false $unpacked */
                $unpacked = unpack('d', substr($raw, 2, 8));
                if ($unpacked === false) {
                    return null;
                }

                return ['value' => $unpacked[1], 'unit' => substr($raw, 11, 2)];
            }
            if (str_starts_with($raw, 'I:')) {
                $parts = explode(':', $raw, 3);
                if (count($parts) === 3) {
                    return ['value' => (int) $parts[1], 'unit' => $parts[2]];
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
