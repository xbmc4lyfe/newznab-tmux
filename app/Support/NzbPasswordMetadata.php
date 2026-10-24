<?php

declare(strict_types=1);

namespace App\Support;

use FFI;

final class NzbPasswordMetadata
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_nzb_password_metadata_valid(const char *data, size_t data_len, const char *lens, size_t count);
        C;

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public static function isValid(mixed $values): bool
    {
        return self::isValidPhp($values);
    }

    public static function isValidPhp(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > 64) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_string($value) || strlen($value) > 4096
                || preg_match('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** Rust candidate for {@see isValidPhp()}; null when the library is unavailable. */
    public static function isValidNative(mixed $values): ?bool
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }
        if (! is_array($values) || ! array_is_list($values) || count($values) > 64) {
            return false;
        }
        $lengths = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                return false;
            }
            $lengths[] = \strlen($value);
        }
        $data = implode('', $values);
        $packed = pack('Q*', ...$lengths);
        // @phpstan-ignore method.notFound
        $code = $ffi->nntmux_nzb_password_metadata_valid($data, \strlen($data), $packed, \count($lengths));

        return $code < 0 ? null : $code === 1;
    }
}
