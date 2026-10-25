<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Throwable;

/** Presentation only: keep stored subjects intact for subsequent name-recovery passes. */
final class ReleaseNameDisplay
{
    /**
     * Wave 46.3 production default is Rust: the paired benchmark in
     * tests/Unit/RustAbstractCategorizerPipeSafetyNetDisplayNamesW46ParityTest.php measures
     * 2.33-2.60x mean / 2.05-3.08x p99 on the common 32-subject batch and 6.25-6.55x mean /
     * 4.35-5.62x p99 on the uncommon 2000-subject batch. PHP decides batches Rust declines.
     * Named W463 so it stays distinct from the class's existing Rust paths.
     */
    public const bool W463_RUST_FFI_DEFAULT = true;

    public const string W463_FFI_CDEF = <<<'C'
        ssize_t nntmux_w463_release_name_display_batch(
            const char *blob_ptr,
            size_t blob_len,
            const char *lens_ptr,
            size_t lens_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** cleanPhp()'s wrapper pattern with its optional yEnc suffix captured; matching is identical. */
    public const string W463_WRAPPER_PATTERN = '/^\s*\[\s*\d+\s*\/\s*\d+\s*\]\s*-?\s*(?<quote>"|#34;|&#34;|&quot;)(?<file>(?:(?!\k<quote>)[^\r\n])+)\k<quote>\s*(?<yenc>-?\s*yEnc(?:\s+\(\d+\/\d+\))?)?\s*$/i';

    /** Exactly 6 0x1F-separated decimals of 1-19 digits. */
    private const string W463_FIELDS_PATTERN = '/\A(?:\d{1,19}\x1F){5}\d{1,19}\z/';

    private static ?bool $w463RustFfiOverride = null;

    private static FFI|false|null $w463RustFfiHandle = null;

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

    /**
     * Enables (true) or disables (false) the Wave 46.3 Rust helper; null restores
     * W463_RUST_FFI_DEFAULT.
     */
    public static function useW463RustFfi(?bool $enable = true): void
    {
        self::$w463RustFfiOverride = $enable;
    }

    public static function usesW463RustFfi(): bool
    {
        return self::$w463RustFfiOverride ?? self::W463_RUST_FFI_DEFAULT;
    }

    public static function resetW463RustFfi(): void
    {
        self::$w463RustFfiOverride = null;
        self::$w463RustFfiHandle = null;
    }

    /**
     * Display wrapper summary for a batch of stored subjects: empty names, names cleanPhp() unwraps
     * to their quoted file (wrapped), wrappers it keeps because the file trims to nothing
     * (blank_file), and among all wrapper matches those quoted with an HTML entity and those with a
     * yEnc suffix. Null and other non-strings count as empty names.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, wrapped: int, blank_file: int, entity: int, yenc: int}
     */
    public static function summarizeDisplayWrapperBatch(array $names): array
    {
        return (self::usesW463RustFfi() ? self::summarizeDisplayWrapperBatchNative($names) : null)
            ?? self::summarizeDisplayWrapperBatchPhp($names);
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, wrapped: int, blank_file: int, entity: int, yenc: int}
     */
    public static function summarizeDisplayWrapperBatchPhp(array $names): array
    {
        $empty = $wrapped = $blank = $entity = $yenc = 0;
        foreach ($names as $name) {
            if (! \is_string($name) || $name === '') {
                $empty++;

                continue;
            }
            if (preg_match(self::W463_WRAPPER_PATTERN, $name, $match) !== 1) {
                continue;
            }
            if (trim($match['file']) === '') {
                $blank++;
            } else {
                $wrapped++;
            }
            $entity += (int) ($match['quote'] !== '"');
            $yenc += (int) (($match['yenc'] ?? '') !== '');
        }

        return [
            'total' => \count($names),
            'empty' => $empty,
            'wrapped' => $wrapped,
            'blank_file' => $blank,
            'entity' => $entity,
            'yenc' => $yenc,
        ];
    }

    /**
     * Declines (null) for values other than strings and null, when PCRE limits differ from their
     * defaults and when the library is unavailable. Rust also declines whole batches holding a
     * non-ASCII or 4096+ byte name, so PHP's locale tables and resource limits decide those.
     *
     * @param  array<array-key, mixed>  $names
     * @return array{total: int, empty: int, wrapped: int, blank_file: int, entity: int, yenc: int}|null
     */
    public static function summarizeDisplayWrapperBatchNative(array $names): ?array
    {
        if (! self::w463PcreAtDefaults()) {
            return null;
        }
        $lengths = [];
        foreach ($names as $name) {
            if (\is_string($name)) {
                $lengths[] = \strlen($name);
            } elseif ($name === null) {
                $lengths[] = 0;
            } else {
                return null;
            }
        }
        $fields = self::callW463Native(implode('', $names), pack('V*', ...$lengths));
        if ($fields === null) {
            return null;
        }

        return [
            'total' => (int) $fields[0],
            'empty' => (int) $fields[1],
            'wrapped' => (int) $fields[2],
            'blank_file' => (int) $fields[3],
            'entity' => (int) $fields[4],
            'yenc' => (int) $fields[5],
        ];
    }

    /**
     * Whether PCRE runs with the limits Rust mirrors; restricted resources keep PHP's failure
     * behavior.
     */
    private static function w463PcreAtDefaults(): bool
    {
        return (int) ini_get('pcre.backtrack_limit') >= 1000000
            && (int) ini_get('pcre.recursion_limit') >= 100000
            && ini_get('pcre.jit') === '1';
    }

    /**
     * Calls the Wave 46.3 export with the shared scratch buffer, retrying once at the size it asks
     * for. Returns its fields only when they match W463_FIELDS_PATTERN.
     *
     * @return list<string>|null
     */
    private static function callW463Native(string $blob, string $lens): ?array
    {
        $ffi = self::w463RustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 192, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w463_release_name_display_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w463_release_name_display_batch($blob, \strlen($blob), $lens, \strlen($lens), $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            $raw = FFI::string($buffer, $written);

            return preg_match(self::W463_FIELDS_PATTERN, $raw) === 1 ? explode("\x1F", $raw) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function w463RustFfi(): ?FFI
    {
        if (self::$w463RustFfiHandle === null) {
            self::$w463RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w463', self::W463_FFI_CDEF) ?? false;
        }

        return self::$w463RustFfiHandle === false ? null : self::$w463RustFfiHandle;
    }
}
