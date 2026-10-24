<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class PredbIdentityKey
{
    private const string CLEANING_CDEF = 'ssize_t nntmux_predb_identity_key_normalize(const char *value, size_t len, char *out, size_t cap);';

    private static FFI|false|null $cleaningFfi = null;

    public static function normalize(string $value): ?string
    {
        // Unicode fallback misses the mean/p99 gate; retain ICU/mbstring in PHP.
        return self::normalizePhp($value);
    }

    /**
     * null is a valid normalized result; $supported distinguishes it from fallback.
     * Non-ASCII normalization stays with ICU/mbstring for exact NFC and casing.
     */
    public static function normalizeNative(string $value, bool &$supported = false): ?string
    {
        $supported = false;
        if (self::$cleaningFfi === null) {
            self::$cleaningFfi = RustPipelineLibrary::resolveDomain('predb_identity_key', self::CLEANING_CDEF) ?? false;
        }
        $ffi = self::$cleaningFfi;
        if ($ffi === false) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, min(513, \strlen($value) + 1), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound (domain C ABI)
            $written = $ffi->nntmux_predb_identity_key_normalize($value, \strlen($value), $scratch, $cap);
            if ($written === -2) {
                $supported = true;

                return null;
            }
            if ($written < 0) {
                return null;
            }
            $supported = true;

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public static function normalizePhp(string $value): ?string
    {
        if ($value === '' || strlen($value) > 512 || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/[._\s]+/u', '.', $value);
    }
}
