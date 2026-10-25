<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use FFI\CData;
use Throwable;

final class BookIsbn
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_log_parse_header_spans(const char *line, size_t line_len);
        int64_t nntmux_log_plain_timestamp_span(const char *line, size_t line_len);
        ssize_t nntmux_log_is_header_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_log_parse_headers_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        ssize_t nntmux_log_parse_entries_block(const char *input, size_t input_len, uint32_t structured, size_t max_entry_bytes, int64_t *meta_out, size_t meta_cap, char *out_buf, size_t out_cap);
        int32_t nntmux_donor_validate_canonical_single(int64_t predb_id, const char *title, size_t title_len, const char *search_name, size_t search_name_len);
        int32_t nntmux_donor_select_canonical_batch_nul(const char *input, size_t input_len, size_t count);
        int32_t nntmux_donor_select_best_index(const int64_t *pairs, size_t count, int64_t target_size, int64_t tolerance_percent);
        int32_t nntmux_donor_select_best_nul(const char *input, size_t input_len, size_t count, int64_t target_size, int64_t tolerance_percent);
        ssize_t nntmux_book_isbn_normalize(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        int32_t nntmux_book_isbn_equivalent(const char *left, size_t left_len, const char *right, size_t right_len);
        ssize_t nntmux_book_isbn_normalize_batch_nul(const char *input, size_t input_len, size_t count, uint32_t mode, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    private static ?CData $buf = null;

    private static int $cap = 0;

    private static bool $useNative = true;

    public static function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::$useNative) {
            $ffi = self::$ffi ??= self::initFfi();
            if ($ffi !== false && self::$buf !== null) {
                try {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_book_isbn_normalize($value, \strlen($value), 0, self::$buf, self::$cap);
                    if (\is_int($written) && $written >= 0) {
                        return $written === 0 ? null : FFI::string(self::$buf, $written);
                    }
                } catch (Throwable) {
                }
            }
        }

        return self::normalizePhp($value);
    }

    public static function normalizePhp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $isbn = strtoupper((string) preg_replace('/[^0-9X]/i', '', $value));

        return match (strlen($isbn)) {
            10 => self::isValidIsbn10($isbn) ? $isbn : null,
            13 => self::isValidIsbn13($isbn) ? $isbn : null,
            default => null,
        };
    }

    public static function equivalent(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null || $left === '' || $right === '') {
            return false;
        }

        if (self::$useNative) {
            $ffi = self::$ffi ??= self::initFfi();
            if ($ffi !== false) {
                try {
                    // @phpstan-ignore method.notFound
                    $res = $ffi->nntmux_book_isbn_equivalent($left, \strlen($left), $right, \strlen($right));
                    if (\is_int($res) && $res >= 0) {
                        return $res === 1;
                    }
                } catch (Throwable) {
                }
            }
        }

        return self::equivalentPhp($left, $right);
    }

    public static function equivalentPhp(?string $left, ?string $right): bool
    {
        $leftIdentifiers = self::equivalentsPhp($left);
        $rightIdentifiers = self::equivalentsPhp($right);

        return $leftIdentifiers !== [] && array_intersect($leftIdentifiers, $rightIdentifiers) !== [];
    }

    /**
     * @return list<string>
     */
    public static function equivalents(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (self::$useNative) {
            $ffi = self::$ffi ??= self::initFfi();
            if ($ffi !== false && self::$buf !== null) {
                try {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_book_isbn_normalize($value, \strlen($value), 3, self::$buf, self::$cap);
                    if (\is_int($written) && $written >= 0) {
                        if ($written === 0) {
                            return [];
                        }

                        return explode("\0", FFI::string(self::$buf, $written));
                    }
                } catch (Throwable) {
                }
            }
        }

        return self::equivalentsPhp($value);
    }

    /**
     * @return list<string>
     */
    public static function equivalentsPhp(?string $value): array
    {
        $isbn = self::normalizePhp($value);
        if ($isbn === null) {
            return [];
        }

        $identifiers = [$isbn];
        $converted = strlen($isbn) === 10 ? self::toIsbn13Php($isbn) : self::toIsbn10Php($isbn);
        if ($converted !== null) {
            $identifiers[] = $converted;
        }

        sort($identifiers);

        return array_values(array_unique($identifiers));
    }

    public static function toIsbn13(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::$useNative) {
            $ffi = self::$ffi ??= self::initFfi();
            if ($ffi !== false && self::$buf !== null) {
                try {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_book_isbn_normalize($value, \strlen($value), 1, self::$buf, self::$cap);
                    if (\is_int($written) && $written >= 0) {
                        return $written === 0 ? null : FFI::string(self::$buf, $written);
                    }
                } catch (Throwable) {
                }
            }
        }

        return self::toIsbn13Php($value);
    }

    public static function toIsbn13Php(?string $value): ?string
    {
        $isbn10 = self::normalizePhp($value);
        if ($isbn10 === null || strlen($isbn10) !== 10) {
            return null;
        }

        $body = '978'.substr($isbn10, 0, 9);
        $sum = 0;
        for ($index = 0; $index < 12; $index++) {
            $sum += ((int) $body[$index]) * ($index % 2 === 0 ? 1 : 3);
        }

        return $body.(string) ((10 - ($sum % 10)) % 10);
    }

    public static function toIsbn10(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (self::$useNative) {
            $ffi = self::$ffi ??= self::initFfi();
            if ($ffi !== false && self::$buf !== null) {
                try {
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_book_isbn_normalize($value, \strlen($value), 2, self::$buf, self::$cap);
                    if (\is_int($written) && $written >= 0) {
                        return $written === 0 ? null : FFI::string(self::$buf, $written);
                    }
                } catch (Throwable) {
                }
            }
        }

        return self::toIsbn10Php($value);
    }

    public static function toIsbn10Php(?string $value): ?string
    {
        $isbn13 = self::normalizePhp($value);
        if ($isbn13 === null || strlen($isbn13) !== 13 || ! str_starts_with($isbn13, '978')) {
            return null;
        }

        $body = substr($isbn13, 3, 9);
        $sum = 0;
        for ($index = 0; $index < 9; $index++) {
            $sum += ((int) $body[$index]) * (10 - $index);
        }
        $check = (11 - ($sum % 11)) % 11;

        return $body.($check === 10 ? 'X' : (string) $check);
    }

    /**
     * Batch normalize or convert a list of ISBN strings (`$mode`: 0 = normalize, 1 = toIsbn13, 2 = toIsbn10).
     *
     * @param  list<string|null>  $values
     * @return list<string|null>
     */
    public static function normalizeBatch(array $values, int $mode = 0): array
    {
        $count = \count($values);
        if ($count === 0) {
            return [];
        }

        if (self::$useNative && $mode >= 0 && $mode <= 2) {
            $ffi = self::$ffi ??= self::initFfi();
            if ($ffi !== false) {
                try {
                    $joined = \implode("\0", $values);
                    $inLen = \strlen($joined);
                    $needed = $count * 14 + 64;
                    $cap = self::$cap;
                    $scratch = (self::$buf !== null && $cap >= $needed)
                        ? self::$buf
                        : RustPipelineLibrary::obtainScratch($ffi, $needed, $cap);
                    if ($scratch !== null) {
                        self::$buf = $scratch;
                        self::$cap = $cap;
                        // @phpstan-ignore method.notFound
                        $written = $ffi->nntmux_book_isbn_normalize_batch_nul($joined, $inLen, $count, $mode, $scratch, $cap);
                        if (\is_int($written) && $written < -1) {
                            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                            if ($scratch !== null) {
                                self::$buf = $scratch;
                                self::$cap = $cap;
                                // @phpstan-ignore method.notFound
                                $written = $ffi->nntmux_book_isbn_normalize_batch_nul($joined, $inLen, $count, $mode, $scratch, $cap);
                            }
                        }
                        if (\is_int($written) && $written >= 0 && $scratch !== null) {
                            if ($written === 0) {
                                return array_fill(0, $count, null);
                            }
                            $parts = explode("\0", FFI::string($scratch, $written));
                            if (\count($parts) === $count) {
                                $out = [];
                                foreach ($parts as $p) {
                                    $out[] = $p === '' ? null : $p;
                                }

                                return $out;
                            }
                        }
                    }
                } catch (Throwable) {
                }
            }
        }

        return self::normalizeBatchPhp($values, $mode);
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string|null>
     */
    public static function normalizeBatchPhp(array $values, int $mode = 0): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[] = match ($mode) {
                0 => self::normalizePhp($value),
                1 => self::toIsbn13Php($value),
                2 => self::toIsbn10Php($value),
                default => null,
            };
        }

        return $out;
    }

    private static function initFfi(): FFI|false
    {
        $ffi = RustPipelineLibrary::resolveDomain('log_donor_isbn', self::FFI_CDEF);
        if ($ffi === null) {
            return false;
        }
        self::$buf = RustPipelineLibrary::obtainScratch($ffi, 256, self::$cap);

        return $ffi;
    }

    private static function isValidIsbn10(string $isbn): bool
    {
        if (preg_match('/^\d{9}[\dX]$/', $isbn) !== 1) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < 10; $index++) {
            $digit = $isbn[$index] === 'X' ? 10 : (int) $isbn[$index];
            $sum += $digit * (10 - $index);
        }

        return $sum % 11 === 0;
    }

    private static function isValidIsbn13(string $isbn): bool
    {
        if (preg_match('/^97[89]\d{10}$/', $isbn) !== 1) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < 13; $index++) {
            $sum += ((int) $isbn[$index]) * ($index % 2 === 0 ? 1 : 3);
        }

        return $sum % 10 === 0;
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 4.36x, p99 2.83x; uncommon mean 11.99x, p99 7.59x; see the W44 parity test). */
    public const bool W444_RUST_FFI_DEFAULT = true;

    public const string W444_FFI_CDEF = <<<'C'
        ssize_t nntmux_w444_isbn_value_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W444_SUMMARY_KEYS = ['total_values', 'empty_value_count', 'isbn10_count', 'isbn13_count', 'invalid_count', 'duplicate_count', 'isbn10_convertible_count', 'x_check_digit_count'];

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
     * Summarize values through normalizePhp(): valid ISBN-10 and ISBN-13
     * counts, non-empty values without a valid ISBN, repeats of an earlier
     * value's canonical ISBN-13 (toIsbn13Php() for ISBN-10), values
     * toIsbn10Php() can convert, and ISBN-10 X check digits. Entries come
     * from strings, integers, or the isbn or identifier field; other values
     * count as empty.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeIsbnBatch(array $entries): array
    {
        return self::usesW444RustFfi()
            ? self::summarizeIsbnBatchNative($entries)
            : self::summarizeIsbnBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeIsbnBatchNative(array $entries): array
    {
        return self::w444Call(self::w444Entries($entries)) ?? self::summarizeIsbnBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeIsbnBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W444_SUMMARY_KEYS, 0);
        $seen = [];
        foreach (self::w444Entries($entries) as $value) {
            $summary['total_values']++;
            $value = trim($value);
            if ($value === '') {
                $summary['empty_value_count']++;

                continue;
            }
            $isbn = self::normalizePhp($value);
            if ($isbn === null) {
                $summary['invalid_count']++;

                continue;
            }
            if (strlen($isbn) === 10) {
                $summary['isbn10_count']++;
                $summary['isbn10_convertible_count']++;
                $summary['x_check_digit_count'] += (int) ($isbn[9] === 'X');
                $canonical = (string) self::toIsbn13Php($isbn);
            } else {
                $summary['isbn13_count']++;
                $summary['isbn10_convertible_count'] += (int) str_starts_with($isbn, '978');
                $canonical = $isbn;
            }
            if (isset($seen[$canonical])) {
                $summary['duplicate_count']++;
            } else {
                $seen[$canonical] = true;
            }
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
                $item = $item['isbn'] ?? $item['identifier'] ?? null;
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
            $written = $ffi->nntmux_w444_isbn_value_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W444_FFI_CDEF.)
                $written = $ffi->nntmux_w444_isbn_value_batch($input, strlen($input), $out, $capacity);
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
