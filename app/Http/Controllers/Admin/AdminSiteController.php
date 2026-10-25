<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\GrabStat;
use App\Models\ReleaseStat;
use App\Models\RoleStat;
use App\Models\SignupStat;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class AdminSiteController extends BasePageController
{
    private const string FFI_CDEF = 'ptrdiff_t nntmux_w33_passkey_auth_admin_inform_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyAuthAdminInformMcpParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /** @throws \Exception */
    public function stats(): mixed
    {
        $this->viewData = array_merge($this->viewData, [
            'topgrabs' => GrabStat::getTopGrabbers(),
            'recent' => ReleaseStat::getRecentlyAdded(),
            'usersbymonth' => SignupStat::getUsersByMonth(),
            'usersbyrole' => RoleStat::getUsersByRole(),
            'totusers' => 0,
            'totrusers' => 0,
            'title' => 'Site Stats',
            'meta_title' => 'Site Stats',
        ]);

        return view('admin.site.stats', $this->viewData);
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
     * Classify an alternate NNTP server setting. The host is trimmed and ASCII-lowercased;
     * compression is on for "1", "true", "yes" or "on" (trimmed, case-insensitive).
     *
     * @return array{configured: bool, host: string, transport: string, default_port: int, compression: bool, classification: string}
     */
    public static function classifyAlternateNntpConfiguration(string $host, string $compression, bool $useSsl): array
    {
        return self::usesRustFfi()
            ? self::classifyAlternateNntpConfigurationNative($host, $compression, $useSsl)
            : self::classifyAlternateNntpConfigurationPhp($host, $compression, $useSsl);
    }

    /**
     * @return array{configured: bool, host: string, transport: string, default_port: int, compression: bool, classification: string}
     */
    public static function classifyAlternateNntpConfigurationPhp(string $host, string $compression, bool $useSsl): array
    {
        $normalizedHost = strtolower(trim($host));
        $configured = $normalizedHost !== '';

        return [
            'configured' => $configured,
            'host' => $normalizedHost,
            'transport' => $useSsl ? 'ssl' : 'plain',
            'default_port' => $useSsl ? 563 : 119,
            'compression' => in_array(strtolower(trim($compression)), ['1', 'true', 'yes', 'on'], true),
            'classification' => match (true) {
                ! $configured => 'disabled',
                in_array($normalizedHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true) => 'local',
                $useSsl => 'secure',
                default => 'plaintext',
            },
        ];
    }

    /**
     * @return array{configured: bool, host: string, transport: string, default_port: int, compression: bool, classification: string}
     */
    public static function classifyAlternateNntpConfigurationNative(string $host, string $compression, bool $useSsl): array
    {
        $result = self::nativePacket(2, [$host, $compression, $useSsl ? '1' : '0']);
        if ($result === null || count($result) !== 6) {
            return self::classifyAlternateNntpConfigurationPhp($host, $compression, $useSsl);
        }

        return [
            'configured' => $result[0] === '1',
            'host' => $result[1],
            'transport' => $result[2],
            'default_port' => (int) $result[3],
            'compression' => $result[4] === '1',
            'classification' => $result[5],
        ];
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

            $cap = strlen($input) * 2 + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $out = $ffi->new("char[{$cap}]");
                // @phpstan-ignore method.notFound
                $written = (int) $ffi->nntmux_w33_passkey_auth_admin_inform_mcp($mode, $input, strlen($input), $out, $cap);
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
