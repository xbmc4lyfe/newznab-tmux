<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Throwable;

/**
 * Small UTF-8 conversion helper used in hot header-processing paths.
 */
final class Utf8
{
    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_utf8_is_valid(const char *input, size_t len);
        C;

    /** @var list<string>|null */
    private static ?array $encodings = null;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    public static function clean(string $value): string
    {
        // Retain native mb_check_encoding() in production: PHP caches IS_STR_VALID_UTF8 on
        // zend_string (24 ns cached, 36-377 ns fresh vs 82-518 ns across FFI).
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', self::encodings());
    }

    public static function isValidNative(string $value): ?bool
    {
        if ($value === '') {
            return true;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_utf8_is_valid($value, strlen($value));

            return is_int($res) ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function cleanNative(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $valid = self::isValidNative($value);
        if ($valid === true || ($valid === null && mb_check_encoding($value, 'UTF-8'))) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', self::encodings());
    }

    /**
     * @return list<string>
     */
    private static function encodings(): array
    {
        if (self::$encodings === null) {
            self::$encodings = mb_list_encodings();
        }

        return self::$encodings;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('support_utf8', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }
}
