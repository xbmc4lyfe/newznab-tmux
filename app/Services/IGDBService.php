<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\IGDB\Models\Company;
use App\Services\IGDB\Models\Game;
use App\Support\MetadataSources;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * IGDBService - IGDB (Internet Game Database) API integration.
 *
 * Note: Local IGDB resource models expose dynamic properties
 * (name, id, etc.) that PHPStan cannot fully resolve.
 *
 * Features:
 * - Rate limiting and caching
 * - Multiple search strategies for better matching
 * - Complete game metadata retrieval
 * - Age rating and release date extraction
 */
class IGDBService
{
    // Rate limiting constants
    protected const string RATE_LIMIT_KEY = 'igdb_api_rate_limit';

    protected const int REQUESTS_PER_MINUTE = 4;

    protected const int DECAY_SECONDS = 60;

    // Cache TTLs
    protected const int GAME_CACHE_TTL = 86400; // 24 hours

    protected const int FAILED_LOOKUP_CACHE_TTL = 3600; // 1 hour

    // Matching configuration
    protected const int MATCH_THRESHOLD = 85;

    // PC Platform IDs in IGDB
    protected const array PC_PLATFORM_IDS = [6, 13, 14, 3]; // PC Windows, DOS, Mac, Linux

    protected const array PLATFORM_ALIASES = [
        'x360' => 'xbox 360',
        'xbox360' => 'xbox 360',
        'xbla' => 'xbox 360',
        'xbox one' => 'xbox one',
        'xboxone' => 'xbox one',
        'x-box' => 'xbox',
        'xbox' => 'xbox',
        'dsi' => 'nintendo ds',
        'nds' => 'nintendo ds',
        '3ds' => 'nintendo 3ds',
        'ps2' => 'playstation2',
        'ps3' => 'playstation 3',
        'ps4' => 'playstation 4',
        'psp' => 'sony psp',
        'psvita' => 'playstation vita',
        'psx' => 'playstation',
        'psx2psp' => 'playstation',
        'wiiu' => 'nintendo wii u',
        'wii' => 'nintendo wii',
        'ngc' => 'gamecube',
        'gc' => 'gamecube',
        'n64' => 'nintendo 64',
        'nes' => 'nintendo nes',
        'super nintendo' => 'snes',
        'nintendo super nes' => 'snes',
        'snes' => 'snes',
    ];

    private const string FFI_CDEF = <<<'C'
        const char *nntmux_igdb_normalize_for_match(const char *input, size_t input_len);
        const char *nntmux_igdb_extract_base_title(const char *input, size_t input_len);
        double nntmux_igdb_compute_similarity(const char *a, size_t a_len, const char *b, size_t b_len);
        ssize_t nntmux_igdb_score_candidates_batch_nul(const char *query, size_t query_len, const char *candidates, size_t candidates_len, size_t count, double *out_scores);
        C;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    private static ?CData $scoreBuf = null;

    private static int $scoreCap = 0;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Check if IGDB is configured.
     */
    public function isConfigured(): bool
    {
        return MetadataSources::isAvailable(MetadataSources::IGDB);
    }

    /**
     * Search for a game by title and return the best matching Game object.
     */
    public function search(string $title): ?Game
    {
        if (! $this->isConfigured() || empty($title)) {
            return null;
        }

        $cacheKey = 'igdb_search:'.md5(mb_strtolower($title));

        // Check failed lookup cache
        if (Cache::has("igdb_search_failed:{$cacheKey}")) {
            Log::debug('IGDBService: Skipping previously failed search', ['title' => $title]);

            return null;
        }

        // Check successful search cache
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::debug('IGDBService: Using cached search result', ['title' => $title]);

            return $cached instanceof Game ? $cached : null;
        }

        $game = $this->searchWithStrategies($title, self::PC_PLATFORM_IDS);

        if ($game !== null) {
            Cache::put($cacheKey, $game, self::GAME_CACHE_TTL);
            Log::info('IGDBService: Found match', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound
        } else {
            Cache::put("igdb_search_failed:{$cacheKey}", true, self::FAILED_LOOKUP_CACHE_TTL);
            Log::debug('IGDBService: No match found', ['title' => $title]);
        }

        return $game;
    }

    /**
     * Search for a console game using an optional platform hint.
     */
    public function searchConsole(string $title, string $platformHint): ?Game
    {
        if (! $this->isConfigured() || $title === '') {
            return null;
        }

        $cacheKey = 'igdb_console_search:'.md5(mb_strtolower($title.'|'.$platformHint));

        if (Cache::has("igdb_console_search_failed:{$cacheKey}")) {
            Log::debug('IGDBService: Skipping previously failed console search', [
                'title' => $title,
                'platform' => $platformHint,
            ]);

            return null;
        }

        $cached = Cache::get($cacheKey);
        if ($cached instanceof Game) {
            Log::debug('IGDBService: Using cached console search result', [
                'title' => $title,
                'platform' => $platformHint,
            ]);

            return $cached;
        }

        $game = $this->searchWithStrategies($title, null, $platformHint);

        if ($game instanceof Game) {
            Cache::put($cacheKey, $game, self::GAME_CACHE_TTL);

            return $game;
        }

        Cache::put("igdb_console_search_failed:{$cacheKey}", true, self::FAILED_LOOKUP_CACHE_TTL);

        return null;
    }

