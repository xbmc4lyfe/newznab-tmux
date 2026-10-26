<?php

declare(strict_types=1);

namespace App\Services\IGDB;

use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** Disabled: seven paired runs missed the 1.5x common p99 gate in three (min common mean 1.77x, p99 1.34x; uncommon mean 1.80x, p99 1.63x); shared PHP flattening dominates these short rows (see the W47 parity test). */
    public const bool W47_REQUEST_RUST_FFI_DEFAULT = false;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_igdb_client_request_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W47_REQUEST_KEYS = ['total_requests', 'empty_endpoint_count', 'known_endpoint_count', 'nested_endpoint_count', 'invalid_endpoint_count', 'token_ttl_sum', 'short_token_count', 'rate_limited_count', 'server_error_count', 'client_error_count', 'success_count', 'endpoint_digest'];

    private const array W47_ENDPOINTS = ['age_ratings' => true, 'alternative_names' => true, 'artworks' => true, 'collections' => true, 'companies' => true, 'covers' => true, 'external_games' => true, 'franchises' => true, 'game_modes' => true, 'game_videos' => true, 'games' => true, 'genres' => true, 'involved_companies' => true, 'multiquery' => true, 'platform_logos' => true, 'platforms' => true, 'release_dates' => true, 'screenshots' => true, 'search' => true, 'themes' => true, 'websites' => true];

    private static ?bool $w47UseRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW47RequestRustFfi(?bool $enable = true): void
    {
        self::$w47UseRustFfi = $enable;
    }

    public static function usesW47RequestRustFfi(): bool
    {
        return self::$w47UseRustFfi ?? self::W47_REQUEST_RUST_FFI_DEFAULT;
    }

    public static function resetW47RequestRustFfi(): void
    {
        self::$w47UseRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Summarize IGDB request descriptors (endpoint, expires_in, status) as
     * request() and getAccessToken() see them: endpoints trimmed of
     * whitespace and slashes that are empty, known v4 resources, nested or
     * outside [a-z0-9_/]; computeTokenTtl() of a strict 1-9 digit expires_in
     * (else 3600) and how many hit the 60 second floor; 429, 5xx, other 4xx
     * and 2xx statuses; and a 64-bit FNV-1a digest of the well-formed
     * endpoints, each followed by a newline. String rows are bare endpoints.
     *
     * @param  array<array-key, mixed>  $requests
     * @return array<string, int>
     */
    public static function summarizeRequestBatch(array $requests): array
    {
        return self::usesW47RequestRustFfi()
            ? self::summarizeRequestBatchNative($requests)
            : self::summarizeRequestBatchPhp($requests);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $requests
     * @return array<string, int>
     */
    public static function summarizeRequestBatchNative(array $requests): array
    {
        $fields = self::w47Fields($requests);

        return self::w47Call($fields) ?? self::w47Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $requests
     * @return array<string, int>
     */
    public static function summarizeRequestBatchPhp(array $requests): array
    {
        return self::w47Summary(self::w47Fields($requests));
    }

    /**
     * @param  array<array-key, mixed>  $requests
     * @return list<int|string>
     */
    private static function w47Fields(array $requests): array
    {
        $fields = [];
        foreach ($requests as $request) {
            // Integers stay integers: serialize() writes their decimal text, which Rust reads
            // as the string, and this loop is shared by both dispatches.
            if (is_array($request)) {
                $v = $request['endpoint'] ?? null;
                $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
                $v = $request['expires_in'] ?? null;
                $fields[] = is_string($v) || is_int($v) ? $v : '';
                $v = $request['status'] ?? null;
                $fields[] = is_string($v) || is_int($v) ? $v : '';
            } else {
                $v = $request;
                $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
                $fields[] = '';
                $fields[] = '';
            }
        }

        return $fields;
    }

    /**
     * @param  list<int|string>  $fields
     * @return array<string, int>
     */
    private static function w47Summary(array $fields): array
    {
        $summary = array_fill_keys(self::W47_REQUEST_KEYS, 0);
        $digest = '';
        for ($i = 0, $n = count($fields); $i < $n; $i += 3) {
            $summary['total_requests']++;
            $endpoint = trim((string) $fields[$i], " \t\n\r\0\x0B/");
            if ($endpoint === '') {
                $summary['empty_endpoint_count']++;
            } else {
                $summary['known_endpoint_count'] += (int) isset(self::W47_ENDPOINTS[$endpoint]);
                $summary['nested_endpoint_count'] += (int) str_contains($endpoint, '/');
                if (strspn($endpoint, 'abcdefghijklmnopqrstuvwxyz0123456789_/') !== strlen($endpoint)) {
                    $summary['invalid_endpoint_count']++;
                } else {
                    $digest .= $endpoint."\n";
                }
            }
            // Integers behave as their decimal text: 1-9 digits means 0..999999999.
            $expires = $fields[$i + 1];
            $seconds = is_int($expires)
                ? ($expires >= 0 && $expires <= 999999999 ? $expires : 3600)
                : (strlen($expires) <= 9 && ctype_digit($expires) ? (int) $expires : 3600);
            $ttl = max(60, $seconds - 60);
            $summary['token_ttl_sum'] += $ttl;
            $summary['short_token_count'] += (int) ($ttl === 60);
            // Three-digit text, or an integer 100..999; '0xx' strings have class 0.
            $status = $fields[$i + 2];
            $code = is_int($status)
                ? ($status >= 100 && $status <= 999 ? $status : 0)
                : (strlen($status) === 3 && ctype_digit($status) ? (int) $status : 0);
            $class = intdiv($code, 100);
            if ($code === 429) {
                $summary['rate_limited_count']++;
            } elseif ($class === 5) {
                $summary['server_error_count']++;
            } elseif ($class === 4) {
                $summary['client_error_count']++;
            } elseif ($class === 2) {
                $summary['success_count']++;
            }
        }
        /** @var array{1: int} $hash */
        $hash = unpack('J', hash('fnv1a64', $digest, true));
        $summary['endpoint_digest'] = $hash[1];

        return $summary;
    }

    /**
     * @param  list<int|string>  $fields
     * @return array<string, int>|null
     */
    private static function w47Call(array $fields): ?array
    {
        try {
            $input = serialize($fields);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w47Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w47', self::W47_FFI_CDEF) ?? false;
            $ffi = self::$w47Ffi;
            if ($ffi === false) {
                return null;
            }
            // obtainScratch() returns at least the 96 bytes requested.
            $out = RustPipelineLibrary::obtainScratch($ffi, 96);
            // @phpstan-ignore method.notFound (Declared by W47_FFI_CDEF.)
            if ($out === null || $ffi->nntmux_w47_igdb_client_request_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 3) {
                return null;
            }

            return array_combine(self::W47_REQUEST_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Wave 54.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.85x, p99 1.85x; uncommon mean 2.79x, p99 2.02x); see RustXxxPipeIgdbClientCompanyGamePlatformW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    public const string W54_FFI_CDEF = <<<'C'
        ssize_t nntmux_w544_igdb_request_row_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** W47_ENDPOINTS keys by code. */
    private const array W54_ENDPOINT_CODES = ['age_ratings' => 1, 'alternative_names' => 2, 'artworks' => 3, 'collections' => 4, 'companies' => 5, 'covers' => 6, 'external_games' => 7, 'franchises' => 8, 'game_modes' => 9, 'game_videos' => 10, 'games' => 11, 'genres' => 12, 'involved_companies' => 13, 'multiquery' => 14, 'platform_logos' => 15, 'platforms' => 16, 'release_dates' => 17, 'screenshots' => 18, 'search' => 19, 'themes' => 20, 'websites' => 21];

    private const string W54_ENDPOINT_TRIM = " \t\n\r\0\x0B/";

    /**
     * summarizeRequestBatchPhp() per request instead of as totals. Each (endpoint, expires_in,
     * status) yields four words: the endpoint trimmed of whitespace and slashes as a span
     * (offset << 32 | length), its flags (W47_ENDPOINTS code 1-21, else 0 | 32 empty | 64 nested
     * | 128 outside [a-z0-9_/]), computeTokenTtl() of a strict 1-9 digit expires_in (else 3600)
     * and the status as class (rate_limited 1, server 2, client 3, success 4, other 0) | 16 when
     * retryable | the 100-999 code (else 0) << 8.
     *
     * @param  list<string>  $endpoints
     * @param  list<string>  $expiresIn
     * @param  list<int>  $statuses
     * @return list<int>
     */
    public static function requestRowBatch(array $endpoints, array $expiresIn, array $statuses): array
    {
        return self::usesW54RustFfi()
            ? self::requestRowBatchNative($endpoints, $expiresIn, $statuses)
            : self::requestRowBatchPhp($endpoints, $expiresIn, $statuses);
    }

    /**
     * @param  list<string>  $endpoints
     * @param  list<string>  $expiresIn
     * @param  list<int>  $statuses
     * @return list<int>
     */
    public static function requestRowBatchNative(array $endpoints, array $expiresIn, array $statuses): array
    {
        $count = self::w54AssertColumns($endpoints, $expiresIn, $statuses);
        if ($count === 0) {
            return [];
        }

        return self::w54Call(implode("\0", $endpoints)."\0".implode("\0", $expiresIn), pack('P*', ...$statuses), $count, 4)
            ?? self::requestRowBatchPhp($endpoints, $expiresIn, $statuses);
    }

    /**
     * @param  list<string>  $endpoints
     * @param  list<string>  $expiresIn
     * @param  list<int>  $statuses
     * @return list<int>
     */
    public static function requestRowBatchPhp(array $endpoints, array $expiresIn, array $statuses): array
    {
        self::w54AssertColumns($endpoints, $expiresIn, $statuses);
        $out = [];
        foreach ($endpoints as $i => $endpoint) {
            $trimmed = trim($endpoint, self::W54_ENDPOINT_TRIM);
            $flags = $trimmed === ''
                ? 32
                : (self::W54_ENDPOINT_CODES[$trimmed] ?? 0)
                    | (str_contains($trimmed, '/') ? 64 : 0)
                    | (strspn($trimmed, 'abcdefghijklmnopqrstuvwxyz0123456789_/') !== \strlen($trimmed) ? 128 : 0);
            $expires = $expiresIn[$i];
            $seconds = \strlen($expires) <= 9 && ctype_digit($expires) ? (int) $expires : 3600;
            $status = $statuses[$i];
            $code = $status >= 100 && $status <= 999 ? $status : 0;
            $class = intdiv($code, 100);
            $category = $code === 429 ? 1 : match ($class) {
                5 => 2,
                4 => 3,
                2 => 4,
                default => 0,
            };
            $retryable = $code === 0 || $code === 408 || $code === 429 || $class === 5;
            array_push(
                $out,
                strspn($endpoint, self::W54_ENDPOINT_TRIM) << 32 | \strlen($trimmed),
                $flags,
                max(60, $seconds - 60),
                $category | ($retryable ? 16 : 0) | $code << 8,
            );
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $first
     * @param  array<array-key, mixed>  $second
     * @param  array<array-key, mixed>  $third
     */
    private static function w54AssertColumns(array $first, array $second, array $third): int
    {
        self::w54AssertList($first);
        self::w54AssertList($second);
        self::w54AssertList($third);
        $count = \count($first);
        if (\count($second) !== $count || \count($third) !== $count) {
            throw new \InvalidArgumentException('W54 batch columns must have the same length.');
        }

        return $count;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function w54AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W54 batch input must be a list.');
        }
    }

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W54 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('enabled');
        self::w54RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text columns and packed int64 columns in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w54Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w54RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w54', self::W54_FFI_CDEF) ?? false;
                self::w54RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w54RustFfiState('out', $out);
                self::w54RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w544_igdb_request_row_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * W54 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 4.09x, p99 3.60x; uncommon mean 4.82x, p99 3.75x); see RustGamesProcessorGdprNotificationGenreIgdbClientExceptionW67ParityTest. */
    public const bool W67_RUST_FFI_DEFAULT = true;

    public const string W67_IGDB_CLIENT_FFI_CDEF = <<<'C'
        ssize_t nntmux_w673_igdb_client_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 67.3 batch. */
    public const int W67_IGDB_CLIENT_TRAILER = 21;

    public static function enableW67RustFfi(): void
    {
        self::w67RustFfiState('set', true);
    }

    public static function disableW67RustFfi(): void
    {
        self::w67RustFfiState('set', false);
    }

    /** Restores W67_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW67RustFfi(): void
    {
        self::w67RustFfiState('reset');
    }

    public static function usesW67RustFfi(): bool
    {
        $override = self::w67RustFfiState();

        return \is_bool($override) ? $override : self::W67_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the IGDB Client Wave 67 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW67RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w67RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w67RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 67.3 IGDB request plan. Each item is `endpoint|expiresIn|tokenAge|requestsInWindow|windowAgeMs|query` (the
     * query is last, so it may hold `|`). The endpoint is trimmed of whitespace and `/`: code 0 empty, 1-21 per
     * W54_ENDPOINT_CODES, 22 another `[a-z0-9_/]` path or 23 other bytes; nested when it holds `/`. The trimmed query
     * sets bit i of the clause mask when it caselessly holds W67_IGDB_CLAUSES[i]; its limit is the digit run after the
     * first caseless "limit " (999999 beyond 6 digits). Query state 0 empty, 1 valid, 2 not ending in `;`, 3 without a
     * fields clause (other than multiquery) or 4 limit over W67_IGDB_MAX_LIMIT. Expiry, token age (9 digits), window
     * requests and window age (6) are 0 empty, 1 digits or 2 other; the token TTL is max(60, expiresIn (3600 unless
     * digits) - 60), expired when the age reaches it and refresh-soon within 300 seconds. A window of 1000 ms or more
     * resets the request count; W67_IGDB_RATE_LIMIT requests throttle until the window ends. Decision 6 malformed, 0
     * skipped (empty endpoint or query), 1 invalid endpoint, 2 invalid query, 3 refresh the token then send, 4
     * throttled or 5 send. Per item `code | nested << 5 | queryState << 6 | clauses << 9 | expiresState << 16 |
     * ageState << 18 | requestsState << 20 | windowState << 22 | expired << 24 | refreshSoon << 25 | throttled << 26 |
     * decision << 27 | waitMs << 30 | limit (cap 1023) << 40 | semicolons (cap 63) << 50 | queryLength (cap 127) <<
     * 56`. Then the seven decision counts, the known and nested endpoints, the five query-state counts, the expired,
     * refresh-soon and throttled rows, the wait and TTL totals, the distinct known endpoints and the 1-based first
     * sent item.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67IgdbClientPlanBatch(array $items): array
    {
        return self::usesW67RustFfi() ? self::w67IgdbClientPlanBatchNative($items) : self::w67IgdbClientPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67IgdbClientPlanBatchNative(array $items): array
    {
        return self::w67Invoke($items, \count($items) + self::W67_IGDB_CLIENT_TRAILER) ?? self::w67IgdbClientPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w67IgdbClientPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0, 0, 0];
        $queryStates = [0, 0, 0, 0, 0];
        $known = $nestedCount = $expiredCount = $refreshCount = $throttledCount = $waitSum = $ttlSum = $firstSend = 0;
        $endpoints = [];
        $index = 0;
        foreach ($items as $item) {
            [$rawEndpoint, $rawExpires, $rawAge, $rawUsed, $rawWindow, $rawQuery] = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            $endpoint = trim($rawEndpoint, self::W54_ENDPOINT_TRIM);
            $code = $endpoint === '' ? 0 : (self::W54_ENDPOINT_CODES[$endpoint]
                ?? (strspn($endpoint, 'abcdefghijklmnopqrstuvwxyz0123456789_/') === \strlen($endpoint) ? 22 : 23));
            $nested = str_contains($endpoint, '/');
            $query = trim($rawQuery);
            $clauses = 0;
            foreach (self::W67_IGDB_CLAUSES as $bit => $clause) {
                if (stripos($query, $clause) !== false) {
                    $clauses |= 1 << $bit;
                }
            }
            $semicolons = substr_count($query, ';');
            $limit = 0;
            $at = stripos($query, 'limit ');
            if ($at !== false) {
                $run = strspn($query, '0123456789', $at + 6);
                $limit = $run > 6 ? 999_999 : (int) substr($query, $at + 6, $run);
            }
            $queryState = match (true) {
                $query === '' => 0,
                $query[-1] !== ';' => 2,
                $code !== 14 && ($clauses & 1) === 0 => 3,
                $limit > self::W67_IGDB_MAX_LIMIT => 4,
                default => 1,
            };
            [$expiresState, $expires] = self::w67Count($rawExpires, 9);
            $ttl = max(60, ($expiresState === 1 ? $expires : 3600) - 60);
            [$ageState, $age] = self::w67Count($rawAge, 9);
            $expired = $age >= $ttl;
            $refreshSoon = ! $expired && $ttl - $age < 300;
            [$usedState, $used] = self::w67Count($rawUsed, 6);
            [$windowState, $window] = self::w67Count($rawWindow, 6);
            if ($window >= 1000) {
                $used = 0;
            }
            $throttled = $used >= self::W67_IGDB_RATE_LIMIT;
            $wait = $throttled ? 1000 - $window : 0;
            $decision = match (true) {
                $expiresState === 2 || $ageState === 2 || $usedState === 2 || $windowState === 2 => 6,
                $code === 0 || $queryState === 0 => 0,
                $code >= 22 => 1,
                $queryState >= 2 => 2,
                $throttled => 4,
                $expired => 3,
                default => 5,
            };
            $decisions[$decision]++;
            $queryStates[$queryState]++;
            if ($code >= 1 && $code <= 21) {
                $known++;
                $endpoints[$code] = true;
            }
            $nestedCount += $nested ? 1 : 0;
            $expiredCount += $expired ? 1 : 0;
            $refreshCount += $refreshSoon ? 1 : 0;
            $throttledCount += $throttled ? 1 : 0;
            $waitSum = min($waitSum + $wait, self::W67_TOTAL_CAP);
            $ttlSum = min($ttlSum + $ttl, self::W67_TOTAL_CAP);
            if ($decision === 5 && $firstSend === 0) {
                $firstSend = $index + 1;
            }
            $out[] = $code | ($nested ? 1 : 0) << 5 | $queryState << 6 | $clauses << 9 | $expiresState << 16 | $ageState << 18 | $usedState << 20
                | $windowState << 22 | ($expired ? 1 : 0) << 24 | ($refreshSoon ? 1 : 0) << 25 | ($throttled ? 1 : 0) << 26 | $decision << 27
                | $wait << 30 | min($limit, 1023) << 40 | min($semicolons, 63) << 50 | min(\strlen($query), 127) << 56;
            $index++;
        }
        array_push($out, ...$decisions);
        array_push($out, $known, $nestedCount);
        array_push($out, ...$queryStates);
        array_push($out, $expiredCount, $refreshCount, $throttledCount, $waitSum, $ttlSum, \count($endpoints), $firstSend);

        return $out;
    }

    /** Query clauses whose presence sets bits 0-6 of the Wave 67.3 clause mask. */
    private const array W67_IGDB_CLAUSES = ['fields ', 'where ', 'limit ', 'search ', 'sort ', 'offset ', 'exclude '];

    /** IGDB requests allowed per one-second window. */
    public const int W67_IGDB_RATE_LIMIT = 4;

    /** Largest IGDB `limit`. */
    public const int W67_IGDB_MAX_LIMIT = 500;

    /** Cap for the Wave 67.3 running totals. */
    private const int W67_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w67Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * Wave 67 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w67RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('services_w673', self::W67_IGDB_CLIENT_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w67RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 67.3 IGDB Client export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w67Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w67RustFfiState('ffi');
            $out = self::w67RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w67RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W67_IGDB_CLIENT_FFI_CDEF.)
            $written = $ffi->nntmux_w673_igdb_client_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
