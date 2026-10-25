<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Throwable;

class AjaxController extends BasePageController
{
    private const string FFI_CDEF = 'ptrdiff_t nntmux_w33_passkey_auth_admin_inform_mcp(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustPasskeyAuthAdminInformMcpParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @throws \Exception
     */
    public function profile(Request $request): void
    {
        if ($request->has('action') && (int) $request->input('action') === 1 && $request->has('emailto')) {
            $emailTo = $request->input('emailto');
            $ret = User::sendInvite(url('/'), $this->userdata->id, $emailTo);
            if (! $ret) {
                echo self::normalizeProfileActionResponse(false, '', 'Invite not sent.')['message'];
            } else {
                echo self::normalizeProfileActionResponse(true, 'Invite sent. Alternatively paste them following link to register - '.$ret)['message'];
            }
        } else {
            echo self::normalizeProfileActionResponse(false, '', 'Invite not sent.')['message'];
        }
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
     * Pick the message for a profile AJAX action. The chosen message is returned verbatim
     * unless it is blank after trim(), in which case a generic default is used.
     *
     * @return array{success: bool, status: string, message: string}
     */
    public static function normalizeProfileActionResponse(bool $succeeded, string $successMessage, string $errorMessage = ''): array
    {
        return self::usesRustFfi()
            ? self::normalizeProfileActionResponseNative($succeeded, $successMessage, $errorMessage)
            : self::normalizeProfileActionResponsePhp($succeeded, $successMessage, $errorMessage);
    }

    /**
     * @return array{success: bool, status: string, message: string}
     */
    public static function normalizeProfileActionResponsePhp(bool $succeeded, string $successMessage, string $errorMessage = ''): array
    {
        $message = $succeeded ? $successMessage : $errorMessage;
        if (trim($message) === '') {
            $message = $succeeded ? 'Request completed.' : 'Request failed.';
        }

        return [
            'success' => $succeeded,
            'status' => $succeeded ? 'success' : 'error',
            'message' => $message,
        ];
    }

    /**
     * @return array{success: bool, status: string, message: string}
     */
    public static function normalizeProfileActionResponseNative(bool $succeeded, string $successMessage, string $errorMessage = ''): array
    {
        $result = self::nativePacket(3, [$succeeded ? '1' : '0', $successMessage, $errorMessage]);
        if ($result === null || count($result) !== 3) {
            return self::normalizeProfileActionResponsePhp($succeeded, $successMessage, $errorMessage);
        }

        return [
            'success' => $result[0] === '1',
            'status' => $result[1],
            'message' => $result[2],
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
