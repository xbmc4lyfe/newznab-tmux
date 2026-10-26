<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\SteamApp;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * SteamService - Comprehensive Steam API integration for PC Games.
 *
 * Features:
 * - Full Steam Store API integration
 * - Rate limiting and caching
 * - Robust title matching with fuzzy search
 * - Complete game metadata retrieval
 * - DLC and package information
 */
class SteamService
{
    // Steam API endpoints
    protected const string STEAM_API_BASE = 'https://api.steampowered.com';

    protected const string STEAM_STORE_BASE = 'https://store.steampowered.com/api';

    protected const string STEAM_STORE_URL = 'https://store.steampowered.com/app/';

    protected const string STEAM_CDN_BASE = 'https://cdn.akamai.steamstatic.com/steam/apps';

    // Rate limiting
    protected const string RATE_LIMIT_KEY = 'steam_api_rate_limit';

    protected const int REQUESTS_PER_MINUTE = 200; // Steam allows ~200 requests per 5 minutes

    protected const int DECAY_SECONDS = 60;

    // Cache TTLs
    protected const int APP_DETAILS_CACHE_TTL = 86400; // 24 hours

    protected const int APP_LIST_CACHE_TTL = 604800; // 7 days

    protected const int SEARCH_CACHE_TTL = 3600; // 1 hour

    protected const int FAILED_LOOKUP_CACHE_TTL = 1800; // 30 minutes

    // Matching configuration
    protected const int MATCH_THRESHOLD = 85;

    protected const int RELAXED_MATCH_THRESHOLD = 75;

    // Scene/release group noise patterns
    protected const array SCENE_GROUPS = [
        'CODEX', 'PLAZA', 'GOG', 'CPY', 'HOODLUM', 'EMPRESS', 'RUNE', 'TENOKE', 'FLT',
        'RELOADED', 'SKIDROW', 'PROPHET', 'RAZOR1911', 'CORE', 'REFLEX', 'P2P', 'GOLDBERG',
        'DARKSIDERS', 'TINYISO', 'DOGE', 'ANOMALY', 'ELAMIGOS', 'FITGIRL', 'DODI', 'XATAB',
        'CHRONOS', 'FCKDRM', 'I_KNOW', 'KAOS', 'SIMPLEX', 'ALI213', 'FLTDOX', '3DMGAME',
        'POSTMORTEM', 'VACE', 'ROGUE', 'OUTLAWS', 'DARKSIIDERS', 'ONLINEFIX',
    ];

    protected const array EDITION_TAGS = [
        'GOTY', 'GAME OF THE YEAR', 'DEFINITIVE EDITION', 'DELUXE EDITION', 'ULTIMATE EDITION',
        'COMPLETE EDITION', 'REMASTERED', 'HD REMASTER', 'DIRECTORS CUT', 'ANNIVERSARY EDITION',
        'ENHANCED EDITION', 'SPECIAL EDITION', 'COLLECTORS EDITION', 'GOLD EDITION',
        'PREMIUM EDITION', 'LEGENDARY EDITION', 'STANDARD EDITION', 'DIGITAL EDITION',
    ];

    protected const array RELEASE_TAGS = [
        'REPACK', 'RIP', 'ISO', 'PROPER', 'UPDATE', 'DLC', 'INCL', 'MULTI', 'CRACK',
        'CRACKFIX', 'FIX', 'PATCH', 'HOTFIX', 'BETA', 'ALPHA', 'DEMO', 'PREORDER',
    ];

