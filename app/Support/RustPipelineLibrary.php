<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use FFI\CData;
use Throwable;

/**
 * Shared FFI loader and zero-allocation buffer bridge for `libnntmux_pipeline`.
 */
final class RustPipelineLibrary
{
    private const int INITIAL_SCRATCH_BYTES = 262144;

    /** @var array<string, FFI|false> */
    private static array $libraries = [];

    private static ?CData $scratchBuf = null;

    private static int $scratchCap = 0;

    public static function available(?string $libraryPath = null): bool
    {
        return self::resolve($libraryPath) !== null;
    }

    /**
     * @return list<string>|null
     */
    public static function xrefExtractTokens(string $xref, ?string $libraryPath = null): ?array
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        if ($xref === '' || $xref === '0') {
            return [];
        }

        $len = \strlen($xref);
        $scratch = self::ensureScratch($ffi, max($len, 256));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_xref_extract_tokens($xref, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < 0) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_xref_extract_tokens($xref, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }
        if ($written === 0) {
            return [];
        }

        return explode("\n", FFI::string($scratch, $written));
    }

    /**
     * @return list<string>|null
     */
    public static function xrefExtractGroups(string $xref, ?string $libraryPath = null): ?array
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        if ($xref === '' || $xref === '0') {
            return [];
        }

        $len = \strlen($xref);
        $scratch = self::ensureScratch($ffi, max($len, 256));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_xref_extract_groups($xref, $len, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < 0) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_xref_extract_groups($xref, $len, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }
        if ($written === 0) {
            return [];
        }

        return explode("\n", FFI::string($scratch, $written));
    }

    /**
     * @return list<string>|null
     */
    public static function xrefDiffTokens(string $existingXref, string $headerXref, ?string $libraryPath = null): ?array
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        if ($headerXref === '' || $headerXref === '0') {
            return [];
        }

        $existingLen = \strlen($existingXref);
        $incomingLen = \strlen($headerXref);
        $scratch = self::ensureScratch($ffi, max($incomingLen, 256));
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_xref_diff_tokens($existingXref, $existingLen, $headerXref, $incomingLen, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < 0) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_xref_diff_tokens($existingXref, $existingLen, $headerXref, $incomingLen, $scratch, self::$scratchCap);
            if (! \is_int($written) || $written < 0) {
                return null;
            }
        }
        if ($written === 0) {
            return [];
        }

        return explode("\n", FFI::string($scratch, $written));
    }

    /**
     * Batch extract Xref tokens and/or deduplicated group names for a list of Xref strings.
     *
     * @param  list<string>  $xrefs
     * @return list<string>|null Raw newline-split lines per input item, or null if unavailable/declined
     */
    public static function xrefExtractBatchLines(array $xrefs, int $mode, ?string $libraryPath = null): ?array
    {
        $count = \count($xrefs);
        if ($count === 0) {
            return [];
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        $joined = implode("\0", $xrefs);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }

        $len = \strlen($joined);
        $needed = $mode === 3 ? ($len * 2 + $count * 2 + 64) : ($len + $count + 64);
        $scratch = self::ensureScratch($ffi, $needed);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_xref_extract_batch_nul($joined, $len, $mode, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < -1) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_xref_extract_batch_nul($joined, $len, $mode, $scratch, self::$scratchCap);
        }
        if (! \is_int($written) || $written < 0) {
            return null;
        }
        if ($written === 0) {
            return array_fill(0, $count, '');
        }

        $lines = explode("\n", FFI::string($scratch, $written));

        return \count($lines) === $count ? $lines : null;
    }

    /**
     * Compute a single binary identity MD5 hex hash via Rust FFI, or null when declined/unavailable.
     */
    public static function binaryIdentityHash(string $subject, string $poster, int $fileNumber, ?string $libraryPath = null): ?string
    {
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }
        $scratch = self::ensureScratch($ffi, 64);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_binary_identity_hash(
            $subject,
            \strlen($subject),
            $poster,
            \strlen($poster),
            $fileNumber,
            $scratch
        );

        return $written === 32 ? FFI::string($scratch, 32) : null;
    }

    /**
     * Batch compute binary identity MD5 hex hashes for `count` pairs of `(first, second)` strings.
     *
     * @param  list<string>  $flatPairs
     * @return list<string>|null 32-char hex string per record (or '' when declined to PHP fallback), or null
     */
    public static function binaryIdentityHashesBatch(array $flatPairs, int $count, ?string $libraryPath = null, bool &$allResolved = false): ?array
    {
        $allResolved = false;
        if ($count === 0) {
            $allResolved = true;

            return [];
        }
        $ffi = self::resolve($libraryPath);
        if ($ffi === null) {
            return null;
        }

        $joined = implode("\0", $flatPairs);
        $needed = $count * 33 + 64;
        $scratch = self::ensureScratch($ffi, $needed);
        if ($scratch === null) {
            return null;
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_binary_identity_hash_batch_nul($joined, \strlen($joined), $count, $scratch, self::$scratchCap);
        if (! \is_int($written)) {
            return null;
        }
        if ($written < -1) {
            $scratch = self::ensureScratch($ffi, -$written);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_binary_identity_hash_batch_nul($joined, \strlen($joined), $count, $scratch, self::$scratchCap);
        }
        if (! \is_int($written) || $written < 0) {
            return null;
        }
        if ($written === 0) {
            return array_fill(0, $count, '');
        }

        $allResolved = $written === ($count * 33 - 1);
        $lines = explode("\n", FFI::string($scratch, $written));

        return \count($lines) === $count ? $lines : null;
    }

    public static function resolve(?string $libraryPath = null): ?FFI
    {
        if (! \in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) || ! \extension_loaded('FFI')) {
            return null;
        }

        $pid = (int) getmypid();
        $cacheKey = $pid.':'.($libraryPath ?? 'default');
        if (\array_key_exists($cacheKey, self::$libraries)) {
            $cached = self::$libraries[$cacheKey];

            return $cached === false ? null : $cached;
        }

        $root = \dirname(__DIR__, 2);
        $suffix = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
        $candidates = $libraryPath !== null ? [$libraryPath] : [
            $root.'/storage/app/nntmux-native/libnntmux_pipeline.'.$suffix,
            $root.'/rust/pipeline-ffi/target/release/libnntmux_pipeline.'.$suffix,
            '/usr/local/lib/nntmux/libnntmux_pipeline.'.$suffix,
        ];

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
                continue;
            }
            try {
                $ffi = FFI::cdef(<<<'C'
                    uint32_t nntmux_pipeline_abi_version(void);
                    ssize_t nntmux_xref_extract_tokens(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_xref_extract_groups(const char *input, size_t input_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_xref_diff_tokens(const char *existing, size_t existing_len, const char *incoming, size_t incoming_len, char *out_buf, size_t out_cap);
                    ssize_t nntmux_xref_extract_batch_nul(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
                    ssize_t nntmux_binary_identity_hash(const char *subject, size_t subject_len, const char *poster, size_t poster_len, int64_t file_number, char *out_hex32);
                    ssize_t nntmux_binary_identity_hash_batch_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
                    C, $resolved);

                // @phpstan-ignore method.notFound
                if ($ffi->nntmux_pipeline_abi_version() !== 1) {
                    continue;
                }

                self::$libraries[$cacheKey] = $ffi;

                return $ffi;
            } catch (Throwable) {
                continue;
            }
        }

        self::$libraries[$cacheKey] = false;

        return null;
    }

    private static function ensureScratch(FFI $ffi, int $minBytes): ?CData
    {
        $required = max(self::INITIAL_SCRATCH_BYTES, $minBytes);
        if (self::$scratchBuf !== null && self::$scratchCap >= $required) {
            return self::$scratchBuf;
        }
        try {
            $cap = $required > self::INITIAL_SCRATCH_BYTES ? $required * 2 : self::INITIAL_SCRATCH_BYTES;
            self::$scratchBuf = $ffi->new("char[{$cap}]");
            self::$scratchCap = $cap;

            return self::$scratchBuf;
        } catch (Throwable) {
            return null;
        }
    }
}
