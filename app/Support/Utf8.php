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

    /** Disabled: seven paired runs missed the 1.5x common gates (min common mean 1.48x, p99 1.14x; uncommon mean 1.70x, p99 1.63x; see the W44 parity test). */
    public const bool W444_RUST_FFI_DEFAULT = false;

    public const string W444_FFI_CDEF = <<<'C'
        ssize_t nntmux_w444_utf8_value_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W444_SUMMARY_KEYS = ['total_values', 'empty_value_count', 'ascii_count', 'valid_multibyte_count', 'invalid_utf8_count', 'control_char_count', 'bom_count', 'mojibake_count'];

    private static ?bool $w444UseRustFfi = null;

    private static FFI|false|null $w444Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW444RustFfi(?bool $enable = true): void
    {
        self::$w444UseRustFfi = $enable;
    }

    public static function usesW444RustFfi(): bool
    {
        return self::$w444UseRustFfi ?? self::W444_RUST_FFI_DEFAULT;
    }

    public static function resetW444RustFfi(): void
    {
        self::$w444UseRustFfi = null;
        self::$w444Ffi = null;
    }

    /**
     * Summarize trimmed values as clean() sees them: pure ASCII, valid
     * multibyte UTF-8 (mb_check_encoding()), invalid UTF-8, C0/DEL control
     * bytes other than tab/LF/CR, a UTF-8 BOM and the C3 82/C3 83 mojibake
     * pairs fixEncoding() targets. Entries come from strings, integers, or
     * the text or value field; other values count as empty.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeUtf8Batch(array $entries): array
    {
        return self::usesW444RustFfi()
            ? self::summarizeUtf8BatchNative($entries)
            : self::summarizeUtf8BatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeUtf8BatchNative(array $entries): array
    {
        return self::w444Call(self::w444Entries($entries)) ?? self::summarizeUtf8BatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeUtf8BatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W444_SUMMARY_KEYS, 0);
        foreach (self::w444Entries($entries) as $value) {
            $summary['total_values']++;
            $value = trim($value);
            if ($value === '') {
                $summary['empty_value_count']++;

                continue;
            }
            $ascii = preg_match('/[\x80-\xFF]/', $value) !== 1;
            $valid = $ascii || mb_check_encoding($value, 'UTF-8');
            $summary['ascii_count'] += (int) $ascii;
            $summary['valid_multibyte_count'] += (int) (! $ascii && $valid);
            $summary['invalid_utf8_count'] += (int) ! $valid;
            $summary['control_char_count'] += (int) (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1);
            $summary['bom_count'] += (int) str_starts_with($value, "\xEF\xBB\xBF");
            $summary['mojibake_count'] += (int) (str_contains($value, "\xC3\x83") || str_contains($value, "\xC3\x82"));
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w444Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['text'] ?? $item['value'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w444Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w444Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w444', self::W444_FFI_CDEF) ?? false;
            $ffi = self::$w444Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W444_FFI_CDEF.)
            $written = $ffi->nntmux_w444_utf8_value_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W444_FFI_CDEF.)
                $written = $ffi->nntmux_w444_utf8_value_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W444_SUMMARY_KEYS || $result['total_values'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W444_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }
}
