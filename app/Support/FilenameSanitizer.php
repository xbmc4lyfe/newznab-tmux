<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Par2PacketAccumulator;
use FFI;
use FFI\CData;
use Illuminate\Support\Str;
use Normalizer;
use Throwable;

final class FilenameSanitizer
{
    private const int MAX_FILENAME_LENGTH = 200;

    public const string FFI_CDEF = <<<'CDEF'
        intptr_t nntmux_filename_sanitize(const char *input, size_t input_len, const char *fallback, size_t fallback_len, uint32_t ascii_only, char *out_buf, size_t out_cap);
        CDEF;

    private const int OUTPUT_BYTES = 256;

    private static bool $ffiResolved = false;

    private static ?FFI $ffi = null;

    private static ?CData $buf = null;

    private static int $cap = 0;

    /**
     * Default for the Wave 44.2 toggle only (summarizeFilenames); true because the paired benchmark in
     * tests/Unit/RustPar2PacketMetadataProofPayloadSanitizerW44ParityTest.php clears 1.5x on common and uncommon mean and p99.
     */
    public const bool W442_RUST_FFI_DEFAULT = true;

    private static ?bool $useW442RustFfi = null;

    /**
     * @var list<string>
     */
    private const array UNICODE_PATH_SEPARATORS = [
        '⁄',
        '∕',
        '⧸',
        '／',
        '＼',
        '﹨',
        '⧵',
        '⟋',
        '⟍',
        '╱',
        '╲',
    ];

    public static function sanitize(?string $name, string $fallback = 'download'): string
    {
        return self::sanitizeRust($name ?? '', $fallback, 0) ?? self::sanitizePhp($name, $fallback);
    }

    public static function asciiFallback(?string $name, string $fallback = 'download'): string
    {
        return self::sanitizeRust($name ?? '', $fallback, 1) ?? self::asciiFallbackPhp($name, $fallback);
    }

    public static function sanitizePhp(?string $name, string $fallback = 'download'): string
    {
        $sanitized = self::normalize($name);

        if ($sanitized === '') {
            return self::safeFallback($fallback);
        }

        return self::finalize($sanitized, $fallback);
    }

    public static function asciiFallbackPhp(?string $name, string $fallback = 'download'): string
    {
        $sanitized = self::sanitizePhp($name, $fallback);
        $ascii = Str::ascii($sanitized);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $ascii) ?? $ascii;
        $ascii = preg_replace('/_+/', '_', $ascii) ?? $ascii;

