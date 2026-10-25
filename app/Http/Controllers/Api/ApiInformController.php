<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Release;
use App\Models\ReleaseInform;
use App\Models\User;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ApiInformController extends Controller
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
     * http://sitename/api/inform/release?api_token=xxxxx&relo=xxx&relp=xxx.
     *
     *
     * @throws \Exception
     */
    public function release(Request $request): JsonResponse
    {
        $relO = $request->input('relo');
        $relP = $request->input('relp');
        $token = $request->input('api_token');
        if (($relO === null || is_string($relO)) && ($relP === null || is_string($relP)) && ($token === null || is_string($token))) {
            $params = self::normalizeInformRequestParams($relO, $relP, $token);
            $releaseObName = $params['relo'];
            $releasePrName = $params['relp'];
            $apiToken = $params['api_token'];
        } else {
            $releaseObName = $request->has('relo') && ! empty($request->input('relo')) ? $request->input('relo') : '';
            $releasePrName = $request->has('relp') && ! empty($request->input('relp')) ? $request->input('relp') : '';
            $apiToken = $request->has('api_token') && ! empty($request->input('api_token')) ? $request->input('api_token') : '';
        }
        $user = User::findVerifiedByApiToken((string) $request->input('api_token'));
        if (! $user) {
            return apiJsonError(100);
        }

        if ($user->is_disabled || $user->hasRole('Disabled')) {
            return apiJsonError(101);
        }

        if (! empty($releaseObName) && ! empty($releasePrName) && ! empty($apiToken)) {
            ReleaseInform::insertOrIgnore(['relOName' => $releaseObName, 'relPName' => $releasePrName, 'api_token' => $apiToken, 'created_at' => now(), 'updated_at' => now()]);
            $release = Release::whereSearchname($releaseObName)->first();
            if (! empty($release)) {
                (new ReleaseUpdateService)->updateRelease($release, $releasePrName, 'Release Inform API', true, 'Filenames, ', true, true);
            }

            return response()->json(['message' => 'Release Information Added!'], 200);
        }

        return response()->json(['message' => 'Indexer inform error, wrong data supplied!'], 404);
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
     * Mirror the inform endpoint's `empty()` handling for string inputs: null, "" and "0"
     * become "", every other value is kept verbatim. `complete` requires all three.
     *
     * @return array{relo: string, relp: string, api_token: string, complete: bool}
     */
    public static function normalizeInformRequestParams(?string $relO, ?string $relP, ?string $apiToken): array
    {
        return self::usesRustFfi()
            ? self::normalizeInformRequestParamsNative($relO, $relP, $apiToken)
            : self::normalizeInformRequestParamsPhp($relO, $relP, $apiToken);
    }

    /**
     * @return array{relo: string, relp: string, api_token: string, complete: bool}
     */
    public static function normalizeInformRequestParamsPhp(?string $relO, ?string $relP, ?string $apiToken): array
    {
        $relO = empty($relO) ? '' : $relO;
        $relP = empty($relP) ? '' : $relP;
        $apiToken = empty($apiToken) ? '' : $apiToken;

        return [
            'relo' => $relO,
            'relp' => $relP,
            'api_token' => $apiToken,
            'complete' => $relO !== '' && $relP !== '' && $apiToken !== '',
        ];
    }

    /**
     * @return array{relo: string, relp: string, api_token: string, complete: bool}
     */
    public static function normalizeInformRequestParamsNative(?string $relO, ?string $relP, ?string $apiToken): array
    {
        $result = self::nativePacket(4, [(string) $relO, (string) $relP, (string) $apiToken]);
        if ($result === null || count($result) !== 4) {
            return self::normalizeInformRequestParamsPhp($relO, $relP, $apiToken);
        }

        return [
            'relo' => $result[0],
            'relp' => $result[1],
            'api_token' => $result[2],
            'complete' => $result[3] === '1',
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
