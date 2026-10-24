<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Throwable;

/** Presentation only: keep stored subjects intact for subsequent name-recovery passes. */
final class ReleaseNameDisplay
{
    /** Benchmarked candidates remain opt-in until every workload passes the gate. */
    public static bool $useRustFfi = false;

    public static function clean(?string $name): string
    {
        return self::$useRustFfi ? self::cleanNative($name) : self::cleanPhp($name);
    }

    public static function cleanNative(?string $name): string
    {
        return self::transformNative(3, $name ?? '') ?? self::cleanPhp($name);
    }

    /** @param array<array-key, string|null> $names
     * @return array<array-key, string>
     */
    public static function cleanBatch(array $names): array
    {
        return self::$useRustFfi ? self::cleanBatchNative($names) : array_map(self::cleanPhp(...), $names);
    }

    /** @param array<array-key, string|null> $names
     * @return array<array-key, string>
     */
    public static function cleanBatchNative(array $names): array
    {
        $input = '';
        foreach ($names as $name) {
            $name ??= '';
            $input .= pack('V', strlen($name)).$name;
        }
        $output = self::transformNative(8, $input);
        if ($output === null) {
            return array_map(self::cleanPhp(...), $names);
        }
        $result = [];
        foreach ($names as $key => $_) {
            if (strlen($output) < 4) {
                return array_map(self::cleanPhp(...), $names);
            }
            $len = unpack('Vlen', $output)['len'];
            $result[$key] = substr($output, 4, $len);
            $output = substr($output, 4 + $len);
        }

        return $result;
    }

    /** Shared byte-oriented cleaning ABI. Null requests the PHP fallback. */
    public static function transformNative(int $mode, string $input): ?string
    {
        // Preserve PCRE failure behavior when an operator restricts its resources.
        if ($mode !== 0 && ((int) ini_get('pcre.backtrack_limit') < 1000000
            || (int) ini_get('pcre.recursion_limit') < 100000
            || ini_get('pcre.jit') !== '1')) {
            return null;
        }
        static $ffi = null;
        static $resolved = false;
        if (! $resolved) {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning', 'ssize_t nntmux_regex_release_cleaning(const char *input, size_t len, uint32_t mode, char *out, size_t cap);');
            $resolved = true;
        }
        if ($ffi === null) {
            return null;
        }
        try {
            $cap = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 128, $cap);
            if ($out === null) {
                return null;
            }
            $len = $ffi->nntmux_regex_release_cleaning($input, strlen($input), $mode, $out, $cap);
            if ($len < -1) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$len - 3, $cap);
                if ($out === null) {
                    return null;
                }
                $len = $ffi->nntmux_regex_release_cleaning($input, strlen($input), $mode, $out, $cap);
            }

            return $len >= 0 ? FFI::string($out, $len) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function cleanPhp(?string $name): string
    {
        $name ??= '';
        // Match the complete counter-and-quoted-file wrapper, including optional transport decorations.
        // Anchoring the suffix leaves titles with meaningful text after their quotes untouched.
        if (preg_match('/^\s*\[\s*\d+\s*\/\s*\d+\s*\]\s*-?\s*(?<quote>"|#34;|&#34;|&quot;)(?<file>(?:(?!\k<quote>)[^\r\n])+)\k<quote>\s*(?:-?\s*yEnc(?:\s+\(\d+\/\d+\))?)?\s*$/i', $name, $match) !== 1) {
            return $name;
        }

        $filename = trim($match['file']);

        return $filename === '' ? $name : $filename;
    }
}