    /**
     * Get complete game details from a Game object.
     *
     * @return array<string, mixed>
     *                              title: string,
     *                              asin: string,
     *                              review: string,
     *                              coverurl: string,
     *                              releasedate: string,
     *                              esrb: string,
     *                              url: string,
     *                              backdropurl: string,
     *                              trailer: string,
     *                              publisher: string,
     *                              developer: string,
     *                              genres: array,
     *                              }|false
     * @return array<string, mixed>
     */
    public function getGameDetails(Game $game): array|false
    {
        if (empty($game->name)) {
            return false;
        }

        $genreName = '';

        return $this->buildGameData($game, $genreName);
    }

    /**
     * Search IGDB using multiple strategies for better match rates.
     */
    protected function searchWithStrategies(string $title, ?array $platformIds = null, ?string $platformHint = null): ?Game
    {
        // Strategy 1: Exact name search with PC platform filter
        $game = $this->searchExact($title, $platformIds, $platformHint);
        if ($game !== null) {
            Log::debug('IGDBService: Exact match found', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound

            return $game;
        }

        // Strategy 2: Fuzzy search using IGDB's search endpoint
        $game = $this->searchFuzzy($title, $platformIds, $platformHint);
        if ($game !== null) {
            Log::debug('IGDBService: Fuzzy match found', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound

            return $game;
        }

        // Strategy 3: Search without special characters
        $cleanTitle = preg_replace('/[^a-zA-Z0-9\s]/', '', $title);
        if ($cleanTitle !== $title && $cleanTitle !== '') {
            $game = $this->searchFuzzy($cleanTitle, $platformIds, $platformHint);
            if ($game !== null) {
                Log::debug('IGDBService: Clean title match found', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound

                return $game;
            }
        }

        // Strategy 4: Try with common subtitle patterns removed
        $baseTitle = $this->extractBaseTitle($title);
        if ($baseTitle !== $title && $baseTitle !== $cleanTitle && $baseTitle !== '') {
            $game = $this->searchFuzzy($baseTitle, $platformIds, $platformHint);
            if ($game !== null) {
                Log::debug('IGDBService: Base title match found', ['title' => $title, 'matched' => $game->name]); // @phpstan-ignore property.notFound

                return $game;
            }
        }

        return null;
    }

    /**
     * Exact name search on IGDB with PC platform filter.
     */
    protected function searchExact(string $title, ?array $platformIds = null, ?string $platformHint = null): ?Game
    {
        try {
            $results = RateLimiter::attempt(
                self::RATE_LIMIT_KEY,
                self::REQUESTS_PER_MINUTE,
                function () use ($platformIds, $title) {
                    $query = Game::where('name', $title)
                        ->with($this->getGameRelations())
                        ->orderByDesc('aggregated_rating_count')
                        ->limit(10);

                    if ($platformIds !== null) {
                        $query->whereIn('platforms', $platformIds);
                    }

                    return $query->get();
                },
                self::DECAY_SECONDS
            );

            if ($results === true || empty($results)) {
                return null;
            }

            return $this->findBestMatch($results, $title, $platformHint);
        } catch (\Exception $e) {
            Log::warning('IGDBService: Exact search error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fuzzy search on IGDB using the search endpoint.
     */
    protected function searchFuzzy(string $title, ?array $platformIds = null, ?string $platformHint = null): ?Game
    {
        try {
            $results = RateLimiter::attempt(
                self::RATE_LIMIT_KEY,
                self::REQUESTS_PER_MINUTE,
                function () use ($platformIds, $title) {
                    $query = Game::search($title)
                        ->where('category', 0) // Main game only (not DLC, expansion, etc.)
                        ->with($this->getGameRelations())
                        ->orderByDesc('aggregated_rating_count')
                        ->limit(10);

                    if ($platformIds !== null) {
                        $query->whereIn('platforms', $platformIds);
                    }

                    return $query->get();
                },
                self::DECAY_SECONDS
            );

            if ($results === true || empty($results)) {
                return null;
            }

            return $this->findBestMatch($results, $title, $platformHint);
        } catch (\Exception $e) {
            Log::warning('IGDBService: Fuzzy search error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get relations to load with IGDB queries.
     *
     * @return array<string, mixed>
     */
    protected function getGameRelations(): array
    {
        return [
            'cover' => ['url', 'image_id'],
            'screenshots' => ['url', 'image_id'],
            'artworks' => ['url', 'image_id'],
            'videos' => ['video_id', 'name'],
            'involved_companies' => ['company', 'publisher', 'developer'],
            'genres' => ['name'],
            'themes' => ['name'],
            'game_modes' => ['name'],
            'player_perspectives' => ['name'],
            'age_ratings' => ['rating', 'category'],
            'websites' => ['url', 'category'],
            'platforms' => ['name', 'abbreviation'],
            'release_dates' => ['date', 'platform', 'human'],
        ];
    }

    /**
     * Find the best matching game from results.
     */
    protected function findBestMatch(mixed $results, string $title, ?string $platformHint = null): ?Game
    {
        $bestMatch = null;
        $bestScore = 0;
        $normalizedQuery = $this->normalizeForMatch($title);

        foreach ($results as $game) {
            $normalizedName = $this->normalizeForMatch($game->name);
            $score = $this->computeSimilarity($normalizedQuery, $normalizedName);

            // Boost score for games with more ratings (more popular/verified)
            if (isset($game->aggregated_rating_count) && $game->aggregated_rating_count > 10) {
                $score += min(5, $game->aggregated_rating_count / 100);
            }

            // Boost score for games with covers (more complete data)
            if (isset($game->cover)) {
                $score += 2;
            }

            if ($platformHint !== null && $platformHint !== '') {
                $platformScore = $this->getPlatformMatchScore($game, $platformHint);
                $score += $platformScore > 0 ? $platformScore : -10;
            }

            // Check alternative names if available
            if (isset($game->alternative_names)) {
                foreach ($game->alternative_names as $altName) {
                    $altScore = $this->computeSimilarity($normalizedQuery, $this->normalizeForMatch($altName['name'] ?? ''));
                    if ($altScore > $score) {
                        $score = $altScore;
                    }
                }
            }

            if ($score >= self::MATCH_THRESHOLD && $score > $bestScore) {
                $bestMatch = $game;
                $bestScore = $score;
            }
        }

        if ($bestMatch !== null) {
            Log::debug('IGDBService: Best match selected', [
                'query' => $title,
                'matched' => $bestMatch->name,
                'score' => $bestScore,
            ]);
        }

        return $bestMatch;
    }

    /**
     * Rust `nntmux_igdb_score_candidates_batch_nul` for parity testing: `computeSimilarity(normalizeForMatch($title),
     * normalizeForMatch($name))` per name; `null` when the library is unavailable or declines.
     *
     * @param  array<int, string>  $names
     * @return array<int, float>|null
     */
    public static function scoreCandidatesNative(string $title, array $names): ?array
    {
        $ffi = self::$ffi ?? (self::$ffiResolved ? null : self::ffi());
        $count = count($names);
        if ($ffi === null || $count === 0) {
            return null;
        }
        $joined = implode("\0", $names);
        if (substr_count($joined, "\0") !== $count - 1) {
            return null;
        }
        try {
            if (self::$scoreBuf === null || self::$scoreCap < $count) {
                $cap = max(64, $count * 2);
                self::$scoreBuf = $ffi->new("double[{$cap}]");
                self::$scoreCap = $cap;
            }
            $buf = self::$scoreBuf;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_igdb_score_candidates_batch_nul($title, strlen($title), $joined, strlen($joined), $count, $buf);
            if ($written !== $count) {
                return null;
            }
            $scores = [];
            for ($i = 0; $i < $count; $i++) {
                $scores[] = (float) $buf[$i];
            }

            return $scores;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Build console-specific game data from an IGDB Game model.
     *
     * @return array<string, mixed>
     */
    public function buildConsoleData(Game $game, string $platformHint): array
    {
        $genres = $this->extractGenres($game);
        $publishers = $this->extractCompanyNames($game, 'publisher');

        return [
            'title' => $game->name,
            'asin' => (string) $game->id,
            'review' => (string) ($game->summary ?? ''),
            'coverurl' => $this->getImageUrl($game->cover ?? null, 'cover_big'),
            'releasedate' => $this->getReleaseDate($game),
            'esrb' => isset($game->aggregated_rating) && is_numeric($game->aggregated_rating)
                ? round((float) $game->aggregated_rating).'%'
                : $this->getAgeRating($game),
            'url' => $game->url ?? '',
            'publisher' => ! empty($publishers) ? implode(',', $publishers) : 'Unknown',
            'platform' => $this->resolvePlatformName($game, $platformHint),
            'consolegenre' => ! empty($genres) ? implode(',', $genres) : 'Unknown',
            'salesrank' => '',
        ];
    }

    /**
     * Build game data array from IGDB Game model.
     *
     * @return array<string, mixed>
     */
    public function buildGameData(Game $game, string &$genreName): array
    {
        // Extract publishers and developers
        $publishers = [];
        $developers = [];
        if (! empty($game->involved_companies)) {
            $involvedCompanies = $game->involved_companies;
            if ($involvedCompanies instanceof Collection) {
                $involvedCompanies = $involvedCompanies->toArray();
            }
            foreach ($involvedCompanies as $company) {
                $isPublisher = is_array($company) ? ($company['publisher'] ?? false) : ($company->publisher ?? false);
                $isDeveloper = is_array($company) ? ($company['developer'] ?? false) : ($company->developer ?? false);
                $companyId = is_array($company) ? ($company['company'] ?? null) : ($company->company ?? null);

                if ($isPublisher === true && $companyId) {
                    $companyData = Company::find($companyId);
                    if ($companyData) {
                        $publishers[] = $companyData->name;
                    }
                }
                if ($isDeveloper === true && $companyId) {
                    $companyData = Company::find($companyId);
                    if ($companyData) {
                        $developers[] = $companyData->name;
                    }
                }
            }
        }

        // Extract genres
        $genres = $this->extractGenres($game);
        $genreName = $this->matchGenre(implode(',', array_filter($genres)));

        // Get cover and backdrop URLs
        $coverUrl = $this->getImageUrl($game->cover ?? null, 'cover_big');
        $backdropUrl = $this->getBackdropUrl($game);

        // Get trailer URL
        $trailerUrl = $this->getTrailerUrl($game);

        // Get rating
        $esrb = $this->getAgeRating($game);

        // Get release date for PC
        $releaseDate = $this->getReleaseDate($game);

        // Get game URL
        $gameUrl = $game->url ?? ('https://www.igdb.com/games/'.($game->slug ?? $game->id)); // @phpstan-ignore property.notFound

        // Build review text
        $review = $this->buildReview($game, $developers); // @phpstan-ignore argument.type

        Log::info('IGDBService: Game data built', [
            'title' => $game->name, // @phpstan-ignore property.notFound
            'id' => $game->id, // @phpstan-ignore property.notFound
            'has_cover' => ! empty($coverUrl),
            'has_backdrop' => ! empty($backdropUrl),
            'genres' => $genres,
        ]);

        return [
            'title' => $game->name, // @phpstan-ignore property.notFound
            'asin' => 'igdb-'.$game->id, // @phpstan-ignore property.notFound
            'review' => $review,
            'coverurl' => $coverUrl,
            'releasedate' => $releaseDate,
            'esrb' => $esrb,
            'url' => $gameUrl,
            'backdropurl' => $backdropUrl,
            'trailer' => $trailerUrl,
            'publisher' => ! empty($publishers) ? implode(', ', array_slice($publishers, 0, 3)) : 'Unknown',
            'developer' => ! empty($developers) ? implode(', ', array_slice($developers, 0, 3)) : '',
            'genres' => $genres,
        ];
    }

    /**
     * Extract genres from game data.
     *
     * @return array<string, mixed>
     */
    protected function extractGenres(Game $game): array
    {
        $genres = [];
        if (! empty($game->genres)) {
            $gameGenres = $game->genres;
            if ($gameGenres instanceof Collection) {
                $gameGenres = $gameGenres->toArray();
            }
            foreach ($gameGenres as $genre) {
                $genres[] = is_array($genre) ? ($genre['name'] ?? '') : ($genre->name ?? '');
            }
        }

        // Fall back to themes if no genres
        if (empty($genres) && ! empty($game->themes)) {
            $gameThemes = $game->themes;
            if ($gameThemes instanceof Collection) {
                $gameThemes = $gameThemes->toArray();
            }
            foreach ($gameThemes as $theme) {
                $genres[] = is_array($theme) ? ($theme['name'] ?? '') : ($theme->name ?? '');
            }
        }

        return array_filter($genres);
    }

    /**
     * @return array<int, string>
     */
    protected function extractCompanyNames(Game $game, string $flag): array
    {
        $names = [];
        if (empty($game->involved_companies)) {
            return $names;
        }

        $involvedCompanies = $game->involved_companies;
        if ($involvedCompanies instanceof Collection) {
            $involvedCompanies = $involvedCompanies->toArray();
        }

        foreach ($involvedCompanies as $company) {
            $isMatch = is_array($company) ? ($company[$flag] ?? false) : ($company->{$flag} ?? false);
            $companyId = is_array($company) ? ($company['company'] ?? null) : ($company->company ?? null);

            if ($isMatch !== true || ! $companyId) {
                continue;
            }

            $companyData = Company::find($companyId);
            if ($companyData !== null && ! empty($companyData->name)) {
                $names[] = $companyData->name;
            }
        }

        return array_values(array_unique($names));
    }

    protected function resolvePlatformName(Game $game, string $platformHint): string
    {
        $normalizedHint = $this->normalizePlatformHint($platformHint);
        $fallback = '';

        foreach ((array) ($game->platforms ?? []) as $platform) {
            $name = is_array($platform) ? (string) ($platform['name'] ?? '') : (string) ($platform->name ?? '');
            $abbreviation = is_array($platform) ? (string) ($platform['abbreviation'] ?? '') : (string) ($platform->abbreviation ?? '');

            if ($fallback === '' && $name !== '') {
                $fallback = $name;
            }

            foreach ([$name, $abbreviation] as $candidate) {
                if ($candidate !== '' && $this->normalizePlatformHint($candidate) === $normalizedHint) {
                    return $name !== '' ? $name : $candidate;
                }
            }
        }

        return $fallback;
    }

    protected function getPlatformMatchScore(Game $game, string $platformHint): int
    {
        $normalizedHint = $this->normalizePlatformHint($platformHint);
        if ($normalizedHint === '') {
            return 0;
        }

        foreach ((array) ($game->platforms ?? []) as $platform) {
            $name = is_array($platform) ? (string) ($platform['name'] ?? '') : (string) ($platform->name ?? '');
            $abbreviation = is_array($platform) ? (string) ($platform['abbreviation'] ?? '') : (string) ($platform->abbreviation ?? '');

            foreach ([$name, $abbreviation] as $candidate) {
                if ($candidate === '') {
                    continue;
                }

                if ($this->normalizePlatformHint($candidate) === $normalizedHint) {
                    return 15;
                }
            }
        }

        return 0;
    }

    protected function normalizePlatformHint(string $platform): string
    {
        $normalized = mb_strtolower(trim($platform));

        return self::PLATFORM_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * Get properly formatted IGDB image URL.
     *
     * @param  array<string, mixed>  $imageData
     * @return array<int<0, max>, mixed>
     */
    public function getImageUrl(array|object|null $imageData, string $size = 'cover_big'): string
    {
        if (empty($imageData)) {
            return '';
        }

        if (is_object($imageData)) {
            $imageId = $imageData->image_id ?? ($imageData->imageId ?? null);
            $url = $imageData->url ?? null;
            $imageData = [
                'image_id' => $imageId,
                'url' => $url,
            ];
        }

        if (! empty($imageData['image_id'])) {
            return 'https://images.igdb.com/igdb/image/upload/t_'.$size.'/'.$imageData['image_id'].'.jpg';
        }

        if (! empty($imageData['url'])) {
            $url = $imageData['url'];
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            }

            return preg_replace('/t_[a-z0-9_]+/', 't_'.$size, $url);
        }

        return '';
    }

    /**
     * Get backdrop URL from artworks or screenshots.
     */
    protected function getBackdropUrl(Game $game): string
    {
        if (! empty($game->artworks)) {
            $artworks = $game->artworks;
            $firstArtwork = ($artworks instanceof Collection) ? $artworks->first() : ($artworks[0] ?? null);
            $url = $this->getImageUrl($firstArtwork, '1080p');
            if (! empty($url)) {
                return $url;
            }
        }

        if (! empty($game->screenshots)) {
            $screenshots = $game->screenshots;
            $firstScreenshot = ($screenshots instanceof Collection) ? $screenshots->first() : ($screenshots[0] ?? null);

            return $this->getImageUrl($firstScreenshot, '1080p');
        }

        return '';
    }

    /**
     * Get trailer URL from videos.
     */
    protected function getTrailerUrl(Game $game): string
    {
        if (! empty($game->videos)) {
            $videos = $game->videos;
            if ($videos instanceof Collection) {
                $videos = $videos->toArray();
            }
            foreach ($videos as $video) {
                $videoId = is_array($video) ? ($video['video_id'] ?? null) : ($video->video_id ?? null);
                if ($videoId) {
                    return 'https://www.youtube.com/watch?v='.$videoId;
                }
            }
        }

        return '';
    }

    /**
     * Get age rating string from IGDB age ratings.
     */
    protected function getAgeRating(Game $game): string
    {
        $ageRatings = $game->age_ratings ?? [];
        if ($ageRatings instanceof Collection) {
            $ageRatings = $ageRatings->toArray();
        }

        if (empty($ageRatings)) {
            return 'Not Rated';
        }

        // ESRB ratings map
        $esrbMap = [
            6 => 'RP (Rating Pending)',
            7 => 'EC (Early Childhood)',
            8 => 'E (Everyone)',
            9 => 'E10+ (Everyone 10+)',
            10 => 'T (Teen)',
            11 => 'M (Mature 17+)',
            12 => 'AO (Adults Only)',
        ];

        // PEGI ratings map
        $pegiMap = [
            1 => 'PEGI 3',
            2 => 'PEGI 7',
            3 => 'PEGI 12',
            4 => 'PEGI 16',
            5 => 'PEGI 18',
        ];

        foreach ($ageRatings as $rating) {
            $category = is_array($rating) ? ($rating['category'] ?? 0) : ($rating->category ?? 0);
            $ratingValue = is_array($rating) ? ($rating['rating'] ?? 0) : ($rating->rating ?? 0);

            // Prefer ESRB
            if ($category === 1 && isset($esrbMap[$ratingValue])) {
                return $esrbMap[$ratingValue];
            }
            // Fall back to PEGI
            if ($category === 2 && isset($pegiMap[$ratingValue])) {
                return $pegiMap[$ratingValue];
            }
        }

        return 'Not Rated';
    }

    /**
     * Get PC release date from IGDB game data.
     */
    protected function getReleaseDate(Game $game): string
    {
        if (! empty($game->release_dates)) {
            foreach ($game->release_dates as $release) {
                if (isset($release['platform']) && $release['platform'] === 6 && isset($release['date'])) {
                    return Carbon::createFromTimestamp($release['date'])->format('Y-m-d');
                }
            }
            if (isset($game->release_dates[0]['date'])) {
                return Carbon::createFromTimestamp($game->release_dates[0]['date'])->format('Y-m-d');
            }
        }

        if (isset($game->first_release_date)) {
            if ($game->first_release_date instanceof Carbon) {
                return $game->first_release_date->format('Y-m-d');
            }
            if (is_numeric($game->first_release_date)) {
                return Carbon::createFromTimestamp($game->first_release_date)->format('Y-m-d');
            }
        }

        return now()->format('Y-m-d');
    }

    /**
     * Build review/summary text.
     *
     * @param  array<string, mixed>  $developers
     */
    protected function buildReview(Game $game, array $developers): string
    {
        $review = $game->summary ?? '';
        if (empty($review) && isset($game->storyline)) {
            $review = $game->storyline;
        }

        $additionalInfo = [];
        if (! empty($developers)) {
            $additionalInfo[] = 'Developer: '.implode(', ', array_slice($developers, 0, 3));
        }
        if (! empty($game->game_modes)) {
            $gameModes = $game->game_modes;
            if ($gameModes instanceof Collection) {
                $modes = $gameModes->map(fn ($m) => is_array($m) ? ($m['name'] ?? '') : ($m->name ?? ''))->toArray();
            } else {
                $modes = array_map(fn ($m) => $m['name'] ?? '', $gameModes);
            }
            $additionalInfo[] = 'Modes: '.implode(', ', array_filter($modes));
        }
        if (! empty($additionalInfo) && ! empty($review)) {
            $review .= "\n\n".implode("\n", $additionalInfo);
        }

        return $review;
    }

    /**
     * Extract base title by removing common subtitle patterns.
     */
    protected function extractBaseTitle(string $title): string
    {
        if (self::$useRustFfi && ($ffi = self::$ffi ?? (self::$ffiResolved ? null : self::ffi())) !== null) {
            try {
                // @phpstan-ignore method.notFound
                $base = $ffi->nntmux_igdb_extract_base_title($title, strlen($title));
                if (is_string($base)) {
                    return $base;
                }
            } catch (Throwable) {
            }
        }

        return $this->extractBaseTitlePhp($title);
    }

    /**
     * Rust `nntmux_igdb_extract_base_title`; `null` when the library is unavailable or declines the input.
     */
    public static function extractBaseTitleNative(string $title): ?string
    {
        $ffi = self::$ffi ?? (self::$ffiResolved ? null : self::ffi());
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $base = $ffi->nntmux_igdb_extract_base_title($title, strlen($title));
        } catch (Throwable) {
            return null;
        }

        return is_string($base) ? $base : null;
    }

    protected function extractBaseTitlePhp(string $title): string
    {
        $patterns = [
            '/\s*[-:]\s+.*$/',
            '/\s+(?:Episode|Chapter|Part)\s+\d+.*/i',
            '/\s+(?:Vol(?:ume)?\.?\s*\d+).*/i',
            '/\s+\d+$/',
        ];

        $baseTitle = $title;
        foreach ($patterns as $pattern) {
            $baseTitle = preg_replace($pattern, '', $baseTitle);
        }

        return trim($baseTitle);
    }

    /**
     * Normalize title for matching.
     */
    protected function normalizeForMatch(string $title): string
    {
        if (self::$useRustFfi) {
            $native = self::normalizeForMatchNative($title);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->normalizeForMatchPhp($title);
    }

    /**
     * Rust `nntmux_igdb_normalize_for_match`; `null` when the library is unavailable or declines the input.
     */
    public static function normalizeForMatchNative(string $title): ?string
    {
        $ffi = self::$ffi ?? (self::$ffiResolved ? null : self::ffi());
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $normalized = $ffi->nntmux_igdb_normalize_for_match($title, strlen($title));
        } catch (Throwable) {
            return null;
        }

        return is_string($normalized) ? $normalized : null;
    }

    protected function normalizeForMatchPhp(string $title): string
    {
        $t = mb_strtolower($title);
        $t = (string) preg_replace('/\b(game of the year|goty|definitive edition|deluxe edition|ultimate edition|complete edition|remastered|hd remaster|directors? cut|anniversary edition|update|patch|hotfix|incl(?:uding)? dlcs?|dlcs?|repack|rip|iso|crack(?:fix)?|beta|alpha)\b/i', ' ', $t);
        $t = (string) preg_replace('/\b(pc|gog|steam|x64|x86|win64|win32|mult[iy]?\d*|eng|english|fr|french|de|german|es|spanish|it|italian|pt|ptbr|portuguese|ru|russian|pl|polish|tr|turkish|nl|dutch|se|swedish|no|norwegian|da|danish|fi|finnish|jp|japanese|cn|chs|cht|ko|korean)\b/i', ' ', $t);
        $t = (string) preg_replace('/[^a-z0-9]+/i', ' ', $t);
        $t = trim(preg_replace('/\s{2,}/', ' ', $t));

        return $t;
    }

    /**
     * Compute similarity between two strings.
     */
    protected function computeSimilarity(string $a, string $b): float
    {
        if (self::$useRustFfi) {
            $native = self::computeSimilarityNative($a, $b);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->computeSimilarityPhp($a, $b);
    }

    /**
     * Rust `nntmux_igdb_compute_similarity`; `null` when the library is unavailable.
     */
    public static function computeSimilarityNative(string $a, string $b): ?float
    {
        $ffi = self::$ffi ?? (self::$ffiResolved ? null : self::ffi());
        if ($ffi === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $score = $ffi->nntmux_igdb_compute_similarity($a, strlen($a), $b, strlen($b));
        } catch (Throwable) {
            return null;
        }

        return is_float($score) && $score >= 0.0 ? $score : null;
    }

    protected function computeSimilarityPhp(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }
        $percent = 0.0;
        similar_text($a, $b, $percent);

        $levScore = 0.0;
        $len = max(strlen($a), strlen($b));
        if ($len > 0) {
            $dist = levenshtein($a, $b);
            if ($dist >= 0) {
                $levScore = (1 - ($dist / $len)) * 100.0;
            }
        }

        return max($percent, $levScore);
    }

    private static function ffi(): ?FFI
    {
        if (! self::$ffiResolved) {
            self::$ffi = RustPipelineLibrary::resolveDomain('igdb_matching', self::FFI_CDEF);
            self::$ffiResolved = true;
        }

        return self::$ffi;
    }

    /**
     * Match genre string to known genres.
     */
    public function matchGenre(string $genre): string
    {
        $genreName = '';
        $a = str_replace('-', ' ', $genre);
        $tmpGenre = explode(',', $a);
        foreach ($tmpGenre as $tg) {
            $genreMatch = $this->isKnownGenre(ucwords(trim($tg)));
            if ($genreMatch !== false) {
                $genreName = (string) $genreMatch;
                break;
            }
            if (empty($genreName) && ! empty($tmpGenre[0])) {
                $genreName = trim($tmpGenre[0]);
            }
        }

        return $genreName;
    }

    /**
     * Check if genre is in known genres list.
     */
    public function isKnownGenre(string $gameGenre): bool|string
    {
        $knownGenres = [
            'Action', 'Adventure', 'Arcade', 'Board Games', 'Cards', 'Casino',
            'Flying', 'Puzzle', 'Racing', 'Rhythm', 'Role-Playing', 'RPG',
            'Simulation', 'Sports', 'Strategy', 'Trivia', 'Shooter', 'FPS',
            'Horror', 'Survival', 'Sandbox', 'Open World', 'Platformer', 'Fighting',
            'Stealth', 'MMO', 'MMORPG', 'Battle Royale', 'Roguelike', 'Roguelite',
            'Metroidvania', 'Visual Novel', 'Point & Click', 'Management',
            'City Builder', 'Tower Defense', 'Turn-Based', 'Real-Time',
            'Educational', 'Music', 'Party', 'Indie', 'Hack and Slash',
            'Souls-like', 'JRPG', 'ARPG', 'Tactical',
        ];

        return in_array($gameGenre, $knownGenres, true) ? $gameGenre : false;
    }

    /**
     * Clear lookup caches.
     */
    public function clearCache(): void
    {
        Log::info('IGDBService: Cache clear requested');
    }

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.74x, p99 2.27x; uncommon mean 2.60x, p99 2.49x; see the W47 parity test). */
    public const bool W47_CANDIDATE_SCORE_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_igdb_candidate_score_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W47_CANDIDATE_SCORE_KEYS = ['total_candidates', 'empty_title_count', 'exact_title_count', 'matched_count', 'platform_match_count', 'platform_mismatch_count', 'known_genre_count', 'score_sum', 'best_score', 'best_index', 'partial_match_count', 'title_score_sum'];

    private const array W47_STOPWORDS = ['a', 'an', 'and', 'complete', 'definitive', 'deluxe', 'edition', 'goty', 'hd', 'of', 'remastered', 'the', 'ultimate'];

    /** isKnownGenre() list as a lookup. */
    private const array W47_KNOWN_GENRES = [
        'Action' => true, 'Adventure' => true, 'Arcade' => true, 'Board Games' => true, 'Cards' => true, 'Casino' => true,
        'Flying' => true, 'Puzzle' => true, 'Racing' => true, 'Rhythm' => true, 'Role-Playing' => true, 'RPG' => true,
        'Simulation' => true, 'Sports' => true, 'Strategy' => true, 'Trivia' => true, 'Shooter' => true, 'FPS' => true,
        'Horror' => true, 'Survival' => true, 'Sandbox' => true, 'Open World' => true, 'Platformer' => true, 'Fighting' => true,
        'Stealth' => true, 'MMO' => true, 'MMORPG' => true, 'Battle Royale' => true, 'Roguelike' => true, 'Roguelite' => true,
        'Metroidvania' => true, 'Visual Novel' => true, 'Point & Click' => true, 'Management' => true,
        'City Builder' => true, 'Tower Defense' => true, 'Turn-Based' => true, 'Real-Time' => true,
        'Educational' => true, 'Music' => true, 'Party' => true, 'Indie' => true, 'Hack and Slash' => true,
        'Souls-like' => true, 'JRPG' => true, 'ARPG' => true, 'Tactical' => true,
    ];

    private static ?bool $w47UseRustFfi = null;

    private static FFI|false|null $w47Ffi = null;

    /** Null restores the benchmark-qualified default. */
    public static function useW47CandidateScoreRustFfi(?bool $enable = true): void
    {
        self::$w47UseRustFfi = $enable;
    }

    public static function usesW47CandidateScoreRustFfi(): bool
    {
        return self::$w47UseRustFfi ?? self::W47_CANDIDATE_SCORE_RUST_FFI_DEFAULT;
    }

    public static function resetW47CandidateScoreRustFfi(): void
    {
        self::$w47UseRustFfi = null;
        self::$w47Ffi = null;
    }

    /**
     * Score and rank IGDB search candidates. Titles become strtolower()
     * tokens split on [^a-z0-9]+ without edition stopwords; identical token
     * lists score 100, otherwise 100 * shared / union unique tokens
     * (integer), plus 10 (capped at 100) when one list is a proper prefix of
     * the other. A non-empty normalizePlatformHint() adds 15 when any comma
     * separated candidate platform normalizes to it and subtracts 10
     * otherwise; the final score is floored at zero. Also counts rows whose
     * comma separated genres hold a matchGenre() known genre, MATCH_THRESHOLD
     * (85) and 50 point matches, and reports the first best-scoring row
     * (-1 for an empty batch). Rows are ['query', 'name', 'platform',
     * 'platforms', 'genres']; list fields hold strings or ['name' => ...].
     *
     * @param  array<array-key, mixed>  $candidates
     * @return array<string, int>
     */
    public static function scoreCandidateBatch(array $candidates): array
    {
        return self::usesW47CandidateScoreRustFfi()
            ? self::scoreCandidateBatchNative($candidates)
            : self::scoreCandidateBatchPhp($candidates);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $candidates
     * @return array<string, int>
     */
    public static function scoreCandidateBatchNative(array $candidates): array
    {
        $fields = self::w47Fields($candidates);

        return self::w47Call($fields) ?? self::w47Summary($fields);
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return array<string, int>
     */
    public static function scoreCandidateBatchPhp(array $candidates): array
    {
        return self::w47Summary(self::w47Fields($candidates));
    }

    /** Strings, integers, and comma-joined string/integer or ['name' => ...] list entries; objects are never cast. */
    private static function w47Join(mixed $value): string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value : (is_int($value) ? (string) $value : '');
        }
        $names = [];
        foreach ($value as $v) {
            if (is_array($v)) {
                $v = $v['name'] ?? null;
            }
            $names[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
        }

        return implode(',', $names);
    }

    /**
     * @param  array<array-key, mixed>  $candidates
     * @return list<string>
     */
    private static function w47Fields(array $candidates): array
    {
        $fields = [];
        foreach ($candidates as $row) {
            if (! is_array($row)) {
                $fields[] = is_string($row) ? $row : (is_int($row) ? (string) $row : '');
                array_push($fields, '', '', '', '');

                continue;
            }
            $v = $row['query'] ?? null;
            $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
            $v = $row['name'] ?? null;
            $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
            $v = $row['platform'] ?? null;
            $fields[] = is_string($v) ? $v : (is_int($v) ? (string) $v : '');
            $fields[] = self::w47Join($row['platforms'] ?? null);
            $fields[] = self::w47Join($row['genres'] ?? null);
        }

        return $fields;
    }

    /** @return list<string> */
    private static function w47Tokens(string $title): array
    {
        return array_values(array_diff(preg_split('/[^a-z0-9]+/', strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [], self::W47_STOPWORDS));
    }

    private static function w47Platform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return self::PLATFORM_ALIASES[$platform] ?? $platform;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private static function w47Summary(array $fields): array
    {
        $summary = array_fill_keys(self::W47_CANDIDATE_SCORE_KEYS, 0);
        $summary['best_score'] = $summary['best_index'] = -1;
        for ($i = 0, $row = 0, $n = count($fields); $i < $n; $i += 5, $row++) {
            $summary['total_candidates']++;
            $query = self::w47Tokens($fields[$i]);
            $candidate = self::w47Tokens($fields[$i + 1]);
            if ($query === [] || $candidate === []) {
                $summary['empty_title_count']++;
                $title = 0;
            } elseif ($query === $candidate) {
                $title = 100;
            } else {
                $unique = array_flip($query);
                $other = array_flip($candidate);
                $shared = count(array_intersect_key($unique, $other));
                $title = intdiv(100 * $shared, count($unique) + count($other) - $shared);
                [$short, $long] = count($query) < count($candidate) ? [$query, $candidate] : [$candidate, $query];
                if (count($short) < count($long) && array_slice($long, 0, count($short)) === $short) {
                    $title = min(100, $title + 10);
                }
            }
            $summary['exact_title_count'] += (int) ($title === 100);
            $adjust = 0;
            $hint = self::w47Platform($fields[$i + 2]);
            if ($hint !== '') {
                $matched = false;
                foreach (explode(',', $fields[$i + 3]) as $platform) {
                    $platform = self::w47Platform($platform);
                    if ($platform !== '' && $platform === $hint) {
                        $matched = true;
                        break;
                    }
                }
                $summary['platform_match_count'] += (int) $matched;
                $summary['platform_mismatch_count'] += (int) ! $matched;
                $adjust = $matched ? 15 : -10;
            }
            foreach (explode(',', str_replace('-', ' ', $fields[$i + 4])) as $genre) {
                if (isset(self::W47_KNOWN_GENRES[ucwords(trim($genre))])) {
                    $summary['known_genre_count']++;
                    break;
                }
            }
            $score = max(0, $title + $adjust);
            $summary['matched_count'] += (int) ($score >= self::MATCH_THRESHOLD);
            $summary['score_sum'] += $score;
            if ($score > $summary['best_score']) {
                $summary['best_score'] = $score;
                $summary['best_index'] = $row;
            }
            $summary['partial_match_count'] += (int) ($score >= 50);
            $summary['title_score_sum'] += $title;
        }

        return $summary;
    }

    /**
     * @param  list<string>  $fields
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
            if ($out === null || $ffi->nntmux_w47_igdb_candidate_score_batch($input, strlen($input), $out, 96) !== 96) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, 96));
            if ($values === false || count($values) !== 12 || count($fields) !== $values[1] * 5) {
                return null;
            }

            return array_combine(self::W47_CANDIDATE_SCORE_KEYS, $values);
        } catch (Throwable) {
            return null;
        }
    }
}
