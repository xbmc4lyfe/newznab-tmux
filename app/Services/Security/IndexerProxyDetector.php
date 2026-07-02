<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Behavioural detector for direct NZBHydra2/Prowlarr proxy fetches.
 *
 * Combines four weak signals into a single score so that no individual signal
 * can false-positive a legitimate user. Redirected grabs (indexer hands a URL
 * to a download client that fetches it with its own downloader UA) score zero
 * because none of the signals fire: downloader UA, no indexer Referer, UA
 * changed between search and download, normal download/search ratio, and the
 * IP's last indexer-search UA differs from the download UA.
 *
 * State is kept in the cache using a sliding window (default 1 hour). Cache
 * operations are portable across the file, database and Redis stores.
 */
final class IndexerProxyDetector
{
    private const CACHE_PREFIX = 'proxy_detect:';

    private const MAX_TRACKED_USER_AGENTS = 5;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * Score a download request and decide whether it should be blocked.
     *
     * Reads the sliding-window counters populated by recordSearch(), then
     * records this download so the ratio signal reflects real traffic.
     */
    public function analyze(ProxyRequestContext $ctx): ProxyVerdict
    {
        if (! $this->enabled()) {
            return ProxyVerdict::allow();
        }

        $score = 0;
        $reasons = [];

        // 1. Referer — indexers leak their own host (e.g. http://hydra.local:5076/...).
        if ($this->refererLooksLikeIndexer($ctx->referer)) {
            $score += 30;
            $reasons['referer'] = 30;
        }

        // 2. UA-pair — the same api_token recently searched with this exact UA.
        //    Same UA on search + download is the proxy fingerprint; a redirected
        //    grab swaps to the download client's UA and never matches.
        if ($ctx->apiToken !== null
            && in_array($ctx->userAgent, $this->recentUserAgentsForToken($ctx->apiToken), true)
        ) {
            $score += 25;
            $reasons['ua_pair'] = 25;
        }

        // 3. Download-to-search ratio — proxies download almost everything they
        //    search; humans search many and download few. Gated behind a minimum
        //    search count so a brand-new token can't trip it.
        if ($ctx->apiToken !== null) {
            $searches = $this->searchCount($ctx->apiToken);
            $downloads = $this->downloadCount($ctx->apiToken);

            if ($searches >= $this->minSearches()) {
                $ratio = $downloads / max($searches, 1);

                if ($ratio >= $this->ratioMin()) {
                    $score += 25;
                    $reasons['download_ratio'] = 25;
                }
            }
        }

        // 4. IP correlation — the same client IP just searched with a known
        //    indexer UA and is now downloading with that same UA.
        if ($this->recentIndexerUserAgentForIp($ctx->clientIp) === $ctx->userAgent) {
            $score += 20;
            $reasons['ip_correlation'] = 20;
        }

        // Record this download so the ratio window stays accurate.
        $this->recordDownload($ctx);

        return new ProxyVerdict($score >= $this->threshold(), $score, $reasons);
    }

    /**
     * Feed the sliding windows from a search/RSS request.
     *
     * Without this, signals 2, 3 and 4 have no data to correlate against.
     */
    public function recordSearch(ProxyRequestContext $ctx): void
    {
        if (! $this->enabled()) {
            return;
        }

        $ttl = $this->windowSeconds();

        if ($ctx->apiToken !== null) {
            $this->increment($this->key('count', $ctx->apiToken, 'searches'), $ttl);
            $this->pushUserAgentForToken($ctx->apiToken, $ctx->userAgent, $ttl);
        }

        // Remember the indexer UA that just searched from this IP so a later
        // download from the same IP + UA can be correlated.
        if ($ctx->userAgent !== '') {
            $this->cache->put($this->key('ip', $ctx->clientIp, 'indexer_ua'), $ctx->userAgent, $ttl);
        }
    }

    /**
     * Bump the per-token download counter for the ratio signal.
     */
    public function recordDownload(ProxyRequestContext $ctx): void
    {
        if (! $this->enabled() || $ctx->apiToken === null) {
            return;
        }

        $this->increment($this->key('count', $ctx->apiToken, 'downloads'), $this->windowSeconds());
    }

    /**
     * @return array<int, string>
     */
    private function recentUserAgentsForToken(string $token): array
    {
        $value = $this->cache->get($this->key('ua', $token));

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function pushUserAgentForToken(string $token, string $userAgent, int $ttl): void
    {
        if ($userAgent === '') {
            return;
        }

        $userAgents = $this->recentUserAgentsForToken($token);

        // Move to front, de-duplicate, cap the list (LPUSH + LTRIM equivalent).
        array_unshift($userAgents, $userAgent);
        $userAgents = array_values(array_unique($userAgents));
        $userAgents = array_slice($userAgents, 0, self::MAX_TRACKED_USER_AGENTS);

        $this->cache->put($this->key('ua', $token), $userAgents, $ttl);
    }

    private function recentIndexerUserAgentForIp(string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }

        $value = $this->cache->get($this->key('ip', $ip, 'indexer_ua'));

        return is_string($value) ? $value : null;
    }

    private function searchCount(string $token): int
    {
        return (int) $this->cache->get($this->key('count', $token, 'searches'), 0);
    }

    private function downloadCount(string $token): int
    {
        return (int) $this->cache->get($this->key('count', $token, 'downloads'), 0);
    }

    /**
     * Increment a counter, seeding it with a TTL on first write so the whole
     * window expires together (portable across cache stores).
     */
    private function increment(string $key, int $ttl): void
    {
        if ($this->cache->get($key) === null) {
            $this->cache->put($key, 1, $ttl);

            return;
        }

        $this->cache->increment($key);
    }

    private function refererLooksLikeIndexer(?string $referer): bool
    {
        if ($referer === null || $referer === '') {
            return false;
        }

        return $this->matchesAnyPattern($referer, $this->indexerRefererPatterns());
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesAnyPattern(string $haystack, array $patterns): bool
    {
        $lower = strtolower($haystack);

        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function indexerRefererPatterns(): array
    {
        $configured = config('nntmux.proxy_detection_indexer_referer_patterns', '');

        if (is_array($configured)) {
            $patterns = $configured;
        } else {
            $patterns = preg_split('/[\r\n,]+/', (string) $configured) ?: [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $pattern): string => is_string($pattern) ? trim($pattern) : '',
            $patterns,
        )));
    }

    private function key(string ...$parts): string
    {
        return self::CACHE_PREFIX.implode(':', array_map(
            static fn (string $part): string => str_contains($part, ':') || strlen($part) > 40
                ? md5($part)
                : $part,
            $parts,
        ));
    }

    private function enabled(): bool
    {
        return (bool) config('nntmux.proxy_detection_enabled', false);
    }

    private function threshold(): int
    {
        return (int) config('nntmux.proxy_detection_threshold', 50);
    }

    private function windowSeconds(): int
    {
        return (int) config('nntmux.proxy_detection_window_seconds', 3600);
    }

    private function ratioMin(): float
    {
        return (float) config('nntmux.proxy_detection_ratio_min', 0.8);
    }

    private function minSearches(): int
    {
        return (int) config('nntmux.proxy_detection_min_searches', 20);
    }
}
