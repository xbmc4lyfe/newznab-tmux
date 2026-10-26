<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    // ---- Wave 59.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 3.01x/2.97x, uncommon 3.04x/2.96x); see RustEnumsTmuxPanePostProcessSequentialPredbKeySeasonPackW59ParityTest. */
    public const bool W59_RUST_FFI_DEFAULT = true;

    private const string W59_IDENTITY_KEY_FFI_CDEF = <<<'C'
        intptr_t nntmux_w592_predb_identity_key_summary_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 59 routing, binding and output buffer, kept in function-local statics (no new static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w59', self::W59_IDENTITY_KEY_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w59RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
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

    public static function usesW59RustFfi(): bool
    {
        $enabled = self::w59RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with summarizeIdentityKeyBatch() routed to Rust ($enabled) or PHP,
     * then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w59Digest(array $out): int
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
    private static function w59Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** Canonical length => hash kind (MD5, SHA-1, SHA-256) when every byte is a lowercase hex digit. */
    private const array W59_HASH_LENGTHS = [32 => 1, 40 => 2, 64 => 3];

    /**
     * Batch identity key summary. Type: 0 empty, 1 over 512 bytes, 2 non-ASCII (left to the
     * ICU/mbstring normalize()), 3 ASCII, whose canonical token is normalizePhp()'s result:
     * strtolower(trim(key)) with each run of `[._ \t\n\v\f\r]` replaced by one dot. Other types use
     * the empty token. Per key `type | changed << 2 | empty << 3 | hash << 4 | edge << 6 |
     * min(len, 0xFFFFFF) << 8 | crc32(canonical) << 32` (changed: canonical differs from the key;
     * empty canonical; hash kind; a leading or trailing dot). Then the per-type counts, the changed
     * and hash counts, the distinct non-empty canonical tokens and the XXH64 digest of all earlier
     * words.
     *
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function summarizeIdentityKeyBatch(array $keys): array
    {
        return self::usesW59RustFfi() ? self::summarizeIdentityKeyBatchNative($keys) : self::summarizeIdentityKeyBatchPhp($keys);
    }

    /**
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function summarizeIdentityKeyBatchPhp(array $keys): array
    {
        $out = $seen = [];
        $types = [0, 0, 0, 0];
        $changed = $hashes = 0;
        foreach ($keys as $key) {
            $key = (string) $key;
            $canonical = '';
            if ($key === '') {
                $type = 0;
            } elseif (\strlen($key) > 512) {
                $type = 1;
            } elseif (! mb_check_encoding($key, 'ASCII')) {
                $type = 2;
            } else {
                $type = 3;
                $canonical = (string) preg_replace('/[._ \t\n\x0B\f\r]+/', '.', strtolower(trim($key)));
            }
            $types[$type]++;
            $word = $type;
            $length = \strlen($canonical);
            if ($type === 3) {
                $hash = self::W59_HASH_LENGTHS[$length] ?? 0;
                if ($hash !== 0 && strspn($canonical, '0123456789abcdef') !== $length) {
                    $hash = 0;
                }
                $isChanged = $canonical !== $key ? 1 : 0;
                $edge = $length > 0 && ($canonical[0] === '.' || $canonical[$length - 1] === '.') ? 1 : 0;
                $changed += $isChanged;
                $hashes += $hash !== 0 ? 1 : 0;
                if ($length > 0) {
                    $seen[$canonical] = true;
                }
                $word |= $isChanged << 2 | ($length === 0 ? 1 : 0) << 3 | $hash << 4 | $edge << 6;
            }
            $out[] = $word | min($length, 0xFFFFFF) << 8 | crc32($canonical) << 32;
        }
        array_push($out, ...$types);
        array_push($out, $changed, $hashes, \count($seen));
        $out[] = self::w59Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, an item holds a NUL byte or the
     * column is not a list.
     *
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function summarizeIdentityKeyBatchNative(array $keys): array
    {
        $count = \count($keys);
        $words = $count + 8;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w59RustFfiState('out', $words);
        if ($state === null || ! array_is_list($keys)) {
            return self::summarizeIdentityKeyBatchPhp($keys);
        }
        $blob = implode("\0", $keys);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W59_IDENTITY_KEY_FFI_CDEF.)
            $written = $ffi->nntmux_w592_predb_identity_key_summary_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w59Words($written, $out, $words) ?? self::summarizeIdentityKeyBatchPhp($keys);
    }
}
