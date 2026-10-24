<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;

final class ApiUserResolver
{
    /**
     * Rust candidates for the cache key and credential classification. They stay
     * opt-in: neither beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchApiThrottleNoCacheFiltersParityTest.php).
     */
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_api_user_cache_key(const char *token, size_t token_len, char *out, size_t cap);
        int32_t nntmux_api_token_classify(const char *api_token, size_t api_token_len, int32_t api_token_kind, const char *apikey, size_t apikey_len, int32_t apikey_kind, int32_t *out_source);
        C;

    /** Credentials resolved through the v1 (`apikey`) lookup. */
    public const int VERSION_1 = 1;

    /** Credentials resolved through the v2 (`api_token`) lookup. */
    public const int VERSION_2 = 2;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function searchFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function v1(string $token): ?User
    {
        return Cache::remember(self::cacheKey($token), 300, static function () use ($token): ?User {
            $user = User::findVerifiedByApiToken($token);
            $user?->load('role');

            return $user;
        });
    }

    public function v2(string $token): ?User
    {
        return Cache::remember(self::cacheKey($token), 300, static fn (): ?User => User::query()
            ->whereApiToken($token)
            ->with('role')
            ->first());
    }

    public static function cacheKey(string $token): string
    {
        return self::$useRustFfi ? self::cacheKeyNative($token) : self::cacheKeyPhp($token);
    }

    public static function cacheKeyPhp(string $token): string
    {
        return 'api_user:'.md5($token);
    }

    public static function cacheKeyNative(string $token): string
    {
        $ffi = self::searchFfi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, 64, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::cacheKeyPhp($token);
        }

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_api_user_cache_key($token, \strlen($token), $scratch, $cap);

        return \is_int($written) && $written > 0 ? FFI::string($scratch, $written) : self::cacheKeyPhp($token);
    }

    /**
     * Pick the API token from the `api_token` and `apikey` request inputs:
     * `api_token ?? apikey` must be a non-empty string, and it resolves through
     * v2 when `api_token` is `filled()` and v1 otherwise.
     *
     * @return array{token: string, version: int}|null
     */
    public static function resolveCredentials(mixed $apiToken, mixed $apikey): ?array
    {
        return self::$useRustFfi
            ? self::resolveCredentialsNative($apiToken, $apikey)
            : self::resolveCredentialsPhp($apiToken, $apikey);
    }

    /**
     * @return array{token: string, version: int}|null
     */
    public static function resolveCredentialsPhp(mixed $apiToken, mixed $apikey): ?array
    {
        $token = $apiToken ?? $apikey;

        if (! \is_string($token) || $token === '') {
            return null;
        }

        // Request::filled(): a string counts once trimmed; null/apikey fallback never does.
        $version = \is_string($apiToken) && trim($apiToken) !== '' ? self::VERSION_2 : self::VERSION_1;

        return ['token' => $token, 'version' => $version];
    }

    /**
     * @return array{token: string, version: int}|null
     */
    public static function resolveCredentialsNative(mixed $apiToken, mixed $apikey): ?array
    {
        $ffi = self::searchFfi();
        if ($ffi === null) {
            return self::resolveCredentialsPhp($apiToken, $apikey);
        }

        $apiTokenBytes = \is_string($apiToken) ? $apiToken : '';
        $apikeyBytes = \is_string($apikey) ? $apikey : '';
        $source = $ffi->new('int32_t[1]');
        // @phpstan-ignore method.notFound
        $version = $ffi->nntmux_api_token_classify(
            $apiTokenBytes,
            \strlen($apiTokenBytes),
            self::inputKind($apiToken),
            $apikeyBytes,
            \strlen($apikeyBytes),
            self::inputKind($apikey),
            $source,
        );
        if (! \is_int($version) || $version < 0) {
            return self::resolveCredentialsPhp($apiToken, $apikey);
        }
        if ($version === 0) {
            return null;
        }

        return ['token' => $source[0] === 0 ? $apiTokenBytes : $apikeyBytes, 'version' => $version];
    }

    /**
     * Request-input kind for the C ABI: 0 null, 1 string, 2 any other value.
     */
    private static function inputKind(mixed $value): int
    {
        return match (true) {
            $value === null => 0,
            \is_string($value) => 1,
            default => 2,
        };
    }
}
