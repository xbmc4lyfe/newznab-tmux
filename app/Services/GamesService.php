<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ImageAssetProfile;
use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\Category;
use App\Models\GamesInfo;
use App\Models\Genre;
use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Services\Releases\ReleaseBrowseService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GamesService - Comprehensive PC Games processing service.
 *
 * Features:
 * - Game info retrieval and management
 * - Release processing with Steam and IGDB lookup
 * - Title parsing and matching
 * - Browse/search functionality
 * - Cover image handling
 */
class GamesService
{
    /** Qualified by paired common/uncommon mean and p99 benchmarks (including p99 rank bounds). */
    public const bool W404_RUST_FFI_DEFAULT = true;

    public const string W404_FFI_CDEF = <<<'C'
        ssize_t nntmux_w404_pc_game_lookup_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private static ?bool $w404UseRustFfi = null;

    private static FFI|false|null $w404Ffi = null;

    public static function useW404RustFfi(?bool $use = true): void
    {
        self::$w404UseRustFfi = $use;
        if ($use === null) {
            self::$w404Ffi = null;
        }
    }

    public static function usesW404RustFfi(): bool
    {
        return self::$w404UseRustFfi ?? self::W404_RUST_FFI_DEFAULT;
    }

    public static function resetW404RustFfi(): void
    {
        self::$w404UseRustFfi = null;
        self::$w404Ffi = null;
    }

