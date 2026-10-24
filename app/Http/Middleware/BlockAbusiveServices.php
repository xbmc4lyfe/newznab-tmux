<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\IndexerProxyDetector;
use App\Services\Security\ProxyRequestContext;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Middleware to block abusive streaming services and cloud providers.
 *
 * Blocks:
 * - AIOStreams (User-Agent based)
 * - UsenetStreamer (User-Agent based)
 * - Configured indexer apps proxying NZB downloads (User-Agent based, opt-in).
 *   Only NZB download/grab requests are blocked, so proxied searches and RSS
 *   feeds keep working. Redirected grabs (Prowlarr/NZBHydra2 "Redirect" setting)
 *   arrive directly from the download client, whose User-Agent does not match
 *   the configured proxy patterns, and are therefore allowed.
 * - Oracle Cloud (ASN: AS31898)
 * - Cloudflare WARP (ASN: AS13335)
 */
class BlockAbusiveServices
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_http_matches_user_agent_patterns_nul(const char *user_agent, size_t user_agent_len, uint32_t use_default_blocked, const char *patterns_nul, size_t patterns_len, size_t patterns_count);
        ssize_t nntmux_http_redact_sensitive_query_keys_nul(const char *keys_nul, size_t keys_len, size_t keys_count, char *out_buf, size_t out_cap);
        C;

    /**
     * @var array<int, string>
     */
    private const DEFAULT_BLOCKED_USER_AGENTS = [
        'aiostreams',
        'usenetstreamer',
        'stremio',
    ];

    /**
     * @var array<int, string>
     */
    private const SENSITIVE_QUERY_PARAMETERS = [
        'apikey',
        'api_token',
        'token',
        'passkey',
    ];

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * User-Agent patterns to block (case-insensitive).
     *
     * @var array<string>
     */
    protected array $blockedUserAgents = [
        'aiostreams',
        'usenetstreamer',
        'stremio',
    ];

    /**
     * Blocked ASNs (Autonomous System Numbers).
     * Format: ASN number => Description
     *
     * @var array<int, string>
     */
    protected array $blockedAsns = [
        31898 => 'Oracle Cloud Infrastructure',
        13335 => 'Cloudflare WARP',
    ];

    /**
     * Cache TTL for ASN lookups (in seconds).
     * Default: 24 hours
     */
    protected int $cacheTtl = 86400;

    public function __construct(private readonly IndexerProxyDetector $detector) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent() ?? '';

        // Check for blocked User-Agents
        if ($this->isBlockedUserAgent($userAgent)) {
            Log::warning('Blocked abusive User-Agent', [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'uri' => $this->safeRequestUri($request),
            ]);

            return $this->blockedResponse('Access denied: Streaming services are not allowed.');
        }

        // Behavioural proxy-fetch detection. Downloads are scored against the
        // sliding-window signals; searches/RSS feed those windows so a later
        // download from the same token/IP can be correlated. Redirected grabs
        // score zero and pass through.
        $isDownload = $this->isNzbDownloadRequest($request);
        $isSearch = $this->isSearchRequest($request);

        if ($isDownload || $isSearch) {
            $ctx = ProxyRequestContext::fromRequest($request, $isDownload, $isSearch);

            if ($isDownload) {
                $verdict = $this->detector->analyze($ctx);

                if ($verdict->shouldBlock) {
                    Log::warning('Blocked proxied NZB download (behavioural)', [
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                        'score' => $verdict->score,
                        'reasons' => $verdict->reasons,
                        'uri' => $this->safeRequestUri($request),
                    ]);

                    return $this->blockedResponse('Access denied: Proxying NZB downloads through indexer apps is not allowed.');
                }
            } else {
                $this->detector->recordSearch($ctx);
            }
        }

        if ($this->shouldBlockProxyIndexerApp($request, $userAgent)) {
            Log::warning('Blocked proxied NZB download from indexer app', [
                'ip' => $ip,
                'user_agent' => $userAgent,
                'uri' => $this->safeRequestUri($request),
            ]);

            return $this->blockedResponse('Access denied: Proxying NZB downloads through indexer apps is not allowed.');
        }

        // Check for blocked ASNs
        $asnInfo = $this->getAsnInfo($ip);
        if ($asnInfo !== null && isset($this->blockedAsns[$asnInfo['asn']])) {
            Log::warning('Blocked ASN', [
                'ip' => $ip,
                'asn' => $asnInfo['asn'],
                'org' => $asnInfo['org'] ?? 'Unknown',
                'blocked_reason' => $this->blockedAsns[$asnInfo['asn']],
                'user_agent' => $userAgent,
                'uri' => $this->safeRequestUri($request),
            ]);

            return $this->blockedResponse(
                sprintf('Access denied: %s is not allowed.', $this->blockedAsns[$asnInfo['asn']])
            );
        }

        return $next($request);
    }

    /**
     * Check if the User-Agent matches any blocked patterns.
     *
     * @param  array<int, string>|null  $blockedPatterns
     */
    public function isBlockedUserAgent(string $userAgent, ?array $blockedPatterns = null): bool
    {
        // Retain native PHP in production: single-string `strtolower` + 3 `str_contains` checks (~116 ns)
        // beats FFI call boundary overhead (~185 ns, 0.49x-0.63x).
        return $this->isBlockedUserAgentPhp($userAgent, $blockedPatterns);
    }

    /**
     * @param  array<int, string>|null  $blockedPatterns
     */
    public function isBlockedUserAgentPhp(string $userAgent, ?array $blockedPatterns = null): bool
    {
        $patterns = $blockedPatterns ?? $this->blockedUserAgents;
        $lowerUserAgent = strtolower($userAgent);

        foreach ($patterns as $pattern) {
            if (str_contains($lowerUserAgent, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>|null  $blockedPatterns
     */
    public function isBlockedUserAgentNative(string $userAgent, ?array $blockedPatterns = null): ?bool
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $patterns = $blockedPatterns ?? $this->blockedUserAgents;
        if ($patterns === self::DEFAULT_BLOCKED_USER_AGENTS) {
            $ffi = self::$ffi ?? self::resolveFfi();
            if ($ffi === null || $ffi === false) {
                return null;
            }
            try {
                // @phpstan-ignore method.notFound
                $res = $ffi->nntmux_http_matches_user_agent_patterns_nul($userAgent, \strlen($userAgent), 1, null, 0, 0);

                return \is_int($res) && $res >= 0 ? ($res === 1) : null;
            } catch (Throwable) {
                return null;
            }
        }

        return self::matchesAnyUserAgentPatternNative($userAgent, $patterns);
    }

    /**
     * Check whether an opt-in proxy indexer app block should apply.
     */
    protected function shouldBlockProxyIndexerApp(Request $request, string $userAgent): bool
    {
        if (! (bool) config('nntmux.block_proxy_indexer_apps', false)) {
            return false;
        }

        if (! $this->isNzbDownloadRequest($request)) {
            return false;
        }

        return $this->matchesAnyUserAgentPattern(
            $userAgent,
            $this->configuredProxyIndexerAppUserAgents()
        );
    }

    /**
     * Detect NZB download/grab requests so only proxied downloads are blocked.
     *
     * Searches, caps and RSS feeds are intentionally out of scope so proxied
     * searches keep working. Redirected grabs arrive directly from the download
     * client, whose User-Agent does not match the configured proxy patterns.
     */
    protected function isNzbDownloadRequest(Request $request): bool
    {
        if ($request->is('api/v2/getnzb')) {
            return true;
        }

        if (! $request->is('api/v1/api')) {
            return false;
        }

        $type = strtolower(trim((string) $request->input('t', '')));

        return in_array($type, ['get', 'g'], true);
    }

    /**
     * Detect search / caps / RSS requests that should feed the correlation
     * windows. These populate the per-token and per-IP signals so a later
     * download from the same client can be scored.
     */
    protected function isSearchRequest(Request $request): bool
    {
        // RSS feeds are search-like: a client discovering releases to grab.
        if ($request->is('rss/*')) {
            return true;
        }

        // v2 REST search/caps endpoints.
        if ($request->is(
            'api/v2/search',
            'api/v2/movies',
            'api/v2/audio',
            'api/v2/books',
            'api/v2/anime',
            'api/v2/tv',
            'api/v2/capabilities',
        )) {
            return true;
        }

        // v1 newznab: t= identifies the operation.
        if (! $request->is('api/v1/api')) {
            return false;
        }

        $type = strtolower(trim((string) $request->input('t', '')));

        return in_array($type, ['search', 'tvsearch', 'movie', 'music', 'book', 'caps'], true);
    }

    /**
     * @return array<int, string>
     */
    protected function configuredProxyIndexerAppUserAgents(): array
    {
        $configured = config('nntmux.block_proxy_indexer_app_user_agents', []);

        if (is_string($configured)) {
            $configured = preg_split('/[\r\n,]+/', $configured) ?: [];
        }

        if (! is_array($configured)) {
            Log::warning('Ignoring invalid proxy indexer app User-Agent configuration.', [
                'configured_type' => get_debug_type($configured),
            ]);

            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $pattern): string => is_string($pattern) ? trim($pattern) : '',
            $configured,
        )));
    }

    /**
     * @param  array<int, string>  $patterns
     */
    public function matchesAnyUserAgentPattern(string $userAgent, array $patterns): bool
    {
        // Retain native PHP in production: NUL-joining `$patterns` + FFI boundary (~255-505 ns)
        // exceeds in-process `strtolower` + `str_contains` loop (~150-260 ns, 0.51x-0.59x).
        return $this->matchesAnyUserAgentPatternPhp($userAgent, $patterns);
    }

    /**
     * @param  array<int, string>  $patterns
     */
    public function matchesAnyUserAgentPatternPhp(string $userAgent, array $patterns): bool
    {
        $lowerUserAgent = strtolower($userAgent);

        foreach ($patterns as $pattern) {
            if (str_contains($lowerUserAgent, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    public static function matchesAnyUserAgentPatternNative(string $userAgent, array $patterns): ?bool
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $count = \count($patterns);
        if ($count === 0) {
            return false;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        if ($patterns === self::DEFAULT_BLOCKED_USER_AGENTS) {
            try {
                // @phpstan-ignore method.notFound
                $res = $ffi->nntmux_http_matches_user_agent_patterns_nul($userAgent, \strlen($userAgent), 1, null, 0, 0);

                return \is_int($res) && $res >= 0 ? ($res === 1) : null;
            } catch (Throwable) {
                return null;
            }
        }

        foreach ($patterns as $p) {
            if (\str_contains($p, "\0")) {
                return null;
            }
        }
        $joined = implode("\0", $patterns);

        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_http_matches_user_agent_patterns_nul($userAgent, \strlen($userAgent), 0, $joined, \strlen($joined), $count);

            return \is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Return the request URI with sensitive query parameter values redacted for logs.
     */
    protected function safeRequestUri(Request $request): string
    {
        $query = $request->query();
        if ($query === []) {
            return $request->getPathInfo();
        }

        $queryString = http_build_query(
            $this->redactSensitiveQueryParameters($query),
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return $queryString === ''
            ? $request->getPathInfo()
            : $request->getPathInfo().'?'.$queryString;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function redactSensitiveQueryParameters(array $parameters): array
    {
        return $this->redactSensitiveQueryParametersPhp($parameters);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function redactSensitiveQueryParametersPhp(array $parameters): array
    {
        $redacted = [];

        foreach ($parameters as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_QUERY_PARAMETERS, true)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_array($value)
                ? $this->redactSensitiveQueryParametersPhp($value)
                : $value;
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>|null
     */
    public function redactSensitiveQueryParametersNative(array $parameters): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $count = \count($parameters);
        if ($count === 0) {
            return [];
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $strKeys = [];
        foreach ($parameters as $key => $_) {
            $ks = (string) $key;
            if (\str_contains($ks, "\0")) {
                return null;
            }
            $strKeys[] = $ks;
        }
        $joined = implode("\0", $strKeys);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(64, $count), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_http_redact_sensitive_query_keys_nul($joined, \strlen($joined), $count, $scratch, $cap);
            if (! \is_int($written) || $written !== $count) {
                return null;
            }
            $flags = FFI::string($scratch, $count);
        } catch (Throwable) {
            return null;
        }

        $redacted = [];
        $idx = 0;
        foreach ($parameters as $key => $value) {
            if ($flags[$idx] === '1') {
                $redacted[$key] = '[redacted]';
                $idx++;

                continue;
            }
            $idx++;
            if (is_array($value)) {
                $nested = $this->redactSensitiveQueryParametersNative($value);
                if ($nested === null) {
                    return null;
                }
                $redacted[$key] = $nested;
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    /**
     * Get ASN information for an IP address.
     * Uses ip-api.com with caching to minimize API calls.
     *
     * @return array{asn: int, org: string}|null
     */
    protected function getAsnInfo(string $ip): ?array
    {
        // Skip private/local IPs
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $cacheKey = 'asn_lookup_'.md5($ip);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($ip) {
            return $this->fetchAsnFromApi($ip);
        });
    }

    /**
     * Fetch ASN info from ip-api.com.
     *
     * @return array{asn: int, org: string}|null
     */
    protected function fetchAsnFromApi(string $ip): ?array
    {
        try {
            $response = Http::timeout(3)
                ->retry(2, 100)
                ->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,as,org',
                ]);

            if (! $response->successful()) {
                Log::debug('ASN lookup failed', ['ip' => $ip, 'status' => $response->status()]);

                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'success' || empty($data['as'])) {
                return null;
            }

            // Parse ASN from "AS31898 Oracle Corporation" format
            if (preg_match('/^AS(\d+)/', $data['as'], $matches)) {
                return [
                    'asn' => (int) $matches[1],
                    'org' => $data['org'] ?? $data['as'],
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('ASN lookup exception', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Generate a blocked response.
     */
    protected function blockedResponse(string $message): Response
    {
        return response()->json([
            'error' => true,
            'message' => $message,
        ], 403);
    }
}
