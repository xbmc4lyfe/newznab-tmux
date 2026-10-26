<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use voku\helper\HtmlDomParser;

class ImdbScraper
{
    protected const int SOFT_FAILURE_TTL_MINUTES = 30;

    protected const int HARD_FAILURE_TTL_HOURS = 6;

    protected const string IMDBAPI_DEV_INTERVAL_CACHE_KEY = 'imdbapi_dev:min_interval';

    protected const string IMDBAPI_DEV_COOLDOWN_CACHE_KEY = 'imdbapi_dev:cooldown';

    protected const string WAF_BACKOFF_CACHE_KEY = 'imdb_scraper:waf_backoff';

    protected const string WAF_BLOCKED_CACHE_KEY = 'imdb_scraper:waf_blocked';

    protected const string WAF_PROBE_CACHE_KEY = 'imdb_scraper:waf_probe';

    protected const int WAF_PROBE_LOCK_SECONDS = 120;

    protected Client $client;

    protected string $imdbApiDevBaseUrl;

    protected bool $lastRequestWasBlocked = false;

    protected ?string $lastFetchSource = null;

    protected ?string $lastFailureReason = null;

    protected ?string $lastFallbackFailureReason = null;

    protected bool $lastFetchWasSkipped = false;

    private const string NATIVE_CDEF = <<<'C'
        int32_t nntmux_imdb_is_waf_response(const char *html, size_t html_len);
        ssize_t nntmux_imdb_extract_jsonld_and_languages(const char *html, size_t html_len, char *out_buf, size_t out_cap);
        C;

    // Search candidates are exposed through *Native for parity/benchmarks.
    // Production stays PHP: the paired mean/p99 gate failed (see the parity test).
    private const string SEARCH_CDEF = <<<'C'
        ssize_t nntmux_imdb_search_text(const char *input, size_t input_len, uint32_t mode, char *out_buf, size_t out_cap);
        ssize_t nntmux_imdb_suggestion_rows(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_imdb_search_html_rows(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    private ?FFI $searchNative = null;

    private bool $searchNativeResolved = false;

    private ?FFI $native = null;

    private bool $nativeResolved = false;

    /** Large body whose WAF scan was just answered; fetchById() and parseTitleHtml() check the same page. */
    private ?string $wafCheckedBody = null;

    private bool $wafCheckedResult = false;

    public function __construct(?Client $client = null, private readonly bool $useNative = true, private readonly bool $useRustFfi = true)
    {
        $this->imdbApiDevBaseUrl = rtrim((string) config('nntmux_api.imdbapi_dev_base_url', 'https://api.imdbapi.dev'), '/');
        $this->client = $client ?? new Client([
            'timeout' => 10,
            'connect_timeout' => 10,
            'http_errors' => false,
            'allow_redirects' => true,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ],
        ]);
    }

    public function wasBlockedByWaf(): bool
    {
        return $this->lastRequestWasBlocked;
    }

    public function isScrapingEnabled(): bool
    {
        return MetadataSources::isAvailable(MetadataSources::IMDB_SCRAPER);
    }

    public function isImdbApiDevEnabled(): bool
    {
        return MetadataSources::isAvailable(MetadataSources::IMDBAPI_DEV);
    }

    /**
     * Whether either IMDb source (scraping or the imdbapi.dev fallback) is switched on.
     */
    public function isEnabled(): bool
    {
        return $this->isScrapingEnabled() || $this->isImdbApiDevEnabled();
    }

    /**
     * Whether imdb.com is considered blocked, i.e. scraping is backing off or waiting for a probe.
     */
    public function isWafBackoffActive(): bool
    {
        return Cache::has(self::WAF_BLOCKED_CACHE_KEY);
    }

    /**
     * Whether the last fetchById() made no request because every source was off or paused.
     */
    public function wasSkipped(): bool
    {
        return $this->lastFetchWasSkipped;
    }

    public function getLastFetchSource(): ?string
    {
        return $this->lastFetchSource;
    }

    public function getLastFailureReason(): ?string
    {
        return $this->lastFailureReason;
    }

    public function getLastFallbackFailureReason(): ?string
    {
        return $this->lastFallbackFailureReason;
    }

    /**
     * Fetch a movie by IMDB numeric ID.
     *
     * @param  string  $id  Numeric part without 'tt'.
     * @return array<string, mixed>
     */
    public function fetchById(string $id): array|false
    {
        $this->lastRequestWasBlocked = false;
        $this->lastFetchSource = null;
        $this->lastFailureReason = null;
        $this->lastFallbackFailureReason = null;
        $this->lastFetchWasSkipped = false;

        $id = preg_replace('/[^0-9]/', '', $id) ?? '';
        if ($id === '' || strlen($id) < 5 || strlen($id) > 8) {
            $this->lastFailureReason = 'invalid_id';

            return false;
        }

        $cacheKey = 'imdb_scrape_id_'.$id;
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (! $this->isScrapingEnabled() || ! $this->acquireScrapeSlot()) {
            return $this->fetchWithoutScraping($id, $cacheKey);
        }

        $url = 'https://www.imdb.com/title/tt'.$id.'/';

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Referer' => 'https://www.imdb.com/',
                    'Upgrade-Insecure-Requests' => '1',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $html = (string) $response->getBody();

            if ($this->isWafResponse($statusCode, $html)) {
                $this->lastRequestWasBlocked = true;
                $this->lastFailureReason = 'waf_block';
                $this->startWafBackoff();
            } else {
                $this->endWafBackoff();
            }

            if (! $this->lastRequestWasBlocked && $statusCode < 400 && trim($html) !== '') {
                $data = $this->parseTitleHtml($html, $id);
                if ($data !== false) {
                    $this->lastFetchSource = 'imdb_html';
                    $this->lastFailureReason = null;
                    Cache::put($cacheKey, $data, now()->addDays(7));

                    return $data;
                }

                $this->lastFailureReason = 'html_parse_failure';
            } elseif (! $this->lastRequestWasBlocked) {
                $this->lastFailureReason = trim($html) === '' ? 'html_empty_response' : 'html_http_failure';
            }

            $fallbackData = $this->fetchFromImdbApiDev($id);
            if ($fallbackData !== false) {
                $this->lastFetchSource = 'imdbapi_dev';
                $this->lastFailureReason = null;
                $this->lastFallbackFailureReason = null;
                Cache::put($cacheKey, $fallbackData, now()->addDays(7));

                return $fallbackData;
            }

            $ttl = $this->lastRequestWasBlocked
                ? now()->addMinutes(self::SOFT_FAILURE_TTL_MINUTES)
                : now()->addHours(self::HARD_FAILURE_TTL_HOURS);
            Cache::put($cacheKey, false, $ttl);

            return false;
        } catch (Throwable $e) {
            Log::debug('IMDb fetch error tt'.$id.': '.$e->getMessage());
            Cache::forget(self::WAF_PROBE_CACHE_KEY);
            if ($this->lastFailureReason === null) {
                $this->lastFailureReason = 'html_exception';
            }

            $fallbackData = $this->fetchFromImdbApiDev($id);
            if ($fallbackData !== false) {
                $this->lastFetchSource = 'imdbapi_dev';
                $this->lastFailureReason = null;
                $this->lastFallbackFailureReason = null;
                Cache::put($cacheKey, $fallbackData, now()->addDays(7));

                return $fallbackData;
            }

            $ttl = $this->lastRequestWasBlocked
                ? now()->addMinutes(self::SOFT_FAILURE_TTL_MINUTES)
                : now()->addHours(self::HARD_FAILURE_TTL_HOURS);
            Cache::put($cacheKey, false, $ttl);

            return false;
        }
    }

