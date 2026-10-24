<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Throwable;

/**
 * Shared helpers for migrating Scout/MySQL boolean fulltext queries to the unified search engine.
 */
final class MetadataSearchLookup
{
    public const LOOKUP_FFI_CDEF = <<<'C'
        ssize_t nntmux_metadata_normalize_boolean_search_words(const char *input, size_t len, char *out, size_t cap);
        C;

    /** Null selects the benchmark-qualified production default. */
    public static ?bool $useRustFfi = null;

    private static FFI|false|null $lookupFfi = null;

    public static function lookupFfi(): ?FFI
    {
        if (self::$lookupFfi === null) {
            self::$lookupFfi = RustPipelineLibrary::resolveDomain('metadata', self::LOOKUP_FFI_CDEF) ?? false;
        }

        return self::$lookupFfi === false ? null : self::$lookupFfi;
    }

    /** PCRE `\s` without `/u` follows LC_CTYPE character tables outside the C locales. */
    public static function lookupEnvironmentSupported(): bool
    {
        return \in_array(setlocale(LC_CTYPE, '0'), ['C', 'POSIX', 'C.UTF-8', 'C.utf8'], true);
    }

    /**
     * Turn legacy "+word1 +word2" boolean query into a space-separated phrase for Manticore/ES.
     */
    public static function normalizeBooleanSearchWords(string $searchwords): string
    {
        return (self::$useRustFfi ?? false)
            ? self::normalizeBooleanSearchWordsNative($searchwords)
            : self::normalizeBooleanSearchWordsPhp($searchwords);
    }

    public static function normalizeBooleanSearchWordsNative(string $searchwords): string
    {
        if (! self::lookupEnvironmentSupported() || ($ffi = self::lookupFfi()) === null) {
            return self::normalizeBooleanSearchWordsPhp($searchwords);
        }
        try {
            $len = \strlen($searchwords);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(16, $len + 4), $capacity);
            if ($buffer === null) {
                return self::normalizeBooleanSearchWordsPhp($searchwords);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_metadata_normalize_boolean_search_words($searchwords, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeBooleanSearchWordsPhp($searchwords);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_metadata_normalize_boolean_search_words($searchwords, $len, $buffer, $capacity);
            }
            if (\is_int($written) && $written >= 0 && $written <= $capacity) {
                return FFI::string($buffer, $written);
            }
        } catch (Throwable) {
        }

        return self::normalizeBooleanSearchWordsPhp($searchwords);
    }

    public static function normalizeBooleanSearchWordsPhp(string $searchwords): string
    {
        $s = str_replace('+', ' ', $searchwords);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }
}
