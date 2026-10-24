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
}