    protected ?string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('services.steam.api_key');
    }

    /**
     * Search for a game by title and return the best match's Steam App ID.
     */
    public function search(string $title): ?int
    {
        $cleanTitle = $this->cleanTitle($title);
        if (empty($cleanTitle)) {
            Log::debug('SteamService: Empty title after cleaning', ['original' => $title]);

            return null;
        }

        // Check failed lookup cache
        $cacheKey = 'steam_search_failed:'.md5(mb_strtolower($cleanTitle));
        if (Cache::has($cacheKey)) {
            Log::debug('SteamService: Skipping previously failed search', ['title' => $cleanTitle]);

            return null;
        }

        // Check successful search cache
        $successCacheKey = 'steam_search:'.md5(mb_strtolower($cleanTitle));
        $cached = Cache::get($successCacheKey);
        if ($cached !== null) {
            Log::debug('SteamService: Using cached search result', ['title' => $cleanTitle, 'appid' => $cached]);

            return (int) $cached;
        }

        $appId = $this->performSearch($cleanTitle);

        if ($appId !== null) {
            Cache::put($successCacheKey, $appId, self::SEARCH_CACHE_TTL);
            Log::info('SteamService: Found match', ['title' => $cleanTitle, 'appid' => $appId]);
        } else {
            Cache::put($cacheKey, true, self::FAILED_LOOKUP_CACHE_TTL);
            Log::debug('SteamService: No match found', ['title' => $cleanTitle]);
        }

        return $appId;
    }

    /**
     * Get complete game details from Steam.
     *
     * @return array<string, mixed>
     *                              title: string,
     *                              steamid: int,
     *                              description: ?string,
     *                              detailed_description: ?string,
     *                              about: ?string,
     *                              short_description: ?string,
     *                              cover: ?string,
     *                              backdrop: ?string,
     *                              screenshots: array,
     *                              movies: array,
     *                              trailer: ?string,
     *                              publisher: ?string,
     *                              developers: array,
     *                              releasedate: ?string,
     *                              genres: string,
     *                              categories: array,
     *                              rating: ?int,
     *                              metacritic_score: ?int,
     *                              metacritic_url: ?string,
     *                              price: ?array,
     *                              platforms: array,
     *                              requirements: array,
     *                              dlc: array,
     *                              achievements: ?int,
     *                              recommendations: ?int,
     *                              website: ?string,
     *                              support_url: ?string,
     *                              legal_notice: ?string,
     *                              directurl: string,
     *                              type: string,
     *                              }|false
     * @return array<string, mixed>
     */
    public function getGameDetails(int $appId): array|false
    {
        // Check cache first
        $cacheKey = "steam_app_details:{$appId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $data = $this->fetchAppDetails($appId);
        if ($data === null) {
            return false;
        }

        $result = $this->transformAppDetails($data, $appId);

        Cache::put($cacheKey, $result, self::APP_DETAILS_CACHE_TTL);

        return $result;
    }

    /**
     * Alias for getGameDetails for backward compatibility.
     *
     * @return array<string, mixed>
     */
    public function getAll(int $appId): array|false
    {
        return $this->getGameDetails($appId);
    }

    /**
     * Get Steam player count for a game.
     */
    public function getPlayerCount(int $appId): ?int
    {
        if (empty($this->apiKey)) {
            return null;
        }

        $cacheKey = "steam_player_count:{$appId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (int) $cached;
        }

        try {
            $response = $this->makeRequest(
                self::STEAM_API_BASE.'/ISteamUserStats/GetNumberOfCurrentPlayers/v1/',
                ['appid' => $appId]
            );

            if ($response && isset($response['response']['player_count'])) {
                $count = (int) $response['response']['player_count'];
                Cache::put($cacheKey, $count, 300); // 5 minute cache

                return $count;
            }
        } catch (\Exception $e) {
            Log::warning('SteamService: Failed to get player count', [
                'appid' => $appId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get game reviews summary.
     *
     * @return array<string, mixed>
     */
    public function getReviewsSummary(int $appId): ?array
    {
        $cacheKey = "steam_reviews:{$appId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::timeout(10)
                ->get(self::STEAM_STORE_BASE.'/appreviews/'.$appId, [
                    'json' => 1,
                    'language' => 'all',
                    'purchase_type' => 'all',
                ])
                ->json();

            if ($response && isset($response['query_summary'])) {
                $summary = [
                    'total_positive' => $response['query_summary']['total_positive'] ?? 0,
                    'total_negative' => $response['query_summary']['total_negative'] ?? 0,
                    'total_reviews' => $response['query_summary']['total_reviews'] ?? 0,
                    'review_score' => $response['query_summary']['review_score'] ?? 0,
                    'review_score_desc' => $response['query_summary']['review_score_desc'] ?? 'No Reviews',
                ];

                Cache::put($cacheKey, $summary, 3600); // 1 hour cache

                return $summary;
            }
        } catch (\Exception $e) {
            Log::warning('SteamService: Failed to get reviews', [
                'appid' => $appId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get DLC list for a game.
     *
     * @return array<string, mixed>
     */
    public function getDLCList(int $appId): array
    {
        $details = $this->getGameDetails($appId);
        if ($details === false) {
            return [];
        }

        $dlcIds = $details['dlc'] ?? [];
        if (empty($dlcIds)) {
            return [];
        }

        $dlcList = [];
        foreach (array_slice($dlcIds, 0, 20) as $dlcId) { // Limit to prevent too many API calls
            $dlcDetails = $this->getGameDetails($dlcId);
            if ($dlcDetails !== false) {
                $dlcList[] = [
                    'appid' => $dlcId,
                    'name' => $dlcDetails['title'],
                    'price' => $dlcDetails['price'] ?? null,
                ];
            }
        }

        return $dlcList;
    }

    /**
     * Populate the steam_apps table with the full app list from Steam.
     *
     * @return array{inserted: int<0, max>, updated: int<0, max>, skipped: int<0, max>, errors: int<0, max>}
     */
    public function populateSteamAppsTable(?callable $progressCallback = null): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        try {
            $appList = $this->getFullAppList();
            if (empty($appList)) {
                Log::error('SteamService: Failed to retrieve app list from Steam');

                return $stats;
            }

            $total = count($appList);
            $processed = 0;

            // Process in chunks to avoid memory issues
            $chunks = array_chunk($appList, 1000);

            foreach ($chunks as $chunk) {
                $existingApps = SteamApp::query()
                    ->whereIn('appid', Arr::pluck($chunk, 'appid'))
                    ->pluck('appid')
                    ->toArray();

                $toInsert = [];
                foreach ($chunk as $app) {
                    $processed++;

                    if (empty($app['name']) || ! isset($app['appid'])) {
                        $stats['skipped']++;

                        continue;
                    }

                    if (in_array($app['appid'], $existingApps, true)) {
                        $stats['skipped']++;

                        continue;
                    }

                    $toInsert[] = [
                        'appid' => $app['appid'],
                        'name' => $app['name'],
                    ];
                }

                if (! empty($toInsert)) {
                    try {
                        SteamApp::query()->insert($toInsert);
                        $stats['inserted'] += count($toInsert);
                    } catch (\Exception $e) {
                        Log::warning('SteamService: Batch insert failed, trying individual inserts', [
                            'error' => $e->getMessage(),
                        ]);

                        foreach ($toInsert as $app) {
                            try {
                                SteamApp::query()->insertOrIgnore($app);
                                $stats['inserted']++;
                            } catch (\Exception $e2) {
                                $stats['errors']++;
                            }
                        }
                    }
                }

                if ($progressCallback !== null) {
                    $progressCallback($processed, $total);
                }
            }

            Log::info('SteamService: App list populated', $stats);
        } catch (\Exception $e) {
            Log::error('SteamService: Failed to populate app list', ['error' => $e->getMessage()]);
            $stats['errors']++;
        }

        return $stats;
    }

    /**
     * Get the full list of Steam apps.
     *
     * @return list<array<string, mixed>>
     */
    public function getFullAppList(): array
    {
        $cacheKey = 'steam_full_app_list';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(60)
                ->get(self::STEAM_API_BASE.'/ISteamApps/GetAppList/v2/')
                ->json();

            if ($response && isset($response['applist']['apps'])) {
                $apps = $response['applist']['apps'];
                Cache::put($cacheKey, $apps, self::APP_LIST_CACHE_TTL);

                return $apps;
            }
        } catch (\Exception $e) {
            Log::error('SteamService: Failed to fetch app list', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Search for games and return multiple matches.
     *
     * @return array{}
     */
    public function searchMultiple(string $title, int $limit = 10): Collection // @phpstan-ignore missingType.generics
    {
        $cleanTitle = $this->cleanTitle($title);
        if (empty($cleanTitle)) {
            return collect();
        }

        $matches = $this->findMatches($cleanTitle, $limit * 2); // Get more to filter

        return collect($matches)
            ->filter(fn ($m) => $m['score'] >= self::RELAXED_MATCH_THRESHOLD)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    // ========================================
    // Protected Methods
    // ========================================

    /**
     * Perform the actual search with multiple strategies.
     */
    protected function performSearch(string $title): ?int
    {
        $matches = $this->findMatches($title);

        if (empty($matches)) {
            return null;
        }

        // Get the best match
        $bestMatch = $matches[0];

        if ($bestMatch['score'] >= self::MATCH_THRESHOLD) {
            return $bestMatch['appid'];
        }

        // Try relaxed threshold for close matches
        if ($bestMatch['score'] >= self::RELAXED_MATCH_THRESHOLD) {
            // Verify with additional checks
            $details = $this->fetchAppDetails($bestMatch['appid']);
            if ($details !== null && $this->isGameType($details)) {
                return $bestMatch['appid'];
            }
        }

        return null;
    }

    /**
     * Find matching games from the database.
     *
     * @return array<int, array{appid: int, name: string, score: float}>
     */
    protected function findMatches(string $title, int $limit = 25): array
    {
        $variants = $this->generateQueryVariants($title);
        $variants = array_values(array_unique(array_filter(array_map('trim', $variants), static fn (string $variant): bool => $variant !== '')));
        $matches = [];
        $seenAppIds = [];

        try {
            if (Search::isAvailable() && $variants !== []) {
                $orQuery = implode(' | ', $variants);
                $hits = Search::searchSecondary(SecondarySearchIndex::Steam, $orQuery, $limit);
                foreach ($hits['data'] as $row) {
                    $appid = $row['appid'] ?? null;
                    $name = $row['name'] ?? null;
                    if ($appid === null || $name === null || $name === '' || isset($seenAppIds[$appid])) {
                        continue;
                    }
                    $score = $this->scoreTitle((string) $name, $title);
                    if ($score >= self::RELAXED_MATCH_THRESHOLD) {
                        $seenAppIds[(int) $appid] = true;
                        $matches[] = [
                            'appid' => (int) $appid,
                            'name' => (string) $name,
                            'score' => $score,
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug('SteamService: search index lookup failed', ['error' => $e->getMessage()]);
        }

        // LIKE fallback
        try {
            $fallbackQuery = SteamApp::query()->select(['appid', 'name']);
            if ($variants !== []) {
                $fallbackQuery->where(function ($query) use ($variants): void {
                    foreach ($variants as $index => $variant) {
                        $likeTerm = $this->buildLikePattern($variant);
                        if ($index === 0) {
                            $query->where('name', 'like', $likeTerm);
                        } else {
                            $query->orWhere('name', 'like', $likeTerm);
                        }
                    }
                });
            } else {
                $fallbackQuery->where('name', 'like', $this->buildLikePattern($title));
            }

            $fallbacks = $fallbackQuery->limit($limit)->get();

            foreach ($fallbacks as $row) {
                $appid = $row->appid;
                $name = $row->name;

                if (isset($seenAppIds[$appid])) {
                    continue;
                }

                $score = $this->scoreTitle($name, $title);
                if ($score >= self::RELAXED_MATCH_THRESHOLD) {
                    $seenAppIds[$appid] = true;
                    $matches[] = [
                        'appid' => (int) $appid,
                        'name' => $name,
                        'score' => $score,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::debug('SteamService: LIKE search failed', ['error' => $e->getMessage()]);
        }

        // Sort by score descending
        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, $limit);
    }

    /**
     * Fetch app details from Steam API.
     *
     * @return array<string, mixed>
     */
    protected function fetchAppDetails(int $appId): ?array
    {
        $result = RateLimiter::attempt(
            self::RATE_LIMIT_KEY,
            self::REQUESTS_PER_MINUTE,
            function () use ($appId) {
                try {
                    $response = Http::timeout(15)
                        ->get(self::STEAM_STORE_BASE.'/appdetails', [
                            'appids' => $appId,
                            'cc' => 'us',
                            'l' => 'english',
                        ])
                        ->json();

                    if ($response && isset($response[(string) $appId]['success']) && $response[(string) $appId]['success'] === true) {
                        return $response[(string) $appId]['data'];
                    }
                } catch (\Exception $e) {
                    Log::warning('SteamService: Failed to fetch app details', [
                        'appid' => $appId,
                        'error' => $e->getMessage(),
                    ]);
                }

                return null;
            },
            self::DECAY_SECONDS
        );

        // RateLimiter::attempt returns true when rate limit is exceeded
        return is_array($result) ? $result : null;
    }

    /**
     * Transform Steam API response to our standard format.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function transformAppDetails(array $data, int $appId): array
    {
        // Extract screenshots
        $screenshots = [];
        if (! empty($data['screenshots'])) {
            foreach ($data['screenshots'] as $ss) {
                $screenshots[] = [
                    'thumbnail' => $ss['path_thumbnail'] ?? null,
                    'full' => $ss['path_full'] ?? null,
                ];
            }
        }

        // Extract movies/trailers
        $movies = [];
        $trailerUrl = null;
        if (! empty($data['movies'])) {
            foreach ($data['movies'] as $movie) {
                $movieData = [
                    'id' => $movie['id'] ?? null,
                    'name' => $movie['name'] ?? null,
                    'thumbnail' => $movie['thumbnail'] ?? null,
                    'webm' => $movie['webm']['max'] ?? ($movie['webm']['480'] ?? null),
                    'mp4' => $movie['mp4']['max'] ?? ($movie['mp4']['480'] ?? null),
                ];
                $movies[] = $movieData;

                if ($trailerUrl === null && ! empty($movieData['mp4'])) {
                    $trailerUrl = $movieData['mp4'];
                }
            }
        }

        // Extract genres
        $genres = [];
        if (! empty($data['genres'])) {
            foreach ($data['genres'] as $genre) {
                $genres[] = $genre['description'] ?? '';
            }
        }

        // Extract categories (multiplayer, co-op, etc.)
        $categories = [];
        if (! empty($data['categories'])) {
            foreach ($data['categories'] as $cat) {
                $categories[] = $cat['description'] ?? '';
            }
        }

        // Extract price info
        $price = null;
        if (isset($data['price_overview'])) {
            $price = [
                'currency' => $data['price_overview']['currency'] ?? 'USD',
                'initial' => ($data['price_overview']['initial'] ?? 0) / 100,
                'final' => ($data['price_overview']['final'] ?? 0) / 100,
                'discount_percent' => $data['price_overview']['discount_percent'] ?? 0,
                'final_formatted' => $data['price_overview']['final_formatted'] ?? null,
            ];
        } elseif ($data['is_free'] ?? false) {
            $price = [
                'currency' => 'USD',
                'initial' => 0,
                'final' => 0,
                'discount_percent' => 0,
                'final_formatted' => 'Free',
            ];
        }

        // Extract platforms
        $platforms = [];
        if (! empty($data['platforms'])) {
            if ($data['platforms']['windows'] ?? false) {
                $platforms[] = 'Windows';
            }
            if ($data['platforms']['mac'] ?? false) {
                $platforms[] = 'Mac';
            }
            if ($data['platforms']['linux'] ?? false) {
                $platforms[] = 'Linux';
            }
        }

        // Extract requirements
        $requirements = [];
        if (! empty($data['pc_requirements'])) {
            $requirements['pc'] = [
                'minimum' => $data['pc_requirements']['minimum'] ?? null,
                'recommended' => $data['pc_requirements']['recommended'] ?? null,
            ];
        }
        if (! empty($data['mac_requirements'])) {
            $requirements['mac'] = [
                'minimum' => $data['mac_requirements']['minimum'] ?? null,
                'recommended' => $data['mac_requirements']['recommended'] ?? null,
            ];
        }
        if (! empty($data['linux_requirements'])) {
            $requirements['linux'] = [
                'minimum' => $data['linux_requirements']['minimum'] ?? null,
                'recommended' => $data['linux_requirements']['recommended'] ?? null,
            ];
        }

        // Extract release date
        $releaseDate = null;
        if (! empty($data['release_date']['date'])) {
            try {
                $releaseDate = Carbon::parse($data['release_date']['date'])->format('Y-m-d');
            } catch (\Exception $e) {
                $releaseDate = $data['release_date']['date'];
            }
        }

        // Build publisher string
        $publisher = null;
        if (! empty($data['publishers'])) {
            $publisher = implode(', ', array_filter(array_map('strval', $data['publishers'])));
        }

        // Build developers array
        $developers = [];
        if (! empty($data['developers'])) {
            $developers = array_filter(array_map('strval', $data['developers']));
        }

        return [
            'title' => $data['name'] ?? '',
            'steamid' => $appId,
            'type' => $data['type'] ?? 'game',
            'description' => $data['short_description'] ?? null,
            'detailed_description' => $data['detailed_description'] ?? null,
            'about' => $data['about_the_game'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'cover' => $data['header_image'] ?? null,
            'backdrop' => $data['background'] ?? ($data['background_raw'] ?? null),
            'screenshots' => $screenshots,
            'movies' => $movies,
            'trailer' => $trailerUrl,
            'publisher' => $publisher,
            'developers' => $developers,
            'releasedate' => $releaseDate,
            'genres' => implode(',', array_filter($genres)),
            'categories' => $categories,
            'rating' => $data['metacritic']['score'] ?? null,
            'metacritic_score' => $data['metacritic']['score'] ?? null,
            'metacritic_url' => $data['metacritic']['url'] ?? null,
            'price' => $price,
            'platforms' => $platforms,
            'requirements' => $requirements,
            'dlc' => $data['dlc'] ?? [],
            'achievements' => $data['achievements']['total'] ?? null,
            'recommendations' => $data['recommendations']['total'] ?? null,
            'website' => $data['website'] ?? null,
            'support_url' => $data['support_info']['url'] ?? null,
            'legal_notice' => $data['legal_notice'] ?? null,
            'directurl' => self::STEAM_STORE_URL.$appId,
        ];
    }

    /**
     * Check if the app is a game (not DLC, video, etc.).
     *
     * @param  array<string, mixed>  $data
     */
    protected function isGameType(array $data): bool
    {
        $type = $data['type'] ?? '';

        return in_array($type, ['game', 'demo'], true);
    }

    /**
     * Make a rate-limited request to Steam API.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function makeRequest(string $url, array $params = []): ?array
    {
        return RateLimiter::attempt(
            self::RATE_LIMIT_KEY,
            self::REQUESTS_PER_MINUTE,
            function () use ($url, $params) {
                if ($this->apiKey !== null) {
                    $params['key'] = $this->apiKey;
                }

                try {
                    return Http::timeout(15)->get($url, $params)->json();
                } catch (\Exception $e) {
                    Log::warning('SteamService: API request failed', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            },
            self::DECAY_SECONDS
        );
    }

    // ========================================
    // Title Matching & Normalization
    // ========================================

    /**
     * Clean a release title for searching.
     */
    public function cleanTitle(string $title): string
    {
        $fast = RustPipelineLibrary::steamCleanTitle($title);
        if ($fast !== null) {
            return $fast;
        }

        return $this->cleanTitlePhp($title);
    }

    public function cleanTitlePhp(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        // URL decode
        $title = urldecode($title);

        // Remove file extensions
        $title = (string) preg_replace('/\.(zip|rar|7z|iso|nfo|sfv|exe|mkv|mp4|avi)$/i', '', $title);

        // Remove bracketed content
        $title = (string) preg_replace('/\[[^\]]*\]|\([^)]*\)|\{[^}]*\}/u', ' ', $title);

        // Remove scene groups at end
        $groupPattern = '/\s*[-_]\s*('.implode('|', array_map('preg_quote', self::SCENE_GROUPS)).')\s*$/i';
        $title = (string) preg_replace($groupPattern, '', $title);

        // Remove edition tags (multi-word patterns first)
        $editionPatterns = [
            '/\b(Game\s+of\s+the\s+Year)[\s._-]*(Edition)?\b/i',
            '/\bGOTY[\s._-]*(Edition)?\b/i',
            '/\b(Definitive|Deluxe|Ultimate|Complete|Enhanced|Special|Collectors?|Gold|Premium|Legendary|Standard|Digital)[\s._-]*(Edition)?\b/i',
            '/\b(Remastered|HD[\s._-]*Remaster|Directors?[\s._-]*Cut|Anniversary)[\s._-]*(Edition)?\b/i',
            '/\bEdition\b/i', // Remove standalone "Edition"
        ];
        foreach ($editionPatterns as $pattern) {
            $title = (string) preg_replace($pattern, ' ', $title);
        }

        // Remove standalone edition tags
        foreach (self::EDITION_TAGS as $tag) {
            // Skip tags already handled above
            if (stripos($tag, 'EDITION') !== false) {
                continue;
            }
            $title = (string) preg_replace('/\b'.preg_quote($tag, '/').'\b/i', ' ', $title);
        }

        // Remove release tags
        foreach (self::RELEASE_TAGS as $tag) {
            $title = (string) preg_replace('/\b'.preg_quote($tag, '/').'\d*\b/i', ' ', $title);
        }

        // Remove DLCs tag (common in scene releases)
        $title = (string) preg_replace('/\b(Incl(?:uding)?\.?\s*)?DLCs?\b/i', ' ', $title);

        // Remove version numbers (v1.2.3, 1.2.3.4, etc.)
        $title = (string) preg_replace('/\bv?\d+(?:\.\d+){2,}\b/i', ' ', $title);

        // Replace separators with spaces
        $title = (string) preg_replace('/[._+\-]+/', ' ', $title);

        // Clean up
        $title = (string) preg_replace('/\s+/', ' ', $title);
        $title = trim($title, " \t\n\r\0\x0B-_");

        return $title;
    }

    /**
     * Generate query variants for better matching.
     *
     * @return array<string>
     */
    protected function generateQueryVariants(string $title): array
    {
        return $this->generateQueryVariantsPhp($title);
    }

    /** @return list<string> */
    public function generateQueryVariantsPhp(string $title): array
    {
        $variants = [$title];

        // Without edition tags (already cleaned, but be sure)
        $stripped = $this->stripEditionTags($title);
        if ($stripped !== $title && $stripped !== '') {
            $variants[] = $stripped;
        }

        // Without parentheses content
        $noParen = (string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $stripped);
        $noParen = trim(preg_replace('/\s+/', ' ', $noParen) ?? '');
        if ($noParen !== $stripped && $noParen !== '') {
            $variants[] = $noParen;
        }

        // Left side of colon (base title)
        if (str_contains($noParen, ':')) {
            $left = trim(explode(':', $noParen, 2)[0]);
            if ($left !== '' && $left !== $noParen) {
                $variants[] = $left;
            }
        }

        // Normalized version
        $normalized = $this->normalizeTitle($noParen);
        if ($normalized !== $noParen && $normalized !== '') {
            $variants[] = $normalized;
        }

        // De-duplicate while preserving order
        $unique = [];
        $seen = [];
        foreach ($variants as $v) {
            $v = trim($v);
            if ($v === '') {
                continue;
            }
            $lower = mb_strtolower($v);
            if (! isset($seen[$lower])) {
                $seen[$lower] = true;
                $unique[] = $v;
            }
        }

        return $unique;
    }

    /**
     * Normalize a title for comparison.
     */
    protected function normalizeTitle(string $title): string
    {
        $fast = RustPipelineLibrary::steamNormalizeTitle($title);
        if ($fast !== null) {
            return $fast;
        }

        return $this->normalizeTitlePhp($title);
    }

    public function normalizeTitlePhp(string $title): string
    {
        $s = mb_strtolower($title);

        // Replace separators
        $s = (string) preg_replace('/[._\-+]+/u', ' ', $s);

        // Convert roman numerals
        $s = $this->replaceRomanNumeralsPhp($s);

        // Remove common noise
        $noise = array_merge(
            array_map('strtolower', self::SCENE_GROUPS),
            array_map('strtolower', self::EDITION_TAGS),
            array_map('strtolower', self::RELEASE_TAGS),
            ['pc', 'win', 'windows', 'x86', 'x64', 'x32']
        );
        $noisePattern = '/\b('.implode('|', array_map(fn ($w) => preg_quote($w, '/'), $noise)).')\b/u';
        $s = (string) preg_replace($noisePattern, ' ', $s);

        // Remove non-alphanumeric
        $s = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        $s = trim($s);

        // Remove leading articles
        $s = (string) preg_replace('/^(the|a|an)\s+/u', '', $s);

        return $s;
    }

    /**
     * Strip edition tags from a title.
     */
    protected function stripEditionTags(string $title): string
    {
        $fast = RustPipelineLibrary::steamStripEditionTags($title);
        if ($fast !== null) {
            return $fast;
        }

        return $this->stripEditionTagsPhp($title);
    }

    public function stripEditionTagsPhp(string $title): string
    {
        // Remove compound edition phrases first
        $compoundPatterns = [
            '/\b(Game\s+of\s+the\s+Year)[\s._-]*(Edition)?\b/i',
            '/\bGOTY[\s._-]*(Edition)?\b/i',
            '/\b(Definitive|Deluxe|Ultimate|Complete|Enhanced|Special|Collectors?|Gold|Premium|Legendary|Standard|Digital)[\s._-]*(Edition)?\b/i',
            '/\b(Remastered|HD[\s._-]*Remaster|Directors?[\s._-]*Cut|Anniversary)[\s._-]*(Edition)?\b/i',
            '/\bEdition\b/i', // Remove standalone "Edition"
        ];
        foreach ($compoundPatterns as $pattern) {
            $title = (string) preg_replace($pattern, ' ', $title);
        }

        // Remove remaining individual edition tags
        foreach (self::EDITION_TAGS as $tag) {
            $title = (string) preg_replace('/\s*[-_]?\s*'.preg_quote($tag, '/').'\s*/i', ' ', $title);
        }

        return trim(preg_replace('/\s+/', ' ', $title) ?? '');
    }

    /**
     * Score a candidate title against the original search term.
     */
    protected function scoreTitle(string $candidate, string $original): float
    {
        $fast = RustPipelineLibrary::steamScoreTitle($candidate, $original);
        if ($fast !== null) {
            return $fast;
        }

        return $this->scoreTitlePhp($candidate, $original);
    }

    public function scoreTitlePhp(string $candidate, string $original): float
    {
        $normCand = $this->normalizeTitlePhp($candidate);
        $normOrig = $this->normalizeTitlePhp($original);

        if ($normCand === '' || $normOrig === '') {
            return 0.0;
        }

        // Perfect match
        if ($normCand === $normOrig) {
            return 100.0;
        }

        // Token-based scoring
        $tokensCand = $this->tokenize($normCand);
        $tokensOrig = $this->tokenize($normOrig);

        // Token containment check
        $intersect = count(array_intersect($tokensCand, $tokensOrig));
        $union = count(array_unique(array_merge($tokensCand, $tokensOrig)));
        $jaccard = $union > 0 ? ($intersect / $union) : 0.0;

        // Levenshtein similarity
        $lev = levenshtein($normCand, $normOrig);
        $maxLen = max(strlen($normCand), strlen($normOrig));
        $levSim = $maxLen > 0 ? (1.0 - ($lev / $maxLen)) : 0.0;

        // Prefix bonus
        $prefixBoost = 0.0;
        if (str_starts_with($normCand, $normOrig) || str_starts_with($normOrig, $normCand)) {
            $prefixBoost = 0.15;
        }

        // If all tokens of one are in the other
        $candInOrig = empty(array_diff($tokensCand, $tokensOrig));
        $origInCand = empty(array_diff($tokensOrig, $tokensCand));
        if ($candInOrig || $origInCand) {
            $shortCount = min(count($tokensCand), count($tokensOrig));
            $longCount = max(count($tokensCand), count($tokensOrig));
            $coverage = $longCount > 0 ? ($shortCount / $longCount) : 0.0;
            if ($coverage >= 0.8) {
                return 100.0;
            }
        }

        // Combined score
        $score = ($jaccard * 0.6 + $levSim * 0.4 + $prefixBoost) * 100.0;

        return max(0.0, min(100.0, $score));
    }

    /**
     * Tokenize a string.
     *
     * @return array<string, mixed>
     */
    protected function tokenize(string $s): array
    {
        return $this->tokenizePhp($s);
    }

    /** @return list<string> */
    public function tokenizePhp(string $s): array
    {
        $parts = preg_split('/\s+/u', mb_strtolower($s)) ?: [];
        $seen = [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || isset($seen[$p])) {
                continue;
            }
            $seen[$p] = true;
            $out[] = $p;
        }

        return $out;
    }

    /**
     * Replace roman numerals with arabic numbers.
     *
     * @return list<string>
     */
    protected function replaceRomanNumerals(string $s): string
    {
        $fast = RustPipelineLibrary::steamReplaceRomanNumerals($s);
        if ($fast !== null) {
            return $fast;
        }

        return $this->replaceRomanNumeralsPhp($s);
    }

    public function replaceRomanNumeralsPhp(string $s): string
    {
        $map = [
            'xx' => '20', 'xix' => '19', 'xviii' => '18', 'xvii' => '17', 'xvi' => '16',
            'xv' => '15', 'xiv' => '14', 'xiii' => '13', 'xii' => '12', 'xi' => '11',
            'x' => '10', 'ix' => '9', 'viii' => '8', 'vii' => '7', 'vi' => '6',
            'v' => '5', 'iv' => '4', 'iii' => '3', 'ii' => '2',
        ];

        // Don't replace standalone 'i' as it's too common in titles
        foreach ($map as $roman => $arabic) {
            $s = (string) preg_replace('/\b'.$roman.'\b/ui', $arabic, $s);
        }

        return $s;
    }

    /**
     * Build a SQL LIKE pattern from a search term.
     */
    protected function buildLikePattern(string $term): string
    {
        return $this->buildLikePatternPhp($term);
    }

    public function buildLikePatternPhp(string $term): string
    {
        $normalized = $this->normalizeTitle($term);
        $pattern = preg_replace('/\s+/', '%', $normalized);
        $pattern = trim($pattern ?? '');

        return $pattern === '' ? '%' : '%'.$pattern.'%';
    }

    /**
     * Clear all cached data.
     */
    public function clearCache(): void
    {
        // Note: This is a placeholder. In production, you'd use tagged caches
        // or implement specific cache key management.
        Log::info('SteamService: Cache clear requested');
    }

    private static ?FFI $titleMatchingFfi = null;

    private static bool $titleMatchingResolved = false;

    private static function titleMatchingFfi(): ?FFI
    {
        if (! self::$titleMatchingResolved) {
            self::$titleMatchingResolved = true;
            self::$titleMatchingFfi = RustPipelineLibrary::resolveDomain('steam_title_matching', <<<'C'
        ssize_t nntmux_steam_generate_query_variants_nul(const char *, size_t, char *, size_t);
        ssize_t nntmux_steam_tokenize_nul(const char *, size_t, char *, size_t);
        ssize_t nntmux_steam_build_like_pattern(const char *, size_t, char *, size_t);
        C);
        }

        return self::$titleMatchingFfi;
    }

    /** Null means unavailable/declined; negative byte counts request an exact-size retry. */
    private static function titleMatchingStringNative(string $symbol, string $input): ?string
    {
        $ffi = self::$titleMatchingFfi ?? self::titleMatchingFfi();
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($input) + 32), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
            if (! is_int($written) || $written === PHP_INT_MIN) {
                return null;
            }
            if ($written < 0) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string>|null */
    public static function generateQueryVariantsNative(string $title): ?array
    {
        $packed = self::titleMatchingStringNative('nntmux_steam_generate_query_variants_nul', $title);

        return $packed === null ? null : ($packed === '' ? [] : explode("\0", $packed));
    }

    /** @return list<string>|null */
    public static function tokenizeNative(string $title): ?array
    {
        $packed = self::titleMatchingStringNative('nntmux_steam_tokenize_nul', $title);

        return $packed === null ? null : ($packed === '' ? [] : explode("\0", $packed));
    }

    public static function buildLikePatternNative(string $title): ?string
    {
        return self::titleMatchingStringNative('nntmux_steam_build_like_pattern', $title);
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.26x, p99 1.68x; uncommon mean 2.82x, p99 2.35x; see the W44 parity test). */
    public const bool W444_RUST_FFI_DEFAULT = true;

    public const string W444_FFI_CDEF = <<<'C'
        ssize_t nntmux_w444_steam_title_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W444_SUMMARY_KEYS = ['total_titles', 'empty_title_count', 'scene_group_count', 'edition_tag_count', 'release_tag_count', 'platform_tag_count', 'version_count', 'bracket_count', 'extension_count', 'url_encoded_count', 'roman_numeral_count'];

    private static ?bool $w444UseRustFfi = null;

    private static FFI|false|null $w444Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW444RustFfi(?bool $enable = true): void
    {
        self::$w444UseRustFfi = $enable;
    }

    public static function usesW444RustFfi(): bool
    {
        return self::$w444UseRustFfi ?? self::W444_RUST_FFI_DEFAULT;
    }

    public static function resetW444RustFfi(): void
    {
        self::$w444UseRustFfi = null;
        self::$w444Ffi = null;
    }

    /**
     * Summarize release titles as cleanTitle()/normalizeTitle() see them:
     * a trailing [-_] SCENE_GROUPS suffix, edition, RELEASE_TAGS (with a digit
     * tail), platform and roman numeral words, dotted versions with three or
     * more parts, brackets, archive/video extensions and %XX escapes. Entries
     * come from strings, integers, or the title, name or searchname field;
     * other values count as empty. PCRE runs without /u, so \b, \s and /i
     * are ASCII-only.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeSteamTitleBatch(array $entries): array
    {
        return self::usesW444RustFfi()
            ? self::summarizeSteamTitleBatchNative($entries)
            : self::summarizeSteamTitleBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeSteamTitleBatchNative(array $entries): array
    {
        return self::w444Call(self::w444Entries($entries)) ?? self::summarizeSteamTitleBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizeSteamTitleBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W444_SUMMARY_KEYS, 0);
        $groups = '/[-_]\s*(?:'.implode('|', array_map(static fn (string $group): string => preg_quote($group, '/'), self::SCENE_GROUPS)).')\s*\z/i';
        $releases = '/\b(?:'.implode('|', array_map(static fn (string $tag): string => preg_quote($tag, '/'), self::RELEASE_TAGS)).')\d*\b/i';
        foreach (self::w444Entries($entries) as $title) {
            $summary['total_titles']++;
            $title = trim($title);
            if ($title === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $summary['scene_group_count'] += (int) (preg_match($groups, $title) === 1);
            $summary['edition_tag_count'] += (int) (preg_match('/\b(?:GOTY|Edition|Remastered|Definitive|Deluxe|Ultimate|Complete|Anniversary|Enhanced|Special|Collectors?|Gold|Premium|Legendary|Standard|Digital)\b/i', $title) === 1);
            $summary['release_tag_count'] += (int) (preg_match($releases, $title) === 1);
            $summary['platform_tag_count'] += (int) (preg_match('/\b(?:pc|win|windows|x86|x64|x32)\b/i', $title) === 1);
            $summary['version_count'] += (int) (preg_match('/\bv?\d+(?:\.\d+){2,}\b/i', $title) === 1);
            $summary['bracket_count'] += (int) (strpbrk($title, '[({') !== false);
            $summary['extension_count'] += (int) (preg_match('/\.(?:zip|rar|7z|iso|nfo|sfv|exe|mkv|mp4|avi)\z/i', $title) === 1);
            $summary['url_encoded_count'] += (int) (preg_match('/%[0-9A-Fa-f]{2}/', $title) === 1);
            $summary['roman_numeral_count'] += (int) (preg_match('/\b(?:xx|xix|xviii|xvii|xvi|xv|xiv|xiii|xii|xi|x|ix|viii|vii|vi|v|iv|iii|ii)\b/i', $title) === 1);
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w444Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['title'] ?? $item['name'] ?? $item['searchname'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w444Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w444Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w444', self::W444_FFI_CDEF) ?? false;
            $ffi = self::$w444Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W444_FFI_CDEF.)
            $written = $ffi->nntmux_w444_steam_title_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W444_FFI_CDEF.)
                $written = $ffi->nntmux_w444_steam_title_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W444_SUMMARY_KEYS || $result['total_titles'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W444_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    /** C signature of the Wave 62.5 {@see self::lookupPlanBatch()} helper. */
    private const string W62_STEAM_PLAN_FFI_CDEF = 'int64_t nntmux_w625_steam_lookup_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 62.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 2.85/2.78/2.65/2.63x); see tests/Unit/RustSteamTmdbClientTraktTurnstileReleaseSearchW62ParityTest.php.
     */
    public const bool W62_RUST_FFI_DEFAULT = true;

    /**
     * Wave 62.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
                    $ffi = RustPipelineLibrary::resolveDomain('w62_5', self::W62_STEAM_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
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
                return $enabled ?? self::W62_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW62RustFfi(): bool
    {
        return self::w62RustFfiState() === true;
    }

    /**
     * Runs $callback with lookupPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 62.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w62Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w62RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w625_steam_lookup_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
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
     * Batch Steam lookup plan over `appid\ttitle\tcandidate\ttype`. Ten words per record: `[appid, appid_kind,
     * title_words, clean_len, clean_words, edition_code, scene_code, similarity, tier, flags]`. The trimmed app id
     * is 1-10 digits with a positive value (kind 1) or a STEAM_STORE_URL link whose path segment holds them (kind
     * 2); 0 empty, 3 invalid. Title tokens are ASCII alphanumeric or non-ASCII runs. The edition is the 1-based
     * EDITION_TAGS entry (code order) whose words first occur as a contiguous ASCII case-insensitive token run.
     * The clean title drops that run plus SCENE_GROUPS and RELEASE_TAGS tokens; its length joins the rest with
     * single spaces. The scene code is the 1-based SCENE_GROUPS index of the first scene token. Similarity is the
     * Dice coefficient `200 * common / (|clean| + |candidate|)` over distinct ASCII case-insensitive tokens; the
     * tier is 2 at MATCH_THRESHOLD, 1 at RELAXED_MATCH_THRESHOLD, else 0. Flags: 1 empty title, 2 invalid app id,
     * 4 store URL, 8 scene group, 16 release tag, 32 edition tag, 64 empty candidate, 128 non-ASCII title, 256
     * non-game type, 512 not exactly four fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function lookupPlanBatch(array $records): array
    {
        return self::usesW62RustFfi() ? self::lookupPlanBatchNative($records) : self::lookupPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function lookupPlanBatchPhp(array $records): array
    {
        $scene = array_flip(array_map(strtolower(...), self::SCENE_GROUPS));
        $release = array_flip(array_map(strtolower(...), self::RELEASE_TAGS));
        $editions = [];
        foreach (self::EDITION_TAGS as $tag) {
            $editions[] = [' '.strtolower($tag).' ', substr_count($tag, ' ') + 1];
        }
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 4 ? 0 : 512;
            $appid = trim($fields[0]);
            [$id, $idKind] = [0, 0];
            if ($appid !== '') {
                [$body, $kind] = [$appid, 1];
                if (str_starts_with($appid, self::STEAM_STORE_URL)) {
                    $flags |= 4;
                    $rest = substr($appid, \strlen(self::STEAM_STORE_URL));
                    $slash = strpos($rest, '/');
                    [$body, $kind] = [$slash === false ? $rest : substr($rest, 0, $slash), 2];
                }
                $value = self::w62Digits($body, 10);
                if ($value !== null && $value > 0) {
                    [$id, $idKind] = [$value, $kind];
                } else {
                    $flags |= 2;
                    $idKind = 3;
                }
            }
            $title = $fields[1] ?? '';
            $tokens = self::w62Tokens(strtolower($title));
            if ($tokens === []) {
                $flags |= 1;
            }
            [$edition, $spanStart, $spanEnd] = [0, -1, -1];
            $joined = ' '.implode(' ', $tokens).' ';
            foreach ($editions as $index => [$needle, $length]) {
                $at = strpos($joined, $needle);
                if ($at !== false) {
                    $spanStart = $at === 0 ? 0 : substr_count(substr($joined, 0, $at), ' ');
                    [$edition, $spanEnd] = [$index + 1, $spanStart + $length];
                    $flags |= 32;
                    break;
                }
            }
            $sceneCode = 0;
            $clean = [];
            foreach ($tokens as $index => $token) {
                if ($index >= $spanStart && $index < $spanEnd) {
                    continue;
                }
                if (isset($scene[$token])) {
                    if ($sceneCode === 0) {
                        $sceneCode = $scene[$token] + 1;
                    }
                    $flags |= 8;

                    continue;
                }
                if (isset($release[$token])) {
                    $flags |= 16;

                    continue;
                }
                $clean[] = $token;
            }
            $left = array_unique($clean);
            $right = array_unique(self::w62Tokens(strtolower($fields[2] ?? '')));
            if ($right === []) {
                $flags |= 64;
            }
            $sizes = \count($left) + \count($right);
            $similarity = $sizes > 0 ? intdiv(200 * \count(array_intersect($left, $right)), $sizes) : 0;
            $tier = $similarity >= self::MATCH_THRESHOLD ? 2 : ($similarity >= self::RELAXED_MATCH_THRESHOLD ? 1 : 0);
            if (preg_match('/[\x80-\xff]/', $title) === 1) {
                $flags |= 128;
            }
            $type = trim($fields[3] ?? '');
            if ($type !== '' && strtolower($type) !== 'game') {
                $flags |= 256;
            }
            array_push($out, $id, $idKind, \count($tokens), $clean === [] ? 0 : \strlen(implode(' ', $clean)), \count($clean), $edition, $sceneCode, $similarity, $tier, $flags);
        }

        return $out;
    }

    /** 1-$max ASCII digits as an integer, else null. */
    private static function w62Digits(string $text, int $max): ?int
    {
        return $text !== '' && \strlen($text) <= $max && ctype_digit($text) ? (int) $text : null;
    }

    /**
     * Runs of ASCII alphanumeric or non-ASCII bytes.
     *
     * @return list<string>
     */
    private static function w62Tokens(string $text): array
    {
        $tokens = preg_split('/[^A-Za-z0-9\x80-\xff]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return \is_array($tokens) ? $tokens : [];
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function lookupPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w62Words($count, implode("\0", $records), 10 * $count) ?? self::lookupPlanBatchPhp($records);
    }
}
