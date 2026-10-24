<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
