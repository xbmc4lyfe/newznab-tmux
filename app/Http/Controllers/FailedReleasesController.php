<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Release;
use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class FailedReleasesController extends BasePageController
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_failed_release_dnzb_error_spec(
        int32_t code,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_failed_release_build_location_query(
        const char *guid_ptr,
        size_t guid_len,
        uint32_t has_token,
        const char *token_ptr,
        size_t token_len,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustCoverCartFailedControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = (bool) $useRustFfi;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function failed(Request $request): Application|Response|\Illuminate\Contracts\Foundation\Application|ResponseFactory
    {
        if ($request->missing('guid')) {
            $err = self::buildDnzbErrorSpec(400);

            return response($err['body'], $err['status'])->withHeaders($err['headers']);
        }

        $user = $this->resolveUser($request);
        if ($user === null) {
            $err = self::buildDnzbErrorSpec(401);

            return response($err['body'], $err['status'])->withHeaders($err['headers']);
        }

        $alt = Release::getAlternate($request->input('guid'), $user->id);

        if (empty($alt)) {
            $err = self::buildDnzbErrorSpec(404);

            return response($err['body'], $err['status'])->withHeaders($err['headers']);
        }

        $query = self::buildAlternateLocationQuery(
            (string) $alt['guid'],
            $request->filled('api_token'),
            $user->api_token
        );

        return response('Success')->withHeaders([
            'Location' => url('/getnzb').'?'.$query,
        ]);
    }

    /**
     * @return array{status: int, body: string, headers: array{X-DNZB-RCode: int, X-DNZB-RText: string}}
     */
    public static function buildDnzbErrorSpec(int $statusCode): array
    {
        if (self::$useRustFfi) {
            return self::buildDnzbErrorSpecNative($statusCode);
        }

        return self::buildDnzbErrorSpecPhp($statusCode);
    }

    /**
     * @return array{status: int, body: string, headers: array{X-DNZB-RCode: int, X-DNZB-RText: string}}
     */
    public static function buildDnzbErrorSpecPhp(int $statusCode): array
    {
        return match ($statusCode) {
            400 => [
                'status' => 400,
                'body' => 'Bad request, please supply all parameters!',
                'headers' => [
                    'X-DNZB-RCode' => 400,
                    'X-DNZB-RText' => 'Bad request, please supply all parameters!',
                ],
            ],
            401 => [
                'status' => 401,
                'body' => 'Unauthorised, wrong rss key!',
                'headers' => [
                    'X-DNZB-RCode' => 401,
                    'X-DNZB-RText' => 'Unauthorised, wrong rss key!',
                ],
            ],
            default => [
                'status' => 404,
                'body' => 'No NZB found for alternate match!',
                'headers' => [
                    'X-DNZB-RCode' => 404,
                    'X-DNZB-RText' => 'No NZB found for alternate match.',
                ],
            ],
        };
    }

    /**
     * @return array{status: int, body: string, headers: array{X-DNZB-RCode: int, X-DNZB-RText: string}}
     */
    public static function buildDnzbErrorSpecNative(int $statusCode): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildDnzbErrorSpecPhp($statusCode);
        }

        try {
            $normalizedCode = in_array($statusCode, [400, 401, 404], true) ? $statusCode : 404;
            $lens = $ffi->new('size_t[2]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
            if ($buf === null) {
                return self::buildDnzbErrorSpecPhp($statusCode);
            }
            $rc = (int) self::callFfi($ffi, 'nntmux_failed_release_dnzb_error_spec', $normalizedCode, $lens, $buf, $cap);
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::buildDnzbErrorSpecPhp($statusCode);
                }
                $rc = (int) self::callFfi($ffi, 'nntmux_failed_release_dnzb_error_spec', $normalizedCode, $lens, $buf, $cap);
            }
            if ($rc < 0) {
                return self::buildDnzbErrorSpecPhp($statusCode);
            }

            $bodyLen = (int) $lens[0];
            $rtextLen = (int) $lens[1];
            $packed = FFI::string($buf, $rc);
            $body = substr($packed, 0, $bodyLen);
            $rtext = substr($packed, $bodyLen, $rtextLen);

            return [
                'status' => $normalizedCode,
                'body' => $body,
                'headers' => [
                    'X-DNZB-RCode' => $normalizedCode,
                    'X-DNZB-RText' => $rtext,
                ],
            ];
        } catch (Throwable) {
            return self::buildDnzbErrorSpecPhp($statusCode);
        }
    }

    public static function buildAlternateLocationQuery(string $guid, bool $includeToken, ?string $apiToken): string
    {
        if (self::$useRustFfi) {
            return self::buildAlternateLocationQueryNative($guid, $includeToken, $apiToken);
        }

        return self::buildAlternateLocationQueryPhp($guid, $includeToken, $apiToken);
    }

    public static function buildAlternateLocationQueryPhp(string $guid, bool $includeToken, ?string $apiToken): string
    {
        $locationParams = ['id' => $guid];
        if ($includeToken) {
            $locationParams['r'] = $apiToken;
        }

        return http_build_query($locationParams, '', '&', PHP_QUERY_RFC3986);
    }

    public static function buildAlternateLocationQueryNative(string $guid, bool $includeToken, ?string $apiToken): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildAlternateLocationQueryPhp($guid, $includeToken, $apiToken);
        }

        try {
            $hasToken = ($includeToken && $apiToken !== null) ? 1 : 0;
            $tokenStr = $apiToken ?? '';
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(128, (strlen($guid) + strlen($tokenStr)) * 3 + 16), $cap);
            if ($buf === null) {
                return self::buildAlternateLocationQueryPhp($guid, $includeToken, $apiToken);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_failed_release_build_location_query',
                $guid,
                strlen($guid),
                $hasToken,
                $tokenStr,
                strlen($tokenStr),
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::buildAlternateLocationQueryPhp($guid, $includeToken, $apiToken);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_failed_release_build_location_query',
                    $guid,
                    strlen($guid),
                    $hasToken,
                    $tokenStr,
                    strlen($tokenStr),
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::buildAlternateLocationQueryPhp($guid, $includeToken, $apiToken);
            }

            return FFI::string($buf, $rc);
        } catch (Throwable) {
            return self::buildAlternateLocationQueryPhp($guid, $includeToken, $apiToken);
        }
    }

    private function resolveUser(Request $request): ?User
    {
        if ($request->has('api_token')) {
            $token = $request->input('api_token');

            return is_string($token) && $token !== '' ? User::findVerifiedByApiToken($token) : null;
        }

        $sessionUser = $request->user();
        if ($request->isMethod('post') && $sessionUser instanceof User) {
            return $sessionUser;
        }

        return null;
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
