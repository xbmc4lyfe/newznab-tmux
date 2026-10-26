<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Extractors;

use App\Support\RustPipelineLibrary;
use FFI\CData;

final class ObfuscatedSubjectExtractor
{
    /**
     * @var list<string>
     */
    private const array PREFIX_PATTERNS = [
        '/^\s*N:\/NZB\s*\[\d+\/\d+\]\s*-\s*/i',
        '/^\s*N[\s._:-]*NZB[\s._-]*\[\d+(?:[\/_]\d+)?\][\s._-]*-\s*/i',
        '/^\s*\[[^\]]{2,80}\]\s*/',
        '/^\s*(?:re\s*)?posted\s+by\s+[^-]{2,120}\s*-\s*/i',
    ];

    /**
     * @var list<string>
     */
    private const array TRAILING_ARCHIVE_PATTERNS = [
        '/\.part\d+\.rar$/i',
        '/\.r\d{2,4}$/i',
        '/\.7z\.\d{2,4}$/i',
        '/\.7z$/i',
        '/\.rar$/i',
        '/\.par2?$/i',
        '/\.part$/i',
        '/\.vol\d+[+\-]\d+\.par2?$/i',
        '/\.\d{3}$/',
    ];

    public function extract(string $value): ?string
    {
        $supported = false;
        $native = RustPipelineLibrary::extractObfuscatedSubject($value, null, $supported);
        if ($supported) {
            return $native;
        }

        return $this->extractPhp($value);
    }

