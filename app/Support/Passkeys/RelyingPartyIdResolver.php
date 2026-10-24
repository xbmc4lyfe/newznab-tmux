<?php

declare(strict_types=1);

namespace App\Support\Passkeys;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Throwable;

final class RelyingPartyIdResolver
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_passkey_resolve_rp_host(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?FFI\CData $scratch = null;

    private static int $scratchCap = 0;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function scratch(FFI $ffi, int $minBytes): ?FFI\CData
    {
        if (self::$scratch === null || self::$scratchCap < $minBytes) {
            self::$scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, self::$scratchCap);
        }

        return self::$scratch;
    }

    public static function resolve(?Request $request = null): string
    {
        return (self::$useRustFfi ? self::resolveNative($request) : null)
            ?? self::resolvePhp($request);
    }

    public static function resolvePhp(?Request $request = null): string
    {
        $request ??= request();

        $candidates = [
            self::firstHostPhp((string) $request->header('X-Forwarded-Host', '')),
            self::firstHostPhp((string) $request->header('X-Original-Host', '')),
            (string) config('passkeys.relying_party.id', ''),
            (string) $request->getHost(),
        ];

        foreach ($candidates as $candidate) {
            if (self::isValidRelyingPartyIdPhp($candidate)) {
                return $candidate;
            }
        }

        return 'localhost';
    }

    public static function resolveNative(?Request $request = null): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $request ??= request();
        $rawFrames = [
            (string) $request->header('X-Forwarded-Host', ''),
            (string) $request->header('X-Original-Host', ''),
            (string) config('passkeys.relying_party.id', ''),
            (string) $request->getHost(),
        ];
        $payload = '';
        foreach ($rawFrames as $frame) {
            $payload .= pack('V', strlen($frame)).$frame;
        }
        try {
            $scratch = self::scratch($ffi, max(256, strlen($payload) + 32));
            if ($scratch === null) {
                return null;
            }
            $cap = self::$scratchCap;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_passkey_resolve_rp_host($payload, strlen($payload), 2, $scratch, $cap);
            if (! is_int($written) || $written < 0 || $written > $cap) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public static function firstHost(string $value): string
    {
        return (self::$useRustFfi ? self::firstHostNative($value) : null)
            ?? self::firstHostPhp($value);
    }

    public static function firstHostPhp(string $value): string
    {
        $host = trim(explode(',', $value)[0] ?? '');

        if ($host === '') {
            return '';
        }

        // Strip optional port suffix.
        return trim((string) preg_replace('/:\d+$/', '', $host));
    }

    public static function firstHostNative(string $value): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $scratch = self::scratch($ffi, max(256, strlen($value) + 16));
            if ($scratch === null) {
                return null;
            }
            $cap = self::$scratchCap;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_passkey_resolve_rp_host($value, strlen($value), 0, $scratch, $cap);
            if (! is_int($written) || $written < 0 || $written > $cap) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    public static function isValidRelyingPartyId(string $value): bool
    {
        return (self::$useRustFfi ? self::isValidRelyingPartyIdNative($value) : null)
            ?? self::isValidRelyingPartyIdPhp($value);
    }

    public static function isValidRelyingPartyIdPhp(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if ($value === 'localhost') {
            return true;
        }

        return str_contains($value, '.');
    }

    public static function isValidRelyingPartyIdNative(string $value): ?bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_passkey_resolve_rp_host($value, strlen($value), 1, null, 0);
            if (! is_int($status) || $status < 0) {
                return null;
            }

            return $status === 1;
        } catch (Throwable) {
            return null;
        }
    }
}