    /**
     * Search IMDb suggestion API for a title.
     *
     * @return array<string, mixed>
     */
    public function search(string $query): array
    {
        $this->lastRequestWasBlocked = false;

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $slug = $this->normalizeSearchSlug($query);
        if ($slug === '') {
            return [];
        }

        $prefix = substr($slug, 0, 1);
        $cacheKey = 'imdb_search_'.md5($slug);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (! $this->isScrapingEnabled()) {
            return [];
        }

        $url = 'https://v2.sg.media-imdb.com/suggestion/'.urlencode($prefix).'/'.urlencode($slug).'.json';

        try {
            $res = $this->client->get($url, [
                'headers' => [
                    'Accept' => 'application/json,text/plain,*/*',
                    'Origin' => 'https://www.imdb.com',
                    'Referer' => 'https://www.imdb.com/find/?q='.rawurlencode($query).'&s=tt',
                ],
            ]);
            $body = (string) $res->getBody();

            $results = [];
            if ($res->getStatusCode() === 200 && ! $this->isWafResponse($res->getStatusCode(), $body)) {
                $results = $this->parseSuggestionJson($body);
            }

            if ($results === [] && ! $this->isWafBackoffActive()) {
                $htmlResults = $this->searchHtmlFallback($query);
                if ($htmlResults !== []) {
                    $results = $htmlResults;
                }
            }

            if ($results === []) {
                $ttl = $this->isWafBackoffActive()
                    ? now()->addMinutes(self::SOFT_FAILURE_TTL_MINUTES)
                    : now()->addHours(self::HARD_FAILURE_TTL_HOURS);
                Cache::put($cacheKey, [], $ttl);

                return [];
            }

            Cache::put($cacheKey, $results, now()->addHours(12));

            return $results; // @phpstan-ignore return.type
        } catch (Throwable $e) {
            Log::debug('IMDb search error '.$query.': '.$e->getMessage());
            Cache::put($cacheKey, [], now()->addHours(self::HARD_FAILURE_TTL_HOURS));

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTitleHtml(string $html, string $id): array|false
    {
        $blocked = $this->isWafResponse(200, $html);
        $this->wafCheckedBody = null;
        if ($blocked) {
            $this->lastRequestWasBlocked = true;

            return false;
        }

        $sections = $this->nativeTitleSections($html);
        $jsonLd = $sections !== null
            ? $this->findPrimaryJsonLdEntity($sections['json'])
            : $this->extractPrimaryJsonLdEntity($html);

        // The DOM is only built when a JSON-LD field is missing and a selector fallback needs it.
        $dom = null;
        $resolveDom = static function () use (&$dom, $html): HtmlDomParser {
            return $dom ??= HtmlDomParser::str_get_html($html);
        };

        $title = $this->extractTitle($jsonLd, $resolveDom);
        if ($title === '') {
            return false;
        }

        $year = $this->extractYearFromEntity($jsonLd);
        if ($year === '') {
            $year = $this->extractYearFromDom($resolveDom);
        }

        $plot = $this->extractPlot($jsonLd, $resolveDom);
        $rating = $this->extractRating($jsonLd, $resolveDom);
        $cover = $this->extractCover($jsonLd, $resolveDom);
        $genres = $this->extractGenres($jsonLd, $resolveDom);
        $directors = $this->extractPeople($jsonLd['director'] ?? null);
        $actors = array_slice($this->extractPeople($jsonLd['actor'] ?? null), 0, 10);
        $language = $this->extractLanguage($jsonLd, $resolveDom, $sections['languages'] ?? null);
        $type = $this->extractType($jsonLd);

        if ($directors === [] || $actors === []) {
            $peopleDom = $resolveDom();

            if ($directors === []) {
                $directors = $this->extractPeopleFromDom($peopleDom, [
                    "li[data-testid='title-pc-principal-credit']:contains(Director) a",
                    "li[data-testid='title-pc-principal-credit']:contains(Creator) a",
                ]);
            }

            if ($actors === []) {
                $actors = array_slice($this->extractPeopleFromDom($peopleDom, [
                    "div[data-testid='title-cast-item'] a[data-testid='title-cast-item__actor']",
                ]), 0, 10);
            }
        }

        return [
            'imdbid' => $id,
            'title' => $title,
            'year' => $year,
            'plot' => $plot,
            'rating' => $rating,
            'cover' => $cover,
            'genre' => $genres,
            'director' => $directors,
            'actors' => $actors,
            'language' => $language,
            'type' => $type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFromImdbApiDev(string $id): array|false
    {
        if (! $this->canUseImdbApiDev()) {
            return false;
        }

        if ($this->imdbApiDevBaseUrl === '') {
            $this->lastFallbackFailureReason = 'fallback_disabled';

            return false;
        }

        try {
            $this->markImdbApiDevAttempt();

            $response = $this->client->get($this->imdbApiDevBaseUrl.'/titles/tt'.$id, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 429) {
                $this->lastFallbackFailureReason = 'fallback_rate_limited';
                $this->activateImdbApiDevCooldown();

                return false;
            }

            if ($response->getStatusCode() !== 200) {
                $this->lastFallbackFailureReason = 'fallback_http_failure';
                if ($response->getStatusCode() >= 500) {
                    $this->activateImdbApiDevCooldown();
                }

                return false;
            }

            $payload = json_decode((string) $response->getBody(), true);
            if (! is_array($payload)) {
                $this->lastFallbackFailureReason = 'fallback_invalid_json';

                return false;
            }

            $mapped = $this->mapImdbApiDevTitle($payload, $id);
            if ($mapped === false) {
                $this->lastFallbackFailureReason = 'fallback_invalid_payload';

                return false;
            }

            return $mapped;
        } catch (Throwable $e) {
            Log::debug('imdbapi.dev fetch error tt'.$id.': '.$e->getMessage());
            $this->lastFallbackFailureReason = 'fallback_exception';
            $this->activateImdbApiDevCooldown();

            return false;
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchWithoutScraping(string $id, string $cacheKey): array|false
    {
        $this->lastRequestWasBlocked = $this->isScrapingEnabled();
        $this->lastFailureReason = $this->lastRequestWasBlocked ? 'waf_backoff' : 'scraper_disabled';

        if (! $this->canUseImdbApiDev()) {
            $this->lastFetchWasSkipped = true;

            return false;
        }

        $fallbackData = $this->fetchFromImdbApiDev($id);
        if ($fallbackData !== false) {
            $this->lastFetchSource = 'imdbapi_dev';
            $this->lastFailureReason = null;
            $this->lastFallbackFailureReason = null;
            Cache::put($cacheKey, $fallbackData, now()->addDays(7));

            return $fallbackData;
        }

        Cache::put($cacheKey, false, now()->addMinutes(self::SOFT_FAILURE_TTL_MINUTES));

        return false;
    }

    /**
     * While blocked, let a single worker probe imdb.com once the back-off interval has expired.
     */
    private function acquireScrapeSlot(): bool
    {
        if (! Cache::has(self::WAF_BLOCKED_CACHE_KEY)) {
            return true;
        }

        if (Cache::has(self::WAF_BACKOFF_CACHE_KEY)) {
            return false;
        }

        return Cache::add(self::WAF_PROBE_CACHE_KEY, true, self::WAF_PROBE_LOCK_SECONDS);
    }

    private function startWafBackoff(): void
    {
        $minutes = max(1, (int) config('nntmux_api.imdb_scraper_block_backoff_minutes', 60));
        Cache::put(self::WAF_BACKOFF_CACHE_KEY, true, now()->addMinutes($minutes));
        Cache::forget(self::WAF_PROBE_CACHE_KEY);

        if (Cache::add(self::WAF_BLOCKED_CACHE_KEY, true, now()->addDays(7))) {
            Log::warning('IMDb scraping blocked by WAF, pausing IMDb scraping for '.$minutes.' minutes');
        } else {
            Cache::put(self::WAF_BLOCKED_CACHE_KEY, true, now()->addDays(7));
            Log::debug('IMDb still blocked by WAF, backing off for '.$minutes.' minutes');
        }
    }

    private function endWafBackoff(): void
    {
        if (Cache::pull(self::WAF_BLOCKED_CACHE_KEY) === null) {
            return;
        }

        Cache::forget(self::WAF_BACKOFF_CACHE_KEY);
        Cache::forget(self::WAF_PROBE_CACHE_KEY);
        Log::info('IMDb scraping no longer blocked by WAF, lookups resumed');
    }

    private function canUseImdbApiDev(): bool
    {
        if (! $this->isImdbApiDevEnabled()) {
            $this->lastFallbackFailureReason = 'fallback_disabled';

            return false;
        }

        if (Cache::has(self::IMDBAPI_DEV_COOLDOWN_CACHE_KEY)) {
            $this->lastFallbackFailureReason = 'fallback_cooldown_active';

            return false;
        }

        $minInterval = max(0, (int) config('nntmux_api.imdbapi_dev_min_interval_seconds', 15));
        if ($minInterval > 0 && Cache::has(self::IMDBAPI_DEV_INTERVAL_CACHE_KEY)) {
            $this->lastFallbackFailureReason = 'fallback_min_interval_active';

            return false;
        }

        return true;
    }

    private function markImdbApiDevAttempt(): void
    {
        $minInterval = max(0, (int) config('nntmux_api.imdbapi_dev_min_interval_seconds', 15));
        if ($minInterval <= 0) {
            return;
        }

        Cache::put(self::IMDBAPI_DEV_INTERVAL_CACHE_KEY, true, now()->addSeconds($minInterval));
    }

    private function activateImdbApiDevCooldown(): void
    {
        $cooldownSeconds = max(0, (int) config('nntmux_api.imdbapi_dev_cooldown_seconds', 300));
        if ($cooldownSeconds <= 0) {
            return;
        }

        Cache::put(self::IMDBAPI_DEV_COOLDOWN_CACHE_KEY, true, now()->addSeconds($cooldownSeconds));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mapImdbApiDevTitle(array $payload, string $id): array|false
    {
        $title = trim((string) ($payload['primaryTitle'] ?? $payload['title'] ?? ''));
        if ($title === '') {
            return false;
        }

        $year = trim((string) ($payload['startYear'] ?? $payload['year'] ?? ''));
        $plot = trim((string) ($payload['plot'] ?? $payload['description'] ?? ''));
        $rating = trim((string) ($payload['rating']['aggregateRating'] ?? $payload['rating'] ?? ''));
        $cover = $this->normalizeImageUrl($payload['primaryImage'] ?? $payload['image'] ?? '');
        $genres = $this->normalizeStringList($payload['genres'] ?? []);
        $directors = $this->extractApiDevPeople($payload['directors'] ?? []);
        $actors = array_slice($this->extractApiDevPeople($payload['stars'] ?? $payload['cast'] ?? []), 0, 10);
        $language = $this->extractApiDevLanguages($payload['spokenLanguages'] ?? []);
        $type = $this->normalizeApiDevType((string) ($payload['type'] ?? 'movie'));

        return [
            'imdbid' => $id,
            'title' => $title,
            'year' => $year,
            'plot' => $plot,
            'rating' => $rating,
            'cover' => $cover,
            'genre' => $genres,
            'director' => $directors,
            'actors' => $actors,
            'language' => $language,
            'type' => $type,
        ];
    }

    private function isWafResponse(int $statusCode, string $body): bool
    {
        if ($statusCode === 202) {
            return true;
        }

        if ($body === '') {
            return false;
        }

        $native = $this->nativeIsWafBody($body);
        if ($native !== null) {
            return $native;
        }

        return Str::contains($body, [
            'window.awsWafCookieDomainList',
            'window.gokuProps',
            'challenge.js',
            'captcha',
            'awswaf',
        ], true);
    }

    private function nativeLibrary(): ?FFI
    {
        if (! $this->useNative) {
            return null;
        }

        if (! $this->nativeResolved) {
            $this->native = RustPipelineLibrary::resolveDomain('imdb', self::NATIVE_CDEF);
            $this->nativeResolved = true;
        }

        return $this->native;
    }

    /**
     * Null means the Rust library cannot answer with exact PHP parity and the PHP check must run.
     */
    private function nativeIsWafBody(string $body): ?bool
    {
        $ffi = $this->nativeLibrary();
        if ($ffi === null) {
            return null;
        }

        if ($this->wafCheckedBody === $body) {
            return $this->wafCheckedResult;
        }

        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_imdb_is_waf_response($body, strlen($body));
        } catch (Throwable) {
            return null;
        }

        if ($result < 0) {
            return null;
        }

        $this->wafCheckedBody = strlen($body) > 65536 ? $body : null;
        $this->wafCheckedResult = $result === 1;

        return $this->wafCheckedResult;
    }

    /**
     * Single-pass extraction of JSON-LD bodies and title-detail languages from the raw page.
     * 'languages' is null when the markup needs the DOM selector; the result is null when Rust is unavailable.
     *
     * @return array{json: array<int, string>, languages: array<int, string>|null}|null
     */
    private function nativeTitleSections(string $html): ?array
    {
        $ffi = $this->nativeLibrary();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
            if ($scratch === null) {
                return null;
            }

            $length = strlen($html);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_imdb_extract_jsonld_and_languages($html, $length, $scratch, $capacity);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_imdb_extract_jsonld_and_languages($html, $length, $scratch, $capacity);
            }
            if ($written < 0) {
                return null;
            }

            $raw = $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }

        $json = [];
        $languages = [];
        $useDom = false;
        foreach (explode("\0", $raw) as $section) {
            if ($section === '') {
                continue;
            }

            $payload = substr($section, 1);
            match ($section[0]) {
                'J' => $json[] = $payload,
                'L' => $languages[] = $payload,
                default => $useDom = true,
            };
        }

        return ['json' => $json, 'languages' => $useDom ? null : $languages];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPrimaryJsonLdEntity(string $html): array
    {
        if (! preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return [];
        }

        return $this->findPrimaryJsonLdEntity($matches[1]);
    }

    /**
     * @param  array<int, string>  $bodies  Raw JSON-LD script bodies in document order.
     * @return array<string, mixed>
     */
    private function findPrimaryJsonLdEntity(array $bodies): array
    {
        foreach ($bodies as $json) {
            $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (! is_array($decoded)) {
                continue;
            }

            $entity = $this->findTitleEntity($decoded);
            if ($entity !== []) {
                return $entity;
            }
        }

        return [];
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function findTitleEntity(array $payload): array
    {
        if ($this->looksLikeTitleEntity($payload)) {
            /** @var array<string, mixed> $payload */
            return $payload;
        }

        foreach ($payload as $value) {
            if (! is_array($value)) {
                continue;
            }

            $entity = $this->findTitleEntity($value);
            if ($entity !== []) {
                return $entity;
            }
        }

        return [];
    }

    /**
     * @param  array<int|string, mixed>  $payload
     */
    private function looksLikeTitleEntity(array $payload): bool
    {
        $type = strtolower((string) ($payload['@type'] ?? ''));

        return in_array($type, ['movie', 'tvseries', 'tvminiseries', 'tvepisode', 'tvmovie', 'video'], true)
            || (isset($payload['name']) && (isset($payload['actor']) || isset($payload['director']) || isset($payload['aggregateRating'])));
    }

    private function extractTitle(array $jsonLd, Closure $resolveDom): string
    {
        $title = trim((string) ($jsonLd['name'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $dom = $resolveDom();
        if ($dom !== false) {
            $titleNode = $dom->findOne('h1');
            $title = trim((string) ($titleNode?->text() ?? ''));
            if ($title !== '') {
                return preg_replace('/\s+\(.*?\)$/', '', $title) ?? $title;
            }

            $ogTitle = $dom->findOne("meta[property='og:title']");
            $title = trim((string) ($ogTitle?->getAttribute('content') ?? ''));
            if ($title !== '') {
                return preg_replace('/\s+-\s+IMDb$/', '', $title) ?? $title;
            }
        }

        return '';
    }

    private function extractYearFromEntity(array $jsonLd): string
    {
        $year = $this->extractYearFromText((string) ($jsonLd['datePublished'] ?? ''));
        if ($year !== '') {
            return $year;
        }

        return $this->extractYearFromText((string) ($jsonLd['dateCreated'] ?? ''));
    }

    private function extractYearFromDom(Closure $resolveDom): string
    {
        $dom = $resolveDom();
        if ($dom === false) {
            return '';
        }

        $selectors = [
            "span[data-testid='title-details-releasedate'] a",
            "span[data-testid='title-details-releasedate']",
            "ul[data-testid='hero-title-block__metadata'] li",
        ];

        foreach ($selectors as $selector) {
            foreach ($dom->find($selector) as $node) {
                $year = $this->extractYearFromText($node->text());
                if ($year !== '') {
                    return $year;
                }
            }
        }

        return '';
    }

    private function extractPlot(array $jsonLd, Closure $resolveDom): string
    {
        $plot = trim((string) ($jsonLd['description'] ?? ''));
        if ($plot !== '') {
            return $plot;
        }

        $dom = $resolveDom();
        if ($dom === false) {
            return '';
        }

        $plotNode = $dom->findOne("span[data-testid='plot-l']") ?: $dom->findOne("span[data-testid='plot-xl']");
        $plot = trim((string) ($plotNode?->text() ?? ''));
        if ($plot !== '') {
            return $plot;
        }

        $html = $dom->html();
        if (preg_match('/data-testid=["\']plot-(?:l|xl)["\'][^>]*>(.*?)<\/span>/is', $html, $matches) === 1) {
            $plot = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plot !== '') {
                return $plot;
            }
        }

        $descriptionNode = $dom->findOne("meta[name='description']");

        return trim((string) ($descriptionNode?->getAttribute('content') ?? ''));
    }

    private function extractRating(array $jsonLd, Closure $resolveDom): string
    {
        $rating = trim((string) ($jsonLd['aggregateRating']['ratingValue'] ?? ''));
        if ($rating !== '') {
            return $rating;
        }

        $dom = $resolveDom();
        if ($dom === false) {
            return '';
        }

        $ratingNode = $dom->findOne("div[data-testid='hero-rating-bar__aggregate-rating__score'] span");
        $ratingText = trim((string) ($ratingNode?->text() ?? ''));

        return preg_match('/\d+(?:\.\d+)?/', $ratingText, $matches) === 1 ? $matches[0] : '';
    }

    private function extractCover(array $jsonLd, Closure $resolveDom): string
    {
        $cover = $this->normalizeImageUrl($jsonLd['image'] ?? '');
        if ($cover !== '') {
            return $cover;
        }

        $dom = $resolveDom();
        if ($dom === false) {
            return '';
        }

        $posterNode = $dom->findOne("div[data-testid='hero-media__poster'] img") ?: $dom->findOne("meta[property='og:image']");
        $cover = (string) ($posterNode?->getAttribute('src') ?? $posterNode?->getAttribute('content') ?? '');

        return $this->normalizeImageUrl($cover);
    }

    /**
     * @return array<int, string>
     */
    private function extractGenres(array $jsonLd, Closure $resolveDom): array
    {
        $genres = $this->normalizeStringList($jsonLd['genre'] ?? []);
        if ($genres !== []) {
            return $genres;
        }

        $dom = $resolveDom();
        if ($dom === false) {
            return [];
        }

        $genres = [];
        foreach ($dom->find("div[data-testid='genres'] a") as $genreNode) {
            $genre = trim($genreNode->text());
            if ($genre !== '') {
                $genres[] = $genre;
            }
        }

        return array_values(array_unique($genres));
    }

    /**
     * @return array<int, string>
     */
    private function extractPeople(mixed $value): array
    {
        $people = [];
        foreach ($this->normalizeValueList($value) as $item) {
            $name = '';
            if (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
            } elseif (is_string($item)) {
                $name = trim($item);
            }

            if ($name !== '') {
                $people[] = $name;
            }
        }

        return array_values(array_unique($people));
    }

    /**
     * @param  array<int, string>  $selectors
     * @return array<int, string>
     */
    private function extractPeopleFromDom(HtmlDomParser $dom, array $selectors): array
    {
        $people = [];

        foreach ($selectors as $selector) {
            foreach ($dom->find($selector) as $node) {
                $name = trim($node->text());
                if ($name !== '') {
                    $people[] = $name;
                }
            }
        }

        return array_values(array_unique($people));
    }

    /**
     * @return array<int, string>
     */
    private function extractApiDevPeople(mixed $value): array
    {
        $people = [];

        foreach ($this->normalizeValueList($value) as $item) {
            if (is_array($item)) {
                $name = trim((string) ($item['displayName'] ?? $item['name'] ?? ''));
            } else {
                $name = trim((string) $item);
            }

            if ($name !== '') {
                $people[] = $name;
            }
        }

        return array_values(array_unique($people));
    }

    /**
     * @param  array<int, string>|null  $nativeLanguages  Link texts from the raw HTML, or null to use the DOM.
     */
    private function extractLanguage(array $jsonLd, Closure $resolveDom, ?array $nativeLanguages = null): string
    {
        $languages = $this->normalizeStringList($jsonLd['inLanguage'] ?? []);
        if ($languages !== []) {
            return implode(', ', $languages);
        }

        if ($nativeLanguages !== null) {
            return implode(', ', array_values(array_unique($nativeLanguages)));
        }

        $dom = $resolveDom();
        if ($dom === false) {
            return '';
        }

        $languages = [];
        foreach ($dom->find("li[data-testid='title-details-languages'] a") as $languageNode) {
            $language = trim($languageNode->text());
            if ($language !== '') {
                $languages[] = $language;
            }
        }

        return implode(', ', array_values(array_unique($languages)));
    }

    private function extractApiDevLanguages(mixed $value): string
    {
        $languages = [];

        foreach ($this->normalizeValueList($value) as $item) {
            if (is_array($item)) {
                $language = trim((string) ($item['name'] ?? $item['displayName'] ?? ''));
            } else {
                $language = trim((string) $item);
            }

            if ($language !== '') {
                $languages[] = $language;
            }
        }

        return implode(', ', array_values(array_unique($languages)));
    }

    private function extractType(array $jsonLd): string
    {
        $type = strtolower((string) ($jsonLd['@type'] ?? 'movie'));

        return match ($type) {
            'tvseries', 'tvminiseries', 'tvepisode', 'tvmovie' => 'tv',
            default => 'movie',
        };
    }

    private function normalizeApiDevType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'tvseries', 'tv_series', 'tv mini series', 'tv_mini_series', 'tvminiseries', 'tvspecial', 'tv_special', 'tvmovie', 'tv_movie', 'tvepisode', 'tv_episode' => 'tv',
            default => 'movie',
        };
    }

    private function searchLibrary(): ?FFI
    {
        if (! $this->useRustFfi) {
            return null;
        }
        if (! $this->searchNativeResolved) {
            $this->searchNative = RustPipelineLibrary::resolveDomain('media', self::SEARCH_CDEF);
            $this->searchNativeResolved = true;
        }

        return $this->searchNative;
    }

    private function searchNativeOutput(string $input, string $symbol, ?int $mode = null): ?string
    {
        $ffi = $this->searchLibrary();
        if ($ffi === null) {
            return null;
        }
        try {
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) + 16), $capacity);
            if ($scratch === null) {
                return null;
            }
            $args = $mode === null ? [$input, strlen($input)] : [$input, strlen($input), $mode];
            $written = $ffi->$symbol(...[...$args, $scratch, $capacity]);
            if ($written <= -4) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $scratch, $capacity]);
            }

            return $written < 0 ? null : ($written === 0 ? '' : FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
    }

    public function extractYearFromTextNative(string $text): string
    {
        return $this->searchNativeOutput($text, 'nntmux_imdb_search_text', 0) ?? $this->extractYearFromText($text);
    }

    public function normalizeImageUrlNative(mixed $image): string
    {
        if (! is_string($image)) {
            return $this->normalizeImageUrl($image);
        }

        return $this->searchNativeOutput($image, 'nntmux_imdb_search_text', 1) ?? $this->normalizeImageUrl($image);
    }

    private function normalizeSearchSlug(string $query): string
    {
        $norm = strtolower(preg_replace('/[^a-z0-9 ]/i', '', $query) ?? '');

        return preg_replace('/\s+/', '_', trim($norm)) ?? '';
    }

    public function normalizeSearchSlugNative(string $query): string
    {
        return $this->searchNativeOutput($query, 'nntmux_imdb_search_text', 2) ?? $this->normalizeSearchSlug($query);
    }

    /** @return list<array{imdbid: string, title: string, year: string}>|null */
    private function nativeSearchRows(string $input, string $symbol, bool $unique = false): ?array
    {
        $raw = $this->searchNativeOutput($input, $symbol);
        if ($raw === null) {
            return null;
        }
        if ($raw === '') {
            return [];
        }
        $fields = explode("\0", substr($raw, 0, -1));
        $results = [];
        for ($i = 0, $count = count($fields); $i < $count; $i += 3) {
            $row = ['imdbid' => $fields[$i], 'title' => $fields[$i + 1], 'year' => $fields[$i + 2]];
            if ($unique) {
                $results[$row['imdbid']] = $row;
            } else {
                $results[] = $row;
            }
        }

        return array_values($results);
    }

    /** @return list<array{imdbid: string, title: string, year: string}> */
    public function parseSuggestionJsonNative(string $body): array
    {
        return $this->nativeSearchRows($body, 'nntmux_imdb_suggestion_rows') ?? $this->parseSuggestionJson($body);
    }

    /** @return list<array{imdbid: string, title: string, year: string}> */
    public function parseSearchHtmlNative(string $html): array
    {
        return $this->nativeSearchRows($html, 'nntmux_imdb_search_html_rows', true) ?? $this->parseSearchHtml($html);
    }

    private function extractYearFromText(string $text): string
    {
        return preg_match('/(19|20)\d{2}/', $text, $matches) === 1 ? $matches[0] : '';
    }

    private function normalizeImageUrl(mixed $image): string
    {
        $url = '';

        if (is_string($image)) {
            $url = trim($image);
        } elseif (is_array($image)) {
            $url = trim((string) ($image['url'] ?? $image[0]['url'] ?? $image[0] ?? ''));
        }

        if ($url !== '' && str_contains($url, '._V1_')) {
            $url = preg_replace('/\._V1_.*\./', '.', $url) ?? $url;
        }

        return $url;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $items = [];
        foreach ($this->normalizeValueList($value) as $item) {
            if (is_string($item)) {
                $string = trim($item);
                if ($string !== '') {
                    $items[] = $string;
                }
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<int, mixed>
     */
    private function normalizeValueList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return [$value];
    }

    /**
     * @return array<int, array{imdbid: string, title: string, year: string}>
     */
    private function parseSuggestionJson(string $body): array
    {
        $json = json_decode($body, true);
        if (! is_array($json)) {
            return [];
        }

        $results = [];
        foreach (($json['d'] ?? []) as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_string($row['id']) || ! str_starts_with($row['id'], 'tt')) {
                continue;
            }

            $title = trim((string) ($row['l'] ?? ''));
            if ($title === '') {
                continue;
            }

            $results[] = [
                'imdbid' => substr($row['id'], 2),
                'title' => $title,
                'year' => trim((string) ($row['y'] ?? '')),
            ];

            if (count($results) >= 25) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return array<int, array{imdbid: string, title: string, year: string}>
     */
    private function searchHtmlFallback(string $query): array
    {
        $url = 'https://www.imdb.com/find/?q='.rawurlencode($query).'&s=tt';

        try {
            $response = $this->client->get($url, [
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Referer' => 'https://www.imdb.com/',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $html = (string) $response->getBody();

            if ($this->isWafResponse($statusCode, $html)) {
                $this->lastRequestWasBlocked = true;
                $this->startWafBackoff();

                return [];
            }

            if ($statusCode >= 400 || trim($html) === '') {
                return [];
            }

            return $this->parseSearchHtml($html);
        } catch (Throwable $e) {
            Log::debug('IMDb HTML search fallback failed for '.$query.': '.$e->getMessage());

            return [];
        }
    }

    /**
     * @return array<int, array{imdbid: string, title: string, year: string}>
     */
    private function parseSearchHtml(string $html): array
    {
        $results = [];

        if (preg_match_all('#<a[^>]+href=["\']/title/tt(?P<id>\d{5,8})/["\'][^>]*>(?P<title>.*?)</a>#is', $html, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $fullMatch) {
                $title = trim(html_entity_decode(strip_tags($matches['title'][$index][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($title === '') {
                    continue;
                }

                $offset = $fullMatch[1] + strlen($fullMatch[0]);
                $context = substr($html, $offset, 200);

                $results[] = [
                    'imdbid' => $matches['id'][$index][0],
                    'title' => $title,
                    'year' => $this->extractYearFromText(strip_tags($context)),
                ];

                if (count($results) >= 25) {
                    break;
                }
            }
        }

        if ($results === []) {
            $dom = HtmlDomParser::str_get_html($html);
            if ($dom !== false) {
                foreach ($dom->find("a[href^='/title/tt']") as $link) {
                    $href = (string) $link->getAttribute('href');
                    if (! preg_match('#/title/tt(?P<id>\d{5,8})/#', $href, $matches)) {
                        continue;
                    }

                    $title = trim($link->text());
                    if ($title === '') {
                        continue;
                    }

                    $context = trim(strip_tags((string) $html));
                    $results[] = [
                        'imdbid' => $matches['id'],
                        'title' => $title,
                        'year' => $this->extractYearFromText($context),
                    ];

                    if (count($results) >= 25) {
                        break;
                    }
                }
            }
        }

        $unique = [];
        foreach ($results as $result) {
            $unique[$result['imdbid']] = $result;
        }

        return array_values($unique);
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 3.92x, p99 3.14x; uncommon mean 4.43x, p99 4.07x; see the W46 parity test). */
    public const bool W464_RUST_FFI_DEFAULT = true;

    public const string W464_FFI_CDEF = <<<'C'
        ssize_t nntmux_w464_imdb_search_text_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W464_SUMMARY_KEYS = ['total_entries', 'empty_count', 'year_count', 'imdb_id_count', 'slug_empty_count', 'multi_word_slug_count', 'stripped_char_count', 'v1_image_count', 'waf_marker_count', 'tv_type_count', 'overlong_count'];

    /** unpack() format for the eleven little-endian int64 counters, in W464_SUMMARY_KEYS order. */
    private const string W464_UNPACK = 'Ptotal_entries/Pempty_count/Pyear_count/Pimdb_id_count/Pslug_empty_count/Pmulti_word_slug_count/Pstripped_char_count/Pv1_image_count/Pwaf_marker_count/Ptv_type_count/Poverlong_count';

    private static ?bool $w464UseRustFfi = null;

    private static FFI|false|null $w464Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW464RustFfi(?bool $enable = true): void
    {
        self::$w464UseRustFfi = $enable;
    }

    public static function usesW464RustFfi(): bool
    {
        return self::$w464UseRustFfi ?? self::W464_RUST_FFI_DEFAULT;
    }

    public static function resetW464RustFfi(): void
    {
        self::$w464UseRustFfi = null;
        self::$w464Ffi = null;
    }

    /**
     * Summarize search queries, suggestion titles and api.imdbapi.dev type
     * strings as search() and its helpers read them (all trimmed): years
     * extractYearFromText() finds, tt ids, normalizeSearchSlug() outcomes,
     * ._V1_ image markers, isWafResponse() markers (ASCII case-insensitive),
     * normalizeApiDevType() TV types and overlong text. Entries come from
     * strings, integers, or the title, l, name or query field; other values
     * count as empty. Byte classes are ASCII-only.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeImdbSearchTextBatch(array $entries): array
    {
        return self::usesW464RustFfi()
            ? self::summarizeImdbSearchTextBatchNative($entries)
            : self::summarizeImdbSearchTextBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeImdbSearchTextBatchNative(array $entries): array
    {
        return self::w464Call(self::w464Entries($entries)) ?? self::summarizeImdbSearchTextBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeImdbSearchTextBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W464_SUMMARY_KEYS, 0);
        foreach (self::w464Entries($entries) as $entry) {
            $summary['total_entries']++;
            $text = trim($entry);
            if ($text === '') {
                $summary['empty_count']++;

                continue;
            }
            $slug = preg_replace('/\s+/', '_', trim(strtolower(preg_replace('/[^A-Za-z0-9 ]/', '', $text) ?? ''))) ?? '';
            $summary['year_count'] += (int) (preg_match('/(?:19|20)[0-9]{2}/', $text) === 1);
            $summary['imdb_id_count'] += (int) (preg_match('/tt[0-9]{5}/', $text) === 1);
            $summary['slug_empty_count'] += (int) ($slug === '');
            $summary['multi_word_slug_count'] += (int) str_contains($slug, '_');
            $summary['stripped_char_count'] += (int) (preg_match('/[^A-Za-z0-9 ]/', $text) === 1);
            $summary['v1_image_count'] += (int) str_contains($text, '._V1_');
            $summary['waf_marker_count'] += (int) (stripos($text, 'captcha') !== false || stripos($text, 'challenge.js') !== false || stripos($text, 'awswaf') !== false || stripos($text, 'window.gokuprops') !== false);
            $summary['tv_type_count'] += (int) in_array(strtolower($text), ['tvseries', 'tv_series', 'tv mini series', 'tv_mini_series', 'tvminiseries', 'tvspecial', 'tv_special', 'tvmovie', 'tv_movie', 'tvepisode', 'tv_episode'], true);
            $summary['overlong_count'] += (int) (strlen($text) > 255);
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w464Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['title'] ?? $item['l'] ?? $item['name'] ?? $item['query'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w464Call(array $entries): ?array
    {
        try {
            $input = serialize($entries);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w464Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w464', self::W464_FFI_CDEF) ?? false;
            $ffi = self::$w464Ffi;
            if ($ffi === false) {
                return null;
            }
            // obtainScratch() returns at least the 88 bytes requested.
            $out = RustPipelineLibrary::obtainScratch($ffi, 88);
            // @phpstan-ignore method.notFound (Declared by W464_FFI_CDEF.)
            if ($out === null || $ffi->nntmux_w464_imdb_search_text_batch($input, strlen($input), $out, 88) !== 88) {
                return null;
            }
            /** @var array<string, int>|false $summary */
            $summary = unpack(self::W464_UNPACK, FFI::string($out, 88));
            $total = count($entries);
            if ($summary === false || $summary['total_entries'] !== $total || min($summary) < 0 || max($summary) > $total) {
                return null;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    /** C signature of the Wave 61.5 {@see self::scrapePlanBatch()} helper. */
    private const string W61_IMDB_PLAN_FFI_CDEF = 'int64_t nntmux_w615_imdb_scrape_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 61.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 5.27/4.32/5.34/3.44x); see tests/Unit/RustGoogleBooksIgdbImdbScraperItunesLogIndexerW61ParityTest.php.
     */
    public const bool W61_RUST_FFI_DEFAULT = true;

    /** normalizeApiDevType() TV spellings. */
    private const array W61_TV_TYPES = ['tvseries' => true, 'tv_series' => true, 'tv mini series' => true, 'tv_mini_series' => true, 'tvminiseries' => true, 'tvspecial' => true, 'tv_special' => true, 'tvmovie' => true, 'tv_movie' => true, 'tvepisode' => true, 'tv_episode' => true];

    /**
     * Wave 61.5 routing and FFI cache, kept in function-local statics (no static properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w61RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w61_5', self::W61_IMDB_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W61_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW61RustFfi(): bool
    {
        return self::w61RustFfiState() === true;
    }

    /**
     * Runs $callback with scrapePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW61RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w61RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w61RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 61.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w61Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w61RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w615_imdb_scrape_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch IMDb scrape summary over `id\ttitle\tyear\trating\tgenres\tdirectors\tactors\ttype`. Ten words per record:
     * `[imdb_num, id_kind, title_len, year, rating_x10, genre_count, director_count, actor_count, type_code, flags]`.
     * The trimmed id takes an optional ASCII case-insensitive `tt` and 1-10 digits with a positive value; its kind is
     * 0 empty, 1 canonical (`tt` plus 7-8 digits), 2 other valid, 3 invalid. The year is the first `(19|20)\d{2}` run
     * (extractYearFromText()), else 0. The trimmed rating is `\d{1,2}(\.\d)?` up to 10.0 in tenths, else -1. Genres
     * (distinct ASCII case-insensitively), directors and actors (capped at 10, as mapImdbApiDevTitle()) count trimmed,
     * non-empty comma-separated items. Type is 2 for the normalizeApiDevType() TV spellings, else 1. Flags: 1 empty
     * id, 2 invalid id, 4 empty title, 8 no year in a non-empty field, 16 invalid rating, 32 capped actors,
     * 64 duplicate genre, 128 unknown type, 256 not exactly eight fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function scrapePlanBatch(array $records): array
    {
        return self::usesW61RustFfi() ? self::scrapePlanBatchNative($records) : self::scrapePlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function scrapePlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 8 ? 0 : 256;
            $id = trim($fields[0]);
            [$number, $kind] = [0, 0];
            if ($id === '') {
                $flags |= 1;
            } else {
                $body = \strlen($id) >= 2 && strncasecmp($id, 'tt', 2) === 0 ? substr($id, 2) : $id;
                $value = self::w61Digits($body, 10);
                if ($value !== null && $value > 0) {
                    $number = $value;
                    $kind = str_starts_with($id, 'tt') && (\strlen($body) === 7 || \strlen($body) === 8) ? 1 : 2;
                } else {
                    $kind = 3;
                    $flags |= 2;
                }
            }
            $titleLength = \strlen(trim($fields[1] ?? ''));
            if ($titleLength === 0) {
                $flags |= 4;
            }
            $year = 0;
            if (preg_match('/(19|20)\d{2}/', $fields[2] ?? '', $matches) === 1) {
                $year = (int) $matches[0];
            } elseif (trim($fields[2] ?? '') !== '') {
                $flags |= 8;
            }
            $rating = -1;
            $text = trim($fields[3] ?? '');
            if ($text !== '') {
                if (preg_match('/^(\d{1,2})(?:\.(\d))?$/D', $text, $matches) === 1 && (int) $matches[1] * 10 + (int) ($matches[2] ?? 0) <= 100) {
                    $rating = (int) $matches[1] * 10 + (int) ($matches[2] ?? 0);
                } else {
                    $flags |= 16;
                }
            }
            $genres = [];
            foreach (explode(',', $fields[4] ?? '') as $genre) {
                $genre = strtolower(trim($genre));
                if ($genre === '') {
                    continue;
                }
                if (isset($genres[$genre])) {
                    $flags |= 64;
                } else {
                    $genres[$genre] = true;
                }
            }
            $directors = self::w61Count($fields[5] ?? '');
            $actors = self::w61Count($fields[6] ?? '');
            if ($actors > 10) {
                $actors = 10;
                $flags |= 32;
            }
            $type = strtolower(trim($fields[7] ?? ''));
            $typeCode = isset(self::W61_TV_TYPES[$type]) ? 2 : 1;
            if ($typeCode === 1 && $type !== '' && $type !== 'movie') {
                $flags |= 128;
            }
            array_push($out, $number, $kind, $titleLength, $year, $rating, \count($genres), $directors, $actors, $typeCode, $flags);
        }

        return $out;
    }

    /** Trimmed, non-empty comma-separated items. */
    private static function w61Count(string $list): int
    {
        $count = 0;
        foreach (explode(',', $list) as $item) {
            if (trim($item) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /** 1-$max ASCII digits as an integer, else null. */
    private static function w61Digits(string $text, int $max): ?int
    {
        return $text !== '' && \strlen($text) <= $max && ctype_digit($text) ? (int) $text : null;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function scrapePlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w61Words($count, implode("\0", $records), 10 * $count) ?? self::scrapePlanBatchPhp($records);
    }
}