    public function extractPhp(string $value): ?string
    {
        $original = trim($value);
        if ($original === '') {
            return null;
        }

        $normalized = $original;
        $looksObfuscated = false;
        foreach (self::PREFIX_PATTERNS as $pattern) {
            $updated = preg_replace($pattern, '', $normalized) ?? $normalized;
            if ($updated !== $normalized) {
                $looksObfuscated = true;
                $normalized = $updated;
            }
        }

        if (preg_match('/"([^"]{3,240})"/', $normalized, $quoted) === 1) {
            $normalized = $quoted[1];
            $looksObfuscated = true;
        } elseif (preg_match("/'([^']{3,240})'/", $normalized, $quoted) === 1) {
            $normalized = $quoted[1];
            $looksObfuscated = true;
        }

        if ($looksObfuscated) {
            foreach (self::TRAILING_ARCHIVE_PATTERNS as $pattern) {
                $normalized = preg_replace($pattern, '', $normalized) ?? $normalized;
            }
        }

        $normalized = str_replace(['_', '+'], [' ', ' '], $normalized);
        $normalized = preg_replace('/\.(?=[A-Za-z0-9])/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s{2,}/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B\"'`-_.");
        $normalized = $this->toReadableTitle($normalized);

        if ($normalized === '' || $normalized === $original) {
            return null;
        }

        return $normalized;
    }

    private function toReadableTitle(string $value): string
    {
        // If the candidate looks fully lowercase, present a cleaner title-cased
        // variant for UI/searchname while preserving separators and numbers.
        if (preg_match('/\p{Lu}/u', $value) === 1) {
            return $value;
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    // ---- Wave 58.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 4.41x/3.53x, uncommon 4.22x/3.65x); see RustConstrainedPredbFileNameNfoObfuscatedNameCheckerW58ParityTest. */
    private const bool W58_RUST_FFI_DEFAULT = true;

    private const string W58_FFI_CDEF = <<<'C'
        intptr_t nntmux_w58_obfuscated_subject_plan_batch(const char *subjects, size_t subjects_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 58 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w58', self::W58_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w58RustFfiState('ffi');
                    $buffer = $resolved instanceof \FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $enabled = self::w58RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planSubjectBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w58Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w58Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', \FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** `/\.(?:part\d{1,4}\.rar|vol\d{1,4}[+-]\d{1,4}\.par2|r\d{2,3}|\d{3}|rar|par2|7z|zip|nzb|nfo|sfv)\z/` on the lowercased subject. */
    private const string W58_ARCHIVE = '/\.(?:part[0-9]{1,4}\.rar|vol[0-9]{1,4}[+-][0-9]{1,4}\.par2|r[0-9]{2,3}|[0-9]{3}|rar|par2|7z|zip|nzb|nfo|sfv)\z/';

    private const string W58_HEX = '0123456789abcdefABCDEF';

    private const string W58_ALNUM = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Batch plan over raw Usenet subjects. After trim(): cut at the last `yEnc` and rtrim (flag
     * 1); drop a trailing `(\d{1,5}/\d{1,5})` at the last `(` and rtrim (2); drop leading
     * `[...]` tags with 1-80 byte bodies, ltrimming spaces and dashes (8); take the first
     * non-empty `"..."` body (4); cut an archive suffix (16); trim spaces, quotes, dashes, dots
     * and underscores. Flags 32: 16+ hex digits; 64: an 8-4-4-4-12 hex UUID; 128: neither, 12-64
     * ASCII alphanumerics with a digit, an upper and a lower letter (a random hash). Per subject
     * `flags | min(len, 0xFFFFFF) << 8 | crc32(core) << 32`; then the yEnc, hex, UUID and random
     * counts, the distinct non-empty cores and the XXH64 digest of all earlier words.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planSubjectBatch(array $subjects): array
    {
        return self::usesW58RustFfi() ? self::planSubjectBatchNative($subjects) : self::planSubjectBatchPhp($subjects);
    }

    /**
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planSubjectBatchPhp(array $subjects): array
    {
        $out = $seen = [];
        $yenc = $hex = $uuid = $random = 0;
        foreach ($subjects as $subject) {
            $s = trim((string) $subject);
            $flags = 0;
            if (($p = strrpos($s, 'yEnc')) !== false) {
                $s = rtrim(substr($s, 0, $p));
                $flags |= 1;
            }
            if (str_ends_with($s, ')') && ($q = strrpos($s, '(')) !== false
                && preg_match('#\A[0-9]{1,5}/[0-9]{1,5}\z#', substr($s, $q + 1, -1)) === 1) {
                $s = rtrim(substr($s, 0, $q));
                $flags |= 2;
            }
            while (($s[0] ?? '') === '[' && ($q = strpos($s, ']')) !== false && $q >= 2 && $q <= 81) {
                $s = ltrim(substr($s, $q + 1), ' -');
                $flags |= 8;
            }
            if (($p = strpos($s, '"')) !== false && ($q = strpos($s, '"', $p + 1)) !== false && $q > $p + 1) {
                $s = substr($s, $p + 1, $q - $p - 1);
                $flags |= 4;
            }
            if (preg_match(self::W58_ARCHIVE, strtolower($s), $m, PREG_OFFSET_CAPTURE) === 1) {
                $s = substr($s, 0, $m[0][1]);
                $flags |= 16;
            }
            $core = trim($s, " \"'-._");
            $len = \strlen($core);
            if ($len >= 16 && strspn($core, self::W58_HEX) === $len) {
                $flags |= 32;
                $hex++;
            } elseif ($len === 36 && $core[8] === '-' && $core[13] === '-' && $core[18] === '-' && $core[23] === '-'
                && strspn(str_replace('-', '', $core), self::W58_HEX) === 32) {
                $flags |= 64;
                $uuid++;
            } elseif ($len >= 12 && $len <= 64 && strspn($core, self::W58_ALNUM) === $len && strpbrk($core, '0123456789') !== false
                && strpbrk($core, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') !== false && strpbrk($core, 'abcdefghijklmnopqrstuvwxyz') !== false) {
                $flags |= 128;
                $random++;
            }
            $yenc += $flags & 1;
            if ($core !== '') {
                $seen[$core] = true;
            }
            $out[] = $flags | min($len, 0xFFFFFF) << 8 | crc32($core) << 32;
        }
        array_push($out, $yenc, $hex, $uuid, $random, \count($seen));
        $out[] = self::w58Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a subject holds a NUL byte or
     * the column is not a list.
     *
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planSubjectBatchNative(array $subjects): array
    {
        $count = \count($subjects);
        $words = $count + 6;
        /** @var array{\FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w58RustFfiState('out', $words);
        if ($state === null || ! array_is_list($subjects)) {
            return self::planSubjectBatchPhp($subjects);
        }
        $blob = implode("\0", $subjects);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W58_FFI_CDEF.)
            $written = $ffi->nntmux_w58_obfuscated_subject_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }

        return self::w58Words($written, $out, $words) ?? self::planSubjectBatchPhp($subjects);
    }
}