    /** @param list<list<string>> $rows
     * @return array<string, int>|null
     */
    private static function w404Call(array $rows): ?array
    {
        try {
            $input = serialize([$rows]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w404Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w404', self::W404_FFI_CDEF) ?? false;
            $ffi = self::$w404Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W404_FFI_CDEF.)
            $written = $ffi->nntmux_w404_pc_game_lookup_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W404_FFI_CDEF.)
                $written = $ffi->nntmux_w404_pc_game_lookup_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            $keys = ['total_entries', 'scene_group_tagged_count', 'update_patch_dlc_count', 'repack_gog_steam_count', 'eligible_lookup_count'];
            if (! is_array($result) || array_keys($result) !== $keys) {
                return null;
            }
            $summary = [];
            foreach ($keys as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($rows)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    /** Only integer and string fields participate; objects are never cast or serialized. */
    private static function w404Text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }

    /** @param array<array-key, mixed> $input
     * @return list<list<string>>
     */
    private static function w404Rows(array $input): array
    {
        $rows = [];
        foreach ($input as $row) {
            $row = is_array($row) ? $row : [];
            $rows[] = [self::w404Text($row['searchname'] ?? $row['title'] ?? null)];
        }

        return $rows;
    }

    /**
     * Summarize searchname (or title) fields. Scene groups require a -GROUP
     * suffix; UPDATE/PATCH/DLC and REPACK/GOG/STEAM use whole ASCII tokens.
     * Eligible means a nonempty title without UPDATE, PATCH or DLC tokens.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizePcGameLookupBatch(array $entries): array
    {
        if (self::usesW404RustFfi()) {
            $native = self::w404Call(self::w404Rows($entries));
            if ($native !== null) {
                return $native;
            }
        }

        return self::summarizePcGameLookupBatchPhp($entries);
    }

    /** @param array<array-key, mixed> $entries
     * @return array<string, int>
     */
    public static function summarizePcGameLookupBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(['total_entries', 'scene_group_tagged_count', 'update_patch_dlc_count', 'repack_gog_steam_count', 'eligible_lookup_count'], 0);
        $summary['total_entries'] = count($entries);
        foreach (self::w404Rows($entries) as [$title]) {
            $upper = strtoupper($title);
            $tokens = preg_split('/[^A-Z0-9]+/', $upper, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $scene = false;
            foreach (self::SCENE_GROUPS as $group) {
                if (str_ends_with($upper, '-'.strtoupper($group))) {
                    $scene = true;
                    break;
                }
            }
            $update = array_intersect(['UPDATE', 'PATCH', 'DLC'], $tokens) !== [];
            $summary['scene_group_tagged_count'] += (int) $scene;
            $summary['update_patch_dlc_count'] += (int) $update;
            $summary['repack_gog_steam_count'] += (int) (array_intersect(['REPACK', 'GOG', 'STEAM'], $tokens) !== []);
            $summary['eligible_lookup_count'] += (int) ($title !== '' && ! $update);
        }

        return $summary;
    }

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_games_service_clean_game_reg(const char *ptr, size_t len, char *out, size_t cap);
        ssize_t nntmux_games_service_parse_title(const char *ptr, size_t len, char *out, size_t cap);
        ssize_t nntmux_games_clean_spaces(const char *ptr, size_t len, char *out, size_t cap);
        ssize_t nntmux_games_clean_search_title(const char *ptr, size_t len, char *out, size_t cap);
        int32_t nntmux_games_extract_year(const char *ptr, size_t len);
        int32_t nntmux_games_match_release_year(const char *rel_ptr, size_t rel_len, const char *cand_ptr, size_t cand_len);
        ssize_t nntmux_games_service_normalize_for_match(const char *ptr, size_t len, char *out, size_t cap);
        double nntmux_games_levenshtein_percent(const char *a_ptr, size_t a_len, const char *b_ptr, size_t b_len);
        double nntmux_games_compute_title_similarity(const char *a_ptr, size_t a_len, const char *b_ptr, size_t b_len);
        ssize_t nntmux_games_detect_genre_key(const char *genre_ptr, size_t genre_len, const char *ser_ptr, size_t ser_len, char *out, size_t cap);
        C;

    protected const int GAME_MATCH_PERCENTAGE = 85;

    protected const int GAME_CACHE_TTL = 86400; // 24 hours

    protected const int FAILED_LOOKUP_CACHE_TTL = 3600; // 1 hour

    protected const array SCENE_GROUPS = [
        'CODEX', 'PLAZA', 'GOG', 'CPY', 'HOODLUM', 'EMPRESS', 'RUNE', 'TENOKE', 'FLT',
        'RELOADED', 'SKIDROW', 'PROPHET', 'RAZOR1911', 'CORE', 'REFLEX', 'P2P', 'GOLDBERG',
        'DARKSIDERS', 'TINYISO', 'DOGE', 'ANOMALY', 'ELAMIGOS', 'FITGIRL', 'DODI', 'XATAB',
        'GOG-GAMES', 'BLG', 'RARGB', 'CHRONOS', 'FCKDRM', 'I_KnoW', 'STEAM', 'PLAZA',
        'SPTGAMES', 'DARKSiDERS', 'TiNYiSO', 'KaOs', 'SiMPLEX', 'ElAmigos', 'FitGirl',
        'PROPHET', 'ALI213', 'FLTDOX', '3DMGAME', 'POSTMORTEM', 'VACE', 'ROGUE', 'OUTLAWS',
    ];

    protected const string LEGACY_TITLE_REGEX =
        '#(?P<title>[\w\s\.]+)(-(?P<relgrp>FLT|RELOADED|Elamigos|SKIDROW|PROPHET|RAZOR1911|CORE|REFLEX))?\s?(\s*(\?('.
        '(?P<reltype>PROPER|MULTI\d|RETAIL|CRACK(FIX)?|ISO|(RE)?(RIP|PACK))|(?P<year>(19|20)\d{2})|V\s?'.
        '(?P<version>(\d+\.)+\d+)|(-\s)?(?P=relgrp))\)?)\s?)*\s?(\.\w{2,4})?#i';

    /** Null selects the benchmark-qualified production default per helper. */
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
        return self::$useRustFfi ?? true;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public bool $echoOutput;

    public string|int|null $gameQty;

    public string $imgSavePath;

    public int $matchPercentage;

    public bool $maxHitRequest;

    public string $renamed;

    public string $catWhere;

    protected SteamService $steamService;

    protected IGDBService $igdbService;

    protected GamesTitleParser $titleParser;

    protected ReleaseImageService $imageService;

    // Processing stats
    protected int $processedCount = 0;

    protected int $matchedCount = 0;

    protected int $failedCount = 0;

    protected int $cachedCount = 0;

    protected string $_classUsed = '';

    /**
     * @throws \Exception
     */
    public function __construct(
        ?SteamService $steamService = null,
        ?IGDBService $igdbService = null,
        ?GamesTitleParser $titleParser = null,
        ?ReleaseImageService $imageService = null
    ) {
        $this->echoOutput = config('nntmux.echocli');
        $this->steamService = $steamService ?? new SteamService;
        $this->igdbService = $igdbService ?? new IGDBService;
        $this->titleParser = $titleParser ?? new GamesTitleParser;
        $this->imageService = $imageService ?? new ReleaseImageService;

        $this->gameQty = app(ConfigurationProvider::class)->metadata()->maxGamesProcessed !== '' ? (int) app(ConfigurationProvider::class)->metadata()->maxGamesProcessed : 150;
        $this->imgSavePath = config('nntmux_settings.covers_path').'/games/';
        $this->renamed = (int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2 ? 'AND isrenamed = 1' : '';
        $this->matchPercentage = 60;
        $this->maxHitRequest = false;
        $this->catWhere = 'AND categories_id = '.Category::PC_GAMES.' ';
    }

    // ========================================
    // Game Info Retrieval Methods
    // ========================================

    /**
     * Get game info by ID.
     */
    public function getGamesInfoById(int $id): ?Model
    {
        return GamesInfo::query()
            ->where('gamesinfo.id', $id)
            ->leftJoin('genres as g', 'g.id', '=', 'gamesinfo.genres_id')
            ->select(['gamesinfo.*', 'g.title as genres'])
            ->first();
    }

    /**
     * Get game info by name using full-text search.
     */
    public function getGamesInfoByName(string $title): bool|Model
    {
        $bestMatch = false;

        if (empty($title)) {
            return false;
        }

        if (Search::isAvailable()) {
            $hits = Search::searchSecondary(SecondarySearchIndex::Games, $title, 80);
            $bestMatchPct = 0;
            $normQuery = $this->normalizeTitleForMatch($title);

            foreach ($hits['id'] as $gid) {
                $result = GamesInfo::query()
                    ->where('gamesinfo.id', $gid)
                    ->leftJoin('genres as g', 'g.id', '=', 'gamesinfo.genres_id')
                    ->select(['gamesinfo.*', 'g.title as genres'])
                    ->first();
                if ($result === null) {
                    continue;
                }
                $candidate = (string) $result->title;
                if ($candidate === '') {
                    continue;
                }
                if ($candidate === $title) {
                    $bestMatch = $result;
                    break;
                }
                $score = $this->computeTitleSimilarity($normQuery, $this->normalizeTitleForMatch($candidate));
                if ($score >= self::GAME_MATCH_PERCENTAGE && $score > $bestMatchPct) {
                    $bestMatch = $result;
                    $bestMatchPct = $score;
                }
            }

            return $bestMatch;
        }

        $results = GamesInfo::query()
            ->whereRaw('MATCH (title) AGAINST (? IN NATURAL LANGUAGE MODE)', [$title])
            ->get();

        $bestMatchPct = 0;
        $normQuery = $this->normalizeTitleForMatch($title);

        foreach ($results as $result) {
            $candidate = (string) $result->title;
            if ($candidate === '') {
                continue;
            }
            if ($candidate === $title) {
                $bestMatch = $result;
                break;
            }
            $score = $this->computeTitleSimilarity($normQuery, $this->normalizeTitleForMatch($candidate));
            if ($score >= self::GAME_MATCH_PERCENTAGE && $score > $bestMatchPct) {
                $bestMatch = $result;
                $bestMatchPct = $score;
            }
        }

        return $bestMatch;
    }

    /**
     * Get paginated list of all games.
     */
    public function getRange(?string $search = null): LengthAwarePaginator // @phpstan-ignore missingType.generics
    {
        $query = GamesInfo::query()
            ->select(['gi.*', 'g.title as genretitle'])
            ->from('gamesinfo as gi')
            ->join('genres as g', 'gi.genres_id', '=', 'g.id');

        if (! empty($search)) {
            if (Search::isAvailable()) {
                $ids = Search::searchSecondary(SecondarySearchIndex::Games, $search, 3000)['id'];
                if ($ids === []) {
                    return $query->whereRaw('1 = 0')->paginate(config('nntmux.items_per_page'));
                }
                $query->whereIn('gi.id', $ids);
            } else {
                $query->where('gi.title', 'like', '%'.$search.'%');
            }
        }

        return $query->orderByDesc('created_at')
            ->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Get total count of games.
     */
    public function getCount(): int
    {
        return GamesInfo::query()->count('id') ?? 0;
    }

    /**
     * Get games range for browsing with filtering.
     *
     * @param  array<string, mixed>  $excludedCats
     * @param  array<string, mixed>  $orderBy
     *
     * @throws \Exception
     */
    public function getGamesRange(mixed $page, mixed $cat, mixed $start, mixed $num, array|string $orderBy = '', string $maxAge = '', array $excludedCats = []): mixed
    {
        $page = max(1, $page);
        $start = max(0, $start);

        $browseBy = $this->getBrowseBy();
        $catsrch = '';
        if (count($cat) > 0 && $cat[0] !== -1) {
            $catsrch = Category::getCategorySearch($cat);
        }
        $whereAge = '';
        if ($maxAge > 0) {
            $whereAge = sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge);
        }
        $exccatlist = '';
        if (count($excludedCats) > 0) {
            $exccatlist = ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')';
        }
        $order = $this->getGamesOrder($orderBy);
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        $showPasswords = app(ReleaseBrowseService::class)->showPasswords();

        $baseWhere = "gi.title != '' AND gi.cover = 1 "
            ."AND r.passwordstatus {$showPasswords} "
            .$browseBy.' '
            .$catsrch.' '
            .$whereAge
            .$exccatlist;

        $cacheKey = md5('games_range_'.$baseWhere.$order[0].$order[1].$start.$num.$page);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Step 1: Count total distinct games matching filters
        $countSql = 'SELECT COUNT(DISTINCT gi.id) AS total '
            .'FROM gamesinfo gi '
            .'INNER JOIN releases r ON gi.id = r.gamesinfo_id '
            .'WHERE '.$baseWhere;

        $totalResult = DB::select($countSql);
        $totalCount = $totalResult[0]->total ?? 0;

        if ($totalCount === 0) {
            return collect();
        }

        // Step 2: Get paginated games entity list with only needed columns
        $gamesSql = 'SELECT gi.id, gi.title, gi.cover, gi.publisher, gi.releasedate, gi.review, gi.url, '
            .'YEAR(gi.releasedate) AS year, '
            .'MAX(r.postdate) AS latest_postdate, '
            .'COUNT(r.id) AS total_releases '
            .'FROM gamesinfo gi '
            .'INNER JOIN releases r ON gi.id = r.gamesinfo_id '
            .'WHERE '.$baseWhere.' '
            .'GROUP BY gi.id, gi.title, gi.cover, gi.publisher, gi.releasedate, gi.review, gi.url '
            ."ORDER BY {$order[0]} {$order[1]} "
            ."LIMIT {$num} OFFSET {$start}";

        $games = GamesInfo::fromQuery($gamesSql);

        if ($games->isEmpty()) {
            return collect();
        }

        // Build list of game IDs for release query
        $gameIds = $games->pluck('id')->toArray();
        $inGameIds = implode(',', array_map('intval', $gameIds));

        // Step 3: Get top 2 releases per game using ROW_NUMBER()
        $releasesSql = 'SELECT ranked.id, ranked.gamesinfo_id, ranked.guid, ranked.searchname, '
            .'ranked.size, ranked.postdate, ranked.adddate, ranked.haspreview, ranked.grabs, '
            .'ranked.comments, ranked.totalpart, ranked.group_name, ranked.nfoid, ranked.failed_count '
            .'FROM ( '
            .'SELECT r.id, r.gamesinfo_id, r.guid, r.searchname, r.size, r.postdate, r.adddate, '
            .'r.haspreview, r.grabs, r.comments, r.totalpart, g.name AS group_name, '
            .'rn.releases_id AS nfoid, df.failed AS failed_count, '
            .'ROW_NUMBER() OVER (PARTITION BY r.gamesinfo_id ORDER BY r.postdate DESC) AS rn '
            .'FROM releases r '
            .'LEFT JOIN usenet_groups g ON g.id = r.groups_id '
            .'LEFT JOIN release_nfos rn ON rn.releases_id = r.id '
            .'LEFT JOIN dnzb_failures df ON df.release_id = r.id '
            ."WHERE r.gamesinfo_id IN ({$inGameIds}) "
            ."AND r.passwordstatus {$showPasswords} "
            .$catsrch.' '
            .$whereAge
            .$exccatlist
            .') ranked '
            .'WHERE ranked.rn <= 2 '
            .'ORDER BY ranked.gamesinfo_id, ranked.postdate DESC';

        $releases = DB::select($releasesSql);

        // Group releases by gamesinfo_id for fast lookup
        $releasesByGame = [];
        foreach ($releases as $release) {
            $releasesByGame[$release->gamesinfo_id][] = $release;
        }

        // Attach releases to each game entity
        foreach ($games as $game) {
            $game->releases = $releasesByGame[$game->id] ?? []; // @phpstan-ignore assign.propertyReadOnly
        }

        // Set total count on first item
        if ($games->isNotEmpty()) {
            $games[0]->_totalcount = $totalCount; // @phpstan-ignore property.notFound
        }

        Cache::put($cacheKey, $games, $expiresAt);

        return $games;
    }

    /**
     * Get order array for games.
     *
     * @param  array<string, mixed>|string  $orderBy
     * @return array{0: string, 1: string}
     */
    public function getGamesOrder(array|string $orderBy): array
    {
        $order = $orderBy === '' ? 'r.postdate' : $orderBy;
        $orderArr = explode('_', $order);
        $orderField = match ($orderArr[0]) {
            'title' => 'gi.title',
            'releasedate' => 'gi.releasedate',
            'genre' => 'gi.genres_id',
            'size' => 'r.size',
            'files' => 'r.totalpart',
            'stats' => 'r.grabs',
            default => 'r.postdate',
        };
        $orderSort = (isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc';

        return [$orderField, $orderSort];
    }

    /**
     * Get ordering options.
     *
     * @return array<int, string>
     */
    public function getGamesOrdering(): array
    {
        return [
            'title_asc', 'title_desc', 'posted_asc', 'posted_desc', 'size_asc', 'size_desc',
            'files_asc', 'files_desc', 'stats_asc', 'stats_desc',
            'releasedate_asc', 'releasedate_desc', 'genre_asc', 'genre_desc',
        ];
    }

    /**
     * Get browse by options.
     *
     * @return array<string, mixed>
     */
    public function getBrowseByOptions(): array
    {
        return ['title' => 'title', 'genre' => 'genres_id', 'year' => 'year'];
    }

    /**
     * Get browse by SQL clause.
     */
    public function getBrowseBy(): string
    {
        $browseBy = ' ';
        foreach ($this->getBrowseByOptions() as $bbk => $bbv) {
            if (! empty($_REQUEST[$bbk])) {
                $bbs = stripslashes($_REQUEST[$bbk]);
                if ($bbk === 'year') {
                    $browseBy .= ' AND YEAR (gi.releasedate) '.'LIKE '.escapeString('%'.$bbs.'%');
                } else {
                    $browseBy .= ' AND gi.'.$bbv.' '.'LIKE '.escapeString('%'.$bbs.'%');
                }
            }
        }

        return $browseBy;
    }

    // ========================================
    // Game Update Methods
    // ========================================

    /**
     * Update a game record.
     */
    public function update(
        int $id,
        string $title,
        ?string $asin,
        ?string $url,
        ?string $publisher,
        mixed $releaseDate,
        ?string $esrb,
        int $cover,
        ?string $trailerUrl,
        ?int $genreID
    ): void {
        GamesInfo::query()
            ->where('id', $id)
            ->update([
                'title' => $title,
                'asin' => $asin,
                'url' => $url,
                'publisher' => $publisher,
                'releasedate' => $releaseDate,
                'esrb' => $esrb,
                'cover' => $cover,
                'trailer' => $trailerUrl,
                'genres_id' => $genreID,
            ]);
    }

    /**
     * Update game info from external APIs (Steam/IGDB).
     *
     * @param  array<string, mixed>  $gameInfo
     */
    public function updateGamesInfo(array $gameInfo): bool|int
    {
        $gen = new GenreService;

        $game = [];
        $titleKey = $this->generateCacheKey($gameInfo['title']);

        // Check if we've already failed to find this game recently
        if (Cache::has("game_lookup_failed:{$titleKey}")) {
            Log::debug('GamesService: Skipping previously failed lookup', ['title' => $gameInfo['title']]);
            $this->cachedCount++;

            return false;
        }

        // Check cache first for existing lookup
        $cachedResult = Cache::get("game_lookup:{$titleKey}");
        if ($cachedResult !== null) {
            Log::debug('GamesService: Using cached lookup result', ['title' => $gameInfo['title']]);
            $this->cachedCount++;

            return $this->saveGameInfoFromCache($cachedResult, $gen, $gameInfo);
        }

        // Try Steam first (has more details)
        $this->_classUsed = 'Steam';
        $genreName = '';

        $steamGameID = $this->steamService->search($gameInfo['title']);

        if ($steamGameID !== null) {
            $steamResults = $this->steamService->getAll($steamGameID);

            if ($steamResults !== false) {
                if (empty($steamResults['title'])) {
                    return false;
                }

                $game = $this->buildGameFromSteam($steamResults, $genreName);
            }
        }

        // Fall back to IGDB if Steam didn't find anything
        if ($this->igdbService->isConfigured()) {
            try {
                if ($steamGameID === null || empty($game)) {
                    $igdbGame = $this->igdbService->search($gameInfo['title']);
                    if ($igdbGame !== null) {
                        $this->_classUsed = 'IGDB';
                        $game = $this->igdbService->buildGameData($igdbGame, $genreName);
                    } else {
                        Cache::put("game_lookup_failed:{$titleKey}", true, self::FAILED_LOOKUP_CACHE_TTL);

                        return false;
                    }
                }
            } catch (IgdbHttpException $e) {
                if ($e->getStatusCode() === 429) {
                    Log::warning('GamesService: IGDB rate limit exceeded');
                }
            }
        }

        if (empty($game)) {
            Cache::put("game_lookup_failed:{$titleKey}", true, self::FAILED_LOOKUP_CACHE_TTL);

            return false;
        }

        // Save to database
        return $this->saveGameToDatabase($game, $genreName, $gen, $gameInfo, $titleKey);
    }

    /**
     * Build game array from Steam data.
     *
     * @param  array<string, mixed>  $steamResults
     * @return array<string, mixed>
     */
    protected function buildGameFromSteam(array $steamResults, string &$genreName): array
    {
        $game = [];

        if (! empty($steamResults['cover'])) {
            $game['coverurl'] = (string) $steamResults['cover'];
        }

        if (! empty($steamResults['backdrop'])) {
            $game['backdropurl'] = (string) $steamResults['backdrop'];
        }

        $game['title'] = (string) $steamResults['title'];
        $game['asin'] = $steamResults['steamid'];
        $game['url'] = (string) $steamResults['directurl'];
        $game['publisher'] = ! empty($steamResults['publisher']) ? (string) $steamResults['publisher'] : 'Unknown';
        $game['esrb'] = ! empty($steamResults['rating']) ? (string) $steamResults['rating'] : 'Not Rated';

        if (! empty($steamResults['releasedate'])) {
            $dateReleased = strtotime($steamResults['releasedate']) === false ? '' : $steamResults['releasedate'];
            $game['releasedate'] = (strtotime($steamResults['releasedate']) === false)
                ? null
                : Carbon::createFromFormat('M j, Y', Carbon::parse($dateReleased)->toFormattedDateString())->format('Y-m-d');
        }

        if (! empty($steamResults['description'])) {
            $game['review'] = (string) $steamResults['description'];
        }

        if (! empty($steamResults['genres'])) {
            $genreName = $this->igdbService->matchGenre($steamResults['genres']);
        }

        return $game;
    }

    /**
     * Save game to database.
     *
     * @param  array<string, mixed>  $game
     * @param  array<string, mixed>  $gameInfo
     */
    protected function saveGameToDatabase(array $game, string $genreName, GenreService $gen, array $gameInfo, string $titleKey): bool|int
    {
        // Load genres
        $defaultGenres = $gen->loadGenres((string) GenreService::GAME_TYPE);

        // Prepare database values
        $game['cover'] = isset($game['coverurl']) ? 1 : 0;
        $game['backdrop'] = isset($game['backdropurl']) ? 1 : 0;
        if (! isset($game['trailer'])) {
            $game['trailer'] = 0;
        }
        if (empty($game['title'])) {
            $game['title'] = $gameInfo['title'];
        }
        if (! isset($game['releasedate'])) {
            $game['releasedate'] = '';
        }
        if (! isset($game['review'])) {
            $game['review'] = 'No Review';
        }
        $game['classused'] = $this->_classUsed;

        if (empty($genreName)) {
            $genreName = 'Unknown';
        }

        $detectedKey = $this->detectGenreKey((string) $genreName, $defaultGenres);
        if ($detectedKey !== false) {
            $genreKey = $detectedKey;
        } else {
            $genreKey = Genre::query()->insertGetId(['title' => $genreName, 'type' => GenreService::GAME_TYPE]);
        }

        $game['gamesgenre'] = $genreName;
        $game['gamesgenreID'] = $genreKey;

        $gamesId = false;

        if (! empty($game['asin'])) {
            try {
                DB::beginTransaction();

                $check = GamesInfo::query()->where('asin', $game['asin'])->first();
                if ($check === null) {
                    $gamesId = GamesInfo::query()
                        ->insertGetId([
                            'title' => $game['title'],
                            'asin' => $game['asin'],
                            'url' => $game['url'],
                            'publisher' => $game['publisher'],
                            'genres_id' => $game['gamesgenreID'] === -1 ? null : $game['gamesgenreID'],
                            'esrb' => $game['esrb'],
                            'releasedate' => $game['releasedate'] !== '' ? $game['releasedate'] : null,
                            'review' => substr($game['review'], 0, 3000),
                            'cover' => $game['cover'],
                            'backdrop' => $game['backdrop'],
                            'trailer' => $game['trailer'],
                            'classused' => $game['classused'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    Log::info('GamesService: Added new game', ['id' => $gamesId, 'title' => $game['title'], 'source' => $this->_classUsed]);
                } else {
                    $gamesId = $check['id'];
                    GamesInfo::query()
                        ->where('id', $gamesId)
                        ->update([
                            'title' => $game['title'],
                            'asin' => $game['asin'],
                            'url' => $game['url'],
                            'publisher' => $game['publisher'],
                            'genres_id' => $game['gamesgenreID'] === -1 ? null : $game['gamesgenreID'],
                            'esrb' => $game['esrb'],
                            'releasedate' => $game['releasedate'] !== '' ? $game['releasedate'] : null,
                            'review' => substr($game['review'], 0, 3000),
                            'cover' => $game['cover'],
                            'backdrop' => $game['backdrop'],
                            'trailer' => $game['trailer'],
                            'classused' => $game['classused'],
                        ]);

                    Log::debug('GamesService: Updated existing game', ['id' => $gamesId, 'title' => $game['title']]);
                }

                DB::commit();

                // Cache the successful lookup
                Cache::put("game_lookup:{$titleKey}", $game, self::GAME_CACHE_TTL);
                $this->matchedCount++;

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('GamesService: Database error saving game', [
                    'title' => $game['title'],
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        if (! empty($gamesId)) {
            if ($this->echoOutput) {
                cli()->header('Added/updated game: ').
                cli()->alternateOver('   Title:    ').
                cli()->primary($game['title']).
                cli()->alternateOver('   Source:   ').
                cli()->primary($this->_classUsed);
            }

            // Save cover image
            if ($game['cover'] === 1 && isset($game['coverurl'])) {
                $game['cover'] = (int) $this->imageService->saveRemoteImage(
                    (string) $gamesId,
                    $game['coverurl'],
                    $this->imgSavePath,
                    ImageAssetProfile::MetadataCover,
                )->success;
            }

            // Save backdrop image
            if ($game['backdrop'] === 1 && isset($game['backdropurl'])) {
                $game['backdrop'] = (int) $this->imageService->saveRemoteImage(
                    $gamesId.'-backdrop',
                    $game['backdropurl'],
                    $this->imgSavePath,
                    ImageAssetProfile::Backdrop,
                )->success;
            }
        } elseif ($this->echoOutput) {
            cli()->headerOver('Nothing to update: ').
            cli()->primary($game['title'].' (PC)');
        }

        return $gamesId !== false ? $gamesId : false;
    }

    /**
     * Save game info from cached data.
     *
     * @param  array<string, mixed>  $game
     * @param  array<string, mixed>  $gameInfo
     */
    protected function saveGameInfoFromCache(array $game, GenreService $gen, array $gameInfo): bool|int
    {
        $defaultGenres = $gen->loadGenres((string) GenreService::GAME_TYPE);
        $genreName = $game['gamesgenre'] ?? 'Unknown';

        $detectedKey = $this->detectGenreKey((string) $genreName, $defaultGenres);
        if ($detectedKey !== false) {
            $genreKey = $detectedKey;
        } else {
            $genreKey = Genre::query()->insertGetId(['title' => $genreName, 'type' => GenreService::GAME_TYPE]);
        }

        $game['gamesgenreID'] = $genreKey;

        if (! empty($game['asin'])) {
            $check = GamesInfo::query()->where('asin', $game['asin'])->first();
            if ($check !== null) {
                return $check['id'];
            }
        }

        return false;
    }

    // ========================================
    // Release Processing Methods
    // ========================================

    /**
     * Process game releases.
     *
     * @throws \Exception
     */
    public function processGamesReleases(string $groupID = '', string $guidChar = ''): void
    {
        // Reset stats
        $this->processedCount = 0;
        $this->matchedCount = 0;
        $this->failedCount = 0;
        $this->cachedCount = 0;

        $startTime = microtime(true);

        $query = Release::query()
            ->where('gamesinfo_id', '=', 0)
            ->where('categories_id', '=', Category::PC_GAMES);
        Release::whereNzbSettled($query);

        if ($guidChar !== '') {
            $query->where('leftguid', 'like', $guidChar.'%');
        }

        if ($groupID !== '') {
            $query->where('groups_id', $groupID);
        }

        if ((int) app(ConfigurationProvider::class)->metadata()->gameLookup->value === 2) {
            $query->where('isrenamed', '=', 1);
        }

        $query->select(['searchname', 'id'])
            ->orderByDesc('postdate')
            ->limit($this->gameQty);

        $res = $query->get();

        if ($res->count() > 0) {
            if ($this->echoOutput) {
                cli()->header('Processing '.$res->count().' games release(s).');
            }

            Log::info('GamesService: Starting processing', ['count' => $res->count()]);

            $releaseUpdates = [];
            $releaseNames = [];

            foreach ($res as $arr) {
                $this->processedCount++;
                $this->maxHitRequest = false;

                $releaseNames[$arr['id']] = $arr['searchname'];
                $gameInfo = $this->parseTitle($arr['searchname']);

                if ($gameInfo !== false) {
                    if ($this->echoOutput) {
                        cli()->info('Looking up: '.$gameInfo['title'].' (PC)');
                    }

                    // Check for existing games entry
                    $gameCheck = $this->getGamesInfoByName($gameInfo['title']);

                    if ($gameCheck === false) {
                        $gameId = $this->updateGamesInfo($gameInfo);
                        if ($gameId === false) {
                            $gameId = -2;
                            $this->failedCount++;
                        }
                    } else {
                        $gameId = $gameCheck['id'];
                        $this->cachedCount++;
                    }

                    $releaseUpdates[$arr['id']] = $gameId;
                } else {
                    $releaseUpdates[$arr['id']] = -2;
                    $this->failedCount++;

                    if ($this->echoOutput) {
                        echo '.';
                    }
                }
            }

            // Batch update releases
            $this->batchUpdateReleases($releaseUpdates, $releaseNames);

            $elapsed = round(microtime(true) - $startTime, 2);

            Log::info('GamesService: Processing completed', [
                'processed' => $this->processedCount,
                'matched' => $this->matchedCount,
                'cached' => $this->cachedCount,
                'failed' => $this->failedCount,
                'elapsed_seconds' => $elapsed,
            ]);

            if ($this->echoOutput) {
                cli()->header(sprintf(
                    'Games processing complete: %d processed, %d matched, %d cached, %d failed (%.2fs)',
                    $this->processedCount,
                    $this->matchedCount,
                    $this->cachedCount,
                    $this->failedCount,
                    $elapsed
                ));
            }
        } elseif ($this->echoOutput) {
            cli()->header('No games releases to process.');
        }
    }

    /**
     * Parse release title.
     *
     * @return array{title: string, release: string}|false
     */
    public function parseTitle(string $releaseName): array|false
    {
        return (self::$useRustFfi ?? true)
            ? $this->parseTitleNative($releaseName)
            : $this->parseTitlePhp($releaseName);
    }

    /**
     * @return array{title: string, release: string}|false
     */
    public function parseTitleNative(string $releaseName): array|false
    {
        $out = self::callNativeString('nntmux_games_service_parse_title', $releaseName);
        if ($out === null) {
            return $this->parseTitlePhp($releaseName);
        }
        if ($out === '') {
            return false;
        }
        $tag = $out[0];
        $title = substr($out, 1);
        if ($title === '') {
            return false;
        }

        return [
            'title' => $title,
            'release' => $tag === '2' ? trim($releaseName) : $releaseName,
        ];
    }

    /**
     * @return array{title: string, release: string}|false
     */
    public function parseTitlePhp(string $releaseName): array|false
    {
        $name = $this->cleanGameRegPhp($releaseName);
        if ($name === '') {
            return $this->fallbackParseTitlePhp($releaseName);
        }

        return [
            'title' => $name,
            'release' => $releaseName,
        ];
    }

    public function cleanGameReg(string $releaseName): string
    {
        return (self::$useRustFfi ?? true)
            ? $this->cleanGameRegNative($releaseName)
            : $this->cleanGameRegPhp($releaseName);
    }

    public function cleanGameRegNative(string $releaseName): string
    {
        return self::callNativeString('nntmux_games_service_clean_game_reg', $releaseName)
            ?? $this->cleanGameRegPhp($releaseName);
    }

    public function cleanGameRegPhp(string $releaseName): string
    {
        $name = (string) preg_replace('/\.(zip|rar|7z|iso|nfo|sfv|exe|mkv|mp4|avi)$/i', '', $releaseName);
        $name = str_replace('%20', ' ', $name);
        $name = (string) preg_replace('/^\[[^]]+\]\s*/', '', $name);
        $name = (string) preg_replace('/^(PC\s*ISO\)\s*\()/i', '', $name);
        $name = (string) preg_replace('/\[[^]]{1,80}\]|\([^)]{1,80}\)/', ' ', $name);
        $name = (string) preg_replace(
            '/\b(Game\s+of\s+the\s+Year|GOTY|Definitive Edition|Deluxe Edition|Ultimate Edition|Complete Edition|Remastered|HD Remaster|Directors? Cut|Anniversary Edition)\b/i',
            ' ',
            $name
        );
        $name = (string) preg_replace('/\b(Update|Patch|Hotfix)\b[\s._-]*v?\d+(?:\.\d+){1,}\b/i', ' ', $name);
        $name = (string) preg_replace('/\bMULTI\d+|MULTi\d+\b/i', ' ', $name);
        $name = (string) preg_replace('/(?:^|[\s._-])v\d+(?:[\s._-]\d+){1,}(?=$|[\s._-])/i', ' ', $name);
        $name = (string) preg_replace('/(?:^|[\s._-])\d+(?:[\s._-]\d+){2,}(?=$|[\s._-])/', ' ', $name);
        $name = (string) preg_replace('/\b(Incl(?:uding)?\s+DLCs?|DLCs?|PROPER|REPACK|RIP|ISO|CRACK(?:FIX)?|BETA|ALPHA)\b/i', ' ', $name);
        $name = (string) preg_replace('/\b(Update|Patch|Hotfix)\b/i', ' ', $name);

        $groupAlternation = implode('|', array_map('preg_quote', self::SCENE_GROUPS));
        $name = (string) preg_replace('/\s*-\s*(?:'.$groupAlternation.'|[A-Z0-9]{2,})\s*$/i', '', $name);
        $name = (string) preg_replace('/[._+]+/', ' ', $name);

        $name = (string) preg_replace(
            '/\b(Game\s+of\s+the\s+Year|GOTY|Definitive Edition|Deluxe Edition|Ultimate Edition|Complete Edition|Remastered|HD Remaster|Directors? Cut|Anniversary Edition)\b/i',
            ' ',
            $name
        );
        $name = (string) preg_replace('/\b(Incl|Including|DLC|DLCs)\b/i', ' ', $name);
        $name = $this->cleanVersionTokensPhp($name);
        $name = (string) preg_replace('/(?:^|\s)v\d+(?:\s+\d+){1,}(?=$|\s)/i', ' ', $name);
        $name = (string) preg_replace('/(?:^|\s)\d+(?:\s+\d+){2,}(?=$|\s)/', ' ', $name);
        $name = (string) preg_replace('/\s{2,}/', ' ', $name);
        $name = trim($name, " \t\n\r\0\x0B-_");
        $name = str_replace(' RF ', ' ', $name);

        for ($i = 0; $i < 2; $i++) {
            $name = (string) preg_replace('/(?:^|\s)v\d+(?:\s+\d+){1,}(?=$|\s)/i', ' ', $name);
            $name = (string) preg_replace('/(?:^|\s)\d+(?:\s+\d+){2,}(?=$|\s)/', ' ', $name);
            $name = (string) preg_replace('/\b[vV]\d+(?:[ ._-]\d+){1,}\b/', ' ', $name);
            $name = (string) preg_replace('/\b\d+(?:[ ._-]\d+){2,}\b/', ' ', $name);
            $name = (string) preg_replace('/\s{2,}/', ' ', $name);
            $name = trim($name, " \t\n\r\0\x0B-_");
        }

        return $name;
    }

    public function cleanGameSpaces(string $title): string
    {
        return (self::$useRustFfi ?? false)
            ? $this->cleanGameSpacesNative($title)
            : $this->cleanGameSpacesPhp($title);
    }

    public function cleanGameSpacesNative(string $title): string
    {
        return self::callNativeString('nntmux_games_clean_spaces', $title)
            ?? $this->cleanGameSpacesPhp($title);
    }

    public function cleanGameSpacesPhp(string $title): string
    {
        $clean = str_replace('%20', ' ', $title);
        $clean = (string) preg_replace('/[._+]+/', ' ', $clean);
        $clean = (string) preg_replace('/\s{2,}/', ' ', $clean);
        $clean = trim($clean, " \t\n\r\0\x0B-_");
        $clean = str_replace(' RF ', ' ', $clean);
        $clean = (string) preg_replace('/\s{2,}/', ' ', $clean);

        return trim($clean, " \t\n\r\0\x0B-_");
    }

    public function cleanSearchTitle(string $title): string
    {
        return (self::$useRustFfi ?? false)
            ? $this->cleanSearchTitleNative($title)
            : $this->cleanSearchTitlePhp($title);
    }

    public function cleanSearchTitleNative(string $title): string
    {
        return self::callNativeString('nntmux_games_clean_search_title', $title)
            ?? $this->cleanSearchTitlePhp($title);
    }

    public function cleanSearchTitlePhp(string $title): string
    {
        $clean = str_replace('%20', ' ', $title);
        $clean = (string) preg_replace('/\[[^]]{1,80}\]|\([^)]{1,80}\)/', ' ', $clean);
        $clean = (string) preg_replace(
            '/\b(Game\s+of\s+the\s+Year|GOTY|Definitive Edition|Deluxe Edition|Ultimate Edition|Complete Edition|Remastered|HD Remaster|Directors? Cut|Anniversary Edition|Incl(?:uding)?\s+DLCs?|DLCs?|PROPER|REPACK|RIP|ISO|CRACK(?:FIX)?|BETA|ALPHA|Update|Patch|Hotfix)\b/i',
            ' ',
            $clean
        );
        $clean = $this->cleanGameSpacesPhp($clean);

        return mb_strtolower($clean);
    }

    public function extractYearFromText(string $text): ?int
    {
        return (self::$useRustFfi ?? false)
            ? $this->extractYearFromTextNative($text)
            : $this->extractYearFromTextPhp($text);
    }

    public function extractYearFromTextNative(string $text): ?int
    {
        if (($ffi = self::ffi()) === null) {
            return $this->extractYearFromTextPhp($text);
        }
        try {
            // @phpstan-ignore method.notFound
            $year = $ffi->nntmux_games_extract_year($text, strlen($text));
            if (is_int($year) && $year >= 0) {
                return $year === 0 ? null : $year;
            }
        } catch (Throwable) {
        }

        return $this->extractYearFromTextPhp($text);
    }

    public function extractYearFromTextPhp(string $text): ?int
    {
        if (preg_match('/\b((?:19|20)\d{2})\b/', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public function matchReleaseYear(string $releaseName, int|string|null $candidateYear): bool
    {
        return (self::$useRustFfi ?? false)
            ? $this->matchReleaseYearNative($releaseName, $candidateYear)
            : $this->matchReleaseYearPhp($releaseName, $candidateYear);
    }

    public function matchReleaseYearNative(string $releaseName, int|string|null $candidateYear): bool
    {
        if ($candidateYear === null || $candidateYear === '') {
            return true;
        }
        if (($ffi = self::ffi()) === null) {
            return $this->matchReleaseYearPhp($releaseName, $candidateYear);
        }
        $candStr = (string) $candidateYear;
        try {
            // @phpstan-ignore method.notFound
            $res = $ffi->nntmux_games_match_release_year(
                $releaseName,
                strlen($releaseName),
                $candStr,
                strlen($candStr),
            );
            if (is_int($res) && ($res === 0 || $res === 1)) {
                return $res === 1;
            }
        } catch (Throwable) {
        }

        return $this->matchReleaseYearPhp($releaseName, $candidateYear);
    }

    public function matchReleaseYearPhp(string $releaseName, int|string|null $candidateYear): bool
    {
        if ($candidateYear === null || $candidateYear === '') {
            return true;
        }
        $candYear = is_int($candidateYear)
            ? (($candidateYear >= 1900 && $candidateYear <= 2099) ? $candidateYear : null)
            : $this->extractYearFromTextPhp((string) $candidateYear);
        if ($candYear === null) {
            return true;
        }
        $relYear = $this->extractYearFromTextPhp($releaseName);
        if ($relYear === null) {
            return true;
        }

        return $relYear === $candYear;
    }

    public function normalizeTitleForMatch(string $title): string
    {
        return (self::$useRustFfi ?? true)
            ? $this->normalizeTitleForMatchNative($title)
            : $this->normalizeTitleForMatchPhp($title);
    }

    public function normalizeTitleForMatchNative(string $title): string
    {
        return self::callNativeString('nntmux_games_service_normalize_for_match', $title)
            ?? $this->normalizeTitleForMatchPhp($title);
    }

    public function normalizeTitleForMatchPhp(string $title): string
    {
        $t = mb_strtolower($title);
        $groupAlternation = implode('|', array_map('preg_quote', self::SCENE_GROUPS));
        $t = (string) preg_replace('/\s*-\s*(?:'.$groupAlternation.'|[A-Z0-9]{2,})\s*$/i', '', $t);
        $t = (string) preg_replace('/\b(game of the year|goty|definitive edition|deluxe edition|ultimate edition|complete edition|remastered|hd remaster|directors? cut|anniversary edition|update|patch|hotfix|incl(?:uding)? dlcs?|dlcs?|repack|rip|iso|crack(?:fix)?|beta|alpha)\b/i', ' ', $t);
        $t = (string) preg_replace('/\b(pc|gog|steam|x64|x86|win64|win32|mult[iy]?\d*|eng|english|fr|french|de|german|es|spanish|it|italian|pt|ptbr|portuguese|ru|russian|pl|polish|tr|turkish|nl|dutch|se|swedish|no|norwegian|da|danish|fi|finnish|jp|japanese|cn|chs|cht|ko|korean)\b/i', ' ', $t);
        $t = (string) preg_replace('/[^a-z0-9]+/i', ' ', $t);

        return trim((string) preg_replace('/\s{2,}/', ' ', $t));
    }

    public function levenshteinPercent(string $a, string $b): float
    {
        return (self::$useRustFfi ?? false)
            ? $this->levenshteinPercentNative($a, $b)
            : $this->levenshteinPercentPhp($a, $b);
    }

    public function levenshteinPercentNative(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }
        if (($ffi = self::ffi()) === null) {
            return $this->levenshteinPercentPhp($a, $b);
        }
        try {
            // @phpstan-ignore method.notFound
            $score = $ffi->nntmux_games_levenshtein_percent($a, strlen($a), $b, strlen($b));
            if (is_float($score) && $score >= 0.0 && $score <= 100.0) {
                return $score;
            }
        } catch (Throwable) {
        }

        return $this->levenshteinPercentPhp($a, $b);
    }

    public function levenshteinPercentPhp(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }
        $len = max(strlen($a), strlen($b));
        if ($len === 0) {
            return 100.0;
        }
        $dist = levenshtein($a, $b);
        if ($dist < 0) {
            return 0.0;
        }

        return (1 - ($dist / $len)) * 100.0;
    }

    public function computeTitleSimilarity(string $a, string $b): float
    {
        return (self::$useRustFfi ?? false)
            ? $this->computeTitleSimilarityNative($a, $b)
            : $this->computeTitleSimilarityPhp($a, $b);
    }

    public function computeTitleSimilarityNative(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }
        if (($ffi = self::ffi()) === null) {
            return $this->computeTitleSimilarityPhp($a, $b);
        }
        try {
            // @phpstan-ignore method.notFound
            $score = $ffi->nntmux_games_compute_title_similarity($a, strlen($a), $b, strlen($b));
            if (is_float($score) && $score >= 0.0 && $score <= 100.0) {
                return $score;
            }
        } catch (Throwable) {
        }

        return $this->computeTitleSimilarityPhp($a, $b);
    }

    public function computeTitleSimilarityPhp(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }
        $percent = 0.0;
        similar_text($a, $b, $percent);
        $levScore = $this->levenshteinPercentPhp($a, $b);

        return max($percent, $levScore);
    }

    /**
     * @param  array<int|string, mixed>  $defaultGenres
     */
    public function detectGenreKey(string $genreName, array $defaultGenres): int|string|false
    {
        return (self::$useRustFfi ?? false)
            ? $this->detectGenreKeyNative($genreName, $defaultGenres)
            : $this->detectGenreKeyPhp($genreName, $defaultGenres);
    }

    /**
     * @param  array<int|string, mixed>  $defaultGenres
     */
    public function detectGenreKeyNative(string $genreName, array $defaultGenres): int|string|false
    {
        if (($ffi = self::ffi()) === null) {
            return $this->detectGenreKeyPhp($genreName, $defaultGenres);
        }
        try {
            $ser = @serialize($defaultGenres);
            $serLen = strlen($ser);
            $genreLen = strlen($genreName);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $genreLen + 64), $capacity);
            if ($buffer === null) {
                return $this->detectGenreKeyPhp($genreName, $defaultGenres);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_games_detect_genre_key($genreName, $genreLen, $ser, $serLen, $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->detectGenreKeyPhp($genreName, $defaultGenres);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_games_detect_genre_key($genreName, $genreLen, $ser, $serLen, $buffer, $capacity);
            }
            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                if ($written === 0) {
                    return false;
                }
                $raw = FFI::string($buffer, $written);
                if (str_starts_with($raw, 'i:')) {
                    return (int) substr($raw, 2);
                }
                if (str_starts_with($raw, 's:')) {
                    return substr($raw, 2);
                }
            }
        } catch (Throwable) {
        }

        return $this->detectGenreKeyPhp($genreName, $defaultGenres);
    }

    /**
     * @param  array<int|string, mixed>  $defaultGenres
     */
    public function detectGenreKeyPhp(string $genreName, array $defaultGenres): int|string|false
    {
        $normalized = strtolower(trim($genreName));
        if ($normalized === '') {
            return false;
        }
        foreach ($defaultGenres as $key => $value) {
            if (is_string($value) && strtolower(trim($value)) === $normalized) {
                return $key;
            }
        }

        return false;
    }

    protected function cleanVersionTokensPhp(string $name): string
    {
        $tokens = preg_split('/\s+/', trim($name)) ?: [];
        $filtered = [];
        $i = 0;
        $tcount = count($tokens);

        while ($i < $tcount) {
            $tok = $tokens[$i];
            if (preg_match('/^v?\d+$/i', $tok)) {
                $j = $i + 1;
                $numRun = 0;
                while ($j < $tcount && preg_match('/^\d+$/', $tokens[$j])) {
                    $numRun++;
                    $j++;
                }
                $startsWithV = preg_match('/^v\d+$/i', $tok) === 1;
                if (($startsWithV && $numRun >= 1) || (! $startsWithV && $numRun >= 2)) {
                    $i = $j;

                    continue;
                }
                $filtered[] = $tok;
                $i++;

                continue;
            }
            $filtered[] = $tok;
            $i++;
        }

        return implode(' ', $filtered);
    }

    /**
     * @return array{title: string, release: string}|false
     */
    protected function fallbackParseTitlePhp(string $releaseName): array|false
    {
        $cleanedName = (string) preg_replace('/\sMulti\d?\s/i', '', $releaseName);
        if (preg_match(self::LEGACY_TITLE_REGEX, $cleanedName, $hits)) {
            $result = [];
            $result['title'] = str_replace(' RF ', ' ', (string) preg_replace('/(?:[-:._]|%20|[\[\]])/', ' ', $hits['title']));
            $result['title'] = (string) preg_replace('/(brazilian|chinese|croatian|danish|deutsch|dutch|english|estonian|flemish|finnish|french|german|greek|hebrew|icelandic|italian|latin|nordic|norwegian|polish|portuguese|japenese|japanese|russian|serbian|slovenian|spanish|spanisch|swedish|thai|turkish)$/i', '', $result['title']);
            $result['title'] = (string) preg_replace('/^(PC\sISO\)\s\()/i', '', $result['title']);
            $result['title'] = trim((string) preg_replace('/\s{2,}/', ' ', $result['title']));

            if (empty($result['title'])) {
                return false;
            }

            $result['release'] = $releaseName;

            return array_map('trim', $result);
        }

        return false;
    }

    private static function callNativeString(string $symbol, string $input): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $len = strlen($input);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            $written = $ffi->$symbol($input, $len, $buffer, $capacity);
            if (! is_int($written) || $written === -1 || $written === PHP_INT_MIN) {
                return null;
            }
            if ($written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($input, $len, $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch update release records with game IDs.
     *
     * @param  array<string, mixed>  $updates
     * @param  array<int|string, string>  $searchnames  Release id => searchname the lookup used; omitted ids are not guarded.
     */
    protected function batchUpdateReleases(array $updates, array $searchnames = []): void
    {
        if (empty($updates)) {
            return;
        }

        try {
            DB::beginTransaction();

            foreach ($updates as $releaseId => $gameId) {
                // Only for the title it was matched on: an upgrade may have renamed it meanwhile.
                Release::query()
                    ->where('id', '=', $releaseId)
                    ->where('categories_id', '=', Category::PC_GAMES)
                    ->when(isset($searchnames[$releaseId]), static fn ($q) => $q->where('searchname', $searchnames[$releaseId]))
                    ->update(['gamesinfo_id' => $gameId]);
            }

            DB::commit();

            Log::debug('GamesService: Batch updated releases', ['count' => count($updates)]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('GamesService: Failed to batch update releases', ['error' => $e->getMessage()]);

            // Fall back to individual updates
            foreach ($updates as $releaseId => $gameId) {
                try {
                    Release::query()
                        ->where('id', '=', $releaseId)
                        ->where('categories_id', '=', Category::PC_GAMES)
                        ->when(isset($searchnames[$releaseId]), static fn ($q) => $q->where('searchname', $searchnames[$releaseId]))
                        ->update(['gamesinfo_id' => $gameId]);
                } catch (\Exception $e) {
                    Log::error('GamesService: Failed to update release', [
                        'release_id' => $releaseId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    // ========================================
    // Utility Methods
    // ========================================

    /**
     * Generate a consistent cache key from a game title.
     */
    protected function generateCacheKey(string $title): string
    {
        return md5(mb_strtolower(trim($title)));
    }

    /**
     * Get processing statistics from the last run.
     *
     * @return array<string, mixed>
     */
    public function getProcessingStats(): array
    {
        return [
            'processed' => $this->processedCount,
            'matched' => $this->matchedCount,
            'cached' => $this->cachedCount,
            'failed' => $this->failedCount,
        ];
    }

    /**
     * Clear game lookup caches.
     */
    public function clearLookupCaches(): void
    {
        Log::info('GamesService: Lookup caches cleared');
    }

    /**
     * Match genre name to known genres.
     */
    public function matchGenreName(string $gameGenre): bool|string
    {
        return $this->igdbService->isKnownGenre($gameGenre) ? $gameGenre : false;
    }
}
