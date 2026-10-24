<?php

declare(strict_types=1);

namespace App\Services\IGDB;

use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class Client
{
    protected const string TOKEN_CACHE_KEY = 'igdb:access_token';

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_igdb_client_query_cache_key(const char *endpoint_ptr, size_t endpoint_len, const char *query_ptr, size_t query_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_igdb_client_normalize_target(const char *base_ptr, size_t base_len, const char *endpoint_ptr, size_t endpoint_len, size_t *out_base_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_igdb_client_compute_ttl(int32_t kind, int32_t val_kind, int64_t val_int, const char *val_ptr, size_t val_len, int64_t *out_ttl);
        ssize_t nntmux_igdb_client_build_auth_payload(int32_t mode, const char *client_id_ptr, size_t client_id_len, const char *secret_ptr, size_t secret_len, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustMetadataAnidbBookGamesIgdbMovieParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Build the cache key `'igdb:query:' . md5($endpoint . '|' . $query)`.
     */
    public static function buildQueryCacheKey(string $endpoint, string $query): string
    {
        return self::usesRustFfi()
            ? self::buildQueryCacheKeyNative($endpoint, $query)
            : self::buildQueryCacheKeyPhp($endpoint, $query);
    }

    public static function buildQueryCacheKeyNative(string $endpoint, string $query): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::buildQueryCacheKeyPhp($endpoint, $query);
        }
        $epLen = \strlen($endpoint);
        $qLen = \strlen($query);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return self::buildQueryCacheKeyPhp($endpoint, $query);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_client_query_cache_key($endpoint, $epLen, $query, $qLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::buildQueryCacheKeyPhp($endpoint, $query);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_igdb_client_query_cache_key($endpoint, $epLen, $query, $qLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::buildQueryCacheKeyPhp($endpoint, $query);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::buildQueryCacheKeyPhp($endpoint, $query);
        }
    }

    public static function buildQueryCacheKeyPhp(string $endpoint, string $query): string
    {
        return 'igdb:query:'.md5($endpoint.'|'.$query);
    }

    /**
     * Normalize `rtrim($baseUrl, '/')` and `ltrim($endpoint, '/')`.
     *
     * @return array{base_url: string, endpoint: string}
     */
    public static function normalizeRequestTarget(string $baseUrl, string $endpoint): array
    {
        return self::usesRustFfi()
            ? self::normalizeRequestTargetNative($baseUrl, $endpoint)
            : self::normalizeRequestTargetPhp($baseUrl, $endpoint);
    }

    /**
     * @return array{base_url: string, endpoint: string}
     */
    public static function normalizeRequestTargetNative(string $baseUrl, string $endpoint): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeRequestTargetPhp($baseUrl, $endpoint);
        }
        $baseLen = \strlen($baseUrl);
        $epLen = \strlen($endpoint);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $baseLen + $epLen + 16), $capacity);
        if ($buffer === null) {
            return self::normalizeRequestTargetPhp($baseUrl, $endpoint);
        }
        try {
            $outBaseLen = $ffi->new('size_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_client_normalize_target($baseUrl, $baseLen, $endpoint, $epLen, $outBaseLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeRequestTargetPhp($baseUrl, $endpoint);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_igdb_client_normalize_target($baseUrl, $baseLen, $endpoint, $epLen, $outBaseLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::normalizeRequestTargetPhp($baseUrl, $endpoint);
            }
            $split = (int) $outBaseLen[0];
            if ($split < 0 || $split > $written) {
                return self::normalizeRequestTargetPhp($baseUrl, $endpoint);
            }
            $blob = $written === 0 ? '' : FFI::string($buffer, $written);

            return [
                'base_url' => substr($blob, 0, $split),
                'endpoint' => substr($blob, $split),
            ];
        } catch (Throwable) {
            return self::normalizeRequestTargetPhp($baseUrl, $endpoint);
        }
    }

    /**
     * @return array{base_url: string, endpoint: string}
     */
    public static function normalizeRequestTargetPhp(string $baseUrl, string $endpoint): array
    {
        return [
            'base_url' => rtrim($baseUrl, '/'),
            'endpoint' => ltrim($endpoint, '/'),
        ];
    }

    /**
     * Normalize `max(0, (int) $lifetime)`.
     */
    public static function normalizeCacheLifetime(mixed $lifetime): int
    {
        return self::usesRustFfi()
            ? self::normalizeCacheLifetimeNative($lifetime)
            : self::normalizeCacheLifetimePhp($lifetime);
    }

    public static function normalizeCacheLifetimeNative(mixed $lifetime): int
    {
        $res = self::computeTtlNative(0, $lifetime);

        return $res ?? self::normalizeCacheLifetimePhp($lifetime);
    }

    public static function normalizeCacheLifetimePhp(mixed $lifetime): int
    {
        return max(0, (int) $lifetime);
    }

    /**
     * Compute Twitch OAuth token cache TTL `max(60, ((int) $expiresIn) - 60)`.
     */
    public static function computeTokenTtl(mixed $expiresIn): int
    {
        return self::usesRustFfi()
            ? self::computeTokenTtlNative($expiresIn)
            : self::computeTokenTtlPhp($expiresIn);
    }

    public static function computeTokenTtlNative(mixed $expiresIn): int
    {
        $res = self::computeTtlNative(1, $expiresIn);

        return $res ?? self::computeTokenTtlPhp($expiresIn);
    }

    public static function computeTokenTtlPhp(mixed $expiresIn): int
    {
        return max(60, ((int) $expiresIn) - 60);
    }

    /**
     * Build the IGDB API request headers array (`Accept` and `Client-ID`).
     *
     * @return array{Accept: string, 'Client-ID': string}
     */
    public static function buildRequestHeaders(string $clientId): array
    {
        return self::usesRustFfi()
            ? self::buildRequestHeadersNative($clientId)
            : self::buildRequestHeadersPhp($clientId);
    }

    /**
     * @return array{Accept: string, 'Client-ID': string}
     */
    public static function buildRequestHeadersNative(string $clientId): array
    {
        $decoded = self::buildAuthPayloadNative(0, $clientId, '');
        if (\is_array($decoded) && isset($decoded['Accept'], $decoded['Client-ID'])) {
            /** @var array{Accept: string, 'Client-ID': string} $decoded */
            return $decoded;
        }

        return self::buildRequestHeadersPhp($clientId);
    }

    /**
     * @return array{Accept: string, 'Client-ID': string}
     */
    public static function buildRequestHeadersPhp(string $clientId): array
    {
        return [
            'Accept' => 'application/json',
            'Client-ID' => $clientId,
        ];
    }

    /**
     * Build the Twitch OAuth client_credentials form payload.
     *
     * @return array{client_id: string, client_secret: string, grant_type: string}
     */
    public static function buildTokenFormPayload(string $clientId, string $clientSecret): array
    {
        return self::usesRustFfi()
            ? self::buildTokenFormPayloadNative($clientId, $clientSecret)
            : self::buildTokenFormPayloadPhp($clientId, $clientSecret);
    }

    /**
     * @return array{client_id: string, client_secret: string, grant_type: string}
     */
    public static function buildTokenFormPayloadNative(string $clientId, string $clientSecret): array
    {
        $decoded = self::buildAuthPayloadNative(1, $clientId, $clientSecret);
        if (\is_array($decoded) && isset($decoded['client_id'], $decoded['client_secret'], $decoded['grant_type'])) {
            /** @var array{client_id: string, client_secret: string, grant_type: string} $decoded */
            return $decoded;
        }

        return self::buildTokenFormPayloadPhp($clientId, $clientSecret);
    }

    /**
     * @return array{client_id: string, client_secret: string, grant_type: string}
     */
    public static function buildTokenFormPayloadPhp(string $clientId, string $clientSecret): array
    {
        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'client_credentials',
        ];
    }

    private static function computeTtlNative(int $kind, mixed $val): ?int
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        if ($val === null || $val === false) {
            $valKind = 0;
            $valInt = 0;
            $valStr = '';
        } elseif ($val === true) {
            $valKind = 1;
            $valInt = 1;
            $valStr = '';
        } elseif (\is_int($val)) {
            $valKind = 2;
            $valInt = $val;
            $valStr = '';
        } elseif (\is_string($val)) {
            $valKind = 3;
            $valInt = 0;
            $valStr = $val;
        } else {
            return null;
        }
        try {
            $outTtl = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_igdb_client_compute_ttl($kind, $valKind, $valInt, $valStr, \strlen($valStr), $outTtl);
            if ($rc === 1) {
                return (int) $outTtl[0];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildAuthPayloadNative(int $mode, string $clientId, string $clientSecret): ?array
    {
        if (($ffi = self::ffi()) === null || $clientId === '' || ($mode === 1 && $clientSecret === '')) {
            return null;
        }
        $idLen = \strlen($clientId);
        $secLen = \strlen($clientSecret);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $idLen + $secLen + 160), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_client_build_auth_payload($mode, $clientId, $idLen, $clientSecret, $secLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_igdb_client_build_auth_payload($mode, $clientId, $idLen, $clientSecret, $secLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, mixed>|null $result */
            $result = \is_array($decoded) ? $decoded : null;

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function request(string $endpoint, string $query): array
    {
        $cacheLifetime = self::normalizeCacheLifetime(config('igdb.cache_lifetime', 86400));
        $cacheKey = self::buildQueryCacheKey($endpoint, $query);

        if ($cacheLifetime > 0) {
            /** @var array<int, array<string, mixed>>|null $cached */
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $target = self::normalizeRequestTarget(
            (string) config('igdb.base_url', 'https://api.igdb.com/v4'),
            $endpoint,
        );

        $response = Http::baseUrl($target['base_url'])
            ->withHeaders(self::buildRequestHeaders($this->getClientId()))
            ->withToken($this->getAccessToken())
            ->withBody($query, 'text/plain')
            ->timeout(30)
            ->post($target['endpoint']);

        if ($response->failed()) {
            throw new IgdbHttpException(
                message: 'IGDB request failed with status '.$response->status(),
                statusCode: $response->status(),
                responseBody: $response->body(),
            );
        }

        /** @var array<int, array<string, mixed>> $payload */
        $payload = $response->json() ?? [];

        if ($cacheLifetime > 0) {
            Cache::put($cacheKey, $payload, $cacheLifetime);
        }

        return $payload;
    }

    protected function getClientId(): string
    {
        $clientId = (string) config('igdb.credentials.client_id', '');

        if ($clientId === '') {
            throw new IgdbHttpException('IGDB client ID is not configured.', 0);
        }

        return $clientId;
    }

    protected function getAccessToken(): string
    {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $clientSecret = (string) config('igdb.credentials.client_secret', '');
        if ($clientSecret === '') {
            throw new IgdbHttpException('IGDB client secret is not configured.', 0);
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post(
                (string) config('igdb.token_url', 'https://id.twitch.tv/oauth2/token'),
                self::buildTokenFormPayload($this->getClientId(), $clientSecret),
            );

        if ($response->failed()) {
            throw new IgdbHttpException(
                message: 'Unable to authenticate with Twitch for IGDB access.',
                statusCode: $response->status(),
                responseBody: $response->body(),
            );
        }

        $token = (string) $response->json('access_token', '');
        if ($token === '') {
            throw new IgdbHttpException('Twitch authentication response did not include an access token.', 0, $response->body());
        }

        $expiresIn = self::computeTokenTtl($response->json('expires_in', 3600));
        Cache::put(self::TOKEN_CACHE_KEY, $token, $expiresIn);

        return $token;
    }
}
