<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

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

    private const string FFI_CDEF = <<<'C'
        int32_t nntmux_proxy_detector_match_referer(const char *haystack, size_t haystack_len, const char *patterns, size_t patterns_len, size_t count, uint8_t mode);
        ssize_t nntmux_proxy_detector_cache_key_nul(const char *input, size_t input_len, size_t count, char *out_buf, size_t out_cap);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    public function __construct(private readonly CacheRepository $cache) {}

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

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

        if (self::$useRustFfi) {
            $configured = config('nntmux.proxy_detection_indexer_referer_patterns', '');
            if (! is_array($configured)) {
                $native = self::refererLooksLikeIndexerNative($referer, (string) $configured);
                if ($native !== null) {
                    return $native;
                }
            }
        }

        return $this->matchesAnyPattern($referer, $this->indexerRefererPatterns());
    }

    public static function refererLooksLikeIndexerNative(?string $referer, string $configuredPatterns): ?bool
    {
        if ($referer === null || $referer === '' || $configuredPatterns === '') {
            return false;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_proxy_detector_match_referer(
                $referer,
                strlen($referer),
                $configuredPatterns,
                strlen($configuredPatterns),
                0,
                0,
            );

            return is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesAnyPattern(string $haystack, array $patterns): bool
    {
        // Retain native PHP loop for pre-split pattern arrays: implode + FFI crossing is 0.64x mean / 0.71x p99.
        $lower = strtolower($haystack);

        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    public static function matchesAnyPatternNative(string $haystack, array $patterns): ?bool
    {
        $count = count($patterns);
        if ($count === 0) {
            return false;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $joined = implode("\0", $patterns);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_proxy_detector_match_referer(
                $haystack,
                strlen($haystack),
                $joined,
                strlen($joined),
                $count,
                1,
            );

            return is_int($res) && $res >= 0 ? ($res === 1) : null;
        } catch (Throwable) {
            return null;
        }
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
        // Retain native PHP key assembly in production: 2..3 short strings with implode + FFI::string is 1.10x mean / 1.25x p99 (< 1.5x).
        return self::CACHE_PREFIX.implode(':', array_map(
            static fn (string $part): string => str_contains($part, ':') || strlen($part) > 40
                ? md5($part)
                : $part,
            $parts,
        ));
    }

    public static function keyNative(string ...$parts): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $count = count($parts);
        $joined = $count === 0 ? '' : implode("\0", $parts);
        if ($count > 0 && substr_count($joined, "\0") !== $count - 1) {
            return null;
        }
        $len = strlen($joined);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + $count * 33 + 32), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_proxy_detector_cache_key_nul($joined, $len, $count, $scratch, $cap);
            if (! is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_proxy_detector_cache_key_nul($joined, $len, $count, $scratch, $cap);
                if (! is_int($written) || $written <= 0) {
                    return null;
                }
            }

            return $written <= 0 ? null : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('indexer_proxy_detector', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
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

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W47 parity test). */
    public const bool W47_SCORE_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_proxy_signal_score_batch(const char *ips, size_t ips_len, const char *agents, size_t agents_len, const char *referers, size_t referers_len, size_t count, const char *patterns, size_t patterns_len, char *out, size_t cap);
        C;

    /** Lowercase User-Agent fragments of indexer proxies and *arr clients. */
    public const array W47_INDEXER_AGENTS = ['nzbhydra', 'prowlarr', 'jackett', 'sonarr', 'radarr', 'lidarr', 'readarr', 'mylar'];

    /** Lowercase User-Agent fragments of download clients that fetch redirected grabs. */
    public const array W47_DOWNLOADER_AGENTS = ['sabnzbd', 'nzbget', 'nzbvortex', 'nzbunity'];

    private static ?bool $w47ScoreRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark default; setUseRustFfi() is untouched. */
    public static function useW47ScoreRustFfi(?bool $enabled = true): void
    {
        self::$w47ScoreRustFfi = $enabled;
    }

    public static function usesW47ScoreRustFfi(): bool
    {
        return self::$w47ScoreRustFfi ?? self::W47_SCORE_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle. */
    public static function resetW47ScoreRustFfi(): void
    {
        self::$w47ScoreRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Stateless header/User-Agent/subnet signal scores for a request batch, one int each:
     * `score | flags << 8`. Flags: 1 referer matches a configured pattern (+30), 2 indexer UA
     * (+25), 4 downloader UA (-20 without an indexer UA), 8 empty UA (+15), 16 private or loopback
     * client IP (+20 with an indexer UA), 32 invalid client IP, 64 referer names localhost, a
     * `.local`/`.lan` host or a private IP (+10). Scores clamp to 0..100. Patterns are split
     * like `proxy_detection_indexer_referer_patterns` and matched case-insensitively.
     *
     * @param  list<string>  $clientIps
     * @param  list<string>  $userAgents  Parallel to $clientIps.
     * @param  list<string>  $referers  Parallel to $clientIps; '' when absent.
     * @return list<int>
     */
    public static function scoreRequests(array $clientIps, array $userAgents, array $referers, string $configuredPatterns): array
    {
        return self::usesW47ScoreRustFfi()
            ? self::scoreRequestsNative($clientIps, $userAgents, $referers, $configuredPatterns)
            : self::scoreRequestsPhp($clientIps, $userAgents, $referers, $configuredPatterns);
    }

    /**
     * @param  list<string>  $clientIps
     * @param  list<string>  $userAgents
     * @param  list<string>  $referers
     * @return list<int>
     */
    public static function scoreRequestsPhp(array $clientIps, array $userAgents, array $referers, string $configuredPatterns): array
    {
        $patterns = [];
        foreach (preg_split('/[\r\n,]+/', $configuredPatterns) ?: [] as $pattern) {
            $pattern = strtolower(trim($pattern));
            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }
        $out = [];
        foreach ($clientIps as $i => $ip) {
            $agent = strtolower($userAgents[$i] ?? '');
            $referer = strtolower($referers[$i] ?? '');
            $score = $flags = 0;
            if ($referer !== '') {
                foreach ($patterns as $pattern) {
                    if (str_contains($referer, $pattern)) {
                        $score += 30;
                        $flags |= 1;

                        break;
                    }
                }
            }
            if ($agent === '') {
                $score += 15;
                $flags |= 8;
            } else {
                foreach (self::W47_INDEXER_AGENTS as $token) {
                    if (str_contains($agent, $token)) {
                        $score += 25;
                        $flags |= 2;

                        break;
                    }
                }
                foreach (self::W47_DOWNLOADER_AGENTS as $token) {
                    if (str_contains($agent, $token)) {
                        $flags |= 4;

                        break;
                    }
                }
            }
            $packed = self::w47PackedIp($ip);
            if ($packed === null) {
                $flags |= 32;
            } elseif (self::w47PrivateIp($packed)) {
                $flags |= 16;
                if (($flags & 2) !== 0) {
                    $score += 20;
                }
            }
            if (self::w47RefererLocal($referer)) {
                $score += 10;
                $flags |= 64;
            }
            if (($flags & 6) === 4) {
                $score -= 20;
            }
            $out[] = max(0, min(100, $score)) | $flags << 8;
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or
     * a value holds a NUL byte.
     *
     * @param  list<string>  $clientIps
     * @param  list<string>  $userAgents
     * @param  list<string>  $referers
     * @return list<int>
     */
    public static function scoreRequestsNative(array $clientIps, array $userAgents, array $referers, string $configuredPatterns): array
    {
        $count = \count($clientIps);
        if ($count === 0) {
            return [];
        }
        $ffi = self::$w47Ffi instanceof FFI ? self::$w47Ffi : self::w47Ffi();
        $ips = implode("\0", $clientIps);
        $agents = implode("\0", $userAgents);
        $refs = implode("\0", $referers);
        if ($ffi === null || \count($userAgents) !== $count || \count($referers) !== $count
            || substr_count($ips, "\0") !== $count - 1 || substr_count($agents, "\0") !== $count - 1 || substr_count($refs, "\0") !== $count - 1) {
            return self::scoreRequestsPhp($clientIps, $userAgents, $referers, $configuredPatterns);
        }
        $bytes = $count * 8;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, $bytes, $cap);
            if ($scratch === null) {
                return self::scoreRequestsPhp($clientIps, $userAgents, $referers, $configuredPatterns);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_proxy_signal_score_batch($ips, \strlen($ips), $agents, \strlen($agents), $refs, \strlen($refs), $count, $configuredPatterns, \strlen($configuredPatterns), $scratch, $cap);
            $words = $written === $bytes ? unpack('P*', FFI::string($scratch, $bytes)) : false;
        } catch (Throwable) {
            $words = false;
        }

        /** @var list<int>|null $values */
        $values = \is_array($words) ? array_values($words) : null;

        return $values ?? self::scoreRequestsPhp($clientIps, $userAgents, $referers, $configuredPatterns);
    }

    private static function w47Ffi(): ?FFI
    {
        self::$w47Ffi ??= RustPipelineLibrary::resolveDomain('cleaning_w47', self::W47_FFI_CDEF) ?? false;

        return self::$w47Ffi === false ? null : self::$w47Ffi;
    }

    /**
     * `inet_pton()` bytes of a `filter_var()`-valid IP, otherwise null.
     */
    private static function w47PackedIp(string $ip): ?string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);

        return $packed === false ? null : $packed;
    }

    /**
     * Loopback, RFC 1918, link-local and CGNAT IPv4; IPv6 loopback, ULA and link-local.
     */
    private static function w47PrivateIp(string $packed): bool
    {
        $a = \ord($packed[0]);
        $b = \ord($packed[1]);
        if (\strlen($packed) === 4) {
            return $a === 10 || $a === 127 || ($a === 172 && ($b & 0xF0) === 16) || ($a === 192 && $b === 168)
                || ($a === 169 && $b === 254) || ($a === 100 && ($b & 0xC0) === 64);
        }

        return $packed === "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\1" || ($a & 0xFE) === 0xFC || ($a === 0xFE && ($b & 0xC0) === 0x80);
    }

    /**
     * Whether a lowercase `http(s)://` referer names localhost, a `.local`/`.lan` host or a private IP.
     */
    private static function w47RefererLocal(string $referer): bool
    {
        if (str_starts_with($referer, 'http://')) {
            $rest = substr($referer, 7);
        } elseif (str_starts_with($referer, 'https://')) {
            $rest = substr($referer, 8);
        } else {
            return false;
        }
        $authority = substr($rest, 0, strcspn($rest, '/?#'));
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        if ($authority !== '' && $authority[0] === '[') {
            $close = strpos($authority, ']');
            $host = $close === false ? '' : substr($authority, 1, $close - 1);
        } else {
            $colon = strpos($authority, ':');
            $host = $colon === false ? $authority : substr($authority, 0, $colon);
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.lan')) {
            return true;
        }
        $packed = self::w47PackedIp($host);

        return $packed !== null && self::w47PrivateIp($packed);
    }

    // Rust is the default only while the paired W59.6 benchmark clears 1.5x on all four metrics.
    public const bool W59_RUST_FFI_DEFAULT = true;

    public const string W59_FFI_CDEF = <<<'C'
        int32_t nntmux_w596_proxy_detect_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, size_t count, int64_t threshold, int64_t *out, size_t out_cap);
        C;

    private const int W59_MAX_ITEMS = 1_048_576;

    public const int W59_DETECT_HEADER = 22;

    /** Lowercase indexer proxy and *arr fragments (bits 0-7), then download clients (bits 8-11). */
    private const array W59_SIGNATURES = ['nzbhydra', 'prowlarr', 'jackett', 'sonarr', 'radarr', 'lidarr', 'readarr', 'mylar',
        'sabnzbd', 'nzbget', 'nzbvortex', 'nzbunity'];

    /**
     * Header-signature scores for a request batch. Signatures match the lowercased User-Agent; hops are
     * comma-separated Via or X-Forwarded-For entries that are not blank. Score: +40 indexer UA, -20
     * downloader-only UA, +15 empty UA, +10 per Via hop (3 max), +5 per forwarded hop (4 max), +20 when
     * Via names an indexer; clamped to 0..100 and blocked at `$threshold`.
     *
     * Layout: `[0]` count, `[1]` blocked, `[2]` indexer, `[3]` downloader, `[4]` empty UA, `[5]` Via
     * hops, `[6]` forwarded hops (each capped at 255), `[7]` max score, `[8]` first blocked index (-1),
     * `[9..20]` per-signature hits, `[21]` FNV-1a digest; then per request `score | blocked << 7 |
     * first signature + 1 << 8 | signatures << 12 | min(via, 255) << 24 | min(xff, 255) << 32 |
     * via indexer << 40 | empty UA << 41 | min(UA bytes, 65535) << 42`.
     *
     * @param  array<array-key, string>  $userAgents
     * @param  array<array-key, string>  $vias  Parallel to $userAgents; '' when absent.
     * @param  array<array-key, string>  $forwardedFor  Parallel to $userAgents; '' when absent.
     * @return list<int>
     */
    public static function detectProxyBatch(array $userAgents, array $vias, array $forwardedFor, int $threshold = 50): array
    {
        return (self::usesW59RustFfi() ? self::detectProxyBatchNative($userAgents, $vias, $forwardedFor, $threshold) : null)
            ?? self::detectProxyBatchPhp($userAgents, $vias, $forwardedFor, $threshold);
    }

    /**
     * @param  array<array-key, string>  $userAgents
     * @param  array<array-key, string>  $vias
     * @param  array<array-key, string>  $forwardedFor
     * @return list<int>
     */
    public static function detectProxyBatchPhp(array $userAgents, array $vias, array $forwardedFor, int $threshold = 50): array
    {
        $userAgents = array_values($userAgents);
        $vias = array_values($vias);
        $forwardedFor = array_values($forwardedFor);
        $out = array_fill(0, self::W59_DETECT_HEADER, 0);
        $out[0] = \count($userAgents);
        $out[8] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($userAgents as $at => $raw) {
            $raw = (string) $raw;
            $agent = strtolower($raw);
            $via = strtolower((string) ($vias[$at] ?? ''));
            $mask = $first = 0;
            if ($agent !== '') {
                foreach (self::W59_SIGNATURES as $bit => $signature) {
                    if (str_contains($agent, $signature)) {
                        $mask |= 1 << $bit;
                        if ($first === 0) {
                            $first = $bit + 1;
                        }
                        $out[9 + $bit]++;
                    }
                }
            }
            $indexer = ($mask & 0xFF) !== 0;
            $downloader = ($mask & 0xF00) !== 0;
            $empty = $agent === '';
            $viaHops = self::w59Hops($via);
            $xffHops = self::w59Hops((string) ($forwardedFor[$at] ?? ''));
            $viaIndexer = false;
            if ($via !== '') {
                for ($bit = 0; $bit < 8; $bit++) {
                    if (str_contains($via, self::W59_SIGNATURES[$bit])) {
                        $viaIndexer = true;

                        break;
                    }
                }
            }
            $score = $indexer ? 40 : ($downloader ? -20 : 0);
            if ($empty) {
                $score += 15;
            }
            $score += min($viaHops, 3) * 10 + min($xffHops, 4) * 5;
            if ($viaIndexer) {
                $score += 20;
            }
            $score = max(0, min(100, $score));
            $blocked = $score >= $threshold;
            $out[1] += (int) $blocked;
            $out[2] += (int) $indexer;
            $out[3] += (int) $downloader;
            $out[4] += (int) $empty;
            $out[5] += min($viaHops, 255);
            $out[6] += min($xffHops, 255);
            $out[7] = max($out[7], $score);
            if ($blocked && $out[8] < 0) {
                $out[8] = $at;
            }
            $row = $score | (int) $blocked << 7 | $first << 8 | $mask << 12 | min($viaHops, 255) << 24 | min($xffHops, 255) << 32
                | (int) $viaIndexer << 40 | (int) $empty << 41 | min(\strlen($raw), 65_535) << 42;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[21] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust scores, or null when PHP must compute them (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $userAgents
     * @param  array<array-key, string>  $vias
     * @param  array<array-key, string>  $forwardedFor
     * @return list<int>|null
     */
    public static function detectProxyBatchNative(array $userAgents, array $vias, array $forwardedFor, int $threshold = 50): ?array
    {
        $count = \count($userAgents);
        if ($count > self::W59_MAX_ITEMS || \count($vias) !== $count || \count($forwardedFor) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w59Strings(array_values($userAgents), array_values($vias), array_values($forwardedFor));
        $fields = self::W59_DETECT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w59Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w596_proxy_detect_batch($joined, \strlen($joined), $twin, \strlen($twin), $count, $threshold, $out, $fields));
    }

    /**
     * Comma-separated entries that are not blank after `trim(" \t")`.
     */
    private static function w59Hops(string $header): int
    {
        if ($header === '') {
            return 0;
        }
        $hops = 0;
        foreach (explode(',', $header) as $entry) {
            if (trim($entry, " \t") !== '') {
                $hops++;
            }
        }

        return $hops;
    }

    /**
     * Run `$callback` with the Wave 59.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    public static function usesW59RustFfi(): bool
    {
        $forced = self::w59RustFfiState();

        return \is_bool($forced) ? $forced : self::W59_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 59.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w59` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w59', self::W59_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 59.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w59Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w59RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w59Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
