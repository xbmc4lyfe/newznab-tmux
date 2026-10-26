<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Services\Nzb\NzbParserService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

class SensitiveValueRedactor
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_mcp_redact_string(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        int32_t nntmux_mcp_is_sensitive_key(const char *input, size_t input_len);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public function redact(string $value): string
    {
        if (\strlen($value) < 6) {
            return $value;
        }

        if (self::$useRustFfi) {
            $native = self::redactNative($value);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->redactPhp($value);
    }

    public static function redactNative(string $value): ?string
    {
        $len = \strlen($value);
        if ($len < 6) {
            return $value;
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 128), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_mcp_redact_string($value, $len, $scratch, $cap);
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written === 0) {
                return $value;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_mcp_redact_string($value, $len, $scratch, $cap);
                if (! \is_int($written) || $written <= 0) {
                    return null;
                }
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private function redactPhp(string $value): string
    {
        $value = preg_replace(
            '/-----BEGIN [^-\r\n]*PRIVATE KEY-----.*?-----END [^-\r\n]*PRIVATE KEY-----/s',
            '[REDACTED PRIVATE KEY]',
            $value,
        ) ?? $value;

        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('#\b([a-z][a-z0-9+.-]*://)[^\s/:@]+:[^\s/@]+@#i', '$1[REDACTED]@', $value) ?? $value;
        $value = preg_replace(
            '/\b(password|passwd|token|secret|api[_-]?key|authorization)(\s*["\']?\s*(?:=>|=|:)\s*["\']?)([^\s,"\'}]+)/i',
            '$1$2[REDACTED]',
            $value,
        ) ?? $value;
        $value = preg_replace(
            '/\b([A-Z][A-Z0-9_]*(?:KEY|TOKEN|SECRET|PASSWORD|PASSWD))=([^\s]+)/',
            '$1=[REDACTED]',
            $value,
        ) ?? $value;

        return $value;
    }

    public function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redact($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $redacted[$key] = $this->isSensitiveKey((string) $key)
                ? '[REDACTED]'
                : $this->redactValue($item);
        }

        return $redacted;
    }

    public static function isSensitiveKeyNative(string $key): ?bool
    {
        $len = \strlen($key);
        if ($len < 5) {
            return false;
        }

        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_mcp_is_sensitive_key($key, $len);

            return \is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isSensitiveKey(string $key): bool
    {
        if (\strlen($key) < 5) {
            return false;
        }

        // Retain native PHP in production: single-key FFI call overhead (~85ns/key) exceeds
        // cached PCRE2 check (~60ns/key, 0.73x-0.78x speedup on 8-key batches).
        return $this->isSensitiveKeyPhp($key);
    }

    private function isSensitiveKeyPhp(string $key): bool
    {
        return preg_match('/(?:password|passwd|token|secret|api[_-]?key|authorization|credential)/i', $key) === 1;
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('mcp_sensitive_value_redactor', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    // ---- Wave 57.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 4.09x/3.40x, uncommon 3.97x/3.10x); see RustMcpPathGuardRepoContextRedactorStatusTmuxCollectorW57ParityTest. */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = <<<'C'
        intptr_t nntmux_w57_redaction_plan_batch(const char *keys, size_t keys_len, const char *values, size_t values_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 57 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w57', self::W57_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w57RustFfiState('ffi');
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

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planRedactionBatch() routed to Rust ($enabled) or PHP, then restores
     * the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w57Digest(array $out): int
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
    private static function w57Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * Batch redaction plan over (key, value) string pairs, each resolved as redactValue()
     * resolves a string item of an array: `[REDACTED]` for a sensitive key, else redact() of
     * the value. Per pair: `flags | min(length, 0xFFFFFF) << 8 | crc32(result) << 32`. Flags: 1
     * the key is sensitive, 2 redaction changed the value. Sensitive keys, redacted values,
     * unchanged values, the total result bytes (24-bit lengths) and the XXH64 digest of all
     * earlier words follow.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function planRedactionBatch(array $keys, array $values): array
    {
        return self::usesW57RustFfi() ? self::planRedactionBatchNative($keys, $values) : self::planRedactionBatchPhp($keys, $values);
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function planRedactionBatchPhp(array $keys, array $values): array
    {
        $redactor = new self;
        $values = array_values($values);
        $out = [];
        $sensitive = $redacted = $unchanged = $bytes = 0;
        foreach (array_values($keys) as $i => $key) {
            $value = (string) ($values[$i] ?? '');
            if ($redactor->isSensitiveKey((string) $key)) {
                $sensitive++;
                [$flags, $result] = [1, '[REDACTED]'];
            } else {
                $result = \strlen($value) < 6 ? $value : $redactor->redactPhp($value);
                $flags = $result !== $value ? 2 : 0;
                $flags === 2 ? $redacted++ : $unchanged++;
            }
            $length = min(\strlen($result), 0xFFFFFF);
            $bytes += $length;
            $out[] = $flags | $length << 8 | crc32($result) << 32;
        }
        array_push($out, $sensitive, $redacted, $unchanged, $bytes);
        $out[] = self::w57Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a key or value holds a NUL
     * byte, the columns are not lists of one length or PCRE runs with locale-specific
     * character tables.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $values
     * @return list<int>
     */
    public static function planRedactionBatchNative(array $keys, array $values): array
    {
        $count = \count($keys);
        $words = $count + 5;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 || \count($values) !== $count ? null : self::w57RustFfiState('out', $words);
        if ($state === null || ! array_is_list($keys) || ! array_is_list($values) || ! NzbParserService::pcreUsesDefaultTables()) {
            return self::planRedactionBatchPhp($keys, $values);
        }
        $keyBlob = implode("\0", $keys);
        $valueBlob = implode("\0", $values);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W57_FFI_CDEF.)
            $written = $ffi->nntmux_w57_redaction_plan_batch($keyBlob, \strlen($keyBlob), $valueBlob, \strlen($valueBlob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w57Words($written, $out, $words) ?? self::planRedactionBatchPhp($keys, $values);
    }
}
