<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Cloudflare\CloudflareIpRangeService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class TrustProxies extends Middleware
{
    private const string FFI_CDEF = 'ptrdiff_t nntmux_w32_passkey_reindex_proxy_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyReindexProxyMcpParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * The headers used to detect proxy forwarding information.
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * An empty proxy list means "no trusted proxies" (`null`); anything else passes through unchanged.
     *
     * @param  array<array-key, mixed>|string|null  $configuredProxies
     * @return array<array-key, mixed>|string|null
     */
    public static function normalizeTrustedProxyList(array|string|null $configuredProxies): array|string|null
    {
        return self::usesRustFfi()
            ? self::normalizeTrustedProxyListNative($configuredProxies)
            : self::normalizeTrustedProxyListPhp($configuredProxies);
    }

    /**
     * @param  array<array-key, mixed>|string|null  $configuredProxies
     * @return array<array-key, mixed>|string|null
     */
    public static function normalizeTrustedProxyListPhp(array|string|null $configuredProxies): array|string|null
    {
        return $configuredProxies !== [] ? $configuredProxies : null;
    }

    /**
     * Only string lists cross the FFI boundary; keyed or non-string arrays use PHP.
     *
     * @param  array<array-key, mixed>|string|null  $configuredProxies
     * @return array<array-key, mixed>|string|null
     */
    public static function normalizeTrustedProxyListNative(array|string|null $configuredProxies): array|string|null
    {
        if ($configuredProxies === null) {
            $items = ['n'];
        } elseif (is_string($configuredProxies)) {
            $items = ['s', $configuredProxies];
        } else {
            if (! array_is_list($configuredProxies)) {
                return self::normalizeTrustedProxyListPhp($configuredProxies);
            }
            $items = ['a'];
            foreach ($configuredProxies as $proxy) {
                if (! is_string($proxy)) {
                    return self::normalizeTrustedProxyListPhp($configuredProxies);
                }
                $items[] = $proxy;
            }
        }

        $result = self::nativePacket(2, $items);

        return match (true) {
            $result === ['n'] => null,
            $result !== null && $result[0] === 's' && count($result) === 2 => $result[1],
            $result !== null && $result[0] === 'a' && count($result) > 1 => array_slice($result, 1),
            default => self::normalizeTrustedProxyListPhp($configuredProxies),
        };
    }

    /**
     * Resolve trusted proxies from the stored Cloudflare manifest and any
     * manually configured proxy ranges.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        return self::normalizeTrustedProxyList(app(CloudflareIpRangeService::class)->trustedProxies());
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