        return self::finalize($ascii, $fallback);
    }

    /** Force the Wave 44.2 helper onto Rust (true) or PHP (false); null restores W442_RUST_FFI_DEFAULT. */
    public static function useW442RustFfi(?bool $enable = true): void
    {
        self::$useW442RustFfi = $enable;
    }

    public static function usesW442RustFfi(): bool
    {
        return self::$useW442RustFfi ?? self::W442_RUST_FFI_DEFAULT;
    }

    public static function resetW442RustFfi(): void
    {
        self::$useW442RustFfi = null;
    }

    /**
     * Classify filenames as sanitizePhp() treats them: other non-strings are invalid; null and blank strings are empty;
     * any byte above 0x7F is non_ascii (Unicode normalization is not replayed); ASCII names fall back when nothing
     * survives normalization, are truncated past MAX_FILENAME_LENGTH, clean when sanitizing leaves them unchanged and
     * changed otherwise. Bytes sum every string.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{names: int, invalid: int, empty: int, non_ascii: int, fallback: int, truncated: int, clean: int, changed: int, bytes: int}
     */
    public static function summarizeFilenames(array $names): array
    {
        return (self::usesW442RustFfi() ? self::summarizeFilenamesNative($names) : null)
            ?? self::summarizeFilenamesPhp($names);
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{names: int, invalid: int, empty: int, non_ascii: int, fallback: int, truncated: int, clean: int, changed: int, bytes: int}
     */
    public static function summarizeFilenamesPhp(array $names): array
    {
        $summary = ['names' => \count($names), 'invalid' => 0, 'empty' => 0, 'non_ascii' => 0, 'fallback' => 0, 'truncated' => 0, 'clean' => 0, 'changed' => 0, 'bytes' => 0];
        foreach ($names as $name) {
            if ($name !== null && ! \is_string($name)) {
                $summary['invalid']++;

                continue;
            }
            $name ??= '';
            $summary['bytes'] += \strlen($name);
            if (trim($name) === '') {
                $summary['empty']++;

                continue;
            }
            if (preg_match('/[\x80-\xFF]/', $name) === 1) {
                $summary['non_ascii']++;

                continue;
            }
            $normalized = self::normalize($name);
            $finished = self::trimFilename(mb_substr($normalized, 0, self::MAX_FILENAME_LENGTH));
            if ($normalized === '' || $finished === '' || $finished === '.' || $finished === '..') {
                $summary['fallback']++;
            } elseif (\strlen($normalized) > self::MAX_FILENAME_LENGTH) {
                $summary['truncated']++;
            } else {
                $summary[$finished === $name ? 'clean' : 'changed']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{names: int, invalid: int, empty: int, non_ascii: int, fallback: int, truncated: int, clean: int, changed: int, bytes: int}|null
     */
    public static function summarizeFilenamesNative(array $names): ?array
    {
        $slots = [];
        $tail = [];
        foreach ($names as $name) {
            if (\is_string($name)) {
                array_push($slots, \strlen($name), 0);
                $tail[] = $name;
            } else {
                array_push($slots, -1, $name === null ? 1 : 2);
            }
        }
        $values = Par2PacketAccumulator::evaluateW442Native(4, \count($names), $slots, implode('', $tail), 9);
        if ($values === null) {
            return null;
        }
        [$count, $invalid, $empty, $nonAscii, $fallback, $truncated, $clean, $changed, $bytes] = $values;
        if ($count !== \count($names) || min($values) < 0 || $invalid + $empty + $nonAscii + $fallback + $truncated + $clean + $changed !== $count) {
            return null;
        }

        return ['names' => $count, 'invalid' => $invalid, 'empty' => $empty, 'non_ascii' => $nonAscii, 'fallback' => $fallback,
            'truncated' => $truncated, 'clean' => $clean, 'changed' => $changed, 'bytes' => $bytes];
    }

    /**
     * Returns null when the native library is unavailable or declines (non-ASCII input).
     */
    private static function sanitizeRust(string $name, string $fallback, int $asciiOnly): ?string
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('file_prioritizer', self::FFI_CDEF);
            if (self::$ffi !== null) {
                self::$buf = RustPipelineLibrary::obtainScratch(self::$ffi, self::OUTPUT_BYTES, self::$cap);
            }
            self::$ffiResolved = true;
        }
        if (self::$ffi === null || self::$buf === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_filename_sanitize(
                $name,
                \strlen($name),
                $fallback,
                \strlen($fallback),
                $asciiOnly,
                self::$buf,
                self::$cap,
            );
            if (! \is_int($written) || $written <= 0) {
                return null;
            }

            return FFI::string(self::$buf, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function normalize(?string $name): string
    {
        $normalized = trim((string) $name);

        if ($normalized === '') {
            return '';
        }

        if (class_exists(Normalizer::class)) {
            $candidate = Normalizer::normalize($normalized, Normalizer::FORM_KC);
            if (is_string($candidate)) {
                $normalized = $candidate;
            }
        }

        $normalized = str_replace(self::UNICODE_PATH_SEPARATORS, '/', $normalized);
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', '', $normalized) ?? $normalized;
        $normalized = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '_', $normalized);
        $normalized = preg_replace('/[\s,]+/u', '_', $normalized) ?? $normalized;
        $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;

        return self::trimFilename($normalized);
    }

    private static function finalize(string $name, string $fallback): string
    {
        $name = mb_substr($name, 0, self::MAX_FILENAME_LENGTH);
        $name = self::trimFilename($name);

        if ($name === '' || $name === '.' || $name === '..') {
            return self::safeFallback($fallback);
        }

        return $name;
    }

    private static function safeFallback(string $fallback): string
    {
        $safeFallback = Str::ascii($fallback);
        $safeFallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $safeFallback) ?? $safeFallback;
        $safeFallback = preg_replace('/_+/', '_', $safeFallback) ?? $safeFallback;
        $safeFallback = mb_substr($safeFallback, 0, self::MAX_FILENAME_LENGTH);
        $safeFallback = self::trimFilename($safeFallback);

        if ($safeFallback === '' || $safeFallback === '.' || $safeFallback === '..') {
            return 'download';
        }

        return $safeFallback;
    }

    private static function trimFilename(string $name): string
    {
        return ltrim(trim($name, " \t\n\r\0\x0B._-"), '.');
    }
}
