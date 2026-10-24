<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use App\Support\Passkeys\RelyingPartyIdResolver;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

final class ConfigureCeremonyStepManagerFactoryAction extends \Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction
{
    private const string FFI_CDEF = 'ptrdiff_t nntmux_w32_passkey_reindex_proxy_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyReindexProxyMcpParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public function execute(): CeremonyStepManagerFactory
    {
        $factory = parent::execute();

        $request = request();
        $rpId = RelyingPartyIdResolver::resolve($request);

        $factory->setAllowedOrigins(self::normalizeAllowedRpOrigins($request->getSchemeAndHttpHost(), $rpId));

        return $factory;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Allowed WebAuthn origins: the request origin, then `https://{rpId}`, plus
     * `http://localhost` for local development; duplicates keep their first position.
     *
     * @return list<string>
     */
    public static function normalizeAllowedRpOrigins(?string $appUrl, ?string $rpId): array
    {
        return self::usesRustFfi()
            ? self::normalizeAllowedRpOriginsNative($appUrl, $rpId)
            : self::normalizeAllowedRpOriginsPhp($appUrl, $rpId);
    }

    /**
     * @return list<string>
     */
    public static function normalizeAllowedRpOriginsPhp(?string $appUrl, ?string $rpId): array
    {
        $origins = [];
        if ($appUrl !== null) {
            $origins[] = $appUrl;
        }

        if ($rpId !== null) {
            // HTTPS origins for normal environments.
            $origins[] = "https://{$rpId}";

            // Local development allowance for localhost over HTTP.
            if ($rpId === 'localhost') {
                $origins[] = 'http://localhost';
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * @return list<string>
     */
    public static function normalizeAllowedRpOriginsNative(?string $appUrl, ?string $rpId): array
    {
        return self::nativePacket(1, [
            $appUrl !== null ? '1' : '0',
            $appUrl ?? '',
            $rpId !== null ? '1' : '0',
            $rpId ?? '',
        ]) ?? self::normalizeAllowedRpOriginsPhp($appUrl, $rpId);
    }

    /**
     * Count- and length-prefixed (little-endian u32) packets preserve PHP byte strings.
     *
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private static function nativePacket(int $mode, array $items): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null || count($items) > 100000) {
            return null;
        }

        try {
            $input = pack('V', count($items));
            foreach ($items as $item) {
                $input .= pack('V', strlen($item)).$item;
            }
            if (strlen($input) > 16777216) {
                return null;
            }

            $cap = strlen($input) + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $out = $ffi->new("char[{$cap}]");
                // @phpstan-ignore method.notFound
                $written = (int) $ffi->nntmux_w32_passkey_reindex_proxy_mcp($mode, $input, strlen($input), $out, $cap);
                if ($written >= 4 && $written <= $cap) {
                    return self::unpackNativePacket(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $cap) {
                    return null;
                }
                $cap = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function unpackNativePacket(string $packet): ?array
    {
        $length = strlen($packet);
        $header = unpack('V', $packet);
        $count = is_array($header) ? $header[1] : null;
        if (! is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $prefix = unpack('V', $packet, $offset);
            $size = is_array($prefix) ? $prefix[1] : null;
            $offset += 4;
            if (! is_int($size) || $size > $length - $offset) {
                return null;
            }
            $parts[] = substr($packet, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$ffi = RustPipelineLibrary::resolveDomain(
                'search',
                self::FFI_CDEF,
                is_string($path) && $path !== '' ? $path : null,
            ) ?? false;
        }

        return self::$ffi instanceof FFI ? self::$ffi : null;
    }
}
